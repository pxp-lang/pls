import { TextDocument } from "vscode-languageserver-textdocument";
import { CompletionItem, InitializeParams, InitializeResult, Position, ProposedFeatures, TextDocumentPositionParams, TextDocumentSyncKind, TextDocuments, createConnection } from "vscode-languageserver/node";
import { promisify } from "util";
import * as child_process from 'child_process';

const plsPath = '/Users/ryan/Projects/Pxp/pls/bin/pls'
const connection = createConnection(ProposedFeatures.all)
const documents = new TextDocuments(TextDocument)
const exec = promisify(child_process.exec);

connection.onInitialize((params: InitializeParams) => {
    const result: InitializeResult = {
        capabilities: {
            textDocumentSync: TextDocumentSyncKind.Incremental,
            completionProvider: {
                resolveProvider: false,
                triggerCharacters: ['>', '$', '(', '@']
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
    console.log('initialized!')
})

connection.onCompletion(async (request: TextDocumentPositionParams): Promise<CompletionItem[]> => {
    const document = documents.get(request.textDocument.uri)
    const text = document.getText()
    const index = positionToIndex(request.position, text) - 1

    console.log(index)

    return []
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