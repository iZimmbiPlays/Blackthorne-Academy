<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Blackthorne Academy - Course Lesson Progress Engine
|--------------------------------------------------------------------------
|
| Shared lesson availability and progress helpers.
|
| This file intentionally contains no page output. Student-facing course pages,
| dashboards, Orientation, and future APIs can all use the same rules.
|
*/


/*
|--------------------------------------------------------------------------
| Enrollment Lookup
|--------------------------------------------------------------------------
*/

function blackthorne_course_enrollment(
    PDO $pdo,
    int $userId,
    int $offeringId
): ?array {
    if (
        $userId <= 0
        || $offeringId <= 0
    ) {
        return null;
    }

    $statement =
        $pdo->prepare(
            '
            SELECT
                ce.id,
                ce.user_id,
                ce.offering_id,
                ce.status,
                ce.progress,
                ce.enrolled_at,
                ce.completed_at,
                ce.withdrawn_at,
                ce.suspended_at,

                co.course_id,
                co.school_year_id,
                co.pacing_mode,
                co.drip_basis,
                co.course_start_date,
                co.course_end_date,
                co.status AS offering_status,

                sy.course_access_ends_at

            FROM course_enrollments ce

            INNER JOIN course_offerings co
                ON co.id = ce.offering_id

            LEFT JOIN school_years sy
                ON sy.id = co.school_year_id

            WHERE ce.user_id = :user_id
              AND ce.offering_id = :offering_id

            LIMIT 1
            '
        );

    $statement->execute([
        'user_id' =>
            $userId,

        'offering_id' =>
            $offeringId,
    ]);

    $row =
        $statement->fetch(
            PDO::FETCH_ASSOC
        );

    return is_array($row)
        ? $row
        : null;
}


/*
|--------------------------------------------------------------------------
| Offering Lesson Lookup
|--------------------------------------------------------------------------
*/

function blackthorne_offering_lesson(
    PDO $pdo,
    int $offeringId,
    int $lessonId
): ?array {
    if (
        $offeringId <= 0
        || $lessonId <= 0
    ) {
        return null;
    }

    $statement =
        $pdo->prepare(
            '
            SELECT
                col.id AS offering_lesson_id,
                col.offering_id,
                col.lesson_id,
                col.lesson_version_id,
                col.sort_order,

                l.course_id,
                l.internal_name,
                l.slug,
                l.is_published,

                lv.version_number,
                lv.title,
                lv.description,
                lv.content,
                lv.status AS version_status,
                lv.published_at,

                lrr.id AS release_rule_id,
                lrr.is_enabled AS release_enabled,
                lrr.release_type,
                lrr.release_delay_days,
                lrr.release_at,
                lrr.prerequisite_lesson_id

            FROM course_offering_lessons col

            INNER JOIN lessons l
                ON l.id = col.lesson_id

            LEFT JOIN lesson_versions lv
                ON lv.id = col.lesson_version_id

            LEFT JOIN lesson_release_rules lrr
                ON lrr.offering_id = col.offering_id
               AND lrr.lesson_id = col.lesson_id

            WHERE col.offering_id = :offering_id
              AND col.lesson_id = :lesson_id

            LIMIT 1
            '
        );

    $statement->execute([
        'offering_id' =>
            $offeringId,

        'lesson_id' =>
            $lessonId,
    ]);

    $row =
        $statement->fetch(
            PDO::FETCH_ASSOC
        );

    return is_array($row)
        ? $row
        : null;
}


/*
|--------------------------------------------------------------------------
| Progress Lookup
|--------------------------------------------------------------------------
*/

function blackthorne_lesson_progress(
    PDO $pdo,
    int $enrollmentId,
    int $lessonId
): ?array {
    if (
        $enrollmentId <= 0
        || $lessonId <= 0
    ) {
        return null;
    }

    $statement =
        $pdo->prepare(
            '
            SELECT
                id,
                enrollment_id,
                lesson_id,
                status,
                started_at,
                completed_at,
                updated_at

            FROM lesson_progress

            WHERE enrollment_id = :enrollment_id
              AND lesson_id = :lesson_id

            LIMIT 1
            '
        );

    $statement->execute([
        'enrollment_id' =>
            $enrollmentId,

        'lesson_id' =>
            $lessonId,
    ]);

    $row =
        $statement->fetch(
            PDO::FETCH_ASSOC
        );

    return is_array($row)
        ? $row
        : null;
}


