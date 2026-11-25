<?php
/**
 * STUDIIO.CO admin — client documents for the Pipeline (onboarding spec, phase 2).
 *
 * - Templates: admin/crm/<business>/templates/<lang>/ (*.md documents, intake.json, messages.json,
 *   labels.json). {{name}} fills in a value, {{#name}}…{{/name}} keeps a part only when the value
 *   is set (or for the project's track: {{#retainer}}), {{^name}}…{{/name}} only when it is not,
 *   {{> name}} includes another template. Lines starting with %% are notes and never shown.
 * - Documents (proposal, quotation, agreement, welcome pack, kickoff agenda) are made from a
 *   template into a snapshot (kept as JSON) and a PDF (lib/pdf.php), stored in the private data
 *   folder under crm/files/. Each has a private link (client/?t=…) that shows it as a web page
 *   with a Download PDF button.
 * - The intake questionnaire is a form behind the same kind of link; answers go onto the project
 *   (and a few onto the client), and submitting it completes the Intake stage.
 */
declare(strict_types=1);

require_once __DIR__ . '/crm.php';
require_once __DIR__ . '/pdf.php';

const CRM_MAKE_TYPES = ['proposal', 'quotation', 'agreement', 'welcome_pack', 'kickoff_agenda'];
const CRM_INTAKE_SAVES = ['client.instagram', 'client.tiktok', 'client.website', 'client.channel', 'project.approver'];

/* --------------------------------------------------------------------------
   Templates
   -------------------------------------------------------------------------- */

function crm_tpl_file(string $name, string $ext = 'md', string $lang = 'en'): string
{
    if (!preg_match('/^[a-z_]+$/', $name) || !preg_match('/^[a-z]{2}$/', $lang)) {
        throw new ApiError('Unknown template.', 500);
    }
    $file = crm_dir() . "/templates/$lang/$name.$ext";
    $raw = is_file($file) ? @file_get_contents($file) : false;
    if ($raw === false) {
        throw new ApiError('Template admin/crm/' . CRM_BUSINESS . "/templates/$lang/$name.$ext is missing.", 500);
    }
    return $raw;
}

/** A JSON template (intake, messages, labels), cached. */
function crm_tpl_json(string $name): array
{
    static $cache = [];
    return $cache[$name] ??= crm_read_json("templates/en/$name.json");
}

function crm_label(string $key, array $vars = []): string
{
    $v = crm_tpl_json('labels')[$key] ?? $key;
    return is_string($v) ? crm_tpl_vars($v, $vars) : $key;
}

/** Fill in a template: partials, sections, values. */
function crm_tpl(string $tpl, array $vars, int $depth = 0): string
{
    $tpl = (string) preg_replace_callback('/\{\{>\s*([a-z_]+)\s*\}\}/', static function (array $m) use ($vars, $depth): string {
        return $depth < 3 ? rtrim(crm_tpl(crm_tpl_strip(crm_tpl_file($m[1])), $vars, $depth + 1)) : '';
    }, $tpl);
    return crm_tpl_sections($tpl, $vars);
}

function crm_tpl_strip(string $tpl): string
{
    return (string) preg_replace('/^%%.*(\R|$)/m', '', $tpl);
}

function crm_tpl_sections(string $s, array $vars): string
{
    $out = '';
    while (preg_match('/\{\{([#^])([a-z_]+)\}\}/', $s, $m, PREG_OFFSET_CAPTURE)) {
        $start = $m[0][1];
        $open = $start + strlen($m[0][0]);
        $name = $m[2][0];
        $out .= crm_tpl_vars(substr($s, 0, $start), $vars);
        $depth = 1;
        $pos = $open;
        $innerEnd = null;
        while ($depth > 0 && preg_match('/\{\{([#^\/])' . $name . '\}\}/', $s, $mm, PREG_OFFSET_CAPTURE, $pos)) {
            $depth += $mm[1][0] === '/' ? -1 : 1;
            $innerEnd = $mm[0][1];
            $pos = $mm[0][1] + strlen($mm[0][0]);
        }
        if ($depth > 0) {
            throw new ApiError("A template opens {{{$m[1][0]}$name}} but never closes it with {{/$name}}.", 500);
        }
        $value = $vars[$name] ?? '';
        $truthy = is_array($value) ? $value !== [] : ($value !== '' && $value !== false && $value !== null && $value !== '0');
        if (($m[1][0] === '#') === $truthy) {
            $out .= crm_tpl_sections(substr($s, $open, $innerEnd - $open), $vars);
        }
        $s = substr($s, $pos);
    }
    return $out . crm_tpl_vars($s, $vars);
}

function crm_tpl_vars(string $s, array $vars): string
{
    return (string) preg_replace_callback('/\{\{([a-z_]+)\}\}/', static function (array $m) use ($vars): string {
        $v = $vars[$m[1]] ?? '';
        return is_scalar($v) && !is_bool($v) ? (string) $v : '';
    }, $s);
}

/**
 * A Markdown template filled in. Lines that were blank in the template stay paragraph breaks;
 * lines that only became empty because a {{#…}} part was dropped disappear.
 */
function crm_tpl_md(string $name, array $vars): string
{
    $src = crm_tpl_strip(crm_tpl_file($name));
    $src = (string) preg_replace('/^[ \t]*$/m', "\x01", $src);
    $out = crm_tpl($src, $vars);
    $lines = array_filter(preg_split('/\R/', $out), static fn($l) => trim($l) !== '');
    $text = implode("\n", array_map(static fn($l) => trim($l) === "\x01" ? '' : rtrim($l), $lines));
    return trim((string) preg_replace("/\n{3,}/", "\n\n", str_replace("\x01", '', $text)));
}

/** Plain text (messages): filled in, tidy blank lines. */
function crm_tpl_text(string $tpl, array $vars): string
{
    $out = crm_tpl($tpl, $vars);
    return trim((string) preg_replace("/\n{3,}/", "\n\n", (string) preg_replace('/[ \t]+$/m', '', $out)));
}

/* --------------------------------------------------------------------------
   Markdown (a small subset) → blocks shared by the PDF and the web page
   -------------------------------------------------------------------------- */

/** Text with **bold** → runs [[text, bold], …]; "\n" inside is a line break. */
function crm_runs(string $text): array
{
    $runs = [];
    foreach (preg_split('/\*\*/', $text) as $i => $part) {
        if ($part !== '') {
            $runs[] = [$part, $i % 2 === 1];
        }
    }
    return $runs;
}

/** Blocks: h1, h2, h3 and p (runs), ul and ol (items of runs), page. */
function crm_md_blocks(string $md): array
{
    $blocks = [];
    $para = [];
    $list = null;
    $flushPara = static function () use (&$para, &$blocks): void {
        if ($para) {
            $blocks[] = ['t' => 'p', 'runs' => crm_runs(implode("\n", $para))];
            $para = [];
        }
    };
    $flushList = static function () use (&$list, &$blocks): void {
        if ($list) {
            $blocks[] = $list;
            $list = null;
        }
    };
    foreach (preg_split('/\R/', $md) as $line) {
        $t = trim($line);
        if ($t === '') {
            $flushPara();
            $flushList();
        } elseif ($t === '[page]') {
            $flushPara();
            $flushList();
            $blocks[] = ['t' => 'page'];
        } elseif (preg_match('/^(#{1,3})\s+(.+)$/', $t, $m)) {
            $flushPara();
            $flushList();
            $blocks[] = ['t' => 'h' . strlen($m[1]), 'runs' => crm_runs($m[2])];
        } elseif (preg_match('/^[-*]\s+(.+)$/', $t, $m) || preg_match('/^\d+[.)]\s+(.+)$/', $t, $m)) {
            $flushPara();
            $kind = preg_match('/^\d/', $t) ? 'ol' : 'ul';
            if (!$list || $list['t'] !== $kind) {
                $flushList();
                $list = ['t' => $kind, 'items' => []];
            }
            $list['items'][] = crm_runs($m[1]);
        } else {
            $flushList();
            $para[] = $t;
        }
    }
    $flushPara();
    $flushList();
    return $blocks;
}

