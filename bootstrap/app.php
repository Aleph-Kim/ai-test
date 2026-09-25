<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API 라우트(쿠키 복호화 없음)에서도 대화 소유자 식별자를 읽을 수 있도록 암호화 제외
        $middleware->encryptCookies(except: ['client_id']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API 요청의 FormRequest 검증 실패 시 응답 포맷({msg, data}, 400)
        $exceptions->render(function (ValidationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json(['msg' => $e->validator->errors()->all()[0], 'data' => []], 400);
            }
        });

        // API 요청 라우트 모델 바인딩 실패 시 응답 포맷({msg, data}, 400)
        $exceptions->render(function (NotFoundHttpException $e, $request) {
            if ($request->is('api/*') && $e->getPrevious() instanceof ModelNotFoundException) {
                return response()->json(['msg' => '존재하지 않는 데이터입니다.', 'data' => []], 400);
            }
        });

        // API 요청에서 abort()로 중단한 경우 지정한 상태 코드와 메시지 유지
        $exceptions->render(function (HttpException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json(['msg' => $e->getMessage() ?: '요청을 처리할 수 없습니다.', 'data' => []], $e->getStatusCode());
            }
        });

        // API 요청에서 처리되지 않은 예외 발생 시 응답 포맷({msg, data}, 500)
        $exceptions->render(function (Throwable $e, $request) {
            if ($request->is('api/*')) {
                return response()->json(['msg' => '일시적인 오류가 발생하였습니다.', 'data' => []], 500);
            }
        });
    })->create();
