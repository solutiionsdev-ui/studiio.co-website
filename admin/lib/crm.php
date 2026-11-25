<?php
/**
 * STUDIIO.CO admin — the client system (Pipeline): clients, projects and their stages, stage
 * gates, checklists, documents, payments, the message log and an activity log.
 *
 * Records are personal data: they live in the private data folder (data/crm/<collection>.json,
 * outside the website on Hostinger), never in the public repo. Settings and client-facing
 * wording live in admin/crm/<business>/ (settings.json, templates/<lang>/*.json), so another
 * business (studiio.ai, solutiions.dev) is a new folder, not new code. Every record carries
 * its business key.
 */
declare(strict_types=1);

require_once __DIR__ . '/leads.php';

const CRM_BUSINESS = 'studiio.co';

/** Pipeline stages, in order. What it takes to leave each one is in crm_gate(). */
const CRM_STAGES = [
    'lead'          => 'Lead',
    'intake'        => 'Intake',
    'proposal'      => 'Proposal',
    'agreement'     => 'Agreement',
    'onboarding'    => 'Onboarding',
    'kickoff'       => 'Kickoff',
    'in_production' => 'In production',
    'review'        => 'Review',
    'delivered'     => 'Delivered',
    'offboarding'   => 'Offboarding',
    'follow_up'     => 'Follow-up',
];
const CRM_COLLECTIONS = ['clients', 'projects', 'checklist', 'documents', 'payments', 'messages', 'cycles', 'events', 'media', 'uploads'];
const CRM_DOC_TYPES = ['intake' => 'Intake questionnaire', 'proposal' => 'Proposal', 'quotation' => 'Quotation', 'agreement' => 'Service agreement', 'invoice' => 'Invoice', 'welcome_pack' => 'Welcome pack', 'kickoff_agenda' => 'Kickoff agenda', 'handover' => 'Handover'];
const CRM_DOC_STATUSES = ['draft', 'sent', 'viewed', 'completed', 'signed', 'paid'];
const CRM_ITEM_STATUSES = ['waiting', 'received', 'not_needed'];
const CRM_CATEGORIES = ['brand' => 'Brand', 'access' => 'Access', 'logistics' => 'Logistics', 'legal' => 'Legal'];
const CRM_BLOCKERS = ['', 'client', 'studiio'];
const CRM_CHANNELS = ['whatsapp' => 'WhatsApp', 'email' => 'Email'];
const CRM_PAYMENT_KINDS = ['deposit' => 'Deposit', 'final' => 'Final payment', 'monthly' => 'Monthly', 'other' => 'Other'];
const CRM_MESSAGE_CHANNELS = ['whatsapp' => 'WhatsApp', 'email' => 'Email', 'call' => 'Call', 'instagram' => 'Instagram', 'in_person' => 'In person', 'other' => 'Other'];

/**
 * Steps Mario records by hand for now (later phases fill most of them in automatically:
 * the intake form, signing in the portal, and so on). Steps tied to a document also
 * create or update that document.
 */
const CRM_RECORDS = [
    'qualified'   => ['label' => 'Marked as qualified'],
    'intake'      => ['label' => 'Intake questionnaire received', 'doc' => 'intake', 'status' => 'completed'],
    'proposal'    => ['label' => 'Proposal accepted in writing', 'doc' => 'proposal', 'status' => 'signed'],
    'agreement'   => ['label' => 'Agreement signed', 'doc' => 'agreement', 'status' => 'signed'],
    'kickoff'     => ['label' => 'Kickoff done'],
    'draft'       => ['label' => 'First draft delivered'],
    'approved'    => ['label' => 'Client approved in writing'],
    'testimonial' => ['label' => 'Testimonial requested'],
    'checkin'     => ['label' => 'Check-in sent'],
];

/* --------------------------------------------------------------------------
   Settings and templates (admin/crm/<business>/)
   -------------------------------------------------------------------------- */

function crm_dir(): string
{
    return ADMIN_DIR . '/crm/' . CRM_BUSINESS;
}

function crm_read_json(string $rel): array
{
    $file = crm_dir() . '/' . $rel;
    $raw = is_file($file) ? @file_get_contents($file) : false;
    if ($raw === false) {
        throw new ApiError('admin/crm/' . CRM_BUSINESS . "/$rel is missing.", 500);
    }
    try {
        $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new ApiError('admin/crm/' . CRM_BUSINESS . "/$rel is not valid JSON: " . $e->getMessage(), 500);
    }
    if (!is_array($data)) {
        throw new ApiError('admin/crm/' . CRM_BUSINESS . "/$rel should hold a JSON object.", 500);
    }
    return $data;
}

/** settings.json, checked. Throws a readable error if someone broke it while editing. */
function crm_settings(): array
{
    static $cache = null;
    if ($cache === null) {
        $data = crm_read_json('settings.json');
        $errors = crm_settings_errors($data);
        if ($errors) {
            throw new ApiError('admin/crm/' . CRM_BUSINESS . '/settings.json: ' . $errors[0], 500, ['errors' => $errors]);
        }
        $cache = $data;
    }
    return $cache;
}

function crm_settings_errors(array $s): array
{
    $e = [];
    if (($s['business']['key'] ?? '') !== CRM_BUSINESS) {
        $e[] = 'business.key must be "' . CRM_BUSINESS . '".';
    }
    if (!is_string($s['business']['currency'] ?? null) || !preg_match('/^[A-Z]{3}$/', $s['business']['currency'])) {
        $e[] = 'business.currency must be a 3-letter code such as USD.';
    }
    if (!in_array($s['business']['timezone'] ?? '', timezone_identifiers_list(), true)) {
        $e[] = 'business.timezone must be a time zone name such as Asia/Beirut.';
    }
    $tracks = $s['tracks'] ?? null;
    if (!is_array($tracks) || !$tracks || array_is_list($tracks)) {
        $e[] = 'tracks must list at least one track.';
        $tracks = [];
    }
    foreach ($tracks as $key => $t) {
        if (!preg_match('/^[a-z][a-z_]{1,30}$/', (string) $key)) {
            $e[] = "Track key \"$key\" must be lowercase letters.";
        }
        if (!is_array($t) || !is_string($t['label'] ?? null) || trim($t['label']) === '') {
            $e[] = "Track \"$key\" needs a label.";
        }
    }
    if (!is_array($s['sources'] ?? null) || !$s['sources'] || array_is_list($s['sources'])) {
        $e[] = 'sources must list at least one source (key: label).';
    }
    $t = $s['terms'] ?? null;
    if (!is_array($t)) {
        return [...$e, 'terms is missing.'];
    }
    $whole = [
        'deposit_percent' => [0, 100], 'revision_rounds' => [0, 20], 'client_feedback_working_days' => [1, 60],
        'studiio_reply_working_days' => [0, 30], 'reschedule_notice_hours' => [0, 720], 'retainer_minimum_months' => [0, 60],
        'retainer_notice_days' => [0, 365], 'delivery_link_days' => [1, 3650], 'checkin_days_after_delivery' => [1, 3650],
    ];
    foreach ($whole as $key => [$min, $max]) {
        if (!is_int($t[$key] ?? null) || $t[$key] < $min || $t[$key] > $max) {
            $e[] = "terms.$key must be a whole number from $min to $max.";
        }
    }
    foreach (array_keys($tracks) as $key) {
        if (!is_string($t['payment_schedule'][$key] ?? null)) {
            $e[] = "terms.payment_schedule needs a line for the $key track.";
        }
    }
    if (!is_bool($t['unused_visits_roll_over'] ?? null)) {
        $e[] = 'terms.unused_visits_roll_over must be true or false.';
    }
    foreach (['payment_methods', 'client_languages'] as $key) {
        $list = $t[$key] ?? null;
        if (!is_array($list) || !$list || !array_is_list($list) || array_filter($list, static fn($v) => !is_string($v) || trim($v) === '')) {
            $e[] = "terms.$key must be a list of names.";
        }
    }
    foreach (['budget_ranges', 'send_without_approval'] as $key) {
        if (!is_array($t[$key] ?? null) || !array_is_list($t[$key])) {
            $e[] = "terms.$key must be a list (it can be empty).";
        }
    }
    foreach ((array) ($s['timing'] ?? []) as $key => $v) {
        if (!is_int($v) || $v < 0 || $v > 3650) {
            $e[] = "timing.$key must be a whole number of days.";
        }
    }
    foreach ((array) ($s['next_actions'] ?? []) as $key => $v) {
        if (!isset(CRM_STAGES[$key]) || !is_string($v)) {
            $e[] = "next_actions.$key is not a stage.";
        }
    }
    return $e;
}

