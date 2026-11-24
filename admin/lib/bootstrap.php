<?php
/**
 * STUDIIO.CO admin — shared setup: config, sessions, login, CSRF, HTTP helpers.
 * Nothing here runs on include; callers pick what they need.
 */
declare(strict_types=1);

define('ADMIN_DIR', dirname(__DIR__));
define('REPO_DIR', dirname(__DIR__, 2));

/** An error the admin UI can show as-is. */
final class ApiError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400, public readonly array $extra = [])
    {
        parent::__construct($message);
    }
}

/* --------------------------------------------------------------------------
   Config
   -------------------------------------------------------------------------- */

/** Where config.php is looked for, in order. The first one that exists wins. */
function config_candidates(): array
{
    $local = in_array(PHP_SAPI, ['cli', 'cli-server'], true);
    return array_values(array_filter([
        getenv('STUDIIO_ADMIN_CONFIG') ?: null,
        dirname(REPO_DIR) . '/studiio-private/config.php',  // Hostinger: next to public_html, never web-served
        $local ? REPO_DIR . '/.private/config.php' : null,  // this Mac only (git-ignored); never used inside a web root
    ]));
}

function config_file(): ?string
{
    foreach (config_candidates() as $file) {
        if (is_file($file)) {
            return $file;
        }
    }
    return null;
}

function config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $file = config_file();
    $loaded = $file ? require $file : [];
    if (!is_array($loaded)) {
        $loaded = [];
    }
    $defaults = [
        'admin_email'          => '',
        'admin_password_hash'  => '',
        'site_root'            => REPO_DIR,
        'site_url'             => '',
        'data_dir'             => $file ? dirname($file) . '/data' : '',
        'content_backend'      => 'local',          // 'local' = write files on this machine, 'github' = commit through the GitHub API
        'github'               => ['repo' => '', 'branch' => 'main', 'token' => ''],
        'git'                  => ['bin' => 'git', 'push' => true, 'author_name' => 'STUDIIO Admin', 'author_email' => 'info@studiio.co'],
        'claude'               => ['runner' => 'off', 'bin' => 'claude', 'model' => 'claude-opus-5-5', 'max_turns' => 40, 'workflow' => 'ask-claude.yml'],
        'session_idle_minutes' => 120,
    ];
    $cfg = $defaults;
    foreach ($loaded as $key => $value) {
        $cfg[$key] = (is_array($value) && isset($defaults[$key]) && is_array($defaults[$key]))
            ? array_merge($defaults[$key], $value)
            : $value;
    }
    $cfg['_file'] = $file;
    return $cfg;
}

function is_configured(): bool
{
    $c = config();
    return $c['_file'] !== null && $c['admin_email'] !== '' && $c['admin_password_hash'] !== '' && $c['data_dir'] !== '';
}

/** Absolute path inside the private data folder; creates parent folders. */
function data_path(string $rel = ''): string
{
    $dir = rtrim((string) config()['data_dir'], '/');
    if ($dir === '') {
        throw new ApiError('The admin has no data folder configured.', 500);
    }
    $path = $rel === '' ? $dir : $dir . '/' . ltrim($rel, '/');
    $parent = $rel === '' || str_ends_with($rel, '/') ? $path : dirname($path);
    if (!is_dir($parent) && !@mkdir($parent, 0770, true) && !is_dir($parent)) {
        throw new ApiError('Could not create the admin data folder.', 500);
    }
    return $path;
}

/* --------------------------------------------------------------------------
   Files
   -------------------------------------------------------------------------- */

/** Write a file atomically (temp file + rename) so readers never see half a file. */
function write_atomic(string $path, string $bytes): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new ApiError("Could not create folder for $path", 500);
    }
    $tmp = $dir . '/.' . basename($path) . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $bytes) === false || !rename($tmp, $path)) {
        @unlink($tmp);
        throw new ApiError('Could not save ' . basename($path), 500);
    }
}

/**
 * Run $fn while holding an exclusive lock named $name (a file in the data folder).
 * Used for leads, rate limits and tasks so two requests never overwrite each other.
 */
