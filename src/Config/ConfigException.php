<?php

declare(strict_types=1);

namespace App\Config;

use RuntimeException;

/**
 * Thrown when a required configuration key is missing or empty.
 *
 * Security requirement: the exception message/context carries only the
 * missing key's name. It must never include the values of other
 * configuration keys, since those values may include secrets (DB
 * passwords, API keys, HMAC secrets, etc.).
 */
final class ConfigException extends RuntimeException
{
    public function __construct(
        private readonly string $missingKey,
    ) {
        parent::__construct(sprintf('Missing required configuration key: %s', $missingKey));
    }

    public function missingKey(): string
    {
        return $this->missingKey;
    }
}