/** Checklist template (templates/en/checklist.json): ['all' => [...], '<track>' => [...]]. */
function crm_checklist_template(): array
{
    $tpl = crm_read_json('templates/en/checklist.json');
    $errors = crm_checklist_errors($tpl, crm_settings()['tracks']);
    if ($errors) {
        throw new ApiError('admin/crm/' . CRM_BUSINESS . '/templates/en/checklist.json: ' . $errors[0], 500, ['errors' => $errors]);
    }
    return $tpl;
}

function crm_checklist_errors(array $tpl, array $tracks): array
{
    $e = [];
    foreach (['all', ...array_keys($tracks)] as $group) {
        $items = $tpl[$group] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            $e[] = "\"$group\" must be a list of items.";
            continue;
        }
        foreach ($items as $n => $item) {
            $where = "$group item " . ($n + 1);
            if (!is_array($item) || !is_string($item['label'] ?? null) || trim($item['label']) === '') {
                $e[] = "$where needs a label.";
            } elseif (!isset(CRM_CATEGORIES[$item['category'] ?? ''])) {
                $e[] = "$where: category must be one of " . implode(', ', array_keys(CRM_CATEGORIES)) . '.';
            } elseif (!is_bool($item['required'] ?? null)) {
                $e[] = "$where: required must be true or false.";
            }
        }
    }
    return $e;
}

/** Today's date where the business is (Beirut), as YYYY-MM-DD. */
function crm_today(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone(crm_settings()['business']['timezone'])))->format('Y-m-d');
}

/* --------------------------------------------------------------------------
   Store: data/crm/<collection>.json, one lock for all of them
   -------------------------------------------------------------------------- */

function crm_file(string $col): string
{
    return data_path("crm/$col.json");
}

/** Every collection. Throws if a file exists but can't be read, so a bad read is never saved over it. */
function crm_load(): array
{
    $db = [];
    foreach (CRM_COLLECTIONS as $col) {
        $file = crm_file($col);
        if (!is_file($file)) {
            $db[$col] = [];
            continue;
        }
        $raw = @file_get_contents($file);
        $list = $raw === false ? null : json_decode($raw, true);
        if (!is_array($list)) {
            throw new ApiError("crm/$col.json in the data folder could not be read. Nothing was changed; restore it from crm/$col.json.bak.", 500);
        }
        $db[$col] = array_values(array_filter($list, 'is_array'));
    }
    return $db;
}

/** Run $fn(array &$db) under the lock and write back only the collections it changed. */
function crm_tx(callable $fn): mixed
{
    return with_lock('crm', static function () use ($fn): mixed {
        $db = crm_load();
        $before = array_map('serialize', $db);
        $result = $fn($db);
        foreach (CRM_COLLECTIONS as $col) {
            if (serialize($db[$col]) === $before[$col]) {
                continue;
            }
            $file = crm_file($col);
            if (is_file($file)) {
                @copy($file, "$file.bak"); // previous version, in case anything goes wrong
            }
            write_atomic($file, encode_json(array_values($db[$col])));
        }
        return $result;
    });
}

/* --------------------------------------------------------------------------
   Small helpers
   -------------------------------------------------------------------------- */

function crm_id(): string
{
    return bin2hex(random_bytes(6));
}

function crm_text(mixed $v, int $max = 300): string
{
    return mb_substr(trim(is_scalar($v) ? (string) $v : ''), 0, $max);
}

function crm_date(mixed $v): string
{
    $v = crm_text($v, 10);
    if ($v === '') {
        return '';
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v) {
        throw new ApiError("\"$v\" isn't a date.");
    }
    return $v;
}

function crm_money(mixed $v): float
{
    if ($v === '' || $v === null) {
        return 0.0;
    }
    if (!is_numeric($v) || (float) $v < 0 || (float) $v > 10_000_000) {
        throw new ApiError('Amounts must be a number, 0 or more.');
    }
    return round((float) $v, 2);
}

function crm_link(mixed $v): string
{
    $v = crm_text($v, 500);
    if ($v !== '' && !preg_match('#^https?://[^\s<>"]+$#i', $v)) {
        throw new ApiError('Links must start with https://');
    }
    return $v;
}

/** Textarea → list of non-empty lines. */
function crm_lines(mixed $v, int $max = 60): array
{
    $lines = is_array($v) ? $v : preg_split('/\R/', is_scalar($v) ? (string) $v : '');
    $lines = array_map(static fn($l) => crm_text($l, 300), $lines);
    return array_slice(array_values(array_filter($lines, static fn($l) => $l !== '')), 0, $max);
}

function crm_pick(mixed $v, array $allowed, string $what): string
{
    $v = is_scalar($v) ? (string) $v : '';
    if (!in_array($v, $allowed, true)) {
        throw new ApiError("Pick a $what.");
    }
    return $v;
}

function crm_usd(float $n): string
{
    return '$' . number_format($n, fmod($n, 1.0) === 0.0 ? 0 : 2);
}

function crm_index(array $list, string $id): ?int
{
    foreach ($list as $i => $row) {
        if (($row['id'] ?? '') === $id) {
            return $i;
        }
    }
    return null;
}

function crm_project_at(array $db, string $id): int
{
    $i = crm_index($db['projects'], $id);
    if ($i === null) {
        throw new ApiError('That project no longer exists.', 404);
    }
    return $i;
}

