<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Blackthorne Academy - Course Completion Engine
|--------------------------------------------------------------------------
|
| Shared course-completion logic for student enrollments.
|
| This file intentionally renders no HTML. Lesson, assignment, quiz,
| classroom, dashboard, and future staff workflows can all call the same
| completion checks after student progress changes.
|
| Current completion rules:
| - Every lesson attached to the offering must be completed.
| - Every assignment attached to the offering must have a graded submission.
| - Every quiz / midterm / final attached to the offering must have a graded,
|   passing attempt.
| - A course with no completion requirements is never auto-completed.
| - Suspended or withdrawn enrollments are never changed automatically.
|
*/


/*
|--------------------------------------------------------------------------
| Enrollment Context
|--------------------------------------------------------------------------
*/

function blackthorne_course_completion_enrollment(
    PDO $pdo,
    int $enrollmentId
): ?array {
    if ($enrollmentId <= 0) {
        return null;
    }

    $statement =
        $pdo->prepare(
            '
            SELECT
                ce.id AS enrollment_id,
                ce.user_id,
                ce.offering_id,
                ce.status AS enrollment_status,
                ce.progress,
                ce.enrolled_at,
                ce.completed_at,
                ce.withdrawn_at,
                ce.suspended_at,

                co.course_id,
                co.offering_scope,
                co.school_year_id,
                co.status AS offering_status,

                c.title AS course_title,
                c.slug AS course_slug,
                c.status AS course_status

            FROM course_enrollments ce

            INNER JOIN course_offerings co
                ON co.id = ce.offering_id

            INNER JOIN courses c
                ON c.id = co.course_id

            WHERE ce.id = :enrollment_id

            LIMIT 1
            '
        );

    $statement->execute([
        'enrollment_id' =>
            $enrollmentId,
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
| Lesson Requirements
|--------------------------------------------------------------------------
*/

function blackthorne_course_completion_lessons(
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
            'completed' => 0,
            'remaining' => 0,
        ];
    }

    $statement =
        $pdo->prepare(
            '
            SELECT
                COUNT(*) AS total_lessons,
                COALESCE(
                    SUM(
                        CASE
                            WHEN lp.status = \'completed\' THEN 1
                            ELSE 0
                        END
                    ),
                    0
                ) AS completed_lessons

            FROM course_offering_lessons col

            LEFT JOIN lesson_progress lp
                ON lp.enrollment_id = :enrollment_id
               AND lp.lesson_id = col.lesson_id

            WHERE col.offering_id = :offering_id
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
        (int) ($row['total_lessons'] ?? 0);

    $completed =
        (int) ($row['completed_lessons'] ?? 0);

    return [
        'total' =>
            $total,

        'completed' =>
            $completed,

        'remaining' =>
            max(0, $total - $completed),
    ];
}


/*
|--------------------------------------------------------------------------
| Assignment Requirements
|--------------------------------------------------------------------------
*/

function blackthorne_course_completion_assignments(
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
            'completed' => 0,
            'remaining' => 0,
        ];
    }

    $statement =
        $pdo->prepare(
            '
            SELECT
                COUNT(*) AS total_assignments,
                COALESCE(
                    SUM(
                        CASE
                            WHEN EXISTS (
                                SELECT 1
                                FROM assignment_submissions submission
                                WHERE submission.enrollment_id = :enrollment_id
                                  AND submission.offering_id = aos.offering_id
                                  AND submission.assignment_id = aos.assignment_id
                                  AND submission.status = \'graded\'
                            ) THEN 1
                            ELSE 0
                        END
                    ),
                    0
                ) AS completed_assignments

            FROM assignment_offering_settings aos

            WHERE aos.offering_id = :offering_id
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
        (int) ($row['total_assignments'] ?? 0);

    $completed =
        (int) ($row['completed_assignments'] ?? 0);

    return [
        'total' =>
            $total,

        'completed' =>
            $completed,

        'remaining' =>
            max(0, $total - $completed),
    ];
}


/*
|--------------------------------------------------------------------------
| Assessment Requirements
|--------------------------------------------------------------------------
*/

function blackthorne_course_completion_assessments(
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
            'completed' => 0,
            'remaining' => 0,
            'quizzes' => [
                'total' => 0,
                'completed' => 0,
            ],
            'midterms' => [
                'total' => 0,
                'completed' => 0,
            ],
            'finals' => [
                'total' => 0,
                'completed' => 0,
            ],
        ];
    }

    $statement =
        $pdo->prepare(
            '
            SELECT
                q.assessment_type,
                COUNT(*) AS total_assessments,
                COALESCE(
                    SUM(
                        CASE
                            WHEN EXISTS (
                                SELECT 1
                                FROM quiz_attempts qa
                                WHERE qa.enrollment_id = :enrollment_id
                                  AND qa.offering_id = qos.offering_id
                                  AND qa.quiz_id = qos.quiz_id
                                  AND qa.status = \'graded\'
                                  AND qa.passed = 1
                            ) THEN 1
                            ELSE 0
                        END
                    ),
                    0
                ) AS completed_assessments

            FROM quiz_offering_settings qos

            INNER JOIN quizzes q
                ON q.id = qos.quiz_id

            WHERE qos.offering_id = :offering_id

            GROUP BY q.assessment_type
            '
        );

    $statement->execute([
        'enrollment_id' =>
            $enrollmentId,

        'offering_id' =>
            $offeringId,
    ]);

    $rows =
        $statement->fetchAll(
            PDO::FETCH_ASSOC
        );

    $summary = [
        'total' => 0,
        'completed' => 0,
        'remaining' => 0,
        'quizzes' => [
            'total' => 0,
            'completed' => 0,
        ],
        'midterms' => [
            'total' => 0,
            'completed' => 0,
        ],
        'finals' => [
            'total' => 0,
            'completed' => 0,
        ],
    ];

    foreach ($rows as $row) {
        $type =
            (string) ($row['assessment_type'] ?? 'quiz');

        $total =
            (int) ($row['total_assessments'] ?? 0);

        $completed =
            (int) ($row['completed_assessments'] ?? 0);

        $summary['total'] +=
            $total;

        $summary['completed'] +=
            $completed;

        $bucket =
            match ($type) {
                'midterm' => 'midterms',
                'final' => 'finals',
                default => 'quizzes',
            };

        $summary[$bucket]['total'] +=
            $total;

        $summary[$bucket]['completed'] +=
            $completed;
    }

    $summary['remaining'] =
        max(
            0,
            $summary['total'] - $summary['completed']
        );

    return $summary;
}


