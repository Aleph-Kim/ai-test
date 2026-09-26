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

    // 무료 API가 가끔 응답 도중 멈추므로, 이 시간 동안 아무것도 오지 않으면 멈춘 것으로 보고 끊음
    private const STALL_TIMEOUT = 45;

    // 첫 글자 전에 멈추거나 끊기면 새 요청은 대부분 바로 응답하므로 한 번 더 시도
    private const ATTEMPTS = 2;

    public function model(): string
    {
        return config('services.nvidia.chat_model');
    }

    /**
     * OpenAI 호환 chat/completions 스트리밍 호출 후 답변 텍스트 조각을 도착 순서대로 반환
     * 답변 첫 조각을 보내기 전에 멈추거나 끊기면 재요청 (이미 보낸 뒤에는 화면에 중복되므로 재요청하지 않음)
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return Generator<int, string>
     */
    public function stream(array $messages): Generator
    {
        for ($attempt = 1; ; $attempt++) {
            $started = false;
            try {
                foreach ($this->request($messages) as $delta) {
                    $started = true;
                    yield $delta;
                }

                return;
            } catch (Throwable $e) {
                // 요청 내용이 잘못된 4xx는 다시 보내도 같은 결과
                $clientError = $e instanceof RequestException && $e->response->clientError();
                if ($started || $clientError || $attempt >= self::ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return Generator<int, string>
     */
    private function request(array $messages): Generator
    {
        $response = Http::withToken(config('services.nvidia.api_key'))
            ->timeout(self::STREAM_TIMEOUT)
            ->connectTimeout(5)
            // 무료 API는 분당 요청 한도(429)에 자주 걸리며 잠시 뒤 다시 보내면 대부분 성공
            ->retry(3, 2000, fn (Throwable $e) => $e instanceof RequestException && $e->response->status() === 429)
            // read_timeout은 첫 응답 대기와 조각 사이 대기 모두에 적용됨 (지정하지 않으면 60초)
            ->withOptions(['stream' => true, 'read_timeout' => self::STALL_TIMEOUT])
            ->post(config('services.nvidia.base_url').'/chat/completions', [
                'model' => $this->model(),
                'messages' => $messages,
                'stream' => true,
                'chat_template_kwargs' => ['thinking' => config('services.nvidia.chat_thinking')],
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
