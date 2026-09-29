<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/forum-functions.php';

require_active_account();


/*
|--------------------------------------------------------------------------
| Blackthorne Academy
| Thread Page
|--------------------------------------------------------------------------
|
| Traditional flat forum thread layout.
| Page 1 shows the starter post plus up to 20 replies.
| Later pages show 20 replies each.
|
*/

$userId =
    (int) current_user_id();


/*
|--------------------------------------------------------------------------
| Local Helpers
|--------------------------------------------------------------------------
*/

function blackthorne_thread_safe_color(?string $color): ?string
{
    $color =
        trim(
            (string) $color
        );

    return preg_match(
        '/^#[0-9a-fA-F]{6}$/',
        $color
    ) === 1
        ? $color
        : null;
}


function blackthorne_thread_avatar_url(?string $avatar): ?string
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


function blackthorne_thread_initials(string $name): string
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

    return $initials === ''
        ? '?'
        : (
            function_exists(
                'mb_strtoupper'
            )
                ? mb_strtoupper(
                    $initials,
                    'UTF-8'
                )
                : strtoupper(
                    $initials
                )
        );
}


function blackthorne_thread_datetime(?string $dateTime): string
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

        return $date
            ->format(
                'M j, Y \a\t g:i A'
            );

    } catch (Throwable) {
        return $dateTime;
    }
}


function blackthorne_thread_page_url(
    int $threadId,
    int $page
): string {
    $parameters = [
        't' => $threadId,
    ];

    if ($page > 1) {
        $parameters['page'] =
            $page;
    }

    return url(
        'thread.php?'
        . http_build_query(
            $parameters
        )
    );
}



function blackthorne_thread_quote_snapshot(string $html): string
{
    $html = preg_replace(
        '~<\s*br\s*/?\s*>~i',
        "\n",
        $html
    ) ?? $html;

    $html = preg_replace(
        '~</\s*(p|div|li|blockquote|h[1-6])\s*>~i',
        "\n",
        $html
    ) ?? $html;

    $text = html_entity_decode(
        strip_tags($html),
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    );

    $text = preg_replace(
        "/[ \t]+\n/u",
        "\n",
        $text
    ) ?? $text;

    $text = preg_replace(
        "/\n{3,}/u",
        "\n\n",
        $text
    ) ?? $text;

    return trim($text);
}


/*
|--------------------------------------------------------------------------
| Resolve Thread
|--------------------------------------------------------------------------
*/

$threadReference =
    trim(
        (string) (
            $_GET['t']
            ?? ''
        )
    );

if (
    $threadReference === ''
    || !ctype_digit(
        $threadReference
    )
) {
    http_response_code(
        404
    );

    $pageTitle =
        'Thread Not Found | Blackthorne Academy';

    $pageDescription =
        'The requested forum thread could not be found.';

    $robots =
        'noindex, nofollow';

    require INCLUDES_PATH . '/header.php';

    ?>

<main id="main-content" class="forum-thread-page">
    <section class="forum-thread-error">
        <div class="section-inner">
            <h1>Thread Not Found</h1>
            <p>
                The requested discussion could not be found.
            </p>
            <a class="button button-secondary" href="<?= e(
                        url(
                            'forums.php'
                        )
                    ); ?>">
                Return to Forums
            </a>
        </div>
    </section>
</main>

<?php

    require INCLUDES_PATH . '/footer.php';

    exit;
}


$threadId =
    (int) $threadReference;

$thread =
    forum_fetch_thread(
        $pdo,
        $threadId
    );


if (
    $thread === null
    || (int) $thread[
        'is_deleted'
    ] === 1
    || !forum_can_view_thread(
        $pdo,
        $threadId,
        $userId
    )
) {
    http_response_code(
        404
    );

    $pageTitle =
        'Thread Not Found | Blackthorne Academy';

    $pageDescription =
        'The requested forum thread could not be found.';

    $robots =
        'noindex, nofollow';

    require INCLUDES_PATH . '/header.php';

    ?>

<main id="main-content" class="forum-thread-page">
    <section class="forum-thread-error">
        <div class="section-inner">
            <h1>Thread Not Found</h1>
            <p>
                The requested discussion could not be found or is not available to your account.
            </p>
            <a class="button button-secondary" href="<?= e(
                        url(
                            'forums.php'
                        )
                    ); ?>">
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
    (int) $thread[
        'forum_id'
    ];

$forum =
    forum_fetch_forum(
        $pdo,
        $forumId
    );


if (
    $forum === null
    || !forum_can_access_forum(
        $pdo,
        $forumId,
        $userId
    )
) {
    http_response_code(
        403
    );

    $pageTitle =
        'Thread Access Restricted | Blackthorne Academy';

    $pageDescription =
        'This discussion is restricted.';

    $robots =
        'noindex, nofollow';

    require INCLUDES_PATH . '/header.php';

    ?>

<main id="main-content" class="forum-thread-page">
    <section class="forum-thread-error">
        <div class="section-inner">
            <h1>Access Restricted</h1>
            <p>
                Your account does not have permission to view this discussion.
            </p>
            <a class="button button-secondary" href="<?= e(
                        url(
                            'forums.php'
                        )
                    ); ?>">
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
| Normalized Forum Media Settings
|--------------------------------------------------------------------------
|
| These values normally come directly from the forums table through
| forum_fetch_forum(). Normalizing them here prevents PHP warnings if an
| older/incomplete forum row or temporarily inconsistent schema omits one
| of the media-setting keys.
|
*/

$forumAllowsImages =
    array_key_exists('allow_images', $forum)
        ? (int) $forum['allow_images'] === 1
        : false;

$forumMaxAttachmentsPerPost =
    isset($forum['max_attachments_per_post'])
        ? max(0, (int) $forum['max_attachments_per_post'])
        : 0;

$forumMaxImageSizeMb =
    isset($forum['max_image_size_mb'])
        ? max(0, (int) $forum['max_image_size_mb'])
        : 0;


/*
|--------------------------------------------------------------------------
| Forum Category + Parent Board
|--------------------------------------------------------------------------
*/

$categoryStatement =
    $pdo->prepare(
        '
        SELECT
            fc.id,
            fc.title

        FROM forum_categories fc

        INNER JOIN forums f
            ON f.category_id = fc.id

        WHERE f.id = :forum_id

        LIMIT 1
        '
    );

$categoryStatement->execute([
    'forum_id' =>
        $forumId,
]);

$category =
    $categoryStatement->fetch(
        PDO::FETCH_ASSOC
    )
    ?: null;


/*
|--------------------------------------------------------------------------
| Forum Ancestors
|--------------------------------------------------------------------------
|
| Forums can be nested recursively. Build the complete visible ancestor chain
| so threads inside deeply nested sub-forums keep their full navigation path.
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
            title

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
| Labels
|--------------------------------------------------------------------------
*/

$labelStatement =
    $pdo->prepare(
        '
        SELECT
            fl.id,
            fl.name,
            fl.label_color

        FROM forum_thread_labels ftl

        INNER JOIN forum_labels fl
            ON fl.id = ftl.label_id

        WHERE ftl.thread_id = :thread_id
          AND fl.forum_id = :forum_id
          AND fl.is_active = 1

        ORDER BY
            fl.sort_order ASC,
            fl.name ASC
        '
    );

$labelStatement->execute([
    'thread_id' =>
        $threadId,

    'forum_id' =>
        $forumId,
]);

$threadLabels =
    $labelStatement->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Starter Post
|--------------------------------------------------------------------------
*/

$starterPostStatement =
    $pdo->prepare(
        '
        SELECT
            fp.id

        FROM forum_posts fp

        WHERE fp.thread_id = :thread_id
          AND fp.is_deleted = 0

        ORDER BY
            fp.id ASC

        LIMIT 1
        '
    );

$starterPostStatement->execute([
    'thread_id' =>
        $threadId,
]);

$starterPostId =
    (int) (
        $starterPostStatement
            ->fetchColumn()
        ?: 0
    );


/*
|--------------------------------------------------------------------------
| Reply Pagination
|--------------------------------------------------------------------------
*/

$repliesPerPage =
    20;

$page =
    max(
        1,
        (int) (
            $_GET['page']
            ?? 1
        )
    );


$replyCountStatement =
    $pdo->prepare(
        '
        SELECT COUNT(*)

        FROM forum_posts fp

        WHERE fp.thread_id = :thread_id
          AND fp.is_deleted = 0
          AND fp.id <> :starter_post_id
        '
    );

$replyCountStatement->execute([
    'thread_id' =>
        $threadId,

    'starter_post_id' =>
        $starterPostId,
]);

$totalReplies =
    (int) $replyCountStatement
        ->fetchColumn();

$totalPages =
    max(
        1,
        (int) ceil(
            $totalReplies
            / $repliesPerPage
        )
    );

if ($page > $totalPages) {
    $page =
        $totalPages;
}

$replyOffset =
    ($page - 1)
    * $repliesPerPage;


/*
|--------------------------------------------------------------------------
| Post Fetch Helper
|--------------------------------------------------------------------------
*/

$postSelectSql = '
    SELECT
        fp.id,
        fp.thread_id,
        fp.user_id,
        fp.content,
        fp.is_edited,
        fp.created_at,
        fp.updated_at,

        u.username,
        u.display_name,
        u.avatar,

        (
            SELECT r.name

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
        ) AS role_name,

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
        ) AS role_color,

        (
            SELECT h.name

            FROM house_memberships hm

            INNER JOIN houses h
                ON h.id = hm.house_id

            WHERE hm.user_id = u.id
              AND hm.membership_status = "active"
              AND hm.left_at IS NULL
              AND h.is_active = 1

            ORDER BY
                hm.joined_at DESC,
                hm.id DESC

            LIMIT 1
        ) AS house_name,

        (
            SELECT h.display_color

            FROM house_memberships hm

            INNER JOIN houses h
                ON h.id = hm.house_id

            WHERE hm.user_id = u.id
              AND hm.membership_status = "active"
              AND hm.left_at IS NULL
              AND h.is_active = 1

            ORDER BY
                hm.joined_at DESC,
                hm.id DESC

            LIMIT 1
        ) AS house_color,

        (
            SELECT COUNT(*)

            FROM forum_posts member_posts

            WHERE member_posts.user_id = u.id
              AND member_posts.is_deleted = 0
        ) AS forum_post_count,

        (
            SELECT COUNT(*)

            FROM forum_reactions member_reactions

            INNER JOIN forum_posts liked_posts
                ON liked_posts.id = member_reactions.post_id

            WHERE liked_posts.user_id = u.id
              AND liked_posts.is_deleted = 0
              AND member_reactions.reaction_type = "like"
        ) AS forum_like_count

    FROM forum_posts fp

    INNER JOIN users u
        ON u.id = fp.user_id
';


$starterPost =
    null;

if (
    $starterPostId > 0
    && $page === 1
) {

    $starterFullStatement =
        $pdo->prepare(
            $postSelectSql
            . '
            WHERE fp.id = :post_id
              AND fp.thread_id = :thread_id
              AND fp.is_deleted = 0

            LIMIT 1
            '
        );

    $starterFullStatement->execute([
        'post_id' =>
            $starterPostId,

        'thread_id' =>
            $threadId,
    ]);

    $starterPost =
        $starterFullStatement->fetch(
            PDO::FETCH_ASSOC
        )
        ?: null;
}


$replyStatement =
    $pdo->prepare(
        $postSelectSql
        . '
        WHERE fp.thread_id = :thread_id
          AND fp.is_deleted = 0
          AND fp.id <> :starter_post_id

        ORDER BY fp.id ASC

        LIMIT :reply_limit
        OFFSET :reply_offset
        '
    );

$replyStatement->bindValue(
    ':thread_id',
    $threadId,
    PDO::PARAM_INT
);

$replyStatement->bindValue(
    ':starter_post_id',
    $starterPostId,
    PDO::PARAM_INT
);

$replyStatement->bindValue(
    ':reply_limit',
    $repliesPerPage,
    PDO::PARAM_INT
);

$replyStatement->bindValue(
    ':reply_offset',
    $replyOffset,
    PDO::PARAM_INT
);

$replyStatement->execute();

$replies =
    $replyStatement->fetchAll(
        PDO::FETCH_ASSOC
    );

$posts =
    [];

if (
    $page === 1
    && $starterPost !== null
) {
    $posts[] =
        $starterPost;
}

foreach ($replies as $reply) {
    $posts[] =
        $reply;
}


/*
|--------------------------------------------------------------------------
| Quote Snapshots
|--------------------------------------------------------------------------
*/

$quotesByPost =
    [];

$postIds =
    array_values(
        array_map(
            static fn (
                array $post
            ): int =>
                (int) $post['id'],
            $posts
        )
    );

