<?php

/**
 * Blackthorne Academy
 * Shared Member Sidebar
 *
 * This include is self-contained so authenticated member pages can reuse
 * the exact same sidebar structure as the logged-in homepage.
 *
 * Expected:
 * - bootstrap.php already loaded
 * - authenticated, active user
 */

require_once INCLUDES_PATH . '/forum-functions.php';

$sidebarUser =
    current_user();

if ($sidebarUser === null) {
    return;
}

$sidebarUserId =
    (int) (
        $sidebarUser['id']
        ?? current_user_id()
        ?? 0
    );

if ($sidebarUserId <= 0) {
    return;
}


$sidebarDisplayName =
    trim(
        (string) (
            $sidebarUser['display_name']
            ?? $sidebarUser['username']
            ?? 'Scholar'
        )
    );

if ($sidebarDisplayName === '') {
    $sidebarDisplayName =
        'Scholar';
}


$sidebarRoleName =
    'Registered User';

if (current_user_is_superuser()) {
    $sidebarRoleName =
        'Admin';

} else {
    $sidebarAssignedRoles =
        current_user_roles();

    foreach ($sidebarAssignedRoles as $sidebarAssignedRole) {
        $sidebarAssignedRoleName =
            trim(
                (string) (
                    $sidebarAssignedRole['name']
                    ?? ''
                )
            );

        if (
            $sidebarAssignedRoleName !== ''
            && (int) (
                $sidebarAssignedRole['is_staff']
                ?? 0
            ) === 1
        ) {
            $sidebarRoleName =
                $sidebarAssignedRoleName;

            break;
        }
    }

    if ($sidebarRoleName === 'Registered User') {
        foreach ($sidebarAssignedRoles as $sidebarAssignedRole) {
            $sidebarAssignedRoleName =
                trim(
                    (string) (
                        $sidebarAssignedRole['name']
                        ?? ''
                    )
                );

            if ($sidebarAssignedRoleName !== '') {
                $sidebarRoleName =
                    $sidebarAssignedRoleName;

                break;
            }
        }
    }

    if (
        $sidebarRoleName === 'Registered User'
        && current_user_has_student_enrollment()
    ) {
        $sidebarRoleName =
            'Student';
    }
}


$sidebarData = [
    'user_id'          => $sidebarUserId,
    'display_name'     => $sidebarDisplayName,
    'role_name'        => $sidebarRoleName,
    'house'            => null,
    'class_year'       => null,
    'unread_messages'  => 0,
    'online_friends'   => null,
    'forum_categories' => [],
];


