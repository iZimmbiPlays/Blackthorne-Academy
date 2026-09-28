<?php

declare(strict_types=1);

/**
 * Blackthorne Academy
 * House System Helpers
 *
 * Central House data + crest helpers used by:
 *   - admin/houses.php
 *   - common-room.php
 *   - Sorting Ceremony assignment
 *   - forum house access
 *   - dashboard/profile/forum House display
 */


/*
|--------------------------------------------------------------------------
| General House Validation
|--------------------------------------------------------------------------
*/

function house_slugify(string $value): string
{
    $value = trim($value);

    $value = function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);

    if (function_exists('iconv')) {
        $converted = iconv(
            'UTF-8',
            'ASCII//TRANSLIT//IGNORE',
            $value
        );

        if (is_string($converted)) {
            $value = strtolower($converted);
        }
    }

    $value =
        preg_replace(
            '/[^a-z0-9]+/',
            '-',
            $value
        )
        ?? '';

    return substr(
        trim($value, '-'),
        0,
        120
    );
}


function house_valid_hex_color(?string $value): bool
{
    $value = trim((string) $value);

    return
        $value === ''
        || preg_match(
            '/^#[0-9A-Fa-f]{6}$/',
            $value
        ) === 1;
}


function house_normalize_hex_color(?string $value): ?string
{
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    if (!house_valid_hex_color($value)) {
        throw new InvalidArgumentException(
            'House colors must use a six-digit hex value such as #6E3A73.'
        );
    }

    return strtoupper($value);
}


/*
|--------------------------------------------------------------------------
| Current School Year
|--------------------------------------------------------------------------
*/

function house_current_school_year_id(): ?int
{
    global $pdo;

    $statement = $pdo->query(
        'SELECT id
         FROM school_years
         WHERE is_current = 1
           AND is_active = 1
         ORDER BY id DESC
         LIMIT 1'
    );

    $value = $statement->fetchColumn();

    if ($value === false) {
        return null;
    }

    $schoolYearId = (int) $value;

    return $schoolYearId > 0
        ? $schoolYearId
        : null;
}


/*
|--------------------------------------------------------------------------
| House Lookup
|--------------------------------------------------------------------------
*/

function house_by_id(
    int $houseId,
    bool $includeInactive = false
): ?array {
    global $pdo;

    if ($houseId <= 0) {
        return null;
    }

    $sql =
        'SELECT *
         FROM houses
         WHERE id = :house_id';

    if (!$includeInactive) {
        $sql .= ' AND is_active = 1';
    }

    $sql .= ' LIMIT 1';

    $statement = $pdo->prepare($sql);
    $statement->execute([
        'house_id' => $houseId,
    ]);

    $house =
        $statement->fetch(
            PDO::FETCH_ASSOC
        );

    return is_array($house)
        ? $house
        : null;
}


function house_by_slug(
    string $slug,
    bool $includeInactive = false
): ?array {
    global $pdo;

    $slug = trim($slug);

    if ($slug === '') {
        return null;
    }

    $sql =
        'SELECT *
         FROM houses
         WHERE slug = :slug';

    if (!$includeInactive) {
        $sql .= ' AND is_active = 1';
    }

    $sql .= ' LIMIT 1';

    $statement = $pdo->prepare($sql);
    $statement->execute([
        'slug' => $slug,
    ]);

    $house =
        $statement->fetch(
            PDO::FETCH_ASSOC
        );

    return is_array($house)
        ? $house
        : null;
}


/*
|--------------------------------------------------------------------------
| Active House Membership
|--------------------------------------------------------------------------
|
| House membership is persistent across school years. A user keeps the same
| House until an administrator transfers or removes the membership.
|
*/

function house_user_membership(
    int $userId
): ?array {
    global $pdo;

    if ($userId <= 0) {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT
            hm.id AS membership_id,
            hm.user_id,
            hm.house_id,
            hm.assigned_by,
            hm.membership_status,
            hm.joined_at,
            hm.left_at,
            h.name,
            h.display_name,
            h.slug,
            h.description,
            h.motto,
            h.house_introduction,
            h.display_color,
            h.crest_image,
            h.hero_image,
            h.mascot_image,
            h.mascot_type,
            h.mascot_name,
            h.mascot_represents,
            h.primary_color,
            h.secondary_color,
            h.accent_color,
            h.dark_neutral_color,
            h.highlight_color,
            h.common_room_forum_id,
            h.announcement_forum_id,
            h.is_active AS house_is_active
         FROM house_memberships hm
         INNER JOIN houses h
            ON h.id = hm.house_id
         WHERE hm.user_id = :user_id
           AND hm.membership_status = "active"
           AND hm.left_at IS NULL
           AND h.is_active = 1
         ORDER BY hm.joined_at DESC, hm.id DESC
         LIMIT 1'
    );

    $statement->execute([
        'user_id' => $userId,
    ]);

    $membership =
        $statement->fetch(
            PDO::FETCH_ASSOC
        );

    return is_array($membership)
        ? $membership
        : null;
}


