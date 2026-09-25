<?php

namespace App\Services;

use Generator;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

class NvidiaChatService
{
    // 스트리밍 요청 전체에 걸리는 제한이므로 긴 답변도 끊기지 않도록 넉넉하게 설정
    private const STREAM_TIMEOUT = 120;

    public function model(): string
    {
        return config('services.nvidia.chat_model');
    }

    /**
     * OpenAI 호환 chat/completions 스트리밍 호출 후 답변 텍스트 조각을 도착 순서대로 반환
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return Generator<int, string>
     */
    public function stream(array $messages): Generator
    {
        $response = Http::withToken(config('services.nvidia.api_key'))
            ->timeout(self::STREAM_TIMEOUT)
            ->connectTimeout(5)
            // 무료 API는 분당 요청 한도(429)에 자주 걸리며 잠시 뒤 다시 보내면 대부분 성공
            ->retry(3, 2000, fn (Throwable $e) => $e instanceof RequestException && $e->response->status() === 429)
            // 스트리밍 핸들러는 read_timeout이 없으면 첫 응답을 60초만 기다리고 끊음
            ->withOptions(['stream' => true, 'read_timeout' => self::STREAM_TIMEOUT])
            ->post(config('services.nvidia.base_url').'/chat/completions', [
                'model' => $this->model(),
                'messages' => $messages,
                'stream' => true,
            ])
            ->throw();

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';

        while (! $body->eof()) {
            $buffer .= $body->read(1024);

            // SSE 한 줄("data: {...}")이 여러 번에 나뉘어 도착할 수 있어 줄바꿈 단위로 처리
            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newline));
                $buffer = substr($buffer, $newline + 1);

                if (! str_starts_with($line, 'data:')) {
                    continue;
                }

                $data = trim(substr($line, 5));
                if ($data === '[DONE]') {
                    return;
                }

                $delta = json_decode($data, true)['choices'][0]['delta']['content'] ?? '';
                if ($delta !== '') {
                    yield $delta;
                }
            }
        }
    }
}
