<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

require_login();
require_active_account();

$userId = (int) (current_user_id() ?? 0);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function course_registration_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now');
}

function course_registration_format_datetime(?string $value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return '—';
    }

    $timestamp = strtotime($value);

    if ($timestamp === false) {
        return $value;
    }

    return date('M j, Y g:i A', $timestamp);
}

function course_registration_window_status(array $group, DateTimeImmutable $now): string
{
    if ((int) ($group['is_active'] ?? 0) !== 1) {
        return 'inactive';
    }

    if ((int) ($group['school_year_is_active'] ?? 0) !== 1) {
        return 'inactive';
    }

    $opens = trim((string) ($group['registration_opens_at'] ?? ''));
    $closes = trim((string) ($group['registration_closes_at'] ?? ''));

    if ($opens !== '') {
        $openAt = new DateTimeImmutable($opens);

        if ($now < $openAt) {
            return 'not_open';
        }
    }

    if ($closes !== '') {
        $closeAt = new DateTimeImmutable($closes);

        if ($now > $closeAt) {
            return 'closed';
        }
    }

    return 'open';
}

function course_registration_gateway_status(
    PDO $pdo,
    int $userId,
    ?int $gatewayCourseId
): array {
    if ($gatewayCourseId === null || $gatewayCourseId <= 0) {
        return [
            'required' => false,
            'completed' => true,
            'enrollment' => null,
            'offering' => null,
        ];
    }

    $statement = $pdo->prepare(
        '
        SELECT
            ce.id AS enrollment_id,
            ce.status AS enrollment_status,
            ce.completed_at,
            co.id AS offering_id,
            co.status AS offering_status,
            co.offering_scope,
            co.school_year_id,
            c.title AS course_title
        FROM course_enrollments ce
        INNER JOIN course_offerings co
            ON co.id = ce.offering_id
        INNER JOIN courses c
            ON c.id = co.course_id
        WHERE ce.user_id = :user_id
          AND co.course_id = :course_id
        ORDER BY
            CASE ce.status
                WHEN "completed" THEN 0
                WHEN "enrolled" THEN 1
                WHEN "suspended" THEN 2
                WHEN "withdrawn" THEN 3
                ELSE 4
            END,
            ce.enrolled_at DESC
        '
    );

    $statement->execute([
        'user_id' => $userId,
        'course_id' => $gatewayCourseId,
    ]);

    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        if ((string) ($row['enrollment_status'] ?? '') === 'completed') {
            return [
                'required' => true,
                'completed' => true,
                'enrollment' => $row,
                'offering' => null,
            ];
        }
    }

    foreach ($rows as $row) {
        if ((string) ($row['enrollment_status'] ?? '') === 'enrolled') {
            return [
                'required' => true,
                'completed' => false,
                'enrollment' => $row,
                'offering' => null,
            ];
        }
    }

    $offeringStatement = $pdo->prepare(
        '
        SELECT
            co.id AS offering_id,
            co.course_id,
            co.offering_scope,
            co.school_year_id,
            co.enrollment_open_date,
            co.enrollment_close_date,
            co.status AS offering_status,
            c.title AS course_title,
            c.status AS course_status,
            sy.is_active AS school_year_is_active,
            sy.is_current AS school_year_is_current
        FROM course_offerings co
        INNER JOIN courses c
            ON c.id = co.course_id
        LEFT JOIN school_years sy
            ON sy.id = co.school_year_id
        WHERE co.course_id = :course_id
          AND c.status = "published"
          AND co.status = "open"
          AND (
                co.enrollment_open_date IS NULL
                OR co.enrollment_open_date <= NOW()
              )
          AND (
                co.enrollment_close_date IS NULL
                OR co.enrollment_close_date >= NOW()
              )
          AND (
                co.offering_scope = "perpetual"
                OR sy.is_active = 1
              )
        ORDER BY
            CASE co.offering_scope
                WHEN "perpetual" THEN 0
                ELSE 1
            END,
            sy.is_current DESC,
            co.id DESC
        LIMIT 1
        '
    );

    $offeringStatement->execute([
        'course_id' => $gatewayCourseId,
    ]);

    $offering = $offeringStatement->fetch(PDO::FETCH_ASSOC) ?: null;

    return [
        'required' => true,
        'completed' => false,
        'enrollment' => null,
        'offering' => $offering,
    ];
}

