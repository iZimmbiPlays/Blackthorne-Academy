<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/forum-image-map-functions.php';

require_active_account();
require_permission('manage_forums');


/*
|--------------------------------------------------------------------------
| Local Helpers
|--------------------------------------------------------------------------
*/

function admin_forum_slugify(string $value): string
{
    $value = function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);

    $value = trim($value);

    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        if (is_string($converted)) {
            $value = strtolower($converted);
        }
    }

    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');

    return substr($value, 0, 160);
}


function admin_forum_text_length(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);
}


function admin_forum_lower(string $value): string
{
    return function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
}


function admin_forum_post_ids(string $key): array
{
    $values = $_POST[$key] ?? [];

    if (!is_array($values)) {
        return [];
    }

    $ids = [];

    foreach ($values as $value) {
        if (is_scalar($value) && ctype_digit((string) $value)) {
            $id = (int) $value;

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
    }

    return array_values($ids);
}


function admin_forum_permission_selection(
    string $key,
    array $validRoleIds
): array {
    $values = $_POST[$key] ?? ['everyone'];

    if (!is_array($values)) {
        $values = ['everyone'];
    }

    $everyone = in_array('everyone', $values, true);
    $roleIds = [];

    foreach ($values as $value) {
        if (!is_scalar($value) || !ctype_digit((string) $value)) {
            continue;
        }

        $roleId = (int) $value;

        if (isset($validRoleIds[$roleId])) {
            $roleIds[$roleId] = $roleId;
        }
    }

    return [
        'everyone' => $everyone,
        'role_ids' => array_values($roleIds),
    ];
}


function admin_forum_selected(string $key, string $value): bool
{
    $values = $_POST[$key] ?? ['everyone'];

    return is_array($values)
        && in_array($value, array_map('strval', $values), true);
}



function admin_forum_descendant_ids(
    PDO $pdo,
    int $forumId
): array {
    if ($forumId <= 0) {
        return [];
    }

    $statement = $pdo->prepare(
        'WITH RECURSIVE forum_descendants AS (
            SELECT id
            FROM forums
            WHERE parent_forum_id = :forum_id

            UNION ALL

            SELECT f.id
            FROM forums f
            INNER JOIN forum_descendants d
                ON f.parent_forum_id = d.id
        )
        SELECT id
        FROM forum_descendants'
    );

    $statement->execute([
        'forum_id' => $forumId,
    ]);

    return array_map(
        'intval',
        $statement->fetchAll(PDO::FETCH_COLUMN)
    );
}


function admin_forum_flatten_hierarchy(
    array $forumsByParent,
    int $parentId = 0,
    int $depth = 0
): array {
    $flattened = [];

    foreach ($forumsByParent[$parentId] ?? [] as $forum) {
        $forum['hierarchy_depth'] = $depth;
        $flattened[] = $forum;

        $forumId = (int) ($forum['id'] ?? 0);

        foreach (
            admin_forum_flatten_hierarchy(
                $forumsByParent,
                $forumId,
                $depth + 1
            )
            as $child
        ) {
            $flattened[] = $child;
        }
    }

    return $flattened;
}



function admin_forum_render_image_map_builder(
    string $idPrefix,
    ?array $map,
    array $forumOptions,
    array $savedMaps
): void {
    $enabled = $map !== null;
    $selectedMapId = $enabled ? (int) ($map['id'] ?? 0) : 0;
    $mapName = $enabled ? trim((string) ($map['name'] ?? '')) : '';
    $imagePath = $enabled ? trim((string) ($map['image_path'] ?? '')) : '';
    $webpPath = $enabled ? trim((string) ($map['webp_path'] ?? '')) : '';
    $imageAlt = $enabled ? trim((string) ($map['image_alt'] ?? '')) : '';
    $width = $enabled ? (int) ($map['original_width'] ?? 0) : 0;
    $height = $enabled ? (int) ($map['original_height'] ?? 0) : 0;
    $areas = $enabled && is_array($map['areas'] ?? null) ? $map['areas'] : [];

    $serializedAreas = [];

    foreach ($areas as $area) {
        $serializedAreas[] = [
            'shape' => (string) ($area['shape'] ?? ''),
            'coords' => array_values(array_map('floatval', (array) ($area['coords'] ?? []))),
            'link_type' => (string) ($area['link_type'] ?? 'url'),
            'target_forum_id' => (int) ($area['target_forum_id'] ?? 0),
            'link_url' => (string) ($area['link_url'] ?? ''),
            'title' => (string) ($area['title'] ?? ''),
            'alt_text' => (string) ($area['alt_text'] ?? ''),
            'target' => (string) ($area['link_target'] ?? '_self'),
        ];
    }

    $forumTargetOptions = [];

    foreach ($forumOptions as $forumOption) {
        $forumTargetOptions[] = [
            'id' => (int) ($forumOption['id'] ?? 0),
            'title' => (string) ($forumOption['title'] ?? ''),
            'parent_forum_id' => (int) ($forumOption['parent_forum_id'] ?? 0),
        ];
    }

    $savedMapPayload = [];

    foreach ($savedMaps as $savedMap) {
        $savedAreas = [];
        foreach ((array) ($savedMap['areas'] ?? []) as $savedArea) {
            $savedAreas[] = [
                'shape' => (string) ($savedArea['shape'] ?? ''),
                'coords' => array_values(array_map('floatval', (array) ($savedArea['coords'] ?? []))),
                'link_type' => (string) ($savedArea['link_type'] ?? 'url'),
                'target_forum_id' => (int) ($savedArea['target_forum_id'] ?? 0),
                'link_url' => (string) ($savedArea['link_url'] ?? ''),
                'title' => (string) ($savedArea['title'] ?? ''),
                'alt_text' => (string) ($savedArea['alt_text'] ?? ''),
                'target' => (string) ($savedArea['link_target'] ?? '_self'),
            ];
        }

        $savedMapPayload[] = [
            'id' => (int) ($savedMap['id'] ?? 0),
            'name' => (string) ($savedMap['name'] ?? ''),
            'image_path' => (string) ($savedMap['image_path'] ?? ''),
            'image_url' => (string) ($savedMap['image_path'] ?? '') !== '' ? url((string) $savedMap['image_path']) : '',
            'webp_path' => (string) ($savedMap['webp_path'] ?? ''),
            'webp_url' => (string) ($savedMap['webp_path'] ?? '') !== '' ? url((string) $savedMap['webp_path']) : '',
            'image_alt' => (string) ($savedMap['image_alt'] ?? ''),
            'original_width' => (int) ($savedMap['original_width'] ?? 0),
            'original_height' => (int) ($savedMap['original_height'] ?? 0),
            'usage_count' => (int) ($savedMap['usage_count'] ?? 0),
            'areas' => $savedAreas,
        ];
    }
    ?>

<fieldset class="forum-admin-fieldset forum-image-map-fieldset" data-image-map-builder
    data-builder-id="<?= e($idPrefix); ?>"
    data-existing-areas="<?= e(json_encode($serializedAreas, JSON_UNESCAPED_SLASHES)); ?>"
    data-forum-options="<?= e(json_encode($forumTargetOptions, JSON_UNESCAPED_SLASHES)); ?>"
    data-saved-maps="<?= e(json_encode($savedMapPayload, JSON_UNESCAPED_SLASHES)); ?>"
    data-natural-width="<?= $width; ?>" data-natural-height="<?= $height; ?>">
    <legend>Board Image Map</legend>

    <label class="forum-admin-choice forum-image-map-toggle">
        <input type="checkbox" name="image_map_enabled" value="1" data-image-map-enabled
            <?= $enabled ? 'checked' : ''; ?>>
        <span>Use an image map instead of the normal sub-board list</span>
    </label>

    <p class="form-help">
        Image maps are saved independently from forums. You can reuse the same saved map on multiple boards.
        Editing a reused map updates every board currently using that map.
    </p>

    <div class="forum-image-map-settings" data-image-map-settings <?= $enabled ? '' : 'hidden'; ?>>
        <div class="forum-image-map-source-grid">
            <div class="form-group">
                <label for="<?= e($idPrefix); ?>-saved-map">Saved Image Map</label>
                <select class="form-control" id="<?= e($idPrefix); ?>-saved-map" name="image_map_id"
                    data-saved-map-select>
                    <option value="0" <?= $selectedMapId === 0 ? 'selected' : ''; ?>>Create a new saved image map
                    </option>
                    <?php foreach ($savedMaps as $savedMap): ?>
                    <?php
                            $savedId = (int) ($savedMap['id'] ?? 0);
                            $savedName = trim((string) ($savedMap['name'] ?? ''));
                            if ($savedName === '') {
                                $savedName = 'Image Map #' . $savedId;
                            }
                            $uses = (int) ($savedMap['usage_count'] ?? 0);
                            ?>
                    <option value="<?= $savedId; ?>" <?= $savedId === $selectedMapId ? 'selected' : ''; ?>>
                        <?= e($savedName . ' (' . $uses . ' ' . ($uses === 1 ? 'board' : 'boards') . ')'); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <p class="form-help">
                    Choose an existing map to attach this board to it. Any edits you save here will change that map
                    everywhere it is used.
                </p>
            </div>

            <div class="form-group">
                <label for="<?= e($idPrefix); ?>-map-name">Saved Map Name</label>
                <input class="form-control" type="text" id="<?= e($idPrefix); ?>-map-name" name="image_map_name"
                    maxlength="150" value="<?= e($mapName); ?>" placeholder="Example: Common Room Map" data-map-name>
                <p class="form-help">This is the name staff will see when selecting this map later.</p>
            </div>
        </div>

        <div class="forum-image-map-shared-warning" data-shared-map-warning <?= $selectedMapId > 0 ? '' : 'hidden'; ?>>
            <strong>Shared map:</strong> saving changes to this map updates every forum that uses it.
        </div>

        <div class="form-group">
            <label for="<?= e($idPrefix); ?>-image">Map Image</label>
            <input class="form-control" type="file" id="<?= e($idPrefix); ?>-image" name="image_map_image"
                accept="image/png,image/jpeg,image/gif,image/webp" data-image-map-file>
            <p class="form-help">
                PNG, JPG, GIF, or WebP. The site automatically prefers a generated WebP version when one exists and
                keeps the original as fallback.
                <?= $enabled ? 'Leave this empty to keep the saved map image.' : ''; ?>
            </p>
        </div>

        <div class="form-group">
            <label for="<?= e($idPrefix); ?>-alt">Image Alt Text</label>
            <input class="form-control" type="text" id="<?= e($idPrefix); ?>-alt" name="image_map_alt" maxlength="255"
                value="<?= e($imageAlt); ?>" placeholder="Describe the map image for members using assistive technology"
                data-map-alt>
        </div>

        <input type="hidden" name="image_map_areas_json"
            value="<?= e(json_encode($serializedAreas, JSON_UNESCAPED_SLASHES)); ?>" data-image-map-json>

        <div class="forum-image-map-builder-shell">
            <div class="forum-image-map-toolbar" role="toolbar" aria-label="Image map drawing tools">
                <button class="button button-secondary" type="button" data-map-tool="rect">Rectangle</button>
                <button class="button button-secondary" type="button" data-map-tool="circle">Circle</button>
                <button class="button button-secondary" type="button" data-map-tool="poly">Polygon</button>
                <button class="button button-secondary" type="button" data-map-cancel-tool>Cancel Drawing</button>
                <button class="button button-secondary" type="button" data-map-clear>Clear Areas</button>
            </div>

            <p class="form-help forum-image-map-tool-help" data-map-tool-help>
                Upload or load an image, choose a shape tool, then draw directly on the image.
            </p>

            <div class="forum-image-map-stage" data-map-stage>
                <?php if ($imagePath !== ''): ?>
                <picture>
                    <?php if ($webpPath !== ''): ?>
                    <source srcset="<?= e(url($webpPath)); ?>" type="image/webp">
                    <?php endif; ?>
                    <img src="<?= e(url($imagePath)); ?>" alt="" data-map-image>
                </picture>
                <?php else: ?>
                <img src="" alt="" data-map-image hidden>
                <?php endif; ?>
                <svg class="forum-image-map-drawing-layer" data-map-svg
                    <?= $width > 0 && $height > 0 ? 'viewBox="0 0 ' . $width . ' ' . $height . '"' : ''; ?>
                    aria-hidden="true"></svg>
                <div class="forum-image-map-placeholder" data-map-placeholder <?= $imagePath !== '' ? 'hidden' : ''; ?>>
                    Upload an image or choose a saved map to begin.
                </div>
            </div>
        </div>

        <div class="forum-image-map-area-editor">
            <div class="forum-image-map-area-heading">
                <div>
                    <h3>Clickable Areas</h3>
                    <p class="form-help">Each shape can link to a forum/sub-forum or to a custom URL.</p>
                </div>
                <span class="forum-image-map-count" data-map-count>0 areas</span>
            </div>

            <div data-map-area-list></div>
        </div>
    </div>
</fieldset>

<?php
}

/*
|--------------------------------------------------------------------------
| Available Roles and Members
|--------------------------------------------------------------------------
*/

$rolesStatement = $pdo->query(
    'SELECT id, name, slug
     FROM roles
     WHERE is_active = 1
     ORDER BY sort_order ASC, name ASC'
);

$availableRoles = $rolesStatement->fetchAll(PDO::FETCH_ASSOC);
$validRoleIds = [];

foreach ($availableRoles as $role) {
    $validRoleIds[(int) $role['id']] = true;
}


$housesStatement = $pdo->query(
    'SELECT
        id,
        name,
        display_name,
        display_color,
        is_active,
        sort_order
     FROM houses
     WHERE is_active = 1
     ORDER BY sort_order ASC, display_name ASC, name ASC, id ASC'
);

$availableHouses = $housesStatement->fetchAll(PDO::FETCH_ASSOC);
$validHouseIds = [];

foreach ($availableHouses as $house) {
    $validHouseIds[(int) $house['id']] = true;
}

$houseBoardDisplayColors = [];

foreach ($availableHouses as $house) {
    $displayColor = safe_css_color((string) ($house['display_color'] ?? ''));

    if ($displayColor === null) {
        continue;
    }

    foreach (['name', 'display_name'] as $houseNameField) {
        $houseName = trim((string) ($house[$houseNameField] ?? ''));

        if ($houseName === '') {
            continue;
        }

        $houseBoardDisplayColors[admin_forum_lower($houseName)] = $displayColor;
    }
}


$membersStatement = $pdo->query(
    'SELECT id, username, display_name
     FROM users
     WHERE status = "active"
       AND email_verified_at IS NOT NULL
     ORDER BY display_name ASC, username ASC'
);

$availableMembers = $membersStatement->fetchAll(PDO::FETCH_ASSOC);
$validMemberIds = [];

foreach ($availableMembers as $member) {
    $validMemberIds[(int) $member['id']] = true;
}


/*
|--------------------------------------------------------------------------
| Form Processing
|--------------------------------------------------------------------------
*/

$errors = [];
$formAction = post_value('form_action');


