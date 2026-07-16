<?php

require __DIR__ . '/src/Database.php';

$pdo = getConnection();

$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    name TEXT NOT NULL,
    email TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    subscription_tier TEXT,
    subscribed_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW()
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS sessions (
    token TEXT PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at TIMESTAMP DEFAULT NOW(),
    expires_at TIMESTAMP NOT NULL
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS tiers (
    slug TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    price_cents INT NOT NULL,
    period TEXT NOT NULL DEFAULT '',
    belt_slug TEXT NOT NULL,
    tagline TEXT NOT NULL,
    featured BOOLEAN NOT NULL DEFAULT FALSE,
    sort_order INT NOT NULL DEFAULT 0
)");

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
    duration TEXT NOT NULL,
    instructor TEXT NOT NULL,
    premium BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order INT NOT NULL DEFAULT 0
)");

// Seed tiers + features only if empty -- prevents duplicates on every restart.
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
    $pdo->exec("INSERT INTO videos (belt_slug, lesson_number, type, title, duration, instructor, premium, sort_order) VALUES
        ('white', 1, 'Kihon', 'Zenkutsu-dachi: The Front Stance', '8:12', 'Sensei Rina Aoki', FALSE, 1),
        ('white', 2, 'Kihon', 'Age-uke Rising Block Fundamentals', '6:40', 'Sensei Kenji Ohta', FALSE, 2),
        ('white', 3, 'Kata', 'Heian Shodan, Step by Step', '14:05', 'Sensei Rina Aoki', FALSE, 3),
        ('yellow', 1, 'Kumite', 'Reading Distance in Kumite', '11:30', 'Sensei Marcus Diallo', TRUE, 4),
        ('yellow', 2, 'Kata', 'Heian Nidan, Full Breakdown', '16:20', 'Sensei Rina Aoki', TRUE, 5),
        ('orange', 1, 'Kihon', 'Hip Rotation for Real Power', '9:55', 'Sensei Kenji Ohta', TRUE, 6),
        ('orange', 2, 'Bunkai', 'Heian Sandan Application (Bunkai)', '13:10', 'Sensei Marcus Diallo', TRUE, 7),
        ('green', 1, 'Conditioning', 'Conditioning: Striking Power Circuit', '22:00', 'Coach Leila Nasser', TRUE, 8),
        ('green', 2, 'Kata', 'Heian Yondan, Full Breakdown', '17:45', 'Sensei Rina Aoki', TRUE, 9),
        ('blue', 1, 'Kumite', 'Counter-Fighting Fundamentals', '19:15', 'Sensei Marcus Diallo', TRUE, 10),
        ('blue', 2, 'Bunkai', 'Heian Godan Application (Bunkai)', '15:40', 'Sensei Kenji Ohta', TRUE, 11),
        ('purple', 1, 'Kata', 'Tekki Shodan, Full Breakdown', '18:30', 'Sensei Rina Aoki', TRUE, 12),
        ('purple', 2, 'Conditioning', 'Explosive Footwork Drills', '20:10', 'Coach Leila Nasser', TRUE, 13),
        ('brown', 1, 'Kata', 'Bassai Dai, Full Breakdown', '21:00', 'Sensei Rina Aoki', TRUE, 14),
        ('brown', 2, 'Kumite', 'Pre-Grading Sparring Strategy', '24:35', 'Sensei Marcus Diallo', TRUE, 15),
        ('black', 1, 'Kihon', 'Black Belt Mindset: The Long Game', '12:50', 'Sensei Kenji Ohta', TRUE, 16)");
}

echo "Migration complete\n";