<?php
/**
 * STUDIIO.CO admin — site content: the JSON files in content/, their rules,
 * and where they are saved (files on this machine, or commits on GitHub).
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** Every content file the site loads. Claude Code edits the same files (see CLAUDE.md). */
const CONTENT_FILES = [
    'work'     => 'Selected work',
    'reel'     => 'Hero reel',
    'copy'     => 'Website copy',
    'clients'  => 'Client logos',
    'services' => 'Services',
    'articles' => 'Articles',
    'taxonomy' => 'Sectors & categories',
];

const IMAGE_NAME = '[a-z0-9][a-z0-9._-]{0,80}\.(?:jpe?g|png|webp|gif|svg)';

/** Only these repo paths can ever be read or written by the admin. */
function safe_rel_path(string $rel): string
{
    if (!preg_match('#^(?:content/[a-z]+\.json|img/(?:clients/)?' . IMAGE_NAME . ')$#', $rel) || str_contains($rel, '..')) {
        throw new ApiError('Path not allowed: ' . $rel, 400);
    }
    return $rel;
}

function content_rel(string $name): string
{
    if (!isset(CONTENT_FILES[$name])) {
        throw new ApiError('Unknown content file: ' . $name, 404);
    }
    return "content/$name.json";
}

/* --------------------------------------------------------------------------
   Stores
   -------------------------------------------------------------------------- */

interface ContentStore
{
    /** @return array{text:string,version:string}|null */
    public function read(string $rel): ?array;
    /** Saves text; fails with 409 if $version no longer matches. Returns the new version. */
    public function write(string $rel, string $text, ?string $version, string $message): string;
    public function writeBinary(string $rel, string $bytes, string $message): void;
    /** Image file names in a folder (img or img/clients). */
    public function listImages(string $dir): array;
    public function describe(): string;
}

function conflict_error(string $rel, ContentStore $store): ApiError
{
    $current = $store->read($rel);
    return new ApiError(
        basename($rel) . ' changed since you opened it (Claude Code or another tab may have edited it). Reload to get the latest version, then redo your change.',
        409,
        ['version' => $current['version'] ?? null]
    );
}

/** Saves straight into the repo folder on this machine. Claude Code sees changes instantly; Publish commits them. */
final class LocalStore implements ContentStore
{
    public function __construct(private readonly string $root)
    {
    }

    private function abs(string $rel): string
    {
        return rtrim($this->root, '/') . '/' . safe_rel_path($rel);
    }

    public function read(string $rel): ?array
    {
        $path = $this->abs($rel);
        if (!is_file($path)) {
            return null;
        }
        $text = (string) file_get_contents($path);
        return ['text' => $text, 'version' => sha1($text)];
    }

    public function write(string $rel, string $text, ?string $version, string $message): string
    {
        return with_lock('content', function () use ($rel, $text, $version): string {
            $current = $this->read($rel);
            if ($version !== null && $current !== null && !hash_equals($current['version'], $version)) {
                throw conflict_error($rel, $this);
            }
            write_atomic($this->abs($rel), $text);
            return sha1($text);
        });
    }

    public function writeBinary(string $rel, string $bytes, string $message): void
    {
        write_atomic($this->abs($rel), $bytes);
    }

    public function listImages(string $dir): array
    {
        $path = rtrim($this->root, '/') . '/' . $dir;
        $names = is_dir($path) ? scandir($path) : [];
        $names = array_values(array_filter($names, static fn($n) => preg_match('#^' . IMAGE_NAME . '$#i', $n) && is_file("$path/$n")));
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        return $names;
    }

    public function describe(): string
    {
        return 'local';
    }
}

/** Commits each save to GitHub; Hostinger's Git deployment then puts it live. */
final class GitHubStore implements ContentStore
{
    private array $listCache = [];

    public function __construct(private readonly string $branch)
    {
    }

    private function url(string $rel): string
    {
        return gh_repo_path('/contents/' . implode('/', array_map('rawurlencode', explode('/', safe_rel_path($rel)))));
    }