function course_registration_load_groups(PDO $pdo, int $userId): array
{
    $statement = $pdo->prepare(
        '
        SELECT
            crg.id,
            crg.name,
            crg.slug,
            crg.school_year_id,
            crg.year_group_id,
            crg.gateway_course_id,
            crg.description,
            crg.registration_opens_at,
            crg.registration_closes_at,
            crg.is_active,
            crg.sort_order,

            sy.name AS school_year_name,
            sy.start_date AS school_year_start_date,
            sy.end_date AS school_year_end_date,
            sy.is_current AS school_year_is_current,
            sy.is_active AS school_year_is_active,

            yg.name AS year_group_name,
            yg.year_number,

            gateway.title AS gateway_course_title,

            crge.id AS group_enrollment_id,
            crge.status AS group_enrollment_status,
            crge.registered_at,

            sye.id AS year_enrollment_id,
            sye.promotion_status AS year_enrollment_status,

            (
                SELECT COUNT(*)
                FROM course_registration_group_offerings crgo
                WHERE crgo.registration_group_id = crg.id
            ) AS offering_count

        FROM course_registration_groups crg

        INNER JOIN school_years sy
            ON sy.id = crg.school_year_id

        INNER JOIN year_groups yg
            ON yg.id = crg.year_group_id

        LEFT JOIN courses gateway
            ON gateway.id = crg.gateway_course_id

        LEFT JOIN course_registration_group_enrollments crge
            ON crge.registration_group_id = crg.id
           AND crge.user_id = :group_user_id

        LEFT JOIN student_year_enrollments sye
            ON sye.user_id = :year_user_id
           AND sye.school_year_id = crg.school_year_id

        WHERE crg.is_active = 1
          AND sy.is_active = 1
          AND yg.is_active = 1

        ORDER BY
            sy.is_current DESC,
            sy.start_date ASC,
            yg.sort_order ASC,
            yg.year_number ASC,
            crg.sort_order ASC,
            crg.name ASC
        '
    );

    $statement->execute([
        'group_user_id' => $userId,
        'year_user_id' => $userId,
    ]);

    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function course_registration_group_offerings(PDO $pdo, int $groupId): array
{
    $statement = $pdo->prepare(
        '
        SELECT
            crgo.id,
            crgo.registration_group_id,
            crgo.offering_id,
            crgo.is_required,
            crgo.sort_order,

            co.course_id,
            co.school_year_id,
            co.status AS offering_status,
            co.enrollment_open_date,
            co.enrollment_close_date,

            c.title AS course_title,
            c.course_code,
            c.status AS course_status

        FROM course_registration_group_offerings crgo

        INNER JOIN course_offerings co
            ON co.id = crgo.offering_id

        INNER JOIN courses c
            ON c.id = co.course_id

        WHERE crgo.registration_group_id = :group_id

        ORDER BY
            crgo.sort_order ASC,
            c.title ASC
        '
    );

    $statement->execute([
        'group_id' => $groupId,
    ]);

    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

/*
|--------------------------------------------------------------------------
| POST Actions
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'enroll_gateway') {
        $courseId = filter_input(INPUT_POST, 'course_id', FILTER_VALIDATE_INT);
        $offeringId = filter_input(INPUT_POST, 'offering_id', FILTER_VALIDATE_INT);

        $courseId = is_int($courseId) && $courseId > 0 ? $courseId : 0;
        $offeringId = is_int($offeringId) && $offeringId > 0 ? $offeringId : 0;

        if ($courseId <= 0 || $offeringId <= 0) {
            set_flash('error', 'The Orientation enrollment request was invalid.');
            header('Location: ' . url('course-registration.php'));
            exit;
        }

        try {
            $pdo->beginTransaction();

            $offeringStatement = $pdo->prepare(
                '
                SELECT
                    co.id,
                    co.course_id,
                    co.offering_scope,
                    co.status,
                    co.enrollment_open_date,
                    co.enrollment_close_date,
                    c.status AS course_status,
                    sy.is_active AS school_year_is_active
                FROM course_offerings co
                INNER JOIN courses c
                    ON c.id = co.course_id
                LEFT JOIN school_years sy
                    ON sy.id = co.school_year_id
                WHERE co.id = :offering_id
                  AND co.course_id = :course_id
                FOR UPDATE
                '
            );

            $offeringStatement->execute([
                'offering_id' => $offeringId,
                'course_id' => $courseId,
            ]);

            $offering = $offeringStatement->fetch(PDO::FETCH_ASSOC);

            if (!$offering) {
                throw new RuntimeException('Orientation offering not found.');
            }

            $now = course_registration_now();
            $openDate = trim((string) ($offering['enrollment_open_date'] ?? ''));
            $closeDate = trim((string) ($offering['enrollment_close_date'] ?? ''));

            $available =
                (string) $offering['status'] === 'open'
                && (string) $offering['course_status'] === 'published'
                && (
                    (string) $offering['offering_scope'] === 'perpetual'
                    || (int) ($offering['school_year_is_active'] ?? 0) === 1
                )
                && (
                    $openDate === ''
                    || $now >= new DateTimeImmutable($openDate)
                )
                && (
                    $closeDate === ''
                    || $now <= new DateTimeImmutable($closeDate)
                );

            if (!$available) {
                throw new RuntimeException('Orientation enrollment is not currently available.');
            }

            $existingStatement = $pdo->prepare(
                '
                SELECT id, status
                FROM course_enrollments
                WHERE user_id = :user_id
                  AND offering_id = :offering_id
                FOR UPDATE
                '
            );

            $existingStatement->execute([
                'user_id' => $userId,
                'offering_id' => $offeringId,
            ]);

            $existing = $existingStatement->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $status = (string) ($existing['status'] ?? '');

                if ($status === 'suspended') {
                    throw new RuntimeException('This Orientation enrollment is suspended and cannot be reactivated here.');
                }

                if ($status === 'withdrawn') {
                    $reactivateStatement = $pdo->prepare(
                        '
                        UPDATE course_enrollments
                        SET
                            status = "enrolled",
                            withdrawn_at = NULL,
                            suspended_at = NULL
                        WHERE id = :id
                        '
                    );

                    $reactivateStatement->execute([
                        'id' => (int) $existing['id'],
                    ]);
                }
            } else {
                $insertStatement = $pdo->prepare(
                    '
                    INSERT INTO course_enrollments (
                        user_id,
                        offering_id,
                        status,
                        progress
                    ) VALUES (
                        :user_id,
                        :offering_id,
                        "enrolled",
                        0.00
                    )
                    '
                );

                $insertStatement->execute([
                    'user_id' => $userId,
                    'offering_id' => $offeringId,
                ]);
            }

            $pdo->commit();

            set_flash('success', 'Orientation is ready. Complete it before registering for your Academy year.');
            header('Location: ' . url('course.php?offering=' . $offeringId));
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Course registration Orientation enrollment error: ' . $exception->getMessage());

            set_flash('error', $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Orientation enrollment could not be completed.');

            header('Location: ' . url('course-registration.php'));
            exit;
        }
    }

    if ($action === 'register_group') {
        $groupId = filter_input(INPUT_POST, 'group_id', FILTER_VALIDATE_INT);
        $groupId = is_int($groupId) && $groupId > 0 ? $groupId : 0;

        if ($groupId <= 0) {
            set_flash('error', 'The registration request was invalid.');
            header('Location: ' . url('course-registration.php'));
            exit;
        }

        try {
            $pdo->beginTransaction();

            $groupStatement = $pdo->prepare(
                '
                SELECT
                    crg.*,
                    sy.is_active AS school_year_is_active,
                    yg.is_active AS year_group_is_active
                FROM course_registration_groups crg
                INNER JOIN school_years sy
                    ON sy.id = crg.school_year_id
                INNER JOIN year_groups yg
                    ON yg.id = crg.year_group_id
                WHERE crg.id = :group_id
                FOR UPDATE
                '
            );

            $groupStatement->execute([
                'group_id' => $groupId,
            ]);

            $group = $groupStatement->fetch(PDO::FETCH_ASSOC);

            if (!$group) {
                throw new RuntimeException('That registration group could not be found.');
            }

            if ((int) $group['is_active'] !== 1
                || (int) $group['school_year_is_active'] !== 1
                || (int) $group['year_group_is_active'] !== 1) {
                throw new RuntimeException('Registration is not currently available for that Academy year.');
            }

            $now = course_registration_now();
            $opens = trim((string) ($group['registration_opens_at'] ?? ''));
            $closes = trim((string) ($group['registration_closes_at'] ?? ''));

            if ($opens !== '' && $now < new DateTimeImmutable($opens)) {
                throw new RuntimeException('Registration has not opened yet.');
            }

            if ($closes !== '' && $now > new DateTimeImmutable($closes)) {
                throw new RuntimeException('Registration is closed.');
            }

            $gatewayCourseId = (int) ($group['gateway_course_id'] ?? 0);

            if ($gatewayCourseId > 0) {
                $gatewayStatement = $pdo->prepare(
                    '
                    SELECT 1
                    FROM course_enrollments ce
                    INNER JOIN course_offerings co
                        ON co.id = ce.offering_id
                    WHERE ce.user_id = :user_id
                      AND co.course_id = :course_id
                      AND ce.status = "completed"
                    LIMIT 1
                    '
                );

                $gatewayStatement->execute([
                    'user_id' => $userId,
                    'course_id' => $gatewayCourseId,
                ]);

                if (!$gatewayStatement->fetchColumn()) {
                    throw new RuntimeException('Orientation must be completed before you can register for this Academy year.');
                }
            }

            $existingGroupStatement = $pdo->prepare(
                '
                SELECT id, status
                FROM course_registration_group_enrollments
                WHERE registration_group_id = :group_id
                  AND user_id = :user_id
                FOR UPDATE
                '
            );

            $existingGroupStatement->execute([
                'group_id' => $groupId,
                'user_id' => $userId,
            ]);

            $existingGroupEnrollment = $existingGroupStatement->fetch(PDO::FETCH_ASSOC);

            if ($existingGroupEnrollment
                && in_array((string) $existingGroupEnrollment['status'], ['registered', 'active', 'completed'], true)) {
                throw new RuntimeException('You are already registered for this Academy year.');
            }

            $offeringsStatement = $pdo->prepare(
                '
                SELECT
                    crgo.offering_id,
                    crgo.is_required,
                    co.course_id,
                    co.school_year_id,
                    co.status AS offering_status,
                    co.enrollment_open_date,
                    co.enrollment_close_date,
                    c.title AS course_title,
                    c.status AS course_status
                FROM course_registration_group_offerings crgo
                INNER JOIN course_offerings co
                    ON co.id = crgo.offering_id
                INNER JOIN courses c
                    ON c.id = co.course_id
                WHERE crgo.registration_group_id = :group_id
                ORDER BY crgo.sort_order ASC, crgo.id ASC
                FOR UPDATE
                '
            );

            $offeringsStatement->execute([
                'group_id' => $groupId,
            ]);

            $offerings = $offeringsStatement->fetchAll(PDO::FETCH_ASSOC);

            if ($offerings === []) {
                throw new RuntimeException('No course offerings have been assigned to this registration group yet.');
            }

            foreach ($offerings as $offering) {
                if ((int) $offering['school_year_id'] !== (int) $group['school_year_id']) {
                    throw new RuntimeException('A course offering in this registration group does not match its school year.');
                }

                if ((string) $offering['course_status'] !== 'published'
                    || (string) $offering['offering_status'] !== 'open') {
                    throw new RuntimeException('One or more required courses are not currently open for enrollment.');
                }

                $openDate = trim((string) ($offering['enrollment_open_date'] ?? ''));
                $closeDate = trim((string) ($offering['enrollment_close_date'] ?? ''));

                if ($openDate !== '' && $now < new DateTimeImmutable($openDate)) {
                    throw new RuntimeException('One or more required courses have not opened for enrollment yet.');
                }

                if ($closeDate !== '' && $now > new DateTimeImmutable($closeDate)) {
                    throw new RuntimeException('One or more required courses are no longer open for enrollment.');
                }
            }

            $yearEnrollmentStatement = $pdo->prepare(
                '
                SELECT
                    sye.id,
                    sye.year_group_id,
                    sye.promotion_status,
                    yg.year_number AS current_year_number
                FROM student_year_enrollments sye
                INNER JOIN year_groups yg
                    ON yg.id = sye.year_group_id
                WHERE sye.user_id = :user_id
                  AND sye.school_year_id = :school_year_id
                FOR UPDATE
                '
            );

            $yearEnrollmentStatement->execute([
                'user_id' => $userId,
                'school_year_id' => (int) $group['school_year_id'],
            ]);

            $yearEnrollment = $yearEnrollmentStatement->fetch(PDO::FETCH_ASSOC);

            $ruleStatement = $pdo->prepare(
                '
                SELECT
                    required_percentage,
                    required_points
                FROM year_progression_rules
                WHERE year_group_id = :year_group_id
                  AND is_active = 1
                LIMIT 1
                '
            );

            $ruleStatement->execute([
                'year_group_id' => (int) $group['year_group_id'],
            ]);

            $progressionRule = $ruleStatement->fetch(PDO::FETCH_ASSOC);

            $requiredPercentage =
                $progressionRule !== false
                    ? (float) ($progressionRule['required_percentage'] ?? 85.00)
                    : 85.00;

            $requiredPoints =
                $progressionRule !== false
                && array_key_exists('required_points', $progressionRule)
                && $progressionRule['required_points'] !== null
                    ? (float) $progressionRule['required_points']
                    : null;

            if ($yearEnrollment) {
                $existingYearGroupId = (int) $yearEnrollment['year_group_id'];
                $targetYearGroupId = (int) $group['year_group_id'];
                $currentYearNumber = (int) ($yearEnrollment['current_year_number'] ?? -1);
                $promotionStatus = (string) ($yearEnrollment['promotion_status'] ?? '');

                if ($existingYearGroupId !== $targetYearGroupId) {
                    if ($currentYearNumber !== 0) {
                        throw new RuntimeException('You already have a different year-group enrollment for this school year.');
                    }

                    /*
                     * Year 0 is the New Student / pre-Orientation holding year.
                     * Once the gateway course has been completed, registration
                     * promotes that same school-year enrollment into the selected
                     * Academy year instead of creating a second record.
                     */
                    $promoteYearStatement = $pdo->prepare(
                        '
                        UPDATE student_year_enrollments
                        SET
                            year_group_id = :year_group_id,
                            academic_points_earned = 0.00,
                            academic_points_possible = 0.00,
                            progress_percentage = 0.00,
                            required_percentage_snapshot = :required_percentage_snapshot,
                            required_points_snapshot = :required_points_snapshot,
                            points_recalculated_at = NULL,
                            promotion_status = "active",
                            eligible_at = NULL,
                            completed_at = NULL,
                            promoted_at = NULL,
                            promotion_approved_by = NULL,
                            promotion_notes = NULL
                        WHERE id = :id
                        '
                    );

                    $promoteYearStatement->execute([
                        'year_group_id' => $targetYearGroupId,
                        'required_percentage_snapshot' => $requiredPercentage,
                        'required_points_snapshot' => $requiredPoints,
                        'id' => (int) $yearEnrollment['id'],
                    ]);
                } elseif (in_array($promotionStatus, ['withdrawn', 'completed', 'promoted'], true)) {
                    throw new RuntimeException('Your existing Academy-year record cannot be reactivated through self-registration.');
                }
            } else {
                $insertYearStatement = $pdo->prepare(
                    '
                    INSERT INTO student_year_enrollments (
                        user_id,
                        year_group_id,
                        school_year_id,
                        required_percentage_snapshot,
                        required_points_snapshot,
                        promotion_status
                    ) VALUES (
                        :user_id,
                        :year_group_id,
                        :school_year_id,
                        :required_percentage_snapshot,
                        :required_points_snapshot,
                        "active"
                    )
                    '
                );

                $insertYearStatement->execute([
                    'user_id' => $userId,
                    'year_group_id' => (int) $group['year_group_id'],
                    'school_year_id' => (int) $group['school_year_id'],
                    'required_percentage_snapshot' => $requiredPercentage,
                    'required_points_snapshot' => $requiredPoints,
                ]);
            }

            if ($existingGroupEnrollment) {
                $updateGroupStatement = $pdo->prepare(
                    '
                    UPDATE course_registration_group_enrollments
                    SET
                        status = "registered",
                        registered_at = CURRENT_TIMESTAMP,
                        completed_at = NULL,
                        withdrawn_at = NULL
                    WHERE id = :id
                    '
                );

                $updateGroupStatement->execute([
                    'id' => (int) $existingGroupEnrollment['id'],
                ]);
            } else {
                $insertGroupStatement = $pdo->prepare(
                    '
                    INSERT INTO course_registration_group_enrollments (
                        registration_group_id,
                        user_id,
                        status
                    ) VALUES (
                        :group_id,
                        :user_id,
                        "registered"
                    )
                    '
                );

                $insertGroupStatement->execute([
                    'group_id' => $groupId,
                    'user_id' => $userId,
                ]);
            }

            $existingCourseStatement = $pdo->prepare(
                '
                SELECT id, status
                FROM course_enrollments
                WHERE user_id = :user_id
                  AND offering_id = :offering_id
                FOR UPDATE
                '
            );

            $insertCourseStatement = $pdo->prepare(
                '
                INSERT INTO course_enrollments (
                    user_id,
                    offering_id,
                    status,
                    progress
                ) VALUES (
                    :user_id,
                    :offering_id,
                    "enrolled",
                    0.00
                )
                '
            );

            foreach ($offerings as $offering) {
                $offeringId = (int) $offering['offering_id'];

                $existingCourseStatement->execute([
                    'user_id' => $userId,
                    'offering_id' => $offeringId,
                ]);

                $existingCourseEnrollment = $existingCourseStatement->fetch(PDO::FETCH_ASSOC);

                if (!$existingCourseEnrollment) {
                    $insertCourseStatement->execute([
                        'user_id' => $userId,
                        'offering_id' => $offeringId,
                    ]);

                    continue;
                }

                $existingStatus = (string) ($existingCourseEnrollment['status'] ?? '');

                if ($existingStatus === 'suspended') {
                    throw new RuntimeException('One of your course enrollments is suspended. Registration cannot overwrite that status.');
                }

                if ($existingStatus === 'withdrawn') {
                    throw new RuntimeException('One of your course enrollments was previously withdrawn. A staff member must review it before re-enrollment.');
                }
            }

            $activateGroupStatement = $pdo->prepare(
                '
                UPDATE course_registration_group_enrollments
                SET status = "active"
                WHERE registration_group_id = :group_id
                  AND user_id = :user_id
                '
            );

            $activateGroupStatement->execute([
                'group_id' => $groupId,
                'user_id' => $userId,
            ]);

            $pdo->commit();

            set_flash('success', 'Registration complete. Your courses are now available.');
            header('Location: ' . url('courses.php'));
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Course registration group enrollment error: ' . $exception->getMessage());

            set_flash('error', $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Registration could not be completed. No enrollment changes were saved.');

            header('Location: ' . url('course-registration.php'));
            exit;
        }
    }

    set_flash('error', 'The requested registration action was not recognized.');
    header('Location: ' . url('course-registration.php'));
    exit;
}

