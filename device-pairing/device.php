<?php
/**
 * The "TV". Open this in one tab, full screen.
 * It asks for a code, shows it, and polls until your website pairs it.
 */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>StreamBox — Device Setup</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/hls.js/1.5.17/hls.min.js"></script>
<style>
  :root { color-scheme: dark; }
  * { box-sizing: border-box; }
  body {
    margin: 0; min-height: 100vh;
    background: #0B1015; color: #E8EEF2;
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    display: flex; align-items: center; justify-content: center;
    padding: 24px;
  }
  .screen { width: 100%; max-width: 760px; text-align: center; }
  .brand {
    font-size: .75rem; letter-spacing: .3em; text-transform: uppercase;
    color: #5AA9C7; font-weight: 700;
  }
  h1 { font-size: clamp(1.4rem, 4vw, 2.1rem); font-weight: 600; margin: 14px 0 6px; }
  .lead { color: #9AAAB4; font-size: 1rem; margin: 0 0 30px; }
  .lead b { color: #E8EEF2; }

  .code {
    font-family: ui-monospace, "SF Mono", Menlo, monospace;
    font-size: clamp(2.4rem, 11vw, 4.6rem);
    font-weight: 700; letter-spacing: .1em;
    background: #131C24; border: 2px solid #23323D; border-radius: 12px;
    padding: 22px 16px; margin: 0 auto; max-width: 560px;
    word-break: break-all;
  }
  .expiry { color: #7E9099; font-size: .875rem; margin-top: 14px; }

  .status {
    display: inline-flex; align-items: center; gap: 10px;
    margin-top: 28px; font-size: .9375rem; color: #9AAAB4;
  }
  .dot {
    width: 9px; height: 9px; border-radius: 50%; background: #5AA9C7;
    animation: pulse 1.4s ease-in-out infinite;
  }
  @keyframes pulse { 0%,100% { opacity: .25 } 50% { opacity: 1 } }
  @media (prefers-reduced-motion: reduce) { .dot { animation: none; opacity: .8 } }

  .ok .code { border-color: #2E7D5B; background: #10231B; }
  .tick { font-size: 3rem; color: #4FC08D; line-height: 1; }
  h2 { font-size: 1.5rem; margin: 14px 0 6px; }

  video {
    width: 100%; max-width: 100%; margin-top: 26px;
    border-radius: 10px; background: #000; aspect-ratio: 16/9;
  }
  .err { color: #E5876A; }
  button {
    margin-top: 20px; background: #1B4A5C; color: #E8EEF2;
    border: 1px solid #2B6B83; border-radius: 8px;
    padding: 11px 20px; font-size: .9375rem; cursor: pointer;
  }
  button:hover { background: #235E75; }
  button:focus-visible { outline: 2px solid #5AA9C7; outline-offset: 2px; }
</style>
</head>
<body>

<div class="screen" id="screen">
  <div class="brand">StreamBox</div>
  <h1>Loading…</h1>
</div>

<script>
const TEST_STREAM = 'https://stream.mux.com/v69RSHhFejbClaDbdF5Wvs72OgVwGyAHcbcpjYCTUaeg.m3u8';
const screen = document.getElementById('screen');

let deviceCode = null;
let interval   = 5;
let expiresAt  = 0;
let timer      = null;

const api = (action, body) =>
  fetch('api.php?action=' + action, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body || {})
  }).then(async r => ({ status: r.status, data: await r.json() }));

function renderCode(userCode) {
  screen.className = 'screen';
  screen.innerHTML = `
    <div class="brand">StreamBox</div>
    <h1>Enter this code to activate</h1>
    <p class="lead">On your phone or computer, go to <b>your website</b> and type the code below.</p>
    <div class="code">${userCode}</div>
    <div class="expiry" id="expiry"></div>
    <div class="status"><span class="dot"></span><span>Waiting for activation…</span></div>
  `;
  tickExpiry();
}

function tickExpiry() {
  const el = document.getElementById('expiry');
  if (!el) return;
  const left = Math.max(0, expiresAt - Math.floor(Date.now() / 1000));
  const m = String(Math.floor(left / 60)).padStart(2, '0');
  const s = String(left % 60).padStart(2, '0');
  el.textContent = left > 0 ? `This code expires in ${m}:${s}` : 'Code expired — getting a new one…';
}

function renderPaired(name) {
  clearInterval(timer);
  screen.className = 'screen ok';
  screen.innerHTML = `
    <div class="brand">StreamBox</div>
    <div class="tick">✓</div>
    <h2>Your device has been paired</h2>
    <p class="lead">Signed in as <b>${name || 'this device'}</b>. Starting playback…</p>
    <video id="player" controls autoplay muted playsinline></video>
  `;
  startPlayback();
}

function startPlayback() {
  const video = document.getElementById('player');
  if (!video) return;
  if (window.Hls && Hls.isSupported()) {
    const hls = new Hls();
    hls.loadSource(TEST_STREAM);
    hls.attachMedia(video);
    hls.on(Hls.Events.ERROR, () => { video.poster = ''; });
  } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
    video.src = TEST_STREAM;
  }
}

async function requestCode() {
  const { data } = await api('new', { device_name: 'Living Room TV' });
  deviceCode = data.device_code;
  interval   = data.interval || 5;
  expiresAt  = Math.floor(Date.now() / 1000) + data.expires_in;
  renderCode(data.user_code);

  clearInterval(timer);
  timer = setInterval(tickExpiry, 1000);
  setTimeout(poll, interval * 1000);
}

async function poll() {
  if (!deviceCode) return;
  const { data } = await api('poll', { device_code: deviceCode });

  if (data.status === 'paired')          return renderPaired(data.device_name);
  if (data.error === 'expired_token')    return requestCode();
  if (data.error === 'access_denied')    {
    screen.innerHTML = '<div class="brand">StreamBox</div><h1 class="err">Activation refused</h1>';
    return;
  }
  setTimeout(poll, interval * 1000); // authorization_pending
}

requestCode();
</script>
</body>
</html>
