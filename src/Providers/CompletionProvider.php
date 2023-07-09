<?php

namespace Pxp\Pls\Providers;

use Phpactor\LanguageServerProtocol\CompletionItem;
use Phpactor\LanguageServerProtocol\CompletionItemKind;
use Phpactor\LanguageServerProtocol\InsertTextFormat;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt\Expression;
use Pxp\Parser\Parser\Pxp;
use Pxp\Pls\Support\Keywords;
use Pxp\TypeDeducer\Support;
use Pxp\TypeDeducer\TypeDeducer;
use PhpParser\Node\Expr\Variable;
use Exception;
use Pxp\TypeDeducer\Types\NamedType;
use Roave\BetterReflection\Reflection\ReflectionEnum;

class CompletionProvider
{
    public function __construct(
        protected Pxp $parser,
    ) {}

    public function provide(TypeDeducer $typeDeducer, int $position): array
    {
        $items = [];
        $node = Support::findNodeAtPosition($typeDeducer->getAst(), $position, searchNonExpr: true);

        if ($node === null) {
            return $items;
        }

        $parent = $node->getAttribute('parent');

        if ($node instanceof New_ || $parent instanceof New_) {
            try {
                $classes = $typeDeducer->getReflectionProvider()->getAllClasses();
            } catch (Exception $e) {
                return $items;
            }

            foreach ($classes as $class) {
                if (!$class->isInstantiable()) {
                    continue;
                }

                $items[] = CompletionItem::fromArray([
                    'label' => $class->getName(),
                    'kind' => CompletionItemKind::CLASS_,
                    'insertText' => $class->getName(),
                ]);
            }
        }

        if ($node instanceof Expression) {
            $node = $node->expr;
        }

        // We're trying to autocomplete a variable.
        if ($node instanceof Variable || $parent instanceof Variable) {
            $variables = $typeDeducer->getVariablesInScopeOfNode($node);

            foreach ($variables as $name => $variable) {
                $items[] = CompletionItem::fromArray([
                    'label' => '$' . $name,
                    'kind' => CompletionItemKind::VARIABLE,
                    'insertText' => $name,
                ]);
            }
        }

        if ($node instanceof PropertyFetch || $parent instanceof PropertyFetch) {
            $type = $typeDeducer->getTypeOfNode($node->var);

            if (!$type instanceof NamedType) {
                return $items;
            }

            $reflection = $type->getReflection();

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
        }

        if ($node instanceof ClassConstFetch || $parent instanceof ClassConstFetch) {
            $type = $typeDeducer->getTypeOfNode($node->class);

            if (!$type instanceof NamedType) {
                return $items;
            }

            $reflection = $type->getReflection();

            if ($reflection instanceof ReflectionEnum) {
                foreach ($reflection->getCases() as $case) {
                    $items[] = CompletionItem::fromArray([
                        'label' => $case->getName(),
                        'kind' => CompletionItemKind::ENUM_MEMBER,
                        'insertText' => $case->getName(),
                    ]);
                }
            }

            foreach ($reflection->getConstants() as $constant) {
                if (!$constant->isPublic()) {
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

            if (! $reflection instanceof ReflectionEnum) {
                foreach ($reflection->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
                    $items[] = CompletionItem::fromArray([
                        'label' => '$' . $property->getName(),
                        'kind' => CompletionItemKind::PROPERTY,
                        'insertText' => '$' . $property->getName(),
                    ]);
                }
            }

            foreach ($reflection->getMethods() as $method) {
                if (!$method->isStatic()) {
                    continue;
                }

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
        }

        if ($node instanceof FuncCall || $parent instanceof FuncCall) {
            // FIXME: Can we get rid of this? Right now it's needed to access the file scope.
            $typeDeducer->getTypeOfNode($node);

            $resolvedName = $typeDeducer->getFileScope()->resolveName($node->name);
            $reflectionProvider = $typeDeducer->getReflectionProvider();

            if (!$reflectionProvider->hasFunction($resolvedName)) {
                return $items;
            }

            $reflection = $reflectionProvider->getFunction($resolvedName);

            foreach ($reflection->getParameters() as $parameter) {
                $items[] = CompletionItem::fromArray([
                    'label' => $parameter->getName(),
                    'kind' => CompletionItemKind::FIELD,
                    'insertText' => $parameter->getName() . ': ',
                ]);
            }
        }

        if ($node instanceof MethodCall || $node instanceof StaticCall) {
            if (!$node->name instanceof Identifier) {
                return $items;
            }

            $type = $typeDeducer->getTypeOfNode($node->var);

            if (!$type instanceof NamedType) {
                return $items;
            }

            $items = [];
            $reflection = $type->getReflection();
            $reflectionMethod = $reflection->getMethod($node->name->toString());

            if ($reflectionMethod === null) {
                return $items;
            }

            foreach ($reflectionMethod->getParameters() as $parameter) {
                $items[] = CompletionItem::fromArray([
                    'label' => $parameter->getName(),
                    'kind' => CompletionItemKind::FIELD,
                    'insertText' => $parameter->getName() . ': ',
                ]);
            }
        }

        if ($node instanceof ConstFetch || $parent instanceof ConstFetch) {
            $classes = $typeDeducer->getReflectionProvider()->getAllClasses();

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
                    'kind' => CompletionItemKind::FUNCTION ,
                    'insertText' => $insertText,
                    'insertTextFormat' => $insertTextFormat,
                ]);
            }

            foreach (Keywords::all() as $keyword) {
                $items[] = CompletionItem::fromArray([
                    'label' => $keyword,
                    'kind' => CompletionItemKind::KEYWORD,
                    'insertText' => $keyword,
                ]);
            }
        }

        return array_values($items);
    }
}