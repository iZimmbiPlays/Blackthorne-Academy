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

$canManageGradeLevels =
    $isProtectedSuperAdmin
    || user_can('enrollments.progression.manage');

if (
    !$hasStaffIdentity
    || !$canManageGradeLevels
) {
    http_response_code(403);

    $pageTitle =
        'Access Denied | Blackthorne Academy';

    $pageDescription =
        'You do not have permission to manage grade levels.';

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
                Your account does not have permission to manage
                Grade Levels.
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

function grade_levels_post_string(
    string $key
): string {
    $value =
        $_POST[$key]
        ?? '';

    return is_scalar($value)
        ? trim((string) $value)
        : '';
}


function grade_levels_post_id(
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


function grade_levels_post_bool(
    string $key
): int {
    return isset($_POST[$key])
        ? 1
        : 0;
}


function grade_levels_slug_base(
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
            $value =
                $transliterated;
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


function grade_levels_unique_slug(
    PDO $pdo,
    string $name,
    int $excludeId = 0
): string {
    $base =
        grade_levels_slug_base(
            $name
        );

    if ($base === '') {
        $base =
            'year-group-'
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

    $candidate =
        $base;

    $suffix = 2;

    while (true) {
        $sql =
            '
            SELECT id

            FROM year_groups

            WHERE slug = :slug
            ';

        if ($excludeId > 0) {
            $sql .=
                ' AND id <> :exclude_id';
        }

        $sql .=
            ' LIMIT 1';

        $statement =
            $pdo->prepare($sql);

        $params = [
            'slug' =>
                $candidate,
        ];

        if ($excludeId > 0) {
            $params['exclude_id'] =
                $excludeId;
        }

        $statement->execute($params);

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


function grade_levels_audit(
    PDO $pdo,
    int $actorUserId,
    int $yearGroupId,
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
                'year_group',

            'entity_id' =>
                $yearGroupId,

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
            'Grade Level audit error: '
            . $exception->getMessage()
        );
    }
}


/*
|--------------------------------------------------------------------------
| Form State
|--------------------------------------------------------------------------
*/

$errors = [];

$createForm = [
    'name' => '',
    'year_number' => '',
    'description' => '',
    'sort_order' => '',
    'is_active' => '1',
];

$editYearGroupId =
    filter_input(
        INPUT_GET,
        'edit',
        FILTER_VALIDATE_INT
    );

if (
    !is_int($editYearGroupId)
    || $editYearGroupId <= 0
) {
    $editYearGroupId = 0;
}


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
        grade_levels_post_string(
            'action'
        );


    /*
    |--------------------------------------------------------------------------
    | Create
    |--------------------------------------------------------------------------
    */

    if (
        $errors === []
        && $action === 'create_grade_level'
    ) {
        $createForm['name'] =
            grade_levels_post_string(
                'name'
            );

        $createForm['year_number'] =
            grade_levels_post_string(
                'year_number'
            );

        $createForm['description'] =
            grade_levels_post_string(
                'description'
            );

        $createForm['sort_order'] =
            grade_levels_post_string(
                'sort_order'
            );

        $createForm['is_active'] =
            (string) grade_levels_post_bool(
                'is_active'
            );

        $name =
            $createForm['name'];

        $yearNumber =
            ctype_digit(
                $createForm['year_number']
            )
                ? (int) $createForm['year_number']
                : 0;

        $sortOrder =
            $createForm['sort_order'] === ''
                ? $yearNumber
                : (
                    ctype_digit(
                        $createForm['sort_order']
                    )
                        ? (int) $createForm['sort_order']
                        : -1
                );

        if ($name === '') {
            $errors[] =
                'Grade Level Name is required.';
        }

        if (
            strlen($name) > 100
        ) {
            $errors[] =
                'Grade Level Name must be 100 characters or fewer.';
        }

        if ($yearNumber < 0) {
            $errors[] =
                'Year Number must be zero or higher.';
        }

        if ($sortOrder < 0) {
            $errors[] =
                'Sort Order must be zero or higher.';
        }

        if ($errors === []) {
            try {
                $pdo->beginTransaction();

                $duplicateYearStatement =
                    $pdo->prepare(
                        '
                        SELECT id

                        FROM year_groups

                        WHERE year_number = :year_number

                        LIMIT 1
                        '
                    );

                $duplicateYearStatement->execute([
                    'year_number' =>
                        $yearNumber,
                ]);

                if (
                    $duplicateYearStatement->fetchColumn()
                    !== false
                ) {
                    throw new RuntimeException(
                        'That Year Number is already assigned to another Grade Level.'
                    );
                }

                $slug =
                    grade_levels_unique_slug(
                        $pdo,
                        $name
                    );

                $insertStatement =
                    $pdo->prepare(
                        '
                        INSERT INTO year_groups (
                            name,
                            slug,
                            year_number,
                            description,
                            is_active,
                            sort_order
                        ) VALUES (
                            :name,
                            :slug,
                            :year_number,
                            :description,
                            :is_active,
                            :sort_order
                        )
                        '
                    );

                $insertStatement->execute([
                    'name' =>
                        $name,

                    'slug' =>
                        $slug,

                    'year_number' =>
                        $yearNumber,

                    'description' =>
                        $createForm['description']
                        !== ''
                            ? $createForm['description']
                            : null,

                    'is_active' =>
                        (int) $createForm[
                            'is_active'
                        ],

                    'sort_order' =>
                        $sortOrder,
                ]);

                $yearGroupId =
                    (int) $pdo->lastInsertId();

                grade_levels_audit(
                    $pdo,
                    $currentUserId,
                    $yearGroupId,
                    'year_group.create',
                    'Created Grade Level: '
                    . $name
                );

                $pdo->commit();

                set_flash(
                    'success',
                    'Grade Level created successfully.'
                );

                redirect(
                    url(
                        'admin/grade-levels.php'
                    )
                );
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if (
                    $exception instanceof RuntimeException
                ) {
                    $errors[] =
                        $exception->getMessage();
                } else {
                    error_log(
                        'Grade Level create error: '
                        . $exception->getMessage()
                    );

                    $errors[] =
                        'The Grade Level could not be created.';
                }
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Update
    |--------------------------------------------------------------------------
    */

    if (
        $errors === []
        && $action === 'update_grade_level'
    ) {
        $yearGroupId =
            grade_levels_post_id(
                'year_group_id'
            );

        $name =
            grade_levels_post_string(
                'name'
            );

        $yearNumberRaw =
            grade_levels_post_string(
                'year_number'
            );

        $description =
            grade_levels_post_string(
                'description'
            );

        $sortOrderRaw =
            grade_levels_post_string(
                'sort_order'
            );

        $isActive =
            grade_levels_post_bool(
                'is_active'
            );

        $yearNumber =
            ctype_digit(
                $yearNumberRaw
            )
                ? (int) $yearNumberRaw
                : 0;

        $sortOrder =
            $sortOrderRaw === ''
                ? $yearNumber
                : (
                    ctype_digit(
                        $sortOrderRaw
                    )
                        ? (int) $sortOrderRaw
                        : -1
                );

        if ($yearGroupId <= 0) {
            $errors[] =
                'The Grade Level could not be identified.';
        }

        if ($name === '') {
            $errors[] =
                'Grade Level Name is required.';
        }

        if (
            strlen($name) > 100
        ) {
            $errors[] =
                'Grade Level Name must be 100 characters or fewer.';
        }

        if ($yearNumber < 0) {
            $errors[] =
                'Year Number must be zero or higher.';
        }

        if ($sortOrder < 0) {
            $errors[] =
                'Sort Order must be zero or higher.';
        }

        if ($errors === []) {
            try {
                $pdo->beginTransaction();

                $existingStatement =
                    $pdo->prepare(
                        '
                        SELECT id

                        FROM year_groups

                        WHERE id = :id

                        LIMIT 1
                        '
                    );

                $existingStatement->execute([
                    'id' =>
                        $yearGroupId,
                ]);

                if (
                    $existingStatement->fetchColumn()
                    === false
                ) {
                    throw new RuntimeException(
                        'The Grade Level no longer exists.'
                    );
                }

                $duplicateYearStatement =
                    $pdo->prepare(
                        '
                        SELECT id

                        FROM year_groups

                        WHERE year_number = :year_number
                          AND id <> :id

                        LIMIT 1
                        '
                    );

                $duplicateYearStatement->execute([
                    'year_number' =>
                        $yearNumber,

                    'id' =>
                        $yearGroupId,
                ]);

                if (
                    $duplicateYearStatement->fetchColumn()
                    !== false
                ) {
                    throw new RuntimeException(
                        'That Year Number is already assigned to another Grade Level.'
                    );
                }

                $slug =
                    grade_levels_unique_slug(
                        $pdo,
                        $name,
                        $yearGroupId
                    );

                $updateStatement =
                    $pdo->prepare(
                        '
                        UPDATE year_groups

                        SET
                            name = :name,
                            slug = :slug,
                            year_number = :year_number,
                            description = :description,
                            is_active = :is_active,
                            sort_order = :sort_order

                        WHERE id = :id
                        '
                    );

                $updateStatement->execute([
                    'name' =>
                        $name,

                    'slug' =>
                        $slug,

                    'year_number' =>
                        $yearNumber,

                    'description' =>
                        $description !== ''
                            ? $description
                            : null,

                    'is_active' =>
                        $isActive,

                    'sort_order' =>
                        $sortOrder,

                    'id' =>
                        $yearGroupId,
                ]);

                grade_levels_audit(
                    $pdo,
                    $currentUserId,
                    $yearGroupId,
                    'year_group.update',
                    'Updated Grade Level: '
                    . $name
                );

                $pdo->commit();

                set_flash(
                    'success',
                    'Grade Level updated successfully.'
                );

                redirect(
                    url(
                        'admin/grade-levels.php'
                    )
                );
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if (
                    $exception instanceof RuntimeException
                ) {
                    $errors[] =
                        $exception->getMessage();
                } else {
                    error_log(
                        'Grade Level update error: '
                        . $exception->getMessage()
                    );

                    $errors[] =
                        'The Grade Level could not be updated.';
                }
            }
        }

        $editYearGroupId =
            $yearGroupId;
    }
}


/*
|--------------------------------------------------------------------------
| Load Grade Levels
|--------------------------------------------------------------------------
*/

$yearGroups =
    $pdo->query(
        '
        SELECT
            id,
            name,
            slug,
            year_number,
            description,
            is_active,
            sort_order,
            created_at,
            updated_at

        FROM year_groups

        ORDER BY
            sort_order ASC,
            year_number ASC,
            name ASC,
            id ASC
        '
    )->fetchAll(
        PDO::FETCH_ASSOC
    );

$editYearGroup = null;

if ($editYearGroupId > 0) {
    foreach ($yearGroups as $yearGroupRow) {
        if (
            (int) (
                $yearGroupRow['id']
                ?? 0
            ) === $editYearGroupId
        ) {
            $editYearGroup =
                $yearGroupRow;

            break;
        }
    }
}


/*
|--------------------------------------------------------------------------
| Summary
|--------------------------------------------------------------------------
*/

$summary = [
    'total' => count($yearGroups),
    'active' => 0,
    'highest_year' => 0,
];

foreach ($yearGroups as $yearGroupRow) {
    if (
        (int) (
            $yearGroupRow['is_active']
            ?? 0
        ) === 1
    ) {
        $summary['active']++;
    }

    $summary['highest_year'] =
        max(
            $summary['highest_year'],
            (int) (
                $yearGroupRow['year_number']
                ?? 0
            )
        );
}


/*
|--------------------------------------------------------------------------
| Shared Courses Sidebar
|--------------------------------------------------------------------------
*/

$courseSidebarActive = 'grade-levels';

require INCLUDES_PATH . '/staff-course-sidebar.php';


/*
|--------------------------------------------------------------------------
| SEO / Header
|--------------------------------------------------------------------------
*/

$pageTitle =
    'Grade Levels | Blackthorne Academy';

$pageDescription =
    'Manage Blackthorne Academy grade levels and year groups.';

$pageCanonical =
    url('admin/grade-levels.php');

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
    class="dashboard-page staff-dashboard-page dashboard-workspace-page courses-dashboard-page grade-levels-page">

    <section class="dashboard-hero staff-dashboard-hero" aria-labelledby="grade-levels-heading"
        <?php if ($staffHeroUrl !== ''): ?> style="--staff-dashboard-hero-image: url('<?= e($staffHeroUrl); ?>');"
        <?php endif; ?>>
        <div class="section-inner">
            <div class="dashboard-hero-inner">

                <p class="academy-overline">
                    Academic Administration
                </p>

                <h1 id="grade-levels-heading">
                    Grade Levels
                </h1>

                <p class="dashboard-hero-copy">
                    Define the Academy's progression levels, such as
                    First Year, Second Year, and beyond.
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
                            Academic Progression
                        </p>

                        <h2>
                            Grade Level Management
                        </h2>

                        <p>
                            Course offerings and registration groups use these
                            records to determine which Academy year a course
                            belongs to.
                        </p>
                    </div>
                </header>


                <?php if ($errors !== []): ?>

                <div class="form-message form-message-error" role="alert">
                    <strong>
                        The Grade Level could not be saved.
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


                <div class="dashboard-summary-grid">

                    <article class="dashboard-summary-card">
                        <span class="dashboard-summary-kicker">
                            Grade Levels
                        </span>

                        <strong>
                            <?= number_format($summary['total']); ?>
                        </strong>

                        <p>
                            Total progression levels
                        </p>
                    </article>

                    <article class="dashboard-summary-card">
                        <span class="dashboard-summary-kicker">
                            Active
                        </span>

                        <strong>
                            <?= number_format($summary['active']); ?>
                        </strong>

                        <p>
                            Available for course assignment
                        </p>
                    </article>

                    <article class="dashboard-summary-card">
                        <span class="dashboard-summary-kicker">
                            Highest Year
                        </span>

                        <strong>
                            <?= number_format($summary['highest_year']); ?>
                        </strong>

                        <p>
                            Highest configured year number
                        </p>
                    </article>

                </div>


                <?php if ($editYearGroup !== null): ?>

                <section class="forum-admin-panel">

                    <header class="forum-admin-titlebar">
                        <p class="forum-admin-step">
                            Edit Grade Level
                        </p>

                        <h2>
                            <?= e((string) $editYearGroup['name']); ?>
                        </h2>
                    </header>

                    <form action="<?= e(
                                url(
                                    'admin/grade-levels.php?edit='
                                    . (int) $editYearGroup['id']
                                )
                            ); ?>" method="post" class="forum-admin-form">
                        <?= csrf_field(); ?>

                        <input type="hidden" name="action" value="update_grade_level">

                        <input type="hidden" name="year_group_id" value="<?= (int) $editYearGroup['id']; ?>">


                        <div class="forum-admin-form-grid">

                            <div class="form-group">
                                <label for="edit-grade-level-name">
                                    Grade Level Name
                                </label>

                                <input class="form-control" type="text" id="edit-grade-level-name" name="name"
                                    maxlength="100" value="<?= e(
                                            (string) (
                                                $_POST['action']
                                                ?? ''
                                            ) === 'update_grade_level'
                                                ? grade_levels_post_string('name')
                                                : (string) $editYearGroup['name']
                                        ); ?>" required>

                                <p class="form-help">
                                    Example: First Year.
                                </p>
                            </div>


                            <div class="form-group">
                                <label for="edit-year-number">
                                    Year Number
                                </label>

                                <input class="form-control" type="number" id="edit-year-number" name="year_number"
                                    min="0" step="1" value="<?= e(
                                            (string) (
                                                $_POST['action']
                                                ?? ''
                                            ) === 'update_grade_level'
                                                ? grade_levels_post_string('year_number')
                                                : (string) $editYearGroup['year_number']
                                        ); ?>" required>

                                <p class="form-help">
                                    New/Pre-Orientation = 0, First Year = 1, Second Year = 2, etc.
                                </p>
                            </div>

                        </div>


                        <div class="form-group">
                            <label for="edit-description">
                                Description
                            </label>

                            <textarea class="form-control" id="edit-description" name="description" rows="5"><?= e(
                                    (string) (
                                        $_POST['action']
                                            ?? ''
                                    ) === 'update_grade_level'
                                        ? grade_levels_post_string('description')
                                        : (string) (
                                            $editYearGroup['description']
                                            ?? ''
                                        )
                                ); ?></textarea>
                        </div>


                        <div class="form-group">
                            <label for="edit-sort-order">
                                Sort Order
                            </label>

                            <input class="form-control" type="number" id="edit-sort-order" name="sort_order" min="0"
                                step="1" value="<?= e(
                                        (string) (
                                            $_POST['action']
                                                ?? ''
                                        ) === 'update_grade_level'
                                            ? grade_levels_post_string('sort_order')
                                            : (string) $editYearGroup['sort_order']
                                    ); ?>">

                            <p class="form-help">
                                Controls display order. Leaving it blank
                                uses the Year Number.
                            </p>
                        </div>


                        <fieldset class="forum-admin-fieldset">
                            <legend>
                                Grade Level State
                            </legend>

                            <label class="forum-admin-choice">
                                <input type="checkbox" name="is_active" value="1" <?= (
                                            (
                                                $_POST['action']
                                                ?? ''
                                            ) === 'update_grade_level'
                                                ? isset($_POST['is_active'])
                                                : (int) (
                                                    $editYearGroup['is_active']
                                                    ?? 0
                                                ) === 1
                                        )
                                            ? 'checked'
                                            : ''; ?>>

                                <span>
                                    Active
                                </span>
                            </label>

                            <p class="form-help">
                                Inactive Grade Levels remain in historical
                                records but stop appearing as choices for
                                new course setup.
                            </p>
                        </fieldset>


                        <div class="forum-admin-actions">

                            <a class="button button-secondary" href="<?= e(url('admin/grade-levels.php')); ?>">
                                Cancel
                            </a>

                            <button type="submit" class="button button-primary">
                                Save Grade Level
                            </button>

                        </div>

                    </form>

                </section>

                <?php else: ?>

                <section class="forum-admin-panel">

                    <header class="forum-admin-titlebar">
                        <p class="forum-admin-step">
                            New Grade Level
                        </p>

                        <h2>
                            Create Grade Level
                        </h2>
                    </header>

                    <form action="<?= e(url('admin/grade-levels.php')); ?>" method="post" class="forum-admin-form">
                        <?= csrf_field(); ?>

                        <input type="hidden" name="action" value="create_grade_level">


                        <div class="forum-admin-form-grid">

                            <div class="form-group">
                                <label for="grade-level-name">
                                    Grade Level Name
                                </label>

                                <input class="form-control" type="text" id="grade-level-name" name="name"
                                    maxlength="100" value="<?= e($createForm['name']); ?>" placeholder="First Year"
                                    required>
                            </div>


                            <div class="form-group">
                                <label for="year-number">
                                    Year Number
                                </label>

                                <input class="form-control" type="number" id="year-number" name="year_number" min="0"
                                    step="1" value="<?= e($createForm['year_number']); ?>" placeholder="0" required>

                                <p class="form-help">
                                    New/Pre-Orientation = 0, First Year = 1, Second Year = 2, etc.
                                </p>
                            </div>

                        </div>


                        <div class="form-group">
                            <label for="description">
                                Description
                            </label>

                            <textarea class="form-control" id="description" name="description"
                                rows="5"><?= e($createForm['description']); ?></textarea>
                        </div>


                        <div class="form-group">
                            <label for="sort-order">
                                Sort Order
                            </label>

                            <input class="form-control" type="number" id="sort-order" name="sort_order" min="0" step="1"
                                value="<?= e($createForm['sort_order']); ?>">

                            <p class="form-help">
                                Optional. Leave blank to use the Year Number.
                            </p>
                        </div>


                        <fieldset class="forum-admin-fieldset">
                            <legend>
                                Grade Level State
                            </legend>

                            <label class="forum-admin-choice">
                                <input type="checkbox" name="is_active" value="1" <?= $createForm['is_active'] === '1'
                                            ? 'checked'
                                            : ''; ?>>

                                <span>
                                    Active
                                </span>
                            </label>
                        </fieldset>


                        <div class="forum-admin-actions">
                            <button type="submit" class="button button-primary">
                                Create Grade Level
                            </button>
                        </div>

                    </form>

                </section>

                <?php endif; ?>


                <section class="dashboard-workspace-panel">

                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">
                                Academic Progression
                            </p>

                            <h3>
                                Existing Grade Levels
                            </h3>
                        </div>
                    </div>

                    <div class="dashboard-panel-body">

                        <?php if ($yearGroups === []): ?>

                        <p>
                            No Grade Levels have been created yet.
                            The first expected record can be New/Pre-Orientation
                            with Year Number 0, followed by First Year
                            with Year Number 1.
                        </p>

                        <?php else: ?>

                        <div class="dashboard-placeholder-list">

                            <?php foreach ($yearGroups as $yearGroupRow): ?>

                            <span>

                                <strong>
                                    <?= e((string) $yearGroupRow['name']); ?>
                                </strong>

                                · Year
                                <?= number_format(
                                            (int) (
                                                $yearGroupRow['year_number']
                                                ?? 0
                                            )
                                        ); ?>

                                · Slug:
                                <?= e((string) $yearGroupRow['slug']); ?>

                                · Sort:
                                <?= number_format(
                                            (int) (
                                                $yearGroupRow['sort_order']
                                                ?? 0
                                            )
                                        ); ?>

                                · <?= (int) (
                                            $yearGroupRow['is_active']
                                            ?? 0
                                        ) === 1
                                            ? 'Active'
                                            : 'Inactive'; ?>

                                ·
                                <a href="<?= e(
                                                url(
                                                    'admin/grade-levels.php?edit='
                                                    . (int) $yearGroupRow['id']
                                                )
                                            ); ?>">
                                    Edit
                                </a>

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

<?php

require INCLUDES_PATH . '/footer.php';
