<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\DocumentController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::get('/', function (Request $request) {
    // 로그인 없이 대화 목록을 브라우저별로 구분하기 위한 식별자 (1년 유지)
    if (! $request->cookie('client_id')) {
        Cookie::queue('client_id', (string) Str::uuid(), 60 * 24 * 365);
    }

    return view('chat', [
        'provider' => config('ai.default'),
        'chatModel' => config('ai.providers.'.config('ai.default').'.models.text.default'),
    ]);
});

Route::prefix('api')->group(function () {
    Route::get('documents', [DocumentController::class, 'index']);
    Route::post('documents', [DocumentController::class, 'store']);
    Route::delete('documents/{document}', [DocumentController::class, 'destroy']);

    Route::get('documents/{document}/conversations', [ChatController::class, 'conversations']);
    Route::post('documents/{document}/conversations', [ChatController::class, 'createConversation']);
    Route::patch('conversations/{conversation}', [ChatController::class, 'renameConversation']);
    Route::get('conversations/{conversation}/messages', [ChatController::class, 'messages']);
    Route::post('conversations/{conversation}/messages', [ChatController::class, 'ask']);
});
