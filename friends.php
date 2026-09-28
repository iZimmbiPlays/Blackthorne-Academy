<?php

declare(strict_types=1);

/**
 * Blackthorne Academy
 * Full Friends Listing
 *
 * Upload path:
 * /friends.php
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/profile-functions.php';
require_once INCLUDES_PATH . '/friend-functions.php';


/*
|--------------------------------------------------------------------------
| Target Member
|--------------------------------------------------------------------------
*/

$targetUserReference =
    trim(
        (string) (
            $_GET['u']
            ?? ''
        )
    );

if (
    strtolower(
        $targetUserReference
    ) === 'me'
) {
    $currentProfileUserId =
        (int) (
            current_user_id()
            ?? 0
        );

    if ($currentProfileUserId > 0) {
        $targetUserReference =
            (string) $currentProfileUserId;
    }
}

if (
    $targetUserReference === ''
    || !ctype_digit(
        $targetUserReference
    )
    || (int) $targetUserReference <= 0
) {
    http_response_code(404);

    $pageTitle =
        'Friends Not Found | Blackthorne Academy';

    $pageDescription =
        'The requested Blackthorne Academy friends list could not be found.';

    $robots =
        'noindex, nofollow';

    require
        INCLUDES_PATH
        . '/header.php';

    ?>
    <main
        id="main-content"
        class="friends-page"
    >
        <section class="friends-page-error">
            <div class="section-inner">

                <p class="academy-overline">
                    Community
                </p>

                <h1>
                    Friends Not Found
                </h1>

                <p>
                    The requested member friends list could not be found.
                </p>

                <a
                    class="button button-secondary"
                    href="<?= e(url('index.php')); ?>"
                >
                    Return to Blackthorne
                </a>

            </div>
        </section>
    </main>
    <?php

    require
        INCLUDES_PATH
        . '/footer.php';

    exit;
}


$targetUserId =
    (int) $targetUserReference;


/*
|--------------------------------------------------------------------------
| Viewer
|--------------------------------------------------------------------------
*/

$viewerUserId =
    (int) (
        current_user_id()
        ?? 0
    );

$isOwnProfile =
    $viewerUserId > 0
    && $viewerUserId === $targetUserId;

$viewerIsStaff =
    false;

if ($viewerUserId > 0) {
    if (
        function_exists(
            'current_user_is_superuser'
        )
        && current_user_is_superuser()
    ) {
        $viewerIsStaff =
            true;

    } elseif (
        function_exists(
            'user_can_any'
        )
        && user_can_any(
            [
                'staff.dashboard.access',
                'members.admin.view',
                'members.profiles.manage',
            ]
        )
    ) {
        $viewerIsStaff =
            true;

    } elseif (
        function_exists(
            'user_can'
        )
        && (
            user_can(
                'staff.dashboard.access'
            )
            || user_can(
                'members.admin.view'
            )
            || user_can(
                'members.profiles.manage'
            )
        )
    ) {
        $viewerIsStaff =
            true;
    }
}


/*
|--------------------------------------------------------------------------
| Member / Profile
|--------------------------------------------------------------------------
*/

$memberStatement =
    $pdo->prepare(
        'SELECT
            u.id,
            u.username,
            u.display_name,
            u.avatar,
            u.status,
            up.profile_visibility

         FROM users u

         LEFT JOIN user_profiles up
            ON up.user_id = u.id

         WHERE u.id = :user_id

         LIMIT 1'
    );

$memberStatement->execute([
    'user_id' =>
        $targetUserId,
]);

$member =
    $memberStatement->fetch(
        PDO::FETCH_ASSOC
    );


if (
    !is_array(
        $member
    )
    || (string) (
        $member['status']
        ?? ''
    ) !== 'active'
) {
    http_response_code(404);

    $pageTitle =
        'Friends Not Found | Blackthorne Academy';

    $pageDescription =
        'The requested Blackthorne Academy friends list could not be found.';

    $robots =
        'noindex, nofollow';

    require
        INCLUDES_PATH
        . '/header.php';

    ?>
    <main
        id="main-content"
        class="friends-page"
    >
        <section class="friends-page-error">
            <div class="section-inner">

                <p class="academy-overline">
                    Community
                </p>

                <h1>
                    Friends Not Found
                </h1>

                <p>
                    The requested member friends list could not be found.
                </p>

                <a
                    class="button button-secondary"
                    href="<?= e(url('index.php')); ?>"
                >
                    Return to Blackthorne
                </a>

            </div>
        </section>
    </main>
    <?php

    require
        INCLUDES_PATH
        . '/footer.php';

    exit;
}


/*
|--------------------------------------------------------------------------
| Visibility
|--------------------------------------------------------------------------
|
| Match the member-profile visibility rules so a Friends page never exposes
| a profile that the viewer could not otherwise open.
|
*/

$profileVisibility =
    (string) (
        $member['profile_visibility']
        ?? 'members'
    );

