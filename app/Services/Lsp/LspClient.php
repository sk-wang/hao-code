<?php

namespace App\Services\Lsp;

/**
 * Basic LSP client that communicates with language servers via stdio.
 * Supports: goToDefinition, findReferences, hover, documentSymbol.
 */
class LspClient
{
    private static array $servers = [];

    /**
     * Get or create an LSP server process.
     */
    public static function getServer(string $language): ?LspServerProcess
    {
        if (isset(self::$servers[$language])) {
            return self::$servers[$language];
        }

        $command = self::getServerCommand($language);
        if ($command === null) {
            return null;
        }

        $server = new LspServerProcess($command);
        if ($server->initialize(getcwd())) {
            self::$servers[$language] = $server;
            return $server;
        }

        return null;
    }

    /**
     * Map language to LSP server command.
     */
    private static function getServerCommand(string $language): ?string
    {
        return match ($language) {
            'typescript', 'javascript', 'ts', 'js' => self::findCommand(['typescript-language-server --stdio', 'npx typescript-language-server --stdio']),
            'php' => self::findCommand(['phpactor', 'phan']),
            'python', 'py' => self::findCommand(['pylsp', 'pyright-langserver --stdio', 'pyright']),
            'go' => self::findCommand(['gopls']),
            'rust' => self::findCommand(['rust-analyzer']),
            'java' => self::findCommand(['jdtls']),
            default => null,
        };
    }

    private static function findCommand(array $commands): ?string
    {
        foreach ($commands as $cmd) {
            $binary = explode(' ', $cmd)[0];
            $result = shell_exec("which {$binary} 2>/dev/null");
            if (!empty(trim($result ?? ''))) {
                return $cmd;
            }
        }
        return null;
    }

    /**
     * Detect language from file extension.
     */
    public static function detectLanguage(string $filePath): string
    {
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        return match ($ext) {
            'ts', 'tsx' => 'typescript',
            'js', 'jsx', 'mjs' => 'javascript',
            'php' => 'php',
            'py', 'pyi', 'pyw' => 'python',
            'go' => 'go',
            'rs' => 'rust',
            'java' => 'java',
            'c', 'h' => 'c',
            'cpp', 'cc', 'cxx', 'hpp' => 'cpp',
            'rb' => 'ruby',
            'swift' => 'swift',
            'kt' => 'kotlin',
            'scala' => 'scala',
            default => 'unknown',
        };
    }

    /**
     * Shutdown all server processes.
     */
    public static function shutdownAll(): void
    {
        foreach (self::$servers as $server) {
            $server->shutdown();
        }
        self::$servers = [];
    }
}
