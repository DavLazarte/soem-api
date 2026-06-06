<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PrestadorController;
use App\Http\Controllers\SocioController;
use Illuminate\Support\Facades\Route;

// ─── PÚBLICO ─────────────────────────────────────────────────────
Route::post('/login', [AuthController::class, 'login']);

// ─── AUTENTICADO ─────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // ─── SOCIO ───────────────────────────────────────────────────
    Route::middleware('role:socio')->prefix('socio')->group(function () {
        Route::get('/dashboard', [SocioController::class, 'dashboard']);
        Route::get('/transacciones', [SocioController::class, 'transacciones']);
        Route::get('/acreditaciones', [SocioController::class, 'acreditaciones']);
        Route::put('/password', [SocioController::class, 'updatePassword']);
    });

    // ─── PRESTADOR ───────────────────────────────────────────────
    Route::middleware('role:prestador')->prefix('prestador')->group(function () {
        Route::get('/dashboard', [PrestadorController::class, 'dashboard']);
        Route::get('/socios/buscar', [PrestadorController::class, 'buscarSocio']);
        Route::post('/transacciones', [PrestadorController::class, 'registrarCompra']);
        Route::put('/transacciones/{id}', [PrestadorController::class, 'editarTransaccion']);
        Route::get('/transacciones', [PrestadorController::class, 'transacciones']);
        Route::post('/cuotas/{id}/cobrar', [PrestadorController::class, 'cobrarCuota']);
    });

    // ─── ADMIN ───────────────────────────────────────────────────
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        // Dashboard
        Route::get('/dashboard', [AdminController::class, 'dashboard']);

        // Socios CRUD
        Route::get('/socios', [AdminController::class, 'indexSocios']);
        Route::post('/socios', [AdminController::class, 'storeSocio']);
        Route::put('/socios/{id}', [AdminController::class, 'updateSocio']);
        Route::delete('/socios/{id}', [AdminController::class, 'destroySocio']);

        // Prestadores CRUD
        Route::get('/prestadores', [AdminController::class, 'indexPrestadores']);
        Route::post('/prestadores', [AdminController::class, 'storePrestador']);
        Route::put('/prestadores/{id}', [AdminController::class, 'updatePrestador']);
        Route::delete('/prestadores/{id}', [AdminController::class, 'destroyPrestador']);

        // Acreditaciones
        Route::post('/acreditaciones/masiva', [AdminController::class, 'acreditacionMasiva']);
        Route::get('/acreditaciones', [AdminController::class, 'acreditaciones']);

        // Transacciones
        Route::get('/transacciones', [AdminController::class, 'transacciones']);
        Route::post('/transacciones/{id}/anular', [AdminController::class, 'anularTransaccion']);

        // Periodos
        Route::get('/periodos', [AdminController::class, 'periodos']);
        Route::post('/periodos', [AdminController::class, 'crearPeriodo']);

        // Audit logs
        Route::get('/audit-logs', [AdminController::class, 'auditLogs']);

        // Settings
        Route::get('/settings', [AdminController::class, 'getSettings']);
        Route::put('/settings', [AdminController::class, 'updateSettings']);
    });
});