if ($postIds !== []) {

    $quotePlaceholders =
        implode(
            ',',
            array_fill(
                0,
                count(
                    $postIds
                ),
                '?'
            )
        );

    $quoteStatement =
        $pdo->prepare(
            '
            SELECT
                post_id,
                quoted_post_id,
                quoted_user_id,
                quoted_display_name_snapshot,
                quoted_content_snapshot,
                sort_order

            FROM forum_post_quotes

            WHERE post_id IN ('
            . $quotePlaceholders
            . ')

            ORDER BY
                post_id ASC,
                sort_order ASC,
                id ASC
            '
        );

    $quoteStatement->execute(
        $postIds
    );

    foreach (
        $quoteStatement->fetchAll(
            PDO::FETCH_ASSOC
        )
        as $quote
    ) {
        $quotesByPost[
            (int) $quote[
                'post_id'
            ]
        ][] =
            $quote;
    }
}


/*
|--------------------------------------------------------------------------
| Forum Mute
|--------------------------------------------------------------------------
*/

$activeForumMute =
    forum_active_mute(
        $pdo,
        $userId
    );

$isForumMuted =
    $activeForumMute !== null;


/*
|--------------------------------------------------------------------------
| Capabilities
|--------------------------------------------------------------------------
*/

$canReply =
    !$isForumMuted
    && forum_can_reply_to_thread(
        $pdo,
        $threadId,
        $userId
    );


/*
|--------------------------------------------------------------------------
| Thread Watch State
|--------------------------------------------------------------------------
*/

$threadWatchStatement =
    $pdo->prepare(
        'SELECT
            id,
            notify_on_update,
            last_notified_post_id
         FROM thread_bookmarks
         WHERE user_id = :user_id
           AND thread_id = :thread_id
         LIMIT 1'
    );

$threadWatchStatement->execute([
    'user_id' => $userId,
    'thread_id' => $threadId,
]);

$threadWatchRecord =
    $threadWatchStatement->fetch(
        PDO::FETCH_ASSOC
    );

$isWatchingThread =
    is_array($threadWatchRecord)
    && (int) ($threadWatchRecord['notify_on_update'] ?? 0) === 1;


$canPinThread =
    forum_can_pin_thread(
        $pdo,
        $threadId,
        $userId
    );

$canLockThread =
    forum_can_lock_thread(
        $pdo,
        $threadId,
        $userId
    );

$canMarkAnnouncement =
    forum_can_mark_announcement(
        $pdo,
        $forumId,
        $userId
    );

$canApplyLabels =
    forum_can_apply_labels(
        $pdo,
        $forumId,
        $userId
    );

$canMoveThread =
    forum_can_move_thread(
        $pdo,
        $forumId,
        $userId
    );

$canEditThread =
    forum_can_edit_thread(
        $pdo,
        $threadId,
        $userId
    );

$canDeleteThread =
    forum_can_delete_thread(
        $pdo,
        $threadId,
        $userId
    );

/*
 * Thread moderation is capability-based. Do not hard-code role names here.
 * The forum resolver already accounts for whatever permissions and explicit
 * board-moderator assignments exist for this forum.
 */
$showThreadModeration =
    forum_user_can_moderate(
        $pdo,
        $forumId,
        $userId
    );


/*
|--------------------------------------------------------------------------
| Thread Moderation Data
|--------------------------------------------------------------------------
*/

$availableThreadLabels = [];
$currentThreadLabelIds = [];
$moveDestinationForums = [];

if ($showThreadModeration) {
    if ($canApplyLabels) {
        $availableThreadLabels =
            forum_fetch_active_labels(
                $pdo,
                $forumId
            );

        $currentThreadLabelIds =
            array_values(
                array_map(
                    static fn (array $label): int =>
                        (int) $label['id'],
                    $threadLabels
                )
            );
    }

    if ($canMoveThread) {
        $destinationStatement = $pdo->prepare(
            'SELECT
                f.id,
                f.title,
                f.parent_forum_id,
                fc.title AS category_title
             FROM forums f
             INNER JOIN forum_categories fc
                ON fc.id = f.category_id
             WHERE f.is_visible = 1
               AND fc.is_visible = 1
               AND f.id <> :current_forum_id
             ORDER BY
                fc.sort_order ASC,
                fc.title ASC,
                f.sort_order ASC,
                f.title ASC'
        );

        $destinationStatement->execute([
            'current_forum_id' => $forumId,
        ]);

        foreach (
            $destinationStatement->fetchAll(PDO::FETCH_ASSOC)
            as $destinationForum
        ) {
            $destinationForumId =
                (int) $destinationForum['id'];

            if (
                $destinationForumId > 0
                && forum_can_view_forum(
                    $pdo,
                    $destinationForumId,
                    $userId
                )
                && forum_can_access_forum(
                    $pdo,
                    $destinationForumId,
                    $userId
                )
            ) {
                $moveDestinationForums[] =
                    $destinationForum;
            }
        }
    }
}



/*
|--------------------------------------------------------------------------
| Post + Thread Actions
|--------------------------------------------------------------------------
*/

if (is_post()) {
    $postAction = (string) ($_POST['action'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | Watch / Unwatch Thread
    |--------------------------------------------------------------------------
    */

    if ($postAction === 'watch_thread') {
        require_valid_csrf();

        $watchStatement =
            $pdo->prepare(
                'INSERT INTO thread_bookmarks (
                    user_id,
                    thread_id,
                    notify_on_update,
                    last_notified_post_id
                 ) VALUES (
                    :user_id,
                    :thread_id,
                    1,
                    :last_notified_post_id
                 )
                 ON DUPLICATE KEY UPDATE
                    notify_on_update = 1,
                    last_notified_post_id = VALUES(last_notified_post_id),
                    updated_at = CURRENT_TIMESTAMP'
            );

        $watchStatement->execute([
            'user_id' => $userId,
            'thread_id' => $threadId,
            'last_notified_post_id' =>
                isset($posts) && $posts !== []
                    ? (int) max(array_column($posts, 'id'))
                    : null,
        ]);

        header(
            'Location: '
            . blackthorne_thread_page_url(
                $threadId,
                $page
            )
            . '#thread-top'
        );
        exit;
    }


    if ($postAction === 'unwatch_thread') {
        require_valid_csrf();

        /*
         * Keep the bookmark record as history/settings state, but disable
         * update notifications for this thread.
         */
        $unwatchStatement =
            $pdo->prepare(
                'UPDATE thread_bookmarks
                 SET
                    notify_on_update = 0,
                    updated_at = CURRENT_TIMESTAMP
                 WHERE user_id = :user_id
                   AND thread_id = :thread_id'
            );

        $unwatchStatement->execute([
            'user_id' => $userId,
            'thread_id' => $threadId,
        ]);

        header(
            'Location: '
            . blackthorne_thread_page_url(
                $threadId,
                $page
            )
            . '#thread-top'
        );
        exit;
    }


    /*
     * Thread moderation actions are capability-based. The protected
     * Super Admin is resolved as full-access by forum-functions.php.
     */
    if ($postAction === 'moderate_lock_thread') {
        require_valid_csrf();

        if (!$canLockThread) {
            http_response_code(403);
            exit('You do not have permission to lock or unlock this thread.');
        }

        $newLockedState =
            (int) $thread['is_locked'] === 1
                ? 0
                : 1;

        $statement = $pdo->prepare(
            'UPDATE forum_threads
             SET is_locked = :is_locked
             WHERE id = :thread_id'
        );

        $statement->execute([
            'is_locked' => $newLockedState,
            'thread_id' => $threadId,
        ]);

        forum_log_moderation_action(
            $pdo,
            $newLockedState === 1 ? 'lock_thread' : 'unlock_thread',
            $userId,
            $forumId,
            $threadId,
            null,
            null,
            (int) $thread['user_id'],
            null,
            null,
            $newLockedState === 1
                ? 'Thread locked from moderation controls.'
                : 'Thread unlocked from moderation controls.'
        );

        header(
            'Location: '
            . blackthorne_thread_page_url($threadId, $page)
            . '&moderated=lock#thread-top'
        );
        exit;
    }

    if ($postAction === 'moderate_pin_thread') {
        require_valid_csrf();

        if (!$canPinThread) {
            http_response_code(403);
            exit('You do not have permission to sticky or unsticky this thread.');
        }

        $newPinnedState =
            (int) $thread['is_pinned'] === 1
                ? 0
                : 1;

        $statement = $pdo->prepare(
            'UPDATE forum_threads
             SET is_pinned = :is_pinned
             WHERE id = :thread_id'
        );

        $statement->execute([
            'is_pinned' => $newPinnedState,
            'thread_id' => $threadId,
        ]);

        forum_log_moderation_action(
            $pdo,
            $newPinnedState === 1 ? 'pin_thread' : 'unpin_thread',
            $userId,
            $forumId,
            $threadId,
            null,
            null,
            (int) $thread['user_id'],
            null,
            null,
            $newPinnedState === 1
                ? 'Thread made sticky from moderation controls.'
                : 'Sticky status removed from moderation controls.'
        );

        header(
            'Location: '
            . blackthorne_thread_page_url($threadId, $page)
            . '&moderated=pin#thread-top'
        );
        exit;
    }

    if ($postAction === 'moderate_announcement') {
        require_valid_csrf();

        if (!$canMarkAnnouncement) {
            http_response_code(403);
            exit('You do not have permission to change announcement status.');
        }

        $newAnnouncementState =
            (int) $thread['is_announcement'] === 1
                ? 0
                : 1;

        $statement = $pdo->prepare(
            'UPDATE forum_threads
             SET is_announcement = :is_announcement
             WHERE id = :thread_id'
        );

        $statement->execute([
            'is_announcement' => $newAnnouncementState,
            'thread_id' => $threadId,
        ]);

        forum_log_moderation_action(
            $pdo,
            $newAnnouncementState === 1
                ? 'mark_announcement'
                : 'remove_announcement',
            $userId,
            $forumId,
            $threadId,
            null,
            null,
            (int) $thread['user_id'],
            null,
            null,
            $newAnnouncementState === 1
                ? 'Thread marked as an announcement.'
                : 'Announcement status removed.'
        );

        header(
            'Location: '
            . blackthorne_thread_page_url($threadId, $page)
            . '&moderated=announcement#thread-top'
        );
        exit;
    }

    if ($postAction === 'moderate_labels') {
        require_valid_csrf();

        if (!$canApplyLabels) {
            http_response_code(403);
            exit('You do not have permission to apply thread labels.');
        }

        $submittedLabelIds =
            array_values(
                array_unique(
                    array_filter(
                        array_map(
                            'intval',
                            (array) ($_POST['label_ids'] ?? [])
                        ),
                        static fn (int $labelId): bool =>
                            $labelId > 0
                    )
                )
            );

        $validLabelIds = [];

        if ($submittedLabelIds !== []) {
            $placeholders =
                implode(
                    ',',
                    array_fill(
                        0,
                        count($submittedLabelIds),
                        '?'
                    )
                );

            $labelValidationStatement = $pdo->prepare(
                'SELECT id
                 FROM forum_labels
                 WHERE forum_id = ?
                   AND is_active = 1
                   AND id IN (' . $placeholders . ')'
            );

            $labelValidationStatement->execute(
                array_merge(
                    [$forumId],
                    $submittedLabelIds
                )
            );

            $validLabelIds =
                array_map(
                    'intval',
                    $labelValidationStatement->fetchAll(
                        PDO::FETCH_COLUMN
                    )
                );
        }

        try {
            $pdo->beginTransaction();

            $deleteLabelsStatement = $pdo->prepare(
                'DELETE FROM forum_thread_labels
                 WHERE thread_id = :thread_id'
            );

            $deleteLabelsStatement->execute([
                'thread_id' => $threadId,
            ]);

            if ($validLabelIds !== []) {
                $insertLabelStatement = $pdo->prepare(
                    'INSERT INTO forum_thread_labels (
                        thread_id,
                        label_id,
                        assigned_by
                     ) VALUES (
                        :thread_id,
                        :label_id,
                        :assigned_by
                     )'
                );

                foreach ($validLabelIds as $labelId) {
                    $insertLabelStatement->execute([
                        'thread_id' => $threadId,
                        'label_id' => $labelId,
                        'assigned_by' => $userId,
                    ]);

                    forum_log_moderation_action(
                        $pdo,
                        'apply_label',
                        $userId,
                        $forumId,
                        $threadId,
                        null,
                        $labelId,
                        (int) $thread['user_id'],
                        null,
                        null,
                        'Thread label applied from moderation controls.'
                    );
                }
            }

            $pdo->commit();

        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'Blackthorne thread label moderation error: '
                . $exception->getMessage()
            );

            http_response_code(500);
            exit('The thread labels could not be updated.');
        }

        header(
            'Location: '
            . blackthorne_thread_page_url($threadId, $page)
            . '&moderated=labels#thread-top'
        );
        exit;
    }

    if ($postAction === 'moderate_move_thread') {
        require_valid_csrf();

        if (!$canMoveThread) {
            http_response_code(403);
            exit('You do not have permission to move this thread.');
        }

        $destinationForumId =
            max(
                0,
                (int) ($_POST['destination_forum_id'] ?? 0)
            );

        if (
            $destinationForumId <= 0
            || $destinationForumId === $forumId
            || !forum_can_view_forum(
                $pdo,
                $destinationForumId,
                $userId
            )
            || !forum_can_access_forum(
                $pdo,
                $destinationForumId,
                $userId
            )
        ) {
            http_response_code(400);
            exit('Choose a valid destination forum.');
        }

        $destinationExistsStatement = $pdo->prepare(
            'SELECT id
             FROM forums
             WHERE id = :forum_id
               AND is_visible = 1
             LIMIT 1'
        );

        $destinationExistsStatement->execute([
            'forum_id' => $destinationForumId,
        ]);

        if (!$destinationExistsStatement->fetchColumn()) {
            http_response_code(404);
            exit('Destination forum not found.');
        }

        try {
            $pdo->beginTransaction();

            $moveStatement = $pdo->prepare(
                'UPDATE forum_threads
                 SET forum_id = :destination_forum_id
                 WHERE id = :thread_id'
            );

            $moveStatement->execute([
                'destination_forum_id' => $destinationForumId,
                'thread_id' => $threadId,
            ]);

            /*
             * Labels belong to a specific forum, so old-board labels cannot
             * remain attached after the thread is moved.
             */
            $clearLabelsStatement = $pdo->prepare(
                'DELETE FROM forum_thread_labels
                 WHERE thread_id = :thread_id'
            );

            $clearLabelsStatement->execute([
                'thread_id' => $threadId,
            ]);

            /*
             * Keep any thread poll associated with the board it now lives in.
             */
            $movePollStatement = $pdo->prepare(
                "UPDATE polls
                 SET forum_id = :destination_forum_id
                 WHERE thread_id = :thread_id
                   AND poll_scope = 'forum'"
            );

            $movePollStatement->execute([
                'destination_forum_id' => $destinationForumId,
                'thread_id' => $threadId,
            ]);

            forum_log_moderation_action(
                $pdo,
                'move_thread',
                $userId,
                $forumId,
                $threadId,
                null,
                null,
                (int) $thread['user_id'],
                null,
                null,
                'Thread moved from forum '
                    . $forumId
                    . ' to forum '
                    . $destinationForumId
                    . '.'
            );

            /*
             * Correct the source/destination thread columns are thread IDs,
             * not forum IDs. The forum transition itself is preserved in notes.
             */
            $pdo->commit();

        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'Blackthorne thread move error: '
                . $exception->getMessage()
            );

            http_response_code(500);
            exit('The thread could not be moved.');
        }

        header(
            'Location: '
            . url('thread.php?t=' . $threadId)
            . '&moderated=move#thread-top'
        );
        exit;
    }

    if ($postAction === 'delete_post') {
        require_valid_csrf();

        $targetPostId = max(0, (int) ($_POST['post_id'] ?? 0));
        $targetPost = forum_fetch_post($pdo, $targetPostId);

        if (
            !$targetPost
            || (int) $targetPost['thread_id'] !== $threadId
            || (int) $targetPost['is_deleted'] === 1
        ) {
            http_response_code(404);
            exit('Post not found.');
        }

        $isStarterPost = $targetPostId === $starterPostId;

        if ($isStarterPost) {
            if (!forum_can_delete_thread($pdo, $threadId, $userId)) {
                http_response_code(403);
                exit('You do not have permission to delete this thread.');
            }

            try {
                $pdo->beginTransaction();

                $deleteThreadStatement = $pdo->prepare(
                    "UPDATE forum_threads
                     SET is_deleted = 1,
                         deleted_at = NOW(),
                         deleted_by = :deleted_by
                     WHERE id = :thread_id
                       AND is_deleted = 0"
                );
                $deleteThreadStatement->execute([
                    'deleted_by' => $userId,
                    'thread_id' => $threadId,
                ]);

                $moderationStatement = $pdo->prepare(
                    "INSERT INTO moderation_actions (
                        moderator_id,
                        forum_id,
                        thread_id,
                        post_id,
                        action_type,
                        reason
                     ) VALUES (
                        :moderator_id,
                        :forum_id,
                        :thread_id,
                        :post_id,
                        'delete_thread',
                        :reason
                     )"
                );
                $moderationStatement->execute([
                    'moderator_id' => $userId,
                    'forum_id' => $forumId,
                    'thread_id' => $threadId,
                    'post_id' => $targetPostId,
                    'reason' => 'Thread deleted from starter post control.',
                ]);

                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Blackthorne thread delete error: ' . $exception->getMessage());
                http_response_code(500);
                exit('The thread could not be deleted. Please try again.');
            }

            header('Location: ' . url('forum.php?f=' . $forumId . '&deleted=thread'));
            exit;
        }

        if (!forum_can_delete_post($pdo, $targetPostId, $userId)) {
            http_response_code(403);
            exit('You do not have permission to delete this post.');
        }

        try {
            $pdo->beginTransaction();

            $deletePostStatement = $pdo->prepare(
                "UPDATE forum_posts
                 SET is_deleted = 1,
                     deleted_at = NOW(),
                     deleted_by = :deleted_by
                 WHERE id = :post_id
                   AND thread_id = :thread_id
                   AND is_deleted = 0"
            );
            $deletePostStatement->execute([
                'deleted_by' => $userId,
                'post_id' => $targetPostId,
                'thread_id' => $threadId,
            ]);

            $refreshActivityStatement = $pdo->prepare(
                "UPDATE forum_threads ft
                 SET last_activity_at = COALESCE(
                     (
                         SELECT MAX(fp.created_at)
                         FROM forum_posts fp
                         WHERE fp.thread_id = ft.id
                           AND fp.is_deleted = 0
                     ),
                     ft.created_at
                 )
                 WHERE ft.id = :thread_id"
            );
            $refreshActivityStatement->execute([
                'thread_id' => $threadId,
            ]);

            $moderationStatement = $pdo->prepare(
                "INSERT INTO moderation_actions (
                    moderator_id,
                    target_user_id,
                    forum_id,
                    thread_id,
                    post_id,
                    action_type,
                    reason
                 ) VALUES (
                    :moderator_id,
                    :target_user_id,
                    :forum_id,
                    :thread_id,
                    :post_id,
                    'delete_post',
                    :reason
                 )"
            );
            $moderationStatement->execute([
                'moderator_id' => $userId,
                'target_user_id' => (int) $targetPost['user_id'],
                'forum_id' => $forumId,
                'thread_id' => $threadId,
                'post_id' => $targetPostId,
                'reason' => 'Post deleted from thread controls.',
            ]);

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Blackthorne post delete error: ' . $exception->getMessage());
            http_response_code(500);
            exit('The post could not be deleted. Please try again.');
        }

        header(
            'Location: '
            . blackthorne_thread_page_url($threadId, $page)
            . '&deleted=post#thread-top'
        );
        exit;
    }

    if ($postAction === 'report_post') {
        require_valid_csrf();

        $targetPostId = max(0, (int) ($_POST['post_id'] ?? 0));
        $reportReason = trim((string) ($_POST['reason'] ?? ''));
        $reportDetails = trim((string) ($_POST['details'] ?? ''));

        $allowedReportReasons = [
            'Spam or advertising',
            'Harassment or bullying',
            'Inappropriate content',
            'Personal or private information',
            'Other',
        ];

        $targetPost = forum_fetch_post($pdo, $targetPostId);

        if (
            !$targetPost
            || (int) $targetPost['thread_id'] !== $threadId
            || (int) $targetPost['is_deleted'] === 1
        ) {
            http_response_code(404);
            exit('Post not found.');
        }

        if (!in_array($reportReason, $allowedReportReasons, true)) {
            header(
                'Location: '
                . blackthorne_thread_page_url($threadId, $page)
                . '&report_error=reason#post-'
                . $targetPostId
            );
            exit;
        }

        if (mb_strlen($reportDetails) > 2000) {
            $reportDetails = mb_substr($reportDetails, 0, 2000);
        }

        $existingReportStatement = $pdo->prepare(
            "SELECT id
             FROM forum_reports
             WHERE reported_by = :reported_by
               AND post_id = :post_id
               AND status IN ('open', 'reviewing')
             ORDER BY id DESC
             LIMIT 1"
        );

        $existingReportStatement->execute([
            'reported_by' => $userId,
            'post_id' => $targetPostId,
        ]);

        if ((int) ($existingReportStatement->fetchColumn() ?: 0) > 0) {
            header(
                'Location: '
                . blackthorne_thread_page_url($threadId, $page)
                . '&reported=existing#post-'
                . $targetPostId
            );
            exit;
        }

        $insertReportStatement = $pdo->prepare(
            "INSERT INTO forum_reports (
                reported_by,
                thread_id,
                thread_title_snapshot,
                post_id,
                post_content_snapshot,
                reason,
                details,
                status,
                priority
             ) VALUES (
                :reported_by,
                :thread_id,
                :thread_title_snapshot,
                :post_id,
                :post_content_snapshot,
                :reason,
                :details,
                'open',
                'normal'
             )"
        );

        try {
            $pdo->beginTransaction();

            $insertReportStatement->execute([
                'reported_by' => $userId,
                'thread_id' => $threadId,
                'thread_title_snapshot' => (string) $thread['title'],
                'post_id' => $targetPostId,
                'post_content_snapshot' => (string) $targetPost['content'],
                'reason' => $reportReason,
                'details' => $reportDetails !== '' ? $reportDetails : null,
            ]);

            $reportId = (int) $pdo->lastInsertId();

            /*
             * Notify active users whose effective global permissions allow
             * them to work with forum reports. Report handling is now a
             * site-wide permission system, so notification eligibility must
             * not depend on being assigned as a moderator in this specific
             * board.
             *
             * The protected Admin is included automatically because
             * user_can_by_id() applies the protected Super Admin override.
             */
            $notificationCandidatesStatement = $pdo->query(
                "SELECT
                    u.id,
                    COALESCE(np.notify_moderation, 1) AS notify_moderation
                 FROM users u
                 LEFT JOIN notification_preferences np
                    ON np.user_id = u.id
                 WHERE u.status = 'active'"
            );

            $notificationCandidates =
                $notificationCandidatesStatement->fetchAll(PDO::FETCH_ASSOC);

            $reporterNameStatement = $pdo->prepare(
                'SELECT display_name FROM users WHERE id = :user_id LIMIT 1'
            );
            $reporterNameStatement->execute(['user_id' => $userId]);

            $reporterName = trim((string) ($reporterNameStatement->fetchColumn() ?: 'A member'));

            $notificationInsertStatement = $pdo->prepare(
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

            $notificationLink =
                url('admin/reports.php')
                . '?status=all#report-'
                . $reportId;

            foreach ($notificationCandidates as $candidate) {
                $candidateUserId = (int) ($candidate['id'] ?? 0);

                $canReceiveReportNotification =
                    $candidateUserId > 0
                    && (
                        user_can_by_id(
                            $candidateUserId,
                            'moderation.reports.view'
                        )
                        || user_can_by_id(
                            $candidateUserId,
                            'moderation.reports.review'
                        )
                        || user_can_by_id(
                            $candidateUserId,
                            'moderation.reports.resolve'
                        )
                        || user_can_by_id(
                            $candidateUserId,
                            'moderation.reports.dismiss'
                        )
                    );

                if (
                    $candidateUserId <= 0
                    || $candidateUserId === $userId
                    || (int) ($candidate['notify_moderation'] ?? 1) !== 1
                    || !$canReceiveReportNotification
                ) {
                    continue;
                }

                $notificationInsertStatement->execute([
                    'user_id' => $candidateUserId,
                    'actor_user_id' => $userId,
                    'related_entity_id' => $reportId,
                    'title' => 'New forum report',
                    'message' => $reporterName
                        . ' reported a post in “'
                        . (string) $thread['title']
                        . '” for '
                        . $reportReason
                        . '.',
                    'link_url' => $notificationLink,
                ]);
            }

            $pdo->commit();

        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'Blackthorne forum report submission error: '
                . $exception->getMessage()
            );

            http_response_code(500);
            exit('The report could not be submitted. Please try again.');
        }

        header(
            'Location: '
            . blackthorne_thread_page_url($threadId, $page)
            . '&reported=1#post-'
            . $targetPostId
        );
        exit;
    }

    if ($postAction === 'toggle_like') {
        require_valid_csrf();

        $targetPostId = max(
            0,
            (int) ($_POST['post_id'] ?? 0)
        );

        $targetPost = forum_fetch_post(
            $pdo,
            $targetPostId
        );

        if (
            !$targetPost
            || (int) $targetPost['thread_id'] !== $threadId
            || (int) $targetPost['is_deleted'] === 1
        ) {
            http_response_code(404);
            exit('Post not found.');
        }

        $existingReactionStatement = $pdo->prepare(
            'SELECT id, reaction_type
             FROM forum_reactions
             WHERE post_id = :post_id
               AND user_id = :user_id
             LIMIT 1'
        );

        $existingReactionStatement->execute([
            'post_id' => $targetPostId,
            'user_id' => $userId,
        ]);

        $existingReaction = $existingReactionStatement->fetch(PDO::FETCH_ASSOC);

        if (
            $existingReaction
            && (string) $existingReaction['reaction_type'] === 'like'
        ) {
            $deleteReactionStatement = $pdo->prepare(
                'DELETE FROM forum_reactions
                 WHERE id = :reaction_id
                 LIMIT 1'
            );

            $deleteReactionStatement->execute([
                'reaction_id' => (int) $existingReaction['id'],
            ]);
        } elseif ($existingReaction) {
            $updateReactionStatement = $pdo->prepare(
                'UPDATE forum_reactions
                 SET reaction_type = :reaction_type
                 WHERE id = :reaction_id'
            );

            $updateReactionStatement->execute([
                'reaction_type' => 'like',
                'reaction_id' => (int) $existingReaction['id'],
            ]);
        } else {
            $insertReactionStatement = $pdo->prepare(
                'INSERT INTO forum_reactions (
                    post_id,
                    user_id,
                    reaction_type
                 ) VALUES (
                    :post_id,
                    :user_id,
                    :reaction_type
                 )'
            );

            $insertReactionStatement->execute([
                'post_id' => $targetPostId,
                'user_id' => $userId,
                'reaction_type' => 'like',
            ]);
        }

        header(
            'Location: '
            . blackthorne_thread_page_url(
                $threadId,
                $page
            )
            . '#post-'
            . $targetPostId
        );
        exit;
    }

    if ($postAction === 'edit_post') {
        require_valid_csrf();

        if ($isForumMuted) {
            http_response_code(403);
            exit('Your account is currently muted from posting or editing forum content.');
        }

        $targetPostId = max(
            0,
            (int) ($_POST['post_id'] ?? 0)
        );

        $targetPost = forum_fetch_post(
            $pdo,
            $targetPostId
        );

        if (
            !$targetPost
            || (int) $targetPost['thread_id'] !== $threadId
        ) {
            http_response_code(404);
            exit('Post not found.');
        }

        if (
            !forum_can_edit_post(
                $pdo,
                $targetPostId,
                $userId
            )
        ) {
            http_response_code(403);
            exit('You do not have permission to edit this post.');
        }

        $editedContent =
            trim(
                (string) ($_POST['content'] ?? '')
            );

        $safeEditedContent =
            sanitize_rich_text(
                $editedContent
            );

        $safeEditedPlain =
            trim(
                html_entity_decode(
                    strip_tags(
                        $safeEditedContent
                    ),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                )
            );

        $safeEditedHasImage =
            preg_match(
                '/<img\b[^>]*\bsrc=/i',
                $safeEditedContent
            ) === 1;

        if (
            $safeEditedPlain === ''
            && !$safeEditedHasImage
        ) {
            $_SESSION['flash_error'] =
                'A post cannot be empty.';

            header(
                'Location: '
                . blackthorne_thread_page_url(
                    $threadId,
                    $page
                )
                . '&edit='
                . $targetPostId
                . '#post-'
                . $targetPostId
            );
            exit;
        }

        $previousContent =
            (string) $targetPost['content'];

        if (
            $safeEditedContent
            !== $previousContent
        ) {
            try {
                $pdo->beginTransaction();

                $editHistoryStatement =
                    $pdo->prepare(
                        'INSERT INTO forum_post_edits (
                            post_id,
                            edited_by,
                            previous_content,
                            edit_reason
                         ) VALUES (
                            :post_id,
                            :edited_by,
                            :previous_content,
                            NULL
                         )'
                    );

                $editHistoryStatement->execute([
                    'post_id' => $targetPostId,
                    'edited_by' => $userId,
                    'previous_content' => $previousContent,
                ]);

                $updateEditedPostStatement =
                    $pdo->prepare(
                        'UPDATE forum_posts
                         SET
                            content = :content,
                            is_edited = 1,
                            updated_at = CURRENT_TIMESTAMP
                         WHERE id = :post_id'
                    );

                $updateEditedPostStatement->execute([
                    'content' => $safeEditedContent,
                    'post_id' => $targetPostId,
                ]);

                if (
                    (int) $targetPost['user_id']
                    !== $userId
                ) {
                    $moderationEditStatement =
                        $pdo->prepare(
                            'INSERT INTO moderation_actions (
                                moderator_id,
                                target_user_id,
                                forum_id,
                                thread_id,
                                post_id,
                                action_type,
                                reason
                             ) VALUES (
                                :moderator_id,
                                :target_user_id,
                                :forum_id,
                                :thread_id,
                                :post_id,
                                :action_type,
                                :reason
                             )'
                        );

                    $moderationEditStatement->execute([
                        'moderator_id' => $userId,
                        'target_user_id' => (int) $targetPost['user_id'],
                        'forum_id' => $forumId,
                        'thread_id' => $threadId,
                        'post_id' => $targetPostId,
                        'action_type' => 'edit_post',
                        'reason' => 'Post edited by forum staff.',
                    ]);
                }

                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $_SESSION['flash_error'] =
                    'The post could not be updated. Please try again.';

                header(
                    'Location: '
                    . blackthorne_thread_page_url(
                        $threadId,
                        $page
                    )
                    . '&edit='
                    . $targetPostId
                    . '#post-'
                    . $targetPostId
                );
                exit;
            }
        }

        header(
            'Location: '
            . blackthorne_thread_page_url(
                $threadId,
                $page
            )
            . '#post-'
            . $targetPostId
        );
        exit;
    }
}


