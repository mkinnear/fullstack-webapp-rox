<?php

declare(strict_types=1);

require __DIR__ . '../../vendor/autoload.php';

/** Apply security headers to every response. Call once, early. */
function applySecurityHeaders(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    // HSTS only makes sense once you're definitely on HTTPS (Render terminates TLS for you).
    header('Strict-Transport-Security: max-age=63072000; includeSubDomains');
}

/* ---------------- EMAIL (Resend) ---------------- */

/**
 * Sends an email through the official Resend PHP SDK.
 *
 * Required environment variables:
 * - RESEND_API_KEY
 * - RESEND_FROM_EMAIL
 *
 * Returns true when Resend accepts the message. It never throws to callers;
 * failures are logged so email-provider issues do not crash signup/login.
 */
function sendEmail(string $to, string $subject, string $html): bool
{
    $apiKey = trim((string) getenv('RESEND_API_KEY'));
    $from = trim((string) getenv('RESEND_FROM_EMAIL'));

    if ($apiKey === '' || $from === '') {
        error_log(
            "sendEmail skipped (RESEND_API_KEY/RESEND_FROM_EMAIL not set): " .
            "would have sent '" . $subject . "' to " . $to
        );
        return false;
    }

    if (!isValidEmail($to)) {
        error_log("sendEmail skipped: invalid recipient address '$to'");
        return false;
    }

    try {
        $resend = Resend::client($apiKey);

        $result = $resend->emails->send([
            'from' => $from,
            'to' => [$to],
            'subject' => $subject,
            'html' => $html,
        ]);

        $resultData = method_exists($result, 'toArray')
            ? $result->toArray()
            : ['result_type' => get_debug_type($result)];

        error_log(
            'sendEmail succeeded: ' .
            json_encode(
                $resultData,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            )
        );

        return true;
    } catch (Throwable $e) {
        error_log(
            'sendEmail failed: ' . get_class($e) . ': ' . $e->getMessage()
        );
        return false;
    }
}

function otpEmailHtml(string $name, string $code, string $purposeLabel): string
{
    $safeName = htmlspecialchars(
        $name,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    $safeCode = htmlspecialchars(
        $code,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    $safePurpose = htmlspecialchars(
        $purposeLabel,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    return "
        <div style='font-family:sans-serif;max-width:420px;margin:0 auto;'>
            <h2>IKKO Academy</h2>
            <p>Hi $safeName,</p>
            <p>Your $safePurpose code is:</p>
            <p style='font-size:32px;font-weight:bold;letter-spacing:4px;'>$safeCode</p>
            <p>This code expires in 10 minutes. If you didn't request this, you can safely ignore this email.</p>
        </div>
    ";
}

/**
 * Sent once, right after a member verifies their email for the first time.
 * Content mirrors the official IKKO Academy member announcement.
 */
function welcomeEmailHtml(string $name): string
{
    $safeName = htmlspecialchars(
        $name,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    return "
        <div style='font-family:sans-serif;max-width:520px;margin:0 auto;line-height:1.6;color:#222;'>
            <h2 style='margin-bottom:0;'>IKKO Academy</h2>
            <p style='color:#666;margin-top:4px;'>Official Digital Learning Platform</p>

            <p>Dear IKKO $safeName,</p>

            <p>
                We are excited to welcome you to <strong>IKKO Academy</strong>
                &ndash; the official digital learning platform designed to
                complement your dojo training and preserve the teachings of
                IKKO Karate for future generations.
            </p>

            <p>
                IKKO Academy has been created to provide students with the
                opportunity to continue their learning beyond the dojo through
                structured educational resources aligned with the IKKO curriculum.
            </p>

            <p>As the Academy develops, members can look forward to:</p>

            <ul style='padding-left:20px;list-style:none;'>
                <li>&#129355; Live online training sessions</li>
                <li>&#127909; Access to lesson recordings</li>
                <li>&#128214; Official IKKO Academy Training Guides</li>
                <li>&#128218; Educational resources and study materials</li>
                <li>&#127919; Curriculum-based learning to support grading preparation</li>
            </ul>

            <p>
                Our vision is not to replace traditional dojo training, but to
                extend the learning experience, allowing students to revise
                lessons, deepen their understanding and continue developing
                their knowledge, discipline and spirit wherever they are.
            </p>

            <p style='font-weight:bold;letter-spacing:1px;'>
                Train &bull; Learn &bull; Grow
            </p>

            <p>
                If you would like to find out more about becoming an IKKO
                Academy member or have any questions, please feel free to
                contact me directly.
            </p>

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
function isRateLimited(
    PDO $pdo,
    string $identifier,
    int $maxAttempts,
    int $windowMinutes
): bool {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM rate_limit_attempts
         WHERE identifier = :id
         AND created_at > NOW() - (:mins || ' minutes')::interval"
    );

    $stmt->execute([
        'id' => $identifier,
        'mins' => $windowMinutes,
    ]);

    $count = (int) $stmt->fetchColumn();

    $insert = $pdo->prepare(
        'INSERT INTO rate_limit_attempts (identifier) VALUES (:id)'
    );

    $insert->execute([
        'id' => $identifier,
    ]);

    return $count >= $maxAttempts;
}

function clientIp(): string
{
    return $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? 'unknown';
}

/* ---------------- VALIDATION ---------------- */

function isValidEmail(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        && strlen($email) <= 255;
}

function isStrongEnoughPassword(string $password): bool
{
    return strlen($password) >= 8
        && strlen($password) <= 200;
}

/** Generic 6-digit numeric OTP, cryptographically random. */
function generateOtpCode(): string
{
    return str_pad(
        (string) random_int(0, 999999),
        6,
        '0',
        STR_PAD_LEFT
    );
}