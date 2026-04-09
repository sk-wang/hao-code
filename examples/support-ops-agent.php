#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Support Ops Agent Demo
 *
 * A more complete SDK demo that walks through a realistic support-operations
 * workflow and touches the major SDK entry points:
 * - HaoCode::query()
 * - HaoCode::stream()
 * - HaoCode::conversation()
 * - HaoCode::resume()
 * - HaoCode::continueLatest()
 * - HaoCode::structured()
 * - custom SdkTool + SdkSkill
 * - callbacks, cost tracking, session resume, and AbortController wiring
 *
 * Usage:
 *   php examples/support-ops-agent.php
 *
 * Prerequisites:
 *   - composer install
 *   - ANTHROPIC_API_KEY set in .env or ~/.haocode/settings.json
 */
$packageRoot = dirname(__DIR__);
require_once $packageRoot.'/vendor/autoload.php';

$pathResolver = new StoragePathResolver;
$storagePath = $pathResolver->resolve(
    packageRoot: $packageRoot,
    autoloadPath: $packageRoot.'/vendor/autoload.php',
);
if ($storagePath) {
    if (! is_dir($storagePath)) {
        mkdir($storagePath, 0755, true);
    }

    putenv("LARAVEL_STORAGE_PATH={$storagePath}");
    $_ENV['LARAVEL_STORAGE_PATH'] = $storagePath;
    $_SERVER['LARAVEL_STORAGE_PATH'] = $storagePath;
}

$app = require $packageRoot.'/bootstrap/app.php';
if ($storagePath) {
    $app->useStoragePath($storagePath);
}

$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Sdk\Examples\SupportOpsAgent;
use App\Support\Runtime\StoragePathResolver;
use Illuminate\Contracts\Console\Kernel;

$workspaceDir = $packageRoot.'/examples/output/support-ops-agent';

try {
    $agent = new SupportOpsAgent($workspaceDir);
    $result = $agent->run();

    echo "\nSupport Ops Agent completed successfully.\n";
    echo 'Workspace: '.$result['workspace_dir']."\n";
    echo 'Conversation session: '.($result['conversation_session_id'] ?? 'n/a')."\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Support Ops Agent failed: {$e->getMessage()}\n");
    exit(1);
}
