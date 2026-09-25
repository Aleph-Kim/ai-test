<?php

namespace App\Http\Controllers;

use App\Models\Chunk;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Embedding;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

    // 모델이 답변 끝에 붙이는 사전 질문 블록의 시작 표시 (화면에는 질문 카드로만 노출)
    private const CLARIFY_MARKER = '[[사전질문]]';

    private const MAX_CLARIFICATIONS = 3;

    private const INSTRUCTIONS = <<<'TXT'
        당신은 사내 규정을 직원에게 설명하는 도우미입니다.
        - 아래 [규정 조항]에 있는 내용만 근거로 한국어로 쉽게 설명합니다.
        - 근거가 없으면 "해당 규정에서 찾을 수 없습니다"라고 답하고 추측하지 않습니다.
        - 답변에서 조항을 언급할 때는 조항 라벨을 그대로 씁니다(예: 제12조(연차휴가)).
        - 마크다운(목록, 굵게, 표)을 사용해 읽기 쉽게 정리합니다.
        - 근속 기간, 퇴직 사유, 고용 형태 등 직원 개인 상황에 따라 결론이 달라지는데 대화에 그 정보가 없으면,
          규정을 설명하거나 경우를 나열하지 말고 결론에 꼭 필요한 정보만 1~3개 묶어서 먼저 물어봅니다.
          이때 본문은 "정확히 안내해 드리려면 몇 가지 확인이 필요해요." 한 문장만 쓰고, 맨 끝에 아래 형식으로 질문을 씁니다.
          선택지가 정해진 질문은 "|"로 선택지를 이어 씁니다.
          [[사전질문]]
          - 첫 출근일이 언제이신가요?
          - 퇴직 사유가 무엇인가요? | 자발적 퇴사 | 권고사직 | 계약 만료
        - 대화에서 이미 받은 정보는 다시 묻지 않습니다. 필요한 정보가 모이면 사전 질문 없이 직원 상황에 맞는 결론을 먼저 말하고 근거 조항을 설명합니다.
        - 질문이 이미 구체적이면 되묻지 않고 바로 답합니다.
        - "어제", "작년 3월" 같은 날짜 표현은 아래 오늘 날짜를 기준으로 계산합니다.
        TXT;

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
            $conversation->messages()->orderBy('id')->get(['role', 'content', 'citations', 'clarifications', 'provider', 'model', 'elapsed_ms', 'created_at'])
        );
    }

    public function ask(Request $request, Conversation $conversation): StreamedResponse
    {
        $startedAt = hrtime(true);
        $this->authorizeConversation($request, $conversation);
        $question = $request->validate(['question' => ['required', 'string', 'max:2000']])['question'];

        $recent = $conversation->messages()->latest('id')->limit(self::HISTORY_LIMIT)->get();
        $history = $recent->reverse()
            ->map(fn (Message $m) => $m->role === 'user' ? new UserMessage($m->content) : new AssistantMessage($this->historyContent($m)))
            ->values()
            ->all();
        $searchQuery = $this->searchQuery($recent, $question);

        // 스트림 중 오류가 나도 질문은 남도록 먼저 저장
        $conversation->messages()->create(['role' => 'user', 'content' => $question]);

        return response()->eventStream(function () use ($conversation, $question, $searchQuery, $history, $startedAt) {
            // 웹 요청 기본 실행 제한(30초)에 걸리면 오류 이벤트 없이 강제 종료되므로 해제 (대기 한도는 STREAM_TIMEOUT)
            set_time_limit(0);

            try {
                $found = $this->search($conversation->document_id, $searchQuery);
                $citations = $found->where('score', '>=', config('ai.rag.min_similarity'))->values()->all();

                $clauses = $found->map(fn (array $c) => "### {$c['label']}\n{$c['text']}")->join("\n\n");
                $today = now()->locale('ko')->isoFormat('YYYY년 M월 D일 dddd');
                $instructions = self::INSTRUCTIONS."\n\n[오늘 날짜]\n{$today}\n\n[규정 조항]\n".$clauses;
                $stream = agent($instructions, $history)->stream($question, timeout: self::STREAM_TIMEOUT);

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

            // 되묻는 차례에는 결론이 없으므로 근거 조항 미표시
            [$content, $clarifications] = $this->splitClarifications($answer);
            $citations = $clarifications === [] ? $citations : [];
            $message = $conversation->messages()->create([
                'role' => 'assistant',
                'content' => $content,
                'citations' => $citations,
                'clarifications' => $clarifications,
                'provider' => config('ai.default'),
                'model' => config('ai.providers.'.config('ai.default').'.models.text.default'),
                'elapsed_ms' => intdiv(hrtime(true) - $startedAt, 1_000_000),
            ]);
            yield $this->event('done', [
                'created_at' => $message->created_at->toJSON(),
                'elapsed_ms' => $message->elapsed_ms,
                'citations' => $citations,
                'clarifications' => $clarifications,
            ]);
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

    /**
     * 사전 질문에 대한 답변이면 원래 질문까지 이어 붙인 검색어 ("어제" 같은 답만으로는 관련 조항 검색 불가)
     *
     * @param  Collection<int, Message>  $recent  최신순 메시지
     */
    private function searchQuery(Collection $recent, string $question): string
    {
        $parts = [$question];
        foreach ($recent as $message) {
            if ($message->role === 'assistant') {
                if (empty($message->clarifications)) {
                    break;
                }

                continue;
            }
            array_unshift($parts, $message->content);
        }

        return implode("\n", $parts);
    }

    // 사전 질문 목록은 본문과 따로 저장되므로 모델이 이전에 무엇을 물었는지 알 수 있게 대화 기록에 다시 포함
    private function historyContent(Message $message): string
    {
        if (empty($message->clarifications)) {
            return $message->content;
        }

        $questions = collect($message->clarifications)->map(fn (array $c) => '- '.$c['question'])->join("\n");

        return "{$message->content}\n{$questions}";
    }

    /**
     * 답변 본문과 끝에 붙은 사전 질문 목록 분리 ("질문 | 선택지1 | 선택지2" 형식은 선택지 포함)
     *
     * @return array{0: string, 1: list<array{question: string, options: list<string>}>}
     */
    private function splitClarifications(string $answer): array
    {
        $position = mb_strpos($answer, self::CLARIFY_MARKER);
        if ($position === false) {
            return [trim($answer), []];
        }

        $block = mb_substr($answer, $position + mb_strlen(self::CLARIFY_MARKER));
        $clarifications = collect(preg_split('/\R/u', $block))
            ->map(fn (string $line) => trim(preg_replace('/^\s*[-*•]\s*/u', '', $line)))
            ->filter()
            ->take(self::MAX_CLARIFICATIONS)
            ->map(function (string $line) {
                $parts = array_values(array_filter(array_map('trim', explode('|', $line)), fn ($p) => $p !== ''));

                return ['question' => $parts[0], 'options' => array_slice($parts, 1)];
            })
            ->values()
            ->all();

        return [trim(mb_substr($answer, 0, $position)), $clarifications];
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
