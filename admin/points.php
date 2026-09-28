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

$canAward = $isProtectedSuperAdmin || user_can('points.house.award');
$canDeduct = $isProtectedSuperAdmin || user_can('points.house.deduct');
$canViewHistory = $isProtectedSuperAdmin || user_can('points.history.view');
$canReverse = $isProtectedSuperAdmin || user_can('points.reverse');
$canOpenPage = $canAward || $canDeduct || $canViewHistory || $canReverse;

if (!$hasStaffIdentity || !$canOpenPage) {
    http_response_code(403);

    $pageTitle = 'Access Denied | Blackthorne Academy';
    $pageDescription = 'You do not have permission to manage House Points.';
    $pageCanonical = url('staff-dashboard.php');
    $robots = 'noindex, nofollow';

    require INCLUDES_PATH . '/header.php';
    ?>
    <main id="main-content" class="forum-board-page">
        <section class="forum-board-error">
            <div class="section-inner">
                <p class="academy-overline">Restricted Staff Area</p>
                <h1>Access Denied</h1>
                <p>Your account does not have permission to manage House Points.</p>
                <a class="button button-secondary" href="<?= e(url('staff-dashboard.php')); ?>">
                    Return to Staff Dashboard
                </a>
            </div>
        </section>
    </main>
    <?php
    require INCLUDES_PATH . '/footer.php';
    exit;
}

$currentUserId = (int) (current_user_id() ?? 0);

function admin_points_post_string(string $key): string
{
    $value = $_POST[$key] ?? '';

    return is_scalar($value) ? trim((string) $value) : '';
}

function admin_points_post_id(string $key): int
{
    $value = $_POST[$key] ?? '';

    if (!is_scalar($value) || !ctype_digit((string) $value)) {
        return 0;
    }

    return max(0, (int) $value);
}

function admin_points_query_id(string $key): int
{
    $value = $_GET[$key] ?? '';

    if (!is_scalar($value) || !ctype_digit((string) $value)) {
        return 0;
    }

    return max(0, (int) $value);
}

function admin_points_decimal(string $value, string $label): string
{
    $value = trim($value);

    if ($value === '' || !is_numeric($value)) {
        throw new InvalidArgumentException($label . ' must be a valid number.');
    }

    $amount = round((float) $value, 2);

    if (!is_finite($amount) || $amount <= 0) {
        throw new InvalidArgumentException($label . ' must be greater than zero.');
    }

    if ($amount > 99999999.99) {
        throw new InvalidArgumentException($label . ' is too large.');
    }

    return number_format($amount, 2, '.', '');
}

function admin_points_audit(
    PDO $pdo,
    int $actorUserId,
    string $actionType,
    int $targetUserId,
    int $ledgerId,
    string $description
): void {
    if ($actorUserId <= 0 || $targetUserId <= 0) {
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
            'action_type' => $actionType,
            'entity_type' => 'points_ledger',
            'entity_id' => $ledgerId > 0 ? $ledgerId : $targetUserId,
            'description' => $description,
            'ip_address' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500) ?: null,
        ]);
    } catch (Throwable $exception) {
        error_log('House Point audit error: ' . $exception->getMessage());
    }
}

/*
|--------------------------------------------------------------------------
| Users / Houses
|--------------------------------------------------------------------------
*/

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
     WHERE u.status = \'active\'
     ORDER BY u.display_name ASC, u.username ASC, u.id ASC'
);

$users = $userStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
$usersById = [];

foreach ($users as $userRow) {
    $usersById[(int) $userRow['id']] = $userRow;
}

/*
|--------------------------------------------------------------------------
| School Years
|--------------------------------------------------------------------------
*/

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

/*
|--------------------------------------------------------------------------
| Selection State
|--------------------------------------------------------------------------
*/

$selectedUserId = admin_points_query_id('user');
$selectedSchoolYearId = admin_points_query_id('school_year');

if ($selectedUserId <= 0 && isset($_POST['user_id'])) {
    $selectedUserId = admin_points_post_id('user_id');
}

if ($selectedSchoolYearId <= 0 && isset($_POST['school_year_id'])) {
    $selectedSchoolYearId = admin_points_post_id('school_year_id');
}

