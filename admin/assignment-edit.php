<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';


/*
|--------------------------------------------------------------------------
| Authentication / Permission
|--------------------------------------------------------------------------
*/

require_login();
require_active_account();

$isProtectedSuperAdmin =
    current_user_is_superuser();

$hasStaffIdentity =
    $isProtectedSuperAdmin
    || current_user_is_admin()
    || current_user_is_staff();

$canEditAssignments =
    $isProtectedSuperAdmin
    || user_can('assignments.edit');

$canPublishAssignments =
    $isProtectedSuperAdmin
    || user_can('assignments.publish');

$canManageAssignmentSettings =
    $isProtectedSuperAdmin
    || user_can('assignments.settings.manage');

if (
    !$hasStaffIdentity
    || !$canEditAssignments
) {
    http_response_code(403);

    $pageTitle =
        'Access Denied | Blackthorne Academy';

    $pageDescription =
        'You do not have permission to edit assignments.';

    $pageCanonical =
        url('admin/assignments.php');

    $robots =
        'noindex, nofollow';

    require INCLUDES_PATH . '/header.php';
    ?>

    <main id="main-content" class="forum-board-page">
        <section class="forum-board-error">
            <div class="section-inner">

                <p class="academy-overline">
                    Restricted Staff Area
                </p>

                <h1>
                    Access Denied
                </h1>

                <p>
                    Your account does not have permission to edit assignments.
                </p>

                <a
                    class="button button-secondary"
                    href="<?= e(url('admin/assignments.php')); ?>"
                >
                    Return to Assignments
                </a>

            </div>
        </section>
    </main>

    <?php
    require INCLUDES_PATH . '/footer.php';
    exit;
}

$currentUserId =
    (int) (
        current_user_id()
        ?? 0
    );


/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function assignment_edit_post_string(
    string $key
): string {
    $value =
        $_POST[$key]
        ?? '';

    return is_scalar($value)
        ? trim((string) $value)
        : '';
}


function assignment_edit_post_id(
    string $key
): int {
    $value =
        $_POST[$key]
        ?? '';

    if (
        !is_scalar($value)
        || !ctype_digit(
            (string) $value
        )
    ) {
        return 0;
    }

    return max(
        0,
        (int) $value
    );
}


function assignment_edit_post_bool(
    string $key
): int {
    return isset($_POST[$key])
        ? 1
        : 0;
}


function assignment_edit_post_uint(
    string $key,
    int $default = 0
): int {
    $value =
        assignment_edit_post_string(
            $key
        );

    if (
        $value === ''
        || !ctype_digit($value)
    ) {
        return $default;
    }

    return max(
        0,
        (int) $value
    );
}


function assignment_edit_post_decimal(
    string $key,
    float $default = 0.0
): float {
    $value =
        assignment_edit_post_string(
            $key
        );

    if (
        $value === ''
        || !is_numeric($value)
    ) {
        return $default;
    }

    return max(
        0,
        (float) $value
    );
}


function assignment_edit_normalize_datetime(
    string $value
): ?string {
    $value =
        trim($value);

    if ($value === '') {
        return null;
    }

    $date =
        DateTime::createFromFormat(
            'Y-m-d\TH:i',
            $value
        );

    if (!$date) {
        return null;
    }

    return $date->format(
        'Y-m-d H:i:s'
    );
}


function assignment_edit_form_datetime(
    ?string $value
): string {
    $value =
        trim(
            (string) $value
        );

    if ($value === '') {
        return '';
    }

    $timestamp =
        strtotime($value);

    if ($timestamp === false) {
        return '';
    }

    return date(
        'Y-m-d\TH:i',
        $timestamp
    );
}


