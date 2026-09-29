<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

require_active_account();


/*
|--------------------------------------------------------------------------
| Page Access
|--------------------------------------------------------------------------
*/

$isProtectedSuperAdmin = current_user_is_superuser();

$canViewUsers =
    $isProtectedSuperAdmin
    || user_can('users.view')
    || user_can('users.manage')
    || user_can('roles.assign')
    || user_can('users.permissions.manage');

if (!$canViewUsers) {
    http_response_code(403);
    exit('You do not have permission to access User Management.');
}

$canAssignRoles =
    $isProtectedSuperAdmin
    || user_can('roles.assign');

$canManageUserPermissions =
    $isProtectedSuperAdmin;

/*
 * Full-privilege/Admin roles are protected at the highest level.
 *
 * A member may have roles.assign and may manage ordinary role memberships,
 * but ONLY the protected Super Admin account may assign, remove, expire, or
 * otherwise alter a role where grants_all_permissions = 1.
 *
 * This is deliberately based on the protected Super Admin identity rather
 * than another role or permission, so role-management access can never be
 * used to promote oneself into an Admin-level role.
 */
$canAssignAdminRole =
    $isProtectedSuperAdmin;

$currentUserId = (int) (current_user_id() ?? 0);


/*
|--------------------------------------------------------------------------
| Local Helpers
|--------------------------------------------------------------------------
*/

function admin_users_query_id(string $key): int
{
    $value = $_GET[$key] ?? '';

    if (!is_scalar($value) || !ctype_digit((string) $value)) {
        return 0;
    }

    return max(0, (int) $value);
}


function admin_users_valid_color(string $value): bool
{
    return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1;
}


function admin_users_search_value(): string
{
    $value = $_GET['q'] ?? '';

    if (!is_scalar($value)) {
        return '';
    }

    return trim((string) $value);
}


function admin_users_parse_expiration(string $value): ?string
{
    $value = trim($value);

    if ($value === '') {
        return null;
    }

    $date = DateTime::createFromFormat('Y-m-d', $value);

    if (
        !$date
        || $date->format('Y-m-d') !== $value
    ) {
        return null;
    }

    /*
     * A role expiration date remains valid through the selected calendar day.
     * Store the expiration at the end of that day in the site/database timezone.
     */
    return $value . ' 23:59:59';
}


function admin_users_audit(
    PDO $pdo,
    int $actorUserId,
    string $actionType,
    int $targetUserId,
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
            'user_id' => $actorUserId,
            'action_type' => $actionType,
            'entity_type' => 'user',
            'entity_id' => $targetUserId,
            'description' => $description,
            'ip_address' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        ]);
    } catch (PDOException $exception) {
        error_log(
            'Blackthorne user-role audit log error: '
            . $exception->getMessage()
        );
    }
}


/*
|--------------------------------------------------------------------------
| Available Roles / Groups
|--------------------------------------------------------------------------
*/

$rolesStatement = $pdo->query(
    'SELECT
        id,
        name,
        slug,
        description,
        display_color,
        is_system_role,
        grants_all_permissions,
        is_staff,
        is_active,
        sort_order
     FROM roles
     WHERE is_active = 1
     ORDER BY sort_order ASC, name ASC, id ASC'
);

$availableRoles = $rolesStatement->fetchAll(PDO::FETCH_ASSOC);

$rolesById = [];

foreach ($availableRoles as $role) {
    $rolesById[(int) $role['id']] = $role;
}


/*
|--------------------------------------------------------------------------
| Available Individual Permissions
|--------------------------------------------------------------------------
*/

$permissionsStatement = $pdo->query(
    'SELECT
        id,
        name,
        slug,
        category,
        description,
        sort_order
     FROM permissions
     WHERE is_active = 1
     ORDER BY category ASC, sort_order ASC, name ASC, id ASC'
);

$availablePermissions =
    $permissionsStatement->fetchAll(PDO::FETCH_ASSOC);

$permissionsById = [];
$permissionsByCategory = [];

foreach ($availablePermissions as $permission) {
    $permissionId =
        (int) $permission['id'];

    $permissionsById[$permissionId] =
        $permission;

    $category =
        trim(
            (string) (
                $permission['category']
                ?? ''
            )
        );

    if ($category === '') {
        $category =
            'Other';
    }

    $permissionsByCategory[$category][] =
        $permission;
}


/*
|--------------------------------------------------------------------------
| Direct Override Security Scope
|--------------------------------------------------------------------------
|
| Individual overrides are powerful because they take precedence over normal
| role permissions. A staff member who may manage overrides must never be able
| to use that interface to increase their own authority.
|
| Non-Super-Admins:
| - may never edit their own individual permission overrides
| - may only grant/deny permissions that their own account actually possesses
| - may not directly grant escalation-sensitive permission-management powers
|
*/

$protectedOverridePermissionSlugs = [
    'users.permissions.manage',
    'roles.permissions.manage',
    'roles.assign',
];

$actorManageablePermissionIds = [];

