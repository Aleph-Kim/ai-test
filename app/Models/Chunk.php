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
        $preface = trim(substr($text, 0, $matches[0][1]));
        if ($preface !== '') {
            $chunks[] = ['label' => '서문', 'text' => $preface];
        }

        foreach ($matches as $i => [$label, $offset]) {
            $end = $matches[$i + 1][1] ?? strlen($text);
            $chunks[] = ['label' => trim($label), 'text' => trim(substr($text, $offset, $end - $offset))];
        }

        return $chunks;
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
