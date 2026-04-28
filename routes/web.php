<?php

use App\Http\Controllers\CardsController;
use App\Http\Controllers\BlockedCardsController;
use App\Http\Controllers\CompetitorCardsController;
use App\Http\Controllers\CopyCardController;
use App\Http\Controllers\IndexController;
use Illuminate\Support\Facades\Route;

Route::get('/', [IndexController::class, "index"]);
Route::get('/copycard', [CopyCardController::class, "index"]);
Route::post('/copycard', [CopyCardController::class, "index"]);
Route::get('/cards', [CardsController::class, "index"]);
Route::get('/competitorCards', [CompetitorCardsController::class, "index"]);
Route::post('/competitorCards', [CompetitorCardsController::class, "index"]);
Route::get('/blockedCards', [BlockedCardsController::class, "index"]);

