<?php

declare(strict_types=1);

/**
 * Blackthorne Academy
 * Dynamic Staff Dashboard
 *
 * Loaded for members who qualify for Staff Dashboard access through an
 * administrative account, a staff-designated role, or effective staff
 * permissions.
 *
 * IMPORTANT:
 * - Dashboard access is permission-aware and is not tied to a role name.
 * - Effective permissions decide which tools/cards are visible.
 * - No role names are hard-coded here.
 * - Multiple active roles combine through user_can().
 * - Direct user permission overrides are respected by user_can().
 */


$staffDashboardPermissions = [
    'users.view',
    'users.manage',
    'roles.view',
    'roles.create',
    'roles.edit',
    'roles.delete',
    'roles.permissions.manage',
    'roles.assign',
    'users.permissions.manage',
    'users.activate',
    'users.suspend',
    'users.archive',
    'users.sanctions.view',
    'users.sanctions.manage',
    'forums.admin.access',
    'forums.categories.manage',
    'forums.manage',
    'forums.access.manage',
    'forums.labels.manage',
    'forums.attachments.manage',
    'forums.moderate',
    'forums.posts.edit',
    'forums.posts.delete',
    'forums.posts.restore',
    'forums.threads.edit',
    'forums.threads.delete',
    'forums.threads.restore',
    'forums.threads.lock',
    'forums.threads.pin',
    'forums.threads.move',
    'forums.posts.move',
    'forums.announcements.manage',
    'forums.labels.apply',
    'moderation.reports.view',
    'moderation.reports.review',
    'moderation.reports.resolve',
    'moderation.reports.dismiss',
    'moderation.history.view',
    'moderation.mutes.manage',
    'courses.admin.view',
    'courses.create',
    'courses.edit',
    'courses.publish',
    'courses.archive',
    'courses.offerings.manage',
    'houses.sorting.manage',
    'points.history.view',
    'points.house.award',
    'points.house.deduct',
    'points.reverse',
    'achievements.manage',
    'achievements.award',
    'progression.rules.manage',
    'house_cup.finalize',
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
    'assignments.submissions.view',
    'assignments.grade',
    'quizzes.create',
    'quizzes.edit',
    'quizzes.publish',
    'quizzes.attempts.view',
    'quizzes.grade',
    'members.admin.view',
    'members.profiles.manage',
    'profiles.fields.manage',
    'messaging.admin.access',
    'messaging.manage',
    'messaging.reports.view',
    'messaging.reports.review',
    'notifications.manage',
    'notifications.system.send',
    'settings.manage',
    'audit.view',
    'manage_forums',
];

$canAccessStaffDashboard =
    current_user_is_superuser()
    || current_user_is_admin()
    || current_user_is_staff()
    || user_can_any(
        $staffDashboardPermissions
    );

if (!$canAccessStaffDashboard) {

    http_response_code(403);

    exit(
        'You do not have permission to access the staff dashboard.'
    );

}


/*
|--------------------------------------------------------------------------
| Current Staff Roles
|--------------------------------------------------------------------------
|
| Only staff-designated roles are displayed here. A user may hold additional
| non-staff roles, but those do not need to appear in the staff identity line.
|
*/

$staffRoles = [];


foreach (
    current_user_roles()
    as $role
) {

    if (
        isset($role['is_staff'])
        && (int) $role['is_staff'] === 1
    ) {

        $staffRoles[] = $role;

    }

}


/*
|--------------------------------------------------------------------------
| Permission-Aware Dashboard Areas
|--------------------------------------------------------------------------
|
| A card appears only when the user has at least one permission that currently
| authorizes an action in that exact management area.
|
| Related permissions are not enough by themselves. For example, the Forums
| card links to admin/forums.php, which currently requires manage_forums, so
| granular forum or poll permissions do not expose that card until the page
| itself supports those permissions.
|
| View-only/access-only permissions also do not make management cards appear.
|
*/