/* --------------------------------------------------------------------------
   Values for templates
   -------------------------------------------------------------------------- */

function crm_long_date(string $ymd): string
{
    return $ymd !== '' ? date('j M Y', (int) strtotime($ymd . ' 12:00')) : '';
}

function crm_list(array $items): string
{
    return implode("\n", array_map(static fn($i) => '- ' . str_replace("\n", ' ', (string) $i), array_filter($items, static fn($i) => trim((string) $i) !== '')));
}

/** "a, b or c" */
function crm_join(array $items, string $last = 'or'): string
{
    $items = array_values($items);
    return count($items) < 2 ? implode('', $items) : implode(', ', array_slice($items, 0, -1)) . " $last " . end($items);
}

/** Every value a template can use for this project. */
function crm_doc_vars(array $db, array $p, array $c, array $extra = []): array
{
    $s = crm_settings();
    $t = $s['terms'];
    $ct = $s['contact'] ?? [];
    $price = (float) ($p['price'] ?? 0);
    $paid = crm_paid($db, $p['id']);
    $retainer = $p['track'] === 'retainer';
    $deposit = $price > 0 ? ($retainer ? $price : round($price * $t['deposit_percent'] / 100, 2)) : 0.0;
    $items = array_values(array_filter($db['checklist'], static fn($i) => $i['project_id'] === $p['id']));
    $waiting = array_values(array_filter($items, static fn($i) => $i['status'] === 'waiting'));
    $dues = array_filter(array_column($waiting, 'due_date'));
    $shoots = array_values(array_filter((array) ($p['shoot_dates'] ?? []), 'is_string'));
    $first = trim(explode(' ', trim((string) $c['contact_name']))[0] ?? '');
    $goal = crm_answer($p, 'goal');
    $reply = (int) $t['studiio_reply_working_days'];
    $deliveredAt = '';
    foreach ($db['events'] as $e) {
        if ($e['project_id'] === $p['id'] && $e['kind'] === 'stage' && str_ends_with($e['text'], ' to Delivered')) {
            $deliveredAt = substr($e['at'], 0, 10);
        }
    }
    $expiryBase = $deliveredAt ?: crm_today();
    $vars = [
        'client_name' => $c['business_name'] ?: $c['contact_name'],
        'business_name' => $c['business_name'] ?: $c['contact_name'],
        'contact_name' => $c['contact_name'],
        'contact_first_name' => $first !== '' ? $first : ($c['business_name'] ?: 'there'),
        'project_title' => $p['title'],
        'track_label' => $s['tracks'][$p['track']]['label'] ?? $p['track'],
        'track_about' => $s['tracks'][$p['track']]['about'] ?? '',
        'retainer' => $retainer, 'production' => $p['track'] === 'production', 'photography' => $p['track'] === 'photography',
        'scope' => (string) ($p['scope'] ?? ''),
        'deliverables_list' => crm_list((array) ($p['deliverables'] ?? [])),
        'exclusions_list' => crm_list((array) ($p['exclusions'] ?? [])),
        'deliverables_summary' => crm_join((array) ($p['deliverables'] ?? []), 'and') ?: $p['title'],
        'price' => $price > 0 ? crm_usd($price) : '',
        'currency' => $s['business']['currency'],
        'deposit_percent' => $t['deposit_percent'] . '%',
        'deposit_amount' => $deposit > 0 ? crm_usd($deposit) : '',
        'paid' => crm_usd($paid),
        'balance_amount' => $price > $paid ? crm_usd($price - $paid) : '',
        'payment_schedule' => rtrim((string) ($p['payment_schedule'] ?? ''), '. '),
        'payment_schedule_lower' => lcfirst(rtrim((string) ($p['payment_schedule'] ?? ''), '. ')),
        'payment_methods' => crm_join($t['payment_methods']),
        'revision_rounds' => (string) ($p['revision_rounds'] ?? $t['revision_rounds']),
        'feedback_days' => (string) $t['client_feedback_working_days'],
        'reply_days_text' => $reply === 0 ? 'the same day' : ($reply === 1 ? 'one working day' : "$reply working days"),
        'reschedule_hours' => (string) $t['reschedule_notice_hours'],
        'retainer_min_months' => (string) $t['retainer_minimum_months'],
        'retainer_notice_days' => (string) $t['retainer_notice_days'],
        'unused_visits_roll_over' => (bool) $t['unused_visits_roll_over'],
        'raw_footage' => (string) $t['raw_footage'],
        'raw_footage_lower' => lcfirst((string) $t['raw_footage']),
        'delivery_link_days' => (string) $t['delivery_link_days'],
        'checkin_days' => (string) $t['checkin_days_after_delivery'],
        'signing_lower' => lcfirst((string) $t['signing']),
        'start_date' => crm_long_date((string) ($p['start_date'] ?? '')),
        'end_date' => crm_long_date((string) ($p['end_date'] ?? '')),
        'kickoff_date' => crm_long_date((string) ($p['kickoff_date'] ?? '')),
        'draft_date' => crm_long_date((string) ($p['draft_date'] ?? '')),
        'delivery_date' => crm_long_date((string) ($p['delivery_date'] ?? '')),
        'first_shoot' => crm_long_date($shoots[0] ?? ''),
        'checklist_due' => $dues ? crm_long_date(min($dues)) : '',
        'checklist_list' => crm_list(array_column($waiting, 'label')),
        'checklist_open_list' => crm_list(array_column(array_filter($waiting, static fn($i) => $i['required']), 'label')),
        'today' => crm_long_date(crm_today()),
        'contact_person' => (string) ($ct['person'] ?? 'Studiio'),
        'contact_email' => (string) ($ct['email'] ?? ''),
        'contact_phone' => (string) ($ct['phone'] ?? ''),
        'contact_instagram' => (string) ($ct['instagram'] ?? ''),
        'reply_hours' => (string) ($ct['reply_hours'] ?? ''),
        'approver' => (string) (($p['approver'] ?? '') ?: crm_answer($p, 'approver')),
        'channel_label' => CRM_CHANNELS[$c['channel']] ?? 'WhatsApp',
        'client_goal' => $goal,
        'success' => crm_answer($p, 'success'),
        'deadline' => crm_answer($p, 'deadline'),
        'usage' => crm_answer($p, 'usage'),
        'references' => crm_answer($p, 'references'),
        'no_go' => crm_answer($p, 'no_go'),
        'location' => crm_answer($p, 'location'),
        'on_camera' => crm_answer($p, 'on_camera'),
        'delivery_link' => (string) (($p['delivery_link'] ?? '') ?: (static function () use ($db, $p): string {
            foreach ($db['documents'] ?? [] as $d) { // the client portal holds the files when no other link is set
                if ($d['project_id'] === $p['id'] && $d['type'] === 'portal') {
                    return crm_doc_link($d);
                }
            }
            return '';
        })()),
        'round' => (string) max([0, ...array_map(static fn($r) => $r['status'] !== 'draft' ? (int) $r['n'] : 0, (array) ($p['rounds'] ?? []))]),
        'link_expiry_date' => crm_long_date(date('Y-m-d', (int) strtotime($expiryBase . ' +' . (int) $t['delivery_link_days'] . ' days'))),
        'link' => '',
        'invoice_no' => '', 'invoice_amount' => '', 'invoice_due' => '',
        'last_payment' => (static function () use ($db, $p): string {
            $mine = array_filter($db['payments'], static fn($x) => $x['project_id'] === $p['id']);
            usort($mine, static fn($a, $b) => strcmp($a['date'] . $a['created_at'], $b['date'] . $b['created_at']));
            return $mine ? crm_usd((float) end($mine)['amount']) : '';
        })(),
    ];
    $vars['approver_or_one_person'] = $vars['approver'] !== '' ? $vars['approver'] : 'the person you name';
    return $extra + $vars;
}

