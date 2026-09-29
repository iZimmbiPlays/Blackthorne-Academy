<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/course-lesson-progress.php';

require_login();
require_active_account();

$userId =
    (int) (
        current_user_id()
        ?? 0
    );


/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function student_courses_format_date(
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
        'M j, Y',
        $timestamp
    );
}


function student_courses_image_urls(
    string $courseImage
): array {
    $courseImage =
        trim(
            $courseImage
        );

    if ($courseImage === '') {
        return [
            'original' => '',
            'webp' => '',
        ];
    }

    $normalized =
        ltrim(
            $courseImage,
            '/'
        );

    $original =
        url(
            $normalized
        );

    $webp = '';

    $extension =
        strtolower(
            pathinfo(
                $normalized,
                PATHINFO_EXTENSION
            )
        );

    if (
        $extension !== ''
        && $extension !== 'webp'
    ) {
        $webpReference =
            substr(
                $normalized,
                0,
                -strlen(
                    $extension
                )
            )
            . 'webp';

        if (
            is_file(
                __DIR__
                . '/'
                . $webpReference
            )
        ) {
            $webp =
                url(
                    $webpReference
                );
        }
    }

    return [
        'original' =>
            $original,

        'webp' =>
            $webp,
    ];
}


/*
|--------------------------------------------------------------------------
| Enrolled Courses
|--------------------------------------------------------------------------
*/

$courseStatement =
    $pdo->prepare(
        '
        SELECT
            ce.id AS enrollment_id,
            ce.status AS enrollment_status,
            ce.progress,
            ce.enrolled_at,
            ce.completed_at,
            ce.withdrawn_at,
            ce.suspended_at,

            co.id AS offering_id,
            co.pacing_mode,
            co.drip_basis,
            co.course_start_date,
            co.course_end_date,
            co.status AS offering_status,

            c.id AS course_id,
            c.title,
            c.slug,
            c.course_code,
            c.short_description,
            c.description,
            c.course_image,
            c.status AS course_status,

            sy.name AS school_year_name,
            sy.is_current AS school_year_is_current,
            sy.course_access_ends_at

        FROM course_enrollments ce

        INNER JOIN course_offerings co
            ON co.id = ce.offering_id

        INNER JOIN courses c
            ON c.id = co.course_id

        LEFT JOIN school_years sy
            ON sy.id = co.school_year_id

        WHERE ce.user_id = :user_id

        ORDER BY
            CASE ce.status
                WHEN "enrolled" THEN 0
                WHEN "completed" THEN 1
                WHEN "suspended" THEN 2
                WHEN "withdrawn" THEN 3
                ELSE 4
            END ASC,
            sy.is_current DESC,
            co.course_start_date DESC,
            c.title ASC
        '
    );

$courseStatement->execute([
    'user_id' =>
        $userId,
]);

$enrollments =
    $courseStatement->fetchAll(
        PDO::FETCH_ASSOC
    );

$activeCourses = [];
$completedCourses = [];
$inactiveCourses = [];

foreach (
    $enrollments
    as $courseRow
) {
    $offeringId =
        (int) $courseRow[
            'offering_id'
        ];

    $lessonSummary =
        blackthorne_lesson_progress_summary(
            $pdo,
            (int) $courseRow[
                'enrollment_id'
            ],
            $offeringId
        );

    $courseRow[
        'lesson_summary'
    ] =
        $lessonSummary;

    $courseRow[
        'image_urls'
    ] =
        student_courses_image_urls(
            (string) (
                $courseRow[
                    'course_image'
                ]
                ?? ''
            )
        );

    $status =
        (string) $courseRow[
            'enrollment_status'
        ];

    if ($status === 'enrolled') {
        $activeCourses[] =
            $courseRow;

        continue;
    }

    if ($status === 'completed') {
        $completedCourses[] =
            $courseRow;

        continue;
    }

    $inactiveCourses[] =
        $courseRow;
}


/*
|--------------------------------------------------------------------------
| Page Metadata
|--------------------------------------------------------------------------
*/

$pageTitle =
    'My Courses | Blackthorne Academy';

$pageDescription =
    'View your enrolled Blackthorne Academy courses, progress, and completed coursework.';

$pageCanonical =
    url('courses.php');

$robots =
    'noindex, nofollow';

require INCLUDES_PATH . '/header.php';

?>

