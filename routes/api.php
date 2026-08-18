<?php

use App\Http\Controllers\Api\TesteController;
use App\Http\Controllers\Api\TokenVerificationController;
use Illuminate\Support\Facades\Route;

//ROTA DE TESTE DE CONEXÃO
Route::get('/teste', TesteController::class)
    ->middleware('auth.token');

Route::get('/verificar-token', TokenVerificationController::class)
    ->middleware('auth.database-token');