$editPostId =
    max(
        0,
        (int) ($_GET['edit'] ?? 0)
    );

if ($isForumMuted) {
    $editPostId = 0;
}

if ($editPostId > 0) {
    $editPost =
        forum_fetch_post(
            $pdo,
            $editPostId
        );

    if (
        !$editPost
        || (int) $editPost['thread_id'] !== $threadId
        || !forum_can_edit_post(
            $pdo,
            $editPostId,
            $userId
        )
    ) {
        $editPostId = 0;
    }
}


/*
|--------------------------------------------------------------------------
| Reply Submission
|--------------------------------------------------------------------------
*/

$replyErrors = [];
$replyContent = '';

if (is_post() && (string) ($_POST['action'] ?? '') === 'reply') {
    require_valid_csrf();

    if ($isForumMuted) {
        $replyErrors[] =
            'Your account is currently muted from posting forum content.';
    }

    $replyContent = trim((string) ($_POST['content'] ?? ''));

    $quotedPostIdsRaw =
        json_decode(
            (string) ($_POST['quote_post_ids'] ?? '[]'),
            true
        );

    $quotedPostIds =
        is_array(
            $quotedPostIdsRaw
        )
            ? array_values(
                array_unique(
                    array_filter(
                        array_map(
                            'intval',
                            $quotedPostIdsRaw
                        ),
                        static fn (int $quotedPostId): bool =>
                            $quotedPostId > 0
                    )
                )
            )
            : [];

    if (count($quotedPostIds) > 10) {
        $replyErrors[] =
            'You can quote up to 10 posts in one reply.';
    }

    $validatedQuotePosts = [];

    foreach (
        array_slice(
            $quotedPostIds,
            0,
            10
        )
        as $quotedPostId
    ) {
        $quotedPost =
            forum_fetch_post(
                $pdo,
                $quotedPostId
            );

        if (
            !$quotedPost
            || (int) $quotedPost['thread_id'] !== $threadId
            || !forum_can_quote_post(
                $pdo,
                $quotedPostId,
                $userId
            )
        ) {
            $replyErrors[] =
                'One of the quoted posts is no longer available.';
            continue;
        }

        $validatedQuotePosts[] =
            $quotedPost;
    }


    if (!forum_can_reply_to_thread($pdo, $threadId, $userId)) {
        $replyErrors[] = 'You no longer have permission to reply to this thread.';
    }

    $plainReplyContent = trim(
        html_entity_decode(
            strip_tags($replyContent),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        )
    );

    $replyHasImage = preg_match('/<img\b/i', $replyContent) === 1;

    if ($plainReplyContent === '' && !$replyHasImage) {
        $replyErrors[] = 'Write a reply or add an image before posting.';
    }

    if (strlen($replyContent) > 500000) {
        $replyErrors[] = 'The reply is too large. Please shorten it and try again.';
    }

    $allowedImageMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];

    $normalizeUploadFiles = static function (array $files): array {
        if (!isset($files['name']) || !is_array($files['name'])) {
            return [];
        }

        $normalized = [];

        foreach ($files['name'] as $index => $name) {
            $normalized[] = [
                'name' => (string) $name,
                'type' => (string) ($files['type'][$index] ?? ''),
                'tmp_name' => (string) ($files['tmp_name'][$index] ?? ''),
                'error' => (int) ($files['error'][$index] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int) ($files['size'][$index] ?? 0),
            ];
        }

        return $normalized;
    };

    $uploadFiles = $normalizeUploadFiles($_FILES['uploaded_images'] ?? []);
    $uploadFiles = array_values(array_filter(
        $uploadFiles,
        static fn (array $file): bool => $file['error'] !== UPLOAD_ERR_NO_FILE
    ));

    $uploadTokensRaw = json_decode((string) ($_POST['upload_tokens'] ?? '[]'), true);
    $uploadTokens = is_array($uploadTokensRaw) ? array_values($uploadTokensRaw) : [];

    $maxUploads = max(0, $forumMaxAttachmentsPerPost);
    $maxImageBytes = max(1, $forumMaxImageSizeMb) * 1024 * 1024;
    $validatedUploads = [];

    if ($uploadFiles !== [] && !$forumAllowsImages) {
        $replyErrors[] = 'Image uploads are not enabled in this board.';
    }

    if ($maxUploads > 0 && count($uploadFiles) > $maxUploads) {
        $replyErrors[] = 'You can upload up to ' . $maxUploads . ' images in one reply.';
    }

    if (count($uploadTokens) !== count($uploadFiles)) {
        if ($uploadFiles !== []) {
            $replyErrors[] = 'The selected images could not be matched to the editor. Please select them again.';
        }
    } else {
        $finfo = new finfo(FILEINFO_MIME_TYPE);

        foreach ($uploadFiles as $index => $file) {
            $token = strtolower(trim((string) ($uploadTokens[$index] ?? '')));

            if (preg_match('/^[a-z0-9_-]{8,80}$/', $token) !== 1) {
                $replyErrors[] = 'One of the selected images has an invalid upload reference. Please select it again.';
                continue;
            }

            if ($file['error'] !== UPLOAD_ERR_OK) {
                $replyErrors[] = 'The image “' . $file['name'] . '” could not be uploaded.';
                continue;
            }

            if ($file['size'] < 1 || $file['size'] > $maxImageBytes) {
                $replyErrors[] = 'The image “' . $file['name'] . '” must be ' . $forumMaxImageSizeMb . ' MB or smaller.';
                continue;
            }

            if (!is_uploaded_file($file['tmp_name'])) {
                $replyErrors[] = 'The image “' . $file['name'] . '” was not received as a valid upload.';
                continue;
            }

            $mimeType = (string) $finfo->file($file['tmp_name']);

            if (!array_key_exists($mimeType, $allowedImageMimeTypes)) {
                $replyErrors[] = 'The image “' . $file['name'] . '” must be a JPG, PNG, GIF, WEBP, or AVIF file.';
                continue;
            }

            if (@getimagesize($file['tmp_name']) === false) {
                $replyErrors[] = 'The file “' . $file['name'] . '” is not a valid image.';
                continue;
            }

            $validatedUploads[] = [
                'token' => $token,
                'name' => function_exists('mb_substr')
                    ? mb_substr(basename($file['name']), 0, 255, 'UTF-8')
                    : substr(basename($file['name']), 0, 255),
                'tmp_name' => $file['tmp_name'],
                'size' => $file['size'],
                'mime_type' => $mimeType,
                'extension' => $allowedImageMimeTypes[$mimeType],
            ];
        }
    }

    if ($replyErrors === []) {
        try {
            $pdo->beginTransaction();

            $postStatement = $pdo->prepare(
                'INSERT INTO forum_posts (
                    thread_id,
                    user_id,
                    parent_post_id,
                    content
                 ) VALUES (
                    :thread_id,
                    :user_id,
                    NULL,
                    :content
                 )'
            );

            $postStatement->execute([
                'thread_id' => $threadId,
                'user_id' => $userId,
                'content' => '<p>Preparing post…</p>',
            ]);

            $newPostId = (int) $pdo->lastInsertId();
            $finalReplyContent = $replyContent;
            $movedUploadPaths = [];

            if ($validatedUploads !== []) {
                $relativeDirectory = 'forum/' . date('Y') . '/' . date('m');
                $absoluteDirectory = rtrim(UPLOADS_PATH, '/\\') . DIRECTORY_SEPARATOR
                    . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);

                if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0755, true) && !is_dir($absoluteDirectory)) {
                    throw new RuntimeException('The forum image upload directory could not be created.');
                }

                $uploadsUrlPath = (string) parse_url(UPLOADS_URL, PHP_URL_PATH);
                $uploadsUrlPath = '/' . trim($uploadsUrlPath, '/');

                $attachmentStatement = $pdo->prepare(
                    'INSERT INTO forum_attachments (
                        post_id,
                        uploaded_by,
                        original_filename,
                        stored_filename,
                        file_path,
                        mime_type,
                        file_size,
                        attachment_type
                     ) VALUES (
                        :post_id,
                        :uploaded_by,
                        :original_filename,
                        :stored_filename,
                        :file_path,
                        :mime_type,
                        :file_size,
                        :attachment_type
                     )'
                );

                foreach ($validatedUploads as $upload) {
                    $storedFilename = bin2hex(random_bytes(18)) . '.' . $upload['extension'];
                    $relativePath = $relativeDirectory . '/' . $storedFilename;
                    $absolutePath = $absoluteDirectory . DIRECTORY_SEPARATOR . $storedFilename;

                    if (!move_uploaded_file($upload['tmp_name'], $absolutePath)) {
                        throw new RuntimeException('An uploaded image could not be saved.');
                    }

                    $movedUploadPaths[] = $absolutePath;
                    $publicPath = $uploadsUrlPath . '/' . $relativePath;
                    $placeholder = '/__blackthorne_pending_image_' . $upload['token'] . '__';
                    $finalReplyContent = str_replace($placeholder, $publicPath, $finalReplyContent);

                    $attachmentStatement->execute([
                        'post_id' => $newPostId,
                        'uploaded_by' => $userId,
                        'original_filename' => $upload['name'],
                        'stored_filename' => $storedFilename,
                        'file_path' => $relativePath,
                        'mime_type' => $upload['mime_type'],
                        'file_size' => $upload['size'],
                        'attachment_type' => 'image',
                    ]);
                }
            }

            $safeReplyContent = sanitize_rich_text($finalReplyContent);
            $safePlainReply = trim(
                html_entity_decode(
                    strip_tags($safeReplyContent),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                )
            );
            $safeReplyHasImage = preg_match('/<img\b[^>]*\bsrc=/i', $safeReplyContent) === 1;

            if ($safePlainReply === '' && !$safeReplyHasImage) {
                throw new RuntimeException('Your reply did not contain any supported forum content.');
            }

            $updatePostStatement = $pdo->prepare(
                'UPDATE forum_posts
                 SET content = :content
                 WHERE id = :post_id'
            );

            $updatePostStatement->execute([
                'content' => $safeReplyContent,
                'post_id' => $newPostId,
            ]);

            if ($validatedQuotePosts !== []) {
                $quoteInsertStatement =
                    $pdo->prepare(
                        'INSERT INTO forum_post_quotes (
                            post_id,
                            quoted_post_id,
                            quoted_user_id,
                            quoted_display_name_snapshot,
                            quoted_content_snapshot,
                            sort_order
                         ) VALUES (
                            :post_id,
                            :quoted_post_id,
                            :quoted_user_id,
                            :quoted_display_name_snapshot,
                            :quoted_content_snapshot,
                            :sort_order
                         )'
                    );

                $quotedUserNameStatement =
                    $pdo->prepare(
                        'SELECT display_name
                         FROM users
                         WHERE id = :user_id
                         LIMIT 1'
                    );

                foreach (
                    $validatedQuotePosts
                    as $quoteSortOrder => $quotedPost
                ) {
                    $quotedUserNameStatement->execute([
                        'user_id' => (int) $quotedPost['user_id'],
                    ]);

                    $quotedDisplayName =
                        trim(
                            (string) (
                                $quotedUserNameStatement->fetchColumn()
                                ?: 'Member'
                            )
                        );

                    $quoteInsertStatement->execute([
                        'post_id' => $newPostId,
                        'quoted_post_id' => (int) $quotedPost['id'],
                        'quoted_user_id' => (int) $quotedPost['user_id'],
                        'quoted_display_name_snapshot' => $quotedDisplayName,
                        'quoted_content_snapshot' =>
                            blackthorne_thread_quote_snapshot(
                                (string) $quotedPost['content']
                            ),
                        'sort_order' => $quoteSortOrder,
                    ]);
                }
            }

            forum_touch_thread_activity($pdo, $threadId);

            $readStatusStatement = $pdo->prepare(
                'INSERT INTO thread_read_status (
                    user_id, thread_id, last_read_post_id, last_read_at
                 ) VALUES (
                    :user_id, :thread_id, :last_read_post_id, CURRENT_TIMESTAMP
                 )
                 ON DUPLICATE KEY UPDATE
                    last_read_post_id = VALUES(last_read_post_id),
                    last_read_at = CURRENT_TIMESTAMP'
            );

            $readStatusStatement->execute([
                'user_id' => $userId,
                'thread_id' => $threadId,
                'last_read_post_id' => $newPostId,
            ]);

            $countStatement = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM forum_posts
                 WHERE thread_id = :thread_id
                   AND is_deleted = 0
                   AND id <> :starter_post_id'
            );
            $countStatement->execute([
                'thread_id' => $threadId,
                'starter_post_id' => $starterPostId,
            ]);
            $replyCountAfter = (int) $countStatement->fetchColumn();
            $targetPage = max(1, (int) ceil($replyCountAfter / $repliesPerPage));

            /*
            |--------------------------------------------------------------------------
            | Unified Forum Notifications
            |--------------------------------------------------------------------------
            |
            | Watched-thread activity, quotes, and @mentions all use the normal
            | notification bell. Each recipient receives at most one notification
            | for this new post, even when multiple reasons apply.
            |
            | Priority for compact wording:
            | 1. quoted + mentioned
            | 2. quoted
            | 3. mentioned
            | 4. watched-thread activity
            |
            */

            $forumNotificationRecipients = [];

            foreach ($validatedQuotePosts as $quotedPost) {
                $quotedUserId =
                    (int) ($quotedPost['user_id'] ?? 0);

                if (
                    $quotedUserId <= 0
                    || $quotedUserId === $userId
                ) {
                    continue;
                }

                if (!isset($forumNotificationRecipients[$quotedUserId])) {
                    $forumNotificationRecipients[$quotedUserId] = [
                        'quoted' => false,
                        'mentioned' => false,
                        'watched' => false,
                    ];
                }

                $forumNotificationRecipients[$quotedUserId]['quoted'] = true;
            }

            $mentionText =
                html_entity_decode(
                    strip_tags($safeReplyContent),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                );

            $mentionedUsernames = [];

            if (
                preg_match_all(
                    '/(?<![A-Za-z0-9_@])@([A-Za-z0-9_]{3,50})\b/',
                    $mentionText,
                    $mentionMatches
                ) > 0
            ) {
                foreach (($mentionMatches[1] ?? []) as $mentionedUsername) {
                    $normalizedMentionUsername =
                        strtolower(
                            trim(
                                (string) $mentionedUsername
                            )
                        );

                    if ($normalizedMentionUsername !== '') {
                        $mentionedUsernames[$normalizedMentionUsername] =
                            $normalizedMentionUsername;
                    }
                }
            }

            if ($mentionedUsernames !== []) {
                $mentionPlaceholders =
                    implode(
                        ',',
                        array_fill(
                            0,
                            count($mentionedUsernames),
                            '?'
                        )
                    );

                $mentionUserStatement =
                    $pdo->prepare(
                        'SELECT id, username
                         FROM users
                         WHERE status = "active"
                           AND LOWER(username) IN ('
                        . $mentionPlaceholders
                        . ')'
                    );

                $mentionUserStatement->execute(
                    array_values($mentionedUsernames)
                );

                foreach (
                    $mentionUserStatement->fetchAll(PDO::FETCH_ASSOC)
                    as $mentionedUser
                ) {
                    $mentionedUserId =
                        (int) ($mentionedUser['id'] ?? 0);

                    if (
                        $mentionedUserId <= 0
                        || $mentionedUserId === $userId
                    ) {
                        continue;
                    }

                    if (!isset($forumNotificationRecipients[$mentionedUserId])) {
                        $forumNotificationRecipients[$mentionedUserId] = [
                            'quoted' => false,
                            'mentioned' => false,
                            'watched' => false,
                        ];
                    }

                    $forumNotificationRecipients[$mentionedUserId]['mentioned'] = true;
                }
            }

            $watcherStatement =
                $pdo->prepare(
                    'SELECT
                        tb.user_id,
                        COALESCE(np.notify_bookmarked_threads, 1)
                            AS notify_watched_threads
                     FROM thread_bookmarks tb
                     INNER JOIN users u
                        ON u.id = tb.user_id
                     LEFT JOIN notification_preferences np
                        ON np.user_id = tb.user_id
                     WHERE tb.thread_id = :thread_id
                       AND tb.notify_on_update = 1
                       AND tb.user_id <> :actor_user_id
                       AND u.status = "active"'
                );

            $watcherStatement->execute([
                'thread_id' => $threadId,
                'actor_user_id' => $userId,
            ]);

            $watchers =
                $watcherStatement->fetchAll(
                    PDO::FETCH_ASSOC
                );

            foreach ($watchers as $watcher) {
                $watcherUserId =
                    (int) ($watcher['user_id'] ?? 0);

                if ($watcherUserId <= 0) {
                    continue;
                }

                if (
                    (int) (
                        $watcher['notify_watched_threads']
                        ?? 1
                    ) !== 1
                ) {
                    continue;
                }

                if (!isset($forumNotificationRecipients[$watcherUserId])) {
                    $forumNotificationRecipients[$watcherUserId] = [
                        'quoted' => false,
                        'mentioned' => false,
                        'watched' => false,
                    ];
                }

                $forumNotificationRecipients[$watcherUserId]['watched'] = true;
            }

            if ($forumNotificationRecipients !== []) {
                $recipientIds =
                    array_map(
                        'intval',
                        array_keys($forumNotificationRecipients)
                    );

                $recipientPlaceholders =
                    implode(
                        ',',
                        array_fill(
                            0,
                            count($recipientIds),
                            '?'
                        )
                    );

                $eligibleRecipientStatement =
                    $pdo->prepare(
                        'SELECT
                            u.id,
                            COALESCE(np.notify_forum_replies, 1)
                                AS notify_forum_replies
                         FROM users u
                         LEFT JOIN notification_preferences np
                            ON np.user_id = u.id
                         WHERE u.status = "active"
                           AND u.id IN ('
                        . $recipientPlaceholders
                        . ')'
                    );

                $eligibleRecipientStatement->execute(
                    $recipientIds
                );

                $forumReplyPreferenceByUser = [];

                foreach (
                    $eligibleRecipientStatement->fetchAll(PDO::FETCH_ASSOC)
                    as $eligibleRecipient
                ) {
                    $eligibleRecipientId =
                        (int) ($eligibleRecipient['id'] ?? 0);

                    if ($eligibleRecipientId <= 0) {
                        continue;
                    }

                    $forumReplyPreferenceByUser[$eligibleRecipientId] =
                        (int) (
                            $eligibleRecipient['notify_forum_replies']
                            ?? 1
                        ) === 1;
                }

                $notificationThreadTitle =
                    trim(
                        (string) (
                            $thread['title']
                            ?? 'Forum thread'
                        )
                    );

                if ($notificationThreadTitle === '') {
                    $notificationThreadTitle = 'Forum thread';
                }

                $forumPostNotificationLink =
                    blackthorne_thread_page_url(
                        $threadId,
                        $targetPage
                    )
                    . '#post-'
                    . $newPostId;

                $forumNotificationInsertStatement =
                    $pdo->prepare(
                        'INSERT INTO notifications (
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
                            "forum_reply",
                            "forum_post",
                            :related_entity_id,
                            :title,
                            :message,
                            :link_url,
                            0
                         )'
                    );

                $existingNotificationStatement =
                    $pdo->prepare(
                        'SELECT id
                         FROM notifications
                         WHERE user_id = :user_id
                           AND notification_type = "forum_reply"
                           AND related_entity_type = "forum_post"
                           AND related_entity_id = :post_id
                         LIMIT 1'
                    );

                $watchBookmarkUpdate =
                    $pdo->prepare(
                        'UPDATE thread_bookmarks
                         SET
                            last_notified_post_id = :post_id,
                            updated_at = CURRENT_TIMESTAMP
                         WHERE user_id = :user_id
                           AND thread_id = :thread_id'
                    );

                foreach (
                    $forumNotificationRecipients
                    as $recipientUserId => $notificationReasons
                ) {
                    $recipientUserId =
                        (int) $recipientUserId;

                    if ($recipientUserId <= 0) {
                        continue;
                    }

                    $wasQuoted =
                        !empty($notificationReasons['quoted']);

                    $wasMentioned =
                        !empty($notificationReasons['mentioned']);

                    $wasWatching =
                        !empty($notificationReasons['watched']);

                    $forumReplyAllowed =
                        $forumReplyPreferenceByUser[$recipientUserId]
                        ?? true;

                    if (
                        !$wasWatching
                        && !$forumReplyAllowed
                    ) {
                        continue;
                    }

                    $showQuoteMentionReason =
                        $forumReplyAllowed
                        && (
                            $wasQuoted
                            || $wasMentioned
                        );

                    $notificationTitle =
                        $notificationThreadTitle;

                    if (
                        $showQuoteMentionReason
                        && $wasQuoted
                        && $wasMentioned
                    ) {
                        $notificationMessage =
                            'You were quoted and mentioned in this thread.';

                    } elseif (
                        $showQuoteMentionReason
                        && $wasQuoted
                    ) {
                        $notificationMessage =
                            'You were quoted in this thread.';

                    } elseif (
                        $showQuoteMentionReason
                        && $wasMentioned
                    ) {
                        $notificationMessage =
                            'You were mentioned in this thread.';

                    } elseif ($wasWatching) {
                        $notificationMessage =
                            'This watched thread has had activity.';

                    } else {
                        continue;
                    }

                    $existingNotificationStatement->execute([
                        'user_id' => $recipientUserId,
                        'post_id' => $newPostId,
                    ]);

                    if (
                        $existingNotificationStatement->fetchColumn()
                        === false
                    ) {
                        $forumNotificationInsertStatement->execute([
                            'user_id' => $recipientUserId,
                            'actor_user_id' => $userId,
                            'related_entity_id' => $newPostId,
                            'title' => $notificationTitle,
                            'message' => $notificationMessage,
                            'link_url' => $forumPostNotificationLink,
                        ]);
                    }

                    if ($wasWatching) {
                        $watchBookmarkUpdate->execute([
                            'post_id' => $newPostId,
                            'user_id' => $recipientUserId,
                            'thread_id' => $threadId,
                        ]);
                    }
                }
            }


            $pdo->commit();

            $redirectUrl = blackthorne_thread_page_url($threadId, $targetPage) . '#post-' . $newPostId;
            header('Location: ' . $redirectUrl);
            exit;

        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if (isset($movedUploadPaths) && is_array($movedUploadPaths)) {
                foreach ($movedUploadPaths as $movedPath) {
                    if (is_string($movedPath) && is_file($movedPath)) {
                        @unlink($movedPath);
                    }
                }
            }

            $replyErrors[] = 'Your reply could not be posted. Please try again.';
        }
    }
}



