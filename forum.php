<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/forum-functions.php';
require_once INCLUDES_PATH . '/forum-image-map-functions.php';

require_active_account();


/*
|--------------------------------------------------------------------------
| Blackthorne Academy
| Board / Sub-board Page
|--------------------------------------------------------------------------
|
| One dynamic page powers every forum and sub-forum. Creating a new board in
| the database automatically makes it available through this file.
|
*/

$userId =
    (int) current_user_id();


/*
|--------------------------------------------------------------------------
| Local Helpers
|--------------------------------------------------------------------------
*/

function blackthorne_forum_avatar_url(?string $avatar): ?string
{
    $avatar =
        trim(
            (string) $avatar
        );

    if ($avatar === '') {
        return null;
    }

    if (
        preg_match(
            '~^https?://~i',
            $avatar
        ) === 1
    ) {
        return $avatar;
    }

    if (
        str_starts_with(
            $avatar,
            '/'
        )
    ) {
        return $avatar;
    }

    return url(
        ltrim(
            $avatar,
            '/'
        )
    );
}


function blackthorne_forum_initials(string $name): string
{
    $name =
        trim(
            $name
        );

    if ($name === '') {
        return '?';
    }

    $parts =
        preg_split(
            '/\s+/u',
            $name
        )
        ?: [];

    $initials =
        '';

    foreach (
        array_slice(
            $parts,
            0,
            2
        )
        as $part
    ) {
        if ($part === '') {
            continue;
        }

        $initials .=
            function_exists('mb_substr')
                ? mb_substr(
                    $part,
                    0,
                    1,
                    'UTF-8'
                )
                : substr(
                    $part,
                    0,
                    1
                );
    }

    if ($initials === '') {
        return '?';
    }

    return function_exists('mb_strtoupper')
        ? mb_strtoupper(
            $initials,
            'UTF-8'
        )
        : strtoupper(
            $initials
        );
}


function blackthorne_forum_datetime(?string $dateTime): string
{
    $dateTime =
        trim(
            (string) $dateTime
        );

    if ($dateTime === '') {
        return '';
    }

    try {
        $date =
            new DateTimeImmutable(
                $dateTime,
                new DateTimeZone(
                    'America/New_York'
                )
            );

        return $date->format(
            'M j, Y \a\t g:i A'
        );

    } catch (Throwable) {
        return $dateTime;
    }
}


function blackthorne_forum_safe_color(?string $color): ?string
{
    $color =
        trim(
            (string) $color
        );

    if (
        preg_match(
            '/^#[0-9a-fA-F]{6}$/',
            $color
        ) !== 1
    ) {
        return null;
    }

    return $color;
}


function blackthorne_forum_page_url(
    int $forumId,
    int $page,
    string $search = ''
): string {
    $parameters = [
        'f' => $forumId,
    ];

    if ($search !== '') {
        $parameters['q'] =
            $search;
    }

    if ($page > 1) {
        $parameters['page'] =
            $page;
    }

    return url(
        'forum.php?'
        . http_build_query(
            $parameters
        )
    );
}


/*
|--------------------------------------------------------------------------
| Resolve Requested Forum
|--------------------------------------------------------------------------
*/

$forumReference =
    trim(
        (string) (
            $_GET['f']
            ?? ''
        )
    );

if ($forumReference === '') {

    http_response_code(
        404
    );

    $pageTitle =
        'Forum Not Found | Blackthorne Academy';

    $pageDescription =
        'The requested Blackthorne Academy forum could not be found.';

    $robots =
        'noindex, nofollow';

    require INCLUDES_PATH . '/header.php';

    ?>

    <main
        id="main-content"
        class="forum-board-page"
    >
        <section class="forum-board-error">
            <div class="section-inner">
                <h1>Forum Not Found</h1>
                <p>
                    The requested board could not be found.
                </p>
                <a
                    class="button button-secondary"
                    href="<?= e(
                        url(
                            'forums.php'
                        )
                    ); ?>"
                >
                    Return to Forums
                </a>
            </div>
        </section>
    </main>

    <?php

    require INCLUDES_PATH . '/footer.php';

    exit;
}


if (
    ctype_digit(
        $forumReference
    )
) {

    $forumStatement =
        $pdo->prepare(
            '
            SELECT
                f.*,
                fc.title AS category_title,
                fc.slug AS category_slug

            FROM forums f

            INNER JOIN forum_categories fc
                ON fc.id = f.category_id

            WHERE f.id = :forum_id

            LIMIT 1
            '
        );

    $forumStatement->execute([
        'forum_id' =>
            (int) $forumReference,
    ]);

} else {

    $forumStatement =
        $pdo->prepare(
            '
            SELECT
                f.*,
                fc.title AS category_title,
                fc.slug AS category_slug

            FROM forums f

            INNER JOIN forum_categories fc
                ON fc.id = f.category_id

            WHERE f.slug = :forum_slug

            ORDER BY f.id ASC

            LIMIT 1
            '
        );

    $forumStatement->execute([
        'forum_slug' =>
            $forumReference,
    ]);
}


$forum =
    $forumStatement->fetch(
        PDO::FETCH_ASSOC
    );


if (
    !$forum
    || (int) $forum[
        'is_visible'
    ] !== 1
) {

    http_response_code(
        404
    );

    $pageTitle =
        'Forum Not Found | Blackthorne Academy';

    $pageDescription =
        'The requested Blackthorne Academy forum could not be found.';

    $robots =
        'noindex, nofollow';

    require INCLUDES_PATH . '/header.php';

    ?>

    <main
        id="main-content"
        class="forum-board-page"
    >
        <section class="forum-board-error">
            <div class="section-inner">
                <h1>Forum Not Found</h1>
                <p>
                    The requested board could not be found.
                </p>
                <a
                    class="button button-secondary"
                    href="<?= e(
                        url(
                            'forums.php'
                        )
                    ); ?>"
                >
                    Return to Forums
                </a>
            </div>
        </section>
    </main>

    <?php

    require INCLUDES_PATH . '/footer.php';

    exit;
}


$forumId =
    (int) $forum['id'];


if (
    !forum_can_access_forum(
        $pdo,
        $forumId,
        $userId
    )
) {

    http_response_code(
        403
    );

    $pageTitle =
        'Forum Access Restricted | Blackthorne Academy';

    $pageDescription =
        'This Blackthorne Academy forum is restricted.';

    $robots =
        'noindex, nofollow';

    require INCLUDES_PATH . '/header.php';

    ?>

    <main
        id="main-content"
        class="forum-board-page"
    >
        <section class="forum-board-error">
            <div class="section-inner">
                <h1>Access Restricted</h1>
                <p>
                    Your account does not have permission to enter this board.
                </p>
                <a
                    class="button button-secondary"
                    href="<?= e(
                        url(
                            'forums.php'
                        )
                    ); ?>"
                >
                    Return to Forums
                </a>
            </div>
        </section>
    </main>

    <?php

    require INCLUDES_PATH . '/footer.php';

    exit;
}


/*
|--------------------------------------------------------------------------
| Forum Permissions
|--------------------------------------------------------------------------
*/

$forumPermissions =
    forum_get_user_forum_permissions(
        $pdo,
        $forumId,
        $userId
    );

$canViewAllThreads =
    (bool) $forumPermissions[
        'can_view_all_threads'
    ];

$canCreateThreads =
    forum_can_create_thread(
        $pdo,
        $forumId,
        $userId
    );


/*
|--------------------------------------------------------------------------
| Forum Ancestors
|--------------------------------------------------------------------------
|
| Forums may be nested recursively. Build the complete ancestor chain so a
| third-level (or deeper) sub-forum gets breadcrumbs such as:
|
| Category / Main Forum / Sub-forum / Current Forum
|
*/

$forumAncestors =
    [];

$ancestorId =
    $forum['parent_forum_id'] !== null
        ? (int) $forum['parent_forum_id']
        : 0;

$visitedAncestorIds =
    [];

$ancestorStatement =
    $pdo->prepare(
        '
        SELECT
            id,
            parent_forum_id,
            title,
            slug

        FROM forums

        WHERE id = :forum_id

        LIMIT 1
        '
    );

while (
    $ancestorId > 0
    && !isset(
        $visitedAncestorIds[
            $ancestorId
        ]
    )
) {
    $visitedAncestorIds[
        $ancestorId
    ] = true;

    $ancestorStatement->execute([
        'forum_id' =>
            $ancestorId,
    ]);

    $ancestorForum =
        $ancestorStatement->fetch(
            PDO::FETCH_ASSOC
        );

    if (!is_array($ancestorForum)) {
        break;
    }

    if (
        forum_can_view_forum(
            $pdo,
            $ancestorId,
            $userId
        )
    ) {
        $forumAncestors[] =
            $ancestorForum;
    }

    $ancestorId =
        $ancestorForum[
            'parent_forum_id'
        ] !== null
            ? (int) $ancestorForum[
                'parent_forum_id'
            ]
            : 0;
}

$forumAncestors =
    array_reverse(
        $forumAncestors
    );


/*
|--------------------------------------------------------------------------
| Accessible Sub-boards
|--------------------------------------------------------------------------
*/

$subforumStatement =
    $pdo->prepare(
        '
        SELECT
            id,
            category_id,
            parent_forum_id,
            title,
            slug,
            description,
            is_locked,
            is_visible,
            sort_order

        FROM forums

        WHERE parent_forum_id = :forum_id
          AND is_visible = 1

        ORDER BY
            sort_order ASC,
            title ASC
        '
    );

$subforumStatement->execute([
    'forum_id' =>
        $forumId,
]);

