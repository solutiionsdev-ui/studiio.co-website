<?php
/**
 * STUDIIO.CO admin — Reels editor: client reels made with Reels Montage PRO on this Mac.
 *
 * The engine (~/reels-montage-pro) does the real work: new_project.sh prepares a video,
 * engine/preview.py and engine/build.py render it. This file only drives it:
 *   - every reel is an engine project in <package>/projects/<slug>/ (the engine's own layout);
 *   - its plan is beats.json in that folder: one effect per spoken phrase ("beat"), edited by
 *     the panel and by Claude Code alike. Before a preview or render it is copied into the
 *     project's overlay.html as the STUDIIO BEATS block (lib/reels-bridge.js);
 *   - admin-only data (client, status, chat, job logs) lives in the private data folder.
 *
 * Nothing from the package is copied into this repository: it is a paid, personal-use package,
 * and this repository is public. The editor only works on the Mac that has the package.
 */
declare(strict_types=1);

require_once __DIR__ . '/claude.php';
require_once __DIR__ . '/leads.php';

const REEL_STATUSES = ['editing', 'review', 'approved', 'delivered'];

const REEL_GROUPS = [
    'plate' => 'Under your face',
    'type'  => 'Typography',
    'back'  => 'Behind you',
    'full'  => 'Full screen',
    'end'   => 'Ending',
];

/**
 * Effects the editor can place on a beat: name => [group, fields].
 * Fields: s = text, i = list (one per line), n = number, p = photos (value = how many are needed).
 * The renderer for each one is in lib/reels-bridge.js; keep the two lists in step.
 */
const REEL_EFFECTS = [
    'askBar'       => ['plate', ['s' => 'Text that types (default: the phrase)']],
    'allowBar'     => ['plate', ['s' => 'Command in the bar (default: the phrase)']],
    'notifyStack'  => ['plate', ['i' => 'Messages, one per line']],
    'toggleList'   => ['plate', ['i' => 'Toggles, one per line']],
    'checklist'    => ['plate', ['i' => 'Steps, one per line']],
    'statCard'     => ['plate', ['s' => 'Card label', 'i' => 'Rows, one per line']],
    'statLines'    => ['plate', ['s' => 'Title', 'i' => 'Facts, one per line, number first: 73% time saved']],
    'numPair'      => ['plate', ['i' => 'Two lines: label: value']],
    'sliderBar'    => ['plate', ['s' => 'Label', 'n' => 'Fills to, e.g. 97%']],
    'scaleBar'     => ['plate', ['s' => 'Label', 'n' => 'Score from 0 to 100']],
    'stopwatch'    => ['plate', ['s' => 'Label', 'n' => 'Counts to (seconds)']],
    'segmented'    => ['plate', ['i' => '2 or 3 options, one per line']],
    'duoCircles'   => ['plate', ['i' => 'Two lines: Problem: … and Solution: …']],
    'liquidChart'  => ['plate', ['s' => 'Label', 'n' => 'Grows to']],
    'chartCard'    => ['plate', ['s' => 'Title']],
    'scoreCard'    => ['plate', ['s' => 'Title', 'n' => 'Score']],
    'layers'       => ['plate', ['i' => 'Layers, one per line']],
    'circles'      => ['plate', ['i' => 'Steps, one per line']],
    'timeline'     => ['plate', ['i' => 'Stages, one per line: DAY 1: idea']],
    'cardStack3D'  => ['plate', ['i' => 'Cards, one per line']],
    'routeSteps'   => ['plate', ['i' => 'Steps, one per line: Raw clip: one take', 'n' => 'Big number', 's' => 'Unit, e.g. minutes']],
    'tags'         => ['plate', ['i' => 'Tags, one per line']],
    'labelPlate'   => ['plate', ['s' => 'Small label, e.g. comment', 'i' => 'Line under it']],
    'folder'       => ['plate', ['s' => 'Folder label']],
    'loader'       => ['plate', ['s' => 'Loader text']],
    'chatPill'     => ['plate', ['s' => 'Comment or quote']],
    'annotPin'     => ['plate', ['s' => 'Note']],
    'pathRide'     => ['plate', ['s' => 'Label', 'p' => 1]],
    'phone'        => ['plate', ['p' => 1]],
    'phoneCompare' => ['plate', ['p' => 2]],
    'title'        => ['type', ['s' => 'Small word above the big word']],
    'bracketWord'  => ['type', ['s' => 'Small word before the big word']],
    'ghostWord'    => ['type', ['s' => 'Word (default: the big word)']],
    'noiseWord'    => ['type', ['s' => 'Word (default: the big word)']],
    'focusWord'    => ['type', ['s' => 'Word (default: the big word)']],
    'ribbon'       => ['type', ['s' => 'Ribbon text (default: the big word)']],
    'ctaWord'      => ['type', ['s' => 'Word (default: your code word)']],
    'typeLine'     => ['type', ['s' => 'Phrase that types (default: the phrase)']],
    'behindWord'   => ['back', ['s' => 'Word (default: the big word)']],
    'walls'        => ['back', ['s' => 'Word (default: the big word)']],
    'wordSwarm'    => ['back', ['s' => 'Word (default: the big word)']],
    'wordRing'     => ['back', ['i' => 'Words, one per line']],
    'lineBehind'   => ['back', ['s' => 'Label']],
    'ring'         => ['back', ['s' => 'Label']],
    'collage'      => ['back', ['p' => 3]],
    'orbit'        => ['back', ['p' => 3]],
    'duoCards'     => ['back', ['i' => 'Two labels: before: random posts', 'p' => 2]],
    'titleWall'    => ['back', ['s' => 'Line 1 (default: the big word)', 'i' => 'Line 2', 'p' => 0]],
    'uiWall'       => ['back', ['s' => 'Title', 'i' => 'Label', 'p' => 3]],
    'split'        => ['back', ['s' => 'Title (default: the big word)', 'i' => 'Bullets, one per line']],
    'sphere'       => ['back', []],
    'network'      => ['back', []],
    'shutter'      => ['full', ['p' => 2]],
    'carouselWide' => ['full', ['s' => 'Title', 'i' => 'Captions, one per line', 'p' => 3]],
    'flowNodes'    => ['full', ['i' => 'Steps (3 or 4), one per line', 's' => 'Input label']],
    'phaseCards'   => ['full', ['i' => 'Phases, one per line: Film: phone, any room']],
    'halfBlur'     => ['full', ['i' => 'Two lines, number first: 20% what people see']],
    'mapRoute'     => ['full', ['i' => 'Two lines: from, to', 's' => 'Time, e.g. 2 min']],
    'cta'          => ['end', []],
];

/** Video effects (engine/vfx.py changes the picture itself): kind => [label, options]. */
const REEL_VFX = [
    'bg'      => ['Background swap', ['color']],
    'outline' => ['White outline on colour', ['color']],
    'clones'  => ['Three of you', []],
    'echo'    => ['Echo trail on a move', []],
    'window'  => ['You in a small window', ['color', 'caption']],
    'grid'    => ['Grid of thumbnails', ['caption']],
    'zoom'    => ['Step zoom into the face', []],
];

