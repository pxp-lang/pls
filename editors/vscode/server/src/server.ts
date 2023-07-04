import { TextDocument } from "vscode-languageserver-textdocument";
import { CompletionItem, CompletionItemKind, DocumentFormattingParams, InitializeParams, InitializeResult, Position, ProposedFeatures, TextDocumentPositionParams, TextDocumentSyncKind, TextDocuments, TextEdit, createConnection } from "vscode-languageserver/node";
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
    console.log(folder)

    const result: InitializeResult = {
        capabilities: {
            textDocumentSync: TextDocumentSyncKind.Incremental,
            completionProvider: {
                resolveProvider: false,
                triggerCharacters: ['>', '$', '(', '@', ':']
            },
            inlayHintProvider: false,
            definitionProvider: false,
            typeDefinitionProvider: false,
            documentSymbolProvider: false,
            hoverProvider: false,
            documentFormattingProvider: false,
            documentRangeFormattingProvider: false,
        }
    }

    return result
})

connection.onInitialized(() => {
    console.log('Initialized!')
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
    const items: CompletionItem[] = JSON.parse(stdout).items
    
    return items.map(({ label, kind, insertText, insertTextFormat }, index) => ({
        label,
        kind,
        insertText,
        insertTextFormat,
        data: index,
        // documentation: "Hello, **world**!"
    }))
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