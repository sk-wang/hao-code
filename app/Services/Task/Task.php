<?php

namespace App\Services\Task;

/**
 * Immutable task value object.
 */
class Task
{
    public function __construct(
        public readonly string $id,
        public readonly string $subject,
        public readonly string $activeForm,
        public readonly ?string $description = null,
        public readonly string $status = 'pending',
        public readonly ?string $result = null,
        public readonly int $createdAt = 0,
        public readonly int $updatedAt = 0,
    ) {}

    public function with(
        ?string $status = null,
        ?string $result = null,
        ?int $updatedAt = null,
    ): self {
        return new self(
            id: $this->id,
            subject: $this->subject,
            activeForm: $this->activeForm,
            description: $this->description,
            status: $status ?? $this->status,
            result: $result ?? $this->result,
            createdAt: $this->createdAt,
            updatedAt: $updatedAt ?? $this->updatedAt,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'activeForm' => $this->activeForm,
            'description' => $this->description,
            'status' => $this->status,
            'result' => $this->result,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            subject: $data['subject'],
            activeForm: $data['activeForm'],
            description: $data['description'] ?? null,
            status: $data['status'] ?? 'pending',
            result: $data['result'] ?? null,
            createdAt: $data['createdAt'] ?? 0,
            updatedAt: $data['updatedAt'] ?? 0,
        );
    }
}
