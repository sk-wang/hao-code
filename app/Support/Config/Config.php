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

    public static function getInstance(): ?self
    {
        return self::$instance;
    }

    /**
     * Check if Config has been initialized (vs falling back to Laravel).
     */
    public static function isInitialized(): bool
    {
        return self::$instance !== null;
    }

    /**
     * Get a config value.
     *
     * When initialized: reads from the loaded config array.
     * When NOT initialized: falls back to Laravel's config() if available.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        // Normalize key
        $bareKey = str_starts_with($key, 'haocode.') ? substr($key, 8) : $key;

        // If initialized, use our own config
        if (self::$instance !== null) {
            if (array_key_exists($bareKey, self::$instance->overrides)) {
                return self::$instance->overrides[$bareKey];
            }

            return self::$instance->values[$bareKey] ?? $default;
        }

        // Fall back to Laravel's config() during coexistence
        if (function_exists('config')) {
            return config('haocode.' . $bareKey, $default);
        }

        return $default;
    }

    /**
     * Set a runtime override (does not persist to disk).
     */
    public static function set(string $key, mixed $value): void
    {
        $bareKey = str_starts_with($key, 'haocode.') ? substr($key, 8) : $key;

        if (self::$instance !== null) {
            self::$instance->overrides[$bareKey] = $value;
        }

        // Also set in Laravel config if available (for coexistence)
        if (function_exists('config')) {
            config(['haocode.' . $bareKey => $value]);
        }
    }

    /**
     * Get all values (merged with overrides).
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        if (self::$instance === null) {
            return [];
        }

        return array_merge(self::$instance->values, self::$instance->overrides);
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
