<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/_layout.php';

/**
 * Code-first pairing.
 *
 *   1. Anyone can reach this page and type the code — no login wall.
 *   2. The code is checked. Wrong, expired or used → error, rate limited.
 *   3. Valid → held in the session.
 *   4. Not logged in → sent to login/register. The code waits.
 *   5. Back here after login → the code is RE-CHECKED from the database
 *      (it may have expired while they signed in) and then paired.
 *   6. "Connected."
 */

session_begin();
$user   = current_user();
$state  = 'enter';     // enter | connected | error
$error  = null;
$device = null;

$messages = [
    'bad_format'   => ['Wrong code', 'A code is 8 letters, like BDWP-HQPK.'],
    'not_found'    => ['Wrong code', 'That code does not match any device. Check the TV screen and try again.'],
    'expired'      => ['Code expired', 'That code is more than an hour old. Get a new one from your TV.'],
    'already_used' => ['Already used', 'This code has already activated a device.'],
    'rate_limited' => ['Too many tries', 'Wait five minutes before trying again.'],
    'expired_late' => ['Code expired while you were signing in', 'Get a fresh code from your TV and enter it again.'],
];

/** Bind the code to the logged-in account. Re-checks first — always. */
function complete_pairing(string $code, int $userId): array
{
    $check = check_code($code);
    if (!$check['ok']) {
        return ['ok' => false, 'error' => $check['error'] === 'expired' ? 'expired_late' : $check['error']];
    }
    db()->prepare("UPDATE device_codes SET status = 'paired', user_id = ?, paired_at = ? WHERE device_code = ?")
        ->execute([$userId, time(), $check['row']['device_code']]);
    return ['ok' => true, 'device' => $check['row']];
}

/* --- Step 2/3: a code was just submitted --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (rate_limited()) {
        $error = 'rate_limited';
    } else {
        $code  = normalise_code((string) ($_POST['code'] ?? ''));
        $check = check_code($code);

        if (!$check['ok']) {
            if (in_array($check['error'], ['bad_format', 'not_found'], true)) {
                record_attempt();        // only guesses count against the limit
            }
            $error = $check['error'];
        } elseif ($user) {
            $result = complete_pairing($code, $user['id']);
            if ($result['ok']) { $state = 'connected'; $device = $result['device']; }
            else               { $error = $result['error']; }
        } else {
            $_SESSION['pending_code'] = $code;
            header('Location: login.php?next=activate.php');
            exit;
        }
    }
}

/* --- Step 5: back from login with a code waiting --- */
elseif ($user && !empty($_SESSION['pending_code'])) {
    $code = (string) $_SESSION['pending_code'];
    unset($_SESSION['pending_code']);
    $result = complete_pairing($code, $user['id']);
    if ($result['ok']) { $state = 'connected'; $device = $result['device']; }
    else               { $error = $result['error']; }
}

if ($error !== null) {
    $state = 'error';
}

page_top('Activate a device', $user);

if ($state === 'connected'): ?>
<div class="card centre">
  <div class="tick">&#10003;</div>
  <h1>Connected</h1>
  <p class="lead"><b><?= e($device['device_name']) ?></b> has been paired to your account.
     Look at the TV — it should update within a few seconds.</p>
  <a class="btn" href="devices.php">See my devices</a>
</div>

<?php else: ?>
<div class="card">
  <h1>Activate your device</h1>
  <p class="lead">Type the code showing on your TV screen.
    <?php if (!$user): ?>You'll be asked to log in after.<?php endif; ?>
  </p>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <label for="code">Activation code</label>
    <input class="code" id="code" name="code" maxlength="9" placeholder="XXXX-XXXX"
           spellcheck="false" required autofocus
           value="<?= e($state === 'error' ? ($_POST['code'] ?? '') : '') ?>">
    <button type="submit">Connect device</button>
  </form>
  <?php if ($state === 'error'): [$t, $m] = $messages[$error] ?? ['Something went wrong', 'Please try again.']; ?>
    <div class="msg bad"><b><?= e($t) ?></b><?= e($m) ?></div>
  <?php endif; ?>
  <p class="hint">Codes use letters only — no numbers, no letter O or I.</p>
</div>
<script>
  const i = document.getElementById('code');
  i.addEventListener('input', () => {
    const r = i.value.toUpperCase().replace(/[^A-Z]/g, '').slice(0, 8);
    i.value = r.length > 4 ? r.slice(0, 4) + '-' + r.slice(4) : r;
  });
</script>
<?php endif;

page_bottom();
