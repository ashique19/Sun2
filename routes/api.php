<?php

use App\Http\Controllers\CarryBeeWebhookController;
use App\Http\Controllers\PathaoWebhookController;
use App\Http\Controllers\RedxWebhookController;
use App\Http\Controllers\SteadfastWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/steadfast', SteadfastWebhookController::class)
    ->name('webhooks.steadfast');

Route::post('/webhooks/pathao', PathaoWebhookController::class)
    ->name('webhooks.pathao');

Route::post('/webhooks/redx', RedxWebhookController::class)
    ->name('webhooks.redx');

Route::post('/webhooks/carrybee', CarryBeeWebhookController::class)
    ->name('webhooks.carrybee');
