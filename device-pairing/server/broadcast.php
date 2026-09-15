<?php
/**
 * Broadcast portal. Whatever you send here appears on every paired screen
 * within a few seconds.
 *
 * Protected by ADMIN_KEY in config.php for the prototype. In production,
 * delete the key box and put this behind your real admin login:
 *
 *   session_start();
 *   if (empty($_SESSION['is_admin'])) { header('Location: /login.php'); exit; }
 */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Broadcast Portal</title>
<style>
  :root {
    color-scheme: light dark;
    --bg:#F2F5F6; --card:#FFF; --ink:#16212A; --soft:#5C6D78; --line:#D8E0E4;
    --accent:#17667E; --ok:#1F7A54; --bad:#B23B23; --ok-bg:#E4F3EC; --bad-bg:#FBE8E3;
    --live:#1F7A54;
  }
  @media (prefers-color-scheme: dark) {
    :root {
      --bg:#0F1518; --card:#182227; --ink:#E4ECEF; --soft:#9CB0B8; --line:#2A3840;
      --accent:#58AFC9; --ok:#5BC495; --bad:#E58467; --ok-bg:#142A22; --bad-bg:#331F1B;
      --live:#5BC495;
    }
  }
  * { box-sizing:border-box; }
  body {
    margin:0; background:var(--bg); color:var(--ink); min-height:100vh;
    font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
    padding:28px 20px 60px;
  }
  .page { max-width:56rem; margin:0 auto; }
  h1 { font-size:1.5rem; margin:0 0 4px; }
  .sub { color:var(--soft); font-size:.9375rem; margin:0 0 26px; }
  .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(19rem,1fr)); gap:18px; align-items:start; }
  .card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:22px; }
  h2 { font-size:1.0625rem; margin:0 0 14px; }
  label { display:block; font-size:.6875rem; font-weight:700; letter-spacing:.08em;
          text-transform:uppercase; color:var(--soft); margin:14px 0 6px; }
  label:first-of-type { margin-top:0; }
  input, select, textarea {
    width:100%; padding:10px 12px; font-size:.9375rem; font-family:inherit;
    border:1px solid var(--line); border-radius:8px; background:var(--bg); color:var(--ink);
  }
  textarea { min-height:5rem; resize:vertical; }
  input:focus, select:focus, textarea:focus { outline:none; border-color:var(--accent); }
  button {
    width:100%; margin-top:18px; padding:13px; font-size:.9375rem; font-weight:600;
    background:var(--accent); color:#FFF; border:none; border-radius:9px; cursor:pointer;
  }
  button:hover { filter:brightness(1.08); }
  button.ghost { background:transparent; color:var(--bad); border:1px solid var(--bad); margin-top:9px; }
  button:focus-visible { outline:2px solid var(--ink); outline-offset:2px; }
  .msg { margin-top:14px; padding:11px 14px; border-radius:8px; font-size:.875rem; }
  .msg.ok { background:var(--ok-bg); color:var(--ok); }
  .msg.bad { background:var(--bad-bg); color:var(--bad); }
  .dev { display:flex; align-items:center; gap:10px; padding:10px 0; border-bottom:1px solid var(--line); font-size:.9375rem; }
  .dev:last-child { border-bottom:none; }
  .pip { width:8px; height:8px; border-radius:50%; background:var(--soft); flex:none; }
  .pip.on { background:var(--live); box-shadow:0 0 0 3px color-mix(in srgb, var(--live) 22%, transparent); }
  .dev .when { margin-left:auto; color:var(--soft); font-size:.8125rem; }
  .empty { color:var(--soft); font-size:.9375rem; padding:10px 0; }
  .livebox { background:var(--ok-bg); border:1px solid var(--ok); border-radius:9px; padding:13px 15px; font-size:.875rem; }
  .livebox b { display:block; color:var(--ok); margin-bottom:3px; }
  .hint { font-size:.8125rem; color:var(--soft); margin-top:10px; line-height:1.5; }
