<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('embeddings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chunk_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('model');
            // 공급자·모델별 벡터를 한 컬럼에 저장하므로 모든 임베딩 모델이 같은 차원이어야 함
            $table->vector('vector', dimensions: config('services.nvidia.embedding_dimensions'));
            $table->unique(['chunk_id', 'provider', 'model']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('embeddings');
    }
};
