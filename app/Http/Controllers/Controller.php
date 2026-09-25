<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Throwable;

abstract class Controller
{
    /**
     * AI 공급자 호출 실패 로그 기록 후 사용자 표시용 메시지 반환 (공급자가 돌려준 HTTP 상태 코드 포함)
     */
    protected function aiFailure(Throwable $e, string $action, array $context = []): string
    {
        $request = $e instanceof RequestException ? $e : $e->getPrevious();
        $status = $request instanceof RequestException ? $request->response->status() : null;

        Log::error("AI {$action} 실패", [
            'provider' => config('ai.default'),
            'status' => $status,
            ...$context,
            'exception' => $e,
        ]);

        return 'AI 공급자 호출 실패'.($status ? " ({$status})" : '');
    }
}