/*
|--------------------------------------------------------------------------
| Reactions
|--------------------------------------------------------------------------
*/

$likeCountsByPost = [];
$userLikedPosts = [];

if ($postIds !== []) {
    $reactionPlaceholders =
        implode(
            ',',
            array_fill(
                0,
                count($postIds),
                '?'
            )
        );

    $reactionCountStatement =
        $pdo->prepare(
            'SELECT
                post_id,
                COUNT(*) AS like_count
             FROM forum_reactions
             WHERE reaction_type = "like"
               AND post_id IN ('
            . $reactionPlaceholders
            . ')
             GROUP BY post_id'
        );

    $reactionCountStatement->execute(
        $postIds
    );

    foreach (
        $reactionCountStatement->fetchAll(
            PDO::FETCH_ASSOC
        )
        as $reactionCount
    ) {
        $likeCountsByPost[
            (int) $reactionCount['post_id']
        ] =
            (int) $reactionCount['like_count'];
    }

    $userLikeParameters =
        array_merge(
            [$userId],
            $postIds
        );

    $userLikeStatement =
        $pdo->prepare(
            'SELECT post_id
             FROM forum_reactions
             WHERE user_id = ?
               AND reaction_type = "like"
               AND post_id IN ('
            . $reactionPlaceholders
            . ')'
        );

    $userLikeStatement->execute(
        $userLikeParameters
    );

    foreach (
        $userLikeStatement->fetchAll(
            PDO::FETCH_COLUMN
        )
        as $likedPostId
    ) {
        $userLikedPosts[
            (int) $likedPostId
        ] = true;
    }
}


/*
|--------------------------------------------------------------------------
| View Count
|--------------------------------------------------------------------------
|
| Count the page load once. This is intentionally simple for now. We can
| later make view-counting session-aware if desired.
|
*/

$viewCountStatement =
    $pdo->prepare(
        '
        UPDATE forum_threads

        SET view_count =
            view_count + 1

        WHERE id = :thread_id
        '
    );

$viewCountStatement->execute([
    'thread_id' =>
        $threadId,
]);


/*
|--------------------------------------------------------------------------
| Metadata
|--------------------------------------------------------------------------
*/

$pageTitle =
    (string) $thread['title']
    . ' | Blackthorne Academy Forums';

$pageDescription =
    'Blackthorne Academy forum discussion.';

$pageCanonical =
    url(
        'thread.php?t='
        . $threadId
    );

$robots =
    'noindex, nofollow';

require INCLUDES_PATH . '/header.php';

?>

<main id="main-content" class="forum-thread-page">


    <!-- ================================================================
         THREAD HEADER
    ================================================================= -->

    <section class="forum-thread-header">

        <div class="section-inner">


            <nav class="forum-breadcrumbs" aria-label="Forum breadcrumb">

                <?php if (
                    $showThreadModeration
                ): ?>

                <a href="<?= e(
                            url(
                                'forums.php'
                            )
                        ); ?>">
                    Forums
                </a>

                <?php endif; ?>


                <?php if (
                    $category !== null
                ): ?>

                <?php if (
                        $showThreadModeration
                    ): ?>

                <span aria-hidden="true">
                    /
                </span>

                <?php endif; ?>

                <span>
                    <?= e(
                            (string) $category[
                                'title'
                            ]
                        ); ?>
                </span>

                <?php endif; ?>


                <?php foreach (
                    $forumAncestors
                    as $ancestorForum
                ): ?>

                <span aria-hidden="true">
                    /
                </span>

                <a href="<?= e(
                            url(
                                'forum.php?f='
                                . (int) $ancestorForum[
                                    'id'
                                ]
                            )
                        ); ?>">
                    <?= e(
                            (string) $ancestorForum[
                                'title'
                            ]
                        ); ?>
                </a>

                <?php endforeach; ?>


                <span aria-hidden="true">
                    /
                </span>

                <a href="<?= e(
                        url(
                            'forum.php?f='
                            . $forumId
                        )
                    ); ?>">
                    <?= e(
                        (string) $forum[
                            'title'
                        ]
                    ); ?>
                </a>

            </nav>


            <div class="forum-thread-titlebar">

                <div class="forum-thread-title-copy">


                    <?php if (
                        $threadLabels !== []
                    ): ?>

                    <div class="forum-thread-page-labels">

                        <?php foreach (
                                $threadLabels
                                as $label
                            ): ?>

                        <?php

                                $labelColor =
                                    blackthorne_thread_safe_color(
                                        (string) $label[
                                            'label_color'
                                        ]
                                    );

                                ?>

                        <span class="forum-thread-page-label" <?= $labelColor !== null
                                        ? 'style="--thread-label-color: '
                                            . e(
                                                $labelColor
                                            )
                                            . ';"'
                                        : ''; ?>>
                            <?= e(
                                        (string) $label[
                                            'name'
                                        ]
                                    ); ?>
                        </span>

                        <?php endforeach; ?>

                    </div>

                    <?php endif; ?>


                    <h1>
                        <?= e(
                            (string) $thread[
                                'title'
                            ]
                        ); ?>
                    </h1>


                    <div class="forum-thread-flags">

                        <?php if (
                            (int) $thread[
                                'is_announcement'
                            ] === 1
                        ): ?>

                        <span>
                            Announcement
                        </span>

                        <?php endif; ?>


                        <?php if (
                            (int) $thread[
                                'is_pinned'
                            ] === 1
                        ): ?>

                        <span>
                            Sticky
                        </span>

                        <?php endif; ?>


                        <?php if (
                            (int) $thread[
                                'is_locked'
                            ] === 1
                        ): ?>

                        <span>
                            Locked
                        </span>

                        <?php endif; ?>

                    </div>

                </div>


                <?php if (
                    $showThreadModeration
                ): ?>

                <div class="forum-thread-moderator-controls">

                    <button type="button" class="forum-thread-moderate-button" aria-haspopup="dialog"
                        aria-expanded="false" aria-controls="forum-thread-moderation-modal" title="Moderate this thread"
                        data-open-thread-moderation>
                        Moderate
                    </button>

                </div>

                <?php endif; ?>

            </div>

        </div>

    </section>


    <!-- ================================================================
         THREAD CONTENT + MEMBER SIDEBAR
    ================================================================= -->

    <section class="forum-thread-content">

        <div class="section-inner member-home-layout forum-board-layout forum-thread-layout">

            <?php require INCLUDES_PATH . '/member-sidebar.php'; ?>

            <div class="member-home-main forum-board-main forum-thread-main">


                <?php
    $reportStatus = (string) ($_GET['reported'] ?? '');
    $reportError = (string) ($_GET['report_error'] ?? '');
    if ($reportStatus !== '' || $reportError !== ''):
    ?>
                <section class="forum-thread-report-feedback-section" aria-live="polite">
                    <div class="forum-thread-section-inner">
                        <div class="forum-thread-private-notice">
                            <?php if ($reportStatus === '1'): ?>
                            <strong>Report submitted.</strong> A staff member can now review this post.
                            <?php elseif ($reportStatus === 'existing'): ?>
                            <strong>Report already submitted.</strong> You already have an open report for this post.
                            <?php else: ?>
                            <strong>Report not submitted.</strong> Choose a reason and try again.
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
                <?php endif; ?>


                <!-- ================================================================
         THREAD NAVIGATION
    ================================================================= -->

                <section class="forum-thread-nav-section">

                    <div class="forum-thread-section-inner">

                        <div class="forum-thread-nav">


                            <nav class="forum-thread-pagination" aria-label="Thread pages">

                                <?php if (
                        $page > 1
                    ): ?>

                                <a href="<?= e(
                                blackthorne_thread_page_url(
                                    $threadId,
                                    $page - 1
                                )
                            ); ?>">
                                    Previous
                                </a>

                                <?php else: ?>

                                <span class="is-disabled">
                                    Previous
                                </span>

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

                                <span class="is-current" aria-current="page">
                                    <?= $paginationPage; ?>
                                </span>

                                <?php else: ?>

                                <a href="<?= e(
                                    blackthorne_thread_page_url(
                                        $threadId,
                                        $paginationPage
                                    )
                                ); ?>">
                                    <?= $paginationPage; ?>
                                </a>

                                <?php endif; ?>

                                <?php endfor; ?>


                                <?php if (
                        $page < $totalPages
                    ): ?>

                                <a href="<?= e(
                                blackthorne_thread_page_url(
                                    $threadId,
                                    $page + 1
                                )
                            ); ?>">
                                    Next
                                </a>

                                <?php else: ?>

                                <span class="is-disabled">
                                    Next
                                </span>

                                <?php endif; ?>

                            </nav>


                            <div class="forum-thread-nav-actions">

                                <a class="button button-secondary" href="<?= e(
                            url(
                                'forum.php?f='
                                . $forumId
                            )
                        ); ?>">
                                    Back to Board
                                </a>


                                <form action="<?= e(
                            blackthorne_thread_page_url(
                                $threadId,
                                $page
                            )
                        ); ?>" method="post">

                                    <?= csrf_field(); ?>

                                    <input type="hidden" name="action" value="<?= $isWatchingThread
                                ? 'unwatch_thread'
                                : 'watch_thread'; ?>">

                                    <button class="button button-secondary" type="submit">
                                        <?= $isWatchingThread
                                ? 'Unwatch Thread'
                                : 'Watch Thread'; ?>
                                    </button>

                                </form>


                                <?php if (
                        $canReply
                    ): ?>

                                <a class="button button-primary" href="#respond">
                                    Reply
                                </a>

                                <?php endif; ?>

                            </div>

                        </div>

                    </div>

                </section>


                <!-- ================================================================
         POSTS
    ================================================================= -->

                <section class="forum-thread-posts">

                    <div class="forum-thread-section-inner">


                        <?php if (
                $posts === []
            ): ?>

                        <div class="forum-thread-empty">
                            This thread does not currently contain any visible posts.
                        </div>


                        <?php else: ?>

                        <?php foreach (
                    $posts
                    as $postIndex => $post
                ): ?>

                        <?php

                    $postId =
                        (int) $post[
                            'id'
                        ];

                    $posterName =
                        trim(
                            (string) (
                                $post[
                                    'display_name'
                                ]
                                ?? $post[
                                    'username'
                                ]
                                ?? 'Member'
                            )
                        );

                    $posterRole =
                        trim(
                            (string) (
                                $post[
                                    'role_name'
                                ]
                                ?? 'Member'
                            )
                        );

                    $posterRoleColor =
                        blackthorne_thread_safe_color(
                            isset(
                                $post[
                                    'role_color'
                                ]
                            )
                                ? (string) $post[
                                    'role_color'
                                ]
                                : null
                        );

                    $posterHouse =
                        trim(
                            (string) (
                                $post[
                                    'house_name'
                                ]
                                ?? ''
                            )
                        );

                    $posterHouseColor =
                        blackthorne_thread_safe_color(
                            isset(
                                $post[
                                    'house_color'
                                ]
                            )
                                ? (string) $post[
                                    'house_color'
                                ]
                                : null
                        );

                    $avatarUrl =
                        blackthorne_thread_avatar_url(
                            isset(
                                $post[
                                    'avatar'
                                ]
                            )
                                ? (string) $post[
                                    'avatar'
                                ]
                                : null
                        );

                    $postQuotes =
                        $quotesByPost[
                            $postId
                        ]
                        ?? [];

                    $canEditPost =
                        !$isForumMuted
                        && forum_can_edit_post(
                            $pdo,
                            $postId,
                            $userId
                        );

                    $canDeletePost =
                        forum_can_delete_post(
                            $pdo,
                            $postId,
                            $userId
                        );

                    $canQuotePost =
                        forum_can_quote_post(
                            $pdo,
                            $postId,
                            $userId
                        );

                    $likeCount =
                        $likeCountsByPost[
                            $postId
                        ]
                        ?? 0;

                    $userLikedPost =
                        isset(
                            $userLikedPosts[
                                $postId
                            ]
                        );

                    $isStarter =
                        $postId
                        === $starterPostId;

                    ?>


                        <article class="forum-post<?= $isStarter ? ' is-starter-post' : ''; ?>"
                            id="post-<?= $postId; ?>">


                            <aside class="forum-post-profile">


                                <a class="forum-post-avatar" href="<?= e(
                                    url(
                                        'profile.php?u='
                                        . (int) $post[
                                            'user_id'
                                        ]
                                    )
                                ); ?>" aria-label="View <?= e(
                                    $posterName
                                ); ?>'s profile">

                                    <?php if (
                                    $avatarUrl !== null
                                ): ?>

                                    <img src="<?= e(
                                            $avatarUrl
                                        ); ?>" alt="" loading="lazy">

                                    <?php else: ?>

                                    <span aria-hidden="true">
                                        <?= e(
                                            blackthorne_thread_initials(
                                                $posterName
                                            )
                                        ); ?>
                                    </span>

                                    <?php endif; ?>

                                </a>


                                <a class="forum-post-member-name" href="<?= e(
                                    url(
                                        'profile.php?u='
                                        . (int) $post[
                                            'user_id'
                                        ]
                                    )
                                ); ?>" <?= $posterHouseColor !== null
                                    ? 'style="color: '
                                        . e(
                                            $posterHouseColor
                                        )
                                        . ';"'
                                    : ''; ?>>
                                    <?= e(
                                    $posterName
                                ); ?>
                                </a>


                                <p class="forum-post-member-role" <?= $posterRoleColor !== null
                                    ? 'style="color: '
                                        . e(
                                            $posterRoleColor
                                        )
                                        . ';"'
                                    : ''; ?>>
                                    <?= e(
                                    $posterRole
                                ); ?>
                                </p>


                                <?php if (
                                $posterHouse !== ''
                            ): ?>

                                <p class="forum-post-member-house" <?= $posterHouseColor !== null
                                        ? 'style="color: '
                                            . e(
                                                $posterHouseColor
                                            )
                                            . ';"'
                                        : ''; ?>>
                                    <?= e(
                                        $posterHouse
                                    ); ?>
                                </p>

                                <?php endif; ?>


                                <dl class="forum-post-member-stats">

                                    <div>
                                        <dt>
                                            Posts
                                        </dt>
                                        <dd>
                                            <?= number_format(
                                            (int) $post[
                                                'forum_post_count'
                                            ]
                                        ); ?>
                                        </dd>
                                    </div>

                                    <div>
                                        <dt>
                                            Likes
                                        </dt>
                                        <dd>
                                            <?= number_format(
                                            (int) ($post['forum_like_count'] ?? 0)
                                        ); ?>
                                        </dd>
                                    </div>

                                    <div>
                                        <dt>
                                            House Points
                                        </dt>
                                        <dd>
                                            —
                                        </dd>
                                    </div>

                                    <div>
                                        <dt>
                                            HW Points
                                        </dt>
                                        <dd>
                                            —
                                        </dd>
                                    </div>

                                </dl>

                            </aside>


                            <div class="forum-post-main">


                                <div class="forum-post-content">


                                    <?php if (
                                    $postQuotes !== []
                                ): ?>

                                    <div class="forum-post-quotes">

                                        <?php foreach (
                                            $postQuotes
                                            as $quote
                                        ): ?>

                                        <blockquote class="forum-post-quote">

                                            <header>
                                                <?= e(
                                                        (string) $quote[
                                                            'quoted_display_name_snapshot'
                                                        ]
                                                    ); ?> wrote:
                                            </header>

                                            <div>
                                                <?= nl2br(
                                                        e(
                                                            (string) $quote[
                                                                'quoted_content_snapshot'
                                                            ]
                                                        )
                                                    ); ?>
                                            </div>

                                        </blockquote>

                                        <?php endforeach; ?>

                                    </div>

                                    <?php endif; ?>


                                    <?php if (
                                    $editPostId === $postId
                                    && $canEditPost
                                ): ?>

                                    <form class="forum-inline-edit-form" method="post" action="<?= e(
                                            blackthorne_thread_page_url(
                                                $threadId,
                                                $page
                                            )
                                            . '#post-'
                                            . $postId
                                        ); ?>" data-inline-edit-form>
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="edit_post">
                                        <input type="hidden" name="post_id" value="<?= $postId; ?>">

                                        <div class="forum-rich-editor" data-inline-editor>
                                            <div class="forum-rich-editor-toolbar" role="toolbar"
                                                aria-label="Edit post formatting">
                                                <div class="forum-editor-tool-group">
                                                    <button type="button" class="forum-editor-tool"
                                                        data-edit-command="bold"
                                                        title="Bold"><strong>B</strong></button>
                                                    <button type="button" class="forum-editor-tool"
                                                        data-edit-command="italic" title="Italic"><em>I</em></button>
                                                    <button type="button" class="forum-editor-tool"
                                                        data-edit-command="underline"
                                                        title="Underline"><u>U</u></button>
                                                    <button type="button" class="forum-editor-tool"
                                                        data-edit-command="strikeThrough"
                                                        title="Strikethrough"><s>S</s></button>
                                                </div>

                                                <div class="forum-editor-tool-group">
                                                    <select class="forum-editor-select" data-edit-format
                                                        title="Text style" aria-label="Text style">
                                                        <option value="p">Paragraph</option>
                                                        <option value="h2">Heading 2</option>
                                                        <option value="h3">Heading 3</option>
                                                        <option value="h4">Heading 4</option>
                                                        <option value="blockquote">Quote Block</option>
                                                    </select>

                                                    <select class="forum-editor-select" data-edit-size title="Font size"
                                                        aria-label="Font size">
                                                        <option value="2">Small</option>
                                                        <option value="3" selected>Normal</option>
                                                        <option value="4">Large</option>
                                                        <option value="5">Extra Large</option>
                                                    </select>
                                                </div>

                                                <div class="forum-editor-tool-group">
                                                    <label class="forum-editor-color-control" title="Text color">
                                                        <span>A</span>
                                                        <input type="color" value="#e8e0e6" data-edit-color
                                                            aria-label="Text color">
                                                    </label>
                                                    <button type="button" class="forum-editor-tool"
                                                        data-edit-apply-color
                                                        title="Apply the current text color to the selected text">Apply
                                                        Text</button>
                                                    <label class="forum-editor-color-control" title="Highlight color">
                                                        <span>▰</span>
                                                        <input type="color" value="#34263a" data-edit-highlight
                                                            aria-label="Highlight color">
                                                    </label>
                                                    <button type="button" class="forum-editor-tool"
                                                        data-edit-apply-highlight
                                                        title="Apply the current highlight color to the selected text">Apply
                                                        Highlight</button>
                                                </div>

                                                <div class="forum-editor-tool-group">
                                                    <button type="button" class="forum-editor-tool"
                                                        data-edit-command="insertUnorderedList" title="Bulleted list">•
                                                        List</button>
                                                    <button type="button" class="forum-editor-tool"
                                                        data-edit-command="insertOrderedList" title="Numbered list">1.
                                                        List</button>
                                                    <button type="button" class="forum-editor-tool" data-edit-quote
                                                        title="Format selected text as a quote">Quote</button>
                                                    <button type="button" class="forum-editor-tool"
                                                        data-edit-command="justifyLeft" title="Align left">Left</button>
                                                    <button type="button" class="forum-editor-tool"
                                                        data-edit-command="justifyCenter"
                                                        title="Align center">Center</button>
                                                    <button type="button" class="forum-editor-tool"
                                                        data-edit-command="justifyRight"
                                                        title="Align right">Right</button>
                                                </div>

                                                <div class="forum-editor-tool-group">
                                                    <button type="button" class="forum-editor-tool"
                                                        data-edit-link>Link</button>
                                                    <button type="button" class="forum-editor-tool"
                                                        data-edit-command="unlink">Unlink</button>
                                                    <select class="forum-editor-select" data-edit-image-size
                                                        title="Image size">
                                                        <option value="">Image Size</option>
                                                        <option value="25%">25%</option>
                                                        <option value="40%">40%</option>
                                                        <option value="50%">50%</option>
                                                        <option value="60%">60%</option>
                                                        <option value="75%">75%</option>
                                                        <option value="90%">90%</option>
                                                        <option value="100%">100%</option>
                                                    </select>

                                                    <select class="forum-editor-select" data-edit-image-align
                                                        title="Image alignment">
                                                        <option value="">Image Align</option>
                                                        <option value="left">Left</option>
                                                        <option value="center">Center</option>
                                                        <option value="right">Right</option>
                                                    </select>

                                                    <button type="button" class="forum-editor-tool"
                                                        data-edit-image-url>Image URL</button>
                                                    <button type="button"
                                                        class="forum-editor-tool forum-editor-youtube-tool"
                                                        data-edit-youtube title="Embed a YouTube video">YouTube</button>
                                                    <button type="button" class="forum-editor-tool"
                                                        data-edit-command="removeFormat">Clear</button>
                                                </div>
                                            </div>

                                            <div class="forum-rich-editor-surface forum-inline-edit-surface"
                                                contenteditable="true" data-inline-editor-area><?= sanitize_rich_text(
                                                (string) $post['content']
                                            ); ?></div>

                                            <div class="forum-editor-counts" aria-live="polite" aria-atomic="true">
                                                <span data-edit-word-count>0 words</span>
                                                <span aria-hidden="true">•</span>
                                                <span data-edit-character-count>0 characters</span>
                                            </div>
                                        </div>

                                        <textarea name="content" class="forum-rich-editor-input" aria-hidden="true"
                                            tabindex="-1" data-inline-editor-input><?= e(
                                            (string) $post['content']
                                        ); ?></textarea>

                                        <div class="forum-inline-edit-actions">
                                            <button type="submit" class="button button-primary">Save Changes</button>
                                            <a class="button button-secondary" href="<?= e(
                                                    blackthorne_thread_page_url(
                                                        $threadId,
                                                        $page
                                                    )
                                                    . '#post-'
                                                    . $postId
                                                ); ?>">
                                                Cancel
                                            </a>
                                        </div>
                                    </form>

                                    <?php else: ?>

                                    <div class="rich-text-content forum-post-body">
                                        <?= blackthorne_render_forum_content(
                                            (string) $post[
                                                'content'
                                            ]
                                        ); ?>
                                    </div>

                                    <?php endif; ?>


                                    <?php if (
                                    (int) $post[
                                        'is_edited'
                                    ] === 1
                                ): ?>

                                    <p class="forum-post-edited">
                                        Edited
                                        <?= e(
                                            blackthorne_thread_datetime(
                                                (string) $post[
                                                    'updated_at'
                                                ]
                                            )
                                        ); ?>
                                    </p>

                                    <?php endif; ?>

                                </div>


                                <footer class="forum-post-footer">

                                    <div class="forum-post-date">

                                        <a href="<?= e(
                                            blackthorne_thread_page_url(
                                                $threadId,
                                                $page
                                            )
                                            . '#post-'
                                            . $postId
                                        ); ?>">
                                            #<?= $postId; ?>
                                        </a>

                                        <span aria-hidden="true">
                                            ·
                                        </span>

                                        <time datetime="<?= e(
                                            (string) $post[
                                                'created_at'
                                            ]
                                        ); ?>">
                                            <?= e(
                                            blackthorne_thread_datetime(
                                                (string) $post[
                                                    'created_at'
                                                ]
                                            )
                                        ); ?>
                                        </time>

                                    </div>


                                    <div class="forum-post-actions">

                                        <?php if ($userLikedPost): ?>

                                        <span class="forum-post-action forum-post-like-action is-active"
                                            aria-label="You liked this post">
                                            <span aria-hidden="true">♥</span>
                                            <span>Liked</span>
                                            <?php if ($likeCount > 0): ?>
                                            <span class="forum-post-like-count"><?= number_format($likeCount); ?></span>
                                            <?php endif; ?>
                                        </span>

                                        <form method="post" action="<?= e(
                                                blackthorne_thread_page_url(
                                                    $threadId,
                                                    $page
                                                )
                                                . '#post-'
                                                . $postId
                                            ); ?>" class="forum-post-action-form">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="action" value="toggle_like">
                                            <input type="hidden" name="post_id" value="<?= $postId; ?>">

                                            <button type="submit" class="forum-post-action forum-post-unlike-action"
                                                title="Remove your like" aria-label="Unlike this post">
                                                Unlike
                                            </button>
                                        </form>

                                        <?php else: ?>

                                        <form method="post" action="<?= e(
                                                blackthorne_thread_page_url(
                                                    $threadId,
                                                    $page
                                                )
                                                . '#post-'
                                                . $postId
                                            ); ?>" class="forum-post-action-form">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="action" value="toggle_like">
                                            <input type="hidden" name="post_id" value="<?= $postId; ?>">

                                            <button type="submit" class="forum-post-action forum-post-like-action"
                                                title="Like this post" aria-label="Like this post">
                                                <span aria-hidden="true">♥</span>
                                                <span>Like</span>
                                                <?php if ($likeCount > 0): ?>
                                                <span
                                                    class="forum-post-like-count"><?= number_format($likeCount); ?></span>
                                                <?php endif; ?>
                                            </button>
                                        </form>

                                        <?php endif; ?>


                                        <?php if (
                                        $canQuotePost
                                        && $canReply
                                    ): ?>

                                        <button type="button" class="forum-post-action forum-post-quote-action"
                                            data-quote-post="<?= $postId; ?>" title="Quote this post in your reply">
                                            Quote
                                        </button>

                                        <?php endif; ?>


                                        <?php if (
                                        $canEditPost
                                    ): ?>

                                        <a class="forum-post-action forum-post-edit-action" href="<?= e(
                                                blackthorne_thread_page_url(
                                                    $threadId,
                                                    $page
                                                )
                                                . '&edit='
                                                . $postId
                                                . '#post-'
                                                . $postId
                                            ); ?>">
                                            Edit
                                        </a>

                                        <?php endif; ?>


                                        <?php if (
                                        $canDeletePost
                                        || (
                                            $isStarter
                                            && $canDeleteThread
                                        )
                                    ): ?>

                                        <form method="post" class="forum-post-inline-action-form"
                                            onsubmit="return confirm('<?= $isStarter ? 'Delete this entire thread?' : 'Delete this post?'; ?>');">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete_post">
                                            <input type="hidden" name="post_id" value="<?= $postId; ?>">

                                            <button type="submit" class="forum-post-action is-danger">
                                                <?= $isStarter ? 'Delete Thread' : 'Delete'; ?>
                                            </button>
                                        </form>

                                        <?php endif; ?>


                                        <?php if (
                                        forum_can_move_post(
                                            $pdo,
                                            $forumId,
                                            $userId
                                        )
                                    ): ?>

                                        <button type="button" class="forum-post-action" disabled>
                                            Move
                                        </button>

                                        <?php endif; ?>


                                        <button type="button" class="forum-post-action forum-post-report-action"
                                            data-report-post="<?= $postId; ?>"
                                            data-report-author="<?= e((string) $post['display_name']); ?>"
                                            title="Report this post" aria-label="Report this post">
                                            <span class="forum-post-report-cog" aria-hidden="true">
                                                ⚙
                                            </span>
                                            <span class="sr-only">Report</span>
                                        </button>

                                    </div>

                                </footer>

                            </div>

                        </article>


                        <?php endforeach; ?>

                        <?php endif; ?>


                        <!-- ========================================================
                 BOTTOM NAV
            ========================================================= -->

                        <?php if (
                $totalPages > 1
            ): ?>

                        <nav class="forum-thread-pagination forum-thread-pagination-bottom" aria-label="Thread pages">

                            <?php if (
                        $page > 1
                    ): ?>

                            <a href="<?= e(
                                blackthorne_thread_page_url(
                                    $threadId,
                                    $page - 1
                                )
                            ); ?>">
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

                            <span class="is-current" aria-current="page">
                                <?= $paginationPage; ?>
                            </span>

                            <?php else: ?>

                            <a href="<?= e(
                                    blackthorne_thread_page_url(
                                        $threadId,
                                        $paginationPage
                                    )
                                ); ?>">
                                <?= $paginationPage; ?>
                            </a>

                            <?php endif; ?>

                            <?php endfor; ?>


                            <?php if (
                        $page < $totalPages
                    ): ?>

                            <a href="<?= e(
                                blackthorne_thread_page_url(
                                    $threadId,
                                    $page + 1
                                )
                            ); ?>">
                                Next
                            </a>

                            <?php endif; ?>

                        </nav>

                        <?php endif; ?>


                        <!-- ========================================================
                 REPLY AREA
            ========================================================= -->

                        <section class="forum-thread-reply" id="respond">

                            <?php if ($isForumMuted): ?>

                            <div class="forum-thread-locked-notice">
                                Your account is currently muted from posting forum content.
                                <?php if (!empty($activeForumMute['expires_at'])): ?>
                                This mute expires
                                <?= e(
                                blackthorne_thread_datetime(
                                    (string) $activeForumMute['expires_at']
                                )
                            ); ?>.
                                <?php else: ?>
                                This mute does not currently have an automatic expiration.
                                <?php endif; ?>
                            </div>


                            <?php elseif (
                    (int) $thread[
                        'is_locked'
                    ] === 1
                ): ?>

                            <div class="forum-thread-locked-notice">
                                This thread is locked. New replies cannot be posted.
                            </div>


                            <?php elseif (
                    $canReply
                ): ?>

                            <header class="forum-board-section-titlebar">

                                <h2>
                                    Reply to Thread
                                </h2>

                            </header>

                            <?php if ($replyErrors !== []): ?>
                            <div class="form-alert form-alert-error" role="alert">
                                <strong>Your reply was not posted.</strong>
                                <ul>
                                    <?php foreach ($replyErrors as $replyError): ?>
                                    <li><?= e($replyError); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <?php endif; ?>

                            <form class="forum-thread-composer forum-reply-composer" method="post"
                                enctype="multipart/form-data"
                                action="<?= e(blackthorne_thread_page_url($threadId, $page) . '#respond'); ?>"
                                id="thread-reply-form">
                                <?= csrf_field(); ?>
                                <input type="hidden" name="action" value="reply">
                                <input type="hidden" name="quote_post_ids" value="[]" data-quote-post-ids>

                                <div class="form-group forum-rich-editor-field">
                                    <label id="reply-message-label" for="reply-editor">Message</label>

                                    <div class="forum-rich-editor" data-forum-editor>
                                        <div class="forum-rich-editor-toolbar" role="toolbar"
                                            aria-label="Reply formatting">
                                            <div class="forum-editor-tool-group">
                                                <button type="button" class="forum-editor-tool" data-command="bold"
                                                    title="Bold" aria-label="Bold"><strong>B</strong></button>
                                                <button type="button" class="forum-editor-tool" data-command="italic"
                                                    title="Italic" aria-label="Italic"><em>I</em></button>
                                                <button type="button" class="forum-editor-tool" data-command="underline"
                                                    title="Underline" aria-label="Underline"><u>U</u></button>
                                                <button type="button" class="forum-editor-tool"
                                                    data-command="strikeThrough" title="Strikethrough"
                                                    aria-label="Strikethrough"><s>S</s></button>
                                            </div>

                                            <div class="forum-editor-tool-group">
                                                <label class="sr-only" for="reply-format">Text style</label>
                                                <select id="reply-format" class="forum-editor-select" data-editor-format
                                                    title="Text style">
                                                    <option value="p">Paragraph</option>
                                                    <option value="h2">Heading 2</option>
                                                    <option value="h3">Heading 3</option>
                                                    <option value="h4">Heading 4</option>
                                                    <option value="blockquote">Quote Block</option>
                                                </select>
                                                <label class="sr-only" for="reply-font-size">Font size</label>
                                                <select id="reply-font-size" class="forum-editor-select"
                                                    data-editor-size title="Font size">
                                                    <option value="3">Normal</option>
                                                    <option value="2">Small</option>
                                                    <option value="4">Large</option>
                                                    <option value="5">Larger</option>
                                                    <option value="6">Very Large</option>
                                                </select>
                                            </div>

                                            <div class="forum-editor-tool-group forum-editor-color-tools">
                                                <label class="forum-editor-color-label" title="Text color">
                                                    <span>A</span>
                                                    <input type="color" value="#e8e1e6" data-editor-color
                                                        aria-label="Text color">
                                                </label>
                                                <button type="button" class="forum-editor-tool" data-editor-apply-color
                                                    title="Apply the current text color to the selected text">Apply
                                                    Text</button>
                                                <label class="forum-editor-color-label" title="Highlight color">
                                                    <span>▰</span>
                                                    <input type="color" value="#3f2b48" data-editor-highlight
                                                        aria-label="Highlight color">
                                                </label>
                                                <button type="button" class="forum-editor-tool"
                                                    data-editor-apply-highlight
                                                    title="Apply the current highlight color to the selected text">Apply
                                                    Highlight</button>
                                            </div>

                                            <div class="forum-editor-tool-group">
                                                <button type="button" class="forum-editor-tool"
                                                    data-command="insertUnorderedList" title="Bulleted list"
                                                    aria-label="Bulleted list">• List</button>
                                                <button type="button" class="forum-editor-tool"
                                                    data-command="insertOrderedList" title="Numbered list"
                                                    aria-label="Numbered list">1. List</button>
                                                <button type="button" class="forum-editor-tool" data-editor-quote
                                                    title="Format selected text as a quote"
                                                    aria-label="Quote selected text">
                                                    Quote
                                                </button>
                                            </div>

                                            <div class="forum-editor-tool-group">
                                                <button type="button" class="forum-editor-tool"
                                                    data-command="justifyLeft" title="Align left"
                                                    aria-label="Align left">Left</button>
                                                <button type="button" class="forum-editor-tool"
                                                    data-command="justifyCenter" title="Align center"
                                                    aria-label="Align center">Center</button>
                                                <button type="button" class="forum-editor-tool"
                                                    data-command="justifyRight" title="Align right"
                                                    aria-label="Align right">Right</button>
                                            </div>

                                            <div class="forum-editor-tool-group">
                                                <button type="button" class="forum-editor-tool" data-editor-link
                                                    title="Insert link">Link</button>
                                                <button type="button" class="forum-editor-tool" data-command="unlink"
                                                    title="Remove link">Unlink</button>
                                                <button type="button"
                                                    class="forum-editor-tool forum-editor-youtube-tool"
                                                    data-editor-youtube title="Embed a YouTube video">YouTube</button>
                                                <button type="button" class="forum-editor-tool"
                                                    data-command="removeFormat" title="Clear formatting">Clear</button>
                                            </div>

                                            <div class="forum-editor-tool-group forum-editor-image-tools">
                                                <?php if ($forumAllowsImages): ?>
                                                <select class="forum-editor-select" data-editor-image-size
                                                    title="Image size">
                                                    <option value="">Image Size</option>
                                                    <option value="25%">25%</option>
                                                    <option value="40%">40%</option>
                                                    <option value="50%">50%</option>
                                                    <option value="60%">60%</option>
                                                    <option value="75%">75%</option>
                                                    <option value="90%">90%</option>
                                                    <option value="100%">100%</option>
                                                </select>

                                                <select class="forum-editor-select" data-editor-image-align
                                                    title="Image alignment">
                                                    <option value="">Image Align</option>
                                                    <option value="left">Left</option>
                                                    <option value="center">Center</option>
                                                    <option value="right">Right</option>
                                                </select>

                                                <button type="button" class="forum-editor-tool" data-editor-image-upload
                                                    title="Upload an image from your device">Upload Image</button>
                                                <input class="forum-editor-image-upload-input" type="file"
                                                    id="reply-image-upload" name="uploaded_images[]"
                                                    accept="image/jpeg,image/png,image/gif,image/webp,image/avif"
                                                    multiple data-editor-image-input>
                                                <input type="hidden" name="upload_tokens" id="reply-upload-tokens"
                                                    value="[]" data-editor-upload-tokens>
                                                <?php endif; ?>
                                                <button type="button" class="forum-editor-tool" data-editor-image-url
                                                    title="Insert an image from an HTTPS URL">Image URL</button>
                                            </div>
                                        </div>

                                        <div class="forum-rich-editor-surface" id="reply-editor" contenteditable="true"
                                            role="textbox" aria-labelledby="reply-message-label" aria-multiline="true"
                                            data-placeholder="Write your reply..." spellcheck="true">
                                            <?= $replyContent !== '' ? sanitize_rich_text($replyContent) : ''; ?></div>

                                        <?php if ($forumAllowsImages): ?>
                                        <div class="forum-editor-image-preview-list" data-editor-image-previews hidden
                                            aria-live="polite"></div>
                                        <?php endif; ?>

                                        <div class="forum-editor-counts" aria-live="polite" aria-atomic="true">
                                            <span data-editor-word-count>0 words</span>
                                            <span aria-hidden="true">•</span>
                                            <span data-editor-character-count>0 characters</span>
                                        </div>

                                        <textarea class="forum-rich-editor-input" name="content" id="reply-content"
                                            required aria-hidden="true"
                                            tabindex="-1"><?= e($replyContent); ?></textarea>
                                    </div>

                                    <p class="form-help">
                                        Formatting is preserved when the reply is posted. You may embed a YouTube video,
                                        insert an HTTPS image URL<?php if ($forumAllowsImages): ?> or upload up to
                                        <?= $forumMaxAttachmentsPerPost; ?> images (<?= $forumMaxImageSizeMb; ?> MB
                                        each)<?php endif; ?>.
                                    </p>
                                </div>

                                <div class="forum-thread-composer-actions">
                                    <button class="button button-primary" type="submit">Post Reply</button>
                                </div>
                            </form>


                            <?php else: ?>

                            <div class="forum-thread-locked-notice">
                                Your account does not have permission to reply to this thread.
                            </div>

                            <?php endif; ?>

                        </section>

                    </div>

                </section>


            </div>

        </div>

    </section>

