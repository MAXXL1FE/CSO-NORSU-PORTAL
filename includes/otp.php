<?php
/**
 * Password-reset codes (one-time passwords).
 *
 * Security rules applied here:
 *  - Codes are random 6-digit numbers from random_int(). Only an HMAC of the
 *    code is stored, keyed with a server secret, so a leaked table reveals nothing.
 *  - A code expires after 10 minutes and works once.
 *  - 5 wrong guesses kill a code. A new request kills the older unused codes.
 *  - At most 3 requests per account and 10 per IP address in 15 minutes.
 *  - Codes are never written to logs or shown after they are issued.
 *  - Comparison uses hash_equals (constant time).
 */

const OTP_LIFETIME_MIN = 10;
const OTP_MAX_ATTEMPTS = 5;
const OTP_MAX_PER_USER = 3;
const OTP_MAX_PER_IP   = 10;
const OTP_WINDOW_MIN   = 15;
const RESET_WINDOW_MIN = 10;   // after a correct code, time allowed to choose the new password

require_once __DIR__ . '/../config/mail.php';

function otp_ensure_tables($conn) {
    static $done = false;
    if ($done) return;
    $conn->query("CREATE TABLE IF NOT EXISTS app_secrets (
        name  VARCHAR(50) PRIMARY KEY,
        value VARCHAR(128) NOT NULL
    ) ENGINE=InnoDB");
    $conn->query("CREATE TABLE IF NOT EXISTS password_resets (
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
    ) ENGINE=InnoDB");
    $done = true;
}

/** Server secret used to key the code HMAC. Created once and stored in the database. */
function otp_secret($conn) {
    otp_ensure_tables($conn);
    $r = $conn->query("SELECT value FROM app_secrets WHERE name = 'otp_key'");
    $row = $r ? $r->fetch_assoc() : null;
    if ($row) return $row['value'];
    $key = bin2hex(random_bytes(32));
    $stmt = $conn->prepare("INSERT IGNORE INTO app_secrets (name, value) VALUES ('otp_key', ?)");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $stmt->close();
    $r = $conn->query("SELECT value FROM app_secrets WHERE name = 'otp_key'");
    return $r->fetch_assoc()['value'];
}

function otp_count($conn, $sql, $types, $params) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $n = (int) $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    return $n;
}

/** True when this account or this IP has asked for too many codes recently. */
function otp_too_many($conn, $user_id, $ip) {
    otp_ensure_tables($conn);
    $w = OTP_WINDOW_MIN;
    $by_user = otp_count($conn,
        "SELECT COUNT(*) AS c FROM password_resets WHERE user_id = ? AND created_at > NOW() - INTERVAL ? MINUTE",
        'ii', [$user_id, $w]);
    $by_ip = otp_count($conn,
        "SELECT COUNT(*) AS c FROM password_resets WHERE requested_ip = ? AND created_at > NOW() - INTERVAL ? MINUTE",
        'si', [$ip, $w]);
    return $by_user >= OTP_MAX_PER_USER || $by_ip >= OTP_MAX_PER_IP;
}