/* --------------------------------------------------------------------------
   Package, paths, access
   -------------------------------------------------------------------------- */

/** The Reels Montage PRO folder on this Mac, or '' when the editor isn't available (e.g. on Hostinger). */
function reels_root(): string
{
    static $root = null;
    if ($root !== null) {
        return $root;
    }
    $dir = (string) (config()['reels']['dir'] ?? '');
    if ($dir === '' && in_array(PHP_SAPI, ['cli', 'cli-server'], true)) {
        $home = getenv('HOME') ?: '';
        $dir = $home !== '' ? $home . '/reels-montage-pro' : '';
    }
    $real = $dir !== '' ? realpath($dir) : false;
    $root = ($real && is_file("$real/new_project.sh") && is_dir("$real/engine") && is_dir("$real/bank")) ? $real : '';
    return $root;
}

function reels_enabled(): bool
{
    return config()['content_backend'] !== 'github' && reels_root() !== '';
}

function reels_require(): string
{
    if (!reels_enabled()) {
        throw new ApiError('The reel editor runs on the Mac that has Reels Montage PRO (~/reels-montage-pro). Open the admin there: http://localhost:4380/admin/', 404);
    }
    return reels_root();
}

function reels_python(): string
{
    $py = (string) (config()['reels']['python'] ?? '');
    return $py !== '' ? $py : reels_root() . '/.venv/bin/python';
}

function reel_slug_check(string $slug): string
{
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $slug)) {
        throw new ApiError('Unknown reel.', 404);
    }
    return $slug;
}

/** The engine project folder (must exist unless $mayBeMissing). */
function reel_dir(string $slug, bool $mayBeMissing = false): string
{
    $dir = reels_require() . '/projects/' . reel_slug_check($slug);
    if (!$mayBeMissing && !is_dir($dir)) {
        throw new ApiError('Unknown reel.', 404);
    }
    return $dir;
}

/** The reel's private admin folder (client, status, chat, jobs). */
function reel_data(string $slug, string $rel = ''): string
{
    return data_path('reels/' . reel_slug_check($slug) . '/' . $rel);
}

/**
 * Short-lived key for reels-file.php. It sits in the URL path so the sandboxed preview
 * frames (which send no cookies) can load the overlay, the bank and the project's files.
 */
function reels_token(): string
{
    $exp = (intdiv(time(), 3600) + 13) * 3600; // valid 12–13 hours, stable within the hour (lets the browser cache files)
    return $exp . '.' . substr(hash_hmac('sha256', "reels|$exp", reels_secret()), 0, 32);
}

function reels_token_ok(string $token): bool
{
    if (!preg_match('/^(\d{10})\.([a-f0-9]{32})$/', $token, $m) || (int) $m[1] < time()) {
        return false;
    }
    return hash_equals(substr(hash_hmac('sha256', "reels|{$m[1]}", reels_secret()), 0, 32), $m[2]);
}

function reels_secret(): string
{
    $file = data_path('reels/.key');
    $key = is_file($file) ? trim((string) file_get_contents($file)) : '';
    if (strlen($key) < 64) {
        $key = bin2hex(random_bytes(32));
        write_atomic($file, $key);
        @chmod($file, 0600);
    }
    return $key;
}

/* --------------------------------------------------------------------------
   Reels list and metadata
   -------------------------------------------------------------------------- */

function reel_meta(string $slug): array
{
    $m = read_json_file(reel_data($slug, 'reel.json'), []);
    $m = is_array($m) ? $m : [];
    return array_merge([
        'slug'       => $slug,
        'title'      => $slug,
        'client'     => ['id' => '', 'name' => ''],
        'status'     => 'editing',
        'created_at' => '',
        'updated_at' => '',
        'source'     => null,
    ], $m, ['slug' => $slug]);
}

function reel_meta_save(string $slug, array $meta): array
{
    $meta['updated_at'] = gmdate('c');
    write_atomic(reel_data($slug, 'reel.json'), encode_json($meta));
    return $meta;
}

function reel_meta_update(string $slug, array $in): array
{
    reel_dir($slug, true);
    return with_lock("reel-$slug", static function () use ($slug, $in) {
        $meta = reel_meta($slug);
        if (array_key_exists('title', $in)) {
            $title = trim((string) $in['title']);
            if ($title === '' || mb_strlen($title) > 80) {
                throw new ApiError('Give the reel a title (up to 80 characters).');
            }
            $meta['title'] = $title;
        }
        if (array_key_exists('status', $in)) {
            if (!in_array($in['status'], REEL_STATUSES, true)) {
                throw new ApiError('Unknown status.');
            }
            $meta['status'] = $in['status'];
        }
        if (array_key_exists('client', $in)) {
            $meta['client'] = reel_client_clean($in['client']);
        }
        return reel_meta_save($slug, $meta);
    });
}

function reel_client_clean(mixed $c): array
{
    $c = is_array($c) ? $c : [];
    $id = is_string($c['id'] ?? null) && preg_match('/^[A-Za-z0-9-]{1,64}$/', $c['id']) ? $c['id'] : '';
    $name = trim(mb_substr(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($c['name'] ?? '')) ?? '', 0, 120));
    return ['id' => $id, 'name' => $name];
}

/** Every engine project (also ones made with new_project.sh in Terminal), newest first. */
function reels_list(): array
{
    $root = reels_require();
    $out = [];
    foreach (glob("$root/projects/*", GLOB_ONLYDIR) ?: [] as $dir) {
        $slug = basename($dir);
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $slug)) {
            continue; // the engine allows any name; the editor only manages safe ones
        }
        $out[] = reel_summary($slug);
    }
    // Reels whose project folder isn't there yet (still uploading or preparing).
    foreach (glob(data_path('reels/') . '*/reel.json') ?: [] as $file) {
        $slug = basename(dirname($file));
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $slug) && !is_dir("$root/projects/$slug")) {
            $out[] = reel_summary($slug);
        }
    }
    usort($out, static fn($a, $b) => strcmp($b['sort'], $a['sort']));
    return $out;
}

function reel_summary(string $slug): array
{
    $root = reels_root();
    $dir = "$root/projects/$slug";
    $meta = reel_meta($slug);
    $proj = read_json_file("$dir/project.json", null);
    $beats = read_json_file("$dir/beats.json", null);
    $job = reel_job_current($slug);
    return [
        'slug'      => $slug,
        'title'     => $meta['title'],
        'client'    => $meta['client'],
        'status'    => $meta['status'],
        'duration'  => is_array($proj) ? (float) ($proj['dur'] ?? 0) : null,
        'beats'     => is_array($beats) ? count((array) ($beats['beats'] ?? [])) : 0,
        'rendered'  => is_file("$dir/out.mp4"),
        'job'       => $job,
        'updated'   => $meta['updated_at'] ?: (is_dir($dir) ? gmdate('c', (int) filemtime($dir)) : ''),
        'sort'      => $meta['created_at'] ?: (is_dir($dir) ? gmdate('c', (int) filectime($dir)) : ''),
    ];
}

