-- Run this in your psql session to set the hero title text directly.
-- Edit the VALUES below to whatever you actually want it to say, then run it.
-- This bypasses the admin panel entirely, so it works regardless of deploy status.

UPDATE content_blocks SET value = 'TRAIN WITH', updated_at = NOW() WHERE key = 'hero_title_line1';
UPDATE content_blocks SET value = 'INTENT.', updated_at = NOW() WHERE key = 'hero_title_line2';

-- Confirm it took:
SELECT key, value FROM content_blocks WHERE key IN ('hero_title_line1', 'hero_title_line2');