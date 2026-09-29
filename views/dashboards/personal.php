<?php

declare(strict_types=1);

require_once INCLUDES_PATH . '/points-functions.php';

$personalDashboardMode = isset($personalDashboardMode) && $personalDashboardMode === 'student'
    ? 'student'
    : 'registered';

$isStudentDashboard = $personalDashboardMode === 'student';
$userId = (int) ($user['id'] ?? 0);

$dashboardCounts = [
    'unread_notifications' => 0,
    'friends' => 0,
    'active_courses' => 0,
    'posts' => 0,
    'threads' => 0,
    'likes_received' => 0,
];

$orientationCourse = null;
$orientationCompleted = false;
$currentYearGroup = null;
$orientationCourseId = 0;
$orientationCourseHref = '';
$recentActivity = [];
$latestHouseNews = null;
$academicProgress = null;

$dashboardOrdinal = static function (int $number): string {
    $mod100 = $number % 100;
    if ($mod100 >= 11 && $mod100 <= 13) {
        return $number . 'th';
    }

    return $number . match ($number % 10) {
        1 => 'st',
        2 => 'nd',
        3 => 'rd',
        default => 'th',
    };
};

$dashboardDate = static function (?string $value): string {
    if ($value === null || trim($value) === '') {
        return '';
    }

    try {
        $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $date
            ->setTimezone(new DateTimeZone('America/New_York'))
            ->format('M j, Y · g:i A');
    } catch (Throwable) {
        return '';
    }
};

