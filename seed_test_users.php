<?php
/**
 * Creates the friendships table (if missing) and 10 test users with some friendships and requests.
 * Usage: `php seed_test_users.php` or open /seed_test_users.php in the browser.
 * Safe to run more than once. Delete this file on a public server.
 */
define('SKIP_MAINTENANCE', true);
require __DIR__ . '/includes/bootstrap.php';

const TEST_PASSWORD = 'password123';

if (PHP_SAPI !== 'cli' && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Run this from localhost only.');
}

function seed_test_users(): array
{
    $log = [];

    // Make sure every table (including the new `friendships`) exists.
    $sql = file_get_contents(__DIR__ . '/schema.sql');
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if (!preg_match('/^(--[^\n]*\n\s*)*$/', $stmt)) {
            db()->exec($stmt);
        }
    }
    $log[] = 'Tables ready (friendships included).';

    $users = [
        ['Ayesha Rahman',  'test1@elaka.local'],
        ['Tanvir Hossain', 'test2@elaka.local'],
        ['Farhana Akter',  'test3@elaka.local'],
        ['Sakib Hasan',    'test4@elaka.local'],
        ['Mim Chowdhury',  'test5@elaka.local'],
        ['Rafi Ahmed',     'test6@elaka.local'],
        ['Sumaiya Islam',  'test7@elaka.local'],
        ['Imran Kabir',    'test8@elaka.local'],
        ['Nadia Sultana',  'test9@elaka.local'],
        ['Arif Mahmud',    'test10@elaka.local'],
    ];

    $ids = [];
    $created = 0;
    $hash = password_hash(TEST_PASSWORD, PASSWORD_DEFAULT);
    foreach ($users as $i => [$name, $email]) {
        $id = q('SELECT id FROM users WHERE email = ?', [$email])->fetchColumn();
        if (!$id) {
            q(
                "INSERT INTO users (name, email, password_hash, role, created_at) VALUES (?, ?, ?, 'user', NOW() - INTERVAL ? DAY)",
                [$name, $email, $hash, ($i + 1) * 7]
            );
            $id = db()->lastInsertId();
            $created++;
        }
        $ids[$i + 1] = (int)$id;
    }
    $log[] = "$created new test users created (" . (count($users) - $created) . ' already existed).';

    // Some accepted friendships and pending requests so the feature has something to show.
    $pairs = [
        // [from, to, status]
        [1, 2, 'accepted'], [1, 3, 'accepted'], [2, 3, 'accepted'], [2, 4, 'accepted'],
        [3, 5, 'accepted'], [4, 6, 'accepted'], [5, 6, 'accepted'], [7, 8, 'accepted'],
        [8, 9, 'accepted'], [9, 10, 'accepted'], [1, 6, 'accepted'],
        [4, 1, 'pending'], [5, 1, 'pending'], [10, 2, 'pending'], [6, 7, 'pending'], [3, 9, 'pending'],
    ];
    $made = 0;
    foreach ($pairs as [$a, $b, $status]) {
        if (friendship_row($ids[$a], $ids[$b])) {
            continue;
        }
        q(
            'INSERT INTO friendships (requester_id, addressee_id, status, accepted_at) VALUES (?, ?, ?, ?)',
            [$ids[$a], $ids[$b], $status, $status === 'accepted' ? date('Y-m-d H:i:s') : null]
        );
        $made++;
    }

    // Send the admin a few requests so they can try Confirm / Delete straight away.
    $admin = q("SELECT id FROM users WHERE email = 'admin@elaka.local'")->fetchColumn();
    if ($admin) {
        foreach ([1, 2, 3] as $i) {
            if (!friendship_row($ids[$i], (int)$admin)) {
                q('INSERT INTO friendships (requester_id, addressee_id) VALUES (?, ?)', [$ids[$i], (int)$admin]);
                $made++;
            }
        }
    }
    $log[] = "$made friendships / requests added.";
    $log[] = 'Log in as test1@elaka.local … test10@elaka.local with password ' . TEST_PASSWORD . '.';
    return $log;
}

try {
    $lines = seed_test_users();
} catch (Throwable $e) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Seeding failed: ' . $e->getMessage() . "\n");
        exit(1);
    }
    $lines = ['Seeding failed: ' . $e->getMessage()];
}

if (PHP_SAPI === 'cli') {
    foreach ($lines as $l) {
        echo "✓ $l\n";
    }
    exit(0);
}
header('Content-Type: text/plain; charset=utf-8');
echo implode("\n", $lines), "\n";