/** Clients you can link a reel to: leads you've worked with, newest first. */
function reel_clients(): array
{
    $out = [];
    foreach (leads_all() as $l) {
        if (in_array($l['status'] ?? '', ['won', 'qualified', 'contacted'], true)) {
            $name = trim((string) ($l['company'] ?? '')) ?: (string) ($l['name'] ?? '');
            $out[] = ['id' => (string) $l['id'], 'name' => $name, 'status' => (string) $l['status']];
        }
    }
    return $out;
}

/* --------------------------------------------------------------------------
   Creating a reel: chunked upload, then new_project.sh as a background job
   -------------------------------------------------------------------------- */

const REEL_UPLOAD_MAX = 4 * 1024 * 1024 * 1024; // 4 GB

function reel_upload_path(string $id): string
{
    if (!preg_match('/^[a-f0-9]{16,32}$/', $id)) {
        throw new ApiError('Bad upload id.');
    }
    return data_path("reels/uploads/$id.part");
}

/** Append one chunk (the raw request body) at $offset. Returns the new size. */
function reel_upload_chunk(string $id, int $offset): int
{
    reels_require();
    $path = reel_upload_path($id);
    if ($offset === 0) {
        // Uploads abandoned more than a day ago (closed tab, lost connection) are just taking space.
        foreach (glob(dirname($path) . '/*.part') ?: [] as $old) {
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
    if ($written === false) {
        throw new ApiError('Could not save the upload.', 500);
    }
    if ($size + $written > REEL_UPLOAD_MAX) {
        @unlink($path);
        throw new ApiError('That video is over 4 GB.', 413);
    }
    return $size + $written;
}

function reel_create(array $in): array
{
    $root = reels_require();
    $title = trim((string) ($in['title'] ?? ''));
    if ($title === '' || mb_strlen($title) > 80) {
        throw new ApiError('Give the reel a title (up to 80 characters).');
    }
    $upload = reel_upload_path((string) ($in['upload'] ?? ''));
    if (!is_file($upload) || filesize($upload) < 1000) {
        throw new ApiError('The video upload is missing. Pick the file again.');
    }
    $ext = strtolower(pathinfo((string) ($in['filename'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['mov', 'mp4', 'm4v'], true)) {
        throw new ApiError('Use a .mov or .mp4 video.');
    }
    $edited = ($in['mode'] ?? '') === 'edited';

    $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) ?: $title)) ?? '', '-');
    $base = substr($base !== '' ? $base : 'reel', 0, 40);
    do {
        $slug = $base . '-' . bin2hex(random_bytes(2));
    } while (is_dir("$root/projects/$slug") || is_file(data_path("reels/$slug/reel.json")));

    $video = reel_data($slug, "source.$ext");
    if (!rename($upload, $video)) {
        throw new ApiError('Could not move the upload into place.', 500);
    }
    $meta = reel_meta_save($slug, [
        'slug'       => $slug,
        'title'      => $title,
        'client'     => reel_client_clean($in['client'] ?? []),
        'status'     => 'editing',
        'created_at' => gmdate('c'),
        'source'     => ['name' => mb_substr(basename((string) ($in['filename'] ?? '')), 0, 120), 'size' => filesize($video), 'mode' => $edited ? 'edited' : 'raw'],
        'upload'     => $video,
    ]);
    reel_job_start($slug, 'prepare');
    return reel_summary($slug) + ['meta' => $meta];
}

/* --------------------------------------------------------------------------
   Background jobs: prepare, auto beats, preview, render
   -------------------------------------------------------------------------- */

const REEL_JOB_KINDS = ['prepare', 'auto', 'preview', 'render'];

function reel_job_dir(string $slug, string $kind): string
{
    if (!in_array($kind, REEL_JOB_KINDS, true)) {
        throw new ApiError('Unknown job.', 404);
    }
    return reel_data($slug, "jobs/$kind/");
}

function reel_job_read(string $slug, string $kind): ?array
{
    $dir = reel_job_dir($slug, $kind);
    $job = read_json_file($dir . 'job.json', null);
    if (!is_array($job)) {
        return null;
    }
    if ($job['status'] === 'running') {
        $done = is_file($dir . 'exit');
        $alive = !empty($job['pid']) && (!function_exists('posix_kill') || @posix_kill((int) $job['pid'], 0));
        if ($done || !$alive) {
            $code = $done ? (int) trim((string) file_get_contents($dir . 'exit')) : -1;
            $job['status'] = $code === 0 ? 'done' : 'failed';
            $job['code'] = $code;
            $job['finished_at'] = gmdate('c');
            write_atomic($dir . 'job.json', encode_json($job));
            reel_job_finished($slug, $job);
        }
    }
    $log = (string) @file_get_contents($dir . 'log');
    $job['progress'] = reel_job_progress($slug, $job, $log);
    $job['log'] = mb_substr(implode("\n", array_slice(explode("\n", trim($log)), -14)), -3000);
    if ($kind === 'preview' && $job['status'] === 'done') {
        $job['overlaps'] = reel_overlaps($log);
    }
    return $job;
}

/** The running job, or the latest one. */
function reel_job_current(string $slug): ?array
{
    $latest = null;
    foreach (REEL_JOB_KINDS as $kind) {
        $job = reel_job_read($slug, $kind);
        if ($job && $job['status'] === 'running') {
            return $job;
        }
        if ($job && ($latest === null || strcmp($job['started_at'], $latest['started_at']) > 0)) {
            $latest = $job;
        }
    }
    return $latest;
}

function reel_jobs(string $slug): array
{
    $out = [];
    foreach (REEL_JOB_KINDS as $kind) {
        $out[$kind] = reel_job_read($slug, $kind);
    }
    return $out;
}

function reel_job_progress(string $slug, array $job, string $log): array
{
    $root = reels_root();
    $dir = "$root/projects/$slug";
    switch ($job['kind']) {
        case 'prepare':
            $step = 0;
            $label = 'Starting';
            foreach (explode("\n", $log) as $line) {
                if (preg_match('#^([1-5])/5\s+(.+)$#', trim($line), $m)) {
                    $step = (int) $m[1];
                    $label = $m[2];
                } elseif (str_starts_with(trim($line), 'auto beats')) {
                    $step = 6;
                    $label = 'beats ready';
                }
            }
            $names = [0 => 'Starting', 1 => 'Converting the video', 2 => 'Writing the transcript', 3 => 'Finding your face', 4 => 'Cutting you out (person masks)', 5 => 'Finding the head line', 6 => 'Splitting into beats'];
            return ['step' => min($step, 6), 'of' => 6, 'label' => $names[min($step, 6)] ?? $label];
        case 'render':
            $proj = read_json_file("$dir/project.json", []);
            $total = 3 * (int) ($proj['frames'] ?? 0);
            $count = 0;
            foreach (['fr', 'ba', 'gl'] as $d) {
                $count += count(glob("$dir/$d/o_*.png") ?: []);
            }
            $label = str_contains($log, 'rendering ') ? ($count >= $total && $total > 0 ? 'Encoding the video and sound' : 'Rendering frames') : 'Checking overlaps';
            return ['done' => $count, 'total' => $total, 'label' => $label];
        case 'preview':
            return ['label' => str_contains($log, 'overlaps:') ? 'Rendering preview frames' : 'Checking overlaps'];
        default:
            return ['label' => 'Splitting into beats'];
    }
}