/* --------------------------------------------------------------------------
   Making documents
   -------------------------------------------------------------------------- */

/** The site address client links start with (where this admin is running). */
function crm_public_base(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        $site = rtrim((string) (config()['site_url'] ?? ''), '/');
        return $site !== '' ? $site : 'http://localhost:4380';
    }
    return (is_https() ? 'https://' : 'http://') . preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', $host);
}

function crm_doc_link(array $doc): string
{
    return ($doc['token'] ?? '') !== '' ? crm_public_base() . '/client/?t=' . $doc['token'] : '';
}

function crm_doc_title(string $type): string
{
    return (string) (crm_tpl_json('labels')['documents'][$type] ?? CRM_DOC_TYPES[$type] ?? $type);
}

/** The content of a document, before it becomes a PDF or a web page. */
function crm_doc_snapshot(array $db, array $p, array $c, string $type, array $in, array $doc): array
{
    $vars = crm_doc_vars($db, $p, $c);
    $s = crm_settings();
    $ct = $s['contact'] ?? [];
    $snap = [
        'type' => $type,
        'title' => crm_doc_title($type),
        'prepared_for' => $vars['client_name'],
        'meta' => [[crm_label('date'), $vars['today']]],
        'blocks' => [],
        'contact' => array_values(array_filter([(string) ($ct['email'] ?? ''), (string) ($ct['phone'] ?? '')])),
        'labels' => ['page' => (string) (crm_tpl_json('labels')['page'] ?? '{{n}} / {{total}}'), 'download' => crm_label('download_pdf'), 'prepared_for' => crm_label('prepared_for')],
        'version' => (int) ($doc['version'] ?? 1),
        'generated_at' => gmdate('c'),
    ];
    if ($type === 'quotation') {
        $price = (float) ($p['price'] ?? 0);
        if ($price <= 0) {
            throw new ApiError('Set the project price in Project details first.');
        }
        $valid = date('Y-m-d', (int) strtotime(crm_today() . ' +' . (int) ($s['documents']['quote_valid_days'] ?? 30) . ' days'));
        $snap['meta'] = [[crm_label('quote_no'), $doc['quote_no']], [crm_label('issue_date'), $vars['today']], [crm_label('valid_until'), crm_long_date($valid)]];
        $details = (array) ($p['deliverables'] ?? []);
        if (!$details && ($p['scope'] ?? '') !== '') {
            $details = [$p['scope']];
        }
        $snap['blocks'][] = [
            't' => 'quote', 'title' => $p['title'], 'details' => array_values($details),
            'amount' => crm_usd($price) . ($p['track'] === 'retainer' ? ' ' . crm_label('per_month') : ''),
            'total' => crm_usd($price), 'labels' => [crm_label('description'), crm_label('amount'), crm_label('total')],
        ];
        if ($vars['payment_schedule'] !== '') {
            $snap['blocks'][] = ['t' => 'label', 'text' => crm_label('payment_terms')];
            $snap['blocks'][] = ['t' => 'p', 'runs' => crm_runs($vars['payment_schedule'] . '.')];
        }
        $notes = crm_text($in['notes'] ?? '', 1000);
        $snap['blocks'][] = ['t' => 'label', 'text' => crm_label('notes')];
        $snap['blocks'][] = ['t' => 'p', 'runs' => crm_runs(trim($notes . ($notes !== '' ? "\n" : '') . crm_label('quote_note', ['valid_until' => crm_long_date($valid)])))];
        $snap['notes'] = $notes;
        return $snap;
    }
    if ($type === 'proposal' || $type === 'kickoff_agenda') {
        $snap['meta'][] = [crm_label('project'), $vars['track_label']];
    }
    $snap['blocks'] = crm_md_blocks(crm_tpl_md($type, $vars));
    return $snap;
}

/** Make a document (or a new version of it). Returns the client id. */
function crm_doc_make(string $pid, string $type, array $in, string $by): string
{
    return crm_tx(static fn(array &$db): string => crm_doc_make_in($db, $pid, $type, $in, $by));
}

/** crm_doc_make() inside a transaction that is already open (used by the automation too). */
function crm_doc_make_in(array &$db, string $pid, string $type, array $in, string $by): string
{
    $type = crm_pick($type, CRM_MAKE_TYPES, 'document');
    $p = $db['projects'][crm_project_at($db, $pid)];
    $ci = crm_index($db['clients'], $p['client_id']);
    $c = $db['clients'][$ci];
    $n = null;
    foreach ($db['documents'] as $k => $d) {
        if ($d['project_id'] === $pid && $d['type'] === $type) {
            $n = $k;
        }
    }
    // A made document that is already signed stays as it is. One only recorded by hand
    // (accepted or signed before it had a PDF) can still get its PDF, keeping its status.
    if ($n !== null && ($db['documents'][$n]['file'] ?? '') !== '' && in_array($db['documents'][$n]['status'], ['signed', 'paid', 'completed'], true)) {
        throw new ApiError('The ' . strtolower(crm_doc_title($type)) . ' is already ' . $db['documents'][$n]['status'] . ', so it can’t be changed.');
    }
    $doc = $n !== null ? $db['documents'][$n] : [
        'id' => crm_id(), 'business' => CRM_BUSINESS, 'client_id' => $p['client_id'], 'project_id' => $pid, 'type' => $type,
        'token' => '', 'file' => '', 'link' => '', 'version' => 0, 'status' => 'draft', 'sent_at' => '', 'viewed_at' => '',
        'completed_at' => '', 'note' => '', 'created_at' => gmdate('c'),
    ];
    $first = ($doc['file'] ?? '') === ''; // a document recorded by hand gets its first PDF as version 1
    $doc['version'] = $first ? 1 : (int) $doc['version'] + 1;
    $doc['token'] = ($doc['token'] ?? '') !== '' ? $doc['token'] : bin2hex(random_bytes(16));
    if ($type === 'quotation' && ($doc['quote_no'] ?? '') === '') {
        $day = date('Ymd', (int) strtotime(crm_today()));
        $used = array_filter(array_column($db['documents'], 'quote_no'), static fn($q) => is_string($q) && str_starts_with($q, "Q-$day-"));
        $doc['quote_no'] = sprintf('Q-%s-%02d', $day, count($used) + 1);
    }
    $snap = crm_doc_snapshot($db, $p, $c, $type, $in, $doc);
    $base = "crm/files/{$doc['id']}-v{$doc['version']}";
    write_atomic(data_path("$base.json"), encode_json($snap));
    write_atomic(data_path("$base.pdf"), crm_doc_pdf($snap));
    $doc['file'] = "$base.pdf";
    $doc['generated_at'] = gmdate('c');
    if ($n === null) {
        $db['documents'][] = $doc;
    } else {
        $db['documents'][$n] = $doc;
    }
    crm_log($db, $p, $by, 'document', crm_doc_title($type) . ($first ? ' made' : " updated (version {$doc['version']})"));
    return $p['client_id'];
}

/** Make sure the project has an intake questionnaire link. Returns the client id. */
function crm_intake_link(string $pid, string $by): string
{
    return crm_tx(static fn(array &$db): string => crm_intake_link_in($db, $pid, $by));
}

/** crm_intake_link() inside a transaction that is already open (used by the automation too). */
function crm_intake_link_in(array &$db, string $pid, string $by): string
{
    $p = $db['projects'][crm_project_at($db, $pid)];
    foreach ($db['documents'] as $k => $d) {
        if ($d['project_id'] === $pid && $d['type'] === 'intake') {
            if (($d['token'] ?? '') === '') {
                $db['documents'][$k]['token'] = bin2hex(random_bytes(16));
            }
            return $p['client_id'];
        }
    }
    $db['documents'][] = [
        'id' => crm_id(), 'business' => CRM_BUSINESS, 'client_id' => $p['client_id'], 'project_id' => $pid, 'type' => 'intake',
        'token' => bin2hex(random_bytes(16)), 'file' => '', 'link' => '', 'version' => 1, 'status' => 'draft', 'sent_at' => '',
        'viewed_at' => '', 'completed_at' => '', 'note' => '', 'created_at' => gmdate('c'),
    ];
    crm_log($db, $p, $by, 'document', 'Intake questionnaire link made');
    return $p['client_id'];
}

