<?php

namespace App\Sdk;

use Illuminate\Support\ServiceProvider;

/**
 * Service provider for external Laravel apps using HaoCode as an SDK.
 *
 * When another Laravel app installs hao-code via Composer, this provider
 * is auto-discovered and registers the SDK facade + configuration.
 *
 * Usage in external app:
 *   // config/haocode.php is auto-published
 *   // .env: ANTHROPIC_API_KEY=sk-ant-...
 *
 *   use App\Sdk\HaoCode;
 *   $result = HaoCode::query('Explain this codebase');
 */
class HaoCodeSdkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/haocode.php',
            'haocode',
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/haocode.php' => config_path('haocode.php'),
            ], 'haocode-config');
        }
    }
}