/*
|--------------------------------------------------------------------------
| Date Helpers
|--------------------------------------------------------------------------
*/

function blackthorne_lesson_progress_now(
    ?DateTimeImmutable $now = null
): DateTimeImmutable {
    return $now
        ?? new DateTimeImmutable('now');
}


function blackthorne_lesson_progress_datetime(
    ?string $value
): ?DateTimeImmutable {
    $value =
        trim(
            (string) $value
        );

    if ($value === '') {
        return null;
    }

    try {
        return new DateTimeImmutable(
            $value
        );
    } catch (Throwable $exception) {
        return null;
    }
}


/*
|--------------------------------------------------------------------------
| Lesson Release / Availability
|--------------------------------------------------------------------------
|
| Return structure:
|
| [
|   'available' => bool,
|   'reason' => string,
|   'release_type' => string,
|   'unlocks_at' => ?string,
|   'prerequisite_lesson_id' => ?int,
| ]
|
*/

function blackthorne_lesson_availability(
    PDO $pdo,
    array $enrollment,
    array $offeringLesson,
    ?DateTimeImmutable $now = null
): array {
    $now =
        blackthorne_lesson_progress_now(
            $now
        );

    $result = [
        'available' => false,
        'reason' => 'unavailable',
        'release_type' => 'immediate',
        'unlocks_at' => null,
        'prerequisite_lesson_id' => null,
    ];

    $enrollmentId =
        (int) (
            $enrollment['id']
            ?? 0
        );

    $enrollmentOfferingId =
        (int) (
            $enrollment['offering_id']
            ?? 0
        );

    $lessonOfferingId =
        (int) (
            $offeringLesson['offering_id']
            ?? 0
        );

    $lessonId =
        (int) (
            $offeringLesson['lesson_id']
            ?? 0
        );

    if (
        $enrollmentId <= 0
        || $lessonId <= 0
        || $enrollmentOfferingId <= 0
        || $lessonOfferingId <= 0
        || $enrollmentOfferingId
            !== $lessonOfferingId
    ) {
        $result['reason'] =
            'invalid_context';

        return $result;
    }

    $enrollmentStatus =
        (string) (
            $enrollment['status']
            ?? ''
        );

    if (
        !in_array(
            $enrollmentStatus,
            [
                'enrolled',
                'completed',
            ],
            true
        )
    ) {
        $result['reason'] =
            $enrollmentStatus === 'suspended'
                ? 'enrollment_suspended'
                : 'enrollment_inactive';

        return $result;
    }

    $offeringStatus =
        (string) (
            $enrollment[
                'offering_status'
            ]
            ?? ''
        );

    if ($offeringStatus === 'archived') {
        $result['reason'] =
            'offering_archived';

        return $result;
    }

    $courseAccessEndsAt =
        blackthorne_lesson_progress_datetime(
            $enrollment[
                'course_access_ends_at'
            ]
            ?? null
        );

    if (
        $courseAccessEndsAt !== null
        && $now > $courseAccessEndsAt
    ) {
        $result['reason'] =
            'course_access_ended';

        return $result;
    }

    if (
        (int) (
            $offeringLesson[
                'is_published'
            ]
            ?? 0
        ) !== 1
    ) {
        $result['reason'] =
            'lesson_unpublished';

        return $result;
    }

    if (
        (string) (
            $offeringLesson[
                'version_status'
            ]
            ?? ''
        ) !== 'published'
    ) {
        $result['reason'] =
            'lesson_version_unpublished';

        return $result;
    }

    $releaseEnabled =
        $offeringLesson[
            'release_enabled'
        ]
        ?? null;

    if (
        $releaseEnabled !== null
        && (int) $releaseEnabled !== 1
    ) {
        $result['available'] = true;
        $result['reason'] =
            'release_rule_disabled';

        return $result;
    }

    $releaseType =
        (string) (
            $offeringLesson[
                'release_type'
            ]
            ?? 'immediate'
        );

    $result['release_type'] =
        $releaseType;

    if ($releaseType === 'immediate') {
        $result['available'] = true;
        $result['reason'] =
            'immediate';

        return $result;
    }

    if (
        $releaseType
        === 'days_after_course_start'
    ) {
        $courseStart =
            blackthorne_lesson_progress_datetime(
                $enrollment[
                    'course_start_date'
                ]
                ?? null
            );

        if ($courseStart === null) {
            $result['reason'] =
                'course_start_missing';

            return $result;
        }

        $delayDays =
            max(
                0,
                (int) (
                    $offeringLesson[
                        'release_delay_days'
                    ]
                    ?? 0
                )
            );

        $unlocksAt =
            $courseStart->modify(
                '+'
                . $delayDays
                . ' days'
            );

        $result['unlocks_at'] =
            $unlocksAt->format(
                'Y-m-d H:i:s'
            );

        $result['available'] =
            $now >= $unlocksAt;

        $result['reason'] =
            $result['available']
                ? 'course_start_delay_met'
                : 'waiting_for_course_start_delay';

        return $result;
    }

    if ($releaseType === 'fixed_date') {
        $unlocksAt =
            blackthorne_lesson_progress_datetime(
                $offeringLesson[
                    'release_at'
                ]
                ?? null
            );

        if ($unlocksAt === null) {
            $result['reason'] =
                'fixed_release_date_missing';

            return $result;
        }

        $result['unlocks_at'] =
            $unlocksAt->format(
                'Y-m-d H:i:s'
            );

        $result['available'] =
            $now >= $unlocksAt;

        $result['reason'] =
            $result['available']
                ? 'fixed_release_date_met'
                : 'waiting_for_fixed_release_date';

        return $result;
    }

    if (
        $releaseType
        === 'after_previous_lesson'
    ) {
        $prerequisiteLessonId =
            (int) (
                $offeringLesson[
                    'prerequisite_lesson_id'
                ]
                ?? 0
            );

        $result[
            'prerequisite_lesson_id'
        ] =
            $prerequisiteLessonId > 0
                ? $prerequisiteLessonId
                : null;

        if ($prerequisiteLessonId <= 0) {
            $result['reason'] =
                'prerequisite_lesson_missing';

            return $result;
        }

        $prerequisiteStatement =
            $pdo->prepare(
                '
                SELECT lp.status

                FROM lesson_progress lp

                INNER JOIN course_offering_lessons col
                    ON col.offering_id = :offering_id
                   AND col.lesson_id = lp.lesson_id

                WHERE lp.enrollment_id = :enrollment_id
                  AND lp.lesson_id = :lesson_id

                LIMIT 1
                '
            );

        $prerequisiteStatement->execute([
            'offering_id' =>
                $lessonOfferingId,

            'enrollment_id' =>
                $enrollmentId,

            'lesson_id' =>
                $prerequisiteLessonId,
        ]);

        $prerequisiteStatus =
            $prerequisiteStatement->fetchColumn();

        $result['available'] =
            $prerequisiteStatus
            === 'completed';

        $result['reason'] =
            $result['available']
                ? 'prerequisite_completed'
                : 'waiting_for_prerequisite';

        return $result;
    }

    /*
     * Legacy support only.
     *
     * Blackthorne's current admin UI does not create new
     * enrollment-relative lesson rules because late registrants
     * must join the existing cohort schedule. This branch exists
     * only so older rows remain readable if any exist.
     */
    if (
        $releaseType
        === 'days_after_enrollment'
    ) {
        $enrolledAt =
            blackthorne_lesson_progress_datetime(
                $enrollment[
                    'enrolled_at'
                ]
                ?? null
            );

        if ($enrolledAt === null) {
            $result['reason'] =
                'enrollment_date_missing';

            return $result;
        }

        $delayDays =
            max(
                0,
                (int) (
                    $offeringLesson[
                        'release_delay_days'
                    ]
                    ?? 0
                )
            );

        $unlocksAt =
            $enrolledAt->modify(
                '+'
                . $delayDays
                . ' days'
            );

        $result['unlocks_at'] =
            $unlocksAt->format(
                'Y-m-d H:i:s'
            );

        $result['available'] =
            $now >= $unlocksAt;

        $result['reason'] =
            $result['available']
                ? 'legacy_enrollment_delay_met'
                : 'waiting_for_legacy_enrollment_delay';

        return $result;
    }

    $result['reason'] =
        'unknown_release_rule';

    return $result;
}


