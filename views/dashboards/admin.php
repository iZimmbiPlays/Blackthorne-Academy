<?php

declare(strict_types=1);

/**
 * Blackthorne Academy
 * Administrative Dashboard
 *
 * Loaded by dashboard.php for both the protected Super Admin and future
 * permission-based Admin accounts.
 */


if (!current_user_is_admin()) {

    http_response_code(403);

    exit(
        'You do not have permission to access the administrative dashboard.'
    );

}


$isProtectedSuperAdmin =
    current_user_is_superuser();


/*
|--------------------------------------------------------------------------
| Dashboard Statistics
|--------------------------------------------------------------------------
*/

$adminStatistics = [
    'total_users'       => 0,
    'pending_users'     => 0,
    'active_students'   => 0,
    'forum_categories'  => 0,
    'forums'            => 0,
    'forum_threads'     => 0,
    'open_reports'      => 0,
    'published_courses' => 0,
    'draft_courses'     => 0,
    'active_houses'     => 0,
];

$currentSchoolYear =
    null;


try {

    $adminStatistics['total_users'] =
        (int) $pdo
            ->query('SELECT COUNT(*) FROM users')
            ->fetchColumn();


    $adminStatistics['pending_users'] =
        (int) $pdo
            ->query(
                'SELECT COUNT(*)
                 FROM users
                 WHERE status = "pending"'
            )
            ->fetchColumn();


    $adminStatistics['active_students'] =
        (int) $pdo
            ->query(
                'SELECT COUNT(DISTINCT user_id)
                 FROM student_year_enrollments
                 WHERE promotion_status IN (
                    "active",
                    "eligible",
                    "not_eligible",
                    "repeating"
                 )'
            )
            ->fetchColumn();


    $adminStatistics['forum_categories'] =
        (int) $pdo
            ->query('SELECT COUNT(*) FROM forum_categories')
            ->fetchColumn();


    $adminStatistics['forums'] =
        (int) $pdo
            ->query('SELECT COUNT(*) FROM forums')
            ->fetchColumn();


    $adminStatistics['forum_threads'] =
        (int) $pdo
            ->query(
                'SELECT COUNT(*)
                 FROM forum_threads
                 WHERE is_deleted = 0'
            )
            ->fetchColumn();


    $adminStatistics['open_reports'] =
        (int) $pdo
            ->query(
                'SELECT COUNT(*)
                 FROM forum_reports
                 WHERE status IN ("open", "reviewing")'
            )
            ->fetchColumn();


    $adminStatistics['published_courses'] =
        (int) $pdo
            ->query(
                'SELECT COUNT(*)
                 FROM courses
                 WHERE status = "published"'
            )
            ->fetchColumn();


    $adminStatistics['draft_courses'] =
        (int) $pdo
            ->query(
                'SELECT COUNT(*)
                 FROM courses
                 WHERE status = "draft"'
            )
            ->fetchColumn();


    $adminStatistics['active_houses'] =
        (int) $pdo
            ->query(
                'SELECT COUNT(*)
                 FROM houses
                 WHERE is_active = 1'
            )
            ->fetchColumn();


    $schoolYearStatement =
        $pdo->query(
            'SELECT name
             FROM school_years
             WHERE is_current = 1
               AND is_active = 1
             ORDER BY start_date DESC
             LIMIT 1'
        );


    $schoolYearResult =
        $schoolYearStatement->fetchColumn();


    if (
        is_string($schoolYearResult)
        &&
        trim($schoolYearResult) !== ''
    ) {

        $currentSchoolYear =
            trim($schoolYearResult);

    }


} catch (PDOException $exception) {

    error_log(
        'Blackthorne administrative dashboard statistics error: '
        . $exception->getMessage()
    );

}


