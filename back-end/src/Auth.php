<?php

const SESSION_LIFETIME_DAYS = 30;
const OTP_LIFETIME_MINUTES = 10;

// A trial counts as active only while trial_ends_at hasn't passed; a paid
// tier counts as active as long as it's set AND the account isn't paused.
// Computed in SQL so it's always consistent with the DB's clock, not PHP's.
const USER_ACTIVE_SQL = "(
    (subscription_tier = 'trial' AND trial_ends_at > NOW())
    OR (subscription_tier IS NOT NULL AND subscription_tier <> 'trial' AND account_status = 'active')
) AS subscription_active";

/* ---------------- SESSIONS (hashed tokens) ---------------- */

function createSession(PDO $pdo, int $userId): string {
    $token = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare(
        "INSERT INTO sessions (token_hash, user_id, expires_at)
         VALUES (:hash, :user_id, NOW() + (:days || ' days')::interval)"
    );
    $stmt->execute([
        'hash' => hash('sha256', $token),
        'user_id' => $userId,
        'days' => SESSION_LIFETIME_DAYS,
    ]);
    return $token; // raw token goes to the client; only the hash is stored
}

function destroySession(PDO $pdo, string $token): void {
    $stmt = $pdo->prepare('DELETE FROM sessions WHERE token_hash = :hash');
    $stmt->execute(['hash' => hash('sha256', $token)]);
}

/** Invalidate every session for a user -- used after a password reset. */
function destroyAllSessions(PDO $pdo, int $userId): void {
    $stmt = $pdo->prepare('DELETE FROM sessions WHERE user_id = :id');
    $stmt->execute(['id' => $userId]);
}

function bearerToken(): ?string {
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? (function_exists('getallheaders') ? (getallheaders()['Authorization'] ?? null) : null);

    if ($header && stripos($header, 'Bearer ') === 0) {
        return trim(substr($header, 7));
    }
    return null;
}

function getUserFromToken(PDO $pdo, ?string $token): ?array {
    if (!$token) {
        return null;
    }
    $stmt = $pdo->prepare(
        "SELECT u.id, u.name, u.email, u.subscription_tier, u.is_admin, u.email_verified,
                u.trial_ends_at, u.trial_used, u.account_status, u.stripe_customer_id, u.stripe_subscription_id,
                " . USER_ACTIVE_SQL . "
         FROM sessions s
         JOIN users u ON u.id = s.user_id
         WHERE s.token_hash = :hash AND s.expires_at > NOW()"
    );
    $stmt->execute(['hash' => hash('sha256', $token)]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user ?: null;
}

/** Returns the authenticated user or halts the request with a 401. */
function requireAuth(PDO $pdo): array {
    $user = getUserFromToken($pdo, bearerToken());
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Sign in to continue.']);
        exit;
    }
    return $user;
}

/** Returns the authenticated admin or halts with 401/403. */
function requireAdmin(PDO $pdo): array {
    $user = requireAuth($pdo);
    $isAdmin = $user['is_admin'] === true || $user['is_admin'] === 't';
    if (!$isAdmin) {
        http_response_code(403);
        echo json_encode(['error' => 'Admin access required.']);
        exit;
    }
    return $user;
}

/* ---------------- OTP (email verification / password reset) ---------------- */

function issueOtp(PDO $pdo, int $userId, string $purpose): string {
    $code = generateOtpCode();
    $stmt = $pdo->prepare(
        "INSERT INTO otp_codes (user_id, code_hash, purpose, expires_at)
         VALUES (:user_id, :hash, :purpose, NOW() + (:mins || ' minutes')::interval)"
    );
    $stmt->execute([
        'user_id' => $userId,
        'hash' => hash('sha256', $code),
        'purpose' => $purpose,
        'mins' => OTP_LIFETIME_MINUTES,
    ]);
    return $code; // raw code is emailed to the user; only the hash is stored
}

/** Verifies a code and marks it consumed. Returns true if valid. */
function consumeOtp(PDO $pdo, int $userId, string $purpose, string $code): bool {
    $stmt = $pdo->prepare(
        "SELECT id, code_hash FROM otp_codes
         WHERE user_id = :user_id AND purpose = :purpose
           AND consumed_at IS NULL AND expires_at > NOW()
         ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute(['user_id' => $userId, 'purpose' => $purpose]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || !hash_equals($row['code_hash'], hash('sha256', $code))) {
        return false;
    }

    $update = $pdo->prepare('UPDATE otp_codes SET consumed_at = NOW() WHERE id = :id');
    $update->execute(['id' => $row['id']]);
    return true;
}