function current_user_house_membership(): ?array
{
    $userId = current_user_id();

    if ($userId === null) {
        return null;
    }

    return house_user_membership(
        $userId
    );
}


/*
|--------------------------------------------------------------------------
| Crest Configuration
|--------------------------------------------------------------------------
*/

function house_crest_config(): array
{
    return [
        'max_bytes' =>
            5 * 1024 * 1024,

        'max_width' =>
            1800,

        'max_height' =>
            1800,

        'allowed_mime_types' => [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
        ],
    ];
}


/*
|--------------------------------------------------------------------------
| Safe Crest Reference
|--------------------------------------------------------------------------
*/

function house_safe_crest_reference(
    ?string $value
): ?string {
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    if (
        !str_starts_with(
            $value,
            '/uploads/houses/'
        )
    ) {
        return null;
    }

    if (
        str_contains($value, '..')
        || str_contains($value, "\0")
    ) {
        return null;
    }

    return $value;
}


function house_crest_webp_reference(
    ?string $originalReference
): ?string {
    $originalReference =
        house_safe_crest_reference(
            $originalReference
        );

    if ($originalReference === null) {
        return null;
    }

    $extension =
        strtolower(
            pathinfo(
                $originalReference,
                PATHINFO_EXTENSION
            )
        );

    if ($extension === 'webp') {
        return $originalReference;
    }

    $webpReference =
        preg_replace(
            '/\.[^.\/]+$/',
            '.webp',
            $originalReference
        );

    if (!is_string($webpReference)) {
        return null;
    }

    $absolute =
        BASE_PATH
        . $webpReference;

    return is_file($absolute)
        ? $webpReference
        : null;
}




/*
|--------------------------------------------------------------------------
| House Mascot Images
|--------------------------------------------------------------------------
*/

function house_safe_mascot_reference(
    ?string $value
): ?string {
    $value =
        trim(
            (string) $value
        );

    if ($value === '') {
        return null;
    }

    if (
        !str_starts_with(
            $value,
            '/uploads/houses/'
        )
        || str_contains(
            $value,
            '..'
        )
        || str_contains(
            $value,
            "\0"
        )
    ) {
        return null;
    }

    return $value;
}


function house_mascot_webp_reference(
    ?string $originalReference
): ?string {
    $originalReference =
        house_safe_mascot_reference(
            $originalReference
        );

    if ($originalReference === null) {
        return null;
    }

    $extension =
        strtolower(
            pathinfo(
                $originalReference,
                PATHINFO_EXTENSION
            )
        );

    if ($extension === 'webp') {
        return $originalReference;
    }

    $webpReference =
        preg_replace(
            '/\.[^.\/]+$/',
            '.webp',
            $originalReference
        );

    if (!is_string($webpReference)) {
        return null;
    }

    return
        is_file(
            BASE_PATH
            . $webpReference
        )
            ? $webpReference
            : null;
}


