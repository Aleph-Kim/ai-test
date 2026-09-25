<?php

namespace Tests\Unit;

use App\Models\Chunk;
use PHPUnit\Framework\TestCase;

class ChunkSplitTest extends TestCase
{
    public function test_articles_with_preface_and_titles(): void
    {
        $text = "취업규칙\n\n제1조(목적) 이 규칙은 근로조건을 정한다.\n제2조(적용범위) 이 규칙은 모든 직원에게 적용한다.\n제1조에 따른 목적은 다음과 같다.\n제3조 휴가는 연 15일로 한다.\n";

        $chunks = Chunk::split($text);

        $this->assertSame(['서문', '제1조(목적)', '제2조(적용범위)', '제3조'], array_column($chunks, 'label'));
        $this->assertSame('취업규칙', $chunks[0]['text']);
        // 줄 맨 앞이라도 "제1조에 따른" 인용은 새 조항으로 나누지 않음
        $this->assertStringContainsString('제1조에 따른 목적', $chunks[2]['text']);
    }

    public function test_article_with_sub_number(): void
    {
        $chunks = Chunk::split("제5조(근무시간) 1일 8시간\n제5조의2(유연근무) 신청 시 허용\n제6조(휴게) 1시간");

        $this->assertSame(['제5조(근무시간)', '제5조의2(유연근무)', '제6조(휴게)'], array_column($chunks, 'label'));
    }

    public function test_document_without_articles_uses_paragraphs(): void
    {
        $first = str_repeat('가', 600);
        $second = str_repeat('나', 600);
        $third = str_repeat('다', 100);

        $chunks = Chunk::split("{$first}\n\n{$second}\n\n{$third}");

        $this->assertSame([
            ['label' => '문단 1', 'text' => $first],
            ['label' => '문단 2', 'text' => "{$second}\n\n{$third}"],
        ], $chunks);
    }
}
