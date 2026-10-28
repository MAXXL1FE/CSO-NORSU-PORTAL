<?php
/**
 * Outgoing email (Resend).
 *
 * Used for the sign-up email verification code, the password-reset code, and
 * the member-approval / event notices.
 *
 * OTP_MAIL_FROM must be an address on a domain verified in the Resend
 * dashboard (Domains > Add domain). Until a domain is verified, Resend only
 * accepts the shared sender 'onboarding@resend.dev', and it will only deliver
 * to the email address that owns the Resend account — other recipients are
 * rejected. Verify the CSO domain before real sign-ups go through.
 *
 * Leave OTP_MAIL_API_KEY empty to turn email off; password resets then work
 * through an officer-issued code (Dashboard > Reset Codes) and sign-ups skip
 * the email step.
 */
$secrets = file_exists(__DIR__ . '/secrets.php') ? require __DIR__ . '/secrets.php' : [];

if (!defined('OTP_MAIL_API_KEY'))   define('OTP_MAIL_API_KEY', $secrets['resend_api_key'] ?? '');
if (!defined('OTP_MAIL_FROM'))      define('OTP_MAIL_FROM', 'onboarding@resend.dev');
if (!defined('OTP_MAIL_FROM_NAME')) define('OTP_MAIL_FROM_NAME', 'CSO-NORSU');
