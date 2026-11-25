<?php
/**
 * STUDIIO.CO admin — automation and the approvals inbox (onboarding spec, phase 4).
 *
 * - Rules (spec section 6) draft messages into an outbox (collection "outbox") or do the
 *   internal steps the spec automates (make the agreement and deposit invoice, move to
 *   Onboarding, move to Delivered…). Event rules run inside the action that triggers them;
 *   timed rules run in crm_auto_tick(): when the admin is opened (at most every 15 minutes),
 *   from n8n (admin/hooks.php?action=tick) or from a cron job (admin/tools/tick.php).
 * - Nothing reaches a client before Mario approves it in the inbox, unless he switched that
 *   template to "send without approval" (and n8n is connected).
 * - Approved messages go to n8n (a webhook) when it is connected; n8n sends them and reports
 *   back to admin/hooks.php, which writes them to the message log. Without n8n, approving opens
 *   WhatsApp or email on Mario's device, like Send… on the client page.
 * - Settings (rules on/off, templates that send by themselves, the n8n address and key) live in
 *   the private data folder (crm/automation.json), never in the repo.
 */
declare(strict_types=1);

require_once __DIR__ . '/crm-portal.php';

/** rule => [label, kind]. kind "message" drafts a client message, "step" does an internal step, "notice" tells Mario. */
const CRM_RULES = [
    'intake_link' => ['Lead marked qualified → draft the intake questionnaire link', 'message'],
    'intake_reminder' => ['Intake not submitted after {{intake_reminder_days}} days → draft one reminder, then flag it for you', 'message'],
    'proposal_accepted' => ['Proposal accepted → make the agreement and the deposit invoice, draft the agreement message', 'step'],
    'agreement_ready' => ['Agreement signed and deposit in → make the welcome pack, draft it, move to Onboarding (checklist)', 'step'],
    'checklist_reminder' => ['Required checklist items still waiting {{checklist_reminder_days_before_due}} days before their due date → draft a reminder, mark waiting on the client', 'message'],
    'kickoff_notice' => ['All required checklist items received → tell you to book the kickoff', 'notice'],
    'feedback_reminder' => ['Feedback overdue on a shared round → draft a reminder with the new delivery date, and move the date', 'message'],
    'final_paid' => ['Final payment recorded → move to Delivered, draft the handover message', 'step'],
    'testimonial' => ['{{testimonial_days_after_handover}} days after the handover → draft the feedback and testimonial request', 'message'],
    'checkin' => ['{{checkin_days}} days after the handover → draft the check-in', 'message'],
    'retainer_cycle' => ['Retainers: start each month’s cycle in production, make its invoice and draft the invoice message', 'step'],
    'renewal' => ['Retainers: {{renewal_notice_days}} days before the month ends → draft the renewal summary', 'message'],
];

/* --------------------------------------------------------------------------
   Settings (private): rules on/off, auto-send templates, n8n
   -------------------------------------------------------------------------- */

function crm_auto_settings(): array
{
    $s = read_json_file(data_path('crm/automation.json'), []);
    $s = is_array($s) ? $s : [];
    $rules = [];
    foreach (array_keys(CRM_RULES) as $r) {
        $rules[$r] = (bool) ($s['rules'][$r] ?? true);
    }
    return [
        'rules' => $rules,
        'auto_send' => array_values(array_filter((array) ($s['auto_send'] ?? crm_settings()['terms']['send_without_approval'] ?? []), 'is_string')),
        'webhook_url' => (string) ($s['webhook_url'] ?? ''),
        'key' => (string) ($s['key'] ?? ''),
        'last_tick' => (string) ($s['last_tick'] ?? ''),
    ];
}

function crm_auto_save(array $changes): array
{
    return with_lock('crm-auto', static function () use ($changes): array {
        $s = array_replace(crm_auto_settings(), $changes);
        write_atomic(data_path('crm/automation.json'), encode_json($s));
        return $s;
    });
}

