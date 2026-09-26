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
        // 조항 전체 벡터만 있는 기존 데이터에는 항목별 벡터가 없어 삭제 (질문 시 누락 조항 자동 재임베딩)
        DB::table('embeddings')->delete();

        Schema::table('embeddings', function (Blueprint $table) {
            // 0: 조항 전체, 1부터: 조항 안의 항·호 (여러 항목이 묶인 조항에서 항목 하나만 묻는 질문도 찾기 위함)
            $table->unsignedSmallInteger('part')->default(0)->after('model');
            // chunk_id 외래키가 기존 유니크 인덱스를 쓰므로 새 인덱스를 먼저 만든 뒤 삭제
            $table->unique(['chunk_id', 'provider', 'model', 'part']);
            $table->dropUnique(['chunk_id', 'provider', 'model']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('embeddings')->where('part', '>', 0)->delete();

        Schema::table('embeddings', function (Blueprint $table) {
            $table->unique(['chunk_id', 'provider', 'model']);
            $table->dropUnique(['chunk_id', 'provider', 'model', 'part']);
            $table->dropColumn('part');
        });
    }
};
