<?php

declare(strict_types=1);

namespace App\Core;

use App\Controller\Controller;
use ReflectionClass;
use ReflectionMethod;

/*
 * Docblock annotation tabanlı yönlendirici (PHP 7.3 uyumlu — .NET Core [HttpGet] hissi).
 * PHP 7.3'te native attribute (#[...]) olmadığından, verb metot docblock'undan okunur.
 *
 * Kaynak (controller) konvansiyonla bulunur (base_path zaten soyulmuş):
 *   /{resource} -> App\Controller\{Resource}Controller
 *
 * Aksiyon, metodun docblock'undaki annotation ile eşlenir (metot adı serbest):
 *   @Get            -> GET  /{resource}
 *   @Post           -> POST /{resource}
 *   @Get("detail")  -> GET  /{resource}/detail
 */
final class Router
{
    /** @var Request */
    private $request;

    /** @var Response */
    private $response;

    /** @var Database */
    private $db;

    public function __construct(Request $request, Response $response, Database $db)
    {
        $this->request = $request;
        $this->response = $response;
        $this->db = $db;
    }

    public function dispatch(): void
    {
        $segments = array_values(array_filter(explode('/', $this->request->path())));

        // Beklenen yapı: {resource}[/{altyol}]  (base_path zaten soyulmuş durumda)
        if ($segments === []) {
            throw new HttpException('Endpoint bulunamadı.', 'NOT_FOUND', 404);
        }

        $controllerClass = 'App\\Controller\\' . $this->studly($segments[0]) . 'Controller';

        if (!class_exists($controllerClass)) {
            throw new HttpException('Endpoint bulunamadı: ' . $segments[0], 'NOT_FOUND', 404);
        }

        $subPath = implode('/', array_slice($segments, 1)); // '' veya 'detail'
        $action = $this->matchAction($controllerClass, $this->request->method(), $subPath);

        if ($action === null) {
            throw new HttpException('Endpoint bulunamadı.', 'NOT_FOUND', 404);
        }

        $controller = new $controllerClass($this->request, $this->response, $this->db);
        $controller->{$action}();
    }

    /**
     * Docblock'undaki @Verb annotation'ı istenen verb + alt yola uyan ilk public metodu bulur.
     *
     * @return string|null
     */
    private function matchAction(string $controllerClass, string $httpMethod, string $subPath)
    {
        $reflection = new ReflectionClass($controllerClass);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            // Bir metotta birden çok annotation olabilir (örn. hem @Get hem @Post).
            foreach ($this->parseDocRoutes($method->getDocComment()) as $route) {
                if ($route['method'] === $httpMethod && $route['path'] === $subPath) {
                    return $method->getName();
                }
            }
        }

        return null;
    }

    /**
     * Docblock'taki tüm "@Get", "@Post('detail')" annotation'larını verb + alt yola çevirir.
     *
     * @param string|false $docComment
     * @return array<int,array{method:string,path:string}>
     */
    private function parseDocRoutes($docComment): array
    {
        if (!is_string($docComment)) {
            return [];
        }

        $pattern = '/@(Get|Post|Put|Delete)\b\s*(?:\(\s*["\']?([^"\')]*)["\']?\s*\))?/';
        if (preg_match_all($pattern, $docComment, $matches, PREG_SET_ORDER) === false) {
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

    private function studly(string $value): string
    {
        $value = str_replace(['-', '_'], ' ', $value);

        return str_replace(' ', '', ucwords($value));
    }
}
