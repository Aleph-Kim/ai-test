<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DocumentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            // 고유 ID
            'id' => $this->id,
            // 문서명
            'title' => $this->title,
            // 원본 파일명
            'filename' => $this->filename,
            // 생성일
            'created_at' => $this->created_at,
        ];
    }
}
