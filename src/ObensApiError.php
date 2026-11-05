<?php

declare(strict_types=1);

namespace Obens\Sdk;

class ObensApiError extends \RuntimeException
{
    /** @param mixed $body the decoded JSON error body, or the raw text */
    public function __construct(
        public readonly int $status,
        public readonly mixed $body,
        string $method,
        string $path
    ) {
        $detail = $body ? ': ' . (is_string($body) ? $body : json_encode($body)) : '';
        parent::__construct("$method $path failed with $status$detail", $status);
    }
}
