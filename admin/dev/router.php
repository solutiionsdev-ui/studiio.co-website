<?php
/**
 * Local development router for PHP's built-in server. Not used on Hostinger.
 *
 *   php -S localhost:4380 admin/dev/router.php      (run from the repo root)
 *
 * Serves the site and /admin like the live server would, and blocks the same
 * private paths that .htaccess blocks in production.
 */
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

// Normalise the raw path the way the file lookup will see it: no query, decoded,
// repeated slashes collapsed and "." segments removed (parse_url would read //x as a host).
$path = rawurldecode(explode('?', $_SERVER['REQUEST_URI'] ?? '/', 2)[0]);
$path = '/' . implode('/', array_filter(explode('/', str_replace('\\', '/', $path)), static fn($s) => $s !== '' && $s !== '.'));

if (str_contains($path, '..')
    || preg_match('#^/(\.private|\.git|\.github|\.claude|\.data)(/|$)#', $path)
    || preg_match('#^/admin/(lib|dev|tools|crm)(/|$)#', $path)
    || preg_match('#^/admin/config[^/]*\.php$#', $path)
    || preg_match('#/\.ht#', $path)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo "Not found\n";
    return true;
}

if (str_starts_with($path, '/content/') && str_ends_with($path, '.json')) {
    header('Cache-Control: no-cache');
}

return false; // let the built-in server serve the file or run the .php
