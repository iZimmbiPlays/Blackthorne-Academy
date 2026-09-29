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

$canCreateLessons =
    $isProtectedSuperAdmin
    || user_can('lessons.create');

$canEditLessons =
    $isProtectedSuperAdmin
    || user_can('lessons.edit');

$canPublishLessons =
    $isProtectedSuperAdmin
    || user_can('lessons.publish');

$canManageRelease =
    $isProtectedSuperAdmin
    || user_can('lessons.release.manage');

$canAccessLessons =
    $canCreateLessons
    || $canEditLessons
    || $canPublishLessons
    || $canManageRelease;

if (
    !$hasStaffIdentity
    || !$canAccessLessons
) {
    http_response_code(403);

    $pageTitle =
        'Access Denied | Blackthorne Academy';

    $pageDescription =
        'You do not have permission to manage lessons.';

    $pageCanonical =
        url('admin/courses.php');

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
                Your account does not have permission to manage lessons.
            </p>

            <a class="button button-secondary" href="<?= e(url('admin/courses.php')); ?>">
                Return to Courses
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

function lessons_post_string(
    string $key
): string {
    $value =
        $_POST[$key]
        ?? '';

    return is_scalar($value)
        ? trim((string) $value)
        : '';
}


function lessons_post_id(
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


function lessons_post_uint(
    string $key,
    int $default = 0
): int {
    $value =
        lessons_post_string($key);

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


function lessons_slug_base(
    string $value
): string {
    $value =
        trim($value);

    if ($value === '') {
        return '';
    }

    if (function_exists('iconv')) {
        $ascii =
            @iconv(
                'UTF-8',
                'ASCII//TRANSLIT//IGNORE',
                $value
            );

        if (
            is_string($ascii)
            && $ascii !== ''
        ) {
            $value = $ascii;
        }
    }

    $value =
        strtolower($value);

    $value =
        preg_replace(
            '/[^a-z0-9]+/',
            '-',
            $value
        ) ?? '';

    return trim(
        $value,
        '-'
    );
}


function lessons_unique_slug(
    PDO $pdo,
    int $courseId,
    string $title
): string {
    $base =
        lessons_slug_base(
            $title
        );

    if ($base === '') {
        $base =
            'lesson-'
            . bin2hex(
                random_bytes(4)
            );
    }

    $base =
        substr(
            $base,
            0,
            205
        );

    $candidate =
        $base;

    $suffix = 2;

    while (true) {
        $statement =
            $pdo->prepare(
                '
                SELECT id

                FROM lessons

                WHERE course_id = :course_id
                  AND slug = :slug

                LIMIT 1
                '
            );

        $statement->execute([
            'course_id' =>
                $courseId,

            'slug' =>
                $candidate,
        ]);

        if (
            $statement->fetchColumn()
            === false
        ) {
            return $candidate;
        }

        $candidate =
            substr(
                $base,
                0,
                200
            )
            . '-'
            . $suffix;

        $suffix++;
    }
}


function lessons_normalize_datetime(
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


function lessons_format_datetime(
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


function lessons_release_label(
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
            . lessons_format_datetime(
                $row['release_at']
                ?? null
            );
    }

    if ($type === 'after_previous_lesson') {
        $previousTitle =
            trim(
                (string) (
                    $row[
                        'prerequisite_lesson_title'
                    ]
                    ?? ''
                )
            );

        return $previousTitle !== ''
            ? 'After: ' . $previousTitle
            : 'After previous lesson';
    }

    if ($type === 'days_after_enrollment') {
        return
            'Legacy enrollment-based release';
    }

    return 'Immediate';
}


function lessons_audit(
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
            'Lesson audit error: '
            . $exception->getMessage()
        );
    }
}


/*
|--------------------------------------------------------------------------
| Offering Selection
|--------------------------------------------------------------------------
*/

$selectedOfferingId =
    filter_input(
        INPUT_GET,
        'offering',
        FILTER_VALIDATE_INT
    );

if (
    !is_int($selectedOfferingId)
    || $selectedOfferingId <= 0
) {
    $selectedOfferingId = 0;
}

$offerings =
    $pdo->query(
        '
        SELECT
            co.id,
            co.course_id,
            co.offering_scope,
            co.school_year_id,
            co.pacing_mode,
            co.drip_basis,
            co.course_start_date,
            co.course_end_date,
            co.status,
            c.title AS course_title,
            c.status AS course_status,
            sy.name AS school_year_name,
            GROUP_CONCAT(
                DISTINCT yg.name
                ORDER BY yg.sort_order ASC, yg.year_number ASC
                SEPARATOR ", "
            ) AS year_group_names

        FROM course_offerings co

        INNER JOIN courses c
            ON c.id = co.course_id

        LEFT JOIN school_years sy
            ON sy.id = co.school_year_id

        LEFT JOIN course_offering_year_groups coyg
            ON coyg.offering_id = co.id

        LEFT JOIN year_groups yg
            ON yg.id = coyg.year_group_id

        WHERE co.status <> "archived"

        GROUP BY
            co.id,
            co.course_id,
            co.offering_scope,
            co.school_year_id,
            co.pacing_mode,
            co.drip_basis,
            co.course_start_date,
            co.course_end_date,
            co.status,
            c.title,
            c.status,
            sy.name

        ORDER BY
            CASE
                WHEN co.offering_scope = "perpetual"
                THEN 0
                ELSE 1
            END,
            sy.start_date DESC,
            c.title ASC,
            co.id DESC
        '
    )->fetchAll(
        PDO::FETCH_ASSOC
    );

$selectedOffering = null;

foreach ($offerings as $offeringRow) {
    if (
        (int) (
            $offeringRow['id']
            ?? 0
        ) === $selectedOfferingId
    ) {
        $selectedOffering =
            $offeringRow;

        break;
    }
}


/*
|--------------------------------------------------------------------------
| Existing Offering Lessons
|--------------------------------------------------------------------------
*/

$offeringLessons = [];

if ($selectedOffering !== null) {
    $lessonStatement =
        $pdo->prepare(
            '
            SELECT
                col.id AS offering_lesson_id,
                col.sort_order,
                l.id AS lesson_id,
                l.internal_name,
                l.slug,
                l.is_published,
                lv.id AS lesson_version_id,
                lv.version_number,
                lv.title,
                lv.description,
                lv.status AS version_status,
                lv.published_at,
                lrr.is_enabled AS release_enabled,
                lrr.release_type,
                lrr.release_delay_days,
                lrr.release_at,
                lrr.prerequisite_lesson_id,
                plv.title AS prerequisite_lesson_title

            FROM course_offering_lessons col

            INNER JOIN lessons l
                ON l.id = col.lesson_id

            LEFT JOIN lesson_versions lv
                ON lv.id = col.lesson_version_id

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

            ORDER BY
                col.sort_order ASC,
                col.id ASC
            '
        );

    $lessonStatement->execute([
        'offering_id' =>
            $selectedOfferingId,
    ]);

    $offeringLessons =
        $lessonStatement->fetchAll(
            PDO::FETCH_ASSOC
        );
}



/*
|--------------------------------------------------------------------------
| Lesson Image / Rich-Text Upload Helpers
|--------------------------------------------------------------------------
*/

function lessons_normalize_upload_files(array $files): array
{
    if (!isset($files['name']) || !is_array($files['name'])) {
        return [];
    }

    $normalized = [];

    foreach ($files['name'] as $index => $name) {
        $normalized[] = [
            'name' => (string) $name,
            'tmp_name' => (string) ($files['tmp_name'][$index] ?? ''),
            'error' => (int) ($files['error'][$index] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($files['size'][$index] ?? 0),
        ];
    }

    return $normalized;
}


function lessons_validate_image_upload(array $file, int $maxBytes = 10485760): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'empty' => true];
    }

    if ($error !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'The selected image could not be uploaded.'];
    }

    $size = (int) ($file['size'] ?? 0);

    if ($size < 1 || $size > $maxBytes) {
        return ['ok' => false, 'error' => 'Lesson images must be 10 MB or smaller.'];
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');

    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return ['ok' => false, 'error' => 'The selected image was not received as a valid upload.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmpName);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];

    if (!isset($allowed[$mime]) || @getimagesize($tmpName) === false) {
        return [
            'ok' => false,
            'error' => 'Lesson images must be JPG, PNG, GIF, WEBP, or AVIF files.',
        ];
    }

    return [
        'ok' => true,
        'empty' => false,
        'tmp_name' => $tmpName,
        'mime' => $mime,
        'extension' => $allowed[$mime],
        'name' => mb_substr(basename((string) ($file['name'] ?? 'image')), 0, 255, 'UTF-8'),
    ];
}


function lessons_generate_webp(string $absolutePath, string $mime): ?string
{
    if ($mime === 'image/webp' || !function_exists('imagewebp')) {
        return null;
    }

    $image = null;

    if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
        $image = @imagecreatefromjpeg($absolutePath);
    } elseif ($mime === 'image/png' && function_exists('imagecreatefrompng')) {
        $image = @imagecreatefrompng($absolutePath);

        if ($image !== false) {
            imagealphablending($image, true);
            imagesavealpha($image, true);
        }
    } elseif ($mime === 'image/gif' && function_exists('imagecreatefromgif')) {
        $image = @imagecreatefromgif($absolutePath);
    } elseif ($mime === 'image/avif' && function_exists('imagecreatefromavif')) {
        $image = @imagecreatefromavif($absolutePath);
    }

    if ($image === false || $image === null) {
        return null;
    }

    $webpPath = preg_replace('/\.[^.]+$/', '.webp', $absolutePath);

    if (!is_string($webpPath) || $webpPath === '' || $webpPath === $absolutePath) {
        imagedestroy($image);
        return null;
    }

    $saved = @imagewebp($image, $webpPath, 86);
    imagedestroy($image);

    if (!$saved || !is_file($webpPath) || (int) @filesize($webpPath) <= 0) {
        if (is_file($webpPath)) {
            @unlink($webpPath);
        }

        return null;
    }

    return $webpPath;
}