$staffAreas = [

    [
        'title'       => 'Users & Role Assignments',
        'description' => 'Find Academy members and manage the roles and groups assigned to their accounts when your permissions allow it.',
        'permissions' => [
            'users.view',
            'users.manage',
            'roles.assign',
            'users.permissions.manage',
            'users.activate',
            'users.suspend',
            'users.archive',
        ],
        'url'         => url('admin/users.php'),
        'action'      => 'Manage Users & Roles',
    ],

    [
        'title'       => 'Roles & Permissions',
        'description' => 'View or manage roles, groups, role permissions, assignments, and individual permission overrides.',
        'permissions' => [
            'roles.view',
            'roles.create',
            'roles.edit',
            'roles.delete',
            'roles.permissions.manage',
        ],
        'url'         => url('admin/roles.php'),
        'action'      => 'Manage Roles & Permissions',
    ],

    [
        'title'       => 'Forums',
        'description' => 'Manage forum categories, boards, access rules, labels, attachments, and other permitted forum settings.',
        'permissions' => [
            'forums.admin.access',
            'manage_forums',
        ],
        'url'         => url('admin/forums.php'),
        'action'      => 'Manage Forums',
    ],

    [
        'title'       => 'Forum Moderation',
        'description' => 'Moderate posts and threads using the actions specifically granted to your role or account.',
        'permissions' => [
            'forums.moderate',
            'forums.posts.edit',
            'forums.posts.delete',
            'forums.posts.restore',
            'forums.threads.edit',
            'forums.threads.delete',
            'forums.threads.restore',
            'forums.threads.lock',
            'forums.threads.pin',
            'forums.threads.move',
            'forums.posts.move',
            'forums.announcements.manage',
            'forums.labels.apply',
        ],
        'url'         => url('forums.php'),
        'action'      => 'Open Forums',
    ],

    [
        'title'       => 'Moderation',
        'description' => 'Review reports, moderation history, and user sanctions according to your current permissions.',
        'permissions' => [
            'moderation.reports.review',
            'moderation.reports.resolve',
            'moderation.reports.dismiss',
        ],
        'url'         => null,
        'action'      => 'Open Moderation',
        'area_key'    => 'moderation',
    ],

    [
        'title'       => 'Moderation Reports',
        'description' => 'Review reported forum content and take permitted moderation actions.',
        'permissions' => [
            'moderation.reports.view',
            'moderation.reports.review',
            'moderation.reports.resolve',
            'moderation.reports.dismiss',
        ],
        'url'         => url('admin/reports.php'),
        'action'      => 'Open Reports',
    ],

    [
        'title'       => 'Moderation History',
        'description' => 'Review the Academy moderation history and prior staff actions.',
        'permissions' => [
            'moderation.history.view',
        ],
        'url'         => url('admin/moderation-history.php'),
        'action'      => 'View Moderation History',
    ],

    [
        'title'       => 'User Sanctions',
        'description' => 'View and manage permitted member sanctions and moderation restrictions.',
        'permissions' => [
            'users.sanctions.view',
            'users.sanctions.manage',
            'moderation.mutes.manage',
        ],
        'url'         => url('admin/sanctions.php'),
        'action'      => 'Manage User Sanctions',
    ],

    [
        'title'       => 'Courses',
        'description' => 'Create, edit, publish, archive, or otherwise manage courses and course offerings as permitted.',
        'permissions' => [
            'courses.admin.view',
            'courses.create',
            'courses.edit',
            'courses.publish',
            'courses.archive',
            'courses.offerings.manage',
            'courses.prerequisites.manage',
            'courses.learning_paths.manage',
        ],
        'url'         => url('admin/courses.php'),
        'action'      => 'Manage Courses',
    ],

    [
        'title'       => 'Course Staff',
        'description' => 'View and manage instructors, assistants, course-staff permissions, invitations, and staff notes.',
        'permissions' => [
            'course_staff.view',
            'course_staff.assign',
            'course_staff.permissions.manage',
            'course_staff.invites.manage',
            'course_staff.notes.manage',
        ],
        'url'         => null,
        'action'      => 'Management Page Pending',
    ],

    [
        'title'       => 'Lessons',
        'description' => 'Create, edit, publish, and configure the release of course lessons.',
        'permissions' => [
            'lessons.create',
            'lessons.edit',
            'lessons.publish',
            'lessons.release.manage',
        ],
        'url'         => null,
        'action'      => 'Management Page Pending',
    ],

    [
        'title'       => 'Assignments',
        'description' => 'Create and manage assignments, assignment versions, deadlines, submissions, and related settings.',
        'permissions' => [
            'assignments.create',
            'assignments.edit',
            'assignments.publish',
            'assignments.settings.manage',
        ],
        'url'         => null,
        'action'      => 'Management Page Pending',
    ],

    [
        'title'       => 'Assessments',
        'description' => 'Create and manage quizzes, midterms, finals, questions, attempts, and assessment settings.',
        'permissions' => [
            'assessments.create',
            'assessments.edit',
            'assessments.publish',
            'assessments.settings.manage',
        ],
        'url'         => null,
        'action'      => 'Management Page Pending',
    ],

    [
        'title'       => 'Grading',
        'description' => 'View grades, grade coursework, perform permitted regrading, and review grade history.',
        'permissions' => [
            'grading.assignments',
            'grading.assessments',
            'grading.regrade',
            'grading.settings.manage',
        ],
        'url'         => null,
        'action'      => 'Management Page Pending',
    ],

    [
        'title'       => 'Enrollments',
        'description' => 'View and manage course enrollments, school-year enrollment, and student progression.',
        'permissions' => [
            'enrollments.courses.manage',
            'enrollments.years.manage',
            'enrollments.progression.manage',
        ],
        'url'         => null,
        'action'      => 'Management Page Pending',
    ],

    [
        'title'       => 'Houses',
        'description' => 'Create and configure Academy Houses, House themes, Common Room settings, crests, and House memberships where permitted.',
        'permissions' => [
            'houses.view',
            'houses.create',
            'houses.edit',
            'houses.delete',
            'houses.members.manage',
        ],
        'url'         => url('admin/houses.php'),
        'action'      => 'Manage Houses',
    ],

    [
        'title'       => 'House Members',
        'description' => 'Manage current House memberships and member placement.',
        'permissions' => [
            'houses.members.manage',
        ],
        'url'         => url('admin/house-members.php'),
        'action'      => 'Manage House Members',
        'admin_only'  => true,
    ],

    [
        'title'       => 'Sorting Ceremony',
        'description' => 'Manage ceremony versions, primary and Choosing questions, hidden House mappings, answers, and magical interludes.',
        'permissions' => [
            'houses.sorting.manage',
        ],
        'url'         => url('admin/sorting-ceremony.php'),
        'action'      => 'Manage Sorting Ceremony',
    ],

    [
        'title'       => 'Points',
        'description' => 'Award or deduct House Points, review point history, manage progression rules, and access the Points & Achievements workspace.',
        'permissions' => [
            'points.history.view',
            'points.house.award',
            'points.house.deduct',
            'points.reverse',
            'progression.rules.manage',
            'house_cup.finalize',
        ],
        'url'         => url('admin/points.php'),
        'action'      => 'Open Points & Achievements',
    ],

    [
        'title'       => 'Messaging',
        'description' => 'Access permitted messaging administration and private-message report tools.',
        'permissions' => [
            'messaging.manage',
            'messaging.reports.review',
        ],
        'url'         => null,
        'action'      => 'Management Page Pending',
    ],

    [
        'title'       => 'Announcements',
        'description' => 'Create, edit, publish, and delete announcements according to your permissions.',
        'permissions' => [
            'announcements.create',
            'announcements.edit',
            'announcements.publish',
            'announcements.delete',
        ],
        'url'         => null,
        'action'      => 'Management Page Pending',
    ],

    [
        'title'       => 'Events & Contests',
        'description' => 'Manage Academy events and contests and award event-related points where permitted.',
        'permissions' => [
            'events.manage',
            'contests.manage',
            'events.points.award',
        ],
        'url'         => null,
        'action'      => 'Management Page Pending',
    ],

    [
        'title'       => 'Achievements',
        'description' => 'Create and configure dynamic Academy achievements, House Point rewards, trigger rules, and repeat behavior.',
        'permissions' => [
            'achievements.manage',
            'achievements.award',
        ],
        'url'         => url('admin/achievements.php'),
        'action'      => 'Manage Achievements',
    ],

    [
        'title'       => 'Notifications',
        'description' => 'Send or manage system-level notifications where your role has been granted access.',
        'permissions' => [
            'notifications.system.send',
            'notifications.manage',
        ],
        'url'         => null,
        'action'      => 'Management Page Pending',
    ],

    [
        'title'       => 'Audit Log',
        'description' => 'Review Academy audit information when the audit workspace is connected.',
        'permissions' => [
            'audit.view',
        ],
        'url'         => null,
        'action'      => 'Management Page Pending',
    ],

    [
        'title'       => 'Site Administration',
        'description' => 'Access permitted Academy-wide administrative settings and audit information.',
        'permissions' => [
            'settings.manage',
        ],
        'url'         => null,
        'action'      => 'Management Page Pending',
    ],

];