function assignment_edit_format_datetime(
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


function assignment_edit_audit(
    PDO $pdo,
    int $actorUserId,
    int $assignmentId,
    string $actionType,
    string $description
): void {
    if ($actorUserId <= 0) {
        return;
    }

    try {
        $statement =
            $pdo->prepare(
                '
                INSERT INTO audit_log (
                    user_id,
                    action_type,
                    entity_type,
                    entity_id,
                    description,
                    ip_address,
                    user_agent
                ) VALUES (
                    :user_id,
                    :action_type,
                    :entity_type,
                    :entity_id,
                    :description,
                    :ip_address,
                    :user_agent
                )
                '
            );

        $statement->execute([
            'user_id' =>
                $actorUserId,

            'action_type' =>
                $actionType,

            'entity_type' =>
                'assignment',

            'entity_id' =>
                $assignmentId,

            'description' =>
                $description,

            'ip_address' =>
                $_SERVER['REMOTE_ADDR']
                ?? null,

            'user_agent' =>
                substr(
                    (string) (
                        $_SERVER[
                            'HTTP_USER_AGENT'
                        ]
                        ?? ''
                    ),
                    0,
                    500
                ),
        ]);
    } catch (Throwable $exception) {
        error_log(
            'Assignment edit audit error: '
            . $exception->getMessage()
        );
    }
}


/*
|--------------------------------------------------------------------------
| Identify Offering / Assignment
|--------------------------------------------------------------------------
*/

$offeringId =
    filter_input(
        INPUT_GET,
        'offering',
        FILTER_VALIDATE_INT
    );

$assignmentId =
    filter_input(
        INPUT_GET,
        'assignment',
        FILTER_VALIDATE_INT
    );

if (
    !is_int($offeringId)
    || $offeringId <= 0
) {
    $offeringId = 0;
}

if (
    !is_int($assignmentId)
    || $assignmentId <= 0
) {
    $assignmentId = 0;
}

if (
    $offeringId <= 0
    || $assignmentId <= 0
) {
    set_flash(
        'error',
        'Choose a valid assignment to edit.'
    );

    redirect(
        url('admin/assignments.php')
    );
}


/*
|--------------------------------------------------------------------------
| Load Assignment / Offering
|--------------------------------------------------------------------------
*/

$assignmentStatement =
    $pdo->prepare(
        '
        SELECT
            aos.id AS offering_setting_id,
            aos.assignment_id,
            aos.assignment_version_id,
            aos.offering_id,
            aos.course_id,
            aos.course_offering_lesson_id,
            aos.points_possible,
            aos.due_date,
            aos.submission_close_date,
            aos.allow_resubmissions,
            aos.max_submissions,
            aos.allow_late_submissions,
            aos.allow_extra_credit,
            aos.extra_credit_max_points,

            co.offering_scope,
            co.status AS offering_status,

            c.title AS course_title,

            sy.name AS school_year_name,

            a.internal_name,
            a.is_published,

            av.version_number AS active_version_number,
            av.title AS active_title,
            av.description AS active_description,
            av.instructions AS active_instructions,
            av.allow_text_submission,
            av.allow_file_upload,
            av.status AS active_version_status,
            av.created_at AS active_version_created_at,
            av.published_at AS active_version_published_at,

            lv.title AS linked_lesson_title,

            GROUP_CONCAT(
                DISTINCT yg.name
                ORDER BY yg.sort_order ASC, yg.year_number ASC
                SEPARATOR ", "
            ) AS year_group_names

        FROM assignment_offering_settings aos

        INNER JOIN assignments a
            ON a.id = aos.assignment_id

        INNER JOIN course_offerings co
            ON co.id = aos.offering_id

        INNER JOIN courses c
            ON c.id = aos.course_id

        LEFT JOIN school_years sy
            ON sy.id = co.school_year_id

        LEFT JOIN course_offering_year_groups coyg
            ON coyg.offering_id = co.id

        LEFT JOIN year_groups yg
            ON yg.id = coyg.year_group_id

        LEFT JOIN assignment_versions av
            ON av.id = aos.assignment_version_id

        LEFT JOIN course_offering_lessons col
            ON col.id = aos.course_offering_lesson_id

        LEFT JOIN lesson_versions lv
            ON lv.id = col.lesson_version_id

        WHERE aos.offering_id = :offering_id
          AND aos.assignment_id = :assignment_id

        GROUP BY
            aos.id,
            aos.assignment_id,
            aos.assignment_version_id,
            aos.offering_id,
            aos.course_id,
            aos.course_offering_lesson_id,
            aos.points_possible,
            aos.due_date,
            aos.submission_close_date,
            aos.allow_resubmissions,
            aos.max_submissions,
            aos.allow_late_submissions,
            aos.allow_extra_credit,
            aos.extra_credit_max_points,
            co.offering_scope,
            co.status,
            c.title,
            sy.name,
            a.internal_name,
            a.is_published,
            av.version_number,
            av.title,
            av.description,
            av.instructions,
            av.allow_text_submission,
            av.allow_file_upload,
            av.status,
            av.created_at,
            av.published_at,
            lv.title

        LIMIT 1
        '
    );

$assignmentStatement->execute([
    'offering_id' =>
        $offeringId,

    'assignment_id' =>
        $assignmentId,
]);

$assignment =
    $assignmentStatement->fetch(
        PDO::FETCH_ASSOC
    );

if (!is_array($assignment)) {
    set_flash(
        'error',
        'That assignment is not attached to the selected course offering.'
    );

    redirect(
        url(
            'admin/assignments.php?offering='
            . $offeringId
        )
    );
}


/*
|--------------------------------------------------------------------------
| Offering Lessons
|--------------------------------------------------------------------------
*/

$lessonStatement =
    $pdo->prepare(
        '
        SELECT
            col.id AS course_offering_lesson_id,
            col.sort_order,
            lv.title

        FROM course_offering_lessons col

        INNER JOIN lesson_versions lv
            ON lv.id = col.lesson_version_id

        WHERE col.offering_id = :offering_id

        ORDER BY
            col.sort_order ASC,
            col.id ASC
        '
    );

$lessonStatement->execute([
    'offering_id' =>
        $offeringId,
]);

$offeringLessons =
    $lessonStatement->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Version History
|--------------------------------------------------------------------------
*/

$versionsStatement =
    $pdo->prepare(
        '
        SELECT
            av.id,
            av.version_number,
            av.title,
            av.description,
            av.allow_text_submission,
            av.allow_file_upload,
            av.status,
            av.created_by,
            av.approved_by,
            av.published_at,
            av.locked_at,
            av.locked_by,
            av.created_at,
            creator.display_name AS creator_display_name,
            creator.username AS creator_username,
            approver.display_name AS approver_display_name,
            approver.username AS approver_username

        FROM assignment_versions av

        LEFT JOIN users creator
            ON creator.id = av.created_by

        LEFT JOIN users approver
            ON approver.id = av.approved_by

        WHERE av.assignment_id = :assignment_id

        ORDER BY
            av.version_number DESC,
            av.id DESC
        '
    );

$versionsStatement->execute([
    'assignment_id' =>
        $assignmentId,
]);

$versions =
    $versionsStatement->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Submission Count
|--------------------------------------------------------------------------
*/

$submissionCountStatement =
    $pdo->prepare(
        '
        SELECT COUNT(*)

        FROM assignment_submissions

        WHERE assignment_id = :assignment_id
          AND offering_id = :offering_id
        '
    );

$submissionCountStatement->execute([
    'assignment_id' =>
        $assignmentId,

    'offering_id' =>
        $offeringId,
]);

$submissionCount =
    (int) $submissionCountStatement->fetchColumn();


/*
|--------------------------------------------------------------------------
| Form State
|--------------------------------------------------------------------------
*/

$errors = [];

$contentForm = [
    'title' =>
        (string) (
            $assignment['active_title']
            ?? $assignment['internal_name']
            ?? ''
        ),

    'description' =>
        (string) (
            $assignment['active_description']
            ?? ''
        ),

    'instructions' =>
        (string) (
            $assignment['active_instructions']
            ?? ''
        ),

    'allow_text_submission' =>
        (string) (
            $assignment[
                'allow_text_submission'
            ]
            ?? 1
        ),

    'allow_file_upload' =>
        (string) (
            $assignment[
                'allow_file_upload'
            ]
            ?? 0
        ),

    'status' =>
        (string) (
            $assignment[
                'active_version_status'
            ]
            ?? 'draft'
        ),
];

$settingsForm = [
    'course_offering_lesson_id' =>
        $assignment[
            'course_offering_lesson_id'
        ] !== null
            ? (string) $assignment[
                'course_offering_lesson_id'
            ]
            : '',

    'points_possible' =>
        (string) (
            $assignment[
                'points_possible'
            ]
            ?? '100.00'
        ),

    'due_date' =>
        assignment_edit_form_datetime(
            $assignment[
                'due_date'
            ]
            ?? null
        ),

    'submission_close_date' =>
        assignment_edit_form_datetime(
            $assignment[
                'submission_close_date'
            ]
            ?? null
        ),

    'allow_resubmissions' =>
        (string) (
            $assignment[
                'allow_resubmissions'
            ]
            ?? 0
        ),

    'max_submissions' =>
        (string) (
            $assignment[
                'max_submissions'
            ]
            ?? 1
        ),

    'allow_late_submissions' =>
        (string) (
            $assignment[
                'allow_late_submissions'
            ]
            ?? 1
        ),

    'allow_extra_credit' =>
        (string) (
            $assignment[
                'allow_extra_credit'
            ]
            ?? 0
        ),

    'extra_credit_max_points' =>
        (string) (
            $assignment[
                'extra_credit_max_points'
            ]
            ?? '0.00'
        ),
];


/*
|--------------------------------------------------------------------------
| POST Actions
|--------------------------------------------------------------------------
*/

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
        assignment_edit_post_string(
            'action'
        );


    /*
    |--------------------------------------------------------------------------
    | Create New Version
    |--------------------------------------------------------------------------
    */

    if (
        $errors === []
        && $action === 'create_new_version'
    ) {
        $contentForm['title'] =
            assignment_edit_post_string(
                'title'
            );

        $contentForm['description'] =
            assignment_edit_post_string(
                'description'
            );

        $contentForm['instructions'] =
            assignment_edit_post_string(
                'instructions'
            );

        $contentForm[
            'allow_text_submission'
        ] =
            (string) assignment_edit_post_bool(
                'allow_text_submission'
            );

        $contentForm[
            'allow_file_upload'
        ] =
            (string) assignment_edit_post_bool(
                'allow_file_upload'
            );

        $contentForm['status'] =
            assignment_edit_post_string(
                'status'
            );

        $title =
            $contentForm['title'];

        $status =
            in_array(
                $contentForm['status'],
                [
                    'draft',
                    'review',
                    'published',
                ],
                true
            )
                ? $contentForm['status']
                : 'draft';

        $allowTextSubmission =
            assignment_edit_post_bool(
                'allow_text_submission'
            );

        $allowFileUpload =
            assignment_edit_post_bool(
                'allow_file_upload'
            );

        if ($title === '') {
            $errors[] =
                'Assignment Title is required.';
        }

        if (strlen($title) > 200) {
            $errors[] =
                'Assignment Title must be 200 characters or fewer.';
        }

        if (
            $allowTextSubmission !== 1
            && $allowFileUpload !== 1
        ) {
            $errors[] =
                'Allow at least one submission method.';
        }

        if (
            $status === 'published'
            && !$canPublishAssignments
        ) {
            $errors[] =
                'You do not have permission to publish assignment versions.';
        }

        if ($errors === []) {
            try {
                $pdo->beginTransaction();

                $nextVersionStatement =
                    $pdo->prepare(
                        '
                        SELECT
                            COALESCE(
                                MAX(version_number),
                                0
                            ) + 1

                        FROM assignment_versions

                        WHERE assignment_id = :assignment_id
                        '
                    );

                $nextVersionStatement->execute([
                    'assignment_id' =>
                        $assignmentId,
                ]);

                $nextVersionNumber =
                    (int) $nextVersionStatement->fetchColumn();

                if ($nextVersionNumber <= 0) {
                    $nextVersionNumber = 1;
                }

                $publishedAt =
                    $status === 'published'
                        ? date('Y-m-d H:i:s')
                        : null;

                $approvedBy =
                    $status === 'published'
                        ? (
                            $currentUserId > 0
                                ? $currentUserId
                                : null
                        )
                        : null;

                $insertVersion =
                    $pdo->prepare(
                        '
                        INSERT INTO assignment_versions (
                            assignment_id,
                            version_number,
                            title,
                            description,
                            instructions,
                            allow_text_submission,
                            allow_file_upload,
                            status,
                            created_by,
                            approved_by,
                            published_at
                        ) VALUES (
                            :assignment_id,
                            :version_number,
                            :title,
                            :description,
                            :instructions,
                            :allow_text_submission,
                            :allow_file_upload,
                            :status,
                            :created_by,
                            :approved_by,
                            :published_at
                        )
                        '
                    );

                $insertVersion->execute([
                    'assignment_id' =>
                        $assignmentId,

                    'version_number' =>
                        $nextVersionNumber,

                    'title' =>
                        $title,

                    'description' =>
                        $contentForm['description'] !== ''
                            ? $contentForm['description']
                            : null,

                    'instructions' =>
                        $contentForm['instructions'] !== ''
                            ? $contentForm['instructions']
                            : null,

                    'allow_text_submission' =>
                        $allowTextSubmission,

                    'allow_file_upload' =>
                        $allowFileUpload,

                    'status' =>
                        $status,

                    'created_by' =>
                        $currentUserId > 0
                            ? $currentUserId
                            : null,

                    'approved_by' =>
                        $approvedBy,

                    'published_at' =>
                        $publishedAt,
                ]);

                $newVersionId =
                    (int) $pdo->lastInsertId();

                /*
                 * Only this offering switches to the new version.
                 * Other offerings may continue using older versions.
                 */
                $updateOfferingSettings =
                    $pdo->prepare(
                        '
                        UPDATE assignment_offering_settings

                        SET assignment_version_id = :assignment_version_id

                        WHERE assignment_id = :assignment_id
                          AND offering_id = :offering_id
                        '
                    );

                $updateOfferingSettings->execute([
                    'assignment_version_id' =>
                        $newVersionId,

                    'assignment_id' =>
                        $assignmentId,

                    'offering_id' =>
                        $offeringId,
                ]);

                $updateAssignment =
                    $pdo->prepare(
                        '
                        UPDATE assignments

                        SET
                            internal_name = :internal_name,
                            is_published = :is_published

                        WHERE id = :assignment_id
                        '
                    );

                $updateAssignment->execute([
                    'internal_name' =>
                        $title,

                    'is_published' =>
                        $status === 'published'
                            ? 1
                            : (int) (
                                $assignment[
                                    'is_published'
                                ]
                                ?? 0
                            ),

                    'assignment_id' =>
                        $assignmentId,
                ]);

                assignment_edit_audit(
                    $pdo,
                    $currentUserId,
                    $assignmentId,
                    'assignment.version.create',
                    'Created assignment version '
                    . $nextVersionNumber
                    . ' for offering #'
                    . $offeringId
                );

                $pdo->commit();

                set_flash(
                    'success',
                    'A new assignment version was created and assigned to this offering.'
                );

                redirect(
                    url(
                        'admin/assignment-edit.php?offering='
                        . $offeringId
                        . '&assignment='
                        . $assignmentId
                    )
                );
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'Assignment version create error: '
                    . $exception->getMessage()
                );

                $errors[] =
                    'The new assignment version could not be created.';
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Update Offering Settings
    |--------------------------------------------------------------------------
    */

    if (
        $errors === []
        && $action === 'update_offering_settings'
    ) {
        if (!$canManageAssignmentSettings) {
            $errors[] =
                'You do not have permission to manage assignment offering settings.';
        }

        $settingsForm[
            'course_offering_lesson_id'
        ] =
            assignment_edit_post_string(
                'course_offering_lesson_id'
            );

        $settingsForm[
            'points_possible'
        ] =
            assignment_edit_post_string(
                'points_possible'
            );

        $settingsForm['due_date'] =
            assignment_edit_post_string(
                'due_date'
            );

        $settingsForm[
            'submission_close_date'
        ] =
            assignment_edit_post_string(
                'submission_close_date'
            );

        $settingsForm[
            'allow_resubmissions'
        ] =
            (string) assignment_edit_post_bool(
                'allow_resubmissions'
            );

        $settingsForm[
            'max_submissions'
        ] =
            assignment_edit_post_string(
                'max_submissions'
            );

        $settingsForm[
            'allow_late_submissions'
        ] =
            (string) assignment_edit_post_bool(
                'allow_late_submissions'
            );

        $settingsForm[
            'allow_extra_credit'
        ] =
            (string) assignment_edit_post_bool(
                'allow_extra_credit'
            );

        $settingsForm[
            'extra_credit_max_points'
        ] =
            assignment_edit_post_string(
                'extra_credit_max_points'
            );

        $courseOfferingLessonId =
            assignment_edit_post_id(
                'course_offering_lesson_id'
            );

        $pointsPossible =
            assignment_edit_post_decimal(
                'points_possible',
                100.0
            );

        $dueDate =
            assignment_edit_normalize_datetime(
                $settingsForm['due_date']
            );

        $submissionCloseDate =
            assignment_edit_normalize_datetime(
                $settingsForm[
                    'submission_close_date'
                ]
            );

        $allowResubmissions =
            assignment_edit_post_bool(
                'allow_resubmissions'
            );

        $maxSubmissions =
            assignment_edit_post_uint(
                'max_submissions',
                1
            );

        $allowLateSubmissions =
            assignment_edit_post_bool(
                'allow_late_submissions'
            );

        $allowExtraCredit =
            assignment_edit_post_bool(
                'allow_extra_credit'
            );

        $extraCreditMaxPoints =
            assignment_edit_post_decimal(
                'extra_credit_max_points',
                0.0
            );

        if (
            $courseOfferingLessonId > 0
        ) {
            $validLesson = false;

            foreach ($offeringLessons as $lessonRow) {
                if (
                    (int) (
                        $lessonRow[
                            'course_offering_lesson_id'
                        ]
                        ?? 0
                    ) === $courseOfferingLessonId
                ) {
                    $validLesson = true;
                    break;
                }
            }

            if (!$validLesson) {
                $errors[] =
                    'The selected lesson does not belong to this course offering.';
            }
        }

        if ($pointsPossible <= 0) {
            $errors[] =
                'Points Possible must be greater than zero.';
        }

        if (
            $settingsForm['due_date'] !== ''
            && $dueDate === null
        ) {
            $errors[] =
                'Due Date is invalid.';
        }

        if (
            $settingsForm[
                'submission_close_date'
            ] !== ''
            && $submissionCloseDate === null
        ) {
            $errors[] =
                'Submission Close Date is invalid.';
        }

        if (
            $dueDate !== null
            && $submissionCloseDate !== null
            && $submissionCloseDate < $dueDate
        ) {
            $errors[] =
                'Submission Close Date cannot be earlier than the Due Date.';
        }

        if (
            $allowResubmissions === 1
            && $maxSubmissions < 2
        ) {
            $errors[] =
                'Allowing resubmissions requires at least 2 maximum submissions.';
        }

        if ($maxSubmissions < 1) {
            $errors[] =
                'Maximum Submissions must be at least 1.';
        }

        if (
            $allowExtraCredit !== 1
        ) {
            $extraCreditMaxPoints = 0.0;
        }

        if (
            $allowExtraCredit === 1
            && $extraCreditMaxPoints <= 0
        ) {
            $errors[] =
                'Enter the maximum extra-credit points when extra credit is enabled.';
        }

        if ($errors === []) {
            try {
                $updateSettings =
                    $pdo->prepare(
                        '
                        UPDATE assignment_offering_settings

                        SET
                            course_offering_lesson_id = :course_offering_lesson_id,
                            points_possible = :points_possible,
                            due_date = :due_date,
                            submission_close_date = :submission_close_date,
                            allow_resubmissions = :allow_resubmissions,
                            max_submissions = :max_submissions,
                            allow_late_submissions = :allow_late_submissions,
                            allow_extra_credit = :allow_extra_credit,
                            extra_credit_max_points = :extra_credit_max_points

                        WHERE assignment_id = :assignment_id
                          AND offering_id = :offering_id
                        '
                    );

                $updateSettings->execute([
                    'course_offering_lesson_id' =>
                        $courseOfferingLessonId > 0
                            ? $courseOfferingLessonId
                            : null,

                    'points_possible' =>
                        number_format(
                            $pointsPossible,
                            2,
                            '.',
                            ''
                        ),

                    'due_date' =>
                        $dueDate,

                    'submission_close_date' =>
                        $submissionCloseDate,

                    'allow_resubmissions' =>
                        $allowResubmissions,

                    'max_submissions' =>
                        $allowResubmissions === 1
                            ? $maxSubmissions
                            : 1,

                    'allow_late_submissions' =>
                        $allowLateSubmissions,

                    'allow_extra_credit' =>
                        $allowExtraCredit,

                    'extra_credit_max_points' =>
                        number_format(
                            $extraCreditMaxPoints,
                            2,
                            '.',
                            ''
                        ),

                    'assignment_id' =>
                        $assignmentId,

                    'offering_id' =>
                        $offeringId,
                ]);

                assignment_edit_audit(
                    $pdo,
                    $currentUserId,
                    $assignmentId,
                    'assignment.offering_settings.update',
                    'Updated assignment offering settings for offering #'
                    . $offeringId
                );

                set_flash(
                    'success',
                    'Assignment offering settings updated successfully.'
                );

                redirect(
                    url(
                        'admin/assignment-edit.php?offering='
                        . $offeringId
                        . '&assignment='
                        . $assignmentId
                    )
                );
            } catch (Throwable $exception) {
                error_log(
                    'Assignment settings update error: '
                    . $exception->getMessage()
                );

                $errors[] =
                    'The assignment offering settings could not be updated.';
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| Sidebar
|--------------------------------------------------------------------------
*/

$courseSidebarActive =
    'assignments';

require INCLUDES_PATH . '/staff-course-sidebar.php';


/*
|--------------------------------------------------------------------------
| SEO / Header
|--------------------------------------------------------------------------
*/

$pageTitle =
    'Edit Assignment | Blackthorne Academy';

$pageDescription =
    'Edit assignment versions and offering settings.';

$pageCanonical =
    url(
        'admin/assignment-edit.php?offering='
        . $offeringId
        . '&assignment='
        . $assignmentId
    );

$robots =
    'noindex, nofollow';

require INCLUDES_PATH . '/header.php';


/*
|--------------------------------------------------------------------------
| Hero
|--------------------------------------------------------------------------
*/

$staffHeroPngReference =
    'assets/images/staff_dashboard_hero_bg.png';

$staffHeroWebpReference =
    'assets/images/staff_dashboard_hero_bg.webp';

$projectRoot =
    dirname(__DIR__);

$staffHeroReference =
    is_file(
        $projectRoot
        . '/'
        . $staffHeroWebpReference
    )
        ? $staffHeroWebpReference
        : $staffHeroPngReference;

$staffHeroUrl =
    is_file(
        $projectRoot
        . '/'
        . $staffHeroReference
    )
        ? url($staffHeroReference)
        : '';

?>

<main
    id="main-content"
    class="dashboard-page staff-dashboard-page dashboard-workspace-page courses-dashboard-page assignment-edit-page"
>

    <section
        class="dashboard-hero staff-dashboard-hero"
        aria-labelledby="assignment-edit-heading"
        <?php if ($staffHeroUrl !== ''): ?>
            style="--staff-dashboard-hero-image: url('<?= e($staffHeroUrl); ?>');"
        <?php endif; ?>
    >
        <div class="section-inner">
            <div class="dashboard-hero-inner">

                <p class="academy-overline">
                    Assignment Management
                </p>

                <h1 id="assignment-edit-heading">
                    Edit Assignment
                </h1>

                <p class="dashboard-hero-copy">
                    Create new assignment versions without overwriting
                    historical content, and manage offering-specific rules.
                </p>

            </div>
        </div>
    </section>


    <section class="dashboard-workspace-section">
        <div class="section-inner dashboard-workspace-layout">

            <?php
            require
                INCLUDES_PATH
                . '/dashboard-sidebar.php';
            ?>

            <div class="dashboard-workspace-main">

                <header class="dashboard-workspace-heading">
                    <div>
                        <p class="academy-overline">
                            <?= e(
                                (string) (
                                    $assignment[
                                        'course_title'
                                    ]
                                    ?? 'Course'
                                )
                            ); ?>
                        </p>

                        <h2>
                            <?= e(
                                (string) (
                                    $assignment[
                                        'active_title'
                                    ]
                                    ?? $assignment[
                                        'internal_name'
                                    ]
                                    ?? 'Assignment'
                                )
                            ); ?>
                        </h2>

                        <p>
                            This offering currently uses Version
                            <?= number_format(
                                (int) (
                                    $assignment[
                                        'active_version_number'
                                    ]
                                    ?? 1
                                )
                            ); ?>.
                        </p>
                    </div>

                    <div class="dashboard-workspace-heading-actions">
                        <a
                            class="button button-secondary"
                            href="<?= e(
                                url(
                                    'admin/assignments.php?offering='
                                    . $offeringId
                                )
                            ); ?>"
                        >
                            Back to Assignments
                        </a>
                    </div>
                </header>


                <?php if ($errors !== []): ?>

                    <div
                        class="form-message form-message-error"
                        role="alert"
                    >
                        <strong>
                            The assignment could not be updated.
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


                <section class="dashboard-workspace-panel">

                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">
                                Current Offering
                            </p>

                            <h3>
                                Assignment Context
                            </h3>
                        </div>
                    </div>

                    <div class="dashboard-panel-body">

                        <div class="dashboard-placeholder-list">

                            <span>
                                <strong>Offering:</strong>
                                #<?= number_format($offeringId); ?>
                            </span>

                            <span>
                                <strong>Scope:</strong>
                                <?= e(
                                    ucfirst(
                                        str_replace(
                                            '_',
                                            ' ',
                                            (string) (
                                                $assignment[
                                                    'offering_scope'
                                                ]
                                                ?? 'school_year'
                                            )
                                        )
                                    )
                                ); ?>
                            </span>

                            <?php if (
                                !empty(
                                    $assignment[
                                        'school_year_name'
                                    ]
                                )
                            ): ?>
                                <span>
                                    <strong>School Year:</strong>
                                    <?= e(
                                        (string) $assignment[
                                            'school_year_name'
                                        ]
                                    ); ?>
                                </span>
                            <?php endif; ?>

                            <?php if (
                                !empty(
                                    $assignment[
                                        'year_group_names'
                                    ]
                                )
                            ): ?>
                                <span>
                                    <strong>Grade Level:</strong>
                                    <?= e(
                                        (string) $assignment[
                                            'year_group_names'
                                        ]
                                    ); ?>
                                </span>
                            <?php endif; ?>

                            <span>
                                <strong>Points:</strong>
                                <?= e(
                                    number_format(
                                        (float) (
                                            $assignment[
                                                'points_possible'
                                            ]
                                            ?? 0
                                        ),
                                        2
                                    )
                                ); ?>
                            </span>

                            <span>
                                <strong>Submissions:</strong>
                                <?= number_format($submissionCount); ?>
                            </span>

                        </div>

                    </div>

                </section>


                <section class="forum-admin-panel">

                    <header class="forum-admin-titlebar">
                        <p class="forum-admin-step">
                            Content Revision
                        </p>

                        <h2>
                            Create New Version
                        </h2>
                    </header>

                    <form
                        action="<?= e(
                            url(
                                'admin/assignment-edit.php?offering='
                                . $offeringId
                                . '&assignment='
                                . $assignmentId
                            )
                        ); ?>"
                        method="post"
                        class="forum-admin-form"
                    >
                        <?= csrf_field(); ?>

                        <input
                            type="hidden"
                            name="action"
                            value="create_new_version"
                        >


                        <div class="form-group">
                            <label for="assignment-title">
                                Assignment Title
                            </label>

                            <input
                                class="form-control"
                                type="text"
                                id="assignment-title"
                                name="title"
                                maxlength="200"
                                value="<?= e($contentForm['title']); ?>"
                                required
                            >
                        </div>


                        <div class="form-group">
                            <label for="assignment-description">
                                Short Description
                            </label>

                            <textarea
                                class="form-control"
                                id="assignment-description"
                                name="description"
                                rows="4"
                            ><?= e($contentForm['description']); ?></textarea>
                        </div>


                        <div class="form-group">
                            <label for="assignment-instructions">
                                Assignment Instructions
                            </label>

                            <textarea
                                class="form-control"
                                id="assignment-instructions"
                                name="instructions"
                                rows="14"
                            ><?= e($contentForm['instructions']); ?></textarea>

                            <p class="form-help">
                                Saving this section creates a new version.
                                It does not overwrite the current historical version.
                            </p>
                        </div>


                        <fieldset class="forum-admin-fieldset">

                            <legend>
                                Submission Methods
                            </legend>

                            <label class="forum-admin-choice">
                                <input
                                    type="checkbox"
                                    name="allow_text_submission"
                                    value="1"
                                    <?= $contentForm['allow_text_submission'] === '1'
                                        ? 'checked'
                                        : ''; ?>
                                >

                                <span>
                                    Allow text response
                                </span>
                            </label>

                            <label class="forum-admin-choice">
                                <input
                                    type="checkbox"
                                    name="allow_file_upload"
                                    value="1"
                                    <?= $contentForm['allow_file_upload'] === '1'
                                        ? 'checked'
                                        : ''; ?>
                                >

                                <span>
                                    Allow file upload
                                </span>
                            </label>

                        </fieldset>


                        <div class="form-group">
                            <label for="assignment-status">
                                New Version Status
                            </label>

                            <select
                                class="form-control"
                                id="assignment-status"
                                name="status"
                            >
                                <option
                                    value="draft"
                                    <?= $contentForm['status'] === 'draft'
                                        ? 'selected'
                                        : ''; ?>
                                >
                                    Draft
                                </option>

                                <option
                                    value="review"
                                    <?= $contentForm['status'] === 'review'
                                        ? 'selected'
                                        : ''; ?>
                                >
                                    Review
                                </option>

                                <?php if ($canPublishAssignments): ?>
                                    <option
                                        value="published"
                                        <?= $contentForm['status'] === 'published'
                                            ? 'selected'
                                            : ''; ?>
                                    >
                                        Published
                                    </option>
                                <?php endif; ?>
                            </select>
                        </div>


                        <div class="forum-admin-actions">
                            <button
                                type="submit"
                                class="button button-primary"
                            >
                                Create New Version
                            </button>
                        </div>

                    </form>

                </section>


                <?php if ($canManageAssignmentSettings): ?>

                    <section class="forum-admin-panel">

                        <header class="forum-admin-titlebar">
                            <p class="forum-admin-step">
                                Offering Settings
                            </p>

                            <h2>
                                Points &amp; Submission Rules
                            </h2>
                        </header>

                        <form
                            action="<?= e(
                                url(
                                    'admin/assignment-edit.php?offering='
                                    . $offeringId
                                    . '&assignment='
                                    . $assignmentId
                                )
                            ); ?>"
                            method="post"
                            class="forum-admin-form"
                        >
                            <?= csrf_field(); ?>

                            <input
                                type="hidden"
                                name="action"
                                value="update_offering_settings"
                            >


                            <div class="form-group">
                                <label for="course-offering-lesson-id">
                                    Related Lesson
                                </label>

                                <select
                                    class="form-control"
                                    id="course-offering-lesson-id"
                                    name="course_offering_lesson_id"
                                >
                                    <option value="">
                                        None / Course-level assignment
                                    </option>

                                    <?php foreach ($offeringLessons as $lessonRow): ?>
                                        <option
                                            value="<?= (int) $lessonRow['course_offering_lesson_id']; ?>"
                                            <?= (string) $lessonRow['course_offering_lesson_id']
                                                === $settingsForm['course_offering_lesson_id']
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= number_format(
                                                (int) (
                                                    $lessonRow[
                                                        'sort_order'
                                                    ]
                                                    ?? 0
                                                )
                                            ); ?>.
                                            <?= e(
                                                (string) (
                                                    $lessonRow[
                                                        'title'
                                                    ]
                                                    ?? 'Lesson'
                                                )
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>


                            <div class="form-group">
                                <label for="points-possible">
                                    Points Possible
                                </label>

                                <input
                                    class="form-control"
                                    type="number"
                                    id="points-possible"
                                    name="points_possible"
                                    min="0.01"
                                    step="0.01"
                                    value="<?= e($settingsForm['points_possible']); ?>"
                                    required
                                >
                            </div>


                            <div class="forum-admin-form-grid">

                                <div class="form-group">
                                    <label for="due-date">
                                        Due Date
                                    </label>

                                    <input
                                        class="form-control"
                                        type="datetime-local"
                                        id="due-date"
                                        name="due_date"
                                        value="<?= e($settingsForm['due_date']); ?>"
                                    >
                                </div>


                                <div class="form-group">
                                    <label for="submission-close-date">
                                        Submission Close Date
                                    </label>

                                    <input
                                        class="form-control"
                                        type="datetime-local"
                                        id="submission-close-date"
                                        name="submission_close_date"
                                        value="<?= e(
                                            $settingsForm[
                                                'submission_close_date'
                                            ]
                                        ); ?>"
                                    >
                                </div>

                            </div>


                            <fieldset class="forum-admin-fieldset">

                                <legend>
                                    Submission Rules
                                </legend>

                                <label class="forum-admin-choice">
                                    <input
                                        type="checkbox"
                                        name="allow_resubmissions"
                                        value="1"
                                        <?= $settingsForm['allow_resubmissions'] === '1'
                                            ? 'checked'
                                            : ''; ?>
                                    >

                                    <span>
                                        Allow resubmissions
                                    </span>
                                </label>

                                <div class="form-group">
                                    <label for="max-submissions">
                                        Maximum Submissions
                                    </label>

                                    <input
                                        class="form-control"
                                        type="number"
                                        id="max-submissions"
                                        name="max_submissions"
                                        min="1"
                                        step="1"
                                        value="<?= e($settingsForm['max_submissions']); ?>"
                                    >
                                </div>

                                <label class="forum-admin-choice">
                                    <input
                                        type="checkbox"
                                        name="allow_late_submissions"
                                        value="1"
                                        <?= $settingsForm['allow_late_submissions'] === '1'
                                            ? 'checked'
                                            : ''; ?>
                                    >

                                    <span>
                                        Allow late submissions
                                    </span>
                                </label>

                            </fieldset>


                            <fieldset class="forum-admin-fieldset">

                                <legend>
                                    Extra Credit
                                </legend>

                                <label class="forum-admin-choice">
                                    <input
                                        type="checkbox"
                                        name="allow_extra_credit"
                                        value="1"
                                        <?= $settingsForm['allow_extra_credit'] === '1'
                                            ? 'checked'
                                            : ''; ?>
                                    >

                                    <span>
                                        Allow extra credit
                                    </span>
                                </label>

                                <div class="form-group">
                                    <label for="extra-credit-max-points">
                                        Maximum Extra-Credit Points
                                    </label>

                                    <input
                                        class="form-control"
                                        type="number"
                                        id="extra-credit-max-points"
                                        name="extra_credit_max_points"
                                        min="0"
                                        step="0.01"
                                        value="<?= e(
                                            $settingsForm[
                                                'extra_credit_max_points'
                                            ]
                                        ); ?>"
                                    >
                                </div>

                            </fieldset>


                            <div class="forum-admin-actions">
                                <button
                                    type="submit"
                                    class="button button-primary"
                                >
                                    Save Offering Settings
                                </button>
                            </div>

                        </form>

                    </section>

                <?php endif; ?>


                <?php if (
                    $canManageAssignmentSettings
                    || $canEditAssignments
                ): ?>

                    <section class="dashboard-workspace-panel">

                        <div class="dashboard-panel-titlebar">
                            <div>
                                <p class="academy-overline">
                                    Optional Work
                                </p>

                                <h3>
                                    Extra Credit
                                </h3>
                            </div>
                        </div>

                        <div class="dashboard-panel-body">

                            <p>
                                Extra-credit tasks are stored with the exact
                                assignment version used by this offering.
                            </p>

                            <div class="forum-admin-actions">
                                <a
                                    class="button button-secondary"
                                    href="<?= e(
                                        url(
                                            'admin/assignment-extra-credit.php?offering='
                                            . $offeringId
                                            . '&assignment='
                                            . $assignmentId
                                        )
                                    ); ?>"
                                >
                                    Manage Extra Credit
                                </a>
                            </div>

                        </div>

                    </section>

                <?php endif; ?>


                <section class="dashboard-workspace-panel">

                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">
                                Version History
                            </p>

                            <h3>
                                Saved Assignment Versions
                            </h3>
                        </div>
                    </div>

                    <div class="dashboard-panel-body">

                        <?php if ($versions === []): ?>

                            <p>
                                No version history is available.
                            </p>

                        <?php else: ?>

                            <div class="dashboard-placeholder-list">

                                <?php foreach ($versions as $versionRow): ?>
                                    <?php
                                    $creatorName =
                                        trim(
                                            (string) (
                                                $versionRow[
                                                    'creator_display_name'
                                                ]
                                                ?? $versionRow[
                                                    'creator_username'
                                                ]
                                                ?? ''
                                            )
                                        );

                                    $approverName =
                                        trim(
                                            (string) (
                                                $versionRow[
                                                    'approver_display_name'
                                                ]
                                                ?? $versionRow[
                                                    'approver_username'
                                                ]
                                                ?? ''
                                            )
                                        );

                                    $isCurrentVersion =
                                        (int) (
                                            $versionRow['id']
                                            ?? 0
                                        )
                                        ===
                                        (int) (
                                            $assignment[
                                                'assignment_version_id'
                                            ]
                                            ?? 0
                                        );
                                    ?>

                                    <span>

                                        <strong>
                                            Version
                                            <?= number_format(
                                                (int) (
                                                    $versionRow[
                                                        'version_number'
                                                    ]
                                                    ?? 1
                                                )
                                            ); ?>
                                            —
                                            <?= e(
                                                (string) (
                                                    $versionRow[
                                                        'title'
                                                    ]
                                                    ?? 'Assignment'
                                                )
                                            ); ?>
                                        </strong>

                                        <?php if ($isCurrentVersion): ?>
                                            · Current for this offering
                                        <?php endif; ?>

                                        ·
                                        <?= e(
                                            ucfirst(
                                                (string) (
                                                    $versionRow[
                                                        'status'
                                                    ]
                                                    ?? 'draft'
                                                )
                                            )
                                        ); ?>

                                        ·
                                        <?= (int) (
                                            $versionRow[
                                                'allow_text_submission'
                                            ]
                                            ?? 0
                                        ) === 1
                                            ? 'Text'
                                            : 'No text'; ?>

                                        ·
                                        <?= (int) (
                                            $versionRow[
                                                'allow_file_upload'
                                            ]
                                            ?? 0
                                        ) === 1
                                            ? 'File upload'
                                            : 'No file upload'; ?>

                                        · Created
                                        <?= e(
                                            assignment_edit_format_datetime(
                                                $versionRow[
                                                    'created_at'
                                                ]
                                                ?? null
                                            )
                                        ); ?>

                                        <?php if ($creatorName !== ''): ?>
                                            by <?= e($creatorName); ?>
                                        <?php endif; ?>

                                        <?php if (
                                            !empty(
                                                $versionRow[
                                                    'published_at'
                                                ]
                                            )
                                        ): ?>
                                            · Published
                                            <?= e(
                                                assignment_edit_format_datetime(
                                                    $versionRow[
                                                        'published_at'
                                                    ]
                                                )
                                            ); ?>
                                        <?php endif; ?>

                                        <?php if ($approverName !== ''): ?>
                                            · Approved by
                                            <?= e($approverName); ?>
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

<?php

require INCLUDES_PATH . '/footer.php';
