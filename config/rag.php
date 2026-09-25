<?php

return [

    // 질문과 함께 모델에 넘길 조항 수
    'top_k' => (int) env('RAG_TOP_K', 5),

    // 답변에 조항 언급이 없을 때 근거 조항으로 표시할 최소 코사인 유사도
    'min_similarity' => (float) env('RAG_MIN_SIMILARITY', 0.3),

];
