<?php
/**
 * STUDIIO.CO admin — the address n8n talks to (no admin login; the n8n key instead).
 *
 * Every call carries the key from the admin's Approvals page (Settings → n8n), as the
 * header "X-Studiio-Key: <key>" (or "Authorization: Bearer <key>").
 *
 *   GET  hooks.php?action=outbox   approved messages waiting to be sent
 *   POST hooks.php?action=sent     {"id": "..."}                  n8n sent it: logged as sent
 *   POST hooks.php?action=failed   {"id": "...", "error": "..."}  n8n could not: back in the inbox
 *   POST hooks.php?action=tick     run the timed rules now (for an n8n schedule)
 *
 * Logic: admin/lib/crm-auto.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/crm.php';

security_headers();
set_exception_handler(static function (Throwable $e): void {
    if ($e instanceof ApiError) {
        respond(['error' => $e->getMessage()], $e->status);
    }
    error_log('studiio-hooks: ' . $e);
    respond(['error' => 'Server error'], 500);
});

if (!is_configured()) {
    respond(['error' => 'Not found'], 404);
}
$key = (string) ($_SERVER['HTTP_X_STUDIIO_KEY'] ?? '');
if ($key === '' && preg_match('/^Bearer\s+(\S+)$/', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $m)) {
    $key = $m[1];
}
$stored = crm_auto_settings()['key'];
if ($stored === '' || $key === '' || !hash_equals($stored, $key)) {
    if (!crm_public_rate_ok(20, 600)) {
        respond(['error' => 'Too many attempts'], 429);
    }
    respond(['error' => 'Wrong or missing key'], 401);
}

$action = (string) ($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$in = $method === 'POST' ? request_data() : [];
$id = is_string($in['id'] ?? null) ? $in['id'] : '';

switch ("$method $action") {
    case 'GET outbox':
        respond(['messages' => crm_outbox_for_n8n()]);
    case 'POST sent':
        crm_outbox_report($id, true);
        respond(['ok' => true]);
    case 'POST failed':
        crm_outbox_report($id, false, is_string($in['error'] ?? null) ? $in['error'] : '');
        respond(['ok' => true]);
    case 'POST tick':
        respond(['ok' => true, 'made' => crm_auto_tick()]);
}
respond(['error' => 'Unknown action'], 404);
