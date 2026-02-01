# Laraswag - Laravel Swagger Exporter

[![Latest Version on Packagist](https://img.shields.io/packagist/v/laraswag/laravel-swagger-exporter.svg?style=flat-square)](https://packagist.org/packages/laraswag/laravel-swagger-exporter)
[![License](https://img.shields.io/packagist/l/laraswag/laravel-swagger-exporter.svg?style=flat-square)](LICENSE.md)
![PHP Version](https://img.shields.io/packagist/php-v/laraswag/laravel-swagger-exporter?style=flat-square)
[![Laravel Version](https://img.shields.io/badge/Laravel-8.x--11.x-orange.svg?style=flat-square)](https://laravel.com)

Automatically generate Swagger/OpenAPI YAML documentation for your Eloquent models by analyzing routes and FormRequest validation rules.

## Installation

```bash
composer require laraswag/laravel-swagger-exporter
```

The package auto-registers with Laravel 11+. For Laravel 10 and below, add the service provider to `config/app.php`:

```php
'providers' => [
    // ...
    Laraswag\LaravelSwaggerExporter\ServiceProvider::class,
],
```

## Quick Usage

### Generate Swagger Files

```bash
# Export a single model
php artisan swagger:export --model=Post

# Or use FQCN
php artisan swagger:export --model=App\\Models\\Post

# Or Export all models
php artisan swagger:export --all
```

### View Documentation

Visit `/swagger` in your app to view all generated YAML files.

## Features

- **Automatic Documentation**: Analyzes routes and FormRequest validation to generate Swagger YAML
- **Built-in UI**: View generated docs at `/swagger`
- **Artisan Commands**: Easy model export via CLI
- **Configurable**: Customize output paths and routes via `config/laraswag.php`
- **DI Ready**: Use `ModelSwaggerExporter` service directly

## Publishing Assets

```bash
# Publish public files
php artisan vendor:publish --provider="Laraswag\LaravelSwaggerExporter\ServiceProvider" --tag=laraswag-public

# Publish config
php artisan vendor:publish --provider="Laraswag\LaravelSwaggerExporter\ServiceProvider" --tag=laraswag-config

# Publish views for customization
php artisan vendor:publish --provider="Laraswag\LaravelSwaggerExporter\ServiceProvider" --tag=laraswag-views
```

## Routes
add these routes to api.php file
```php
Route::get('/ping', fn () => response()->json(['message' => 'pong'], 200));
Route::middleware('auth:sanctum')->get('/ping-auth', fn () => response()->json(['message' => 'pong'], 200));
```

## Configuration

Key options in `config/laraswag.php`:

```php
'public_path' => public_path('swagger_ui/'), // Where YAML files are stored
'route_prefix' => 'swagger', // URL prefix for the UI
'middleware' => ['web'], // Middleware for Swagger routes
```

## Requirements

- PHP 8.0+
- Laravel 8.x, 9.x, 10.x, or 11.x
