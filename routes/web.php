<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::view('/docs', 'swagger')->name('docs');

Route::get('/docs/openapi', function () {
    return response()->make(file_get_contents(resource_path('openapi.yaml')), 200, [
        'Content-Type' => 'application/yaml; charset=UTF-8',
    ]);
})->name('docs.openapi');
