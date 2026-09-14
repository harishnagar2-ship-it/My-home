<?php
/**
 * Your website's side. This is the page you drop into your existing PHP site.
 * In production, require the user to be logged in before showing this form.
 *
 * Example:
 *   session_start();
 *   if (empty($_SESSION['user_id'])) { header('Location: /login.php'); exit; }
 */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Activate your device</title>
<style>
  :root {
    color-scheme: light dark;
    --bg: #F4F6F7; --card: #FFFFFF; --ink: #16212A; --soft: #5C6D78;
    --line: #D8E0E4; --accent: #17667E; --ok: #1F7A54; --bad: #B23B23;
    --ok-bg: #E4F3EC; --bad-bg: #FBE8E3;
  }
  @media (prefers-color-scheme: dark) {
    :root {
      --bg: #0F1518; --card: #18222700; --ink: #E4ECEF; --soft: #9CB0B8;
      --line: #2A3840; --accent: #58AFC9; --ok: #5BC495; --bad: #E58467;
      --ok-bg: #142A22; --bad-bg: #331F1B;
    }
    :root { --card: #182227; }
  }
  * { box-sizing: border-box; }
  body {
    margin: 0; min-height: 100vh; background: var(--bg); color: var(--ink);
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    display: flex; align-items: center; justify-content: center; padding: 24px;
  }
  .card {
    background: var(--card); border: 1px solid var(--line); border-radius: 14px;
    padding: 34px 30px; width: 100%; max-width: 27rem;
  }
  h1 { font-size: 1.4rem; margin: 0 0 8px; }
  p.lead { color: var(--soft); margin: 0 0 24px; font-size: .9375rem; line-height: 1.55; }
  label { display: block; font-size: .75rem; font-weight: 700; letter-spacing: .08em;
          text-transform: uppercase; color: var(--soft); margin-bottom: 8px; }
  input {
    width: 100%; font-family: ui-monospace, "SF Mono", Menlo, monospace;
    font-size: 1.6rem; font-weight: 700; letter-spacing: .12em; text-align: center;
    padding: 15px 12px; border: 2px solid var(--line); border-radius: 10px;
    background: var(--bg); color: var(--ink); text-transform: uppercase;
  }
  input:focus { outline: none; border-color: var(--accent); }
  button {
    width: 100%; margin-top: 16px; padding: 14px; font-size: 1rem; font-weight: 600;
    background: var(--accent); color: #FFF; border: none; border-radius: 10px; cursor: pointer;
  }
  button:hover { filter: brightness(1.08); }
  button:disabled { opacity: .55; cursor: not-allowed; }
  button:focus-visible { outline: 2px solid var(--ink); outline-offset: 2px; }
  .msg { margin-top: 18px; padding: 14px 16px; border-radius: 10px;
         font-size: .9375rem; line-height: 1.5; }
  .msg.ok  { background: var(--ok-bg);  color: var(--ok);  border: 1px solid var(--ok); }
  .msg.bad { background: var(--bad-bg); color: var(--bad); border: 1px solid var(--bad); }
  .msg b { display: block; font-size: 1rem; margin-bottom: 3px; }
  .hint { margin-top: 18px; font-size: .8125rem; color: var(--soft); }
</style>
</head>
<body>

<div class="card">
  <h1>Activate your device</h1>
  <p class="lead">Type the code showing on your TV screen. It expires one hour after it appears.</p>

  <form id="form" autocomplete="off">
    <label for="code">Activation code</label>
    <input id="code" name="code" placeholder="XXXX-XXXX" maxlength="9"
           inputmode="latin" spellcheck="false" required>
    <button id="go" type="submit">Connect device</button>
  </form>

  <div id="msg"></div>
  <p class="hint">Codes use letters only — no numbers, no letter O or I.</p>
</div>

<script>
const form  = document.getElementById('form');
const input = document.getElementById('code');
const go    = document.getElementById('go');
const msg   = document.getElementById('msg');

// Keep the field tidy: letters only, dash inserted automatically.
input.addEventListener('input', () => {
  const raw = input.value.toUpperCase().replace(/[^A-Z]/g, '').slice(0, 8);
  input.value = raw.length > 4 ? raw.slice(0, 4) + '-' + raw.slice(4) : raw;
});

const show = (kind, title, text) => {
  msg.innerHTML = `<div class="msg ${kind}"><b>${title}</b>${text}</div>`;
};

const MESSAGES = {
  not_found:         ['Wrong code', 'That code does not match any device. Check the screen and try again.'],
  bad_format:        ['Wrong code', 'A code is 8 letters, like BDWP-HQPK.'],
  expired:           ['Code expired', 'That code is more than an hour old. Get a new one from your TV.'],
  already_used:      ['Already used', 'This code has already activated a device.'],
  too_many_attempts: ['Too many tries', 'Wait five minutes before trying again.'],
};

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  go.disabled = true;
  msg.innerHTML = '';

  try {
    const res  = await fetch('api.php?action=verify', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ user_code: input.value })
    });
    const data = await res.json();

    if (data.ok) {
      show('ok', 'Your device has been paired',
           `${data.device_name} is now connected. Look at your TV — it should start playing.`);
      input.value = '';
    } else {
      const [title, text] = MESSAGES[data.error] || ['Something went wrong', 'Please try again.'];
      show('bad', title, text);
    }
  } catch {
    show('bad', 'Connection problem', 'Could not reach the server. Check it is running.');
  } finally {
    go.disabled = false;
  }
});
</script>
</body>
</html>
