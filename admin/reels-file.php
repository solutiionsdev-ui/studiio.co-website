<?php
/**
 * STUDIIO.CO admin — files for the reel editor, read from Reels Montage PRO on this Mac:
 * the engine's overlay page (with the reel's data added, for the live preview), its component
 * bank and gallery, and a reel's video, person masks, photos, preview sheet and final render.
 *
 *   reels-file.php/<key>/bank/<file>
 *   reels-file.php/<key>/projects/<slug>/<file>
 *
 * The key (reels_token(), valid about 12 hours, handed out to the signed-in admin) sits in the
 * path, so relative links inside the overlay keep working and the sandboxed preview frames,
 * which send no cookies, can load what they need. Only the listed kinds of files are served.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/reels.php';

function reels_file_fail(int $code = 404): never
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $code === 403 ? "Forbidden\n" : "Not found\n";
    exit;
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Robots-Tag: noindex, nofollow');

if (!is_configured() || !reels_enabled()) {
    reels_file_fail();
}

$info = (string) ($_SERVER['PATH_INFO'] ?? '');
if ($info === '') {
    $uri = rawurldecode(explode('?', (string) ($_SERVER['REQUEST_URI'] ?? ''), 2)[0]);
    $at = strpos($uri, '/reels-file.php/');
    $info = $at === false ? '' : substr($uri, $at + strlen('/reels-file.php'));
}
$parts = explode('/', ltrim($info, '/'));
$token = array_shift($parts) ?? '';
if (!reels_token_ok($token)) {
    reels_file_fail(403);
}
foreach ($parts as $p) {
    if ($p === '' || $p[0] === '.' || str_contains($p, "\0") || str_contains($p, '\\')) {
        reels_file_fail();
    }
}
$rel = implode('/', $parts);
$root = reels_root();

$types = [
    'js' => 'text/javascript; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'html' => 'text/html; charset=utf-8',
    'json' => 'application/json', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
    'webp' => 'image/webp', 'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'mp4' => 'video/mp4',
];
$ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
$slug = null;
if (str_starts_with($rel, 'bank/')) {
    if (!isset($types[$ext]) || $ext === 'mp4') {
        reels_file_fail();
    }
    $cache = 'private, max-age=3600';
} elseif (preg_match('#^projects/([a-z0-9][a-z0-9-]{0,62})/(overlay\.html|preview\.jpg|out\.mp4|src/main\.mp4|photos/[A-Za-z0-9._-]+\.(?:jpe?g|png|webp)|masks/m_\d{5}\.png)$#', $rel, $m)) {
    $slug = $m[1];
    $cache = str_starts_with($m[2], 'masks/') || str_starts_with($m[2], 'photos/') ? 'private, max-age=86400' : 'no-cache';
} else {
    reels_file_fail();
}

$path = realpath("$root/$rel");
if ($path === false || !is_file($path) || !str_starts_with($path, $root . '/')) {
    reels_file_fail();
}

$origin = (is_https() ? 'https://' : 'http://') . preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));

if ($ext === 'html') {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'self' $origin; script-src 'self' $origin 'unsafe-inline'; style-src 'self' $origin 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com data:; img-src 'self' $origin data: blob:; media-src 'self' $origin; connect-src 'none'; frame-ancestors 'self' $origin; base-uri 'none'; form-action 'none'; object-src 'none'");
    $html = (string) file_get_contents($path);
    $layer = (string) ($_GET['layer'] ?? '');
    if ($slug !== null && in_array($layer, ['front', 'back'], true)) {
        // Live preview: the data engine/common.py's page_setup() injects for a render, plus a
        // message hook so the admin page can move the time and swap in unsaved beats.
        $dir = "$root/projects/$slug";
        $load = static fn(string $f, mixed $d) => is_file("$dir/$f") ? json_decode((string) file_get_contents("$dir/$f"), true) ?? $d : $d;
        $proj = $load('project.json', []);
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP;
        $data = '<script>window.WORDS=' . json_encode($load('words2.json', []), $flags)
            . ';window.DUR=' . json_encode((float) ($proj['dur'] ?? 0))
            . ';window.FACES=' . json_encode($load('faces_s.json', []), $flags)
            . ';window.HEADS=' . json_encode($load('heads.json', []), $flags)
            . ';window.SHOTS=' . json_encode($load('shots.json', [0]), $flags)
            . ";window.LAYER='$layer';window.STUDIIO_LIVE=1;</script>";
        $hook = "<script>(function(){addEventListener('message',function(e){if(e.source!==parent)return;var d=e.data||{};"
            . "try{if(d.type==='apply'&&window.STUDIIO_APPLY)STUDIIO_APPLY(d.doc);if(typeof d.t==='number')render(d.t);"
            . "if(d.type==='probe'){var r=document.getElementById('root');parent.postMessage({type:'studiio-probe',layer:LAYER,html:r.innerHTML.length,text:r.innerText.replace(/\\s+/g,' ').slice(0,300)},'*')}}"
            . "catch(err){parent.postMessage({type:'studiio-error',layer:LAYER,msg:String(err&&err.message||err)},'*')}});"
            . "Promise.all(['700 100px Antonio','400 50px Inter','600 50px Inter','400 50px \"IBM Plex Mono\"','500 50px \"IBM Plex Mono\"','900 50px Doto'].map(function(f){return document.fonts.load(f).catch(function(){})}))"
            . ".then(function(){parent.postMessage({type:'studiio-ready',layer:LAYER,effects:window.STUDIIO_EFFECTS||[]},'*')})})();</script>";
        $html = preg_replace('#<head>#i', '<head>' . $data, $html, 1) ?? $html;
        $html = preg_replace('#</body>#i', $hook . '</body>', $html, 1) ?? $html;
        $html = str_replace('background:transparent', 'background:transparent;color-scheme:normal', $html);
    }
    echo $html;
    exit;
}

$size = (int) filesize($path);
$mtime = (int) filemtime($path);
$etag = '"' . dechex($mtime) . '-' . dechex($size) . '"';
header('Content-Type: ' . $types[$ext]);
header('Accept-Ranges: bytes');
header("ETag: $etag");
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header("Cache-Control: $cache");
if ($slug !== null && !empty($_GET['download'])) {
    $title = reel_meta($slug)['title'];
    $name = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $title) ?? '', '-') ?: $slug;
    header('Content-Disposition: attachment; filename="' . $name . '.' . $ext . '"');
}
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}
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
    // Answer open-ended video ranges in 2 MB pieces: the browser asks for the next piece itself,
    // and the small local PHP server never has a worker tied up streaming a whole file.
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
