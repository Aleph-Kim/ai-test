<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class DocumentUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // 문서명
            'title' => ['required', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'title' => '문서명',
        ];
    }
}
