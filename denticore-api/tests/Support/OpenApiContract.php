<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Assert;

/**
 * Valida respuestas reales contra el documento OpenAPI 3.1 exportado (SDD §4.1, §6.1;
 * RNF-044). Los esquemas de OpenAPI 3.1 son JSON Schema 2020-12.
 */
final class OpenApiContract
{
    private const DOCUMENT_ID = 'https://denticore.test/openapi.json';

    /** @var list<string> Operaciones validadas ("GET /patients"). */
    public static array $covered = [];

    private static ?object $document = null;

    public static function path(): string
    {
        return base_path('openapi.json');
    }

    public static function document(): object
    {
        return self::$document ??= json_decode((string) file_get_contents(self::path()), false, flags: JSON_THROW_ON_ERROR);
    }

    public static function assertMatches(TestResponse $response, string $method, string $pathTemplate): void
    {
        $operation = self::document()->paths->{$pathTemplate}->{strtolower($method)} ?? null;
        Assert::assertNotNull($operation, "{$method} {$pathTemplate} no está en el documento OpenAPI.");

        $status = (string) $response->getStatusCode();
        $documented = $operation->responses->{$status} ?? null;
        Assert::assertNotNull($documented, "{$method} {$pathTemplate} respondió {$status}, que no está documentado.");

        $pointer = '/paths/'.self::escape($pathTemplate).'/'.strtolower($method).'/responses/'.$status;

        if (isset($documented->{'$ref'})) {
            $pointer = substr($documented->{'$ref'}, 1);
            $documented = self::resolve($pointer);
            $pointer = implode('/', array_map(rawurlencode(...), explode('/', $pointer)));
        }

        self::$covered[] = strtoupper($method).' '.$pathTemplate;

        $content = (array) ($documented->content ?? []);
        if ($content === []) {
            return;
        }

        $mediaType = strtolower(trim(explode(';', (string) $response->headers->get('Content-Type'))[0]));

        if (! array_key_exists($mediaType, $content)) {
            Assert::assertArrayHasKey('*/*', $content, "{$method} {$pathTemplate} {$status} respondió {$mediaType}, que no está documentado.");

            return;
        }

        if (! str_contains($mediaType, 'json')) {
            return;
        }

        $validator = new Validator;
        $validator->setMaxErrors(5);
        $validator->resolver()->registerRaw(self::document(), self::DOCUMENT_ID);

        $result = $validator->validate(
            json_decode((string) $response->getContent(), false),
            self::DOCUMENT_ID.'#'.$pointer.'/content/'.self::escape($mediaType).'/schema',
        );

        Assert::assertTrue(
            $result->isValid(),
            "{$method} {$pathTemplate} {$status} no cumple el contrato: "
                .json_encode($result->hasError() ? (new ErrorFormatter)->format($result->error()) : [], JSON_UNESCAPED_UNICODE),
        );
    }

    private static function resolve(string $pointer): object
    {
        $node = self::document();

        foreach (explode('/', ltrim($pointer, '/')) as $segment) {
            $node = $node->{str_replace(['~1', '~0'], ['/', '~'], $segment)};
        }

        return $node;
    }

    /**
     * Segmento de JSON Pointer (RFC 6901) codificado para el fragmento de un URI.
     */
    private static function escape(string $segment): string
    {
        return rawurlencode(str_replace(['~', '/'], ['~0', '~1'], $segment));
    }
}
