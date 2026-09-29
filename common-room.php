<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/house-functions.php';
require_once INCLUDES_PATH . '/forum-functions.php';

require_login();
require_active_account();

$userId =
    (int) (current_user_id() ?? 0);

$membership =
    current_user_house_membership();

if ($membership === null) {
    http_response_code(403);

    $pageTitle =
        'Common Room Access Restricted | Blackthorne Academy';

    $pageDescription =
        'A House assignment is required to enter a Blackthorne Academy Common Room.';

    $robots =
        'noindex, nofollow';

    require INCLUDES_PATH . '/header.php';
    ?>
<main id="main-content" class="house-common-room-page">
    <section class="house-common-room-denied">
        <div class="section-inner">
            <p class="academy-overline">
                The Common Rooms
            </p>

            <h1>
                You have not been sorted yet.
            </h1>

            <p>
                Common Rooms are private to members of their assigned House.
                Complete the Sorting Ceremony before entering.
            </p>

            <div class="forum-admin-edit-actions">
                <a href="<?= e(url('sorting-ceremony.php')); ?>" class="button button-primary">
                    Begin the Sorting Ceremony
                </a>

                <a href="<?= e(HOME_URL); ?>" class="button button-secondary">
                    Return Home
                </a>
            </div>
        </div>
    </section>
</main>
<?php
    require INCLUDES_PATH . '/footer.php';
    exit;
}


/*
|--------------------------------------------------------------------------
| House Identity / Theme
|--------------------------------------------------------------------------
*/

$houseId =
    (int) $membership['house_id'];

$houseName =
    function_exists('house_visible_name')
        ? house_visible_name($membership)
        : trim(
            (string) (
                $membership['display_name']
                ?? $membership['name']
                ?? 'House'
            )
        );

if ($houseName === '') {
    $houseName =
        'House';
}

$houseDescription =
    trim(
        (string) (
            $membership['description']
            ?? ''
        )
    );

$houseMotto =
    trim(
        (string) (
            $membership['motto']
            ?? ''
        )
    );

$houseIntroduction =
    trim(
        (string) (
            $membership['house_introduction']
            ?? ''
        )
    );

$displayColor =
    safe_css_color(
        (string) (
            $membership['display_color']
            ?? ''
        )
    )
    ?? '#C8BEC5';

$primaryColor =
    safe_css_color(
        (string) (
            $membership['primary_color']
            ?? ''
        )
    )
    ?? '#5B355F';

$secondaryColor =
    safe_css_color(
        (string) (
            $membership['secondary_color']
            ?? ''
        )
    )
    ?? '#2B182F';

$accentColor =
    safe_css_color(
        (string) (
            $membership['accent_color']
            ?? ''
        )
    )
    ?? '#C7A55B';

$darkColor =
    safe_css_color(
        (string) (
            $membership['dark_neutral_color']
            ?? ''
        )
    )
    ?? '#100B13';

$highlightColor =
    safe_css_color(
        (string) (
            $membership['highlight_color']
            ?? ''
        )
    )
    ?? '#E3D2AE';

$commonRoomForumId =
    isset($membership['common_room_forum_id'])
    && $membership['common_room_forum_id'] !== null
        ? (int) $membership['common_room_forum_id']
        : 0;

$announcementForumId =
    isset($membership['announcement_forum_id'])
    && $membership['announcement_forum_id'] !== null
        ? (int) $membership['announcement_forum_id']
        : 0;

$canAccessCommonRoomForum =
    $commonRoomForumId > 0
    && forum_can_access_forum(
        $pdo,
        $commonRoomForumId,
        $userId
    );

$crestReference =
    house_safe_crest_reference(
        isset($membership['crest_image'])
            ? (string) $membership['crest_image']
            : null
    );

$crestWebpReference =
    house_crest_webp_reference(
        $crestReference
    );

$crestUrl =
    $crestReference !== null
        ? url(
            ltrim(
                $crestReference,
                '/'
            )
        )
        : null;

$crestWebpUrl =
    $crestWebpReference !== null
        ? url(
            ltrim(
                $crestWebpReference,
                '/'
            )
        )
        : null;

$heroReference =
    house_safe_hero_reference(
        isset($membership['hero_image'])
            ? (string) $membership['hero_image']
            : null
    );

$heroWebpReference =
    house_hero_webp_reference(
        $heroReference
    );

$heroUrl =
    $heroReference !== null
        ? url(
            ltrim(
                $heroReference,
                '/'
            )
        )
        : null;

$heroWebpUrl =
    $heroWebpReference !== null
        ? url(
            ltrim(
                $heroWebpReference,
                '/'
            )
        )
        : null;

$mascotReference =
    house_safe_mascot_reference(
        isset($membership['mascot_image'])
            ? (string) $membership['mascot_image']
            : null
    );

$mascotWebpReference =
    house_mascot_webp_reference(
        $mascotReference
    );

$mascotUrl =
    $mascotReference !== null
        ? url(
            ltrim(
                $mascotReference,
                '/'
            )
        )
        : null;

$mascotWebpUrl =
    $mascotWebpReference !== null
        ? url(
            ltrim(
                $mascotWebpReference,
                '/'
            )
        )
        : null;

$mascotType =
    trim(
        (string) (
            $membership['mascot_type']
            ?? ''
        )
    );

$mascotName =
    trim(
        (string) (
            $membership['mascot_name']
            ?? ''
        )
    );

