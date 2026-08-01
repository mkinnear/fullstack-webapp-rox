<?php

/** Apply security headers to every response. Call once, early. */
function applySecurityHeaders(): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    // HSTS only makes sense once you're definitely on HTTPS (Render terminates TLS for you).
    header('Strict-Transport-Security: max-age=63072000; includeSubDomains');
}

/* ---------------- EMAIL (Resend) ---------------- */

/**
 * Sends an email via the Resend API. Returns true on success.
 * Requires RESEND_API_KEY and RESEND_FROM_EMAIL env vars.
 * Never throws on failure -- logs instead, so a flaky email provider
 * can't take down signup/login.
 */
function sendEmail(string $to, string $subject, string $html): bool {
    $apiKey = getenv('RESEND_API_KEY');
    $from = getenv('RESEND_FROM_EMAIL');

    if (!$apiKey || !$from) {
        error_log("sendEmail skipped (RESEND_API_KEY/RESEND_FROM_EMAIL not set): would have sent '$subject' to $to");
        return false;
    }

    $payload = json_encode([
        'from' => $from,
        'to' => [$to],
        'subject' => $subject,
        'html' => $html,
    ]);

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error || $status >= 300) {
        error_log("sendEmail failed (status=$status, error=$error): $response");
        return false;
    }
    return true;
}

function otpEmailHtml(string $name, string $code, string $purposeLabel): string {
    $safeName = htmlspecialchars($name, ENT_QUOTES);
    $safeCode = htmlspecialchars($code, ENT_QUOTES);
    return "
        <div style='font-family:sans-serif;max-width:420px;margin:0 auto;'>
            <h2>IKKO Academy</h2>
            <p>Hi $safeName,</p>
            <p>Your $purposeLabel code is:</p>
            <p style='font-size:32px;font-weight:bold;letter-spacing:4px;'>$safeCode</p>
            <p>This code expires in 10 minutes. If you didn't request this, you can safely ignore this email.</p>
        </div>
    ";
}

/**
 * Sent once, right after a member verifies their email for the first time.
 * Content mirrors the official IKKO Academy member announcement.
 */
function welcomeEmailHtml(string $name): string {
    $safeName = htmlspecialchars($name, ENT_QUOTES);
    return "
        <div style='font-family:sans-serif;max-width:520px;margin:0 auto;line-height:1.6;color:#222;'>
            <h2 style='margin-bottom:0;'>IKKO Academy</h2>
            <p style='color:#666;margin-top:4px;'>Official Digital Learning Platform</p>
            <p>Dear IKKO $safeName,</p>
            <p>We are excited to welcome you to <strong>IKKO Academy</strong> &ndash; the official digital learning platform designed to complement your dojo training and preserve the teachings of IKKO Karate for future generations.</p>
            <p>IKKO Academy has been created to provide students with the opportunity to continue their learning beyond the dojo through structured educational resources aligned with the IKKO curriculum.</p>
            <p>As the Academy develops, members can look forward to:</p>
            <ul style='padding-left:20px;list-style:none;'>
                <li>&#129355; Live online training sessions</li>
                <li>&#127909; Access to lesson recordings</li>
                <li>&#128214; Official IKKO Academy Training Guides</li>
                <li>&#128218; Educational resources and study materials</li>
                <li>&#127919; Curriculum-based learning to support grading preparation</li>
            </ul>
            <p>Our vision is not to replace traditional dojo training, but to extend the learning experience, allowing students to revise lessons, deepen their understanding and continue developing their knowledge, discipline and spirit wherever they are.</p>
            <p style='font-weight:bold;letter-spacing:1px;'>Train &bull; Learn &bull; Grow</p>
            <p>If you would like to find out more about becoming an IKKO Academy member or have any questions, please feel free to contact me directly.</p>
            <p style='margin-bottom:0;'>Roxanne Cassim</p>
            <p style='margin-top:0;color:#666;'>IKKO Academy</p>
        </div>
    ";
}

/* ---------------- RATE LIMITING ---------------- */

/**
 * Records an attempt and returns true if the identifier has exceeded
 * $maxAttempts within $windowMinutes. Call this BEFORE doing the
 * expensive/sensitive work (password check, email send, etc).
 */
function isRateLimited(PDO $pdo, string $identifier, int $maxAttempts, int $windowMinutes): bool {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM rate_limit_attempts
         WHERE identifier = :id AND created_at > NOW() - (:mins || ' minutes')::interval"
    );
    $stmt->execute(['id' => $identifier, 'mins' => $windowMinutes]);
    $count = (int) $stmt->fetchColumn();

    $insert = $pdo->prepare('INSERT INTO rate_limit_attempts (identifier) VALUES (:id)');
    $insert->execute(['id' => $identifier]);

    return $count >= $maxAttempts;
}

function clientIp(): string {
    return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/* ---------------- VALIDATION ---------------- */

function isValidEmail(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false && strlen($email) <= 255;
}

function isStrongEnoughPassword(string $password): bool {
    return strlen($password) >= 8 && strlen($password) <= 200;
}

/** Generic 6-digit numeric OTP, cryptographically random. */
function generateOtpCode(): string {
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}