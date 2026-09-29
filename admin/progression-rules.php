<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

/*
|--------------------------------------------------------------------------
| Authentication / Permission
|--------------------------------------------------------------------------
*/

require_login();
require_active_account();

$isProtectedSuperAdmin = current_user_is_superuser();
$hasStaffIdentity =
    $isProtectedSuperAdmin
    || current_user_is_admin()
    || current_user_is_staff();

$canManageProgression =
    $isProtectedSuperAdmin
    || user_can('progression.rules.manage');

if (!$hasStaffIdentity || !$canManageProgression) {
    http_response_code(403);

    $pageTitle = 'Access Denied | Blackthorne Academy';
    $pageDescription = 'You do not have permission to manage academic progression rules.';
    $pageCanonical = url('staff-dashboard.php');
    $robots = 'noindex, nofollow';

    require INCLUDES_PATH . '/header.php';
    ?>

<main id="main-content" class="forum-board-page">
    <section class="forum-board-error">
        <div class="section-inner">
            <p class="academy-overline">Restricted Staff Area</p>
            <h1>Access Denied</h1>
            <p>
                Your account does not have permission to manage
                Academy progression rules.
            </p>
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

/*
|--------------------------------------------------------------------------
| Local Helpers
|--------------------------------------------------------------------------
*/

function progression_rules_post_string(string $key): string
{
    $value = $_POST[$key] ?? '';

    return is_scalar($value)
        ? trim((string) $value)
        : '';
}

function progression_rules_post_id(string $key): int
{
    $value = $_POST[$key] ?? '';

    if (!is_scalar($value) || !ctype_digit((string) $value)) {
        return 0;
    }

    return max(0, (int) $value);
}

function progression_rules_post_bool(string $key): int
{
    return isset($_POST[$key]) ? 1 : 0;
}

function progression_rules_decimal(
    string $value,
    string $label,
    bool $allowBlank = false,
    ?float $minimum = null,
    ?float $maximum = null
): ?string {
    $value = trim($value);

    if ($value === '') {
        if ($allowBlank) {
            return null;
        }

        throw new InvalidArgumentException($label . ' is required.');
    }

    if (!is_numeric($value)) {
        throw new InvalidArgumentException($label . ' must be a valid number.');
    }

    $number = (float) $value;

    if ($minimum !== null && $number < $minimum) {
        throw new InvalidArgumentException(
            $label . ' must be at least ' . rtrim(rtrim(number_format($minimum, 2, '.', ''), '0'), '.') . '.'
        );
    }

    if ($maximum !== null && $number > $maximum) {
        throw new InvalidArgumentException(
            $label . ' cannot be higher than ' . rtrim(rtrim(number_format($maximum, 2, '.', ''), '0'), '.') . '.'
        );
    }

    return number_format($number, 2, '.', '');
}

function progression_rules_audit(
    PDO $pdo,
    int $actorUserId,
    int $ruleId,
    string $description
): void {
    if ($actorUserId <= 0) {
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
            'action_type' => 'progression.rule.save',
            'entity_type' => 'year_progression_rule',
            'entity_id' => $ruleId,
            'description' => $description,
            'ip_address' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500) ?: null,
        ]);
    } catch (Throwable $exception) {
        error_log('Progression rule audit error: ' . $exception->getMessage());
    }
}

/*
|--------------------------------------------------------------------------
| Load Year Groups
|--------------------------------------------------------------------------
*/

$yearGroupStatement = $pdo->query(
    'SELECT
        yg.id,
        yg.name,
        yg.slug,
        yg.year_number,
        yg.description,
        yg.is_active,
        yg.sort_order,
        ypr.id AS rule_id,
        ypr.required_percentage,
        ypr.required_points,
        ypr.next_year_group_id,
        ypr.is_active AS rule_is_active,
        (
            SELECT COUNT(*)
            FROM student_year_enrollments sye
            WHERE sye.year_group_id = yg.id
        ) AS enrollment_count
     FROM year_groups yg
     LEFT JOIN year_progression_rules ypr
        ON ypr.year_group_id = yg.id
     ORDER BY yg.sort_order ASC, yg.year_number ASC, yg.name ASC'
);

