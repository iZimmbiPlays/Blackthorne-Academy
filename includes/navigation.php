<?php

declare(strict_types=1);

/**
 * Blackthorne Academy
 * Shared Navigation
 */

$navigationState =
    current_dashboard_type();

$currentPage =
    basename(
        (string) (
            $_SERVER['PHP_SELF']
            ?? ''
        )
    );


/*
|--------------------------------------------------------------------------
| Notification Bell Data
|--------------------------------------------------------------------------
|
| The shared bell shows the logged-in user's unread notifications. Forum
| reports receive a richer preview containing the report reason, reporter,
| and a direct link to the exact reported post.
|
*/

$navigationUserId =
    (int) (current_user_id() ?? 0);

$navigationUnreadNotificationCount = 0;
$navigationUnreadNotifications = [];

$navigationBookmarks = [];

if (
    $navigationState !== 'guest'
    && $navigationUserId > 0
) {
    /*
    |--------------------------------------------------------------------------
    | Backfill Unresolved Forum Report Notifications
    |--------------------------------------------------------------------------
    |
    | Reports created before moderation notifications were introduced may
    | already exist in forum_reports without a corresponding notification.
    | When an authorized staff member loads the navigation, create any
    | missing report notifications for that user exactly once.
    |
    */

    $navigationNotifyModeration = 1;

    $navigationPreferenceStatement = $pdo->prepare(
        'SELECT notify_moderation
         FROM notification_preferences
         WHERE user_id = :user_id
         LIMIT 1'
    );

    $navigationPreferenceStatement->execute([
        'user_id' => $navigationUserId,
    ]);

    $navigationPreferenceValue =
        $navigationPreferenceStatement->fetchColumn();

    if ($navigationPreferenceValue !== false) {
        $navigationNotifyModeration =
            (int) $navigationPreferenceValue;
    }

    if ($navigationNotifyModeration === 1) {
        $missingReportStatement = $pdo->prepare(
            "SELECT
                fr.id,
                fr.reported_by,
                fr.reason,
                fr.thread_id,
                fr.thread_title_snapshot,
                fr.post_id,
                ft.forum_id,
                reporter.display_name AS reporter_display_name
             FROM forum_reports fr
             INNER JOIN forum_threads ft
                ON ft.id = fr.thread_id
             LEFT JOIN users reporter
                ON reporter.id = fr.reported_by
             WHERE fr.status IN ('open', 'reviewing')
               AND NOT EXISTS (
                    SELECT 1
                    FROM notifications n
                    WHERE n.user_id = :notification_user_id
                      AND n.notification_type = 'moderation'
                      AND n.related_entity_type = 'forum_report'
                      AND n.related_entity_id = fr.id
               )
             ORDER BY fr.created_at ASC, fr.id ASC"
        );

        $missingReportStatement->execute([
            'notification_user_id' => $navigationUserId,
        ]);

        $missingReports =
            $missingReportStatement->fetchAll(
                PDO::FETCH_ASSOC
            );

        if ($missingReports !== []) {
            $backfillNotificationStatement = $pdo->prepare(
                "INSERT INTO notifications (
                    user_id,
                    actor_user_id,
                    notification_type,
                    related_entity_type,
                    related_entity_id,
                    title,
                    message,
                    link_url,
                    is_read
                 ) VALUES (
                    :user_id,
                    :actor_user_id,
                    'moderation',
                    'forum_report',
                    :related_entity_id,
                    :title,
                    :message,
                    :link_url,
                    0
                 )"
            );

            foreach ($missingReports as $missingReport) {
                $reportedBy =
                    (int) ($missingReport['reported_by'] ?? 0);

                $canReceiveReportNotification =
                    user_can_any([
                        'moderation.reports.view',
                        'moderation.reports.review',
                        'moderation.reports.resolve',
                        'moderation.reports.dismiss',
                    ]);

                if (
                    $reportedBy === $navigationUserId
                    || !$canReceiveReportNotification
                ) {
                    continue;
                }

                $reporterDisplayName =
                    trim(
                        (string) (
                            $missingReport['reporter_display_name']
                            ?? 'A member'
                        )
                    );

                if ($reporterDisplayName === '') {
                    $reporterDisplayName = 'A member';
                }

                $threadTitle =
                    trim(
                        (string) (
                            $missingReport['thread_title_snapshot']
                            ?? 'Forum thread'
                        )
                    );

                if ($threadTitle === '') {
                    $threadTitle = 'Forum thread';
                }

                $reportReason =
                    trim(
                        (string) (
                            $missingReport['reason']
                            ?? 'No reason supplied'
                        )
                    );

                $reportedPostId =
                    (int) ($missingReport['post_id'] ?? 0);

                $backfillLink =
                    url('admin/reports.php')
                    . '?status=all#report-'
                    . (int) $missingReport['id'];

                $backfillNotificationStatement->execute([
                    'user_id' => $navigationUserId,
                    'actor_user_id' =>
                        $reportedBy > 0
                            ? $reportedBy
                            : null,
                    'related_entity_id' =>
                        (int) $missingReport['id'],
                    'title' => 'New forum report',
                    'message' =>
                        $reporterDisplayName
                        . ' reported a post in “'
                        . $threadTitle
                        . '” for '
                        . $reportReason
                        . '.',
                    'link_url' => $backfillLink,
                ]);
            }
        }
    }

    $notificationCountStatement = $pdo->prepare(
        "SELECT COUNT(*)
         FROM notifications
         WHERE user_id = :user_id
           AND is_read = 0"
    );

    $notificationCountStatement->execute([
        'user_id' => $navigationUserId,
    ]);

    $navigationUnreadNotificationCount =
        (int) $notificationCountStatement->fetchColumn();

    $notificationPreviewStatement = $pdo->prepare(
        "SELECT
            n.id,
            n.notification_type,
            n.related_entity_type,
            n.related_entity_id,
            n.title,
            n.message,
            n.link_url,
            n.created_at,
            fr.reason AS report_reason,
            fr.thread_title_snapshot AS report_thread_title,
            fr.post_id AS reported_post_id,
            reporter.display_name AS reporter_display_name
         FROM notifications n
         LEFT JOIN forum_reports fr
            ON n.related_entity_type = 'forum_report'
           AND fr.id = n.related_entity_id
         LEFT JOIN users reporter
            ON reporter.id = fr.reported_by
         WHERE n.user_id = :user_id
           AND n.is_read = 0
         ORDER BY n.created_at DESC, n.id DESC
         LIMIT 8"
    );

    $notificationPreviewStatement->execute([
        'user_id' => $navigationUserId,
    ]);

    $navigationUnreadNotifications =
        $notificationPreviewStatement->fetchAll(
            PDO::FETCH_ASSOC
        );


    /*
    |--------------------------------------------------------------------------
    | Personal Bookmark Dropdown
    |--------------------------------------------------------------------------
    |
    | These are the user's private custom bookmarks from user_bookmarks.
    | Thread-watch subscriptions remain separate in thread_bookmarks.
    |
    */

    $navigationBookmarksStatement =
        $pdo->prepare(
            'SELECT
                id,
                title,
                bookmark_url,
                sort_order
             FROM user_bookmarks
             WHERE user_id = :user_id
             ORDER BY
                sort_order ASC,
                title ASC,
                id ASC
             LIMIT 10'
        );

    $navigationBookmarksStatement->execute([
        'user_id' =>
            $navigationUserId,
    ]);

    $navigationBookmarks =
        $navigationBookmarksStatement->fetchAll(
            PDO::FETCH_ASSOC
        );


}


