<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/house-functions.php';

require_login();
require_active_account();


/*
|--------------------------------------------------------------------------
| Blackthorne Academy
| The Living Ledger
|--------------------------------------------------------------------------
|
| Fantasy-themed online member directory.
|
| Members are grouped beneath their active House and are considered online
| when their recorded presence has been refreshed within the last 5 minutes.
| Member privacy settings are respected.
|
*/


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

$viewerIsStaff =
    current_user_is_staff();

$superAdminUserId =
    configured_super_admin_user_id();


/*
|--------------------------------------------------------------------------
| Houses
|--------------------------------------------------------------------------
*/

$housesStatement =
    $pdo->query(
        '
        SELECT
            id,
            name,
            display_name,
            slug,
            description,
            motto,
            display_color,
            crest_image,
            primary_color,
            secondary_color,
            accent_color,
            dark_neutral_color,
            highlight_color,
            sort_order

        FROM houses

        WHERE is_active = 1

        ORDER BY
            sort_order ASC,
            display_name ASC,
            name ASC,
            id ASC
        '
    );

$houses =
    $housesStatement->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Currently Online House Members
|--------------------------------------------------------------------------
|
| The 5-minute cutoff matches the online-state logic already used by member
| profiles, Friends, and the shared member sidebar.
|
| Privacy rules:
| - show_online_status = 0 hides the member from the Ledger.
| - staff_only profiles are visible here only to staff viewers.
|
| Staff detection mirrors the current authentication layer:
| - protected Super Admin
| - active roles with roles.is_staff = 1
| - temporary built-in staff-role compatibility slugs
|
*/

$onlineMembersStatement =
    $pdo->prepare(
        '
        SELECT
            u.id,
            u.username,
            u.display_name,
            hm.house_id,
            upres.last_seen_at,

            CASE
                WHEN u.id = :super_admin_user_id THEN 1

                WHEN EXISTS (
                    SELECT 1

                    FROM user_roles staff_ur

                    INNER JOIN roles staff_role
                        ON staff_role.id = staff_ur.role_id

                    WHERE staff_ur.user_id = u.id
                      AND staff_ur.is_active = 1
                      AND staff_ur.revoked_at IS NULL
                      AND (
                            staff_ur.expires_at IS NULL
                            OR staff_ur.expires_at > CURRENT_TIMESTAMP
                          )
                      AND staff_role.is_active = 1
                      AND (
                            staff_role.is_staff = 1
                            OR LOWER(staff_role.slug) IN (
                                "instructor",
                                "moderator",
                                "admin",
                                "administrator"
                            )
                          )
                )
                THEN 1

                ELSE 0
            END AS is_staff,

            (
                SELECT staff_role_name.name

                FROM user_roles staff_ur_name

                INNER JOIN roles staff_role_name
                    ON staff_role_name.id = staff_ur_name.role_id

                WHERE staff_ur_name.user_id = u.id
                  AND staff_ur_name.is_active = 1
                  AND staff_ur_name.revoked_at IS NULL
                  AND (
                        staff_ur_name.expires_at IS NULL
                        OR staff_ur_name.expires_at > CURRENT_TIMESTAMP
                      )
                  AND staff_role_name.is_active = 1
                  AND (
                        staff_role_name.is_staff = 1
                        OR LOWER(staff_role_name.slug) IN (
                            "instructor",
                            "moderator",
                            "admin",
                            "administrator"
                        )
                      )

                ORDER BY
                    staff_role_name.sort_order ASC,
                    staff_role_name.name ASC,
                    staff_role_name.id ASC

                LIMIT 1
            ) AS staff_role_name

        FROM house_memberships hm

        INNER JOIN users u
            ON u.id = hm.user_id

        INNER JOIN user_presence upres
            ON upres.user_id = u.id

        LEFT JOIN user_profiles profile
            ON profile.user_id = u.id

        WHERE hm.membership_status = "active"
          AND hm.left_at IS NULL
          AND u.status = "active"

          AND COALESCE(
                profile.show_online_status,
                1
              ) = 1

          AND (
                COALESCE(
                    profile.profile_visibility,
                    "members"
                ) <> "staff_only"
                OR :viewer_is_staff = 1
              )

          AND upres.last_seen_at >= DATE_SUB(
                CURRENT_TIMESTAMP,
                INTERVAL 5 MINUTE
              )

        ORDER BY
            hm.house_id ASC,
            is_staff DESC,
            u.display_name ASC,
            u.username ASC,
            u.id ASC
        '
    );

$onlineMembersStatement->execute([
    'super_admin_user_id' =>
        $superAdminUserId,

    'viewer_is_staff' =>
        $viewerIsStaff
            ? 1
            : 0,
]);

$onlineMemberRows =
    $onlineMembersStatement->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Group Members by House
|--------------------------------------------------------------------------
*/

$onlineMembersByHouse = [];