</style>
</head>
<body>
<div class="page">
  <h1>Broadcast Portal</h1>
  <p class="sub">Anything you send appears on every paired screen within about 8 seconds.</p>

  <div class="grid">

    <div class="card">
      <h2>Send a broadcast</h2>

      <label for="key">Admin key</label>
      <input id="key" type="password" placeholder="ADMIN_KEY from config.php">

      <label for="type">What to show</label>
      <select id="type">
        <option value="message">Message on screen</option>
        <option value="video">Play a video (HLS or MP4)</option>
        <option value="image">Show an image</option>
      </select>

      <label for="title">Title</label>
      <input id="title" placeholder="Match starts in 10 minutes">

      <label for="body">Message</label>
      <textarea id="body" placeholder="Shown under the title"></textarea>

      <label for="url">Media URL <span style="text-transform:none;font-weight:400">— for video or image</span></label>
      <input id="url" placeholder="https://example.com/stream.m3u8">

      <button id="send">Broadcast now</button>
      <button id="stop" class="ghost">Stop broadcast</button>
      <div id="msg"></div>
    </div>

    <div class="card">
      <h2>Live now</h2>
      <div id="live"><div class="empty">Nothing is being broadcast.</div></div>

      <h2 style="margin-top:24px">Paired devices</h2>
      <div id="devices"><div class="empty">Enter your admin key to load devices.</div></div>
      <p class="hint">A green dot means that screen checked in within the last 30 seconds.</p>
    </div>

  </div>
</div>

<script>
const $ = id => document.getElementById(id);
const msg = (kind, text) => { $('msg').innerHTML = '<div class="msg ' + kind + '">' + text + '</div>'; };

// Remember the key for this tab only, so you're not retyping it constantly.
$('key').value = sessionStorage.getItem('adminKey') || '';
$('key').addEventListener('input', () => sessionStorage.setItem('adminKey', $('key').value));

const api = (action, body) =>
  fetch('api.php?action=' + action, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body)
  }).then(r => r.json()).catch(() => ({ ok: false, error: 'network' }));

async function send(type) {
  const key = $('key').value.trim();
  if (!key) return msg('bad', 'Enter your admin key first.');

  const data = await api('send', {
    admin_key: key,
    type,
    title: $('title').value,
    body: $('body').value,
    media_url: $('url').value.trim()
  });

  if (data.ok) {
    msg('ok', type === 'off' ? 'Broadcast stopped. Screens return to idle.' : 'Sent to all paired screens.');
    refresh();
  } else if (data.error === 'unauthorised') {
    msg('bad', 'Wrong admin key.');
  } else if (data.error === 'bad_url') {
    msg('bad', 'That media URL is not valid. Include https://');
  } else {
    msg('bad', 'Could not send. Is the server running?');
  }
}

$('send').addEventListener('click', () => send($('type').value));
$('stop').addEventListener('click', () => send('off'));

function ago(ts) {
  if (!ts) return 'never';
  const s = Math.floor(Date.now() / 1000) - ts;
  if (s < 60)   return s + 's ago';
  if (s < 3600) return Math.floor(s / 60) + 'm ago';
  return Math.floor(s / 3600) + 'h ago';
}

async function refresh() {
  const key = $('key').value.trim();
  if (!key) return;

  const data = await api('devices', { admin_key: key });
  if (!data.ok) return;

  $('live').innerHTML = data.live
    ? '<div class="livebox"><b>' + (data.live.type).toUpperCase() + '</b>' +
      (data.live.title || '(no title)') + '</div>'
    : '<div class="empty">Nothing is being broadcast.</div>';

  $('devices').innerHTML = data.devices.length
    ? data.devices.map(d =>
        '<div class="dev"><span class="pip ' + (d.online ? 'on' : '') + '"></span>' +
        '<span>' + d.device_name + '</span>' +
        '<span class="when">' + ago(d.last_seen) + '</span></div>'
      ).join('')
    : '<div class="empty">No devices paired yet.</div>';
}

refresh();
setInterval(refresh, 5000);
</script>
</body>
</html>
