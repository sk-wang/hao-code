<?php

namespace App\Services\Mcp;

/**
 * Transport layer for communicating with an MCP server via JSON-RPC 2.0.
 * Supports stdio (subprocess), http (streamable HTTP), and sse transports.
 */
final class McpTransport
{
    private ?int $nextId = 1;

    /** @var resource|null stdio process handle */
    private $process = null;

    /** @var resource|null stdin pipe */
    private $stdin = null;

    /** @var resource|null stdout pipe */
    private $stdout = null;

    /** @var string read buffer for stdio */
    private string $readBuffer = '';

    private ?string $httpSessionId = null;

    private function __construct(
        private readonly string $transport,
        private readonly ?string $command,
        private readonly array $args,
        private readonly ?string $url,
        private readonly array $env,
        private readonly array $headers,
    ) {}

    /**
     * Create a transport from a normalized server config array.
     *
     * @param array{transport: string, command: ?string, args: array, url: ?string, env: array, headers: array} $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            transport: $config['transport'],
            command: $config['command'] ?? null,
            args: $config['args'] ?? [],
            url: $config['url'] ?? null,
            env: $config['env'] ?? [],
            headers: $config['headers'] ?? [],
        );
    }

    public function getTransportType(): string
    {
        return $this->transport;
    }

    /**
     * Open the transport connection.
     *
     * @throws McpConnectionException
     */
    public function connect(int $timeoutSeconds = 30): void
    {
        match ($this->transport) {
            'stdio' => $this->connectStdio(),
            'http', 'sse' => null, // HTTP transports are stateless per-request
            default => throw new McpConnectionException("Unsupported transport: {$this->transport}"),
        };
    }

    /**
     * Send a JSON-RPC request and return the result.
     *
     * @return mixed The 'result' field from the JSON-RPC response
     * @throws McpConnectionException on transport or protocol errors
     */
    public function request(string $method, array $params = [], int $timeoutSeconds = 60): mixed
    {
        $id = $this->nextId++;
        $message = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => (object) $params,
        ];

