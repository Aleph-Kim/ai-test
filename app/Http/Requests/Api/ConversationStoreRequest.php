<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class ConversationStoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // 대화명 (첫 질문, 앞 30자만 저장)
            'title' => ['required', 'string'],
        ];
    }

    public function attributes(): array
    {
        return [
            'title' => '대화명',
        ];
    }
}