/*
|--------------------------------------------------------------------------
| Build Visible Staff Areas
|--------------------------------------------------------------------------
*/

$visibleStaffAreas = [];


foreach (
    $staffAreas
    as $area
) {

    $areaIsAdminOnly = (bool) ($area['admin_only'] ?? false);

    if (
        $areaIsAdminOnly
        && !current_user_is_admin()
        && !current_user_is_superuser()
    ) {
        continue;
    }

    if (
        !current_user_is_superuser()
        && !user_can_any(
            $area['permissions']
        )
    ) {
        continue;
    }


    /*
     * Moderation now has one central landing page. The hub performs its own
     * permission-aware display and each destination still enforces its own
     * authorization checks.
     */
    if (
        isset($area['area_key'])
        && $area['area_key'] === 'moderation'
    ) {

        $area['url'] =
            url(
                'admin/moderation.php'
            );

        $area['action'] =
            'Open Moderation';

    }


    $visibleStaffAreas[] =
        $area;

}


/*
|--------------------------------------------------------------------------
| Permission Count
|--------------------------------------------------------------------------
|
| Counts active permission records the current user can actually use.
| This is informational only and does not affect authorization.
|
*/

$effectivePermissionCount =
    0;


try {

    $permissionStatement =
        $pdo->query(
            'SELECT slug
             FROM permissions
             WHERE is_active = 1
             ORDER BY id ASC'
        );


    $permissionSlugs =
        $permissionStatement->fetchAll(
            PDO::FETCH_COLUMN
        );


    foreach (
        $permissionSlugs
        as $permissionSlug
    ) {

        if (
            is_string($permissionSlug)
            &&
            user_can($permissionSlug)
        ) {

            $effectivePermissionCount++;

        }

    }


} catch (PDOException $exception) {

    error_log(
        'Blackthorne staff dashboard permission count error: '
        . $exception->getMessage()
    );

}