function lessons_store_image_upload(
    array $validatedUpload,
    int $courseId,
    int $lessonId,
    string $prefix
): array {
    $relativeDirectory = 'lessons/' . $courseId . '/' . $lessonId;
    $absoluteDirectory =
        rtrim(UPLOADS_PATH, '/\\')
        . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);

    if (
        !is_dir($absoluteDirectory)
        && !mkdir($absoluteDirectory, 0755, true)
        && !is_dir($absoluteDirectory)
    ) {
        throw new RuntimeException('The lesson upload directory could not be created.');
    }

    $filename =
        $prefix
        . '-'
        . bin2hex(random_bytes(12))
        . '.'
        . $validatedUpload['extension'];

    $relativePath = $relativeDirectory . '/' . $filename;
    $absolutePath = $absoluteDirectory . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($validatedUpload['tmp_name'], $absolutePath)) {
        throw new RuntimeException('The lesson image could not be saved.');
    }

    $webpAbsolute = lessons_generate_webp(
        $absolutePath,
        (string) $validatedUpload['mime']
    );

    return [
        'relative_path' => $relativePath,
        'absolute_path' => $absolutePath,
        'webp_absolute' => $webpAbsolute,
    ];
}


function lessons_remove_upload_paths(array $paths): void
{
    foreach ($paths as $path) {
        if (is_string($path) && $path !== '' && is_file($path)) {
            @unlink($path);
        }
    }
}


function lessons_remove_directory(string $directory): void
{
    $directory =
        rtrim(
            $directory,
            '/\\'
        );

    if (
        $directory === ''
        || !is_dir($directory)
    ) {
        return;
    }

    $items =
        scandir(
            $directory
        );

    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if (
            $item === '.'
            || $item === '..'
        ) {
            continue;
        }

        $path =
            $directory
            . DIRECTORY_SEPARATOR
            . $item;

        if (is_dir($path)) {
            lessons_remove_directory(
                $path
            );
        } else {
            @unlink(
                $path
            );
        }
    }

    @rmdir(
        $directory
    );
}

/*
|--------------------------------------------------------------------------
| Form State
|--------------------------------------------------------------------------
*/

$errors = [];

$form = [
    'title' => '',
    'description' => '',
    'content' => '',
    'sort_order' =>
        (string) (
            count($offeringLessons) + 1
        ),
    'status' => 'draft',
    'release_type' => 'immediate',
    'release_delay_days' => '',
    'release_at' => '',
    'prerequisite_lesson_id' => '',
];


