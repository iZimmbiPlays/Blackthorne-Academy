<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/course-lesson-progress.php';
require_once __DIR__ . '/includes/course-assignment-submissions.php';
require_once __DIR__ . '/includes/course-completion.php';

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

$lessonId =
    filter_input(
        INPUT_GET,
        'lesson',
        FILTER_VALIDATE_INT
    );

$offeringId =
    is_int($offeringId) && $offeringId > 0
        ? $offeringId
        : 0;

$lessonId =
    is_int($lessonId) && $lessonId > 0
        ? $lessonId
        : 0;


/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function student_lesson_format_datetime(
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



function student_lesson_image_urls(
    string $lessonImage
): array {
    $lessonImage =
        trim(
            $lessonImage
        );

    if ($lessonImage === '') {
        return [
            'original' => '',
            'webp' => '',
        ];
    }

    $relative =
        ltrim(
            $lessonImage,
            '/'
        );

    $original =
        rtrim(
            UPLOADS_URL,
            '/'
        )
        . '/'
        . $relative;

    $webp = '';

    $extension =
        strtolower(
            pathinfo(
                $relative,
                PATHINFO_EXTENSION
            )
        );

    if (
        $extension !== ''
        && $extension !== 'webp'
    ) {
        $webpRelative =
            substr(
                $relative,
                0,
                -strlen(
                    $extension
                )
            )
            . 'webp';

        $webpAbsolute =
            rtrim(
                UPLOADS_PATH,
                '/\\'
            )
            . DIRECTORY_SEPARATOR
            . str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $webpRelative
            );

        if (is_file($webpAbsolute)) {
            $webp =
                rtrim(
                    UPLOADS_URL,
                    '/'
                )
                . '/'
                . $webpRelative;
        }
    }

    return [
        'original' =>
            $original,

        'webp' =>
            $webp,
    ];
}


function student_lesson_render_content(
    string $content
): string {
    $content =
        trim(
            $content
        );

    if ($content === '') {
        return '';
    }

    /*
     * Older lessons may contain plain text from before the rich editor
     * existed. Preserve their line breaks while rendering new rich HTML
     * through the shared sanitizer.
     */
    if (
        preg_match(
            '/<\s*[a-z][^>]*>/i',
            $content
        ) !== 1
    ) {
        return nl2br(
            e($content)
        );
    }

    return sanitize_rich_text(
        $content
    );
}


function student_lesson_unavailable_message(
    string $reason
): string {
    $messages = [
        'not_enrolled' =>
            'You are not enrolled in this course offering.',

        'lesson_not_in_offering' =>
            'This lesson is not part of your course offering.',

        'enrollment_suspended' =>
            'Your enrollment is currently suspended.',

        'enrollment_inactive' =>
            'Your enrollment is not active.',

        'offering_archived' =>
            'This course offering has been archived.',

        'course_access_ended' =>
            'Access to this course has ended.',

        'lesson_unpublished' =>
            'This lesson is not currently published.',

        'lesson_version_unpublished' =>
            'This lesson version is not currently published.',

        'waiting_for_course_start' =>
            'This lesson will become available when the course begins.',

        'waiting_for_fixed_date' =>
            'This lesson has not reached its release date yet.',

        'waiting_for_previous_lesson' =>
            'Complete the required previous lesson before continuing.',

        'waiting_for_legacy_enrollment_delay' =>
            'This lesson is not available yet.',

        'unknown_release_rule' =>
            'This lesson is not currently available.',
    ];

    return $messages[$reason]
        ?? 'This lesson is not currently available.';
}


/*
|--------------------------------------------------------------------------
| Validate Enrollment / Lesson
|--------------------------------------------------------------------------
*/