/*
|--------------------------------------------------------------------------
| Availability For User
|--------------------------------------------------------------------------
*/

function blackthorne_user_lesson_availability(
    PDO $pdo,
    int $userId,
    int $offeringId,
    int $lessonId,
    ?DateTimeImmutable $now = null
): array {
    $enrollment =
        blackthorne_course_enrollment(
            $pdo,
            $userId,
            $offeringId
        );

    if ($enrollment === null) {
        return [
            'available' => false,
            'reason' => 'not_enrolled',
            'release_type' => 'immediate',
            'unlocks_at' => null,
            'prerequisite_lesson_id' => null,
        ];
    }

    $offeringLesson =
        blackthorne_offering_lesson(
            $pdo,
            $offeringId,
            $lessonId
        );

    if ($offeringLesson === null) {
        return [
            'available' => false,
            'reason' => 'lesson_not_in_offering',
            'release_type' => 'immediate',
            'unlocks_at' => null,
            'prerequisite_lesson_id' => null,
        ];
    }

    return blackthorne_lesson_availability(
        $pdo,
        $enrollment,
        $offeringLesson,
        $now
    );
}


/*
|--------------------------------------------------------------------------
| Mark Lesson Started
|--------------------------------------------------------------------------
*/

function blackthorne_mark_lesson_started(
    PDO $pdo,
    int $enrollmentId,
    int $lessonId
): bool {
    if (
        $enrollmentId <= 0
        || $lessonId <= 0
    ) {
        return false;
    }

    $statement =
        $pdo->prepare(
            '
            INSERT INTO lesson_progress (
                enrollment_id,
                lesson_id,
                status,
                started_at,
                completed_at
            ) VALUES (
                :enrollment_id,
                :lesson_id,
                "in_progress",
                NOW(),
                NULL
            )

            ON DUPLICATE KEY UPDATE
                status = CASE
                    WHEN status = "completed"
                    THEN status
                    ELSE "in_progress"
                END,

                started_at = CASE
                    WHEN started_at IS NULL
                    THEN NOW()
                    ELSE started_at
                END
            '
        );

    return $statement->execute([
        'enrollment_id' =>
            $enrollmentId,

        'lesson_id' =>
            $lessonId,
    ]);
}


