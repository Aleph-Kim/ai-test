<?php

namespace App\Services;

use App\Enums\MessageRole;
use App\Models\Chunk;
use App\Models\Conversation;
use App\Models\Message;
use Generator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RegulationChatService
{
    private const HISTORY_LIMIT = 10;

    // 모델이 답변 끝에 붙이는 사전 질문 블록의 시작 표시 (화면에는 질문 카드로만 노출)
    private const CLARIFY_MARKER = '[[사전질문]]';

    private const MAX_CLARIFICATIONS = 3;

    // 모델이 답변 맨 앞에 쓰는 계산 과정 블록 (본문과 분리해 별도 섹션으로 노출)
    private const CALC_START = '[[계산과정]]';

    private const CALC_END = '[[/계산과정]]';

    private const INSTRUCTIONS = <<<'TXT'
        당신은 사내 규정을 직원에게 설명하는 도우미입니다.
        - 아래 [규정 조항]에 있는 내용만 근거로 한국어로 쉽게 설명합니다.
        - 근거가 없으면 "해당 규정에서 찾을 수 없습니다"라고 답하고 추측하지 않습니다.
        - 답변에서 조항을 언급할 때는 조항 라벨을 그대로 씁니다(예: 제12조(연차휴가)).
        - 마크다운(목록, 굵게, 표)을 사용해 읽기 쉽게 정리합니다.
        - 먼저 질문의 종류를 판단합니다.
          (가) 규정 내용을 묻는 질문(기간·일수·조건·절차·기준, 관련 조항이 있는지 등)은 되묻지 않고 바로 결론 답변을 씁니다.
              직원마다 달라지는 부분은 경우를 짧게 나눠 설명합니다. (예: "정년이 몇 살이야?", "병가는 며칠이야?", "쌍둥이 출산휴가는?", "해고 기준이 뭐야?")
          (나) 직원 본인의 남은 연차처럼 개인 사실(입사일, 이미 사용한 일수 등)을 넣어야 숫자가 나오는 계산 질문인데
              그 사실이 질문과 대화에 없을 때만 사전 질문을 합니다. 필요한 사실이 질문에 이미 있으면 되묻지 않고 바로 계산합니다.
        - 사전 질문을 할 때는 본문에 "정확히 안내해 드리려면 몇 가지 확인이 필요해요." 한 문장만 쓰고, 맨 끝에 아래 형식으로
          결론에 꼭 필요한 정보만 1~3개 묻습니다. 선택지가 정해진 질문은 "|"로 선택지를 이어 씁니다.
          [[사전질문]]
          - 첫 출근일이 언제이신가요?
          - 퇴직 사유가 무엇인가요? | 자발적 퇴사 | 권고사직 | 계약 만료
        - 계산에 필요한 사실(이미 사용한 일수, 정확한 입사일, 출근율 등)을 추정해서 계산하거나
          "사용하신 일수를 제외하고 확인하시면 됩니다"처럼 직원에게 떠넘기지 않습니다.
        - 대화에서 이미 받은 정보는 다시 묻지 않습니다. 필요한 정보가 모이면 사전 질문 없이 바로 결론 답변을 씁니다.
        - "관련 조항이 있는지" 묻는 질문은 [규정 조항] 전체에서 해당 내용이 나오는 조항을 빠짐없이 찾아 모두 알려 줍니다.
        - "어제", "작년 3월" 같은 날짜 표현은 아래 오늘 날짜를 기준으로 계산합니다.
        - 일수·금액을 계산하기 전에 관련 조항의 모든 항(①②③…)을 끝까지 확인하고, 포함·차감·한도 규정을 빠짐없이 적용합니다.
          (예: "최초 1년은 1년 미만 기간에 생긴 휴가를 포함해 15일로 하고 이미 쓴 일수를 뺀다"는 항이 있으면 따로 더하지 않습니다)
        - 근속 기간처럼 날짜 계산이 필요하면 오늘 날짜 기준으로 먼저 계산하고, 그 시점에 해당하는 규정만 적용합니다.
          서로 다른 시점·조건에 적용되는 규정(예: 입사 1년 미만에 생기는 연차와 1년이 지나 생기는 연차)을 규정에 합산하라는 내용이 없으면 더하지 않습니다.

        [결론 답변 작성 방법]
        - 조항 문장을 그대로 옮기지 않습니다. 직원이 한 번 읽고 바로 이해하도록 쉬운 말과 해요체로 풀어서 설명합니다.
        - 법률·인사 용어는 괄호로 쉽게 풀어 줍니다. (예: 평균임금(최근 3개월 동안 받은 임금의 하루 평균))
        - 각 항목 끝에 그 내용의 근거 조항 라벨을 괄호로 붙입니다. (예: "1년간 쓰지 않은 연차는 사라져요 (제26조(연차휴가의 사용))")
          [규정 조항]에 없는 일반 상식이나 법령 내용은 쓰지 않습니다.
        - 일수·금액·기간을 계산해야 하는 답변은 결론보다 먼저, 답변 맨 앞에 [[계산과정]]으로 시작해 [[/계산과정]]으로 끝나는 블록을 씁니다.
          (먼저 계산해야 결론이 정확해짐. 이 블록은 화면에서 본문과 분리된 별도 섹션으로 보여 줌)
          블록 안에는 세 가지만 씁니다: 적용하는 조항과 항이 이 상황에 어떻게 적용되는지(포함·차감·한도), 오늘 날짜 기준 근속 기간 등 직원 정보를 대입한 식, 결과.
          결론과 설명은 블록이 끝난 뒤 본문에 씁니다. 아래 예시는 형식만 참고하고 내용은 실제 조항과 직원 상황으로 씁니다.
          (예시)
          [[계산과정]]
          1) 제25조(연차유급휴가) ③항: 최초 1년 연차는 ②항 휴가를 포함해 15일이고, 이미 쓴 일수는 15일에서 뺀다.
          2) 입사 2025년 1월 2일 → 오늘 기준 근속 1년 8개월
          3) 15일 - 사용 10일 = 5일
          [[/계산과정]]
          계산 도중 필요한 정보가 없거나 규정 해석이 둘 이상으로 갈리면, 결론 대신 사전 질문으로 묻습니다.
          계산 과정을 쓴 뒤 아래 1번 결론 문장은 반드시 계산 결과와 같은 값으로 씁니다.
        - 아래 순서로 씁니다. 해당 내용이 규정에 없는 항목은 생략합니다.
          1. 결론 문장: 판단의 전제와 직원 상황에 맞춘 결론 한 문장 (예: "입사 3년 차이시니 육아휴직은 최대 1년까지 쓸 수 있어요.")
             계산 과정 블록이 있으면 블록 바로 다음에, 없으면 답변 첫 문장으로 씁니다.
          2. **자세히 알려드릴게요**: 기간·금액·횟수·조건·예외를 직원 상황에 대입해 3~5개 항목으로 구체적으로 설명합니다.
             계산이 가능하면 직원 상황으로 직접 계산한 값을 보여 줍니다. (예: "2024년 3월 입사라면 올해 연차는 15일이에요.")
          3. **이렇게 하면 돼요**: 신청 방법, 제출 서류, 기한 등 직원이 해야 할 일
          4. **참고하세요**: 놓치기 쉬운 주의사항이나 예외
          5. 마지막 줄: "근거: " 뒤에 답변에서 사용한 조항 라벨을 빠짐없이 나열 (예: 근거: 제30조(육아휴직), 제31조(육아휴직 급여))
        TXT;

    public function __construct(
        private NvidiaChatService $chat,
        private NvidiaEmbeddingService $embeddings,
    ) {}

    public function chatModel(): string
    {
        return $this->chat->model();
    }

    /**
     * 새 질문을 저장하기 전의 대화 기록으로 모델 대화 기록·검색어·직전 사전 질문 정보를 구성
     *
     * @return array{history: list<array{role: string, content: string}>, search_query: string, answered: string}
     */
    public function context(Conversation $conversation, string $question): array
    {
        // 저장된 오류 메시지는 화면 기록용이므로 모델 대화 기록과 검색어에서 제외
        $recent = $conversation->messages()->whereIn('role', [MessageRole::User, MessageRole::Assistant])->latest('id')->limit(self::HISTORY_LIMIT)->get();

        return [
            'history' => $recent->reverse()
                ->map(fn (Message $m) => ['role' => $m->role->value, 'content' => $m->role === MessageRole::User ? $m->content : $this->historyContent($m)])
                ->values()
                ->all(),
            'search_query' => $this->searchQuery($recent, $question),
            'answered' => $this->answeredClarification($recent, $question),
        ];
    }

    /**
     * 검색한 조항과 대화 기록으로 프롬프트를 만들어 답변 텍스트 조각을 도착 순서대로 반환
     *
     * @param  Collection<int, array{chunk_id: int, label: string, text: string, score: float}>  $found
     * @return Generator<int, string>
     */
    public function stream(array $context, Collection $found, string $question): Generator
    {
        $clauses = $found->map(fn (array $c) => "### {$c['label']}\n{$c['text']}")->join("\n\n");
        $today = now()->locale('ko')->isoFormat('YYYY년 M월 D일 dddd');
        // 가벼운 모델이 오늘 날짜를 무시하고 학습 시점 날짜로 계산하는 경우가 있어 맨 앞과 조항 앞에 모두 명시
        $instructions = "오늘 날짜는 {$today}입니다.\n\n".self::INSTRUCTIONS.$context['answered']."\n\n[오늘 날짜]\n{$today}\n\n[규정 조항]\n".$clauses;

        yield from $this->chat->stream([
            ['role' => 'system', 'content' => $instructions],
            ...$context['history'],
            ['role' => 'user', 'content' => $question],
        ]);
    }

    /**
     * 완성된 답변을 본문·계산 과정·사전 질문·근거 조항으로 분리
     * 되묻는 차례에는 결론이 없으므로 근거 조항 미표시
     *
     * @param  Collection<int, array{chunk_id: int, label: string, text: string, score: float}>  $found
     * @return array{content: string, calculation: ?string, clarifications: list<array{question: string, options: list<string>}>, citations: list<array>}
     */
    public function parseAnswer(int $documentId, string $answer, Collection $found): array
    {
        [$calculation, $answer] = $this->splitCalculation($answer);
        [$content, $clarifications] = $this->splitClarifications($answer);

        return [
            'content' => $content,
            'calculation' => $calculation,
            'clarifications' => $clarifications,
            'citations' => $clarifications === [] ? $this->citations($documentId, $content, $found) : [],
        ];
    }

    /**
     * 질문과 가까운 조항 상위 N개 (score는 코사인 유사도)
     *
     * @return Collection<int, array{chunk_id: int, label: string, text: string, score: float}>
     */
    public function search(int $documentId, string $question): Collection
    {
        $provider = NvidiaEmbeddingService::PROVIDER;
        $model = $this->embeddings->model();

        // 공급자·모델 변경 후 아직 벡터가 없는 조항만 현재 모델로 임베딩 (다른 모델 벡터와의 비교는 무의미)
        $missing = Chunk::where('document_id', $documentId)
            ->whereDoesntHave('embeddings', fn ($q) => $q->where('provider', $provider)->where('model', $model))
            ->get();
        if ($missing->isNotEmpty()) {
            $vectors = $this->embeddings->embedPassages($missing->pluck('text')->all());
            foreach ($missing->values() as $i => $chunk) {
                $chunk->embeddings()->create(['provider' => $provider, 'model' => $model, 'vector' => $vectors[$i]]);
            }
        }

        $query = $this->embeddings->embedQuery($question);

        return DB::table('embeddings')
            ->join('chunks', 'chunks.id', '=', 'embeddings.chunk_id')
            ->where('chunks.document_id', $documentId)
            ->where('embeddings.provider', $provider)
            ->where('embeddings.model', $model)
            ->select('chunks.id as chunk_id', 'chunks.label', 'chunks.text')
            ->selectVectorDistance('embeddings.vector', $query, 'distance')
            ->orderByVectorDistance('embeddings.vector', $query)
            ->limit(config('rag.top_k'))
            ->get()
            ->map(fn (object $row) => [
                'chunk_id' => $row->chunk_id,
                'label' => $row->label,
                'text' => $row->text,
                'score' => round(1 - $row->distance, 4),
            ]);
    }

    /**
     * 답변 본문과 "근거:" 줄에서 언급한 조항을 모두 근거 조항으로 사용 (언급한 조항이 없을 때만 검색 점수 기준)
     * 검색 점수는 임베딩 모델마다 분포가 달라 기준값으로 거르면 누락되고, "근거:" 줄만 보면 본문에서 쓴 조항이 빠짐
     *
     * @param  Collection<int, array{chunk_id: int, label: string, text: string, score: float}>  $found
     */
    private function citations(int $documentId, string $content, Collection $found): array
    {
        // "제25조(연차유급휴가)"처럼 제목까지 쓰거나 "제25조"만 써도 같은 조항으로 찾도록 조 번호로 비교
        preg_match_all('/제\s*\d+\s*조(?:의\s*\d+)?/u', $content, $cited);
        $articles = array_values(array_unique(array_map(fn (string $a) => preg_replace('/\s+/u', '', $a), $cited[0])));
        if ($articles === []) {
            return $found->where('score', '>=', config('rag.min_similarity'))->values()->all();
        }
        $articleOf = fn (string $label) => preg_match('/^제\s*\d+\s*조(?:의\s*\d+)?/u', $label, $m) ? preg_replace('/\s+/u', '', $m[0]) : null;

        return Chunk::where('document_id', $documentId)->orderBy('seq')->get(['id', 'label', 'text'])
            ->filter(fn (Chunk $chunk) => in_array($articleOf($chunk->label), $articles, true))
            ->sortBy(fn (Chunk $chunk) => array_search($articleOf($chunk->label), $articles, true))
            ->map(fn (Chunk $chunk) => [
                'chunk_id' => $chunk->id,
                'label' => $chunk->label,
                'text' => $chunk->text,
                'score' => $found->firstWhere('chunk_id', $chunk->id)['score'] ?? null,
            ])
            ->values()
            ->all();
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
            if ($message->role === MessageRole::Assistant) {
                if (empty($message->clarifications)) {
                    break;
                }

                continue;
            }
            array_unshift($parts, $message->content);
        }

        return implode("\n", $parts);
    }

    // 사전 질문 목록은 본문과 따로 저장되므로 원래 출력 형식(표시 블록)으로 대화 기록에 다시 포함
    // (본문 목록으로 넣으면 모델이 직원 답변 뒤에도 그 답변을 그대로 복사해 같은 질문을 반복함)
    private function historyContent(Message $message): string
    {
        if (empty($message->clarifications)) {
            return $message->content;
        }

        return "{$message->content}\n\n".self::CLARIFY_MARKER."\n".$this->clarificationLines($message->clarifications);
    }

    private function clarificationLines(array $clarifications): string
    {
        return collect($clarifications)
            ->map(fn (array $c) => '- '.implode(' | ', [$c['question'], ...$c['options']]))
            ->join("\n");
    }

    /**
     * 직전 답변이 사전 질문이면 물은 질문과 직원 답변을 프롬프트에 명시 (같은 질문 반복 방지)
     *
     * @param  Collection<int, Message>  $recent  최신순 메시지
     */
    private function answeredClarification(Collection $recent, string $question): string
    {
        $last = $recent->first();
        if ($last?->role !== MessageRole::Assistant || empty($last->clarifications)) {
            return '';
        }

        return "\n\n[직전에 물은 사전 질문과 직원 답변]\n"
            .$this->clarificationLines($last->clarifications)
            ."\n직원 답변: {$question}\n"
            .'직원이 이미 답한 내용은 다시 묻지 않습니다. 이 답변으로 결론을 쓰고, 답변에 없는 정보가 꼭 필요할 때만 그 정보만 새로 묻습니다.';
    }

    /**
     * 답변 앞의 계산 과정 블록과 나머지 답변 분리 (블록이 없거나 닫히지 않았으면 계산 과정 없음)
     *
     * @return array{0: ?string, 1: string}
     */
    private function splitCalculation(string $answer): array
    {
        $start = mb_strpos($answer, self::CALC_START);
        $end = mb_strpos($answer, self::CALC_END);
        if ($start === false || $end === false || $end < $start) {
            return [null, str_replace([self::CALC_START, self::CALC_END], '', $answer)];
        }

        $calculation = trim(mb_substr($answer, $start + mb_strlen(self::CALC_START), $end - $start - mb_strlen(self::CALC_START)));
        $rest = mb_substr($answer, 0, $start).mb_substr($answer, $end + mb_strlen(self::CALC_END));

        return [$calculation !== '' ? $calculation : null, trim($rest)];
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

        // 표시 바로 뒤 빈 줄 전까지만 질문으로 보고, 모델이 그 뒤에 이어 쓴 설명은 본문으로 되돌림
        $lines = preg_split('/\R/u', ltrim(mb_substr($answer, $position + mb_strlen(self::CLARIFY_MARKER))));
        $questions = [];
        while ($lines !== [] && trim($lines[0]) !== '') {
            $questions[] = trim(preg_replace('/^\s*[-*•]\s*/u', '', array_shift($lines)));
        }
        $content = trim(mb_substr($answer, 0, $position)."\n\n".implode("\n", $lines));

        $clarifications = collect($questions)
            ->take(self::MAX_CLARIFICATIONS)
            ->map(function (string $line) {
                $parts = array_values(array_filter(array_map('trim', explode('|', $line)), fn ($p) => $p !== ''));

                return ['question' => $parts[0], 'options' => array_slice($parts, 1)];
            })
            ->values()
            ->all();

        return [$content, $clarifications];
    }
}
