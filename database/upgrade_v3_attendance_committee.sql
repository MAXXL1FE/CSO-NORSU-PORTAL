-- =========================================================
-- CSO-MIS Upgrade v3 — Time In/Out, Attendance Reminders, Committee Roles
-- Run this on an EXISTING install. Safe to skip and just reload the
-- site instead — config/db.php runs the same changes automatically
-- via includes/schema_check.php on the next page load.
-- =========================================================
USE cso_mis;

-- Free-typed committee/position label for officers, e.g. "Vice President",
-- "Events Committee Head". Settable by admin from Members > Edit.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS committee_role VARCHAR(100) NULL DEFAULT NULL AFTER role;

-- Attendance now records both a time in (checked_in_at, already existed)
-- and an optional time out.
ALTER TABLE attendance
    ADD COLUMN IF NOT EXISTS time_out TIMESTAMP NULL DEFAULT NULL AFTER checked_in_at;

-- Attendance reminders — short messages admin/officers post, optionally
-- tied to a specific event, shown to members and officers on their
-- dashboards.
CREATE TABLE IF NOT EXISTS attendance_reminders (
    reminder_id  INT AUTO_INCREMENT PRIMARY KEY,
    event_id     INT NULL,
    message      VARCHAR(255) NOT NULL,
    created_by   INT NOT NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;
