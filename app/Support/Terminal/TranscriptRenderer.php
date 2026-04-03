<?php

namespace App\Support\Terminal;

class TranscriptRenderer
{
    public function render(array $messages): string
    {
        $chunks = [];

        foreach ($messages as $message) {
            $role = $message['role'] ?? 'unknown';
            $content = $message['content'] ?? '';

            $chunk = match ($role) {
                'assistant' => $this->renderAssistantMessage($content),
                'user' => $this->renderUserMessage($content),
                default => $this->renderUnknownMessage($content),
            };

            if ($chunk !== '') {
                $chunks[] = rtrim($chunk);
            }
        }

        return implode("\n\n", $chunks);
    }

    private function renderUserMessage(mixed $content): string
    {
        if (is_string($content)) {
            return "You\n" . $this->indent($content);
        }

        if (! is_array($content)) {
            return '';
        }

        $lines = [];
        foreach ($content as $block) {
            if (($block['type'] ?? '') !== 'tool_result') {
                continue;
            }

            $label = ($block['is_error'] ?? false) ? 'Tool error' : 'Tool result';
            $toolUseId = (string) ($block['tool_use_id'] ?? 'unknown');
            $text = is_string($block['content'] ?? null) ? $block['content'] : json_encode($block['content'] ?? [], JSON_UNESCAPED_UNICODE);
            $lines[] = "{$label} ({$toolUseId})";
            $lines[] = $this->indent($text ?? '');
        }

        return implode("\n", array_filter($lines, fn (string $line): bool => $line !== ''));
    }

    private function renderAssistantMessage(mixed $content): string
    {
        if (is_string($content)) {
            return "Hao\n" . $this->indent($content);
        }

        if (! is_array($content)) {
            return '';
        }

        $lines = ['Hao'];

        foreach ($content as $block) {
            $type = $block['type'] ?? '';

            if ($type === 'text') {
                $text = (string) ($block['text'] ?? '');
                if ($text !== '') {
                    $lines[] = $this->indent($text);
                }
                continue;
            }

            if ($type === 'tool_use') {
                $toolName = (string) ($block['name'] ?? 'unknown');
                $input = $block['input'] ?? [];
                $summary = $this->summarizeToolInput($input);
                $lines[] = $this->indent("[Tool: {$toolName}]");
                if ($summary !== '') {
                    $lines[] = $this->indent($summary, 4);
                }
            }
        }

        return implode("\n", $lines);
    }

    private function renderUnknownMessage(mixed $content): string
    {
        if (is_string($content)) {
            return "Message\n" . $this->indent($content);
        }

        return '';
    }

    private function summarizeToolInput(mixed $input): string
    {
        if (! is_array($input) || $input === []) {
            return '';
        }

        $json = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($json)) {
            return '';
        }

        return mb_strlen($json) > 240 ? mb_substr($json, 0, 237) . '...' : $json;
    }

    private function indent(string $text, int $spaces = 2): string
    {
        $prefix = str_repeat(' ', $spaces);
        $lines = preg_split('/\R/u', $text) ?: [''];

        return implode("\n", array_map(
            static fn (string $line): string => $prefix . $line,
            $lines,
        ));
    }
}
