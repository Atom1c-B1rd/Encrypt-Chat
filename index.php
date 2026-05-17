<?php

define('SECRET_KEY', 'Change This');
define('MESSAGES_FILE', __DIR__ . '/messages.json');
define('USERS_FILE',    __DIR__ . '/users.json');
define('MAX_MESSAGES',  200);


function encrypt(string $text): string {
    $iv = random_bytes(16);
    $encrypted = openssl_encrypt($text, 'AES-256-CBC', SECRET_KEY, 0, $iv);
    return base64_encode($iv . '::' . $encrypted);
}

function decrypt(string $data): string {
    $decoded = base64_decode($data);
    if (!str_contains($decoded, '::')) return '';
    [$iv, $encrypted] = explode('::', $decoded, 2);
    return openssl_decrypt($encrypted, 'AES-256-CBC', SECRET_KEY, 0, $iv) ?: '';
}

function loadUsers(): array {
    if (!file_exists(USERS_FILE)) return [];
    $raw = json_decode(file_get_contents(USERS_FILE), true) ?? [];
    $now = time();
    return array_filter($raw, fn($u) => ($now - $u['ts']) < 15);
}

function heartbeat(string $user): void {
    $users = [];
    if (file_exists(USERS_FILE))
        $users = json_decode(file_get_contents(USERS_FILE), true) ?? [];
    $now = time();
    $users = array_filter($users, fn($u) => ($now - $u['ts']) < 15);
    $found = false;
    foreach ($users as &$u) {
        if ($u['name'] === $user) { $u['ts'] = $now; $found = true; break; }
    }
    if (!$found) $users[] = ['name' => $user, 'ts' => $now];
    file_put_contents(USERS_FILE, json_encode(array_values($users)));
}

function loadMessages(): array {
    if (!file_exists(MESSAGES_FILE)) return [];
    $raw = json_decode(file_get_contents(MESSAGES_FILE), true) ?? [];
    return array_map(fn($m) => [
        'id'   => $m['id']   ?? uniqid(),
        'user' => htmlspecialchars(decrypt($m['user'])),
        'text' => htmlspecialchars(decrypt($m['text'])),
        'time' => $m['time'],
        'dm'   => isset($m['dm']) ? htmlspecialchars(decrypt($m['dm'])) : null,
    ], $raw);
}