/** Mark a document sent (or back to draft). Returns the client id. */
function crm_doc_status(string $docId, string $status, string $by): string
{
    $status = crm_pick($status, ['draft', 'sent'], 'status');
    return crm_tx(static function (array &$db) use ($docId, $status, $by): string {
        $k = crm_index($db['documents'], $docId);
        if ($k === null) {
            throw new ApiError('That document no longer exists.', 404);
        }
        $d = $db['documents'][$k];
        if (in_array($d['status'], ['completed', 'signed', 'paid'], true)) {
            throw new ApiError('This document is already ' . $d['status'] . '.');
        }
        $db['documents'][$k]['status'] = $status;
        $db['documents'][$k]['sent_at'] = $status === 'sent' ? ($d['sent_at'] ?: gmdate('c')) : '';
        $i = crm_index($db['projects'], $d['project_id']);
        if ($i !== null) {
            crm_log($db, $db['projects'][$i], $by, 'document', crm_doc_title($d['type']) . ($d['type'] === 'invoice' ? " {$d['invoice_no']}" : '') . ($status === 'sent' ? ' marked as sent' : ' back to draft'));
            if ($status === 'sent' && $d['type'] === 'agreement') {
                crm_invoice_auto_agreement($db, $db['projects'][$i], $by);
            }
        }
        return $d['client_id'];
    });
}

/** [absolute path, download name] of a document's PDF. */
function crm_doc_pdf_file(array $doc, array $client): array
{
    $path = ($doc['file'] ?? '') !== '' ? data_path($doc['file']) : '';
    if ($path === '' || !is_file($path)) {
        throw new ApiError('This document has no PDF yet.', 404);
    }
    $who = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $client['business_name'] ?: $client['contact_name']), '-');
    $what = $doc['type'] === 'invoice' ? (string) $doc['invoice_no'] : str_replace(' ', '-', crm_doc_title($doc['type']));
    return [$path, "STUDIIO-$what-" . ($who ?: 'client') . ($doc['version'] > 1 ? "-v{$doc['version']}" : '') . '.pdf'];
}

/** [path, download name] of a document's PDF, for the admin. */
function crm_doc_admin_pdf(string $docId): array
{
    $db = crm_load();
    $k = crm_index($db['documents'], $docId);
    if ($k === null) {
        throw new ApiError('That document no longer exists.', 404);
    }
    $ci = crm_index($db['clients'], $db['documents'][$k]['client_id']);
    return crm_doc_pdf_file($db['documents'][$k], $ci === null ? ['business_name' => '', 'contact_name' => ''] : $db['clients'][$ci]);
}

/** A message from messages.json, filled in for this project (and document, if any). */
function crm_message_draft(string $pid, string $key, string $docId = ''): array
{
    $tpls = crm_tpl_json('messages');
    if (!preg_match('/^[a-z_]+$/', $key) || !is_array($tpls[$key] ?? null)) {
        throw new ApiError('Unknown message template.');
    }
    $db = crm_load();
    $p = $db['projects'][crm_project_at($db, $pid)];
    $c = $db['clients'][crm_index($db['clients'], $p['client_id'])];
    $link = '';
    if ($docId !== '') {
        $k = crm_index($db['documents'], $docId);
        if ($k === null || $db['documents'][$k]['project_id'] !== $pid) {
            throw new ApiError('That document no longer exists.', 404);
        }
        $link = crm_doc_link($db['documents'][$k]);
    }
    $extra = ['link' => $link];
    if ($docId !== '' && $db['documents'][$k]['type'] === 'invoice') {
        $inv = $db['documents'][$k];
        $extra += ['invoice_no' => $inv['invoice_no'], 'invoice_amount' => crm_usd(max(0, (float) $inv['amount'] - crm_invoice_paid($db, $inv['id']))),
            'invoice_due' => $inv['due_date'] !== '' ? crm_long_date($inv['due_date']) : lcfirst((string) (crm_tpl_json('labels')['invoice']['on_receipt'] ?? 'on receipt'))];
    }
    $vars = crm_doc_vars($db, $p, $c, $extra);
    return ['subject' => crm_tpl_text((string) ($tpls[$key]['subject'] ?? ''), $vars), 'text' => crm_tpl_text((string) $tpls[$key]['text'], $vars)];
}

/** key => label of the message templates. */
function crm_message_templates(): array
{
    $out = [];
    $vars = ['checkin_days' => (string) crm_settings()['terms']['checkin_days_after_delivery']];
    foreach (crm_tpl_json('messages') as $key => $t) {
        if (is_array($t) && isset($t['text'])) {
            $out[$key] = crm_tpl_vars((string) ($t['label'] ?? $key), $vars);
        }
    }
    return $out;
}

/** The intake answers of a project as [[question, answer], …] for the admin. */
function crm_intake_view(array $p): array
{
    if (empty($p['intake']['answers'])) {
        return [];
    }
    $rows = [];
    foreach (crm_intake_questions($p['track']) as $q) {
        $a = crm_answer($p, $q['id']);
        if ($a !== '') {
            $rows[] = [$q['label'], $a];
        }
    }
    return $rows;
}

/* --------------------------------------------------------------------------
   PDF layout
   -------------------------------------------------------------------------- */

const CRM_INK = [17, 17, 17];
const CRM_GREY = [128, 128, 128];
const CRM_RULE = [214, 214, 214];
const CRM_BLACK = [0, 0, 0];
const CRM_WHITE = [255, 255, 255];

