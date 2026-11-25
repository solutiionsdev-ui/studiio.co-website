<?php
/**
 * STUDIIO.CO admin — JSON API used by the admin page (admin/assets/admin.js).
 * Every action except login needs a signed-in session; every POST needs the CSRF token.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/claude.php';
require_once __DIR__ . '/lib/leads.php';
require_once __DIR__ . '/lib/crm.php';
require_once __DIR__ . '/lib/reels.php';

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
            'crm'        => (static function (): ?array {
                try {
                    return crm_overview();
                } catch (ApiError $e) {
                    return null;
                }
            })(),
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
        respond(['leads' => leads_all(), 'statuses' => LEAD_STATUSES, 'inPipeline' => crm_lead_links()]);

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

    /* ---- client system: pipeline, clients, projects (see lib/crm.php) ---- */

    case 'GET crm':
        respond(crm_board());

    case 'GET crm-settings':
        respond(['settings' => crm_public_settings()]);

    case 'GET crm-client':
        respond(crm_client_payload($str('id')));

    case 'POST crm-client-create':
        respond(crm_client_payload(crm_client_create($in, $user)));

    case 'POST crm-client':
        respond(crm_client_payload(crm_client_update($str('id'), (array) ($in['fields'] ?? []), $user)));

    case 'POST crm-client-delete':
        crm_client_delete($str('id'));
        respond(['ok' => true]);

    case 'POST crm-project-create':
        $pid = crm_project_create($str('client_id'), $in, $user);
        respond(crm_client_payload($str('client_id')) + ['project_id' => $pid]);

    case 'POST crm-project':
        respond(crm_client_payload(crm_project_update($str('id'), (array) ($in['fields'] ?? []), $user)));

    case 'POST crm-move':
        respond(crm_client_payload(crm_move($str('id'), $str('to'), $str('reason'), $user)));

    case 'POST crm-close':
        respond(crm_client_payload(crm_close($str('id'), $str('outcome'), $str('reason'), $user)));

    case 'POST crm-reopen':
        respond(crm_client_payload(crm_reopen($str('id'), $user)));

    case 'POST crm-record':
        respond(crm_client_payload(crm_record($str('id'), $str('what'), $in, $user)));

    case 'POST crm-shoot':
        respond(crm_client_payload(crm_shoot($str('id'), $str('op'), $str('date'), $str('reason'), $user)));

    case 'POST crm-checklist':
        respond(crm_client_payload(crm_checklist($str('id'), $in, $user)));

    case 'POST crm-payment':
        respond(crm_client_payload(crm_payment_add($str('id'), $in, $user)));

    case 'POST crm-payment-delete':
        respond(crm_client_payload(crm_payment_delete($str('payment_id'), $user)));

    case 'POST crm-message':
        respond(crm_client_payload(crm_message_add($str('id'), $in, $user)));

    case 'POST crm-doc-make':
        respond(crm_client_payload(crm_doc_make($str('id'), $str('type'), $in, $user)));

    case 'POST crm-intake-link':
        respond(crm_client_payload(crm_intake_link($str('id'), $user)));

    case 'POST crm-doc-status':
        respond(crm_client_payload(crm_doc_status($str('doc_id'), $str('status'), $user)));

    case 'GET crm-doc-pdf':
        [$path, $name] = crm_doc_admin_pdf($str('id'));
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;

    case 'GET crm-message-draft':
        respond(crm_message_draft($str('id'), $str('template'), $str('doc_id')));

    case 'POST crm-invoice':
        respond(crm_client_payload(crm_invoice_make($str('id'), $in, $user)));

    case 'POST crm-invoice-delete':
        respond(crm_client_payload(crm_invoice_delete($str('invoice_id'), $user)));

    case 'GET crm-payments':
        respond(crm_payments_view());

    case 'GET crm-payments-csv':
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="studiio-payments-' . gmdate('Y-m-d') . '.csv"');
        echo crm_payments_csv();
        exit;

    case 'POST crm-pay-details':
        crm_pay_details_save($in['text'] ?? '');
        respond(crm_payments_view());

    case 'POST crm-message-delete':
        respond(crm_client_payload(crm_message_delete($str('message_id'))));

    /* ---- reel editor (this Mac only, see lib/reels.php) ---- */

    case 'GET reels':
        respond(['reels' => reels_list(), 'clients' => reel_clients(), 'statuses' => REEL_STATUSES]);

    case 'POST reel-upload':
        respond(['size' => reel_upload_chunk((string) ($_GET['id'] ?? ''), (int) ($_GET['offset'] ?? -1))]);

    case 'POST reel-create':
        respond(['reel' => reel_create($in)]);

    case 'GET reel':
        respond(reel_payload($str('slug')));

    case 'GET reel-status':
        respond(reel_status($str('slug')));

    case 'POST reel-meta':
        respond(['reel' => reel_meta_update($str('slug'), array_intersect_key($in, array_flip(['title', 'status', 'client'])))]);

    case 'POST reel-beats':
        respond(reel_beats_save($str('slug'), $in['doc'] ?? null, ($in['version'] ?? null) !== null ? $str('version') : null));

    case 'POST reel-job':
        respond(['job' => reel_job_start($str('slug'), $str('kind'), ['force' => !empty($in['force'])])]);

    case 'POST reel-photo':
        respond(reel_photo_upload($str('slug'), $_FILES['file'] ?? []));

    case 'POST reel-chat':
        respond(['chat' => reel_chat_send($str('slug'), $str('message'))]);
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
        'reels'   => reels_enabled(),
    ];
}
