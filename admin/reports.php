<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

require_active_account();


/*
|--------------------------------------------------------------------------
| Access
|--------------------------------------------------------------------------
*/

$canViewReports =
    user_can_any([
        'moderation.reports.view',
        'moderation.reports.review',
        'moderation.reports.resolve',
        'moderation.reports.dismiss',
    ]);

if (!$canViewReports) {
    http_response_code(403);
    exit('You do not have permission to access moderation reports.');
}

$canReviewReports =
    user_can('moderation.reports.review');

$canResolveReports =
    user_can('moderation.reports.resolve');

$canDismissReports =
    user_can('moderation.reports.dismiss');

$currentUserId =
    (int) (current_user_id() ?? 0);


/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function admin_reports_query_value(
    string $key,
    string $default = ''
): string {
    $value =
        $_GET[$key]
        ?? $default;

    if (!is_scalar($value)) {
        return $default;
    }

    return trim((string) $value);
}


function admin_reports_post_id(
    string $key
): int {
    $value =
        $_POST[$key]
        ?? '';

    if (
        !is_scalar($value)
        || !ctype_digit((string) $value)
    ) {
        return 0;
    }

    return max(
        0,
        (int) $value
    );
}


function admin_reports_text_length(
    string $value
): int {
    return function_exists('mb_strlen')
        ? mb_strlen(
            $value,
            'UTF-8'
        )
        : strlen($value);
}


function admin_reports_excerpt(
    string $value,
    int $limit = 280
): string {
    $value =
        trim(
            preg_replace(
                '/\s+/u',
                ' ',
                strip_tags($value)
            )
            ?? ''
        );

    if ($value === '') {
        return '';
    }

    $length =
        function_exists('mb_strlen')
            ? mb_strlen(
                $value,
                'UTF-8'
            )
            : strlen($value);

    if ($length <= $limit) {
        return $value;
    }

    $excerpt =
        function_exists('mb_substr')
            ? mb_substr(
                $value,
                0,
                max(0, $limit - 1),
                'UTF-8'
            )
            : substr(
                $value,
                0,
                max(0, $limit - 1)
            );

    return rtrim($excerpt) . '…';
}