/** Overlap report lines from engine/overlap.py ("3.4s: big overlaps face"). */
function reel_overlaps(string $log): array
{
    $out = [];
    foreach (explode("\n", $log) as $line) {
        if (preg_match('/^(\d+(?:\.\d+)?)s: (.+)$/', trim($line), $m)) {
            $out[] = ['t' => (float) $m[1], 'what' => $m[2]];
        }
    }
    return array_slice($out, 0, 40);
}

function reel_job_finished(string $slug, array $job): void
{
    if ($job['kind'] === 'prepare' && $job['status'] === 'done') {
        $meta = reel_meta($slug);
        if (!empty($meta['upload']) && is_file($meta['upload'])) {
            @unlink($meta['upload']); // new_project.sh copied it into the project
        }
        unset($meta['upload']);
        reel_meta_save($slug, $meta);
    }
    if (in_array($job['kind'], ['prepare', 'auto'], true) && $job['status'] === 'done') {
        try {
            reel_beats_from_auto($slug);
        } catch (Throwable $e) {
            error_log('studiio reels: auto beats failed: ' . $e->getMessage());
        }
    }
}

function reel_job_start(string $slug, string $kind, array $opts = []): array
{
    $root = reels_require();
    reel_dir($slug, true);
    if (!in_array($kind, REEL_JOB_KINDS, true)) {
        throw new ApiError('Unknown job.', 404);
    }
    return with_lock("reel-$slug", static function () use ($root, $slug, $kind, $opts) {
        foreach (REEL_JOB_KINDS as $k) {
            $running = reel_job_read($slug, $k);
            if ($running && $running['status'] === 'running') {
                throw new ApiError('This reel is busy (' . $k . '). Wait for it to finish.', 409);
            }
        }
        $chat = reel_chat_load($slug);
        if (!empty($chat['running']) && in_array($kind, ['preview', 'render'], true)) {
            throw new ApiError('Claude is still editing this reel. Wait for it to finish.', 409);
        }
        $py = reels_python();
        $q = static fn(string $s): string => escapeshellarg($s);
        $proj = "projects/$slug";
        $job = ['kind' => $kind, 'status' => 'running', 'started_at' => gmdate('c'), 'pid' => null];

        switch ($kind) {
            case 'prepare':
                $meta = reel_meta($slug);
                $video = (string) ($meta['upload'] ?? '');
                if ($video === '' || !is_file($video)) {
                    throw new ApiError('The uploaded video is gone. Create the reel again.');
                }
                if (is_dir("$root/$proj")) {
                    // A failed earlier try left a half-made project: set it aside (never delete it).
                    @mkdir("$root/projects/.failed", 0775, true);
                    rename("$root/$proj", "$root/projects/.failed/$slug-" . gmdate('YmdHis'));
                }
                $mode = (($meta['source']['mode'] ?? '') === 'edited') ? ' --edited' : '';
                $cmd = 'bash new_project.sh ' . $q($slug) . ' ' . $q($video) . $mode
                    . ' && ' . $q($py) . ' ' . $q(ADMIN_DIR . '/tools/reels_auto.py') . ' ' . $q($proj);
                break;
            case 'auto':
                reel_dir($slug);
                $cmd = $q($py) . ' ' . $q(ADMIN_DIR . '/tools/reels_auto.py') . ' ' . $q($proj);
                break;
            case 'preview':
                reel_dir($slug);
                reel_sync_overlay($slug);
                $times = reel_preview_times($slug);
                $job['times'] = $times;
                $cmd = $q($py) . " engine/overlap.py " . $q($proj) . '; '
                    . $q($py) . ' engine/preview.py ' . $q($proj) . ' ' . implode(' ', array_map(static fn($t) => sprintf('%.2f', $t), $times));
                break;
            case 'render':
                reel_dir($slug);
                reel_sync_overlay($slug);
                $job['force'] = !empty($opts['force']);
                $cmd = $q($py) . ' engine/build.py ' . $q($proj) . ($job['force'] ? ' --force' : '');
                break;
            default:
                throw new ApiError('Unknown job.', 404);
        }

        $dir = reel_job_dir($slug, $kind);
        @unlink($dir . 'exit');
        @unlink($dir . 'log');
        $script = "#!/bin/sh\n# STUDIIO admin reel job ($kind), started " . gmdate('c') . "\ncd " . $q($root) . " || exit 1\n"
            . "{\n$cmd\n} > " . $q($dir . 'log') . " 2>&1\necho \$? > " . $q($dir . 'exit') . "\n";
        file_put_contents($dir . 'run.sh', $script);
        $env = claude_child_env();
        $env['PYTHONUNBUFFERED'] = '1'; // progress lines reach the log as they happen
        [$code, $out] = run_cmd(['/bin/sh', '-c', 'nohup /bin/sh "$0" > /dev/null 2>&1 & echo $!', $dir . 'run.sh'], $root, null, $env);
        if ($code !== 0) {
            throw new ApiError('Could not start the job.', 500);
        }
        $job['pid'] = (int) trim($out);
        write_atomic($dir . 'job.json', encode_json($job));
        return reel_job_read($slug, $kind);
    });
}

/** One preview frame per beat (the middle of it), at most 16, spread over the reel. */
function reel_preview_times(string $slug): array
{
    $doc = reel_beats_load($slug)['doc'];
    $proj = read_json_file(reel_dir($slug) . '/project.json', []);
    $dur = (float) ($proj['dur'] ?? 0);
    $times = [];
    foreach ((array) ($doc['beats'] ?? []) as $b) {
        $times[] = round(((float) $b['a'] + min((float) $b['b'], $dur)) / 2, 2);
    }
    if (!$times) {
        for ($i = 1; $i <= 8; $i++) {
            $times[] = round($dur * $i / 9, 2);
        }
    }
    if (count($times) > 16) {
        $pick = [];
        for ($i = 0; $i < 16; $i++) {
            $pick[] = $times[(int) round($i * (count($times) - 1) / 15)];
        }
        $times = array_values(array_unique($pick));
    }
    return array_values(array_filter($times, static fn($t) => $t > 0 && ($dur <= 0 || $t < $dur)));
}

/* --------------------------------------------------------------------------
   Beats: beats.json in the project folder
   -------------------------------------------------------------------------- */

function reel_beats_file(string $slug): string
{
    return reel_dir($slug) . '/beats.json';
}

