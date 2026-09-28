<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

require_active_account();
require_permission('moderation.history.view');

$isProtectedAdmin =
    current_user_is_superuser();

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

function moderation_history_query_value(
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


function moderation_history_query_id(
    string $key
): int {
    $value =
        $_GET[$key]
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


function moderation_history_action_label(
    string $action
): string {
    $labels = [
        'warning' => 'Warning',
        'edit_post' => 'Edited Post',
        'delete_post' => 'Deleted Post',
        'restore_post' => 'Restored Post',
        'edit_thread' => 'Edited Thread',
        'delete_thread' => 'Deleted Thread',
        'restore_thread' => 'Restored Thread',
        'move_post' => 'Moved Post',
        'lock_thread' => 'Locked Thread',
        'unlock_thread' => 'Unlocked Thread',
        'pin_thread' => 'Pinned Thread',
        'unpin_thread' => 'Unpinned Thread',
        'mark_announcement' => 'Marked Announcement',
        'remove_announcement' => 'Removed Announcement',
        'apply_label' => 'Applied Label',
        'remove_label' => 'Removed Label',
        'move_thread' => 'Moved Thread',
        'mute_user' => 'Muted User',
        'unmute_user' => 'Unmuted User',
        'suspend_user' => 'Suspended User',
        'unsuspend_user' => 'Unsuspended User',
        'ban_user' => 'Banned User',
        'unban_user' => 'Unbanned User',
        'other' => 'Other',
    ];

    return $labels[$action]
        ?? ucwords(
            str_replace(
                '_',
                ' ',
                $action
            )
        );
}


function moderation_history_datetime(
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


/*
|--------------------------------------------------------------------------
| Archive View
|--------------------------------------------------------------------------
*/

$archiveView =
    (
        $isProtectedAdmin
        && moderation_history_query_value(
            'archive'
        ) === '1'
    );


/*
|--------------------------------------------------------------------------
| Protected Admin Archive Actions
|--------------------------------------------------------------------------
*/

if (is_post()) {
    require_valid_csrf();

    if (!$isProtectedAdmin) {
        http_response_code(403);
        exit('You do not have permission to manage moderation history archives.');
    }

    $formAction =
        post_value('form_action');

    $entryId =
        max(
            0,
            (int) post_value(
                'entry_id',
                '0'
            )
        );

    $archiveReason =
        trim(
            post_value(
                'archive_reason'
            )
        );

    $redirectArchive =
        post_value(
            'return_archive',
            '0'
        ) === '1';

    if (
        $formAction === 'archive_entry'
        && $entryId > 0
    ) {
        $statement =
            $pdo->prepare(
                'UPDATE moderation_actions
                 SET
                    is_archived = 1,
                    archived_at = NOW(),
                    archived_by = :archived_by,
                    archive_reason = :archive_reason
                 WHERE id = :entry_id
                   AND is_archived = 0'
            );

        $statement->execute([
            'archived_by' =>
                $currentUserId,
            'archive_reason' =>
                $archiveReason !== ''
                    ? $archiveReason
                    : null,
            'entry_id' =>
                $entryId,
        ]);

        set_flash(
            'success',
            $statement->rowCount() > 0
                ? 'The moderation history entry was archived.'
                : 'That moderation history entry was already archived or could not be found.'
        );
    }


    if (
        $formAction === 'restore_entry'
        && $entryId > 0
    ) {
        $statement =
            $pdo->prepare(
                'UPDATE moderation_actions
                 SET
                    is_archived = 0,
                    archived_at = NULL,
                    archived_by = NULL,
                    archive_reason = NULL
                 WHERE id = :entry_id
                   AND is_archived = 1'
            );

        $statement->execute([
            'entry_id' =>
                $entryId,
        ]);

        set_flash(
            'success',
            $statement->rowCount() > 0
                ? 'The moderation history entry was restored.'
                : 'That archived entry could not be found.'
        );
    }


    if (
        $formAction === 'delete_entry'
        && $entryId > 0
    ) {
        $confirmed =
            post_value(
                'confirm_delete'
            ) === '1';

        if (!$confirmed) {
            set_flash(
                'error',
                'Permanent deletion was not confirmed.'
            );
        } else {
            $statement =
                $pdo->prepare(
                    'DELETE FROM moderation_actions
                     WHERE id = :entry_id
                       AND is_archived = 1
                     LIMIT 1'
                );

            $statement->execute([
                'entry_id' =>
                    $entryId,
            ]);

            set_flash(
                'success',
                $statement->rowCount() > 0
                    ? 'The archived moderation history entry was permanently deleted.'
                    : 'Only archived moderation history entries can be permanently deleted.'
            );
        }
    }


    if ($formAction === 'bulk_archive') {
        $age =
            post_value(
                'archive_age'
            );

        $intervalSql = null;
        $ageLabel = null;

        if ($age === '6months') {
            $intervalSql =
                '6 MONTH';
            $ageLabel =
                'six months';
        } elseif ($age === '1year') {
            $intervalSql =
                '1 YEAR';
            $ageLabel =
                'one year';
        } elseif ($age === '2years') {
            $intervalSql =
                '2 YEAR';
            $ageLabel =
                'two years';
        }

        if ($intervalSql === null) {
            set_flash(
                'error',
                'Choose a valid archive age.'
            );
        } else {
            $statement =
                $pdo->prepare(
                    'UPDATE moderation_actions
                     SET
                        is_archived = 1,
                        archived_at = NOW(),
                        archived_by = :archived_by,
                        archive_reason = :archive_reason
                     WHERE is_archived = 0
                       AND created_at < DATE_SUB(NOW(), INTERVAL '
                    . $intervalSql
                    . ')'
                );

            $statement->execute([
                'archived_by' =>
                    $currentUserId,
                'archive_reason' =>
                    'Bulk archive: entries older than '
                    . $ageLabel
                    . '.',
            ]);

            set_flash(
                'success',
                number_format(
                    $statement->rowCount()
                )
                . ' moderation history entr'
                . (
                    $statement->rowCount() === 1
                        ? 'y was'
                        : 'ies were'
                )
                . ' archived.'
            );
        }
    }


    $redirectUrl =
        url('admin/moderation-history.php');

    if ($redirectArchive) {
        $redirectUrl .=
            '?archive=1';
    }

    redirect($redirectUrl);
}


/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$actionFilter =
    moderation_history_query_value(
        'action'
    );

$moderatorFilter =
    moderation_history_query_id(
        'moderator'
    );

$targetUserFilter =
    moderation_history_query_id(
        'target_user'
    );

$forumFilter =
    moderation_history_query_id(
        'forum'
    );

$dateFrom =
    moderation_history_query_value(
        'from'
    );

$dateTo =
    moderation_history_query_value(
        'to'
    );


$pageValue =
    $_GET['page']
    ?? '1';

$currentPage =
    (
        is_scalar($pageValue)
        && ctype_digit((string) $pageValue)
    )
        ? max(1, (int) $pageValue)
        : 1;

$perPage =
    20;

$validActionTypes = [
    'warning',
    'edit_post',
    'delete_post',
    'restore_post',
    'edit_thread',
    'delete_thread',
    'restore_thread',
    'move_post',
    'lock_thread',
    'unlock_thread',
    'pin_thread',
    'unpin_thread',
    'mark_announcement',
    'remove_announcement',
    'apply_label',
    'remove_label',
    'move_thread',
    'mute_user',
    'unmute_user',
    'suspend_user',
    'unsuspend_user',
    'ban_user',
    'unban_user',
    'other',
];

if (
    $actionFilter !== ''
    && !in_array(
        $actionFilter,
        $validActionTypes,
        true
    )
) {
    $actionFilter =
        '';
}

if (
    $dateFrom !== ''
    && !preg_match(
        '/^\d{4}-\d{2}-\d{2}$/',
        $dateFrom
    )
) {
    $dateFrom =
        '';
}

if (
    $dateTo !== ''
    && !preg_match(
        '/^\d{4}-\d{2}-\d{2}$/',
        $dateTo
    )
) {
    $dateTo =
        '';
}


/*
|--------------------------------------------------------------------------
| Filter Options
|--------------------------------------------------------------------------
*/

$moderatorsStatement =
    $pdo->query(
        'SELECT DISTINCT
            u.id,
            u.display_name,
            u.username
         FROM moderation_actions ma
         INNER JOIN users u
            ON u.id = ma.moderator_id
         ORDER BY u.display_name ASC, u.username ASC'
    );

$moderators =
    $moderatorsStatement->fetchAll(
        PDO::FETCH_ASSOC
    );


$targetUsersStatement =
    $pdo->query(
        'SELECT DISTINCT
            u.id,
            u.display_name,
            u.username
         FROM moderation_actions ma
         INNER JOIN users u
            ON u.id = ma.target_user_id
         WHERE ma.target_user_id IS NOT NULL
         ORDER BY u.display_name ASC, u.username ASC'
    );

$targetUsers =
    $targetUsersStatement->fetchAll(
        PDO::FETCH_ASSOC
    );


$forumsStatement =
    $pdo->query(
        'SELECT DISTINCT
            f.id,
            f.title
         FROM moderation_actions ma
         INNER JOIN forums f
            ON f.id = ma.forum_id
         WHERE ma.forum_id IS NOT NULL
         ORDER BY f.title ASC'
    );

$forums =
    $forumsStatement->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Matching Result Count
|--------------------------------------------------------------------------
*/

$countSql =
    'SELECT COUNT(*)
     FROM moderation_actions ma
     WHERE ma.is_archived = :archive_state';

$countParams = [
    'archive_state' =>
        $archiveView
            ? 1
            : 0,
];

if ($actionFilter !== '') {
    $countSql .=
        ' AND ma.action_type = :action_type';

    $countParams['action_type'] =
        $actionFilter;
}

if ($moderatorFilter > 0) {
    $countSql .=
        ' AND ma.moderator_id = :moderator_id';

    $countParams['moderator_id'] =
        $moderatorFilter;
}

if ($targetUserFilter > 0) {
    $countSql .=
        ' AND ma.target_user_id = :target_user_id';

    $countParams['target_user_id'] =
        $targetUserFilter;
}

if ($forumFilter > 0) {
    $countSql .=
        ' AND ma.forum_id = :forum_id';

    $countParams['forum_id'] =
        $forumFilter;
}

if ($dateFrom !== '') {
    $countSql .=
        ' AND ma.created_at >= :date_from';

    $countParams['date_from'] =
        $dateFrom
        . ' 00:00:00';
}

if ($dateTo !== '') {
    $countSql .=
        ' AND ma.created_at <= :date_to';

    $countParams['date_to'] =
        $dateTo
        . ' 23:59:59';
}

$countStatement =
    $pdo->prepare($countSql);

$countStatement->execute(
    $countParams
);

$totalMatchingActions =
    (int) $countStatement->fetchColumn();

$totalPages =
    max(
        1,
        (int) ceil(
            $totalMatchingActions
            / $perPage
        )
    );

if ($currentPage > $totalPages) {
    $currentPage =
        $totalPages;
}

$offset =
    ($currentPage - 1)
    * $perPage;


/*
|--------------------------------------------------------------------------
| Moderation History Query
|--------------------------------------------------------------------------
*/

$sql =
    'SELECT
        ma.id,
        ma.moderator_id,
        ma.target_user_id,
        ma.forum_id,
        ma.thread_id,
        ma.post_id,
        ma.label_id,
        ma.source_thread_id,
        ma.destination_thread_id,
        ma.action_type,
        ma.reason,
        ma.notes,
        ma.expires_at,
        ma.is_archived,
        ma.archived_at,
        ma.archived_by,
        ma.archive_reason,
        ma.created_at,

        archived_user.display_name
            AS archived_by_display_name,
        archived_user.username
            AS archived_by_username,

        moderator.display_name
            AS moderator_display_name,
        moderator.username
            AS moderator_username,

        target.display_name
            AS target_display_name,
        target.username
            AS target_username,

        f.title
            AS forum_title,

        t.title
            AS thread_title,

        source_thread.title
            AS source_thread_title,

        destination_thread.title
            AS destination_thread_title,

        fl.name
            AS label_name

     FROM moderation_actions ma

     INNER JOIN users moderator
        ON moderator.id = ma.moderator_id

     LEFT JOIN users target
        ON target.id = ma.target_user_id

     LEFT JOIN forums f
        ON f.id = ma.forum_id

     LEFT JOIN forum_threads t
        ON t.id = ma.thread_id

     LEFT JOIN forum_threads source_thread
        ON source_thread.id = ma.source_thread_id

     LEFT JOIN forum_threads destination_thread
        ON destination_thread.id = ma.destination_thread_id

     LEFT JOIN forum_labels fl
        ON fl.id = ma.label_id

     LEFT JOIN users archived_user
        ON archived_user.id = ma.archived_by

     WHERE ma.is_archived = :archive_state';

$params = [
    'archive_state' =>
        $archiveView
            ? 1
            : 0,
];

if ($actionFilter !== '') {
    $sql .=
        ' AND ma.action_type = :action_type';

    $params['action_type'] =
        $actionFilter;
}

if ($moderatorFilter > 0) {
    $sql .=
        ' AND ma.moderator_id = :moderator_id';

    $params['moderator_id'] =
        $moderatorFilter;
}

if ($targetUserFilter > 0) {
    $sql .=
        ' AND ma.target_user_id = :target_user_id';

    $params['target_user_id'] =
        $targetUserFilter;
}

if ($forumFilter > 0) {
    $sql .=
        ' AND ma.forum_id = :forum_id';

    $params['forum_id'] =
        $forumFilter;
}

if ($dateFrom !== '') {
    $sql .=
        ' AND ma.created_at >= :date_from';

    $params['date_from'] =
        $dateFrom
        . ' 00:00:00';
}

if ($dateTo !== '') {
    $sql .=
        ' AND ma.created_at <= :date_to';

    $params['date_to'] =
        $dateTo
        . ' 23:59:59';
}

$sql .=
    ' ORDER BY ma.created_at DESC, ma.id DESC
      LIMIT '
    . (int) $perPage
    . ' OFFSET '
    . (int) $offset;

$historyStatement =
    $pdo->prepare($sql);

$historyStatement->execute(
    $params
);

$history =
    $historyStatement->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Summary Counts
|--------------------------------------------------------------------------
*/

$summaryStatement =
    $pdo->query(
        'SELECT
            SUM(CASE WHEN is_archived = 0 THEN 1 ELSE 0 END)
                AS active_actions,
            SUM(CASE WHEN is_archived = 1 THEN 1 ELSE 0 END)
                AS archived_actions,
            COUNT(DISTINCT moderator_id)
                AS total_moderators,
            COUNT(DISTINCT target_user_id)
                AS total_target_users
         FROM moderation_actions'
    );

$summary =
    $summaryStatement->fetch(
        PDO::FETCH_ASSOC
    )
    ?: [
        'active_actions' => 0,
        'archived_actions' => 0,
        'total_moderators' => 0,
        'total_target_users' => 0,
    ];


/*
|--------------------------------------------------------------------------
| Pagination URLs
|--------------------------------------------------------------------------
*/

$paginationBaseQuery = [];

if ($archiveView) {
    $paginationBaseQuery['archive'] =
        '1';
}

if ($actionFilter !== '') {
    $paginationBaseQuery['action'] =
        $actionFilter;
}

if ($moderatorFilter > 0) {
    $paginationBaseQuery['moderator'] =
        $moderatorFilter;
}

if ($targetUserFilter > 0) {
    $paginationBaseQuery['target_user'] =
        $targetUserFilter;
}

if ($forumFilter > 0) {
    $paginationBaseQuery['forum'] =
        $forumFilter;
}

if ($dateFrom !== '') {
    $paginationBaseQuery['from'] =
        $dateFrom;
}

if ($dateTo !== '') {
    $paginationBaseQuery['to'] =
        $dateTo;
}

$previousPageUrl =
    '';

$nextPageUrl =
    '';

if ($currentPage > 1) {
    $previousQuery =
        $paginationBaseQuery;

    $previousQuery['page'] =
        $currentPage - 1;

    $previousPageUrl =
        url('admin/moderation-history.php')
        . '?'
        . http_build_query($previousQuery);
}

if ($currentPage < $totalPages) {
    $nextQuery =
        $paginationBaseQuery;

    $nextQuery['page'] =
        $currentPage + 1;

    $nextPageUrl =
        url('admin/moderation-history.php')
        . '?'
        . http_build_query($nextQuery);
}

$pageLinks = [];

$startPage =
    max(
        1,
        $currentPage - 2
    );

$endPage =
    min(
        $totalPages,
        $currentPage + 2
    );

for (
    $pageNumber = $startPage;
    $pageNumber <= $endPage;
    $pageNumber++
) {
    $pageQuery =
        $paginationBaseQuery;

    $pageQuery['page'] =
        $pageNumber;

    $pageLinks[] = [
        'number' => $pageNumber,
        'url' =>
            url('admin/moderation-history.php')
            . '?'
            . http_build_query($pageQuery),
        'current' =>
            $pageNumber === $currentPage,
    ];
}


/*
|--------------------------------------------------------------------------
| Page Metadata
|--------------------------------------------------------------------------
*/

$pageTitle =
    'Moderation History | Blackthorne Academy';

$pageDescription =
    'Review recorded moderation actions across Blackthorne Academy.';

$pageCanonical =
    url('admin/moderation-history.php');

$robots =
    'noindex, nofollow';

require
    INCLUDES_PATH
    . '/header.php';

?>

<style>
.moderation-history-page .moderation-history-toolbar {
    display: grid;
    gap: 1rem;
    margin-bottom: 1.4rem;
}

.moderation-history-page .moderation-history-filters {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.85rem 1rem;
    padding: 1rem;
    border: 1px solid rgba(150, 113, 147, 0.28);
    background: rgba(24, 13, 27, 0.58);
}

.moderation-history-page .moderation-history-filters .form-group {
    margin: 0;
}

.moderation-history-page .moderation-history-filter-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.6rem;
    align-items: end;
}

.moderation-history-page select.form-control,
.moderation-history-page input.form-control {
    color: #eee7ef;
    background: #160d19;
    border: 1px solid rgba(150, 113, 147, 0.55);
    border-radius: 5px;
    box-shadow: none;
}

.moderation-history-page select.form-control:hover,
.moderation-history-page input.form-control:hover {
    border-color: rgba(212, 178, 91, 0.55);
}

.moderation-history-page select.form-control:focus,
.moderation-history-page input.form-control:focus {
    color: #fff8ef;
    background: #1c1020;
    border-color: #d4b25b;
    outline: 2px solid rgba(212, 178, 91, 0.18);
    outline-offset: 2px;
    box-shadow: none;
}

.moderation-history-page select.form-control {
    color-scheme: dark;
}

.moderation-history-page select.form-control option {
    color: #eee7ef;
    background: #160d19;
}

.moderation-history-page .moderation-summary {
    display: flex;
    flex-wrap: wrap;
    gap: 0.55rem;
}

.moderation-history-page .moderation-summary-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    min-height: 2.1rem;
    padding: 0.38rem 0.7rem;
    border: 1px solid rgba(212, 178, 91, 0.3);
    border-radius: 999px;
    background: rgba(31, 17, 35, 0.6);
    font-size: 0.8rem;
}

.moderation-history-page .moderation-summary-pill strong {
    color: #d4b25b;
}

.moderation-history-page .moderation-history-list {
    display: grid;
    gap: 0.8rem;
}

.moderation-history-page .moderation-history-card {
    border: 1px solid rgba(212, 178, 91, 0.18);
    background: rgba(10, 8, 13, 0.62);
}

.moderation-history-page .moderation-history-card-header {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 1rem;
    align-items: start;
    padding: 0.9rem 1rem;
    border-bottom: 1px solid rgba(212, 178, 91, 0.12);
}

.moderation-history-page .moderation-history-card-header h2 {
    margin: 0 0 0.3rem;
    font-size: 1rem;
    font-weight: 500;
}

.moderation-history-page .moderation-history-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 0.3rem 0.75rem;
    margin: 0;
    font-size: 0.8rem;
    opacity: 0.78;
}

.moderation-history-page .moderation-action-badge {
    display: inline-flex;
    align-items: center;
    min-height: 1.8rem;
    padding: 0.25rem 0.55rem;
    border: 1px solid rgba(150, 113, 147, 0.5);
    border-radius: 999px;
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    white-space: nowrap;
}

.moderation-history-page .moderation-history-card-body {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(220px, 0.45fr);
    gap: 1.2rem;
    padding: 1rem;
}

.moderation-history-page .moderation-history-details {
    min-width: 0;
}

.moderation-history-page .moderation-history-details p {
    margin: 0 0 0.65rem;
}

.moderation-history-page .moderation-history-context {
    padding: 0.75rem 0.85rem;
    border-left: 2px solid rgba(212, 178, 91, 0.52);
    background: rgba(40, 23, 43, 0.34);
}

.moderation-history-page .moderation-history-context p {
    margin: 0 0 0.45rem;
}

.moderation-history-page .moderation-history-context p:last-child {
    margin-bottom: 0;
}

.moderation-history-page .moderation-history-links {
    display: flex;
    flex-wrap: wrap;
    gap: 0.55rem;
    align-content: start;
}

.moderation-history-page .moderation-history-empty {
    padding: 2rem 1rem;
    text-align: center;
    border: 1px solid rgba(212, 178, 91, 0.2);
    background: rgba(255, 255, 255, 0.018);
}



.moderation-history-page .moderation-archive-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 0.65rem;
    align-items: end;
    margin: 0 0 1rem;
    padding: 0.9rem;
    border: 1px solid rgba(212, 178, 91, 0.2);
    background: rgba(30, 16, 34, 0.42);
}