function admin_reports_datetime(
    ?string $value
): string {
    $value =
        trim((string) $value);

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


function admin_reports_return_url(
    string $status,
    string $priority
): string {
    $query = [];

    if ($status !== '') {
        $query['status'] =
            $status;
    }

    if ($priority !== '') {
        $query['priority'] =
            $priority;
    }

    $url =
        url('admin/reports.php');

    if ($query !== []) {
        $url .=
            '?'
            . http_build_query($query);
    }

    return $url;
}


/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$validStatuses = [
    'all',
    'open',
    'reviewing',
    'resolved',
    'dismissed',
];

$validPriorities = [
    'all',
    'low',
    'normal',
    'high',
    'urgent',
];

$statusFilter =
    admin_reports_query_value(
        'status',
        'open'
    );

$priorityFilter =
    admin_reports_query_value(
        'priority',
        'all'
    );

if (
    !in_array(
        $statusFilter,
        $validStatuses,
        true
    )
) {
    $statusFilter =
        'open';
}

if (
    !in_array(
        $priorityFilter,
        $validPriorities,
        true
    )
) {
    $priorityFilter =
        'all';
}


/*
|--------------------------------------------------------------------------
| Form Processing
|--------------------------------------------------------------------------
*/

$errors = [];

if (is_post()) {
    require_valid_csrf();

    $formAction =
        post_value('form_action');

    $reportId =
        admin_reports_post_id(
            'report_id'
        );

    $returnStatus =
        post_value(
            'return_status',
            $statusFilter
        );

    $returnPriority =
        post_value(
            'return_priority',
            $priorityFilter
        );

    if (
        !in_array(
            $returnStatus,
            $validStatuses,
            true
        )
    ) {
        $returnStatus =
            'open';
    }

    if (
        !in_array(
            $returnPriority,
            $validPriorities,
            true
        )
    ) {
        $returnPriority =
            'all';
    }

    if ($reportId <= 0) {
        $errors[] =
            'The selected report is invalid.';
    }


    $report = null;

    if ($errors === []) {
        $reportStatement =
            $pdo->prepare(
                'SELECT
                    id,
                    status,
                    priority,
                    reviewed_by,
                    reviewed_at,
                    resolution_notes
                 FROM forum_reports
                 WHERE id = :report_id
                 LIMIT 1'
            );

        $reportStatement->execute([
            'report_id' =>
                $reportId,
        ]);

        $report =
            $reportStatement->fetch(
                PDO::FETCH_ASSOC
            )
            ?: null;

        if ($report === null) {
            $errors[] =
                'The selected report could not be found.';
        }
    }


    if (
        $errors === []
        && $report !== null
    ) {
        try {
            $pdo->beginTransaction();


            /*
            |--------------------------------------------------------------
            | Start / Take Review
            |--------------------------------------------------------------
            */

            if ($formAction === 'start_review') {
                if (!$canReviewReports) {
                    throw new RuntimeException(
                        'You do not have permission to review reports.'
                    );
                }

                if (
                    in_array(
                        (string) $report['status'],
                        [
                            'resolved',
                            'dismissed',
                        ],
                        true
                    )
                ) {
                    throw new RuntimeException(
                        'Closed reports cannot be moved back into review from this page.'
                    );
                }

                $statement =
                    $pdo->prepare(
                        'UPDATE forum_reports
                         SET
                            status = "reviewing",
                            reviewed_by = :reviewed_by,
                            reviewed_at = NOW()
                         WHERE id = :report_id'
                    );

                $statement->execute([
                    'reviewed_by' =>
                        $currentUserId,
                    'report_id' =>
                        $reportId,
                ]);

                set_flash(
                    'success',
                    'Report #'
                    . $reportId
                    . ' is now under review.'
                );
            }


            /*
            |--------------------------------------------------------------
            | Priority
            |--------------------------------------------------------------
            */

            elseif ($formAction === 'update_priority') {
                if (!$canReviewReports) {
                    throw new RuntimeException(
                        'You do not have permission to update report priority.'
                    );
                }

                $priority =
                    post_value(
                        'priority',
                        'normal'
                    );

                if (
                    !in_array(
                        $priority,
                        [
                            'low',
                            'normal',
                            'high',
                            'urgent',
                        ],
                        true
                    )
                ) {
                    throw new RuntimeException(
                        'Choose a valid priority.'
                    );
                }

                $statement =
                    $pdo->prepare(
                        'UPDATE forum_reports
                         SET priority = :priority
                         WHERE id = :report_id'
                    );

                $statement->execute([
                    'priority' =>
                        $priority,
                    'report_id' =>
                        $reportId,
                ]);

                set_flash(
                    'success',
                    'Report #'
                    . $reportId
                    . ' priority updated.'
                );
            }


            /*
            |--------------------------------------------------------------
            | Resolve
            |--------------------------------------------------------------
            */

            elseif ($formAction === 'resolve_report') {
                if (!$canResolveReports) {
                    throw new RuntimeException(
                        'You do not have permission to resolve reports.'
                    );
                }

                $resolutionNotes =
                    post_value(
                        'resolution_notes'
                    );

                if (
                    admin_reports_text_length(
                        $resolutionNotes
                    ) > 10000
                ) {
                    throw new RuntimeException(
                        'Resolution notes are too long.'
                    );
                }

                $statement =
                    $pdo->prepare(
                        'UPDATE forum_reports
                         SET
                            status = "resolved",
                            reviewed_by = :reviewed_by,
                            reviewed_at = NOW(),
                            resolution_notes = :resolution_notes
                         WHERE id = :report_id'
                    );

                $statement->execute([
                    'reviewed_by' =>
                        $currentUserId,
                    'resolution_notes' =>
                        $resolutionNotes !== ''
                            ? $resolutionNotes
                            : null,
                    'report_id' =>
                        $reportId,
                ]);


                /*
                 * A resolved report no longer needs outstanding bell alerts.
                 */
                $notificationStatement =
                    $pdo->prepare(
                        'UPDATE notifications
                         SET
                            is_read = 1,
                            read_at = COALESCE(
                                read_at,
                                NOW()
                            )
                         WHERE related_entity_type = "forum_report"
                           AND related_entity_id = :report_id
                           AND notification_type = "moderation"
                           AND is_read = 0'
                    );

                $notificationStatement->execute([
                    'report_id' =>
                        $reportId,
                ]);

                set_flash(
                    'success',
                    'Report #'
                    . $reportId
                    . ' has been resolved.'
                );
            }


            /*
            |--------------------------------------------------------------
            | Dismiss
            |--------------------------------------------------------------
            */

            elseif ($formAction === 'dismiss_report') {
                if (!$canDismissReports) {
                    throw new RuntimeException(
                        'You do not have permission to dismiss reports.'
                    );
                }

                $resolutionNotes =
                    post_value(
                        'resolution_notes'
                    );

                if (
                    admin_reports_text_length(
                        $resolutionNotes
                    ) > 10000
                ) {
                    throw new RuntimeException(
                        'Resolution notes are too long.'
                    );
                }

                $statement =
                    $pdo->prepare(
                        'UPDATE forum_reports
                         SET
                            status = "dismissed",
                            reviewed_by = :reviewed_by,
                            reviewed_at = NOW(),
                            resolution_notes = :resolution_notes
                         WHERE id = :report_id'
                    );

                $statement->execute([
                    'reviewed_by' =>
                        $currentUserId,
                    'resolution_notes' =>
                        $resolutionNotes !== ''
                            ? $resolutionNotes
                            : null,
                    'report_id' =>
                        $reportId,
                ]);


                $notificationStatement =
                    $pdo->prepare(
                        'UPDATE notifications
                         SET
                            is_read = 1,
                            read_at = COALESCE(
                                read_at,
                                NOW()
                            )
                         WHERE related_entity_type = "forum_report"
                           AND related_entity_id = :report_id
                           AND notification_type = "moderation"
                           AND is_read = 0'
                    );

                $notificationStatement->execute([
                    'report_id' =>
                        $reportId,
                ]);

                set_flash(
                    'success',
                    'Report #'
                    . $reportId
                    . ' has been dismissed.'
                );
            }


            else {
                throw new RuntimeException(
                    'Unknown report action.'
                );
            }


            $pdo->commit();

            redirect(
                admin_reports_return_url(
                    $returnStatus,
                    $returnPriority
                )
            );

        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $message =
                $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'The report could not be updated. Please try again.';

            $errors[] =
                $message;

            if (!($exception instanceof RuntimeException)) {
                error_log(
                    'Blackthorne report moderation error: '
                    . $exception->getMessage()
                );
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| Status Counts
|--------------------------------------------------------------------------
*/

$countStatement =
    $pdo->query(
        'SELECT
            status,
            COUNT(*) AS total
         FROM forum_reports
         GROUP BY status'
    );

$statusCounts = [
    'open' => 0,
    'reviewing' => 0,
    'resolved' => 0,
    'dismissed' => 0,
];

foreach (
    $countStatement->fetchAll(
        PDO::FETCH_ASSOC
    )
    as $row
) {
    $status =
        (string) $row['status'];

    if (
        array_key_exists(
            $status,
            $statusCounts
        )
    ) {
        $statusCounts[$status] =
            (int) $row['total'];
    }
}


/*
|--------------------------------------------------------------------------
| Report Listing
|--------------------------------------------------------------------------
*/

$sql =
    'SELECT
        fr.id,
        fr.reported_by,
        fr.thread_id,
        fr.thread_title_snapshot,
        fr.post_id,
        fr.post_content_snapshot,
        fr.reason,
        fr.details,
        fr.status,
        fr.priority,
        fr.reviewed_by,
        fr.reviewed_at,
        fr.resolution_notes,
        fr.created_at,
        fr.updated_at,

        reporter.username
            AS reporter_username,

        reporter.display_name
            AS reporter_display_name,

        reviewer.username
            AS reviewer_username,

        reviewer.display_name
            AS reviewer_display_name,

        ft.title
            AS current_thread_title,

        fp.is_deleted
            AS current_post_deleted

     FROM forum_reports fr

     INNER JOIN users reporter
        ON reporter.id = fr.reported_by

     LEFT JOIN users reviewer
        ON reviewer.id = fr.reviewed_by

     LEFT JOIN forum_threads ft
        ON ft.id = fr.thread_id

     LEFT JOIN forum_posts fp
        ON fp.id = fr.post_id

     WHERE 1 = 1';

$params = [];

if ($statusFilter !== 'all') {
    $sql .=
        ' AND fr.status = :status';

    $params['status'] =
        $statusFilter;
}

if ($priorityFilter !== 'all') {
    $sql .=
        ' AND fr.priority = :priority';

    $params['priority'] =
        $priorityFilter;
}

$sql .=
    ' ORDER BY
        CASE fr.priority
            WHEN "urgent" THEN 1
            WHEN "high" THEN 2
            WHEN "normal" THEN 3
            WHEN "low" THEN 4
            ELSE 5
        END ASC,
        fr.created_at ASC,
        fr.id ASC
      LIMIT 250';

$reportsStatement =
    $pdo->prepare($sql);

$reportsStatement->execute(
    $params
);

$reports =
    $reportsStatement->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Flash + Page Metadata
|--------------------------------------------------------------------------
*/

$successMessage =
    get_flash('success');

$pageTitle =
    'Reports & Moderation | Blackthorne Academy';

$pageDescription =
    'Review and process Blackthorne Academy forum reports.';

$pageCanonical =
    url('admin/reports.php');

$robots =
    'noindex, nofollow';

require
    INCLUDES_PATH
    . '/header.php';

?>

<style>
    .reports-admin-page .reports-toolbar {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 1rem 1.5rem;
        align-items: end;
        margin-bottom: 1.5rem;
    }

    .reports-admin-page .reports-filter-form {
        display: flex;
        flex-wrap: wrap;
        gap: 0.85rem;
        align-items: end;
    }

    .reports-admin-page .reports-filter-form .form-group {
        margin: 0;
        min-width: 170px;
    }

    /* Blackthorne form controls for the moderation workspace */
    .reports-admin-page select.form-control,
    .reports-admin-page textarea.form-control,
    .reports-admin-page input.form-control {
        color: #eee7ef;
        background: #160d19;
        border: 1px solid rgba(150, 113, 147, 0.55);
        border-radius: 5px;
        box-shadow: none;
    }

    .reports-admin-page select.form-control:hover,
    .reports-admin-page textarea.form-control:hover,
    .reports-admin-page input.form-control:hover {
        border-color: rgba(212, 178, 91, 0.55);
    }

    .reports-admin-page select.form-control:focus,
    .reports-admin-page textarea.form-control:focus,
    .reports-admin-page input.form-control:focus {
        color: #fff8ef;
        background: #1c1020;
        border-color: #d4b25b;
        outline: 2px solid rgba(212, 178, 91, 0.18);
        outline-offset: 2px;
        box-shadow: none;
    }

    .reports-admin-page select.form-control {
        color-scheme: dark;
    }

    .reports-admin-page select.form-control option {
        color: #eee7ef;
        background: #160d19;
    }

    .reports-admin-page textarea.form-control::placeholder,
    .reports-admin-page input.form-control::placeholder {
        color: rgba(238, 231, 239, 0.5);
    }

    .reports-admin-page .reports-filter-form label,
    .reports-admin-page .report-action-form label {
        color: #eee7ef;
    }

    .reports-admin-page .report-action-form {
        border-color: rgba(150, 113, 147, 0.26);
        background: rgba(30, 16, 34, 0.42);
    }

    .reports-admin-page .report-reviewer {
        border-color: rgba(150, 113, 147, 0.26);
        background: rgba(30, 16, 34, 0.32);
    }

    .reports-admin-page .report-snapshot {
        background: rgba(40, 23, 43, 0.36);
    }

    .reports-admin-page .reports-count-pill {
        background: rgba(31, 17, 35, 0.6);
    }

    .reports-admin-page .reports-counts {
        display: flex;
        flex-wrap: wrap;
        gap: 0.55rem;
        justify-content: flex-end;
    }

    .reports-admin-page .reports-count-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        min-height: 2.15rem;
        padding: 0.4rem 0.7rem;
        border: 1px solid rgba(212, 178, 91, 0.3);
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.025);
        font-size: 0.8rem;
    }

    .reports-admin-page .reports-count-pill strong {
        color: var(--color-gold, #d4b25b);
    }

    .reports-admin-page .reports-list {
        display: grid;
        gap: 1rem;
    }

    .reports-admin-page .report-card {
        border: 1px solid rgba(212, 178, 91, 0.22);
        background: rgba(10, 8, 13, 0.62);
    }

    .reports-admin-page .report-card-header {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 1rem;
        align-items: start;
        padding: 1rem 1.15rem;
        border-bottom: 1px solid rgba(212, 178, 91, 0.16);
    }

    .reports-admin-page .report-card-header h2 {
        margin: 0 0 0.35rem;
        font-size: 1.08rem;
        font-weight: 500;
    }

    .reports-admin-page .report-card-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 0.35rem 0.75rem;
        margin: 0;
        font-size: 0.82rem;
        opacity: 0.82;
    }

    .reports-admin-page .report-badges {
        display: flex;
        flex-wrap: wrap;
        gap: 0.45rem;
        justify-content: flex-end;
    }

    .reports-admin-page .report-badge {
        display: inline-flex;
        align-items: center;
        min-height: 1.8rem;
        padding: 0.25rem 0.55rem;
        border: 1px solid rgba(255, 255, 255, 0.12);
        border-radius: 999px;
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.07em;
    }

    .reports-admin-page .report-badge.priority-urgent {
        border-color: rgba(255, 90, 90, 0.65);
    }

    .reports-admin-page .report-badge.priority-high {
        border-color: rgba(232, 145, 77, 0.62);
    }

    .reports-admin-page .report-badge.status-reviewing {
        border-color: rgba(166, 129, 183, 0.7);
    }

    .reports-admin-page .report-badge.status-resolved {
        border-color: rgba(112, 175, 127, 0.58);
    }

    .reports-admin-page .report-card-body {
        display: grid;
        grid-template-columns: minmax(0, 1.4fr) minmax(260px, 0.8fr);
        gap: 1.2rem;
        padding: 1.15rem;
    }

    .reports-admin-page .report-section {
        min-width: 0;
    }

    .reports-admin-page .report-section h3 {
        margin: 0 0 0.45rem;
        font-size: 0.88rem;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--color-gold, #d4b25b);
    }

    .reports-admin-page .report-section p {
        margin: 0 0 0.8rem;
    }

    .reports-admin-page .report-snapshot {
        max-height: 190px;
        overflow: auto;
        padding: 0.8rem;
        border-left: 2px solid rgba(212, 178, 91, 0.55);
        background: rgba(255, 255, 255, 0.025);
        line-height: 1.55;
        white-space: pre-wrap;
    }

    .reports-admin-page .report-actions {
        display: grid;
        gap: 0.75rem;
    }

    .reports-admin-page .report-action-form {
        padding: 0.85rem;
        border: 1px solid rgba(255, 255, 255, 0.08);
        background: rgba(255, 255, 255, 0.018);
    }

    .reports-admin-page .report-action-form .form-group {
        margin-bottom: 0.7rem;
    }

    .reports-admin-page .report-action-form textarea {
        min-height: 88px;
    }

    .reports-admin-page .report-action-buttons {
        display: flex;
        flex-wrap: wrap;
        gap: 0.55rem;
    }

    .reports-admin-page .report-reviewer {
        padding: 0.75rem 0.85rem;
        border: 1px solid rgba(255, 255, 255, 0.08);
        font-size: 0.82rem;
    }

    .reports-admin-page .report-empty {
        padding: 2rem 1.2rem;
        text-align: center;
        border: 1px solid rgba(212, 178, 91, 0.2);
        background: rgba(255, 255, 255, 0.018);
    }

    @media (max-width: 900px) {

        .reports-admin-page .reports-toolbar,
        .reports-admin-page .report-card-body {
            grid-template-columns: 1fr;
        }

        .reports-admin-page .reports-counts {
            justify-content: flex-start;
        }
    }

    @media (max-width: 640px) {
        .reports-admin-page .report-card-header {
            grid-template-columns: 1fr;
        }

        .reports-admin-page .report-badges {
            justify-content: flex-start;
        }

        .reports-admin-page .reports-filter-form {
            display: grid;
            grid-template-columns: 1fr;
        }

        .reports-admin-page .reports-filter-form .form-group {
            min-width: 0;
        }
    }

</style>

<main id="main-content" class="forum-admin-page reports-admin-page">

    <section class="forum-admin-hero" aria-labelledby="reports-heading">

        <div class="section-inner">

            <p class="academy-overline">
                Moderation
            </p>

            <h1 id="reports-heading">
                Reports &amp; Moderation
            </h1>

            <p>
                Review reported forum content, track investigation status,
                and close reports when moderation is complete.
            </p>

            <div class="forum-admin-edit-actions">

                <?php if (user_can('moderation.history.view')): ?>

                <a href="<?= e(url('admin/moderation-history.php')); ?>" class="button button-secondary">
                    Moderation History
                </a>

                <?php endif; ?>

                <a href="<?= e(url('staff-dashboard.php')); ?>" class="button button-secondary">
                    Staff Dashboard
                </a>

                <a href="<?= e(url('forums.php')); ?>" class="button button-secondary">
                    Forums
                </a>

            </div>

        </div>

    </section>


    <section class="forum-admin-content">

        <div class="section-inner">

            <?php if ($successMessage !== null): ?>

            <div class="form-message form-message-success" role="status">
                <?= e($successMessage); ?>
            </div>

            <?php endif; ?>


            <?php if ($errors !== []): ?>

            <div class="form-message form-message-error" role="alert">

                <ul>

                    <?php foreach ($errors as $error): ?>

                    <li>
                        <?= e($error); ?>
                    </li>

                    <?php endforeach; ?>

                </ul>

            </div>

            <?php endif; ?>


            <div class="reports-toolbar">

                <form action="<?= e(url('admin/reports.php')); ?>" method="get" class="reports-filter-form">

                    <div class="form-group">

                        <label for="report-status-filter">
                            Status
                        </label>

                        <select id="report-status-filter" name="status" class="form-control">
                            <option value="all" <?= $statusFilter === 'all' ? 'selected' : ''; ?>>
                                All Statuses
                            </option>
                            <option value="open" <?= $statusFilter === 'open' ? 'selected' : ''; ?>>
                                Open
                            </option>
                            <option value="reviewing" <?= $statusFilter === 'reviewing' ? 'selected' : ''; ?>>
                                Reviewing
                            </option>
                            <option value="resolved" <?= $statusFilter === 'resolved' ? 'selected' : ''; ?>>
                                Resolved
                            </option>
                            <option value="dismissed" <?= $statusFilter === 'dismissed' ? 'selected' : ''; ?>>
                                Dismissed
                            </option>
                        </select>

                    </div>


                    <div class="form-group">

                        <label for="report-priority-filter">
                            Priority
                        </label>

                        <select id="report-priority-filter" name="priority" class="form-control">
                            <option value="all" <?= $priorityFilter === 'all' ? 'selected' : ''; ?>>
                                All Priorities
                            </option>
                            <option value="urgent" <?= $priorityFilter === 'urgent' ? 'selected' : ''; ?>>
                                Urgent
                            </option>
                            <option value="high" <?= $priorityFilter === 'high' ? 'selected' : ''; ?>>
                                High
                            </option>
                            <option value="normal" <?= $priorityFilter === 'normal' ? 'selected' : ''; ?>>
                                Normal
                            </option>
                            <option value="low" <?= $priorityFilter === 'low' ? 'selected' : ''; ?>>
                                Low
                            </option>
                        </select>

                    </div>


                    <button type="submit" class="button button-primary">
                        Apply Filters
                    </button>

                </form>


                <div class="reports-counts" aria-label="Report status counts">

                    <span class="reports-count-pill">
                        Open
                        <strong>
                            <?= number_format($statusCounts['open']); ?>
                        </strong>
                    </span>

                    <span class="reports-count-pill">
                        Reviewing
                        <strong>
                            <?= number_format($statusCounts['reviewing']); ?>
                        </strong>
                    </span>

                    <span class="reports-count-pill">
                        Resolved
                        <strong>
                            <?= number_format($statusCounts['resolved']); ?>
                        </strong>
                    </span>

                    <span class="reports-count-pill">
                        Dismissed
                        <strong>
                            <?= number_format($statusCounts['dismissed']); ?>
                        </strong>
                    </span>

                </div>

            </div>


            <?php if ($reports === []): ?>

            <div class="report-empty">

                <h2>
                    No reports found.
                </h2>

                <p>
                    There are no reports matching the selected filters.
                </p>

            </div>

            <?php else: ?>

            <div class="reports-list">

                <?php foreach ($reports as $report): ?>

                <?php

                        $reportId =
                            (int) $report['id'];

                        $reportStatus =
                            (string) $report['status'];

                        $reportPriority =
                            (string) $report['priority'];

                        $reporterName =
                            trim(
                                (string) (
                                    $report['reporter_display_name']
                                    ?? ''
                                )
                            );

                        if ($reporterName === '') {
                            $reporterName =
                                (string) $report['reporter_username'];
                        }

                        $reviewerName =
                            trim(
                                (string) (
                                    $report['reviewer_display_name']
                                    ?? ''
                                )
                            );

                        if (
                            $reviewerName === ''
                            && $report['reviewer_username'] !== null
                        ) {
                            $reviewerName =
                                (string) $report['reviewer_username'];
                        }

                        $threadTitle =
                            trim(
                                (string) (
                                    $report['current_thread_title']
                                    ?? ''
                                )
                            );

                        if ($threadTitle === '') {
                            $threadTitle =
                                trim(
                                    (string) (
                                        $report['thread_title_snapshot']
                                        ?? ''
                                    )
                                );
                        }

                        if ($threadTitle === '') {
                            $threadTitle =
                                'Reported Forum Content';
                        }

                        $postLink = null;

                        if (
                            $report['thread_id'] !== null
                            && $report['post_id'] !== null
                        ) {
                            $postLink =
                                url(
                                    'thread.php?t='
                                    . (int) $report['thread_id']
                                )
                                . '#post-'
                                . (int) $report['post_id'];
                        }

                        $isClosed =
                            in_array(
                                $reportStatus,
                                [
                                    'resolved',
                                    'dismissed',
                                ],
                                true
                            );

                        ?>

                <article class="report-card" id="report-<?= $reportId; ?>">

                    <header class="report-card-header">

                        <div>

                            <h2>
                                Report #<?= $reportId; ?>:
                                <?= e($threadTitle); ?>
                            </h2>

                            <p class="report-card-meta">

                                <span>
                                    Reported by
                                    <?= e($reporterName); ?>
                                </span>

                                <span>
                                    <?= e(
                                                admin_reports_datetime(
                                                    (string) $report['created_at']
                                                )
                                            ); ?>
                                </span>

                                <span>
                                    Reason:
                                    <?= e((string) $report['reason']); ?>
                                </span>

                            </p>

                        </div>


                        <div class="report-badges">

                            <span class="report-badge status-<?= e($reportStatus); ?>">
                                <?= e(ucfirst($reportStatus)); ?>
                            </span>

                            <span class="report-badge priority-<?= e($reportPriority); ?>">
                                <?= e(ucfirst($reportPriority)); ?>
                            </span>

                        </div>

                    </header>


                    <div class="report-card-body">

                        <div class="report-section">

                            <?php if (
                                        trim(
                                            (string) (
                                                $report['details']
                                                ?? ''
                                            )
                                        ) !== ''
                                    ): ?>

                            <h3>
                                Reporter Details
                            </h3>

                            <p>
                                <?= nl2br(
                                                e(
                                                    (string) $report['details']
                                                )
                                            ); ?>
                            </p>

                            <?php endif; ?>


                            <h3>
                                Reported Post Snapshot
                            </h3>

                            <div class="report-snapshot">
                                <?= e(
                                            admin_reports_excerpt(
                                                (string) (
                                                    $report['post_content_snapshot']
                                                    ?? ''
                                                ),
                                                1200
                                            )
                                        ); ?>
                            </div>


                            <?php if ($postLink !== null): ?>

                            <div class="forum-admin-edit-actions" style="margin-top: 0.85rem;">

                                <a href="<?= e($postLink); ?>" class="button button-secondary">
                                    View Reported Post
                                </a>

                            </div>

                            <?php else: ?>

                            <p style="margin-top: 0.85rem;">
                                The original post or thread is no longer available,
                                so this report is using its stored snapshot.
                            </p>

                            <?php endif; ?>


                            <?php if (
                                        trim(
                                            (string) (
                                                $report['resolution_notes']
                                                ?? ''
                                            )
                                        ) !== ''
                                    ): ?>

                            <h3 style="margin-top: 1rem;">
                                Resolution Notes
                            </h3>

                            <p>
                                <?= nl2br(
                                                e(
                                                    (string) $report['resolution_notes']
                                                )
                                            ); ?>
                            </p>

                            <?php endif; ?>

                        </div>


                        <aside class="report-actions">

                            <div class="report-reviewer">

                                <strong>
                                    Reviewer:
                                </strong>

                                <?= $reviewerName !== ''
                                            ? e($reviewerName)
                                            : 'Not assigned'; ?>

                                <br>

                                <strong>
                                    Reviewed:
                                </strong>

                                <?= e(
                                            admin_reports_datetime(
                                                $report['reviewed_at'] !== null
                                                    ? (string) $report['reviewed_at']
                                                    : null
                                            )
                                        ); ?>

                            </div>


                            <?php if (
                                        !$isClosed
                                        && $canReviewReports
                                    ): ?>

                            <form action="<?= e(url('admin/reports.php')); ?>" method="post" class="report-action-form">

                                <?= csrf_field(); ?>

                                <input type="hidden" name="form_action" value="start_review">

                                <input type="hidden" name="report_id" value="<?= $reportId; ?>">

                                <input type="hidden" name="return_status" value="<?= e($statusFilter); ?>">

                                <input type="hidden" name="return_priority" value="<?= e($priorityFilter); ?>">

                                <button type="submit" class="button button-secondary">
                                    <?= $reportStatus === 'reviewing'
                                                    ? 'Take Over Review'
                                                    : 'Start Review'; ?>
                                </button>

                            </form>


                            <form action="<?= e(url('admin/reports.php')); ?>" method="post" class="report-action-form">

                                <?= csrf_field(); ?>

                                <input type="hidden" name="form_action" value="update_priority">

                                <input type="hidden" name="report_id" value="<?= $reportId; ?>">

                                <input type="hidden" name="return_status" value="<?= e($statusFilter); ?>">

                                <input type="hidden" name="return_priority" value="<?= e($priorityFilter); ?>">

                                <div class="form-group">

                                    <label for="priority-<?= $reportId; ?>">
                                        Priority
                                    </label>

                                    <select id="priority-<?= $reportId; ?>" name="priority" class="form-control">
                                        <option value="low" <?= $reportPriority === 'low' ? 'selected' : ''; ?>>
                                            Low
                                        </option>
                                        <option value="normal" <?= $reportPriority === 'normal' ? 'selected' : ''; ?>>
                                            Normal
                                        </option>
                                        <option value="high" <?= $reportPriority === 'high' ? 'selected' : ''; ?>>
                                            High
                                        </option>
                                        <option value="urgent" <?= $reportPriority === 'urgent' ? 'selected' : ''; ?>>
                                            Urgent
                                        </option>
                                    </select>

                                </div>

                                <button type="submit" class="button button-secondary">
                                    Update Priority
                                </button>

                            </form>

                            <?php endif; ?>


                            <?php if (
                                        !$isClosed
                                        && (
                                            $canResolveReports
                                            || $canDismissReports
                                        )
                                    ): ?>

                            <form action="<?= e(url('admin/reports.php')); ?>" method="post" class="report-action-form">

                                <?= csrf_field(); ?>

                                <input type="hidden" name="report_id" value="<?= $reportId; ?>">

                                <input type="hidden" name="return_status" value="<?= e($statusFilter); ?>">

                                <input type="hidden" name="return_priority" value="<?= e($priorityFilter); ?>">

                                <div class="form-group">

                                    <label for="resolution-notes-<?= $reportId; ?>">
                                        Resolution Notes
                                    </label>

                                    <textarea id="resolution-notes-<?= $reportId; ?>" name="resolution_notes"
                                        class="form-control" maxlength="10000"
                                        placeholder="Optional internal notes about the outcome"></textarea>

                                </div>


                                <div class="report-action-buttons">

                                    <?php if ($canResolveReports): ?>

                                    <button type="submit" name="form_action" value="resolve_report"
                                        class="button button-primary">
                                        Resolve
                                    </button>

                                    <?php endif; ?>


                                    <?php if ($canDismissReports): ?>

                                    <button type="submit" name="form_action" value="dismiss_report"
                                        class="button button-secondary">
                                        Dismiss
                                    </button>

                                    <?php endif; ?>

                                </div>

                            </form>

                            <?php endif; ?>


                            <?php if ($isClosed): ?>

                            <div class="report-reviewer">

                                This report is closed.

                                <?php if ($reportStatus === 'resolved'): ?>
                                The report was resolved.
                                <?php else: ?>
                                The report was dismissed.
                                <?php endif; ?>

                            </div>

                            <?php endif; ?>

                        </aside>

                    </div>

                </article>

                <?php endforeach; ?>

            </div>

            <?php endif; ?>

        </div>

    </section>

</main>

<?php

require
    INCLUDES_PATH
    . '/footer.php';

?>
