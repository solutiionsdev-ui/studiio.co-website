<?php
/**
 * STUDIIO.CO admin — the client portal (onboarding spec, phase 5).
 *
 * One private link per project (a document of type "portal", client/?t=…) opens the client's
 * page: where the project is, coming dates, the checklist (they upload files or tick items),
 * their documents (accept the proposal, sign the agreement by typing their name), and the files
 * to review in rounds (watch, send one feedback message, or approve).
 *
 * Files live in the private data folder on the server (crm/media/<project>/ for deliverables,
 * crm/uploads/<project>/ for what clients send), never in the public site or the repo, and are
 * only served through the client's link or the signed-in admin.
 */
declare(strict_types=1);

require_once __DIR__ . '/crm-pay.php';

/** Deliverables Mario uploads: extension => [kind, content type]. */
const CRM_MEDIA_TYPES = [
    'mp4' => ['video', 'video/mp4'], 'm4v' => ['video', 'video/mp4'], 'mov' => ['video', 'video/mp4'], 'webm' => ['video', 'video/webm'],
    'jpg' => ['image', 'image/jpeg'], 'jpeg' => ['image', 'image/jpeg'], 'png' => ['image', 'image/png'], 'webp' => ['image', 'image/webp'],
    'gif' => ['image', 'image/gif'], 'pdf' => ['file', 'application/pdf'], 'zip' => ['file', 'application/zip'],
];
/** What clients may upload to their checklist (always handed to Mario as a download, never shown inline). */
const CRM_CLIENT_UPLOAD_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'svg', 'pdf', 'ai', 'eps', 'psd', 'zip', 'otf', 'ttf', 'woff', 'woff2',
    'txt', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'key', 'pages', 'csv', 'mp4', 'mov', 'mp3', 'wav'];

function crm_portal_label(string $key, array $vars = []): string
{
    $v = crm_tpl_json('labels')['portal'] ?? [];
    foreach (explode('.', $key) as $k) {
        $v = is_array($v) ? ($v[$k] ?? null) : null;
    }
    return is_string($v) ? crm_tpl_vars($v, $vars) : $key;
}

function crm_portal_doc(array $db, string $pid): ?array
{
    foreach ($db['documents'] as $d) {
        if ($d['project_id'] === $pid && $d['type'] === 'portal') {
            return $d;
        }
    }
    return null;
}

/** Make sure the project has a portal link. Returns the client id. */
function crm_portal_link(string $pid, string $by): string
{
    return crm_tx(static function (array &$db) use ($pid, $by): string {
        $p = $db['projects'][crm_project_at($db, $pid)];
        if (!crm_portal_doc($db, $pid)) {
            $db['documents'][] = [
                'id' => crm_id(), 'business' => CRM_BUSINESS, 'client_id' => $p['client_id'], 'project_id' => $pid, 'type' => 'portal',
                'token' => bin2hex(random_bytes(16)), 'file' => '', 'link' => '', 'version' => 1, 'status' => 'draft', 'sent_at' => '',
                'viewed_at' => '', 'completed_at' => '', 'note' => '', 'created_at' => gmdate('c'),
            ];
            crm_log($db, $p, $by, 'document', 'Client portal link made');
        }
        return $p['client_id'];
    });
}

/** Can the client download their files now? [bool open, string reason or "until" date]. */
function crm_portal_downloads(array $db, array $p): array
{
    $override = (string) ($p['downloads'] ?? '');
    $t = crm_settings()['terms'];
    $deliveredAt = '';
    foreach ($db['events'] as $e) {
        if ($e['project_id'] === $p['id'] && in_array($e['kind'], ['stage', 'override'], true) && str_contains($e['text'], ' to Delivered')) {
            $deliveredAt = substr($e['at'], 0, 10);
        }
    }
    $until = $deliveredAt !== '' ? date('Y-m-d', (int) strtotime($deliveredAt . ' +' . (int) $t['delivery_link_days'] . ' days')) : '';
    if ($override === 'closed') {
        return [false, 'closed'];
    }
    if ($until !== '' && $until < crm_today() && $override !== 'open') {
        return [false, 'expired'];
    }
    if ($override === 'open' || (crm_settings()['portal']['downloads'] ?? 'when_paid') === 'always') {
        return [true, $until];
    }
    $price = (float) ($p['price'] ?? 0);
    return $price > 0 && crm_paid($db, $p['id']) >= $price ? [true, $until] : [false, 'unpaid'];
}

/* --------------------------------------------------------------------------
   Deliverables (admin): chunked upload, rounds, share
   -------------------------------------------------------------------------- */

function crm_media_tmp(string $uploadId): string
{
    if (!preg_match('/^[a-f0-9]{16,32}$/', $uploadId)) {
        throw new ApiError('Bad upload id.');
    }
    return data_path("crm/tmp/$uploadId.part");
}

