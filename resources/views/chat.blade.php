<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="color-scheme" content="light dark">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>규칙 설명 챗봇</title>
  {{-- 파일 수정 시각을 주소에 붙여 수정 후에도 브라우저가 예전 캐시를 쓰지 않도록 처리 --}}
  <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body>
  <a class="skip" href="#question">질문 입력으로 건너뛰기</a>

  <div class="app">
    <aside class="sidebar">
      <header class="sidebar-header">
        <h1>규칙 설명 챗봇</h1>
        <p class="caption">{{ $provider }} · {{ $chatModel }}</p>
      </header>

      <section class="group">
        <div class="group-head">
          <h2 class="group-title">문서</h2>
          <button id="open-upload" type="button" class="icon-button plus" aria-label="문서 올리기" aria-haspopup="dialog"></button>
        </div>
        <ul id="document-list" class="list"></ul>
      </section>

      <section id="conversation-section" class="group" hidden>
        <div class="group-head">
          <h2 class="group-title">대화</h2>
          <button id="new-conversation" type="button" class="icon-button plus" aria-label="새 대화"></button>
        </div>
        <ul id="conversation-list" class="list"></ul>
      </section>
    </aside>

    <main class="chat">
      <header class="chat-header">
        <h2 id="chat-title">문서를 선택하세요</h2>
      </header>

      <div id="chat-scroll" class="chat-scroll">
        <p id="chat-empty" class="empty">왼쪽에서 문서를 고른 뒤 질문하세요.</p>
        <div id="messages" class="messages"></div>
      </div>

      <form id="ask-form" class="composer" hidden>
        <label for="question" class="visually-hidden">질문</label>
        <div class="composer-field">
          <textarea id="question" name="question" rows="1" maxlength="2000" placeholder="규정에 대해 질문하기…"></textarea>
          <button id="ask-button" type="submit" class="send-button">보내기</button>
        </div>
        <p class="caption composer-hint">Enter로 보내기 · Ctrl/⌘ + Enter로 줄바꿈</p>
        <p id="ask-status" class="status" aria-live="polite"></p>
      </form>
    </main>
  </div>

  <dialog id="upload-dialog" class="modal" aria-labelledby="upload-dialog-title">
    <form id="upload-form">
      <h2 id="upload-dialog-title">문서 올리기</h2>
      <label for="upload-title">제목</label>
      <input id="upload-title" name="title" type="text" autocomplete="off" placeholder="비우면 파일명 사용">
      <label for="upload-file">규칙 파일 (.txt, .md, UTF-8)</label>
      <input id="upload-file" name="file" type="file" accept=".txt,.md">
      <p id="upload-status" class="status" aria-live="polite"></p>
      <div class="modal-actions">
        <button id="close-upload" type="button" class="plain-button">취소</button>
        <button type="submit" class="filled-button">올리기</button>
      </div>
    </form>
  </dialog>

  <script src="{{ asset('vendor/marked.umd.js') }}"></script>
  <script src="{{ asset('vendor/purify.min.js') }}"></script>
  <script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}"></script>
</body>
</html>
