-- IKKO Academy schema v2
-- Adds: hashed sessions, admin role + email verification, OTP codes,
-- rate limiting, CMS content blocks, video captions/URLs, Stripe fields.

CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    name TEXT NOT NULL,
    email TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    is_admin BOOLEAN NOT NULL DEFAULT FALSE,
    email_verified BOOLEAN NOT NULL DEFAULT FALSE,
    subscription_tier TEXT,
    subscribed_at TIMESTAMP,
    trial_ends_at TIMESTAMP,
    trial_used BOOLEAN NOT NULL DEFAULT FALSE,
    stripe_customer_id TEXT,
    failed_login_attempts INT NOT NULL DEFAULT 0,
    locked_until TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Sessions store a SHA-256 hash of the token, never the raw token.
CREATE TABLE IF NOT EXISTS sessions (
    token_hash TEXT PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at TIMESTAMP DEFAULT NOW(),
    expires_at TIMESTAMP NOT NULL
);

-- One-time codes for email verification and password reset.
CREATE TABLE IF NOT EXISTS otp_codes (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    code_hash TEXT NOT NULL,
    purpose TEXT NOT NULL, -- 'verify_email' | 'password_reset'
    expires_at TIMESTAMP NOT NULL,
    consumed_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Coarse rate limiting for auth endpoints (login/signup/forgot-password).
CREATE TABLE IF NOT EXISTS rate_limit_attempts (
    id SERIAL PRIMARY KEY,
    identifier TEXT NOT NULL, -- e.g. "login:email:ip"
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS tiers (
    slug TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    price_cents INT NOT NULL,
    period TEXT NOT NULL DEFAULT '',
    belt_slug TEXT NOT NULL,
    tagline TEXT NOT NULL,
    featured BOOLEAN NOT NULL DEFAULT FALSE,
    sort_order INT NOT NULL DEFAULT 0,
    trial_days INT, -- only set on the trial tier; NULL for paid tiers
    stripe_price_id TEXT -- set once the tier is created in the Stripe dashboard
);

CREATE TABLE IF NOT EXISTS tier_features (
    id SERIAL PRIMARY KEY,
    tier_slug TEXT NOT NULL REFERENCES tiers(slug) ON DELETE CASCADE,
    feature TEXT NOT NULL,
    sort_order INT NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS videos (
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
);

-- Admin-managed "new & upcoming events" shown in the homepage carousel.
CREATE TABLE IF NOT EXISTS events (
    id SERIAL PRIMARY KEY,
    title TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    event_date DATE,
    location TEXT NOT NULL DEFAULT '',
    image_url TEXT NOT NULL DEFAULT '',
    link_url TEXT NOT NULL DEFAULT '',
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT NOW()
);

INSERT INTO events (title, description, event_date, location, sort_order) VALUES
    ('Autumn Grading Day', 'Belt grading for all ranks, open to spectators. Arrive 30 minutes early to warm up.', CURRENT_DATE + INTERVAL '21 days', 'Main Dojo', 1),
    ('Kata & Bunkai Masterclass', 'A focused half-day masterclass breaking down application for the Heian series.', CURRENT_DATE + INTERVAL '35 days', 'Main Dojo', 2),
    ('Inter-Dojo Friendly Tournament', 'Light-contact kumite tournament, all belts welcome. Team sign-up closes one week prior.', CURRENT_DATE + INTERVAL '50 days', 'Regional Sports Hall', 3),
    ('Junior Students Open Day', 'A free taster session for new junior students aged 6-12. No experience required.', CURRENT_DATE + INTERVAL '10 days', 'Main Dojo', 4),
    ('Shihan Seminar Weekend', 'Two-day intensive seminar covering advanced kata and instructor-level teaching methods.', CURRENT_DATE + INTERVAL '70 days', 'Regional Sports Hall', 5);

-- Admin-editable text sections shown on the public site. Always rendered
-- as plain text on the frontend (never innerHTML) to rule out stored XSS.
CREATE TABLE IF NOT EXISTS content_blocks (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Records of completed Stripe payments, for audit/reconciliation.
CREATE TABLE IF NOT EXISTS payments (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    tier_slug TEXT NOT NULL,
    stripe_session_id TEXT UNIQUE,
    stripe_payment_intent TEXT,
    amount_cents INT NOT NULL,
    status TEXT NOT NULL, -- 'completed' | 'refunded'
    created_at TIMESTAMP DEFAULT NOW()
);

-- Tiers
INSERT INTO tiers (slug, name, price_cents, period, belt_slug, tagline, featured, sort_order, trial_days) VALUES
    ('trial', 'Free Trial', 0, 'for 7 days', 'white', 'Try it out, no card required', FALSE, 1, 7),
    ('standard', 'Academy Member', 25000, '/month', 'blue', 'Perfect for the majority of students.', TRUE, 2, NULL),
    ('advanced', 'Advanced Member', 79900, '/month', 'black', 'Designed for committed students and instructors.', FALSE, 3, NULL)
ON CONFLICT (slug) DO NOTHING;

INSERT INTO tier_features (tier_slug, feature, sort_order) VALUES
    ('trial', 'All white belt Kihon & Kata lessons', 1),
    ('trial', 'Community forum access', 2),
    ('trial', 'New basics content monthly', 3),
    ('standard', '1 live online training session per month', 1),
    ('standard', 'Access to the replay library', 2),
    ('standard', 'Training Guide (PDF) for every session', 3),
    ('standard', 'Resource library (terminology, grading guides, practice logs)', 4),
    ('standard', 'Members-only announcements', 5),
    ('advanced', 'Everything in Academy Member, plus:', 1),
    ('advanced', 'Weekly live coaching sessions', 2),
    ('advanced', 'Monthly Q&A with Shihan', 3),
    ('advanced', 'Instructor development content', 4),
    ('advanced', 'Exclusive seminars or masterclasses', 5),
    ('advanced', 'Priority grading preparation', 6),
    ('advanced', 'Early access to new content', 7),
    ('advanced', 'Discounts on seminars and merchandise', 8),
    ('advanced', 'Direct feedback on training videos', 9);

INSERT INTO videos (belt_slug, lesson_number, type, title, caption, duration, instructor, premium, sort_order) VALUES
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
    ('black', 1, 'Kihon', 'Black Belt Mindset: The Long Game', 'Black belt is the beginning, not the end.', '12:50', 'Sensei Kenji Ohta', TRUE, 16);

INSERT INTO content_blocks (key, value) VALUES
    ('philosophy_divider_label', 'IKKO Academy Philosophy'),
    ('events_divider_label', 'New & Upcoming Events'),
    ('hero_divider_label', 'Train With Intent'),
    ('membership_divider_label', 'Join IKKO Academy'),
    ('about_eyebrow', 'Who we are'),
    ('about_title', 'ABOUT IKKO ACADEMY'),
    ('about_body', 'IKKO Academy is the official digital dojo of the International Kenbukai Karate & Kobudo Federation — every kihon, kata, kumite and conditioning lesson taught in order, by real instructors, so you train with the same structure and correction as students on the mat.'),
    ('hero_eyebrow', 'Official Digital Learning Platform'),
    ('hero_title_line1', 'TRAIN. LEARN.'),
    ('hero_title_line2', 'GROW.'),
    ('hero_subtitle', 'IKKO Academy is the official online learning platform preserving and sharing the teachings of IKKO Karate. From live sessions and a recorded lesson library to belt-specific curriculum, training guides and grading prep, we help students revisit lessons, deepen their understanding and keep developing between classes — extending, never replacing, the discipline and philosophy learned in the dojo.'),
    ('library_title', 'EVERY LESSON, BY RANK.'),
    ('library_note', 'Filter by belt to see what''s next on your path, or by technique type to drill a specific skill. Lessons are numbered in the order your dojo teaches them.'),
    ('membership_title', 'PICK YOUR RANK.'),
    ('footer_note', 'White belt to black. No shortcuts.')
ON CONFLICT (key) DO NOTHING;