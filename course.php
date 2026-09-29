<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/course-lesson-progress.php';
require_once __DIR__ . '/includes/course-assignment-submissions.php';

require_login();
require_active_account();

$userId =
    (int) (
        current_user_id()
        ?? 0
    );

$offeringId =
    filter_input(
        INPUT_GET,
        'offering',
        FILTER_VALIDATE_INT
    );

$offeringId =
    is_int($offeringId) && $offeringId > 0
        ? $offeringId
        : 0;


/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function student_course_format_datetime(
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


function student_course_reason_label(
    string $reason
): string {
    $labels = [
        'available' =>
            'Available',

        'release_disabled' =>
            'Available',

        'immediate' =>
            'Available',

        'course_start_reached' =>
            'Available',

        'fixed_date_reached' =>
            'Available',

        'previous_lesson_completed' =>
            'Available',

        'legacy_enrollment_delay_met' =>
            'Available',

        'waiting_for_course_start' =>
            'Not yet available',

        'waiting_for_fixed_date' =>
            'Not yet available',

        'waiting_for_previous_lesson' =>
            'Complete the previous lesson first',

        'waiting_for_legacy_enrollment_delay' =>
            'Not yet available',

        'lesson_unpublished' =>
            'Not published',

        'lesson_version_unpublished' =>
            'Not published',

        'offering_archived' =>
            'Unavailable',

        'course_access_ended' =>
            'Course access ended',

        'enrollment_suspended' =>
            'Enrollment suspended',

        'enrollment_inactive' =>
            'Enrollment inactive',
    ];

    return $labels[$reason]
        ?? 'Unavailable';
}


/*
|--------------------------------------------------------------------------
| Validate Offering Enrollment
|--------------------------------------------------------------------------
*/

if (
    $userId <= 0
    || $offeringId <= 0
) {
    http_response_code(404);

    $pageTitle =
        'Course Not Found | Blackthorne Academy';

    $pageDescription =
        'The requested course could not be found.';

    $pageCanonical =
        url('courses.php');

    $robots =
        'noindex, nofollow';

    require INCLUDES_PATH . '/header.php';
    ?>

<main id="main-content" class="dashboard-page">
    <section class="section-inner">
        <div class="dashboard-workspace-panel">
            <div class="dashboard-panel-body">
                <h1>Course Not Found</h1>
                <p>
                    The requested course could not be found.
                </p>
            </div>
        </div>
    </section>
</main>

<?php
    require INCLUDES_PATH . '/footer.php';
    exit;
}

$enrollment =
    blackthorne_course_enrollment(
        $pdo,
        $userId,
        $offeringId
    );

if ($enrollment === null) {
    http_response_code(403);

    $pageTitle =
        'Course Unavailable | Blackthorne Academy';

    $pageDescription =
        'This course is not available for your account.';

    $pageCanonical =
        url('courses.php');

    $robots =
        'noindex, nofollow';

    require INCLUDES_PATH . '/header.php';
    ?>

<main id="main-content" class="dashboard-page">
    <section class="section-inner">
        <div class="dashboard-workspace-panel">
            <div class="dashboard-panel-body">
                <h1>Course Unavailable</h1>
                <p>
                    You are not enrolled in this course offering.
                </p>

                <a class="button button-secondary" href="<?= e(url('courses.php')); ?>">
                    Back to My Courses
                </a>
            </div>
        </div>
    </section>
</main>

<?php
    require INCLUDES_PATH . '/footer.php';
    exit;
}


/*
|--------------------------------------------------------------------------
| Course / Offering Metadata
|--------------------------------------------------------------------------
*/

$courseStatement =
    $pdo->prepare(
        '
        SELECT
            co.id AS offering_id,
            co.course_id,
            co.pacing_mode,
            co.drip_basis,
            co.course_start_date,
            co.course_end_date,
            co.status AS offering_status,

            c.title,
            c.slug,
            c.course_code,
            c.short_description,
            c.description,
            c.course_image,
            c.course_forum_id,
            c.status AS course_status,

            sy.name AS school_year_name,
            sy.course_access_ends_at

        FROM course_offerings co

        INNER JOIN courses c
            ON c.id = co.course_id

        LEFT JOIN school_years sy
            ON sy.id = co.school_year_id

        WHERE co.id = :offering_id

        LIMIT 1
        '
    );

$courseStatement->execute([
    'offering_id' =>
        $offeringId,
]);

$course =
    $courseStatement->fetch(
        PDO::FETCH_ASSOC
    );

if (!is_array($course)) {
    http_response_code(404);

    $pageTitle =
        'Course Not Found | Blackthorne Academy';

    $pageDescription =
        'The requested course could not be found.';

    $pageCanonical =
        url('courses.php');

    $robots =
        'noindex, nofollow';

    require INCLUDES_PATH . '/header.php';
    ?>

<main id="main-content" class="dashboard-page">
    <section class="section-inner">
        <div class="dashboard-workspace-panel">
            <div class="dashboard-panel-body">
                <h1>Course Not Found</h1>
                <p>
                    The requested course offering no longer exists.
                </p>
            </div>
        </div>
    </section>
</main>

<?php
    require INCLUDES_PATH . '/footer.php';
    exit;
}


/*
|--------------------------------------------------------------------------
| Lessons
|--------------------------------------------------------------------------
*/

$lessons =
    blackthorne_offering_lessons_for_user(
        $pdo,
        $userId,
        $offeringId
    );

$lessonSummary =
    blackthorne_lesson_progress_summary(
        $pdo,
        (int) $enrollment['id'],
        $offeringId
    );


/*
|--------------------------------------------------------------------------
| Assignments
|--------------------------------------------------------------------------
*/

$assignmentStatement =
    $pdo->prepare(
        '
        SELECT
            aos.assignment_id,
            aos.assignment_version_id,
            aos.course_offering_lesson_id,
            aos.points_possible,
            aos.due_date,
            aos.submission_close_date,
            aos.allow_resubmissions,
            aos.max_submissions,
            aos.allow_late_submissions,

            a.internal_name,
            a.is_published,

            av.title,
            av.description,
            av.status AS version_status,

            lv.title AS lesson_title

        FROM assignment_offering_settings aos

        INNER JOIN assignments a
            ON a.id = aos.assignment_id

        INNER JOIN assignment_versions av
            ON av.id = aos.assignment_version_id

        LEFT JOIN course_offering_lessons col
            ON col.id = aos.course_offering_lesson_id
           AND col.offering_id = aos.offering_id

        LEFT JOIN lesson_versions lv
            ON lv.id = col.lesson_version_id

        WHERE aos.offering_id = :offering_id
          AND a.is_published = 1
          AND av.status = "published"

        ORDER BY
            CASE
                WHEN aos.due_date IS NULL
                THEN 1
                ELSE 0
            END ASC,
            aos.due_date ASC,
            aos.assignment_id ASC
        '
    );

$assignmentStatement->execute([
    'offering_id' =>
        $offeringId,
]);

$assignments =
    $assignmentStatement->fetchAll(
        PDO::FETCH_ASSOC
    );

$assignmentCards = [];

foreach ($assignments as $assignmentRow) {
    $assignmentId =
        (int) $assignmentRow[
            'assignment_id'
        ];

    $assignmentContext =
        blackthorne_assignment_context(
            $pdo,
            $userId,
            $offeringId,
            $assignmentId
        );

    if ($assignmentContext === null) {
        continue;
    }

    $availability =
        blackthorne_assignment_submission_availability(
            $pdo,
            $assignmentContext
        );

    $summary =
        blackthorne_assignment_submission_summary(
            $pdo,
            (int) $assignmentContext[
                'enrollment_id'
            ],
            $offeringId,
            $assignmentId
        );

    $assignmentRow['availability'] =
        $availability;

    $assignmentRow['summary'] =
        $summary;

    $assignmentCards[] =
        $assignmentRow;
}


/*
|--------------------------------------------------------------------------
| Course Staff
|--------------------------------------------------------------------------
*/

$courseStaffStatement =
    $pdo->prepare(
        '
        SELECT
            ci.user_id,
            ci.instructor_role,
            u.display_name,
            u.username,
            u.profile_slug,
            u.avatar

        FROM course_instructors ci

        INNER JOIN users u
            ON u.id = ci.user_id

        WHERE ci.offering_id = :offering_id
          AND ci.is_active = 1
          AND ci.ended_at IS NULL
          AND u.status = "active"

        ORDER BY
            CASE ci.instructor_role
                WHEN "primary" THEN 1
                WHEN "co_instructor" THEN 2
                ELSE 3
            END ASC,
            u.display_name ASC,
            u.username ASC
        '
    );

$courseStaffStatement->execute([
    'offering_id' =>
        $offeringId,
]);

$courseStaff =
    $courseStaffStatement->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Dedicated Course Forum / Pinned Announcements
|--------------------------------------------------------------------------
*/

$courseForum = null;
$courseAnnouncements = [];

$courseForumId =
    (int) (
        $course[
            'course_forum_id'
        ]
        ?? 0
    );

if ($courseForumId > 0) {
    $courseForumStatement =
        $pdo->prepare(
            '
            SELECT
                f.id,
                f.title,
                f.description

            FROM forums f

            INNER JOIN forum_categories fc
                ON fc.id = f.category_id

            WHERE f.id = :forum_id
              AND f.is_visible = 1
              AND fc.is_visible = 1

            LIMIT 1
            '
        );

    $courseForumStatement->execute([
        'forum_id' =>
            $courseForumId,
    ]);

    $courseForum =
        $courseForumStatement->fetch(
            PDO::FETCH_ASSOC
        );

    if (is_array($courseForum)) {
        $announcementStatement =
            $pdo->prepare(
                '
                SELECT
                    ft.id,
                    ft.title,
                    ft.created_at,
                    ft.last_activity_at,
                    u.display_name AS author_display_name,
                    u.username AS author_username,
                    (
                        SELECT fp.content
                        FROM forum_posts fp
                        WHERE fp.thread_id = ft.id
                          AND fp.is_deleted = 0
                        ORDER BY
                            fp.created_at ASC,
                            fp.id ASC
                        LIMIT 1
                    ) AS first_post_content

                FROM forum_threads ft

                INNER JOIN users u
                    ON u.id = ft.user_id

                WHERE ft.forum_id = :forum_id
                  AND ft.is_pinned = 1
                  AND ft.is_deleted = 0

                ORDER BY
                    ft.last_activity_at DESC,
                    ft.id DESC

                LIMIT 5
                '
            );

        $announcementStatement->execute([
            'forum_id' =>
                $courseForumId,
        ]);

        $courseAnnouncements =
            $announcementStatement->fetchAll(
                PDO::FETCH_ASSOC
            );
    }
}


/*
|--------------------------------------------------------------------------
| Continue Learning
|--------------------------------------------------------------------------
*/

$continueLesson = null;

foreach ($lessons as $lessonRow) {
    $lessonAvailable =
        (bool) (
            $lessonRow[
                'available'
            ]
            ?? false
        );

    $lessonStatus =
        (string) (
            $lessonRow[
                'progress_status'
            ]
            ?? 'not_started'
        );

    if (
        $lessonAvailable
        && $lessonStatus !== 'completed'
    ) {
        $continueLesson =
            $lessonRow;

        break;
    }
}

if (
    $continueLesson === null
    && $lessons !== []
) {
    foreach ($lessons as $lessonRow) {
        if (
            (bool) (
                $lessonRow[
                    'available'
                ]
                ?? false
            )
        ) {
            $continueLesson =
                $lessonRow;

            break;
        }
    }
}


/*
|--------------------------------------------------------------------------
| Counts / Course Status
|--------------------------------------------------------------------------
*/

$availableLessonCount = 0;
$lockedLessonCount = 0;

foreach ($lessons as $lessonRow) {
    if (
        (bool) (
            $lessonRow[
                'available'
            ]
            ?? false
        )
    ) {
        $availableLessonCount++;
    } else {
        $lockedLessonCount++;
    }
}

$submittedAssignmentCount = 0;
$gradedAssignmentCount = 0;
$openAssignmentCount = 0;

foreach ($assignmentCards as $assignmentRow) {
    $summary =
        $assignmentRow['summary'];

    if (
        (int) (
            $summary[
                'graded_count'
            ]
            ?? 0
        ) > 0
    ) {
        $gradedAssignmentCount++;
    } elseif (
        (int) (
            $summary[
                'submitted_count'
            ]
            ?? 0
        ) > 0
    ) {
        $submittedAssignmentCount++;
    }

    if (
        (bool) (
            $assignmentRow[
                'availability'
            ][
                'can_submit'
            ]
            ?? false
        )
    ) {
        $openAssignmentCount++;
    }
}


$overallCourseProgress =
    (float) (
        $enrollment[
            'progress'
        ]
        ?? $lessonSummary[
            'percent'
        ]
        ?? 0
    );

$overallCourseProgress =
    max(
        0,
        min(
            100,
            $overallCourseProgress
        )
    );


/*
|--------------------------------------------------------------------------
| Image
|--------------------------------------------------------------------------
*/

$courseImage =
    trim(
        (string) (
            $course['course_image']
            ?? ''
        )
    );

$courseImageUrl = '';
$courseImageWebpUrl = '';

if ($courseImage !== '') {
    $normalizedCourseImage =
        ltrim(
            $courseImage,
            '/'
        );

    $courseImageUrl =
        url(
            $normalizedCourseImage
        );

    $extension =
        strtolower(
            pathinfo(
                $normalizedCourseImage,
                PATHINFO_EXTENSION
            )
        );

    if (
        $extension !== ''
        && $extension !== 'webp'
    ) {
        $webpReference =
            substr(
                $normalizedCourseImage,
                0,
                -strlen($extension)
            )
            . 'webp';

        if (
            is_file(
                __DIR__
                . '/'
                . $webpReference
            )
        ) {
            $courseImageWebpUrl =
                url(
                    $webpReference
                );
        }
    }
}


/*
|--------------------------------------------------------------------------
| Page Metadata
|--------------------------------------------------------------------------
*/

$courseTitle =
    (string) (
        $course['title']
        ?? 'Course'
    );

$pageTitle =
    $courseTitle
    . ' | Blackthorne Academy';

$pageDescription =
    trim(
        (string) (
            $course[
                'short_description'
            ]
            ?? ''
        )
    );

if ($pageDescription === '') {
    $pageDescription =
        'View lessons, assignments, and course progress for '
        . $courseTitle
        . '.';
}

$pageCanonical =
    url(
        'course.php?offering='
        . $offeringId
    );

$robots =
    'noindex, nofollow';

require INCLUDES_PATH . '/header.php';

?>

<main id="main-content" class="dashboard-page course-page">

    <section class="dashboard-workspace-section">
        <div class="section-inner dashboard-workspace-layout">

            <?php
            require
                INCLUDES_PATH
                . '/member-sidebar.php';
            ?>

            <div class="dashboard-workspace-main">

                <section class="dashboard-workspace-panel">

                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">
                                <?= e(
                                    trim(
                                        (string) (
                                            $course[
                                                'course_code'
                                            ]
                                            ?? ''
                                        )
                                    ) !== ''
                                        ? (string) $course[
                                            'course_code'
                                        ]
                                        : 'Blackthorne Academy Course'
                                ); ?>
                            </p>

                            <h2>
                                <?= e($courseTitle); ?>
                            </h2>
                        </div>
                    </div>

                    <div class="dashboard-panel-body">

                        <?php
                        $courseShortDescription =
                            trim(
                                (string) (
                                    $course[
                                        'short_description'
                                    ]
                                    ?? ''
                                )
                            );
                        ?>

                        <?php if ($courseShortDescription !== ''): ?>
                        <p class="course-overview-intro">
                            <?= e($courseShortDescription); ?>
                        </p>
                        <?php endif; ?>

                        <?php if ($courseImageUrl !== ''): ?>
                        <div class="course-overview-image">
                            <picture>
                                <?php if (
                                        $courseImageWebpUrl
                                        !== ''
                                    ): ?>
                                <source srcset="<?= e(
                                                $courseImageWebpUrl
                                            ); ?>" type="image/webp">
                                <?php endif; ?>

                                <img src="<?= e(
                                            $courseImageUrl
                                        ); ?>" alt="">
                            </picture>
                        </div>
                        <?php endif; ?>

                        <?php if (
                            trim(
                                (string) (
                                    $course[
                                        'description'
                                    ]
                                    ?? ''
                                )
                            ) !== ''
                        ): ?>

                        <div class="forum-post-content">
                            <?= nl2br(
                                    e(
                                        (string) $course[
                                            'description'
                                        ]
                                    )
                                ); ?>
                        </div>

                        <?php endif; ?>


                        <div class="course-overview-pills">

                            <?php if (
                                trim(
                                    (string) (
                                        $course[
                                            'school_year_name'
                                        ]
                                        ?? ''
                                    )
                                ) !== ''
                            ): ?>
                            <span class="course-overview-pill">
                                <small>School Year</small>
                                <strong>
                                    <?= e(
                                            (string) $course[
                                                'school_year_name'
                                            ]
                                        ); ?>
                                </strong>
                            </span>
                            <?php endif; ?>

                            <span class="course-overview-pill">
                                <small>Pacing</small>
                                <strong>
                                    <?= e(
                                        (string) (
                                            $course[
                                                'pacing_mode'
                                            ]
                                            ?? ''
                                        ) === 'drip'
                                            ? 'Drip Content'
                                            : 'Self-Paced'
                                    ); ?>
                                </strong>
                            </span>

                            <span class="course-overview-pill">
                                <small>Starts</small>
                                <strong>
                                    <?= e(
                                        student_course_format_datetime(
                                            $course[
                                                'course_start_date'
                                            ]
                                            ?? null
                                        )
                                    ); ?>
                                </strong>
                            </span>

                            <span class="course-overview-pill">
                                <small>Ends</small>
                                <strong>
                                    <?= e(
                                        student_course_format_datetime(
                                            $course[
                                                'course_end_date'
                                            ]
                                            ?? null
                                        )
                                    ); ?>
                                </strong>
                            </span>

                            <span class="course-overview-pill">
                                <small>Enrollment</small>
                                <strong>
                                    <?= e(
                                        ucfirst(
                                            (string) (
                                                $enrollment[
                                                    'status'
                                                ]
                                                ?? 'enrolled'
                                            )
                                        )
                                    ); ?>
                                </strong>
                            </span>

                        </div>

                    </div>

                </section>


                <section class="course-classroom-home" aria-label="Course classroom dashboard">
                    <div class="course-classroom-primary">

                        <article class="course-classroom-card course-continue-card">
                            <div class="course-classroom-card-head">
                                <div>
                                    <p class="academy-overline">
                                        Continue Learning
                                    </p>

                                    <h2>
                                        <?php if ($continueLesson !== null): ?>
                                        <?= e(
                                                (string) (
                                                    $continueLesson[
                                                        'title'
                                                    ]
                                                    ?? $continueLesson[
                                                        'internal_name'
                                                    ]
                                                    ?? 'Next Lesson'
                                                )
                                            ); ?>
                                        <?php elseif (
                                            (
                                                $enrollment[
                                                    'status'
                                                ]
                                                ?? ''
                                            ) === 'completed'
                                        ): ?>
                                        Course Complete
                                        <?php else: ?>
                                        Classroom Ready
                                        <?php endif; ?>
                                    </h2>
                                </div>
                            </div>

                            <?php if ($continueLesson !== null): ?>
                            <p>
                                <?php
                                    $continueDescription =
                                        trim(
                                            (string) (
                                                $continueLesson[
                                                    'description'
                                                ]
                                                ?? ''
                                            )
                                        );
                                    ?>

                                <?= $continueDescription !== ''
                                        ? e($continueDescription)
                                        : 'Pick up where you left off and continue through the course in order.'; ?>
                            </p>

                            <div class="course-classroom-actions">
                                <a class="button button-primary" href="<?= e(
                                            url(
                                                'lesson.php?offering='
                                                . $offeringId
                                                . '&lesson='
                                                . (int) $continueLesson[
                                                    'lesson_id'
                                                ]
                                            )
                                        ); ?>">
                                    <?= (
                                            $continueLesson[
                                                'progress_status'
                                            ]
                                            ?? 'not_started'
                                        ) === 'in_progress'
                                            ? 'Continue Lesson'
                                            : 'Open Lesson'; ?>
                                </a>

                                <?php if ($courseForum !== null): ?>
                                <a class="button button-secondary" href="<?= e(
                                                url(
                                                    'forum.php?f='
                                                    . (int) $courseForum[
                                                        'id'
                                                    ]
                                                )
                                            ); ?>">
                                    Course Forum
                                </a>
                                <?php endif; ?>
                            </div>
                            <?php elseif (
                                (
                                    $enrollment[
                                        'status'
                                    ]
                                    ?? ''
                                ) === 'completed'
                            ): ?>
                            <p>
                                You have completed this course. Your lessons
                                remain available for review while your course
                                access is active.
                            </p>
                            <?php else: ?>
                            <p>
                                No lesson is currently available to open.
                                Check the lesson list below for release timing
                                or prerequisites.
                            </p>
                            <?php endif; ?>
                        </article>

                        <article class="course-classroom-card course-progress-card">
                            <div class="course-classroom-card-head">
                                <div>
                                    <p class="academy-overline">
                                        Overall Progress
                                    </p>

                                    <h2>
                                        <?= e(
                                            number_format(
                                                $overallCourseProgress,
                                                0
                                            )
                                        ); ?>%
                                    </h2>
                                </div>
                            </div>

                            <div class="course-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100"
                                aria-valuenow="<?= e(
                                    number_format(
                                        $overallCourseProgress,
                                        0
                                    )
                                ); ?>" aria-label="Overall course progress">
                                <span class="course-progress-fill" style="width: <?= e(
                                        number_format(
                                            $overallCourseProgress,
                                            2,
                                            '.',
                                            ''
                                        )
                                    ); ?>%;"></span>
                            </div>

                            <div class="course-progress-summary">
                                <span>
                                    <strong>
                                        <?= number_format(
                                            (int) (
                                                $lessonSummary[
                                                    'completed'
                                                ]
                                                ?? 0
                                            )
                                        ); ?>
                                    </strong>
                                    of
                                    <strong>
                                        <?= number_format(
                                            (int) (
                                                $lessonSummary[
                                                    'total'
                                                ]
                                                ?? 0
                                            )
                                        ); ?>
                                    </strong>
                                    lessons complete
                                </span>

                                <span>
                                    <strong>
                                        <?= number_format(
                                            $openAssignmentCount
                                        ); ?>
                                    </strong>
                                    open assignment<?= $openAssignmentCount === 1
                                        ? ''
                                        : 's'; ?>
                                </span>
                            </div>
                        </article>

                    </div>

                    <div class="course-classroom-secondary">

                        <article class="course-classroom-card">
                            <div class="course-classroom-card-head">
                                <div>
                                    <p class="academy-overline">
                                        Course Staff
                                    </p>

                                    <h2>
                                        Instructor Team
                                    </h2>
                                </div>
                            </div>

                            <?php if ($courseStaff === []): ?>
                            <p class="course-classroom-muted">
                                No instructor has been assigned to this
                                offering yet.
                            </p>
                            <?php else: ?>
                            <div class="course-staff-list">
                                <?php foreach ($courseStaff as $staffMember): ?>
                                <?php
                                        $staffDisplayName =
                                            trim(
                                                (string) (
                                                    $staffMember[
                                                        'display_name'
                                                    ]
                                                    ?? $staffMember[
                                                        'username'
                                                    ]
                                                    ?? 'Staff Member'
                                                )
                                            );

                                        $staffRole =
                                            match (
                                                (string) (
                                                    $staffMember[
                                                        'instructor_role'
                                                    ]
                                                    ?? ''
                                                )
                                            ) {
                                                'primary' =>
                                                    'Primary Instructor',

                                                'co_instructor' =>
                                                    'Co-Instructor',

                                                default =>
                                                    'Teaching Assistant',
                                            };

                                        $staffProfileSlug =
                                            trim(
                                                (string) (
                                                    $staffMember[
                                                        'profile_slug'
                                                    ]
                                                    ?? ''
                                                )
                                            );
                                        ?>

                                <div class="course-staff-member">
                                    <div>
                                        <?php if ($staffProfileSlug !== ''): ?>
                                        <a href="<?= e(
                                                            url(
                                                                'profile.php?u='
                                                                . rawurlencode(
                                                                    $staffProfileSlug
                                                                )
                                                            )
                                                        ); ?>">
                                            <?= e($staffDisplayName); ?>
                                        </a>
                                        <?php else: ?>
                                        <strong>
                                            <?= e($staffDisplayName); ?>
                                        </strong>
                                        <?php endif; ?>

                                        <span>
                                            <?= e($staffRole); ?>
                                        </span>
                                    </div>
                                </div>

                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </article>

                        <article class="course-classroom-card">
                            <div class="course-classroom-card-head">
                                <div>
                                    <p class="academy-overline">
                                        Course Forum
                                    </p>

                                    <h2>
                                        Pinned Announcements
                                    </h2>
                                </div>
                            </div>

                            <?php if ($courseAnnouncements === []): ?>
                            <p class="course-classroom-muted">
                                <?php if ($courseForum === null): ?>
                                No dedicated course forum has been assigned yet.
                                <?php else: ?>
                                No pinned announcement threads have been posted yet.
                                <?php endif; ?>
                            </p>
                            <?php else: ?>
                            <div class="course-announcement-list">
                                <?php foreach ($courseAnnouncements as $announcement): ?>
                                <article class="course-announcement-item">
                                    <h3>
                                        <a href="<?= e(
                                                        url(
                                                            'thread.php?t='
                                                            . (int) (
                                                                $announcement[
                                                                    'id'
                                                                ]
                                                                ?? 0
                                                            )
                                                        )
                                                    ); ?>">
                                            <?= e(
                                                        (string) (
                                                            $announcement[
                                                                'title'
                                                            ]
                                                            ?? 'Announcement'
                                                        )
                                                    ); ?>
                                        </a>
                                    </h3>

                                    <p class="course-announcement-meta">
                                        <?= e(
                                                    student_course_format_datetime(
                                                        $announcement[
                                                            'last_activity_at'
                                                        ]
                                                        ?? $announcement[
                                                            'created_at'
                                                        ]
                                                        ?? null
                                                    )
                                                ); ?>

                                        <?php
                                                $announcementAuthor =
                                                    trim(
                                                        (string) (
                                                            $announcement[
                                                                'author_display_name'
                                                            ]
                                                            ?? $announcement[
                                                                'author_username'
                                                            ]
                                                            ?? ''
                                                        )
                                                    );
                                                ?>

                                        <?php if ($announcementAuthor !== ''): ?>
                                        · <?= e($announcementAuthor); ?>
                                        <?php endif; ?>
                                    </p>

                                    <?php
                                            $announcementContent =
                                                trim(
                                                    strip_tags(
                                                        (string) (
                                                            $announcement[
                                                                'first_post_content'
                                                            ]
                                                            ?? ''
                                                        )
                                                    )
                                                );
                                            ?>

                                    <?php if ($announcementContent !== ''): ?>
                                    <p>
                                        <?= nl2br(
                                                        e(
                                                            mb_strimwidth(
                                                                $announcementContent,
                                                                0,
                                                                320,
                                                                '…',
                                                                'UTF-8'
                                                            )
                                                        )
                                                    ); ?>
                                    </p>
                                    <?php endif; ?>
                                </article>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </article>

                    </div>
                </section>


                <section class="dashboard-workspace-panel">

                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">
                                Progress
                            </p>

                            <h2>
                                Course Snapshot
                            </h2>
                        </div>
                    </div>

                    <div class="dashboard-panel-body">

                        <div class="course-snapshot-visual">
                            <div class="course-snapshot-progress">
                                <div class="course-snapshot-progress-head">
                                    <span>Lesson Progress</span>
                                    <strong>
                                        <?= e(
                                            number_format(
                                                (float) $lessonSummary[
                                                    'percent'
                                                ],
                                                0
                                            )
                                        ); ?>%
                                    </strong>
                                </div>

                                <div class="course-progress-track" role="progressbar" aria-valuemin="0"
                                    aria-valuemax="100" aria-valuenow="<?= e(
                                        number_format(
                                            (float) $lessonSummary[
                                                'percent'
                                            ],
                                            0
                                        )
                                    ); ?>" aria-label="Lesson progress">
                                    <span class="course-progress-fill" style="width: <?= e(
                                            number_format(
                                                (float) $lessonSummary[
                                                    'percent'
                                                ],
                                                2,
                                                '.',
                                                ''
                                            )
                                        ); ?>%;"></span>
                                </div>
                            </div>

                            <div class="course-snapshot-grid">
                                <div class="course-snapshot-stat">
                                    <strong><?= number_format((int) $lessonSummary['completed']); ?>/<?= number_format((int) $lessonSummary['total']); ?></strong>
                                    <span>Lessons Complete</span>
                                </div>

                                <div class="course-snapshot-stat">
                                    <strong><?= number_format($availableLessonCount); ?></strong>
                                    <span>Available Now</span>
                                </div>

                                <div class="course-snapshot-stat">
                                    <strong><?= number_format(count($assignmentCards)); ?></strong>
                                    <span>Total Assignments</span>
                                </div>

                                <div class="course-snapshot-stat">
                                    <strong><?= number_format($openAssignmentCount); ?></strong>
                                    <span>Open Assignments</span>
                                </div>

                                <div class="course-snapshot-stat">
                                    <strong><?= number_format($gradedAssignmentCount); ?></strong>
                                    <span>Graded</span>
                                </div>

                                <div class="course-snapshot-stat">
                                    <strong><?= e(number_format($overallCourseProgress, 0)); ?>%</strong>
                                    <span>Overall Course</span>
                                </div>
                            </div>
                        </div>

                        <p class="form-help course-snapshot-note">
                            Overall progress can include lessons, assignments,
                            and assessments, so it may differ from lesson progress.
                        </p>

                    </div>

                </section>


                <section class="dashboard-workspace-panel">

                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">
                                Course Content
                            </p>

                            <h2>
                                Lessons
                            </h2>
                        </div>
                    </div>

                    <div class="dashboard-panel-body">

                        <?php if ($lessons === []): ?>

                        <p>
                            No lessons have been added to this course offering yet.
                        </p>

                        <?php else: ?>

                        <div class="course-lesson-list">

                            <?php foreach ($lessons as $lessonRow): ?>
                            <?php
                                    $lessonAvailable =
                                        (bool) (
                                            $lessonRow[
                                                'available'
                                            ]
                                            ?? false
                                        );

                                    $lessonStatus =
                                        (string) (
                                            $lessonRow[
                                                'progress_status'
                                            ]
                                            ?? 'not_started'
                                        );

                                    $availabilityReason =
                                        (string) (
                                            $lessonRow[
                                                'availability_reason'
                                            ]
                                            ?? ''
                                        );

                                    $lessonTitle =
                                        (string) (
                                            $lessonRow[
                                                'title'
                                            ]
                                            ?? $lessonRow[
                                                'internal_name'
                                            ]
                                            ?? 'Lesson'
                                        );

                                    $lessonDescription =
                                        trim(
                                            (string) (
                                                $lessonRow[
                                                    'description'
                                                ]
                                                ?? ''
                                            )
                                        );
                                    ?>

                            <article class="course-lesson-row <?= $lessonAvailable
                                            ? 'is-available'
                                            : 'is-locked'; ?>">
                                <div class="course-lesson-number">
                                    <?= number_format(
                                                (int) (
                                                    $lessonRow[
                                                        'sort_order'
                                                    ]
                                                    ?? 0
                                                )
                                            ); ?>
                                </div>

                                <div class="course-lesson-copy">
                                    <div class="course-lesson-heading">
                                        <h3><?= e($lessonTitle); ?></h3>

                                        <div class="course-lesson-badges">
                                            <span class="course-lesson-badge">
                                                <?= e(
                                                            ucwords(
                                                                str_replace(
                                                                    '_',
                                                                    ' ',
                                                                    $lessonStatus
                                                                )
                                                            )
                                                        ); ?>
                                            </span>

                                            <span class="course-lesson-badge">
                                                <?= e(
                                                            student_course_reason_label(
                                                                $availabilityReason
                                                            )
                                                        ); ?>
                                            </span>
                                        </div>
                                    </div>

                                    <?php if ($lessonDescription !== ''): ?>
                                    <p><?= e($lessonDescription); ?></p>
                                    <?php endif; ?>

                                    <?php if (
                                                !$lessonAvailable
                                                && !empty($lessonRow['unlocks_at'])
                                            ): ?>
                                    <p class="course-lesson-unlock">
                                        Opens
                                        <?= e(
                                                        student_course_format_datetime(
                                                            $lessonRow[
                                                                'unlocks_at'
                                                            ]
                                                        )
                                                    ); ?>
                                    </p>
                                    <?php endif; ?>
                                </div>

                                <div class="course-lesson-action">
                                    <?php if ($lessonAvailable): ?>
                                    <a class="button button-secondary" href="<?= e(
                                                        url(
                                                            'lesson.php?offering='
                                                            . $offeringId
                                                            . '&lesson='
                                                            . (int) $lessonRow[
                                                                'lesson_id'
                                                            ]
                                                        )
                                                    ); ?>">
                                        <?= $lessonStatus === 'completed'
                                                        ? 'Review'
                                                        : 'Open Lesson'; ?>
                                    </a>
                                    <?php else: ?>
                                    <span class="course-lesson-lock">
                                        Locked
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </article>
                            <?php endforeach; ?>

                        </div>

                        <?php endif; ?>

                    </div>

                </section>


                <section class="dashboard-workspace-panel">

                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">
                                Coursework
                            </p>

                            <h2>
                                Assignments
                            </h2>
                        </div>
                    </div>

                    <div class="dashboard-panel-body">

                        <?php if (
                            $assignmentCards === []
                        ): ?>

                        <p>
                            No published assignments are available for this course yet.
                        </p>

                        <?php else: ?>

                        <div class="dashboard-placeholder-list">

                            <?php foreach (
                                    $assignmentCards
                                    as $assignmentRow
                                ): ?>
                            <?php
                                    $assignmentAvailability =
                                        $assignmentRow[
                                            'availability'
                                        ];

                                    $assignmentSummary =
                                        $assignmentRow[
                                            'summary'
                                        ];

                                    $latestAttempt =
                                        $assignmentSummary[
                                            'latest_attempt'
                                        ]
                                        ?? null;

                                    $assignmentStatus =
                                        is_array(
                                            $latestAttempt
                                        )
                                            ? ucfirst(
                                                (string) (
                                                    $latestAttempt[
                                                        'status'
                                                    ]
                                                    ?? 'draft'
                                                )
                                            )
                                            : 'Not Started';

                                    $canOpenAssignment =
                                        (
                                            (bool) (
                                                $assignmentAvailability[
                                                    'available'
                                                ]
                                                ?? false
                                            )
                                            || is_array(
                                                $latestAttempt
                                            )
                                        );
                                    ?>

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

                                ·
                                <?= e(
                                            $assignmentStatus
                                        ); ?>

                                <?php if (
                                            !empty(
                                                $assignmentRow[
                                                    'due_date'
                                                ]
                                            )
                                        ): ?>
                                · Due
                                <?= e(
                                                student_course_format_datetime(
                                                    $assignmentRow[
                                                        'due_date'
                                                    ]
                                                )
                                            ); ?>
                                <?php endif; ?>

                                <?php if (
                                            (bool) (
                                                $assignmentAvailability[
                                                    'is_late'
                                                ]
                                                ?? false
                                            )
                                            && (bool) (
                                                $assignmentAvailability[
                                                    'can_submit'
                                                ]
                                                ?? false
                                            )
                                        ): ?>
                                · Late
                                <?php endif; ?>

                                <?php if (
                                            trim(
                                                (string) (
                                                    $assignmentRow[
                                                        'lesson_title'
                                                    ]
                                                    ?? ''
                                                )
                                            ) !== ''
                                        ): ?>
                                · Related to
                                <?= e(
                                                (string) $assignmentRow[
                                                    'lesson_title'
                                                ]
                                            ); ?>
                                <?php endif; ?>

                                <?php if (
                                            $canOpenAssignment
                                        ): ?>
                                ·
                                <a href="<?= e(
                                                    url(
                                                        'assignment.php?offering='
                                                        . $offeringId
                                                        . '&assignment='
                                                        . (int) $assignmentRow[
                                                            'assignment_id'
                                                        ]
                                                    )
                                                ); ?>">
                                    <?= is_array(
                                                    $latestAttempt
                                                )
                                                    ? 'View Assignment'
                                                    : 'Start Assignment'; ?>
                                </a>
                                <?php endif; ?>

                                <?php if (
                                            $assignmentSummary[
                                                'highest_grade'
                                            ] !== null
                                        ): ?>
                                · Highest grade:
                                <strong>
                                    <?= e(
                                                    number_format(
                                                        (float) $assignmentSummary[
                                                            'highest_grade'
                                                        ],
                                                        2
                                                    )
                                                ); ?>
                                </strong>
                                <?php endif; ?>

                                <?php if (
                                            trim(
                                                (string) (
                                                    $assignmentRow[
                                                        'description'
                                                    ]
                                                    ?? ''
                                                )
                                            ) !== ''
                                        ): ?>
                                <br>
                                <?= e(
                                                (string) $assignmentRow[
                                                    'description'
                                                ]
                                            ); ?>
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