if (
    $userId <= 0
    || $offeringId <= 0
    || $lessonId <= 0
) {
    http_response_code(404);

    $pageTitle =
        'Lesson Not Found | Blackthorne Academy';

    $pageDescription =
        'The requested lesson could not be found.';

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
                    <h1>Lesson Not Found</h1>
                    <p>
                        The requested lesson could not be found.
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

$lesson =
    blackthorne_offering_lesson(
        $pdo,
        $offeringId,
        $lessonId
    );

$availability =
    blackthorne_user_lesson_availability(
        $pdo,
        $userId,
        $offeringId,
        $lessonId
    );

if (
    $enrollment === null
    || $lesson === null
) {
    http_response_code(404);

    $pageTitle =
        'Lesson Not Found | Blackthorne Academy';

    $pageDescription =
        'The requested lesson could not be found.';

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
                    <h1>Lesson Not Found</h1>
                    <p>
                        The requested lesson could not be found in this course.
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
| Stable Lesson Image
|--------------------------------------------------------------------------
*/

$lessonImageStatement =
    $pdo->prepare(
        '
        SELECT lesson_image
        FROM lessons
        WHERE id = :lesson_id
        LIMIT 1
        '
    );

$lessonImageStatement->execute([
    'lesson_id' =>
        $lessonId,
]);

$lessonImagePath =
    trim(
        (string) (
            $lessonImageStatement->fetchColumn()
            ?: ''
        )
    );

$lessonImageUrls =
    student_lesson_image_urls(
        $lessonImagePath
    );


/*
|--------------------------------------------------------------------------
| Course Metadata
|--------------------------------------------------------------------------
*/

$courseStatement =
    $pdo->prepare(
        '
        SELECT
            c.id,
            c.title,
            c.course_code,
            c.short_description,
            co.pacing_mode,
            co.course_start_date,
            co.course_end_date,
            co.status AS offering_status,
            sy.name AS school_year_name

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
    )
    ?: [];


/*
|--------------------------------------------------------------------------
| Locked Lesson Page
|--------------------------------------------------------------------------
*/

if (
    !(bool) (
        $availability[
            'available'
        ]
        ?? false
    )
) {
    $lessonTitle =
        trim(
            (string) (
                $lesson['title']
                ?? $lesson[
                    'internal_name'
                ]
                ?? 'Lesson'
            )
        );

    $pageTitle =
        $lessonTitle
        . ' | Blackthorne Academy';

    $pageDescription =
        'This lesson is not currently available.';

    $pageCanonical =
        url(
            'lesson.php?offering='
            . $offeringId
            . '&lesson='
            . $lessonId
        );

    $robots =
        'noindex, nofollow';

    require INCLUDES_PATH . '/header.php';
    ?>

    <main
        id="main-content"
        class="dashboard-page lesson-page"
    >
        <section class="dashboard-workspace-section">
            <div class="section-inner dashboard-workspace-layout">

                <?php
                require
                    INCLUDES_PATH
                    . '/member-sidebar.php';
                ?>

                <div class="dashboard-workspace-main">

                    <header class="dashboard-workspace-heading">
                        <div>
                            <p class="academy-overline">
                                <?= e(
                                    (string) (
                                        $course[
                                            'title'
                                        ]
                                        ?? 'Course'
                                    )
                                ); ?>
                            </p>

                            <h1>
                                <?= e($lessonTitle); ?>
                            </h1>
                        </div>
                    </header>

                    <section class="dashboard-workspace-panel">
                        <div class="dashboard-panel-body">

                            <div
                                class="form-message form-message-error"
                                role="alert"
                            >
                                <?= e(
                                    student_lesson_unavailable_message(
                                        (string) (
                                            $availability[
                                                'reason'
                                            ]
                                            ?? ''
                                        )
                                    )
                                ); ?>
                            </div>

                            <?php if (
                                !empty(
                                    $availability[
                                        'unlocks_at'
                                    ]
                                )
                            ): ?>
                                <p>
                                    Scheduled availability:
                                    <strong>
                                        <?= e(
                                            student_lesson_format_datetime(
                                                $availability[
                                                    'unlocks_at'
                                                ]
                                            )
                                        ); ?>
                                    </strong>
                                </p>
                            <?php endif; ?>

                            <a
                                class="button button-secondary"
                                href="<?= e(
                                    url(
                                        'course.php?offering='
                                        . $offeringId
                                    )
                                ); ?>"
                            >
                                Back to Course
                            </a>

                        </div>
                    </section>

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
| Progress
|--------------------------------------------------------------------------
|
| Opening an available lesson starts it, but does not complete it.
|
*/

$enrollmentId =
    (int) $enrollment['id'];

$progress =
    blackthorne_lesson_progress(
        $pdo,
        $enrollmentId,
        $lessonId
    );

if (
    $progress === null
    || (string) (
        $progress['status']
        ?? ''
    ) === 'not_started'
) {
    blackthorne_mark_lesson_started(
        $pdo,
        $enrollmentId,
        $lessonId
    );

    $progress =
        blackthorne_lesson_progress(
            $pdo,
            $enrollmentId,
            $lessonId
        );
}


/*
|--------------------------------------------------------------------------
| POST - Complete Lesson
|--------------------------------------------------------------------------
*/

$errors = [];

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
        isset($_POST['action'])
        && is_scalar($_POST['action'])
            ? trim(
                (string) $_POST['action']
            )
            : '';

    if (
        $errors === []
        && $action !== 'complete_lesson'
    ) {
        $errors[] =
            'Choose a valid lesson action.';
    }

    /*
     * Recheck availability during POST so a stale page cannot bypass a
     * changed release rule or enrollment status.
     */
    if ($errors === []) {
        $postAvailability =
            blackthorne_user_lesson_availability(
                $pdo,
                $userId,
                $offeringId,
                $lessonId
            );

        if (
            !(bool) (
                $postAvailability[
                    'available'
                ]
                ?? false
            )
        ) {
            $errors[] =
                'This lesson is no longer available to complete.';
        }
    }

    if ($errors === []) {
        $markedComplete =
            blackthorne_mark_lesson_completed(
                $pdo,
                $enrollmentId,
                $lessonId
            );

        if (!$markedComplete) {
            $errors[] =
                'The lesson could not be marked complete.';
        } else {
            $completionSummary =
                blackthorne_recalculate_course_completion(
                    $pdo,
                    $enrollmentId
                );

            $completionMessage =
                'Lesson marked complete.';

            if (
                is_array($completionSummary)
                && (
                    $completionSummary[
                        'is_complete'
                    ]
                    ?? false
                ) === true
            ) {
                $completionMessage =
                    'Lesson marked complete. You have completed this course.';
            }

            set_flash(
                'success',
                $completionMessage
            );

            redirect(
                url(
                    'lesson.php?offering='
                    . $offeringId
                    . '&lesson='
                    . $lessonId
                )
            );
        }
    }

    $progress =
        blackthorne_lesson_progress(
            $pdo,
            $enrollmentId,
            $lessonId
        );
}


/*
|--------------------------------------------------------------------------
| Ordered Lesson Navigation
|--------------------------------------------------------------------------
*/

$offeringLessons =
    blackthorne_offering_lessons_for_user(
        $pdo,
        $userId,
        $offeringId
    );

$currentIndex = null;

foreach (
    $offeringLessons
    as $index => $lessonRow
) {
    if (
        (int) (
            $lessonRow[
                'lesson_id'
            ]
            ?? 0
        ) === $lessonId
    ) {
        $currentIndex =
            $index;

        break;
    }
}

$previousLesson = null;
$nextLesson = null;

if ($currentIndex !== null) {
    for (
        $index = $currentIndex - 1;
        $index >= 0;
        $index--
    ) {
        $candidate =
            $offeringLessons[$index];

        if (
            (bool) (
                $candidate[
                    'available'
                ]
                ?? false
            )
        ) {
            $previousLesson =
                $candidate;

            break;
        }
    }

    for (
        $index = $currentIndex + 1,
        $count = count(
            $offeringLessons
        );
        $index < $count;
        $index++
    ) {
        $candidate =
            $offeringLessons[$index];

        if (
            (bool) (
                $candidate[
                    'available'
                ]
                ?? false
            )
        ) {
            $nextLesson =
                $candidate;

            break;
        }
    }
}


/*
|--------------------------------------------------------------------------
| Assignments Linked To This Lesson
|--------------------------------------------------------------------------
*/

$assignmentStatement =
    $pdo->prepare(
        '
        SELECT
            aos.assignment_id,
            aos.points_possible,
            aos.due_date,
            aos.submission_close_date,

            av.title,
            av.description

        FROM assignment_offering_settings aos

        INNER JOIN assignments a
            ON a.id = aos.assignment_id

        INNER JOIN assignment_versions av
            ON av.id = aos.assignment_version_id

        WHERE aos.offering_id = :offering_id
          AND aos.course_offering_lesson_id = :offering_lesson_id
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

    'offering_lesson_id' =>
        (int) $lesson[
            'offering_lesson_id'
        ],
]);

