-- Elaka database schema (MySQL 8 / MariaDB 10.4+)
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(80)  NOT NULL,
    email         VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('user','trusted','moderator','admin') NOT NULL DEFAULT 'user',
    is_banned     TINYINT(1)   NOT NULL DEFAULT 0,
    last_lat      DECIMAL(10,7) NULL,
    last_lng      DECIMAL(10,7) NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS posts (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id        INT UNSIGNED NOT NULL,
    category       ENUM('alert','event','lost_found','marketplace','service','news','discussion','poll') NOT NULL,
    subtype        VARCHAR(30)  NULL,
    title          VARCHAR(150) NOT NULL,
    body           TEXT         NULL,
    image          VARCHAR(100) NULL,
    lat            DECIMAL(10,7) NOT NULL,
    lng            DECIMAL(10,7) NOT NULL,
    radius_m       INT UNSIGNED NOT NULL DEFAULT 1000,
    is_anonymous   TINYINT(1)   NOT NULL DEFAULT 0,
    is_verified    TINYINT(1)   NOT NULL DEFAULT 0,
    verified_by    INT UNSIGNED NULL,
    verified_at    DATETIME     NULL,
    status         ENUM('active','hidden','removed') NOT NULL DEFAULT 'active',
    price          DECIMAL(12,2) NULL,
    contact        VARCHAR(120) NULL,
    location_text  VARCHAR(190) NULL,
    business_hours VARCHAR(120) NULL,
    event_start    DATETIME     NULL,
    event_end      DATETIME     NULL,
    expires_at     DATETIME     NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_posts_geo (lat, lng),
    INDEX idx_posts_status (status, expires_at),
    INDEX idx_posts_category (category),
    INDEX idx_posts_user (user_id, created_at),
    CONSTRAINT fk_posts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_posts_verifier FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comments (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_id      INT UNSIGNED NOT NULL,
    user_id      INT UNSIGNED NOT NULL,
    body         TEXT NOT NULL,
    is_anonymous TINYINT(1) NOT NULL DEFAULT 0,
    status       ENUM('active','removed') NOT NULL DEFAULT 'active',
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_comments_post (post_id, created_at),
    INDEX idx_comments_user (user_id, created_at),
    CONSTRAINT fk_comments_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
    CONSTRAINT fk_comments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reactions (
    post_id    INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    type       ENUM('like','helpful','important','love') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (post_id, user_id),
    CONSTRAINT fk_reactions_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
    CONSTRAINT fk_reactions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS poll_options (
    id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_id INT UNSIGNED NOT NULL,
    label   VARCHAR(120) NOT NULL,
    CONSTRAINT fk_options_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS poll_votes (
    post_id    INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    option_id  INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (post_id, user_id),
    CONSTRAINT fk_votes_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
    CONSTRAINT fk_votes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_votes_option FOREIGN KEY (option_id) REFERENCES poll_options(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reports (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    post_id     INT UNSIGNED NULL,
    comment_id  INT UNSIGNED NULL,
    reason      VARCHAR(30)  NOT NULL,
    details     VARCHAR(500) NULL,
    status      ENUM('open','resolved','dismissed') NOT NULL DEFAULT 'open',
    resolved_by INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reports_status (status, created_at),
    CONSTRAINT fk_reports_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_reports_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
    CONSTRAINT fk_reports_comment FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS watch_areas (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    name       VARCHAR(60) NOT NULL,
    lat        DECIMAL(10,7) NOT NULL,
    lng        DECIMAL(10,7) NOT NULL,
    radius_m   INT UNSIGNED NOT NULL DEFAULT 1000,
    categories VARCHAR(200) NOT NULL DEFAULT 'alert,event,lost_found',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_watch_geo (lat, lng),
    CONSTRAINT fk_watch_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    post_id    INT UNSIGNED NULL,
    type       VARCHAR(30)  NOT NULL,
    message    VARCHAR(255) NOT NULL,
    is_read    TINYINT(1)   NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notif_user (user_id, is_read, created_at),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_notif_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Friend requests / friendships. One row per pair, requester sends, addressee accepts.
CREATE TABLE IF NOT EXISTS friendships (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    requester_id INT UNSIGNED NOT NULL,
    addressee_id INT UNSIGNED NOT NULL,
    status       ENUM('pending','accepted') NOT NULL DEFAULT 'pending',
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    accepted_at  DATETIME NULL,
    UNIQUE KEY uq_friend_pair (requester_id, addressee_id),
    INDEX idx_friend_addressee (addressee_id, status),
    CONSTRAINT fk_friend_requester FOREIGN KEY (requester_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_friend_addressee FOREIGN KEY (addressee_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lost Pet Tracking Board. A pet report is a normal 'lost_found' post (so comments, reactions,
-- reports, moderation and notifications all apply) plus one row here with the pet details.
CREATE TABLE IF NOT EXISTS pet_reports (
    post_id            INT UNSIGNED PRIMARY KEY,
    pet_name           VARCHAR(60)  NULL,
    pet_type           VARCHAR(20)  NOT NULL,
    breed              VARCHAR(80)  NULL,
    color              VARCHAR(80)  NOT NULL,
    sex                ENUM('unknown','male','female') NOT NULL DEFAULT 'unknown',
    seen_at            DATETIME     NOT NULL,
    pet_status         ENUM('active','sighted','reunited','closed') NOT NULL DEFAULT 'active',
    status_changed_at  DATETIME     NULL,
    share_token        CHAR(32)     NOT NULL,
    link_sharing       TINYINT(1)   NOT NULL DEFAULT 1,
    contact_visibility ENUM('everyone','members','hidden') NOT NULL DEFAULT 'members',
    precise_location   TINYINT(1)   NOT NULL DEFAULT 0,
    UNIQUE KEY uq_pet_share (share_token),
    INDEX idx_pet_filter (pet_type, pet_status),
    CONSTRAINT fk_pet_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pet_photos (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_id    INT UNSIGNED NOT NULL,
    filename   VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pet_photos_post (post_id),
    CONSTRAINT fk_pet_photo_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A sighting is a comment on the report (description, author, anonymity, moderation)
-- plus where and when the pet was seen and an optional photo.
CREATE TABLE IF NOT EXISTS pet_sightings (
    comment_id    INT UNSIGNED PRIMARY KEY,
    post_id       INT UNSIGNED NOT NULL,
    lat           DECIMAL(10,7) NOT NULL,
    lng           DECIMAL(10,7) NOT NULL,
    seen_at       DATETIME     NOT NULL,
    location_text VARCHAR(190) NULL,
    photo         VARCHAR(100) NULL,
    INDEX idx_pet_sightings_post (post_id, seen_at),
    CONSTRAINT fk_sighting_comment FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE,
    CONSTRAINT fk_sighting_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Community Marketplace. A listing is a normal 'marketplace' post (radius rules, comments, reports,
-- moderation, watch-area notifications and expiry all apply) plus one row here with the sale details.
-- Older marketplace posts without a row here still appear on the marketplace with default values.
CREATE TABLE IF NOT EXISTS market_listings (
    post_id           INT UNSIGNED PRIMARY KEY,
    item_category     VARCHAR(20)  NOT NULL DEFAULT 'other',
    item_condition    ENUM('new','like_new','good','fair','for_parts') NULL,
    negotiable        TINYINT(1)   NOT NULL DEFAULT 0,
    sale_status       ENUM('available','reserved','sold') NOT NULL DEFAULT 'available',
    status_changed_at DATETIME     NULL,
    phone             VARCHAR(30)  NULL,
    whatsapp          TINYINT(1)   NOT NULL DEFAULT 0,
    email             VARCHAR(190) NULL,
    INDEX idx_market_filter (item_category, sale_status),
    CONSTRAINT fk_market_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS market_photos (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_id    INT UNSIGNED NOT NULL,
    filename   VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_market_photos_post (post_id),
    CONSTRAINT fk_market_photo_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
