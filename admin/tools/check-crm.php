<?php
/**
 * Check the client system's settings and templates (admin/crm/<business>/) with the same
 * rules the admin applies when it reads them.
 *
 *   php admin/tools/check-crm.php        report problems (exit code 1 if any)
 *
 * Run it after editing settings.json or a template by hand or with Claude Code.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/lib/crm.php';

$failed = false;
$report = static function (string $rel, array $errors) use (&$failed): void {
    if ($errors) {
        $failed = true;
        echo "✗ $rel\n" . implode('', array_map(static fn($e) => "  - $e\n", $errors));
    } else {
        echo "✓ $rel\n";
    }
};
$base = 'admin/crm/' . CRM_BUSINESS;

try {
    $settings = crm_read_json('settings.json');
    $report("$base/settings.json", crm_settings_errors($settings));
} catch (ApiError $e) {
    $report("$base/settings.json", [$e->getMessage()]);
    exit(1);
}

try {
    $report("$base/templates/en/checklist.json", crm_checklist_errors(crm_read_json('templates/en/checklist.json'), (array) ($settings['tracks'] ?? [])));
} catch (ApiError $e) {
    $report("$base/templates/en/checklist.json", [$e->getMessage()]);
}

exit($failed ? 1 : 0);
