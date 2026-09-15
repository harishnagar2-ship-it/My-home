<?php
declare(strict_types=1);

/**
 * Shared setup: database, sessions, security helpers, schema.
 *
 * SQLite by default so it runs with no database server. To move onto your
 * live site, set DB_DSN to your MySQL connection and import schema.mysql.sql —
 * nothing else in the project changes.
 */

const CODE_TTL_SECONDS = 3600;   // a pairing code lives one hour
const POLL_INTERVAL    = 5;      // seconds the TV waits between polls
const MAX_ATTEMPTS     = 10;     // wrong codes allowed per IP
const ATTEMPT_WINDOW   = 300;    // ...within this many seconds
const UPLOAD_MAX_BYTES = 10 * 1024 * 1024;   // 10 MB — what free hosts allow
const PURGE_GRACE      = 1800;   // keep a just-expired code this long so a user
                                 // finishing login still sees "expired", not "wrong code"

/** Characters a human reads off a screen: consonants only, no 0/O, 1/I/l. */
const CODE_ALPHABET = 'BCDFGHJKLMNPQRSTVWXZ';

/**
 * Database. SQLite for local and small deployments; swap the DSN for MySQL.
 *   const DB_DSN  = 'mysql:host=localhost;dbname=streambox;charset=utf8mb4';
 *   const DB_USER = 'streambox_user';
 *   const DB_PASS = '...';
 * Keep real credentials in config.local.php (gitignored), which overrides these.
 */
const DB_DSN  = 'sqlite:' . __DIR__ . '/pairing.sqlite';
const DB_USER = null;
const DB_PASS = null;

/**
 * Origins allowed to call the TV-facing API from a browser. The device page
 * lives on another host, so list its exact origin — scheme and host, no path,
 * no trailing slash. Never '*'.
 */
const ALLOWED_ORIGINS = [
    'http://localhost:8000',
    'http://localhost:8001',
    'http://127.0.0.1:8001',
    // 'https://your-device-page.netlify.app',
];

if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