/*
|--------------------------------------------------------------------------
| Grouped Staff Navigation
|--------------------------------------------------------------------------
|
| The sidebar is the staff dashboard's navigation. Management destinations
| are grouped by area so the main workspace can stay focused on status,
| queues, and activity instead of duplicating the same links as cards.
|
*/

$visibleStaffAreasByTitle = [];

foreach ($visibleStaffAreas as $area) {
    $visibleStaffAreasByTitle[(string) $area['title']] = $area;
}

$makeStaffNavItem = static function (
    string $title,
    string $icon = '›'
) use ($visibleStaffAreasByTitle): ?array {
    if (!isset($visibleStaffAreasByTitle[$title])) {
        return null;
    }

    $area = $visibleStaffAreasByTitle[$title];
    $href = is_string($area['url']) ? $area['url'] : '';

    return [
        'label' => $title,
        'href' => $href,
        'icon' => $icon,
        'disabled' => $href === '',
        'meta' => $href === '' ? 'Coming soon' : '',
    ];
};

$makeStaffNavGroup = static function (
    string $label,
    ?string $parentAreaTitle,
    array $childAreaTitles
) use ($visibleStaffAreasByTitle, $makeStaffNavItem): ?array {
    $parentArea = $parentAreaTitle !== null
        ? ($visibleStaffAreasByTitle[$parentAreaTitle] ?? null)
        : null;

    $children = [];

    /*
     * Group labels are organizational headings only. When the group also has
     * its own management destination, list that destination first inside the
     * group so all clickable tools follow the same visual pattern.
     */
    if ($parentArea !== null && $parentAreaTitle !== null) {
        $parentItem = $makeStaffNavItem($parentAreaTitle);
        if ($parentItem !== null) {
            $children[] = $parentItem;
        }
    }

    foreach ($childAreaTitles as $childTitle) {
        $child = $makeStaffNavItem((string) $childTitle);
        if ($child !== null) {
            $children[] = $child;
        }
    }

    if ($children === []) {
        return null;
    }

    return [
        'type' => 'group',
        'label' => $label,
        'href' => '',
        'children' => $children,
    ];
};

