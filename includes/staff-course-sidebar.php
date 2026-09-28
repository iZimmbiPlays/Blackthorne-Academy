<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Blackthorne Academy - Shared Staff Course Sidebar
|--------------------------------------------------------------------------
|
| Optional page variables:
|
|   $courseSidebarActive = 'overview';
|   $courseSidebarEditCourseId = 123;
|
| Supported active keys:
|   overview
|   courses
|   create-course
|   edit-course
|   offerings
|   registration-groups
|   school-years
|   grade-levels
|   prerequisites
|   lessons
|   assignments
|   assessments
|   enrollments
|   grading
|   course-staff
|
| This file prepares the variables consumed by:
|   /includes/dashboard-sidebar.php
|
*/

$courseSidebarActive =
    isset($courseSidebarActive)
    && is_string($courseSidebarActive)
        ? trim($courseSidebarActive)
        : '';

$courseSidebarEditCourseId =
    isset($courseSidebarEditCourseId)
        ? (int) $courseSidebarEditCourseId
        : 0;


/*
|--------------------------------------------------------------------------
| Capability Context
|--------------------------------------------------------------------------
*/

$courseSidebarIsSuperAdmin =
    current_user_is_superuser();

$courseSidebarCanViewCourses =
    $courseSidebarIsSuperAdmin
    || user_can('courses.admin.view')
    || user_can('courses.create')
    || user_can('courses.edit')
    || user_can('courses.publish')
    || user_can('courses.archive');

$courseSidebarCanCreateCourses =
    $courseSidebarIsSuperAdmin
    || user_can('courses.create');

$courseSidebarCanEditCourses =
    $courseSidebarIsSuperAdmin
    || user_can('courses.edit');

$courseSidebarCanManageOfferings =
    $courseSidebarIsSuperAdmin
    || user_can('courses.offerings.manage');

$courseSidebarCanManageRegistrationGroups =
    $courseSidebarIsSuperAdmin
    || user_can('courses.learning_paths.manage');

$courseSidebarCanManageSchoolYears =
    $courseSidebarIsSuperAdmin
    || user_can('enrollments.years.manage');

$courseSidebarCanManageGradeLevels =
    $courseSidebarIsSuperAdmin
    || user_can('enrollments.progression.manage');

$courseSidebarCanManagePrerequisites =
    $courseSidebarIsSuperAdmin
    || user_can('courses.prerequisites.manage');

$courseSidebarCanManageLessons =
    $courseSidebarIsSuperAdmin
    || user_can('lessons.create')
    || user_can('lessons.edit')
    || user_can('lessons.publish')
    || user_can('lessons.release.manage');

$courseSidebarCanManageAssignments =
    $courseSidebarIsSuperAdmin
    || user_can('assignments.create')
    || user_can('assignments.edit')
    || user_can('assignments.publish')
    || user_can('assignments.settings.manage')
    || user_can('assignments.submissions.view')
    || user_can('assignments.grade');

$courseSidebarCanManageAssessments =
    $courseSidebarIsSuperAdmin
    || user_can('assessments.create')
    || user_can('assessments.edit')
    || user_can('assessments.publish')
    || user_can('assessments.settings.manage')
    || user_can('quizzes.create')
    || user_can('quizzes.edit')
    || user_can('quizzes.publish')
    || user_can('quizzes.attempts.view')
    || user_can('quizzes.grade');

$courseSidebarCanManageEnrollments =
    $courseSidebarIsSuperAdmin
    || user_can('enrollments.courses.manage')
    || user_can('enrollments.years.manage')
    || user_can('enrollments.progression.manage');

$courseSidebarCanManageGrading =
    $courseSidebarIsSuperAdmin
    || user_can('grading.assignments')
    || user_can('grading.assessments')
    || user_can('grading.regrade')
    || user_can('grading.settings.manage')
    || user_can('assignments.grade')
    || user_can('quizzes.grade');

$courseSidebarCanManageCourseStaff =
    $courseSidebarIsSuperAdmin
    || user_can('course_staff.view')
    || user_can('course_staff.assign')
    || user_can('course_staff.permissions.manage')
    || user_can('course_staff.invites.manage')
    || user_can('course_staff.notes.private.view')
    || user_can('course_staff.notes.manage');


/*
|--------------------------------------------------------------------------
| Header
|--------------------------------------------------------------------------
*/

$dashboardSidebarEyebrow =
    'Academic Workspace';

$dashboardSidebarTitle =
    'Courses';


/*
|--------------------------------------------------------------------------
| Overview
|--------------------------------------------------------------------------
*/

$dashboardSidebarItems = [
    [
        'label' => 'Overview',
        'href' => url('admin/courses.php'),
        'icon' => '⌂',
        'active' =>
            $courseSidebarActive
            === 'overview',
    ],
];


/*
|--------------------------------------------------------------------------
| Course Management
|--------------------------------------------------------------------------
*/

$courseManagementChildren = [];

if ($courseSidebarCanViewCourses) {
    $courseManagementChildren[] = [
        'label' => 'Courses',
        'href' => url('admin/courses.php'),
        'icon' => '›',
        'active' =>
            $courseSidebarActive
            === 'courses',
    ];
}