/** Rules, auto-send and the n8n address, from the inbox's settings panel. */
function crm_auto_update(array $in): array
{
    $changes = [];
    if (isset($in['rules']) && is_array($in['rules'])) {
        $rules = crm_auto_settings()['rules'];
        foreach ($in['rules'] as $r => $on) {
            if (isset(CRM_RULES[$r])) {
                $rules[$r] = (bool) $on;
            }
        }
        $changes['rules'] = $rules;
    }
    if (isset($in['auto_send']) && is_array($in['auto_send'])) {
        $known = array_keys(crm_message_templates());
        $changes['auto_send'] = array_values(array_intersect(array_map('strval', $in['auto_send']), $known));
    }
    if (array_key_exists('webhook_url', $in)) {
        $url = crm_text($in['webhook_url'], 500);
        if ($url !== '' && !preg_match('#^https://[^\s<>"]+$#i', $url) && !preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?/#', $url)) {
            throw new ApiError('The n8n address must start with https://');
        }
        $changes['webhook_url'] = $url;
    }
    if (!empty($in['new_key'])) {
        $changes['key'] = bin2hex(random_bytes(24));
    }
    return crm_auto_save($changes);
}

function crm_auto_on(string $rule): bool
{
    return crm_auto_settings()['rules'][$rule] ?? true;
}

function crm_n8n_connected(): bool
{
    $s = crm_auto_settings();
    return $s['webhook_url'] !== '' && $s['key'] !== '';
}

/* --------------------------------------------------------------------------
   Outbox: drafts and notices
   -------------------------------------------------------------------------- */

/** Has this rule already fired for this key (whatever happened to it since)? */
function crm_auto_fired(array $db, string $key): bool
{
    foreach ($db['outbox'] as $o) {
        if (($o['key'] ?? '') === $key) {
            return true;
        }
    }
    return false;
}

/** Who a message goes to: the client's preferred channel, falling back to the other one. */
function crm_auto_recipient(array $c): array
{
    $phone = preg_replace('/\D/', '', (string) preg_replace('/^00/', '+', trim((string) $c['phone'])));
    $hasPhone = str_starts_with(trim((string) $c['phone']), '+') || str_starts_with(trim((string) $c['phone']), '00');
    $wa = $hasPhone && strlen((string) $phone) >= 8 ? (string) $phone : '';
    $email = (string) $c['email'];
    $channel = $c['channel'] === 'email' ? ($email !== '' ? 'email' : ($wa !== '' ? 'whatsapp' : 'email')) : ($wa !== '' ? 'whatsapp' : ($email !== '' ? 'email' : 'whatsapp'));
    return ['channel' => $channel, 'whatsapp' => $wa, 'email' => $email];
}

/** Draft a client message from a template (inside a transaction). Returns the outbox row, or null if it already fired. */
function crm_auto_draft(array &$db, array $p, string $rule, string $key, string $template, string $docId = '', string $why = ''): ?array
{
    if (crm_auto_fired($db, $key)) {
        return null;
    }
    $c = $db['clients'][crm_index($db['clients'], $p['client_id'])];
    $tpl = crm_tpl_json('messages')[$template] ?? null;
    if (!is_array($tpl)) {
        return null;
    }
    $link = '';
    if ($docId !== '') {
        $k = crm_index($db['documents'], $docId);
        $link = $k === null ? '' : crm_doc_link($db['documents'][$k]);
    }
    $vars = crm_doc_vars($db, $p, $c, ['link' => $link]);
    $to = crm_auto_recipient($c);
    $row = [
        'id' => crm_id(), 'business' => CRM_BUSINESS, 'client_id' => $p['client_id'], 'project_id' => $p['id'], 'kind' => 'message',
        'rule' => $rule, 'key' => $key, 'template' => $template, 'why' => $why, 'doc_id' => $docId,
        'channel' => $to['channel'], 'to_whatsapp' => $to['whatsapp'], 'to_email' => $to['email'],
        'subject' => crm_tpl_text((string) ($tpl['subject'] ?? ''), $vars), 'text' => crm_tpl_text((string) $tpl['text'], $vars),
        'status' => 'pending', 'created_at' => gmdate('c'), 'approved_at' => '', 'sent_at' => '', 'via' => '', 'error' => '', 'by' => '',
    ];
    $db['outbox'][] = $row;
    crm_log($db, $p, 'automation', 'automation', 'Drafted for your approval: ' . (crm_message_templates()[$template] ?? $template));
    if (in_array($template, crm_auto_settings()['auto_send'], true) && crm_n8n_connected()) {
        crm_outbox_queue($db, $row['id'], 'automation');
    }
    return $row;
}