$linkedAssignments =
    $assignmentStatement->fetchAll(
        PDO::FETCH_ASSOC
    );

foreach (
    $linkedAssignments
    as &$assignmentRow
) {
    $assignmentContext =
        blackthorne_assignment_context(
            $pdo,
            $userId,
            $offeringId,
            (int) $assignmentRow[
                'assignment_id'
            ]
        );

    if ($assignmentContext === null) {
        $assignmentRow[
            'availability'
        ] = null;

        $assignmentRow[
            'summary'
        ] = null;

        continue;
    }

    $assignmentRow[
        'availability'
    ] =
        blackthorne_assignment_submission_availability(
            $pdo,
            $assignmentContext
        );

    $assignmentRow[
        'summary'
    ] =
        blackthorne_assignment_submission_summary(
            $pdo,
            $enrollmentId,
            $offeringId,
            (int) $assignmentRow[
                'assignment_id'
            ]
        );
}

unset($assignmentRow);


$successMessage =
    get_flash(
        'success'
    );


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

$lessonTitle =
    (string) (
        $lesson['title']
        ?? $lesson[
            'internal_name'
        ]
        ?? 'Lesson'
    );

$lessonCompleted =
    (string) (
        $progress['status']
        ?? ''
    ) === 'completed';

$pageTitle =
    $lessonTitle
    . ' | Blackthorne Academy';

