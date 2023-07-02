<?php

namespace Pxp\Pls;

use Phpactor\LanguageServerProtocol\CompletionItem;
use Phpactor\LanguageServerProtocol\CompletionItemKind;
use Phpactor\LanguageServerProtocol\CompletionList;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeTraverser;
use Pxp\Parser\Lexer\Emulative;
use Pxp\Parser\Parser\Pxp;
use Pxp\Pls\Visitors\VariableFindingVisitor;
use Pxp\TypeDeducer\Support;

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

    public function completion(string $file, int $position): CompletionList
    {
        $code = file_get_contents($file);
        $ast = $this->parser->parse($code, new Collecting);
        $node = Support::findNodeAtPosition($ast, $position);

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

            $items = array_map(fn (string $variable, $index) => CompletionItem::fromArray([
                'label' => '$' . $variable,
                'kind' => CompletionItemKind::VARIABLE,
                'data' => $index,
            ]), $variables, array_keys($variables));

            return CompletionList::fromArray([
                'isIncomplete' => false,
                'items' => $items,
            ]);
        }

        return CompletionList::fromArray([
            'isIncomplete' => false,
            'items' => [],
        ]);
    }
}