/** Wrap runs into lines of segments [[text, bold], …] that fit $max points. */
function crm_pdf_wrap(array $runs, float $max, float $size): array
{
    $lines = [[]];
    $w = 0.0;
    $push = static function (string $text, bool $bold) use (&$lines, &$w, $size): void {
        $lines[count($lines) - 1][] = [$text, $bold];
        $w += Pdf::width($text, $bold, $size);
    };
    foreach ($runs as [$text, $bold]) {
        foreach (preg_split('/(\n| +)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $part) {
            if ($part === "\n") {
                $lines[] = [];
                $w = 0.0;
                continue;
            }
            if (trim($part) === '') {
                if ($w > 0) {
                    $push(' ', $bold);
                }
                continue;
            }
            $pw = Pdf::width($part, $bold, $size);
            if ($w > 0 && $w + $pw > $max) {
                $lines[] = [];
                $w = 0.0;
            }
            while ($pw > $max) { // a word longer than the line (a long link): cut it
                $cut = mb_strlen($part);
                while ($cut > 1 && Pdf::width(mb_substr($part, 0, $cut), $bold, $size) > $max) {
                    $cut--;
                }
                $push(mb_substr($part, 0, $cut), $bold);
                $lines[] = [];
                $w = 0.0;
                $part = mb_substr($part, $cut);
                $pw = Pdf::width($part, $bold, $size);
            }
            $push($part, $bold);
        }
    }
    foreach ($lines as &$line) {
        while ($line && trim(end($line)[0]) === '') {
            array_pop($line);
        }
    }
    return $lines;
}

/** A document snapshot → PDF bytes, in the quotation's look (black band, tracked type, hairlines). */
function crm_doc_pdf(array $snap): string
{
    $pdf = new Pdf();
    $L = 56.0;
    $R = Pdf::W - 56.0;
    $band = 118.0;
    $bottom = 84.0;
    $top = Pdf::H - 64.0;
    $pdf->addPage();
    $pdf->rect(0, Pdf::H - $band, Pdf::W, $band, CRM_BLACK);
    $pdf->logo($L, Pdf::H - $band + 50, 17, CRM_WHITE);
    $pdf->textRight($R, Pdf::H - $band + 53, mb_strtoupper($snap['title']), false, 10.5, CRM_WHITE, 3.2);
    $y = Pdf::H - $band - 54;
    $pdf->text($L, $y, mb_strtoupper($snap['labels']['prepared_for']), false, 7.5, CRM_GREY, 1.6);
    $who = crm_pdf_wrap([[$snap['prepared_for'], true]], 260, 13.5);
    $wy = $y - 19;
    foreach ($who as $line) {
        $pdf->text($L, $wy, implode('', array_column($line, 0)), true, 13.5, CRM_INK);
        $wy -= 17;
    }
    $my = $y;
    foreach ($snap['meta'] as [$label, $value]) {
        $pdf->textRight($R - 92, $my, mb_strtoupper((string) $label), false, 7.5, CRM_GREY, 1.4);
        $pdf->textRight($R, $my, (string) $value, true, 9.5, CRM_INK);
        $my -= 17;
    }
    $y = min($wy, $my) - 26;

    $ensure = static function (float $need) use (&$y, $pdf, $bottom, $top): void {
        if ($y - $need < $bottom) {
            $pdf->addPage();
            $y = $top;
        }
    };
    $drawLines = static function (array $lines, float $x, float $size, float $lead, array $color, float $maxW = 0) use (&$y, $pdf, $ensure): void {
        foreach ($lines as $line) {
            $ensure($lead);
            $y -= $lead;
            $cx = $x;
            foreach ($line as [$text, $bold]) {
                $pdf->text($cx, $y, $text, $bold, $size, $color);
                $cx += Pdf::width($text, $bold, $size);
            }
        }
    };
    $width = $R - $L;
    $first = true;
    foreach ($snap['blocks'] as $b) {
        switch ($b['t']) {
            case 'page':
                $pdf->addPage();
                $y = $top;
                break;
            case 'h1':
                $lines = crm_pdf_wrap($b['runs'], $width, 19);
                $ensure(24 * count($lines) + 40);
                $y -= $first ? 4 : 14;
                $drawLines(array_map(static fn($l) => array_map(static fn($s) => [$s[0], true], $l), $lines), $L, 19, 24, CRM_INK);
                $y -= 8;
                break;
            case 'h2':
            case 'h3':
                $size = $b['t'] === 'h2' ? 12.0 : 10.5;
                $lines = crm_pdf_wrap($b['runs'], $width, $size);
                $ensure(16 * count($lines) + 52);
                $y -= $b['t'] === 'h2' ? 16 : 8;
                $drawLines(array_map(static fn($l) => array_map(static fn($s) => [$s[0], true], $l), $lines), $L, $size, $size + 5, CRM_INK);
                $y -= 3;
                break;
            case 'label':
                $ensure(40);
                $y -= 20;
                $pdf->text($L, $y, mb_strtoupper($b['text']), false, 7.5, CRM_GREY, 1.6);
                $y -= 2;
                break;
            case 'p':
                $lines = crm_pdf_wrap($b['runs'], $width, 10);
                if (count($lines) <= 5) {
                    $ensure(15 * count($lines)); // keep short blocks (a signature block) on one page
                }
                $drawLines($lines, $L, 10, 15, CRM_INK);
                $y -= 6;
                break;
            case 'ul':
            case 'ol':
                foreach ($b['items'] as $n => $runs) {
                    $mark = $b['t'] === 'ul' ? "\u{2022}" : ($n + 1) . '.';
                    $lines = crm_pdf_wrap($runs, $width - 16, 10);
                    $ensure(15);
                    $pdf->text($L + 2, $y - 15, $mark, $b['t'] === 'ol', 10, CRM_INK);
                    $drawLines($lines, $L + 16, 10, 15, CRM_INK);
                    $y -= 2;
                }
                $y -= 5;
                break;
            case 'quote':
                [$lDesc, $lAmount, $lTotal] = $b['labels'];
                $ensure(120);
                $y -= 8;
                $pdf->text($L, $y, mb_strtoupper($lDesc), false, 7.5, CRM_GREY, 1.6);
                $pdf->textRight($R, $y, mb_strtoupper($lAmount), false, 7.5, CRM_GREY, 1.6);
                $y -= 8;
                $pdf->line($L, $y, $R, $y, 1.2, CRM_BLACK);
                $y -= 4;
                $amountW = Pdf::width($b['amount'], true, 11) + 24;
                $drawLines(crm_pdf_wrap([[$b['title'], true]], $width - $amountW, 11), $L, 11, 18, CRM_INK);
                $pdf->textRight($R, $y, $b['amount'], true, 11, CRM_INK);
                foreach ($b['details'] as $d) {
                    $drawLines(crm_pdf_wrap([[(string) $d, false]], $width - $amountW, 9), $L, 9, 13.5, CRM_GREY);
                }
                $y -= 16;
                $pdf->line($L, $y, $R, $y, 0.5, CRM_RULE);
                $y -= 30;
                $pdf->line($R - 170, $y + 16, $R, $y + 16, 0.9, CRM_BLACK);
                $pdf->text($R - 170, $y, mb_strtoupper($lTotal), false, 9, CRM_INK, 2);
                $pdf->textRight($R, $y, $b['total'], true, 15, CRM_INK);
                foreach ($b['extra'] ?? [] as $n => [$label, $value]) {
                    $last = $n === count($b['extra']) - 1;
                    $y -= $last ? 24 : 20;
                    $pdf->text($R - 170, $y, mb_strtoupper((string) $label), false, 8, $last ? CRM_INK : CRM_GREY, 1.6);
                    $pdf->textRight($R, $y, (string) $value, $last, $last ? 13 : 10, $last ? CRM_INK : CRM_GREY);
                }
                $y -= 12;
                break;
        }
        $first = false;
    }

    $total = $pdf->pageCount();
    for ($i = 0; $i < $total; $i++) {
        $pdf->onPage($i);
        $pdf->line($L, 58, $R, 58, 0.5, CRM_RULE);
        $pdf->logo($L, 38.5, 7.5, CRM_BLACK);
        $right = implode('     ', $snap['contact']);
        $page = crm_tpl_vars($snap['labels']['page'], ['n' => (string) ($i + 1), 'total' => (string) $total]);
        $pdf->textRight($R, 39.5, $right . ($total > 1 ? "     $page" : ''), false, 8, CRM_GREY);
    }
    return $pdf->output($snap['title'] . ' · ' . $snap['prepared_for']);
}

/* --------------------------------------------------------------------------
   Client-facing web pages (client/index.php)
   -------------------------------------------------------------------------- */

/** The STUDIIO logo as inline SVG (the letters only), for the web pages. */
function crm_logo_svg(string $class = 'logo'): string
{
    [$paths, $minX, $minY, $maxX, $maxY] = Pdf::logoPaths();
    $d = '';
    foreach ($paths as $path) {
        foreach ($path as $seg) {
            $d .= match ($seg[0]) {
                'M' => sprintf('M%g %g', $seg[1], $seg[2]),
                'L' => sprintf('L%g %g', $seg[1], $seg[2]),
                'C' => sprintf('C%.2f %.2f %.2f %.2f %g %g', ...array_slice($seg, 1)),
                default => 'Z',
            };
        }
    }
    return sprintf('<svg class="%s" viewBox="%g %g %g %g" role="img" aria-label="STUDIIO"><path fill="currentColor" fill-rule="evenodd" d="%s"/></svg>',
        htmlspecialchars($class), $minX, $minY, $maxX - $minX, $maxY - $minY, $d);
}

function crm_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Runs → HTML: escaped, bold, line breaks, links clickable. */
function crm_runs_html(array $runs): string
{
    $out = '';
    foreach ($runs as [$text, $bold]) {
        $html = nl2br(crm_h($text), false);
        $html = (string) preg_replace_callback('#https?://[^\s<]+[^\s<.,;:!?)\]\'"]#i', static fn($m) => '<a href="' . $m[0] . '" rel="noopener nofollow" target="_blank">' . $m[0] . '</a>', $html);
        $out .= $bold ? "<strong>$html</strong>" : $html;
    }
    return $out;
}

function crm_blocks_html(array $blocks): string
{
    $out = '';
    foreach ($blocks as $b) {
        switch ($b['t']) {
            case 'h1':
            case 'h2':
            case 'h3':
                $out .= "<{$b['t']}>" . crm_runs_html(array_map(static fn($r) => [$r[0], false], $b['runs'])) . "</{$b['t']}>\n";
                break;
            case 'p':
                $out .= '<p>' . crm_runs_html($b['runs']) . "</p>\n";
                break;
            case 'ul':
            case 'ol':
                $out .= "<{$b['t']}>" . implode('', array_map(static fn($r) => '<li>' . crm_runs_html($r) . '</li>', $b['items'])) . "</{$b['t']}>\n";
                break;
            case 'label':
                $out .= '<p class="label">' . crm_h($b['text']) . "</p>\n";
                break;
            case 'quote':
                [$lDesc, $lAmount, $lTotal] = $b['labels'];
                $out .= '<table class="quote"><thead><tr><th>' . crm_h($lDesc) . '</th><th>' . crm_h($lAmount) . '</th></tr></thead><tbody><tr><td><strong>'
                    . crm_h($b['title']) . '</strong>' . implode('', array_map(static fn($d) => '<span>' . crm_h((string) $d) . '</span>', $b['details']))
                    . '</td><td><strong>' . crm_h($b['amount']) . '</strong></td></tr></tbody><tfoot><tr><td>' . crm_h($lTotal) . '</td><td>'
                    . crm_h($b['total']) . '</td></tr>'
                    . implode('', array_map(static fn($r) => '<tr class="extra"><td>' . crm_h((string) $r[0]) . '</td><td>' . crm_h((string) $r[1]) . '</td></tr>', $b['extra'] ?? []))
                    . "</tfoot></table>\n";
                break;
        }
    }
    return $out;
}

/** A full client-facing page: black band with the logo, then $body. */
function crm_page(string $title, string $bandTitle, string $body, array $contact): string
{
    $css = '/client/client.css?v=' . (@filemtime(REPO_DIR . '/client/client.css') ?: 1);
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow"><title>' . crm_h($title) . '</title>'
        . '<link rel="icon" href="/favicon.svg" type="image/svg+xml">'
        . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700&display=swap">'
        . '<link rel="stylesheet" href="' . crm_h($css) . '"></head><body>'
        . '<header class="band"><div class="wrap">' . crm_logo_svg() . '<span class="band-title">' . crm_h(mb_strtoupper($bandTitle)) . '</span></div></header>'
        . '<main class="wrap">' . $body . '</main>'
        . '<footer class="wrap foot">' . crm_logo_svg('logo small') . '<span>' . crm_h(implode('  ·  ', $contact)) . '</span></footer>'
        . '</body></html>';
}

function crm_doc_html(array $snap, string $pdfUrl, string $after = '', string $notice = ''): string
{
    $meta = '';
    foreach ($snap['meta'] as [$label, $value]) {
        $meta .= '<div><dt>' . crm_h((string) $label) . '</dt><dd>' . crm_h((string) $value) . '</dd></div>';
    }
    $body = '<div class="doc-head"><div><p class="label">' . crm_h($snap['labels']['prepared_for']) . '</p><p class="who">' . crm_h($snap['prepared_for']) . '</p></div>'
        . '<dl class="meta">' . $meta . '</dl></div>'
        . ($notice !== '' ? '<p class="notice" role="status">' . crm_h($notice) . '</p>' : '')
        . '<article class="doc doc-' . crm_h($snap['type']) . '">' . crm_blocks_html($snap['blocks']) . '</article>'
        . $after
        . '<p class="actions"><a class="btn" href="' . crm_h($pdfUrl) . '">' . crm_h($snap['labels']['download']) . '</a></p>';
    return crm_page($snap['title'] . ' · ' . $snap['prepared_for'], $snap['title'], $body, $snap['contact']);
}

/** Find the document behind a client link. Returns [$db, $doc, $project, $client] or null. */
function crm_public_find(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return null;
    }
    $db = crm_load();
    foreach ($db['documents'] as $doc) {
        if (($doc['token'] ?? '') !== '' && hash_equals($doc['token'], $token)) {
            $i = crm_index($db['projects'], $doc['project_id']);
            $ci = $i === null ? null : crm_index($db['clients'], $db['projects'][$i]['client_id']);
            if ($i === null || $ci === null || $db['projects'][$i]['status'] === 'lost') {
                return null;
            }
            return [$db, $doc, $db['projects'][$i], $db['clients'][$ci]];
        }
    }
    return null;
}

