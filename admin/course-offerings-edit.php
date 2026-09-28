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

$canManageOfferings =
    $isProtectedSuperAdmin
    || user_can('courses.offerings.manage');

if (
    !$hasStaffIdentity
    || !$canManageOfferings
) {
    http_response_code(403);

    $pageTitle =
        'Access Denied | Blackthorne Academy';

    $pageDescription =
        'You do not have permission to edit course offerings.';

    $pageCanonical =
        url('admin/course-offerings.php');

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
                    Your account does not have permission to edit
                    course offerings.
                </p>

                <a
                    class="button button-secondary"
                    href="<?= e(url('admin/course-offerings.php')); ?>"
                >
                    Return to Course Offerings
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

function offering_edit_post_string(
    string $key
): string {
    $value =
        $_POST[$key]
        ?? '';

    return is_scalar($value)
        ? trim((string) $value)
        : '';
}


function offering_edit_post_id(
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


function offering_edit_post_ids(
    string $key
): array {
    $values =
        $_POST[$key]
        ?? [];

    if (!is_array($values)) {
        return [];
    }

    $ids = [];

    foreach ($values as $value) {
        if (
            is_scalar($value)
            && ctype_digit(
                (string) $value
            )
        ) {
            $id =
                (int) $value;

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
    }

    return array_values($ids);
}


function offering_edit_datetime_input(
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


function offering_edit_normalize_datetime(
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


function offering_edit_audit(
    PDO $pdo,
    int $actorUserId,
    int $offeringId,
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
                'course_offering.update',

            'entity_type' =>
                'course_offering',

            'entity_id' =>
                $offeringId,

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
            'Course offering edit audit error: '
            . $exception->getMessage()
        );
    }
}


/*
|--------------------------------------------------------------------------
| Offering
|--------------------------------------------------------------------------
*/

$offeringId =
    offering_edit_post_id(
        'offering_id'
    );

if ($offeringId <= 0) {
    $queryOffering =
        $_GET['offering']
        ?? '';

    if (
        is_scalar($queryOffering)
        && ctype_digit(
            (string) $queryOffering
        )
    ) {
        $offeringId =
            (int) $queryOffering;
    }
}

if ($offeringId <= 0) {
    http_response_code(404);

    $pageTitle =
        'Offering Not Found | Blackthorne Academy';

    require INCLUDES_PATH . '/header.php';
    ?>
    <main id="main-content" class="forum-board-page">
        <section class="forum-board-error">
            <div class="section-inner">
                <h1>
                    Course Offering Not Found
                </h1>

                <a
                    class="button button-secondary"
                    href="<?= e(url('admin/course-offerings.php')); ?>"
                >
                    Return to Course Offerings
                </a>
            </div>
        </section>
    </main>
    <?php
    require INCLUDES_PATH . '/footer.php';
    exit;
}

$offeringStatement =
    $pdo->prepare(
        '
        SELECT
            co.id,
            co.course_id,
            co.offering_scope,
            co.school_year_id,
            co.pacing_mode,
            co.drip_basis,
            co.course_start_date,
            co.course_end_date,
            co.enrollment_open_date,
            co.enrollment_close_date,
            co.status,
            c.title AS course_title,
            (
                SELECT COUNT(*)
                FROM course_enrollments ce
                WHERE ce.offering_id = co.id
            ) AS enrollment_count,
            (
                SELECT coyg.year_group_id
                FROM course_offering_year_groups coyg
                WHERE coyg.offering_id = co.id
                ORDER BY coyg.id ASC
                LIMIT 1
            ) AS year_group_id

        FROM course_offerings co

        INNER JOIN courses c
            ON c.id = co.course_id

        WHERE co.id = :offering_id

        LIMIT 1
        '
    );

$offeringStatement->execute([
    'offering_id' =>
        $offeringId,
]);

$offering =
    $offeringStatement->fetch(
        PDO::FETCH_ASSOC
    );

if (!$offering) {
    http_response_code(404);

    $pageTitle =
        'Offering Not Found | Blackthorne Academy';

    require INCLUDES_PATH . '/header.php';
    ?>
    <main id="main-content" class="forum-board-page">
        <section class="forum-board-error">
            <div class="section-inner">
                <h1>
                    Course Offering Not Found
                </h1>

                <a
                    class="button button-secondary"
                    href="<?= e(url('admin/course-offerings.php')); ?>"
                >
                    Return to Course Offerings
                </a>
            </div>
        </section>
    </main>
    <?php
    require INCLUDES_PATH . '/footer.php';
    exit;
}

$isPerpetual =
    (
        $offering[
            'offering_scope'
        ]
        ?? ''
    ) === 'perpetual';

$hasEnrollments =
    (int) (
        $offering[
            'enrollment_count'
        ]
        ?? 0
    ) > 0;


/*
|--------------------------------------------------------------------------
| Reference Data
|--------------------------------------------------------------------------
*/

$schoolYears =
    $pdo->query(
        '
        SELECT
            id,
            name,
            start_date,
            end_date,
            is_current

        FROM school_years

        WHERE is_active = 1

        ORDER BY
            is_current DESC,
            start_date DESC,
            id DESC
        '
    )->fetchAll(
        PDO::FETCH_ASSOC
    );

$yearGroups =
    $pdo->query(
        '
        SELECT
            id,
            name,
            year_number

        FROM year_groups

        WHERE is_active = 1

        ORDER BY
            sort_order ASC,
            year_number ASC,
            name ASC
        '
    )->fetchAll(
        PDO::FETCH_ASSOC
    );

$superAdminUserId =
    configured_super_admin_user_id();

$staffStatement =
    $pdo->prepare(
        '
        SELECT DISTINCT
            u.id,
            u.display_name,
            u.username

        FROM users u

        WHERE u.status = "active"
          AND (
                u.id = :super_admin_user_id
                OR EXISTS (
                    SELECT 1

                    FROM user_roles ur

                    INNER JOIN roles r
                        ON r.id = ur.role_id

                    WHERE ur.user_id = u.id
                      AND ur.is_active = 1
                      AND ur.revoked_at IS NULL
                      AND (
                            ur.expires_at IS NULL
                            OR ur.expires_at > CURRENT_TIMESTAMP
                          )
                      AND r.is_active = 1
                      AND (
                            r.is_staff = 1
                            OR r.grants_all_permissions = 1
                          )
                )
              )

        ORDER BY
            u.display_name ASC,
            u.username ASC,
            u.id ASC
        '
    );

$staffStatement->execute([
    'super_admin_user_id' =>
        $superAdminUserId
        ?? 0,
]);

$availableStaff =
    $staffStatement->fetchAll(
        PDO::FETCH_ASSOC
    );

$currentStaffStatement =
    $pdo->prepare(
        '
        SELECT
            user_id,
            instructor_role

        FROM course_instructors

        WHERE offering_id = :offering_id
          AND is_active = 1
          AND ended_at IS NULL

        ORDER BY
            FIELD(
                instructor_role,
                "primary",
                "co_instructor",
                "assistant"
            ) ASC,
            assigned_at ASC,
            id ASC
        '
    );

$currentStaffStatement->execute([
    'offering_id' =>
        $offeringId,
]);

$currentStaff =
    $currentStaffStatement->fetchAll(
        PDO::FETCH_ASSOC
    );

$currentInstructorIds = [];
$currentTaIds = [];

foreach ($currentStaff as $staffRow) {
    $staffUserId =
        (int) (
            $staffRow[
                'user_id'
            ]
            ?? 0
        );

    if ($staffUserId <= 0) {
        continue;
    }

    if (
        (
            $staffRow[
                'instructor_role'
            ]
            ?? ''
        ) === 'assistant'
    ) {
        $currentTaIds[] =
            $staffUserId;
    } else {
        $currentInstructorIds[] =
            $staffUserId;
    }
}


/*
|--------------------------------------------------------------------------
| Form State
|--------------------------------------------------------------------------
*/

$form = [
    'school_year_id' =>
        (string) (
            $offering[
                'school_year_id'
            ]
            ?? ''
        ),

    'year_group_id' =>
        (string) (
            $offering[
                'year_group_id'
            ]
            ?? ''
        ),

    'course_start_date' =>
        offering_edit_datetime_input(
            $offering[
                'course_start_date'
            ]
            ?? null
        ),

    'course_end_date' =>
        offering_edit_datetime_input(
            $offering[
                'course_end_date'
            ]
            ?? null
        ),

    'enrollment_open_date' =>
        offering_edit_datetime_input(
            $offering[
                'enrollment_open_date'
            ]
            ?? null
        ),

    'enrollment_close_date' =>
        offering_edit_datetime_input(
            $offering[
                'enrollment_close_date'
            ]
            ?? null
        ),

    'status' =>
        (string) (
            $offering[
                'status'
            ]
            ?? 'draft'
        ),

    'instructor_ids' =>
        $currentInstructorIds,

    'ta_ids' =>
        $currentTaIds,
];

$errors = [];


/*
|--------------------------------------------------------------------------
| Update
|--------------------------------------------------------------------------
*/

if (
    ($_SERVER['REQUEST_METHOD'] ?? '')
    === 'POST'
    && offering_edit_post_string(
        'action'
    ) === 'update_offering'
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

    $form['school_year_id'] =
        (string) offering_edit_post_id(
            'school_year_id'
        );

    $form['year_group_id'] =
        (string) offering_edit_post_id(
            'year_group_id'
        );

    $form['course_start_date'] =
        offering_edit_post_string(
            'course_start_date'
        );

    $form['course_end_date'] =
        offering_edit_post_string(
            'course_end_date'
        );

    $form['enrollment_open_date'] =
        offering_edit_post_string(
            'enrollment_open_date'
        );

    $form['enrollment_close_date'] =
        offering_edit_post_string(
            'enrollment_close_date'
        );

    $form['status'] =
        offering_edit_post_string(
            'status'
        );

    $form['instructor_ids'] =
        offering_edit_post_ids(
            'instructor_ids'
        );

    $form['ta_ids'] =
        offering_edit_post_ids(
            'ta_ids'
        );

    $schoolYearId =
        (int) $form[
            'school_year_id'
        ];

    $yearGroupId =
        (int) $form[
            'year_group_id'
        ];

    if ($isPerpetual) {
        $schoolYearId = 0;
        $yearGroupId = 0;

        $form[
            'school_year_id'
        ] = '';

        $form[
            'year_group_id'
        ] = '';

        $form[
            'course_end_date'
        ] = '';
    } else {
        if ($hasEnrollments) {
            $schoolYearId =
                (int) (
                    $offering[
                        'school_year_id'
                    ]
                    ?? 0
                );

            $yearGroupId =
                (int) (
                    $offering[
                        'year_group_id'
                    ]
                    ?? 0
                );

            $form[
                'school_year_id'
            ] =
                (string) $schoolYearId;

            $form[
                'year_group_id'
            ] =
                (string) $yearGroupId;
        }

        if ($schoolYearId <= 0) {
            $errors[] =
                'School Year is required.';
        }

        if ($yearGroupId <= 0) {
            $errors[] =
                'Grade Level is required.';
        }

        if (
            trim(
                $form[
                    'course_end_date'
                ]
            ) === ''
        ) {
            $errors[] =
                'Course End Date is required for a school-year offering.';
        }
    }

    if (
        !in_array(
            $form['status'],
            [
                'draft',
                'open',
                'closed',
                'completed',
                'archived',
            ],
            true
        )
    ) {
        $errors[] =
            'Choose a valid offering status.';
    }

    $courseStartDate =
        offering_edit_normalize_datetime(
            $form[
                'course_start_date'
            ]
        );

    $courseEndDate =
        offering_edit_normalize_datetime(
            $form[
                'course_end_date'
            ]
        );

    $enrollmentOpenDate =
        offering_edit_normalize_datetime(
            $form[
                'enrollment_open_date'
            ]
        );

    $enrollmentCloseDate =
        offering_edit_normalize_datetime(
            $form[
                'enrollment_close_date'
            ]
        );

    foreach (
        [
            [
                'raw' =>
                    $form[
                        'course_start_date'
                    ],

                'value' =>
                    $courseStartDate,

                'label' =>
                    'Course Start Date',
            ],
            [
                'raw' =>
                    $form[
                        'course_end_date'
                    ],

                'value' =>
                    $courseEndDate,

                'label' =>
                    'Course End Date',
            ],
            [
                'raw' =>
                    $form[
                        'enrollment_open_date'
                    ],

                'value' =>
                    $enrollmentOpenDate,

                'label' =>
                    'Enrollment Open Date',
            ],
            [
                'raw' =>
                    $form[
                        'enrollment_close_date'
                    ],

                'value' =>
                    $enrollmentCloseDate,

                'label' =>
                    'Enrollment Close Date',
            ],
        ]
        as $dateField
    ) {
        if (
            $dateField['raw'] !== ''
            && $dateField['value']
                === null
        ) {
            $errors[] =
                $dateField['label']
                . ' is invalid.';
        }
    }

    if (
        $courseStartDate !== null
        && $courseEndDate !== null
        && strtotime($courseEndDate)
            < strtotime($courseStartDate)
    ) {
        $errors[] =
            'Course End Date cannot be earlier than Course Start Date.';
    }

    if (
        $enrollmentOpenDate !== null
        && $enrollmentCloseDate !== null
        && strtotime($enrollmentCloseDate)
            < strtotime($enrollmentOpenDate)
    ) {
        $errors[] =
            'Enrollment Close Date cannot be earlier than Enrollment Open Date.';
    }

    if (
        !$isPerpetual
        && !$hasEnrollments
        && $schoolYearId > 0
    ) {
        $schoolYearCheck =
            $pdo->prepare(
                '
                SELECT 1
                FROM school_years
                WHERE id = :id
                  AND is_active = 1
                LIMIT 1
                '
            );

        $schoolYearCheck->execute([
            'id' =>
                $schoolYearId,
        ]);

        if (
            $schoolYearCheck->fetchColumn()
            === false
        ) {
            $errors[] =
                'The selected School Year is not available.';
        }

        $yearGroupCheck =
            $pdo->prepare(
                '
                SELECT 1
                FROM year_groups
                WHERE id = :id
                  AND is_active = 1
                LIMIT 1
                '
            );

        $yearGroupCheck->execute([
            'id' =>
                $yearGroupId,
        ]);

        if (
            $yearGroupCheck->fetchColumn()
            === false
        ) {
            $errors[] =
                'The selected Grade Level is not available.';
        }

        $duplicateCheck =
            $pdo->prepare(
                '
                SELECT co.id

                FROM course_offerings co

                INNER JOIN course_offering_year_groups coyg
                    ON coyg.offering_id = co.id

                WHERE co.id <> :offering_id
                  AND co.course_id = :course_id
                  AND co.school_year_id = :school_year_id
                  AND co.offering_scope = "school_year"
                  AND co.status <> "archived"
                  AND coyg.year_group_id = :year_group_id

                LIMIT 1
                '
            );

        $duplicateCheck->execute([
            'offering_id' =>
                $offeringId,

            'course_id' =>
                (int) (
                    $offering[
                        'course_id'
                    ]
                    ?? 0
                ),

            'school_year_id' =>
                $schoolYearId,

            'year_group_id' =>
                $yearGroupId,
        ]);

        if (
            $duplicateCheck->fetchColumn()
            !== false
        ) {
            $errors[] =
                'That course already has another non-archived offering for the selected School Year and Grade Level.';
        }
    }

    $duplicateStaffIds =
        array_values(
            array_intersect(
                $form[
                    'instructor_ids'
                ],
                $form[
                    'ta_ids'
                ]
            )
        );

    if ($duplicateStaffIds !== []) {
        $errors[] =
            'The same person cannot be both an Instructor and a Teaching Assistant.';
    }

    $selectedStaffIds =
        array_values(
            array_unique(
                array_merge(
                    $form[
                        'instructor_ids'
                    ],
                    $form[
                        'ta_ids'
                    ]
                )
            )
        );

    if ($selectedStaffIds !== []) {
        $placeholders =
            implode(
                ',',
                array_fill(
                    0,
                    count(
                        $selectedStaffIds
                    ),
                    '?'
                )
            );

        $staffValidation =
            $pdo->prepare(
                '
                SELECT DISTINCT u.id

                FROM users u

                WHERE u.id IN ('
                . $placeholders
                . ')
                  AND u.status = "active"
                  AND (
                        u.id = ?
                        OR EXISTS (
                            SELECT 1

                            FROM user_roles ur

                            INNER JOIN roles r
                                ON r.id = ur.role_id

                            WHERE ur.user_id = u.id
                              AND ur.is_active = 1
                              AND ur.revoked_at IS NULL
                              AND (
                                    ur.expires_at IS NULL
                                    OR ur.expires_at > CURRENT_TIMESTAMP
                                  )
                              AND r.is_active = 1
                              AND (
                                    r.is_staff = 1
                                    OR r.grants_all_permissions = 1
                                  )
                        )
                      )
                '
            );

        $staffValidation->execute(
            array_merge(
                $selectedStaffIds,
                [
                    $superAdminUserId
                    ?? 0,
                ]
            )
        );

        $validStaffIds =
            array_map(
                'intval',
                $staffValidation->fetchAll(
                    PDO::FETCH_COLUMN
                )
            );

        sort($validStaffIds);

        $compareIds =
            $selectedStaffIds;

        sort($compareIds);

        if (
            $validStaffIds
            !== $compareIds
        ) {
            $errors[] =
                'One or more selected staff members are no longer eligible.';
        }
    }

    if ($errors === []) {
        try {
            $pdo->beginTransaction();

            $updateOffering =
                $pdo->prepare(
                    '
                    UPDATE course_offerings
                    SET
                        school_year_id = :school_year_id,
                        pacing_mode = :pacing_mode,
                        drip_basis = :drip_basis,
                        course_start_date = :course_start_date,
                        course_end_date = :course_end_date,
                        enrollment_open_date = :enrollment_open_date,
                        enrollment_close_date = :enrollment_close_date,
                        status = :status
                    WHERE id = :offering_id
                    '
                );

            $updateOffering->execute([
                'school_year_id' =>
                    $isPerpetual
                        ? null
                        : $schoolYearId,

                'pacing_mode' =>
                    $isPerpetual
                        ? 'self_paced'
                        : 'drip',

                'drip_basis' =>
                    $isPerpetual
                        ? null
                        : 'course_start_date',

                'course_start_date' =>
                    $courseStartDate,

                'course_end_date' =>
                    $isPerpetual
                        ? null
                        : $courseEndDate,

                'enrollment_open_date' =>
                    $enrollmentOpenDate,

                'enrollment_close_date' =>
                    $enrollmentCloseDate,

                'status' =>
                    $form[
                        'status'
                    ],

                'offering_id' =>
                    $offeringId,
            ]);

            if (!$isPerpetual) {
                $deleteYearGroups =
                    $pdo->prepare(
                        '
                        DELETE FROM course_offering_year_groups
                        WHERE offering_id = :offering_id
                        '
                    );

                $deleteYearGroups->execute([
                    'offering_id' =>
                        $offeringId,
                ]);

                $insertYearGroup =
                    $pdo->prepare(
                        '
                        INSERT INTO course_offering_year_groups (
                            offering_id,
                            year_group_id,
                            is_visible,
                            is_enrollable
                        ) VALUES (
                            :offering_id,
                            :year_group_id,
                            1,
                            1
                        )
                        '
                    );

                $insertYearGroup->execute([
                    'offering_id' =>
                        $offeringId,

                    'year_group_id' =>
                        $yearGroupId,
                ]);
            }

            /*
             * End the active staffing records instead of deleting historical
             * instructor rows. Existing course_staff_permissions remain tied
             * to the historical assignment they were created for.
             */
            $endStaff =
                $pdo->prepare(
                    '
                    UPDATE course_instructors
                    SET
                        is_active = 0,
                        ended_at = CURRENT_TIMESTAMP,
                        ended_by = :ended_by,
                        end_reason = "Offering staffing updated"
                    WHERE offering_id = :offering_id
                      AND is_active = 1
                      AND ended_at IS NULL
                    '
                );

            $endStaff->execute([
                'ended_by' =>
                    $currentUserId,

                'offering_id' =>
                    $offeringId,
            ]);

            $insertStaff =
                $pdo->prepare(
                    '
                    INSERT INTO course_instructors (
                        offering_id,
                        user_id,
                        instructor_role,
                        is_active
                    ) VALUES (
                        :offering_id,
                        :user_id,
                        :instructor_role,
                        1
                    )
                    '
                );

            foreach (
                array_values(
                    $form[
                        'instructor_ids'
                    ]
                )
                as $index =>
                $staffUserId
            ) {
                $insertStaff->execute([
                    'offering_id' =>
                        $offeringId,

                    'user_id' =>
                        $staffUserId,

                    'instructor_role' =>
                        $index === 0
                            ? 'primary'
                            : 'co_instructor',
                ]);
            }

            foreach (
                $form[
                    'ta_ids'
                ]
                as $staffUserId
            ) {
                $insertStaff->execute([
                    'offering_id' =>
                        $offeringId,

                    'user_id' =>
                        $staffUserId,

                    'instructor_role' =>
                        'assistant',
                ]);
            }

            offering_edit_audit(
                $pdo,
                $currentUserId,
                $offeringId,
                'Updated course offering for '
                . (
                    $offering[
                        'course_title'
                    ]
                    ?? 'course'
                )
            );

            $pdo->commit();

            set_flash(
                'success',
                'Course offering updated successfully.'
            );

            redirect(
                url(
                    'admin/course-offerings.php'
                )
            );
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'Course offering update error: '
                . $exception->getMessage()
            );

            $errors[] =
                'The course offering could not be updated. No changes were saved.';
        }
    }
}


/*
|--------------------------------------------------------------------------
| Shared Sidebar / Page
|--------------------------------------------------------------------------
*/

$courseSidebarActive =
    'offerings';

require INCLUDES_PATH . '/staff-course-sidebar.php';

$pageTitle =
    'Edit Course Offering | Blackthorne Academy';

$pageDescription =
    'Edit a Blackthorne Academy course offering.';

$pageCanonical =
    url(
        'admin/course-offering-edit.php?offering='
        . $offeringId
    );

$robots =
    'noindex, nofollow';

require INCLUDES_PATH . '/header.php';

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
    class="dashboard-page staff-dashboard-page dashboard-workspace-page courses-dashboard-page course-offerings-page"
>

    <section
        class="dashboard-hero staff-dashboard-hero"
        aria-labelledby="course-offering-edit-heading"
        <?php if ($staffHeroUrl !== ''): ?>
            style="--staff-dashboard-hero-image: url('<?= e($staffHeroUrl); ?>');"
        <?php endif; ?>
    >
        <div class="section-inner">
            <div class="dashboard-hero-inner">
                <p class="academy-overline">
                    Academic Administration
                </p>

                <h1 id="course-offering-edit-heading">
                    Edit Course Offering
                </h1>

                <p class="dashboard-hero-copy">
                    Update dates, enrollment windows, status, grade level,
                    and assigned staff for this course run.
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
                                $isPerpetual
                                    ? 'Perpetual Offering'
                                    : 'School-Year Offering'
                            ); ?>
                        </p>

                        <h2>
                            <?= e(
                                (string) (
                                    $offering[
                                        'course_title'
                                    ]
                                    ?? 'Course'
                                )
                            ); ?>
                        </h2>

                        <p>
                            The course and offering scope stay fixed after
                            creation. Everything specific to this course run
                            can be updated here.
                        </p>
                    </div>

                    <div>
                        <a
                            class="button button-secondary"
                            href="<?= e(
                                url(
                                    'admin/course-offerings.php'
                                )
                            ); ?>"
                        >
                            Back to Offerings
                        </a>
                    </div>
                </header>

                <?php if ($errors !== []): ?>
                    <div
                        class="form-message form-message-error"
                        role="alert"
                    >
                        <strong>
                            The offering could not be updated.
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

                <section class="forum-admin-panel">
                    <header class="forum-admin-titlebar">
                        <p class="forum-admin-step">
                            Offering #<?= $offeringId; ?>
                        </p>

                        <h2>
                            Offering Settings
                        </h2>
                    </header>

                    <form
                        action="<?= e(
                            url(
                                'admin/course-offering-edit.php?offering='
                                . $offeringId
                            )
                        ); ?>"
                        method="post"
                        class="forum-admin-form"
                    >
                        <?= csrf_field(); ?>

                        <input
                            type="hidden"
                            name="action"
                            value="update_offering"
                        >

                        <input
                            type="hidden"
                            name="offering_id"
                            value="<?= $offeringId; ?>"
                        >

                        <div class="forum-admin-form-grid">
                            <div class="form-group">
                                <label>
                                    Course
                                </label>

                                <input
                                    class="form-control"
                                    type="text"
                                    value="<?= e(
                                        (string) (
                                            $offering[
                                                'course_title'
                                            ]
                                            ?? ''
                                        )
                                    ); ?>"
                                    disabled
                                >
                            </div>

                            <div class="form-group">
                                <label>
                                    Offering Type
                                </label>

                                <input
                                    class="form-control"
                                    type="text"
                                    value="<?= e(
                                        $isPerpetual
                                            ? 'Perpetual · Self-Paced'
                                            : 'School Year · Drip Content'
                                    ); ?>"
                                    disabled
                                >
                            </div>
                        </div>

                        <?php if (!$isPerpetual): ?>
                            <div class="forum-admin-form-grid">
                                <div class="form-group">
                                    <label for="school-year-id">
                                        School Year
                                    </label>

                                    <?php if ($hasEnrollments): ?>
                                        <?php
                                        $schoolYearName =
                                            'School Year #'
                                            . (int) (
                                                $offering[
                                                    'school_year_id'
                                                ]
                                                ?? 0
                                            );

                                        foreach ($schoolYears as $schoolYear) {
                                            if (
                                                (int) $schoolYear['id']
                                                ===
                                                (int) (
                                                    $offering[
                                                        'school_year_id'
                                                    ]
                                                    ?? 0
                                                )
                                            ) {
                                                $schoolYearName =
                                                    (string) (
                                                        $schoolYear[
                                                            'name'
                                                        ]
                                                        ?? $schoolYearName
                                                    );
                                                break;
                                            }
                                        }
                                        ?>

                                        <input
                                            class="form-control"
                                            type="text"
                                            value="<?= e($schoolYearName); ?>"
                                            disabled
                                        >

                                        <input
                                            type="hidden"
                                            name="school_year_id"
                                            value="<?= (int) (
                                                $offering[
                                                    'school_year_id'
                                                ]
                                                ?? 0
                                            ); ?>"
                                        >
                                    <?php else: ?>
                                        <select
                                            class="form-control"
                                            id="school-year-id"
                                            name="school_year_id"
                                            required
                                        >
                                            <option value="">
                                                Choose a school year
                                            </option>

                                            <?php foreach ($schoolYears as $schoolYear): ?>
                                                <option
                                                    value="<?= (int) $schoolYear['id']; ?>"
                                                    <?= (int) $form['school_year_id'] === (int) $schoolYear['id']
                                                        ? 'selected'
                                                        : ''; ?>
                                                >
                                                    <?= e(
                                                        (string) (
                                                            $schoolYear[
                                                                'name'
                                                            ]
                                                            ?? 'School Year'
                                                        )
                                                    ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>

                                    <?php if ($hasEnrollments): ?>
                                        <p class="form-help">
                                            Locked because students have already
                                            been enrolled in this offering.
                                        </p>
                                    <?php endif; ?>
                                </div>

                                <div class="form-group">
                                    <label for="year-group-id">
                                        Grade Level
                                    </label>

                                    <?php if ($hasEnrollments): ?>
                                        <?php
                                        $yearGroupName =
                                            'Grade Level #'
                                            . (int) (
                                                $offering[
                                                    'year_group_id'
                                                ]
                                                ?? 0
                                            );

                                        foreach ($yearGroups as $yearGroup) {
                                            if (
                                                (int) $yearGroup['id']
                                                ===
                                                (int) (
                                                    $offering[
                                                        'year_group_id'
                                                    ]
                                                    ?? 0
                                                )
                                            ) {
                                                $yearGroupName =
                                                    (string) (
                                                        $yearGroup[
                                                            'name'
                                                        ]
                                                        ?? $yearGroupName
                                                    );
                                                break;
                                            }
                                        }
                                        ?>

                                        <input
                                            class="form-control"
                                            type="text"
                                            value="<?= e($yearGroupName); ?>"
                                            disabled
                                        >

                                        <input
                                            type="hidden"
                                            name="year_group_id"
                                            value="<?= (int) (
                                                $offering[
                                                    'year_group_id'
                                                ]
                                                ?? 0
                                            ); ?>"
                                        >
                                    <?php else: ?>
                                        <select
                                            class="form-control"
                                            id="year-group-id"
                                            name="year_group_id"
                                            required
                                        >
                                            <option value="">
                                                Choose a grade level
                                            </option>

                                            <?php foreach ($yearGroups as $yearGroup): ?>
                                                <option
                                                    value="<?= (int) $yearGroup['id']; ?>"
                                                    <?= (int) $form['year_group_id'] === (int) $yearGroup['id']
                                                        ? 'selected'
                                                        : ''; ?>
                                                >
                                                    <?= e(
                                                        (string) (
                                                            $yearGroup[
                                                                'name'
                                                            ]
                                                            ?? 'Grade Level'
                                                        )
                                                    ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>

                                    <?php if ($hasEnrollments): ?>
                                        <p class="form-help">
                                            Locked because students have already
                                            been enrolled in this offering.
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="forum-admin-form-grid">
                            <div class="form-group">
                                <label for="course-start-date">
                                    Course Start Date
                                </label>

                                <input
                                    class="form-control"
                                    type="datetime-local"
                                    id="course-start-date"
                                    name="course_start_date"
                                    value="<?= e(
                                        $form[
                                            'course_start_date'
                                        ]
                                    ); ?>"
                                >
                            </div>

                            <?php if (!$isPerpetual): ?>
                                <div class="form-group">
                                    <label for="course-end-date">
                                        Course End Date
                                    </label>

                                    <input
                                        class="form-control"
                                        type="datetime-local"
                                        id="course-end-date"
                                        name="course_end_date"
                                        value="<?= e(
                                            $form[
                                                'course_end_date'
                                            ]
                                        ); ?>"
                                        required
                                    >
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="forum-admin-form-grid">
                            <div class="form-group">
                                <label for="enrollment-open-date">
                                    Enrollment Opens
                                </label>

                                <input
                                    class="form-control"
                                    type="datetime-local"
                                    id="enrollment-open-date"
                                    name="enrollment_open_date"
                                    value="<?= e(
                                        $form[
                                            'enrollment_open_date'
                                        ]
                                    ); ?>"
                                >
                            </div>

                            <div class="form-group">
                                <label for="enrollment-close-date">
                                    Enrollment Closes
                                </label>

                                <input
                                    class="form-control"
                                    type="datetime-local"
                                    id="enrollment-close-date"
                                    name="enrollment_close_date"
                                    value="<?= e(
                                        $form[
                                            'enrollment_close_date'
                                        ]
                                    ); ?>"
                                >
                            </div>
                        </div>

                        <div class="forum-admin-form-grid">
                            <div class="form-group">
                                <label for="instructor-ids">
                                    Instructor(s)
                                </label>

                                <select
                                    class="form-control"
                                    id="instructor-ids"
                                    name="instructor_ids[]"
                                    multiple
                                    size="8"
                                >
                                    <?php foreach ($availableStaff as $staffMember): ?>
                                        <?php
                                        $staffName =
                                            trim(
                                                (string) (
                                                    $staffMember[
                                                        'display_name'
                                                    ]
                                                    ?? $staffMember[
                                                        'username'
                                                    ]
                                                    ?? 'Staff'
                                                )
                                            );
                                        ?>

                                        <option
                                            value="<?= (int) $staffMember['id']; ?>"
                                            <?= in_array(
                                                (int) $staffMember['id'],
                                                $form['instructor_ids'],
                                                true
                                            )
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            <?= e($staffName); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <p class="form-help">
                                    The first selected instructor is Primary;
                                    additional selections are Co-Instructors.
                                </p>
                            </div>

                            <div class="form-group">
                                <label for="ta-ids">
                                    Teaching Assistant(s) / TA(s)
                                </label>

                                <select
                                    class="form-control"
                                    id="ta-ids"
                                    name="ta_ids[]"
                                    multiple
                                    size="8"
                                >
                                    <?php foreach ($availableStaff as $staffMember): ?>
                                        <?php
                                        $staffName =
                                            trim(
                                                (string) (
                                                    $staffMember[
                                                        'display_name'
                                                    ]
                                                    ?? $staffMember[
                                                        'username'
                                                    ]
                                                    ?? 'Staff'
                                                )
                                            );
                                        ?>

                                        <option
                                            value="<?= (int) $staffMember['id']; ?>"
                                            <?= in_array(
                                                (int) $staffMember['id'],
                                                $form['ta_ids'],
                                                true
                                            )
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            <?= e($staffName); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="offering-status">
                                Offering Status
                            </label>

                            <select
                                class="form-control"
                                id="offering-status"
                                name="status"
                            >
                                <?php
                                $statuses = [
                                    'draft' => 'Draft',
                                    'open' => 'Open',
                                    'closed' => 'Closed',
                                    'completed' => 'Completed',
                                    'archived' => 'Archived',
                                ];
                                ?>

                                <?php foreach ($statuses as $value => $label): ?>
                                    <option
                                        value="<?= e($value); ?>"
                                        <?= $form['status'] === $value
                                            ? 'selected'
                                            : ''; ?>
                                    >
                                        <?= e($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="forum-admin-actions">
                            <button
                                type="submit"
                                class="button button-primary"
                            >
                                Save Offering
                            </button>

                            <a
                                class="button button-secondary"
                                href="<?= e(
                                    url(
                                        'admin/course-offerings.php'
                                    )
                                ); ?>"
                            >
                                Cancel
                            </a>
                        </div>
                    </form>
                </section>
            </div>
        </div>
    </section>
</main>

<?php
require INCLUDES_PATH . '/footer.php';