        return match ($this->transport) {
            'stdio' => $this->sendStdio($message, $timeoutSeconds),
            'http' => $this->sendHttp($message, $timeoutSeconds),
            'sse' => $this->sendHttp($message, $timeoutSeconds),
            default => throw new McpConnectionException("Unsupported transport: {$this->transport}"),
        };
    }

    /**
     * Send a JSON-RPC notification (no response expected).
     */
    public function notify(string $method, array $params = []): void
    {
        $message = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => (object) $params,
        ];

        match ($this->transport) {
            'stdio' => $this->writeStdio($message),
            'http', 'sse' => $this->sendHttpNotification($message),
            default => null,
        };
    }

    /**
     * Close the transport and release resources.
     */
    public function close(): void
    {
        if ($this->stdin !== null) {
            @fclose($this->stdin);
            $this->stdin = null;
        }
        if ($this->stdout !== null) {
            @fclose($this->stdout);
            $this->stdout = null;
        }
        if ($this->process !== null) {
            // Send SIGTERM, then SIGKILL after a short wait
            $status = proc_get_status($this->process);
            if ($status['running']) {
                proc_terminate($this->process, 15); // SIGTERM
                usleep(200_000);
                $status = proc_get_status($this->process);
                if ($status['running']) {
                    proc_terminate($this->process, 9); // SIGKILL
                }
            }
            proc_close($this->process);
            $this->process = null;
        }
        $this->readBuffer = '';
    }

    public function isConnected(): bool
    {
        if ($this->transport === 'stdio') {
            return $this->process !== null && proc_get_status($this->process)['running'];
        }

        // HTTP transports are always "connected" as long as we have a URL
        return $this->url !== null;
    }

    // ─── stdio transport ────────────────────────────────────────────────

    private function connectStdio(): void
    {
        if ($this->command === null) {
            throw new McpConnectionException('Stdio transport requires a command');
        }

        $cmd = $this->command;
        if (!empty($this->args)) {
            $cmd .= ' ' . implode(' ', array_map('escapeshellarg', $this->args));
        }

        $env = array_merge(getenv(), $this->env);

        $descriptorSpec = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr (discard)
        ];

        $this->process = proc_open($cmd, $descriptorSpec, $pipes, null, $env);

        if (!is_resource($this->process)) {
            throw new McpConnectionException("Failed to start MCP server: {$cmd}");
        }

        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];

        // Set stdout to non-blocking for timeout support
        stream_set_blocking($this->stdout, false);

        // Close stderr pipe to avoid blocking
        @fclose($pipes[2]);
    }

    private function writeStdio(array $message): void
    {
        if ($this->stdin === null) {
            throw new McpConnectionException('Stdio transport not connected');
        }

        $json = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $written = @fwrite($this->stdin, $json . "\n");

        if ($written === false) {
            throw new McpConnectionException('Failed to write to MCP server stdin');
        }

        @fflush($this->stdin);
    }

    private function sendStdio(array $message, int $timeoutSeconds): mixed
    {
        $this->writeStdio($message);

        $deadline = microtime(true) + $timeoutSeconds;
        $expectedId = $message['id'];

        while (microtime(true) < $deadline) {
            // Try to read a complete JSON-RPC message (newline-delimited)
            $chunk = @fread($this->stdout, 65536);
            if ($chunk !== false && $chunk !== '') {
                $this->readBuffer .= $chunk;
            }

            // Try to extract complete lines
            while (($newlinePos = strpos($this->readBuffer, "\n")) !== false) {
                $line = substr($this->readBuffer, 0, $newlinePos);
                $this->readBuffer = substr($this->readBuffer, $newlinePos + 1);

                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $response = json_decode($line, true);
                if (!is_array($response)) {
                    continue;
                }

                // Skip notifications (no id)
                if (!isset($response['id'])) {
                    continue;
                }

                if ($response['id'] === $expectedId) {
                    if (isset($response['error'])) {
                        throw new McpConnectionException(
                            'MCP error: ' . ($response['error']['message'] ?? 'Unknown error'),
                            (int) ($response['error']['code'] ?? 0),
                        );
                    }
                    return $response['result'] ?? null;
                }
            }

            // Small sleep to avoid busy-waiting
            usleep(10_000);
        }

        throw new McpConnectionException("MCP request timed out after {$timeoutSeconds}s: {$message['method']}");
    }

    // ─── HTTP transport (streamable HTTP / SSE) ─────────────────────────

    private function sendHttp(array $message, int $timeoutSeconds): mixed
    {
        if ($this->url === null) {
            throw new McpConnectionException('HTTP transport requires a URL');
        }

        $json = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $headers = array_merge([
            'Content-Type: application/json',
            'Accept: application/json, text/event-stream',
        ], $this->buildHttpHeaders());

        if ($this->httpSessionId !== null) {
            $headers[] = 'Mcp-Session-Id: ' . $this->httpSessionId;
        }

        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HEADER => true,
        ]);

        $rawResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($rawResponse === false) {
            throw new McpConnectionException("HTTP request failed: {$curlError}");
        }

        $responseHeaders = substr($rawResponse, 0, $headerSize);
        $responseBody = substr($rawResponse, $headerSize);

        // Extract session ID from response headers
        if (preg_match('/^Mcp-Session-Id:\s*(.+)$/mi', $responseHeaders, $m)) {
            $this->httpSessionId = trim($m[1]);
        }

        if ($httpCode === 401) {
            throw new McpConnectionException('MCP server authentication required (401)', 401);
        }

        if ($httpCode === 404) {
            // Session expired
            $this->httpSessionId = null;
            throw new McpConnectionException('MCP session expired (404)', 404);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new McpConnectionException("MCP HTTP error {$httpCode}: " . substr($responseBody, 0, 500));
        }

        // Check if response is SSE (text/event-stream)
        $contentType = '';
        if (preg_match('/^Content-Type:\s*([^\r\n;]+)/mi', $responseHeaders, $m)) {
            $contentType = trim($m[1]);
        }

        if ($contentType === 'text/event-stream') {
            return $this->parseSSEResponse($responseBody, $message['id']);
        }

        // Standard JSON response
        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            throw new McpConnectionException('Invalid JSON response from MCP server');
        }

        if (isset($decoded['error'])) {
            throw new McpConnectionException(
                'MCP error: ' . ($decoded['error']['message'] ?? 'Unknown error'),
                (int) ($decoded['error']['code'] ?? 0),
            );
        }

        return $decoded['result'] ?? null;
    }

    private function sendHttpNotification(array $message): void
    {
        if ($this->url === null) {
            return;
        }

        $json = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $headers = array_merge([
            'Content-Type: application/json',
        ], $this->buildHttpHeaders());

        if ($this->httpSessionId !== null) {
            $headers[] = 'Mcp-Session-Id: ' . $this->httpSessionId;
        }

        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);

        curl_exec($ch);
        curl_close($ch);
    }

    /**
     * @return array<int, string>
     */
    private function buildHttpHeaders(): array
    {
        $result = [];
        foreach ($this->headers as $key => $value) {
            $result[] = "{$key}: {$value}";
        }
        return $result;
    }

    /**
     * Parse an SSE (text/event-stream) response body and extract the JSON-RPC result.
     */
    private function parseSSEResponse(string $body, int $expectedId): mixed
    {
        $events = preg_split('/\r?\n\r?\n/', $body);

        foreach ($events as $event) {
            $data = '';
            foreach (explode("\n", $event) as $line) {
                if (str_starts_with($line, 'data: ')) {
                    $data .= substr($line, 6);
                }
            }

            if ($data === '') {
                continue;
            }

            $decoded = json_decode($data, true);
            if (!is_array($decoded) || !isset($decoded['id'])) {
                continue;
            }

            if ($decoded['id'] === $expectedId) {
                if (isset($decoded['error'])) {
                    throw new McpConnectionException(
                        'MCP error: ' . ($decoded['error']['message'] ?? 'Unknown error'),
                        (int) ($decoded['error']['code'] ?? 0),
                    );
                }
                return $decoded['result'] ?? null;
            }
        }

        throw new McpConnectionException('No matching response found in SSE stream');
    }
}
