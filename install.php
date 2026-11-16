<?php
/**
 * One-time installer: creates the tables, an admin account and (optionally) demo posts.
 * Usage: open /install.php in the browser, or run `php install.php [--demo LAT LNG]` from the CLI.
 * Delete or protect this file after installing on a public server.
 */
define('SKIP_MAINTENANCE', true);
require __DIR__ . '/includes/bootstrap.php';

function run_install(bool $demo, float $lat, float $lng): array
{
    $log = [];
    $c = config('db');

    // Create the database itself if the account is allowed to.
    try {
        $pdo = new PDO(sprintf('mysql:host=%s;port=%d;charset=%s', $c['host'], $c['port'], $c['charset']), $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $c['name']) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $log[] = 'Database `' . $c['name'] . '` ready.';
    } catch (PDOException $e) {
        throw new RuntimeException('Cannot connect to MySQL: ' . $e->getMessage());
    }

    $sql = file_get_contents(__DIR__ . '/schema.sql');
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if (!preg_match('/^(--[^\n]*\n\s*)*$/', $stmt)) {
            db()->exec($stmt);
        }
    }
    $log[] = 'Tables created.';

    if (!q("SELECT 1 FROM users WHERE email = 'admin@elaka.local'")->fetchColumn()) {
        q(
            "INSERT INTO users (name, email, password_hash, role) VALUES ('Elaka Admin', 'admin@elaka.local', ?, 'admin')",
            [password_hash('admin123', PASSWORD_DEFAULT)]
        );
        $log[] = 'Admin account created: admin@elaka.local / admin123 (change this password!).';
    } else {
        $log[] = 'Admin account already exists.';
    }

    if ($demo) {
        $log[] = seed_demo($lat, $lng) . ' demo posts added around ' . $lat . ', ' . $lng . '.';
    }
    return $log;
}

/** Offset a point by metres north/east. */
function offset(float $lat, float $lng, float $north, float $east): array
{
    return [$lat + $north / 111320, $lng + $east / (111320 * cos(deg2rad($lat)))];
}

