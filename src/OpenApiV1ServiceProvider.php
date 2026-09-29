<?php

namespace Agentoom\OpenApiV1;

use Agentoom\Core\Services\PermissionRegistry;
use Agentoom\OpenApiV1\Contracts\AgentExecutionAdapter;
use Agentoom\OpenApiV1\Services\DynamicAgentExecutionAdapter;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\ServiceProvider;

class OpenApiV1ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/openapi-v1.php', 'openapi-v1'
        );

        $this->app->bind(
            AgentExecutionAdapter::class,
            DynamicAgentExecutionAdapter::class
        );
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'openapi-v1');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'openapi-v1');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/admin.php');

        $this->app->make(PermissionRegistry::class)->register('openapi_tokens', ['openapi-tokens.view', 'openapi-tokens.create', 'openapi-tokens.edit', 'openapi-tokens.delete']);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/openapi-v1.php' => config_path('openapi-v1.php'),
            ], 'openapi-v1-config');
        }

        $this->registerMiddlewareAliases();
    }

    protected function registerMiddlewareAliases(): void
    {
        /** @var Middleware $middleware */
        $middleware = $this->app->make(Kernel::class);
        // In Laravel 12+, middleware aliases are registered via bootstrap/app.php.
        // The aliases are registered there so they can be referenced in route definitions.
    }
}
