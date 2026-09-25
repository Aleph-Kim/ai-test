<?php

namespace App\Http\Controllers\API;

use App\Http\Requests\Api\ConversationStoreRequest;
use App\Http\Requests\Api\ConversationUpdateRequest;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags 대화
 */
class ConversationController extends Controller
{
    /**
     * 문서별 내 대화 목록 조회 (마지막 채팅이 최근인 순)
     */
    public function index(Request $request, Document $document): JsonResponse
    {
        $list = $document->conversations()
            ->where('client_id', $this->clientId($request))
            ->select(['id', 'title', 'created_at'])
            ->withMax('messages as last_message_at', 'created_at')
            ->withCasts(['last_message_at' => 'datetime'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();

        return $this->responseData(data: ['list' => ConversationResource::collection($list)]);
    }

    /**
     * 대화 생성 (대화명은 첫 질문 앞 30자)
     */
    public function store(ConversationStoreRequest $request, Document $document): JsonResponse
    {
        $conversation = $document->conversations()->create([
            'client_id' => $this->clientId($request),
            'title' => mb_substr(trim($request->validated('title')), 0, 30),
        ]);

        return $this->responseData(msg: '등록되었습니다.', data: ['id' => $conversation->id]);
    }

    /**
     * 대화명 수정
     */
    public function update(ConversationUpdateRequest $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversation($request, $conversation);
        $conversation->update(['title' => trim($request->validated('title'))]);

        return $this->responseData(msg: '수정되었습니다.', data: ['title' => $conversation->title]);
    }

    /**
     * 대화 삭제 (메시지 함께 삭제)
     */
    public function destroy(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversation($request, $conversation);
        $conversation->delete();

        return $this->responseData(msg: '삭제되었습니다.');
    }
}