/*
|--------------------------------------------------------------------------
| Create Lesson
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
        lessons_post_string(
            'action'
        );


    /*
    |--------------------------------------------------------------------------
    | Delete Lesson
    |--------------------------------------------------------------------------
    |
    | A lesson is a course-level record that may be attached to one or more
    | offerings. Deleting it permanently removes the lesson, its versions,
    | release rules, and offering placements. To protect academic history,
    | deletion is blocked once student progress exists or an assignment/quiz
    | is attached to one of the lesson's offering placements.
    |
    */

    if (
        $errors === []
        && $action === 'delete_lesson'
    ) {
        if (!$canEditLessons) {
            $errors[] =
                'You do not have permission to delete lessons.';
        }

        $postedOfferingId =
            lessons_post_id(
                'offering_id'
            );

        $lessonId =
            lessons_post_id(
                'lesson_id'
            );

        if (
            $postedOfferingId <= 0
            || $postedOfferingId !== $selectedOfferingId
            || $selectedOffering === null
        ) {
            $errors[] =
                'The selected course offering is invalid.';
        }

        if ($lessonId <= 0) {
            $errors[] =
                'The selected lesson is invalid.';
        }

        $lessonToDelete = null;

        if ($errors === []) {
            $lessonLookup =
                $pdo->prepare(
                    '
                    SELECT
                        l.id,
                        l.course_id,
                        l.internal_name,
                        COALESCE(
                            (
                                SELECT lv.title
                                FROM lesson_versions lv
                                WHERE lv.lesson_id = l.id
                                ORDER BY
                                    lv.version_number DESC,
                                    lv.id DESC
                                LIMIT 1
                            ),
                            l.internal_name
                        ) AS lesson_title

                    FROM lessons l

                    INNER JOIN course_offering_lessons col
                        ON col.lesson_id = l.id

                    WHERE l.id = :lesson_id
                      AND col.offering_id = :offering_id
                      AND l.course_id = :course_id

                    LIMIT 1
                    '
                );

            $lessonLookup->execute([
                'lesson_id' =>
                    $lessonId,

                'offering_id' =>
                    $selectedOfferingId,

                'course_id' =>
                    (int) (
                        $selectedOffering[
                            'course_id'
                        ]
                        ?? 0
                    ),
            ]);

            $lessonToDelete =
                $lessonLookup->fetch(
                    PDO::FETCH_ASSOC
                );

            if (!$lessonToDelete) {
                $errors[] =
                    'The selected lesson could not be found in this course offering.';
            }
        }

        if (
            $errors === []
            && $lessonToDelete !== null
        ) {
            $dependencyStatement =
                $pdo->prepare(
                    '
                    SELECT
                        (
                            SELECT COUNT(*)
                            FROM lesson_progress lp
                            WHERE lp.lesson_id = :progress_lesson_id
                        ) AS progress_count,

                        (
                            SELECT COUNT(*)
                            FROM assignment_offering_settings aos
                            INNER JOIN course_offering_lessons col_a
                                ON col_a.id = aos.course_offering_lesson_id
                            WHERE col_a.lesson_id = :assignment_lesson_id
                        ) AS assignment_count,

                        (
                            SELECT COUNT(*)
                            FROM quiz_offering_settings qos
                            INNER JOIN course_offering_lessons col_q
                                ON col_q.id = qos.course_offering_lesson_id
                            WHERE col_q.lesson_id = :quiz_lesson_id
                        ) AS quiz_count
                    '
                );

            $dependencyStatement->execute([
                'progress_lesson_id' =>
                    $lessonId,

                'assignment_lesson_id' =>
                    $lessonId,

                'quiz_lesson_id' =>
                    $lessonId,
            ]);

            $dependencies =
                $dependencyStatement->fetch(
                    PDO::FETCH_ASSOC
                )
                ?: [];

            $progressCount =
                (int) (
                    $dependencies[
                        'progress_count'
                    ]
                    ?? 0
                );

            $assignmentCount =
                (int) (
                    $dependencies[
                        'assignment_count'
                    ]
                    ?? 0
                );

            $quizCount =
                (int) (
                    $dependencies[
                        'quiz_count'
                    ]
                    ?? 0
                );

            if (
                $progressCount > 0
                || $assignmentCount > 0
                || $quizCount > 0
            ) {
                $dependencyParts = [];

                if ($progressCount > 0) {
                    $dependencyParts[] =
                        number_format(
                            $progressCount
                        )
                        . ' student progress record'
                        . (
                            $progressCount === 1
                                ? ''
                                : 's'
                        );
                }

                if ($assignmentCount > 0) {
                    $dependencyParts[] =
                        number_format(
                            $assignmentCount
                        )
                        . ' linked assignment'
                        . (
                            $assignmentCount === 1
                                ? ''
                                : 's'
                        );
                }

                if ($quizCount > 0) {
                    $dependencyParts[] =
                        number_format(
                            $quizCount
                        )
                        . ' linked assessment'
                        . (
                            $quizCount === 1
                                ? ''
                                : 's'
                        );
                }

                $errors[] =
                    'This lesson cannot be permanently deleted because it has '
                    . implode(
                        ', ',
                        $dependencyParts
                    )
                    . '. Remove those dependencies first or keep the lesson for academic history.';
            }
        }

        if (
            $errors === []
            && $lessonToDelete !== null
        ) {
            try {
                $pdo->beginTransaction();

                /*
                 * Other lessons may use this lesson as a prerequisite. Clear
                 * those prerequisite references before removing the lesson.
                 */
                $clearPrerequisite =
                    $pdo->prepare(
                        '
                        UPDATE lesson_release_rules
                        SET prerequisite_lesson_id = NULL
                        WHERE prerequisite_lesson_id = :lesson_id
                        '
                    );

                $clearPrerequisite->execute([
                    'lesson_id' =>
                        $lessonId,
                ]);

                $deleteReleaseRules =
                    $pdo->prepare(
                        '
                        DELETE FROM lesson_release_rules
                        WHERE lesson_id = :lesson_id
                        '
                    );

                $deleteReleaseRules->execute([
                    'lesson_id' =>
                        $lessonId,
                ]);

                /*
                 * Remove every offering placement for this stable lesson.
                 * Dependency checks above prevent deleting placements that
                 * are still attached to assignments or assessments.
                 */
                $deleteOfferingLessons =
                    $pdo->prepare(
                        '
                        DELETE FROM course_offering_lessons
                        WHERE lesson_id = :lesson_id
                        '
                    );

                $deleteOfferingLessons->execute([
                    'lesson_id' =>
                        $lessonId,
                ]);

                $deleteVersions =
                    $pdo->prepare(
                        '
                        DELETE FROM lesson_versions
                        WHERE lesson_id = :lesson_id
                        '
                    );

                $deleteVersions->execute([
                    'lesson_id' =>
                        $lessonId,
                ]);

                $deleteLesson =
                    $pdo->prepare(
                        '
                        DELETE FROM lessons
                        WHERE id = :lesson_id
                        LIMIT 1
                        '
                    );

                $deleteLesson->execute([
                    'lesson_id' =>
                        $lessonId,
                ]);

                if (
                    $deleteLesson->rowCount()
                    !== 1
                ) {
                    throw new RuntimeException(
                        'The lesson record was not deleted.'
                    );
                }

                lessons_audit(
                    $pdo,
                    $currentUserId,
                    $lessonId,
                    'lesson.delete',
                    'Permanently deleted lesson "'
                    . (string) (
                        $lessonToDelete[
                            'lesson_title'
                        ]
                        ?? $lessonToDelete[
                            'internal_name'
                        ]
                        ?? 'Lesson'
                    )
                    . '".'
                );

                $pdo->commit();

                /*
                 * Database deletion is complete before touching files. A file
                 * cleanup failure should never roll back an otherwise valid
                 * lesson deletion.
                 */
                $lessonUploadDirectory =
                    rtrim(
                        UPLOADS_PATH,
                        '/\\'
                    )
                    . DIRECTORY_SEPARATOR
                    . 'lessons'
                    . DIRECTORY_SEPARATOR
                    . (int) (
                        $lessonToDelete[
                            'course_id'
                        ]
                        ?? 0
                    )
                    . DIRECTORY_SEPARATOR
                    . $lessonId;

                lessons_remove_directory(
                    $lessonUploadDirectory
                );

                set_flash(
                    'success',
                    'Lesson deleted permanently.'
                );

                redirect(
                    url(
                        'admin/lessons.php?offering='
                        . $selectedOfferingId
                    )
                );
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'Lesson delete error: '
                    . $exception->getMessage()
                );

                $errors[] =
                    'The lesson could not be deleted. No changes were saved.';
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Reorder Lessons
    |--------------------------------------------------------------------------
    */

    if (
        $errors === []
        && in_array(
            $action,
            [
                'move_lesson_up',
                'move_lesson_down',
            ],
            true
        )
    ) {
        if (!$canEditLessons) {
            $errors[] =
                'You do not have permission to reorder lessons.';
        }

        $postedOfferingId =
            lessons_post_id(
                'offering_id'
            );

        $offeringLessonId =
            lessons_post_id(
                'offering_lesson_id'
            );

        if (
            $postedOfferingId <= 0
            || $postedOfferingId !== $selectedOfferingId
            || $selectedOffering === null
        ) {
            $errors[] =
                'The selected course offering is invalid.';
        }

        $currentIndex = null;

        if ($errors === []) {
            foreach (
                $offeringLessons
                as $index => $existingLesson
            ) {
                if (
                    (int) (
                        $existingLesson[
                            'offering_lesson_id'
                        ]
                        ?? 0
                    ) === $offeringLessonId
                ) {
                    $currentIndex =
                        (int) $index;

                    break;
                }
            }

            if ($currentIndex === null) {
                $errors[] =
                    'The selected lesson could not be found in this offering.';
            }
        }

        if (
            $errors === []
            && $currentIndex !== null
        ) {
            $targetIndex =
                $action === 'move_lesson_up'
                    ? $currentIndex - 1
                    : $currentIndex + 1;

            if (
                $targetIndex < 0
                || $targetIndex >= count(
                    $offeringLessons
                )
            ) {
                redirect(
                    url(
                        'admin/lessons.php?offering='
                        . $selectedOfferingId
                    )
                );
            }

            $reorderedLessons =
                $offeringLessons;

            $movingLesson =
                $reorderedLessons[
                    $currentIndex
                ];

            $reorderedLessons[
                $currentIndex
            ] =
                $reorderedLessons[
                    $targetIndex
                ];

            $reorderedLessons[
                $targetIndex
            ] =
                $movingLesson;

            try {
                $pdo->beginTransaction();

                $updateOrderStatement =
                    $pdo->prepare(
                        '
                        UPDATE course_offering_lessons

                        SET sort_order = :sort_order

                        WHERE id = :id
                          AND offering_id = :offering_id
                        '
                    );

                foreach (
                    $reorderedLessons
                    as $index => $lessonOrderRow
                ) {
                    $updateOrderStatement->execute([
                        'sort_order' =>
                            $index + 1,

                        'id' =>
                            (int) (
                                $lessonOrderRow[
                                    'offering_lesson_id'
                                ]
                                ?? 0
                            ),

                        'offering_id' =>
                            $selectedOfferingId,
                    ]);
                }

                lessons_audit(
                    $pdo,
                    $currentUserId,
                    (int) (
                        $movingLesson[
                            'lesson_id'
                        ]
                        ?? 0
                    ),
                    'lesson.reorder',
                    (
                        $action === 'move_lesson_up'
                            ? 'Moved lesson up'
                            : 'Moved lesson down'
                    )
                    . ' in offering #'
                    . $selectedOfferingId
                );

                $pdo->commit();

                set_flash(
                    'success',
                    'Lesson order updated successfully.'
                );

                redirect(
                    url(
                        'admin/lessons.php?offering='
                        . $selectedOfferingId
                    )
                );
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'Lesson reorder error: '
                    . $exception->getMessage()
                );

                $errors[] =
                    'The lesson order could not be updated.';
            }
        }
    }


    if (
        $errors === []
        && $action === 'create_lesson'
    ) {
        if (!$canCreateLessons) {
            $errors[] =
                'You do not have permission to create lessons.';
        }

        $postedOfferingId =
            lessons_post_id(
                'offering_id'
            );

        if (
            $postedOfferingId <= 0
            || $postedOfferingId !== $selectedOfferingId
            || $selectedOffering === null
        ) {
            $errors[] =
                'Select a valid course offering before creating a lesson.';
        }

        $form['title'] =
            lessons_post_string(
                'title'
            );

        $form['description'] =
            lessons_post_string(
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

        $form['sort_order'] =
            lessons_post_string(
                'sort_order'
            );

        $form['status'] =
            lessons_post_string(
                'status'
            );

        $form['release_type'] =
            lessons_post_string(
                'release_type'
            );

        $form['release_delay_days'] =
            lessons_post_string(
                'release_delay_days'
            );

        $form['release_at'] =
            lessons_post_string(
                'release_at'
            );

        $form['prerequisite_lesson_id'] =
            lessons_post_string(
                'prerequisite_lesson_id'
            );

        $title =
            $form['title'];

        $sortOrder =
            lessons_post_uint(
                'sort_order',
                count($offeringLessons) + 1
            );

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

        $offeringScope =
            (string) (
                $selectedOffering[
                    'offering_scope'
                ]
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

        $releaseDelayDays =
            $releaseType
                === 'days_after_course_start'
                ? lessons_post_uint(
                    'release_delay_days'
                )
                : null;

        $releaseAt =
            $releaseType
                === 'fixed_date'
                ? lessons_normalize_datetime(
                    $form['release_at']
                )
                : null;

        $prerequisiteLessonId =
            $releaseType
                === 'after_previous_lesson'
                ? lessons_post_id(
                    'prerequisite_lesson_id'
                )
                : 0;

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
            strlen($rawContent) > 1000000
        ) {
            $errors[] =
                'Lesson Content is too large. Shorten the lesson and try again.';
        }

        $coverUpload =
            lessons_validate_image_upload(
                $_FILES['lesson_image']
                ?? []
            );

        if (
            !($coverUpload['ok'] ?? false)
        ) {
            $errors[] =
                (string) (
                    $coverUpload['error']
                    ?? 'The lesson image could not be uploaded.'
                );
        }

        $inlineFiles =
            lessons_normalize_upload_files(
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
            count($inlineFiles) > 10
        ) {
            $errors[] =
                'You can upload up to 10 inline lesson images at a time.';
        }

        $validatedInlineUploads = [];

        if (
            count($uploadTokens)
            !== count($inlineFiles)
        ) {
            if ($inlineFiles !== []) {
                $errors[] =
                    'The selected inline images could not be matched to the lesson editor. Select them again.';
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
                        'One of the selected lesson images has an invalid upload reference.';
                    continue;
                }

                $validated =
                    lessons_validate_image_upload(
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
                'You do not have permission to publish lessons.';
        }

        if (
            $releaseType !== 'immediate'
            && !$canManageRelease
        ) {
            $errors[] =
                'You do not have permission to manage lesson release rules.';
        }

        if (
            $releaseType === 'days_after_course_start'
            && $form['release_delay_days'] === ''
        ) {
            $errors[] =
                'Enter the number of days after the course start date.';
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
            $validPrerequisite = false;

            foreach ($offeringLessons as $existingLesson) {
                if (
                    (int) (
                        $existingLesson['lesson_id']
                        ?? 0
                    ) === $prerequisiteLessonId
                ) {
                    $validPrerequisite = true;
                    break;
                }
            }

            if (!$validPrerequisite) {
                $errors[] =
                    'The prerequisite lesson must belong to this course offering.';
            }
        }

        if (
            $releaseType === 'days_after_course_start'
            && empty(
                $selectedOffering[
                    'course_start_date'
                ]
            )
        ) {
            $errors[] =
                'This offering needs a Course Start Date before a course-start release delay can be used.';
        }

        if ($errors === []) {
            try {
                $pdo->beginTransaction();

                $courseId =
                    (int) (
                        $selectedOffering[
                            'course_id'
                        ]
                        ?? 0
                    );

                $slug =
                    lessons_unique_slug(
                        $pdo,
                        $courseId,
                        $title
                    );

                $lessonPublished =
                    $status === 'published'
                        ? 1
                        : 0;

                $insertLesson =
                    $pdo->prepare(
                        '
                        INSERT INTO lessons (
                            course_id,
                            internal_name,
                            slug,
                            lesson_image,
                            is_published
                        ) VALUES (
                            :course_id,
                            :internal_name,
                            :slug,
                            NULL,
                            :is_published
                        )
                        '
                    );

                $insertLesson->execute([
                    'course_id' =>
                        $courseId,

                    'internal_name' =>
                        $title,

                    'slug' =>
                        $slug,

                    'is_published' =>
                        $lessonPublished,
                ]);

                $lessonId =
                    (int) $pdo->lastInsertId();

                $movedUploadPaths = [];
                $finalLessonContent =
                    $rawContent;

                if (
                    !($coverUpload['empty'] ?? true)
                ) {
                    $storedCover =
                        lessons_store_image_upload(
                            $coverUpload,
                            $courseId,
                            $lessonId,
                            'cover'
                        );

                    $movedUploadPaths[] =
                        $storedCover[
                            'absolute_path'
                        ];

                    if (
                        !empty(
                            $storedCover[
                                'webp_absolute'
                            ]
                        )
                    ) {
                        $movedUploadPaths[] =
                            $storedCover[
                                'webp_absolute'
                            ];
                    }

                    $updateLessonImage =
                        $pdo->prepare(
                            '
                            UPDATE lessons
                            SET lesson_image = :lesson_image
                            WHERE id = :lesson_id
                            '
                        );

                    $updateLessonImage->execute([
                        'lesson_image' =>
                            (string) $storedCover[
                                'relative_path'
                            ],

                        'lesson_id' =>
                            $lessonId,
                    ]);
                }

                $uploadsUrlPath =
                    '/'
                    . trim(
                        (string) parse_url(
                            UPLOADS_URL,
                            PHP_URL_PATH
                        ),
                        '/'
                    );

                foreach (
                    $validatedInlineUploads
                    as $inlineUpload
                ) {
                    $storedInline =
                        lessons_store_image_upload(
                            $inlineUpload,
                            $courseId,
                            $lessonId,
                            'content'
                        );

                    $movedUploadPaths[] =
                        $storedInline[
                            'absolute_path'
                        ];

                    if (
                        !empty(
                            $storedInline[
                                'webp_absolute'
                            ]
                        )
                    ) {
                        $movedUploadPaths[] =
                            $storedInline[
                                'webp_absolute'
                            ];
                    }

                    $publicPath =
                        rtrim(
                            $uploadsUrlPath,
                            '/'
                        )
                        . '/'
                        . ltrim(
                            (string) $storedInline[
                                'relative_path'
                            ],
                            '/'
                        );

                    $finalLessonContent =
                        str_replace(
                            '/__lesson_upload__/'
                            . $inlineUpload[
                                'token'
                            ],
                            $publicPath,
                            $finalLessonContent
                        );
                }

                $finalLessonContent =
                    sanitize_rich_text(
                        $finalLessonContent
                    );

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
                            1,
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

                    'title' =>
                        $title,

                    'description' =>
                        $form['description'] !== ''
                            ? $form['description']
                            : null,

                    'content' =>
                        $finalLessonContent !== ''
                            ? $finalLessonContent
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

                $lessonVersionId =
                    (int) $pdo->lastInsertId();

                $insertOfferingLesson =
                    $pdo->prepare(
                        '
                        INSERT INTO course_offering_lessons (
                            offering_id,
                            lesson_id,
                            lesson_version_id,
                            sort_order
                        ) VALUES (
                            :offering_id,
                            :lesson_id,
                            :lesson_version_id,
                            :sort_order
                        )
                        '
                    );

                $insertOfferingLesson->execute([
                    'offering_id' =>
                        $selectedOfferingId,

                    'lesson_id' =>
                        $lessonId,

                    'lesson_version_id' =>
                        $lessonVersionId,

                    'sort_order' =>
                        $sortOrder,
                ]);

                $insertReleaseRule =
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

                $insertReleaseRule->execute([
                    'offering_id' =>
                        $selectedOfferingId,

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

                lessons_audit(
                    $pdo,
                    $currentUserId,
                    $lessonId,
                    'lesson.create',
                    'Created lesson "'
                    . $title
                    . '" for offering #'
                    . $selectedOfferingId
                );

                $pdo->commit();

                set_flash(
                    'success',
                    'Lesson created successfully.'
                );

                redirect(
                    url(
                        'admin/lessons.php?offering='
                        . $selectedOfferingId
                    )
                );
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if (
                    isset($movedUploadPaths)
                    && is_array($movedUploadPaths)
                ) {
                    lessons_remove_upload_paths(
                        $movedUploadPaths
                    );
                }

                error_log(
                    'Lesson create error: '
                    . $exception->getMessage()
                );

                $errors[] =
                    'The lesson could not be created.';
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
    'Lessons | Blackthorne Academy';

$pageDescription =
    'Manage course lessons and release schedules.';

$pageCanonical =
    url('admin/lessons.php');

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

<main id="main-content"
    class="dashboard-page staff-dashboard-page dashboard-workspace-page courses-dashboard-page lessons-admin-page">

    <section class="dashboard-hero staff-dashboard-hero" aria-labelledby="lessons-heading"
        <?php if ($staffHeroUrl !== ''): ?> style="--staff-dashboard-hero-image: url('<?= e($staffHeroUrl); ?>');"
        <?php endif; ?>>
        <div class="section-inner">
            <div class="dashboard-hero-inner">

                <p class="academy-overline">
                    Course Content
                </p>

                <h1 id="lessons-heading">
                    Lessons
                </h1>

                <p class="dashboard-hero-copy">
                    Build versioned lesson content for a specific course
                    offering and control when each lesson becomes available.
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
                            Lesson Management
                        </p>

                        <h2>
                            Course Lessons
                        </h2>

                        <p>
                            Lessons are created for the master course, versioned,
                            and then attached to the exact offering that should
                            use that version.
                        </p>
                    </div>
                </header>


                <?php if ($errors !== []): ?>

                <div class="form-message form-message-error" role="alert">
                    <strong>
                        The lesson could not be saved.
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


                <section class="forum-admin-panel">

                    <header class="forum-admin-titlebar">
                        <p class="forum-admin-step">
                            Step 1
                        </p>

                        <h2>
                            Choose Course Offering
                        </h2>
                    </header>

                    <form action="<?= e(url('admin/lessons.php')); ?>" method="get" class="forum-admin-form">
                        <div class="form-group">
                            <label for="offering">
                                Course Offering
                            </label>

                            <select class="form-control" id="offering" name="offering" required>
                                <option value="">
                                    Select an offering
                                </option>

                                <?php foreach ($offerings as $offeringRow): ?>
                                <?php
                                    $scope =
                                        (string) (
                                            $offeringRow['offering_scope']
                                            ?? 'school_year'
                                        );

                                    $contextParts = [];

                                    if ($scope === 'perpetual') {
                                        $contextParts[] =
                                            'Perpetual';
                                    } else {
                                        $schoolYearName =
                                            trim(
                                                (string) (
                                                    $offeringRow[
                                                        'school_year_name'
                                                    ]
                                                    ?? ''
                                                )
                                            );

                                        if ($schoolYearName !== '') {
                                            $contextParts[] =
                                                $schoolYearName;
                                        }

                                        $yearGroupNames =
                                            trim(
                                                (string) (
                                                    $offeringRow[
                                                        'year_group_names'
                                                    ]
                                                    ?? ''
                                                )
                                            );

                                        if ($yearGroupNames !== '') {
                                            $contextParts[] =
                                                $yearGroupNames;
                                        }
                                    }

                                    $contextParts[] =
                                        ucfirst(
                                            str_replace(
                                                '_',
                                                ' ',
                                                (string) (
                                                    $offeringRow[
                                                        'status'
                                                    ]
                                                    ?? 'draft'
                                                )
                                            )
                                        );

                                    $optionLabel =
                                        (string) (
                                            $offeringRow[
                                                'course_title'
                                            ]
                                            ?? 'Course'
                                        )
                                        . ' — '
                                        . implode(
                                            ' · ',
                                            $contextParts
                                        );
                                    ?>

                                <option value="<?= (int) $offeringRow['id']; ?>" <?= (int) $offeringRow['id'] === $selectedOfferingId
                                            ? 'selected'
                                            : ''; ?>>
                                    <?= e($optionLabel); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="forum-admin-actions">
                            <button type="submit" class="button button-primary">
                                Load Lessons
                            </button>
                        </div>
                    </form>

                </section>


                <?php if ($selectedOffering !== null): ?>

                <section class="dashboard-workspace-panel">

                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">
                                Selected Offering
                            </p>

                            <h3>
                                <?= e(
                                        (string) (
                                            $selectedOffering[
                                                'course_title'
                                            ]
                                            ?? 'Course'
                                        )
                                    ); ?>
                            </h3>
                        </div>
                    </div>

                    <div class="dashboard-panel-body">

                        <div class="dashboard-placeholder-list">

                            <span>
                                <strong>Scope:</strong>
                                <?= e(
                                        ucfirst(
                                            str_replace(
                                                '_',
                                                ' ',
                                                (string) (
                                                    $selectedOffering[
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
                                        $selectedOffering[
                                            'school_year_name'
                                        ]
                                    )
                                ): ?>
                            <span>
                                <strong>School Year:</strong>
                                <?= e(
                                            (string) $selectedOffering[
                                                'school_year_name'
                                            ]
                                        ); ?>
                            </span>
                            <?php endif; ?>

                            <?php if (
                                    !empty(
                                        $selectedOffering[
                                            'year_group_names'
                                        ]
                                    )
                                ): ?>
                            <span>
                                <strong>Grade Level:</strong>
                                <?= e(
                                            (string) $selectedOffering[
                                                'year_group_names'
                                            ]
                                        ); ?>
                            </span>
                            <?php endif; ?>

                            <span>
                                <strong>Pacing:</strong>
                                <?= e(
                                        ucfirst(
                                            str_replace(
                                                '_',
                                                ' ',
                                                (string) (
                                                    $selectedOffering[
                                                        'pacing_mode'
                                                    ]
                                                    ?? 'self_paced'
                                                )
                                            )
                                        )
                                    ); ?>
                            </span>

                            <?php if (
                                    !empty(
                                        $selectedOffering[
                                            'course_start_date'
                                        ]
                                    )
                                ): ?>
                            <span>
                                <strong>Course Start:</strong>
                                <?= e(
                                            lessons_format_datetime(
                                                $selectedOffering[
                                                    'course_start_date'
                                                ]
                                            )
                                        ); ?>
                            </span>
                            <?php endif; ?>

                        </div>

                    </div>

                </section>


                <section class="dashboard-workspace-panel">

                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">
                                Lesson Sequence
                            </p>

                            <h3>
                                Existing Lessons
                            </h3>
                        </div>
                    </div>

                    <div class="dashboard-panel-body">

                        <?php if ($offeringLessons === []): ?>

                        <p>
                            No lessons have been added to this offering yet.
                        </p>

                        <?php else: ?>

                        <div class="dashboard-placeholder-list">

                            <?php foreach ($offeringLessons as $lessonIndex => $lessonRow): ?>

                            <span>

                                <strong>
                                    <?= number_format(
                                                    (int) (
                                                        $lessonRow[
                                                            'sort_order'
                                                        ]
                                                        ?? 0
                                                    )
                                                ); ?>.
                                    <?= e(
                                                    (string) (
                                                        $lessonRow[
                                                            'title'
                                                        ]
                                                        ?? $lessonRow[
                                                            'internal_name'
                                                        ]
                                                        ?? 'Lesson'
                                                    )
                                                ); ?>
                                </strong>

                                · Version
                                <?= number_format(
                                                (int) (
                                                    $lessonRow[
                                                        'version_number'
                                                    ]
                                                    ?? 1
                                                )
                                            ); ?>

                                ·
                                <?= e(
                                                ucfirst(
                                                    (string) (
                                                        $lessonRow[
                                                            'version_status'
                                                        ]
                                                        ?? 'draft'
                                                    )
                                                )
                                            ); ?>

                                ·
                                <?= e(
                                                lessons_release_label(
                                                    $lessonRow
                                                )
                                            ); ?>

                                <?php if ($canEditLessons): ?>
                                ·
                                <a href="<?= e(
                                                        url(
                                                            'admin/lesson-edit.php?offering='
                                                            . $selectedOfferingId
                                                            . '&lesson='
                                                            . (int) (
                                                                $lessonRow[
                                                                    'lesson_id'
                                                                ]
                                                                ?? 0
                                                            )
                                                        )
                                                    ); ?>">
                                    Edit
                                </a>


                                ·
                                <form action="<?= e(
                                                        url(
                                                            'admin/lessons.php?offering='
                                                            . $selectedOfferingId
                                                        )
                                                    ); ?>" method="post" style="display:inline;"
                                    onsubmit="return confirm('Permanently delete this lesson? This removes the lesson, all of its versions, and its placement from course offerings. This cannot be undone.');">
                                    <?= csrf_field(); ?>

                                    <input type="hidden" name="action" value="delete_lesson">

                                    <input type="hidden" name="offering_id" value="<?= $selectedOfferingId; ?>">

                                    <input type="hidden" name="lesson_id" value="<?= (int) (
                                                            $lessonRow[
                                                                'lesson_id'
                                                            ]
                                                            ?? 0
                                                        ); ?>">

                                    <button type="submit" class="button-link lesson-delete-link" aria-label="Delete <?= e(
                                                            (string) (
                                                                $lessonRow[
                                                                    'title'
                                                                ]
                                                                ?? $lessonRow[
                                                                    'internal_name'
                                                                ]
                                                                ?? 'lesson'
                                                            )
                                                        ); ?>">
                                        Delete
                                    </button>
                                </form>

                                <?php if ($lessonIndex > 0): ?>
                                ·
                                <form action="<?= e(
                                                            url(
                                                                'admin/lessons.php?offering='
                                                                . $selectedOfferingId
                                                            )
                                                        ); ?>" method="post" style="display:inline;">
                                    <?= csrf_field(); ?>

                                    <input type="hidden" name="action" value="move_lesson_up">

                                    <input type="hidden" name="offering_id" value="<?= $selectedOfferingId; ?>">

                                    <input type="hidden" name="offering_lesson_id" value="<?= (int) (
                                                                $lessonRow[
                                                                    'offering_lesson_id'
                                                                ]
                                                                ?? 0
                                                            ); ?>">

                                    <button type="submit" class="button-link" aria-label="Move <?= e(
                                                                (string) (
                                                                    $lessonRow[
                                                                        'title'
                                                                    ]
                                                                    ?? $lessonRow[
                                                                        'internal_name'
                                                                    ]
                                                                    ?? 'lesson'
                                                                )
                                                            ); ?> up">
                                        Move Up
                                    </button>
                                </form>
                                <?php endif; ?>

                                <?php if (
                                                    $lessonIndex
                                                    < count($offeringLessons) - 1
                                                ): ?>
                                ·
                                <form action="<?= e(
                                                            url(
                                                                'admin/lessons.php?offering='
                                                                . $selectedOfferingId
                                                            )
                                                        ); ?>" method="post" style="display:inline;">
                                    <?= csrf_field(); ?>

                                    <input type="hidden" name="action" value="move_lesson_down">

                                    <input type="hidden" name="offering_id" value="<?= $selectedOfferingId; ?>">

                                    <input type="hidden" name="offering_lesson_id" value="<?= (int) (
                                                                $lessonRow[
                                                                    'offering_lesson_id'
                                                                ]
                                                                ?? 0
                                                            ); ?>">

                                    <button type="submit" class="button-link" aria-label="Move <?= e(
                                                                (string) (
                                                                    $lessonRow[
                                                                        'title'
                                                                    ]
                                                                    ?? $lessonRow[
                                                                        'internal_name'
                                                                    ]
                                                                    ?? 'lesson'
                                                                )
                                                            ); ?> down">
                                        Move Down
                                    </button>
                                </form>
                                <?php endif; ?>
                                <?php endif; ?>

                            </span>

                            <?php endforeach; ?>

                        </div>

                        <?php endif; ?>

                    </div>

                </section>


                <?php if ($canCreateLessons): ?>

                <section class="forum-admin-panel">

                    <header class="forum-admin-titlebar">
                        <p class="forum-admin-step">
                            Step 2
                        </p>

                        <h2>
                            Create Lesson
                        </h2>
                    </header>

                    <form action="<?= e(
                                    url(
                                        'admin/lessons.php?offering='
                                        . $selectedOfferingId
                                    )
                                ); ?>" method="post" enctype="multipart/form-data" class="forum-admin-form"
                        id="lesson-create-form">
                        <?= csrf_field(); ?>

                        <input type="hidden" name="action" value="create_lesson">

                        <input type="hidden" name="offering_id" value="<?= $selectedOfferingId; ?>">


                        <div class="form-group">
                            <label for="lesson-title">
                                Lesson Title
                            </label>

                            <input class="form-control" type="text" id="lesson-title" name="title" maxlength="200"
                                value="<?= e($form['title']); ?>" placeholder="Lesson 1: ..." required>
                        </div>


                        <div class="form-group">
                            <label for="lesson-image">
                                Lesson Image
                            </label>

                            <input class="form-control" type="file" id="lesson-image" name="lesson_image"
                                accept="image/jpeg,image/png,image/gif,image/webp,image/avif">

                            <p class="form-help">
                                Optional. Use this as the lesson thumbnail or header image. Maximum file size: 10 MB.
                            </p>
                        </div>


                        <div class="form-group">
                            <label for="lesson-description">
                                Lesson Description
                            </label>

                            <textarea class="form-control" id="lesson-description" name="description"
                                rows="4"><?= e($form['description']); ?></textarea>
                        </div>


                        <div class="form-group forum-rich-editor-field">
                            <label id="lesson-content-label" for="lesson-editor">
                                Lesson Content
                            </label>

                            <div class="forum-rich-editor" data-forum-editor>
                                <div class="forum-rich-editor-toolbar" role="toolbar"
                                    aria-label="Lesson content formatting">
                                    <div class="forum-editor-tool-group">
                                        <button type="button" class="forum-editor-tool"
                                            data-command="bold"><strong>B</strong></button>
                                        <button type="button" class="forum-editor-tool"
                                            data-command="italic"><em>I</em></button>
                                        <button type="button" class="forum-editor-tool"
                                            data-command="underline"><u>U</u></button>
                                        <button type="button" class="forum-editor-tool"
                                            data-command="strikeThrough"><s>S</s></button>
                                    </div>

                                    <div class="forum-editor-tool-group">
                                        <select class="forum-editor-select" data-editor-format title="Text style">
                                            <option value="">Text Style</option>
                                            <option value="p">Paragraph</option>
                                            <option value="h2">Heading 2</option>
                                            <option value="h3">Heading 3</option>
                                            <option value="h4">Heading 4</option>
                                        </select>

                                        <select class="forum-editor-select" data-editor-size title="Font size">
                                            <option value="">Font Size</option>
                                            <option value="14px">Small</option>
                                            <option value="16px">Normal</option>
                                            <option value="18px">Large</option>
                                            <option value="22px">Extra Large</option>
                                            <option value="28px">Display</option>
                                        </select>
                                    </div>

                                    <div class="forum-editor-tool-group forum-editor-color-tools">
                                        <label class="forum-editor-color-label">
                                            Text
                                            <input type="color" value="#e8e1e6" data-editor-color
                                                aria-label="Text color">
                                        </label>
                                        <button type="button" class="forum-editor-tool" data-editor-apply-color
                                            title="Apply the current text color to the selected text">
                                            Apply Text
                                        </button>

                                        <label class="forum-editor-color-label">
                                            Highlight
                                            <input type="color" value="#55336f" data-editor-highlight
                                                aria-label="Highlight color">
                                        </label>
                                        <button type="button" class="forum-editor-tool" data-editor-apply-highlight
                                            title="Apply the current highlight color to the selected text">
                                            Apply Highlight
                                        </button>
                                    </div>

                                    <div class="forum-editor-tool-group">
                                        <button type="button" class="forum-editor-tool"
                                            data-command="insertUnorderedList">• List</button>
                                        <button type="button" class="forum-editor-tool"
                                            data-command="insertOrderedList">1. List</button>
                                        <button type="button" class="forum-editor-tool" data-editor-quote>Quote</button>
                                    </div>

                                    <div class="forum-editor-tool-group">
                                        <button type="button" class="forum-editor-tool"
                                            data-command="justifyLeft">Left</button>
                                        <button type="button" class="forum-editor-tool"
                                            data-command="justifyCenter">Center</button>
                                        <button type="button" class="forum-editor-tool"
                                            data-command="justifyRight">Right</button>
                                    </div>

                                    <div class="forum-editor-tool-group">
                                        <button type="button" class="forum-editor-tool" data-editor-link>Link</button>
                                        <button type="button" class="forum-editor-tool"
                                            data-command="unlink">Unlink</button>
                                        <button type="button" class="forum-editor-tool"
                                            data-command="removeFormat">Clear</button>
                                    </div>

                                    <div class="forum-editor-tool-group forum-editor-image-tools">
                                        <select class="forum-editor-select" data-editor-image-size title="Image size">
                                            <option value="">Image Size</option>
                                            <option value="25%">25%</option>
                                            <option value="40%">40%</option>
                                            <option value="50%">50%</option>
                                            <option value="60%">60%</option>
                                            <option value="75%">75%</option>
                                            <option value="90%">90%</option>
                                            <option value="100%">100%</option>
                                        </select>

                                        <select class="forum-editor-select" data-editor-image-align
                                            title="Image alignment">
                                            <option value="">Image Align</option>
                                            <option value="left">Left</option>
                                            <option value="center">Center</option>
                                            <option value="right">Right</option>
                                        </select>

                                        <button type="button" class="forum-editor-tool" data-editor-image-upload>
                                            Upload Image
                                        </button>

                                        <input class="forum-editor-image-upload-input" type="file"
                                            id="lesson-content-image-upload" name="uploaded_images[]"
                                            accept="image/jpeg,image/png,image/gif,image/webp,image/avif" multiple
                                            data-editor-image-input>

                                        <input type="hidden" name="upload_tokens" value="[]" data-editor-upload-tokens>

                                        <button type="button" class="forum-editor-tool" data-editor-image-url>
                                            Image URL
                                        </button>
                                    </div>
                                </div>

                                <div class="forum-rich-editor-surface" id="lesson-editor" contenteditable="true"
                                    role="textbox" aria-labelledby="lesson-content-label" aria-multiline="true"
                                    data-placeholder="Write the lesson content here..." spellcheck="true">
                                    <?= $form['content'] !== '' ? sanitize_rich_text($form['content']) : ''; ?></div>

                                <div class="forum-editor-image-preview-list" data-editor-image-previews hidden
                                    aria-live="polite"></div>

                                <div class="forum-editor-counts" aria-live="polite" aria-atomic="true">
                                    <span data-editor-word-count>0 words</span>
                                    <span aria-hidden="true">•</span>
                                    <span data-editor-character-count>0 characters</span>
                                </div>

                                <textarea class="forum-rich-editor-input" name="content" id="lesson-content"
                                    aria-hidden="true" tabindex="-1"><?= e($form['content']); ?></textarea>
                            </div>

                            <p class="form-help">
                                Use headings, emphasis, colors, lists, alignment, links, quotes, and images. This stores
                                Version 1; later edits create new lesson versions instead of overwriting historical
                                content.
                            </p>
                        </div>


                        <div class="forum-admin-form-grid">

                            <div class="form-group">
                                <label for="lesson-sort-order">
                                    Lesson Order
                                </label>

                                <input class="form-control" type="number" id="lesson-sort-order" name="sort_order"
                                    min="0" step="1" value="<?= e($form['sort_order']); ?>" required>
                            </div>


                            <div class="form-group">
                                <label for="lesson-status">
                                    Lesson Status
                                </label>

                                <select class="form-control" id="lesson-status" name="status">
                                    <option value="draft" <?= $form['status'] === 'draft'
                                                    ? 'selected'
                                                    : ''; ?>>
                                        Draft
                                    </option>

                                    <option value="review" <?= $form['status'] === 'review'
                                                    ? 'selected'
                                                    : ''; ?>>
                                        Review
                                    </option>

                                    <?php if ($canPublishLessons): ?>
                                    <option value="published" <?= $form['status'] === 'published'
                                                        ? 'selected'
                                                        : ''; ?>>
                                        Published
                                    </option>
                                    <?php endif; ?>
                                </select>
                            </div>

                        </div>


                        <fieldset class="forum-admin-fieldset">

                            <legend>
                                Release Rule
                            </legend>

                            <?php if (!$canManageRelease): ?>

                            <p class="form-help">
                                You do not have permission to manage
                                release schedules. This lesson will
                                release immediately.
                            </p>

                            <input type="hidden" name="release_type" value="immediate">

                            <?php else: ?>

                            <div class="form-group">
                                <label for="release-type">
                                    Release Type
                                </label>

                                <select class="form-control" id="release-type" name="release_type">
                                    <option value="immediate" <?= $form['release_type'] === 'immediate'
                                                        ? 'selected'
                                                        : ''; ?>>
                                        Immediate
                                    </option>

                                    <?php if (
                                                    ($selectedOffering['offering_scope'] ?? '')
                                                    !== 'perpetual'
                                                ): ?>

                                    <option value="days_after_course_start" <?= $form['release_type'] === 'days_after_course_start'
                                                            ? 'selected'
                                                            : ''; ?>>
                                        Days After Course Start
                                    </option>

                                    <option value="fixed_date" <?= $form['release_type'] === 'fixed_date'
                                                            ? 'selected'
                                                            : ''; ?>>
                                        Fixed Date
                                    </option>

                                    <?php endif; ?>

                                    <?php if ($offeringLessons !== []): ?>
                                    <option value="after_previous_lesson" <?= $form['release_type'] === 'after_previous_lesson'
                                                            ? 'selected'
                                                            : ''; ?>>
                                        After Previous Lesson
                                    </option>
                                    <?php endif; ?>
                                </select>

                                <p class="form-help">
                                    Enrollment-relative release is
                                    intentionally not available.
                                    Late registrants follow the same
                                    cohort schedule as everyone else.
                                </p>
                            </div>


                            <?php if (
                                            ($selectedOffering['offering_scope'] ?? '')
                                            !== 'perpetual'
                                        ): ?>

                            <div class="form-group">
                                <label for="release-delay-days">
                                    Days After Course Start
                                </label>

                                <input class="form-control" type="number" id="release-delay-days"
                                    name="release_delay_days" min="0" step="1" value="<?= e(
                                                        $form[
                                                            'release_delay_days'
                                                        ]
                                                    ); ?>">

                                <p class="form-help">
                                    Example: 7 releases the lesson
                                    seven days after the offering's
                                    Course Start Date.
                                </p>
                            </div>


                            <div class="form-group">
                                <label for="release-at">
                                    Fixed Release Date
                                </label>

                                <input class="form-control" type="datetime-local" id="release-at" name="release_at"
                                    value="<?= e(
                                                        $form[
                                                            'release_at'
                                                        ]
                                                    ); ?>">
                            </div>

                            <?php endif; ?>


                            <?php if ($offeringLessons !== []): ?>

                            <div class="form-group">
                                <label for="prerequisite-lesson">
                                    Previous Lesson
                                </label>

                                <select class="form-control" id="prerequisite-lesson" name="prerequisite_lesson_id">
                                    <option value="">
                                        Select a lesson
                                    </option>

                                    <?php foreach ($offeringLessons as $lessonRow): ?>
                                    <option value="<?= (int) $lessonRow['lesson_id']; ?>" <?= (string) $lessonRow['lesson_id']
                                                                === $form['prerequisite_lesson_id']
                                                                    ? 'selected'
                                                                    : ''; ?>>
                                        <?= e(
                                                                (string) (
                                                                    $lessonRow[
                                                                        'title'
                                                                    ]
                                                                    ?? $lessonRow[
                                                                        'internal_name'
                                                                    ]
                                                                    ?? 'Lesson'
                                                                )
                                                            ); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <?php endif; ?>

                            <?php endif; ?>

                        </fieldset>


                        <div class="forum-admin-actions">
                            <button type="submit" class="button button-primary">
                                Create Lesson
                            </button>
                        </div>

                    </form>

                </section>

                <?php endif; ?>

                <?php else: ?>

                <section class="dashboard-workspace-panel">

                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">
                                Start Here
                            </p>

                            <h3>
                                Select an Offering
                            </h3>
                        </div>
                    </div>

                    <div class="dashboard-panel-body">
                        <p>
                            Choose a course offering above to view its lesson
                            sequence or create new lesson content.
                        </p>
                    </div>

                </section>

                <?php endif; ?>

            </div>

        </div>
    </section>

