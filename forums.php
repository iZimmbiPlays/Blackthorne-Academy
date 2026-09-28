<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/forum-functions.php';

require_active_account();


/*
|--------------------------------------------------------------------------
| Blackthorne Academy
| Forum Index
|--------------------------------------------------------------------------
|
| Main forum landing page.
|
| Displays only categories, boards, sub-boards, threads, posts, moderators,
| and last-post information the current member is allowed to see.
|
*/

$userId =
    (int) current_user_id();


$houseBoardDisplayColors = [];

try {
    $houseColorStatement = $pdo->query(
        'SELECT name, display_name, display_color
         FROM houses
         WHERE is_active = 1'
    );

    foreach ($houseColorStatement->fetchAll(PDO::FETCH_ASSOC) as $houseColorRow) {
        $displayColor = safe_css_color((string) ($houseColorRow['display_color'] ?? ''));

        if ($displayColor === null) {
            continue;
        }

        foreach (['name', 'display_name'] as $houseNameField) {
            $houseName = trim((string) ($houseColorRow[$houseNameField] ?? ''));

            if ($houseName === '') {
                continue;
            }

            $houseNameKey = function_exists('mb_strtolower')
                ? mb_strtolower($houseName, 'UTF-8')
                : strtolower($houseName);

            $houseBoardDisplayColors[$houseNameKey] = $displayColor;
        }
    }
} catch (PDOException) {
    $houseBoardDisplayColors = [];
}


/*
|--------------------------------------------------------------------------
| Local Presentation Helpers
|--------------------------------------------------------------------------
*/

function forum_index_avatar_url(?string $avatar): ?string
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


function forum_index_initials(string $displayName): string
{
    $displayName =
        trim(
            $displayName
        );

    if ($displayName === '') {
        return '?';
    }

    $parts =
        preg_split(
            '/\s+/u',
            $displayName
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


function forum_index_datetime(?string $dateTime): string
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
                    'UTC'
                )
            );

        return $date
            ->setTimezone(
                new DateTimeZone(
                    'America/New_York'
                )
            )
            ->format(
                'M j, Y \a\t g:i A'
            );

    } catch (Throwable) {
        return $dateTime;
    }
}


/*
|--------------------------------------------------------------------------
| Categories
|--------------------------------------------------------------------------
*/

$categoriesStatement =
    $pdo->query(
        '
        SELECT
            id,
            title,
            slug,
            description,
            sort_order,
            access_mode,
            is_visible

        FROM forum_categories

        WHERE is_visible = 1

        ORDER BY
            sort_order ASC,
            title ASC
        '
    );

$allCategories =
    $categoriesStatement->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Forums
|--------------------------------------------------------------------------
*/

$forumsStatement =
    $pdo->query(
        '
        SELECT
            id,
            category_id,
            parent_forum_id,
            title,
            slug,
            description,
            forum_type,
            access_mode,
            sort_order,
            is_locked,
            is_visible

        FROM forums

        WHERE is_visible = 1

        ORDER BY
            category_id ASC,
            parent_forum_id ASC,
            sort_order ASC,
            title ASC
        '
    );

$allForums =
    $forumsStatement->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Build Accessible Forum Structure
|--------------------------------------------------------------------------
*/

$forumsByCategory = [];
$subforumsByParent = [];
$visibleForumIds = [];


foreach ($allForums as $forum) {

    $forumId =
        (int) $forum['id'];

    if (
        !forum_can_view_forum(
            $pdo,
            $forumId,
            $userId
        )
    ) {
        continue;
    }

    $visibleForumIds[$forumId] =
        true;

    if (
        $forum['parent_forum_id']
        !== null
    ) {
        $parentId =
            (int) $forum[
                'parent_forum_id'
            ];

        $subforumsByParent[
            $parentId
        ][] =
            $forum;

        continue;
    }

    $categoryId =
        (int) $forum[
            'category_id'
        ];

    $forumsByCategory[
        $categoryId
    ][] =
        $forum;
}


/*
|--------------------------------------------------------------------------
| Forum Row Statistics
|--------------------------------------------------------------------------
|
| Counts and last-post information are scoped to threads the current user
| may see. Staff-only threads are included only for their author or someone
| with can_view_all_threads.
|
*/

