<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Blackthorne Academy - Assignment Submission Engine
|--------------------------------------------------------------------------
|
| Shared rules for student assignment attempts and submissions.
|
| This file does not render HTML. Student-facing assignment pages can use the
| same functions for availability, draft creation, resubmission limits, late
| rules, close dates, text/file validation, and final submission.
|
*/


/*
|--------------------------------------------------------------------------
| Assignment Context
|--------------------------------------------------------------------------
*/

function blackthorne_assignment_context(
    PDO $pdo,
    int $userId,
    int $offeringId,
    int $assignmentId
): ?array {
    if (
        $userId <= 0
        || $offeringId <= 0
        || $assignmentId <= 0
    ) {
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
                ce.enrolled_at,
                ce.completed_at AS enrollment_completed_at,
                ce.withdrawn_at,
                ce.suspended_at,

                co.course_id,
                co.offering_scope,
                co.status AS offering_status,

                sy.course_access_ends_at,

                aos.id AS offering_setting_id,
                aos.assignment_id,
                aos.assignment_version_id,
                aos.course_offering_lesson_id,
                aos.points_possible,
                aos.due_date,
                aos.submission_close_date,
                aos.allow_resubmissions,
                aos.max_submissions,
                aos.allow_late_submissions,
                aos.allow_extra_credit,
                aos.extra_credit_max_points,

                a.internal_name,
                a.is_published,

                av.version_number,
                av.title,
                av.description,
                av.instructions,
                av.allow_text_submission,
                av.allow_file_upload,
                av.status AS version_status,
                av.published_at

            FROM course_enrollments ce

            INNER JOIN course_offerings co
                ON co.id = ce.offering_id

            LEFT JOIN school_years sy
                ON sy.id = co.school_year_id

            INNER JOIN assignment_offering_settings aos
                ON aos.offering_id = co.id

            INNER JOIN assignments a
                ON a.id = aos.assignment_id

            INNER JOIN assignment_versions av
                ON av.id = aos.assignment_version_id

            WHERE ce.user_id = :user_id
              AND ce.offering_id = :offering_id
              AND aos.assignment_id = :assignment_id

            LIMIT 1
            '
        );

    $statement->execute([
        'user_id' =>
            $userId,

        'offering_id' =>
            $offeringId,

        'assignment_id' =>
            $assignmentId,
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
| Attempt History
|--------------------------------------------------------------------------
*/

function blackthorne_assignment_attempts(
    PDO $pdo,
    int $enrollmentId,
    int $offeringId,
    int $assignmentId
): array {
    if (
        $enrollmentId <= 0
        || $offeringId <= 0
        || $assignmentId <= 0
    ) {
        return [];
    }

    $statement =
        $pdo->prepare(
            '
            SELECT
                id,
                assignment_id,
                assignment_version_id,
                points_possible_snapshot,
                enrollment_id,
                offering_id,
                attempt_number,
                submission_text,
                file_path,
                status,
                grade,
                raw_points_earned,
                late_penalty_points,
                extra_credit_points,
                instructor_feedback,
                submitted_at,
                locked_at,
                graded_at,
                graded_by,
                created_at,
                updated_at

            FROM assignment_submissions

            WHERE enrollment_id = :enrollment_id
              AND offering_id = :offering_id
              AND assignment_id = :assignment_id

            ORDER BY
                attempt_number ASC,
                id ASC
            '
        );

    $statement->execute([
        'enrollment_id' =>
            $enrollmentId,

        'offering_id' =>
            $offeringId,

        'assignment_id' =>
            $assignmentId,
    ]);

    return $statement->fetchAll(
        PDO::FETCH_ASSOC
    );
}


/*
|--------------------------------------------------------------------------
| Datetime Helpers
|--------------------------------------------------------------------------
*/

function blackthorne_assignment_now(
    ?DateTimeImmutable $now = null
): DateTimeImmutable {
    return $now
        ?? new DateTimeImmutable('now');
}


function blackthorne_assignment_datetime(
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
| Submission Availability
|--------------------------------------------------------------------------
|
| Return keys:
|   available
|   can_save_draft
|   can_submit
|   can_create_new_attempt
|   is_late
|   reason
|   attempts_used
|   attempts_allowed
|   due_at
|   closes_at
|
*/

function blackthorne_assignment_submission_availability(
    PDO $pdo,
    array $context,
    ?DateTimeImmutable $now = null
): array {
    $now =
        blackthorne_assignment_now(
            $now
        );

    $result = [
        'available' => false,
        'can_save_draft' => false,
        'can_submit' => false,
        'can_create_new_attempt' => false,
        'is_late' => false,
        'reason' => 'unavailable',
        'attempts_used' => 0,
        'attempts_allowed' => 0,
        'due_at' => null,
        'closes_at' => null,
    ];

    $enrollmentId =
        (int) (
            $context['enrollment_id']
            ?? 0
        );

    $offeringId =
        (int) (
            $context['offering_id']
            ?? 0
        );

    $assignmentId =
        (int) (
            $context['assignment_id']
            ?? 0
        );

    if (
        $enrollmentId <= 0
        || $offeringId <= 0
        || $assignmentId <= 0
    ) {
        $result['reason'] =
            'invalid_context';

        return $result;
    }

    $enrollmentStatus =
        (string) (
            $context[
                'enrollment_status'
            ]
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

    if (
        (string) (
            $context[
                'offering_status'
            ]
            ?? ''
        ) === 'archived'
    ) {
        $result['reason'] =
            'offering_archived';

        return $result;
    }

    $courseAccessEndsAt =
        blackthorne_assignment_datetime(
            $context[
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
            $context['is_published']
            ?? 0
        ) !== 1
    ) {
        $result['reason'] =
            'assignment_unpublished';

        return $result;
    }

    if (
        (string) (
            $context['version_status']
            ?? ''
        ) !== 'published'
    ) {
        $result['reason'] =
            'assignment_version_unpublished';

        return $result;
    }

    $dueAt =
        blackthorne_assignment_datetime(
            $context['due_date']
            ?? null
        );

    $closesAt =
        blackthorne_assignment_datetime(
            $context[
                'submission_close_date'
            ]
            ?? null
        );

    $result['due_at'] =
        $dueAt?->format(
            'Y-m-d H:i:s'
        );

    $result['closes_at'] =
        $closesAt?->format(
            'Y-m-d H:i:s'
        );

    if (
        $closesAt !== null
        && $now > $closesAt
    ) {
        $result['reason'] =
            'submission_closed';

        return $result;
    }

    $isLate =
        $dueAt !== null
        && $now > $dueAt;

    $result['is_late'] =
        $isLate;

    if (
        $isLate
        && (int) (
            $context[
                'allow_late_submissions'
            ]
            ?? 0
        ) !== 1
    ) {
        $result['reason'] =
            'late_submissions_not_allowed';

        return $result;
    }

    $attempts =
        blackthorne_assignment_attempts(
            $pdo,
            $enrollmentId,
            $offeringId,
            $assignmentId
        );

    $attemptsUsed =
        count($attempts);

    $allowResubmissions =
        (int) (
            $context[
                'allow_resubmissions'
            ]
            ?? 0
        ) === 1;

    $maxSubmissions =
        max(
            1,
            (int) (
                $context[
                    'max_submissions'
                ]
                ?? 1
            )
        );

    $attemptsAllowed =
        $allowResubmissions
            ? $maxSubmissions
            : 1;

    $result['attempts_used'] =
        $attemptsUsed;

    $result['attempts_allowed'] =
        $attemptsAllowed;

    $latestAttempt =
        $attempts !== []
            ? $attempts[
                array_key_last(
                    $attempts
                )
            ]
            : null;

    if (
        is_array($latestAttempt)
        && (string) (
            $latestAttempt['status']
            ?? ''
        ) === 'draft'
    ) {
        $result['available'] = true;
        $result['can_save_draft'] = true;
        $result['can_submit'] = true;
        $result[
            'can_create_new_attempt'
        ] = false;
        $result['reason'] =
            $isLate
                ? 'draft_available_late'
                : 'draft_available';

        return $result;
    }

    if (
        $attemptsUsed >= $attemptsAllowed
    ) {
        $result['reason'] =
            'attempt_limit_reached';

        return $result;
    }

    if (
        is_array($latestAttempt)
        && in_array(
            (string) (
                $latestAttempt['status']
                ?? ''
            ),
            [
                'submitted',
                'graded',
            ],
            true
        )
        && !$allowResubmissions
    ) {
        $result['reason'] =
            'already_submitted';

        return $result;
    }

    $result['available'] = true;
    $result['can_save_draft'] = true;
    $result['can_submit'] = true;
    $result[
        'can_create_new_attempt'
    ] = true;
    $result['reason'] =
        $isLate
            ? 'new_attempt_available_late'
            : 'new_attempt_available';

    return $result;
}


/*
|--------------------------------------------------------------------------
| Current Editable Draft
|--------------------------------------------------------------------------
*/

function blackthorne_assignment_current_draft(
    PDO $pdo,
    int $enrollmentId,
    int $offeringId,
    int $assignmentId
): ?array {
    $statement =
        $pdo->prepare(
            '
            SELECT
                id,
                assignment_id,
                assignment_version_id,
                points_possible_snapshot,
                enrollment_id,
                offering_id,
                attempt_number,
                submission_text,
                file_path,
                status,
                created_at,
                updated_at

            FROM assignment_submissions

            WHERE enrollment_id = :enrollment_id
              AND offering_id = :offering_id
              AND assignment_id = :assignment_id
              AND status = "draft"

            ORDER BY
                attempt_number DESC,
                id DESC

            LIMIT 1
            '
        );

    $statement->execute([
        'enrollment_id' =>
            $enrollmentId,

        'offering_id' =>
            $offeringId,

        'assignment_id' =>
            $assignmentId,
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
| Create Draft Attempt
|--------------------------------------------------------------------------
*/

function blackthorne_assignment_create_draft(
    PDO $pdo,
    array $context,
    ?DateTimeImmutable $now = null
): array {
    $availability =
        blackthorne_assignment_submission_availability(
            $pdo,
            $context,
            $now
        );

    if (
        !$availability[
            'can_create_new_attempt'
        ]
    ) {
        return [
            'success' => false,
            'reason' =>
                $availability['reason'],
            'submission_id' => null,
        ];
    }

    $existingDraft =
        blackthorne_assignment_current_draft(
            $pdo,
            (int) $context[
                'enrollment_id'
            ],
            (int) $context[
                'offering_id'
            ],
            (int) $context[
                'assignment_id'
            ]
        );

    if ($existingDraft !== null) {
        return [
            'success' => true,
            'reason' => 'existing_draft',
            'submission_id' =>
                (int) $existingDraft['id'],
        ];
    }

    $attemptNumber =
        (int) $availability[
            'attempts_used'
        ] + 1;

    $statement =
        $pdo->prepare(
            '
            INSERT INTO assignment_submissions (
                assignment_id,
                assignment_version_id,
                points_possible_snapshot,
                enrollment_id,
                offering_id,
                attempt_number,
                status
            ) VALUES (
                :assignment_id,
                :assignment_version_id,
                :points_possible_snapshot,
                :enrollment_id,
                :offering_id,
                :attempt_number,
                "draft"
            )
            '
        );

    $statement->execute([
        'assignment_id' =>
            (int) $context[
                'assignment_id'
            ],

        'assignment_version_id' =>
            (int) $context[
                'assignment_version_id'
            ],

        'points_possible_snapshot' =>
            number_format(
                (float) (
                    $context[
                        'points_possible'
                    ]
                    ?? 0
                ),
                2,
                '.',
                ''
            ),

        'enrollment_id' =>
            (int) $context[
                'enrollment_id'
            ],

        'offering_id' =>
            (int) $context[
                'offering_id'
            ],

        'attempt_number' =>
            $attemptNumber,
    ]);

    return [
        'success' => true,
        'reason' => 'draft_created',
        'submission_id' =>
            (int) $pdo->lastInsertId(),
    ];
}


/*
|--------------------------------------------------------------------------
| Draft Ownership / Context Validation
|--------------------------------------------------------------------------
*/

function blackthorne_assignment_submission_record(
    PDO $pdo,
    int $submissionId,
    int $enrollmentId,
    int $offeringId,
    int $assignmentId
): ?array {
    if (
        $submissionId <= 0
        || $enrollmentId <= 0
        || $offeringId <= 0
        || $assignmentId <= 0
    ) {
        return null;
    }

    $statement =
        $pdo->prepare(
            '
            SELECT
                id,
                assignment_id,
                assignment_version_id,
                points_possible_snapshot,
                enrollment_id,
                offering_id,
                attempt_number,
                submission_text,
                file_path,
                status,
                submitted_at,
                locked_at,
                graded_at,
                created_at,
                updated_at

            FROM assignment_submissions

            WHERE id = :submission_id
              AND enrollment_id = :enrollment_id
              AND offering_id = :offering_id
              AND assignment_id = :assignment_id

            LIMIT 1
            '
        );

    $statement->execute([
        'submission_id' =>
            $submissionId,

        'enrollment_id' =>
            $enrollmentId,

        'offering_id' =>
            $offeringId,

        'assignment_id' =>
            $assignmentId,
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
| Validate Submission Content
|--------------------------------------------------------------------------
*/

function blackthorne_assignment_validate_content(
    array $context,
    ?string $submissionText,
    ?string $filePath,
    bool $forFinalSubmission = false
): array {
    $errors = [];

    $submissionText =
        trim(
            (string) $submissionText
        );

    $filePath =
        trim(
            (string) $filePath
        );

    $allowText =
        (int) (
            $context[
                'allow_text_submission'
            ]
            ?? 0
        ) === 1;

    $allowFile =
        (int) (
            $context[
                'allow_file_upload'
            ]
            ?? 0
        ) === 1;

    if (
        $submissionText !== ''
        && !$allowText
    ) {
        $errors[] =
            'Text submissions are not allowed for this assignment version.';
    }

    if (
        $filePath !== ''
        && !$allowFile
    ) {
        $errors[] =
            'File uploads are not allowed for this assignment version.';
    }

    if (
        $forFinalSubmission
        && $submissionText === ''
        && $filePath === ''
    ) {
        $errors[] =
            'Add a response or file before submitting the assignment.';
    }

    return $errors;
}


/*
|--------------------------------------------------------------------------
| Save Draft Content
|--------------------------------------------------------------------------
*/

function blackthorne_assignment_save_draft(
    PDO $pdo,
    array $context,
    int $submissionId,
    ?string $submissionText,
    ?string $filePath,
    ?DateTimeImmutable $now = null
): array {
    $availability =
        blackthorne_assignment_submission_availability(
            $pdo,
            $context,
            $now
        );

    if (
        !$availability[
            'can_save_draft'
        ]
    ) {
        return [
            'success' => false,
            'reason' =>
                $availability['reason'],
            'errors' => [],
        ];
    }

    $submission =
        blackthorne_assignment_submission_record(
            $pdo,
            $submissionId,
            (int) $context[
                'enrollment_id'
            ],
            (int) $context[
                'offering_id'
            ],
            (int) $context[
                'assignment_id'
            ]
        );

    if ($submission === null) {
        return [
            'success' => false,
            'reason' =>
                'submission_not_found',
            'errors' => [],
        ];
    }

    if (
        (string) (
            $submission['status']
            ?? ''
        ) !== 'draft'
    ) {
        return [
            'success' => false,
            'reason' =>
                'submission_locked',
            'errors' => [],
        ];
    }

    $errors =
        blackthorne_assignment_validate_content(
            $context,
            $submissionText,
            $filePath,
            false
        );

    if ($errors !== []) {
        return [
            'success' => false,
            'reason' =>
                'validation_failed',
            'errors' =>
                $errors,
        ];
    }

    $statement =
        $pdo->prepare(
            '
            UPDATE assignment_submissions

            SET
                submission_text = :submission_text,
                file_path = :file_path

            WHERE id = :submission_id
              AND status = "draft"
            '
        );

    $statement->execute([
        'submission_text' =>
            trim(
                (string) $submissionText
            ) !== ''
                ? trim(
                    (string) $submissionText
                )
                : null,

        'file_path' =>
            trim(
                (string) $filePath
            ) !== ''
                ? trim(
                    (string) $filePath
                )
                : null,

        'submission_id' =>
            $submissionId,
    ]);

    return [
        'success' => true,
        'reason' => 'draft_saved',
        'errors' => [],
    ];
}


/*
|--------------------------------------------------------------------------
| Submit Assignment
|--------------------------------------------------------------------------
*/

function blackthorne_assignment_submit(
    PDO $pdo,
    array $context,
    int $submissionId,
    ?string $submissionText,
    ?string $filePath,
    ?DateTimeImmutable $now = null
): array {
    $now =
        blackthorne_assignment_now(
            $now
        );

    $availability =
        blackthorne_assignment_submission_availability(
            $pdo,
            $context,
            $now
        );

    if (!$availability['can_submit']) {
        return [
            'success' => false,
            'reason' =>
                $availability['reason'],
            'errors' => [],
            'is_late' =>
                $availability['is_late'],
        ];
    }

    $submission =
        blackthorne_assignment_submission_record(
            $pdo,
            $submissionId,
            (int) $context[
                'enrollment_id'
            ],
            (int) $context[
                'offering_id'
            ],
            (int) $context[
                'assignment_id'
            ]
        );

    if ($submission === null) {
        return [
            'success' => false,
            'reason' =>
                'submission_not_found',
            'errors' => [],
            'is_late' =>
                $availability['is_late'],
        ];
    }

    if (
        (string) (
            $submission['status']
            ?? ''
        ) !== 'draft'
    ) {
        return [
            'success' => false,
            'reason' =>
                'submission_locked',
            'errors' => [],
            'is_late' =>
                $availability['is_late'],
        ];
    }

    $errors =
        blackthorne_assignment_validate_content(
            $context,
            $submissionText,
            $filePath,
            true
        );

    if ($errors !== []) {
        return [
            'success' => false,
            'reason' =>
                'validation_failed',
            'errors' =>
                $errors,
            'is_late' =>
                $availability['is_late'],
        ];
    }

    /*
     * Preserve the version/points snapshot taken when the attempt was created.
     * Do not replace it with the assignment's newest version here.
     */
    $statement =
        $pdo->prepare(
            '
            UPDATE assignment_submissions

            SET
                submission_text = :submission_text,
                file_path = :file_path,
                status = "submitted",
                submitted_at = :submitted_at,
                locked_at = :locked_at

            WHERE id = :submission_id
              AND status = "draft"
            '
        );

    $submittedAt =
        $now->format(
            'Y-m-d H:i:s'
        );

    $statement->execute([
        'submission_text' =>
            trim(
                (string) $submissionText
            ) !== ''
                ? trim(
                    (string) $submissionText
                )
                : null,

        'file_path' =>
            trim(
                (string) $filePath
            ) !== ''
                ? trim(
                    (string) $filePath
                )
                : null,

        'submitted_at' =>
            $submittedAt,

        'locked_at' =>
            $submittedAt,

        'submission_id' =>
            $submissionId,
    ]);

    if ($statement->rowCount() !== 1) {
        return [
            'success' => false,
            'reason' =>
                'submission_not_updated',
            'errors' => [],
            'is_late' =>
                $availability['is_late'],
        ];
    }

    return [
        'success' => true,
        'reason' => 'submitted',
        'errors' => [],
        'is_late' =>
            $availability['is_late'],
    ];
}


/*
|--------------------------------------------------------------------------
| Assignment Submission Summary
|--------------------------------------------------------------------------
*/

function blackthorne_assignment_submission_summary(
    PDO $pdo,
    int $enrollmentId,
    int $offeringId,
    int $assignmentId
): array {
    $attempts =
        blackthorne_assignment_attempts(
            $pdo,
            $enrollmentId,
            $offeringId,
            $assignmentId
        );

    $summary = [
        'attempt_count' => count($attempts),
        'draft_count' => 0,
        'submitted_count' => 0,
        'graded_count' => 0,
        'returned_count' => 0,
        'latest_attempt' => null,
        'highest_grade' => null,
    ];

    foreach ($attempts as $attempt) {
        $status =
            (string) (
                $attempt['status']
                ?? ''
            );

        if ($status === 'draft') {
            $summary['draft_count']++;
        } elseif ($status === 'submitted') {
            $summary['submitted_count']++;
        } elseif ($status === 'graded') {
            $summary['graded_count']++;
        } elseif ($status === 'returned') {
            $summary['returned_count']++;
        }

        if (
            $attempt['grade']
            ?? null
        ) {
            $grade =
                (float) $attempt['grade'];

            if (
                $summary['highest_grade']
                === null
                || $grade
                    > $summary[
                        'highest_grade'
                    ]
            ) {
                $summary['highest_grade'] =
                    $grade;
            }
        }

        $summary['latest_attempt'] =
            $attempt;
    }

    return $summary;
}
