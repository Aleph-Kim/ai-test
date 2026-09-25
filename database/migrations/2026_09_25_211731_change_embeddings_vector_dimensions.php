<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 차원이 다른 기존 벡터는 새 컬럼에 담을 수 없어 삭제 (질문 시 누락 조항 자동 재임베딩)
        DB::table('embeddings')->delete();

        Schema::table('embeddings', function (Blueprint $table) {
            $table->vector('vector', dimensions: config('services.nvidia.embedding_dimensions'))->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('embeddings')->delete();

        Schema::table('embeddings', function (Blueprint $table) {
            $table->vector('vector', dimensions: 1024)->change();
        });
    }
};
