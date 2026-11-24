<?php
/**
 * STUDIIO.CO admin — page shell. The app itself is admin/assets/admin.js,
 * which talks to admin/api.php. Sign-in is checked by the API on every call.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

security_headers();
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data: blob:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'; object-src 'none'");

$asset = static fn(string $file): string => 'assets/' . $file . '?v=' . (@filemtime(__DIR__ . '/assets/' . $file) ?: 1);
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>STUDIIO.CO — Admin</title>
  <link rel="icon" href="../favicon.svg" type="image/svg+xml">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans+Arabic:wght@400;500;600&display=swap">
  <link rel="stylesheet" href="<?= htmlspecialchars($asset('admin.css')) ?>">
</head>
<body>
<?php if (!is_configured()): ?>
  <main class="setup">
    <img class="setup-logo" src="../img/studiio-logo.svg" alt="STUDIIO.CO">
    <h1>Admin isn't set up yet</h1>
    <p>Create the admin login on this machine by running, from the website folder:</p>
    <pre>php admin/tools/setup.php</pre>
    <p class="muted">It saves a private config file outside the public site. See <code>admin/README.md</code>.</p>
  </main>
<?php else: ?>
  <div id="app" class="boot" aria-live="polite"><p class="boot-msg">Loading…</p></div>
  <script type="module" src="<?= htmlspecialchars($asset('admin.js')) ?>"></script>
<?php endif; ?>
</body>
</html>
