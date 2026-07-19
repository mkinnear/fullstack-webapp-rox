<?php

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/Auth.php';
require __DIR__ . '/../src/Security.php';
require __DIR__ . '/../src/Payments.php';

applySecurityHeaders();

$allowedOrigin = getenv('FRONTEND_URL') ?: '*'; // '*' only as a local-dev fallback -- set FRONTEND_URL in production
header("Access-Control-Allow-Origin: $allowedOrigin");
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Stripe-Signature');
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE'], true)) {
    http_response_code(405);
    header('Allow: GET, POST, PUT, DELETE, OPTIONS');
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
    $decoded = json_decode(file_get_contents('php://input'), true);
    return is_array($decoded) ? $decoded : [];
}

function publicUser(array $user): array {
    return [
        'id' => (int) $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'subscriptionTier' => $user['subscription_tier'],
        'subscriptionActive' => toBool($user['subscription_active'] ?? false),
        'trialEndsAt' => $user['trial_ends_at'] ?? null,
        'trialUsed' => toBool($user['trial_used'] ?? false),
        'isAdmin' => toBool($user['is_admin']),
        'emailVerified' => toBool($user['email_verified']),
    ];
}

function toBool($v): bool {
    return $v === true || $v === 't';
}

// Everything below runs inside a try/catch so an unexpected error returns a
// generic 500 instead of leaking a PHP stack trace (which can expose file
// paths, query structure, etc. to an attacker).
try {

/* ============================================================
   AUTH
   ============================================================ */

if ($path === 'api/auth/signup' && $method === 'POST') {
    $data = body();
    $name = trim($data['name'] ?? '');
    $email = strtolower(trim($data['email'] ?? ''));
    $password = (string) ($data['password'] ?? '');

    if (isRateLimited($pdo, 'signup:' . clientIp(), 10, 60)) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many signups from this connection. Please try again later.']);
        exit;
    }
    if ($name === '' || !isValidEmail($email) || !isStrongEnoughPassword($password)) {
        http_response_code(400);
        echo json_encode(['error' => 'Enter a name, a valid email, and a password of at least 8 characters.']);
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
         RETURNING id, name, email, subscription_tier, is_admin, email_verified, trial_ends_at, trial_used, ' . USER_ACTIVE_SQL
    );
    $stmt->execute([
        'name' => $name,
        'email' => $email,
        'hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $code = issueOtp($pdo, (int) $user['id'], 'verify_email');
    sendEmail($email, 'Verify your IKKO Academy account', otpEmailHtml($name, $code, 'email verification'));

    $token = createSession($pdo, (int) $user['id']);
    echo json_encode(['token' => $token, 'user' => publicUser($user)]);
    exit;
}

if ($path === 'api/auth/login' && $method === 'POST') {
    $data = body();
    $email = strtolower(trim($data['email'] ?? ''));
    $password = (string) ($data['password'] ?? '');
    $rateKey = 'login:' . $email . ':' . clientIp();

    if (isRateLimited($pdo, $rateKey, 8, 15)) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many attempts. Please wait a few minutes and try again.']);
        exit;
    }

    $stmt = $pdo->prepare(
        'SELECT id, name, email, password_hash, subscription_tier, is_admin, email_verified, locked_until,
                trial_ends_at, trial_used, ' . USER_ACTIVE_SQL . '
         FROM users WHERE email = :email'
    );
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && $user['locked_until'] && strtotime($user['locked_until']) > time()) {
        http_response_code(423);
        echo json_encode(['error' => 'This account is temporarily locked due to repeated failed attempts. Try again shortly.']);
        exit;
    }

    if (!$user || !password_verify($password, $user['password_hash'])) {
        if ($user) {
            // Lock the account after 5 consecutive failures, for 15 minutes.
            $fails = $pdo->prepare(
                'UPDATE users SET failed_login_attempts = failed_login_attempts + 1,
                 locked_until = CASE WHEN failed_login_attempts + 1 >= 5 THEN NOW() + INTERVAL \'15 minutes\' ELSE locked_until END
                 WHERE id = :id'
            );
            $fails->execute(['id' => $user['id']]);
        }
        http_response_code(401);
        echo json_encode(['error' => "That email and password don't match an account."]);
        exit;
    }

    $reset = $pdo->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = :id');
    $reset->execute(['id' => $user['id']]);

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

if ($path === 'api/auth/verify-email' && $method === 'POST') {
    $user = requireAuth($pdo);
    $code = trim((string) (body()['code'] ?? ''));

    if (!consumeOtp($pdo, (int) $user['id'], 'verify_email', $code)) {
        http_response_code(400);
        echo json_encode(['error' => 'That code is invalid or has expired.']);
        exit;
    }
    $update = $pdo->prepare('UPDATE users SET email_verified = TRUE WHERE id = :id');
    $update->execute(['id' => $user['id']]);

    echo json_encode(['ok' => true]);
    exit;
}

if ($path === 'api/auth/resend-verification' && $method === 'POST') {
    $user = requireAuth($pdo);
    if (isRateLimited($pdo, 'resend-otp:' . $user['id'], 3, 10)) {
        http_response_code(429);
        echo json_encode(['error' => 'Please wait a few minutes before requesting another code.']);
        exit;
    }
    $code = issueOtp($pdo, (int) $user['id'], 'verify_email');
    sendEmail($user['email'], 'Your IKKO Academy verification code', otpEmailHtml($user['name'], $code, 'email verification'));
    echo json_encode(['ok' => true]);
    exit;
}

if ($path === 'api/auth/forgot-password' && $method === 'POST') {
    $email = strtolower(trim(body()['email'] ?? ''));

    if (isRateLimited($pdo, 'forgot-password:' . $email . ':' . clientIp(), 5, 30)) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many requests. Please try again later.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id, name, email FROM users WHERE email = :email');
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Always return the same response whether or not the account exists --
    // otherwise this endpoint becomes a way to discover which emails are registered.
    if ($user) {
        $code = issueOtp($pdo, (int) $user['id'], 'password_reset');
        sendEmail($user['email'], 'Reset your IKKO Academy password', otpEmailHtml($user['name'], $code, 'password reset'));
    }
    echo json_encode(['ok' => true, 'message' => 'If that email is registered, a reset code has been sent.']);
    exit;
}

if ($path === 'api/auth/reset-password' && $method === 'POST') {
    $data = body();
    $email = strtolower(trim($data['email'] ?? ''));
    $code = trim((string) ($data['code'] ?? ''));
    $newPassword = (string) ($data['newPassword'] ?? '');

    if (isRateLimited($pdo, 'reset-password:' . $email . ':' . clientIp(), 8, 15)) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many attempts. Please try again later.']);
        exit;
    }
    if (!isStrongEnoughPassword($newPassword)) {
        http_response_code(400);
        echo json_encode(['error' => 'Password must be at least 8 characters.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email');
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !consumeOtp($pdo, (int) $user['id'], 'password_reset', $code)) {
        http_response_code(400);
        echo json_encode(['error' => 'That code is invalid or has expired.']);
        exit;
    }

    $update = $pdo->prepare('UPDATE users SET password_hash = :hash, failed_login_attempts = 0, locked_until = NULL WHERE id = :id');
    $update->execute(['hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $user['id']]);

    // Force re-login everywhere -- a leaked old session token becomes useless.
    destroyAllSessions($pdo, (int) $user['id']);

    echo json_encode(['ok' => true]);
    exit;
}

/* ============================================================
   PUBLIC CONTENT (videos, tiers, CMS text)
   ============================================================ */

if ($path === 'api/videos' && $method === 'GET') {
    $stmt = $pdo->query(
        'SELECT id, belt_slug, lesson_number, type, title, caption, video_url, duration, instructor, premium
         FROM videos ORDER BY sort_order'
    );
    $videos = array_map(function ($row) {
        return [
            'id' => (int) $row['id'],
            'belt' => $row['belt_slug'],
            'lesson' => (int) $row['lesson_number'],
            'type' => $row['type'],
            'title' => $row['title'],
            'caption' => $row['caption'],
            'videoUrl' => $row['video_url'],
            'duration' => $row['duration'],
            'instructor' => $row['instructor'],
            'premium' => toBool($row['premium']),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    echo json_encode($videos);
    exit;
}

if ($path === 'api/tiers' && $method === 'GET') {
    $tiersStmt = $pdo->query('SELECT slug, name, price_cents, period, belt_slug, tagline, featured FROM tiers ORDER BY sort_order');
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
            'featured' => toBool($t['featured']),
            'features' => $featuresByTier[$t['slug']] ?? [],
        ];
    }, $tiers);
    echo json_encode($result);
    exit;
}

if ($path === 'api/content' && $method === 'GET') {
    $stmt = $pdo->query('SELECT key, value FROM content_blocks');
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[$row['key']] = $row['value'];
    }
    echo json_encode($map);
    exit;
}

if ($path === 'api/events' && $method === 'GET') {
    $stmt = $pdo->query(
        'SELECT id, title, description, event_date, location, image_url, link_url
         FROM events ORDER BY (event_date IS NULL), event_date ASC, sort_order ASC'
    );
    $events = array_map(function ($row) {
        return [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'description' => $row['description'],
            'eventDate' => $row['event_date'],
            'location' => $row['location'],
            'imageUrl' => $row['image_url'],
            'linkUrl' => $row['link_url'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    echo json_encode($events);
    exit;
}

/* ============================================================
   SUBSCRIPTIONS & PAYMENTS
   ============================================================ */

// The 7-day free trial -- no payment required, so there's nothing for
// Stripe to verify. Enforced server-side as one-time-use per account.
if ($path === 'api/subscriptions' && $method === 'POST') {
    $user = requireAuth($pdo);
    $tierSlug = body()['tierSlug'] ?? '';

    $tierStmt = $pdo->prepare('SELECT slug, price_cents, trial_days FROM tiers WHERE slug = :slug');
    $tierStmt->execute(['slug' => $tierSlug]);
    $tier = $tierStmt->fetch(PDO::FETCH_ASSOC);

    if (!$tier) {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown membership tier.']);
        exit;
    }
    if ((int) $tier['price_cents'] > 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Paid tiers must go through checkout.', 'requiresCheckout' => true]);
        exit;
    }
    if (toBool($user['trial_used'])) {
        http_response_code(409);
        echo json_encode(['error' => "You've already used your free trial. Choose a paid plan to continue."]);
        exit;
    }

    $trialDays = (int) ($tier['trial_days'] ?? 7);
    $update = $pdo->prepare(
        "UPDATE users
         SET subscription_tier = :tier, subscribed_at = NOW(),
             trial_ends_at = NOW() + (:days || ' days')::interval, trial_used = TRUE
         WHERE id = :id
         RETURNING id, name, email, subscription_tier, is_admin, email_verified, trial_ends_at, trial_used, " . USER_ACTIVE_SQL
    );
    $update->execute(['tier' => $tierSlug, 'days' => $trialDays, 'id' => $user['id']]);
    echo json_encode(['user' => publicUser($update->fetch(PDO::FETCH_ASSOC))]);
    exit;
}

// Paid tiers: create a Stripe-hosted checkout session. The subscription is
// NOT granted here -- only after Stripe confirms payment via webhook below.
if ($path === 'api/checkout/create-session' && $method === 'POST') {
    $user = requireAuth($pdo);
    $tierSlug = body()['tierSlug'] ?? '';

    $tierStmt = $pdo->prepare('SELECT * FROM tiers WHERE slug = :slug');
    $tierStmt->execute(['slug' => $tierSlug]);
    $tier = $tierStmt->fetch(PDO::FETCH_ASSOC);

    if (!$tier || (int) $tier['price_cents'] === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown or free tier.']);
        exit;
    }

    $result = createStripeCheckoutSession(['id' => $user['id'], 'email' => $user['email']], $tier);
    if (isset($result['error'])) {
        http_response_code(502);
        echo json_encode(['error' => $result['error']]);
        exit;
    }
    echo json_encode($result);
    exit;
}

// Stripe calls this directly (not the browser). Signature-verified, so we
// can trust its payload without the user having had any way to forge it.
if ($path === 'api/webhooks/stripe' && $method === 'POST') {
    $payload = file_get_contents('php://input');
    $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? null;
    $webhookSecret = getenv('STRIPE_WEBHOOK_SECRET');

    if (!$webhookSecret || !verifyStripeWebhookSignature($payload, $sigHeader, $webhookSecret)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }

    $event = json_decode($payload, true);
    if (($event['type'] ?? '') === 'checkout.session.completed') {
        $session = $event['data']['object'];
        $userId = (int) ($session['metadata']['user_id'] ?? 0);
        $tierSlug = $session['metadata']['tier_slug'] ?? null;

        if ($userId && $tierSlug) {
            $update = $pdo->prepare('UPDATE users SET subscription_tier = :tier, subscribed_at = NOW(), stripe_customer_id = :cust WHERE id = :id');
            $update->execute(['tier' => $tierSlug, 'cust' => $session['customer'] ?? null, 'id' => $userId]);

            $tierRow = $pdo->prepare('SELECT price_cents FROM tiers WHERE slug = :slug');
            $tierRow->execute(['slug' => $tierSlug]);
            $priceCents = (int) $tierRow->fetchColumn();

            $record = $pdo->prepare(
                'INSERT INTO payments (user_id, tier_slug, stripe_session_id, amount_cents, status)
                 VALUES (:uid, :tier, :sid, :amount, \'completed\')
                 ON CONFLICT (stripe_session_id) DO NOTHING'
            );
            $record->execute(['uid' => $userId, 'tier' => $tierSlug, 'sid' => $session['id'], 'amount' => $priceCents]);
        }
    }

    echo json_encode(['received' => true]);
    exit;
}

/* ============================================================
   ADMIN: content editing
   ============================================================ */

if ($path === 'api/admin/content' && $method === 'PUT') {
    requireAdmin($pdo);
    $data = body();
    $key = trim($data['key'] ?? '');
    $value = (string) ($data['value'] ?? '');

    if ($key === '' || strlen($value) > 5000) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid key or value too long.']);
        exit;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO content_blocks (key, value, updated_at) VALUES (:key, :value, NOW())
         ON CONFLICT (key) DO UPDATE SET value = :value, updated_at = NOW()'
    );
    $stmt->execute(['key' => $key, 'value' => $value]);
    echo json_encode(['ok' => true]);
    exit;
}

/* ============================================================
   ADMIN: video management
   ============================================================ */

if ($path === 'api/admin/videos' && $method === 'POST') {
    requireAdmin($pdo);
    $d = body();

    $title = trim($d['title'] ?? '');
    $belt = trim($d['belt'] ?? '');
    if ($title === '' || !in_array($belt, ['white','yellow','orange','green','blue','purple','brown','black'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Title and a valid belt are required.']);
        exit;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO videos (belt_slug, lesson_number, type, title, caption, video_url, duration, instructor, premium, sort_order)
         VALUES (:belt, :lesson, :type, :title, :caption, :url, :duration, :instructor, :premium, :sort)
         RETURNING id'
    );
    $stmt->execute([
        'belt' => $belt,
        'lesson' => (int) ($d['lesson'] ?? 1),
        'type' => trim($d['type'] ?? 'Kihon'),
        'title' => $title,
        'caption' => trim($d['caption'] ?? ''),
        'url' => trim($d['videoUrl'] ?? ''),
        'duration' => trim($d['duration'] ?? '0:00'),
        'instructor' => trim($d['instructor'] ?? ''),
        'premium' => !empty($d['premium']),
        'sort' => (int) ($d['sortOrder'] ?? 999),
    ]);
    echo json_encode(['id' => (int) $stmt->fetchColumn()]);
    exit;
}

if (preg_match('#^api/admin/videos/(\d+)$#', $path, $m) && in_array($method, ['PUT', 'DELETE'], true)) {
    requireAdmin($pdo);
    $id = (int) $m[1];

    if ($method === 'DELETE') {
        $stmt = $pdo->prepare('DELETE FROM videos WHERE id = :id');
        $stmt->execute(['id' => $id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // PUT: partial update, only touch fields that were actually sent.
    $d = body();
    $fields = ['belt_slug' => 'belt', 'lesson_number' => 'lesson', 'type' => 'type', 'title' => 'title',
               'caption' => 'caption', 'video_url' => 'videoUrl', 'duration' => 'duration',
               'instructor' => 'instructor', 'premium' => 'premium', 'sort_order' => 'sortOrder'];
    $sets = [];
    $params = ['id' => $id];
    foreach ($fields as $col => $jsonKey) {
        if (array_key_exists($jsonKey, $d)) {
            $sets[] = "$col = :$col";
            $params[$col] = $d[$jsonKey];
        }
    }
    if (empty($sets)) {
        http_response_code(400);
        echo json_encode(['error' => 'No fields to update.']);
        exit;
    }
    $stmt = $pdo->prepare('UPDATE videos SET ' . implode(', ', $sets) . ' WHERE id = :id');
    $stmt->execute($params);
    echo json_encode(['ok' => true]);
    exit;
}

/* ============================================================
   ADMIN: events management
   ============================================================ */

if ($path === 'api/admin/events' && $method === 'POST') {
    requireAdmin($pdo);
    $d = body();

    $title = trim($d['title'] ?? '');
    if ($title === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Title is required.']);
        exit;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO events (title, description, event_date, location, image_url, link_url, sort_order)
         VALUES (:title, :description, NULLIF(:date, \'\')::date, :location, :image, :link, :sort)
         RETURNING id'
    );
    $stmt->execute([
        'title' => $title,
        'description' => trim($d['description'] ?? ''),
        'date' => trim($d['eventDate'] ?? ''),
        'location' => trim($d['location'] ?? ''),
        'image' => trim($d['imageUrl'] ?? ''),
        'link' => trim($d['linkUrl'] ?? ''),
        'sort' => (int) ($d['sortOrder'] ?? 999),
    ]);
    echo json_encode(['id' => (int) $stmt->fetchColumn()]);
    exit;
}

if (preg_match('#^api/admin/events/(\d+)$#', $path, $m) && in_array($method, ['PUT', 'DELETE'], true)) {
    requireAdmin($pdo);
    $id = (int) $m[1];

    if ($method === 'DELETE') {
        $stmt = $pdo->prepare('DELETE FROM events WHERE id = :id');
        $stmt->execute(['id' => $id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    $d = body();
    $fields = ['title' => 'title', 'description' => 'description', 'location' => 'location',
               'image_url' => 'imageUrl', 'link_url' => 'linkUrl', 'sort_order' => 'sortOrder'];
    $sets = [];
    $params = ['id' => $id];
    foreach ($fields as $col => $jsonKey) {
        if (array_key_exists($jsonKey, $d)) {
            $sets[] = "$col = :$col";
            $params[$col] = $d[$jsonKey];
        }
    }
    if (array_key_exists('eventDate', $d)) {
        $sets[] = "event_date = NULLIF(:event_date, '')::date";
        $params['event_date'] = $d['eventDate'];
    }
    if (empty($sets)) {
        http_response_code(400);
        echo json_encode(['error' => 'No fields to update.']);
        exit;
    }
    $stmt = $pdo->prepare('UPDATE events SET ' . implode(', ', $sets) . ' WHERE id = :id');
    $stmt->execute($params);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Not found']);

} catch (Throwable $e) {
    error_log('Unhandled API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Something went wrong. Please try again.']);
}