function house_store_mascot_upload(
    array $file,
    int $houseId
): string {
    $validated =
        house_validate_crest_upload(
            $file
        );

    $directory =
        house_crest_storage_directory(
            $houseId
        );

    [
        $targetWidth,
        $targetHeight,
    ] =
        house_crest_target_dimensions(
            (int) $validated['width'],
            (int) $validated['height']
        );

    $baseName =
        'mascot-'
        . bin2hex(
            random_bytes(18)
        );

    $extension =
        (string) $validated['extension'];

    $originalFilename =
        $baseName
        . '.'
        . $extension;

    $originalAbsolute =
        $directory['absolute']
        . '/'
        . $originalFilename;

    $originalRelative =
        '/'
        . $directory['relative']
        . '/'
        . $originalFilename;

    $sourceMime =
        (string) $validated['mime_type'];

    $format =
        match ($sourceMime) {
            'image/jpeg' => 'jpeg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            default      => '',
        };

    $needsResize =
        $targetWidth !== (int) $validated['width']
        || $targetHeight !== (int) $validated['height'];

    $originalWritten = false;

    if (
        $format !== ''
        && $needsResize
    ) {
        $originalWritten =
            house_crest_write_with_imagick(
                (string) $validated['tmp_name'],
                $originalAbsolute,
                $format,
                $targetWidth,
                $targetHeight
            );

        if (!$originalWritten) {
            $originalWritten =
                house_crest_write_with_gd(
                    (string) $validated['tmp_name'],
                    $sourceMime,
                    $originalAbsolute,
                    $sourceMime,
                    $targetWidth,
                    $targetHeight
                );
        }
    }

    if (!$originalWritten) {
        $originalWritten =
            copy(
                (string) $validated['tmp_name'],
                $originalAbsolute
            );
    }

    if (
        !$originalWritten
        || !is_file(
            $originalAbsolute
        )
    ) {
        @unlink(
            $originalAbsolute
        );

        throw new RuntimeException(
            'The House mascot image could not be saved.'
        );
    }

    if ($sourceMime !== 'image/webp') {
        $webpAbsolute =
            $directory['absolute']
            . '/'
            . $baseName
            . '.webp';

        $webpWritten =
            house_crest_write_with_imagick(
                $originalAbsolute,
                $webpAbsolute,
                'webp',
                $targetWidth,
                $targetHeight
            );

        if (!$webpWritten) {
            $webpWritten =
                house_crest_write_with_gd(
                    $originalAbsolute,
                    $sourceMime,
                    $webpAbsolute,
                    'image/webp',
                    $targetWidth,
                    $targetHeight
                );
        }

        if (!$webpWritten) {
            @unlink(
                $webpAbsolute
            );
        }
    }

    return $originalRelative;
}


function house_delete_local_mascot(
    ?string $reference
): void {
    $reference =
        house_safe_mascot_reference(
            $reference
        );

    if ($reference === null) {
        return;
    }

    $paths = [
        BASE_PATH
        . $reference,
    ];

    $webpReference =
        preg_replace(
            '/\.[^.\/]+$/',
            '.webp',
            $reference
        );

    if (
        is_string(
            $webpReference
        )
        && $webpReference !== $reference
    ) {
        $paths[] =
            BASE_PATH
            . $webpReference;
    }

    foreach (
        array_unique(
            $paths
        )
        as $path
    ) {
        if (
            is_string($path)
            && is_file($path)
        ) {
            @unlink($path);
        }
    }
}


/*
|--------------------------------------------------------------------------
| House Hero / Cover Images
|--------------------------------------------------------------------------
*/

function house_hero_config(): array
{
    return [
        'max_bytes' =>
            8 * 1024 * 1024,

        'max_width' =>
            2600,

        'max_height' =>
            1600,

        'allowed_mime_types' => [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
        ],
    ];
}


function house_safe_hero_reference(
    ?string $value
): ?string {
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    if (
        !str_starts_with(
            $value,
            '/uploads/houses/'
        )
    ) {
        return null;
    }

    if (
        str_contains($value, '..')
        || str_contains($value, "\0")
    ) {
        return null;
    }

    return $value;
}


function house_hero_webp_reference(
    ?string $originalReference
): ?string {
    $originalReference =
        house_safe_hero_reference(
            $originalReference
        );

    if ($originalReference === null) {
        return null;
    }

    $extension =
        strtolower(
            pathinfo(
                $originalReference,
                PATHINFO_EXTENSION
            )
        );

    if ($extension === 'webp') {
        return $originalReference;
    }

    $webpReference =
        preg_replace(
            '/\.[^.\/]+$/',
            '.webp',
            $originalReference
        );

    if (!is_string($webpReference)) {
        return null;
    }

    $absolute =
        BASE_PATH
        . $webpReference;

    return is_file($absolute)
        ? $webpReference
        : null;
}