if (!isset($usersById[$selectedUserId])) {
    $selectedUserId = 0;
}

if (!isset($schoolYearsById[$selectedSchoolYearId])) {
    $selectedSchoolYearId = $currentSchoolYearId;
}

$selectedUser = $selectedUserId > 0 ? ($usersById[$selectedUserId] ?? null) : null;
$selectedSchoolYear = $selectedSchoolYearId > 0 ? ($schoolYearsById[$selectedSchoolYearId] ?? null) : null;

/*
|--------------------------------------------------------------------------
| Actions
|--------------------------------------------------------------------------
*/

$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!verify_csrf_token($_POST['_csrf_token'] ?? null)) {
        $errors[] = 'Your form session expired. Refresh the page and try again.';
    }

    $action = admin_points_post_string('action');

    if ($errors === [] && $action === 'change_house_points') {
        $userId = admin_points_post_id('user_id');
        $schoolYearId = admin_points_post_id('school_year_id');
        $direction = admin_points_post_string('direction');
        $category = admin_points_post_string('category');
        $description = admin_points_post_string('description');
        $isPublic = isset($_POST['is_public']);

        if (!isset($usersById[$userId])) {
            $errors[] = 'Choose a valid member.';
        }

        if (!isset($schoolYearsById[$schoolYearId])) {
            $errors[] = 'Choose a valid school year.';
        }

        if ($direction === 'award' && !$canAward) {
            $errors[] = 'You do not have permission to award House Points.';
        } elseif ($direction === 'deduct' && !$canDeduct) {
            $errors[] = 'You do not have permission to deduct House Points.';
        } elseif (!in_array($direction, ['award', 'deduct'], true)) {
            $errors[] = 'Choose whether to award or deduct points.';
        }

        $targetUser = $usersById[$userId] ?? null;

        if (
            is_array($targetUser)
            && ($targetUser['house_id'] ?? null) === null
        ) {
            $errors[] = 'House Points cannot be changed until this member has an active House membership.';
        }

        $schoolYear = $schoolYearsById[$schoolYearId] ?? null;

        if (is_array($schoolYear)) {
            if ((int) ($schoolYear['is_active'] ?? 0) !== 1) {
                $errors[] = 'Points cannot be changed for an inactive school year.';
            }

            if ((int) ($schoolYear['is_finalized'] ?? 0) === 1) {
                $errors[] = 'Points cannot be changed after a school year has been finalized.';
            }
        }

        if ($description === '') {
            $errors[] = 'A reason or description is required for every staff point change.';
        } elseif (function_exists('mb_strlen') && mb_strlen($description, 'UTF-8') > 255) {
            $errors[] = 'The reason or description must be 255 characters or fewer.';
        } elseif (!function_exists('mb_strlen') && strlen($description) > 255) {
            $errors[] = 'The reason or description must be 255 characters or fewer.';
        }

        $amount = null;

        try {
            $amount = admin_points_decimal(
                admin_points_post_string('amount'),
                'Point amount'
            );
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }

        $sourceType = 'manual';

        if ($direction === 'award') {
            if (!in_array($category, ['manual', 'bonus'], true)) {
                $category = 'manual';
            }
            $sourceType = $category;
        } elseif ($direction === 'deduct') {
            $sourceType = 'penalty';
        }

        if ($errors === [] && $amount !== null) {
            try {
                $signedAmount = $direction === 'deduct'
                    ? '-' . $amount
                    : $amount;

                $result = points_award(
                    $pdo,
                    $userId,
                    'house_only',
                    $sourceType,
                    $signedAmount,
                    [
                        'school_year_id' => $schoolYearId,
                        'description' => $description,
                        'is_public' => $isPublic,
                        'awarded_by' => $currentUserId,
                    ]
                );

                $ledger = $result['ledger'] ?? null;
                $ledgerId = is_array($ledger) ? (int) ($ledger['id'] ?? 0) : 0;

                admin_points_audit(
                    $pdo,
                    $currentUserId,
                    $direction === 'deduct' ? 'points.house.deduct' : 'points.house.award',
                    $userId,
                    $ledgerId,
                    ($direction === 'deduct' ? 'Deducted ' : 'Awarded ')
                    . number_format((float) $amount, 2)
                    . ' House Points for '
                    . (string) ($targetUser['display_name'] ?? ('User #' . $userId))
                    . '. Reason: '
                    . $description
                );

                set_flash(
                    'success',
                    $direction === 'deduct'
                        ? 'House Points deducted successfully. The member was notified.'
                        : 'House Points awarded successfully. The member was notified.'
                );

                redirect(
                    url(
                        'admin/points.php?user=' . $userId
                        . '&school_year=' . $schoolYearId
                    )
                );
            } catch (Throwable $exception) {
                error_log('Staff House Point change error: ' . $exception->getMessage());
                $errors[] = 'The House Point change could not be saved: ' . $exception->getMessage();
            }
        }
    }

    if ($errors === [] && $action === 'reverse_house_points') {
        if (!$canReverse) {
            $errors[] = 'You do not have permission to reverse point transactions.';
        }

        $ledgerId = admin_points_post_id('ledger_id');
        $reason = admin_points_post_string('reversal_reason');

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
                    throw new RuntimeException('HW Point transactions cannot be changed from House Point management.');
                }

                if (!in_array((string) ($entry['source_type'] ?? ''), ['manual', 'bonus', 'penalty'], true)) {
                    throw new RuntimeException('Automatic achievement and system point transactions must be corrected from their originating feature.');
                }

                $result = points_reverse(
                    $pdo,
                    $ledgerId,
                    $currentUserId,
                    $reason
                );

                admin_points_audit(
                    $pdo,
                    $currentUserId,
                    'points.house.reverse',
                    (int) $entry['user_id'],
                    $ledgerId,
                    'Reversed House Point transaction #' . $ledgerId . '. Reason: ' . $reason
                );

                set_flash('success', 'House Point transaction reversed. The member was notified.');

                redirect(
                    url(
                        'admin/points.php?user=' . (int) $entry['user_id']
                        . '&school_year=' . (int) $entry['school_year_id']
                    )
                );
            } catch (Throwable $exception) {
                error_log('House Point reversal error: ' . $exception->getMessage());
                $errors[] = 'The House Point transaction could not be reversed: ' . $exception->getMessage();
            }
        }
    }
}