/*
|--------------------------------------------------------------------------
| Mark Lesson Completed
|--------------------------------------------------------------------------
*/

function blackthorne_mark_lesson_completed(
    PDO $pdo,
    int $enrollmentId,
    int $lessonId
): bool {
    if (
        $enrollmentId <= 0
        || $lessonId <= 0
    ) {
        return false;
    }

    $statement =
        $pdo->prepare(
            '
            INSERT INTO lesson_progress (
                enrollment_id,
                lesson_id,
                status,
                started_at,
                completed_at
            ) VALUES (
                :enrollment_id,
                :lesson_id,
                "completed",
                NOW(),
                NOW()
            )

            ON DUPLICATE KEY UPDATE
                status = "completed",

                started_at = CASE
                    WHEN started_at IS NULL
                    THEN NOW()
                    ELSE started_at
                END,

                completed_at = CASE
                    WHEN completed_at IS NULL
                    THEN NOW()
                    ELSE completed_at
                END
            '
        );

    return $statement->execute([
        'enrollment_id' =>
            $enrollmentId,

        'lesson_id' =>
            $lessonId,
    ]);
}


/*
|--------------------------------------------------------------------------
| Offering Lesson Progress Summary
|--------------------------------------------------------------------------
|
| This calculates lesson completion only. It does not finalize a course
| enrollment because assignments, quizzes, midterms, and finals may also
| be required for course completion.
|
*/

