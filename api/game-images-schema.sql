CREATE TABLE IF NOT EXISTS game_images (
    game_slug VARCHAR(32) NOT NULL,
    image_key VARCHAR(60) NOT NULL,
    source_path VARCHAR(255) NOT NULL,
    alt_text VARCHAR(200) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (game_slug, image_key),
    KEY idx_game_images_public_order (game_slug, is_active, sort_order),
    CONSTRAINT chk_game_images_slug CHECK (game_slug IN ('memory-match', 'picture-jigsaw')),
    CONSTRAINT fk_game_images_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_game_images_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
