<?php

use App\Http\Controllers\CarryBeeWebhookController;
use App\Http\Controllers\PathaoWebhookController;
use App\Http\Controllers\RedxWebhookController;
use App\Http\Controllers\SteadfastWebhookController;
use Illuminate\Support\Facades\Route;

// Steadfast portal callback: /api/steadfast/webhook
Route::post('/steadfast/webhook', SteadfastWebhookController::class)
    ->name('webhooks.steadfast');

// Legacy alias
Route::post('/webhooks/steadfast', SteadfastWebhookController::class)
    ->name('webhooks.steadfast.legacy');

Route::post('/webhooks/pathao', PathaoWebhookController::class)
    ->name('webhooks.pathao');

Route::post('/webhooks/redx', RedxWebhookController::class)
    ->name('webhooks.redx');

Route::post('/webhooks/carrybee', CarryBeeWebhookController::class)
    ->name('webhooks.carrybee');
