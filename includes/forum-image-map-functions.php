<?php

declare(strict_types=1);

/**
 * Blackthorne Academy
 * Forum Image Map Helpers
 */

function forum_image_map_webp_reference(?string $reference): ?string
{
    $reference = trim((string) $reference);

    if ($reference === '') {
        return null;
    }

    $extension = strtolower((string) pathinfo($reference, PATHINFO_EXTENSION));

    if ($extension === 'webp') {
        return $reference;
    }

    $candidate = preg_replace('/\.[^.\/]+$/', '.webp', $reference);

    if (!is_string($candidate) || $candidate === $reference) {
        return null;
    }

    $absolute = BASE_PATH . '/' . ltrim($candidate, '/');

    return is_file($absolute) ? $candidate : null;
}



function forum_image_map_generate_webp(string $absolutePath, string $mime): ?string
{
    if ($mime === 'image/webp') {
        return $absolutePath;
    }

    if (!extension_loaded('gd') || !function_exists('imagewebp')) {
        return null;
    }

    $image = match ($mime) {
        'image/png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($absolutePath) : false,
        'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($absolutePath) : false,
        'image/gif' => function_exists('imagecreatefromgif') ? @imagecreatefromgif($absolutePath) : false,
        default => false,
    };

    if ($image === false) {
        return null;
    }

    if ($mime === 'image/png' || $mime === 'image/gif') {
        imagealphablending($image, true);
        imagesavealpha($image, true);
    }

    $webpPath = preg_replace('/\.[^.\/]+$/', '.webp', $absolutePath);

    if (!is_string($webpPath) || $webpPath === $absolutePath) {
        imagedestroy($image);
        return null;
    }

    $created = @imagewebp($image, $webpPath, 85);
    imagedestroy($image);

    if (!$created || !is_file($webpPath) || (int) @filesize($webpPath) <= 0) {
        if (is_file($webpPath)) {
            @unlink($webpPath);
        }
        return null;
    }

    return $webpPath;
}

function forum_image_map_upload(int $forumId, array $file): array
{
    if ($forumId <= 0) {
        throw new RuntimeException('A valid forum is required before uploading an image map.');
    }

    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Choose an image for the forum image map.');
    }

    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The image map image could not be uploaded.');
    }

    $size = (int) ($file['size'] ?? 0);

    if ($size <= 0 || $size > 15 * 1024 * 1024) {
        throw new RuntimeException('Image map images must be 15 MB or smaller.');
    }

    $temporaryPath = (string) ($file['tmp_name'] ?? '');

    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        throw new RuntimeException('The uploaded image map file is invalid.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($temporaryPath);

    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Image maps support PNG, JPG, GIF, or WebP images.');
    }

    $dimensions = @getimagesize($temporaryPath);

    if (!is_array($dimensions) || empty($dimensions[0]) || empty($dimensions[1])) {
        throw new RuntimeException('The image map image dimensions could not be read.');
    }

    $directory = UPLOADS_PATH . '/forum-maps/library';

    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('The forum image map upload folder could not be created.');
    }

    $filename = 'map-' . bin2hex(random_bytes(10)) . '.' . $extensions[$mime];
    $absolutePath = $directory . '/' . $filename;

    if (!move_uploaded_file($temporaryPath, $absolutePath)) {
        throw new RuntimeException('The forum image map image could not be saved.');
    }

    forum_image_map_generate_webp($absolutePath, $mime);

    return [
        'path' => 'uploads/forum-maps/library/' . $filename,
        'width' => (int) $dimensions[0],
        'height' => (int) $dimensions[1],
    ];
}

function forum_image_map_delete_file(?string $reference): void
{
    $reference = trim((string) $reference);

    if ($reference === '') {
        return;
    }

    $absolute = BASE_PATH . '/' . ltrim($reference, '/');

    if (is_file($absolute)) {
        @unlink($absolute);
    }

    $webp = preg_replace('/\.[^.\/]+$/', '.webp', $absolute);

    if (is_string($webp) && $webp !== $absolute && is_file($webp)) {
        @unlink($webp);
    }
}

