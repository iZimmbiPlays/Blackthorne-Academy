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

$canCreateAssignments =
    $isProtectedSuperAdmin
    || user_can('assignments.create');

$canEditAssignments =
    $isProtectedSuperAdmin
    || user_can('assignments.edit');

$canPublishAssignments =
    $isProtectedSuperAdmin
    || user_can('assignments.publish');

$canManageAssignmentSettings =
    $isProtectedSuperAdmin
    || user_can('assignments.settings.manage');

$canAccessAssignments =
    $canCreateAssignments
    || $canEditAssignments
    || $canPublishAssignments
    || $canManageAssignmentSettings;

if (
    !$hasStaffIdentity
    || !$canAccessAssignments
) {
    http_response_code(403);

    $pageTitle =
        'Access Denied | Blackthorne Academy';

    $pageDescription =
        'You do not have permission to manage assignments.';

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
                Your account does not have permission to manage assignments.
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

function assignments_post_string(
    string $key
): string {
    $value =
        $_POST[$key]
        ?? '';

    return is_scalar($value)
        ? trim((string) $value)
        : '';
}


function assignments_post_id(
    string $key
): int {
    $value =
        $_POST[$key]
        ?? '';

    if (
        !is_scalar($value)
        || !ctype_digit((string) $value)
    ) {
        return 0;
    }

    return max(
        0,
        (int) $value
    );
}


function assignments_post_bool(
    string $key
): int {
    return isset($_POST[$key])
        ? 1
        : 0;
}


function assignments_post_decimal(
    string $key,
    float $default = 0.0
): float {
    $value =
        assignments_post_string($key);

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


function assignments_post_uint(
    string $key,
    int $default = 0
): int {
    $value =
        assignments_post_string($key);

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


function assignments_normalize_datetime(
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


function assignments_format_datetime(
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


function assignments_audit(
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
            'Assignment audit error: '
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
            co.status,
            c.title AS course_title,
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
            co.status,
            c.title,
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
| Offering Lessons
|--------------------------------------------------------------------------
*/

$offeringLessons = [];

if ($selectedOffering !== null) {
    $lessonStatement =
        $pdo->prepare(
            '
            SELECT
                col.id AS course_offering_lesson_id,
                col.lesson_id,
                col.sort_order,
                lv.title

            FROM course_offering_lessons col

            INNER JOIN lesson_versions lv
                ON lv.id = col.lesson_version_id

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
| Existing Assignments
|--------------------------------------------------------------------------
*/

$assignments = [];

if ($selectedOffering !== null) {
    $assignmentStatement =
        $pdo->prepare(
            '
            SELECT
                aos.id AS offering_setting_id,
                aos.assignment_id,
                aos.assignment_version_id,
                aos.course_offering_lesson_id,
                aos.points_possible,
                aos.due_date,
                aos.submission_close_date,
                aos.allow_resubmissions,
                aos.max_submissions,
                aos.allow_late_submissions,
                aos.allow_extra_credit,
                aos.extra_credit_max_points,

                a.internal_name,
                a.is_published,

                av.version_number,
                av.title,
                av.description,
                av.status AS version_status,
                av.allow_text_submission,
                av.allow_file_upload,

                lv.title AS lesson_title,

                (
                    SELECT COUNT(*)
                    FROM assignment_submissions s
                    WHERE s.assignment_id = aos.assignment_id
                      AND s.offering_id = aos.offering_id
                ) AS submission_count

            FROM assignment_offering_settings aos

            INNER JOIN assignments a
                ON a.id = aos.assignment_id

            LEFT JOIN assignment_versions av
                ON av.id = aos.assignment_version_id

            LEFT JOIN course_offering_lessons col
                ON col.id = aos.course_offering_lesson_id

            LEFT JOIN lesson_versions lv
                ON lv.id = col.lesson_version_id

            WHERE aos.offering_id = :offering_id

            ORDER BY
                CASE
                    WHEN col.sort_order IS NULL
                    THEN 999999
                    ELSE col.sort_order
                END ASC,
                aos.id ASC
            '
        );

    $assignmentStatement->execute([
        'offering_id' =>
            $selectedOfferingId,
    ]);

    $assignments =
        $assignmentStatement->fetchAll(
            PDO::FETCH_ASSOC
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
    'instructions' => '',
    'course_offering_lesson_id' => '',
    'allow_text_submission' => '1',
    'allow_file_upload' => '0',
    'status' => 'draft',
    'points_possible' => '100',
    'due_date' => '',
    'submission_close_date' => '',
    'allow_resubmissions' => '0',
    'max_submissions' => '1',
    'allow_late_submissions' => '1',
    'allow_extra_credit' => '0',
    'extra_credit_max_points' => '0',
];


/*
|--------------------------------------------------------------------------
| Create Assignment
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
        assignments_post_string(
            'action'
        );

    if (
        $errors === []
        && $action === 'create_assignment'
    ) {
        if (!$canCreateAssignments) {
            $errors[] =
                'You do not have permission to create assignments.';
        }

        $postedOfferingId =
            assignments_post_id(
                'offering_id'
            );

        if (
            $postedOfferingId <= 0
            || $postedOfferingId !== $selectedOfferingId
            || $selectedOffering === null
        ) {
            $errors[] =
                'Select a valid course offering first.';
        }

        $form['title'] =
            assignments_post_string(
                'title'
            );

        $form['description'] =
            assignments_post_string(
                'description'
            );

        $form['instructions'] =
            assignments_post_string(
                'instructions'
            );

        $form['course_offering_lesson_id'] =
            assignments_post_string(
                'course_offering_lesson_id'
            );

        $form['allow_text_submission'] =
            (string) assignments_post_bool(
                'allow_text_submission'
            );

        $form['allow_file_upload'] =
            (string) assignments_post_bool(
                'allow_file_upload'
            );

        $form['status'] =
            assignments_post_string(
                'status'
            );

        $form['points_possible'] =
            assignments_post_string(
                'points_possible'
            );

        $form['due_date'] =
            assignments_post_string(
                'due_date'
            );

        $form['submission_close_date'] =
            assignments_post_string(
                'submission_close_date'
            );

        $form['allow_resubmissions'] =
            (string) assignments_post_bool(
                'allow_resubmissions'
            );

        $form['max_submissions'] =
            assignments_post_string(
                'max_submissions'
            );

        $form['allow_late_submissions'] =
            (string) assignments_post_bool(
                'allow_late_submissions'
            );

        $form['allow_extra_credit'] =
            (string) assignments_post_bool(
                'allow_extra_credit'
            );

        $form['extra_credit_max_points'] =
            assignments_post_string(
                'extra_credit_max_points'
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

        $courseOfferingLessonId =
            assignments_post_id(
                'course_offering_lesson_id'
            );

        $allowTextSubmission =
            assignments_post_bool(
                'allow_text_submission'
            );

        $allowFileUpload =
            assignments_post_bool(
                'allow_file_upload'
            );

        $pointsPossible =
            assignments_post_decimal(
                'points_possible',
                100.0
            );

        $dueDate =
            assignments_normalize_datetime(
                $form['due_date']
            );

        $submissionCloseDate =
            assignments_normalize_datetime(
                $form[
                    'submission_close_date'
                ]
            );

        $allowResubmissions =
            assignments_post_bool(
                'allow_resubmissions'
            );

        $maxSubmissions =
            assignments_post_uint(
                'max_submissions',
                1
            );

        $allowLateSubmissions =
            assignments_post_bool(
                'allow_late_submissions'
            );

        $allowExtraCredit =
            assignments_post_bool(
                'allow_extra_credit'
            );

        $extraCreditMaxPoints =
            assignments_post_decimal(
                'extra_credit_max_points',
                0.0
            );

        if ($title === '') {
            $errors[] =
                'Assignment Title is required.';
        }

        if (strlen($title) > 200) {
            $errors[] =
                'Assignment Title must be 200 characters or fewer.';
        }

        if (
            $allowTextSubmission !== 1
            && $allowFileUpload !== 1
        ) {
            $errors[] =
                'Allow at least one submission method: text or file upload.';
        }

        if (
            $status === 'published'
            && !$canPublishAssignments
        ) {
            $errors[] =
                'You do not have permission to publish assignments.';
        }

        if (
            $courseOfferingLessonId > 0
        ) {
            $validLesson = false;

            foreach ($offeringLessons as $lessonRow) {
                if (
                    (int) (
                        $lessonRow[
                            'course_offering_lesson_id'
                        ]
                        ?? 0
                    ) === $courseOfferingLessonId
                ) {
                    $validLesson = true;
                    break;
                }
            }

            if (!$validLesson) {
                $errors[] =
                    'The selected lesson does not belong to this course offering.';
            }
        }

        if ($pointsPossible <= 0) {
            $errors[] =
                'Points Possible must be greater than zero.';
        }

        if (
            $form['due_date'] !== ''
            && $dueDate === null
        ) {
            $errors[] =
                'Due Date is invalid.';
        }

        if (
            $form['submission_close_date'] !== ''
            && $submissionCloseDate === null
        ) {
            $errors[] =
                'Submission Close Date is invalid.';
        }

        if (
            $dueDate !== null
            && $submissionCloseDate !== null
            && $submissionCloseDate < $dueDate
        ) {
            $errors[] =
                'Submission Close Date cannot be earlier than the Due Date.';
        }

        if (
            $allowResubmissions === 1
            && $maxSubmissions < 2
        ) {
            $errors[] =
                'Allowing resubmissions requires at least 2 maximum submissions.';
        }

        if ($maxSubmissions < 1) {
            $errors[] =
                'Maximum Submissions must be at least 1.';
        }

        if (
            $allowExtraCredit !== 1
        ) {
            $extraCreditMaxPoints = 0.0;
        }

        if (
            $allowExtraCredit === 1
            && $extraCreditMaxPoints <= 0
        ) {
            $errors[] =
                'Enter the maximum extra-credit points when extra credit is enabled.';
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

                $insertAssignment =
                    $pdo->prepare(
                        '
                        INSERT INTO assignments (
                            course_id,
                            internal_name,
                            is_published
                        ) VALUES (
                            :course_id,
                            :internal_name,
                            :is_published
                        )
                        '
                    );

                $insertAssignment->execute([
                    'course_id' =>
                        $courseId,

                    'internal_name' =>
                        $title,

                    'is_published' =>
                        $status === 'published'
                            ? 1
                            : 0,
                ]);

                $assignmentId =
                    (int) $pdo->lastInsertId();

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
                        INSERT INTO assignment_versions (
                            assignment_id,
                            version_number,
                            title,
                            description,
                            instructions,
                            allow_text_submission,
                            allow_file_upload,
                            status,
                            created_by,
                            approved_by,
                            published_at
                        ) VALUES (
                            :assignment_id,
                            1,
                            :title,
                            :description,
                            :instructions,
                            :allow_text_submission,
                            :allow_file_upload,
                            :status,
                            :created_by,
                            :approved_by,
                            :published_at
                        )
                        '
                    );

                $insertVersion->execute([
                    'assignment_id' =>
                        $assignmentId,

                    'title' =>
                        $title,

                    'description' =>
                        $form['description'] !== ''
                            ? $form['description']
                            : null,

                    'instructions' =>
                        $form['instructions'] !== ''
                            ? $form['instructions']
                            : null,

                    'allow_text_submission' =>
                        $allowTextSubmission,

                    'allow_file_upload' =>
                        $allowFileUpload,

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

                $assignmentVersionId =
                    (int) $pdo->lastInsertId();

                $insertSettings =
                    $pdo->prepare(
                        '
                        INSERT INTO assignment_offering_settings (
                            assignment_id,
                            assignment_version_id,
                            offering_id,
                            course_id,
                            course_offering_lesson_id,
                            points_possible,
                            due_date,
                            submission_close_date,
                            allow_resubmissions,
                            max_submissions,
                            allow_late_submissions,
                            allow_extra_credit,
                            extra_credit_max_points
                        ) VALUES (
                            :assignment_id,
                            :assignment_version_id,
                            :offering_id,
                            :course_id,
                            :course_offering_lesson_id,
                            :points_possible,
                            :due_date,
                            :submission_close_date,
                            :allow_resubmissions,
                            :max_submissions,
                            :allow_late_submissions,
                            :allow_extra_credit,
                            :extra_credit_max_points
                        )
                        '
                    );

                $insertSettings->execute([
                    'assignment_id' =>
                        $assignmentId,

                    'assignment_version_id' =>
                        $assignmentVersionId,

                    'offering_id' =>
                        $selectedOfferingId,

                    'course_id' =>
                        $courseId,

                    'course_offering_lesson_id' =>
                        $courseOfferingLessonId > 0
                            ? $courseOfferingLessonId
                            : null,

                    'points_possible' =>
                        number_format(
                            $pointsPossible,
                            2,
                            '.',
                            ''
                        ),

                    'due_date' =>
                        $dueDate,

                    'submission_close_date' =>
                        $submissionCloseDate,

                    'allow_resubmissions' =>
                        $allowResubmissions,

                    'max_submissions' =>
                        $allowResubmissions === 1
                            ? $maxSubmissions
                            : 1,

                    'allow_late_submissions' =>
                        $allowLateSubmissions,

                    'allow_extra_credit' =>
                        $allowExtraCredit,

                    'extra_credit_max_points' =>
                        number_format(
                            $extraCreditMaxPoints,
                            2,
                            '.',
                            ''
                        ),
                ]);

                assignments_audit(
                    $pdo,
                    $currentUserId,
                    $assignmentId,
                    'assignment.create',
                    'Created assignment "'
                    . $title
                    . '" for offering #'
                    . $selectedOfferingId
                );

                $pdo->commit();

                set_flash(
                    'success',
                    'Assignment created successfully.'
                );

                redirect(
                    url(
                        'admin/assignments.php?offering='
                        . $selectedOfferingId
                    )
                );
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'Assignment create error: '
                    . $exception->getMessage()
                );

                $errors[] =
                    'The assignment could not be created.';
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
    'Assignments | Blackthorne Academy';

$pageDescription =
    'Manage course assignments and offering settings.';

$pageCanonical =
    url('admin/assignments.php');

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
    class="dashboard-page staff-dashboard-page dashboard-workspace-page courses-dashboard-page assignments-admin-page">

    <section class="dashboard-hero staff-dashboard-hero" aria-labelledby="assignments-heading"
        <?php if ($staffHeroUrl !== ''): ?> style="--staff-dashboard-hero-image: url('<?= e($staffHeroUrl); ?>');"
        <?php endif; ?>>
        <div class="section-inner">
            <div class="dashboard-hero-inner">

                <p class="academy-overline">
                    Course Content
                </p>

                <h1 id="assignments-heading">
                    Assignments
                </h1>

                <p class="dashboard-hero-copy">
                    Create versioned assignments and configure points,
                    deadlines, submission methods, and offering-specific rules.
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
                            Assignment Management
                        </p>

                        <h2>
                            Course Assignments
                        </h2>

                        <p>
                            Choose the exact offering first. Assignment content
                            is versioned while points and submission rules remain
                            specific to that offering.
                        </p>
                    </div>
                </header>


                <?php if ($errors !== []): ?>

                <div class="form-message form-message-error" role="alert">
                    <strong>
                        The assignment could not be saved.
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

                    <form action="<?= e(url('admin/assignments.php')); ?>" method="get" class="forum-admin-form">
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
                                    $context = [];

                                    if (
                                        ($offeringRow['offering_scope'] ?? '')
                                        === 'perpetual'
                                    ) {
                                        $context[] = 'Perpetual';
                                    } else {
                                        if (
                                            !empty(
                                                $offeringRow[
                                                    'school_year_name'
                                                ]
                                            )
                                        ) {
                                            $context[] =
                                                (string) $offeringRow[
                                                    'school_year_name'
                                                ];
                                        }

                                        if (
                                            !empty(
                                                $offeringRow[
                                                    'year_group_names'
                                                ]
                                            )
                                        ) {
                                            $context[] =
                                                (string) $offeringRow[
                                                    'year_group_names'
                                                ];
                                        }
                                    }

                                    $context[] =
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

                                    $label =
                                        (string) (
                                            $offeringRow[
                                                'course_title'
                                            ]
                                            ?? 'Course'
                                        )
                                        . ' — '
                                        . implode(
                                            ' · ',
                                            $context
                                        );
                                    ?>

                                <option value="<?= (int) $offeringRow['id']; ?>" <?= (int) $offeringRow['id'] === $selectedOfferingId
                                            ? 'selected'
                                            : ''; ?>>
                                    <?= e($label); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="forum-admin-actions">
                            <button type="submit" class="button button-primary">
                                Load Assignments
                            </button>
                        </div>
                    </form>

                </section>


                <?php if ($selectedOffering !== null): ?>

                <section class="dashboard-workspace-panel">

                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">
                                Existing Content
                            </p>

                            <h3>
                                Assignments
                            </h3>
                        </div>
                    </div>

                    <div class="dashboard-panel-body">

                        <?php if ($assignments === []): ?>

                        <p>
                            No assignments have been created for this offering yet.
                        </p>

                        <?php else: ?>

                        <div class="dashboard-placeholder-list">

                            <?php foreach ($assignments as $assignmentRow): ?>

                            <span>

                                <strong>
                                    <?= e(
                                                    (string) (
                                                        $assignmentRow[
                                                            'title'
                                                        ]
                                                        ?? $assignmentRow[
                                                            'internal_name'
                                                        ]
                                                        ?? 'Assignment'
                                                    )
                                                ); ?>
                                </strong>

                                · Version
                                <?= number_format(
                                                (int) (
                                                    $assignmentRow[
                                                        'version_number'
                                                    ]
                                                    ?? 1
                                                )
                                            ); ?>

                                ·
                                <?= e(
                                                ucfirst(
                                                    (string) (
                                                        $assignmentRow[
                                                            'version_status'
                                                        ]
                                                        ?? 'draft'
                                                    )
                                                )
                                            ); ?>

                                ·
                                <?= e(
                                                number_format(
                                                    (float) (
                                                        $assignmentRow[
                                                            'points_possible'
                                                        ]
                                                        ?? 0
                                                    ),
                                                    2
                                                )
                                            ); ?>
                                points

                                <?php if (
                                                !empty(
                                                    $assignmentRow[
                                                        'lesson_title'
                                                    ]
                                                )
                                            ): ?>
                                · Lesson:
                                <?= e(
                                                    (string) $assignmentRow[
                                                        'lesson_title'
                                                    ]
                                                ); ?>
                                <?php endif; ?>

                                <?php if (
                                                !empty(
                                                    $assignmentRow[
                                                        'due_date'
                                                    ]
                                                )
                                            ): ?>
                                · Due:
                                <?= e(
                                                    assignments_format_datetime(
                                                        $assignmentRow[
                                                            'due_date'
                                                        ]
                                                    )
                                                ); ?>
                                <?php endif; ?>

                                ·
                                <?= number_format(
                                                (int) (
                                                    $assignmentRow[
                                                        'submission_count'
                                                    ]
                                                    ?? 0
                                                )
                                            ); ?>
                                submission(s)

                                <?php if ($canEditAssignments): ?>
                                ·
                                <a href="<?= e(
                                                        url(
                                                            'admin/assignment-edit.php?offering='
                                                            . $selectedOfferingId
                                                            . '&assignment='
                                                            . (int) (
                                                                $assignmentRow[
                                                                    'assignment_id'
                                                                ]
                                                                ?? 0
                                                            )
                                                        )
                                                    ); ?>">
                                    Edit
                                </a>
                                <?php endif; ?>

                            </span>

                            <?php endforeach; ?>

                        </div>

                        <?php endif; ?>

                    </div>

                </section>


                <?php if ($canCreateAssignments): ?>

                <section class="forum-admin-panel">

                    <header class="forum-admin-titlebar">
                        <p class="forum-admin-step">
                            Step 2
                        </p>

                        <h2>
                            Create Assignment
                        </h2>
                    </header>

                    <form action="<?= e(
                                    url(
                                        'admin/assignments.php?offering='
                                        . $selectedOfferingId
                                    )
                                ); ?>" method="post" class="forum-admin-form">
                        <?= csrf_field(); ?>

                        <input type="hidden" name="action" value="create_assignment">

                        <input type="hidden" name="offering_id" value="<?= $selectedOfferingId; ?>">


                        <div class="form-group">
                            <label for="assignment-title">
                                Assignment Title
                            </label>

                            <input class="form-control" type="text" id="assignment-title" name="title" maxlength="200"
                                value="<?= e($form['title']); ?>" required>
                        </div>


                        <div class="form-group">
                            <label for="assignment-description">
                                Short Description
                            </label>

                            <textarea class="form-control" id="assignment-description" name="description"
                                rows="4"><?= e($form['description']); ?></textarea>
                        </div>


                        <div class="form-group">
                            <label for="assignment-instructions">
                                Assignment Instructions
                            </label>

                            <textarea class="form-control" id="assignment-instructions" name="instructions"
                                rows="12"><?= e($form['instructions']); ?></textarea>
                        </div>


                        <div class="form-group">
                            <label for="course-offering-lesson-id">
                                Related Lesson
                            </label>

                            <select class="form-control" id="course-offering-lesson-id"
                                name="course_offering_lesson_id">
                                <option value="">
                                    None / Course-level assignment
                                </option>

                                <?php foreach ($offeringLessons as $lessonRow): ?>
                                <option value="<?= (int) $lessonRow['course_offering_lesson_id']; ?>" <?= (string) $lessonRow['course_offering_lesson_id']
                                                    === $form['course_offering_lesson_id']
                                                        ? 'selected'
                                                        : ''; ?>>
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
                                                        ?? 'Lesson'
                                                    )
                                                ); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>

                            <p class="form-help">
                                Leave blank for an assignment that belongs
                                to the course rather than a specific lesson.
                            </p>
                        </div>


                        <fieldset class="forum-admin-fieldset">

                            <legend>
                                Submission Methods
                            </legend>

                            <label class="forum-admin-choice">
                                <input type="checkbox" name="allow_text_submission" value="1" <?= $form['allow_text_submission'] === '1'
                                                ? 'checked'
                                                : ''; ?>>

                                <span>
                                    Allow text response
                                </span>
                            </label>

                            <label class="forum-admin-choice">
                                <input type="checkbox" name="allow_file_upload" value="1" <?= $form['allow_file_upload'] === '1'
                                                ? 'checked'
                                                : ''; ?>>

                                <span>
                                    Allow file upload
                                </span>
                            </label>

                        </fieldset>


                        <div class="forum-admin-form-grid">

                            <div class="form-group">
                                <label for="points-possible">
                                    Points Possible
                                </label>

                                <input class="form-control" type="number" id="points-possible" name="points_possible"
                                    min="0.01" step="0.01" value="<?= e($form['points_possible']); ?>" required>
                            </div>


                            <div class="form-group">
                                <label for="assignment-status">
                                    Status
                                </label>

                                <select class="form-control" id="assignment-status" name="status">
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

                                    <?php if ($canPublishAssignments): ?>
                                    <option value="published" <?= $form['status'] === 'published'
                                                        ? 'selected'
                                                        : ''; ?>>
                                        Published
                                    </option>
                                    <?php endif; ?>
                                </select>
                            </div>

                        </div>


                        <div class="forum-admin-form-grid">

                            <div class="form-group">
                                <label for="due-date">
                                    Due Date
                                </label>

                                <input class="form-control" type="datetime-local" id="due-date" name="due_date"
                                    value="<?= e($form['due_date']); ?>">
                            </div>


                            <div class="form-group">
                                <label for="submission-close-date">
                                    Submission Close Date
                                </label>

                                <input class="form-control" type="datetime-local" id="submission-close-date"
                                    name="submission_close_date" value="<?= e(
                                                $form[
                                                    'submission_close_date'
                                                ]
                                            ); ?>">
                            </div>

                        </div>


                        <fieldset class="forum-admin-fieldset">

                            <legend>
                                Submission Rules
                            </legend>

                            <label class="forum-admin-choice">
                                <input type="checkbox" name="allow_resubmissions" value="1" <?= $form['allow_resubmissions'] === '1'
                                                ? 'checked'
                                                : ''; ?>>

                                <span>
                                    Allow resubmissions
                                </span>
                            </label>

                            <div class="form-group">
                                <label for="max-submissions">
                                    Maximum Submissions
                                </label>

                                <input class="form-control" type="number" id="max-submissions" name="max_submissions"
                                    min="1" step="1" value="<?= e($form['max_submissions']); ?>">
                            </div>

                            <label class="forum-admin-choice">
                                <input type="checkbox" name="allow_late_submissions" value="1" <?= $form['allow_late_submissions'] === '1'
                                                ? 'checked'
                                                : ''; ?>>

                                <span>
                                    Allow late submissions
                                </span>
                            </label>

                        </fieldset>


                        <fieldset class="forum-admin-fieldset">

                            <legend>
                                Extra Credit
                            </legend>

                            <label class="forum-admin-choice">
                                <input type="checkbox" name="allow_extra_credit" value="1" <?= $form['allow_extra_credit'] === '1'
                                                ? 'checked'
                                                : ''; ?>>

                                <span>
                                    Allow extra credit
                                </span>
                            </label>

                            <div class="form-group">
                                <label for="extra-credit-max-points">
                                    Maximum Extra-Credit Points
                                </label>

                                <input class="form-control" type="number" id="extra-credit-max-points"
                                    name="extra_credit_max_points" min="0" step="0.01" value="<?= e(
                                                $form[
                                                    'extra_credit_max_points'
                                                ]
                                            ); ?>">
                            </div>

                            <p class="form-help">
                                This enables extra credit for the offering.
                                Individual extra-credit tasks will be managed
                                separately after the assignment exists.
                            </p>

                        </fieldset>


                        <div class="forum-admin-actions">
                            <button type="submit" class="button button-primary">
                                Create Assignment
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
                            Choose a course offering above to view or create
                            assignments for that exact course run.
                        </p>
                    </div>

                </section>

                <?php endif; ?>

            </div>

        </div>
    </section>

</main>

<?php

require INCLUDES_PATH . '/footer.php';