foreach ($onlineMemberRows as $member) {
    $houseId =
        (int) (
            $member['house_id']
            ?? 0
        );

    if ($houseId <= 0) {
        continue;
    }

    $onlineMembersByHouse[
        $houseId
    ][] =
        $member;
}


/*
|--------------------------------------------------------------------------
| Page Totals
|--------------------------------------------------------------------------
*/

$totalOnlineMembers =
    count(
        $onlineMemberRows
    );

$totalOnlineStaff = 0;

foreach ($onlineMemberRows as $member) {
    if (
        (int) (
            $member['is_staff']
            ?? 0
        ) === 1
    ) {
        $totalOnlineStaff++;
    }
}


/*
|--------------------------------------------------------------------------
| SEO / Page Metadata
|--------------------------------------------------------------------------
*/

$pageTitle =
    'The Living Ledger | Blackthorne Academy';

$pageDescription =
    'See which Blackthorne Academy members are currently roaming the halls, organized by House.';

$pageCanonical =
    url(
        'living-ledger.php'
    );

$robots =
    'noindex, nofollow';


/*
|--------------------------------------------------------------------------
| Header
|--------------------------------------------------------------------------
*/

require INCLUDES_PATH . '/header.php';

?>

<main
    id="main-content"
    class="living-ledger-page"