$yearGroups = $yearGroupStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
$yearGroupsById = [];

foreach ($yearGroups as $yearGroup) {
    $yearGroupsById[(int) $yearGroup['id']] = $yearGroup;
}

/*
|--------------------------------------------------------------------------
| Form State / Save
|--------------------------------------------------------------------------
*/

$errors = [];
$formValues = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!verify_csrf_token($_POST['_csrf_token'] ?? null)) {
        $errors[] = 'Your form session expired. Refresh the page and try again.';
    }

    $action = progression_rules_post_string('action');

    if ($errors === [] && $action === 'save_progression_rule') {
        $yearGroupId = progression_rules_post_id('year_group_id');
        $nextYearGroupId = progression_rules_post_id('next_year_group_id');
        $requiredPercentageRaw = progression_rules_post_string('required_percentage');
        $requiredPointsRaw = progression_rules_post_string('required_points');
        $isActive = progression_rules_post_bool('is_active');

        $formValues[$yearGroupId] = [
            'required_percentage' => $requiredPercentageRaw,
            'required_points' => $requiredPointsRaw,
            'next_year_group_id' => $nextYearGroupId,
            'is_active' => $isActive,
        ];

        if ($yearGroupId <= 0 || !isset($yearGroupsById[$yearGroupId])) {
            $errors[] = 'Choose a valid Year Group.';
        }

        if ($nextYearGroupId > 0 && !isset($yearGroupsById[$nextYearGroupId])) {
            $errors[] = 'Choose a valid next Year Group.';
        }

        if ($yearGroupId > 0 && $nextYearGroupId === $yearGroupId) {
            $errors[] = 'A Year Group cannot progress into itself.';
        }

        $requiredPercentage = null;
        $requiredPoints = null;

        try {
            $requiredPercentage = progression_rules_decimal(
                $requiredPercentageRaw,
                'Required Percentage',
                false,
                0.0,
                100.0
            );

            $requiredPoints = progression_rules_decimal(
                $requiredPointsRaw,
                'Required HW Points',
                true,
                0.0,
                null
            );
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }

        if ($errors === []) {
            try {
                $pdo->beginTransaction();

                $statement = $pdo->prepare(
                    'INSERT INTO year_progression_rules (
                        year_group_id,
                        required_percentage,
                        required_points,
                        next_year_group_id,
                        is_active
                     ) VALUES (
                        :year_group_id,
                        :required_percentage,
                        :required_points,
                        :next_year_group_id,
                        :is_active
                     )
                     ON DUPLICATE KEY UPDATE
                        required_percentage = VALUES(required_percentage),
                        required_points = VALUES(required_points),
                        next_year_group_id = VALUES(next_year_group_id),
                        is_active = VALUES(is_active)'
                );

                $statement->execute([
                    'year_group_id' => $yearGroupId,
                    'required_percentage' => $requiredPercentage,
                    'required_points' => $requiredPoints,
                    'next_year_group_id' => $nextYearGroupId > 0
                        ? $nextYearGroupId
                        : null,
                    'is_active' => $isActive,
                ]);

                $ruleIdStatement = $pdo->prepare(
                    'SELECT id
                     FROM year_progression_rules
                     WHERE year_group_id = :year_group_id
                     LIMIT 1'
                );
                $ruleIdStatement->execute([
                    'year_group_id' => $yearGroupId,
                ]);

                $ruleId = (int) $ruleIdStatement->fetchColumn();
                $yearGroupName = (string) $yearGroupsById[$yearGroupId]['name'];

                progression_rules_audit(
                    $pdo,
                    $currentUserId,
                    $ruleId,
                    'Saved progression rule for ' . $yearGroupName . '.'
                );

                $pdo->commit();

                set_flash(
                    'success',
                    'Progression rule saved for ' . $yearGroupName . '.'
                );

                redirect(url('admin/progression-rules.php#year-group-' . $yearGroupId));
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log('Progression rule save error: ' . $exception->getMessage());
                $errors[] = 'The progression rule could not be saved.';
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Reload After POST Validation Error
|--------------------------------------------------------------------------
*/

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $errors !== []) {
    $yearGroupStatement = $pdo->query(
        'SELECT
            yg.id,
            yg.name,
            yg.slug,
            yg.year_number,
            yg.description,
            yg.is_active,
            yg.sort_order,
            ypr.id AS rule_id,
            ypr.required_percentage,
            ypr.required_points,
            ypr.next_year_group_id,
            ypr.is_active AS rule_is_active,
            (
                SELECT COUNT(*)
                FROM student_year_enrollments sye
                WHERE sye.year_group_id = yg.id
            ) AS enrollment_count
         FROM year_groups yg
         LEFT JOIN year_progression_rules ypr
            ON ypr.year_group_id = yg.id
         ORDER BY yg.sort_order ASC, yg.year_number ASC, yg.name ASC'
    );

    $yearGroups = $yearGroupStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/*
|--------------------------------------------------------------------------
| Summary
|--------------------------------------------------------------------------
*/

$summary = [
    'year_groups' => count($yearGroups),
    'configured' => 0,
    'active_rules' => 0,
    'with_point_minimum' => 0,
];

foreach ($yearGroups as $yearGroup) {
    if (!empty($yearGroup['rule_id'])) {
        $summary['configured']++;
    }

    if ((int) ($yearGroup['rule_is_active'] ?? 0) === 1) {
        $summary['active_rules']++;
    }

    if ($yearGroup['required_points'] !== null) {
        $summary['with_point_minimum']++;
    }
}

/*
|--------------------------------------------------------------------------
| Shared Points Sidebar
|--------------------------------------------------------------------------
*/

$pointsSidebarActive = 'progression-rules';
require INCLUDES_PATH . '/staff-points-sidebar.php';

/*
 * Until the shared sidebar file is updated in the next step, make the
 * Progression Rules destination live on this page itself.
 */
foreach ($dashboardSidebarItems as &$sidebarItem) {
    if (($sidebarItem['type'] ?? '') !== 'group') {
        continue;
    }

    if (($sidebarItem['label'] ?? '') !== 'Year-End Administration') {
        continue;
    }

    foreach ($sidebarItem['children'] as &$sidebarChild) {
        if (($sidebarChild['label'] ?? '') !== 'Progression Rules') {
            continue;
        }

        $sidebarChild['href'] = url('admin/progression-rules.php');
        $sidebarChild['disabled'] = false;
        unset($sidebarChild['meta']);
        $sidebarChild['active'] = true;
    }
    unset($sidebarChild);
}
unset($sidebarItem);

/*
|--------------------------------------------------------------------------
| SEO / Header
|--------------------------------------------------------------------------
*/

$pageTitle = 'Progression Rules | Blackthorne Academy';
$pageDescription = 'Manage Year Group academic progression requirements.';
$pageCanonical = url('admin/progression-rules.php');
$robots = 'noindex, nofollow';

require INCLUDES_PATH . '/header.php';

$staffHeroPngReference = 'assets/images/staff_dashboard_hero_bg.png';
$staffHeroWebpReference = 'assets/images/staff_dashboard_hero_bg.webp';
$projectRoot = dirname(__DIR__);
$staffHeroReference =
    is_file($projectRoot . '/' . $staffHeroWebpReference)
        ? $staffHeroWebpReference
        : $staffHeroPngReference;
$staffHeroUrl =
    is_file($projectRoot . '/' . $staffHeroReference)
        ? url($staffHeroReference)
        : '';

?>

<main id="main-content"
    class="dashboard-page staff-dashboard-page dashboard-workspace-page progression-rules-admin-page">

    <section class="dashboard-hero staff-dashboard-hero" aria-labelledby="progression-rules-heading"
        <?php if ($staffHeroUrl !== ''): ?> style="--staff-dashboard-hero-image: url('<?= e($staffHeroUrl); ?>');"
        <?php endif; ?>>
        <div class="section-inner">
            <div class="dashboard-hero-inner">
                <p class="academy-overline">Academic Progression</p>
                <h1 id="progression-rules-heading">Progression Rules</h1>
                <p class="dashboard-hero-copy">
                    Define the academic percentage and minimum HW Points a
                    student must earn before becoming eligible to advance.
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
                        <p class="academy-overline">Year Group Requirements</p>
                        <h2>Academic Advancement</h2>
                        <p>
                            Students must satisfy both requirements when a
                            minimum HW Point value is configured. House-only
                            Points never count toward academic progression.
                        </p>
                    </div>
                </header>

                <?php if ($errors !== []): ?>
                <div class="form-message form-message-error" role="alert">
                    <strong>The progression rule could not be saved.</strong>
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
                            <p class="academy-overline">At a Glance</p>
                            <h3>Progression Summary</h3>
                        </div>
                    </div>
                    <div class="dashboard-panel-body">
                        <div class="dashboard-placeholder-list">
                            <span><strong><?= number_format($summary['year_groups']); ?></strong> Year Groups</span>
                            <span><strong><?= number_format($summary['configured']); ?></strong> configured rules</span>
                            <span><strong><?= number_format($summary['active_rules']); ?></strong> active rules</span>
                            <span><strong><?= number_format($summary['with_point_minimum']); ?></strong> require minimum
                                HW Points</span>
                        </div>
                    </div>
                </section>

                <section class="dashboard-workspace-panel">
                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">Important</p>
                            <h3>Requirement Snapshots</h3>
                        </div>
                    </div>
                    <div class="dashboard-panel-body">
                        <p>
                            Changes made here define the rule used when a student
                            begins a Year Group. Existing student requirements are
                            stored separately on their Year enrollment record, so
                            this page does not retroactively rewrite previously
                            snapshotted requirements.
                        </p>
                    </div>
                </section>

                <?php if ($yearGroups === []): ?>
                <section class="dashboard-workspace-panel">
                    <div class="dashboard-panel-body">
                        <p>
                            No Year Groups exist yet. Create Year Groups before
                            configuring progression rules.
                        </p>
                    </div>
                </section>
                <?php else: ?>

                <?php foreach ($yearGroups as $yearGroup): ?>
                <?php
                        $yearGroupId = (int) $yearGroup['id'];
                        $posted = $formValues[$yearGroupId] ?? null;

                        $requiredPercentageValue = $posted !== null
                            ? (string) $posted['required_percentage']
                            : ($yearGroup['required_percentage'] !== null
                                ? (string) $yearGroup['required_percentage']
                                : '85.00');

                        $requiredPointsValue = $posted !== null
                            ? (string) $posted['required_points']
                            : ($yearGroup['required_points'] !== null
                                ? (string) $yearGroup['required_points']
                                : '');

                        $nextYearGroupValue = $posted !== null
                            ? (int) $posted['next_year_group_id']
                            : (int) ($yearGroup['next_year_group_id'] ?? 0);

                        $ruleActiveValue = $posted !== null
                            ? (int) $posted['is_active']
                            : ($yearGroup['rule_id'] !== null
                                ? (int) ($yearGroup['rule_is_active'] ?? 0)
                                : 1);
                        ?>

                <section class="dashboard-workspace-panel" id="year-group-<?= $yearGroupId; ?>">
                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">
                                Year <?= number_format((int) $yearGroup['year_number']); ?>
                            </p>
                            <h3><?= e((string) $yearGroup['name']); ?></h3>
                        </div>
                        <div>
                            <?= (int) $yearGroup['is_active'] === 1
                                        ? 'Active Year Group'
                                        : 'Inactive Year Group'; ?>
                        </div>
                    </div>

                    <div class="dashboard-panel-body">
                        <?php if (trim((string) ($yearGroup['description'] ?? '')) !== ''): ?>
                        <p><?= e((string) $yearGroup['description']); ?></p>
                        <?php endif; ?>

                        <p class="form-help">
                            <?= number_format((int) ($yearGroup['enrollment_count'] ?? 0)); ?>
                            student Year enrollment
                            record<?= (int) ($yearGroup['enrollment_count'] ?? 0) === 1 ? '' : 's'; ?>
                            currently reference this Year Group.
                        </p>

                        <form method="post" novalidate>
                            <?= csrf_field(); ?>
                            <input type="hidden" name="action" value="save_progression_rule">
                            <input type="hidden" name="year_group_id" value="<?= $yearGroupId; ?>">

                            <div class="forum-admin-form-grid">
                                <div class="form-group">
                                    <label for="required-percentage-<?= $yearGroupId; ?>">
                                        Required Academic Percentage
                                    </label>
                                    <input class="form-control" type="number"
                                        id="required-percentage-<?= $yearGroupId; ?>" name="required_percentage" min="0"
                                        max="100" step="0.01" value="<?= e($requiredPercentageValue); ?>" required>
                                    <p class="form-help">
                                        The student must meet or exceed this
                                        overall academic percentage.
                                    </p>
                                </div>

                                <div class="form-group">
                                    <label for="required-points-<?= $yearGroupId; ?>">
                                        Minimum Required HW Points
                                    </label>
                                    <input class="form-control" type="number" id="required-points-<?= $yearGroupId; ?>"
                                        name="required_points" min="0" step="0.01"
                                        value="<?= e($requiredPointsValue); ?>" placeholder="No minimum">
                                    <p class="form-help">
                                        Leave blank for no minimum HW Point
                                        requirement. House Points do not count.
                                    </p>
                                </div>
                            </div>

                            <div class="forum-admin-form-grid">
                                <div class="form-group">
                                    <label for="next-year-group-<?= $yearGroupId; ?>">
                                        Next Year Group
                                    </label>
                                    <select class="form-control" id="next-year-group-<?= $yearGroupId; ?>"
                                        name="next_year_group_id">
                                        <option value="">No next Year Group / final level</option>
                                        <?php foreach ($yearGroups as $nextYearGroup): ?>
                                        <?php
                                                    $candidateId = (int) $nextYearGroup['id'];
                                                    if ($candidateId === $yearGroupId) {
                                                        continue;
                                                    }
                                                    ?>
                                        <option value="<?= $candidateId; ?>"
                                            <?= $nextYearGroupValue === $candidateId ? 'selected' : ''; ?>>
                                            <?= e((string) $nextYearGroup['name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="form-help">
                                        Leave blank if this is the final Academy
                                        Year Group rather than a promotion step.
                                    </p>
                                </div>

                                <div class="form-group">
                                    <label class="forum-admin-choice" style="margin-top: 2rem;">
                                        <input type="checkbox" name="is_active" value="1"
                                            <?= $ruleActiveValue === 1 ? 'checked' : ''; ?>>
                                        <span>Progression rule is active</span>
                                    </label>
                                    <p class="form-help">
                                        Inactive rules remain stored but should not
                                        be used for new requirement snapshots.
                                    </p>
                                </div>
                            </div>

                            <div class="forum-admin-actions">
                                <button type="submit" class="button button-primary">
                                    <?= $yearGroup['rule_id'] !== null
                                                ? 'Save Progression Rule'
                                                : 'Create Progression Rule'; ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </section>

                <?php endforeach; ?>

                <?php endif; ?>

            </div>
        </div>
    </section>

</main>

<?php
require INCLUDES_PATH . '/footer.php';
