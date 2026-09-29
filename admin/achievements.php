<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/achievement-functions.php';


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

$canManageAchievements =
    $isProtectedSuperAdmin
    || user_can('achievements.manage');

if (
    !$hasStaffIdentity
    || !$canManageAchievements
) {
    http_response_code(403);

    $pageTitle = 'Access Denied | Blackthorne Academy';
    $pageDescription = 'You do not have permission to manage achievements.';
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
                Academy achievements.
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

function admin_achievements_post_string(string $key): string
{
    $value = $_POST[$key] ?? '';

    return is_scalar($value)
        ? trim((string) $value)
        : '';
}


function admin_achievements_post_id(string $key): int
{
    $value = $_POST[$key] ?? '';

    if (
        !is_scalar($value)
        || !ctype_digit((string) $value)
    ) {
        return 0;
    }

    return max(0, (int) $value);
}


function admin_achievements_post_bool(string $key): int
{
    return isset($_POST[$key])
        ? 1
        : 0;
}


function admin_achievements_text_length(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);
}


function admin_achievements_slug_base(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    if (function_exists('iconv')) {
        $converted = @iconv(
            'UTF-8',
            'ASCII//TRANSLIT//IGNORE',
            $value
        );

        if (is_string($converted) && $converted !== '') {
            $value = $converted;
        }
    }

    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';

    return trim($value, '-');
}


function admin_achievements_unique_slug(
    PDO $pdo,
    string $name,
    string $requestedSlug = '',
    int $excludeId = 0
): string {
    $base = admin_achievements_slug_base(
        $requestedSlug !== ''
            ? $requestedSlug
            : $name
    );

    if ($base === '') {
        $base = 'achievement-' . bin2hex(random_bytes(4));
    }

    $base = substr($base, 0, 150);
    $candidate = $base;
    $suffix = 2;

    while (true) {
        $sql =
            'SELECT id
             FROM achievements
             WHERE slug = :slug';

        if ($excludeId > 0) {
            $sql .= ' AND id <> :exclude_id';
        }

        $sql .= ' LIMIT 1';

        $statement = $pdo->prepare($sql);
        $params = ['slug' => $candidate];

        if ($excludeId > 0) {
            $params['exclude_id'] = $excludeId;
        }

        $statement->execute($params);

        if ($statement->fetchColumn() === false) {
            return $candidate;
        }

        $suffixText = '-' . $suffix;
        $candidate =
            substr(
                $base,
                0,
                max(1, 160 - strlen($suffixText))
            )
            . $suffixText;

        $suffix++;
    }
}


function admin_achievements_normalize_decimal(
    string $value,
    bool $allowBlank = true
): ?string {
    $value = trim($value);

    if ($value === '') {
        return $allowBlank
            ? null
            : '0.00';
    }

    if (!is_numeric($value)) {
        throw new InvalidArgumentException(
            'Threshold must be a valid number.'
        );
    }

    return number_format((float) $value, 2, '.', '');
}


function admin_achievements_normalize_unsigned_int(
    string $value,
    string $label,
    bool $allowBlank = true
): ?int {
    $value = trim($value);

    if ($value === '') {
        return $allowBlank
            ? null
            : 0;
    }

    if (!ctype_digit($value)) {
        throw new InvalidArgumentException(
            $label . ' must be zero or higher.'
        );
    }

    return (int) $value;
}


function admin_achievements_normalize_json(string $value): ?string
{
    $value = trim($value);

    if ($value === '') {
        return null;
    }

    try {
        $decoded = json_decode(
            $value,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException $exception) {
        throw new InvalidArgumentException(
            'Trigger Configuration must contain valid JSON.'
        );
    }

    if (!is_array($decoded)) {
        throw new InvalidArgumentException(
            'Trigger Configuration must be a JSON object or array.'
        );
    }

    return json_encode(
        $decoded,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ) ?: null;
}


function admin_achievements_image_url(?string $path): string
{
    $path = trim((string) $path);

    if ($path === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $path) === 1) {
        return $path;
    }

    return url(ltrim($path, '/'));
}


function admin_achievements_store_badge_upload(
    array $file,
    string $slug
): string {
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new InvalidArgumentException(
            'No badge image was selected.'
        );
    }

    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException(
            'The badge image could not be uploaded.'
        );
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);

    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new InvalidArgumentException(
            'The selected badge image was not received as a valid upload.'
        );
    }

    if ($size <= 0 || $size > 5 * 1024 * 1024) {
        throw new InvalidArgumentException(
            'Badge images must be 5 MB or smaller.'
        );
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string) $finfo->file($tmpName);

    $extensions = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    if (!isset($extensions[$mimeType])) {
        throw new InvalidArgumentException(
            'Badge images must be PNG, JPG, WebP, or GIF.'
        );
    }

    $safeSlug = admin_achievements_slug_base($slug);

    if ($safeSlug === '') {
        $safeSlug = 'achievement';
    }

    $directory = UPLOADS_PATH . '/achievements';

    if (
        !is_dir($directory)
        && !mkdir($directory, 0755, true)
        && !is_dir($directory)
    ) {
        throw new RuntimeException(
            'The achievement badge upload directory could not be created.'
        );
    }

    $filename =
        substr($safeSlug, 0, 100)
        . '-'
        . bin2hex(random_bytes(8))
        . '.'
        . $extensions[$mimeType];

    $absolutePath = $directory . '/' . $filename;

    if (!move_uploaded_file($tmpName, $absolutePath)) {
        throw new RuntimeException(
            'The badge image could not be saved.'
        );
    }

    return 'uploads/achievements/' . $filename;
}


