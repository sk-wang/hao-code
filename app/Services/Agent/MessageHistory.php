<?php

namespace App\Services\Agent;

class MessageHistory
{
    private array $messages = [];

    public function addUserMessage(string $text): void
    {
        $this->messages[] = ['role' => 'user', 'content' => $text];
    }

    public function addAssistantMessage(array $message): void
    {
        $this->messages[] = $message;
    }

    /**
     * @param array<int, array{tool_use_id: string, content: string, is_error: bool}> $toolResults
     */
    public function addToolResultMessage(array $toolResults): void
    {
        $content = array_map(function (array $result) {
            return [
                'type' => 'tool_result',
                'tool_use_id' => $result['tool_use_id'],
                'content' => $result['content'],
                'is_error' => $result['is_error'] ?? false,
            ];
        }, $toolResults);

        $this->messages[] = ['role' => 'user', 'content' => $content];
    }

    /**
     * Get messages formatted for the Anthropic API.
     * Adds cache_control breakpoints for prompt caching on the penultimate message.
     */
    public function getMessagesForApi(): array
    {
        $messages = $this->messages;
        $count = count($messages);

        // Add cache_control breakpoint on the penultimate message (the one before the last user message).
        // This allows the conversation prefix to be cached while keeping the latest exchange fresh.
        if ($count >= 3) {
            $cacheIdx = $count - 2;
            $messages[$cacheIdx] = $this->addCacheControl($messages[$cacheIdx]);
        }

        return array_map(
            fn (array $message): array => $this->normalizeMessageForApi($message),
            $messages,
        );
    }

    /**
     * Add cache_control to the last content block of a message.
     */
    private function addCacheControl(array $message): array
    {
        if (is_string($message['content'])) {
            // Simple text message: convert to content block format
            $message['content'] = [
                ['type' => 'text', 'text' => $message['content'], 'cache_control' => ['type' => 'ephemeral']],
            ];
            return $message;
        }

        if (is_array($message['content']) && !empty($message['content'])) {
            // Content blocks: add cache_control to the last block
            $lastIdx = count($message['content']) - 1;
            $message['content'][$lastIdx]['cache_control'] = ['type' => 'ephemeral'];
        }

        return $message;
    }

    private function normalizeMessageForApi(array $message): array
    {
        if (! is_array($message['content'] ?? null)) {
            return $message;
        }

        foreach ($message['content'] as $index => $block) {
            if (($block['type'] ?? null) !== 'tool_use') {
                continue;
            }

            if (($block['input'] ?? null) === []) {
                $message['content'][$index]['input'] = (object) [];
            }
        }

        return $message;
    }

    public function count(): int
    {
        return count($this->messages);
    }

    public function clear(): void
    {
        $this->messages = [];
    }

    public function getMessages(): array
    {
        return $this->messages;
    }

    public function getLastAssistantText(): ?string
    {
        for ($i = count($this->messages) - 1; $i >= 0; $i--) {
            $msg = $this->messages[$i];
            if ($msg['role'] === 'assistant') {
                // Handle string content (simple text messages)
                if (is_string($msg['content'])) {
                    return $msg['content'] !== '' ? $msg['content'] : null;
                }
                // Handle array content blocks
                if (is_array($msg['content'])) {
                    foreach ($msg['content'] as $block) {
                        if (($block['type'] ?? '') === 'text' && !empty($block['text'])) {
                            return $block['text'];
                        }
                    }
                }
            }
        }
        return null;
    }
}
