<?php

namespace App\Integrations\Results;

final readonly class OrderImportResult
{
    public function __construct(
        public int $received = 0,
        public int $created = 0,
        public int $updated = 0,
        public int $skipped = 0,
        public int $failed = 0,
        public array $errors = [],
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }
}