/** The client opened their link: sent → viewed. */
function crm_public_viewed(string $docId): void
{
    crm_tx(static function (array &$db) use ($docId): void {
        $k = crm_index($db['documents'], $docId);
        if ($k !== null && $db['documents'][$k]['status'] === 'sent') {
            $db['documents'][$k]['status'] = 'viewed';
            $db['documents'][$k]['viewed_at'] = gmdate('c');
            $i = crm_index($db['projects'], $db['documents'][$k]['project_id']);
            if ($i !== null) {
                crm_log($db, $db['projects'][$i], 'client', 'document', crm_doc_title($db['documents'][$k]['type'] === 'intake' ? 'intake' : $db['documents'][$k]['type']) . ' opened by the client');
            }
        }
    });
}

/* --------------------------------------------------------------------------
   Intake questionnaire
   -------------------------------------------------------------------------- */

/** The questions for a track, budget options filled in from settings (and skipped while empty). */
function crm_intake_questions(string $track): array
{
    $out = [];
    $budget = crm_settings()['terms']['budget_ranges'];
    foreach (crm_tpl_json('intake')['sections'] ?? [] as $si => $section) {
        foreach ($section['questions'] ?? [] as $q) {
            if (isset($q['track']) && $q['track'] !== $track) {
                continue;
            }
            if ($q['type'] === 'budget') {
                if (!$budget) {
                    continue;
                }
                $q['type'] = 'choice';
                $q['options'] = array_values(array_map('strval', $budget));
            }
            $q['section'] = $si;
            $out[] = $q;
        }
    }
    return $out;
}

/** Is the intake form still open for changes? */
function crm_intake_editable(array $p): bool
{
    $until = (string) (crm_settings()['documents']['intake_editable_until'] ?? 'proposal');
    return $p['status'] === 'open' && (empty($p['intake']['submitted_at']) || crm_stage_no($p['stage']) <= crm_stage_no(isset(CRM_STAGES[$until]) ? $until : 'proposal'));
}

/** Check the posted answers. Returns [$answers, $errors (question labels)]. */
function crm_intake_read(array $questions, array $post): array
{
    $a = is_array($post['a'] ?? null) ? $post['a'] : [];
    $o = is_array($post['o'] ?? null) ? $post['o'] : [];
    $answers = [];
    $missing = [];
    foreach ($questions as $q) {
        $id = $q['id'];
        $other = crm_text($o[$id] ?? '', 200);
        $v = $a[$id] ?? '';
        switch ($q['type']) {
            case 'multi':
                $picked = array_values(array_intersect((array) $v, $q['options']));
                if (!empty($q['other']) && $other !== '') {
                    $picked[] = $other;
                }
                $value = $picked;
                break;
            case 'choice':
                $v = is_string($v) ? $v : '';
                $value = in_array($v, $q['options'], true) ? $v : ($v === '__other' && !empty($q['other']) ? $other : '');
                break;
            default:
                $value = crm_text($v, $q['type'] === 'textarea' ? 3000 : 300);
                if ($q['type'] === 'textarea') {
                    $value = mb_substr(trim(str_replace("\r\n", "\n", is_string($v) ? $v : '')), 0, 3000);
                }
        }
        if ($value === '' || $value === []) {
            if (!empty($q['required'])) {
                $missing[] = $q['label'];
            }
            continue;
        }
        $answers[$id] = $value;
    }
    return [$answers, $missing];
}