function saveMessage(string $user, string $text, ?string $dm = null): void {
    $messages = [];
    if (file_exists(MESSAGES_FILE))
        $messages = json_decode(file_get_contents(MESSAGES_FILE), true) ?? [];
    $entry = [
        'id'   => uniqid('m', true),
        'user' => encrypt($user),
        'text' => encrypt($text),
        'time' => date('H:i'),
    ];
    if ($dm) $entry['dm'] = encrypt($dm);
    $messages[] = $entry;
    if (count($messages) > MAX_MESSAGES)
        $messages = array_slice($messages, -MAX_MESSAGES);
    file_put_contents(MESSAGES_FILE, json_encode($messages));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    header('Content-Type: application/json');

    if ($action === 'send') {
        $user = trim($input['user'] ?? '');
        $text = trim($input['text'] ?? '');
        $dm   = trim($input['dm']   ?? '') ?: null;
        if ($user && $text && mb_strlen($user) <= 30 && mb_strlen($text) <= 500) {
            saveMessage($user, $text, $dm);
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Datos inválidos']);
        }
        exit;
    }

    if ($action === 'heartbeat') {
        $user = trim($input['user'] ?? '');
        if ($user) heartbeat($user);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'clear') {
        file_put_contents(MESSAGES_FILE, '[]');
        echo json_encode(['ok' => true]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['poll'])) {
    header('Content-Type: application/json');
    $msgs  = loadMessages();
    $users = array_values(loadUsers());
    echo json_encode(['messages' => $msgs, 'users' => $users]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ChatCrypt</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Space+Grotesk:wght@300;400;500;700&display=swap');

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg:          #0d0d14;
    --surface:     #12121c;
    --surface2:    #191926;
    --border:      #2a2a40;
    --neon-pink:   #ff2d78;
    --neon-cyan:   #00e5ff;
    --neon-purple: #b44fff;
    --neon-green:  #39ff85;
    --text:        #d4d4f0;
    --muted:       #4a4a6a;
    --radius:      4px;
  }

  body {
    background: var(--bg);
    color: var(--text);
    font-family: 'Space Grotesk', sans-serif;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    overflow: hidden;
  }

  body::before {
    content: '';
    position: fixed; inset: 0;
    background-image:
      linear-gradient(rgba(0,229,255,.022) 1px, transparent 1px),
      linear-gradient(90deg, rgba(0,229,255,.022) 1px, transparent 1px);
    background-size: 40px 40px;
    pointer-events: none; z-index: 0;
  }

  body::after {
    content: '';
    position: fixed; inset: 0;
    background: repeating-linear-gradient(
      0deg, transparent, transparent 2px, rgba(0,0,0,.15) 3px
    );
    pointer-events: none; z-index: 0;
    animation: scanroll 10s linear infinite;
  }

  @keyframes scanroll {
    from { background-position: 0 0; }
    to   { background-position: 0 100vh; }
  }

  #loginScreen {
    position: relative; z-index: 10;
    width: 100%; max-width: 380px;
    display: flex; flex-direction: column;
    align-items: center; gap: 2rem;
    animation: fadeUp .3s ease;
  }

  #loginScreen.hidden { display: none; }

  .login-logo { text-align: center; }

  .login-logo .icon {
    font-size: 2.5rem;
    display: block;
    filter: drop-shadow(0 0 16px rgba(0,229,255,.5));
    margin-bottom: .5rem;
  }

  .login-logo h1 {
    font-family: 'Share Tech Mono', monospace;
    font-size: 1.8rem;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--neon-cyan);
    text-shadow: 0 0 16px rgba(0,229,255,.7), 0 0 40px rgba(0,229,255,.3);
  }

  .login-logo h1 span {
    color: var(--neon-pink);
    text-shadow: 0 0 16px rgba(255,45,120,.7);
  }

  .login-logo p {
    font-family: 'Share Tech Mono', monospace;
    font-size: .68rem;
    color: var(--muted);
    letter-spacing: .1em;
    text-transform: uppercase;
    margin-top: .4rem;
  }

  .login-box {
    width: 100%;
    background: var(--surface);
    border: 1px solid var(--border);
    border-top: 2px solid var(--neon-cyan);
    border-radius: var(--radius);
    padding: 1.8rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
    box-shadow: 0 0 40px rgba(0,229,255,.06);
  }

  .login-box label {
    font-family: 'Share Tech Mono', monospace;
    font-size: .68rem;
    color: var(--neon-cyan);
    letter-spacing: .1em;
    text-transform: uppercase;
    display: block;
    margin-bottom: .4rem;
  }

  .login-box input {
    width: 100%;
    background: var(--bg);
    border: 1px solid var(--border);
    color: var(--text);
    font-family: 'Share Tech Mono', monospace;
    font-size: 1rem;
    padding: .75rem 1rem;
    border-radius: var(--radius);
    outline: none;
    transition: border-color .2s, box-shadow .2s;
    letter-spacing: .06em;
  }

  .login-box input:focus {
    border-color: var(--neon-cyan);
    box-shadow: 0 0 0 2px rgba(0,229,255,.1), 0 0 12px rgba(0,229,255,.15);
  }

  .login-box input::placeholder { color: var(--muted); }

  .btn-login {
    width: 100%;
    background: transparent;
    color: var(--neon-pink);
    border: 1px solid var(--neon-pink);
    font-family: 'Share Tech Mono', monospace;
    font-size: .85rem;
    letter-spacing: .15em;
    text-transform: uppercase;
    padding: .8rem;
    border-radius: var(--radius);
    cursor: pointer;
    transition: all .15s;
    box-shadow: 0 0 10px rgba(255,45,120,.2);
    margin-top: .3rem;
  }

  .btn-login:hover {
    background: rgba(255,45,120,.1);
    box-shadow: 0 0 20px rgba(255,45,120,.35);
    transform: translateY(-1px);
  }

  .login-hint {
    font-family: 'Share Tech Mono', monospace;
    font-size: .6rem;
    color: var(--muted);
    text-align: center;
    letter-spacing: .05em;
  }

  /* ════════ APP ════════ */
  #appScreen {
    position: relative; z-index: 1;
    width: 100%; max-width: 980px;
    height: 92vh; max-height: 780px;
    display: flex;
    flex-direction: column;
    filter: drop-shadow(0 0 40px rgba(0,229,255,.05));
  }

  #appScreen.hidden { display: none; }

  /* Top bar */
  .topbar {
    background: var(--surface);
    border: 1px solid var(--border);
    border-bottom: 2px solid var(--neon-cyan);
    border-radius: var(--radius) var(--radius) 0 0;
    padding: .7rem 1.2rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  .topbar-left { display: flex; align-items: center; gap: .7rem; }

  .topbar-logo {
    font-family: 'Share Tech Mono', monospace;
    font-size: .95rem;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: var(--neon-cyan);
    text-shadow: 0 0 10px rgba(0,229,255,.5);
  }

  .topbar-logo span { color: var(--neon-pink); text-shadow: 0 0 10px rgba(255,45,120,.5); }

  .topbar-channel {
    font-family: 'Share Tech Mono', monospace;
    font-size: .76rem;
    color: var(--muted);
    letter-spacing: .05em;
    padding: .18rem .55rem;
    border: 1px solid var(--border);
    border-radius: 2px;
  }

  .topbar-channel.dm-active {
    color: var(--neon-purple);
    border-color: rgba(180,79,255,.4);
    background: rgba(180,79,255,.06);
  }

  .topbar-right { display: flex; align-items: center; gap: .8rem; }

  .user-pill {
    font-family: 'Share Tech Mono', monospace;
    font-size: .68rem;
    color: var(--neon-green);
    border: 1px solid rgba(57,255,133,.3);
    background: rgba(57,255,133,.06);
    padding: .18rem .6rem;
    border-radius: 2px;
    letter-spacing: .05em;
  }

  .badge {
    font-family: 'Share Tech Mono', monospace;
    font-size: .58rem;
    color: var(--neon-green);
    background: rgba(57,255,133,.07);
    border: 1px solid rgba(57,255,133,.25);
    padding: .18rem .5rem;
    border-radius: 2px;
    letter-spacing: .08em;
    text-transform: uppercase;
    animation: blink 3s ease-in-out infinite;
  }

  @keyframes blink {
    0%,90%,100% { opacity: 1; }
    95% { opacity: .3; }
  }

  /* App body */
  .app-body {
    flex: 1;
    display: flex;
    min-height: 0;
    border: 1px solid var(--border);
    border-top: none;
    border-bottom: none;
  }

  /* Sidebar */
  .sidebar {
    width: 175px;
    flex-shrink: 0;
    background: var(--surface);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }

  .sidebar-section {
    padding: .65rem .85rem .35rem;
    font-family: 'Share Tech Mono', monospace;
    font-size: .58rem;
    color: var(--muted);
    letter-spacing: .1em;
    text-transform: uppercase;
    border-bottom: 1px solid var(--border);
  }

  .sidebar-item {
    padding: .42rem .85rem;
    font-family: 'Share Tech Mono', monospace;
    font-size: .74rem;
    color: var(--text);
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: .5rem;
    transition: background .12s, color .12s;
    letter-spacing: .02em;
    border-bottom: 1px solid rgba(255,255,255,.025);
    user-select: none;
  }

  .sidebar-item:hover { background: rgba(0,229,255,.05); color: var(--neon-cyan); }

  .sidebar-item.active {
    background: rgba(0,229,255,.08);
    color: var(--neon-cyan);
    padding-left: calc(.85rem - 2px);
    border-left: 2px solid var(--neon-cyan);
  }

  .sidebar-item .dot {
    width: 6px; height: 6px;
    border-radius: 50%;
    background: var(--neon-green);
    box-shadow: 0 0 6px rgba(57,255,133,.8);
    flex-shrink: 0;
  }

  .channel-prefix { color: var(--muted); }

  .sidebar-users { flex: 1; overflow-y: auto; padding-bottom: .5rem; }
  .sidebar-users::-webkit-scrollbar { width: 2px; }
  .sidebar-users::-webkit-scrollbar-thumb { background: var(--border); }

  /* Chat panel */
  .chat-panel { flex: 1; display: flex; flex-direction: column; min-width: 0; }

  #messages {
    flex: 1;
    overflow-y: auto;
    background: var(--surface);
    padding: 1rem 1.2rem;
    display: flex;
    flex-direction: column;
    gap: .72rem;
    scroll-behavior: smooth;
  }

  #messages::-webkit-scrollbar { width: 3px; }
  #messages::-webkit-scrollbar-thumb { background: rgba(0,229,255,.3); border-radius: 99px; }

  .msg {
    display: flex;
    flex-direction: column;
    max-width: 75%;
    animation: fadeUp .18s ease;
  }

  @keyframes fadeUp {
    from { opacity: 0; transform: translateY(6px); }
    to   { opacity: 1; transform: none; }
  }

  .msg.own   { align-self: flex-end;   align-items: flex-end; }
  .msg.other { align-self: flex-start; align-items: flex-start; }

  .msg-meta {
    font-size: .63rem;
    margin-bottom: .26rem;
    font-family: 'Share Tech Mono', monospace;
    letter-spacing: .04em;
  }

  .msg.own   .msg-meta { color: rgba(180,79,255,.6); }
  .msg.other .msg-meta { color: rgba(0,229,255,.5); }

  .msg-bubble {
    padding: .6rem .95rem;
    border-radius: var(--radius);
    font-size: .87rem;
    line-height: 1.55;
    word-break: break-word;
  }

  .msg.own .msg-bubble {
    background: rgba(180,79,255,.1);
    color: #e8d4ff;
    border: 1px solid rgba(180,79,255,.35);
    box-shadow: 0 0 10px rgba(180,79,255,.1);
  }

  .msg.other .msg-bubble {
    background: rgba(0,229,255,.05);
    color: var(--text);
    border: 1px solid rgba(0,229,255,.18);
    box-shadow: 0 0 8px rgba(0,229,255,.06);
  }

  /* DM bubble overrides */
  .msg.dm-msg .msg-bubble { border-style: dashed; }

  .msg.own.dm-msg .msg-bubble {
    background: rgba(255,45,120,.07);
    border-color: rgba(255,45,120,.3);
    color: #ffd4e3;
    box-shadow: 0 0 10px rgba(255,45,120,.1);
  }

  .msg.other.dm-msg .msg-bubble {
    background: rgba(255,45,120,.05);
    border-color: rgba(255,45,120,.2);
  }

  .dm-label {
    font-family: 'Share Tech Mono', monospace;
    font-size: .57rem;
    color: var(--neon-pink);
    opacity: .7;
    margin-bottom: .18rem;
    letter-spacing: .06em;
    text-transform: uppercase;
  }

  .enc-tag {
    font-family: 'Share Tech Mono', monospace;
    font-size: .55rem;
    color: var(--neon-green);
    opacity: .4;
    margin-top: .2rem;
    letter-spacing: .05em;
    text-transform: uppercase;
  }

  .empty-state {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: var(--muted);
    font-size: .78rem;
    gap: .55rem;
    font-family: 'Share Tech Mono', monospace;
    letter-spacing: .04em;
  }

  .empty-state .big { font-size: 2rem; filter: drop-shadow(0 0 8px rgba(0,229,255,.4)); }

  /* Input area */
  .input-area {
    background: var(--surface);
    border: 1px solid var(--border);
    border-top: 1px solid var(--surface2);
    padding: .75rem 1rem;
    display: flex;
    flex-direction: column;
    gap: .5rem;
    box-shadow: 0 -1px 0 rgba(0,229,255,.06);
  }

  .dm-indicator {
    display: none;
    align-items: center;
    gap: .5rem;
    font-family: 'Share Tech Mono', monospace;
    font-size: .66rem;
    color: var(--neon-pink);
    letter-spacing: .05em;
    text-transform: uppercase;
  }

  .dm-indicator.visible { display: flex; }

  .dm-target-label {
    background: rgba(255,45,120,.1);
    border: 1px solid rgba(255,45,120,.3);
    padding: .1rem .45rem;
    border-radius: 2px;
  }

  .dm-close {
    cursor: pointer;
    color: var(--muted);
    font-size: .85rem;
    margin-left: auto;
    transition: color .12s;
    line-height: 1;
  }

  .dm-close:hover { color: var(--neon-pink); }

  .input-row { display: flex; gap: .6rem; align-items: flex-end; }

  textarea {
    flex: 1;
    background: var(--bg);
    border: 1px solid var(--border);
    color: var(--text);
    font-family: 'Space Grotesk', sans-serif;
    font-size: .85rem;
    padding: .58rem .9rem;
    border-radius: var(--radius);
    outline: none;
    transition: border-color .2s, box-shadow .2s;
    resize: none;
    line-height: 1.5;
  }

  textarea:focus {
    border-color: var(--neon-cyan);
    box-shadow: 0 0 0 2px rgba(0,229,255,.08), 0 0 8px rgba(0,229,255,.12);
  }

  textarea::placeholder { color: var(--muted); font-family: 'Share Tech Mono', monospace; font-size: .76rem; }

  button {
    font-family: 'Share Tech Mono', monospace;
    font-size: .76rem;
    letter-spacing: .1em;
    text-transform: uppercase;
    cursor: pointer;
    border: none;
    border-radius: var(--radius);
    padding: .58rem 1rem;
    transition: all .15s;
  }

  .btn-send {
    background: transparent;
    color: var(--neon-pink);
    border: 1px solid var(--neon-pink);
    white-space: nowrap;
    box-shadow: 0 0 8px rgba(255,45,120,.18);
    align-self: flex-end;
    height: 37px;
  }

  .btn-send:hover {
    background: rgba(255,45,120,.1);
    box-shadow: 0 0 16px rgba(255,45,120,.35);
    transform: translateY(-1px);
  }

  .btn-send:disabled { opacity: .3; cursor: not-allowed; transform: none; box-shadow: none; }

  .footer-row { display: flex; justify-content: space-between; align-items: center; }

  .hint {
    font-family: 'Share Tech Mono', monospace;
    font-size: .58rem;
    color: var(--muted);
    letter-spacing: .03em;
  }

  .btn-clear {
    background: transparent;
    color: var(--muted);
    border: 1px solid rgba(255,255,255,.07);
    font-size: .63rem;
    padding: .26rem .6rem;
  }

  .btn-clear:hover { color: var(--neon-pink); border-color: rgba(255,45,120,.3); }

  /* Rainbow bottom bar */
  .bottombar {
    height: 3px;
    background: linear-gradient(90deg, var(--neon-cyan), var(--neon-purple), var(--neon-pink));
    opacity: .55;
    border-radius: 0 0 var(--radius) var(--radius);
  }

  /* Toast */
  #toast {
    position: fixed;
    bottom: 1.5rem; left: 50%;
    transform: translateX(-50%) translateY(20px);
    background: var(--surface2);
    border: 1px solid var(--neon-cyan);
    color: var(--neon-cyan);
    padding: .5rem 1.1rem;
    border-radius: var(--radius);
    font-size: .72rem;
    font-family: 'Share Tech Mono', monospace;
    letter-spacing: .06em;
    opacity: 0;
    transition: all .3s;
    pointer-events: none;
    z-index: 99;
    box-shadow: 0 0 14px rgba(0,229,255,.18);
  }

  #toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
