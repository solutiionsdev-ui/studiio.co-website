<?php
/**
 * STUDIIO.CO admin — create or update the admin's private config (login, backend, Claude).
 *
 *   php admin/tools/setup.php                  This Mac: writes .private/config.php (git-ignored)
 *   php admin/tools/setup.php --online         Hostinger: writes .private/online/config.php for you
 *                                              to upload to ~/domains/studiio.co/studiio-private/
 *
 * Options: --email=you@studiio.co   --password-stdin (read the password from stdin)
 *          --keep-password (only change other settings)
 * Re-running keeps settings you don't change.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/lib/bootstrap.php';

$opts = getopt('', ['online', 'email:', 'password-stdin', 'keep-password', 'out:']);
$online = isset($opts['online']);
$out = $opts['out'] ?? ($online ? REPO_DIR . '/.private/online/config.php' : REPO_DIR . '/.private/config.php');
$existing = is_file($out) ? (require $out) : [];
$existing = is_array($existing) ? $existing : [];

function ask(string $question, string $default = ''): string
{
    $suffix = $default !== '' ? " [$default]" : '';
    $answer = readline("$question$suffix: ");
    return trim($answer === false ? '' : $answer) ?: $default;
}

function ask_secret(string $question): string
{
    echo "$question: ";
    $tty = stream_isatty(STDIN);
    if ($tty) {
        shell_exec('stty -echo');
    }
    $value = trim((string) fgets(STDIN));
    if ($tty) {
        shell_exec('stty echo');
    }
    echo "\n";
    return $value;
}

echo $online ? "\nSTUDIIO.CO admin — online config (Hostinger + GitHub)\n\n" : "\nSTUDIIO.CO admin — local config (this Mac)\n\n";

$cfg = $existing;
$cfg['admin_email'] = $opts['email'] ?? ask('Admin email (your login)', (string) ($existing['admin_email'] ?? ''));
if (!filter_var($cfg['admin_email'], FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "That doesn't look like an email address.\n");
    exit(1);
}

if (!isset($opts['keep-password']) || empty($existing['admin_password_hash'])) {
    if (isset($opts['password-stdin'])) {
        $password = trim((string) stream_get_contents(STDIN));
    } else {
        $password = ask_secret('Password (at least 12 characters)');
        if ($password !== ask_secret('Same password again')) {
            fwrite(STDERR, "The passwords don't match.\n");
            exit(1);
        }
    }
    if (strlen($password) < 12) {
        fwrite(STDERR, "Use at least 12 characters.\n");
        exit(1);
    }
    $cfg['admin_password_hash'] = password_hash($password, PASSWORD_DEFAULT);
}

$claudeBin = trim((string) shell_exec('command -v claude 2>/dev/null')) ?: (getenv('HOME') . '/.local/bin/claude');

if ($online) {
    $cfg['content_backend'] = 'github';
    $cfg['site_url'] = $existing['site_url'] ?? 'https://studiio.co';
    $gh = $existing['github'] ?? [];
    $gh['repo'] = ask('GitHub repo', (string) ($gh['repo'] ?? 'solutiionsdev-ui/studiio.co-website'));
    $gh['branch'] = ask('Branch that deploys to Hostinger', (string) ($gh['branch'] ?? 'main'));
    if (empty($gh['token']) || strtolower(ask('Replace the saved GitHub token? (y/n)', 'n')) === 'y') {
        echo "Create a fine-grained token at https://github.com/settings/personal-access-tokens/new\n";
        echo "  Repository access: only {$gh['repo']}\n  Permissions: Contents, Pull requests, Actions = Read and write\n";
        $gh['token'] = ask_secret('GitHub token');
    }
    $cfg['github'] = $gh;
    $cfg['claude'] = array_merge(['runner' => 'github', 'model' => 'claude-opus-5-5', 'workflow' => 'ask-claude.yml'], $existing['claude'] ?? []);
    unset($cfg['site_root'], $cfg['data_dir']); // on the server these default to the website folder and studiio-private/data
} else {
    $cfg['content_backend'] = 'local';
    $cfg['github'] = array_merge(['repo' => 'solutiionsdev-ui/studiio.co-website', 'branch' => 'main', 'token' => ''], $existing['github'] ?? []);
    $cfg['claude'] = array_merge(['runner' => 'local', 'bin' => $claudeBin, 'model' => 'claude-opus-5-5', 'max_budget_usd' => 3], $existing['claude'] ?? []);
    $cfg['git'] = array_merge(['push' => true, 'author_name' => 'STUDIIO Admin', 'author_email' => 'info@studiio.co'], $existing['git'] ?? []);
}

$php = "<?php\n// STUDIIO.CO admin config — private: never commit or upload into public_html.\n// Written by admin/tools/setup.php on " . gmdate('Y-m-d H:i') . " UTC.\nreturn " . var_export($cfg, true) . ";\n";
if (!is_dir(dirname($out))) {
    mkdir(dirname($out), 0700, true);
}
file_put_contents($out, $php);
chmod($out, 0600);

echo "\nSaved $out\n";
if ($online) {
    echo "Next: in Hostinger File Manager create the folder studiio-private next to public_html\n";
    echo "(…/domains/studiio.co/studiio-private) and upload this config.php into it. See admin/README.md.\n";
} else {
    echo "Start the admin:  php -S localhost:4380 admin/dev/router.php   then open http://localhost:4380/admin/\n";
}
