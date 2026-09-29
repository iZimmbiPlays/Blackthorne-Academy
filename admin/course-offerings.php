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
        'You do not have permission to manage course offerings.';

    $pageCanonical =
        url('admin/courses.php');

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
                Your account does not have permission to manage
                course offerings.
            </p>

            <a class="button button-secondary" href="<?= e(url('admin/courses.php')); ?>">
                Return to Courses
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

function course_offerings_post_string(
    string $key
): string {
    $value =
        $_POST[$key]
        ?? '';

    return is_scalar($value)
        ? trim((string) $value)
        : '';
}


function course_offerings_post_id(
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


function course_offerings_post_ids(
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


function course_offerings_normalize_datetime(
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


function course_offerings_format_date(
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


function course_offerings_audit(
    PDO $pdo,
    int $actorUserId,
    int $offeringId,
    string $description,
    string $actionType = 'course_offering.create'
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
            'Course offering audit error: '
            . $exception->getMessage()
        );
    }
}


/*
|--------------------------------------------------------------------------
| Reference Data
|--------------------------------------------------------------------------
*/

$courses =
    $pdo->query(
        '
        SELECT
            id,
            title,
            status

        FROM courses

        WHERE status <> "archived"

        ORDER BY
            title ASC,
            id ASC
        '
    )->fetchAll(
        PDO::FETCH_ASSOC
    );

$schoolYears =
    $pdo->query(
        '
        SELECT
            id,
            name,
            start_date,
            end_date,
            is_current,
            is_finalized

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


$successMessage =
    get_flash(
        'success'
    );


/*
|--------------------------------------------------------------------------
| Form State
|--------------------------------------------------------------------------
*/

$errors = [];

$form = [
    'course_id' => '',
    'offering_scope' => 'school_year',
    'school_year_id' => '',
    'year_group_id' => '',
    'course_start_date' => '',
    'course_end_date' => '',
    'enrollment_open_date' => '',
    'enrollment_close_date' => '',
    'pacing_mode' => 'drip',
    'status' => 'draft',
    'instructor_ids' => [],
    'ta_ids' => [],
];


/*
|--------------------------------------------------------------------------
| Create Offering
|--------------------------------------------------------------------------
*/

if (
    ($_SERVER['REQUEST_METHOD'] ?? '')
    === 'POST'
) {
    $action =
        course_offerings_post_string(
            'action'
        );

    if ($action === 'delete_offering') {
        if (
            !verify_csrf_token(
                $_POST['_csrf_token']
                ?? null
            )
        ) {
            $errors[] =
                'Your form session expired. Refresh the page and try again.';
        }

        $offeringId =
            course_offerings_post_id(
                'offering_id'
            );

        $offeringToDelete = null;

        if (
            $errors === []
            && $offeringId > 0
        ) {
            $offeringStatement =
                $pdo->prepare(
                    '
                    SELECT
                        co.id,
                        co.course_id,
                        co.offering_scope,
                        co.status,
                        c.title AS course_title

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

            $offeringToDelete =
                $offeringStatement->fetch(
                    PDO::FETCH_ASSOC
                );

            if (!$offeringToDelete) {
                $errors[] =
                    'The selected course offering could not be found.';
            }
        }

        if (
            $errors === []
            && $offeringToDelete
        ) {
            $dependencyStatement =
                $pdo->prepare(
                    '
                    SELECT
                        (
                            SELECT COUNT(*)
                            FROM course_enrollments
                            WHERE offering_id = :enrollment_offering_id
                        ) AS enrollment_count,

                        (
                            SELECT COUNT(*)
                            FROM course_offering_lessons
                            WHERE offering_id = :lesson_offering_id
                        ) AS lesson_count,

                        (
                            SELECT COUNT(*)
                            FROM assignment_offering_settings
                            WHERE offering_id = :assignment_offering_id
                        ) AS assignment_count,

                        (
                            SELECT COUNT(*)
                            FROM quiz_offering_settings
                            WHERE offering_id = :quiz_offering_id
                        ) AS quiz_count,

                        (
                            SELECT COUNT(*)
                            FROM announcements
                            WHERE offering_id = :announcement_offering_id
                        ) AS announcement_count,

                        (
                            SELECT COUNT(*)
                            FROM forums
                            WHERE offering_id = :forum_offering_id
                        ) AS forum_count,

                        (
                            SELECT COUNT(*)
                            FROM lesson_release_rules
                            WHERE offering_id = :release_offering_id
                        ) AS release_rule_count,

                        (
                            SELECT COUNT(*)
                            FROM points_ledger
                            WHERE offering_id = :points_offering_id
                        ) AS points_count,

                        (
                            SELECT COUNT(*)
                            FROM course_staff_invites
                            WHERE offering_id = :invite_offering_id
                        ) AS invite_count
                    '
                );

            $dependencyStatement->execute([
                'enrollment_offering_id' =>
                    $offeringId,

                'lesson_offering_id' =>
                    $offeringId,

                'assignment_offering_id' =>
                    $offeringId,

                'quiz_offering_id' =>
                    $offeringId,

                'announcement_offering_id' =>
                    $offeringId,

                'forum_offering_id' =>
                    $offeringId,

                'release_offering_id' =>
                    $offeringId,

                'points_offering_id' =>
                    $offeringId,

                'invite_offering_id' =>
                    $offeringId,
            ]);

            $dependencies =
                $dependencyStatement->fetch(
                    PDO::FETCH_ASSOC
                )
                ?: [];

            $labels = [
                'enrollment_count' =>
                    'student enrollment',

                'lesson_count' =>
                    'lesson',

                'assignment_count' =>
                    'assignment',

                'quiz_count' =>
                    'assessment',

                'announcement_count' =>
                    'announcement',

                'forum_count' =>
                    'course forum',

                'release_rule_count' =>
                    'lesson release rule',

                'points_count' =>
                    'points ledger record',

                'invite_count' =>
                    'pending or historical staff invite',
            ];

            $blockingItems = [];

            foreach ($labels as $key => $label) {
                $count =
                    (int) (
                        $dependencies[
                            $key
                        ]
                        ?? 0
                    );

                if ($count <= 0) {
                    continue;
                }

                $blockingItems[] =
                    number_format($count)
                    . ' '
                    . $label
                    . (
                        $count === 1
                            ? ''
                            : 's'
                    );
            }

            if ($blockingItems !== []) {
                $errors[] =
                    'This offering cannot be permanently deleted because it has '
                    . implode(
                        ', ',
                        $blockingItems
                    )
                    . '. Archive the offering instead so its academic history remains intact.';
            }
        }

        if (
            $errors === []
            && $offeringToDelete
        ) {
            try {
                $pdo->beginTransaction();

                /*
                 * Administrative relationships can be removed safely from an
                 * otherwise unused offering.
                 */
                $permissionDelete =
                    $pdo->prepare(
                        '
                        DELETE csp
                        FROM course_staff_permissions csp
                        INNER JOIN course_instructors ci
                            ON ci.id = csp.course_instructor_id
                        WHERE ci.offering_id = :offering_id
                        '
                    );

                $permissionDelete->execute([
                    'offering_id' =>
                        $offeringId,
                ]);

                foreach (
                    [
                        'course_registration_group_offerings',
                        'course_instructors',
                        'course_offering_year_groups',
                        'course_offering_learning_paths',
                    ]
                    as $tableName
                ) {
                    $deleteRelation =
                        $pdo->prepare(
                            'DELETE FROM '
                            . $tableName
                            . ' WHERE offering_id = :offering_id'
                        );

                    $deleteRelation->execute([
                        'offering_id' =>
                            $offeringId,
                    ]);
                }

                $deleteOffering =
                    $pdo->prepare(
                        '
                        DELETE FROM course_offerings
                        WHERE id = :offering_id
                        LIMIT 1
                        '
                    );

                $deleteOffering->execute([
                    'offering_id' =>
                        $offeringId,
                ]);

                if (
                    $deleteOffering->rowCount()
                    !== 1
                ) {
                    throw new RuntimeException(
                        'The course offering was not deleted.'
                    );
                }

                course_offerings_audit(
                    $pdo,
                    $currentUserId,
                    $offeringId,
                    'Permanently deleted unused course offering for '
                    . (
                        $offeringToDelete[
                            'course_title'
                        ]
                        ?? 'course'
                    ),
                    'course_offering.delete'
                );

                $pdo->commit();

                set_flash(
                    'success',
                    'Course offering deleted permanently.'
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
                    'Course offering delete error: '
                    . $exception->getMessage()
                );

                $errors[] =
                    'The course offering could not be deleted. No changes were saved.';
            }
        }
    }


    if ($action === 'create_offering') {
        if (
            !verify_csrf_token(
                $_POST['_csrf_token']
                ?? null
            )
        ) {
            $errors[] =
                'Your form session expired. Refresh the page and try again.';
        }

        $form['course_id'] =
            (string) course_offerings_post_id(
                'course_id'
            );

        $form['offering_scope'] =
            course_offerings_post_string(
                'offering_scope'
            );

        $form['school_year_id'] =
            (string) course_offerings_post_id(
                'school_year_id'
            );

        $form['year_group_id'] =
            (string) course_offerings_post_id(
                'year_group_id'
            );

        $form['course_start_date'] =
            course_offerings_post_string(
                'course_start_date'
            );

        $form['course_end_date'] =
            course_offerings_post_string(
                'course_end_date'
            );

        $form['enrollment_open_date'] =
            course_offerings_post_string(
                'enrollment_open_date'
            );

        $form['enrollment_close_date'] =
            course_offerings_post_string(
                'enrollment_close_date'
            );

        $form['pacing_mode'] =
            course_offerings_post_string(
                'pacing_mode'
            );

        $form['status'] =
            course_offerings_post_string(
                'status'
            );

        $form['instructor_ids'] =
            course_offerings_post_ids(
                'instructor_ids'
            );

        $form['ta_ids'] =
            course_offerings_post_ids(
                'ta_ids'
            );


        /*
        |--------------------------------------------------------------------------
        | Core Validation
        |--------------------------------------------------------------------------
        */

        $courseId =
            (int) $form['course_id'];

        $schoolYearId =
            (int) $form['school_year_id'];

        $yearGroupId =
            (int) $form['year_group_id'];

        if ($courseId <= 0) {
            $errors[] =
                'Choose a course.';
        }

        if (
            !in_array(
                $form['offering_scope'],
                [
                    'school_year',
                    'perpetual',
                ],
                true
            )
        ) {
            $errors[] =
                'Choose a valid offering scope.';
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

        /*
        |--------------------------------------------------------------------------
        | Normalize Pacing From Offering Scope
        |--------------------------------------------------------------------------
        |
        | The pacing radios are intentionally disabled in the browser because the
        | offering scope determines the pacing model. Disabled form controls are
        | not submitted by HTML forms, so pacing_mode may arrive empty on POST.
        | Normalize it server-side before validation instead of requiring a value
        | that the browser does not send.
        |
        */

        if ($form['offering_scope'] === 'perpetual') {
            $form['pacing_mode'] = 'self_paced';
        } elseif ($form['offering_scope'] === 'school_year') {
            $form['pacing_mode'] = 'drip';
        }

        if (
            !in_array(
                $form['pacing_mode'],
                [
                    'self_paced',
                    'drip',
                ],
                true
            )
        ) {
            $errors[] =
                'Choose a valid pacing mode.';
        }


        /*
        |--------------------------------------------------------------------------
        | Scope Rules
        |--------------------------------------------------------------------------
        |
        | Perpetual offerings are the Orientation model:
        | - not attached to a school year
        | - self-paced
        | - no course end date
        | - no grade/year-group restriction
        |
        | School-year offerings are the normal Academy class model:
        | - school year required
        | - grade/year group required
        | - drip pacing required
        | - end date required
        | - drip is based on the Academy course start date, not enrollment date
        |
        */

        if (
            $form['offering_scope']
            === 'perpetual'
        ) {
            $schoolYearId = 0;
            $yearGroupId = 0;

            $form['school_year_id'] = '';
            $form['year_group_id'] = '';
            $form['pacing_mode'] =
                'self_paced';

            $form['course_end_date'] = '';
        } else {
            if ($schoolYearId <= 0) {
                $errors[] =
                    'School Year is required for a school-year offering.';
            }

            if ($yearGroupId <= 0) {
                $errors[] =
                    'Grade Level is required for a school-year offering.';
            }

            if (
                $form['pacing_mode']
                !== 'drip'
            ) {
                $errors[] =
                    'Normal school-year courses use Drip Content so every student follows the Academy release schedule.';
            }

            if (
                $form['course_end_date']
                === ''
            ) {
                $errors[] =
                    'End Date is required for a school-year offering.';
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Date Validation
        |--------------------------------------------------------------------------
        */

        $courseStartDate =
            course_offerings_normalize_datetime(
                $form['course_start_date']
            );

        $courseEndDate =
            course_offerings_normalize_datetime(
                $form['course_end_date']
            );

        $enrollmentOpenDate =
            course_offerings_normalize_datetime(
                $form['enrollment_open_date']
            );

        $enrollmentCloseDate =
            course_offerings_normalize_datetime(
                $form['enrollment_close_date']
            );

        $dateFields = [
            [
                'raw' =>
                    $form['course_start_date'],

                'normalized' =>
                    $courseStartDate,

                'label' =>
                    'Course Start Date',
            ],
            [
                'raw' =>
                    $form['course_end_date'],

                'normalized' =>
                    $courseEndDate,

                'label' =>
                    'Course End Date',
            ],
            [
                'raw' =>
                    $form['enrollment_open_date'],

                'normalized' =>
                    $enrollmentOpenDate,

                'label' =>
                    'Enrollment Open Date',
            ],
            [
                'raw' =>
                    $form['enrollment_close_date'],

                'normalized' =>
                    $enrollmentCloseDate,

                'label' =>
                    'Enrollment Close Date',
            ],
        ];

        foreach ($dateFields as $dateField) {
            if (
                $dateField['raw'] !== ''
                && $dateField['normalized']
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


        /*
        |--------------------------------------------------------------------------
        | Validate Selected Course
        |--------------------------------------------------------------------------
        */

        $courseTitle = '';

        if ($courseId > 0) {
            $courseStatement =
                $pdo->prepare(
                    '
                    SELECT
                        id,
                        title,
                        status

                    FROM courses

                    WHERE id = :course_id
                      AND status <> "archived"

                    LIMIT 1
                    '
                );

            $courseStatement->execute([
                'course_id' =>
                    $courseId,
            ]);

            $selectedCourse =
                $courseStatement->fetch(
                    PDO::FETCH_ASSOC
                );

            if (!is_array($selectedCourse)) {
                $errors[] =
                    'The selected course is no longer available.';
            } else {
                $courseTitle =
                    trim(
                        (string) (
                            $selectedCourse['title']
                            ?? ''
                        )
                    );

                if ($courseTitle === '') {
                    $courseTitle =
                        'Untitled Course #'
                        . $courseId;
                }
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Validate School Year / Grade Level
        |--------------------------------------------------------------------------
        */

        if (
            $form['offering_scope']
            === 'school_year'
            && $schoolYearId > 0
        ) {
            $schoolYearStatement =
                $pdo->prepare(
                    '
                    SELECT 1

                    FROM school_years

                    WHERE id = :school_year_id
                      AND is_active = 1

                    LIMIT 1
                    '
                );

            $schoolYearStatement->execute([
                'school_year_id' =>
                    $schoolYearId,
            ]);

            if (
                $schoolYearStatement->fetchColumn()
                === false
            ) {
                $errors[] =
                    'The selected School Year is not available.';
            }
        }

        if (
            $form['offering_scope']
            === 'school_year'
            && $yearGroupId > 0
        ) {
            $yearGroupStatement =
                $pdo->prepare(
                    '
                    SELECT 1

                    FROM year_groups

                    WHERE id = :year_group_id
                      AND is_active = 1

                    LIMIT 1
                    '
                );

            $yearGroupStatement->execute([
                'year_group_id' =>
                    $yearGroupId,
            ]);

            if (
                $yearGroupStatement->fetchColumn()
                === false
            ) {
                $errors[] =
                    'The selected Grade Level is not available.';
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Duplicate Offering Rules
        |--------------------------------------------------------------------------
        */

        if (
            $errors === []
            && $form['offering_scope']
                === 'perpetual'
        ) {
            $duplicateStatement =
                $pdo->prepare(
                    '
                    SELECT id

                    FROM course_offerings

                    WHERE course_id = :course_id
                      AND offering_scope = "perpetual"
                      AND status <> "archived"

                    LIMIT 1
                    '
                );

            $duplicateStatement->execute([
                'course_id' =>
                    $courseId,
            ]);

            if (
                $duplicateStatement->fetchColumn()
                !== false
            ) {
                $errors[] =
                    'This course already has an active perpetual offering.';
            }
        }

        if (
            $errors === []
            && $form['offering_scope']
                === 'school_year'
        ) {
            $duplicateStatement =
                $pdo->prepare(
                    '
                    SELECT co.id

                    FROM course_offerings co

                    INNER JOIN course_offering_year_groups coyg
                        ON coyg.offering_id = co.id

                    WHERE co.course_id = :course_id
                      AND co.school_year_id = :school_year_id
                      AND co.offering_scope = "school_year"
                      AND co.status <> "archived"
                      AND coyg.year_group_id = :year_group_id

                    LIMIT 1
                    '
                );

            $duplicateStatement->execute([
                'course_id' =>
                    $courseId,

                'school_year_id' =>
                    $schoolYearId,

                'year_group_id' =>
                    $yearGroupId,
            ]);

            if (
                $duplicateStatement->fetchColumn()
                !== false
            ) {
                $errors[] =
                    'That course already has a non-archived offering for the selected School Year and Grade Level.';
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Staff Validation
        |--------------------------------------------------------------------------
        */

        $duplicateStaffIds =
            array_values(
                array_intersect(
                    $form['instructor_ids'],
                    $form['ta_ids']
                )
            );

        if ($duplicateStaffIds !== []) {
            $errors[] =
                'The same person cannot be assigned as both an Instructor and a Teaching Assistant.';
        }

        $selectedStaffIds =
            array_values(
                array_unique(
                    array_merge(
                        $form['instructor_ids'],
                        $form['ta_ids']
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

            $staffValidationStatement =
                $pdo->prepare(
                    '
                    SELECT DISTINCT
                        u.id

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

            $staffValidationStatement->execute(
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
                    $staffValidationStatement
                        ->fetchAll(
                            PDO::FETCH_COLUMN
                        )
                );

            sort($validStaffIds);

            $comparisonIds =
                $selectedStaffIds;

            sort($comparisonIds);

            if (
                $validStaffIds
                !== $comparisonIds
            ) {
                $errors[] =
                    'One or more selected staff members are no longer eligible.';
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Save
        |--------------------------------------------------------------------------
        */

        if ($errors === []) {
            try {
                $pdo->beginTransaction();

                $insertOfferingStatement =
                    $pdo->prepare(
                        '
                        INSERT INTO course_offerings (
                            course_id,
                            offering_scope,
                            school_year_id,
                            pacing_mode,
                            drip_basis,
                            course_start_date,
                            course_end_date,
                            enrollment_open_date,
                            enrollment_close_date,
                            status
                        ) VALUES (
                            :course_id,
                            :offering_scope,
                            :school_year_id,
                            :pacing_mode,
                            :drip_basis,
                            :course_start_date,
                            :course_end_date,
                            :enrollment_open_date,
                            :enrollment_close_date,
                            :status
                        )
                        '
                    );

                $insertOfferingStatement->execute([
                    'course_id' =>
                        $courseId,

                    'offering_scope' =>
                        $form['offering_scope'],

                    'school_year_id' =>
                        $form['offering_scope']
                        === 'school_year'
                            ? $schoolYearId
                            : null,

                    'pacing_mode' =>
                        $form['offering_scope']
                        === 'perpetual'
                            ? 'self_paced'
                            : 'drip',

                    'drip_basis' =>
                        $form['offering_scope']
                        === 'school_year'
                            ? 'course_start_date'
                            : null,

                    'course_start_date' =>
                        $courseStartDate,

                    'course_end_date' =>
                        $form['offering_scope']
                        === 'school_year'
                            ? $courseEndDate
                            : null,

                    'enrollment_open_date' =>
                        $enrollmentOpenDate,

                    'enrollment_close_date' =>
                        $enrollmentCloseDate,

                    'status' =>
                        $form['status'],
                ]);

                $offeringId =
                    (int) $pdo->lastInsertId();


                /*
                |----------------------------------------------------------
                | School-Year Grade Assignment
                |----------------------------------------------------------
                */

                if (
                    $form['offering_scope']
                    === 'school_year'
                ) {
                    $yearGroupInsertStatement =
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

                    $yearGroupInsertStatement->execute([
                        'offering_id' =>
                            $offeringId,

                        'year_group_id' =>
                            $yearGroupId,
                    ]);
                }


                /*
                |----------------------------------------------------------
                | Instructors / TAs
                |----------------------------------------------------------
                */

                $courseStaffInsertStatement =
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
                        $form['instructor_ids']
                    )
                    as $index =>
                    $instructorId
                ) {
                    $courseStaffInsertStatement->execute([
                        'offering_id' =>
                            $offeringId,

                        'user_id' =>
                            $instructorId,

                        'instructor_role' =>
                            $index === 0
                                ? 'primary'
                                : 'co_instructor',
                    ]);
                }

                foreach (
                    $form['ta_ids']
                    as $taId
                ) {
                    $courseStaffInsertStatement->execute([
                        'offering_id' =>
                            $offeringId,

                        'user_id' =>
                            $taId,

                        'instructor_role' =>
                            'assistant',
                    ]);
                }


                /*
                |----------------------------------------------------------
                | Audit
                |----------------------------------------------------------
                */

                course_offerings_audit(
                    $pdo,
                    $currentUserId,
                    $offeringId,
                    'Created '
                    . (
                        $form['offering_scope']
                        === 'perpetual'
                            ? 'perpetual'
                            : 'school-year'
                    )
                    . ' offering for '
                    . $courseTitle
                );

                $pdo->commit();

                set_flash(
                    'success',
                    $form['offering_scope']
                    === 'perpetual'
                        ? 'Perpetual course offering created successfully.'
                        : 'School-year course offering created successfully.'
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
                    'Course offering creation error: '
                    . $exception->getMessage()
                );

                $errors[] =
                    'The course offering could not be created. No offering data was saved.';
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| Existing Offerings
|--------------------------------------------------------------------------
*/

$offeringsStatement =
    $pdo->query(
        '
        SELECT
            co.id,
            co.course_id,
            co.offering_scope,
            co.school_year_id,
            co.pacing_mode,
            co.course_start_date,
            co.course_end_date,
            co.enrollment_open_date,
            co.enrollment_close_date,
            co.status,
            co.created_at,
            co.updated_at,

            c.title AS course_title,

            sy.name AS school_year_name,

            yg.id AS year_group_id,
            yg.name AS year_group_name,

            (
                SELECT COUNT(*)

                FROM course_enrollments ce

                WHERE ce.offering_id = co.id
                  AND ce.status = "enrolled"
            ) AS active_enrollment_count,

            (
                SELECT COUNT(*)

                FROM course_offering_lessons col

                WHERE col.offering_id = co.id
            ) AS lesson_count

        FROM course_offerings co

        INNER JOIN courses c
            ON c.id = co.course_id

        LEFT JOIN school_years sy
            ON sy.id = co.school_year_id

        LEFT JOIN course_offering_year_groups coyg
            ON coyg.offering_id = co.id

        LEFT JOIN year_groups yg
            ON yg.id = coyg.year_group_id

        ORDER BY
            CASE
                WHEN co.offering_scope = "perpetual"
                    THEN 0
                ELSE 1
            END ASC,
            sy.start_date DESC,
            yg.sort_order ASC,
            c.title ASC,
            co.id DESC
        '
    );

$offerings =
    $offeringsStatement->fetchAll(
        PDO::FETCH_ASSOC
    );

$offeringIds =
    array_map(
        static fn (array $row): int =>
            (int) (
                $row['id']
                ?? 0
            ),
        $offerings
    );

$staffByOffering = [];

if ($offeringIds !== []) {
    $placeholders =
        implode(
            ',',
            array_fill(
                0,
                count($offeringIds),
                '?'
            )
        );

    $offeringStaffStatement =
        $pdo->prepare(
            '
            SELECT
                ci.offering_id,
                ci.instructor_role,
                u.display_name,
                u.username

            FROM course_instructors ci

            INNER JOIN users u
                ON u.id = ci.user_id

            WHERE ci.offering_id IN ('
            . $placeholders
            . ')
              AND ci.is_active = 1
              AND ci.ended_at IS NULL

            ORDER BY
                ci.offering_id ASC,
                FIELD(
                    ci.instructor_role,
                    "primary",
                    "co_instructor",
                    "assistant"
                ) ASC,
                ci.assigned_at ASC,
                ci.id ASC
            '
        );

    $offeringStaffStatement->execute(
        $offeringIds
    );

    foreach (
        $offeringStaffStatement->fetchAll(
            PDO::FETCH_ASSOC
        )
        as $staffRow
    ) {
        $offeringId =
            (int) (
                $staffRow['offering_id']
                ?? 0
            );

        if ($offeringId <= 0) {
            continue;
        }

        $staffName =
            trim(
                (string) (
                    $staffRow['display_name']
                    ?? $staffRow['username']
                    ?? 'Staff'
                )
            );

        $staffByOffering[
            $offeringId
        ][] = [
            'name' =>
                $staffName,

            'role' =>
                (string) (
                    $staffRow['instructor_role']
                    ?? 'assistant'
                ),
        ];
    }
}


/*
|--------------------------------------------------------------------------
| Summary
|--------------------------------------------------------------------------
*/

$summary = [
    'total' => count($offerings),
    'perpetual' => 0,
    'school_year' => 0,
    'open' => 0,
];

foreach ($offerings as $offeringRow) {
    if (
        ($offeringRow['offering_scope']
        ?? '') === 'perpetual'
    ) {
        $summary['perpetual']++;
    } else {
        $summary['school_year']++;
    }

    if (
        ($offeringRow['status']
        ?? '') === 'open'
    ) {
        $summary['open']++;
    }
}


/*
|--------------------------------------------------------------------------
| Shared Courses Sidebar
|--------------------------------------------------------------------------
*/

$courseSidebarActive = 'offerings';

require INCLUDES_PATH . '/staff-course-sidebar.php';


/*
|--------------------------------------------------------------------------
| SEO / Header
|--------------------------------------------------------------------------
*/

$pageTitle =
    'Course Offerings | Blackthorne Academy';

$pageDescription =
    'Manage Blackthorne Academy course offerings.';

$pageCanonical =
    url('admin/course-offerings.php');

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

<main id="main-content"
    class="dashboard-page staff-dashboard-page dashboard-workspace-page courses-dashboard-page course-offerings-page">

    <section class="dashboard-hero staff-dashboard-hero" aria-labelledby="course-offerings-heading"
        <?php if ($staffHeroUrl !== ''): ?> style="--staff-dashboard-hero-image: url('<?= e($staffHeroUrl); ?>');"
        <?php endif; ?>>
        <div class="section-inner">
            <div class="dashboard-hero-inner">

                <p class="academy-overline">
                    Academic Administration
                </p>

                <h1 id="course-offerings-heading">
                    Course Offerings
                </h1>

                <p class="dashboard-hero-copy">
                    Define the version of each course students actually take:
                    permanent Orientation access or a school-year-specific
                    Academy class.
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
                            Offering Management
                        </p>

                        <h2>
                            Academic course runs
                        </h2>

                        <p>
                            Perpetual offerings are designed for always-open,
                            self-paced gateway courses such as Orientation.
                            Standard Academy classes are tied to a School Year,
                            Grade Level, end date, and Academy-wide drip
                            schedule.
                        </p>
                    </div>
                </header>


                <?php if (
                    is_string($successMessage)
                    && trim($successMessage) !== ''
                ): ?>

                <div class="form-message form-message-success" role="status">
                    <?= e($successMessage); ?>
                </div>

                <?php endif; ?>


                <?php if ($errors !== []): ?>

                <div class="form-message form-message-error" role="alert">
                    <strong>
                        The offering could not be created.
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


                <div class="dashboard-summary-grid">

                    <article class="dashboard-summary-card">
                        <span class="dashboard-summary-kicker">
                            Offerings
                        </span>

                        <strong>
                            <?= number_format($summary['total']); ?>
                        </strong>

                        <p>
                            Total course runs
                        </p>
                    </article>

                    <article class="dashboard-summary-card">
                        <span class="dashboard-summary-kicker">
                            Perpetual
                        </span>

                        <strong>
                            <?= number_format($summary['perpetual']); ?>
                        </strong>

                        <p>
                            Always-available offerings
                        </p>
                    </article>

                    <article class="dashboard-summary-card">
                        <span class="dashboard-summary-kicker">
                            School Year
                        </span>

                        <strong>
                            <?= number_format($summary['school_year']); ?>
                        </strong>

                        <p>
                            Year-specific offerings
                        </p>
                    </article>

                    <article class="dashboard-summary-card">
                        <span class="dashboard-summary-kicker">
                            Open
                        </span>

                        <strong>
                            <?= number_format($summary['open']); ?>
                        </strong>

                        <p>
                            Currently open offerings
                        </p>
                    </article>

                </div>


                <section class="forum-admin-panel">

                    <header class="forum-admin-titlebar">
                        <p class="forum-admin-step">
                            New Offering
                        </p>

                        <h2>
                            Create Course Offering
                        </h2>
                    </header>

                    <form action="<?= e(url('admin/course-offerings.php')); ?>" method="post" class="forum-admin-form"
                        data-offering-form>
                        <?= csrf_field(); ?>

                        <input type="hidden" name="action" value="create_offering">


                        <div class="form-group">
                            <label for="course-id">
                                Course
                            </label>

                            <select class="form-control" id="course-id" name="course_id" required>
                                <option value="">
                                    Choose a course
                                </option>

                                <?php foreach ($courses as $courseRow): ?>
                                <?php
                                    $courseTitle =
                                        trim(
                                            (string) (
                                                $courseRow['title']
                                                ?? ''
                                            )
                                        );

                                    if ($courseTitle === '') {
                                        $courseTitle =
                                            'Untitled Course #'
                                            . (int) $courseRow['id'];
                                    }
                                    ?>

                                <option value="<?= (int) $courseRow['id']; ?>" <?= (int) $form['course_id'] === (int) $courseRow['id']
                                            ? 'selected'
                                            : ''; ?>>
                                    <?= e($courseTitle); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>


                        <fieldset class="forum-admin-fieldset">
                            <legend>
                                Offering Scope
                            </legend>

                            <label class="forum-admin-choice">
                                <input type="radio" name="offering_scope" value="school_year" <?= $form['offering_scope'] === 'school_year'
                                        ? 'checked'
                                        : ''; ?> data-offering-scope>

                                <span>
                                    School-Year Offering
                                </span>
                            </label>

                            <p class="form-help">
                                Normal Academy class. Tied to a School Year and
                                Grade Level, uses Drip Content, and has an end date.
                            </p>

                            <label class="forum-admin-choice">
                                <input type="radio" name="offering_scope" value="perpetual" <?= $form['offering_scope'] === 'perpetual'
                                        ? 'checked'
                                        : ''; ?> data-offering-scope>

                                <span>
                                    Perpetual Offering
                                </span>
                            </label>

                            <p class="form-help">
                                Always-available self-paced course such as
                                Orientation. It is not tied to a School Year and
                                remains available until the student finishes it.
                            </p>
                        </fieldset>


                        <div data-school-year-offering-fields <?= $form['offering_scope'] === 'school_year'
                                ? ''
                                : 'hidden'; ?>>

                            <div class="forum-admin-form-grid">

                                <div class="form-group">
                                    <label for="school-year-id">
                                        School Year
                                    </label>

                                    <select class="form-control" id="school-year-id" name="school_year_id">
                                        <option value="">
                                            Choose a school year
                                        </option>

                                        <?php foreach ($schoolYears as $schoolYear): ?>
                                        <option value="<?= (int) $schoolYear['id']; ?>" <?= (int) $form['school_year_id'] === (int) $schoolYear['id']
                                                    ? 'selected'
                                                    : ''; ?>>
                                            <?= e((string) $schoolYear['name']); ?>
                                            <?= (int) ($schoolYear['is_current'] ?? 0) === 1
                                                    ? ' — Current'
                                                    : ''; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>


                                <div class="form-group">
                                    <label for="year-group-id">
                                        Grade Level
                                    </label>

                                    <select class="form-control" id="year-group-id" name="year_group_id">
                                        <option value="">
                                            Choose a grade level
                                        </option>

                                        <?php foreach ($yearGroups as $yearGroup): ?>
                                        <option value="<?= (int) $yearGroup['id']; ?>" <?= (int) $form['year_group_id'] === (int) $yearGroup['id']
                                                    ? 'selected'
                                                    : ''; ?>>
                                            <?= e((string) $yearGroup['name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                            </div>

                        </div>


                        <fieldset class="forum-admin-fieldset">
                            <legend>
                                Course Type
                            </legend>

                            <label class="forum-admin-choice">
                                <input type="radio" name="pacing_mode" value="self_paced" <?= $form['pacing_mode'] === 'self_paced'
                                        ? 'checked'
                                        : ''; ?> data-pacing-mode>

                                <span>
                                    Self-paced
                                </span>
                            </label>

                            <label class="forum-admin-choice">
                                <input type="radio" name="pacing_mode" value="drip" <?= $form['pacing_mode'] === 'drip'
                                        ? 'checked'
                                        : ''; ?> data-pacing-mode>

                                <span>
                                    Drip Content
                                </span>
                            </label>

                            <p class="form-help">
                                Perpetual offerings are automatically saved as
                                Self-paced. School-year offerings are automatically
                                saved as Drip Content using the course start date,
                                so late students receive all lessons already
                                released to the class.
                            </p>
                        </fieldset>


                        <div class="forum-admin-form-grid">

                            <div class="form-group">
                                <label for="course-start-date">
                                    Course Start Date
                                </label>

                                <input class="form-control" type="datetime-local" id="course-start-date"
                                    name="course_start_date" value="<?= e($form['course_start_date']); ?>">
                            </div>

                            <div class="form-group" data-school-year-end-field <?= $form['offering_scope'] === 'school_year'
                                    ? ''
                                    : 'hidden'; ?>>
                                <label for="course-end-date">
                                    Course End Date
                                </label>

                                <input class="form-control" type="datetime-local" id="course-end-date"
                                    name="course_end_date" value="<?= e($form['course_end_date']); ?>">
                            </div>

                        </div>


                        <div class="forum-admin-form-grid">

                            <div class="form-group">
                                <label for="enrollment-open-date">
                                    Enrollment Opens
                                </label>

                                <input class="form-control" type="datetime-local" id="enrollment-open-date"
                                    name="enrollment_open_date" value="<?= e($form['enrollment_open_date']); ?>">

                                <p class="form-help">
                                    Optional. Leave blank to avoid a separate
                                    opening-date restriction.
                                </p>
                            </div>

                            <div class="form-group">
                                <label for="enrollment-close-date">
                                    Enrollment Closes
                                </label>

                                <input class="form-control" type="datetime-local" id="enrollment-close-date"
                                    name="enrollment_close_date" value="<?= e($form['enrollment_close_date']); ?>">

                                <p class="form-help">
                                    Optional. For Orientation, leave this blank
                                    so the gateway course remains available.
                                </p>
                            </div>

                        </div>


                        <div class="forum-admin-form-grid">

                            <div class="form-group">
                                <label for="instructor-ids">
                                    Instructor(s)
                                </label>

                                <select class="form-control" id="instructor-ids" name="instructor_ids[]" multiple
                                    size="8">
                                    <?php foreach ($availableStaff as $staffMember): ?>
                                    <?php
                                        $staffName =
                                            trim(
                                                (string) (
                                                    $staffMember['display_name']
                                                    ?? $staffMember['username']
                                                    ?? 'Staff'
                                                )
                                            );
                                        ?>

                                    <option value="<?= (int) $staffMember['id']; ?>" <?= in_array(
                                                (int) $staffMember['id'],
                                                $form['instructor_ids'],
                                                true
                                            )
                                                ? 'selected'
                                                : ''; ?>>
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

                                <select class="form-control" id="ta-ids" name="ta_ids[]" multiple size="8">
                                    <?php foreach ($availableStaff as $staffMember): ?>
                                    <?php
                                        $staffName =
                                            trim(
                                                (string) (
                                                    $staffMember['display_name']
                                                    ?? $staffMember['username']
                                                    ?? 'Staff'
                                                )
                                            );
                                        ?>

                                    <option value="<?= (int) $staffMember['id']; ?>" <?= in_array(
                                                (int) $staffMember['id'],
                                                $form['ta_ids'],
                                                true
                                            )
                                                ? 'selected'
                                                : ''; ?>>
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

                            <select class="form-control" id="offering-status" name="status">
                                <?php
                                $offeringStatuses = [
                                    'draft' => 'Draft',
                                    'open' => 'Open',
                                    'closed' => 'Closed',
                                    'completed' => 'Completed',
                                    'archived' => 'Archived',
                                ];
                                ?>

                                <?php foreach ($offeringStatuses as $statusValue => $statusLabel): ?>
                                <option value="<?= e($statusValue); ?>" <?= $form['status'] === $statusValue
                                            ? 'selected'
                                            : ''; ?>>
                                    <?= e($statusLabel); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>


                        <div class="forum-admin-actions">
                            <button type="submit" class="button button-primary">
                                Create Offering
                            </button>
                        </div>

                    </form>

                </section>


                <section class="dashboard-workspace-panel">

                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">
                                Existing Offerings
                            </p>

                            <h3>
                                Course Runs
                            </h3>
                        </div>
                    </div>

                    <div class="dashboard-panel-body">

                        <?php if ($offerings === []): ?>

                        <p>
                            No course offerings have been created yet.
                            Orientation should use a Perpetual Offering.
                            Standard Academy courses should use
                            School-Year Offerings.
                        </p>

                        <?php else: ?>

                        <div class="dashboard-placeholder-list">

                            <?php foreach ($offerings as $offeringRow): ?>
                            <?php
                                    $rowOfferingId =
                                        (int) (
                                            $offeringRow['id']
                                            ?? 0
                                        );

                                    $rowCourseTitle =
                                        trim(
                                            (string) (
                                                $offeringRow['course_title']
                                                ?? ''
                                            )
                                        );

                                    if ($rowCourseTitle === '') {
                                        $rowCourseTitle =
                                            'Untitled Course';
                                    }

                                    $isPerpetual =
                                        (
                                            $offeringRow['offering_scope']
                                            ?? ''
                                        ) === 'perpetual';

                                    $scopeLabel =
                                        $isPerpetual
                                            ? 'Perpetual'
                                            : (
                                                trim(
                                                    (string) (
                                                        $offeringRow[
                                                            'school_year_name'
                                                        ]
                                                        ?? ''
                                                    )
                                                )
                                                !== ''
                                                    ? (string) $offeringRow[
                                                        'school_year_name'
                                                    ]
                                                    : 'School Year'
                                            );

                                    $yearGroupName =
                                        trim(
                                            (string) (
                                                $offeringRow[
                                                    'year_group_name'
                                                ]
                                                ?? ''
                                            )
                                        );

                                    $rowStaff =
                                        $staffByOffering[
                                            $rowOfferingId
                                        ]
                                        ?? [];

                                    $staffLabels = [];

                                    foreach ($rowStaff as $staffItem) {
                                        $roleLabel =
                                            match (
                                                $staffItem['role']
                                                ?? ''
                                            ) {
                                                'primary' =>
                                                    'Instructor',

                                                'co_instructor' =>
                                                    'Co-Instructor',

                                                'assistant' =>
                                                    'TA',

                                                default =>
                                                    'Staff',
                                            };

                                        $staffLabels[] =
                                            $staffItem['name']
                                            . ' ('
                                            . $roleLabel
                                            . ')';
                                    }
                                    ?>

                            <span>
                                <strong>
                                    <?= e($rowCourseTitle); ?>
                                </strong>

                                · <?= e($scopeLabel); ?>

                                <?php if (
                                            !$isPerpetual
                                            && $yearGroupName !== ''
                                        ): ?>
                                · <?= e($yearGroupName); ?>
                                <?php endif; ?>

                                · <?= e(
                                            ucfirst(
                                                (string) (
                                                    $offeringRow['status']
                                                    ?? 'draft'
                                                )
                                            )
                                        ); ?>

                                · <?= $isPerpetual
                                            ? 'Self-paced'
                                            : 'Drip Content'; ?>

                                <?php if (!$isPerpetual): ?>
                                · Ends
                                <?= e(
                                                course_offerings_format_date(
                                                    $offeringRow[
                                                        'course_end_date'
                                                    ]
                                                    ?? null
                                                )
                                            ); ?>
                                <?php endif; ?>

                                · <?= number_format(
                                            (int) (
                                                $offeringRow[
                                                    'lesson_count'
                                                ]
                                                ?? 0
                                            )
                                        ); ?>
                                lessons

                                · <?= number_format(
                                            (int) (
                                                $offeringRow[
                                                    'active_enrollment_count'
                                                ]
                                                ?? 0
                                            )
                                        ); ?>
                                enrolled

                                <?php if ($staffLabels !== []): ?>
                                · <?= e(
                                                implode(
                                                    ', ',
                                                    $staffLabels
                                                )
                                            ); ?>
                                <?php endif; ?>

                                ·
                                <a href="<?= e(
                                                url(
                                                    'admin/course-offering-edit.php?offering='
                                                    . $rowOfferingId
                                                )
                                            ); ?>">
                                    Edit
                                </a>

                                ·
                                <form action="<?= e(
                                                url(
                                                    'admin/course-offerings.php'
                                                )
                                            ); ?>" method="post" class="offering-inline-action"
                                    onsubmit="return confirm('Permanently delete this course offering? This is only allowed for unused offerings and cannot be undone.');">
                                    <?= csrf_field(); ?>

                                    <input type="hidden" name="action" value="delete_offering">

                                    <input type="hidden" name="offering_id" value="<?= $rowOfferingId; ?>">

                                    <button type="submit" class="offering-delete-link">
                                        Delete
                                    </button>
                                </form>
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


<script>
    (function() {
        'use strict';

        var scopeInputs =
            document.querySelectorAll(
                '[data-offering-scope]'
            );

        var schoolYearFields =
            document.querySelector(
                '[data-school-year-offering-fields]'
            );

        var endField =
            document.querySelector(
                '[data-school-year-end-field]'
            );

        var pacingInputs =
            document.querySelectorAll(
                '[data-pacing-mode]'
            );

        if (!scopeInputs.length) {
            return;
        }

        function setPacing(value) {
            pacingInputs.forEach(function(input) {
                input.checked =
                    input.value === value;

                input.disabled = true;
            });
        }

        function syncOfferingScope() {
            var selected =
                document.querySelector(
                    '[data-offering-scope]:checked'
                );

            var scope =
                selected ?
                selected.value :
                'school_year';

            var isSchoolYear =
                scope === 'school_year';

            if (schoolYearFields) {
                schoolYearFields.hidden = !isSchoolYear;
            }

            if (endField) {
                endField.hidden = !isSchoolYear;
            }

            setPacing(
                isSchoolYear ?
                'drip' :
                'self_paced'
            );
        }

        scopeInputs.forEach(function(input) {
            input.addEventListener(
                'change',
                syncOfferingScope
            );
        });

        syncOfferingScope();
    })();

</script>


<style>
    .offering-inline-action {
        display: inline;
        margin: 0;
        padding: 0;
    }

    .offering-delete-link {
        appearance: none;
        border: 0;
        padding: 0;
        background: transparent;
        color: #d96a72;
        font: inherit;
        text-decoration: none;
        cursor: pointer;
    }

    .offering-delete-link:hover,
    .offering-delete-link:focus-visible {
        color: #f08a91;
        text-decoration: underline;
    }

</style>

<?php

require INCLUDES_PATH . '/footer.php';
