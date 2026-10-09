<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API /api/v1 (SDD §4.1)
|--------------------------------------------------------------------------
|
| Un archivo de rutas por módulo en routes/api/<modulo>.php.
|
*/

Route::group([], base_path('routes/api/identity.php'));
Route::group([], base_path('routes/api/platform.php'));
Route::group([], base_path('routes/api/patients.php'));
Route::group([], base_path('routes/api/odontogram.php'));
Route::group([], base_path('routes/api/treatment.php'));
Route::group([], base_path('routes/api/files.php'));
