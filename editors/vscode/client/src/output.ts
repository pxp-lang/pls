import { window } from "vscode";

const outputChannel = window.createOutputChannel("PHP and PXP Language Server")

export class Output {
    static write(text: string) {
        outputChannel.appendLine('> ' + text)
    }

    static clear() {
        outputChannel.clear()
    }
}