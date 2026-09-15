<?php
declare(strict_types=1);

/**
 * The whole API, three actions:
 *
 *   POST api.php?action=new        device asks for a code
 *   POST api.php?action=poll       device asks "am I paired yet?"
 *   POST api.php?action=verify     your website submits the code a human typed
 *   POST api.php?action=sync       paired device asks "what should I show?"
 *   POST api.php?action=send       portal starts a broadcast          (admin)
 *   POST api.php?action=devices    portal lists paired devices        (admin)
 *
 * Error strings follow RFC 8628 so this stays swappable with a real
 * OAuth device-flow server later.
 */

require __DIR__ . '/config.php';

send_cors();
header('Cache-Control: no-store');
purge_expired();

$action = $_GET['action'] ?? '';
$body   = json_decode(file_get_contents('php://input') ?: '[]', true) ?: $_POST;

switch ($action) {

    /* ---------- 1. Device asks for a code ---------- */
    case 'new': {
        $deviceCode = make_device_code();

        // Retry on the astronomically unlikely user_code collision.
        for ($try = 0; $try < 5; $try++) {
            $userCode = make_user_code();
            try {
                db()->prepare(
                    'INSERT INTO device_codes
                        (device_code, user_code, status, device_name, expires_at, created_at)
                     VALUES (?, ?, \'pending\', ?, ?, ?)'
                )->execute([
                    $deviceCode,
                    $userCode,
                    substr((string) ($body['device_name'] ?? 'Living Room TV'), 0, 60),
                    time() + CODE_TTL_SECONDS,
                    time(),
                ]);

                json_out([
                    'device_code' => $deviceCode,
                    'user_code'   => $userCode,
                    'expires_in'  => CODE_TTL_SECONDS,
                    'interval'    => POLL_INTERVAL,
                ]);
            } catch (PDOException $e) {
                if (!str_contains($e->getMessage(), 'UNIQUE')) {
                    throw $e;
                }
            }
        }
        json_out(['error' => 'server_error'], 500);
    }

    /* ---------- 2. Device polls ---------- */
    case 'poll': {
        $stmt = db()->prepare('SELECT * FROM device_codes WHERE device_code = ?');
        $stmt->execute([(string) ($body['device_code'] ?? '')]);
        $row = $stmt->fetch();

        if (!$row)                        json_out(['error' => 'expired_token'], 400);
        if ($row['expires_at'] < time())  json_out(['error' => 'expired_token'], 400);
        if ($row['status'] === 'denied')  json_out(['error' => 'access_denied'], 400);

        if ($row['status'] === 'paired') {
            json_out([
                'status'       => 'paired',
                'access_token' => 'demo_token_' . substr($row['device_code'], 0, 16),
                'device_name'  => $row['device_name'],
                'paired_at'    => (int) $row['paired_at'],
            ]);
        }

        json_out(['error' => 'authorization_pending'], 400);
    }

    /* ---------- 3. Website submits the typed code ---------- */
    case 'verify': {
        if (rate_limited()) {
            json_out(['ok' => false, 'error' => 'too_many_attempts'], 429);
        }

        $userCode = normalise_code((string) ($body['user_code'] ?? ''));

        if (strlen($userCode) !== 9) {
            record_attempt();
            json_out(['ok' => false, 'error' => 'bad_format'], 400);
        }

        $stmt = db()->prepare('SELECT * FROM device_codes WHERE user_code = ?');
        $stmt->execute([$userCode]);
        $row = $stmt->fetch();

        if (!$row) {
            record_attempt();
            json_out(['ok' => false, 'error' => 'not_found'], 404);
        }
        if ($row['expires_at'] < time()) {
            json_out(['ok' => false, 'error' => 'expired'], 410);
        }
        if ($row['status'] === 'paired') {
            json_out(['ok' => false, 'error' => 'already_used'], 409);
        }

        // Real system: bind $_SESSION['user_id'] to this row here.
        db()->prepare("UPDATE device_codes SET status = 'paired', paired_at = ? WHERE device_code = ?")
            ->execute([time(), $row['device_code']]);

        json_out(['ok' => true, 'device_name' => $row['device_name']]);
    }

    /* ---------- 4. Paired device asks what to show ---------- */
    case 'sync': {
        $stmt = db()->prepare('SELECT * FROM device_codes WHERE device_code = ?');
        $stmt->execute([(string) ($body['device_code'] ?? '')]);
        $row = $stmt->fetch();

        if (!$row || $row['status'] !== 'paired') {
            json_out(['error' => 'not_paired'], 401);
        }

        // Heartbeat, so the portal can show which screens are actually alive.
        db()->prepare('UPDATE device_codes SET last_seen = ? WHERE device_code = ?')
            ->execute([time(), $row['device_code']]);

        json_out([
            'paired'      => true,
            'device_name' => $row['device_name'],
            'broadcast'   => current_broadcast(),
        ]);
    }

    /* ---------- 5. Portal starts a broadcast ---------- */
    case 'send': {
        if (!admin_ok((string) ($body['admin_key'] ?? ''))) {
            json_out(['ok' => false, 'error' => 'unauthorised'], 401);
        }

        $type = (string) ($body['type'] ?? 'message');
        if (!in_array($type, ['message', 'video', 'image', 'off'], true)) {
            json_out(['ok' => false, 'error' => 'bad_type'], 400);
        }

        $url = trim((string) ($body['media_url'] ?? ''));
        if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
            json_out(['ok' => false, 'error' => 'bad_url'], 400);
        }

        db()->prepare(
            'INSERT INTO broadcasts (type, title, body, media_url, created_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $type,
            substr(trim((string) ($body['title'] ?? '')), 0, 120),
            substr(trim((string) ($body['body'] ?? '')), 0, 600),
            $url,
            time(),
        ]);

        json_out(['ok' => true, 'broadcast' => current_broadcast()]);
    }

    /* ---------- 6. Portal lists paired devices ---------- */
    case 'devices': {
        if (!admin_ok((string) ($body['admin_key'] ?? ''))) {
            json_out(['ok' => false, 'error' => 'unauthorised'], 401);
        }

        $rows = db()->query(
            "SELECT device_name, paired_at, last_seen
               FROM device_codes
              WHERE status = 'paired'
           ORDER BY paired_at DESC
              LIMIT 100"
        )->fetchAll();

        $now = time();
        foreach ($rows as &$r) {
            $r['online'] = $r['last_seen'] !== null && ($now - (int) $r['last_seen']) < 30;
        }

        json_out(['ok' => true, 'devices' => $rows, 'live' => current_broadcast()]);
    }

    default:
        json_out(['error' => 'unknown_action'], 404);
}
