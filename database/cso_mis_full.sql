-- =========================================================
-- CSO-MIS — FULL DATABASE SETUP (all versions merged, v1 to v14)
--
-- Import this ONE file on a NEW, EMPTY database. It creates every
-- table and column the current app uses, so you do not need to run
-- any upgrade_*.sql files.
--
-- Already have an older install? Do not import this file over it.
-- Just open the site once: includes/schema_check.php adds anything
-- missing automatically.
--
-- After importing, run reset_admin_password.php once to set the admin
-- login, then delete that file.
-- =========================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------
-- Schema version tracker (used by includes/schema_check.php)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS schema_meta (
    id      INT PRIMARY KEY DEFAULT 1,
    version INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;
INSERT IGNORE INTO schema_meta (id, version) VALUES (1, 14);
UPDATE schema_meta SET version = 14 WHERE id = 1;

-- ---------------------------------------------------------
-- Users: members, officers, moderators, admin, and faculty
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    user_id          INT AUTO_INCREMENT PRIMARY KEY,
    student_id       VARCHAR(30)  NOT NULL UNIQUE,      -- Student ID, or Employee ID for faculty
    first_name       VARCHAR(80)  NOT NULL,
    middle_name      VARCHAR(80)  NULL DEFAULT NULL,
    last_name        VARCHAR(80)  NOT NULL,
    suffix           VARCHAR(20)  NULL DEFAULT NULL,
    email            VARCHAR(120) NOT NULL UNIQUE,
    password         VARCHAR(255) NOT NULL,
    course           VARCHAR(80)  NOT NULL,             -- course, or department for faculty
    year_level       VARCHAR(20)  NOT NULL,             -- 'Faculty' for faculty
    role             ENUM('admin','moderator','officer','member') NOT NULL DEFAULT 'member',
    committee_role   VARCHAR(100) NULL DEFAULT NULL,
    user_type        ENUM('student','faculty') NOT NULL DEFAULT 'student',
    faculty_position VARCHAR(100) NULL DEFAULT NULL,
    status           ENUM('active','inactive') NOT NULL DEFAULT 'active',
    profile_photo    VARCHAR(255) NULL DEFAULT NULL,
    cover_photo      VARCHAR(255) NULL DEFAULT NULL,
    status_note      VARCHAR(80)  NULL DEFAULT NULL,
    notif_seen_at    DATETIME     NULL DEFAULT NULL,
    notif_cleared_at DATETIME     NULL DEFAULT NULL,
    email_alerts     TINYINT(1)   NOT NULL DEFAULT 1,   -- 1 = emails about new events and announcements
    approval_pending TINYINT(1)   NOT NULL DEFAULT 0,   -- 1 = student sign-up waiting for officer approval
    email_verified   TINYINT(1)   NOT NULL DEFAULT 0,   -- 1 = the sign-up email code was entered
    token_version    INT          NOT NULL DEFAULT 0,
    verified_as      ENUM('none','member','officer','faculty') NOT NULL DEFAULT 'none',
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Events
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS events (
    event_id       INT AUTO_INCREMENT PRIMARY KEY,
    title          VARCHAR(150) NOT NULL,
    description    TEXT NULL,
    event_date     DATE NULL DEFAULT NULL,        -- optional: leave empty for "date to be announced"
    event_end_date DATE NULL DEFAULT NULL,
    event_time     TIME NULL DEFAULT NULL,
    event_end_time TIME NULL DEFAULT NULL,
    max_attendees  INT NULL DEFAULT NULL,       -- registration limit, NULL = no limit (extra sign-ups join the waitlist)
    committee      VARCHAR(100) NULL DEFAULT NULL,
    reminder_sent  TINYINT(1) NOT NULL DEFAULT 0,
    venue          VARCHAR(150) NOT NULL,
    cover_image    VARCHAR(255) NULL DEFAULT NULL,
    facebook_url   VARCHAR(500) NULL DEFAULT NULL,   -- optional link to the event's Facebook post
    fb_image       VARCHAR(255) NULL DEFAULT NULL,   -- image read from that link
    fb_description TEXT NULL DEFAULT NULL,           -- description read from that link
    qr_token       VARCHAR(64) NOT NULL UNIQUE,
    created_by     INT NOT NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Event registrations (members opting in ahead of time)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS event_registrations (
    registration_id INT AUTO_INCREMENT PRIMARY KEY,
    event_id        INT NOT NULL,
    user_id         INT NOT NULL,
    registered_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    waitlisted      TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_event_user (event_id, user_id),
    FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)  REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Attendance (QR, quick check-in, or manual roster; time in and time out)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS attendance (
    attendance_id INT AUTO_INCREMENT PRIMARY KEY,
    event_id      INT NOT NULL,
    user_id       INT NOT NULL,
    status        ENUM('present','absent') NOT NULL DEFAULT 'present',
    recorded_by   INT NOT NULL,
    checked_in_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    time_out      TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uniq_attendance (event_id, user_id),
    FOREIGN KEY (event_id)    REFERENCES events(event_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)     REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Attendance reminders (short messages, optionally tied to an event)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS attendance_reminders (
    reminder_id INT AUTO_INCREMENT PRIMARY KEY,
    event_id    INT NULL,
    message     VARCHAR(255) NOT NULL,
    created_by  INT NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id)   REFERENCES events(event_id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Announcements
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS announcements (
    announcement_id INT AUTO_INCREMENT PRIMARY KEY,
    title           VARCHAR(150) NOT NULL,
    content         TEXT NOT NULL,
    deadline        DATETIME NULL DEFAULT NULL,
    image           VARCHAR(255) NULL DEFAULT NULL,
    created_by      INT NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Event photo gallery
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS event_photos (
    photo_id    INT AUTO_INCREMENT PRIMARY KEY,
    event_id    INT NOT NULL,
    image_path  VARCHAR(255) NOT NULL,
    caption     VARCHAR(200) NULL,
    uploaded_by INT NOT NULL,
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id)    REFERENCES events(event_id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Several photos per event or announcement
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS post_images (
    image_id   INT AUTO_INCREMENT PRIMARY KEY,
    post_type  ENUM('event','announcement') NOT NULL,
    post_id    INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_post (post_type, post_id, sort_order)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- About CSO section (single editable row, shown on Home)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS org_info (
    id         INT PRIMARY KEY DEFAULT 1,
    title      VARCHAR(150) NOT NULL DEFAULT 'About CSO',
    content    TEXT NOT NULL,
    image      VARCHAR(255) NULL DEFAULT NULL,
    updated_by INT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Feedback
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS feedback (
    feedback_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    subject     VARCHAR(150) NOT NULL,
    message     TEXT NOT NULL,
    rating      TINYINT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Small key/value store (for example, when event reminders last ran)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS app_state (
    k VARCHAR(50) NOT NULL PRIMARY KEY,
    v VARCHAR(100) NOT NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Problems students report on the campus map
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS map_reports (
    report_id   INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    building_id INT NULL DEFAULT NULL,
    place_text  VARCHAR(150) NULL DEFAULT NULL,
    message     VARCHAR(500) NOT NULL,
    status      ENUM('open','resolved') NOT NULL DEFAULT 'open',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_by INT NULL DEFAULT NULL,
    INDEX (status, created_at),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Personal notices for one user (shown on their Notifications page)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_notices (
    notice_id  INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    message    VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id, created_at),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Official Student ID list: when it has rows, only these IDs can register as students
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS student_roster (
    student_id VARCHAR(30)  NOT NULL PRIMARY KEY,
    full_name  VARCHAR(150) NULL DEFAULT NULL,
    added_by   INT NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Campus map: rooms, floors, buildings and availability (shown on map.php)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS map_locations (
    location_id  INT AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(150) NOT NULL,
    category     ENUM('building','office','room','facility','restroom','gate') NOT NULL DEFAULT 'building',
    building     VARCHAR(150) NULL DEFAULT NULL,
    floor        VARCHAR(40)  NULL DEFAULT NULL,
    room         VARCHAR(60)  NULL DEFAULT NULL,
    status       ENUM('open','limited','maintenance','closed') NOT NULL DEFAULT 'open',
    status_note  VARCHAR(160) NULL DEFAULT NULL,
    pos_x        DECIMAL(5,2) NOT NULL DEFAULT 50,
    pos_y        DECIMAL(5,2) NOT NULL DEFAULT 50,
    updated_by   INT NULL DEFAULT NULL,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Campus map: buildings > floors > rooms, with hours and availability dates
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS map_buildings (
    building_id INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(150) NOT NULL,
    code        VARCHAR(20)  NULL DEFAULT NULL,
    category    ENUM('building','facility','office','restroom','gate') NOT NULL DEFAULT 'building',
    pos_x       DECIMAL(5,2) NOT NULL DEFAULT 50,
    pos_y       DECIMAL(5,2) NOT NULL DEFAULT 50,
    status      ENUM('open','limited','maintenance','closed') NOT NULL DEFAULT 'open',
    status_note VARCHAR(160) NULL DEFAULT NULL,
    open_time   TIME NULL DEFAULT NULL,
    close_time  TIME NULL DEFAULT NULL,
    date_opened DATE NULL DEFAULT NULL,
    date_closed DATE NULL DEFAULT NULL,
    updated_by  INT NULL DEFAULT NULL,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS map_floors (
    floor_id    INT AUTO_INCREMENT PRIMARY KEY,
    building_id INT NOT NULL,
    label       VARCHAR(40) NOT NULL,
    sort_order  INT NOT NULL DEFAULT 0,
    FOREIGN KEY (building_id) REFERENCES map_buildings(building_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS map_rooms (
    room_id     INT AUTO_INCREMENT PRIMARY KEY,
    floor_id    INT NOT NULL,
    name        VARCHAR(100) NOT NULL,
    room_type   ENUM('room','office','lab','facility','restroom') NOT NULL DEFAULT 'room',
    status      ENUM('open','limited','maintenance','closed') NOT NULL DEFAULT 'open',
    status_note VARCHAR(160) NULL DEFAULT NULL,
    open_time   TIME NULL DEFAULT NULL,
    close_time  TIME NULL DEFAULT NULL,
    date_opened DATE NULL DEFAULT NULL,
    date_closed DATE NULL DEFAULT NULL,
    updated_by  INT NULL DEFAULT NULL,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (floor_id) REFERENCES map_floors(floor_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Password reset codes (hashed, expiring, attempt-limited)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS app_secrets (
    name  VARCHAR(50) PRIMARY KEY,
    value VARCHAR(128) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS password_resets (
    reset_id     INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    code_hash    CHAR(64) NOT NULL,
    channel      VARCHAR(20) NOT NULL,
    issued_by    INT NULL DEFAULT NULL,
    requested_ip VARCHAR(45) NULL DEFAULT NULL,
    attempts     INT NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL,
    expires_at   DATETIME NOT NULL,
    verified_at  DATETIME NULL DEFAULT NULL,
    used_at      DATETIME NULL DEFAULT NULL,
    INDEX idx_pr_user (user_id, created_at),
    INDEX idx_pr_ip (requested_ip, created_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Sign-up email verification codes (hashed, expiring, attempt-limited)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_verifications (
    verify_id    INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    code_hash    CHAR(64) NOT NULL,
    requested_ip VARCHAR(45) NULL DEFAULT NULL,
    attempts     INT NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL,
    expires_at   DATETIME NOT NULL,
    used_at      DATETIME NULL DEFAULT NULL,
    INDEX idx_ev_user (user_id, created_at),
    INDEX idx_ev_ip (requested_ip, created_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Seed: default admin account
-- Set the password by running reset_admin_password.php once after import.
-- ---------------------------------------------------------
INSERT INTO users (student_id, first_name, last_name, email, password, course, year_level, role, status, email_verified)
VALUES ('ADMIN-001', 'System', 'Administrator', 'admin@cso-mis.local', '', 'BSCS', '4th Year', 'admin', 'active', 1)
ON DUPLICATE KEY UPDATE student_id = student_id;

-- ---------------------------------------------------------
-- Seed: default About CSO content
-- ---------------------------------------------------------
INSERT INTO org_info (id, title, content)
VALUES (1, 'About CSO', 'The Computer Science Organization (CSO) brings together BSCS students for academic growth, technical skill-building, and community. This system helps us manage membership, events, announcements, and attendance in one place.')
ON DUPLICATE KEY UPDATE id = id;

-- Campus map buildings are added automatically on first load (see includes/map_seed.php).
-- The floor_mode column (floors / rooms_only / none) is added on first load by includes/schema_check.php (v16).
-- Floors and rooms are added in Dashboard > Campus Map.