function house_validate_hero_upload(
    array $file
): array {
    $config =
        house_hero_config();

    $error =
        (int) (
            $file['error']
            ?? UPLOAD_ERR_NO_FILE
        );

    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException(
            'Choose a House hero image to upload.'
        );
    }

    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException(
            'The House hero image upload could not be completed.'
        );
    }

    $tmpName =
        (string) (
            $file['tmp_name']
            ?? ''
        );

    if (
        $tmpName === ''
        || !is_uploaded_file($tmpName)
    ) {
        throw new RuntimeException(
            'The House hero image upload is invalid.'
        );
    }

    $size =
        (int) (
            $file['size']
            ?? 0
        );

    if (
        $size <= 0
        || $size > (int) $config['max_bytes']
    ) {
        throw new RuntimeException(
            'House hero images must be 8 MB or smaller.'
        );
    }

    $finfo =
        new finfo(
            FILEINFO_MIME_TYPE
        );

    $mimeType =
        (string) $finfo->file(
            $tmpName
        );

    $allowed =
        $config['allowed_mime_types'];

    if (
        !isset(
            $allowed[$mimeType]
        )
    ) {
        throw new RuntimeException(
            'House hero images must be JPG, PNG, WebP, or AVIF.'
        );
    }

    $dimensions =
        @getimagesize(
            $tmpName
        );

    if (
        !is_array($dimensions)
        || !isset(
            $dimensions[0],
            $dimensions[1]
        )
    ) {
        throw new RuntimeException(
            'The uploaded House hero image is not a valid image.'
        );
    }

    $width =
        (int) $dimensions[0];

    $height =
        (int) $dimensions[1];

    if (
        $width <= 0
        || $height <= 0
    ) {
        throw new RuntimeException(
            'The uploaded House hero image has invalid dimensions.'
        );
    }

    return [
        'tmp_name' =>
            $tmpName,

        'mime_type' =>
            $mimeType,

        'extension' =>
            (string) $allowed[$mimeType],

        'width' =>
            $width,

        'height' =>
            $height,
    ];
}


function house_hero_target_dimensions(
    int $width,
    int $height
): array {
    $config =
        house_hero_config();

    $maxWidth =
        (int) $config['max_width'];

    $maxHeight =
        (int) $config['max_height'];

    if (
        $width <= $maxWidth
        && $height <= $maxHeight
    ) {
        return [
            $width,
            $height,
        ];
    }

    $ratio =
        min(
            $maxWidth / $width,
            $maxHeight / $height
        );

    return [
        max(
            1,
            (int) round(
                $width * $ratio
            )
        ),
        max(
            1,
            (int) round(
                $height * $ratio
            )
        ),
    ];
}


function house_store_hero_upload(
    array $file,
    int $houseId
): string {
    $validated =
        house_validate_hero_upload(
            $file
        );

    $directory =
        house_crest_storage_directory(
            $houseId
        );

    [
        $targetWidth,
        $targetHeight,
    ] =
        house_hero_target_dimensions(
            (int) $validated['width'],
            (int) $validated['height']
        );

    $baseName =
        'hero-'
        . bin2hex(
            random_bytes(18)
        );

    $extension =
        (string) $validated['extension'];

    $originalFilename =
        $baseName
        . '.'
        . $extension;

    $originalAbsolute =
        $directory['absolute']
        . '/'
        . $originalFilename;

    $originalRelative =
        '/'
        . $directory['relative']
        . '/'
        . $originalFilename;

    $sourceMime =
        (string) $validated['mime_type'];

    $format =
        match ($sourceMime) {
            'image/jpeg' => 'jpeg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            default      => '',
        };

    $needsResize =
        $targetWidth !== (int) $validated['width']
        || $targetHeight !== (int) $validated['height'];

    $originalWritten = false;

    if (
        $format !== ''
        && $needsResize
    ) {
        $originalWritten =
            house_crest_write_with_imagick(
                (string) $validated['tmp_name'],
                $originalAbsolute,
                $format,
                $targetWidth,
                $targetHeight
            );

        if (!$originalWritten) {
            $originalWritten =
                house_crest_write_with_gd(
                    (string) $validated['tmp_name'],
                    $sourceMime,
                    $originalAbsolute,
                    $sourceMime,
                    $targetWidth,
                    $targetHeight
                );
        }
    }

    if (!$originalWritten) {
        $originalWritten =
            copy(
                (string) $validated['tmp_name'],
                $originalAbsolute
            );
    }

    if (
        !$originalWritten
        || !is_file($originalAbsolute)
    ) {
        @unlink(
            $originalAbsolute
        );

        throw new RuntimeException(
            'The House hero image could not be saved.'
        );
    }

    if ($sourceMime !== 'image/webp') {
        $webpAbsolute =
            $directory['absolute']
            . '/'
            . $baseName
            . '.webp';

        $webpWritten =
            house_crest_write_with_imagick(
                $originalAbsolute,
                $webpAbsolute,
                'webp',
                $targetWidth,
                $targetHeight
            );

        if (!$webpWritten) {
            $webpWritten =
                house_crest_write_with_gd(
                    $originalAbsolute,
                    $sourceMime,
                    $webpAbsolute,
                    'image/webp',
                    $targetWidth,
                    $targetHeight
                );
        }

        if (!$webpWritten) {
            @unlink(
                $webpAbsolute
            );

            error_log(
                'Blackthorne House hero WebP generation unavailable for '
                . $originalRelative
            );
        }
    }

    return $originalRelative;
}