$subforumCandidates =
    $subforumStatement->fetchAll(
        PDO::FETCH_ASSOC
    );

$subforums =
    [];


foreach (
    $subforumCandidates
    as $subforum
) {

    if (
        forum_can_view_forum(
            $pdo,
            (int) $subforum['id'],
            $userId
        )
    ) {
        $subforums[] =
            $subforum;
    }
}


/*
|--------------------------------------------------------------------------
| Optional Board Image Map
|--------------------------------------------------------------------------
*/

$forumImageMap = null;
$forumImageMapAreas = [];

try {
    $forumImageMap = forum_image_map_load($pdo, $forumId);
} catch (Throwable $exception) {
    error_log('Blackthorne forum image map display error: ' . $exception->getMessage());
}

if (is_array($forumImageMap)) {
    foreach ((array) ($forumImageMap['areas'] ?? []) as $area) {
        if (!is_array($area)) {
            continue;
        }

        $href = '';

        if ((string) ($area['link_type'] ?? '') === 'forum') {
            $targetForumId = (int) ($area['target_forum_id'] ?? 0);

            if (
                $targetForumId <= 0
                || !forum_can_view_forum($pdo, $targetForumId, $userId)
            ) {
                continue;
            }

            $href = url('forum.php?f=' . $targetForumId);
        } else {
            $storedUrl = trim((string) ($area['link_url'] ?? ''));

            if ($storedUrl === '') {
                continue;
            }

            $href = preg_match('~^https?://~i', $storedUrl) === 1
                ? $storedUrl
                : url($storedUrl);
        }

        $area['href'] = $href;
        $forumImageMapAreas[] = $area;
    }
}


/*
|--------------------------------------------------------------------------
| Sub-board Statistics
|--------------------------------------------------------------------------
*/

$subforumStatistics =
    [];


foreach ($subforums as $subforum) {

    $subforumId =
        (int) $subforum['id'];

    $subPermissions =
        forum_get_user_forum_permissions(
            $pdo,
            $subforumId,
            $userId
        );

    $subCanViewAll =
        (bool) $subPermissions[
            'can_view_all_threads'
        ];

    $visibilitySql =
        $subCanViewAll
            ? ''
            : '
              AND (
                  ft.reply_visibility = "public"
                  OR ft.user_id = :viewer_user_id
              )
            ';

    $parameters = [
        'forum_id' =>
            $subforumId,
    ];

    if (!$subCanViewAll) {
        $parameters[
            'viewer_user_id'
        ] =
            $userId;
    }


    $threadCountStatement =
        $pdo->prepare(
            '
            SELECT COUNT(*)

            FROM forum_threads ft

            WHERE ft.forum_id = :forum_id
              AND ft.is_deleted = 0
            '
            . $visibilitySql
        );

    $threadCountStatement->execute(
        $parameters
    );


    $postCountStatement =
        $pdo->prepare(
            '
            SELECT COUNT(*)

            FROM forum_posts fp

            INNER JOIN forum_threads ft
                ON ft.id = fp.thread_id

            WHERE ft.forum_id = :forum_id
              AND ft.is_deleted = 0
              AND fp.is_deleted = 0
            '
            . $visibilitySql
        );

    $postCountStatement->execute(
        $parameters
    );


    $lastPostStatement =
        $pdo->prepare(
            '
            SELECT
                fp.id AS post_id,
                fp.created_at AS post_created_at,

                ft.id AS thread_id,
                ft.title AS thread_title,

                u.id AS user_id,
                u.username,
                u.display_name,
                u.avatar,

                (
                    SELECT r.display_color

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

                    ORDER BY
                        r.sort_order ASC,
                        r.id ASC

                    LIMIT 1
                ) AS role_color

            FROM forum_posts fp

            INNER JOIN forum_threads ft
                ON ft.id = fp.thread_id

            INNER JOIN users u
                ON u.id = fp.user_id

            WHERE ft.forum_id = :forum_id
              AND ft.is_deleted = 0
              AND fp.is_deleted = 0
            '
            . $visibilitySql
            . '
            ORDER BY
                fp.created_at DESC,
                fp.id DESC

            LIMIT 1
            '
        );

    $lastPostStatement->execute(
        $parameters
    );


    $subforumStatistics[
        $subforumId
    ] = [
        'thread_count' =>
            (int) $threadCountStatement
                ->fetchColumn(),

        'post_count' =>
            (int) $postCountStatement
                ->fetchColumn(),

        'last_post' =>
            $lastPostStatement->fetch(
                PDO::FETCH_ASSOC
            )
            ?: null,
    ];
}


/*
|--------------------------------------------------------------------------
| Assigned Moderators Currently Online
|--------------------------------------------------------------------------
*/

$onlineModeratorsStatement =
    $pdo->prepare(
        '
        SELECT
            u.id,
            u.username,
            u.display_name,
            MAX(upres.last_seen_at) AS last_seen_at
        FROM forum_moderators fm
        INNER JOIN users u
            ON u.id = fm.user_id
        INNER JOIN user_presence upres
            ON upres.user_id = u.id
        WHERE fm.forum_id = :forum_id
          AND fm.is_active = 1
          AND fm.ended_at IS NULL
          AND u.status = "active"
          AND upres.last_seen_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE)
        GROUP BY u.id, u.username, u.display_name
        ORDER BY u.display_name ASC, u.id ASC
        '
    );

$onlineModeratorsStatement->execute([
    'forum_id' => $forumId,
]);

$onlineModerators =
    $onlineModeratorsStatement->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Staff / Moderator Thread Quick Actions
|--------------------------------------------------------------------------
*/

$canQuickPinThreads =
    (bool) ($forumPermissions['can_pin_threads'] ?? false);

$canQuickLockThreads =
    (bool) ($forumPermissions['can_lock_threads'] ?? false);

$canQuickAnnouncements =
    (bool) ($forumPermissions['can_mark_announcements'] ?? false);

$canQuickLabels =
    (bool) ($forumPermissions['can_apply_labels'] ?? false);

$canQuickMoveThreads =
    (bool) ($forumPermissions['can_move_threads'] ?? false);

$canUseThreadQuickActions =
    $canQuickPinThreads
    || $canQuickLockThreads
    || $canQuickAnnouncements
    || $canQuickLabels
    || $canQuickMoveThreads;

$quickActionLabels =
    $canQuickLabels
        ? forum_fetch_active_labels($pdo, $forumId)
        : [];

$quickActionDestinations = [];

if ($canQuickMoveThreads) {
    $destinationStatement =
        $pdo->prepare(
            '
            SELECT
                f.id,
                f.title,
                f.parent_forum_id,
                fc.title AS category_title,
                parent.title AS parent_title
            FROM forums f
            INNER JOIN forum_categories fc
                ON fc.id = f.category_id
            LEFT JOIN forums parent
                ON parent.id = f.parent_forum_id
            WHERE f.id <> :forum_id
              AND f.is_visible = 1
              AND fc.is_visible = 1
            ORDER BY
                fc.sort_order ASC,
                fc.id ASC,
                COALESCE(parent.sort_order, f.sort_order) ASC,
                COALESCE(parent.id, f.id) ASC,
                CASE WHEN f.parent_forum_id IS NULL THEN 0 ELSE 1 END ASC,
                f.sort_order ASC,
                f.id ASC
            '
        );

    $destinationStatement->execute([
        'forum_id' => $forumId,
    ]);

    foreach ($destinationStatement->fetchAll(PDO::FETCH_ASSOC) as $destinationForum) {
        $destinationForumId =
            (int) $destinationForum['id'];

        if (
            !forum_can_view_forum($pdo, $destinationForumId, $userId)
            || !forum_can_access_forum($pdo, $destinationForumId, $userId)
        ) {
            continue;
        }

        $quickActionDestinations[] =
            $destinationForum;
    }
}


