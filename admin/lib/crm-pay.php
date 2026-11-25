<?php
/**
 * STUDIIO.CO admin — invoices and payments for the Pipeline (onboarding spec, phase 3).
 *
 * - Invoices are documents of type "invoice" (several per project): deposit, final, monthly
 *   (retainers) or other, numbered INV-YYYY-NNN, with a PDF and a client link like the other
 *   documents. Amounts are in the project's currency, no tax.
 * - A payment can be recorded against an invoice; the invoice becomes paid when its payments
 *   cover it, and its PDF is redrawn to show what was paid.
 * - Spec 5.4: sending the agreement drafts the deposit invoice (a retainer's first month), and
 *   the client's written approval drafts the final invoice. Drafts are never sent by themselves.
 * - How to pay (bank details, Whish number…) is kept privately in the data folder, not in the repo.
 */
declare(strict_types=1);

require_once __DIR__ . '/crm-docs.php';

const CRM_INVOICE_KINDS = ['deposit', 'final', 'monthly', 'other'];

/** The invoices of a project, oldest first. */
function crm_invoices(array $db, string $pid): array
{
    return array_values(array_filter($db['documents'], static fn($d) => $d['project_id'] === $pid && $d['type'] === 'invoice'));
}

function crm_invoice_paid(array $db, string $invoiceId): float
{
    $sum = 0.0;
    foreach ($db['payments'] as $pay) {
        if (($pay['invoice_id'] ?? '') === $invoiceId) {
            $sum += (float) $pay['amount'];
        }
    }
    return round($sum, 2);
}

/** INV-2026-001, counting up within the year across all clients. */
function crm_invoice_next_no(array $db): string
{
    $prefix = (string) (crm_settings()['documents']['invoice_prefix'] ?? 'INV');
    $year = substr(crm_today(), 0, 4);
    $max = 0;
    foreach ($db['documents'] as $d) {
        if (preg_match('/^' . preg_quote($prefix, '/') . '-' . $year . '-(\d+)$/', (string) ($d['invoice_no'] ?? ''), $m)) {
            $max = max($max, (int) $m[1]);
        }
    }
    return sprintf('%s-%s-%03d', $prefix, $year, $max + 1);
}

/** The private "how to pay" text printed on invoices. */
function crm_pay_details(): string
{
    $file = data_path('crm/payment-details.txt');
    return is_file($file) ? trim((string) file_get_contents($file)) : '';
}

/** Save the "how to pay" text and redraw the invoices that are not paid yet, so they carry it. */
function crm_pay_details_save(mixed $text): string
{
    $text = mb_substr(trim(str_replace("\r\n", "\n", is_string($text) ? $text : '')), 0, 1000);
    crm_tx(static function (array &$db) use ($text): void {
        write_atomic(data_path('crm/payment-details.txt'), $text . "\n");
        foreach ($db['documents'] as $d) {
            if ($d['type'] === 'invoice' && $d['status'] !== 'paid') {
                crm_invoice_render($db, $d['id']);
            }
        }
    });
    return $text;
}

function crm_month_label(string $ym): string
{
    return preg_match('/^\d{4}-\d{2}$/', $ym) ? date('F Y', (int) strtotime($ym . '-01 12:00')) : $ym;
}

/**
 * Create an invoice inside a transaction. $kind: deposit, final, monthly or other.
 * $opts: amount and description (other), month YYYY-MM (monthly). Returns the new invoice.
 */
