<?php

namespace Pxp\Pls;

use Exception;
use Phpactor\LanguageServerProtocol\CompletionItem;
use Phpactor\LanguageServerProtocol\CompletionItemKind;
use Phpactor\LanguageServerProtocol\CompletionList;
use Phpactor\LanguageServerProtocol\Hover;
use Phpactor\LanguageServerProtocol\InsertTextFormat;
use Phpactor\LanguageServerProtocol\InsertTextMode;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Expression;
use Pxp\Parser\Lexer\Emulative;
use Pxp\Parser\Parser\Pxp;
use Pxp\Pls\Providers\HoverProvider;
use Pxp\TypeDeducer\Support;
use Pxp\TypeDeducer\TypeDeducer;
use Pxp\TypeDeducer\Types\NamedType;
use Roave\BetterReflection\Reflection\ReflectionProperty;

final class Pls
{
    private Pxp $parser;

    private HoverProvider $hoverProvider;

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

        $this->hoverProvider = new HoverProvider($this->parser);
    }

    public function hover(string $directory, string $file, int $position): ?Hover
    {
        $code = file_get_contents($file);
        $ast = $this->parser->parse($code, new Collecting);

        $typeDeducer = new TypeDeducer([$directory], $file);
        $typeDeducer->setAst($ast);

        return $this->hoverProvider->provide($typeDeducer, $position);
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
            $variables = $typeDeducer->getVariablesInScopeOfNode($node);
            $items = [];

            foreach ($variables as $name => $variable) {
                $items[] = CompletionItem::fromArray([
                    'label' => '$' . $name,
                    'kind' => CompletionItemKind::VARIABLE,
                    'insertText' => $name,
                ]);
            }

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

        if ($node instanceof ClassConstFetch) {
            $type = $typeDeducer->getTypeOfNode($node->class);

            if (! $type instanceof NamedType) {
                goto no_items;
            }

            $reflection = $type->getReflection();
            $items = [];

            foreach ($reflection->getConstants() as $constant) {
                if (! $constant->isPublic()) {
                    continue;
                }

                $items[] = CompletionItem::fromArray([
                    'label' => $constant->getName(),
                    'kind' => CompletionItemKind::CONSTANT,
                    'insertText' => $constant->getName(),
                ]);
            }

            $items[] = CompletionItem::fromArray([
                'label' => 'class',
                'kind' => CompletionItemKind::CONSTANT,
                'insertText' => 'class',
            ]);

            foreach ($reflection->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
                $items[] = CompletionItem::fromArray([
                    'label' => '$' . $property->getName(),
                    'kind' => CompletionItemKind::PROPERTY,
                    'insertText' => '$' . $property->getName(),
                ]);
            }

            foreach ($reflection->getMethods() as $method) {
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

        if ($node instanceof FuncCall) {
            // FIXME: Can we get rid of this? Right now it's needed to access the file scope.
            $typeDeducer->getTypeOfNode($node);

            $resolvedName = $typeDeducer->getFileScope()->resolveName($node->name);
            $reflectionProvider = $typeDeducer->getReflectionProvider();

            if (! $reflectionProvider->hasFunction($resolvedName)) {
                goto no_items;
            }

            $items = [];
            $reflection = $reflectionProvider->getFunction($resolvedName);

            foreach ($reflection->getParameters() as $parameter) {
                $items[] = CompletionItem::fromArray([
                    'label' => $parameter->getName(),
                    'kind' => CompletionItemKind::FIELD,
                    'insertText' => $parameter->getName() . ': ',
                ]);
            }

            return CompletionList::fromArray([
                'isIncomplete' => false,
                'items' => $items,
            ]);
        }

        if ($node instanceof MethodCall || $node instanceof StaticCall) {
            if (! $node->name instanceof Identifier) {
                goto no_items;
            }

            $type = $typeDeducer->getTypeOfNode($node->var);

            if (! $type instanceof NamedType) {
                goto no_items;
            }

            $items = [];
            $reflection = $type->getReflection();
            $reflectionMethod = $reflection->getMethod($node->name->toString());

            if ($reflectionMethod === null) {
                goto no_items;
            }

            foreach ($reflectionMethod->getParameters() as $parameter) {
                $items[] = CompletionItem::fromArray([
                    'label' => $parameter->getName(),
                    'kind' => CompletionItemKind::FIELD,
                    'insertText' => $parameter->getName() . ': ',
                ]);
            }

            return CompletionList::fromArray([
                'isIncomplete' => false,
                'items' => $items,
            ]);
        }

        if ($node instanceof ConstFetch) {
            $classes = $typeDeducer->getReflectionProvider()->getAllClasses();
            $items = [];

            foreach ($classes as $class) {
                $items[] = CompletionItem::fromArray([
                    'label' => $class->getName(),
                    'kind' => CompletionItemKind::CLASS_,
                    'insertText' => $class->getName(),
                ]);
            }

            $functions = $typeDeducer->getReflectionProvider()->getAllFunctions();

            foreach ($functions as $function) {
                if ($function->getNumberOfParameters() > 0) {
                    $insertText = $function->getName() . '($1)$0';
                    $insertTextFormat = InsertTextFormat::SNIPPET;
                } else {
                    $insertText = $function->getName() . '()';
                    $insertTextFormat = InsertTextFormat::PLAIN_TEXT;
                }

                $items[$function->getName()] = CompletionItem::fromArray([
                    'label' => $function->getName(),
                    'kind' => CompletionItemKind::FUNCTION,
                    'insertText' => $insertText,
                    'insertTextFormat' => $insertTextFormat,
                ]);
            }

            return CompletionList::fromArray([
                'isIncomplete' => false,
                'items' => array_values($items),
            ]);
        }

        if ($node !== null) {
            ray('dont handle: ' . $node::class);
        }

        no_items:
        return CompletionList::fromArray([
            'isIncomplete' => false,
            'items' => [],
        ]);
    }
}