function crm_stage_no(string $stage): int
{
    return (int) array_search($stage, array_keys(CRM_STAGES), true);
}

/** Add a line to the activity log (stage changes, overrides, records, payments, ...). */
function crm_log(array &$db, array $p, string $by, string $kind, string $text, string $reason = ''): void
{
    $db['events'][] = [
        'id' => crm_id(), 'business' => CRM_BUSINESS, 'client_id' => $p['client_id'], 'project_id' => $p['id'],
        'at' => gmdate('c'), 'by' => $by, 'kind' => $kind, 'text' => $text, 'reason' => $reason,
    ];
}

function crm_paid(array $db, string $pid): float
{
    $sum = 0.0;
    foreach ($db['payments'] as $pay) {
        if ($pay['project_id'] === $pid) {
            $sum += (float) $pay['amount'];
        }
    }
    return round($sum, 2);
}

/** The newest document of a type on a project, or null. */
function crm_doc(array $db, string $pid, string $type): ?array
{
    $found = null;
    foreach ($db['documents'] as $d) {
        if ($d['project_id'] === $pid && $d['type'] === $type) {
            $found = $d;
        }
    }
    return $found;
}

/* --------------------------------------------------------------------------
   Stage gates: what has to be true before a project leaves its stage
   -------------------------------------------------------------------------- */

/** Conditions for leaving the project's current stage: [{key, label, met, record, detail}]. */
function crm_gate(array $db, array $p): array
{
    $rec = static fn(string $k): bool => ($p['records'][$k]['date'] ?? '') !== '';
    $doc = static fn(string $type, string $status): bool => (crm_doc($db, $p['id'], $type)['status'] ?? '') === $status;
    $c = static fn(string $key, string $label, bool $met, string $record = '', string $detail = ''): array
        => ['key' => $key, 'label' => $label, 'met' => $met, 'record' => $record, 'detail' => $detail];
    $paid = crm_paid($db, $p['id']);
    $price = (float) ($p['price'] ?? 0);

    switch ($p['stage']) {
        case 'lead':
            return [$c('qualified', 'Marked as qualified', $rec('qualified'), 'qualified')];
        case 'intake':
            return [$c('intake', 'Intake questionnaire submitted', $doc('intake', 'completed'), 'intake')];
        case 'proposal':
            return [$c('proposal', 'Client accepted the proposal in writing', $doc('proposal', 'signed'), 'proposal')];
        case 'agreement':
            $deposit = $price > 0 ? round($price * crm_settings()['terms']['deposit_percent'] / 100, 2) : 0.0;
            return [
                $c('agreement', 'Signed agreement stored', $doc('agreement', 'signed'), 'agreement'),
                $c('deposit', 'Deposit recorded', $paid > 0, 'payment', $paid > 0 ? 'Paid ' . crm_usd($paid) : ($deposit > 0 ? 'Expected ' . crm_usd($deposit) : '')),
            ];
        case 'onboarding':
            $items = array_filter($db['checklist'], static fn($i) => $i['project_id'] === $p['id'] && $i['required']);
            $done = count(array_filter($items, static fn($i) => $i['status'] !== 'waiting'));
            $detail = $items ? "$done of " . count($items) . ' received' : 'No checklist yet';
            return [$c('checklist', 'All required checklist items received', $items && $done === count($items), '', $detail)];
        case 'kickoff':
            return [
                $c('kickoff', 'Kickoff done', $rec('kickoff'), 'kickoff'),
                $c('shoot', 'First shoot date set', !empty($p['shoot_dates']), 'shoot'),
            ];
        case 'in_production':
            return [$c('draft', 'First draft delivered', $rec('draft'), 'draft')];
        case 'review':
            return [$c('approved', 'Client approved in writing', $rec('approved'), 'approved')];
        case 'delivered':
            $detail = $price > 0 ? 'Paid ' . crm_usd($paid) . ' of ' . crm_usd($price) : 'Set the project price first';
            return [$c('paid', 'Final payment recorded', $price > 0 && $paid >= $price, 'payment', $detail)];
        case 'offboarding':
            return [$c('testimonial', 'Testimonial requested', $rec('testimonial'), 'testimonial')];
        case 'follow_up':
            $noted = ($p['records']['checkin']['note'] ?? '') !== '';
            return [$c('checkin', 'Check-in sent and outcome noted', $rec('checkin') && $noted, 'checkin', $rec('checkin') && !$noted ? 'Add the outcome' : '')];
    }
    return [];
}

function crm_unmet(array $db, array $p): array
{
    return array_column(array_filter(crm_gate($db, $p), static fn($c) => !$c['met']), 'label');
}

/* --------------------------------------------------------------------------
   Clients and projects
   -------------------------------------------------------------------------- */

/** Clean client fields from the form; $base is the existing client (or defaults). */
function crm_client_fields(array $in, array $base): array
{
    $s = crm_settings();
    $out = $base;
    foreach (['business_name' => 120, 'contact_name' => 120, 'role' => 80, 'instagram' => 80, 'tiktok' => 80, 'website' => 200, 'referred_by' => 120] as $key => $max) {
        if (array_key_exists($key, $in)) {
            $out[$key] = crm_text($in[$key], $max);
        }
    }
    if (array_key_exists('notes', $in)) {
        $out['notes'] = crm_text($in['notes'], 5000);
    }
    if (array_key_exists('phone', $in)) {
        $phone = crm_text($in['phone'], 40);
        if (!preg_match('/^[+0-9 ().\-]*$/', $phone)) {
            throw new ApiError('The phone number can only have digits, spaces and a leading +.');
        }
        $out['phone'] = $phone;
    }
    if (array_key_exists('email', $in)) {
        $email = crm_text($in['email'], 200);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ApiError('That email address doesn’t look right.');
        }
        $out['email'] = $email;
    }
    if (array_key_exists('channel', $in)) {
        $out['channel'] = crm_pick($in['channel'], array_keys(CRM_CHANNELS), 'preferred channel');
    }
    if (array_key_exists('source', $in)) {
        $out['source'] = crm_pick($in['source'], array_keys($s['sources']), 'source');
    }
    if ($out['business_name'] === '' && $out['contact_name'] === '') {
        throw new ApiError('Add the business name or the contact person.');
    }
    return $out;
}

