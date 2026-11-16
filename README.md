# Elaka — A Location-Based Social Platform

Elaka connects people through their physical surroundings. Posts are pinned to where they were
created and can only be **seen and created within a chosen radius** (500 m, 1 km, 2 km or 5 km).

Built with plain **PHP 8 + MySQL (PDO)**, Leaflet/OpenStreetMap maps and the browser Geolocation API.
It has no framework and no build step.

## Features

| Feature | Where |
|---|---|
| Location-based posts (radius-limited create & view) | `post_create.php`, `fetch_nearby_posts()` in `includes/posts.php` |
| Nearby feed with category tabs, search, sort, map | `index.php` |
| Local alerts (roadblock, power, emergency, safety…) + alert strip | category `alert` |
| Community events (start/end time, venue, "upcoming" sort) | category `event` |
| Lost & Found | category `lost_found` |
| Local marketplace (sell / wanted / exchange / free) | category `marketplace` |
| Nearby services directory (shops, restaurants, repair…) | category `service` |
| Discussions: comments + reactions (👍 🙏 ⚠️ ❤️) | `post.php`, `actions.php` |
| Anonymous posts & comments (only moderators see the author) | `author_label()` |
| Post expiration (category defaults, custom, auto-delete) | `cleanup_expired_posts()`, `cron.php` |
| Verified local information (trusted users & moderators) | verify action in `actions.php` |
| Location-based notifications via "watch areas" | `areas.php`, `notify_watchers()`, `notifications.php` |
| Community polls | category `poll` |
| Safety & moderation: reports, auto-hide after 3 reports, bans, roles | `moderation.php` |
| Spam protection: CSRF, honeypot, rate limits, duplicate & link checks | `includes/helpers.php`, `includes/posts.php` |
| Privacy: map pins rounded to ~100 m, approximate distances | `fuzz_coord()`, `format_distance()` |
| People & friends: browse profiles, send / accept / decline friend requests, unfriend | `people.php`, `user.php`, `friends.php`, `includes/friends.php` |
| Lost Pet Tracking Board: lost/found pet reports with photos, map + list view, filters, sightings, statuses (Active / Sighted / Reunited / Closed), similar-pet matching, share links & QR posters, privacy options | `pets.php`, `pet.php`, `pet_create.php`, `pet_actions.php`, `includes/pets.php` |
| Community Marketplace: list items with up to 6 photos, price, category, condition, negotiable flag; browse nearby listings in a grid or on a map with search, category tabs, type / condition / price / availability / photo filters and price sorting; call, WhatsApp, SMS and email buttons plus public Q&A comments; sellers mark items Available / Reserved / Sold, renew, edit or delete; "My listings" | `marketplace.php`, `market_item.php`, `market_sell.php`, `market_actions.php`, `includes/marketplace.php`, `includes/market_card.php` |

Pet reports are normal `lost_found` posts with an extra `pet_reports` row, so comments, reports, moderation,
watch-area notifications and radius rules apply to them. Sightings are comments with a `pet_sightings` row.
After updating an existing install, run `php install.php` once to create the new `pet_*` tables.

Marketplace listings are normal `marketplace` posts with an extra `market_listings` row and `market_photos`, so
comments, reports, moderation, watch-area notifications, expiry and radius rules apply. Marketplace posts made
before this feature still appear (with default values) and get their listing row when the seller edits them.
Listings expire after 30 days (renewable) and sold items are cleared 7 days after being marked sold; see the
`marketplace` block in `config.php`. Run `php install.php` once to create the `market_*` tables.

## Setup

1. **Create a database user** (MySQL root on Ubuntu uses socket auth, so use `sudo`):
   ```bash
   sudo mysql -e "CREATE DATABASE elaka CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                  CREATE USER 'elaka'@'localhost' IDENTIFIED BY 'elaka_pass';
                  CREATE USER 'elaka'@'127.0.0.1' IDENTIFIED BY 'elaka_pass';
                  GRANT ALL ON elaka.* TO 'elaka'@'localhost';
                  GRANT ALL ON elaka.* TO 'elaka'@'127.0.0.1';"
   ```
   If you use different credentials, edit `config.php`.

2. **Install the tables** (pick one):
   ```bash
   php install.php --demo 23.8103 90.4125   # CLI: tables + admin + demo posts near the given lat/lng
   ```
   or start the server and open `http://localhost:8000/install.php`. The page has a button that uses your
   current location for the demo data.

3. **Run it**:
   ```bash
   php -S localhost:8000
   ```
   Open http://localhost:8000. Browsers only allow geolocation on `localhost` or HTTPS.

4. *(Optional)* Remove expired posts on a schedule. The app also does this on about 5% of page loads:
   ```
   */5 * * * * php /path/to/elaka/cron.php
   ```

## Accounts

| Email | Password | Role |
|---|---|---|
| admin@elaka.local | admin123 | Admin |
| mod@elaka.local | password123 | Moderator (demo) |
| rahim@elaka.local | password123 | Trusted local, can verify posts (demo) |
| nusrat@elaka.local, karim@elaka.local | password123 | Members (demo) |
| test1@elaka.local … test10@elaka.local | password123 | Test members with sample friendships (`php seed_test_users.php`) |

Change the admin password after installing. Delete `install.php` before deploying publicly.

## Notes

- For desktop demos you can pick a location on the map (`allow_manual_location` in `config.php`).
  Set it to `false` to require real GPS.
- Roles are `user` < `trusted` < `moderator` < `admin`. Admins change roles in **Moderation → Users**.
- Access rule: you can open a post if you are within its radius, you wrote it, you are a moderator,
  or you were notified about it through one of your watch areas.

## Structure

```
config.php            settings & DB credentials
schema.sql            database schema
install.php, cron.php setup & expiry job
includes/             bootstrap, db, helpers, auth, post logic, layout partials
index.php             nearby feed          post.php / post_create.php / actions.php
location.php          set GPS/manual location (set_location.php stores it)
areas.php             watch areas          notifications.php
moderation.php        reports, content, users
profile.php, login.php, register.php, logout.php
assets/css, assets/js, uploads/
```
