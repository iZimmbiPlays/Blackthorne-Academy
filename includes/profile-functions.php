<?php

declare(strict_types=1);

/**
 * Blackthorne Academy
 * Profile System Helpers
 *
 * Profile-image pipeline:
 *   - uploaded images are validated and stored locally
 *   - remote HTTPS images are imported locally after SSRF-safe validation
 *   - oversized images are resized while preserving aspect ratio
 *   - the original image format is retained as the fallback
 *   - a matching .webp derivative is generated when supported
 *
 * Database storage remains:
 *   users.avatar
 *   user_profiles.cover_image
 *
 * The database stores the ORIGINAL/FALLBACK path only.
 * The WebP path is derived automatically from that local path.
 */


/*
|--------------------------------------------------------------------------
| Profile Image Configuration
|--------------------------------------------------------------------------
*/

function profile_image_config(string $kind): array
{
    return match ($kind) {
        'avatar' => [
            'directory'  => 'avatars',
            'max_bytes'  => 5 * 1024 * 1024,
            'max_width'  => 800,
            'max_height' => 800,
            'label'      => 'avatar',
        ],

        'cover' => [
            'directory'  => 'covers',
            'max_bytes'  => 10 * 1024 * 1024,
            'max_width'  => 2400,
            'max_height' => 1200,
            'label'      => 'cover image',
        ],

        'bio' => [
            'directory'  => 'bio',
            'max_bytes'  => 8 * 1024 * 1024,
            'max_width'  => 1800,
            'max_height' => 1800,
            'label'      => 'bio image',
        ],

        default => throw new InvalidArgumentException(
            'Unsupported profile image type.'
        ),
    };
}


function profile_allowed_image_mime_types(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];
}


/*
|--------------------------------------------------------------------------
| External Image URL Validation
|--------------------------------------------------------------------------
*/

function profile_validate_external_image_url(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $value = trim($value);

    if ($value === '') {
        return null;
    }

    if (
        strlen($value) > 500
        || filter_var($value, FILTER_VALIDATE_URL) === false
    ) {
        throw new RuntimeException(
            'Enter a valid image URL.'
        );
    }

    $parts = parse_url($value);

    if (
        !is_array($parts)
        || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || trim((string) ($parts['host'] ?? '')) === ''
    ) {
        throw new RuntimeException(
            'Profile image links must use HTTPS.'
        );
    }

    if (
        isset($parts['user'])
        || isset($parts['pass'])
    ) {
        throw new RuntimeException(
            'Profile image links cannot contain login credentials.'
        );
    }

    $host = strtolower(
        rtrim(
            (string) $parts['host'],
            '.'
        )
    );

    if (
        $host === 'localhost'
        || str_ends_with($host, '.localhost')
        || str_ends_with($host, '.local')
        || str_ends_with($host, '.internal')
    ) {
        throw new RuntimeException(
            'That image host is not allowed.'
        );
    }

    return $value;
}


/*
|--------------------------------------------------------------------------
| SSRF Protection
|--------------------------------------------------------------------------
*/

function profile_ip_is_public(string $ip): bool
{
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE
        | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}


function profile_resolve_public_host_ips(string $host): array
{
    $host = strtolower(
        rtrim(
            trim($host),
            '.'
        )
    );

    if ($host === '') {
        throw new RuntimeException(
            'The image host could not be resolved.'
        );
    }

    /*
     * IP-literal hosts are valid only when they are public.
     */
    if (
        filter_var(
            $host,
            FILTER_VALIDATE_IP
        ) !== false
    ) {
        if (!profile_ip_is_public($host)) {
            throw new RuntimeException(
                'That image host is not allowed.'
            );
        }

        return [$host];
    }

    $ips = [];

    if (function_exists('dns_get_record')) {
        $records = @dns_get_record(
            $host,
            DNS_A | DNS_AAAA
        );

        if (is_array($records)) {
            foreach ($records as $record) {
                if (
                    isset($record['ip'])
                    && is_string($record['ip'])
                ) {
                    $ips[] = $record['ip'];
                }

                if (
                    isset($record['ipv6'])
                    && is_string($record['ipv6'])
                ) {
                    $ips[] = $record['ipv6'];
                }
            }
        }
    }

    /*
     * IPv4 fallback on hosts where dns_get_record() is unavailable or limited.
     */
    if ($ips === []) {
        $fallbackIps = @gethostbynamel($host);

        if (is_array($fallbackIps)) {
            $ips = [
                ...$ips,
                ...$fallbackIps,
            ];
        }
    }

    $ips = array_values(
        array_unique(
            array_filter(
                $ips,
                static fn ($ip): bool =>
                    is_string($ip)
                    && $ip !== ''
            )
        )
    );

    if ($ips === []) {
        throw new RuntimeException(
            'The image host could not be resolved.'
        );
    }

    foreach ($ips as $ip) {
        if (!profile_ip_is_public($ip)) {
            throw new RuntimeException(
                'That image host resolves to a private or restricted address.'
            );
        }
    }

    return $ips;
}


