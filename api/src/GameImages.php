<?php
declare(strict_types=1);

final class GameImages
{
    private const COLLECTIONS = [
        'memory-match' => [
            'manifest' => 'assets/hero-loot/memory-match-images.json',
            'asset_directory' => 'assets/hero-loot/Memory Match pic',
            'min_images' => 2,
            'max_images' => 12,
        ],
        'picture-jigsaw' => [
            'manifest' => 'assets/hero-loot/picture-jigsaw-images.json',
            'asset_directory' => 'assets/Picture Jigsaw pic',
            'min_images' => 1,
            'max_images' => 30,
        ],
    ];

    public function __construct(
        private readonly PDO $db,
        private readonly string $projectRoot,
    ) {
    }

    public function listImages(string $gameSlug, bool $includeInactive = false): array
    {
        $configuration = $this->configuration($gameSlug);
        $this->seedDefaults($gameSlug, $configuration);
        $sql = 'SELECT image_key, source_path, alt_text, sort_order, is_active
                FROM game_images WHERE game_slug = :game_slug';
        if (!$includeInactive) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY sort_order, image_key';
        $statement = $this->db->prepare($sql);
        $statement->execute(['game_slug' => $gameSlug]);
        $images = [];
        foreach ($statement->fetchAll() as $row) {
            $images[] = $this->presentImage($gameSlug, $row);
        }
        return $images;
    }

    public function gameCatalog(string $gameSlug): array
    {
        $configuration = $this->configuration($gameSlug);
        $images = array_values(array_filter(
            $this->listImages($gameSlug),
            static fn (array $image): bool => $image['available'],
        ));
        if (count($images) < $configuration['min_images']) {
            throw new ApiException('GAME_ASSETS_UNAVAILABLE', 'The game does not have enough available images.', 503);
        }
        return array_map(static function (array $image): array {
            unset($image['is_active'], $image['sort_order'], $image['available']);
            return $image;
        }, $images);
    }

    public function maxImages(string $gameSlug): int
    {
        return $this->configuration($gameSlug)['max_images'];
    }