/*
|--------------------------------------------------------------------------
| Active-Link Helper
|--------------------------------------------------------------------------
*/

function navigation_link_attributes(
    array $pages,
    string $currentPage
): string {

    if (
        in_array(
            $currentPage,
            $pages,
            true
        )
    ) {

        return
            ' aria-current="page"';

    }


    return '';

}


/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
|
| Search remains available in both public and authenticated navigation.
|
*/

$searchButton = <<<HTML

<button
    class="nav-icon search-toggle"
    type="button"
    aria-label="Search Blackthorne Academy"
    aria-expanded="false"
    aria-controls="header-search"
    title="Search"
>
    <svg
        viewBox="0 0 24 24"
        aria-hidden="true"
        focusable="false"
    >
        <path
            d="M10.75 4a6.75 6.75 0 1 0 4.24 12l4.5 4.5 1.41-1.41-4.5-4.5A6.75 6.75 0 0 0 10.75 4Zm0 2a4.75 4.75 0 1 1 0 9.5 4.75 4.75 0 0 1 0-9.5Z"
        />
    </svg>
</button>

HTML;


/*
|--------------------------------------------------------------------------
| Navigation Shell
|--------------------------------------------------------------------------
*/

?>

<nav
    class="site-navigation<?= $navigationState === 'guest'
        ? ''
        : ' site-navigation-authenticated'; ?>"
    aria-label="Primary navigation"
