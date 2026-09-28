<?php

use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Configuración de Pest
|--------------------------------------------------------------------------
|
| Las pruebas heredadas (clases PHPUnit) siguen funcionando sin cambios; esta
| configuración solo aplica a las pruebas escritas con la sintaxis de Pest
| (SDD §6.1, §6.2). Las pruebas feature siempre corren contra PostgreSQL 16.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Authorization', 'Contract');

pest()->extend(TestCase::class)->in('Unit');
