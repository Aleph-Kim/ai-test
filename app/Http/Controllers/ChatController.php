<?php

namespace App\Http\Controllers;

use App\Models\Chunk;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Embedding;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\StreamedEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Streaming\Events\Error as StreamError;
use Laravel\Ai\Streaming\Events\TextDelta;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

use function Laravel\Ai\agent;

class ChatController extends Controller
{
    private const HISTORY_LIMIT = 10;

    // 스트리밍 요청 전체에 걸리는 제한이므로 긴 답변도 끊기지 않도록 SDK 기본값(60초)보다 길게 설정
    private const STREAM_TIMEOUT = 120;

    private const INSTRUCTIONS = <<<'TXT'
        당신은 사내 규정을 직원에게 설명하는 도우미입니다.
        - 아래 [규정 조항]에 있는 내용만 근거로 한국어로 쉽게 설명합니다.
        - 근거가 없으면 "해당 규정에서 찾을 수 없습니다"라고 답하고 추측하지 않습니다.
        - 답변에서 조항을 언급할 때는 조항 라벨을 그대로 씁니다(예: 제12조(연차휴가)).
        TXT;

    public function conversations(Request $request, Document $document): JsonResponse
    {
        return response()->json(
            $document->conversations()
                ->where('client_id', $this->clientId($request))
                ->latest()
                ->get(['id', 'title', 'created_at'])
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

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversation($request, $conversation);

        return response()->json(
            $conversation->messages()->orderBy('id')->get(['role', 'content', 'citations', 'provider', 'model'])
        );
    }

    public function ask(Request $request, Conversation $conversation): StreamedResponse
    {
        $this->authorizeConversation($request, $conversation);
        $question = $request->validate(['question' => ['required', 'string', 'max:2000']])['question'];

        $history = $conversation->messages()->latest('id')->limit(self::HISTORY_LIMIT)->get()->reverse()
            ->map(fn (Message $m) => $m->role === 'user' ? new UserMessage($m->content) : new AssistantMessage($m->content))
            ->values()
            ->all();

        // 스트림 중 오류가 나도 질문은 남도록 먼저 저장
        $conversation->messages()->create(['role' => 'user', 'content' => $question]);

        return response()->eventStream(function () use ($conversation, $question, $history) {
            // 웹 요청 기본 실행 제한(30초)에 걸리면 오류 이벤트 없이 강제 종료되므로 해제 (대기 한도는 STREAM_TIMEOUT)
            set_time_limit(0);

            try {
                $found = $this->search($conversation->document_id, $question);
                $citations = $found->where('score', '>=', config('ai.rag.min_similarity'))->values()->all();
                yield $this->event('citations', $citations);

                $clauses = $found->map(fn (array $c) => "### {$c['label']}\n{$c['text']}")->join("\n\n");
                $stream = agent(self::INSTRUCTIONS."\n\n[규정 조항]\n".$clauses, $history)->stream($question, timeout: self::STREAM_TIMEOUT);

                $answer = '';
                foreach ($stream as $event) {
                    if ($event instanceof StreamError) {
                        throw new RuntimeException($event->message);
                    }
                    if ($event instanceof TextDelta) {
                        $answer .= $event->delta;
                        yield $this->event('delta', $event->delta);
                    }
                }
            } catch (Throwable $e) {
                yield $this->event('error', $this->aiFailure($e, '답변 생성', ['conversation_id' => $conversation->id]));

                return;
            }

            $conversation->messages()->create([
                'role' => 'assistant',
                'content' => $answer,
                'citations' => $citations,
                'provider' => config('ai.default'),
                'model' => config('ai.providers.'.config('ai.default').'.models.text.default'),
            ]);
            yield $this->event('done', true);
        }, endStreamWith: null);
    }

    /**
     * 질문과 가까운 조항 상위 N개 (score는 코사인 유사도)
     *
     * @return Collection<int, array{chunk_id: int, label: string, text: string, score: float}>
     */
    private function search(int $documentId, string $question): Collection
    {
        $provider = Embedding::provider();
        $model = Embedding::modelName();

        // 공급자·모델 변경 후 아직 벡터가 없는 조항만 현재 모델로 임베딩 (다른 모델 벡터와의 비교는 무의미)
        $missing = Chunk::where('document_id', $documentId)
            ->whereDoesntHave('embeddings', fn ($q) => $q->where('provider', $provider)->where('model', $model))
            ->get();
        if ($missing->isNotEmpty()) {
            $vectors = Embedding::generate($missing->pluck('text')->all(), 'passage');
            foreach ($missing->values() as $i => $chunk) {
                $chunk->embeddings()->create(['provider' => $provider, 'model' => $model, 'vector' => $vectors[$i]]);
            }
        }

        $query = Embedding::generate([$question], 'query')[0];

        return DB::table('embeddings')
            ->join('chunks', 'chunks.id', '=', 'embeddings.chunk_id')
            ->where('chunks.document_id', $documentId)
            ->where('embeddings.provider', $provider)
            ->where('embeddings.model', $model)
            ->select('chunks.id as chunk_id', 'chunks.label', 'chunks.text')
            ->selectVectorDistance('embeddings.vector', $query, 'distance')
            ->orderByVectorDistance('embeddings.vector', $query)
            ->limit(config('ai.rag.top_k'))
            ->get()
            ->map(fn (object $row) => [
                'chunk_id' => $row->chunk_id,
                'label' => $row->label,
                'text' => $row->text,
                'score' => round(1 - $row->distance, 4),
            ]);
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
