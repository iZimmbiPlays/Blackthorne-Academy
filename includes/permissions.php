<?php

declare(strict_types=1);

/**
 * Blackthorne Academy
 * Global Roles / Groups / Permissions Helpers
 *
 * Permission precedence:
 *
 * 1. Protected Super Admin
 * 2. Direct user permission override
 * 3. Active role with grants_all_permissions = 1
 * 4. Permission granted/denied by active roles
 * 5. Default deny
 *
 * Roles may also be marked is_staff = 1. Staff status itself does not
 * grant permissions; it only identifies the role as a staff role.
 */


/*
|--------------------------------------------------------------------------
| Permission Result Constants
|--------------------------------------------------------------------------
*/

if (!defined('PERMISSION_ALLOW')) {
    define('PERMISSION_ALLOW', 1);
}

if (!defined('PERMISSION_DENY')) {
    define('PERMISSION_DENY', -1);
}

if (!defined('PERMISSION_UNSET')) {
    define('PERMISSION_UNSET', 0);
}


/*
|--------------------------------------------------------------------------
| Permission Slug Normalization
|--------------------------------------------------------------------------
*/

function normalize_permission_slug(string $permissionSlug): string
{
    return trim($permissionSlug);
}


/*
|--------------------------------------------------------------------------
| Permission Exists
|--------------------------------------------------------------------------
|
| Returns true only when the permission exists and is active.
|
*/

function permission_exists(string $permissionSlug): bool
{
    global $pdo;

    $permissionSlug = normalize_permission_slug($permissionSlug);

    if ($permissionSlug === '') {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT 1
         FROM permissions
         WHERE slug = :permission_slug
           AND is_active = 1
         LIMIT 1'
    );

    $stmt->execute([
        'permission_slug' => $permissionSlug,
    ]);

    return (bool) $stmt->fetchColumn();
}


/*
|--------------------------------------------------------------------------
| Direct User Permission Result By User
|--------------------------------------------------------------------------
|
| Direct user permissions have the highest normal precedence.
|
| Returns:
|
|  1  = explicitly allowed
| -1  = explicitly denied
|  0  = no active override
|
*/

function user_direct_permission_result(
    int $userId,
    string $permissionSlug
): int {
    global $pdo;

    $permissionSlug = normalize_permission_slug($permissionSlug);

    if ($userId <= 0 || $permissionSlug === '') {
        return PERMISSION_UNSET;
    }

    $stmt = $pdo->prepare(
        'SELECT
            up.is_allowed
         FROM user_permissions up
         INNER JOIN permissions p
            ON p.id = up.permission_id
         WHERE up.user_id = :user_id
           AND p.slug = :permission_slug
           AND p.is_active = 1
           AND up.is_active = 1
           AND up.revoked_at IS NULL
         ORDER BY up.id DESC
         LIMIT 1'
    );

    $stmt->execute([
        'user_id' => $userId,
        'permission_slug' => $permissionSlug,
    ]);

    $result = $stmt->fetchColumn();

    if ($result === false) {
        return PERMISSION_UNSET;
    }

    return ((int) $result === 1)
        ? PERMISSION_ALLOW
        : PERMISSION_DENY;
}


/*
|--------------------------------------------------------------------------
| Current User Direct Permission Result
|--------------------------------------------------------------------------
|
| Kept for compatibility with the existing site code.
|
*/

function direct_user_permission_result(string $permissionSlug): int
{
    $userId = current_user_id();

    if ($userId === null) {
        return PERMISSION_UNSET;
    }

    return user_direct_permission_result(
        $userId,
        $permissionSlug
    );
}


/*
|--------------------------------------------------------------------------
| Active User Roles
|--------------------------------------------------------------------------
|
| Returns all active, non-revoked, non-expired roles belonging to a user.
|
| One user may belong to multiple roles/groups.
|
*/