$forumStatistics = [];
$forumModerators = [];


foreach (
    array_keys(
        $visibleForumIds
    )
    as $forumId
) {

    $permissions =
        forum_get_user_forum_permissions(
            $pdo,
            $forumId,
            $userId
        );

    $canViewAllThreads =
        $permissions[
            'can_view_all_threads'
        ];

    $threadVisibilitySql =
        $canViewAllThreads
            ? ''
            : '
                AND (
                    ft.reply_visibility = "public"
                    OR ft.user_id = :viewer_user_id
                )
            ';


    /*
     * Thread count.
     */

    $threadCountStatement =
        $pdo->prepare(
            '
            SELECT COUNT(*)

            FROM forum_threads ft

            WHERE ft.forum_id = :forum_id

              AND ft.is_deleted = 0
            '
            . $threadVisibilitySql
        );

    $threadParameters = [
        'forum_id' => $forumId,
    ];

    if (!$canViewAllThreads) {
        $threadParameters[
            'viewer_user_id'
        ] =
            $userId;
    }

    $threadCountStatement->execute(
        $threadParameters
    );

    $threadCount =
        (int) $threadCountStatement
            ->fetchColumn();


    /*
     * Post count.
     */

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
            . $threadVisibilitySql
        );

    $postCountStatement->execute(
        $threadParameters
    );

    $postCount =
        (int) $postCountStatement
            ->fetchColumn();


    /*
     * Last visible post.
     */

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
                u.avatar

            FROM forum_posts fp

            INNER JOIN forum_threads ft
                ON ft.id = fp.thread_id

            INNER JOIN users u
                ON u.id = fp.user_id

            WHERE ft.forum_id = :forum_id

              AND ft.is_deleted = 0

              AND fp.is_deleted = 0
            '
            . $threadVisibilitySql
            . '
            ORDER BY
                fp.created_at DESC,
                fp.id DESC

            LIMIT 1
            '
        );

    $lastPostStatement->execute(
        $threadParameters
    );

    $lastPost =
        $lastPostStatement->fetch(
            PDO::FETCH_ASSOC
        );

    $forumStatistics[$forumId] = [
        'thread_count' => $threadCount,
        'post_count'   => $postCount,
        'last_post'    => $lastPost ?: null,
    ];


    /*
     * Active assigned moderators.
     */

    $forumModerators[$forumId] =
        forum_fetch_active_moderators(
            $pdo,
            $forumId
        );
}


/*
|--------------------------------------------------------------------------
| Accessible Categories
|--------------------------------------------------------------------------
*/

$visibleCategories = [];


foreach ($allCategories as $category) {

    $categoryId =
        (int) $category['id'];

    if (
        !forum_can_view_category(
            $pdo,
            $categoryId,
            $userId
        )
    ) {
        continue;
    }

    $categoryForums =
        $forumsByCategory[
            $categoryId
        ]
        ?? [];

    /*
     * Keep empty categories available to authorized users, matching the
     * Academy's category structure. Empty categories receive an empty-state
     * message instead of disappearing.
     */

    $category['forums'] =
        $categoryForums;

    $visibleCategories[] =
        $category;
}


/*
|--------------------------------------------------------------------------
| Page Metadata
|--------------------------------------------------------------------------
*/

$pageTitle =
    'Forums | Blackthorne Academy';

$pageDescription =
    'Browse Blackthorne Academy forum categories, boards, discussions, and community activity.';

$pageCanonical =
    url(
        'forums.php'
    );

$robots =
    'noindex, nofollow';

require INCLUDES_PATH . '/header.php';

?>

<main
    id="main-content"
    class="forum-index-page"