$canViewProfile =
    $isOwnProfile
    || $viewerIsStaff
    || $profileVisibility === 'everyone'
    || (
        $profileVisibility === 'members'
        && $viewerUserId > 0
    );


$displayName =
    trim(
        (string) (
            $member['display_name']
            ?? $member['username']
            ?? 'Member'
        )
    );

if ($displayName === '') {
    $displayName =
        'Member';
}


if (!$canViewProfile) {
    http_response_code(403);

    $pageTitle =
        'Friends Restricted | Blackthorne Academy';

    $pageDescription =
        'This Blackthorne Academy member has limited who may view their profile and friends list.';

    $robots =
        'noindex, nofollow';

    require
        INCLUDES_PATH
        . '/header.php';

    ?>
    <main
        id="main-content"
        class="friends-page"
    >
        <section class="friends-page-error">
            <div class="section-inner">

                <p class="academy-overline">
                    Community
                </p>

                <h1>
                    Friends Restricted
                </h1>

                <p>
                    <?= e($displayName); ?> has limited who may view this profile.
                </p>

                <?php if ($viewerUserId <= 0): ?>

                    <a
                        class="button"
                        href="<?= e(LOGIN_URL); ?>"
                    >
                        Log In
                    </a>

                <?php else: ?>

                    <a
                        class="button button-secondary"
                        href="<?= e(url('index.php')); ?>"
                    >
                        Return to Blackthorne
                    </a>

                <?php endif; ?>

            </div>
        </section>
    </main>
    <?php

    require
        INCLUDES_PATH
        . '/footer.php';

    exit;
}


/*
|--------------------------------------------------------------------------
| Friends
|--------------------------------------------------------------------------
*/

$friends =
    blackthorne_friend_list(
        $pdo,
        $targetUserId
    );

$friendCount =
    count(
        $friends
    );


/*
|--------------------------------------------------------------------------
| Presence
|--------------------------------------------------------------------------
*/

$presenceByUserId =
    [];


