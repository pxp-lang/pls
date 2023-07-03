<?php

namespace Pxp\Pls;

use Exception;
use Phpactor\LanguageServerProtocol\CompletionItem;
use Phpactor\LanguageServerProtocol\CompletionItemKind;
use Phpactor\LanguageServerProtocol\CompletionList;
use Phpactor\LanguageServerProtocol\InsertTextFormat;
use Phpactor\LanguageServerProtocol\InsertTextMode;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeTraverser;
use Pxp\Parser\Lexer\Emulative;
use Pxp\Parser\Parser\Pxp;
use Pxp\Pls\Visitors\VariableFindingVisitor;
use Pxp\TypeDeducer\Support;
use Pxp\TypeDeducer\TypeDeducer;
use Pxp\TypeDeducer\Types\MixedType;
use Pxp\TypeDeducer\Types\NamedType;
use Roave\BetterReflection\Reflection\ReflectionProperty;

final class Pls
{
    private Pxp $parser;

    public function __construct()
    {
        $this->parser = new Pxp(new Emulative([
            'usedAttributes' => [
                'comments',
                'startLine',
                'endLine',
                'startTokenPos',
                'endTokenPos',
                'startFilePos',
                'endFilePos',
            ]
        ]));
    }

    public function completion(string $directory, string $file, int $position): CompletionList
    {
        $code = file_get_contents($file);
        $ast = $this->parser->parse($code, new Collecting);

        $typeDeducer = new TypeDeducer([$directory], $file);
        $typeDeducer->setAst($ast);

        $node = Support::findNodeAtPosition($ast, $position);

        if ($node instanceof New_) {
            try {
                $classes = $typeDeducer->getReflectionProvider()->getAllClasses();
            } catch (Exception $e) {
                goto no_items;
            }
            
            $items = [];

            foreach ($classes as $class) {
                if (! $class->isInstantiable()) {
                    continue;
                }

                $items[] = CompletionItem::fromArray([
                    'label' => $class->getName(),
                    'kind' => CompletionItemKind::CLASS_,
                    'insertText' => $class->getName(),
                ]);
            }

            ray($items);

            return CompletionList::fromArray([
                'isIncomplete' => false,
                'items' => $items,
            ]);
        }

        if ($node instanceof Expression) {
            $node = $node->expr;
        }

        // We're trying to autocomplete a variable.
        if ($node instanceof Variable) {
            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor = new VariableFindingVisitor($position));
            $traverser->traverse($ast);

            $variables = $visitor->getVariables();
            $variables = $variables[array_key_last($variables)];

            $items = array_map(fn (string $variable) => CompletionItem::fromArray([
                'label' => '$' . $variable,
                'kind' => CompletionItemKind::VARIABLE,
                'insertText' => $variable,
            ]), $variables);

            return CompletionList::fromArray([
                'isIncomplete' => false,
                'items' => $items,
            ]);
        }

        if ($node instanceof PropertyFetch) {
            $type = $typeDeducer->getTypeOfNode($node->var);
            
            if (! $type instanceof NamedType) {
                goto no_items;
            }

            $reflection = $type->getReflection();
            $items = [];

            foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                $items[] = CompletionItem::fromArray([
                    'label' => $property->getName(),
                    'kind' => CompletionItemKind::PROPERTY,
                    'insertText' => $property->getName(),
                ]);
            }

            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getNumberOfParameters() > 0) {
                    $insertText = $method->getName() . '($1)$0';
                    $insertTextFormat = InsertTextFormat::SNIPPET;
                } else {
                    $insertText = $method->getName() . '()';
                    $insertTextFormat = InsertTextFormat::PLAIN_TEXT;
                }

                $items[] = CompletionItem::fromArray([
                    'label' => $method->getName(),
                    'kind' => CompletionItemKind::METHOD,
                    'insertText' => $insertText,
                    'insertTextFormat' => $insertTextFormat,
                ]);
            }

            return CompletionList::fromArray([
                'isIncomplete' => false,
                'items' => $items,
            ]);
        }

        no_items:
        return CompletionList::fromArray([
            'isIncomplete' => false,
            'items' => [],
        ]);
    }
}