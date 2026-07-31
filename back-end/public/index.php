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

const BELT_ORDER = ['white', 'yellow', 'orange', 'green', 'blue', 'purple', 'brown', 'black'];

function toBool($v): bool {
    return $v === true || $v === 't';
}

// PDOStatement::execute($params) binds everything as a string by default,
// so a native PHP `false` becomes "" -- which Postgres rejects for a
// boolean column ("invalid input syntax for type boolean"). This normalizes
// any PHP bool in a params array to the string Postgres actually accepts,
// before every dynamic UPDATE built from client input.
function normalizeBoolParams(array $params): array {
    foreach ($params as $key => $value) {
        if (is_bool($value)) {
            $params[$key] = $value ? 'true' : 'false';
        }
    }
    return $params;
}

function publicUser(array $user): array {
    $tier = $user['subscription_tier'] ?? null;
    $trialEndsAt = $user['trial_ends_at'] ?? null;
    $accountStatus = $user['account_status'] ?? 'active';
    $subscriptionActive = $tier !== null
        && ($tier === 'trial'
            ? ($trialEndsAt !== null && strtotime($trialEndsAt) > time())
            : $accountStatus === 'active');
    $role = $user['role'] ?? ($user['is_admin'] === true || $user['is_admin'] === 't' ? 'admin' : 'user');

    return [
        'id' => (int) $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'subscriptionTier' => $tier,
        'subscriptionActive' => $subscriptionActive,
        'trialEndsAt' => $trialEndsAt,
        'trialUsed' => array_key_exists('trial_used', $user) ? toBool($user['trial_used']) : false,
        'accountStatus' => $accountStatus,
        'hasBilling' => !empty($user['stripe_customer_id']),
        'role' => $role,
        'isAdmin' => $role === 'admin' || $role === 'super_admin',
        'isSuperAdmin' => $role === 'super_admin',
        'emailVerified' => toBool($user['email_verified']),
        'currentBelt' => $user['current_belt'] ?? 'white',
        'stripes' => isset($user['stripes']) ? (int) $user['stripes'] : 0,
        'targetBelt' => $user['target_belt'] ?? null,
        'nextGradingDate' => $user['next_grading_date'] ?? null,
        'memberSince' => $user['created_at'] ?? null,
    ];
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
         RETURNING id, name, email, subscription_tier, is_admin, email_verified,
                   trial_ends_at, trial_used, current_belt, stripes, next_grading_date, target_belt, created_at, role,
                   account_status, stripe_customer_id, stripe_subscription_id'
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
                trial_ends_at, trial_used, current_belt, stripes, next_grading_date, target_belt, created_at, role,
                account_status, stripe_customer_id, stripe_subscription_id
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

    $isStaff = in_array($user['role'] ?? 'user', ['admin', 'super_admin'], true);
    $token = createSession($pdo, (int) $user['id'], $isStaff ? ADMIN_SESSION_LIFETIME_HOURS : null);
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
    // Only send the welcome email the first time this account is verified --
    // requireAuth() gave us the pre-update row, so email_verified here still
    // reflects the state before this request.
    $wasAlreadyVerified = $user['email_verified'] === true || $user['email_verified'] === 't';

    $update = $pdo->prepare('UPDATE users SET email_verified = TRUE WHERE id = :id');
    $update->execute(['id' => $user['id']]);

    if (!$wasAlreadyVerified) {
        sendEmail($user['email'], 'Welcome to IKKO Academy', welcomeEmailHtml($user['name']));
    }

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
         FROM events ORDER BY sort_order, event_date'
    );
    $result = array_map(function ($row) {
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
    echo json_encode($result);
    exit;
}

/* ============================================================
   STUDENT DASHBOARD
   ============================================================ */

// Aggregated payload for the dashboard header: belt, grading pathway and
// recorded-lesson progress for the signed-in student's current belt.
if ($path === 'api/dashboard' && $method === 'GET') {
    $user = requireAuth($pdo);
    $belt = $user['current_belt'] ?? 'white';
    $beltIndex = array_search($belt, BELT_ORDER, true);
    $beltIndex = $beltIndex === false ? 0 : $beltIndex;
    $nextBelt = $beltIndex < count(BELT_ORDER) - 1 ? BELT_ORDER[$beltIndex + 1] : null;

    $totalStmt = $pdo->prepare('SELECT COUNT(*) FROM videos WHERE belt_slug = :belt');
    $totalStmt->execute(['belt' => $belt]);
    $totalForBelt = (int) $totalStmt->fetchColumn();

    $doneStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM lesson_progress lp
         JOIN videos v ON v.id = lp.video_id
         WHERE lp.user_id = :uid AND v.belt_slug = :belt'
    );
    $doneStmt->execute(['uid' => $user['id'], 'belt' => $belt]);
    $doneForBelt = (int) $doneStmt->fetchColumn();

    $overallTotal = (int) $pdo->query('SELECT COUNT(*) FROM videos')->fetchColumn();
    $overallDoneStmt = $pdo->prepare('SELECT COUNT(*) FROM lesson_progress WHERE user_id = :uid');
    $overallDoneStmt->execute(['uid' => $user['id']]);
    $overallDone = (int) $overallDoneStmt->fetchColumn();

    echo json_encode([
        'user' => publicUser($user),
        'beltOrder' => BELT_ORDER,
        'progress' => [
            'currentBelt' => $belt,
            'beltIndex' => $beltIndex,
            'totalBelts' => count(BELT_ORDER),
            'nextBelt' => $nextBelt,
            'stripes' => (int) ($user['stripes'] ?? 0),
            'nextGradingDate' => $user['next_grading_date'] ?? null,
            'targetBelt' => $user['target_belt'] ?? $nextBelt,
            'lessonsCompletedForBelt' => $doneForBelt,
            'lessonsTotalForBelt' => $totalForBelt,
            'lessonsCompletedOverall' => $overallDone,
            'lessonsTotalOverall' => $overallTotal,
        ],
    ]);
    exit;
}

