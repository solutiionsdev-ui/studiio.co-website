<?php
/**
 * STUDIIO.CO admin — retainer cycles (onboarding spec, phase 6).
 *
 * For a retainer, stages 7 to 9 repeat every month as a cycle (collection "cycles"): the month's
 * content plan (shared with the client, who approves it or asks for changes on their portal),
 * its shoot visits, its deliverables (rounds uploaded during the month belong to it; the client's
 * approval marks it delivered) and its invoice. Each month's cycle starts by itself (with its
 * invoice and a draft of the invoice message), the renewal summary is drafted 10 days before
 * the month ends, and ending the retainer (minimum term and notice from settings) moves the
 * project to Offboarding once its last month is closed.
 */
declare(strict_types=1);

require_once __DIR__ . '/crm-auto.php';

function crm_month_after(string $ym, int $n = 1): string
{
    return (new DateTimeImmutable("$ym-01"))->modify("+$n month")->format('Y-m');
}

function crm_month_end(string $ym): string
{
    return (new DateTimeImmutable("$ym-01"))->modify('last day of this month')->format('Y-m-d');
}

function crm_cycle_at(array $db, string $pid, string $month): ?int
{
    foreach ($db['cycles'] as $k => $c) {
        if ($c['project_id'] === $pid && $c['month'] === $month) {
            return $k;
        }
    }
    return null;
}

/** The newest cycle of a project (open or closed), or null. */
function crm_cycle_latest(array $db, string $pid): ?array
{
    $found = null;
    foreach ($db['cycles'] as $c) {
        if ($c['project_id'] === $pid && (!$found || $c['month'] > $found['month'])) {
            $found = $c;
        }
    }
    return $found;
}

function crm_cycle_open(array $db, string $pid): ?array
{
    $found = null;
    foreach ($db['cycles'] as $c) {
        if ($c['project_id'] === $pid && $c['status'] === 'open' && (!$found || $c['month'] < $found['month'])) {
            $found = $c;
        }
    }
    return $found;
}

/** Shoot dates booked in a month. */
function crm_cycle_visits(array $p, string $month): int
{
    return count(array_filter((array) ($p['shoot_dates'] ?? []), static fn($d) => is_string($d) && str_starts_with($d, $month)));
}

/** Has the retainer been ended, and up to which month does it run? ('' = it keeps going). */
function crm_retainer_last_month(array $p): string
{
    return !empty($p['retainer_ends']) ? substr((string) $p['retainer_ends'], 0, 7) : '';
}

/**
 * Start a month's cycle (inside a transaction), with its invoice and a draft of the invoice
 * message. Returns the cycle, or null when it already exists or the retainer has ended.
 */
function crm_cycle_start_in(array &$db, string $pid, string $month, string $by): ?array
{
    $p = $db['projects'][crm_project_at($db, $pid)];
    if ($p['track'] !== 'retainer') {
        throw new ApiError('Monthly cycles are for retainers.');
    }
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
        throw new ApiError('Pick a month.');
    }
    $last = crm_retainer_last_month($p);
    if (crm_cycle_at($db, $pid, $month) !== null || ($last !== '' && $month > $last)) {
        return null;
    }
    $cycle = [
        'id' => crm_id(), 'business' => CRM_BUSINESS, 'client_id' => $p['client_id'], 'project_id' => $pid, 'month' => $month,
        'status' => 'open', 'plan_text' => '', 'plan_status' => '', 'plan_shared_at' => '', 'plan_feedback' => [], 'plan_approved' => null,
        'deliverables_due' => array_values((array) ($p['deliverables'] ?? [])), 'delivered_at' => '', 'invoice_id' => '', 'closed_at' => '',
        'created_at' => gmdate('c'),
    ];
    // The month's invoice: reuse one already made for that month, otherwise make it.
    foreach (crm_invoices($db, $pid) as $inv) {
        if (($inv['month'] ?? '') === $month) {
            $cycle['invoice_id'] = $inv['id'];
        }
    }
    if ($cycle['invoice_id'] === '' && (float) ($p['price'] ?? 0) > 0) {
        $inv = crm_invoice_create($db, $p, 'monthly', ['month' => $month], $by);
        $cycle['invoice_id'] = $inv['id'];
        crm_auto_draft($db, $db['projects'][crm_project_at($db, $pid)], 'retainer_cycle', "invoice:$pid:$month", 'invoice_link', $inv['id'], crm_month_label($month) . ' started.');
    }
    $db['cycles'][] = $cycle;
    crm_log($db, $p, $by, 'cycle', crm_month_label($month) . ' started');
    return $cycle;
}