/*
|--------------------------------------------------------------------------
| Load Registration Groups
|--------------------------------------------------------------------------
*/

$groups = course_registration_load_groups($pdo, $userId);
$now = course_registration_now();

foreach ($groups as &$group) {
    $group['window_status'] = course_registration_window_status($group, $now);
    $group['gateway_status'] = course_registration_gateway_status(
        $pdo,
        $userId,
        isset($group['gateway_course_id']) ? (int) $group['gateway_course_id'] : null
    );
    $group['offerings'] = course_registration_group_offerings($pdo, (int) $group['id']);
}
unset($group);

$successMessage = get_flash('success');
$errorMessage = get_flash('error');

/*
|--------------------------------------------------------------------------
| Page Metadata
|--------------------------------------------------------------------------
*/

$pageTitle = 'Course Registration | Blackthorne Academy';
$pageDescription = 'Register for available Blackthorne Academy course groups and complete prerequisite Orientation coursework.';
$pageCanonical = url('course-registration.php');
$robots = 'noindex, nofollow';

require INCLUDES_PATH . '/header.php';
?>

<main id="main-content" class="dashboard-page courses-page">
    <section class="dashboard-workspace-section">
        <div class="section-inner dashboard-workspace-layout">

            <?php require INCLUDES_PATH . '/member-sidebar.php'; ?>

            <div class="dashboard-workspace-main">
                <header class="dashboard-workspace-heading">
                    <div>
                        <p class="academy-overline">Academics</p>
                        <h1>Course Registration</h1>
                        <p>
                            Complete any required gateway course, then register for the available Academy year as a complete course group.
                        </p>
                    </div>
                </header>

                <?php if ($successMessage !== null): ?>
                    <div class="form-message success-message" role="status">
                        <?= e($successMessage); ?>
                    </div>
                <?php endif; ?>

                <?php if ($errorMessage !== null): ?>
                    <div class="form-message error-message" role="alert">
                        <?= e($errorMessage); ?>
                    </div>
                <?php endif; ?>

                <?php if ($groups === []): ?>
                    <section class="dashboard-workspace-panel">
                        <div class="dashboard-panel-titlebar">
                            <div>
                                <p class="academy-overline">Registration</p>
                                <h2>No Registration Groups Available</h2>
                            </div>
                        </div>

                        <div class="dashboard-panel-body">
                            <p>
                                There are no active Academy-year registration groups available right now.
                            </p>
                        </div>
                    </section>
                <?php else: ?>

                    <?php foreach ($groups as $group): ?>
                        <?php
                        $gateway = $group['gateway_status'];
                        $windowStatus = (string) $group['window_status'];
                        $groupEnrollmentStatus = (string) ($group['group_enrollment_status'] ?? '');
                        $alreadyRegistered = in_array(
                            $groupEnrollmentStatus,
                            ['registered', 'active', 'completed'],
                            true
                        );
                        ?>

                        <section class="dashboard-workspace-panel">
                            <div class="dashboard-panel-titlebar">
                                <div>
                                    <p class="academy-overline">
                                        <?= e((string) ($group['school_year_name'] ?? 'School Year')); ?>
                                    </p>
                                    <h2><?= e((string) $group['name']); ?></h2>
                                </div>
                            </div>

                            <div class="dashboard-panel-body">
                                <?php if (trim((string) ($group['description'] ?? '')) !== ''): ?>
                                    <p><?= nl2br(e((string) $group['description'])); ?></p>
                                <?php endif; ?>

                                <div class="dashboard-placeholder-list">
                                    <span>
                                        <strong>Year:</strong>
                                        <?= e((string) ($group['year_group_name'] ?? '—')); ?>
                                    </span>
                                    <span>
                                        <strong>Courses:</strong>
                                        <?= number_format((int) ($group['offering_count'] ?? 0)); ?>
                                    </span>
                                    <span>
                                        <strong>Registration Opens:</strong>
                                        <?= e(course_registration_format_datetime($group['registration_opens_at'] ?? null)); ?>
                                    </span>
                                    <span>
                                        <strong>Registration Closes:</strong>
                                        <?= e(course_registration_format_datetime($group['registration_closes_at'] ?? null)); ?>
                                    </span>
                                </div>

                                <?php if ($group['offerings'] !== []): ?>
                                    <h3>Included Courses</h3>
                                    <div class="dashboard-placeholder-list">
                                        <?php foreach ($group['offerings'] as $offering): ?>
                                            <span>
                                                <strong><?= e((string) $offering['course_title']); ?></strong>
                                                <?php if (trim((string) ($offering['course_code'] ?? '')) !== ''): ?>
                                                    · <?= e((string) $offering['course_code']); ?>
                                                <?php endif; ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($gateway['required']): ?>
                                    <h3>Orientation Requirement</h3>

                                    <?php if ($gateway['completed']): ?>
                                        <p>
                                            <strong>Orientation Complete.</strong>
                                            You have satisfied the gateway requirement for this registration group.
                                        </p>
                                    <?php elseif ($gateway['enrollment'] !== null): ?>
                                        <p>
                                            Orientation is required and your enrollment is already active. Complete it before registering for this Academy year.
                                        </p>

                                        <a
                                            class="button button-secondary"
                                            href="<?= e(url('course.php?offering=' . (int) $gateway['enrollment']['offering_id'])); ?>"
                                        >
                                            Continue Orientation
                                        </a>
                                    <?php elseif ($gateway['offering'] !== null): ?>
                                        <p>
                                            Orientation must be completed before registration. You can begin the available Orientation course now.
                                        </p>

                                        <form method="post" action="<?= e(url('course-registration.php')); ?>">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="action" value="enroll_gateway">
                                            <input type="hidden" name="course_id" value="<?= (int) $group['gateway_course_id']; ?>">
                                            <input type="hidden" name="offering_id" value="<?= (int) $gateway['offering']['offering_id']; ?>">

                                            <button class="button button-secondary" type="submit">
                                                Begin Orientation
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <p>
                                            Orientation is required, but there is no open Orientation offering available for enrollment right now.
                                        </p>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <h3>Registration Status</h3>

                                <?php if ($alreadyRegistered): ?>
                                    <p>
                                        <strong>Already Registered.</strong>
                                        Your Academy-year registration is already on record.
                                    </p>

                                    <a class="button button-primary" href="<?= e(url('courses.php')); ?>">
                                        View My Courses
                                    </a>
                                <?php elseif ($windowStatus === 'not_open'): ?>
                                    <p>
                                        Registration has not opened yet.
                                    </p>
                                <?php elseif ($windowStatus === 'closed'): ?>
                                    <p>
                                        Registration is closed for this Academy year.
                                    </p>
                                <?php elseif ($windowStatus !== 'open'): ?>
                                    <p>
                                        Registration is not currently available for this Academy year.
                                    </p>
                                <?php elseif (!$gateway['completed']): ?>
                                    <p>
                                        <strong>Orientation Required.</strong>
                                        Complete Orientation before the registration button becomes available.
                                    </p>
                                <?php elseif ((int) ($group['offering_count'] ?? 0) <= 0): ?>
                                    <p>
                                        This registration group does not have course offerings assigned yet.
                                    </p>
                                <?php else: ?>
                                    <p>
                                        <strong>Eligible to Register.</strong>
                                        Registering will enroll you in every course offering included in this Academy-year group.
                                    </p>

                                    <form method="post" action="<?= e(url('course-registration.php')); ?>">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="register_group">
                                        <input type="hidden" name="group_id" value="<?= (int) $group['id']; ?>">

                                        <button class="button button-primary" type="submit">
                                            Register for <?= e((string) ($group['year_group_name'] ?? 'Academy Year')); ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </section>
                    <?php endforeach; ?>

                <?php endif; ?>
            </div>
        </div>
    </section>
</main>

<?php require INCLUDES_PATH . '/footer.php'; ?>