/*
|--------------------------------------------------------------------------
| Combined Completion Summary
|--------------------------------------------------------------------------
*/

function blackthorne_course_completion_summary(
    PDO $pdo,
    int $enrollmentId
): ?array {
    $enrollment =
        blackthorne_course_completion_enrollment(
            $pdo,
            $enrollmentId
        );

    if ($enrollment === null) {
        return null;
    }

    $offeringId =
        (int) $enrollment['offering_id'];

    $lessons =
        blackthorne_course_completion_lessons(
            $pdo,
            $enrollmentId,
            $offeringId
        );

    $assignments =
        blackthorne_course_completion_assignments(
            $pdo,
            $enrollmentId,
            $offeringId
        );

    $assessments =
        blackthorne_course_completion_assessments(
            $pdo,
            $enrollmentId,
            $offeringId
        );

    $requiredTotal =
        $lessons['total']
        + $assignments['total']
        + $assessments['total'];

    $completedTotal =
        $lessons['completed']
        + $assignments['completed']
        + $assessments['completed'];

    $progress =
        $requiredTotal > 0
            ? round(
                ($completedTotal / $requiredTotal) * 100,
                2
            )
            : 0.0;

    $isComplete =
        $requiredTotal > 0
        && $completedTotal >= $requiredTotal;

    return [
        'enrollment' =>
            $enrollment,

        'lessons' =>
            $lessons,

        'assignments' =>
            $assignments,

        'assessments' =>
            $assessments,

        'required_total' =>
            $requiredTotal,

        'completed_total' =>
            $completedTotal,

        'remaining_total' =>
            max(0, $requiredTotal - $completedTotal),

        'progress' =>
            $progress,

        'is_complete' =>
            $isComplete,
    ];
}


/*
|--------------------------------------------------------------------------
| Persist Progress / Completion
|--------------------------------------------------------------------------
*/

function blackthorne_recalculate_course_completion(
    PDO $pdo,
    int $enrollmentId
): ?array {
    $summary =
        blackthorne_course_completion_summary(
            $pdo,
            $enrollmentId
        );

    if ($summary === null) {
        return null;
    }

    $enrollment =
        $summary['enrollment'];

    $currentStatus =
        (string) $enrollment['enrollment_status'];

    if (
        $currentStatus === 'withdrawn'
        || $currentStatus === 'suspended'
    ) {
        $summary['status_changed'] = false;
        $summary['progress_saved'] = false;

        return $summary;
    }

    if ($currentStatus === 'completed') {
        $summary['status_changed'] = false;
        $summary['progress_saved'] = false;
        $summary['progress'] = 100.0;
        $summary['is_complete'] = true;

        return $summary;
    }

    $progress =
        max(
            0.0,
            min(
                100.0,
                (float) $summary['progress']
            )
        );

    $statusChanged = false;

    if ($summary['is_complete'] === true) {
        $statement =
            $pdo->prepare(
                '
                UPDATE course_enrollments
                SET
                    status = \'completed\',
                    progress = 100.00,
                    completed_at = COALESCE(completed_at, NOW())
                WHERE id = :enrollment_id
                  AND status = \'enrolled\'
                '
            );

        $statement->execute([
            'enrollment_id' =>
                $enrollmentId,
        ]);

        $statusChanged =
            $statement->rowCount() > 0;

        $summary['progress'] = 100.0;
        $summary['enrollment']['enrollment_status'] = 'completed';
    } else {
        $statement =
            $pdo->prepare(
                '
                UPDATE course_enrollments
                SET progress = :progress
                WHERE id = :enrollment_id
                  AND status = \'enrolled\'
                '
            );

        $statement->execute([
            'progress' =>
                number_format(
                    $progress,
                    2,
                    '.',
                    ''
                ),

            'enrollment_id' =>
                $enrollmentId,
        ]);
    }

    $summary['status_changed'] =
        $statusChanged;

    $summary['progress_saved'] =
        true;

    return $summary;
}


/*
|--------------------------------------------------------------------------
| Convenience Lookup by User + Offering
|--------------------------------------------------------------------------
*/

function blackthorne_recalculate_user_course_completion(
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
            SELECT id
            FROM course_enrollments
            WHERE user_id = :user_id
              AND offering_id = :offering_id
            LIMIT 1
            '
        );

    $statement->execute([
        'user_id' =>
            $userId,

        'offering_id' =>
            $offeringId,
    ]);

    $enrollmentId =
        (int) ($statement->fetchColumn() ?: 0);

    if ($enrollmentId <= 0) {
        return null;
    }

    return blackthorne_recalculate_course_completion(
        $pdo,
        $enrollmentId
    );
}
