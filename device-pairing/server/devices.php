<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/_layout.php';

$user = require_login('devices.php');
$note = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $deviceCode = (string) ($_POST['device'] ?? '');
    $do         = (string) ($_POST['do'] ?? '');

    // Every write is scoped to THIS user — you cannot touch someone else's screen
    // by guessing its id.
    if ($do === 'rename') {
        $name = substr(trim((string) ($_POST['name'] ?? '')), 0, 60);
        if ($name !== '') {
            db()->prepare('UPDATE device_codes SET device_name = ? WHERE device_code = ? AND user_id = ?')
                ->execute([$name, $deviceCode, $user['id']]);
            $note = 'Renamed.';
        }
    } elseif ($do === 'revoke') {
        db()->prepare('UPDATE device_codes SET revoked_at = ? WHERE device_code = ? AND user_id = ? AND revoked_at IS NULL')
            ->execute([time(), $deviceCode, $user['id']]);
        $note = 'Device disconnected. It will stop receiving broadcasts within a few seconds.';
    }
}

$stmt = db()->prepare(
    "SELECT device_code, device_name, paired_at, last_seen, revoked_at
       FROM device_codes
      WHERE user_id = ? AND status = 'paired'
   ORDER BY paired_at DESC"
);
$stmt->execute([$user['id']]);
$devices = $stmt->fetchAll();

function ago(?int $ts): string
{
    if (!$ts) return 'never';
    $s = time() - $ts;
    if ($s < 60)   return $s . 's ago';
    if ($s < 3600) return intdiv($s, 60) . 'm ago';
    if ($s < 86400) return intdiv($s, 3600) . 'h ago';
    return intdiv($s, 86400) . 'd ago';
}

page_top('My devices', $user);
?>
<div class="card">
  <h1>My devices</h1>
  <p class="lead">Screens paired to <b><?= e($user['email']) ?></b>.</p>

  <?php if ($note): ?><div class="msg ok" style="margin:0 0 14px"><?= e($note) ?></div><?php endif; ?>

  <?php if (!$devices): ?>
    <div class="empty">No screens yet. <a href="activate.php">Activate one</a>.</div>
  <?php else: foreach ($devices as $d):
      $online = $d['revoked_at'] === null && $d['last_seen'] !== null && (time() - (int) $d['last_seen']) < 30; ?>
    <div class="row">
      <span class="pip <?= $online ? 'on' : '' ?>"></span>
      <div class="grow">
        <form method="post" class="inline">
          <?= csrf_field() ?>
          <input type="hidden" name="device" value="<?= e($d['device_code']) ?>">
          <input type="hidden" name="do" value="rename">
          <input name="name" value="<?= e($d['device_name']) ?>" maxlength="60" aria-label="Device name">
          <button class="small" type="submit">Rename</button>
        </form>
        <span class="sub">
          <?php if ($d['revoked_at'] !== null): ?>
            Disconnected <?= e(ago((int) $d['revoked_at'])) ?>
          <?php else: ?>
            Paired <?= e(ago((int) $d['paired_at'])) ?> · seen <?= e(ago($d['last_seen'] !== null ? (int) $d['last_seen'] : null)) ?>
          <?php endif; ?>
        </span>
      </div>
      <?php if ($d['revoked_at'] === null): ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="device" value="<?= e($d['device_code']) ?>">
        <input type="hidden" name="do" value="revoke">
        <button class="small ghost" type="submit" style="margin:0">Disconnect</button>
      </form>
      <?php endif; ?>
    </div>
  <?php endforeach; endif; ?>

  <a class="btn" href="activate.php">Activate another device</a>
</div>
<?php page_bottom();
