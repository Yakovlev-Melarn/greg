<?php

use App\Http\Controllers\BlockedCardsController;
use App\Http\Controllers\CardsController;
use App\Http\Controllers\CompetitorCardsController;
use App\Http\Controllers\CopyCardController;
use App\Http\Controllers\DriverAdjustmentsController;
use App\Http\Controllers\DriversController;
use App\Http\Controllers\FleetController;
use App\Http\Controllers\IndexController;
use App\Http\Controllers\NotificationsController;
use App\Http\Controllers\TransportReportsController;
use Illuminate\Support\Facades\Route;

Route::get('/', [IndexController::class, 'index']);
Route::get('/copycard', [CopyCardController::class, 'index']);
Route::post('/copycard', [CopyCardController::class, 'index']);
Route::get('/cards', [CardsController::class, 'index']);
Route::get('/competitorCards', [CompetitorCardsController::class, 'index']);
Route::post('/competitorCards', [CompetitorCardsController::class, 'index']);
Route::get('/blockedCards', [BlockedCardsController::class, 'index']);
Route::get('/notifications', [NotificationsController::class, 'index']);
Route::get('/fleet', [FleetController::class, 'index']);
Route::get('/drivers', [DriversController::class, 'index']);
Route::get('/transport-reports', [TransportReportsController::class, 'index']);
Route::get('/driver-adjustments', [DriverAdjustmentsController::class, 'index']);