/** A note for Mario in the inbox (not a client message). */
function crm_auto_notice(array &$db, array $p, string $rule, string $key, string $text): void
{
    if (crm_auto_fired($db, $key)) {
        return;
    }
    $db['outbox'][] = [
        'id' => crm_id(), 'business' => CRM_BUSINESS, 'client_id' => $p['client_id'], 'project_id' => $p['id'], 'kind' => 'notice',
        'rule' => $rule, 'key' => $key, 'template' => '', 'why' => '', 'doc_id' => '', 'channel' => '', 'to_whatsapp' => '', 'to_email' => '',
        'subject' => '', 'text' => $text, 'status' => 'pending', 'created_at' => gmdate('c'), 'approved_at' => '', 'sent_at' => '',
        'via' => '', 'error' => '', 'by' => '',
    ];
    crm_log($db, $p, 'automation', 'automation', $text);
}

function crm_project_fresh(array $db, string $pid): array
{
    return $db['projects'][crm_project_at($db, $pid)];
}

/* --------------------------------------------------------------------------
   Event rules (called inside the action's transaction)
   -------------------------------------------------------------------------- */

/** Lead marked qualified → draft the intake link. */
function crm_auto_qualified(array &$db, string $pid): void
{
    if (!crm_auto_on('intake_link')) {
        return;
    }
    $p = crm_project_fresh($db, $pid);
    if (!empty($p['intake']['submitted_at'])) {
        return;
    }
    crm_intake_link_in($db, $pid, 'automation');
    $doc = null;
    foreach ($db['documents'] as $d) {
        if ($d['project_id'] === $pid && $d['type'] === 'intake') {
            $doc = $d;
        }
    }
    if ($doc && !in_array($doc['status'], ['completed', 'sent', 'viewed'], true)) {
        crm_auto_draft($db, crm_project_fresh($db, $pid), 'intake_link', "intake_link:$pid", 'intake_link', $doc['id'], 'The lead was marked qualified.');
    }
}

/** Proposal accepted → agreement + deposit invoice, and a draft to send the agreement. */
function crm_auto_proposal_accepted(array &$db, string $pid): void
{
    if (!crm_auto_on('proposal_accepted')) {
        return;
    }
    $p = crm_project_fresh($db, $pid);
    if ($p['status'] !== 'open') {
        return;
    }
    $agreement = null;
    foreach ($db['documents'] as $d) {
        if ($d['project_id'] === $pid && $d['type'] === 'agreement') {
            $agreement = $d;
        }
    }
    if (!$agreement || ($agreement['file'] ?? '') === '') {
        try {
            crm_doc_make_in($db, $pid, 'agreement', [], 'automation');
        } catch (ApiError $e) {
            return; // already signed by hand, nothing to make
        }
        foreach ($db['documents'] as $d) {
            if ($d['project_id'] === $pid && $d['type'] === 'agreement') {
                $agreement = $d;
            }
        }
    }
    crm_invoice_auto_agreement($db, crm_project_fresh($db, $pid), 'automation');
    if ($agreement && !in_array($agreement['status'], ['signed', 'completed', 'sent', 'viewed'], true)) {
        crm_auto_draft($db, crm_project_fresh($db, $pid), 'proposal_accepted', "agreement_link:$pid", 'agreement_link', $agreement['id'], 'The proposal was accepted.');
    }
}

/** Agreement signed and deposit in → welcome pack (made + drafted) and move to Onboarding. */
function crm_auto_agreement_ready(array &$db, string $pid): void
{
    if (!crm_auto_on('agreement_ready')) {
        return;
    }
    $p = crm_project_fresh($db, $pid);
    $signed = false;
    foreach ($db['documents'] as $d) {
        if ($d['project_id'] === $pid && $d['type'] === 'agreement' && $d['status'] === 'signed') {
            $signed = true;
        }
    }
    if ($p['status'] !== 'open' || !$signed || crm_paid($db, $pid) <= 0 || crm_stage_no($p['stage']) > crm_stage_no('onboarding')) {
        return;
    }
    if ($p['stage'] === 'agreement') {
        try {
            crm_move_in($db, $pid, 'onboarding', '', 'automation');
        } catch (ApiError $e) {
            // a condition is still open: leave it to Mario
        }
    }
    $pack = null;
    foreach ($db['documents'] as $d) {
        if ($d['project_id'] === $pid && $d['type'] === 'welcome_pack') {
            $pack = $d;
        }
    }
    if (!$pack) {
        crm_doc_make_in($db, $pid, 'welcome_pack', [], 'automation');
        foreach ($db['documents'] as $d) {
            if ($d['project_id'] === $pid && $d['type'] === 'welcome_pack') {
                $pack = $d;
            }
        }
    }
    if ($pack && $pack['status'] === 'draft') {
        crm_auto_draft($db, crm_project_fresh($db, $pid), 'agreement_ready', "welcome_pack:$pid", 'welcome_pack_link', $pack['id'], 'The agreement is signed and the deposit is in.');
    }
}

