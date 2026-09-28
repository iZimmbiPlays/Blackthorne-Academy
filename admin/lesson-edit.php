<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';


/*
|--------------------------------------------------------------------------
| Authentication / Permission
|--------------------------------------------------------------------------
*/

require_login();
require_active_account();

$isProtectedSuperAdmin =
    current_user_is_superuser();

$hasStaffIdentity =
    $isProtectedSuperAdmin
    || current_user_is_admin()
    || current_user_is_staff();

$canEditLessons =
    $isProtectedSuperAdmin
    || user_can('lessons.edit');

$canPublishLessons =
    $isProtectedSuperAdmin
    || user_can('lessons.publish');

$canManageRelease =
    $isProtectedSuperAdmin
    || user_can('lessons.release.manage');

if (
    !$hasStaffIdentity
    || !$canEditLessons
) {
    http_response_code(403);

    $pageTitle =
        'Access Denied | Blackthorne Academy';

    $pageDescription =
        'You do not have permission to edit lessons.';

    $pageCanonical =
        url('admin/lessons.php');

    $robots =
        'noindex, nofollow';

    require INCLUDES_PATH . '/header.php';
    ?>

    <main id="main-content" class="forum-board-page">
        <section class="forum-board-error">
            <div class="section-inner">

                <p class="academy-overline">
                    Restricted Staff Area
                </p>

                <h1>
                    Access Denied
                </h1>

                <p>
                    Your account does not have permission to edit lessons.
                </p>

                <a
                    class="button button-secondary"
                    href="<?= e(url('admin/lessons.php')); ?>"
                >
                    Return to Lessons
                </a>

            </div>
        </section>
    </main>

    <?php
    require INCLUDES_PATH . '/footer.php';
    exit;
}

$currentUserId =
    (int) (
        current_user_id()
        ?? 0
    );


/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function lesson_edit_post_string(
    string $key
): string {
    $value =
        $_POST[$key]
        ?? '';

    return is_scalar($value)
        ? trim((string) $value)
        : '';
}


function lesson_edit_post_id(
    string $key
): int {
    $value =
        $_POST[$key]
        ?? '';

    if (
        !is_scalar($value)
        || !ctype_digit(
            (string) $value
        )
    ) {
        return 0;
    }

    return max(
        0,
        (int) $value
    );
}


function lesson_edit_post_uint(
    string $key,
    int $default = 0
): int {
    $value =
        lesson_edit_post_string($key);

    if (
        $value === ''
        || !ctype_digit($value)
    ) {
        return $default;
    }

    return max(
        0,
        (int) $value
    );
}


function lesson_edit_normalize_datetime(
    string $value
): ?string {
    $value =
        trim($value);

    if ($value === '') {
        return null;
    }

    $date =
        DateTime::createFromFormat(
            'Y-m-d\TH:i',
            $value
        );

    if (!$date) {
        return null;
    }

    return $date->format(
        'Y-m-d H:i:s'
    );
}


function lesson_edit_form_datetime(
    ?string $value
): string {
    $value =
        trim(
            (string) $value
        );

    if ($value === '') {
        return '';
    }

    $timestamp =
        strtotime($value);

    if ($timestamp === false) {
        return '';
    }

    return date(
        'Y-m-d\TH:i',
        $timestamp
    );
}


function lesson_edit_format_datetime(
    ?string $value
): string {
    $value =
        trim(
            (string) $value
        );

    if ($value === '') {
        return '—';
    }

    $timestamp =
        strtotime($value);

    if ($timestamp === false) {
        return $value;
    }

    return date(
        'M j, Y g:i A',
        $timestamp
    );
}


function lesson_edit_release_label(
    array $row
): string {
    $type =
        (string) (
            $row['release_type']
            ?? 'immediate'
        );

    if ($type === 'days_after_course_start') {
        return
            'Day '
            . number_format(
                (int) (
                    $row['release_delay_days']
                    ?? 0
                )
            )
            . ' after course start';
    }

    if ($type === 'fixed_date') {
        return
            'Fixed date: '
            . lesson_edit_format_datetime(
                $row['release_at']
                ?? null
            );
    }

    if ($type === 'after_previous_lesson') {
        $title =
            trim(
                (string) (
                    $row[
                        'prerequisite_lesson_title'
                    ]
                    ?? ''
                )
            );

        return $title !== ''
            ? 'After: ' . $title
            : 'After previous lesson';
    }

    if ($type === 'days_after_enrollment') {
        return
            'Legacy enrollment-based release';
    }

    return 'Immediate';
}


function lesson_edit_audit(
    PDO $pdo,
    int $actorUserId,
    int $lessonId,
    string $actionType,
    string $description
): void {
    if ($actorUserId <= 0) {
        return;
    }

    try {
        $statement =
            $pdo->prepare(
                '
                INSERT INTO audit_log (
                    user_id,
                    action_type,
                    entity_type,
                    entity_id,
                    description,
                    ip_address,
                    user_agent
                ) VALUES (
                    :user_id,
                    :action_type,
                    :entity_type,
                    :entity_id,
                    :description,
                    :ip_address,
                    :user_agent
                )
                '
            );

        $statement->execute([
            'user_id' =>
                $actorUserId,

            'action_type' =>
                $actionType,

            'entity_type' =>
                'lesson',

            'entity_id' =>
                $lessonId,

            'description' =>
                $description,

            'ip_address' =>
                $_SERVER['REMOTE_ADDR']
                ?? null,

            'user_agent' =>
                substr(
                    (string) (
                        $_SERVER[
                            'HTTP_USER_AGENT'
                        ]
                        ?? ''
                    ),
                    0,
                    500
                ),
        ]);
    } catch (Throwable $exception) {
        error_log(
            'Lesson edit audit error: '
            . $exception->getMessage()
        );
    }
}


/*
|--------------------------------------------------------------------------
| Identify Offering / Lesson
|--------------------------------------------------------------------------
*/

$offeringId =
    filter_input(
        INPUT_GET,
        'offering',
        FILTER_VALIDATE_INT
    );

$lessonId =
    filter_input(
        INPUT_GET,
        'lesson',
        FILTER_VALIDATE_INT
    );

if (
    !is_int($offeringId)
    || $offeringId <= 0
) {
    $offeringId = 0;
}

if (
    !is_int($lessonId)
    || $lessonId <= 0
) {
    $lessonId = 0;
}

if (
    $offeringId <= 0
    || $lessonId <= 0
) {
    set_flash(
        'error',
        'Choose a valid lesson to edit.'
    );

    redirect(
        url('admin/lessons.php')
    );
}



function lesson_edit_normalize_upload_files(
    array $files
): array {
    if (
        !isset($files['name'])
        || !is_array($files['name'])
    ) {
        return [];
    }

    $normalized = [];

    foreach ($files['name'] as $index => $name) {
        $normalized[] = [
            'name' =>
                (string) $name,

            'tmp_name' =>
                (string) (
                    $files['tmp_name'][$index]
                    ?? ''
                ),

            'error' =>
                (int) (
                    $files['error'][$index]
                    ?? UPLOAD_ERR_NO_FILE
                ),

            'size' =>
                (int) (
                    $files['size'][$index]
                    ?? 0
                ),
        ];
    }

    return $normalized;
}


function lesson_edit_validate_image_upload(
    array $file,
    int $maxBytes = 10485760
): array {
    $error =
        (int) (
            $file['error']
            ?? UPLOAD_ERR_NO_FILE
        );

    if ($error === UPLOAD_ERR_NO_FILE) {
        return [
            'ok' => true,
            'empty' => true,
        ];
    }

    if ($error !== UPLOAD_ERR_OK) {
        return [
            'ok' => false,
            'empty' => false,
            'error' =>
                'The selected image could not be uploaded.',
        ];
    }

    $size =
        (int) (
            $file['size']
            ?? 0
        );

    if (
        $size < 1
        || $size > $maxBytes
    ) {
        return [
            'ok' => false,
            'empty' => false,
            'error' =>
                'Lesson images must be 10 MB or smaller.',
        ];
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
        return [
            'ok' => false,
            'empty' => false,
            'error' =>
                'The selected image was not received as a valid upload.',
        ];
    }

    $finfo =
        new finfo(
            FILEINFO_MIME_TYPE
        );

    $mime =
        (string) $finfo->file(
            $tmpName
        );

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];

    if (
        !array_key_exists(
            $mime,
            $allowed
        )
        || @getimagesize(
            $tmpName
        ) === false
    ) {
        return [
            'ok' => false,
            'empty' => false,
            'error' =>
                'Lesson images must be JPG, PNG, GIF, WEBP, or AVIF files.',
        ];
    }

    return [
        'ok' => true,
        'empty' => false,
        'name' =>
            mb_substr(
                basename(
                    (string) (
                        $file['name']
                        ?? 'image'
                    )
                ),
                0,
                255,
                'UTF-8'
            ),
        'tmp_name' =>
            $tmpName,
        'size' =>
            $size,
        'mime' =>
            $mime,
        'extension' =>
            $allowed[$mime],
    ];
}


function lesson_edit_generate_webp(
    string $absolutePath,
    string $mime
): ?string {
    if ($mime === 'image/webp') {
        return $absolutePath;
    }

    if (
        !extension_loaded('gd')
        || !function_exists('imagewebp')
    ) {
        return null;
    }

    $image = match ($mime) {
        'image/jpeg' =>
            function_exists('imagecreatefromjpeg')
                ? @imagecreatefromjpeg(
                    $absolutePath
                )
                : false,

        'image/png' =>
            function_exists('imagecreatefrompng')
                ? @imagecreatefrompng(
                    $absolutePath
                )
                : false,

        'image/gif' =>
            function_exists('imagecreatefromgif')
                ? @imagecreatefromgif(
                    $absolutePath
                )
                : false,

        'image/avif' =>
            function_exists('imagecreatefromavif')
                ? @imagecreatefromavif(
                    $absolutePath
                )
                : false,

        default =>
            false,
    };

    if ($image === false) {
        return null;
    }

    if (
        $mime === 'image/png'
        || $mime === 'image/avif'
    ) {
        imagealphablending(
            $image,
            true
        );

        imagesavealpha(
            $image,
            true
        );
    }

    $webpPath =
        preg_replace(
            '/\.[^.\/]+$/',
            '.webp',
            $absolutePath
        );

    if (
        !is_string($webpPath)
        || $webpPath === $absolutePath
    ) {
        imagedestroy(
            $image
        );

        return null;
    }

    $created =
        @imagewebp(
            $image,
            $webpPath,
            85
        );

    imagedestroy(
        $image
    );

    if (
        !$created
        || !is_file($webpPath)
        || (int) @filesize(
            $webpPath
        ) <= 0
    ) {
        if (
            is_file(
                $webpPath
            )
        ) {
            @unlink(
                $webpPath
            );
        }

        return null;
    }

    return $webpPath;
}