function crm_invoice_create(array &$db, array $p, string $kind, array $opts, string $by): array
{
    $kind = crm_pick($kind, CRM_INVOICE_KINDS, 'invoice type');
    $s = crm_settings();
    $L = crm_tpl_json('labels')['invoice'] ?? [];
    $price = (float) ($p['price'] ?? 0);
    $others = array_filter(crm_invoices($db, $p['id']), static fn($i) => $i['status'] !== 'void');
    $invoiced = array_sum(array_map(static fn($i) => (float) $i['amount'], $others));
    $month = '';
    switch ($kind) {
        case 'deposit':
            if ($price <= 0) {
                throw new ApiError('Set the project price in Project details first.');
            }
            $amount = round($price * $s['terms']['deposit_percent'] / 100, 2);
            $label = crm_tpl_vars((string) ($L['deposit'] ?? 'Deposit'), ['deposit_percent' => $s['terms']['deposit_percent'] . '%']);
            break;
        case 'final':
            if ($price <= 0) {
                throw new ApiError('Set the project price in Project details first.');
            }
            $amount = round($price - $invoiced, 2);
            if ($amount <= 0) {
                throw new ApiError('The whole price is already invoiced.');
            }
            $label = (string) ($L['final'] ?? 'Final payment');
            break;
        case 'monthly':
            if ($price <= 0) {
                throw new ApiError('Set the monthly price in Project details first.');
            }
            $month = (string) ($opts['month'] ?? '');
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
                throw new ApiError('Pick the month this invoice is for.');
            }
            foreach ($others as $i) {
                if (($i['month'] ?? '') === $month) {
                    throw new ApiError('There is already an invoice for ' . crm_month_label($month) . " ({$i['invoice_no']}).");
                }
            }
            $amount = $price;
            $label = crm_tpl_vars((string) ($L['monthly'] ?? '{{month}}'), ['month' => crm_month_label($month)]);
            break;
        default:
            $amount = crm_money($opts['amount'] ?? '');
            $label = crm_text($opts['description'] ?? '', 160);
            if ($amount <= 0 || $label === '') {
                throw new ApiError('Write what the invoice is for and its amount.');
            }
    }
    $issue = crm_today();
    $dueDays = (int) ($s['documents']['invoice_due_days'] ?? 0);
    $doc = [
        'id' => crm_id(), 'business' => CRM_BUSINESS, 'client_id' => $p['client_id'], 'project_id' => $p['id'], 'type' => 'invoice',
        'invoice_no' => crm_invoice_next_no($db), 'kind' => $kind, 'label' => $label, 'month' => $month, 'amount' => $amount,
        'currency' => $s['business']['currency'], 'issue_date' => $issue,
        'due_date' => $dueDays > 0 ? date('Y-m-d', (int) strtotime("$issue +$dueDays days")) : '',
        'note' => crm_text($opts['note'] ?? '', 500), 'token' => bin2hex(random_bytes(16)), 'file' => '', 'link' => '', 'version' => 1,
        'status' => 'draft', 'sent_at' => '', 'viewed_at' => '', 'paid_at' => '', 'completed_at' => '', 'created_at' => gmdate('c'),
    ];
    $db['documents'][] = $doc;
    crm_invoice_render($db, $doc['id']);
    crm_log($db, $p, $by, 'invoice', "Invoice {$doc['invoice_no']} made: $label, " . crm_usd($amount));
    return $doc;
}

