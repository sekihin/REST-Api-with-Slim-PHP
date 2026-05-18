<?php

if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }

    $input = file_get_contents('php://input');
    $data  = json_decode($input, true);
    if (!$data) $data = ['message' => ''];

    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    if (ob_get_level()) ob_end_clean();

    $targetUrl = 'http://192.168.1.159:8080/agent/rag';
    $buffer    = '';

    $ch = curl_init($targetUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: text/event-stream, application/json',
        ],
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_WRITEFUNCTION  => function($ch, $chunk) use (&$buffer) {
            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line   = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                if (str_starts_with($line, 'data:')) {
                    echo $line . "\n\n";
                } elseif (trim($line) !== '') {
                    $payload = json_encode(['token' => trim($line)]);
                    echo "data: $payload\n\n";
                }
                flush();
            }
            return strlen($chunk);
        },
    ]);

    $ok    = curl_exec($ch);
    $error = curl_error($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($buffer !== '') {
        echo "data: " . json_encode(['token' => $buffer]) . "\n\n";
        flush();
    }

    if (!$ok || ($code !== 200 && $code !== 0)) {
        echo "data: " . json_encode([
            'error' => $error ?: "HTTP $code",
            'mock'  => 'APIが利用できないため、モックデータを返します。',
        ]) . "\n\n";
    }

    echo "data: [DONE]\n\n";
    flush();
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AIチャット (ストリーミング対応)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        .chat-window {
            position: fixed; bottom: 20px; right: 20px;
            width: 600px; max-height: 600px;
            background: #fff; border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,.1);
            z-index: 1000; overflow: hidden;
            transition: all .3s ease;
            display: flex; flex-direction: column;
        }
        .chat-ai-header {
            background: #f8f9fa; padding: 15px;
            border-bottom: 1px solid #e9ecef;
            display: flex; align-items: center; cursor: pointer;
        }
        .chat-ai-header h5 { margin:0; font-size:16px; font-weight:600; color:#333; }
        .chat-ai-body1 { padding:15px; flex-grow:1; max-height:200px; }
        .chat-ai-body {
            padding: 15px; overflow-y: auto;
            flex-grow: 1; height: 300px; max-height: 300px;
            display: flex; flex-direction: column; gap: 20px;
        }
        .chat-ai-input-container {
            padding: 10px 15px; border-top: 1px solid #e9ecef;
            display: flex; align-items: center;
        }
        .chat-ai-input {
            flex-grow:1; border:1px solid #ced4da;
            border-radius:20px; padding:8px 15px; font-size:14px; outline:none;
        }
        .chat-ai-input:focus { border-color:#007bff; }
        .chat-ai-send-btn {
            background:#007bff; color:#fff; border:none;
            border-radius:50%; width:36px; height:36px;
            margin-left:10px; display:flex; align-items:center;
            justify-content:center; cursor:pointer;
        }
        .chat-ai-toggle {
            position:fixed; bottom:20px; right:20px;
            width:50px; height:50px; background:#007bff; color:#fff;
            border-radius:50%; display:flex; align-items:center;
            justify-content:center; cursor:pointer;
            box-shadow:0 5px 15px rgba(0,0,0,.1); z-index:1000;
        }
        .chat-window.collapsed .chat-ai-body,
        .chat-window.collapsed .chat-ai-body1,
        .chat-window.collapsed .chat-ai-input-container { display:none; }

        .chat-row { display:flex; width:100%; box-sizing:border-box; }
        .chat-row.bot-row  { justify-content:flex-start; align-items:flex-start; gap:12px; }
        .chat-row.bot-row .chat-avatar { margin-top:22px; }
        .chat-row.user-row { flex-direction:row-reverse !important; align-items:center !important; gap:12px; }

        .chat-avatar { width:42px; height:42px; border-radius:12px; flex-shrink:0; }
        .chat-content-wrap { display:flex; flex-direction:column; max-width:85%; }
        .chat-time { font-size:11.5px; color:#9b9ba0; margin-bottom:6px; }
        .user-row .chat-time { text-align:right; }
        .bot-row  .chat-time { text-align:left; }
        .bubble-layout { display:flex; align-items:flex-end; gap:8px; }
        .chat-bubble {
            background:#fff; padding:12px 16px;
            border-radius:8px; border:1px solid #e5e5eb;
            color:#63666a; font-size:14px; line-height:1.5;
            box-shadow:0 1px 3px rgba(0,0,0,.05);
            white-space: pre-wrap; word-break: break-word;
        }
        .bot-row  .chat-bubble { border-left:4px solid #001e43; }
        .user-row .chat-bubble { border-right:4px solid #111; }

        .streaming-cursor::after {
            content: '▍';
            display: inline-block;
            animation: blink .7s step-end infinite;
            color: #001e43;
            margin-left: 1px;
        }
        @keyframes blink { 50% { opacity:0; } }

        .btn-group-actions { display:flex; align-items:center; gap:5px; flex-shrink:0; }
        .bot-row  .btn-group-actions { margin-left:12px; }
        .user-row .btn-group-actions { margin-right:12px; }
        .action-btn, .msg-action-btn {
            background:transparent; border:1px solid #d1d1d6;
            border-radius:8px; width:25px; height:25px;
            display:flex; align-items:center; justify-content:center;
            color:#8e8e93; cursor:pointer; transition:all .2s; padding:0; font-size:13px;
        }
        .action-btn svg, .msg-action-btn svg { width:14px; height:14px; display:block; }
        .action-btn:hover, .msg-action-btn:hover { background:#e5e5eb; color:#333; }
        .action-btn.copied { border-color:#34c759; color:#34c759; }

        .chat-ai-buttons { gap:8px; margin-bottom:15px; flex-wrap:wrap; display:flex; }
        .chat-ai-btn {
            padding:8px 12px; border-radius:20px; font-size:12px;
            font-weight:500; cursor:pointer; border:1px solid #dee2e6;
            background:#f8f9fa; color:#495057; transition:all .2s;
        }
        .chat-ai-btn:hover { background:#e5e5eb; }
        .chat-ai-btn.active { background:#007bff; color:#fff; }
        .limit-message {
            background:#f8f9fa; border:1px solid #dee2e6; border-radius:5px;
            padding:8px 12px; text-align:center; font-size:12px; color:#6c757d;
        }
        .limit-message .counter { font-weight:bold; color:#007bff; }

        /* ── typing dots (used inside the bot bubble) ── */
        .typing-dot {
            display: inline-block;
            width: 6px; height: 6px;
            background: #9b9ba0; border-radius: 50%;
            animation: typing 1.4s infinite both;
        }
        .typing-dot:nth-child(2) { animation-delay: .2s; }
        .typing-dot:nth-child(3) { animation-delay: .4s; }
        @keyframes typing { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-4px)} }

        .system-message { background:#f8f9fa; border-left:3px solid #6c757d; padding:8px 12px; font-size:12px; color:#6c757d; }
        .error-message  { background:#f8d7da; border-left:3px solid #dc3545; padding:8px 12px; font-size:12px; color:#721c24; }
    </style>
</head>
<body>
<div class="container mt-5">
    <h1 class="text-center mb-4">AIチャット — ストリーミング対応版</h1>
    <p class="text-center text-success">✅ PHP SSEプロキシによりトークンを逐次受信します</p>
</div>

<div class="chat-window collapsed" id="chatWindow">
    <div class="chat-ai-header" id="chatHeader">
        <h5>🤖 AIアシスタント</h5>
    </div>
    <div class="chat-ai-body1">
        <div style="font-size:14px;color:#333;margin-bottom:10px;">質問があれば入力してください。</div>
        <div class="chat-ai-buttons">
            <button class="chat-ai-btn" data-query="ログインパスワードを忘れた場合の対処方法を教えてください">パスワードを忘れ</button>
            <button class="chat-ai-btn" data-query="製品の受け取り方法について教えてください">製品の受取方法</button>
            <button class="chat-ai-btn" data-query="受領確認の手順について教えてください">受領確認の手順</button>
        </div>
        <div class="limit-message">
            送信回数: <span class="counter" id="counter">0</span>/3
        </div>
    </div>
    <div class="chat-ai-body" id="chatBody"></div>
    <div class="chat-ai-input-container">
        <input type="text" class="chat-ai-input" id="chatInput" placeholder="質問内容を入力">
        <button class="chat-ai-send-btn" id="chatSendBtn"><i class="fa fa-paper-plane"></i></button>
    </div>
</div>
<div class="chat-ai-toggle" id="chatToggle"><i class="fa fa-comments"></i></div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(function () {
    const chatWindow  = $('#chatWindow');
    const chatBody    = $('#chatBody');
    const chatInput   = $('#chatInput');
    const chatSendBtn = $('#chatSendBtn');
    const counter     = $('#counter');

    let conversationCount = 0;
    const MAX     = 3;
    const API_URL = 'chat.php';

    // ── HH:mm:ss timestamp ───────────────────────────────────────
    function ts() {
        const n = new Date();
        return [n.getHours(), n.getMinutes(), n.getSeconds()]
            .map(v => String(v).padStart(2, '0')).join(':');
    }

    const COPY_ICON = `<svg version="1.0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
        <g transform="translate(0,24) scale(0.1,-0.1)" fill="currentColor" stroke="none">
        <path d="M80 210 c0 -15 -7 -20 -25 -20 -25 0 -25 -1 -25 -85 l0 -85 70 0 c63
        0 70 2 70 20 0 15 7 20 25 20 24 0 25 3 25 57 0 48 -4 61 -27 85 -21 22 -36
        28 -70 28 -36 0 -43 -3 -43 -20z m80 -15 c0 -20 5 -25 25 -25 23 0 25 -3 25
        -50 l0 -50 -60 0 -60 0 0 75 0 75 35 0 c31 0 35 -3 35 -25z m30 0 c10 -12 10
        -15 -4 -15 -9 0 -16 7 -16 15 0 8 2 15 4 15 2 0 9 -7 16 -15z m-110 -75 l0
        -60 40 0 c29 0 40 -4 40 -15 0 -12 -13 -15 -60 -15 l-60 0 0 75 c0 68 2 75 20
        75 18 0 20 -7 20 -60z"/></g></svg>`;

    const REGEN_ICON = `<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16">
        <path d="M8 3a5 5 0 1 0 4.53 2.914.5.5 0 0 1 .908-.417A6 6 0 1 1 8 2v1z"/>
        <path d="M8 4.466V.534a.25.25 0 0 1 .41-.192l2.36 1.966c.12.1.12.284 0 .384L8.41 4.658A.25.25 0 0 1 8 4.466z"/>
        </svg>`;

    function userAvatar() {
        return `<svg class="chat-avatar" viewBox="0 0 100 100">
            <rect width="100" height="100" fill="#4696e0" rx="25"/>
            <circle cx="50" cy="40" r="18" fill="#fff"/>
            <path d="M25 100 C25 65,75 65,75 100" fill="#fff"/>
        </svg>`;
    }
    function botAvatar() {
        return `<svg class="chat-avatar" viewBox="0 0 100 100">
            <rect width="100" height="100" fill="#ffa78c" rx="25"/>
            <rect x="25" y="30" width="50" height="40" rx="8" fill="#fff"/>
            <circle cx="38" cy="45" r="5" fill="#333"/>
            <circle cx="62" cy="45" r="5" fill="#333"/>
            <path d="M42 60 L58 60" stroke="#333" stroke-width="3" stroke-linecap="round"/>
        </svg>`;
    }

    function scrollBottom() {
        chatBody.scrollTop(chatBody.prop('scrollHeight'));
    }

    function updateCounter() {
        counter.text(conversationCount);
        if (conversationCount >= MAX) {
            chatInput.prop('disabled', true).attr('placeholder', '送信上限に達しました');
            chatSendBtn.prop('disabled', true);
            $('.chat-ai-btn').prop('disabled', true);
        }
    }

    function addUserMessage(text) {
        chatBody.append(`
        <div class="chat-row user-row">
            ${userAvatar()}
            <div class="chat-content-wrap">
                <div class="chat-time">${ts()}</div>
                <div class="bubble-layout">
                    <div class="btn-group-actions">
                        <button class="action-btn" title="コピー">${COPY_ICON}</button>
                    </div>
                    <div class="chat-bubble">${escHtml(text)}</div>
                </div>
            </div>
        </div>`);
        scrollBottom();
    }

    // ── 「考え中…」indicator: a full bot row ────────────────────
    function showTyping() {
        chatBody.append(`
        <div class="chat-row bot-row" id="typingIndicator">
            ${botAvatar()}
            <div class="chat-content-wrap">
                <div class="chat-time">${ts()}</div>
                <div class="bubble-layout">
                    <div class="chat-bubble"
                         style="display:flex;align-items:center;gap:8px;
                                color:#9b9ba0;font-size:13px;font-style:italic;">
                        <span style="display:inline-flex;gap:3px;align-items:center;">
                            <span class="typing-dot"></span>
                            <span class="typing-dot"></span>
                            <span class="typing-dot"></span>
                        </span>
                        考え中…
                    </div>
                </div>
            </div>
        </div>`);
        scrollBottom();
    }
    function hideTyping() { $('#typingIndicator').remove(); }

    // ── streaming bot bubble ─────────────────────────────────────
    function addBotBubble() {
        const id = 'bubble-' + Date.now();
        chatBody.append(`
        <div class="chat-row bot-row">
            ${botAvatar()}
            <div class="chat-content-wrap">
                <div class="chat-time">${ts()}</div>
                <div class="bubble-layout">
                    <div class="chat-bubble streaming-cursor" id="${id}"></div>
                    <div class="btn-group-actions">
                        <button class="action-btn" title="コピー">${COPY_ICON}</button>
                        <button class="msg-action-btn" title="再生成">${REGEN_ICON}</button>
                    </div>
                </div>
            </div>
        </div>`);
        scrollBottom();
        return $('#' + id);
    }

    function addSystemMessage(text) {
        chatBody.append(`<div class="system-message">${escHtml(text)}</div>`);
        scrollBottom();
    }
    function addErrorMessage(text) {
        chatBody.append(`<div class="error-message">エラー：${escHtml(text)}</div>`);
        scrollBottom();
    }

    function escHtml(s) {
        return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    // ── streaming fetch ──────────────────────────────────────────
    async function streamApi(message) {
        showTyping();

        let $bubble = null;
        let accum   = '';

        try {
            const resp = await fetch(API_URL, {
                method : 'POST',
                headers: { 'Content-Type': 'application/json' },
                body   : JSON.stringify({ message })
            });

            hideTyping();

            if (!resp.ok) { addErrorMessage(`HTTP ${resp.status}`); return; }

            $bubble = addBotBubble();

            const reader  = resp.body.getReader();
            const decoder = new TextDecoder();
            let   partial = '';

            while (true) {
                const { value, done } = await reader.read();
                if (done) break;

                partial += decoder.decode(value, { stream: true });
                const parts = partial.split('\n\n');
                partial = parts.pop();

                for (const part of parts) {
                    const line = part.trim();
                    if (!line.startsWith('data:')) continue;
                    const raw = line.slice(5).trim();

                    if (raw === '[DONE]') {
                        $bubble.removeClass('streaming-cursor');
                        return;
                    }

                    let token = raw;
                    try {
                        const obj = JSON.parse(raw);
                        if (obj.error) {
                            $bubble.removeClass('streaming-cursor');
                            addErrorMessage(obj.error + (obj.mock ? ' — ' + obj.mock : ''));
                            return;
                        }
                        if (obj.choices?.[0]?.delta?.content !== undefined)
                            token = obj.choices[0].delta.content;
                        else if (obj.token !== undefined)
                            token = obj.token;
                        else if (obj.reply || obj.answer || obj.content || obj.text || obj.message)
                            token = obj.reply ?? obj.answer ?? obj.content ?? obj.text ?? obj.message;
                        else
                            token = raw;
                    } catch (_) {}

                    if (typeof token === 'string' && token.length) {
                        accum += token;
                        $bubble.text(accum);
                        scrollBottom();
                    }
                }
            }

            if (partial.trim() && $bubble) {
                const raw = partial.replace(/^data:\s*/, '').trim();
                if (raw && raw !== '[DONE]') {
                    try {
                        const obj = JSON.parse(raw);
                        const t = obj.token ?? obj.reply ?? obj.answer ?? raw;
                        if (t) { accum += t; $bubble.text(accum); }
                    } catch(_) { accum += raw; $bubble.text(accum); }
                }
            }

            if ($bubble) $bubble.removeClass('streaming-cursor');

        } catch (err) {
            hideTyping();
            if ($bubble) $bubble.removeClass('streaming-cursor');
            addErrorMessage('ネットワーク接続エラー: ' + err.message);
        }
    }

    async function send(msg) {
        if (conversationCount >= MAX) { addSystemMessage('本日の送信回数上限に達しました'); return; }
        if (!msg) return;

        addUserMessage(msg);
        await streamApi(msg);

        conversationCount++;
        updateCounter();
        if (conversationCount === MAX)
            addSystemMessage('本日の利用回数制限（3回）に到達しました！');
        else if (conversationCount === MAX - 1)
            addSystemMessage('残りの利用回数はあと1回です');
    }

    function toggleChat() {
        chatWindow.toggleClass('collapsed');
        $('#chatToggle').toggle();
    }
    $('#chatHeader').click(toggleChat);
    $('#chatToggle').click(toggleChat);

    chatSendBtn.click(() => { const m = chatInput.val().trim(); chatInput.val(''); send(m); });
    chatInput.keypress(e => {
        if (e.which === 13) { const m = chatInput.val().trim(); chatInput.val(''); send(m); }
    });

    $('.chat-ai-btn').click(function () {
        if (conversationCount >= MAX) { addSystemMessage('送信回数上限に達しました'); return; }
        $('.chat-ai-btn').removeClass('active');
        $(this).addClass('active');
        send($(this).data('query'));
    });

    $(document).on('click', '.action-btn', function () {
        const text = $(this).closest('.bubble-layout').find('.chat-bubble').text().trim();
        if (navigator.clipboard) navigator.clipboard.writeText(text);
        $(this).addClass('copied');
        setTimeout(() => $(this).removeClass('copied'), 1500);
    });

    updateCounter();
    $('#chatToggle').show();
});
</script>
</body>
</html>