<?php

namespace Pxp\Pls\Visitors;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeVisitorAbstract;
use PHPStan\PhpDocParser\Ast\NodeTraverser;

class VariableFindingVisitor extends NodeVisitorAbstract
{
    protected array $variables = [];

    public function __construct(
        // The $position marks the point at which we want to stop looking for variables.
        protected int $position,
    ) {
        $this->variables[] = [];
    }

    public function enterNode(Node $node)
    {
        $variables =& $this->variables[array_key_last($this->variables)];

        if ($node->getStartFilePos() >= $this->position) {
            return NodeTraverser::STOP_TRAVERSAL;
        }

        if ($node instanceof FunctionLike) {
            $params = [];

            foreach ($node->getParams() as $param) {
                $params[] = $param->var->name;
            }

            $this->variables[] = $params;
        }

        if ($node instanceof Assign && $node->var instanceof Variable && is_string($node->var->name)) {
            $variables[] = $node->var->name;
        }
    }

    public function leaveNode(Node $node)
    {
        if ($node instanceof FunctionLike) {
            array_pop($this->variables);
        }
    }

    public function getVariables(): array
    {
        return $this->variables;
    }
}