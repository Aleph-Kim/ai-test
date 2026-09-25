<?php

use App\Http\Controllers\Web\ChatPageController;
use Illuminate\Support\Facades\Route;

// 채팅 화면
Route::get('/', ChatPageController::class);
