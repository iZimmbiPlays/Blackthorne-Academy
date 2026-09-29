<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/points-functions.php';

require_login();
require_active_account();

$isProtectedSuperAdmin = current_user_is_superuser();
$hasStaffIdentity =
    $isProtectedSuperAdmin
    || current_user_is_admin()
    || current_user_is_staff();

$canViewHistory = $isProtectedSuperAdmin || user_can('points.history.view');
$canReverse = $isProtectedSuperAdmin || user_can('points.reverse');

if (!$hasStaffIdentity || (!$canViewHistory && !$canReverse)) {
    http_response_code(403);

    $pageTitle = 'Access Denied | Blackthorne Academy';
    $pageDescription = 'You do not have permission to view House Point history.';
    $pageCanonical = url('staff-dashboard.php');
    $robots = 'noindex, nofollow';

    require INCLUDES_PATH . '/header.php';
    ?>
<main id="main-content" class="forum-board-page">
    <section class="forum-board-error">
        <div class="section-inner">
            <p class="academy-overline">Restricted Staff Area</p>
            <h1>Access Denied</h1>
            <p>Your account does not have permission to view House Point history.</p>
            <a class="button button-secondary" href="<?= e(url('staff-dashboard.php')); ?>">Return to Staff
                Dashboard</a>
        </div>
    </section>
</main>
<?php
    require INCLUDES_PATH . '/footer.php';
    exit;
}

$currentUserId = (int) (current_user_id() ?? 0);

function point_history_query_id(string $key): int
{
    $value = $_GET[$key] ?? '';

    if (!is_scalar($value) || !ctype_digit((string) $value)) {
        return 0;
    }

    return max(0, (int) $value);
}

function point_history_post_id(string $key): int
{
    $value = $_POST[$key] ?? '';

    if (!is_scalar($value) || !ctype_digit((string) $value)) {
        return 0;
    }

    return max(0, (int) $value);
}

function point_history_post_string(string $key): string
{
    $value = $_POST[$key] ?? '';

    return is_scalar($value) ? trim((string) $value) : '';
}

function point_history_query_string(string $key): string
{
    $value = $_GET[$key] ?? '';

    return is_scalar($value) ? trim((string) $value) : '';
}

function point_history_audit(
    PDO $pdo,
    int $actorUserId,
    int $targetUserId,
    int $ledgerId,
    string $description
): void {
    if ($actorUserId <= 0 || $targetUserId <= 0 || $ledgerId <= 0) {
        return;
    }

    try {
        $statement = $pdo->prepare(
            'INSERT INTO audit_log (
                user_id,
                action_type,
                entity_type,
                entity_id,
                description,
                ip_address,
                user_agent
             ) VALUES (
                :user_id,
                :action_type,
                :entity_type,
                :entity_id,
                :description,
                :ip_address,
                :user_agent
             )'
        );

        $statement->execute([
            'user_id' => $actorUserId,
            'action_type' => 'points.house.reverse',
            'entity_type' => 'points_ledger',
            'entity_id' => $ledgerId,
            'description' => $description,
            'ip_address' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500) ?: null,
        ]);
    } catch (Throwable $exception) {
        error_log('Point History audit error: ' . $exception->getMessage());
    }
}

$userStatement = $pdo->query(
    'SELECT
        u.id,
        u.username,
        u.display_name,
        u.profile_slug,
        u.status,
        h.id AS house_id,
        h.display_name AS house_display_name,
        h.name AS house_name,
        h.display_color AS house_display_color
     FROM users u
     LEFT JOIN house_memberships hm
        ON hm.user_id = u.id
       AND hm.membership_status = \'active\'
       AND hm.left_at IS NULL
     LEFT JOIN houses h
        ON h.id = hm.house_id
     ORDER BY u.display_name ASC, u.username ASC, u.id ASC'
);

$users = $userStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
$usersById = [];

foreach ($users as $userRow) {
    $usersById[(int) $userRow['id']] = $userRow;
}

$schoolYearStatement = $pdo->query(
    'SELECT
        id,
        name,
        start_date,
        end_date,
        is_current,
        is_active,
        is_finalized
     FROM school_years
     ORDER BY is_current DESC, start_date DESC, id DESC'
);

