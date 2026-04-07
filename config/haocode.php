<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Model
    |--------------------------------------------------------------------------
    */
    'model' => env('HAOCODE_MODEL', 'claude-sonnet-4-20250514'),

    /*
    |--------------------------------------------------------------------------
    | API Key
    |--------------------------------------------------------------------------
    */
    'api_key' => env('ANTHROPIC_API_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | API Base URL
    |--------------------------------------------------------------------------
    */
    'api_base_url' => env('HAOCODE_API_BASE_URL', 'https://api.anthropic.com'),

    /*
    |--------------------------------------------------------------------------
    | Active Provider
    |--------------------------------------------------------------------------
    */
    'active_provider' => env('HAOCODE_ACTIVE_PROVIDER', null),

    /*
    |--------------------------------------------------------------------------
    | Max Tokens
    |--------------------------------------------------------------------------
    */
    'max_tokens' => (int) env('HAOCODE_MAX_TOKENS', 16384),

    /*
    |--------------------------------------------------------------------------
    | Permission Mode
    |--------------------------------------------------------------------------
    | Public Claude-style config surface. Hao Code derives internal approval
    | and sandbox behavior from this setting.
    | Supported: default, plan, accept_edits, bypass_permissions
    */
    'permission_mode' => env('HAOCODE_PERMISSION_MODE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Session Storage Path
    |--------------------------------------------------------------------------
    */
    'session_path' => env('HAOCODE_SESSION_PATH',
        (getenv('LARAVEL_STORAGE_PATH') ?: dirname(__DIR__) . '/storage') . '/app/haocode/sessions'
    ),

    /*
    |--------------------------------------------------------------------------
    | Settings Paths
    |--------------------------------------------------------------------------
    */
    'global_settings_path' => env('HAOCODE_GLOBAL_SETTINGS_PATH', null),

    /*
    |--------------------------------------------------------------------------
    | Background Agent Lifecycle
    |--------------------------------------------------------------------------
    */
    'background_agent_idle_timeout' => (int) env('HAOCODE_BACKGROUND_AGENT_IDLE_TIMEOUT', 300),
    'background_agent_poll_interval_ms' => (int) env('HAOCODE_BACKGROUND_AGENT_POLL_INTERVAL_MS', 250),

    /*
    |--------------------------------------------------------------------------
    | Assistant Text Streaming
    |--------------------------------------------------------------------------
    | Public Claude-style toggle. Disabled by default so the terminal stays
    | calmer and prints the final answer as a stable block.
    */
    'stream_output' => filter_var(env('HAOCODE_STREAM_OUTPUT', false), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,

    /*
    |--------------------------------------------------------------------------
    | Terminal Streaming Render
    |--------------------------------------------------------------------------
    */
    'stream_render_interval_ms' => (int) env('HAOCODE_STREAM_RENDER_INTERVAL_MS', 120),

    /*
    |--------------------------------------------------------------------------
    | API Stream Stall Detection
    |--------------------------------------------------------------------------
    */
    'api_stream_idle_timeout' => (int) env('HAOCODE_API_STREAM_IDLE_TIMEOUT', 60),
    'api_stream_poll_timeout' => (float) env('HAOCODE_API_STREAM_POLL_TIMEOUT', 1.0),

    /*
    |--------------------------------------------------------------------------
    | Extended Thinking
    |--------------------------------------------------------------------------
    */
    'thinking_enabled' => filter_var(env('HAOCODE_THINKING', false), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,
    'thinking_budget' => (int) env('HAOCODE_THINKING_BUDGET', 10000),

    /*
    |--------------------------------------------------------------------------
    | Effort Level (low, medium, high, max, auto)
    |--------------------------------------------------------------------------
    */
    'effort_level' => env('HAOCODE_EFFORT_LEVEL', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Editor Mode (normal, vim)
    |--------------------------------------------------------------------------
    */
    'editor_mode' => env('HAOCODE_EDITOR_MODE', 'normal'),

    /*
    |--------------------------------------------------------------------------
    | Cost Thresholds
    |--------------------------------------------------------------------------
    */
    'cost_warn_threshold' => (float) env('HAOCODE_COST_WARN', 5.00),
    'cost_stop_threshold' => (float) env('HAOCODE_COST_STOP', 50.00),
];