if ($path === 'api/guides' && $method === 'GET') {
    $user = getUserFromToken($pdo, bearerToken());
    $active = $user ? publicUser($user)['subscriptionActive'] : false;

    $stmt = $pdo->query('SELECT id, belt_slug, title, description, file_url, premium FROM guides ORDER BY sort_order');
    $guides = array_map(function ($row) use ($active) {
        $premium = toBool($row['premium']);
        return [
            'id' => (int) $row['id'],
            'belt' => $row['belt_slug'],
            'title' => $row['title'],
            'description' => $row['description'],
            'premium' => $premium,
            'locked' => $premium && !$active,
            'fileUrl' => (!$premium || $active) ? $row['file_url'] : null,
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    echo json_encode($guides);
    exit;
}

if ($path === 'api/announcements' && $method === 'GET') {
    $stmt = $pdo->query(
        'SELECT id, title, body, pinned, created_at FROM announcements ORDER BY pinned DESC, created_at DESC LIMIT 20'
    );
    $result = array_map(function ($row) {
        return [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'body' => $row['body'],
            'pinned' => toBool($row['pinned']),
            'createdAt' => $row['created_at'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    echo json_encode($result);
    exit;
}

if ($path === 'api/live-sessions' && $method === 'GET') {
    $stmt = $pdo->query(
        'SELECT id, title, description, instructor, session_at, duration_minutes, join_url, belt_slug
         FROM live_sessions WHERE session_at > NOW() - INTERVAL \'2 hours\' ORDER BY session_at ASC LIMIT 20'
    );
    $result = array_map(function ($row) {
        return [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'description' => $row['description'],
            'instructor' => $row['instructor'],
            'sessionAt' => $row['session_at'],
            'durationMinutes' => (int) $row['duration_minutes'],
            'joinUrl' => $row['join_url'],
            'belt' => $row['belt_slug'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    echo json_encode($result);
    exit;
}

// Terminology / philosophy / grading-prep / instructor-development cards,
// grouped by category. Instructor-development is gated to advanced members.
if ($path === 'api/resources' && $method === 'GET') {
    $user = getUserFromToken($pdo, bearerToken());
    $pu = $user ? publicUser($user) : null;
    $active = $pu ? $pu['subscriptionActive'] : false;
    $isAdvanced = $pu && $pu['subscriptionTier'] === 'advanced' && $active;

    $stmt = $pdo->query('SELECT id, category, title, body, link_url, premium FROM resources ORDER BY category, sort_order');
    $grouped = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $premium = toBool($row['premium']);
        $isInstructor = $row['category'] === 'instructor';
        $unlocked = $isInstructor ? $isAdvanced : (!$premium || $active);
        $grouped[$row['category']][] = [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'body' => $unlocked ? $row['body'] : null,
            'linkUrl' => $unlocked ? $row['link_url'] : null,
            'premium' => $premium || $isInstructor,
            'locked' => !$unlocked,
        ];
    }
    echo json_encode($grouped);
    exit;
}

if (preg_match('#^api/progress/videos/(\d+)$#', $path, $m) && in_array($method, ['POST', 'DELETE'], true)) {
    $user = requireAuth($pdo);
    $videoId = (int) $m[1];

    if ($method === 'POST') {
        $stmt = $pdo->prepare(
            'INSERT INTO lesson_progress (user_id, video_id) VALUES (:uid, :vid)
             ON CONFLICT (user_id, video_id) DO NOTHING'
        );
        $stmt->execute(['uid' => $user['id'], 'vid' => $videoId]);
    } else {
        $stmt = $pdo->prepare('DELETE FROM lesson_progress WHERE user_id = :uid AND video_id = :vid');
        $stmt->execute(['uid' => $user['id'], 'vid' => $videoId]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

if ($path === 'api/progress/videos' && $method === 'GET') {
    $user = requireAuth($pdo);
    $stmt = $pdo->prepare('SELECT video_id FROM lesson_progress WHERE user_id = :uid');
    $stmt->execute(['uid' => $user['id']]);
    echo json_encode(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    exit;
}

/* ============================================================
   ADMIN: guides, announcements, live sessions, resources, belts
   ============================================================ */

if ($path === 'api/admin/guides' && $method === 'POST') {
    requireAdmin($pdo);
    $d = body();
    $title = trim($d['title'] ?? '');
    if ($title === '') {
        http_response_code(400);
        echo json_encode(['error' => 'A title is required.']);
        exit;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO guides (belt_slug, title, description, file_url, premium, sort_order)
         VALUES (:belt, :title, :description, :url, :premium, :sort) RETURNING id'
    );
    $stmt->execute([
        'belt' => trim($d['belt'] ?? 'all') ?: 'all',
        'title' => $title,
        'description' => trim($d['description'] ?? ''),
        'url' => trim($d['fileUrl'] ?? ''),
        'premium' => !empty($d['premium']) ? 'true' : 'false',
        'sort' => (int) ($d['sortOrder'] ?? 999),
    ]);
    echo json_encode(['id' => (int) $stmt->fetchColumn()]);
    exit;
}

if (preg_match('#^api/admin/guides/(\d+)$#', $path, $m) && in_array($method, ['PUT', 'DELETE'], true)) {
    requireAdmin($pdo);
    $id = (int) $m[1];
    if ($method === 'DELETE') {
        $stmt = $pdo->prepare('DELETE FROM guides WHERE id = :id');
        $stmt->execute(['id' => $id]);
        echo json_encode(['ok' => true]);
        exit;
    }
    $d = body();
    $fields = ['belt_slug' => 'belt', 'title' => 'title', 'description' => 'description',
               'file_url' => 'fileUrl', 'premium' => 'premium', 'sort_order' => 'sortOrder'];
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
    $stmt = $pdo->prepare('UPDATE guides SET ' . implode(', ', $sets) . ' WHERE id = :id');
    $stmt->execute(normalizeBoolParams($params));
    echo json_encode(['ok' => true]);
    exit;
}

if ($path === 'api/admin/announcements' && $method === 'POST') {
    requireAdmin($pdo);
    $d = body();
    $title = trim($d['title'] ?? '');
    if ($title === '') {
        http_response_code(400);
        echo json_encode(['error' => 'A title is required.']);
        exit;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO announcements (title, body, pinned) VALUES (:title, :body, :pinned) RETURNING id'
    );
    $stmt->execute([
        'title' => $title,
        'body' => trim($d['body'] ?? ''),
        'pinned' => !empty($d['pinned']) ? 'true' : 'false',
    ]);
    echo json_encode(['id' => (int) $stmt->fetchColumn()]);
    exit;
}

if (preg_match('#^api/admin/announcements/(\d+)$#', $path, $m) && $method === 'DELETE') {
    requireAdmin($pdo);
    $stmt = $pdo->prepare('DELETE FROM announcements WHERE id = :id');
    $stmt->execute(['id' => (int) $m[1]]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($path === 'api/admin/live-sessions' && $method === 'POST') {
    requireAdmin($pdo);
    $d = body();
    $title = trim($d['title'] ?? '');
    $sessionAt = trim($d['sessionAt'] ?? '');
    if ($title === '' || $sessionAt === '') {
        http_response_code(400);
        echo json_encode(['error' => 'A title and date/time are required.']);
        exit;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO live_sessions (title, description, instructor, session_at, duration_minutes, join_url, belt_slug)
         VALUES (:title, :description, :instructor, :session_at, :duration, :join_url, :belt) RETURNING id'
    );
    $stmt->execute([
        'title' => $title,
        'description' => trim($d['description'] ?? ''),
        'instructor' => trim($d['instructor'] ?? ''),
        'session_at' => $sessionAt,
        'duration' => (int) ($d['durationMinutes'] ?? 60),
        'join_url' => trim($d['joinUrl'] ?? ''),
        'belt' => trim($d['belt'] ?? 'all') ?: 'all',
    ]);
    echo json_encode(['id' => (int) $stmt->fetchColumn()]);
    exit;
}

if (preg_match('#^api/admin/live-sessions/(\d+)$#', $path, $m) && $method === 'DELETE') {
    requireAdmin($pdo);
    $stmt = $pdo->prepare('DELETE FROM live_sessions WHERE id = :id');
    $stmt->execute(['id' => (int) $m[1]]);
    echo json_encode(['ok' => true]);
    exit;
}

/** A regular admin may only manage plain 'user' accounts -- never other
 *  admins or the super admin. Only a super_admin may touch staff accounts. */
function requesterRole(array $user): string {
    return $user['role'] ?? (($user['is_admin'] === true || $user['is_admin'] === 't') ? 'admin' : 'user');
}

function targetUserRole(PDO $pdo, int $id): ?string {
    $stmt = $pdo->prepare('SELECT role FROM users WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $role = $stmt->fetchColumn();
    return $role === false ? null : $role;
}

function assertCanManageTarget(PDO $pdo, array $requester, int $targetId): void {
    $targetRole = targetUserRole($pdo, $targetId);
    if ($targetRole === null) {
        http_response_code(404);
        echo json_encode(['error' => 'No such account.']);
        exit;
    }
    if ($targetRole !== 'user' && requesterRole($requester) !== 'super_admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Only a super admin can manage staff accounts.']);
        exit;
    }
}

// Admin sets a student's belt/stripes/grading pathway (there is no
// self-service belt promotion -- only an instructor account can do this).
if (preg_match('#^api/admin/users/(\d+)/progress$#', $path, $m) && $method === 'PUT') {
    $requester = requireAdmin($pdo);
    $id = (int) $m[1];
    assertCanManageTarget($pdo, $requester, $id);
    $d = body();

    if (isset($d['currentBelt']) && !in_array($d['currentBelt'], BELT_ORDER, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid belt.']);
        exit;
    }

    $sets = [];
    $params = ['id' => $id];
    if (isset($d['currentBelt'])) { $sets[] = 'current_belt = :belt'; $params['belt'] = $d['currentBelt']; }
    if (isset($d['stripes'])) { $sets[] = 'stripes = :stripes'; $params['stripes'] = (int) $d['stripes']; }
    if (array_key_exists('nextGradingDate', $d)) { $sets[] = 'next_grading_date = :grading'; $params['grading'] = $d['nextGradingDate'] ?: null; }
    if (array_key_exists('targetBelt', $d)) { $sets[] = 'target_belt = :target'; $params['target'] = $d['targetBelt'] ?: null; }

    if (empty($sets)) {
        http_response_code(400);
        echo json_encode(['error' => 'No fields to update.']);
        exit;
    }
    $stmt = $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id');
    $stmt->execute(normalizeBoolParams($params));
    echo json_encode(['ok' => true]);
    exit;
}

// General account-detail edit (name/email/subscription tier). Belt/grading
// has its own endpoint above; role changes have their own endpoint below --
// neither can be set here.
if (preg_match('#^api/admin/users/(\d+)$#', $path, $m) && $method === 'PUT') {
    $requester = requireAdmin($pdo);
    $id = (int) $m[1];
    assertCanManageTarget($pdo, $requester, $id);
    $d = body();

    $sets = [];
    $params = ['id' => $id];
    if (isset($d['name'])) {
        $name = trim($d['name']);
        if ($name === '') { http_response_code(400); echo json_encode(['error' => 'Name cannot be empty.']); exit; }
        $sets[] = 'name = :name'; $params['name'] = $name;
    }
    if (isset($d['email'])) {
        $email = strtolower(trim($d['email']));
        if (!isValidEmail($email)) { http_response_code(400); echo json_encode(['error' => 'Invalid email.']); exit; }
        $sets[] = 'email = :email'; $params['email'] = $email;
    }
    if (array_key_exists('subscriptionTier', $d)) {
        $sets[] = 'subscription_tier = :tier'; $params['tier'] = $d['subscriptionTier'] ?: null;
    }

    if (empty($sets)) {
        http_response_code(400);
        echo json_encode(['error' => 'No fields to update.']);
        exit;
    }
    try {
        $stmt = $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->execute(normalizeBoolParams($params));
    } catch (PDOException $e) {
        http_response_code(409);
        echo json_encode(['error' => 'That email is already in use.']);
        exit;
    }
    echo json_encode(['ok' => true]);
    exit;
}

// Lightweight student/staff lookup for the admin panel (name/email search).
if ($path === 'api/admin/users' && $method === 'GET') {
    $requester = requireAdmin($pdo);
    $q = trim($_GET['q'] ?? '');
    $roleFilter = trim($_GET['role'] ?? '');
    $validRoleFilter = in_array($roleFilter, ['user', 'admin', 'super_admin'], true) ? $roleFilter : null;

    if ($validRoleFilter !== null) {
        // Staff directory -- super admin only, enforced here (not just hidden
        // in the UI), since it can expose other staff members' emails.
        if (requesterRole($requester) !== 'super_admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Super admin access required.']);
            exit;
        }
        // No name/email search, no row cap -- there won't be many admins.
        $stmt = $pdo->prepare(
            'SELECT id, name, email, subscription_tier, current_belt, stripes, next_grading_date, target_belt, role
             FROM users WHERE role = :role ORDER BY name'
        );
        $stmt->execute(['role' => $validRoleFilter]);
    } elseif ($q !== '') {
        $stmt = $pdo->prepare(
            'SELECT id, name, email, subscription_tier, current_belt, stripes, next_grading_date, target_belt, role
             FROM users WHERE name ILIKE :q_name OR email ILIKE :q_email ORDER BY name LIMIT 20'
        );
        $stmt->execute(['q_name' => '%' . $q . '%', 'q_email' => '%' . $q . '%']);
    } else {
        $stmt = $pdo->query(
            'SELECT id, name, email, subscription_tier, current_belt, stripes, next_grading_date, target_belt, role
             FROM users ORDER BY created_at DESC LIMIT 20'
        );
    }
    $result = array_map(function ($row) {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'email' => $row['email'],
            'subscriptionTier' => $row['subscription_tier'],
            'currentBelt' => $row['current_belt'],
            'stripes' => (int) $row['stripes'],
            'nextGradingDate' => $row['next_grading_date'],
            'targetBelt' => $row['target_belt'],
            'role' => $row['role'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    echo json_encode($result);
    exit;
}

/* ============================================================
   SUPER ADMIN ONLY: create staff/student accounts, grant/revoke admin
   ============================================================ */

// Creates an account directly (no email verification flow) as either a
// regular user or a regular admin. Can NEVER create a super_admin -- that
// role only ever exists via a manual database insert.
if ($path === 'api/admin/accounts' && $method === 'POST') {
    requireSuperAdmin($pdo);
    $d = body();
    $name = trim($d['name'] ?? '');
    $email = strtolower(trim($d['email'] ?? ''));
    $password = (string) ($d['password'] ?? '');
    $role = $d['role'] ?? 'user';

    if (!in_array($role, ['user', 'admin'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Role must be "user" or "admin".']);
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

    // Admin-created accounts skip the email OTP flow -- the admin has already
    // vouched for the address.
    $stmt = $pdo->prepare(
        'INSERT INTO users (name, email, password_hash, role, is_admin, email_verified)
         VALUES (:name, :email, :hash, :role, :is_admin, TRUE)
         RETURNING id, name, email, role'
    );
    $stmt->execute([
        'name' => $name,
        'email' => $email,
        'hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => $role,
        'is_admin' => $role === 'admin' ? 'true' : 'false',
    ]);
    echo json_encode(['user' => $stmt->fetch(PDO::FETCH_ASSOC)]);
    exit;
}

// Grants or revokes regular admin access. Can never set/target super_admin --
// that role can't be assigned or changed through the API at all.
if (preg_match('#^api/admin/users/(\d+)/role$#', $path, $m) && $method === 'PUT') {
    requireSuperAdmin($pdo);
    $id = (int) $m[1];
    $role = body()['role'] ?? '';

    if (!in_array($role, ['user', 'admin'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Role must be "user" or "admin".']);
        exit;
    }
    $currentRole = targetUserRole($pdo, $id);
    if ($currentRole === null) {
        http_response_code(404);
        echo json_encode(['error' => 'No such account.']);
        exit;
    }
    if ($currentRole === 'super_admin') {
        http_response_code(403);
        echo json_encode(['error' => 'The super admin account can only be changed directly in the database.']);
        exit;
    }

    $stmt = $pdo->prepare('UPDATE users SET role = :role, is_admin = :is_admin WHERE id = :id');
    $stmt->execute(['role' => $role, 'is_admin' => $role === 'admin' ? 'true' : 'false', 'id' => $id]);
    echo json_encode(['ok' => true]);
    exit;
}

/* ============================================================
   SUBSCRIPTIONS & PAYMENTS
   ============================================================ */

// Free tier only -- no payment required, so there's nothing for Stripe to verify.
if ($path === 'api/subscriptions' && $method === 'POST') {
    $user = requireAuth($pdo);
    $tierSlug = body()['tierSlug'] ?? '';

    $tierStmt = $pdo->prepare('SELECT slug, price_cents FROM tiers WHERE slug = :slug');
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

    $update = $pdo->prepare(
        'UPDATE users SET subscription_tier = :tier,
                subscribed_at = NOW(),
                trial_ends_at = CASE WHEN :tier_check1 = \'trial\' THEN NOW() + INTERVAL \'7 days\' ELSE trial_ends_at END,
                trial_used = CASE WHEN :tier_check2 = \'trial\' THEN TRUE ELSE trial_used END
         WHERE id = :id
         RETURNING id, name, email, subscription_tier, is_admin, email_verified,
                   trial_ends_at, trial_used, current_belt, stripes, next_grading_date, target_belt, created_at, role,
                   account_status, stripe_customer_id, stripe_subscription_id'
    );
    $update->execute(['tier' => $tierSlug, 'tier_check1' => $tierSlug, 'tier_check2' => $tierSlug, 'id' => $user['id']]);
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
            $update = $pdo->prepare(
                'UPDATE users SET subscription_tier = :tier, subscribed_at = NOW(),
                 stripe_customer_id = :cust, stripe_subscription_id = :sub, account_status = \'active\'
                 WHERE id = :id'
            );
            $update->execute([
                'tier' => $tierSlug,
                'cust' => $session['customer'] ?? null,
                'sub' => $session['subscription'] ?? null,
                'id' => $userId,
            ]);

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
   ACCOUNT MANAGEMENT
   ============================================================ */

// Redirects to Stripe's own hosted Billing Portal -- we never build our
// own payment-method/invoice UI.
if ($path === 'api/account/billing-portal' && $method === 'POST') {
    $user = requireAuth($pdo);
    if (empty($user['stripe_customer_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'No billing account yet -- subscribe to a paid plan first.']);
        exit;
    }
    $result = createBillingPortalSession($user['stripe_customer_id']);
    if (isset($result['error'])) {
        http_response_code(502);
        echo json_encode(['error' => $result['error']]);
        exit;
    }
    echo json_encode($result);
    exit;
}

if ($path === 'api/account/pause' && $method === 'POST') {
    $user = requireAuth($pdo);
    if (empty($user['stripe_subscription_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'No active paid subscription to pause.']);
        exit;
    }
    $result = pauseStripeSubscription($user['stripe_subscription_id']);
    if (isset($result['error'])) {
        http_response_code(502);
        echo json_encode(['error' => $result['error']]);
        exit;
    }
    $update = $pdo->prepare('UPDATE users SET account_status = \'paused\' WHERE id = :id');
    $update->execute(['id' => $user['id']]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($path === 'api/account/resume' && $method === 'POST') {
    $user = requireAuth($pdo);
    if (empty($user['stripe_subscription_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'No subscription to resume.']);
        exit;
    }
    $result = resumeStripeSubscription($user['stripe_subscription_id']);
    if (isset($result['error'])) {
        http_response_code(502);
        echo json_encode(['error' => $result['error']]);
        exit;
    }
    $update = $pdo->prepare('UPDATE users SET account_status = \'active\' WHERE id = :id');
    $update->execute(['id' => $user['id']]);
    echo json_encode(['ok' => true]);
    exit;
}

// Cancels any live Stripe subscription FIRST, so deleting an account can
// never leave someone getting billed for a service they can no longer log
// into. The user row is deleted last, and cascades to sessions/otp_codes/
// lesson_progress via their foreign keys.
if ($path === 'api/account' && $method === 'DELETE') {
    $user = requireAuth($pdo);

    if (!empty($user['stripe_subscription_id'])) {
        $cancel = cancelStripeSubscription($user['stripe_subscription_id']);
        if (isset($cancel['error'])) {
            http_response_code(502);
            echo json_encode(['error' => 'Could not cancel your subscription automatically: ' . $cancel['error'] . '. Please contact support before deleting your account.']);
            exit;
        }
    }

    $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
    $stmt->execute(['id' => $user['id']]);
    echo json_encode(['ok' => true]);
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
        'INSERT INTO content_blocks (key, value, updated_at) VALUES (:key, :value_insert, NOW())
         ON CONFLICT (key) DO UPDATE SET value = :value_update, updated_at = NOW()'
    );
    $stmt->execute(['key' => $key, 'value_insert' => $value, 'value_update' => $value]);
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
        'premium' => !empty($d['premium']) ? 'true' : 'false',
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
    $stmt->execute(normalizeBoolParams($params));
    echo json_encode(['ok' => true]);
    exit;
}

/* ============================================================
   ADMIN: events (homepage carousel)
   ============================================================ */

if ($path === 'api/admin/events' && $method === 'POST') {
    requireAdmin($pdo);
    $d = body();
    $title = trim($d['title'] ?? '');
    if ($title === '') {
        http_response_code(400);
        echo json_encode(['error' => 'A title is required.']);
        exit;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO events (title, description, event_date, location, image_url, link_url, sort_order)
         VALUES (:title, :description, :date, :location, :image, :link, :sort) RETURNING id'
    );
    $stmt->execute([
        'title' => $title,
        'description' => trim($d['description'] ?? ''),
        'date' => $d['eventDate'] ?: null,
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
    $fields = ['title' => 'title', 'description' => 'description', 'event_date' => 'eventDate',
               'location' => 'location', 'image_url' => 'imageUrl', 'link_url' => 'linkUrl', 'sort_order' => 'sortOrder'];
    $sets = [];
    $params = ['id' => $id];
    foreach ($fields as $col => $jsonKey) {
        if (array_key_exists($jsonKey, $d)) {
            $sets[] = "$col = :$col";
            $params[$col] = ($col === 'event_date' && $d[$jsonKey] === '') ? null : $d[$jsonKey];
        }
    }
    if (empty($sets)) {
        http_response_code(400);
        echo json_encode(['error' => 'No fields to update.']);
        exit;
    }
    $stmt = $pdo->prepare('UPDATE events SET ' . implode(', ', $sets) . ' WHERE id = :id');
    $stmt->execute(normalizeBoolParams($params));
    echo json_encode(['ok' => true]);
    exit;
}

/* ============================================================
   ADMIN: resources (terminology / philosophy / grading / instructor cards)
   ============================================================ */

if ($path === 'api/admin/resources' && $method === 'POST') {
    requireAdmin($pdo);
    $d = body();
    $title = trim($d['title'] ?? '');
    $category = trim($d['category'] ?? '');
    if ($title === '' || !in_array($category, ['terminology', 'philosophy', 'instructor', 'grading'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'A title and a valid category are required.']);
        exit;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO resources (category, title, body, link_url, premium, sort_order)
         VALUES (:category, :title, :body, :link, :premium, :sort) RETURNING id'
    );
    $stmt->execute([
        'category' => $category,
        'title' => $title,
        'body' => trim($d['body'] ?? ''),
        'link' => trim($d['linkUrl'] ?? ''),
        'premium' => !empty($d['premium']) ? 'true' : 'false',
        'sort' => (int) ($d['sortOrder'] ?? 999),
    ]);
    echo json_encode(['id' => (int) $stmt->fetchColumn()]);
    exit;
}

if (preg_match('#^api/admin/resources/(\d+)$#', $path, $m) && in_array($method, ['PUT', 'DELETE'], true)) {
    requireAdmin($pdo);
    $id = (int) $m[1];
    if ($method === 'DELETE') {
        $stmt = $pdo->prepare('DELETE FROM resources WHERE id = :id');
        $stmt->execute(['id' => $id]);
        echo json_encode(['ok' => true]);
        exit;
    }
    $d = body();
    if (isset($d['category']) && !in_array($d['category'], ['terminology', 'philosophy', 'instructor', 'grading'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid category.']);
        exit;
    }
    $fields = ['category' => 'category', 'title' => 'title', 'body' => 'body',
               'link_url' => 'linkUrl', 'premium' => 'premium', 'sort_order' => 'sortOrder'];
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
    $stmt = $pdo->prepare('UPDATE resources SET ' . implode(', ', $sets) . ' WHERE id = :id');
    $stmt->execute(normalizeBoolParams($params));
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