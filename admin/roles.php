<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

require_active_account();


/*
|--------------------------------------------------------------------------
| Page Access
|--------------------------------------------------------------------------
|
| Protected Super Admin always has access.
| Other users may access this page when their assigned permissions allow it.
|
*/

$isProtectedSuperAdmin = current_user_is_superuser();

$canViewRoles =
    $isProtectedSuperAdmin
    || user_can('roles.view')
    || user_can('roles.create')
    || user_can('roles.edit')
    || user_can('roles.permissions.manage');

if (!$canViewRoles) {
    http_response_code(403);
    exit('You do not have permission to access Roles & Permissions.');
}

$canCreateRoles = $isProtectedSuperAdmin || user_can('roles.create');
$canEditRoles = $isProtectedSuperAdmin || user_can('roles.edit');
$canManageRolePermissions =
    $isProtectedSuperAdmin || user_can('roles.permissions.manage');


/*
|--------------------------------------------------------------------------
| Local Helpers
|--------------------------------------------------------------------------
*/

function admin_roles_slugify(string $value): string
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

    return substr($value, 0, 100);
}


function admin_roles_text_length(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);
}


function admin_roles_query_id(string $key): int
{
    $value = $_GET[$key] ?? '';

    if (!is_scalar($value) || !ctype_digit((string) $value)) {
        return 0;
    }

    return max(0, (int) $value);
}


function admin_roles_post_ids(string $key): array
{
    $values = $_POST[$key] ?? [];

    if (!is_array($values)) {
        return [];
    }

    $ids = [];

    foreach ($values as $value) {
        if (!is_scalar($value) || !ctype_digit((string) $value)) {
            continue;
        }

        $id = (int) $value;

        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    return array_values($ids);
}


function admin_roles_valid_color(string $value): bool
{
    if ($value === '') {
        return true;
    }

    return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1;
}


function admin_roles_current_user_role_ids(
    PDO $pdo,
    int $userId
): array {
    if ($userId <= 0) {
        return [];
    }

    $statement = $pdo->prepare(
        'SELECT ur.role_id
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
           AND r.is_active = 1'
    );

    $statement->execute([
        'user_id' => $userId,
    ]);

    return array_values(
        array_unique(
            array_map(
                'intval',
                $statement->fetchAll(PDO::FETCH_COLUMN)
            )
        )
    );
}


function admin_roles_permission_ids_actor_can_grant(
    array $permissions,
    bool $isProtectedSuperAdmin
): array {
    if ($isProtectedSuperAdmin) {
        return array_map(
            static fn (array $permission): int =>
                (int) $permission['id'],
            $permissions
        );
    }

    /*
     * These permissions change who can administer roles or direct user
     * permissions. They are intentionally non-delegable through the role
     * matrix for every account except the protected Super Admin.
     *
     * A staff member with roles.permissions.manage may still manage ordinary
     * capabilities that the staff member personally possesses, but cannot use
     * that capability to create another permission administrator or role
     * assigner.
     */
    $protectedPermissionSlugs = [
        'users.permissions.manage',
        'roles.permissions.manage',
        'roles.assign',
    ];

    $allowedIds = [];

    foreach ($permissions as $permission) {
        $permissionId =
            (int) (
                $permission['id']
                ?? 0
            );

        $permissionSlug =
            trim(
                (string) (
                    $permission['slug']
                    ?? ''
                )
            );

        if (
            $permissionId <= 0
            || $permissionSlug === ''
            || in_array(
                $permissionSlug,
                $protectedPermissionSlugs,
                true
            )
        ) {
            continue;
        }

        if (user_can($permissionSlug)) {
            $allowedIds[$permissionId] =
                $permissionId;
        }
    }

    return array_values($allowedIds);
}


function admin_roles_audit(
    PDO $pdo,
    int $userId,
    string $actionType,
    int $roleId,
    string $description
): void {
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
            'user_id' => $userId,
            'action_type' => $actionType,
            'entity_type' => 'role',
            'entity_id' => $roleId,
            'description' => $description,
            'ip_address' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        ]);
    } catch (PDOException $exception) {
        error_log(
            'Blackthorne role audit log error: ' . $exception->getMessage()
        );
    }
}


/*
|--------------------------------------------------------------------------
| Permission Catalog
|--------------------------------------------------------------------------
*/

$permissionStatement = $pdo->query(
    'SELECT
        id,
        name,
        slug,
        category,
        description,
        sort_order
     FROM permissions
     WHERE is_active = 1
     ORDER BY category ASC, sort_order ASC, name ASC'
);

$permissions = $permissionStatement->fetchAll(PDO::FETCH_ASSOC);
$permissionsByCategory = [];
$validPermissionIds = [];