/** Save the client's answers. Returns the client id. */
function crm_intake_save(string $docId, array $answers, array $questions): string
{
    return crm_tx(static function (array &$db) use ($docId, $answers, $questions): string {
        $k = crm_index($db['documents'], $docId);
        if ($k === null) {
            throw new ApiError('This link no longer works.', 404);
        }
        $doc = $db['documents'][$k];
        $i = crm_project_at($db, $doc['project_id']);
        $p = $db['projects'][$i];
        if (!crm_intake_editable($p)) {
            throw new ApiError('The questionnaire is closed.', 409);
        }
        $ci = crm_index($db['clients'], $p['client_id']);
        $c = $db['clients'][$ci];
        $again = !empty($p['intake']['submitted_at']);
        $p['intake'] = ['answers' => $answers, 'submitted_at' => gmdate('c'), 'first_submitted_at' => $p['intake']['first_submitted_at'] ?? gmdate('c')];
        foreach ($questions as $q) {
            $to = $q['saves_to'] ?? '';
            $v = $answers[$q['id']] ?? '';
            if (!in_array($to, CRM_INTAKE_SAVES, true) || !is_string($v) || $v === '') {
                continue;
            }
            [$where, $field] = explode('.', $to);
            if ($to === 'client.channel') {
                $c['channel'] = strtolower($v) === 'email' ? 'email' : 'whatsapp';
            } elseif ($where === 'client' && ($c[$field] ?? '') === '') {
                $c[$field] = mb_substr($v, 0, 200);
            } elseif ($where === 'project' && ($p[$field] ?? '') === '') {
                $p[$field] = mb_substr($v, 0, 120);
            }
        }
        $records = is_array($p['records'] ?? null) ? $p['records'] : [];
        $records['intake'] ??= ['date' => crm_today(), 'note' => 'Submitted by the client', 'link' => '', 'by' => 'client', 'at' => gmdate('c')];
        $p['records'] = $records;
        $p['updated_at'] = gmdate('c');
        $db['projects'][$i] = $p;
        $db['clients'][$ci] = $c;
        $db['documents'][$k]['status'] = 'completed';
        $db['documents'][$k]['completed_at'] = crm_today();
        crm_intake_fill_checklist($db, $p);
        crm_log($db, $p, 'client', 'record', $again ? 'Intake answers updated by the client' : 'Intake questionnaire submitted by the client');
        return $p['client_id'];
    });
}

/** The questionnaire as a page. $state: form, thanks or closed. */
function crm_intake_html(array $p, array $c, string $token, string $state, array $values = [], array $missing = [], string $notice = ''): string
{
    $tpl = crm_tpl_json('intake');
    $contact = array_values(array_filter([(string) (crm_settings()['contact']['email'] ?? ''), (string) (crm_settings()['contact']['phone'] ?? '')]));
    $first = trim(explode(' ', trim((string) $c['contact_name']))[0] ?? '');
    $title = (string) ($tpl['title'] ?? 'Intake');
    if ($state !== 'form') {
        $body = '<section class="done"><h1>' . crm_h((string) ($state === 'thanks' ? $tpl['thanks_title'] : $tpl['thanks_title'])) . '</h1><p>'
            . crm_h((string) ($state === 'thanks' ? $tpl['thanks'] : $tpl['closed'])) . '</p>'
            . ($state === 'thanks' && crm_intake_editable($p) ? '<p><a class="btn ghost" href="?t=' . crm_h($token) . '&amp;edit=1">' . crm_h((string) $tpl['change']) . '</a></p>' : '')
            . '</section>';
        return crm_page($title, (string) ($tpl['band'] ?? 'Intake'), $body, $contact);
    }
    $questions = crm_intake_questions($p['track']);
    $sections = $tpl['sections'] ?? [];
    $html = '<h1>' . crm_h(($first !== '' ? "$first, " . lcfirst($title) : $title)) . '</h1><p class="intro">' . crm_h((string) $tpl['intro']) . '</p>';
    if ($missing) {
        $html .= '<p class="error" role="alert">' . crm_h(crm_tpl_vars((string) $tpl['missing'], ['questions' => implode('; ', $missing)])) . '</p>';
    }
    if ($notice !== '') {
        $html .= '<p class="error" role="alert">' . crm_h($notice) . '</p>';
    }
    $html .= '<form method="post" class="intake" action="?t=' . crm_h($token) . '">'
        . '<p class="hint">' . crm_h((string) $tpl['required_note']) . '</p>'
        . '<div class="hp" aria-hidden="true"><label>Leave this empty <input type="text" name="hp_website" tabindex="-1" autocomplete="off"></label></div>';
    $current = null;
    foreach ($questions as $q) {
        if ($q['section'] !== $current) {
            $html .= ($current !== null ? '</fieldset>' : '') . '<fieldset class="section"><legend>' . crm_h((string) ($sections[$q['section']]['title'] ?? '')) . '</legend>';
            $current = $q['section'];
        }
        $id = $q['id'];
        $name = 'a[' . crm_h($id) . ']';
        $req = !empty($q['required']);
        $label = crm_h($q['label']) . ($req ? ' <span class="req" aria-hidden="true">*</span>' : '');
        $hint = isset($q['hint']) ? '<small>' . crm_h((string) $q['hint']) . '</small>' : '';
        $v = $values[$id] ?? '';
        $bad = in_array($q['label'], $missing, true) ? ' bad' : '';
        if ($q['type'] === 'choice' || $q['type'] === 'multi') {
            $multi = $q['type'] === 'multi';
            $picked = (array) $v;
            $otherVal = '';
            foreach ($picked as $pv) {
                if (!in_array($pv, $q['options'], true)) {
                    $otherVal = (string) $pv;
                }
            }
            $html .= '<fieldset class="q' . $bad . '"><legend>' . $label . '</legend>' . $hint . '<div class="opts">';
            foreach ($q['options'] as $opt) {
                $html .= '<label class="opt"><input type="' . ($multi ? 'checkbox' : 'radio') . '" name="' . $name . ($multi ? '[]' : '') . '" value="' . crm_h($opt) . '"'
                    . (in_array($opt, $picked, true) ? ' checked' : '') . ($req && !$multi ? ' required' : '') . '><span>' . crm_h($opt) . '</span></label>';
            }
            if (!empty($q['other'])) {
                if (!$multi) {
                    $html .= '<label class="opt"><input type="radio" name="' . $name . '" value="__other"' . ($otherVal !== '' ? ' checked' : '') . '><span>' . crm_h((string) $tpl['other']) . '</span></label>';
                }
                $html .= '<input class="other" type="text" name="o[' . crm_h($id) . ']" value="' . crm_h($otherVal) . '" placeholder="' . crm_h((string) $tpl['other']) . '…" aria-label="' . crm_h((string) $tpl['other']) . '" maxlength="200">';
            }
            $html .= '</div></fieldset>';
        } else {
            $field = $q['type'] === 'textarea'
                ? '<textarea name="' . $name . '" rows="3" maxlength="3000"' . ($req ? ' required' : '') . '>' . crm_h((string) $v) . '</textarea>'
                : '<input type="text" name="' . $name . '" value="' . crm_h((string) $v) . '" maxlength="300"' . ($req ? ' required' : '') . '>';
            $html .= '<label class="q' . $bad . '"><span class="ql">' . $label . '</span>' . $hint . $field . '</label>';
        }
    }
    $html .= ($current !== null ? '</fieldset>' : '') . '<button class="btn" type="submit">' . crm_h((string) $tpl['submit']) . '</button></form>';
    return crm_page($title, (string) ($tpl['band'] ?? 'Intake'), $html, $contact);
}