if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && (string) ($_POST['post_action'] ?? '') === 'bulk_thread_action'
) {
    require_valid_csrf();

    if (!$canUseThreadQuickActions) {
        http_response_code(403);
        exit('You do not have permission to moderate threads in this board.');
    }

    $selectedThreadIds =
        array_values(
            array_unique(
                array_filter(
                    array_map('intval', (array) ($_POST['thread_ids'] ?? [])),
                    static fn (int $threadId): bool => $threadId > 0
                )
            )
        );

    if (count($selectedThreadIds) > 100) {
        $selectedThreadIds = array_slice($selectedThreadIds, 0, 100);
    }

    $bulkAction =
        trim((string) ($_POST['bulk_action'] ?? ''));

    $returnPage =
        max(1, (int) ($_POST['return_page'] ?? 1));

    $returnSearch =
        trim((string) ($_POST['return_search'] ?? ''));

    $redirectToThreads =
        static function (
            int $updated,
            int $skipped,
            string $status = 'success'
        ) use (
            $forumId,
            $returnPage,
            $returnSearch
        ): never {
            $redirectUrl =
                blackthorne_forum_page_url(
                    $forumId,
                    $returnPage,
                    $returnSearch
                );

            header(
                'Location: '
                . $redirectUrl
                . (str_contains($redirectUrl, '?') ? '&' : '?')
                . 'quick_action=' . rawurlencode($status)
                . '&updated=' . $updated
                . '&skipped=' . $skipped
                . '#threads-heading'
            );
            exit;
        };

    if ($selectedThreadIds === []) {
        $redirectToThreads(0, 0, 'none_selected');
    }

    $allowedActions = [
        'pin',
        'unpin',
        'lock',
        'unlock',
        'announcement',
        'remove_announcement',
        'apply_label',
        'remove_label',
        'move',
    ];

    if (!in_array($bulkAction, $allowedActions, true)) {
        $redirectToThreads(0, count($selectedThreadIds), 'invalid');
    }

    $threadPlaceholders =
        implode(',', array_fill(0, count($selectedThreadIds), '?'));

    $selectedThreadsStatement =
        $pdo->prepare(
            '
            SELECT
                id,
                forum_id,
                user_id,
                is_pinned,
                is_announcement,
                is_locked
            FROM forum_threads
            WHERE forum_id = ?
              AND is_deleted = 0
              AND id IN (' . $threadPlaceholders . ')
            '
        );

    $selectedThreadsStatement->execute([
        $forumId,
        ...$selectedThreadIds,
    ]);

    $selectedThreads =
        $selectedThreadsStatement->fetchAll(PDO::FETCH_ASSOC);

    $labelId =
        max(0, (int) ($_POST['label_id'] ?? 0));

    $destinationForumId =
        max(0, (int) ($_POST['destination_forum_id'] ?? 0));

    if (in_array($bulkAction, ['apply_label', 'remove_label'], true)) {
        if (!$canQuickLabels || $labelId <= 0) {
            $redirectToThreads(0, count($selectedThreadIds), 'invalid');
        }

        $labelValidationStatement =
            $pdo->prepare(
                '
                SELECT id
                FROM forum_labels
                WHERE id = :label_id
                  AND forum_id = :forum_id
                  AND is_active = 1
                LIMIT 1
                '
            );
        $labelValidationStatement->execute([
            'label_id' => $labelId,
            'forum_id' => $forumId,
        ]);

        if (!$labelValidationStatement->fetchColumn()) {
            $redirectToThreads(0, count($selectedThreadIds), 'invalid');
        }
    }

    if ($bulkAction === 'move') {
        if (
            !$canQuickMoveThreads
            || $destinationForumId <= 0
            || $destinationForumId === $forumId
            || !forum_can_view_forum($pdo, $destinationForumId, $userId)
            || !forum_can_access_forum($pdo, $destinationForumId, $userId)
        ) {
            $redirectToThreads(0, count($selectedThreadIds), 'invalid');
        }

        $destinationExistsStatement =
            $pdo->prepare(
                '
                SELECT id
                FROM forums
                WHERE id = :forum_id
                  AND is_visible = 1
                LIMIT 1
                '
            );
        $destinationExistsStatement->execute([
            'forum_id' => $destinationForumId,
        ]);

        if (!$destinationExistsStatement->fetchColumn()) {
            $redirectToThreads(0, count($selectedThreadIds), 'invalid');
        }
    }

    $updatedCount = 0;
    $skippedCount =
        max(0, count($selectedThreadIds) - count($selectedThreads));

    try {
        $pdo->beginTransaction();

        foreach ($selectedThreads as $selectedThread) {
            $selectedThreadId =
                (int) $selectedThread['id'];

            $selectedThreadForumId =
                (int) $selectedThread['forum_id'];

            $targetUserId =
                (int) $selectedThread['user_id'];

            if (in_array($bulkAction, ['pin', 'unpin'], true)) {
                if (!forum_can_pin_thread($pdo, $selectedThreadId, $userId)) {
                    $skippedCount++;
                    continue;
                }

                $newState = $bulkAction === 'pin' ? 1 : 0;
                if ((int) $selectedThread['is_pinned'] === $newState) {
                    $skippedCount++;
                    continue;
                }

                $statement = $pdo->prepare(
                    'UPDATE forum_threads SET is_pinned = :state WHERE id = :thread_id'
                );
                $statement->execute([
                    'state' => $newState,
                    'thread_id' => $selectedThreadId,
                ]);

                forum_log_moderation_action(
                    $pdo,
                    $newState === 1 ? 'pin_thread' : 'unpin_thread',
                    $userId,
                    $forumId,
                    $selectedThreadId,
                    null,
                    null,
                    $targetUserId,
                    null,
                    null,
                    $newState === 1
                        ? 'Thread made sticky from forum quick actions.'
                        : 'Sticky status removed from forum quick actions.'
                );
                $updatedCount++;
                continue;
            }

            if (in_array($bulkAction, ['lock', 'unlock'], true)) {
                if (!forum_can_lock_thread($pdo, $selectedThreadId, $userId)) {
                    $skippedCount++;
                    continue;
                }

                $newState = $bulkAction === 'lock' ? 1 : 0;
                if ((int) $selectedThread['is_locked'] === $newState) {
                    $skippedCount++;
                    continue;
                }

                $statement = $pdo->prepare(
                    'UPDATE forum_threads SET is_locked = :state WHERE id = :thread_id'
                );
                $statement->execute([
                    'state' => $newState,
                    'thread_id' => $selectedThreadId,
                ]);

                forum_log_moderation_action(
                    $pdo,
                    $newState === 1 ? 'lock_thread' : 'unlock_thread',
                    $userId,
                    $forumId,
                    $selectedThreadId,
                    null,
                    null,
                    $targetUserId,
                    null,
                    null,
                    $newState === 1
                        ? 'Thread locked from forum quick actions.'
                        : 'Thread unlocked from forum quick actions.'
                );
                $updatedCount++;
                continue;
            }

            if (in_array($bulkAction, ['announcement', 'remove_announcement'], true)) {
                if (!forum_can_mark_announcement($pdo, $selectedThreadForumId, $userId)) {
                    $skippedCount++;
                    continue;
                }

                $newState = $bulkAction === 'announcement' ? 1 : 0;
                if ((int) $selectedThread['is_announcement'] === $newState) {
                    $skippedCount++;
                    continue;
                }

                $statement = $pdo->prepare(
                    'UPDATE forum_threads SET is_announcement = :state WHERE id = :thread_id'
                );
                $statement->execute([
                    'state' => $newState,
                    'thread_id' => $selectedThreadId,
                ]);

                forum_log_moderation_action(
                    $pdo,
                    $newState === 1 ? 'mark_announcement' : 'remove_announcement',
                    $userId,
                    $forumId,
                    $selectedThreadId,
                    null,
                    null,
                    $targetUserId,
                    null,
                    null,
                    $newState === 1
                        ? 'Thread marked as an announcement from forum quick actions.'
                        : 'Announcement status removed from forum quick actions.'
                );
                $updatedCount++;
                continue;
            }

            if (in_array($bulkAction, ['apply_label', 'remove_label'], true)) {
                if (!forum_can_apply_labels($pdo, $selectedThreadForumId, $userId)) {
                    $skippedCount++;
                    continue;
                }

                if ($bulkAction === 'apply_label') {
                    $statement = $pdo->prepare(
                        '
                        INSERT IGNORE INTO forum_thread_labels (
                            thread_id,
                            label_id,
                            assigned_by
                        ) VALUES (
                            :thread_id,
                            :label_id,
                            :assigned_by
                        )
                        '
                    );
                    $statement->execute([
                        'thread_id' => $selectedThreadId,
                        'label_id' => $labelId,
                        'assigned_by' => $userId,
                    ]);

                    if ($statement->rowCount() <= 0) {
                        $skippedCount++;
                        continue;
                    }

                    forum_log_moderation_action(
                        $pdo,
                        'apply_label',
                        $userId,
                        $forumId,
                        $selectedThreadId,
                        null,
                        $labelId,
                        $targetUserId,
                        null,
                        null,
                        'Thread label applied from forum quick actions.'
                    );
                } else {
                    $statement = $pdo->prepare(
                        '
                        DELETE FROM forum_thread_labels
                        WHERE thread_id = :thread_id
                          AND label_id = :label_id
                        '
                    );
                    $statement->execute([
                        'thread_id' => $selectedThreadId,
                        'label_id' => $labelId,
                    ]);

                    if ($statement->rowCount() <= 0) {
                        $skippedCount++;
                        continue;
                    }

                    forum_log_moderation_action(
                        $pdo,
                        'remove_label',
                        $userId,
                        $forumId,
                        $selectedThreadId,
                        null,
                        $labelId,
                        $targetUserId,
                        null,
                        null,
                        'Thread label removed from forum quick actions.'
                    );
                }

                $updatedCount++;
                continue;
            }

            if ($bulkAction === 'move') {
                if (!forum_can_move_thread($pdo, $selectedThreadForumId, $userId)) {
                    $skippedCount++;
                    continue;
                }

                $moveStatement = $pdo->prepare(
                    'UPDATE forum_threads SET forum_id = :destination_forum_id WHERE id = :thread_id'
                );
                $moveStatement->execute([
                    'destination_forum_id' => $destinationForumId,
                    'thread_id' => $selectedThreadId,
                ]);

                $clearLabelsStatement = $pdo->prepare(
                    'DELETE FROM forum_thread_labels WHERE thread_id = :thread_id'
                );
                $clearLabelsStatement->execute([
                    'thread_id' => $selectedThreadId,
                ]);

                $movePollStatement = $pdo->prepare(
                    "UPDATE polls
                     SET forum_id = :destination_forum_id
                     WHERE thread_id = :thread_id
                       AND poll_scope = 'forum'"
                );
                $movePollStatement->execute([
                    'destination_forum_id' => $destinationForumId,
                    'thread_id' => $selectedThreadId,
                ]);

                forum_log_moderation_action(
                    $pdo,
                    'move_thread',
                    $userId,
                    $forumId,
                    $selectedThreadId,
                    null,
                    null,
                    $targetUserId,
                    null,
                    null,
                    'Thread moved from forum '
                        . $forumId
                        . ' to forum '
                        . $destinationForumId
                        . ' from forum quick actions.'
                );
                $updatedCount++;
            }
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log(
            'Blackthorne forum bulk moderation error: '
            . $exception->getMessage()
        );

        http_response_code(500);
        exit('The selected thread actions could not be completed.');
    }

    $redirectToThreads($updatedCount, $skippedCount);
}


/*
|--------------------------------------------------------------------------
| Thread Search + Pagination
|--------------------------------------------------------------------------
*/

$search =
    trim(
        (string) (
            $_GET['q']
            ?? ''
        )
    );

if (
    function_exists(
        'mb_substr'
    )
) {
    $search =
        mb_substr(
            $search,
            0,
            120,
            'UTF-8'
        );
} else {
    $search =
        substr(
            $search,
            0,
            120
        );
}


$page =
    max(
        1,
        (int) (
            $_GET['page']
            ?? 1
        )
    );

$threadsPerPage =
    25;

$offset =
    ($page - 1)
    * $threadsPerPage;


$threadVisibilitySql =
    $canViewAllThreads
        ? ''
        : '
          AND (
              ft.reply_visibility = "public"
              OR ft.user_id = :viewer_user_id
          )
        ';

$threadSearchSql =
    $search !== ''
        ? '
          AND ft.title LIKE :thread_search
        '
        : '';


$threadCountSql =
    '
    SELECT COUNT(*)

    FROM forum_threads ft

    WHERE ft.forum_id = :forum_id
      AND ft.is_deleted = 0
    '
    . $threadVisibilitySql
    . $threadSearchSql;


$threadCountStatement =
    $pdo->prepare(
        $threadCountSql
    );

$countParameters = [
    'forum_id' =>
        $forumId,
];

if (!$canViewAllThreads) {
    $countParameters[
        'viewer_user_id'
    ] =
        $userId;
}

if ($search !== '') {
    $countParameters[
        'thread_search'
    ] =
        '%' . $search . '%';
}

$threadCountStatement->execute(
    $countParameters
);

$totalThreads =
    (int) $threadCountStatement
        ->fetchColumn();

$totalPages =
    max(
        1,
        (int) ceil(
            $totalThreads
            / $threadsPerPage
        )
    );

if ($page > $totalPages) {
    $page =
        $totalPages;

    $offset =
        ($page - 1)
        * $threadsPerPage;
}


/*
|--------------------------------------------------------------------------
| Thread Listing
|--------------------------------------------------------------------------
*/

$threadSql =
    '
    SELECT
        ft.id,
        ft.forum_id,
        ft.user_id,
        ft.title,
        ft.is_pinned,
        ft.is_announcement,
        ft.reply_visibility,
        ft.is_locked,
        ft.view_count,
        ft.created_at,
        ft.last_activity_at,

        author.username AS author_username,
        author.display_name AS author_display_name,

        (
            SELECT r.display_color

            FROM user_roles ur

            INNER JOIN roles r
                ON r.id = ur.role_id

            WHERE ur.user_id = author.id
              AND ur.is_active = 1
              AND ur.revoked_at IS NULL
              AND (
                  ur.expires_at IS NULL
                  OR ur.expires_at > CURRENT_TIMESTAMP
              )
              AND r.is_active = 1

            ORDER BY
                r.sort_order ASC,
                r.id ASC

            LIMIT 1
        ) AS author_role_color,

        (
            SELECT COUNT(*)

            FROM forum_posts fp_count

            WHERE fp_count.thread_id = ft.id
              AND fp_count.is_deleted = 0
        ) AS post_count,

        (
            SELECT fp_last.id

            FROM forum_posts fp_last

            WHERE fp_last.thread_id = ft.id
              AND fp_last.is_deleted = 0

            ORDER BY
                fp_last.created_at DESC,
                fp_last.id DESC

            LIMIT 1
        ) AS last_post_id,

        (
            SELECT fp_last.created_at

            FROM forum_posts fp_last

            WHERE fp_last.thread_id = ft.id
              AND fp_last.is_deleted = 0

            ORDER BY
                fp_last.created_at DESC,
                fp_last.id DESC

            LIMIT 1
        ) AS last_post_created_at,

        (
            SELECT u_last.id

            FROM forum_posts fp_last

            INNER JOIN users u_last
                ON u_last.id = fp_last.user_id

            WHERE fp_last.thread_id = ft.id
              AND fp_last.is_deleted = 0

            ORDER BY
                fp_last.created_at DESC,
                fp_last.id DESC

            LIMIT 1
        ) AS last_user_id,

        (
            SELECT u_last.display_name

            FROM forum_posts fp_last

            INNER JOIN users u_last
                ON u_last.id = fp_last.user_id

            WHERE fp_last.thread_id = ft.id
              AND fp_last.is_deleted = 0

            ORDER BY
                fp_last.created_at DESC,
                fp_last.id DESC

            LIMIT 1
        ) AS last_display_name,

        (
            SELECT u_last.username

            FROM forum_posts fp_last

            INNER JOIN users u_last
                ON u_last.id = fp_last.user_id

            WHERE fp_last.thread_id = ft.id
              AND fp_last.is_deleted = 0

            ORDER BY
                fp_last.created_at DESC,
                fp_last.id DESC

            LIMIT 1
        ) AS last_username,

        (
            SELECT u_last.avatar

            FROM forum_posts fp_last

            INNER JOIN users u_last
                ON u_last.id = fp_last.user_id

            WHERE fp_last.thread_id = ft.id
              AND fp_last.is_deleted = 0

            ORDER BY
                fp_last.created_at DESC,
                fp_last.id DESC

            LIMIT 1
        ) AS last_avatar,

        (
            SELECT r.display_color

            FROM forum_posts fp_last

            INNER JOIN users u_last
                ON u_last.id = fp_last.user_id

            INNER JOIN user_roles ur
                ON ur.user_id = u_last.id

            INNER JOIN roles r
                ON r.id = ur.role_id

            WHERE fp_last.thread_id = ft.id
              AND fp_last.is_deleted = 0

              AND ur.is_active = 1
              AND ur.revoked_at IS NULL

              AND (
                  ur.expires_at IS NULL
                  OR ur.expires_at > CURRENT_TIMESTAMP
              )

              AND r.is_active = 1

            ORDER BY
                fp_last.created_at DESC,
                fp_last.id DESC,
                r.sort_order ASC,
                r.id ASC

            LIMIT 1
        ) AS last_role_color

    FROM forum_threads ft

    INNER JOIN users author
        ON author.id = ft.user_id

    WHERE ft.forum_id = :forum_id
      AND ft.is_deleted = 0
    '
    . $threadVisibilitySql
    . $threadSearchSql
    . '

    ORDER BY
        ft.is_announcement DESC,
        ft.is_pinned DESC,
        ft.last_activity_at DESC,
        ft.id DESC

    LIMIT :thread_limit
    OFFSET :thread_offset
    ';


$threadStatement =
    $pdo->prepare(
        $threadSql
    );

$threadStatement->bindValue(
    ':forum_id',
    $forumId,
    PDO::PARAM_INT
);

if (!$canViewAllThreads) {
    $threadStatement->bindValue(
        ':viewer_user_id',
        $userId,
        PDO::PARAM_INT
    );
}

if ($search !== '') {
    $threadStatement->bindValue(
        ':thread_search',
        '%' . $search . '%',
        PDO::PARAM_STR
    );
}

$threadStatement->bindValue(
    ':thread_limit',
    $threadsPerPage,
    PDO::PARAM_INT
);

$threadStatement->bindValue(
    ':thread_offset',
    $offset,
    PDO::PARAM_INT
);

$threadStatement->execute();

$threads =
    $threadStatement->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Thread Labels
|--------------------------------------------------------------------------
*/

$labelsByThread =
    [];

$threadIds =
    array_values(
        array_filter(
            array_map(
                static fn (
                    array $thread
                ): int =>
                    (int) $thread['id'],
                $threads
            )
        )
    );


if ($threadIds !== []) {

    $labelPlaceholders =
        implode(
            ',',
            array_fill(
                0,
                count(
                    $threadIds
                ),
                '?'
            )
        );

    $labelStatement =
        $pdo->prepare(
            '
            SELECT
                ftl.thread_id,
                fl.id,
                fl.name,
                fl.label_color,
                fl.sort_order

            FROM forum_thread_labels ftl

            INNER JOIN forum_labels fl
                ON fl.id = ftl.label_id

            WHERE ftl.thread_id IN ('
            . $labelPlaceholders
            . ')

              AND fl.forum_id = ?
              AND fl.is_active = 1

            ORDER BY
                fl.sort_order ASC,
                fl.name ASC
            '
        );

    $labelStatement->execute([
        ...$threadIds,
        $forumId,
    ]);

    foreach (
        $labelStatement->fetchAll(
            PDO::FETCH_ASSOC
        )
        as $label
    ) {

        $labelsByThread[
            (int) $label[
                'thread_id'
            ]
        ][] =
            $label;
    }
}


$forumHouseColor = null;

try {
    $forumHouseColorStatement = $pdo->prepare(
        'SELECT display_color
         FROM houses
         WHERE is_active = 1
           AND (
                LOWER(TRIM(name)) = LOWER(TRIM(:forum_title_name))
                OR LOWER(TRIM(display_name)) = LOWER(TRIM(:forum_title_display))
           )
         ORDER BY sort_order ASC, id ASC
         LIMIT 1'
    );
    $forumHouseColorStatement->execute([
        'forum_title_name' => (string) $forum['title'],
        'forum_title_display' => (string) $forum['title'],
    ]);

    $forumHouseColor = safe_css_color(
        (string) ($forumHouseColorStatement->fetchColumn() ?: '')
    );
} catch (PDOException) {
    $forumHouseColor = null;
}


/*
|--------------------------------------------------------------------------
| Page Metadata
|--------------------------------------------------------------------------
*/

$pageTitle =
    (string) $forum['title']
    . ' | Blackthorne Academy Forums';

$pageDescription =
    trim(
        (string) (
            $forum[
                'description'
            ]
            ?? ''
        )
    );

if ($pageDescription === '') {
    $pageDescription =
        'Blackthorne Academy forum discussions.';
}

$pageCanonical =
    url(
        'forum.php?f='
        . $forumId
    );

$robots =
    'noindex, nofollow';

require INCLUDES_PATH . '/header.php';

?>

<main
    id="main-content"
    class="forum-board-page"
>


    <!-- ================================================================
         BOARD HEADER
    ================================================================= -->

    <section class="forum-board-header">

        <div class="section-inner">


            <nav
                class="forum-breadcrumbs"
                aria-label="Forum breadcrumb"
            >

                <?php if (
                    forum_user_can_moderate(
                        $pdo,
                        $forumId,
                        $userId
                    )
                ): ?>

                    <a
                        href="<?= e(
                            url(
                                'forums.php'
                            )
                        ); ?>"
                    >
                        Forums
                    </a>

                    <span aria-hidden="true">
                        /
                    </span>

                <?php endif; ?>

                <span>
                    <?= e(
                        (string) $forum[
                            'category_title'
                        ]
                    ); ?>
                </span>


                <?php foreach (
                    $forumAncestors
                    as $ancestorForum
                ): ?>

                    <span aria-hidden="true">
                        /
                    </span>

                    <a
                        href="<?= e(
                            url(
                                'forum.php?f='
                                . (int) $ancestorForum[
                                    'id'
                                ]
                            )
                        ); ?>"
                    >
                        <?= e(
                            (string) $ancestorForum[
                                'title'
                            ]
                        ); ?>
                    </a>

                <?php endforeach; ?>

            </nav>


            <div class="forum-board-heading-row">

                <div>

                    <p class="academy-overline">
                        <?= $forum[
                            'parent_forum_id'
                        ] === null
                            ? 'Forum Board'
                            : 'Sub-forum'; ?>
                    </p>

                    <h1<?= $forumHouseColor !== null ? ' style="color:' . e($forumHouseColor) . ';"' : ''; ?>>
                        <?= e(
                            (string) $forum[
                                'title'
                            ]
                        ); ?>
                    </h1>

                    <?php if (
                        trim(
                            (string) (
                                $forum[
                                    'description'
                                ]
                                ?? ''
                            )
                        ) !== ''
                    ): ?>

                        <p class="forum-board-description">
                            <?= e(
                                (string) $forum[
                                    'description'
                                ]
                            ); ?>
                        </p>

                    <?php endif; ?>

                </div>


                <?php if (
                    (int) (
                        $forum['is_locked']
                        ?? 0
                    ) === 1
                ): ?>

                    <span class="forum-board-locked-badge">
                        Board Locked
                    </span>

                <?php endif; ?>

            </div>

        </div>

    </section>


    <!-- ================================================================
         BOARD CONTENT
    ================================================================= -->

    <section class="forum-board-content">

        <div class="section-inner member-home-layout forum-board-layout">

            <?php require INCLUDES_PATH . '/member-sidebar.php'; ?>

            <div class="member-home-main forum-board-main">


            <!-- ========================================================
                 SUB-BOARDS
            ========================================================= -->

            <?php if (is_array($forumImageMap)): ?>

                <section
                    class="forum-board-image-map"
                    aria-labelledby="board-map-heading"
                >
                    <header class="forum-board-section-titlebar">
                        <h2 id="board-map-heading">Explore <span<?= $forumHouseColor !== null ? ' style="color:' . e($forumHouseColor) . ';"' : ''; ?>><?= e((string) $forum['title']); ?></span></h2>
                    </header>

                    <div class="forum-image-map-display">
                        <picture>
                            <?php if (!empty($forumImageMap['webp_path'])): ?>
                                <source
                                    srcset="<?= e(url((string) $forumImageMap['webp_path'])); ?>"
                                    type="image/webp"
                                >
                            <?php endif; ?>
                            <img
                                src="<?= e(url((string) $forumImageMap['image_path'])); ?>"
                                alt="<?= e((string) ($forumImageMap['image_alt'] ?? $forum['title'])); ?>"
                                width="<?= (int) $forumImageMap['original_width']; ?>"
                                height="<?= (int) $forumImageMap['original_height']; ?>"
                            >
                        </picture>

                        <?php if ($forumImageMapAreas !== []): ?>
                            <svg
                                class="forum-image-map-links-layer"
                                viewBox="0 0 <?= (int) $forumImageMap['original_width']; ?> <?= (int) $forumImageMap['original_height']; ?>"
                                aria-hidden="true"
                            >
                                <?php foreach ($forumImageMapAreas as $area): ?>
                                    <?php
                                    $coords = array_map('floatval', (array) ($area['coords'] ?? []));
                                    $shape = (string) ($area['shape'] ?? '');
                                    $title = trim((string) ($area['title'] ?? $area['alt_text'] ?? 'Open destination'));
                                    $target = (string) ($area['link_target'] ?? '_self');
                                    ?>
                                    <a
                                        href="<?= e((string) $area['href']); ?>"
                                        target="<?= e($target); ?>"
                                        <?= $target === '_blank' ? 'rel="noopener noreferrer"' : ''; ?>
                                    >
                                        <?php if ($shape === 'rect' && count($coords) >= 4): ?>
                                            <?php
                                            $x = min($coords[0], $coords[2]);
                                            $y = min($coords[1], $coords[3]);
                                            $w = abs($coords[2] - $coords[0]);
                                            $h = abs($coords[3] - $coords[1]);
                                            ?>
                                            <rect class="forum-image-map-hotspot" x="<?= $x; ?>" y="<?= $y; ?>" width="<?= $w; ?>" height="<?= $h; ?>"><title><?= e($title); ?></title></rect>
                                        <?php elseif ($shape === 'circle' && count($coords) >= 3): ?>
                                            <circle class="forum-image-map-hotspot" cx="<?= $coords[0]; ?>" cy="<?= $coords[1]; ?>" r="<?= abs($coords[2]); ?>"><title><?= e($title); ?></title></circle>
                                        <?php elseif ($shape === 'poly' && count($coords) >= 6): ?>
                                            <?php
                                            $points = [];
                                            for ($pointIndex = 0; $pointIndex + 1 < count($coords); $pointIndex += 2) {
                                                $points[] = $coords[$pointIndex] . ',' . $coords[$pointIndex + 1];
                                            }
                                            ?>
                                            <polygon class="forum-image-map-hotspot" points="<?= e(implode(' ', $points)); ?>"><title><?= e($title); ?></title></polygon>
                                        <?php endif; ?>
                                    </a>
                                <?php endforeach; ?>
                            </svg>

                            <div class="sr-only">
                                <p>Image map destinations:</p>
                                <ul>
                                    <?php foreach ($forumImageMapAreas as $area): ?>
                                        <li>
                                            <a href="<?= e((string) $area['href']); ?>">
                                                <?= e(trim((string) ($area['alt_text'] ?? $area['title'] ?? 'Open destination')) ?: 'Open destination'); ?>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

            <?php elseif (
                $subforums !== []
            ): ?>

                <section
                    class="forum-board-subforums"
                    aria-labelledby="subboards-heading"
                >

                    <header class="forum-board-section-titlebar">

                        <h2 id="subboards-heading">
                            Sub-boards
                        </h2>

                    </header>


                    <div class="forum-subboard-list">


                        <?php foreach (
                            $subforums
                            as $subforum
                        ): ?>

                            <?php

                            $subforumId =
                                (int) $subforum[
                                    'id'
                                ];

                            $subStats =
                                $subforumStatistics[
                                    $subforumId
                                ]
                                ?? [
                                    'thread_count' => 0,
                                    'post_count' => 0,
                                    'last_post' => null,
                                ];

                            $subLastPost =
                                is_array(
                                    $subStats[
                                        'last_post'
                                    ]
                                )
                                    ? $subStats[
                                        'last_post'
                                    ]
                                    : null;

                            ?>


                            <article class="forum-subboard-row">


                                <div class="forum-subboard-copy">

                                    <h3>

                                        <a
                                            href="<?= e(
                                                url(
                                                    'forum.php?f='
                                                    . $subforumId
                                                )
                                            ); ?>"
                                        >
                                            <?= e(
                                                (string) $subforum[
                                                    'title'
                                                ]
                                            ); ?>
                                        </a>

                                    </h3>


                                    <?php if (
                                        trim(
                                            (string) (
                                                $subforum[
                                                    'description'
                                                ]
                                                ?? ''
                                            )
                                        ) !== ''
                                    ): ?>

                                        <p>
                                            <?= e(
                                                (string) $subforum[
                                                    'description'
                                                ]
                                            ); ?>
                                        </p>

                                    <?php endif; ?>

                                </div>


                                <div class="forum-subboard-stat">

                                    <strong>
                                        <?= number_format(
                                            (int) $subStats[
                                                'thread_count'
                                            ]
                                        ); ?>
                                    </strong>

                                    <span>
                                        Threads
                                    </span>

                                </div>


                                <div class="forum-subboard-stat">

                                    <strong>
                                        <?= number_format(
                                            (int) $subStats[
                                                'post_count'
                                            ]
                                        ); ?>
                                    </strong>

                                    <span>
                                        Posts
                                    </span>

                                </div>


                                <div class="forum-subboard-last-post">


                                    <?php if (
                                        $subLastPost
                                        === null
                                    ): ?>

                                        <span class="forum-no-last-post">
                                            No posts yet.
                                        </span>


                                    <?php else: ?>

                                        <?php

                                        $subLastName =
                                            trim(
                                                (string) (
                                                    $subLastPost[
                                                        'display_name'
                                                    ]
                                                    ?? $subLastPost[
                                                        'username'
                                                    ]
                                                    ?? 'Member'
                                                )
                                            );

                                        $subAvatar =
                                            blackthorne_forum_avatar_url(
                                                isset(
                                                    $subLastPost[
                                                        'avatar'
                                                    ]
                                                )
                                                    ? (string) $subLastPost[
                                                        'avatar'
                                                    ]
                                                    : null
                                            );

                                        $subRoleColor =
                                            user_house_display_color(
                                                (int) $subLastPost['user_id']
                                            );

                                        ?>


                                        <div class="forum-last-post-card">

                                            <a
                                                class="forum-last-post-avatar"
                                                href="<?= e(
                                                    url(
                                                        'profile.php?u='
                                                        . (int) $subLastPost[
                                                            'user_id'
                                                        ]
                                                    )
                                                ); ?>"
                                            >

                                                <?php if (
                                                    $subAvatar
                                                    !== null
                                                ): ?>

                                                    <img
                                                        src="<?= e(
                                                            $subAvatar
                                                        ); ?>"
                                                        alt=""
                                                        loading="lazy"
                                                    >

                                                <?php else: ?>

                                                    <span aria-hidden="true">
                                                        <?= e(
                                                            blackthorne_forum_initials(
                                                                $subLastName
                                                            )
                                                        ); ?>
                                                    </span>

                                                <?php endif; ?>

                                            </a>


                                            <div>

                                                <a
                                                    class="forum-last-post-thread"
                                                    href="<?= e(
                                                        url(
                                                            'thread.php?t='
                                                            . (int) $subLastPost[
                                                                'thread_id'
                                                            ]
                                                        )
                                                    ); ?>"
                                                >
                                                    <?= e(
                                                        (string) $subLastPost[
                                                            'thread_title'
                                                        ]
                                                    ); ?>
                                                </a>

                                                <p>
                                                    by
                                                    <a
                                                        href="<?= e(
                                                            url(
                                                                'profile.php?u='
                                                                . (int) $subLastPost[
                                                                    'user_id'
                                                                ]
                                                            )
                                                        ); ?>"
                                                        <?= $subRoleColor !== null
                                                            ? 'style="color: ' . e(
                                                                $subRoleColor
                                                            ) . ';"'
                                                            : ''; ?>
                                                    >
                                                        <?= e(
                                                            $subLastName
                                                        ); ?>
                                                    </a>
                                                </p>

                                                <time
                                                    datetime="<?= e(
                                                        (string) $subLastPost[
                                                            'post_created_at'
                                                        ]
                                                    ); ?>"
                                                >
                                                    <?= e(
                                                        blackthorne_forum_datetime(
                                                            (string) $subLastPost[
                                                                'post_created_at'
                                                            ]
                                                        )
                                                    ); ?>
                                                </time>

                                            </div>

                                        </div>

                                    <?php endif; ?>

                                </div>

                            </article>


                        <?php endforeach; ?>


                    </div>

                </section>

            <?php endif; ?>


            <!-- ========================================================
                 STAFF ONLINE
            ========================================================= -->

            <section
                class="forum-board-staff-online"
                aria-labelledby="staff-online-heading"
            >
                <h2 id="staff-online-heading">Staff Online</h2>

                <p class="forum-board-staff-online-names">
                    <?php if ($onlineModerators !== []): ?>
                        <?php foreach ($onlineModerators as $index => $moderator): ?>
                            <?php if ($index > 0): ?>, <?php endif; ?>
                            <a
                                href="<?= e(url('profile.php?u=' . (int) $moderator['id'])); ?>"
                                <?= user_display_name_style_attr((int) $moderator['id']); ?>
                            ><?= e(trim((string) ($moderator['display_name'] ?? $moderator['username'] ?? 'Staff Member'))); ?></a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span>No assigned moderators are currently online.</span>
                    <?php endif; ?>
                </p>
            </section>


            <!-- ========================================================
                 THREAD TOOLBAR
            ========================================================= -->

            <section
                class="forum-thread-list-section"
                aria-labelledby="threads-heading"
            >

                <header class="forum-board-section-titlebar">

                    <div>

                        <p class="forum-board-section-kicker">
                            Discussion
                        </p>

                        <h2 id="threads-heading">
                            Threads
                        </h2>

                    </div>


                    <div class="forum-thread-titlebar-actions">

                        <?php if ($canUseThreadQuickActions && $threads !== []): ?>
                            <button
                                type="button"
                                class="forum-new-thread-placeholder forum-thread-quick-toggle"
                                data-thread-quick-toggle
                                aria-expanded="false"
                                aria-controls="forum-thread-quick-actions-form"
                            >
                                Quick Actions
                            </button>
                        <?php endif; ?>

                        <?php if (
                            $canCreateThreads
                            && (int) (
                                $forum['is_locked']
                                ?? 0
                            ) !== 1
                        ): ?>

                            <a
                                class="forum-new-thread-placeholder"
                                href="/new-thread.php?f=<?php echo (int) $forumId; ?>"
                            >
                                New Thread
                            </a>

                        <?php endif; ?>

                    </div>

                </header>


                <div class="forum-thread-toolbar">


                    <div class="forum-thread-pagination">

                        <?php if (
                            $page > 1
                        ): ?>

                            <a
                                href="<?= e(
                                    blackthorne_forum_page_url(
                                        $forumId,
                                        $page - 1,
                                        $search
                                    )
                                ); ?>"
                            >
                                Previous
                            </a>

                        <?php else: ?>

                            <span class="is-disabled">
                                Previous
                            </span>

                        <?php endif; ?>


                        <?php

                        $startPage =
                            max(
                                1,
                                $page - 2
                            );

                        $endPage =
                            min(
                                $totalPages,
                                $page + 2
                            );

                        ?>


                        <?php for (
                            $paginationPage =
                                $startPage;
                            $paginationPage <=
                                $endPage;
                            $paginationPage++
                        ): ?>

                            <?php if (
                                $paginationPage
                                === $page
                            ): ?>

                                <span
                                    class="is-current"
                                    aria-current="page"
                                >
                                    <?= $paginationPage; ?>
                                </span>

                            <?php else: ?>

                                <a
                                    href="<?= e(
                                        blackthorne_forum_page_url(
                                            $forumId,
                                            $paginationPage,
                                            $search
                                        )
                                    ); ?>"
                                >
                                    <?= $paginationPage; ?>
                                </a>

                            <?php endif; ?>

                        <?php endfor; ?>


                        <?php if (
                            $page < $totalPages
                        ): ?>

                            <a
                                href="<?= e(
                                    blackthorne_forum_page_url(
                                        $forumId,
                                        $page + 1,
                                        $search
                                    )
                                ); ?>"
                            >
                                Next
                            </a>

                        <?php else: ?>

                            <span class="is-disabled">
                                Next
                            </span>

                        <?php endif; ?>

                    </div>


                    <form
                        class="forum-thread-search"
                        action="<?= e(
                            url(
                                'forum.php'
                            )
                        ); ?>"
                        method="get"
                        role="search"
                    >

                        <input
                            type="hidden"
                            name="f"
                            value="<?= $forumId; ?>"
                        >

                        <label
                            class="sr-only"
                            for="forum-thread-search"
                        >
                            Search threads
                        </label>

                        <input
                            id="forum-thread-search"
                            type="search"
                            name="q"
                            value="<?= e(
                                $search
                            ); ?>"
                            maxlength="120"
                            placeholder="Search threads..."
                        >

                        <button
                            type="submit"
                            aria-label="Search threads"
                        >
                            Search
                        </button>

                    </form>

                </div>


                <?php if (
                    $search !== ''
                ): ?>

                    <div class="forum-search-summary">

                        Showing results for
                        <strong>
                            “<?= e(
                                $search
                            ); ?>”
                        </strong>

                        <a
                            href="<?= e(
                                url(
                                    'forum.php?f='
                                    . $forumId
                                )
                            ); ?>"
                        >
                            Clear search
                        </a>

                    </div>

                <?php endif; ?>


                <!-- ====================================================
                     THREAD ROWS
                ===================================================== -->

                <?php
                $quickActionStatus = trim((string) ($_GET['quick_action'] ?? ''));
                $quickActionUpdated = max(0, (int) ($_GET['updated'] ?? 0));
                $quickActionSkipped = max(0, (int) ($_GET['skipped'] ?? 0));
                ?>

                <?php if ($quickActionStatus !== ''): ?>
                    <div
                        class="forum-thread-quick-action-notice<?= $quickActionStatus === 'success' ? ' is-success' : ' is-warning'; ?>"
                        role="status"
                    >
                        <?php if ($quickActionStatus === 'success'): ?>
                            Updated <?= number_format($quickActionUpdated); ?> selected
                            <?= $quickActionUpdated === 1 ? 'thread' : 'threads'; ?>.
                            <?php if ($quickActionSkipped > 0): ?>
                                <?= number_format($quickActionSkipped); ?>
                                <?= $quickActionSkipped === 1 ? 'selection was' : 'selections were'; ?>
                                skipped because the action was already applied or permission was unavailable.
                            <?php endif; ?>
                        <?php elseif ($quickActionStatus === 'none_selected'): ?>
                            Select at least one thread before using a quick action.
                        <?php else: ?>
                            That quick action could not be applied. Check the selected action and required destination or label.
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($canUseThreadQuickActions && $threads !== []): ?>
                    <form
                        id="forum-thread-quick-actions-form"
                        class="forum-thread-quick-actions"
                        hidden
                        action="<?= e(url('forum.php?f=' . $forumId)); ?>"
                        method="post"
                    >
                        <?= csrf_field(); ?>
                        <input type="hidden" name="post_action" value="bulk_thread_action">
                        <input type="hidden" name="return_page" value="<?= (int) $page; ?>">
                        <input type="hidden" name="return_search" value="<?= e($search); ?>">

                        <div class="forum-thread-quick-actions-heading">
                            <strong>Staff / Moderator Quick Actions</strong>
                            <span>Apply an action to the selected threads below.</span>
                        </div>

                        <label class="forum-thread-quick-select-all">
                            <input type="checkbox" data-thread-select-all>
                            <span>Select all on this page</span>
                        </label>

                        <label class="forum-thread-quick-field">
                            <span>Action</span>
                            <select name="bulk_action" required data-thread-bulk-action>
                                <option value="">Choose action…</option>
                                <?php if ($canQuickPinThreads): ?>
                                    <option value="pin">Make Sticky</option>
                                    <option value="unpin">Remove Sticky</option>
                                <?php endif; ?>
                                <?php if ($canQuickLockThreads): ?>
                                    <option value="lock">Lock</option>
                                    <option value="unlock">Unlock</option>
                                <?php endif; ?>
                                <?php if ($canQuickAnnouncements): ?>
                                    <option value="announcement">Make Announcement</option>
                                    <option value="remove_announcement">Remove Announcement</option>
                                <?php endif; ?>
                                <?php if ($canQuickLabels && $quickActionLabels !== []): ?>
                                    <option value="apply_label">Apply Label</option>
                                    <option value="remove_label">Remove Label</option>
                                <?php endif; ?>
                                <?php if ($canQuickMoveThreads && $quickActionDestinations !== []): ?>
                                    <option value="move">Move to Another Board</option>
                                <?php endif; ?>
                            </select>
                        </label>

                        <?php if ($canQuickLabels && $quickActionLabels !== []): ?>
                            <label class="forum-thread-quick-field is-conditional" data-thread-label-field hidden>
                                <span>Label</span>
                                <select name="label_id">
                                    <option value="">Choose label…</option>
                                    <?php foreach ($quickActionLabels as $label): ?>
                                        <option value="<?= (int) $label['id']; ?>"><?= e((string) $label['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        <?php endif; ?>

                        <?php if ($canQuickMoveThreads && $quickActionDestinations !== []): ?>
                            <label class="forum-thread-quick-field is-conditional" data-thread-destination-field hidden>
                                <span>Destination</span>
                                <select
                                    name="destination_forum_id"
                                    data-forum-picker
                                >
                                    <option value="">Choose destination…</option>
                                    <?php foreach ($quickActionDestinations as $destinationForum): ?>
                                        <?php
                                        $destinationLabel = trim((string) $destinationForum['category_title']) . ' — ';
                                        if (trim((string) ($destinationForum['parent_title'] ?? '')) !== '') {
                                            $destinationLabel .= trim((string) $destinationForum['parent_title']) . ' › ';
                                        }
                                        $destinationLabel .= trim((string) $destinationForum['title']);
                                        ?>
                                        <option
                                            value="<?= (int) $destinationForum['id']; ?>"
                                            data-forum-id="<?= (int) $destinationForum['id']; ?>"
                                            data-parent-forum-id="<?= (int) ($destinationForum['parent_forum_id'] ?? 0); ?>"
                                            data-category-title="<?= e((string) $destinationForum['category_title']); ?>"
                                            data-forum-title="<?= e((string) $destinationForum['title']); ?>"
                                        >
                                            <?= e((string) $destinationForum['title']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        <?php endif; ?>

                        <button type="submit" class="button button-secondary forum-thread-quick-apply">Apply</button>
                    </form>
                <?php endif; ?>

                <?php if (
                    $threads === []
                ): ?>

                    <div class="forum-thread-empty">

                        <?php if (
                            $search !== ''
                        ): ?>

                            No threads matched your search.

                        <?php else: ?>

                            No threads have been created in this board yet.

                        <?php endif; ?>

                    </div>


                <?php else: ?>

                    <div class="forum-thread-list">


                        <?php foreach (
                            $threads
                            as $thread
                        ): ?>

                            <?php

                            $threadId =
                                (int) $thread[
                                    'id'
                                ];

                            $postCount =
                                (int) $thread[
                                    'post_count'
                                ];

                            $replyCount =
                                max(
                                    0,
                                    $postCount - 1
                                );

                            $threadLabels =
                                $labelsByThread[
                                    $threadId
                                ]
                                ?? [];

                            $authorName =
                                trim(
                                    (string) (
                                        $thread[
                                            'author_display_name'
                                        ]
                                        ?? $thread[
                                            'author_username'
                                        ]
                                        ?? 'Member'
                                    )
                                );

                            $authorColor =
                                user_house_display_color(
                                    (int) $thread['user_id']
                                );

                            $lastName =
                                trim(
                                    (string) (
                                        $thread[
                                            'last_display_name'
                                        ]
                                        ?? $thread[
                                            'last_username'
                                        ]
                                        ?? $authorName
                                    )
                                );

                            $lastAvatar =
                                blackthorne_forum_avatar_url(
                                    isset(
                                        $thread[
                                            'last_avatar'
                                        ]
                                    )
                                        ? (string) $thread[
                                            'last_avatar'
                                        ]
                                        : null
                                );

                            $lastColor =
                                user_house_display_color(
                                    (int) (
                                        $thread['last_user_id']
                                        ?? 0
                                    )
                                );

                            ?>


                            <article
                                class="forum-thread-row<?= (int) $thread['is_announcement'] === 1 ? ' is-announcement' : ''; ?><?= (int) $thread['is_pinned'] === 1 ? ' is-pinned' : ''; ?><?= (int) $thread['is_locked'] === 1 ? ' is-locked' : ''; ?>"
                            >


                                <div class="forum-thread-status">

                                    <?php if ($canUseThreadQuickActions): ?>
                                        <label
                                            class="forum-thread-bulk-checkbox"
                                            data-thread-bulk-picker
                                            hidden
                                            title="Select this thread for staff quick actions"
                                        >
                                            <input
                                                type="checkbox"
                                                name="thread_ids[]"
                                                value="<?= $threadId; ?>"
                                                form="forum-thread-quick-actions-form"
                                                data-thread-select
                                                aria-label="Select <?= e((string) $thread['title']); ?> for staff quick actions"
                                            >
                                            <span aria-hidden="true"></span>
                                        </label>
                                    <?php endif; ?>

                                    <?php if (
                                        (int) $thread[
                                            'is_announcement'
                                        ] === 1
                                    ): ?>

                                        <span
                                            class="forum-thread-status-icon"
                                            title="Announcement"
                                            aria-label="Announcement"
                                        >
                                            <svg
                                                viewBox="0 0 24 24"
                                                aria-hidden="true"
                                            >
                                                <path
                                                    d="M4 10v4h3l4 4V6L7 10H4Zm9-3v10c3-1 5-3 7-5-2-2-4-4-7-5Z"
                                                    fill="currentColor"
                                                />
                                            </svg>
                                        </span>

                                    <?php endif; ?>


                                    <?php if (
                                        (int) $thread[
                                            'is_pinned'
                                        ] === 1
                                    ): ?>

                                        <span
                                            class="forum-thread-status-icon"
                                            title="Sticky"
                                            aria-label="Sticky"
                                        >
                                            <svg
                                                viewBox="0 0 24 24"
                                                aria-hidden="true"
                                            >
                                                <path
                                                    d="m14 3 7 7-2 2-2-1-4 4v4l-2 2-2-6-6-2 2-2h4l4-4-1-2 2-2Z"
                                                    fill="currentColor"
                                                />
                                            </svg>
                                        </span>

                                    <?php endif; ?>


                                    <?php if (
                                        (int) $thread[
                                            'is_locked'
                                        ] === 1
                                    ): ?>

                                        <span
                                            class="forum-thread-status-icon"
                                            title="Locked"
                                            aria-label="Locked"
                                        >
                                            <svg
                                                viewBox="0 0 24 24"
                                                aria-hidden="true"
                                            >
                                                <path
                                                    d="M7 10V8a5 5 0 0 1 10 0v2h2v11H5V10h2Zm2 0h6V8a3 3 0 0 0-6 0v2Z"
                                                    fill="currentColor"
                                                />
                                            </svg>
                                        </span>

                                    <?php endif; ?>


                                    <?php if (
                                        (int) $thread[
                                            'is_announcement'
                                        ] !== 1
                                        && (int) $thread[
                                            'is_pinned'
                                        ] !== 1
                                        && (int) $thread[
                                            'is_locked'
                                        ] !== 1
                                    ): ?>

                                        <span
                                            class="forum-thread-status-icon is-regular"
                                            title="Thread"
                                            aria-label="Thread"
                                        >
                                            <svg
                                                viewBox="0 0 24 24"
                                                aria-hidden="true"
                                            >
                                                <path
                                                    d="M4 4h16v12H8l-4 4V4Zm4 4v2h8V8H8Zm0 4v2h5v-2H8Z"
                                                    fill="currentColor"
                                                />
                                            </svg>
                                        </span>

                                    <?php endif; ?>

                                </div>


                                <div class="forum-thread-copy">


                                    <?php if (
                                        $threadLabels !== []
                                    ): ?>

                                        <div class="forum-thread-labels">

                                            <?php foreach (
                                                $threadLabels
                                                as $label
                                            ): ?>

                                                <?php

                                                $labelColor =
                                                    blackthorne_forum_safe_color(
                                                        (string) $label[
                                                            'label_color'
                                                        ]
                                                    );

                                                ?>

                                                <span
                                                    class="forum-thread-label"
                                                    <?= $labelColor !== null
                                                        ? 'style="--thread-label-color: ' . e(
                                                            $labelColor
                                                        ) . ';"'
                                                        : ''; ?>
                                                >
                                                    <?= e(
                                                        (string) $label[
                                                            'name'
                                                        ]
                                                    ); ?>
                                                </span>

                                            <?php endforeach; ?>

                                        </div>

                                    <?php endif; ?>


                                    <h3>

                                        <a
                                            href="<?= e(
                                                url(
                                                    'thread.php?t='
                                                    . $threadId
                                                )
                                            ); ?>"
                                        >
                                            <?= e(
                                                (string) $thread[
                                                    'title'
                                                ]
                                            ); ?>
                                        </a>

                                    </h3>


                                    <p class="forum-thread-byline">

                                        by

                                        <a
                                            href="<?= e(
                                                url(
                                                    'profile.php?u='
                                                    . (int) $thread[
                                                        'user_id'
                                                    ]
                                                )
                                            ); ?>"
                                            <?= $authorColor !== null
                                                ? 'style="color: ' . e(
                                                    $authorColor
                                                ) . ';"'
                                                : ''; ?>
                                        >
                                            <?= e(
                                                $authorName
                                            ); ?>
                                        </a>

                                        <span aria-hidden="true">
                                            ·
                                        </span>

                                        <time
                                            datetime="<?= e(
                                                (string) $thread[
                                                    'created_at'
                                                ]
                                            ); ?>"
                                        >
                                            <?= e(
                                                blackthorne_forum_datetime(
                                                    (string) $thread[
                                                        'created_at'
                                                    ]
                                                )
                                            ); ?>
                                        </time>

                                    </p>

                                </div>


                                <div class="forum-thread-counts">

                                    <span>
                                        <strong>
                                            <?= number_format(
                                                $replyCount
                                            ); ?>
                                        </strong>
                                        <?= $replyCount === 1
                                            ? 'reply'
                                            : 'replies'; ?>
                                    </span>

                                    <span>
                                        <strong>
                                            <?= number_format(
                                                (int) $thread[
                                                    'view_count'
                                                ]
                                            ); ?>
                                        </strong>
                                        views
                                    </span>

                                </div>


                                <div class="forum-thread-last-post">


                                    <?php if (
                                        (int) (
                                            $thread[
                                                'last_user_id'
                                            ]
                                            ?? 0
                                        ) <= 0
                                    ): ?>

                                        <span class="forum-no-last-post">
                                            No posts yet.
                                        </span>


                                    <?php else: ?>

                                        <div class="forum-last-post-card">

                                            <a
                                                class="forum-last-post-avatar"
                                                href="<?= e(
                                                    url(
                                                        'profile.php?u='
                                                        . (int) $thread[
                                                            'last_user_id'
                                                        ]
                                                    )
                                                ); ?>"
                                            >

                                                <?php if (
                                                    $lastAvatar
                                                    !== null
                                                ): ?>

                                                    <img
                                                        src="<?= e(
                                                            $lastAvatar
                                                        ); ?>"
                                                        alt=""
                                                        loading="lazy"
                                                    >

                                                <?php else: ?>

                                                    <span aria-hidden="true">
                                                        <?= e(
                                                            blackthorne_forum_initials(
                                                                $lastName
                                                            )
                                                        ); ?>
                                                    </span>

                                                <?php endif; ?>

                                            </a>


                                            <div>

                                                <a
                                                    href="<?= e(
                                                        url(
                                                            'profile.php?u='
                                                            . (int) $thread[
                                                                'last_user_id'
                                                            ]
                                                        )
                                                    ); ?>"
                                                    <?= $lastColor !== null
                                                        ? 'style="color: ' . e(
                                                            $lastColor
                                                        ) . ';"'
                                                        : ''; ?>
                                                >
                                                    <?= e(
                                                        $lastName
                                                    ); ?>
                                                </a>

                                                <time
                                                    datetime="<?= e(
                                                        (string) (
                                                            $thread[
                                                                'last_post_created_at'
                                                            ]
                                                            ?? ''
                                                        )
                                                    ); ?>"
                                                >
                                                    <?= e(
                                                        blackthorne_forum_datetime(
                                                            (string) (
                                                                $thread[
                                                                    'last_post_created_at'
                                                                ]
                                                                ?? ''
                                                            )
                                                        )
                                                    ); ?>
                                                </time>

                                            </div>

                                        </div>

                                    <?php endif; ?>

                                </div>

                            </article>


                        <?php endforeach; ?>


                    </div>

                <?php endif; ?>


                <?php if (
                    $totalPages > 1
                ): ?>

                    <nav
                        class="forum-thread-pagination forum-thread-pagination-bottom"
                        aria-label="Thread pages"
                    >

                        <?php if (
                            $page > 1
                        ): ?>

                            <a
                                href="<?= e(
                                    blackthorne_forum_page_url(
                                        $forumId,
                                        $page - 1,
                                        $search
                                    )
                                ); ?>"
                            >
                                Previous
                            </a>

                        <?php endif; ?>


                        <?php for (
                            $paginationPage =
                                max(
                                    1,
                                    $page - 2
                                );
                            $paginationPage <=
                                min(
                                    $totalPages,
                                    $page + 2
                                );
                            $paginationPage++
                        ): ?>

                            <?php if (
                                $paginationPage
                                === $page
                            ): ?>

                                <span
                                    class="is-current"
                                    aria-current="page"
                                >
                                    <?= $paginationPage; ?>
                                </span>

                            <?php else: ?>

                                <a
                                    href="<?= e(
                                        blackthorne_forum_page_url(
                                            $forumId,
                                            $paginationPage,
                                            $search
                                        )
                                    ); ?>"
                                >
                                    <?= $paginationPage; ?>
                                </a>

                            <?php endif; ?>

                        <?php endfor; ?>


                        <?php if (
                            $page < $totalPages
                        ): ?>

                            <a
                                href="<?= e(
                                    blackthorne_forum_page_url(
                                        $forumId,
                                        $page + 1,
                                        $search
                                    )
                                ); ?>"
                            >
                                Next
                            </a>

                        <?php endif; ?>

                    </nav>

                <?php endif; ?>

            </section>

            </div>

        </div>

    </section>

