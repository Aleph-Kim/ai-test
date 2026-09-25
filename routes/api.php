<?php

use App\Http\Controllers\API\ConversationController;
use App\Http\Controllers\API\DocumentController;
use App\Http\Controllers\API\MessageController;
use Illuminate\Support\Facades\Route;

// 규칙 문서
Route::controller(DocumentController::class)->prefix('documents')->group(function () {
    Route::get('/', 'index');
    Route::post('/', 'store');
    Route::patch('/{document}', 'update');
    Route::delete('/{document}', 'destroy');
});

// 대화
Route::controller(ConversationController::class)->group(function () {
    Route::get('/documents/{document}/conversations', 'index');
    Route::post('/documents/{document}/conversations', 'store');
    Route::patch('/conversations/{conversation}', 'update');
    Route::delete('/conversations/{conversation}', 'destroy');
});

// 메시지
Route::controller(MessageController::class)->prefix('conversations/{conversation}/messages')->group(function () {
    Route::get('/', 'index');
    Route::post('/', 'store');
});
