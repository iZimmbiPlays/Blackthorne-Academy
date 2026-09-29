<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/house-functions.php';
require_once INCLUDES_PATH . '/forum-functions.php';

require_login();
require_active_account();

$user = current_user();

if ($user === null) {
    redirect(LOGIN_URL);
}

/*
|--------------------------------------------------------------------------
| Personal Dashboard Type
|--------------------------------------------------------------------------
|
| Staff/Admin status never replaces a user's personal Academy dashboard.
| Everyone receives either the student dashboard (when actively enrolled)
| or the registered-member dashboard. Staff access lives separately at
| /staff-dashboard.php and is linked from the dedicated dashboard sidebar.
|
*/

$dashboardType = current_user_is_student()
    ? 'student'
    : 'registered';

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
    'houses.sorting.manage',
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
    || user_can_any($staffDashboardPermissions);

$displayName = trim((string) (
    $user['display_name']
    ?? $user['username']
    ?? 'Scholar'
));

if ($displayName === '') {
    $displayName = 'Scholar';
}

$registeredDashboard = $dashboardType === 'registered';
$dashboardHeroClass = $registeredDashboard
    ? ' dashboard-hero-registered'
    : '';

/*
|--------------------------------------------------------------------------
| Visible Account Designation
|--------------------------------------------------------------------------
*/

if (current_user_is_superuser()) {
    $accountLabel = 'Admin';
} else {
    $activeRoles = current_user_roles();
    $accountLabel = '';

    foreach ($activeRoles as $role) {
        $roleName = trim((string) ($role['name'] ?? ''));

        if ($roleName === '') {
            continue;
        }

        if (
            (int) ($role['is_staff'] ?? 0) === 1
            || (int) ($role['grants_all_permissions'] ?? 0) === 1
        ) {
            $accountLabel = $roleName;
            break;
        }
    }

    if ($accountLabel === '' && !empty($activeRoles)) {
        foreach ($activeRoles as $role) {
            $roleName = trim((string) ($role['name'] ?? ''));

            if ($roleName !== '') {
                $accountLabel = $roleName;
                break;
            }
        }
    }

    if ($accountLabel === '') {
        $accountLabel = $dashboardType === 'student'
            ? 'Current Student'
            : 'Registered Member';
    }
}

$pageTitle = 'Dashboard | Blackthorne Academy';
$pageDescription = 'Your Blackthorne Academy dashboard.';
$pageCanonical = DASHBOARD_URL;
$robots = 'noindex, nofollow';

require INCLUDES_PATH . '/header.php';

$dashboardViews = [
    'registered' => __DIR__ . '/views/dashboards/registered.php',
    'student' => __DIR__ . '/views/dashboards/student.php',
];

$dashboardView = $dashboardViews[$dashboardType] ?? null;

if (!is_string($dashboardView) || !is_file($dashboardView) || filesize($dashboardView) <= 0) {
    http_response_code(500);
    ?>
<main id="main-content" class="dashboard-page">
    <section class="dashboard-overview">
        <div class="section-inner">
            <div class="form-message form-message-error">
                Your dashboard is currently unavailable.
            </div>
        </div>
    </section>
</main>
<?php
    require INCLUDES_PATH . '/footer.php';
    exit;
}

require $dashboardView;
require INCLUDES_PATH . '/footer.php';