foreach ($availablePermissions as $permission) {
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
    ) {
        continue;
    }

    if ($isProtectedSuperAdmin) {
        $actorManageablePermissionIds[$permissionId] =
            true;

        continue;
    }

    if (
        in_array(
            $permissionSlug,
            $protectedOverridePermissionSlugs,
            true
        )
    ) {
        continue;
    }

    if (user_can($permissionSlug)) {
        $actorManageablePermissionIds[$permissionId] =
            true;
    }
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

    if ($formAction === 'save_user_roles') {
        if (!$canAssignRoles) {
            http_response_code(403);
            exit('You do not have permission to assign user roles.');
        }

        $targetUserIdRaw = post_value('user_id');

        if (
            $targetUserIdRaw === ''
            || !ctype_digit($targetUserIdRaw)
        ) {
            $errors[] = 'The selected user is invalid.';
            $targetUserId = 0;
        } else {
            $targetUserId = (int) $targetUserIdRaw;
        }


        $targetUser = null;

        if ($targetUserId > 0) {
            $targetStatement = $pdo->prepare(
                'SELECT
                    id,
                    username,
                    display_name,
                    email,
                    status,
                    email_verified_at
                 FROM users
                 WHERE id = :user_id
                 LIMIT 1'
            );

            $targetStatement->execute([
                'user_id' => $targetUserId,
            ]);

            $targetUser =
                $targetStatement->fetch(PDO::FETCH_ASSOC)
                ?: null;

            if ($targetUser === null) {
                $errors[] = 'The selected user could not be found.';
            }
        }


        $selectedRoleIds = $_POST['role_ids'] ?? [];

        if (!is_array($selectedRoleIds)) {
            $selectedRoleIds = [];
        }

        $cleanSelectedRoleIds = [];

        foreach ($selectedRoleIds as $selectedRoleId) {
            if (
                !is_scalar($selectedRoleId)
                || !ctype_digit((string) $selectedRoleId)
            ) {
                continue;
            }

            $roleId = (int) $selectedRoleId;

            if (isset($rolesById[$roleId])) {
                $cleanSelectedRoleIds[$roleId] = $roleId;
            }
        }

        $selectedRoleIds = array_values($cleanSelectedRoleIds);


        /*
        |------------------------------------------------------------------
        | Protect Admin-Role Assignments
        |------------------------------------------------------------------
        |
        | Staff with roles.assign may manage ordinary roles for anyone,
        | including users who are themselves Admins or the protected Super
        | Admin. The restriction is on the full-privilege role itself.
        |
        | For everyone except the protected Super Admin:
        | - Full-privilege/Admin roles are not accepted from POST.
        | - Any such role already assigned to the target is preserved.
        | - This also prevents self-promotion through User Management.
        |
        */

        if (
            !$canAssignAdminRole
            && $targetUser !== null
        ) {
            $currentAdminRoleStatement = $pdo->prepare(
                'SELECT DISTINCT ur.role_id
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
                   AND r.grants_all_permissions = 1'
            );

            $currentAdminRoleStatement->execute([
                'user_id' => $targetUserId,
            ]);

            $currentAdminRoleIds = array_map(
                'intval',
                $currentAdminRoleStatement->fetchAll(PDO::FETCH_COLUMN)
            );


            foreach ($selectedRoleIds as $roleId) {
                if (
                    isset($rolesById[$roleId])
                    && (int) $rolesById[$roleId]['grants_all_permissions'] === 1
                    && !in_array($roleId, $currentAdminRoleIds, true)
                ) {
                    $errors[] =
                        'Only the protected Super Admin may assign or remove an Admin-level role.';
                    break;
                }
            }


            $editableSelectedRoleIds = [];

            foreach ($selectedRoleIds as $roleId) {
                if (
                    isset($rolesById[$roleId])
                    && (int) $rolesById[$roleId]['grants_all_permissions'] === 1
                ) {
                    continue;
                }

                $editableSelectedRoleIds[] = $roleId;
            }

            $selectedRoleIds = array_values(
                array_unique(
                    array_merge(
                        $editableSelectedRoleIds,
                        $currentAdminRoleIds
                    )
                )
            );
        }


        $expirationValues = $_POST['role_expires'] ?? [];

        if (!is_array($expirationValues)) {
            $expirationValues = [];
        }


        $roleExpirations = [];

        foreach ($selectedRoleIds as $roleId) {
            $rawExpiration = $expirationValues[(string) $roleId]
                ?? $expirationValues[$roleId]
                ?? '';

            if (!is_scalar($rawExpiration)) {
                $rawExpiration = '';
            }

            $rawExpiration = trim((string) $rawExpiration);

            if ($rawExpiration === '') {
                $roleExpirations[$roleId] = null;
                continue;
            }

            $parsedExpiration =
                admin_users_parse_expiration($rawExpiration);

            if ($parsedExpiration === null) {
                $roleName =
                    (string) ($rolesById[$roleId]['name'] ?? 'role');

                $errors[] =
                    'Enter a valid expiration date for '
                    . $roleName
                    . '.';

                continue;
            }

            $roleExpirations[$roleId] =
                $parsedExpiration;
        }


        if (
            $errors === []
            && $targetUser !== null
        ) {
            try {
                $pdo->beginTransaction();

                /*
                |----------------------------------------------------------
                | Find Current Active Assignments
                |----------------------------------------------------------
                */

                $activeStatement = $pdo->prepare(
                    'SELECT
                        id,
                        role_id,
                        expires_at
                     FROM user_roles
                     WHERE user_id = :user_id
                       AND is_active = 1
                       AND revoked_at IS NULL'
                );

                $activeStatement->execute([
                    'user_id' => $targetUserId,
                ]);

                $activeRows =
                    $activeStatement->fetchAll(PDO::FETCH_ASSOC);

                $activeByRoleId = [];

                foreach ($activeRows as $activeRow) {
                    $activeByRoleId[(int) $activeRow['role_id']][] =
                        $activeRow;
                }


                /*
                |----------------------------------------------------------
                | Revoke Removed Assignments
                |----------------------------------------------------------
                */

                $revokeStatement = $pdo->prepare(
                    'UPDATE user_roles
                     SET
                        is_active = 0,
                        revoked_at = NOW(),
                        revoked_by = :revoked_by,
                        revocation_reason = :revocation_reason
                     WHERE id = :user_role_id
                       AND is_active = 1
                       AND revoked_at IS NULL'
                );


                foreach ($activeByRoleId as $roleId => $rows) {
                    if (in_array($roleId, $selectedRoleIds, true)) {
                        continue;
                    }

                    foreach ($rows as $row) {
                        $revokeStatement->execute([
                            'revoked_by' => $currentUserId,
                            'revocation_reason' =>
                                'Removed through User Management.',
                            'user_role_id' => (int) $row['id'],
                        ]);
                    }
                }


                /*
                |----------------------------------------------------------
                | Add / Update Selected Assignments
                |----------------------------------------------------------
                */

                $updateActiveStatement = $pdo->prepare(
                    'UPDATE user_roles
                     SET
                        expires_at = :expires_at
                     WHERE id = :user_role_id
                       AND is_active = 1
                       AND revoked_at IS NULL'
                );


                $insertStatement = $pdo->prepare(
                    'INSERT INTO user_roles (
                        user_id,
                        role_id,
                        assigned_by,
                        assigned_at,
                        expires_at,
                        revoked_at,
                        revoked_by,
                        revocation_reason,
                        is_active
                     ) VALUES (
                        :user_id,
                        :role_id,
                        :assigned_by,
                        NOW(),
                        :expires_at,
                        NULL,
                        NULL,
                        NULL,
                        1
                     )'
                );


                foreach ($selectedRoleIds as $roleId) {
                    $expiresAt =
                        $roleExpirations[$roleId]
                        ?? null;

                    $existingRows =
                        $activeByRoleId[$roleId]
                        ?? [];

                    if ($existingRows !== []) {
                        /*
                         * If historical mistakes ever created duplicate active
                         * rows for one role, keep the first and revoke extras.
                         */
                        $firstRow = array_shift($existingRows);

                        $updateActiveStatement->execute([
                            'expires_at' => $expiresAt,
                            'user_role_id' => (int) $firstRow['id'],
                        ]);

                        foreach ($existingRows as $duplicateRow) {
                            $revokeStatement->execute([
                                'revoked_by' => $currentUserId,
                                'revocation_reason' =>
                                    'Duplicate active role assignment cleaned up through User Management.',
                                'user_role_id' => (int) $duplicateRow['id'],
                            ]);
                        }

                        continue;
                    }


                    $insertStatement->execute([
                        'user_id' => $targetUserId,
                        'role_id' => $roleId,
                        'assigned_by' =>
                            $currentUserId > 0
                                ? $currentUserId
                                : null,
                        'expires_at' => $expiresAt,
                    ]);
                }


                $pdo->commit();


                $assignedRoleNames = [];

                foreach ($selectedRoleIds as $roleId) {
                    if (isset($rolesById[$roleId])) {
                        $assignedRoleNames[] =
                            (string) $rolesById[$roleId]['name'];
                    }
                }


                $description =
                    'Updated role/group assignments for '
                    . (string) $targetUser['display_name']
                    . '. Active roles: '
                    . (
                        $assignedRoleNames !== []
                            ? implode(', ', $assignedRoleNames)
                            : 'none'
                    )
                    . '.';


                admin_users_audit(
                    $pdo,
                    $currentUserId,
                    'user_roles_updated',
                    $targetUserId,
                    $description
                );


                set_flash(
                    'success',
                    'Roles and groups updated successfully for '
                    . (string) $targetUser['display_name']
                    . '.'
                );


                redirect(
                    url(
                        'admin/users.php?user='
                        . $targetUserId
                    )
                );

            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'Blackthorne user-role update error: '
                    . $exception->getMessage()
                );

                $errors[] =
                    'The role assignments could not be saved. Please try again.';
            }
        }
    }


    elseif ($formAction === 'clear_user_permissions') {

        if (!$isProtectedSuperAdmin) {
            http_response_code(403);

            exit(
                'Only the protected Super Admin may clear individual permission overrides.'
            );
        }

        $targetUserIdRaw =
            post_value('user_id');

        if (
            $targetUserIdRaw === ''
            || !ctype_digit($targetUserIdRaw)
        ) {
            $errors[] =
                'The selected user is invalid.';

            $targetUserId =
                0;

        } else {
            $targetUserId =
                (int) $targetUserIdRaw;
        }


        $targetUser = null;

        if ($targetUserId > 0) {

            $targetStatement =
                $pdo->prepare(
                    'SELECT
                        id,
                        username,
                        display_name,
                        email,
                        status
                     FROM users
                     WHERE id = :user_id
                     LIMIT 1'
                );

            $targetStatement->execute([
                'user_id' =>
                    $targetUserId,
            ]);

            $targetUser =
                $targetStatement->fetch(
                    PDO::FETCH_ASSOC
                )
                ?: null;

            if ($targetUser === null) {
                $errors[] =
                    'The selected user could not be found.';
            }
        }


        if (
            $errors === []
            && $targetUser !== null
            && user_is_protected_super_admin(
                $targetUserId
            )
        ) {
            $errors[] =
                'Individual permission overrides are not applicable to the protected Admin account.';
        }


        if (
            $errors === []
            && $targetUser !== null
        ) {
            try {
                $pdo->beginTransaction();

                $clearStatement =
                    $pdo->prepare(
                        'UPDATE user_permissions
                         SET
                            is_active = 0,
                            revoked_at = NOW(),
                            revoked_by = :revoked_by,
                            revocation_reason = :revocation_reason
                         WHERE user_id = :user_id
                           AND is_active = 1
                           AND revoked_at IS NULL'
                    );

                $clearStatement->execute([
                    'revoked_by' =>
                        $currentUserId,

                    'revocation_reason' =>
                        'Cleared by protected Super Admin through User Management.',

                    'user_id' =>
                        $targetUserId,
                ]);

                $clearedCount =
                    $clearStatement->rowCount();

                admin_users_audit(
                    $pdo,
                    $currentUserId,
                    'user_permissions_cleared',
                    $targetUserId,
                    'Cleared '
                    . $clearedCount
                    . ' individual permission override'
                    . (
                        $clearedCount === 1
                            ? ''
                            : 's'
                    )
                    . ' for '
                    . (string) $targetUser['display_name']
                    . '.'
                );

                $pdo->commit();

                set_flash(
                    'success',
                    $clearedCount > 0
                        ? 'All individual permission overrides were cleared for '
                            . (string) $targetUser['display_name']
                            . '.'
                        : 'No active individual permission overrides were found for '
                            . (string) $targetUser['display_name']
                            . '.'
                );

                redirect(
                    url(
                        'admin/users.php?user='
                        . $targetUserId
                    )
                );

            } catch (Throwable $exception) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'Blackthorne clear user permissions error: '
                    . $exception->getMessage()
                );

                $errors[] =
                    'The individual permission overrides could not be cleared. Please try again.';
            }
        }
    }


    elseif ($formAction === 'save_user_permissions') {
        if (!$canManageUserPermissions) {
            http_response_code(403);
            exit('You do not have permission to manage individual user permissions.');
        }

        $targetUserIdRaw =
            post_value('user_id');

        if (
            $targetUserIdRaw === ''
            || !ctype_digit($targetUserIdRaw)
        ) {
            $errors[] =
                'The selected user is invalid.';

            $targetUserId =
                0;

        } else {
            $targetUserId =
                (int) $targetUserIdRaw;
        }


        $targetUser = null;

        if ($targetUserId > 0) {
            $targetStatement =
                $pdo->prepare(
                    'SELECT
                        id,
                        username,
                        display_name,
                        email,
                        status
                     FROM users
                     WHERE id = :user_id
                     LIMIT 1'
                );

            $targetStatement->execute([
                'user_id' =>
                    $targetUserId,
            ]);

            $targetUser =
                $targetStatement->fetch(PDO::FETCH_ASSOC)
                ?: null;

            if ($targetUser === null) {
                $errors[] =
                    'The selected user could not be found.';
            }
        }


        /*
         * Direct overrides intentionally do not apply to the protected
         * Super Admin, whose protected access always wins.
         */
        if (
            $errors === []
            && $targetUser !== null
            && user_is_protected_super_admin($targetUserId)
        ) {
            $errors[] =
                'Individual permission overrides are not applicable to the protected Admin account.';
        }


        /*
         * Nobody except the protected Super Admin may alter their own direct
         * permission overrides. This prevents self-escalation even when a
         * staff member legitimately has users.permissions.manage.
         */
        if (
            $errors === []
            && !$isProtectedSuperAdmin
            && $targetUserId === $currentUserId
        ) {
            http_response_code(403);

            exit(
                'You cannot change individual permission overrides for your own account.'
            );
        }


        $submittedOverrides =
            $_POST['permission_overrides']
            ?? [];

        if (!is_array($submittedOverrides)) {
            $submittedOverrides = [];
        }


        $cleanOverrides = [];

        foreach (
            $submittedOverrides
            as $permissionIdRaw => $stateRaw
        ) {
            if (
                !is_scalar($permissionIdRaw)
                || !ctype_digit((string) $permissionIdRaw)
                || !is_scalar($stateRaw)
            ) {
                continue;
            }

            $permissionId =
                (int) $permissionIdRaw;

            if (!isset($permissionsById[$permissionId])) {
                continue;
            }

            $state =
                trim((string) $stateRaw);

            if (
                !in_array(
                    $state,
                    [
                        'inherit',
                        'allow',
                        'deny',
                    ],
                    true
                )
            ) {
                continue;
            }

            /*
             * The protected Super Admin may manage every permission.
             * Other staff may only touch permissions inside their own
             * effective authority and never the escalation-sensitive set.
             */
            if (
                !$isProtectedSuperAdmin
                && !isset(
                    $actorManageablePermissionIds[
                        $permissionId
                    ]
                )
            ) {
                $errors[] =
                    'You cannot change an individual permission override outside your own authority.';

                break;
            }

            $cleanOverrides[$permissionId] =
                $state;
        }


        if (
            $errors === []
            && $targetUser !== null
        ) {
            try {
                $pdo->beginTransaction();


                $activeStatement =
                    $pdo->prepare(
                        'SELECT
                            id,
                            permission_id,
                            is_allowed
                         FROM user_permissions
                         WHERE user_id = :user_id
                           AND is_active = 1
                           AND revoked_at IS NULL
                         ORDER BY id DESC'
                    );

                $activeStatement->execute([
                    'user_id' =>
                        $targetUserId,
                ]);

                $activeRows =
                    $activeStatement->fetchAll(
                        PDO::FETCH_ASSOC
                    );

                $activeByPermissionId = [];

                foreach ($activeRows as $activeRow) {
                    $permissionId =
                        (int) $activeRow['permission_id'];

                    $activeByPermissionId[$permissionId][] =
                        $activeRow;
                }


                $revokeStatement =
                    $pdo->prepare(
                        'UPDATE user_permissions
                         SET
                            is_active = 0,
                            revoked_at = NOW(),
                            revoked_by = :revoked_by,
                            revocation_reason = :revocation_reason
                         WHERE id = :user_permission_id
                           AND is_active = 1
                           AND revoked_at IS NULL'
                    );


                $insertStatement =
                    $pdo->prepare(
                        'INSERT INTO user_permissions (
                            user_id,
                            permission_id,
                            is_allowed,
                            assigned_by,
                            assigned_at,
                            revoked_at,
                            revoked_by,
                            revocation_reason,
                            is_active
                         ) VALUES (
                            :user_id,
                            :permission_id,
                            :is_allowed,
                            :assigned_by,
                            NOW(),
                            NULL,
                            NULL,
                            NULL,
                            1
                         )'
                    );


                foreach (
                    $cleanOverrides
                    as $permissionId => $state
                ) {
                    $existingRows =
                        $activeByPermissionId[$permissionId]
                        ?? [];


                    /*
                     * "Use Role Setting" means there should be no active
                     * direct override for this permission.
                     */
                    if ($state === 'inherit') {
                        foreach ($existingRows as $row) {
                            $revokeStatement->execute([
                                'revoked_by' =>
                                    $currentUserId,
                                'revocation_reason' =>
                                    'Returned to role-based permission through User Management.',
                                'user_permission_id' =>
                                    (int) $row['id'],
                            ]);
                        }

                        continue;
                    }


                    $desiredAllowed =
                        $state === 'allow'
                            ? 1
                            : 0;


                    /*
                     * If the newest active row already matches the desired
                     * override, keep it and only clean up duplicate rows.
                     */
                    if ($existingRows !== []) {
                        $primaryRow =
                            array_shift($existingRows);

                        if (
                            (int) $primaryRow['is_allowed']
                            === $desiredAllowed
                        ) {
                            foreach ($existingRows as $duplicateRow) {
                                $revokeStatement->execute([
                                    'revoked_by' =>
                                        $currentUserId,
                                    'revocation_reason' =>
                                        'Duplicate active permission override cleaned up through User Management.',
                                    'user_permission_id' =>
                                        (int) $duplicateRow['id'],
                                ]);
                            }

                            continue;
                        }


                        $rowsToRevoke =
                            array_merge(
                                [
                                    $primaryRow,
                                ],
                                $existingRows
                            );

                        foreach ($rowsToRevoke as $row) {
                            $revokeStatement->execute([
                                'revoked_by' =>
                                    $currentUserId,
                                'revocation_reason' =>
                                    'Replaced by a new individual permission override.',
                                'user_permission_id' =>
                                    (int) $row['id'],
                            ]);
                        }
                    }


                    $insertStatement->execute([
                        'user_id' =>
                            $targetUserId,
                        'permission_id' =>
                            $permissionId,
                        'is_allowed' =>
                            $desiredAllowed,
                        'assigned_by' =>
                            $currentUserId > 0
                                ? $currentUserId
                                : null,
                    ]);
                }


                $pdo->commit();


                admin_users_audit(
                    $pdo,
                    $currentUserId,
                    'user_permissions_updated',
                    $targetUserId,
                    'Updated individual permission overrides for '
                    . (string) $targetUser['display_name']
                    . '.'
                );


                set_flash(
                    'success',
                    'Individual permission overrides updated successfully for '
                    . (string) $targetUser['display_name']
                    . '.'
                );


                redirect(
                    url(
                        'admin/users.php?user='
                        . $targetUserId
                        . '#individual-permissions'
                    )
                );

            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'Blackthorne user-permission update error: '
                    . $exception->getMessage()
                );

                $errors[] =
                    'The individual permission overrides could not be saved. Please try again.';
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| Selected User
|--------------------------------------------------------------------------
*/

$selectedUserId = admin_users_query_id('user');
$selectedUser = null;
$selectedUserRoles = [];
$selectedRoleIds = [];
$selectedRoleExpirations = [];
$selectedPermissionOverrides = [];

if ($selectedUserId > 0) {
    $userStatement = $pdo->prepare(
        'SELECT
            id,
            username,
            display_name,
            email,
            email_verified_at,
            status,
            avatar,
            created_at,
            last_login
         FROM users
         WHERE id = :user_id
         LIMIT 1'
    );

    $userStatement->execute([
        'user_id' => $selectedUserId,
    ]);

    $selectedUser =
        $userStatement->fetch(PDO::FETCH_ASSOC)
        ?: null;


    if ($selectedUser !== null) {
        $userRolesStatement = $pdo->prepare(
            'SELECT
                ur.id AS user_role_id,
                ur.role_id,
                ur.assigned_at,
                ur.expires_at,
                ur.assigned_by,
                r.name,
                r.slug,
                r.description,
                r.display_color,
                r.is_staff,
                r.grants_all_permissions
             FROM user_roles ur
             INNER JOIN roles r
                ON r.id = ur.role_id
             WHERE ur.user_id = :user_id
               AND ur.is_active = 1
               AND ur.revoked_at IS NULL
               AND r.is_active = 1
             ORDER BY r.sort_order ASC, r.name ASC, ur.id ASC'
        );

        $userRolesStatement->execute([
            'user_id' => $selectedUserId,
        ]);

        $selectedUserRoles =
            $userRolesStatement->fetchAll(PDO::FETCH_ASSOC);

        foreach ($selectedUserRoles as $assignedRole) {
            $roleId = (int) $assignedRole['role_id'];

            $selectedRoleIds[$roleId] = true;

            $expiresAt =
                trim((string) ($assignedRole['expires_at'] ?? ''));

            $selectedRoleExpirations[$roleId] =
                $expiresAt !== ''
                    ? substr($expiresAt, 0, 10)
                    : '';
        }


        $userPermissionsStatement =
            $pdo->prepare(
                'SELECT
                    up.id,
                    up.permission_id,
                    up.is_allowed
                 FROM user_permissions up
                 INNER JOIN permissions p
                    ON p.id = up.permission_id
                 WHERE up.user_id = :user_id
                   AND up.is_active = 1
                   AND up.revoked_at IS NULL
                   AND p.is_active = 1
                 ORDER BY up.id DESC'
            );

        $userPermissionsStatement->execute([
            'user_id' =>
                $selectedUserId,
        ]);

        foreach (
            $userPermissionsStatement->fetchAll(PDO::FETCH_ASSOC)
            as $override
        ) {
            $permissionId =
                (int) $override['permission_id'];

            /*
             * If legacy data ever contains duplicate active overrides, use
             * the newest row here. Saving the form will clean duplicates.
             */
            if (isset($selectedPermissionOverrides[$permissionId])) {
                continue;
            }

            $selectedPermissionOverrides[$permissionId] =
                (int) $override['is_allowed'] === 1
                    ? 'allow'
                    : 'deny';
        }
    }
}


/*
|--------------------------------------------------------------------------
| User Search / Listing
|--------------------------------------------------------------------------
*/

$searchValue = admin_users_search_value();

$userSql =
    'SELECT
        u.id,
        u.username,
        u.display_name,
        u.email,
        u.status,
        u.email_verified_at,
        u.created_at,
        COUNT(
            DISTINCT CASE
                WHEN ur.is_active = 1
                 AND ur.revoked_at IS NULL
                 AND (
                    ur.expires_at IS NULL
                    OR ur.expires_at > NOW()
                 )
                THEN ur.role_id
                ELSE NULL
            END
        ) AS active_role_count
     FROM users u
     LEFT JOIN user_roles ur
        ON ur.user_id = u.id';

$userParams = [];

if ($searchValue !== '') {
    $userSql .=
        ' WHERE (
            u.username LIKE :search_username
            OR u.display_name LIKE :search_display_name
            OR u.email LIKE :search_email
        )';

    $searchPattern =
        '%' . $searchValue . '%';

    $userParams['search_username'] =
        $searchPattern;

    $userParams['search_display_name'] =
        $searchPattern;

    $userParams['search_email'] =
        $searchPattern;
}

