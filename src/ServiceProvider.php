<?php

namespace Laraswag\LaravelSwaggerExporter;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Laraswag\LaravelSwaggerExporter\Commands\ExportModelSwagger;

class ServiceProvider extends BaseServiceProvider
{
    public function boot(): void
    {
        // Load package views
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'laraswag');

        // Load package routes
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');

        if ($this->app->runningInConsole()) {
            // Publish assets
            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/laraswag'),
            ], 'laraswag-views');

            $this->publishes([
                __DIR__ . '/../config/laraswag.php' => config_path('laraswag.php'),
            ], 'laraswag-config');

            $this->publishes([
                __DIR__ . '/../public' => public_path('swagger_ui'),
            ], 'laraswag-public');

            // Register commands
            $this->commands([
                ExportModelSwagger::class,
            ]);
        }
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/laraswag.php', 'laraswag');

        $this->app->singleton(\Laraswag\LaravelSwaggerExporter\Services\ModelSwaggerExporter::class, function ($app) {
            return new \Laraswag\LaravelSwaggerExporter\Services\ModelSwaggerExporter();
        });
    }
}
