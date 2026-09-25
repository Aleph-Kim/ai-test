const state = { documentId: null, conversationId: null, documents: [], lastDate: null };

const timeFormat = new Intl.DateTimeFormat("ko-KR", { hour: "numeric", minute: "2-digit" });
const dateFormat = new Intl.DateTimeFormat("ko-KR", { dateStyle: "full" });
const monthDayFormat = new Intl.DateTimeFormat("ko-KR", { month: "long", day: "numeric" });
const shortDateFormat = new Intl.DateTimeFormat("ko-KR", { year: "numeric", month: "numeric", day: "numeric" });

// 대화 목록용 시간: 오늘은 시각, 어제는 "어제", 올해는 월일, 그 이전은 연월일
function listTime(value) {
  const date = new Date(value);
  const now = new Date();
  const yesterday = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);
  let text;
  if (date.toDateString() === now.toDateString()) text = timeFormat.format(date);
  else if (date.toDateString() === yesterday.toDateString()) text = "어제";
  else if (date.getFullYear() === now.getFullYear()) text = monthDayFormat.format(date);
  else text = shortDateFormat.format(date);

  const el = document.createElement("time");
  el.className = "row-time";
  el.dateTime = date.toISOString();
  el.textContent = text;
  return el;
}

const $ = (id) => document.getElementById(id);

// Laravel이 오류를 JSON으로 돌려주도록 Accept 지정, POST·DELETE용 CSRF 토큰 포함
function headers(extra = {}) {
  return {
    Accept: "application/json",
    "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]').content,
    ...extra,
  };
}

async function api(url, options = {}) {
  const res = await fetch(url, { ...options, headers: headers(options.headers) });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.message || `요청 실패 (${res.status})`);
  return data;
}

function setStatus(el, text, isError = false) {
  el.textContent = text;
  el.classList.toggle("error", isError);
}

function listItem(label, current, onSelect, onRename) {
  const li = document.createElement("li");
  const button = document.createElement("button");
  button.type = "button";
  button.className = "row-button";
  button.textContent = label;
  button.setAttribute("aria-current", String(current));
  button.addEventListener("click", onSelect);
  li.append(button);

  if (onRename) {
    const edit = document.createElement("button");
    edit.type = "button";
    edit.className = "edit-button";
    edit.textContent = "✎";
    edit.setAttribute("aria-label", `${label} 이름 수정`);
    edit.addEventListener("click", () => startRename(li, label, onRename));
    li.append(edit);
  }
  return li;
}

// 목록 항목을 입력칸으로 바꿔 이름 수정 (Enter 저장, Esc·포커스 이탈 시 취소)
function startRename(li, label, onRename) {
  const original = [...li.childNodes];
  const input = document.createElement("input");
  input.type = "text";
  input.className = "rename-input";
  input.value = label;
  input.maxLength = 255;
  input.setAttribute("aria-label", "새 이름");
  li.replaceChildren(input);
  input.focus();
  input.select();

  let settled = false;
  const cancel = () => {
    if (settled) return;
    settled = true;
    li.replaceChildren(...original);
  };

  input.addEventListener("input", () => input.setCustomValidity(""));
  input.addEventListener("blur", cancel);
  input.addEventListener("keydown", async (e) => {
    if (e.isComposing) return;
    if (e.key === "Escape") cancel();
    if (e.key !== "Enter") return;
    e.preventDefault();
    const title = input.value.trim();
    if (!title || title === label) return cancel();

    settled = true;
    input.disabled = true;
    try {
      await onRename(title);
    } catch (err) {
      settled = false;
      input.disabled = false;
      input.focus();
      input.setCustomValidity(err.message);
      input.reportValidity();
    }
  });
}

async function renameConversation(id, title) {
  await api(`/api/conversations/${id}`, {
    method: "PATCH",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ title }),
  });
  await loadConversations();
}

async function loadDocuments() {
  const list = $("document-list");
  list.replaceChildren();
  const documents = await api("/api/documents");
  state.documents = documents;
  if (!documents.length) {
    const li = document.createElement("li");
    li.className = "empty-row";
    li.textContent = "올린 문서가 없습니다.";
    list.append(li);
  }
  for (const doc of documents) {
    const li = listItem(
      doc.title,
      doc.id === state.documentId,
      () => selectDocument(doc.id),
      (title) => renameDocument(doc.id, title),
    );
    li.append(deleteButton(doc.title, () => deleteDocument(doc)));
    list.append(li);
  }
}