</main>

<?php if ($showThreadModeration): ?>

<dialog class="forum-admin-modal forum-thread-moderation-modal" id="forum-thread-moderation-modal"
    aria-labelledby="forum-thread-moderation-heading">
    <header class="forum-admin-titlebar forum-admin-modal-titlebar">
        <div>
            <p class="forum-admin-step">Thread Moderation</p>
            <h2 id="forum-thread-moderation-heading">Moderate Thread</h2>
        </div>

        <button type="button" class="forum-admin-modal-close" data-close-thread-moderation
            aria-label="Close moderation controls">
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
                <path d="M6.75 6.75 17.25 17.25M17.25 6.75 6.75 17.25" fill="none" stroke="currentColor"
                    stroke-width="1.8" stroke-linecap="round" />
            </svg>
        </button>
    </header>

    <div class="forum-admin-form forum-thread-moderation-form">

        <p class="forum-report-intro">
            Manage <strong><?= e((string) $thread['title']); ?></strong>.
            Only actions your account can perform are shown.
        </p>

        <div class="forum-thread-moderation-grid">

            <?php if (
                $canLockThread
                || $canPinThread
                || $canMarkAnnouncement
            ): ?>

            <section class="forum-thread-moderation-section">
                <h3>Thread Status</h3>

                <div class="forum-thread-moderation-actions">

                    <?php if ($canLockThread): ?>
                    <form method="post" action="<?= e(blackthorne_thread_page_url($threadId, $page)); ?>">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="moderate_lock_thread">

                        <button type="submit" class="button">
                            <?= (int) $thread['is_locked'] === 1
                                        ? 'Unlock Thread'
                                        : 'Lock Thread'; ?>
                        </button>
                    </form>
                    <?php endif; ?>


                    <?php if ($canPinThread): ?>
                    <form method="post" action="<?= e(blackthorne_thread_page_url($threadId, $page)); ?>">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="moderate_pin_thread">

                        <button type="submit" class="button">
                            <?= (int) $thread['is_pinned'] === 1
                                        ? 'Remove Sticky'
                                        : 'Make Sticky'; ?>
                        </button>
                    </form>
                    <?php endif; ?>


                    <?php if ($canMarkAnnouncement): ?>
                    <form method="post" action="<?= e(blackthorne_thread_page_url($threadId, $page)); ?>">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="moderate_announcement">

                        <button type="submit" class="button">
                            <?= (int) $thread['is_announcement'] === 1
                                        ? 'Remove Announcement'
                                        : 'Make Announcement'; ?>
                        </button>
                    </form>
                    <?php endif; ?>

                </div>
            </section>

            <?php endif; ?>


            <?php if ($canApplyLabels): ?>

            <section class="forum-thread-moderation-section">
                <h3>Labels</h3>

                <?php if ($availableThreadLabels !== []): ?>

                <form method="post" action="<?= e(blackthorne_thread_page_url($threadId, $page)); ?>"
                    class="forum-thread-moderation-label-form">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="moderate_labels">

                    <div class="forum-thread-label-choice-list">

                        <?php foreach ($availableThreadLabels as $label): ?>
                        <?php
                                    $labelId = (int) $label['id'];
                                    ?>

                        <label class="forum-thread-label-choice">
                            <input type="checkbox" name="label_ids[]" value="<?= $labelId; ?>" <?= in_array(
                                                $labelId,
                                                $currentThreadLabelIds,
                                                true
                                            )
                                                ? 'checked'
                                                : ''; ?>>

                            <span class="forum-thread-label-dot"
                                style="--thread-label-color: <?= e((string) $label['label_color']); ?>;"
                                aria-hidden="true"></span>

                            <span>
                                <?= e((string) $label['name']); ?>
                            </span>
                        </label>
                        <?php endforeach; ?>

                    </div>

                    <button type="submit" class="button button-primary">
                        Save Labels
                    </button>
                </form>

                <?php else: ?>

                <p class="muted">
                    This board does not have any active labels.
                </p>

                <?php endif; ?>

            </section>

            <?php endif; ?>


            <?php if ($canMoveThread): ?>

            <section class="forum-thread-moderation-section">
                <h3>Move Thread</h3>

                <?php if ($moveDestinationForums !== []): ?>

                <form method="post" action="<?= e(blackthorne_thread_page_url($threadId, $page)); ?>"
                    class="forum-thread-moderation-move-form" data-confirm-move-thread>
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="moderate_move_thread">

                    <label for="moderation-destination-forum">
                        Destination board
                    </label>

                    <select class="form-control" id="moderation-destination-forum" name="destination_forum_id"
                        data-forum-picker required>
                        <option value="">
                            Choose a board
                        </option>

                        <?php foreach ($moveDestinationForums as $destinationForum): ?>
                        <option value="<?= (int) $destinationForum['id']; ?>"
                            data-forum-id="<?= (int) $destinationForum['id']; ?>"
                            data-parent-forum-id="<?= (int) ($destinationForum['parent_forum_id'] ?? 0); ?>"
                            data-category-title="<?= e((string) $destinationForum['category_title']); ?>"
                            data-forum-title="<?= e((string) $destinationForum['title']); ?>">
                            <?= e((string) $destinationForum['title']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit" class="button">
                        Move Thread
                    </button>
                </form>

                <?php else: ?>

                <p class="muted">
                    There are no other boards available to move this thread to.
                </p>

                <?php endif; ?>

            </section>

            <?php endif; ?>


            <?php if ($canDeleteThread): ?>

            <section class="forum-thread-moderation-section forum-thread-moderation-danger">
                <h3>Danger Zone</h3>

                <p>
                    Delete this thread from public view. The database record is retained
                    for moderation history.
                </p>

                <form method="post" action="<?= e(blackthorne_thread_page_url($threadId, $page)); ?>"
                    data-confirm-delete-thread>
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="delete_post">
                    <input type="hidden" name="post_id" value="<?= $starterPostId; ?>">

                    <button type="submit" class="button forum-thread-danger-button">
                        Delete Thread
                    </button>
                </form>
            </section>

            <?php endif; ?>

        </div>

    </div>
</dialog>

<script>
    (() => {
        'use strict';

        const modal =
            document.getElementById(
                'forum-thread-moderation-modal'
            );

        const openButton =
            document.querySelector(
                '[data-open-thread-moderation]'
            );

        if (!modal || !openButton) {
            return;
        }

        const closeModal = () => {
            modal.close();
            openButton.setAttribute(
                'aria-expanded',
                'false'
            );
        };

        openButton.addEventListener(
            'click',
            () => {
                if (
                    typeof modal.showModal ===
                    'function'
                ) {
                    modal.showModal();
                    openButton.setAttribute(
                        'aria-expanded',
                        'true'
                    );
                }
            }
        );

        modal
            .querySelectorAll(
                '[data-close-thread-moderation]'
            )
            .forEach(
                (button) => {
                    button.addEventListener(
                        'click',
                        closeModal
                    );
                }
            );

        modal.addEventListener(
            'close',
            () => {
                openButton.setAttribute(
                    'aria-expanded',
                    'false'
                );
            }
        );

        modal.addEventListener(
            'click',
            (event) => {
                if (event.target !== modal) {
                    return;
                }

                const rect =
                    modal.getBoundingClientRect();

                const inside =
                    event.clientX >= rect.left &&
                    event.clientX <= rect.right &&
                    event.clientY >= rect.top &&
                    event.clientY <= rect.bottom;

                if (!inside) {
                    closeModal();
                }
            }
        );

        modal
            .querySelectorAll(
                '[data-confirm-move-thread]'
            )
            .forEach(
                (form) => {
                    form.addEventListener(
                        'submit',
                        (event) => {
                            if (
                                !window.confirm(
                                    'Move this thread to the selected board?'
                                )
                            ) {
                                event.preventDefault();
                            }
                        }
                    );
                }
            );

        modal
            .querySelectorAll(
                '[data-confirm-delete-thread]'
            )
            .forEach(
                (form) => {
                    form.addEventListener(
                        'submit',
                        (event) => {
                            if (
                                !window.confirm(
                                    'Delete this entire thread? This will remove it from normal forum view.'
                                )
                            ) {
                                event.preventDefault();
                            }
                        }
                    );
                }
            );
    })();

</script>

<?php endif; ?>


<dialog class="forum-admin-modal forum-report-modal" id="forum-report-modal"
    aria-labelledby="forum-report-modal-heading">
    <header class="forum-admin-titlebar forum-admin-modal-titlebar">
        <div>
            <p class="forum-admin-step">Member Report</p>
            <h2 id="forum-report-modal-heading">Report Post</h2>
        </div>
        <button type="button" class="forum-admin-modal-close" data-close-report-modal aria-label="Close report form">
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
                <path d="M6.75 6.75 17.25 17.25M17.25 6.75 6.75 17.25" fill="none" stroke="currentColor"
                    stroke-width="1.8" stroke-linecap="round" />
            </svg>
        </button>
    </header>

    <form method="post" action="<?= e(blackthorne_thread_page_url($threadId, $page)); ?>"
        class="forum-admin-form forum-report-form" data-report-form>
        <?= csrf_field(); ?>
        <input type="hidden" name="action" value="report_post">
        <input type="hidden" name="post_id" value="" data-report-post-id>

        <p class="forum-report-intro">
            Report the selected post by <strong data-report-author>this member</strong> for staff review.
        </p>

        <div class="form-group">
            <label for="forum-report-reason">Reason</label>
            <select class="form-control" id="forum-report-reason" name="reason" required>
                <option value="">Choose a reason</option>
                <option value="Spam or advertising">Spam or advertising</option>
                <option value="Harassment or bullying">Harassment or bullying</option>
                <option value="Inappropriate content">Inappropriate content</option>
                <option value="Personal or private information">Personal or private information</option>
                <option value="Other">Other</option>
            </select>
        </div>

        <div class="form-group">
            <label for="forum-report-details">Additional details <span class="muted">(optional)</span></label>
            <textarea class="form-control" id="forum-report-details" name="details" rows="5" maxlength="2000"
                placeholder="Tell staff what they should look at."></textarea>
        </div>

        <div class="forum-report-form-actions">
            <button type="button" class="button" data-close-report-modal>Cancel</button>
            <button type="submit" class="button button-primary">Submit Report</button>
        </div>
    </form>
</dialog>

<script>
    (() => {
        'use strict';

        const reportModal = document.getElementById('forum-report-modal');
        if (!reportModal) return;

        const reportPostInput = reportModal.querySelector('[data-report-post-id]');
        const reportAuthor = reportModal.querySelector('[data-report-author]');
        const reportReason = reportModal.querySelector('#forum-report-reason');
        const reportDetails = reportModal.querySelector('#forum-report-details');

        document.querySelectorAll('[data-report-post]').forEach((button) => {
            button.addEventListener('click', () => {
                if (reportPostInput) reportPostInput.value = button.dataset.reportPost || '';
                if (reportAuthor) reportAuthor.textContent = button.dataset.reportAuthor ||
                    'this member';
                if (reportReason) reportReason.value = '';
                if (reportDetails) reportDetails.value = '';

                if (typeof reportModal.showModal === 'function') {
                    reportModal.showModal();
                }
            });
        });

        reportModal.querySelectorAll('[data-close-report-modal]').forEach((button) => {
            button.addEventListener('click', () => reportModal.close());
        });

        reportModal.addEventListener('click', (event) => {
            if (event.target !== reportModal) return;

            const rect = reportModal.getBoundingClientRect();
            const inside =
                event.clientX >= rect.left &&
                event.clientX <= rect.right &&
                event.clientY >= rect.top &&
                event.clientY <= rect.bottom;

            if (!inside) reportModal.close();
        });
    })();

</script>

<script>
    (() => {
        'use strict';

        const form = document.getElementById('thread-reply-form');
        const editor = document.getElementById('reply-editor');
        const input = document.getElementById('reply-content');

        if (!form || !editor || !input) {
            return;
        }

        const imageUploadButton = form.querySelector('[data-editor-image-upload]');
        const imageInput = form.querySelector('[data-editor-image-input]');
        const imageUrlButton = form.querySelector('[data-editor-image-url]');
        const imageSizeSelect = form.querySelector('[data-editor-image-size]');
        const imageAlignSelect = form.querySelector('[data-editor-image-align]');
        const imagePreviews = form.querySelector('[data-editor-image-previews]');
        const uploadTokensInput = form.querySelector('[data-editor-upload-tokens]');
        const wordCount = form.querySelector('[data-editor-word-count]');
        const characterCount = form.querySelector('[data-editor-character-count]');
        const quoteFormattingButton = form.querySelector('[data-editor-quote]');
        const youtubeButton = form.querySelector('[data-editor-youtube]');
        const maxImageUploads = <?= max(0, $forumMaxAttachmentsPerPost); ?>;
        const maxImageBytes = <?= max(1, $forumMaxImageSizeMb) * 1024 * 1024; ?>;
        const quotePostIdsInput = form.querySelector('[data-quote-post-ids]');

        let selectedUploads = [];
        let savedRange = null;
        let selectedImage = null;

        try {
            document.execCommand('styleWithCSS', false, true);
        } catch (error) {
            // Formatting still works in browsers that ignore styleWithCSS.
        }

        const escapeHtml = (value) => String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');

        const getPlainEditorText = () =>
            editor.textContent
            .replace(/\u00a0/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();

        const updateEditorCounts = () => {
            const plainText = getPlainEditorText();

            const characters = plainText.length;

            const words =
                plainText === '' ?
                0 :
                plainText
                .split(/\s+/u)
                .filter(Boolean)
                .length;

            if (wordCount) {
                wordCount.textContent =
                    `${words} ${words === 1 ? 'word' : 'words'}`;
            }

            if (characterCount) {
                characterCount.textContent =
                    `${characters} ${characters === 1 ? 'character' : 'characters'}`;
            }
        };

        const normalizeForumLink = (rawValue) => {
            const value =
                String(rawValue || '')
                .trim();

            if (
                value === '' ||
                /[\u0000-\u001F\u007F\\]/u.test(value)
            ) {
                return null;
            }

            if (value.startsWith('#')) {
                return value;
            }

            if (
                value.startsWith('/') &&
                !value.startsWith('//')
            ) {
                return value;
            }

            if (/^mailto:/i.test(value)) {
                const address =
                    value.slice(7);

                if (
                    address === '' ||
                    /\s/u.test(address)
                ) {
                    return null;
                }

                return `mailto:${address}`;
            }

            try {
                const parsed = new URL(value);

                if (
                    parsed.protocol !== 'http:' &&
                    parsed.protocol !== 'https:'
                ) {
                    return null;
                }

                return parsed.href;
            } catch (error) {
                return null;
            }
        };

        const insertPlainTextAtSelection = (plainText) => {
            const normalized =
                String(plainText || '')
                .replace(/\r\n?/g, '\n');

            if (normalized === '') {
                return;
            }

            const safeHtml =
                escapeHtml(normalized)
                .replace(/\n/g, '<br>');

            insertHtmlAtSelection(
                safeHtml
            );
        };

        const youtubeVideoIdFromUrl = (rawValue) => {
            const value = String(rawValue || '').trim();

            if (value === '') {
                return null;
            }

            let parsed;

            try {
                parsed = new URL(value);
            } catch (error) {
                return null;
            }

            if (
                parsed.protocol !== 'https:' &&
                parsed.protocol !== 'http:'
            ) {
                return null;
            }

            const host =
                parsed.hostname
                .toLowerCase()
                .replace(/^(?:www\.|m\.)/, '');

            let videoId = null;

            if (host === 'youtu.be') {
                videoId =
                    parsed.pathname
                    .split('/')
                    .filter(Boolean)[0] ||
                    null;
            } else if (
                host === 'youtube.com' ||
                host === 'youtube-nocookie.com'
            ) {
                if (parsed.pathname === '/watch') {
                    videoId =
                        parsed.searchParams.get('v');
                } else {
                    const segments =
                        parsed.pathname
                        .split('/')
                        .filter(Boolean);

                    if (
                        segments.length >= 2 && ['shorts', 'embed', 'live'].includes(
                            segments[0].toLowerCase()
                        )
                    ) {
                        videoId = segments[1];
                    }
                }
            }

            return /^[A-Za-z0-9_-]{11}$/.test(videoId || '') ?
                videoId :
                null;
        };

        const insertYoutubeEmbedPlaceholder = (videoId) => {
            const href =
                `https://www.youtube.com/watch?v=${videoId}`;

            const safeHref =
                escapeHtml(href);

            const safeTitle =
                escapeHtml(
                    `blackthorne-youtube:${videoId}`
                );

            insertHtmlAtSelection(
                `<a href="${safeHref}" title="${safeTitle}" target="_blank" rel="noopener noreferrer nofollow">YouTube video: ${safeHref}</a>`
            );
        };

        const saveSelection = () => {
            const selection = window.getSelection();
            if (!selection || selection.rangeCount === 0) return;
            const range = selection.getRangeAt(0);
            if (editor.contains(range.commonAncestorContainer)) {
                savedRange = range.cloneRange();
            }
        };

        const restoreSelection = () => {
            if (!savedRange) {
                return;
            }

            /*
             * Clone the author's selection before returning focus to the
             * contenteditable. Chrome can collapse the live selection when the
             * toolbar/color control takes focus. Restoring from this private copy
             * keeps formatting attached to the highlighted text.
             */
            const rangeToRestore =
                savedRange.cloneRange();

            try {
                editor.focus({
                    preventScroll: true
                });
            } catch (error) {
                editor.focus();
            }

            const selection =
                window.getSelection();

            if (!selection) {
                return;
            }

            selection.removeAllRanges();
            selection.addRange(
                rangeToRestore
            );
        };

        const syncEditor = () => {
            const clone = editor.cloneNode(true);
            clone.querySelectorAll('[data-forum-quote-preview]').forEach((quotePreview) => {
                quotePreview.remove();
            });

            clone.querySelectorAll('img[data-upload-token]').forEach((image) => {
                const token = image.getAttribute('data-upload-token');
                if (token) {
                    image.setAttribute('src', `/__blackthorne_pending_image_${token}__`);
                }
                image.removeAttribute('data-upload-token');
            });
            input.value = clone.innerHTML.trim();
            updateEditorCounts();
        };

        const updateSelectedImageControls = () => {
            if (!selectedImage) {
                if (imageSizeSelect) {
                    imageSizeSelect.value = '';
                }

                if (imageAlignSelect) {
                    imageAlignSelect.value = '';
                }

                return;
            }

            if (imageSizeSelect) {
                const currentWidth =
                    selectedImage.style.width;

                const hasSize =
                    Array.from(
                        imageSizeSelect.options
                    ).some(
                        (option) =>
                        option.value === currentWidth
                    );

                imageSizeSelect.value =
                    hasSize ?
                    currentWidth :
                    '';
            }

            if (imageAlignSelect) {
                const left =
                    selectedImage.style.marginLeft;

                const right =
                    selectedImage.style.marginRight;

                if (
                    left === '0px' &&
                    right === 'auto'
                ) {
                    imageAlignSelect.value = 'left';
                } else if (
                    left === 'auto' &&
                    right === '0px'
                ) {
                    imageAlignSelect.value = 'right';
                } else if (
                    left === 'auto' &&
                    right === 'auto'
                ) {
                    imageAlignSelect.value = 'center';
                } else {
                    imageAlignSelect.value = '';
                }
            }
        };

        editor.addEventListener(
            'click',
            (event) => {
                const target =
                    event.target;

                selectedImage =
                    target instanceof HTMLImageElement &&
                    editor.contains(target) ?
                    target :
                    null;

                updateSelectedImageControls();
            }
        );

        if (imageSizeSelect) {
            imageSizeSelect.addEventListener(
                'change',
                () => {
                    if (
                        !selectedImage ||
                        !editor.contains(selectedImage)
                    ) {
                        window.alert(
                            'Click an image in the editor first, then choose its size.'
                        );

                        imageSizeSelect.value = '';
                        return;
                    }

                    const size =
                        imageSizeSelect.value;

                    if (size === '') {
                        return;
                    }

                    selectedImage.style.width =
                        size;

                    selectedImage.style.maxWidth =
                        '100%';

                    selectedImage.style.height =
                        'auto';

                    syncEditor();
                }
            );
        }

        if (imageAlignSelect) {
            imageAlignSelect.addEventListener(
                'change',
                () => {
                    if (
                        !selectedImage ||
                        !editor.contains(selectedImage)
                    ) {
                        window.alert(
                            'Click an image in the editor first, then choose its alignment.'
                        );

                        imageAlignSelect.value = '';
                        return;
                    }

                    const alignment =
                        imageAlignSelect.value;

                    if (alignment === '') {
                        return;
                    }

                    selectedImage.style.display =
                        'block';

                    if (alignment === 'left') {
                        selectedImage.style.marginLeft =
                            '0';

                        selectedImage.style.marginRight =
                            'auto';
                    } else if (alignment === 'right') {
                        selectedImage.style.marginLeft =
                            'auto';

                        selectedImage.style.marginRight =
                            '0';
                    } else {
                        selectedImage.style.marginLeft =
                            'auto';

                        selectedImage.style.marginRight =
                            'auto';
                    }

                    syncEditor();
                }
            );
        }

        const applyBlockAlignment = (alignmentCommand) => {
            const alignmentMap = {
                justifyLeft: 'left',
                justifyCenter: 'center',
                justifyRight: 'right',
            };

            const alignment =
                alignmentMap[
                    alignmentCommand
                ] ??
                '';

            if (alignment === '') {
                return false;
            }

            if (!savedRange) {
                return true;
            }

            /*
             * Do not run a browser alignment command on the live selection.
             * Chrome can merge inline formatting when a selection crosses a
             * heading/paragraph boundary. Instead, identify the selected blocks,
             * apply alignment to a detached clone, then replace the editor HTML.
             * This preserves the exact <strong>, <em>, color, link, etc. markup.
             */
            const range =
                savedRange.cloneRange();

            const blockSelector =
                'p,h1,h2,h3,h4,h5,h6,blockquote,li,div';

            const liveBlocks =
                Array.from(
                    editor.querySelectorAll(
                        blockSelector
                    )
                );

            let selectedIndexes =
                liveBlocks
                .map(
                    (block, index) => {
                        try {
                            return range.intersectsNode(block) ?
                                index :
                                -1;
                        } catch (error) {
                            return -1;
                        }
                    }
                )
                .filter(
                    (index) => index >= 0
                );

            /*
             * If both an outer DIV and its inner P/H2 are selected, only style
             * the innermost blocks. This avoids wrapping/inheritance surprises.
             */
            selectedIndexes =
                selectedIndexes.filter(
                    (index) => {
                        const block =
                            liveBlocks[index];

                        return !selectedIndexes.some(
                            (otherIndex) =>
                            otherIndex !== index &&
                            block.contains(
                                liveBlocks[
                                    otherIndex
                                ]
                            )
                        );
                    }
                );

            if (selectedIndexes.length === 0) {
                let node =
                    range.commonAncestorContainer;

                if (node.nodeType === Node.TEXT_NODE) {
                    node =
                        node.parentElement;
                }

                const nearestBlock =
                    node instanceof Element ?
                    node.closest(
                        blockSelector
                    ) :
                    null;

                if (
                    nearestBlock &&
                    editor.contains(
                        nearestBlock
                    )
                ) {
                    const index =
                        liveBlocks.indexOf(
                            nearestBlock
                        );

                    if (index >= 0) {
                        selectedIndexes = [
                            index,
                        ];
                    }
                }
            }

            if (selectedIndexes.length === 0) {
                return true;
            }

            const editorClone =
                editor.cloneNode(true);

            const clonedBlocks =
                Array.from(
                    editorClone.querySelectorAll(
                        blockSelector
                    )
                );

            selectedIndexes.forEach(
                (index) => {
                    const clonedBlock =
                        clonedBlocks[index];

                    if (clonedBlock) {
                        clonedBlock.style.textAlign =
                            alignment;
                    }
                }
            );

            editor.innerHTML =
                editorClone.innerHTML;

            /*
             * The old Range points at nodes that were just replaced, so discard
             * it. The next mouse/keyboard selection will establish a fresh one.
             */
            savedRange = null;

            syncEditor();
            return true;
        };

        const runCommand = (command, value = null) => {
            if (applyBlockAlignment(command)) {
                return;
            }

            restoreSelection();
            document.execCommand(command, false, value);
            saveSelection();
            syncEditor();
        };

        const insertHtmlAtSelection = (html) => {
            restoreSelection();
            document.execCommand('insertHTML', false, html);
            saveSelection();
            syncEditor();
        };

        const createUploadToken = () => {
            if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
                const bytes = new Uint8Array(12);
                window.crypto.getRandomValues(bytes);
                return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
            }
            return `${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 14)}`;
        };

        const rebuildFileInput = () => {
            if (!imageInput || !uploadTokensInput) return;
            const transfer = new DataTransfer();
            selectedUploads.forEach((upload) => transfer.items.add(upload.file));
            imageInput.files = transfer.files;
            uploadTokensInput.value = JSON.stringify(selectedUploads.map((upload) => upload.token));
        };

        const renderImagePreviews = () => {
            if (!imagePreviews) return;
            imagePreviews.innerHTML = '';
            imagePreviews.hidden = selectedUploads.length === 0;

            selectedUploads.forEach((upload) => {
                const card = document.createElement('div');
                card.className = 'forum-editor-image-preview';
                const image = document.createElement('img');
                image.src = upload.previewUrl;
                image.alt = '';
                const name = document.createElement('span');
                name.className = 'forum-editor-image-preview-name';
                name.textContent = upload.file.name;
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'forum-editor-image-preview-remove';
                remove.setAttribute('aria-label', `Remove ${upload.file.name}`);
                remove.textContent = '×';
                remove.addEventListener('click', () => {
                    URL.revokeObjectURL(upload.previewUrl);
                    selectedUploads = selectedUploads.filter((item) => item.token !== upload
                        .token);
                    editor.querySelectorAll(
                        `img[data-upload-token="${CSS.escape(upload.token)}"]`).forEach((
                        embedded) => embedded.remove());
                    rebuildFileInput();
                    renderImagePreviews();
                    syncEditor();
                });
                card.append(image, name, remove);
                imagePreviews.appendChild(card);
            });
        };

        form.querySelectorAll('[data-command]').forEach((button) => {
            button.addEventListener('mousedown', (event) => event.preventDefault());
            button.addEventListener('click', () => runCommand(button.dataset.command || ''));
        });

        const formatSelect = form.querySelector('[data-editor-format]');
        if (formatSelect) {
            formatSelect.addEventListener('change', () => {
                runCommand('formatBlock', formatSelect.value);
                formatSelect.value = 'p';
            });
        }

        const sizeSelect = form.querySelector('[data-editor-size]');
        if (sizeSelect) {
            sizeSelect.addEventListener('change', () => {
                runCommand('fontSize', sizeSelect.value);
                sizeSelect.value = '3';
            });
        }

        const colorInput = form.querySelector('[data-editor-color]');
        const applyColorButton = form.querySelector('[data-editor-apply-color]');
        const applyHighlightButton = form.querySelector('[data-editor-apply-highlight]');

        const applyTextColor = () => {
            if (!savedRange || savedRange.collapsed) {
                return;
            }

            runCommand('foreColor', colorInput.value);
        };

        if (colorInput) {
            colorInput.addEventListener('pointerdown', saveSelection);
            colorInput.addEventListener('click', applyTextColor);
            colorInput.addEventListener('input', applyTextColor);
            colorInput.addEventListener('change', applyTextColor);
        }

        const highlightInput = form.querySelector('[data-editor-highlight]');

        const applyHighlightColor = () => {
            if (!savedRange || savedRange.collapsed) {
                return;
            }

            restoreSelection();

            document.execCommand(
                document.queryCommandSupported('hiliteColor') ?
                'hiliteColor' :
                'backColor',
                false,
                highlightInput.value
            );

            syncEditor();
            saveSelection();
        };

        if (highlightInput) {
            highlightInput.addEventListener('pointerdown', saveSelection);
            highlightInput.addEventListener('click', applyHighlightColor);
            highlightInput.addEventListener('input', applyHighlightColor);
            highlightInput.addEventListener('change', applyHighlightColor);
        }

        if (applyColorButton) {
            applyColorButton.addEventListener('mousedown', (event) => event.preventDefault());
            applyColorButton.addEventListener('click', applyTextColor);
        }

        if (applyHighlightButton) {
            applyHighlightButton.addEventListener('mousedown', (event) => event.preventDefault());
            applyHighlightButton.addEventListener('click', applyHighlightColor);
        }

        const linkButton = form.querySelector('[data-editor-link]');
        if (linkButton) {
            linkButton.addEventListener('mousedown', (event) => event.preventDefault());
            linkButton.addEventListener('click', () => {
                saveSelection();

                const href = window.prompt('Enter the link URL:');

                if (!href) {
                    return;
                }

                const normalizedHref =
                    normalizeForumLink(href);

                if (!normalizedHref) {
                    window.alert(
                        'Use a full http:// or https:// URL, a mailto: link, a #anchor, or a Blackthorne site-relative link beginning with a single /.'
                    );
                    return;
                }

                restoreSelection();

                const selection =
                    window.getSelection();

                if (
                    selection &&
                    selection.rangeCount > 0 &&
                    !selection.getRangeAt(0).collapsed
                ) {
                    document.execCommand(
                        'createLink',
                        false,
                        normalizedHref
                    );
                } else {
                    const safeHref =
                        escapeHtml(normalizedHref);

                    insertHtmlAtSelection(
                        `<a href="${safeHref}" rel="noopener noreferrer nofollow">${safeHref}</a>`
                    );
                }

                saveSelection();
                syncEditor();
            });
        }

        if (quoteFormattingButton) {
            quoteFormattingButton.addEventListener(
                'mousedown',
                (event) => event.preventDefault()
            );

            quoteFormattingButton.addEventListener(
                'click',
                () => {
                    runCommand(
                        'formatBlock',
                        'blockquote'
                    );
                }
            );
        }

        if (youtubeButton) {
            youtubeButton.addEventListener(
                'mousedown',
                (event) => {
                    event.preventDefault();
                    saveSelection();
                }
            );

            youtubeButton.addEventListener(
                'click',
                () => {
                    const youtubeUrl =
                        window.prompt(
                            'Paste the YouTube video URL:'
                        );

                    if (!youtubeUrl) {
                        return;
                    }

                    const videoId =
                        youtubeVideoIdFromUrl(
                            youtubeUrl
                        );

                    if (!videoId) {
                        window.alert(
                            'Use a valid YouTube video, Shorts, Live, or youtu.be URL.'
                        );
                        return;
                    }

                    insertYoutubeEmbedPlaceholder(
                        videoId
                    );
                }
            );
        }

        if (imageUrlButton) {
            imageUrlButton.addEventListener('mousedown', (event) => {
                event.preventDefault();
                saveSelection();
            });
            imageUrlButton.addEventListener('click', () => {
                const imageUrl = window.prompt('Enter the direct HTTPS image URL:');
                if (!imageUrl) return;
                const trimmed = imageUrl.trim();
                if (!/^https:\/\//i.test(trimmed)) {
                    window.alert('Image URLs must begin with https://');
                    return;
                }
                const altText = window.prompt('Optional image description (alt text):') || '';
                insertHtmlAtSelection(
                    `<img src="${escapeHtml(trimmed)}" alt="${escapeHtml(altText.trim())}" loading="lazy" style="display:block;margin-left:auto;margin-right:auto;max-width:100%;height:auto;">`
                );
            });
        }

        if (imageUploadButton && imageInput) {
            imageUploadButton.addEventListener('mousedown', (event) => {
                event.preventDefault();
                saveSelection();
            });
            imageUploadButton.addEventListener('click', () => imageInput.click());
            imageInput.addEventListener('change', () => {
                const incomingFiles = Array.from(imageInput.files || []);
                if (incomingFiles.length === 0) {
                    rebuildFileInput();
                    return;
                }
                if (maxImageUploads > 0 && selectedUploads.length + incomingFiles.length >
                    maxImageUploads) {
                    window.alert(`You can upload up to ${maxImageUploads} images in one post.`);
                    rebuildFileInput();
                    return;
                }
                for (const file of incomingFiles) {
                    if (!file.type.startsWith('image/')) {
                        window.alert(`${file.name} is not an image file.`);
                        continue;
                    }
                    if (file.size > maxImageBytes) {
                        window.alert(`${file.name} is larger than the allowed image size.`);
                        continue;
                    }
                    const token = createUploadToken();
                    const previewUrl = URL.createObjectURL(file);
                    selectedUploads.push({
                        file,
                        token,
                        previewUrl
                    });
                    insertHtmlAtSelection(
                        `<img src="${escapeHtml(previewUrl)}" alt="${escapeHtml(file.name)}" data-upload-token="${escapeHtml(token)}" loading="lazy" style="display:block;margin-left:auto;margin-right:auto;max-width:100%;height:auto;">`
                    );
                }
                rebuildFileInput();
                renderImagePreviews();
            });
        }

        document.querySelectorAll('[data-quote-post]').forEach((quoteButton) => {
            quoteButton.addEventListener('click', () => {
                const postId = Number.parseInt(quoteButton.dataset.quotePost || '0', 10);
                const article = quoteButton.closest('.forum-post');

                if (!postId || !article) {
                    return;
                }

                if (editor.querySelector(
                        `[data-forum-quote-preview][data-quoted-post-id="${postId}"]`)) {
                    document.getElementById('respond')?.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                    editor.focus();
                    return;
                }

                const author = article.querySelector('.forum-post-member-name')?.textContent
                    ?.trim() || 'Member';
                const body = article.querySelector('.forum-post-body');
                const quoteText = body?.innerText?.trim() || '';

                const quotePreview = document.createElement('blockquote');
                quotePreview.className = 'forum-post-quote forum-editor-quoted-post';
                quotePreview.setAttribute('data-forum-quote-preview', '');
                quotePreview.setAttribute('data-quoted-post-id', String(postId));
                quotePreview.setAttribute('contenteditable', 'false');

                const quoteHeader = document.createElement('header');
                quoteHeader.textContent = `${author} wrote:`;

                const quoteBody = document.createElement('div');
                quoteBody.textContent = quoteText;

                quotePreview.append(quoteHeader, quoteBody);

                editor.insertBefore(quotePreview, editor.firstChild);

                let replyParagraph = editor.querySelector('[data-quote-reply-caret]');

                if (!replyParagraph) {
                    replyParagraph = document.createElement('p');
                    replyParagraph.setAttribute('data-quote-reply-caret', '');
                    replyParagraph.appendChild(document.createElement('br'));
                    editor.appendChild(replyParagraph);
                }

                const currentQuoteIds = Array.from(
                        editor.querySelectorAll('[data-forum-quote-preview]')
                    ).map((node) => Number.parseInt(node.getAttribute('data-quoted-post-id') || '0',
                        10))
                    .filter((id) => id > 0);

                if (quotePostIdsInput) {
                    quotePostIdsInput.value = JSON.stringify(currentQuoteIds);
                }

                syncEditor();
                document.getElementById('respond')?.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });

                if (replyParagraph) {
                    const range = document.createRange();
                    range.selectNodeContents(replyParagraph);
                    range.collapse(false);

                    const selection = window.getSelection();
                    selection.removeAllRanges();
                    selection.addRange(range);
                    savedRange = range.cloneRange();
                }

                editor.focus();
            });
        });

        editor.addEventListener('click', (event) => {
            const quotePreview = event.target.closest('[data-forum-quote-preview]');

            if (!quotePreview) {
                return;
            }

            let replyParagraph = editor.querySelector('[data-quote-reply-caret]');

            if (!replyParagraph) {
                replyParagraph = document.createElement('p');
                replyParagraph.setAttribute('data-quote-reply-caret', '');
                replyParagraph.appendChild(document.createElement('br'));
                editor.appendChild(replyParagraph);
            }

            const range = document.createRange();
            range.selectNodeContents(replyParagraph);
            range.collapse(false);

            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            savedRange = range.cloneRange();
            editor.focus({
                preventScroll: true
            });
        });

        ['keyup', 'mouseup', 'input'].forEach((eventName) => {
            editor.addEventListener(eventName, () => {
                saveSelection();
                syncEditor();
            });
        });

        editor.addEventListener(
            'paste',
            (event) => {
                const clipboard = event.clipboardData;

                if (!clipboard) {
                    return;
                }

                const plainText =
                    clipboard.getData('text/plain');

                if (plainText === '') {
                    return;
                }

                event.preventDefault();
                saveSelection();

                insertPlainTextAtSelection(
                    plainText
                );
            }
        );

        form.addEventListener('submit', (event) => {
            if (quotePostIdsInput) {
                quotePostIdsInput.value = JSON.stringify(
                    Array.from(
                        editor.querySelectorAll('[data-forum-quote-preview]')
                    )
                    .map((node) => Number.parseInt(node.getAttribute('data-quoted-post-id') || '0', 10))
                    .filter((id) => id > 0)
                );
            }

            syncEditor();
            const text = getPlainEditorText();
            const hasImage = editor.querySelector('img') !== null;
            if (!text && !hasImage) {
                event.preventDefault();
                window.alert('Write a reply or add an image before posting.');
                editor.focus();
            }
        });

        if (editor.innerHTML.trim() !== '') {
            input.value = editor.innerHTML.trim();
        }

        updateEditorCounts();
    })();

