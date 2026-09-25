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
        Schema::table('messages', function (Blueprint $table) {
            // 꼬리 질문 대신 답변 전 개인 상황 확인용 사전 질문 저장 ([{question, options}])
            $table->renameColumn('follow_ups', 'clarifications');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->renameColumn('clarifications', 'follow_ups');
        });
    }
};