/** Append a piece of an upload (raw request body). Returns the size so far. */
function crm_media_chunk(string $uploadId, int $offset): int
{
    $path = crm_media_tmp($uploadId);
    if ($offset === 0) {
        foreach (glob(dirname($path) . '/*.part') ?: [] as $old) { // abandoned uploads
            if (filemtime($old) < time() - 86400) {
                @unlink($old);
            }
        }
    }
    clearstatcache(true, $path);
    $size = is_file($path) ? (int) filesize($path) : 0;
    if ($offset !== $size) {
        throw new ApiError('Upload out of step.', 409, ['size' => $size]);
    }
    $in = fopen('php://input', 'rb');
    $out = fopen($path, 'ab');
    if (!$in || !$out) {
        throw new ApiError('Could not save the upload.', 500);
    }
    $written = stream_copy_to_stream($in, $out, 16 * 1024 * 1024);
    fclose($in);
    fclose($out);
    $max = (int) (crm_settings()['portal']['max_file_mb'] ?? 2048) * 1024 * 1024;
    if ($written === false || $size + $written > $max) {
        @unlink($path);
        throw new ApiError($written === false ? 'Could not save the upload.' : 'That file is over ' . ($max >> 20) . ' MB.', 413);
    }
    return $size + $written;
}

/** A downsized JPEG for showing photos on phones (the original stays for download). */
function crm_media_preview(string $src, string $dest, string $ext): bool
{
    if (!function_exists('imagecreatefromstring') || $ext === 'gif') {
        return false;
    }
    $img = @imagecreatefromstring((string) file_get_contents($src));
    if (!$img) {
        return false;
    }
    if (in_array($ext, ['jpg', 'jpeg'], true) && function_exists('exif_read_data')) {
        $o = (int) (@exif_read_data($src)['Orientation'] ?? 1);
        $img = match ($o) { 3 => imagerotate($img, 180, 0), 6 => imagerotate($img, -90, 0), 8 => imagerotate($img, 90, 0), default => $img } ?: $img;
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min(1, 1800 / max($w, $h));
    $out = imagecreatetruecolor(max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    imagecopyresampled($out, $img, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
    return imagejpeg($out, $dest, 84);
}

/** Finish an upload: becomes a deliverable in the project's open round (a new round if the last one was shared). */
function crm_media_add(string $pid, string $uploadId, string $name, string $by): string
{
    $tmp = crm_media_tmp($uploadId);
    if (!is_file($tmp)) {
        throw new ApiError('The upload was not found. Try again.', 404);
    }
    $name = trim((string) preg_replace('/[^\p{L}\p{N} ._()\-]+/u', '_', mb_substr(basename($name), 0, 120)), ' .') ?: 'file';
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!isset(CRM_MEDIA_TYPES[$ext])) {
        @unlink($tmp);
        throw new ApiError('Upload videos (MP4, MOV, WebM), photos (JPG, PNG, WebP, GIF), PDF or ZIP.');
    }
    return crm_tx(static function (array &$db) use ($pid, $tmp, $name, $ext, $by): string {
        $i = crm_project_at($db, $pid);
        $p = $db['projects'][$i];
        $rounds = (array) ($p['rounds'] ?? []);
        $last = $rounds ? $rounds[count($rounds) - 1] : null;
        if (!$last || $last['status'] !== 'draft') {
            $rounds[] = ['n' => count($rounds) + 1, 'status' => 'draft', 'shared_at' => '', 'feedback' => [], 'approved' => null,
                // a retainer's round belongs to the month it is made in
                'cycle' => $p['track'] === 'retainer' ? (crm_cycle_open($db, $pid)['month'] ?? substr(crm_today(), 0, 7)) : ''];
        }
        $round = $rounds[count($rounds) - 1]['n'];
        $id = crm_id();
        $dir = "crm/media/{$p['id']}";
        $path = "$dir/$id.$ext";
        if (!@rename($tmp, data_path($path))) {
            throw new ApiError('Could not save the file.', 500);
        }
        $preview = '';
        if (CRM_MEDIA_TYPES[$ext][0] === 'image' && crm_media_preview(data_path($path), data_path("$dir/$id-preview.jpg"), $ext)) {
            $preview = "$dir/$id-preview.jpg";
        }
        $db['media'][] = [
            'id' => $id, 'business' => CRM_BUSINESS, 'client_id' => $p['client_id'], 'project_id' => $pid, 'round' => $round,
            'name' => $name, 'ext' => $ext, 'kind' => CRM_MEDIA_TYPES[$ext][0], 'size' => (int) filesize(data_path($path)),
            'path' => $path, 'preview' => $preview, 'uploaded_at' => gmdate('c'), 'by' => $by,
        ];
        $p['rounds'] = $rounds;
        $p['updated_at'] = gmdate('c');
        $db['projects'][$i] = $p;
        return $p['client_id'];
    });
}

/** Delete a deliverable (and its round, if that leaves an empty draft round). */
function crm_media_delete(string $mediaId, string $by): string
{
    return crm_tx(static function (array &$db) use ($mediaId, $by): string {
        $n = crm_index($db['media'], $mediaId);
        if ($n === null) {
            throw new ApiError('That file no longer exists.', 404);
        }
        $m = $db['media'][$n];
        foreach ([$m['path'], $m['preview']] as $f) {
            if ($f !== '') {
                @unlink(data_path($f));
            }
        }
        array_splice($db['media'], $n, 1);
        $i = crm_index($db['projects'], $m['project_id']);
        if ($i !== null) {
            $p = $db['projects'][$i];
            $left = array_filter($db['media'], static fn($x) => $x['project_id'] === $p['id'] && (int) $x['round'] === (int) $m['round']);
            $rounds = (array) ($p['rounds'] ?? []);
            $last = $rounds ? $rounds[count($rounds) - 1] : null;
            if (!$left && $last && (int) $last['n'] === (int) $m['round'] && $last['status'] === 'draft') {
                array_pop($rounds);
                $p['rounds'] = $rounds;
                $db['projects'][$i] = $p;
            }
            if ($last && $last['status'] !== 'draft') {
                crm_log($db, $p, $by, 'files', "Removed {$m['name']} from round {$m['round']}");
            }
        }
        return $m['client_id'];
    });
}

/** Share the open round with the client (or take it back while they haven't answered). */
function crm_round(string $pid, string $op, string $by): string
{
    $op = crm_pick($op, ['share', 'unshare'], 'action');
    return crm_tx(static function (array &$db) use ($pid, $op, $by): string {
        $i = crm_project_at($db, $pid);
        $p = $db['projects'][$i];
        $rounds = (array) ($p['rounds'] ?? []);
        if (!$rounds) {
            throw new ApiError('Upload some files first.');
        }
        $k = count($rounds) - 1;
        $r = $rounds[$k];
        if ($op === 'share') {
            if ($r['status'] !== 'draft') {
                throw new ApiError("Round {$r['n']} is already shared.");
            }
            if (!array_filter($db['media'], static fn($x) => $x['project_id'] === $pid && (int) $x['round'] === (int) $r['n'])) {
                throw new ApiError('This round has no files yet.');
            }
            $rounds[$k]['status'] = 'shared';
            $rounds[$k]['shared_at'] = gmdate('c');
            // Spec 5.8 / section 6: the feedback timer starts now.
            $rounds[$k]['feedback_due'] = crm_add_working_days(crm_today(), (int) crm_settings()['terms']['client_feedback_working_days']);
            $records = is_array($p['records'] ?? null) ? $p['records'] : [];
            if ((int) $r['n'] === 1 && empty($records['draft'])) {
                $records['draft'] = ['date' => crm_today(), 'note' => 'Shared in the client portal', 'link' => '', 'by' => $by, 'at' => gmdate('c')];
                $p['records'] = $records;
            }
            crm_log($db, $p, $by, 'files', "Round {$r['n']} shared with the client");
        } else {
            if ($r['status'] !== 'shared') {
                throw new ApiError('Only a shared round the client hasn’t answered can be taken back.');
            }
            $rounds[$k]['status'] = 'draft';
            $rounds[$k]['shared_at'] = '';
            crm_log($db, $p, $by, 'files', "Round {$r['n']} taken back from the client");
        }
        $p['rounds'] = $rounds;
        $p['updated_at'] = gmdate('c');
        $db['projects'][$i] = $p;
        return $p['client_id'];
    });
}

/** Admin: a file to stream or download. [path, content type, download name]. */
function crm_admin_file(string $kind, string $id): array
{
    $db = crm_load();
    $col = $kind === 'upload' ? 'uploads' : 'media';
    $n = crm_index($db[$col], $id);
    if ($n === null) {
        throw new ApiError('That file no longer exists.', 404);
    }
    $row = $db[$col][$n];
    $type = $col === 'media' ? CRM_MEDIA_TYPES[$row['ext']][1] : 'application/octet-stream';
    return [data_path($row['path']), $type, $row['name']];
}

/** Send a file with Range support (2 MB per response, so shared hosting never ties up a worker). */
function crm_send_file(string $path, string $type, string $name, bool $download): never
{
    if (!is_file($path)) {
        http_response_code(404);
        exit;
    }
    $size = (int) filesize($path);
    header('Content-Type: ' . $type);
    header('X-Content-Type-Options: nosniff');
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, max-age=3600');
    $safe = str_replace(['"', "\\", "\r", "\n"], '', $name);
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $safe . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    $start = 0;
    $end = $size - 1;
    if (preg_match('/^bytes=(\d*)-(\d*)$/', (string) ($_SERVER['HTTP_RANGE'] ?? ''), $r) && ($r[1] !== '' || $r[2] !== '')) {
        if ($r[1] === '') {
            $start = max(0, $size - (int) $r[2]);
        } else {
            $start = (int) $r[1];
            if ($r[2] !== '') {
                $end = min($end, (int) $r[2]);
            }
        }
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header("Content-Range: bytes */$size");
            exit;
        }
        $end = min($end, $start + 2 * 1024 * 1024 - 1);
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
    header('Content-Length: ' . ($end - $start + 1));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
        exit;
    }
    while (ob_get_level()) {
        ob_end_clean();
    }
    $fh = fopen($path, 'rb');
    fseek($fh, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($fh) && !connection_aborted()) {
        $chunk = (string) fread($fh, min(1 << 20, $left));
        if ($chunk === '') {
            break;
        }
        echo $chunk;
        flush();
        $left -= strlen($chunk);
    }
    fclose($fh);
    exit;
}

/** Space used by client files. (Free space isn't shown: on shared hosting PHP sees the whole server's disk, not your plan.) */
function crm_storage(array $db): array
{
    return ['used' => array_sum(array_column($db['media'], 'size')) + array_sum(array_column($db['uploads'], 'size'))];
}

/* --------------------------------------------------------------------------
   What the client does on their page
   -------------------------------------------------------------------------- */

/** The client sends files for a checklist item. Returns a notice key or an error message. */
function crm_portal_upload(string $pid, string $itemId, array $files): string
{
    $max = (int) (crm_settings()['portal']['client_upload_mb'] ?? 50);
    $list = [];
    foreach ((array) ($files['name'] ?? []) as $k => $n) {
        $list[] = ['name' => (string) $n, 'tmp' => (string) ($files['tmp_name'][$k] ?? ''), 'error' => (int) ($files['error'][$k] ?? 4), 'size' => (int) ($files['size'][$k] ?? 0)];
    }
    $list = array_values(array_filter($list, static fn($f) => $f['error'] !== UPLOAD_ERR_NO_FILE));
    if (!$list) {
        return '';
    }
    foreach ($list as $f) {
        if (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $f['size'] > $max * 1024 * 1024) {
            throw new ApiError(crm_portal_label('errors.too_big', ['mb' => (string) $max]), 413);
        }
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp'])) {
            throw new ApiError(crm_portal_label('errors.failed'));
        }
        if (!in_array(strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)), CRM_CLIENT_UPLOAD_EXT, true)) {
            throw new ApiError(crm_portal_label('errors.type'), 415);
        }
    }
    return crm_tx(static function (array &$db) use ($pid, $itemId, $list): string {
        $p = $db['projects'][crm_project_at($db, $pid)];
        $n = crm_index($db['checklist'], $itemId);
        if ($n === null || $db['checklist'][$n]['project_id'] !== $pid) {
            throw new ApiError(crm_portal_label('errors.failed'), 404);
        }
        if (count(array_filter($db['uploads'], static fn($u) => $u['item_id'] === $itemId)) + count($list) > 30) {
            throw new ApiError(crm_portal_label('errors.failed'));
        }
        foreach ($list as $f) {
            $id = crm_id();
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            $path = "crm/uploads/{$p['id']}/$id.$ext";
            if (!move_uploaded_file($f['tmp'], data_path($path))) {
                throw new ApiError(crm_portal_label('errors.failed'), 500);
            }
            $name = trim((string) preg_replace('/[^\p{L}\p{N} ._()\-]+/u', '_', mb_substr(basename($f['name']), 0, 120)), ' .') ?: "file.$ext";
            $db['uploads'][] = [
                'id' => $id, 'business' => CRM_BUSINESS, 'client_id' => $p['client_id'], 'project_id' => $pid, 'item_id' => $itemId,
                'name' => $name, 'ext' => $ext, 'size' => (int) filesize(data_path($path)), 'path' => $path, 'uploaded_at' => gmdate('c'),
            ];
        }
        $item = $db['checklist'][$n];
        if ($item['status'] === 'waiting') {
            $db['checklist'][$n]['status'] = 'received';
            $db['checklist'][$n]['received_at'] = crm_today();
        }
        crm_log($db, $p, 'client', 'checklist', 'Client sent ' . (count($list) === 1 ? 'a file' : count($list) . ' files') . " for “{$item['label']}”");
        crm_auto_checklist_done($db, $pid);
        return 'uploaded';
    });
}

