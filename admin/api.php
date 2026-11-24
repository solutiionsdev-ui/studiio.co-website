<?php
/**
 * STUDIIO.CO admin — JSON API used by the admin page (admin/assets/admin.js).
 * Every action except login needs a signed-in session; every POST needs the CSRF token.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/claude.php';
require_once __DIR__ . '/lib/leads.php';

security_headers();

set_exception_handler(static function (Throwable $e): void {
    if ($e instanceof ApiError) {
        respond(['error' => $e->getMessage()] + $e->extra, $e->status);
    }
    error_log('studiio-admin: ' . $e);
    respond(['error' => 'Something went wrong on the server. Details are in the PHP error log.'], 500);
});

if (!is_configured()) {
    throw new ApiError('The admin is not set up yet. Run: php admin/tools/setup.php', 503);
}

$action = (string) ($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$post = $method === 'POST';

/* ---- public: session check, login ---- */

if ($action === 'session') {
    $user = current_user();
    respond($user ? session_payload($user) : ['user' => null]);
}

if ($action === 'login') {
    if (!$post) {
        throw new ApiError('Use POST.', 405);
    }
    $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    if ($site !== '' && $site !== 'same-origin') {
        throw new ApiError('Cross-site request blocked.', 403);
    }
    $in = request_data();
    $user = login((string) ($in['email'] ?? ''), (string) ($in['password'] ?? ''));
    respond(session_payload($user));
}

/* ---- everything below needs a signed-in admin ---- */

$user = current_user();
if (!$user) {
    throw new ApiError('Please sign in.', 401);
}
if ($post) {
    require_csrf();
}
if ($action === 'logout') {
    logout();
    respond(['ok' => true]);
}
// Release the session lock so slow calls (GitHub, git push) don't block other requests.
session_write_close();

$in = $post ? request_data() : $_GET;
$str = static fn(string $key): string => is_scalar($in[$key] ?? null) ? (string) $in[$key] : '';

switch ("$method $action") {
    case 'GET overview':
        $leads = leads_all();
        $changes = null;
        try {
            $changes = changes_summary();
        } catch (ApiError $e) {
            $changes = ['error' => $e->getMessage()];
        }
        respond([
            'newLeads'   => count(array_filter($leads, static fn($l) => ($l['status'] ?? '') === 'new')),
            'totalLeads' => count($leads),
            'latestLeads' => array_slice($leads, 0, 5),
            'changes'    => $changes,
            'tasks'      => claude_runner() === 'off' ? [] : array_slice(tasks_list(5), 0, 3),
        ]);

    case 'GET content':
        $name = $str('file');
        respond(['file' => $name] + load_content($name));

    case 'POST content':
        $name = $str('file');
        [$version, $warnings, $saved] = save_content($name, $in['data'] ?? null, ($in['version'] ?? null) !== null ? $str('version') : null);
        respond(['ok' => true, 'file' => $name, 'data' => $saved, 'version' => $version, 'warnings' => $warnings]);

    case 'GET images':
        $folder = $str('folder') === 'clients' ? 'img/clients' : 'img';
        respond(['folder' => $folder, 'files' => store()->listImages($folder)]);

    case 'POST upload':
        respond(save_upload($_FILES['file'] ?? [], $str('kind') === 'logo' ? 'logo' : 'photo'));

    case 'GET changes':
        respond(changes_summary());

    case 'GET diff':
        respond(['path' => $str('path'), 'diff' => change_diff($str('path'))]);

    case 'POST publish':
        respond(publish_changes((array) ($in['paths'] ?? []), $str('message')));

    case 'POST discard':
        discard_change($str('path'));
        respond(['ok' => true]);

    case 'GET leads':
        respond(['leads' => leads_all(), 'statuses' => LEAD_STATUSES]);

    case 'GET leads-csv':
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="studiio-leads-' . gmdate('Y-m-d') . '.csv"');
        echo leads_csv();
        exit;

    case 'POST lead':
        $changes = array_intersect_key($in, array_flip(['status', 'notes']));
        respond(['lead' => leads_update($str('id'), $changes)]);

    case 'POST lead-delete':
        leads_delete($str('id'));
        respond(['ok' => true]);

    case 'GET tasks':
        respond(['runner' => claude_runner(), 'tasks' => tasks_list()]);

    case 'POST task':
        respond(['task' => task_create($str('prompt'))]);

    case 'GET task':
        $task = task_refresh(task_load($str('id')));
        $diff = $task['status'] === 'review' ? task_diff($task) : [];
        respond(['task' => $task, 'diff' => $diff]);

    case 'POST task-publish':
        respond(task_publish($str('id')));

    case 'POST task-discard':
        respond(['task' => task_discard($str('id'))]);
}

throw new ApiError('Unknown action.', 404);

function session_payload(string $user): array
{
    $cfg = config();
    return [
        'user'    => $user,
        'csrf'    => csrf_token(),
        'backend' => $cfg['content_backend'] === 'github' ? 'github' : 'local',
        'branch'  => $cfg['github']['branch'] ?: 'main',
        'repo'    => $cfg['github']['repo'],
        'siteUrl' => $cfg['site_url'] ?: '/',
        'claude'  => claude_runner(),
        'model'   => (string) ($cfg['claude']['model'] ?? ''),
        'files'   => CONTENT_FILES,
    ];
}
