<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
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

$assignmentId =
    filter_input(
        INPUT_GET,
        'assignment',
        FILTER_VALIDATE_INT
    );

$offeringId =
    is_int($offeringId) && $offeringId > 0
        ? $offeringId
        : 0;

$assignmentId =
    is_int($assignmentId) && $assignmentId > 0
        ? $assignmentId
        : 0;

if (
    $userId <= 0
    || $offeringId <= 0
    || $assignmentId <= 0
) {
    http_response_code(404);

    $pageTitle =
        'Assignment Not Found | Blackthorne Academy';

    $pageDescription =
        'The requested assignment could not be found.';

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
                    <h1>Assignment Not Found</h1>
                    <p>
                        The requested assignment could not be found.
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
| Helpers
|--------------------------------------------------------------------------
*/

function student_assignment_post_string(
    string $key
): string {
    $value =
        $_POST[$key]
        ?? '';

    return is_scalar($value)
        ? trim((string) $value)
        : '';
}


function student_assignment_format_datetime(
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


function student_assignment_upload_file(
    array $file,
    int $userId,
    int $submissionId,
    string $prefix = 'assignment'
): array {
    $error =
        (int) (
            $file['error']
            ?? UPLOAD_ERR_NO_FILE
        );

    if ($error === UPLOAD_ERR_NO_FILE) {
        return [
            'success' => true,
            'path' => null,
            'uploaded' => false,
            'error' => null,
        ];
    }

    if ($error !== UPLOAD_ERR_OK) {
        return [
            'success' => false,
            'path' => null,
            'uploaded' => false,
            'error' => 'The uploaded file could not be received.',
        ];
    }

    $temporaryPath =
        (string) (
            $file['tmp_name']
            ?? ''
        );

    if (
        $temporaryPath === ''
        || !is_uploaded_file(
            $temporaryPath
        )
    ) {
        return [
            'success' => false,
            'path' => null,
            'uploaded' => false,
            'error' => 'The uploaded file is invalid.',
        ];
    }

    $size =
        (int) (
            $file['size']
            ?? 0
        );

    $maxBytes =
        20 * 1024 * 1024;

    if (
        $size <= 0
        || $size > $maxBytes
    ) {
        return [
            'success' => false,
            'path' => null,
            'uploaded' => false,
            'error' => 'Files must be 20 MB or smaller.',
        ];
    }

    $allowedMimeTypes = [
        'application/pdf' =>
            'pdf',

        'application/msword' =>
            'doc',

        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' =>
            'docx',

        'application/vnd.oasis.opendocument.text' =>
            'odt',

        'text/plain' =>
            'txt',

        'application/rtf' =>
            'rtf',

        'text/rtf' =>
            'rtf',

        'image/jpeg' =>
            'jpg',

        'image/png' =>
            'png',

        'image/webp' =>
            'webp',
    ];

    $finfo =
        new finfo(
            FILEINFO_MIME_TYPE
        );

    $mimeType =
        (string) $finfo->file(
            $temporaryPath
        );

    if (
        !isset(
            $allowedMimeTypes[
                $mimeType
            ]
        )
    ) {
        return [
            'success' => false,
            'path' => null,
            'uploaded' => false,
            'error' =>
                'That file type is not allowed. Use PDF, DOC, DOCX, ODT, RTF, TXT, JPG, PNG, or WebP.',
        ];
    }

    $extension =
        $allowedMimeTypes[
            $mimeType
        ];

    $safePrefix =
        preg_replace(
            '/[^a-z0-9_-]+/i',
            '-',
            $prefix
        );

    $safePrefix =
        trim(
            (string) $safePrefix,
            '-'
        );

    if ($safePrefix === '') {
        $safePrefix =
            'assignment';
    }

    $relativeDirectory =
        'uploads/assignments/'
        . $userId
        . '/'
        . $submissionId;

    $absoluteDirectory =
        __DIR__
        . '/'
        . $relativeDirectory;

    if (
        !is_dir($absoluteDirectory)
        && !mkdir(
            $absoluteDirectory,
            0755,
            true
        )
        && !is_dir($absoluteDirectory)
    ) {
        return [
            'success' => false,
            'path' => null,
            'uploaded' => false,
            'error' => 'The assignment upload directory could not be created.',
        ];
    }

    try {
        $randomName =
            bin2hex(
                random_bytes(12)
            );
    } catch (Throwable $exception) {
        $randomName =
            str_replace(
                '.',
                '',
                uniqid(
                    '',
                    true
                )
            );
    }

    $filename =
        $safePrefix
        . '-'
        . $randomName
        . '.'
        . $extension;

    $absolutePath =
        $absoluteDirectory
        . '/'
        . $filename;

    if (
        !move_uploaded_file(
            $temporaryPath,
            $absolutePath
        )
    ) {
        return [
            'success' => false,
            'path' => null,
            'uploaded' => false,
            'error' => 'The uploaded file could not be saved.',
        ];
    }

    return [
        'success' => true,
        'path' =>
            $relativeDirectory
            . '/'
            . $filename,
        'uploaded' => true,
        'error' => null,
    ];
}


function student_assignment_safe_unlink(
    ?string $relativePath
): void {
    $relativePath =
        trim(
            (string) $relativePath
        );

    if (
        $relativePath === ''
        || !str_starts_with(
            $relativePath,
            'uploads/assignments/'
        )
    ) {
        return;
    }

    $absolutePath =
        __DIR__
        . '/'
        . ltrim(
            $relativePath,
            '/'
        );

    if (
        is_file($absolutePath)
    ) {
        @unlink($absolutePath);
    }
}


/*
|--------------------------------------------------------------------------
| Assignment / Enrollment Context
|--------------------------------------------------------------------------
*/

$context =
    blackthorne_assignment_context(
        $pdo,
        $userId,
        $offeringId,
        $assignmentId
    );

if ($context === null) {
    http_response_code(403);

    $pageTitle =
        'Assignment Unavailable | Blackthorne Academy';

    $pageDescription =
        'This assignment is not available for your enrollment.';

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
                    <h1>Assignment Unavailable</h1>
                    <p>
                        This assignment is not available for your current enrollment.
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
| Course / Related Lesson Metadata
|--------------------------------------------------------------------------
*/

$metadataStatement =
    $pdo->prepare(
        '
        SELECT
            c.title AS course_title,
            lv.title AS lesson_title

        FROM assignment_offering_settings aos

        INNER JOIN courses c
            ON c.id = aos.course_id

        LEFT JOIN course_offering_lessons col
            ON col.id = aos.course_offering_lesson_id
           AND col.offering_id = aos.offering_id

        LEFT JOIN lesson_versions lv
            ON lv.id = col.lesson_version_id

        WHERE aos.assignment_id = :assignment_id
          AND aos.offering_id = :offering_id

        LIMIT 1
        '
    );

$metadataStatement->execute([
    'assignment_id' =>
        $assignmentId,

    'offering_id' =>
        $offeringId,
]);

$metadata =
    $metadataStatement->fetch(
        PDO::FETCH_ASSOC
    )
    ?: [];


/*
|--------------------------------------------------------------------------
| Extra-Credit Tasks
|--------------------------------------------------------------------------
*/

$extraCreditTasks = [];

if (
    (int) (
        $context[
            'allow_extra_credit'
        ]
        ?? 0
    ) === 1
) {
    $extraCreditStatement =
        $pdo->prepare(
            '
            SELECT
                id,
                title,
                instructions,
                points_possible,
                sort_order

            FROM assignment_extra_credit_tasks

            WHERE assignment_version_id = :assignment_version_id

            ORDER BY
                sort_order ASC,
                id ASC
            '
        );

    $extraCreditStatement->execute([
        'assignment_version_id' =>
            (int) $context[
                'assignment_version_id'
            ],
    ]);

    $extraCreditTasks =
        $extraCreditStatement->fetchAll(
            PDO::FETCH_ASSOC
        );
}


/*
|--------------------------------------------------------------------------
| Current State
|--------------------------------------------------------------------------
*/

$availability =
    blackthorne_assignment_submission_availability(
        $pdo,
        $context
    );

$attempts =
    blackthorne_assignment_attempts(
        $pdo,
        (int) $context[
            'enrollment_id'
        ],
        $offeringId,
        $assignmentId
    );

$currentDraft =
    blackthorne_assignment_current_draft(
        $pdo,
        (int) $context[
            'enrollment_id'
        ],
        $offeringId,
        $assignmentId
    );

$errors = [];

$formText =
    (string) (
        $currentDraft[
            'submission_text'
        ]
        ?? ''
    );

$formFilePath =
    (string) (
        $currentDraft[
            'file_path'
        ]
        ?? ''
    );

$extraCreditResponses = [];

if ($currentDraft !== null) {
    $responseStatement =
        $pdo->prepare(
            '
            SELECT
                extra_credit_task_id,
                response_text,
                file_path

            FROM assignment_extra_credit_responses

            WHERE submission_id = :submission_id
              AND assignment_version_id = :assignment_version_id
            '
        );

    $responseStatement->execute([
        'submission_id' =>
            (int) $currentDraft[
                'id'
            ],

        'assignment_version_id' =>
            (int) $currentDraft[
                'assignment_version_id'
            ],
    ]);

    foreach (
        $responseStatement->fetchAll(
            PDO::FETCH_ASSOC
        )
        as $responseRow
    ) {
        $extraCreditResponses[
            (int) $responseRow[
                'extra_credit_task_id'
            ]
        ] =
            $responseRow;
    }
}


/*
|--------------------------------------------------------------------------
| POST - Save Draft / Submit
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
        student_assignment_post_string(
            'action'
        );

    if (
        $errors === []
        && !in_array(
            $action,
            [
                'save_draft',
                'submit_assignment',
            ],
            true
        )
    ) {
        $errors[] =
            'Choose a valid assignment action.';
    }

    $formText =
        student_assignment_post_string(
            'submission_text'
        );

    if ($errors === []) {
        /*
         * Create the student's attempt only when they actually save or submit.
         * Merely viewing the assignment does not consume an attempt.
         */
        if ($currentDraft === null) {
            $draftResult =
                blackthorne_assignment_create_draft(
                    $pdo,
                    $context
                );

            if (
                !$draftResult[
                    'success'
                ]
            ) {
                $errors[] =
                    'A new attempt cannot be started right now.';
            } else {
                $currentDraft =
                    blackthorne_assignment_submission_record(
                        $pdo,
                        (int) $draftResult[
                            'submission_id'
                        ],
                        (int) $context[
                            'enrollment_id'
                        ],
                        $offeringId,
                        $assignmentId
                    );
            }
        }
    }

    $newlyUploadedPaths = [];

    if (
        $errors === []
        && is_array($currentDraft)
    ) {
        $submissionId =
            (int) $currentDraft['id'];

        $formFilePath =
            (string) (
                $currentDraft[
                    'file_path'
                ]
                ?? ''
            );

        $removeExistingFile =
            isset(
                $_POST[
                    'remove_submission_file'
                ]
            );

        if ($removeExistingFile) {
            $formFilePath = '';
        }

        if (
            isset(
                $_FILES[
                    'submission_file'
                ]
            )
            && is_array(
                $_FILES[
                    'submission_file'
                ]
            )
            && (int) (
                $_FILES[
                    'submission_file'
                ]['error']
                ?? UPLOAD_ERR_NO_FILE
            ) !== UPLOAD_ERR_NO_FILE
        ) {
            if (
                (int) (
                    $context[
                        'allow_file_upload'
                    ]
                    ?? 0
                ) !== 1
            ) {
                $errors[] =
                    'File uploads are not allowed for this assignment.';
            } else {
                $uploadResult =
                    student_assignment_upload_file(
                        $_FILES[
                            'submission_file'
                        ],
                        $userId,
                        $submissionId,
                        'submission'
                    );

                if (
                    !$uploadResult[
                        'success'
                    ]
                ) {
                    $errors[] =
                        (string) $uploadResult[
                            'error'
                        ];
                } else {
                    if (
                        $uploadResult[
                            'uploaded'
                        ]
                    ) {
                        $formFilePath =
                            (string) $uploadResult[
                                'path'
                            ];

                        $newlyUploadedPaths[] =
                            $formFilePath;
                    }
                }
            }
        }

        /*
         * Extra-credit responses live with the same submission attempt and
         * assignment-version snapshot. They are optional and may be completed
         * alongside the main assignment.
         */
        $extraCreditTextInput =
            $_POST[
                'extra_credit_text'
            ]
            ?? [];

        if (
            !is_array(
                $extraCreditTextInput
            )
        ) {
            $extraCreditTextInput = [];
        }

        $extraCreditFileInput =
            $_FILES[
                'extra_credit_file'
            ]
            ?? null;

        $pendingExtraCredit = [];

        foreach (
            $extraCreditTasks
            as $taskRow
        ) {
            $taskId =
                (int) $taskRow['id'];

            $existingResponse =
                $extraCreditResponses[
                    $taskId
                ]
                ?? [];

            $responseText =
                isset(
                    $extraCreditTextInput[
                        $taskId
                    ]
                )
                && is_scalar(
                    $extraCreditTextInput[
                        $taskId
                    ]
                )
                    ? trim(
                        (string) $extraCreditTextInput[
                            $taskId
                        ]
                    )
                    : '';

            $responseFilePath =
                (string) (
                    $existingResponse[
                        'file_path'
                    ]
                    ?? ''
                );

            $removeKey =
                'remove_extra_credit_file_'
                . $taskId;

            if (
                isset(
                    $_POST[$removeKey]
                )
            ) {
                $responseFilePath = '';
            }

            if (
                is_array(
                    $extraCreditFileInput
                )
                && isset(
                    $extraCreditFileInput[
                        'error'
                    ][
                        $taskId
                    ]
                )
                && (int) $extraCreditFileInput[
                    'error'
                ][
                    $taskId
                ] !== UPLOAD_ERR_NO_FILE
            ) {
                $taskFile = [
                    'name' =>
                        $extraCreditFileInput[
                            'name'
                        ][
                            $taskId
                        ]
                        ?? '',

                    'type' =>
                        $extraCreditFileInput[
                            'type'
                        ][
                            $taskId
                        ]
                        ?? '',

                    'tmp_name' =>
                        $extraCreditFileInput[
                            'tmp_name'
                        ][
                            $taskId
                        ]
                        ?? '',

                    'error' =>
                        $extraCreditFileInput[
                            'error'
                        ][
                            $taskId
                        ]
                        ?? UPLOAD_ERR_NO_FILE,

                    'size' =>
                        $extraCreditFileInput[
                            'size'
                        ][
                            $taskId
                        ]
                        ?? 0,
                ];

                $taskUpload =
                    student_assignment_upload_file(
                        $taskFile,
                        $userId,
                        $submissionId,
                        'extra-credit-'
                        . $taskId
                    );

                if (
                    !$taskUpload[
                        'success'
                    ]
                ) {
                    $errors[] =
                        'Extra credit "'
                        . (string) $taskRow[
                            'title'
                        ]
                        . '": '
                        . (string) $taskUpload[
                            'error'
                        ];
                } elseif (
                    $taskUpload[
                        'uploaded'
                    ]
                ) {
                    $responseFilePath =
                        (string) $taskUpload[
                            'path'
                        ];

                    $newlyUploadedPaths[] =
                        $responseFilePath;
                }
            }

            $pendingExtraCredit[
                $taskId
            ] = [
                'text' =>
                    $responseText,

                'file_path' =>
                    $responseFilePath,

                'previous_file_path' =>
                    (string) (
                        $existingResponse[
                            'file_path'
                        ]
                        ?? ''
                    ),
            ];
        }

        if ($errors === []) {
            try {
                $pdo->beginTransaction();

                $saveResult =
                    blackthorne_assignment_save_draft(
                        $pdo,
                        $context,
                        $submissionId,
                        $formText,
                        $formFilePath
                    );

                if (
                    !$saveResult[
                        'success'
                    ]
                ) {
                    foreach (
                        $saveResult[
                            'errors'
                        ]
                        ?? []
                        as $saveError
                    ) {
                        $errors[] =
                            (string) $saveError;
                    }

                    if ($errors === []) {
                        $errors[] =
                            'The assignment draft could not be saved.';
                    }

                    throw new RuntimeException(
                        'Assignment draft save failed.'
                    );
                }

                foreach (
                    $pendingExtraCredit
                    as $taskId => $responseData
                ) {
                    $responseText =
                        trim(
                            (string) $responseData[
                                'text'
                            ]
                        );

                    $responseFilePath =
                        trim(
                            (string) $responseData[
                                'file_path'
                            ]
                        );

                    if (
                        $responseText === ''
                        && $responseFilePath === ''
                    ) {
                        $deleteResponse =
                            $pdo->prepare(
                                '
                                DELETE FROM assignment_extra_credit_responses

                                WHERE submission_id = :submission_id
                                  AND extra_credit_task_id = :extra_credit_task_id
                                '
                            );

                        $deleteResponse->execute([
                            'submission_id' =>
                                $submissionId,

                            'extra_credit_task_id' =>
                                $taskId,
                        ]);

                        continue;
                    }

                    $upsertResponse =
                        $pdo->prepare(
                            '
                            INSERT INTO assignment_extra_credit_responses (
                                submission_id,
                                assignment_version_id,
                                extra_credit_task_id,
                                response_text,
                                file_path
                            ) VALUES (
                                :submission_id,
                                :assignment_version_id,
                                :extra_credit_task_id,
                                :response_text,
                                :file_path
                            )

                            ON DUPLICATE KEY UPDATE
                                response_text = VALUES(response_text),
                                file_path = VALUES(file_path)
                            '
                        );

                    $upsertResponse->execute([
                        'submission_id' =>
                            $submissionId,

                        'assignment_version_id' =>
                            (int) $currentDraft[
                                'assignment_version_id'
                            ],

                        'extra_credit_task_id' =>
                            $taskId,

                        'response_text' =>
                            $responseText !== ''
                                ? $responseText
                                : null,

                        'file_path' =>
                            $responseFilePath !== ''
                                ? $responseFilePath
                                : null,
                    ]);
                }

                if (
                    $action
                    === 'submit_assignment'
                ) {
                    $submitResult =
                        blackthorne_assignment_submit(
                            $pdo,
                            $context,
                            $submissionId,
                            $formText,
                            $formFilePath
                        );

                    if (
                        !$submitResult[
                            'success'
                        ]
                    ) {
                        foreach (
                            $submitResult[
                                'errors'
                            ]
                            ?? []
                            as $submitError
                        ) {
                            $errors[] =
                                (string) $submitError;
                        }

                        if ($errors === []) {
                            $errors[] =
                                'The assignment could not be submitted.';
                        }

                        throw new RuntimeException(
                            'Assignment submit failed.'
                        );
                    }
                }

                $pdo->commit();

                /*
                 * Delete replaced files only after the database transaction
                 * succeeds, so a failed save never destroys the old draft.
                 */
                $oldMainFile =
                    (string) (
                        $currentDraft[
                            'file_path'
                        ]
                        ?? ''
                    );

                if (
                    $oldMainFile !== ''
                    && $oldMainFile
                        !== $formFilePath
                ) {
                    student_assignment_safe_unlink(
                        $oldMainFile
                    );
                }

                foreach (
                    $pendingExtraCredit
                    as $responseData
                ) {
                    $previousPath =
                        trim(
                            (string) $responseData[
                                'previous_file_path'
                            ]
                        );

                    $newPath =
                        trim(
                            (string) $responseData[
                                'file_path'
                            ]
                        );

                    if (
                        $previousPath !== ''
                        && $previousPath
                            !== $newPath
                    ) {
                        student_assignment_safe_unlink(
                            $previousPath
                        );
                    }
                }

                if (
                    $action
                    === 'submit_assignment'
                ) {
                    set_flash(
                        'success',
                        'Your assignment was submitted successfully.'
                    );
                } else {
                    set_flash(
                        'success',
                        'Your assignment draft was saved.'
                    );
                }

                redirect(
                    url(
                        'assignment.php?offering='
                        . $offeringId
                        . '&assignment='
                        . $assignmentId
                    )
                );
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                foreach (
                    $newlyUploadedPaths
                    as $newlyUploadedPath
                ) {
                    student_assignment_safe_unlink(
                        $newlyUploadedPath
                    );
                }

                error_log(
                    'Student assignment save error: '
                    . $exception->getMessage()
                );

                if ($errors === []) {
                    $errors[] =
                        'Your assignment could not be saved. Please try again.';
                }
            }
        } else {
            foreach (
                $newlyUploadedPaths
                as $newlyUploadedPath
            ) {
                student_assignment_safe_unlink(
                    $newlyUploadedPath
                );
            }
        }
    }

    /*
     * Refresh page state after an unsuccessful POST.
     */
    $availability =
        blackthorne_assignment_submission_availability(
            $pdo,
            $context
        );

    $attempts =
        blackthorne_assignment_attempts(
            $pdo,
            (int) $context[
                'enrollment_id'
            ],
            $offeringId,
            $assignmentId
        );

    $currentDraft =
        blackthorne_assignment_current_draft(
            $pdo,
            (int) $context[
                'enrollment_id'
            ],
            $offeringId,
            $assignmentId
        );
}


/*
|--------------------------------------------------------------------------
| Page State Labels
|--------------------------------------------------------------------------
*/

$courseTitle =
    (string) (
        $metadata[
            'course_title'
        ]
        ?? 'Course'
    );

$lessonTitle =
    trim(
        (string) (
            $metadata[
                'lesson_title'
            ]
            ?? ''
        )
    );

$assignmentTitle =
    (string) (
        $context['title']
        ?? $context[
            'internal_name'
        ]
        ?? 'Assignment'
    );

$reasonMessages = [
    'enrollment_suspended' =>
        'Your course enrollment is currently suspended.',

    'enrollment_inactive' =>
        'Your course enrollment is not active.',

    'offering_archived' =>
        'This course offering has been archived.',

    'course_access_ended' =>
        'Access to this course has ended.',

    'assignment_unpublished' =>
        'This assignment is not currently published.',

    'assignment_version_unpublished' =>
        'This assignment version is not currently published.',

    'submission_closed' =>
        'The submission window for this assignment has closed.',

    'late_submissions_not_allowed' =>
        'The due date has passed and late submissions are not accepted.',

    'attempt_limit_reached' =>
        'You have used all available attempts for this assignment.',

    'already_submitted' =>
        'This assignment has already been submitted.',
];

$availabilityMessage =
    $reasonMessages[
        (string) $availability[
            'reason'
        ]
    ]
    ?? '';

$pageTitle =
    $assignmentTitle
    . ' | Blackthorne Academy';

$pageDescription =
    'View and submit coursework for '
    . $courseTitle
    . '.';

$pageCanonical =
    url(
        'assignment.php?offering='
        . $offeringId
        . '&assignment='
        . $assignmentId
    );

$robots =
    'noindex, nofollow';

require INCLUDES_PATH . '/header.php';

?>

<main
    id="main-content"
    class="dashboard-page assignment-page"
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
                            <?= e($assignmentTitle); ?>
                        </h1>

                        <?php if ($lessonTitle !== ''): ?>
                            <p>
                                Related lesson:
                                <strong>
                                    <?= e($lessonTitle); ?>
                                </strong>
                            </p>
                        <?php endif; ?>

                    </div>
                </header>


                <?php if ($errors !== []): ?>

                    <div
                        class="form-message form-message-error"
                        role="alert"
                    >
                        <strong>
                            Your assignment could not be saved.
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
                                Assignment
                            </p>

                            <h2>
                                Instructions
                            </h2>
                        </div>
                    </div>

                    <div class="dashboard-panel-body">

                        <?php if (
                            trim(
                                (string) (
                                    $context[
                                        'description'
                                    ]
                                    ?? ''
                                )
                            ) !== ''
                        ): ?>

                            <p>
                                <?= nl2br(
                                    e(
                                        (string) $context[
                                            'description'
                                        ]
                                    )
                                ); ?>
                            </p>

                        <?php endif; ?>

                        <?php if (
                            trim(
                                (string) (
                                    $context[
                                        'instructions'
                                    ]
                                    ?? ''
                                )
                            ) !== ''
                        ): ?>

                            <div class="forum-post-content">
                                <?= nl2br(
                                    e(
                                        (string) $context[
                                            'instructions'
                                        ]
                                    )
                                ); ?>
                            </div>

                        <?php else: ?>

                            <p>
                                No additional instructions have been provided.
                            </p>

                        <?php endif; ?>

                    </div>

                </section>


                <section class="dashboard-workspace-panel">

                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">
                                Submission Details
                            </p>

                            <h2>
                                Requirements &amp; Deadlines
                            </h2>
                        </div>
                    </div>

                    <div class="dashboard-panel-body">

                        <div class="dashboard-placeholder-list">

                            <span>
                                <strong>Points:</strong>
                                <?= e(
                                    number_format(
                                        (float) (
                                            $context[
                                                'points_possible'
                                            ]
                                            ?? 0
                                        ),
                                        2
                                    )
                                ); ?>
                            </span>

                            <span>
                                <strong>Due:</strong>
                                <?= e(
                                    student_assignment_format_datetime(
                                        $context[
                                            'due_date'
                                        ]
                                        ?? null
                                    )
                                ); ?>
                            </span>

                            <span>
                                <strong>Submission closes:</strong>
                                <?= e(
                                    student_assignment_format_datetime(
                                        $context[
                                            'submission_close_date'
                                        ]
                                        ?? null
                                    )
                                ); ?>
                            </span>

                            <span>
                                <strong>Attempts:</strong>
                                <?= number_format(
                                    (int) $availability[
                                        'attempts_used'
                                    ]
                                ); ?>
                                /
                                <?= number_format(
                                    (int) $availability[
                                        'attempts_allowed'
                                    ]
                                ); ?>
                            </span>

                            <span>
                                <strong>Late submissions:</strong>
                                <?= (int) (
                                    $context[
                                        'allow_late_submissions'
                                    ]
                                    ?? 0
                                ) === 1
                                    ? 'Allowed'
                                    : 'Not allowed'; ?>
                            </span>

                            <span>
                                <strong>Submission method:</strong>

                                <?php
                                $methods = [];

                                if (
                                    (int) (
                                        $context[
                                            'allow_text_submission'
                                        ]
                                        ?? 0
                                    ) === 1
                                ) {
                                    $methods[] =
                                        'Text';
                                }

                                if (
                                    (int) (
                                        $context[
                                            'allow_file_upload'
                                        ]
                                        ?? 0
                                    ) === 1
                                ) {
                                    $methods[] =
                                        'File upload';
                                }

                                echo e(
                                    implode(
                                        ' + ',
                                        $methods
                                    )
                                );
                                ?>
                            </span>

                        </div>

                        <?php if (
                            (bool) $availability[
                                'is_late'
                            ]
                            && (bool) $availability[
                                'can_submit'
                            ]
                        ): ?>

                            <p class="form-help">
                                The due date has passed. This submission will be recorded as late.
                            </p>

                        <?php elseif (
                            !(bool) $availability[
                                'can_submit'
                            ]
                            && $availabilityMessage !== ''
                        ): ?>

                            <div class="form-message form-message-error">
                                <?= e(
                                    $availabilityMessage
                                ); ?>
                            </div>

                        <?php endif; ?>

                    </div>

                </section>


                <?php if (
                    (bool) $availability[
                        'can_save_draft'
                    ]
                    || (bool) $availability[
                        'can_submit'
                    ]
                ): ?>

                    <section class="forum-admin-panel">

                        <header class="forum-admin-titlebar">
                            <p class="forum-admin-step">
                                Your Work
                            </p>

                            <h2>
                                <?= $currentDraft !== null
                                    ? 'Current Draft'
                                    : 'Start Assignment'; ?>
                            </h2>
                        </header>

                        <form
                            method="post"
                            enctype="multipart/form-data"
                            action="<?= e(
                                url(
                                    'assignment.php?offering='
                                    . $offeringId
                                    . '&assignment='
                                    . $assignmentId
                                )
                            ); ?>"
                            class="forum-admin-form"
                        >
                            <?= csrf_field(); ?>


                            <?php if (
                                (int) (
                                    $context[
                                        'allow_text_submission'
                                    ]
                                    ?? 0
                                ) === 1
                            ): ?>

                                <div class="form-group">
                                    <label for="submission-text">
                                        Written Response
                                    </label>

                                    <textarea
                                        class="form-control"
                                        id="submission-text"
                                        name="submission_text"
                                        rows="14"
                                    ><?= e($formText); ?></textarea>
                                </div>

                            <?php endif; ?>


                            <?php if (
                                (int) (
                                    $context[
                                        'allow_file_upload'
                                    ]
                                    ?? 0
                                ) === 1
                            ): ?>

                                <div class="form-group">
                                    <label for="submission-file">
                                        Assignment File
                                    </label>

                                    <input
                                        class="form-control"
                                        type="file"
                                        id="submission-file"
                                        name="submission_file"
                                        accept=".pdf,.doc,.docx,.odt,.rtf,.txt,.jpg,.jpeg,.png,.webp"
                                    >

                                    <p class="form-help">
                                        Accepted: PDF, DOC, DOCX, ODT, RTF, TXT, JPG, PNG, or WebP. Maximum 20 MB.
                                    </p>
                                </div>

                                <?php if ($formFilePath !== ''): ?>

                                    <div class="form-group">

                                        <p>
                                            Current file:
                                            <a
                                                href="<?= e(
                                                    url(
                                                        $formFilePath
                                                    )
                                                ); ?>"
                                                target="_blank"
                                                rel="noopener"
                                            >
                                                View uploaded file
                                            </a>
                                        </p>

                                        <label class="forum-admin-choice">
                                            <input
                                                type="checkbox"
                                                name="remove_submission_file"
                                                value="1"
                                            >

                                            <span>
                                                Remove current file
                                            </span>
                                        </label>

                                    </div>

                                <?php endif; ?>

                            <?php endif; ?>


                            <?php if (
                                $extraCreditTasks !== []
                            ): ?>

                                <fieldset class="forum-admin-fieldset">

                                    <legend>
                                        Optional Extra Credit
                                    </legend>

                                    <p class="form-help">
                                        Extra-credit work is optional and is stored with this assignment attempt.
                                    </p>

                                    <?php foreach (
                                        $extraCreditTasks
                                        as $taskRow
                                    ): ?>
                                        <?php
                                        $taskId =
                                            (int) $taskRow['id'];

                                        $response =
                                            $extraCreditResponses[
                                                $taskId
                                            ]
                                            ?? [];

                                        $responseText =
                                            isset(
                                                $_POST[
                                                    'extra_credit_text'
                                                ][
                                                    $taskId
                                                ]
                                            )
                                            && is_scalar(
                                                $_POST[
                                                    'extra_credit_text'
                                                ][
                                                    $taskId
                                                ]
                                            )
                                                ? trim(
                                                    (string) $_POST[
                                                        'extra_credit_text'
                                                    ][
                                                        $taskId
                                                    ]
                                                )
                                                : (string) (
                                                    $response[
                                                        'response_text'
                                                    ]
                                                    ?? ''
                                                );

                                        $responseFile =
                                            (string) (
                                                $response[
                                                    'file_path'
                                                ]
                                                ?? ''
                                            );
                                        ?>

                                        <div class="dashboard-workspace-panel">

                                            <div class="dashboard-panel-body">

                                                <h3>
                                                    <?= e(
                                                        (string) $taskRow[
                                                            'title'
                                                        ]
                                                    ); ?>
                                                </h3>

                                                <p>
                                                    Worth up to
                                                    <strong>
                                                        <?= e(
                                                            number_format(
                                                                (float) (
                                                                    $taskRow[
                                                                        'points_possible'
                                                                    ]
                                                                    ?? 0
                                                                ),
                                                                2
                                                            )
                                                        ); ?>
                                                        points
                                                    </strong>
                                                </p>

                                                <?php if (
                                                    trim(
                                                        (string) (
                                                            $taskRow[
                                                                'instructions'
                                                            ]
                                                            ?? ''
                                                        )
                                                    ) !== ''
                                                ): ?>

                                                    <p>
                                                        <?= nl2br(
                                                            e(
                                                                (string) $taskRow[
                                                                    'instructions'
                                                                ]
                                                            )
                                                        ); ?>
                                                    </p>

                                                <?php endif; ?>


                                                <div class="form-group">
                                                    <label
                                                        for="extra-credit-text-<?= $taskId; ?>"
                                                    >
                                                        Extra-Credit Response
                                                    </label>

                                                    <textarea
                                                        class="form-control"
                                                        id="extra-credit-text-<?= $taskId; ?>"
                                                        name="extra_credit_text[<?= $taskId; ?>]"
                                                        rows="6"
                                                    ><?= e($responseText); ?></textarea>
                                                </div>


                                                <div class="form-group">
                                                    <label
                                                        for="extra-credit-file-<?= $taskId; ?>"
                                                    >
                                                        Optional File
                                                    </label>

                                                    <input
                                                        class="form-control"
                                                        type="file"
                                                        id="extra-credit-file-<?= $taskId; ?>"
                                                        name="extra_credit_file[<?= $taskId; ?>]"
                                                        accept=".pdf,.doc,.docx,.odt,.rtf,.txt,.jpg,.jpeg,.png,.webp"
                                                    >
                                                </div>


                                                <?php if (
                                                    $responseFile !== ''
                                                ): ?>

                                                    <p>
                                                        Current file:
                                                        <a
                                                            href="<?= e(
                                                                url(
                                                                    $responseFile
                                                                )
                                                            ); ?>"
                                                            target="_blank"
                                                            rel="noopener"
                                                        >
                                                            View uploaded file
                                                        </a>
                                                    </p>

                                                    <label class="forum-admin-choice">
                                                        <input
                                                            type="checkbox"
                                                            name="remove_extra_credit_file_<?= $taskId; ?>"
                                                            value="1"
                                                        >

                                                        <span>
                                                            Remove this extra-credit file
                                                        </span>
                                                    </label>

                                                <?php endif; ?>

                                            </div>

                                        </div>

                                    <?php endforeach; ?>

                                </fieldset>

                            <?php endif; ?>


                            <div class="forum-admin-actions">

                                <?php if (
                                    (bool) $availability[
                                        'can_save_draft'
                                    ]
                                ): ?>

                                    <button
                                        type="submit"
                                        class="button button-secondary"
                                        name="action"
                                        value="save_draft"
                                    >
                                        Save Draft
                                    </button>

                                <?php endif; ?>


                                <?php if (
                                    (bool) $availability[
                                        'can_submit'
                                    ]
                                ): ?>

                                    <button
                                        type="submit"
                                        class="button button-primary"
                                        name="action"
                                        value="submit_assignment"
                                    >
                                        Submit Assignment
                                    </button>

                                <?php endif; ?>

                            </div>

                        </form>

                    </section>

                <?php endif; ?>


                <section class="dashboard-workspace-panel">

                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">
                                Submission History
                            </p>

                            <h2>
                                Your Attempts
                            </h2>
                        </div>
                    </div>

                    <div class="dashboard-panel-body">

                        <?php if ($attempts === []): ?>

                            <p>
                                You have not started this assignment yet.
                            </p>

                        <?php else: ?>

                            <div class="dashboard-placeholder-list">

                                <?php foreach (
                                    array_reverse(
                                        $attempts
                                    )
                                    as $attempt
                                ): ?>

                                    <span>

                                        <strong>
                                            Attempt
                                            <?= number_format(
                                                (int) (
                                                    $attempt[
                                                        'attempt_number'
                                                    ]
                                                    ?? 1
                                                )
                                            ); ?>
                                        </strong>

                                        ·
                                        <?= e(
                                            ucfirst(
                                                (string) (
                                                    $attempt[
                                                        'status'
                                                    ]
                                                    ?? 'draft'
                                                )
                                            )
                                        ); ?>

                                        · Version
                                        <?= number_format(
                                            (int) (
                                                $attempt[
                                                    'assignment_version_id'
                                                ]
                                                ?? 0
                                            )
                                        ); ?>

                                        ·
                                        <?= e(
                                            number_format(
                                                (float) (
                                                    $attempt[
                                                        'points_possible_snapshot'
                                                    ]
                                                    ?? 0
                                                ),
                                                2
                                            )
                                        ); ?>
                                        points possible

                                        <?php if (
                                            !empty(
                                                $attempt[
                                                    'submitted_at'
                                                ]
                                            )
                                        ): ?>
                                            · Submitted
                                            <?= e(
                                                student_assignment_format_datetime(
                                                    $attempt[
                                                        'submitted_at'
                                                    ]
                                                )
                                            ); ?>
                                        <?php endif; ?>

                                        <?php if (
                                            $attempt[
                                                'grade'
                                            ] !== null
                                        ): ?>
                                            · Grade:
                                            <strong>
                                                <?= e(
                                                    number_format(
                                                        (float) $attempt[
                                                            'grade'
                                                        ],
                                                        2
                                                    )
                                                ); ?>
                                            </strong>
                                        <?php endif; ?>

                                        <?php if (
                                            trim(
                                                (string) (
                                                    $attempt[
                                                        'instructor_feedback'
                                                    ]
                                                    ?? ''
                                                )
                                            ) !== ''
                                        ): ?>
                                            <br>
                                            <strong>Instructor Feedback:</strong>
                                            <?= nl2br(
                                                e(
                                                    (string) $attempt[
                                                        'instructor_feedback'
                                                    ]
                                                )
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

<?php

require INCLUDES_PATH . '/footer.php';