/* Reload selection after POST validation errors. */
if ($selectedUserId > 0) {
    $selectedUser = $usersById[$selectedUserId] ?? null;
}

/*
|--------------------------------------------------------------------------
| Totals / History
|--------------------------------------------------------------------------
*/

$totals = null;
$history = [];

if ($selectedUserId > 0 && $selectedSchoolYearId > 0) {
    $totals = points_student_totals($pdo, $selectedUserId, $selectedSchoolYearId);

    if ($canViewHistory || $canReverse) {
        $allHistory = points_student_history(
            $pdo,
            $selectedUserId,
            $selectedSchoolYearId,
            100,
            0,
            true
        );

        foreach ($allHistory as $entry) {
            if ((string) ($entry['point_type'] ?? '') === 'house_only') {
                $history[] = $entry;
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Sidebar / Header
|--------------------------------------------------------------------------
*/

$pointsSidebarActive = 'award-points';
require INCLUDES_PATH . '/staff-points-sidebar.php';

/* Make this destination live immediately, even before the shared sidebar's
 * next replacement is uploaded. */
foreach ($dashboardSidebarItems as &$sidebarItem) {
    if (($sidebarItem['type'] ?? '') !== 'group' || ($sidebarItem['label'] ?? '') !== 'Points') {
        continue;
    }

    foreach ($sidebarItem['children'] as &$child) {
        if (($child['label'] ?? '') === 'Award / Deduct Points') {
            $child['href'] = url('admin/points.php');
            $child['disabled'] = false;
            unset($child['meta']);
            $child['active'] = true;
        }
    }
    unset($child);
}
unset($sidebarItem);

$pageTitle = 'House Points | Blackthorne Academy';
$pageDescription = 'Award, deduct, review, and correct staff-controlled House Points.';
$pageCanonical = url('admin/points.php');
$robots = 'noindex, nofollow';

require INCLUDES_PATH . '/header.php';

$heroPng = 'assets/images/staff_dashboard_hero_bg.png';
$heroWebp = 'assets/images/staff_dashboard_hero_bg.webp';
$projectRoot = dirname(__DIR__);
$heroReference = is_file($projectRoot . '/' . $heroWebp) ? $heroWebp : $heroPng;
$heroUrl = is_file($projectRoot . '/' . $heroReference) ? url($heroReference) : '';
?>

<main id="main-content" class="dashboard-page staff-dashboard-page dashboard-workspace-page points-admin-page">
    <section
        class="dashboard-hero staff-dashboard-hero"
        aria-labelledby="points-heading"
        <?php if ($heroUrl !== ''): ?>
            style="--staff-dashboard-hero-image: url('<?= e($heroUrl); ?>');"
        <?php endif; ?>
    >
        <div class="section-inner">
            <div class="dashboard-hero-inner">
                <p class="academy-overline">House Records</p>
                <h1 id="points-heading">House Point Management</h1>
                <p class="dashboard-hero-copy">
                    Award and deduct staff-controlled House Points while preserving a complete transaction history.
                    HW Points are coursework records and cannot be edited here.
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
                        <p class="academy-overline">Staff Point Controls</p>
                        <h2>Award / Deduct House Points</h2>
                        <p>
                            Every change creates a ledger transaction and sends the member a House Point notification.
                            Existing HW Points are intentionally excluded from all controls on this page.
                        </p>
                    </div>
                </header>

                <?php if ($errors !== []): ?>
                    <div class="form-message form-message-error" role="alert">
                        <strong>The point change could not be completed.</strong>
                        <ul>
                            <?php foreach ($errors as $error): ?>
                                <li><?= e((string) $error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <section class="dashboard-workspace-panel">
                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">Select Record</p>
                            <h3>Member &amp; School Year</h3>
                        </div>
                    </div>
                    <div class="dashboard-panel-body">
                        <form method="get" class="forum-admin-form-grid">
                            <div class="form-group">
                                <label for="points-user">Member</label>
                                <select class="form-control" id="points-user" name="user" required>
                                    <option value="">Choose a member</option>
                                    <?php foreach ($users as $userRow): ?>
                                        <?php
                                        $userId = (int) $userRow['id'];
                                        $houseLabel = trim((string) ($userRow['house_display_name'] ?? $userRow['house_name'] ?? ''));
                                        ?>
                                        <option value="<?= $userId; ?>" <?= $selectedUserId === $userId ? 'selected' : ''; ?>>
                                            <?= e((string) $userRow['display_name']); ?>
                                            (<?= e((string) $userRow['username']); ?>)
                                            <?= $houseLabel !== '' ? ' · ' . e($houseLabel) : ' · Unsorted'; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="points-school-year">School Year</label>
                                <select class="form-control" id="points-school-year" name="school_year" required>
                                    <?php foreach ($schoolYears as $schoolYear): ?>
                                        <?php
                                        $yearId = (int) $schoolYear['id'];
                                        $yearSuffix = [];
                                        if ((int) $schoolYear['is_current'] === 1) {
                                            $yearSuffix[] = 'Current';
                                        }
                                        if ((int) $schoolYear['is_finalized'] === 1) {
                                            $yearSuffix[] = 'Finalized';
                                        } elseif ((int) $schoolYear['is_active'] !== 1) {
                                            $yearSuffix[] = 'Inactive';
                                        }
                                        ?>
                                        <option value="<?= $yearId; ?>" <?= $selectedSchoolYearId === $yearId ? 'selected' : ''; ?>>
                                            <?= e((string) $schoolYear['name']); ?><?= $yearSuffix !== [] ? ' · ' . e(implode(' · ', $yearSuffix)) : ''; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="forum-admin-actions">
                                <button type="submit" class="button button-secondary">Load Member</button>
                            </div>
                        </form>
                    </div>
                </section>

                <?php if (is_array($selectedUser) && is_array($selectedSchoolYear)): ?>
                    <?php
                    $selectedHouseName = trim((string) ($selectedUser['house_display_name'] ?? $selectedUser['house_name'] ?? ''));
                    $houseOnlyTotal = (float) ($totals['house_only'] ?? 0);
                    $academicTotal = (float) ($totals['academic'] ?? 0);
                    $combinedTotal = (float) ($totals['total'] ?? 0);
                    $yearWritable =
                        (int) ($selectedSchoolYear['is_active'] ?? 0) === 1
                        && (int) ($selectedSchoolYear['is_finalized'] ?? 0) !== 1;
                    ?>

                    <section class="dashboard-workspace-panel">
                        <div class="dashboard-panel-titlebar">
                            <div>
                                <p class="academy-overline">Selected Member</p>
                                <h3><?= e((string) $selectedUser['display_name']); ?></h3>
                            </div>
                            <a class="button button-secondary" href="<?= e(url('profile.php?u=' . (int) $selectedUser['id'])); ?>">
                                View Profile
                            </a>
                        </div>
                        <div class="dashboard-panel-body">
                            <div class="dashboard-placeholder-list">
                                <span><strong>House:</strong> <?= $selectedHouseName !== '' ? e($selectedHouseName) : 'Not Sorted'; ?></span>
                                <span><strong>School Year:</strong> <?= e((string) $selectedSchoolYear['name']); ?></span>
                                <span><strong>House Points:</strong> <?= number_format($houseOnlyTotal, 2); ?></span>
                                <span><strong>HW Points:</strong> <?= number_format($academicTotal, 2); ?> <small>(read-only here)</small></span>
                                <span><strong>Total House Contribution:</strong> <?= number_format($combinedTotal, 2); ?></span>
                            </div>
                        </div>
                    </section>

                    <?php if (($canAward || $canDeduct) && $yearWritable): ?>
                        <section class="dashboard-workspace-panel">
                            <div class="dashboard-panel-titlebar">
                                <div>
                                    <p class="academy-overline">New Transaction</p>
                                    <h3>Change House Points</h3>
                                </div>
                            </div>
                            <div class="dashboard-panel-body">
                                <?php if ($selectedHouseName === ''): ?>
                                    <div class="form-message form-message-error" role="alert">
                                        This member has not been sorted into an active House. House Points cannot be awarded or deducted yet.
                                    </div>
                                <?php else: ?>
                                    <form method="post">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="change_house_points">
                                        <input type="hidden" name="user_id" value="<?= (int) $selectedUser['id']; ?>">
                                        <input type="hidden" name="school_year_id" value="<?= (int) $selectedSchoolYear['id']; ?>">

                                        <div class="forum-admin-form-grid">
                                            <div class="form-group">
                                                <label for="points-direction">Change</label>
                                                <select class="form-control" id="points-direction" name="direction" required>
                                                    <?php if ($canAward): ?>
                                                        <option value="award">Award House Points</option>
                                                    <?php endif; ?>
                                                    <?php if ($canDeduct): ?>
                                                        <option value="deduct">Deduct House Points</option>
                                                    <?php endif; ?>
                                                </select>
                                            </div>

                                            <div class="form-group">
                                                <label for="points-amount">Amount</label>
                                                <input class="form-control" id="points-amount" name="amount" type="number" min="0.01" step="0.01" required>
                                                <p class="form-help">Enter a positive number. The selected action determines whether it is added or deducted.</p>
                                            </div>
                                        </div>

                                        <div class="forum-admin-form-grid">
                                            <div class="form-group">
                                                <label for="points-category">Award Category</label>
                                                <select class="form-control" id="points-category" name="category">
                                                    <option value="manual">Staff Award</option>
                                                    <option value="bonus">Bonus / Special Recognition</option>
                                                </select>
                                                <p class="form-help">Deductions are always recorded as a penalty regardless of this selection.</p>
                                            </div>

                                            <div class="form-group">
                                                <label class="forum-admin-choice" style="margin-top:2rem;">
                                                    <input type="checkbox" name="is_public" value="1">
                                                    <span>Show this House Point change in the public House feed</span>
                                                </label>
                                            </div>
                                        </div>

                                        <div class="form-group">
                                            <label for="points-description">Reason / Description</label>
                                            <input
                                                class="form-control"
                                                id="points-description"
                                                name="description"
                                                type="text"
                                                maxlength="255"
                                                placeholder="Example: Outstanding contribution during the House event"
                                                required
                                            >
                                            <p class="form-help">This reason is stored with the ledger transaction and included in the member notification.</p>
                                        </div>

                                        <div class="forum-admin-actions">
                                            <button type="submit" class="button button-primary">Save House Point Change</button>
                                        </div>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </section>
                    <?php elseif (($canAward || $canDeduct) && !$yearWritable): ?>
                        <div class="form-message form-message-error" role="status">
                            This school year is finalized or inactive, so its point records are read-only.
                        </div>
                    <?php endif; ?>

                    <?php if ($canViewHistory || $canReverse): ?>
                        <section class="dashboard-workspace-panel">
                            <div class="dashboard-panel-titlebar">
                                <div>
                                    <p class="academy-overline">Ledger</p>
                                    <h3>House Point History</h3>
                                </div>
                            </div>
                            <div class="dashboard-panel-body">
                                <?php if ($history === []): ?>
                                    <p>No House Point transactions have been recorded for this member in the selected school year.</p>
                                <?php else: ?>
                                    <div style="overflow-x:auto;">
                                        <table class="forum-admin-table" style="width:100%;">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Change</th>
                                                    <th>Source</th>
                                                    <th>Description</th>
                                                    <th>Staff</th>
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
                                                    $canReverseThis =
                                                        $canReverse
                                                        && !$entryReversed
                                                        && $yearWritable
                                                        && in_array($sourceType, ['manual', 'bonus', 'penalty'], true);
                                                    ?>
                                                    <tr>
                                                        <td><?= e((string) ($entry['created_at'] ?? '')); ?></td>
                                                        <td><?= $entryPoints > 0 ? '+' : ''; ?><?= number_format($entryPoints, 2); ?></td>
                                                        <td><?= e(ucwords(str_replace('_', ' ', $sourceType))); ?></td>
                                                        <td><?= e((string) ($entry['description'] ?? '')); ?></td>
                                                        <td><?= e((string) ($entry['awarded_by_display_name'] ?? 'System')); ?></td>
                                                        <td><?= $entryReversed ? 'Reversed' : 'Active'; ?></td>
                                                        <?php if ($canReverse): ?>
                                                            <td>
                                                                <?php if ($canReverseThis): ?>
                                                                    <form method="post" style="display:flex;gap:.5rem;align-items:center;min-width:260px;">
                                                                        <?= csrf_field(); ?>
                                                                        <input type="hidden" name="action" value="reverse_house_points">
                                                                        <input type="hidden" name="ledger_id" value="<?= (int) $entry['id']; ?>">
                                                                        <input type="hidden" name="user_id" value="<?= (int) $selectedUser['id']; ?>">
                                                                        <input type="hidden" name="school_year_id" value="<?= (int) $selectedSchoolYear['id']; ?>">
                                                                        <input class="form-control" type="text" name="reversal_reason" maxlength="255" placeholder="Reversal reason" required>
                                                                        <button type="submit" class="button button-secondary">Reverse</button>
                                                                    </form>
                                                                <?php elseif ($sourceType === 'achievement'): ?>
                                                                    Automatic
                                                                <?php else: ?>
                                                                    —
                                                                <?php endif; ?>
                                                            </td>
                                                        <?php endif; ?>
                                                    </tr>
                                                    <?php if ($entryReversed && trim((string) ($entry['reversal_reason'] ?? '')) !== ''): ?>
                                                        <tr>
                                                            <td colspan="<?= $canReverse ? 7 : 6; ?>">
                                                                <small>
                                                                    Reversal reason: <?= e((string) $entry['reversal_reason']); ?>
                                                                    <?php if (trim((string) ($entry['reversed_by_display_name'] ?? '')) !== ''): ?>
                                                                        · by <?= e((string) $entry['reversed_by_display_name']); ?>
                                                                    <?php endif; ?>
                                                                </small>
                                                            </td>
                                                        </tr>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </section>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>
</main>

<script>
(function () {
    const direction = document.getElementById('points-direction');
    const category = document.getElementById('points-category');

    if (!direction || !category) {
        return;
    }

    const syncCategory = function () {
        const deducting = direction.value === 'deduct';
        category.disabled = deducting;
        category.setAttribute('aria-disabled', deducting ? 'true' : 'false');
    };

    direction.addEventListener('change', syncCategory);
    syncCategory();
}());
</script>

<?php require INCLUDES_PATH . '/footer.php'; ?>