try {
    /*
     * Current active House membership.
     */
    $sidebarStatement =
        $pdo->prepare(
            'SELECT
                h.id,
                h.name,
                h.display_name,
                h.display_color,
                h.primary_color,
                h.common_room_forum_id

             FROM house_memberships hm

             INNER JOIN houses h
                ON h.id = hm.house_id

             WHERE hm.user_id = :user_id
               AND hm.membership_status = "active"
               AND hm.left_at IS NULL
               AND h.is_active = 1

             ORDER BY
                hm.joined_at DESC,
                hm.id DESC

             LIMIT 1'
        );

    $sidebarStatement->execute([
        'user_id' =>
            $sidebarUserId,
    ]);

    $sidebarHouse =
        $sidebarStatement->fetch(
            PDO::FETCH_ASSOC
        );

    if ($sidebarHouse) {
        $sidebarData['house'] =
            $sidebarHouse;
    }


    /*
     * Current class/year group.
     */
    $sidebarStatement =
        $pdo->prepare(
            'SELECT yg.name

             FROM student_year_enrollments sye

             INNER JOIN year_groups yg
                ON yg.id = sye.year_group_id

             INNER JOIN school_years sy
                ON sy.id = sye.school_year_id

             WHERE sye.user_id = :user_id
               AND sye.promotion_status IN (
                    "active",
                    "eligible",
                    "not_eligible",
                    "repeating"
               )

             ORDER BY
                sy.is_current DESC,
                sye.started_at DESC

             LIMIT 1'
        );

    $sidebarStatement->execute([
        'user_id' =>
            $sidebarUserId,
    ]);

    $sidebarClassYear =
        $sidebarStatement->fetchColumn();

    if (
        is_string($sidebarClassYear)
        && $sidebarClassYear !== ''
    ) {
        $sidebarData['class_year'] =
            $sidebarClassYear;
    }


    /*
     * Unread private messages.
     */
    $sidebarStatement =
        $pdo->prepare(
            'SELECT COUNT(DISTINCT pm.id)

             FROM conversation_participants cp

             INNER JOIN private_messages pm
                ON pm.conversation_id = cp.conversation_id

             LEFT JOIN private_message_user_states pmus
                ON pmus.message_id = pm.id
               AND pmus.user_id = cp.user_id

             WHERE cp.user_id = :user_id
               AND cp.left_at IS NULL
               AND pm.sender_id <> :sender_id
               AND (
                    cp.last_read_at IS NULL
                    OR pm.created_at > cp.last_read_at
               )
               AND COALESCE(pmus.is_hidden, 0) = 0'
        );

    $sidebarStatement->execute([
        'user_id' =>
            $sidebarUserId,

        'sender_id' =>
            $sidebarUserId,
    ]);

    $sidebarData['unread_messages'] =
        (int) $sidebarStatement->fetchColumn();


    /*
     * Online friends.
     *
     * A friend counts as online when their presence was refreshed within the
     * same five-minute window used by the profile/friends system. Members who
     * hide online status are intentionally excluded from the displayed count.
     */
    $sidebarStatement =
        $pdo->prepare(
            'SELECT COUNT(*)

             FROM user_friendships uf

             INNER JOIN users friend_user
                ON friend_user.id =
                    CASE
                        WHEN uf.user_low_id = :friend_user_id_case
                            THEN uf.user_high_id
                        ELSE uf.user_low_id
                    END

             LEFT JOIN user_profiles friend_profile
                ON friend_profile.user_id = friend_user.id

             INNER JOIN user_presence friend_presence
                ON friend_presence.user_id = friend_user.id

             WHERE uf.status = "accepted"
               AND (
                    uf.user_low_id = :friend_user_id_low
                    OR uf.user_high_id = :friend_user_id_high
               )
               AND friend_user.status = "active"
               AND COALESCE(
                    friend_profile.show_online_status,
                    1
               ) = 1
               AND friend_presence.last_seen_at >=
                    DATE_SUB(
                        CURRENT_TIMESTAMP,
                        INTERVAL 5 MINUTE
                    )'
        );

    $sidebarStatement->execute([
        'friend_user_id_case' =>
            $sidebarUserId,

        'friend_user_id_low' =>
            $sidebarUserId,

        'friend_user_id_high' =>
            $sidebarUserId,
    ]);

    $sidebarData['online_friends'] =
        (int) $sidebarStatement->fetchColumn();


    /*
     * Sidebar forum categories and accessible main boards.
     */
    $sidebarCategoryStatement =
        $pdo->prepare(
            'SELECT
                fc.id,
                fc.title,
                fc.slug,
                fc.description,
                fc.sort_order

             FROM forum_categories fc

             WHERE fc.is_visible = 1
               AND fc.show_in_sidebar = 1
               AND (
                    fc.access_mode = "public"

                    OR EXISTS (
                        SELECT 1

                        FROM forum_category_user_access fcua

                        WHERE fcua.category_id = fc.id
                          AND fcua.user_id = :category_user_id
                          AND fcua.can_view_category = 1
                    )

                    OR EXISTS (
                        SELECT 1

                        FROM user_roles ur

                        INNER JOIN forum_category_role_access fcra
                            ON fcra.role_id = ur.role_id

                        WHERE ur.user_id = :category_role_user_id
                          AND ur.is_active = 1
                          AND ur.revoked_at IS NULL
                          AND (
                                ur.expires_at IS NULL
                                OR ur.expires_at > NOW()
                          )
                          AND fcra.category_id = fc.id
                          AND fcra.can_view_category = 1
                    )
               )

             ORDER BY
                fc.sort_order ASC,
                fc.title ASC'
        );

    $sidebarCategoryStatement->execute([
        'category_user_id' =>
            $sidebarUserId,

        'category_role_user_id' =>
            $sidebarUserId,
    ]);

    $sidebarCategories =
        $sidebarCategoryStatement->fetchAll(
            PDO::FETCH_ASSOC
        );


    /*
     * Global sidebar navigation intentionally contains only top-level forums.
     * Nested sub-forums belong inside their parent forum pages, regardless of
     * how many hierarchy levels exist.
     */
    $sidebarForumStatement =
        $pdo->prepare(
            'SELECT
                f.id,
                f.category_id,
                f.parent_forum_id,
                f.title,
                f.slug,
                f.description,
                f.sort_order

             FROM forums f

             WHERE f.category_id = :category_id
               AND f.parent_forum_id IS NULL
               AND f.forum_type = "general"
               AND f.is_visible = 1

             ORDER BY
                f.sort_order ASC,
                f.title ASC'
        );



    foreach ($sidebarCategories as $sidebarCategory) {
        $sidebarCategoryId =
            (int) $sidebarCategory['id'];

        $sidebarForumStatement->execute([
            'category_id' =>
                $sidebarCategoryId,
        ]);

        $sidebarAccessibleForums =
            array_values(
                array_filter(
                    $sidebarForumStatement->fetchAll(
                        PDO::FETCH_ASSOC
                    ),
                    static function (array $forum) use ($pdo, $sidebarUserId): bool {
                        return forum_can_view_forum(
                            $pdo,
                            (int) $forum['id'],
                            $sidebarUserId
                        );
                    }
                )
            );

        /*
         * The query already guarantees these are top-level forums only.
         * Keep the sidebar data flat so deeper forum nesting never changes
         * global navigation behavior.
         */
        $sidebarCategory['forums'] =
            $sidebarAccessibleForums;

        $sidebarData[
            'forum_categories'
        ][] =
            $sidebarCategory;
    }

} catch (Throwable $sidebarException) {
    /*
     * The sidebar remains usable even if one optional data source fails.
     * Core navigation and member identity still render.
     */
}