    public function read(string $rel): ?array
    {
        [$status, $body] = github_api('GET', $this->url($rel) . '?ref=' . rawurlencode($this->branch));
        if ($status === 404) {
            return null;
        }
        if ($status !== 200 || !is_array($body) || !isset($body['sha'])) {
            throw new ApiError("GitHub could not read $rel (HTTP $status).", 502);
        }
        return ['text' => (string) base64_decode((string) ($body['content'] ?? ''), false), 'version' => (string) $body['sha']];
    }

    public function write(string $rel, string $text, ?string $version, string $message): string
    {
        return $this->put($rel, $text, $version ?? ($this->read($rel)['version'] ?? null), $message);
    }

    public function writeBinary(string $rel, string $bytes, string $message): void
    {
        $this->put($rel, $bytes, $this->read($rel)['version'] ?? null, $message);
    }

    private function put(string $rel, string $bytes, ?string $sha, string $message): string
    {
        $body = ['message' => $message, 'content' => base64_encode($bytes), 'branch' => $this->branch];
        if ($sha !== null) {
            $body['sha'] = $sha;
        }
        [$status, $res] = github_api('PUT', $this->url($rel), $body);
        if ($status === 409 || ($status === 422 && str_contains(strtolower((string) ($res['message'] ?? '')), 'sha'))) {
            throw conflict_error($rel, $this);
        }
        if (($status !== 200 && $status !== 201) || !isset($res['content']['sha'])) {
            throw new ApiError("GitHub did not save $rel (HTTP $status): " . ($res['message'] ?? 'unknown error'), 502);
        }
        unset($this->listCache[dirname($rel)]);
        return (string) $res['content']['sha'];
    }

    public function listImages(string $dir): array
    {
        if (isset($this->listCache[$dir])) {
            return $this->listCache[$dir];
        }
        [$status, $body] = github_api('GET', gh_repo_path('/contents/' . $dir) . '?ref=' . rawurlencode($this->branch));
        if ($status !== 200 || !is_array($body)) {
            throw new ApiError("GitHub could not list $dir (HTTP $status).", 502);
        }
        $names = [];
        foreach ($body as $item) {
            if (($item['type'] ?? '') === 'file' && preg_match('#^' . IMAGE_NAME . '$#i', (string) $item['name'])) {
                $names[] = (string) $item['name'];
            }
        }
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        return $this->listCache[$dir] = $names;
    }

    public function describe(): string
    {
        return 'github';
    }
}

function store(): ContentStore
{
    static $store = null;
    if ($store === null) {
        $cfg = config();
        $store = $cfg['content_backend'] === 'github'
            ? new GitHubStore((string) ($cfg['github']['branch'] ?: 'main'))
            : new LocalStore((string) $cfg['site_root']);
    }
    return $store;
}

/* --------------------------------------------------------------------------
   Reading and saving content
   -------------------------------------------------------------------------- */

