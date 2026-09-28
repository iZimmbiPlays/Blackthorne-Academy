<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';


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


$displayName = trim(
    (string) (
        $user['display_name']
        ?? $user['username']
        ?? 'Scholar'
    )
);

if ($displayName === '') {
    $displayName = 'Scholar';
}


/*
|--------------------------------------------------------------------------
| Staff Workspace Access
|--------------------------------------------------------------------------
|
| This is intentionally broader than roles.is_staff alone.
|
| Access is granted when the current user is:
|
| - Protected Super Admin
| - An administrator
| - A member of an active role/group marked as Staff
|
| Their actual cards/tools are still permission-driven inside the staff
| dashboard view.
|
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

    // Points, Achievements, Progression, and House Cup
    'points.history.view',
    'points.house.award',
    'points.house.deduct',
    'points.reverse',
    'achievements.manage',
    'achievements.award',
    'progression.rules.manage',
    'house_cup.finalize',

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
| SEO
|--------------------------------------------------------------------------
*/

$pageTitle =
    'Staff Dashboard | Blackthorne Academy';

$pageDescription =
    'Your Blackthorne Academy staff and administration workspace.';

$pageCanonical =
    url('staff-dashboard.php');

$robots =
    'noindex, nofollow';


/*
|--------------------------------------------------------------------------
| Header
|--------------------------------------------------------------------------
*/

require
    INCLUDES_PATH
    . '/header.php';


/*
|--------------------------------------------------------------------------
| Staff Dashboard View
|--------------------------------------------------------------------------
*/

$staffDashboardView =
    __DIR__
    . '/views/dashboards/staff.php';


if (
    !is_file($staffDashboardView)
    ||
    filesize($staffDashboardView) <= 0
) {
    http_response_code(500);

    echo '<main id="main-content" class="dashboard-page">';
    echo '<section class="dashboard-overview">';
    echo '<div class="section-inner">';
    echo '<div class="form-message form-message-error">';
    echo 'The staff dashboard view is currently unavailable.';
    echo '</div>';
    echo '</div>';
    echo '</section>';
    echo '</main>';

    require
        INCLUDES_PATH
        . '/footer.php';

    exit;
}


require $staffDashboardView;


/*
|--------------------------------------------------------------------------
| Footer
|--------------------------------------------------------------------------
*/

require
    INCLUDES_PATH
    . '/footer.php';