.moderation-history-page .moderation-archive-toolbar form {
    display: flex;
    flex-wrap: wrap;
    gap: 0.55rem;
    align-items: end;
    margin: 0;
}

.moderation-history-page .moderation-archive-toolbar .form-group {
    margin: 0;
}

.moderation-history-page .moderation-admin-actions {
    margin-top: 0.9rem;
    padding-top: 0.9rem;
    border-top: 1px solid rgba(150, 113, 147, 0.2);
}

.moderation-history-page .moderation-admin-actions form {
    margin: 0;
}

.moderation-history-page .moderation-admin-actions-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 0.6rem;
    align-items: end;
}

.moderation-history-page .moderation-delete-confirm {
    display: flex;
    gap: 0.45rem;
    align-items: flex-start;
    margin: 0.65rem 0;
    font-size: 0.8rem;
}

.moderation-history-page .moderation-archive-meta {
    margin-top: 0.75rem;
    padding: 0.7rem 0.8rem;
    border: 1px solid rgba(212, 178, 91, 0.18);
    background: rgba(212, 178, 91, 0.04);
    font-size: 0.82rem;
}

.moderation-history-page .moderation-pagination {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: center;
    gap: 0.45rem;
    margin: 1rem 0;
}

.moderation-history-page .moderation-pagination a,
.moderation-history-page .moderation-pagination span {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 2.25rem;
    min-height: 2.1rem;
    padding: 0.35rem 0.65rem;
    border: 1px solid rgba(150, 113, 147, 0.35);
    border-radius: 4px;
    background: rgba(31, 17, 35, 0.55);
    color: #eee7ef;
    text-decoration: none;
    font-size: 0.82rem;
}

