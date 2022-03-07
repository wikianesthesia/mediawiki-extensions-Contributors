ALTER TABLE /*_*/contributors
    ADD COLUMN cn_characters_added int unsigned NOT NULL DEFAULT 0 AFTER cn_revision_count;