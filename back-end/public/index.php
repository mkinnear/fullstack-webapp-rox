<?php

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/Auth.php';

$allowedOrigin = getenv('FRONTEND_URL') ?: '*'; // '*' only as a local-dev fallback
header("Access-Control-Allow-Origin: $allowedOrigin");
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

// Browsers send a preflight OPTIONS request whenever a custom header
// (Authorization) is used -- it must succeed before the real request fires.
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST, OPTIONS');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

try {
    $pdo = getConnection();
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

function body(): array {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

function publicUser(array $user): array {
    return [
        'id' => (int) $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'subscriptionTier' => $user['subscription_tier'],
    ];
}

/* ---------------- AUTH ---------------- */

if ($path === 'api/auth/signup' && $method === 'POST') {
    $data = body();
    $name = trim($data['name'] ?? '');
    $email = strtolower(trim($data['email'] ?? ''));
    $password = (string) ($data['password'] ?? '');

    if ($name === '' || $email === '' || strlen($password) < 8) {
        http_response_code(400);
        echo json_encode(['error' => 'Name, email, and a password of at least 8 characters are required.']);
        exit;
    }

    $exists = $pdo->prepare('SELECT 1 FROM users WHERE email = :email');
    $exists->execute(['email' => $email]);
    if ($exists->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'An account with that email already exists.']);
        exit;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :hash)
         RETURNING id, name, email, subscription_tier'
    );
    $stmt->execute([
        'name' => $name,
        'email' => $email,
        'hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $token = createSession($pdo, (int) $user['id']);

    echo json_encode(['token' => $token, 'user' => publicUser($user)]);
    exit;
}

if ($path === 'api/auth/login' && $method === 'POST') {
    $data = body();
    $email = strtolower(trim($data['email'] ?? ''));
    $password = (string) ($data['password'] ?? '');

    $stmt = $pdo->prepare('SELECT id, name, email, password_hash, subscription_tier FROM users WHERE email = :email');
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password_hash'])) {
        http_response_code(401);
        echo json_encode(['error' => "That email and password don't match an account."]);
        exit;
    }

    $token = createSession($pdo, (int) $user['id']);
    echo json_encode(['token' => $token, 'user' => publicUser($user)]);
    exit;
}

if ($path === 'api/auth/logout' && $method === 'POST') {
    $token = bearerToken();
    if ($token) {
        destroySession($pdo, $token);
    }
    echo json_encode(['ok' => true]);
    exit;
}

if ($path === 'api/auth/me' && $method === 'GET') {
    $user = getUserFromToken($pdo, bearerToken());
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Not signed in']);
        exit;
    }
    echo json_encode(['user' => publicUser($user)]);
    exit;
}

/* ---------------- VIDEOS ---------------- */

if ($path === 'api/videos' && $method === 'GET') {
    $stmt = $pdo->query(
        'SELECT id, belt_slug, lesson_number, type, title, duration, instructor, premium
         FROM videos ORDER BY sort_order'
    );
    $videos = array_map(function ($row) {
        return [
            'id' => (int) $row['id'],
            'belt' => $row['belt_slug'],
            'lesson' => (int) $row['lesson_number'],
            'type' => $row['type'],
            'title' => $row['title'],
            'duration' => $row['duration'],
            'instructor' => $row['instructor'],
            'premium' => $row['premium'] === true || $row['premium'] === 't',
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode($videos);
    exit;
}

/* ---------------- TIERS ---------------- */

if ($path === 'api/tiers' && $method === 'GET') {
    $tiersStmt = $pdo->query(
        'SELECT slug, name, price_cents, period, belt_slug, tagline, featured
         FROM tiers ORDER BY sort_order'
    );
    $tiers = $tiersStmt->fetchAll(PDO::FETCH_ASSOC);

    $featuresStmt = $pdo->query('SELECT tier_slug, feature FROM tier_features ORDER BY sort_order');
    $featuresByTier = [];
    foreach ($featuresStmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $featuresByTier[$f['tier_slug']][] = $f['feature'];
    }

    $result = array_map(function ($t) use ($featuresByTier) {
        return [
            'slug' => $t['slug'],
            'name' => $t['name'],
            'priceCents' => (int) $t['price_cents'],
            'period' => $t['period'],
            'belt' => $t['belt_slug'],
            'tagline' => $t['tagline'],
            'featured' => $t['featured'] === true || $t['featured'] === 't',
            'features' => $featuresByTier[$t['slug']] ?? [],
        ];
    }, $tiers);

    echo json_encode($result);
    exit;
}

/* ---------------- SUBSCRIPTIONS ---------------- */

if ($path === 'api/subscriptions' && $method === 'POST') {
    $user = requireAuth($pdo);
    $data = body();
    $tierSlug = $data['tierSlug'] ?? '';

    $tierStmt = $pdo->prepare('SELECT slug FROM tiers WHERE slug = :slug');
    $tierStmt->execute(['slug' => $tierSlug]);
    if (!$tierStmt->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown membership tier.']);
        exit;
    }

    // Proof-of-concept: no real payment is processed here. A production build
    // would create a payment-provider charge/subscription before this write.
    $update = $pdo->prepare(
        'UPDATE users SET subscription_tier = :tier, subscribed_at = NOW() WHERE id = :id
         RETURNING id, name, email, subscription_tier'
    );
    $update->execute(['tier' => $tierSlug, 'id' => $user['id']]);
    $updated = $update->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['user' => publicUser($updated)]);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Not found']);