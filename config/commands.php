<?php

return [
    'default' => App\Console\Commands\HaoCodeCommand::class,

    'paths' => [app_path('Console/Commands')],

    'add' => [],

    'hidden' => [
        NunoMaduro\LaravelConsoleSummary\SummaryCommand::class,
        Symfony\Component\Console\Command\DumpCompletionCommand::class,
        Symfony\Component\Console\Command\HelpCommand::class,
        Symfony\Component\Console\Command\ListCommand::class,
        Illuminate\Foundation\Console\VendorPublishCommand::class,
    ],

    'remove' => [],
];