function seed_demo(float $lat, float $lng): int
{
    $users = [
        ['Rahim Uddin', 'rahim@elaka.local', 'trusted'],
        ['Nusrat Jahan', 'nusrat@elaka.local', 'user'],
        ['Karim Store', 'karim@elaka.local', 'user'],
        ['Moderator Mitu', 'mod@elaka.local', 'moderator'],
    ];
    $ids = [];
    foreach ($users as [$name, $email, $role]) {
        $id = q('SELECT id FROM users WHERE email = ?', [$email])->fetchColumn();
        if (!$id) {
            q('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)', [$name, $email, password_hash('password123', PASSWORD_DEFAULT), $role]);
            $id = db()->lastInsertId();
        }
        $ids[] = (int)$id;
    }
    [$rahim, $nusrat, $karim, $mod] = $ids;
    $h = fn(int $hours) => date('Y-m-d H:i:s', time() + $hours * 3600);

    $posts = [
        // [user, category, subtype, title, body, north, east, radius, extra]
        [$rahim, 'alert', 'roadblock', 'Road closed near the bazaar for pipe repair', 'The main road beside the bazaar is dug up for WASA pipe work. Use the lane behind the mosque instead. Expected to reopen tonight.', 150, -120, 2000, ['expires_at' => $h(12), 'is_verified' => 1, 'verified_by' => $mod]],
        [$nusrat, 'alert', 'power_outage', 'Power outage in Block C since 3 pm', 'No electricity in Block C. DESCO says a transformer tripped; crew is on the way.', -300, 200, 1000, ['expires_at' => $h(8)]],
        [$karim, 'event', 'market', 'Weekend winter fair at the school field', 'Pitha, handicrafts, games for kids. Everyone welcome! Stalls still available — contact me.', 400, 350, 2000, ['event_start' => $h(48), 'event_end' => $h(56), 'expires_at' => $h(56), 'location_text' => 'Govt. Primary School field', 'contact' => '01700-000000']],
        [$rahim, 'event', 'volunteer', 'Neighbourhood clean-up drive on Friday', 'Bring gloves if you have them; bags will be provided. Meet at the playground.', -100, -400, 1000, ['event_start' => $h(72), 'event_end' => $h(75), 'expires_at' => $h(75), 'location_text' => 'Central playground']],
        [$nusrat, 'lost_found', 'lost', 'Lost: black wallet near the bus stand', 'Black leather wallet with NID card. Reward offered. Please comment or call.', 250, 50, 2000, ['expires_at' => $h(720), 'location_text' => 'Bus stand / tea stall area', 'contact' => 'Comment here']],
        [$karim, 'lost_found', 'found', 'Found a set of keys with a blue keyring', 'Found this morning on Road 4. Describe the keyring to claim.', -50, 80, 1000, ['expires_at' => $h(720)]],
        [$nusrat, 'marketplace', 'sell', 'Selling used study table — good condition', 'Wooden study table, 4 ft, two drawers. Pick-up only.', 600, -200, 5000, ['price' => 2500, 'expires_at' => $h(720), 'contact' => 'Message in comments']],
        [$karim, 'marketplace', 'free', 'Free: old textbooks for class 8', 'Bangla and English medium books. First come, first served.', 80, 60, 2000, ['price' => 0, 'expires_at' => $h(720)]],
        [$karim, 'service', 'shop', 'Karim General Store — open late', 'Groceries, mobile recharge, bKash. Home delivery within 1 km.', 30, -30, 2000, ['location_text' => 'House 12, Road 3', 'business_hours' => 'Daily 7am – 11pm', 'contact' => '01800-000000']],
        [$rahim, 'service', 'repair', 'Reliable mobile & laptop repair', 'Screen, battery and software fixes. Same-day service for most phones.', -350, -250, 5000, ['location_text' => 'Market lane, shop 7', 'business_hours' => 'Sat–Thu 10am – 9pm']],
        [$nusrat, 'news', null, 'New streetlights installed on Lake Road', 'City corporation installed 12 new LED streetlights. Walking at night feels much safer now.', 500, 500, 2000, ['expires_at' => $h(168)]],
        [$rahim, 'discussion', null, 'Should we ask for a speed breaker near the school?', 'Bikes go very fast past the school gate during pick-up time. What do you all think?', 200, 300, 2000, []],
        [$nusrat, 'discussion', null, 'Stray dogs getting aggressive at night', 'Several people were chased near the canal. Anyone know who to contact?', -200, 100, 1000, ['is_anonymous' => 1]],
        [$rahim, 'poll', null, 'Best time for the monthly community meeting?', 'Let us pick a time that works for most people.', 100, -100, 2000, ['expires_at' => $h(168), 'poll' => ['Friday after Jumma', 'Saturday evening', 'Sunday morning']]],
        // Far away (3.5 km): only visible with the 5 km radius.
        [$karim, 'news', null, 'New bridge opening across the river', 'The new bridge in the next union opens next week.', 3500, 0, 5000, ['expires_at' => $h(168)]],
    ];

    $n = 0;
    foreach ($posts as [$uid, $cat, $sub, $title, $body, $north, $east, $radius, $x]) {
        if (q('SELECT 1 FROM posts WHERE title = ?', [$title])->fetchColumn()) {
            continue;
        }
        [$plat, $plng] = offset($lat, $lng, $north, $east);
        q(
            'INSERT INTO posts (user_id, category, subtype, title, body, lat, lng, radius_m, is_anonymous, is_verified, verified_by,
                                verified_at, price, contact, location_text, business_hours, event_start, event_end, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW() - INTERVAL ? MINUTE)',
            [
                $uid, $cat, $sub, $title, $body, $plat, $plng, $radius, $x['is_anonymous'] ?? 0,
                $x['is_verified'] ?? 0, $x['verified_by'] ?? null, isset($x['is_verified']) ? date('Y-m-d H:i:s') : null,
                $x['price'] ?? null, $x['contact'] ?? null, $x['location_text'] ?? null, $x['business_hours'] ?? null,
                $x['event_start'] ?? null, $x['event_end'] ?? null, $x['expires_at'] ?? null, random_int(5, 600),
            ]
        );
        $pid = (int)db()->lastInsertId();
        foreach ($x['poll'] ?? [] as $opt) {
            q('INSERT INTO poll_options (post_id, label) VALUES (?, ?)', [$pid, $opt]);
        }
        if ($cat === 'discussion' && $n % 2 === 1) {
            q('INSERT INTO comments (post_id, user_id, body) VALUES (?, ?, ?)', [$pid, $karim, 'Agreed — I can help collect signatures.']);
        }
        q('INSERT IGNORE INTO reactions (post_id, user_id, type) VALUES (?, ?, ?)', [$pid, $nusrat === $uid ? $rahim : $nusrat, 'helpful']);
        $n++;
    }
    return $n;
}

