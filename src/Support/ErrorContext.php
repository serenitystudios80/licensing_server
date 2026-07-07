<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/**
 * Builds detailed, consistently formatted diagnostic strings for logging.
 *
 * `ErrorContext::describe()` is the single place that formats "what
 * failed, why, and where" for a caught exception, so every call site
 * across the codebase (API handlers, Sweep_Job, admin controllers)
 * produces log lines with the same shape — per the "Error message and
 * logging specificity policy" in design.md.
 */
final class ErrorContext
{
    private function __construct()
    {
        // Static-only helper; never instantiated.
    }

    /**
     * Formats: `"{class}::{method} failed at {file}:{line}: {message} (context: {redacted-context})"`.
     *
     * `{file}:{line}` and `{message}` come directly from the Throwable
     * itself (`getFile()`, `getLine()`, `getMessage()`), and `{class}`
     * from `get_class($e)`.
     *
     * `{method}` is not something a plain `Throwable` carries directly —
     * a `\Throwable` only knows where it was *thrown/constructed*, not
     * which method the caller considers "the operation that failed".
     * As a best-effort approximation, we take the first frame of
     * `$e->getTrace()` (the function/method active at the point the
     * exception was constructed) and render it as `Class::method` (or
     * just `function` for a plain function/closure with no owning
     * class). When the trace is empty (can happen for exceptions
     * constructed in unusual ways), `{method}` falls back to the
     * literal string `unknown`.
     *
     * @param array<int|string, mixed> $context Passed through the same
     *        secret-redaction rule as `Logger`, so a caller accidentally
     *        including a secret in $context is redacted regardless.
     */
    public static function describe(Throwable $e, array $context = []): string
    {
        $class = get_class($e);
        $method = self::deriveMethod($e);
        $file = $e->getFile();
        $line = $e->getLine();
        $message = SecretRedactor::redactMessage($e->getMessage());

        $redactedContext = SecretRedactor::redactContext($context);
        $contextJson = '{}';
        if ($redactedContext !== []) {
            $encoded = json_encode($redactedContext, JSON_UNESCAPED_SLASHES);
            if ($encoded !== false) {
                $contextJson = $encoded;
            }
        }

        return sprintf(
            '%s::%s failed at %s:%d: %s (context: %s)',
            $class,
            $method,
            $file,
            $line,
            $message,
            $contextJson,
        );
    }

    /**
     * Derives a "where" indicator from the first meaningful frame of the
     * Throwable's trace — see the docblock on `describe()` for why this
     * is a best-effort approximation rather than an exact "method".
     */
    private static function deriveMethod(Throwable $e): string
    {
        $trace = $e->getTrace();

        if (!isset($trace[0])) {
            return 'unknown';
        }

        $frame = $trace[0];
        $function = $frame['function'] ?? null;

        if (!is_string($function) || $function === '') {
            return 'unknown';
        }

        $class = $frame['class'] ?? null;
        if (is_string($class) && $class !== '') {
            return $class . '::' . $function;
        }

        return $function;
    }
}
