<?php

return [

    // 문서 전체 글자 수가 이 값 이하면 검색 없이 모든 조항을 모델에 넘김 (검색 누락 방지, 임베딩 호출 생략)
    'full_context_chars' => (int) env('RAG_FULL_CONTEXT_CHARS', 40000),

    // 문서가 커서 검색할 때 질문과 함께 모델에 넘길 조항 수
    'top_k' => (int) env('RAG_TOP_K', 5),

    // 답변에 조항 언급이 없을 때 근거 조항으로 표시할 최소 코사인 유사도
    'min_similarity' => (float) env('RAG_MIN_SIMILARITY', 0.3),

];
