<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use App\Config\Config;
use App\Config\ConfigException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Config\Config
 * @covers \App\Config\ConfigException
 */
final class ConfigTest extends TestCase
{
    /**
     * Mirrors `Config::REQUIRED_KEYS`. That constant is private, so the
     * list is deliberately duplicated here: any future drift between the
     * implementation and this list will surface as a genuine test failure
     * (either a key the implementation now requires isn't exercised, or a
     * key this suite exercises no longer exists), rather than being masked.
     *
     * @var list<string>
     */
    private const REQUIRED_KEYS = [
        'DB_HOST',
        'DB_NAME',
        'DB_USER',
        'DB_PASS',
        'RAZORPAY_KEY_ID',
        'RAZORPAY_WEBHOOK_SECRET',
        'HMAC_SHARED_SECRET',
        'TRUSTED_PROXY_RANGES',
        'RATE_LIMIT_IP_MAX',
        'RATE_LIMIT_IP_WINDOW_SECONDS',
        'RATE_LIMIT_KEY_MAX',
        'RATE_LIMIT_KEY_WINDOW_SECONDS',
        'SESSION_SECRET',
    ];

    /** @var list<string> */
    private array $tempFiles = [];

    /**
     * Snapshot of the real process environment for every required key, so
     * tests can neutralize it for hermetic behavior and restore it exactly
     * afterwards regardless of whether the host running the suite happens
     * to have any of these variables set.
     *
     * @var array<string, string|false>
     */
    private array $originalGetenv = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::REQUIRED_KEYS as $key) {
            $this->originalGetenv[$key] = getenv($key);
            // Passing a bare name (no "=") to putenv() removes the
            // variable from the real process environment.
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->tempFiles = [];

        foreach ($this->originalGetenv as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv(sprintf('%s=%s', $key, $value));
            }
        }
        $this->originalGetenv = [];

        parent::tearDown();
    }

    /**
     * A complete, valid set of required configuration values. Every value
     * is prefixed/suffixed distinctly per key so a substring-search assertion
     * can reliably detect accidental leakage of one key's value into another
     * key's exception message.
     *
     * @return array<string, string>
     */
    private function validValues(): array
    {
        $values = [];
        foreach (self::REQUIRED_KEYS as $key) {
            $values[$key] = 'VALID_VALUE_FOR_' . $key;
        }

        return $values;
    }

    /**
     * Writes a temporary `.env`-style file containing the given key/value
     * pairs and registers it for cleanup in tearDown(). Returns the file
     * path, suitable for passing directly to `Config::load()`.
     *
     * @param array<string, string> $values
     */
    private function writeEnvFile(array $values): string
    {
        $path = tempnam(sys_get_temp_dir(), 'config_test_');
        if ($path === false) {
            self::fail('Could not create a temporary file for the .env fixture.');
        }

        $lines = [];
        foreach ($values as $key => $value) {
            $lines[] = sprintf('%s="%s"', $key, $value);
        }

        file_put_contents($path, implode("\n", $lines) . "\n");
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @return array<string, list<string>>
     */
    public static function requiredKeyProvider(): array
    {
        $cases = [];
        foreach (self::REQUIRED_KEYS as $key) {
            $cases[$key] = [$key];
        }

        return $cases;
    }

    public function testLoadWithAllRequiredKeysPresentLoadsSuccessfully(): void
    {
        $values = $this->validValues();
        $path = $this->writeEnvFile($values);

        $config = Config::load($path);

        foreach ($values as $key => $expectedValue) {
            self::assertSame($expectedValue, $config->get($key));
        }
    }

    #[DataProvider('requiredKeyProvider')]
    public function testMissingRequiredKeyThrowsConfigExceptionNamingOnlyThatKey(string $missingKey): void
    {
        $values = $this->validValues();
        unset($values[$missingKey]);
        $path = $this->writeEnvFile($values);

        try {
            Config::load($path);
            self::fail(sprintf('Expected ConfigException for missing key "%s".', $missingKey));
        } catch (ConfigException $exception) {
            self::assertSame($missingKey, $exception->missingKey());
            self::assertSame(
                sprintf('Missing required configuration key: %s', $missingKey),
                $exception->getMessage()
            );
        }
    }

    #[DataProvider('requiredKeyProvider')]
    public function testEmptyRequiredKeyThrowsConfigExceptionNamingOnlyThatKey(string $emptyKey): void
    {
        $values = $this->validValues();
        $values[$emptyKey] = '';
        $path = $this->writeEnvFile($values);

        try {
            Config::load($path);
            self::fail(sprintf('Expected ConfigException for empty key "%s".', $emptyKey));
        } catch (ConfigException $exception) {
            self::assertSame($emptyKey, $exception->missingKey());
            self::assertSame(
                sprintf('Missing required configuration key: %s', $emptyKey),
                $exception->getMessage()
            );
        }
    }

    public function testExceptionMessageNeverIncludesOtherConfigurationValues(): void
    {
        $values = $this->validValues();
        $missingKey = 'HMAC_SHARED_SECRET';
        unset($values[$missingKey]);
        $path = $this->writeEnvFile($values);

        try {
            Config::load($path);
            self::fail('Expected ConfigException.');
        } catch (ConfigException $exception) {
            $message = $exception->getMessage();

            self::assertSame(
                sprintf('Missing required configuration key: %s', $missingKey),
                $message
            );

            foreach ($values as $key => $value) {
                self::assertStringNotContainsString(
                    $value,
                    $message,
                    sprintf('Exception message must not leak the value of "%s".', $key)
                );
            }
        }
    }

    public function testRealEnvironmentValueOverridesEnvFileValueForRequiredKey(): void
    {
        $values = $this->validValues();
        $path = $this->writeEnvFile($values);

        putenv('DB_HOST=override-from-real-env');

        $config = Config::load($path);

        self::assertSame('override-from-real-env', $config->get('DB_HOST'));
        self::assertSame($values['DB_NAME'], $config->get('DB_NAME'));
    }

    public function testMissingEnvFileStillLoadsWhenRealEnvironmentSuppliesAllRequiredKeys(): void
    {
        $nonExistentPath = sys_get_temp_dir() . '/config_test_does_not_exist_' . bin2hex(random_bytes(8)) . '.env';

        foreach ($this->validValues() as $key => $value) {
            putenv(sprintf('%s=%s', $key, $value));
        }

        $config = Config::load($nonExistentPath);

        self::assertSame('VALID_VALUE_FOR_DB_HOST', $config->get('DB_HOST'));
    }
}
