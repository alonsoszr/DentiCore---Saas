<?php

use App\Support\Files\FileDownloadController;
use Illuminate\Support\Facades\Route;

// Descarga de archivos con URL firmada de 10 minutos (SDD DI-16, DD-18). Ruta de fontanería:
// la emite FileStorage::temporaryUrl después de la Policy del recurso.
Route::get('/files/{tenant}/{file}', FileDownloadController::class)
    ->middleware('signed')
    ->name('files.download');
