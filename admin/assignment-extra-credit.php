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

$canEditAssignments =
    $isProtectedSuperAdmin
    || user_can('assignments.edit');

$canManageAssignmentSettings =
    $isProtectedSuperAdmin
    || user_can('assignments.settings.manage');

$canManageExtraCredit =
    $canEditAssignments
    || $canManageAssignmentSettings;

if (
    !$hasStaffIdentity
    || !$canManageExtraCredit
) {
    http_response_code(403);

    $pageTitle =
        'Access Denied | Blackthorne Academy';

    $pageDescription =
        'You do not have permission to manage assignment extra credit.';

    $pageCanonical =
        url('admin/assignments.php');

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
                Your account does not have permission to manage assignment extra credit.
            </p>

            <a class="button button-secondary" href="<?= e(url('admin/assignments.php')); ?>">
                Return to Assignments
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

function assignment_ec_post_string(
    string $key
): string {
    $value =
        $_POST[$key]
        ?? '';

    return is_scalar($value)
        ? trim((string) $value)
        : '';
}


function assignment_ec_post_id(
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


function assignment_ec_post_uint(
    string $key,
    int $default = 0
): int {
    $value =
        assignment_ec_post_string(
            $key
        );

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


function assignment_ec_post_decimal(
    string $key,
    float $default = 0.0
): float {
    $value =
        assignment_ec_post_string(
            $key
        );

    if (
        $value === ''
        || !is_numeric($value)
    ) {
        return $default;
    }

    return max(
        0,
        (float) $value
    );
}


function assignment_ec_audit(
    PDO $pdo,
    int $actorUserId,
    int $assignmentId,
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
                'assignment',

            'entity_id' =>
                $assignmentId,

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
            'Assignment extra-credit audit error: '
            . $exception->getMessage()
        );
    }
}


/*
|--------------------------------------------------------------------------
| Identify Offering / Assignment
|--------------------------------------------------------------------------
*/

$offeringId =
    filter_input(
        INPUT_GET,
        'offering',
        FILTER_VALIDATE_INT
    );

$assignmentId =
    filter_input(
        INPUT_GET,
        'assignment',
        FILTER_VALIDATE_INT
    );

if (
    !is_int($offeringId)
    || $offeringId <= 0
) {
    $offeringId = 0;
}

if (
    !is_int($assignmentId)
    || $assignmentId <= 0
) {
    $assignmentId = 0;
}

if (
    $offeringId <= 0
    || $assignmentId <= 0
) {
    set_flash(
        'error',
        'Choose a valid assignment first.'
    );

    redirect(
        url('admin/assignments.php')
    );
}


/*
|--------------------------------------------------------------------------
| Load Assignment Context
|--------------------------------------------------------------------------
*/

$assignmentStatement =
    $pdo->prepare(
        '
        SELECT
            aos.id AS offering_setting_id,
            aos.assignment_id,
            aos.assignment_version_id,
            aos.offering_id,
            aos.course_id,
            aos.allow_extra_credit,
            aos.extra_credit_max_points,

            a.internal_name,

            av.version_number,
            av.title,
            av.status AS version_status,

            c.title AS course_title,

            co.offering_scope,

            sy.name AS school_year_name

        FROM assignment_offering_settings aos

        INNER JOIN assignments a
            ON a.id = aos.assignment_id

        INNER JOIN assignment_versions av
            ON av.id = aos.assignment_version_id

        INNER JOIN course_offerings co
            ON co.id = aos.offering_id

        INNER JOIN courses c
            ON c.id = aos.course_id

        LEFT JOIN school_years sy
            ON sy.id = co.school_year_id

        WHERE aos.offering_id = :offering_id
          AND aos.assignment_id = :assignment_id

        LIMIT 1
        '
    );

$assignmentStatement->execute([
    'offering_id' =>
        $offeringId,

    'assignment_id' =>
        $assignmentId,
]);

$assignment =
    $assignmentStatement->fetch(
        PDO::FETCH_ASSOC
    );

if (!is_array($assignment)) {
    set_flash(
        'error',
        'That assignment is not attached to the selected offering.'
    );

    redirect(
        url(
            'admin/assignments.php?offering='
            . $offeringId
        )
    );
}

$assignmentVersionId =
    (int) (
        $assignment[
            'assignment_version_id'
        ]
        ?? 0
    );

if ($assignmentVersionId <= 0) {
    set_flash(
        'error',
        'This assignment does not have an active version.'
    );

    redirect(
        url(
            'admin/assignment-edit.php?offering='
            . $offeringId
            . '&assignment='
            . $assignmentId
        )
    );
}


/*
|--------------------------------------------------------------------------
| Existing Tasks
|--------------------------------------------------------------------------
*/

$taskStatement =
    $pdo->prepare(
        '
        SELECT
            id,
            assignment_version_id,
            title,
            instructions,
            points_possible,
            sort_order,
            created_at,
            updated_at

        FROM assignment_extra_credit_tasks

        WHERE assignment_version_id = :assignment_version_id

        ORDER BY
            sort_order ASC,
            id ASC
        '
    );

$taskStatement->execute([
    'assignment_version_id' =>
        $assignmentVersionId,
]);

$tasks =
    $taskStatement->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Totals
|--------------------------------------------------------------------------
*/

$totalTaskPoints = 0.0;

foreach ($tasks as $taskRow) {
    $totalTaskPoints +=
        (float) (
            $taskRow[
                'points_possible'
            ]
            ?? 0
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
    'instructions' => '',
    'points_possible' => '',
    'sort_order' =>
        (string) (
            count($tasks) + 1
        ),
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
        assignment_ec_post_string(
            'action'
        );


    /*
    |--------------------------------------------------------------------------
    | Create Task
    |--------------------------------------------------------------------------
    */

    if (
        $errors === []
        && $action === 'create_task'
    ) {
        if (
            (int) (
                $assignment[
                    'allow_extra_credit'
                ]
                ?? 0
            ) !== 1
        ) {
            $errors[] =
                'Extra credit must be enabled in the assignment offering settings before tasks can be added.';
        }

        $form['title'] =
            assignment_ec_post_string(
                'title'
            );

        $form['instructions'] =
            assignment_ec_post_string(
                'instructions'
            );

        $form['points_possible'] =
            assignment_ec_post_string(
                'points_possible'
            );

        $form['sort_order'] =
            assignment_ec_post_string(
                'sort_order'
            );

        $title =
            $form['title'];

        $pointsPossible =
            assignment_ec_post_decimal(
                'points_possible',
                0.0
            );

        $sortOrder =
            assignment_ec_post_uint(
                'sort_order',
                count($tasks) + 1
            );

        if ($title === '') {
            $errors[] =
                'Extra-Credit Task Title is required.';
        }

        if (strlen($title) > 200) {
            $errors[] =
                'Extra-Credit Task Title must be 200 characters or fewer.';
        }

        if ($pointsPossible <= 0) {
            $errors[] =
                'Points Possible must be greater than zero.';
        }

        $extraCreditCap =
            (float) (
                $assignment[
                    'extra_credit_max_points'
                ]
                ?? 0
            );

        if (
            $extraCreditCap > 0
            && (
                $totalTaskPoints
                + $pointsPossible
            ) > $extraCreditCap
        ) {
            $errors[] =
                'The combined extra-credit task points cannot exceed the offering maximum of '
                . number_format(
                    $extraCreditCap,
                    2
                )
                . ' points.';
        }

        if ($errors === []) {
            try {
                $insertTask =
                    $pdo->prepare(
                        '
                        INSERT INTO assignment_extra_credit_tasks (
                            assignment_version_id,
                            title,
                            instructions,
                            points_possible,
                            sort_order
                        ) VALUES (
                            :assignment_version_id,
                            :title,
                            :instructions,
                            :points_possible,
                            :sort_order
                        )
                        '
                    );

                $insertTask->execute([
                    'assignment_version_id' =>
                        $assignmentVersionId,

                    'title' =>
                        $title,

                    'instructions' =>
                        $form['instructions'] !== ''
                            ? $form['instructions']
                            : null,

                    'points_possible' =>
                        number_format(
                            $pointsPossible,
                            2,
                            '.',
                            ''
                        ),

                    'sort_order' =>
                        $sortOrder,
                ]);

                assignment_ec_audit(
                    $pdo,
                    $currentUserId,
                    $assignmentId,
                    'assignment.extra_credit_task.create',
                    'Created extra-credit task "'
                    . $title
                    . '" for assignment version #'
                    . $assignmentVersionId
                );

                set_flash(
                    'success',
                    'Extra-credit task created successfully.'
                );

                redirect(
                    url(
                        'admin/assignment-extra-credit.php?offering='
                        . $offeringId
                        . '&assignment='
                        . $assignmentId
                    )
                );
            } catch (Throwable $exception) {
                error_log(
                    'Extra-credit task create error: '
                    . $exception->getMessage()
                );

                $errors[] =
                    'The extra-credit task could not be created.';
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Delete Task
    |--------------------------------------------------------------------------
    */

    if (
        $errors === []
        && $action === 'delete_task'
    ) {
        $taskId =
            assignment_ec_post_id(
                'task_id'
            );

        if ($taskId <= 0) {
            $errors[] =
                'Choose a valid extra-credit task.';
        }

        $taskToDelete = null;

        if ($errors === []) {
            foreach ($tasks as $taskRow) {
                if (
                    (int) (
                        $taskRow['id']
                        ?? 0
                    ) === $taskId
                ) {
                    $taskToDelete =
                        $taskRow;

                    break;
                }
            }

            if ($taskToDelete === null) {
                $errors[] =
                    'That extra-credit task does not belong to the active assignment version.';
            }
        }

        if ($errors === []) {
            $responseStatement =
                $pdo->prepare(
                    '
                    SELECT COUNT(*)

                    FROM assignment_extra_credit_responses

                    WHERE extra_credit_task_id = :task_id
                    '
                );

            $responseStatement->execute([
                'task_id' =>
                    $taskId,
            ]);

            $responseCount =
                (int) $responseStatement->fetchColumn();

            if ($responseCount > 0) {
                $errors[] =
                    'This task already has student responses and cannot be deleted.';
            }
        }

        if ($errors === []) {
            try {
                $deleteStatement =
                    $pdo->prepare(
                        '
                        DELETE FROM assignment_extra_credit_tasks

                        WHERE id = :task_id
                          AND assignment_version_id = :assignment_version_id
                        '
                    );

                $deleteStatement->execute([
                    'task_id' =>
                        $taskId,

                    'assignment_version_id' =>
                        $assignmentVersionId,
                ]);

                assignment_ec_audit(
                    $pdo,
                    $currentUserId,
                    $assignmentId,
                    'assignment.extra_credit_task.delete',
                    'Deleted extra-credit task #'
                    . $taskId
                    . ' from assignment version #'
                    . $assignmentVersionId
                );

                set_flash(
                    'success',
                    'Extra-credit task deleted successfully.'
                );

                redirect(
                    url(
                        'admin/assignment-extra-credit.php?offering='
                        . $offeringId
                        . '&assignment='
                        . $assignmentId
                    )
                );
            } catch (Throwable $exception) {
                error_log(
                    'Extra-credit task delete error: '
                    . $exception->getMessage()
                );

                $errors[] =
                    'The extra-credit task could not be deleted.';
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
    'assignments';

require INCLUDES_PATH . '/staff-course-sidebar.php';


/*
|--------------------------------------------------------------------------
| SEO / Header
|--------------------------------------------------------------------------
*/

$pageTitle =
    'Assignment Extra Credit | Blackthorne Academy';

$pageDescription =
    'Manage extra-credit tasks for an assignment version.';

$pageCanonical =
    url(
        'admin/assignment-extra-credit.php?offering='
        . $offeringId
        . '&assignment='
        . $assignmentId
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

<main id="main-content"
    class="dashboard-page staff-dashboard-page dashboard-workspace-page courses-dashboard-page assignment-extra-credit-page">

    <section class="dashboard-hero staff-dashboard-hero" aria-labelledby="assignment-extra-credit-heading"
        <?php if ($staffHeroUrl !== ''): ?> style="--staff-dashboard-hero-image: url('<?= e($staffHeroUrl); ?>');"
        <?php endif; ?>>
        <div class="section-inner">
            <div class="dashboard-hero-inner">

                <p class="academy-overline">
                    Assignment Management
                </p>

                <h1 id="assignment-extra-credit-heading">
                    Extra Credit
                </h1>

                <p class="dashboard-hero-copy">
                    Manage optional extra-credit tasks for the exact assignment
                    version currently used by this course offering.
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
                                    $assignment[
                                        'course_title'
                                    ]
                                    ?? 'Course'
                                )
                            ); ?>
                        </p>

                        <h2>
                            <?= e(
                                (string) (
                                    $assignment[
                                        'title'
                                    ]
                                    ?? $assignment[
                                        'internal_name'
                                    ]
                                    ?? 'Assignment'
                                )
                            ); ?>
                        </h2>

                        <p>
                            Managing extra credit for Version
                            <?= number_format(
                                (int) (
                                    $assignment[
                                        'version_number'
                                    ]
                                    ?? 1
                                )
                            ); ?>.
                        </p>
                    </div>

                    <div class="dashboard-workspace-heading-actions">
                        <a class="button button-secondary" href="<?= e(
                                url(
                                    'admin/assignment-edit.php?offering='
                                    . $offeringId
                                    . '&assignment='
                                    . $assignmentId
                                )
                            ); ?>">
                            Back to Assignment
                        </a>
                    </div>
                </header>


                <?php if ($errors !== []): ?>

                <div class="form-message form-message-error" role="alert">
                    <strong>
                        Extra credit could not be updated.
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
                                Offering Limit
                            </p>

                            <h3>
                                Extra-Credit Allowance
                            </h3>
                        </div>
                    </div>

                    <div class="dashboard-panel-body">

                        <div class="dashboard-placeholder-list">

                            <span>
                                <strong>Enabled:</strong>
                                <?= (int) (
                                    $assignment[
                                        'allow_extra_credit'
                                    ]
                                    ?? 0
                                ) === 1
                                    ? 'Yes'
                                    : 'No'; ?>
                            </span>

                            <span>
                                <strong>Maximum Allowed:</strong>
                                <?= e(
                                    number_format(
                                        (float) (
                                            $assignment[
                                                'extra_credit_max_points'
                                            ]
                                            ?? 0
                                        ),
                                        2
                                    )
                                ); ?>
                                points
                            </span>

                            <span>
                                <strong>Currently Assigned:</strong>
                                <?= e(
                                    number_format(
                                        $totalTaskPoints,
                                        2
                                    )
                                ); ?>
                                points
                            </span>

                            <span>
                                <strong>Remaining:</strong>
                                <?= e(
                                    number_format(
                                        max(
                                            0,
                                            (float) (
                                                $assignment[
                                                    'extra_credit_max_points'
                                                ]
                                                ?? 0
                                            )
                                            - $totalTaskPoints
                                        ),
                                        2
                                    )
                                ); ?>
                                points
                            </span>

                        </div>

                        <?php if (
                            (int) (
                                $assignment[
                                    'allow_extra_credit'
                                ]
                                ?? 0
                            ) !== 1
                        ): ?>
                        <p class="form-help">
                            Extra credit is currently disabled for this offering.
                            Enable it from Assignment Edit before adding tasks.
                        </p>
                        <?php endif; ?>

                    </div>

                </section>


                <section class="dashboard-workspace-panel">

                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">
                                Version Tasks
                            </p>

                            <h3>
                                Existing Extra-Credit Tasks
                            </h3>
                        </div>
                    </div>

                    <div class="dashboard-panel-body">

                        <?php if ($tasks === []): ?>

                        <p>
                            No extra-credit tasks have been created for this version.
                        </p>

                        <?php else: ?>

                        <div class="dashboard-placeholder-list">

                            <?php foreach ($tasks as $taskRow): ?>

                            <span>

                                <strong>
                                    <?= number_format(
                                                (int) (
                                                    $taskRow[
                                                        'sort_order'
                                                    ]
                                                    ?? 0
                                                )
                                            ); ?>.
                                    <?= e(
                                                (string) (
                                                    $taskRow[
                                                        'title'
                                                    ]
                                                    ?? 'Extra-Credit Task'
                                                )
                                            ); ?>
                                </strong>

                                ·
                                <?= e(
                                            number_format(
                                                (float) (
                                                    $taskRow[
                                                        'points_possible'
                                                    ]
                                                    ?? 0
                                                ),
                                                2
                                            )
                                        ); ?>
                                points

                                <form action="<?= e(
                                                url(
                                                    'admin/assignment-extra-credit.php?offering='
                                                    . $offeringId
                                                    . '&assignment='
                                                    . $assignmentId
                                                )
                                            ); ?>" method="post" style="display:inline;"
                                    onsubmit="return confirm('Delete this extra-credit task?');">
                                    <?= csrf_field(); ?>

                                    <input type="hidden" name="action" value="delete_task">

                                    <input type="hidden" name="task_id" value="<?= (int) (
                                                    $taskRow[
                                                        'id'
                                                    ]
                                                    ?? 0
                                                ); ?>">

                                    ·
                                    <button type="submit" class="button-link">
                                        Delete
                                    </button>
                                </form>

                            </span>

                            <?php endforeach; ?>

                        </div>

                        <?php endif; ?>

                    </div>

                </section>


                <?php if (
                    (int) (
                        $assignment[
                            'allow_extra_credit'
                        ]
                        ?? 0
                    ) === 1
                ): ?>

                <section class="forum-admin-panel">

                    <header class="forum-admin-titlebar">
                        <p class="forum-admin-step">
                            New Task
                        </p>

                        <h2>
                            Add Extra-Credit Task
                        </h2>
                    </header>

                    <form action="<?= e(
                                url(
                                    'admin/assignment-extra-credit.php?offering='
                                    . $offeringId
                                    . '&assignment='
                                    . $assignmentId
                                )
                            ); ?>" method="post" class="forum-admin-form">
                        <?= csrf_field(); ?>

                        <input type="hidden" name="action" value="create_task">


                        <div class="form-group">
                            <label for="task-title">
                                Task Title
                            </label>

                            <input class="form-control" type="text" id="task-title" name="title" maxlength="200"
                                value="<?= e($form['title']); ?>" required>
                        </div>


                        <div class="form-group">
                            <label for="task-instructions">
                                Instructions
                            </label>

                            <textarea class="form-control" id="task-instructions" name="instructions"
                                rows="8"><?= e($form['instructions']); ?></textarea>
                        </div>


                        <div class="forum-admin-form-grid">

                            <div class="form-group">
                                <label for="task-points">
                                    Points Possible
                                </label>

                                <input class="form-control" type="number" id="task-points" name="points_possible"
                                    min="0.01" step="0.01" value="<?= e($form['points_possible']); ?>" required>
                            </div>


                            <div class="form-group">
                                <label for="task-sort-order">
                                    Display Order
                                </label>

                                <input class="form-control" type="number" id="task-sort-order" name="sort_order" min="0"
                                    step="1" value="<?= e($form['sort_order']); ?>" required>
                            </div>

                        </div>


                        <div class="forum-admin-actions">
                            <button type="submit" class="button button-primary">
                                Add Extra-Credit Task
                            </button>
                        </div>

                    </form>

                </section>

                <?php endif; ?>

            </div>

        </div>
    </section>

</main>

<?php

require INCLUDES_PATH . '/footer.php';