if ($courseSidebarCanCreateCourses) {
    $courseManagementChildren[] = [
        'label' => 'Create Course',
        'href' => url('admin/course-create.php'),
        'icon' => '›',
        'active' =>
            $courseSidebarActive
            === 'create-course',
    ];
}

if (
    $courseSidebarEditCourseId > 0
    && $courseSidebarCanEditCourses
) {
    $courseManagementChildren[] = [
        'label' => 'Edit Course',
        'href' => url(
            'admin/course-edit.php?id='
            . $courseSidebarEditCourseId
        ),
        'icon' => '›',
        'active' =>
            $courseSidebarActive
            === 'edit-course',
    ];
}

if ($courseSidebarCanManageOfferings) {
    $courseManagementChildren[] = [
        'label' => 'Course Offerings',
        'href' => url('admin/course-offerings.php'),
        'icon' => '›',
        'active' =>
            $courseSidebarActive
            === 'offerings',
    ];
}

if ($courseSidebarCanManageRegistrationGroups) {
    $courseManagementChildren[] = [
        'label' => 'Registration Groups',
        'href' => url(
            'admin/course-registration-groups.php'
        ),
        'icon' => '›',
        'active' =>
            $courseSidebarActive
            === 'registration-groups',
    ];
}

if ($courseSidebarCanManageSchoolYears) {
    $courseManagementChildren[] = [
        'label' => 'School Years',
        'href' => url('admin/school-years.php'),
        'icon' => '›',
        'active' =>
            $courseSidebarActive
            === 'school-years',
    ];
}

if ($courseSidebarCanManageGradeLevels) {
    $courseManagementChildren[] = [
        'label' => 'Grade Levels',
        'href' => url('admin/grade-levels.php'),
        'icon' => '›',
        'active' =>
            $courseSidebarActive
            === 'grade-levels',
    ];
}

if ($courseSidebarCanManagePrerequisites) {
    $courseManagementChildren[] = [
        'label' => 'Prerequisites',
        'href' => '',
        'icon' => '›',
        'disabled' => true,
        'meta' => 'Coming soon',
        'active' =>
            $courseSidebarActive
            === 'prerequisites',
    ];
}

if ($courseManagementChildren !== []) {
    $dashboardSidebarItems[] = [
        'type' => 'group',
        'label' => 'Course Management',
        'href' => '',
        'children' =>
            $courseManagementChildren,
    ];
}


/*
|--------------------------------------------------------------------------
| Course Content
|--------------------------------------------------------------------------
*/

$courseContentChildren = [];

if ($courseSidebarCanManageLessons) {
    $courseContentChildren[] = [
        'label' => 'Lessons',
        'href' => url('admin/lessons.php'),
        'icon' => '›',
        'active' =>
            $courseSidebarActive
            === 'lessons',
    ];
}

if ($courseSidebarCanManageAssignments) {
    $courseContentChildren[] = [
        'label' => 'Assignments',
        'href' => url('admin/assignments.php'),
        'icon' => '›',
        'active' =>
            $courseSidebarActive
            === 'assignments',
    ];
}

if ($courseSidebarCanManageAssessments) {
    $courseContentChildren[] = [
        'label' => 'Assessments',
        'href' => '',
        'icon' => '›',
        'disabled' => true,
        'meta' => 'Coming soon',
        'active' =>
            $courseSidebarActive
            === 'assessments',
    ];
}

if ($courseContentChildren !== []) {
    $dashboardSidebarItems[] = [
        'type' => 'group',
        'label' => 'Course Content',
        'href' => '',
        'children' =>
            $courseContentChildren,
    ];
}


/*
|--------------------------------------------------------------------------
| Academic Operations
|--------------------------------------------------------------------------
*/

$courseOperationsChildren = [];

if ($courseSidebarCanManageEnrollments) {
    $courseOperationsChildren[] = [
        'label' => 'Enrollments',
        'href' => '',
        'icon' => '›',
        'disabled' => true,
        'meta' => 'Coming soon',
        'active' =>
            $courseSidebarActive
            === 'enrollments',
    ];
}

if ($courseSidebarCanManageGrading) {
    $courseOperationsChildren[] = [
        'label' => 'Grading',
        'href' => '',
        'icon' => '›',
        'disabled' => true,
        'meta' => 'Coming soon',
        'active' =>
            $courseSidebarActive
            === 'grading',
    ];
}

if ($courseSidebarCanManageCourseStaff) {
    $courseOperationsChildren[] = [
        'label' => 'Course Staff',
        'href' => '',
        'icon' => '›',
        'disabled' => true,
        'meta' => 'Coming soon',
        'active' =>
            $courseSidebarActive
            === 'course-staff',
    ];
}

if ($courseOperationsChildren !== []) {
    $dashboardSidebarItems[] = [
        'type' => 'group',
        'label' => 'Academic Operations',
        'href' => '',
        'children' =>
            $courseOperationsChildren,
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
        'icon' => '◇',
        'meta' => 'Return to staff overview',
    ],
    [
        'label' => 'Personal Dashboard',
        'href' => url('dashboard.php'),
        'icon' => '◇',
    ],
    [
        'label' => 'Academy Homepage',
        'href' => url('index.php'),
        'icon' => '⌂',
    ],
];