function lesson_edit_store_image_upload(
    array $upload,
    int $lessonId,
    string $prefix
): array {
    $relativeDirectory =
        'uploads/lessons/'
        . $lessonId
        . '/'
        . date('Y')
        . '/'
        . date('m');

    $absoluteDirectory =
        BASE_PATH
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
            'The lesson image directory could not be created.'
        );
    }

    $filename =
        $prefix
        . '-'
        . bin2hex(
            random_bytes(16)
        )
        . '.'
        . $upload['extension'];

    $relativePath =
        $relativeDirectory
        . '/'
        . $filename;

    $absolutePath =
        BASE_PATH
        . '/'
        . $relativePath;

    if (
        !move_uploaded_file(
            (string) $upload['tmp_name'],
            $absolutePath
        )
    ) {
        throw new RuntimeException(
            'The lesson image could not be saved.'
        );
    }

    $webpAbsolute =
        lesson_edit_generate_webp(
            $absolutePath,
            (string) $upload['mime']
        );

    return [
        'reference' =>
            $relativePath,

        'absolute' =>
            $absolutePath,

        'webp_absolute' =>
            $webpAbsolute,
    ];
}


function lesson_edit_remove_upload(
    array $upload
): void {
    foreach (
        [
            $upload['webp_absolute']
                ?? null,
            $upload['absolute']
                ?? null,
        ]
        as $path
    ) {
        if (
            is_string($path)
            && $path !== ''
            && is_file($path)
        ) {
            @unlink($path);
        }
    }
}


function lesson_edit_remove_image_reference(
    ?string $reference
): void {
    $reference =
        ltrim(
            trim(
                (string) $reference
            ),
            '/'
        );

    if (
        $reference === ''
        || !str_starts_with(
            $reference,
            'uploads/lessons/'
        )
    ) {
        return;
    }

    $absolute =
        BASE_PATH
        . '/'
        . $reference;

    $webp =
        preg_replace(
            '/\.[^.\/]+$/',
            '.webp',
            $absolute
        );

    foreach (
        array_unique([
            $absolute,
            is_string($webp)
                ? $webp
                : '',
        ])
        as $path
    ) {
        if (
            $path !== ''
            && is_file($path)
        ) {
            @unlink($path);
        }
    }
}


function lesson_edit_strip_pending_images(
    string $html
): string {
    return (string) preg_replace(
        '#<img\b[^>]*\bsrc=(["\'])/__blackthorne_pending_lesson_image_[a-z0-9_-]{8,80}__\1[^>]*>#i',
        '',
        $html
    );
}


/*
|--------------------------------------------------------------------------
| Load Offering Lesson
|--------------------------------------------------------------------------
*/

$lessonStatement =
    $pdo->prepare(
        '
        SELECT
            col.id AS offering_lesson_id,
            col.offering_id,
            col.lesson_id,
            col.lesson_version_id,
            col.sort_order,

            co.course_id,
            co.offering_scope,
            co.school_year_id,
            co.pacing_mode,
            co.drip_basis,
            co.course_start_date,
            co.course_end_date,
            co.status AS offering_status,

            c.title AS course_title,

            sy.name AS school_year_name,

            l.internal_name,
            l.slug,
            l.lesson_image,
            l.is_published,

            lv.version_number AS active_version_number,
            lv.title AS active_title,
            lv.description AS active_description,
            lv.content AS active_content,
            lv.status AS active_version_status,
            lv.created_at AS active_version_created_at,
            lv.published_at AS active_version_published_at,

            lrr.is_enabled AS release_enabled,
            lrr.release_type,
            lrr.release_delay_days,
            lrr.release_at,
            lrr.prerequisite_lesson_id,

            plv.title AS prerequisite_lesson_title,

            GROUP_CONCAT(
                DISTINCT yg.name
                ORDER BY yg.sort_order ASC, yg.year_number ASC
                SEPARATOR ", "
            ) AS year_group_names

        FROM course_offering_lessons col

        INNER JOIN course_offerings co
            ON co.id = col.offering_id

        INNER JOIN courses c
            ON c.id = co.course_id

        INNER JOIN lessons l
            ON l.id = col.lesson_id

        INNER JOIN lesson_versions lv
            ON lv.id = col.lesson_version_id

        LEFT JOIN school_years sy
            ON sy.id = co.school_year_id

        LEFT JOIN course_offering_year_groups coyg
            ON coyg.offering_id = co.id

        LEFT JOIN year_groups yg
            ON yg.id = coyg.year_group_id

        LEFT JOIN lesson_release_rules lrr
            ON lrr.offering_id = col.offering_id
           AND lrr.lesson_id = col.lesson_id

        LEFT JOIN lessons pl
            ON pl.id = lrr.prerequisite_lesson_id

        LEFT JOIN lesson_versions plv
            ON plv.id = (
                SELECT plv2.id
                FROM lesson_versions plv2
                WHERE plv2.lesson_id = pl.id
                ORDER BY plv2.version_number DESC
                LIMIT 1
            )

        WHERE col.offering_id = :offering_id
          AND col.lesson_id = :lesson_id

        GROUP BY
            col.id,
            col.offering_id,
            col.lesson_id,
            col.lesson_version_id,
            col.sort_order,
            co.course_id,
            co.offering_scope,
            co.school_year_id,
            co.pacing_mode,
            co.drip_basis,
            co.course_start_date,
            co.course_end_date,
            co.status,
            c.title,
            sy.name,
            l.internal_name,
            l.slug,
            l.lesson_image,
            l.is_published,
            lv.version_number,
            lv.title,
            lv.description,
            lv.content,
            lv.status,
            lv.created_at,
            lv.published_at,
            lrr.is_enabled,
            lrr.release_type,
            lrr.release_delay_days,
            lrr.release_at,
            lrr.prerequisite_lesson_id,
            plv.title

        LIMIT 1
        '
    );

$lessonStatement->execute([
    'offering_id' =>
        $offeringId,

    'lesson_id' =>
        $lessonId,
]);

$lesson =
    $lessonStatement->fetch(
        PDO::FETCH_ASSOC
    );

if (!is_array($lesson)) {
    set_flash(
        'error',
        'That lesson is not attached to the selected course offering.'
    );

    redirect(
        url(
            'admin/lessons.php?offering='
            . $offeringId
        )
    );
}


/*
|--------------------------------------------------------------------------
| Other Lessons In Offering
|--------------------------------------------------------------------------
*/

$otherLessonsStatement =
    $pdo->prepare(
        '
        SELECT
            col.lesson_id,
            col.sort_order,
            lv.title

        FROM course_offering_lessons col

        INNER JOIN lesson_versions lv
            ON lv.id = col.lesson_version_id

        WHERE col.offering_id = :offering_id
          AND col.lesson_id <> :lesson_id

        ORDER BY
            col.sort_order ASC,
            col.id ASC
        '
    );

$otherLessonsStatement->execute([
    'offering_id' =>
        $offeringId,

    'lesson_id' =>
        $lessonId,
]);

$otherLessons =
    $otherLessonsStatement->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Version History
|--------------------------------------------------------------------------
*/

$versionsStatement =
    $pdo->prepare(
        '
        SELECT
            lv.id,
            lv.version_number,
            lv.title,
            lv.description,
            lv.status,
            lv.created_by,
            lv.approved_by,
            lv.published_at,
            lv.created_at,
            creator.display_name AS creator_display_name,
            creator.username AS creator_username,
            approver.display_name AS approver_display_name,
            approver.username AS approver_username

        FROM lesson_versions lv

        LEFT JOIN users creator
            ON creator.id = lv.created_by

        LEFT JOIN users approver
            ON approver.id = lv.approved_by

        WHERE lv.lesson_id = :lesson_id

        ORDER BY
            lv.version_number DESC,
            lv.id DESC
        '
    );

$versionsStatement->execute([
    'lesson_id' =>
        $lessonId,
]);

$versions =
    $versionsStatement->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Form State
|--------------------------------------------------------------------------
*/

$errors = [];

$form = [
    'title' =>
        (string) (
            $lesson['active_title']
            ?? $lesson['internal_name']
            ?? ''
        ),

    'description' =>
        (string) (
            $lesson['active_description']
            ?? ''
        ),

    'content' =>
        (string) (
            $lesson['active_content']
            ?? ''
        ),

    'lesson_image' =>
        (string) (
            $lesson['lesson_image']
            ?? ''
        ),

    'status' =>
        (string) (
            $lesson['active_version_status']
            ?? 'draft'
        ),

    'sort_order' =>
        (string) (
            $lesson['sort_order']
            ?? 0
        ),

    'release_type' =>
        (string) (
            $lesson['release_type']
            ?? 'immediate'
        ),

    'release_delay_days' =>
        $lesson['release_delay_days'] !== null
            ? (string) $lesson['release_delay_days']
            : '',

    'release_at' =>
        lesson_edit_form_datetime(
            $lesson['release_at']
            ?? null
        ),

    'prerequisite_lesson_id' =>
        $lesson['prerequisite_lesson_id'] !== null
            ? (string) $lesson['prerequisite_lesson_id']
            : '',
];


/*
|--------------------------------------------------------------------------
| POST Actions
|--------------------------------------------------------------------------
*/