function house_delete_local_hero(
    ?string $reference
): void {
    $reference =
        house_safe_hero_reference(
            $reference
        );

    if ($reference === null) {
        return;
    }

    $originalAbsolute =
        BASE_PATH
        . $reference;

    $webpReference =
        preg_replace(
            '/\.[^.\/]+$/',
            '.webp',
            $reference
        );

    $paths = [
        $originalAbsolute,
    ];

    if (
        is_string($webpReference)
        && $webpReference !== $reference
    ) {
        $paths[] =
            BASE_PATH
            . $webpReference;
    }

    foreach (
        array_unique($paths)
        as $path
    ) {
        if (
            is_file($path)
            && str_starts_with(
                realpath(
                    dirname($path)
                ) ?: '',
                realpath(
                    BASE_PATH
                    . '/uploads/houses'
                ) ?: (
                    BASE_PATH
                    . '/uploads/houses'
                )
            )
        ) {
            @unlink($path);
        }
    }
}


/*
|--------------------------------------------------------------------------
| Crest Storage Directory
|--------------------------------------------------------------------------
*/

function house_crest_storage_directory(
    int $houseId
): array {
    if ($houseId <= 0) {
        throw new InvalidArgumentException(
            'A valid House ID is required.'
        );
    }

    $relative =
        'uploads/houses/'
        . $houseId;

    $absolute =
        BASE_PATH
        . '/'
        . $relative;

    if (
        !is_dir($absolute)
        && !mkdir(
            $absolute,
            0755,
            true
        )
        && !is_dir($absolute)
    ) {
        throw new RuntimeException(
            'The House crest directory could not be created.'
        );
    }

    return [
        'relative' => $relative,
        'absolute' => $absolute,
    ];
}


/*
|--------------------------------------------------------------------------
| Crest Validation
|--------------------------------------------------------------------------
*/

function house_validate_crest_upload(
    array $file
): array {
    $config = house_crest_config();

    $error =
        isset($file['error'])
        ? (int) $file['error']
        : UPLOAD_ERR_NO_FILE;

    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException(
            'Choose a House crest image to upload.'
        );
    }

    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException(
            'The House crest upload could not be completed.'
        );
    }

    $tmpName =
        isset($file['tmp_name'])
        ? (string) $file['tmp_name']
        : '';

    if (
        $tmpName === ''
        || !is_uploaded_file($tmpName)
    ) {
        throw new RuntimeException(
            'The House crest upload is invalid.'
        );
    }

    $size =
        isset($file['size'])
        ? (int) $file['size']
        : 0;

    if (
        $size <= 0
        || $size > (int) $config['max_bytes']
    ) {
        throw new RuntimeException(
            'House crests must be 5 MB or smaller.'
        );
    }

    $finfo =
        new finfo(
            FILEINFO_MIME_TYPE
        );

    $mimeType =
        (string) $finfo->file(
            $tmpName
        );

    $allowed =
        $config['allowed_mime_types'];

    if (!isset($allowed[$mimeType])) {
        throw new RuntimeException(
            'House crests must be JPG, PNG, WebP, or AVIF.'
        );
    }

    $imageInfo =
        @getimagesize(
            $tmpName
        );

    if (
        !is_array($imageInfo)
        || !isset(
            $imageInfo[0],
            $imageInfo[1]
        )
    ) {
        throw new RuntimeException(
            'The uploaded House crest is not a valid image.'
        );
    }

    $width =
        (int) $imageInfo[0];

    $height =
        (int) $imageInfo[1];

    if (
        $width <= 0
        || $height <= 0
    ) {
        throw new RuntimeException(
            'The uploaded House crest has invalid dimensions.'
        );
    }

    return [
        'tmp_name' =>
            $tmpName,

        'mime_type' =>
            $mimeType,

        'extension' =>
            (string) $allowed[$mimeType],

        'width' =>
            $width,

        'height' =>
            $height,
    ];
}