<style>
    .courses-page .course-card-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 18px;
    }

    .courses-page .course-card {
        min-width: 0;
        overflow: hidden;
        border: 1px solid rgba(219, 193, 125, 0.14);
        border-radius: 14px;
        background:
            linear-gradient(180deg, rgba(31, 15, 38, 0.94), rgba(17, 8, 21, 0.98));
        box-shadow: 0 14px 34px rgba(0, 0, 0, 0.16);
    }

    .courses-page .course-card-top {
        display: flex;
        flex-direction: column;
        gap: 12px;
        padding: 15px;
        border-bottom: 1px solid rgba(219, 193, 125, 0.10);
        background: rgba(76, 39, 86, 0.10);
    }

    .courses-page .course-card-image {
        width: 100%;
        aspect-ratio: 16 / 9;
        overflow: hidden;
        border: 1px solid rgba(219, 193, 125, 0.16);
        border-radius: 10px;
        background: #100713;
    }

    .courses-page .course-card-image picture,
    .courses-page .course-card-image img {
        display: block;
        width: 100%;
        height: 100%;
    }

    .courses-page .course-card-image img {
        object-fit: cover;
    }

    .courses-page .course-card-image-placeholder {
        display: grid;
        place-items: center;
        color: rgba(219, 193, 125, 0.76);
        font-family: Georgia, "Times New Roman", serif;
        font-size: 34px;
    }

    .courses-page .course-card-heading {
        min-width: 0;
        padding: 0 2px 2px;
    }

    .courses-page .course-card-heading .academy-overline {
        margin-bottom: 5px;
        font-size: 9px;
    }

    .courses-page .course-card-heading h3 {
        margin: 0;
        color: var(--color-text);
        font-family: Georgia, "Times New Roman", serif;
        font-size: clamp(20px, 2vw, 25px);
        font-weight: 500;
        line-height: 1.16;
    }

    .courses-page .course-card-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-top: 10px;
    }

    .courses-page .course-card-meta span {
        display: inline-flex;
        align-items: center;
        min-height: 25px;
        padding: 3px 8px;
        border: 1px solid rgba(219, 193, 125, 0.13);
        border-radius: 999px;
        color: #b9adb8;
        background: rgba(255, 255, 255, 0.018);
        font-size: 10px;
        line-height: 1.2;
    }

    .courses-page .course-card-body {
        display: grid;
        gap: 15px;
        padding: 16px;
    }

    .courses-page .course-card-description {
        margin: 0;
        color: #cfc3ce;
        font-size: 12px;
        line-height: 1.65;
    }

    .courses-page .course-card-stats {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
    }

    .courses-page .course-card-stats>div {
        min-width: 0;
        padding: 9px 10px;
        border: 1px solid rgba(219, 193, 125, 0.09);
        border-radius: 9px;
        background: rgba(255, 255, 255, 0.018);
    }

    .courses-page .course-card-stats span,
    .courses-page .course-card-stats strong {
        display: block;
    }

    .courses-page .course-card-stats span {
        margin-bottom: 3px;
        color: #8f828f;
        font-size: 9px;
        font-weight: 750;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .courses-page .course-card-stats strong {
        color: #e4dce3;
        font-size: 11px;
        font-weight: 650;
        line-height: 1.35;
    }

    .courses-page .course-card-stat-wide {
        grid-column: 1 / -1;
    }

    .courses-page .course-card-progress {
        display: grid;
        gap: 6px;
    }

    .courses-page .course-card-progress-label {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        color: #9b8e9a;
        font-size: 10px;
    }

    .courses-page .course-card-progress progress {
        display: block;
        width: 100%;
        height: 8px;
        overflow: hidden;
        border: 0;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.06);
    }

    .courses-page .course-card-actions {
        display: flex;
        justify-content: flex-end;
        padding-top: 1px;
    }

    .courses-page .course-card-actions .button {
        min-height: 38px;
        padding: 8px 14px;
        font-size: 11px;
    }

    @media (max-width: 1180px) {
        .courses-page .course-card-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 620px) {
        .courses-page .course-card-top {
            padding: 12px;
        }

        .courses-page .course-card-body {
            padding: 13px;
        }

        .courses-page .course-card-actions {
            justify-content: stretch;
        }

        .courses-page .course-card-actions .button {
            width: 100%;
            justify-content: center;
        }
    }

    @media (max-width: 430px) {
        .courses-page .course-card-image {
            aspect-ratio: 16 / 9;
        }
    }

</style>

