<?php

namespace App\Tools\AskUserQuestion;

use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;

class AskUserQuestionTool extends BaseTool
{
    public function name(): string
    {
        return 'AskUserQuestion';
    }

    public function description(): string
    {
        return <<<DESC
Use this tool when you need to ask the user questions during execution.
Allows you to gather user preferences, clarify ambiguous instructions, or get decisions on implementation choices.
Users can always select "Other" to provide custom text input.
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'questions' => [
                    'type' => 'array',
                    'description' => 'Questions to ask the user (1-4 questions)',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'question' => ['type' => 'string', 'description' => 'The complete question to ask'],
                            'header' => ['type' => 'string', 'description' => 'Short label (max 12 chars)'],
                            'options' => [
                                'type' => 'array',
                                'description' => 'Available choices (2-4 options)',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'label' => ['type' => 'string'],
                                        'description' => ['type' => 'string'],
                                    ],
                                    'required' => ['label'],
                                ],
                            ],
                        ],
                        'required' => ['question', 'header', 'options'],
                    ],
                    'minItems' => 1,
                    'maxItems' => 4,
                ],
            ],
            'required' => ['questions'],
        ], [
            'questions' => 'required|array|min:1|max:4',
            'questions.*.question' => 'required|string',
            'questions.*.header' => 'required|string|max:12',
            'questions.*.options' => 'required|array|min:2|max:4',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        // This tool requires interactive terminal input
        // For now, return the first option as default
        $answers = [];
        foreach ($input['questions'] as $q) {
            $question = $q['question'];
            $options = $q['options'] ?? [];
            $firstOption = $options[0]['label'] ?? 'N/A';

            $answers[$question] = $firstOption;
        }

        $output = "User answers (auto-selected first option - interactive mode needed for real prompts):\n";
        foreach ($answers as $q => $a) {
            $output .= "  Q: {$q}\n  A: {$a}\n\n";
        }

        return ToolResult::success($output);
    }

    public function isReadOnly(array $input): bool
    {
        return true;
    }
}
