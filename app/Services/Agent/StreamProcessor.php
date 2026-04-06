<?php

namespace App\Services\Agent;

use App\Services\Api\StreamEvent;

class StreamProcessor
{
    /** @var array<int, array{type: string, text?: string, id?: string, name?: string, input?: string}> */
    private array $contentBlocks = [];

    private ?string $stopReason = null;

    private bool $sawMessageDelta = false;

    private bool $sawMessageStop = false;

    private array $usage = [];

    private string $accumulatedText = '';

    private ?string $messageId = null;

    private ?string $model = null;

    /** @var callable|null Callback invoked when a tool_use block completes during streaming */
    private $onToolBlockComplete = null;

    /** @var array<int, bool> */
    private array $completedToolBlocks = [];

    /** @var callable|null Callback for thinking delta display */
    private $onThinkingDelta = null;

    private string $accumulatedThinking = '';

    public function processEvent(StreamEvent $event): void
    {
        $data = $event->data;
        if ($data === null) {
            return;
        }

        match ($event->type) {
            'message_start' => $this->handleMessageStart($data),
            'content_block_start' => $this->handleContentBlockStart($data),
            'content_block_delta' => $this->handleContentBlockDelta($data),
            'content_block_stop' => $this->handleContentBlockStop($data),
            'message_delta' => $this->handleMessageDelta($data),
            'message_stop' => $this->handleMessageStop(),
            'ping' => null,
            'error' => $this->handleError($data),
            default => null,
        };
    }

    public function setOnToolBlockComplete(callable $cb): void
    {
        $this->onToolBlockComplete = $cb;
    }

    public function setOnThinkingDelta(callable $cb): void
    {
        $this->onThinkingDelta = $cb;
    }

    public function getAccumulatedThinking(): string
    {
        return $this->accumulatedThinking;
    }

    public function hasThinking(): bool
    {
        return ! empty($this->accumulatedThinking);
    }

    private function handleMessageStart(array $data): void
    {
        $this->messageId = $data['message']['id'] ?? null;
        $this->model = $data['message']['model'] ?? null;
        $this->usage = $data['message']['usage'] ?? [];
    }

    private function handleContentBlockStart(array $data): void
    {
        $index = $data['index'];
        $block = $data['content_block'];

        $this->contentBlocks[$index] = [
            'type' => $block['type'],
            'text' => $block['text'] ?? '',
            'id' => $block['id'] ?? null,
            'name' => $block['name'] ?? null,
            'input' => '',
            'signature' => null, // populated by signature_delta for thinking blocks
        ];
    }

    private function handleContentBlockDelta(array $data): void
    {
        $index = $data['index'];
        $delta = $data['delta'];

        if (! isset($this->contentBlocks[$index])) {
            return;
        }

        $type = $this->contentBlocks[$index]['type'];

        if ($type === 'text' && ($delta['type'] ?? '') === 'text_delta') {
            $text = $delta['text'];
            $this->contentBlocks[$index]['text'] .= $text;
            $this->accumulatedText .= $text;
        } elseif ($type === 'tool_use' && ($delta['type'] ?? '') === 'input_json_delta') {
            $this->contentBlocks[$index]['input'] .= $delta['partial_json'];
        } elseif ($type === 'thinking' && ($delta['type'] ?? '') === 'thinking_delta') {
            $thinking = $delta['thinking'];
            $this->contentBlocks[$index]['text'] .= $thinking;
            $this->accumulatedThinking .= $thinking;
            if ($this->onThinkingDelta !== null) {
                ($this->onThinkingDelta)($thinking);
            }
        } elseif ($type === 'thinking' && ($delta['type'] ?? '') === 'signature_delta') {
            // The Anthropic API provides a signature for thinking blocks that MUST be
            // included when thinking blocks are passed back in subsequent turns.
            // Without it, the API rejects the conversation history.
            $this->contentBlocks[$index]['signature'] = $delta['signature'] ?? null;
        }
    }

    private function handleMessageDelta(array $data): void
    {
        $this->sawMessageDelta = true;
        $this->stopReason = $data['delta']['stop_reason'] ?? null;
        if (isset($data['usage'])) {
            $this->usage = array_merge($this->usage, $data['usage']);
        }

        if ($this->stopReason === 'tool_use') {
            foreach (array_keys($this->contentBlocks) as $index) {
                $this->completeToolBlock($index);
            }
        }
    }

    private function handleMessageStop(): void
    {
        $this->sawMessageStop = true;
    }

    private function handleContentBlockStop(array $data): void
    {
        $index = $data['index'] ?? null;
        if ($index === null || ! isset($this->contentBlocks[$index])) {
            return;
        }

        $this->completeToolBlock($index);
    }

    private function completeToolBlock(int $index): void
    {
        if (($this->completedToolBlocks[$index] ?? false) || ! isset($this->contentBlocks[$index])) {
            return;
        }

        $block = $this->contentBlocks[$index];
        if ($block['type'] !== 'tool_use') {
            return;
        }

        $this->completedToolBlocks[$index] = true;

        if ($this->onToolBlockComplete !== null) {
            $decodedInput = $this->decodeToolInput((string) ($block['input'] ?? ''));
            ($this->onToolBlockComplete)(
                [
                    'id' => $block['id'],
                    'name' => $block['name'],
                    'input' => $decodedInput['input'],
                    'raw_input' => $decodedInput['raw_input'],
                    'input_json_error' => $decodedInput['input_json_error'],
                ],
                $index,
            );
        }
    }

