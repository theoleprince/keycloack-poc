<?php

use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\DiscoveryController;
use App\Http\Controllers\LegacyAuthController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\NoteController;
use App\Http\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;

// --- Public ----------------------------------------------------------------
Route::get('/health', fn () => response()->json(['status' => 'ok']));
Route::get('/discover', DiscoveryController::class)->middleware('throttle:discover');

// --- Legacy (Sanctum) : à retirer après migration -------------------------
Route::post('/login', [LegacyAuthController::class, 'login'])->middleware('throttle:10,1');
Route::get('/legacy/me', [LegacyAuthController::class, 'me'])->middleware(['auth:sanctum', 'tenant.user']);

// --- Keycloak OU Sanctum (cohabitation), scopé société --------------------
Route::middleware('auth.any')->group(function () {
    Route::get('/me', MeController::class);

    Route::get('/notes', [NoteController::class, 'index']);
    Route::post('/notes', [NoteController::class, 'store']);
    Route::get('/notes/{note}', [NoteController::class, 'show']);
    Route::get('/projects', [ProjectController::class, 'index']);
    Route::get('/projects/{project}', [ProjectController::class, 'show']);

    Route::get('/admin/users', [AdminUserController::class, 'index'])->middleware('role:admin');
});