function crm_new_project(array $client, string $track, string $title, string $by): array
{
    $s = crm_settings();
    $now = gmdate('c');
    return [
        'id' => crm_id(), 'business' => CRM_BUSINESS, 'client_id' => $client['id'],
        'title' => $title !== '' ? $title : $s['tracks'][$track]['label'] . ' · ' . ($client['business_name'] ?: $client['contact_name']),
        'track' => $track, 'stage' => 'lead', 'stage_entered_at' => $now, 'status' => 'open', 'closed_at' => '',
        'scope' => '', 'deliverables' => [], 'exclusions' => [], 'start_date' => '', 'end_date' => '',
        'price' => 0, 'currency' => $s['business']['currency'], 'payment_schedule' => $s['terms']['payment_schedule'][$track] ?? '',
        'revision_rounds' => $s['terms']['revision_rounds'],
        'next_action' => $s['next_actions']['lead'] ?? '', 'next_action_due' => '', 'blocked_by' => '',
        'shoot_dates' => [], 'records' => [], 'approver' => '', 'kickoff_date' => '', 'draft_date' => '', 'delivery_date' => '',
        'delivery_link' => '', 'intake' => [],
        'created_at' => $now, 'created_by' => $by, 'updated_at' => $now,
    ];
}

/** New client with a first project at the Lead stage. Returns the client id. */
function crm_client_create(array $in, string $by): string
{
    $s = crm_settings();
    $track = crm_pick($in['track'] ?? '', array_keys($s['tracks']), 'track');
    $title = crm_text($in['title'] ?? '', 160);
    $leadId = crm_text($in['lead_id'] ?? '', 20);
    if ($leadId !== '' && !in_array($leadId, array_column(leads_all(), 'id'), true)) {
        throw new ApiError('That website enquiry no longer exists.', 404);
    }
    $now = gmdate('c');
    $client = crm_client_fields($in + ['channel' => 'whatsapp', 'source' => array_key_first($s['sources'])], [
        'id' => crm_id(), 'business' => CRM_BUSINESS, 'business_name' => '', 'contact_name' => '', 'role' => '',
        'phone' => '', 'email' => '', 'instagram' => '', 'tiktok' => '', 'website' => '', 'channel' => 'whatsapp', 'source' => '',
        'referred_by' => '', 'notes' => '', 'lead_id' => $leadId, 'created_at' => $now, 'created_by' => $by, 'updated_at' => $now,
    ]);
    return crm_tx(static function (array &$db) use ($client, $track, $title, $by, $leadId): string {
        if ($leadId !== '') {
            foreach ($db['clients'] as $c) {
                if (($c['lead_id'] ?? '') === $leadId) {
                    throw new ApiError('This enquiry is already in the pipeline.', 409, ['client_id' => $c['id']]);
                }
            }
        }
        $project = crm_new_project($client, $track, $title, $by);
        $db['clients'][] = $client;
        $db['projects'][] = $project;
        crm_log($db, $project, $by, 'created', 'Client added' . ($leadId !== '' ? ' from a website enquiry' : '') . ': ' . $project['title']);
        return $client['id'];
    });
}

function crm_client_update(string $id, array $in, string $by): string
{
    return crm_tx(static function (array &$db) use ($id, $in): string {
        $i = crm_index($db['clients'], $id);
        if ($i === null) {
            throw new ApiError('That client no longer exists.', 404);
        }
        $db['clients'][$i] = crm_client_fields($in, $db['clients'][$i]);
        $db['clients'][$i]['updated_at'] = gmdate('c');
        return $id;
    });
}

/** Delete a client and everything recorded about them. */
function crm_client_delete(string $id): void
{
    crm_tx(static function (array &$db) use ($id): void {
        if (crm_index($db['clients'], $id) === null) {
            throw new ApiError('That client no longer exists.', 404);
        }
        foreach ($db['documents'] as $d) { // their PDFs and document snapshots
            if (($d['client_id'] ?? '') === $id) {
                foreach (glob(data_path('crm/files/') . $d['id'] . '-v*.{pdf,json}', GLOB_BRACE) ?: [] as $f) {
                    @unlink($f);
                }
            }
        }
        foreach ($db['projects'] as $p) { // their deliverables and the files they sent
            if ($p['client_id'] === $id) {
                foreach (['media', 'uploads'] as $kind) {
                    $dir = data_path("crm/$kind/{$p['id']}/");
                    foreach (glob($dir . '*') ?: [] as $f) {
                        @unlink($f);
                    }
                    @rmdir($dir);
                }
            }
        }
        $db['clients'] = array_values(array_filter($db['clients'], static fn($c) => $c['id'] !== $id));
        foreach (array_diff(CRM_COLLECTIONS, ['clients']) as $col) {
            $db[$col] = array_values(array_filter($db[$col], static fn($r) => ($r['client_id'] ?? '') !== $id));
        }
    });
}

/** Another project for an existing client (for example a returning client). Returns the new project id. */
function crm_project_create(string $clientId, array $in, string $by): string
{
    $s = crm_settings();
    $track = crm_pick($in['track'] ?? '', array_keys($s['tracks']), 'track');
    $title = crm_text($in['title'] ?? '', 160);
    return crm_tx(static function (array &$db) use ($clientId, $track, $title, $by): string {
        $i = crm_index($db['clients'], $clientId);
        if ($i === null) {
            throw new ApiError('That client no longer exists.', 404);
        }
        $project = crm_new_project($db['clients'][$i], $track, $title, $by);
        $db['projects'][] = $project;
        crm_log($db, $project, $by, 'created', 'New project: ' . $project['title']);
        return $project['id'];
    });
}

/** Edit project details, next action and "blocked by". Returns the client id. */
function crm_project_update(string $pid, array $in, string $by): string
{
    $s = crm_settings();
    return crm_tx(static function (array &$db) use ($pid, $in, $by, $s): string {
        $i = crm_project_at($db, $pid);
        $p = $db['projects'][$i];
        $old = $p;
        foreach (['title' => 160, 'payment_schedule' => 300, 'next_action' => 300, 'approver' => 120] as $key => $max) {
            if (array_key_exists($key, $in)) {
                $p[$key] = crm_text($in[$key], $max);
            }
        }
        if (array_key_exists('scope', $in)) {
            $p['scope'] = crm_text($in['scope'], 3000);
        }
        foreach (['deliverables', 'exclusions'] as $key) {
            if (array_key_exists($key, $in)) {
                $p[$key] = crm_lines($in[$key]);
            }
        }
        foreach (['start_date', 'end_date', 'next_action_due', 'kickoff_date', 'draft_date', 'delivery_date'] as $key) {
            if (array_key_exists($key, $in)) {
                $p[$key] = crm_date($in[$key]);
            }
        }
        if (array_key_exists('price', $in)) {
            $p['price'] = crm_money($in['price']);
        }
        if (array_key_exists('delivery_link', $in)) {
            $p['delivery_link'] = crm_link($in['delivery_link']);
        }
        if (array_key_exists('revision_rounds', $in)) {
            $n = filter_var($in['revision_rounds'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 20]]);
            if ($n === false) {
                throw new ApiError('Revision rounds must be a whole number from 0 to 20.');
            }
            $p['revision_rounds'] = $n;
        }
        if (array_key_exists('track', $in)) {
            $p['track'] = crm_pick($in['track'], array_keys($s['tracks']), 'track');
        }
        if (array_key_exists('blocked_by', $in)) {
            $p['blocked_by'] = crm_pick($in['blocked_by'], CRM_BLOCKERS, 'blocker');
        }
        if (array_key_exists('downloads', $in)) {
            $p['downloads'] = crm_pick($in['downloads'], ['', 'open', 'closed'], 'download setting');
        }
        if ($p['title'] === '') {
            throw new ApiError('The project needs a title.');
        }
        if ($p === $old) {
            return $p['client_id'];
        }
        $p['updated_at'] = gmdate('c');
        $db['projects'][$i] = $p;
        if ($p['track'] !== $old['track']) {
            crm_log($db, $p, $by, 'edit', 'Track changed from ' . ($s['tracks'][$old['track']]['label'] ?? $old['track']) . ' to ' . $s['tracks'][$p['track']]['label']);
        }
        if ($p['blocked_by'] !== $old['blocked_by']) {
            crm_log($db, $p, $by, 'edit', ['' => 'No longer blocked', 'client' => 'Marked as waiting on the client', 'studiio' => 'Marked as waiting on Studiio'][$p['blocked_by']]);
        }
        return $p['client_id'];
    });
}