function forum_image_map_parse_areas(string $json): array
{
    $json = trim($json);

    if ($json === '') {
        return [];
    }

    $decoded = json_decode($json, true);

    if (!is_array($decoded)) {
        throw new RuntimeException('The image map hotspot data is invalid.');
    }

    $areas = [];

    foreach ($decoded as $index => $area) {
        if (!is_array($area)) {
            continue;
        }

        $shape = (string) ($area['shape'] ?? '');

        if (!in_array($shape, ['rect', 'circle', 'poly'], true)) {
            continue;
        }

        $coords = $area['coords'] ?? [];

        if (!is_array($coords)) {
            continue;
        }

        $normalizedCoords = [];

        foreach ($coords as $coordinate) {
            if (!is_numeric($coordinate)) {
                continue 2;
            }

            $normalizedCoords[] = round((float) $coordinate, 3);
        }

        $minimumCoordinates = $shape === 'rect' ? 4 : ($shape === 'circle' ? 3 : 6);

        if (count($normalizedCoords) < $minimumCoordinates) {
            continue;
        }

        $linkType = (string) ($area['link_type'] ?? 'url');

        if (!in_array($linkType, ['forum', 'url'], true)) {
            $linkType = 'url';
        }

        $targetForumId = isset($area['target_forum_id']) && is_numeric($area['target_forum_id'])
            ? max(0, (int) $area['target_forum_id'])
            : 0;

        $linkUrl = trim((string) ($area['link_url'] ?? ''));

        if (
            $linkUrl !== ''
            && preg_match('~^(?:https?://|/|[a-zA-Z0-9][a-zA-Z0-9._/-]*\.php(?:\?|$))~', $linkUrl) !== 1
        ) {
            throw new RuntimeException(
                'Image map area #' . ($index + 1) . ' has an invalid URL. Use a full http(s) URL or a site path such as /forum.php?f=13.'
            );
        }

        $title = trim((string) ($area['title'] ?? ''));
        $altText = trim((string) ($area['alt_text'] ?? ''));
        $target = (string) ($area['target'] ?? '_self');

        if (!in_array($target, ['_self', '_blank'], true)) {
            $target = '_self';
        }

        /*
         * Keep valid drawn areas even when their destination has not been
         * configured yet. The admin image-map editor is allowed to save a
         * hotspot as work in progress; forum.php already ignores unlinked
         * hotspots on the public map until a forum or URL is assigned.
         */
        if ($linkType === 'forum' && $targetForumId <= 0) {
            $targetForumId = 0;
        }

        if ($linkType === 'url' && $linkUrl === '') {
            $linkUrl = '';
        }

        $areas[] = [
            'shape' => $shape,
            'coords' => $normalizedCoords,
            'link_type' => $linkType,
            'target_forum_id' => $targetForumId > 0 ? $targetForumId : null,
            'link_url' => $linkUrl !== '' ? substr($linkUrl, 0, 500) : null,
            'title' => $title !== '' ? substr($title, 0, 150) : null,
            'alt_text' => $altText !== '' ? substr($altText, 0, 255) : null,
            'target' => $target,
            'sort_order' => ($index + 1) * 10,
        ];
    }

    return $areas;
}

function forum_image_map_load_by_id(PDO $pdo, int $imageMapId): ?array
{
    if ($imageMapId <= 0) {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT
            fim.id,
            fim.forum_id,
            fim.name,
            fim.image_path,
            fim.image_alt,
            fim.original_width,
            fim.original_height,
            fim.is_active,
            (
                SELECT COUNT(*)
                FROM forum_image_map_assignments fima
                WHERE fima.image_map_id = fim.id
            ) AS usage_count
         FROM forum_image_maps fim
         WHERE fim.id = :image_map_id
           AND fim.is_active = 1
         LIMIT 1'
    );
    $statement->execute(['image_map_id' => $imageMapId]);
    $map = $statement->fetch(PDO::FETCH_ASSOC);

    if (!is_array($map)) {
        return null;
    }

    $areaStatement = $pdo->prepare(
        'SELECT
            id,
            shape,
            coords_json,
            link_type,
            target_forum_id,
            link_url,
            title,
            alt_text,
            link_target,
            sort_order
         FROM forum_image_map_areas
         WHERE image_map_id = :image_map_id
         ORDER BY sort_order ASC, id ASC'
    );
    $areaStatement->execute(['image_map_id' => $imageMapId]);

    $areas = [];

    foreach ($areaStatement->fetchAll(PDO::FETCH_ASSOC) as $area) {
        $coords = json_decode((string) $area['coords_json'], true);

        if (!is_array($coords)) {
            continue;
        }

        $area['coords'] = array_map('floatval', $coords);
        unset($area['coords_json']);
        $areas[] = $area;
    }

    $map['areas'] = $areas;
    $map['webp_path'] = forum_image_map_webp_reference((string) $map['image_path']);

    return $map;
}

function forum_image_map_list(PDO $pdo): array
{
    $ids = $pdo->query(
        'SELECT id
         FROM forum_image_maps
         WHERE is_active = 1
         ORDER BY COALESCE(NULLIF(name, ""), CONCAT("Image Map #", id)) ASC, id ASC'
    )->fetchAll(PDO::FETCH_COLUMN);

    $maps = [];

    foreach ($ids as $id) {
        $map = forum_image_map_load_by_id($pdo, (int) $id);
        if ($map !== null) {
            $maps[] = $map;
        }
    }

    return $maps;
}

function forum_image_map_load(PDO $pdo, int $forumId): ?array
{
    if ($forumId <= 0) {
        return null;
    }

    $assignment = $pdo->prepare(
        'SELECT image_map_id
         FROM forum_image_map_assignments
         WHERE forum_id = :forum_id
         LIMIT 1'
    );
    $assignment->execute(['forum_id' => $forumId]);
    $imageMapId = (int) ($assignment->fetchColumn() ?: 0);

    if ($imageMapId <= 0) {
        return null;
    }

    return forum_image_map_load_by_id($pdo, $imageMapId);
}