<main id="main-content" class="dashboard-page courses-page">

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
                            Academics
                        </p>

                        <h1>
                            My Courses
                        </h1>

                        <p>
                            Access your current classrooms, review completed work,
                            and keep track of your lesson progress.
                        </p>

                    </div>
                </header>


                <section class="dashboard-workspace-panel">

                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">
                                Current Enrollment
                            </p>

                            <h2>
                                Active Courses
                            </h2>
                        </div>
                    </div>

                    <div class="dashboard-panel-body">

                        <?php if (
                            $activeCourses === []
                        ): ?>

                        <p>
                            You do not currently have any active course enrollments.
                        </p>

                        <?php else: ?>

                        <div class="course-card-grid">

                            <?php foreach (
                                    $activeCourses
                                    as $courseRow
                                ): ?>
                            <?php
                                    $summary =
                                        $courseRow[
                                            'lesson_summary'
                                        ];

                                    $images =
                                        $courseRow[
                                            'image_urls'
                                        ];

                                    $courseCode =
                                        trim(
                                            (string) (
                                                $courseRow[
                                                    'course_code'
                                                ]
                                                ?? ''
                                            )
                                        );

                                    if ($courseCode === '') {
                                        $courseCode = 'Course';
                                    }

                                    $courseDescription =
                                        trim(
                                            (string) (
                                                $courseRow[
                                                    'short_description'
                                                ]
                                                ?? ''
                                            )
                                        );

                                    $hasCourseDates =
                                        !empty(
                                            $courseRow[
                                                'course_start_date'
                                            ]
                                        )
                                        || !empty(
                                            $courseRow[
                                                'course_end_date'
                                            ]
                                        );
                                    ?>

                            <article class="course-card">

                                <div class="course-card-top">

                                    <?php if (
                                                $images[
                                                    'original'
                                                ] !== ''
                                            ): ?>

                                    <div class="course-card-image">
                                        <picture>
                                            <?php if (
                                                            $images[
                                                                'webp'
                                                            ] !== ''
                                                        ): ?>
                                            <source srcset="<?= e(
                                                                    $images[
                                                                        'webp'
                                                                    ]
                                                                ); ?>" type="image/webp">
                                            <?php endif; ?>

                                            <img src="<?= e(
                                                                $images[
                                                                    'original'
                                                                ]
                                                            ); ?>" alt="<?= e(
                                                                (string) $courseRow[
                                                                    'title'
                                                                ]
                                                                . ' course thumbnail'
                                                            ); ?>">
                                        </picture>
                                    </div>

                                    <?php else: ?>

                                    <div class="course-card-image course-card-image-placeholder" aria-hidden="true">
                                        <span>B</span>
                                    </div>

                                    <?php endif; ?>

                                    <div class="course-card-heading">

                                        <p class="academy-overline">
                                            <?= e(
                                                        $courseCode
                                                    ); ?>
                                        </p>

                                        <h3>
                                            <?= e(
                                                        (string) $courseRow[
                                                            'title'
                                                        ]
                                                    ); ?>
                                        </h3>

                                        <div class="course-card-meta">

                                            <span>
                                                <?= e(
                                                            (string) (
                                                                $courseRow[
                                                                    'pacing_mode'
                                                                ]
                                                                ?? ''
                                                            ) === 'drip'
                                                                ? 'Drip Content'
                                                                : 'Self-Paced'
                                                        ); ?>
                                            </span>

                                            <?php if (
                                                        trim(
                                                            (string) (
                                                                $courseRow[
                                                                    'school_year_name'
                                                                ]
                                                                ?? ''
                                                            )
                                                        ) !== ''
                                                    ): ?>
                                            <span>
                                                <?= e(
                                                                (string) $courseRow[
                                                                    'school_year_name'
                                                                ]
                                                            ); ?>
                                            </span>
                                            <?php endif; ?>

                                        </div>

                                    </div>

                                </div>

                                <div class="course-card-body">

                                    <?php if (
                                                $courseDescription !== ''
                                            ): ?>
                                    <p class="course-card-description">
                                        <?= nl2br(
                                                        e(
                                                            $courseDescription
                                                        )
                                                    ); ?>
                                    </p>
                                    <?php endif; ?>

                                    <div class="course-card-stats">

                                        <div>
                                            <span>Lessons</span>
                                            <strong>
                                                <?= number_format(
                                                            (int) $summary[
                                                                'completed'
                                                            ]
                                                        ); ?>
                                                /
                                                <?= number_format(
                                                            (int) $summary[
                                                                'total'
                                                            ]
                                                        ); ?>
                                            </strong>
                                        </div>

                                        <div>
                                            <span>Progress</span>
                                            <strong>
                                                <?= e(
                                                            number_format(
                                                                (float) $summary[
                                                                    'percent'
                                                                ],
                                                                0
                                                            )
                                                        ); ?>%
                                            </strong>
                                        </div>

                                        <?php if (
                                                    $hasCourseDates
                                                ): ?>
                                        <div class="course-card-stat-wide">
                                            <span>Course Dates</span>
                                            <strong>
                                                <?= e(
                                                                student_courses_format_date(
                                                                    $courseRow[
                                                                        'course_start_date'
                                                                    ]
                                                                    ?? null
                                                                )
                                                            ); ?>
                                                –
                                                <?= e(
                                                                student_courses_format_date(
                                                                    $courseRow[
                                                                        'course_end_date'
                                                                    ]
                                                                    ?? null
                                                                )
                                                            ); ?>
                                            </strong>
                                        </div>
                                        <?php endif; ?>

                                    </div>

                                    <div class="course-card-progress">
                                        <div class="course-card-progress-label">
                                            <span>Lesson progress</span>
                                            <span>
                                                <?= e(
                                                            number_format(
                                                                (float) $summary[
                                                                    'percent'
                                                                ],
                                                                0
                                                            )
                                                        ); ?>%
                                            </span>
                                        </div>

                                        <progress value="<?= e(
                                                        number_format(
                                                            (float) $summary[
                                                                'percent'
                                                            ],
                                                            2,
                                                            '.',
                                                            ''
                                                        )
                                                    ); ?>" max="100">
                                            <?= e(
                                                        number_format(
                                                            (float) $summary[
                                                                'percent'
                                                            ],
                                                            0
                                                        )
                                                    ); ?>%
                                        </progress>
                                    </div>

                                    <div class="course-card-actions">
                                        <a class="button button-primary" href="<?= e(
                                                        url(
                                                            'course.php?offering='
                                                            . (int) $courseRow[
                                                                'offering_id'
                                                            ]
                                                        )
                                                    ); ?>">
                                            Enter Classroom
                                        </a>
                                    </div>

                                </div>

                            </article>

                            <?php endforeach; ?>

                        </div>

                        <?php endif; ?>

                    </div>

                </section>


                <?php if (
                    $completedCourses !== []
                ): ?>

                <section class="dashboard-workspace-panel">

                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">
                                Course History
                            </p>

                            <h2>
                                Completed Courses
                            </h2>
                        </div>
                    </div>

                    <div class="dashboard-panel-body">

                        <div class="dashboard-placeholder-list">

                            <?php foreach (
                                    $completedCourses
                                    as $courseRow
                                ): ?>
                            <?php
                                    $summary =
                                        $courseRow[
                                            'lesson_summary'
                                        ];
                                    ?>

                            <span>

                                <strong>
                                    <?= e(
                                                (string) $courseRow[
                                                    'title'
                                                ]
                                            ); ?>
                                </strong>

                                <?php if (
                                            trim(
                                                (string) (
                                                    $courseRow[
                                                        'school_year_name'
                                                    ]
                                                    ?? ''
                                                )
                                            ) !== ''
                                        ): ?>
                                ·
                                <?= e(
                                                (string) $courseRow[
                                                    'school_year_name'
                                                ]
                                            ); ?>
                                <?php endif; ?>

                                <?php if (
                                            !empty(
                                                $courseRow[
                                                    'completed_at'
                                                ]
                                            )
                                        ): ?>
                                · Completed
                                <?= e(
                                                student_courses_format_date(
                                                    $courseRow[
                                                        'completed_at'
                                                    ]
                                                )
                                            ); ?>
                                <?php endif; ?>

                                ·
                                <?= number_format(
                                            (int) $summary[
                                                'completed'
                                            ]
                                        ); ?>
                                /
                                <?= number_format(
                                            (int) $summary[
                                                'total'
                                            ]
                                        ); ?>
                                lessons complete

                                ·
                                <a href="<?= e(
                                                url(
                                                    'course.php?offering='
                                                    . (int) $courseRow[
                                                        'offering_id'
                                                    ]
                                                )
                                            ); ?>">
                                    Review Course
                                </a>

                            </span>

                            <?php endforeach; ?>

                        </div>

                    </div>

                </section>

                <?php endif; ?>


                <?php if (
                    $inactiveCourses !== []
                ): ?>

                <section class="dashboard-workspace-panel">

                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">
                                Enrollment History
                            </p>

                            <h2>
                                Other Courses
                            </h2>
                        </div>
                    </div>

                    <div class="dashboard-panel-body">

                        <div class="dashboard-placeholder-list">

                            <?php foreach (
                                    $inactiveCourses
                                    as $courseRow
                                ): ?>

                            <span>

                                <strong>
                                    <?= e(
                                                (string) $courseRow[
                                                    'title'
                                                ]
                                            ); ?>
                                </strong>

                                ·
                                <?= e(
                                            ucfirst(
                                                (string) $courseRow[
                                                    'enrollment_status'
                                                ]
                                            )
                                        ); ?>

                                <?php if (
                                            trim(
                                                (string) (
                                                    $courseRow[
                                                        'school_year_name'
                                                    ]
                                                    ?? ''
                                                )
                                            ) !== ''
                                        ): ?>
                                ·
                                <?= e(
                                                (string) $courseRow[
                                                    'school_year_name'
                                                ]
                                            ); ?>
                                <?php endif; ?>

                            </span>

                            <?php endforeach; ?>

                        </div>

                    </div>

                </section>

                <?php endif; ?>

            </div>

        </div>
    </section>

</main>

<?php

require INCLUDES_PATH . '/footer.php';
