<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/_layout.php';

/**
 * Create an account. The very first account on a fresh install becomes the
 * admin — that is how you bootstrap the broadcast portal with no seed data.
 * Every account after it is a normal user.
 */

session_begin();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $pass  = (string) ($_POST['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'That does not look like an email address.';
    } elseif (strlen($pass) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        $pdo = db();
        $isFirst = ((int) $pdo->query('SELECT COUNT(*) AS n FROM users')->fetch()['n']) === 0;
        try {
            $pdo->prepare('INSERT INTO users (email, password_hash, is_admin, created_at) VALUES (?, ?, ?, ?)')
                ->execute([$email, password_hash($pass, PASSWORD_DEFAULT), $isFirst ? 1 : 0, time()]);
            login_user((int) $pdo->lastInsertId());

            // A pairing code entered before registering picks up right here.
            $next = !empty($_SESSION['pending_code']) ? 'activate.php' : 'devices.php';
            header('Location: ' . $next);
            exit;
        } catch (PDOException $e) {
            $error = str_contains($e->getMessage(), 'UNIQUE')
                ? 'An account with that email already exists.'
                : 'Could not create the account.';
        }
    }
}

page_top('Register');
?>
<div class="card">
  <h1>Create an account</h1>
  <p class="lead">
    <?php if (!empty($_SESSION['pending_code'])): ?>
      Your code <b><?= e($_SESSION['pending_code']) ?></b> is saved. Create an account to finish pairing.
    <?php else: ?>
      One account controls all your screens.
    <?php endif; ?>
  </p>
  <form method="post">
    <?= csrf_field() ?>
    <label for="email">Email</label>
    <input id="email" name="email" type="email" required autocomplete="email"
           value="<?= e($_POST['email'] ?? '') ?>">
    <label for="password">Password</label>
    <input id="password" name="password" type="password" required minlength="8" autocomplete="new-password">
    <button type="submit">Create account</button>
  </form>
  <?php if ($error): ?><div class="msg bad"><?= e($error) ?></div><?php endif; ?>
  <p class="hint">Already have an account? <a href="login.php">Log in</a></p>
</div>
<?php page_bottom();