$mascotRepresents =
    trim(
        (string) (
            $membership['mascot_represents']
            ?? ''
        )
    );

$currentUser =
    current_user();

$currentMemberName =
    trim(
        (string) (
            $currentUser['display_name']
            ?? $currentUser['username']
            ?? 'Scholar'
        )
    );

if ($currentMemberName === '') {
    $currentMemberName =
        'Scholar';
}

$houseMemberCountStatement =
    $pdo->prepare(
        'SELECT COUNT(DISTINCT hm.user_id)
         FROM house_memberships hm
         INNER JOIN users u
            ON u.id = hm.user_id
         WHERE hm.house_id = :house_id
           AND hm.membership_status = "active"
           AND hm.left_at IS NULL
           AND u.status = "active"'
    );

$houseMemberCountStatement->execute([
    'house_id' =>
        $houseId,
]);

$houseMemberCount =
    (int) $houseMemberCountStatement->fetchColumn();


/*
|--------------------------------------------------------------------------
| Latest House Announcement
|--------------------------------------------------------------------------
*/

$latestAnnouncement =
    null;

if (
    $announcementForumId > 0
    && forum_can_access_forum(
        $pdo,
        $announcementForumId,
        $userId
    )
) {
    $announcementStatement =
        $pdo->prepare(
            'SELECT
                ft.id,
                ft.forum_id,
                ft.title,
                ft.user_id AS author_id,
                ft.created_at,
                ft.last_activity_at,
                ft.is_locked,
                u.display_name,
                u.username,
                fp.content
             FROM forum_threads ft
             INNER JOIN users u
                ON u.id = ft.user_id
             INNER JOIN forum_posts fp
                ON fp.id = (
                    SELECT MIN(fp2.id)
                    FROM forum_posts fp2
                    WHERE fp2.thread_id = ft.id
                      AND fp2.is_deleted = 0
                )
             WHERE ft.forum_id = :forum_id
               AND ft.is_deleted = 0
             ORDER BY
                ft.is_pinned DESC,
                ft.created_at DESC,
                ft.id DESC
             LIMIT 12'
        );

    $announcementStatement->execute([
        'forum_id' =>
            $announcementForumId,
    ]);

    foreach (
        $announcementStatement->fetchAll(
            PDO::FETCH_ASSOC
        )
        as $candidate
    ) {
        if (
            forum_can_view_thread(
                $pdo,
                (int) $candidate['id'],
                $userId
            )
        ) {
            $latestAnnouncement =
                $candidate;

            break;
        }
    }
}


/*
|--------------------------------------------------------------------------
| Recent Common Room Discussions
|--------------------------------------------------------------------------
|
| The configured Common Room forum is treated as the House forum root.
| Every visible descendant forum is included recursively so activity from
| deeply nested House areas appears here too.
|
*/

$recentDiscussions = [];
$forumIds = [];