/**
 * Move a project to another stage. Forward one stage needs that stage's exit conditions,
 * unless a reason is given (an override, logged with the reason). Skipping stages always
 * needs a reason. Moving back is allowed and logged.
 */
function crm_move(string $pid, string $to, string $reason, string $by): string
{
    if (!isset(CRM_STAGES[$to])) {
        throw new ApiError('Unknown stage.');
    }
    $reason = crm_text($reason, 500);
    return crm_tx(static function (array &$db) use ($pid, $to, $reason, $by): string {
        $i = crm_project_at($db, $pid);
        $p = $db['projects'][$i];
        if ($p['status'] !== 'open') {
            throw new ApiError('This project is closed. Reopen it first.');
        }
        $from = crm_stage_no($p['stage']);
        $dest = crm_stage_no($to);
        if ($from === $dest) {
            throw new ApiError('The project is already in that stage.');
        }
        $unmet = [];
        if ($dest > $from) {
            $unmet = crm_unmet($db, $p);
            if ($dest > $from + 1) {
                $skipped = array_slice(array_values(CRM_STAGES), $from + 1, $dest - $from - 1);
                $unmet[] = 'Skips ' . implode(', ', $skipped);
            }
            if ($unmet && $reason === '') {
                throw new ApiError('Not ready for ' . CRM_STAGES[$to] . ': ' . implode('; ', $unmet) . '.', 409, ['needs_override' => true, 'unmet' => $unmet]);
            }
        }
        $text = ($dest < $from ? 'Moved back from ' : 'Moved from ') . CRM_STAGES[$p['stage']] . ' to ' . CRM_STAGES[$to];
        $p['stage'] = $to;
        $p['stage_entered_at'] = gmdate('c');
        $p['next_action'] = crm_settings()['next_actions'][$to] ?? '';
        $p['next_action_due'] = '';
        $p['blocked_by'] = '';
        $p['updated_at'] = gmdate('c');
        $db['projects'][$i] = $p;
        if ($unmet) {
            crm_log($db, $p, $by, 'override', "$text (override; not done: " . implode('; ', $unmet) . ')', $reason);
        } else {
            crm_log($db, $p, $by, 'stage', $text, $reason);
        }
        if ($to === 'onboarding' && crm_checklist_generate($db, $p) > 0) {
            crm_log($db, $p, $by, 'checklist', 'Checklist created for the ' . crm_settings()['tracks'][$p['track']]['label'] . ' track');
        }
        return $p['client_id'];
    });
}

/** Close a project as lost (any time) or done (from Follow-up, once the check-in is noted). */
function crm_close(string $pid, string $outcome, string $reason, string $by): string
{
    $outcome = crm_pick($outcome, ['lost', 'done'], 'outcome');
    $reason = crm_text($reason, 500);
    return crm_tx(static function (array &$db) use ($pid, $outcome, $reason, $by): string {
        $i = crm_project_at($db, $pid);
        $p = $db['projects'][$i];
        if ($p['status'] !== 'open') {
            throw new ApiError('This project is already closed.');
        }
        $override = [];
        if ($outcome === 'done') {
            $override = $p['stage'] === 'follow_up' ? crm_unmet($db, $p) : ['Project is still in ' . CRM_STAGES[$p['stage']]];
            if ($override && $reason === '') {
                throw new ApiError('Not ready to close: ' . implode('; ', $override) . '.', 409, ['needs_override' => true, 'unmet' => $override]);
            }
        }
        $p['status'] = $outcome;
        $p['closed_at'] = gmdate('c');
        $p['updated_at'] = gmdate('c');
        $db['projects'][$i] = $p;
        $text = $outcome === 'lost' ? 'Marked as lost' : 'Project closed as done';
        crm_log($db, $p, $by, $override ? 'override' : 'close', $override ? "$text (override; not done: " . implode('; ', $override) . ')' : $text, $reason);
        return $p['client_id'];
    });
}

function crm_reopen(string $pid, string $by): string
{
    return crm_tx(static function (array &$db) use ($pid, $by): string {
        $i = crm_project_at($db, $pid);
        $p = $db['projects'][$i];
        if ($p['status'] === 'open') {
            throw new ApiError('This project is already open.');
        }
        $p['status'] = 'open';
        $p['closed_at'] = '';
        $p['updated_at'] = gmdate('c');
        $db['projects'][$i] = $p;
        crm_log($db, $p, $by, 'reopen', 'Reopened in ' . CRM_STAGES[$p['stage']]);
        return $p['client_id'];
    });
}

/** Record (or clear) one of the steps in CRM_RECORDS. */
function crm_record(string $pid, string $what, array $in, string $by): string
{
    $def = CRM_RECORDS[$what] ?? throw new ApiError('Unknown step.');
    $clear = !empty($in['clear']);
    $date = $clear ? '' : (crm_date($in['date'] ?? '') ?: crm_today());
    $note = crm_text($in['note'] ?? '', 1000);
    $link = crm_link($in['link'] ?? '');
    return crm_tx(static function (array &$db) use ($pid, $what, $def, $clear, $date, $note, $link, $by): string {
        $i = crm_project_at($db, $pid);
        $p = $db['projects'][$i];
        $records = is_array($p['records'] ?? null) ? $p['records'] : [];
        if ($clear) {
            unset($records[$what]);
        } else {
            $records[$what] = ['date' => $date, 'note' => $note, 'link' => $link, 'by' => $by, 'at' => gmdate('c')];
        }
        $p['records'] = $records;
        $p['updated_at'] = gmdate('c');
        $db['projects'][$i] = $p;
        if (isset($def['doc'])) {
            $d = null;
            foreach ($db['documents'] as $n => $doc) {
                if ($doc['project_id'] === $pid && $doc['type'] === $def['doc']) {
                    $d = $n;
                }
            }
            if ($clear) {
                if ($d !== null) {
                    $db['documents'][$d]['status'] = 'sent';
                    $db['documents'][$d]['completed_at'] = '';
                }
            } elseif ($d === null) {
                $db['documents'][] = [
                    'id' => crm_id(), 'business' => CRM_BUSINESS, 'client_id' => $p['client_id'], 'project_id' => $pid,
                    'type' => $def['doc'], 'file' => '', 'link' => $link, 'version' => 1, 'status' => $def['status'],
                    'sent_at' => '', 'completed_at' => $date, 'note' => $note, 'created_at' => gmdate('c'),
                ];
            } else {
                $db['documents'][$d]['status'] = $def['status'];
                $db['documents'][$d]['completed_at'] = $date;
                $db['documents'][$d]['note'] = $note;
                if ($link !== '') {
                    $db['documents'][$d]['link'] = $link;
                }
            }
        }
        crm_log($db, $p, $by, 'record', ($clear ? 'Undone: ' : '') . $def['label'] . (!$clear && $note !== '' ? " ($note)" : ''));
        if ($what === 'approved' && !$clear) {
            crm_invoice_auto_final($db, $p, $by);
        }
        return $p['client_id'];
    });
}

