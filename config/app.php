<?php

return [
    'name' => 'Hao Code',
    'version' => '0.1.4',
    'env' => env('APP_ENV', 'production'),

    'providers' => [
        App\Providers\AgentServiceProvider::class,
        App\Providers\ToolServiceProvider::class,
        App\Sdk\HaoCodeSdkServiceProvider::class,
    ],
];