/*
|--------------------------------------------------------------------------
| Target Dimensions
|--------------------------------------------------------------------------
*/

function house_crest_target_dimensions(
    int $width,
    int $height
): array {
    $config = house_crest_config();

    $maxWidth =
        (int) $config['max_width'];

    $maxHeight =
        (int) $config['max_height'];

    if (
        $width <= $maxWidth
        && $height <= $maxHeight
    ) {
        return [
            $width,
            $height,
        ];
    }

    $scale =
        min(
            $maxWidth / $width,
            $maxHeight / $height
        );

    return [
        max(
            1,
            (int) floor(
                $width * $scale
            )
        ),
        max(
            1,
            (int) floor(
                $height * $scale
            )
        ),
    ];
}


/*
|--------------------------------------------------------------------------
| Image Processing
|--------------------------------------------------------------------------
*/

function house_crest_write_with_imagick(
    string $source,
    string $destination,
    string $outputFormat,
    int $width,
    int $height
): bool {
    if (!class_exists('Imagick')) {
        return false;
    }

    try {
        $image = new Imagick($source);

        if (
            method_exists(
                $image,
                'autoOrient'
            )
        ) {
            $image->autoOrient();
        }

        if (
            $image->getImageWidth() !== $width
            || $image->getImageHeight() !== $height
        ) {
            $image->thumbnailImage(
                $width,
                $height,
                true,
                true
            );
        }

        if (
            method_exists(
                $image,
                'stripImage'
            )
        ) {
            $image->stripImage();
        }

        $image->setImageFormat(
            $outputFormat
        );

        if ($outputFormat === 'webp') {
            $image->setImageCompressionQuality(82);
        }

        $result =
            $image->writeImage(
                $destination
            );

        $image->clear();
        $image->destroy();

        return
            $result
            && is_file($destination);

    } catch (Throwable $exception) {
        error_log(
            'Blackthorne House crest Imagick error: '
            . $exception->getMessage()
        );

        @unlink($destination);

        return false;
    }
}


function house_crest_gd_source(
    string $source,
    string $mimeType
) {
    return match ($mimeType) {
        'image/jpeg' =>
            function_exists('imagecreatefromjpeg')
                ? @imagecreatefromjpeg($source)
                : false,

        'image/png' =>
            function_exists('imagecreatefrompng')
                ? @imagecreatefrompng($source)
                : false,

        'image/webp' =>
            function_exists('imagecreatefromwebp')
                ? @imagecreatefromwebp($source)
                : false,

        'image/avif' =>
            function_exists('imagecreatefromavif')
                ? @imagecreatefromavif($source)
                : false,

        default =>
            false,
    };
}


function house_crest_write_with_gd(
    string $source,
    string $sourceMimeType,
    string $destination,
    string $outputMimeType,
    int $width,
    int $height
): bool {
    if (
        !function_exists(
            'imagecreatetruecolor'
        )
    ) {
        return false;
    }

    $sourceImage =
        house_crest_gd_source(
            $source,
            $sourceMimeType
        );

    if ($sourceImage === false) {
        return false;
    }

    $sourceWidth =
        imagesx($sourceImage);

    $sourceHeight =
        imagesy($sourceImage);

    $target =
        imagecreatetruecolor(
            $width,
            $height
        );

    if ($target === false) {
        imagedestroy($sourceImage);
        return false;
    }

    if (
        in_array(
            $outputMimeType,
            [
                'image/png',
                'image/webp',
                'image/avif',
            ],
            true
        )
    ) {
        imagealphablending(
            $target,
            false
        );

        imagesavealpha(
            $target,
            true
        );

        $transparent =
            imagecolorallocatealpha(
                $target,
                0,
                0,
                0,
                127
            );

        imagefill(
            $target,
            0,
            0,
            $transparent
        );
    }

    $resampled =
        imagecopyresampled(
            $target,
            $sourceImage,
            0,
            0,
            0,
            0,
            $width,
            $height,
            $sourceWidth,
            $sourceHeight
        );

    if (!$resampled) {
        imagedestroy($sourceImage);
        imagedestroy($target);
        return false;
    }

    $written =
        match ($outputMimeType) {
            'image/jpeg' =>
                function_exists('imagejpeg')
                    ? imagejpeg(
                        $target,
                        $destination,
                        88
                    )
                    : false,

            'image/png' =>
                function_exists('imagepng')
                    ? imagepng(
                        $target,
                        $destination,
                        6
                    )
                    : false,

            'image/webp' =>
                function_exists('imagewebp')
                    ? imagewebp(
                        $target,
                        $destination,
                        82
                    )
                    : false,

            'image/avif' =>
                function_exists('imageavif')
                    ? imageavif(
                        $target,
                        $destination,
                        82
                    )
                    : false,

            default =>
                false,
        };

    imagedestroy($sourceImage);
    imagedestroy($target);

    return
        $written
        && is_file($destination);
}