function user_active_roles(int $userId): array
{
    global $pdo;

    if ($userId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT
            r.id,
            r.name,
            r.slug,
            r.description,
            r.display_color,
            r.is_system_role,
            r.grants_all_permissions,
            r.is_staff,
            r.is_active,
            r.sort_order,
            ur.id AS user_role_id,
            ur.assigned_by,
            ur.assigned_at,
            ur.expires_at
         FROM user_roles ur
         INNER JOIN roles r
            ON r.id = ur.role_id
         WHERE ur.user_id = :user_id
           AND ur.is_active = 1
           AND ur.revoked_at IS NULL
           AND (
                ur.expires_at IS NULL
                OR ur.expires_at > NOW()
           )
           AND r.is_active = 1
         ORDER BY
            r.sort_order ASC,
            r.name ASC,
            r.id ASC'
    );

    $stmt->execute([
        'user_id' => $userId,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}


/*
|--------------------------------------------------------------------------
| Current User Active Roles
|--------------------------------------------------------------------------
*/

function current_user_active_roles(): array
{
    $userId = current_user_id();

    if ($userId === null) {
        return [];
    }

    return user_active_roles($userId);
}


/*
|--------------------------------------------------------------------------
| Grants-All-Permissions Role
|--------------------------------------------------------------------------
|
| A role/group may explicitly grant every normal site permission.
|
| This does NOT replace the protected Super Admin account.
|
| Protected Super Admin exists separately through:
|
| settings.super_admin_user_id
|
*/

function user_has_all_permissions_role(int $userId): bool
{
    global $pdo;

    if ($userId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT 1
         FROM user_roles ur
         INNER JOIN roles r
            ON r.id = ur.role_id
         WHERE ur.user_id = :user_id
           AND ur.is_active = 1
           AND ur.revoked_at IS NULL
           AND (
                ur.expires_at IS NULL
                OR ur.expires_at > NOW()
           )
           AND r.is_active = 1
           AND r.grants_all_permissions = 1
         LIMIT 1'
    );

    $stmt->execute([
        'user_id' => $userId,
    ]);

    return (bool) $stmt->fetchColumn();
}


/*
|--------------------------------------------------------------------------
| Current User Grants-All-Permissions Role
|--------------------------------------------------------------------------
*/

function current_user_has_all_permissions_role(): bool
{
    $userId = current_user_id();

    if ($userId === null) {
        return false;
    }

    return user_has_all_permissions_role($userId);
}


/*
|--------------------------------------------------------------------------
| Staff Role Detection
|--------------------------------------------------------------------------
|
| Staff status is separate from permissions.
|
| A role with is_staff = 1 identifies the user as staff, but does not grant
| any specific administrative/moderation/course permission by itself.
|
*/

function user_has_staff_role(int $userId): bool
{
    global $pdo;

    if ($userId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT 1
         FROM user_roles ur
         INNER JOIN roles r
            ON r.id = ur.role_id
         WHERE ur.user_id = :user_id
           AND ur.is_active = 1
           AND ur.revoked_at IS NULL
           AND (
                ur.expires_at IS NULL
                OR ur.expires_at > NOW()
           )
           AND r.is_active = 1
           AND r.is_staff = 1
         LIMIT 1'
    );

    $stmt->execute([
        'user_id' => $userId,
    ]);

    return (bool) $stmt->fetchColumn();
}


/*
|--------------------------------------------------------------------------
| Current User Staff Role Detection
|--------------------------------------------------------------------------
*/

function current_user_has_staff_role(): bool
{
    $userId = current_user_id();

    if ($userId === null) {
        return false;
    }

    return user_has_staff_role($userId);
}


/*
|--------------------------------------------------------------------------
| Role Permission Result By User
|--------------------------------------------------------------------------
|
| Permissions from all active roles/groups are combined.
|
| If ANY active role allows a permission, the role layer allows it.
|
| A direct user-level deny still overrides this because direct permissions
| are evaluated before role permissions.
|
*/

function user_role_permission_result(
    int $userId,
    string $permissionSlug
): int {
    global $pdo;

    $permissionSlug = normalize_permission_slug($permissionSlug);

    if ($userId <= 0 || $permissionSlug === '') {
        return PERMISSION_UNSET;
    }

    $stmt = $pdo->prepare(
        'SELECT
            rp.is_allowed
         FROM user_roles ur
         INNER JOIN roles r
            ON r.id = ur.role_id
         INNER JOIN role_permissions rp
            ON rp.role_id = r.id
         INNER JOIN permissions p
            ON p.id = rp.permission_id
         WHERE ur.user_id = :user_id
           AND p.slug = :permission_slug
           AND p.is_active = 1

           AND ur.is_active = 1
           AND ur.revoked_at IS NULL
           AND (
                ur.expires_at IS NULL
                OR ur.expires_at > NOW()
           )

           AND r.is_active = 1'
    );

    $stmt->execute([
        'user_id' => $userId,
        'permission_slug' => $permissionSlug,
    ]);

    $results = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($results)) {
        return PERMISSION_UNSET;
    }

    $hasDeny = false;

    foreach ($results as $result) {
        if ((int) $result === 1) {
            return PERMISSION_ALLOW;
        }

        if ((int) $result === 0) {
            $hasDeny = true;
        }
    }

    return $hasDeny
        ? PERMISSION_DENY
        : PERMISSION_UNSET;
}


/*
|--------------------------------------------------------------------------
| Current User Role Permission Result
|--------------------------------------------------------------------------
|
| Kept for compatibility with the existing site code.
|
*/

function role_permission_result(string $permissionSlug): int
{
    $userId = current_user_id();

    if ($userId === null) {
        return PERMISSION_UNSET;
    }

    return user_role_permission_result(
        $userId,
        $permissionSlug
    );
}


/*
|--------------------------------------------------------------------------
| Protected Super Admin By User ID
|--------------------------------------------------------------------------
|
| The protected Super Admin account exists separately from roles/groups.
|
| This allows permission checks for users other than the currently logged-in
| user when needed by administration/moderation systems.
|
*/

function user_is_protected_super_admin(int $userId): bool
{
    global $pdo;

    if ($userId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT setting_value
         FROM settings
         WHERE setting_key = :setting_key
         LIMIT 1'
    );

    $stmt->execute([
        'setting_key' => 'super_admin_user_id',
    ]);

    $configuredUserId = $stmt->fetchColumn();

    if ($configuredUserId === false) {
        return false;
    }

    $superAdminUserId = (int) $configuredUserId;

    if ($superAdminUserId <= 0 || $superAdminUserId !== $userId) {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT 1
         FROM users
         WHERE id = :user_id
           AND status = :status
           AND email_verified_at IS NOT NULL
         LIMIT 1'
    );

    $stmt->execute([
        'user_id' => $userId,
        'status' => 'active',
    ]);

    return (bool) $stmt->fetchColumn();
}


/*
|--------------------------------------------------------------------------
| Permission Check For Specific User
|--------------------------------------------------------------------------
|
| This is the central global permission resolver.
|
| Precedence:
|
| 1. Protected Super Admin
| 2. Direct user override
| 3. Role with grants_all_permissions
| 4. Normal role permissions
| 5. Default deny
|
*/

function user_can_by_id(
    int $userId,
    string $permissionSlug
): bool {
    $permissionSlug = normalize_permission_slug($permissionSlug);

    if ($userId <= 0 || $permissionSlug === '') {
        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | 1. Protected Super Admin
    |--------------------------------------------------------------------------
    */

    if (user_is_protected_super_admin($userId)) {
        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | 2. Direct User Override
    |--------------------------------------------------------------------------
    */

    $directResult = user_direct_permission_result(
        $userId,
        $permissionSlug
    );

    if ($directResult === PERMISSION_ALLOW) {
        return true;
    }

    if ($directResult === PERMISSION_DENY) {
        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | 3. Grants-All-Permissions Role
    |--------------------------------------------------------------------------
    */

    if (user_has_all_permissions_role($userId)) {
        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | 4. Normal Role Permission
    |--------------------------------------------------------------------------
    */

    $roleResult = user_role_permission_result(
        $userId,
        $permissionSlug
    );

    if ($roleResult === PERMISSION_ALLOW) {
        return true;
    }

    if ($roleResult === PERMISSION_DENY) {
        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | 5. Default Deny
    |--------------------------------------------------------------------------
    */

    return false;
}


/*
|--------------------------------------------------------------------------
| Current User Permission Check
|--------------------------------------------------------------------------
|
| Existing calls such as:
|
| user_can('manage_forums')
|
| continue working.
|
*/

function user_can(string $permissionSlug): bool
{
    if (!is_logged_in()) {
        return false;
    }

    $userId = current_user_id();

    if ($userId === null) {
        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Preserve Current Super Admin Fast Path
    |--------------------------------------------------------------------------
    |
    | current_user_is_superuser() is the existing protected-account helper.
    |
    */

    if (current_user_is_superuser()) {
        return true;
    }

    return user_can_by_id(
        $userId,
        $permissionSlug
    );
}


/*
|--------------------------------------------------------------------------
| Require Permission
|--------------------------------------------------------------------------
*/

function require_permission(string $permissionSlug): void
{
    global $pdo;

    require_login();

    if (user_can($permissionSlug)) {
        return;
    }

    http_response_code(403);

    /*
    |----------------------------------------------------------------------
    | Styled Permission-Denied Response
    |----------------------------------------------------------------------
    |
    | Permission-protected pages should never fall back to a bare browser
    | message. Render the standard Blackthorne Academy shell so denied
    | access is clear, consistent, and still provides normal navigation.
    |
    */
    $pageTitle =
        'Access Denied | Blackthorne Academy';

    $pageDescription =
        'You do not have permission to access this Blackthorne Academy area.';

    $pageCanonical =
        HOME_URL;

    $robots =
        'noindex, nofollow';

    require
        INCLUDES_PATH
        . '/header.php';

    ?>

<main id="main-content" class="forum-board-page">
    <section class="forum-board-error">
        <div class="section-inner">
            <p class="academy-overline">
                Restricted Area
            </p>

            <h1>
                Access Denied
            </h1>

            <p>
                You do not have permission to access this area.
            </p>

            <a class="button button-secondary" href="<?= e(HOME_URL); ?>">
                Return to Home
            </a>
        </div>
    </section>
</main>

<?php

    require
        INCLUDES_PATH
        . '/footer.php';

    exit;
}


/*
|--------------------------------------------------------------------------
| Any Permission Check
|--------------------------------------------------------------------------
*/

function user_can_any(array $permissionSlugs): bool
{
    foreach ($permissionSlugs as $permissionSlug) {
        if (!is_string($permissionSlug)) {
            continue;
        }

        $permissionSlug = normalize_permission_slug($permissionSlug);

        if ($permissionSlug === '') {
            continue;
        }

        if (user_can($permissionSlug)) {
            return true;
        }
    }

    return false;
}


/*
|--------------------------------------------------------------------------
| All Permissions Check
|--------------------------------------------------------------------------
*/

function user_can_all(array $permissionSlugs): bool
{
    foreach ($permissionSlugs as $permissionSlug) {
        if (!is_string($permissionSlug)) {
            return false;
        }

        $permissionSlug = normalize_permission_slug($permissionSlug);

        if ($permissionSlug === '') {
            return false;
        }

        if (!user_can($permissionSlug)) {
            return false;
        }
    }

    return true;
}


/*
|--------------------------------------------------------------------------
| Specific User Any Permission Check
|--------------------------------------------------------------------------
*/

function user_can_any_by_id(
    int $userId,
    array $permissionSlugs
): bool {
    foreach ($permissionSlugs as $permissionSlug) {
        if (!is_string($permissionSlug)) {
            continue;
        }

        $permissionSlug = normalize_permission_slug($permissionSlug);

        if ($permissionSlug === '') {
            continue;
        }

        if (user_can_by_id($userId, $permissionSlug)) {
            return true;
        }
    }

    return false;
}


/*
|--------------------------------------------------------------------------
| Specific User All Permissions Check
|--------------------------------------------------------------------------
*/

function user_can_all_by_id(
    int $userId,
    array $permissionSlugs
): bool {
    foreach ($permissionSlugs as $permissionSlug) {
        if (!is_string($permissionSlug)) {
            return false;
        }

        $permissionSlug = normalize_permission_slug($permissionSlug);

        if ($permissionSlug === '') {
            return false;
        }

        if (!user_can_by_id($userId, $permissionSlug)) {
            return false;
        }
    }

    return true;
}
