<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/**
 * Minimal file / `error_log`-based logger with built-in secret redaction.
 *
 * Design intent (see design.md's "Error message and logging specificity
 * policy" and Requirement 21 AC4 / Requirement 22 AC4):
 *
 *  - Writes to a configured log file path when one is provided, falling
 *    back to PHP's `error_log()` otherwise (or if the file write fails).
 *  - Before writing, every `$context` value keyed by a known
 *    secret-indicating name (see `SecretRedactor`) is replaced with a
 *    fixed redaction marker, regardless of what a caller passes in. The
 *    free-text `$message` is defensively scanned too, as a backstop for
 *    accidental `key=value` interpolation.
 *  - `log()` never throws. Any failure while resolving the destination,
 *    encoding the context, or writing the line is swallowed internally
 *    (falling back to `error_log()` as a last resort, and ultimately
 *    doing nothing rather than propagating) so a broken log sink can
 *    never turn a tolerated secondary failure into a fatal error for the
 *    caller's primary action.
 */
final class Logger
{
    /**
     * @param string|null $logFilePath Absolute path to a writable log
     *        file. When null (or when a write to this path fails),
     *        `log()` falls back to PHP's `error_log()`.
     */
    public function __construct(
        private readonly ?string $logFilePath = null,
    ) {
    }

    /**
     * Writes one log entry.
     *
     * @param string $level e.g. 'error', 'warning', 'info'.
     * @param array<int|string, mixed> $context Arbitrary structured
     *        context. Secret-indicating keys are redacted before writing.
     */
    public function log(string $level, string $message, array $context = []): void
    {
        try {
            $line = $this->formatLine($level, $message, $context);
        } catch (Throwable) {
            // Formatting itself must never throw out of log(); if it
            // somehow does (e.g. a pathological json_encode failure on
            // an unencodable resource in $context), fall back to a bare
            // message with no context rather than losing the log line.
            $line = sprintf('[%s] %s', strtoupper($level), SecretRedactor::redactMessage($message));
        }

        $this->write($line);
    }

    private function formatLine(string $level, string $message, array $context): string
    {
        $redactedMessage = SecretRedactor::redactMessage($message);
        $redactedContext = SecretRedactor::redactContext($context);

        $contextJson = '{}';
        if ($redactedContext !== []) {
            $encoded = json_encode($redactedContext, JSON_UNESCAPED_SLASHES);
            if ($encoded !== false) {
                $contextJson = $encoded;
            }
        }

        return sprintf(
            '[%s] [%s] %s context=%s',
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $redactedMessage,
            $contextJson,
        );
    }

    private function write(string $line): void
    {
        if ($this->logFilePath !== null) {
            try {
                $result = @file_put_contents($this->logFilePath, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
                if ($result !== false) {
                    return;
                }
            } catch (Throwable) {
                // Fall through to the error_log() fallback below.
            }
        }

        try {
            error_log($line);
        } catch (Throwable) {
            // Nothing further can be done; swallow so the caller's
            // primary action is never affected by a logging failure.
        }
    }
}
