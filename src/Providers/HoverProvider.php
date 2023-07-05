<?php

namespace Pxp\Pls\Providers;

use Phpactor\LanguageServerProtocol\Hover;
use Phpactor\LanguageServerProtocol\MarkupContent;
use Phpactor\LanguageServerProtocol\MarkupKind;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use Pxp\Parser\Parser\Pxp;
use Pxp\Parser\PrettyPrinter\Standard;
use Pxp\TypeDeducer\Support;
use Pxp\TypeDeducer\TypeDeducer;
use Pxp\TypeDeducer\Types\MixedType;
use Pxp\TypeDeducer\Types\NamedType;
use Pxp\TypeDeducer\Types\TypeFactory;
use Roave\BetterReflection\Reflection\ReflectionParameter;

class HoverProvider
{
    public function __construct(
        protected Pxp $parser,
    ) {}

    public function provide(TypeDeducer $typeDeducer, int $position): ?Hover
    {
        $node = Support::findNodeAtPosition($typeDeducer->getAst(), $position, searchNonExpr: true);

        if ($node === null) {
            return null;
        }

        $parent = $node->getAttribute('parent');

        // Foo::bar(), $foo::bar()
        //      ^^^          ^^^
        // static function Foo::bar(<args>): <return>
        if ($node instanceof Identifier && $parent instanceof StaticCall) {
            $receiverType = $typeDeducer->getTypeOfNode($parent->class);

            if (! $receiverType instanceof NamedType) {
                return null;
            }

            $reflection = $receiverType->getReflection();
            $reflectionMethod = $reflection->getMethod($node->name);

            if ($reflectionMethod === null) {
                return null;
            }

            return Hover::fromArray([
                'contents' => MarkupContent::fromArray([
                    'kind' => MarkupKind::MARKDOWN,
                    'value' => sprintf(
                        <<<'MD'
                        ```pxp
                        static function %s::%s(%s): %s
                        ```
                        MD,
                        $receiverType,
                        $node->name,
                        implode(', ', array_map(function (ReflectionParameter $parameter) use ($typeDeducer): string {
                            if ($parameter->hasType()) {
                                return sprintf('%s $%s', TypeFactory::fromReflectionType($parameter->getType(), $typeDeducer->getReflectionProvider()), $parameter->getName());
                            }

                            return '$' . $parameter->getName();
                        }, $reflectionMethod->getParameters())),
                        $reflectionMethod->hasReturnType() ? TypeFactory::fromReflectionType($reflectionMethod->getReturnType(), $typeDeducer->getReflectionProvider()) : new MixedType(),
                    )
                ]),
            ]);
        }

        // foo()
        // ^^^
        // function foo(<args>): <return>
        if ($node instanceof Name && $parent instanceof FuncCall) {
            $typeDeducer->getTypeOfNode($node);

            $fileScope = $typeDeducer->getFileScope();
            $reflectionProvider = $typeDeducer->getReflectionProvider();

            $resolvedName = $fileScope->resolveName($node);

            if ($fileScope->isInNamespace() && $reflectionProvider->hasFunction($fileScope->getNamespace() . '\\' . $node->toString())) {
                $reflectionFunction = $reflectionProvider->getFunction($fileScope->getNamespace() . '\\' . $node->toString());
            } else {
                $reflectionFunction = $reflectionProvider->getFunction($resolvedName);
            }
            
            if ($reflectionFunction === null) {
                return null;
            }

            return Hover::fromArray([
                'contents' => MarkupContent::fromArray([
                    'kind' => MarkupKind::MARKDOWN,
                    'value' => sprintf(
                        <<<'MD'
                        ```pxp
                        function %s(%s): %s
                        ```
                        MD,
                        $reflectionFunction->getName(),
                        implode(', ', array_map(function (ReflectionParameter $parameter) use ($typeDeducer): string {
                            if ($parameter->hasType()) {
                                $string = sprintf('%s $%s', TypeFactory::fromReflectionType($parameter->getType(), $typeDeducer->getReflectionProvider()), $parameter->getName());
                            } else {
                                $string = '$'.$parameter->getName();
                            }

                            if ($parameter->isDefaultValueAvailable()) {
                                $string .= ' = ' . ltrim((new Standard)->prettyPrintExpr($parameter->getDefaultValueExpression()), '\\');
                            }
                            
                            return $string;
                        }, $reflectionFunction->getParameters())),
                        $reflectionFunction->hasReturnType() ? TypeFactory::fromReflectionType($reflectionFunction->getReturnType(), $typeDeducer->getReflectionProvider()) : new MixedType(),
                    )
                ]),
            ]);
        }

        if ($node instanceof Variable && is_string($node->name)) {
            $type = $typeDeducer->getTypeOfNode($node);

            return Hover::fromArray([
                'contents' => MarkupContent::fromArray([
                    'kind' => MarkupKind::MARKDOWN,
                    'value' => <<<MD
                    ```pxp
                    {$type} \${$node->name}
                    ```
                    MD,
                ]),
            ]);
        }

        if ($node) {
            ray('unhandled hover for node: ' . $node::class);
        }

        return null;
    }
}