/*
|--------------------------------------------------------------------------
| Redirect URL Resolution
|--------------------------------------------------------------------------
*/

function profile_resolve_redirect_url(
    string $currentUrl,
    string $location
): string {
    $location = trim($location);

    if ($location === '') {
        throw new RuntimeException(
            'The remote image returned an invalid redirect.'
        );
    }

    if (
        filter_var(
            $location,
            FILTER_VALIDATE_URL
        ) !== false
    ) {
        return $location;
    }

    $current = parse_url($currentUrl);

    if (
        !is_array($current)
        || empty($current['scheme'])
        || empty($current['host'])
    ) {
        throw new RuntimeException(
            'The remote image redirect could not be resolved.'
        );
    }

    $scheme = (string) $current['scheme'];
    $host = (string) $current['host'];
    $port = isset($current['port'])
        ? ':' . (int) $current['port']
        : '';

    if (str_starts_with($location, '//')) {
        return $scheme . ':' . $location;
    }

    if (str_starts_with($location, '/')) {
        return
            $scheme
            . '://'
            . $host
            . $port
            . $location;
    }

    $path = (string) ($current['path'] ?? '/');
    $directory = preg_replace(
        '#/[^/]*$#',
        '/',
        $path
    );

    if (!is_string($directory)) {
        $directory = '/';
    }

    $combined =
        $directory
        . $location;

    $segments = [];

    foreach (
        explode('/', $combined)
        as $segment
    ) {
        if (
            $segment === ''
            || $segment === '.'
        ) {
            continue;
        }

        if ($segment === '..') {
            array_pop($segments);
            continue;
        }

        $segments[] = $segment;
    }

    return
        $scheme
        . '://'
        . $host
        . $port
        . '/'
        . implode('/', $segments);
}


/*
|--------------------------------------------------------------------------
| Safe Remote Download
|--------------------------------------------------------------------------
*/