/** ['doc' => array|null, 'version' => string|null] */
function reel_beats_load(string $slug): array
{
    $file = reel_beats_file($slug);
    if (!is_file($file)) {
        return ['doc' => null, 'version' => null];
    }
    $raw = (string) file_get_contents($file);
    $doc = json_decode($raw, true);
    if (!is_array($doc)) {
        throw new ApiError('beats.json in this reel is not valid JSON. Fix it in Claude Code or ask Claude in the reel chat.', 500, ['version' => sha1($raw)]);
    }
    return ['doc' => $doc, 'version' => sha1($raw)];
}

function reel_beats_save(string $slug, mixed $doc, ?string $version): array
{
    return with_lock("reel-$slug", static function () use ($slug, $doc, $version) {
        $current = reel_beats_load($slug);
        if ($version !== null && $current['version'] !== null && !hash_equals($current['version'], $version)) {
            throw new ApiError('This reel was changed somewhere else (for example by Claude). Reload to see the latest beats.', 409);
        }
        $clean = reel_beats_validate($slug, $doc);
        $warnings = $clean['_warnings'];
        unset($clean['_warnings']);
        $raw = encode_json($clean);
        write_atomic(reel_beats_file($slug), $raw);
        reel_sync_overlay($slug);
        reel_meta_touch($slug);
        return ['doc' => $clean, 'version' => sha1($raw), 'warnings' => $warnings];
    });
}

function reel_meta_touch(string $slug): void
{
    $meta = reel_meta($slug);
    if ($meta['created_at'] === '') {
        $meta['created_at'] = gmdate('c');
    }
    reel_meta_save($slug, $meta);
}

function reel_text(mixed $v, int $max): string
{
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', is_scalar($v) ? (string) $v : '') ?? '';
    return trim(mb_substr($s, 0, $max));
}

/** Check and normalise a beats document. Throws ApiError listing what's wrong. */
function reel_beats_validate(string $slug, mixed $doc): array
{
    if (!is_array($doc) || !is_array($doc['beats'] ?? null)) {
        throw new ApiError('beats.json needs a "beats" list.');
    }
    $proj = read_json_file(reel_dir($slug) . '/project.json', []);
    $dur = (float) ($proj['dur'] ?? 0);
    $photos = array_flip(reel_photos($slug));
    $errors = [];
    $beats = [];
    $prev = -1.0;
    foreach (array_slice(array_values($doc['beats']), 0, 300) as $i => $b) {
        $n = $i + 1;
        if (!is_array($b)) {
            $errors[] = "Beat $n is not an object.";
            continue;
        }
        $a = is_numeric($b['a'] ?? null) ? round((float) $b['a'], 3) : null;
        $e = is_numeric($b['b'] ?? null) ? round((float) $b['b'], 3) : null;
        if ($a === null || $e === null || $a < 0 || $e <= $a || ($dur > 0 && $a >= $dur + 0.5)) {
            $errors[] = "Beat $n has bad start/end times.";
            continue;
        }
        if ($a < $prev - 0.01) {
            $errors[] = "Beat $n starts before the beat above it.";
        }
        $prev = $a;
        $effect = (string) ($b['effect'] ?? '');
        if ($effect !== '' && $effect !== 'none' && !isset(REEL_EFFECTS[$effect])) {
            $errors[] = "Beat $n: unknown effect \"$effect\".";
            $effect = '';
        }
        $f = is_array($b['fields'] ?? null) ? $b['fields'] : [];
        $items = [];
        foreach (array_slice(is_array($f['items'] ?? null) ? $f['items'] : [], 0, 10) as $it) {
            $t = reel_text($it, 80);
            if ($t !== '') {
                $items[] = $t;
            }
        }
        $pics = [];
        foreach (array_slice(is_array($f['photos'] ?? null) ? $f['photos'] : [], 0, 12) as $p) {
            $p = (string) $p;
            if (!isset($photos[$p])) {
                $errors[] = "Beat $n uses a photo that isn't in this reel: $p";
                continue;
            }
            $pics[] = $p;
        }
        $beats[] = [
            'a'       => $a,
            'b'       => $e,
            'text'    => reel_text($b['text'] ?? '', 500),
            'big'     => reel_text($b['big'] ?? '', 40),
            'accent'  => !empty($b['accent']),
            'eyebrow' => reel_text($b['eyebrow'] ?? '', 80),
            'sub'     => reel_text($b['sub'] ?? '', 60),
            'behind'  => !empty($b['behind']),
            'effect'  => $effect === 'none' ? '' : $effect,
            'fields'  => [
                'say'    => reel_text($f['say'] ?? '', 140),
                'items'  => $items,
                'number' => reel_text($f['number'] ?? '', 14),
                'photos' => $pics,
            ],
        ];
    }
    if (!$beats) {
        $errors[] = 'A reel needs at least one beat.';
    }
    $accents = count(array_filter($beats, static fn($b) => $b['accent'] && $b['big'] !== ''));
    $warnings = $accents > 3 ? ["$accents accent words: the style guide says 3 at most."] : [];

    $vfx = [];
    foreach (array_slice(is_array($doc['vfx'] ?? null) ? $doc['vfx'] : [], 0, 6) as $i => $v) {
        $n = $i + 1;
        $kind = (string) ($v['kind'] ?? '');
        $a = is_numeric($v['a'] ?? null) ? round((float) $v['a'], 2) : null;
        $e = is_numeric($v['b'] ?? null) ? round((float) $v['b'], 2) : null;
        if (!isset(REEL_VFX[$kind]) || $a === null || $e === null || $a < 0 || $e <= $a) {
            $errors[] = "Video effect $n needs a kind and a start before its end.";
            continue;
        }
        $row = ['a' => $a, 'b' => $e, 'kind' => $kind];
        $color = (string) ($v['color'] ?? '');
        if (in_array('color', REEL_VFX[$kind][1], true) && preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $row['color'] = strtoupper($color);
        }
        if (in_array('caption', REEL_VFX[$kind][1], true)) {
            $row['caption'] = reel_text($v['caption'] ?? '', 60);
        }
        $vfx[] = $row;
    }
    if ($errors) {
        throw new ApiError(count($errors) === 1 ? $errors[0] : 'Some beats need fixing.', 422, ['errors' => array_slice($errors, 0, 12)]);
    }
    $cta = is_array($doc['cta'] ?? null) ? $doc['cta'] : [];
    return [
        'version'  => 1,
        'cta'      => [
            'word' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', reel_text($cta['word'] ?? '', 20)) ?? ''),
            'gift' => reel_text($cta['gift'] ?? 'FREE GUIDE', 40) ?: 'FREE GUIDE',
        ],
        'beats'    => $beats,
        'vfx'      => $vfx,
        '_warnings' => $warnings,
    ];
}