</main>

<script>
(() => {
    const form = document.getElementById('forum-thread-quick-actions-form');
    if (!form) return;

    const toggle = document.querySelector('[data-thread-quick-toggle]');
    const pickers = Array.from(document.querySelectorAll('[data-thread-bulk-picker]'));
    const selectAll = form.querySelector('[data-thread-select-all]');
    const threadChecks = Array.from(document.querySelectorAll('[data-thread-select]'));
    const actionSelect = form.querySelector('[data-thread-bulk-action]');
    const labelField = form.querySelector('[data-thread-label-field]');
    const destinationField = form.querySelector('[data-thread-destination-field]');

    const setQuickActionsOpen = (open) => {
        form.hidden = !open;
        pickers.forEach((picker) => {
            picker.hidden = !open;
        });

        if (toggle) {
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            toggle.textContent = open ? 'Hide Quick Actions' : 'Quick Actions';
        }

        if (!open) {
            threadChecks.forEach((checkbox) => {
                checkbox.checked = false;
            });
            if (selectAll) {
                selectAll.checked = false;
                selectAll.indeterminate = false;
            }
        }
    };

    if (toggle) {
        toggle.addEventListener('click', () => {
            setQuickActionsOpen(form.hidden);
        });
    }

    setQuickActionsOpen(false);

    const syncSelectAll = () => {
        if (!selectAll || threadChecks.length === 0) return;
        const checkedCount = threadChecks.filter((checkbox) => checkbox.checked).length;
        selectAll.checked = checkedCount === threadChecks.length;
        selectAll.indeterminate = checkedCount > 0 && checkedCount < threadChecks.length;
    };

    if (selectAll) {
        selectAll.addEventListener('change', () => {
            threadChecks.forEach((checkbox) => {
                checkbox.checked = selectAll.checked;
            });
            syncSelectAll();
        });
    }

    threadChecks.forEach((checkbox) => {
        checkbox.addEventListener('change', syncSelectAll);
    });

    const syncConditionalFields = () => {
        const action = actionSelect ? actionSelect.value : '';
        const needsLabel = action === 'apply_label' || action === 'remove_label';
        const needsDestination = action === 'move';

        if (labelField) {
            labelField.hidden = !needsLabel;
            const select = labelField.querySelector('select');
            if (select) select.required = needsLabel;
        }

        if (destinationField) {
            destinationField.hidden = !needsDestination;
            const select = destinationField.querySelector('select');
            if (select) select.required = needsDestination;
        }
    };

    if (actionSelect) {
        actionSelect.addEventListener('change', syncConditionalFields);
        syncConditionalFields();
    }

    form.addEventListener('submit', (event) => {
        if (!threadChecks.some((checkbox) => checkbox.checked)) {
            event.preventDefault();
            window.alert('Select at least one thread first.');
        }
    });
})();
</script>

<?php

require INCLUDES_PATH . '/footer.php';
