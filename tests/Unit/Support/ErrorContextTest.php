<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\ErrorContext;
use App\Support\SecretRedactor;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \App\Support\ErrorContext
 */
final class ErrorContextTest extends TestCase
{
    public function testDescribeOutputContainsClassMessageFileAndLine(): void
    {
        $exception = new RuntimeException('something specific went wrong');
        $expectedLine = __LINE__ - 1;

        $output = ErrorContext::describe($exception);

        self::assertStringContainsString(RuntimeException::class, $output);
        self::assertStringContainsString('something specific went wrong', $output);
        self::assertStringContainsString($exception->getFile(), $output);
        self::assertStringContainsString((string) $expectedLine, $output);
        self::assertSame($exception->getLine(), $expectedLine);
    }

    public function testDescribeRendersNonSecretContextValuesForDiagnosability(): void
    {
        $exception = new RuntimeException('lookup failed');

        $output = ErrorContext::describe($exception, [
            'license_key' => 'SERB-AAAAA-BBBBB-CCCCC-DDDDD',
            'site_hash' => 'abc123',
        ]);

        self::assertStringContainsString('SERB-AAAAA-BBBBB-CCCCC-DDDDD', $output);
        self::assertStringContainsString('abc123', $output);
    }

    public function testDescribeRedactsSecretLikeContextKeysInsteadOfTheRawValue(): void
    {
        $exception = new InvalidArgumentException('signature mismatch');

        $output = ErrorContext::describe($exception, [
            'hmac_shared_secret' => 'super-secret-hmac-value',
            'db_pass' => 'super-secret-db-password',
            'password_hash' => 'super-secret-hash-value',
        ]);

        self::assertStringNotContainsString('super-secret-hmac-value', $output);
        self::assertStringNotContainsString('super-secret-db-password', $output);
        self::assertStringNotContainsString('super-secret-hash-value', $output);
        self::assertStringContainsString(SecretRedactor::REDACTED, $output);
    }

    public function testDescribeFormatMatchesTheDocumentedShape(): void
    {
        $exception = new RuntimeException('boom');

        $output = ErrorContext::describe($exception, ['foo' => 'bar']);

        // "{class}::{method} failed at {file}:{line}: {message} (context: {redacted-context})"
        self::assertMatchesRegularExpression(
            '/^.+::.+ failed at .+:\d+: boom \(context: \{.*"foo":"bar".*\}\)$/',
            $output
        );
    }

    public function testDeriveMethodDoesNotThrowAndProducesAStringForAnExceptionWithARealTrace(): void
    {
        // Throwing directly at the top level of this test method gives the
        // exception a genuine, non-empty trace (the frame that invoked this
        // test method, via PHPUnit's own call stack), which is the normal,
        // realistic case for exceptions raised inside application code.
        try {
            $this->throwsDirectly();
            self::fail('Expected exception was not thrown.');
        } catch (RuntimeException $exception) {
            $output = ErrorContext::describe($exception);

            self::assertIsString($output);
            self::assertNotSame('', $output);
            // Best-effort method derivation should resolve to this test
            // class/method rather than falling back to "unknown", since a
            // real trace frame is available.
            self::assertStringContainsString(self::class, $output);
        }

        // NOTE on the "unknown" fallback sub-case: ErrorContext derives
        // {method} from the first frame of $e->getTrace(), which is empty
        // only when a Throwable is constructed with no calling frame above
        // it (e.g. constructed and thrown from the true top level of a PHP
        // process with no enclosing function/method). PHPUnit always invokes
        // test methods through its own call stack, so every exception
        // constructed inside a test necessarily has a non-empty trace. There
        // is no deterministic, non-artificial way to reproduce an empty
        // trace from within a PHPUnit test method, so that specific
        // fallback branch is intentionally left uncovered here rather than
        // forcing an unrealistic construction (e.g. reflection-based
        // trickery) purely to hit it.
    }

    private function throwsDirectly(): void
    {
        throw new RuntimeException('direct throw for trace inspection');
    }
}
