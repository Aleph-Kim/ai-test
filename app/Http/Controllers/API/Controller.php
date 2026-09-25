<?php

namespace App\Http\Controllers\API;

use App\Models\Conversation;
use App\Services\NvidiaEmbeddingService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /*
        Response
    */
    public function responseData($stateCode = 200, $msg = '성공적으로 처리되었습니다.', $data = []): JsonResponse
    {
        return response()->json(['msg' => $msg, 'data' => $data], $stateCode);
    }

    /**
     * AI 공급자 호출 실패 로그 기록 후 사용자 표시용 메시지 반환 (공급자가 돌려준 HTTP 상태 코드 포함)
     */
    protected function aiFailure(Throwable $e, string $action, array $context = []): string
    {
        $request = $e instanceof RequestException ? $e : $e->getPrevious();
        $status = $request instanceof RequestException ? $request->response->status() : null;

        Log::error("AI {$action} 실패", [
            'provider' => NvidiaEmbeddingService::PROVIDER,
            'status' => $status,
            ...$context,
            'exception' => $e,
        ]);

        return 'AI 공급자 호출 실패'.($status ? " ({$status})" : '');
    }

    /**
     * 로그인 없이 대화 소유자를 구분하는 브라우저 식별자 (메인 페이지 접속 시 발급)
     */
    protected function clientId(Request $request): string
    {
        $clientId = $request->cookie('client_id');
        abort_unless(is_string($clientId) && Str::isUuid($clientId), 401, '브라우저 식별 정보가 없습니다. 페이지를 새로고침하세요.');

        return $clientId;
    }

    protected function authorizeConversation(Request $request, Conversation $conversation): void
    {
        abort_unless($conversation->client_id === $this->clientId($request), 404, '존재하지 않는 데이터입니다.');
    }
}