/** Turn the engine's automatic plan (studiio-auto.json from tools/reels_auto.py) into beats.json. */
function reel_beats_from_auto(string $slug): void
{
    $dir = reel_dir($slug);
    if (is_file("$dir/beats.json")) {
        return;
    }
    $auto = read_json_file("$dir/studiio-auto.json", null);
    if (!is_array($auto) || !is_array($auto['big'] ?? null)) {
        throw new ApiError('No automatic plan found for this reel.');
    }
    $dur = (float) ($auto['dur'] ?? 0);
    $beats = [];
    $count = count($auto['big']);
    foreach ($auto['big'] as $i => $r) {
        [$a, $b, $txt, $sub, $eb, $bh] = array_pad((array) $r, 6, '');
        $big = (string) $txt;
        $last = $i === $count - 1;
        $beats[] = [
            'a'       => round((float) $a, 3),
            'b'       => round(min((float) $b, $dur > 0 ? $dur : (float) $b), 3),
            'text'    => (string) ($auto['raw'][$i] ?? ''),
            'big'     => strtoupper(str_replace('*', '', $big)),
            'accent'  => str_contains($big, '*'),
            'eyebrow' => (string) $eb,
            'sub'     => (string) $sub,
            'behind'  => (int) $bh === 1,
            'effect'  => $last && $count > 1 ? 'cta' : (isset(REEL_EFFECTS[(string) ($auto['kinds'][$i] ?? '')]) ? (string) $auto['kinds'][$i] : ''),
            'fields'  => ['say' => '', 'items' => [], 'number' => '', 'photos' => []],
        ];
    }
    $doc = reel_beats_validate($slug, ['cta' => ['word' => '', 'gift' => 'FREE GUIDE'], 'beats' => $beats, 'vfx' => []]);
    unset($doc['_warnings']);
    write_atomic("$dir/beats.json", encode_json($doc));
    reel_sync_overlay($slug);
}

/* --------------------------------------------------------------------------
   overlay.html: the STUDIIO BEATS block
   -------------------------------------------------------------------------- */

const REEL_BLOCK_START = '// >>> STUDIIO BEATS';
const REEL_BLOCK_END = '// <<< STUDIIO BEATS';

/**
 * Copy beats.json into the project's overlay.html, just before the end of its
 * EDIT PER VIDEO block. Returns false when there are no beats yet (the engine's
 * automatic plan is used then).
 */
function reel_sync_overlay(string $slug): bool
{
    $dir = reel_dir($slug);
    $file = "$dir/overlay.html";
    $html = (string) @file_get_contents($file);
    if ($html === '') {
        throw new ApiError('This reel has no overlay.html yet. Prepare the video first.');
    }
    $beats = reel_beats_load($slug)['doc'];
    $clean = preg_replace('#\n?' . preg_quote(REEL_BLOCK_START, '#') . '.*?' . preg_quote(REEL_BLOCK_END, '#') . '[^\n]*#s', '', $html) ?? $html;
    if ($beats === null) {
        if ($clean !== $html) {
            write_atomic($file, $clean);
        }
        return false;
    }
    unset($beats['_warnings']);
    // The EDIT PER VIDEO block ends with a line of "=" right after backPlates().
    if (!preg_match('#function backPlates\(t\)[^\n]*\n(?:.*\n)*?(// ={20,}\n)#', $clean, $m, PREG_OFFSET_CAPTURE)) {
        throw new ApiError("This reel's overlay.html doesn't look like the Reels Montage PRO template (no EDIT PER VIDEO block end), so the editor can't add the beats to it.", 500);
    }
    $at = $m[1][1];
    $json = json_encode($beats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    $bridge = (string) file_get_contents(__DIR__ . '/reels-bridge.js');
    $block = REEL_BLOCK_START . ": written by the STUDIIO admin from beats.json. Edit beats.json (or the admin), never this block.\n"
        . "const STUDIIO_BEATS=$json;\n" . trim($bridge) . "\n" . REEL_BLOCK_END . "\n";
    $out = substr($clean, 0, $at) . $block . substr($clean, $at);
    if ($out !== $html) {
        write_atomic($file, $out);
    }
    return true;
}

/* --------------------------------------------------------------------------
   Photos for effects (collage, orbit, phone…): <project>/photos/
   -------------------------------------------------------------------------- */

function reel_photos(string $slug): array
{
    $out = [];
    foreach (glob(reel_dir($slug) . '/photos/*') ?: [] as $f) {
        $name = basename($f);
        if (preg_match('/^[A-Za-z0-9._-]+\.(jpe?g|png|webp)$/i', $name)) {
            $out[] = $name;
        }
    }
    sort($out);
    return $out;
}

function reel_photo_upload(string $slug, array $file): array
{
    $dir = reel_dir($slug) . '/photos';
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        throw new ApiError('The photo did not arrive. Try again (max 20 MB).');
    }
    if (count(reel_photos($slug)) >= 60) {
        throw new ApiError('A reel can hold 60 photos.');
    }
    $tmp = (string) $file['tmp_name'];
    $info = @getimagesize($tmp);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        throw new ApiError('Use a JPG, PNG or WebP photo.');
    }
    $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(pathinfo((string) ($file['name'] ?? 'photo'), PATHINFO_FILENAME))) ?? '', '-') ?: 'photo';
    $base = substr($base, 0, 40);
    @mkdir($dir, 0775, true);
    $name = "$base.jpg";
    for ($i = 2; is_file("$dir/$name"); $i++) {
        $name = "$base-$i.jpg";
    }
    if (!function_exists('imagecreatefromstring')) {
        throw new ApiError('This PHP has no image support (GD).', 500);
    }
    $src = @imagecreatefromstring((string) file_get_contents($tmp));
    if (!$src) {
        throw new ApiError('Could not read that photo.');
    }
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $o = (int) (@exif_read_data($tmp)['Orientation'] ?? 1);
        $src = match ($o) { 3 => imagerotate($src, 180, 0), 6 => imagerotate($src, -90, 0), 8 => imagerotate($src, 90, 0), default => $src };
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $k = min(1, 1600 / max($w, $h));
    $nw = max(1, (int) round($w * $k));
    $nh = max(1, (int) round($h * $k));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    if (!imagejpeg($dst, "$dir/$name", 85)) {
        throw new ApiError('Could not save the photo.', 500);
    }
    return ['name' => $name, 'photos' => reel_photos($slug)];
}

/* --------------------------------------------------------------------------
   The reel page payload
   -------------------------------------------------------------------------- */

function reel_catalog(): array
{
    $root = reels_root();
    $notes = [];
    foreach (explode("\n", (string) @file_get_contents("$root/EFFECTS.md")) as $line) {
        if (preg_match('/^\|\s*`([A-Za-z0-9]+)`\s*\|\s*([^|]+)\|\s*([^|]+)\|/', $line, $m)) {
            $notes[$m[1]] ??= ['say' => trim($m[2]), 'good' => trim($m[3])];
        }
    }
    $out = [];
    foreach (REEL_EFFECTS as $name => [$group, $fields]) {
        $out[] = [
            'name'   => $name,
            'group'  => $group,
            'fields' => (object) $fields,
            'say'    => $name === 'cta' ? 'call to action at the end' : ($notes[$name]['say'] ?? ''),
            'good'   => $name === 'cta' ? 'the last beat: your code word, or "follow for more"' : ($notes[$name]['good'] ?? ''),
        ];
    }
    return $out;
}