/** Start the next month by hand. Returns the client id. */
function crm_cycle_start(string $pid, string $month, string $by): string
{
    return crm_tx(static function (array &$db) use ($pid, $month, $by): string {
        $p = $db['projects'][crm_project_at($db, $pid)];
        if (!crm_cycle_start_in($db, $pid, $month, $by)) {
            throw new ApiError(crm_cycle_at($db, $pid, $month) !== null ? crm_month_label($month) . ' already has a cycle.' : 'The retainer ends before ' . crm_month_label($month) . '.');
        }
        return $p['client_id'];
    });
}

function crm_cycle_find(array $db, string $id): int
{
    $k = crm_index($db['cycles'], $id);
    if ($k === null) {
        throw new ApiError('That month no longer exists.', 404);
    }
    return $k;
}

/**
 * Change a cycle: op = save (plan text, deliverables due), share (plan to the client),
 * unshare, delivered, undelivered, close, reopen. Returns the client id.
 */
function crm_cycle_update(string $id, array $in, string $by): string
{
    $op = crm_pick($in['op'] ?? '', ['save', 'share', 'unshare', 'delivered', 'undelivered', 'close', 'reopen'], 'action');
    return crm_tx(static function (array &$db) use ($id, $in, $op, $by): string {
        $k = crm_cycle_find($db, $id);
        $c = $db['cycles'][$k];
        $i = crm_project_at($db, $c['project_id']);
        $p = $db['projects'][$i];
        $label = crm_month_label($c['month']);
        switch ($op) {
            case 'save':
                if (array_key_exists('plan_text', $in)) {
                    if ($c['plan_status'] === 'approved' && trim((string) $in['plan_text']) !== $c['plan_text']) {
                        throw new ApiError('This plan is already approved by the client. Changing it means sharing it again.');
                    }
                    $c['plan_text'] = mb_substr(trim(str_replace("\r\n", "\n", is_string($in['plan_text']) ? $in['plan_text'] : '')), 0, 8000);
                }
                if (array_key_exists('deliverables_due', $in)) {
                    $c['deliverables_due'] = crm_lines($in['deliverables_due']);
                }
                break;
            case 'share':
                if (trim($c['plan_text']) === '') {
                    throw new ApiError('Write the plan first.');
                }
                if ($c['plan_status'] === 'approved') {
                    throw new ApiError('The client already approved this plan.');
                }
                $c['plan_status'] = 'shared';
                $c['plan_shared_at'] = gmdate('c');
                crm_log($db, $p, $by, 'cycle', "$label plan shared with the client");
                break;
            case 'unshare':
                if ($c['plan_status'] !== 'shared') {
                    throw new ApiError('Only a shared plan the client hasn’t answered can be taken back.');
                }
                $c['plan_status'] = '';
                $c['plan_shared_at'] = '';
                break;
            case 'delivered':
                $c['delivered_at'] = crm_today();
                crm_log($db, $p, $by, 'cycle', "$label marked delivered");
                break;
            case 'undelivered':
                $c['delivered_at'] = '';
                break;
            case 'close':
                $c['status'] = 'closed';
                $c['closed_at'] = gmdate('c');
                crm_log($db, $p, $by, 'cycle', "$label closed" . (crm_cycle_unused($p, $c) > 0 ? ' (' . crm_cycle_unused($p, $c) . ' unused visit(s)' . (crm_settings()['terms']['unused_visits_roll_over'] ? ', rolled over)' : ', not rolled over)') : ''));
                break;
            case 'reopen':
                $c['status'] = 'open';
                $c['closed_at'] = '';
                break;
        }
        $db['cycles'][$k] = $c;
        // The retainer's last month is closed: on to Offboarding (spec: only when the retainer ends).
        $last = crm_retainer_last_month($p);
        if ($op === 'close' && $last !== '' && $c['month'] >= $last && $p['status'] === 'open' && crm_stage_no($p['stage']) < crm_stage_no('offboarding')) {
            crm_move_in($db, $p['id'], 'offboarding', 'The retainer ended (' . crm_month_label($last) . ' was the last month).', 'automation');
        }
        return $p['client_id'];
    });
}