$dashboardSidebarEyebrow = 'Staff Workspace';
$dashboardSidebarTitle = 'Staff Dashboard';
$dashboardSidebarItems = [
    [
        'label' => 'Overview',
        'href' => url('staff-dashboard.php'),
        'icon' => '⌂',
        'active' => true,
        'meta' => number_format(count($visibleStaffAreas)) . ' available areas',
    ],
];

$staffNavigationGroups = [
    $makeStaffNavGroup(
        'Roles & Permissions',
        'Roles & Permissions',
        ['Users & Role Assignments']
    ),
    $makeStaffNavGroup(
        'Forums',
        'Forums',
        ['Forum Moderation', 'Moderation', 'Moderation Reports', 'Moderation History', 'User Sanctions']
    ),
    $makeStaffNavGroup(
        'Courses',
        'Courses',
        ['Enrollments', 'Lessons', 'Assignments', 'Assessments', 'Grading', 'Course Staff']
    ),
    $makeStaffNavGroup(
        'Houses',
        'Houses',
        ['House Members', 'Sorting Ceremony', 'Points']
    ),
    $makeStaffNavGroup(
        'Community & Communications',
        null,
        ['Messaging', 'Announcements', 'Events & Contests', 'Notifications']
    ),
    $makeStaffNavGroup(
        'Members',
        null,
        ['Achievements']
    ),
    $makeStaffNavGroup(
        'Site Administration',
        'Site Administration',
        ['Audit Log']
    ),
];

foreach ($staffNavigationGroups as $group) {
    if ($group !== null) {
        $dashboardSidebarItems[] = $group;
    }
}

/*
|--------------------------------------------------------------------------
| Staff Dashboard Hero Image
|--------------------------------------------------------------------------
|
| Prefer the generated WebP derivative when it exists. Until then, use the
| original PNG automatically so the hero never depends on an unavailable
| derivative.
|
*/

$staffHeroPngReference = 'assets/images/staff_dashboard_hero_bg.png';
$staffHeroWebpReference = 'assets/images/staff_dashboard_hero_bg.webp';
$staffHeroProjectRoot = dirname(__DIR__, 2);
$staffHeroWebpPath = $staffHeroProjectRoot . '/' . $staffHeroWebpReference;
$staffHeroPngPath = $staffHeroProjectRoot . '/' . $staffHeroPngReference;

$staffHeroReference = is_file($staffHeroWebpPath)
    ? $staffHeroWebpReference
    : $staffHeroPngReference;

$staffHeroUrl = is_file($staffHeroWebpPath) || is_file($staffHeroPngPath)
    ? url($staffHeroReference)
    : '';


$dashboardSidebarFooter = [
    [
        'label' => 'Personal Dashboard',
        'href' => url('dashboard.php'),
        'icon' => '◇',
        'meta' => 'Return to member view',
    ],
    [
        'label' => 'Academy Homepage',
        'href' => url('index.php'),
        'icon' => '⌂',
    ],
];

?>

