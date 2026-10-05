<?php

namespace App\Support\Http;

use Attribute;

/**
 * Documenta en el OpenAPI (SDD §4.1, RNF-044) un error problem+json que la acción produce por
 * una regla de negocio (BusinessRuleException) y que Scramble no puede inferir. Lo lee
 * ProblemDetailsDocumentation.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class ProblemResponse
{
    public function __construct(public int $status, public string $description) {}
}