function crm_cycle_unused(array $p, array $c): int
{
    $allowance = (int) ($p['visits_per_month'] ?? 0);
    return $allowance > 0 ? max(0, $allowance - crm_cycle_visits($p, $c['month'])) : 0;
}

/**
 * End the retainer: it runs to the end of the month of $date, at the earliest after the notice
 * period and the minimum term. Returns the client id.
 */
function crm_retainer_end(string $pid, mixed $date, string $by): string
{
    $date = crm_date($date);
    return crm_tx(static function (array &$db) use ($pid, $date, $by): string {
        $i = crm_project_at($db, $pid);
        $p = $db['projects'][$i];
        if ($p['track'] !== 'retainer') {
            throw new ApiError('Only a retainer can be ended this way.');
        }
        $t = crm_settings()['terms'];
        $earliest = date('Y-m-d', (int) strtotime(crm_today() . ' +' . (int) $t['retainer_notice_days'] . ' days'));
        $start = (string) ($p['start_date'] ?? '') ?: substr((string) ($p['created_at'] ?? gmdate('c')), 0, 10);
        $minimum = date('Y-m-d', (int) strtotime($start . ' +' . (int) $t['retainer_minimum_months'] . ' months -1 day'));
        $end = crm_month_end(substr(max($date ?: $earliest, $earliest, $minimum), 0, 7));
        $p['retainer_ends'] = $end;
        $p['end_date'] = $end;
        $p['updated_at'] = gmdate('c');
        $db['projects'][$i] = $p;
        crm_log($db, $p, $by, 'cycle', 'Retainer ends ' . crm_long_date($end) . " (notice {$t['retainer_notice_days']} days, minimum {$t['retainer_minimum_months']} months)");
        return $p['client_id'];
    });
}

/** Undo ending the retainer. */
function crm_retainer_continue(string $pid, string $by): string
{
    return crm_tx(static function (array &$db) use ($pid, $by): string {
        $i = crm_project_at($db, $pid);
        $db['projects'][$i]['retainer_ends'] = '';
        crm_log($db, $db['projects'][$i], $by, 'cycle', 'Retainer continues (end cancelled)');
        return $db['projects'][$i]['client_id'];
    });
}

/* --------------------------------------------------------------------------
   The client's side (portal) and automation
   -------------------------------------------------------------------------- */

/** The client approves the month's plan (typed name) or sends changes. Returns a notice code. */
function crm_portal_plan(string $pid, string $cycleId, string $action, string $value): string
{
    return crm_tx(static function (array &$db) use ($pid, $cycleId, $action, $value): string {
        $k = crm_cycle_find($db, $cycleId);
        $c = $db['cycles'][$k];
        if ($c['project_id'] !== $pid || $c['plan_status'] !== 'shared') {
            throw new ApiError(crm_portal_label('errors.failed'), 409);
        }
        $p = $db['projects'][crm_project_at($db, $pid)];
        $label = crm_month_label($c['month']);
        if ($action === 'approve') {
            $name = crm_text($value, 120);
            if (mb_strlen($name) < 2) {
                throw new ApiError(crm_portal_label('errors.name'));
            }
            $c['plan_status'] = 'approved';
            $c['plan_approved'] = ['name' => $name, 'date' => crm_today(), 'at' => gmdate('c')];
            crm_log($db, $p, 'client', 'cycle', "Client approved the $label plan ($name)");
            $code = 'plan_approved';
        } else {
            $text = mb_substr(trim(str_replace("\r\n", "\n", $value)), 0, 5000);
            if ($text === '') {
                throw new ApiError(crm_portal_label('errors.empty'));
            }
            $c['plan_status'] = 'changes';
            $c['plan_feedback'][] = ['text' => $text, 'at' => gmdate('c')];
            crm_log($db, $p, 'client', 'cycle', "Changes asked on the $label plan: " . mb_substr($text, 0, 300));
            $code = 'plan_changes';
        }
        $db['cycles'][$k] = $c;
        return $code;
    });
}

