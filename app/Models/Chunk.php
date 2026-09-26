<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['seq', 'label', 'text'])]
class Chunk extends Model
{
    // 줄 맨 앞의 "제N조", "제N조의M", 뒤따르는 괄호 제목까지 (본문 중 "제3조에 따라" 같은 인용은 제외)
    private const ARTICLE = '/^[ \t]*제\s*\d+\s*조(?:의\s*\d+)?(?:\s*\([^)\n]*\))?(?=\s|$)/mu';

    // 조항 사이에 끼는 "제6장 임금", "제2절 휴가", "부칙" 같은 제목 줄 (본문 문장과 구분하기 위해 40자 이하로 한정)
    private const HEADING = '/^[ \t]*(?:제\s*\d+\s*(?:장|절|관)(?:[ \t]+[^\n]{0,40})?|부[ \t]*칙(?:[ \t]*\([^)\n]*\))?)[ \t]*$/mu';

    private const PARAGRAPH_LIMIT = 1000;

    public $timestamps = false;

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function embeddings(): HasMany
    {
        return $this->hasMany(Embedding::class);
    }

    /**
     * 규칙 문서를 조항 단위로 분할 (조항 표기가 없으면 1,000자 이하 문단 묶음)
     *
     * @return list<array{label: string, text: string}>
     */
    public static function split(string $text): array
    {
        preg_match_all(self::ARTICLE, $text, $matches, PREG_OFFSET_CAPTURE);
        $matches = $matches[0];

        if ($matches === []) {
            return self::splitParagraphs($text);
        }

        $chunks = [];
        $preface = self::removeHeadings(substr($text, 0, $matches[0][1]));
        if ($preface !== '') {
            $chunks[] = ['label' => '서문', 'text' => $preface];
        }

        foreach ($matches as $i => [$label, $offset]) {
            $end = $matches[$i + 1][1] ?? strlen($text);
            $chunks[] = ['label' => trim($label), 'text' => self::removeHeadings(substr($text, $offset, $end - $offset))];
        }

        return $chunks;
    }

    /**
     * 임베딩할 텍스트 목록: [조항 전체, 항·호별 텍스트...] (항목이 하나뿐이면 조항 전체만)
     * 14개 항목이 묶인 복무의무 조항처럼 긴 조항은 전체 벡터가 흐려져 항목 하나만 묻는 질문으로 찾기 어려움
     *
     * @return list<string>
     */
    public static function passages(string $label, string $text): array
    {
        // ①②… 앞과 줄 맨 앞 "1." 앞에서 나누고, 조항 머리말(첫 조각)은 전체 벡터에 이미 담겨 제외
        $items = array_slice(preg_split('/(?=[\x{2460}-\x{2473}])|(?=^[ \t]*\d+\.\s)/mu', $text), 1);
        $items = array_values(array_filter(array_map('trim', $items), fn (string $item) => mb_strlen($item) >= 5));

        // 항목만으로는 어느 조항인지 알 수 없어 조항 라벨을 앞에 붙임
        return count($items) < 2 ? [$text] : [$text, ...array_map(fn (string $item) => "{$label} {$item}", $items)];
    }

    /**
     * 조항 본문에 딸려 온 장·절·부칙 제목 줄 제거
     */
    public static function removeHeadings(string $text): string
    {
        $text = preg_replace(self::HEADING, '', $text);

        return trim(preg_replace('/\n[ \t]*(?:\n[ \t]*){2,}/u', "\n\n", $text));
    }

    private static function splitParagraphs(string $text): array
    {
        $groups = [];
        $buffer = '';

        foreach (preg_split('/\n\s*\n/u', $text) as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            if ($buffer !== '' && mb_strlen($buffer) + mb_strlen($paragraph) + 2 > self::PARAGRAPH_LIMIT) {
                $groups[] = $buffer;
                $buffer = $paragraph;
            } else {
                $buffer = $buffer === '' ? $paragraph : "{$buffer}\n\n{$paragraph}";
            }
        }

        if ($buffer !== '') {
            $groups[] = $buffer;
        }

        return array_map(
            fn (string $group, int $i) => ['label' => '문단 '.($i + 1), 'text' => $group],
            $groups,
            array_keys($groups),
        );
    }
}