function profile_download_remote_image(
    string $url,
    string $kind
): string {
    if (!function_exists('curl_init')) {
        throw new RuntimeException(
            'Remote profile images are not available on this server because cURL is not enabled.'
        );
    }

    $config = profile_image_config($kind);

    $currentUrl =
        profile_validate_external_image_url(
            $url
        );

    if ($currentUrl === null) {
        throw new RuntimeException(
            'Enter a valid image URL.'
        );
    }

    $maxRedirects = 3;

    for (
        $redirectCount = 0;
        $redirectCount <= $maxRedirects;
        $redirectCount++
    ) {
        $parts =
            parse_url(
                $currentUrl
            );

        if (
            !is_array($parts)
            || empty($parts['host'])
        ) {
            throw new RuntimeException(
                'The image URL could not be read.'
            );
        }

        $host =
            (string) $parts['host'];

        $ips =
            profile_resolve_public_host_ips(
                $host
            );

        /*
         * Prefer IPv4 for the CURLOPT_RESOLVE pin when available because the
         * syntax is universally supported by older libcurl builds.
         */
        $selectedIp =
            $ips[0];

        foreach ($ips as $candidateIp) {
            if (
                filter_var(
                    $candidateIp,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_IPV4
                ) !== false
            ) {
                $selectedIp = $candidateIp;
                break;
            }
        }

        $tempPath =
            tempnam(
                sys_get_temp_dir(),
                'blackthorne_profile_'
            );

        if ($tempPath === false) {
            throw new RuntimeException(
                'A temporary image file could not be created.'
            );
        }

        $handle =
            fopen(
                $tempPath,
                'wb'
            );

        if ($handle === false) {
            @unlink($tempPath);

            throw new RuntimeException(
                'A temporary image file could not be opened.'
            );
        }

        $downloadedBytes = 0;
        $responseHeaders = [];

        $curl =
            curl_init();

        if ($curl === false) {
            fclose($handle);
            @unlink($tempPath);

            throw new RuntimeException(
                'The remote image request could not be started.'
            );
        }

        $port =
            isset($parts['port'])
                ? (int) $parts['port']
                : 443;

        $resolveIp =
            str_contains(
                $selectedIp,
                ':'
            )
                ? '[' . $selectedIp . ']'
                : $selectedIp;

        curl_setopt_array(
            $curl,
            [
                CURLOPT_URL =>
                    $currentUrl,

                CURLOPT_FOLLOWLOCATION =>
                    false,

                CURLOPT_PROTOCOLS =>
                    CURLPROTO_HTTPS,

                CURLOPT_CONNECTTIMEOUT =>
                    5,

                CURLOPT_TIMEOUT =>
                    15,

                CURLOPT_USERAGENT =>
                    'BlackthorneAcademy-ProfileImageImporter/1.0',

                CURLOPT_HTTPHEADER => [
                    'Accept: image/avif,image/webp,image/png,image/jpeg;q=0.9,*/*;q=0.5',
                ],

                CURLOPT_RESOLVE => [
                    $host
                    . ':'
                    . $port
                    . ':'
                    . $resolveIp,
                ],

                CURLOPT_HEADERFUNCTION =>
                    static function (
                        $curlHandle,
                        string $headerLine
                    ) use (
                        &$responseHeaders
                    ): int {
                        $length =
                            strlen(
                                $headerLine
                            );

                        $trimmed =
                            trim(
                                $headerLine
                            );

                        if (
                            $trimmed !== ''
                            && str_contains(
                                $trimmed,
                                ':'
                            )
                        ) {
                            [
                                $name,
                                $value,
                            ] =
                                explode(
                                    ':',
                                    $trimmed,
                                    2
                                );

                            $responseHeaders[
                                strtolower(
                                    trim(
                                        $name
                                    )
                                )
                            ] =
                                trim(
                                    $value
                                );
                        }

                        return $length;
                    },

                CURLOPT_WRITEFUNCTION =>
                    static function (
                        $curlHandle,
                        string $data
                    ) use (
                        $handle,
                        &$downloadedBytes,
                        $config
                    ): int {
                        $length =
                            strlen(
                                $data
                            );

                        $downloadedBytes +=
                            $length;

                        if (
                            $downloadedBytes
                            > (int) $config['max_bytes']
                        ) {
                            return 0;
                        }

                        $written =
                            fwrite(
                                $handle,
                                $data
                            );

                        return
                            $written === false
                                ? 0
                                : $written;
                    },
            ]
        );

        $success =
            curl_exec(
                $curl
            );

        $httpCode =
            (int) curl_getinfo(
                $curl,
                CURLINFO_RESPONSE_CODE
            );

        $curlError =
            curl_error(
                $curl
            );

        curl_close(
            $curl
        );

        fclose(
            $handle
        );

        if (
            $downloadedBytes
            > (int) $config['max_bytes']
        ) {
            @unlink($tempPath);

            $maxMb =
                (int) ceil(
                    (int) $config['max_bytes']
                    / 1024
                    / 1024
                );

            throw new RuntimeException(
                'The remote '
                . $config['label']
                . ' is larger than '
                . $maxMb
                . ' MB.'
            );
        }

        if (
            $httpCode >= 300
            && $httpCode < 400
        ) {
            $location =
                $responseHeaders['location']
                ?? '';

            @unlink(
                $tempPath
            );

            if (
                $redirectCount
                >= $maxRedirects
            ) {
                throw new RuntimeException(
                    'The remote image redirected too many times.'
                );
            }

            $nextUrl =
                profile_resolve_redirect_url(
                    $currentUrl,
                    $location
                );

            $currentUrl =
                profile_validate_external_image_url(
                    $nextUrl
                );

            if ($currentUrl === null) {
                throw new RuntimeException(
                    'The remote image redirect was invalid.'
                );
            }

            continue;
        }

        if (
            $success === false
            || $httpCode < 200
            || $httpCode >= 300
        ) {
            @unlink(
                $tempPath
            );

            error_log(
                'Blackthorne remote profile image download error: '
                . $curlError
                . ' HTTP '
                . $httpCode
            );

            throw new RuntimeException(
                'The remote image could not be downloaded.'
            );
        }

        if (
            !is_file(
                $tempPath
            )
            || filesize(
                $tempPath
            ) <= 0
        ) {
            @unlink(
                $tempPath
            );

            throw new RuntimeException(
                'The remote image was empty.'
            );
        }

        return $tempPath;
    }

    throw new RuntimeException(
        'The remote image could not be downloaded.'
    );
}