/** Timed: start this month's cycle for running retainers, and draft renewal summaries 10 days before month end. */
function crm_cycles_tick(array &$db): void
{
    $today = crm_today();
    $month = substr($today, 0, 7);
    foreach ($db['projects'] as $p) {
        if ($p['track'] !== 'retainer' || $p['status'] !== 'open' || crm_stage_no($p['stage']) < crm_stage_no('in_production') || crm_stage_no($p['stage']) >= crm_stage_no('offboarding')) {
            continue;
        }
        if (crm_auto_on('retainer_cycle')) {
            crm_cycle_start_in($db, $p['id'], $month, 'automation');
        }
        $k = crm_cycle_at($db, $p['id'], $month);
        $notice = (int) (crm_settings()['timing']['renewal_notice_days'] ?? 10);
        if ($k !== null && crm_auto_on('renewal') && $db['cycles'][$k]['status'] === 'open'
            && $today >= date('Y-m-d', (int) strtotime(crm_month_end($month) . " -$notice days"))) {
            $last = crm_retainer_last_month($p);
            if ($last === '' || $last > $month) { // no renewal for a retainer that ends this month
                crm_auto_draft($db, $db['projects'][crm_project_at($db, $p['id'])], 'renewal', "renewal:{$p['id']}:$month", 'renewal', '', crm_month_label($month) . " ends in $notice days or less.");
            }
        }
    }
}

/** Template values for the current month of a retainer. */
function crm_cycle_vars(array $db, array $p): array
{
    $c = crm_cycle_open($db, $p['id']) ?? crm_cycle_latest($db, $p['id']);
    if (!$c) {
        return ['month' => crm_month_label(substr(crm_today(), 0, 7)), 'next_month' => crm_month_label(crm_month_after(substr(crm_today(), 0, 7))),
            'delivered_summary' => '', 'next_plan' => '', 'visits_used' => '0', 'visits_per_month' => (string) ((int) ($p['visits_per_month'] ?? 0) ?: '')];
    }
    $kinds = ['video' => 0, 'image' => 0, 'file' => 0];
    foreach ($db['media'] ?? [] as $m) {
        foreach ((array) ($p['rounds'] ?? []) as $r) {
            if ($m['project_id'] === $p['id'] && (int) $m['round'] === (int) $r['n'] && ($r['cycle'] ?? '') === $c['month'] && $r['status'] !== 'draft') {
                $kinds[$m['kind']]++;
            }
        }
    }
    $parts = array_filter([$kinds['video'] ? $kinds['video'] . ' ' . ($kinds['video'] === 1 ? 'video' : 'videos') : '',
        $kinds['image'] ? $kinds['image'] . ' ' . ($kinds['image'] === 1 ? 'photo' : 'photos') : '', $kinds['file'] ? $kinds['file'] . ' ' . ($kinds['file'] === 1 ? 'file' : 'files') : '']);
    $next = $db['cycles'][crm_cycle_at($db, $p['id'], crm_month_after($c['month'])) ?? -1]['plan_text'] ?? '';
    return [
        'month' => crm_month_label($c['month']),
        'next_month' => crm_month_label(crm_month_after($c['month'])),
        'delivered_summary' => $parts ? crm_join(array_values($parts), 'and') : ($c['deliverables_due'] ? crm_join($c['deliverables_due'], 'and') : '[what we delivered]'),
        'next_plan' => trim(str_replace("\n", '; ', (string) $next)),
        'visits_used' => (string) crm_cycle_visits($p, $c['month']),
        'visits_per_month' => (string) ((int) ($p['visits_per_month'] ?? 0) ?: ''),
    ];
}