function reel_payload(string $slug): array
{
    reel_dir($slug, true);
    $meta = reel_meta($slug);
    if ($meta['created_at'] === '' && !is_dir(reels_root() . "/projects/$slug")) {
        throw new ApiError('Unknown reel.', 404);
    }
    $root = reels_require();
    $dir = "$root/projects/$slug";
    $ready = is_file("$dir/project.json") && is_file("$dir/src/main.mp4");
    $beats = $ready ? reel_beats_load($slug) : ['doc' => null, 'version' => null];
    if ($ready && $beats['doc'] === null && is_file("$dir/studiio-auto.json")) {
        reel_beats_from_auto($slug);
        $beats = reel_beats_load($slug);
    }
    $proj = $ready ? read_json_file("$dir/project.json", []) : [];
    $file = static fn(string $rel): ?int => is_file("$dir/$rel") ? (int) filemtime("$dir/$rel") : null;
    return [
        'reel'    => $meta,
        'ready'   => $ready,
        'project' => ['dur' => (float) ($proj['dur'] ?? 0), 'frames' => (int) ($proj['frames'] ?? 0), 'fps' => (int) ($proj['fps'] ?? 30), 'path' => '~/reels-montage-pro/projects/' . $slug],
        'beats'   => $beats['doc'],
        'version' => $beats['version'],
        'files'   => ['main' => $file('src/main.mp4'), 'out' => $file('out.mp4'), 'preview' => $file('preview.jpg'), 'masks' => is_dir("$dir/masks")],
        'photos'  => $ready ? reel_photos($slug) : [],
        'jobs'    => reel_jobs($slug),
        'chat'    => reel_chat_public($slug),
        'base'    => 'reels-file.php/' . reels_token() . '/',
        'catalog' => reel_catalog(),
        'groups'  => REEL_GROUPS,
        'vfx'     => array_map(static fn($k, $v) => ['kind' => $k, 'label' => $v[0], 'opts' => $v[1]], array_keys(REEL_VFX), REEL_VFX),
        'statuses' => REEL_STATUSES,
    ];
}

/** What the reel page polls while something runs. */
function reel_status(string $slug): array
{
    $dir = reel_dir($slug, true);
    $beats = is_file("$dir/beats.json") ? sha1((string) file_get_contents("$dir/beats.json")) : null;
    $file = static fn(string $rel): ?int => is_file("$dir/$rel") ? (int) filemtime("$dir/$rel") : null;
    return [
        'jobs'    => reel_jobs($slug),
        'chat'    => reel_chat_public($slug),
        'version' => $beats,
        'ready'   => is_file("$dir/project.json") && is_file("$dir/src/main.mp4"),
        'files'   => ['main' => $file('src/main.mp4'), 'out' => $file('out.mp4'), 'preview' => $file('preview.jpg'), 'masks' => is_dir("$dir/masks")],
    ];
}

/* --------------------------------------------------------------------------
   Chat with Claude about one reel (Claude Code edits beats.json)
   -------------------------------------------------------------------------- */

function reel_chat_load(string $slug): array
{
    $c = read_json_file(reel_data($slug, 'chat.json'), []);
    return array_merge(['messages' => [], 'running' => null], is_array($c) ? $c : []);
}

function reel_chat_save(string $slug, array $chat): void
{
    write_atomic(reel_data($slug, 'chat.json'), encode_json($chat));
}

/** Chat for the page; finishes the running Claude turn if it has ended. */
function reel_chat_public(string $slug): array
{
    $chat = reel_chat_load($slug);
    if (!empty($chat['running'])) {
        $chat = with_lock("reel-chat-$slug", static fn() => reel_chat_refresh($slug));
    }
    return ['messages' => array_slice($chat['messages'], -40), 'running' => $chat['running'] ? true : false, 'steps' => $chat['steps'] ?? []];
}

