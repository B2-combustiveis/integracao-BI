<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminSessionController;
use App\Http\Controllers\Admin\IntegrationServiceController;

Route::get('/', function () {
    return view('welcome');
});

Route::view('/docs', 'swagger')->name('docs');

Route::get('/docs/openapi', function () {
    return response()->make(file_get_contents(resource_path('openapi.yaml')), 200, [
        'Content-Type' => 'application/yaml; charset=UTF-8',
    ]);
})->name('docs.openapi');

Route::middleware('guest')->group(function (): void {
    Route::get('/admin/login', [AdminSessionController::class, 'create'])->name('admin.login');
    Route::post('/admin/login', [AdminSessionController::class, 'store'])->name('admin.login.store');
});

Route::prefix('admin')->middleware('auth.admin-session')->group(function (): void {
    Route::get('/', [AdminDashboardController::class, 'index'])->name('admin.dashboard');
    Route::get('/services', [AdminDashboardController::class, 'services'])->name('admin.services');
    Route::post('/services/{service}/pause', [IntegrationServiceController::class, 'pause'])->name('admin.services.pause');
    Route::post('/services/{service}/resume', [IntegrationServiceController::class, 'resume'])->name('admin.services.resume');
    Route::post('/services/{service}/run', [IntegrationServiceController::class, 'run'])->name('admin.services.run');
    Route::post('/services/{service}/settings', [IntegrationServiceController::class, 'update'])->name('admin.services.update');
    Route::get('/overview', [AdminDashboardController::class, 'overview'])->name('admin.overview');
    Route::post('/logout', [AdminSessionController::class, 'destroy'])->name('admin.logout');
});