if (
    ($_SERVER['REQUEST_METHOD'] ?? '')
    === 'POST'
) {
    if (
        !verify_csrf_token(
            $_POST['_csrf_token']
            ?? null
        )
    ) {
        $errors[] =
            'Your form session expired. Refresh the page and try again.';
    }

    $action =
        lesson_edit_post_string(
            'action'
        );


    /*
    |--------------------------------------------------------------------------
    | Create New Version
    |--------------------------------------------------------------------------
    */

    if (
        $errors === []
        && $action === 'create_new_version'
    ) {
        $form['title'] =
            lesson_edit_post_string(
                'title'
            );

        $form['description'] =
            lesson_edit_post_string(
                'description'
            );

        $form['content'] =
            trim(
                is_scalar(
                    $_POST['content']
                    ?? ''
                )
                    ? (string) $_POST['content']
                    : ''
            );

        $form['status'] =
            lesson_edit_post_string(
                'status'
            );

        $title =
            $form['title'];

        $status =
            in_array(
                $form['status'],
                [
                    'draft',
                    'review',
                    'published',
                ],
                true
            )
                ? $form['status']
                : 'draft';

        if ($title === '') {
            $errors[] =
                'Lesson Title is required.';
        }

        if (
            strlen($title) > 200
        ) {
            $errors[] =
                'Lesson Title must be 200 characters or fewer.';
        }

        $rawContent =
            $form['content'];

        $plainContent =
            trim(
                html_entity_decode(
                    strip_tags(
                        $rawContent
                    ),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                )
            );

        $contentHasImage =
            preg_match(
                '/<img\b/i',
                $rawContent
            ) === 1;

        if (
            strlen(
                $rawContent
            ) > 1000000
        ) {
            $errors[] =
                'Lesson Content is too large. Shorten the lesson and try again.';
        }

        $replaceLessonImage =
            lesson_edit_validate_image_upload(
                $_FILES['lesson_image']
                ?? []
            );

        if (
            !($replaceLessonImage['ok'] ?? false)
        ) {
            $errors[] =
                (string) (
                    $replaceLessonImage['error']
                    ?? 'The lesson image could not be uploaded.'
                );
        }

        $removeLessonImage =
            isset(
                $_POST['remove_lesson_image']
            )
            && (string) $_POST['remove_lesson_image']
                === '1';

        $inlineFiles =
            lesson_edit_normalize_upload_files(
                $_FILES['uploaded_images']
                ?? []
            );

        $inlineFiles =
            array_values(
                array_filter(
                    $inlineFiles,
                    static fn (array $file): bool =>
                        (int) (
                            $file['error']
                            ?? UPLOAD_ERR_NO_FILE
                        )
                        !== UPLOAD_ERR_NO_FILE
                )
            );

        $uploadTokensRaw =
            json_decode(
                (string) (
                    $_POST['upload_tokens']
                    ?? '[]'
                ),
                true
            );

        $uploadTokens =
            is_array(
                $uploadTokensRaw
            )
                ? array_values(
                    $uploadTokensRaw
                )
                : [];

        if (
            count(
                $inlineFiles
            ) > 10
        ) {
            $errors[] =
                'You can upload up to 10 inline lesson images at a time.';
        }

        $validatedInlineUploads = [];

        if (
            count(
                $uploadTokens
            )
            !==
            count(
                $inlineFiles
            )
        ) {
            if ($inlineFiles !== []) {
                $errors[] =
                    'The selected inline images could not be matched to the editor. Select them again.';
            }
        } else {
            foreach (
                $inlineFiles
                as $index => $inlineFile
            ) {
                $token =
                    strtolower(
                        trim(
                            (string) (
                                $uploadTokens[
                                    $index
                                ]
                                ?? ''
                            )
                        )
                    );

                if (
                    preg_match(
                        '/^[a-z0-9_-]{8,80}$/',
                        $token
                    ) !== 1
                ) {
                    $errors[] =
                        'One of the inline lesson images has an invalid upload reference.';
                    continue;
                }

                $validated =
                    lesson_edit_validate_image_upload(
                        $inlineFile
                    );

                if (
                    !($validated['ok'] ?? false)
                    || ($validated['empty'] ?? true)
                ) {
                    $errors[] =
                        (string) (
                            $validated['error']
                            ?? 'One of the inline lesson images could not be uploaded.'
                        );
                    continue;
                }

                $validated['token'] =
                    $token;

                $validatedInlineUploads[] =
                    $validated;
            }
        }

        $preSanitizedContent =
            sanitize_rich_text(
                $rawContent
            );

        $preSanitizedPlain =
            trim(
                html_entity_decode(
                    strip_tags(
                        $preSanitizedContent
                    ),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                )
            );

        $preSanitizedHasImage =
            preg_match(
                '/<img\b[^>]*\bsrc=/i',
                $preSanitizedContent
            ) === 1;

        if (
            ($plainContent !== '' || $contentHasImage)
            && $preSanitizedPlain === ''
            && !$preSanitizedHasImage
        ) {
            $errors[] =
                'Lesson Content did not contain any supported content.';
        }

        if (
            $status === 'published'
            && !$canPublishLessons
        ) {
            $errors[] =
                'You do not have permission to publish lesson versions.';
        }

        if ($errors === []) {
            try {
                $pdo->beginTransaction();

                $movedUploads = [];
                $finalContent =
                    $rawContent;

                $oldLessonImage =
                    (string) (
                        $lesson['lesson_image']
                        ?? ''
                    );

                $newLessonImage =
                    $oldLessonImage;

                if (
                    !($replaceLessonImage['empty'] ?? true)
                ) {
                    $storedCover =
                        lesson_edit_store_image_upload(
                            $replaceLessonImage,
                            $lessonId,
                            'cover'
                        );

                    $movedUploads[] =
                        $storedCover;

                    $newLessonImage =
                        (string) $storedCover[
                            'reference'
                        ];
                } elseif ($removeLessonImage) {
                    $newLessonImage =
                        '';
                }

                foreach (
                    $validatedInlineUploads
                    as $inlineUpload
                ) {
                    $storedInline =
                        lesson_edit_store_image_upload(
                            $inlineUpload,
                            $lessonId,
                            'content'
                        );

                    $movedUploads[] =
                        $storedInline;

                    $publicPath =
                        '/'
                        . ltrim(
                            (string) $storedInline[
                                'reference'
                            ],
                            '/'
                        );

                    $placeholder =
                        '/__blackthorne_pending_lesson_image_'
                        . $inlineUpload[
                            'token'
                        ]
                        . '__';

                    $finalContent =
                        str_replace(
                            $placeholder,
                            $publicPath,
                            $finalContent
                        );
                }

                $finalContent =
                    lesson_edit_strip_pending_images(
                        $finalContent
                    );

                $safeContent =
                    sanitize_rich_text(
                        $finalContent
                    );

                $versionNumberStatement =
                    $pdo->prepare(
                        '
                        SELECT
                            COALESCE(
                                MAX(version_number),
                                0
                            ) + 1

                        FROM lesson_versions

                        WHERE lesson_id = :lesson_id
                        '
                    );

                $versionNumberStatement->execute([
                    'lesson_id' =>
                        $lessonId,
                ]);

                $nextVersionNumber =
                    (int) $versionNumberStatement->fetchColumn();

                if ($nextVersionNumber <= 0) {
                    $nextVersionNumber = 1;
                }

                $publishedAt =
                    $status === 'published'
                        ? date('Y-m-d H:i:s')
                        : null;

                $approvedBy =
                    $status === 'published'
                        ? (
                            $currentUserId > 0
                                ? $currentUserId
                                : null
                        )
                        : null;

                $insertVersion =
                    $pdo->prepare(
                        '
                        INSERT INTO lesson_versions (
                            lesson_id,
                            version_number,
                            title,
                            description,
                            content,
                            status,
                            created_by,
                            approved_by,
                            published_at
                        ) VALUES (
                            :lesson_id,
                            :version_number,
                            :title,
                            :description,
                            :content,
                            :status,
                            :created_by,
                            :approved_by,
                            :published_at
                        )
                        '
                    );

                $insertVersion->execute([
                    'lesson_id' =>
                        $lessonId,

                    'version_number' =>
                        $nextVersionNumber,

                    'title' =>
                        $title,

                    'description' =>
                        $form['description'] !== ''
                            ? $form['description']
                            : null,

                    'content' =>
                        $safeContent !== ''
                            ? $safeContent
                            : null,

                    'status' =>
                        $status,

                    'created_by' =>
                        $currentUserId > 0
                            ? $currentUserId
                            : null,

                    'approved_by' =>
                        $approvedBy,

                    'published_at' =>
                        $publishedAt,
                ]);

                $newVersionId =
                    (int) $pdo->lastInsertId();

                /*
                 * Important:
                 * Only this exact offering is switched to the new version.
                 * Other offerings may continue using older versions.
                 */
                $updateOfferingLesson =
                    $pdo->prepare(
                        '
                        UPDATE course_offering_lessons

                        SET lesson_version_id = :lesson_version_id

                        WHERE offering_id = :offering_id
                          AND lesson_id = :lesson_id
                        '
                    );

                $updateOfferingLesson->execute([
                    'lesson_version_id' =>
                        $newVersionId,

                    'offering_id' =>
                        $offeringId,

                    'lesson_id' =>
                        $lessonId,
                ]);

                $updateLesson =
                    $pdo->prepare(
                        '
                        UPDATE lessons

                        SET
                            internal_name = :internal_name,
                            lesson_image = :lesson_image,
                            is_published = :is_published

                        WHERE id = :lesson_id
                        '
                    );

                $updateLesson->execute([
                    'internal_name' =>
                        $title,

                    'lesson_image' =>
                        $newLessonImage !== ''
                            ? $newLessonImage
                            : null,

                    'is_published' =>
                        $status === 'published'
                            ? 1
                            : (int) (
                                $lesson[
                                    'is_published'
                                ]
                                ?? 0
                            ),

                    'lesson_id' =>
                        $lessonId,
                ]);

                lesson_edit_audit(
                    $pdo,
                    $currentUserId,
                    $lessonId,
                    'lesson.version.create',
                    'Created lesson version '
                    . $nextVersionNumber
                    . ' for offering #'
                    . $offeringId
                );

                $pdo->commit();

                if (
                    $oldLessonImage !== ''
                    && $oldLessonImage !== $newLessonImage
                ) {
                    lesson_edit_remove_image_reference(
                        $oldLessonImage
                    );
                }

                set_flash(
                    'success',
                    'A new lesson version was created and assigned to this offering.'
                );

                redirect(
                    url(
                        'admin/lesson-edit.php?offering='
                        . $offeringId
                        . '&lesson='
                        . $lessonId
                    )
                );
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if (
                    isset($movedUploads)
                    && is_array($movedUploads)
                ) {
                    foreach ($movedUploads as $movedUpload) {
                        if (is_array($movedUpload)) {
                            lesson_edit_remove_upload(
                                $movedUpload
                            );
                        }
                    }
                }

                error_log(
                    'Lesson version create error: '
                    . $exception->getMessage()
                );

                $errors[] =
                    'The new lesson version could not be created.';
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Update Offering Settings
    |--------------------------------------------------------------------------
    */

    if (
        $errors === []
        && $action === 'update_offering_settings'
    ) {
        $form['sort_order'] =
            lesson_edit_post_string(
                'sort_order'
            );

        $form['release_type'] =
            lesson_edit_post_string(
                'release_type'
            );

        $form['release_delay_days'] =
            lesson_edit_post_string(
                'release_delay_days'
            );

        $form['release_at'] =
            lesson_edit_post_string(
                'release_at'
            );

        $form['prerequisite_lesson_id'] =
            lesson_edit_post_string(
                'prerequisite_lesson_id'
            );

        $sortOrder =
            lesson_edit_post_uint(
                'sort_order',
                (int) (
                    $lesson['sort_order']
                    ?? 0
                )
            );

        $offeringScope =
            (string) (
                $lesson['offering_scope']
                ?? 'school_year'
            );

        $releaseType =
            $form['release_type'];

        $schoolYearReleaseTypes = [
            'immediate',
            'days_after_course_start',
            'fixed_date',
            'after_previous_lesson',
        ];

        $perpetualReleaseTypes = [
            'immediate',
            'after_previous_lesson',
        ];

        $allowedReleaseTypes =
            $offeringScope === 'perpetual'
                ? $perpetualReleaseTypes
                : $schoolYearReleaseTypes;

        if (
            !in_array(
                $releaseType,
                $allowedReleaseTypes,
                true
            )
        ) {
            $releaseType =
                'immediate';
        }

        if (
            $releaseType !== 'immediate'
            && !$canManageRelease
        ) {
            $errors[] =
                'You do not have permission to change lesson release rules.';
        }

        $releaseDelayDays =
            $releaseType
                === 'days_after_course_start'
                ? lesson_edit_post_uint(
                    'release_delay_days'
                )
                : null;

        $releaseAt =
            $releaseType
                === 'fixed_date'
                ? lesson_edit_normalize_datetime(
                    $form['release_at']
                )
                : null;

        $prerequisiteLessonId =
            $releaseType
                === 'after_previous_lesson'
                ? lesson_edit_post_id(
                    'prerequisite_lesson_id'
                )
                : 0;

        if (
            $releaseType === 'days_after_course_start'
            && $form['release_delay_days'] === ''
        ) {
            $errors[] =
                'Enter the number of days after the course start date.';
        }

        if (
            $releaseType === 'days_after_course_start'
            && empty(
                $lesson['course_start_date']
            )
        ) {
            $errors[] =
                'This offering needs a Course Start Date before a course-start release delay can be used.';
        }

        if (
            $releaseType === 'fixed_date'
            && $releaseAt === null
        ) {
            $errors[] =
                'Enter a valid fixed release date and time.';
        }

        if (
            $releaseType === 'after_previous_lesson'
            && $prerequisiteLessonId <= 0
        ) {
            $errors[] =
                'Choose the lesson that must be completed first.';
        }

        if (
            $releaseType === 'after_previous_lesson'
            && $prerequisiteLessonId > 0
        ) {
            $validPreviousLesson = false;

            foreach ($otherLessons as $otherLesson) {
                if (
                    (int) (
                        $otherLesson['lesson_id']
                        ?? 0
                    ) === $prerequisiteLessonId
                ) {
                    $validPreviousLesson = true;
                    break;
                }
            }

            if (!$validPreviousLesson) {
                $errors[] =
                    'The prerequisite lesson must belong to this course offering.';
            }
        }

        if ($errors === []) {
            try {
                $pdo->beginTransaction();

                $updateSort =
                    $pdo->prepare(
                        '
                        UPDATE course_offering_lessons

                        SET sort_order = :sort_order

                        WHERE offering_id = :offering_id
                          AND lesson_id = :lesson_id
                        '
                    );

                $updateSort->execute([
                    'sort_order' =>
                        $sortOrder,

                    'offering_id' =>
                        $offeringId,

                    'lesson_id' =>
                        $lessonId,
                ]);

                $existingReleaseStatement =
                    $pdo->prepare(
                        '
                        SELECT id

                        FROM lesson_release_rules

                        WHERE offering_id = :offering_id
                          AND lesson_id = :lesson_id

                        LIMIT 1
                        '
                    );

                $existingReleaseStatement->execute([
                    'offering_id' =>
                        $offeringId,

                    'lesson_id' =>
                        $lessonId,
                ]);

                $releaseRuleId =
                    $existingReleaseStatement->fetchColumn();

                if ($releaseRuleId !== false) {
                    $updateRelease =
                        $pdo->prepare(
                            '
                            UPDATE lesson_release_rules

                            SET
                                is_enabled = 1,
                                release_type = :release_type,
                                release_delay_days = :release_delay_days,
                                release_at = :release_at,
                                prerequisite_lesson_id = :prerequisite_lesson_id

                            WHERE id = :id
                            '
                        );

                    $updateRelease->execute([
                        'release_type' =>
                            $releaseType,

                        'release_delay_days' =>
                            $releaseDelayDays,

                        'release_at' =>
                            $releaseAt,

                        'prerequisite_lesson_id' =>
                            $prerequisiteLessonId > 0
                                ? $prerequisiteLessonId
                                : null,

                        'id' =>
                            (int) $releaseRuleId,
                    ]);
                } else {
                    $insertRelease =
                        $pdo->prepare(
                            '
                            INSERT INTO lesson_release_rules (
                                offering_id,
                                lesson_id,
                                is_enabled,
                                release_type,
                                release_delay_days,
                                release_at,
                                prerequisite_lesson_id
                            ) VALUES (
                                :offering_id,
                                :lesson_id,
                                1,
                                :release_type,
                                :release_delay_days,
                                :release_at,
                                :prerequisite_lesson_id
                            )
                            '
                        );

                    $insertRelease->execute([
                        'offering_id' =>
                            $offeringId,

                        'lesson_id' =>
                            $lessonId,

                        'release_type' =>
                            $releaseType,

                        'release_delay_days' =>
                            $releaseDelayDays,

                        'release_at' =>
                            $releaseAt,

                        'prerequisite_lesson_id' =>
                            $prerequisiteLessonId > 0
                                ? $prerequisiteLessonId
                                : null,
                    ]);
                }

                lesson_edit_audit(
                    $pdo,
                    $currentUserId,
                    $lessonId,
                    'lesson.offering_settings.update',
                    'Updated lesson order/release settings for offering #'
                    . $offeringId
                );

                $pdo->commit();

                set_flash(
                    'success',
                    'Lesson offering settings updated successfully.'
                );

                redirect(
                    url(
                        'admin/lesson-edit.php?offering='
                        . $offeringId
                        . '&lesson='
                        . $lessonId
                    )
                );
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'Lesson offering settings update error: '
                    . $exception->getMessage()
                );

                $errors[] =
                    'The lesson offering settings could not be updated.';
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| Sidebar
|--------------------------------------------------------------------------
*/

$courseSidebarActive =
    'lessons';

require INCLUDES_PATH . '/staff-course-sidebar.php';


/*
|--------------------------------------------------------------------------
| SEO / Header
|--------------------------------------------------------------------------
*/

$pageTitle =
    'Edit Lesson | Blackthorne Academy';

$pageDescription =
    'Edit lesson versions and offering release settings.';

$pageCanonical =
    url(
        'admin/lesson-edit.php?offering='
        . $offeringId
        . '&lesson='
        . $lessonId
    );

$robots =
    'noindex, nofollow';

require INCLUDES_PATH . '/header.php';


/*
|--------------------------------------------------------------------------
| Hero
|--------------------------------------------------------------------------
*/

$staffHeroPngReference =
    'assets/images/staff_dashboard_hero_bg.png';

$staffHeroWebpReference =
    'assets/images/staff_dashboard_hero_bg.webp';

$projectRoot =
    dirname(__DIR__);

$staffHeroReference =
    is_file(
        $projectRoot
        . '/'
        . $staffHeroWebpReference
    )
        ? $staffHeroWebpReference
        : $staffHeroPngReference;

$staffHeroUrl =
    is_file(
        $projectRoot
        . '/'
        . $staffHeroReference
    )
        ? url($staffHeroReference)
        : '';

?>

<main
    id="main-content"
    class="dashboard-page staff-dashboard-page dashboard-workspace-page courses-dashboard-page lesson-edit-page"
>

    <section
        class="dashboard-hero staff-dashboard-hero"
        aria-labelledby="lesson-edit-heading"
        <?php if ($staffHeroUrl !== ''): ?>
            style="--staff-dashboard-hero-image: url('<?= e($staffHeroUrl); ?>');"
        <?php endif; ?>
    >
        <div class="section-inner">
            <div class="dashboard-hero-inner">

                <p class="academy-overline">
                    Lesson Management
                </p>

                <h1 id="lesson-edit-heading">
                    Edit Lesson
                </h1>

                <p class="dashboard-hero-copy">
                    Create new lesson versions without overwriting historical
                    course content, and manage this offering's release settings.
                </p>

            </div>
        </div>
    </section>


    <section class="dashboard-workspace-section">
        <div class="section-inner dashboard-workspace-layout">

            <?php
            require
                INCLUDES_PATH
                . '/dashboard-sidebar.php';
            ?>

            <div class="dashboard-workspace-main">

                <header class="dashboard-workspace-heading">
                    <div>
                        <p class="academy-overline">
                            <?= e(
                                (string) (
                                    $lesson['course_title']
                                    ?? 'Course'
                                )
                            ); ?>
                        </p>

                        <h2>
                            <?= e(
                                (string) (
                                    $lesson['active_title']
                                    ?? $lesson['internal_name']
                                    ?? 'Lesson'
                                )
                            ); ?>
                        </h2>

                        <p>
                            This offering currently uses Version
                            <?= number_format(
                                (int) (
                                    $lesson[
                                        'active_version_number'
                                    ]
                                    ?? 1
                                )
                            ); ?>.
                        </p>
                    </div>

                    <div class="dashboard-workspace-heading-actions">
                        <a
                            class="button button-secondary"
                            href="<?= e(
                                url(
                                    'admin/lessons.php?offering='
                                    . $offeringId
                                )
                            ); ?>"
                        >
                            Back to Lessons
                        </a>
                    </div>
                </header>


                <?php if ($errors !== []): ?>

                    <div
                        class="form-message form-message-error"
                        role="alert"
                    >
                        <strong>
                            The lesson could not be updated.
                        </strong>

                        <ul>
                            <?php foreach ($errors as $error): ?>
                                <li>
                                    <?= e($error); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                <?php endif; ?>


                <section class="dashboard-workspace-panel">

                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">
                                Current Offering
                            </p>

                            <h3>
                                Course Context
                            </h3>
                        </div>
                    </div>

                    <div class="dashboard-panel-body">

                        <div class="dashboard-placeholder-list">

                            <span>
                                <strong>Offering:</strong>
                                #<?= number_format($offeringId); ?>
                            </span>

                            <span>
                                <strong>Scope:</strong>
                                <?= e(
                                    ucfirst(
                                        str_replace(
                                            '_',
                                            ' ',
                                            (string) (
                                                $lesson[
                                                    'offering_scope'
                                                ]
                                                ?? 'school_year'
                                            )
                                        )
                                    )
                                ); ?>
                            </span>

                            <?php if (
                                !empty(
                                    $lesson[
                                        'school_year_name'
                                    ]
                                )
                            ): ?>
                                <span>
                                    <strong>School Year:</strong>
                                    <?= e(
                                        (string) $lesson[
                                            'school_year_name'
                                        ]
                                    ); ?>
                                </span>
                            <?php endif; ?>

                            <?php if (
                                !empty(
                                    $lesson[
                                        'year_group_names'
                                    ]
                                )
                            ): ?>
                                <span>
                                    <strong>Grade Level:</strong>
                                    <?= e(
                                        (string) $lesson[
                                            'year_group_names'
                                        ]
                                    ); ?>
                                </span>
                            <?php endif; ?>

                            <span>
                                <strong>Release:</strong>
                                <?= e(
                                    lesson_edit_release_label(
                                        $lesson
                                    )
                                ); ?>
                            </span>

                        </div>

                    </div>

                </section>


                <section class="forum-admin-panel">

                    <header class="forum-admin-titlebar">
                        <p class="forum-admin-step">
                            Content Revision
                        </p>

                        <h2>
                            Create New Version
                        </h2>
                    </header>

                    <form
                        action="<?= e(
                            url(
                                'admin/lesson-edit.php?offering='
                                . $offeringId
                                . '&lesson='
                                . $lessonId
                            )
                        ); ?>"
                        method="post"
                        enctype="multipart/form-data"
                        class="forum-admin-form"
                        id="lesson-version-form"
                    >
                        <?= csrf_field(); ?>

                        <input
                            type="hidden"
                            name="action"
                            value="create_new_version"
                        >


                        <div class="form-group">
                            <label for="lesson-title">
                                Lesson Title
                            </label>

                            <input
                                class="form-control"
                                type="text"
                                id="lesson-title"
                                name="title"
                                maxlength="200"
                                value="<?= e($form['title']); ?>"
                                required
                            >
                        </div>


                        <div class="form-group">
                            <label for="lesson-image">
                                Lesson Image
                            </label>

                            <?php if (
                                trim(
                                    (string) (
                                        $lesson['lesson_image']
                                        ?? ''
                                    )
                                ) !== ''
                            ): ?>
                                <div class="lesson-edit-current-image">
                                    <img
                                        src="<?= e(
                                            url(
                                                ltrim(
                                                    (string) $lesson['lesson_image'],
                                                    '/'
                                                )
                                            )
                                        ); ?>"
                                        alt="<?= e(
                                            (string) (
                                                $lesson['active_title']
                                                ?? 'Lesson'
                                            )
                                        ); ?>"
                                        loading="lazy"
                                    >

                                    <label class="forum-admin-checkbox-row">
                                        <input
                                            type="checkbox"
                                            name="remove_lesson_image"
                                            value="1"
                                        >
                                        Remove current lesson image
                                    </label>
                                </div>
                            <?php endif; ?>

                            <input
                                class="form-control"
                                type="file"
                                id="lesson-image"
                                name="lesson_image"
                                accept="image/jpeg,image/png,image/gif,image/webp,image/avif"
                            >

                            <p class="form-help">
                                Optional. Upload a replacement lesson thumbnail/header image. Maximum file size: 10 MB.
                            </p>
                        </div>


                        <div class="form-group">
                            <label for="lesson-description">
                                Lesson Description
                            </label>

                            <textarea
                                class="form-control"
                                id="lesson-description"
                                name="description"
                                rows="4"
                            ><?= e($form['description']); ?></textarea>
                        </div>


                        <div class="form-group forum-rich-editor-field">
                            <label id="lesson-content-label" for="lesson-editor">
                                Lesson Content
                            </label>

                            <div class="forum-rich-editor" data-forum-editor>

                                <div
                                    class="forum-rich-editor-toolbar"
                                    role="toolbar"
                                    aria-label="Lesson content formatting"
                                >
                                    <div class="forum-editor-tool-group">
                                        <button type="button" class="forum-editor-tool" data-command="bold" title="Bold"><strong>B</strong></button>
                                        <button type="button" class="forum-editor-tool" data-command="italic" title="Italic"><em>I</em></button>
                                        <button type="button" class="forum-editor-tool" data-command="underline" title="Underline"><u>U</u></button>
                                        <button type="button" class="forum-editor-tool" data-command="strikeThrough" title="Strikethrough"><s>S</s></button>
                                    </div>

                                    <div class="forum-editor-tool-group">
                                        <select
                                            class="forum-editor-select"
                                            data-editor-format
                                            title="Text style"
                                            aria-label="Text style"
                                        >
                                            <option value="">Text Style</option>
                                            <option value="p">Paragraph</option>
                                            <option value="h2">Heading 2</option>
                                            <option value="h3">Heading 3</option>
                                            <option value="h4">Heading 4</option>
                                        </select>

                                        <select
                                            class="forum-editor-select"
                                            data-editor-size
                                            title="Font size"
                                            aria-label="Font size"
                                        >
                                            <option value="">Font Size</option>
                                            <option value="14px">Small</option>
                                            <option value="16px">Normal</option>
                                            <option value="18px">Large</option>
                                            <option value="22px">Extra Large</option>
                                            <option value="28px">Display</option>
                                        </select>
                                    </div>

                                    <div class="forum-editor-tool-group forum-editor-color-tools">
                                        <label class="forum-editor-color-label" title="Text color">
                                            Text
                                            <input
                                                type="color"
                                                value="#e8e1e6"
                                                data-editor-color
                                                aria-label="Text color"
                                            >
                                        </label>
                                        <button
                                            type="button"
                                            class="forum-editor-tool"
                                            data-editor-apply-color
                                            title="Apply the current text color to the selected text"
                                        >
                                            Apply Text
                                        </button>

                                        <label class="forum-editor-color-label" title="Highlight color">
                                            Highlight
                                            <input
                                                type="color"
                                                value="#55336f"
                                                data-editor-highlight
                                                aria-label="Highlight color"
                                            >
                                        </label>
                                        <button
                                            type="button"
                                            class="forum-editor-tool"
                                            data-editor-apply-highlight
                                            title="Apply the current highlight color to the selected text"
                                        >
                                            Apply Highlight
                                        </button>
                                    </div>

                                    <div class="forum-editor-tool-group">
                                        <button type="button" class="forum-editor-tool" data-command="insertUnorderedList" title="Bulleted list">• List</button>
                                        <button type="button" class="forum-editor-tool" data-command="insertOrderedList" title="Numbered list">1. List</button>
                                        <button type="button" class="forum-editor-tool" data-editor-quote title="Quote">Quote</button>
                                    </div>

                                    <div class="forum-editor-tool-group">
                                        <button type="button" class="forum-editor-tool" data-command="justifyLeft">Left</button>
                                        <button type="button" class="forum-editor-tool" data-command="justifyCenter">Center</button>
                                        <button type="button" class="forum-editor-tool" data-command="justifyRight">Right</button>
                                    </div>

                                    <div class="forum-editor-tool-group">
                                        <button type="button" class="forum-editor-tool" data-editor-link>Link</button>
                                        <button type="button" class="forum-editor-tool" data-command="unlink">Unlink</button>
                                        <button type="button" class="forum-editor-tool" data-command="removeFormat">Clear</button>
                                    </div>

                                    <div class="forum-editor-tool-group forum-editor-image-tools">
                                        <select
                                            class="forum-editor-select"
                                            data-editor-image-size
                                            title="Image size"
                                        >
                                            <option value="">Image Size</option>
                                            <option value="25%">25%</option>
                                            <option value="40%">40%</option>
                                            <option value="50%">50%</option>
                                            <option value="60%">60%</option>
                                            <option value="75%">75%</option>
                                            <option value="90%">90%</option>
                                            <option value="100%">100%</option>
                                        </select>

                                            <select
                                                class="forum-editor-select"
                                                data-editor-image-align
                                                title="Image alignment"
                                            >
                                                <option value="">Image Align</option>
                                                <option value="left">Left</option>
                                                <option value="center">Center</option>
                                                <option value="right">Right</option>
                                            </select>

                                        <button
                                            type="button"
                                            class="forum-editor-tool"
                                            data-editor-image-upload
                                        >
                                            Upload Image
                                        </button>

                                        <input
                                            class="forum-editor-image-upload-input"
                                            type="file"
                                            id="lesson-content-image-upload"
                                            name="uploaded_images[]"
                                            accept="image/jpeg,image/png,image/gif,image/webp,image/avif"
                                            multiple
                                            data-editor-image-input
                                        >

                                        <input
                                            type="hidden"
                                            name="upload_tokens"
                                            value="[]"
                                            data-editor-upload-tokens
                                        >

                                        <button
                                            type="button"
                                            class="forum-editor-tool"
                                            data-editor-image-url
                                        >
                                            Image URL
                                        </button>
                                    </div>
                                </div>

                                <div
                                    class="forum-rich-editor-surface"
                                    id="lesson-editor"
                                    contenteditable="true"
                                    role="textbox"
                                    aria-labelledby="lesson-content-label"
                                    aria-multiline="true"
                                    data-placeholder="Write the lesson content here..."
                                    spellcheck="true"
                                ><?= $form['content'] !== '' ? sanitize_rich_text($form['content']) : ''; ?></div>

                                <div
                                    class="forum-editor-image-preview-list"
                                    data-editor-image-previews
                                    hidden
                                    aria-live="polite"
                                ></div>

                                <div
                                    class="forum-editor-counts"
                                    aria-live="polite"
                                    aria-atomic="true"
                                >
                                    <span data-editor-word-count>0 words</span>
                                    <span aria-hidden="true">•</span>
                                    <span data-editor-character-count>0 characters</span>
                                </div>

                                <textarea
                                    class="forum-rich-editor-input"
                                    name="content"
                                    id="lesson-content"
                                    aria-hidden="true"
                                    tabindex="-1"
                                ><?= e($form['content']); ?></textarea>

                            </div>

                            <p class="form-help">
                                Saving creates a new lesson version. Use headings, text styles, colors, lists, alignment, links, quotes, and inline images without overwriting the previous version.
                            </p>
                        </div>


                        <div class="form-group">
                            <label for="lesson-status">
                                New Version Status
                            </label>

                            <select
                                class="form-control"
                                id="lesson-status"
                                name="status"
                            >
                                <option
                                    value="draft"
                                    <?= $form['status'] === 'draft'
                                        ? 'selected'
                                        : ''; ?>
                                >
                                    Draft
                                </option>

                                <option
                                    value="review"
                                    <?= $form['status'] === 'review'
                                        ? 'selected'
                                        : ''; ?>
                                >
                                    Review
                                </option>

                                <?php if ($canPublishLessons): ?>
                                    <option
                                        value="published"
                                        <?= $form['status'] === 'published'
                                            ? 'selected'
                                            : ''; ?>
                                    >
                                        Published
                                    </option>
                                <?php endif; ?>
                            </select>
                        </div>


                        <div class="forum-admin-actions">
                            <button
                                type="submit"
                                class="button button-primary"
                            >
                                Create New Version
                            </button>
                        </div>

                    </form>

                </section>


                <section class="forum-admin-panel">

                    <header class="forum-admin-titlebar">
                        <p class="forum-admin-step">
                            Offering Settings
                        </p>

                        <h2>
                            Order &amp; Release
                        </h2>
                    </header>

                    <form
                        action="<?= e(
                            url(
                                'admin/lesson-edit.php?offering='
                                . $offeringId
                                . '&lesson='
                                . $lessonId
                            )
                        ); ?>"
                        method="post"
                        class="forum-admin-form"
                    >
                        <?= csrf_field(); ?>

                        <input
                            type="hidden"
                            name="action"
                            value="update_offering_settings"
                        >


                        <div class="form-group">
                            <label for="sort-order">
                                Lesson Order
                            </label>

                            <input
                                class="form-control"
                                type="number"
                                id="sort-order"
                                name="sort_order"
                                min="0"
                                step="1"
                                value="<?= e($form['sort_order']); ?>"
                                required
                            >
                        </div>


                        <?php if ($canManageRelease): ?>

                            <fieldset class="forum-admin-fieldset">

                                <legend>
                                    Release Rule
                                </legend>

                                <div class="form-group">
                                    <label for="release-type">
                                        Release Type
                                    </label>

                                    <select
                                        class="form-control"
                                        id="release-type"
                                        name="release_type"
                                    >
                                        <option
                                            value="immediate"
                                            <?= $form['release_type'] === 'immediate'
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            Immediate
                                        </option>

                                        <?php if (
                                            ($lesson['offering_scope'] ?? '')
                                            !== 'perpetual'
                                        ): ?>

                                            <option
                                                value="days_after_course_start"
                                                <?= $form['release_type'] === 'days_after_course_start'
                                                    ? 'selected'
                                                    : ''; ?>
                                            >
                                                Days After Course Start
                                            </option>

                                            <option
                                                value="fixed_date"
                                                <?= $form['release_type'] === 'fixed_date'
                                                    ? 'selected'
                                                    : ''; ?>
                                            >
                                                Fixed Date
                                            </option>

                                        <?php endif; ?>

                                        <?php if ($otherLessons !== []): ?>
                                            <option
                                                value="after_previous_lesson"
                                                <?= $form['release_type'] === 'after_previous_lesson'
                                                    ? 'selected'
                                                    : ''; ?>
                                            >
                                                After Previous Lesson
                                            </option>
                                        <?php endif; ?>
                                    </select>

                                    <p class="form-help">
                                        Enrollment-relative release is intentionally
                                        excluded so late registrants remain on the
                                        same course timeline as the rest of the cohort.
                                    </p>
                                </div>


                                <?php if (
                                    ($lesson['offering_scope'] ?? '')
                                    !== 'perpetual'
                                ): ?>

                                    <div class="form-group">
                                        <label for="release-delay-days">
                                            Days After Course Start
                                        </label>

                                        <input
                                            class="form-control"
                                            type="number"
                                            id="release-delay-days"
                                            name="release_delay_days"
                                            min="0"
                                            step="1"
                                            value="<?= e(
                                                $form[
                                                    'release_delay_days'
                                                ]
                                            ); ?>"
                                        >
                                    </div>


                                    <div class="form-group">
                                        <label for="release-at">
                                            Fixed Release Date
                                        </label>

                                        <input
                                            class="form-control"
                                            type="datetime-local"
                                            id="release-at"
                                            name="release_at"
                                            value="<?= e(
                                                $form[
                                                    'release_at'
                                                ]
                                            ); ?>"
                                        >
                                    </div>

                                <?php endif; ?>


                                <?php if ($otherLessons !== []): ?>

                                    <div class="form-group">
                                        <label for="prerequisite-lesson">
                                            Previous Lesson
                                        </label>

                                        <select
                                            class="form-control"
                                            id="prerequisite-lesson"
                                            name="prerequisite_lesson_id"
                                        >
                                            <option value="">
                                                Select a lesson
                                            </option>

                                            <?php foreach ($otherLessons as $otherLesson): ?>
                                                <option
                                                    value="<?= (int) $otherLesson['lesson_id']; ?>"
                                                    <?= (string) $otherLesson['lesson_id']
                                                        === $form['prerequisite_lesson_id']
                                                            ? 'selected'
                                                            : ''; ?>
                                                >
                                                    <?= e(
                                                        (string) (
                                                            $otherLesson[
                                                                'title'
                                                            ]
                                                            ?? 'Lesson'
                                                        )
                                                    ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                <?php endif; ?>

                            </fieldset>

                        <?php else: ?>

                            <input
                                type="hidden"
                                name="release_type"
                                value="<?= e(
                                    (string) (
                                        $lesson[
                                            'release_type'
                                        ]
                                        ?? 'immediate'
                                    )
                                ); ?>"
                            >

                            <p class="form-help">
                                You can change lesson order, but your role does
                                not have permission to alter release rules.
                            </p>

                        <?php endif; ?>


                        <div class="forum-admin-actions">
                            <button
                                type="submit"
                                class="button button-primary"
                            >
                                Save Offering Settings
                            </button>
                        </div>

                    </form>

                </section>


                <section class="dashboard-workspace-panel">

                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">
                                Version History
                            </p>

                            <h3>
                                Saved Lesson Versions
                            </h3>
                        </div>
                    </div>

                    <div class="dashboard-panel-body">

                        <?php if ($versions === []): ?>

                            <p>
                                No version history is available.
                            </p>

                        <?php else: ?>

                            <div class="dashboard-placeholder-list">

                                <?php foreach ($versions as $versionRow): ?>
                                    <?php
                                    $creatorName =
                                        trim(
                                            (string) (
                                                $versionRow[
                                                    'creator_display_name'
                                                ]
                                                ?? $versionRow[
                                                    'creator_username'
                                                ]
                                                ?? ''
                                            )
                                        );

                                    $approverName =
                                        trim(
                                            (string) (
                                                $versionRow[
                                                    'approver_display_name'
                                                ]
                                                ?? $versionRow[
                                                    'approver_username'
                                                ]
                                                ?? ''
                                            )
                                        );

                                    $isCurrentVersion =
                                        (int) (
                                            $versionRow['id']
                                            ?? 0
                                        )
                                        ===
                                        (int) (
                                            $lesson[
                                                'lesson_version_id'
                                            ]
                                            ?? 0
                                        );
                                    ?>

                                    <span>

                                        <strong>
                                            Version
                                            <?= number_format(
                                                (int) (
                                                    $versionRow[
                                                        'version_number'
                                                    ]
                                                    ?? 1
                                                )
                                            ); ?>
                                            —
                                            <?= e(
                                                (string) (
                                                    $versionRow[
                                                        'title'
                                                    ]
                                                    ?? 'Lesson'
                                                )
                                            ); ?>
                                        </strong>

                                        <?php if ($isCurrentVersion): ?>
                                            · Current for this offering
                                        <?php endif; ?>

                                        ·
                                        <?= e(
                                            ucfirst(
                                                (string) (
                                                    $versionRow[
                                                        'status'
                                                    ]
                                                    ?? 'draft'
                                                )
                                            )
                                        ); ?>

                                        · Created
                                        <?= e(
                                            lesson_edit_format_datetime(
                                                $versionRow[
                                                    'created_at'
                                                ]
                                                ?? null
                                            )
                                        ); ?>

                                        <?php if ($creatorName !== ''): ?>
                                            by <?= e($creatorName); ?>
                                        <?php endif; ?>

                                        <?php if (
                                            !empty(
                                                $versionRow[
                                                    'published_at'
                                                ]
                                            )
                                        ): ?>
                                            · Published
                                            <?= e(
                                                lesson_edit_format_datetime(
                                                    $versionRow[
                                                        'published_at'
                                                    ]
                                                )
                                            ); ?>
                                        <?php endif; ?>

                                        <?php if ($approverName !== ''): ?>
                                            · Approved by
                                            <?= e($approverName); ?>
                                        <?php endif; ?>

                                    </span>

                                <?php endforeach; ?>

                            </div>

                        <?php endif; ?>

                    </div>

                </section>

            </div>

        </div>
    </section>