if ($friends !== []) {
    $friendUserIds =
        array_values(
            array_filter(
                array_map(
                    static fn (
                        array $friend
                    ): int =>
                        (int) (
                            $friend['id']
                            ?? 0
                        ),
                    $friends
                ),
                static fn (
                    int $friendUserId
                ): bool =>
                    $friendUserId > 0
            )
        );

    if ($friendUserIds !== []) {
        $presencePlaceholders =
            implode(
                ', ',
                array_fill(
                    0,
                    count(
                        $friendUserIds
                    ),
                    '?'
                )
            );

        $presenceStatement =
            $pdo->prepare(
                'SELECT
                    u.id AS user_id,

                    COALESCE(
                        up.show_online_status,
                        1
                    ) AS show_online_status,

                    CASE
                        WHEN
                            upr.last_seen_at IS NOT NULL
                            AND upr.last_seen_at >= DATE_SUB(
                                CURRENT_TIMESTAMP,
                                INTERVAL 5 MINUTE
                            )
                        THEN 1
                        ELSE 0
                    END AS is_online

                 FROM users u

                 LEFT JOIN user_profiles up
                    ON up.user_id = u.id

                 LEFT JOIN user_presence upr
                    ON upr.user_id = u.id

                 WHERE u.id IN ('
                    . $presencePlaceholders
                    . ')'
            );

        $presenceStatement->execute(
            $friendUserIds
        );

        foreach (
            $presenceStatement->fetchAll(
                PDO::FETCH_ASSOC
            ) as $presenceRow
        ) {
            $presenceByUserId[
                (int) $presenceRow['user_id']
            ] = [
                'show_online_status' =>
                    (int) (
                        $presenceRow[
                            'show_online_status'
                        ]
                        ?? 1
                    ) === 1,

                'is_online' =>
                    (int) (
                        $presenceRow[
                            'is_online'
                        ]
                        ?? 0
                    ) === 1,
            ];
        }
    }
}


/*
|--------------------------------------------------------------------------
| Display Values
|--------------------------------------------------------------------------
*/

$profileUrl =
    url(
        'profile.php?u='
        . $targetUserId
    );

foreach (
    $friends as $friendIndex =>
    $friend
) {
    $friendUserId =
        (int) (
            $friend['id']
            ?? 0
        );

    $friendName =
        trim(
            (string) (
                $friend['display_name']
                ?? $friend['username']
                ?? 'Member'
            )
        );

    if ($friendName === '') {
        $friendName =
            'Member';
    }

    $friends[
        $friendIndex
    ]['display_name_resolved'] =
        $friendName;

    $friends[
        $friendIndex
    ]['avatar_sources'] =
        profile_picture_sources(
            isset(
                $friend['avatar']
            )
                ? (string) $friend['avatar']
                : null
        );

    $friends[
        $friendIndex
    ]['profile_url'] =
        url(
            'profile.php?u='
            . $friendUserId
        );

    $friends[
        $friendIndex
    ]['show_online_status'] =
        (bool) (
            $presenceByUserId[
                $friendUserId
            ]['show_online_status']
            ?? true
        );

    $friends[
        $friendIndex
    ]['is_online'] =
        (bool) (
            $presenceByUserId[
                $friendUserId
            ]['is_online']
            ?? false
        );
}


/*
|--------------------------------------------------------------------------
| Metadata
|--------------------------------------------------------------------------
*/

$pageTitle =
    $displayName
    . '\'s Friends | Blackthorne Academy';

$pageDescription =
    'View '
    . $displayName
    . '\'s friends at Blackthorne Academy.';

$robots =
    'noindex, nofollow';


require
    INCLUDES_PATH
    . '/header.php';

?>


<main
    id="main-content"
    class="friends-page"
>

    <section class="friends-page-hero">

        <div class="section-inner">

            <div class="friends-page-hero-content">

                <div>

                    <p class="academy-overline">
                        Community
                    </p>

                    <h1<?= user_display_name_style_attr($targetUserId); ?>>
                        <?= e($displayName); ?>'s Friends
                    </h1>

                    <p class="friends-page-summary">
                        <?= number_format($friendCount); ?>
                        <?= $friendCount === 1 ? 'friend' : 'friends'; ?>
                    </p>

                </div>


                <a
                    class="button button-secondary"
                    href="<?= e($profileUrl); ?>"
                >
                    Back to Profile
                </a>

            </div>

        </div>

    </section>


    <section class="friends-page-content">

        <div class="section-inner">

            <?php if ($friends !== []): ?>

                <div class="friends-page-grid">

                    <?php foreach ($friends as $friend): ?>

                        <?php
                        $friendName =
                            (string) (
                                $friend[
                                    'display_name_resolved'
                                ]
                                ?? 'Member'
                            );

                        $avatarSources =
                            is_array(
                                $friend['avatar_sources']
                                ?? null
                            )
                                ? $friend['avatar_sources']
                                : [
                                    'original' => null,
                                    'webp' => null,
                                ];

                        $showOnlineStatus =
                            (bool) (
                                $friend[
                                    'show_online_status'
                                ]
                                ?? true
                            );

                        $isOnline =
                            (bool) (
                                $friend[
                                    'is_online'
                                ]
                                ?? false
                            );
                        ?>

                        <a
                            class="friends-page-card"
                            href="<?= e((string) $friend['profile_url']); ?>"
                            aria-label="View <?= e($friendName); ?>'s profile"
                        >

                            <span class="friends-page-avatar">

                                <?php if (
                                    $avatarSources['original']
                                    !== null
                                ): ?>

                                    <picture>

                                        <?php if (
                                            $avatarSources['webp']
                                            !== null
                                            && $avatarSources['webp']
                                                !== $avatarSources['original']
                                        ): ?>

                                            <source
                                                srcset="<?= e($avatarSources['webp']); ?>"
                                                type="image/webp"
                                            >

                                        <?php endif; ?>

                                        <img
                                            src="<?= e($avatarSources['original']); ?>"
                                            alt=""
                                            loading="lazy"
                                            decoding="async"
                                        >

                                    </picture>

                                <?php else: ?>

                                    <span
                                        class="friends-page-avatar-fallback"
                                        aria-hidden="true"
                                    >
                                        <?= e(
                                            profile_avatar_initial(
                                                $friendName
                                            )
                                        ); ?>
                                    </span>

                                <?php endif; ?>


                                <?php if ($showOnlineStatus): ?>

                                    <span
                                        class="friends-page-presence<?= $isOnline ? ' is-online' : ''; ?>"
                                        title="<?= $isOnline ? 'Online' : 'Offline'; ?>"
                                        aria-label="<?= $isOnline ? 'Online' : 'Offline'; ?>"
                                    ></span>

                                <?php endif; ?>

                            </span>


                            <span class="friends-page-card-body">

                                <strong
                                    class="friends-page-name"
                                    <?= user_display_name_style_attr((int) ($friend['id'] ?? 0)); ?>
                                >
                                    <?= e($friendName); ?>
                                </strong>

                                <?php if ($showOnlineStatus): ?>

                                    <span class="friends-page-status">
                                        <?= $isOnline ? 'Online' : 'Offline'; ?>
                                    </span>

                                <?php endif; ?>

                            </span>

                        </a>

                    <?php endforeach; ?>

                </div>

            <?php else: ?>

                <div class="friends-page-empty">

                    <p class="academy-overline">
                        Community
                    </p>

                    <h2>
                        No Friends Yet
                    </h2>

                    <p>
                        <?= e($displayName); ?> has not added any friends yet.
                    </p>

                    <a
                        class="button button-secondary"
                        href="<?= e($profileUrl); ?>"
                    >
                        Back to Profile
                    </a>

                </div>

            <?php endif; ?>

        </div>

    </section>

</main>


<?php

require
    INCLUDES_PATH
    . '/footer.php';

