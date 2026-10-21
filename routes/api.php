<?php

use App\Http\Controllers\Api\V1\ClientCoinGeckoMappingController;
use App\Http\Controllers\Api\V1\ClientExecutionReportController;
use App\Http\Controllers\Api\V1\ClientMarketController;
use App\Http\Controllers\Api\V1\ClientRiskFactorController;
use App\Http\Controllers\Api\V1\PaperTradingController;
use App\Http\Controllers\Api\V1\ReplayController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/client')->middleware(['client-auth', 'throttle:120,1'])->group(function (): void {
    Route::get('markets/{subscription}/replay/history', [ReplayController::class, 'history'])->whereUuid('subscription');
    Route::get('markets/{subscription}/replay/models', [ReplayController::class, 'models'])->whereUuid('subscription');
    Route::post('markets/{subscription}/replay/infer', [ReplayController::class, 'infer'])->whereUuid('subscription')->middleware('throttle:30,1');
    Route::get('coingecko-mappings', ClientCoinGeckoMappingController::class);
    Route::get('risk-factor', ClientRiskFactorController::class);
    Route::get('markets', [ClientMarketController::class, 'index']);
    Route::get('markets/{subscription}', [ClientMarketController::class, 'show'])->whereUuid('subscription');
    Route::put('markets/{subscription}/settings', [ClientMarketController::class, 'updateSettings'])->whereUuid('subscription')->middleware('throttle:30,1');
    Route::get('markets/{subscription}/reports', [ClientExecutionReportController::class, 'index'])->whereUuid('subscription');
    Route::post('markets/{subscription}/reports', [ClientExecutionReportController::class, 'store'])->whereUuid('subscription')->middleware('throttle:120,1');
    Route::get('markets/{subscription}/paper', [PaperTradingController::class, 'show'])->whereUuid('subscription');
    Route::post('markets/{subscription}/paper', [PaperTradingController::class, 'store'])->whereUuid('subscription')->middleware('throttle:60,1');
});
