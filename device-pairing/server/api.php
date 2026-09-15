<?php
declare(strict_types=1);

/**
 * The TV-facing API. Three actions, all JSON, all cross-origin:
 *
 *   POST api.php?action=new    TV asks for a pairing code
 *   POST api.php?action=poll   TV asks "am I paired yet?"
 *   POST api.php?action=sync   paired TV asks "what should I show?"
 *
 * Everything a human does in a browser (entering the code, logging in,
 * broadcasting) lives in the session-backed pages, not here.
 *
 * Error strings follow RFC 8628 so this stays swappable with a real OAuth
 * device-flow server later.
 */

require __DIR__ . '/config.php';

send_cors();
header('Cache-Control: no-store');
purge_expired();

$action = $_GET['action'] ?? '';
$body   = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];

switch ($action) {

    case 'new': {
        $deviceCode = make_device_code();
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

    case 'poll': {
        $stmt = db()->prepare('SELECT * FROM device_codes WHERE device_code = ?');
        $stmt->execute([(string) ($body['device_code'] ?? '')]);
        $row = $stmt->fetch();

        if (!$row)                           json_out(['error' => 'expired_token'], 400);
        if ($row['revoked_at'] !== null)     json_out(['error' => 'access_denied'], 400);
        if ($row['status'] === 'paired') {
            json_out([
                'status'       => 'paired',
                'access_token' => 'tok_' . substr($row['device_code'], 0, 16),
                'device_name'  => $row['device_name'],
                'paired_at'    => (int) $row['paired_at'],
            ]);
        }
        if ($row['expires_at'] < time())     json_out(['error' => 'expired_token'], 400);

        json_out(['error' => 'authorization_pending'], 400);
    }

    case 'sync': {
        $stmt = db()->prepare('SELECT * FROM device_codes WHERE device_code = ?');
        $stmt->execute([(string) ($body['device_code'] ?? '')]);
        $row = $stmt->fetch();

        if (!$row || $row['status'] !== 'paired') json_out(['error' => 'not_paired'], 401);
        if ($row['revoked_at'] !== null)          json_out(['error' => 'revoked'], 401);

        db()->prepare('UPDATE device_codes SET last_seen = ? WHERE device_code = ?')
            ->execute([time(), $row['device_code']]);

        json_out([
            'paired'      => true,
            'device_name' => $row['device_name'],
            'broadcast'   => current_broadcast(),
        ]);
    }

    default:
        json_out(['error' => 'unknown_action'], 404);
}