$userSql .=
    ' GROUP BY
        u.id,
        u.username,
        u.display_name,
        u.email,
        u.status,
        u.email_verified_at,
        u.created_at
      ORDER BY
        u.display_name ASC,
        u.username ASC,
        u.id ASC
      LIMIT 200';


$usersStatement = $pdo->prepare($userSql);
$usersStatement->execute($userParams);

$users =
    $usersStatement->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Protected Super Admin ID
|--------------------------------------------------------------------------
*/

$protectedSuperAdminId = 0;

try {
    $superAdminStatement = $pdo->prepare(
        'SELECT setting_value
         FROM settings
         WHERE setting_key = :setting_key
         LIMIT 1'
    );

    $superAdminStatement->execute([
        'setting_key' => 'super_admin_user_id',
    ]);

    $protectedSuperAdminId =
        (int) ($superAdminStatement->fetchColumn() ?: 0);

} catch (PDOException $exception) {
    error_log(
        'Blackthorne super admin lookup error: '
        . $exception->getMessage()
    );
}


/*
|--------------------------------------------------------------------------
| Flash + Metadata
|--------------------------------------------------------------------------
*/

$successMessage = get_flash('success');

$pageTitle =
    'User Management | Blackthorne Academy';

$pageDescription =
    'Manage Blackthorne Academy members and role/group assignments.';

