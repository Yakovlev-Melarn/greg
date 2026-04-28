<?php

use App\Http\Controllers\Api\Api;
use Illuminate\Support\Facades\Route;

Route::post('/{entity}/{method}', [Api::class, "index"]);