if (is_post()) {
    require_valid_csrf();

    if ($formAction === 'reorder_forums') {
        $orderJson = post_value('forum_order_json');
        $orderData = json_decode($orderJson, true);

        if (!is_array($orderData)) {
            $errors[] = 'The forum order data was invalid.';
        } else {
            try {
                $pdo->beginTransaction();

                $categoryRows = $pdo
                    ->query('SELECT id FROM forum_categories FOR UPDATE')
                    ->fetchAll(PDO::FETCH_ASSOC);

                $validCategoryIds = [];
                foreach ($categoryRows as $categoryRow) {
                    $validCategoryIds[(int) $categoryRow['id']] = true;
                }

                $forumRows = $pdo
                    ->query(
                        'SELECT id, category_id, parent_forum_id
                         FROM forums
                         FOR UPDATE'
                    )
                    ->fetchAll(PDO::FETCH_ASSOC);

                $forumsById = [];
                $childCounts = [];

                foreach ($forumRows as $forumRow) {
                    $rowId = (int) $forumRow['id'];
                    $forumsById[$rowId] = [
                        'id' => $rowId,
                        'category_id' => (int) $forumRow['category_id'],
                        'parent_forum_id' => $forumRow['parent_forum_id'] !== null
                            ? (int) $forumRow['parent_forum_id']
                            : 0,
                    ];

                    $parentId = $forumRow['parent_forum_id'] !== null
                        ? (int) $forumRow['parent_forum_id']
                        : 0;

                    if ($parentId > 0) {
                        $childCounts[$parentId] = ($childCounts[$parentId] ?? 0) + 1;
                    }
                }

                $requested = [];

                foreach ($orderData as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }

                    $forumId = isset($entry['id']) ? (int) $entry['id'] : 0;
                    $sortOrder = isset($entry['sort_order']) ? (int) $entry['sort_order'] : 0;
                    $categoryId = isset($entry['category_id']) ? (int) $entry['category_id'] : 0;
                    $parentForumId = isset($entry['parent_forum_id']) ? (int) $entry['parent_forum_id'] : 0;

                    if (
                        $forumId <= 0
                        || $sortOrder < 0
                        || $categoryId <= 0
                        || !isset($forumsById[$forumId])
                        || !isset($validCategoryIds[$categoryId])
                    ) {
                        throw new RuntimeException('The requested forum move contained invalid data.');
                    }

                    if ($parentForumId === $forumId) {
                        throw new RuntimeException('A forum cannot be its own parent.');
                    }

                    if ($parentForumId > 0 && !isset($forumsById[$parentForumId])) {
                        throw new RuntimeException('The requested parent forum does not exist.');
                    }

                    $requested[$forumId] = [
                        'id' => $forumId,
                        'sort_order' => $sortOrder,
                        'category_id' => $categoryId,
                        'parent_forum_id' => $parentForumId,
                    ];
                }

                if ($requested === []) {
                    throw new RuntimeException('No forum positions were supplied.');
                }

                /*
                 * Resolve the complete final hierarchy before writing anything.
                 * Forums may be nested to any depth, but circular relationships
                 * are never allowed. Every descendant inherits the category of
                 * its final parent/root.
                 */
                $finalParents = [];
                $finalCategories = [];

                foreach ($forumsById as $forumId => $forumRow) {
                    $finalParents[$forumId] = isset($requested[$forumId])
                        ? (int) $requested[$forumId]['parent_forum_id']
                        : (int) $forumRow['parent_forum_id'];

                    $finalCategories[$forumId] = isset($requested[$forumId])
                        ? (int) $requested[$forumId]['category_id']
                        : (int) $forumRow['category_id'];
                }

                foreach ($finalParents as $forumId => $parentForumId) {
                    if ($parentForumId === $forumId) {
                        throw new RuntimeException('A forum cannot be its own parent.');
                    }

                    if (
                        $parentForumId > 0
                        && !isset($forumsById[$parentForumId])
                    ) {
                        throw new RuntimeException('The requested parent forum does not exist.');
                    }

                    $seen = [$forumId => true];
                    $cursor = $parentForumId;

                    while ($cursor > 0) {
                        if (isset($seen[$cursor])) {
                            throw new RuntimeException('A forum cannot be moved inside one of its own descendants.');
                        }

                        $seen[$cursor] = true;
                        $cursor = (int) ($finalParents[$cursor] ?? 0);
                    }
                }

                $resolvedCategories = [];

                $resolveForumCategory = static function (
                    int $forumId
                ) use (
                    &$resolveForumCategory,
                    &$resolvedCategories,
                    $finalParents,
                    $finalCategories
                ): int {
                    if (isset($resolvedCategories[$forumId])) {
                        return $resolvedCategories[$forumId];
                    }

                    $parentForumId = (int) ($finalParents[$forumId] ?? 0);

                    if ($parentForumId <= 0) {
                        $resolvedCategories[$forumId] =
                            (int) ($finalCategories[$forumId] ?? 0);

                        return $resolvedCategories[$forumId];
                    }

                    $resolvedCategories[$forumId] =
                        $resolveForumCategory($parentForumId);

                    return $resolvedCategories[$forumId];
                };

                foreach (array_keys($forumsById) as $forumId) {
                    $resolveForumCategory((int) $forumId);
                }

                foreach ($requested as $forumId => &$move) {
                    $move['category_id'] =
                        (int) ($resolvedCategories[$forumId] ?? $move['category_id']);
                }
                unset($move);

                $updateForumStatement = $pdo->prepare(
                    'UPDATE forums
                     SET category_id = :category_id,
                         parent_forum_id = :parent_forum_id,
                         sort_order = :sort_order
                     WHERE id = :forum_id'
                );

                foreach ($requested as $move) {
                    $updateForumStatement->execute([
                        'category_id' => (int) $move['category_id'],
                        'parent_forum_id' => (int) $move['parent_forum_id'] > 0
                            ? (int) $move['parent_forum_id']
                            : null,
                        'sort_order' => (int) $move['sort_order'],
                        'forum_id' => (int) $move['id'],
                    ]);
                }

                $pdo->commit();
                set_flash('success', 'The forum structure and order were updated.');
                redirect(url('admin/forums.php'));
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log('Blackthorne forum reorder error: ' . $exception->getMessage());
                $errors[] = $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'The forum structure could not be updated.';
            }
        }

    } elseif ($formAction === 'create_category') {
        $categoryName = post_value('category_name');
        $categorySlug = admin_forum_slugify($categoryName);
        $categoryDisplayName = post_value('category_display_name');
        $categoryDescription = post_value('category_description');
        $showInSidebar = post_value('show_in_sidebar') === '1' ? 1 : 0;
        $categoryAccessMode = post_value('category_access_mode', 'everyone');
        $categoryRoleIds = admin_forum_post_ids('category_role_ids');
        $categoryRoleIds = array_values(
            array_filter(
                $categoryRoleIds,
                static fn (int $id): bool => isset($validRoleIds[$id])
            )
        );

        if ($categorySlug === '') {
            $errors[] = 'Enter a valid category name for the URL.';
        }

        if ($categoryDisplayName === '') {
            $errors[] = 'Enter the category display name.';
        } elseif (admin_forum_text_length($categoryDisplayName) > 150) {
            $errors[] = 'The category display name cannot exceed 150 characters.';
        }

        if (admin_forum_text_length($categoryDescription) > 5000) {
            $errors[] = 'The category description is too long.';
        }

        if (!in_array($categoryAccessMode, ['everyone', 'restricted'], true)) {
            $categoryAccessMode = 'everyone';
        }

        if ($categoryAccessMode === 'restricted' && $categoryRoleIds === []) {
            $errors[] = 'Choose at least one role for a restricted category.';
        }

        $duplicateStatement = $pdo->prepare(
            'SELECT id
             FROM forum_categories
             WHERE slug = :slug
             LIMIT 1'
        );
        $duplicateStatement->execute(['slug' => $categorySlug]);

        if ($duplicateStatement->fetchColumn() !== false) {
            $errors[] = 'That category URL name is already in use.';
        }

        if ($errors === []) {
            try {
                $pdo->beginTransaction();

                $sortStatement = $pdo->query(
                    'SELECT COALESCE(MAX(sort_order), 0) + 10
                     FROM forum_categories'
                );
                $sortOrder = (int) $sortStatement->fetchColumn();

                $insertStatement = $pdo->prepare(
                    'INSERT INTO forum_categories (
                        title,
                        slug,
                        description,
                        sort_order,
                        is_visible,
                        access_mode,
                        show_in_sidebar
                     ) VALUES (
                        :title,
                        :slug,
                        :description,
                        :sort_order,
                        1,
                        :access_mode,
                        :show_in_sidebar
                     )'
                );

                $insertStatement->execute([
                    'title' => $categoryDisplayName,
                    'slug' => $categorySlug,
                    'description' => $categoryDescription !== ''
                        ? $categoryDescription
                        : null,
                    'sort_order' => $sortOrder,
                    'access_mode' => $categoryAccessMode === 'restricted'
                        ? 'restricted'
                        : 'public',
                    'show_in_sidebar' => $showInSidebar,
                ]);

                $categoryId = (int) $pdo->lastInsertId();

                if ($categoryAccessMode === 'restricted') {
                    $accessStatement = $pdo->prepare(
                        'INSERT INTO forum_category_role_access (
                            category_id,
                            role_id,
                            can_view_category
                         ) VALUES (
                            :category_id,
                            :role_id,
                            1
                         )'
                    );

                    foreach ($categoryRoleIds as $roleId) {
                        $accessStatement->execute([
                            'category_id' => $categoryId,
                            'role_id' => $roleId,
                        ]);
                    }
                }

                $pdo->commit();

                set_flash('success', 'The forum category was created successfully.');
                redirect(url('admin/forums.php'));

            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'Blackthorne forum category creation error: '
                    . $exception->getMessage()
                );

                $errors[] = 'The forum category could not be created. Please try again.';
            }
        }

    } elseif ($formAction === 'update_category') {
        $categoryId = (int) post_value('category_id');
        $categoryName = post_value('category_name');
        $categorySlug = admin_forum_slugify($categoryName);
        $categoryDisplayName = post_value('category_display_name');
        $categoryDescription = post_value('category_description');
        $showInSidebar = post_value('show_in_sidebar') === '1' ? 1 : 0;
        $categoryAccessMode = post_value('category_access_mode', 'everyone');
        $categoryRoleIds = admin_forum_post_ids('category_role_ids');
        $categoryRoleIds = array_values(
            array_filter(
                $categoryRoleIds,
                static fn (int $id): bool => isset($validRoleIds[$id])
            )
        );

        $categoryExists = false;

        if ($categoryId > 0) {
            $categoryExistsStatement = $pdo->prepare(
                'SELECT id
                 FROM forum_categories
                 WHERE id = :category_id
                 LIMIT 1'
            );
            $categoryExistsStatement->execute([
                'category_id' => $categoryId,
            ]);

            $categoryExists =
                $categoryExistsStatement->fetchColumn() !== false;
        }

        if (!$categoryExists) {
            $errors[] = 'The forum category you tried to edit could not be found.';
        }

        if ($categorySlug === '') {
            $errors[] = 'Enter a valid category name for the URL.';
        }

        if ($categoryDisplayName === '') {
            $errors[] = 'Enter the category display name.';
        } elseif (admin_forum_text_length($categoryDisplayName) > 150) {
            $errors[] = 'The category display name cannot exceed 150 characters.';
        }

        if (admin_forum_text_length($categoryDescription) > 5000) {
            $errors[] = 'The category description is too long.';
        }

        if (!in_array($categoryAccessMode, ['everyone', 'restricted'], true)) {
            $categoryAccessMode = 'everyone';
        }

        if ($categoryAccessMode === 'restricted' && $categoryRoleIds === []) {
            $errors[] = 'Choose at least one role for a restricted category.';
        }

        if ($categoryId > 0 && $categorySlug !== '') {
            $duplicateStatement = $pdo->prepare(
                'SELECT id
                 FROM forum_categories
                 WHERE slug = :slug
                   AND id <> :category_id
                 LIMIT 1'
            );
            $duplicateStatement->execute([
                'slug' => $categorySlug,
                'category_id' => $categoryId,
            ]);

            if ($duplicateStatement->fetchColumn() !== false) {
                $errors[] = 'That category URL name is already in use.';
            }
        }

        if ($errors === []) {
            try {
                $pdo->beginTransaction();

                $updateStatement = $pdo->prepare(
                    'UPDATE forum_categories
                     SET
                        title = :title,
                        slug = :slug,
                        description = :description,
                        access_mode = :access_mode,
                        show_in_sidebar = :show_in_sidebar
                     WHERE id = :category_id'
                );

                $updateStatement->execute([
                    'title' => $categoryDisplayName,
                    'slug' => $categorySlug,
                    'description' => $categoryDescription !== ''
                        ? $categoryDescription
                        : null,
                    'access_mode' => $categoryAccessMode === 'restricted'
                        ? 'restricted'
                        : 'public',
                    'show_in_sidebar' => $showInSidebar,
                    'category_id' => $categoryId,
                ]);

                $deleteRoleAccessStatement = $pdo->prepare(
                    'DELETE FROM forum_category_role_access
                     WHERE category_id = :category_id'
                );
                $deleteRoleAccessStatement->execute([
                    'category_id' => $categoryId,
                ]);

                if ($categoryAccessMode === 'restricted') {
                    $accessStatement = $pdo->prepare(
                        'INSERT INTO forum_category_role_access (
                            category_id,
                            role_id,
                            can_view_category
                         ) VALUES (
                            :category_id,
                            :role_id,
                            1
                         )'
                    );

                    foreach ($categoryRoleIds as $roleId) {
                        $accessStatement->execute([
                            'category_id' => $categoryId,
                            'role_id' => $roleId,
                        ]);
                    }
                }

                $pdo->commit();

                set_flash('success', 'The forum category was updated successfully.');
                redirect(
                    url(
                        'admin/forums.php?edit_category='
                        . $categoryId
                    )
                );

            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'Blackthorne forum category update error: '
                    . $exception->getMessage()
                );

                $errors[] =
                    'The forum category could not be updated. Please try again.';
            }
        }

    } elseif ($formAction === 'delete_category') {
        $categoryId = (int) post_value('category_id');
        $confirmDelete = post_value('confirm_delete') === '1';

        if ($categoryId <= 0) {
            $errors[] = 'Choose a valid forum category to delete.';
        }

        if (!$confirmDelete) {
            $errors[] = 'Confirm that you want to permanently delete this forum category and everything inside it.';
        }

        $categoryTitle = '';
        $categoryForums = [];

        if ($categoryId > 0) {
            $categoryStatement = $pdo->prepare(
                'SELECT title
                 FROM forum_categories
                 WHERE id = :category_id
                 LIMIT 1'
            );
            $categoryStatement->execute(['category_id' => $categoryId]);
            $categoryTitleResult = $categoryStatement->fetchColumn();

            if ($categoryTitleResult === false) {
                $errors[] = 'The forum category you tried to delete could not be found.';
            } else {
                $categoryTitle = (string) $categoryTitleResult;

                $categoryForumsStatement = $pdo->prepare(
                    'SELECT id, parent_forum_id
                     FROM forums
                     WHERE category_id = :category_id'
                );
                $categoryForumsStatement->execute(['category_id' => $categoryId]);
                $categoryForums = $categoryForumsStatement->fetchAll(PDO::FETCH_ASSOC);

            }
        }

        if ($errors === []) {
            try {
                $pdo->beginTransaction();

                /*
                 * The forums table uses ON DELETE RESTRICT for parent_forum_id,
                 * so every forum in the category must be deleted deepest-first.
                 * forum_moderators also uses ON DELETE RESTRICT and must be
                 * cleared before its forum can be removed. Threads, posts,
                 * polls, labels, access rows, etc. cascade from the forum.
                 */
                if ($categoryForums !== []) {
                    $forumRowsById = [];
                    $forumIds = [];

                    foreach ($categoryForums as $categoryForumRow) {
                        $currentForumId = (int) ($categoryForumRow['id'] ?? 0);

                        if ($currentForumId <= 0) {
                            continue;
                        }

                        $forumRowsById[$currentForumId] = $categoryForumRow;
                        $forumIds[] = $currentForumId;
                    }

                    $depthCache = [];
                    $depthResolver = function (int $forumId, array $trail = []) use (&$depthResolver, &$depthCache, $forumRowsById): int {
                        if (isset($depthCache[$forumId])) {
                            return $depthCache[$forumId];
                        }

                        if (isset($trail[$forumId])) {
                            return 0;
                        }

                        $trail[$forumId] = true;
                        $parentForumId = (int) ($forumRowsById[$forumId]['parent_forum_id'] ?? 0);

                        if ($parentForumId > 0 && isset($forumRowsById[$parentForumId])) {
                            $depthCache[$forumId] = 1 + $depthResolver($parentForumId, $trail);
                        } else {
                            $depthCache[$forumId] = 0;
                        }

                        return $depthCache[$forumId];
                    };

                    usort(
                        $forumIds,
                        static function (int $leftForumId, int $rightForumId) use ($depthResolver): int {
                            return $depthResolver($rightForumId) <=> $depthResolver($leftForumId);
                        }
                    );

                    $deleteModeratorStatement = $pdo->prepare(
                        'DELETE FROM forum_moderators
                         WHERE forum_id = :forum_id'
                    );

                    $deleteForumStatement = $pdo->prepare(
                        'DELETE FROM forums
                         WHERE id = :forum_id
                           AND category_id = :category_id'
                    );

                    foreach ($forumIds as $forumIdToDelete) {
                        $deleteModeratorStatement->execute([
                            'forum_id' => $forumIdToDelete,
                        ]);

                        $deleteForumStatement->execute([
                            'forum_id' => $forumIdToDelete,
                            'category_id' => $categoryId,
                        ]);
                    }
                }

                $deleteCategoryStatement = $pdo->prepare(
                    'DELETE FROM forum_categories
                     WHERE id = :category_id'
                );
                $deleteCategoryStatement->execute(['category_id' => $categoryId]);

                $pdo->commit();


                set_flash(
                    'success',
                    'The forum category "' . $categoryTitle . '" and everything inside it were deleted successfully.'
                );
                redirect(url('admin/forums.php'));

            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'Blackthorne forum category cascade deletion error: '
                    . $exception->getMessage()
                );

                $errors[] =
                    'The forum category and its contents could not be deleted. Please try again.';
            }
        }

    } elseif ($formAction === 'create_forum') {
        $categoryId = (int) post_value('forum_category_id');
        $parentForumId = (int) post_value('parent_forum_id');
        $forumName = post_value('forum_name');
        $forumSlug = admin_forum_slugify($forumName);
        $forumDisplayName = post_value('forum_display_name');
        $forumDescription = post_value('forum_description');
        $isPrivate = post_value('is_private') === '1';
        $allowPolls = post_value('allow_polls') === '1';
        $isAnnouncementForum =
            post_value('is_announcement_forum') === '1';
        $isHouseNewsForum =
            post_value('is_house_news_forum') === '1';
        $imageMapEnabled = post_value('image_map_enabled') === '1';
        $imageMapId = (int) post_value('image_map_id');
        $imageMapName = post_value('image_map_name');
        $imageMapAlt = post_value('image_map_alt');
        $imageMapAreas = [];
        $uploadedMapImage = null;

        try {
            $imageMapAreas = forum_image_map_parse_areas(post_value('image_map_areas_json'));
        } catch (RuntimeException $exception) {
            $errors[] = $exception->getMessage();
        }

        if (
            $imageMapEnabled
            && $imageMapId <= 0
            && (
                !isset($_FILES['image_map_image'])
                || (int) ($_FILES['image_map_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
            )
        ) {
            $errors[] = 'Choose an image when creating a new saved image map.';
        }

        $restrictedAccessRule =
            post_value(
                'restricted_access_rule',
                'roles'
            );

        if (
            !in_array(
                $restrictedAccessRule,
                [
                    'roles',
                    'houses',
                    'roles_or_houses',
                    'roles_and_houses',
                ],
                true
            )
        ) {
            $restrictedAccessRule = 'roles';
        }

        $moderatorIds = admin_forum_post_ids('moderator_user_ids');
        $moderatorIds = array_values(
            array_filter(
                $moderatorIds,
                static fn (int $id): bool => isset($validMemberIds[$id])
            )
        );

        $categoryStatement = $pdo->prepare(
            'SELECT id
             FROM forum_categories
             WHERE id = :category_id
             LIMIT 1'
        );
        $categoryStatement->execute(['category_id' => $categoryId]);

        if ($categoryStatement->fetchColumn() === false) {
            $errors[] = 'Choose a valid forum category.';
        }

        if ($parentForumId > 0) {
            $parentStatement = $pdo->prepare(
                'SELECT id
                 FROM forums
                 WHERE id = :forum_id
                   AND category_id = :category_id
                 LIMIT 1'
            );
            $parentStatement->execute([
                'forum_id' => $parentForumId,
                'category_id' => $categoryId,
            ]);

            if ($parentStatement->fetchColumn() === false) {
                $errors[] = 'The selected parent forum does not exist in that category.';
            }
        } else {
            $parentForumId = 0;
        }

        if ($forumSlug === '') {
            $errors[] = 'Enter a valid forum name for the URL.';
        }

        if ($forumDisplayName === '') {
            $errors[] = 'Enter the forum display name.';
        } elseif (admin_forum_text_length($forumDisplayName) > 150) {
            $errors[] = 'The forum display name cannot exceed 150 characters.';
        }

        if ($forumDescription === '') {
            $errors[] = 'Enter a description for the forum.';
        } elseif (admin_forum_text_length($forumDescription) > 10000) {
            $errors[] = 'The forum description is too long.';
        }

        $duplicateStatement = $pdo->prepare(
            'SELECT id
             FROM forums
             WHERE category_id = :category_id
               AND slug = :slug
             LIMIT 1'
        );
        $duplicateStatement->execute([
            'category_id' => $categoryId,
            'slug' => $forumSlug,
        ]);

        if ($duplicateStatement->fetchColumn() !== false) {
            $errors[] = 'That forum URL name is already used in this category.';
        }

        $permissionKeys = [
            'view_role_ids',
            'access_role_ids',
            'thread_role_ids',
            'reply_role_ids',
            'poll_role_ids',
            'view_all_thread_role_ids',
        ];

        $permissionSelections = [];

        foreach ($permissionKeys as $permissionKey) {
            $permissionSelections[$permissionKey] =
                admin_forum_permission_selection($permissionKey, $validRoleIds);
        }

        $housePermissionKeys = [
            'view_house_ids',
            'access_house_ids',
            'thread_house_ids',
            'reply_house_ids',
            'poll_house_ids',
            'view_all_thread_house_ids',
        ];

        $housePermissionSelections = [];

        foreach ($housePermissionKeys as $permissionKey) {
            $houseIds = admin_forum_post_ids($permissionKey);

            $housePermissionSelections[$permissionKey] =
                array_values(
                    array_filter(
                        $houseIds,
                        static fn (int $id): bool => isset($validHouseIds[$id])
                    )
                );
        }

        if (!$allowPolls) {
            $permissionSelections['poll_role_ids'] = [
                'everyone' => false,
                'role_ids' => [],
            ];

            $housePermissionSelections['poll_house_ids'] = [];
        }

        $labelNames = $_POST['label_name'] ?? [];
        $labelColors = $_POST['label_color'] ?? [];
        $labels = [];
        $seenLabelNames = [];

        if (is_array($labelNames) && is_array($labelColors)) {
            foreach ($labelNames as $index => $labelNameValue) {
                if (!is_scalar($labelNameValue)) {
                    continue;
                }

                $labelName = trim((string) $labelNameValue);

                if ($labelName === '') {
                    continue;
                }

                $labelColorValue = $labelColors[$index] ?? '';
                $labelColor = is_scalar($labelColorValue)
                    ? safe_css_color((string) $labelColorValue)
                    : null;

                if (admin_forum_text_length($labelName) > 100) {
                    $errors[] = 'Forum label names cannot exceed 100 characters.';
                    continue;
                }

                if ($labelColor === null) {
                    $errors[] = 'Choose a valid color for every forum label.';
                    continue;
                }

                $normalizedLabelName = admin_forum_lower($labelName);

                if (isset($seenLabelNames[$normalizedLabelName])) {
                    $errors[] = 'Each forum label name must be unique.';
                    continue;
                }

                $seenLabelNames[$normalizedLabelName] = true;
                $labels[] = [
                    'name' => $labelName,
                    'color' => $labelColor,
                ];
            }
        }

        if ($errors === []) {
            try {
                $pdo->beginTransaction();

                $sortStatement = $pdo->prepare(
                    'SELECT COALESCE(MAX(sort_order), 0) + 10
                     FROM forums
                     WHERE category_id = :category_id
                       AND (
                            parent_forum_id = :parent_forum_id
                            OR (
                                parent_forum_id IS NULL
                                AND :parent_is_null = 1
                            )
                       )'
                );
                $sortStatement->execute([
                    'category_id' => $categoryId,
                    'parent_forum_id' => $parentForumId,
                    'parent_is_null' => $parentForumId === 0 ? 1 : 0,
                ]);
                $sortOrder = (int) $sortStatement->fetchColumn();

                $hasRestrictedDefaults =
                    !$permissionSelections['view_role_ids']['everyone']
                    || !$permissionSelections['access_role_ids']['everyone'];

                $accessMode = $isPrivate
                    ? 'private_threads'
                    : ($hasRestrictedDefaults ? 'restricted' : 'public');

                $insertStatement = $pdo->prepare(
                    'INSERT INTO forums (
                        category_id,
                        parent_forum_id,
                        offering_id,
                        title,
                        slug,
                        description,
                        forum_type,
                        access_mode,
                        restricted_access_rule,
                        default_can_view_forum,
                        default_can_access_forum,
                        default_can_create_threads,
                        default_can_reply,
                        default_can_create_polls,
                        sort_order,
                        is_locked,
                        is_visible,
                        allow_attachments,
                        allow_images,
                        allow_polls,
                        is_announcement_forum,
                        is_house_news_forum
                     ) VALUES (
                        :category_id,
                        :parent_forum_id,
                        NULL,
                        :title,
                        :slug,
                        :description,
                        "general",
                        :access_mode,
                        :restricted_access_rule,
                        :default_can_view_forum,
                        :default_can_access_forum,
                        :default_can_create_threads,
                        :default_can_reply,
                        :default_can_create_polls,
                        :sort_order,
                        0,
                        1,
                        1,
                        1,
                        :allow_polls,
                        :is_announcement_forum,
                        :is_house_news_forum
                     )'
                );

                $insertStatement->execute([
                    'category_id' => $categoryId,
                    'parent_forum_id' => $parentForumId > 0
                        ? $parentForumId
                        : null,
                    'title' => $forumDisplayName,
                    'slug' => $forumSlug,
                    'description' => $forumDescription,
                    'access_mode' => $accessMode,
                    'restricted_access_rule' => $restrictedAccessRule,
                    'default_can_view_forum' => $permissionSelections['view_role_ids']['everyone'] ? 1 : 0,
                    'default_can_access_forum' => $permissionSelections['access_role_ids']['everyone'] ? 1 : 0,
                    'default_can_create_threads' => $permissionSelections['thread_role_ids']['everyone'] ? 1 : 0,
                    'default_can_reply' => $permissionSelections['reply_role_ids']['everyone'] ? 1 : 0,
                    'default_can_create_polls' => $permissionSelections['poll_role_ids']['everyone'] ? 1 : 0,
                    'sort_order' => $sortOrder,
                    'allow_polls' => $allowPolls ? 1 : 0,
                    'is_announcement_forum' =>
                        $isAnnouncementForum ? 1 : 0,
                    'is_house_news_forum' =>
                        $isHouseNewsForum ? 1 : 0,
                ]);

                $forumId = (int) $pdo->lastInsertId();

                if (
                    $imageMapEnabled
                    && isset($_FILES['image_map_image'])
                    && (int) ($_FILES['image_map_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
                ) {
                    $uploadedMapImage = forum_image_map_upload(
                        $forumId,
                        $_FILES['image_map_image']
                    );
                }

                forum_image_map_save_shared(
                    $pdo,
                    $forumId,
                    $imageMapEnabled,
                    $imageMapId,
                    $imageMapName,
                    $uploadedMapImage,
                    $imageMapAlt,
                    $imageMapAreas
                );

                $rolePermissionMap = [];
                $permissionColumns = [
                    'view_role_ids' => 'can_view_forum',
                    'access_role_ids' => 'can_access_forum',
                    'thread_role_ids' => 'can_create_threads',
                    'reply_role_ids' => 'can_reply',
                    'poll_role_ids' => 'can_create_polls',
                    'view_all_thread_role_ids' => 'can_view_all_threads',
                ];

                foreach ($permissionColumns as $permissionKey => $column) {
                    foreach ($permissionSelections[$permissionKey]['role_ids'] as $roleId) {
                        if (!isset($rolePermissionMap[$roleId])) {
                            $rolePermissionMap[$roleId] = [
                                'can_view_forum' => 0,
                                'can_access_forum' => 0,
                                'can_view_all_threads' => 0,
                                'can_create_threads' => 0,
                                'can_reply' => 0,
                                'can_create_polls' => 0,
                            ];
                        }

                        $rolePermissionMap[$roleId][$column] = 1;
                    }
                }

                $roleAccessStatement = $pdo->prepare(
                    'INSERT INTO forum_role_access (
                        forum_id,
                        role_id,
                        can_view_forum,
                        can_access_forum,
                        can_view_all_threads,
                        can_create_threads,
                        can_reply,
                        can_create_polls
                     ) VALUES (
                        :forum_id,
                        :role_id,
                        :can_view_forum,
                        :can_access_forum,
                        :can_view_all_threads,
                        :can_create_threads,
                        :can_reply,
                        :can_create_polls
                     )'
                );

                foreach ($rolePermissionMap as $roleId => $permissions) {
                    $roleAccessStatement->execute([
                        'forum_id' => $forumId,
                        'role_id' => $roleId,
                        'can_view_forum' => $permissions['can_view_forum'],
                        'can_access_forum' => $permissions['can_access_forum'],
                        'can_view_all_threads' => $permissions['can_view_all_threads'],
                        'can_create_threads' => $permissions['can_create_threads'],
                        'can_reply' => $permissions['can_reply'],
                        'can_create_polls' => $permissions['can_create_polls'],
                    ]);
                }

                $housePermissionMap = [];
                $housePermissionColumns = [
                    'view_house_ids' => 'can_view_forum',
                    'access_house_ids' => 'can_access_forum',
                    'thread_house_ids' => 'can_create_threads',
                    'reply_house_ids' => 'can_reply',
                    'poll_house_ids' => 'can_create_polls',
                    'view_all_thread_house_ids' => 'can_view_all_threads',
                ];

                foreach ($housePermissionColumns as $permissionKey => $column) {
                    foreach ($housePermissionSelections[$permissionKey] as $houseId) {
                        if (!isset($housePermissionMap[$houseId])) {
                            $housePermissionMap[$houseId] = [
                                'can_view_forum' => 0,
                                'can_access_forum' => 0,
                                'can_view_all_threads' => 0,
                                'can_create_threads' => 0,
                                'can_reply' => 0,
                                'can_create_polls' => 0,
                            ];
                        }

                        $housePermissionMap[$houseId][$column] = 1;
                    }
                }

                if ($housePermissionMap !== []) {
                    $houseAccessStatement = $pdo->prepare(
                        'INSERT INTO forum_house_access (
                            forum_id,
                            house_id,
                            can_view_forum,
                            can_access_forum,
                            can_view_all_threads,
                            can_create_threads,
                            can_reply,
                            can_create_polls
                         ) VALUES (
                            :forum_id,
                            :house_id,
                            :can_view_forum,
                            :can_access_forum,
                            :can_view_all_threads,
                            :can_create_threads,
                            :can_reply,
                            :can_create_polls
                         )'
                    );

                    foreach ($housePermissionMap as $houseId => $permissions) {
                        $houseAccessStatement->execute([
                            'forum_id' => $forumId,
                            'house_id' => $houseId,
                            'can_view_forum' => $permissions['can_view_forum'],
                            'can_access_forum' => $permissions['can_access_forum'],
                            'can_view_all_threads' => $permissions['can_view_all_threads'],
                            'can_create_threads' => $permissions['can_create_threads'],
                            'can_reply' => $permissions['can_reply'],
                            'can_create_polls' => $permissions['can_create_polls'],
                        ]);
                    }
                }

                if ($labels !== []) {
                    $labelStatement = $pdo->prepare(
                        'INSERT INTO forum_labels (
                            forum_id,
                            name,
                            label_color,
                            is_active,
                            sort_order,
                            created_by
                         ) VALUES (
                            :forum_id,
                            :name,
                            :label_color,
                            1,
                            :sort_order,
                            :created_by
                         )'
                    );

                    foreach ($labels as $index => $label) {
                        $labelStatement->execute([
                            'forum_id' => $forumId,
                            'name' => $label['name'],
                            'label_color' => $label['color'],
                            'sort_order' => ($index + 1) * 10,
                            'created_by' => current_user_id(),
                        ]);
                    }
                }

                if ($moderatorIds !== []) {
                    $moderatorStatement = $pdo->prepare(
                        'INSERT INTO forum_moderators (
                            forum_id,
                            user_id,
                            assigned_by,
                            can_apply_labels,
                            can_manage_labels,
                            is_active
                         ) VALUES (
                            :forum_id,
                            :user_id,
                            :assigned_by,
                            1,
                            0,
                            1
                         )'
                    );

                    foreach ($moderatorIds as $moderatorId) {
                        $moderatorStatement->execute([
                            'forum_id' => $forumId,
                            'user_id' => $moderatorId,
                            'assigned_by' => current_user_id(),
                        ]);
                    }
                }

                $pdo->commit();

                set_flash('success', 'The forum was created successfully.');
                redirect(url('admin/forums.php'));

            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if (is_array($uploadedMapImage) && !empty($uploadedMapImage['path'])) {
                    forum_image_map_delete_file((string) $uploadedMapImage['path']);
                }

                error_log(
                    'Blackthorne forum creation error: '
                    . $exception->getMessage()
                );

                $errors[] = 'The forum could not be created. Please try again.';
            }
        }

    } elseif ($formAction === 'update_forum') {
        $forumId = (int) post_value('forum_id');
        $categoryId = (int) post_value('forum_category_id');
        $parentForumId = (int) post_value('parent_forum_id');
        $forumName = post_value('forum_name');
        $forumSlug = admin_forum_slugify($forumName);
        $forumDisplayName = post_value('forum_display_name');
        $forumDescription = post_value('forum_description');
        $isPrivate = post_value('is_private') === '1';
        $allowPolls = post_value('allow_polls') === '1';
        $isAnnouncementForum = post_value('is_announcement_forum') === '1';
        $isHouseNewsForum = post_value('is_house_news_forum') === '1';
        $imageMapEnabled = post_value('image_map_enabled') === '1';
        $imageMapId = (int) post_value('image_map_id');
        $imageMapName = post_value('image_map_name');
        $imageMapAlt = post_value('image_map_alt');
        $imageMapAreas = [];
        $uploadedMapImage = null;
        $mapFileToDeleteAfterCommit = null;

        try {
            $imageMapAreas = forum_image_map_parse_areas(post_value('image_map_areas_json'));
        } catch (RuntimeException $exception) {
            $errors[] = $exception->getMessage();
        }

        $restrictedAccessRule =
            post_value(
                'restricted_access_rule',
                'roles'
            );

        if (
            !in_array(
                $restrictedAccessRule,
                [
                    'roles',
                    'houses',
                    'roles_or_houses',
                    'roles_and_houses',
                ],
                true
            )
        ) {
            $restrictedAccessRule = 'roles';
        }

        $moderatorIds = admin_forum_post_ids('moderator_user_ids');
        $moderatorIds = array_values(
            array_filter(
                $moderatorIds,
                static fn (int $id): bool => isset($validMemberIds[$id])
            )
        );

        $existingForumStatement = $pdo->prepare(
            'SELECT id, category_id, parent_forum_id
             FROM forums
             WHERE id = :forum_id
             LIMIT 1'
        );
        $existingForumStatement->execute(['forum_id' => $forumId]);
        $existingForum = $existingForumStatement->fetch(PDO::FETCH_ASSOC);

        if (!$existingForum) {
            $errors[] = 'The forum you tried to edit could not be found.';
        }

        $existingImageMap = null;

        if ($existingForum) {
            try {
                $existingImageMap = forum_image_map_load($pdo, $forumId);
            } catch (Throwable $exception) {
                error_log('Blackthorne forum image map load error: ' . $exception->getMessage());
            }
        }

        if (
            $imageMapEnabled
            && $imageMapId <= 0
            && $existingImageMap === null
            && (
                !isset($_FILES['image_map_image'])
                || (int) ($_FILES['image_map_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
            )
        ) {
            $errors[] = 'Choose an image before creating a new saved image map.';
        }

        $categoryStatement = $pdo->prepare(
            'SELECT id
             FROM forum_categories
             WHERE id = :category_id
             LIMIT 1'
        );
        $categoryStatement->execute(['category_id' => $categoryId]);

        if ($categoryStatement->fetchColumn() === false) {
            $errors[] = 'Choose a valid forum category.';
        }

        $descendantIds =
            $forumId > 0
                ? admin_forum_descendant_ids(
                    $pdo,
                    $forumId
                )
                : [];

        if ($parentForumId > 0) {
            if (
                $parentForumId === $forumId
                || in_array(
                    $parentForumId,
                    $descendantIds,
                    true
                )
            ) {
                $errors[] =
                    'A forum cannot be placed inside itself or one of its descendants.';
            } else {
                $parentStatement = $pdo->prepare(
                    'SELECT id
                     FROM forums
                     WHERE id = :forum_id
                       AND id <> :current_forum_id
                       AND category_id = :category_id
                     LIMIT 1'
                );
                $parentStatement->execute([
                    'forum_id' => $parentForumId,
                    'current_forum_id' => $forumId,
                    'category_id' => $categoryId,
                ]);

                if ($parentStatement->fetchColumn() === false) {
                    $errors[] =
                        'The selected parent forum does not exist in that category.';
                }
            }
        } else {
            $parentForumId = 0;
        }

        if ($forumSlug === '') {
            $errors[] = 'Enter a valid forum name for the URL.';
        }

        if ($forumDisplayName === '') {
            $errors[] = 'Enter the forum display name.';
        } elseif (admin_forum_text_length($forumDisplayName) > 150) {
            $errors[] = 'The forum display name cannot exceed 150 characters.';
        }

        if ($forumDescription === '') {
            $errors[] = 'Enter a description for the forum.';
        } elseif (admin_forum_text_length($forumDescription) > 10000) {
            $errors[] = 'The forum description is too long.';
        }

        $duplicateStatement = $pdo->prepare(
            'SELECT id
             FROM forums
             WHERE category_id = :category_id
               AND slug = :slug
               AND id <> :forum_id
             LIMIT 1'
        );
        $duplicateStatement->execute([
            'category_id' => $categoryId,
            'slug' => $forumSlug,
            'forum_id' => $forumId,
        ]);

        if ($duplicateStatement->fetchColumn() !== false) {
            $errors[] = 'That forum URL name is already used in this category.';
        }

        $permissionKeys = [
            'view_role_ids',
            'access_role_ids',
            'thread_role_ids',
            'reply_role_ids',
            'poll_role_ids',
            'view_all_thread_role_ids',
        ];

        $permissionSelections = [];

        foreach ($permissionKeys as $permissionKey) {
            if (
                $permissionKey === 'view_all_thread_role_ids'
                && !array_key_exists($permissionKey, $_POST)
            ) {
                $permissionSelections[$permissionKey] = [
                    'everyone' => false,
                    'role_ids' => [],
                ];
                continue;
            }

            $permissionSelections[$permissionKey] =
                admin_forum_permission_selection($permissionKey, $validRoleIds);
        }

        $housePermissionKeys = [
            'view_house_ids',
            'access_house_ids',
            'thread_house_ids',
            'reply_house_ids',
            'poll_house_ids',
            'view_all_thread_house_ids',
        ];

        $housePermissionSelections = [];

        foreach ($housePermissionKeys as $permissionKey) {
            $houseIds = admin_forum_post_ids($permissionKey);

            $housePermissionSelections[$permissionKey] =
                array_values(
                    array_filter(
                        $houseIds,
                        static fn (int $id): bool => isset($validHouseIds[$id])
                    )
                );
        }

        if (!$allowPolls) {
            $permissionSelections['poll_role_ids'] = [
                'everyone' => false,
                'role_ids' => [],
            ];

            $housePermissionSelections['poll_house_ids'] = [];
        }

        $labelIds = $_POST['label_id'] ?? [];
        $labelNames = $_POST['label_name'] ?? [];
        $labelColors = $_POST['label_color'] ?? [];
        $labels = [];
        $seenLabelNames = [];

        if (
            is_array($labelIds)
            && is_array($labelNames)
            && is_array($labelColors)
        ) {
            foreach ($labelNames as $index => $labelNameValue) {
                if (!is_scalar($labelNameValue)) {
                    continue;
                }

                $labelName = trim((string) $labelNameValue);

                if ($labelName === '') {
                    continue;
                }

                $labelIdValue = $labelIds[$index] ?? '0';
                $labelId = is_scalar($labelIdValue)
                    && ctype_digit((string) $labelIdValue)
                        ? (int) $labelIdValue
                        : 0;

                $labelColorValue = $labelColors[$index] ?? '';
                $labelColor = is_scalar($labelColorValue)
                    ? safe_css_color((string) $labelColorValue)
                    : null;

                if (admin_forum_text_length($labelName) > 100) {
                    $errors[] = 'Forum label names cannot exceed 100 characters.';
                    continue;
                }

                if ($labelColor === null) {
                    $errors[] = 'Choose a valid color for every forum label.';
                    continue;
                }

                $normalizedLabelName = admin_forum_lower($labelName);

                if (isset($seenLabelNames[$normalizedLabelName])) {
                    $errors[] = 'Each forum label name must be unique.';
                    continue;
                }

                if ($labelId > 0) {
                    $labelOwnerStatement = $pdo->prepare(
                        'SELECT id
                         FROM forum_labels
                         WHERE id = :label_id
                           AND forum_id = :forum_id
                         LIMIT 1'
                    );
                    $labelOwnerStatement->execute([
                        'label_id' => $labelId,
                        'forum_id' => $forumId,
                    ]);

                    if ($labelOwnerStatement->fetchColumn() === false) {
                        $errors[] = 'One of the submitted forum labels is invalid.';
                        continue;
                    }
                }

                $seenLabelNames[$normalizedLabelName] = true;
                $labels[] = [
                    'id' => $labelId,
                    'name' => $labelName,
                    'color' => $labelColor,
                ];
            }
        }

        if ($errors === [] && $existingForum) {
            try {
                $pdo->beginTransaction();

                $hasRestrictedDefaults =
                    !$permissionSelections['view_role_ids']['everyone']
                    || !$permissionSelections['access_role_ids']['everyone'];

                $accessMode = $isPrivate
                    ? 'private_threads'
                    : ($hasRestrictedDefaults ? 'restricted' : 'public');

                $updateStatement = $pdo->prepare(
                    'UPDATE forums
                     SET category_id = :category_id,
                         parent_forum_id = :parent_forum_id,
                         title = :title,
                         slug = :slug,
                         description = :description,
                         access_mode = :access_mode,
                         restricted_access_rule = :restricted_access_rule,
                         default_can_view_forum = :default_can_view_forum,
                         default_can_access_forum = :default_can_access_forum,
                         default_can_create_threads = :default_can_create_threads,
                         default_can_reply = :default_can_reply,
                         default_can_create_polls = :default_can_create_polls,
                         allow_polls = :allow_polls,
                         is_announcement_forum = :is_announcement_forum,
                         is_house_news_forum = :is_house_news_forum
                     WHERE id = :forum_id'
                );

                $updateStatement->execute([
                    'category_id' => $categoryId,
                    'parent_forum_id' => $parentForumId > 0
                        ? $parentForumId
                        : null,
                    'title' => $forumDisplayName,
                    'slug' => $forumSlug,
                    'description' => $forumDescription,
                    'access_mode' => $accessMode,
                    'restricted_access_rule' => $restrictedAccessRule,
                    'default_can_view_forum' => $permissionSelections['view_role_ids']['everyone'] ? 1 : 0,
                    'default_can_access_forum' => $permissionSelections['access_role_ids']['everyone'] ? 1 : 0,
                    'default_can_create_threads' => $permissionSelections['thread_role_ids']['everyone'] ? 1 : 0,
                    'default_can_reply' => $permissionSelections['reply_role_ids']['everyone'] ? 1 : 0,
                    'default_can_create_polls' => $permissionSelections['poll_role_ids']['everyone'] ? 1 : 0,
                    'allow_polls' => $allowPolls ? 1 : 0,
                    'is_announcement_forum' => $isAnnouncementForum ? 1 : 0,
                    'is_house_news_forum' => $isHouseNewsForum ? 1 : 0,
                    'forum_id' => $forumId,
                ]);

                /*
                 * Descendants always stay in the same category as their parent.
                 * If this forum moves categories, carry its entire subtree.
                 */
                $descendantIds =
                    admin_forum_descendant_ids(
                        $pdo,
                        $forumId
                    );

                if ($descendantIds !== []) {
                    $descendantPlaceholders =
                        implode(
                            ',',
                            array_fill(
                                0,
                                count($descendantIds),
                                '?'
                            )
                        );

                    $descendantCategoryStatement =
                        $pdo->prepare(
                            'UPDATE forums
                             SET category_id = ?
                             WHERE id IN ('
                             . $descendantPlaceholders
                             . ')'
                        );

                    $descendantCategoryStatement->execute(
                        array_merge(
                            [$categoryId],
                            $descendantIds
                        )
                    );
                }

                $pdo->prepare(
                    'DELETE FROM forum_role_access
                     WHERE forum_id = :forum_id'
                )->execute(['forum_id' => $forumId]);

                $pdo->prepare(
                    'DELETE FROM forum_house_access
                     WHERE forum_id = :forum_id'
                )->execute(['forum_id' => $forumId]);

                $rolePermissionMap = [];
                $permissionColumns = [
                    'view_role_ids' => 'can_view_forum',
                    'access_role_ids' => 'can_access_forum',
                    'thread_role_ids' => 'can_create_threads',
                    'reply_role_ids' => 'can_reply',
                    'poll_role_ids' => 'can_create_polls',
                    'view_all_thread_role_ids' => 'can_view_all_threads',
                ];

                foreach ($permissionColumns as $permissionKey => $column) {
                    foreach ($permissionSelections[$permissionKey]['role_ids'] as $roleId) {
                        if (!isset($rolePermissionMap[$roleId])) {
                            $rolePermissionMap[$roleId] = [
                                'can_view_forum' => 0,
                                'can_access_forum' => 0,
                                'can_view_all_threads' => 0,
                                'can_create_threads' => 0,
                                'can_reply' => 0,
                                'can_create_polls' => 0,
                            ];
                        }

                        $rolePermissionMap[$roleId][$column] = 1;
                    }
                }

                if ($rolePermissionMap !== []) {
                    $roleAccessStatement = $pdo->prepare(
                        'INSERT INTO forum_role_access (
                            forum_id,
                            role_id,
                            can_view_forum,
                            can_access_forum,
                            can_view_all_threads,
                            can_create_threads,
                            can_reply,
                            can_create_polls
                         ) VALUES (
                            :forum_id,
                            :role_id,
                            :can_view_forum,
                            :can_access_forum,
                            :can_view_all_threads,
                            :can_create_threads,
                            :can_reply,
                            :can_create_polls
                         )'
                    );

                    foreach ($rolePermissionMap as $roleId => $permissions) {
                        $roleAccessStatement->execute([
                            'forum_id' => $forumId,
                            'role_id' => $roleId,
                            'can_view_forum' => $permissions['can_view_forum'],
                            'can_access_forum' => $permissions['can_access_forum'],
                            'can_view_all_threads' => $permissions['can_view_all_threads'],
                            'can_create_threads' => $permissions['can_create_threads'],
                            'can_reply' => $permissions['can_reply'],
                            'can_create_polls' => $permissions['can_create_polls'],
                        ]);
                    }
                }

                $housePermissionMap = [];
                $housePermissionColumns = [
                    'view_house_ids' => 'can_view_forum',
                    'access_house_ids' => 'can_access_forum',
                    'thread_house_ids' => 'can_create_threads',
                    'reply_house_ids' => 'can_reply',
                    'poll_house_ids' => 'can_create_polls',
                    'view_all_thread_house_ids' => 'can_view_all_threads',
                ];

                foreach ($housePermissionColumns as $permissionKey => $column) {
                    foreach ($housePermissionSelections[$permissionKey] as $houseId) {
                        if (!isset($housePermissionMap[$houseId])) {
                            $housePermissionMap[$houseId] = [
                                'can_view_forum' => 0,
                                'can_access_forum' => 0,
                                'can_view_all_threads' => 0,
                                'can_create_threads' => 0,
                                'can_reply' => 0,
                                'can_create_polls' => 0,
                            ];
                        }

                        $housePermissionMap[$houseId][$column] = 1;
                    }
                }

                if ($housePermissionMap !== []) {
                    $houseAccessStatement = $pdo->prepare(
                        'INSERT INTO forum_house_access (
                            forum_id,
                            house_id,
                            can_view_forum,
                            can_access_forum,
                            can_view_all_threads,
                            can_create_threads,
                            can_reply,
                            can_create_polls
                         ) VALUES (
                            :forum_id,
                            :house_id,
                            :can_view_forum,
                            :can_access_forum,
                            :can_view_all_threads,
                            :can_create_threads,
                            :can_reply,
                            :can_create_polls
                         )'
                    );

                    foreach ($housePermissionMap as $houseId => $permissions) {
                        $houseAccessStatement->execute([
                            'forum_id' => $forumId,
                            'house_id' => $houseId,
                            'can_view_forum' => $permissions['can_view_forum'],
                            'can_access_forum' => $permissions['can_access_forum'],
                            'can_view_all_threads' => $permissions['can_view_all_threads'],
                            'can_create_threads' => $permissions['can_create_threads'],
                            'can_reply' => $permissions['can_reply'],
                            'can_create_polls' => $permissions['can_create_polls'],
                        ]);
                    }
                }

                $submittedExistingLabelIds = [];
                $updateLabelStatement = $pdo->prepare(
                    'UPDATE forum_labels
                     SET name = :name,
                         label_color = :label_color,
                         is_active = 1,
                         sort_order = :sort_order
                     WHERE id = :label_id
                       AND forum_id = :forum_id'
                );
                $insertLabelStatement = $pdo->prepare(
                    'INSERT INTO forum_labels (
                        forum_id,
                        name,
                        label_color,
                        is_active,
                        sort_order,
                        created_by
                     ) VALUES (
                        :forum_id,
                        :name,
                        :label_color,
                        1,
                        :sort_order,
                        :created_by
                     )'
                );

                foreach ($labels as $index => $label) {
                    $sortOrder = ($index + 1) * 10;

                    if ((int) $label['id'] > 0) {
                        $submittedExistingLabelIds[] = (int) $label['id'];
                        $updateLabelStatement->execute([
                            'name' => $label['name'],
                            'label_color' => $label['color'],
                            'sort_order' => $sortOrder,
                            'label_id' => (int) $label['id'],
                            'forum_id' => $forumId,
                        ]);
                    } else {
                        $insertLabelStatement->execute([
                            'forum_id' => $forumId,
                            'name' => $label['name'],
                            'label_color' => $label['color'],
                            'sort_order' => $sortOrder,
                            'created_by' => current_user_id(),
                        ]);
                    }
                }

                $deactivateLabelsSql =
                    'UPDATE forum_labels
                     SET is_active = 0
                     WHERE forum_id = :forum_id';
                $deactivateLabelParams = ['forum_id' => $forumId];

                if ($submittedExistingLabelIds !== []) {
                    $placeholders = [];

                    foreach ($submittedExistingLabelIds as $index => $labelId) {
                        $placeholder = ':label_id_' . $index;
                        $placeholders[] = $placeholder;
                        $deactivateLabelParams['label_id_' . $index] = $labelId;
                    }

                    $deactivateLabelsSql .=
                        ' AND id NOT IN (' . implode(', ', $placeholders) . ')';
                }

                $deactivateLabelsStatement = $pdo->prepare($deactivateLabelsSql);
                $deactivateLabelsStatement->execute($deactivateLabelParams);

                $activeModeratorStatement = $pdo->prepare(
                    'SELECT id, user_id
                     FROM forum_moderators
                     WHERE forum_id = :forum_id
                       AND is_active = 1
                       AND ended_at IS NULL'
                );
                $activeModeratorStatement->execute(['forum_id' => $forumId]);
                $activeModeratorRows = $activeModeratorStatement->fetchAll(PDO::FETCH_ASSOC);
                $activeModeratorUserIds = [];

                foreach ($activeModeratorRows as $moderatorRow) {
                    $activeModeratorUserIds[(int) $moderatorRow['user_id']] = true;

                    if (!in_array((int) $moderatorRow['user_id'], $moderatorIds, true)) {
                        $endModeratorStatement = $pdo->prepare(
                            'UPDATE forum_moderators
                             SET is_active = 0,
                                 ended_at = NOW(),
                                 ended_by = :ended_by,
                                 end_reason = :end_reason
                             WHERE id = :moderator_id'
                        );
                        $endModeratorStatement->execute([
                            'ended_by' => current_user_id(),
                            'end_reason' => 'Removed through forum management.',
                            'moderator_id' => (int) $moderatorRow['id'],
                        ]);
                    }
                }

                $addModeratorStatement = $pdo->prepare(
                    'INSERT INTO forum_moderators (
                        forum_id,
                        user_id,
                        assigned_by,
                        can_apply_labels,
                        can_manage_labels,
                        is_active
                     ) VALUES (
                        :forum_id,
                        :user_id,
                        :assigned_by,
                        1,
                        0,
                        1
                     )'
                );

                foreach ($moderatorIds as $moderatorId) {
                    if (isset($activeModeratorUserIds[$moderatorId])) {
                        continue;
                    }

                    $addModeratorStatement->execute([
                        'forum_id' => $forumId,
                        'user_id' => $moderatorId,
                        'assigned_by' => current_user_id(),
                    ]);
                }

                if (
                    $imageMapEnabled
                    && isset($_FILES['image_map_image'])
                    && (int) ($_FILES['image_map_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
                ) {
                    $uploadedMapImage = forum_image_map_upload(
                        $forumId,
                        $_FILES['image_map_image']
                    );
                }

                $mapFileToDeleteAfterCommit = forum_image_map_save_shared(
                    $pdo,
                    $forumId,
                    $imageMapEnabled,
                    $imageMapId,
                    $imageMapName,
                    $uploadedMapImage,
                    $imageMapAlt,
                    $imageMapAreas
                );

                $pdo->commit();

                if (is_string($mapFileToDeleteAfterCommit) && $mapFileToDeleteAfterCommit !== '') {
                    forum_image_map_delete_file($mapFileToDeleteAfterCommit);
                }

                set_flash('success', 'The forum was updated successfully.');
                redirect(url('admin/forums.php?edit_forum=' . $forumId));

            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if (is_array($uploadedMapImage) && !empty($uploadedMapImage['path'])) {
                    forum_image_map_delete_file((string) $uploadedMapImage['path']);
                }

                error_log(
                    'Blackthorne forum update error: '
                    . $exception->getMessage()
                );

                $errors[] = 'The forum could not be updated. Please try again.';
            }
        }

    } elseif ($formAction === 'delete_forum') {
        $forumId = (int) post_value('forum_id');
        $confirmDelete = post_value('confirm_delete') === '1';

        if ($forumId <= 0) {
            $errors[] = 'Choose a valid forum to delete.';
        }

        if (!$confirmDelete) {
            $errors[] = 'Confirm that you want to permanently delete this forum.';
        }

        $forumTitle = '';

        if ($forumId > 0) {
            $forumStatement = $pdo->prepare(
                'SELECT id, title
                 FROM forums
                 WHERE id = :forum_id
                 LIMIT 1'
            );
            $forumStatement->execute(['forum_id' => $forumId]);
            $forumToDelete = $forumStatement->fetch(PDO::FETCH_ASSOC);

            if ($forumToDelete === false) {
                $errors[] = 'The forum you tried to delete could not be found.';
            } else {
                $forumTitle = (string) $forumToDelete['title'];

                $childCountStatement = $pdo->prepare(
                    'SELECT COUNT(*)
                     FROM forums
                     WHERE parent_forum_id = :forum_id'
                );
                $childCountStatement->execute(['forum_id' => $forumId]);

                if ((int) $childCountStatement->fetchColumn() > 0) {
                    $errors[] =
                        'This forum still contains sub-forums. Move or delete those sub-forums before deleting the forum.';
                }
            }
        }

        if ($errors === []) {
            try {
                $pdo->beginTransaction();

                // forum_moderators intentionally uses ON DELETE RESTRICT in the
                // schema, so its rows must be removed before the forum itself.
                $deleteModeratorStatement = $pdo->prepare(
                    'DELETE FROM forum_moderators
                     WHERE forum_id = :forum_id'
                );
                $deleteModeratorStatement->execute(['forum_id' => $forumId]);

                $deleteForumStatement = $pdo->prepare(
                    'DELETE FROM forums
                     WHERE id = :forum_id'
                );
                $deleteForumStatement->execute(['forum_id' => $forumId]);

                $pdo->commit();


                set_flash(
                    'success',
                    'The forum "' . $forumTitle . '" was deleted successfully.'
                );
                redirect(url('admin/forums.php'));

            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'Blackthorne forum deletion error: '
                    . $exception->getMessage()
                );

                $errors[] = 'The forum could not be deleted. Please try again.';
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| Current Forum Structure
|--------------------------------------------------------------------------
*/

$categoriesStatement = $pdo->query(
    'SELECT
        fc.id,
        fc.title,
        fc.slug,
        fc.description,
        fc.access_mode,
        fc.show_in_sidebar,
        fc.is_visible
     FROM forum_categories fc
     ORDER BY fc.sort_order ASC, fc.title ASC'
);

$forumCategories = $categoriesStatement->fetchAll(PDO::FETCH_ASSOC);

$editCategoryId = 0;

if (
    isset($_GET['edit_category'])
    && ctype_digit((string) $_GET['edit_category'])
) {
    $editCategoryId = (int) $_GET['edit_category'];
}

if (in_array($formAction, ['update_category', 'delete_category'], true)) {
    $postedEditCategoryId = (int) post_value('category_id');

    if ($postedEditCategoryId > 0) {
        $editCategoryId = $postedEditCategoryId;
    }
}

$editCategory = null;
$editCategoryRoleIds = [];

if ($editCategoryId > 0) {
    foreach ($forumCategories as $category) {
        if ((int) $category['id'] === $editCategoryId) {
            $editCategory = $category;
            break;
        }
    }

    if ($editCategory !== null) {
        $editCategoryRoleStatement = $pdo->prepare(
            'SELECT role_id
             FROM forum_category_role_access
             WHERE category_id = :category_id
               AND can_view_category = 1
             ORDER BY role_id ASC'
        );
        $editCategoryRoleStatement->execute([
            'category_id' => $editCategoryId,
        ]);

        $editCategoryRoleIds = array_map(
            'intval',
            $editCategoryRoleStatement->fetchAll(PDO::FETCH_COLUMN)
        );
    }
}

$forumsStatement = $pdo->query(
    'SELECT
        f.id,
        f.category_id,
        f.parent_forum_id,
        f.title,
        f.slug,
        f.description,
        f.access_mode,
        f.is_visible,
        f.is_locked,
        f.is_announcement_forum,
        f.is_house_news_forum,
        EXISTS(
            SELECT 1
            FROM forum_image_map_assignments fima
            INNER JOIN forum_image_maps fim ON fim.id = fima.image_map_id
            WHERE fima.forum_id = f.id
              AND fim.is_active = 1
        ) AS has_image_map,
        (
            SELECT COUNT(*)
            FROM forum_labels fl
            WHERE fl.forum_id = f.id
              AND fl.is_active = 1
        ) AS label_count,
        (
            SELECT COUNT(*)
            FROM forum_moderators fm
            WHERE fm.forum_id = f.id
              AND fm.is_active = 1
              AND fm.ended_at IS NULL
        ) AS moderator_count
     FROM forums f
     ORDER BY f.category_id ASC, f.parent_forum_id ASC, f.sort_order ASC, f.title ASC'
);

$allForums = $forumsStatement->fetchAll(PDO::FETCH_ASSOC);

try {
    $savedImageMaps = forum_image_map_list($pdo);
} catch (Throwable $exception) {
    error_log('Blackthorne saved image map list error: ' . $exception->getMessage());
    $savedImageMaps = [];
}
$forumsByCategory = [];
$subforumsByParent = [];
$allForumsById = [];
$forumsByParent = [];

foreach ($allForums as $forum) {
    $forumId = (int) $forum['id'];
    $categoryId = (int) $forum['category_id'];
    $parentForumId =
        $forum['parent_forum_id'] !== null
            ? (int) $forum['parent_forum_id']
            : 0;

    $allForumsById[$forumId] = $forum;
    $forumsByParent[$parentForumId][] = $forum;

    if ($parentForumId === 0) {
        $forumsByCategory[$categoryId][] = $forum;
    } else {
        $subforumsByParent[$parentForumId][] = $forum;
    }
}

$forumParentOptions =
    admin_forum_flatten_hierarchy(
        $forumsByParent
    );


$renderForumStructureNode = null;

$renderForumStructureNode =
    static function (
        array $forum,
        int $categoryId,
        int $depth = 0
    ) use (
        &$renderForumStructureNode,
        $subforumsByParent,
        $houseBoardDisplayColors
    ): void {
        $forumId =
            (int) $forum['id'];

        $childForums =
            $subforumsByParent[
                $forumId
            ]
            ?? [];

        $forumHouseColor =
            $houseBoardDisplayColors[
                admin_forum_lower(
                    trim(
                        (string) $forum['title']
                    )
                )
            ]
            ?? null;

        $isSubforum =
            $depth > 0;
        ?>
<div class="forum-structure-main-group<?= $isSubforum ? ' is-subforum-group' : ''; ?>" data-forum-sort-item
    data-forum-id="<?= $forumId; ?>" data-forum-depth="<?= $depth; ?>" draggable="true">
    <div class="forum-structure-forum<?= $isSubforum ? ' is-subforum' : ''; ?>">
        <button type="button" class="forum-structure-drag-handle"
            aria-label="Drag to reorder <?= e((string) $forum['title']); ?>" title="Drag to reorder">⋮⋮</button>

        <div class="forum-structure-copy">
            <h4<?= $forumHouseColor !== null ? ' style="color:' . e($forumHouseColor) . ';"' : ''; ?>>
                <?= e((string) $forum['title']); ?>
                </h4>

                <p>
                    <?php if ($isSubforum): ?>
                    Sub-forum
                    <?php else: ?>
                    Main forum
                    <?php endif; ?>
                    · Forum ID: <?= $forumId; ?>
                    · /forum.php?f=<?= $forumId; ?>
                    · /<?= e((string) $forum['slug']); ?>
                </p>
        </div>

        <div class="forum-structure-meta">
            <span><?= e(str_replace('_', ' ', ucfirst((string) $forum['access_mode']))); ?></span>
            <?php if ((int) ($forum['is_announcement_forum'] ?? 0) === 1): ?><span>Announcement
                Forum</span><?php endif; ?>
            <?php if ((int) ($forum['is_house_news_forum'] ?? 0) === 1): ?><span>House News</span><?php endif; ?>
            <?php if ((int) ($forum['has_image_map'] ?? 0) === 1): ?><span>Image Map</span><?php endif; ?>
            <span><?= number_format((int) $forum['label_count']); ?> labels</span>
            <span><?= number_format((int) $forum['moderator_count']); ?> moderators</span>

            <a class="button button-secondary" href="<?= e(url('admin/forums.php?edit_forum=' . $forumId)); ?>">
                Edit
            </a>

            <form method="post" action="<?= e(url('admin/forums.php')); ?>" class="forum-structure-inline-form"
                data-confirm-delete="Delete this forum? This permanently deletes its forum content.">
                <?= csrf_field(); ?>
                <input type="hidden" name="form_action" value="delete_forum">
                <input type="hidden" name="forum_id" value="<?= $forumId; ?>">
                <input type="hidden" name="confirm_delete" value="1">
                <button type="submit" class="button forum-admin-delete-button">
                    Delete
                </button>
            </form>
        </div>
    </div>

    <div class="forum-structure-subforums<?= $childForums === [] ? ' is-empty' : ''; ?>" data-forum-sort-container
        data-parent-id="<?= $forumId; ?>" data-category-id="<?= $categoryId; ?>">
        <?php if ($childForums === []): ?>
        <p class="forum-structure-empty forum-structure-drop-hint" data-forum-drop-hint>
            Drag a forum here to nest it beneath <?= e((string) $forum['title']); ?>.
        </p>
        <?php endif; ?>

        <?php foreach ($childForums as $childForum): ?>
        <?php
                    $renderForumStructureNode(
                        $childForum,
                        $categoryId,
                        $depth + 1
                    );
                    ?>
        <?php endforeach; ?>
    </div>
</div>
<?php
    };

$editForumId = 0;

if (isset($_GET['edit_forum']) && ctype_digit((string) $_GET['edit_forum'])) {
    $editForumId = (int) $_GET['edit_forum'];
}

if (in_array($formAction, ['update_forum', 'delete_forum'], true)) {
    $postedEditForumId = (int) post_value('forum_id');

    if ($postedEditForumId > 0) {
        $editForumId = $postedEditForumId;
    }
}

$editForum = null;
$editForumLabels = [];
$editForumModeratorIds = [];
$editForumImageMap = null;
$editPermissionSelections = [
    'view_role_ids' => [],
    'access_role_ids' => [],
    'thread_role_ids' => [],
    'reply_role_ids' => [],
    'poll_role_ids' => [],
    'view_all_thread_role_ids' => [],
];

$editHousePermissionSelections = [
    'view_house_ids' => [],
    'access_house_ids' => [],
    'thread_house_ids' => [],
    'reply_house_ids' => [],
    'poll_house_ids' => [],
    'view_all_thread_house_ids' => [],
];

if ($editForumId > 0) {
    $editForumStatement = $pdo->prepare(
        'SELECT
            id,
            category_id,
            parent_forum_id,
            title,
            slug,
            description,
            access_mode,
            restricted_access_rule,
            default_can_view_forum,
            default_can_access_forum,
            default_can_create_threads,
            default_can_reply,
            default_can_create_polls,
            allow_polls,
            is_announcement_forum,
            is_house_news_forum
         FROM forums
         WHERE id = :forum_id
         LIMIT 1'
    );
    $editForumStatement->execute(['forum_id' => $editForumId]);
    $editForum = $editForumStatement->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($editForum !== null) {
        try {
            $editForumImageMap = forum_image_map_load($pdo, $editForumId);
        } catch (Throwable $exception) {
            error_log('Blackthorne edit forum image map load error: ' . $exception->getMessage());
        }

        $editPermissionSelections['view_role_ids'] =
            (int) $editForum['default_can_view_forum'] === 1
                ? ['everyone']
                : [];
        $editPermissionSelections['access_role_ids'] =
            (int) $editForum['default_can_access_forum'] === 1
                ? ['everyone']
                : [];
        $editPermissionSelections['thread_role_ids'] =
            (int) $editForum['default_can_create_threads'] === 1
                ? ['everyone']
                : [];
        $editPermissionSelections['reply_role_ids'] =
            (int) $editForum['default_can_reply'] === 1
                ? ['everyone']
                : [];
        $editPermissionSelections['poll_role_ids'] =
            (int) $editForum['default_can_create_polls'] === 1
                ? ['everyone']
                : [];

        $editRoleAccessStatement = $pdo->prepare(
            'SELECT
                role_id,
                can_view_forum,
                can_access_forum,
                can_view_all_threads,
                can_create_threads,
                can_reply,
                can_create_polls
             FROM forum_role_access
             WHERE forum_id = :forum_id'
        );
        $editRoleAccessStatement->execute(['forum_id' => $editForumId]);

        foreach ($editRoleAccessStatement->fetchAll(PDO::FETCH_ASSOC) as $roleAccess) {
            $roleIdString = (string) (int) $roleAccess['role_id'];

            if ((int) $roleAccess['can_view_forum'] === 1) {
                $editPermissionSelections['view_role_ids'][] = $roleIdString;
            }

            if ((int) $roleAccess['can_access_forum'] === 1) {
                $editPermissionSelections['access_role_ids'][] = $roleIdString;
            }

            if ((int) $roleAccess['can_create_threads'] === 1) {
                $editPermissionSelections['thread_role_ids'][] = $roleIdString;
            }

            if ((int) $roleAccess['can_reply'] === 1) {
                $editPermissionSelections['reply_role_ids'][] = $roleIdString;
            }

            if ((int) $roleAccess['can_create_polls'] === 1) {
                $editPermissionSelections['poll_role_ids'][] = $roleIdString;
            }

            if ((int) $roleAccess['can_view_all_threads'] === 1) {
                $editPermissionSelections['view_all_thread_role_ids'][] = $roleIdString;
            }
        }

        $editHouseAccessStatement = $pdo->prepare(
            'SELECT
                house_id,
                can_view_forum,
                can_access_forum,
                can_view_all_threads,
                can_create_threads,
                can_reply,
                can_create_polls
             FROM forum_house_access
             WHERE forum_id = :forum_id'
        );
        $editHouseAccessStatement->execute(['forum_id' => $editForumId]);

        foreach ($editHouseAccessStatement->fetchAll(PDO::FETCH_ASSOC) as $houseAccess) {
            $houseIdString = (string) (int) $houseAccess['house_id'];

            if ((int) $houseAccess['can_view_forum'] === 1) {
                $editHousePermissionSelections['view_house_ids'][] = $houseIdString;
            }

            if ((int) $houseAccess['can_access_forum'] === 1) {
                $editHousePermissionSelections['access_house_ids'][] = $houseIdString;
            }

            if ((int) $houseAccess['can_create_threads'] === 1) {
                $editHousePermissionSelections['thread_house_ids'][] = $houseIdString;
            }

            if ((int) $houseAccess['can_reply'] === 1) {
                $editHousePermissionSelections['reply_house_ids'][] = $houseIdString;
            }

            if ((int) $houseAccess['can_create_polls'] === 1) {
                $editHousePermissionSelections['poll_house_ids'][] = $houseIdString;
            }

            if ((int) $houseAccess['can_view_all_threads'] === 1) {
                $editHousePermissionSelections['view_all_thread_house_ids'][] = $houseIdString;
            }
        }

        $editLabelsStatement = $pdo->prepare(
            'SELECT id, name, label_color
             FROM forum_labels
             WHERE forum_id = :forum_id
               AND is_active = 1
             ORDER BY sort_order ASC, name ASC'
        );
        $editLabelsStatement->execute(['forum_id' => $editForumId]);
        $editForumLabels = $editLabelsStatement->fetchAll(PDO::FETCH_ASSOC);

        $editModeratorsStatement = $pdo->prepare(
            'SELECT user_id
             FROM forum_moderators
             WHERE forum_id = :forum_id
               AND is_active = 1
               AND ended_at IS NULL'
        );
        $editModeratorsStatement->execute(['forum_id' => $editForumId]);
        $editForumModeratorIds = array_map(
            'intval',
            $editModeratorsStatement->fetchAll(PDO::FETCH_COLUMN)
        );
    }
}

$successMessage = get_flash('success');


/*
|--------------------------------------------------------------------------
| Page Metadata
|--------------------------------------------------------------------------
*/

$pageTitle = 'Forum Management | Blackthorne Academy';
$pageDescription = 'Create and manage Blackthorne Academy forum categories, forums, permissions, labels, and moderators.';
$pageCanonical = url('admin/forums.php');
$robots = 'noindex, nofollow';

require INCLUDES_PATH . '/header.php';

?>

<main id="main-content" class="forum-admin-page">

    <section class="forum-admin-hero" aria-labelledby="forum-admin-heading">
        <div class="section-inner">
            <p class="academy-overline">Academy Administration</p>
            <h1 id="forum-admin-heading">Forum Management</h1>
            <p>
                Create the Academy's forum structure, control access, prepare
                thread labels, and appoint forum moderators.
            </p>
            <div class="forum-admin-edit-actions">
                <a href="<?= e(url('staff-dashboard.php')); ?>" class="button button-secondary">
                    Staff Dashboard
                </a>

                <a href="<?= e(DASHBOARD_URL); ?>" class="button button-secondary">
                    Return to Dashboard
                </a>
            </div>
        </div>
    </section>


    <section class="forum-admin-content">
        <div class="section-inner">

            <?php if ($successMessage !== null): ?>
            <div class="form-message form-message-success" role="status">
                <?= e($successMessage); ?>
            </div>
            <?php endif; ?>

            <?php if ($errors !== []): ?>
            <div class="form-message form-message-error" role="alert">
                <h2>Please correct the following:</h2>
                <ul>
                    <?php foreach ($errors as $error): ?>
                    <li><?= e($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>


            <section class="forum-admin-actions" data-forum-admin-actions>
                <div>
                    <p class="forum-admin-step">Forum Builder</p>
                    <h2>Manage Forum Structure</h2>
                    <p>Create categories and boards, then drag boards into the order you want.</p>
                </div>
                <div class="forum-admin-action-buttons">
                    <button type="button" class="button button-primary" data-open-create-panel="create-category-panel">
                        Create Category
                    </button>
                    <button type="button" class="button button-primary" data-open-create-panel="create-forum-panel"
                        <?= $forumCategories === [] ? 'disabled' : ''; ?>>
                        Create Board
                    </button>
                </div>
            </section>

            <div class="forum-admin-form-grid">

                <section class="forum-admin-panel forum-admin-create-panel" id="create-category-panel"
                    aria-labelledby="create-category-heading" hidden>
                    <header class="forum-admin-titlebar">
                        <p class="forum-admin-step">Step I</p>
                        <h2 id="create-category-heading">Create a Category</h2>
                    </header>

                    <form action="<?= e(url('admin/forums.php')); ?>" method="post" class="forum-admin-form"
                        data-category-access-scope>
                        <?= csrf_field(); ?>
                        <input type="hidden" name="form_action" value="create_category">

                        <div class="form-group">
                            <label for="category-name">Name</label>
                            <input class="form-control js-slug-source" type="text" id="category-name"
                                name="category_name" maxlength="160"
                                value="<?= $formAction === 'create_category' ? e(post_value('category_name')) : ''; ?>"
                                data-slug-preview="category-slug-preview" required>
                            <p class="form-help">
                                URL: <code id="category-slug-preview">category-name</code>
                            </p>
                        </div>

                        <div class="form-group">
                            <label for="category-display-name">Display Name</label>
                            <input class="form-control" type="text" id="category-display-name"
                                name="category_display_name" maxlength="150"
                                value="<?= $formAction === 'create_category' ? e(post_value('category_display_name')) : ''; ?>"
                                required>
                        </div>

                        <div class="form-group">
                            <label for="category-description">Description</label>
                            <textarea class="form-control" id="category-description" name="category_description"
                                rows="5"><?= $formAction === 'create_category' ? e(post_value('category_description')) : ''; ?></textarea>
                            <p class="form-help">
                                Administrative reference describing the boards that belong here.
                            </p>
                        </div>

                        <fieldset class="forum-admin-fieldset">
                            <legend>Show in Member Sidebar?</legend>
                            <label class="forum-admin-choice">
                                <input type="radio" name="show_in_sidebar" value="1">
                                <span>Yes</span>
                            </label>
                            <label class="forum-admin-choice">
                                <input type="radio" name="show_in_sidebar" value="0" checked>
                                <span>No</span>
                            </label>
                        </fieldset>

                        <fieldset class="forum-admin-fieldset">
                            <legend>Who Can Access This Category?</legend>
                            <label class="forum-admin-choice">
                                <input type="radio" name="category_access_mode" value="everyone" checked
                                    data-category-access-toggle>
                                <span>Everyone</span>
                            </label>
                            <label class="forum-admin-choice">
                                <input type="radio" name="category_access_mode" value="restricted"
                                    data-category-access-toggle>
                                <span>Selected Roles</span>
                            </label>

                            <div class="forum-admin-role-box" data-category-role-box hidden>
                                <?php foreach ($availableRoles as $role): ?>
                                <label>
                                    <input type="checkbox" name="category_role_ids[]" value="<?= (int) $role['id']; ?>">
                                    <span><?= e((string) $role['name']); ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>

                        <button type="submit" class="button button-primary">
                            Create Category
                        </button>
                    </form>
                </section>


                <section class="forum-admin-panel forum-admin-create-panel" id="create-forum-panel"
                    aria-labelledby="create-forum-heading" hidden>
                    <header class="forum-admin-titlebar">
                        <p class="forum-admin-step">Step II</p>
                        <h2 id="create-forum-heading">Create a Forum</h2>
                    </header>

                    <?php if ($forumCategories === []): ?>
                    <div class="forum-admin-empty">
                        Create at least one category before creating a forum.
                    </div>
                    <?php else: ?>
                    <form action="<?= e(url('admin/forums.php')); ?>" method="post" enctype="multipart/form-data"
                        class="forum-admin-form">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="form_action" value="create_forum">

                        <div class="form-group">
                            <label for="forum-category-id">Location: Category</label>
                            <select class="form-control" id="forum-category-id" name="forum_category_id"
                                data-forum-category-select required>
                                <option value="">Choose a category</option>
                                <?php foreach ($forumCategories as $category): ?>
                                <option value="<?= (int) $category['id']; ?>">
                                    <?= e((string) $category['title']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="parent-forum-id">Parent Forum</label>
                            <select class="form-control" id="parent-forum-id" name="parent_forum_id"
                                data-parent-forum-select data-forum-picker>
                                <option value="0">None — create a main forum</option>
                                <?php foreach ($forumParentOptions as $parentOption): ?>
                                <?php
                                        $parentDepth =
                                            (int) (
                                                $parentOption[
                                                    'hierarchy_depth'
                                                ]
                                                ?? 0
                                            );
                                        ?>
                                <option value="<?= (int) $parentOption['id']; ?>"
                                    data-category-id="<?= (int) $parentOption['category_id']; ?>"
                                    data-forum-id="<?= (int) $parentOption['id']; ?>"
                                    data-parent-forum-id="<?= (int) ($parentOption['parent_forum_id'] ?? 0); ?>"
                                    data-depth="<?= $parentDepth; ?>"
                                    data-forum-title="<?= e((string) $parentOption['title']); ?>" hidden>
                                    <?= e(
                                                str_repeat(
                                                    '    ',
                                                    $parentDepth
                                                )
                                                . (string) $parentOption['title']
                                            ); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="form-help">
                                Choose any forum or sub-forum to nest this forum beneath it.
                            </p>
                        </div>

                        <div class="form-group">
                            <label for="forum-name">Name</label>
                            <input class="form-control js-slug-source" type="text" id="forum-name" name="forum_name"
                                maxlength="160" data-slug-preview="forum-slug-preview" required>
                            <p class="form-help">
                                URL: <code id="forum-slug-preview">forum-name</code>
                            </p>
                        </div>

                        <div class="form-group">
                            <label for="forum-display-name">Display Name</label>
                            <input class="form-control" type="text" id="forum-display-name" name="forum_display_name"
                                maxlength="150" required>
                        </div>

                        <div class="form-group">
                            <label for="forum-description">Description</label>
                            <textarea class="form-control" id="forum-description" name="forum_description" rows="6"
                                required></textarea>
                            <p class="form-help">
                                This reminder will appear at the bottom of the forum page.
                            </p>
                        </div>

                        <fieldset class="forum-admin-fieldset">
                            <legend>Forum Settings</legend>
                            <label class="forum-admin-choice">
                                <input type="checkbox" name="is_private" value="1">
                                <span>Private forum</span>
                            </label>
                            <label class="forum-admin-choice">
                                <input type="checkbox" name="allow_polls" value="1" checked>
                                <span>Enable polls</span>
                            </label>
                            <label class="forum-admin-choice">
                                <input type="checkbox" name="is_announcement_forum" value="1">
                                <span>Announcement Forum</span>
                            </label>
                            <p class="form-help">
                                Threads posted in an Announcement Forum can appear in the
                                homepage Announcements / Events section.
                            </p>
                            <label class="forum-admin-choice">
                                <input type="checkbox" name="is_house_news_forum" value="1">
                                <span>House News Forum</span>
                            </label>
                            <p class="form-help">
                                The newest accessible thread from a House News Forum is used
                                for that House member's dashboard news panel. House access
                                permissions determine which House sees it.
                            </p>
                        </fieldset>

                        <?php admin_forum_render_image_map_builder('create-forum-map', null, $allForums, $savedImageMaps); ?>

                        <fieldset class="forum-admin-fieldset">
                            <legend>Forum Permissions</legend>
                            <p class="form-help">
                                Choose how selected roles/groups and Houses work together, then assign the permitted
                                groups for each action.
                            </p>

                            <div class="form-group">
                                <label for="restricted-access-rule">
                                    Access Requirement
                                </label>

                                <select class="form-control" id="restricted-access-rule" name="restricted_access_rule">
                                    <option value="roles">
                                        Selected roles / groups
                                    </option>
                                    <option value="houses">
                                        Selected Houses
                                    </option>
                                    <option value="roles_or_houses">
                                        Selected role/group OR selected House
                                    </option>
                                    <option value="roles_and_houses">
                                        Selected role/group AND selected House
                                    </option>
                                </select>

                                <p class="form-help">
                                    “Everyone” still grants that individual permission to everyone.
                                    For a House-only board, uncheck Everyone for View / Enter and
                                    select the permitted House below.
                                </p>
                            </div>

                            <?php
                                $permissionFields = [
                                    'view_role_ids' => 'Who can see that the forum exists?',
                                    'access_role_ids' => 'Who can enter the forum?',
                                    'thread_role_ids' => 'Who can create threads?',
                                    'reply_role_ids' => 'Who can reply?',
                                    'poll_role_ids' => 'Who can create polls?',
                                    'view_all_thread_role_ids' => 'Who can view every private thread?',
                                ];
                                ?>

                            <?php foreach ($permissionFields as $fieldName => $fieldLabel): ?>
                            <div class="form-group forum-permission-group" data-permission-group>
                                <span class="forum-permission-label">
                                    <?= e($fieldLabel); ?>
                                </span>
                                <?php
                                        $houseFieldName =
                                            str_replace(
                                                '_role_ids',
                                                '_house_ids',
                                                $fieldName
                                            );
                                        ?>

                                <p class="form-help">
                                    Roles / Groups
                                </p>

                                <div class="forum-admin-role-box">
                                    <?php if ($fieldName !== 'view_all_thread_role_ids'): ?>
                                    <label>
                                        <input type="checkbox" name="<?= e($fieldName); ?>[]" value="everyone" checked
                                            data-permission-everyone>
                                        <span>Everyone</span>
                                    </label>
                                    <?php endif; ?>
                                    <?php foreach ($availableRoles as $role): ?>
                                    <label>
                                        <input type="checkbox" name="<?= e($fieldName); ?>[]"
                                            value="<?= (int) $role['id']; ?>" data-permission-role>
                                        <span><?= e((string) $role['name']); ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>

                                <p class="form-help">
                                    Houses
                                </p>

                                <div class="forum-admin-role-box">
                                    <?php if ($availableHouses === []): ?>
                                    <span class="form-help">
                                        No active Houses have been created yet.
                                    </span>
                                    <?php else: ?>
                                    <?php foreach ($availableHouses as $house): ?>
                                    <?php
                                                    $houseLabel =
                                                        trim(
                                                            (string) (
                                                                $house['display_name']
                                                                ?? ''
                                                            )
                                                        );

                                                    if ($houseLabel === '') {
                                                        $houseLabel =
                                                            (string) $house['name'];
                                                    }
                                                    ?>
                                    <label>
                                        <input type="checkbox" name="<?= e($houseFieldName); ?>[]"
                                            value="<?= (int) $house['id']; ?>" data-permission-house>
                                        <span><?= e($houseLabel); ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </fieldset>

                        <fieldset class="forum-admin-fieldset">
                            <legend>Thread Labels</legend>
                            <p class="form-help">
                                Labels created here can later be attached to threads by authorized staff.
                            </p>

                            <div data-label-list>
                                <div class="forum-label-row">
                                    <input class="form-control" type="text" name="label_name[]" maxlength="100"
                                        placeholder="Label name">
                                    <input class="forum-label-color" type="color" name="label_color[]" value="#744081"
                                        aria-label="Label color">
                                    <button class="forum-label-remove" type="button" data-remove-label
                                        aria-label="Remove label">
                                        ×
                                    </button>
                                </div>
                            </div>

                            <button class="button button-secondary forum-add-label" type="button" data-add-label>
                                Add Another Label
                            </button>
                        </fieldset>

                        <fieldset class="forum-admin-fieldset">
                            <legend>Forum Moderators</legend>
                            <label for="moderator-user-ids">Assign Registered Members</label>
                            <select class="form-control" id="moderator-user-ids" name="moderator_user_ids[]" multiple
                                size="7">
                                <?php foreach ($availableMembers as $member): ?>
                                <option value="<?= (int) $member['id']; ?>">
                                    <?= e((string) $member['display_name']); ?>
                                    (@<?= e((string) $member['username']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="form-help">
                                Use Ctrl or Command to select more than one moderator.
                            </p>
                        </fieldset>

                        <button type="submit" class="button button-primary">
                            Create Forum
                        </button>
                    </form>
                    <?php endif; ?>
                </section>

            </div>


            <?php if ($editCategory !== null): ?>
            <?php
                $editingCategoryAfterPost =
                    $formAction === 'update_category'
                    && (int) post_value('category_id') === (int) $editCategory['id'];

                $editCategoryNameValue =
                    $editingCategoryAfterPost
                        ? post_value('category_name')
                        : (string) $editCategory['slug'];

                $editCategoryDisplayNameValue =
                    $editingCategoryAfterPost
                        ? post_value('category_display_name')
                        : (string) $editCategory['title'];

                $editCategoryDescriptionValue =
                    $editingCategoryAfterPost
                        ? post_value('category_description')
                        : (string) ($editCategory['description'] ?? '');

                $editCategorySidebarValue =
                    $editingCategoryAfterPost
                        ? post_value('show_in_sidebar', '0')
                        : ((int) $editCategory['show_in_sidebar'] === 1 ? '1' : '0');

                $editCategoryAccessValue =
                    $editingCategoryAfterPost
                        ? post_value('category_access_mode', 'everyone')
                        : (
                            (string) $editCategory['access_mode'] === 'restricted'
                                ? 'restricted'
                                : 'everyone'
                        );

                $editCategorySelectedRoleIds =
                    $editingCategoryAfterPost
                        ? admin_forum_post_ids('category_role_ids')
                        : $editCategoryRoleIds;
                ?>

            <section class="forum-admin-panel forum-admin-edit-panel" aria-labelledby="edit-category-heading">
                <header class="forum-admin-titlebar">
                    <p class="forum-admin-step">Edit Category</p>
                    <h2 id="edit-category-heading">
                        <?= e((string) $editCategory['title']); ?>
                    </h2>
                </header>

                <form action="<?= e(url('admin/forums.php?edit_category=' . (int) $editCategory['id'])); ?>"
                    method="post" class="forum-admin-form" data-category-access-scope>
                    <?= csrf_field(); ?>
                    <input type="hidden" name="form_action" value="update_category">
                    <input type="hidden" name="category_id" value="<?= (int) $editCategory['id']; ?>">

                    <div class="form-group">
                        <label for="edit-category-name">Name</label>
                        <input class="form-control js-slug-source" type="text" id="edit-category-name"
                            name="category_name" maxlength="160" value="<?= e($editCategoryNameValue); ?>"
                            data-slug-preview="edit-category-slug-preview" required>
                        <p class="form-help">
                            URL:
                            <code id="edit-category-slug-preview">
                                <?= e((string) $editCategory['slug']); ?>
                            </code>
                        </p>
                    </div>

                    <div class="form-group">
                        <label for="edit-category-display-name">Display Name</label>
                        <input class="form-control" type="text" id="edit-category-display-name"
                            name="category_display_name" maxlength="150"
                            value="<?= e($editCategoryDisplayNameValue); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="edit-category-description">Description</label>
                        <textarea class="form-control" id="edit-category-description" name="category_description"
                            rows="5"><?= e($editCategoryDescriptionValue); ?></textarea>
                        <p class="form-help">
                            Administrative reference describing the boards that belong here.
                        </p>
                    </div>

                    <fieldset class="forum-admin-fieldset">
                        <legend>Show in Member Sidebar?</legend>
                        <label class="forum-admin-choice">
                            <input type="radio" name="show_in_sidebar" value="1"
                                <?= $editCategorySidebarValue === '1' ? 'checked' : ''; ?>>
                            <span>Yes</span>
                        </label>
                        <label class="forum-admin-choice">
                            <input type="radio" name="show_in_sidebar" value="0"
                                <?= $editCategorySidebarValue !== '1' ? 'checked' : ''; ?>>
                            <span>No</span>
                        </label>
                    </fieldset>

                    <fieldset class="forum-admin-fieldset">
                        <legend>Who Can Access This Category?</legend>
                        <label class="forum-admin-choice">
                            <input type="radio" name="category_access_mode" value="everyone" data-category-access-toggle
                                <?= $editCategoryAccessValue !== 'restricted' ? 'checked' : ''; ?>>
                            <span>Everyone</span>
                        </label>
                        <label class="forum-admin-choice">
                            <input type="radio" name="category_access_mode" value="restricted"
                                data-category-access-toggle
                                <?= $editCategoryAccessValue === 'restricted' ? 'checked' : ''; ?>>
                            <span>Selected Roles</span>
                        </label>

                        <div class="forum-admin-role-box" data-category-role-box
                            <?= $editCategoryAccessValue === 'restricted' ? '' : 'hidden'; ?>>
                            <?php foreach ($availableRoles as $role): ?>
                            <?php $roleId = (int) $role['id']; ?>
                            <label>
                                <input type="checkbox" name="category_role_ids[]" value="<?= $roleId; ?>"
                                    <?= in_array($roleId, $editCategorySelectedRoleIds, true) ? 'checked' : ''; ?>>
                                <span><?= e((string) $role['name']); ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>

                    <div class="forum-admin-actions">
                        <button type="submit" class="button button-primary">
                            Save Category Changes
                        </button>
                        <a href="<?= e(url('admin/forums.php')); ?>" class="button button-secondary">
                            Cancel Editing
                        </a>
                    </div>
                </form>

                <div class="forum-admin-danger-zone">
                    <h3>Delete Category</h3>
                    <p>
                        Permanently delete this category and everything inside it,
                        including all boards, sub-forums, threads, posts, polls, labels,
                        forum access settings, and related forum content.
                    </p>
                    <form action="<?= e(url('admin/forums.php?edit_category=' . (int) $editCategory['id'])); ?>"
                        method="post">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="form_action" value="delete_category">
                        <input type="hidden" name="category_id" value="<?= (int) $editCategory['id']; ?>">
                        <label class="forum-admin-danger-confirm">
                            <input type="checkbox" name="confirm_delete" value="1" required>
                            <span>
                                I understand that this permanently deletes the category and all forum content inside it.
                            </span>
                        </label>
                        <button type="submit" class="button forum-admin-delete-button">
                            Delete Category
                        </button>
                    </form>
                </div>
            </section>
            <?php elseif ($editCategoryId > 0): ?>
            <div class="form-message form-message-error" role="alert">
                The forum category you tried to edit could not be found.
            </div>
            <?php endif; ?>


            <?php if ($editForum !== null): ?>
            <section class="forum-admin-panel forum-admin-edit-panel" aria-labelledby="edit-forum-heading">
                <header class="forum-admin-titlebar">
                    <p class="forum-admin-step">Edit Forum</p>
                    <h2 id="edit-forum-heading">
                        <?= e((string) $editForum['title']); ?>
                    </h2>
                </header>

                <form action="<?= e(url('admin/forums.php?edit_forum=' . (int) $editForum['id'])); ?>" method="post"
                    enctype="multipart/form-data" class="forum-admin-form">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="form_action" value="update_forum">
                    <input type="hidden" name="forum_id" value="<?= (int) $editForum['id']; ?>">

                    <div class="form-group">
                        <label for="edit-forum-category-id">Location: Category</label>
                        <select class="form-control" id="edit-forum-category-id" name="forum_category_id"
                            data-edit-forum-category-select required>
                            <?php foreach ($forumCategories as $category): ?>
                            <option value="<?= (int) $category['id']; ?>"
                                <?= (int) $editForum['category_id'] === (int) $category['id'] ? 'selected' : ''; ?>>
                                <?= e((string) $category['title']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="edit-parent-forum-id">Parent Forum</label>
                        <select class="form-control" id="edit-parent-forum-id" name="parent_forum_id"
                            data-edit-parent-forum-select data-forum-picker>
                            <option value="0">None — main forum</option>
                            <?php
                                $editDescendantIds =
                                    admin_forum_descendant_ids(
                                        $pdo,
                                        (int) $editForum['id']
                                    );
                                ?>
                            <?php foreach ($forumParentOptions as $parentOption): ?>
                            <?php
                                    $parentOptionId =
                                        (int) $parentOption['id'];

                                    if (
                                        $parentOptionId
                                        === (int) $editForum['id']
                                        || in_array(
                                            $parentOptionId,
                                            $editDescendantIds,
                                            true
                                        )
                                    ) {
                                        continue;
                                    }

                                    $parentDepth =
                                        (int) (
                                            $parentOption[
                                                'hierarchy_depth'
                                            ]
                                            ?? 0
                                        );
                                    ?>
                            <option value="<?= $parentOptionId; ?>"
                                data-category-id="<?= (int) $parentOption['category_id']; ?>"
                                data-forum-id="<?= $parentOptionId; ?>"
                                data-parent-forum-id="<?= (int) ($parentOption['parent_forum_id'] ?? 0); ?>"
                                data-depth="<?= $parentDepth; ?>"
                                data-forum-title="<?= e((string) $parentOption['title']); ?>"
                                <?= (int) ($editForum['parent_forum_id'] ?? 0) === $parentOptionId ? 'selected' : ''; ?>>
                                <?= e(
                                            str_repeat(
                                                '    ',
                                                $parentDepth
                                            )
                                            . (string) $parentOption['title']
                                        ); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="form-help">
                            Choose any forum or sub-forum in the selected category. A forum cannot be placed inside
                            itself or one of its descendants.
                        </p>
                    </div>

                    <div class="form-group">
                        <label for="edit-forum-name">Name</label>
                        <input class="form-control js-slug-source" type="text" id="edit-forum-name" name="forum_name"
                            maxlength="160" value="<?= e((string) $editForum['slug']); ?>"
                            data-slug-preview="edit-forum-slug-preview" required>
                        <p class="form-help">
                            URL: <code id="edit-forum-slug-preview"><?= e((string) $editForum['slug']); ?></code>
                        </p>
                    </div>

                    <div class="form-group">
                        <label for="edit-forum-display-name">Display Name</label>
                        <input class="form-control" type="text" id="edit-forum-display-name" name="forum_display_name"
                            maxlength="150" value="<?= e((string) $editForum['title']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="edit-forum-description">Description</label>
                        <textarea class="form-control" id="edit-forum-description" name="forum_description" rows="6"
                            required><?= e((string) $editForum['description']); ?></textarea>
                    </div>

                    <fieldset class="forum-admin-fieldset">
                        <legend>Forum Settings</legend>
                        <label class="forum-admin-choice">
                            <input type="checkbox" name="is_private" value="1"
                                <?= (string) $editForum['access_mode'] === 'private_threads' ? 'checked' : ''; ?>>
                            <span>Private forum</span>
                        </label>
                        <label class="forum-admin-choice">
                            <input type="checkbox" name="allow_polls" value="1"
                                <?= (int) $editForum['allow_polls'] === 1 ? 'checked' : ''; ?>>
                            <span>Enable polls</span>
                        </label>
                        <label class="forum-admin-choice">
                            <input type="checkbox" name="is_announcement_forum" value="1"
                                <?= (int) $editForum['is_announcement_forum'] === 1 ? 'checked' : ''; ?>>
                            <span>Announcement Forum</span>
                        </label>
                        <p class="form-help">
                            Threads posted in an Announcement Forum can appear in the homepage Announcements / Events
                            section.
                        </p>
                        <label class="forum-admin-choice">
                            <input type="checkbox" name="is_house_news_forum" value="1"
                                <?= (int) ($editForum['is_house_news_forum'] ?? 0) === 1 ? 'checked' : ''; ?>>
                            <span>House News Forum</span>
                        </label>
                        <p class="form-help">
                            The newest accessible thread from a House News Forum is used for that House member's
                            dashboard news panel. House access permissions determine which House sees it.
                        </p>
                    </fieldset>

                    <?php admin_forum_render_image_map_builder('edit-forum-map', $editForumImageMap, $allForums, $savedImageMaps); ?>

                    <fieldset class="forum-admin-fieldset">
                        <legend>Forum Permissions</legend>
                        <p class="form-help">
                            Choose how selected roles/groups and Houses work together, then assign the permitted groups
                            for each action.
                        </p>

                        <div class="form-group">
                            <label for="edit-restricted-access-rule">
                                Access Requirement
                            </label>

                            <select class="form-control" id="edit-restricted-access-rule" name="restricted_access_rule">
                                <?php
                                    $editRestrictedRule =
                                        (string) (
                                            $editForum['restricted_access_rule']
                                            ?? 'roles'
                                        );
                                    ?>
                                <option value="roles" <?= $editRestrictedRule === 'roles' ? 'selected' : ''; ?>>
                                    Selected roles / groups
                                </option>
                                <option value="houses" <?= $editRestrictedRule === 'houses' ? 'selected' : ''; ?>>
                                    Selected Houses
                                </option>
                                <option value="roles_or_houses"
                                    <?= $editRestrictedRule === 'roles_or_houses' ? 'selected' : ''; ?>>
                                    Selected role/group OR selected House
                                </option>
                                <option value="roles_and_houses"
                                    <?= $editRestrictedRule === 'roles_and_houses' ? 'selected' : ''; ?>>
                                    Selected role/group AND selected House
                                </option>
                            </select>

                            <p class="form-help">
                                “Everyone” still grants that individual permission to everyone.
                                For a House-only board, uncheck Everyone for View / Enter and
                                select the permitted House below.
                            </p>
                        </div>

                        <?php
                            $editPermissionFields = [
                                'view_role_ids' => 'Who can see that the forum exists?',
                                'access_role_ids' => 'Who can enter the forum?',
                                'thread_role_ids' => 'Who can create threads?',
                                'reply_role_ids' => 'Who can reply?',
                                'poll_role_ids' => 'Who can create polls?',
                                'view_all_thread_role_ids' => 'Who can view every private thread?',
                            ];
                            ?>

                        <?php foreach ($editPermissionFields as $fieldName => $fieldLabel): ?>
                        <div class="form-group forum-permission-group" data-permission-group>
                            <span class="forum-permission-label">
                                <?= e($fieldLabel); ?>
                            </span>
                            <?php
                                    $houseFieldName =
                                        str_replace(
                                            '_role_ids',
                                            '_house_ids',
                                            $fieldName
                                        );
                                    ?>

                            <p class="form-help">
                                Roles / Groups
                            </p>

                            <div class="forum-admin-role-box">
                                <?php if ($fieldName !== 'view_all_thread_role_ids'): ?>
                                <label>
                                    <input type="checkbox" name="<?= e($fieldName); ?>[]" value="everyone"
                                        <?= in_array('everyone', $editPermissionSelections[$fieldName], true) ? 'checked' : ''; ?>
                                        data-permission-everyone>
                                    <span>Everyone</span>
                                </label>
                                <?php endif; ?>
                                <?php foreach ($availableRoles as $role): ?>
                                <?php $roleIdString = (string) (int) $role['id']; ?>
                                <label>
                                    <input type="checkbox" name="<?= e($fieldName); ?>[]"
                                        value="<?= (int) $role['id']; ?>"
                                        <?= in_array($roleIdString, $editPermissionSelections[$fieldName], true) ? 'checked' : ''; ?>
                                        data-permission-role>
                                    <span><?= e((string) $role['name']); ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>

                            <p class="form-help">
                                Houses
                            </p>

                            <div class="forum-admin-role-box">
                                <?php if ($availableHouses === []): ?>
                                <span class="form-help">
                                    No active Houses have been created yet.
                                </span>
                                <?php else: ?>
                                <?php foreach ($availableHouses as $house): ?>
                                <?php
                                                $houseIdString =
                                                    (string) (int) $house['id'];

                                                $houseLabel =
                                                    trim(
                                                        (string) (
                                                            $house['display_name']
                                                            ?? ''
                                                        )
                                                    );

                                                if ($houseLabel === '') {
                                                    $houseLabel =
                                                        (string) $house['name'];
                                                }
                                                ?>
                                <label>
                                    <input type="checkbox" name="<?= e($houseFieldName); ?>[]"
                                        value="<?= (int) $house['id']; ?>" <?= in_array(
                                                            $houseIdString,
                                                            $editHousePermissionSelections[$houseFieldName],
                                                            true
                                                        ) ? 'checked' : ''; ?> data-permission-house>
                                    <span><?= e($houseLabel); ?></span>
                                </label>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </fieldset>

                    <fieldset class="forum-admin-fieldset">
                        <legend>Thread Labels</legend>
                        <p class="form-help">
                            Removing a label here makes it inactive instead of deleting its history from existing
                            threads.
                        </p>

                        <div data-edit-label-list>
                            <?php if ($editForumLabels === []): ?>
                            <div class="forum-label-row">
                                <input type="hidden" name="label_id[]" value="0">
                                <input class="form-control" type="text" name="label_name[]" maxlength="100"
                                    placeholder="Label name">
                                <input class="forum-label-color" type="color" name="label_color[]" value="#744081"
                                    aria-label="Label color">
                                <button class="forum-label-remove" type="button" data-remove-edit-label
                                    aria-label="Remove label">×</button>
                            </div>
                            <?php else: ?>
                            <?php foreach ($editForumLabels as $label): ?>
                            <div class="forum-label-row">
                                <input type="hidden" name="label_id[]" value="<?= (int) $label['id']; ?>">
                                <input class="form-control" type="text" name="label_name[]" maxlength="100"
                                    value="<?= e((string) $label['name']); ?>" placeholder="Label name">
                                <input class="forum-label-color" type="color" name="label_color[]"
                                    value="<?= e((string) $label['label_color']); ?>" aria-label="Label color">
                                <button class="forum-label-remove" type="button" data-remove-edit-label
                                    aria-label="Remove label">×</button>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <button class="button button-secondary forum-add-label" type="button" data-add-edit-label>
                            Add Another Label
                        </button>
                    </fieldset>

                    <fieldset class="forum-admin-fieldset">
                        <legend>Forum Moderators</legend>
                        <label for="edit-moderator-user-ids">Assigned Registered Members</label>
                        <select class="form-control" id="edit-moderator-user-ids" name="moderator_user_ids[]" multiple
                            size="7">
                            <?php foreach ($availableMembers as $member): ?>
                            <option value="<?= (int) $member['id']; ?>"
                                <?= in_array((int) $member['id'], $editForumModeratorIds, true) ? 'selected' : ''; ?>>
                                <?= e((string) $member['display_name']); ?>
                                (@<?= e((string) $member['username']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="form-help">
                            Use Ctrl or Command to select more than one moderator.
                        </p>
                    </fieldset>

                    <div class="forum-admin-actions">
                        <button type="submit" class="button button-primary">
                            Save Forum Changes
                        </button>
                        <a href="<?= e(url('admin/forums.php')); ?>" class="button button-secondary">
                            Cancel Editing
                        </a>
                    </div>
                </form>

                <div class="forum-admin-danger-zone">
                    <h3>Delete Forum</h3>
                    <p>
                        Permanently delete this forum and its threads, posts, polls,
                        labels, access rules, and related forum data. A main forum cannot
                        be deleted until its direct sub-forums have been moved or deleted.
                    </p>
                    <form action="<?= e(url('admin/forums.php?edit_forum=' . (int) $editForum['id'])); ?>"
                        method="post">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="form_action" value="delete_forum">
                        <input type="hidden" name="forum_id" value="<?= (int) $editForum['id']; ?>">
                        <label class="forum-admin-danger-confirm">
                            <input type="checkbox" name="confirm_delete" value="1" required>
                            <span>
                                I understand that deleting this forum is permanent.
                            </span>
                        </label>
                        <button type="submit" class="button forum-admin-delete-button">
                            Delete Forum
                        </button>
                    </form>
                </div>
            </section>
            <?php elseif ($editForumId > 0): ?>
            <div class="form-message form-message-error" role="alert">
                The forum you tried to edit could not be found.
            </div>
            <?php endif; ?>


            <section class="forum-structure-panel" aria-labelledby="forum-structure-heading" data-forum-structure-panel>
                <header class="forum-admin-titlebar">
                    <p class="forum-admin-step">Current Structure</p>
                    <h2 id="forum-structure-heading">Categories, Forums &amp; Nested Sub-forums</h2>
                </header>

                <?php if ($forumCategories === []): ?>
                <div class="forum-admin-empty">
                    No forum categories have been created yet.
                </div>
                <?php else: ?>
                <div class="forum-structure-list">
                    <?php foreach ($forumCategories as $category): ?>
                    <?php
                            $categoryId = (int) $category['id'];
                            $categoryForums = $forumsByCategory[$categoryId] ?? [];
                            ?>

                    <article class="forum-structure-category">
                        <header>
                            <div>
                                <h3><?= e((string) $category['title']); ?></h3>
                                <p>/<?= e((string) $category['slug']); ?></p>
                            </div>
                            <div class="forum-structure-badges">
                                <span><?= (int) $category['show_in_sidebar'] === 1 ? 'Sidebar' : 'Not in Sidebar'; ?></span>
                                <span><?= e(ucfirst((string) $category['access_mode'])); ?></span>
                                <a class="button button-secondary"
                                    href="<?= e(url('admin/forums.php?edit_category=' . (int) $category['id'])); ?>">
                                    Edit Category
                                </a>
                                <form method="post" action="<?= e(url('admin/forums.php')); ?>"
                                    class="forum-structure-inline-form"
                                    data-confirm-delete="Delete this category and EVERYTHING inside it? This cannot be undone.">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="form_action" value="delete_category">
                                    <input type="hidden" name="category_id" value="<?= (int) $category['id']; ?>">
                                    <input type="hidden" name="confirm_delete" value="1">
                                    <button type="submit" class="button forum-admin-delete-button">Delete</button>
                                </form>
                            </div>
                        </header>

                        <?php if ($categoryForums === []): ?>
                        <div class="forum-structure-forums is-empty" data-forum-sort-container data-parent-id="0"
                            data-category-id="<?= $categoryId; ?>">
                            <p class="forum-structure-empty" data-forum-drop-hint>
                                No forums in this category. Drag a board here to move it into this category.
                            </p>
                        </div>
                        <?php else: ?>
                        <div class="forum-structure-forums" data-forum-sort-container data-parent-id="0"
                            data-category-id="<?= $categoryId; ?>">
                            <?php foreach ($categoryForums as $forum): ?>
                            <?php
                                            $renderForumStructureNode(
                                                $forum,
                                                $categoryId,
                                                0
                                            );
                                            ?>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </article>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <form method="post" action="<?= e(url('admin/forums.php')); ?>" data-forum-reorder-form hidden>
                    <?= csrf_field(); ?>
                    <input type="hidden" name="form_action" value="reorder_forums">
                    <input type="hidden" name="forum_order_json" value="" data-forum-order-json>
                </form>
            </section>

        </div>
    </section>

    <div class="forum-admin-floating-tools" data-forum-floating-tools aria-label="Forum quick actions">
        <div class="forum-admin-floating-thread" data-floating-thread-panel hidden>
            <label for="floating-thread-forum-id">
                Forum ID
            </label>

            <div class="forum-admin-floating-thread-row">
                <input class="form-control" type="number" id="floating-thread-forum-id" min="1" step="1"
                    inputmode="numeric" placeholder="e.g. 14" data-floating-thread-forum-id>

                <button type="button" class="button button-primary" data-floating-thread-go>
                    Create Thread
                </button>
            </div>

            <p class="form-help">
                Enter the forum ID shown in the structure list.
            </p>
        </div>

        <div class="forum-admin-floating-buttons">
            <button type="button" class="button button-secondary" data-floating-create-thread aria-expanded="false">
                Create Thread
            </button>

            <button type="button" class="button button-primary" data-open-create-panel="create-forum-panel"
                <?= $forumCategories === [] ? 'disabled' : ''; ?>>
                Create Forum
            </button>
        </div>
    </div>

</main>


<template id="forum-label-row-template">
    <div class="forum-label-row">
        <input class="form-control" type="text" name="label_name[]" maxlength="100" placeholder="Label name">
        <input class="forum-label-color" type="color" name="label_color[]" value="#744081" aria-label="Label color">
        <button class="forum-label-remove" type="button" data-remove-label aria-label="Remove label">
            ×
        </button>
    </div>
</template>

<template id="forum-edit-label-row-template">
    <div class="forum-label-row">
        <input type="hidden" name="label_id[]" value="0">
        <input class="form-control" type="text" name="label_name[]" maxlength="100" placeholder="Label name">
        <input class="forum-label-color" type="color" name="label_color[]" value="#744081" aria-label="Label color">
        <button class="forum-label-remove" type="button" data-remove-edit-label aria-label="Remove label">
            ×
        </button>
    </div>
</template>


<style>
    .forum-admin-floating-tools {
        position: fixed;
        right: 1.5rem;
        bottom: 1.5rem;
        z-index: 80;
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 0.65rem;
        max-width: min(92vw, 28rem);
    }

    .forum-admin-floating-buttons {
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-end;
        gap: 0.6rem;
        padding: 0.65rem;
        border: 1px solid rgba(197, 157, 85, 0.38);
        border-radius: 0.85rem;
        background: rgba(24, 15, 24, 0.96);
        box-shadow: 0 0.75rem 2rem rgba(0, 0, 0, 0.35);
        backdrop-filter: blur(8px);
    }

    .forum-admin-floating-thread {
        width: min(25rem, calc(100vw - 3rem));
        padding: 0.9rem;
        border: 1px solid rgba(197, 157, 85, 0.42);
        border-radius: 0.85rem;
        background: rgba(24, 15, 24, 0.98);
        box-shadow: 0 0.75rem 2rem rgba(0, 0, 0, 0.38);
    }

    .forum-admin-floating-thread>label {
        display: block;
        margin-bottom: 0.45rem;
        color: var(--color-gold, #c59d55);
    }

    .forum-admin-floating-thread-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 0.6rem;
        align-items: center;
    }

    .forum-admin-floating-thread .form-help {
        margin: 0.5rem 0 0;
    }

    @media (max-width: 640px) {
        .forum-admin-floating-tools {
            right: 0.75rem;
            bottom: 0.75rem;
            left: 0.75rem;
            max-width: none;
            align-items: stretch;
        }

        .forum-admin-floating-buttons {
            justify-content: stretch;
        }

        .forum-admin-floating-buttons .button {
            flex: 1 1 0;
        }

        .forum-admin-floating-thread {
            width: auto;
        }

        .forum-admin-floating-thread-row {
            grid-template-columns: 1fr;
        }
    }

</style>


<script>
    document.addEventListener('DOMContentLoaded', function() {
        function slugify(value) {
            return value
                .toLowerCase()
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '')
                .slice(0, 160);
        }

        document.querySelectorAll('.js-slug-source').forEach(function(input) {
            var preview = document.getElementById(input.dataset.slugPreview || '');

            if (!preview) {
                return;
            }

            function updatePreview() {
                preview.textContent = slugify(input.value) || 'url-name';
            }

            input.addEventListener('input', updatePreview);
            updatePreview();
        });

        document.querySelectorAll('[data-permission-group]').forEach(function(group) {
            var everyone = group.querySelector('[data-permission-everyone]');
            var specificOptions = group.querySelectorAll(
                '[data-permission-role], [data-permission-house]'
            );

            if (everyone) {
                everyone.addEventListener('change', function() {
                    if (!everyone.checked) {
                        return;
                    }

                    specificOptions.forEach(function(option) {
                        option.checked = false;
                    });
                });
            }

            specificOptions.forEach(function(option) {
                option.addEventListener('change', function() {
                    if (option.checked && everyone) {
                        everyone.checked = false;
                    }
                });
            });
        });

        document.querySelectorAll('[data-category-access-scope]').forEach(function(scope) {
            var categoryToggles =
                scope.querySelectorAll('[data-category-access-toggle]');
            var categoryRoleBox =
                scope.querySelector('[data-category-role-box]');

            function updateCategoryRoles() {
                if (!categoryRoleBox) {
                    return;
                }

                var restricted = scope.querySelector(
                    '[data-category-access-toggle][value="restricted"]:checked'
                );

                categoryRoleBox.hidden = !restricted;
            }

            categoryToggles.forEach(function(toggle) {
                toggle.addEventListener('change', updateCategoryRoles);
            });

            updateCategoryRoles();
        });

        var categorySelect = document.querySelector('[data-forum-category-select]');
        var parentSelect = document.querySelector('[data-parent-forum-select]');

        function updateParentForums() {
            if (!categorySelect || !parentSelect) {
                return;
            }

            var categoryId = categorySelect.value;
            parentSelect.value = '0';

            parentSelect.querySelectorAll('option[data-category-id]').forEach(function(option) {
                option.hidden = option.dataset.categoryId !== categoryId;
            });
        }

        if (categorySelect && parentSelect) {
            categorySelect.addEventListener('change', updateParentForums);
            updateParentForums();
        }

        var labelList = document.querySelector('[data-label-list]');
        var addLabelButton = document.querySelector('[data-add-label]');
        var labelTemplate = document.getElementById('forum-label-row-template');

        if (labelList && addLabelButton && labelTemplate) {
            addLabelButton.addEventListener('click', function() {
                labelList.appendChild(labelTemplate.content.cloneNode(true));
            });

            labelList.addEventListener('click', function(event) {
                var removeButton = event.target.closest('[data-remove-label]');

                if (!removeButton) {
                    return;
                }

                var row = removeButton.closest('.forum-label-row');

                if (row) {
                    row.remove();
                }
            });
        }

        var editCategorySelect = document.querySelector('[data-edit-forum-category-select]');
        var editParentSelect = document.querySelector('[data-edit-parent-forum-select]');

        function updateEditParentForums() {
            if (!editCategorySelect || !editParentSelect) {
                return;
            }

            var categoryId = editCategorySelect.value;
            var selectedValue = editParentSelect.value;
            var selectedStillVisible = selectedValue === '0';

            editParentSelect.querySelectorAll('option[data-category-id]').forEach(function(option) {
                option.hidden = option.dataset.categoryId !== categoryId;

                if (!option.hidden && option.value === selectedValue) {
                    selectedStillVisible = true;
                }
            });

            if (!selectedStillVisible) {
                editParentSelect.value = '0';
            }
        }

        if (editCategorySelect && editParentSelect) {
            editCategorySelect.addEventListener('change', updateEditParentForums);
            updateEditParentForums();
        }

        var editLabelList = document.querySelector('[data-edit-label-list]');
        var addEditLabelButton = document.querySelector('[data-add-edit-label]');
        var editLabelTemplate = document.getElementById('forum-edit-label-row-template');

        if (editLabelList && addEditLabelButton && editLabelTemplate) {
            addEditLabelButton.addEventListener('click', function() {
                editLabelList.appendChild(editLabelTemplate.content.cloneNode(true));
            });

            editLabelList.addEventListener('click', function(event) {
                var removeButton = event.target.closest('[data-remove-edit-label]');

                if (!removeButton) {
                    return;
                }

                var row = removeButton.closest('.forum-label-row');

                if (row) {
                    row.remove();
                }
            });
        }
    });

</script>


<script>
    document.addEventListener('DOMContentLoaded', function() {
        var actions = document.querySelector('[data-forum-admin-actions]');
        var structure = document.querySelector('[data-forum-structure-panel]');

        if (actions && structure) {
            actions.insertAdjacentElement('afterend', structure);
        }

        function closeCreatePanels() {
            document.querySelectorAll('.forum-admin-create-panel.is-open').forEach(function(panel) {
                panel.classList.remove('is-open');
                panel.hidden = true;
            });
            document.body.classList.remove('forum-admin-overlay-open');
        }

        function openCreatePanel(id) {
            var panel = document.getElementById(id);

            if (!panel) {
                return;
            }

            closeCreatePanels();
            panel.hidden = false;
            panel.classList.add('is-open');
            document.body.classList.add('forum-admin-overlay-open');

            var firstField = panel.querySelector('input:not([type="hidden"]), select, textarea');
            if (firstField) {
                window.setTimeout(function() {
                    firstField.focus();
                }, 60);
            }
        }

        document.querySelectorAll('[data-open-create-panel]').forEach(function(button) {
            button.addEventListener('click', function() {
                openCreatePanel(button.getAttribute('data-open-create-panel'));
            });
        });

        document.querySelectorAll('.forum-admin-create-panel').forEach(function(panel) {
            var titlebar = panel.querySelector('.forum-admin-titlebar');

            if (titlebar && !titlebar.querySelector('[data-close-create-panel]')) {
                var close = document.createElement('button');
                close.type = 'button';
                close.className = 'forum-admin-modal-close';
                close.setAttribute('data-close-create-panel', '');
                close.setAttribute('aria-label', 'Close');
                close.textContent = '×';
                titlebar.appendChild(close);
            }
        });

        document.addEventListener('click', function(event) {
            if (event.target.closest('[data-close-create-panel]')) {
                closeCreatePanels();
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && document.body.classList.contains(
                    'forum-admin-overlay-open')) {
                closeCreatePanels();
            }
        });

        var floatingThreadButton =
            document.querySelector(
                '[data-floating-create-thread]'
            );

        var floatingThreadPanel =
            document.querySelector(
                '[data-floating-thread-panel]'
            );

        var floatingThreadInput =
            document.querySelector(
                '[data-floating-thread-forum-id]'
            );

        var floatingThreadGo =
            document.querySelector(
                '[data-floating-thread-go]'
            );

        function closeFloatingThreadPanel() {
            if (!floatingThreadPanel) {
                return;
            }

            floatingThreadPanel.hidden = true;

            if (floatingThreadButton) {
                floatingThreadButton.setAttribute(
                    'aria-expanded',
                    'false'
                );
            }
        }

        function openFloatingThreadPanel() {
            if (!floatingThreadPanel) {
                return;
            }

            floatingThreadPanel.hidden = false;

            if (floatingThreadButton) {
                floatingThreadButton.setAttribute(
                    'aria-expanded',
                    'true'
                );
            }

            if (floatingThreadInput) {
                window.setTimeout(
                    function() {
                        floatingThreadInput.focus();
                        floatingThreadInput.select();
                    },
                    40
                );
            }
        }

        function goToFloatingThreadComposer() {
            if (!floatingThreadInput) {
                return;
            }

            var forumId =
                Number(
                    floatingThreadInput.value
                );

            if (
                !Number.isInteger(forumId) ||
                forumId <= 0
            ) {
                floatingThreadInput.focus();
                return;
            }

            window.location.href =
                <?= json_encode(url('new-thread.php')); ?> +
                '?f=' +
                encodeURIComponent(
                    String(forumId)
                );
        }

        if (floatingThreadButton) {
            floatingThreadButton.addEventListener(
                'click',
                function() {
                    if (
                        floatingThreadPanel &&
                        !floatingThreadPanel.hidden
                    ) {
                        closeFloatingThreadPanel();
                        return;
                    }

                    openFloatingThreadPanel();
                }
            );
        }

        if (floatingThreadGo) {
            floatingThreadGo.addEventListener(
                'click',
                goToFloatingThreadComposer
            );
        }

        if (floatingThreadInput) {
            floatingThreadInput.addEventListener(
                'keydown',
                function(event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        goToFloatingThreadComposer();
                    }
                }
            );
        }

        var failedCreateAction = <?= json_encode($errors !== [] ? $formAction : ''); ?>;
        if (failedCreateAction === 'create_category') {
            openCreatePanel('create-category-panel');
        } else if (failedCreateAction === 'create_forum') {
            openCreatePanel('create-forum-panel');
        }

        document.querySelectorAll('[data-confirm-delete]').forEach(function(form) {
            form.addEventListener('submit', function(event) {
                var message = form.getAttribute('data-confirm-delete') || 'Delete this item?';
                if (!window.confirm(message)) {
                    event.preventDefault();
                }
            });
        });

        var draggedItem = null;
        var draggedContainer = null;
        var dragHandleArmedItem = null;

        function directSortItems(container) {
            return Array.from(container.children).filter(function(child) {
                return child.matches('[data-forum-sort-item]');
            });
        }

        function directChildCount(item) {
            var nestedContainer = item.querySelector(':scope > [data-forum-sort-container]');
            if (!nestedContainer) {
                return 0;
            }

            return directSortItems(nestedContainer).length;
        }

        function clearForumDragState() {
            document.querySelectorAll(
                '.is-dragging, .is-drag-over, .is-drop-before, .is-drop-after, .is-valid-drop-zone, .is-invalid-drop-zone'
            ).forEach(function(row) {
                row.classList.remove('is-dragging', 'is-drag-over', 'is-drop-before', 'is-drop-after',
                    'is-valid-drop-zone', 'is-invalid-drop-zone');
            });

            if (dragHandleArmedItem) {
                dragHandleArmedItem.setAttribute('draggable', 'false');
            }

            draggedItem = null;
            draggedContainer = null;
            dragHandleArmedItem = null;
        }

        function containerCanAcceptItem(container, item) {
            if (!container || !item) {
                return false;
            }

            /* Never allow a forum to be dropped inside its own descendants. */
            if (item.contains(container)) {
                return false;
            }

            /*
             * Recursive nesting is allowed. The only invalid target is a container
             * inside the dragged forum's own descendant tree, checked above.
             */
            return true;
        }

        function refreshDropHints() {
            document.querySelectorAll('[data-forum-sort-container]').forEach(function(container) {
                var hint = container.querySelector(':scope > [data-forum-drop-hint]');
                var hasItems = directSortItems(container).length > 0;

                container.classList.toggle('is-empty', !hasItems);

                if (hint) {
                    hint.hidden = hasItems;
                }
            });
        }

        function submitForumOrder() {
            var order = [];

            document.querySelectorAll('[data-forum-sort-container]').forEach(function(sortContainer) {
                var categoryId = Number(sortContainer.getAttribute('data-category-id') || 0);
                var parentForumId = Number(sortContainer.getAttribute('data-parent-id') || 0);

                directSortItems(sortContainer).forEach(function(child, index) {
                    order.push({
                        id: Number(child.getAttribute('data-forum-id') || 0),
                        category_id: categoryId,
                        parent_forum_id: parentForumId,
                        sort_order: (index + 1) * 10
                    });
                });
            });

            var form = document.querySelector('[data-forum-reorder-form]');
            var input = document.querySelector('[data-forum-order-json]');

            if (form && input && order.length > 0) {
                input.value = JSON.stringify(order);
                form.requestSubmit();
            }
        }

        document.querySelectorAll('[data-forum-sort-item]').forEach(function(item) {
            item.setAttribute('draggable', 'false');

            var handle = item.querySelector(
                ':scope > .forum-structure-forum > .forum-structure-drag-handle, :scope > .forum-structure-drag-handle'
            );

            if (!handle) {
                return;
            }

            handle.addEventListener('pointerdown', function() {
                dragHandleArmedItem = item;
                item.setAttribute('draggable', 'true');
            });

            handle.addEventListener('pointerup', function() {
                if (!draggedItem) {
                    item.setAttribute('draggable', 'false');
                    dragHandleArmedItem = null;
                }
            });

            item.addEventListener('dragstart', function(event) {
                /* Nested forums live inside their parent forum DOM node.
                 * Stop dragstart from bubbling into ancestor forum handlers.
                 */
                event.stopPropagation();

                if (dragHandleArmedItem !== item) {
                    event.preventDefault();
                    return;
                }

                draggedItem = item;
                draggedContainer = item.parentElement;
                item.classList.add('is-dragging');

                document.querySelectorAll('[data-forum-sort-container]').forEach(function(
                    container) {
                    container.classList.add(
                        containerCanAcceptItem(container, item) ?
                        'is-valid-drop-zone' :
                        'is-invalid-drop-zone'
                    );
                });

                if (event.dataTransfer) {
                    event.dataTransfer.effectAllowed = 'move';
                    event.dataTransfer.setData('text/plain', item.getAttribute(
                            'data-forum-id') ||
                        '');
                }
            });

            item.addEventListener('dragend', function(event) {
                event.stopPropagation();
                clearForumDragState();
                refreshDropHints();
            });
        });

        document.querySelectorAll('[data-forum-sort-container]').forEach(function(container) {
            container.addEventListener('dragover', function(event) {
                if (!draggedItem || !containerCanAcceptItem(container, draggedItem)) {
                    return;
                }

                /* Use the innermost valid container only. This prevents a nested
                 * forum drop zone from also triggering its ancestor containers.
                 */
                event.stopPropagation();
                event.preventDefault();

                if (event.dataTransfer) {
                    event.dataTransfer.dropEffect = 'move';
                }

                var candidates = directSortItems(container).filter(function(item) {
                    return item !== draggedItem;
                });

                document.querySelectorAll('.is-drop-before, .is-drop-after, .is-drag-over')
                    .forEach(
                        function(row) {
                            row.classList.remove('is-drop-before', 'is-drop-after',
                                'is-drag-over');
                        });

                container.classList.add('is-drag-over');

                if (candidates.length === 0) {
                    return;
                }

                var target = null;
                var placeAfter = false;

                for (var i = 0; i < candidates.length; i += 1) {
                    var box = candidates[i].getBoundingClientRect();
                    var midpoint = box.top + (box.height / 2);

                    if (event.clientY < midpoint) {
                        target = candidates[i];
                        placeAfter = false;
                        break;
                    }
                }

                if (!target) {
                    target = candidates[candidates.length - 1];
                    placeAfter = true;
                }

                target.classList.add(placeAfter ? 'is-drop-after' : 'is-drop-before');
            });

            container.addEventListener('dragleave', function(event) {
                if (event.relatedTarget && container.contains(event.relatedTarget)) {
                    return;
                }
                container.classList.remove('is-drag-over');
            });

            container.addEventListener('drop', function(event) {
                if (!draggedItem || !containerCanAcceptItem(container, draggedItem)) {
                    return;
                }

                event.stopPropagation();
                event.preventDefault();

                var beforeTarget = container.querySelector(':scope > .is-drop-before');
                var afterTarget = container.querySelector(':scope > .is-drop-after');

                if (beforeTarget && beforeTarget !== draggedItem) {
                    container.insertBefore(draggedItem, beforeTarget);
                } else if (afterTarget && afterTarget !== draggedItem) {
                    container.insertBefore(draggedItem, afterTarget.nextSibling);
                } else {
                    container.appendChild(draggedItem);
                }

                /*
                 * If a forum tree moved categories, every descendant container inherits
                 * the new category. The server validates and enforces the same rule.
                 */
                var targetCategoryId =
                    container.getAttribute('data-category-id') ||
                    '0';

                draggedItem
                    .querySelectorAll('[data-forum-sort-container]')
                    .forEach(function(nestedContainer) {
                        nestedContainer.setAttribute(
                            'data-category-id',
                            targetCategoryId
                        );
                    });

                clearForumDragState();
                refreshDropHints();
                submitForumOrder();
            });
        });

        refreshDropHints();

        document.querySelectorAll('[data-image-map-builder]').forEach(function(builder) {
            var enabled = builder.querySelector('[data-image-map-enabled]');
            var settings = builder.querySelector('[data-image-map-settings]');
            var fileInput = builder.querySelector('[data-image-map-file]');
            var savedSelect = builder.querySelector('[data-saved-map-select]');
            var mapNameInput = builder.querySelector('[data-map-name]');
            var altInput = builder.querySelector('[data-map-alt]');
            var sharedWarning = builder.querySelector('[data-shared-map-warning]');
            var image = builder.querySelector('[data-map-image]');
            var svg = builder.querySelector('[data-map-svg]');
            var placeholder = builder.querySelector('[data-map-placeholder]');
            var jsonInput = builder.querySelector('[data-image-map-json]');
            var list = builder.querySelector('[data-map-area-list]');
            var count = builder.querySelector('[data-map-count]');
            var help = builder.querySelector('[data-map-tool-help]');
            var clearButton = builder.querySelector('[data-map-clear]');
            var cancelButton = builder.querySelector('[data-map-cancel-tool]');
            var tools = builder.querySelectorAll('[data-map-tool]');
            var forumOptions = [];
            var savedMaps = [];
            var areas = [];
            var activeTool = null;
            var startPoint = null;
            var draftShape = null;
            var polygonPoints = [];
            var width = Number(builder.getAttribute('data-natural-width') || 0);
            var height = Number(builder.getAttribute('data-natural-height') || 0);

            try {
                forumOptions = JSON.parse(builder.getAttribute('data-forum-options') || '[]');
            } catch (error) {}
            try {
                savedMaps = JSON.parse(builder.getAttribute('data-saved-maps') || '[]');
            } catch (error) {}
            try {
                areas = JSON.parse(builder.getAttribute('data-existing-areas') || '[]');
            } catch (error) {}
            if (!Array.isArray(savedMaps)) {
                savedMaps = [];
            }
            if (!Array.isArray(areas)) {
                areas = [];
            }

            function syncEnabled() {
                settings.hidden = !enabled.checked;
            }

            function pointFromEvent(event) {
                var rect = svg.getBoundingClientRect();
                if (!rect.width || !rect.height || !width || !height) {
                    return null;
                }
                return {
                    x: Math.max(0, Math.min(width, (event.clientX - rect.left) * width / rect.width)),
                    y: Math.max(0, Math.min(height, (event.clientY - rect.top) * height / rect.height))
                };
            }

            function makeSvgElement(name, attributes) {
                var element = document.createElementNS('http://www.w3.org/2000/svg', name);
                Object.keys(attributes).forEach(function(key) {
                    element.setAttribute(key, String(attributes[key]));
                });
                return element;
            }

            function appendShape(area, index, draft) {
                var coords = area.coords || [];
                var shape = null;

                if (area.shape === 'rect' && coords.length >= 4) {
                    var x1 = Math.min(coords[0], coords[2]);
                    var y1 = Math.min(coords[1], coords[3]);
                    shape = makeSvgElement('rect', {
                        x: x1,
                        y: y1,
                        width: Math.abs(coords[2] - coords[0]),
                        height: Math.abs(coords[3] - coords[1])
                    });
                } else if (area.shape === 'circle' && coords.length >= 3) {
                    shape = makeSvgElement('circle', {
                        cx: coords[0],
                        cy: coords[1],
                        r: Math.abs(coords[2])
                    });
                } else if (area.shape === 'poly' && coords.length >= 6) {
                    var points = [];
                    for (var i = 0; i < coords.length; i += 2) {
                        points.push(coords[i] + ',' + coords[i + 1]);
                    }
                    shape = makeSvgElement('polygon', {
                        points: points.join(' ')
                    });
                }

                if (!shape) {
                    return;
                }
                shape.setAttribute('class', draft ? 'forum-map-shape is-draft' : 'forum-map-shape');
                if (!draft) {
                    shape.setAttribute('data-map-shape-index', String(index));
                }
                svg.appendChild(shape);
            }

            function serialize() {
                jsonInput.value = JSON.stringify(areas);
                count.textContent = areas.length + (areas.length === 1 ? ' area' : ' areas');
            }

            function buildForumSelect(area, index) {
                var select = document.createElement('select');
                select.className = 'form-control';
                select.setAttribute('data-area-field', 'target_forum_id');
                select.setAttribute('data-index', index);
                var blank = document.createElement('option');
                blank.value = '0';
                blank.textContent = 'Choose a forum / sub-forum';
                select.appendChild(blank);
                forumOptions.forEach(function(option) {
                    var opt = document.createElement('option');
                    opt.value = option.id;
                    opt.textContent = option.parent_forum_id ? '↳ ' + option.title : option
                        .title;
                    if (Number(area.target_forum_id || 0) === Number(option.id)) {
                        opt.selected = true;
                    }
                    select.appendChild(opt);
                });
                return select;
            }

            function renderList() {
                list.innerHTML = '';
                areas.forEach(function(area, index) {
                    var row = document.createElement('div');
                    row.className = 'forum-image-map-area-row';
                    row.setAttribute('data-area-index', index);

                    var heading = document.createElement('div');
                    heading.className = 'forum-image-map-area-row-heading';
                    heading.innerHTML = '<strong>Area ' + (index + 1) + '</strong><span>' + area
                        .shape + '</span>';
                    var remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'forum-label-remove';
                    remove.setAttribute('data-remove-map-area', String(index));
                    remove.setAttribute('aria-label', 'Remove clickable area');
                    remove.textContent = '×';
                    heading.appendChild(remove);
                    row.appendChild(heading);

                    var grid = document.createElement('div');
                    grid.className = 'forum-image-map-area-grid';

                    var linkType = document.createElement('select');
                    linkType.className = 'form-control';
                    linkType.setAttribute('data-area-field', 'link_type');
                    linkType.setAttribute('data-index', index);
                    linkType.innerHTML =
                        '<option value="forum">Forum / Sub-forum</option><option value="url">Custom URL</option>';
                    linkType.value = area.link_type || 'url';
                    grid.appendChild(linkType);

                    var forumSelect = buildForumSelect(area, index);
                    forumSelect.hidden = linkType.value !== 'forum';
                    forumSelect.setAttribute('data-forum-target-select', '');
                    grid.appendChild(forumSelect);

                    var urlInput = document.createElement('input');
                    urlInput.className = 'form-control';
                    urlInput.type = 'text';
                    urlInput.placeholder = '/forum.php?f=14 or https://...';
                    urlInput.value = area.link_url || '';
                    urlInput.setAttribute('data-area-field', 'link_url');
                    urlInput.setAttribute('data-index', index);
                    urlInput.setAttribute('data-url-target-input', '');
                    urlInput.hidden = linkType.value !== 'url';
                    grid.appendChild(urlInput);

                    var titleInput = document.createElement('input');
                    titleInput.className = 'form-control';
                    titleInput.type = 'text';
                    titleInput.placeholder = 'Link title';
                    titleInput.value = area.title || '';
                    titleInput.setAttribute('data-area-field', 'title');
                    titleInput.setAttribute('data-index', index);
                    grid.appendChild(titleInput);

                    var altInput = document.createElement('input');
                    altInput.className = 'form-control';
                    altInput.type = 'text';
                    altInput.placeholder = 'Accessible description';
                    altInput.value = area.alt_text || '';
                    altInput.setAttribute('data-area-field', 'alt_text');
                    altInput.setAttribute('data-index', index);
                    grid.appendChild(altInput);

                    var target = document.createElement('select');
                    target.className = 'form-control';
                    target.setAttribute('data-area-field', 'target');
                    target.setAttribute('data-index', index);
                    target.innerHTML =
                        '<option value="_self">Open normally</option><option value="_blank">Open in new tab</option>';
                    target.value = area.target || '_self';
                    grid.appendChild(target);

                    row.appendChild(grid);
                    list.appendChild(row);
                });
            }

            function render() {
                while (svg.firstChild) {
                    svg.removeChild(svg.firstChild);
                }
                areas.forEach(function(area, index) {
                    appendShape(area, index, false);
                });
                if (draftShape) {
                    appendShape(draftShape, -1, true);
                }
                renderList();
                serialize();
            }

            function loadSavedMap(imageMapId) {
                var id = Number(imageMapId || 0);
                sharedWarning.hidden = id <= 0;

                if (id <= 0) {
                    mapNameInput.value = '';
                    altInput.value = '';
                    areas = [];
                    width = 0;
                    height = 0;
                    image.src = '';
                    image.hidden = true;
                    placeholder.hidden = false;
                    svg.removeAttribute('viewBox');
                    fileInput.value = '';
                    setTool(null);
                    return;
                }

                var selected = savedMaps.find(function(item) {
                    return Number(item.id || 0) === id;
                });

                if (!selected) {
                    return;
                }

                mapNameInput.value = selected.name || '';
                altInput.value = selected.image_alt || '';
                areas = JSON.parse(JSON.stringify(selected.areas || []));
                width = Number(selected.original_width || 0);
                height = Number(selected.original_height || 0);
                fileInput.value = '';

                if (width > 0 && height > 0) {
                    svg.setAttribute('viewBox', '0 0 ' + width + ' ' + height);
                }

                var sourceUrl = selected.webp_url || selected.image_url || '';
                if (sourceUrl) {
                    image.onload = function() {
                        image.hidden = false;
                        placeholder.hidden = true;
                        render();
                    };
                    image.src = sourceUrl;
                    image.hidden = false;
                    placeholder.hidden = true;
                } else {
                    image.src = '';
                    image.hidden = true;
                    placeholder.hidden = false;
                }

                setTool(null);
            }

            function setTool(tool) {
                activeTool = tool;
                startPoint = null;
                draftShape = null;
                polygonPoints = [];
                tools.forEach(function(button) {
                    button.classList.toggle('is-active', button.getAttribute(
                            'data-map-tool') ===
                        tool);
                });
                if (!tool) {
                    help.textContent =
                        'Upload or keep an image, choose a shape tool, then draw directly on the image.';
                } else if (tool === 'rect') {
                    help.textContent =
                        'Rectangle: click and drag from one corner to the opposite corner.';
                } else if (tool === 'circle') {
                    help.textContent = 'Circle: click the center, then drag outward to set the radius.';
                } else {
                    help.textContent =
                        'Polygon: click each corner. Double-click the final point to finish.';
                }
                render();
            }

            enabled.addEventListener('change', syncEnabled);
            if (savedSelect) {
                savedSelect.addEventListener('change', function() {
                    loadSavedMap(savedSelect.value);
                });
            }
            syncEnabled();

            tools.forEach(function(button) {
                button.addEventListener('click', function() {
                    if (!width || !height || image.hidden) {
                        window.alert(
                            'Upload or load an image before drawing clickable areas.'
                        );
                        return;
                    }
                    setTool(button.getAttribute('data-map-tool'));
                });
            });

            cancelButton.addEventListener('click', function() {
                setTool(null);
            });
            clearButton.addEventListener('click', function() {
                if (areas.length && !window.confirm(
                        'Remove every clickable area from this image map?')) {
                    return;
                }
                areas = [];
                setTool(null);
            });

            fileInput.addEventListener('change', function() {
                var file = fileInput.files && fileInput.files[0];
                if (!file) {
                    return;
                }
                if (areas.length) {
                    areas = [];
                }
                builder.querySelectorAll('picture source').forEach(function(source) {
                    source.remove();
                });
                var objectUrl = URL.createObjectURL(file);
                image.onload = function() {
                    width = image.naturalWidth;
                    height = image.naturalHeight;
                    svg.setAttribute('viewBox', '0 0 ' + width + ' ' + height);
                    image.hidden = false;
                    placeholder.hidden = true;
                    URL.revokeObjectURL(objectUrl);
                    setTool(null);
                };
                image.src = objectUrl;
            });

            svg.addEventListener('pointerdown', function(event) {
                if (activeTool !== 'rect' && activeTool !== 'circle') {
                    return;
                }
                var point = pointFromEvent(event);
                if (!point) {
                    return;
                }
                event.preventDefault();
                startPoint = point;
                svg.setPointerCapture(event.pointerId);
            });

            svg.addEventListener('pointermove', function(event) {
                if (!startPoint || (activeTool !== 'rect' && activeTool !== 'circle')) {
                    return;
                }
                var point = pointFromEvent(event);
                if (!point) {
                    return;
                }
                if (activeTool === 'rect') {
                    draftShape = {
                        shape: 'rect',
                        coords: [startPoint.x, startPoint.y, point.x, point.y]
                    };
                } else {
                    var dx = point.x - startPoint.x;
                    var dy = point.y - startPoint.y;
                    draftShape = {
                        shape: 'circle',
                        coords: [startPoint.x, startPoint.y, Math.sqrt(dx * dx + dy * dy)]
                    };
                }
                render();
            });

            svg.addEventListener('pointerup', function(event) {
                if (!startPoint || !draftShape || (activeTool !== 'rect' && activeTool !==
                        'circle')) {
                    return;
                }
                var coords = draftShape.coords;
                var valid = activeTool === 'rect' ?
                    Math.abs(coords[2] - coords[0]) > 4 && Math.abs(coords[3] - coords[1]) > 4 :
                    coords[2] > 4;
                if (valid) {
                    draftShape.link_type = 'url';
                    draftShape.target_forum_id = 0;
                    draftShape.link_url = '';
                    draftShape.title = '';
                    draftShape.alt_text = '';
                    draftShape.target = '_self';
                    areas.push(draftShape);
                }
                startPoint = null;
                draftShape = null;
                setTool(null);
            });

            svg.addEventListener('click', function(event) {
                if (activeTool !== 'poly') {
                    return;
                }
                var point = pointFromEvent(event);
                if (!point) {
                    return;
                }
                polygonPoints.push(point.x, point.y);
                draftShape = {
                    shape: 'poly',
                    coords: polygonPoints.slice()
                };

                if (event.detail >= 2) {
                    if (polygonPoints.length >= 8) {
                        polygonPoints.splice(-2, 2);
                        areas.push({
                            shape: 'poly',
                            coords: polygonPoints.slice(),
                            link_type: 'url',
                            target_forum_id: 0,
                            link_url: '',
                            title: '',
                            alt_text: '',
                            target: '_self'
                        });
                    }
                    setTool(null);
                    return;
                }
                render();
            });

            list.addEventListener('click', function(event) {
                var button = event.target.closest('[data-remove-map-area]');
                if (!button) {
                    return;
                }
                var index = Number(button.getAttribute('data-remove-map-area'));
                areas.splice(index, 1);
                render();
            });

            function updateAreaField(field) {
                var index = Number(field.getAttribute('data-index'));
                var key = field.getAttribute('data-area-field');
                if (!areas[index] || !key) {
                    return;
                }
                areas[index][key] = key === 'target_forum_id' ? Number(field.value || 0) : field.value;

                if (key === 'link_type') {
                    var row = field.closest('.forum-image-map-area-row');
                    var forumSelect = row.querySelector('[data-forum-target-select]');
                    var urlInput = row.querySelector('[data-url-target-input]');
                    forumSelect.hidden = field.value !== 'forum';
                    urlInput.hidden = field.value !== 'url';
                }
                serialize();
            }

            list.addEventListener('input', function(event) {
                var field = event.target.closest('[data-area-field]');
                if (field) {
                    updateAreaField(field);
                }
            });
            list.addEventListener('change', function(event) {
                var field = event.target.closest('[data-area-field]');
                if (field) {
                    updateAreaField(field);
                }
            });

            if (width > 0 && height > 0) {
                svg.setAttribute('viewBox', '0 0 ' + width + ' ' + height);
            }
            render();
        });
    });

</script>

<?php require INCLUDES_PATH . '/footer.php'; ?>