foreach ($permissions as $permission) {
    $permissionId = (int) $permission['id'];
    $category = trim((string) $permission['category']);

    if ($category === '') {
        $category = 'Other';
    }

    $validPermissionIds[$permissionId] = true;
    $permissionsByCategory[$category][] = $permission;
}


/*
|--------------------------------------------------------------------------
| Form Processing
|--------------------------------------------------------------------------
*/

$errors = [];
$formAction = post_value('form_action');
$currentUserId = (int) (current_user_id() ?? 0);

$currentUserRoleIds =
    admin_roles_current_user_role_ids(
        $pdo,
        $currentUserId
    );

$currentUserRoleIdMap =
    array_fill_keys(
        $currentUserRoleIds,
        true
    );

$grantablePermissionIds =
    admin_roles_permission_ids_actor_can_grant(
        $permissions,
        $isProtectedSuperAdmin
    );

$grantablePermissionIdMap =
    array_fill_keys(
        $grantablePermissionIds,
        true
    );


if (is_post()) {
    require_valid_csrf();

    /*
    |----------------------------------------------------------------------
    | Create Role / Group
    |----------------------------------------------------------------------
    */

    if ($formAction === 'create_role') {
        if (!$canCreateRoles) {
            http_response_code(403);
            exit('You do not have permission to create roles or groups.');
        }

        $roleName = post_value('role_name');
        $roleSlug = admin_roles_slugify($roleName);
        $roleDescription = post_value('role_description');
        $useDisplayColor = post_value('use_display_color') === '1';
        $displayColor = $useDisplayColor ? post_value('display_color') : '';
        $isStaff = post_value('is_staff') === '1' ? 1 : 0;
        $isActive = post_value('is_active') === '1' ? 1 : 0;

        /*
         * grants_all_permissions is intentionally restricted to the
         * protected Super Admin account because it bypasses the normal
         * role permission matrix.
         */
        $grantsAllPermissions =
            $isProtectedSuperAdmin
            && post_value('grants_all_permissions') === '1'
                ? 1
                : 0;

        if ($roleName === '') {
            $errors[] = 'Enter a role/group name.';
        } elseif (admin_roles_text_length($roleName) > 100) {
            $errors[] = 'The role/group name cannot exceed 100 characters.';
        }

        if ($roleSlug === '') {
            $errors[] = 'Enter a role/group name that can create a valid URL-safe slug.';
        }

        if (admin_roles_text_length($roleDescription) > 5000) {
            $errors[] = 'The role/group description is too long.';
        }

        if ($useDisplayColor && !admin_roles_valid_color($displayColor)) {
            $errors[] = 'Choose a valid display color.';
        }

        if ($useDisplayColor && $displayColor === '') {
            $errors[] = 'Select a role color or turn off Use Role Color.';
        }

        if ($errors === []) {
            $duplicateStatement = $pdo->prepare(
                'SELECT id
                 FROM roles
                 WHERE name = :name
                    OR slug = :slug
                 LIMIT 1'
            );

            $duplicateStatement->execute([
                'name' => $roleName,
                'slug' => $roleSlug,
            ]);

            if ($duplicateStatement->fetchColumn() !== false) {
                $errors[] = 'A role/group with that name or slug already exists.';
            }
        }

        if ($errors === []) {
            try {
                $pdo->beginTransaction();

                $sortStatement = $pdo->query(
                    'SELECT COALESCE(MAX(sort_order), 0) + 10
                     FROM roles'
                );

                $sortOrder = (int) $sortStatement->fetchColumn();

                $insertStatement = $pdo->prepare(
                    'INSERT INTO roles (
                        name,
                        slug,
                        description,
                        display_color,
                        is_system_role,
                        grants_all_permissions,
                        is_staff,
                        is_active,
                        sort_order
                     ) VALUES (
                        :name,
                        :slug,
                        :description,
                        :display_color,
                        0,
                        :grants_all_permissions,
                        :is_staff,
                        :is_active,
                        :sort_order
                     )'
                );

                $insertStatement->execute([
                    'name' => $roleName,
                    'slug' => $roleSlug,
                    'description' => $roleDescription !== '' ? $roleDescription : null,
                    'display_color' => $displayColor !== '' ? $displayColor : null,
                    'grants_all_permissions' => $grantsAllPermissions,
                    'is_staff' => $isStaff,
                    'is_active' => $isActive,
                    'sort_order' => $sortOrder,
                ]);

                $roleId = (int) $pdo->lastInsertId();

                admin_roles_audit(
                    $pdo,
                    $currentUserId,
                    'role_create',
                    $roleId,
                    'Created role/group: ' . $roleName
                );

                $pdo->commit();

                set_flash(
                    'success',
                    'Role/group created. You can now assign its permissions.'
                );

                redirect(url('admin/roles.php?edit_role=' . $roleId));
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'Blackthorne role creation error: ' . $exception->getMessage()
                );

                $errors[] = 'The role/group could not be created. Please try again.';
            }
        }
    }


    /*
    |----------------------------------------------------------------------
    | Update Role / Group Details
    |----------------------------------------------------------------------
    */

    if ($formAction === 'update_role') {
        if (!$canEditRoles) {
            http_response_code(403);
            exit('You do not have permission to edit roles or groups.');
        }

        $roleIdValue = post_value('role_id');
        $roleId = ctype_digit($roleIdValue) ? (int) $roleIdValue : 0;

        $roleStatement = $pdo->prepare(
            'SELECT *
             FROM roles
             WHERE id = :role_id
             LIMIT 1'
        );
        $roleStatement->execute(['role_id' => $roleId]);
        $existingRole = $roleStatement->fetch(PDO::FETCH_ASSOC);

        if (!$existingRole) {
            $errors[] = 'The selected role/group could not be found.';
        } else {
            $editingOwnRole =
                isset(
                    $currentUserRoleIdMap[
                        $roleId
                    ]
                );

            $editingFullPrivilegeRole =
                (int) (
                    $existingRole[
                        'grants_all_permissions'
                    ]
                    ?? 0
                ) === 1;

            if (
                !$isProtectedSuperAdmin
                && $editingOwnRole
            ) {
                $errors[] =
                    'You cannot edit the settings of a role/group assigned to your own account.';
            }

            if (
                !$isProtectedSuperAdmin
                && $editingFullPrivilegeRole
            ) {
                $errors[] =
                    'Only the protected Super Admin may edit an all-permissions role.';
            }

            $roleName = post_value('role_name');
            $roleDescription = post_value('role_description');
            $useDisplayColor = post_value('use_display_color') === '1';
            $displayColor = $useDisplayColor ? post_value('display_color') : '';
            $isStaff = post_value('is_staff') === '1' ? 1 : 0;
            $isActive = post_value('is_active') === '1' ? 1 : 0;

            if ($roleName === '') {
                $errors[] = 'Enter a role/group name.';
            } elseif (admin_roles_text_length($roleName) > 100) {
                $errors[] = 'The role/group name cannot exceed 100 characters.';
            }

            if (admin_roles_text_length($roleDescription) > 5000) {
                $errors[] = 'The role/group description is too long.';
            }

            if ($useDisplayColor && !admin_roles_valid_color($displayColor)) {
                $errors[] = 'Choose a valid display color.';
            }

            if ($useDisplayColor && $displayColor === '') {
                $errors[] = 'Select a role color or turn off Use Role Color.';
            }

            $duplicateStatement = $pdo->prepare(
                'SELECT id
                 FROM roles
                 WHERE name = :name
                   AND id <> :role_id
                 LIMIT 1'
            );
            $duplicateStatement->execute([
                'name' => $roleName,
                'role_id' => $roleId,
            ]);

            if ($duplicateStatement->fetchColumn() !== false) {
                $errors[] = 'Another role/group already uses that name.';
            }

            $grantsAllPermissions =
                (int) $existingRole['grants_all_permissions'];

            if ($isProtectedSuperAdmin) {
                $grantsAllPermissions =
                    post_value('grants_all_permissions') === '1' ? 1 : 0;
            }

            if ($errors === []) {
                try {
                    $updateStatement = $pdo->prepare(
                        'UPDATE roles
                         SET
                            name = :name,
                            description = :description,
                            display_color = :display_color,
                            grants_all_permissions = :grants_all_permissions,
                            is_staff = :is_staff,
                            is_active = :is_active
                         WHERE id = :role_id'
                    );

                    $updateStatement->execute([
                        'name' => $roleName,
                        'description' => $roleDescription !== '' ? $roleDescription : null,
                        'display_color' => $displayColor !== '' ? $displayColor : null,
                        'grants_all_permissions' => $grantsAllPermissions,
                        'is_staff' => $isStaff,
                        'is_active' => $isActive,
                        'role_id' => $roleId,
                    ]);

                    admin_roles_audit(
                        $pdo,
                        $currentUserId,
                        'role_update',
                        $roleId,
                        'Updated role/group settings: ' . $roleName
                    );

                    set_flash('success', 'Role/group settings updated.');
                    redirect(url('admin/roles.php?edit_role=' . $roleId));
                } catch (PDOException $exception) {
                    error_log(
                        'Blackthorne role update error: ' . $exception->getMessage()
                    );

                    $errors[] = 'The role/group settings could not be saved.';
                }
            }
        }
    }


    /*
    |----------------------------------------------------------------------
    | Save Role Permission Matrix
    |----------------------------------------------------------------------
    */

    if ($formAction === 'save_role_permissions') {
        if (!$canManageRolePermissions) {
            http_response_code(403);
            exit('You do not have permission to manage role permissions.');
        }

        $roleIdValue = post_value('role_id');
        $roleId = ctype_digit($roleIdValue) ? (int) $roleIdValue : 0;

        $roleStatement = $pdo->prepare(
            'SELECT
                id,
                name,
                grants_all_permissions
             FROM roles
             WHERE id = :role_id
             LIMIT 1'
        );

        $roleStatement->execute([
            'role_id' =>
                $roleId,
        ]);

        $existingRole =
            $roleStatement->fetch(
                PDO::FETCH_ASSOC
            );

        if (!$existingRole) {
            $errors[] =
                'The selected role/group could not be found.';
        }

        $editingOwnRole =
            isset(
                $currentUserRoleIdMap[
                    $roleId
                ]
            );

        $editingFullPrivilegeRole =
            $existingRole
            && (int) (
                $existingRole[
                    'grants_all_permissions'
                ]
                ?? 0
            ) === 1;

        if (
            !$isProtectedSuperAdmin
            && $editingOwnRole
        ) {
            $errors[] =
                'You cannot change permissions for a role/group assigned to your own account.';
        }

        if (
            !$isProtectedSuperAdmin
            && $editingFullPrivilegeRole
        ) {
            $errors[] =
                'Only the protected Super Admin may change permissions for an all-permissions role.';
        }

        $selectedPermissionIds =
            admin_roles_post_ids(
                'permission_ids'
            );

        $selectedPermissionIds =
            array_values(
                array_filter(
                    $selectedPermissionIds,
                    static fn (int $permissionId): bool =>
                        isset(
                            $validPermissionIds[
                                $permissionId
                            ]
                        )
                )
            );

        if (
            !$isProtectedSuperAdmin
            && $errors === []
        ) {
            foreach (
                $selectedPermissionIds
                as $selectedPermissionId
            ) {
                if (
                    !isset(
                        $grantablePermissionIdMap[
                            $selectedPermissionId
                        ]
                    )
                ) {
                    $errors[] =
                        'You cannot grant a permission that your own account does not have.';
                    break;
                }
            }
        }

        if ($errors === []) {
            try {
                $pdo->beginTransaction();

                /*
                 * Super Admin may replace the entire matrix.
                 *
                 * Other authorized staff may change only permissions that they
                 * personally possess. Permissions outside their authority are
                 * preserved exactly as they are.
                 */
                if ($isProtectedSuperAdmin) {
                    $deleteStatement =
                        $pdo->prepare(
                            'DELETE FROM role_permissions
                             WHERE role_id = :role_id'
                        );

                    $deleteStatement->execute([
                        'role_id' =>
                            $roleId,
                    ]);

                } else {
                    if ($grantablePermissionIds !== []) {
                        $grantablePlaceholders =
                            implode(
                                ',',
                                array_fill(
                                    0,
                                    count(
                                        $grantablePermissionIds
                                    ),
                                    '?'
                                )
                            );

                        $deleteSql =
                            'DELETE FROM role_permissions
                             WHERE role_id = ?
                               AND permission_id IN ('
                            . $grantablePlaceholders
                            . ')';

                        $deleteStatement =
                            $pdo->prepare(
                                $deleteSql
                            );

                        $deleteStatement->execute(
                            array_merge(
                                [
                                    $roleId,
                                ],
                                $grantablePermissionIds
                            )
                        );
                    }
                }

                $insertPermissionIds =
                    $isProtectedSuperAdmin
                        ? $selectedPermissionIds
                        : array_values(
                            array_filter(
                                $selectedPermissionIds,
                                static fn (
                                    int $permissionId
                                ): bool =>
                                    isset(
                                        $grantablePermissionIdMap[
                                            $permissionId
                                        ]
                                    )
                            )
                        );

                if ($insertPermissionIds !== []) {
                    $insertStatement =
                        $pdo->prepare(
                            'INSERT INTO role_permissions (
                                role_id,
                                permission_id,
                                is_allowed
                             ) VALUES (
                                :role_id,
                                :permission_id,
                                1
                             )'
                        );

                    foreach (
                        $insertPermissionIds
                        as $permissionId
                    ) {
                        $insertStatement->execute([
                            'role_id' =>
                                $roleId,

                            'permission_id' =>
                                $permissionId,
                        ]);
                    }
                }

                admin_roles_audit(
                    $pdo,
                    $currentUserId,
                    'role_permissions_update',
                    $roleId,
                    'Updated permissions for role/group: '
                    . (string) $existingRole['name']
                );

                $pdo->commit();

                set_flash(
                    'success',
                    'Role/group permissions updated.'
                );

                redirect(
                    url(
                        'admin/roles.php?edit_role='
                        . $roleId
                    )
                );

            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'Blackthorne role permission update error: '
                    . $exception->getMessage()
                );

                $errors[] =
                    'The role/group permissions could not be saved.';
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| Current Roles / Groups
|--------------------------------------------------------------------------
*/

$rolesStatement = $pdo->query(
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
        COUNT(DISTINCT CASE
            WHEN ur.is_active = 1
             AND ur.revoked_at IS NULL
             AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
            THEN ur.user_id
            ELSE NULL
        END) AS active_member_count,
        COUNT(DISTINCT CASE
            WHEN rp.is_allowed = 1
            THEN rp.permission_id
            ELSE NULL
        END) AS permission_count
     FROM roles r
     LEFT JOIN user_roles ur
        ON ur.role_id = r.id
     LEFT JOIN role_permissions rp
        ON rp.role_id = r.id
     GROUP BY
        r.id,
        r.name,
        r.slug,
        r.description,
        r.display_color,
        r.is_system_role,
        r.grants_all_permissions,
        r.is_staff,
        r.is_active,
        r.sort_order
     ORDER BY r.sort_order ASC, r.name ASC'
);

$roles = $rolesStatement->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Selected Role / Group
|--------------------------------------------------------------------------
*/

$editRoleId = admin_roles_query_id('edit_role');
$editRole = null;
$editRolePermissionIds = [];

if ($editRoleId > 0) {
    $editRoleStatement = $pdo->prepare(
        'SELECT *
         FROM roles
         WHERE id = :role_id
         LIMIT 1'
    );
    $editRoleStatement->execute(['role_id' => $editRoleId]);
    $editRole = $editRoleStatement->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($editRole !== null) {
        $rolePermissionStatement = $pdo->prepare(
            'SELECT permission_id
             FROM role_permissions
             WHERE role_id = :role_id
               AND is_allowed = 1'
        );
        $rolePermissionStatement->execute(['role_id' => $editRoleId]);

        $editRolePermissionIds = array_map(
            'intval',
            $rolePermissionStatement->fetchAll(PDO::FETCH_COLUMN)
        );
    }
}


$editRoleIsCurrentUsersRole =
    $editRole !== null
    && isset(
        $currentUserRoleIdMap[
            (int) $editRole['id']
        ]
    );

$editRoleIsFullPrivilege =
    $editRole !== null
    && (int) (
        $editRole[
            'grants_all_permissions'
        ]
        ?? 0
    ) === 1;

$canEditSelectedRoleDetails =
    $canEditRoles
    && (
        $isProtectedSuperAdmin
        || (
            !$editRoleIsCurrentUsersRole
            && !$editRoleIsFullPrivilege
        )
    );

$canEditSelectedRolePermissions =
    $canManageRolePermissions
    && (
        $isProtectedSuperAdmin
        || (
            !$editRoleIsCurrentUsersRole
            && !$editRoleIsFullPrivilege
        )
    );


$successMessage = get_flash('success');


/*
|--------------------------------------------------------------------------
| Page Metadata
|--------------------------------------------------------------------------
*/

$pageTitle = 'Roles & Permissions | Blackthorne Academy';
$pageDescription = 'Create and manage Blackthorne Academy roles, staff groups, and permission assignments.';
$pageCanonical = url('admin/roles.php');
$robots = 'noindex, nofollow';

require INCLUDES_PATH . '/header.php';

?>

<main id="main-content" class="forum-admin-page roles-admin-page">

    <section class="forum-admin-hero" aria-labelledby="roles-admin-heading">
        <div class="section-inner">
            <p class="academy-overline">Academy Administration</p>
            <h1 id="roles-admin-heading">Roles, Groups &amp; Permissions</h1>
            <p>
                Create custom Academy groups, designate staff positions, and
                control exactly which tools each role is allowed to use.
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


            <?php if ($canCreateRoles): ?>
            <section class="forum-admin-actions" aria-labelledby="create-role-heading">
                <div>
                    <p class="academy-overline">New Role / Group</p>
                    <h2 id="create-role-heading">Create a Custom Group</h2>
                    <p>
                        The slug is generated automatically from the name.
                        Permissions can be selected immediately after creation.
                    </p>
                </div>

                <form action="<?= e(url('admin/roles.php')); ?>" method="post" class="forum-admin-form">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="form_action" value="create_role">

                    <div class="forum-admin-form-grid">
                        <div class="form-group">
                            <label for="role-name">Role / Group Name</label>
                            <input class="form-control" id="role-name" type="text" name="role_name" maxlength="100"
                                value="<?= e(post_value('role_name')); ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="forum-admin-choice">
                                <input type="checkbox" name="use_display_color" value="1"
                                    data-role-color-toggle="create"
                                    <?= post_value('use_display_color') === '1' ? 'checked' : ''; ?>>
                                <span>
                                    <strong>Use Role Color</strong><br>
                                    Give this role/group a custom display color.
                                </span>
                            </label>

                            <div data-role-color-picker="create"
                                <?= post_value('use_display_color') === '1' ? '' : 'hidden'; ?>>
                                <label for="role-color">Role Color</label>
                                <input class="form-control" id="role-color" type="color" name="display_color"
                                    value="<?= e(admin_roles_valid_color(post_value('display_color')) && post_value('display_color') !== '' ? post_value('display_color') : '#744081'); ?>"
                                    <?= post_value('use_display_color') === '1' ? '' : 'disabled'; ?>>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="role-description">Description</label>
                        <textarea class="form-control" id="role-description" name="role_description" rows="3"
                            maxlength="5000"><?= e(post_value('role_description')); ?></textarea>
                    </div>

                    <fieldset class="forum-admin-fieldset">
                        <legend>Role / Group Status</legend>

                        <label class="forum-admin-choice">
                            <input type="checkbox" name="is_staff" value="1"
                                <?= post_value('is_staff') === '1' ? 'checked' : ''; ?>>
                            <span>
                                <strong>Set as Staff</strong><br>
                                Identifies members of this group as Academy staff.
                                Staff status alone does not grant permissions.
                            </span>
                        </label>

                        <label class="forum-admin-choice">
                            <input type="checkbox" name="is_active" value="1"
                                <?= !is_post() || post_value('is_active') === '1' ? 'checked' : ''; ?>>
                            <span>
                                <strong>Active</strong><br>
                                Allows this role/group to participate in permission resolution.
                            </span>
                        </label>

                        <?php if ($isProtectedSuperAdmin): ?>
                        <label class="forum-admin-choice">
                            <input type="checkbox" name="grants_all_permissions" value="1"
                                <?= post_value('grants_all_permissions') === '1' ? 'checked' : ''; ?>>
                            <span>
                                <strong>Grant All Permissions</strong><br>
                                Gives this role every normal site permission.
                                Use only for highly trusted administrative groups.
                            </span>
                        </label>
                        <?php endif; ?>
                    </fieldset>

                    <button type="submit" class="button button-primary">
                        Create Role / Group
                    </button>
                </form>
            </section>
            <?php endif; ?>


            <?php if ($editRole !== null): ?>
            <section class="forum-structure-panel" aria-labelledby="edit-role-heading">
                <header class="forum-admin-titlebar">
                    <p class="forum-admin-step">Edit Role / Group</p>
                    <h2 id="edit-role-heading">
                        <?= e((string) $editRole['name']); ?>
                    </h2>
                </header>

                <?php if ($canEditSelectedRoleDetails): ?>
                <form action="<?= e(url('admin/roles.php?edit_role=' . (int) $editRole['id'])); ?>" method="post"
                    class="forum-admin-form">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="form_action" value="update_role">
                    <input type="hidden" name="role_id" value="<?= (int) $editRole['id']; ?>">

                    <div class="forum-admin-form-grid">
                        <div class="form-group">
                            <label for="edit-role-name">Role / Group Name</label>
                            <input class="form-control" id="edit-role-name" type="text" name="role_name" maxlength="100"
                                value="<?= e((string) $editRole['name']); ?>" required>
                        </div>

                        <div class="form-group">
                            <?php $editRoleUsesColor = trim((string) ($editRole['display_color'] ?? '')) !== ''; ?>
                            <label class="forum-admin-choice">
                                <input type="checkbox" name="use_display_color" value="1" data-role-color-toggle="edit"
                                    <?= $editRoleUsesColor ? 'checked' : ''; ?>>
                                <span>
                                    <strong>Use Role Color</strong><br>
                                    Give this role/group a custom display color.
                                </span>
                            </label>

                            <div data-role-color-picker="edit" <?= $editRoleUsesColor ? '' : 'hidden'; ?>>
                                <label for="edit-role-color">Role Color</label>
                                <input class="form-control" id="edit-role-color" type="color" name="display_color"
                                    value="<?= e(admin_roles_valid_color((string) ($editRole['display_color'] ?? '')) && (string) ($editRole['display_color'] ?? '') !== '' ? (string) $editRole['display_color'] : '#744081'); ?>"
                                    <?= $editRoleUsesColor ? '' : 'disabled'; ?>>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Slug</label>
                        <input class="form-control" type="text" value="<?= e((string) $editRole['slug']); ?>" readonly>
                        <p class="form-help">
                            The slug is permanent so existing role assignments and access rules remain stable.
                        </p>
                    </div>

                    <div class="form-group">
                        <label for="edit-role-description">Description</label>
                        <textarea class="form-control" id="edit-role-description" name="role_description" rows="3"
                            maxlength="5000"><?= e((string) ($editRole['description'] ?? '')); ?></textarea>
                    </div>

                    <fieldset class="forum-admin-fieldset">
                        <legend>Role / Group Status</legend>

                        <label class="forum-admin-choice">
                            <input type="checkbox" name="is_staff" value="1"
                                <?= (int) $editRole['is_staff'] === 1 ? 'checked' : ''; ?>>
                            <span>
                                <strong>Set as Staff</strong><br>
                                Members assigned this role are recognized as Academy staff.
                            </span>
                        </label>

                        <label class="forum-admin-choice">
                            <input type="checkbox" name="is_active" value="1"
                                <?= (int) $editRole['is_active'] === 1 ? 'checked' : ''; ?>>
                            <span>
                                <strong>Active</strong><br>
                                Inactive roles remain stored but do not grant permissions.
                            </span>
                        </label>

                        <?php if ($isProtectedSuperAdmin): ?>
                        <label class="forum-admin-choice">
                            <input type="checkbox" name="grants_all_permissions" value="1"
                                <?= (int) $editRole['grants_all_permissions'] === 1 ? 'checked' : ''; ?>>
                            <span>
                                <strong>Grant All Permissions</strong><br>
                                This role bypasses the normal permission matrix.
                            </span>
                        </label>
                        <?php endif; ?>
                    </fieldset>

                    <?php if ((int) $editRole['is_system_role'] === 1): ?>
                    <div class="form-message form-message-info">
                        This is a protected system role. It can be configured,
                        but it should not be treated as a disposable custom role.
                    </div>
                    <?php endif; ?>

                    <div class="forum-admin-edit-actions">
                        <button type="submit" class="button button-primary">
                            Save Role Settings
                        </button>
                        <a href="<?= e(url('admin/roles.php')); ?>" class="button button-secondary">
                            Close Editor
                        </a>
                    </div>
                </form>
                <?php else: ?>

                <div class="forum-admin-empty">
                    <?php if ($editRoleIsCurrentUsersRole): ?>
                    You cannot edit the settings of a role/group assigned to your own account.
                    <?php elseif ($editRoleIsFullPrivilege): ?>
                    Only the protected Super Admin may edit this all-permissions role.
                    <?php else: ?>
                    You can view this role/group, but you do not have permission to edit its settings.
                    <?php endif; ?>
                </div>

                <?php endif; ?>


                <header class="forum-admin-titlebar">
                    <p class="forum-admin-step">Permission Matrix</p>
                    <h2>Allowed Capabilities</h2>
                </header>

                <?php if ((int) $editRole['grants_all_permissions'] === 1): ?>
                <div class="form-message form-message-success" role="status">
                    This role currently grants all normal permissions. The boxes below
                    may still be configured, but the all-permissions setting takes precedence.
                </div>
                <?php endif; ?>

                <?php if ($canEditSelectedRolePermissions): ?>
                <form action="<?= e(url('admin/roles.php?edit_role=' . (int) $editRole['id'])); ?>" method="post"
                    class="forum-admin-form">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="form_action" value="save_role_permissions">
                    <input type="hidden" name="role_id" value="<?= (int) $editRole['id']; ?>">

                    <?php foreach ($permissionsByCategory as $category => $categoryPermissions): ?>
                    <fieldset class="forum-admin-fieldset">
                        <legend><?= e($category); ?></legend>

                        <?php foreach ($categoryPermissions as $permission): ?>
                        <?php
                                        $permissionId = (int) $permission['id'];
                                        $isChecked = in_array(
                                            $permissionId,
                                            $editRolePermissionIds,
                                            true
                                        );
                                        ?>

                        <?php
                                        $permissionSlug =
                                            trim(
                                                (string) (
                                                    $permission['slug']
                                                    ?? ''
                                                )
                                            );

                                        $permissionIsProtectedAuthority =
                                            !$isProtectedSuperAdmin
                                            && in_array(
                                                $permissionSlug,
                                                [
                                                    'users.permissions.manage',
                                                    'roles.permissions.manage',
                                                    'roles.assign',
                                                ],
                                                true
                                            );

                                        $actorCanGrantPermission =
                                            $isProtectedSuperAdmin
                                            || isset(
                                                $grantablePermissionIdMap[
                                                    $permissionId
                                                ]
                                            );
                                        ?>

                        <label class="forum-admin-choice">
                            <input type="checkbox" name="permission_ids[]" value="<?= $permissionId; ?>"
                                <?= $isChecked ? 'checked' : ''; ?> <?= $actorCanGrantPermission ? '' : 'disabled'; ?>>
                            <span>
                                <strong><?= e((string) $permission['name']); ?></strong>
                                <br>
                                <?php if (trim((string) ($permission['description'] ?? '')) !== ''): ?>
                                <?= e((string) $permission['description']); ?>
                                <br>
                                <?php endif; ?>
                                <small><?= e((string) $permission['slug']); ?></small>

                                <?php if (!$actorCanGrantPermission): ?>
                                <br>
                                <small>
                                    <?php if ($permissionIsProtectedAuthority): ?>
                                    Locked: only the protected Super Admin may delegate this authority.
                                    <?php else: ?>
                                    Locked: your account does not have this permission.
                                    <?php endif; ?>
                                </small>
                                <?php endif; ?>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </fieldset>
                    <?php endforeach; ?>

                    <button type="submit" class="button button-primary">
                        Save Permissions
                    </button>
                </form>
                <?php else: ?>
                <div class="forum-admin-empty">
                    <?php if ($editRoleIsCurrentUsersRole): ?>
                    You cannot change permissions for a role/group assigned to your own account.
                    <?php elseif ($editRoleIsFullPrivilege): ?>
                    Only the protected Super Admin may change permissions for this all-permissions role.
                    <?php else: ?>
                    You can view this role/group, but you do not have permission
                    to change its capability assignments.
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </section>
            <?php endif; ?>


            <section class="forum-structure-panel" aria-labelledby="roles-list-heading">
                <header class="forum-admin-titlebar">
                    <p class="forum-admin-step">Current Configuration</p>
                    <h2 id="roles-list-heading">Roles &amp; Groups</h2>
                </header>

                <?php if ($roles === []): ?>
                <div class="forum-admin-empty">
                    No roles or groups have been created yet.
                </div>
                <?php else: ?>
                <div class="forum-structure-list">
                    <?php foreach ($roles as $role): ?>
                    <?php
                            $roleId = (int) $role['id'];
                            $roleColor = (string) ($role['display_color'] ?? '');
                            $roleNameStyle = admin_roles_valid_color($roleColor) && $roleColor !== ''
                                ? ' style="color:' . e($roleColor) . ';"'
                                : '';
                            ?>

                    <article class="forum-structure-category">
                        <header>
                            <div>
                                <h3<?= $roleNameStyle; ?>>
                                    <?= e((string) $role['name']); ?>
                                    </h3>
                                    <p>
                                        <?= e((string) ($role['description'] ?? 'No description provided.')); ?>
                                    </p>
                                    <p class="form-help">
                                        Slug: <?= e((string) $role['slug']); ?>
                                    </p>
                            </div>

                            <div class="forum-structure-badges">
                                <?php if ((int) $role['is_system_role'] === 1): ?>
                                <span>System Role</span>
                                <?php endif; ?>

                                <?php if ((int) $role['is_staff'] === 1): ?>
                                <span>Staff</span>
                                <?php endif; ?>

                                <?php if ((int) $role['grants_all_permissions'] === 1): ?>
                                <span>All Permissions</span>
                                <?php else: ?>
                                <span>
                                    <?= number_format((int) $role['permission_count']); ?> permissions
                                </span>
                                <?php endif; ?>

                                <span>
                                    <?= number_format((int) $role['active_member_count']); ?> active members
                                </span>

                                <span>
                                    <?= (int) $role['is_active'] === 1 ? 'Active' : 'Inactive'; ?>
                                </span>

                                <a class="button button-secondary forum-structure-edit"
                                    href="<?= e(url('admin/roles.php?edit_role=' . $roleId)); ?>">
                                    Edit
                                </a>
                            </div>
                        </header>
                    </article>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </section>

        </div>
    </section>

</main>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('[data-role-color-toggle]').forEach(function(toggle) {
            var key = toggle.getAttribute('data-role-color-toggle');
            var pickerWrap = document.querySelector('[data-role-color-picker="' + key + '"]');

            if (!pickerWrap) {
                return;
            }

            var colorInput = pickerWrap.querySelector('input[type="color"]');

            function syncRoleColor() {
                var enabled = toggle.checked;
                pickerWrap.hidden = !enabled;

                if (colorInput) {
                    colorInput.disabled = !enabled;
                }
            }

            toggle.addEventListener('change', syncRoleColor);
            syncRoleColor();
        });
    });

</script>

<?php require INCLUDES_PATH . '/footer.php'; ?>