/*
|--------------------------------------------------------------------------
| Source Image Validation
|--------------------------------------------------------------------------
*/

function profile_validate_image_source(
    string $path,
    string $kind
): array {
    $config =
        profile_image_config(
            $kind
        );

    if (
        $path === ''
        || !is_file(
            $path
        )
    ) {
        throw new RuntimeException(
            'The image file could not be found.'
        );
    }

    $size =
        (int) filesize(
            $path
        );

    if (
        $size <= 0
        || $size > (int) $config['max_bytes']
    ) {
        $maxMb =
            (int) ceil(
                (int) $config['max_bytes']
                / 1024
                / 1024
            );

        throw new RuntimeException(
            'The '
            . $config['label']
            . ' must be '
            . $maxMb
            . ' MB or smaller.'
        );
    }

    $finfo =
        new finfo(
            FILEINFO_MIME_TYPE
        );

    $mimeType =
        (string) $finfo->file(
            $path
        );

    $allowedTypes =
        profile_allowed_image_mime_types();

    if (
        !isset(
            $allowedTypes[$mimeType]
        )
    ) {
        throw new RuntimeException(
            'Use a JPG, PNG, WebP, or AVIF image.'
        );
    }

    $imageInfo =
        @getimagesize(
            $path
        );

    if ($imageInfo === false) {
        throw new RuntimeException(
            'The uploaded file is not a valid image.'
        );
    }

    $width =
        (int) (
            $imageInfo[0]
            ?? 0
        );

    $height =
        (int) (
            $imageInfo[1]
            ?? 0
        );

    if (
        $width <= 0
        || $height <= 0
    ) {
        throw new RuntimeException(
            'The image dimensions could not be read.'
        );
    }

    /*
     * Prevent decompression-bomb style images with absurd pixel counts.
     * 80 million pixels still permits very large legitimate photographs.
     */
    if (
        ($width * $height)
        > 80000000
    ) {
        throw new RuntimeException(
            'The image dimensions are too large.'
        );
    }

    return [
        'size'      => $size,
        'mime_type' => $mimeType,
        'extension' => $allowedTypes[$mimeType],
        'width'     => $width,
        'height'    => $height,
    ];
}


function profile_validate_image_upload(
    array $file,
    string $kind
): array {
    $config =
        profile_image_config(
            $kind
        );

    $error =
        (int) (
            $file['error']
            ?? UPLOAD_ERR_NO_FILE
        );

    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException(
            'No '
            . $config['label']
            . ' was selected.'
        );
    }

    if ($error !== UPLOAD_ERR_OK) {
        $message =
            match ($error) {
                UPLOAD_ERR_INI_SIZE,
                UPLOAD_ERR_FORM_SIZE =>
                    'The selected image is too large.',

                UPLOAD_ERR_PARTIAL =>
                    'The image upload did not finish. Please try again.',

                default =>
                    'The image could not be uploaded. Please try again.',
            };

        throw new RuntimeException(
            $message
        );
    }

    $tmpName =
        (string) (
            $file['tmp_name']
            ?? ''
        );

    if (
        $tmpName === ''
        || !is_uploaded_file(
            $tmpName
        )
    ) {
        throw new RuntimeException(
            'The uploaded image could not be verified.'
        );
    }

    $validated =
        profile_validate_image_source(
            $tmpName,
            $kind
        );

    $validated['tmp_name'] =
        $tmpName;

    return $validated;
}


