<?php

declare(strict_types=1);

namespace XaiSdk\Laravel;

use Illuminate\Support\ServiceProvider;
use XaiOfficial\Sdk\SpaceXAI;

class XaiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/xai.php', 'xai');

        $this->app->singleton(SpaceXAI::class, function ($app) {
            $config = $app['config']->get('xai');

            return new SpaceXAI([
                'apiKey' => $config['api_key'],
                'baseURL' => $config['base_url'],
                'timeout' => $config['timeout'],
                'idleTimeout' => $config['idle_timeout'],
                'maxRetries' => $config['max_retries'],
                'retryBeforeOutput' => $config['retry_before_output'],
            ]);
        });

        $this->app->alias(SpaceXAI::class, 'xai');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/xai.php' => config_path('xai.php'),
            ], 'xai-config');
        }
    }
}