>

    <button
        class="mobile-menu-toggle"
        type="button"
        aria-label="Open navigation"
        aria-expanded="false"
        aria-controls="primary-menu"
    >
        <span></span>
        <span></span>
        <span></span>
    </button>


    <div
        class="primary-menu<?= $navigationState === 'guest'
            ? ''
            : ' authenticated-menu'; ?>"
        id="primary-menu"
    >


        <?php if (
            $navigationState === 'guest'
        ): ?>


            <!-- ========================================================
                 Logged-Out Navigation
            ========================================================= -->

            <a
                href="<?= e(HOME_URL); ?>"
                class="nav-link"
                <?= navigation_link_attributes(
                    ['index.php'],
                    $currentPage
                ); ?>
            >
                Home
            </a>


            <a
                href="<?= e(url('features.php')); ?>"
                class="nav-link"
                <?= navigation_link_attributes(
                    ['features.php'],
                    $currentPage
                ); ?>
            >
                Features
            </a>


            <a
                href="<?= e(url('about.php')); ?>"
                class="nav-link"
                <?= navigation_link_attributes(
                    ['about.php'],
                    $currentPage
                ); ?>
            >
                About
            </a>


            <a
                href="<?= e(url('contact.php')); ?>"
                class="nav-link"
                <?= navigation_link_attributes(
                    ['contact.php'],
                    $currentPage
                ); ?>
            >
                Contact
            </a>


            <a
                href="<?= e(LOGIN_URL); ?>"
                class="nav-link"
                <?= navigation_link_attributes(
                    ['login.php'],
                    $currentPage
                ); ?>
            >
                Login
            </a>


            <a
                href="<?= e(REGISTER_URL); ?>"
                class="nav-link nav-enroll"
                <?= navigation_link_attributes(
                    ['register.php'],
                    $currentPage
                ); ?>
            >
                Enroll
            </a>


            <?= $searchButton; ?>


        <?php else: ?>


            <!-- ========================================================
                 Logged-In Text Navigation
            ========================================================= -->

            <div class="authenticated-menu-links">

                <a
                    href="<?= e(HOME_URL); ?>"
                    class="nav-link"
                    <?= navigation_link_attributes(
                        ['index.php'],
                        $currentPage
                    ); ?>
                >
                    Home
                </a>


                <a
                    href="<?= e(url('news.php')); ?>"
                    class="nav-link"
                    <?= navigation_link_attributes(
                        ['news.php'],
                        $currentPage
                    ); ?>
                >
                    News
                </a>


                <a
                    href="<?= e(url('courses.php')); ?>"
                    class="nav-link"
                    <?= navigation_link_attributes(
                        ['courses.php', 'course.php'],
                        $currentPage
                    ); ?>
                >
                    Classes
                </a>


                <a
                    href="<?= e(DASHBOARD_URL); ?>"
                    class="nav-link"
                    <?= navigation_link_attributes(
                        ['dashboard.php'],
                        $currentPage
                    ); ?>
                >
                    Dashboard
                </a>

            </div>


            <!-- ========================================================
                 Logged-In Icon Navigation
            ========================================================= -->

            <div
                class="authenticated-menu-icons"
                aria-label="Account navigation"
            >

                <?= $searchButton; ?>


                <!-- Profile -->

                <div class="nav-action">

                    <a
                        href="<?= e(url('profile.php?u=me')); ?>"
                        class="nav-icon nav-account-icon"
                        aria-label="My Profile"
                        title="My Profile"
                        <?= navigation_link_attributes(
                            ['profile.php'],
                            $currentPage
                        ); ?>
                    >
                        <svg
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                            focusable="false"
                        >
                            <path
                                d="M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9Zm0 2c-4.14 0-7.5 2.46-7.5 5.5V21h15v-1.5C19.5 16.46 16.14 14 12 14Z"
                            />
                        </svg>

                        <span
                            class="nav-notification-badge"
                            data-notification-badge="profile"
                            hidden
                        ></span>
                    </a>


                    <div
                        class="nav-notification-popover"
                        data-notification-popover="profile"
                        hidden
                    ></div>

                </div>


                <!-- Notifications -->

                <div class="nav-action">

                    <a
                        href="<?= e(url('notifications.php')); ?>"
                        class="nav-icon nav-account-icon"
                        aria-label="Notifications"
                        title="Notifications"
                        <?= navigation_link_attributes(
                            ['notifications.php'],
                            $currentPage
                        ); ?>
                    >
                        <svg
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                            focusable="false"
                        >
                            <path
                                d="M12 22a2.3 2.3 0 0 0 2.24-1.8H9.76A2.3 2.3 0 0 0 12 22Zm7-5.1-1.7-2.08V10a5.35 5.35 0 0 0-4.3-5.25V4a1 1 0 0 0-2 0v.75A5.35 5.35 0 0 0 6.7 10v4.82L5 16.9V18h14v-1.1Z"
                            />
                        </svg>

                        <span
                            class="nav-notification-badge"
                            data-notification-badge="notifications"
                            <?= $navigationUnreadNotificationCount > 0
                                ? ''
                                : 'hidden'; ?>
                        >
                            <?= $navigationUnreadNotificationCount > 99
                                ? '99+'
                                : $navigationUnreadNotificationCount; ?>
                        </span>
                    </a>


                    <div
                        class="nav-notification-popover nav-notification-popover-rich"
                        data-notification-popover="notifications"
                        hidden
                        role="region"
                        aria-label="Unread notifications"
                    >
                        <div class="nav-notification-popover-heading">
                            <span>Notifications</span>

                            <?php if ($navigationUnreadNotificationCount > 0): ?>
                                <span class="nav-notification-popover-count">
                                    <?= $navigationUnreadNotificationCount; ?> unread
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php if ($navigationUnreadNotifications !== []): ?>

                            <div class="nav-notification-list">

                                <?php foreach ($navigationUnreadNotifications as $navNotification): ?>
                                    <?php
                                    $isForumReport =
                                        (string) ($navNotification['related_entity_type'] ?? '')
                                        === 'forum_report';

                                    $isForumActivity =
                                        (string) ($navNotification['notification_type'] ?? '')
                                        === 'forum_reply'
                                        || (string) ($navNotification['notification_type'] ?? '')
                                        === 'bookmarked_thread';

                                    $notificationHref =
                                        url(
                                            'notification.php?n='
                                            . (int) $navNotification['id']
                                        );

                                    $compactTitle =
                                        trim(
                                            (string) (
                                                $navNotification['title']
                                                ?? 'Forum thread'
                                            )
                                        );

                                    if ($compactTitle === '') {
                                        $compactTitle = 'Forum thread';
                                    }

                                    $compactMessage =
                                        trim(
                                            (string) (
                                                $navNotification['message']
                                                ?? ''
                                            )
                                        );

                                    $compactMessageLower =
                                        strtolower($compactMessage);

                                    $isWatchedActivity =
                                        str_contains(
                                            $compactMessageLower,
                                            'watched thread'
                                        )
                                        || str_contains(
                                            $compactMessageLower,
                                            'has had activity'
                                        );
                                    ?>

                                    <?php if ($isForumReport): ?>

                                        <a
                                            href="<?= e($notificationHref); ?>"
                                            class="nav-notification-item is-moderation-report"
                                        >
                                            <span class="nav-notification-item-type">
                                                Reported Post
                                            </span>

                                            <span class="nav-notification-item-title">
                                                <?= e(
                                                    trim(
                                                        (string) (
                                                            $navNotification['report_thread_title']
                                                            ?? 'Forum post'
                                                        )
                                                    )
                                                ); ?>
                                            </span>

                                            <span class="nav-notification-item-meta">
                                                <strong>Reason:</strong>
                                                <?= e(
                                                    (string) (
                                                        $navNotification['report_reason']
                                                        ?? 'Not specified'
                                                    )
                                                ); ?>
                                            </span>

                                            <span class="nav-notification-item-meta">
                                                <strong>Reported by:</strong>
                                                <?= e(
                                                    (string) (
                                                        $navNotification['reporter_display_name']
                                                        ?? 'Member'
                                                    )
                                                ); ?>
                                            </span>
                                        </a>

                                    <?php elseif ($isForumActivity): ?>

                                        <div class="nav-notification-item nav-notification-item-compact">

                                            <?php if ($isWatchedActivity): ?>

                                                <span class="nav-notification-compact-text">
                                                    The thread,
                                                    <a
                                                        href="<?= e($notificationHref); ?>"
                                                        class="nav-notification-inline-link"
                                                    >
                                                        <?= e($compactTitle); ?>
                                                    </a>,
                                                    has had activity.
                                                </span>

                                            <?php elseif (
                                                str_contains(
                                                    $compactMessageLower,
                                                    'quoted and mentioned'
                                                )
                                            ): ?>

                                                <span class="nav-notification-compact-text">
                                                    You were quoted and mentioned in
                                                    <a
                                                        href="<?= e($notificationHref); ?>"
                                                        class="nav-notification-inline-link"
                                                    >
                                                        <?= e($compactTitle); ?>
                                                    </a>.
                                                </span>

                                            <?php elseif (
                                                str_contains(
                                                    $compactMessageLower,
                                                    'quoted'
                                                )
                                            ): ?>

                                                <span class="nav-notification-compact-text">
                                                    You were quoted in
                                                    <a
                                                        href="<?= e($notificationHref); ?>"
                                                        class="nav-notification-inline-link"
                                                    >
                                                        <?= e($compactTitle); ?>
                                                    </a>.
                                                </span>

                                            <?php elseif (
                                                str_contains(
                                                    $compactMessageLower,
                                                    'mentioned'
                                                )
                                            ): ?>

                                                <span class="nav-notification-compact-text">
                                                    You were mentioned in
                                                    <a
                                                        href="<?= e($notificationHref); ?>"
                                                        class="nav-notification-inline-link"
                                                    >
                                                        <?= e($compactTitle); ?>
                                                    </a>.
                                                </span>

                                            <?php else: ?>

                                                <a
                                                    href="<?= e($notificationHref); ?>"
                                                    class="nav-notification-compact-link"
                                                >
                                                    <strong>
                                                        <?= e($compactTitle); ?>
                                                    </strong>

                                                    <?php if ($compactMessage !== ''): ?>
                                                        <span>
                                                            <?= e($compactMessage); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </a>

                                            <?php endif; ?>

                                        </div>

                                    <?php else: ?>

                                        <a
                                            href="<?= e($notificationHref); ?>"
                                            class="nav-notification-item"
                                        >
                                            <span class="nav-notification-item-title">
                                                <?= e((string) $navNotification['title']); ?>
                                            </span>

                                            <?php if (
                                                trim(
                                                    (string) (
                                                        $navNotification['message']
                                                        ?? ''
                                                    )
                                                ) !== ''
                                            ): ?>
                                                <span class="nav-notification-item-message">
                                                    <?= e((string) $navNotification['message']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </a>

                                    <?php endif; ?>

                                <?php endforeach; ?>

                            </div>

                        <?php else: ?>

                            <p class="nav-notification-empty">
                                You have no unread notifications.
                            </p>

                        <?php endif; ?>


                        <div class="nav-notification-popover-actions">

                            <a
                                href="<?= e(url('notifications.php')); ?>"
                                class="nav-notification-popover-action"
                            >
                                View All Notifications
                            </a>

                            <?php if ($navigationUnreadNotificationCount > 0): ?>

                                <form
                                    method="post"
                                    action="<?= e(url('notifications.php')); ?>"
                                    class="nav-notification-clear-form"
                                >
                                    <?= csrf_field(); ?>

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="mark_all_read"
                                    >

                                    <button
                                        type="submit"
                                        class="nav-notification-popover-action"
                                    >
                                        Clear All
                                    </button>
                                </form>

                            <?php endif; ?>

                        </div>

                    </div>

                </div>


                <!-- Messages -->

                <div class="nav-action">

                    <a
                        href="<?= e(url('messages.php')); ?>"
                        class="nav-icon nav-account-icon"
                        aria-label="Messages"
                        title="Messages"
                        <?= navigation_link_attributes(
                            ['messages.php'],
                            $currentPage
                        ); ?>
                    >
                        <svg
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                            focusable="false"
                        >
                            <path
                                d="M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H9l-5 3v-3a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Zm0 3v.32l8 5 8-5V7l-8 5-8-5Z"
                            />
                        </svg>

                        <span
                            class="nav-notification-badge"
                            data-notification-badge="messages"
                            hidden
                        ></span>
                    </a>


                    <div
                        class="nav-notification-popover"
                        data-notification-popover="messages"
                        hidden
                    ></div>

                </div>


                <!-- Bookmarks -->

                <div class="nav-action">

                    <button
                        type="button"
                        class="nav-icon nav-account-icon"
                        aria-label="Bookmarks"
                        aria-expanded="false"
                        aria-controls="navigation-bookmark-popover"
                        title="Bookmarks"
                        data-bookmark-toggle
                    >
                        <svg
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                            focusable="false"
                        >
                            <path
                                d="M6 3a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v19l-6-3.8L6 22V3Zm2 0v15.36l4-2.54 4 2.54V3H8Z"
                            />
                        </svg>
                    </button>


                    <div
                        class="nav-notification-popover nav-notification-popover-rich"
                        id="navigation-bookmark-popover"
                        data-notification-popover="bookmarks"
                        hidden
                        role="region"
                        aria-label="Saved bookmarks"
                    >

                        <div class="nav-notification-popover-heading">
                            <span>Bookmarks</span>

                            <?php if ($navigationBookmarks !== []): ?>
                                <span class="nav-notification-popover-count">
                                    <?= count($navigationBookmarks); ?>
                                    saved
                                </span>
                            <?php endif; ?>
                        </div>


                        <?php if ($navigationBookmarks !== []): ?>

                            <div class="nav-notification-list">

                                <?php foreach ($navigationBookmarks as $navigationBookmark): ?>

                                    <?php
                                    $navigationBookmarkTitle =
                                        trim(
                                            (string) (
                                                $navigationBookmark['title']
                                                ?? 'Bookmark'
                                            )
                                        );

                                    if ($navigationBookmarkTitle === '') {
                                        $navigationBookmarkTitle =
                                            'Bookmark';
                                    }

                                    $navigationBookmarkUrl =
                                        trim(
                                            (string) (
                                                $navigationBookmark['bookmark_url']
                                                ?? ''
                                            )
                                        );

                                    if ($navigationBookmarkUrl === '') {
                                        $navigationBookmarkUrl =
                                            url('bookmarks.php');
                                    }
                                    ?>

                                    <a
                                        href="<?= e($navigationBookmarkUrl); ?>"
                                        class="nav-notification-item nav-notification-item-compact"
                                    >
                                        <span class="nav-notification-item-title">
                                            <?= e($navigationBookmarkTitle); ?>
                                        </span>
                                    </a>

                                <?php endforeach; ?>

                            </div>

                        <?php else: ?>

                            <p class="nav-notification-empty">
                                You have no saved bookmarks yet.
                            </p>

                        <?php endif; ?>


                        <div class="nav-notification-popover-actions">

                            <a
                                href="<?= e(url('bookmarks.php')); ?>"
                                class="nav-notification-popover-action"
                            >
                                View All Bookmarks
                            </a>

                        </div>

                    </div>

                </div>


                <!-- Logout -->

                <form
                    class="nav-logout-form"
                    action="<?= e(url('logout.php')); ?>"
                    method="post"
                >

                    <?= csrf_field(); ?>


                    <button
                        class="nav-icon nav-account-icon nav-logout-button"
                        type="submit"
                        aria-label="Logout"
                        title="Logout"
                    >
                        <svg
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                            focusable="false"
                        >
                            <path
                                d="M11 2h2v10h-2V2Zm5.66 3.34 1.42-1.42A9 9 0 1 1 5.92 3.92l1.42 1.42A7 7 0 1 0 16.66 5.34Z"
                            />
                        </svg>
                    </button>

                </form>

            </div>


        <?php endif; ?>


    </div>


    <!-- ================================================================
         Shared Search Panel
    ================================================================= -->

    <div
        class="header-search"
        id="header-search"
        hidden
    >

        <label
            class="sr-only"
            for="academy-search"
        >
            Search Blackthorne Academy
        </label>


        <input
            type="search"
            id="academy-search"
            name="q"
            placeholder="Search Blackthorne Academy..."
            autocomplete="off"
        >


        <button
            type="button"
            class="search-close"
            aria-label="Close search"
        >
            &times;
        </button>

    </div>

<script>
(() => {
    'use strict';

    const popoverNames = [
        'notifications',
        'bookmarks',
    ];

    popoverNames.forEach((popoverName) => {
        const action =
            document.querySelector(
                `[data-notification-popover="${popoverName}"]`
            )?.closest('.nav-action');

        if (!action) {
            return;
        }

        const popover =
            action.querySelector(
                `[data-notification-popover="${popoverName}"]`
            );

        if (!popover) {
            return;
        }

        let closeTimer = null;

        const toggleButton =
            action.querySelector(
                '[data-bookmark-toggle]'
            );

        const openPopover = () => {
            if (closeTimer !== null) {
                window.clearTimeout(closeTimer);
                closeTimer = null;
            }

            popover.hidden = false;

            if (toggleButton) {
                toggleButton.setAttribute(
                    'aria-expanded',
                    'true'
                );
            }
        };

        const closePopover = () => {
            popover.hidden = true;

            if (toggleButton) {
                toggleButton.setAttribute(
                    'aria-expanded',
                    'false'
                );
            }
        };

        const scheduleClose = () => {
            if (closeTimer !== null) {
                window.clearTimeout(closeTimer);
            }

            closeTimer = window.setTimeout(
                () => {
                    if (!action.matches(':hover')
                        && !action.matches(':focus-within')) {
                        closePopover();
                    }
                },
                140
            );
        };

        action.addEventListener(
            'mouseenter',
            openPopover
        );

        action.addEventListener(
            'mouseleave',
            scheduleClose
        );

        action.addEventListener(
            'focusin',
            openPopover
        );

        action.addEventListener(
            'focusout',
            scheduleClose
        );

        if (toggleButton) {
            toggleButton.addEventListener(
                'click',
                (event) => {
                    event.preventDefault();

                    if (popover.hidden) {
                        openPopover();
                    } else {
                        closePopover();
                    }
                }
            );
        }
    });
})();
</script>

</nav>
