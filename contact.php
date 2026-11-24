<?php
/**
 * STUDIIO.CO — contact form handler (Hostinger, PHP mail()).
 * Saves each enquiry as a lead for the admin panel (private data folder, see admin/README.md)
 * and emails it to info@studiio.co with Reply-To set to the visitor.
 * Answers JSON for the site's fetch() call, or redirects back for no-JS posts.
 */
declare(strict_types=1);

const MAIL_TO   = 'info@studiio.co';
const MAIL_FROM = 'info@studiio.co';   // must be an address on the domain for deliverability
const RATE_MAX  = 5;                   // submissions…
const RATE_WIN  = 900;                 // …per 15 minutes per visitor

$wantsJson = strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;

function finish(bool $ok, int $code, string $error = ''): void {
    global $wantsJson;
    if ($wantsJson) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($error === '' ? ['ok' => $ok] : ['ok' => $ok, 'error' => $error]);
    } else {
        header('Location: /?sent=' . ($ok ? '1' : '0') . '#contact', true, 303);
    }
    exit;
}

function cut(string $s, int $max): string {
    return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
}

/** One line, no control characters — safe for headers and the subject. */
function oneLine($v, int $max): string {
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $v) ?? '';
    return trim(cut($s, $max));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    finish(false, 405, 'method');
}

// Honeypot: real visitors never see or fill this field.
if (!empty($_POST['website'])) {
    finish(true, 200);
}

$name    = oneLine($_POST['name'] ?? '', 120);
$company = oneLine($_POST['company'] ?? '', 160);
$email   = trim((string) ($_POST['email'] ?? ''));
$message = trim(cut(str_replace("\0", '', (string) ($_POST['message'] ?? '')), 5000));
$lang    = (($_POST['lang'] ?? 'en') === 'ar') ? 'Arabic' : 'English';

$services = [
    'pr'         => 'Public Relations & Strategic Communications',
    'production' => 'Branding & Creative Production',
    'digital'    => 'Digital Marketing & Growth',
    'web'        => 'Website Design & Development',
    'events'     => 'Events & Experiential Marketing',
];
// Service names come from content/services.json (edited in the admin), with the list above as a fallback.
$fromContent = json_decode((string) @file_get_contents(__DIR__ . '/content/services.json'), true);
foreach (is_array($fromContent) ? $fromContent : [] as $svc) {
    if (is_array($svc) && isset($svc['id'], $svc['title']['en']) && is_string($svc['id']) && is_string($svc['title']['en'])) {
        $services[$svc['id']] = $svc['title']['en'];
    }
}
$services['all'] = 'Not sure yet — all of it';
$serviceKey = (string) ($_POST['service'] ?? '');
$service = isset($services[$serviceKey]) ? $services[$serviceKey] : 'Not specified';

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 200) {
    finish(false, 422, 'invalid');
}

// Per-visitor rate limit using the server's temp folder. Keyed by IP (IPv6: by /64 network),
// and read + checked + written under one lock so parallel posts can't slip through.
$ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$bin = @inet_pton($ip);
$key = ($bin !== false && strlen($bin) === 16) ? bin2hex(substr($bin, 0, 8)) : $ip;
$fh  = @fopen(rtrim(sys_get_temp_dir(), '/') . '/studiio-contact-' . sha1($key), 'c+');
if ($fh && flock($fh, LOCK_EX)) {
    $now = time();
    $history = array_filter(
        array_map('intval', explode(',', (string) stream_get_contents($fh))),
        static fn(int $t): bool => $t > $now - RATE_WIN
    );
    $limited = count($history) >= RATE_MAX;
    if (!$limited) {
        $history[] = $now;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, implode(',', $history));
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    if ($limited) {
        finish(false, 429, 'rate');
    }
}

// Save the lead for the admin panel first, so it's kept even if the email fails.
// Skipped quietly when the admin isn't set up on this server yet.
$leadId = null;
try {
    if (is_file(__DIR__ . '/admin/lib/leads.php')) {
        require_once __DIR__ . '/admin/lib/leads.php';
    }
    if (function_exists('leads_add') && is_configured()) {
        $leadId = leads_add([
            'name'          => $name,
            'company'       => $company,
            'email'         => $email,
            'service'       => isset($services[$serviceKey]) ? $serviceKey : '',
            'service_label' => $service,
            'message'       => $message,
            'lang'          => $lang === 'Arabic' ? 'ar' : 'en',
        ]);
    }
} catch (Throwable $e) {
    error_log('studiio contact: could not save lead: ' . $e->getMessage());
}

$subject = 'New enquiry — ' . $service . ' — ' . $name;
$body = implode("\n", [
    'New enquiry from studiio.co',
    '',
    'Name:     ' . $name,
    'Company:  ' . ($company !== '' ? $company : '—'),
    'Email:    ' . $email,
    'Service:  ' . $service,
    'Language: ' . $lang,
    '',
    'Message:',
    $message !== '' ? $message : '—',
    '',
    '—',
    'Reply to this email to answer ' . $name . ' directly.',
]);

$headers = implode("\r\n", [
    'From: STUDIIO.CO Website <' . MAIL_FROM . '>',
    'Reply-To: ' . $email,
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
    'X-Mailer: studiio.co',
]);

if (PHP_SAPI === 'cli-server') {
    // Local development server (admin/dev/router.php): never send real email from a test.
    error_log("studiio contact (dev): email to " . MAIL_TO . " skipped: $subject");
    $sent = false;
} else {
    $sent = @mail(MAIL_TO, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers, '-f' . MAIL_FROM);
}

if ($leadId !== null) {
    try {
        leads_update($leadId, ['mail_sent' => $sent]);
    } catch (Throwable $e) {
        error_log('studiio contact: could not update lead: ' . $e->getMessage());
    }
}

// The enquiry counts as received if it was emailed or saved for the admin.
$ok = $sent || $leadId !== null;
finish($ok, $ok ? 200 : 500, $ok ? '' : 'send');