/* ---------------------------------------------------------------- database */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO(DB_DSN, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    if (str_starts_with(DB_DSN, 'sqlite:')) {
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS users (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                email         TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                is_admin      INTEGER NOT NULL DEFAULT 0,
                created_at    INTEGER NOT NULL
            )');
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS device_codes (
                device_code  TEXT PRIMARY KEY,
                user_code    TEXT NOT NULL UNIQUE,
                status       TEXT NOT NULL DEFAULT \'pending\',
                device_name  TEXT,
                user_id      INTEGER,
                paired_at    INTEGER,
                revoked_at   INTEGER,
                last_seen    INTEGER,
                expires_at   INTEGER NOT NULL,
                created_at   INTEGER NOT NULL
            )');
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS broadcasts (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                type       TEXT NOT NULL,
                title      TEXT,
                body       TEXT,
                media_url  TEXT,
                created_by INTEGER,
                created_at INTEGER NOT NULL
            )');
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS verify_attempts (
                ip           TEXT NOT NULL,
                attempted_at INTEGER NOT NULL
            )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_attempts ON verify_attempts (ip, attempted_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_devices_user ON device_codes (user_id)');
    }

    return $pdo;
}

/* ----------------------------------------------------------------- session */

function session_begin(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('streambox');
    session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
}

function current_user(): ?array
{
    session_begin();
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    static $cache = null;
    if ($cache !== null && $cache['id'] === (int) $_SESSION['user_id']) {
        return $cache;
    }
    $stmt = db()->prepare('SELECT id, email, is_admin FROM users WHERE id = ?');
    $stmt->execute([(int) $_SESSION['user_id']]);
    $row = $stmt->fetch() ?: null;
    if ($row) {
        $row['id']       = (int) $row['id'];
        $row['is_admin'] = (bool) $row['is_admin'];
    }
    return $cache = $row;
}

function require_login(string $next = ''): array
{
    $u = current_user();
    if (!$u) {
        header('Location: login.php' . ($next !== '' ? '?next=' . rawurlencode($next) : ''));
        exit;
    }
    return $u;
}

function require_admin(): array
{
    $u = require_login(basename($_SERVER['SCRIPT_NAME'] ?? 'broadcast.php'));
    if (!$u['is_admin']) {
        http_response_code(403);
        exit('Admin only.');
    }
    return $u;
}

/**
 * Log a user in. Regenerates the session id (session fixation) while
 * deliberately carrying the pending pairing code across — doing one without
 * the other either loses the code or leaves the hole open.
 */
function login_user(int $userId): void
{
    session_begin();
    $pending = $_SESSION['pending_code'] ?? null;
    session_regenerate_id(true);
    $_SESSION = [
        'user_id' => $userId,
        'csrf'    => bin2hex(random_bytes(16)),
    ];
    if ($pending !== null) {
        $_SESSION['pending_code'] = $pending;
    }
}

function logout_user(): void
{
    session_begin();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/* -------------------------------------------------------------------- CSRF */

function csrf_field(): string
{
    session_begin();
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
}

function csrf_check(): void
{
    session_begin();
    $given = (string) ($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '');
    if ($given === '' || !hash_equals($_SESSION['csrf'], $given)) {
        http_response_code(403);
        exit('Invalid form token. Reload the page and try again.');
    }
}

/* ------------------------------------------------------------- pairing code */

function make_user_code(): string
{
    $out = '';
    $max = strlen(CODE_ALPHABET) - 1;
    for ($i = 0; $i < 8; $i++) {
        $out .= CODE_ALPHABET[random_int(0, $max)];
    }
    return substr($out, 0, 4) . '-' . substr($out, 4, 4);
}

/** The long secret only the TV ever holds. Never displayed. */
function make_device_code(): string
{
    return bin2hex(random_bytes(32));
}

/** "bdwp hqpk", "BDWPHQPK", "bdwp-hqpk" are all the same code. */
function normalise_code(string $raw): string
{
    $clean = strtoupper(preg_replace('/[^A-Za-z]/', '', $raw) ?? '');
    return strlen($clean) === 8
        ? substr($clean, 0, 4) . '-' . substr($clean, 4, 4)
        : $clean;
}

/**
 * Look a code up and say why it cannot be used, or return the row.
 * Called at the code step AND again after login — never trust a
 * "was valid" flag carried in the session.
 */
function check_code(string $userCode): array
{
    if (strlen($userCode) !== 9) {
        return ['ok' => false, 'error' => 'bad_format'];
    }
    $stmt = db()->prepare('SELECT * FROM device_codes WHERE user_code = ?');
    $stmt->execute([$userCode]);
    $row = $stmt->fetch();

    if (!$row)                        return ['ok' => false, 'error' => 'not_found'];
    if ($row['status'] === 'paired')  return ['ok' => false, 'error' => 'already_used'];
    if ($row['expires_at'] < time())  return ['ok' => false, 'error' => 'expired'];

    return ['ok' => true, 'row' => $row];
}

/* --------------------------------------------------------------- rate limit */

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

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
    // Grace window: a code that expired seconds ago is kept so a user still
    // completing login gets "expired while signing in" instead of "wrong code".
    db()->prepare("DELETE FROM device_codes WHERE expires_at < ? AND status = 'pending'")
        ->execute([time() - PURGE_GRACE]);
}

/* ----------------------------------------------------------------- broadcast */

function current_broadcast(): ?array
{
    $row = db()->query('SELECT * FROM broadcasts ORDER BY id DESC LIMIT 1')->fetch();
    if (!$row || $row['type'] === 'off') {
        return null;
    }
    return [
        'id'        => (int) $row['id'],
        'type'      => $row['type'],
        'title'     => $row['title'],
        'body'      => $row['body'],
        'media_url' => $row['media_url'],
    ];
}

/* ------------------------------------------------------------------- output */

/** Escape for HTML. Every piece of user or admin text goes through this. */
function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

/* --------------------------------------------------------------------- CORS */

function send_cors(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && in_array($origin, ALLOWED_ORIGINS, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 86400');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
