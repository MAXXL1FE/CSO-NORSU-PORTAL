<?php
/**
 * Self-healing schema check.
 *
 * Ensures the media-support columns/tables (profile_photo, cover_image,
 * announcements.image, event_photos, org_info) exist, adding whichever
 * are missing. This is what makes the app tolerant of:
 *   - a database created from an older cso_mis.sql
 *   - someone skipping database/upgrade_v2_media.sql
 *   - a partially-applied migration
 *
 * Cost after the first successful run is one fast SELECT — the
 * schema_meta version check below short-circuits everything else.
 */

define('CSO_MIS_SCHEMA_VERSION', 3);

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

    $conn->query("UPDATE schema_meta SET version = " . CSO_MIS_SCHEMA_VERSION . " WHERE id = 1");
}
