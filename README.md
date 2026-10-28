# CSO-MIS — Professional Edition

Computer Science Organization — Management and Information System

This is a full redesign and hardening pass over the original CSO-MIS: a new
clean, modern UI design system, tightened permission logic, a graceful error
page, and every dashboard module (members, events, announcements, attendance,
QR check-in, quick check-in, profile, feedback) rebuilt to match.

## What's included

- **Login-first entry point** — `index.php` routes to login or dashboard; the public homepage lives at `home.php`
- **Public homepage (all about CSO)** — hero banner, about + CSO-at-a-glance numbers, Faculty & Advisers (only shown once approved faculty exist), officers, upcoming events, latest announcements
- **Faculty / instructors / professors can join CSO** — pick "Faculty / Instructor" on the register page (Employee ID, position, department). Their account waits for admin approval (Dashboard > Members, set Status to Active); approved faculty appear on Home with their position
- **Facebook-style side menu** — My Profile on top, then Dashboard, Events, Announcements, Notifications, etc.; staff tools under "Manage"; Settings and Log Out last
- **Skeleton loading screen** instead of the old spinner/wave loader
- **Full officer directory** with search (`officers.php`)
- **Event details page** with self-service registration (`event.php`)
- **Registration & login** — password hashing, sessions, role detection, required Terms & Conditions checkbox
- **Role-based dashboards** — Admin, Officer, Member, each with its own stat cards and quick links
- **Member management** — full CRUD, search + status filter, role promotion (admin only)
- **Event management** — full CRUD, QR code check-in generation, CSV attendance export, registrant list
- **Announcements** — full CRUD
- **Attendance** — checkbox roster per event, self-check-in via QR, staff Quick Check-In by student ID (barcode-scanner friendly), live present/total counter
- **Event registration** for members, with a live registered/unregistered toggle
- **Editable profile** for every role, with optional password change
- **Feedback** — members submit subject/message/rating; admins & officers see and manage everyone's
- **Officer permission tiers** — officers can only edit/delete events and announcements they created; admins manage everything
- **CSRF protection** on every data-changing form
- **Graceful error pages** instead of raw PHP crashes (see `includes/error_page.php`)
- **New design system** — dark sticky topbar, dashboard sidebar, white cards on a light canvas, consistent buttons/forms/tables/badges/alerts, fully responsive down to mobile
- **Image uploads throughout** — officer profile photos, event cover images, Facebook-style announcement images, a per-event photo gallery, and an editable "About CSO" section with its own image

## Requirements

- XAMPP (or any Apache + PHP 8+ + MySQL/MariaDB stack), PHP `mysqli` and `mbstring` extensions (both included in default XAMPP)

## Setup steps

1. **Copy the project folder** into your XAMPP `htdocs` directory, e.g.:
   ```
   C:\xampp\htdocs\CSO-MIS
   ```
2. **Start Apache and MySQL** in the XAMPP Control Panel.
3. **Import the database**:
   - Open `http://localhost/phpmyadmin`
   - Create/select a database and import `database/cso_mis_full.sql`
     (this creates the `cso_mis` database and a starter admin row)
4. **Set the admin password**:
   - Visit `http://localhost/CSO-MIS/reset_admin_password.php` once in your browser (it locks itself after use, then delete the file)
   - You choose the admin password on that page. It is never shown or stored in a file.
   - **Delete `reset_admin_password.php` afterward** — it's a one-time setup script
5. **Check `config/db.php`** matches your MySQL credentials (default XAMPP: user `root`, no password)
6. **Visit the site**: `http://localhost/CSO-MIS/index.php`

**Updating an existing install?** Nothing to run by hand. The app adds any missing columns and tables automatically on the next page load (see `includes/schema_check.php`).

## Default admin login

- Student ID: `ADMIN-001`
- Password: the one you set with reset_admin_password.php