/** Redraw an invoice's PDF and page (after it is made, and when payments against it change). */
function crm_invoice_render(array &$db, string $invoiceId): void
{
    $k = crm_index($db['documents'], $invoiceId);
    $inv = $db['documents'][$k];
    $p = $db['projects'][crm_index($db['projects'], $inv['project_id'])];
    $c = $db['clients'][crm_index($db['clients'], $p['client_id'])];
    $L = crm_tpl_json('labels')['invoice'] ?? [];
    $vars = crm_doc_vars($db, $p, $c);
    $paid = crm_invoice_paid($db, $invoiceId);
    $amount = (float) $inv['amount'];
    $meta = [
        [(string) ($L['number'] ?? 'Invoice no.'), $inv['invoice_no']],
        [crm_label('issue_date'), crm_long_date($inv['issue_date'])],
        [(string) ($L['due'] ?? 'Due'), $inv['due_date'] !== '' ? crm_long_date($inv['due_date']) : (string) ($L['on_receipt'] ?? 'On receipt')],
    ];
    if ($paid >= $amount) {
        $meta[] = [(string) ($L['status'] ?? 'Status'), (string) ($L['paid_status'] ?? 'Paid')];
    }
    $extra = [];
    if ($paid > 0) {
        $extra[] = [(string) ($L['paid'] ?? 'Paid'), '-' . crm_usd(min($paid, $amount))];
        $extra[] = [(string) ($L['amount_due'] ?? 'Amount due'), crm_usd(max(0, $amount - $paid))];
    }
    $how = crm_tpl_vars((string) ($L['methods'] ?? ''), $vars);
    $details = crm_pay_details();
    $blocks = [
        ['t' => 'quote', 'title' => $inv['label'], 'details' => [$p['title']], 'amount' => crm_usd($amount), 'total' => crm_usd($amount),
            'labels' => [crm_label('description'), crm_label('amount'), crm_label('total')], 'extra' => $extra],
        ['t' => 'label', 'text' => (string) ($L['how_to_pay'] ?? 'How to pay')],
        ['t' => 'p', 'runs' => crm_runs(trim($how . ($details !== '' ? "\n" . $details : '')))],
    ];
    if (($inv['note'] ?? '') !== '') {
        $blocks[] = ['t' => 'label', 'text' => crm_label('notes')];
        $blocks[] = ['t' => 'p', 'runs' => crm_runs($inv['note'])];
    }
    $blocks[] = ['t' => 'p', 'runs' => [["\n" . (string) ($L['thanks'] ?? 'Thank you.'), false]]];
    $ct = crm_settings()['contact'] ?? [];
    $snap = [
        'type' => 'invoice', 'title' => crm_doc_title('invoice'), 'prepared_for' => $vars['client_name'], 'meta' => $meta, 'blocks' => $blocks,
        'contact' => array_values(array_filter([(string) ($ct['email'] ?? ''), (string) ($ct['phone'] ?? '')])),
        'labels' => ['page' => (string) (crm_tpl_json('labels')['page'] ?? '{{n}} / {{total}}'), 'download' => crm_label('download_pdf'),
            'prepared_for' => (string) ($L['billed_to'] ?? 'Billed to')],
        'version' => 1, 'generated_at' => gmdate('c'),
    ];
    $base = "crm/files/{$inv['id']}-v1";
    write_atomic(data_path("$base.json"), encode_json($snap));
    write_atomic(data_path("$base.pdf"), crm_doc_pdf($snap));
    $db['documents'][$k]['file'] = "$base.pdf";
    $db['documents'][$k]['generated_at'] = gmdate('c');
}

/** After payments change: paid when covered (back to sent/draft when not), and redraw. */
function crm_invoice_sync(array &$db, string $invoiceId): void
{
    $k = crm_index($db['documents'], $invoiceId);
    if ($k === null || $db['documents'][$k]['type'] !== 'invoice') {
        return;
    }
    $inv = $db['documents'][$k];
    $paid = crm_invoice_paid($db, $invoiceId);
    if ($paid >= (float) $inv['amount'] && $inv['status'] !== 'paid') {
        $dates = array_column(array_filter($db['payments'], static fn($x) => ($x['invoice_id'] ?? '') === $invoiceId), 'date');
        $db['documents'][$k]['status'] = 'paid';
        $db['documents'][$k]['paid_at'] = $dates ? max($dates) : crm_today();
    } elseif ($paid < (float) $inv['amount'] && $inv['status'] === 'paid') {
        $db['documents'][$k]['status'] = $inv['viewed_at'] !== '' ? 'viewed' : ($inv['sent_at'] !== '' ? 'sent' : 'draft');
        $db['documents'][$k]['paid_at'] = '';
    }
    crm_invoice_render($db, $invoiceId);
}

/** Make an invoice by hand. Returns the client id. */
function crm_invoice_make(string $pid, array $in, string $by): string
{
    return crm_tx(static function (array &$db) use ($pid, $in, $by): string {
        $p = $db['projects'][crm_project_at($db, $pid)];
        crm_invoice_create($db, $p, (string) ($in['kind'] ?? ''), $in, $by);
        return $p['client_id'];
    });
}

