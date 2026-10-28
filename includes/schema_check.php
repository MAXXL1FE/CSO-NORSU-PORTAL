<?php
/**
 * Self-healing schema check.
 *
 * Ensures the media-support columns/tables (profile_photo, cover_image,
 * announcements.image, event_photos, org_info) exist, adding whichever
 * are missing. This is what makes the app tolerant of:
 *   - a database created from an older cso_mis_full.sql
 *   - a partially-applied migration
 *
 * Cost after the first successful run is one fast SELECT — the
 * schema_meta version check below short-circuits everything else.
 */

define('CSO_MIS_SCHEMA_VERSION', 24);

function cso_mis_ensure_schema(mysqli $conn) {
    // Fast path: already up to date?
    $result = @$conn->query("SELECT version FROM schema_meta WHERE id = 1");
    if ($result) {
        $row = $result->fetch_assoc();
        if ($row && (int) $row['version'] >= CSO_MIS_SCHEMA_VERSION) {
            return;
        }
    }

    // Not up to date (or schema_meta doesn't exist yet) — run the checks.
    $conn->query("CREATE TABLE IF NOT EXISTS schema_meta (id INT PRIMARY KEY DEFAULT 1, version INT NOT NULL DEFAULT 0)");
    $conn->query("INSERT IGNORE INTO schema_meta (id, version) VALUES (1, 0)");

    $db_name_res = $conn->query("SELECT DATABASE() AS db");
    $db_name = $db_name_res ? $db_name_res->fetch_assoc()['db'] : null;
    if (!$db_name) return; // can't introspect — bail out quietly, don't crash the request

    $column_exists = function ($table, $column) use ($conn, $db_name) {
        $stmt = $conn->prepare("SELECT COUNT(*) c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
        $stmt->bind_param('sss', $db_name, $table, $column);
        $stmt->execute();
        return (int) $stmt->get_result()->fetch_assoc()['c'] > 0;
    };
    $table_exists = function ($table) use ($conn, $db_name) {
        $stmt = $conn->prepare("SELECT COUNT(*) c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
        $stmt->bind_param('ss', $db_name, $table);
        $stmt->execute();
        return (int) $stmt->get_result()->fetch_assoc()['c'] > 0;
    };

    if ($table_exists('users') && !$column_exists('users', 'profile_photo')) {
        $conn->query("ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) NULL DEFAULT NULL AFTER year_level");
    }

    if ($table_exists('events') && !$column_exists('events', 'cover_image')) {
        $conn->query("ALTER TABLE events ADD COLUMN cover_image VARCHAR(255) NULL DEFAULT NULL AFTER venue");
    }

    if ($table_exists('announcements') && !$column_exists('announcements', 'image')) {
        $conn->query("ALTER TABLE announcements ADD COLUMN image VARCHAR(255) NULL DEFAULT NULL AFTER content");
    }

    if (!$table_exists('event_photos')) {
        $conn->query("
            CREATE TABLE event_photos (
                photo_id     INT AUTO_INCREMENT PRIMARY KEY,
                event_id     INT NOT NULL,
                image_path   VARCHAR(255) NOT NULL,
                caption      VARCHAR(200) NULL,
                uploaded_by  INT NOT NULL,
                uploaded_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE,
                FOREIGN KEY (uploaded_by) REFERENCES users(user_id) ON DELETE CASCADE
            ) ENGINE=InnoDB
        ");
    }

    if (!$table_exists('org_info')) {
        $conn->query("
            CREATE TABLE org_info (
                id           INT PRIMARY KEY DEFAULT 1,
                title        VARCHAR(150) NOT NULL DEFAULT 'About CSO',
                content      TEXT NOT NULL,
                image        VARCHAR(255) NULL DEFAULT NULL,
                updated_by   INT NULL,
                updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
            ) ENGINE=InnoDB
        ");
        $default_content = 'The Computer Science Organization (CSO) brings together BSCS students for academic growth, technical skill-building, and community. This system helps us manage membership, events, announcements, and attendance in one place.';
        $stmt = $conn->prepare("INSERT INTO org_info (id, title, content) VALUES (1, 'About CSO', ?) ON DUPLICATE KEY UPDATE id = id");
        $stmt->bind_param('s', $default_content);
        $stmt->execute();
    }

    // ---- v3: committee roles, attendance time-out, attendance reminders ----
    if ($table_exists('users') && !$column_exists('users', 'committee_role')) {
        $conn->query("ALTER TABLE users ADD COLUMN committee_role VARCHAR(100) NULL DEFAULT NULL AFTER role");
    }

    if ($table_exists('attendance') && !$column_exists('attendance', 'time_out')) {
        $conn->query("ALTER TABLE attendance ADD COLUMN time_out TIMESTAMP NULL DEFAULT NULL AFTER checked_in_at");
    }

    if (!$table_exists('attendance_reminders')) {
        $conn->query("
            CREATE TABLE attendance_reminders (
                reminder_id  INT AUTO_INCREMENT PRIMARY KEY,
                event_id     INT NULL,
                message      VARCHAR(255) NOT NULL,
                created_by   INT NOT NULL,
                created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE,
                FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE CASCADE
            ) ENGINE=InnoDB
        ");
    }

    // ---- Facebook link on events ----
    if ($table_exists('events') && !$column_exists('events', 'facebook_url')) {
        $conn->query("ALTER TABLE events ADD COLUMN facebook_url VARCHAR(500) NULL DEFAULT NULL AFTER cover_image");
    }

    if ($table_exists('events') && !$column_exists('events', 'fb_image')) {
        $conn->query("ALTER TABLE events ADD COLUMN fb_image VARCHAR(255) NULL DEFAULT NULL AFTER facebook_url");
    }
    if ($table_exists('events') && !$column_exists('events', 'fb_description')) {
        $conn->query("ALTER TABLE events ADD COLUMN fb_description TEXT NULL DEFAULT NULL AFTER fb_image");
    }

    // ---- date and time are optional ----
    if ($table_exists('events')) {
        $conn->query("ALTER TABLE events MODIFY COLUMN event_date DATE NULL DEFAULT NULL");
        $conn->query("ALTER TABLE events MODIFY COLUMN event_time TIME NULL DEFAULT NULL");
    }

    // ---- v4: moderator role, event end date/time, announcement deadline ----
    if ($table_exists('events') && !$column_exists('events', 'event_end_date')) {
        $conn->query("ALTER TABLE events ADD COLUMN event_end_date DATE NULL DEFAULT NULL AFTER event_date");
    }
    if ($table_exists('events') && !$column_exists('events', 'event_end_time')) {
        $conn->query("ALTER TABLE events ADD COLUMN event_end_time TIME NULL DEFAULT NULL AFTER event_time");
    }
    if ($table_exists('announcements') && !$column_exists('announcements', 'deadline')) {
        $conn->query("ALTER TABLE announcements ADD COLUMN deadline DATETIME NULL DEFAULT NULL AFTER content");
    }
    // Widen the users.role ENUM so the Moderator role can be assigned.
    if ($table_exists('users')) {
        $rc = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");
        $rrow = $rc ? $rc->fetch_assoc() : null;
        if ($rrow && strpos($rrow['Type'], "'moderator'") === false) {
            $conn->query("ALTER TABLE users MODIFY COLUMN role ENUM('member','officer','moderator','admin') NOT NULL DEFAULT 'member'");
        }
    }

    // ---- v5: middle name + suffix ----
    if ($table_exists('users') && !$column_exists('users', 'middle_name')) {
        $conn->query("ALTER TABLE users ADD COLUMN middle_name VARCHAR(80) NULL DEFAULT NULL AFTER first_name");
    }
    if ($table_exists('users') && !$column_exists('users', 'suffix')) {
        $conn->query("ALTER TABLE users ADD COLUMN suffix VARCHAR(20) NULL DEFAULT NULL AFTER last_name");
    }

    // ---- v6: Facebook-style profile (cover photo + status note) ----
    if ($table_exists('users') && !$column_exists('users', 'cover_photo')) {
        $conn->query("ALTER TABLE users ADD COLUMN cover_photo VARCHAR(255) NULL DEFAULT NULL AFTER profile_photo");
    }
    if ($table_exists('users') && !$column_exists('users', 'status_note')) {
        $conn->query("ALTER TABLE users ADD COLUMN status_note VARCHAR(80) NULL DEFAULT NULL AFTER cover_photo");
    }

    // ---- v7: notifications "seen" marker (clears the unread badge / reminder panel) ----
    if ($table_exists('users') && !$column_exists('users', 'notif_seen_at')) {
        $conn->query("ALTER TABLE users ADD COLUMN notif_seen_at DATETIME NULL DEFAULT NULL");
    }

    // ---- v8: several photos per event / announcement (Facebook-style posts) ----
    if (!$table_exists('post_images')) {
        $conn->query("
            CREATE TABLE post_images (
                image_id    INT AUTO_INCREMENT PRIMARY KEY,
                post_type   ENUM('event','announcement') NOT NULL,
                post_id     INT NOT NULL,
                image_path  VARCHAR(255) NOT NULL,
                sort_order  INT NOT NULL DEFAULT 0,
                created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_post (post_type, post_id, sort_order)
            ) ENGINE=InnoDB
        ");
        // Carry over the single cover image / announcement image that already exist.
        if ($table_exists('events') && $column_exists('events', 'cover_image')) {
            $conn->query("INSERT INTO post_images (post_type, post_id, image_path, sort_order) SELECT 'event', event_id, cover_image, 0 FROM events WHERE cover_image IS NOT NULL AND cover_image <> ''");
        }
        if ($table_exists('announcements') && $column_exists('announcements', 'image')) {
            $conn->query("INSERT INTO post_images (post_type, post_id, image_path, sort_order) SELECT 'announcement', announcement_id, image, 0 FROM announcements WHERE image IS NOT NULL AND image <> ''");
        }
    }

    // ---- v9: faculty / instructors / professors who join CSO (shown on Home with their position) ----
    if ($table_exists('users') && !$column_exists('users', 'user_type')) {
        $conn->query("ALTER TABLE users ADD COLUMN user_type ENUM('student','faculty') NOT NULL DEFAULT 'student' AFTER role");
    }
    if ($table_exists('users') && !$column_exists('users', 'faculty_position')) {
        $conn->query("ALTER TABLE users ADD COLUMN faculty_position VARCHAR(100) NULL DEFAULT NULL AFTER user_type");
    }

    // ---- v11: campus map as buildings > floors > rooms (hours and availability dates) ----
    $conn->query("CREATE TABLE IF NOT EXISTS map_buildings (
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
    ) ENGINE=InnoDB");
    $conn->query("CREATE TABLE IF NOT EXISTS map_floors (
        floor_id    INT AUTO_INCREMENT PRIMARY KEY,
        building_id INT NOT NULL,
        label       VARCHAR(40) NOT NULL,
        sort_order  INT NOT NULL DEFAULT 0,
        FOREIGN KEY (building_id) REFERENCES map_buildings(building_id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    $conn->query("CREATE TABLE IF NOT EXISTS map_rooms (
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
    ) ENGINE=InnoDB");
    $map_count = $conn->query("SELECT COUNT(*) AS c FROM map_buildings");
    if ($map_count && (int) $map_count->fetch_assoc()['c'] === 0) {
        $seeds = require __DIR__ . '/map_seed.php';
        $ins = $conn->prepare("INSERT INTO map_buildings (name, category, pos_x, pos_y) VALUES (?, ?, ?, ?)");
        foreach ($seeds as $sd) {
            $ins->bind_param('ssdd', $sd['name'], $sd['category'], $sd['x'], $sd['y']);
            $ins->execute();
        }
    }

    // ---- v16: map places can have floors and rooms, rooms only, or neither ----
    if ($table_exists('map_buildings') && !$column_exists('map_buildings', 'floor_mode')) {
        require_once __DIR__ . '/map_lib.php';
        $conn->query("ALTER TABLE map_buildings ADD COLUMN floor_mode ENUM('floors','rooms_only','none') NOT NULL DEFAULT 'floors' AFTER category");
        $set = $conn->prepare("UPDATE map_buildings SET floor_mode = ? WHERE name = ?");
        foreach ([['none', MAP_NO_FLOOR_NAMES], ['rooms_only', MAP_ROOMS_ONLY_NAMES]] as [$fm, $names]) {
            foreach ($names as $nm) {
                $set->bind_param('ss', $fm, $nm);
                $set->execute();
            }
        }
        $conn->query("UPDATE map_buildings SET floor_mode = 'none' WHERE category = 'gate'");
        // Remove the floors and rooms these places should no longer have.
        $res = $conn->query("SELECT building_id FROM map_buildings WHERE floor_mode <> 'floors'");
        while ($row = $res->fetch_assoc()) map_apply_floor_mode($conn, (int) $row['building_id']);
    }

    // ---- v17: each user can clear their own notification history ----
    if ($table_exists('users') && !$column_exists('users', 'notif_cleared_at')) {
        $conn->query("ALTER TABLE users ADD COLUMN notif_cleared_at DATETIME NULL DEFAULT NULL");
    }

    // ---- v18: student sign-ups wait for officer approval; official Student ID list (roster) ----
    if ($table_exists('users') && !$column_exists('users', 'approval_pending')) {
        $conn->query("ALTER TABLE users ADD COLUMN approval_pending TINYINT(1) NOT NULL DEFAULT 0");
    }
    $conn->query("CREATE TABLE IF NOT EXISTS student_roster (
        student_id VARCHAR(30)  NOT NULL PRIMARY KEY,
        full_name  VARCHAR(150) NULL DEFAULT NULL,
        added_by   INT NULL DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    // ---- v19: personal notices for one user (for example "your account was approved") ----
    $conn->query("CREATE TABLE IF NOT EXISTS user_notices (
        notice_id  INT AUTO_INCREMENT PRIMARY KEY,
        user_id    INT NOT NULL,
        message    VARCHAR(255) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id, created_at),
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    // ---- v20: email alerts, event limits + waitlist, committees, reminders, map problem reports ----
    if ($table_exists('users') && !$column_exists('users', 'email_alerts')) {
        $conn->query("ALTER TABLE users ADD COLUMN email_alerts TINYINT(1) NOT NULL DEFAULT 1");
    }
    if ($table_exists('events')) {
        if (!$column_exists('events', 'max_attendees')) $conn->query("ALTER TABLE events ADD COLUMN max_attendees INT NULL DEFAULT NULL");
        if (!$column_exists('events', 'committee'))     $conn->query("ALTER TABLE events ADD COLUMN committee VARCHAR(100) NULL DEFAULT NULL");
        if (!$column_exists('events', 'reminder_sent')) $conn->query("ALTER TABLE events ADD COLUMN reminder_sent TINYINT(1) NOT NULL DEFAULT 0");
    }
    if ($table_exists('event_registrations') && !$column_exists('event_registrations', 'waitlisted')) {
        $conn->query("ALTER TABLE event_registrations ADD COLUMN waitlisted TINYINT(1) NOT NULL DEFAULT 0");
    }
    $conn->query("CREATE TABLE IF NOT EXISTS app_state (
        k VARCHAR(50) NOT NULL PRIMARY KEY,
        v VARCHAR(100) NOT NULL
    ) ENGINE=InnoDB");
    $conn->query("CREATE TABLE IF NOT EXISTS map_reports (
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
    ) ENGINE=InnoDB");

    // ---- v14: officer badge option ----
    if ($table_exists('users') && $column_exists('users', 'verified_as')) {
        $vc = $conn->query("SHOW COLUMNS FROM users LIKE 'verified_as'");
        $vrow = $vc ? $vc->fetch_assoc() : null;
        if ($vrow && strpos($vrow['Type'], "'officer'") === false) {
            $conn->query("ALTER TABLE users MODIFY COLUMN verified_as ENUM('none','member','officer','faculty') NOT NULL DEFAULT 'none'");
        }
    }

    // ---- v13: admin-granted verified badge (member or faculty) ----
    if ($table_exists('users') && !$column_exists('users', 'verified_as')) {
        $conn->query("ALTER TABLE users ADD COLUMN verified_as ENUM('none','member','faculty') NOT NULL DEFAULT 'none'");
    }

    // ---- v12: password reset codes; token_version signs out sessions after a password change ----
    if ($table_exists('users') && !$column_exists('users', 'token_version')) {
        $conn->query("ALTER TABLE users ADD COLUMN token_version INT NOT NULL DEFAULT 0");
    }

    // ---- v15: members choose whether they appear in the public Members list (off by default) ----
    if ($table_exists('users') && !$column_exists('users', 'show_in_members')) {
        $conn->query("ALTER TABLE users ADD COLUMN show_in_members TINYINT(1) NOT NULL DEFAULT 0");
    }

    // ---- v24: sign-up email verification. Accounts made before this flow existed
    //      are treated as already verified, so nobody is locked out by the upgrade. ----
    if ($table_exists('users') && !$column_exists('users', 'email_verified')) {
        $conn->query("ALTER TABLE users ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 0");
        $conn->query("UPDATE users SET email_verified = 1");
    }
    $conn->query("CREATE TABLE IF NOT EXISTS email_verifications (
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
    ) ENGINE=InnoDB");

    $conn->query("UPDATE schema_meta SET version = " . CSO_MIS_SCHEMA_VERSION . " WHERE id = 1");
}
