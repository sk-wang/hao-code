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

    public function getMessagesForApi(): array
    {
        return $this->messages;
    }

    public function count(): int
    {
        return count($this->messages);
    }

    public function clear(): void
    {
        $this->messages = [];
    }

    public function getLastAssistantText(): ?string
    {
        for ($i = count($this->messages) - 1; $i >= 0; $i--) {
            $msg = $this->messages[$i];
            if ($msg['role'] === 'assistant') {
                foreach ($msg['content'] as $block) {
                    if (($block['type'] ?? '') === 'text' && !empty($block['text'])) {
                        return $block['text'];
                    }
                }
            }
        }
        return null;
    }
}