$managementAreas = [
    [
        'number'      => 'I',
        'title'       => 'User Accounts',
        'description' => 'Review registrations, account status, profiles, enrollments, and administrative actions.',
        'status'      => 'Management Page Pending',
        'url'         => null,
    ],
    [
        'number'      => 'II',
        'title'       => 'Forums & Announcements',
        'description' => 'Create categories, forums, sub-forums, announcements, events, access rules, and sidebar placement.',
        'status'      => 'Manage Forums',
        'url'         => url('admin/forums.php'),
    ],
    [
        'number'      => 'III',
        'title'       => 'Courses & Lessons',
        'description' => 'Create courses, offerings, lessons, prerequisites, assignments, quizzes, and instructor access.',
        'status'      => 'Management Page Pending',
        'url'         => null,
    ],
    [
        'number'      => 'IV',
        'title'       => 'Houses & Sorting',
        'description' => 'Create Houses, choose their colors and crests, manage sorting, and review House membership.',
        'status'      => 'Management Page Pending',
        'url'         => null,
    ],
    [
        'number'      => 'V',
        'title'       => 'Roles & Permissions',
        'description' => 'Create roles and control exactly which site, academic, community, and moderation tools they can use.',
        'status'      => 'Manage Roles & Permissions',
        'url'         => url('admin/roles.php'),
    ],
    [
        'number'      => 'VI',
        'title'       => 'Points & Achievements',
        'description' => 'Manage House points, homework points, point reasons, achievements, corrections, and yearly totals.',
        'status'      => 'Management Page Pending',
        'url'         => null,
    ],
    [
        'number'      => 'VII',
        'title'       => 'School Years',
        'description' => 'Configure academic years, promotion windows, course access dates, finalization, and annual resets.',
        'status'      => 'Management Page Pending',
        'url'         => null,
    ],
    [
        'number'      => 'VIII',
        'title'       => 'Reports & Moderation',
        'description' => 'Review reported content, moderation history, member sanctions, and unresolved community issues.',
        'status'      => 'Management Page Pending',
        'url'         => null,
    ],
    [
        'number'      => 'IX',
        'title'       => 'Academy Settings',
        'description' => 'Control site-wide configuration, feature availability, limits, defaults, and protected owner settings.',
        'status'      => 'Management Page Pending',
        'url'         => null,
    ],
];

?>

<main
    id="main-content"
    class="dashboard-page admin-dashboard-page"