/** The client ticks an item off, with an optional note or link. */
function crm_portal_item_done(string $pid, string $itemId, string $note): string
{
    $note = crm_text($note, 1000);
    return crm_tx(static function (array &$db) use ($pid, $itemId, $note): string {
        $p = $db['projects'][crm_project_at($db, $pid)];
        $n = crm_index($db['checklist'], $itemId);
        if ($n === null || $db['checklist'][$n]['project_id'] !== $pid) {
            throw new ApiError(crm_portal_label('errors.failed'), 404);
        }
        $item = $db['checklist'][$n];
        if ($note !== '') {
            $db['checklist'][$n]['note'] = mb_substr(trim($item['note'] . ($item['note'] !== '' ? "\n" : '') . 'From the client: ' . $note), 0, 1000);
        }
        if ($item['status'] === 'waiting') {
            $db['checklist'][$n]['status'] = 'received';
            $db['checklist'][$n]['received_at'] = crm_today();
        }
        crm_log($db, $p, 'client', 'checklist', "Client marked “{$item['label']}” as sent" . ($note !== '' ? ": $note" : ''));
        crm_auto_checklist_done($db, $pid);
        return 'item_done';
    });
}

/** The client's feedback on the shared round. */
function crm_portal_feedback(string $pid, int $round, string $text): string
{
    $text = mb_substr(trim(str_replace("\r\n", "\n", $text)), 0, 5000);
    if ($text === '') {
        throw new ApiError(crm_portal_label('errors.empty'));
    }
    return crm_tx(static function (array &$db) use ($pid, $round, $text): string {
        $i = crm_project_at($db, $pid);
        $p = $db['projects'][$i];
        $rounds = (array) ($p['rounds'] ?? []);
        $k = array_search($round, array_map('intval', array_column($rounds, 'n')), true);
        if ($k === false || $rounds[$k]['status'] !== 'shared') {
            throw new ApiError(crm_portal_label('errors.failed'), 409);
        }
        $rounds[$k]['status'] = 'changes';
        $rounds[$k]['feedback'][] = ['text' => $text, 'at' => gmdate('c')];
        $p['rounds'] = $rounds;
        $p['next_action'] = "Work on the round $round feedback";
        $p['blocked_by'] = 'studiio';
        $p['updated_at'] = gmdate('c');
        $db['projects'][$i] = $p;
        crm_log($db, $p, 'client', 'files', "Feedback on round $round: " . mb_substr($text, 0, 300));
        return 'feedback';
    });
}