$dashboardExcerpt = static function (string $value, int $limit = 220): string {
    $plain = trim((string) preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
    if ($plain === '') {
        return '';
    }

    $length = function_exists('mb_strlen') ? mb_strlen($plain, 'UTF-8') : strlen($plain);
    if ($length <= $limit) {
        return $plain;
    }

    $cut = function_exists('mb_substr')
        ? mb_substr($plain, 0, $limit - 1, 'UTF-8')
        : substr($plain, 0, $limit - 1);

    return rtrim($cut) . '…';
};

try {
    $notificationStatement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM notifications
         WHERE user_id = :user_id
           AND is_read = 0'
    );
    $notificationStatement->execute(['user_id' => $userId]);
    $dashboardCounts['unread_notifications'] = (int) $notificationStatement->fetchColumn();

    $friendStatement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM user_friendships
         WHERE status = \'accepted\'
           AND (user_low_id = :user_id OR user_high_id = :user_id)'
    );
    $friendStatement->execute(['user_id' => $userId]);
    $dashboardCounts['friends'] = (int) $friendStatement->fetchColumn();

    $postCountStatement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM forum_posts
         WHERE user_id = :user_id
           AND is_deleted = 0'
    );
    $postCountStatement->execute(['user_id' => $userId]);
    $dashboardCounts['posts'] = (int) $postCountStatement->fetchColumn();

    $threadCountStatement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM forum_threads
         WHERE user_id = :user_id
           AND is_deleted = 0'
    );
    $threadCountStatement->execute(['user_id' => $userId]);
    $dashboardCounts['threads'] = (int) $threadCountStatement->fetchColumn();

    $likesStatement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM forum_reactions fr
         INNER JOIN forum_posts fp
            ON fp.id = fr.post_id
         WHERE fp.user_id = :user_id
           AND fp.is_deleted = 0
           AND fr.reaction_type = \'like\''
    );
    $likesStatement->execute(['user_id' => $userId]);
    $dashboardCounts['likes_received'] = (int) $likesStatement->fetchColumn();

    $courseStatement = $pdo->prepare(
        "SELECT COUNT(*)
         FROM course_enrollments
         WHERE user_id = :user_id
           AND status = 'enrolled'"
    );
    $courseStatement->execute(['user_id' => $userId]);
    $dashboardCounts['active_courses'] = (int) $courseStatement->fetchColumn();

    /*
     * Orientation is a normal Blackthorne course. Prefer the configured
     * orientation_course_id setting once the course system is built. The
     * reserved slug fallback keeps this ready before that setting exists.
     */
    $orientationSettingStatement = $pdo->prepare(
        "SELECT setting_value
         FROM settings
         WHERE setting_key = 'orientation_course_id'
         LIMIT 1"
    );
    $orientationSettingStatement->execute();
    $configuredOrientationId = (int) ($orientationSettingStatement->fetchColumn() ?: 0);

    if ($configuredOrientationId > 0) {
        $orientationStatement = $pdo->prepare(
            "SELECT id, title, slug, status
             FROM courses
             WHERE id = :course_id
             LIMIT 1"
        );
        $orientationStatement->execute(['course_id' => $configuredOrientationId]);
        $orientationCourse = $orientationStatement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (!is_array($orientationCourse)) {
        $orientationStatement = $pdo->prepare(
            "SELECT id, title, slug, status
             FROM courses
             WHERE slug = 'orientation'
             ORDER BY id
             LIMIT 1"
        );
        $orientationStatement->execute();
        $orientationCourse = $orientationStatement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (is_array($orientationCourse)) {
        $orientationCourseId = (int) ($orientationCourse['id'] ?? 0);

        if ($orientationCourseId > 0) {
            $orientationCourseHref = url('course.php?c=' . $orientationCourseId);

            $orientationCompletionStatement = $pdo->prepare(
                "SELECT 1
                 FROM course_enrollments ce
                 INNER JOIN course_offerings co
                    ON co.id = ce.offering_id
                 WHERE ce.user_id = :user_id
                   AND co.course_id = :course_id
                   AND ce.status = 'completed'
                 LIMIT 1"
            );
            $orientationCompletionStatement->execute([
                'user_id' => $userId,
                'course_id' => $orientationCourseId,
            ]);
            $orientationCompleted = $orientationCompletionStatement->fetchColumn() !== false;
        }
    }

    $yearGroupStatement = $pdo->prepare(
        "SELECT
            yg.id,
            yg.name,
            yg.year_number,
            sy.id AS school_year_id,
            sy.name AS school_year_name,
            sy.is_current,
            sye.id AS student_year_enrollment_id,
            sye.academic_points_earned,
            sye.academic_points_possible,
            sye.progress_percentage,
            sye.required_percentage_snapshot,
            sye.required_points_snapshot,
            sye.promotion_status,
            sye.eligible_at
         FROM student_year_enrollments sye
         INNER JOIN year_groups yg
            ON yg.id = sye.year_group_id
         INNER JOIN school_years sy
            ON sy.id = sye.school_year_id
         WHERE sye.user_id = :user_id
           AND sye.promotion_status IN ('active', 'eligible', 'repeating')
         ORDER BY sy.is_current DESC, sy.start_date DESC, sye.id DESC
         LIMIT 1"
    );
    $yearGroupStatement->execute(['user_id' => $userId]);
    $currentYearGroup = $yearGroupStatement->fetch(PDO::FETCH_ASSOC) ?: null;

    if (
        is_array($currentYearGroup)
        && (int) ($currentYearGroup['year_number'] ?? 0) > 0
        && (int) ($currentYearGroup['school_year_id'] ?? 0) > 0
    ) {
        $progressSchoolYearId =
            (int) $currentYearGroup['school_year_id'];

        $pointTotals = points_student_totals(
            $pdo,
            $userId,
            $progressSchoolYearId
        );

        $academicEarned =
            (float) ($pointTotals['academic'] ?? 0);

        $academicPossible =
            (float) ($currentYearGroup['academic_points_possible'] ?? 0);

        $progressPercentage =
            $academicPossible > 0
                ? round(
                    ($academicEarned / $academicPossible) * 100,
                    2
                )
                : 0.00;

        $requiredPercentage =
            (float) (
                $currentYearGroup['required_percentage_snapshot']
                ?? 85.00
            );

        $requiredPoints =
            $currentYearGroup['required_points_snapshot'] !== null
                ? (float) $currentYearGroup['required_points_snapshot']
                : null;

        $percentageRequirementMet =
            $academicPossible > 0
            && $progressPercentage >= $requiredPercentage;

        $pointsRequirementMet =
            $requiredPoints === null
            || $academicEarned >= $requiredPoints;

        $academicProgress = [
            'earned' => $academicEarned,
            'possible' => $academicPossible,
            'percentage' => $progressPercentage,
            'required_percentage' => $requiredPercentage,
            'required_points' => $requiredPoints,
            'percentage_met' => $percentageRequirementMet,
            'points_met' => $pointsRequirementMet,
            'status' => (string) (
                $currentYearGroup['promotion_status']
                ?? 'active'
            ),
        ];
    }

    $recentNotificationStatement = $pdo->prepare(
        'SELECT id, title, message, link_url, is_read, created_at
         FROM notifications
         WHERE user_id = :user_id
         ORDER BY created_at DESC, id DESC
         LIMIT 8'
    );
    $recentNotificationStatement->execute(['user_id' => $userId]);

    foreach ($recentNotificationStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $recentActivity[] = [
            'type' => 'notification',
            'label' => (int) $row['is_read'] === 1 ? 'Notification' : 'New notification',
            'title' => (string) $row['title'],
            'summary' => $dashboardExcerpt((string) $row['message'], 145),
            'created_at' => (string) $row['created_at'],
            'href' => trim((string) ($row['link_url'] ?? '')) !== ''
                ? (string) $row['link_url']
                : url('notification.php?n=' . (int) $row['id']),
        ];
    }

    $recentThreadsStatement = $pdo->prepare(
        'SELECT id, title, created_at
         FROM forum_threads
         WHERE user_id = :user_id
           AND is_deleted = 0
         ORDER BY created_at DESC, id DESC
         LIMIT 6'
    );
    $recentThreadsStatement->execute(['user_id' => $userId]);

    foreach ($recentThreadsStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (function_exists('forum_can_view_thread') && !forum_can_view_thread($pdo, (int) $row['id'], $userId)) {
            continue;
        }

        $recentActivity[] = [
            'type' => 'thread',
            'label' => 'Thread created',
            'title' => (string) $row['title'],
            'summary' => 'You started a new Academy discussion.',
            'created_at' => (string) $row['created_at'],
            'href' => url('thread.php?t=' . (int) $row['id']),
        ];
    }

    $recentRepliesStatement = $pdo->prepare(
        'SELECT fp.id, fp.thread_id, fp.content, fp.created_at, ft.title
         FROM forum_posts fp
         INNER JOIN forum_threads ft
            ON ft.id = fp.thread_id
         WHERE fp.user_id = :user_id
           AND fp.is_deleted = 0
           AND ft.is_deleted = 0
           AND fp.id <> (
                SELECT MIN(fp2.id)
                FROM forum_posts fp2
                WHERE fp2.thread_id = fp.thread_id
                  AND fp2.is_deleted = 0
           )
         ORDER BY fp.created_at DESC, fp.id DESC
         LIMIT 6'
    );
    $recentRepliesStatement->execute(['user_id' => $userId]);

    foreach ($recentRepliesStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (function_exists('forum_can_view_thread') && !forum_can_view_thread($pdo, (int) $row['thread_id'], $userId)) {
            continue;
        }

        $recentActivity[] = [
            'type' => 'reply',
            'label' => 'Forum reply',
            'title' => (string) $row['title'],
            'summary' => $dashboardExcerpt((string) $row['content'], 145),
            'created_at' => (string) $row['created_at'],
            'href' => url('thread.php?t=' . (int) $row['thread_id'] . '#post-' . (int) $row['id']),
        ];
    }

    usort(
        $recentActivity,
        static fn(array $a, array $b): int => strtotime((string) $b['created_at']) <=> strtotime((string) $a['created_at'])
    );
    $recentActivity = array_slice($recentActivity, 0, 8);
} catch (PDOException $exception) {
    error_log('Blackthorne personal dashboard data error: ' . $exception->getMessage());
}

$houseMembership = null;
if (function_exists('current_user_house_membership')) {
    try {
        $houseMembership = current_user_house_membership();
    } catch (Throwable $exception) {
        error_log('Blackthorne personal dashboard House lookup error: ' . $exception->getMessage());
        $houseMembership = null;
    }
}

$houseName = '';
$houseDisplayColor = '';
$houseId = 0;
if (is_array($houseMembership)) {
    $houseName = trim((string) (
        $houseMembership['display_name']
        ?? $houseMembership['house_display_name']
        ?? $houseMembership['name']
        ?? $houseMembership['house_name']
        ?? ''
    ));

    $candidateHouseColor = trim((string) ($houseMembership['display_color'] ?? ''));
    if (preg_match('/^#[0-9A-Fa-f]{6}$/', $candidateHouseColor) === 1) {
        $houseDisplayColor = $candidateHouseColor;
    }

    $houseId = (int) ($houseMembership['house_id'] ?? $houseMembership['id'] ?? 0);
}

$houseAnnouncementForumId = 0;
if (is_array($houseMembership)) {
    $houseAnnouncementForumId = (int) ($houseMembership['announcement_forum_id'] ?? 0);
}

if ($houseAnnouncementForumId > 0 && function_exists('forum_can_access_forum')) {
    try {
        if (forum_can_access_forum($pdo, $houseAnnouncementForumId, $userId)) {
            $houseNewsStatement = $pdo->prepare(
                'SELECT
                    ft.id,
                    ft.forum_id,
                    ft.title,
                    ft.created_at,
                    ft.last_activity_at,
                    ft.is_pinned,
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
            $houseNewsStatement->execute([
                'forum_id' => $houseAnnouncementForumId,
            ]);

            foreach ($houseNewsStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $candidate) {
                if (forum_can_view_thread($pdo, (int) $candidate['id'], $userId)) {
                    $latestHouseNews = $candidate;
                    break;
                }
            }
        }
    } catch (Throwable $exception) {
        error_log('Blackthorne dashboard House news error: ' . $exception->getMessage());
        $latestHouseNews = null;
    }
}

$hasYearPlacement = is_array($currentYearGroup) && (int) ($currentYearGroup['year_number'] ?? 0) > 0;
$yearNumber = $hasYearPlacement ? (int) $currentYearGroup['year_number'] : 0;
$yearCourseLabel = $yearNumber > 0 ? $dashboardOrdinal($yearNumber) . ' Year Courses' : '';

if ($hasYearPlacement) {
    $courseSidebarLabel = 'Courses';
    $courseSidebarHref = url('courses.php');
    $courseSidebarMeta = $yearCourseLabel;
    $courseSidebarDisabled = false;
} else {
    $courseSidebarLabel = 'Orientation';
    $courseSidebarHref = $orientationCourseHref;
    $courseSidebarDisabled = $orientationCourseHref === '';

    if ($orientationCompleted) {
        $courseSidebarMeta = 'First Year placement pending';
    } elseif ($orientationCourseId > 0) {
        $courseSidebarMeta = 'Required before First Year';
    } else {
        $courseSidebarMeta = 'Course setup pending';
    }
}

$dashboardSidebarEyebrow = $isStudentDashboard ? 'Student Workspace' : 'Member Workspace';
$dashboardSidebarTitle = 'My Dashboard';
$dashboardSidebarItems = [
    [
        'label' => 'Overview',
        'href' => url('dashboard.php'),
        'icon' => '⌂',
        'active' => true,
    ],
    [
        'label' => $courseSidebarLabel,
        'href' => $courseSidebarHref,
        'icon' => '◇',
        'meta' => $courseSidebarMeta,
        'disabled' => $courseSidebarDisabled,
    ],
];

if ($houseMembership !== null) {
    $dashboardSidebarItems[] = [
        'label' => 'Common Room',
        'href' => url('common-room.php'),
        'icon' => '♜',
        'meta' => $houseName !== '' ? $houseName : 'Your House',
        'meta_color' => $houseDisplayColor,
    ];
} else {
    $dashboardSidebarItems[] = [
        'label' => 'Sorting Ceremony',
        'href' => url('sorting-ceremony.php'),
        'icon' => '✧',
        'meta' => 'Discover your House',
    ];
}

$dashboardSidebarItems = array_merge($dashboardSidebarItems, [
    [
        'label' => 'Announcements',
        'href' => url('announcements.php'),
        'icon' => '✦',
    ],
    [
        'label' => 'Forums',
        'href' => url('forums.php'),
        'icon' => '◫',
    ],
    [
        'label' => 'Friends',
        'href' => url('friends.php?u=' . $userId),
        'icon' => '♢',
        'meta' => number_format($dashboardCounts['friends']) . ' connected',
    ],
    [
        'label' => 'Notifications',
        'href' => url('notifications.php'),
        'icon' => '◌',
        'meta' => $dashboardCounts['unread_notifications'] > 0
            ? number_format($dashboardCounts['unread_notifications']) . ' unread'
            : 'All caught up',
    ],
    [
        'label' => 'Bookmarks',
        'href' => url('bookmarks.php'),
        'icon' => '⌑',
    ],
]);

$dashboardSidebarFooter = [
    [
        'label' => 'View Profile',
        'href' => url('profile.php?u=me'),
        'icon' => '○',
    ],
    [
        'label' => 'Edit Profile',
        'href' => url('profile-edit.php'),
        'icon' => '✎',
    ],
];

if ($canAccessStaffDashboard) {
    $dashboardSidebarFooter[] = [
        'label' => 'Staff Dashboard',
        'href' => url('staff-dashboard.php'),
        'icon' => '◆',
        'meta' => 'Staff workspace',
    ];
}
?>

<main id="main-content" class="dashboard-page dashboard-workspace-page">
    <section class="dashboard-hero<?= e($dashboardHeroClass); ?>" aria-labelledby="dashboard-heading">
        <div class="section-inner">
            <div class="dashboard-hero-inner">
                <p class="academy-overline">Academy Dashboard</p>
                <h1 id="dashboard-heading">
                    Welcome back, <span<?= user_display_name_style_attr($userId); ?>><?= e($displayName); ?></span>.
                </h1>
                <p class="dashboard-hero-copy">
                    <?= $isStudentDashboard
                        ? 'Your courses, House life, Academy community, and account activity all have a home here.'
                        : 'Your place inside Blackthorne is ready. Use your dashboard to move between Academy life, community spaces, and your account.'; ?>
                </p>
                <div class="dashboard-status-line">
                    <span class="dashboard-status-label"><?= e($accountLabel); ?></span>
                    <span aria-hidden="true">✦</span>
                    <span>Account Active</span>
                    <?php if ($houseName !== ''): ?>
                    <span aria-hidden="true">✦</span>
                    <span<?= $houseDisplayColor !== '' ? ' style="color:' . e($houseDisplayColor) . ';"' : ''; ?>>
                        <?= e($houseName); ?></span>
                        <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <section class="dashboard-workspace-section" aria-labelledby="personal-dashboard-overview-heading">
        <div class="section-inner dashboard-workspace-layout">
            <?php require INCLUDES_PATH . '/dashboard-sidebar.php'; ?>

            <div class="dashboard-workspace-main">
                <header class="dashboard-workspace-heading">
                    <div>
                        <p class="academy-overline"><?= $isStudentDashboard ? 'Student Overview' : 'Member Overview'; ?>
                        </p>
                        <h2 id="personal-dashboard-overview-heading">
                            <?= $isStudentDashboard ? 'Your Academy at a glance.' : 'Your place at Blackthorne.'; ?>
                        </h2>
                    </div>
                    <a href="<?= e(url('index.php')); ?>" class="button button-secondary">Academy Homepage</a>
                </header>

                <div class="dashboard-summary-grid">
                    <article class="dashboard-summary-card">
                        <span
                            class="dashboard-summary-kicker"><?= $hasYearPlacement ? 'Courses' : 'Orientation'; ?></span>
                        <strong><?= $hasYearPlacement ? number_format($dashboardCounts['active_courses']) : ($orientationCompleted ? 'Complete' : 'Required'); ?></strong>
                        <p><?= $hasYearPlacement ? e($yearCourseLabel) : ($orientationCompleted ? 'Awaiting automatic First Year placement' : ($orientationCourseId > 0 ? 'Complete Orientation before First Year' : 'Orientation course setup pending')); ?>
                        </p>
                    </article>

                    <article class="dashboard-summary-card">
                        <span class="dashboard-summary-kicker">House</span>
                        <strong<?= $houseName !== '' && $houseDisplayColor !== '' ? ' style="color:' . e($houseDisplayColor) . ';"' : ''; ?>>
                            <?= e($houseName !== '' ? $houseName : 'Unsorted'); ?></strong>
                            <p><?= $houseMembership !== null ? 'Your permanent Academy House' : 'Complete the Sorting Ceremony when ready'; ?>
                            </p>
                    </article>

                    <article class="dashboard-summary-card">
                        <span class="dashboard-summary-kicker">Notifications</span>
                        <strong><?= number_format($dashboardCounts['unread_notifications']); ?></strong>
                        <p>Unread notifications</p>
                    </article>

                    <article class="dashboard-summary-card">
                        <span class="dashboard-summary-kicker">Friends</span>
                        <strong><?= number_format($dashboardCounts['friends']); ?></strong>
                        <p>Academy connections</p>
                    </article>
                </div>

                <section class="dashboard-community-stats" aria-label="Forum activity totals">
                    <article>
                        <span>Total Posts</span>
                        <strong><?= number_format($dashboardCounts['posts']); ?></strong>
                    </article>
                    <article>
                        <span>Threads Created</span>
                        <strong><?= number_format($dashboardCounts['threads']); ?></strong>
                    </article>
                    <article>
                        <span>Likes Received</span>
                        <strong><?= number_format($dashboardCounts['likes_received']); ?></strong>
                    </article>
                </section>

                <section class="dashboard-workspace-panel">
                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">Coursework</p>
                            <h3><?= $hasYearPlacement ? e($yearCourseLabel) : 'Academy Orientation'; ?></h3>
                        </div>
                        <?php if ($hasYearPlacement): ?>
                        <a href="<?= e(url('courses.php')); ?>" class="dashboard-panel-link">View courses →</a>
                        <?php elseif ($orientationCourseHref !== ''): ?>
                        <a href="<?= e($orientationCourseHref); ?>" class="dashboard-panel-link">Open Orientation →</a>
                        <?php endif; ?>
                    </div>
                    <div class="dashboard-panel-body">
                        <?php if ($hasYearPlacement): ?>
                        <?php if ($dashboardCounts['active_courses'] > 0): ?>
                        <p>You are placed in <?= e($yearCourseLabel); ?> for
                            <?= e((string) ($currentYearGroup['school_year_name'] ?? 'your current school year')); ?>.
                            Your active course enrollments are ready here.</p>
                        <?php else: ?>
                        <div class="dashboard-empty-state">
                            <span aria-hidden="true">◇</span>
                            <div>
                                <h4><?= e($yearCourseLabel); ?></h4>
                                <p>Your Year placement is active. Courses will appear here as the appropriate offerings
                                    are opened and assigned.</p>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php elseif ($orientationCompleted): ?>
                        <div class="dashboard-empty-state">
                            <span aria-hidden="true">✓</span>
                            <div>
                                <h4>Orientation completed.</h4>
                                <p>Your Orientation course is complete. When the course-completion workflow is built,
                                    that completion will automatically create your First Year enrollment for the
                                    appropriate school year. Until that placement exists, the dashboard will not show
                                    First Year courses.</p>
                            </div>
                        </div>
                        <?php elseif ($orientationCourseId > 0): ?>
                        <p>Academy Orientation is your required first course. Complete it successfully before you can be
                            placed into First Year and receive access to First Year courses.</p>
                        <?php else: ?>
                        <div class="dashboard-empty-state">
                            <span aria-hidden="true">◇</span>
                            <div>
                                <h4>Orientation course setup is pending.</h4>
                                <p>Orientation will be offered through Blackthorne's normal course system. Once that
                                    course exists, this dashboard will link directly to it rather than to a separate
                                    Orientation page.</p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </section>

                <?php if (
                    $isStudentDashboard
                    && $hasYearPlacement
                    && is_array($academicProgress)
                ): ?>
                <?php
                    $progressStatus =
                        strtolower((string) $academicProgress['status']);

                    $progressStatusLabel = match ($progressStatus) {
                        'eligible' => 'Eligible for Promotion',
                        'repeating' => 'Repeating Year',
                        'promoted' => 'Promoted',
                        'completed' => 'Year Completed',
                        'not_eligible' => 'Not Eligible',
                        'withdrawn' => 'Withdrawn',
                        default => 'In Progress',
                    };

                    $progressBarPercent =
                        max(
                            0.0,
                            min(
                                100.0,
                                (float) $academicProgress['percentage']
                            )
                        );
                    ?>

                <section class="dashboard-workspace-panel dashboard-academic-progress-panel">
                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">Academic Progression</p>
                            <h3><?= e($yearCourseLabel); ?> Progress</h3>
                        </div>
                    </div>

                    <div class="dashboard-panel-body">
                        <div class="dashboard-community-stats" aria-label="Academic progression totals">
                            <article>
                                <span>HW Points</span>
                                <strong>
                                    <?= e(number_format((float) $academicProgress['earned'], 2)); ?>
                                    <?php if ($academicProgress['required_points'] !== null): ?>
                                    / <?= e(number_format((float) $academicProgress['required_points'], 2)); ?>
                                    <?php endif; ?>
                                </strong>
                                <span>
                                    <?= $academicProgress['required_points'] === null
                                            ? 'No minimum HW Point requirement'
                                            : ($academicProgress['points_met']
                                                ? 'HW Point requirement met'
                                                : 'HW Points still required'); ?>
                                </span>
                            </article>

                            <article>
                                <span>Academic Average</span>
                                <strong>
                                    <?= (float) $academicProgress['possible'] > 0
                                            ? e(number_format((float) $academicProgress['percentage'], 2)) . '%'
                                            : 'Pending'; ?>
                                </strong>
                                <span>
                                    Required:
                                    <?= e(number_format((float) $academicProgress['required_percentage'], 2)); ?>%
                                </span>
                            </article>

                            <article>
                                <span>Progression Status</span>
                                <strong><?= e($progressStatusLabel); ?></strong>
                                <span>
                                    Promotion requires both academic requirements.
                                </span>
                            </article>
                        </div>

                        <?php if ((float) $academicProgress['possible'] > 0): ?>
                        <div aria-label="Academic average progress"
                            style="margin-top:1rem;height:.65rem;border:1px solid rgba(198,163,79,.45);background:rgba(10,7,12,.72);overflow:hidden;">
                            <div
                                style="height:100%;width:<?= e(number_format($progressBarPercent, 2, '.', '')); ?>%;background:linear-gradient(90deg,rgba(112,70,105,.9),rgba(198,163,79,.9));">
                            </div>
                        </div>
                        <?php else: ?>
                        <p style="margin-top:1rem;">
                            Your academic percentage will appear once graded coursework begins contributing possible HW
                            Points.
                        </p>
                        <?php endif; ?>

                        <p style="margin-top:1rem;">
                            To become eligible for promotion, you must meet the
                            <?= e(number_format((float) $academicProgress['required_percentage'], 2)); ?>% academic
                            requirement
                            <?php if ($academicProgress['required_points'] !== null): ?>
                            and earn at least
                            <?= e(number_format((float) $academicProgress['required_points'], 2)); ?> HW Points
                            <?php endif; ?>.
                            House-only points do not count toward academic progression.
                        </p>
                    </div>
                </section>
                <?php endif; ?>

                <section class="dashboard-workspace-panel dashboard-house-overview-panel">
                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">Academy Life</p>
                            <h3><?= $houseMembership !== null ? 'Your House' : 'The Sorting Ceremony'; ?></h3>
                        </div>
                    </div>
                    <div class="dashboard-panel-body">
                        <?php if ($houseMembership !== null): ?>
                        <p>You belong to
                            <strong<?= $houseDisplayColor !== '' ? ' style="color:' . e($houseDisplayColor) . ';"' : ''; ?>>
                                <?= e($houseName !== '' ? $houseName : 'your House'); ?></strong>. Your Common Room
                                brings together House announcements, discussions, members, resources, birthdays, and
                                House Cup information.
                        </p>
                        <a href="<?= e(url('common-room.php')); ?>" class="dashboard-inline-link">Enter your Common Room
                            →</a>
                        <?php else: ?>
                        <p>You have not been sorted yet. The ceremony will place you into one of Blackthorne Academy's
                            four Houses and unlock your Common Room.</p>
                        <a href="<?= e(url('sorting-ceremony.php')); ?>" class="dashboard-inline-link">Enter the Sorting
                            Ceremony →</a>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="dashboard-workspace-panel dashboard-house-news-panel">
                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">House News</p>
                            <h3<?= $houseName !== '' && $houseDisplayColor !== '' ? ' style="color:' . e($houseDisplayColor) . ';"' : ''; ?>>
                                <?= $houseName !== '' ? e($houseName) . ' Noticeboard' : 'House Noticeboard'; ?></h3>
                        </div>
                        <?php if ($houseMembership !== null): ?>
                        <a href="<?= e(url('common-room.php')); ?>" class="dashboard-panel-link">Common Room →</a>
                        <?php endif; ?>
                    </div>
                    <div class="dashboard-panel-body">
                        <?php if ($houseMembership === null): ?>
                        <div class="dashboard-empty-state">
                            <span aria-hidden="true">✧</span>
                            <div>
                                <h4>House news unlocks after Sorting.</h4>
                                <p>Once you have a House, its latest announcement will appear here.</p>
                            </div>
                        </div>
                        <?php elseif (is_array($latestHouseNews)): ?>
                        <article class="dashboard-house-news-item">
                            <p class="dashboard-activity-label">Latest announcement</p>
                            <h4><a
                                    href="<?= e(url('thread.php?t=' . (int) $latestHouseNews['id'])); ?>"><?= e((string) $latestHouseNews['title']); ?></a>
                            </h4>
                            <p><?= e($dashboardExcerpt((string) ($latestHouseNews['content'] ?? ''), 220)); ?></p>
                            <div class="dashboard-house-news-meta">
                                <span><?= e((string) ($latestHouseNews['display_name'] ?: $latestHouseNews['username'])); ?></span>
                                <?php $houseNewsDate = $dashboardDate((string) ($latestHouseNews['created_at'] ?? '')); ?>
                                <?php if ($houseNewsDate !== ''): ?><span><?= e($houseNewsDate); ?></span><?php endif; ?>
                            </div>
                        </article>
                        <?php else: ?>
                        <div class="dashboard-empty-state">
                            <span aria-hidden="true">✦</span>
                            <div>
                                <h4>No House announcement yet.</h4>
                                <p>The newest announcement from your House announcement forum will appear here
                                    automatically.</p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="dashboard-workspace-panel dashboard-recent-activity-panel">
                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">Community</p>
                            <h3>Recent Activity</h3>
                        </div>
                        <a href="<?= e(url('notifications.php')); ?>" class="dashboard-panel-link">All notifications
                            →</a>
                    </div>
                    <div class="dashboard-panel-body dashboard-activity-list">
                        <?php if ($recentActivity === []): ?>
                        <div class="dashboard-empty-state">
                            <span aria-hidden="true">◇</span>
                            <div>
                                <h4>No recent activity yet.</h4>
                                <p>Notifications, forum threads, and forum replies will appear here as you use the
                                    Academy.</p>
                            </div>
                        </div>
                        <?php else: ?>
                        <?php foreach ($recentActivity as $activity): ?>
                        <a class="dashboard-activity-item" href="<?= e((string) $activity['href']); ?>">
                            <span class="dashboard-activity-symbol"
                                aria-hidden="true"><?= $activity['type'] === 'notification' ? '◌' : ($activity['type'] === 'thread' ? '◫' : '↳'); ?></span>
                            <span class="dashboard-activity-copy">
                                <span class="dashboard-activity-topline">
                                    <span class="dashboard-activity-label"><?= e((string) $activity['label']); ?></span>
                                    <?php $activityDate = $dashboardDate((string) $activity['created_at']); ?>
                                    <?php if ($activityDate !== ''): ?><span
                                        class="dashboard-activity-date"><?= e($activityDate); ?></span><?php endif; ?>
                                </span>
                                <strong><?= e((string) $activity['title']); ?></strong>
                                <?php if ((string) $activity['summary'] !== ''): ?><span
                                    class="dashboard-activity-summary"><?= e((string) $activity['summary']); ?></span><?php endif; ?>
                            </span>
                        </a>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>

                <?php if ($canAccessStaffDashboard): ?>
                <section class="dashboard-workspace-panel dashboard-staff-switcher">
                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">Staff Access</p>
                            <h3>Your staff workspace is separate.</h3>
                        </div>
                        <a href="<?= e(url('staff-dashboard.php')); ?>" class="button button-secondary">Open Staff
                            Dashboard</a>
                    </div>
                    <div class="dashboard-panel-body">
                        <p>This remains your personal Academy dashboard. Staff and administrative tools live in the
                            Staff Dashboard and appear according to your effective permissions.</p>
                    </div>
                </section>
                <?php endif; ?>
            </div>
        </div>
    </section>
</main>