$pageCanonical =
    url('admin/users.php');

$robots =
    'noindex, nofollow';

require INCLUDES_PATH . '/header.php';

?>

<style>
    /* User Management only: compact directory/search presentation */
    .users-admin-page .forum-admin-hero {
        padding-top: 3rem;
        padding-bottom: 3rem;
    }

    .users-admin-page .forum-admin-hero h1 {
        margin-bottom: 0.75rem;
    }

    .users-admin-page .forum-admin-content {
        padding-top: 2.5rem;
        padding-bottom: 3rem;
    }

    .users-admin-page .forum-admin-actions {
        gap: 2rem;
        padding: 1.5rem;
        align-items: center;
    }

    .users-admin-page .forum-admin-actions h2,
    .users-admin-page .forum-admin-titlebar h2 {
        font-size: clamp(1.35rem, 2vw, 1.7rem);
        margin-bottom: 0.4rem;
    }

    .users-admin-page .forum-admin-actions p {
        margin-bottom: 0;
    }

    .users-admin-page .forum-admin-actions .forum-admin-form {
        gap: 0.75rem;
    }

    .users-admin-page .forum-admin-actions .form-group {
        margin-bottom: 0.65rem;
    }

    .users-admin-page .forum-structure-panel {
        margin-top: 1.5rem;
    }

    .users-admin-page .forum-admin-titlebar {
        padding: 1rem 1.4rem;
    }

    .users-admin-page .forum-structure-list {
        padding: 0 1.4rem;
    }

    .users-admin-page .forum-structure-category {
        padding: 1.1rem 0;
        min-height: 0;
    }

    .users-admin-page .forum-structure-category header {
        gap: 1rem;
        align-items: center;
    }

    .users-admin-page .forum-structure-category h3 {
        font-size: 1.15rem;
        line-height: 1.25;
        margin: 0 0 0.3rem;
    }

    .users-admin-page .forum-structure-category p {
        font-size: 0.86rem;
        line-height: 1.45;
        margin: 0.15rem 0;
    }

    .users-admin-page .forum-structure-category .button {
        min-height: 2.4rem;
        padding: 0.55rem 0.9rem;
        font-size: 0.82rem;
        white-space: nowrap;
    }

    .users-admin-page .forum-admin-fieldset {
        padding: 1rem 1.1rem;
        margin-bottom: 0.85rem;
    }

    .users-admin-page .forum-admin-fieldset legend {
        font-size: 1rem;
    }

    .users-admin-page .user-permission-intro {
        padding: 1rem 1.15rem;
        margin: 0;
        border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    }

    .users-admin-page .permission-override-groups {
        display: grid;
        gap: 0.8rem;
        padding: 1rem 1.4rem 1.35rem;
    }

    .users-admin-page .permission-override-group {
        border: 1px solid rgba(255, 255, 255, 0.08);
        background: rgba(255, 255, 255, 0.015);
    }

    .users-admin-page .permission-override-group summary {
        cursor: pointer;
        padding: 0.85rem 1rem;
        color: var(--color-gold, #d4b25b);
        font-size: 0.96rem;
        user-select: none;
    }

    .users-admin-page .permission-override-list {
        display: grid;
        gap: 0;
        border-top: 1px solid rgba(255, 255, 255, 0.07);
    }

    .users-admin-page .permission-override-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(170px, 210px);
        gap: 1rem;
        align-items: center;
        padding: 0.85rem 1rem;
        border-bottom: 1px solid rgba(255, 255, 255, 0.055);
    }

    .users-admin-page .permission-override-row:last-child {
        border-bottom: 0;
    }

    .users-admin-page .permission-override-name {
        margin: 0 0 0.18rem;
        font-size: 0.94rem;
    }

    .users-admin-page .permission-override-slug,
    .users-admin-page .permission-override-description {
        margin: 0;
        font-size: 0.78rem;
        opacity: 0.72;
        line-height: 1.4;
    }

    .users-admin-page .permission-override-select {
        width: 100%;
    }

    .users-admin-page .permission-override-save {
        padding: 0 1.4rem 1.4rem;
    }

    .users-admin-page .permission-override-count {
        display: inline-block;
        margin-left: 0.35rem;
        opacity: 0.72;
        font-size: 0.78rem;
    }

    @media (max-width: 760px) {
        .users-admin-page .permission-override-row {
            grid-template-columns: 1fr;
            gap: 0.65rem;
        }

        .users-admin-page .permission-override-groups {
            padding-left: 1rem;
            padding-right: 1rem;
        }

        .users-admin-page .permission-override-save {
            padding-left: 1rem;
            padding-right: 1rem;
        }

        .users-admin-page .forum-admin-hero {
            padding-top: 2rem;
            padding-bottom: 2rem;
        }

        .users-admin-page .forum-admin-actions {
            gap: 1rem;
            padding: 1.1rem;
        }

        .users-admin-page .forum-structure-list {
            padding: 0 1rem;
        }

        .users-admin-page .forum-structure-category header {
            align-items: flex-start;
        }
    }