/** Delete an invoice that has no payments against it. Returns the client id. */
function crm_invoice_delete(string $invoiceId, string $by): string
{
    return crm_tx(static function (array &$db) use ($invoiceId, $by): string {
        $k = crm_index($db['documents'], $invoiceId);
        if ($k === null || $db['documents'][$k]['type'] !== 'invoice') {
            throw new ApiError('That invoice no longer exists.', 404);
        }
        $inv = $db['documents'][$k];
        if (crm_invoice_paid($db, $invoiceId) > 0) {
            throw new ApiError('This invoice has payments recorded against it. Remove those first.');
        }
        array_splice($db['documents'], $k, 1);
        $i = crm_index($db['projects'], $inv['project_id']);
        if ($i !== null) {
            crm_log($db, $db['projects'][$i], $by, 'invoice', "Invoice {$inv['invoice_no']} deleted");
        }
        return $inv['client_id'];
    });
}

/**
 * Spec 5.4: the agreement went out → draft the deposit invoice (a retainer's first month),
 * unless the project already has one or has no price yet.
 */
function crm_invoice_auto_agreement(array &$db, array $p, string $by): void
{
    if ((float) ($p['price'] ?? 0) <= 0) {
        return;
    }
    $existing = array_filter(crm_invoices($db, $p['id']), static fn($i) => in_array($i['kind'] ?? '', ['deposit', 'monthly'], true));
    if ($existing) {
        return;
    }
    try {
        if ($p['track'] === 'retainer') {
            $start = (string) ($p['start_date'] ?? '');
            crm_invoice_create($db, $p, 'monthly', ['month' => substr($start !== '' ? $start : crm_today(), 0, 7)], $by);
        } else {
            crm_invoice_create($db, $p, 'deposit', [], $by);
        }
    } catch (ApiError $e) {
        // nothing to draft: the admin can still make it by hand
    }
}

/** Spec 5.4: the client approved in writing → draft the final invoice for what is left. */
function crm_invoice_auto_final(array &$db, array $p, string $by): void
{
    if ($p['track'] === 'retainer' || (float) ($p['price'] ?? 0) <= 0) {
        return;
    }
    foreach (crm_invoices($db, $p['id']) as $i) {
        if (($i['kind'] ?? '') === 'final') {
            return;
        }
    }
    try {
        crm_invoice_create($db, $p, 'final', [], $by);
    } catch (ApiError $e) {
        // the whole price is already invoiced
    }
}

/* --------------------------------------------------------------------------
   Payments page
   -------------------------------------------------------------------------- */

