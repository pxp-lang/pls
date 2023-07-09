<?php

namespace Pxp\Pls;

use Exception;
use Phpactor\LanguageServerProtocol\CompletionItem;
use Phpactor\LanguageServerProtocol\CompletionItemKind;
use Phpactor\LanguageServerProtocol\CompletionList;
use Phpactor\LanguageServerProtocol\Hover;
use Phpactor\LanguageServerProtocol\InsertTextFormat;
use Phpactor\LanguageServerProtocol\InsertTextMode;
use Phpactor\LanguageServerProtocol\Location;
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
use Pxp\Pls\Providers\CompletionProvider;
use Pxp\Pls\Providers\DefinitionProvider;
use Pxp\Pls\Providers\HoverProvider;
use Pxp\TypeDeducer\Support;
use Pxp\TypeDeducer\TypeDeducer;
use Pxp\TypeDeducer\Types\NamedType;
use Roave\BetterReflection\Reflection\ReflectionProperty;

final class Pls
{
    private Pxp $parser;

    private HoverProvider $hoverProvider;

    private DefinitionProvider $definitionProvider;

    private CompletionProvider $completionProvider;

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
        $this->definitionProvider = new DefinitionProvider($this->parser);
        $this->completionProvider = new CompletionProvider($this->parser);
    }

    public function hover(string $directory, string $file, int $position): ?Hover
    {
        $code = file_get_contents($file);
        $ast = $this->parser->parse($code, new Collecting);

        $typeDeducer = new TypeDeducer([$directory], $file);
        $typeDeducer->setAst($ast);

        return $this->hoverProvider->provide($typeDeducer, $position);
    }

    public function definition(string $directory, string $file, int $position): ?array
    {
        $code = file_get_contents($file);
        $ast = $this->parser->parse($code, new Collecting);

        $typeDeducer = new TypeDeducer([$directory], $file);
        $typeDeducer->setAst($ast);

        return $this->definitionProvider->provide($typeDeducer, $position);
    }

    public function completion(string $directory, string $file, int $position): array
    {
        $code = file_get_contents($file);
        $ast = $this->parser->parse($code, new Collecting);

        $typeDeducer = new TypeDeducer([$directory, $file]);
        $typeDeducer->setAst($ast);

        return $this->completionProvider->provide($typeDeducer, $position);
    }
}