/*
|--------------------------------------------------------------------------
| Resize Calculation
|--------------------------------------------------------------------------
*/

function profile_target_dimensions(
    int $width,
    int $height,
    int $maxWidth,
    int $maxHeight
): array {
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
                $width
                * $scale
            )
        ),

        max(
            1,
            (int) floor(
                $height
                * $scale
            )
        ),
    ];
}


/*
|--------------------------------------------------------------------------
| Imagick Processing
|--------------------------------------------------------------------------
*/

function profile_imagick_available(): bool
{
    return class_exists(
        'Imagick'
    );
}


function profile_process_with_imagick(
    string $sourcePath,
    string $destinationPath,
    string $destinationFormat,
    int $targetWidth,
    int $targetHeight
): bool {
    if (!profile_imagick_available()) {
        return false;
    }

    try {
        $image =
            new Imagick(
                $sourcePath
            );

        /*
         * Remove profiles/metadata, including EXIF GPS information.
         */
        $image->stripImage();

        $image->setImageOrientation(
            Imagick::ORIENTATION_TOPLEFT
        );

        if (
            $image->getImageWidth() !== $targetWidth
            || $image->getImageHeight() !== $targetHeight
        ) {
            $image->resizeImage(
                $targetWidth,
                $targetHeight,
                Imagick::FILTER_LANCZOS,
                1,
                false
            );
        }

        $image->setImageFormat(
            $destinationFormat
        );

        if ($destinationFormat === 'webp') {
            $image->setImageCompressionQuality(
                82
            );

        } elseif (
            $destinationFormat === 'jpeg'
            || $destinationFormat === 'jpg'
        ) {
            $image->setImageCompressionQuality(
                88
            );

        } elseif ($destinationFormat === 'avif') {
            $image->setImageCompressionQuality(
                82
            );
        }

        $written =
            $image->writeImage(
                $destinationPath
            );

        $image->clear();
        $image->destroy();

        return
            $written
            && is_file(
                $destinationPath
            )
            && filesize(
                $destinationPath
            ) > 0;

    } catch (Throwable $exception) {
        error_log(
            'Blackthorne Imagick profile image processing error: '
            . $exception->getMessage()
        );

        @unlink(
            $destinationPath
        );

        return false;
    }
}


/*
|--------------------------------------------------------------------------
| GD Processing
|--------------------------------------------------------------------------
*/

function profile_gd_source_image(
    string $sourcePath,
    string $mimeType
) {
    return match ($mimeType) {
        'image/jpeg' =>
            function_exists('imagecreatefromjpeg')
                ? @imagecreatefromjpeg($sourcePath)
                : false,

        'image/png' =>
            function_exists('imagecreatefrompng')
                ? @imagecreatefrompng($sourcePath)
                : false,

        'image/webp' =>
            function_exists('imagecreatefromwebp')
                ? @imagecreatefromwebp($sourcePath)
                : false,

        'image/avif' =>
            function_exists('imagecreatefromavif')
                ? @imagecreatefromavif($sourcePath)
                : false,

        default =>
            false,
    };
}


function profile_gd_write_image(
    $image,
    string $destinationPath,
    string $mimeType
): bool {
    return match ($mimeType) {
        'image/jpeg' =>
            function_exists('imagejpeg')
                ? imagejpeg(
                    $image,
                    $destinationPath,
                    88
                )
                : false,

        'image/png' =>
            function_exists('imagepng')
                ? imagepng(
                    $image,
                    $destinationPath,
                    6
                )
                : false,

        'image/webp' =>
            function_exists('imagewebp')
                ? imagewebp(
                    $image,
                    $destinationPath,
                    82
                )
                : false,

        'image/avif' =>
            function_exists('imageavif')
                ? imageavif(
                    $image,
                    $destinationPath,
                    82
                )
                : false,

        default =>
            false,
    };
}