function with_lock(string $name, callable $fn): mixed
{
    $fh = fopen(data_path("locks/$name.lock"), 'c');
    if (!$fh || !flock($fh, LOCK_EX)) {
        throw new ApiError('Could not lock ' . $name, 500);
    }
    try {
        return $fn();
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function read_json_file(string $path, mixed $fallback = null): mixed
{
    if (!is_file($path)) {
        return $fallback;
    }
    $data = json_decode((string) file_get_contents($path), true);
    return $data ?? $fallback;
}

/** JSON the way the repo stores it: 2-space indent, readable Arabic, unescaped slashes, trailing newline. */
function encode_json(mixed $data): string
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $json = preg_replace_callback('/^(?: {4})+/m', static fn(array $m): string => str_repeat(' ', intdiv(strlen($m[0]), 2)), $json);
    return $json . "\n";
}

/* --------------------------------------------------------------------------
   HTTP
   -------------------------------------------------------------------------- */

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

function respond(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

/** Request body as an array (JSON body, or form fields for multipart uploads). */
function request_data(): array
{
    static $data = null;
    if ($data !== null) {
        return $data;
    }
    $type = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_starts_with($type, 'application/json')) {
        $raw = file_get_contents('php://input', false, null, 0, 4 * 1024 * 1024);
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            throw new ApiError('The request body is not valid JSON.');
        }
    } else {
        $data = $_POST;
    }
    return $data;
}

/* --------------------------------------------------------------------------
   Session, login, CSRF
   -------------------------------------------------------------------------- */

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string) (12 * 3600));
    session_save_path(data_path('sessions/'));
    session_name('studiio_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/admin/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

/** The signed-in admin's email, or null. Ends sessions that sat idle or are older than 12 hours. */
function current_user(): ?string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // Only resume sessions we created: a missing, malformed or unknown cookie never creates a session file.
        $id = $_COOKIE['studiio_admin'] ?? '';
        if (!is_string($id) || !preg_match('/^[a-zA-Z0-9,-]{22,128}$/', $id) || !is_file(data_path('sessions/sess_' . $id))) {
            return null;
        }
    }
    start_session();
    $user = $_SESSION['user'] ?? null;
    if (!$user) {
        return null;
    }
    $now = time();
    $idle = max(5, (int) config()['session_idle_minutes']) * 60;
    if ($now - (int) ($_SESSION['seen'] ?? 0) > $idle || $now - (int) ($_SESSION['login_at'] ?? 0) > 12 * 3600) {
        logout();
        return null;
    }
    $_SESSION['seen'] = $now;
    return $user;
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Every state-changing request must come from the admin page itself. */
function require_csrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($sent) || $sent === '' || !hash_equals(csrf_token(), $sent)) {
        throw new ApiError('Your session expired. Reload the page and try again.', 403);
    }
    $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    if ($site !== '' && $site !== 'same-origin') {
        throw new ApiError('Cross-site request blocked.', 403);
    }
}

const LOGIN_MAX_PER_IP = 5;     // failed attempts per IP (IPv6: per /64) per window → blocked
const LOGIN_SLOW_GLOBAL = 30;   // failed attempts from everyone per window → every attempt is slowed down
const LOGIN_WINDOW     = 900;   // 15 minutes. To clear all limits, delete the data folder's ratelimit/ files.

/** Rate-limit key for the visitor: the IP, or its /64 network for IPv6 (one home or phone usually owns a whole /64). */
function client_net(): string
{
    $ip = client_ip();
    $bin = @inet_pton($ip);
    return ($bin !== false && strlen($bin) === 16) ? bin2hex(substr($bin, 0, 8)) . '::/64' : $ip;
}

/** Recent failure timestamps for a rate-limit bucket. */
function login_failures(string $bucket): array
{
    $now = time();
    $list = read_json_file(data_path("ratelimit/$bucket.json"), []);
    return array_values(array_filter(is_array($list) ? $list : [], static fn($t) => is_int($t) && $t > $now - LOGIN_WINDOW));
}