function load_content(string $name): array
{
    $rel = content_rel($name);
    $file = store()->read($rel);
    if ($file === null) {
        throw new ApiError("$rel does not exist yet.", 404);
    }
    try {
        $data = json_decode($file['text'], true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new ApiError("$rel is not valid JSON (" . $e->getMessage() . '). Fix it in Raw JSON, or ask Claude Code to.', 500, ['file' => $name, 'version' => $file['version'], 'raw' => $file['text']]);
    }
    return ['data' => $data, 'version' => $file['version']];
}

/**
 * Validate, tidy and save one content file. Returns [newVersion, warnings, savedData].
 * savedData is exactly what was written (not re-read: GitHub reads can lag just after a commit).
 */
function save_content(string $name, mixed $data, ?string $version): array
{
    $rel = content_rel($name);
    $v = new ContentValidator(store());
    $clean = $v->validate($name, $data);
    if ($v->errors) {
        throw new ApiError('Please fix: ' . implode(' · ', array_slice($v->errors, 0, 6)), 422, ['errors' => $v->errors]);
    }
    $text = encode_json($clean);
    $newVersion = store()->write($rel, $text, $version, 'Admin: update ' . strtolower(CONTENT_FILES[$name]));
    return [$newVersion, $v->warnings, json_decode($text, true)];
}

/** Images uploaded in the last hour (GitHub's folder listing can lag behind a fresh commit). */
function recent_uploads(string $dir, ?string $add = null): array
{
    $file = data_path('recent-uploads.json');
    return with_lock('uploads', function () use ($file, $dir, $add): array {
        $list = read_json_file($file, []);
        $list = array_filter(is_array($list) ? $list : [], static fn($t) => is_int($t) && $t > time() - 3600);
        if ($add !== null) {
            $list["$dir/$add"] = time();
            write_atomic($file, json_encode($list));
        }
        $prefix = "$dir/";
        return array_values(array_map(
            static fn($k) => substr($k, strlen($prefix)),
            array_filter(array_keys($list), static fn($k) => str_starts_with($k, $prefix) && !str_contains(substr($k, strlen($prefix)), '/'))
        ));
    });
}

/* --------------------------------------------------------------------------
   Rules for each file
   -------------------------------------------------------------------------- */

final class ContentValidator
{
    public array $errors = [];
    public array $warnings = [];
    private array $images = [];
    private ?array $taxonomy = null;

    public function __construct(private readonly ContentStore $store)
    {
    }

    public function validate(string $name, mixed $data): mixed
    {
        return match ($name) {
            'copy'     => $this->copy($data),
            'work'     => $this->list($data, 'work', fn($item, $at) => $this->workItem($item, $at)),
            'reel'     => $this->list($data, 'reel', fn($item, $at) => $this->reelItem($item, $at)),
            'clients'  => $this->clients($data),
            'services' => $this->services($data),
            'articles' => $this->list($data, 'articles', fn($item, $at) => $this->article($item, $at)),
            'taxonomy' => $this->taxonomyFile($data),
        };
    }

    /* ---- building blocks ---- */

    private function fail(string $at, string $msg): void
    {
        $this->errors[] = "$at: $msg";
    }

    private function str(mixed $v, string $at, bool $required = false, int $max = 2000): string
    {
        if ($v === null || $v === '') {
            if ($required) {
                $this->fail($at, 'required');
            }
            return '';
        }
        if (!is_string($v) && !is_int($v) && !is_float($v)) {
            $this->fail($at, 'must be text');
            return '';
        }
        $s = trim((string) $v);
        if (mb_strlen($s) > $max) {
            $this->fail($at, "too long (max $max characters)");
        }
        return $s;
    }

    /** {en, ar} pair. English is required where the site needs it; missing Arabic is a warning. */
    private function pair(mixed $v, string $at, bool $required = true, int $max = 500): array
    {
        $v = is_array($v) ? $v : [];
        $en = $this->str($v['en'] ?? null, "$at.en", $required, $max);
        $ar = $this->str($v['ar'] ?? null, "$at.ar", false, $max);
        if ($en !== '' && $ar === '') {
            $this->warnings[] = "$at: Arabic is empty (the site shows English instead)";
        }
        return ['en' => $en, 'ar' => $ar];
    }

    private function slug(mixed $v, string $at): string
    {
        $s = $this->str($v, $at, true, 40);
        if ($s !== '' && !preg_match('/^[a-z][a-z0-9-]*$/', $s)) {
            $this->fail($at, 'use lowercase letters, numbers and dashes');
        }
        return $s;
    }

    private function image(mixed $v, string $at, string $dir = 'img'): string
    {
        $s = $this->str($v, $at, true, 120);
        if ($s === '') {
            return '';
        }
        if (!preg_match('#^' . IMAGE_NAME . '$#i', $s)) {
            $this->fail($at, "\"$s\" is not a valid image file name");
            return $s;
        }
        $this->images[$dir] ??= $this->store->listImages($dir);
        if (!in_array($s, $this->images[$dir], true) && $this->store instanceof GitHubStore) {
            $this->images[$dir] = array_merge($this->images[$dir], recent_uploads($dir));
        }
        if (!in_array($s, $this->images[$dir], true)) {
            $this->fail($at, "image \"$s\" is not in $dir/ (upload it first)");
        }
        return $s;
    }

    private function int(mixed $v, string $at, int $min, int $max): int
    {
        if (!is_numeric($v) || (int) $v != $v) {
            $this->fail($at, 'must be a whole number');
            return $min;
        }
        $n = (int) $v;
        if ($n < $min || $n > $max) {
            $this->fail($at, "must be between $min and $max");
        }
        return $n;
    }

    /** Keys the admin doesn't know about (e.g. added by Claude Code) are kept, after the known ones. */
    private function extra(array $item, array $known): array
    {
        return array_diff_key($item, array_flip($known));
    }

    private function list(mixed $data, string $at, callable $each): array
    {
        if (!is_array($data) || !array_is_list($data)) {
            $this->fail($at, 'must be a list');
            return [];
        }
        $out = [];
        foreach ($data as $i => $item) {
            if (!is_array($item) || array_is_list($item)) {
                $this->fail("{$at}[" . ($i + 1) . ']', 'must be an object');
                continue;
            }
            $out[] = $each($item, "{$at}[" . ($i + 1) . ']');
        }
        return $out;
    }

    private function taxonomy(): array
    {
        if ($this->taxonomy === null) {
            $file = $this->store->read('content/taxonomy.json');
            $this->taxonomy = $file ? (json_decode($file['text'], true) ?: []) : [];
        }
        return $this->taxonomy;
    }

    private function category(mixed $v, string $at): string
    {
        $s = $this->str($v, $at, true, 40);
        $cats = array_keys($this->taxonomy()['categories'] ?? []);
        if ($s !== '' && $cats && !in_array($s, $cats, true)) {
            $this->fail($at, "unknown category \"$s\" (use " . implode(', ', $cats) . ')');
        }
        return $s;
    }

    /* ---- files ---- */

    private function copy(mixed $data): array
    {
        if (!is_array($data) || !isset($data['en']) || !is_array($data['en'])) {
            $this->fail('copy', 'must have "en" and "ar" sections');
            return ['en' => new stdClass(), 'ar' => new stdClass()];
        }
        $out = [];
        foreach (['en', 'ar'] as $lang) {
            $section = is_array($data[$lang] ?? null) ? $data[$lang] : [];
            $clean = [];
            foreach ($section as $key => $value) {
                $key = (string) $key;
                if (!preg_match('/^[a-zA-Z][\w.-]{0,79}$/', $key)) {
                    $this->fail("copy.$lang", "invalid key \"$key\"");
                    continue;
                }
                $clean[$key] = self::inlineHtml($this->str($value, "copy.$lang.$key", false, 5000));
            }
            $out[$lang] = $clean ?: new stdClass();
        }
        $missing = array_diff(array_keys((array) $out['en']), array_keys((array) $out['ar']));
        if ($missing) {
            $this->warnings[] = 'Arabic is missing for: ' . implode(', ', array_slice($missing, 0, 8)) . (count($missing) > 8 ? '…' : '');
        }
        return $out;
    }

    /** Copy may contain a few inline tags (<em>, <br>, <span class="lc">); everything else is stripped. */
    public static function inlineHtml(string $s): string
    {
        if (!str_contains($s, '<')) {
            return $s;
        }
        $s = strip_tags($s, '<em><strong><b><i><br><span>');
        $s = preg_replace('#<(/?)(em|strong|b|i)\b[^>]*>#i', '<$1$2>', $s);
        $s = preg_replace('#<br\b[^>]*>#i', '<br>', $s);
        return preg_replace_callback('#<span\b([^>]*)>#i', static function (array $m): string {
            return preg_match('/class\s*=\s*"([a-z0-9 _-]*)"/i', $m[1], $c) ? '<span class="' . $c[1] . '">' : '<span>';
        }, $s);
    }

    private function workItem(array $w, string $at): array
    {
        $cats = [];
        foreach ((array) ($w['cats'] ?? []) as $j => $c) {
            $cats[] = $this->category($c, "$at.cats[" . ($j + 1) . ']');
        }
        if (!$cats) {
            $this->fail("$at.cats", 'pick at least one service');
        }
        $sector = $this->str($w['sector'] ?? null, "$at.sector", true, 40);
        $sectors = array_keys($this->taxonomy()['sectors'] ?? []);
        if ($sector !== '' && $sectors && !in_array($sector, $sectors, true)) {
            $this->fail("$at.sector", "unknown sector \"$sector\" (add it under Sectors & categories first)");
        }
        return [
            'img'    => $this->image($w['img'] ?? null, "$at.img"),
            'title'  => $this->pair($w['title'] ?? null, "$at.title", true, 120),
            'sub'    => $this->pair($w['sub'] ?? null, "$at.sub", false, 160),
            'cats'   => array_values(array_unique($cats)),
            'sector' => $sector,
        ] + $this->extra($w, ['img', 'title', 'sub', 'cats', 'sector']);
    }

    private function reelItem(array $s, string $at): array
    {
        return [
            'img' => $this->image($s['img'] ?? null, "$at.img"),
            'cap' => $this->pair($s['cap'] ?? null, "$at.cap", true, 120),
        ] + $this->extra($s, ['img', 'cap']);
    }

    private function clients(mixed $data): array
    {
        $data = is_array($data) ? $data : [];
        $logos = $this->list($data['logos'] ?? null, 'logos', function (array $c, string $at): array {
            $logo = [
                'name' => $this->str($c['name'] ?? null, "$at.name", true, 120),
                'file' => $this->image($c['file'] ?? null, "$at.file", 'img/clients'),
                'w'    => $this->int($c['w'] ?? null, "$at.w", 1, 4000),
                'h'    => $this->int($c['h'] ?? null, "$at.h", 1, 4000),
            ];
            if (isset($c['s']) && $c['s'] !== '' && (float) $c['s'] != 1.0) {
                $s = (float) $c['s'];
                if ($s < 0.3 || $s > 3) {
                    $this->fail("$at.s", 'scale must be between 0.3 and 3');
                }
                $logo['s'] = round($s, 2);
            }
            return $logo + $this->extra($c, ['name', 'file', 'w', 'h', 's']);
        });
        return ['version' => $this->int($data['version'] ?? 1, 'version', 1, 1000000), 'logos' => $logos] + $this->extra($data, ['version', 'logos']);
    }

    private function services(mixed $data): array
    {
        $ids = [];
        return $this->list($data, 'services', function (array $s, string $at) use (&$ids): array {
            $id = $this->slug($s['id'] ?? null, "$at.id");
            if (in_array($id, $ids, true)) {
                $this->fail("$at.id", "\"$id\" is used twice");
            }
            $ids[] = $id;
            $tags = ['en' => [], 'ar' => []];
            foreach (['en', 'ar'] as $lang) {
                foreach ((array) ($s['tags'][$lang] ?? []) as $j => $tag) {
                    $tags[$lang][] = $this->str($tag, "{$at}.tags.{$lang}[" . ($j + 1) . ']', true, 80);
                }
            }
            $out = ['id' => $id, 'img' => $this->image($s['img'] ?? null, "$at.img")];
            if (!empty($s['mock'])) {
                $out['mock'] = $this->str($s['mock'], "$at.mock", false, 20);
            }
            return $out + [
                'title' => $this->pair($s['title'] ?? null, "$at.title", true, 120),
                'lead'  => $this->pair($s['lead'] ?? null, "$at.lead", false, 400),
                'tags'  => $tags,
            ] + $this->extra($s, ['id', 'img', 'mock', 'title', 'lead', 'tags']);
        });
    }

    private function article(array $a, string $at): array
    {
        return [
            'img'   => $this->image($a['img'] ?? null, "$at.img"),
            'cat'   => $this->category($a['cat'] ?? null, "$at.cat"),
            'mins'  => $this->int($a['mins'] ?? 5, "$at.mins", 1, 120),
            'title' => $this->pair($a['title'] ?? null, "$at.title", true, 200),
        ] + $this->extra($a, ['img', 'cat', 'mins', 'title']);
    }

    private function taxonomyFile(mixed $data): array
    {
        $data = is_array($data) ? $data : [];
        $out = [];
        foreach (['sectors', 'categories'] as $group) {
            $clean = [];
            foreach ((array) ($data[$group] ?? []) as $key => $label) {
                $clean[$this->slug((string) $key, "$group.$key")] = $this->pair($label, "$group.$key", true, 60);
            }
            $out[$group] = $clean ?: new stdClass();
        }
        return $out + $this->extra($data, ['sectors', 'categories']);
    }
}

/* --------------------------------------------------------------------------
   Image uploads
   -------------------------------------------------------------------------- */

/**
 * Validate an uploaded image, resize it for the web, and save it.
 * kind 'photo' → img/ as JPEG (max 1600×2000); kind 'logo' → img/clients/ as PNG (max 480 wide, keeps transparency).
 * Returns ['file' => name, 'folder' => dir, 'w' => width, 'h' => height].
 */
function save_upload(array $upload, string $kind): array
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($upload['tmp_name'] ?? ''))) {
        throw new ApiError(($upload['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'That file is larger than the server allows.' : 'The upload did not arrive. Try again.');
    }
    if ((int) $upload['size'] > 20 * 1024 * 1024) {
        throw new ApiError('Images must be under 20 MB.');
    }
    $tmp = (string) $upload['tmp_name'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $info = @getimagesize($tmp);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || !$info) {
        throw new ApiError('Upload a JPG, PNG or WebP image.');
    }
    $isLogo = $kind === 'logo';
    $dir = $isLogo ? 'img/clients' : 'img';
    [$maxW, $maxH] = $isLogo ? [480, 480] : [1600, 2000];
    [$bytes, $w, $h, $ext] = resize_image($tmp, $mime, $maxW, $maxH, $isLogo);

    $base = strtolower(pathinfo((string) ($upload['name'] ?? 'image'), PATHINFO_FILENAME));
    $base = trim(preg_replace('/[^a-z0-9]+/', '-', $base) ?? '', '-') ?: 'image';
    $base = substr($base, 0, 60);
    $existing = store()->listImages($dir);
    if (store() instanceof GitHubStore) {
        $existing = array_merge($existing, recent_uploads($dir));
    }
    $name = "$base.$ext";
    for ($n = 2; in_array($name, $existing, true); $n++) {
        $name = "$base-$n.$ext";
    }
    store()->writeBinary("$dir/$name", $bytes, "Admin: add image $dir/$name");
    if (store() instanceof GitHubStore) {
        recent_uploads($dir, $name);
    }
    return ['file' => $name, 'folder' => $dir, 'w' => $w, 'h' => $h];
}

/** Returns [bytes, width, height, extension]. Without GD the original is kept as-is. */
function resize_image(string $path, string $mime, int $maxW, int $maxH, bool $keepAlpha): array
{
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
    [$w, $h] = getimagesize($path);
    if (!function_exists('imagecreatetruecolor')) {
        return [(string) file_get_contents($path), $w, $h, $ext];
    }
    $src = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png'  => @imagecreatefrompng($path),
        'image/webp' => @imagecreatefromwebp($path),
    };
    if (!$src) {
        throw new ApiError('That image could not be read. Try exporting it again as JPG or PNG.');
    }
    // Phone photos store rotation in EXIF; apply it so the picture isn't sideways.
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $orientation = (int) (@exif_read_data($path)['Orientation'] ?? 1);
        $rotated = match ($orientation) { 3 => imagerotate($src, 180, 0), 6 => imagerotate($src, -90, 0), 8 => imagerotate($src, 90, 0), default => null };
        if ($rotated) {
            imagedestroy($src);
            $src = $rotated;
        }
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1, $maxW / $w, $maxH / $h);
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    if ($keepAlpha) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    } else {
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    ob_start();
    if ($keepAlpha) {
        imagepng($dst, null, 9);
        $ext = 'png';
    } else {
        imageinterlace($dst, true);
        imagejpeg($dst, null, 82);
        $ext = 'jpg';
    }
    $bytes = (string) ob_get_clean();
    imagedestroy($src);
    imagedestroy($dst);
    return [$bytes, $nw, $nh, $ext];
}