function profile_process_with_gd(
    string $sourcePath,
    string $sourceMimeType,
    string $destinationPath,
    string $destinationMimeType,
    int $targetWidth,
    int $targetHeight
): bool {
    if (
        !function_exists(
            'imagecreatetruecolor'
        )
    ) {
        return false;
    }

    $sourceImage =
        profile_gd_source_image(
            $sourcePath,
            $sourceMimeType
        );

    if ($sourceImage === false) {
        return false;
    }

    $sourceWidth =
        imagesx(
            $sourceImage
        );

    $sourceHeight =
        imagesy(
            $sourceImage
        );

    $destinationImage =
        imagecreatetruecolor(
            $targetWidth,
            $targetHeight
        );

    if ($destinationImage === false) {
        imagedestroy(
            $sourceImage
        );

        return false;
    }

    /*
     * Preserve alpha where applicable.
     */
    if (
        in_array(
            $destinationMimeType,
            [
                'image/png',
                'image/webp',
                'image/avif',
            ],
            true
        )
    ) {
        imagealphablending(
            $destinationImage,
            false
        );

        imagesavealpha(
            $destinationImage,
            true
        );

        $transparent =
            imagecolorallocatealpha(
                $destinationImage,
                0,
                0,
                0,
                127
            );

        imagefill(
            $destinationImage,
            0,
            0,
            $transparent
        );
    }

    $resampled =
        imagecopyresampled(
            $destinationImage,
            $sourceImage,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight
        );

    $written = false;

    if ($resampled) {
        $written =
            profile_gd_write_image(
                $destinationImage,
                $destinationPath,
                $destinationMimeType
            );
    }

    imagedestroy(
        $destinationImage
    );

    imagedestroy(
        $sourceImage
    );

    if (!$written) {
        @unlink(
            $destinationPath
        );

        return false;
    }

    return
        is_file(
            $destinationPath
        )
        && filesize(
            $destinationPath
        ) > 0;
}


/*
|--------------------------------------------------------------------------
| Generic Local Processing
|--------------------------------------------------------------------------
*/

function profile_format_from_mime(
    string $mimeType
): string {
    return match ($mimeType) {
        'image/jpeg' => 'jpeg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        default      => '',
    };
}


function profile_write_processed_image(
    string $sourcePath,
    string $sourceMimeType,
    string $destinationPath,
    string $destinationMimeType,
    int $targetWidth,
    int $targetHeight
): bool {
    $destinationFormat =
        profile_format_from_mime(
            $destinationMimeType
        );

    if (
        $destinationFormat !== ''
        && profile_process_with_imagick(
            $sourcePath,
            $destinationPath,
            $destinationFormat,
            $targetWidth,
            $targetHeight
        )
    ) {
        return true;
    }

    return
        profile_process_with_gd(
            $sourcePath,
            $sourceMimeType,
            $destinationPath,
            $destinationMimeType,
            $targetWidth,
            $targetHeight
        );
}


/*
|--------------------------------------------------------------------------
| Storage Helpers
|--------------------------------------------------------------------------
*/

function profile_image_storage_directory(
    int $userId,
    string $kind
): array {
    if ($userId <= 0) {
        throw new InvalidArgumentException(
            'A valid user ID is required.'
        );
    }

    $config =
        profile_image_config(
            $kind
        );

    $relativeDirectory =
        'uploads/profile/'
        . $config['directory']
        . '/'
        . $userId;

    $absoluteDirectory =
        dirname(__DIR__)
        . '/'
        . $relativeDirectory;

    if (
        !is_dir(
            $absoluteDirectory
        )
        && !mkdir(
            $absoluteDirectory,
            0755,
            true
        )
        && !is_dir(
            $absoluteDirectory
        )
    ) {
        throw new RuntimeException(
            'The profile image directory could not be created.'
        );
    }

    return [
        'relative' =>
            $relativeDirectory,

        'absolute' =>
            $absoluteDirectory,
    ];
}


/*
|--------------------------------------------------------------------------
| Store Validated Source + WebP Derivative
|--------------------------------------------------------------------------
*/

