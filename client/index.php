<?php
/**
 * STUDIIO.CO — client links: https://studiio.co/client/?t=<token>
 *
 * Each project's portal, each document the admin makes (proposal, quotation, agreement,
 * welcome pack, invoices) and each intake questionnaire has its own private link. This page
 * shows the portal (checklist uploads, documents, files to review), the document (with a
 * Download PDF button, and Accept / Sign for the proposal and agreement) or the questionnaire.
 * No sign-in: the 32-character token in the link is the key, so links are only ever sent to the
 * client. No scripts: plain forms. Logic: admin/lib/crm-docs.php and crm-portal.php.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/admin/lib/crm.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; style-src 'self' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; media-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

function client_gone(int $code = 404): never
{
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    $text = 'This link no longer works. Message us and we will send you a new one.';
    try {
        $text = crm_label('link_gone');
        echo crm_page('STUDIIO', 'Studiio', '<section class="done"><p>' . crm_h($text) . '</p></section>', []);
    } catch (Throwable $e) {
        echo '<!doctype html><meta charset="utf-8"><title>STUDIIO</title><p>' . htmlspecialchars($text) . '</p>';
    }
    exit;
}

if (!is_configured()) {
    client_gone();
}

$token = (string) ($_GET['t'] ?? '');
try {
    $found = crm_public_find($token);
} catch (Throwable $e) {
    error_log('studiio-client: ' . $e);
    client_gone(500);
}
if ($found === null) {
    client_gone();
}
[$db, $doc, $project, $client] = $found;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$back = static function (string $code, string $anchor = '') use ($token): never {
    header('Location: ?t=' . $token . '&ok=' . $code . $anchor, true, 303);
    exit;
};
$posted = static function (): void {
    $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    if ($site !== '' && $site !== 'same-origin') {
        client_gone(403);
    }
};

try {
    if ($doc['type'] === 'portal') {
        // A file: a deliverable (stream, photo preview, or download once downloads are open).
        $fid = (string) ($_GET['f'] ?? '');
        if ($fid !== '') {
            $n = preg_match('/^[a-f0-9]{12}$/', $fid) ? crm_index($db['media'], $fid) : null;
            $m = $n === null ? null : $db['media'][$n];
            $shared = $m && in_array((int) $m['round'], array_map(static fn($r) => $r['status'] !== 'draft' ? (int) $r['n'] : -1, (array) ($project['rounds'] ?? [])), true);
            if (!$m || $m['project_id'] !== $project['id'] || !$shared) { // files in an unshared round stay private
                client_gone();
            }
            $download = !empty($_GET['dl']);
            if ($download && !crm_portal_downloads($db, $project)[0]) {
                client_gone(403);
            }
            if (!$download && ($_GET['v'] ?? '') === 'preview' && $m['preview'] !== '') {
                crm_send_file(data_path($m['preview']), 'image/jpeg', $m['name'], false);
            }
            crm_send_file(data_path($m['path']), CRM_MEDIA_TYPES[$m['ext']][1], $m['name'], $download);
        }
        header('Content-Type: text/html; charset=utf-8');
        if ($method === 'POST') {
            $posted();
            if (!$_POST && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) { // over the server's upload limit
                http_response_code(413);
                echo crm_portal_html($db, $doc, $project, $client, crm_portal_label('errors.too_big', ['mb' => (string) (crm_settings()['portal']['client_upload_mb'] ?? 50)]), true);
                exit;
            }
            if (!crm_public_rate_ok(60, 600)) {
                http_response_code(429);
                echo crm_portal_html($db, $doc, $project, $client, crm_portal_label('errors.failed'), true);
                exit;
            }
            if ($project['status'] !== 'open') {
                client_gone(409);
            }
            try {
                switch ((string) ($_POST['action'] ?? '')) {
                    case 'item':
                        $item = (string) ($_POST['item'] ?? '');
                        $code = crm_portal_upload($project['id'], $item, $_FILES['files'] ?? []);
                        $note = (string) ($_POST['note'] ?? '');
                        if ($code === '' || trim($note) !== '') {
                            $code = crm_portal_item_done($project['id'], $item, $note);
                        }
                        $back($code, '#need');
                    case 'feedback':
                        $back(crm_portal_feedback($project['id'], (int) ($_POST['round'] ?? 0), (string) ($_POST['text'] ?? '')), '#review');
                    case 'approve':
                        $back(crm_portal_approve($project['id'], (int) ($_POST['round'] ?? 0), (string) ($_POST['name'] ?? '')), '#review');
                    case 'plan':
                        $do = (string) ($_POST['do'] ?? '');
                        $back(crm_portal_plan($project['id'], (string) ($_POST['cycle'] ?? ''), $do === 'approve' ? 'approve' : 'changes',
                            (string) ($do === 'approve' ? ($_POST['name'] ?? '') : ($_POST['text'] ?? ''))), '#month');
                }
                client_gone();
            } catch (ApiError $e) {
                http_response_code($e->status >= 400 && $e->status < 500 ? $e->status : 400);
                [$db, $doc, $project, $client] = crm_public_find($token) ?? client_gone();
                echo crm_portal_html($db, $doc, $project, $client, $e->getMessage(), true);
                exit;
            }
        }
        if (empty($_GET['preview'])) {
            crm_public_viewed($doc['id']);
        }
        echo crm_portal_html($db, $doc, $project, $client, crm_portal_notice((string) ($_GET['ok'] ?? '')));
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    if ($doc['type'] === 'intake') {
        $questions = crm_intake_questions($project['track']);
        if ($method === 'POST') {
            $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
            if ($site !== '' && $site !== 'same-origin') {
                client_gone(403);
            }
            if (!empty($_POST['hp_website'])) { // spam bots fill the hidden field
                header('Location: ?t=' . $token . '&done=1', true, 303);
                exit;
            }
            if (!crm_intake_editable($project)) {
                echo crm_intake_html($project, $client, $token, 'closed');
                exit;
            }
            if (!crm_public_rate_ok()) {
                http_response_code(429);
                echo crm_intake_html($project, $client, $token, 'form', $project['intake']['answers'] ?? [], [], (string) (crm_tpl_json('intake')['too_many'] ?? 'Too many attempts.'));
                exit;
            }
            [$answers, $missing] = crm_intake_read($questions, $_POST);
            if ($missing) {
                http_response_code(422);
                $values = $answers;
                foreach ($questions as $q) { // keep what they typed in "Other"
                    $o = $_POST['o'][$q['id']] ?? '';
                    if (is_string($o) && $o !== '' && !isset($values[$q['id']])) {
                        $values[$q['id']] = $o;
                    }
                }
                echo crm_intake_html($project, $client, $token, 'form', $values, $missing);
                exit;
            }
            crm_intake_save($doc['id'], $answers, $questions);
            header('Location: ?t=' . $token . '&done=1', true, 303);
            exit;
        }
        if (empty($_GET['preview'])) { // the admin's own preview doesn't count as the client opening it
            crm_public_viewed($doc['id']);
        }
        $submitted = !empty($project['intake']['submitted_at']);
        if ($submitted && empty($_GET['edit'])) {
            echo crm_intake_html($project, $client, $token, crm_intake_editable($project) ? 'thanks' : 'closed');
        } elseif (!crm_intake_editable($project)) {
            echo crm_intake_html($project, $client, $token, 'closed');
        } else {
            echo crm_intake_html($project, $client, $token, 'form', $project['intake']['answers'] ?? []);
        }
        exit;
    }

    // A document: the PDF, or the page.
    $snapFile = ($doc['file'] ?? '') !== '' ? data_path(preg_replace('/\.pdf$/', '.json', $doc['file'])) : '';
    $snap = $snapFile !== '' ? read_json_file($snapFile) : null;
    if (!is_array($snap)) {
        client_gone();
    }
    if (!empty($_GET['pdf'])) {
        [$path, $name] = crm_doc_pdf_file($doc, $client);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
    if ($method === 'POST' && ($_POST['action'] ?? '') === 'sign') {
        $posted();
        if (!crm_public_rate_ok(60, 600)) {
            client_gone(429);
        }
        try {
            $back(crm_doc_sign($doc['id'], (string) ($_POST['name'] ?? ''), !empty($_POST['agree'])));
        } catch (ApiError $e) {
            http_response_code(400);
            echo crm_doc_html($snap, '?t=' . $token . '&pdf=1', crm_sign_form($doc, $token), $e->getMessage());
            exit;
        }
    }
    if (empty($_GET['preview'])) {
        crm_public_viewed($doc['id']);
    }
    echo crm_doc_html($snap, '?t=' . $token . '&pdf=1', crm_sign_form($doc, $token), crm_portal_notice((string) ($_GET['ok'] ?? '')));
} catch (ApiError $e) {
    client_gone($e->status === 409 ? 409 : 404);
} catch (Throwable $e) {
    error_log('studiio-client: ' . $e);
    client_gone(500);
}
