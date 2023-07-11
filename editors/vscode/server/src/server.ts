import { TextDocument } from "vscode-languageserver-textdocument";
import { CompletionItem, CompletionItemLabelDetails, DefinitionParams, Hover, HoverParams, InitializeParams, InitializeResult, Location, Position, ProposedFeatures, Range, TextDocumentPositionParams, TextDocumentSyncKind, TextDocuments, TextEdit, createConnection } from "vscode-languageserver/node";
import { promisify } from "util";
import * as child_process from 'child_process';
import * as fs from 'fs'
import which from 'which'
import tmp from 'tmp'
import { fileURLToPath } from "url";

const tmpFile = tmp.fileSync()
const phpPath = which.sync('php')
const plsPath = '/Users/ryan/Projects/Pxp/pls/bin/pls'
const connection = createConnection(ProposedFeatures.all)
const documents = new TextDocuments(TextDocument)
const exec = promisify(child_process.exec);
let folder

connection.onInitialize((params: InitializeParams) => {
    folder = fileURLToPath(decodeURI(params.workspaceFolders[0].uri))

    const result: InitializeResult = {
        capabilities: {
            textDocumentSync: TextDocumentSyncKind.Incremental,
            completionProvider: {
                resolveProvider: false,
                triggerCharacters: ['>', '$', '(', '@', ':', '\\'],
            },
            inlayHintProvider: false,
            definitionProvider: true,
            typeDefinitionProvider: false,
            documentSymbolProvider: false,
            hoverProvider: true,
            documentFormattingProvider: false,
            documentRangeFormattingProvider: false,
        }
    }

    return result
})

connection.onInitialized(() => {
    console.log('Initialized!')
})

connection.onHover(async (request: HoverParams): Promise<Hover | undefined> => {
    const document = documents.get(request.textDocument.uri);
    const text = document.getText();

    fs.writeFileSync(tmpFile.name, text)

    const index = positionToIndex(request.position, text) - 1
    const cmd = (await exec(`${phpPath} ${plsPath} hover ${folder} ${tmpFile.name} ${index}`))
    const stdout = cmd.stdout

    if (stdout.length <= 0) {
        return undefined
    }

    const hover = JSON.parse(stdout)

    return hover as Hover
})

connection.onDefinition(async (request: DefinitionParams): Promise<Location | undefined> => {
    console.log('Initiating definition request.');

    const document = documents.get(request.textDocument.uri);
    const text = document.getText();

    fs.writeFileSync(tmpFile.name, text)

    const index = positionToIndex(request.position, text) - 1
    const cmd = (await exec(`${phpPath} ${plsPath} definition ${folder} ${tmpFile.name} ${index}`))
    const stdout = cmd.stdout

    if (stdout.length <= 0) {
        return undefined
    }

    const location: { position?: number, file?: string, line?: number, column?: number } = JSON.parse(stdout)

    console.log(location)

    // If we just get a position back from the server, it means we're in the current file
    // and need to calculate the correct line & column numbers. It's actually faster to do
    // this with JavaScript instead of PHP because the JIT will recognise this as a hot-path
    // and optimise it for all future calls.
    if (location.position !== undefined) {
        const position = document.positionAt(location.position)
        return Location.create(request.textDocument.uri, Range.create(position, position))
    }

    return Location.create(location.file, Range.create(location.line, location.column, location.line, location.column));
})

connection.onCompletion(async (request: TextDocumentPositionParams): Promise<CompletionItem[]> => {
    const document = documents.get(request.textDocument.uri)
    const text = document.getText()
    
    // Since the code we're trying to autocomplete hasn't necessarily been saved yet,
    // we can write it to a temp file and try to autocomplete from that.
    fs.writeFileSync(tmpFile.name, text)
    
    const index = positionToIndex(request.position, text) - 1
    const cmd = (await exec(`${phpPath} ${plsPath} completion ${folder} ${tmpFile.name} ${index}`))
    const stdout = cmd.stdout
    
    return JSON.parse(stdout).map(({ label, kind, insertText, insertTextFormat, labelDetails }, index) => {
        return {
            label,
            labelDetails: {
                description: labelDetails?.description || undefined,
            },
            kind,
            insertText,
            insertTextFormat,
            data: index,
            // documentation: "Hello, **world**!"
        } as CompletionItem
    })
})

documents.listen(connection)
connection.listen()

function positionToIndex(position: Position, text: string): number {
    let line = 0;
    let character = 0;
    let i = 0;

    while (i < text.length) {
        if (line == position.line && character == position.character) {
            return i;
        }

        if (text[i] === "\n") {
            line++;
            character = 0;
        } else {
            character++;
        }

        i++;
    }

    return i;
}