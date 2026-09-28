<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';


/*
|--------------------------------------------------------------------------
| Authentication / Access
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

$canCreateCourses =
    $isProtectedSuperAdmin
    || user_can('courses.create');

if (
    !$hasStaffIdentity
    || !$canCreateCourses
) {
    http_response_code(403);

    $pageTitle =
        'Access Denied | Blackthorne Academy';

    $pageDescription =
        'You do not have permission to create Blackthorne Academy courses.';

    $pageCanonical =
        url('admin/courses.php');

    $robots =
        'noindex, nofollow';

    require INCLUDES_PATH . '/header.php';
    ?>

    <main
        id="main-content"
        class="forum-board-page"
    >
        <section class="forum-board-error">
            <div class="section-inner">
                <p class="academy-overline">
                    Restricted Staff Area
                </p>

                <h1>
                    Access Denied
                </h1>

                <p>
                    Your account does not have permission to create courses.
                </p>

                <a
                    class="button button-secondary"
                    href="<?= e(url('admin/courses.php')); ?>"
                >
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
| Local Helpers
|--------------------------------------------------------------------------
*/

function course_create_text_length(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);
}


function course_create_slug_base(string $value): string
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


function course_create_unique_slug(
    PDO $pdo,
    string $title
): string {
    $base =
        course_create_slug_base($title);

    if ($base === '') {
        $base =
            'draft-'
            . bin2hex(
                random_bytes(5)
            );
    }

    $base =
        substr(
            $base,
            0,
            145
        );

    $candidate = $base;
    $suffix = 2;

    $statement =
        $pdo->prepare(
            '
            SELECT 1
            FROM courses
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
                145
            )
            . '-'
            . $suffix;

        $suffix++;
    }
}