/** All required checklist items received → tell Mario to book the kickoff. */
function crm_auto_checklist_done(array &$db, string $pid): void
{
    if (!crm_auto_on('kickoff_notice')) {
        return;
    }
    $p = crm_project_fresh($db, $pid);
    $req = array_filter($db['checklist'], static fn($i) => $i['project_id'] === $pid && $i['required']);
    if ($p['status'] !== 'open' || $p['stage'] !== 'onboarding' || !$req || array_filter($req, static fn($i) => $i['status'] === 'waiting')) {
        return;
    }
    $c = $db['clients'][crm_index($db['clients'], $p['client_id'])];
    crm_auto_notice($db, $p, 'kickoff_notice', "kickoff_notice:$pid", 'Everything on the checklist is in for ' . ($c['business_name'] ?: $c['contact_name']) . ': book the kickoff.');
    $i = crm_project_at($db, $pid);
    if ($db['projects'][$i]['blocked_by'] === 'client') {
        $db['projects'][$i]['blocked_by'] = '';
    }
}

/** Final payment recorded → move to Delivered and draft the handover. */
function crm_auto_paid(array &$db, string $pid): void
{
    if (!crm_auto_on('final_paid')) {
        return;
    }
    $p = crm_project_fresh($db, $pid);
    $price = (float) ($p['price'] ?? 0);
    if ($p['status'] !== 'open' || $p['track'] === 'retainer' || $price <= 0 || crm_paid($db, $pid) < $price
        || crm_stage_no($p['stage']) < crm_stage_no('review') || crm_stage_no($p['stage']) > crm_stage_no('delivered')) {
        return;
    }
    if ($p['stage'] === 'review') {
        try {
            crm_move_in($db, $pid, 'delivered', '', 'automation');
        } catch (ApiError $e) {
            return; // not approved yet: Mario moves it when it is
        }
    }
    $portal = crm_portal_doc($db, $pid);
    crm_auto_draft($db, crm_project_fresh($db, $pid), 'final_paid', "handover:$pid", 'handover', $portal['id'] ?? '', 'The final payment is in.');
}

/* --------------------------------------------------------------------------
   Timed rules
   -------------------------------------------------------------------------- */

/** Add working days (Monday to Friday). */
function crm_add_working_days(string $ymd, int $days): string
{
    $d = new DateTimeImmutable($ymd);
    while ($days > 0) {
        $d = $d->modify('+1 day');
        if ((int) $d->format('N') <= 5) {
            $days--;
        }
    }
    return $d->format('Y-m-d');
}

function crm_working_days_between(string $from, string $to): int
{
    $n = 0;
    $d = new DateTimeImmutable($from);
    $end = new DateTimeImmutable($to);
    while ($d < $end) {
        $d = $d->modify('+1 day');
        if ((int) $d->format('N') <= 5) {
            $n++;
        }
    }
    return $n;
}

/** When the handover message went out (from the message log), or ''. */
function crm_handover_date(array $db, string $pid): string
{
    $date = '';
    foreach ($db['messages'] as $m) {
        if ($m['project_id'] === $pid && ($m['template'] ?? '') === 'handover' && $m['direction'] === 'out') {
            $date = max($date, (string) $m['date']);
        }
    }
    return $date;
}

