<?php

namespace Laraswag\LaravelSwaggerExporter\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\File;

class SwaggerController extends Controller
{
    
    public function index()
    {
        $files = $this->getSwaggerFiles();
        return view('laraswag::swagger.index', ['files' => $files,'path' => 'test-connection.yaml']);
    }

    public function showFromPrefix(string $prefix, string $file)
    {
        $files = $this->getSwaggerFiles();
        return view('laraswag::swagger.swagger-layout', ['files' => $files,'path' => $prefix.'/'.$file . '.yaml']);
    }

    public function showFromFile(string $file)
    {
        $files = $this->getSwaggerFiles();
        return view('laraswag::swagger.swagger-layout', ['files' => $files,'path' => $file . '.yaml']);
    }

    private function getSwaggerFiles(): array
    {
        $dir = public_path(config('laraswag.public_path', 'swagger_ui'));

        $filesData = [];
        if (File::exists($dir)) {
            $files = collect(File::allFiles($dir))
                ->filter(fn($f) => preg_match('/\.yaml$/', $f->getRelativePathname()))
                ->map(fn($f) => preg_replace('/\.yaml$/', '', $f->getRelativePathname()))
                ->values()
                ->all();

            foreach ($files as $filePath) {
                $lastSlashPos = strrpos($filePath, '\\');
                if ($lastSlashPos !== false) {
                    $prefix = substr($filePath, 0, $lastSlashPos);
                    $file = substr($filePath, $lastSlashPos + 1);
                } else {
                    // If there's no slash, the entire filename is considered as 'file' with empty prefix
                    $prefix = '';
                    $file = $filePath;
                }

                $filesData[] = [
                    'prefix' => $prefix,
                    'file' => $file,
                ];
            }
        }

        return $filesData;
    }
}
