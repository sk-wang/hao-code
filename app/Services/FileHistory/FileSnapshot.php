<?php

namespace App\Services\FileHistory;

/**
 * Immutable file snapshot value object.
 */
class FileSnapshot
{
    public function __construct(
        public readonly int $id,
        public readonly string $filePath,
        public readonly string $content,
        public readonly string $contentHash,
        public readonly int $timestamp,
    ) {}
}
