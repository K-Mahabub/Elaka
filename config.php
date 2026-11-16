<?php
/**
 * Elaka configuration.
 * Adjust the database credentials to match your MySQL setup, then open install.php once.
 */
return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3307, // XAMPP MariaDB (3306 is used by the MySQL80 service)
        'name'    => 'elaka',
        'user'    => 'elaka',
        'pass'    => 'elaka_pass',
        'charset' => 'utf8mb4',
    ],

    'app_name' => 'Elaka',
    'timezone' => 'Asia/Dhaka',

    // Radius choices (metres) offered to viewers and posters.
    'radius_options' => [500, 1000, 2000, 5000],
    'default_radius' => 1000,
    // Viewers may also type any custom viewing radius within these limits (metres).
    'radius_min' => 100,
    'radius_max' => 50000,

    // When true, users may pick their location on a map (handy for desktop demos).
    // Set to false to require real GPS / browser geolocation.
    'allow_manual_location' => true,

    // Location older than this many hours is flagged as stale.
    'location_stale_hours' => 6,

    // Watch areas (location-based notifications).
    'max_watch_areas'      => 5,
    'watch_area_max_km'    => 10, // centre must be within this distance of you

    // Uploads.
    'upload_dir'       => __DIR__ . '/uploads',
    'upload_url'       => 'uploads',
    'upload_max_bytes' => 3 * 1024 * 1024,

    // Spam protection.
    'rate_limit_posts'    => 5,   // posts ...
    'rate_limit_comments' => 20,  // comments ...
    'rate_limit_minutes'  => 10,  // ... per this many minutes
    'max_links_per_post'  => 3,

    // Moderation: a post is auto-hidden after this many distinct user reports.
    'auto_hide_reports' => 3,

    // Lost Pet Tracking Board.
    'pets' => [
        'max_photos'      => 5,   // photos per report
        'max_report_km'   => 10,  // last-seen / sighting point must be this close to you
        'match_km'        => 5,   // similar-pet matching looks this far around a report
        'match_days'      => 90,  // ... and this many days back
        'match_show'      => 35,  // minimum score (0–100) to list a possible match
        'match_notify'    => 55,  // minimum score to notify the other report's owner
    ],
    // Community Marketplace.
    'marketplace' => [
        'max_photos'  => 6,   // photos per listing
        'max_km'      => 10,  // pick-up point must be this close to you
        'expiry_days' => 30,  // listings expire after this many days (sellers can renew)
        'sold_days'   => 7,   // sold listings are cleared this many days after being marked sold
    ],
    // Absolute base URL for share links / QR codes, e.g. 'https://elaka.example'. Empty = detect.
    'base_url' => '',
];