$schoolYears = $schoolYearStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
$schoolYearsById = [];
$currentSchoolYearId = 0;

foreach ($schoolYears as $schoolYear) {
    $schoolYearId = (int) $schoolYear['id'];
    $schoolYearsById[$schoolYearId] = $schoolYear;

    if (
        $currentSchoolYearId === 0
        && (int) ($schoolYear['is_current'] ?? 0) === 1
        && (int) ($schoolYear['is_active'] ?? 0) === 1
    ) {
        $currentSchoolYearId = $schoolYearId;
    }
}

$selectedUserId = point_history_query_id('user');
$selectedSchoolYearId = point_history_query_id('school_year');

if (isset($_POST['user_id'])) {
    $selectedUserId = point_history_post_id('user_id');
}

if (isset($_POST['school_year_id'])) {
    $selectedSchoolYearId = point_history_post_id('school_year_id');
}

if (!isset($usersById[$selectedUserId])) {
    $selectedUserId = 0;
}

if (!isset($schoolYearsById[$selectedSchoolYearId])) {
    $selectedSchoolYearId = $currentSchoolYearId;
}

$selectedUser = $selectedUserId > 0 ? ($usersById[$selectedUserId] ?? null) : null;
$selectedSchoolYear = $selectedSchoolYearId > 0 ? ($schoolYearsById[$selectedSchoolYearId] ?? null) : null;

$allowedSources = [
    'all',
    'manual',
    'bonus',
    'penalty',
    'achievement',
    'contest',
    'event',
    'forum_thread',
    'forum_reply',
];
$sourceFilter = point_history_query_string('source');
if (!in_array($sourceFilter, $allowedSources, true)) {
    $sourceFilter = 'all';
}

$statusFilter = point_history_query_string('status');
if (!in_array($statusFilter, ['all', 'active', 'reversed'], true)) {
    $statusFilter = 'all';
}

$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!verify_csrf_token($_POST['_csrf_token'] ?? null)) {
        $errors[] = 'Your form session expired. Refresh the page and try again.';
    }

    $action = point_history_post_string('action');

    if ($errors === [] && $action === 'reverse_house_points') {
        if (!$canReverse) {
            $errors[] = 'You do not have permission to reverse point transactions.';
        }

        $ledgerId = point_history_post_id('ledger_id');
        $reason = point_history_post_string('reversal_reason');

        if ($ledgerId <= 0) {
            $errors[] = 'Choose a valid House Point transaction.';
        }

        if ($reason === '') {
            $errors[] = 'A reversal reason is required.';
        }

        if ($errors === []) {
            try {
                $entry = points_ledger_entry($pdo, $ledgerId);

                if (!is_array($entry)) {
                    throw new RuntimeException('The point transaction does not exist.');
                }

                if ((string) ($entry['point_type'] ?? '') !== 'house_only') {
                    throw new RuntimeException('HW Point transactions cannot be changed from House Point history.');
                }

                if (!in_array((string) ($entry['source_type'] ?? ''), ['manual', 'bonus', 'penalty'], true)) {
                    throw new RuntimeException('Automatic achievement and system transactions must be corrected from their originating feature.');
                }

                points_reverse($pdo, $ledgerId, $currentUserId, $reason);

                point_history_audit(
                    $pdo,
                    $currentUserId,
                    (int) $entry['user_id'],
                    $ledgerId,
                    'Reversed House Point transaction #' . $ledgerId . '. Reason: ' . $reason
                );

                set_flash('success', 'House Point transaction reversed. The member was notified.');

                $redirect = 'admin/points-history.php?user=' . (int) $entry['user_id']
                    . '&school_year=' . (int) $entry['school_year_id'];

                if ($sourceFilter !== 'all') {
                    $redirect .= '&source=' . rawurlencode($sourceFilter);
                }
                if ($statusFilter !== 'all') {
                    $redirect .= '&status=' . rawurlencode($statusFilter);
                }

                redirect(url($redirect));
            } catch (Throwable $exception) {
                error_log('Point History reversal error: ' . $exception->getMessage());
                $errors[] = 'The House Point transaction could not be reversed: ' . $exception->getMessage();
            }
        }
    }
}

