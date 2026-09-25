<?php

namespace App\Http\Controllers\API;

use App\Models\Chunk;
use App\Models\Document;
use App\Services\NvidiaEmbeddingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        return $this->responseData(data: ['list' => Document::latest('id')->get(['id', 'title', 'filename', 'created_at'])]);
    }

    /**
     * 문서 업로드 (조항 분할·임베딩 후 저장)
     */
    public function store(Request $request, NvidiaEmbeddingService $embeddings): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'file' => ['required', 'file', 'extensions:txt,md', 'max:2048'],
        ], [
            'file.required' => '파일을 고르세요.',
            'file.extensions' => '.txt 또는 .md 파일만 올릴 수 있습니다.',
            'file.max' => '파일은 2MB 이하만 올릴 수 있습니다.',
        ]);

        $file = $validated['file'];
        // BOM이 붙은 UTF-8(메모장 저장 파일)도 허용
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $file->get());
        if (! mb_check_encoding($content, 'UTF-8')) {
            throw ValidationException::withMessages(['file' => 'UTF-8 파일만 올릴 수 있습니다.']);
        }

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
    public function update(Request $request, Document $document): JsonResponse
    {
        $validated = $request->validate(['title' => ['required', 'string', 'max:255']], [
            'title.required' => '문서명을 입력하세요.',
            'title.max' => '문서명은 255자 이하로 입력하세요.',
        ]);

        $document->update(['title' => trim($validated['title'])]);

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
