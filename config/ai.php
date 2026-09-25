<?php

return [

    'default' => env('LLM_PROVIDER', 'gemini'),

    'default_for_embeddings' => env('LLM_PROVIDER', 'gemini'),

    'caching' => [
        'embeddings' => [
            'cache' => false,
            'store' => env('CACHE_STORE', 'database'),
            'individually' => true,
        ],
    ],

    'providers' => [
        'gemini' => [
            'driver' => 'gemini',
            'key' => env('GEMINI_API_KEY'),
            'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/'),
            'models' => [
                'text' => ['default' => env('GEMINI_CHAT_MODEL', 'gemini-3.8-flash')],
                'embeddings' => [
                    'default' => env('GEMINI_EMBED_MODEL', 'gemini-embedding-001'),
                    'dimensions' => (int) env('EMBEDDING_DIMENSIONS', 2048),
                ],
            ],
        ],

        // NVIDIA는 OpenAI 호환 API이며, 임베딩 차원은 모델 고유값 사용 (nemotron-3-embed-1b는 2048 고정이라 dimensions 미전송)
        'nvidia' => [
            'driver' => 'openai-compatible',
            'url' => env('NVIDIA_URL', 'https://integrate.api.nvidia.com/v1'),
            'key' => env('NVIDIA_API_KEY'),
            'models' => [
                'text' => ['default' => env('NVIDIA_CHAT_MODEL')],
                'embeddings' => ['default' => env('NVIDIA_EMBED_MODEL')],
            ],
        ],
    ],

    // 조항 검색 설정 (앱 전용)
    'rag' => [
        'dimensions' => (int) env('EMBEDDING_DIMENSIONS', 2048),
        'min_similarity' => (float) env('MIN_SIMILARITY', 0.3),
        'top_k' => (int) env('TOP_K', 5),
    ],

];