function login(string $email, string $password): string
{
    $ipBucket = 'login-' . sha1(client_net());
    if (count(login_failures($ipBucket)) >= LOGIN_MAX_PER_IP) {
        throw new ApiError('Too many failed attempts. Wait 15 minutes and try again.', 429);
    }
    // Many failures from many places: slow everyone down rather than lock the owner out.
    if (count(login_failures('login-all')) >= LOGIN_SLOW_GLOBAL) {
        sleep(3);
    }
    return with_lock('login', function () use ($email, $password, $ipBucket): string {
        if (count(login_failures($ipBucket)) >= LOGIN_MAX_PER_IP) {
            throw new ApiError('Too many failed attempts. Wait 15 minutes and try again.', 429);
        }
        $cfg = config();
        // Always check the password, so a wrong email takes as long as a wrong password.
        $okPassword = $cfg['admin_password_hash'] !== '' && password_verify($password, (string) $cfg['admin_password_hash']);
        $okEmail = hash_equals(strtolower(trim((string) $cfg['admin_email'])), strtolower(trim($email)));
        if (!$okPassword || !$okEmail) {
            foreach ([$ipBucket, 'login-all'] as $bucket) {
                $list = login_failures($bucket);
                $list[] = time();
                write_atomic(data_path("ratelimit/$bucket.json"), json_encode($list));
            }
            throw new ApiError('Wrong email or password.', 401);
        }
        @unlink(data_path("ratelimit/$ipBucket.json"));
        start_session();
        session_regenerate_id(true);
        $_SESSION = [
            'user'     => (string) $cfg['admin_email'],
            'login_at' => time(),
            'seen'     => time(),
            'csrf'     => bin2hex(random_bytes(32)),
        ];
        if (password_needs_rehash($cfg['admin_password_hash'], PASSWORD_DEFAULT)) {
            error_log('studiio-admin: password hash uses an old algorithm; run admin/tools/setup.php to update it.');
        }
        return $_SESSION['user'];
    });
}

function logout(): void
{
    start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Strict']);
    }
    session_destroy();
}

/* --------------------------------------------------------------------------
   Processes and outbound HTTP
   -------------------------------------------------------------------------- */

/**
 * Run a command without a shell (arguments are passed as-is, never interpreted).
 * Returns [exitCode, stdout, stderr].
 */
function run_cmd(array $cmd, string $cwd, ?string $stdin = null, ?array $env = null): array
{
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
    if (!is_resource($proc)) {
        throw new ApiError('Could not run ' . basename((string) $cmd[0]), 500);
    }
    if ($stdin !== null) {
        fwrite($pipes[0], $stdin);
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), (string) $out, (string) $err];
}

/** Call the GitHub REST API. Returns [status, decoded body]. */
function github_api(string $method, string $path, ?array $body = null): array
{
    $gh = config()['github'];
    if (empty($gh['token']) || empty($gh['repo'])) {
        throw new ApiError('GitHub is not configured (github.repo and github.token in config.php).', 500);
    }
    $url = rtrim((string) ($gh['api_url'] ?? '') ?: 'https://api.github.com', '/') . $path;
    $ch = curl_init($url);
    $headers = [
        'Accept: application/vnd.github+json',
        'Authorization: Bearer ' . $gh['token'],
        'X-GitHub-Api-Version: 2022-11-28',
        'User-Agent: studiio-admin',
    ];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        throw new ApiError('Could not reach GitHub: ' . $error, 502);
    }
    $decoded = $raw === '' ? null : json_decode((string) $raw, true);
    if ($status === 401 || $status === 403) {
        throw new ApiError('GitHub refused the request (' . $status . '). Check the token in config.php and its permissions.', 502);
    }
    return [$status, $decoded];
}

/** Repo path on GitHub, with each segment URL-encoded. */
function gh_repo_path(string $suffix): string
{
    $repo = (string) config()['github']['repo'];
    if (!preg_match('#^[\w.-]+/[\w.-]+$#', $repo)) {
        throw new ApiError('github.repo in config.php must look like owner/name.', 500);
    }
    return '/repos/' . $repo . $suffix;
}
