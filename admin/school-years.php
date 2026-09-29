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

$canManageSchoolYears =
    $isProtectedSuperAdmin
    || user_can('enrollments.years.manage');

if (
    !$hasStaffIdentity
    || !$canManageSchoolYears
) {
    http_response_code(403);

    $pageTitle =
        'Access Denied | Blackthorne Academy';

    $pageDescription =
        'You do not have permission to manage school years.';

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
                School Years.
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

function school_years_post_string(
    string $key
): string {
    $value =
        $_POST[$key]
        ?? '';

    return is_scalar($value)
        ? trim((string) $value)
        : '';
}


function school_years_post_id(
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


function school_years_post_bool(
    string $key
): int {
    return isset($_POST[$key])
        ? 1
        : 0;
}


function school_years_normalize_date(
    string $value
): ?string {
    $value =
        trim($value);

    if ($value === '') {
        return null;
    }

    $date =
        DateTime::createFromFormat(
            'Y-m-d',
            $value
        );

    if (
        !$date
        || $date->format('Y-m-d')
            !== $value
    ) {
        return null;
    }

    return $value;
}


function school_years_normalize_datetime(
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


function school_years_form_datetime(
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


function school_years_format_date(
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


function school_years_audit(
    PDO $pdo,
    int $actorUserId,
    int $schoolYearId,
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
                'school_year',

            'entity_id' =>
                $schoolYearId,

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
            'School Year audit error: '
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
    'start_date' => '',
    'end_date' => '',
    'finals_end_date' => '',
    'course_access_ends_at' => '',
    'promotion_window_start' => '',
    'is_current' => '0',
    'is_active' => '1',
];

$editSchoolYearId =
    filter_input(
        INPUT_GET,
        'edit',
        FILTER_VALIDATE_INT
    );

if (
    !is_int($editSchoolYearId)
    || $editSchoolYearId <= 0
) {
    $editSchoolYearId = 0;
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
        school_years_post_string(
            'action'
        );

    /*
    |--------------------------------------------------------------------------
    | Create
    |--------------------------------------------------------------------------
    */

    if (
        $errors === []
        && $action === 'create_school_year'
    ) {
        $createForm['name'] =
            school_years_post_string(
                'name'
            );

        $createForm['start_date'] =
            school_years_post_string(
                'start_date'
            );

        $createForm['end_date'] =
            school_years_post_string(
                'end_date'
            );

        $createForm['finals_end_date'] =
            school_years_post_string(
                'finals_end_date'
            );

        $createForm['course_access_ends_at'] =
            school_years_post_string(
                'course_access_ends_at'
            );

        $createForm['promotion_window_start'] =
            school_years_post_string(
                'promotion_window_start'
            );

        $createForm['is_current'] =
            (string) school_years_post_bool(
                'is_current'
            );

        $createForm['is_active'] =
            (string) school_years_post_bool(
                'is_active'
            );

        $name =
            $createForm['name'];

        $startDate =
            school_years_normalize_date(
                $createForm['start_date']
            );

        $endDate =
            school_years_normalize_date(
                $createForm['end_date']
            );

        $finalsEndDate =
            school_years_normalize_date(
                $createForm['finals_end_date']
            );

        $promotionWindowStart =
            school_years_normalize_date(
                $createForm[
                    'promotion_window_start'
                ]
            );

        $courseAccessEndsAt =
            school_years_normalize_datetime(
                $createForm[
                    'course_access_ends_at'
                ]
            );

        if ($name === '') {
            $errors[] =
                'School Year Name is required.';
        }

        if (
            strlen($name) > 20
        ) {
            $errors[] =
                'School Year Name must be 20 characters or fewer.';
        }

        if ($startDate === null) {
            $errors[] =
                'Start Date is required and must be valid.';
        }

        if ($endDate === null) {
            $errors[] =
                'End Date is required and must be valid.';
        }

        if (
            $startDate !== null
            && $endDate !== null
            && $endDate < $startDate
        ) {
            $errors[] =
                'End Date cannot be earlier than Start Date.';
        }

        if (
            $createForm['finals_end_date'] !== ''
            && $finalsEndDate === null
        ) {
            $errors[] =
                'Finals End Date is invalid.';
        }

        if (
            $finalsEndDate !== null
            && $endDate !== null
            && $finalsEndDate < $endDate
        ) {
            $errors[] =
                'Finals End Date cannot be earlier than the School Year End Date.';
        }

        if (
            $createForm[
                'promotion_window_start'
            ] !== ''
            && $promotionWindowStart === null
        ) {
            $errors[] =
                'Promotion Window Start is invalid.';
        }

        if (
            $createForm[
                'course_access_ends_at'
            ] !== ''
            && $courseAccessEndsAt === null
        ) {
            $errors[] =
                'Course Access Ends date/time is invalid.';
        }

        if ($errors === []) {
            try {
                $pdo->beginTransaction();

                $duplicateStatement =
                    $pdo->prepare(
                        '
                        SELECT id
                        FROM school_years
                        WHERE name = :name
                        LIMIT 1
                        '
                    );

                $duplicateStatement->execute([
                    'name' =>
                        $name,
                ]);

                if (
                    $duplicateStatement->fetchColumn()
                    !== false
                ) {
                    throw new RuntimeException(
                        'A School Year with that name already exists.'
                    );
                }

                $isCurrent =
                    (int) $createForm[
                        'is_current'
                    ];

                if ($isCurrent === 1) {
                    $pdo->exec(
                        '
                        UPDATE school_years
                        SET is_current = 0
                        '
                    );
                }

                $insertStatement =
                    $pdo->prepare(
                        '
                        INSERT INTO school_years (
                            name,
                            start_date,
                            end_date,
                            finals_end_date,
                            course_access_ends_at,
                            promotion_window_start,
                            is_current,
                            is_active,
                            is_finalized
                        ) VALUES (
                            :name,
                            :start_date,
                            :end_date,
                            :finals_end_date,
                            :course_access_ends_at,
                            :promotion_window_start,
                            :is_current,
                            :is_active,
                            0
                        )
                        '
                    );

                $insertStatement->execute([
                    'name' =>
                        $name,

                    'start_date' =>
                        $startDate,

                    'end_date' =>
                        $endDate,

                    'finals_end_date' =>
                        $finalsEndDate,

                    'course_access_ends_at' =>
                        $courseAccessEndsAt,

                    'promotion_window_start' =>
                        $promotionWindowStart,

                    'is_current' =>
                        $isCurrent,

                    'is_active' =>
                        (int) $createForm[
                            'is_active'
                        ],
                ]);

                $schoolYearId =
                    (int) $pdo->lastInsertId();

                school_years_audit(
                    $pdo,
                    $currentUserId,
                    $schoolYearId,
                    'school_year.create',
                    'Created School Year: '
                    . $name
                );

                $pdo->commit();

                set_flash(
                    'success',
                    'School Year created successfully.'
                );

                redirect(
                    url(
                        'admin/school-years.php'
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
                        'School Year create error: '
                        . $exception->getMessage()
                    );

                    $errors[] =
                        'The School Year could not be created.';
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
        && $action === 'update_school_year'
    ) {
        $schoolYearId =
            school_years_post_id(
                'school_year_id'
            );

        $name =
            school_years_post_string(
                'name'
            );

        $startDateRaw =
            school_years_post_string(
                'start_date'
            );

        $endDateRaw =
            school_years_post_string(
                'end_date'
            );

        $finalsEndDateRaw =
            school_years_post_string(
                'finals_end_date'
            );

        $courseAccessEndsAtRaw =
            school_years_post_string(
                'course_access_ends_at'
            );

        $promotionWindowStartRaw =
            school_years_post_string(
                'promotion_window_start'
            );

        $finalizationNotes =
            school_years_post_string(
                'finalization_notes'
            );

        $isCurrent =
            school_years_post_bool(
                'is_current'
            );

        $isActive =
            school_years_post_bool(
                'is_active'
            );

        $isFinalized =
            school_years_post_bool(
                'is_finalized'
            );

        $startDate =
            school_years_normalize_date(
                $startDateRaw
            );

        $endDate =
            school_years_normalize_date(
                $endDateRaw
            );

        $finalsEndDate =
            school_years_normalize_date(
                $finalsEndDateRaw
            );

        $courseAccessEndsAt =
            school_years_normalize_datetime(
                $courseAccessEndsAtRaw
            );

        $promotionWindowStart =
            school_years_normalize_date(
                $promotionWindowStartRaw
            );

        if ($schoolYearId <= 0) {
            $errors[] =
                'The School Year could not be identified.';
        }

        if ($name === '') {
            $errors[] =
                'School Year Name is required.';
        }

        if (strlen($name) > 20) {
            $errors[] =
                'School Year Name must be 20 characters or fewer.';
        }

        if ($startDate === null) {
            $errors[] =
                'Start Date is required and must be valid.';
        }

        if ($endDate === null) {
            $errors[] =
                'End Date is required and must be valid.';
        }

        if (
            $startDate !== null
            && $endDate !== null
            && $endDate < $startDate
        ) {
            $errors[] =
                'End Date cannot be earlier than Start Date.';
        }

        if (
            $finalsEndDateRaw !== ''
            && $finalsEndDate === null
        ) {
            $errors[] =
                'Finals End Date is invalid.';
        }

        if (
            $finalsEndDate !== null
            && $endDate !== null
            && $finalsEndDate < $endDate
        ) {
            $errors[] =
                'Finals End Date cannot be earlier than the School Year End Date.';
        }

        if (
            $courseAccessEndsAtRaw !== ''
            && $courseAccessEndsAt === null
        ) {
            $errors[] =
                'Course Access Ends date/time is invalid.';
        }

        if (
            $promotionWindowStartRaw !== ''
            && $promotionWindowStart === null
        ) {
            $errors[] =
                'Promotion Window Start is invalid.';
        }

        if (
            $isCurrent === 1
            && $isActive !== 1
        ) {
            $errors[] =
                'The current School Year must also be active.';
        }

        if ($errors === []) {
            try {
                $pdo->beginTransaction();

                $existingStatement =
                    $pdo->prepare(
                        '
                        SELECT
                            id,
                            is_finalized

                        FROM school_years

                        WHERE id = :id

                        LIMIT 1
                        '
                    );

                $existingStatement->execute([
                    'id' =>
                        $schoolYearId,
                ]);

                $existing =
                    $existingStatement->fetch(
                        PDO::FETCH_ASSOC
                    );

                if (!is_array($existing)) {
                    throw new RuntimeException(
                        'The School Year no longer exists.'
                    );
                }

                $duplicateStatement =
                    $pdo->prepare(
                        '
                        SELECT id

                        FROM school_years

                        WHERE name = :name
                          AND id <> :id

                        LIMIT 1
                        '
                    );

                $duplicateStatement->execute([
                    'name' =>
                        $name,

                    'id' =>
                        $schoolYearId,
                ]);

                if (
                    $duplicateStatement->fetchColumn()
                    !== false
                ) {
                    throw new RuntimeException(
                        'A School Year with that name already exists.'
                    );
                }

                if ($isCurrent === 1) {
                    $clearCurrentStatement =
                        $pdo->prepare(
                            '
                            UPDATE school_years

                            SET is_current = 0

                            WHERE id <> :id
                            '
                        );

                    $clearCurrentStatement->execute([
                        'id' =>
                            $schoolYearId,
                    ]);
                }

                $wasFinalized =
                    (int) (
                        $existing[
                            'is_finalized'
                        ]
                        ?? 0
                    );

                $finalizedAt = null;
                $finalizedBy = null;

                if ($isFinalized === 1) {
                    if ($wasFinalized === 1) {
                        $finalizedDataStatement =
                            $pdo->prepare(
                                '
                                SELECT
                                    finalized_at,
                                    finalized_by

                                FROM school_years

                                WHERE id = :id

                                LIMIT 1
                                '
                            );

                        $finalizedDataStatement->execute([
                            'id' =>
                                $schoolYearId,
                        ]);

                        $finalizedData =
                            $finalizedDataStatement->fetch(
                                PDO::FETCH_ASSOC
                            );

                        $finalizedAt =
                            $finalizedData[
                                'finalized_at'
                            ]
                            ?? date(
                                'Y-m-d H:i:s'
                            );

                        $finalizedBy =
                            $finalizedData[
                                'finalized_by'
                            ]
                            ?? $currentUserId;
                    } else {
                        $finalizedAt =
                            date(
                                'Y-m-d H:i:s'
                            );

                        $finalizedBy =
                            $currentUserId > 0
                                ? $currentUserId
                                : null;
                    }
                }

                $updateStatement =
                    $pdo->prepare(
                        '
                        UPDATE school_years

                        SET
                            name = :name,
                            start_date = :start_date,
                            end_date = :end_date,
                            finals_end_date = :finals_end_date,
                            course_access_ends_at = :course_access_ends_at,
                            promotion_window_start = :promotion_window_start,
                            is_current = :is_current,
                            is_active = :is_active,
                            is_finalized = :is_finalized,
                            finalized_at = :finalized_at,
                            finalized_by = :finalized_by,
                            finalization_notes = :finalization_notes

                        WHERE id = :id
                        '
                    );

                $updateStatement->execute([
                    'name' =>
                        $name,

                    'start_date' =>
                        $startDate,

                    'end_date' =>
                        $endDate,

                    'finals_end_date' =>
                        $finalsEndDate,

                    'course_access_ends_at' =>
                        $courseAccessEndsAt,

                    'promotion_window_start' =>
                        $promotionWindowStart,

                    'is_current' =>
                        $isCurrent,

                    'is_active' =>
                        $isActive,

                    'is_finalized' =>
                        $isFinalized,

                    'finalized_at' =>
                        $finalizedAt,

                    'finalized_by' =>
                        $finalizedBy,

                    'finalization_notes' =>
                        $finalizationNotes !== ''
                            ? $finalizationNotes
                            : null,

                    'id' =>
                        $schoolYearId,
                ]);

                school_years_audit(
                    $pdo,
                    $currentUserId,
                    $schoolYearId,
                    'school_year.update',
                    'Updated School Year: '
                    . $name
                );

                $pdo->commit();

                set_flash(
                    'success',
                    'School Year updated successfully.'
                );

                redirect(
                    url(
                        'admin/school-years.php'
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
                        'School Year update error: '
                        . $exception->getMessage()
                    );

                    $errors[] =
                        'The School Year could not be updated.';
                }
            }
        }

        $editSchoolYearId =
            $schoolYearId;
    }
}


/*
|--------------------------------------------------------------------------
| Load School Years
|--------------------------------------------------------------------------
*/

$schoolYears =
    $pdo->query(
        '
        SELECT
            sy.id,
            sy.name,
            sy.start_date,
            sy.end_date,
            sy.finals_end_date,
            sy.course_access_ends_at,
            sy.promotion_window_start,
            sy.is_current,
            sy.is_active,
            sy.is_finalized,
            sy.finalized_at,
            sy.finalized_by,
            sy.finalization_notes,
            sy.created_at,
            sy.updated_at,
            u.display_name AS finalized_by_display_name,
            u.username AS finalized_by_username

        FROM school_years sy

        LEFT JOIN users u
            ON u.id = sy.finalized_by

        ORDER BY
            sy.is_current DESC,
            sy.start_date DESC,
            sy.id DESC
        '
    )->fetchAll(
        PDO::FETCH_ASSOC
    );

$editSchoolYear = null;

if ($editSchoolYearId > 0) {
    foreach ($schoolYears as $schoolYearRow) {
        if (
            (int) (
                $schoolYearRow['id']
                ?? 0
            ) === $editSchoolYearId
        ) {
            $editSchoolYear =
                $schoolYearRow;

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
    'total' => count($schoolYears),
    'active' => 0,
    'finalized' => 0,
    'current_name' => 'None',
];

foreach ($schoolYears as $schoolYearRow) {
    if (
        (int) (
            $schoolYearRow['is_active']
            ?? 0
        ) === 1
    ) {
        $summary['active']++;
    }

    if (
        (int) (
            $schoolYearRow['is_finalized']
            ?? 0
        ) === 1
    ) {
        $summary['finalized']++;
    }

    if (
        (int) (
            $schoolYearRow['is_current']
            ?? 0
        ) === 1
    ) {
        $summary['current_name'] =
            (string) (
                $schoolYearRow['name']
                ?? 'Current'
            );
    }
}


/*
|--------------------------------------------------------------------------
| Shared Courses Sidebar
|--------------------------------------------------------------------------
*/

$courseSidebarActive = 'school-years';

require INCLUDES_PATH . '/staff-course-sidebar.php';


/*
|--------------------------------------------------------------------------
| SEO / Header
|--------------------------------------------------------------------------
*/

$pageTitle =
    'School Years | Blackthorne Academy';

$pageDescription =
    'Manage Blackthorne Academy school years.';

$pageCanonical =
    url('admin/school-years.php');

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
    class="dashboard-page staff-dashboard-page dashboard-workspace-page courses-dashboard-page school-years-page">

    <section class="dashboard-hero staff-dashboard-hero" aria-labelledby="school-years-heading"
        <?php if ($staffHeroUrl !== ''): ?> style="--staff-dashboard-hero-image: url('<?= e($staffHeroUrl); ?>');"
        <?php endif; ?>>
        <div class="section-inner">
            <div class="dashboard-hero-inner">

                <p class="academy-overline">
                    Academic Administration
                </p>

                <h1 id="school-years-heading">
                    School Years
                </h1>

                <p class="dashboard-hero-copy">
                    Define Blackthorne Academy's academic calendar and control
                    which School Year is currently active.
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
                            Academic Calendar
                        </p>

                        <h2>
                            School Year Management
                        </h2>

                        <p>
                            Course offerings and registration groups pull their
                            School Year choices directly from these records.
                            Only one School Year can be marked Current at a time.
                        </p>
                    </div>
                </header>


                <?php if ($errors !== []): ?>

                <div class="form-message form-message-error" role="alert">
                    <strong>
                        The School Year could not be saved.
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
                            School Years
                        </span>

                        <strong>
                            <?= number_format($summary['total']); ?>
                        </strong>

                        <p>
                            Total records
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
                            Available for academic use
                        </p>
                    </article>

                    <article class="dashboard-summary-card">
                        <span class="dashboard-summary-kicker">
                            Finalized
                        </span>

                        <strong>
                            <?= number_format($summary['finalized']); ?>
                        </strong>

                        <p>
                            Closed academic years
                        </p>
                    </article>

                    <article class="dashboard-summary-card">
                        <span class="dashboard-summary-kicker">
                            Current
                        </span>

                        <strong style="font-size: 1.2rem;">
                            <?= e($summary['current_name']); ?>
                        </strong>

                        <p>
                            Current School Year
                        </p>
                    </article>

                </div>


                <?php if ($editSchoolYear !== null): ?>

                <section class="forum-admin-panel">

                    <header class="forum-admin-titlebar">
                        <p class="forum-admin-step">
                            Edit School Year
                        </p>

                        <h2>
                            <?= e((string) $editSchoolYear['name']); ?>
                        </h2>
                    </header>

                    <form action="<?= e(
                                url(
                                    'admin/school-years.php?edit='
                                    . (int) $editSchoolYear['id']
                                )
                            ); ?>" method="post" class="forum-admin-form">
                        <?= csrf_field(); ?>

                        <input type="hidden" name="action" value="update_school_year">

                        <input type="hidden" name="school_year_id" value="<?= (int) $editSchoolYear['id']; ?>">


                        <div class="form-group">
                            <label for="edit-school-year-name">
                                School Year Name
                            </label>

                            <input class="form-control" type="text" id="edit-school-year-name" name="name"
                                maxlength="20" value="<?= e(
                                        (string) (
                                            $_POST['action']
                                                ?? ''
                                        ) === 'update_school_year'
                                            ? school_years_post_string('name')
                                            : (string) $editSchoolYear['name']
                                    ); ?>" required>

                            <p class="form-help">
                                Example: 2026–2027.
                            </p>
                        </div>


                        <div class="forum-admin-form-grid">

                            <div class="form-group">
                                <label for="edit-start-date">
                                    Start Date
                                </label>

                                <input class="form-control" type="date" id="edit-start-date" name="start_date" value="<?= e(
                                            (string) (
                                                $_POST['action']
                                                ?? ''
                                            ) === 'update_school_year'
                                                ? school_years_post_string('start_date')
                                                : (string) $editSchoolYear['start_date']
                                        ); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="edit-end-date">
                                    End Date
                                </label>

                                <input class="form-control" type="date" id="edit-end-date" name="end_date" value="<?= e(
                                            (string) (
                                                $_POST['action']
                                                ?? ''
                                            ) === 'update_school_year'
                                                ? school_years_post_string('end_date')
                                                : (string) $editSchoolYear['end_date']
                                        ); ?>" required>
                            </div>

                        </div>


                        <div class="forum-admin-form-grid">

                            <div class="form-group">
                                <label for="edit-finals-end-date">
                                    Finals End Date
                                </label>

                                <input class="form-control" type="date" id="edit-finals-end-date" name="finals_end_date"
                                    value="<?= e(
                                            (string) (
                                                $_POST['action']
                                                ?? ''
                                            ) === 'update_school_year'
                                                ? school_years_post_string('finals_end_date')
                                                : (string) (
                                                    $editSchoolYear['finals_end_date']
                                                    ?? ''
                                                )
                                        ); ?>">
                            </div>

                            <div class="form-group">
                                <label for="edit-promotion-window">
                                    Promotion Window Start
                                </label>

                                <input class="form-control" type="date" id="edit-promotion-window"
                                    name="promotion_window_start" value="<?= e(
                                            (string) (
                                                $_POST['action']
                                                ?? ''
                                            ) === 'update_school_year'
                                                ? school_years_post_string('promotion_window_start')
                                                : (string) (
                                                    $editSchoolYear['promotion_window_start']
                                                    ?? ''
                                                )
                                        ); ?>">
                            </div>

                        </div>


                        <div class="form-group">
                            <label for="edit-course-access-ends">
                                Course Access Ends
                            </label>

                            <input class="form-control" type="datetime-local" id="edit-course-access-ends"
                                name="course_access_ends_at" value="<?= e(
                                        (string) (
                                            $_POST['action']
                                                ?? ''
                                        ) === 'update_school_year'
                                            ? school_years_post_string('course_access_ends_at')
                                            : school_years_form_datetime(
                                                $editSchoolYear[
                                                    'course_access_ends_at'
                                                ]
                                                ?? null
                                            )
                                    ); ?>">

                            <p class="form-help">
                                Optional date/time when students lose access
                                to courses from this School Year.
                            </p>
                        </div>


                        <fieldset class="forum-admin-fieldset">
                            <legend>
                                School Year State
                            </legend>

                            <label class="forum-admin-choice">
                                <input type="checkbox" name="is_active" value="1" <?= (
                                            (
                                                $_POST['action']
                                                ?? ''
                                            ) === 'update_school_year'
                                                ? isset($_POST['is_active'])
                                                : (int) (
                                                    $editSchoolYear['is_active']
                                                    ?? 0
                                                ) === 1
                                        )
                                            ? 'checked'
                                            : ''; ?>>

                                <span>
                                    Active
                                </span>
                            </label>

                            <label class="forum-admin-choice">
                                <input type="checkbox" name="is_current" value="1" <?= (
                                            (
                                                $_POST['action']
                                                ?? ''
                                            ) === 'update_school_year'
                                                ? isset($_POST['is_current'])
                                                : (int) (
                                                    $editSchoolYear['is_current']
                                                    ?? 0
                                                ) === 1
                                        )
                                            ? 'checked'
                                            : ''; ?>>

                                <span>
                                    Current School Year
                                </span>
                            </label>

                            <label class="forum-admin-choice">
                                <input type="checkbox" name="is_finalized" value="1" <?= (
                                            (
                                                $_POST['action']
                                                ?? ''
                                            ) === 'update_school_year'
                                                ? isset($_POST['is_finalized'])
                                                : (int) (
                                                    $editSchoolYear['is_finalized']
                                                    ?? 0
                                                ) === 1
                                        )
                                            ? 'checked'
                                            : ''; ?>>

                                <span>
                                    Finalized
                                </span>
                            </label>

                            <p class="form-help">
                                Finalized marks the academic year as closed.
                                It does not delete the year or its historical
                                course/enrollment records.
                            </p>
                        </fieldset>


                        <div class="form-group">
                            <label for="edit-finalization-notes">
                                Finalization Notes
                            </label>

                            <textarea class="form-control" id="edit-finalization-notes" name="finalization_notes"
                                rows="4"><?= e(
                                    (string) (
                                        $_POST['action']
                                            ?? ''
                                    ) === 'update_school_year'
                                        ? school_years_post_string('finalization_notes')
                                        : (string) (
                                            $editSchoolYear['finalization_notes']
                                            ?? ''
                                        )
                                ); ?></textarea>
                        </div>


                        <div class="forum-admin-actions">

                            <a class="button button-secondary" href="<?= e(url('admin/school-years.php')); ?>">
                                Cancel
                            </a>

                            <button type="submit" class="button button-primary">
                                Save School Year
                            </button>

                        </div>

                    </form>

                </section>

                <?php else: ?>

                <section class="forum-admin-panel">

                    <header class="forum-admin-titlebar">
                        <p class="forum-admin-step">
                            New School Year
                        </p>

                        <h2>
                            Create School Year
                        </h2>
                    </header>

                    <form action="<?= e(url('admin/school-years.php')); ?>" method="post" class="forum-admin-form">
                        <?= csrf_field(); ?>

                        <input type="hidden" name="action" value="create_school_year">


                        <div class="form-group">
                            <label for="school-year-name">
                                School Year Name
                            </label>

                            <input class="form-control" type="text" id="school-year-name" name="name" maxlength="20"
                                value="<?= e($createForm['name']); ?>" placeholder="2026–2027" required>
                        </div>


                        <div class="forum-admin-form-grid">

                            <div class="form-group">
                                <label for="start-date">
                                    Start Date
                                </label>

                                <input class="form-control" type="date" id="start-date" name="start_date"
                                    value="<?= e($createForm['start_date']); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="end-date">
                                    End Date
                                </label>

                                <input class="form-control" type="date" id="end-date" name="end_date"
                                    value="<?= e($createForm['end_date']); ?>" required>
                            </div>

                        </div>


                        <div class="forum-admin-form-grid">

                            <div class="form-group">
                                <label for="finals-end-date">
                                    Finals End Date
                                </label>

                                <input class="form-control" type="date" id="finals-end-date" name="finals_end_date"
                                    value="<?= e($createForm['finals_end_date']); ?>">
                            </div>

                            <div class="form-group">
                                <label for="promotion-window-start">
                                    Promotion Window Start
                                </label>

                                <input class="form-control" type="date" id="promotion-window-start"
                                    name="promotion_window_start"
                                    value="<?= e($createForm['promotion_window_start']); ?>">
                            </div>

                        </div>


                        <div class="form-group">
                            <label for="course-access-ends-at">
                                Course Access Ends
                            </label>

                            <input class="form-control" type="datetime-local" id="course-access-ends-at"
                                name="course_access_ends_at" value="<?= e($createForm['course_access_ends_at']); ?>">
                        </div>


                        <fieldset class="forum-admin-fieldset">
                            <legend>
                                School Year State
                            </legend>

                            <label class="forum-admin-choice">
                                <input type="checkbox" name="is_active" value="1" <?= $createForm['is_active'] === '1'
                                            ? 'checked'
                                            : ''; ?>>

                                <span>
                                    Active
                                </span>
                            </label>

                            <label class="forum-admin-choice">
                                <input type="checkbox" name="is_current" value="1" <?= $createForm['is_current'] === '1'
                                            ? 'checked'
                                            : ''; ?>>

                                <span>
                                    Make this the Current School Year
                                </span>
                            </label>

                            <p class="form-help">
                                Marking this Current automatically removes
                                Current status from every other School Year.
                            </p>
                        </fieldset>


                        <div class="forum-admin-actions">
                            <button type="submit" class="button button-primary">
                                Create School Year
                            </button>
                        </div>

                    </form>

                </section>

                <?php endif; ?>


                <section class="dashboard-workspace-panel">

                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">
                                Academic Calendar
                            </p>

                            <h3>
                                Existing School Years
                            </h3>
                        </div>
                    </div>

                    <div class="dashboard-panel-body">

                        <?php if ($schoolYears === []): ?>

                        <p>
                            No School Years have been created yet.
                        </p>

                        <?php else: ?>

                        <div class="dashboard-placeholder-list">

                            <?php foreach ($schoolYears as $schoolYearRow): ?>
                            <?php
                                    $finalizedBy =
                                        trim(
                                            (string) (
                                                $schoolYearRow[
                                                    'finalized_by_display_name'
                                                ]
                                                ?? $schoolYearRow[
                                                    'finalized_by_username'
                                                ]
                                                ?? ''
                                            )
                                        );
                                    ?>

                            <span>

                                <strong>
                                    <?= e((string) $schoolYearRow['name']); ?>
                                </strong>

                                · <?= e(
                                            school_years_format_date(
                                                $schoolYearRow['start_date']
                                                ?? null
                                            )
                                        ); ?>

                                to

                                <?= e(
                                            school_years_format_date(
                                                $schoolYearRow['end_date']
                                                ?? null
                                            )
                                        ); ?>

                                <?php if (
                                            (int) (
                                                $schoolYearRow['is_current']
                                                ?? 0
                                            ) === 1
                                        ): ?>
                                · Current
                                <?php endif; ?>

                                · <?= (int) (
                                            $schoolYearRow['is_active']
                                            ?? 0
                                        ) === 1
                                            ? 'Active'
                                            : 'Inactive'; ?>

                                <?php if (
                                            (int) (
                                                $schoolYearRow['is_finalized']
                                                ?? 0
                                            ) === 1
                                        ): ?>
                                · Finalized

                                <?php if ($finalizedBy !== ''): ?>
                                by <?= e($finalizedBy); ?>
                                <?php endif; ?>
                                <?php endif; ?>

                                ·
                                <a href="<?= e(
                                                url(
                                                    'admin/school-years.php?edit='
                                                    . (int) $schoolYearRow['id']
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
