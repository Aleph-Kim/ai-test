<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DocumentStoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // 문서명 (비우면 파일명 사용)
            'title' => ['nullable', 'string', 'max:255'],
            // 규칙 파일 (.txt, .md, 2MB 이하, UTF-8)
            'file' => ['required', 'file', 'extensions:txt,md', 'max:2048'],
        ];
    }

    public function attributes(): array
    {
        return [
            'title' => '문서명',
            'file' => '규칙 파일',
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => '규칙 파일을 선택해 주세요.',
            'file.extensions' => '.txt 또는 .md 파일만 올릴 수 있습니다.',
            'file.max' => '규칙 파일은 2MB 이하만 올릴 수 있습니다.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->file('file') && ! mb_check_encoding($this->fileContent(), 'UTF-8')) {
                    $validator->errors()->add('file', 'UTF-8 파일만 올릴 수 있습니다.');
                }
            },
        ];
    }

    /**
     * 업로드 파일 본문 (BOM이 붙은 UTF-8(메모장 저장 파일)도 허용하도록 BOM 제거)
     */
    public function fileContent(): string
    {
        return preg_replace('/^\xEF\xBB\xBF/', '', $this->file('file')->get());
    }
}
