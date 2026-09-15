<?php
/**
 * Shared page chrome. Every browser-facing page calls page_top() and
 * page_bottom() so they look like one site and share one nav.
 *
 * To fold these pages into your existing website, replace these two
 * functions with your own header and footer includes.
 */

function page_top(string $title, ?array $user = null): void
{
    $user = $user ?? current_user();
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> — StreamBox</title>
<style>
  :root {
    color-scheme: light dark;
    --bg:#F2F5F6; --card:#FFF; --ink:#16212A; --soft:#5C6D78; --line:#D8E0E4;
    --accent:#17667E; --ok:#1F7A54; --bad:#B23B23; --ok-bg:#E4F3EC; --bad-bg:#FBE8E3;
  }
  @media (prefers-color-scheme: dark) {
    :root {
      --bg:#0F1518; --card:#182227; --ink:#E4ECEF; --soft:#9CB0B8; --line:#2A3840;
      --accent:#58AFC9; --ok:#5BC495; --bad:#E58467; --ok-bg:#142A22; --bad-bg:#331F1B;
    }
  }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--bg); color:var(--ink); min-height:100vh;
         font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; }
  nav { display:flex; flex-wrap:wrap; gap:6px 18px; align-items:center;
        padding:14px 20px; border-bottom:1px solid var(--line); font-size:.875rem; }
  nav .brand { font-weight:700; letter-spacing:.14em; text-transform:uppercase; color:var(--accent); margin-right:auto; }
  nav a { color:var(--soft); text-decoration:none; }
  nav a:hover, nav a[aria-current] { color:var(--ink); }
  main { max-width:30rem; margin:0 auto; padding:34px 20px 60px; }
  main.wide { max-width:56rem; }
  .card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:26px; }
  h1 { font-size:1.4rem; margin:0 0 8px; }
  h2 { font-size:1.0625rem; margin:0 0 12px; }
  p.lead { color:var(--soft); margin:0 0 22px; font-size:.9375rem; line-height:1.55; }
  label { display:block; font-size:.6875rem; font-weight:700; letter-spacing:.08em;
          text-transform:uppercase; color:var(--soft); margin:14px 0 6px; }
  label:first-of-type { margin-top:0; }
  input, select, textarea {
    width:100%; padding:11px 13px; font-size:.9375rem; font-family:inherit;
    border:1px solid var(--line); border-radius:8px; background:var(--bg); color:var(--ink);
  }
  input.code {
    font-family:ui-monospace,"SF Mono",Menlo,monospace; font-size:1.5rem; font-weight:700;
    letter-spacing:.12em; text-align:center; text-transform:uppercase; padding:14px 10px;
  }
  textarea { min-height:4.5rem; resize:vertical; }
  input:focus, select:focus, textarea:focus { outline:none; border-color:var(--accent); }
  button, .btn {
    display:inline-block; width:100%; margin-top:16px; padding:13px; font-size:.9375rem; font-weight:600;
    background:var(--accent); color:#FFF; border:none; border-radius:9px; cursor:pointer;
    text-align:center; text-decoration:none; font-family:inherit;
  }
  button:hover, .btn:hover { filter:brightness(1.08); }
  button.ghost { background:transparent; color:var(--bad); border:1px solid var(--bad); margin-top:9px; }
  button.small { width:auto; padding:7px 12px; font-size:.8125rem; margin:0; }
  button:focus-visible { outline:2px solid var(--ink); outline-offset:2px; }
  .msg { margin-top:16px; padding:12px 15px; border-radius:9px; font-size:.9375rem; line-height:1.5; }
  .msg.ok  { background:var(--ok-bg);  color:var(--ok);  border:1px solid var(--ok); }
  .msg.bad { background:var(--bad-bg); color:var(--bad); border:1px solid var(--bad); }
  .msg b { display:block; margin-bottom:2px; }
  .hint { margin-top:16px; font-size:.8125rem; color:var(--soft); line-height:1.5; }
  .hint a { color:var(--accent); }
  .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(19rem,1fr)); gap:18px; align-items:start; }
  .row { display:flex; align-items:center; gap:10px; padding:11px 0; border-bottom:1px solid var(--line); font-size:.9375rem; }
  .row:last-child { border-bottom:none; }
  .row .grow { flex:1; min-width:0; }
  .row .sub { display:block; color:var(--soft); font-size:.8125rem; }
  .pip { width:8px; height:8px; border-radius:50%; background:var(--soft); flex:none; }
  .pip.on { background:var(--ok); }
  .empty { color:var(--soft); font-size:.9375rem; padding:10px 0; }
  .inline { display:flex; gap:8px; align-items:center; }
  .inline input { flex:1; }
  .tick { font-size:2.4rem; color:var(--ok); line-height:1; }
  .centre { text-align:center; }
</style>
</head>
<body>
<nav>
  <span class="brand">StreamBox</span>
  <a href="activate.php">Activate a device</a>
  <?php if ($user): ?>
    <a href="devices.php">My devices</a>
    <?php if ($user['is_admin']): ?><a href="broadcast.php">Broadcast</a><?php endif; ?>
    <a href="logout.php">Log out (<?= e($user['email']) ?>)</a>
  <?php else: ?>
    <a href="login.php">Log in</a>
    <a href="register.php">Register</a>
  <?php endif; ?>
</nav>
<main>
    <?php
}

function page_bottom(): void
{
    echo "</main>\n</body>\n</html>\n";
}
