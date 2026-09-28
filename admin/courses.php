<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';


/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

require_login();
require_active_account();


/*
|--------------------------------------------------------------------------
| Current User
|--------------------------------------------------------------------------
*/

$user = current_user();

if ($user === null) {
    redirect(LOGIN_URL);
}

$displayName =
    trim(
        (string) (
            $user['display_name']
            ?? $user['username']
            ?? 'Staff'
        )
    );

if ($displayName === '') {
    $displayName = 'Staff';
}


/*
|--------------------------------------------------------------------------
| Course Workspace Permissions
|--------------------------------------------------------------------------
|
| Staff status identifies who belongs in staff workspaces. It does not grant
| course permissions.
|
| A user must:
|
| 1. Qualify as staff / administrator, AND
| 2. Hold at least one course-workspace permission.
|
| The protected Super Admin bypass remains intact through the global
| permission resolver and the explicit protected-account checks below.
|
*/

$courseWorkspacePermissions = [
    'courses.admin.view',
    'courses.create',
    'courses.edit',
    'courses.publish',
    'courses.archive',
    'courses.offerings.manage',
    'courses.prerequisites.manage',
    'courses.learning_paths.manage',

    'course_staff.view',
    'course_staff.assign',
    'course_staff.permissions.manage',
    'course_staff.invites.manage',
    'course_staff.notes.private.view',
    'course_staff.notes.manage',

    'lessons.create',
    'lessons.edit',
    'lessons.publish',
    'lessons.release.manage',

    'assignments.create',
    'assignments.edit',
    'assignments.publish',
    'assignments.settings.manage',
    'assignments.submissions.view',
    'assignments.grade',

    'assessments.create',
    'assessments.edit',
    'assessments.publish',
    'assessments.settings.manage',

    'quizzes.create',
    'quizzes.edit',
    'quizzes.publish',
    'quizzes.attempts.view',
    'quizzes.grade',

    'grading.assignments',
    'grading.assessments',
    'grading.regrade',
    'grading.settings.manage',

    'enrollments.courses.manage',
    'enrollments.years.manage',
    'enrollments.progression.manage',
];

$hasStaffIdentity =
    current_user_is_superuser()
    || current_user_is_admin()
    || current_user_is_staff();

$hasCourseWorkspacePermission =
    current_user_is_superuser()
    || user_can_any(
        $courseWorkspacePermissions
    );

