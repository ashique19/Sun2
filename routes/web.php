<?php

use App\Livewire\StorefrontHome;
use Illuminate\Support\Facades\Route;

Route::get('/', StorefrontHome::class)->name('home');