$history = [];
$totals = null;
$totalRows = 0;
$perPage = 50;
$page = max(1, point_history_query_id('page'));
$totalPages = 1;

if ($selectedUserId > 0 && $selectedSchoolYearId > 0) {
    $totals = points_student_totals($pdo, $selectedUserId, $selectedSchoolYearId);

    $where = [
        'pl.user_id = :user_id',
        'pl.school_year_id = :school_year_id',
        'pl.point_type = \'house_only\'',
    ];
    $params = [
        'user_id' => $selectedUserId,
        'school_year_id' => $selectedSchoolYearId,
    ];

    if ($sourceFilter !== 'all') {
        $where[] = 'pl.source_type = :source_type';
        $params['source_type'] = $sourceFilter;
    }

    if ($statusFilter === 'active') {
        $where[] = 'pl.is_reversed = 0';
    } elseif ($statusFilter === 'reversed') {
        $where[] = 'pl.is_reversed = 1';
    }

    $whereSql = implode(' AND ', $where);

    $countStatement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM points_ledger pl
         WHERE ' . $whereSql
    );
    $countStatement->execute($params);
    $totalRows = (int) $countStatement->fetchColumn();

    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    $historyStatement = $pdo->prepare(
        'SELECT
            pl.*,
            h.name AS house_name,
            h.display_name AS house_display_name,
            h.slug AS house_slug,
            h.display_color AS house_display_color,
            awarder.display_name AS awarded_by_display_name,
            reverser.display_name AS reversed_by_display_name
         FROM points_ledger pl
         LEFT JOIN houses h
            ON h.id = pl.house_id
         LEFT JOIN users awarder
            ON awarder.id = pl.awarded_by
         LEFT JOIN users reverser
            ON reverser.id = pl.reversed_by
         WHERE ' . $whereSql . '
         ORDER BY pl.created_at DESC, pl.id DESC
         LIMIT ' . $perPage . ' OFFSET ' . $offset
    );
    $historyStatement->execute($params);
    $history = $historyStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$pointsSidebarActive = 'point-history';
require INCLUDES_PATH . '/staff-points-sidebar.php';

/* Keep Point History usable immediately even before the next shared-sidebar upload. */
foreach ($dashboardSidebarItems as &$sidebarItem) {
    if (($sidebarItem['type'] ?? '') !== 'group' || ($sidebarItem['label'] ?? '') !== 'Points') {
        continue;
    }

    foreach ($sidebarItem['children'] as &$child) {
        if (($child['label'] ?? '') === 'Point History') {
            $child['href'] = url('admin/points-history.php');
            $child['disabled'] = false;
            unset($child['meta']);
            $child['active'] = true;
        }
    }
    unset($child);
}
unset($sidebarItem);

$pageTitle = 'Point History | Blackthorne Academy';
$pageDescription = 'Review House Point transactions, revisions, reversals, sources, and staff activity.';
$pageCanonical = url('admin/points-history.php');
$robots = 'noindex, nofollow';

require INCLUDES_PATH . '/header.php';

$heroPng = 'assets/images/staff_dashboard_hero_bg.png';
$heroWebp = 'assets/images/staff_dashboard_hero_bg.webp';
$projectRoot = dirname(__DIR__);
$heroReference = is_file($projectRoot . '/' . $heroWebp) ? $heroWebp : $heroPng;
$heroUrl = is_file($projectRoot . '/' . $heroReference) ? url($heroReference) : '';

$buildHistoryUrl = static function (int $targetPage) use (
    $selectedUserId,
    $selectedSchoolYearId,
    $sourceFilter,
    $statusFilter
): string {
    $query = [];
    if ($selectedUserId > 0) {
        $query['user'] = $selectedUserId;
    }
    if ($selectedSchoolYearId > 0) {
        $query['school_year'] = $selectedSchoolYearId;
    }
    if ($sourceFilter !== 'all') {
        $query['source'] = $sourceFilter;
    }
    if ($statusFilter !== 'all') {
        $query['status'] = $statusFilter;
    }
    if ($targetPage > 1) {
        $query['page'] = $targetPage;
    }

    $suffix = $query !== [] ? '?' . http_build_query($query) : '';

    return url('admin/points-history.php' . $suffix);
};
?>