if ($canAccessCommonRoomForum) {
    $forumIds = [
        $commonRoomForumId,
    ];

    $descendantForumStatement =
        $pdo->prepare(
            'WITH RECURSIVE forum_descendants AS (
                SELECT
                    id,
                    parent_forum_id,
                    sort_order

                FROM forums

                WHERE parent_forum_id = :root_forum_id
                  AND is_visible = 1

                UNION ALL

                SELECT
                    f.id,
                    f.parent_forum_id,
                    f.sort_order

                FROM forums f

                INNER JOIN forum_descendants fd
                    ON f.parent_forum_id = fd.id

                WHERE f.is_visible = 1
            )

            SELECT id
            FROM forum_descendants
            ORDER BY sort_order ASC, id ASC'
        );

    $descendantForumStatement->execute([
        'root_forum_id' =>
            $commonRoomForumId,
    ]);

    foreach (
        $descendantForumStatement->fetchAll(
            PDO::FETCH_COLUMN
        )
        as $descendantForumId
    ) {
        $descendantForumId =
            (int) $descendantForumId;

        if (
            $descendantForumId > 0
            && forum_can_access_forum(
                $pdo,
                $descendantForumId,
                $userId
            )
        ) {
            $forumIds[] =
                $descendantForumId;
        }
    }

    $forumIds =
        array_values(
            array_unique(
                array_filter(
                    array_map(
                        'intval',
                        $forumIds
                    ),
                    static fn (int $id): bool =>
                        $id > 0
                )
            )
        );

    if ($forumIds !== []) {
        $placeholders =
            implode(
                ',',
                array_fill(
                    0,
                    count($forumIds),
                    '?'
                )
            );

        $discussionStatement =
            $pdo->prepare(
                'SELECT
                    ft.id,
                    ft.forum_id,
                    ft.title,
                    ft.user_id AS author_id,
                    ft.last_activity_at,
                    ft.created_at,
                    f.title AS forum_title,
                    u.display_name,
                    u.username,
                    (
                        SELECT COUNT(*)
                        FROM forum_posts fp
                        WHERE fp.thread_id = ft.id
                          AND fp.is_deleted = 0
                    ) AS post_count
                 FROM forum_threads ft
                 INNER JOIN forums f
                    ON f.id = ft.forum_id
                 INNER JOIN users u
                    ON u.id = ft.user_id
                 WHERE ft.forum_id IN (' . $placeholders . ')
                   AND ft.is_deleted = 0
                 ORDER BY
                    ft.last_activity_at DESC,
                    ft.id DESC
                 LIMIT 30'
            );

        $discussionStatement->execute(
            $forumIds
        );

        foreach (
            $discussionStatement->fetchAll(
                PDO::FETCH_ASSOC
            )
            as $discussion
        ) {
            if (
                !forum_can_view_thread(
                    $pdo,
                    (int) $discussion['id'],
                    $userId
                )
            ) {
                continue;
            }

            $recentDiscussions[] =
                $discussion;

            if (
                count(
                    $recentDiscussions
                ) >= 6
            ) {
                break;
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| Pinned House Resources
|--------------------------------------------------------------------------
*/

$pinnedHouseThreads = [];

if ($forumIds !== []) {
    $pinnedPlaceholders =
        implode(
            ',',
            array_fill(
                0,
                count($forumIds),
                '?'
            )
        );

    $pinnedStatement =
        $pdo->prepare(
            'SELECT
                ft.id,
                ft.forum_id,
                ft.title,
                ft.user_id AS author_id,
                ft.last_activity_at,
                f.title AS forum_title,
                u.display_name,
                u.username
             FROM forum_threads ft
             INNER JOIN forums f
                ON f.id = ft.forum_id
             INNER JOIN users u
                ON u.id = ft.user_id
             WHERE ft.forum_id IN (' . $pinnedPlaceholders . ')
               AND ft.is_pinned = 1
               AND ft.is_deleted = 0
             ORDER BY
                ft.last_activity_at DESC,
                ft.id DESC
             LIMIT 20'
        );

    $pinnedStatement->execute(
        $forumIds
    );

    foreach (
        $pinnedStatement->fetchAll(
            PDO::FETCH_ASSOC
        )
        as $pinnedThread
    ) {
        if (
            !forum_can_view_thread(
                $pdo,
                (int) $pinnedThread['id'],
                $userId
            )
        ) {
            continue;
        }

        $pinnedHouseThreads[] =
            $pinnedThread;

        if (
            count(
                $pinnedHouseThreads
            ) >= 5
        ) {
            break;
        }
    }
}


/*
|--------------------------------------------------------------------------
| Current House Cup Snapshot
|--------------------------------------------------------------------------
*/

$houseCup =
    null;

$currentSchoolYearStatement =
    $pdo->query(
        'SELECT
            id,
            name
         FROM school_years
         WHERE is_current = 1
           AND is_active = 1
         ORDER BY id DESC
         LIMIT 1'
    );

$currentSchoolYear =
    $currentSchoolYearStatement->fetch(
        PDO::FETCH_ASSOC
    );

if ($currentSchoolYear) {
    $cupStatement =
        $pdo->prepare(
            'SELECT
                hcr.school_year_id,
                hcr.house_id,
                hcr.academic_points_total,
                hcr.house_only_points_total,
                hcr.final_points_total,
                hcr.final_rank,
                hcr.is_winner,
                hcr.finalized_at
             FROM house_cup_results hcr
             WHERE hcr.school_year_id = :school_year_id
               AND hcr.house_id = :house_id
             LIMIT 1'
        );

    $cupStatement->execute([
        'school_year_id' =>
            (int) $currentSchoolYear['id'],

        'house_id' =>
            $houseId,
    ]);

    $houseCup =
        $cupStatement->fetch(
            PDO::FETCH_ASSOC
        );

    if ($houseCup) {
        $houseCup['school_year_name'] =
            (string) (
                $currentSchoolYear['name']
                ?? ''
            );

        $houseCup['display_rank'] =
            $houseCup['final_rank'] !== null
                ? (int) $houseCup['final_rank']
                : null;

        /*
         * Before results are formally finalized, derive a live position from
         * the points already stored for the current school year.
         */
        if ($houseCup['display_rank'] === null) {
            $liveRankStatement =
                $pdo->prepare(
                    'SELECT
                        1 + COUNT(*)
                     FROM house_cup_results other
                     WHERE other.school_year_id = :school_year_id
                       AND other.final_points_total > :points_total'
                );

            $liveRankStatement->execute([
                'school_year_id' =>
                    (int) $currentSchoolYear['id'],

                'points_total' =>
                    (string) $houseCup['final_points_total'],
            ]);

            $houseCup['display_rank'] =
                (int) $liveRankStatement->fetchColumn();
        }
    } else {
        $houseCup = null;
    }
}


/*
|--------------------------------------------------------------------------
| This Week's House Birthdays
|--------------------------------------------------------------------------
|
| The Common Room birthday window is the current Sunday-through-Saturday
| calendar week in Eastern Time. Birth years are ignored for matching; only
| month/day determine whether a member's birthday falls inside this week.
|
*/

$houseBirthdays = [];

$easternZone =
    new DateTimeZone(
        'America/New_York'
    );

$today =
    new DateTimeImmutable(
        'today',
        $easternZone
    );

/*
 * DateTime's natural-language "sunday this week" can resolve to the
 * upcoming Sunday depending on the current weekday. Instead, subtract the
 * numeric weekday (0 = Sunday ... 6 = Saturday) so this always begins with
 * the Sunday that belongs to the current calendar week.
 */
$daysSinceSunday =
    (int) $today->format('w');

$currentWeekSunday =
    $today->modify(
        '-' . $daysSinceSunday . ' days'
    );

$currentWeekSaturday =
    $currentWeekSunday->modify(
        '+6 days'
    );

/*
 * Build the seven month/day values represented by the current week.
 * Using explicit dates also handles weeks that cross month or year boundaries.
 */
$weekMonthDays = [];
$weekDateLookup = [];

for ($offset = 0; $offset < 7; $offset++) {
    $weekDate =
        $currentWeekSunday->modify(
            '+' . $offset . ' days'
        );

    $monthDay =
        $weekDate->format(
            'm-d'
        );

    $weekMonthDays[] =
        $monthDay;

    $weekDateLookup[$monthDay] =
        $weekDate;
}

$birthdayPlaceholders =
    implode(
        ',',
        array_fill(
            0,
            count($weekMonthDays),
            '?'
        )
    );

$birthdayStatement =
    $pdo->prepare(
        'SELECT
            u.id,
            u.display_name,
            u.username,
            u.date_of_birth,
            COALESCE(
                up.birthday_display,
                "month_day"
            ) AS birthday_display
         FROM house_memberships hm
         INNER JOIN users u
            ON u.id = hm.user_id
         LEFT JOIN user_profiles up
            ON up.user_id = u.id
         WHERE hm.house_id = ?
           AND hm.membership_status = "active"
           AND hm.left_at IS NULL
           AND u.status = "active"
           AND DATE_FORMAT(
                u.date_of_birth,
                "%m-%d"
           ) IN (' . $birthdayPlaceholders . ')
         ORDER BY
            FIELD(
                DATE_FORMAT(
                    u.date_of_birth,
                    "%m-%d"
                ),
                ' . implode(
                    ',',
                    array_fill(
                        0,
                        count($weekMonthDays),
                        '?'
                    )
                ) . '
            ),
            u.display_name ASC,
            u.username ASC'
    );

$birthdayStatement->execute([
    $houseId,
    ...$weekMonthDays,
    ...$weekMonthDays,
]);

foreach (
    $birthdayStatement->fetchAll(
        PDO::FETCH_ASSOC
    )
    as $birthdayRow
) {
    $dateOfBirth =
        trim(
            (string) (
                $birthdayRow['date_of_birth']
                ?? ''
            )
        );

    if ($dateOfBirth === '') {
        continue;
    }

    try {
        $birthDate =
            new DateTimeImmutable(
                $dateOfBirth
            );

        $monthDay =
            $birthDate->format(
                'm-d'
            );

        $birthdayThisWeek =
            $weekDateLookup[$monthDay]
            ?? null;

        if (
            !$birthdayThisWeek
            instanceof DateTimeImmutable
        ) {
            continue;
        }

        $birthdayRow['birthday_this_week'] =
            $birthdayThisWeek;

        $birthdayRow['is_today'] =
            $birthdayThisWeek->format('Y-m-d')
            ===
            $today->format('Y-m-d');

        $houseBirthdays[] =
            $birthdayRow;

    } catch (Throwable) {
        continue;
    }
}


/*
|--------------------------------------------------------------------------
| Housemates Currently Active
|--------------------------------------------------------------------------
*/

$activeHousematesStatement =
    $pdo->prepare(
        'SELECT
            u.id,
            u.display_name,
            u.username,
            MAX(upres.last_seen_at) AS last_seen_at
         FROM house_memberships hm
         INNER JOIN users u
            ON u.id = hm.user_id
         INNER JOIN user_presence upres
            ON upres.user_id = u.id
         WHERE hm.house_id = :house_id
           AND hm.membership_status = "active"
           AND hm.left_at IS NULL
           AND u.status = "active"
           AND upres.last_seen_at >= DATE_SUB(
                CURRENT_TIMESTAMP,
                INTERVAL 15 MINUTE
           )
         GROUP BY
            u.id,
            u.display_name,
            u.username
         ORDER BY
            last_seen_at DESC,
            u.display_name ASC
         LIMIT 12'
    );

$activeHousematesStatement->execute([
    'house_id' =>
        $houseId,
]);

$activeHousemates =
    $activeHousematesStatement->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Metadata
|--------------------------------------------------------------------------
*/

$pageTitle =
    $houseName
    . ' Common Room | Blackthorne Academy';

$pageDescription =
    'Private Common Room for members of '
    . $houseName
    . ' at Blackthorne Academy.';

$pageCanonical =
    url(
        'common-room.php'
    );

$robots =
    'noindex, nofollow';

require INCLUDES_PATH . '/header.php';

?>

<style>
    .house-common-room-page {
        --house-primary: <?=e($primaryColor);
        ?>;
        --house-secondary: <?=e($secondaryColor);
        ?>;
        --house-accent: <?=e($accentColor);
        ?>;
        --house-dark: <?=e($darkColor);
        ?>;
        --house-highlight: <?=e($highlightColor);
        ?>;
        --house-name: <?=e($displayColor);
        ?>;
    }

</style>

<main id="main-content" class="house-common-room-page house-theme">

    <?php
    $houseHeroBackgroundUrl =
        $heroWebpUrl
        ?? $heroUrl;
    ?>

    <section class="house-common-room-hero<?= $houseHeroBackgroundUrl !== null ? ' has-house-hero-image' : ''; ?>"
        aria-labelledby="house-common-room-heading" <?php if ($houseHeroBackgroundUrl !== null): ?>
        style="--house-hero-image: url('<?= e($houseHeroBackgroundUrl); ?>');" <?php endif; ?>>
        <div class="section-inner house-common-room-hero-inner">

            <?php if ($canAccessCommonRoomForum): ?>
            <a href="<?= e(
                        url(
                            'forum.php?f='
                            . $commonRoomForumId
                        )
                    ); ?>" class="house-common-room-crest-link" aria-label="<?= e(
                        'Enter the '
                        . $houseName
                        . ' forums'
                    ); ?>">
                <?php else: ?>
                <div class="house-common-room-crest-static">
                    <?php endif; ?>

                    <?php if ($crestUrl !== null): ?>
                    <picture>
                        <?php if (
                            $crestWebpUrl !== null
                            && $crestWebpUrl !== $crestUrl
                        ): ?>
                        <source srcset="<?= e($crestWebpUrl); ?>" type="image/webp">
                        <?php endif; ?>

                        <img src="<?= e($crestUrl); ?>" alt="<?= e(
                                $houseName
                                . ' House crest'
                            ); ?>" class="house-common-room-crest">
                    </picture>
                    <?php else: ?>
                    <span class="house-common-room-crest-fallback" aria-hidden="true">
                        <?= e(
                            function_exists('mb_substr')
                                ? mb_strtoupper(
                                    mb_substr(
                                        $houseName,
                                        0,
                                        1,
                                        'UTF-8'
                                    ),
                                    'UTF-8'
                                )
                                : strtoupper(
                                    substr(
                                        $houseName,
                                        0,
                                        1
                                    )
                                )
                        ); ?>
                    </span>
                    <?php endif; ?>

                    <?php if ($canAccessCommonRoomForum): ?>
            </a>
            <?php else: ?>
        </div>
        <?php endif; ?>


        <div class="house-common-room-copy">

            <p class="academy-overline">
                <?= e($houseName); ?> House
            </p>

            <h1 id="house-common-room-heading" class="house-common-room-title">
                Common Room
            </h1>

            <p class="house-common-room-kicker">
                <?= e($houseName); ?>
            </p>

            <p class="house-common-room-welcome">
                Welcome home,
                <strong><?= e($currentMemberName); ?></strong>.
            </p>

            <?php if ($houseDescription !== ''): ?>
            <p class="house-common-room-description">
                <?= e($houseDescription); ?>
            </p>
            <?php endif; ?>

            <?php if ($houseMotto !== ''): ?>
            <blockquote class="house-common-room-motto">
                “<?= e($houseMotto); ?>”
            </blockquote>
            <?php endif; ?>

            <div class="house-common-room-actions">

                <?php if ($canAccessCommonRoomForum): ?>
                <a href="<?= e(
                                url(
                                    'forum.php?f='
                                    . $commonRoomForumId
                                )
                            ); ?>" class="button button-primary">
                    Enter the House Forums
                </a>
                <?php endif; ?>

                <a href="<?= e(HOME_URL); ?>" class="button button-secondary">
                    Academy Home
                </a>

            </div>

        </div>

        </div>
    </section>


    <section class="house-common-room-content">
        <div class="section-inner member-home-layout house-common-room-layout">

            <?php require INCLUDES_PATH . '/member-sidebar.php'; ?>

            <div class="member-home-main house-common-room-main">

                <section class="house-room-panel house-room-welcome-panel">
                    <div class="house-room-welcome-inner">
                        <div class="house-room-welcome-mark">
                            <?php if ($crestUrl !== null): ?>
                            <picture>
                                <?php if (
                                        $crestWebpUrl !== null
                                        && $crestWebpUrl !== $crestUrl
                                    ): ?>
                                <source srcset="<?= e($crestWebpUrl); ?>" type="image/webp">
                                <?php endif; ?>

                                <img src="<?= e($crestUrl); ?>" alt="<?= e($houseName . ' House crest'); ?>"
                                    class="house-room-welcome-crest" loading="lazy">
                            </picture>
                            <?php else: ?>
                            <span class="house-room-welcome-crest-fallback" aria-hidden="true">
                                <?= e(
                                        function_exists('mb_substr')
                                            ? mb_strtoupper(mb_substr($houseName, 0, 1, 'UTF-8'), 'UTF-8')
                                            : strtoupper(substr($houseName, 0, 1))
                                    ); ?>
                            </span>
                            <?php endif; ?>
                        </div>

                        <div class="house-room-welcome-copy">
                            <p class="academy-overline">
                                About <?= e($houseName); ?>
                            </p>

                            <h2>
                                Welcome to <?= e($houseName); ?>
                            </h2>

                            <?php if ($houseIntroduction !== ''): ?>
                            <div class="house-introduction-copy rich-text-content">
                                <?= sanitize_rich_text(
                                        $houseIntroduction
                                    ); ?>
                            </div>
                            <?php endif; ?>

                            <div class="house-about-meta">
                                <span>
                                    <strong><?= number_format($houseMemberCount); ?></strong>
                                    <?= $houseMemberCount === 1
                                        ? 'House member'
                                        : 'House members'; ?>
                                </span>

                                <span>
                                    This week:
                                    <strong>
                                        <?= e($currentWeekSunday->format('M j')); ?>
                                        –
                                        <?= e($currentWeekSaturday->format('M j')); ?>
                                    </strong>
                                </span>
                            </div>
                        </div>
                    </div>
                </section>

                <div class="house-room-dashboard-heading">
                    <div>
                        <p class="academy-overline">Inside <?= e($houseName); ?></p>
                        <h2>The Common Room</h2>
                        <p>House news, conversations, traditions, and the people who make <?= e($houseName); ?> home.
                        </p>
                    </div>

                    <?php if ($canAccessCommonRoomForum): ?>
                    <a href="<?= e(url('forum.php?f=' . $commonRoomForumId)); ?>" class="button button-primary">
                        Enter House Forums
                    </a>
                    <?php endif; ?>
                </div>

                <section class="house-room-panel house-room-featured-announcement">
                    <header class="house-room-panel-heading">
                        <h2>
                            Latest House Announcement
                        </h2>
                    </header>

                    <div class="house-room-panel-body">

                        <?php if ($latestAnnouncement === null): ?>

                        <p class="house-room-empty">
                            No House announcement has been posted yet.
                        </p>

                        <?php else: ?>

                        <?php
                            $announcementAuthorName =
                                trim(
                                    (string) (
                                        $latestAnnouncement['display_name']
                                        ?? $latestAnnouncement['username']
                                        ?? 'Member'
                                    )
                                );

                            $announcementAuthorColor =
                                user_house_display_color(
                                    (int) $latestAnnouncement['author_id']
                                );
                            ?>

                        <article class="house-room-announcement">

                            <h3>
                                <a href="<?= e(
                                            url(
                                                'thread.php?t='
                                                . (int) $latestAnnouncement['id']
                                            )
                                        ); ?>">
                                    <?= e(
                                            (string) $latestAnnouncement['title']
                                        ); ?>
                                </a>
                            </h3>

                            <p class="house-room-announcement-meta">
                                by
                                <a href="<?= e(
                                            url(
                                                'profile.php?u='
                                                . (int) $latestAnnouncement['author_id']
                                            )
                                        ); ?>" <?= $announcementAuthorColor !== null
                                            ? 'style="color: '
                                                . e($announcementAuthorColor)
                                                . ';"'
                                            : ''; ?>>
                                    <?= e($announcementAuthorName); ?>
                                </a>
                                ·
                                <?= e(
                                        date(
                                            'F j, Y',
                                            strtotime(
                                                (string) $latestAnnouncement['created_at']
                                            )
                                        )
                                    ); ?>
                            </p>

                            <div class="house-room-announcement-content rich-text-content">
                                <?= blackthorne_render_forum_content(
                                        (string) $latestAnnouncement['content']
                                    ); ?>
                            </div>

                            <div class="house-room-announcement-actions">
                                <a href="<?= e(
                                            url(
                                                'thread.php?t='
                                                . (int) $latestAnnouncement['id']
                                            )
                                        ); ?>" class="button button-secondary">
                                    Open Announcement
                                </a>
                            </div>

                        </article>

                        <?php endif; ?>

                    </div>
                </section>

                <div class="house-room-section-heading">
                    <div>
                        <p class="academy-overline">House Community</p>
                        <h2>Around the Common Room</h2>
                    </div>
                    <span class="house-room-section-flourish" aria-hidden="true">✦</span>
                </div>

                <div class="house-common-room-community-grid">
                    <section class="house-room-panel house-room-pinned-panel">
                        <header class="house-room-panel-heading">
                            <h2>
                                Pinned House Resources
                            </h2>
                        </header>

                        <div class="house-room-panel-body">

                            <?php if ($pinnedHouseThreads === []): ?>

                            <p class="house-room-empty">
                                No House resources have been pinned yet.
                            </p>

                            <?php else: ?>

                            <div class="house-room-pinned-list">

                                <?php foreach ($pinnedHouseThreads as $pinnedThread): ?>
                                <?php
                                    $pinnedAuthorName =
                                        trim(
                                            (string) (
                                                $pinnedThread['display_name']
                                                ?? $pinnedThread['username']
                                                ?? 'Member'
                                            )
                                        );

                                    $pinnedAuthorColor =
                                        user_house_display_color(
                                            (int) $pinnedThread['author_id']
                                        );
                                    ?>

                                <article class="house-room-pinned-item">

                                    <a href="<?= e(
                                                url(
                                                    'thread.php?t='
                                                    . (int) $pinnedThread['id']
                                                )
                                            ); ?>">
                                        <?= e(
                                                (string) $pinnedThread['title']
                                            ); ?>
                                    </a>

                                    <p class="house-room-pinned-meta">
                                        <?= e(
                                                (string) $pinnedThread['forum_title']
                                            ); ?>
                                        · pinned by
                                        <a href="<?= e(
                                                    url(
                                                        'profile.php?u='
                                                        . (int) $pinnedThread['author_id']
                                                    )
                                                ); ?>" <?= $pinnedAuthorColor !== null
                                                    ? 'style="color: '
                                                        . e($pinnedAuthorColor)
                                                        . ';"'
                                                    : ''; ?>>
                                            <?= e($pinnedAuthorName); ?>
                                        </a>
                                    </p>

                                </article>

                                <?php endforeach; ?>

                            </div>

                            <?php endif; ?>

                        </div>
                    </section>
                    <section class="house-room-panel house-room-discussions-panel">
                        <header class="house-room-panel-heading">
                            <h2>
                                Recent House Discussions
                            </h2>
                        </header>

                        <div class="house-room-panel-body">

                            <?php if ($recentDiscussions === []): ?>

                            <p class="house-room-empty">
                                No recent House discussions are available yet.
                            </p>

                            <?php else: ?>

                            <div class="house-room-discussion-list">

                                <?php foreach ($recentDiscussions as $discussion): ?>
                                <?php
                                    $discussionAuthorName =
                                        trim(
                                            (string) (
                                                $discussion['display_name']
                                                ?? $discussion['username']
                                                ?? 'Member'
                                            )
                                        );

                                    $discussionAuthorColor =
                                        user_house_display_color(
                                            (int) $discussion['author_id']
                                        );

                                    $replyCount =
                                        max(
                                            0,
                                            (int) $discussion['post_count']
                                            - 1
                                        );
                                    ?>

                                <article class="house-room-discussion">

                                    <h3>
                                        <a href="<?= e(
                                                    url(
                                                        'thread.php?t='
                                                        . (int) $discussion['id']
                                                    )
                                                ); ?>">
                                            <?= e(
                                                    (string) $discussion['title']
                                                ); ?>
                                        </a>
                                    </h3>

                                    <p class="house-room-discussion-meta">
                                        <?= e(
                                                (string) $discussion['forum_title']
                                            ); ?>
                                        · by
                                        <a href="<?= e(
                                                    url(
                                                        'profile.php?u='
                                                        . (int) $discussion['author_id']
                                                    )
                                                ); ?>" <?= $discussionAuthorColor !== null
                                                    ? 'style="color: '
                                                        . e($discussionAuthorColor)
                                                        . ';"'
                                                    : ''; ?>>
                                            <?= e($discussionAuthorName); ?>
                                        </a>
                                        ·
                                        <?= number_format($replyCount); ?>
                                        <?= $replyCount === 1
                                                ? 'reply'
                                                : 'replies'; ?>
                                    </p>

                                </article>

                                <?php endforeach; ?>

                            </div>

                            <?php endif; ?>

                        </div>
                    </section>
                </div>

                <div class="house-room-section-heading">
                    <div>
                        <p class="academy-overline">House Identity</p>
                        <h2>Spirit &amp; Standing</h2>
                    </div>
                    <span class="house-room-section-flourish" aria-hidden="true">✦</span>
                </div>

                <div class="house-common-room-identity-grid">
                    <section class="house-room-panel house-room-mascot-panel">
                        <header class="house-room-panel-heading">
                            <h2>
                                House Mascot
                            </h2>
                        </header>

                        <div class="house-room-panel-body">

                            <?php if ($mascotUrl !== null): ?>

                            <div class="house-room-mascot-wrap">
                                <picture>

                                    <?php if (
                                        $mascotWebpUrl !== null
                                        && $mascotWebpUrl !== $mascotUrl
                                    ): ?>
                                    <source srcset="<?= e($mascotWebpUrl); ?>" type="image/webp">
                                    <?php endif; ?>

                                    <img src="<?= e($mascotUrl); ?>" alt="<?= e(
                                            $houseName
                                            . ' House mascot'
                                        ); ?>" loading="lazy">

                                </picture>
                            </div>

                            <?php else: ?>

                            <p class="house-room-mascot-fallback">
                                The <?= e($houseName); ?> mascot has not been
                                revealed yet.
                            </p>

                            <?php endif; ?>


                            <?php if (
                            $mascotType !== ''
                            || $mascotName !== ''
                            || $mascotRepresents !== ''
                        ): ?>

                            <dl class="house-room-mascot-details">

                                <?php if ($mascotType !== ''): ?>
                                <div class="house-room-mascot-detail">
                                    <dt>
                                        Mascot:
                                    </dt>
                                    <dd>
                                        <?= e($mascotType); ?>
                                    </dd>
                                </div>
                                <?php endif; ?>

                                <?php if ($mascotName !== ''): ?>
                                <div class="house-room-mascot-detail">
                                    <dt>
                                        Name:
                                    </dt>
                                    <dd>
                                        <?= e($mascotName); ?>
                                    </dd>
                                </div>
                                <?php endif; ?>

                                <?php if ($mascotRepresents !== ''): ?>
                                <div class="house-room-mascot-detail">
                                    <dt>
                                        Represents:
                                    </dt>
                                    <dd>
                                        <?= e($mascotRepresents); ?>
                                    </dd>
                                </div>
                                <?php endif; ?>

                            </dl>

                            <?php endif; ?>

                        </div>
                    </section>
                    <section class="house-room-panel house-room-cup-panel">
                        <header class="house-room-panel-heading">
                            <h2>
                                House Cup
                            </h2>
                        </header>

                        <div class="house-room-panel-body">

                            <?php if ($houseCup === null): ?>

                            <p class="house-room-empty">
                                House Cup standings will appear here once points
                                are recorded for the current school year.
                            </p>

                            <?php else: ?>

                            <div class="house-cup-card">

                                <div class="house-cup-scoreline">

                                    <div class="house-cup-points">
                                        <?= number_format(
                                            (float) $houseCup['final_points_total'],
                                            0
                                        ); ?>
                                        <small>
                                            total points
                                        </small>
                                    </div>

                                    <div class="house-cup-rank">
                                        <?php if (
                                            (int) (
                                                $houseCup['display_rank']
                                                ?? 0
                                            ) > 0
                                        ): ?>
                                        <strong>
                                            #<?= number_format(
                                                    (int) $houseCup['display_rank']
                                                ); ?>
                                        </strong>
                                        current standing
                                        <?php else: ?>
                                        Standing pending
                                        <?php endif; ?>
                                    </div>

                                </div>

                                <div class="house-cup-breakdown">
                                    <div>
                                        <span>
                                            Academic
                                        </span>
                                        <strong>
                                            <?= number_format(
                                                (float) $houseCup['academic_points_total'],
                                                0
                                            ); ?>
                                        </strong>
                                    </div>

                                    <div>
                                        <span>
                                            House
                                        </span>
                                        <strong>
                                            <?= number_format(
                                                (float) $houseCup['house_only_points_total'],
                                                0
                                            ); ?>
                                        </strong>
                                    </div>
                                </div>

                                <?php if (
                                    trim(
                                        (string) (
                                            $houseCup['school_year_name']
                                            ?? ''
                                        )
                                    ) !== ''
                                ): ?>
                                <p class="house-room-empty">
                                    <?= e(
                                            (string) $houseCup['school_year_name']
                                        ); ?>
                                </p>
                                <?php endif; ?>

                            </div>

                            <?php endif; ?>

                        </div>
                    </section>
                </div>

                <div class="house-room-section-heading">
                    <div>
                        <p class="academy-overline">House Life</p>
                        <h2>This Week in <?= e($houseName); ?></h2>
                    </div>
                    <span class="house-room-section-flourish" aria-hidden="true">✦</span>
                </div>

                <div class="house-common-room-life-grid">
                    <section class="house-room-panel house-room-birthdays-panel">
                        <header class="house-room-panel-heading">
                            <h2>
                                This Week's Birthdays
                            </h2>
                        </header>

                        <div class="house-room-panel-body">

                            <?php if ($houseBirthdays === []): ?>

                            <p class="house-room-empty">
                                No House birthdays fall between
                                <?= e($currentWeekSunday->format('F j')); ?>
                                and
                                <?= e($currentWeekSaturday->format('F j')); ?>.
                            </p>

                            <?php else: ?>

                            <div class="house-room-birthday-list">

                                <?php foreach ($houseBirthdays as $birthday): ?>
                                <?php
                                    $birthdayName =
                                        trim(
                                            (string) (
                                                $birthday['display_name']
                                                ?? $birthday['username']
                                                ?? 'Member'
                                            )
                                        );

                                    $birthdayColor =
                                        user_house_display_color(
                                            (int) $birthday['id']
                                        );

                                    $birthdayDate =
                                        $birthday['birthday_this_week']
                                        ?? null;

                                    $birthdayDisplay =
                                        (string) (
                                            $birthday['birthday_display']
                                            ?? 'month_day'
                                        );

                                    $birthdayLabel =
                                        '';

                                    if ($birthdayDate instanceof DateTimeImmutable) {
                                        $birthdayLabel =
                                            match ($birthdayDisplay) {
                                                'month' =>
                                                    $birthdayDate->format('F'),

                                                'month_day_year' =>
                                                    $birthdayDate->format('F j'),

                                                default =>
                                                    $birthdayDate->format('F j'),
                                            };
                                    }

                                    $isToday =
                                        !empty(
                                            $birthday['is_today']
                                        );
                                    ?>

                                <div class="house-room-birthday">

                                    <a href="<?= e(
                                                url(
                                                    'profile.php?u='
                                                    . (int) $birthday['id']
                                                )
                                            ); ?>" <?= $birthdayColor !== null
                                                ? 'style="color: '
                                                    . e($birthdayColor)
                                                    . ';"'
                                                : ''; ?>>
                                        <?= e($birthdayName); ?>
                                    </a>

                                    <span class="house-room-birthday-date<?= $isToday
                                                ? ' house-room-birthday-today'
                                                : ''; ?>">
                                        <?= $isToday
                                                ? 'Today'
                                                : e($birthdayLabel); ?>
                                    </span>

                                </div>

                                <?php endforeach; ?>

                            </div>

                            <?php endif; ?>

                        </div>
                    </section>
                    <section class="house-room-panel house-room-housemates-panel">
                        <header class="house-room-panel-heading">
                            <h2>
                                Housemates in the Halls
                            </h2>
                        </header>

                        <div class="house-room-panel-body">

                            <?php if ($activeHousemates === []): ?>

                            <p class="house-room-empty">
                                No other House members are currently active.
                            </p>

                            <?php else: ?>

                            <div class="house-room-member-list">

                                <?php foreach ($activeHousemates as $housemate): ?>
                                <?php
                                    $housemateName =
                                        trim(
                                            (string) (
                                                $housemate['display_name']
                                                ?? $housemate['username']
                                                ?? 'Member'
                                            )
                                        );

                                    $housemateColor =
                                        user_house_display_color(
                                            (int) $housemate['id']
                                        );
                                    ?>

                                <div class="house-room-member">

                                    <a class="house-room-member-name" href="<?= e(
                                                url(
                                                    'profile.php?u='
                                                    . (int) $housemate['id']
                                                )
                                            ); ?>" <?= $housemateColor !== null
                                                ? 'style="color: '
                                                    . e($housemateColor)
                                                    . ';"'
                                                : ''; ?>>
                                        <?= e($housemateName); ?>
                                    </a>

                                    <span class="house-room-member-status">
                                        Online
                                    </span>

                                </div>

                                <?php endforeach; ?>

                            </div>

                            <?php endif; ?>

                        </div>
                    </section>
                </div>

            </div>

        </div>
    </section>
</main>

<?php require INCLUDES_PATH . '/footer.php'; ?>