/** Everything the Payments page shows. */
function crm_payments_view(): array
{
    $db = crm_load();
    $clients = array_column($db['clients'], null, 'id');
    $projects = array_column($db['projects'], null, 'id');
    $name = static fn(string $cid): string => ($clients[$cid]['business_name'] ?? '') ?: ($clients[$cid]['contact_name'] ?? '?');
    $docs = array_column($db['documents'], null, 'id');
    $today = crm_today();

    $payments = array_map(static fn($x) => [
        'id' => $x['id'], 'date' => $x['date'], 'amount' => (float) $x['amount'], 'method' => $x['method'], 'kind' => $x['kind'],
        'note' => $x['note'], 'client_id' => $x['client_id'], 'client' => $name($x['client_id']), 'project' => $projects[$x['project_id']]['title'] ?? '',
        'project_id' => $x['project_id'], 'invoice_no' => $docs[$x['invoice_id'] ?? '']['invoice_no'] ?? '',
    ], $db['payments']);
    usort($payments, static fn($a, $b) => strcmp($b['date'], $a['date']));

    $outstanding = [];
    $invoicedBy = [];
    foreach ($db['documents'] as $d) {
        if ($d['type'] !== 'invoice' || $d['status'] === 'void') {
            continue;
        }
        $invoicedBy[$d['project_id']] = ($invoicedBy[$d['project_id']] ?? 0) + (float) $d['amount'];
        $paid = crm_invoice_paid($db, $d['id']);
        if ($paid < (float) $d['amount']) {
            $outstanding[] = [
                'id' => $d['id'], 'invoice_no' => $d['invoice_no'], 'label' => $d['label'], 'amount' => (float) $d['amount'], 'paid' => $paid,
                'due' => (float) $d['amount'] - $paid, 'issue_date' => $d['issue_date'], 'due_date' => $d['due_date'], 'status' => $d['status'],
                'overdue' => $d['status'] !== 'draft' && ($d['due_date'] !== '' ? $d['due_date'] : $d['issue_date']) < $today,
                'client_id' => $d['client_id'], 'client' => $name($d['client_id']), 'project_id' => $d['project_id'],
                'project' => $projects[$d['project_id']]['title'] ?? '',
            ];
        }
    }
    usort($outstanding, static fn($a, $b) => strcmp($a['issue_date'], $b['issue_date']));

    $uninvoiced = [];
    foreach ($db['projects'] as $p) {
        $price = (float) ($p['price'] ?? 0);
        $inv = $invoicedBy[$p['id']] ?? 0.0;
        if ($p['status'] === 'open' && $p['track'] !== 'retainer' && $price > $inv) {
            $uninvoiced[] = ['client_id' => $p['client_id'], 'client' => $name($p['client_id']), 'project_id' => $p['id'], 'project' => $p['title'],
                'stage' => CRM_STAGES[$p['stage']] ?? $p['stage'], 'price' => $price, 'invoiced' => $inv, 'left' => $price - $inv];
        }
    }

    // Last 12 months: received (payments) and invoiced (by issue date).
    $months = [];
    $start = new DateTimeImmutable(substr($today, 0, 7) . '-01');
    for ($n = 11; $n >= 0; $n--) {
        $m = $start->modify("-$n months")->format('Y-m');
        $months[$m] = ['month' => $m, 'label' => crm_month_label($m), 'received' => 0.0, 'invoiced' => 0.0, 'count' => 0];
    }
    foreach ($db['payments'] as $x) {
        $m = substr($x['date'], 0, 7);
        if (isset($months[$m])) {
            $months[$m]['received'] += (float) $x['amount'];
            $months[$m]['count']++;
        }
    }
    foreach ($db['documents'] as $d) {
        $m = substr((string) ($d['issue_date'] ?? ''), 0, 7);
        if ($d['type'] === 'invoice' && $d['status'] !== 'void' && isset($months[$m])) {
            $months[$m]['invoiced'] += (float) $d['amount'];
        }
    }
    // Start at the first month with any activity (at least the last three months).
    $months = array_values($months);
    while (count($months) > 3 && $months[0]['received'] == 0 && $months[0]['invoiced'] == 0) {
        array_shift($months);
    }
    $year = substr($today, 0, 4);
    return [
        'today' => $today,
        'currency' => crm_settings()['business']['currency'],
        'months' => $months,
        'payments' => $payments,
        'outstanding' => $outstanding,
        'uninvoiced' => $uninvoiced,
        'totals' => [
            'thisMonth' => array_sum(array_map(static fn($x) => str_starts_with($x['date'], substr($today, 0, 7)) ? (float) $x['amount'] : 0, $db['payments'])),
            'thisYear' => array_sum(array_map(static fn($x) => str_starts_with($x['date'], $year) ? (float) $x['amount'] : 0, $db['payments'])),
            'outstanding' => array_sum(array_column($outstanding, 'due')),
            'overdue' => count(array_filter($outstanding, static fn($o) => $o['overdue'])),
        ],
        'payDetails' => crm_pay_details(),
    ];
}

/** Payments as CSV for Excel / Google Sheets (UTF-8 with BOM). */
function crm_payments_csv(): string
{
    $v = crm_payments_view();
    $out = fopen('php://temp', 'w+');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Date', 'Client', 'Project', 'Amount (' . $v['currency'] . ')', 'Method', 'For', 'Invoice', 'Note'], ',', '"', '');
    foreach ($v['payments'] as $x) {
        $row = [$x['date'], $x['client'], $x['project'], number_format($x['amount'], 2, '.', ''), $x['method'], CRM_PAYMENT_KINDS[$x['kind']] ?? $x['kind'], $x['invoice_no'], $x['note']];
        $row = array_map(static fn($c) => preg_match('/^[=+\-@\t\r]/', (string) $c) ? "'" . $c : (string) $c, $row);
        fputcsv($out, $row, ',', '"', '');
    }
    rewind($out);
    return (string) stream_get_contents($out);
}
