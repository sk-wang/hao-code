<?php

namespace App\Services\Lsp;

/**
 * Represents an LSP server process communicating via stdio.
 */
class LspServerProcess
{
    private $process = null;
    private $input = null;
    private $output = null;
    private int $requestId = 0;
    private bool $initialized = false;

    public function __construct(
        private readonly string $command,
    ) {}

    public function initialize(string $rootPath): bool
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $this->process = proc_open($this->command, $descriptors, $pipes, $rootPath);

        if (!is_resource($this->process)) {
            return false;
        }

        $this->input = $pipes[0];
        $this->output = $pipes[1];
        fclose($pipes[2]); // Ignore stderr

        // Send initialize request
        $response = $this->sendRequest('initialize', [
            'processId' => getmypid(),
            'rootUri' => 'file://' . $rootPath,
            'capabilities' => (object) [],
        ]);

        if ($response === null) {
            return false;
        }

        // Send initialized notification
        $this->sendNotification('initialized', (object) []);
        $this->initialized = true;
        return true;
    }

    public function goToDefinition(string $filePath, int $line, int $character): ?array
    {
        return $this->sendRequest('textDocument/definition', [
            'textDocument' => ['uri' => 'file://' . $filePath],
            'position' => ['line' => $line, 'character' => $character],
        ]);
    }

    public function findReferences(string $filePath, int $line, int $character): ?array
    {
        $response = $this->sendRequest('textDocument/references', [
            'textDocument' => ['uri' => 'file://' . $filePath],
            'position' => ['line' => $line, 'character' => $character],
            'context' => ['includeDeclaration' => true],
        ]);

        return is_array($response) ? $response : null;
    }

    public function hover(string $filePath, int $line, int $character): ?array
    {
        return $this->sendRequest('textDocument/hover', [
            'textDocument' => ['uri' => 'file://' . $filePath],
            'position' => ['line' => $line, 'character' => $character],
        ]);
    }

    public function documentSymbol(string $filePath): ?array
    {
        $response = $this->sendRequest('textDocument/documentSymbol', [
            'textDocument' => ['uri' => 'file://' . $filePath],
        ]);

        return is_array($response) ? $response : null;
    }

    public function workspaceSymbol(string $query): ?array
    {
        $response = $this->sendRequest('workspace/symbol', [
            'query' => $query,
        ]);

        return is_array($response) ? $response : null;
    }

    private function sendRequest(string $method, array|object $params): mixed
    {
        if ($this->input === null || $this->output === null) {
            return null;
        }

        $id = ++$this->requestId;
        $message = json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ], JSON_UNESCAPED_SLASHES);

        $this->writeMessage($message);
        return $this->readResponse($id);
    }

    private function sendNotification(string $method, array|object $params): void
    {
        if ($this->input === null) {
            return;
        }

        $message = json_encode([
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
        ], JSON_UNESCAPED_SLASHES);

        $this->writeMessage($message);
    }

    private function writeMessage(string $message): void
    {
        $header = "Content-Length: " . strlen($message) . "\r\n\r\n";
        fwrite($this->input, $header . $message);
        fflush($this->input);
    }

    private function readResponse(int $expectedId, float $timeout = 10.0): mixed
    {
        $startTime = microtime(true);

        while (microtime(true) - $startTime < $timeout) {
            // Read headers
            $headers = '';
            while (($line = fgets($this->output)) !== false) {
                $headers .= $line;
                if ($line === "\r\n") break;
            }

            if (!preg_match('/Content-Length:\s*(\d+)/i', $headers, $m)) {
                continue;
            }

            $length = (int) $m[1];
            $body = '';
            $remaining = $length;
            while ($remaining > 0) {
                $chunk = fread($this->output, $remaining);
                if ($chunk === false) break;
                $body .= $chunk;
                $remaining -= strlen($chunk);
            }

            $data = json_decode($body, true);
            if (!$data) continue;

            // Skip notifications
            if (isset($data['method'])) continue;

            // Check for matching response ID
            if (($data['id'] ?? null) === $expectedId) {
                return $data['result'] ?? null;
            }
        }

        return null;
    }

    public function shutdown(): void
    {
        if ($this->input !== null) {
            $this->sendRequest('shutdown', (object) []);
            $this->sendNotification('exit', (object) []);
            fclose($this->input);
        }
        if ($this->output !== null) {
            fclose($this->output);
        }
        if (is_resource($this->process)) {
            proc_close($this->process);
        }
        $this->input = null;
        $this->output = null;
        $this->process = null;
    }

    public function __destruct()
    {
        $this->shutdown();
    }
}