</style>
</head>
<body>

<div id="loginScreen">
  <div class="login-logo">
    <span class="icon">🔐</span>
    <h1>Chat<span>Crypt</span></h1>
    <p>Encrypted · AES-256-CBC · Lo-Fi</p>
  </div>

  <div class="login-box">
    <div>
      <label for="loginName">Identificador</label>
      <input type="text" id="loginName" placeholder="tu_alias" maxlength="30" autocomplete="off" spellcheck="false">
    </div>
    <button class="btn-login" onclick="doLogin()">// Conectar</button>
  </div>

  <p class="login-hint">Solo necesitas un nombre · Sin contraseña · Sin registro</p>
</div>

<!-- APP -->
<div id="appScreen" class="hidden">

  <div class="topbar">
    <div class="topbar-left">
      <span class="topbar-logo">Chat<span>Crypt</span></span>
      <span class="topbar-channel" id="channelLabel"># general</span>
    </div>
    <div class="topbar-right">
      <span class="user-pill" id="meLabel"></span>
      <span class="badge">AES-256</span>
    </div>
  </div>

  <div class="app-body">

    <div class="sidebar">
      <div class="sidebar-section">Canales</div>
      <div class="sidebar-item active" id="tab-general" onclick="switchToGeneral()">
        <span class="channel-prefix">#</span>&nbsp;general
      </div>

      <div class="sidebar-section">En línea</div>
      <div class="sidebar-users" id="userList"></div>
    </div>

    <!-- Chat -->
    <div class="chat-panel">
      <div id="messages">
        <div class="empty-state" id="emptyState">
          <span class="big">🔒</span>
          <span id="emptyText">Sin mensajes aún</span>
          <span>Todo va encriptado</span>
        </div>
      </div>

      <div class="input-area">
        <div class="dm-indicator" id="dmIndicator">
          <span>DM →</span>
          <span class="dm-target-label" id="dmTargetLabel"></span>
          <span class="dm-close" onclick="clearDm()" title="Volver al general">✕</span>
        </div>
        <div class="input-row">
          <textarea id="msgInput" placeholder="Escribe un mensaje..." maxlength="500" rows="1"></textarea>
          <button class="btn-send" id="sendBtn" onclick="sendMessage()">Enviar</button>
        </div>
        <div class="footer-row">
          <span class="hint">↵ Enter enviar · Shift+Enter salto · Click en usuario → DM</span>
          <button class="btn-clear" onclick="clearChat()">🗑 Borrar</button>
        </div>
      </div>
    </div>

  </div>

  <div class="bottombar"></div>