function course_create_unique_category_slug(
    PDO $pdo,
    string $name
): string {
    $base =
        course_create_slug_base($name);

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


function course_create_unique_tag_slug(
    PDO $pdo,
    string $name
): string {
    $base =
        course_create_slug_base($name);

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


function course_create_post_string(string $key): string
{
    $value =
        $_POST[$key]
        ?? '';

    return is_scalar($value)
        ? trim((string) $value)
        : '';
}


function course_create_post_id(string $key): int
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


function course_create_post_ids(string $key): array
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


function course_create_normalize_datetime(
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


function course_create_parse_tags(
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


function course_create_generate_webp(
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


function course_create_upload_image(
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
        course_create_generate_webp(
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


function course_create_delete_uploaded_image(
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


function course_create_audit(
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
                'course.create',

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
            'Course create audit error: '
            . $exception->getMessage()
        );
    }
}


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

$prerequisiteCourses =
    $pdo->query(
        '
        SELECT
            id,
            title,
            status

        FROM courses

        WHERE status <> "archived"

        ORDER BY
            title ASC,
            id ASC
        '
    )->fetchAll(
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

/*
|--------------------------------------------------------------------------
| Staff Options
|--------------------------------------------------------------------------
*/

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
| Form State
|--------------------------------------------------------------------------
*/

$errors = [];

$form = [
    'course_title' => '',
    'short_description' => '',
    'long_description' => '',
    'school_year_id' => '',
    'year_group_id' => '',
    'prerequisite_mode' => 'unset',
    'prerequisite_course_ids' => [],
    'course_start_date' => '',
    'course_end_date' => '',
    'course_type' => '',
    'category_id' => '',
    'course_forum_id' => '',
    'new_category_name' => '',
    'tags' => '',
    'instructor_ids' => [],
    'ta_ids' => [],
    'status' => 'draft',
];


/*
|--------------------------------------------------------------------------
| Form Submission
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
        course_create_post_string(
            'course_title'
        );

    $form['short_description'] =
        course_create_post_string(
            'short_description'
        );

    $form['long_description'] =
        course_create_post_string(
            'long_description'
        );

    $form['school_year_id'] =
        (string) course_create_post_id(
            'school_year_id'
        );

    $form['year_group_id'] =
        (string) course_create_post_id(
            'year_group_id'
        );

    $form['prerequisite_mode'] =
        course_create_post_string(
            'prerequisite_mode'
        );

    $form['prerequisite_course_ids'] =
        course_create_post_ids(
            'prerequisite_course_ids'
        );

    $form['course_start_date'] =
        course_create_post_string(
            'course_start_date'
        );

    $form['course_end_date'] =
        course_create_post_string(
            'course_end_date'
        );

    $form['course_type'] =
        course_create_post_string(
            'course_type'
        );

    $form['category_id'] =
        (string) course_create_post_id(
            'category_id'
        );

    $form['course_forum_id'] =
        (string) course_create_post_id(
            'course_forum_id'
        );

    $form['new_category_name'] =
        course_create_post_string(
            'new_category_name'
        );

    $form['tags'] =
        course_create_post_string(
            'tags'
        );

    $form['instructor_ids'] =
        course_create_post_ids(
            'instructor_ids'
        );

    $form['ta_ids'] =
        course_create_post_ids(
            'ta_ids'
        );

    $form['status'] =
        course_create_post_string(
            'status'
        );


    /*
    |--------------------------------------------------------------------------
    | Basic Validation
    |--------------------------------------------------------------------------
    */

    if (
        course_create_text_length(
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


    /*
    |--------------------------------------------------------------------------
    | Publication Requirements
    |--------------------------------------------------------------------------
    */

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
                'Prerequisites must be completed before a course can be scheduled or published. Choose None or select prerequisite courses.';
        }

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

        if ($form['course_type'] === '') {
            $errors[] =
                'Course Type is required before a course can be scheduled or published.';
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Validate School Year / Grade Level / Category
    |--------------------------------------------------------------------------
    */

    $schoolYearId =
        (int) $form['school_year_id'];

    $yearGroupId =
        (int) $form['year_group_id'];

    $categoryId =
        (int) $form['category_id'];

    $courseForumId =
        (int) $form['course_forum_id'];

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
            'id' => $schoolYearId,
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
            'id' => $yearGroupId,
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
            'id' => $categoryId,
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
        && course_create_text_length(
            $form['new_category_name']
        ) > 100
    ) {
        $errors[] =
            'New Category names must be 100 characters or fewer.';
    }


    /*
    |--------------------------------------------------------------------------
    | Offering Validation
    |--------------------------------------------------------------------------
    */

    $courseStartDate =
        course_create_normalize_datetime(
            $form['course_start_date']
        );

    $courseEndDate =
        course_create_normalize_datetime(
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
        $schoolYearId > 0
        || $yearGroupId > 0
        || $form['course_type'] !== ''
        || $courseStartDate !== null
        || $courseEndDate !== null
        || $form['instructor_ids'] !== []
        || $form['ta_ids'] !== [];

    if (
        $hasOfferingDetails
        && $schoolYearId <= 0
    ) {
        $errors[] =
            'Choose a School Year to save offering details such as dates, Grade Level, Instructors, or TAs.';
    }

    if (
        $hasOfferingDetails
        && $form['course_type'] === ''
    ) {
        $errors[] =
            'Choose a Course Type to save offering details.';
    }


    /*
    |--------------------------------------------------------------------------
    | Validate Prerequisites
    |--------------------------------------------------------------------------
    */

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
                  AND status <> "archived"
                '
            );

        $statement->execute(
            $form[
                'prerequisite_course_ids'
            ]
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


    /*
    |--------------------------------------------------------------------------
    | Validate Staff Selections
    |--------------------------------------------------------------------------
    */

    $duplicateStaffIds =
        array_values(
            array_intersect(
                $form['instructor_ids'],
                $form['ta_ids']
            )
        );

    if ($duplicateStaffIds !== []) {
        $errors[] =
            'The same person cannot be assigned as both an Instructor and a Teaching Assistant for the same course offering.';
    }

    $selectedStaffIds =
        array_values(
            array_unique(
                array_merge(
                    $form['instructor_ids'],
                    $form['ta_ids']
                )
            )
        );

    if ($selectedStaffIds !== []) {
        $placeholders =
            implode(
                ',',
                array_fill(
                    0,
                    count(
                        $selectedStaffIds
                    ),
                    '?'
                )
            );

        $staffValidationStatement =
            $pdo->prepare(
                '
                SELECT DISTINCT
                    u.id

                FROM users u

                WHERE u.id IN ('
                . $placeholders
                . ')
                  AND u.status = "active"
                  AND (
                        u.id = ?
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
                '
            );

        $staffValidationParams =
            array_merge(
                $selectedStaffIds,
                [
                    $superAdminUserId
                    ?? 0,
                ]
            );

        $staffValidationStatement->execute(
            $staffValidationParams
        );

        $validStaffIds =
            array_map(
                'intval',
                $staffValidationStatement->fetchAll(
                    PDO::FETCH_COLUMN
                )
            );

        sort($validStaffIds);

        $comparisonStaffIds =
            $selectedStaffIds;

        sort($comparisonStaffIds);

        if (
            $validStaffIds
            !== $comparisonStaffIds
        ) {
            $errors[] =
                'One or more selected course staff members are no longer eligible.';
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Validate Tags
    |--------------------------------------------------------------------------
    */

    $tags =
        course_create_parse_tags(
            $form['tags']
        );

    if (count($tags) > 15) {
        $errors[] =
            'Use no more than 15 tags per course.';
    }

    foreach ($tags as $tag) {
        if (
            course_create_text_length(
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
    | Save
    |--------------------------------------------------------------------------
    */

    $imageUpload = [
        'reference' => null,
        'absolute' => null,
        'webp_absolute' => null,
    ];

    if ($errors === []) {
        try {
            $imageUpload =
                course_create_upload_image(
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
                    $newCategorySlug =
                        course_create_unique_category_slug(
                            $pdo,
                            $form[
                                'new_category_name'
                            ]
                        );

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
                            $newCategorySlug,
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

            $courseSlug =
                course_create_unique_slug(
                    $pdo,
                    $form['course_title']
                );

            $courseInsertStatement =
                $pdo->prepare(
                    '
                    INSERT INTO courses (
                        title,
                        slug,
                        short_description,
                        description,
                        course_image,
                        category_id,
                        course_forum_id,
                        prerequisite_mode,
                        status
                    ) VALUES (
                        :title,
                        :slug,
                        :short_description,
                        :description,
                        :course_image,
                        :category_id,
                        :course_forum_id,
                        :prerequisite_mode,
                        :status
                    )
                    '
                );

            $courseInsertStatement->execute([
                'title' =>
                    $form['course_title'],

                'slug' =>
                    $courseSlug,

                'short_description' =>
                    $form['short_description'] !== ''
                        ? $form['short_description']
                        : null,

                'description' =>
                    $form['long_description'] !== ''
                        ? $form['long_description']
                        : null,

                'course_image' =>
                    $imageUpload['reference'],

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
            ]);

            $courseId =
                (int) $pdo->lastInsertId();


            /*
            |--------------------------------------------------------------
            | Prerequisites
            |--------------------------------------------------------------
            */

            if (
                $form['prerequisite_mode']
                === 'required'
            ) {
                $prerequisiteInsertStatement =
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
                    $prerequisiteInsertStatement->execute([
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
                        'name' => $tag,
                    ]);

                    $tagId =
                        $tagLookupStatement->fetchColumn();

                    if ($tagId === false) {
                        $tagSlug =
                            course_create_unique_tag_slug(
                                $pdo,
                                $tag
                            );

                        $tagInsertStatement->execute([
                            'name' =>
                                $tag,

                            'slug' =>
                                $tagSlug,
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

            $offeringId = 0;

            if ($hasOfferingDetails) {
                $offeringInsertStatement =
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

                $offeringInsertStatement->execute([
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

                $offeringId =
                    (int) $pdo->lastInsertId();


                /*
                |----------------------------------------------------------
                | Grade Level
                |----------------------------------------------------------
                */

                if ($yearGroupId > 0) {
                    $yearGroupInsertStatement =
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

                    $yearGroupInsertStatement->execute([
                        'offering_id' =>
                            $offeringId,

                        'year_group_id' =>
                            $yearGroupId,
                    ]);
                }


                /*
                |----------------------------------------------------------
                | Instructors / Teaching Assistants
                |----------------------------------------------------------
                */

                if (
                    $form['instructor_ids']
                    !== []
                ) {
                    $courseStaffInsertStatement =
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
                        $courseStaffInsertStatement->execute([
                            'offering_id' =>
                                $offeringId,

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
                        $courseStaffInsertStatement->execute([
                            'offering_id' =>
                                $offeringId,

                            'user_id' =>
                                $taId,

                            'instructor_role' =>
                                'assistant',
                        ]);
                    }
                } elseif (
                    $form['ta_ids']
                    !== []
                ) {
                    $taInsertStatement =
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
                                "assistant",
                                1
                            )
                            '
                        );

                    foreach (
                        $form['ta_ids']
                        as $taId
                    ) {
                        $taInsertStatement->execute([
                            'offering_id' =>
                                $offeringId,

                            'user_id' =>
                                $taId,
                        ]);
                    }
                }
            }


            /*
            |--------------------------------------------------------------
            | Audit
            |--------------------------------------------------------------
            */

            course_create_audit(
                $pdo,
                $currentUserId,
                $courseId,
                'Created course: '
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

            set_flash(
                'success',
                $form['status']
                === 'draft'
                    ? 'Course draft created successfully.'
                    : (
                        $form['status']
                        === 'scheduled'
                            ? 'Course created and marked as scheduled.'
                            : 'Course created and published.'
                    )
            );

            redirect(
                url(
                    'admin/courses.php'
                )
            );
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            course_create_delete_uploaded_image(
                $imageUpload
            );

            error_log(
                'Course creation error: '
                . $exception->getMessage()
            );

            $errors[] =
                'The course could not be created. No course data was saved.';
        }
    }
}


/*
|--------------------------------------------------------------------------
| Shared Courses Sidebar
|--------------------------------------------------------------------------
*/

$courseSidebarActive = 'create-course';

require INCLUDES_PATH . '/staff-course-sidebar.php';


/*
|--------------------------------------------------------------------------
| SEO / Header
|--------------------------------------------------------------------------
*/

$pageTitle =
    'Create Course | Blackthorne Academy';

$pageDescription =
    'Create a new Blackthorne Academy course.';

$pageCanonical =
    url('admin/course-create.php');

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
    class="dashboard-page staff-dashboard-page dashboard-workspace-page courses-dashboard-page course-create-page"
>

    <section
        class="dashboard-hero staff-dashboard-hero"
        aria-labelledby="course-create-heading"
        <?php if ($staffHeroUrl !== ''): ?>
            style="--staff-dashboard-hero-image: url('<?= e($staffHeroUrl); ?>');"
        <?php endif; ?>
    >
        <div class="section-inner">
            <div class="dashboard-hero-inner">

                <p class="academy-overline">
                    Academic Administration
                </p>

                <h1 id="course-create-heading">
                    Create Course
                </h1>

                <p class="dashboard-hero-copy">
                    Establish the course record, academic placement,
                    prerequisites, staff, and publishing state.
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
                            Course information
                        </h2>

                        <p>
                            Drafts may remain incomplete. Scheduled and
                            Published courses must contain every required
                            publishing field.
                        </p>
                    </div>
                </header>


                <?php if ($errors !== []): ?>

                    <div
                        class="form-message form-message-error"
                        role="alert"
                    >
                        <strong>
                            The course could not be saved.
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
                            Course Setup
                        </p>

                        <h2>
                            Create a New Course
                        </h2>
                    </header>

                    <form
                        action="<?= e(url('admin/course-create.php')); ?>"
                        method="post"
                        enctype="multipart/form-data"
                        class="forum-admin-form"
                        data-course-create-form
                    >
                        <?= csrf_field(); ?>


                        <div class="form-group">
                            <label for="course-title">
                                Course Title
                                <span
                                    data-publish-required
                                    aria-hidden="true"
                                >*</span>
                            </label>

                            <input
                                class="form-control"
                                type="text"
                                id="course-title"
                                name="course_title"
                                maxlength="150"
                                value="<?= e($form['course_title']); ?>"
                            >

                            <p class="form-help">
                                Required before the course can be scheduled or published.
                            </p>
                        </div>


                        <div class="form-group">
                            <label for="course-image">
                                Course Thumbnail / Image
                            </label>

                            <input
                                class="form-control"
                                type="file"
                                id="course-image"
                                name="course_image"
                                accept="image/jpeg,image/png,image/webp,image/avif"
                            >

                            <p class="form-help">
                                JPG, PNG, WebP, or AVIF. Maximum 10 MB.
                                Blackthorne will generate and prefer a WebP
                                derivative when the server supports it.
                            </p>
                        </div>


                        <div class="form-group">
                            <label for="short-description">
                                Short Description
                                <span
                                    data-publish-required
                                    aria-hidden="true"
                                >*</span>
                            </label>

                            <textarea
                                class="form-control"
                                id="short-description"
                                name="short_description"
                                rows="4"
                            ><?= e($form['short_description']); ?></textarea>

                            <p class="form-help">
                                Used for course cards, listings, and compact previews.
                            </p>
                        </div>


                        <div class="form-group">
                            <label for="long-description">
                                Long Description
                                <span
                                    data-publish-required
                                    aria-hidden="true"
                                >*</span>
                            </label>

                            <textarea
                                class="form-control"
                                id="long-description"
                                name="long_description"
                                rows="8"
                            ><?= e($form['long_description']); ?></textarea>

                            <p class="form-help">
                                Full course overview shown on the course page.
                            </p>
                        </div>


                        <div class="forum-admin-form-grid">

                            <div class="form-group">
                                <label for="school-year-id">
                                    School Year
                                    <span
                                        data-publish-required
                                        aria-hidden="true"
                                    >*</span>
                                </label>

                                <select
                                    class="form-control"
                                    id="school-year-id"
                                    name="school_year_id"
                                >
                                    <option value="">
                                        Choose a school year
                                    </option>

                                    <?php foreach ($schoolYears as $schoolYear): ?>
                                        <option
                                            value="<?= (int) $schoolYear['id']; ?>"
                                            <?= (int) $form['school_year_id'] === (int) $schoolYear['id']
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            <?= e((string) $schoolYear['name']); ?>
                                            <?= (int) ($schoolYear['is_current'] ?? 0) === 1
                                                ? ' — Current'
                                                : ''; ?>
                                            <?= (int) ($schoolYear['is_finalized'] ?? 0) === 1
                                                ? ' — Finalized'
                                                : ''; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>


                            <div class="form-group">
                                <label for="year-group-id">
                                    Grade Level
                                    <span
                                        data-publish-required
                                        aria-hidden="true"
                                    >*</span>
                                </label>

                                <select
                                    class="form-control"
                                    id="year-group-id"
                                    name="year_group_id"
                                >
                                    <option value="">
                                        Choose a grade level
                                    </option>

                                    <?php foreach ($yearGroups as $yearGroup): ?>
                                        <option
                                            value="<?= (int) $yearGroup['id']; ?>"
                                            <?= (int) $form['year_group_id'] === (int) $yearGroup['id']
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            <?= e((string) $yearGroup['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                        </div>


                        <fieldset class="forum-admin-fieldset">
                            <legend>
                                Prerequisites
                                <span
                                    data-publish-required
                                    aria-hidden="true"
                                >*</span>
                            </legend>

                            <p class="form-help">
                                This choice must be completed before publication,
                                even when the course has no prerequisites.
                            </p>

                            <label class="forum-admin-choice">
                                <input
                                    type="radio"
                                    name="prerequisite_mode"
                                    value="unset"
                                    <?= $form['prerequisite_mode'] === 'unset'
                                        ? 'checked'
                                        : ''; ?>
                                    data-prerequisite-mode
                                >
                                <span>
                                    Not decided yet
                                </span>
                            </label>

                            <label class="forum-admin-choice">
                                <input
                                    type="radio"
                                    name="prerequisite_mode"
                                    value="none"
                                    <?= $form['prerequisite_mode'] === 'none'
                                        ? 'checked'
                                        : ''; ?>
                                    data-prerequisite-mode
                                >
                                <span>
                                    None
                                </span>
                            </label>

                            <label class="forum-admin-choice">
                                <input
                                    type="radio"
                                    name="prerequisite_mode"
                                    value="required"
                                    <?= $form['prerequisite_mode'] === 'required'
                                        ? 'checked'
                                        : ''; ?>
                                    data-prerequisite-mode
                                >
                                <span>
                                    Select prerequisite course(s)
                                </span>
                            </label>

                            <div
                                class="forum-admin-role-box"
                                data-prerequisite-course-box
                                <?= $form['prerequisite_mode'] === 'required'
                                    ? ''
                                    : 'hidden'; ?>
                            >
                                <?php if ($prerequisiteCourses === []): ?>

                                    <p class="form-help">
                                        No existing courses are currently available
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
                                            <input
                                                type="checkbox"
                                                name="prerequisite_course_ids[]"
                                                value="<?= (int) $prerequisiteCourse['id']; ?>"
                                                <?= in_array(
                                                    (int) $prerequisiteCourse['id'],
                                                    $form['prerequisite_course_ids'],
                                                    true
                                                )
                                                    ? 'checked'
                                                    : ''; ?>
                                            >

                                            <span>
                                                <?= e($prerequisiteTitle); ?>
                                            </span>
                                        </label>

                                    <?php endforeach; ?>

                                <?php endif; ?>
                            </div>
                        </fieldset>


                        <div class="forum-admin-form-grid">

                            <div class="form-group">
                                <label for="course-start-date">
                                    Start Date
                                </label>

                                <input
                                    class="form-control"
                                    type="datetime-local"
                                    id="course-start-date"
                                    name="course_start_date"
                                    value="<?= e($form['course_start_date']); ?>"
                                >
                            </div>


                            <div class="form-group">
                                <label for="course-end-date">
                                    End Date
                                </label>

                                <input
                                    class="form-control"
                                    type="datetime-local"
                                    id="course-end-date"
                                    name="course_end_date"
                                    value="<?= e($form['course_end_date']); ?>"
                                >
                            </div>

                        </div>


                        <fieldset class="forum-admin-fieldset">
                            <legend>
                                Course Type
                                <span
                                    data-publish-required
                                    aria-hidden="true"
                                >*</span>
                            </legend>

                            <label class="forum-admin-choice">
                                <input
                                    type="radio"
                                    name="course_type"
                                    value="self_paced"
                                    <?= $form['course_type'] === 'self_paced'
                                        ? 'checked'
                                        : ''; ?>
                                >

                                <span>
                                    Self-paced
                                </span>
                            </label>

                            <label class="forum-admin-choice">
                                <input
                                    type="radio"
                                    name="course_type"
                                    value="drip"
                                    <?= $form['course_type'] === 'drip'
                                        ? 'checked'
                                        : ''; ?>
                                >

                                <span>
                                    Drip Content
                                </span>
                            </label>
                        </fieldset>


                        <div class="forum-admin-form-grid">

                            <div class="form-group">
                                <label for="category-id">
                                    Category
                                </label>

                                <select
                                    class="form-control"
                                    id="category-id"
                                    name="category_id"
                                >
                                    <option value="">
                                        No category
                                    </option>

                                    <?php foreach ($categories as $category): ?>
                                        <option
                                            value="<?= (int) $category['id']; ?>"
                                            <?= (int) $form['category_id'] === (int) $category['id']
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            <?= e((string) $category['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>


                            <div class="form-group">
                                <label for="new-category-name">
                                    Or Create New Category
                                </label>

                                <input
                                    class="form-control"
                                    type="text"
                                    id="new-category-name"
                                    name="new_category_name"
                                    maxlength="100"
                                    value="<?= e($form['new_category_name']); ?>"
                                >

                                <p class="form-help">
                                    If entered, this takes priority over the
                                    category dropdown.
                                </p>
                            </div>

                        </div>


                        <div class="form-group">
                            <label for="course-forum-id">
                                Course Forum
                            </label>

                            <select
                                class="form-control"
                                id="course-forum-id"
                                name="course_forum_id"
                                data-forum-picker
                            >
                                <option value="">
                                    No dedicated course forum
                                </option>

                                <?php foreach ($courseForumOptions as $forumOption): ?>
                                    <option
                                        value="<?= (int) $forumOption['id']; ?>"
                                        data-forum-id="<?= (int) $forumOption['id']; ?>"
                                        data-parent-forum-id="<?= (int) ($forumOption['parent_forum_id'] ?? 0); ?>"
                                        data-depth="<?= (int) ($forumOption['hierarchy_depth'] ?? 0); ?>"
                                        data-category-title="<?= e((string) ($forumOption['category_title'] ?? 'Forums')); ?>"
                                        data-forum-title="<?= e((string) ($forumOption['title'] ?? 'Forum')); ?>"
                                        data-forum-path="<?= e((string) ($forumOption['forum_path'] ?? '')); ?>"
                                        <?= (int) $form['course_forum_id'] === (int) $forumOption['id']
                                            ? 'selected'
                                            : ''; ?>
                                    >
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

                            <input
                                class="form-control"
                                type="text"
                                id="course-tags"
                                name="tags"
                                maxlength="1000"
                                value="<?= e($form['tags']); ?>"
                                placeholder="foundations, first year, practical magic"
                            >

                            <p class="form-help">
                                Separate tags with commas. Up to 15 tags.
                            </p>
                        </div>


                        <div class="forum-admin-form-grid">

                            <div class="form-group">
                                <label for="instructor-ids">
                                    Instructor(s)
                                </label>

                                <select
                                    class="form-control"
                                    id="instructor-ids"
                                    name="instructor_ids[]"
                                    multiple
                                    size="8"
                                >
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

                                        <option
                                            value="<?= (int) $staffMember['id']; ?>"
                                            <?= in_array(
                                                (int) $staffMember['id'],
                                                $form['instructor_ids'],
                                                true
                                            )
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            <?= e($staffName); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <p class="form-help">
                                    Use Ctrl/Cmd to select more than one.
                                    The first selected instructor becomes the
                                    primary instructor; additional selections
                                    become co-instructors.
                                </p>
                            </div>


                            <div class="form-group">
                                <label for="ta-ids">
                                    Teaching Assistant(s) / TA(s)
                                </label>

                                <select
                                    class="form-control"
                                    id="ta-ids"
                                    name="ta_ids[]"
                                    multiple
                                    size="8"
                                >
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

                                        <option
                                            value="<?= (int) $staffMember['id']; ?>"
                                            <?= in_array(
                                                (int) $staffMember['id'],
                                                $form['ta_ids'],
                                                true
                                            )
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            <?= e($staffName); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <p class="form-help">
                                    Use Ctrl/Cmd to select more than one.
                                </p>
                            </div>

                        </div>


                        <fieldset class="forum-admin-fieldset">
                            <legend>
                                Status
                            </legend>

                            <label class="forum-admin-choice">
                                <input
                                    type="radio"
                                    name="status"
                                    value="draft"
                                    <?= $form['status'] === 'draft'
                                        ? 'checked'
                                        : ''; ?>
                                    data-course-status
                                >

                                <span>
                                    Draft
                                </span>
                            </label>

                            <label class="forum-admin-choice">
                                <input
                                    type="radio"
                                    name="status"
                                    value="scheduled"
                                    <?= $form['status'] === 'scheduled'
                                        ? 'checked'
                                        : ''; ?>
                                    data-course-status
                                >

                                <span>
                                    Scheduled
                                </span>
                            </label>

                            <label class="forum-admin-choice">
                                <input
                                    type="radio"
                                    name="status"
                                    value="published"
                                    <?= $form['status'] === 'published'
                                        ? 'checked'
                                        : ''; ?>
                                    data-course-status
                                >

                                <span>
                                    Published
                                </span>
                            </label>

                            <p class="form-help">
                                Drafts may be incomplete. Scheduled and
                                Published courses require Course Title,
                                Short Description, Long Description,
                                Prerequisites, School Year, Grade Level,
                                and Course Type.
                            </p>
                        </fieldset>


                        <div class="forum-admin-actions">
                            <a
                                class="button button-secondary"
                                href="<?= e(url('admin/courses.php')); ?>"
                            >
                                Cancel
                            </a>

                            <button
                                type="submit"
                                class="button button-primary"
                            >
                                Create Course
                            </button>
                        </div>

                    </form>
                </section>

            </div>

        </div>
    </section>

</main>


<script>
(function () {
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

        box.hidden =
            !selected
            || selected.value !== 'required';
    }

    modes.forEach(function (mode) {
        mode.addEventListener(
            'change',
            syncPrerequisiteBox
        );
    });

    syncPrerequisiteBox();
})();
</script>

<?php

require INCLUDES_PATH . '/footer.php';