/** Run the timed rules over every open project. Returns how many things it created. */
function crm_auto_tick(): int
{
    $made = crm_tx(static function (array &$db): int {
        $before = count($db['outbox']);
        $today = crm_today();
        $timing = crm_settings()['timing'];
        $terms = crm_settings()['terms'];
        foreach ($db['projects'] as $p) {
            $pid = $p['id'];
            if ($p['status'] !== 'open' && $p['stage'] !== 'follow_up') {
                continue;
            }
            // Intake not submitted after N days → one reminder, then a flag for Mario.
            if (crm_auto_on('intake_reminder') && empty($p['intake']['submitted_at'])) {
                foreach ($db['documents'] as $d) {
                    if ($d['project_id'] !== $pid || $d['type'] !== 'intake' || !in_array($d['status'], ['sent', 'viewed'], true) || $d['sent_at'] === '') {
                        continue;
                    }
                    $days = (int) ($timing['intake_reminder_days'] ?? 3);
                    if (substr($d['sent_at'], 0, 10) <= date('Y-m-d', (int) strtotime("$today -$days days"))) {
                        crm_auto_draft($db, $p, 'intake_reminder', "intake_reminder:$pid", 'intake_reminder', $d['id'], "The questionnaire was sent $days or more days ago and isn't filled in.");
                    }
                    foreach ($db['outbox'] as $o) {
                        if (($o['key'] ?? '') === "intake_reminder:$pid" && $o['status'] === 'sent' && substr($o['sent_at'], 0, 10) <= date('Y-m-d', (int) strtotime("$today -$days days"))) {
                            $c = $db['clients'][crm_index($db['clients'], $p['client_id'])];
                            crm_auto_notice($db, $p, 'intake_reminder', "intake_flag:$pid", 'The intake questionnaire is still not filled in by ' . ($c['business_name'] ?: $c['contact_name']) . ', even after the reminder. Follow up yourself.');
                            $i = crm_project_at($db, $pid);
                            $db['projects'][$i]['blocked_by'] = 'client';
                        }
                    }
                }
            }
            // Required checklist items still waiting N days before their due date.
            if (crm_auto_on('checklist_reminder') && $p['status'] === 'open') {
                $waiting = array_filter($db['checklist'], static fn($i) => $i['project_id'] === $pid && $i['required'] && $i['status'] === 'waiting' && $i['due_date'] !== '');
                $soon = date('Y-m-d', (int) strtotime($today . ' +' . (int) ($timing['checklist_reminder_days_before_due'] ?? 2) . ' days'));
                $due = $waiting ? min(array_column($waiting, 'due_date')) : '';
                if ($due !== '' && $due <= $soon) {
                    $portal = crm_portal_doc($db, $pid);
                    if (crm_auto_draft($db, $p, 'checklist_reminder', "checklist_reminder:$pid:$due", 'checklist_reminder', $portal['id'] ?? '', 'Checklist items are due ' . crm_long_date($due) . ' and still waiting.')) {
                        $i = crm_project_at($db, $pid);
                        $db['projects'][$i]['blocked_by'] = 'client';
                    }
                }
            }
            // Feedback overdue on a shared round → reminder with the new delivery date (moved by the delay).
            if (crm_auto_on('feedback_reminder') && $p['status'] === 'open') {
                foreach ((array) ($p['rounds'] ?? []) as $r) {
                    $dueBy = (string) ($r['feedback_due'] ?? '');
                    if ($r['status'] !== 'shared' || $dueBy === '' || $dueBy >= $today || crm_auto_fired($db, "feedback_reminder:$pid:{$r['n']}")) {
                        continue;
                    }
                    $late = crm_working_days_between($dueBy, $today);
                    $i = crm_project_at($db, $pid);
                    if (($p['delivery_date'] ?? '') !== '' && $late > 0) {
                        $moved = crm_add_working_days($p['delivery_date'], $late);
                        $db['projects'][$i]['delivery_date'] = $moved;
                        crm_log($db, $p, 'automation', 'automation', "Delivery moved to $moved: feedback on round {$r['n']} is $late working day(s) late");
                    }
                    $portal = crm_portal_doc($db, $pid);
                    crm_auto_draft($db, $db['projects'][$i], 'feedback_reminder', "feedback_reminder:$pid:{$r['n']}", 'feedback_reminder', $portal['id'] ?? '', "Feedback on round {$r['n']} was due " . crm_long_date($dueBy) . '.');
                }
            }
            // After the handover: testimonial request, then the check-in.
            $handover = crm_handover_date($db, $pid);
            if ($handover !== '') {
                $t = (int) ($timing['testimonial_days_after_handover'] ?? 3);
                if (crm_auto_on('testimonial') && $handover <= date('Y-m-d', (int) strtotime("$today -$t days"))) {
                    crm_auto_draft($db, $p, 'testimonial', "testimonial:$pid", 'testimonial', '', "The handover went out $t or more days ago.");
                }
                $ci = (int) ($terms['checkin_days_after_delivery'] ?? 60);
                if (crm_auto_on('checkin') && $handover <= date('Y-m-d', (int) strtotime("$today -$ci days"))) {
                    crm_auto_draft($db, $p, 'checkin', "checkin:$pid", 'checkin', '', "The handover went out $ci or more days ago.");
                }
            }
        }
        crm_cycles_tick($db); // retainers: this month's cycle, renewal summaries
        return count($db['outbox']) - $before;
    });
    crm_auto_save(['last_tick' => gmdate('c')]);
    return $made;
}