</script>


<script>
    (() => {
        'use strict';

        document.querySelectorAll('[data-inline-edit-form]').forEach((form) => {
            const editor = form.querySelector('[data-inline-editor-area]');
            const input = form.querySelector('[data-inline-editor-input]');
            const wordCount = form.querySelector('[data-edit-word-count]');
            const characterCount = form.querySelector('[data-edit-character-count]');
            const quoteFormattingButton = form.querySelector('[data-edit-quote]');
            const youtubeButton = form.querySelector('[data-edit-youtube]');

            if (!editor || !input) {
                return;
            }

            let savedRange = null;
            let selectedImage = null;

            try {
                document.execCommand('styleWithCSS', false, true);
            } catch (error) {
                // Formatting still works when styleWithCSS is unavailable.
            }

            const escapeHtml = (value) => String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');

            const getPlainEditorText = () =>
                editor.textContent
                .replace(/\u00a0/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();

            const updateEditorCounts = () => {
                const plainText =
                    getPlainEditorText();

                const characters =
                    plainText.length;

                const words =
                    plainText === '' ?
                    0 :
                    plainText
                    .split(/\s+/u)
                    .filter(Boolean)
                    .length;

                if (wordCount) {
                    wordCount.textContent =
                        `${words} ${words === 1 ? 'word' : 'words'}`;
                }

                if (characterCount) {
                    characterCount.textContent =
                        `${characters} ${characters === 1 ? 'character' : 'characters'}`;
                }
            };

            const normalizeForumLink = (rawValue) => {
                const value =
                    String(rawValue || '')
                    .trim();

                if (
                    value === '' ||
                    /[\u0000-\u001F\u007F\\]/u.test(value)
                ) {
                    return null;
                }

                if (value.startsWith('#')) {
                    return value;
                }

                if (
                    value.startsWith('/') &&
                    !value.startsWith('//')
                ) {
                    return value;
                }

                if (/^mailto:/i.test(value)) {
                    const address =
                        value.slice(7);

                    if (
                        address === '' ||
                        /\s/u.test(address)
                    ) {
                        return null;
                    }

                    return `mailto:${address}`;
                }

                try {
                    const parsed =
                        new URL(value);

                    if (
                        parsed.protocol !== 'http:' &&
                        parsed.protocol !== 'https:'
                    ) {
                        return null;
                    }

                    return parsed.href;
                } catch (error) {
                    return null;
                }
            };

            const insertHtmlAtSelection = (html) => {
                restoreSelection();
                document.execCommand(
                    'insertHTML',
                    false,
                    html
                );
                saveSelection();
                sync();
            };

            const insertPlainTextAtSelection = (plainText) => {
                const normalized =
                    String(plainText || '')
                    .replace(/\r\n?/g, '\n');

                if (normalized === '') {
                    return;
                }

                const safeHtml =
                    escapeHtml(normalized)
                    .replace(/\n/g, '<br>');

                insertHtmlAtSelection(
                    safeHtml
                );
            };

            const youtubeVideoIdFromUrl = (rawValue) => {
                const value =
                    String(rawValue || '')
                    .trim();

                if (value === '') {
                    return null;
                }

                let parsed;

                try {
                    parsed = new URL(value);
                } catch (error) {
                    return null;
                }

                if (
                    parsed.protocol !== 'https:' &&
                    parsed.protocol !== 'http:'
                ) {
                    return null;
                }

                const host =
                    parsed.hostname
                    .toLowerCase()
                    .replace(/^(?:www\.|m\.)/, '');

                let videoId = null;

                if (host === 'youtu.be') {
                    videoId =
                        parsed.pathname
                        .split('/')
                        .filter(Boolean)[0] ||
                        null;
                } else if (
                    host === 'youtube.com' ||
                    host === 'youtube-nocookie.com'
                ) {
                    if (parsed.pathname === '/watch') {
                        videoId =
                            parsed.searchParams.get('v');
                    } else {
                        const segments =
                            parsed.pathname
                            .split('/')
                            .filter(Boolean);

                        if (
                            segments.length >= 2 && ['shorts', 'embed', 'live'].includes(
                                segments[0].toLowerCase()
                            )
                        ) {
                            videoId = segments[1];
                        }
                    }
                }

                return /^[A-Za-z0-9_-]{11}$/.test(videoId || '') ?
                    videoId :
                    null;
            };

            const insertYoutubeEmbedPlaceholder = (videoId) => {
                const href =
                    `https://www.youtube.com/watch?v=${videoId}`;

                const safeHref =
                    escapeHtml(href);

                const safeTitle =
                    escapeHtml(
                        `blackthorne-youtube:${videoId}`
                    );

                insertHtmlAtSelection(
                    `<a href="${safeHref}" title="${safeTitle}" target="_blank" rel="noopener noreferrer nofollow">YouTube video: ${safeHref}</a>`
                );
            };

            const saveSelection = () => {
                const selection = window.getSelection();

                if (!selection || selection.rangeCount === 0) {
                    return;
                }

                const range = selection.getRangeAt(0);

                if (editor.contains(range.commonAncestorContainer)) {
                    savedRange = range.cloneRange();
                }
            };

            const restoreSelection = () => {
                if (!savedRange) {
                    return;
                }

                /*
                 * Clone the author's selection before returning focus to the
                 * contenteditable. Chrome can collapse the live selection when the
                 * toolbar/color control takes focus. Restoring from this private copy
                 * keeps formatting attached to the highlighted text.
                 */
                const rangeToRestore =
                    savedRange.cloneRange();

                try {
                    editor.focus({
                        preventScroll: true
                    });
                } catch (error) {
                    editor.focus();
                }

                const selection =
                    window.getSelection();

                if (!selection) {
                    return;
                }

                selection.removeAllRanges();
                selection.addRange(
                    rangeToRestore
                );
            };

            const sync = () => {
                input.value = editor.innerHTML.trim();
                updateEditorCounts();
            };

            const updateSelectedImageControls = () => {
                if (!selectedImage) {
                    if (imageSizeSelect) {
                        imageSizeSelect.value = '';
                    }

                    if (imageAlignSelect) {
                        imageAlignSelect.value = '';
                    }

                    return;
                }

                if (imageSizeSelect) {
                    const currentWidth =
                        selectedImage.style.width;

                    const hasSize =
                        Array.from(
                            imageSizeSelect.options
                        ).some(
                            (option) =>
                            option.value === currentWidth
                        );

                    imageSizeSelect.value =
                        hasSize ?
                        currentWidth :
                        '';
                }

                if (imageAlignSelect) {
                    const left =
                        selectedImage.style.marginLeft;

                    const right =
                        selectedImage.style.marginRight;

                    if (
                        left === '0px' &&
                        right === 'auto'
                    ) {
                        imageAlignSelect.value = 'left';
                    } else if (
                        left === 'auto' &&
                        right === '0px'
                    ) {
                        imageAlignSelect.value = 'right';
                    } else if (
                        left === 'auto' &&
                        right === 'auto'
                    ) {
                        imageAlignSelect.value = 'center';
                    } else {
                        imageAlignSelect.value = '';
                    }
                }
            };

            editor.addEventListener(
                'click',
                (event) => {
                    const target =
                        event.target;

                    selectedImage =
                        target instanceof HTMLImageElement &&
                        editor.contains(target) ?
                        target :
                        null;

                    updateSelectedImageControls();
                }
            );

            if (imageSizeSelect) {
                imageSizeSelect.addEventListener(
                    'change',
                    () => {
                        if (
                            !selectedImage ||
                            !editor.contains(selectedImage)
                        ) {
                            window.alert(
                                'Click an image in the editor first, then choose its size.'
                            );

                            imageSizeSelect.value = '';
                            return;
                        }

                        const size =
                            imageSizeSelect.value;

                        if (size === '') {
                            return;
                        }

                        selectedImage.style.width =
                            size;

                        selectedImage.style.maxWidth =
                            '100%';

                        selectedImage.style.height =
                            'auto';

                        sync();
                    }
                );
            }

            if (imageAlignSelect) {
                imageAlignSelect.addEventListener(
                    'change',
                    () => {
                        if (
                            !selectedImage ||
                            !editor.contains(selectedImage)
                        ) {
                            window.alert(
                                'Click an image in the editor first, then choose its alignment.'
                            );

                            imageAlignSelect.value = '';
                            return;
                        }

                        const alignment =
                            imageAlignSelect.value;

                        if (alignment === '') {
                            return;
                        }

                        selectedImage.style.display =
                            'block';

                        if (alignment === 'left') {
                            selectedImage.style.marginLeft =
                                '0';

                            selectedImage.style.marginRight =
                                'auto';
                        } else if (alignment === 'right') {
                            selectedImage.style.marginLeft =
                                'auto';

                            selectedImage.style.marginRight =
                                '0';
                        } else {
                            selectedImage.style.marginLeft =
                                'auto';

                            selectedImage.style.marginRight =
                                'auto';
                        }

                        sync();
                    }
                );
            }

            const applyBlockAlignment = (alignmentCommand) => {
                const alignmentMap = {
                    justifyLeft: 'left',
                    justifyCenter: 'center',
                    justifyRight: 'right',
                };

                const alignment =
                    alignmentMap[
                        alignmentCommand
                    ] ??
                    '';

                if (alignment === '') {
                    return false;
                }

                if (!savedRange) {
                    return true;
                }

                /*
                 * Do not run a browser alignment command on the live selection.
                 * Chrome can merge inline formatting when a selection crosses a
                 * heading/paragraph boundary. Instead, identify the selected blocks,
                 * apply alignment to a detached clone, then replace the editor HTML.
                 * This preserves the exact <strong>, <em>, color, link, etc. markup.
                 */
                const range =
                    savedRange.cloneRange();

                const blockSelector =
                    'p,h1,h2,h3,h4,h5,h6,blockquote,li,div';

                const liveBlocks =
                    Array.from(
                        editor.querySelectorAll(
                            blockSelector
                        )
                    );

                let selectedIndexes =
                    liveBlocks
                    .map(
                        (block, index) => {
                            try {
                                return range.intersectsNode(block) ?
                                    index :
                                    -1;
                            } catch (error) {
                                return -1;
                            }
                        }
                    )
                    .filter(
                        (index) => index >= 0
                    );

                /*
                 * If both an outer DIV and its inner P/H2 are selected, only style
                 * the innermost blocks. This avoids wrapping/inheritance surprises.
                 */
                selectedIndexes =
                    selectedIndexes.filter(
                        (index) => {
                            const block =
                                liveBlocks[index];

                            return !selectedIndexes.some(
                                (otherIndex) =>
                                otherIndex !== index &&
                                block.contains(
                                    liveBlocks[
                                        otherIndex
                                    ]
                                )
                            );
                        }
                    );

                if (selectedIndexes.length === 0) {
                    let node =
                        range.commonAncestorContainer;

                    if (node.nodeType === Node.TEXT_NODE) {
                        node =
                            node.parentElement;
                    }

                    const nearestBlock =
                        node instanceof Element ?
                        node.closest(
                            blockSelector
                        ) :
                        null;

                    if (
                        nearestBlock &&
                        editor.contains(
                            nearestBlock
                        )
                    ) {
                        const index =
                            liveBlocks.indexOf(
                                nearestBlock
                            );

                        if (index >= 0) {
                            selectedIndexes = [
                                index,
                            ];
                        }
                    }
                }

                if (selectedIndexes.length === 0) {
                    return true;
                }

                const editorClone =
                    editor.cloneNode(true);

                const clonedBlocks =
                    Array.from(
                        editorClone.querySelectorAll(
                            blockSelector
                        )
                    );

                selectedIndexes.forEach(
                    (index) => {
                        const clonedBlock =
                            clonedBlocks[index];

                        if (clonedBlock) {
                            clonedBlock.style.textAlign =
                                alignment;
                        }
                    }
                );

                editor.innerHTML =
                    editorClone.innerHTML;

                /*
                 * The old Range points at nodes that were just replaced, so discard
                 * it. The next mouse/keyboard selection will establish a fresh one.
                 */
                savedRange = null;

                sync();
                return true;
            };

            const runCommand = (command, value = null) => {
                if (applyBlockAlignment(command)) {
                    return;
                }

                restoreSelection();
                document.execCommand(command, false, value);
                saveSelection();
                sync();
            };

            form.querySelectorAll('[data-edit-command]').forEach((button) => {
                button.addEventListener('mousedown', (event) => event.preventDefault());
                button.addEventListener('click', () => {
                    runCommand(button.dataset.editCommand || '');
                });
            });

            const formatSelect = form.querySelector('[data-edit-format]');

            if (formatSelect) {
                formatSelect.addEventListener('change', () => {
                    runCommand('formatBlock', formatSelect.value);
                    formatSelect.value = 'p';
                });
            }

            const sizeSelect = form.querySelector('[data-edit-size]');

            if (sizeSelect) {
                sizeSelect.addEventListener('change', () => {
                    runCommand('fontSize', sizeSelect.value);
                    sizeSelect.value = '3';
                });
            }

            const colorInput = form.querySelector('[data-edit-color]');
            const applyColorButton = form.querySelector('[data-edit-apply-color]');
            const applyHighlightButton = form.querySelector('[data-edit-apply-highlight]');

            const applyEditTextColor = () => {
                if (!savedRange || savedRange.collapsed) {
                    return;
                }

                runCommand('foreColor', colorInput.value);
            };

            if (colorInput) {
                colorInput.addEventListener('pointerdown', saveSelection);
                colorInput.addEventListener('click', applyEditTextColor);
                colorInput.addEventListener('input', applyEditTextColor);
                colorInput.addEventListener('change', applyEditTextColor);
            }

            const highlightInput = form.querySelector('[data-edit-highlight]');

            const applyEditHighlightColor = () => {
                if (!savedRange || savedRange.collapsed) {
                    return;
                }

                restoreSelection();

                document.execCommand(
                    document.queryCommandSupported('hiliteColor') ?
                    'hiliteColor' :
                    'backColor',
                    false,
                    highlightInput.value
                );

                syncEditor();
                saveSelection();
            };

            if (highlightInput) {
                highlightInput.addEventListener('pointerdown', saveSelection);
                highlightInput.addEventListener('click', applyEditHighlightColor);
                highlightInput.addEventListener('input', applyEditHighlightColor);
                highlightInput.addEventListener('change', applyEditHighlightColor);
            }

            if (applyColorButton) {
                applyColorButton.addEventListener('mousedown', (event) => event.preventDefault());
                applyColorButton.addEventListener('click', applyEditTextColor);
            }

            if (applyHighlightButton) {
                applyHighlightButton.addEventListener('mousedown', (event) => event.preventDefault());
                applyHighlightButton.addEventListener('click', applyEditHighlightColor);
            }

            const linkButton = form.querySelector('[data-edit-link]');

            if (linkButton) {
                linkButton.addEventListener('mousedown', (event) => event.preventDefault());
                linkButton.addEventListener('click', () => {
                    saveSelection();

                    const href =
                        window.prompt(
                            'Enter the link URL:'
                        );

                    if (!href) {
                        return;
                    }

                    const normalizedHref =
                        normalizeForumLink(href);

                    if (!normalizedHref) {
                        window.alert(
                            'Use a full http:// or https:// URL, a mailto: link, a #anchor, or a Blackthorne site-relative link beginning with a single /.'
                        );
                        return;
                    }

                    restoreSelection();

                    const selection =
                        window.getSelection();

                    if (
                        selection &&
                        selection.rangeCount > 0 &&
                        !selection.getRangeAt(0).collapsed
                    ) {
                        document.execCommand(
                            'createLink',
                            false,
                            normalizedHref
                        );
                    } else {
                        const safeHref =
                            escapeHtml(normalizedHref);

                        insertHtmlAtSelection(
                            `<a href="${safeHref}" rel="noopener noreferrer nofollow">${safeHref}</a>`
                        );
                    }

                    saveSelection();
                    sync();
                });
            }

            if (quoteFormattingButton) {
                quoteFormattingButton.addEventListener(
                    'mousedown',
                    (event) => event.preventDefault()
                );

                quoteFormattingButton.addEventListener(
                    'click',
                    () => {
                        runCommand(
                            'formatBlock',
                            'blockquote'
                        );
                    }
                );
            }

            if (youtubeButton) {
                youtubeButton.addEventListener(
                    'mousedown',
                    (event) => {
                        event.preventDefault();
                        saveSelection();
                    }
                );

                youtubeButton.addEventListener(
                    'click',
                    () => {
                        const youtubeUrl =
                            window.prompt(
                                'Paste the YouTube video URL:'
                            );

                        if (!youtubeUrl) {
                            return;
                        }

                        const videoId =
                            youtubeVideoIdFromUrl(
                                youtubeUrl
                            );

                        if (!videoId) {
                            window.alert(
                                'Use a valid YouTube video, Shorts, Live, or youtu.be URL.'
                            );
                            return;
                        }

                        insertYoutubeEmbedPlaceholder(
                            videoId
                        );
                    }
                );
            }

            const imageUrlButton = form.querySelector('[data-edit-image-url]');
            const imageSizeSelect = form.querySelector('[data-edit-image-size]');
            const imageAlignSelect = form.querySelector('[data-edit-image-align]');

            if (imageUrlButton) {
                imageUrlButton.addEventListener('mousedown', (event) => {
                    event.preventDefault();
                    saveSelection();
                });

                imageUrlButton.addEventListener('click', () => {
                    const imageUrl =
                        window.prompt(
                            'Enter the direct HTTPS image URL:'
                        );

                    if (!imageUrl) {
                        return;
                    }

                    const trimmed =
                        imageUrl.trim();

                    if (!/^https:\/\//i.test(trimmed)) {
                        window.alert(
                            'Image URLs must begin with https://'
                        );
                        return;
                    }

                    const altText =
                        window.prompt(
                            'Optional image description (alt text):'
                        ) ||
                        '';

                    restoreSelection();
                    document.execCommand(
                        'insertHTML',
                        false,
                        `<img src="${escapeHtml(trimmed)}" alt="${escapeHtml(altText.trim())}" loading="lazy" style="display:block;margin-left:auto;margin-right:auto;max-width:100%;height:auto;">`
                    );
                    saveSelection();
                    sync();
                });
            }

            ['keyup', 'mouseup', 'input'].forEach((eventName) => {
                editor.addEventListener(eventName, () => {
                    saveSelection();
                    sync();
                });
            });

            editor.addEventListener(
                'paste',
                (event) => {
                    const clipboard =
                        event.clipboardData;

                    if (!clipboard) {
                        return;
                    }

                    const plainText =
                        clipboard.getData(
                            'text/plain'
                        );

                    if (plainText === '') {
                        return;
                    }

                    event.preventDefault();
                    saveSelection();

                    insertPlainTextAtSelection(
                        plainText
                    );
                }
            );

            form.addEventListener('submit', (event) => {
                sync();

                const text =
                    getPlainEditorText();

                const hasImage =
                    editor.querySelector('img') !== null;

                if (!text && !hasImage) {
                    event.preventDefault();
                    window.alert(
                        'A post cannot be empty.'
                    );
                    editor.focus();
                }
            });

            updateEditorCounts();
        });
    })();

</script>


<style>
    /*
|--------------------------------------------------------------------------
| Rich Editor Working Area
|--------------------------------------------------------------------------
|
| Keep the editor at a practical default height so long content scrolls
| inside the writing area while the formatting toolbar stays accessible.
| The lower edge can still be dragged vertically when more room is useful.
|
*/

    .forum-rich-editor {
        display: flex;
        flex-direction: column;
        min-width: 0;
    }

    .forum-rich-editor-toolbar {
        position: relative;
        z-index: 3;
        flex: 0 0 auto;
    }

    .forum-rich-editor-surface {
        box-sizing: border-box;
        width: 100%;
        height: 420px;
        min-height: 260px;
        max-height: 78vh;
        overflow-x: auto;
        overflow-y: auto;
        resize: vertical;
        overscroll-behavior: contain;
        scrollbar-gutter: stable;
    }

    .forum-rich-editor-surface:focus {
        overflow-y: auto;
    }

    @media (max-width: 720px) {
        .forum-rich-editor-surface {
            height: 340px;
            min-height: 220px;
            max-height: 70vh;
        }
    }

</style>


<?php

require INCLUDES_PATH . '/footer.php';