/** Per-visitor limit on form posts (same idea as contact.php). Returns false when over the limit. */
function crm_public_rate_ok(int $max = 20, int $window = 600): bool
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $bin = @inet_pton($ip);
    $key = ($bin !== false && strlen($bin) === 16) ? bin2hex(substr($bin, 0, 8)) : $ip;
    $fh = @fopen(data_path('ratelimit/client-' . sha1($key)), 'c+');
    if (!$fh || !flock($fh, LOCK_EX)) {
        return true;
    }
    $now = time();
    $history = array_filter(array_map('intval', explode(',', (string) stream_get_contents($fh))), static fn(int $t): bool => $t > $now - $window);
    $ok = count($history) < $max;
    if ($ok) {
        $history[] = $now;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, implode(',', $history));
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $ok;
}

/* --------------------------------------------------------------------------
   Template checks (admin/tools/check-crm.php)
   -------------------------------------------------------------------------- */

/** file => problems, for every document, message and form template. */
function crm_template_errors(): array
{
    $out = [];
    $sample = static function (string $track): array {
        $p = ['id' => 'p1', 'client_id' => 'c1', 'title' => 'Sample', 'track' => $track, 'price' => 1000, 'payment_schedule' => 'Half now, half later',
            'revision_rounds' => 2, 'deliverables' => ['One'], 'exclusions' => ['Two'], 'shoot_dates' => [], 'intake' => [], 'scope' => 'Scope'];
        $c = ['business_name' => 'Sample Co', 'contact_name' => 'Sam Sample', 'channel' => 'whatsapp'];
        return crm_doc_vars(['checklist' => [], 'payments' => [], 'events' => []], $p, $c, ['link' => 'https://example.com']);
    };
    $known = array_keys($sample('production'));
    $names = static function (string $text): array {
        preg_match_all('/\{\{\s*[#^\/]?([a-z_]+)\s*\}\}/', $text, $m);
        return array_unique($m[1]);
    };
    $partials = static function (string $text): array {
        preg_match_all('/\{\{>\s*([a-z_]+)\s*\}\}/', $text, $m);
        return $m[1];
    };
    $docs = [...CRM_MAKE_TYPES, 'feedback_rules'];
    foreach (array_diff($docs, ['quotation']) as $name) {
        $errors = [];
        try {
            $src = crm_tpl_strip(crm_tpl_file($name));
            foreach (array_diff($names($src), $known) as $bad) {
                $errors[] = "{{{$bad}}} is not a placeholder the admin fills in.";
            }
            foreach ($partials($src) as $inc) {
                if (!is_file(crm_dir() . "/templates/en/$inc.md")) {
                    $errors[] = "{{> $inc}} includes a template that doesn't exist.";
                }
            }
            foreach (array_keys(crm_settings()['tracks']) as $track) {
                crm_md_blocks(crm_tpl_md($name, $sample($track)));
            }
        } catch (ApiError $e) {
            $errors[] = $e->getMessage();
        }
        $out["$name.md"] = $errors;
    }
    $errors = [];
    try {
        foreach (crm_tpl_json('messages') as $key => $t) {
            if (str_starts_with((string) $key, '_')) {
                continue;
            }
            if (!is_array($t) || !is_string($t['text'] ?? null) || trim($t['text']) === '') {
                $errors[] = "$key needs a text.";
                continue;
            }
            foreach (array_diff($names($t['text'] . ' ' . ($t['subject'] ?? '') . ' ' . ($t['label'] ?? '')), $known) as $bad) {
                $errors[] = "$key: {{{$bad}}} is not a placeholder the admin fills in.";
            }
            crm_tpl_text($t['text'], $sample('production'));
        }
        foreach (CRM_MAKE_TYPES as $type) {
            if ($type !== 'kickoff_agenda' && !isset(crm_tpl_json('messages')[$type . '_link'])) {
                $errors[] = "{$type}_link is missing (the message that sends the " . str_replace('_', ' ', $type) . ').';
            }
        }
        foreach (['portal_link', 'deliverables_ready'] as $key) {
            if (!isset(crm_tpl_json('messages')[$key])) {
                $errors[] = "$key is missing (a client portal message).";
            }
        }
        if (!isset(crm_tpl_json('messages')['invoice_link'])) {
            $errors[] = 'invoice_link is missing (the message that sends an invoice).';
        }
        if (!isset(crm_tpl_json('messages')['intake_link'])) {
            $errors[] = 'intake_link is missing (the message that sends the questionnaire).';
        }
    } catch (ApiError $e) {
        $errors[] = $e->getMessage();
    }
    $out['messages.json'] = $errors;
    $errors = [];
    try {
        $intake = crm_tpl_json('intake');
        $ids = [];
        foreach ((array) ($intake['sections'] ?? []) as $si => $section) {
            foreach ((array) ($section['questions'] ?? []) as $qi => $q) {
                $where = 'section ' . ($si + 1) . ' question ' . ($qi + 1);
                $id = (string) ($q['id'] ?? '');
                if (!preg_match('/^[a-z_]+$/', $id) || isset($ids[$id])) {
                    $errors[] = "$where: id must be unique, lowercase letters and _.";
                }
                $ids[$id] = true;
                if (!is_string($q['label'] ?? null) || trim($q['label']) === '') {
                    $errors[] = "$where needs a label.";
                }
                $type = $q['type'] ?? '';
                if (!in_array($type, ['text', 'textarea', 'choice', 'multi', 'budget'], true)) {
                    $errors[] = "$where: type must be text, textarea, choice, multi or budget.";
                }
                if (in_array($type, ['choice', 'multi'], true) && (!is_array($q['options'] ?? null) || !$q['options'])) {
                    $errors[] = "$where needs options.";
                }
                if (isset($q['saves_to']) && !in_array($q['saves_to'], CRM_INTAKE_SAVES, true)) {
                    $errors[] = "$where: saves_to must be one of " . implode(', ', CRM_INTAKE_SAVES) . '.';
                }
                if (isset($q['track']) && !isset(crm_settings()['tracks'][$q['track']])) {
                    $errors[] = "$where: track \"{$q['track']}\" is not in settings.json.";
                }
            }
        }
        foreach (['title', 'intro', 'submit', 'thanks_title', 'thanks', 'closed', 'missing'] as $k) {
            if (!is_string($intake[$k] ?? null)) {
                $errors[] = "\"$k\" (page wording) is missing.";
            }
        }
    } catch (ApiError $e) {
        $errors[] = $e->getMessage();
    }
    $out['intake.json'] = $errors;
    $errors = [];
    try {
        $labels = crm_tpl_json('labels');
        foreach (['billed_to', 'number', 'due', 'on_receipt', 'paid', 'amount_due', 'how_to_pay', 'methods', 'deposit', 'final', 'monthly'] as $key) {
            if (!is_string($labels['invoice'][$key] ?? null)) {
                $errors[] = "invoice.$key (invoice wording) is missing.";
            }
        }
        foreach (['hello', 'intro', 'where', 'need', 'documents', 'review', 'round', 'round_info', 'feedback_title', 'approve_title', 'approved', 'approved_thanks', 'download', 'errors', 'stages', 'dates'] as $key) {
            if (!isset($labels['portal'][$key])) {
                $errors[] = "portal.$key (client portal wording) is missing.";
            }
        }
        foreach (array_keys(CRM_STAGES) as $stage) {
            if (!is_string($labels['portal']['stages'][$stage] ?? null)) {
                $errors[] = "portal.stages.$stage (what the client sees for that stage) is missing.";
            }
        }
        foreach (['sign_title', 'accept_title', 'agree_sign', 'agree_accept', 'signed', 'accepted', 'thanks_signed', 'thanks_accepted'] as $key) {
            if (!is_string($labels['sign'][$key] ?? null)) {
                $errors[] = "sign.$key (signing wording) is missing.";
            }
        }
        foreach ([...CRM_MAKE_TYPES, 'invoice'] as $type) {
            if (!is_string($labels['documents'][$type] ?? null)) {
                $errors[] = "documents.$type (the document's title) is missing.";
            }
        }
    } catch (ApiError $e) {
        $errors[] = $e->getMessage();
    }
    $out['labels.json'] = $errors;
    return $out;
}

