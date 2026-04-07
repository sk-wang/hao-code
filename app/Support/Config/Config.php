<?php

namespace App\Support\Config;

/**
 * Lightweight config system replacing Laravel's config() helper.
 *
 * Loads values from config/haocode.php with env() fallbacks.
 * Supports runtime overrides via set().
 */
class Config
{
    private static ?self $instance = null;

    /** @var array<string, mixed> */
    private array $values;

    /** @var array<string, mixed> */
    private array $overrides = [];

    public function __construct(string $packageRoot)
    {
        // Ensure env() polyfill exists before loading config
        if (!function_exists('env')) {
            function env(string $key, mixed $default = null): mixed {
                $value = $_ENV[$key] ?? getenv($key);
                if ($value === false) return $default;
                // Cast common string booleans
                return match (strtolower((string) $value)) {
                    'true', '(true)' => true,
                    'false', '(false)' => false,
                    'null', '(null)' => null,
                    'empty', '(empty)' => '',
                    default => $value,
                };
            }
        }

        $configFile = $packageRoot . '/config/haocode.php';
        $this->values = is_file($configFile) ? (array) require $configFile : [];
    }

    public static function init(string $packageRoot): void
    {
        self::$instance = new self($packageRoot);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('Config not initialized. Call Config::init($packageRoot) first.');
        }

        return self::$instance;
    }

    /**
     * Get a config value. Supports dot-less keys (flat haocode.php array).
     *
     * Strips 'haocode.' prefix for backward compatibility with config('haocode.foo').
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $instance = self::getInstance();

        // Strip 'haocode.' prefix for backward compat
        if (str_starts_with($key, 'haocode.')) {
            $key = substr($key, 8);
        }

        if (array_key_exists($key, $instance->overrides)) {
            return $instance->overrides[$key];
        }

        return $instance->values[$key] ?? $default;
    }

    /**
     * Set a runtime override (does not persist to disk).
     */
    public static function set(string $key, mixed $value): void
    {
        $instance = self::getInstance();

        if (str_starts_with($key, 'haocode.')) {
            $key = substr($key, 8);
        }

        $instance->overrides[$key] = $value;
    }

    /**
     * Get all values (merged with overrides).
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $instance = self::getInstance();

        return array_merge($instance->values, $instance->overrides);
    }

    /**
     * Reset all runtime overrides (useful for tests).
     */
    public static function resetOverrides(): void
    {
        if (self::$instance !== null) {
            self::$instance->overrides = [];
        }
    }

    /**
     * Reset the entire instance (useful for tests).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