/*
|--------------------------------------------------------------------------
| Store Uploaded Crest
|--------------------------------------------------------------------------
*/

function house_store_crest_upload(
    array $file,
    int $houseId
): string {
    $validated =
        house_validate_crest_upload(
            $file
        );

    $directory =
        house_crest_storage_directory(
            $houseId
        );

    [
        $targetWidth,
        $targetHeight,
    ] =
        house_crest_target_dimensions(
            (int) $validated['width'],
            (int) $validated['height']
        );

    $baseName =
        bin2hex(
            random_bytes(18)
        );

    $extension =
        (string) $validated['extension'];

    $originalFilename =
        $baseName
        . '.'
        . $extension;

    $originalAbsolute =
        $directory['absolute']
        . '/'
        . $originalFilename;

    $originalRelative =
        '/'
        . $directory['relative']
        . '/'
        . $originalFilename;

    $sourceMime =
        (string) $validated['mime_type'];

    $format =
        match ($sourceMime) {
            'image/jpeg' => 'jpeg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            default      => '',
        };

    $needsResize =
        $targetWidth !== (int) $validated['width']
        || $targetHeight !== (int) $validated['height'];

    $originalWritten = false;

    if (
        $format !== ''
        && $needsResize
    ) {
        $originalWritten =
            house_crest_write_with_imagick(
                (string) $validated['tmp_name'],
                $originalAbsolute,
                $format,
                $targetWidth,
                $targetHeight
            );

        if (!$originalWritten) {
            $originalWritten =
                house_crest_write_with_gd(
                    (string) $validated['tmp_name'],
                    $sourceMime,
                    $originalAbsolute,
                    $sourceMime,
                    $targetWidth,
                    $targetHeight
                );
        }
    }

    if (!$originalWritten) {
        $originalWritten =
            copy(
                (string) $validated['tmp_name'],
                $originalAbsolute
            );
    }

    if (
        !$originalWritten
        || !is_file($originalAbsolute)
    ) {
        @unlink($originalAbsolute);

        throw new RuntimeException(
            'The House crest could not be saved.'
        );
    }

    if ($sourceMime !== 'image/webp') {
        $webpAbsolute =
            $directory['absolute']
            . '/'
            . $baseName
            . '.webp';

        $webpWritten =
            house_crest_write_with_imagick(
                $originalAbsolute,
                $webpAbsolute,
                'webp',
                $targetWidth,
                $targetHeight
            );

        if (!$webpWritten) {
            $webpWritten =
                house_crest_write_with_gd(
                    $originalAbsolute,
                    $sourceMime,
                    $webpAbsolute,
                    'image/webp',
                    $targetWidth,
                    $targetHeight
                );
        }

        if (!$webpWritten) {
            @unlink($webpAbsolute);

            error_log(
                'Blackthorne House crest WebP generation unavailable for '
                . $originalRelative
            );
        }
    }

    return $originalRelative;
}


/*
|--------------------------------------------------------------------------
| Remove Local Crest Files
|--------------------------------------------------------------------------
*/

function house_delete_local_crest(
    ?string $reference
): void {
    $reference =
        house_safe_crest_reference(
            $reference
        );

    if ($reference === null) {
        return;
    }

    $originalAbsolute =
        BASE_PATH
        . $reference;

    $webpReference =
        preg_replace(
            '/\.[^.\/]+$/',
            '.webp',
            $reference
        );

    $paths = [
        $originalAbsolute,
    ];

    if (
        is_string($webpReference)
        && $webpReference !== $reference
    ) {
        $paths[] =
            BASE_PATH
            . $webpReference;
    }

    foreach (
        array_unique($paths)
        as $path
    ) {
        if (
            is_string($path)
            && is_file($path)
        ) {
            @unlink($path);
        }
    }
}
