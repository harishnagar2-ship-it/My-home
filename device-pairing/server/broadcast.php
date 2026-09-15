<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/_layout.php';

/**
 * Broadcast portal. Admin session required — the first account registered
 * on a fresh install is the admin.
 *
 * Three ways to put media on screen:
 *   paste a URL       any size, hosted wherever it already is
 *   upload a clip     under UPLOAD_MAX_BYTES, stored in uploads/
 *   (large films)     use a free external host and paste the URL
 */

$user = require_admin();
$note = null;
$bad  = null;

const ALLOWED_TYPES = [
    'video/mp4'  => 'mp4',
    'video/webm' => 'webm',
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
];

/**
 * Accept one uploaded file, safely. Returns the relative path to store, or an
 * error string. Every check here is server-side; the browser's opinion of the
 * file's type and size is not consulted.
 */
function accept_upload(array $f): array
{
    if ($f['error'] === UPLOAD_ERR_NO_FILE) return ['ok' => false, 'error' => null];
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'error' => 'That file is too large. The limit is ' . intdiv(UPLOAD_MAX_BYTES, 1024 * 1024) . ' MB.'];
    }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        return ['ok' => false, 'error' => 'Upload failed. Try again.'];
    }
    if ($f['size'] > UPLOAD_MAX_BYTES) {
        return ['ok' => false, 'error' => 'That file is too large. The limit is ' . intdiv(UPLOAD_MAX_BYTES, 1024 * 1024) . ' MB.'];
    }

    // The real type comes from the bytes, never from the name or the header.
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
    if (!isset(ALLOWED_TYPES[$mime])) {
        return ['ok' => false, 'error' => 'Only MP4, WebM, JPG and PNG files are accepted. This file is ' . $mime . '.'];
    }

    // Random name. The uploaded name is never used for anything.
    $name = bin2hex(random_bytes(16)) . '.' . ALLOWED_TYPES[$mime];
    $dest = __DIR__ . '/uploads/' . $name;
    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        return ['ok' => false, 'error' => 'Could not save the file. Is the uploads folder writable?'];
    }
    chmod($dest, 0644);
    return ['ok' => true, 'path' => 'uploads/' . $name, 'kind' => str_starts_with($mime, 'video/') ? 'video' : 'image'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && empty($_POST)
    && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    // PHP dropped the whole body because it exceeded post_max_size, so the
    // CSRF token is gone too. Say what actually happened instead of "bad token".
    $bad = 'That file is too large for this server to accept. '
         . 'The limit is ' . intdiv(UPLOAD_MAX_BYTES, 1024 * 1024) . ' MB, '
         . 'and the server may cap it lower still. Host large video elsewhere and paste the URL.';
}
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $type  = (string) ($_POST['type'] ?? 'message');
    $title = substr(trim((string) ($_POST['title'] ?? '')), 0, 120);
    $body  = substr(trim((string) ($_POST['body'] ?? '')), 0, 600);
    $url   = trim((string) ($_POST['media_url'] ?? ''));

    if (!in_array($type, ['message', 'video', 'image', 'off'], true)) {
        $bad = 'Unknown broadcast type.';
    } else {
        // An uploaded file wins over a pasted URL, and sets the type itself.
        if (!empty($_FILES['media']) && $_FILES['media']['error'] !== UPLOAD_ERR_NO_FILE) {
            $up = accept_upload($_FILES['media']);
            if (!$up['ok']) {
                $bad = $up['error'];
            } else {
                $url  = $up['path'];
                $type = $up['kind'];
            }
        } elseif (($type === 'video' || $type === 'image')) {
            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
                $bad = 'Paste a full https:// URL, or upload a file.';
            }
        }

        if ($bad === null) {
            db()->prepare('INSERT INTO broadcasts (type, title, body, media_url, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$type, $title, $body, $url, $user['id'], time()]);
            $note = $type === 'off' ? 'Broadcast stopped. Screens return to idle.' : 'Sent to every paired screen.';
        }
    }
}

$live = current_broadcast();
$devs = db()->query(
    "SELECT d.device_name, d.paired_at, d.last_seen, u.email
       FROM device_codes d LEFT JOIN users u ON u.id = d.user_id
      WHERE d.status = 'paired' AND d.revoked_at IS NULL
   ORDER BY d.paired_at DESC LIMIT 100"
)->fetchAll();

page_top('Broadcast', $user);
?>
<div class="grid" style="max-width:56rem;margin:0 auto">

  <div class="card">
    <h1>Send a broadcast</h1>
    <p class="lead">Appears on every paired screen within about 8 seconds.</p>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="MAX_FILE_SIZE" value="<?= UPLOAD_MAX_BYTES ?>">

      <label for="type">What to show</label>
      <select id="type" name="type">
        <option value="message">Message on screen</option>
        <option value="video">Video from a URL</option>
        <option value="image">Image from a URL</option>
      </select>

      <label for="title">Title</label>
      <input id="title" name="title" placeholder="Match starts in 10 minutes">

      <label for="body">Message</label>
      <textarea id="body" name="body" placeholder="Shown under the title"></textarea>

      <label for="media_url">Media URL</label>
      <input id="media_url" name="media_url" placeholder="https://example.com/clip.mp4">

      <label for="media">Or upload a file <span style="text-transform:none;font-weight:400">— MP4, WebM, JPG, PNG, up to <?= intdiv(UPLOAD_MAX_BYTES, 1024*1024) ?> MB</span></label>
      <input id="media" name="media" type="file" accept="video/mp4,video/webm,image/jpeg,image/png">

      <button type="submit">Broadcast now</button>
    </form>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="type" value="off">
      <button class="ghost" type="submit">Stop broadcast</button>
    </form>
    <?php if ($note): ?><div class="msg ok"><?= e($note) ?></div><?php endif; ?>
    <?php if ($bad):  ?><div class="msg bad"><?= e($bad) ?></div><?php endif; ?>
    <p class="hint">A full-length film will not fit the upload limit on free hosting.
       Host it on a free video service and paste the URL instead.</p>
  </div>

  <div class="card">
    <h2>Live now</h2>
    <?php if ($live): ?>
      <div class="msg ok" style="margin:0 0 20px"><b><?= e(strtoupper($live['type'])) ?></b><?= e($live['title'] ?: '(no title)') ?></div>
    <?php else: ?>
      <div class="empty" style="margin-bottom:12px">Nothing is being broadcast.</div>
    <?php endif; ?>

    <h2>Paired screens</h2>
    <?php if (!$devs): ?>
      <div class="empty">No devices paired yet.</div>
    <?php else: foreach ($devs as $d):
        $on = $d['last_seen'] !== null && (time() - (int) $d['last_seen']) < 30; ?>
      <div class="row">
        <span class="pip <?= $on ? 'on' : '' ?>"></span>
        <div class="grow"><?= e($d['device_name']) ?><span class="sub"><?= e($d['email'] ?? 'no account') ?></span></div>
      </div>
    <?php endforeach; endif; ?>
    <p class="hint">Green means that screen checked in within the last 30 seconds.</p>
  </div>

</div>
<?php page_bottom();
