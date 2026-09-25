<?php

namespace App\Http\Controllers\API;

use App\Models\Conversation;
use App\Services\NvidiaEmbeddingService;
use App\Services\RegulationChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\StreamedEvent;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * @tags 메시지
 */
class MessageController extends Controller
{
    public function __construct(private RegulationChatService $regulationChat) {}

    /**
     * 대화 메시지 목록 조회
     */
    public function index(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversation($request, $conversation);

        $list = $conversation->messages()->orderBy('id')->get(['role', 'content', 'calculation', 'citations', 'clarifications', 'provider', 'model', 'elapsed_ms', 'created_at']);

        return $this->responseData(data: ['list' => $list]);
    }

    /**
     * 질문 전송 및 답변 스트리밍 (SSE: delta → done 또는 error)
     */
    public function store(Request $request, Conversation $conversation): StreamedResponse
    {
        $startedAt = hrtime(true);
        $this->authorizeConversation($request, $conversation);
        $question = $request->validate(['question' => ['required', 'string', 'max:2000']])['question'];

        $context = $this->regulationChat->context($conversation, $question);

        // 스트림 중 오류가 나도 질문은 남도록 먼저 저장
        $conversation->messages()->create(['role' => 'user', 'content' => $question]);

        return response()->eventStream(function () use ($conversation, $question, $context, $startedAt) {
            // 웹 요청 기본 실행 제한(30초)에 걸리면 오류 이벤트 없이 강제 종료되므로 해제 (대기 한도는 채팅 서비스 타임아웃)
            set_time_limit(0);

            try {
                $found = $this->regulationChat->search($conversation->document_id, $context['search_query']);

                $answer = '';
                foreach ($this->regulationChat->stream($context, $found, $question) as $delta) {
                    $answer .= $delta;
                    yield $this->event('delta', $delta);
                }
                // 빈 답변을 정상 답변으로 저장하면 화면에 빈 말풍선만 남으므로 오류로 처리
                if (trim($answer) === '') {
                    throw new RuntimeException('AI 공급자가 빈 답변을 반환했습니다.');
                }
            } catch (Throwable $e) {
                // 새로고침 후에도 대화에 실패 기록이 남도록 오류 메시지로 저장
                $error = $conversation->messages()->create([
                    'role' => 'error',
                    'content' => $this->aiFailure($e, '답변 생성', ['conversation_id' => $conversation->id]),
                    'elapsed_ms' => intdiv(hrtime(true) - $startedAt, 1_000_000),
                ]);
                yield $this->event('error', ['message' => $error->content, 'created_at' => $error->created_at->toJSON()]);

                return;
            }

            $parsed = $this->regulationChat->parseAnswer($conversation->document_id, $answer, $found);
            $message = $conversation->messages()->create([
                'role' => 'assistant',
                ...$parsed,
                'provider' => NvidiaEmbeddingService::PROVIDER,
                'model' => $this->regulationChat->chatModel(),
                'elapsed_ms' => intdiv(hrtime(true) - $startedAt, 1_000_000),
            ]);
            yield $this->event('done', [
                'created_at' => $message->created_at->toJSON(),
                'elapsed_ms' => $message->elapsed_ms,
                'calculation' => $parsed['calculation'],
                'citations' => $parsed['citations'],
                'clarifications' => $parsed['clarifications'],
            ]);
        }, endStreamWith: null);
    }

    // 문자열 데이터도 JSON으로 인코딩하여 줄바꿈이 SSE 이벤트 구분자로 해석되지 않도록 처리
    private function event(string $name, mixed $data): StreamedEvent
    {
        return new StreamedEvent($name, json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