</main>


<style>
.lesson-edit-current-image {
    display: grid;
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.lesson-edit-current-image img {
    display: block;
    width: min(100%, 520px);
    max-height: 260px;
    object-fit: contain;
    border: 1px solid rgba(203, 168, 95, 0.38);
    border-radius: 0.45rem;
    background: rgba(13, 8, 15, 0.74);
}
</style>

<script>
(() => {
    'use strict';

    const form =
        document.getElementById(
            'lesson-version-form'
        );

    const editor =
        document.getElementById(
            'lesson-editor'
        );

    const input =
        document.getElementById(
            'lesson-content'
        );

    if (
        !form
        || !editor
        || !input
    ) {
        return;
    }

    const imageUploadButton =
        form.querySelector(
            '[data-editor-image-upload]'
        );

    const imageInput =
        form.querySelector(
            '[data-editor-image-input]'
        );

    const imageUrlButton =
        form.querySelector(
            '[data-editor-image-url]'
        );

    const imageSizeSelect =
        form.querySelector(
            '[data-editor-image-size]'
        );

    const imageAlignSelect =
        form.querySelector(
            '[data-editor-image-align]'
        );

    const imagePreviews =
        form.querySelector(
            '[data-editor-image-previews]'
        );

    const uploadTokensInput =
        form.querySelector(
            '[data-editor-upload-tokens]'
        );

    const wordCount =
        form.querySelector(
            '[data-editor-word-count]'
        );

    const characterCount =
        form.querySelector(
            '[data-editor-character-count]'
        );

    const quoteButton =
        form.querySelector(
            '[data-editor-quote]'
        );

    const formatSelect =
        form.querySelector(
            '[data-editor-format]'
        );

    const sizeSelect =
        form.querySelector(
            '[data-editor-size]'
        );

    const colorInput =
        form.querySelector(
            '[data-editor-color]'
        );

    const highlightInput =
        form.querySelector(
            '[data-editor-highlight]'
        );

    const applyColorButton =
        form.querySelector(
            '[data-editor-apply-color]'
        );

    const applyHighlightButton =
        form.querySelector(
            '[data-editor-apply-highlight]'
        );

    const linkButton =
        form.querySelector(
            '[data-editor-link]'
        );

    let selectedUploads = [];
    let savedRange = null;
    let selectedImage = null;

    try {
        document.execCommand(
            'styleWithCSS',
            false,
            true
        );
    } catch (error) {
        // Formatting still works without styleWithCSS support.
    }

    const escapeHtml = (value) =>
        String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');

    const saveSelection = () => {
        const selection =
            window.getSelection();

        if (
            !selection
            || selection.rangeCount < 1
        ) {
            return;
        }

        const range =
            selection.getRangeAt(0);

        if (
            editor.contains(
                range.commonAncestorContainer
            )
            || range.commonAncestorContainer
                === editor
        ) {
            savedRange =
                range.cloneRange();
        }
    };

    const restoreSelection = () => {
        if (!savedRange) {
            return;
        }

        /*
         * Clone the author's selection before returning focus to the
         * contenteditable. Chrome can collapse the live selection when the
         * toolbar/color control takes focus. Restoring from this private copy
         * keeps formatting attached to the highlighted text.
         */
        const rangeToRestore =
            savedRange.cloneRange();

        try {
            editor.focus({ preventScroll: true });
        } catch (error) {
            editor.focus();
        }

        const selection =
            window.getSelection();

        if (!selection) {
            return;
        }

        selection.removeAllRanges();
        selection.addRange(
            rangeToRestore
        );
    };

    const updateCounts = () => {
        const plain =
            editor.textContent
                .replace(/\u00a0/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();

        const words =
            plain === ''
                ? 0
                : plain
                    .split(/\s+/u)
                    .filter(Boolean)
                    .length;

        if (wordCount) {
            wordCount.textContent =
                `${words} ${words === 1 ? 'word' : 'words'}`;
        }

        if (characterCount) {
            characterCount.textContent =
                `${plain.length} ${plain.length === 1 ? 'character' : 'characters'}`;
        }
    };

    const syncInput = () => {
        const clone =
            editor.cloneNode(true);

        clone.querySelectorAll(
            'img[data-upload-token]'
        ).forEach(
            (image) => {
                const token =
                    image.getAttribute(
                        'data-upload-token'
                    );

                if (token) {
                    image.setAttribute(
                        'src',
                        '/__blackthorne_pending_lesson_image_'
                        + token
                        + '__'
                    );
                }

                image.removeAttribute(
                    'data-upload-token'
                );

                image.removeAttribute(
                    'data-editor-selected-image'
                );
            }
        );

        input.value =
            clone.innerHTML.trim();

        updateCounts();
    };

    const applyBlockAlignment = (alignmentCommand) => {
        const alignmentMap = {
            justifyLeft: 'left',
            justifyCenter: 'center',
            justifyRight: 'right',
        };

        const alignment =
            alignmentMap[
                alignmentCommand
            ]
            ?? '';

        if (alignment === '') {
            return false;
        }

        if (!savedRange) {
            return true;
        }

        /*
         * Do not run a browser alignment command on the live selection.
         * Chrome can merge inline formatting when a selection crosses a
         * heading/paragraph boundary. Instead, identify the selected blocks,
         * apply alignment to a detached clone, then replace the editor HTML.
         * This preserves the exact <strong>, <em>, color, link, etc. markup.
         */
        const range =
            savedRange.cloneRange();

        const blockSelector =
            'p,h1,h2,h3,h4,h5,h6,blockquote,li,div';

        const liveBlocks =
            Array.from(
                editor.querySelectorAll(
                    blockSelector
                )
            );

        let selectedIndexes =
            liveBlocks
                .map(
                    (block, index) => {
                        try {
                            return range.intersectsNode(block)
                                ? index
                                : -1;
                        } catch (error) {
                            return -1;
                        }
                    }
                )
                .filter(
                    (index) => index >= 0
                );

        /*
         * If both an outer DIV and its inner P/H2 are selected, only style
         * the innermost blocks. This avoids wrapping/inheritance surprises.
         */
        selectedIndexes =
            selectedIndexes.filter(
                (index) => {
                    const block =
                        liveBlocks[index];

                    return !selectedIndexes.some(
                        (otherIndex) =>
                            otherIndex !== index
                            && block.contains(
                                liveBlocks[
                                    otherIndex
                                ]
                            )
                    );
                }
            );

        if (selectedIndexes.length === 0) {
            let node =
                range.commonAncestorContainer;

            if (node.nodeType === Node.TEXT_NODE) {
                node =
                    node.parentElement;
            }

            const nearestBlock =
                node instanceof Element
                    ? node.closest(
                        blockSelector
                    )
                    : null;

            if (
                nearestBlock
                && editor.contains(
                    nearestBlock
                )
            ) {
                const index =
                    liveBlocks.indexOf(
                        nearestBlock
                    );

                if (index >= 0) {
                    selectedIndexes = [
                        index,
                    ];
                }
            }
        }

        if (selectedIndexes.length === 0) {
            return true;
        }

        const editorClone =
            editor.cloneNode(true);

        const clonedBlocks =
            Array.from(
                editorClone.querySelectorAll(
                    blockSelector
                )
            );

        selectedIndexes.forEach(
            (index) => {
                const clonedBlock =
                    clonedBlocks[index];

                if (clonedBlock) {
                    clonedBlock.style.textAlign =
                        alignment;
                }
            }
        );

        editor.innerHTML =
            editorClone.innerHTML;

        /*
         * The old Range points at nodes that were just replaced, so discard
         * it. The next mouse/keyboard selection will establish a fresh one.
         */
        savedRange = null;

        syncInput();
        return true;
    };

    const runCommand = (
        command,
        value = null
    ) => {
        if (applyBlockAlignment(command)) {
            return;
        }

        restoreSelection();

        document.execCommand(
            command,
            false,
            value
        );

        syncInput();
        saveSelection();
    };

    form
        .querySelectorAll(
            '[data-command]'
        )
        .forEach(
            (button) => {
                button.addEventListener(
                    'mousedown',
                    (event) => {
                        event.preventDefault();
                    }
                );

                button.addEventListener(
                    'click',
                    () => {
                        runCommand(
                            button.dataset.command
                        );
                    }
                );
            }
        );

    if (formatSelect) {
        formatSelect.addEventListener(
            'change',
            () => {
                if (
                    formatSelect.value !== ''
                ) {
                    runCommand(
                        'formatBlock',
                        formatSelect.value
                    );

                    formatSelect.value = '';
                }
            }
        );
    }

    if (sizeSelect) {
        sizeSelect.addEventListener(
            'change',
            () => {
                if (
                    sizeSelect.value === ''
                ) {
                    return;
                }

                restoreSelection();

                document.execCommand(
                    'fontSize',
                    false,
                    '7'
                );

                editor
                    .querySelectorAll(
                        'font[size="7"]'
                    )
                    .forEach(
                        (node) => {
                            const span =
                                document.createElement(
                                    'span'
                                );

                            span.style.fontSize =
                                sizeSelect.value;

                            while (
                                node.firstChild
                            ) {
                                span.appendChild(
                                    node.firstChild
                                );
                            }

                            node.replaceWith(
                                span
                            );
                        }
                    );

                sizeSelect.value = '';
                syncInput();
            }
        );
    }

    const applyTextColor = () => {
        if (!savedRange || savedRange.collapsed) {
            return;
        }

        runCommand(
            'foreColor',
            colorInput.value
        );
    };

    if (colorInput) {
        colorInput.addEventListener(
            'pointerdown',
            saveSelection
        );

        colorInput.addEventListener(
            'click',
            applyTextColor
        );

        colorInput.addEventListener(
            'input',
            applyTextColor
        );

        colorInput.addEventListener(
            'change',
            applyTextColor
        );
    }

    const applyHighlightColor = () => {
        if (!savedRange || savedRange.collapsed) {
            return;
        }

        restoreSelection();

        document.execCommand(
            document.queryCommandSupported(
                'hiliteColor'
            )
                ? 'hiliteColor'
                : 'backColor',
            false,
            highlightInput.value
        );

        syncInput();
        saveSelection();
    };

    if (highlightInput) {
        highlightInput.addEventListener(
            'pointerdown',
            saveSelection
        );

        highlightInput.addEventListener(
            'click',
            applyHighlightColor
        );

        highlightInput.addEventListener(
            'input',
            applyHighlightColor
        );

        highlightInput.addEventListener(
            'change',
            applyHighlightColor
        );
    }

    if (applyColorButton) {
        applyColorButton.addEventListener(
            'mousedown',
            (event) => {
                event.preventDefault();
            }
        );

        applyColorButton.addEventListener(
            'click',
            applyTextColor
        );
    }

    if (applyHighlightButton) {
        applyHighlightButton.addEventListener(
            'mousedown',
            (event) => {
                event.preventDefault();
            }
        );

        applyHighlightButton.addEventListener(
            'click',
            applyHighlightColor
        );
    }

    if (quoteButton) {
        quoteButton.addEventListener(
            'mousedown',
            (event) => {
                event.preventDefault();
            }
        );

        quoteButton.addEventListener(
            'click',
            () => {
                runCommand(
                    'formatBlock',
                    'blockquote'
                );
            }
        );
    }

    if (linkButton) {
        linkButton.addEventListener(
            'mousedown',
            (event) => {
                event.preventDefault();
            }
        );

        linkButton.addEventListener(
            'click',
            () => {
                saveSelection();

                const value =
                    window.prompt(
                        'Enter an HTTPS/HTTP URL, mailto link, #anchor, or site-relative path.'
                    );

                if (
                    value === null
                    || value.trim() === ''
                ) {
                    return;
                }

                runCommand(
                    'createLink',
                    value.trim()
                );
            }
        );
    }

    const insertHtml = (html) => {
        restoreSelection();

        document.execCommand(
            'insertHTML',
            false,
            html
        );

        syncInput();
        saveSelection();
    };

    editor.addEventListener(
        'click',
        (event) => {
            const target =
                event.target;

            editor.querySelectorAll(
                'img[data-editor-selected-image="1"]'
            ).forEach(
                (image) => {
                    image.removeAttribute(
                        'data-editor-selected-image'
                    );
                }
            );

            if (
                target instanceof HTMLImageElement
                && editor.contains(target)
            ) {
                selectedImage =
                    target;

                selectedImage.setAttribute(
                    'data-editor-selected-image',
                    '1'
                );

                if (imageSizeSelect) {
                    const currentWidth =
                        selectedImage.style.width
                        || '100%';

                    const hasOption =
                        Array.from(
                            imageSizeSelect.options
                        ).some(
                            (option) =>
                                option.value === currentWidth
                        );

                    imageSizeSelect.value =
                        hasOption
                            ? currentWidth
                            : '';
                }

                if (imageAlignSelect) {
                    const marginLeft =
                        selectedImage.style.marginLeft;

                    const marginRight =
                        selectedImage.style.marginRight;

                    if (
                        marginLeft === '0px'
                        && marginRight === 'auto'
                    ) {
                        imageAlignSelect.value = 'left';
                    } else if (
                        marginLeft === 'auto'
                        && marginRight === '0px'
                    ) {
                        imageAlignSelect.value = 'right';
                    } else {
                        imageAlignSelect.value = 'center';
                    }
                }

                return;
            }

            selectedImage = null;

            if (imageSizeSelect) {
                imageSizeSelect.value = '';
            }

            if (imageAlignSelect) {
                imageAlignSelect.value = '';
            }
        }
    );

    if (imageSizeSelect) {
        imageSizeSelect.addEventListener(
            'change',
            () => {
                if (
                    !selectedImage
                    || !editor.contains(selectedImage)
                ) {
                    window.alert(
                        'Click an image in the lesson first, then choose its size.'
                    );

                    imageSizeSelect.value = '';
                    return;
                }

                const size =
                    imageSizeSelect.value;

                if (size === '') {
                    return;
                }

                selectedImage.style.width =
                    size;

                selectedImage.style.maxWidth =
                    '100%';

                syncInput();
            }
        );
    }

    if (imageAlignSelect) {
        imageAlignSelect.addEventListener(
            'change',
            () => {
                if (
                    !selectedImage
                    || !editor.contains(selectedImage)
                ) {
                    window.alert(
                        'Click an image in the lesson first, then choose its alignment.'
                    );

                    imageAlignSelect.value = '';
                    return;
                }

                const alignment =
                    imageAlignSelect.value;

                if (alignment === '') {
                    return;
                }

                if (alignment === 'left') {
                    selectedImage.style.marginLeft =
                        '0';

                    selectedImage.style.marginRight =
                        'auto';
                } else if (alignment === 'right') {
                    selectedImage.style.marginLeft =
                        'auto';

                    selectedImage.style.marginRight =
                        '0';
                } else {
                    selectedImage.style.marginLeft =
                        'auto';

                    selectedImage.style.marginRight =
                        'auto';
                }

                syncInput();
            }
        );
    }

    if (imageUrlButton) {
        imageUrlButton.addEventListener(
            'mousedown',
            (event) => {
                event.preventDefault();
            }
        );

        imageUrlButton.addEventListener(
            'click',
            () => {
                saveSelection();

                const src =
                    window.prompt(
                        'Enter an HTTPS image URL.'
                    );

                if (
                    src === null
                    || !/^https:\/\//i.test(
                        src.trim()
                    )
                ) {
                    return;
                }

                const alt =
                    window.prompt(
                        'Enter image alt text.',
                        ''
                    )
                    ?? '';

                insertHtml(
                    '<img src="'
                    + escapeHtml(
                        src.trim()
                    )
                    + '" alt="'
                    + escapeHtml(
                        alt
                    )
                    + '" loading="lazy" style="display:block;margin-left:auto;margin-right:auto;max-width:100%;height:auto;">'
                );
            }
        );
    }

    const makeToken = () => {
        if (
            window.crypto
            && window.crypto.getRandomValues
        ) {
            const values =
                new Uint32Array(4);

            window.crypto.getRandomValues(
                values
            );

            return Array.from(
                values,
                (value) =>
                    value.toString(36)
            ).join('');
        }

        return (
            Date.now().toString(36)
            + Math.random()
                .toString(36)
                .slice(2)
        );
    };

    const syncUploadFiles = () => {
        if (
            !imageInput
            || !uploadTokensInput
        ) {
            return;
        }

        const transfer =
            new DataTransfer();

        selectedUploads.forEach(
            (item) => {
                transfer.items.add(
                    item.file
                );
            }
        );

        imageInput.files =
            transfer.files;

        uploadTokensInput.value =
            JSON.stringify(
                selectedUploads.map(
                    (item) =>
                        item.token
                )
            );
    };

    const renderUploadPreviews = () => {
        if (!imagePreviews) {
            return;
        }

        imagePreviews.innerHTML = '';

        if (
            selectedUploads.length === 0
        ) {
            imagePreviews.hidden =
                true;

            return;
        }

        imagePreviews.hidden =
            false;

        selectedUploads.forEach(
            (item) => {
                const row =
                    document.createElement(
                        'div'
                    );

                row.className =
                    'forum-editor-image-preview';

                const label =
                    document.createElement(
                        'span'
                    );

                label.textContent =
                    item.file.name;

                const remove =
                    document.createElement(
                        'button'
                    );

                remove.type =
                    'button';

                remove.className =
                    'button-link';

                remove.textContent =
                    'Remove';

                remove.addEventListener(
                    'click',
                    () => {
                        const removedUpload =
                            selectedUploads.find(
                                (candidate) =>
                                    candidate.token
                                    === item.token
                            );

                        if (
                            removedUpload
                            && removedUpload.previewUrl
                        ) {
                            URL.revokeObjectURL(
                                removedUpload.previewUrl
                            );
                        }

                        selectedUploads =
                            selectedUploads.filter(
                                (candidate) =>
                                    candidate.token
                                    !== item.token
                            );

                        editor
                            .querySelectorAll(
                                'img[data-upload-token]'
                            )
                            .forEach(
                                (img) => {
                                    if (
                                        img.getAttribute(
                                            'data-upload-token'
                                        ) === item.token
                                    ) {
                                        img.remove();
                                    }
                                }
                            );

                        if (
                            selectedImage
                            && !editor.contains(
                                selectedImage
                            )
                        ) {
                            selectedImage = null;
                        }

                        syncUploadFiles();
                        renderUploadPreviews();
                        syncInput();
                    }
                );

                row.append(
                    label,
                    remove
                );

                imagePreviews.appendChild(
                    row
                );
            }
        );
    };

    if (
        imageUploadButton
        && imageInput
    ) {
        imageUploadButton.addEventListener(
            'mousedown',
            (event) => {
                event.preventDefault();
            }
        );

        imageUploadButton.addEventListener(
            'click',
            () => {
                saveSelection();
                imageInput.click();
            }
        );

        imageInput.addEventListener(
            'change',
            () => {
                const incoming =
                    Array.from(
                        imageInput.files
                        ?? []
                    );

                if (
                    incoming.length
                    + selectedUploads.length
                    > 10
                ) {
                    window.alert(
                        'You can upload up to 10 inline lesson images at a time.'
                    );

                    imageInput.value = '';
                    return;
                }

                incoming.forEach(
                    (file) => {
                        if (
                            file.size
                            > 10 * 1024 * 1024
                        ) {
                            window.alert(
                                `${file.name} is larger than 10 MB.`
                            );

                            return;
                        }

                        const token =
                            makeToken();

                        const previewUrl =
                            URL.createObjectURL(
                                file
                            );

                        selectedUploads.push({
                            file,
                            token,
                            previewUrl,
                        });

                        const alt =
                            file.name
                                .replace(
                                    /\.[^.]+$/,
                                    ''
                                )
                                .replace(
                                    /[-_]+/g,
                                    ' '
                                )
                                .trim();

                        insertHtml(
                            '<img src="'
                            + escapeHtml(
                                previewUrl
                            )
                            + '" alt="'
                            + escapeHtml(
                                alt
                            )
                            + '" data-upload-token="'
                            + escapeHtml(
                                token
                            )
                            + '" loading="lazy" style="display:block;margin-left:auto;margin-right:auto;max-width:100%;height:auto;">'
                        );
                    }
                );

                syncUploadFiles();
                renderUploadPreviews();
            }
        );
    }

    [
        'keyup',
        'mouseup',
        'input',
    ].forEach(
        (eventName) => {
            editor.addEventListener(
                eventName,
                () => {
                    saveSelection();
                    syncInput();
                }
            );
        }
    );

    editor.addEventListener(
        'paste',
        () => {
            window.setTimeout(
                syncInput,
                0
            );
        }
    );

    form.addEventListener(
        'submit',
        () => {
            syncInput();
            syncUploadFiles();
        }
    );

    window.addEventListener(
        'beforeunload',
        () => {
            selectedUploads.forEach(
                (upload) => {
                    if (upload.previewUrl) {
                        URL.revokeObjectURL(
                            upload.previewUrl
                        );
                    }
                }
            );
        }
    );

    syncInput();
})();
</script>


<style>
.forum-rich-editor-surface img[data-editor-selected-image="1"] {
    outline: 2px solid var(--gold, #c7a45b);
    outline-offset: 3px;
}
</style>


<style>
/*
|--------------------------------------------------------------------------
| Rich Editor Working Area
|--------------------------------------------------------------------------
|
| Keep the editor at a practical default height so long content scrolls
| inside the writing area while the formatting toolbar stays accessible.
| The lower edge can still be dragged vertically when more room is useful.
|
*/

.forum-rich-editor {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.forum-rich-editor-toolbar {
    position: relative;
    z-index: 3;
    flex: 0 0 auto;
}

.forum-rich-editor-surface {
    box-sizing: border-box;
    width: 100%;
    height: 420px;
    min-height: 260px;
    max-height: 78vh;
    overflow-x: auto;
    overflow-y: auto;
    resize: vertical;
    overscroll-behavior: contain;
    scrollbar-gutter: stable;
}

.forum-rich-editor-surface:focus {
    overflow-y: auto;
}

@media (max-width: 720px) {
    .forum-rich-editor-surface {
        height: 340px;
        min-height: 220px;
        max-height: 70vh;
    }
}
</style>


<?php

require INCLUDES_PATH . '/footer.php';
