<?php

namespace Laraswag\LaravelSwaggerExporter\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;
use Laraswag\LaravelSwaggerExporter\Services\ModelSwaggerExporter;

class ExportModelSwagger extends Command
{
    protected $signature = 'swagger:export {--model= : Model FQCN or short name under App\\Models} {--all : Export all models in App\\Models}';

    protected $description = 'Export a Swagger YAML file for a model or all models';

    public function handle(): int
    {
        $exporter = $this->laravel->make(ModelSwaggerExporter::class);

        if ($this->option('all')) {
            $modelsPath = app_path('Models');
            if (!File::exists($modelsPath)) {
                $this->error("Models directory not found at {$modelsPath}");
                return 1;
            }

            $modelFiles = File::files($modelsPath);
            $models = [];

            foreach ($modelFiles as $file) {
                $filename = $file->getFilenameWithoutExtension();
                // Assume models are in App\Models namespace
                $models[] = "App\\Models\\{$filename}";
            }

            $this->info('Exporting all models...');
            foreach ($models as $model) {
                try {
                    $path = $exporter->export($model);
                    $this->info("Exported swagger YAML for {$model} to: {$path}");
                } catch (\Throwable $e) {
                    $this->error("Failed to export {$model}: " . $e->getMessage());
                }
            }
            return 0;
        }

        $modelInput = $this->option('model');

        if (!$modelInput) {
            $this->error('Please specify a model using --model=ModelName');
            return 1;
        }

        // Determine full class name if short name provided
        if (Str::startsWith($modelInput, 'App\\')) {
            $modelClass = $modelInput;
        } else {
            $modelClass = "App\\Models\\{$modelInput}";
        }

        try {
            $path = $exporter->export($modelClass);
            $this->info("Exported swagger YAML to: {$path}");
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return 1;
        }

        return 0;
    }
}