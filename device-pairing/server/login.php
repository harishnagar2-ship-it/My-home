<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/_layout.php';

session_begin();
$error = null;
$next  = (string) ($_GET['next'] ?? $_POST['next'] ?? '');
// Only ever redirect to one of our own pages.
if (!preg_match('/^[a-z_]+\.php$/', $next)) {
    $next = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $pass  = (string) ($_POST['password'] ?? '');

    $stmt = db()->prepare('SELECT id, password_hash FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    if ($row && password_verify($pass, $row['password_hash'])) {
        login_user((int) $row['id']);
        if (!empty($_SESSION['pending_code'])) {
            $next = 'activate.php';       // finish the pairing that started this
        }
        header('Location: ' . ($next ?: 'devices.php'));
        exit;
    }
    // Same message for wrong email and wrong password — don't leak which.
    $error = 'Email or password is incorrect.';
}

page_top('Log in');
?>
<div class="card">
  <h1>Log in</h1>
  <p class="lead">
    <?php if (!empty($_SESSION['pending_code'])): ?>
      Your code <b><?= e($_SESSION['pending_code']) ?></b> is saved. Log in to finish pairing.
    <?php else: ?>
      Sign in to manage your screens.
    <?php endif; ?>
  </p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <label for="email">Email</label>
    <input id="email" name="email" type="email" required autocomplete="email"
           value="<?= e($_POST['email'] ?? '') ?>">
    <label for="password">Password</label>
    <input id="password" name="password" type="password" required autocomplete="current-password">
    <button type="submit">Log in</button>
  </form>
  <?php if ($error): ?><div class="msg bad"><?= e($error) ?></div><?php endif; ?>
  <p class="hint">New here? <a href="register.php">Create an account</a></p>
</div>
<?php page_bottom();