Change this password (or use the Profile page's password-change fields) before actual use.

## How roles work

- New registrations via `register.php` are always created as `member`.
- Promote a member to `officer` from **Dashboard → Members → Edit** while logged in as admin (the role dropdown only appears for admins).
- `admin` role isn't assignable through the UI — only exists via the seeded database row, for safety.

## Permission tiers

- **Admin**: full control over all members, events, and announcements; can delete accounts and change roles.
- **Officer**: can add/edit members (but not delete or promote/demote), and can edit/delete only the events and announcements *they personally created*. Attempting to touch someone else's event or announcement redirects to `unauthorized.php` instead of silently failing.
- **Member**: can register for events, self-check-in via QR, edit their own profile, and submit feedback.

## Folder structure

```
CSO-MIS/
├── config/
│   └── db.php                 # DB connection + graceful error hook
├── includes/
│   ├── functions.php          # auth guards, CSRF, sanitization, flash messages
│   ├── header.php / footer.php# shared layout — topbar + dashboard sidebar
│   └── error_page.php         # standalone error renderer (no DB dependency)
├── dashboard/
│   ├── admin.php / officer.php / member.php   # role-specific home dashboards
│   ├── members.php            # member CRUD
│   ├── events.php             # event CRUD + QR generation
│   ├── announcements.php      # announcement CRUD (Facebook-style, with image)
│   ├── attendance.php         # roster + quick check-in
│   ├── attendance_export.php  # CSV export per event
│   ├── gallery.php            # event photo gallery (multi-upload)
│   ├── info.php                # editable About CSO section (admin only)
│   ├── profile.php            # self-service profile & password
│   └── feedback.php           # feedback submit / review
├── assets/
│   ├── css/style.css          # design system
│   ├── images/                # logo.png, banner.png (placeholders — swap in your real CSO art)
│   └── uploads/                # user-uploaded images (profiles/events/announcements/gallery/info)
├── database/
│   └── cso_mis_full.sql        # full schema + seed admin row (fresh installs)
├── index.php                  # entry point (login-first router)
├── home.php                   # public homepage
├── event.php / officers.php / terms.php / unauthorized.php
├── login.php / register.php / logout.php
├── checkin.php                # QR self-check-in landing page
└── reset_admin_password.php   # run once, then delete
```

## Image uploads

Every image feature funnels through one validated upload path (`handle_image_upload()`
in `includes/functions.php`): JPG/PNG/WEBP/GIF only, checked by real file content
(not just the extension), capped at 3MB, saved under `assets/uploads/` with a random
filename. That folder ships with an `.htaccess` that blocks any uploaded file from
ever being executed as a script.

- **Officer photos** — Dashboard → Profile → upload your own photo. Shows in the
  officer directory, the homepage officers preview, and your avatar in the topbar.
- **Event cover images** — Dashboard → Events → Create/Edit. Shows on the homepage,
  the event detail page, and as a thumbnail in the admin events table.
- **Announcement images** — Dashboard → Announcements → Create/Edit. Renders
  Facebook-post style: image on top, text below, everywhere announcements appear.
- **Event photo gallery** — Dashboard → Events (or Attendance) → **Photos**. Upload
  several images at once with a shared caption; each photo can be deleted
  individually. Shows as a grid on the event's public page — think Facebook photo
  album for event documentation.
- **About CSO section** — Dashboard → About CSO Section (admin only). Edit the
  title, body text, and an accompanying image shown on the homepage.

If uploads bigger than 3MB matter to you, you can also raise `upload_max_filesize`
and `post_max_size` in `php.ini` — the app-level cap in `handle_image_upload()` is
independent of those and can be raised too.

**Self-healing schema:** every page load checks (cheaply, via `includes/schema_check.php`)
whether the image-related columns/tables exist, and adds anything missing automatically.
This means you don't have to run any upgrade script by hand — visiting
any page on an older database will fix it for you on the first request.

## QR check-in

Each event gets a unique QR code (**Dashboard → Events → QR**). Members scan it with
their phone camera while logged in, and it marks them present automatically. Check-in
only succeeds on the event's actual date — an early or late scan shows a clear
"wrong day" message instead of silently failing.

## Quick Check-In (manual / scanner entry)

**Dashboard → Attendance**, pick an event, then type or scan a student ID into the
big input box and press Enter. It looks the student up, marks them present, shows a
confirmation, and the field clears itself — works well with a cheap USB barcode
scanner pointed at student ID cards. A live "X / Y checked in" counter and the full
roster table update immediately, and there's a **Download CSV** button scoped to
that event.

## A note on install location

All internal links use a `BASE_URL` constant (auto-detected in `includes/functions.php`)
instead of hardcoded paths. This means the app works whether you put it at
`htdocs/CSO-MIS` (`http://localhost/CSO-MIS/`) or directly in `htdocs` itself
(`http://localhost/`) — no configuration needed.

## Notes on security

- Passwords are hashed with `password_hash()` / verified with `password_verify()`.
- All queries use prepared statements (`mysqli` with `bind_param`) — no string-concatenated SQL anywhere.
- Sessions gate every dashboard/CRUD page via `require_role()`.
- Every data-changing form carries a one-time CSRF token, verified server-side (`csrf_check()`).
- A broken database connection shows a clean, on-brand error page instead of a raw PHP stack trace.
- Officer-scoped ownership checks happen server-side on every edit/delete, not just hidden in the UI — a crafted request to edit someone else's event still gets redirected to `unauthorized.php`.

## What's still "nice to have" (not built)

- Email verification / password reset via email
- Push/email notifications
- Financial management module
- Digital certificates
- Advanced analytics/reporting dashboards

## Swapping in your real branding

`assets/images/logo.png` and `assets/images/banner.png` are placeholders generated
for this build. Drop your actual CSO NORSU logo and banner into those two file
paths (same filenames) and they'll appear automatically in the topbar and homepage
hero — no code changes needed.


## Upgrading to the faculty version

Nothing to run by hand: `includes/schema_check.php` adds the new `user_type` and `faculty_position` columns on the next page load.

## Security notes

What the app does for you:

- Passwords are hashed; logins lock after repeated failures (per address and per account); sessions sign out after 2 hours idle, and a role or password change takes effect immediately.
- Every form is CSRF-protected, every query is parameterized, uploads are verified as real images, and uploaded folders cannot run PHP.
- Security headers (CSP, anti-framing, no-sniff, referrer policy) are sent on every page from `includes/functions.php`.
- QR codes are drawn in the browser (`assets/js/qr.js`); student IDs and event check-in tokens are never sent to another website.
- `config/`, `includes/` and `database/` are blocked from direct browser access by `.htaccess`.

What you must do after deploying:

1. Delete `reset_admin_password.php` as soon as the admin password is set.
2. Change the default admin account (`ADMIN-001`) password right away on a fresh install, before sharing the site.
3. Put your database password only in the host's settings or `config/db.php` on the server; never commit it.
4. Use HTTPS (the site sends the HSTS header automatically when it is served over HTTPS).
