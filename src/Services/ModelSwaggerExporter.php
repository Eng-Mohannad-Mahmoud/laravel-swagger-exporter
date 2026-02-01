<?php

namespace Laraswag\LaravelSwaggerExporter\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionNamedType;

class ModelSwaggerExporter
{
    /**
     * Export swagger YAML file for a given model class.
     * Pass either the FQCN or the short class name under App\Models (eg: "Post" or App\\Models\\Post::class)
     *
     * @return string path to created file
     */
    public function export(string $model): string
    {
        $modelClass = $this->resolveModelClass($model);
        if (! class_exists($modelClass)) {
            throw new \RuntimeException("Model class {$modelClass} not found");
        }

        // Basic info

        $modelBase = class_basename($modelClass);
        $resourceName = Str::snake(Str::pluralStudly($modelBase)); // profile_section -> profile_sections
        $tag = Str::plural($modelBase);
        // Find routes that type-hint this model and optionally capture related FormRequest classes
        $routes = collect();
        
        // limit to API routes (only read routes defined in routes/api.php)
        
        $apiRoutes = collect(Route::getRoutes())->filter(function ($r) {
            $uri = $r->uri ?? ($r->uri() ?? '');
            if (Str::startsWith($uri, 'api/')) {
                return true;
            }

            $action = $r->getAction();
            $middleware = $action['middleware'] ?? null;
            if (is_array($middleware) && in_array('api', $middleware, true)) {
                return true;
            }
            if (is_string($middleware) && Str::contains($middleware, 'api')) {
                return true;
            }

            return false;
        });
        foreach ($apiRoutes as $route) {
            $action = $route->getAction();
            
            if (empty($action['controller'])) {
                continue;
            }

            [$controllerClass, $controllerMethod] = explode('@', $action['controller']);
            if (! class_exists($controllerClass) || ! method_exists($controllerClass, $controllerMethod)) {
                continue;
            }
            try {
                $ref = new ReflectionMethod($controllerClass, $controllerMethod);
                if(!str_contains($ref->class, $modelBase))
                    continue;
            } catch (\ReflectionException $e) {
                continue;
            }

            $hasModel = false;
            $requestClass = null;
            foreach ($ref->getParameters() as $param) {
                $type = $param->getType();
                if (! $type) {
                    continue;
                }

                if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                    $t = $type->getName();
                    // Resolve FormRequest or model param
                    if (is_subclass_of($t, \Illuminate\Foundation\Http\FormRequest::class)) {
                        $requestClass = $t;
                    }
                    if (is_subclass_of($t, 'Illuminate\\Database\\Eloquent\\Model') || Str::endsWith($t, $modelBase)) {
                        $hasModel = true;
                    }
                }
            }

            if (! $hasModel) {
                continue;
            }

            // decide intent (create/update) using method name or http verbs and uri
            $intent = 'create';
            $methodLower = strtolower($controllerMethod);
            if (Str::contains($methodLower, 'update') || Str::contains($methodLower, 'edit')) {
                $intent = 'update';
            } else {
                $methods = $route->methods ?? $route->methods();
                $upper = array_map('strtoupper', $methods);
                if (in_array('PUT', $upper) || in_array('PATCH', $upper)) {
                    $intent = 'update';
                }

                $uri = $route->uri ?? $route->uri();
                if (preg_match('/\{[^}]+\}/', $uri) && in_array('POST', $upper) && ! Str::contains(strtolower($controllerMethod), 'store')) {
                    $intent = 'update';
                }
            }

            $routes->push((object) [
                'route' => $route,
                'uri' => $route->uri ?? ($route->uri() ?? ''),
                'methods' => $route->methods ?? ($route->methods() ?? []),
                'requestClass' => $requestClass,
                'intent' => $intent,
                'controllerMethod' => $controllerMethod,
            ]);
        }
        // Also try to include any routes whose URI contains the resource name (e.g., collection endpoints like /messenger/conversations)
        $extraByNorm = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri ?? ($route->uri() ?? '');
            $norm = $this->normalizeRouteUri($uri);