function forum_image_map_assign(PDO $pdo, int $forumId, int $imageMapId): void
{
    $statement = $pdo->prepare(
        'INSERT INTO forum_image_map_assignments (forum_id, image_map_id)
         VALUES (:forum_id, :image_map_id)
         ON DUPLICATE KEY UPDATE
            image_map_id = VALUES(image_map_id),
            updated_at = CURRENT_TIMESTAMP'
    );
    $statement->execute([
        'forum_id' => $forumId,
        'image_map_id' => $imageMapId,
    ]);
}

function forum_image_map_unassign(PDO $pdo, int $forumId): void
{
    $statement = $pdo->prepare(
        'DELETE FROM forum_image_map_assignments WHERE forum_id = :forum_id'
    );
    $statement->execute(['forum_id' => $forumId]);
}

function forum_image_map_save_shared(
    PDO $pdo,
    int $forumId,
    bool $enabled,
    int $selectedImageMapId,
    string $mapName,
    ?array $uploadedImage,
    string $imageAlt,
    array $areas
): ?string {
    if (!$enabled) {
        forum_image_map_unassign($pdo, $forumId);
        return null;
    }

    $mapName = trim($mapName);
    $existing = $selectedImageMapId > 0
        ? forum_image_map_load_by_id($pdo, $selectedImageMapId)
        : null;

    if ($selectedImageMapId > 0 && $existing === null) {
        throw new RuntimeException('The selected saved image map could not be found.');
    }

    if ($mapName === '' && $existing !== null) {
        $mapName = trim((string) ($existing['name'] ?? ''));
    }

    if ($mapName === '') {
        throw new RuntimeException('Give this image map a name so it can be selected again later.');
    }

    $imagePath = $uploadedImage['path'] ?? ($existing['image_path'] ?? null);
    $width = (int) ($uploadedImage['width'] ?? ($existing['original_width'] ?? 0));
    $height = (int) ($uploadedImage['height'] ?? ($existing['original_height'] ?? 0));

    if (!is_string($imagePath) || trim($imagePath) === '' || $width <= 0 || $height <= 0) {
        throw new RuntimeException('Upload an image before saving this image map.');
    }

    $obsoletePath = null;

    if ($uploadedImage !== null && $existing !== null) {
        $oldPath = trim((string) ($existing['image_path'] ?? ''));
        if ($oldPath !== '' && $oldPath !== $imagePath) {
            $obsoletePath = $oldPath;
        }
    }

    if ($existing === null) {
        $statement = $pdo->prepare(
            'INSERT INTO forum_image_maps (
                forum_id, name, image_path, image_alt, original_width, original_height, is_active
             ) VALUES (
                :forum_id, :name, :image_path, :image_alt, :original_width, :original_height, 1
             )'
        );
        $statement->execute([
            'forum_id' => $forumId,
            'name' => substr($mapName, 0, 150),
            'image_path' => $imagePath,
            'image_alt' => $imageAlt !== '' ? substr($imageAlt, 0, 255) : null,
            'original_width' => $width,
            'original_height' => $height,
        ]);
        $imageMapId = (int) $pdo->lastInsertId();
    } else {
        $imageMapId = (int) $existing['id'];
        $statement = $pdo->prepare(
            'UPDATE forum_image_maps
             SET name = :name,
                 image_path = :image_path,
                 image_alt = :image_alt,
                 original_width = :original_width,
                 original_height = :original_height,
                 is_active = 1
             WHERE id = :image_map_id'
        );
        $statement->execute([
            'name' => substr($mapName, 0, 150),
            'image_path' => $imagePath,
            'image_alt' => $imageAlt !== '' ? substr($imageAlt, 0, 255) : null,
            'original_width' => $width,
            'original_height' => $height,
            'image_map_id' => $imageMapId,
        ]);
    }

    $pdo->prepare('DELETE FROM forum_image_map_areas WHERE image_map_id = :image_map_id')
        ->execute(['image_map_id' => $imageMapId]);

    if ($areas !== []) {
        $insertArea = $pdo->prepare(
            'INSERT INTO forum_image_map_areas (
                image_map_id, shape, coords_json, link_type, target_forum_id,
                link_url, title, alt_text, link_target, sort_order
             ) VALUES (
                :image_map_id, :shape, :coords_json, :link_type, :target_forum_id,
                :link_url, :title, :alt_text, :link_target, :sort_order
             )'
        );

        foreach ($areas as $area) {
            $insertArea->execute([
                'image_map_id' => $imageMapId,
                'shape' => $area['shape'],
                'coords_json' => json_encode($area['coords'], JSON_THROW_ON_ERROR),
                'link_type' => $area['link_type'],
                'target_forum_id' => $area['target_forum_id'],
                'link_url' => $area['link_url'],
                'title' => $area['title'],
                'alt_text' => $area['alt_text'],
                'link_target' => $area['target'],
                'sort_order' => $area['sort_order'],
            ]);
        }
    }

    forum_image_map_assign($pdo, $forumId, $imageMapId);

    return $obsoletePath;
}
