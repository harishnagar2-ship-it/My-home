const { chromium } = require('playwright-core');
const fs = require('fs');
const { execSync } = require('child_process');

const API = 'http://127.0.0.1:8000';
const DEV = 'http://127.0.0.1:8001';
const results = [];
const pass = (n, msg) => { results.push(['PASS', n, msg]); console.log(`PASS  ${n}  ${msg}`); };
const fail = (n, msg) => { results.push(['FAIL', n, msg]); console.log(`FAIL  ${n}  ${msg}`); };
const check = (n, cond, msg) => cond ? pass(n, msg) : fail(n, msg);

function forceExpire(userCode) {
  execSync(`cd /home/user/My-home/device-pairing/server && php -r '
    require "config.php";
    db()->prepare("UPDATE device_codes SET expires_at = ? WHERE user_code = ?")->execute([time()-10, $argv[1]]);' "${userCode}"`);
}

(async () => {
  const exe = fs.readdirSync('/opt/pw-browsers').find(d => d.startsWith('chromium-'));
  const browser = await chromium.launch({ executablePath: `/opt/pw-browsers/${exe}/chrome-linux/chrome`, args: ['--no-sandbox'] });

  const newTv = async (ctx) => {
    const tv = await ctx.newPage();
    await tv.goto(DEV + '/app.html');
    await tv.waitForSelector('.code', { timeout: 10000 });
    return { tv, code: (await tv.textContent('.code')).trim() };
  };
  const h1 = async (p) => ((await p.textContent('h1')) || '').trim();
  const waitH1 = (p, txt, t = 20000) => p.waitForFunction(x => (document.querySelector('h1')?.textContent || '').includes(x), txt, { timeout: t });

  /* ===================== USER A (becomes admin) ===================== */
  const ctxA = await browser.newContext({ viewport: { width: 1100, height: 850 } });
  const { tv: tvA, code: codeA } = await newTv(ctxA);
  check(1, /^[BCDFGHJKLMNPQRSTVWXZ]{4}-[BCDFGHJKLMNPQRSTVWXZ]{4}$/.test(codeA), `TV shows a code: ${codeA}`);

  const A = await ctxA.newPage();

  // Test 12 — logged OUT, wrong code
  await A.goto(API + '/activate.php');
  await A.fill('#code', 'ZZZZZZZZ');
  await A.click('button[type=submit]');
  await A.waitForSelector('.msg.bad');
  check(12, (await A.textContent('.msg.bad')).includes('Wrong code') && A.url().includes('activate.php'),
        'logged out + wrong code → error at code step, no login prompt');

  // Test 10 + 14 — logged OUT, correct code → asked to register → paired
  await A.fill('#code', codeA);
  await A.click('button[type=submit]');
  await A.waitForURL(/login\.php/);
  check(10, A.url().includes('login.php') && (await A.textContent('.lead')).includes(codeA),
        'logged out + correct code → sent to login with the code saved');

  await A.click('a[href="register.php"]');
  await A.waitForURL(/register\.php/);
  await A.fill('#email', 'alice@example.com');
  await A.fill('#password', 'correct-horse-battery');
  await A.click('button[type=submit]');
  await A.waitForURL(/activate\.php/);
  check(14, (await h1(A)) === 'Connected', 'registered mid-flow → Connected');

  await waitH1(tvA, 'Ready');
  check(3, true, 'TV flipped to Ready after pairing');

  await tvA.reload();
  await waitH1(tvA, 'Ready');
  check(8, true, 'TV reload → still paired, no new code');

  // Test 11 — logged IN, correct code → immediate
  const { tv: tvA2, code: codeA2 } = await newTv(await browser.newContext());
  await A.goto(API + '/activate.php');
  await A.fill('#code', codeA2);
  await A.click('button[type=submit]');
  await A.waitForSelector('h1');
  check(11, (await h1(A)) === 'Connected' && !A.url().includes('login'), 'logged in + correct code → paired with no login step');
  await waitH1(tvA2, 'Ready');

  // Test 4 — same code twice
  await A.goto(API + '/activate.php');
  await A.fill('#code', codeA2);
  await A.click('button[type=submit]');
  await A.waitForSelector('.msg.bad');
  check(4, (await A.textContent('.msg.bad')).includes('Already used'), 'same code twice → refused');

  /* ===================== TEST 13 — expired DURING login ===================== */
  const ctxC = await browser.newContext();
  const { tv: tvC, code: codeC } = await newTv(ctxC);
  const C = await ctxC.newPage();
  await C.goto(API + '/activate.php');
  await C.fill('#code', codeC);
  await C.click('button[type=submit]');
  await C.waitForURL(/login\.php/);
  forceExpire(codeC);                         // the code dies while they're at the login screen
  await C.fill('#email', 'alice@example.com');
  await C.fill('#password', 'correct-horse-battery');
  await C.click('button[type=submit]');
  await C.waitForURL(u => new URL(u).pathname.endsWith('/activate.php'));
  await C.waitForSelector('.msg.bad, h1');
  const msg13 = await C.textContent('.msg.bad').catch(() => '');
  if (!msg13.includes('expired while you were signing in')) {
    fs.writeFileSync('/tmp/t13-dump.html', await C.content());
    console.log('  [t13 debug] url=' + C.url() + ' h1=' + (await h1(C)) + ' msgbad=' + JSON.stringify(msg13));
    const dbrow = require('child_process').execSync(`cd /home/user/My-home/device-pairing/server && php -r 'require "config.php"; $s=db()->prepare("SELECT status,user_id,expires_at,(expires_at<strftime(%Y=1)) FROM device_codes WHERE user_code=?"); $s->execute([$argv[1]]); echo json_encode($s->fetch());' "${codeC}"`).toString();
    console.log('  [t13 debug] dbrow=' + dbrow);
  }
  check(13, msg13.includes('expired while you were signing in') && (await h1(C)) !== 'Connected',
        'code expired during login → refused with clear message, NOT paired');
  await tvC.close();

  /* ===================== USER B (normal user) ===================== */
  const ctxB = await browser.newContext();
  const { tv: tvB, code: codeB } = await newTv(ctxB);
  const B = await ctxB.newPage();
  await B.goto(API + '/register.php');
  await B.fill('#email', 'bob@example.com');
  await B.fill('#password', 'bobs-long-password');
  await B.click('button[type=submit]');
  await B.waitForURL(/devices\.php/);
  await B.goto(API + '/activate.php');
  await B.fill('#code', codeB);
  await B.click('button[type=submit]');
  await B.waitForSelector('h1');
  check(16, (await h1(B)) === 'Connected', 'user B pairs their own TV');
  await waitH1(tvB, 'Ready');

  // Test 17 — A cannot see B's devices, and cannot revoke by guessing the id
  await A.goto(API + '/devices.php');
  const aDevices = await A.textContent('main');
  const bCodeRow = execSync(`cd /home/user/My-home/device-pairing/server && php -r '
    require "config.php"; $s=db()->prepare("SELECT device_code FROM device_codes WHERE user_code=?"); $s->execute([$argv[1]]); echo $s->fetch()["device_code"];' "${codeB}"`).toString();
  const csrfA = await A.$eval('input[name=csrf]', el => el.value);
  const r17 = await A.request.post(API + '/devices.php', { form: { csrf: csrfA, device: bCodeRow, do: 'revoke' } });
  const bStill = execSync(`cd /home/user/My-home/device-pairing/server && php -r '
    require "config.php"; $s=db()->prepare("SELECT revoked_at FROM device_codes WHERE device_code=?"); $s->execute([$argv[1]]); var_dump($s->fetch()["revoked_at"]);' "${bCodeRow}"`).toString();
  check(17, !aDevices.includes('bob') && bStill.includes('NULL'), 'user A cannot see or revoke user B\'s device');

  // Test 26b — non-admin cannot reach the portal
  const r403 = await B.goto(API + '/broadcast.php');
  check(26.1, r403.status() === 403, 'non-admin user → broadcast portal returns 403');

  /* ===================== BROADCAST (as admin A) ===================== */
  await A.goto(API + '/broadcast.php');
  check(19.0, (await h1(A)).includes('Send a broadcast'), 'admin can open the portal');

  await A.fill('#title', 'Match starts in 10 minutes');
  await A.fill('#body', 'India vs Australia');
  await A.click('form[enctype] button[type=submit]');
  await A.waitForSelector('.msg.ok');
  await waitH1(tvA, 'Match starts');
  await waitH1(tvB, 'Match starts');
  check(19, true, 'message broadcast reached both paired TVs');

  // Test 23 — XSS
  await A.fill('#title', '<img src=x onerror=alert(1)>XSS');
  await A.click('form[enctype] button[type=submit]');
  await A.waitForSelector('.msg.ok');
  await waitH1(tvA, 'XSS');
  const imgs = await tvA.evaluate(() => document.querySelectorAll('h1 img').length);
  check(23, imgs === 0, 'script injection in broadcast title rendered as text, not executed');

  /* ===================== UPLOAD SECURITY ===================== */
  await A.setInputFiles('#media', '/tmp/evil.mp4');
  await A.click('form[enctype] button[type=submit]');
  await A.waitForSelector('.msg.bad, .msg.ok');
  const m24 = await A.textContent('.msg.bad').catch(() => '');
  check(24, m24.includes('Only MP4') && m24.includes('text/x-php'), `.php renamed .mp4 → rejected (${m24.slice(0, 70)})`);

  await A.setInputFiles('#media', '/tmp/fake.mp4');
  await A.click('form[enctype] button[type=submit]');
  await A.waitForSelector('.msg.bad, .msg.ok');
  const m24b = await A.textContent('.msg.bad').catch(() => '');
  check(24.1, m24b.includes('Only MP4'), 'text file renamed .mp4 → rejected');

  await A.setInputFiles('#media', '/tmp/big.mp4');
  await A.click('form[enctype] button[type=submit]');
  await A.waitForSelector('.msg.bad, .msg.ok');
  const m25 = await A.textContent('.msg.bad').catch(() => '');
  check(25, m25.includes('too large'), 'file over 10 MB → rejected server-side');

  await A.setInputFiles('#media', '/tmp/real.mp4');
  await A.fill('#title', 'Uploaded clip');
  await A.click('form[enctype] button[type=submit]');
  await A.waitForSelector('.msg.ok, .msg.bad');
  const okUp = await A.$('.msg.ok');
  await waitH1(tvA, 'Uploaded clip');
  const vsrc = await tvA.evaluate(() => document.querySelector('video')?.src || '');
  check(21, !!okUp && /^https?:\/\/(localhost|127\.0\.0\.1):8000\/uploads\/[0-9a-f]{32}\.mp4$/.test(vsrc), `real mp4 → accepted, TV plays from ${vsrc}`);
  const stored = fs.readdirSync('/home/user/My-home/device-pairing/server/uploads').filter(f => f.endsWith('.mp4'));
  check(21.1, stored.length === 1 && /^[0-9a-f]{32}\.mp4$/.test(stored[0]), `stored under a random name: ${stored[0]}`);

  // Test 26 — logged-out POST to the portal
  const ctxOut = await browser.newContext();
  const outPage = await ctxOut.newPage();
  const r26 = await outPage.request.post(API + '/broadcast.php', { form: { type: 'message', title: 'sneaky' }, maxRedirects: 0 });
  const liveNow = execSync(`cd /home/user/My-home/device-pairing/server && php -r 'require "config.php"; echo current_broadcast()["title"];'`).toString();
  check(26, r26.status() === 302 && liveNow !== 'sneaky', `logged-out POST to portal → redirected (${r26.status()}), nothing broadcast`);

  // Stop → idle
  await A.click('form:not([enctype]) button.ghost');
  await A.waitForSelector('.msg.ok');
  await waitH1(tvA, 'Ready');
  check(22, true, 'stop broadcast → TVs back to Ready');

  /* ===================== REVOKE ===================== */
  await B.goto(API + '/devices.php');
  await B.click('button.ghost');
  await B.waitForSelector('.msg.ok');
  await tvB.waitForSelector('.code', { timeout: 25000 });
  check(18, true, 'revoked device forgot its pairing and is asking for a new code');

  /* ===================== RATE LIMIT ===================== */
  const ctxR = await browser.newContext();
  const R = await ctxR.newPage();
  let limited = false;
  for (let i = 0; i < 12; i++) {
    await R.goto(API + '/activate.php');
    await R.fill('#code', 'ZZZZZZZZ');
    await R.click('button[type=submit]');
    await R.waitForSelector('.msg.bad');
    if ((await R.textContent('.msg.bad')).includes('Too many')) { limited = true; break; }
  }
  check(6, limited, '10+ wrong codes from one IP → rate limited');

  /* ===================== CORS ===================== */
  const cOk  = execSync(`curl -s -i -X POST "${API}/api.php?action=new" -H "Origin: ${DEV}" -H 'Content-Type: application/json' -d '{}' | grep -i "access-control-allow-origin" || true`).toString();
  const cBad = execSync(`curl -s -i -X POST "${API}/api.php?action=new" -H "Origin: https://evil.example.com" -H 'Content-Type: application/json' -d '{}' | grep -i "access-control-allow-origin" || true`).toString();
  check(27, cOk.includes(DEV), 'allowed origin gets CORS header');
  check(28, cBad.trim() === '', 'unlisted origin gets no CORS header');

  /* ===================== CSRF ===================== */
  const rCsrf = await A.request.post(API + '/activate.php', { form: { code: 'BDWPHQPK' } });
  check(29, rCsrf.status() === 403, 'POST without CSRF token → 403');

  await A.screenshot({ path: '/tmp/f-portal.png', fullPage: true });
  await browser.close();

  const failed = results.filter(r => r[0] === 'FAIL');
  console.log(`\n${results.length - failed.length}/${results.length} passed${failed.length ? ' — FAILURES: ' + failed.map(f => f[1]).join(', ') : ''}`);
  process.exit(failed.length ? 1 : 0);
})().catch(e => { console.error('CRASH', e.message); process.exit(2); });
