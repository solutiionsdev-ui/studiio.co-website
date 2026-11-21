<?php
/**
 * STUDIIO.CO — contact form handler (Hostinger, PHP mail()).
 * Sends each enquiry to info@studiio.co with Reply-To set to the visitor.
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
    'all'        => 'Not sure yet — all of it',
];
$service = $services[(string) ($_POST['service'] ?? '')] ?? 'Not specified';

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 200) {
    finish(false, 422, 'invalid');
}

// Simple per-visitor rate limit using the server's temp folder.
$ip      = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$bucket  = rtrim(sys_get_temp_dir(), '/') . '/studiio-contact-' . sha1($ip);
$now     = time();
$history = [];
if (is_readable($bucket)) {
    $history = array_filter(
        array_map('intval', explode(',', (string) file_get_contents($bucket))),
        static fn(int $t): bool => $t > $now - RATE_WIN
    );
}
if (count($history) >= RATE_MAX) {
    finish(false, 429, 'rate');
}
$history[] = $now;
@file_put_contents($bucket, implode(',', $history), LOCK_EX);

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

$sent = @mail(MAIL_TO, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers, '-f' . MAIL_FROM);

finish($sent, $sent ? 200 : 500, $sent ? '' : 'send');
