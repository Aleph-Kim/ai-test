<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class ConversationUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // 대화명
            'title' => ['required', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'title' => '대화명',
        ];
    }
}