if (
    !$hasStaffIdentity
    || !$hasCourseWorkspacePermission
) {
    http_response_code(403);

    $pageTitle =
        'Access Denied | Blackthorne Academy';

    $pageDescription =
        'You do not have permission to access the Blackthorne Academy Courses workspace.';

    $pageCanonical =
        url('staff-dashboard.php');

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
                    Your account does not have permission to access the Courses workspace.
                </p>

                <a
                    class="button button-secondary"
                    href="<?= e(url('staff-dashboard.php')); ?>"
                >
                    Return to Staff Dashboard
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
| Individual Course Capabilities
|--------------------------------------------------------------------------
*/

$canViewCourses =
    current_user_is_superuser()
    || user_can('courses.admin.view')
    || user_can_any([
        'courses.create',
        'courses.edit',
        'courses.publish',
        'courses.archive',
        'courses.offerings.manage',
        'courses.prerequisites.manage',
        'courses.learning_paths.manage',
    ]);

$canCreateCourses =
    current_user_is_superuser()
    || user_can('courses.create');

$canEditCourses =
    current_user_is_superuser()
    || user_can('courses.edit');

$canPublishCourses =
    current_user_is_superuser()
    || user_can('courses.publish');

$canArchiveCourses =
    current_user_is_superuser()
    || user_can('courses.archive');

$canManageOfferings =
    current_user_is_superuser()
    || user_can('courses.offerings.manage');

$canManagePrerequisites =
    current_user_is_superuser()
    || user_can('courses.prerequisites.manage');

$canManageLearningPaths =
    current_user_is_superuser()
    || user_can('courses.learning_paths.manage');

$canManageLessons =
    current_user_is_superuser()
    || user_can_any([
        'lessons.create',
        'lessons.edit',
        'lessons.publish',
        'lessons.release.manage',
    ]);

$canManageAssignments =
    current_user_is_superuser()
    || user_can_any([
        'assignments.create',
        'assignments.edit',
        'assignments.publish',
        'assignments.settings.manage',
        'assignments.submissions.view',
        'assignments.grade',
    ]);

$canManageAssessments =
    current_user_is_superuser()
    || user_can_any([
        'assessments.create',
        'assessments.edit',
        'assessments.publish',
        'assessments.settings.manage',
        'quizzes.create',
        'quizzes.edit',
        'quizzes.publish',
        'quizzes.attempts.view',
        'quizzes.grade',
    ]);

$canManageGrading =
    current_user_is_superuser()
    || user_can_any([
        'grading.assignments',
        'grading.assessments',
        'grading.regrade',
        'grading.settings.manage',
        'assignments.grade',
        'quizzes.grade',
    ]);

$canManageEnrollments =
    current_user_is_superuser()
    || user_can_any([
        'enrollments.courses.manage',
        'enrollments.years.manage',
        'enrollments.progression.manage',
    ]);

$canManageCourseStaff =
    current_user_is_superuser()
    || user_can_any([
        'course_staff.view',
        'course_staff.assign',
        'course_staff.permissions.manage',
        'course_staff.invites.manage',
        'course_staff.notes.private.view',
        'course_staff.notes.manage',
    ]);


/*
|--------------------------------------------------------------------------
| Workspace Statistics
|--------------------------------------------------------------------------
*/

$courseStats = [
    'total_courses' => 0,
    'published_courses' => 0,
    'draft_courses' => 0,
    'archived_courses' => 0,
    'open_offerings' => 0,
    'active_enrollments' => 0,
    'total_lessons' => 0,
];

$currentSchoolYear = null;
$recentCourses = [];
$workspaceLoadError = false;

try {
    $courseCountStatement =
        $pdo->query(
            '
            SELECT
                COUNT(*) AS total_courses,
                COALESCE(
                    SUM(status = "published"),
                    0
                ) AS published_courses,
                COALESCE(
                    SUM(status = "draft"),
                    0
                ) AS draft_courses,
                COALESCE(
                    SUM(status = "archived"),
                    0
                ) AS archived_courses

            FROM courses
            '
        );

    $courseCountRow =
        $courseCountStatement->fetch(
            PDO::FETCH_ASSOC
        );

    if (is_array($courseCountRow)) {
        $courseStats['total_courses'] =
            (int) (
                $courseCountRow['total_courses']
                ?? 0
            );

        $courseStats['published_courses'] =
            (int) (
                $courseCountRow['published_courses']
                ?? 0
            );

        $courseStats['draft_courses'] =
            (int) (
                $courseCountRow['draft_courses']
                ?? 0
            );

        $courseStats['archived_courses'] =
            (int) (
                $courseCountRow['archived_courses']
                ?? 0
            );
    }


    $currentSchoolYearStatement =
        $pdo->query(
            '
            SELECT
                id,
                name,
                start_date,
                end_date,
                finals_end_date,
                course_access_ends_at,
                is_finalized

            FROM school_years

            WHERE is_current = 1
              AND is_active = 1

            ORDER BY
                start_date DESC,
                id DESC

            LIMIT 1
            '
        );

    $currentSchoolYear =
        $currentSchoolYearStatement->fetch(
            PDO::FETCH_ASSOC
        );

    if (!is_array($currentSchoolYear)) {
        $currentSchoolYear = null;
    }


    if ($currentSchoolYear !== null) {
        $offeringCountStatement =
            $pdo->prepare(
                '
                SELECT
                    COUNT(*)

                FROM course_offerings

                WHERE school_year_id = :school_year_id
                  AND status = "open"
                '
            );

        $offeringCountStatement->execute([
            'school_year_id' =>
                (int) $currentSchoolYear['id'],
        ]);

        $courseStats['open_offerings'] =
            (int) $offeringCountStatement->fetchColumn();


        $enrollmentCountStatement =
            $pdo->prepare(
                '
                SELECT
                    COUNT(*)

                FROM course_enrollments ce

                INNER JOIN course_offerings co
                    ON co.id = ce.offering_id

                WHERE co.school_year_id = :school_year_id
                  AND ce.status = "enrolled"
                '
            );

        $enrollmentCountStatement->execute([
            'school_year_id' =>
                (int) $currentSchoolYear['id'],
        ]);

        $courseStats['active_enrollments'] =
            (int) $enrollmentCountStatement->fetchColumn();
    } else {
        $offeringCountStatement =
            $pdo->query(
                '
                SELECT
                    COUNT(*)

                FROM course_offerings

                WHERE status = "open"
                '
            );

        $courseStats['open_offerings'] =
            (int) $offeringCountStatement->fetchColumn();


        $enrollmentCountStatement =
            $pdo->query(
                '
                SELECT
                    COUNT(*)

                FROM course_enrollments

                WHERE status = "enrolled"
                '
            );

        $courseStats['active_enrollments'] =
            (int) $enrollmentCountStatement->fetchColumn();
    }


    $lessonCountStatement =
        $pdo->query(
            '
            SELECT
                COUNT(*)

            FROM lessons
            '
        );

    $courseStats['total_lessons'] =
        (int) $lessonCountStatement->fetchColumn();


    $recentCoursesStatement =
        $pdo->query(
            '
            SELECT
                id,
                title,
                slug,
                course_code,
                short_description,
                status,
                updated_at

            FROM courses

            ORDER BY
                updated_at DESC,
                id DESC

            LIMIT 6
            '
        );

    $recentCourses =
        $recentCoursesStatement->fetchAll(
            PDO::FETCH_ASSOC
        );

} catch (PDOException $exception) {
    $workspaceLoadError = true;

    error_log(
        'Blackthorne Courses dashboard load error: '
        . $exception->getMessage()
    );
}


/*
|--------------------------------------------------------------------------
| Shared Courses Sidebar
|--------------------------------------------------------------------------
*/

$courseSidebarActive = 'overview';

require INCLUDES_PATH . '/staff-course-sidebar.php';


/*
|--------------------------------------------------------------------------
| Course Permission Summary
|--------------------------------------------------------------------------
*/

$courseCapabilityLabels = [];

$courseCapabilityDefinitions = [
    [
        'allowed' => $canCreateCourses,
        'label' => 'Create Courses',
    ],
    [
        'allowed' => $canEditCourses,
        'label' => 'Edit Courses',
    ],
    [
        'allowed' => $canPublishCourses,
        'label' => 'Publish Courses',
    ],
    [
        'allowed' => $canArchiveCourses,
        'label' => 'Archive Courses',
    ],
    [
        'allowed' => $canManageOfferings,
        'label' => 'Manage Offerings',
    ],
    [
        'allowed' => $canManagePrerequisites,
        'label' => 'Manage Prerequisites',
    ],
    [
        'allowed' => $canManageLearningPaths,
        'label' => 'Manage Learning Paths',
    ],
    [
        'allowed' => $canManageLessons,
        'label' => 'Manage Lessons',
    ],
    [
        'allowed' => $canManageAssignments,
        'label' => 'Manage Assignments',
    ],
    [
        'allowed' => $canManageAssessments,
        'label' => 'Manage Assessments',
    ],
    [
        'allowed' => $canManageGrading,
        'label' => 'Manage Grading',
    ],
    [
        'allowed' => $canManageEnrollments,
        'label' => 'Manage Enrollments',
    ],
    [
        'allowed' => $canManageCourseStaff,
        'label' => 'Manage Course Staff',
    ],
];

foreach ($courseCapabilityDefinitions as $definition) {
    if ((bool) $definition['allowed']) {
        $courseCapabilityLabels[] =
            (string) $definition['label'];
    }
}


/*
|--------------------------------------------------------------------------
| Display Helpers
|--------------------------------------------------------------------------
*/

$formatCourseStatus =
    static function (string $status): string {
        return match ($status) {
            'published' => 'Published',
            'archived' => 'Archived',
            default => 'Draft',
        };
    };

$formatDashboardDate =
    static function (?string $value): string {
        $value = trim((string) $value);

        if ($value === '') {
            return '—';
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return $value;
        }

        return date(
            'M j, Y',
            $timestamp
        );
    };


/*
|--------------------------------------------------------------------------
| SEO
|--------------------------------------------------------------------------
*/

$pageTitle =
    'Courses | Staff Dashboard | Blackthorne Academy';

$pageDescription =
    'Blackthorne Academy course administration workspace.';

$pageCanonical =
    url('admin/courses.php');

$robots =
    'noindex, nofollow';


/*
|--------------------------------------------------------------------------
| Header
|--------------------------------------------------------------------------
*/

require INCLUDES_PATH . '/header.php';


/*
|--------------------------------------------------------------------------
| Hero Image
|--------------------------------------------------------------------------
|
| Keep the Courses workspace visually connected to the existing Staff
| Dashboard. A separate academic hero can be introduced later without
| changing this page structure.
|
*/

$staffHeroPngReference =
    'assets/images/staff_dashboard_hero_bg.png';

$staffHeroWebpReference =
    'assets/images/staff_dashboard_hero_bg.webp';

$projectRoot =
    dirname(__DIR__);

$staffHeroWebpPath =
    $projectRoot
    . '/'
    . $staffHeroWebpReference;

$staffHeroPngPath =
    $projectRoot
    . '/'
    . $staffHeroPngReference;

$staffHeroReference =
    is_file($staffHeroWebpPath)
        ? $staffHeroWebpReference
        : $staffHeroPngReference;

$staffHeroUrl =
    (
        is_file($staffHeroWebpPath)
        || is_file($staffHeroPngPath)
    )
        ? url($staffHeroReference)
        : '';


/*
|--------------------------------------------------------------------------
| Current School Year Copy
|--------------------------------------------------------------------------
*/

$currentSchoolYearName =
    $currentSchoolYear !== null
        ? trim(
            (string) (
                $currentSchoolYear['name']
                ?? ''
            )
        )
        : '';

if ($currentSchoolYearName === '') {
    $currentSchoolYearName =
        'No current school year';
}

?>

<main
    id="main-content"
    class="dashboard-page staff-dashboard-page dashboard-workspace-page courses-dashboard-page"
>

    <section
        class="dashboard-hero staff-dashboard-hero"
        aria-labelledby="courses-dashboard-heading"
        <?php if ($staffHeroUrl !== ''): ?>
            style="--staff-dashboard-hero-image: url('<?= e($staffHeroUrl); ?>');"
        <?php endif; ?>
    >
        <div class="section-inner">
            <div class="dashboard-hero-inner">

                <p class="academy-overline">
                    Academic Administration
                </p>

                <h1 id="courses-dashboard-heading">
                    Courses
                </h1>

                <p class="dashboard-hero-copy">
                    Manage Blackthorne Academy's course structure, offerings,
                    content, enrollment, staff, and grading from one
                    permission-aware academic workspace.
                </p>

                <div class="dashboard-status-line">
                    <span class="dashboard-status-label">
                        Academic Workspace
                    </span>

                    <span aria-hidden="true">
                        ✦
                    </span>

                    <span>
                        <?= e($currentSchoolYearName); ?>
                    </span>

                    <span aria-hidden="true">
                        ✦
                    </span>

                    <span>
                        <?= number_format(
                            count($courseCapabilityLabels)
                        ); ?>
                        Available
                        <?= count($courseCapabilityLabels) === 1
                            ? 'Capability'
                            : 'Capabilities'; ?>
                    </span>

                    <span aria-hidden="true">
                        ✦
                    </span>

                    <span>
                        <?= e($displayName); ?>
                    </span>
                </div>

            </div>
        </div>
    </section>


    <section
        class="dashboard-workspace-section"
        aria-labelledby="courses-workspace-heading"
    >
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
                            Courses Overview
                        </p>

                        <h2 id="courses-workspace-heading">
                            Academic course workspace.
                        </h2>

                        <p>
                            Use the navigation at left to move through the
                            course system. Only tools allowed by your current
                            permissions appear in this workspace.
                        </p>
                    </div>
                </header>


                <?php if ($workspaceLoadError): ?>

                    <div class="form-message form-message-error">
                        Some course dashboard information could not be loaded.
                        The error has been recorded for review.
                    </div>

                <?php endif; ?>


                <div class="dashboard-summary-grid">

                    <article class="dashboard-summary-card">
                        <span class="dashboard-summary-kicker">
                            Courses
                        </span>

                        <strong>
                            <?= number_format(
                                $courseStats['total_courses']
                            ); ?>
                        </strong>

                        <p>
                            Master course records
                        </p>
                    </article>


                    <article class="dashboard-summary-card">
                        <span class="dashboard-summary-kicker">
                            Published
                        </span>

                        <strong>
                            <?= number_format(
                                $courseStats['published_courses']
                            ); ?>
                        </strong>

                        <p>
                            Published courses
                        </p>
                    </article>


                    <article class="dashboard-summary-card">
                        <span class="dashboard-summary-kicker">
                            Open Offerings
                        </span>

                        <strong>
                            <?= number_format(
                                $courseStats['open_offerings']
                            ); ?>
                        </strong>

                        <p>
                            <?= e($currentSchoolYearName); ?>
                        </p>
                    </article>


                    <article class="dashboard-summary-card">
                        <span class="dashboard-summary-kicker">
                            Enrollments
                        </span>

                        <strong>
                            <?= number_format(
                                $courseStats['active_enrollments']
                            ); ?>
                        </strong>

                        <p>
                            Active student enrollments
                        </p>
                    </article>

                </div>


                <section class="dashboard-workspace-panel">
                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">
                                Your Access
                            </p>

                            <h3>
                                Course permissions available to you
                            </h3>
                        </div>
                    </div>

                    <div class="dashboard-panel-body">

                        <?php if ($courseCapabilityLabels === []): ?>

                            <p>
                                You currently have view-only access to this
                                workspace.
                            </p>

                        <?php else: ?>

                            <div
                                class="dashboard-role-chips"
                                aria-label="Available course capabilities"
                            >
                                <?php foreach (
                                    $courseCapabilityLabels
                                    as $capabilityLabel
                                ): ?>
                                    <span class="dashboard-role-chip">
                                        <?= e($capabilityLabel); ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>

                        <?php endif; ?>

                    </div>
                </section>


                <div class="dashboard-workspace-two-column">

                    <section class="dashboard-workspace-panel">
                        <div class="dashboard-panel-titlebar">
                            <div>
                                <p class="academy-overline">
                                    Course Library
                                </p>

                                <h3>
                                    Course status
                                </h3>
                            </div>
                        </div>

                        <div class="dashboard-panel-body">

                            <div class="dashboard-placeholder-list">
                                <span>
                                    <strong>
                                        <?= number_format(
                                            $courseStats[
                                                'draft_courses'
                                            ]
                                        ); ?>
                                    </strong>
                                    Draft
                                </span>

                                <span>
                                    <strong>
                                        <?= number_format(
                                            $courseStats[
                                                'published_courses'
                                            ]
                                        ); ?>
                                    </strong>
                                    Published
                                </span>

                                <span>
                                    <strong>
                                        <?= number_format(
                                            $courseStats[
                                                'archived_courses'
                                            ]
                                        ); ?>
                                    </strong>
                                    Archived
                                </span>

                                <span>
                                    <strong>
                                        <?= number_format(
                                            $courseStats[
                                                'total_lessons'
                                            ]
                                        ); ?>
                                    </strong>
                                    Lessons
                                </span>
                            </div>

                        </div>
                    </section>


                    <section class="dashboard-workspace-panel">
                        <div class="dashboard-panel-titlebar">
                            <div>
                                <p class="academy-overline">
                                    School Year
                                </p>

                                <h3>
                                    Current academic cycle
                                </h3>
                            </div>
                        </div>

                        <div class="dashboard-panel-body">

                            <?php if ($currentSchoolYear === null): ?>

                                <p>
                                    No active school year is currently marked
                                    as the Academy's current school year.
                                </p>

                            <?php else: ?>

                                <div class="dashboard-placeholder-list">
                                    <span>
                                        <?= e($currentSchoolYearName); ?>
                                    </span>

                                    <span>
                                        Starts
                                        <?= e(
                                            $formatDashboardDate(
                                                $currentSchoolYear[
                                                    'start_date'
                                                ]
                                                ?? null
                                            )
                                        ); ?>
                                    </span>

                                    <span>
                                        Ends
                                        <?= e(
                                            $formatDashboardDate(
                                                $currentSchoolYear[
                                                    'end_date'
                                                ]
                                                ?? null
                                            )
                                        ); ?>
                                    </span>

                                    <span>
                                        <?= (int) (
                                            $currentSchoolYear[
                                                'is_finalized'
                                            ]
                                            ?? 0
                                        ) === 1
                                            ? 'Finalized'
                                            : 'Active'; ?>
                                    </span>
                                </div>

                            <?php endif; ?>

                        </div>
                    </section>

                </div>


                <section class="dashboard-workspace-panel">
                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">
                                Recently Updated
                            </p>

                            <h3>
                                Courses
                            </h3>
                        </div>
                    </div>

                    <div class="dashboard-panel-body">

                        <?php if ($recentCourses === []): ?>

                            <p>
                                No courses have been created yet. Use Create Course
                                to add the first course to the Academy catalog.
                            </p>

                        <?php else: ?>

                            <div class="dashboard-placeholder-list">

                                <?php foreach (
                                    $recentCourses
                                    as $course
                                ): ?>
                                    <?php
                                    $courseTitle =
                                        trim(
                                            (string) (
                                                $course['title']
                                                ?? 'Untitled Course'
                                            )
                                        );

                                    if ($courseTitle === '') {
                                        $courseTitle =
                                            'Untitled Course';
                                    }

                                    $courseCode =
                                        trim(
                                            (string) (
                                                $course['course_code']
                                                ?? ''
                                            )
                                        );

                                    $courseStatus =
                                        $formatCourseStatus(
                                            (string) (
                                                $course['status']
                                                ?? 'draft'
                                            )
                                        );
                                    ?>

                                    <span>
                                        <strong>
                                            <?= e($courseTitle); ?>
                                        </strong>

                                        <?php if ($courseCode !== ''): ?>
                                            · <?= e($courseCode); ?>
                                        <?php endif; ?>

                                        · <?= e($courseStatus); ?>

                                        · Updated
                                        <?= e(
                                            $formatDashboardDate(
                                                $course['updated_at']
                                                ?? null
                                            )
                                        ); ?>

                                        <?php if ($canEditCourses): ?>
                                            ·
                                            <a
                                                href="<?= e(
                                                    url(
                                                        'admin/course-edit.php?id='
                                                        . (int) $course['id']
                                                    )
                                                ); ?>"
                                            >
                                                Edit
                                            </a>
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

<?php

require INCLUDES_PATH . '/footer.php';