function deleteButton(label, onDelete) {
  const button = document.createElement("button");
  button.type = "button";
  button.className = "delete-button";
  button.textContent = "삭제";
  button.setAttribute("aria-label", `${label} 삭제`);
  button.addEventListener("click", onDelete);
  return button;
}

async function renameDocument(id, title) {
  await api(`/api/documents/${id}`, {
    method: "PATCH",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ title }),
  });
  await loadDocuments();
  if (id === state.documentId) $("chat-title").textContent = title;
}

async function deleteDocument(doc) {
  if (!confirm(`"${doc.title}" 문서와 관련 대화를 모두 삭제할까요?`)) return;
  await api(`/api/documents/${doc.id}`, { method: "DELETE" });
  if (doc.id === state.documentId) {
    state.documentId = null;
    state.conversationId = null;
    $("conversation-section").hidden = true;
    $("ask-form").hidden = true;
    $("chat-empty").hidden = false;
    $("chat-title").textContent = "문서를 선택하세요";
    clearMessages();
  }
  await loadDocuments();
}

async function selectDocument(id) {
  state.documentId = id;
  state.conversationId = null;
  $("conversation-section").hidden = false;
  $("ask-form").hidden = false;
  $("chat-empty").hidden = true;
  clearMessages();
  await Promise.all([loadDocuments(), loadConversations()]);
  $("chat-title").textContent = state.documents.find((d) => d.id === id)?.title ?? "";
  $("question").focus();
}

async function loadConversations() {
  const list = $("conversation-list");
  list.replaceChildren();
  const conversations = await api(`/api/documents/${state.documentId}/conversations`);
  for (const conv of conversations) {
    const li = listItem(
      conv.title,
      conv.id === state.conversationId,
      () => selectConversation(conv.id),
      (title) => renameConversation(conv.id, title),
    );
    if (conv.last_message_at) li.querySelector(".row-button").after(listTime(conv.last_message_at));
    li.append(deleteButton(conv.title, () => deleteConversation(conv)));
    list.append(li);
  }
}

async function deleteConversation(conv) {
  if (!confirm(`"${conv.title}" 대화를 삭제할까요?`)) return;
  await api(`/api/conversations/${conv.id}`, { method: "DELETE" });
  if (conv.id === state.conversationId) {
    state.conversationId = null;
    clearMessages();
  }
  await loadConversations();
}

async function selectConversation(id) {
  state.conversationId = id;
  const messages = await api(`/api/conversations/${id}/messages`);
  clearMessages();
  for (const m of messages) {
    const view = renderMessage(m.role, m.content, m.created_at, m.elapsed_ms);
    if (m.role === "assistant") {
      view.setCitations(m.citations ?? []);
      view.setClarifications(m.clarifications ?? []);
    }
  }
  await loadConversations();
  scrollToBottom();
}

function clearMessages() {
  $("messages").replaceChildren();
  state.lastDate = null;
}

function timeElement(className, date, format) {
  const el = document.createElement("time");
  el.className = className;
  el.dateTime = date.toISOString();
  el.textContent = format.format(date);
  return el;
}

const formatSeconds = (ms) => `${Math.floor(ms / 1000)}초`;

const CLARIFY_MARKER = "[[사전질문]]";

// 스트리밍 중 사전 질문 블록(서버가 분리해 질문 카드로 전달)과 아직 덜 도착한 표시 조각은 본문에서 숨김
function visibleAnswer(raw) {
  const index = raw.indexOf(CLARIFY_MARKER);
  if (index >= 0) return raw.slice(0, index);
  for (let k = CLARIFY_MARKER.length - 1; k > 0; k--) {
    if (raw.endsWith(CLARIFY_MARKER.slice(0, k))) return raw.slice(0, -k);
  }
  return raw;
}

// 선택지 답을 입력칸에 "질문 선택지" 한 줄로 채움 (같은 질문을 다시 고르면 그 줄만 교체)
function fillAnswer(question, option) {
  const input = $("question");
  const lines = input.value.split("\n").filter((line) => line.trim() && !line.startsWith(`${question} `));
  lines.push(`${question} ${option}`);
  input.value = lines.join("\n");
  input.focus();
}

// 답변 시각 옆에 소요 시간 표시 (예: 오후 9:14 · 12초)
function messageTime(createdAt, elapsedMs) {
  const el = timeElement("message-time", new Date(createdAt), timeFormat);
  if (elapsedMs != null) el.textContent += ` · ${formatSeconds(elapsedMs)}`;
  return el;
}