            if (! preg_match('/(^|\/)' . preg_quote($resourceName, '/') . '(\/|$)/', $norm)) {
                continue;
            }

            if (! isset($extraByNorm[$norm])) {
                $extraByNorm[$norm] = [
                    'methods' => [],
                    'uris' => [],
                    'requestClasses' => [],
                    'intents' => [],
                    'sampleRoute' => $route,
                ];
            }

            $methods = $route->methods ?? ($route->methods() ?? []);
            $extraByNorm[$norm]['methods'] = array_values(array_unique(array_merge($extraByNorm[$norm]['methods'], $methods)));
            $extraByNorm[$norm]['uris'][] = $uri;

            $action = $route->getAction();
            if (! empty($action['controller'])) {
                try {
                    [$controllerClass, $controllerMethod] = explode('@', $action['controller']);
                    if (class_exists($controllerClass) && method_exists($controllerClass, $controllerMethod)) {
                        $ref = new ReflectionMethod($controllerClass, $controllerMethod);
                        foreach ($ref->getParameters() as $param) {
                            $type = $param->getType();
                            if (! $type) {
                                continue;
                            }

                            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                                $t = $type->getName();
                                if (is_subclass_of($t, \Illuminate\Foundation\Http\FormRequest::class)) {
                                    $extraByNorm[$norm]['requestClasses'][] = $t;
                                }
                                if (Str::contains(strtolower($controllerMethod), 'update') || Str::contains(strtolower($controllerMethod), 'edit')) {
                                    $extraByNorm[$norm]['intents'][] = 'update';
                                }
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        }

        foreach ($extraByNorm as $norm => $meta) {
            $already = $routes->first(function ($r) use ($norm) {
                return isset($r->uri) && $this->normalizeRouteUri($r->uri) === $norm;
            });

            if ($already) {
                $existingMethods = $already->methods ?? ($already->route->methods ?? []);
                $merged = array_values(array_unique(array_merge($existingMethods, $meta['methods'])));
                $already->methods = $merged;

                continue;
            }

            $requestClass = $meta['requestClasses'][0] ?? null;
            $intent = in_array('update', $meta['intents']) ? 'update' : 'create';

            $routes->push((object) [
                'route' => $meta['sampleRoute'],
                'uri' => $meta['uris'][0] ?? '',
                'methods' => $meta['methods'],
                'requestClass' => $requestClass,
                'intent' => $intent,
                'controllerMethod' => null,
            ]);
        }

        // Fallback: conventional resource paths
        if ($routes->isEmpty()) {
            $conventional = [
                (object) ['uri' => "{prefix}/{$resourceName}", 'methods' => ['GET', 'POST'], 'requestClass' => null, 'intent' => 'create'],
                (object) ['uri' => "{prefix}/{$resourceName}/{id}", 'methods' => ['GET', 'POST', 'DELETE'], 'requestClass' => null, 'intent' => 'update'],
            ];
            $routes = collect($conventional);
        }

        $firstUri = optional($routes->first())->uri ?? "{prefix}/{$resourceName}";
        $prefix = $this->folderFromUri($firstUri);

        if ($prefix === '{prefix}' || Str::contains($firstUri, '{prefix}')) {
            $prefix = $this->guessPrefixForResource($resourceName);
        }

        $targetDir = public_path(config('laraswag.public_path', 'swagger_ui') . DIRECTORY_SEPARATOR . $prefix);
        if (! File::exists($targetDir)) {
            File::makeDirectory($targetDir, 0755, true);
        }

        $serverUrl = rtrim(Config::get('app.url', 'http://127.0.0.1'), '/') . '/api';

        $yaml = [];
        $yaml[] = 'openapi: 3.0.3';
        $yaml[] = 'info:';
        $yaml[] = "  title: {$tag} API";
        $yaml[] = '  version: 1.0.0';
        $yaml[] = '';
        $yaml[] = 'servers:';
        $yaml[] = "  - url: {$serverUrl}";
        $yaml[] = '';
        $yaml[] = 'paths:';

        $paths = [];

        $mergedRequests = [
            'create' => ['required' => [], 'properties' => []],
            'update' => ['required' => [], 'properties' => []],
        ];

        foreach ($routes as $item) {
            $uriRaw = $item->uri ?? ($item->route->uri ?? '');
            $uri = $this->normalizeRouteUri($uriRaw);

            if (Str::contains($uri, '{prefix}')) {
                $uri = str_replace('{prefix}', $prefix, $uri);
            }

            $methods = $item->methods ?? ($item->route->methods ?? []);

            $methodMap = $this->mapMethodsToOperations($methods);

            $requestClass = $item->requestClass ?? null;
            $intent = $item->intent ?? null;
            if ($requestClass && class_exists($requestClass)) {
                $parsed = $this->parseFormRequestRules($requestClass);
                if (! empty($parsed)) {
                    $mergedRequests[$intent === 'update' ? 'update' : 'create']['required'] = array_values(array_unique(array_merge($mergedRequests[$intent === 'update' ? 'update' : 'create']['required'], $parsed['required'] ?? [])));
                    foreach (($parsed['properties'] ?? []) as $k => $v) {
                        $mergedRequests[$intent === 'update' ? 'update' : 'create']['properties'][$k] = $v;
                    }
                }
            }

            if (! isset($paths[$uri])) {
                $paths[$uri] = [];
            }

            foreach ($methodMap as $http => $op) {
                if (! empty($op['request']) && $intent) {
                    $op['request'] = $intent === 'update' ? ["$modelBase" . "UpdateRequest"] : ["$modelBase" . "CreateRequest"];
                }

                $paths[$uri][$http] = $op;
            }
        }

        // Try to discover conventional FormRequests
        $storeClass = $this->discoverFormRequestClass($modelBase, 'Store');
        if ($storeClass && class_exists($storeClass)) {
            $parsed = $this->parseFormRequestRules($storeClass);
            if (! empty($parsed)) {
                $mergedRequests['create']['required'] = array_values(array_unique(array_merge($mergedRequests['create']['required'], $parsed['required'] ?? [])));
                foreach (($parsed['properties'] ?? []) as $k => $v) {
                    $mergedRequests['create']['properties'][$k] = $v;
                }
            }
        }

        $updateClass = $this->discoverFormRequestClass($modelBase, 'Update');
        if ($updateClass && class_exists($updateClass)) {
            $parsed = $this->parseFormRequestRules($updateClass);
            if (! empty($parsed)) {
                $mergedRequests['update']['required'] = array_values(array_unique(array_merge($mergedRequests['update']['required'], $parsed['required'] ?? [])));
                foreach (($parsed['properties'] ?? []) as $k => $v) {
                    $mergedRequests['update']['properties'][$k] = $v;
                }
            }
        }

        $available = [];
        foreach ($apiRoutes as $route) {
            $ruri = $this->normalizeRouteUri($route->uri ?? ($route->uri() ?? ''));
            $rmethods = array_map('strtolower', $route->methods ?? ($route->methods() ?? []));

            if (! isset($available[$ruri])) {
                $available[$ruri] = [];
            }
            $available[$ruri] = array_values(array_unique(array_merge($available[$ruri], $rmethods)));

            if (! Str::startsWith($ruri, $prefix) && $prefix) {
                $prefixed = trim("{$prefix}/{$ruri}", '/');
                if (! isset($available[$prefixed])) {
                    $available[$prefixed] = [];
                }
                $available[$prefixed] = array_values(array_unique(array_merge($available[$prefixed], $rmethods)));
            }
        }

        foreach ($paths as $uri => $methodMap) {
            foreach (array_keys($methodMap) as $http) {
                if (! in_array($http, $available[$uri] ?? [], true)) {
                    unset($paths[$uri][$http]);
                }
            }

            if (empty($paths[$uri])) {
                continue;
            }

            $yaml[] = "  /{$uri}:";

            foreach ($paths[$uri] as $http => $op) {
                $summary = $op['summary'] . ' ' . $tag;
                $yaml[] = "    {$http}:";
                $yaml[] = "      summary: {$summary}";
                $yaml[] = "      tags: [{$tag}]";

                $yaml[] = '      security:';
                $yaml[] = "        - bearerAuth: []";

                $params = $this->pathParametersFromUri($uri);
                if (! empty($params)) {
                    $yaml[] = '      parameters:';
                    foreach ($params as $p) {
                        $yaml[] = "        - name: {$p}";
                        $yaml[] = "          in: path";
                        $yaml[] = "          required: true";
                        $yaml[] = "          schema:";
                        $yaml[] = "            type: string";
                    }
                }

                if (! empty($op['request'])) {
                    $yaml[] = '      requestBody:';
                    $yaml[] = '        required: true';
                    $yaml[] = '        content:';
                    $yaml[] = '          application/json:';
                    $yaml[] = '            schema:';
                    $yaml[] = '              $ref: "#/components/schemas/' . ($op['request'][0] ?? '') . '"';
                }

                $yaml[] = '      responses:';
                foreach ($op['responses'] as $code => $resp) {
                    $yaml[] = "        '{$code}':";
                    $yaml[] = "          description: {$resp['description']}";
                }

                $yaml[] = '';
            }
        }

        // components
        $yaml[] = 'components:';
        $yaml[] = '  securitySchemes:';
        $yaml[] = '    bearerAuth:';
        $yaml[] = '      type: http';
        $yaml[] = '      scheme: bearer';
        $yaml[] = '      bearerFormat: JWT';
        $yaml[] = '';
        $yaml[] = '  schemas:';

        $modelInstance = new $modelClass;
        $fillable = method_exists($modelInstance, 'getFillable') ? $modelInstance->getFillable() : [];
        $casts = method_exists($modelInstance, 'getCasts') ? $modelInstance->getCasts() : [];

        $props = array_unique(array_merge(['id', 'created_at', 'updated_at'], $fillable));

        $yaml[] = "    {$modelBase}:";
        $yaml[] = '      type: object';
        $yaml[] = '      properties:';
        foreach ($props as $prop) {
            $type = $this->typeFromCast($prop, $casts);
            $yaml[] = "        {$prop}:";
            $yaml[] = "          type: {$type}";
        }

        $yaml[] = '';
        $yaml[] = "    {$modelBase}CreateRequest:";
        $yaml[] = '      type: object';

        $createRequired = $mergedRequests['create']['required'] ?? [];
        if (! empty($createRequired)) {
            $yaml[] = '      required:';
            foreach ($createRequired as $r) {
                $yaml[] = "        - {$r}";
            }
        }

        $yaml[] = '      properties:';
        $createProps = $mergedRequests['create']['properties'] ?? [];
        $allCreateKeys = array_unique(array_merge($fillable, array_keys($createProps)));
        foreach ($allCreateKeys as $prop) {
            $schema = $createProps[$prop] ?? null;
            if ($schema && is_array($schema)) {
                $yaml[] = "        {$prop}:";
                $this->writeSchemaToYaml($yaml, $schema, '          ');

                continue;
            }

            $type = $this->typeFromCast($prop, $casts);
            $yaml[] = "        {$prop}:";
            $yaml[] = "          type: {$type}";
        }

        $yaml[] = '';
        $yaml[] = "    {$modelBase}UpdateRequest:";
        $yaml[] = '      type: object';
        $yaml[] = '      properties:';
        $updateProps = $mergedRequests['update']['properties'] ?? [];
        $allUpdateKeys = array_unique(array_merge($fillable, array_keys($updateProps)));
        foreach ($allUpdateKeys as $prop) {
            $schema = $updateProps[$prop] ?? null;
            if ($schema && is_array($schema)) {
                $yaml[] = "        {$prop}:";
                $this->writeSchemaToYaml($yaml, $schema, '          ');

                continue;
            }

            $type = $this->typeFromCast($prop, $casts);
            $yaml[] = "        {$prop}:";
            $yaml[] = "          type: {$type}";
        }

        $content = implode("\n", $yaml);

        $fileName = $resourceName . '.yaml';
        $targetFile = $targetDir . DIRECTORY_SEPARATOR . $fileName;
        File::put($targetFile, $content);

        return $targetFile;
    }

    protected function resolveModelClass(string $model): string
    {
        if (class_exists($model)) {
            return $model;
        }

        $candidate = 'App\\Models\\' . ltrim($model, '\\');

        return $candidate;
    }

    protected function folderFromUri(string $uri): string
    {
        $parts = explode('/', trim($uri, '/'));
        if (isset($parts[0]) && $parts[0] === 'api') {
            return $parts[1] ?? 'auto';
        }

        return $parts[0] ?? 'auto';
    }

    protected function normalizeRouteUri(string $uri): string
    {
        $normalized = ltrim($uri, '/');
        if (Str::startsWith($normalized, 'api/')) {
            $normalized = preg_replace('#^api/#', '', $normalized);
        }

        return trim($normalized, '/');
    }

    protected function pathParametersFromUri(string $uri): array
    {
        preg_match_all('/\{([^}]+)\}/', $uri, $matches);

        return $matches[1] ?? [];
    }

    protected function typeFromCast(string $prop, array $casts): string
    {
        $cast = $casts[$prop] ?? null;
        if (! $cast) {
            if (Str::endsWith($prop, '_id') || $prop === 'id') {
                return 'integer';
            }
            if (Str::contains($prop, ['date', 'at'])) {
                return 'string';
            }

            return 'string';
        }

        if (in_array($cast, ['integer', 'int', 'smallint', 'bigint'])) {
            return 'integer';
        }
        if (in_array($cast, ['boolean', 'bool'])) {
            return 'boolean';
        }
        if (in_array($cast, ['array', 'json'])) {
            return 'object';
        }
        if (in_array($cast, ['datetime', 'date', 'date:Y-m-d'])) {
            return 'string';
        }

        return 'string';
    }

    protected function mapMethodsToOperations(array $methods): array
    {
        $map = [];
        $upper = array_map('strtoupper', $methods);
        foreach ($upper as $m) {
            if ($m === 'GET') {
                $map['get'] = [
                    'summary' => 'Retrieve',
                    'responses' => [
                        '200' => ['description' => 'OK'],
                    ],
                    'request' => [],
                ];
            } elseif ($m === 'POST') {
                $map['post'] = [
                    'summary' => 'Create or send',
                    'responses' => [
                        '201' => ['description' => 'Created'],
                    ],
                    'request' => ['body'],
                ];
            } elseif ($m === 'PUT' || $m === 'PATCH') {
                $map[strtolower($m)] = [
                    'summary' => 'Update',
                    'responses' => [
                        '200' => ['description' => 'Updated'],
                    ],
                    'request' => ['body'],
                ];
            } elseif ($m === 'DELETE') {
                $map['delete'] = [
                    'summary' => 'Delete',
                    'responses' => [
                        '204' => ['description' => 'No Content'],
                    ],
                    'request' => [],
                ];
            }
        }

        return $map;
    }

    protected function parseFormRequestRules(string $class): ?array
    {
        try {
            $instance = new $class;
            if (! method_exists($instance, 'rules')) {
                return null;
            }

            if (method_exists($instance, 'setUserResolver')) {
                $instance->setUserResolver(function () {});
            }

            $rules = $instance->rules();
        } catch (\Throwable $e) {
            return null;
        }

        if (empty($rules) || ! is_array($rules)) {
            return null;
        }

        $required = [];
        $properties = [];
        $arrayItemRules = [];

        foreach ($rules as $key => $rule) {
            if (strpos($key, '*') !== false) {
                $base = explode('.', $key)[0];
                $rulesArr = is_array($rule) ? $rule : array_map('trim', explode('|', (string) $rule));
                if (! isset($arrayItemRules[$base])) {
                    $arrayItemRules[$base] = [];
                }
                $arrayItemRules[$base] = array_merge($arrayItemRules[$base], $rulesArr);

                continue;
            }

            $rulesArr = is_array($rule) ? $rule : array_map('trim', explode('|', (string) $rule));

            foreach ($rulesArr as $r) {
                if (is_string($r) && ($r === 'required' || Str::startsWith($r, 'required:') || Str::contains($r, 'required'))) {
                    $required[] = $key;
                }

                if (is_object($r) && (method_exists($r, 'passes') || Str::endsWith(class_basename($r), 'Required'))) {
                    $required[] = $key;
                }
            }

            $schema = $this->mapValidationRulesToSchema($rulesArr);
            $properties[$key] = $schema;
        }

        foreach ($arrayItemRules as $base => $itemRules) {
            $itemSchema = $this->mapValidationRulesToSchema($itemRules);
            if (isset($properties[$base])) {
                $prop = $properties[$base];
                if (($prop['type'] ?? null) !== 'array') {
                    $prop['type'] = 'array';
                }
                $prop['items'] = $itemSchema;
                $properties[$base] = $prop;
            } else {
                $properties[$base] = ['type' => 'array', 'items' => $itemSchema];
            }
        }

        foreach ($properties as $k => $v) {
            if (($v['type'] ?? null) === 'array' && isset($rules[$k])) {
                $rulesArr = is_array($rules[$k]) ? $rules[$k] : array_map('trim', explode('|', (string) $rules[$k]));
                foreach ($rulesArr as $r) {
                    if (Str::startsWith($r, 'min:')) {
                        $val = (int) substr($r, 4);
                        $v['minItems'] = $val;
                    }
                    if (Str::startsWith($r, 'max:')) {
                        $val = (int) substr($r, 4);
                        $v['maxItems'] = $val;
                    }
                }
                $properties[$k] = $v;
            }
        }

        return ['required' => array_values(array_unique($required)), 'properties' => $properties];
    }

    protected function mapValidationRulesToSchema(array $rulesArr): array
    {
        $schema = ['type' => 'string'];

        foreach ($rulesArr as $r) {
            if (is_object($r)) {
                if (method_exists($r, 'values')) {
                    try {
                        $vals = $r->values();
                        $schema['enum'] = $vals;
                    } catch (\Throwable $e) {
                        // ignore
                    }
                }

                continue;
            }

            $str = (string) $r;

            if (Str::startsWith($str, 'in:')) {
                $vals = explode(',', substr($str, 3));
                $vals = array_map('trim', $vals);
                if (! empty($vals)) {
                    $schema['enum'] = $vals;
                }

                continue;
            }

            if (Str::startsWith($str, 'exists:')) {
                $schema['type'] = 'integer';

                continue;
            }

            if (Str::startsWith($str, 'date_format:')) {
                $schema['type'] = 'string';
                $schema['format'] = 'date';

                continue;
            }

            if (Str::startsWith($str, 'min:')) {
                $val = (int) substr($str, 4);
                if (($schema['type'] ?? '') === 'string') {
                    $schema['minLength'] = $val;
                } elseif (($schema['type'] ?? '') === 'array') {
                    $schema['minItems'] = $val;
                }

                continue;
            }

            if (Str::startsWith($str, 'max:')) {
                $val = (int) substr($str, 4);
                if (($schema['type'] ?? '') === 'string') {
                    $schema['maxLength'] = $val;
                } elseif (($schema['type'] ?? '') === 'array') {
                    $schema['maxItems'] = $val;
                }

                continue;
            }

            if (Str::contains($str, 'integer') || Str::contains($str, 'digits')) {
                $schema['type'] = 'integer';

                continue;
            }
            if (Str::contains($str, 'numeric')) {
                $schema['type'] = 'number';

                continue;
            }
            if (Str::contains($str, 'boolean')) {
                $schema['type'] = 'boolean';

                continue;
            }
            if (Str::contains($str, 'array')) {
                $schema['type'] = 'array';
                if (! isset($schema['items'])) {
                    $schema['items'] = ['type' => 'string'];
                }

                continue;
            }
            if (Str::contains($str, 'json')) {
                $schema['type'] = 'object';

                continue;
            }
            if (Str::contains($str, 'date') || Str::contains($str, 'datetime')) {
                $schema['type'] = 'string';
                $schema['format'] = 'date-time';

                continue;
            }
            if (Str::contains($str, 'email')) {
                $schema['type'] = 'string';
                $schema['format'] = 'email';

                continue;
            }
            if (Str::contains($str, 'url')) {
                $schema['type'] = 'string';
                $schema['format'] = 'uri';

                continue;
            }
            if (Str::contains($str, 'uuid')) {
                $schema['type'] = 'string';
                $schema['format'] = 'uuid';

                continue;
            }

            $schema['type'] = $schema['type'] ?? 'string';
        }

        return $schema;
    }

    protected function writeSchemaToYaml(array &$yaml, array $schema, string $indent)
    {
        $type = $schema['type'] ?? 'string';
        $yaml[] = "{$indent}type: {$type}";

        if (isset($schema['format'])) {
            $yaml[] = "{$indent}format: {$schema['format']}";
        }

        if (isset($schema['enum'])) {
            $yaml[] = "{$indent}enum:";
            foreach ($schema['enum'] as $val) {
                if (is_string($val)) {
                    $yaml[] = "{$indent}  - '{$val}'";
                } else {
                    $yaml[] = "{$indent}  - {$val}";
                }
            }
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $yaml[] = "{$indent}items:";
            $this->writeSchemaToYaml($yaml, $schema['items'], $indent . '  ');
        }

        if (isset($schema['minimum'])) {
            $yaml[] = "{$indent}minimum: {$schema['minimum']}";
        }
        if (isset($schema['maximum'])) {
            $yaml[] = "{$indent}maximum: {$schema['maximum']}";
        }
        if (isset($schema['minLength'])) {
            $yaml[] = "{$indent}minLength: {$schema['minLength']}";
        }
        if (isset($schema['maxLength'])) {
            $yaml[] = "{$indent}maxLength: {$schema['maxLength']}";
        }
        if (isset($schema['minItems'])) {
            $yaml[] = "{$indent}minItems: {$schema['minItems']}";
        }
        if (isset($schema['maxItems'])) {
            $yaml[] = "{$indent}maxItems: {$schema['maxItems']}";
        }
    }

    protected function discoverFormRequestClass(string $modelBase, string $prefix): ?string
    {
        $dir = app_path('Http/Requests');
        if (! is_dir($dir)) {
            return null;
        }

        $targetFile = "{$prefix}{$modelBase}Request.php";
        foreach (\Illuminate\Support\Facades\File::allFiles($dir) as $file) {
            if ($file->getFilename() !== $targetFile) {
                continue;
            }

            $rel = str_replace(app_path() . DIRECTORY_SEPARATOR, '', $file->getRealPath());
            $rel = str_replace(DIRECTORY_SEPARATOR, '\\', $rel);
            $fqcn = 'App\\' . substr($rel, 0, -4);

            return $fqcn;
        }

        return null;
    }

    protected function guessPrefixForResource(string $resourceName): string
    {
        foreach (Route::getRoutes() as $route) {
            $action = $route->getAction();
            $uri = $route->uri ?? ($route->uri() ?? '');
            $norm = $this->normalizeRouteUri($uri);
            $parts = array_values(array_filter(explode('/', trim($norm, '/'))));
            if (in_array($resourceName, $parts, true)) {
                $idx = array_search($resourceName, $parts, true);
                return $parts[$idx - 1] ?? $resourceName;
            }
        }

        return $resourceName;
    }

    protected function isApiRoute($route): bool
    {
        $uri = $route->uri ?? ($route->uri() ?? '');
        if (Str::startsWith($uri, 'api/')) {
            return true;
        }

        $action = $route->getAction();
        $middleware = $action['middleware'] ?? null;
        if (is_array($middleware) && in_array('api', $middleware, true)) {
            return true;
        }
        if (is_string($middleware) && Str::contains($middleware, 'api')) {
            return true;
        }

        return false;
    }
}