    public function addOrReplace(
        int $actorId,
        string $gameSlug,
        ?string $imageKey,
        string $altText,
        array $file,
    ): array {
        $configuration = $this->configuration($gameSlug);
        $this->seedDefaults($gameSlug, $configuration);
        $altText = trim($altText);
        if ($altText === '' || $this->textLength($altText) > 200) {
            throw new ApiException('VALIDATION_ERROR', 'Image description must contain 1 to 200 characters.', 422);
        }
        if ($imageKey !== null && !$this->validImageKey($imageKey)) {
            throw new ApiException('VALIDATION_ERROR', 'Invalid game image key.', 422);
        }

        $imageInfo = $this->validateUpload($file);
        $isReplacement = $imageKey !== null;
        $imageKey ??= 'image-' . bin2hex(random_bytes(8));
        $now = gmdate('Y-m-d H:i:s');

        $this->db->beginTransaction();
        $destination = null;
        try {
            $this->lockCollection($gameSlug);
            $current = null;
            if ($isReplacement) {
                $current = $this->findRow($gameSlug, $imageKey, true);
                if ($current === null) {
                    throw new ApiException('RESOURCE_NOT_FOUND', 'Game image not found.', 404);
                }
            } else {
                $count = $this->db->prepare('SELECT COUNT(*) FROM game_images WHERE game_slug = :game_slug');
                $count->execute(['game_slug' => $gameSlug]);
                if ((int) $count->fetchColumn() >= $configuration['max_images']) {
                    throw new ApiException(
                        'VALIDATION_ERROR',
                        'This game has reached its image limit of ' . $configuration['max_images'] . '.',
                        422,
                    );
                }
            }

            $destination = $this->storeUpload($gameSlug, $imageKey, $imageInfo['extension'], $file);
            $relativePath = substr($destination, strlen(rtrim($this->projectRoot, '/\\')) + 1);
            if ($current === null) {
                $nextOrder = $this->db->prepare(
                    'SELECT COALESCE(MAX(sort_order), -1) + 1
                     FROM game_images WHERE game_slug = :game_slug',
                );
                $nextOrder->execute(['game_slug' => $gameSlug]);
                $insert = $this->db->prepare(
                    'INSERT INTO game_images
                        (game_slug, image_key, source_path, alt_text, sort_order, is_active,
                         created_by, updated_by, created_at, updated_at)
                     VALUES (:game_slug, :image_key, :source_path, :alt_text, :sort_order, 1,
                             :actor_id, :actor_id, :created_at, :updated_at)',
                );
                $insert->execute([
                    'game_slug' => $gameSlug,
                    'image_key' => $imageKey,
                    'source_path' => $relativePath,
                    'alt_text' => $altText,
                    'sort_order' => (int) $nextOrder->fetchColumn(),
                    'actor_id' => $actorId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $update = $this->db->prepare(
                    'UPDATE game_images
                     SET source_path = :source_path, alt_text = :alt_text,
                         updated_by = :actor_id, updated_at = :updated_at
                     WHERE game_slug = :game_slug AND image_key = :image_key',
                );
                $update->execute([
                    'source_path' => $relativePath,
                    'alt_text' => $altText,
                    'actor_id' => $actorId,
                    'updated_at' => $now,
                    'game_slug' => $gameSlug,
                    'image_key' => $imageKey,
                ]);
            }
            $this->audit($actorId, 'owner_game_image_uploaded', $gameSlug, $imageKey, [
                'operation' => $isReplacement ? 'replaced' : 'added',
                'source_path' => $relativePath,
                'alt_text' => $altText,
            ]);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if (is_string($destination) && is_file($destination)) {
                unlink($destination);
            }
            throw $exception;
        }

        return $this->listImages($gameSlug, true);
    }

    public function setActive(int $actorId, string $gameSlug, string $imageKey, bool $isActive): array
    {
        $configuration = $this->configuration($gameSlug);
        $this->seedDefaults($gameSlug, $configuration);
        if (!$this->validImageKey($imageKey)) {
            throw new ApiException('VALIDATION_ERROR', 'Invalid game image key.', 422);
        }

        $this->db->beginTransaction();
        try {
            $this->lockCollection($gameSlug);
            $current = $this->findRow($gameSlug, $imageKey, true);
            if ($current === null) {
                throw new ApiException('RESOURCE_NOT_FOUND', 'Game image not found.', 404);
            }
            if (!$isActive && (bool) $current['is_active']) {
                $count = $this->db->prepare(
                    'SELECT COUNT(*) FROM game_images WHERE game_slug = :game_slug AND is_active = 1',
                );
                $count->execute(['game_slug' => $gameSlug]);
                if ((int) $count->fetchColumn() <= $configuration['min_images']) {
                    throw new ApiException(
                        'VALIDATION_ERROR',
                        'Keep at least ' . $configuration['min_images'] . ' active image(s) in this game.',
                        422,
                    );
                }
            }
            if ((bool) $current['is_active'] !== $isActive) {
                $now = gmdate('Y-m-d H:i:s');
                $update = $this->db->prepare(
                    'UPDATE game_images
                     SET is_active = :is_active, updated_by = :actor_id, updated_at = :updated_at
                     WHERE game_slug = :game_slug AND image_key = :image_key',
                );
                $update->execute([
                    'is_active' => $isActive ? 1 : 0,
                    'actor_id' => $actorId,
                    'updated_at' => $now,
                    'game_slug' => $gameSlug,
                    'image_key' => $imageKey,
                ]);
                $this->audit($actorId, 'owner_game_image_visibility_changed', $gameSlug, $imageKey, [
                    'is_active' => $isActive,
                ]);
            }
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
        return $this->listImages($gameSlug, true);
    }

    public function reorder(int $actorId, string $gameSlug, array $imageKeys): array
    {
        $this->configuration($gameSlug);
        $this->seedDefaults($gameSlug, self::COLLECTIONS[$gameSlug]);
        if (
            !array_is_list($imageKeys)
            || array_filter($imageKeys, fn (mixed $imageKey): bool => !is_string($imageKey) || !$this->validImageKey($imageKey)) !== []
        ) {
            throw new ApiException('VALIDATION_ERROR', 'Image order must be a list of valid image keys.', 422);
        }

        $this->db->beginTransaction();
        try {
            $this->lockCollection($gameSlug);
            $currentKeys = array_column($this->listImages($gameSlug, true), 'id');
            if (
                count($imageKeys) !== count($currentKeys)
                || count(array_unique($imageKeys)) !== count($imageKeys)
                || array_diff($currentKeys, $imageKeys) !== []
                || array_diff($imageKeys, $currentKeys) !== []
            ) {
                throw new ApiException('VALIDATION_ERROR', 'The image order must include every game image exactly once.', 422);
            }
            $update = $this->db->prepare(
                'UPDATE game_images SET sort_order = :sort_order, updated_by = :actor_id, updated_at = :updated_at
                 WHERE game_slug = :game_slug AND image_key = :image_key',
            );
            $now = gmdate('Y-m-d H:i:s');
            foreach ($imageKeys as $order => $imageKey) {
                $update->execute([
                    'sort_order' => $order,
                    'actor_id' => $actorId,
                    'updated_at' => $now,
                    'game_slug' => $gameSlug,
                    'image_key' => $imageKey,
                ]);
            }
            $this->audit($actorId, 'owner_game_images_reordered', $gameSlug, null, [
                'image_keys' => $imageKeys,
            ]);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
        return $this->listImages($gameSlug, true);
    }

    private function configuration(string $gameSlug): array
    {
        if (!isset(self::COLLECTIONS[$gameSlug])) {
            throw new ApiException('VALIDATION_ERROR', 'Unsupported game image collection.', 422);
        }
        return self::COLLECTIONS[$gameSlug];
    }

    private function lockCollection(string $gameSlug): void
    {
        $suffix = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $statement = $this->db->prepare(
            'SELECT id FROM game_catalog WHERE slug = :game_slug' . $suffix,
        );
        $statement->execute(['game_slug' => $gameSlug]);
        if ($statement->fetchColumn() === false) {
            throw new ApiException('GAME_NOT_AVAILABLE', 'The game image collection is not configured.', 503);
        }
    }

    private function seedDefaults(string $gameSlug, array $configuration): void
    {
        $count = $this->db->prepare('SELECT COUNT(*) FROM game_images WHERE game_slug = :game_slug');
        $count->execute(['game_slug' => $gameSlug]);
        if ((int) $count->fetchColumn() > 0) {
            return;
        }

        $manifestPath = rtrim($this->projectRoot, '/\\') . '/' . $configuration['manifest'];
        $contents = file_get_contents($manifestPath);
        if ($contents === false) {
            throw new ApiException('GAME_ASSETS_UNAVAILABLE', 'The game image manifest is unavailable.', 503);
        }
        try {
            $records = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ApiException('GAME_ASSETS_UNAVAILABLE', 'The game image manifest is invalid.', 503);
        }
        if (!is_array($records) || $records === []) {
            throw new ApiException('GAME_ASSETS_UNAVAILABLE', 'The game image manifest is empty.', 503);
        }

        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'mysql'
            ? 'INSERT IGNORE INTO game_images
                (game_slug, image_key, source_path, alt_text, sort_order, is_active, created_at, updated_at)
               VALUES (:game_slug, :image_key, :source_path, :alt_text, :sort_order, 1, :created_at, :updated_at)'
            : 'INSERT INTO game_images
                (game_slug, image_key, source_path, alt_text, sort_order, is_active, created_at, updated_at)
               VALUES (:game_slug, :image_key, :source_path, :alt_text, :sort_order, 1, :created_at, :updated_at)
               ON CONFLICT(game_slug, image_key) DO NOTHING';
        $insert = $this->db->prepare($sql);
        $now = gmdate('Y-m-d H:i:s');
        foreach ($records as $index => $record) {
            if (
                !is_array($record)
                || !is_string($record['id'] ?? null)
                || !$this->validImageKey($record['id'])
                || !is_string($record['src'] ?? null)
                || !is_string($record['alt'] ?? null)
                || trim($record['alt']) === ''
                || $this->textLength($record['alt']) > 200
            ) {
                throw new ApiException('GAME_ASSETS_UNAVAILABLE', 'The game image manifest contains an invalid entry.', 503);
            }
            $this->validateSourcePath($gameSlug, $record['src']);
            $insert->execute([
                'game_slug' => $gameSlug,
                'image_key' => $record['id'],
                'source_path' => $record['src'],
                'alt_text' => $record['alt'],
                'sort_order' => $index,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function presentImage(string $gameSlug, array $row): array
    {
        $source = (string) $row['source_path'];
        $this->validateSourcePath($gameSlug, $source);
        $path = $this->resolveSourcePath($gameSlug, $source);
        $info = $path === null ? false : @getimagesize($path);
        $available = $info !== false
            && in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true);
        return [
            'id' => (string) $row['image_key'],
            'alt' => (string) $row['alt_text'],
            'src' => $this->publicUrl($source),
            'sort_order' => (int) $row['sort_order'],
            'is_active' => (bool) $row['is_active'],
            'available' => $available,
            'width' => $available ? (int) $info[0] : null,
            'height' => $available ? (int) $info[1] : null,
        ];
    }

    private function validateUpload(array $file): array
    {
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new ApiException('VALIDATION_ERROR', 'Choose an image file to upload.', 422);
        }
        $temporaryPath = (string) ($file['tmp_name'] ?? '');
        if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
            throw new ApiException('VALIDATION_ERROR', 'The uploaded file could not be verified.', 422);
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size < 1 || $size > 5 * 1024 * 1024) {
            throw new ApiException('VALIDATION_ERROR', 'Images must be 5 MB or smaller.', 422);
        }

        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $fileInfo === false ? '' : (string) finfo_file($fileInfo, $temporaryPath);
        if ($fileInfo !== false) {
            finfo_close($fileInfo);
        }
        $extensions = ['image/webp' => 'webp', 'image/png' => 'png', 'image/jpeg' => 'jpg'];
        $dimensions = @getimagesize($temporaryPath);
        if (
            !isset($extensions[$mime])
            || $dimensions === false
            || ($dimensions['mime'] ?? '') !== $mime
            || $dimensions[0] < 1
            || $dimensions[1] < 1
            || $dimensions[0] > 8000
            || $dimensions[1] > 8000
        ) {
            throw new ApiException('VALIDATION_ERROR', 'Upload a valid WebP, PNG, or JPG image up to 8000 × 8000 pixels.', 422);
        }
        return ['extension' => $extensions[$mime]];
    }

    private function storeUpload(string $gameSlug, string $imageKey, string $extension, array $file): string
    {
        $projectRoot = rtrim($this->projectRoot, '/\\');
        $uploadRoot = $projectRoot . '/uploads/game-images';
        $directory = $uploadRoot . '/' . $gameSlug;
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the game image upload directory.');
        }
        $realUploadRoot = realpath($uploadRoot);
        $realDirectory = realpath($directory);
        if (
            $realUploadRoot === false
            || $realDirectory === false
            || !str_starts_with($realDirectory, $realUploadRoot . DIRECTORY_SEPARATOR)
        ) {
            throw new RuntimeException('The game image upload directory is not safe.');
        }
        $protectionFile = $uploadRoot . '/.htaccess';
        if (!is_file($protectionFile)) {
            $written = file_put_contents(
                $protectionFile,
                "Options -Indexes\n<FilesMatch \"(?i)\\.(?:php[0-9]?|phtml|phar)$\">\n    Require all denied\n</FilesMatch>\n",
                LOCK_EX,
            );
            if ($written === false) {
                throw new RuntimeException('Could not protect the game image upload directory.');
            }
        }

        $filename = $imageKey . '-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(6)) . '.' . $extension;
        $destination = $realDirectory . '/' . $filename;
        if (!move_uploaded_file((string) $file['tmp_name'], $destination)) {
            throw new RuntimeException('Could not save the game image file.');
        }
        if (!chmod($destination, 0644)) {
            unlink($destination);
            throw new RuntimeException('Could not set safe permissions for the game image file.');
        }
        return $destination;
    }

    private function findRow(string $gameSlug, string $imageKey, bool $lock): ?array
    {
        $suffix = $lock && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $statement = $this->db->prepare(
            'SELECT source_path, alt_text, sort_order, is_active
             FROM game_images WHERE game_slug = :game_slug AND image_key = :image_key' . $suffix,
        );
        $statement->execute(['game_slug' => $gameSlug, 'image_key' => $imageKey]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    private function validateSourcePath(string $gameSlug, string $source): void
    {
        $configuration = $this->configuration($gameSlug);
        $assetPrefix = $configuration['asset_directory'] . '/';
        $uploadPrefix = 'uploads/game-images/' . $gameSlug . '/';
        if (
            str_contains($source, "\0")
            || str_contains($source, '\\')
            || str_contains($source, '../')
            || (!str_starts_with($source, $assetPrefix) && !str_starts_with($source, $uploadPrefix))
        ) {
            throw new ApiException('GAME_ASSETS_UNAVAILABLE', 'A game image path is invalid.', 503);
        }

        $resolved = realpath(rtrim($this->projectRoot, '/\\') . '/' . $source);
        if ($resolved === false) {
            return;
        }
        $allowedRoot = str_starts_with($source, $assetPrefix)
            ? realpath(rtrim($this->projectRoot, '/\\') . '/' . $configuration['asset_directory'])
            : realpath(rtrim($this->projectRoot, '/\\') . '/uploads/game-images/' . $gameSlug);
        if ($allowedRoot === false || !str_starts_with($resolved, $allowedRoot . DIRECTORY_SEPARATOR)) {
            throw new ApiException('GAME_ASSETS_UNAVAILABLE', 'A game image path is outside its allowed folder.', 503);
        }
    }

    private function resolveSourcePath(string $gameSlug, string $source): ?string
    {
        $this->validateSourcePath($gameSlug, $source);
        $path = realpath(rtrim($this->projectRoot, '/\\') . '/' . $source);
        return $path !== false && is_file($path) ? $path : null;
    }

    private function publicUrl(string $source): string
    {
        return '/' . implode('/', array_map('rawurlencode', explode('/', $source)));
    }

    private function validImageKey(string $imageKey): bool
    {
        return preg_match('/\A[a-z0-9][a-z0-9-]{0,59}\z/', $imageKey) === 1;
    }

    private function textLength(string $value): int
    {
        $count = preg_match_all('/./us', $value);
        return $count === false ? PHP_INT_MAX : $count;
    }

    private function audit(int $actorId, string $action, string $gameSlug, ?string $imageKey, array $payload): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO audit_logs
                (actor_user_id, actor_role, action_type, entity_type, entity_id, payload,
                 ip_address, user_agent, created_at)
             VALUES (:actor_id, \'owner\', :action_type, \'game_image\', NULL, :payload,
                     :ip_address, :user_agent, :created_at)',
        );
        $statement->execute([
            'actor_id' => $actorId,
            'action_type' => $action,
            'payload' => json_encode(
                ['game_slug' => $gameSlug, 'image_key' => $imageKey] + $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 512) : null,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
