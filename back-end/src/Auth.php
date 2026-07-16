<?php

const SESSION_LIFETIME_DAYS = 30;

function createSession(PDO $pdo, int $userId): string {
    $token = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare(
        "INSERT INTO sessions (token, user_id, expires_at)
         VALUES (:token, :user_id, NOW() + (:days || ' days')::interval)"
    );
    $stmt->execute([
        'token' => $token,
        'user_id' => $userId,
        'days' => SESSION_LIFETIME_DAYS,
    ]);
    return $token;
}

function destroySession(PDO $pdo, string $token): void {
    $stmt = $pdo->prepare('DELETE FROM sessions WHERE token = :token');
    $stmt->execute(['token' => $token]);
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
        "SELECT u.id, u.name, u.email, u.subscription_tier
         FROM sessions s
         JOIN users u ON u.id = s.user_id
         WHERE s.token = :token AND s.expires_at > NOW()"
    );
    $stmt->execute(['token' => $token]);
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