function blackthorne_lesson_progress_summary(
    PDO $pdo,
    int $enrollmentId,
    int $offeringId
): array {
    if (
        $enrollmentId <= 0
        || $offeringId <= 0
    ) {
        return [
            'total' => 0,
            'not_started' => 0,
            'in_progress' => 0,
            'completed' => 0,
            'percent' => 0.0,
        ];
    }

    $statement =
        $pdo->prepare(
            '
            SELECT
                COUNT(*) AS total_lessons,

                SUM(
                    CASE
                        WHEN COALESCE(
                            lp.status,
                            "not_started"
                        ) = "not_started"
                        THEN 1
                        ELSE 0
                    END
                ) AS not_started_lessons,

                SUM(
                    CASE
                        WHEN lp.status = "in_progress"
                        THEN 1
                        ELSE 0
                    END
                ) AS in_progress_lessons,

                SUM(
                    CASE
                        WHEN lp.status = "completed"
                        THEN 1
                        ELSE 0
                    END
                ) AS completed_lessons

            FROM course_offering_lessons col

            INNER JOIN lessons l
                ON l.id = col.lesson_id

            INNER JOIN lesson_versions lv
                ON lv.id = col.lesson_version_id

            LEFT JOIN lesson_progress lp
                ON lp.enrollment_id = :enrollment_id
               AND lp.lesson_id = col.lesson_id

            WHERE col.offering_id = :offering_id
              AND l.is_published = 1
              AND lv.status = "published"
            '
        );

    $statement->execute([
        'enrollment_id' =>
            $enrollmentId,

        'offering_id' =>
            $offeringId,
    ]);

    $row =
        $statement->fetch(
            PDO::FETCH_ASSOC
        );

    $total =
        (int) (
            $row['total_lessons']
            ?? 0
        );

    $completed =
        (int) (
            $row['completed_lessons']
            ?? 0
        );

    return [
        'total' =>
            $total,

        'not_started' =>
            (int) (
                $row['not_started_lessons']
                ?? 0
            ),

        'in_progress' =>
            (int) (
                $row['in_progress_lessons']
                ?? 0
            ),

        'completed' =>
            $completed,

        'percent' =>
            $total > 0
                ? round(
                    ($completed / $total) * 100,
                    2
                )
                : 0.0,
    ];
}


/*
|--------------------------------------------------------------------------
| Offering Lesson List With Availability + Progress
|--------------------------------------------------------------------------
*/

function blackthorne_offering_lessons_for_user(
    PDO $pdo,
    int $userId,
    int $offeringId,
    ?DateTimeImmutable $now = null
): array {
    $enrollment =
        blackthorne_course_enrollment(
            $pdo,
            $userId,
            $offeringId
        );

    if ($enrollment === null) {
        return [];
    }

    $statement =
        $pdo->prepare(
            '
            SELECT
                col.id AS offering_lesson_id,
                col.offering_id,
                col.lesson_id,
                col.lesson_version_id,
                col.sort_order,

                l.course_id,
                l.internal_name,
                l.slug,
                l.is_published,

                lv.version_number,
                lv.title,
                lv.description,
                lv.status AS version_status,
                lv.published_at,

                lrr.is_enabled AS release_enabled,
                lrr.release_type,
                lrr.release_delay_days,
                lrr.release_at,
                lrr.prerequisite_lesson_id,

                lp.status AS progress_status,
                lp.started_at,
                lp.completed_at

            FROM course_offering_lessons col

            INNER JOIN lessons l
                ON l.id = col.lesson_id

            INNER JOIN lesson_versions lv
                ON lv.id = col.lesson_version_id

            LEFT JOIN lesson_release_rules lrr
                ON lrr.offering_id = col.offering_id
               AND lrr.lesson_id = col.lesson_id

            LEFT JOIN lesson_progress lp
                ON lp.enrollment_id = :enrollment_id
               AND lp.lesson_id = col.lesson_id

            WHERE col.offering_id = :offering_id

            ORDER BY
                col.sort_order ASC,
                col.id ASC
            '
        );

    $statement->execute([
        'enrollment_id' =>
            (int) $enrollment['id'],

        'offering_id' =>
            $offeringId,
    ]);

    $rows =
        $statement->fetchAll(
            PDO::FETCH_ASSOC
        );

    foreach ($rows as &$row) {
        $availability =
            blackthorne_lesson_availability(
                $pdo,
                $enrollment,
                $row,
                $now
            );

        $row['available'] =
            $availability['available'];

        $row['availability_reason'] =
            $availability['reason'];

        $row['unlocks_at'] =
            $availability['unlocks_at'];

        $row['progress_status'] =
            (string) (
                $row['progress_status']
                ?? 'not_started'
            );
    }

    unset($row);

    return $rows;
}
