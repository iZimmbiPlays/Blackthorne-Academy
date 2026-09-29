<?php

declare(strict_types=1);

/**
 * Blackthorne Academy
 * Registered-Member Homepage
 *
 * Available data is prepared by index.php in $registeredHomeData.
 */

$profileUrl =
    url(
        'profile.php?u=' .
        (int) $registeredHomeData['user_id']
    );

$onlineCount =
    (int) $registeredHomeData['online_count'];

$onlineLabel =
    $onlineCount === 1
        ? 'Scholar Within the Halls'
        : 'Scholars Within the Halls';


/*
|--------------------------------------------------------------------------
| Points
|--------------------------------------------------------------------------
|
| These values default to zero until the point system is connected. Later,
| index.php can supply each user's current school-year totals using the
| house_points and homework_points keys.
|
*/

$housePoints =
    (int) (
        $registeredHomeData['house_points']
        ?? 0
    );

$homeworkPoints =
    (int) (
        $registeredHomeData['homework_points']
        ?? 0
    );


/*
|--------------------------------------------------------------------------
| Moderator Tools Sidebar
|--------------------------------------------------------------------------
|
| Visibility is controlled by a dedicated permission so each role/group can
| explicitly opt into this homepage section. Individual links remain
| capability-based as well.
|
*/

$showModeratorTools =
    user_can('forums.tools.sidebar');

$canOpenForumManagement =
    user_can('forums.admin.access')
    || user_can('manage_forums');

$canOpenReportManagement =
    user_can_any([
        'moderation.reports.view',
        'moderation.reports.review',
        'moderation.reports.resolve',
        'moderation.reports.dismiss',
    ]);


/*
|--------------------------------------------------------------------------
| Pending Moderation Reports
|--------------------------------------------------------------------------
|
| Only reports that still need staff attention are counted here. Resolved
| and dismissed reports are intentionally excluded.
|
*/

$pendingModerationReportCount =
    0;

if ($canOpenReportManagement) {
    $pendingModerationReportStatement =
        $pdo->query(
            'SELECT COUNT(*)
             FROM forum_reports
             WHERE status IN ("open", "reviewing")'
        );

    $pendingModerationReportCount =
        (int) $pendingModerationReportStatement->fetchColumn();
}


/*
|--------------------------------------------------------------------------
| Eastern Date and Time
|--------------------------------------------------------------------------
*/

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

<main id="main-content" class="member-homepage">


    <!-- ================================================================
         REGISTERED-MEMBER HERO
    ================================================================= -->

    <section class="member-home-hero" aria-labelledby="member-home-heading">

        <div class="member-home-hero-overlay">

            <div class="section-inner member-home-hero-inner">

                <h1 id="member-home-heading">
                    Blackthorne Academy
                </h1>

                <p class="member-home-slogan">
                    Where curiosity becomes craft.
                </p>

                <p class="member-home-online-count">

                    <span aria-hidden="true">
                        ✦
                    </span>

                    <?= number_format($onlineCount); ?>
                    <?= e($onlineLabel); ?>

                    <span aria-hidden="true">
                        ✦
                    </span>

                </p>

            </div>

        </div>

    </section>


    <!-- ================================================================
         REGISTERED-MEMBER CONTENT
    ================================================================= -->

    <div class="section-inner member-home-layout">


        <!-- ============================================================
             SHARED MEMBER SIDEBAR
        ============================================================= -->

        <?php require INCLUDES_PATH . '/member-sidebar.php'; ?>


        <!-- ============================================================
             ANNOUNCEMENTS AND EVENTS
        ============================================================= -->

        <section class="member-home-main" aria-labelledby="announcements-events-heading">

            <p class="member-home-datetime" data-eastern-datetime data-server-time="<?= e(
                    $easternTime->format(
                        DateTimeInterface::ATOM
                    )
                ); ?>">
                <?= e($easternDateTime); ?>
            </p>


            <header class="member-content-titlebar">

                <h2 id="announcements-events-heading">
                    Announcements / Events
                </h2>

            </header>


            <div class="announcement-list">

                <?php if (
                    $registeredHomeData['announcements'] === []
                ): ?>

                <div class="announcement-empty-state">

                    <p>
                        There are no announcements or events posted yet.
                        When academy news arrives, it will appear here.
                    </p>

                </div>

                <?php else: ?>

                <?php foreach (
                        $registeredHomeData['announcements']
                        as $announcement
                    ): ?>

                <article class="announcement-entry">

                    <h3>

                        <a href="<?= e(
                                        url(
                                            'thread.php?t=' .
                                            (int) $announcement['id']
                                        )
                                    ); ?>">
                            <?= e(
                                        (string) $announcement[
                                            'title'
                                        ]
                                    ); ?>
                        </a>

                    </h3>


                    <p class="announcement-byline">

                        by

                        <?php
                                $announcementAuthorHouseColor =
                                    safe_css_color(
                                        (string) (
                                            $announcement[
                                                'author_house_color'
                                            ]
                                            ?? ''
                                        )
                                    );
                                ?>

                        <a href="<?= e(
                                        url(
                                            'profile.php?u=' .
                                            (int) $announcement[
                                                'author_id'
                                            ]
                                        )
                                    ); ?>" <?php if ($announcementAuthorHouseColor !== null): ?>
                            style="color: <?= e($announcementAuthorHouseColor); ?>;" <?php endif; ?>>
                            <?= e(
                                        (string) $announcement[
                                            'author_display_name'
                                        ]
                                    ); ?>
                        </a>

                        <span aria-hidden="true">
                            •
                        </span>

                        <time datetime="<?= e(
                                        date(
                                            'Y-m-d',
                                            strtotime(
                                                (string) $announcement[
                                                    'created_at'
                                                ]
                                            )
                                        )
                                    ); ?>">
                            <?= e(
                                        date(
                                            'F j, Y',
                                            strtotime(
                                                (string) $announcement[
                                                    'created_at'
                                                ]
                                            )
                                        )
                                    ); ?>
                        </time>

                    </p>


                    <div class="announcement-content rich-text-content">
                        <?= sanitize_rich_text(
                                    (string) $announcement['content']
                                ); ?>
                    </div>


                    <p class="announcement-actions">

                        <span>
                            Replies (<?= number_format(
                                        (int) $announcement[
                                            'reply_count'
                                        ]
                                    ); ?>)
                        </span>

                        <?php if (
                                    (int) $announcement[
                                        'is_locked'
                                    ] !== 1
                                ): ?>

                        <span aria-hidden="true">
                            |
                        </span>

                        <a href="<?= e(
                                            url(
                                                'thread.php?t=' .
                                                (int) $announcement['id'] .
                                                '#respond'
                                            )
                                        ); ?>">
                            Respond
                        </a>

                        <?php endif; ?>

                    </p>

                </article>

                <?php endforeach; ?>

                <?php endif; ?>

            </div>


            <div class="announcement-view-all">

                <a class="button button-secondary" href="<?= e(
                        url(
                            'announcements.php'
                        )
                    ); ?>">
                    View All
                </a>

            </div>

        </section>

    </div>

</main>
