<?php
/**
 * Check every content/*.json file with the same rules the admin uses on save.
 *
 *   php admin/tools/check-content.php           report problems (exit code 1 if any)
 *   php admin/tools/check-content.php --format  also rewrite files in the standard format
 *
 * Run it after editing content by hand or with Claude Code.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/lib/content.php';

$format = in_array('--format', $argv, true);
$store = new LocalStore(REPO_DIR);
$failed = false;

foreach (array_keys(CONTENT_FILES) as $name) {
    $rel = "content/$name.json";
    $file = $store->read($rel);
    if ($file === null) {
        echo "✗ $rel is missing\n";
        $failed = true;
        continue;
    }
    try {
        $data = json_decode($file['text'], true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        echo "✗ $rel is not valid JSON: {$e->getMessage()}\n";
        $failed = true;
        continue;
    }
    $v = new ContentValidator($store);
    $clean = $v->validate($name, $data);
    $canonical = encode_json($clean);
    if ($v->errors) {
        $failed = true;
        echo "✗ $rel\n";
        foreach ($v->errors as $e) {
            echo "    error: $e\n";
        }
    } else {
        echo "✓ $rel" . ($canonical !== $file['text'] ? ($format ? ' (reformatted)' : ' (not in standard format; run with --format)') : '') . "\n";
        if ($format && $canonical !== $file['text']) {
            file_put_contents(REPO_DIR . '/' . $rel, $canonical);
        }
    }
    foreach ($v->warnings as $w) {
        echo "    note: $w\n";
    }
}

exit($failed ? 1 : 0);