$sidebarHouseDisplayColor = null;

if (is_array($sidebarData['house'])) {
    $sidebarHouseDisplayColor =
        safe_css_color(
            (string) (
                $sidebarData['house']['display_color']
                ?? ''
            )
        );
}


$profileUrl =
    url(
        'profile.php?u='
        . $sidebarUserId
    );

$housePoints =
    0;

$homeworkPoints =
    0;


$showModeratorTools =
    user_can(
        'forums.tools.sidebar'
    );

$canOpenForumManagement =
    user_can(
        'forums.admin.access'
    )
    || user_can(
        'manage_forums'
    );

$canOpenReportManagement =
    user_can_any([
        'moderation.reports.view',
        'moderation.reports.review',
        'moderation.reports.resolve',
        'moderation.reports.dismiss',
    ]);

$pendingModerationReportCount =
    0;

if ($canOpenReportManagement) {
    try {
        $sidebarReportStatement =
            $pdo->query(
                'SELECT COUNT(*)
                 FROM forum_reports
                 WHERE status IN (
                    "open",
                    "reviewing"
                 )'
            );

        $pendingModerationReportCount =
            (int) $sidebarReportStatement
                ->fetchColumn();

    } catch (Throwable $sidebarReportException) {
        $pendingModerationReportCount =
            0;
    }
}


$easternTime =
    new DateTimeImmutable(
        'now',
        new DateTimeZone(
            'America/New_York'
        )
    );

$easternDateTime =
    $easternTime->format(
        'l, F jS, Y - g:i A'
    );

?>

<button class="member-sidebar-mobile-toggle" type="button" aria-expanded="false" aria-controls="member-sidebar-drawer"
    data-member-sidebar-open>
    <span aria-hidden="true">☰</span>
    <span>Open Sidebar</span>
</button>

<div class="member-sidebar-backdrop" data-member-sidebar-backdrop hidden></div>

