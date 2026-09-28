<?php

declare(strict_types=1);

/**
 * Blackthorne Academy
 * Dedicated Dashboard Sidebar
 *
 * This component is intentionally separate from includes/member-sidebar.php.
 *
 * Expected variables:
 * - $dashboardSidebarTitle    string
 * - $dashboardSidebarEyebrow string
 * - $dashboardSidebarItems   array<int,array<string,mixed>>
 * - $dashboardSidebarFooter  array<int,array<string,mixed>> (optional)
 *
 * Sidebar items may be normal links or grouped navigation items. A group uses:
 * - type     => 'group'
 * - label    => Group title
 * - href     => Optional parent destination
 * - children => Child navigation items
 */

$dashboardSidebarTitle = isset($dashboardSidebarTitle)
    ? trim((string) $dashboardSidebarTitle)
    : 'Dashboard';

$dashboardSidebarEyebrow = isset($dashboardSidebarEyebrow)
    ? trim((string) $dashboardSidebarEyebrow)
    : 'Workspace';

$dashboardSidebarItems = isset($dashboardSidebarItems) && is_array($dashboardSidebarItems)
    ? $dashboardSidebarItems
    : [];

$dashboardSidebarFooter = isset($dashboardSidebarFooter) && is_array($dashboardSidebarFooter)
    ? $dashboardSidebarFooter
    : [];

$currentRequestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';

$dashboardSidebarItemIsActive = static function (array $item) use ($currentRequestPath): bool {
    if ((bool) ($item['active'] ?? false)) {
        return true;
    }

    $href = trim((string) ($item['href'] ?? ''));
    if ($href === '') {
        return false;
    }

    $itemPath = parse_url($href, PHP_URL_PATH) ?: '';

    return $itemPath !== '' && $itemPath === $currentRequestPath;
};

$renderDashboardSidebarLink = static function (
    array $item,
    bool $nested = false
) use ($dashboardSidebarItemIsActive): void {
    $label = trim((string) ($item['label'] ?? ''));
    $href = trim((string) ($item['href'] ?? ''));
    $meta = trim((string) ($item['meta'] ?? ''));
    $metaColor = trim((string) ($item['meta_color'] ?? ''));

    if (preg_match('/^#[0-9A-Fa-f]{6}$/', $metaColor) !== 1) {
        $metaColor = '';
    }

    $icon = trim((string) ($item['icon'] ?? ($nested ? '›' : '✦')));
    $isDisabled = (bool) ($item['disabled'] ?? false);
    $isActive = $dashboardSidebarItemIsActive($item);

    if ($label === '') {
        return;
    }

    $classes = ['dashboard-nav-link'];

    if ($nested) {
        $classes[] = 'is-nested';
    }

    if ($isActive) {
        $classes[] = 'is-active';
    }

    if ($isDisabled || $href === '') {
        $classes[] = 'is-disabled';
    }

    if ($isDisabled || $href === '') {
        ?>
        <span class="<?= e(implode(' ', $classes)); ?>" aria-disabled="true">
            <span class="dashboard-nav-icon" aria-hidden="true"><?= e($icon); ?></span>
            <span class="dashboard-nav-copy">
                <span class="dashboard-nav-label"><?= e($label); ?></span>
                <?php if ($meta !== ''): ?>
                    <span class="dashboard-nav-meta"<?= $metaColor !== '' ? ' style="color:' . e($metaColor) . ';"' : ''; ?>><?= e($meta); ?></span>
                <?php endif; ?>
            </span>
        </span>
        <?php
        return;
    }
    ?>
    <a class="<?= e(implode(' ', $classes)); ?>" href="<?= e($href); ?>"<?= $isActive ? ' aria-current="page"' : ''; ?>>
        <span class="dashboard-nav-icon" aria-hidden="true"><?= e($icon); ?></span>
        <span class="dashboard-nav-copy">
            <span class="dashboard-nav-label"><?= e($label); ?></span>
            <?php if ($meta !== ''): ?>
                <span class="dashboard-nav-meta"<?= $metaColor !== '' ? ' style="color:' . e($metaColor) . ';"' : ''; ?>><?= e($meta); ?></span>
            <?php endif; ?>
        </span>
    </a>
    <?php
};

$renderDashboardSidebarGroup = static function (array $group) use (
    $dashboardSidebarItemIsActive,
    $renderDashboardSidebarLink
): void {
    $label = trim((string) ($group['label'] ?? ''));
    $href = trim((string) ($group['href'] ?? ''));
    $meta = trim((string) ($group['meta'] ?? ''));
    $children = isset($group['children']) && is_array($group['children'])
        ? array_values(array_filter($group['children'], 'is_array'))
        : [];

    if ($label === '' || ($href === '' && $children === [])) {
        return;
    }

    $groupActive = $dashboardSidebarItemIsActive($group);

    foreach ($children as $child) {
        if ($dashboardSidebarItemIsActive($child)) {
            $groupActive = true;
            break;
        }
    }

    ?>
    <section class="dashboard-nav-group<?= $groupActive ? ' is-active' : ''; ?>">
        <?php if ($href !== ''): ?>
            <a class="dashboard-nav-group-heading" href="<?= e($href); ?>"<?= $dashboardSidebarItemIsActive($group) ? ' aria-current="page"' : ''; ?>>
                <span><?= e($label); ?></span>
                <?php if ($meta !== ''): ?>
                    <small><?= e($meta); ?></small>
                <?php endif; ?>
            </a>
        <?php else: ?>
            <div class="dashboard-nav-group-heading is-static">
                <span><?= e($label); ?></span>
                <?php if ($meta !== ''): ?>
                    <small><?= e($meta); ?></small>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($children !== []): ?>
            <div class="dashboard-nav-children">
                <?php foreach ($children as $child): ?>
                    <?php $renderDashboardSidebarLink($child, true); ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php
};
?>

<aside class="dashboard-workspace-sidebar" aria-label="<?= e($dashboardSidebarTitle); ?> navigation">
    <div class="dashboard-workspace-sidebar-inner">
        <header class="dashboard-workspace-sidebar-header">
            <p class="academy-overline"><?= e($dashboardSidebarEyebrow); ?></p>
            <h2><?= e($dashboardSidebarTitle); ?></h2>
        </header>

        <nav class="dashboard-workspace-nav" aria-label="<?= e($dashboardSidebarTitle); ?>">
            <?php foreach ($dashboardSidebarItems as $item): ?>
                <?php if (!is_array($item)): ?>
                    <?php continue; ?>
                <?php endif; ?>

                <?php if (($item['type'] ?? '') === 'group'): ?>
                    <?php $renderDashboardSidebarGroup($item); ?>
                <?php else: ?>
                    <?php $renderDashboardSidebarLink($item); ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>

        <?php if ($dashboardSidebarFooter !== []): ?>
            <div class="dashboard-workspace-sidebar-footer">
                <?php foreach ($dashboardSidebarFooter as $item): ?>
                    <?php if (is_array($item)): ?>
                        <?php $renderDashboardSidebarLink($item); ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</aside>