    private function handleError(array $data): void
    {
        $errorMsg = $data['error']['message'] ?? 'Unknown streaming error';
        throw new \RuntimeException("API Error: {$errorMsg}");
    }

    public function getAccumulatedText(): string
    {
        return $this->accumulatedText;
    }

    /**
     * @return array<int, array{id: string, name: string, input: array, raw_input: string, input_json_error: ?string}>
     */
    public function getToolUseBlocks(): array
    {
        return array_values($this->getIndexedToolUseBlocks());
    }

    /**
     * @return array<int, array{id: string, name: string, input: array, raw_input: string, input_json_error: ?string}>
     */
    public function getIndexedToolUseBlocks(): array
    {
        $blocks = [];
        foreach ($this->contentBlocks as $index => $block) {
            if ($block['type'] === 'tool_use') {
                $decodedInput = $this->decodeToolInput((string) ($block['input'] ?? ''));
                $blocks[$index] = [
                    'id' => $block['id'],
                    'name' => $block['name'],
                    'input' => $decodedInput['input'],
                    'raw_input' => $decodedInput['raw_input'],
                    'input_json_error' => $decodedInput['input_json_error'],
                ];
            }
        }

        return $blocks;
    }

    public function hasToolUse(): bool
    {
        return ! empty($this->getToolUseBlocks());
    }

    public function getStopReason(): ?string
    {
        return $this->stopReason;
    }

    public function hasFinalMessageEvent(): bool
    {
        return $this->sawMessageDelta || $this->sawMessageStop;
    }

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function getUsage(): array
    {
        return $this->usage;
    }

    public function toAssistantMessage(): array
    {
        $content = [];
        foreach ($this->contentBlocks as $block) {
            if ($block['type'] === 'text') {
                $content[] = ['type' => 'text', 'text' => $block['text']];
            } elseif ($block['type'] === 'tool_use') {
                $decodedInput = $this->decodeToolInput((string) ($block['input'] ?? ''));
                $content[] = [
                    'type' => 'tool_use',
                    'id' => $block['id'],
                    'name' => $block['name'],
                    'input' => $decodedInput['input'],
                ];
            } elseif ($block['type'] === 'thinking') {
                $thinking = [
                    'type' => 'thinking',
                    'thinking' => $block['text'],
                ];
                if ($block['signature'] !== null) {
                    $thinking['signature'] = $block['signature'];
                }
                $content[] = $thinking;
            }
        }

        return ['role' => 'assistant', 'content' => $content];
    }

    public function reset(): void
    {
        $this->contentBlocks = [];
        $this->completedToolBlocks = [];
        $this->stopReason = null;
        $this->sawMessageDelta = false;
        $this->sawMessageStop = false;
        $this->usage = [];
        $this->accumulatedText = '';
        $this->accumulatedThinking = '';
        $this->messageId = null;
    }

    /**
     * @return array{input: array, raw_input: string, input_json_error: ?string}
     */
    private function decodeToolInput(string $rawInput): array
    {
        if ($rawInput === '') {
            return [
                'input' => [],
                'raw_input' => $rawInput,
                'input_json_error' => 'Tool input JSON was empty.',
            ];
        }

        $decoded = json_decode($rawInput, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $repairAttempt = $this->repairControlCharactersInsideJsonStrings($rawInput);
            if ($repairAttempt !== null) {
                $decoded = json_decode($repairAttempt, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    return [
                        'input' => $decoded,
                        'raw_input' => $rawInput,
                        'input_json_error' => null,
                    ];
                }
            }

            return [
                'input' => [],
                'raw_input' => $rawInput,
                'input_json_error' => 'Tool input JSON could not be parsed: ' . json_last_error_msg() . '.',
            ];
        }

        if (!is_array($decoded)) {
            return [
                'input' => [],
                'raw_input' => $rawInput,
                'input_json_error' => 'Tool input must decode to a JSON object.',
            ];
        }

        return [
            'input' => $decoded,
            'raw_input' => $rawInput,
            'input_json_error' => null,
        ];
    }

    private function repairControlCharactersInsideJsonStrings(string $rawInput): ?string
    {
        $repaired = '';
        $inString = false;
        $isEscaped = false;
        $changed = false;

        $length = strlen($rawInput);
        for ($index = 0; $index < $length; $index++) {
            $char = $rawInput[$index];

            if ($isEscaped) {
                $repaired .= $char;
                $isEscaped = false;
                continue;
            }

            if ($char === '\\') {
                $repaired .= $char;
                $isEscaped = true;
                continue;
            }

            if ($char === '"') {
                $repaired .= $char;
                $inString = !$inString;
                continue;
            }

            if ($inString) {
                $ord = ord($char);
                if ($ord < 0x20) {
                    $repaired .= match ($char) {
                        "\n" => '\\n',
                        "\r" => '\\r',
                        "\t" => '\\t',
                        "\f" => '\\f',
                        "\b" => '\\b',
                        default => sprintf('\\u%04x', $ord),
                    };
                    $changed = true;
                    continue;
                }
            }

            $repaired .= $char;
        }

        return $changed ? $repaired : null;
    }
}