/* ---------- CLI ---------- */
if (PHP_SAPI === 'cli') {
    $demo = in_array('--demo', $argv, true);
    $i = array_search('--demo', $argv, true);
    $lat = $demo && isset($argv[$i + 1]) ? (float)$argv[$i + 1] : 23.8103;
    $lng = $demo && isset($argv[$i + 2]) ? (float)$argv[$i + 2] : 90.4125;
    try {
        foreach (run_install($demo, $lat, $lng) as $line) {
            echo "✓ $line\n";
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'Install failed: ' . $e->getMessage() . "\n");
        exit(1);
    }
    exit(0);
}

/* ---------- Web ---------- */
$log = [];
$error = '';
$installed = false;
try {
    $c = config('db');
    $probe = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s', $c['host'], $c['port'], $c['name']), $c['user'], $c['pass']);
    $installed = (bool)$probe->query("SHOW TABLES LIKE 'users'")->fetchColumn();
} catch (Throwable $e) {
    // database not created yet, or wrong credentials: run_install() reports the details
}

if (is_post()) {
    verify_csrf();
    $lat = is_numeric($_POST['lat'] ?? null) ? (float)$_POST['lat'] : 23.8103;
    $lng = is_numeric($_POST['lng'] ?? null) ? (float)$_POST['lng'] : 90.4125;
    try {
        $log = run_install(!empty($_POST['demo']), $lat, $lng);
        $installed = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$pageTitle = 'Install';
require __DIR__ . '/includes/header.php';
?>
<section class="auth-wrap">
    <div class="card auth-card wide">
        <h1>Install Elaka</h1>
        <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
        <?php foreach ($log as $line): ?><div class="flash flash-success">✓ <?= e($line) ?></div><?php endforeach; ?>

        <?php if ($log): ?>
            <p><a class="btn btn-primary" href="login.php">Go to login</a></p>
            <p class="muted small">Demo users (password <code>password123</code>): rahim@ (trusted), nusrat@, karim@, mod@ (moderator) — all <code>@elaka.local</code>.
                For security, delete <code>install.php</code> on a public server.</p>
        <?php else: ?>
            <p><?= $installed ? 'Elaka is already installed. Running again is safe (existing data is kept).' : 'This will create the database tables and an admin account.' ?></p>
            <form method="post" class="form">
                <?= csrf_field() ?>
                <label class="check"><input type="checkbox" name="demo" value="1" checked>
                    <span>Add demo users and posts around this location</span></label>
                <div class="grid-2">
                    <label>Latitude <input type="text" name="lat" id="i_lat" value="23.8103"></label>
                    <label>Longitude <input type="text" name="lng" id="i_lng" value="90.4125"></label>
                </div>
                <p class="small"><button class="linklike" type="button" data-fill-coords="#i_lat,#i_lng">📡 Use my current location for demo data</button></p>
                <button class="btn btn-primary" type="submit">Install</button>
            </form>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
