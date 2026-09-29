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

$canEditCourses =
    $isProtectedSuperAdmin
    || user_can('courses.edit');

if (
    !$hasStaffIdentity
    || !$canEditCourses
) {
    http_response_code(403);

    $pageTitle =
        'Access Denied | Blackthorne Academy';

    $pageDescription =
        'You do not have permission to edit Blackthorne Academy courses.';

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
                Your account does not have permission to edit courses.
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
| Course ID
|--------------------------------------------------------------------------
*/

$courseId =
    filter_input(
        INPUT_GET,
        'id',
        FILTER_VALIDATE_INT
    );

if (
    !is_int($courseId)
    || $courseId <= 0
) {
    http_response_code(404);

    $pageTitle =
        'Course Not Found | Blackthorne Academy';

    $pageDescription =
        'The requested course could not be found.';

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
                Course Management
            </p>

            <h1>
                Course Not Found
            </h1>

            <p>
                The requested course does not exist.
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


/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function course_edit_post_string(string $key): string
{
    $value =
        $_POST[$key]
        ?? '';

    return is_scalar($value)
        ? trim((string) $value)
        : '';
}


function course_edit_post_id(string $key): int
{
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


function course_edit_post_ids(string $key): array
{
    $values =
        $_POST[$key]
        ?? [];

    if (!is_array($values)) {
        return [];
    }

    $ids = [];

    foreach ($values as $value) {
        if (
            is_scalar($value)
            && ctype_digit(
                (string) $value
            )
        ) {
            $id = (int) $value;

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
    }

    return array_values($ids);
}


function course_edit_text_length(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);
}


function course_edit_slug_base(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    if (function_exists('iconv')) {
        $transliterated =
            @iconv(
                'UTF-8',
                'ASCII//TRANSLIT//IGNORE',
                $value
            );

        if (
            is_string($transliterated)
            && $transliterated !== ''
        ) {
            $value = $transliterated;
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


function course_edit_unique_category_slug(
    PDO $pdo,
    string $name
): string {
    $base =
        course_edit_slug_base($name);

    if ($base === '') {
        $base =
            'category-'
            . bin2hex(
                random_bytes(4)
            );
    }

    $base =
        substr(
            $base,
            0,
            108
        );

    $candidate = $base;
    $suffix = 2;

    $statement =
        $pdo->prepare(
            '
            SELECT 1
            FROM course_categories
            WHERE slug = :slug
            LIMIT 1
            '
        );

    while (true) {
        $statement->execute([
            'slug' => $candidate,
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
                108
            )
            . '-'
            . $suffix;

        $suffix++;
    }
}


function course_edit_unique_tag_slug(
    PDO $pdo,
    string $name
): string {
    $base =
        course_edit_slug_base($name);

    if ($base === '') {
        $base =
            'tag-'
            . bin2hex(
                random_bytes(4)
            );
    }

    $base =
        substr(
            $base,
            0,
            88
        );

    $candidate = $base;
    $suffix = 2;

    $statement =
        $pdo->prepare(
            '
            SELECT 1
            FROM course_tags
            WHERE slug = :slug
            LIMIT 1
            '
        );

    while (true) {
        $statement->execute([
            'slug' => $candidate,
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
                88
            )
            . '-'
            . $suffix;

        $suffix++;
    }
}


function course_edit_parse_tags(
    string $value
): array {
    $parts =
        preg_split(
            '/[,]+/',
            $value
        ) ?: [];

    $tags = [];

    foreach ($parts as $part) {
        $tag =
            trim(
                preg_replace(
                    '/\s+/',
                    ' ',
                    (string) $part
                ) ?? ''
            );

        if ($tag === '') {
            continue;
        }

        $key =
            function_exists('mb_strtolower')
                ? mb_strtolower(
                    $tag,
                    'UTF-8'
                )
                : strtolower($tag);

        $tags[$key] = $tag;
    }

    return
        array_values($tags);
}


function course_edit_normalize_datetime(
    string $value
): ?string {
    $value = trim($value);

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

    return
        $date->format(
            'Y-m-d H:i:s'
        );
}


function course_edit_form_datetime(
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

    return
        date(
            'Y-m-d\TH:i',
            $timestamp
        );
}


function course_edit_generate_webp(
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
                ? @imagecreatefromjpeg($absolutePath)
                : false,

        'image/png' =>
            function_exists('imagecreatefrompng')
                ? @imagecreatefrompng($absolutePath)
                : false,

        'image/avif' =>
            function_exists('imagecreatefromavif')
                ? @imagecreatefromavif($absolutePath)
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
        imagedestroy($image);
        return null;
    }

    $created =
        @imagewebp(
            $image,
            $webpPath,
            85
        );

    imagedestroy($image);

    if (
        !$created
        || !is_file($webpPath)
        || (int) @filesize($webpPath) <= 0
    ) {
        if (is_file($webpPath)) {
            @unlink($webpPath);
        }

        return null;
    }

    return $webpPath;
}


function course_edit_upload_image(
    array $file
): array {
    $error =
        (int) (
            $file['error']
            ?? UPLOAD_ERR_NO_FILE
        );

    if ($error === UPLOAD_ERR_NO_FILE) {
        return [
            'reference' => null,
            'absolute' => null,
            'webp_absolute' => null,
        ];
    }

    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException(
            'The course image could not be uploaded.'
        );
    }

    $size =
        (int) (
            $file['size']
            ?? 0
        );

    if (
        $size <= 0
        || $size > 10 * 1024 * 1024
    ) {
        throw new RuntimeException(
            'Course images must be 10 MB or smaller.'
        );
    }

    $temporaryPath =
        (string) (
            $file['tmp_name']
            ?? ''
        );

    if (
        $temporaryPath === ''
        || !is_uploaded_file(
            $temporaryPath
        )
    ) {
        throw new RuntimeException(
            'The uploaded course image is invalid.'
        );
    }

    $finfo =
        new finfo(
            FILEINFO_MIME_TYPE
        );

    $mime =
        (string) $finfo->file(
            $temporaryPath
        );

    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];

    if (!isset($extensions[$mime])) {
        throw new RuntimeException(
            'Course images must be JPG, PNG, WebP, or AVIF.'
        );
    }

    $dimensions =
        @getimagesize(
            $temporaryPath
        );

    if (
        !is_array($dimensions)
        || empty($dimensions[0])
        || empty($dimensions[1])
    ) {
        throw new RuntimeException(
            'The course image dimensions could not be read.'
        );
    }

    $directory =
        UPLOADS_PATH
        . '/courses';

    if (
        !is_dir($directory)
        && !mkdir(
            $directory,
            0755,
            true
        )
        && !is_dir($directory)
    ) {
        throw new RuntimeException(
            'The course image folder could not be created.'
        );
    }

    $filename =
        'course-'
        . bin2hex(
            random_bytes(10)
        )
        . '.'
        . $extensions[$mime];

    $absolutePath =
        $directory
        . '/'
        . $filename;

    if (
        !move_uploaded_file(
            $temporaryPath,
            $absolutePath
        )
    ) {
        throw new RuntimeException(
            'The course image could not be saved.'
        );
    }

    $webpAbsolute =
        course_edit_generate_webp(
            $absolutePath,
            $mime
        );

    return [
        'reference' =>
            'uploads/courses/'
            . $filename,

        'absolute' =>
            $absolutePath,

        'webp_absolute' =>
            $webpAbsolute,
    ];
}


function course_edit_remove_uploaded_files(
    array $upload
): void {
    $paths = [
        $upload['webp_absolute']
        ?? null,

        $upload['absolute']
        ?? null,
    ];

    foreach ($paths as $path) {
        if (
            is_string($path)
            && $path !== ''
            && is_file($path)
        ) {
            @unlink($path);
        }
    }
}


function course_edit_remove_course_image_files(
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
            'uploads/courses/'
        )
    ) {
        return;
    }

    $absolute =
        dirname(__DIR__)
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


function course_edit_audit(
    PDO $pdo,
    int $actorUserId,
    int $courseId,
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
                'course.update',

            'entity_type' =>
                'course',

            'entity_id' =>
                $courseId,

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
            'Course update audit error: '
            . $exception->getMessage()
        );
    }
}


/*
|--------------------------------------------------------------------------
| Load Course
|--------------------------------------------------------------------------
*/

$courseStatement =
    $pdo->prepare(
        '
        SELECT
            id,
            title,
            slug,
            course_code,
            short_description,
            description,
            course_image,
            category_id,
            course_forum_id,
            prerequisite_mode,
            status,
            created_at,
            updated_at

        FROM courses

        WHERE id = :course_id

        LIMIT 1
        '
    );

$courseStatement->execute([
    'course_id' =>
        $courseId,
]);

$course =
    $courseStatement->fetch(
        PDO::FETCH_ASSOC
    );

if (!is_array($course)) {
    http_response_code(404);

    $pageTitle =
        'Course Not Found | Blackthorne Academy';

    $pageDescription =
        'The requested course could not be found.';

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
                Course Management
            </p>

            <h1>
                Course Not Found
            </h1>

            <p>
                The requested course does not exist.
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


/*
|--------------------------------------------------------------------------
| Current Offering
|--------------------------------------------------------------------------
|
| Until dedicated Course Offerings management is built, this editor manages
| the most recently created offering attached to the course.
|
*/

$offeringStatement =
    $pdo->prepare(
        '
        SELECT
            id,
            offering_scope,
            school_year_id,
            pacing_mode,
            drip_basis,
            course_start_date,
            course_end_date,
            status

        FROM course_offerings

        WHERE course_id = :course_id

        ORDER BY
            CASE
                WHEN offering_scope = "perpetual" THEN 0
                ELSE 1
            END ASC,
            created_at DESC,
            id DESC

        LIMIT 1
        '
    );

$offeringStatement->execute([
    'course_id' =>
        $courseId,
]);

$offering =
    $offeringStatement->fetch(
        PDO::FETCH_ASSOC
    );

if (!is_array($offering)) {
    $offering = null;
}

$offeringId =
    $offering !== null
        ? (int) $offering['id']
        : 0;

$isPerpetualOffering =
    $offering !== null
    && (string) ($offering['offering_scope'] ?? '') === 'perpetual';


/*
|--------------------------------------------------------------------------
| Reference Data
|--------------------------------------------------------------------------
*/

$schoolYears =
    $pdo->query(
        '
        SELECT
            id,
            name,
            start_date,
            end_date,
            is_current,
            is_finalized

        FROM school_years

        WHERE is_active = 1

        ORDER BY
            is_current DESC,
            start_date DESC,
            id DESC
        '
    )->fetchAll(
        PDO::FETCH_ASSOC
    );

$yearGroups =
    $pdo->query(
        '
        SELECT
            id,
            name,
            slug,
            year_number,
            description

        FROM year_groups

        WHERE is_active = 1

        ORDER BY
            sort_order ASC,
            year_number ASC,
            name ASC
        '
    )->fetchAll(
        PDO::FETCH_ASSOC
    );

$prerequisiteCoursesStatement =
    $pdo->prepare(
        '
        SELECT
            id,
            title,
            status

        FROM courses

        WHERE id <> :course_id
          AND status <> "archived"

        ORDER BY
            title ASC,
            id ASC
        '
    );

$prerequisiteCoursesStatement->execute([
    'course_id' =>
        $courseId,
]);

$prerequisiteCourses =
    $prerequisiteCoursesStatement->fetchAll(
        PDO::FETCH_ASSOC
    );

$categories =
    $pdo->query(
        '
        SELECT
            id,
            name,
            slug

        FROM course_categories

        WHERE is_active = 1

        ORDER BY
            sort_order ASC,
            name ASC,
            id ASC
        '
    )->fetchAll(
        PDO::FETCH_ASSOC
    );


$courseForumOptions =
    $pdo->query(
        '
        WITH RECURSIVE forum_paths AS (
            SELECT
                f.id,
                f.category_id,
                f.parent_forum_id,
                f.title,
                f.is_visible,
                CAST(f.title AS CHAR(1000)) AS forum_path,
                0 AS hierarchy_depth

            FROM forums f

            WHERE f.parent_forum_id IS NULL

            UNION ALL

            SELECT
                child.id,
                child.category_id,
                child.parent_forum_id,
                child.title,
                child.is_visible,
                CONCAT(
                    parent.forum_path,
                    " → ",
                    child.title
                ) AS forum_path,
                parent.hierarchy_depth + 1 AS hierarchy_depth

            FROM forums child

            INNER JOIN forum_paths parent
                ON parent.id = child.parent_forum_id
        )

        SELECT
            fp.id,
            fp.title,
            fp.parent_forum_id,
            fp.forum_path,
            fp.hierarchy_depth,
            fc.title AS category_title

        FROM forum_paths fp

        INNER JOIN forum_categories fc
            ON fc.id = fp.category_id

        WHERE fp.is_visible = 1
          AND fc.is_visible = 1

        ORDER BY
            fc.sort_order ASC,
            fc.title ASC,
            fp.forum_path ASC,
            fp.id ASC
        '
    )->fetchAll(
        PDO::FETCH_ASSOC
    );

$superAdminUserId =
    configured_super_admin_user_id();

$staffStatement =
    $pdo->prepare(
        '
        SELECT DISTINCT
            u.id,
            u.display_name,
            u.username

        FROM users u

        WHERE u.status = "active"
          AND (
                u.id = :super_admin_user_id
                OR EXISTS (
                    SELECT 1

                    FROM user_roles ur

                    INNER JOIN roles r
                        ON r.id = ur.role_id

                    WHERE ur.user_id = u.id
                      AND ur.is_active = 1
                      AND ur.revoked_at IS NULL
                      AND (
                            ur.expires_at IS NULL
                            OR ur.expires_at > CURRENT_TIMESTAMP
                          )
                      AND r.is_active = 1
                      AND (
                            r.is_staff = 1
                            OR r.grants_all_permissions = 1
                          )
                )
              )

        ORDER BY
            u.display_name ASC,
            u.username ASC,
            u.id ASC
        '
    );

$staffStatement->execute([
    'super_admin_user_id' =>
        $superAdminUserId
        ?? 0,
]);

$availableStaff =
    $staffStatement->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Existing Relationships
|--------------------------------------------------------------------------
*/

$existingPrerequisiteIds =
    array_map(
        'intval',
        $pdo->query(
            '
            SELECT prerequisite_course_id
            FROM course_prerequisites
            WHERE course_id = '
            . (int) $courseId
            . '
            ORDER BY id ASC
            '
        )->fetchAll(
            PDO::FETCH_COLUMN
        )
    );

$existingYearGroupId = 0;

if ($offeringId > 0) {
    $yearGroupStatement =
        $pdo->prepare(
            '
            SELECT year_group_id
            FROM course_offering_year_groups
            WHERE offering_id = :offering_id
            ORDER BY id ASC
            LIMIT 1
            '
        );

    $yearGroupStatement->execute([
        'offering_id' =>
            $offeringId,
    ]);

    $existingYearGroupId =
        (int) (
            $yearGroupStatement->fetchColumn()
            ?: 0
        );
}

$existingInstructorIds = [];
$existingTaIds = [];

if ($offeringId > 0) {
    $courseStaffStatement =
        $pdo->prepare(
            '
            SELECT
                user_id,
                instructor_role

            FROM course_instructors

            WHERE offering_id = :offering_id
              AND is_active = 1
              AND ended_at IS NULL

            ORDER BY
                FIELD(
                    instructor_role,
                    "primary",
                    "co_instructor",
                    "assistant"
                ),
                assigned_at ASC,
                id ASC
            '
        );

    $courseStaffStatement->execute([
        'offering_id' =>
            $offeringId,
    ]);

    foreach (
        $courseStaffStatement->fetchAll(
            PDO::FETCH_ASSOC
        )
        as $staffRow
    ) {
        $staffId =
            (int) (
                $staffRow['user_id']
                ?? 0
            );

        $role =
            (string) (
                $staffRow['instructor_role']
                ?? ''
            );

        if ($staffId <= 0) {
            continue;
        }

        if ($role === 'assistant') {
            $existingTaIds[] =
                $staffId;
        } else {
            $existingInstructorIds[] =
                $staffId;
        }
    }
}

$tagStatement =
    $pdo->prepare(
        '
        SELECT
            ct.name

        FROM course_tag_assignments cta

        INNER JOIN course_tags ct
            ON ct.id = cta.tag_id

        WHERE cta.course_id = :course_id

        ORDER BY
            ct.name ASC
        '
    );

$tagStatement->execute([
    'course_id' =>
        $courseId,
]);

$existingTags =
    implode(
        ', ',
        array_map(
            'strval',
            $tagStatement->fetchAll(
                PDO::FETCH_COLUMN
            )
        )
    );


/*
|--------------------------------------------------------------------------
| Form State
|--------------------------------------------------------------------------
*/

$form = [
    'course_title' =>
        (string) (
            $course['title']
            ?? ''
        ),

    'short_description' =>
        (string) (
            $course['short_description']
            ?? ''
        ),

    'long_description' =>
        (string) (
            $course['description']
            ?? ''
        ),

    'school_year_id' =>
        $offering !== null
            ? (string) (
                $offering['school_year_id']
                ?? ''
            )
            : '',

    'year_group_id' =>
        $existingYearGroupId > 0
            ? (string) $existingYearGroupId
            : '',

    'prerequisite_mode' =>
        (string) (
            $course['prerequisite_mode']
            ?? (
                $existingPrerequisiteIds !== []
                    ? 'required'
                    : 'unset'
            )
        ),

    'prerequisite_course_ids' =>
        $existingPrerequisiteIds,

    'course_start_date' =>
        $offering !== null
            ? course_edit_form_datetime(
                $offering[
                    'course_start_date'
                ]
                ?? null
            )
            : '',

    'course_end_date' =>
        $offering !== null
            ? course_edit_form_datetime(
                $offering[
                    'course_end_date'
                ]
                ?? null
            )
            : '',

    'course_type' =>
        $offering !== null
            ? (string) (
                $offering['pacing_mode']
                ?? ''
            )
            : '',

    'category_id' =>
        (string) (
            $course['category_id']
            ?? ''
        ),

    'course_forum_id' =>
        (string) (
            $course['course_forum_id']
            ?? ''
        ),

    'new_category_name' =>
        '',

    'tags' =>
        $existingTags,

    'instructor_ids' =>
        $existingInstructorIds,

    'ta_ids' =>
        $existingTaIds,

    'status' =>
        (string) (
            $course['status']
            ?? 'draft'
        ),
];

if ($isPerpetualOffering) {
    $form['school_year_id'] = '';
    $form['year_group_id'] = '';
    $form['course_type'] = 'self_paced';
    $form['course_start_date'] = '';
    $form['course_end_date'] = '';
}

$errors = [];


/*
|--------------------------------------------------------------------------
| Update Submission
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

    $form['course_title'] =
        course_edit_post_string(
            'course_title'
        );

    $form['short_description'] =
        course_edit_post_string(
            'short_description'
        );

    $form['long_description'] =
        course_edit_post_string(
            'long_description'
        );

    $form['school_year_id'] =
        (string) course_edit_post_id(
            'school_year_id'
        );

    $form['year_group_id'] =
        (string) course_edit_post_id(
            'year_group_id'
        );

    $form['prerequisite_mode'] =
        course_edit_post_string(
            'prerequisite_mode'
        );

    $form['prerequisite_course_ids'] =
        course_edit_post_ids(
            'prerequisite_course_ids'
        );

    $form['course_start_date'] =
        course_edit_post_string(
            'course_start_date'
        );

    $form['course_end_date'] =
        course_edit_post_string(
            'course_end_date'
        );

    $form['course_type'] =
        course_edit_post_string(
            'course_type'
        );

    $form['category_id'] =
        (string) course_edit_post_id(
            'category_id'
        );

    $form['course_forum_id'] =
        (string) course_edit_post_id(
            'course_forum_id'
        );

    $form['new_category_name'] =
        course_edit_post_string(
            'new_category_name'
        );

    $form['tags'] =
        course_edit_post_string(
            'tags'
        );

    $form['instructor_ids'] =
        course_edit_post_ids(
            'instructor_ids'
        );

    $form['ta_ids'] =
        course_edit_post_ids(
            'ta_ids'
        );

    $form['status'] =
        course_edit_post_string(
            'status'
        );

    if ($isPerpetualOffering) {
        $form['school_year_id'] = '';
        $form['year_group_id'] = '';
        $form['course_type'] = 'self_paced';
        $form['course_start_date'] = '';
        $form['course_end_date'] = '';
    }


    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    if (
        course_edit_text_length(
            $form['course_title']
        ) > 150
    ) {
        $errors[] =
            'Course Title must be 150 characters or fewer.';
    }

    if (
        !in_array(
            $form['status'],
            [
                'draft',
                'scheduled',
                'published',
            ],
            true
        )
    ) {
        $errors[] =
            'Choose a valid course status.';
    }

    if (
        !in_array(
            $form['prerequisite_mode'],
            [
                'unset',
                'none',
                'required',
            ],
            true
        )
    ) {
        $errors[] =
            'Choose a valid prerequisite option.';
    }

    if (
        $form['prerequisite_mode']
        === 'required'
        && $form['prerequisite_course_ids']
        === []
    ) {
        $errors[] =
            'Select at least one prerequisite course, or choose None.';
    }

    if (
        $form['prerequisite_mode']
        !== 'required'
    ) {
        $form['prerequisite_course_ids'] = [];
    }

    if (
        $form['course_type'] !== ''
        && !in_array(
            $form['course_type'],
            [
                'self_paced',
                'drip',
            ],
            true
        )
    ) {
        $errors[] =
            'Choose a valid Course Type.';
    }

    $isPublicationState =
        in_array(
            $form['status'],
            [
                'scheduled',
                'published',
            ],
            true
        );

    if ($isPublicationState) {
        if ($form['course_title'] === '') {
            $errors[] =
                'Course Title is required before a course can be scheduled or published.';
        }

        if ($form['short_description'] === '') {
            $errors[] =
                'Short Description is required before a course can be scheduled or published.';
        }

        if ($form['long_description'] === '') {
            $errors[] =
                'Long Description is required before a course can be scheduled or published.';
        }

        if (
            !in_array(
                $form['prerequisite_mode'],
                [
                    'none',
                    'required',
                ],
                true
            )
        ) {
            $errors[] =
                'Prerequisites must be completed before a course can be scheduled or published.';
        }

        if (!$isPerpetualOffering) {
            if (
                (int) $form['school_year_id']
                <= 0
            ) {
                $errors[] =
                    'School Year is required before a course can be scheduled or published.';
            }

            if (
                (int) $form['year_group_id']
                <= 0
            ) {
                $errors[] =
                    'Grade Level is required before a course can be scheduled or published.';
            }
        }

        if (
            !$isPerpetualOffering
            && $form['course_type'] === ''
        ) {
            $errors[] =
                'Course Type is required before a course can be scheduled or published.';
        }
    }

    $schoolYearId =
        (int) $form['school_year_id'];

    $yearGroupId =
        (int) $form['year_group_id'];

    $categoryId =
        (int) $form['category_id'];

    $courseForumId =
        (int) $form['course_forum_id'];

    $courseStartDate =
        course_edit_normalize_datetime(
            $form['course_start_date']
        );

    $courseEndDate =
        course_edit_normalize_datetime(
            $form['course_end_date']
        );

    if (
        $form['course_start_date'] !== ''
        && $courseStartDate === null
    ) {
        $errors[] =
            'Start Date is invalid.';
    }

    if (
        $form['course_end_date'] !== ''
        && $courseEndDate === null
    ) {
        $errors[] =
            'End Date is invalid.';
    }

    if (
        $courseStartDate !== null
        && $courseEndDate !== null
        && strtotime($courseEndDate)
            < strtotime($courseStartDate)
    ) {
        $errors[] =
            'End Date cannot be earlier than Start Date.';
    }

    $hasOfferingDetails =
        !$isPerpetualOffering
        && (
            $schoolYearId > 0
            || $yearGroupId > 0
            || $form['course_type'] !== ''
            || $courseStartDate !== null
            || $courseEndDate !== null
            || $form['instructor_ids'] !== []
            || $form['ta_ids'] !== []
        );

    if (
        $hasOfferingDetails
        && $schoolYearId <= 0
    ) {
        $errors[] =
            'Choose a School Year to save offering details.';
    }

    if (
        $hasOfferingDetails
        && $form['course_type'] === ''
    ) {
        $errors[] =
            'Choose a Course Type to save offering details.';
    }

    if ($schoolYearId > 0) {
        $statement =
            $pdo->prepare(
                '
                SELECT 1
                FROM school_years
                WHERE id = :id
                  AND is_active = 1
                LIMIT 1
                '
            );

        $statement->execute([
            'id' =>
                $schoolYearId,
        ]);

        if (
            $statement->fetchColumn()
            === false
        ) {
            $errors[] =
                'The selected School Year is not available.';
        }
    }

    if ($yearGroupId > 0) {
        $statement =
            $pdo->prepare(
                '
                SELECT 1
                FROM year_groups
                WHERE id = :id
                  AND is_active = 1
                LIMIT 1
                '
            );

        $statement->execute([
            'id' =>
                $yearGroupId,
        ]);

        if (
            $statement->fetchColumn()
            === false
        ) {
            $errors[] =
                'The selected Grade Level is not available.';
        }
    }

    if ($courseForumId > 0) {
        $statement =
            $pdo->prepare(
                '
                SELECT 1

                FROM forums f

                INNER JOIN forum_categories fc
                    ON fc.id = f.category_id

                WHERE f.id = :id
                  AND f.is_visible = 1
                  AND fc.is_visible = 1

                LIMIT 1
                '
            );

        $statement->execute([
            'id' =>
                $courseForumId,
        ]);

        if (
            $statement->fetchColumn()
            === false
        ) {
            $errors[] =
                'The selected Course Forum is not available.';
        }
    }

    if (
        $categoryId > 0
        && $form['new_category_name'] === ''
    ) {
        $statement =
            $pdo->prepare(
                '
                SELECT 1
                FROM course_categories
                WHERE id = :id
                  AND is_active = 1
                LIMIT 1
                '
            );

        $statement->execute([
            'id' =>
                $categoryId,
        ]);

        if (
            $statement->fetchColumn()
            === false
        ) {
            $errors[] =
                'The selected Category is not available.';
        }
    }

    if (
        $form['new_category_name'] !== ''
        && course_edit_text_length(
            $form['new_category_name']
        ) > 100
    ) {
        $errors[] =
            'New Category names must be 100 characters or fewer.';
    }

    if (
        in_array(
            $courseId,
            $form['prerequisite_course_ids'],
            true
        )
    ) {
        $errors[] =
            'A course cannot be its own prerequisite.';
    }

    if (
        $form['prerequisite_course_ids']
        !== []
    ) {
        $placeholders =
            implode(
                ',',
                array_fill(
                    0,
                    count(
                        $form[
                            'prerequisite_course_ids'
                        ]
                    ),
                    '?'
                )
            );

        $statement =
            $pdo->prepare(
                '
                SELECT id
                FROM courses
                WHERE id IN ('
                . $placeholders
                . ')
                  AND id <> ?
                  AND status <> "archived"
                '
            );

        $statement->execute(
            array_merge(
                $form[
                    'prerequisite_course_ids'
                ],
                [
                    $courseId,
                ]
            )
        );

        $foundIds =
            array_map(
                'intval',
                $statement->fetchAll(
                    PDO::FETCH_COLUMN
                )
            );

        sort($foundIds);

        $submittedIds =
            $form[
                'prerequisite_course_ids'
            ];

        sort($submittedIds);

        if (
            $foundIds
            !== $submittedIds
        ) {
            $errors[] =
                'One or more prerequisite courses are no longer available.';
        }
    }

    $duplicateStaffIds =
        array_values(
            array_intersect(
                $form['instructor_ids'],
                $form['ta_ids']
            )
        );

    if ($duplicateStaffIds !== []) {
        $errors[] =
            'The same person cannot be assigned as both an Instructor and a Teaching Assistant.';
    }

    $tags =
        course_edit_parse_tags(
            $form['tags']
        );

    if (count($tags) > 15) {
        $errors[] =
            'Use no more than 15 tags per course.';
    }

    foreach ($tags as $tag) {
        if (
            course_edit_text_length(
                $tag
            ) > 80
        ) {
            $errors[] =
                'Each tag must be 80 characters or fewer.';
            break;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Save Update
    |--------------------------------------------------------------------------
    */

    $newImageUpload = [
        'reference' => null,
        'absolute' => null,
        'webp_absolute' => null,
    ];

    $oldImageReference =
        (string) (
            $course['course_image']
            ?? ''
        );

    if ($errors === []) {
        try {
            $newImageUpload =
                course_edit_upload_image(
                    $_FILES['course_image']
                    ?? []
                );

            $pdo->beginTransaction();

            /*
            |--------------------------------------------------------------
            | Category
            |--------------------------------------------------------------
            */

            if (
                $form['new_category_name']
                !== ''
            ) {
                $categoryLookupStatement =
                    $pdo->prepare(
                        '
                        SELECT id
                        FROM course_categories
                        WHERE LOWER(name) = LOWER(:name)
                        LIMIT 1
                        '
                    );

                $categoryLookupStatement->execute([
                    'name' =>
                        $form[
                            'new_category_name'
                        ],
                ]);

                $existingCategoryId =
                    $categoryLookupStatement->fetchColumn();

                if (
                    $existingCategoryId
                    !== false
                ) {
                    $categoryId =
                        (int) $existingCategoryId;
                } else {
                    $categoryInsertStatement =
                        $pdo->prepare(
                            '
                            INSERT INTO course_categories (
                                name,
                                slug,
                                is_active,
                                sort_order
                            ) VALUES (
                                :name,
                                :slug,
                                1,
                                0
                            )
                            '
                        );

                    $categoryInsertStatement->execute([
                        'name' =>
                            $form[
                                'new_category_name'
                            ],

                        'slug' =>
                            course_edit_unique_category_slug(
                                $pdo,
                                $form[
                                    'new_category_name'
                                ]
                            ),
                    ]);

                    $categoryId =
                        (int) $pdo->lastInsertId();
                }
            }


            /*
            |--------------------------------------------------------------
            | Master Course
            |--------------------------------------------------------------
            */

            $courseImageReference =
                $newImageUpload['reference']
                ?? null;

            if ($courseImageReference === null) {
                $courseImageReference =
                    $oldImageReference !== ''
                        ? $oldImageReference
                        : null;
            }

            $updateCourseStatement =
                $pdo->prepare(
                    '
                    UPDATE courses

                    SET
                        title = :title,
                        short_description = :short_description,
                        description = :description,
                        course_image = :course_image,
                        category_id = :category_id,
                        course_forum_id = :course_forum_id,
                        prerequisite_mode = :prerequisite_mode,
                        status = :status

                    WHERE id = :course_id
                    '
                );

            $updateCourseStatement->execute([
                'title' =>
                    $form['course_title'],

                'short_description' =>
                    $form['short_description'] !== ''
                        ? $form['short_description']
                        : null,

                'description' =>
                    $form['long_description'] !== ''
                        ? $form['long_description']
                        : null,

                'course_image' =>
                    $courseImageReference,

                'category_id' =>
                    $categoryId > 0
                        ? $categoryId
                        : null,

                'course_forum_id' =>
                    $courseForumId > 0
                        ? $courseForumId
                        : null,

                'prerequisite_mode' =>
                    $form[
                        'prerequisite_mode'
                    ],

                'status' =>
                    $form['status'],

                'course_id' =>
                    $courseId,
            ]);


            /*
            |--------------------------------------------------------------
            | Prerequisites
            |--------------------------------------------------------------
            */

            $deletePrerequisitesStatement =
                $pdo->prepare(
                    '
                    DELETE FROM course_prerequisites
                    WHERE course_id = :course_id
                    '
                );

            $deletePrerequisitesStatement->execute([
                'course_id' =>
                    $courseId,
            ]);

            if (
                $form['prerequisite_mode']
                === 'required'
            ) {
                $insertPrerequisiteStatement =
                    $pdo->prepare(
                        '
                        INSERT INTO course_prerequisites (
                            course_id,
                            prerequisite_course_id,
                            is_required,
                            completion_requirement,
                            minimum_percentage
                        ) VALUES (
                            :course_id,
                            :prerequisite_course_id,
                            1,
                            "completed",
                            NULL
                        )
                        '
                    );

                foreach (
                    $form[
                        'prerequisite_course_ids'
                    ]
                    as $prerequisiteCourseId
                ) {
                    $insertPrerequisiteStatement->execute([
                        'course_id' =>
                            $courseId,

                        'prerequisite_course_id' =>
                            $prerequisiteCourseId,
                    ]);
                }
            }


            /*
            |--------------------------------------------------------------
            | Tags
            |--------------------------------------------------------------
            */

            $deleteTagAssignmentsStatement =
                $pdo->prepare(
                    '
                    DELETE FROM course_tag_assignments
                    WHERE course_id = :course_id
                    '
                );

            $deleteTagAssignmentsStatement->execute([
                'course_id' =>
                    $courseId,
            ]);

            if ($tags !== []) {
                $tagLookupStatement =
                    $pdo->prepare(
                        '
                        SELECT id
                        FROM course_tags
                        WHERE LOWER(name) = LOWER(:name)
                        LIMIT 1
                        '
                    );

                $tagInsertStatement =
                    $pdo->prepare(
                        '
                        INSERT INTO course_tags (
                            name,
                            slug
                        ) VALUES (
                            :name,
                            :slug
                        )
                        '
                    );

                $tagAssignmentStatement =
                    $pdo->prepare(
                        '
                        INSERT INTO course_tag_assignments (
                            course_id,
                            tag_id
                        ) VALUES (
                            :course_id,
                            :tag_id
                        )
                        '
                    );

                foreach ($tags as $tag) {
                    $tagLookupStatement->execute([
                        'name' =>
                            $tag,
                    ]);

                    $tagId =
                        $tagLookupStatement->fetchColumn();

                    if ($tagId === false) {
                        $tagInsertStatement->execute([
                            'name' =>
                                $tag,

                            'slug' =>
                                course_edit_unique_tag_slug(
                                    $pdo,
                                    $tag
                                ),
                        ]);

                        $tagId =
                            (int) $pdo->lastInsertId();
                    } else {
                        $tagId =
                            (int) $tagId;
                    }

                    $tagAssignmentStatement->execute([
                        'course_id' =>
                            $courseId,

                        'tag_id' =>
                            $tagId,
                    ]);
                }
            }


            /*
            |--------------------------------------------------------------
            | Offering
            |--------------------------------------------------------------
            */

            $workingOfferingId =
                $offeringId;

            if ($hasOfferingDetails) {
                if ($workingOfferingId > 0) {
                    $updateOfferingStatement =
                        $pdo->prepare(
                            '
                            UPDATE course_offerings

                            SET
                                school_year_id = :school_year_id,
                                pacing_mode = :pacing_mode,
                                drip_basis = :drip_basis,
                                course_start_date = :course_start_date,
                                course_end_date = :course_end_date

                            WHERE id = :offering_id
                            '
                        );

                    $updateOfferingStatement->execute([
                        'school_year_id' =>
                            $schoolYearId,

                        'pacing_mode' =>
                            $form['course_type'],

                        'drip_basis' =>
                            $form['course_type']
                            === 'drip'
                                ? 'course_start_date'
                                : null,

                        'course_start_date' =>
                            $courseStartDate,

                        'course_end_date' =>
                            $courseEndDate,

                        'offering_id' =>
                            $workingOfferingId,
                    ]);
                } else {
                    $insertOfferingStatement =
                        $pdo->prepare(
                            '
                            INSERT INTO course_offerings (
                                course_id,
                                school_year_id,
                                pacing_mode,
                                drip_basis,
                                course_start_date,
                                course_end_date,
                                status
                            ) VALUES (
                                :course_id,
                                :school_year_id,
                                :pacing_mode,
                                :drip_basis,
                                :course_start_date,
                                :course_end_date,
                                "draft"
                            )
                            '
                        );

                    $insertOfferingStatement->execute([
                        'course_id' =>
                            $courseId,

                        'school_year_id' =>
                            $schoolYearId,

                        'pacing_mode' =>
                            $form['course_type'],

                        'drip_basis' =>
                            $form['course_type']
                            === 'drip'
                                ? 'course_start_date'
                                : null,

                        'course_start_date' =>
                            $courseStartDate,

                        'course_end_date' =>
                            $courseEndDate,
                    ]);

                    $workingOfferingId =
                        (int) $pdo->lastInsertId();
                }


                /*
                |----------------------------------------------------------
                | Grade Level
                |----------------------------------------------------------
                */

                $deleteYearGroupsStatement =
                    $pdo->prepare(
                        '
                        DELETE FROM course_offering_year_groups
                        WHERE offering_id = :offering_id
                        '
                    );

                $deleteYearGroupsStatement->execute([
                    'offering_id' =>
                        $workingOfferingId,
                ]);

                if ($yearGroupId > 0) {
                    $insertYearGroupStatement =
                        $pdo->prepare(
                            '
                            INSERT INTO course_offering_year_groups (
                                offering_id,
                                year_group_id,
                                is_visible,
                                is_enrollable
                            ) VALUES (
                                :offering_id,
                                :year_group_id,
                                1,
                                1
                            )
                            '
                        );

                    $insertYearGroupStatement->execute([
                        'offering_id' =>
                            $workingOfferingId,

                        'year_group_id' =>
                            $yearGroupId,
                    ]);
                }


                /*
                |----------------------------------------------------------
                | Course Staff
                |----------------------------------------------------------
                */

                $deactivateStaffStatement =
                    $pdo->prepare(
                        '
                        UPDATE course_instructors

                        SET
                            is_active = 0,
                            ended_at = CURRENT_TIMESTAMP,
                            ended_by = :ended_by,
                            end_reason = "Course staff assignment updated"

                        WHERE offering_id = :offering_id
                          AND is_active = 1
                        '
                    );

                $deactivateStaffStatement->execute([
                    'ended_by' =>
                        $currentUserId > 0
                            ? $currentUserId
                            : null,

                    'offering_id' =>
                        $workingOfferingId,
                ]);

                $insertCourseStaffStatement =
                    $pdo->prepare(
                        '
                        INSERT INTO course_instructors (
                            offering_id,
                            user_id,
                            instructor_role,
                            is_active
                        ) VALUES (
                            :offering_id,
                            :user_id,
                            :instructor_role,
                            1
                        )
                        '
                    );

                foreach (
                    array_values(
                        $form['instructor_ids']
                    )
                    as $index =>
                    $instructorId
                ) {
                    $insertCourseStaffStatement->execute([
                        'offering_id' =>
                            $workingOfferingId,

                        'user_id' =>
                            $instructorId,

                        'instructor_role' =>
                            $index === 0
                                ? 'primary'
                                : 'co_instructor',
                    ]);
                }

                foreach (
                    $form['ta_ids']
                    as $taId
                ) {
                    $insertCourseStaffStatement->execute([
                        'offering_id' =>
                            $workingOfferingId,

                        'user_id' =>
                            $taId,

                        'instructor_role' =>
                            'assistant',
                    ]);
                }
            }


            /*
            |--------------------------------------------------------------
            | Audit
            |--------------------------------------------------------------
            */

            course_edit_audit(
                $pdo,
                $currentUserId,
                $courseId,
                'Updated course: '
                . (
                    $form['course_title']
                    !== ''
                        ? $form['course_title']
                        : 'Untitled Draft'
                )
                . ' ['
                . $form['status']
                . ']'
            );

            $pdo->commit();

            if (
                ($newImageUpload['reference']
                ?? null) !== null
                && $oldImageReference !== ''
                && $oldImageReference
                    !== $newImageUpload['reference']
            ) {
                course_edit_remove_course_image_files(
                    $oldImageReference
                );
            }

            set_flash(
                'success',
                'Course updated successfully.'
            );

            redirect(
                url(
                    'admin/course-edit.php?id='
                    . $courseId
                )
            );
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            course_edit_remove_uploaded_files(
                $newImageUpload
            );

            error_log(
                'Course update error: '
                . $exception->getMessage()
            );

            $errors[] =
                'The course could not be updated. No changes were saved.';
        }
    }
}


/*
|--------------------------------------------------------------------------
| Shared Courses Sidebar
|--------------------------------------------------------------------------
*/

$courseSidebarActive = 'edit-course';

$courseSidebarEditCourseId =
    $courseId;

require INCLUDES_PATH . '/staff-course-sidebar.php';


/*
|--------------------------------------------------------------------------
| SEO / Header
|--------------------------------------------------------------------------
*/

$courseDisplayTitle =
    trim(
        (string) (
            $course['title']
            ?? ''
        )
    );

$currentImageReference =
    trim(
        (string) (
            $course['course_image']
            ?? ''
        )
    );

$currentImageUrl =
    $currentImageReference !== ''
        ? url(
            ltrim(
                $currentImageReference,
                '/'
            )
        )
        : '';

if ($courseDisplayTitle === '') {
    $courseDisplayTitle =
        'Untitled Draft';
}

$pageTitle =
    'Edit '
    . $courseDisplayTitle
    . ' | Blackthorne Academy';

$pageDescription =
    'Edit a Blackthorne Academy course.';

$pageCanonical =
    url(
        'admin/course-edit.php?id='
        . $courseId
    );

$robots =
    'noindex, nofollow';

require INCLUDES_PATH . '/header.php';

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
    class="dashboard-page staff-dashboard-page dashboard-workspace-page courses-dashboard-page course-edit-page">

    <section class="dashboard-hero staff-dashboard-hero" aria-labelledby="course-edit-heading"
        <?php if ($staffHeroUrl !== ''): ?> style="--staff-dashboard-hero-image: url('<?= e($staffHeroUrl); ?>');"
        <?php endif; ?>>
        <div class="section-inner">
            <div class="dashboard-hero-inner">

                <p class="academy-overline">
                    Academic Administration
                </p>

                <h1 id="course-edit-heading">
                    Edit Course
                </h1>

                <p class="dashboard-hero-copy">
                    <?= e($courseDisplayTitle); ?>
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
                            Course Builder
                        </p>

                        <h2>
                            Edit course information
                        </h2>

                        <p>
                            Drafts may remain incomplete. Scheduled and
                            Published courses must contain every required
                            publishing field.
                        </p>
                    </div>
                </header>


                <?php if ($errors !== []): ?>

                <div class="form-message form-message-error" role="alert">
                    <strong>
                        The course could not be updated.
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
                            Course #<?= $courseId; ?>
                        </p>

                        <h2>
                            <?= e($courseDisplayTitle); ?>
                        </h2>
                    </header>


                    <form action="<?= e(
                            url(
                                'admin/course-edit.php?id='
                                . $courseId
                            )
                        ); ?>" method="post" enctype="multipart/form-data" class="forum-admin-form">
                        <?= csrf_field(); ?>


                        <div class="form-group">
                            <label for="course-title">
                                Course Title *
                            </label>

                            <input class="form-control" type="text" id="course-title" name="course_title"
                                maxlength="150" value="<?= e($form['course_title']); ?>">
                        </div>


                        <div class="form-group">
                            <label for="course-image">
                                Course Thumbnail / Image
                            </label>

                            <?php if ($currentImageUrl !== ''): ?>
                            <div style="margin-bottom: 14px;">
                                <img src="<?= e($currentImageUrl); ?>"
                                    alt="<?= e($courseDisplayTitle . ' course thumbnail'); ?>"
                                    style="max-width: 260px; max-height: 180px; width: auto; height: auto; object-fit: contain; border-radius: 8px;">
                            </div>
                            <?php endif; ?>

                            <input class="form-control" type="file" id="course-image" name="course_image"
                                accept="image/jpeg,image/png,image/webp,image/avif">

                            <p class="form-help">
                                Leave this blank to keep the current image.
                                Uploading a new image replaces the previous one.
                            </p>
                        </div>


                        <div class="form-group">
                            <label for="short-description">
                                Short Description *
                            </label>

                            <textarea class="form-control" id="short-description" name="short_description"
                                rows="4"><?= e($form['short_description']); ?></textarea>
                        </div>


                        <div class="form-group">
                            <label for="long-description">
                                Long Description *
                            </label>

                            <textarea class="form-control" id="long-description" name="long_description"
                                rows="8"><?= e($form['long_description']); ?></textarea>
                        </div>


                        <?php if ($isPerpetualOffering): ?>

                        <div class="course-perpetual-notice">
                            <p class="academy-overline">
                                Perpetual Offering
                            </p>

                            <h3>
                                Always Open · Self-Paced
                            </h3>

                            <p>
                                This course is not tied to a school year or
                                grade level and does not require start or end
                                dates. Students remain enrolled until they
                                complete the course.
                            </p>
                        </div>

                        <?php else: ?>

                        <div class="forum-admin-form-grid">

                            <div class="form-group">
                                <label for="school-year-id">
                                    School Year *
                                </label>

                                <select class="form-control" id="school-year-id" name="school_year_id">
                                    <option value="">
                                        Choose a school year
                                    </option>

                                    <?php foreach ($schoolYears as $schoolYear): ?>
                                    <option value="<?= (int) $schoolYear['id']; ?>" <?= (int) $form['school_year_id'] === (int) $schoolYear['id']
                                                    ? 'selected'
                                                    : ''; ?>>
                                        <?= e((string) $schoolYear['name']); ?>
                                        <?= (int) ($schoolYear['is_current'] ?? 0) === 1
                                                    ? ' — Current'
                                                    : ''; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="year-group-id">
                                    Grade Level *
                                </label>

                                <select class="form-control" id="year-group-id" name="year_group_id">
                                    <option value="">
                                        Choose a grade level
                                    </option>

                                    <?php foreach ($yearGroups as $yearGroup): ?>
                                    <option value="<?= (int) $yearGroup['id']; ?>" <?= (int) $form['year_group_id'] === (int) $yearGroup['id']
                                                    ? 'selected'
                                                    : ''; ?>>
                                        <?= e((string) $yearGroup['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                        </div>

                        <?php endif; ?>


                        <fieldset class="forum-admin-fieldset">
                            <legend>
                                Prerequisites *
                            </legend>

                            <label class="forum-admin-choice">
                                <input type="radio" name="prerequisite_mode" value="unset" <?= $form['prerequisite_mode'] === 'unset'
                                        ? 'checked'
                                        : ''; ?> data-prerequisite-mode>

                                <span>
                                    Not decided yet
                                </span>
                            </label>

                            <label class="forum-admin-choice">
                                <input type="radio" name="prerequisite_mode" value="none" <?= $form['prerequisite_mode'] === 'none'
                                        ? 'checked'
                                        : ''; ?> data-prerequisite-mode>

                                <span>
                                    None
                                </span>
                            </label>

                            <label class="forum-admin-choice">
                                <input type="radio" name="prerequisite_mode" value="required" <?= $form['prerequisite_mode'] === 'required'
                                        ? 'checked'
                                        : ''; ?> data-prerequisite-mode>

                                <span>
                                    Select prerequisite course(s)
                                </span>
                            </label>

                            <div class="forum-admin-role-box" data-prerequisite-course-box <?= $form['prerequisite_mode'] === 'required'
                                    ? ''
                                    : 'hidden'; ?>>
                                <?php if ($prerequisiteCourses === []): ?>

                                <p class="form-help">
                                    No other courses are currently available
                                    as prerequisites.
                                </p>

                                <?php else: ?>

                                <?php foreach ($prerequisiteCourses as $prerequisiteCourse): ?>
                                <?php
                                        $prerequisiteTitle =
                                            trim(
                                                (string) (
                                                    $prerequisiteCourse['title']
                                                    ?? ''
                                                )
                                            );

                                        if ($prerequisiteTitle === '') {
                                            $prerequisiteTitle =
                                                'Untitled Course #'
                                                . (int) $prerequisiteCourse['id'];
                                        }
                                        ?>

                                <label>
                                    <input type="checkbox" name="prerequisite_course_ids[]"
                                        value="<?= (int) $prerequisiteCourse['id']; ?>" <?= in_array(
                                                    (int) $prerequisiteCourse['id'],
                                                    $form['prerequisite_course_ids'],
                                                    true
                                                )
                                                    ? 'checked'
                                                    : ''; ?>>

                                    <span>
                                        <?= e($prerequisiteTitle); ?>
                                    </span>
                                </label>

                                <?php endforeach; ?>

                                <?php endif; ?>
                            </div>
                        </fieldset>


                        <?php if (!$isPerpetualOffering): ?>

                        <div class="forum-admin-form-grid">

                            <div class="form-group">
                                <label for="course-start-date">
                                    Start Date
                                </label>

                                <input class="form-control" type="datetime-local" id="course-start-date"
                                    name="course_start_date" value="<?= e($form['course_start_date']); ?>">
                            </div>

                            <div class="form-group">
                                <label for="course-end-date">
                                    End Date
                                </label>

                                <input class="form-control" type="datetime-local" id="course-end-date"
                                    name="course_end_date" value="<?= e($form['course_end_date']); ?>">
                            </div>

                        </div>

                        <fieldset class="forum-admin-fieldset">
                            <legend>
                                Course Type *
                            </legend>

                            <label class="forum-admin-choice">
                                <input type="radio" name="course_type" value="self_paced" <?= $form['course_type'] === 'self_paced'
                                            ? 'checked'
                                            : ''; ?>>

                                <span>
                                    Self-paced
                                </span>
                            </label>

                            <label class="forum-admin-choice">
                                <input type="radio" name="course_type" value="drip" <?= $form['course_type'] === 'drip'
                                            ? 'checked'
                                            : ''; ?>>

                                <span>
                                    Drip Content
                                </span>
                            </label>
                        </fieldset>

                        <?php else: ?>

                        <input type="hidden" name="course_type" value="self_paced">

                        <?php endif; ?>


                        <div class="forum-admin-form-grid">

                            <div class="form-group">
                                <label for="category-id">
                                    Category
                                </label>

                                <select class="form-control" id="category-id" name="category_id">
                                    <option value="">
                                        No category
                                    </option>

                                    <?php foreach ($categories as $category): ?>
                                    <option value="<?= (int) $category['id']; ?>" <?= (int) $form['category_id'] === (int) $category['id']
                                                ? 'selected'
                                                : ''; ?>>
                                        <?= e((string) $category['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>


                            <div class="form-group">
                                <label for="new-category-name">
                                    Or Create New Category
                                </label>

                                <input class="form-control" type="text" id="new-category-name" name="new_category_name"
                                    maxlength="100" value="<?= e($form['new_category_name']); ?>">
                            </div>

                        </div>


                        <div class="form-group">
                            <label for="course-forum-id">
                                Course Forum
                            </label>

                            <select class="form-control" id="course-forum-id" name="course_forum_id" data-forum-picker>
                                <option value="">
                                    No dedicated course forum
                                </option>

                                <?php foreach ($courseForumOptions as $forumOption): ?>
                                <option value="<?= (int) $forumOption['id']; ?>"
                                    data-forum-id="<?= (int) $forumOption['id']; ?>"
                                    data-parent-forum-id="<?= (int) ($forumOption['parent_forum_id'] ?? 0); ?>"
                                    data-depth="<?= (int) ($forumOption['hierarchy_depth'] ?? 0); ?>"
                                    data-category-title="<?= e((string) ($forumOption['category_title'] ?? 'Forums')); ?>"
                                    data-forum-title="<?= e((string) ($forumOption['title'] ?? 'Forum')); ?>"
                                    data-forum-path="<?= e((string) ($forumOption['forum_path'] ?? '')); ?>" <?= (int) $form['course_forum_id'] === (int) $forumOption['id']
                                            ? 'selected'
                                            : ''; ?>>
                                    <?= e(
                                            str_repeat(
                                                '    ',
                                                (int) ($forumOption['hierarchy_depth'] ?? 0)
                                            )
                                            . (string) (
                                                $forumOption[
                                                    'title'
                                                ]
                                                ?? 'Forum'
                                            )
                                        ); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>

                            <p class="form-help">
                                This is the course's dedicated discussion forum.
                                Pinned threads in this forum will appear as the
                                latest course announcements in the classroom.
                            </p>
                        </div>


                        <div class="form-group">
                            <label for="course-tags">
                                Tags
                            </label>

                            <input class="form-control" type="text" id="course-tags" name="tags" maxlength="1000"
                                value="<?= e($form['tags']); ?>">

                            <p class="form-help">
                                Separate tags with commas. Up to 15 tags.
                            </p>
                        </div>


                        <div class="forum-admin-form-grid">

                            <div class="form-group">
                                <label for="instructor-ids">
                                    Instructor(s)
                                </label>

                                <select class="form-control" id="instructor-ids" name="instructor_ids[]" multiple
                                    size="8">
                                    <?php foreach ($availableStaff as $staffMember): ?>
                                    <?php
                                        $staffName =
                                            trim(
                                                (string) (
                                                    $staffMember['display_name']
                                                    ?? $staffMember['username']
                                                    ?? 'Staff'
                                                )
                                            );
                                        ?>

                                    <option value="<?= (int) $staffMember['id']; ?>" <?= in_array(
                                                (int) $staffMember['id'],
                                                $form['instructor_ids'],
                                                true
                                            )
                                                ? 'selected'
                                                : ''; ?>>
                                        <?= e($staffName); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>

                                <p class="form-help">
                                    The first selected instructor is stored as
                                    Primary Instructor. Additional selections
                                    are Co-Instructors.
                                </p>
                            </div>


                            <div class="form-group">
                                <label for="ta-ids">
                                    Teaching Assistant(s) / TA(s)
                                </label>

                                <select class="form-control" id="ta-ids" name="ta_ids[]" multiple size="8">
                                    <?php foreach ($availableStaff as $staffMember): ?>
                                    <?php
                                        $staffName =
                                            trim(
                                                (string) (
                                                    $staffMember['display_name']
                                                    ?? $staffMember['username']
                                                    ?? 'Staff'
                                                )
                                            );
                                        ?>

                                    <option value="<?= (int) $staffMember['id']; ?>" <?= in_array(
                                                (int) $staffMember['id'],
                                                $form['ta_ids'],
                                                true
                                            )
                                                ? 'selected'
                                                : ''; ?>>
                                        <?= e($staffName); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                        </div>


                        <fieldset class="forum-admin-fieldset">
                            <legend>
                                Status
                            </legend>

                            <label class="forum-admin-choice">
                                <input type="radio" name="status" value="draft" <?= $form['status'] === 'draft'
                                        ? 'checked'
                                        : ''; ?>>

                                <span>
                                    Draft
                                </span>
                            </label>

                            <label class="forum-admin-choice">
                                <input type="radio" name="status" value="scheduled" <?= $form['status'] === 'scheduled'
                                        ? 'checked'
                                        : ''; ?>>

                                <span>
                                    Scheduled
                                </span>
                            </label>

                            <label class="forum-admin-choice">
                                <input type="radio" name="status" value="published" <?= $form['status'] === 'published'
                                        ? 'checked'
                                        : ''; ?>>

                                <span>
                                    Published
                                </span>
                            </label>
                        </fieldset>


                        <div class="forum-admin-actions">
                            <a class="button button-secondary" href="<?= e(url('admin/courses.php')); ?>">
                                Back to Courses
                            </a>

                            <button type="submit" class="button button-primary">
                                Save Course Changes
                            </button>
                        </div>

                    </form>
                </section>

            </div>

        </div>
    </section>

</main>


<script>
    (function() {
        'use strict';

        var modes =
            document.querySelectorAll(
                '[data-prerequisite-mode]'
            );

        var box =
            document.querySelector(
                '[data-prerequisite-course-box]'
            );

        if (!modes.length || !box) {
            return;
        }

        function syncPrerequisiteBox() {
            var selected =
                document.querySelector(
                    '[data-prerequisite-mode]:checked'
                );

            box.hidden = !selected ||
                selected.value !== 'required';
        }

        modes.forEach(function(mode) {
            mode.addEventListener(
                'change',
                syncPrerequisiteBox
            );
        });

        syncPrerequisiteBox();
    })();

</script>


<style>
    .course-perpetual-notice {
        margin-bottom: 1.25rem;
        padding: 1rem 1.1rem;
        border: 1px solid rgba(197, 157, 85, 0.28);
        border-radius: 0.8rem;
        background: rgba(197, 157, 85, 0.06);
    }

    .course-perpetual-notice .academy-overline {
        margin-bottom: 0.45rem;
    }

    .course-perpetual-notice h3 {
        margin: 0 0 0.45rem;
    }

    .course-perpetual-notice p:last-child {
        margin-bottom: 0;
    }

</style>

<?php

require INCLUDES_PATH . '/footer.php';