<aside class="member-sidebar" id="member-sidebar-drawer" aria-label="Member and academy navigation">

    <div class="member-sidebar-mobile-header">
        <span>Academy Sidebar</span>

        <button class="member-sidebar-mobile-close" type="button" aria-label="Close sidebar" data-member-sidebar-close>
            <span aria-hidden="true">×</span>
        </button>
    </div>


    <!-- ========================================================
                 USER INFORMATION
            ========================================================= -->

    <section class="sidebar-panel">

        <div class="sidebar-titlebar">

            <h2>
                <a href="<?= e($profileUrl); ?>" <?php if ($sidebarHouseDisplayColor !== null): ?>
                    style="color: <?= e($sidebarHouseDisplayColor); ?>;" <?php endif; ?>>
                    <?= e(
                                (string) $sidebarData[
                                    'display_name'
                                ]
                            ); ?>
                </a>
            </h2>

            <button class="sidebar-collapse-toggle" type="button" aria-expanded="true"
                aria-controls="sidebar-profile-content">

                <span class="sr-only">
                    Toggle profile information
                </span>

                <span class="sidebar-toggle-mark" aria-hidden="true">
                    −
                </span>

            </button>

        </div>


        <div class="sidebar-panel-content sidebar-profile-details" id="sidebar-profile-content">

            <?php if (
                        is_array(
                            $sidebarData['house']
                        )
                    ): ?>

            <?php

                        $houseColor =
                            $sidebarHouseDisplayColor;

                        $sidebarHouseName =
                            trim(
                                (string) (
                                    $sidebarData['house']['display_name']
                                    ?? ''
                                )
                            );

                        if ($sidebarHouseName === '') {
                            $sidebarHouseName =
                                (string) $sidebarData['house']['name'];
                        }

                        ?>

            <p>

                <span class="sidebar-house-name" <?php if ($houseColor !== null): ?>
                    style="color: <?= e($houseColor); ?>;" <?php endif; ?>>
                    <?= e($sidebarHouseName); ?>
                </span>

            </p>

            <?php else: ?>

            <p>

                <a href="<?= e(
                                url(
                                    'sorting-ceremony.php'
                                )
                            ); ?>">
                    Get Sorted
                </a>

            </p>

            <?php endif; ?>


            <p class="sidebar-user-role">

                <em>
                    <?= e(
                                (string) $sidebarData[
                                    'role_name'
                                ]
                            ); ?>
                </em>

            </p>


            <div class="sidebar-user-points">

                <p>
                    <span>
                        House Points:
                    </span>

                    <?= number_format($housePoints); ?>
                </p>

                <p>
                    <span>
                        HW Points:
                    </span>

                    <?= number_format($homeworkPoints); ?>
                </p>

            </div>


            <?php if (
                        is_string(
                            $sidebarData['class_year']
                        )
                    ): ?>

            <p class="sidebar-class-year">

                <?= e(
                                $sidebarData['class_year']
                            ); ?>

            </p>

            <?php endif; ?>

        </div>

    </section>


    <?php if ($showModeratorTools): ?>

    <!-- ====================================================
                     MODERATOR TOOLS
                ===================================================== -->

    <section class="sidebar-panel">

        <div class="sidebar-titlebar">

            <h2>
                Moderator Tools
            </h2>

            <button class="sidebar-collapse-toggle" type="button" aria-expanded="true"
                aria-controls="sidebar-moderator-tools-content">

                <span class="sr-only">
                    Toggle Moderator Tools links
                </span>

                <span class="sidebar-toggle-mark" aria-hidden="true">
                    −
                </span>

            </button>

        </div>


        <nav class="sidebar-panel-content sidebar-link-list" id="sidebar-moderator-tools-content"
            aria-label="Moderator Tools">

            <a href="<?= e(
                            url(
                                'forums.php'
                            )
                        ); ?>">
                Forums
            </a>

            <?php if ($canOpenForumManagement): ?>

            <a href="<?= e(
                                url(
                                    'admin/forums.php'
                                )
                            ); ?>">
                Forum Management
            </a>

            <?php endif; ?>


            <?php if ($canOpenReportManagement): ?>

            <a href="<?= e(
                                url(
                                    'admin/reports.php'
                                )
                            ); ?>">
                Reports &amp; Moderation<?php if ($pendingModerationReportCount > 0): ?>
                (<?= number_format($pendingModerationReportCount); ?>)
                <?php endif; ?>
            </a>

            <?php endif; ?>

        </nav>

    </section>

    <?php endif; ?>


    <!-- ========================================================
                 WELCOME
            ========================================================= -->

    <section class="sidebar-panel">

        <div class="sidebar-titlebar">

            <h2>
                Welcome
            </h2>

            <button class="sidebar-collapse-toggle" type="button" aria-expanded="true"
                aria-controls="sidebar-welcome-content">

                <span class="sr-only">
                    Toggle Welcome links
                </span>

                <span class="sidebar-toggle-mark" aria-hidden="true">
                    −
                </span>

            </button>

        </div>


        <nav class="sidebar-panel-content sidebar-link-list" id="sidebar-welcome-content" aria-label="Welcome">

            <?php if (
                        $sidebarData['class_year'] === null
                    ): ?>

            <a href="<?= e(
                            url(
                                'course.php?slug=academy-orientation'
                            )
                        ); ?>">
                Orientation
            </a>

            <?php endif; ?>


            <?php if (
                        $sidebarData['house'] === null
                    ): ?>

            <a href="<?= e(
                            url(
                                'sorting-ceremony.php'
                            )
                        ); ?>">
                Get Sorted
            </a>

            <?php endif; ?>


            <a href="<?= e(
                        url(
                            'knowledge-base.php'
                        )
                    ); ?>">
                Knowledge Base
            </a>

            <a href="<?= e(
                        url(
                            'faq.php'
                        )
                    ); ?>">
                FAQ
            </a>

            <a href="<?= e(
                        url(
                            'forums.php?board=support'
                        )
                    ); ?>">
                Support
            </a>

        </nav>

    </section>


    <!-- ========================================================
                 INTERACT
            ========================================================= -->

    <section class="sidebar-panel">

        <div class="sidebar-titlebar">

            <h2>
                Interact
            </h2>

            <button class="sidebar-collapse-toggle" type="button" aria-expanded="true"
                aria-controls="sidebar-interact-content">

                <span class="sr-only">
                    Toggle Interact links
                </span>

                <span class="sidebar-toggle-mark" aria-hidden="true">
                    −
                </span>

            </button>

        </div>


        <nav class="sidebar-panel-content sidebar-link-list" id="sidebar-interact-content" aria-label="Interact">

            <a href="<?= e(
                        url(
                            'messages.php'
                        )
                    ); ?>">
                Messages<?php if (
                            (int) $sidebarData[
                                'unread_messages'
                            ] > 0
                        ): ?>
                (<?= number_format(
                                (int) $sidebarData[
                                    'unread_messages'
                                ]
                            ); ?>)<?php endif; ?>
            </a>

            <a href="<?= e(
                        url(
                            'friends.php'
                        )
                    ); ?>">
                Friends<?php if (
                            is_int(
                                $sidebarData[
                                    'online_friends'
                                ]
                            )
                            &&
                            $sidebarData[
                                'online_friends'
                            ] > 0
                        ): ?>
                (<?= number_format(
                                $sidebarData[
                                    'online_friends'
                                ]
                            ); ?>)<?php endif; ?>
            </a>

            <?php if (is_array($sidebarData['house'])): ?>
            <a href="<?= e(url('common-room.php')); ?>">
                Common Room
            </a>
            <?php endif; ?>

            <a href="<?= e(
                        url(
                            'living-ledger.php'
                        )
                    ); ?>">
                The Living Ledger
            </a>

            <a href="<?= e(
                        url(
                            'clubs.php'
                        )
                    ); ?>">
                Clubs
            </a>

            <a href="<?= e(
                        url(
                            'dorms.php'
                        )
                    ); ?>">
                Dorms
            </a>

        </nav>

    </section>


    <!-- ========================================================
                 DYNAMIC FORUM CATEGORIES
            ========================================================= -->

    <?php foreach (
                $sidebarData['forum_categories']
                as $category
            ): ?>

    <?php

                $categoryId =
                    (int) $category['id'];

                $panelId =
                    'sidebar-forum-category-' .
                    $categoryId;

                $categoryForums =
                    is_array(
                        $category['forums']
                        ?? null
                    )
                        ? $category['forums']
                        : [];

                ?>

    <section class="sidebar-panel sidebar-forum-panel" data-forum-category-id="<?= $categoryId; ?>">

        <div class="sidebar-titlebar">

            <h2>
                <?= e(
                                (string) $category['title']
                            ); ?>
            </h2>

            <button class="sidebar-collapse-toggle" type="button" aria-expanded="true"
                aria-controls="<?= e($panelId); ?>">

                <span class="sr-only">
                    Toggle <?= e(
                                    (string) $category['title']
                                ); ?> boards
                </span>

                <span class="sidebar-toggle-mark" aria-hidden="true">
                    −
                </span>

            </button>

        </div>


        <nav class="sidebar-panel-content sidebar-forum-navigation" id="<?= e($panelId); ?>" aria-label="<?= e(
                            (string) $category['title']
                        ); ?> boards">

            <?php if ($categoryForums === []): ?>

            <p class="sidebar-forum-empty">
                No boards have been added yet.
            </p>

            <?php else: ?>

            <?php foreach (
                                $categoryForums
                                as $sidebarForum
                            ): ?>

            <?php

                                $sidebarForumId =
                                    (int) $sidebarForum['id'];

                                ?>

            <div class="sidebar-forum-group" data-forum-id="<?= $sidebarForumId; ?>">

                <a class="sidebar-forum-link sidebar-forum-main-link" href="<?= e(
                                            url(
                                                'forum.php?f=' .
                                                $sidebarForumId
                                            )
                                        ); ?>">
                    <?= e(
                                            (string) $sidebarForum['title']
                                        ); ?>
                </a>

            </div>

            <?php endforeach; ?>

            <?php endif; ?>

        </nav>

    </section>

    <?php endforeach; ?>