/** The client approves the shared round in writing: completes Review and drafts the final invoice. */
function crm_portal_approve(string $pid, int $round, string $name): string
{
    $name = crm_text($name, 120);
    if (mb_strlen($name) < 2) {
        throw new ApiError(crm_portal_label('errors.name'));
    }
    return crm_tx(static function (array &$db) use ($pid, $round, $name): string {
        $i = crm_project_at($db, $pid);
        $p = $db['projects'][$i];
        $rounds = (array) ($p['rounds'] ?? []);
        $k = array_search($round, array_map('intval', array_column($rounds, 'n')), true);
        if ($k === false || $rounds[$k]['status'] !== 'shared') {
            throw new ApiError(crm_portal_label('errors.failed'), 409);
        }
        $rounds[$k]['status'] = 'approved';
        $rounds[$k]['approved'] = ['name' => $name, 'date' => crm_today(), 'at' => gmdate('c')];
        $records = is_array($p['records'] ?? null) ? $p['records'] : [];
        $records['approved'] = ['date' => crm_today(), 'note' => "Approved in the client portal by $name (round $round)", 'link' => '', 'by' => 'client', 'at' => gmdate('c')];
        $p['records'] = $records;
        $p['rounds'] = $rounds;
        $p['blocked_by'] = '';
        $p['updated_at'] = gmdate('c');
        $db['projects'][$i] = $p;
        crm_log($db, $p, 'client', 'record', "Client approved round $round in writing ($name)");
        crm_invoice_auto_final($db, $p, 'client');
        $ck = ($rounds[$k]['cycle'] ?? '') !== '' ? crm_cycle_at($db, $pid, $rounds[$k]['cycle']) : null;
        if ($ck !== null && $db['cycles'][$ck]['delivered_at'] === '') { // a retainer month is delivered once its files are approved
            $db['cycles'][$ck]['delivered_at'] = crm_today();
        }
        return 'approved';
    });
}

