<?php

namespace App\Contracts;

readonly class ProviderResponse
{
    public function __construct(
        public string $messageId,
    ) {}
}