// 직전 메시지와 날짜가 다를 때만 날짜 구분선 추가
function appendDateDivider(date) {
  const key = date.toDateString();
  if (key === state.lastDate) return;
  state.lastDate = key;
  const divider = document.createElement("p");
  divider.className = "date-divider";
  divider.append(timeElement("", date, dateFormat));
  $("messages").append(divider);
}

function scrollToBottom() {
  const el = $("chat-scroll");
  el.scrollTop = el.scrollHeight;
}

// LLM 답변의 태그·스크립트 실행 방지를 위해 마크다운 변환 결과를 DOMPurify로 정리한 뒤에만 HTML로 삽입
function setMarkdown(el, text) {
  el.innerHTML = DOMPurify.sanitize(marked.parse(text, { breaks: true, gfm: true }));
}

// 질문은 textContent로만 출력, 답변은 정리된 마크다운 HTML로 출력
function renderMessage(role, content, createdAt, elapsedMs) {
  const date = createdAt ? new Date(createdAt) : null;
  if (date) appendDateDivider(date);

  const wrap = document.createElement("article");
  wrap.className = `message ${role}`;
  const roleEl = document.createElement("span");
  roleEl.className = "visually-hidden";
  roleEl.textContent = role === "user" ? "질문" : "답변";
  const body = document.createElement("div");
  body.className = "bubble";
  if (role === "assistant") setMarkdown(body, content);
  else body.textContent = content;
  wrap.append(roleEl, body);
  if (date) wrap.append(messageTime(createdAt, elapsedMs));
  $("messages").append(wrap);
  scrollToBottom();

  return {
    body,
    setTime(createdAt, elapsedMs) {
      body.after(messageTime(createdAt, elapsedMs));
    },
    setCitations(citations) {
      if (!citations.length) return;
      const details = document.createElement("details");
      details.className = "citations";
      const summary = document.createElement("summary");
      summary.textContent = `근거 조항 ${citations.length}개`;
      const ul = document.createElement("ul");
      for (const c of citations) {
        const li = document.createElement("li");
        const label = document.createElement("strong");
        label.textContent = c.label;
        const clause = document.createElement("div");
        clause.className = "clause";
        clause.textContent = c.text;
        li.append(label, clause);
        ul.append(li);
      }
      details.append(summary, ul);
      wrap.append(details);
    },
    // 사전 질문 카드: 선택지가 있으면 버튼, 질문이 하나뿐이면 선택 즉시 전송
    setClarifications(clarifications) {
      if (!clarifications.length) return;
      const list = document.createElement("ol");
      list.className = "clarifications";
      list.setAttribute("aria-label", "확인이 필요한 사항");
      for (const { question, options } of clarifications) {
        const item = document.createElement("li");
        const text = document.createElement("p");
        text.textContent = question;
        item.append(text);
        if (options.length) {
          const choices = document.createElement("div");
          choices.className = "choices";
          for (const option of options) {
            const choice = document.createElement("button");
            choice.type = "button";
            choice.className = "choice";
            choice.textContent = option;
            choice.addEventListener("click", () => {
              if ($("ask-button").disabled) return;
              if (clarifications.length === 1) {
                $("question").value = option;
                $("ask-form").requestSubmit();
              } else {
                fillAnswer(question, option);
              }
            });
            choices.append(choice);
          }
          item.append(choices);
        }
        list.append(item);
      }
      wrap.append(list);
    },
  };
}

// 답변 첫 글자가 올 때까지 점 애니메이션과 경과 시간 표시, stop()으로 타이머 해제
function typingIndicator() {
  const wrap = document.createElement("span");
  wrap.className = "loading";
  wrap.setAttribute("role", "status");
  wrap.setAttribute("aria-label", "답변 작성 중");
  const dots = document.createElement("span");
  dots.className = "typing";
  for (let i = 0; i < 3; i++) dots.append(document.createElement("span"));
  const elapsed = document.createElement("span");
  elapsed.className = "elapsed";
  elapsed.setAttribute("aria-hidden", "true");
  wrap.append(dots, elapsed);

  const startedAt = performance.now();
  const tick = () => (elapsed.textContent = formatSeconds(performance.now() - startedAt));
  tick();
  const timer = setInterval(tick, 100);
  return { el: wrap, stop: () => clearInterval(timer) };
}

async function readEvents(res, onEvent) {
  const reader = res.body.getReader();
  const decoder = new TextDecoder();
  let buffer = "";
  while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    buffer += decoder.decode(value, { stream: true });
    let idx;
    while ((idx = buffer.indexOf("\n\n")) >= 0) {
      const raw = buffer.slice(0, idx);
      buffer = buffer.slice(idx + 2);
      const event = raw.match(/^event: (.*)$/m)?.[1];
      const data = raw.match(/^data: (.*)$/m)?.[1];
      if (event && data !== undefined) onEvent(event, JSON.parse(data));
    }
  }
}

