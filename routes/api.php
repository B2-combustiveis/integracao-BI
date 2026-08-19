<?php

use App\Http\Controllers\Api\WebPosto\EmpresasController;
use App\Http\Controllers\Api\WebPosto\ClienteGruposController;
use App\Http\Controllers\Api\WebPosto\ClienteEmpresasController;
use App\Http\Controllers\Api\WebPosto\ClientesController;
use App\Http\Controllers\Api\WebPosto\ProdutoGruposController;
use App\Http\Controllers\Api\WebPosto\ProdutoLmcLmpController;
use App\Http\Controllers\Api\WebPosto\ProdutoEmpresasController;
use App\Http\Controllers\Api\WebPosto\ProdutoSubgruposController;
use App\Http\Controllers\Api\WebPosto\ProdutosController;
use App\Http\Controllers\Api\WebPosto\RawResourcesController;
use App\Services\WebPosto\WebPostoResourceCatalog;
use App\Http\Controllers\Api\TesteController;
use App\Http\Controllers\Api\TokenVerificationController;
use Illuminate\Support\Facades\Route;

//ROTAS DE TESTE DE CONEXÃO
Route::get('/teste', TesteController::class)
    ->middleware('auth.database-token');

Route::get('/verificar-token', TokenVerificationController::class)
    ->middleware('auth.database-token');

//ROTAS WEBPOSTO INTEGRAÇÃO
Route::prefix('webposto')
    ->middleware('auth.database-token')
    ->group(function (): void {
        Route::get('/empresas', EmpresasController::class);
        Route::get('/cliente-grupos', ClienteGruposController::class);
        Route::get('/cliente-empresas', ClienteEmpresasController::class);
        Route::get('/clientes', ClientesController::class);
        Route::get('/produto-grupos', ProdutoGruposController::class);
        Route::get('/produto-lmc-lmp', ProdutoLmcLmpController::class);
        Route::get('/produto-empresas', ProdutoEmpresasController::class);
        Route::get('/produto-subgrupos', ProdutoSubgruposController::class);
        Route::get('/produtos', ProdutosController::class);
        Route::get('/{resource}', RawResourcesController::class)
            ->whereIn('resource', array_keys(app(WebPostoResourceCatalog::class)->all()));
    });