/** Accept the proposal or sign the agreement by typed name: stamps the PDF and completes that stage step. */
function crm_doc_sign(string $docId, string $name, bool $agree): string
{
    $name = crm_text($name, 120);
    if (mb_strlen($name) < 2) {
        throw new ApiError(crm_portal_label('errors.name'));
    }
    if (!$agree) {
        throw new ApiError(crm_portal_label('errors.agree'));
    }
    return crm_tx(static function (array &$db) use ($docId, $name): string {
        $k = crm_index($db['documents'], $docId);
        $d = $k === null ? null : $db['documents'][$k];
        if (!$d || !in_array($d['type'], ['proposal', 'agreement'], true) || ($d['file'] ?? '') === '' || in_array($d['status'], ['signed', 'completed'], true)) {
            throw new ApiError(crm_portal_label('errors.failed'), 409);
        }
        $L = crm_tpl_json('labels')['sign'] ?? [];
        $date = crm_today();
        $line = crm_tpl_vars((string) ($L[$d['type'] === 'agreement' ? 'signed' : 'accepted'] ?? 'Signed by {{name}} on {{date}}.'), ['name' => $name, 'date' => crm_long_date($date)]);
        $snapFile = data_path(preg_replace('/\.pdf$/', '.json', $d['file']));
        $snap = read_json_file($snapFile);
        if (!is_array($snap)) {
            throw new ApiError(crm_portal_label('errors.failed'), 500);
        }
        $snap['blocks'][] = ['t' => 'label', 'text' => (string) ($L[$d['type'] === 'agreement' ? 'sign_title' : 'accept_title'] ?? 'Signed')];
        $snap['blocks'][] = ['t' => 'p', 'runs' => [[$line, true]]];
        $snap['signature'] = ['name' => $name, 'date' => $date, 'at' => gmdate('c')];
        write_atomic($snapFile, encode_json($snap));
        write_atomic(data_path($d['file']), crm_doc_pdf($snap));
        $db['documents'][$k]['status'] = 'signed';
        $db['documents'][$k]['completed_at'] = $date;
        $db['documents'][$k]['signature'] = $snap['signature'];
        $i = crm_index($db['projects'], $d['project_id']);
        $p = $db['projects'][$i];
        $records = is_array($p['records'] ?? null) ? $p['records'] : [];
        $what = $d['type'] === 'agreement' ? 'agreement' : 'proposal';
        $records[$what] = ['date' => $date, 'note' => ($what === 'agreement' ? 'Signed' : 'Accepted') . " in the client portal by $name", 'link' => '', 'by' => 'client', 'at' => gmdate('c')];
        $p['records'] = $records;
        $p['updated_at'] = gmdate('c');
        $db['projects'][$i] = $p;
        crm_log($db, $p, 'client', 'record', ($what === 'agreement' ? 'Agreement signed' : 'Proposal accepted') . " by the client in the portal ($name)");
        $what === 'agreement' ? crm_auto_agreement_ready($db, $p['id']) : crm_auto_proposal_accepted($db, $p['id']);
        return $what === 'agreement' ? 'signed' : 'accepted';
    });
}