async function ask(event) {
  event.preventDefault();
  const input = $("question");
  const status = $("ask-status");
  const question = input.value.trim();
  if (!question) {
    setStatus(status, "질문을 입력하세요.", true);
    input.focus();
    return;
  }

  const button = $("ask-button");
  button.disabled = true;
  setStatus(status, "");
  let answer = null;
  let loading = null;
  try {
    if (!state.conversationId) {
      const conv = await api(`/api/documents/${state.documentId}/conversations`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ title: question }),
      });
      state.conversationId = conv.id;
      await loadConversations();
    }

    renderMessage("user", question, new Date().toISOString());
    input.value = "";
    answer = renderMessage("assistant", "");
    answer.body.classList.add("pending");
    loading = typingIndicator();
    answer.body.append(loading.el);

    // EventSource는 GET만 지원하여 질문 본문 전송이 불가능하므로 fetch 스트림으로 수신
    const res = await fetch(`/api/conversations/${state.conversationId}/messages`, {
      method: "POST",
      headers: headers({ "Content-Type": "application/json" }),
      body: JSON.stringify({ question }),
    });
    if (!res.ok) {
      const data = await res.json().catch(() => ({}));
      throw new Error(data.message || `요청 실패 (${res.status})`);
    }

    let citations = [];
    let failed = false;
    let raw = "";
    let clarifications = [];
    await readEvents(res, (type, data) => {
      if (type === "done") {
        answer.setTime(data.created_at, data.elapsed_ms);
        citations = data.citations;
        clarifications = data.clarifications;
      }
      if (type === "delta") {
        if (answer.body.classList.contains("pending")) {
          loading.stop();
          answer.body.classList.remove("pending");
          answer.body.textContent = "";
        }
        raw += data;
        setMarkdown(answer.body, visibleAnswer(raw));
        scrollToBottom();
      }
      if (type === "error") {
        failed = true;
        answer.body.classList.remove("pending");
        answer.body.textContent = data;
        setStatus(status, data, true);
      }
    });
    // 마지막 채팅 시간과 목록 순서 갱신
    loadConversations();
    if (!failed) {
      answer.setCitations(citations);
      answer.setClarifications(clarifications);
      setStatus(status, "");
    }
  } catch (err) {
    setStatus(status, err.message, true);
    if (answer?.body.classList.contains("pending")) {
      answer.body.classList.remove("pending");
      answer.body.textContent = err.message;
    }
  } finally {
    loading?.stop();
    button.disabled = false;
  }
}

async function upload(event) {
  event.preventDefault();
  const form = event.target;
  const status = $("upload-status");
  const button = form.querySelector("button");
  if (!$("upload-file").files.length) {
    setStatus(status, "파일을 고르세요.", true);
    return;
  }
  button.disabled = true;
  setStatus(status, "올리는 중…");
  try {
    const doc = await api("/api/documents", { method: "POST", body: new FormData(form) });
    $("upload-dialog").close();
    await selectDocument(doc.id);
  } catch (err) {
    setStatus(status, err.message, true);
  } finally {
    button.disabled = false;
  }
}

$("upload-form").addEventListener("submit", upload);
$("open-upload").addEventListener("click", () => {
  $("upload-form").reset();
  setStatus($("upload-status"), "");
  $("upload-dialog").showModal();
});
$("close-upload").addEventListener("click", () => $("upload-dialog").close());
$("ask-form").addEventListener("submit", ask);
$("new-conversation").addEventListener("click", () => {
  state.conversationId = null;
  clearMessages();
  loadConversations();
  $("question").focus();
});
// Enter 전송, Ctrl/⌘+Enter 줄바꿈 (한글 조합 확정용 Enter는 무시, Safari는 조합 중 keyCode 229)
$("question").addEventListener("keydown", (e) => {
  if (e.key !== "Enter" || e.shiftKey || e.isComposing || e.keyCode === 229) return;
  e.preventDefault();
  if (e.ctrlKey || e.metaKey) {
    e.target.setRangeText("\n", e.target.selectionStart, e.target.selectionEnd, "end");
    return;
  }
  if (!$("ask-button").disabled) $("ask-form").requestSubmit();
});

loadDocuments().catch((err) => {
  const li = document.createElement("li");
  li.className = "empty-row";
  li.textContent = err.message;
  $("document-list").replaceChildren(li);
});
