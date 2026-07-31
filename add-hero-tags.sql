-- Adds the hero tags as real, editable content_blocks rows on the live DB.
-- Safe to run more than once.
INSERT INTO content_blocks (key, value) VALUES
    ('hero_tag_1', 'Live Training'),
    ('hero_tag_2', 'Lesson Library'),
    ('hero_tag_3', 'Belt Curriculum'),
    ('hero_tag_4', 'Grading Prep')
ON CONFLICT (key) DO NOTHING;