<main id="main-content" class="dashboard-page staff-dashboard-page dashboard-workspace-page points-history-page">
    <section class="dashboard-hero staff-dashboard-hero" aria-labelledby="point-history-heading"
        <?php if ($heroUrl !== ''): ?>style="--staff-dashboard-hero-image: url('<?= e($heroUrl); ?>');" <?php endif; ?>>
        <div class="section-inner">
            <div class="dashboard-hero-inner">
                <p class="academy-overline">House Records</p>
                <h1 id="point-history-heading">House Point History</h1>
                <p class="dashboard-hero-copy">
                    Review the complete House Point ledger for a member by school year, including revisions, reversals,
                    source records, and the staff member responsible for each change.
                </p>
            </div>
        </div>
    </section>

    <section class="dashboard-workspace-section">
        <div class="section-inner dashboard-workspace-layout">
            <?php require INCLUDES_PATH . '/dashboard-sidebar.php'; ?>

            <div class="dashboard-workspace-main">
                <header class="dashboard-workspace-heading">
                    <div>
                        <p class="academy-overline">Ledger Review</p>
                        <h2>Point History</h2>
                        <p>HW Points are intentionally excluded from this staff history screen. Academic records remain
                            controlled by coursework and grading.</p>
                    </div>
                </header>

                <?php foreach ($errors as $error): ?>
                <div class="form-message form-message-error" role="alert"><?= e($error); ?></div>
                <?php endforeach; ?>

                <section class="dashboard-workspace-panel">
                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">Filters</p>
                            <h3>Choose a Member and School Year</h3>
                        </div>
                    </div>
                    <div class="dashboard-panel-body">
                        <form method="get" action="<?= e(url('admin/points-history.php')); ?>">
                            <div class="form-grid form-grid-2">
                                <div class="form-group">
                                    <label for="history-user">Member</label>
                                    <select class="form-control" id="history-user" name="user" required>
                                        <option value="">Choose a member</option>
                                        <?php foreach ($users as $user): ?>
                                        <option value="<?= (int) $user['id']; ?>"
                                            <?= (int) $user['id'] === $selectedUserId ? 'selected' : ''; ?>>
                                            <?= e((string) $user['display_name']); ?>
                                            (@<?= e((string) $user['username']); ?>)
                                            <?php if (trim((string) ($user['house_display_name'] ?? $user['house_name'] ?? '')) !== ''): ?>
                                            · <?= e((string) ($user['house_display_name'] ?? $user['house_name'])); ?>
                                            <?php endif; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label for="history-year">School Year</label>
                                    <select class="form-control" id="history-year" name="school_year" required>
                                        <?php foreach ($schoolYears as $schoolYear): ?>
                                        <option value="<?= (int) $schoolYear['id']; ?>"
                                            <?= (int) $schoolYear['id'] === $selectedSchoolYearId ? 'selected' : ''; ?>>
                                            <?= e((string) $schoolYear['name']); ?>
                                            <?= (int) ($schoolYear['is_current'] ?? 0) === 1 ? ' · Current' : ''; ?>
                                            <?= (int) ($schoolYear['is_finalized'] ?? 0) === 1 ? ' · Finalized' : ''; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label for="history-source">Source</label>
                                    <select class="form-control" id="history-source" name="source">
                                        <option value="all" <?= $sourceFilter === 'all' ? 'selected' : ''; ?>>All
                                            sources</option>
                                        <option value="manual" <?= $sourceFilter === 'manual' ? 'selected' : ''; ?>>
                                            Manual</option>
                                        <option value="bonus" <?= $sourceFilter === 'bonus' ? 'selected' : ''; ?>>Bonus
                                        </option>
                                        <option value="penalty" <?= $sourceFilter === 'penalty' ? 'selected' : ''; ?>>
                                            Penalty</option>
                                        <option value="achievement"
                                            <?= $sourceFilter === 'achievement' ? 'selected' : ''; ?>>Achievement
                                        </option>
                                        <option value="contest" <?= $sourceFilter === 'contest' ? 'selected' : ''; ?>>
                                            Contest</option>
                                        <option value="event" <?= $sourceFilter === 'event' ? 'selected' : ''; ?>>Event
                                        </option>
                                        <option value="forum_thread"
                                            <?= $sourceFilter === 'forum_thread' ? 'selected' : ''; ?>>Forum Thread
                                        </option>
                                        <option value="forum_reply"
                                            <?= $sourceFilter === 'forum_reply' ? 'selected' : ''; ?>>Forum Reply
                                        </option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label for="history-status">Status</label>
                                    <select class="form-control" id="history-status" name="status">
                                        <option value="all" <?= $statusFilter === 'all' ? 'selected' : ''; ?>>Active and
                                            reversed</option>
                                        <option value="active" <?= $statusFilter === 'active' ? 'selected' : ''; ?>>
                                            Active only</option>
                                        <option value="reversed" <?= $statusFilter === 'reversed' ? 'selected' : ''; ?>>
                                            Reversed only</option>
                                    </select>
                                </div>
                            </div>

                            <div class="forum-admin-actions">
                                <button type="submit" class="button button-primary">View History</button>
                                <?php if ($selectedUserId > 0): ?>
                                <a class="button button-secondary"
                                    href="<?= e(url('admin/points.php?user=' . $selectedUserId . '&school_year=' . $selectedSchoolYearId)); ?>">Award
                                    / Deduct Points</a>
                                <a class="button button-secondary"
                                    href="<?= e(url('profile.php?u=' . $selectedUserId)); ?>">View Profile</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </section>

                <?php if ($selectedUser !== null && $selectedSchoolYear !== null): ?>
                <section class="dashboard-workspace-panel">
                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">Current Totals</p>
                            <h3><?= e((string) $selectedUser['display_name']); ?></h3>
                        </div>
                    </div>
                    <div class="dashboard-panel-body">
                        <div style="display:flex;flex-wrap:wrap;gap:1rem;align-items:stretch;">
                            <div
                                style="min-width:180px;flex:1;padding:1rem;border:1px solid rgba(201,170,104,.28);border-radius:10px;">
                                <strong style="display:block;">House Points</strong>
                                <span><?= number_format((float) ($totals['house_only'] ?? 0), 2); ?></span>
                            </div>
                            <div
                                style="min-width:180px;flex:1;padding:1rem;border:1px solid rgba(201,170,104,.28);border-radius:10px;">
                                <strong style="display:block;">School Year</strong>
                                <span><?= e((string) $selectedSchoolYear['name']); ?></span>
                            </div>
                            <div
                                style="min-width:180px;flex:1;padding:1rem;border:1px solid rgba(201,170,104,.28);border-radius:10px;">
                                <strong style="display:block;">Transactions</strong>
                                <span><?= number_format($totalRows); ?></span>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="dashboard-workspace-panel">
                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">Ledger</p>
                            <h3>House Point Transactions</h3>
                        </div>
                    </div>
                    <div class="dashboard-panel-body">
                        <?php if ($history === []): ?>
                        <p>No House Point transactions match these filters.</p>
                        <?php else: ?>
                        <div style="overflow-x:auto;">
                            <table class="forum-admin-table" style="width:100%;">
                                <thead>
                                    <tr>
                                        <th>ID / Revision</th>
                                        <th>Date</th>
                                        <th>Change</th>
                                        <th>Source</th>
                                        <th>Description</th>
                                        <th>House Snapshot</th>
                                        <th>Staff / System</th>
                                        <th>Status</th>
                                        <?php if ($canReverse): ?><th>Action</th><?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($history as $entry): ?>
                                    <?php
                                                $entryPoints = (float) ($entry['points'] ?? 0);
                                                $entryReversed = (int) ($entry['is_reversed'] ?? 0) === 1;
                                                $sourceType = (string) ($entry['source_type'] ?? 'manual');
                                                $sourceId = $entry['source_id'] !== null ? (int) $entry['source_id'] : null;
                                                $revisionNumber = max(1, (int) ($entry['revision_number'] ?? 1));
                                                $replacesLedgerId = $entry['replaces_ledger_id'] !== null ? (int) $entry['replaces_ledger_id'] : null;
                                                $yearWritable =
                                                    (int) ($selectedSchoolYear['is_active'] ?? 0) === 1
                                                    && (int) ($selectedSchoolYear['is_finalized'] ?? 0) !== 1;
                                                $canReverseThis =
                                                    $canReverse
                                                    && !$entryReversed
                                                    && $yearWritable
                                                    && in_array($sourceType, ['manual', 'bonus', 'penalty'], true);
                                                ?>
                                    <tr>
                                        <td>
                                            #<?= (int) $entry['id']; ?><br>
                                            <small>Revision
                                                <?= $revisionNumber; ?><?php if ($replacesLedgerId !== null): ?> ·
                                                replaces #<?= $replacesLedgerId; ?><?php endif; ?></small>
                                        </td>
                                        <td><?= e((string) ($entry['created_at'] ?? '')); ?></td>
                                        <td><strong><?= $entryPoints > 0 ? '+' : ''; ?><?= number_format($entryPoints, 2); ?></strong>
                                        </td>
                                        <td>
                                            <?= e(ucwords(str_replace('_', ' ', $sourceType))); ?>
                                            <?php if ($sourceId !== null): ?><br><small>Source
                                                #<?= $sourceId; ?></small><?php endif; ?>
                                        </td>
                                        <td>
                                            <?= e((string) ($entry['description'] ?? '')); ?>
                                            <?php if ((int) ($entry['is_public'] ?? 0) === 1): ?><br><small>Public House
                                                feed</small><?php endif; ?>
                                        </td>
                                        <td><?= e((string) ($entry['house_display_name'] ?? $entry['house_name'] ?? 'No House')); ?>
                                        </td>
                                        <td><?= e((string) ($entry['awarded_by_display_name'] ?? 'System')); ?></td>
                                        <td>
                                            <?= $entryReversed ? 'Reversed' : 'Active'; ?>
                                            <?php if ($entryReversed): ?>
                                            <?php if (trim((string) ($entry['reversal_reason'] ?? '')) !== ''): ?>
                                            <br><small><?= e((string) $entry['reversal_reason']); ?></small>
                                            <?php endif; ?>
                                            <?php if (trim((string) ($entry['reversed_by_display_name'] ?? '')) !== ''): ?>
                                            <br><small>by <?= e((string) $entry['reversed_by_display_name']); ?></small>
                                            <?php endif; ?>
                                            <?php if (trim((string) ($entry['reversed_at'] ?? '')) !== ''): ?>
                                            <br><small><?= e((string) $entry['reversed_at']); ?></small>
                                            <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <?php if ($canReverse): ?>
                                        <td>
                                            <?php if ($canReverseThis): ?>
                                            <form method="post" style="display:grid;gap:.5rem;min-width:240px;">
                                                <?= csrf_field(); ?>
                                                <input type="hidden" name="action" value="reverse_house_points">
                                                <input type="hidden" name="ledger_id"
                                                    value="<?= (int) $entry['id']; ?>">
                                                <input type="hidden" name="user_id"
                                                    value="<?= (int) $selectedUser['id']; ?>">
                                                <input type="hidden" name="school_year_id"
                                                    value="<?= (int) $selectedSchoolYear['id']; ?>">
                                                <input class="form-control" type="text" name="reversal_reason"
                                                    maxlength="255" placeholder="Reversal reason" required>
                                                <button type="submit" class="button button-secondary">Reverse</button>
                                            </form>
                                            <?php elseif ($sourceType === 'achievement'): ?>
                                            Automatic
                                            <?php elseif (!$yearWritable): ?>
                                            Read only
                                            <?php else: ?>
                                            —
                                            <?php endif; ?>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($totalPages > 1): ?>
                        <nav aria-label="Point history pages"
                            style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;margin-top:1rem;">
                            <?php if ($page > 1): ?>
                            <a class="button button-secondary"
                                href="<?= e($buildHistoryUrl($page - 1)); ?>">Previous</a>
                            <?php endif; ?>
                            <span>Page <?= $page; ?> of <?= $totalPages; ?></span>
                            <?php if ($page < $totalPages): ?>
                            <a class="button button-secondary" href="<?= e($buildHistoryUrl($page + 1)); ?>">Next</a>
                            <?php endif; ?>
                        </nav>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </section>
                <?php endif; ?>
            </div>
        </div>
    </section>
</main>

<?php require INCLUDES_PATH . '/footer.php'; ?>