/** Run the timed rules if they haven't run in the last 15 minutes (called when the admin is opened). */
function crm_auto_tick_maybe(): void
{
    $last = crm_auto_settings()['last_tick'];
    if ($last === '' || strtotime($last) < time() - 900) {
        try {
            crm_auto_tick();
        } catch (Throwable $e) {
            error_log('studiio-admin automation: ' . $e->getMessage());
        }
    }
}

/* --------------------------------------------------------------------------
   Approving and sending
   -------------------------------------------------------------------------- */

/** Mark an outbox message approved and hand it to n8n (inside a transaction). */
function crm_outbox_queue(array &$db, string $id, string $by): void
{
    $k = crm_index($db['outbox'], $id);
    $db['outbox'][$k]['status'] = 'approved';
    $db['outbox'][$k]['approved_at'] = gmdate('c');
    $db['outbox'][$k]['by'] = $by;
    $db['outbox'][$k]['via'] = 'n8n';
    $ok = crm_n8n_push($db['outbox'][$k], $db);
    if ($ok !== true) {
        $db['outbox'][$k]['error'] = $ok; // n8n can still pick it up from the outbox
    }
}

/** POST one message to the n8n webhook. Returns true, or the error. */
function crm_n8n_push(array $o, array $db): true|string
{
    $s = crm_auto_settings();
    if ($s['webhook_url'] === '' || !function_exists('curl_init')) {
        return 'n8n is not connected';
    }
    $c = $db['clients'][crm_index($db['clients'], $o['client_id'])] ?? ['business_name' => '', 'contact_name' => ''];
    $p = $db['projects'][crm_index($db['projects'], $o['project_id'])] ?? ['title' => ''];
    $base = crm_public_base() . '/admin/hooks.php';
    $payload = [
        'event' => 'send', 'id' => $o['id'], 'channel' => $o['channel'],
        'to' => ['whatsapp' => $o['to_whatsapp'], 'email' => $o['to_email'], 'name' => $c['contact_name']],
        'subject' => $o['subject'], 'text' => $o['text'], 'template' => $o['template'],
        'client' => $c['business_name'] ?: $c['contact_name'], 'project' => $p['title'],
        'callback' => ['sent' => "$base?action=sent", 'failed' => "$base?action=failed"],
    ];
    $ch = curl_init($s['webhook_url']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Studiio-Key: ' . $s['key'], 'User-Agent: studiio-admin'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) {
        return 'Could not reach n8n: ' . $err;
    }
    return $code >= 200 && $code < 300 ? true : "n8n answered $code";
}

/**
 * Mario approves a drafted message (with his edits). With n8n: queued for n8n. Without: marked
 * sent and logged, and the admin opens WhatsApp or email with the text. Returns the client id
 * and, without n8n, the link to open.
 */
