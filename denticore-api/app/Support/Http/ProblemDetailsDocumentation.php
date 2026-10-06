<?php

namespace App\Support\Http;

use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use ReflectionAttribute;
use ReflectionMethod;

/**
 * Transformador del documento OpenAPI (TASK-018; SDD §4.1, §4.2; RNF-044):
 * - documenta los errores como `application/problem+json` (RFC 9457) con el esquema
 *   `ProblemDetails`, que es lo que responde ProblemDetails::from;
 * - agrega a cada operación los errores que producen sus middleware (401 de `auth:sanctum`,
 *   403 de `role:`/`tenant`/Policies/`signed`, 404 de los parámetros de ruta, 429 de
 *   `throttle:` y 400/409/422 de `idempotent`), que Scramble no infiere.
 */
class ProblemDetailsDocumentation
{
    public function __invoke(OpenApi $openApi): void
    {
        $reference = $openApi->components->addSchema('ProblemDetails', Schema::fromType($this->problemType()));

        foreach ($openApi->components->responses as $response) {
            $response->content = [];
            $response->setContent('application/problem+json', Schema::fromType($reference));
        }

        foreach ($openApi->paths as $path) {
            foreach ($path->operations as $operation) {
                $route = $this->routeFor($path->path, $operation->method);

                if ($route === null) {
                    continue;
                }

                $this->addMiddlewareErrors($operation, $route, $reference);

                if ($route->getName() === 'files.download') {
                    $this->documentBinaryDownload($operation);
                }
            }
        }
    }

    private function problemType(): ObjectType
    {
        $errors = new ObjectType;
        $errors->additionalProperties = (new ArrayType)->setItems(new StringType);

        return (new ObjectType)
            ->addProperty('type', new StringType)
            ->addProperty('title', new StringType)
            ->addProperty('status', new IntegerType)
            ->addProperty('detail', new StringType)
            ->addProperty('instance', new StringType)
            ->addProperty('rule', new StringType)
            ->addProperty('errors', $errors)
            ->setRequired(['type', 'title', 'status', 'detail', 'instance']);
    }

    private function addMiddlewareErrors(Operation $operation, LaravelRoute $route, Reference $problem): void
    {
        $middleware = $route->gatherMiddleware();
        $has = fn (string $prefix): bool => collect($middleware)->contains(
            fn ($name): bool => is_string($name) && ($name === $prefix || Str::startsWith($name, $prefix.':')),
        );

        $codes = [];
        if ($has('auth')) {
            $codes[401] = 'No autenticado';
        }
        if ($has('role') || $has('tenant') || $has('can') || $has('signed')) {
            $codes[403] = 'Acceso denegado';
        }
        if ($route->parameterNames() !== []) {
            $codes[404] = 'Recurso no encontrado';
        }
        if ($has('throttle')) {
            $codes[429] = 'Demasiadas solicitudes';
        }
        if ($has('idempotent')) {
            $codes += [400 => 'Falta la cabecera Idempotency-Key', 409 => 'Solicitud en curso con la misma clave', 422 => 'Datos no válidos'];
        }

        foreach ($this->declaredProblems($route) as $declared) {
            $codes[$declared->status] ??= $declared->description;
        }

        $documented = array_map('strval', array_keys($operation->toArray()['responses'] ?? []));

        foreach ($codes as $code => $description) {
            if (! in_array((string) $code, $documented, true)) {
                $operation->addResponse(Response::make($code)
                    ->setDescription($description)
                    ->setContent('application/problem+json', Schema::fromType($problem)));
            }
        }
    }

    /**
     * Errores de reglas de negocio declarados con #[ProblemResponse] en la acción.
     *
     * @return list<ProblemResponse>
     */
    private function declaredProblems(LaravelRoute $route): array
    {
        $controller = $route->getControllerClass();
        $method = $route->getActionMethod();

        if ($controller === null || ! method_exists($controller, $method)) {
            return [];
        }

        return array_map(
            fn (ReflectionAttribute $attribute): ProblemResponse => $attribute->newInstance(),
            (new ReflectionMethod($controller, $method))->getAttributes(ProblemResponse::class),
        );
    }

    /**
     * La descarga firmada entrega el archivo tal cual (PDF, imagen…), no JSON.
     */
    private function documentBinaryDownload(Operation $operation): void
    {
        foreach ($operation->responses ?? [] as $response) {
            if ($response instanceof Response && (string) $response->code === '200') {
                $response->content = [];
                $response->setDescription('Contenido del archivo');
                $response->setContent('*/*', Schema::fromType((new StringType)->format('binary')));
            }
        }
    }

    private function routeFor(string $path, string $method): ?LaravelRoute
    {
        $uri = trim((string) config('scramble.api_path'), '/').'/'.ltrim($path, '/');

        return collect(Route::getRoutes()->getRoutes())->first(
            fn (LaravelRoute $route): bool => $route->uri() === $uri && in_array(strtoupper($method), $route->methods(), true),
        );
    }
}