function admin_achievements_delete_owned_badge(?string $path): void
{
    $path = trim((string) $path);

    if (
        $path === ''
        || !str_starts_with($path, 'uploads/achievements/')
    ) {
        return;
    }

    $relative = ltrim($path, '/');
    $absolute = BASE_PATH . '/' . $relative;
    $realBase = realpath(UPLOADS_PATH . '/achievements');
    $realFile = realpath($absolute);

    if (
        $realBase === false
        || $realFile === false
        || !str_starts_with(
            $realFile,
            $realBase . DIRECTORY_SEPARATOR
        )
    ) {
        return;
    }

    if (is_file($realFile)) {
        @unlink($realFile);
    }
}


function admin_achievements_audit(
    PDO $pdo,
    int $actorUserId,
    string $actionType,
    int $achievementId,
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
            'action_type' => $actionType,
            'entity_type' => 'achievement',
            'entity_id' => $achievementId,
            'description' => $description,
            'ip_address' => substr(
                (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                0,
                45
            ) ?: null,
            'user_agent' => substr(
                (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                0,
                500
            ) ?: null,
        ]);
    } catch (Throwable $exception) {
        error_log(
            'Achievement audit error: '
            . $exception->getMessage()
        );
    }
}


function admin_achievements_form_defaults(): array
{
    return [
        'name' => '',
        'slug' => '',
        'description' => '',
        'badge_image' => '',
        'requirement_type' => 'manual',
        'requirement_value' => '',
        'threshold_value' => '',
        'target_type' => '',
        'target_id' => '',
        'points_awarded' => '0',
        'repeat_mode' => 'once_ever',
        'trigger_config' => '',
        'sort_order' => '0',
        'is_active' => '1',
    ];
}


function admin_achievements_form_from_row(array $row): array
{
    $triggerConfig = trim((string) ($row['trigger_config'] ?? ''));

    if ($triggerConfig !== '') {
        $decoded = json_decode($triggerConfig, true);

        if (is_array($decoded)) {
            $pretty = json_encode(
                $decoded,
                JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
            );

            if (is_string($pretty)) {
                $triggerConfig = $pretty;
            }
        }
    }

    return [
        'name' => (string) ($row['name'] ?? ''),
        'slug' => (string) ($row['slug'] ?? ''),
        'description' => (string) ($row['description'] ?? ''),
        'badge_image' => (string) ($row['badge_image'] ?? ''),
        'requirement_type' => (string) ($row['requirement_type'] ?? 'manual'),
        'requirement_value' => (string) ($row['requirement_value'] ?? ''),
        'threshold_value' =>
            $row['threshold_value'] === null
                ? ''
                : (string) $row['threshold_value'],
        'target_type' => (string) ($row['target_type'] ?? ''),
        'target_id' =>
            $row['target_id'] === null
                ? ''
                : (string) $row['target_id'],
        'points_awarded' => (string) ($row['points_awarded'] ?? '0'),
        'repeat_mode' => (string) ($row['repeat_mode'] ?? 'once_ever'),
        'trigger_config' => $triggerConfig,
        'sort_order' => (string) ($row['sort_order'] ?? '0'),
        'is_active' => (string) ((int) ($row['is_active'] ?? 0)),
    ];
}


/*
|--------------------------------------------------------------------------
| Form State
|--------------------------------------------------------------------------
*/

$errors = [];
$createForm = admin_achievements_form_defaults();
$editForm = admin_achievements_form_defaults();
$editAchievement = null;

$editAchievementId = filter_input(
    INPUT_GET,
    'edit',
    FILTER_VALIDATE_INT
);

if (
    !is_int($editAchievementId)
    || $editAchievementId <= 0
) {
    $editAchievementId = 0;
}


