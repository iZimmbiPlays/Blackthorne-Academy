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

$canManageLearningPaths =
    $isProtectedSuperAdmin
    || user_can('courses.learning_paths.manage');

if (
    !$hasStaffIdentity
    || !$canManageLearningPaths
) {
    http_response_code(403);

    $pageTitle =
        'Access Denied | Blackthorne Academy';

    $pageDescription =
        'You do not have permission to manage course registration groups.';

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
                    Your account does not have permission to manage
                    course registration groups.
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
| Helpers
|--------------------------------------------------------------------------
*/

function registration_groups_post_string(
    string $key
): string {
    $value =
        $_POST[$key]
        ?? '';

    return is_scalar($value)
        ? trim((string) $value)
        : '';
}


function registration_groups_post_id(
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


function registration_groups_post_ids(
    string $key
): array {
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
            $id =
                (int) $value;

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
    }

    return array_values($ids);
}


function registration_groups_slug_base(
    string $value
): string {
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


function registration_groups_unique_slug(
    PDO $pdo,
    string $name
): string {
    $base =
        registration_groups_slug_base(
            $name
        );

    if ($base === '') {
        $base =
            'registration-group-'
            . bin2hex(
                random_bytes(4)
            );
    }

    $base =
        substr(
            $base,
            0,
            155
        );

    $candidate = $base;
    $suffix = 2;

    $statement =
        $pdo->prepare(
            '
            SELECT 1
            FROM course_registration_groups
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
                155
            )
            . '-'
            . $suffix;

        $suffix++;
    }
}


function registration_groups_normalize_datetime(
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


function registration_groups_format_date(
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
        'M j, Y',
        $timestamp
    );
}


function registration_groups_audit(
    PDO $pdo,
    int $actorUserId,
    int $groupId,
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
                'course_registration_group.create',

            'entity_type' =>
                'course_registration_group',

            'entity_id' =>
                $groupId,

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
            'Course registration group audit error: '
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
            year_number

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

$gatewayCourses =
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

$schoolYearOfferings =
    $pdo->query(
        '
        SELECT
            co.id,
            co.course_id,
            co.school_year_id,
            co.status,
            c.title AS course_title,
            sy.name AS school_year_name,
            yg.id AS year_group_id,
            yg.name AS year_group_name

        FROM course_offerings co

        INNER JOIN courses c
            ON c.id = co.course_id

        INNER JOIN school_years sy
            ON sy.id = co.school_year_id

        LEFT JOIN course_offering_year_groups coyg
            ON coyg.offering_id = co.id

        LEFT JOIN year_groups yg
            ON yg.id = coyg.year_group_id

        WHERE co.offering_scope = "school_year"
          AND co.status <> "archived"

        ORDER BY
            sy.start_date DESC,
            yg.sort_order ASC,
            c.title ASC,
            co.id ASC
        '
    )->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Form State
|--------------------------------------------------------------------------
*/

$errors = [];

$form = [
    'name' => '',
    'description' => '',
    'school_year_id' => '',
    'year_group_id' => '',
    'gateway_course_id' => '',
    'registration_opens_at' => '',
    'registration_closes_at' => '',
    'offering_ids' => [],
    'is_active' => '1',
];


/*
|--------------------------------------------------------------------------
| Create Registration Group
|--------------------------------------------------------------------------
*/

if (
    ($_SERVER['REQUEST_METHOD'] ?? '')
    === 'POST'
) {
    $action =
        registration_groups_post_string(
            'action'
        );

    if ($action === 'create_group') {
        if (
            !verify_csrf_token(
                $_POST['_csrf_token']
                ?? null
            )
        ) {
            $errors[] =
                'Your form session expired. Refresh the page and try again.';
        }

        $form['name'] =
            registration_groups_post_string(
                'name'
            );

        $form['description'] =
            registration_groups_post_string(
                'description'
            );

        $form['school_year_id'] =
            (string) registration_groups_post_id(
                'school_year_id'
            );

        $form['year_group_id'] =
            (string) registration_groups_post_id(
                'year_group_id'
            );

        $form['gateway_course_id'] =
            (string) registration_groups_post_id(
                'gateway_course_id'
            );

        $form['registration_opens_at'] =
            registration_groups_post_string(
                'registration_opens_at'
            );

        $form['registration_closes_at'] =
            registration_groups_post_string(
                'registration_closes_at'
            );

        $form['offering_ids'] =
            registration_groups_post_ids(
                'offering_ids'
            );

        $form['is_active'] =
            registration_groups_post_string(
                'is_active'
            );


        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */

        $schoolYearId =
            (int) $form['school_year_id'];

        $yearGroupId =
            (int) $form['year_group_id'];

        $gatewayCourseId =
            (int) $form['gateway_course_id'];

        if ($form['name'] === '') {
            $errors[] =
                'Registration Group Name is required.';
        }

        if (
            function_exists('mb_strlen')
            && mb_strlen(
                $form['name'],
                'UTF-8'
            ) > 150
        ) {
            $errors[] =
                'Registration Group Name must be 150 characters or fewer.';
        }

        if ($schoolYearId <= 0) {
            $errors[] =
                'School Year is required.';
        }

        if ($yearGroupId <= 0) {
            $errors[] =
                'Grade Level is required.';
        }

        if ($gatewayCourseId <= 0) {
            $errors[] =
                'Gateway Course is required.';
        }

        if (
            !in_array(
                $form['is_active'],
                [
                    '0',
                    '1',
                ],
                true
            )
        ) {
            $errors[] =
                'Choose a valid group status.';
        }

        $registrationOpensAt =
            registration_groups_normalize_datetime(
                $form['registration_opens_at']
            );

        $registrationClosesAt =
            registration_groups_normalize_datetime(
                $form['registration_closes_at']
            );

        if (
            $form['registration_opens_at']
            !== ''
            && $registrationOpensAt
                === null
        ) {
            $errors[] =
                'Registration Open Date is invalid.';
        }

        if (
            $form['registration_closes_at']
            !== ''
            && $registrationClosesAt
                === null
        ) {
            $errors[] =
                'Registration Close Date is invalid.';
        }

        if (
            $registrationOpensAt !== null
            && $registrationClosesAt !== null
            && strtotime($registrationClosesAt)
                < strtotime($registrationOpensAt)
        ) {
            $errors[] =
                'Registration Close Date cannot be earlier than Registration Open Date.';
        }


        /*
        |--------------------------------------------------------------------------
        | Validate School Year
        |--------------------------------------------------------------------------
        */

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


        /*
        |--------------------------------------------------------------------------
        | Validate Grade Level
        |--------------------------------------------------------------------------
        */

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


        /*
        |--------------------------------------------------------------------------
        | Validate Gateway Course
        |--------------------------------------------------------------------------
        */

        if ($gatewayCourseId > 0) {
            $statement =
                $pdo->prepare(
                    '
                    SELECT 1

                    FROM courses

                    WHERE id = :id
                      AND status <> "archived"

                    LIMIT 1
                    '
                );

            $statement->execute([
                'id' =>
                    $gatewayCourseId,
            ]);

            if (
                $statement->fetchColumn()
                === false
            ) {
                $errors[] =
                    'The selected Gateway Course is not available.';
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Validate Unique School Year + Grade Level
        |--------------------------------------------------------------------------
        */

        if (
            $schoolYearId > 0
            && $yearGroupId > 0
        ) {
            $statement =
                $pdo->prepare(
                    '
                    SELECT id

                    FROM course_registration_groups

                    WHERE school_year_id = :school_year_id
                      AND year_group_id = :year_group_id

                    LIMIT 1
                    '
                );

            $statement->execute([
                'school_year_id' =>
                    $schoolYearId,

                'year_group_id' =>
                    $yearGroupId,
            ]);

            if (
                $statement->fetchColumn()
                !== false
            ) {
                $errors[] =
                    'A registration group already exists for that School Year and Grade Level.';
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Validate Offering Membership
        |--------------------------------------------------------------------------
        */

        if ($form['offering_ids'] === []) {
            $errors[] =
                'Select at least one course offering for this registration group.';
        } else {
            $placeholders =
                implode(
                    ',',
                    array_fill(
                        0,
                        count(
                            $form['offering_ids']
                        ),
                        '?'
                    )
                );

            $statement =
                $pdo->prepare(
                    '
                    SELECT
                        co.id

                    FROM course_offerings co

                    INNER JOIN course_offering_year_groups coyg
                        ON coyg.offering_id = co.id

                    WHERE co.id IN ('
                    . $placeholders
                    . ')
                      AND co.offering_scope = "school_year"
                      AND co.school_year_id = ?
                      AND coyg.year_group_id = ?
                      AND co.status <> "archived"
                    '
                );

            $statement->execute(
                array_merge(
                    $form['offering_ids'],
                    [
                        $schoolYearId,
                        $yearGroupId,
                    ]
                )
            );

            $validOfferingIds =
                array_map(
                    'intval',
                    $statement->fetchAll(
                        PDO::FETCH_COLUMN
                    )
                );

            sort($validOfferingIds);

            $submittedOfferingIds =
                $form['offering_ids'];

            sort($submittedOfferingIds);

            if (
                $validOfferingIds
                !== $submittedOfferingIds
            ) {
                $errors[] =
                    'Every selected offering must belong to the selected School Year and Grade Level.';
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Save
        |--------------------------------------------------------------------------
        */

        if ($errors === []) {
            try {
                $pdo->beginTransaction();

                $slug =
                    registration_groups_unique_slug(
                        $pdo,
                        $form['name']
                    );

                $insertGroupStatement =
                    $pdo->prepare(
                        '
                        INSERT INTO course_registration_groups (
                            name,
                            slug,
                            school_year_id,
                            year_group_id,
                            gateway_course_id,
                            description,
                            registration_opens_at,
                            registration_closes_at,
                            is_active,
                            sort_order
                        ) VALUES (
                            :name,
                            :slug,
                            :school_year_id,
                            :year_group_id,
                            :gateway_course_id,
                            :description,
                            :registration_opens_at,
                            :registration_closes_at,
                            :is_active,
                            0
                        )
                        '
                    );

                $insertGroupStatement->execute([
                    'name' =>
                        $form['name'],

                    'slug' =>
                        $slug,

                    'school_year_id' =>
                        $schoolYearId,

                    'year_group_id' =>
                        $yearGroupId,

                    'gateway_course_id' =>
                        $gatewayCourseId,

                    'description' =>
                        $form['description'] !== ''
                            ? $form['description']
                            : null,

                    'registration_opens_at' =>
                        $registrationOpensAt,

                    'registration_closes_at' =>
                        $registrationClosesAt,

                    'is_active' =>
                        (int) $form['is_active'],
                ]);

                $groupId =
                    (int) $pdo->lastInsertId();

                $insertOfferingStatement =
                    $pdo->prepare(
                        '
                        INSERT INTO course_registration_group_offerings (
                            registration_group_id,
                            offering_id,
                            is_required,
                            sort_order
                        ) VALUES (
                            :registration_group_id,
                            :offering_id,
                            1,
                            :sort_order
                        )
                        '
                    );

                foreach (
                    array_values(
                        $form['offering_ids']
                    )
                    as $index =>
                    $offeringId
                ) {
                    $insertOfferingStatement->execute([
                        'registration_group_id' =>
                            $groupId,

                        'offering_id' =>
                            $offeringId,

                        'sort_order' =>
                            $index,
                    ]);
                }

                registration_groups_audit(
                    $pdo,
                    $currentUserId,
                    $groupId,
                    'Created registration group: '
                    . $form['name']
                );

                $pdo->commit();

                set_flash(
                    'success',
                    'Course registration group created successfully.'
                );

                redirect(
                    url(
                        'admin/course-registration-groups.php'
                    )
                );
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'Course registration group creation error: '
                    . $exception->getMessage()
                );

                $errors[] =
                    'The registration group could not be created. No group data was saved.';
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| Existing Registration Groups
|--------------------------------------------------------------------------
*/

$groupsStatement =
    $pdo->query(
        '
        SELECT
            crg.id,
            crg.name,
            crg.description,
            crg.registration_opens_at,
            crg.registration_closes_at,
            crg.is_active,
            crg.created_at,
            crg.updated_at,

            sy.name AS school_year_name,

            yg.name AS year_group_name,

            c.title AS gateway_course_title,

            (
                SELECT COUNT(*)

                FROM course_registration_group_offerings crgo

                WHERE crgo.registration_group_id = crg.id
            ) AS offering_count,

            (
                SELECT COUNT(*)

                FROM course_registration_group_enrollments crge

                WHERE crge.registration_group_id = crg.id
                  AND crge.status IN (
                        "registered",
                        "active"
                      )
            ) AS active_registration_count

        FROM course_registration_groups crg

        INNER JOIN school_years sy
            ON sy.id = crg.school_year_id

        INNER JOIN year_groups yg
            ON yg.id = crg.year_group_id

        LEFT JOIN courses c
            ON c.id = crg.gateway_course_id

        ORDER BY
            sy.start_date DESC,
            yg.sort_order ASC,
            crg.sort_order ASC,
            crg.name ASC,
            crg.id ASC
        '
    );

$groups =
    $groupsStatement->fetchAll(
        PDO::FETCH_ASSOC
    );

$groupIds =
    array_map(
        static fn (array $row): int =>
            (int) (
                $row['id']
                ?? 0
            ),
        $groups
    );

$offeringsByGroup = [];

if ($groupIds !== []) {
    $placeholders =
        implode(
            ',',
            array_fill(
                0,
                count($groupIds),
                '?'
            )
        );

    $groupOfferingsStatement =
        $pdo->prepare(
            '
            SELECT
                crgo.registration_group_id,
                c.title AS course_title

            FROM course_registration_group_offerings crgo

            INNER JOIN course_offerings co
                ON co.id = crgo.offering_id

            INNER JOIN courses c
                ON c.id = co.course_id

            WHERE crgo.registration_group_id IN ('
            . $placeholders
            . ')

            ORDER BY
                crgo.registration_group_id ASC,
                crgo.sort_order ASC,
                c.title ASC
            '
        );

    $groupOfferingsStatement->execute(
        $groupIds
    );

    foreach (
        $groupOfferingsStatement->fetchAll(
            PDO::FETCH_ASSOC
        )
        as $row
    ) {
        $groupId =
            (int) (
                $row['registration_group_id']
                ?? 0
            );

        if ($groupId <= 0) {
            continue;
        }

        $offeringsByGroup[
            $groupId
        ][] =
            (string) (
                $row['course_title']
                ?? 'Untitled Course'
            );
    }
}


/*
|--------------------------------------------------------------------------
| Shared Courses Sidebar
|--------------------------------------------------------------------------
*/

$courseSidebarActive =
    'registration-groups';

require INCLUDES_PATH . '/staff-course-sidebar.php';


/*
|--------------------------------------------------------------------------
| SEO / Header
|--------------------------------------------------------------------------
*/

$pageTitle =
    'Registration Groups | Blackthorne Academy';

$pageDescription =
    'Manage Blackthorne Academy course registration groups.';

$pageCanonical =
    url('admin/course-registration-groups.php');

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
    class="dashboard-page staff-dashboard-page dashboard-workspace-page courses-dashboard-page course-registration-groups-page"
>

    <section
        class="dashboard-hero staff-dashboard-hero"
        aria-labelledby="registration-groups-heading"
        <?php if ($staffHeroUrl !== ''): ?>
            style="--staff-dashboard-hero-image: url('<?= e($staffHeroUrl); ?>');"
        <?php endif; ?>
    >
        <div class="section-inner">
            <div class="dashboard-hero-inner">

                <p class="academy-overline">
                    Academic Administration
                </p>

                <h1 id="registration-groups-heading">
                    Registration Groups
                </h1>

                <p class="dashboard-hero-copy">
                    Bundle a school year and grade level into one student
                    registration choice, gated by completion of a required
                    gateway course such as Orientation.
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
                            Registration Structure
                        </p>

                        <h2>
                            Year-level course bundles
                        </h2>

                        <p>
                            A student completes the Gateway Course first,
                            then registers for this group once. That group
                            contains all required offerings for the selected
                            School Year and Grade Level.
                        </p>
                    </div>
                </header>


                <?php if ($errors !== []): ?>

                    <div
                        class="form-message form-message-error"
                        role="alert"
                    >
                        <strong>
                            The registration group could not be created.
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
                            New Group
                        </p>

                        <h2>
                            Create Registration Group
                        </h2>
                    </header>

                    <form
                        action="<?= e(url('admin/course-registration-groups.php')); ?>"
                        method="post"
                        class="forum-admin-form"
                        data-registration-group-form
                    >
                        <?= csrf_field(); ?>

                        <input
                            type="hidden"
                            name="action"
                            value="create_group"
                        >


                        <div class="form-group">
                            <label for="group-name">
                                Registration Group Name
                            </label>

                            <input
                                class="form-control"
                                type="text"
                                id="group-name"
                                name="name"
                                maxlength="150"
                                value="<?= e($form['name']); ?>"
                                placeholder="First Year — 2026/2027"
                                required
                            >

                            <p class="form-help">
                                Example: First Year — 2026/2027.
                            </p>
                        </div>


                        <div class="form-group">
                            <label for="group-description">
                                Description
                            </label>

                            <textarea
                                class="form-control"
                                id="group-description"
                                name="description"
                                rows="4"
                            ><?= e($form['description']); ?></textarea>
                        </div>


                        <div class="forum-admin-form-grid">

                            <div class="form-group">
                                <label for="school-year-id">
                                    School Year
                                </label>

                                <select
                                    class="form-control"
                                    id="school-year-id"
                                    name="school_year_id"
                                    required
                                    data-group-school-year
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
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>


                            <div class="form-group">
                                <label for="year-group-id">
                                    Grade Level
                                </label>

                                <select
                                    class="form-control"
                                    id="year-group-id"
                                    name="year_group_id"
                                    required
                                    data-group-year-level
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


                        <div class="form-group">
                            <label for="gateway-course-id">
                                Gateway Course
                            </label>

                            <select
                                class="form-control"
                                id="gateway-course-id"
                                name="gateway_course_id"
                                required
                            >
                                <option value="">
                                    Choose a gateway course
                                </option>

                                <?php foreach ($gatewayCourses as $courseRow): ?>
                                    <?php
                                    $courseTitle =
                                        trim(
                                            (string) (
                                                $courseRow['title']
                                                ?? ''
                                            )
                                        );

                                    if ($courseTitle === '') {
                                        $courseTitle =
                                            'Untitled Course #'
                                            . (int) $courseRow['id'];
                                    }
                                    ?>

                                    <option
                                        value="<?= (int) $courseRow['id']; ?>"
                                        <?= (int) $form['gateway_course_id'] === (int) $courseRow['id']
                                            ? 'selected'
                                            : ''; ?>
                                    >
                                        <?= e($courseTitle); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <p class="form-help">
                                For First Year, this will be Orientation.
                                Students must complete this course before
                                registration can unlock.
                            </p>
                        </div>


                        <div class="forum-admin-form-grid">

                            <div class="form-group">
                                <label for="registration-opens-at">
                                    Registration Opens
                                </label>

                                <input
                                    class="form-control"
                                    type="datetime-local"
                                    id="registration-opens-at"
                                    name="registration_opens_at"
                                    value="<?= e($form['registration_opens_at']); ?>"
                                >

                                <p class="form-help">
                                    Optional. Leave blank if registration
                                    should not have a separate opening date.
                                </p>
                            </div>


                            <div class="form-group">
                                <label for="registration-closes-at">
                                    Registration Closes
                                </label>

                                <input
                                    class="form-control"
                                    type="datetime-local"
                                    id="registration-closes-at"
                                    name="registration_closes_at"
                                    value="<?= e($form['registration_closes_at']); ?>"
                                >

                                <p class="form-help">
                                    Optional. Leave blank if late registration
                                    remains available throughout the school year.
                                </p>
                            </div>

                        </div>


                        <fieldset class="forum-admin-fieldset">
                            <legend>
                                Course Offerings in this Group
                            </legend>

                            <p class="form-help">
                                Only offerings that match the selected School
                                Year and Grade Level can be saved into the group.
                                All selected offerings are currently treated as
                                required.
                            </p>

                            <?php if ($schoolYearOfferings === []): ?>

                                <p class="form-help">
                                    No school-year course offerings have been
                                    created yet.
                                </p>

                            <?php else: ?>

                                <div class="forum-admin-role-box">
                                    <?php foreach ($schoolYearOfferings as $offeringRow): ?>
                                        <?php
                                        $offeringId =
                                            (int) (
                                                $offeringRow['id']
                                                ?? 0
                                            );

                                        $courseTitle =
                                            trim(
                                                (string) (
                                                    $offeringRow['course_title']
                                                    ?? ''
                                                )
                                            );

                                        if ($courseTitle === '') {
                                            $courseTitle =
                                                'Untitled Course';
                                        }

                                        $schoolYearName =
                                            trim(
                                                (string) (
                                                    $offeringRow['school_year_name']
                                                    ?? ''
                                                )
                                            );

                                        $yearGroupName =
                                            trim(
                                                (string) (
                                                    $offeringRow['year_group_name']
                                                    ?? ''
                                                )
                                            );
                                        ?>

                                        <label
                                            data-offering-choice
                                            data-school-year-id="<?= (int) ($offeringRow['school_year_id'] ?? 0); ?>"
                                            data-year-group-id="<?= (int) ($offeringRow['year_group_id'] ?? 0); ?>"
                                        >
                                            <input
                                                type="checkbox"
                                                name="offering_ids[]"
                                                value="<?= $offeringId; ?>"
                                                <?= in_array(
                                                    $offeringId,
                                                    $form['offering_ids'],
                                                    true
                                                )
                                                    ? 'checked'
                                                    : ''; ?>
                                            >

                                            <span>
                                                <?= e($courseTitle); ?>
                                                <?php if ($schoolYearName !== ''): ?>
                                                    — <?= e($schoolYearName); ?>
                                                <?php endif; ?>
                                                <?php if ($yearGroupName !== ''): ?>
                                                    — <?= e($yearGroupName); ?>
                                                <?php endif; ?>
                                            </span>
                                        </label>

                                    <?php endforeach; ?>
                                </div>

                            <?php endif; ?>

                        </fieldset>


                        <div class="form-group">
                            <label for="group-status">
                                Group Status
                            </label>

                            <select
                                class="form-control"
                                id="group-status"
                                name="is_active"
                            >
                                <option
                                    value="1"
                                    <?= $form['is_active'] === '1'
                                        ? 'selected'
                                        : ''; ?>
                                >
                                    Active
                                </option>

                                <option
                                    value="0"
                                    <?= $form['is_active'] === '0'
                                        ? 'selected'
                                        : ''; ?>
                                >
                                    Inactive
                                </option>
                            </select>
                        </div>


                        <div class="forum-admin-actions">
                            <button
                                type="submit"
                                class="button button-primary"
                            >
                                Create Registration Group
                            </button>
                        </div>

                    </form>

                </section>


                <section class="dashboard-workspace-panel">

                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">
                                Existing Groups
                            </p>

                            <h3>
                                Registration Bundles
                            </h3>
                        </div>
                    </div>

                    <div class="dashboard-panel-body">

                        <?php if ($groups === []): ?>

                            <p>
                                No registration groups have been created yet.
                                The first expected group will be the current
                                school year's First Year registration bundle,
                                gated by Orientation.
                            </p>

                        <?php else: ?>

                            <div class="dashboard-placeholder-list">

                                <?php foreach ($groups as $groupRow): ?>
                                    <?php
                                    $groupId =
                                        (int) (
                                            $groupRow['id']
                                            ?? 0
                                        );

                                    $groupOfferingTitles =
                                        $offeringsByGroup[
                                            $groupId
                                        ]
                                        ?? [];
                                    ?>

                                    <span>
                                        <strong>
                                            <?= e((string) ($groupRow['name'] ?? 'Registration Group')); ?>
                                        </strong>

                                        · <?= e((string) ($groupRow['school_year_name'] ?? 'School Year')); ?>

                                        · <?= e((string) ($groupRow['year_group_name'] ?? 'Grade Level')); ?>

                                        · Gateway:
                                        <?= e((string) ($groupRow['gateway_course_title'] ?? 'None')); ?>

                                        · <?= number_format(
                                            (int) (
                                                $groupRow['offering_count']
                                                ?? 0
                                            )
                                        ); ?>
                                        courses

                                        · <?= number_format(
                                            (int) (
                                                $groupRow['active_registration_count']
                                                ?? 0
                                            )
                                        ); ?>
                                        registered

                                        · <?= (int) ($groupRow['is_active'] ?? 0) === 1
                                            ? 'Active'
                                            : 'Inactive'; ?>

                                        <?php if ($groupOfferingTitles !== []): ?>
                                            · Includes:
                                            <?= e(
                                                implode(
                                                    ', ',
                                                    $groupOfferingTitles
                                                )
                                            ); ?>
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


<script>
(function () {
    'use strict';

    var schoolYear =
        document.querySelector(
            '[data-group-school-year]'
        );

    var yearLevel =
        document.querySelector(
            '[data-group-year-level]'
        );

    var choices =
        document.querySelectorAll(
            '[data-offering-choice]'
        );

    if (
        !schoolYear
        || !yearLevel
        || !choices.length
    ) {
        return;
    }

    function syncOfferingChoices() {
        var schoolYearId =
            schoolYear.value;

        var yearGroupId =
            yearLevel.value;

        choices.forEach(function (choice) {
            var matches =
                schoolYearId !== ''
                && yearGroupId !== ''
                && choice.dataset.schoolYearId === schoolYearId
                && choice.dataset.yearGroupId === yearGroupId;

            choice.hidden =
                !matches;

            if (!matches) {
                var checkbox =
                    choice.querySelector(
                        'input[type="checkbox"]'
                    );

                if (checkbox) {
                    checkbox.checked = false;
                }
            }
        });
    }

    schoolYear.addEventListener(
        'change',
        syncOfferingChoices
    );

    yearLevel.addEventListener(
        'change',
        syncOfferingChoices
    );

    syncOfferingChoices();
})();
</script>

<?php

require INCLUDES_PATH . '/footer.php';