</style>

<main id="main-content" class="forum-admin-page users-admin-page">

    <section class="forum-admin-hero" aria-labelledby="user-admin-heading">
        <div class="section-inner">

            <p class="academy-overline">
                Academy Administration
            </p>

            <h1 id="user-admin-heading">
                User Management
            </h1>

            <p>
                Find Academy members and manage the roles and groups assigned
                to their accounts.
            </p>

            <div class="forum-admin-edit-actions">

                <a href="<?= e(DASHBOARD_URL); ?>" class="button button-secondary">
                    Return to Dashboard
                </a>

                <?php if (
                    $isProtectedSuperAdmin
                    || user_can('roles.view')
                    || user_can('roles.edit')
                ): ?>
                <a href="<?= e(url('admin/roles.php')); ?>" class="button button-secondary">
                    Roles &amp; Permissions
                </a>
                <?php endif; ?>

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


            <!-- ==========================================================
                 Search
            =========================================================== -->

            <section class="forum-admin-actions" aria-labelledby="user-search-heading">

                <div>

                    <p class="academy-overline">
                        Members
                    </p>

                    <h2 id="user-search-heading">
                        Find a User
                    </h2>

                    <p>
                        Search by display name, username, or email address.
                    </p>

                </div>


                <form action="<?= e(url('admin/users.php')); ?>" method="get" class="forum-admin-form">

                    <div class="form-group">

                        <label for="user-search">
                            Search Users
                        </label>

                        <input class="form-control" id="user-search" type="search" name="q" maxlength="255"
                            value="<?= e($searchValue); ?>" placeholder="Name, username, or email">

                    </div>


                    <div class="forum-admin-edit-actions">

                        <button type="submit" class="button button-primary">
                            Search
                        </button>

                        <?php if ($searchValue !== ''): ?>

                        <a href="<?= e(url('admin/users.php')); ?>" class="button button-secondary">
                            Clear Search
                        </a>

                        <?php endif; ?>

                    </div>

                </form>

            </section>


            <!-- ==========================================================
                 Selected User
            =========================================================== -->

            <?php if ($selectedUserId > 0 && $selectedUser === null): ?>

            <div class="form-message form-message-error">
                That user could not be found.
            </div>

            <?php elseif ($selectedUser !== null): ?>

            <?php

                $selectedIsProtectedSuperAdmin =
                    (int) $selectedUser['id']
                    === $protectedSuperAdminId;

                $selectedIsCurrentUser =
                    (int) $selectedUser['id']
                    === $currentUserId;

                ?>

            <section class="forum-structure-panel" aria-labelledby="selected-user-heading">

                <header class="forum-admin-titlebar">

                    <p class="forum-admin-step">
                        Selected User
                    </p>

                    <h2 id="selected-user-heading">
                        <?= e((string) $selectedUser['display_name']); ?>
                    </h2>

                </header>


                <div class="forum-admin-form">

                    <div class="forum-admin-form-grid">

                        <div class="form-group">
                            <label>Username</label>
                            <input class="form-control" type="text"
                                value="<?= e((string) $selectedUser['username']); ?>" readonly>
                        </div>

                        <div class="form-group">
                            <label>Email</label>
                            <input class="form-control" type="text" value="<?= e((string) $selectedUser['email']); ?>"
                                readonly>
                        </div>

                        <div class="form-group">
                            <label>Account Status</label>
                            <input class="form-control" type="text"
                                value="<?= e(ucfirst((string) $selectedUser['status'])); ?>" readonly>
                        </div>

                        <div class="form-group">
                            <label>Email Verification</label>
                            <input class="form-control" type="text"
                                value="<?= $selectedUser['email_verified_at'] !== null ? 'Verified' : 'Not Verified'; ?>"
                                readonly>
                        </div>

                    </div>


                    <?php if ($selectedIsProtectedSuperAdmin): ?>

                    <div class="form-message form-message-info">

                        <strong>Protected Super Admin Account</strong>

                        <p>
                            This account always retains full Blackthorne
                            access through the protected Super Admin
                            setting, regardless of the roles/groups
                            selected below.
                        </p>

                    </div>

                    <?php endif; ?>

                </div>


                <header class="forum-admin-titlebar">

                    <p class="forum-admin-step">
                        Role Membership
                    </p>

                    <h2>
                        Assign Roles &amp; Groups
                    </h2>

                </header>


                <?php if ($canAssignRoles): ?>

                <form action="<?= e(url('admin/users.php?user=' . (int) $selectedUser['id'])); ?>" method="post"
                    class="forum-admin-form">

                    <?= csrf_field(); ?>

                    <input type="hidden" name="form_action" value="save_user_roles">

                    <input type="hidden" name="user_id" value="<?= (int) $selectedUser['id']; ?>">


                    <?php if ($availableRoles === []): ?>

                    <div class="forum-admin-empty">
                        No active roles/groups are available.
                    </div>

                    <?php else: ?>

                    <?php foreach ($availableRoles as $role): ?>

                    <?php

                                    $roleId =
                                        (int) $role['id'];

                                    $roleColor =
                                        trim(
                                            (string) (
                                                $role['display_color']
                                                ?? ''
                                            )
                                        );

                                    $roleHasColor =
                                        $roleColor !== ''
                                        && admin_users_valid_color($roleColor);

                                    $isChecked =
                                        isset(
                                            $selectedRoleIds[$roleId]
                                        );

                                    $isAdminRole =
                                        (int) $role['grants_all_permissions'] === 1;

                                    if (
                                        $isAdminRole
                                        && !$canAssignAdminRole
                                    ) {
                                        continue;
                                    }

                                    $expirationValue =
                                        $selectedRoleExpirations[$roleId]
                                        ?? '';

                                    ?>

                    <fieldset class="forum-admin-fieldset">

                        <legend <?php if ($roleHasColor): ?> style="color: <?= e($roleColor); ?>;" <?php endif; ?>>
                            <?= e((string) $role['name']); ?>
                        </legend>


                        <label class="forum-admin-choice">

                            <input type="checkbox" name="role_ids[]" value="<?= $roleId; ?>"
                                data-role-assignment-toggle="<?= $roleId; ?>" <?= $isChecked ? 'checked' : ''; ?>>

                            <span>

                                <strong>
                                    Assign <?= e((string) $role['name']); ?>
                                </strong>

                                <?php if ((int) $role['is_staff'] === 1): ?>
                                <br>
                                <small>
                                    Staff Role
                                </small>
                                <?php endif; ?>

                                <?php if ($isAdminRole): ?>
                                <br>
                                <small>
                                    Full-Privilege Role
                                </small>

                                <?php if (false): ?>
                                <br>
                                <small>
                                    Only a full-privilege administrator can assign or remove this role.
                                </small>
                                <?php endif; ?>
                                <?php endif; ?>

                                <?php if (
                                                    trim(
                                                        (string) (
                                                            $role['description']
                                                            ?? ''
                                                        )
                                                    ) !== ''
                                                ): ?>
                                <br>
                                <?= e((string) $role['description']); ?>
                                <?php endif; ?>

                            </span>

                        </label>


                        <div class="form-group" data-role-expiration-box="<?= $roleId; ?>"
                            <?= $isChecked ? '' : 'hidden'; ?>>

                            <label for="role-expires-<?= $roleId; ?>">
                                Optional Expiration Date
                            </label>

                            <input class="form-control" id="role-expires-<?= $roleId; ?>" type="date"
                                name="role_expires[<?= $roleId; ?>]" value="<?= e($expirationValue); ?>"
                                <?= $isChecked ? '' : 'disabled'; ?>>

                            <p class="form-help">
                                Leave blank for a permanent
                                assignment. The role remains
                                active through the selected date.
                            </p>

                        </div>

                    </fieldset>

                    <?php endforeach; ?>


                    <div class="forum-admin-edit-actions">

                        <button type="submit" class="button button-primary">
                            Save Role Assignments
                        </button>

                        <a href="<?= e(url('admin/users.php')); ?>" class="button button-secondary">
                            Close User
                        </a>

                    </div>

                    <?php endif; ?>

                </form>

                <?php else: ?>

                <div class="forum-admin-empty">
                    You may view this user, but you do not have
                    permission to assign or remove roles/groups.
                </div>

                <?php endif; ?>


                <?php if ($canManageUserPermissions): ?>

                <header class="forum-admin-titlebar" id="individual-permissions">

                    <p class="forum-admin-step">
                        Individual Access
                    </p>

                    <h2>
                        Individual Permission Overrides
                    </h2>

                </header>


                <?php if ($selectedIsProtectedSuperAdmin): ?>

                <div class="forum-admin-empty">
                    Individual permission overrides are not used for
                    this protected Admin account. Its protected access
                    always takes precedence.
                </div>

                <?php elseif (
                            $selectedIsCurrentUser
                            && !$isProtectedSuperAdmin
                        ): ?>

                <div class="forum-admin-empty">
                    You cannot change individual permission overrides
                    for your own account.
                </div>

                <?php else: ?>

                <div class="forum-admin-form">

                    <div class="forum-admin-edit-actions">

                        <form action="<?= e(url('admin/users.php?user=' . (int) $selectedUser['id'])); ?>" method="post"
                            onsubmit="return confirm('Clear ALL individual permission overrides for this user?');">
                            <?= csrf_field(); ?>

                            <input type="hidden" name="form_action" value="clear_user_permissions">

                            <input type="hidden" name="user_id" value="<?= (int) $selectedUser['id']; ?>">

                            <button type="submit" class="button button-secondary">
                                Clear All Overrides
                            </button>
                        </form>

                    </div>

                </div>


                <form
                    action="<?= e(url('admin/users.php?user=' . (int) $selectedUser['id'] . '#individual-permissions')); ?>"
                    method="post" class="forum-admin-form">

                    <?= csrf_field(); ?>

                    <input type="hidden" name="form_action" value="save_user_permissions">

                    <input type="hidden" name="user_id" value="<?= (int) $selectedUser['id']; ?>">


                    <p class="user-permission-intro">
                        Only the protected Super Admin may manage direct user overrides. Use <strong>Use Role
                            Setting</strong> for normal
                        role-based access. <strong>Allow</strong> grants
                        this permission directly to this user, while
                        <strong>Deny</strong> blocks it even when one
                        of the user's roles normally grants it.
                    </p>


                    <?php if ($permissionsByCategory === []): ?>

                    <div class="forum-admin-empty">
                        No active permissions are available.
                    </div>

                    <?php else: ?>

                    <div class="permission-override-groups">

                        <?php foreach (
                                            $permissionsByCategory
                                            as $category => $categoryPermissions
                                        ): ?>

                        <?php
                                            $visibleCategoryPermissions = [];

                                            foreach (
                                                $categoryPermissions
                                                as $categoryPermission
                                            ) {
                                                $categoryPermissionId =
                                                    (int) $categoryPermission['id'];

                                                if (
                                                    $isProtectedSuperAdmin
                                                    || isset(
                                                        $actorManageablePermissionIds[
                                                            $categoryPermissionId
                                                        ]
                                                    )
                                                ) {
                                                    $visibleCategoryPermissions[] =
                                                        $categoryPermission;
                                                }
                                            }

                                            if ($visibleCategoryPermissions === []) {
                                                continue;
                                            }

                                            $categoryOverrideCount =
                                                0;

                                            foreach (
                                                $visibleCategoryPermissions
                                                as $categoryPermission
                                            ) {
                                                if (
                                                    isset(
                                                        $selectedPermissionOverrides[
                                                            (int) $categoryPermission['id']
                                                        ]
                                                    )
                                                ) {
                                                    $categoryOverrideCount++;
                                                }
                                            }
                                            ?>

                        <details class="permission-override-group">

                            <summary>
                                <?= e($category); ?>

                                <?php if ($categoryOverrideCount > 0): ?>
                                <span class="permission-override-count">
                                    <?= $categoryOverrideCount; ?>
                                    override<?= $categoryOverrideCount === 1 ? '' : 's'; ?>
                                </span>
                                <?php endif; ?>
                            </summary>


                            <div class="permission-override-list">

                                <?php foreach (
                                                        $visibleCategoryPermissions
                                                        as $permission
                                                    ): ?>

                                <?php
                                                        $permissionId =
                                                            (int) $permission['id'];

                                                        $overrideState =
                                                            $selectedPermissionOverrides[$permissionId]
                                                            ?? 'inherit';
                                                        ?>

                                <div class="permission-override-row">

                                    <div>

                                        <p class="permission-override-name">
                                            <?= e((string) $permission['name']); ?>
                                        </p>

                                        <p class="permission-override-slug">
                                            <?= e((string) $permission['slug']); ?>
                                        </p>

                                        <?php if (
                                                                    trim(
                                                                        (string) (
                                                                            $permission['description']
                                                                            ?? ''
                                                                        )
                                                                    ) !== ''
                                                                ): ?>

                                        <p class="permission-override-description">
                                            <?= e((string) $permission['description']); ?>
                                        </p>

                                        <?php endif; ?>

                                    </div>


                                    <div>

                                        <label class="sr-only" for="permission-override-<?= $permissionId; ?>">
                                            Override for
                                            <?= e((string) $permission['name']); ?>
                                        </label>

                                        <select class="form-control permission-override-select"
                                            id="permission-override-<?= $permissionId; ?>"
                                            name="permission_overrides[<?= $permissionId; ?>]">
                                            <option value="inherit"
                                                <?= $overrideState === 'inherit' ? 'selected' : ''; ?>>
                                                Use Role Setting
                                            </option>

                                            <option value="allow" <?= $overrideState === 'allow' ? 'selected' : ''; ?>>
                                                Allow
                                            </option>

                                            <option value="deny" <?= $overrideState === 'deny' ? 'selected' : ''; ?>>
                                                Deny
                                            </option>
                                        </select>

                                    </div>

                                </div>

                                <?php endforeach; ?>

                            </div>

                        </details>

                        <?php endforeach; ?>

                    </div>


                    <div class="forum-admin-edit-actions permission-override-save">

                        <button type="submit" class="button button-primary">
                            Save Permission Overrides
                        </button>

                    </div>

                    <?php endif; ?>

                </form>

                <?php endif; ?>

                <?php endif; ?>


                <?php if ($selectedUserRoles !== []): ?>

                <header class="forum-admin-titlebar">

                    <p class="forum-admin-step">
                        Current Access
                    </p>

                    <h2>
                        Active Assignments
                    </h2>

                </header>


                <div class="forum-structure-list">

                    <?php foreach ($selectedUserRoles as $assignedRole): ?>

                    <?php

                                $assignedColor =
                                    trim(
                                        (string) (
                                            $assignedRole['display_color']
                                            ?? ''
                                        )
                                    );

                                $assignedHasColor =
                                    $assignedColor !== ''
                                    && admin_users_valid_color($assignedColor);

                                ?>

                    <article class="forum-structure-category">

                        <header>

                            <div>

                                <h3 <?php if ($assignedHasColor): ?> style="color: <?= e($assignedColor); ?>;"
                                    <?php endif; ?>>
                                    <?= e((string) $assignedRole['name']); ?>
                                </h3>

                                <p>

                                    <?php if ((int) $assignedRole['is_staff'] === 1): ?>
                                    Staff Role
                                    <?php else: ?>
                                    Member Role
                                    <?php endif; ?>

                                    <?php if ($assignedRole['expires_at'] !== null): ?>
                                    · Expires
                                    <?= e(
                                                        date(
                                                            'M j, Y',
                                                            strtotime(
                                                                (string) $assignedRole['expires_at']
                                                            )
                                                        )
                                                    ); ?>
                                    <?php else: ?>
                                    · No expiration
                                    <?php endif; ?>

                                </p>

                            </div>

                        </header>

                    </article>

                    <?php endforeach; ?>

                </div>

                <?php endif; ?>

            </section>

            <?php endif; ?>


            <!-- ==========================================================
                 User List
            =========================================================== -->

            <section class="forum-structure-panel" aria-labelledby="users-list-heading">

                <header class="forum-admin-titlebar">

                    <p class="forum-admin-step">
                        User Directory
                    </p>

                    <h2 id="users-list-heading">
                        <?= $searchValue !== '' ? 'Search Results' : 'Registered Users'; ?>
                    </h2>

                </header>


                <?php if ($users === []): ?>

                <div class="forum-admin-empty">
                    No users matched your search.
                </div>

                <?php else: ?>

                <div class="forum-structure-list">

                    <?php foreach ($users as $user): ?>

                    <?php

                            $userId =
                                (int) $user['id'];

                            $isProtected =
                                $userId === $protectedSuperAdminId;

                            ?>

                    <article class="forum-structure-category">

                        <header>

                            <div>

                                <h3<?= user_display_name_style_attr($userId); ?>>
                                    <?= e((string) $user['display_name']); ?>
                                    </h3>

                                    <p>
                                        @<?= e((string) $user['username']); ?>
                                        · <?= e((string) $user['email']); ?>
                                    </p>

                                    <p>
                                        Status:
                                        <?= e(ucfirst((string) $user['status'])); ?>
                                        ·
                                        <?= $user['email_verified_at'] !== null ? 'Verified' : 'Not Verified'; ?>
                                        ·
                                        <?= number_format((int) $user['active_role_count']); ?>
                                        Active
                                        <?= (int) $user['active_role_count'] === 1 ? 'Role' : 'Roles'; ?>

                                        <?php if ($isProtected): ?>
                                        · Protected Super Admin
                                        <?php endif; ?>
                                    </p>

                            </div>


                            <div class="forum-admin-edit-actions">

                                <a href="<?= e(url('admin/users.php?user=' . $userId)); ?>"
                                    class="button button-secondary">
                                    Manage Roles
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

        document
            .querySelectorAll('[data-role-assignment-toggle]')
            .forEach(function(toggle) {

                var roleId =
                    toggle.getAttribute(
                        'data-role-assignment-toggle'
                    );

                var expirationBox =
                    document.querySelector(
                        '[data-role-expiration-box="' + roleId + '"]'
                    );

                if (!expirationBox) {
                    return;
                }

                var expirationInput =
                    expirationBox.querySelector(
                        'input[type="date"]'
                    );

                function syncExpirationBox() {

                    if (toggle.checked) {

                        expirationBox.hidden =
                            false;

                        if (expirationInput) {
                            expirationInput.disabled =
                                false;
                        }

                    } else {

                        expirationBox.hidden =
                            true;

                        if (expirationInput) {
                            expirationInput.disabled =
                                true;
                        }

                    }

                }

                toggle.addEventListener(
                    'change',
                    syncExpirationBox
                );

                syncExpirationBox();

            });

    });

</script>

<?php require INCLUDES_PATH . '/footer.php'; ?>
