import { ExtensionContext } from "vscode";
import { Output } from "./output";
import { LanguageClient, LanguageClientOptions, ServerOptions, TransportKind } from 'vscode-languageclient/node'
import * as path from 'path'

let client: LanguageClient

export function activate(context: ExtensionContext) {
    Output.write("vscode-pls activated!");

    const serverModule = context.asAbsolutePath(
        path.join('out', 'server', 'src', 'server.js')
    )

    const debugOptions = { execArgv: ['--nolazy', '--inspect=6009'] }
    const serverOptions: ServerOptions = {
        run: { module: serverModule, transport: TransportKind.ipc },
        debug: {
            module: serverModule,
            transport: TransportKind.ipc,
            options: debugOptions
        }
    }

    const clientOptions: LanguageClientOptions = {
        documentSelector: [{ scheme: 'file', language: 'pxp' }]
    }

    Output.write("Creating LanguageClient object")

    client = new LanguageClient(
        'pls',
        'PHP and PXP Language Server (Client)',
        serverOptions,
        clientOptions,
        false
    )

    Output.write("Starting client")

    client.start()
    client.outputChannel.show(true)

    Output.write("Client started")
}

export function deactivate(): Thenable<void> | undefined {
    if (! client) {
        return undefined;
    }

    return client.stop()
}