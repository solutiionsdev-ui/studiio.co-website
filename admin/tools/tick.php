<?php
/**
 * Run the Pipeline's timed automation rules (reminders, testimonial, check-in…).
 * The admin also runs them when it's opened; this is for a cron job, e.g. hourly on Hostinger:
 *
 *   php /home/<user>/domains/studiio.co/public_html/admin/tools/tick.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/lib/crm.php';
if (!is_configured()) {
    fwrite(STDERR, "The admin is not set up on this machine.\n");
    exit(1);
}
echo 'Drafted or noted: ', crm_auto_tick(), "\n";