function reel_chat_prompt(): string
{
    $lines = [];
    foreach (REEL_EFFECTS as $name => [$group, $fields]) {
        $f = [];
        foreach ($fields as $k => $v) {
            $f[] = match ($k) { 's' => "say: $v", 'i' => "items: $v", 'n' => "number: $v", 'p' => "photos: at least $v", default => '' };
        }
        $lines[] = "- $name (" . REEL_GROUPS[$group] . ')' . ($f ? ': ' . implode('; ', $f) : '');
    }
    $vfx = implode(', ', array_map(static fn($k, $v) => "$k (" . strtolower($v[0]) . ($v[1] ? ', ' . implode(', ', $v[1]) : '') . ')', array_keys(REEL_VFX), REEL_VFX));
    $effects = implode("\n", $lines);
    return <<<TXT
You are the editor of one vertical Reel inside the STUDIIO.CO admin. The person writing to you is the agency owner; the reel may be for one of their clients.
You work only on beats.json in the current folder. Never edit any other file (overlay.html, the transcript and the video are made by the engine).

beats.json:
{"version":1, "cta":{"word":"GUIDE","gift":"FREE GUIDE"}, "beats":[{"a":0.0,"b":2.8,"text":"what is said","big":"HUGE WORD","accent":false,"eyebrow":"small line above","sub":"","behind":true,"effect":"title","fields":{"say":"","items":[],"number":"","photos":[]}}], "vfx":[{"a":15.0,"b":17.5,"kind":"bg","color":"#F2632A"}]}
- One beat per spoken phrase. Keep a, b and text as they are (they come from the transcript); you may change everything else.
- big: one huge word shown above or behind the head (UPPERCASE, empty for none). accent: draws it in the accent colour, at most 3 accent words per reel. behind: puts it behind the head.
- effect: one per beat from the list below ("" for none). The last beat is usually "cta" (the code word ending; set cta.word and cta.gift, or leave word empty for "follow for more").
- fields: content for the effect. Empty fields fall back to the words of the phrase. items is a list of short strings. photos are file names in the photos/ folder of this reel (list them with Glob photos/*); never use photos that aren't there, and pick a different effect when an effect needs photos the reel doesn't have.
- vfx: video effects that change the picture itself: $vfx. 1.5 to 4 seconds each, start and end on beat boundaries, at most 3, never two at the same time.

Style rules (Reels Montage PRO): pick each effect by the meaning of the phrase; never the same effect twice in a row; at least one effect behind the person and one typography effect per reel; lists → checklist or toggleList, numbers → statCard or numPair, time → stopwatch, questions → askBar, comparisons → duoCircles, messages → notifyStack, short punchlines → typography. Keep text short. Fix words the transcript misheard when you write them into big, say or items.

Effects:
$effects

Read beats.json, make the change, and read the file back to check it is valid JSON. Then reply in plain text (no Markdown), 1 to 4 short lines: what you changed, beat by beat, with the time (e.g. "0:07 collage with the 4 product photos"). If the request needs something you don't have (photos, facts, a number), change nothing and say what you need.
TXT;
}

function reel_chat_send(string $slug, string $message): array
{
    reel_dir($slug);
    if (claude_runner() !== 'local') {
        throw new ApiError('Ask Claude on this Mac is off. Set claude.runner to "local" in .private/config.php.');
    }
    $message = trim($message);
    if ($message === '' || mb_strlen($message) > 2000) {
        throw new ApiError('Write your request in 1 to 2000 characters.');
    }
    if (!is_file(reel_dir($slug) . '/beats.json')) {
        throw new ApiError('This reel has no beats yet. Wait until the video is prepared.');
    }
    foreach (REEL_JOB_KINDS as $k) {
        $j = reel_job_read($slug, $k);
        if ($j && $j['status'] === 'running' && in_array($k, ['preview', 'render', 'prepare'], true)) {
            throw new ApiError('Wait until the ' . $k . ' job has finished.', 409);
        }
    }
    return with_lock("reel-chat-$slug", static function () use ($slug, $message) {
        $chat = reel_chat_load($slug);
        if (!empty($chat['running'])) {
            throw new ApiError('Claude is still working on your last message.', 409);
        }
        $id = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $run = reel_data($slug, "chat/$id/");
        $history = array_slice($chat['messages'], -6);
        $prompt = '';
        if ($history) {
            $prompt .= "Earlier in this conversation:\n";
            foreach ($history as $m) {
                $prompt .= ($m['role'] === 'user' ? 'Owner: ' : 'You: ') . mb_substr((string) $m['text'], 0, 600) . "\n";
            }
            $prompt .= "\n";
        }
        $prompt .= "Request: $message\n";
        file_put_contents($run . 'prompt.txt', $prompt);
        copy(reel_dir($slug) . '/beats.json', $run . 'before.json');

        $c = config()['claude'];
        $cmd = [
            (string) $c['bin'], '-p',
            '--model', (string) $c['model'],
            '--output-format', 'stream-json', '--verbose',
            '--restricted', '--strict-mcp-config', '--disable-slash-commands',
            '--permission-mode', 'acceptEdits',
            '--allowedTools', 'Read,Edit,Write,Glob,Grep',
            '--max-budget-usd', (string) (config()['reels']['chat_budget_usd'] ?? 1),
            '--no-session-persistence',
            '--append-system-prompt', reel_chat_prompt(),
        ];
        $env = claude_child_env();
        $env['TASK_DIR'] = $run;
        $script = '( nohup "$@" < "$TASK_DIR/prompt.txt" > "$TASK_DIR/out.jsonl" 2> "$TASK_DIR/err.log"; echo $? > "$TASK_DIR/exit" ) > /dev/null 2>&1 & echo $!';
        [$code, $out] = run_cmd(array_merge(['/bin/sh', '-c', $script, 'sh'], $cmd), reel_dir($slug), null, $env);
        if ($code !== 0) {
            throw new ApiError('Could not start Claude Code.', 500);
        }
        $chat['messages'][] = ['role' => 'user', 'text' => $message, 'at' => gmdate('c')];
        $chat['running'] = ['id' => $id, 'pid' => (int) trim($out), 'started_at' => gmdate('c')];
        $chat['steps'] = [];
        reel_chat_save($slug, $chat);
        return ['messages' => array_slice($chat['messages'], -40), 'running' => true, 'steps' => []];
    });
}

function reel_chat_refresh(string $slug): array
{
    $chat = reel_chat_load($slug);
    $r = $chat['running'] ?? null;
    if (!$r) {
        return $chat;
    }
    $run = reel_data($slug, 'chat/' . preg_replace('/[^0-9a-f-]/', '', (string) $r['id']) . '/');
    $dir = reel_dir($slug);
    [$files, $steps, $result] = parse_claude_stream($run . 'out.jsonl', $dir);
    $chat['steps'] = array_slice($steps, -6);
    $finished = is_file($run . 'exit');
    $alive = !empty($r['pid']) && (!function_exists('posix_kill') || @posix_kill((int) $r['pid'], 0));
    if (!$finished && $alive) {
        reel_chat_save($slug, $chat);
        return $chat;
    }
    $reply = ['role' => 'claude', 'text' => '', 'at' => gmdate('c'), 'changed' => [], 'cost' => null, 'error' => ''];
    if ($result !== null && empty($result['is_error'])) {
        $reply['text'] = trim((string) ($result['result'] ?? ''));
        $reply['cost'] = isset($result['total_cost_usd']) ? round((float) $result['total_cost_usd'], 3) : null;
    } else {
        $reply['error'] = claude_error_hint($result !== null
            ? ((string) ($result['result'] ?? '') ?: (string) ($result['subtype'] ?? 'Claude Code stopped with an error.'))
            : (trim(mb_substr((string) @file_get_contents($run . 'err.log'), 0, 1500)) ?: 'Claude Code exited without an answer.'));
    }
    // Check Claude's edit: keep it only if beats.json is still valid.
    $before = (string) @file_get_contents($run . 'before.json');
    $now = (string) @file_get_contents("$dir/beats.json");
    if ($now !== $before) {
        try {
            $clean = reel_beats_validate($slug, json_decode($now, true));
            unset($clean['_warnings']);
            write_atomic("$dir/beats.json", encode_json($clean));
            reel_sync_overlay($slug);
            $reply['changed'] = reel_beats_diff(json_decode($before, true) ?: [], $clean);
        } catch (ApiError $e) {
            write_atomic("$dir/beats.json", $before);
            $why = $e->extra['errors'] ?? [$e->getMessage()];
            $reply['error'] = trim($reply['error'] . "\nClaude's edit broke beats.json, so it was undone: " . implode(' ', array_slice($why, 0, 3)));
        }
    }
    $other = array_values(array_filter($files, static fn($f) => $f !== 'beats.json'));
    if ($other) {
        $reply['error'] = trim($reply['error'] . "\nClaude also edited: " . implode(', ', $other) . '. Check those files.');
    }
    $chat['messages'][] = $reply;
    $chat['running'] = null;
    $chat['steps'] = [];
    reel_chat_save($slug, $chat);
    return $chat;
}

/** Which beats changed between two beats documents: list of start times. */
function reel_beats_diff(array $before, array $after): array
{
    $old = (array) ($before['beats'] ?? []);
    $out = [];
    foreach ((array) ($after['beats'] ?? []) as $i => $b) {
        $o = $old[$i] ?? null;
        $strip = static fn($x) => is_array($x) ? array_intersect_key($x, array_flip(['big', 'accent', 'eyebrow', 'sub', 'behind', 'effect', 'fields', 'a', 'b'])) : null;
        if ($strip($o) != $strip($b)) {
            $out[] = (float) $b['a'];
        }
    }
    if (($before['vfx'] ?? []) != ($after['vfx'] ?? [])) {
        $out[] = 'vfx';
    }
    if (($before['cta'] ?? []) != ($after['cta'] ?? [])) {
        $out[] = 'cta';
    }
    return $out;
}
