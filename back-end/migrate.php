<?php

require __DIR__ . '/src/Database.php';

$pdo = getConnection();

$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    name TEXT NOT NULL,
    email TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    is_admin BOOLEAN NOT NULL DEFAULT FALSE,
    email_verified BOOLEAN NOT NULL DEFAULT FALSE,
    subscription_tier TEXT,
    subscribed_at TIMESTAMP,
    stripe_customer_id TEXT,
    failed_login_attempts INT NOT NULL DEFAULT 0,
    locked_until TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW()
)");

// --- Backfill columns for databases created before this migration existed ---
$userCols = ['is_admin' => 'BOOLEAN NOT NULL DEFAULT FALSE',
             'email_verified' => 'BOOLEAN NOT NULL DEFAULT FALSE',
             'stripe_customer_id' => 'TEXT',
             'failed_login_attempts' => 'INT NOT NULL DEFAULT 0',
             'locked_until' => 'TIMESTAMP'];
foreach ($userCols as $col => $def) {
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS $col $def");
}

$pdo->exec("CREATE TABLE IF NOT EXISTS sessions (
    token_hash TEXT PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at TIMESTAMP DEFAULT NOW(),
    expires_at TIMESTAMP NOT NULL
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS otp_codes (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    code_hash TEXT NOT NULL,
    purpose TEXT NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    consumed_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW()
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS rate_limit_attempts (
    id SERIAL PRIMARY KEY,
    identifier TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT NOW()
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS tiers (
    slug TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    price_cents INT NOT NULL,
    period TEXT NOT NULL DEFAULT '',
    belt_slug TEXT NOT NULL,
    tagline TEXT NOT NULL,
    featured BOOLEAN NOT NULL DEFAULT FALSE,
    sort_order INT NOT NULL DEFAULT 0,
    stripe_price_id TEXT
)");
$pdo->exec("ALTER TABLE tiers ADD COLUMN IF NOT EXISTS stripe_price_id TEXT");

$pdo->exec("CREATE TABLE IF NOT EXISTS tier_features (
    id SERIAL PRIMARY KEY,
    tier_slug TEXT NOT NULL REFERENCES tiers(slug) ON DELETE CASCADE,
    feature TEXT NOT NULL,
    sort_order INT NOT NULL DEFAULT 0
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS videos (
    id SERIAL PRIMARY KEY,
    belt_slug TEXT NOT NULL,
    lesson_number INT NOT NULL,
    type TEXT NOT NULL,
    title TEXT NOT NULL,
    caption TEXT NOT NULL DEFAULT '',
    video_url TEXT NOT NULL DEFAULT '',
    duration TEXT NOT NULL,
    instructor TEXT NOT NULL,
    premium BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order INT NOT NULL DEFAULT 0
)");
$pdo->exec("ALTER TABLE videos ADD COLUMN IF NOT EXISTS caption TEXT NOT NULL DEFAULT ''");
$pdo->exec("ALTER TABLE videos ADD COLUMN IF NOT EXISTS video_url TEXT NOT NULL DEFAULT ''");

$pdo->exec("CREATE TABLE IF NOT EXISTS content_blocks (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT NOW()
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS payments (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    tier_slug TEXT NOT NULL,
    stripe_session_id TEXT UNIQUE,
    stripe_payment_intent TEXT,
    amount_cents INT NOT NULL,
    status TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT NOW()
)");

// Seed tiers + features only if empty.
$tierCount = $pdo->query("SELECT COUNT(*) FROM tiers")->fetchColumn();
if ($tierCount == 0) {
    $pdo->exec("INSERT INTO tiers (slug, name, price_cents, period, belt_slug, tagline, featured, sort_order) VALUES
        ('free', 'White Belt', 0, '', 'white', 'Start your journey', FALSE, 1),
        ('monthly', 'Black Belt', 2400, '/month', 'black', 'Full library, month to month', TRUE, 2),
        ('annual', 'Black Belt Annual', 19900, '/year', 'black', 'Save 30%, commit to the craft', FALSE, 3)");

    $pdo->exec("INSERT INTO tier_features (tier_slug, feature, sort_order) VALUES
        ('free', 'All white belt Kihon & Kata lessons', 1),
        ('free', 'Community forum access', 2),
        ('free', 'New basics content monthly', 3),
        ('monthly', 'Every lesson, every belt rank', 1),
        ('monthly', 'New class uploaded weekly', 2),
        ('monthly', 'Technique breakdowns & bunkai', 3),
        ('monthly', 'Cancel anytime', 4),
        ('annual', 'Everything in Black Belt', 1),
        ('annual', '2 form-review credits per year', 2),
        ('annual', 'Early access to new kata drops', 3),
        ('annual', 'Best value', 4)");
}

// Seed videos only if empty.
$videoCount = $pdo->query("SELECT COUNT(*) FROM videos")->fetchColumn();
if ($videoCount == 0) {
    $pdo->exec("INSERT INTO videos (belt_slug, lesson_number, type, title, caption, duration, instructor, premium, sort_order) VALUES
        ('white', 1, 'Kihon', 'Zenkutsu-dachi: The Front Stance', 'The foundation stance for almost every technique that follows.', '8:12', 'Sensei Rina Aoki', FALSE, 1),
        ('white', 2, 'Kihon', 'Age-uke Rising Block Fundamentals', 'Defending the head against a rising strike.', '6:40', 'Sensei Kenji Ohta', FALSE, 2),
        ('white', 3, 'Kata', 'Heian Shodan, Step by Step', 'Your first kata, broken down move by move.', '14:05', 'Sensei Rina Aoki', FALSE, 3),
        ('yellow', 1, 'Kumite', 'Reading Distance in Kumite', 'Judging range before you ever throw a strike.', '11:30', 'Sensei Marcus Diallo', TRUE, 4),
        ('yellow', 2, 'Kata', 'Heian Nidan, Full Breakdown', 'The second Heian kata, with bunkai notes.', '16:20', 'Sensei Rina Aoki', TRUE, 5),
        ('orange', 1, 'Kihon', 'Hip Rotation for Real Power', 'Where strike power actually comes from.', '9:55', 'Sensei Kenji Ohta', TRUE, 6),
        ('orange', 2, 'Bunkai', 'Heian Sandan Application (Bunkai)', 'What those kata movements mean against a real attacker.', '13:10', 'Sensei Marcus Diallo', TRUE, 7),
        ('green', 1, 'Conditioning', 'Conditioning: Striking Power Circuit', 'Build the specific strength karate striking demands.', '22:00', 'Coach Leila Nasser', TRUE, 8),
        ('green', 2, 'Kata', 'Heian Yondan, Full Breakdown', 'The fourth Heian kata, full breakdown.', '17:45', 'Sensei Rina Aoki', TRUE, 9),
        ('blue', 1, 'Kumite', 'Counter-Fighting Fundamentals', 'Turning defense into scoring opportunity.', '19:15', 'Sensei Marcus Diallo', TRUE, 10),
        ('blue', 2, 'Bunkai', 'Heian Godan Application (Bunkai)', 'Application drills for the fifth Heian kata.', '15:40', 'Sensei Kenji Ohta', TRUE, 11),
        ('purple', 1, 'Kata', 'Tekki Shodan, Full Breakdown', 'A close-stance kata built for power over mobility.', '18:30', 'Sensei Rina Aoki', TRUE, 12),
        ('purple', 2, 'Conditioning', 'Explosive Footwork Drills', 'Speed and reaction work for advanced sparring.', '20:10', 'Coach Leila Nasser', TRUE, 13),
        ('brown', 1, 'Kata', 'Bassai Dai, Full Breakdown', 'One of the most demanding kata before black belt.', '21:00', 'Sensei Rina Aoki', TRUE, 14),
        ('brown', 2, 'Kumite', 'Pre-Grading Sparring Strategy', 'Preparing your sparring for a black belt grading panel.', '24:35', 'Sensei Marcus Diallo', TRUE, 15),
        ('black', 1, 'Kihon', 'Black Belt Mindset: The Long Game', 'Black belt is the beginning, not the end.', '12:50', 'Sensei Kenji Ohta', TRUE, 16)");
}

// Seed content blocks only if empty.
$contentCount = $pdo->query("SELECT COUNT(*) FROM content_blocks")->fetchColumn();
if ($contentCount == 0) {
    $stmt = $pdo->prepare("INSERT INTO content_blocks (key, value) VALUES (:key, :value) ON CONFLICT (key) DO NOTHING");
    $defaults = [
        'hero_eyebrow' => 'Est. 1987 · Full-Contact Karate',
        'hero_title_line1' => 'TRAIN WITH',
        'hero_title_line2' => 'INTENT.',
        'hero_subtitle' => 'Structured karate instruction from white belt to black — kihon, kata, kumite and conditioning, taught the way a real dojo teaches: in order, with correction, and without shortcuts.',
        'library_title' => 'EVERY LESSON, BY RANK.',
        'library_note' => "Filter by belt to see what's next on your path, or by technique type to drill a specific skill. Lessons are numbered in the order your dojo teaches them.",
        'membership_title' => 'PICK YOUR RANK.',
        'footer_note' => 'White belt to black. No shortcuts.',
    ];
    foreach ($defaults as $key => $value) {
        $stmt->execute(['key' => $key, 'value' => $value]);
    }
}

// Promote a specific account to admin, if ADMIN_EMAIL is set and that
// account already exists. Safe to run on every deploy (idempotent).
$adminEmail = getenv('ADMIN_EMAIL');
if ($adminEmail) {
    $stmt = $pdo->prepare('UPDATE users SET is_admin = TRUE WHERE email = :email');
    $stmt->execute(['email' => strtolower(trim($adminEmail))]);
    if ($stmt->rowCount() > 0) {
        echo "Promoted $adminEmail to admin\n";
    } else {
        echo "ADMIN_EMAIL is set but no matching account exists yet (sign up first, then redeploy)\n";
    }
}

echo "Migration complete\n";