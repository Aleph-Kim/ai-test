<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class NvidiaEmbeddingService
{
    public const PROVIDER = 'nvidia';

    // 질문과 조항을 구분해 보내지 않으면 검색 정확도가 크게 떨어짐
    private const INPUT_TYPE_QUERY = 'query';
    private const INPUT_TYPE_PASSAGE = 'passage';
    private const CHUNK_SIZE = 32;

    public function model(): string
    {
        return config('services.nvidia.embedding_model');
    }

    public function embedQuery(string $text): array
    {
        return $this->embed([$text], self::INPUT_TYPE_QUERY)[0];
    }

    /**
     * 조항 본문 목록을 passage 임베딩 벡터 목록으로 변환 (입력 순서 유지)
     */
    public function embedPassages(array $texts): array
    {
        return $this->embed($texts, self::INPUT_TYPE_PASSAGE);
    }

    /**
     * NVIDIA NIM API 배치 호출 및 인덱스 순서 복원
     */
    private function embed(array $texts, string $inputType): array
    {
        if (empty($texts)) {
            return [];
        }

        $expectedDimensions = (int) config('services.nvidia.embedding_dimensions');
        $results = [];

        foreach (array_chunk($texts, self::CHUNK_SIZE) as $chunk) {
            $response = Http::withToken(config('services.nvidia.api_key'))
                ->timeout(30)
                ->connectTimeout(5)
                ->retry(3, 1000)
                ->post(config('services.nvidia.base_url').'/embeddings', [
                    'input' => array_values($chunk),
                    'model' => $this->model(),
                    'input_type' => $inputType,
                    'encoding_format' => 'float',
                    'truncate' => 'END',
                ])
                ->throw()
                ->json();

            $data = $response['data'] ?? [];

            // API 응답 순서가 보장되지 않을 수 있으므로 index 기준 재정렬
            usort($data, fn (array $a, array $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

            foreach ($data as $item) {
                $embedding = $item['embedding'] ?? [];

                if (count($embedding) !== $expectedDimensions) {
                    throw new RuntimeException("임베딩 차원 불일치: 기대값 {$expectedDimensions}, 실제값 ".count($embedding));
                }

                $results[] = $embedding;
            }
        }

        return $results;
    }
}