/** Book or remove a shoot date. Booking before Kickoff needs a reason (logged override). */
function crm_shoot(string $pid, string $op, mixed $date, string $reason, string $by): string
{
    $op = crm_pick($op, ['add', 'remove'], 'action');
    $date = crm_date($date);
    if ($date === '') {
        throw new ApiError('Pick the shoot date.');
    }
    $reason = crm_text($reason, 500);
    return crm_tx(static function (array &$db) use ($pid, $op, $date, $reason, $by): string {
        $i = crm_project_at($db, $pid);
        $p = $db['projects'][$i];
        $dates = array_values(array_filter((array) ($p['shoot_dates'] ?? []), 'is_string'));
        if ($op === 'remove') {
            $dates = array_values(array_diff($dates, [$date]));
            crm_log($db, $p, $by, 'shoot', "Shoot on $date removed");
        } else {
            if (in_array($date, $dates, true)) {
                throw new ApiError('That shoot date is already booked.');
            }
            $early = crm_stage_no($p['stage']) < crm_stage_no('kickoff');
            if ($early && $reason === '') {
                throw new ApiError('Shoots are booked from the Kickoff stage on; this project is in ' . CRM_STAGES[$p['stage']] . '.', 409, ['needs_override' => true, 'unmet' => ['Project is before Kickoff']]);
            }
            $dates[] = $date;
            sort($dates);
            crm_log($db, $p, $by, $early ? 'override' : 'shoot', "Shoot booked for $date" . ($early ? ' (override; project is in ' . CRM_STAGES[$p['stage']] . ')' : ''), $reason);
        }
        $p['shoot_dates'] = $dates;
        $p['updated_at'] = gmdate('c');
        $db['projects'][$i] = $p;
        return $p['client_id'];
    });
}

/* --------------------------------------------------------------------------
   Checklist, payments, messages
   -------------------------------------------------------------------------- */

/** Create the project's checklist from the track template, unless it has one. Returns how many items were added. */
function crm_checklist_generate(array &$db, array $p): int
{
    foreach ($db['checklist'] as $item) {
        if ($item['project_id'] === $p['id']) {
            return 0;
        }
    }
    $tpl = crm_checklist_template();
    $n = 0;
    foreach ([...$tpl['all'], ...($tpl[$p['track']] ?? [])] as $item) {
        $row = crm_checklist_row($p, $item['label'], $item['category'], $item['required']);
        if (is_string($item['from_intake'] ?? null)) {
            $row['from_intake'] = $item['from_intake'];
        }
        $db['checklist'][] = $row;
        $n++;
    }
    crm_intake_fill_checklist($db, $p);
    return $n;
}

function crm_checklist_row(array $p, string $label, string $category, bool $required): array
{
    return [
        'id' => crm_id(), 'business' => CRM_BUSINESS, 'client_id' => $p['client_id'], 'project_id' => $p['id'],
        'label' => $label, 'category' => $category, 'required' => $required, 'status' => 'waiting',
        'due_date' => '', 'received_at' => '', 'note' => '', 'created_at' => gmdate('c'),
    ];
}

/** Checklist changes: op = generate | add | update | delete | due. Returns the client id. */
function crm_checklist(string $pid, array $in, string $by): string
{
    $op = crm_pick($in['op'] ?? '', ['generate', 'add', 'update', 'delete', 'due'], 'action');
    return crm_tx(static function (array &$db) use ($pid, $in, $op, $by): string {
        $p = $db['projects'][crm_project_at($db, $pid)];
        $find = static function () use (&$db, $in, $pid): int {
            $n = crm_index($db['checklist'], crm_text($in['item_id'] ?? '', 20));
            if ($n === null || $db['checklist'][$n]['project_id'] !== $pid) {
                throw new ApiError('That checklist item no longer exists.', 404);
            }
            return $n;
        };
        switch ($op) {
            case 'generate':
                if (crm_checklist_generate($db, $p) === 0) {
                    throw new ApiError('This project already has a checklist.');
                }
                crm_log($db, $p, $by, 'checklist', 'Checklist created for the ' . crm_settings()['tracks'][$p['track']]['label'] . ' track');
                break;
            case 'add':
                $label = crm_text($in['label'] ?? '', 300);
                if ($label === '') {
                    throw new ApiError('Write what the client needs to send.');
                }
                $row = crm_checklist_row($p, $label, crm_pick($in['category'] ?? '', array_keys(CRM_CATEGORIES), 'category'), !empty($in['required']));
                $db['checklist'][] = $row;
                break;
            case 'update':
                $n = $find();
                $item = $db['checklist'][$n];
                if (array_key_exists('status', $in)) {
                    $item['status'] = crm_pick($in['status'], CRM_ITEM_STATUSES, 'status');
                    $item['received_at'] = $item['status'] === 'received' ? crm_today() : '';
                }
                if (array_key_exists('note', $in)) {
                    $item['note'] = crm_text($in['note'], 1000);
                }
                if (array_key_exists('due_date', $in)) {
                    $item['due_date'] = crm_date($in['due_date']);
                }
                if (array_key_exists('required', $in)) {
                    $item['required'] = (bool) $in['required'];
                }
                $db['checklist'][$n] = $item;
                break;
            case 'delete':
                array_splice($db['checklist'], $find(), 1);
                break;
            case 'due':
                $due = crm_date($in['due_date'] ?? '');
                foreach ($db['checklist'] as $n => $item) {
                    if ($item['project_id'] === $pid && $item['status'] === 'waiting') {
                        $db['checklist'][$n]['due_date'] = $due;
                    }
                }
                break;
        }
        return $p['client_id'];
    });
}

