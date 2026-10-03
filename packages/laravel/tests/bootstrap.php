<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use XaiSdk\Laravel\XaiServiceProvider;

$app = new Application(dirname(__DIR__).'/vendor/orchestra/testbench-core/laravel');
$app->singleton('config', fn () => new \Illuminate\Config\Repository([
    'xai' => [
        'api_key' => 'test-key',
        'base_url' => 'https://api.x.ai/v1',
        'timeout' => 3_600_000,
        'idle_timeout' => 60_000,
        'max_retries' => 0,
        'retry_before_output' => false,
    ],
]));
$provider = new XaiServiceProvider($app);
$provider->register();

return $app;
