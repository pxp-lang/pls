<?php

namespace Pxp\Pls\Providers;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use Pxp\Parser\Parser\Pxp;
use Pxp\TypeDeducer\Location;
use Pxp\TypeDeducer\Support;
use Pxp\TypeDeducer\TypeDeducer;

class DefinitionProvider
{
    public function __construct(
        protected Pxp $parser,
    ) {}

    public function provide(TypeDeducer $typeDeducer, int $position): ?array
    {
        $node = Support::findNodeAtPosition($typeDeducer->getAst(), $position, searchNonExpr: true);

        if ($node === null) {
            return null;
        }

        $parent = $node->getAttribute('parent');

        if ($node instanceof Variable && is_string($node->name)) {
            $variables = $typeDeducer->getVariablesInScopeOfNode($node);
            
            foreach ($variables as $name => $variable) {
                if ($name === $node->name) {
                    return ['position' => $variable->position];
                }
            }
        }

        ray($node::class, $parent::class);

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

            return [
                'file' => $reflectionFunction->getLocatedSource()->getFileName(),
                'line' => $reflectionFunction->getStartLine(),
                'column' => $reflectionFunction->getEndLine(),
            ];
        }

        return null;
    }
}