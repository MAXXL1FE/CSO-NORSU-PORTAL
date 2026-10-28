<?php
/**
 * Login protection: lockout after repeated failures, one generic message,
 * and a fresh session ID on every successful login.
 */

const LOGIN_MAX_FAILS   = 5;    // failed attempts before a lockout
const LOGIN_LOCK_MINUTES = 15;  // how long the lockout lasts
const LOGIN_ACCOUNT_MAX_FAILS = 20; // failed attempts on one account from ANY address before it pauses

function login_ensure_table($conn) {
    static $done = false;
    if ($done) return;
    $conn->query("CREATE TABLE IF NOT EXISTS login_attempts (
        throttle_key CHAR(40) PRIMARY KEY,
        fails        INT NOT NULL DEFAULT 0,
        locked_until DATETIME NULL DEFAULT NULL
    ) ENGINE=InnoDB");
    $done = true;
}

/** One key per IP address and login name, so attackers cannot try many names freely. */
function login_throttle_key($identifier) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return sha1($ip . '|' . strtolower(trim((string) $identifier)));
}

/** A second key per login name only, so guessing from many addresses at once is also slowed down. */
function login_account_key($identifier) {
    return sha1('account|' . strtolower(trim((string) $identifier)));
}

/** Seconds left on a lockout, or 0 when the user may try again. */
function login_locked_seconds($conn, $key) {
    login_ensure_table($conn);
    // A lockout that has expired is cleared here, so the next failure starts fresh.
    $conn->query("UPDATE login_attempts SET fails = 0, locked_until = NULL
                  WHERE throttle_key = '" . $conn->real_escape_string($key) . "'
                    AND locked_until IS NOT NULL AND locked_until <= NOW()");
    $stmt = $conn->prepare("SELECT GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), locked_until)) AS secs
                            FROM login_attempts WHERE throttle_key = ? AND locked_until > NOW()");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int) $row['secs'] : 0;
}

function login_record_failure($conn, $key, $max = LOGIN_MAX_FAILS) {
    login_ensure_table($conn);
    $mins = LOGIN_LOCK_MINUTES;
    $stmt = $conn->prepare("INSERT INTO login_attempts (throttle_key, fails) VALUES (?, 1)
        ON DUPLICATE KEY UPDATE
            locked_until = IF(fails + 1 >= ?, NOW() + INTERVAL ? MINUTE, locked_until),
            fails        = fails + 1");
    $stmt->bind_param('sii', $key, $max, $mins);
    $stmt->execute();
    $stmt->close();
}

function login_clear_failures($conn, $key) {
    login_ensure_table($conn);
    $stmt = $conn->prepare("DELETE FROM login_attempts WHERE throttle_key = ?");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $stmt->close();
}

/**
 * Checks a login. $mode is 'member', 'faculty' or 'admin'.
 * Returns ['ok' => bool, 'error' => string, 'user' => array|null].
 * Wrong IDs, wrong passwords and wrong account types all get the same message,
 * so the page never reveals which accounts exist. Account status and role
 * messages are only shown after the password is confirmed correct.
 */
function attempt_login($conn, $identifier, $password, $mode) {
    $generic = 'Incorrect ID, email, or password.';
    $key = login_throttle_key($identifier);
    $akey = login_account_key($identifier);

    $secs = max(login_locked_seconds($conn, $key), login_locked_seconds($conn, $akey));
    if ($secs > 0) {
        $mins = (int) ceil($secs / 60);
        return ['ok' => false, 'error' => "Too many failed attempts. Try again in $mins minute" . ($mins === 1 ? '' : 's') . '.', 'user' => null];
    }

    $stmt = $conn->prepare("SELECT user_id, first_name, middle_name, last_name, suffix, password, role, status, profile_photo, user_type, token_version, approval_pending, email_verified
                            FROM users WHERE student_id = ? OR email = ? LIMIT 1");
    $stmt->bind_param('ss', $identifier, $identifier);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Always run one hash check, so response time does not reveal whether the account exists.
    $hash = $user ? $user['password'] : '$2y$10$abcdefghijklmnopqrstuuO3yKcJZ8vvcHsCuUT0uy7QZFqAqE6fq';
    $password_ok = (string) $password !== '' && password_verify((string) $password, $hash);

    if (!$user || !$password_ok) {
        login_record_failure($conn, $key);
        login_record_failure($conn, $akey, LOGIN_ACCOUNT_MAX_FAILS);
        return ['ok' => false, 'error' => $generic, 'user' => null];
    }

    // Password is correct from here on, so account-type and status messages are safe to show.
    if ($mode === 'member' && $user['role'] === 'admin') {
        return ['ok' => false, 'error' => 'Admin accounts sign in on the Admin Login page.', 'user' => null, 'admin_hint' => true];
    }
    if ($mode === 'admin' && $user['role'] !== 'admin') {
        return ['ok' => false, 'error' => $generic, 'user' => null];
    }
    if ($mode === 'faculty') {
        if ($user['role'] === 'admin') {
            return ['ok' => false, 'error' => 'Admin accounts sign in on the Admin Login page.', 'user' => null, 'admin_hint' => true];
        }
        if (($user['user_type'] ?? 'student') !== 'faculty') {
            return ['ok' => false, 'error' => $generic, 'user' => null];
        }
    }
    // Sign-ups that never finished the emailed code are told so plainly, and
    // sent back to the verification page rather than left guessing.
    if (isset($user['email_verified']) && (int) $user['email_verified'] === 0) {
        return ['ok' => false, 'unverified_user_id' => (int) $user['user_id'],
                'error' => 'Your email address is not verified yet. Enter the code we emailed you to finish creating your account.',
                'user' => null];
    }
    if ($user['status'] !== 'active') {
        $msg = (($user['user_type'] ?? 'student') === 'faculty')
            ? 'Your faculty account is still waiting for approval from a CSO admin. Please check back soon.'
            : (!empty($user['approval_pending'])
                ? 'Your account is waiting for approval from a CSO officer. You can log in once it is approved.'
                : 'Your account is inactive. Please contact an officer or admin.');
        if ($mode === 'admin') $msg = 'This admin account is inactive. Please contact the system owner.';
        return ['ok' => false, 'error' => $msg, 'user' => null];
    }

    login_clear_failures($conn, $key);
    login_clear_failures($conn, $akey);
    session_regenerate_id(true);   // new session ID on login, which blocks session fixation
    return ['ok' => true, 'error' => '', 'user' => $user];
}
