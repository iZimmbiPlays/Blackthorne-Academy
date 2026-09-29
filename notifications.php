<?php

declare(strict_types=1);

/**
 * Blackthorne Academy
 * Notification History
 *
 * Upload path:
 * /notifications.php
 */

require_once __DIR__ . '/includes/bootstrap.php';

require_login();
require_active_account();


$user =
    current_user();

if ($user === null) {
    redirect(
        LOGIN_URL
    );
}


$userId =
    (int) (
        $user['id']
        ?? current_user_id()
        ?? 0
    );

if ($userId <= 0) {
    redirect(
        LOGIN_URL
    );
}


/*
|--------------------------------------------------------------------------
| Actions
|--------------------------------------------------------------------------
|
| The navigation notification dropdown posts "mark_all_read" here when the
| user presses Clear All. The history page also exposes the same action.
|
*/

if (is_post()) {
    require_valid_csrf();

    $action =
        trim(
            (string) (
                $_POST['action']
                ?? ''
            )
        );

    if ($action === 'mark_all_read') {
        $markAllReadStatement =
            $pdo->prepare(
                'UPDATE notifications

                 SET
                    is_read = 1,
                    read_at = COALESCE(
                        read_at,
                        CURRENT_TIMESTAMP
                    )

                 WHERE user_id = :user_id
                   AND is_read = 0'
            );

        $markAllReadStatement->execute([
            'user_id' =>
                $userId,
        ]);

        $markedCount =
            $markAllReadStatement->rowCount();

        if ($markedCount > 0) {
            set_flash(
                'success',
                $markedCount === 1
                    ? '1 notification marked as read.'
                    : number_format(
                        $markedCount
                    )
                    . ' notifications marked as read.'
            );

        } else {
            set_flash(
                'success',
                'You have no unread notifications.'
            );
        }

        redirect(
            url(
                'notifications.php'
            )
        );
    }

    set_flash(
        'error',
        'That notification action was not recognized.'
    );

    redirect(
        url(
            'notifications.php'
        )
    );
}


/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$filter =
    strtolower(
        trim(
            (string) (
                $_GET['filter']
                ?? 'all'
            )
        )
    );

if (
    !in_array(
        $filter,
        [
            'all',
            'unread',
            'read',
        ],
        true
    )
) {
    $filter =
        'all';
}


/*
|--------------------------------------------------------------------------
| Counts
|--------------------------------------------------------------------------
*/

$countStatement =
    $pdo->prepare(
        'SELECT
            COUNT(*) AS total_count,
            COALESCE(
                SUM(
                    CASE
                        WHEN is_read = 0
                            THEN 1
                        ELSE 0
                    END
                ),
                0
            ) AS unread_count,
            COALESCE(
                SUM(
                    CASE
                        WHEN is_read = 1
                            THEN 1
                        ELSE 0
                    END
                ),
                0
            ) AS read_count

         FROM notifications

         WHERE user_id = :user_id'
    );

$countStatement->execute([
    'user_id' =>
        $userId,
]);

$counts =
    $countStatement->fetch(
        PDO::FETCH_ASSOC
    );

$totalNotificationCount =
    (int) (
        $counts['total_count']
        ?? 0
    );

$unreadNotificationCount =
    (int) (
        $counts['unread_count']
        ?? 0
    );

$readNotificationCount =
    (int) (
        $counts['read_count']
        ?? 0
    );


/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$perPage =
    25;

$currentPage =
    max(
        1,
        (int) (
            $_GET['page']
            ?? 1
        )
    );


$whereSql =
    'WHERE n.user_id = :user_id';

if ($filter === 'unread') {
    $whereSql .=
        ' AND n.is_read = 0';

} elseif ($filter === 'read') {
    $whereSql .=
        ' AND n.is_read = 1';
}


$filteredCountStatement =
    $pdo->prepare(
        'SELECT COUNT(*)

         FROM notifications n

         '
        . $whereSql
    );

$filteredCountStatement->execute([
    'user_id' =>
        $userId,
]);

$filteredCount =
    (int) $filteredCountStatement
        ->fetchColumn();


