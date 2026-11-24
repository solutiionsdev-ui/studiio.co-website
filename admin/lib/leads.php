<?php
/**
 * STUDIIO.CO admin — leads from the website contact form.
 *
 * Stored in the private data folder (outside the website on Hostinger), never in
 * the public GitHub repo. contact.php adds leads; the admin lists and updates them.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const LEAD_STATUSES = ['new', 'contacted', 'qualified', 'won', 'lost', 'archived'];

function leads_file(): string
{
    return data_path('leads.json');
}

const LEADS_PER_DAY = 200; // above this, new enquiries are still emailed but not stored (stops a flood filling the disk)

/** All leads, newest first. Throws if leads.json exists but can't be read, so a bad read is never saved over it. */
function leads_all(): array
{
    $file = leads_file();
    if (!is_file($file)) {
        return [];
    }
    $raw = @file_get_contents($file);
    $list = $raw === false ? null : json_decode($raw, true);
    if (!is_array($list)) {
        throw new ApiError('leads.json could not be read. Nothing was changed; restore it from leads.json.bak in the data folder.', 500);
    }
    $list = array_values(array_filter($list, 'is_array'));
    usort($list, static fn($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
    return $list;
}

function leads_write(array $list): void
{
    $file = leads_file();
    if (is_file($file)) {
        @copy($file, $file . '.bak'); // previous version, in case anything goes wrong
    }
    write_atomic($file, encode_json(array_values($list)));
}

/** Save a new enquiry. Returns its id. */
function leads_add(array $fields): string
{
    $lead = [
        'id'         => bin2hex(random_bytes(6)),
        'created_at' => gmdate('c'),
        'status'     => 'new',
        'name'       => (string) ($fields['name'] ?? ''),
        'company'    => (string) ($fields['company'] ?? ''),
        'email'      => (string) ($fields['email'] ?? ''),
        'service'    => (string) ($fields['service'] ?? ''),
        'service_label' => (string) ($fields['service_label'] ?? ''),
        'message'    => (string) ($fields['message'] ?? ''),
        'lang'       => (string) ($fields['lang'] ?? 'en'),
        'source'     => (string) ($fields['source'] ?? 'website'),
        'notes'      => '',
        'mail_sent'  => null,
        'updated_at' => gmdate('c'),
    ];
    with_lock('leads', function () use ($lead): void {
        $list = leads_all();
        $since = gmdate('c', time() - 86400);
        if (count(array_filter($list, static fn($l) => (string) ($l['created_at'] ?? '') > $since)) >= LEADS_PER_DAY) {
            throw new ApiError('Daily lead limit reached; not stored.', 429);
        }
        $list[] = $lead;
        leads_write($list);
    });
    return $lead['id'];
}

/** Update status / notes / mail_sent on one lead. Returns the updated lead. */
function leads_update(string $id, array $changes): array
{
    return with_lock('leads', function () use ($id, $changes): array {
        $list = leads_all();
        foreach ($list as $i => $lead) {
            if (($lead['id'] ?? '') !== $id) {
                continue;
            }
            if (array_key_exists('status', $changes)) {
                if (!in_array($changes['status'], LEAD_STATUSES, true)) {
                    throw new ApiError('Unknown status.');
                }
                $lead['status'] = $changes['status'];
            }
            if (array_key_exists('notes', $changes)) {
                $lead['notes'] = mb_substr(trim((string) $changes['notes']), 0, 10000);
            }
            if (array_key_exists('mail_sent', $changes)) {
                $lead['mail_sent'] = (bool) $changes['mail_sent'];
            }
            $lead['updated_at'] = gmdate('c');
            $list[$i] = $lead;
            leads_write($list);
            return $lead;
        }
        throw new ApiError('That lead no longer exists.', 404);
    });
}

function leads_delete(string $id): void
{
    with_lock('leads', function () use ($id): void {
        $list = leads_all();
        $kept = array_filter($list, static fn($l) => ($l['id'] ?? '') !== $id);
        if (count($kept) === count($list)) {
            throw new ApiError('That lead no longer exists.', 404);
        }
        leads_write($kept);
    });
}

/** CSV for Excel / Google Sheets (UTF-8 with BOM so Arabic opens correctly). */
function leads_csv(): string
{
    $out = fopen('php://temp', 'w+');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Date (UTC)', 'Status', 'Name', 'Company', 'Email', 'Service', 'Language', 'Message', 'Notes'], ',', '"', '');
    foreach (leads_all() as $l) {
        $row = [$l['created_at'] ?? '', $l['status'] ?? '', $l['name'] ?? '', $l['company'] ?? '', $l['email'] ?? '', ($l['service_label'] ?? '') ?: ($l['service'] ?? ''), $l['lang'] ?? '', $l['message'] ?? '', $l['notes'] ?? ''];
        // Stop spreadsheet apps from treating a cell as a formula.
        $row = array_map(static fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v, $row);
        fputcsv($out, $row, ',', '"', '');
    }
    rewind($out);
    return (string) stream_get_contents($out);
}
