-- =========================================================
-- CSO-MIS Database Schema
-- Computer Science Organization — Management Information System
-- =========================================================

CREATE DATABASE IF NOT EXISTS cso_mis CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cso_mis;

-- ---------------------------------------------------------
-- Users (members, officers, admin)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    user_id      INT AUTO_INCREMENT PRIMARY KEY,
    student_id   VARCHAR(30)  NOT NULL UNIQUE,
    first_name   VARCHAR(80)  NOT NULL,
    last_name    VARCHAR(80)  NOT NULL,
    email        VARCHAR(120) NOT NULL UNIQUE,
    password     VARCHAR(255) NOT NULL,
    course       VARCHAR(80)  NOT NULL,
    year_level   VARCHAR(20)  NOT NULL,
    role         ENUM('admin','officer','member') NOT NULL DEFAULT 'member',
    committee_role VARCHAR(100) NULL DEFAULT NULL,
    status       ENUM('active','inactive') NOT NULL DEFAULT 'active',
    profile_photo VARCHAR(255) NULL DEFAULT NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Events
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS events (
    event_id     INT AUTO_INCREMENT PRIMARY KEY,
    title        VARCHAR(150) NOT NULL,
    description  TEXT NULL,
    event_date   DATE NOT NULL,
    event_time   TIME NOT NULL,
    venue        VARCHAR(150) NOT NULL,
    cover_image  VARCHAR(255) NULL DEFAULT NULL,
    qr_token     VARCHAR(64) NOT NULL UNIQUE,
    created_by   INT NOT NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Event registrations (members opting in ahead of time)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS event_registrations (
    registration_id INT AUTO_INCREMENT PRIMARY KEY,
    event_id     INT NOT NULL,
    user_id      INT NOT NULL,
    registered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_event_user (event_id, user_id),
    FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Attendance (actual check-in record: QR, quick check-in, or manual roster)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS attendance (
    attendance_id INT AUTO_INCREMENT PRIMARY KEY,
    event_id     INT NOT NULL,
    user_id      INT NOT NULL,
    status       ENUM('present','absent') NOT NULL DEFAULT 'present',
    recorded_by  INT NOT NULL,
    checked_in_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    time_out     TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uniq_attendance (event_id, user_id),
    FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Attendance reminders (short messages, optionally tied to an event,
-- shown to members/officers on their dashboards)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS attendance_reminders (
    reminder_id  INT AUTO_INCREMENT PRIMARY KEY,
    event_id     INT NULL,
    message      VARCHAR(255) NOT NULL,
    created_by   INT NOT NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Announcements
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS announcements (
    announcement_id INT AUTO_INCREMENT PRIMARY KEY,
    title        VARCHAR(150) NOT NULL,
    content      TEXT NOT NULL,
    image        VARCHAR(255) NULL DEFAULT NULL,
    created_by   INT NOT NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Event photo gallery (Facebook-album-style event documentation)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS event_photos (
    photo_id     INT AUTO_INCREMENT PRIMARY KEY,
    event_id     INT NOT NULL,
    image_path   VARCHAR(255) NOT NULL,
    caption      VARCHAR(200) NULL,
    uploaded_by  INT NOT NULL,
    uploaded_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- About CSO section (editable, single row, shown on homepage)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS org_info (
    id           INT PRIMARY KEY DEFAULT 1,
    title        VARCHAR(150) NOT NULL DEFAULT 'About CSO',
    content      TEXT NOT NULL,
    image        VARCHAR(255) NULL DEFAULT NULL,
    updated_by   INT NULL,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Feedback
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS feedback (
    feedback_id  INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    subject      VARCHAR(150) NOT NULL,
    message      TEXT NOT NULL,
    rating       TINYINT NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Seed: default admin account
-- Password is set separately by reset_admin_password.php (run once after import)
-- ---------------------------------------------------------

INSERT INTO users (student_id, first_name, last_name, email, password, course, year_level, role, status)
VALUES ('ADMIN-001', 'System', 'Administrator', 'admin@cso-mis.local', '', 'BSCS', '4th Year', 'admin', 'active')
ON DUPLICATE KEY UPDATE student_id = student_id;

-- ---------------------------------------------------------
-- Seed: default About CSO content
-- ---------------------------------------------------------
INSERT INTO org_info (id, title, content)
VALUES (1, 'About CSO', 'The Computer Science Organization (CSO) brings together BSCS students for academic growth, technical skill-building, and community. This system helps us manage membership, events, announcements, and attendance in one place.')
ON DUPLICATE KEY UPDATE id = id;