/* --------------------------------------------------------------------------
   The client's page
   -------------------------------------------------------------------------- */

function crm_size(int $bytes): string
{
    return $bytes >= 1 << 30 ? round($bytes / (1 << 30), 1) . ' GB' : ($bytes >= 1 << 20 ? round($bytes / (1 << 20), 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB');
}

/** The sign / accept form under a proposal or agreement page (empty when it doesn't apply). */
function crm_sign_form(array $doc, string $token): string
{
    if (!in_array($doc['type'], ['proposal', 'agreement'], true) || ($doc['file'] ?? '') === '') {
        return '';
    }
    $L = crm_tpl_json('labels')['sign'] ?? [];
    $isAgreement = $doc['type'] === 'agreement';
    if (in_array($doc['status'], ['signed', 'completed'], true)) {
        return '';
    }
    return '<section class="sign"><h2>' . crm_h((string) ($L[$isAgreement ? 'sign_title' : 'accept_title'] ?? '')) . '</h2>'
        . '<form method="post" action="?t=' . crm_h($token) . '"><input type="hidden" name="action" value="sign">'
        . '<label class="q"><span class="ql">' . crm_h((string) ($L['name'] ?? 'Your full name')) . '</span><input type="text" name="name" required maxlength="120" autocomplete="name"></label>'
        . '<label class="opt"><input type="checkbox" name="agree" value="1" required><span>' . crm_h((string) ($L[$isAgreement ? 'agree_sign' : 'agree_accept'] ?? '')) . '</span></label>'
        . '<button class="btn" type="submit">' . crm_h((string) ($L[$isAgreement ? 'sign_button' : 'accept_button'] ?? 'Sign')) . '</button></form></section>';
}

/** The portal page. $notice: a message to show at the top (after an action, or an error). */
function crm_portal_html(array $db, array $portal, array $p, array $c, string $notice = '', bool $bad = false): string
{
    $t = $portal['token'];
    $q = '?t=' . crm_h($t);
    $vars = crm_doc_vars($db, $p, $c);
    $L = static fn(string $k, array $v = []): string => crm_h(crm_portal_label($k, $v));
    $contact = array_values(array_filter([(string) (crm_settings()['contact']['email'] ?? ''), (string) (crm_settings()['contact']['phone'] ?? '')]));
    $html = '<h1>' . $L('hello', $vars) . '</h1><p class="intro">' . $L('intro', $vars) . '</p>';
    if ($notice !== '') {
        $html .= '<p class="' . ($bad ? 'error' : 'notice') . '" role="status">' . crm_h($notice) . '</p>';
    }

    // Where we are
    $cur = crm_stage_no($p['stage']);
    $html .= '<section class="block"><h2>' . $L('where') . '</h2><ol class="progress">';
    foreach (array_keys(CRM_STAGES) as $n => $key) {
        $cls = $p['status'] === 'done' || $n < $cur ? 'done' : ($n === $cur ? 'now' : '');
        $html .= '<li class="' . $cls . '"' . ($n === $cur ? ' aria-current="step"' : '') . '>' . $L("stages.$key") . '</li>';
    }
    $html .= '</ol></section>';

    // Coming up
    $dates = [];
    foreach (['kickoff_date', 'first_shoot', 'draft_date', 'delivery_date'] as $k) {
        if ($vars[$k] !== '') {
            $dates[] = '<div><dt>' . $L("dates.$k") . '</dt><dd>' . crm_h($vars[$k]) . '</dd></div>';
        }
    }
    if ($dates) {
        $html .= '<section class="block"><h2>' . $L('coming_up') . '</h2><dl class="dates">' . implode('', $dates) . '</dl></section>';
    }

    // Documents
    $docs = array_filter($db['documents'], static fn($d) => $d['project_id'] === $p['id'] && !in_array($d['type'], ['portal', 'kickoff_agenda'], true)
        && ($d['token'] ?? '') !== '' && ($d['type'] === 'intake' || ($d['file'] ?? '') !== '') && $d['status'] !== 'draft');
    if ($docs) {
        $html .= '<section class="block"><h2>' . $L('documents') . '</h2><ul class="docs">';
        foreach ($docs as $d) {
            $title = $d['type'] === 'invoice' ? crm_doc_title('invoice') . ' ' . $d['invoice_no'] . ' · ' . crm_usd((float) $d['amount']) : crm_doc_title($d['type']);
            $state = '';
            if ($d['type'] === 'agreement' && !in_array($d['status'], ['signed', 'completed'], true)) {
                $state = $L('to_sign');
            } elseif ($d['type'] === 'proposal' && !in_array($d['status'], ['signed', 'completed'], true)) {
                $state = $L('to_accept');
            }
            $html .= '<li><a href="?t=' . crm_h($d['token']) . '"><span>' . crm_h($title) . '</span>' . ($state !== '' ? '<em>' . $state . '</em>' : '') . '</a></li>';
        }
        $html .= '</ul></section>';
    }

    // This month (retainers): the content plan to approve, and the shoot visits.
    $cy = $p['track'] === 'retainer' ? (crm_cycle_open($db, $p['id']) ?? crm_cycle_latest($db, $p['id'])) : null;
    if ($cy) {
        $allow = (int) ($p['visits_per_month'] ?? 0);
        $used = crm_cycle_visits($p, $cy['month']);
        $html .= '<section class="block" id="month"><h2>' . $L('month_title', ['month' => crm_month_label($cy['month'])]) . '</h2>'
            . '<p class="hint">' . ($allow > 0 ? $L('visits', ['used' => (string) $used, 'total' => (string) $allow]) : $L('visits_no_limit', ['used' => (string) $used]))
            . (!crm_settings()['terms']['unused_visits_roll_over'] && $allow > 0 ? ' ' . $L('visits_note') : '') . '</p>';
        if (in_array($cy['plan_status'], ['shared', 'changes', 'approved'], true)) {
            $html .= '<div class="round"><h3>' . $L('plan') . '</h3><article class="doc plan">' . crm_blocks_html(crm_md_blocks($cy['plan_text'])) . '</article>';
            foreach ((array) $cy['plan_feedback'] as $f) {
                $html .= '<blockquote class="feedback"><p>' . nl2br(crm_h($f['text']), false) . '</p><cite>' . crm_h(crm_long_date(substr($f['at'], 0, 10))) . '</cite></blockquote>';
            }
            if ($cy['plan_status'] === 'approved' && $cy['plan_approved']) {
                $html .= '<p class="notice">' . $L('plan_approved', ['name' => $cy['plan_approved']['name'], 'date' => crm_long_date($cy['plan_approved']['date'])]) . '</p>';
            } elseif ($cy['plan_status'] === 'changes') {
                $html .= '<p class="notice">' . $L('plan_changes_sent') . '</p>';
            } elseif ($p['status'] === 'open') {
                $cid = crm_h($cy['id']);
                $html .= '<form method="post" action="' . $q . '#month" class="answer"><input type="hidden" name="action" value="plan"><input type="hidden" name="do" value="changes"><input type="hidden" name="cycle" value="' . $cid . '">'
                    . '<label class="q"><span class="ql">' . $L('plan_changes_title') . '</span><textarea name="text" rows="4" maxlength="5000" required></textarea></label>'
                    . '<button class="btn ghost" type="submit">' . $L('plan_changes_send') . '</button></form>'
                    . '<form method="post" action="' . $q . '#month" class="answer"><input type="hidden" name="action" value="plan"><input type="hidden" name="do" value="approve"><input type="hidden" name="cycle" value="' . $cid . '">'
                    . '<small>' . $L('plan_approve_hint') . '</small><input type="text" name="name" required maxlength="120" autocomplete="name" placeholder="' . $L('your_name') . '" aria-label="' . $L('your_name') . '">'
                    . '<button class="btn" type="submit">' . $L('plan_approve_button') . '</button></form>';
            }
            $html .= '</div>';
        } else {
            $html .= '<p class="hint">' . $L('plan_waiting') . '</p>';
        }
        $html .= '</section>';
    }

    // To review
    $rounds = array_values(array_filter((array) ($p['rounds'] ?? []), static fn($r) => $r['status'] !== 'draft'));
    $included = 1 + (int) ($p['revision_rounds'] ?? 2);
    [$canDownload, $dl] = crm_portal_downloads($db, $p);
    if ($rounds) {
        $html .= '<section class="block" id="review"><h2>' . $L('review') . '</h2>';
        $html .= '<p class="hint">' . ($canDownload ? ($dl !== '' ? $L('download_until', ['date' => crm_long_date($dl)]) : '') : ($dl === 'expired' ? $L('download_closed') : ($dl === 'unpaid' ? $L('download_all_note') : ''))) . '</p>';
        foreach (array_reverse($rounds) as $r) {
            $n = (int) $r['n'];
            $html .= '<div class="round"><h3>' . $L('round', ['n' => (string) $n]) . '</h3><p class="hint">'
                . ($n <= $included ? $L('round_info', ['n' => (string) $n, 'included' => (string) $included]) : $L('round_extra', ['n' => (string) $n, 'included' => (string) $included])) . '</p><div class="media">';
            foreach (array_filter($db['media'], static fn($m) => $m['project_id'] === $p['id'] && (int) $m['round'] === $n) as $m) {
                $src = $q . '&amp;f=' . crm_h($m['id']);
                $html .= '<figure>';
                if ($m['kind'] === 'video') {
                    $html .= '<video controls playsinline preload="metadata" src="' . $src . '"></video>';
                } elseif ($m['kind'] === 'image') {
                    $html .= '<img loading="lazy" alt="' . crm_h($m['name']) . '" src="' . $src . ($m['preview'] !== '' ? '&amp;v=preview' : '') . '">';
                } else {
                    $html .= '<div class="file-tile">' . crm_h(strtoupper($m['ext'])) . '</div>';
                }
                $html .= '<figcaption><span>' . crm_h($m['name']) . '</span>' . ($canDownload ? '<a href="' . $src . '&amp;dl=1">' . $L('download') . '</a>' : '') . '</figcaption></figure>';
            }
            $html .= '</div>';
            foreach ((array) ($r['feedback'] ?? []) as $f) {
                $html .= '<blockquote class="feedback"><p>' . nl2br(crm_h($f['text']), false) . '</p><cite>' . crm_h(crm_long_date(substr($f['at'], 0, 10))) . '</cite></blockquote>';
            }
            if ($r['status'] === 'approved' && $r['approved']) {
                $html .= '<p class="notice">' . $L('approved', ['name' => $r['approved']['name'], 'date' => crm_long_date($r['approved']['date'])]) . '</p>';
            } elseif ($r['status'] === 'changes') {
                $html .= '<p class="notice">' . $L('feedback_sent') . '</p>';
            } elseif ($r['status'] === 'shared' && $p['status'] === 'open') {
                $html .= '<form method="post" action="' . $q . '#review" class="answer"><input type="hidden" name="action" value="feedback"><input type="hidden" name="round" value="' . $n . '">'
                    . '<label class="q"><span class="ql">' . $L('feedback_title') . '</span><small>' . $L('feedback_hint') . '</small><textarea name="text" rows="5" maxlength="5000" required></textarea></label>'
                    . '<button class="btn ghost" type="submit">' . $L('feedback_send') . '</button></form>'
                    . '<form method="post" action="' . $q . '#review" class="answer"><input type="hidden" name="action" value="approve"><input type="hidden" name="round" value="' . $n . '">'
                    . '<p class="ql">' . $L('approve_title') . '</p><small>' . $L('approve_hint') . '</small>'
                    . '<input type="text" name="name" required maxlength="120" autocomplete="name" placeholder="' . $L('your_name') . '" aria-label="' . $L('your_name') . '">'
                    . '<button class="btn" type="submit">' . $L('approve_button') . '</button></form>';
            }
            $html .= '</div>';
        }
        $html .= '</section>';
    }
    // What we need from you
    $items = array_values(array_filter($db['checklist'], static fn($i) => $i['project_id'] === $p['id']));
    if ($items) {
        $waiting = array_filter($items, static fn($i) => $i['status'] === 'waiting');
        $html .= '<section class="block" id="need"><h2>' . $L('need') . '</h2><p class="hint">' . ($waiting ? $L('need_hint') : $L('need_done')) . '</p><ul class="items">';
        foreach ($items as $it) {
            $files = array_filter($db['uploads'], static fn($u) => $u['item_id'] === $it['id']);
            $html .= '<li class="item ' . crm_h($it['status']) . '"><div class="item-head"><span>' . crm_h($it['label']) . ($it['required'] ? '' : ' <em>' . $L('optional') . '</em>') . '</span>'
                . ($it['status'] !== 'waiting' ? '<span class="tick">' . ($it['status'] === 'received' ? $L('received') : $L('not_needed')) . '</span>' : '') . '</div>';
            if ($files) {
                $html .= '<p class="files">' . implode(' · ', array_map(static fn($u) => crm_h($u['name']), $files)) . '</p>';
            }
            if ($it['status'] !== 'not_needed' && $p['status'] === 'open') {
                $html .= ($it['status'] === 'received' ? '<details><summary>' . $L('add_more') . '</summary>' : '')
                    . '<form method="post" action="' . $q . '#need" enctype="multipart/form-data" class="item-form">'
                    . '<input type="hidden" name="action" value="item"><input type="hidden" name="item" value="' . crm_h($it['id']) . '">'
                    . '<input type="file" name="files[]" multiple aria-label="' . $L('upload') . '">'
                    . '<input type="text" name="note" maxlength="1000" placeholder="' . $L('note') . '" aria-label="' . $L('note') . '">'
                    . '<button class="btn small" type="submit">' . ($it['status'] === 'waiting' ? $L('done') : $L('upload')) . '</button></form>'
                    . ($it['status'] === 'received' ? '</details>' : '');
            }
            $html .= '</li>';
        }
        $html .= '</ul></section>';
    }

    return crm_page(crm_portal_label('band') . ' · ' . $vars['client_name'], crm_portal_label('band'), $html, $contact);
}

/** Notices shown after an action (portal and document pages). */
function crm_portal_notice(string $code): string
{
    $map = [
        'uploaded' => crm_portal_label('received'),
        'item_done' => crm_portal_label('received'),
        'feedback' => crm_portal_label('feedback_sent'),
        'approved' => crm_portal_label('approved', ['name' => '', 'date' => '']),
        'signed' => (string) (crm_tpl_json('labels')['sign']['thanks_signed'] ?? ''),
        'plan_approved' => crm_portal_label('approved_thanks'),
        'plan_changes' => crm_portal_label('plan_changes_sent'),
        'accepted' => (string) (crm_tpl_json('labels')['sign']['thanks_accepted'] ?? ''),
    ];
    return $code === 'approved' ? crm_portal_label('approved_thanks') : ($map[$code] ?? '');
}