$totalPages =
    max(
        1,
        (int) ceil(
            $filteredCount
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
| Notification History
|--------------------------------------------------------------------------
*/

$notificationSql =
    'SELECT
        n.id,
        n.actor_user_id,
        n.notification_type,
        n.related_entity_type,
        n.related_entity_id,
        n.title,
        n.message,
        n.link_url,
        n.is_read,
        n.read_at,
        n.created_at,

        actor.display_name
            AS actor_display_name,

        actor.username
            AS actor_username,

        actor.avatar
            AS actor_avatar

     FROM notifications n

     LEFT JOIN users actor
        ON actor.id = n.actor_user_id

     '
    . $whereSql
    . '

     ORDER BY
        n.created_at DESC,
        n.id DESC

     LIMIT :notification_limit
     OFFSET :notification_offset';


$notificationStatement =
    $pdo->prepare(
        $notificationSql
    );

$notificationStatement->bindValue(
    ':user_id',
    $userId,
    PDO::PARAM_INT
);

$notificationStatement->bindValue(
    ':notification_limit',
    $perPage,
    PDO::PARAM_INT
);

$notificationStatement->bindValue(
    ':notification_offset',
    $offset,
    PDO::PARAM_INT
);

$notificationStatement->execute();

$notifications =
    $notificationStatement->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Display Helpers
|--------------------------------------------------------------------------
*/

function blackthorne_notification_type_label(
    string $notificationType
): string {
    return match ($notificationType) {
        'announcement' =>
            'Announcement',

        'assignment_graded' =>
            'Assignment',

        'assessment_graded' =>
            'Assessment',

        'forum_reply' =>
            'Forum',

        'bookmarked_thread' =>
            'Watched Thread',

        'lesson_unlocked' =>
            'Lesson',

        'achievement_earned' =>
            'Achievement',

        'course_update' =>
            'Course',

        'private_message' =>
            'Message',

        'house_points' =>
            'House Points',

        'moderation' =>
            'Moderation',

        'poll' =>
            'Poll',

        'friend_request' =>
            'Friend Request',

        'friend_accepted' =>
            'Friends',

        'system' =>
            'System',

        default =>
            'Notification',
    };
}


function blackthorne_notification_time(
    ?string $createdAt
): string {
    $createdAt =
        trim(
            (string) $createdAt
        );

    if ($createdAt === '') {
        return '';
    }

    try {
        $date =
            new DateTimeImmutable(
                $createdAt
            );

        return
            $date->format(
                'M j, Y \a\t g:i A'
            );

    } catch (Throwable) {
        return $createdAt;
    }
}


/*
|--------------------------------------------------------------------------
| Flash Messages
|--------------------------------------------------------------------------
*/

$successMessage =
    get_flash(
        'success'
    );

$errorMessage =
    get_flash(
        'error'
    );


/*
|--------------------------------------------------------------------------
| Metadata
|--------------------------------------------------------------------------
*/

$pageTitle =
    'Notifications | Blackthorne Academy';

$pageDescription =
    'View your Blackthorne Academy notification history.';

$robots =
    'noindex, nofollow';


require
    INCLUDES_PATH
    . '/header.php';

?>


<main id="main-content" class="notifications-page">

    <section class="notifications-page-header">

        <div class="section-inner">

            <div class="notifications-page-heading-row">

                <div>

                    <p class="academy-overline">
                        Account
                    </p>

                    <h1>
                        Notifications
                    </h1>

                    <p class="notifications-page-summary">
                        Keep up with forum activity, friend requests,
                        Academy updates, moderation notices, and more.
                    </p>

                </div>


                <?php if ($unreadNotificationCount > 0): ?>

                <form method="post" action="<?= e(url('notifications.php')); ?>" class="notifications-mark-all-form">
                    <?= csrf_field(); ?>

                    <input type="hidden" name="action" value="mark_all_read">

                    <button type="submit" class="button button-secondary">
                        Mark All Read
                    </button>
                </form>

                <?php endif; ?>

            </div>


            <div class="notifications-page-counts">

                <span>
                    <strong>
                        <?= number_format($totalNotificationCount); ?>
                    </strong>
                    Total
                </span>

                <span>
                    <strong>
                        <?= number_format($unreadNotificationCount); ?>
                    </strong>
                    Unread
                </span>

                <span>
                    <strong>
                        <?= number_format($readNotificationCount); ?>
                    </strong>
                    Read
                </span>

            </div>

        </div>

    </section>


    <section class="notifications-page-content">

        <div class="section-inner">

            <?php if ($successMessage !== null): ?>

            <div class="notifications-page-message is-success" role="status">
                <?= e($successMessage); ?>
            </div>

            <?php endif; ?>


            <?php if ($errorMessage !== null): ?>

            <div class="notifications-page-message is-error" role="alert">
                <?= e($errorMessage); ?>
            </div>

            <?php endif; ?>


            <nav class="notifications-filter-nav" aria-label="Notification filters">

                <a href="<?= e(url('notifications.php?filter=all')); ?>"
                    class="notifications-filter-link<?= $filter === 'all' ? ' is-active' : ''; ?>" <?= $filter === 'all'
                        ? 'aria-current="page"'
                        : ''; ?>>
                    All
                    <span>
                        <?= number_format($totalNotificationCount); ?>
                    </span>
                </a>

                <a href="<?= e(url('notifications.php?filter=unread')); ?>"
                    class="notifications-filter-link<?= $filter === 'unread' ? ' is-active' : ''; ?>" <?= $filter === 'unread'
                        ? 'aria-current="page"'
                        : ''; ?>>
                    Unread
                    <span>
                        <?= number_format($unreadNotificationCount); ?>
                    </span>
                </a>

                <a href="<?= e(url('notifications.php?filter=read')); ?>"
                    class="notifications-filter-link<?= $filter === 'read' ? ' is-active' : ''; ?>" <?= $filter === 'read'
                        ? 'aria-current="page"'
                        : ''; ?>>
                    Read
                    <span>
                        <?= number_format($readNotificationCount); ?>
                    </span>
                </a>

            </nav>


            <?php if ($notifications !== []): ?>

            <div class="notifications-history-list">

                <?php foreach ($notifications as $notification): ?>

                <?php
                        $notificationId =
                            (int) (
                                $notification['id']
                                ?? 0
                            );

                        $isUnread =
                            (int) (
                                $notification['is_read']
                                ?? 0
                            ) !== 1;

                        $notificationType =
                            (string) (
                                $notification[
                                    'notification_type'
                                ]
                                ?? 'system'
                            );

                        $notificationTitle =
                            trim(
                                (string) (
                                    $notification['title']
                                    ?? ''
                                )
                            );

                        if ($notificationTitle === '') {
                            $notificationTitle =
                                blackthorne_notification_type_label(
                                    $notificationType
                                );
                        }

                        $notificationMessage =
                            trim(
                                (string) (
                                    $notification['message']
                                    ?? ''
                                )
                            );

                        $notificationTime =
                            blackthorne_notification_time(
                                isset(
                                    $notification['created_at']
                                )
                                    ? (string) $notification['created_at']
                                    : null
                            );

                        $notificationHref =
                            url(
                                'notification.php?n='
                                . $notificationId
                            );
                        ?>

                <a href="<?= e($notificationHref); ?>"
                    class="notifications-history-item<?= $isUnread ? ' is-unread' : ' is-read'; ?>">

                    <span class="notifications-history-indicator">

                        <?php if ($isUnread): ?>

                        <span class="notifications-unread-dot" aria-label="Unread notification"></span>

                        <?php endif; ?>

                    </span>


                    <span class="notifications-history-main">

                        <span class="notifications-history-topline">

                            <span class="notifications-history-type">
                                <?= e(
                                            blackthorne_notification_type_label(
                                                $notificationType
                                            )
                                        ); ?>
                            </span>

                            <?php if ($notificationTime !== ''): ?>

                            <time class="notifications-history-time" datetime="<?= e(
                                                (string) (
                                                    $notification['created_at']
                                                    ?? ''
                                                )
                                            ); ?>">
                                <?= e($notificationTime); ?>
                            </time>

                            <?php endif; ?>

                        </span>


                        <strong class="notifications-history-title">
                            <?= e($notificationTitle); ?>
                        </strong>


                        <?php if ($notificationMessage !== ''): ?>

                        <span class="notifications-history-message">
                            <?= e($notificationMessage); ?>
                        </span>

                        <?php endif; ?>

                    </span>


                    <span class="notifications-history-arrow" aria-hidden="true">
                        ›
                    </span>

                </a>

                <?php endforeach; ?>

            </div>


            <?php if ($totalPages > 1): ?>

            <nav class="notifications-pagination" aria-label="Notification history pages">

                <?php if ($currentPage > 1): ?>

                <a class="button button-secondary" href="<?= e(
                                    url(
                                        'notifications.php?filter='
                                        . rawurlencode(
                                            $filter
                                        )
                                        . '&page='
                                        . ($currentPage - 1)
                                    )
                                ); ?>">
                    Previous
                </a>

                <?php endif; ?>


                <span class="notifications-pagination-status">
                    Page
                    <?= number_format($currentPage); ?>
                    of
                    <?= number_format($totalPages); ?>
                </span>


                <?php if ($currentPage < $totalPages): ?>

                <a class="button button-secondary" href="<?= e(
                                    url(
                                        'notifications.php?filter='
                                        . rawurlencode(
                                            $filter
                                        )
                                        . '&page='
                                        . ($currentPage + 1)
                                    )
                                ); ?>">
                    Next
                </a>

                <?php endif; ?>

            </nav>

            <?php endif; ?>

            <?php else: ?>

            <div class="notifications-empty-state">

                <p class="academy-overline">
                    All Quiet
                </p>

                <h2>
                    <?php if ($filter === 'unread'): ?>
                    No Unread Notifications
                    <?php elseif ($filter === 'read'): ?>
                    No Read Notifications
                    <?php else: ?>
                    No Notifications Yet
                    <?php endif; ?>
                </h2>

                <p>
                    <?php if ($filter === 'all'): ?>
                    Your Academy activity will appear here when
                    there is something new to see.
                    <?php else: ?>
                    There are no notifications in this view.
                    <?php endif; ?>
                </p>

                <?php if ($filter !== 'all'): ?>

                <a class="button button-secondary" href="<?= e(url('notifications.php')); ?>">
                    View All Notifications
                </a>

                <?php endif; ?>

            </div>

            <?php endif; ?>

        </div>

    </section>

</main>


<?php

require
    INCLUDES_PATH
    . '/footer.php';