/*
|--------------------------------------------------------------------------
| POST Actions
|--------------------------------------------------------------------------
*/

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (
        !verify_csrf_token(
            $_POST['_csrf_token'] ?? null
        )
    ) {
        $errors[] =
            'Your form session expired. Refresh the page and try again.';
    }

    $action = admin_achievements_post_string('action');

    if (
        $errors === []
        && in_array(
            $action,
            ['create_achievement', 'update_achievement'],
            true
        )
    ) {
        $isUpdate = $action === 'update_achievement';
        $achievementId = $isUpdate
            ? admin_achievements_post_id('achievement_id')
            : 0;

        $form = [
            'name' => admin_achievements_post_string('name'),
            'slug' => admin_achievements_post_string('slug'),
            'description' => admin_achievements_post_string('description'),
            'badge_image' => admin_achievements_post_string('badge_image'),
            'requirement_type' => admin_achievements_post_string('requirement_type'),
            'requirement_value' => admin_achievements_post_string('requirement_value'),
            'threshold_value' => admin_achievements_post_string('threshold_value'),
            'target_type' => admin_achievements_post_string('target_type'),
            'target_id' => admin_achievements_post_string('target_id'),
            'points_awarded' => admin_achievements_post_string('points_awarded'),
            'repeat_mode' => admin_achievements_post_string('repeat_mode'),
            'trigger_config' => admin_achievements_post_string('trigger_config'),
            'sort_order' => admin_achievements_post_string('sort_order'),
            'is_active' => (string) admin_achievements_post_bool('is_active'),
        ];

        if ($isUpdate) {
            $editForm = $form;
            $editAchievementId = $achievementId;
        } else {
            $createForm = $form;
        }

        if ($form['name'] === '') {
            $errors[] = 'Achievement Name is required.';
        } elseif (admin_achievements_text_length($form['name']) > 150) {
            $errors[] = 'Achievement Name must be 150 characters or fewer.';
        }

        if (
            $form['slug'] !== ''
            && admin_achievements_text_length($form['slug']) > 160
        ) {
            $errors[] = 'Slug must be 160 characters or fewer.';
        }

        try {
            $triggerType = achievements_normalize_trigger_type(
                $form['requirement_type']
            );
        } catch (Throwable $exception) {
            $triggerType = '';
            $errors[] = $exception->getMessage();
        }

        if (
            $form['requirement_value'] !== ''
            && admin_achievements_text_length($form['requirement_value']) > 255
        ) {
            $errors[] = 'Requirement Value must be 255 characters or fewer.';
        }

        if (
            $form['target_type'] !== ''
            && admin_achievements_text_length($form['target_type']) > 50
        ) {
            $errors[] = 'Target Type must be 50 characters or fewer.';
        }

        if (
            $form['badge_image'] !== ''
            && admin_achievements_text_length($form['badge_image']) > 255
        ) {
            $errors[] = 'Badge Image path must be 255 characters or fewer.';
        }

        $thresholdValue = null;
        $targetId = null;
        $pointsAwarded = 0;
        $sortOrder = 0;
        $triggerConfig = null;

        try {
            $thresholdValue = admin_achievements_normalize_decimal(
                $form['threshold_value']
            );

            $targetId = admin_achievements_normalize_unsigned_int(
                $form['target_id'],
                'Target ID'
            );

            $pointsAwardedValue = admin_achievements_normalize_unsigned_int(
                $form['points_awarded'],
                'House Points',
                false
            );
            $pointsAwarded = (int) ($pointsAwardedValue ?? 0);

            $sortOrderValue = admin_achievements_normalize_unsigned_int(
                $form['sort_order'],
                'Sort Order',
                false
            );
            $sortOrder = (int) ($sortOrderValue ?? 0);

            $triggerConfig = admin_achievements_normalize_json(
                $form['trigger_config']
            );
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }

        $repeatMode = strtolower(trim($form['repeat_mode']));

        if (!in_array(
            $repeatMode,
            achievements_supported_repeat_modes(),
            true
        )) {
            $errors[] = 'Choose a supported Repeat Mode.';
        }

        if (
            $repeatMode === 'once_per_target'
            && trim($form['target_type']) === ''
        ) {
            $errors[] =
                'Once Per Target achievements require a Target Type.';
        }

        $existingRow = null;

        if ($isUpdate) {
            if ($achievementId <= 0) {
                $errors[] = 'The achievement to update is invalid.';
            } else {
                $existingRow = achievements_definition_by_id(
                    $pdo,
                    $achievementId,
                    false
                );

                if ($existingRow === null) {
                    $errors[] = 'The achievement could not be found.';
                }
            }
        }

        $newBadgePath = null;
        $badgeUploadPresent =
            isset($_FILES['badge_upload'])
            && is_array($_FILES['badge_upload'])
            && (int) ($_FILES['badge_upload']['error'] ?? UPLOAD_ERR_NO_FILE)
                !== UPLOAD_ERR_NO_FILE;

        if ($errors === [] && $badgeUploadPresent) {
            try {
                $slugForUpload =
                    admin_achievements_slug_base(
                        $form['slug'] !== ''
                            ? $form['slug']
                            : $form['name']
                    );

                $newBadgePath = admin_achievements_store_badge_upload(
                    $_FILES['badge_upload'],
                    $slugForUpload
                );

                $form['badge_image'] = $newBadgePath;
            } catch (Throwable $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        if ($isUpdate) {
            $editForm = $form;
        } else {
            $createForm = $form;
        }

        if ($errors === []) {
            try {
                $pdo->beginTransaction();

                $slug = admin_achievements_unique_slug(
                    $pdo,
                    $form['name'],
                    $form['slug'],
                    $achievementId
                );

                if ($isUpdate) {
                    $updateStatement = $pdo->prepare(
                        'UPDATE achievements
                         SET name = :name,
                             slug = :slug,
                             description = :description,
                             badge_image = :badge_image,
                             requirement_type = :requirement_type,
                             requirement_value = :requirement_value,
                             threshold_value = :threshold_value,
                             target_type = :target_type,
                             target_id = :target_id,
                             points_awarded = :points_awarded,
                             repeat_mode = :repeat_mode,
                             trigger_config = :trigger_config,
                             is_active = :is_active,
                             sort_order = :sort_order
                         WHERE id = :achievement_id'
                    );

                    $updateStatement->execute([
                        'name' => $form['name'],
                        'slug' => $slug,
                        'description' => $form['description'] !== ''
                            ? $form['description']
                            : null,
                        'badge_image' => $form['badge_image'] !== ''
                            ? $form['badge_image']
                            : null,
                        'requirement_type' => $triggerType,
                        'requirement_value' => $form['requirement_value'] !== ''
                            ? $form['requirement_value']
                            : null,
                        'threshold_value' => $thresholdValue,
                        'target_type' => $form['target_type'] !== ''
                            ? strtolower($form['target_type'])
                            : null,
                        'target_id' => $targetId,
                        'points_awarded' => $pointsAwarded,
                        'repeat_mode' => $repeatMode,
                        'trigger_config' => $triggerConfig,
                        'is_active' => (int) $form['is_active'],
                        'sort_order' => $sortOrder,
                        'achievement_id' => $achievementId,
                    ]);

                    admin_achievements_audit(
                        $pdo,
                        $currentUserId,
                        'achievement.update',
                        $achievementId,
                        'Updated achievement: ' . $form['name']
                    );
                } else {
                    $insertStatement = $pdo->prepare(
                        'INSERT INTO achievements (
                            name,
                            slug,
                            description,
                            badge_image,
                            requirement_type,
                            requirement_value,
                            threshold_value,
                            target_type,
                            target_id,
                            points_awarded,
                            repeat_mode,
                            trigger_config,
                            is_active,
                            sort_order
                         ) VALUES (
                            :name,
                            :slug,
                            :description,
                            :badge_image,
                            :requirement_type,
                            :requirement_value,
                            :threshold_value,
                            :target_type,
                            :target_id,
                            :points_awarded,
                            :repeat_mode,
                            :trigger_config,
                            :is_active,
                            :sort_order
                         )'
                    );

                    $insertStatement->execute([
                        'name' => $form['name'],
                        'slug' => $slug,
                        'description' => $form['description'] !== ''
                            ? $form['description']
                            : null,
                        'badge_image' => $form['badge_image'] !== ''
                            ? $form['badge_image']
                            : null,
                        'requirement_type' => $triggerType,
                        'requirement_value' => $form['requirement_value'] !== ''
                            ? $form['requirement_value']
                            : null,
                        'threshold_value' => $thresholdValue,
                        'target_type' => $form['target_type'] !== ''
                            ? strtolower($form['target_type'])
                            : null,
                        'target_id' => $targetId,
                        'points_awarded' => $pointsAwarded,
                        'repeat_mode' => $repeatMode,
                        'trigger_config' => $triggerConfig,
                        'is_active' => (int) $form['is_active'],
                        'sort_order' => $sortOrder,
                    ]);

                    $achievementId = (int) $pdo->lastInsertId();

                    admin_achievements_audit(
                        $pdo,
                        $currentUserId,
                        'achievement.create',
                        $achievementId,
                        'Created achievement: ' . $form['name']
                    );
                }

                $pdo->commit();

                if (
                    $isUpdate
                    && $newBadgePath !== null
                    && is_array($existingRow)
                ) {
                    $oldBadgePath = trim(
                        (string) ($existingRow['badge_image'] ?? '')
                    );

                    if (
                        $oldBadgePath !== ''
                        && $oldBadgePath !== $newBadgePath
                    ) {
                        admin_achievements_delete_owned_badge(
                            $oldBadgePath
                        );
                    }
                }

                set_flash(
                    'success',
                    $isUpdate
                        ? 'Achievement updated successfully.'
                        : 'Achievement created successfully.'
                );

                redirect(
                    url(
                        'admin/achievements.php?edit='
                        . $achievementId
                    )
                );
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if ($newBadgePath !== null) {
                    admin_achievements_delete_owned_badge(
                        $newBadgePath
                    );
                }

                error_log(
                    'Achievement save error: '
                    . $exception->getMessage()
                );

                $errors[] =
                    'The achievement could not be saved.';
            }
        }
    }

    if (
        $errors === []
        && $action === 'toggle_achievement'
    ) {
        $achievementId = admin_achievements_post_id('achievement_id');
        $desiredState = admin_achievements_post_string('desired_state');

        if (
            $achievementId <= 0
            || !in_array($desiredState, ['0', '1'], true)
        ) {
            $errors[] = 'The achievement state request is invalid.';
        } else {
            $achievement = achievements_definition_by_id(
                $pdo,
                $achievementId,
                false
            );

            if ($achievement === null) {
                $errors[] = 'The achievement could not be found.';
            } else {
                try {
                    $statement = $pdo->prepare(
                        'UPDATE achievements
                         SET is_active = :is_active
                         WHERE id = :achievement_id'
                    );

                    $statement->execute([
                        'is_active' => (int) $desiredState,
                        'achievement_id' => $achievementId,
                    ]);

                    admin_achievements_audit(
                        $pdo,
                        $currentUserId,
                        $desiredState === '1'
                            ? 'achievement.activate'
                            : 'achievement.deactivate',
                        $achievementId,
                        ($desiredState === '1'
                            ? 'Activated achievement: '
                            : 'Deactivated achievement: ')
                        . (string) $achievement['name']
                    );

                    set_flash(
                        'success',
                        $desiredState === '1'
                            ? 'Achievement activated.'
                            : 'Achievement deactivated.'
                    );

                    redirect(
                        url('admin/achievements.php')
                    );
                } catch (Throwable $exception) {
                    error_log(
                        'Achievement state error: '
                        . $exception->getMessage()
                    );

                    $errors[] =
                        'The achievement state could not be changed.';
                }
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| Load Achievement Definitions
|--------------------------------------------------------------------------
*/

$achievementStatement = $pdo->query(
    'SELECT
        a.*,
        (
            SELECT COUNT(*)
            FROM user_achievements ua
            WHERE ua.achievement_id = a.id
        ) AS earned_count
     FROM achievements a
     ORDER BY a.sort_order ASC, a.name ASC, a.id ASC'
);

$achievements = $achievementStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];

/*
 * On a brand-new installation, make the first recommended achievement an
 * actual prefilled form instead of merely showing placeholder text. Placeholder
 * text is not submitted by the browser, which can make a required field look
 * filled while native validation silently blocks submission.
 */
if (
    $achievements === []
    && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
    && $editAchievementId === 0
) {
    $createForm = [
        'name' => 'Getting Sorted',
        'slug' => 'getting-sorted',
        'description' => 'Awarded when a member completes the Sorting Ceremony and joins a House.',
        'badge_image' => '',
        'requirement_type' => 'house_sorting',
        'requirement_value' => '',
        'threshold_value' => '',
        'target_type' => '',
        'target_id' => '',
        'points_awarded' => '10',
        'repeat_mode' => 'once_ever',
        'trigger_config' => '',
        'sort_order' => '10',
        'is_active' => '1',
    ];
}

if ($editAchievementId > 0) {
    foreach ($achievements as $achievementRow) {
        if ((int) ($achievementRow['id'] ?? 0) === $editAchievementId) {
            $editAchievement = $achievementRow;

            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || $errors === []) {
                $editForm = admin_achievements_form_from_row(
                    $achievementRow
                );
            }

            break;
        }
    }
}

if ($editAchievementId > 0 && $editAchievement === null) {
    $errors[] = 'The requested achievement could not be found.';
    $editAchievementId = 0;
}


/*
|--------------------------------------------------------------------------
| Summary
|--------------------------------------------------------------------------
*/

$summary = [
    'total' => count($achievements),
    'active' => 0,
    'automatic' => 0,
    'rewarding' => 0,
    'earned' => 0,
];

foreach ($achievements as $achievementRow) {
    if ((int) ($achievementRow['is_active'] ?? 0) === 1) {
        $summary['active']++;
    }

    if (
        strtolower((string) ($achievementRow['requirement_type'] ?? ''))
        !== 'manual'
    ) {
        $summary['automatic']++;
    }

    if ((int) ($achievementRow['points_awarded'] ?? 0) > 0) {
        $summary['rewarding']++;
    }

    $summary['earned'] += (int) ($achievementRow['earned_count'] ?? 0);
}


/*
|--------------------------------------------------------------------------
| Shared Points Sidebar
|--------------------------------------------------------------------------
*/

$pointsSidebarActive = 'achievements';
require INCLUDES_PATH . '/staff-points-sidebar.php';

/*
 * The shared Points sidebar was introduced before the project-standard
 * Staff Dashboard route was rechecked. Keep this page's footer destination
 * aligned with the live /staff-dashboard.php route.
 */
$dashboardSidebarFooter = [
    [
        'label' => 'Staff Dashboard',
        'href' => url('staff-dashboard.php'),
        'icon' => '←',
    ],
];


/*
|--------------------------------------------------------------------------
| SEO / Header
|--------------------------------------------------------------------------
*/

$pageTitle = 'Achievements | Blackthorne Academy';
$pageDescription = 'Create and manage dynamic Blackthorne Academy achievements.';
$pageCanonical = url('admin/achievements.php');
$robots = 'noindex, nofollow';

require INCLUDES_PATH . '/header.php';


/*
|--------------------------------------------------------------------------
| Hero
|--------------------------------------------------------------------------
*/

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

$repeatModeLabels = [
    'once_ever' => 'Once Ever',
    'once_per_school_year' => 'Once Per School Year',
    'once_per_source' => 'Once Per Source',
    'once_per_target' => 'Once Per Target',
    'repeatable' => 'Repeatable',
];

$triggerSuggestions = [
    'manual',
    'house_sorting',
    'post_count',
    'likes_received',
    'course_completion',
    'assignment_completion',
    'perfect_score',
    'profile_completion',
    'lesson_completion',
    'quiz_score',
];

?>

<main id="main-content" class="dashboard-page staff-dashboard-page dashboard-workspace-page achievements-admin-page">

    <section class="dashboard-hero staff-dashboard-hero" aria-labelledby="achievements-heading"
        <?php if ($staffHeroUrl !== ''): ?> style="--staff-dashboard-hero-image: url('<?= e($staffHeroUrl); ?>');"
        <?php endif; ?>>
        <div class="section-inner">
            <div class="dashboard-hero-inner">
                <p class="academy-overline">Points &amp; Recognition</p>
                <h1 id="achievements-heading">Achievements</h1>
                <p class="dashboard-hero-copy">
                    Build reusable Academy achievements, connect them to site
                    events, and define optional House Point rewards without
                    hard-coding individual milestones.
                </p>
            </div>
        </div>
    </section>


    <section class="dashboard-workspace-section">
        <div class="section-inner dashboard-workspace-layout">

            <?php
            require INCLUDES_PATH . '/dashboard-sidebar.php';
            ?>

            <div class="dashboard-workspace-main">

                <header class="dashboard-workspace-heading">
                    <div>
                        <p class="academy-overline">Dynamic Recognition</p>
                        <h2>Achievement Management</h2>
                        <p>
                            Achievement definitions live in the database. The
                            site only needs a code hook for each type of event;
                            milestones, thresholds, rewards, and repeat behavior
                            can then be changed here.
                        </p>
                    </div>
                </header>


                <?php if ($errors !== []): ?>
                <div class="form-message form-message-error" role="alert">
                    <strong>The achievement could not be saved.</strong>
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
                            <h3>Achievement Summary</h3>
                        </div>
                    </div>

                    <div class="dashboard-panel-body">
                        <div class="dashboard-placeholder-list">
                            <span><strong><?= number_format($summary['total']); ?></strong> total definitions</span>
                            <span><strong><?= number_format($summary['active']); ?></strong> active</span>
                            <span><strong><?= number_format($summary['automatic']); ?></strong> automatic trigger
                                definitions</span>
                            <span><strong><?= number_format($summary['rewarding']); ?></strong> award House
                                Points</span>
                            <span><strong><?= number_format($summary['earned']); ?></strong> earned occurrences</span>
                        </div>
                    </div>
                </section>


                <section class="dashboard-workspace-panel">
                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">Trigger Status</p>
                            <h3>Connected Site Events</h3>
                        </div>
                    </div>

                    <div class="dashboard-panel-body">
                        <p>
                            <strong>Currently connected automatically:</strong>
                            <code>house_sorting</code> fires when a member completes
                            the Sorting Ceremony. <code>manual</code> is reserved for
                            staff-awarded achievements. Other trigger names may be
                            configured now, but they will not fire until their
                            corresponding site feature is connected to the
                            Achievement service.
                        </p>
                    </div>
                </section>


                <?php
                $renderForm = static function (
                    array $form,
                    bool $editing,
                    int $achievementId = 0,
                    ?array $achievementRow = null
                ) use ($repeatModeLabels, $triggerSuggestions): void {
                    $badgeUrl = admin_achievements_image_url(
                        $form['badge_image'] ?? ''
                    );
                    ?>

                <form id="achievement-form" method="post"
                    action="<?= e(url('admin/achievements.php#achievement-form')); ?>" enctype="multipart/form-data"
                    novalidate>
                    <?= csrf_field(); ?>

                    <input type="hidden" name="action"
                        value="<?= $editing ? 'update_achievement' : 'create_achievement'; ?>">

                    <?php if ($editing): ?>
                    <input type="hidden" name="achievement_id" value="<?= (int) $achievementId; ?>">
                    <?php endif; ?>

                    <div class="forum-admin-form-grid">
                        <div class="form-group">
                            <label for="<?= $editing ? 'edit-' : 'create-'; ?>achievement-name">
                                Achievement Name
                            </label>
                            <input class="form-control" type="text"
                                id="<?= $editing ? 'edit-' : 'create-'; ?>achievement-name" name="name" maxlength="150"
                                value="<?= e((string) $form['name']); ?>" placeholder="Getting Sorted" required>
                        </div>

                        <div class="form-group">
                            <label for="<?= $editing ? 'edit-' : 'create-'; ?>achievement-slug">
                                Slug
                            </label>
                            <input class="form-control" type="text"
                                id="<?= $editing ? 'edit-' : 'create-'; ?>achievement-slug" name="slug" maxlength="160"
                                value="<?= e((string) $form['slug']); ?>" placeholder="getting-sorted">
                            <p class="form-help">
                                Optional. Leave blank and a unique slug will
                                be generated from the name.
                            </p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="<?= $editing ? 'edit-' : 'create-'; ?>achievement-description">
                            Description
                        </label>
                        <textarea class="form-control"
                            id="<?= $editing ? 'edit-' : 'create-'; ?>achievement-description" name="description"
                            rows="4"
                            placeholder="Awarded when a member completes the Sorting Ceremony and joins a House."><?= e((string) $form['description']); ?></textarea>
                    </div>

                    <fieldset class="forum-admin-fieldset">
                        <legend>Badge Image</legend>

                        <?php if ($badgeUrl !== ''): ?>
                        <p>
                            <img src="<?= e($badgeUrl); ?>" alt="Current achievement badge" width="96" height="96"
                                loading="lazy" style="object-fit: contain; max-width: 96px; max-height: 96px;">
                        </p>
                        <?php endif; ?>

                        <div class="forum-admin-form-grid">
                            <div class="form-group">
                                <label for="<?= $editing ? 'edit-' : 'create-'; ?>badge-image">
                                    Existing Image Path or URL
                                </label>
                                <input class="form-control" type="text"
                                    id="<?= $editing ? 'edit-' : 'create-'; ?>badge-image" name="badge_image"
                                    maxlength="255" value="<?= e((string) $form['badge_image']); ?>"
                                    placeholder="uploads/achievements/getting-sorted.webp">
                            </div>

                            <div class="form-group">
                                <label for="<?= $editing ? 'edit-' : 'create-'; ?>badge-upload">
                                    Upload Badge Image
                                </label>
                                <input class="form-control" type="file"
                                    id="<?= $editing ? 'edit-' : 'create-'; ?>badge-upload" name="badge_upload"
                                    accept="image/png,image/jpeg,image/webp,image/gif">
                                <p class="form-help">
                                    PNG, JPG, WebP, or GIF. Maximum 5 MB.
                                    A new upload replaces the path above.
                                </p>
                            </div>
                        </div>
                    </fieldset>

                    <fieldset class="forum-admin-fieldset">
                        <legend>Trigger Configuration</legend>

                        <div class="forum-admin-form-grid">
                            <div class="form-group">
                                <label for="<?= $editing ? 'edit-' : 'create-'; ?>requirement-type">
                                    Trigger Type
                                </label>
                                <input class="form-control" type="text"
                                    id="<?= $editing ? 'edit-' : 'create-'; ?>requirement-type" name="requirement_type"
                                    maxlength="100" list="achievement-trigger-types"
                                    value="<?= e((string) $form['requirement_type']); ?>" required>
                                <p class="form-help">
                                    Trigger names are open-ended. Automatic
                                    achievements only fire when the site emits
                                    the matching event name.
                                </p>
                            </div>

                            <div class="form-group">
                                <label for="<?= $editing ? 'edit-' : 'create-'; ?>repeat-mode">
                                    Repeat Mode
                                </label>
                                <select class="form-control" id="<?= $editing ? 'edit-' : 'create-'; ?>repeat-mode"
                                    name="repeat_mode" required>
                                    <?php foreach ($repeatModeLabels as $value => $label): ?>
                                    <option value="<?= e($value); ?>" <?= (string) $form['repeat_mode'] === $value
                                                    ? 'selected'
                                                    : ''; ?>>
                                        <?= e($label); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <datalist id="achievement-trigger-types">
                            <?php foreach ($triggerSuggestions as $triggerSuggestion): ?>
                            <option value="<?= e($triggerSuggestion); ?>"></option>
                            <?php endforeach; ?>
                        </datalist>

                        <div class="forum-admin-form-grid">
                            <div class="form-group">
                                <label for="<?= $editing ? 'edit-' : 'create-'; ?>threshold-value">
                                    Threshold
                                </label>
                                <input class="form-control" type="number" step="0.01"
                                    id="<?= $editing ? 'edit-' : 'create-'; ?>threshold-value" name="threshold_value"
                                    value="<?= e((string) $form['threshold_value']); ?>" placeholder="1">
                                <p class="form-help">
                                    Optional numeric milestone. For example,
                                    a post-count achievement might use 10.
                                </p>
                            </div>

                            <div class="form-group">
                                <label for="<?= $editing ? 'edit-' : 'create-'; ?>requirement-value">
                                    Requirement Value
                                </label>
                                <input class="form-control" type="text"
                                    id="<?= $editing ? 'edit-' : 'create-'; ?>requirement-value"
                                    name="requirement_value" maxlength="255"
                                    value="<?= e((string) $form['requirement_value']); ?>">
                                <p class="form-help">
                                    Optional text value retained for flexible
                                    trigger-specific configuration.
                                </p>
                            </div>
                        </div>

                        <div class="forum-admin-form-grid">
                            <div class="form-group">
                                <label for="<?= $editing ? 'edit-' : 'create-'; ?>target-type">
                                    Target Type
                                </label>
                                <input class="form-control" type="text"
                                    id="<?= $editing ? 'edit-' : 'create-'; ?>target-type" name="target_type"
                                    maxlength="50" value="<?= e((string) $form['target_type']); ?>" placeholder="house">
                                <p class="form-help">
                                    Optional. Use when an achievement applies
                                    to a particular kind of entity, such as
                                    a House or course.
                                </p>
                            </div>

                            <div class="form-group">
                                <label for="<?= $editing ? 'edit-' : 'create-'; ?>target-id">
                                    Target ID
                                </label>
                                <input class="form-control" type="number" min="1" step="1"
                                    id="<?= $editing ? 'edit-' : 'create-'; ?>target-id" name="target_id"
                                    value="<?= e((string) $form['target_id']); ?>">
                                <p class="form-help">
                                    Optional. Leave blank to match every
                                    target of the selected type.
                                </p>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="<?= $editing ? 'edit-' : 'create-'; ?>trigger-config">
                                Advanced Trigger Configuration (JSON)
                            </label>
                            <textarea class="form-control" id="<?= $editing ? 'edit-' : 'create-'; ?>trigger-config"
                                name="trigger_config" rows="7" spellcheck="false"
                                placeholder='{"comparison":"gte","conditions":{"course_type":"orientation"}}'><?= e((string) $form['trigger_config']); ?></textarea>
                            <p class="form-help">
                                Optional. The Achievement service currently
                                understands a threshold comparison and exact
                                event-context conditions. Leave blank unless
                                the trigger needs advanced matching.
                            </p>
                        </div>
                    </fieldset>

                    <fieldset class="forum-admin-fieldset">
                        <legend>Reward &amp; Display</legend>

                        <div class="forum-admin-form-grid">
                            <div class="form-group">
                                <label for="<?= $editing ? 'edit-' : 'create-'; ?>points-awarded">
                                    House Points Awarded
                                </label>
                                <input class="form-control" type="number" min="0" step="1"
                                    id="<?= $editing ? 'edit-' : 'create-'; ?>points-awarded" name="points_awarded"
                                    value="<?= e((string) $form['points_awarded']); ?>" required>
                                <p class="form-help">
                                    Use 0 for a badge-only achievement.
                                    Achievement rewards are House-only points
                                    and do not count toward academic progression.
                                </p>
                            </div>

                            <div class="form-group">
                                <label for="<?= $editing ? 'edit-' : 'create-'; ?>sort-order">
                                    Sort Order
                                </label>
                                <input class="form-control" type="number" min="0" step="1"
                                    id="<?= $editing ? 'edit-' : 'create-'; ?>sort-order" name="sort_order"
                                    value="<?= e((string) $form['sort_order']); ?>" required>
                            </div>
                        </div>

                        <label class="forum-admin-choice">
                            <input type="checkbox" name="is_active" value="1" <?= (string) $form['is_active'] === '1'
                                        ? 'checked'
                                        : ''; ?>>
                            <span>Active</span>
                        </label>
                    </fieldset>

                    <?php if ($editing && is_array($achievementRow)): ?>
                    <p class="form-help">
                        Earned occurrences:
                        <strong><?= number_format((int) ($achievementRow['earned_count'] ?? 0)); ?></strong>.
                        Historical earned records are preserved even if
                        this definition is deactivated.
                    </p>
                    <?php endif; ?>

                    <div class="forum-admin-actions">
                        <button type="submit" class="button button-primary">
                            <?= $editing ? 'Save Achievement' : 'Create Achievement'; ?>
                        </button>

                        <?php if ($editing): ?>
                        <a class="button button-secondary" href="<?= e(url('admin/achievements.php')); ?>">
                            Cancel Edit
                        </a>
                        <?php endif; ?>
                    </div>
                </form>

                <?php
                };
                ?>


                <?php if ($editAchievement !== null): ?>
                <section class="dashboard-workspace-panel">
                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">Edit Definition</p>
                            <h3><?= e((string) $editAchievement['name']); ?></h3>
                        </div>
                    </div>

                    <div class="dashboard-panel-body">
                        <?php
                            $renderForm(
                                $editForm,
                                true,
                                (int) $editAchievement['id'],
                                $editAchievement
                            );
                            ?>
                    </div>
                </section>
                <?php else: ?>
                <section class="dashboard-workspace-panel">
                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">New Definition</p>
                            <h3>Create Achievement</h3>
                        </div>
                    </div>

                    <div class="dashboard-panel-body">
                        <?php
                            $renderForm(
                                $createForm,
                                false
                            );
                            ?>
                    </div>
                </section>
                <?php endif; ?>


                <section class="dashboard-workspace-panel">
                    <div class="dashboard-panel-titlebar">
                        <div>
                            <p class="academy-overline">Definitions</p>
                            <h3>Existing Achievements</h3>
                        </div>
                    </div>

                    <div class="dashboard-panel-body">
                        <?php if ($achievements === []): ?>
                        <p>
                            No achievements have been created yet. A useful
                            first definition is <strong>Getting Sorted</strong>
                            with the <code>house_sorting</code> trigger.
                        </p>
                        <?php else: ?>
                        <div class="dashboard-placeholder-list">
                            <?php foreach ($achievements as $achievementRow): ?>
                            <?php
                                    $rowBadgeUrl = admin_achievements_image_url(
                                        (string) ($achievementRow['badge_image'] ?? '')
                                    );
                                    $rowActive = (int) ($achievementRow['is_active'] ?? 0) === 1;
                                    ?>

                            <span>
                                <?php if ($rowBadgeUrl !== ''): ?>
                                <img src="<?= e($rowBadgeUrl); ?>" alt="" width="36" height="36" loading="lazy"
                                    style="object-fit: contain; vertical-align: middle; margin-right: .5rem;">
                                <?php endif; ?>

                                <strong><?= e((string) $achievementRow['name']); ?></strong>
                                · <?= $rowActive ? 'Active' : 'Inactive'; ?>
                                · Trigger: <code><?= e((string) $achievementRow['requirement_type']); ?></code>
                                · Repeat:
                                <?= e($repeatModeLabels[(string) $achievementRow['repeat_mode']] ?? (string) $achievementRow['repeat_mode']); ?>
                                · Reward: <?= number_format((int) ($achievementRow['points_awarded'] ?? 0)); ?> House
                                Points
                                · Earned: <?= number_format((int) ($achievementRow['earned_count'] ?? 0)); ?>
                                ·
                                <a href="<?= e(url('admin/achievements.php?edit=' . (int) $achievementRow['id'])); ?>">
                                    Edit
                                </a>

                                ·
                                <form method="post" style="display: inline;">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="action" value="toggle_achievement">
                                    <input type="hidden" name="achievement_id"
                                        value="<?= (int) $achievementRow['id']; ?>">
                                    <input type="hidden" name="desired_state" value="<?= $rowActive ? '0' : '1'; ?>">
                                    <button type="submit" class="button button-secondary"
                                        style="padding: .25rem .6rem; min-height: auto;">
                                        <?= $rowActive ? 'Deactivate' : 'Activate'; ?>
                                    </button>
                                </form>
                            </span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </section>

            </div>
        </div>
    </section>

</main>

<?php
require INCLUDES_PATH . '/footer.php';
