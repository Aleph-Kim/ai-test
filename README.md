# 규칙 설명 챗봇

사칙·취업규칙 같은 규칙 문서(.txt, .md)를 올리면 조항 단위로 나눠 임베딩하고, 직원 질문에 관련 조항을 찾아 상황에 맞게 설명하는 채팅 서비스입니다.

- PHP 8.3 / Laravel 13 / MariaDB 11.8 (벡터 검색)
- AI: NVIDIA NIM API (채팅 `google/gemma-4-31b-it`, 임베딩 `nvidia/nemotron-3-embed-1b`)

## DB 준비

MariaDB는 `../mariadb` 의 Docker Compose로 실행합니다. (포트 3307)

```sh
cd ../mariadb && docker compose up -d
```

## 설치

```sh
sh settings.sh
```

설치 후 `.env` 에 아래 값을 입력합니다.

| 항목 | 설명 |
| --- | --- |
| `DB_PASSWORD` | `../mariadb/docker-compose.yml` 의 `MARIADB_ROOT_PASSWORD` |
| `NVIDIA_NIM_API_KEY` | https://build.nvidia.com 에서 발급한 API 키 |

## 실행

```sh
php artisan serve --no-reload
```

답변 스트리밍 중에도 다른 요청을 처리하도록 `.env` 의 `PHP_CLI_SERVER_WORKERS` 개수만큼 워커를 띄웁니다. `--no-reload` 없이 실행하면 워커가 1개만 떠서, 답변을 기다리는 동안 다른 대화를 불러올 수 없습니다.

## 데이터 베이스 초기화

```sh
php artisan migrate:fresh
```

## 구조

| 경로 | 역할 |
| --- | --- |
| `routes/api.php` | 문서·대화·메시지 API (`/api/*`, 응답 형식 `{msg, data}`) |
| `app/Http/Controllers/API` | API 컨트롤러 (공통 응답·AI 오류 로그·브라우저 식별은 `Controller`) |
| `app/Http/Requests/Api` | 입력 검증 |
| `app/Http/Resources` | 응답 데이터 |
| `app/Services/NvidiaEmbeddingService` | 임베딩 API 호출 (질문·조항 구분) |
| `app/Services/NvidiaChatService` | 채팅 API 스트리밍 호출 |
| `app/Services/RegulationChatService` | 조항 검색, 프롬프트 구성, 답변의 계산 과정·사전 질문·근거 조항 분리 |
| `app/Models/Chunk` | 규칙 문서 조항 단위 분할 |

---

## 커밋 컨벤션

```
<type>: <subject>
```

### Type

| Type     | 설명                                  |
| -------- | ------------------------------------- |
| feat     | 새로운 기능 추가                      |
| fix      | 버그 수정                             |
| chore    | 새로운 기능, 버그 수정 외 간단한 수정 |
| design   | UI/UX 디자인 변경                     |
| docs     | 문서 수정                             |
| style    | 코드 포맷팅, 세미콜론 누락 등         |
| rename   | 파일/폴더명 수정                      |
| remove   | 파일 삭제                             |
| refactor | 코드 리팩토링                         |
| perf     | 성능 개선                             |
| ci       | CI/CD 관련 설정                       |
