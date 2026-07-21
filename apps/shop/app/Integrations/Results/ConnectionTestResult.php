<?php

namespace App\Integrations\Results;

final readonly class ConnectionTestResult
{
    public function __construct(
        public bool $successful,
        public string $message,
        public array $details = [],
    ) {
    }

    public static function success(
        string $message = 'Подключение установлено.',
        array $details = [],
    ): self {
        return new self(
            successful: true,
            message: $message,
            details: $details,
        );
    }

    public static function failure(
        string $message,
        array $details = [],
    ): self {
        return new self(
            successful: false,
            message: $message,
            details: $details,
        );
    }
}
