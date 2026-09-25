<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Document;
use App\Services\NvidiaEmbeddingService;
use App\Services\RegulationChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\StreamedEvent;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ChatController extends Controller
{
    public function __construct(private RegulationChatService $regulationChat) {}

    public function conversations(Request $request, Document $document): JsonResponse
    {
        return response()->json(
            $document->conversations()
                ->where('client_id', $this->clientId($request))
                ->select(['id', 'title', 'created_at'])
                ->withMax('messages as last_message_at', 'created_at')
                ->withCasts(['last_message_at' => 'datetime'])
                ->orderByDesc('last_message_at')
                ->orderByDesc('id')
                ->get()
        );
    }

    public function createConversation(Request $request, Document $document): JsonResponse
    {
        $validated = $request->validate(['title' => ['required', 'string']]);

        $conversation = $document->conversations()->create([
            'client_id' => $this->clientId($request),
            'title' => mb_substr(trim($validated['title']), 0, 30),
        ]);

        return response()->json(['id' => $conversation->id], 201);
    }

    public function renameConversation(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversation($request, $conversation);
        $validated = $request->validate(['title' => ['required', 'string', 'max:255']], [
            'title.required' => '대화명을 입력하세요.',
            'title.max' => '대화명은 255자 이하로 입력하세요.',
        ]);

        $conversation->update(['title' => trim($validated['title'])]);

        return response()->json(['title' => $conversation->title]);
    }

    public function destroyConversation(Request $request, Conversation $conversation): Response
    {
        $this->authorizeConversation($request, $conversation);
        $conversation->delete();

        return response()->noContent();
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversation($request, $conversation);

        return response()->json(
            $conversation->messages()->orderBy('id')->get(['role', 'content', 'calculation', 'citations', 'clarifications', 'provider', 'model', 'elapsed_ms', 'created_at'])
        );
    }

    public function ask(Request $request, Conversation $conversation): StreamedResponse
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

    private function clientId(Request $request): string
    {
        return $request->cookie('client_id') ?? abort(401, 'client_id 쿠키가 없습니다. 페이지를 새로고침하세요.');
    }

    private function authorizeConversation(Request $request, Conversation $conversation): void
    {
        abort_unless($conversation->client_id === $this->clientId($request), 404);
    }
}
