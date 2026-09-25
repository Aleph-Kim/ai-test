const state = { documentId: null, conversationId: null, documents: [] };

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

function listItem(label, current, onSelect) {
  const li = document.createElement("li");
  const button = document.createElement("button");
  button.type = "button";
  button.className = "row-button";
  button.textContent = label;
  button.setAttribute("aria-current", String(current));
  button.addEventListener("click", onSelect);
  li.append(button);
  return li;
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
    const li = listItem(doc.title, doc.id === state.documentId, () => selectDocument(doc.id));
    const del = document.createElement("button");
    del.type = "button";
    del.className = "delete-button";
    del.textContent = "삭제";
    del.setAttribute("aria-label", `${doc.title} 삭제`);
    del.addEventListener("click", () => deleteDocument(doc));
    li.append(del);
    list.append(li);
  }
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
    $("messages").replaceChildren();
  }
  await loadDocuments();
}

async function selectDocument(id) {
  state.documentId = id;
  state.conversationId = null;
  $("conversation-section").hidden = false;
  $("ask-form").hidden = false;
  $("chat-empty").hidden = true;
  $("messages").replaceChildren();
  await Promise.all([loadDocuments(), loadConversations()]);
  $("chat-title").textContent = state.documents.find((d) => d.id === id)?.title ?? "";
  $("question").focus();
}

async function loadConversations() {
  const list = $("conversation-list");
  list.replaceChildren();
  const conversations = await api(`/api/documents/${state.documentId}/conversations`);
  for (const conv of conversations) {
    list.append(listItem(conv.title, conv.id === state.conversationId, () => selectConversation(conv.id)));
  }
}

async function selectConversation(id) {
  state.conversationId = id;
  const messages = await api(`/api/conversations/${id}/messages`);
  $("messages").replaceChildren();
  for (const m of messages) {
    const view = renderMessage(m.role, m.content);
    if (m.role === "assistant") view.setCitations(m.citations);
  }
  await loadConversations();
  scrollToBottom();
}

function scrollToBottom() {
  const el = $("chat-scroll");
  el.scrollTop = el.scrollHeight;
}

// LLM 답변과 업로드 원문에 포함된 태그 실행 방지를 위해 textContent로만 출력
function renderMessage(role, content) {
  const wrap = document.createElement("article");
  wrap.className = `message ${role}`;
  const roleEl = document.createElement("span");
  roleEl.className = "visually-hidden";
  roleEl.textContent = role === "user" ? "질문" : "답변";
  const body = document.createElement("div");
  body.className = "bubble";
  body.textContent = content;
  wrap.append(roleEl, body);
  $("messages").append(wrap);
  scrollToBottom();

  return {
    body,
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
  };
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
  setStatus(status, "답변 작성 중…");
  let answer = null;
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

    renderMessage("user", question);
    input.value = "";
    answer = renderMessage("assistant", "답변 작성 중…");
    answer.body.classList.add("pending");

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
    await readEvents(res, (type, data) => {
      if (type === "citations") citations = data;
      if (type === "delta") {
        if (answer.body.classList.contains("pending")) {
          answer.body.classList.remove("pending");
          answer.body.textContent = "";
        }
        answer.body.textContent += data;
        scrollToBottom();
      }
      if (type === "error") {
        failed = true;
        answer.body.classList.remove("pending");
        answer.body.textContent = data;
        setStatus(status, data, true);
      }
    });
    if (!failed) {
      answer.setCitations(citations);
      setStatus(status, "");
    }
  } catch (err) {
    setStatus(status, err.message, true);
    if (answer?.body.classList.contains("pending")) {
      answer.body.classList.remove("pending");
      answer.body.textContent = err.message;
    }
  } finally {
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
    form.reset();
    setStatus(status, "올렸습니다.");
    await selectDocument(doc.id);
  } catch (err) {
    setStatus(status, err.message, true);
  } finally {
    button.disabled = false;
  }
}

$("upload-form").addEventListener("submit", upload);
$("ask-form").addEventListener("submit", ask);
$("new-conversation").addEventListener("click", () => {
  state.conversationId = null;
  $("messages").replaceChildren();
  loadConversations();
  $("question").focus();
});
$("question").addEventListener("keydown", (e) => {
  if (e.key === "Enter" && (e.ctrlKey || e.metaKey) && !e.isComposing) $("ask-form").requestSubmit();
});

loadDocuments().catch((err) => setStatus($("upload-status"), err.message, true));