</aside>

<script>
    (() => {
        'use strict';

        const sidebar = document.getElementById('member-sidebar-drawer');
        const openButton = document.querySelector('[data-member-sidebar-open]');
        const closeButton = document.querySelector('[data-member-sidebar-close]');
        const backdrop = document.querySelector('[data-member-sidebar-backdrop]');

        if (!sidebar || !openButton || !closeButton || !backdrop) {
            return;
        }

        const mobileMedia = window.matchMedia('(max-width: 760px)');

        const openSidebar = () => {
            if (!mobileMedia.matches) return;

            sidebar.classList.add('is-mobile-open');
            backdrop.hidden = false;

            requestAnimationFrame(() => {
                backdrop.classList.add('is-visible');
            });

            openButton.setAttribute('aria-expanded', 'true');
            document.body.classList.add('member-sidebar-open');

            closeButton.focus({
                preventScroll: true
            });
        };

        const closeSidebar = (restoreFocus = true) => {
            sidebar.classList.remove('is-mobile-open');
            backdrop.classList.remove('is-visible');
            openButton.setAttribute('aria-expanded', 'false');
            document.body.classList.remove('member-sidebar-open');

            window.setTimeout(() => {
                if (!backdrop.classList.contains('is-visible')) {
                    backdrop.hidden = true;
                }
            }, 220);

            if (restoreFocus && mobileMedia.matches) {
                openButton.focus({
                    preventScroll: true
                });
            }
        };

        openButton.addEventListener('click', openSidebar);
        closeButton.addEventListener('click', () => closeSidebar());
        backdrop.addEventListener('click', () => closeSidebar());

        document.addEventListener('keydown', (event) => {
            if (
                event.key === 'Escape' &&
                sidebar.classList.contains('is-mobile-open')
            ) {
                closeSidebar();
            }
        });

        sidebar.addEventListener('click', (event) => {
            if (!mobileMedia.matches) return;

            const link = event.target.closest('a');
            if (link) {
                closeSidebar(false);
            }
        });

        const resetForViewport = () => {
            if (!mobileMedia.matches) {
                sidebar.classList.remove('is-mobile-open');
                backdrop.classList.remove('is-visible');
                backdrop.hidden = true;
                openButton.setAttribute('aria-expanded', 'false');
                document.body.classList.remove('member-sidebar-open');
            }
        };

        if (typeof mobileMedia.addEventListener === 'function') {
            mobileMedia.addEventListener('change', resetForViewport);
        } else {
            mobileMedia.addListener(resetForViewport);
        }

        resetForViewport();
    })();

</script>