>

    <section class="living-ledger-intro">
        <div class="section-inner">

            <p class="academy-overline">
                Academy Community
            </p>

            <h1>
                The Living Ledger
            </h1>

            <p class="living-ledger-intro-copy">
                The Ledger stirs whenever a scholar crosses the Academy halls.
                Those presently within Blackthorne appear beneath the crest of
                their House.
            </p>

            <div
                class="living-ledger-summary"
                aria-label="Living Ledger online summary"
            >
                <span>
                    <strong><?= number_format($totalOnlineMembers); ?></strong>
                    <?= $totalOnlineMembers === 1
                        ? 'member'
                        : 'members'; ?>
                    online
                </span>

                <?php if ($totalOnlineStaff > 0): ?>
                    <span aria-hidden="true">
                        •
                    </span>

                    <span>
                        <strong><?= number_format($totalOnlineStaff); ?></strong>
                        staff
                    </span>
                <?php endif; ?>
            </div>

        </div>
    </section>


    <section class="living-ledger-content">
        <div
            class="section-inner member-home-layout living-ledger-layout"
        >

            <?php
            require
                INCLUDES_PATH
                . '/member-sidebar.php';
            ?>

            <div class="member-home-main living-ledger-main">

                <?php if ($houses === []): ?>

                    <section class="living-ledger-empty-page">
                        <h2>
                            The Ledger is quiet.
                        </h2>

                        <p>
                            No active Houses are currently available.
                        </p>
                    </section>

                <?php else: ?>

                    <div class="living-ledger-houses">

                        <?php foreach ($houses as $house): ?>
                            <?php
                            $houseId =
                                (int) (
                                    $house['id']
                                    ?? 0
                                );

                            $houseName =
                                house_visible_name(
                                    $house
                                );

                            if ($houseName === '') {
                                $houseName =
                                    'House';
                            }

                            $houseMembers =
                                $onlineMembersByHouse[
                                    $houseId
                                ]
                                ?? [];

                            $houseDisplayColor =
                                safe_css_color(
                                    (string) (
                                        $house[
                                            'display_color'
                                        ]
                                        ?? ''
                                    )
                                )
                                ?? '#C8BEC5';

                            $housePrimaryColor =
                                safe_css_color(
                                    (string) (
                                        $house[
                                            'primary_color'
                                        ]
                                        ?? ''
                                    )
                                )
                                ?? '#5B355F';

                            $houseSecondaryColor =
                                safe_css_color(
                                    (string) (
                                        $house[
                                            'secondary_color'
                                        ]
                                        ?? ''
                                    )
                                )
                                ?? '#2B182F';

                            $houseAccentColor =
                                safe_css_color(
                                    (string) (
                                        $house[
                                            'accent_color'
                                        ]
                                        ?? ''
                                    )
                                )
                                ?? '#C7A55B';

                            $houseDarkColor =
                                safe_css_color(
                                    (string) (
                                        $house[
                                            'dark_neutral_color'
                                        ]
                                        ?? ''
                                    )
                                )
                                ?? '#100B13';

                            $houseHighlightColor =
                                safe_css_color(
                                    (string) (
                                        $house[
                                            'highlight_color'
                                        ]
                                        ?? ''
                                    )
                                )
                                ?? '#E3D2AE';

                            $crestReference =
                                house_safe_crest_reference(
                                    isset(
                                        $house[
                                            'crest_image'
                                        ]
                                    )
                                        ? (string) $house[
                                            'crest_image'
                                        ]
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

                            $houseStyle =
                                '--ledger-house-display: '
                                . $houseDisplayColor
                                . '; '
                                . '--ledger-house-primary: '
                                . $housePrimaryColor
                                . '; '
                                . '--ledger-house-secondary: '
                                . $houseSecondaryColor
                                . '; '
                                . '--ledger-house-accent: '
                                . $houseAccentColor
                                . '; '
                                . '--ledger-house-dark: '
                                . $houseDarkColor
                                . '; '
                                . '--ledger-house-highlight: '
                                . $houseHighlightColor
                                . ';';
                            ?>

                            <section
                                class="living-ledger-house"
                                style="<?= e($houseStyle); ?>"
                                aria-labelledby="living-ledger-house-<?= $houseId; ?>"
                            >

                                <header class="living-ledger-house-header">

                                    <div class="living-ledger-house-crest-wrap">

                                        <?php if ($crestUrl !== null): ?>

                                            <picture>
                                                <?php if (
                                                    $crestWebpUrl !== null
                                                    && $crestWebpUrl !== $crestUrl
                                                ): ?>
                                                    <source
                                                        srcset="<?= e($crestWebpUrl); ?>"
                                                        type="image/webp"
                                                    >
                                                <?php endif; ?>

                                                <img
                                                    class="living-ledger-house-crest"
                                                    src="<?= e($crestUrl); ?>"
                                                    alt="<?= e($houseName . ' House crest'); ?>"
                                                    loading="lazy"
                                                >
                                            </picture>

                                        <?php else: ?>

                                            <span
                                                class="living-ledger-house-crest-fallback"
                                                aria-hidden="true"
                                            >
                                                <?= e(
                                                    function_exists(
                                                        'mb_substr'
                                                    )
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

                                    </div>

                                    <div class="living-ledger-house-heading">

                                        <p class="living-ledger-house-kicker">
                                            House
                                        </p>

                                        <h2
                                            id="living-ledger-house-<?= $houseId; ?>"
                                        >
                                            <?= e($houseName); ?>
                                        </h2>

                                        <p class="living-ledger-house-count">
                                            <?php if ($houseMembers === []): ?>
                                                No one currently recorded
                                                in these halls.
                                            <?php else: ?>
                                                <?= number_format(
                                                    count(
                                                        $houseMembers
                                                    )
                                                ); ?>
                                                <?= count($houseMembers) === 1
                                                    ? 'member'
                                                    : 'members'; ?>
                                                currently online
                                            <?php endif; ?>
                                        </p>

                                    </div>

                                </header>


                                <div class="living-ledger-house-body">

                                    <?php if ($houseMembers === []): ?>

                                        <p class="living-ledger-house-empty">
                                            The ink remains still. No
                                            <?= e($houseName); ?> members are
                                            currently online.
                                        </p>

                                    <?php else: ?>

                                        <div class="living-ledger-member-list">

                                            <?php foreach ($houseMembers as $member): ?>
                                                <?php
                                                $memberId =
                                                    (int) (
                                                        $member[
                                                            'id'
                                                        ]
                                                        ?? 0
                                                    );

                                                $memberName =
                                                    trim(
                                                        (string) (
                                                            $member[
                                                                'display_name'
                                                            ]
                                                            ?? $member[
                                                                'username'
                                                            ]
                                                            ?? 'Member'
                                                        )
                                                    );

                                                if ($memberName === '') {
                                                    $memberName =
                                                        'Member';
                                                }

                                                $isStaff =
                                                    (int) (
                                                        $member[
                                                            'is_staff'
                                                        ]
                                                        ?? 0
                                                    ) === 1;

                                                $staffRoleName =
                                                    trim(
                                                        (string) (
                                                            $member[
                                                                'staff_role_name'
                                                            ]
                                                            ?? ''
                                                        )
                                                    );

                                                if (
                                                    $isStaff
                                                    && $staffRoleName === ''
                                                ) {
                                                    $staffRoleName =
                                                        'Staff';
                                                }
                                                ?>

                                                <div class="living-ledger-member">

                                                    <span
                                                        class="living-ledger-online-mark"
                                                        aria-hidden="true"
                                                    ></span>

                                                    <a
                                                        class="living-ledger-member-name"
                                                        href="<?= e(
                                                            url(
                                                                'profile.php?u='
                                                                . $memberId
                                                            )
                                                        ); ?>"
                                                        <?= user_display_name_style_attr(
                                                            $memberId,
                                                            $houseDisplayColor
                                                        ); ?>
                                                    >
                                                        <?= e($memberName); ?>
                                                    </a>

                                                    <?php if ($isStaff): ?>
                                                        <span class="living-ledger-staff-mark">
                                                            <?= e($staffRoleName); ?>
                                                        </span>
                                                    <?php endif; ?>

                                                </div>

                                            <?php endforeach; ?>

                                        </div>

                                    <?php endif; ?>

                                </div>

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
