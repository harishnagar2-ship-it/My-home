<?php
declare(strict_types=1);

/**
 * Shared setup: database connection, schema, and helpers.
 *
 * Storage is SQLite so the prototype runs with no database server.
 * To move this onto your live site, change $pdo to your MySQL DSN —
 * nothing else in the project needs to change.
 */

const CODE_TTL_SECONDS   = 3600; // code lives one hour
const POLL_INTERVAL      = 5;    // seconds the device should wait between polls
const MAX_ATTEMPTS       = 10;   // wrong codes allowed per IP
const ATTEMPT_WINDOW      = 300;  // ...within this many seconds

/** Characters a human has to read off a screen and type on a phone.
 *  Consonants only: no 0/O, no 1/I/l, and no accidental words. */
const CODE_ALPHABET = 'BCDFGHJKLMNPQRSTVWXZ';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO('sqlite:' . __DIR__ . '/pairing.sqlite');
    // For MySQL instead:
    // $pdo = new PDO('mysql:host=localhost;dbname=yourdb;charset=utf8mb4', $user, $pass);

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS device_codes (
            device_code  TEXT PRIMARY KEY,
            user_code    TEXT NOT NULL UNIQUE,
            status       TEXT NOT NULL DEFAULT \'pending\',
            device_name  TEXT,
            paired_at    INTEGER,
            expires_at   INTEGER NOT NULL,
            created_at   INTEGER NOT NULL
        )
    ');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS verify_attempts (
            ip           TEXT NOT NULL,
            attempted_at INTEGER NOT NULL
        )
    ');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_attempts ON verify_attempts (ip, attempted_at)');

    return $pdo;
}

/** The short code the human reads off the screen, e.g. "BDWP-HQPK". */
function make_user_code(): string
{
    $out = '';
    $max = strlen(CODE_ALPHABET) - 1;
    for ($i = 0; $i < 8; $i++) {
        $out .= CODE_ALPHABET[random_int(0, $max)];
    }
    return substr($out, 0, 4) . '-' . substr($out, 4, 4);
}

/** The long secret only the device ever sees. Never displayed anywhere. */
function make_device_code(): string
{
    return bin2hex(random_bytes(32));
}

/** Accept "bdwp hqpk", "BDWPHQPK", "bdwp-hqpk" — all the same code. */
function normalise_code(string $raw): string
{
    $clean = strtoupper(preg_replace('/[^A-Za-z]/', '', $raw) ?? '');
    return strlen($clean) === 8
        ? substr($clean, 0, 4) . '-' . substr($clean, 4, 4)
        : $clean;
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** Brute-forcing an 8-character code is the real attack here, so cap tries. */
function rate_limited(): bool
{
    $pdo = db();
    $pdo->prepare('DELETE FROM verify_attempts WHERE attempted_at < ?')
        ->execute([time() - ATTEMPT_WINDOW]);

    $stmt = $pdo->prepare('SELECT COUNT(*) AS n FROM verify_attempts WHERE ip = ?');
    $stmt->execute([client_ip()]);

    return ((int) $stmt->fetch()['n']) >= MAX_ATTEMPTS;
}

function record_attempt(): void
{
    db()->prepare('INSERT INTO verify_attempts (ip, attempted_at) VALUES (?, ?)')
        ->execute([client_ip(), time()]);
}

function purge_expired(): void
{
    db()->prepare("DELETE FROM device_codes WHERE expires_at < ? AND status = 'pending'")
        ->execute([time()]);
}

function json_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