/** Creates a new code. Returns the plain code once (for emailing or showing to an officer). */
function otp_issue($conn, $user_id, $channel, $ip, $issued_by = null) {
    otp_ensure_tables($conn);
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $hash = hash_hmac('sha256', $code, otp_secret($conn));
    $conn->query("UPDATE password_resets SET used_at = NOW()
                  WHERE user_id = " . (int) $user_id . " AND used_at IS NULL AND verified_at IS NULL");
    $ttl = OTP_LIFETIME_MIN;
    $stmt = $conn->prepare("INSERT INTO password_resets (user_id, code_hash, channel, issued_by, requested_ip, created_at, expires_at)
                            VALUES (?, ?, ?, ?, ?, NOW(), NOW() + INTERVAL ? MINUTE)");
    $stmt->bind_param('issisi', $user_id, $hash, $channel, $issued_by, $ip, $ttl);
    $stmt->execute();
    $stmt->close();
    return $code;
}

/** Checks a code. Returns the reset_id on success, or null. */
function otp_verify($conn, $user_id, $code) {
    otp_ensure_tables($conn);
    if (!preg_match('/^\d{6}$/', $code)) return null;
    $stmt = $conn->prepare("SELECT reset_id, code_hash, attempts FROM password_resets
                            WHERE user_id = ? AND used_at IS NULL AND verified_at IS NULL AND expires_at > NOW()
                            ORDER BY reset_id DESC LIMIT 1");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || (int) $row['attempts'] >= OTP_MAX_ATTEMPTS) return null;

    $expected = hash_hmac('sha256', $code, otp_secret($conn));
    $rid = (int) $row['reset_id'];
    if (hash_equals($row['code_hash'], $expected)) {
        $conn->query("UPDATE password_resets SET verified_at = NOW(), attempts = attempts + 1 WHERE reset_id = $rid");
        return $rid;
    }
    $conn->query("UPDATE password_resets SET attempts = attempts + 1 WHERE reset_id = $rid");
    if ((int) $row['attempts'] + 1 >= OTP_MAX_ATTEMPTS) {
        $conn->query("UPDATE password_resets SET used_at = NOW() WHERE reset_id = $rid");
    }
    return null;
}

/** True when a verified code is still usable to set a new password. */
function otp_reset_valid($conn, $user_id, $reset_id) {
    otp_ensure_tables($conn);
    $w = RESET_WINDOW_MIN;
    $n = otp_count($conn,
        "SELECT COUNT(*) AS c FROM password_resets WHERE reset_id = ? AND user_id = ? AND verified_at IS NOT NULL
         AND used_at IS NULL AND verified_at > NOW() - INTERVAL ? MINUTE",
        'iii', [$reset_id, $user_id, $w]);
    return $n === 1;
}

/** Ends every open code for this account once a password has been changed. */
function otp_close_all($conn, $user_id) {
    $conn->query("UPDATE password_resets SET used_at = NOW() WHERE user_id = " . (int) $user_id . " AND used_at IS NULL");
}

/** Sends a plain-text email through the Resend HTTPS API. Returns true only if Resend accepted it. */
function cso_send_mail($to_email, $to_name, $subject, $text) {
    // Lower-cased: Resend matches the sandbox recipient case-sensitively, so
    // 'Name@gmail.com' is refused where 'name@gmail.com' is accepted.
    $to_email = strtolower(trim((string) $to_email));
    if (OTP_MAIL_API_KEY === '' || !function_exists('curl_init') || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) return false;
    $from = OTP_MAIL_FROM_NAME !== ''
        ? sprintf('%s <%s>', OTP_MAIL_FROM_NAME, OTP_MAIL_FROM)
        : OTP_MAIL_FROM;
    $to = ($to_name !== '' && strpbrk($to_name, '<>@,"') === false)
        ? sprintf('%s <%s>', $to_name, $to_email)
        : $to_email;
    $payload = json_encode([
        'from'    => $from,
        'to'      => [$to],
        'subject' => $subject,
        'text'    => $text,
    ]);
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Authorization: Bearer ' . OTP_MAIL_API_KEY, 'Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err    = curl_error($ch);
    curl_close($ch);
    $ok = $resp !== false && $status >= 200 && $status < 300;
    if (!$ok) {
        // The reason never reaches the visitor (it would leak account details),
        // so record it for whoever is looking after the site.
        error_log('CSO mail: Resend refused a message (HTTP ' . $status . ') ' . ($err !== '' ? $err : substr((string) $resp, 0, 300)));
    }
    return $ok;
}

/** Sends the password reset code by email. */
function otp_send_email($to_email, $to_name, $code) {
    return cso_send_mail($to_email, $to_name, 'Your CSO password reset code',
        "Your password reset code is: $code\n\nIt expires in " . OTP_LIFETIME_MIN . " minutes and works once.\n"
        . "If you did not ask to reset your password, you can ignore this email.");
}

function otp_email_available() {
    return OTP_MAIL_API_KEY !== '' && function_exists('curl_init');
}

/* ---------------------------------------------------------------
 * Sign-up email verification
 *
 * Same rules as the reset codes above: 6 random digits, only an HMAC
 * is stored, 10-minute life, one use, 5 wrong guesses kill the code,
 * and a limit on how many codes an account or an IP can ask for.
 * --------------------------------------------------------------- */

function ev_ensure_table($conn) {
    static $done = false;
    if ($done) return;
    otp_ensure_tables($conn);   // app_secrets holds the HMAC key
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
    $done = true;
}

/** True when this account or this IP has asked for too many verification codes recently. */
function ev_too_many($conn, $user_id, $ip) {
    ev_ensure_table($conn);
    $w = OTP_WINDOW_MIN;
    $by_user = otp_count($conn,
        "SELECT COUNT(*) AS c FROM email_verifications WHERE user_id = ? AND created_at > NOW() - INTERVAL ? MINUTE",
        'ii', [$user_id, $w]);
    $by_ip = otp_count($conn,
        "SELECT COUNT(*) AS c FROM email_verifications WHERE requested_ip = ? AND created_at > NOW() - INTERVAL ? MINUTE",
        'si', [$ip, $w]);
    return $by_user >= OTP_MAX_PER_USER || $by_ip >= OTP_MAX_PER_IP;
}

/** Creates a new sign-up code and returns the plain code once, for emailing. */
function ev_issue($conn, $user_id, $ip) {
    ev_ensure_table($conn);
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $hash = hash_hmac('sha256', $code, otp_secret($conn));
    $conn->query("UPDATE email_verifications SET used_at = NOW()
                  WHERE user_id = " . (int) $user_id . " AND used_at IS NULL");
    $ttl = OTP_LIFETIME_MIN;
    $stmt = $conn->prepare("INSERT INTO email_verifications (user_id, code_hash, requested_ip, created_at, expires_at)
                            VALUES (?, ?, ?, NOW(), NOW() + INTERVAL ? MINUTE)");
    $stmt->bind_param('issi', $user_id, $hash, $ip, $ttl);
    $stmt->execute();
    $stmt->close();
    return $code;
}

/** Checks a sign-up code. Returns true and burns the code on success. */
function ev_verify($conn, $user_id, $code) {
    ev_ensure_table($conn);
    if (!preg_match('/^\d{6}$/', (string) $code)) return false;
    $stmt = $conn->prepare("SELECT verify_id, code_hash, attempts FROM email_verifications
                            WHERE user_id = ? AND used_at IS NULL AND expires_at > NOW()
                            ORDER BY verify_id DESC LIMIT 1");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || (int) $row['attempts'] >= OTP_MAX_ATTEMPTS) return false;

    $expected = hash_hmac('sha256', $code, otp_secret($conn));
    $vid = (int) $row['verify_id'];
    if (hash_equals($row['code_hash'], $expected)) {
        $conn->query("UPDATE email_verifications SET used_at = NOW(), attempts = attempts + 1 WHERE verify_id = $vid");
        return true;
    }
    $conn->query("UPDATE email_verifications SET attempts = attempts + 1 WHERE verify_id = $vid");
    if ((int) $row['attempts'] + 1 >= OTP_MAX_ATTEMPTS) {
        $conn->query("UPDATE email_verifications SET used_at = NOW() WHERE verify_id = $vid");
    }
    return false;
}

/** Sends the sign-up verification code by email. */
function ev_send_email($to_email, $to_name, $code) {
    return cso_send_mail($to_email, $to_name, 'Verify your CSO email address',
        "Welcome to CSO-NORSU!\n\nYour email verification code is: $code\n\n"
        . 'It expires in ' . OTP_LIFETIME_MIN . " minutes and works once.\n"
        . "If you did not sign up for a CSO account, you can ignore this email.");
}

/**
 * Marks the account as verified and applies the normal sign-up rules:
 * students go live straight away unless officer approval is switched on;
 * faculty always wait for an admin.
 * Returns 'active' or 'pending'.
 */
function ev_activate($conn, $user_id) {
    $stmt = $conn->prepare("SELECT user_type FROM users WHERE user_id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $is_faculty = (($row['user_type'] ?? 'student') === 'faculty');
    $needs_approval = $is_faculty || member_approval_required($conn);
    $status  = $needs_approval ? 'inactive' : 'active';
    $pending = ($needs_approval && !$is_faculty) ? 1 : 0;

    $stmt = $conn->prepare("UPDATE users SET email_verified = 1, status = ?, approval_pending = ? WHERE user_id = ?");
    $stmt->bind_param('sii', $status, $pending, $user_id);
    $stmt->execute();
    $stmt->close();
    return $needs_approval ? 'pending' : 'active';
}
