<?php

namespace App\Http\Controllers\API;

use App\Http\Requests\Api\DocumentStoreRequest;
use App\Http\Requests\Api\DocumentUpdateRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Chunk;
use App\Models\Document;
use App\Services\NvidiaEmbeddingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * @tags 규칙 문서
 */
class DocumentController extends Controller
{
    /**
     * 문서 목록 조회
     */
    public function index(): JsonResponse
    {
        return $this->responseData(data: ['list' => DocumentResource::collection(Document::latest('id')->get())]);
    }

    /**
     * 문서 업로드 (조항 분할·임베딩 후 저장)
     */
    public function store(DocumentStoreRequest $request, NvidiaEmbeddingService $embeddings): JsonResponse
    {
        $validated = $request->validated();
        $file = $validated['file'];
        $content = $request->fileContent();

        $chunks = Chunk::split($content);
        if ($chunks === []) {
            throw ValidationException::withMessages(['file' => '내용이 없는 파일입니다.']);
        }

        // 임베딩 성공 후에만 저장하여 실패 시 문서가 남지 않도록 처리
        try {
            $vectors = $embeddings->embedPassages(array_column($chunks, 'text'));
        } catch (Throwable $e) {
            $message = $this->aiFailure($e, '조항 임베딩', ['filename' => $file->getClientOriginalName()]);

            return $this->responseData(502, $message);
        }

        $document = DB::transaction(function () use ($validated, $file, $content, $chunks, $vectors, $embeddings) {
            $document = Document::create([
                'title' => $validated['title'] ?? $file->getClientOriginalName(),
                'filename' => $file->getClientOriginalName(),
                'content' => $content,
            ]);

            foreach ($chunks as $seq => $chunk) {
                $document->chunks()->create(['seq' => $seq, ...$chunk])->embeddings()->create([
                    'provider' => NvidiaEmbeddingService::PROVIDER,
                    'model' => $embeddings->model(),
                    'vector' => $vectors[$seq],
                ]);
            }

            return $document;
        });

        return $this->responseData(msg: '등록되었습니다.', data: ['id' => $document->id]);
    }

    /**
     * 문서명 수정
     */
    public function update(DocumentUpdateRequest $request, Document $document): JsonResponse
    {
        $document->update(['title' => trim($request->validated('title'))]);

        return $this->responseData(msg: '수정되었습니다.', data: ['title' => $document->title]);
    }

    /**
     * 문서 삭제 (조항·임베딩·대화 함께 삭제)
     */
    public function destroy(Document $document): JsonResponse
    {
        $document->delete();

        return $this->responseData(msg: '삭제되었습니다.');
    }
}
