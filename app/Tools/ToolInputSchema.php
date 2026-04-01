<?php

namespace App\Tools;

use Illuminate\Support\Facades\Validator;

class ToolInputSchema
{
    public function __construct(
        private readonly array $jsonSchema,
        private readonly array $validationRules = [],
    ) {}

    /**
     * Create from a raw JSON Schema definition.
     */
    public static function make(array $jsonSchema, array $validationRules = []): self
    {
        return new self($jsonSchema, $validationRules);
    }

    /**
     * Get the JSON Schema for the Anthropic API.
     */
    public function toJsonSchema(): array
    {
        return $this->jsonSchema;
    }

    /**
     * Validate input against the schema rules.
     */
    public function validate(array $input): array
    {
        if (empty($this->validationRules)) {
            return $input;
        }

        $validator = Validator::make($input, $this->validationRules);

        if ($validator->fails()) {
            throw new \InvalidArgumentException(
                'Tool input validation failed: ' . $validator->errors()->first()
            );
        }

        return $validator->validated();
    }
}