>


    <!-- ================================================================
         FORUM HEADER
    ================================================================= -->

    <section
        class="forum-index-header"
        aria-labelledby="forum-index-heading"
    >

        <div class="section-inner">

            <p class="academy-overline">
                Blackthorne Community
            </p>

            <h1 id="forum-index-heading">
                Academy Forums
            </h1>

            <p class="forum-index-intro">
                Enter the halls, find your board, and join the conversation.
            </p>

        </div>

    </section>


    <!-- ================================================================
         CATEGORY + BOARD INDEX
    ================================================================= -->

    <section class="forum-index-content">

        <div class="section-inner member-home-layout forum-index-layout">

            <?php require INCLUDES_PATH . '/member-sidebar.php'; ?>

            <div class="member-home-main forum-index-main">


            <?php if ($visibleCategories === []): ?>

                <div class="forum-index-empty">

                    <h2>
                        No forums are available.
                    </h2>

                    <p>
                        There are currently no forum categories available to
                        your account.
                    </p>

                </div>


            <?php else: ?>

                <div class="forum-index-categories">


                    <?php foreach (
                        $visibleCategories
                        as $category
                    ): ?>

                        <?php

                        $categoryId =
                            (int) $category['id'];

                        $categoryForums =
                            is_array(
                                $category['forums']
                                ?? null
                            )
                                ? $category['forums']
                                : [];

                        ?>


                        <section
                            class="forum-index-category"
                            aria-labelledby="forum-category-<?= $categoryId; ?>"
                        >


                            <header class="forum-index-category-titlebar">

                                <h2
                                    id="forum-category-<?= $categoryId; ?>"
                                >
                                    <?= e(
                                        (string) $category[
                                            'title'
                                        ]
                                    ); ?>
                                </h2>

                            </header>


                            <?php if ($categoryForums === []): ?>

                                <div class="forum-index-category-empty">
                                    No boards have been added to this category yet.
                                </div>


                            <?php else: ?>

                                <div
                                    class="forum-index-board-list"
                                    role="list"
                                >


                                    <?php foreach (
                                        $categoryForums
                                        as $forum
                                    ): ?>

                                        <?php

                                        $forumId =
                                            (int) $forum['id'];

                                        $forumTitleKey = trim((string) $forum['title']);
                                        $forumTitleKey = function_exists('mb_strtolower')
                                            ? mb_strtolower($forumTitleKey, 'UTF-8')
                                            : strtolower($forumTitleKey);
                                        $forumHouseColor = $houseBoardDisplayColors[$forumTitleKey] ?? null;

                                        $stats =
                                            $forumStatistics[
                                                $forumId
                                            ]
                                            ?? [
                                                'thread_count' => 0,
                                                'post_count' => 0,
                                                'last_post' => null,
                                            ];

                                        $subforums =
                                            array_values(
                                                array_filter(
                                                    $subforumsByParent[
                                                        $forumId
                                                    ]
                                                    ?? [],
                                                    static fn (
                                                        array $subforum
                                                    ): bool =>
                                                        isset(
                                                            $visibleForumIds[
                                                                (int) $subforum[
                                                                    'id'
                                                                ]
                                                            ]
                                                        )
                                                )
                                            );

                                        $moderators =
                                            $forumModerators[
                                                $forumId
                                            ]
                                            ?? [];

                                        $lastPost =
                                            is_array(
                                                $stats[
                                                    'last_post'
                                                ]
                                            )
                                                ? $stats[
                                                    'last_post'
                                                ]
                                                : null;

                                        ?>


                                        <article
                                            class="forum-index-board"
                                            role="listitem"
                                        >

                                            <div class="forum-index-board-top">

                                                <div class="forum-index-board-copy">

                                                    <h3>

                                                        <a
                                                            href="<?= e(
                                                                url(
                                                                    'forum.php?f=' .
                                                                    $forumId
                                                                )
                                                            ); ?>"
                                                            <?= $forumHouseColor !== null ? 'style="color:' . e($forumHouseColor) . ';"' : ''; ?>
                                                        >
                                                            <?= e(
                                                                (string) $forum[
                                                                    'title'
                                                                ]
                                                            ); ?>
                                                        </a>

                                                    </h3>


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

                                                        <p class="forum-index-board-description">
                                                            <?= e(
                                                                (string) $forum[
                                                                    'description'
                                                                ]
                                                            ); ?>
                                                        </p>

                                                    <?php endif; ?>

                                                </div>


                                                <div
                                                    class="forum-index-board-stat forum-index-thread-stat"
                                                    aria-label="<?= number_format(
                                                        (int) $stats[
                                                            'thread_count'
                                                        ]
                                                    ); ?> threads"
                                                >

                                                    <strong>
                                                        <?= number_format(
                                                            (int) $stats[
                                                                'thread_count'
                                                            ]
                                                        ); ?>
                                                    </strong>

                                                    <span>
                                                        <?= (int) $stats[
                                                            'thread_count'
                                                        ] === 1
                                                            ? 'Thread'
                                                            : 'Threads'; ?>
                                                    </span>

                                                </div>


                                                <div
                                                    class="forum-index-board-stat forum-index-post-stat"
                                                    aria-label="<?= number_format(
                                                        (int) $stats[
                                                            'post_count'
                                                        ]
                                                    ); ?> posts"
                                                >

                                                    <strong>
                                                        <?= number_format(
                                                            (int) $stats[
                                                                'post_count'
                                                            ]
                                                        ); ?>
                                                    </strong>

                                                    <span>
                                                        <?= (int) $stats[
                                                            'post_count'
                                                        ] === 1
                                                            ? 'Post'
                                                            : 'Posts'; ?>
                                                    </span>

                                                </div>


                                                <div class="forum-index-last-post">

                                                    <?php if (
                                                        $lastPost === null
                                                    ): ?>

                                                        <p class="forum-index-no-posts">
                                                            No posts have been made on this board yet.
                                                        </p>

                                                    <?php else: ?>

                                                        <?php

                                                        $lastPosterName =
                                                            trim(
                                                                (string) (
                                                                    $lastPost[
                                                                        'display_name'
                                                                    ]
                                                                    ?? $lastPost[
                                                                        'username'
                                                                    ]
                                                                    ?? 'Member'
                                                                )
                                                            );

                                                        if (
                                                            $lastPosterName
                                                            === ''
                                                        ) {
                                                            $lastPosterName =
                                                                'Member';
                                                        }

                                                        $avatarUrl =
                                                            forum_index_avatar_url(
                                                                isset(
                                                                    $lastPost[
                                                                        'avatar'
                                                                    ]
                                                                )
                                                                    ? (string) $lastPost[
                                                                        'avatar'
                                                                    ]
                                                                    : null
                                                            );

                                                        ?>

                                                        <div class="forum-index-last-post-inner">

                                                            <a
                                                                class="forum-index-last-post-avatar"
                                                                href="<?= e(
                                                                    url(
                                                                        'profile.php?u=' .
                                                                        (int) $lastPost[
                                                                            'user_id'
                                                                        ]
                                                                    )
                                                                ); ?>"
                                                                aria-label="View <?= e(
                                                                    $lastPosterName
                                                                ); ?>'s profile"
                                                            >

                                                                <?php if (
                                                                    $avatarUrl
                                                                    !== null
                                                                ): ?>

                                                                    <img
                                                                        src="<?= e(
                                                                            $avatarUrl
                                                                        ); ?>"
                                                                        alt=""
                                                                        loading="lazy"
                                                                    >

                                                                <?php else: ?>

                                                                    <span aria-hidden="true">
                                                                        <?= e(
                                                                            forum_index_initials(
                                                                                $lastPosterName
                                                                            )
                                                                        ); ?>
                                                                    </span>

                                                                <?php endif; ?>

                                                            </a>


                                                            <div class="forum-index-last-post-copy">

                                                                <a
                                                                    class="forum-index-last-thread"
                                                                    href="<?= e(
                                                                        url(
                                                                            'thread.php?t=' .
                                                                            (int) $lastPost[
                                                                                'thread_id'
                                                                            ]
                                                                        )
                                                                    ); ?>"
                                                                >
                                                                    <?= e(
                                                                        (string) $lastPost[
                                                                            'thread_title'
                                                                        ]
                                                                    ); ?>
                                                                </a>

                                                                <p>

                                                                    by

                                                                    <a
                                                                        class="forum-index-last-user"
                                                                        href="<?= e(
                                                                            url(
                                                                                'profile.php?u=' .
                                                                                (int) $lastPost[
                                                                                    'user_id'
                                                                                ]
                                                                            )
                                                                        ); ?>"
                                                                        <?= user_display_name_style_attr((int) $lastPost['user_id']); ?>
                                                                    >
                                                                        <?= e(
                                                                            $lastPosterName
                                                                        ); ?>
                                                                    </a>

                                                                </p>

                                                                <time
                                                                    datetime="<?= e(
                                                                        (string) $lastPost[
                                                                            'post_created_at'
                                                                        ]
                                                                    ); ?>"
                                                                >
                                                                    <?= e(
                                                                        forum_index_datetime(
                                                                            (string) $lastPost[
                                                                                'post_created_at'
                                                                            ]
                                                                        )
                                                                    ); ?>
                                                                </time>

                                                            </div>

                                                        </div>

                                                    <?php endif; ?>

                                                </div>

                                            </div>


                                            <?php if (
                                                $subforums !== []
                                                || $moderators !== []
                                            ): ?>

                                                <div class="forum-index-board-meta-strip">

                                                    <?php if (
                                                        $subforums !== []
                                                    ): ?>

                                                        <div class="forum-index-subboards">

                                                            <span class="forum-index-subboards-label">
                                                                Sub-board<?= count($subforums) === 1 ? ':' : 's:'; ?>
                                                            </span>

                                                            <div class="forum-index-subboard-links">

                                                                <?php foreach (
                                                                    $subforums
                                                                    as $subforum
                                                                ): ?>

                                                                    <?php
                                                                    $subforumTitleKey = trim((string) $subforum['title']);
                                                                    $subforumTitleKey = function_exists('mb_strtolower')
                                                                        ? mb_strtolower($subforumTitleKey, 'UTF-8')
                                                                        : strtolower($subforumTitleKey);
                                                                    $subforumHouseColor = $houseBoardDisplayColors[$subforumTitleKey] ?? null;
                                                                    ?>

                                                                    <a
                                                                        href="<?= e(
                                                                            url(
                                                                                'forum.php?f=' .
                                                                                (int) $subforum[
                                                                                    'id'
                                                                                ]
                                                                            )
                                                                        ); ?>"
                                                                        <?= $subforumHouseColor !== null ? 'style="color:' . e($subforumHouseColor) . ';"' : ''; ?>
                                                                    >
                                                                        <?= e(
                                                                            (string) $subforum[
                                                                                'title'
                                                                            ]
                                                                        ); ?>
                                                                    </a>

                                                                <?php endforeach; ?>

                                                            </div>

                                                        </div>

                                                    <?php endif; ?>


                                                    <?php if (
                                                        $moderators !== []
                                                    ): ?>

                                                        <div class="forum-index-moderators">

                                                            <span class="forum-index-moderators-label">
                                                                <?= count(
                                                                    $moderators
                                                                ) === 1
                                                                    ? 'Moderator:'
                                                                    : 'Moderators:'; ?>
                                                            </span>

                                                            <span class="forum-index-moderator-links">

                                                                <?php foreach (
                                                                    $moderators
                                                                    as $index => $moderator
                                                                ): ?>

                                                                    <?php if (
                                                                        $index > 0
                                                                    ): ?>,
                                                                    <?php endif; ?>

                                                                    <a
                                                                        href="<?= e(
                                                                            url(
                                                                                'profile.php?u=' .
                                                                                (int) $moderator[
                                                                                    'id'
                                                                                ]
                                                                            )
                                                                        ); ?>"
                                                                        <?= user_display_name_style_attr((int) $moderator['id']); ?>
                                                                    >
                                                                        <?= e(
                                                                            (string) $moderator[
                                                                                'display_name'
                                                                            ]
                                                                        ); ?>
                                                                    </a>

                                                                <?php endforeach; ?>

                                                            </span>

                                                        </div>

                                                    <?php endif; ?>

                                                </div>

                                            <?php endif; ?>

                                        </article>


                                    <?php endforeach; ?>


                                </div>

                            <?php endif; ?>

                        </section>


                    <?php endforeach; ?>


                </div>

            <?php endif; ?>

            </div>


        </div>

    </section>

</main>

<?php

require INCLUDES_PATH . '/footer.php';