>

    <section
        class="dashboard-hero admin-dashboard-hero"
        aria-labelledby="admin-dashboard-heading"
    >

        <div class="section-inner">

            <div class="dashboard-hero-inner">

                <p class="academy-overline">
                    Academy Administration
                </p>

                <h1 id="admin-dashboard-heading">
                    Welcome back, <?= e($displayName); ?>.
                </h1>

                <p class="dashboard-hero-copy">
                    Govern Blackthorne Academy from one central place. Every
                    administrative system will connect to this dashboard as
                    its management tools are completed.
                </p>

                <div class="dashboard-status-line">

                    <span class="dashboard-status-label">
                        Admin
                    </span>

                    <span aria-hidden="true">✦</span>

                    <?php if ($isProtectedSuperAdmin): ?>

                        <span>
                            Protected Super Admin
                        </span>

                    <?php else: ?>

                        <span>
                            Permission-Based Access
                        </span>

                    <?php endif; ?>

                    <span aria-hidden="true">✦</span>

                    <span>
                        Account Active
                    </span>

                </div>

            </div>

        </div>

    </section>


    <section
        class="admin-dashboard-overview"
        aria-labelledby="admin-overview-heading"
    >

        <div class="section-inner">

            <header class="dashboard-section-heading">

                <p class="academy-overline">
                    Academy Overview
                </p>

                <h2 id="admin-overview-heading">
                    The state of Blackthorne.
                </h2>

                <p>
                    These totals are drawn directly from the academy database.
                </p>

            </header>


            <div class="admin-stat-grid">

                <article class="admin-stat-card">
                    <span class="admin-stat-value">
                        <?= number_format($adminStatistics['total_users']); ?>
                    </span>
                    <h3>Members</h3>
                    <p>
                        <?= number_format($adminStatistics['pending_users']); ?> pending verification
                    </p>
                </article>

                <article class="admin-stat-card">
                    <span class="admin-stat-value">
                        <?= number_format($adminStatistics['active_students']); ?>
                    </span>
                    <h3>Active Students</h3>
                    <p>Currently enrolled academically</p>
                </article>

                <article class="admin-stat-card">
                    <span class="admin-stat-value">
                        <?= number_format($adminStatistics['forum_categories']); ?>
                    </span>
                    <h3>Forum Categories</h3>
                    <p>
                        <?= number_format($adminStatistics['forums']); ?> forums created
                    </p>
                </article>

                <article class="admin-stat-card">
                    <span class="admin-stat-value">
                        <?= number_format($adminStatistics['forum_threads']); ?>
                    </span>
                    <h3>Forum Threads</h3>
                    <p>
                        <?= number_format($adminStatistics['open_reports']); ?> reports awaiting resolution
                    </p>
                </article>

                <article class="admin-stat-card">
                    <span class="admin-stat-value">
                        <?= number_format($adminStatistics['published_courses']); ?>
                    </span>
                    <h3>Published Courses</h3>
                    <p>
                        <?= number_format($adminStatistics['draft_courses']); ?> drafts in progress
                    </p>
                </article>

                <article class="admin-stat-card">
                    <span class="admin-stat-value">
                        <?= number_format($adminStatistics['active_houses']); ?>
                    </span>
                    <h3>Active Houses</h3>
                    <p>
                        <?= $currentSchoolYear !== null
                            ? e($currentSchoolYear)
                            : 'No current school year set'; ?>
                    </p>
                </article>

            </div>


            <div class="admin-dashboard-quick-links">

                <a
                    href="<?= e(url('index.php')); ?>"
                    class="button button-secondary"
                >
                    View Member Homepage
                </a>

                <a
                    href="<?= e(url('forums.php')); ?>"
                    class="button button-secondary"
                >
                    View Forums
                </a>

                <a
                    href="<?= e(url('courses.php')); ?>"
                    class="button button-secondary"
                >
                    View Courses
                </a>

            </div>

        </div>

    </section>


    <section
        class="admin-management-section"
        aria-labelledby="admin-management-heading"
    >

        <div class="section-inner">

            <header class="dashboard-section-heading">

                <p class="academy-overline">
                    Administration
                </p>

                <h2 id="admin-management-heading">
                    Manage the Academy.
                </h2>

                <p>
                    Management pages will be connected here as each system is
                    built and tested.
                </p>

            </header>


            <div class="admin-management-grid">

                <?php foreach ($managementAreas as $area): ?>

                    <article class="dashboard-card admin-management-card">

                        <div class="dashboard-card-number">
                            <?= e($area['number']); ?>
                        </div>

                        <h3>
                            <?= e($area['title']); ?>
                        </h3>

                        <p>
                            <?= e($area['description']); ?>
                        </p>

                        <?php if (is_string($area['url'])): ?>

                            <a
                                href="<?= e($area['url']); ?>"
                                class="dashboard-card-link"
                            >
                                <?= e($area['status']); ?>
                                <span aria-hidden="true">→</span>
                            </a>

                        <?php else: ?>

                            <span class="dashboard-card-coming-soon">
                                <?= e($area['status']); ?>
                            </span>

                        <?php endif; ?>

                    </article>

                <?php endforeach; ?>

            </div>


            <?php if ($isProtectedSuperAdmin): ?>

                <aside
                    class="admin-owner-notice"
                    aria-label="Protected Super Admin status"
                >

                    <span
                        class="admin-owner-notice-mark"
                        aria-hidden="true"
                    >
                        ✦
                    </span>

                    <div>

                        <h2>
                            Protected owner access is active.
                        </h2>

                        <p>
                            Your account can access every Academy feature and
                            cannot be replaced by assigning another user the
                            Admin role. Future administrators will receive only
                            the permissions granted to them.
                        </p>

                    </div>

                </aside>

            <?php endif; ?>

        </div>

    </section>

</main>
