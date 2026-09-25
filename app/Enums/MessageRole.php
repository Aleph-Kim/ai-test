<?php

namespace App\Enums;

enum MessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
    // 답변 생성 실패 기록 (화면에만 표시, 모델 대화 기록에서는 제외)
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::User => '질문',
            self::Assistant => '답변',
            self::Error => '오류',
        };
    }
}