$pageDescription =
    trim(
        (string) (
            $lesson[
                'description'
            ]
            ?? ''
        )
    );

if ($pageDescription === '') {
    $pageDescription =
        'Course lesson for '
        . $courseTitle
        . '.';
}

$pageCanonical =
    url(
        'lesson.php?offering='
        . $offeringId
        . '&lesson='
        . $lessonId
    );

$robots =
    'noindex, nofollow';

require INCLUDES_PATH . '/header.php';

?>

<main
    id="main-content"
    class="dashboard-page lesson-page"
>

    <section class="dashboard-workspace-section">
        <div class="section-inner dashboard-workspace-layout">

            <?php
            require
                INCLUDES_PATH
                . '/member-sidebar.php';
            ?>

            <div class="dashboard-workspace-main">

                <header class="dashboard-workspace-heading">
                    <div>

                        <p class="academy-overline">
                            <?= e($courseTitle); ?>
                        </p>

                        <h1>
                            <?= e($lessonTitle); ?>
                        </h1>

                        <p>
                            Lesson
                            <?= number_format(
                                (int) (
                                    $lesson[
                                        'sort_order'
                                    ]
                                    ?? 0
                                )
                            ); ?>

                            ·

                            <?= $lessonCompleted
                                ? 'Completed'
                                : 'In Progress'; ?>
                        </p>

                    </div>
                </header>


                <?php if (
                    is_string($successMessage)
                    && trim($successMessage) !== ''
                ): ?>

                    <div
                        class="form-message form-message-success"
                        role="status"
                    >
                        <?= e($successMessage); ?>
                    </div>

                <?php endif; ?>


                <?php if ($errors !== []): ?>

                    <div
                        class="form-message form-message-error"
                        role="alert"
                    >
                        <strong>
                            The lesson could not be updated.
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


                <nav
                    class="forum-breadcrumbs"
                    aria-label="Course navigation"
                >
                    <a
                        href="<?= e(
                            url(
                                'course.php?offering='
                                . $offeringId
                            )
                        ); ?>"
                    >
                        <?= e($courseTitle); ?>
                    </a>

                    <span aria-hidden="true">
                        /
                    </span>

                    <span aria-current="page">
                        <?= e($lessonTitle); ?>
                    </span>
                </nav>


                <?php if (
                    $lessonImageUrls[
                        'original'
                    ] !== ''
                ): ?>

                    <figure class="lesson-feature-image">
                        <picture>
                            <?php if (
                                $lessonImageUrls[
                                    'webp'
                                ] !== ''
                            ): ?>
                                <source
                                    srcset="<?= e(
                                        $lessonImageUrls[
                                            'webp'
                                        ]
                                    ); ?>"
                                    type="image/webp"
                                >
                            <?php endif; ?>

                            <img
                                src="<?= e(
                                    $lessonImageUrls[
                                        'original'
                                    ]
                                ); ?>"
                                alt="<?= e(
                                    $lessonTitle
                                    . ' lesson image'
                                ); ?>"
                                loading="eager"
                            >
                        </picture>
                    </figure>

                <?php endif; ?>


                <?php if (
                    trim(
                        (string) (
                            $lesson[
                                'description'
                            ]
                            ?? ''
                        )
                    ) !== ''
                ): ?>

                    <section class="dashboard-workspace-panel">

                        <div class="dashboard-panel-titlebar">
                            <div>
                                <p class="academy-overline">
                                    Lesson Overview
                                </p>

                                <h2>
                                    What You’ll Learn
                                </h2>
                            </div>
                        </div>

                        <div class="dashboard-panel-body">
                            <p>
                                <?= nl2br(
                                    e(
                                        (string) $lesson[
                                            'description'
                                        ]
                                    )
                                ); ?>
                            </p>
                        </div>

                    </section>

                <?php endif; ?>


                <article class="dashboard-workspace-panel">

                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">
                                Lesson Content
                            </p>

                            <h2>
                                <?= e($lessonTitle); ?>
                            </h2>
                        </div>
                    </div>

                    <div class="dashboard-panel-body">

                        <?php if (
                            trim(
                                (string) (
                                    $lesson[
                                        'content'
                                    ]
                                    ?? ''
                                )
                            ) !== ''
                        ): ?>

                            <div class="forum-post-content lesson-content">
                                <?= student_lesson_render_content(
                                    (string) $lesson[
                                        'content'
                                    ]
                                ); ?>
                            </div>

                        <?php else: ?>

                            <p>
                                This lesson does not have content yet.
                            </p>

                        <?php endif; ?>

                    </div>

                </article>


                <?php if ($linkedAssignments !== []): ?>

                    <section class="dashboard-workspace-panel">

                        <div class="dashboard-panel-titlebar">
                            <div>
                                <p class="academy-overline">
                                    Coursework
                                </p>

                                <h2>
                                    Assignments for This Lesson
                                </h2>
                            </div>
                        </div>

                        <div class="dashboard-panel-body">

                            <div class="dashboard-placeholder-list">

                                <?php foreach (
                                    $linkedAssignments
                                    as $assignmentRow
                                ): ?>
                                    <?php
                                    $assignmentSummary =
                                        $assignmentRow[
                                            'summary'
                                        ]
                                        ?? null;

                                    $latestAttempt =
                                        is_array(
                                            $assignmentSummary
                                        )
                                            ? (
                                                $assignmentSummary[
                                                    'latest_attempt'
                                                ]
                                                ?? null
                                            )
                                            : null;

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
                                    ?>

                                    <span>

                                        <strong>
                                            <?= e(
                                                (string) $assignmentRow[
                                                    'title'
                                                ]
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
                                                student_lesson_format_datetime(
                                                    $assignmentRow[
                                                        'due_date'
                                                    ]
                                                )
                                            ); ?>
                                        <?php endif; ?>

                                        ·
                                        <a
                                            href="<?= e(
                                                url(
                                                    'assignment.php?offering='
                                                    . $offeringId
                                                    . '&assignment='
                                                    . (int) $assignmentRow[
                                                        'assignment_id'
                                                    ]
                                                )
                                            ); ?>"
                                        >
                                            <?= is_array(
                                                $latestAttempt
                                            )
                                                ? 'View Assignment'
                                                : 'Open Assignment'; ?>
                                        </a>

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

                        </div>

                    </section>

                <?php endif; ?>


                <section class="dashboard-workspace-panel">

                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">
                                Lesson Progress
                            </p>

                            <h2>
                                <?= $lessonCompleted
                                    ? 'Lesson Complete'
                                    : 'Finish This Lesson'; ?>
                            </h2>
                        </div>
                    </div>

                    <div class="dashboard-panel-body">

                        <?php if ($lessonCompleted): ?>

                            <p>
                                You completed this lesson
                                <?php if (
                                    !empty(
                                        $progress[
                                            'completed_at'
                                        ]
                                    )
                                ): ?>
                                    on
                                    <strong>
                                        <?= e(
                                            student_lesson_format_datetime(
                                                $progress[
                                                    'completed_at'
                                                ]
                                            )
                                        ); ?>
                                    </strong>
                                <?php endif; ?>.
                            </p>

                        <?php else: ?>

                            <p>
                                When you have finished the lesson, mark it complete
                                to record your progress and unlock any content that
                                depends on this lesson.
                            </p>

                            <form
                                method="post"
                                action="<?= e(
                                    url(
                                        'lesson.php?offering='
                                        . $offeringId
                                        . '&lesson='
                                        . $lessonId
                                    )
                                ); ?>"
                            >
                                <?= csrf_field(); ?>

                                <button
                                    type="submit"
                                    class="button button-primary"
                                    name="action"
                                    value="complete_lesson"
                                >
                                    Mark Lesson Complete
                                </button>
                            </form>

                        <?php endif; ?>

                    </div>

                </section>


                <nav
                    class="dashboard-workspace-panel"
                    aria-label="Lesson navigation"
                >
                    <div class="dashboard-panel-body">

                        <div class="forum-admin-actions">

                            <?php if (
                                $previousLesson !== null
                            ): ?>

                                <a
                                    class="button button-secondary"
                                    href="<?= e(
                                        url(
                                            'lesson.php?offering='
                                            . $offeringId
                                            . '&lesson='
                                            . (int) $previousLesson[
                                                'lesson_id'
                                            ]
                                        )
                                    ); ?>"
                                >
                                    ←
                                    <?= e(
                                        (string) (
                                            $previousLesson[
                                                'title'
                                            ]
                                            ?? 'Previous Lesson'
                                        )
                                    ); ?>
                                </a>

                            <?php else: ?>

                                <a
                                    class="button button-secondary"
                                    href="<?= e(
                                        url(
                                            'course.php?offering='
                                            . $offeringId
                                        )
                                    ); ?>"
                                >
                                    ← Back to Course
                                </a>

                            <?php endif; ?>


                            <?php if (
                                $nextLesson !== null
                            ): ?>

                                <a
                                    class="button button-primary"
                                    href="<?= e(
                                        url(
                                            'lesson.php?offering='
                                            . $offeringId
                                            . '&lesson='
                                            . (int) $nextLesson[
                                                'lesson_id'
                                            ]
                                        )
                                    ); ?>"
                                >
                                    <?= e(
                                        (string) (
                                            $nextLesson[
                                                'title'
                                            ]
                                            ?? 'Next Lesson'
                                        )
                                    ); ?>
                                    →
                                </a>

                            <?php endif; ?>

                        </div>

                    </div>
                </nav>

            </div>

        </div>
    </section>

