-- =========================================================
-- CSO-MIS Upgrade v2 — Media support
-- Run this on an EXISTING install to add image support without
-- losing data. Skip this on a brand-new install — cso_mis.sql
-- already includes everything below.
-- =========================================================
USE cso_mis;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS profile_photo VARCHAR(255) NULL DEFAULT NULL AFTER year_level;

ALTER TABLE events
    ADD COLUMN IF NOT EXISTS cover_image VARCHAR(255) NULL DEFAULT NULL AFTER venue;

ALTER TABLE announcements
    ADD COLUMN IF NOT EXISTS image VARCHAR(255) NULL DEFAULT NULL AFTER content;

-- Event photo gallery — Facebook-album-style documentation per event,
-- linked from both Events and Attendance in the dashboard.
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

-- Single-row editable "About CSO" section (title + content + image),
-- shown on the public homepage.
CREATE TABLE IF NOT EXISTS org_info (
    id           INT PRIMARY KEY DEFAULT 1,
    title        VARCHAR(150) NOT NULL DEFAULT 'About CSO',
    content      TEXT NOT NULL,
    image        VARCHAR(255) NULL DEFAULT NULL,
    updated_by   INT NULL,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO org_info (id, title, content)
VALUES (1, 'About CSO', 'The Computer Science Organization (CSO) brings together BSCS students for academic growth, technical skill-building, and community. This system helps us manage membership, events, announcements, and attendance in one place.')
ON DUPLICATE KEY UPDATE id = id;
