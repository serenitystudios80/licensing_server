<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\Logger;
use App\Support\SecretRedactor;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * @covers \App\Support\Logger
 */
final class LoggerTest extends TestCase
{
    /** @var list<string> */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->tempPaths = [];

        parent::tearDown();
    }

    private function tempLogPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'logger_test_');
        if ($path === false) {
            self::fail('Could not create a temporary file for the log fixture.');
        }
        $this->tempPaths[] = $path;

        return $path;
    }

    public function testLogNeverThrowsEvenWhenTheConfiguredLogFilePathIsUnwritable(): void
    {
        // A path inside a non-existent directory can never be written to
        // by file_put_contents(), and error_log() itself never throws, so
        // this exercises the "swallow the failure" path end to end.
        $badPath = sys_get_temp_dir()
            . '/logger_test_missing_dir_' . bin2hex(random_bytes(8))
            . '/nested/log.txt';

        $logger = new Logger($badPath);

        try {
            $logger->log('error', 'something failed', ['foo' => 'bar']);
        } catch (Throwable $e) {
            self::fail('Logger::log() must never throw, but threw: ' . $e::class . ': ' . $e->getMessage());
        }

        // Reaching this line (without the fail() above having run) proves
        // the call completed normally rather than propagating a failure.
        self::assertTrue(true);
    }

    public function testLogNeverThrowsWithAnEmptyStringPath(): void
    {
        $logger = new Logger('');

        try {
            $logger->log('warning', 'edge case path');
        } catch (Throwable $e) {
            self::fail('Logger::log() must never throw, but threw: ' . $e::class . ': ' . $e->getMessage());
        }

        self::assertTrue(true);
    }

    public function testLogRedactsKnownSecretKeyNamesInContextWhileKeepingNonSecretValuesIntact(): void
    {
        $path = $this->tempLogPath();
        $logger = new Logger($path);

        $logger->log('info', 'webhook processed', [
            'hmac_shared_secret' => 'super-secret-hmac-value',
            'db_pass' => 'super-secret-db-password',
            'password_hash' => 'super-secret-hash-value',
            'license_key' => 'SERB-AAAAA-BBBBB-CCCCC-DDDDD',
            'email' => 'customer@example.com',
        ]);

        $contents = file_get_contents($path);
        self::assertIsString($contents);

        self::assertStringNotContainsString('super-secret-hmac-value', $contents);
        self::assertStringNotContainsString('super-secret-db-password', $contents);
        self::assertStringNotContainsString('super-secret-hash-value', $contents);

        self::assertStringContainsString(SecretRedactor::REDACTED, $contents);
        self::assertStringContainsString('SERB-AAAAA-BBBBB-CCCCC-DDDDD', $contents);
        self::assertStringContainsString('customer@example.com', $contents);
    }

    public function testLogRedactsSecretKeyNamesRegardlessOfCasing(): void
    {
        $path = $this->tempLogPath();
        $logger = new Logger($path);

        $logger->log('error', 'webhook signature check', [
            'RAZORPAY_WEBHOOK_SECRET' => 'top-secret-webhook-value',
            'RazorpayWebhookSecret' => 'top-secret-webhook-value-2',
        ]);

        $contents = file_get_contents($path);
        self::assertIsString($contents);

        self::assertStringNotContainsString('top-secret-webhook-value', $contents);
        self::assertStringNotContainsString('top-secret-webhook-value-2', $contents);
    }
}