function profile_store_image_source(
    string $sourcePath,
    int $userId,
    string $kind
): string {
    $validated =
        profile_validate_image_source(
            $sourcePath,
            $kind
        );

    $config =
        profile_image_config(
            $kind
        );

    $directory =
        profile_image_storage_directory(
            $userId,
            $kind
        );

    [
        $targetWidth,
        $targetHeight,
    ] =
        profile_target_dimensions(
            (int) $validated['width'],
            (int) $validated['height'],
            (int) $config['max_width'],
            (int) $config['max_height']
        );

    $baseName =
        bin2hex(
            random_bytes(18)
        );

    $originalFilename =
        $baseName
        . '.'
        . $validated['extension'];

    $originalAbsolutePath =
        $directory['absolute']
        . '/'
        . $originalFilename;

    $originalRelativePath =
        '/'
        . $directory['relative']
        . '/'
        . $originalFilename;


    /*
     * Preserve the source format as the fallback.
     *
     * When resizing is needed, prefer re-encoding through Imagick/GD so the
     * dimensions are normalized and metadata is removed. When the server
     * cannot process that source format, safely copy the already-validated
     * original instead of breaking the member's upload.
     */
    $needsResize =
        $targetWidth !== (int) $validated['width']
        || $targetHeight !== (int) $validated['height'];

    $wroteOriginal =
        false;

    if ($needsResize) {
        $wroteOriginal =
            profile_write_processed_image(
                $sourcePath,
                (string) $validated['mime_type'],
                $originalAbsolutePath,
                (string) $validated['mime_type'],
                $targetWidth,
                $targetHeight
            );
    }

    if (!$wroteOriginal) {
        $wroteOriginal =
            copy(
                $sourcePath,
                $originalAbsolutePath
            );
    }

    if (
        !$wroteOriginal
        || !is_file(
            $originalAbsolutePath
        )
    ) {
        @unlink(
            $originalAbsolutePath
        );

        throw new RuntimeException(
            'The profile image could not be saved.'
        );
    }


    /*
     * Create the WebP sibling using the normalized target dimensions.
     *
     * Failure is graceful. The original remains fully usable as the fallback.
     */
    $webpAbsolutePath =
        $directory['absolute']
        . '/'
        . $baseName
        . '.webp';

    if (
        (string) $validated['mime_type']
        === 'image/webp'
    ) {
        /*
         * The fallback itself is already WebP. No duplicate sibling is needed.
         */
        $webpAbsolutePath =
            $originalAbsolutePath;

    } else {
        $webpCreated =
            profile_write_processed_image(
                $originalAbsolutePath,
                (string) $validated['mime_type'],
                $webpAbsolutePath,
                'image/webp',
                $targetWidth,
                $targetHeight
            );

        if (!$webpCreated) {
            @unlink(
                $webpAbsolutePath
            );

            error_log(
                'Blackthorne profile image WebP generation unavailable for '
                . $originalRelativePath
            );
        }
    }

    return
        $originalRelativePath;
}


/*
|--------------------------------------------------------------------------
| Uploaded Images
|--------------------------------------------------------------------------
*/

function profile_store_image_upload(
    array $file,
    int $userId,
    string $kind
): string {
    $validated =
        profile_validate_image_upload(
            $file,
            $kind
        );

    return
        profile_store_image_source(
            (string) $validated['tmp_name'],
            $userId,
            $kind
        );
}


/*
|--------------------------------------------------------------------------
| Remote Image Import
|--------------------------------------------------------------------------
*/

function profile_import_external_image(
    string $url,
    int $userId,
    string $kind
): string {
    $validatedUrl =
        profile_validate_external_image_url(
            $url
        );

    if ($validatedUrl === null) {
        throw new RuntimeException(
            'Enter a valid image URL.'
        );
    }

    $tempPath =
        profile_download_remote_image(
            $validatedUrl,
            $kind
        );

    try {
        return
            profile_store_image_source(
                $tempPath,
                $userId,
                $kind
            );

    } finally {
        if (
            is_file(
                $tempPath
            )
        ) {
            @unlink(
                $tempPath
            );
        }
    }
}


/*
|--------------------------------------------------------------------------
| WebP Derivative
|--------------------------------------------------------------------------
*/