</main>


<script>
    (() => {
        'use strict';

        const form = document.getElementById('lesson-create-form');
        const editor = document.getElementById('lesson-editor');
        const input = document.getElementById('lesson-content');

        if (!form || !editor || !input) {
            return;
        }

        const q = (selector) => form.querySelector(selector);
        const imageUploadButton = q('[data-editor-image-upload]');
        const imageInput = q('[data-editor-image-input]');
        const imageUrlButton = q('[data-editor-image-url]');
        const imageSizeSelect = q('[data-editor-image-size]');
        const imageAlignSelect = q('[data-editor-image-align]');
        const imagePreviews = q('[data-editor-image-previews]');
        const uploadTokensInput = q('[data-editor-upload-tokens]');
        const formatSelect = q('[data-editor-format]');
        const sizeSelect = q('[data-editor-size]');
        const colorInput = q('[data-editor-color]');
        const highlightInput = q('[data-editor-highlight]');
        const applyColorButton = q('[data-editor-apply-color]');
        const applyHighlightButton = q('[data-editor-apply-highlight]');
        const quoteButton = q('[data-editor-quote]');
        const linkButton = q('[data-editor-link]');
        const wordCount = q('[data-editor-word-count]');
        const characterCount = q('[data-editor-character-count]');

        let selectedUploads = [];
        let savedRange = null;
        let selectedImage = null;

        try {
            document.execCommand('styleWithCSS', false, true);
        } catch (error) {}

        const sync = () => {
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
                            '/__lesson_upload__/' +
                            token
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

            const plain = editor.textContent
                .replace(/\u00a0/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();

            const words =
                plain === '' ?
                0 :
                plain.split(/\s+/u).filter(Boolean).length;

            if (wordCount) {
                wordCount.textContent =
                    `${words} ${words === 1 ? 'word' : 'words'}`;
            }

            if (characterCount) {
                characterCount.textContent =
                    `${plain.length} ${plain.length === 1 ? 'character' : 'characters'}`;
            }
        };

        const saveSelection = () => {
            const selection = window.getSelection();

            if (!selection || selection.rangeCount < 1) {
                return;
            }

            const range = selection.getRangeAt(0);

            if (
                editor.contains(range.commonAncestorContainer) ||
                range.commonAncestorContainer === editor
            ) {
                savedRange = range.cloneRange();
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
                editor.focus({
                    preventScroll: true
                });
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

        const applyBlockAlignment = (alignmentCommand) => {
            const alignmentMap = {
                justifyLeft: 'left',
                justifyCenter: 'center',
                justifyRight: 'right',
            };

            const alignment =
                alignmentMap[
                    alignmentCommand
                ] ??
                '';

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
                            return range.intersectsNode(block) ?
                                index :
                                -1;
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
                            otherIndex !== index &&
                            block.contains(
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
                    node instanceof Element ?
                    node.closest(
                        blockSelector
                    ) :
                    null;

                if (
                    nearestBlock &&
                    editor.contains(
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

            sync();
            return true;
        };

        const command = (name, value = null) => {
            if (applyBlockAlignment(name)) {
                return;
            }

            restoreSelection();
            document.execCommand(name, false, value);
            sync();
            saveSelection();
        };

        form.querySelectorAll('[data-command]').forEach((button) => {
            button.addEventListener('mousedown', (event) => event.preventDefault());
            button.addEventListener('click', () => command(button.dataset.command));
        });

        if (formatSelect) {
            formatSelect.addEventListener('change', () => {
                if (formatSelect.value !== '') {
                    command('formatBlock', formatSelect.value);
                    formatSelect.value = '';
                }
            });
        }

        if (sizeSelect) {
            sizeSelect.addEventListener('change', () => {
                if (sizeSelect.value === '') {
                    return;
                }

                restoreSelection();
                document.execCommand('fontSize', false, '7');

                editor.querySelectorAll('font[size="7"]').forEach((font) => {
                    const span = document.createElement('span');
                    span.style.fontSize = sizeSelect.value;

                    while (font.firstChild) {
                        span.appendChild(font.firstChild);
                    }

                    font.replaceWith(span);
                });

                sizeSelect.value = '';
                sync();
            });
        }

        const applyTextColor = () => {
            if (!savedRange || savedRange.collapsed) {
                return;
            }

            command(
                'foreColor',
                colorInput.value
            );
        };

        const applyHighlightColor = () => {
            if (!savedRange || savedRange.collapsed) {
                return;
            }

            restoreSelection();

            document.execCommand(
                document.queryCommandSupported('hiliteColor') ?
                'hiliteColor' :
                'backColor',
                false,
                highlightInput.value
            );

            sync();
            saveSelection();
        };

        if (colorInput) {
            /*
             * Save the editor selection before the native color picker takes
             * focus. The click handler also applies the swatch's current value,
             * which matters when the author intentionally chooses the SAME color
             * again. Browsers do not fire input/change when a color value is
             * re-selected unchanged, so relying on input alone made that case
             * appear broken.
             */
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
            quoteButton.addEventListener('mousedown', (event) => event.preventDefault());
            quoteButton.addEventListener('click', () => command('formatBlock', 'blockquote'));
        }

        if (linkButton) {
            linkButton.addEventListener('mousedown', (event) => event.preventDefault());
            linkButton.addEventListener('click', () => {
                saveSelection();

                const url = window.prompt('Enter a link URL.');

                if (url !== null && url.trim() !== '') {
                    command('createLink', url.trim());
                }
            });
        }

        const escapeAttribute = (value) => String(value)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');

        const insertImage = (
            src,
            alt = '',
            uploadToken = ''
        ) => {
            restoreSelection();

            const tokenAttribute =
                uploadToken !== '' ?
                ` data-upload-token="${escapeAttribute(uploadToken)}"` :
                '';

            document.execCommand(
                'insertHTML',
                false,
                `<img src="${escapeAttribute(src)}" alt="${escapeAttribute(alt)}"${tokenAttribute} loading="lazy" style="display:block;margin-left:auto;margin-right:auto;max-width:100%;height:auto;">`
            );

            sync();
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
                    target instanceof HTMLImageElement &&
                    editor.contains(target)
                ) {
                    selectedImage =
                        target;

                    selectedImage.setAttribute(
                        'data-editor-selected-image',
                        '1'
                    );

                    if (imageSizeSelect) {
                        const currentWidth =
                            selectedImage.style.width ||
                            '100%';

                        const hasOption =
                            Array.from(
                                imageSizeSelect.options
                            ).some(
                                (option) =>
                                option.value === currentWidth
                            );

                        imageSizeSelect.value =
                            hasOption ?
                            currentWidth :
                            '';
                    }

                    if (imageAlignSelect) {
                        const marginLeft =
                            selectedImage.style.marginLeft;

                        const marginRight =
                            selectedImage.style.marginRight;

                        if (
                            marginLeft === '0px' &&
                            marginRight === 'auto'
                        ) {
                            imageAlignSelect.value = 'left';
                        } else if (
                            marginLeft === 'auto' &&
                            marginRight === '0px'
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
                        !selectedImage ||
                        !editor.contains(selectedImage)
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
                        !selectedImage ||
                        !editor.contains(selectedImage)
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
            imageUrlButton.addEventListener('mousedown', (event) => event.preventDefault());
            imageUrlButton.addEventListener('click', () => {
                saveSelection();

                const src = window.prompt('Enter an HTTPS image URL.');

                if (src === null || !/^https:\/\//i.test(src.trim())) {
                    return;
                }

                const alt = window.prompt('Enter image alt text.', '') ?? '';
                insertImage(src.trim(), alt);
            });
        }

        const makeToken = () => {
            if (window.crypto && window.crypto.getRandomValues) {
                const values = new Uint32Array(4);
                window.crypto.getRandomValues(values);

                return Array.from(values, (value) => value.toString(36)).join('');
            }

            return Date.now().toString(36) + Math.random().toString(36).slice(2);
        };

        const syncFiles = () => {
            if (!imageInput || !uploadTokensInput) {
                return;
            }

            const transfer = new DataTransfer();

            selectedUploads.forEach((item) => transfer.items.add(item.file));

            imageInput.files = transfer.files;
            uploadTokensInput.value = JSON.stringify(
                selectedUploads.map((item) => item.token)
            );
        };

        const renderPreviews = () => {
            if (!imagePreviews) {
                return;
            }

            imagePreviews.innerHTML = '';

            if (selectedUploads.length === 0) {
                imagePreviews.hidden = true;
                return;
            }

            imagePreviews.hidden = false;

            selectedUploads.forEach((item) => {
                const row = document.createElement('div');
                row.className = 'forum-editor-image-preview';

                const label = document.createElement('span');
                label.textContent = item.file.name;

                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'button-link';
                remove.textContent = 'Remove';

                remove.addEventListener('click', () => {
                    const removedUpload =
                        selectedUploads.find(
                            (candidate) =>
                            candidate.token ===
                            item.token
                        );

                    if (
                        removedUpload &&
                        removedUpload.previewUrl
                    ) {
                        URL.revokeObjectURL(
                            removedUpload.previewUrl
                        );
                    }

                    selectedUploads =
                        selectedUploads.filter(
                            (candidate) =>
                            candidate.token !==
                            item.token
                        );

                    editor.querySelectorAll(
                        'img[data-upload-token]'
                    ).forEach(
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
                        selectedImage &&
                        !editor.contains(
                            selectedImage
                        )
                    ) {
                        selectedImage = null;
                    }

                    syncFiles();
                    renderPreviews();
                    sync();
                });

                row.append(label, remove);
                imagePreviews.appendChild(row);
            });
        };

        if (imageUploadButton && imageInput) {
            imageUploadButton.addEventListener('mousedown', (event) => event.preventDefault());
            imageUploadButton.addEventListener('click', () => {
                saveSelection();
                imageInput.click();
            });

            imageInput.addEventListener('change', () => {
                const incoming = Array.from(imageInput.files ?? []);

                if (incoming.length + selectedUploads.length > 10) {
                    window.alert('You can upload up to 10 inline lesson images at a time.');
                    imageInput.value = '';
                    return;
                }

                incoming.forEach((file) => {
                    if (file.size > 10 * 1024 * 1024) {
                        window.alert(`${file.name} is larger than 10 MB.`);
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

                    const alt = file.name
                        .replace(/\.[^.]+$/, '')
                        .replace(/[-_]+/g, ' ')
                        .trim();

                    insertImage(
                        previewUrl,
                        alt,
                        token
                    );
                });

                syncFiles();
                renderPreviews();
            });
        }

        ['keyup', 'mouseup', 'input'].forEach((eventName) => {
            editor.addEventListener(eventName, () => {
                saveSelection();
                sync();
            });
        });

        editor.addEventListener('paste', () => {
            window.setTimeout(sync, 0);
        });

        form.addEventListener('submit', () => {
            sync();
            syncFiles();
        });

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

        sync();
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


<style>
    .lesson-delete-link {
        appearance: none;
        border: 0;
        padding: 0;
        background: transparent;
        color: #d96a72;
        font: inherit;
        text-decoration: none;
        cursor: pointer;
    }

    .lesson-delete-link:hover,
    .lesson-delete-link:focus-visible {
        color: #f08a91;
        text-decoration: underline;
    }

</style>

<?php

require INCLUDES_PATH . '/footer.php';
