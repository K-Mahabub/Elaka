<?php
//
// Post expiration job. Run every few minutes, e.g. crontab:
//   */5 * * * * php /path/to/elaka/cron.php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
define('SKIP_MAINTENANCE', true);
require __DIR__ . '/includes/bootstrap.php';

$n = cleanup_expired_posts();
echo date('c') . " removed $n expired post(s)\n";
