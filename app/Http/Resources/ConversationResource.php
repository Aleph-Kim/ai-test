<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            // 고유 ID
            'id' => $this->id,
            // 대화명
            'title' => $this->title,
            // 생성일
            'created_at' => $this->created_at,
            // 마지막 채팅 시각 (메시지가 없으면 null)
            'last_message_at' => $this->last_message_at,
        ];
    }
}