<style>
    /*
|--------------------------------------------------------------------------
| Course Classroom Typography
|--------------------------------------------------------------------------
|
| Keep every eyebrow consistent with the Course Staff eyebrow and use
| the same serif heading language as the Orientation course title.
|
*/

    .course-page .academy-overline {
        margin: 0 0 14px;
        color: var(--color-gold-light);
        font-family: "Segoe UI", Arial, Helvetica, sans-serif;
        font-size: 11px;
        font-weight: 700;
        line-height: 1.2;
        letter-spacing: 0.22em;
        text-transform: uppercase;
    }

    .course-page .dashboard-panel-titlebar h2,
    .course-page .course-classroom-card h2,
    .course-page .course-classroom-card h3,
    .course-page .course-lesson-heading h3 {
        font-family: Georgia, "Times New Roman", serif;
        font-weight: 500;
        letter-spacing: 0;
    }

    .course-page .dashboard-panel-titlebar h2 {
        font-size: clamp(28px, 3vw, 38px);
        line-height: 1.1;
    }

    /*
 * Secondary classroom panel headings should use the same restrained scale
 * as the corrected Orientation treatment, rather than oversized display text.
 */
    .course-page .dashboard-workspace-panel:not(:first-child) .dashboard-panel-titlebar h2 {
        font-size: clamp(30px, 3vw, 40px);
        line-height: 1.08;
        font-weight: 500;
    }

    .course-page .course-classroom-card h2 {
        font-size: clamp(22px, 2.2vw, 30px);
        line-height: 1.15;
    }

    .course-page .course-classroom-card h3,
    .course-page .course-lesson-heading h3 {
        font-size: 1.05rem;
        line-height: 1.3;
    }

    /*
 * The main course name is the visual anchor of the classroom, using the
 * same large, restrained serif treatment that previously identified the
 * Orientation course at the top of the page.
 */
    .course-page .dashboard-workspace-main>.dashboard-workspace-panel:first-child .dashboard-panel-titlebar h2 {
        font-size: clamp(42px, 5vw, 64px);
        font-weight: 500;
        line-height: 1.04;
    }

    .course-classroom-home {
        display: grid;
        gap: 1rem;
        margin-bottom: 1rem;
    }

    .course-classroom-primary,
    .course-classroom-secondary {
        display: grid;
        gap: 1rem;
    }

    .course-classroom-primary {
        grid-template-columns: minmax(0, 1.35fr) minmax(16rem, 0.65fr);
    }

    .course-classroom-secondary {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .course-classroom-card {
        min-width: 0;
        padding: 1.2rem;
        border: 1px solid rgba(197, 157, 85, 0.28);
        border-radius: 0.9rem;
        background:
            linear-gradient(145deg,
                rgba(44, 27, 43, 0.96),
                rgba(20, 14, 21, 0.98));
        box-shadow: 0 0.6rem 1.5rem rgba(0, 0, 0, 0.16);
    }

    .course-classroom-card-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 0.75rem;
    }

    .course-classroom-card h2,
    .course-classroom-card h3,
    .course-classroom-card p {
        margin-top: 0;
    }

    .course-classroom-card h2 {
        margin-bottom: 0;
    }

    .course-classroom-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.65rem;
        margin-top: 1rem;
    }

    .course-progress-track {
        position: relative;
        width: 100%;
        height: 0.7rem;
        overflow: hidden;
        border: 1px solid rgba(197, 157, 85, 0.28);
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.07);
    }

    .course-progress-fill {
        display: block;
        height: 100%;
        border-radius: inherit;
        background:
            linear-gradient(90deg,
                #8d6b32,
                #c59d55);
    }

    .course-progress-summary {
        display: grid;
        gap: 0.35rem;
        margin-top: 0.85rem;
        font-size: 0.95rem;
    }

    .course-staff-list,
    .course-announcement-list {
        display: grid;
        gap: 0.75rem;
    }

    .course-staff-member {
        padding: 0.75rem 0;
        border-top: 1px solid rgba(197, 157, 85, 0.18);
    }

    .course-staff-member:first-child {
        padding-top: 0;
        border-top: 0;
    }

    .course-staff-member a,
    .course-staff-member strong {
        display: inline-block;
        font-weight: 600;
    }

    .course-staff-member span {
        display: block;
        margin-top: 0.2rem;
        opacity: 0.78;
        font-size: 0.9rem;
    }

    .course-announcement-item {
        padding-top: 0.8rem;
        border-top: 1px solid rgba(197, 157, 85, 0.18);
    }

    .course-announcement-item:first-child {
        padding-top: 0;
        border-top: 0;
    }

    .course-announcement-item h3 {
        margin-bottom: 0.25rem;
        font-size: 1rem;
    }

    .course-announcement-meta {
        margin-bottom: 0.45rem;
        opacity: 0.7;
        font-size: 0.86rem;
    }

    .course-classroom-muted {
        margin-bottom: 0;
        opacity: 0.78;
    }

    .course-overview-pills {
        display: flex;
        flex-wrap: wrap;
        gap: 0.7rem;
        margin-top: 1.25rem;
        padding-top: 1rem;
        border-top: 1px solid rgba(197, 157, 85, 0.18);
    }

    .course-overview-pill {
        display: flex;
        flex: 1 1 9rem;
        min-width: 0;
        flex-direction: column;
        gap: 0.2rem;
        padding: 0.72rem 0.9rem;
        border: 1px solid rgba(197, 157, 85, 0.26);
        border-radius: 999px;
        background: rgba(197, 157, 85, 0.07);
        text-align: center;
    }

    .course-overview-pill small {
        color: #c7af80;
        font-size: 0.68rem;
        letter-spacing: 0.12em;
        text-transform: uppercase;
    }

    .course-overview-pill strong {
        font-size: 0.9rem;
        font-weight: 600;
    }

    .course-snapshot-visual {
        display: grid;
        gap: 1rem;
    }

    .course-snapshot-progress {
        padding: 1rem;
        border: 1px solid rgba(197, 157, 85, 0.22);
        border-radius: 0.8rem;
        background: rgba(255, 255, 255, 0.025);
    }

    .course-snapshot-progress-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 0.7rem;
    }

    .course-snapshot-progress-head span {
        color: #d7c7a8;
    }

    .course-snapshot-progress-head strong {
        color: #d9b96e;
        font-size: 1.35rem;
    }

    .course-snapshot-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.75rem;
    }

    .course-snapshot-stat {
        min-width: 0;
        padding: 1rem 0.8rem;
        border: 1px solid rgba(197, 157, 85, 0.22);
        border-radius: 0.8rem;
        background: linear-gradient(145deg,
                rgba(54, 34, 53, 0.8),
                rgba(25, 17, 26, 0.92));
        text-align: center;
    }

    .course-snapshot-stat strong {
        display: block;
        margin-bottom: 0.22rem;
        color: #d9b96e;
        font-size: 1.45rem;
        font-weight: 600;
    }

    .course-snapshot-stat span {
        font-size: 0.82rem;
        opacity: 0.82;
    }

    .course-snapshot-note {
        margin-top: 1rem;
    }

    .course-lesson-list {
        display: grid;
        gap: 0.8rem;
    }

    .course-lesson-row {
        display: grid;
        grid-template-columns: 2.7rem minmax(0, 1fr) auto;
        gap: 1rem;
        align-items: center;
        padding: 1rem;
        border: 1px solid rgba(197, 157, 85, 0.22);
        border-radius: 0.8rem;
        background: rgba(255, 255, 255, 0.025);
    }

    .course-lesson-row.is-available {
        border-color: rgba(197, 157, 85, 0.35);
    }

    .course-lesson-row.is-locked {
        opacity: 0.72;
    }

    .course-lesson-number {
        display: grid;
        width: 2.4rem;
        height: 2.4rem;
        place-items: center;
        border: 1px solid rgba(197, 157, 85, 0.4);
        border-radius: 50%;
        color: #d9b96e;
        font-weight: 700;
    }

    .course-lesson-copy {
        min-width: 0;
    }

    .course-lesson-heading {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.75rem;
    }

    .course-lesson-heading h3 {
        margin: 0;
        font-size: 1rem;
    }

    .course-lesson-copy p {
        margin: 0.45rem 0 0;
        opacity: 0.82;
    }

    .course-lesson-badges {
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-end;
        gap: 0.35rem;
    }

    .course-lesson-badge {
        padding: 0.22rem 0.5rem;
        border: 1px solid rgba(197, 157, 85, 0.24);
        border-radius: 999px;
        font-size: 0.7rem;
        white-space: nowrap;
    }

    .course-lesson-unlock {
        color: #c7af80;
        font-size: 0.82rem;
    }

    .course-lesson-action {
        align-self: center;
    }

    .course-lesson-lock {
        display: inline-block;
        padding: 0.42rem 0.65rem;
        border: 1px solid rgba(255, 255, 255, 0.16);
        border-radius: 999px;
        font-size: 0.75rem;
        opacity: 0.75;
    }

    .course-announcement-item h3 a {
        color: inherit;
        text-decoration: none;
    }

    .course-announcement-item h3 a:hover,
    .course-announcement-item h3 a:focus-visible {
        color: #d9b96e;
    }

    /*
|--------------------------------------------------------------------------
| Course Overview Spacing / Scale
|--------------------------------------------------------------------------
*/

    .course-page .dashboard-workspace-main>.dashboard-workspace-panel:first-child {
        margin-bottom: 1rem;
    }

    .course-page .dashboard-workspace-main>.dashboard-workspace-panel:first-child .dashboard-panel-titlebar {
        padding-top: 1rem;
        padding-bottom: 1rem;
    }

    .course-page .dashboard-workspace-main>.dashboard-workspace-panel:first-child .dashboard-panel-titlebar .academy-overline {
        margin-bottom: 0.55rem;
    }

    .course-page .dashboard-workspace-main>.dashboard-workspace-panel:first-child .dashboard-panel-titlebar h2 {
        margin: 0;
        font-size: clamp(32px, 3.6vw, 44px);
        line-height: 1.05;
    }

    .course-page .dashboard-workspace-main>.dashboard-workspace-panel:first-child .dashboard-panel-body {
        padding-top: 1.15rem;
    }

    .course-overview-intro {
        margin: 0 0 1.5rem;
        font-size: 1rem;
        line-height: 1.7;
    }

    .course-overview-image {
        margin: 1.5rem 0 1.25rem !important;
        overflow: hidden;
        border: 1px solid rgba(197, 157, 85, 0.24);
        border-radius: 0.8rem;
        background: rgba(0, 0, 0, 0.18);
    }

    .course-overview-image picture,
    .course-overview-image img {
        display: block;
        width: 100%;
    }

    .course-overview-image img {
        height: auto;
        object-fit: contain;
    }

    @media (max-width: 980px) {
        .course-snapshot-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .course-lesson-row {
            grid-template-columns: 2.7rem minmax(0, 1fr);
        }

        .course-lesson-action {
            grid-column: 2;
        }

        .course-classroom-primary,
        .course-classroom-secondary {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 640px) {
        .course-snapshot-grid {
            grid-template-columns: 1fr;
        }

        .course-overview-pill {
            flex-basis: calc(50% - 0.4rem);
        }

        .course-lesson-row {
            grid-template-columns: 2.35rem minmax(0, 1fr);
            gap: 0.75rem;
            padding: 0.85rem;
        }

        .course-lesson-heading {
            display: block;
        }

        .course-lesson-badges {
            justify-content: flex-start;
            margin-top: 0.45rem;
        }

        .course-classroom-card {
            padding: 1rem;
        }
    }

</style>

<?php

require INCLUDES_PATH . '/footer.php';