function crm_payment_add(string $pid, array $in, string $by): string
{
    $s = crm_settings();
    $amount = crm_money($in['amount'] ?? '');
    if ($amount <= 0) {
        throw new ApiError('Enter the amount paid.');
    }
    $date = crm_date($in['date'] ?? '') ?: crm_today();
    $method = crm_pick($in['method'] ?? '', [...$s['terms']['payment_methods'], 'Other'], 'payment method');
    $invoiceId = crm_text($in['invoice_id'] ?? '', 20);
    $kind = $invoiceId !== '' ? 'other' : crm_pick($in['kind'] ?? '', array_keys(CRM_PAYMENT_KINDS), 'payment type');
    $note = crm_text($in['note'] ?? '', 300);
    return crm_tx(static function (array &$db) use ($pid, $amount, $date, $method, $kind, $note, $by, $s, $invoiceId): string {
        $p = $db['projects'][crm_project_at($db, $pid)];
        $invNo = '';
        if ($invoiceId !== '') {
            $k = crm_index($db['documents'], $invoiceId);
            if ($k === null || $db['documents'][$k]['type'] !== 'invoice' || $db['documents'][$k]['project_id'] !== $pid) {
                throw new ApiError('That invoice no longer exists.', 404);
            }
            $kind = $db['documents'][$k]['kind'] ?? 'other';
            $invNo = ' for ' . $db['documents'][$k]['invoice_no'];
        }
        $db['payments'][] = [
            'id' => crm_id(), 'business' => CRM_BUSINESS, 'client_id' => $p['client_id'], 'project_id' => $pid,
            'amount' => $amount, 'currency' => $s['business']['currency'], 'date' => $date, 'method' => $method,
            'kind' => $kind, 'invoice_id' => $invoiceId, 'note' => $note, 'created_at' => gmdate('c'), 'created_by' => $by,
        ];
        crm_log($db, $p, $by, 'payment', 'Payment recorded: ' . crm_usd($amount) . $invNo . ' (' . strtolower(CRM_PAYMENT_KINDS[$kind] ?? $kind) . ", $method, $date)");
        if ($invoiceId !== '') {
            crm_invoice_sync($db, $invoiceId);
        }
        return $p['client_id'];
    });
}

function crm_payment_delete(string $id, string $by): string
{
    return crm_tx(static function (array &$db) use ($id, $by): string {
        $n = crm_index($db['payments'], $id);
        if ($n === null) {
            throw new ApiError('That payment no longer exists.', 404);
        }
        $pay = $db['payments'][$n];
        array_splice($db['payments'], $n, 1);
        if (($pay['invoice_id'] ?? '') !== '') {
            crm_invoice_sync($db, $pay['invoice_id']);
        }
        $i = crm_index($db['projects'], $pay['project_id']);
        if ($i !== null) {
            crm_log($db, $db['projects'][$i], $by, 'payment', 'Payment removed: ' . crm_usd((float) $pay['amount']) . " ({$pay['date']})");
        }
        return $pay['client_id'];
    });
}

/** Log a message. With a template (messages.json) and a document, the document is marked as sent. */
function crm_message_add(string $pid, array $in, string $by): string
{
    $direction = crm_pick($in['direction'] ?? '', ['out', 'in'], 'direction');
    $channel = crm_pick($in['channel'] ?? '', array_keys(CRM_MESSAGE_CHANNELS), 'channel');
    $summary = mb_substr(trim(str_replace("\r\n", "\n", is_string($in['summary'] ?? null) ? $in['summary'] : '')), 0, 2000);
    if ($summary === '') {
        throw new ApiError('Write a short summary of the message.');
    }
    $date = crm_date($in['date'] ?? '') ?: crm_today();
    $template = crm_text($in['template'] ?? '', 40);
    if ($template !== '' && !array_key_exists($template, crm_message_templates())) {
        throw new ApiError('Unknown message template.');
    }
    $docId = crm_text($in['doc_id'] ?? '', 20);
    return crm_tx(static function (array &$db) use ($pid, $direction, $channel, $summary, $date, $by, $template, $docId): string {
        $p = $db['projects'][crm_project_at($db, $pid)];
        $db['messages'][] = [
            'id' => crm_id(), 'business' => CRM_BUSINESS, 'client_id' => $p['client_id'], 'project_id' => $pid,
            'direction' => $direction, 'channel' => $channel, 'template' => $template, 'doc_id' => $docId, 'date' => $date,
            'summary' => $summary, 'created_at' => gmdate('c'), 'created_by' => $by,
        ];
        if ($docId !== '') {
            $k = crm_index($db['documents'], $docId);
            if ($k === null || $db['documents'][$k]['project_id'] !== $pid) {
                throw new ApiError('That document no longer exists.', 404);
            }
            if ($db['documents'][$k]['status'] === 'draft') {
                $db['documents'][$k]['status'] = 'sent';
                $db['documents'][$k]['sent_at'] = gmdate('c');
            }
            crm_log($db, $p, $by, 'document', crm_doc_title($db['documents'][$k]['type']) . ' sent (' . CRM_MESSAGE_CHANNELS[$channel] . ')');
            if ($db['documents'][$k]['type'] === 'agreement') {
                crm_invoice_auto_agreement($db, $p, $by);
            }
        }
        return $p['client_id'];
    });
}

function crm_message_delete(string $id): string
{
    return crm_tx(static function (array &$db) use ($id): string {
        $n = crm_index($db['messages'], $id);
        if ($n === null) {
            throw new ApiError('That message no longer exists.', 404);
        }
        $clientId = $db['messages'][$n]['client_id'];
        array_splice($db['messages'], $n, 1);
        return $clientId;
    });
}

/* --------------------------------------------------------------------------
   What the admin pages get
   -------------------------------------------------------------------------- */

/** The settings the admin pages need (labels, options, a few defaults). */
function crm_public_settings(): array
{
    $s = crm_settings();
    return [
        'business' => $s['business']['name'],
        'currency' => $s['business']['currency'],
        'today' => crm_today(),
        'stages' => array_map(static fn($k, $v) => ['key' => $k, 'label' => $v], array_keys(CRM_STAGES), CRM_STAGES),
        'tracks' => $s['tracks'],
        'sources' => $s['sources'],
        'channels' => CRM_CHANNELS,
        'depositPercent' => $s['terms']['deposit_percent'],
        'paymentMethods' => [...$s['terms']['payment_methods'], 'Other'],
        'paymentKinds' => CRM_PAYMENT_KINDS,
        'messageChannels' => CRM_MESSAGE_CHANNELS,
        'categories' => CRM_CATEGORIES,
        'docTypes' => CRM_DOC_TYPES,
        'records' => array_map(static fn($r) => $r['label'], CRM_RECORDS),
        'makeTypes' => array_combine(CRM_MAKE_TYPES, array_map('crm_doc_title', CRM_MAKE_TYPES)),
        'messageTemplates' => crm_message_templates(),
        'unconfirmed' => array_values((array) ($s['unconfirmed'] ?? [])),
    ];
}