function crm_outbox_approve(string $id, array $in, string $by): array
{
    return crm_tx(static function (array &$db) use ($id, $in, $by): array {
        $k = crm_index($db['outbox'], $id);
        if ($k === null) {
            throw new ApiError('That draft no longer exists.', 404);
        }
        $o = $db['outbox'][$k];
        if ($o['kind'] !== 'message' || !in_array($o['status'], ['pending', 'failed'], true)) {
            throw new ApiError('This one has already been handled.');
        }
        $text = mb_substr(trim(str_replace("\r\n", "\n", is_string($in['text'] ?? null) ? $in['text'] : $o['text'])), 0, 4000);
        if ($text === '') {
            throw new ApiError('The message is empty.');
        }
        $db['outbox'][$k]['text'] = $text;
        $db['outbox'][$k]['subject'] = crm_text($in['subject'] ?? $o['subject'], 200);
        $channel = in_array($in['channel'] ?? '', ['whatsapp', 'email'], true) ? $in['channel'] : $o['channel'];
        if (($channel === 'whatsapp' && $o['to_whatsapp'] === '') || ($channel === 'email' && $o['to_email'] === '')) {
            throw new ApiError($channel === 'whatsapp' ? 'This client has no phone number with a country code. Add it, or send by email.' : 'This client has no email address. Add it, or send on WhatsApp.');
        }
        $db['outbox'][$k]['channel'] = $channel;
        if (crm_n8n_connected()) {
            crm_outbox_queue($db, $id, $by);
            return ['client_id' => $o['client_id'], 'open' => '', 'queued' => true, 'error' => $db['outbox'][$k]['error']];
        }
        crm_outbox_mark_sent($db, $id, 'manual', $by);
        $o = $db['outbox'][$k];
        $open = $channel === 'whatsapp'
            ? 'https://wa.me/' . $o['to_whatsapp'] . '?text=' . rawurlencode($o['text'])
            : 'mailto:' . rawurlencode($o['to_email']) . '?subject=' . rawurlencode($o['subject']) . '&body=' . rawurlencode($o['text']);
        return ['client_id' => $o['client_id'], 'open' => $open, 'queued' => false, 'error' => ''];
    });
}

/** Sent: write it to the message log and mark the linked document sent (inside a transaction). */
function crm_outbox_mark_sent(array &$db, string $id, string $via, string $by): void
{
    $k = crm_index($db['outbox'], $id);
    $o = $db['outbox'][$k];
    if ($o['status'] === 'sent') {
        return;
    }
    $db['outbox'][$k]['status'] = 'sent';
    $db['outbox'][$k]['sent_at'] = gmdate('c');
    $db['outbox'][$k]['via'] = $via;
    $db['outbox'][$k]['error'] = '';
    if ($db['outbox'][$k]['approved_at'] === '') {
        $db['outbox'][$k]['approved_at'] = gmdate('c');
        $db['outbox'][$k]['by'] = $by;
    }
    $db['messages'][] = [
        'id' => crm_id(), 'business' => CRM_BUSINESS, 'client_id' => $o['client_id'], 'project_id' => $o['project_id'],
        'direction' => 'out', 'channel' => $o['channel'], 'template' => $o['template'], 'doc_id' => $o['doc_id'], 'date' => crm_today(),
        'summary' => $o['text'], 'created_at' => gmdate('c'), 'created_by' => $via === 'n8n' ? 'n8n' : $by,
    ];
    $i = crm_index($db['projects'], $o['project_id']);
    if ($o['doc_id'] !== '') {
        $d = crm_index($db['documents'], $o['doc_id']);
        if ($d !== null && $db['documents'][$d]['status'] === 'draft') {
            $db['documents'][$d]['status'] = 'sent';
            $db['documents'][$d]['sent_at'] = gmdate('c');
            if ($db['documents'][$d]['type'] === 'agreement' && $i !== null) {
                crm_invoice_auto_agreement($db, $db['projects'][$i], 'automation');
            }
        }
    }
    if ($i !== null) {
        crm_log($db, $db['projects'][$i], $via === 'n8n' ? 'n8n' : $by, 'message', 'Sent (' . (CRM_MESSAGE_CHANNELS[$o['channel']] ?? $o['channel']) . ($via === 'n8n' ? ', by n8n' : '') . '): ' . (crm_message_templates()[$o['template']] ?? $o['template']));
    }
}

/** Dismiss a draft, or tick off a notice. */
function crm_outbox_dismiss(string $id, string $by): string
{
    return crm_tx(static function (array &$db) use ($id, $by): string {
        $k = crm_index($db['outbox'], $id);
        if ($k === null) {
            throw new ApiError('That draft no longer exists.', 404);
        }
        $o = $db['outbox'][$k];
        $db['outbox'][$k]['status'] = $o['kind'] === 'notice' ? 'done' : 'dismissed';
        $db['outbox'][$k]['by'] = $by;
        $db['outbox'][$k]['approved_at'] = gmdate('c');
        return $o['client_id'];
    });
}