function profile_webp_reference(
    ?string $originalReference
): ?string {
    $originalReference =
        profile_safe_image_reference(
            $originalReference
        );

    if (
        $originalReference === null
        || !str_starts_with(
            $originalReference,
            '/'
        )
    ) {
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
        return
            $originalReference;
    }

    $webpReference =
        preg_replace(
            '/\.[^.\/]+$/',
            '.webp',
            $originalReference
        );

    if (
        !is_string(
            $webpReference
        )
        || $webpReference === $originalReference
    ) {
        return null;
    }

    $absolutePath =
        dirname(__DIR__)
        . $webpReference;

    if (
        !is_file(
            $absolutePath
        )
        || filesize(
            $absolutePath
        ) <= 0
    ) {
        return null;
    }

    return
        $webpReference;
}


function profile_picture_sources(
    ?string $originalReference
): array {
    $original =
        profile_safe_image_reference(
            $originalReference
        );

    return [
        'original' =>
            $original,

        'webp' =>
            profile_webp_reference(
                $original
            ),
    ];
}


/*
|--------------------------------------------------------------------------
| Local Image Cleanup
|--------------------------------------------------------------------------
|
| Deletes both the fallback image and its generated WebP sibling.
| Only Blackthorne's dedicated profile-upload directories may be touched.
|
*/

function profile_delete_local_image(
    ?string $imageReference,
    int $userId,
    string $kind
): void {
    if (
        $imageReference === null
        || $userId <= 0
    ) {
        return;
    }

    $imageReference =
        trim(
            $imageReference
        );

    if (
        $imageReference === ''
        || preg_match(
            '#^https://#i',
            $imageReference
        ) === 1
    ) {
        return;
    }

    $config =
        profile_image_config(
            $kind
        );

    $expectedPrefix =
        '/uploads/profile/'
        . $config['directory']
        . '/'
        . $userId
        . '/';

    if (
        !str_starts_with(
            $imageReference,
            $expectedPrefix
        )
    ) {
        return;
    }

    $filename =
        basename(
            $imageReference
        );

    if (
        $filename === ''
        || $filename === '.'
        || $filename === '..'
    ) {
        return;
    }

    $absolutePath =
        dirname(__DIR__)
        . $expectedPrefix
        . $filename;

    $webpReference =
        preg_replace(
            '/\.[^.\/]+$/',
            '.webp',
            $imageReference
        );

    $webpAbsolutePath =
        is_string(
            $webpReference
        )
            ? dirname(__DIR__)
                . $webpReference
            : null;

    if (
        is_file(
            $absolutePath
        )
    ) {
        @unlink(
            $absolutePath
        );
    }

    if (
        $webpAbsolutePath !== null
        && $webpAbsolutePath !== $absolutePath
        && is_file(
            $webpAbsolutePath
        )
    ) {
        @unlink(
            $webpAbsolutePath
        );
    }
}


/*
|--------------------------------------------------------------------------
| Safe Public Image Reference
|--------------------------------------------------------------------------
*/

function profile_safe_image_reference(
    ?string $value
): ?string {
    if ($value === null) {
        return null;
    }

    $value =
        trim(
            $value
        );

    if ($value === '') {
        return null;
    }

    if (
        str_starts_with(
            $value,
            '/'
        )
        && !str_starts_with(
            $value,
            '//'
        )
    ) {
        return $value;
    }

    try {
        return
            profile_validate_external_image_url(
                $value
            );

    } catch (RuntimeException) {
        return null;
    }
}


/*
|--------------------------------------------------------------------------
| Profile Image Defaults
|--------------------------------------------------------------------------
*/

function profile_avatar_initial(
    string $displayName,
    string $username = ''
): string {
    $source =
        trim(
            $displayName
        );

    if ($source === '') {
        $source =
            trim(
                $username
            );
    }

    if ($source === '') {
        return '?';
    }

    if (
        function_exists(
            'mb_substr'
        )
    ) {
        return
            mb_strtoupper(
                mb_substr(
                    $source,
                    0,
                    1,
                    'UTF-8'
                ),
                'UTF-8'
            );
    }

    return
        strtoupper(
            substr(
                $source,
                0,
                1
            )
        );
}
