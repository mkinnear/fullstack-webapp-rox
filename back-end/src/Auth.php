<?php

const SESSION_LIFETIME_DAYS = 30;      // regular members
const ADMIN_SESSION_LIFETIME_HOURS = 12; // admin/super_admin -- higher privilege, shorter leash
const OTP_LIFETIME_MINUTES = 10;

/* ---------------- SESSIONS (hashed tokens) ---------------- */

function createSession(PDO $pdo, int $userId, ?int $lifetimeHours = null): string {
    $hours = $lifetimeHours ?? (SESSION_LIFETIME_DAYS * 24);
    $token = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare(
        "INSERT INTO sessions (token_hash, user_id, expires_at)
         VALUES (:hash, :user_id, NOW() + (:hours || ' hours')::interval)"
    );
    $stmt->execute([
        'hash' => hash('sha256', $token),
        'user_id' => $userId,
        'hours' => $hours,
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
                u.trial_ends_at, u.trial_used, u.current_belt, u.stripes, u.next_grading_date,
                u.target_belt, u.created_at, u.role,
                u.account_status, u.stripe_customer_id, u.stripe_subscription_id
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

/** Returns the authenticated admin (regular admin OR super_admin) or halts with 401/403. */
function requireAdmin(PDO $pdo): array {
    $user = requireAuth($pdo);
    $role = $user['role'] ?? ($user['is_admin'] === true || $user['is_admin'] === 't' ? 'admin' : 'user');
    if ($role !== 'admin' && $role !== 'super_admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Admin access required.']);
        exit;
    }
    return $user;
}

/** Returns the authenticated super admin or halts with 401/403. Only a super
 *  admin can create accounts, grant/revoke admin, or touch other staff accounts. */
function requireSuperAdmin(PDO $pdo): array {
    $user = requireAuth($pdo);
    if (($user['role'] ?? 'user') !== 'super_admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Super admin access required.']);
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