/** n8n reports back (admin/hooks.php). */
function crm_outbox_report(string $id, bool $sent, string $error = ''): void
{
    crm_tx(static function (array &$db) use ($id, $sent, $error): void {
        $k = crm_index($db['outbox'], $id);
        if ($k === null) {
            throw new ApiError('Unknown message.', 404);
        }
        if ($sent) {
            crm_outbox_mark_sent($db, $id, 'n8n', 'n8n');
        } elseif ($db['outbox'][$k]['status'] !== 'sent') {
            $db['outbox'][$k]['status'] = 'failed';
            $db['outbox'][$k]['error'] = mb_substr($error !== '' ? $error : 'n8n could not send it', 0, 500);
        }
    });
}

/** What n8n can pick up: approved messages not sent yet. */
function crm_outbox_for_n8n(): array
{
    $db = crm_load();
    $out = [];
    foreach ($db['outbox'] as $o) {
        if ($o['kind'] === 'message' && $o['status'] === 'approved') {
            $c = $db['clients'][crm_index($db['clients'], $o['client_id'])] ?? ['business_name' => '', 'contact_name' => ''];
            $out[] = ['id' => $o['id'], 'channel' => $o['channel'], 'to' => ['whatsapp' => $o['to_whatsapp'], 'email' => $o['to_email'], 'name' => $c['contact_name']],
                'subject' => $o['subject'], 'text' => $o['text'], 'template' => $o['template'], 'client' => $c['business_name'] ?: $c['contact_name']];
        }
    }
    return $out;
}

/** The inbox page. */
function crm_inbox(): array
{
    $db = crm_load();
    $clients = array_column($db['clients'], null, 'id');
    $projects = array_column($db['projects'], null, 'id');
    $rows = array_map(static function ($o) use ($clients, $projects): array {
        $c = $clients[$o['client_id']] ?? ['business_name' => '?', 'contact_name' => ''];
        return $o + ['client' => $c['business_name'] ?: $c['contact_name'], 'project' => $projects[$o['project_id']]['title'] ?? ''];
    }, array_reverse($db['outbox']));
    $s = crm_auto_settings();
    $vars = crm_settings()['timing'] + ['checkin_days' => crm_settings()['terms']['checkin_days_after_delivery']];
    return [
        'pending' => array_values(array_filter($rows, static fn($o) => in_array($o['status'], ['pending', 'failed'], true))),
        'recent' => array_slice(array_values(array_filter($rows, static fn($o) => !in_array($o['status'], ['pending', 'failed'], true))), 0, 60),
        'rules' => array_map(static fn($r, $k) => ['key' => $k, 'label' => crm_tpl_vars($r[0], array_map('strval', $vars)), 'kind' => $r[1], 'on' => $s['rules'][$k]], CRM_RULES, array_keys(CRM_RULES)),
        'templates' => crm_message_templates(),
        'autoSend' => $s['auto_send'],
        'n8n' => ['connected' => crm_n8n_connected(), 'webhook_url' => $s['webhook_url'], 'key' => $s['key'], 'last_tick' => $s['last_tick'],
            'hooks' => crm_public_base() . '/admin/hooks.php'],
    ];
}

function crm_inbox_count(): int
{
    try {
        return count(array_filter(crm_load()['outbox'], static fn($o) => in_array($o['status'], ['pending', 'failed'], true)));
    } catch (ApiError $e) {
        return 0;
    }
}

/** Send n8n a test call (event "test"), so Mario can check the connection. */
function crm_n8n_test(): string
{
    $s = crm_auto_settings();
    if ($s['webhook_url'] === '' || $s['key'] === '') {
        return 'Add the n8n address and make a key first.';
    }
    $ch = curl_init($s['webhook_url']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Studiio-Key: ' . $s['key'], 'User-Agent: studiio-admin'],
        CURLOPT_POSTFIELDS => json_encode(['event' => 'test', 'at' => gmdate('c')]),
    ]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) {
        return 'Could not reach n8n: ' . $err;
    }
    return $code >= 200 && $code < 300 ? 'ok' : "n8n answered $code";
}