/** Pipeline board: every project as a card, plus a short list of clients. */
function crm_board(): array
{
    $db = crm_load();
    $clients = array_column($db['clients'], null, 'id');
    $cards = [];
    foreach ($db['projects'] as $p) {
        $c = $clients[$p['client_id']] ?? ['business_name' => '?', 'contact_name' => ''];
        $gate = crm_gate($db, $p);
        $cards[] = [
            'id' => $p['id'], 'client_id' => $p['client_id'], 'client' => $c['business_name'] ?: $c['contact_name'],
            'contact' => $c['contact_name'], 'title' => $p['title'], 'track' => $p['track'], 'stage' => $p['stage'],
            'stage_entered_at' => $p['stage_entered_at'], 'status' => $p['status'], 'closed_at' => $p['closed_at'],
            'next_action' => $p['next_action'], 'next_action_due' => $p['next_action_due'], 'blocked_by' => $p['blocked_by'],
            'ready' => $p['status'] === 'open' && $gate && !in_array(false, array_column($gate, 'met'), true),
            'updated_at' => $p['updated_at'],
        ];
    }
    usort($cards, static fn($a, $b) => strcmp($a['stage_entered_at'], $b['stage_entered_at']));
    $list = array_map(static fn($c) => [
        'id' => $c['id'], 'business_name' => $c['business_name'], 'contact_name' => $c['contact_name'], 'phone' => $c['phone'],
        'email' => $c['email'], 'channel' => $c['channel'], 'source' => $c['source'], 'created_at' => $c['created_at'],
    ], $db['clients']);
    return ['projects' => $cards, 'clients' => $list, 'settings' => crm_public_settings()];
}

/** Everything about one client, for the client page. */
function crm_client_payload(string $id): array
{
    $db = crm_load();
    $i = crm_index($db['clients'], $id);
    if ($i === null) {
        throw new ApiError('That client no longer exists.', 404);
    }
    $client = $db['clients'][$i];
    $mine = static fn(string $col) => array_values(array_filter($db[$col], static fn($r) => ($r['client_id'] ?? '') === $id));
    $projects = array_map(static fn($p) => $p + ['gate' => crm_gate($db, $p), 'paid' => crm_paid($db, $p['id']), 'intake_view' => crm_intake_view($p)], $mine('projects'));
    usort($projects, static fn($a, $b) => [$a['status'] !== 'open', $b['created_at']] <=> [$b['status'] !== 'open', $a['created_at']]);
    // Newest first. Rows are stored oldest first, so reverse before the (stable) sort keeps
    // the right order for things logged within the same second.
    $events = array_reverse($mine('events'));
    usort($events, static fn($a, $b) => strcmp($b['at'], $a['at']));
    $messages = array_reverse($mine('messages'));
    usort($messages, static fn($a, $b) => strcmp($b['date'] . $b['created_at'], $a['date'] . $a['created_at']));
    $payments = $mine('payments');
    usort($payments, static fn($a, $b) => strcmp($a['date'], $b['date']));
    $lead = null;
    if (($client['lead_id'] ?? '') !== '') {
        try {
            foreach (leads_all() as $l) {
                if ($l['id'] === $client['lead_id']) {
                    $lead = array_intersect_key($l, array_flip(['id', 'created_at', 'service_label', 'message']));
                }
            }
        } catch (ApiError $e) {
            // leads.json unreadable: the client page still works
        }
    }
    return [
        'client' => $client, 'lead' => $lead, 'projects' => $projects, 'checklist' => $mine('checklist'),
        'documents' => array_map(static fn($d) => ['link' => in_array($d['type'], ['intake', 'portal'], true) || ($d['file'] ?? '') !== '' ? crm_doc_link($d) : '']
            + ($d['type'] === 'invoice' ? ['paid_amount' => crm_invoice_paid($db, $d['id'])] : []) + $d, $mine('documents')), 'payments' => $payments, 'messages' => $messages, 'events' => $events,
        'cycles' => $mine('cycles'), 'media' => $mine('media'), 'uploads' => $mine('uploads'), 'storage' => crm_storage($db),
        'downloads' => array_combine(array_column($projects, 'id'), array_map(static fn($p) => crm_portal_downloads($db, $p), $projects)),
        'settings' => crm_public_settings(),
    ];
}

/** Numbers for the overview page and the menu badge. */
function crm_overview(): array
{
    $db = crm_load();
    $today = crm_today();
    $open = array_filter($db['projects'], static fn($p) => $p['status'] === 'open');
    return [
        'active' => count($open),
        'waitingOnClient' => count(array_filter($open, static fn($p) => $p['blocked_by'] === 'client')),
        'overdue' => count(array_filter($open, static fn($p) => $p['next_action_due'] !== '' && $p['next_action_due'] < $today)),
        'clients' => count($db['clients']),
        'outstanding' => array_sum(array_map(static fn($d) => $d['type'] === 'invoice' && $d['status'] !== 'void'
            ? max(0, (float) $d['amount'] - crm_invoice_paid($db, $d['id'])) : 0, $db['documents'])),
    ];
}

/** lead id => client id, for the Leads page ("Open in pipeline"). */
function crm_lead_links(): array
{
    try {
        $clients = crm_load()['clients'];
    } catch (ApiError $e) {
        return []; // the Leads page still works if the client files can't be read
    }
    $links = [];
    foreach ($clients as $c) {
        if (($c['lead_id'] ?? '') !== '') {
            $links[$c['lead_id']] = $c['id'];
        }
    }
    return $links;
}

/** Intake answer as text (used for the checklist and the documents). */
function crm_answer(array $p, string $id): string
{
    $a = $p['intake']['answers'][$id] ?? '';
    return is_array($a) ? implode(', ', $a) : (string) $a;
}

/** Tick checklist items that come from an intake answer (from_intake in the checklist template). */
function crm_intake_fill_checklist(array &$db, array $p): void
{
    // Items made before the template said which answer fills them are matched by their wording.
    $byLabel = [];
    try {
        foreach (crm_checklist_template() as $group => $items) {
            foreach (is_array($items) && array_is_list($items) ? $items : [] as $tplItem) {
                if (is_string($tplItem['from_intake'] ?? null)) {
                    $byLabel[$tplItem['label']] = $tplItem['from_intake'];
                }
            }
        }
    } catch (ApiError $e) {
        // template unreadable: only items that carry from_intake themselves are filled
    }
    foreach ($db['checklist'] as $n => $item) {
        $from = (string) ($item['from_intake'] ?? $byLabel[$item['label']] ?? '');
        if ($item['project_id'] !== $p['id'] || $from === '' || $item['status'] !== 'waiting') {
            continue;
        }
        $answer = crm_answer($p, $from);
        if ($answer !== '') {
            $db['checklist'][$n]['status'] = 'received';
            $db['checklist'][$n]['received_at'] = crm_today();
            $db['checklist'][$n]['note'] = mb_substr('From the intake: ' . $answer, 0, 1000);
        }
    }
}

// Documents, client links and the intake questionnaire (phase 2), invoices and payments (phase 3).
require_once __DIR__ . '/crm-docs.php';
require_once __DIR__ . '/crm-pay.php';
require_once __DIR__ . '/crm-portal.php';
