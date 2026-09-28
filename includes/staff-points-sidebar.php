<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Blackthorne Academy - Shared Staff Points & Achievements Sidebar
|--------------------------------------------------------------------------
|
| Optional page variable:
|
|   $pointsSidebarActive = 'achievements';
|
| Supported active keys:
|   overview
|   achievements
|   award-points
|   point-history
|   progression-rules
|   house-cup
|
| This file prepares the variables consumed by:
|   /includes/dashboard-sidebar.php
|
| The sidebar is capability-aware. Protected Super Admin always retains
| access through the existing superuser override.
|
*/

$pointsSidebarActive =
    isset($pointsSidebarActive)
    && is_string($pointsSidebarActive)
        ? trim($pointsSidebarActive)
        : '';


/*
|--------------------------------------------------------------------------
| Capability Context
|--------------------------------------------------------------------------
*/

$pointsSidebarIsSuperAdmin =
    current_user_is_superuser();

$pointsSidebarCanViewHistory =
    $pointsSidebarIsSuperAdmin
    || user_can('points.history.view');

$pointsSidebarCanAwardHousePoints =
    $pointsSidebarIsSuperAdmin
    || user_can('points.house.award');

$pointsSidebarCanDeductHousePoints =
    $pointsSidebarIsSuperAdmin
    || user_can('points.house.deduct');

$pointsSidebarCanReversePoints =
    $pointsSidebarIsSuperAdmin
    || user_can('points.reverse');

$pointsSidebarCanManageAchievements =
    $pointsSidebarIsSuperAdmin
    || user_can('achievements.manage');

$pointsSidebarCanAwardAchievements =
    $pointsSidebarIsSuperAdmin
    || user_can('achievements.award');

$pointsSidebarCanManageProgression =
    $pointsSidebarIsSuperAdmin
    || user_can('progression.rules.manage');

$pointsSidebarCanFinalizeHouseCup =
    $pointsSidebarIsSuperAdmin
    || user_can('house_cup.finalize');

$pointsSidebarCanOpenWorkspace =
    $pointsSidebarCanViewHistory
    || $pointsSidebarCanAwardHousePoints
    || $pointsSidebarCanDeductHousePoints
    || $pointsSidebarCanReversePoints
    || $pointsSidebarCanManageAchievements
    || $pointsSidebarCanAwardAchievements
    || $pointsSidebarCanManageProgression
    || $pointsSidebarCanFinalizeHouseCup;


/*
|--------------------------------------------------------------------------
| Sidebar Header
|--------------------------------------------------------------------------
*/

$dashboardSidebarEyebrow =
    'Academy Records';

$dashboardSidebarTitle =
    'Points & Achievements';


/*
|--------------------------------------------------------------------------
| Navigation
|--------------------------------------------------------------------------
*/

$dashboardSidebarItems = [];

if ($pointsSidebarCanOpenWorkspace) {
    $dashboardSidebarItems[] = [
        'label' => 'Overview',
        'href' => '',
        'icon' => '⌂',
        'disabled' => true,
        'meta' => 'Coming soon',
        'active' =>
            $pointsSidebarActive
            === 'overview',
    ];
}

$achievementChildren = [];

if ($pointsSidebarCanManageAchievements) {
    $achievementChildren[] = [
        'label' => 'Manage Achievements',
        'href' => url('admin/achievements.php'),
        'icon' => '›',
        'active' =>
            $pointsSidebarActive
            === 'achievements',
    ];
}

if ($pointsSidebarCanAwardAchievements) {
    $achievementChildren[] = [
        'label' => 'Award Achievement',
        'href' => '',
        'icon' => '›',
        'disabled' => true,
        'meta' => 'Coming soon',
    ];
}

if ($achievementChildren !== []) {
    $dashboardSidebarItems[] = [
        'type' => 'group',
        'label' => 'Achievements',
        'href' => '',
        'children' => $achievementChildren,
    ];
}

$pointsChildren = [];

if (
    $pointsSidebarCanAwardHousePoints
    || $pointsSidebarCanDeductHousePoints
) {
    $pointsChildren[] = [
        'label' => 'Award / Deduct Points',
        'href' => url('admin/points.php'),
        'icon' => '›',
        'active' =>
            $pointsSidebarActive
            === 'award-points',
    ];
}

if (
    $pointsSidebarCanViewHistory
    || $pointsSidebarCanReversePoints
) {
    $pointsChildren[] = [
        'label' => 'Point History',
        'href' => url('admin/points-history.php'),
        'icon' => '›',
        'active' =>
            $pointsSidebarActive
            === 'point-history',
    ];
}

if ($pointsChildren !== []) {
    $dashboardSidebarItems[] = [
        'type' => 'group',
        'label' => 'Points',
        'href' => '',
        'children' => $pointsChildren,
    ];
}

$progressionChildren = [];

if ($pointsSidebarCanManageProgression) {
    $progressionChildren[] = [
        'label' => 'Progression Rules',
        'href' => url('admin/progression-rules.php'),
        'icon' => '›',
        'active' =>
            $pointsSidebarActive
            === 'progression-rules',
    ];
}

if ($pointsSidebarCanFinalizeHouseCup) {
    $progressionChildren[] = [
        'label' => 'House Cup',
        'href' => '',
        'icon' => '›',
        'disabled' => true,
        'meta' => 'Coming soon',
        'active' =>
            $pointsSidebarActive
            === 'house-cup',
    ];
}

if ($progressionChildren !== []) {
    $dashboardSidebarItems[] = [
        'type' => 'group',
        'label' => 'Year-End Administration',
        'href' => '',
        'children' => $progressionChildren,
    ];
}


/*
|--------------------------------------------------------------------------
| Footer
|--------------------------------------------------------------------------
*/

$dashboardSidebarFooter = [
    [
        'label' => 'Staff Dashboard',
        'href' => url('staff-dashboard.php'),
        'icon' => '←',
    ],
];
