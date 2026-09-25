<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\AsVector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Ai\Embeddings;
use RuntimeException;

#[Fillable(['chunk_id', 'provider', 'model', 'vector'])]
class Embedding extends Model
{
    private const BATCH_SIZE = 32;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'vector' => AsVector::class,
        ];
    }

    public function chunk(): BelongsTo
    {
        return $this->belongsTo(Chunk::class);
    }

    public static function provider(): string
    {
        return config('ai.default_for_embeddings');
    }

    public static function modelName(): string
    {
        return config('ai.providers.'.self::provider().'.models.embeddings.default');
    }

    /**
     * 활성 공급자로 텍스트 임베딩 생성 (kind: query는 질문, passage는 조항)
     * 질문과 조항을 구분해 보내지 않으면 검색 정확도가 크게 떨어지는 공급자별 옵션 포함
     *
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public static function generate(array $texts, string $kind): array
    {
        $options = match (self::provider()) {
            'gemini' => ['taskType' => $kind === 'query' ? 'RETRIEVAL_QUERY' : 'RETRIEVAL_DOCUMENT'],
            'nvidia' => ['input_type' => $kind],
            default => [],
        };

        $vectors = [];
        foreach (array_chunk($texts, self::BATCH_SIZE) as $batch) {
            $vectors = [...$vectors, ...Embeddings::for($batch)->withProviderOptions($options)->generate()->embeddings];
        }

        $dimensions = config('ai.rag.dimensions');
        if ($vectors !== [] && count($vectors[0]) !== $dimensions) {
            throw new RuntimeException('임베딩 차원('.count($vectors[0]).')이 EMBEDDING_DIMENSIONS('.$dimensions.')와 다릅니다');
        }

        return $vectors;
    }
}