<main id="main-content" class="dashboard-page staff-dashboard-page dashboard-workspace-page">
    <section
        class="dashboard-hero staff-dashboard-hero"
        aria-labelledby="staff-dashboard-heading"
        <?php if ($staffHeroUrl !== ''): ?>style="--staff-dashboard-hero-image: url('<?= e($staffHeroUrl); ?>');"<?php endif; ?>
    >
        <div class="section-inner">
            <div class="dashboard-hero-inner">
                <p class="academy-overline">Academy Staff</p>
                <h1 id="staff-dashboard-heading">Welcome back, <?= e($displayName); ?>.</h1>
                <p class="dashboard-hero-copy">
                    Your staff dashboard is built from the permissions attached to your active roles and individual account. When your access changes, the tools shown here change automatically.
                </p>
                <div class="dashboard-status-line">
                    <span class="dashboard-status-label">Staff</span>
                    <span aria-hidden="true">✦</span>
                    <span><?= number_format(count($staffRoles)); ?> Staff <?= count($staffRoles) === 1 ? 'Role' : 'Roles'; ?></span>
                    <span aria-hidden="true">✦</span>
                    <span><?= number_format($effectivePermissionCount); ?> Active <?= $effectivePermissionCount === 1 ? 'Permission' : 'Permissions'; ?></span>
                    <span aria-hidden="true">✦</span>
                    <span>Account Active</span>
                </div>
            </div>
        </div>
    </section>

    <section class="dashboard-workspace-section" aria-labelledby="staff-workspace-heading">
        <div class="section-inner dashboard-workspace-layout">
            <?php require INCLUDES_PATH . '/dashboard-sidebar.php'; ?>

            <div class="dashboard-workspace-main">
                <header class="dashboard-workspace-heading">
                    <div>
                        <p class="academy-overline">Staff Overview</p>
                        <h2 id="staff-workspace-heading">Your Academy workspace.</h2>
                        <p>Use the grouped navigation at left for staff tools. This overview is reserved for your access, queues, and Academy activity.</p>
                    </div>
                </header>

                <div class="dashboard-summary-grid staff-dashboard-summary-grid">
                    <article class="dashboard-summary-card">
                        <span class="dashboard-summary-kicker">Roles</span>
                        <strong><?= number_format(count($staffRoles)); ?></strong>
                        <p>Active staff roles</p>
                    </article>
                    <article class="dashboard-summary-card">
                        <span class="dashboard-summary-kicker">Permissions</span>
                        <strong><?= number_format($effectivePermissionCount); ?></strong>
                        <p>Effective active permissions</p>
                    </article>
                    <article class="dashboard-summary-card">
                        <span class="dashboard-summary-kicker">Areas</span>
                        <strong><?= number_format(count($visibleStaffAreas)); ?></strong>
                        <p>Available management areas</p>
                    </article>
                    <article class="dashboard-summary-card">
                        <span class="dashboard-summary-kicker">Status</span>
                        <strong>Active</strong>
                        <p>Staff workspace access</p>
                    </article>
                </div>

                <?php if (!empty($staffRoles)): ?>
                    <section class="dashboard-workspace-panel">
                        <div class="dashboard-panel-titlebar">
                            <div>
                                <p class="academy-overline">Staff Access</p>
                                <h3>Your active staff roles</h3>
                            </div>
                        </div>
                        <div class="dashboard-panel-body">
                            <div class="dashboard-role-chips" aria-label="Active staff roles">
                                <?php foreach ($staffRoles as $role): ?>
                                    <?php
                                    $roleName = trim((string) ($role['name'] ?? 'Staff'));
                                    if ($roleName === '') {
                                        $roleName = 'Staff';
                                    }
                                    $roleColor = trim((string) ($role['display_color'] ?? ''));
                                    $validRoleColor = preg_match('/^#[0-9A-Fa-f]{6}$/', $roleColor) === 1;
                                    ?>
                                    <span class="dashboard-role-chip"<?= $validRoleColor ? ' style="--dashboard-role-color:' . e($roleColor) . ';"' : ''; ?>><?= e($roleName); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>

                <div class="dashboard-workspace-two-column">
                    <section class="dashboard-workspace-panel">
                        <div class="dashboard-panel-titlebar">
                            <div>
                                <p class="academy-overline">Staff Queue</p>
                                <h3>Things Needing Attention</h3>
                            </div>
                        </div>
                        <div class="dashboard-panel-body">
                            <p>Open reports, pending account actions, grading queues, staff invitations, and other permission-aware tasks will collect here as those systems are connected to the dashboard.</p>
                            <div class="dashboard-placeholder-list">
                                <span>Moderation queue</span>
                                <span>Pending member actions</span>
                                <span>Academic staff tasks</span>
                            </div>
                        </div>
                    </section>

                    <section class="dashboard-workspace-panel">
                        <div class="dashboard-panel-titlebar">
                            <div>
                                <p class="academy-overline">Academy Activity</p>
                                <h3>Recent Administrative Activity</h3>
                            </div>
                        </div>
                        <div class="dashboard-panel-body">
                            <p>Recent audit entries and staff activity summaries will appear here later. This panel is reserved for live staff activity once the remaining management systems are connected.</p>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </section>
</main>
