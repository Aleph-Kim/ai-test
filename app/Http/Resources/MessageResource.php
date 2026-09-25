<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            // 역할 (user: 질문, assistant: 답변, error: 답변 실패 기록)
            'role' => $this->role->value,
            // 본문 (답변은 마크다운)
            'content' => $this->content,
            // 계산 과정 (계산이 필요한 답변만, 마크다운)
            'calculation' => $this->calculation,
            // 근거 조항 [{chunk_id, label, text, score}]
            'citations' => $this->citations ?? [],
            // 사전 질문 [{question, options}] (답변 전 개인 상황 확인)
            'clarifications' => $this->clarifications ?? [],
            // 답변 생성 모델
            'model' => $this->model,
            // 답변 소요 시간 (밀리초)
            'elapsed_ms' => $this->elapsed_ms,
            // 생성일
            'created_at' => $this->created_at,
        ];
    }
}