</div>

<div id="toast"></div>

<script>
  let ME = '';
  let dmTarget  = null;
  let viewingDm = null;
  let cachedMsgs  = [];
  let cachedUsers = [];
  let lastCount = 0;

  const loginInput = document.getElementById('loginName');
  const saved = document.cookie.split('; ').find(r => r.startsWith('chat_user='));
  if (saved) loginInput.value = decodeURIComponent(saved.split('=')[1]);
  loginInput.addEventListener('keydown', e => { if (e.key === 'Enter') doLogin(); });

  function doLogin() {
    const name = loginInput.value.trim();
    if (!name) { loginInput.focus(); return; }
    ME = name;
    document.cookie = `chat_user=${encodeURIComponent(ME)};path=/;max-age=604800`;
    document.getElementById('loginScreen').classList.add('hidden');
    document.getElementById('appScreen').classList.remove('hidden');
    document.getElementById('meLabel').textContent = ME;
    initApp();
  }

  function initApp() {
    const ta = document.getElementById('msgInput');
    ta.addEventListener('input', () => {
      ta.style.height = 'auto';
      ta.style.height = Math.min(ta.scrollHeight, 120) + 'px';
    });
    ta.addEventListener('keydown', e => {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
    });
    poll();
    setInterval(poll, 2500);
    setInterval(sendHeartbeat, 8000);
    sendHeartbeat();
    ta.focus();
  }

  async function sendHeartbeat() {
    if (!ME) return;
    fetch('', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ action: 'heartbeat', user: ME })
    }).catch(() => {});
  }


  function startDm(target) {
    if (target === ME) { toast('No puedes enviarte un DM a ti mismo'); return; }
    dmTarget  = target;
    viewingDm = target;
    document.getElementById('dmTargetLabel').textContent = target;
    document.getElementById('dmIndicator').classList.add('visible');
    document.getElementById('channelLabel').textContent = '@ ' + target;
    document.getElementById('channelLabel').classList.add('dm-active');
    document.querySelectorAll('.sidebar-item').forEach(el => el.classList.remove('active'));
    const el = document.querySelector(`.sidebar-item[data-user="${target.replace(/"/g, '\\"')}"]`);
    if (el) el.classList.add('active');
    renderMessages();
    document.getElementById('msgInput').focus();
  }

  function clearDm() {
    dmTarget = null; viewingDm = null;
    document.getElementById('dmIndicator').classList.remove('visible');
    switchToGeneral();
  }

  function switchToGeneral() {
    dmTarget = null; viewingDm = null;
    document.getElementById('dmIndicator').classList.remove('visible');
    document.getElementById('channelLabel').textContent = '# general';
    document.getElementById('channelLabel').classList.remove('dm-active');
    document.querySelectorAll('.sidebar-item').forEach(el => el.classList.remove('active'));
    document.getElementById('tab-general').classList.add('active');
    renderMessages();
  }

  function renderUsers(users) {
    cachedUsers = users;
    const list = document.getElementById('userList');
    list.innerHTML = '';
    users.forEach(u => {
      if (u.name === ME) return;
      const div = document.createElement('div');
      div.className = 'sidebar-item' + (viewingDm === u.name ? ' active' : '');
      div.setAttribute('data-user', u.name);
      div.title = `Enviar DM a ${u.name}`;
      div.innerHTML = `<span class="dot"></span>${u.name}`;
      div.onclick = () => startDm(u.name);
      list.appendChild(div);
    });
  }

  function renderMessages() {
    const container = document.getElementById('messages');
    const empty = document.getElementById('emptyState');

    let msgs = cachedMsgs;

    if (viewingDm) {
      msgs = msgs.filter(m =>
        (m.dm === viewingDm && m.user === ME) ||
        (m.dm === ME && m.user === viewingDm)
      );
      document.getElementById('emptyText').textContent = `Sin mensajes con ${viewingDm}`;
    } else {
      msgs = msgs.filter(m => !m.dm);
      document.getElementById('emptyText').textContent = 'Sin mensajes aún';
    }

    if (!msgs.length) {
      container.innerHTML = '';
      container.appendChild(empty);
      return;
    }

    const atBottom = container.scrollHeight - container.scrollTop <= container.clientHeight + 60;
    container.innerHTML = '';

    msgs.forEach(m => {
      const isOwn = m.user === ME;
      const isDm  = !!m.dm;
      const div   = document.createElement('div');
      div.className = 'msg ' + (isOwn ? 'own' : 'other') + (isDm ? ' dm-msg' : '');
      div.innerHTML = `
        ${isDm && !viewingDm ? `<div class="dm-label">🔒 DM ${isOwn ? '→ ' + m.dm : '← ' + m.user}</div>` : ''}
        <div class="msg-meta">${m.user} &middot; ${m.time}</div>
        <div class="msg-bubble">${m.text}</div>
        <div class="enc-tag">🔒 aes-256</div>
      `;
      container.appendChild(div);
    });

    if (atBottom || lastCount === 0) container.scrollTop = container.scrollHeight;
    lastCount = msgs.length;
  }

  async function sendMessage() {
    const text = document.getElementById('msgInput').value.trim();
    if (!text) return;
    const btn = document.getElementById('sendBtn');
    btn.disabled = true;
    const body = { action: 'send', user: ME, text };
    if (dmTarget) body.dm = dmTarget;
    try {
      const res  = await fetch('', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body) });
      const data = await res.json();
      if (data.ok) {
        document.getElementById('msgInput').value = '';
        document.getElementById('msgInput').style.height = 'auto';
        await poll();
      } else {
        toast('Error: ' + (data.error || 'algo salió mal'));
      }
    } catch(e) { toast('Error de red'); }
    btn.disabled = false;
    document.getElementById('msgInput').focus();
  }

  async function clearChat() {
    if (!confirm('¿Borrar todos los mensajes?')) return;
    await fetch('', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ action: 'clear' }) });
    cachedMsgs = []; lastCount = 0;
    renderMessages();
    toast('Chat borrado');
}
  async function poll() {
    try {
      const res  = await fetch('?poll=1');
      const data = await res.json();
      cachedMsgs = data.messages;
      renderUsers(data.users);
      renderMessages();
    } catch(e) {}
  }


  function toast(msg, duration = 2500) {
    const el = document.getElementById('toast');
    el.textContent = msg;
    el.classList.add('show');
    setTimeout(() => el.classList.remove('show'), duration);
  }
</script>
</body>
</html>