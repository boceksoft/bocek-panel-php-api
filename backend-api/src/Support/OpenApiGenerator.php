<?php

declare(strict_types=1);

namespace App\Support;

use ReflectionClass;
use ReflectionMethod;

/*
 * Controller docblock'larını tarayıp OpenAPI 3.0 (Swagger) dokümanı üretir.
 * Router ile aynı annotation'ları okur; ek olarak @query ve @body satırlarını
 * parametre/gövde şemasına çevirir. PHP 7.3 uyumlu.
 *
 * Desteklenen docblock etiketleri:
 *   İlk (etiketsiz) satırlar -> özet (summary)
 *   @Get / @Post / @Put / @Delete [("altyol")]
 *   @query <ad> <tip> [required] [açıklama]
 *   @body  <ad> <tip> [required] [açıklama]
 */
final class OpenApiGenerator
{
    /** @var string */
    private $controllerDir;

    /** @var string */
    private $namespace = 'App\\Controller\\';

    /** @var string */
    private $basePath;

    public function __construct(string $controllerDir, string $basePath = '')
    {
        $this->controllerDir = rtrim($controllerDir, '/');
        $basePath = '/' . trim($basePath, '/');
        $this->basePath = $basePath === '/' ? '' : $basePath;
    }

    public function generate(): array
    {
        $paths = [];

        foreach (glob($this->controllerDir . '/*Controller.php') ?: [] as $file) {
            $class = $this->namespace . basename($file, '.php');
            if (!class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract()) {
                continue;
            }

            $short = $reflection->getShortName();
            $resource = lcfirst(substr($short, 0, -strlen('Controller')));

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $doc = $method->getDocComment();
                $routes = $this->parseRoutes($doc);
                if ($routes === []) {
                    continue;
                }

                $query = $this->parseParams($doc, 'query');
                $body = $this->parseParams($doc, 'body');

                foreach ($routes as $route) {
                    $path = '/' . $resource;
                    if ($route['path'] !== '') {
                        $path .= '/' . $route['path'];
                    }
                    $verb = strtolower($route['method']);

                    $operation = [
                        'tags' => [$short],
                        'summary' => $this->parseSummary($doc),
                        'operationId' => $resource . '_' . $method->getName() . '_' . $verb,
                        'security' => [['bearerAuth' => []]],
                        'responses' => [
                            '200' => ['description' => 'Başarılı yanıt (standart zarf: success + data)'],
                            '401' => ['description' => 'Yetkisiz (token yok / geçersiz)'],
                        ],
                    ];

                    if ($query !== []) {
                        $operation['parameters'] = $this->toParameters($query);
                    }
                    if ($body !== []) {
                        $operation['requestBody'] = $this->toRequestBody($body);
                    }

                    $paths[$path][$verb] = $operation;
                }
            }
        }

        return $this->document($paths);
    }

    /**
     * @param string|false $doc
     * @return array<int,array{method:string,path:string}>
     */
    private function parseRoutes($doc): array
    {
        if (!is_string($doc)) {
            return [];
        }

        $pattern = '/@(Get|Post|Put|Delete)\b\s*(?:\(\s*["\']?([^"\')]*)["\']?\s*\))?/';
        if (preg_match_all($pattern, $doc, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        $routes = [];
        foreach ($matches as $m) {
            $routes[] = [
                'method' => strtoupper($m[1]),
                'path' => isset($m[2]) ? trim($m[2], '/') : '',
            ];
        }

        return $routes;
    }

    /**
     * Docblock'un etiketsiz ilk satırlarını özet olarak alır.
     *
     * @param string|false $doc
     */
    private function parseSummary($doc): string
    {
        if (!is_string($doc)) {
            return '';
        }

        $lines = [];
        foreach (explode("\n", $doc) as $line) {
            $line = trim($line, " \t/*");
            if ($line === '') {
                continue;
            }
            if ($line[0] === '@') {
                break;
            }
            $lines[] = $line;
        }

        return implode(' ', $lines);
    }

    /**
     * @query / @body satırlarını çözer: <ad> <tip> [required] [açıklama]
     *
     * @param string|false $doc
     * @return array<int,array{name:string,type:string,required:bool,desc:string}>
     */
    private function parseParams($doc, string $tag): array
    {
        if (!is_string($doc)) {
            return [];
        }

        $result = [];
        $pattern = '/@' . $tag . '\s+(\S+)\s+(\S+)((?:\s+required)?)\s*(.*)$/m';
        if (preg_match_all($pattern, $doc, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        foreach ($matches as $m) {
            $result[] = [
                'name' => $m[1],
                'type' => $m[2],
                'required' => trim($m[3]) === 'required',
                'desc' => trim($m[4]),
            ];
        }

        return $result;
    }

    /**
     * @param array<int,array{name:string,type:string,required:bool,desc:string}> $params
     */
    private function toParameters(array $params): array
    {
        $out = [];
        foreach ($params as $p) {
            $out[] = [
                'name' => $p['name'],
                'in' => 'query',
                'required' => $p['required'],
                'description' => $p['desc'],
                'schema' => ['type' => $this->mapType($p['type'])],
            ];
        }

        return $out;
    }

    /**
     * @param array<int,array{name:string,type:string,required:bool,desc:string}> $params
     */
    private function toRequestBody(array $params): array
    {
        $properties = [];
        $required = [];

        foreach ($params as $p) {
            $schema = ['type' => $this->mapType($p['type'])];
            if ($schema['type'] === 'array') {
                $schema['items'] = ['type' => 'integer'];
            }
            if ($p['desc'] !== '') {
                $schema['description'] = $p['desc'];
            }
            $properties[$p['name']] = $schema;

            if ($p['required']) {
                $required[] = $p['name'];
            }
        }

        $objectSchema = ['type' => 'object', 'properties' => $properties];
        if ($required !== []) {
            $objectSchema['required'] = $required;
        }

        return [
            'required' => true,
            'content' => ['application/json' => ['schema' => $objectSchema]],
        ];
    }

    private function mapType(string $type): string
    {
        switch (strtolower($type)) {
            case 'int':
            case 'integer':
                return 'integer';
            case 'float':
            case 'double':
            case 'number':
                return 'number';
            case 'bool':
            case 'boolean':
                return 'boolean';
            case 'array':
                return 'array';
            default:
                return 'string';
        }
    }

    private function document(array $paths): array
    {
        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'Bocek Panel API',
                'version' => '1.0.0',
                'description' => 'Controller docblock\'larından otomatik üretilmiştir.',
            ],
            'servers' => [
                ['url' => $this->basePath !== '' ? $this->basePath : '/', 'description' => 'Bu sunucu'],
            ],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                    ],
                ],
            ],
            'paths' => $paths,
        ];
    }
}
