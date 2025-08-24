<?php

use App\Http\Controllers\IndexController;
use App\Http\Controllers\Api\Api;
use Illuminate\Support\Facades\Route;

Route::get('/', [IndexController::class, "index"]);
Route::post('/api/{entity}/{method}', [Api::class, "index"]);