.moderation-history-page .moderation-pagination a:hover {
    border-color: rgba(212, 178, 91, 0.6);
    color: #fff8ef;
}

.moderation-history-page .moderation-pagination .current {
    border-color: #d4b25b;
    color: #d4b25b;
    background: rgba(212, 178, 91, 0.08);
}

.moderation-history-page .moderation-pagination .disabled {
    opacity: 0.45;
}

@media (max-width: 900px) {
    .moderation-history-page .moderation-history-filters {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .moderation-history-page .moderation-history-card-body {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 640px) {
    .moderation-history-page .moderation-history-filters,
    .moderation-history-page .moderation-history-card-header {
        grid-template-columns: 1fr;
    }
}
</style>

<main
    id="main-content"
    class="forum-admin-page moderation-history-page"
>

    <section
        class="forum-admin-hero"
        aria-labelledby="moderation-history-heading"
    >

        <div class="section-inner">

            <p class="academy-overline">
                Moderation
            </p>

            <h1 id="moderation-history-heading">
                <?= $archiveView
                    ? 'Archived Moderation History'
                    : 'Moderation History'; ?>
            </h1>

            <p>
                <?php if ($archiveView): ?>
                    Review moderation audit records archived by the protected Admin.
                <?php else: ?>
                    Review recorded moderation actions across the Academy,
                    including forum actions and user sanctions.
                <?php endif; ?>
            </p>

            <div class="forum-admin-edit-actions">

                <a
                    href="<?= e(url('admin/moderation.php')); ?>"
                    class="button button-secondary"
                >
                    Moderation
                </a>

                <?php if (
                    user_can_any([
                        'moderation.reports.view',
                        'moderation.reports.review',
                        'moderation.reports.resolve',
                        'moderation.reports.dismiss',
                    ])
                ): ?>

                    <a
                        href="<?= e(url('admin/reports.php')); ?>"
                        class="button button-secondary"
                    >
                        Reports &amp; Moderation
                    </a>

                <?php endif; ?>

                <a
                    href="<?= e(url('staff-dashboard.php')); ?>"
                    class="button button-secondary"
                >
                    Staff Dashboard
                </a>

            </div>

        </div>

    </section>


    <section class="forum-admin-content">

        <div class="section-inner">

            <div class="moderation-history-toolbar">

                <div
                    class="moderation-summary"
                    aria-label="Moderation history summary"
                >

                    <span class="moderation-summary-pill">
                        Active History
                        <strong>
                            <?= number_format(
                                (int) $summary['active_actions']
                            ); ?>
                        </strong>
                    </span>

                    <?php if ($isProtectedAdmin): ?>

                        <span class="moderation-summary-pill">
                            Archived
                            <strong>
                                <?= number_format(
                                    (int) $summary['archived_actions']
                                ); ?>
                            </strong>
                        </span>

                    <?php endif; ?>

                    <span class="moderation-summary-pill">
                        Moderators
                        <strong>
                            <?= number_format(
                                (int) $summary['total_moderators']
                            ); ?>
                        </strong>
                    </span>

                    <span class="moderation-summary-pill">
                        Users Affected
                        <strong>
                            <?= number_format(
                                (int) $summary['total_target_users']
                            ); ?>
                        </strong>
                    </span>

                    <span class="moderation-summary-pill">
                        Matching Filters
                        <strong>
                            <?= number_format(
                                $totalMatchingActions
                            ); ?>
                        </strong>
                    </span>

                </div>


                <?php if ($isProtectedAdmin): ?>

                    <div class="moderation-archive-toolbar">

                        <?php if ($archiveView): ?>

                            <a
                                href="<?= e(url('admin/moderation-history.php')); ?>"
                                class="button button-secondary"
                            >
                                Active History
                            </a>

                        <?php else: ?>

                            <a
                                href="<?= e(url('admin/moderation-history.php') . '?archive=1'); ?>"
                                class="button button-secondary"
                            >
                                Archived History
                            </a>


                            <form
                                method="post"
                                action="<?= e(url('admin/moderation-history.php')); ?>"
                            >
                                <?= csrf_field(); ?>

                                <input
                                    type="hidden"
                                    name="form_action"
                                    value="bulk_archive"
                                >

                                <div class="form-group">
                                    <label for="archive-age">
                                        Bulk Archive
                                    </label>

                                    <select
                                        id="archive-age"
                                        name="archive_age"
                                        class="form-control"
                                        required
                                    >
                                        <option value="">
                                            Choose age…
                                        </option>
                                        <option value="6months">
                                            Older than 6 months
                                        </option>
                                        <option value="1year">
                                            Older than 1 year
                                        </option>
                                        <option value="2years">
                                            Older than 2 years
                                        </option>
                                    </select>
                                </div>

                                <button
                                    type="submit"
                                    class="button button-secondary"
                                    onclick="return confirm('Archive all moderation history entries older than the selected age?');"
                                >
                                    Archive Old Entries
                                </button>
                            </form>

                        <?php endif; ?>

                    </div>

                <?php endif; ?>


                <form
                    action="<?= e(url('admin/moderation-history.php')); ?>"
                    method="get"
                    class="moderation-history-filters"
                >

                    <?php if ($archiveView): ?>
                        <input
                            type="hidden"
                            name="archive"
                            value="1"
                        >
                    <?php endif; ?>

                    <div class="form-group">

                        <label for="history-action">
                            Action
                        </label>

                        <select
                            id="history-action"
                            name="action"
                            class="form-control"
                        >
                            <option value="">
                                All Actions
                            </option>

                            <?php foreach ($validActionTypes as $actionType): ?>

                                <option
                                    value="<?= e($actionType); ?>"
                                    <?= $actionFilter === $actionType ? 'selected' : ''; ?>
                                >
                                    <?= e(
                                        moderation_history_action_label(
                                            $actionType
                                        )
                                    ); ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <div class="form-group">

                        <label for="history-moderator">
                            Moderator
                        </label>

                        <select
                            id="history-moderator"
                            name="moderator"
                            class="form-control"
                        >
                            <option value="">
                                All Moderators
                            </option>

                            <?php foreach ($moderators as $moderator): ?>

                                <?php

                                $moderatorName =
                                    trim(
                                        (string) (
                                            $moderator['display_name']
                                            ?? ''
                                        )
                                    );

                                if ($moderatorName === '') {
                                    $moderatorName =
                                        (string) $moderator['username'];
                                }

                                ?>

                                <option
                                    value="<?= (int) $moderator['id']; ?>"
                                    <?= $moderatorFilter === (int) $moderator['id'] ? 'selected' : ''; ?>
                                >
                                    <?= e($moderatorName); ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <div class="form-group">

                        <label for="history-target-user">
                            Affected User
                        </label>

                        <select
                            id="history-target-user"
                            name="target_user"
                            class="form-control"
                        >
                            <option value="">
                                All Users
                            </option>

                            <?php foreach ($targetUsers as $targetUser): ?>

                                <?php

                                $targetName =
                                    trim(
                                        (string) (
                                            $targetUser['display_name']
                                            ?? ''
                                        )
                                    );

                                if ($targetName === '') {
                                    $targetName =
                                        (string) $targetUser['username'];
                                }

                                ?>

                                <option
                                    value="<?= (int) $targetUser['id']; ?>"
                                    <?= $targetUserFilter === (int) $targetUser['id'] ? 'selected' : ''; ?>
                                >
                                    <?= e($targetName); ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <div class="form-group">

                        <label for="history-forum">
                            Forum
                        </label>

                        <select
                            id="history-forum"
                            name="forum"
                            class="form-control"
                        >
                            <option value="">
                                All Forums
                            </option>

                            <?php foreach ($forums as $forum): ?>

                                <option
                                    value="<?= (int) $forum['id']; ?>"
                                    <?= $forumFilter === (int) $forum['id'] ? 'selected' : ''; ?>
                                >
                                    <?= e((string) $forum['title']); ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <div class="form-group">

                        <label for="history-from">
                            From
                        </label>

                        <input
                            type="date"
                            id="history-from"
                            name="from"
                            class="form-control"
                            value="<?= e($dateFrom); ?>"
                        >

                    </div>


                    <div class="form-group">

                        <label for="history-to">
                            To
                        </label>

                        <input
                            type="date"
                            id="history-to"
                            name="to"
                            class="form-control"
                            value="<?= e($dateTo); ?>"
                        >

                    </div>


                    <div class="moderation-history-filter-actions">

                        <button
                            type="submit"
                            class="button button-primary"
                        >
                            Apply Filters
                        </button>

                        <a
                            href="<?= e(
                                $archiveView
                                    ? url('admin/moderation-history.php') . '?archive=1'
                                    : url('admin/moderation-history.php')
                            ); ?>"
                            class="button button-secondary"
                        >
                            Clear
                        </a>

                    </div>

                </form>

            </div>


            <?php if ($history === []): ?>

                <div class="moderation-history-empty">

                    <h2>
                        <?= $archiveView
                            ? 'No archived moderation actions found.'
                            : 'No moderation actions found.'; ?>
                    </h2>

                    <p>
                        There are no <?= $archiveView ? 'archived ' : ''; ?>moderation history entries matching
                        the selected filters.
                    </p>

                </div>

            <?php else: ?>


                <nav
                    class="moderation-pagination"
                    aria-label="Moderation history pagination"
                >

                    <?php if ($previousPageUrl !== ''): ?>

                        <a
                            href="<?= e($previousPageUrl); ?>"
                            rel="prev"
                        >
                            Previous
                        </a>

                    <?php else: ?>

                        <span class="disabled">
                            Previous
                        </span>

                    <?php endif; ?>


                    <?php foreach ($pageLinks as $pageLink): ?>

                        <?php if ($pageLink['current']): ?>

                            <span
                                class="current"
                                aria-current="page"
                            >
                                <?= (int) $pageLink['number']; ?>
                            </span>

                        <?php else: ?>

                            <a
                                href="<?= e((string) $pageLink['url']); ?>"
                            >
                                <?= (int) $pageLink['number']; ?>
                            </a>

                        <?php endif; ?>

                    <?php endforeach; ?>


                    <?php if ($nextPageUrl !== ''): ?>

                        <a
                            href="<?= e($nextPageUrl); ?>"
                            rel="next"
                        >
                            Next
                        </a>

                    <?php else: ?>

                        <span class="disabled">
                            Next
                        </span>

                    <?php endif; ?>

                </nav>

                <div class="moderation-history-list">

                    <?php foreach ($history as $entry): ?>

                        <?php

                        $moderatorName =
                            trim(
                                (string) (
                                    $entry['moderator_display_name']
                                    ?? ''
                                )
                            );

                        if ($moderatorName === '') {
                            $moderatorName =
                                (string) $entry['moderator_username'];
                        }


                        $targetName =
                            trim(
                                (string) (
                                    $entry['target_display_name']
                                    ?? ''
                                )
                            );

                        if (
                            $targetName === ''
                            && $entry['target_username'] !== null
                        ) {
                            $targetName =
                                (string) $entry['target_username'];
                        }


                        $threadUrl = null;

                        if ($entry['thread_id'] !== null) {
                            $threadUrl =
                                url(
                                    'thread.php?t='
                                    . (int) $entry['thread_id']
                                );

                            if ($entry['post_id'] !== null) {
                                $threadUrl .=
                                    '#post-'
                                    . (int) $entry['post_id'];
                            }
                        }


                        $sourceThreadUrl = null;

                        if ($entry['source_thread_id'] !== null) {
                            $sourceThreadUrl =
                                url(
                                    'thread.php?t='
                                    . (int) $entry['source_thread_id']
                                );
                        }


                        $destinationThreadUrl = null;

                        if ($entry['destination_thread_id'] !== null) {
                            $destinationThreadUrl =
                                url(
                                    'thread.php?t='
                                    . (int) $entry['destination_thread_id']
                                );
                        }


                        $forumUrl = null;

                        if ($entry['forum_id'] !== null) {
                            $forumUrl =
                                url(
                                    'forum.php?f='
                                    . (int) $entry['forum_id']
                                );
                        }

                        ?>

                        <article class="moderation-history-card">

                            <header class="moderation-history-card-header">

                                <div>

                                    <h2>
                                        <?= e(
                                            moderation_history_action_label(
                                                (string) $entry['action_type']
                                            )
                                        ); ?>
                                    </h2>

                                    <p class="moderation-history-meta">

                                        <span>
                                            By <?= e($moderatorName); ?>
                                        </span>

                                        <?php if ($targetName !== ''): ?>
                                            <span>
                                                User: <?= e($targetName); ?>
                                            </span>
                                        <?php endif; ?>

                                        <span>
                                            <?= e(
                                                moderation_history_datetime(
                                                    (string) $entry['created_at']
                                                )
                                            ); ?>
                                        </span>

                                        <span>
                                            Entry #<?= (int) $entry['id']; ?>
                                        </span>

                                    </p>

                                </div>

                                <span class="moderation-action-badge">
                                    <?= e(
                                        moderation_history_action_label(
                                            (string) $entry['action_type']
                                        )
                                    ); ?>
                                </span>

                            </header>


                            <div class="moderation-history-card-body">

                                <div class="moderation-history-details">

                                    <?php if (
                                        trim(
                                            (string) (
                                                $entry['reason']
                                                ?? ''
                                            )
                                        ) !== ''
                                    ): ?>

                                        <p>
                                            <strong>Reason:</strong><br>
                                            <?= nl2br(
                                                e(
                                                    (string) $entry['reason']
                                                )
                                            ); ?>
                                        </p>

                                    <?php endif; ?>


                                    <?php if (
                                        trim(
                                            (string) (
                                                $entry['notes']
                                                ?? ''
                                            )
                                        ) !== ''
                                    ): ?>

                                        <p>
                                            <strong>Notes:</strong><br>
                                            <?= nl2br(
                                                e(
                                                    (string) $entry['notes']
                                                )
                                            ); ?>
                                        </p>

                                    <?php endif; ?>


                                    <div class="moderation-history-context">

                                        <?php if ($entry['forum_title'] !== null): ?>
                                            <p>
                                                <strong>Forum:</strong>
                                                <?= e((string) $entry['forum_title']); ?>
                                            </p>
                                        <?php endif; ?>

                                        <?php if ($entry['thread_title'] !== null): ?>
                                            <p>
                                                <strong>Thread:</strong>
                                                <?= e((string) $entry['thread_title']); ?>
                                            </p>
                                        <?php endif; ?>

                                        <?php if ($entry['post_id'] !== null): ?>
                                            <p>
                                                <strong>Post:</strong>
                                                #<?= (int) $entry['post_id']; ?>
                                            </p>
                                        <?php endif; ?>

                                        <?php if ($entry['label_name'] !== null): ?>
                                            <p>
                                                <strong>Label:</strong>
                                                <?= e((string) $entry['label_name']); ?>
                                            </p>
                                        <?php endif; ?>

                                        <?php if ($entry['source_thread_title'] !== null): ?>
                                            <p>
                                                <strong>Moved From:</strong>
                                                <?= e((string) $entry['source_thread_title']); ?>
                                            </p>
                                        <?php endif; ?>

                                        <?php if ($entry['destination_thread_title'] !== null): ?>
                                            <p>
                                                <strong>Moved To:</strong>
                                                <?= e((string) $entry['destination_thread_title']); ?>
                                            </p>
                                        <?php endif; ?>

                                        <?php if ($entry['expires_at'] !== null): ?>
                                            <p>
                                                <strong>Expires:</strong>
                                                <?= e(
                                                    moderation_history_datetime(
                                                        (string) $entry['expires_at']
                                                    )
                                                ); ?>
                                            </p>
                                        <?php endif; ?>

                                    </div>


                                    <?php if (
                                        $archiveView
                                        && (int) ($entry['is_archived'] ?? 0) === 1
                                    ): ?>

                                        <?php

                                        $archivedByName =
                                            trim(
                                                (string) (
                                                    $entry['archived_by_display_name']
                                                    ?? ''
                                                )
                                            );

                                        if (
                                            $archivedByName === ''
                                            && $entry['archived_by_username'] !== null
                                        ) {
                                            $archivedByName =
                                                (string) $entry['archived_by_username'];
                                        }

                                        ?>

                                        <div class="moderation-archive-meta">

                                            <strong>Archived:</strong>
                                            <?= e(
                                                moderation_history_datetime(
                                                    (string) $entry['archived_at']
                                                )
                                            ); ?>

                                            <?php if ($archivedByName !== ''): ?>
                                                by <?= e($archivedByName); ?>
                                            <?php endif; ?>

                                            <?php if (
                                                trim(
                                                    (string) (
                                                        $entry['archive_reason']
                                                        ?? ''
                                                    )
                                                ) !== ''
                                            ): ?>
                                                <br>
                                                <strong>Archive reason:</strong>
                                                <?= e((string) $entry['archive_reason']); ?>
                                            <?php endif; ?>

                                        </div>

                                    <?php endif; ?>


                                    <?php if ($isProtectedAdmin): ?>

                                        <div class="moderation-admin-actions">

                                            <?php if (!$archiveView): ?>

                                                <form
                                                    method="post"
                                                    action="<?= e(url('admin/moderation-history.php')); ?>"
                                                >
                                                    <?= csrf_field(); ?>

                                                    <input
                                                        type="hidden"
                                                        name="form_action"
                                                        value="archive_entry"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="entry_id"
                                                        value="<?= (int) $entry['id']; ?>"
                                                    >

                                                    <div class="moderation-admin-actions-grid">

                                                        <div class="form-group">
                                                            <label
                                                                for="archive-reason-<?= (int) $entry['id']; ?>"
                                                            >
                                                                Archive reason
                                                                <span aria-hidden="true">(optional)</span>
                                                            </label>

                                                            <input
                                                                type="text"
                                                                id="archive-reason-<?= (int) $entry['id']; ?>"
                                                                name="archive_reason"
                                                                class="form-control"
                                                                maxlength="255"
                                                                placeholder="Optional note for why this record was archived"
                                                            >
                                                        </div>

                                                        <button
                                                            type="submit"
                                                            class="button button-secondary"
                                                        >
                                                            Archive
                                                        </button>

                                                    </div>
                                                </form>

                                            <?php else: ?>

                                                <form
                                                    method="post"
                                                    action="<?= e(url('admin/moderation-history.php')); ?>"
                                                    style="margin-bottom: 0.7rem;"
                                                >
                                                    <?= csrf_field(); ?>

                                                    <input
                                                        type="hidden"
                                                        name="form_action"
                                                        value="restore_entry"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="entry_id"
                                                        value="<?= (int) $entry['id']; ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="return_archive"
                                                        value="1"
                                                    >

                                                    <button
                                                        type="submit"
                                                        class="button button-secondary"
                                                    >
                                                        Restore to Active History
                                                    </button>
                                                </form>


                                                <form
                                                    method="post"
                                                    action="<?= e(url('admin/moderation-history.php')); ?>"
                                                    onsubmit="return confirm('Permanently delete this archived moderation record? This cannot be undone.');"
                                                >
                                                    <?= csrf_field(); ?>

                                                    <input
                                                        type="hidden"
                                                        name="form_action"
                                                        value="delete_entry"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="entry_id"
                                                        value="<?= (int) $entry['id']; ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="return_archive"
                                                        value="1"
                                                    >

                                                    <label class="moderation-delete-confirm">
                                                        <input
                                                            type="checkbox"
                                                            name="confirm_delete"
                                                            value="1"
                                                            required
                                                        >
                                                        <span>
                                                            I understand this permanently removes the audit record and cannot be undone.
                                                        </span>
                                                    </label>

                                                    <button
                                                        type="submit"
                                                        class="button button-secondary"
                                                    >
                                                        Permanently Delete
                                                    </button>
                                                </form>

                                            <?php endif; ?>

                                        </div>

                                    <?php endif; ?>

                                </div>


                                <div class="moderation-history-links">

                                    <?php if ($threadUrl !== null): ?>

                                        <a
                                            href="<?= e($threadUrl); ?>"
                                            class="button button-secondary"
                                        >
                                            View Thread/Post
                                        </a>

                                    <?php endif; ?>


                                    <?php if (
                                        $forumUrl !== null
                                        && $threadUrl === null
                                    ): ?>

                                        <a
                                            href="<?= e($forumUrl); ?>"
                                            class="button button-secondary"
                                        >
                                            View Forum
                                        </a>

                                    <?php endif; ?>


                                    <?php if (
                                        $sourceThreadUrl !== null
                                        && $sourceThreadUrl !== $threadUrl
                                    ): ?>

                                        <a
                                            href="<?= e($sourceThreadUrl); ?>"
                                            class="button button-secondary"
                                        >
                                            Source Thread
                                        </a>

                                    <?php endif; ?>


                                    <?php if (
                                        $destinationThreadUrl !== null
                                        && $destinationThreadUrl !== $threadUrl
                                    ): ?>

                                        <a
                                            href="<?= e($destinationThreadUrl); ?>"
                                            class="button button-secondary"
                                        >
                                            Destination Thread
                                        </a>

                                    <?php endif; ?>

                                </div>

                            </div>

                        </article>

                    <?php endforeach; ?>

                </div>


                <nav
                    class="moderation-pagination"
                    aria-label="Moderation history pagination"
                >

                    <?php if ($previousPageUrl !== ''): ?>

                        <a
                            href="<?= e($previousPageUrl); ?>"
                            rel="prev"
                        >
                            Previous
                        </a>

                    <?php else: ?>

                        <span class="disabled">
                            Previous
                        </span>

                    <?php endif; ?>


                    <?php foreach ($pageLinks as $pageLink): ?>

                        <?php if ($pageLink['current']): ?>

                            <span
                                class="current"
                                aria-current="page"
                            >
                                <?= (int) $pageLink['number']; ?>
                            </span>

                        <?php else: ?>

                            <a
                                href="<?= e((string) $pageLink['url']); ?>"
                            >
                                <?= (int) $pageLink['number']; ?>
                            </a>

                        <?php endif; ?>

                    <?php endforeach; ?>


                    <?php if ($nextPageUrl !== ''): ?>

                        <a
                            href="<?= e($nextPageUrl); ?>"
                            rel="next"
                        >
                            Next
                        </a>

                    <?php else: ?>

                        <span class="disabled">
                            Next
                        </span>

                    <?php endif; ?>

                </nav>


            <?php endif; ?>

        </div>

    </section>

</main>

<?php

require
    INCLUDES_PATH
    . '/footer.php';

?>