</main>


<style>
.lesson-feature-image {
    margin: 0 0 1.5rem;
    padding: 0;
    overflow: hidden;
    border: 1px solid rgba(199, 164, 91, 0.45);
    border-radius: 14px;
    background:
        linear-gradient(
            180deg,
            rgba(31, 18, 33, 0.94),
            rgba(15, 10, 17, 0.98)
        );
}

.lesson-feature-image picture {
    display: block;
}

.lesson-feature-image img {
    display: block;
    width: 100%;
    max-height: 460px;
    margin: 0 auto;
    object-fit: contain;
    background: rgba(10, 7, 12, 0.72);
}

.lesson-content {
    line-height: 1.75;
}

.lesson-content > :first-child {
    margin-top: 0;
}

.lesson-content > :last-child {
    margin-bottom: 0;
}

.lesson-content h2,
.lesson-content h3,
.lesson-content h4 {
    margin-top: 1.6em;
    margin-bottom: 0.65em;
    color: var(--gold, #c7a45b);
    line-height: 1.25;
}

.lesson-content p,
.lesson-content ul,
.lesson-content ol,
.lesson-content blockquote {
    margin-top: 0;
    margin-bottom: 1rem;
}

.lesson-content ul,
.lesson-content ol {
    padding-left: 1.6rem;
}

.lesson-content blockquote {
    padding: 0.85rem 1rem;
    border-left: 3px solid var(--gold, #c7a45b);
    background: rgba(199, 164, 91, 0.08);
}

.lesson-content img {
    display: block;
    max-width: 100%;
    height: auto;
    margin: 1.35rem auto;
    border-radius: 10px;
}

.lesson-content a {
    overflow-wrap: anywhere;
}

@media (max-width: 720px) {
    .lesson-feature-image img {
        max-height: 340px;
    }

    .lesson-content {
        line-height: 1.68;
    }
}
</style>

<?php

require INCLUDES_PATH . '/footer.php';
