<?php

namespace App\Http\Controllers\Web;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ChatPageController extends Controller
{
    private const CLIENT_ID_MINUTES = 60 * 24 * 365; // 1년

    /**
     * 채팅 화면 (로그인 없이 대화 목록을 브라우저별로 구분하는 식별자 쿠키 발급)
     */
    public function __invoke(Request $request): View
    {
        $raw = $request->cookie('client_id');
        $clientId = $this->legacyClientId($raw) ?? (Str::isUuid((string) $raw) ? $raw : (string) Str::uuid());

        if ($clientId !== $raw) {
            Cookie::queue('client_id', $clientId, self::CLIENT_ID_MINUTES);
        }

        return view('chat', [
            'provider' => 'nvidia',
            'chatModel' => config('services.nvidia.chat_model'),
        ]);
    }

    /**
     * 쿠키 암호화 제외(API 라우트에서 읽기 위함) 전에 암호화된 채로 발급된 식별자 복원 (기존 대화 목록 유지)
     */
    private function legacyClientId(?string $raw): ?string
    {
        if ($raw === null || Str::isUuid($raw)) {
            return null;
        }

        try {
            $value = CookieValuePrefix::validate('client_id', Crypt::decryptString($raw), Crypt::getAllKeys());
        } catch (DecryptException) {
            return null;
        }

        return Str::isUuid((string) $value) ? $value : null;
    }
}
