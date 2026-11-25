<?php
/**
 * STUDIIO.CO — client links: https://studiio.co/client/?t=<token>
 *
 * Each document the admin makes (proposal, quotation, agreement, welcome pack) and each
 * intake questionnaire has its own private link. This page shows the document (with a
 * Download PDF button) or the questionnaire form. No sign-in: the 32-character token in the
 * link is the key, so links are only ever sent to the client. Logic: admin/lib/crm-docs.php.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/admin/lib/crm-docs.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; style-src 'self' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

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
header('Content-Type: text/html; charset=utf-8');

try {
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
    if (empty($_GET['preview'])) {
        crm_public_viewed($doc['id']);
    }
    echo crm_doc_html($snap, '?t=' . $token . '&pdf=1');
} catch (ApiError $e) {
    client_gone($e->status === 409 ? 409 : 404);
} catch (Throwable $e) {
    error_log('studiio-client: ' . $e);
    client_gone(500);
}
