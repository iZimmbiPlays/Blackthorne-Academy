<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/house-functions.php';

require_active_account();


/*
|--------------------------------------------------------------------------
| Access
|--------------------------------------------------------------------------
|
| House membership changes are intentionally restricted to the protected
| Super Admin and users carrying the active Admin/Administrator role.
| A normal permission grant alone is NOT sufficient.
|
*/

if (!current_user_is_admin()) {
    http_response_code(403);

    $pageTitle =
        'House Membership Access Restricted | Blackthorne Academy';

    $pageDescription =
        'House membership management is restricted to Academy administrators.';

    $robots =
        'noindex, nofollow';

    require INCLUDES_PATH . '/header.php';
    ?>
<main id="main-content" class="forum-admin-page">
    <section class="forum-board-error">
        <div class="section-inner">
            <h1>Access Restricted</h1>
            <p>
                Only Academy administrators may add, remove,
                or transfer House members.
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
</main>
<?php
    require INCLUDES_PATH . '/footer.php';
    exit;
}

$currentUserId =
    (int) (current_user_id() ?? 0);


/*
|--------------------------------------------------------------------------
| Local Helpers
|--------------------------------------------------------------------------
*/

function admin_house_members_post_id(
    string $key
): int {
    $value =
        $_POST[$key]
        ?? '';

    if (
        !is_scalar($value)
        || !ctype_digit(
            (string) $value
        )
    ) {
        return 0;
    }

    return
        max(
            0,
            (int) $value
        );
}


function admin_house_members_search_value(): string
{
    $value =
        $_GET['q']
        ?? '';

    if (!is_scalar($value)) {
        return '';
    }

    return
        trim(
            (string) $value
        );
}


function admin_house_members_user(
    PDO $pdo,
    int $userId
): ?array {
    if ($userId <= 0) {
        return null;
    }

    $statement =
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

    $statement->execute([
        'user_id' =>
            $userId,
    ]);

    $user =
        $statement->fetch(
            PDO::FETCH_ASSOC
        );

    return
        is_array($user)
            ? $user
            : null;
}


function admin_house_members_house(
    PDO $pdo,
    int $houseId
): ?array {
    if ($houseId <= 0) {
        return null;
    }

    $statement =
        $pdo->prepare(
            'SELECT
                id,
                name,
                display_name,
                display_color,
                is_active
             FROM houses
             WHERE id = :house_id
             LIMIT 1'
        );

    $statement->execute([
        'house_id' =>
            $houseId,
    ]);

    $house =
        $statement->fetch(
            PDO::FETCH_ASSOC
        );

    return
        is_array($house)
            ? $house
            : null;
}


function admin_house_members_active_membership_for_update(
    PDO $pdo,
    int $userId
): ?array {
    $statement =
        $pdo->prepare(
            'SELECT
                hm.id,
                hm.user_id,
                hm.house_id,
                hm.membership_status,
                hm.joined_at,
                hm.left_at,
                h.name AS house_name,
                h.display_name AS house_display_name
             FROM house_memberships hm
             INNER JOIN houses h
                ON h.id = hm.house_id
             WHERE hm.user_id = :user_id
               AND hm.membership_status = "active"
               AND hm.left_at IS NULL
             ORDER BY hm.id DESC
             LIMIT 1
             FOR UPDATE'
        );

    $statement->execute([
        'user_id' =>
            $userId,
    ]);

    $membership =
        $statement->fetch(
            PDO::FETCH_ASSOC
        );

    return
        is_array($membership)
            ? $membership
            : null;
}


function admin_house_members_audit(
    PDO $pdo,
    int $actorUserId,
    string $actionType,
    int $targetUserId,
    string $description
): void {
    if ($actorUserId <= 0) {
        return;
    }

    try {
        $statement =
            $pdo->prepare(
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
            'user_id' =>
                $actorUserId,

            'action_type' =>
                $actionType,

            'entity_type' =>
                'user',

            'entity_id' =>
                $targetUserId,

            'description' =>
                $description,

            'ip_address' =>
                substr(
                    (string) (
                        $_SERVER['REMOTE_ADDR']
                        ?? ''
                    ),
                    0,
                    45
                ),

            'user_agent' =>
                substr(
                    (string) (
                        $_SERVER['HTTP_USER_AGENT']
                        ?? ''
                    ),
                    0,
                    500
                ),
        ]);

    } catch (PDOException $exception) {
        error_log(
            'Blackthorne House membership audit error: '
            . $exception->getMessage()
        );
    }
}


/*
|--------------------------------------------------------------------------
| Available Houses
|--------------------------------------------------------------------------
*/

$housesStatement =
    $pdo->query(
        'SELECT
            id,
            name,
            display_name,
            display_color,
            is_active,
            sort_order
         FROM houses
         WHERE is_active = 1
         ORDER BY
            sort_order ASC,
            display_name ASC,
            name ASC,
            id ASC'
    );

$availableHouses =
    $housesStatement->fetchAll(
        PDO::FETCH_ASSOC
    );

$validHouseIds = [];

foreach ($availableHouses as $house) {
    $validHouseIds[
        (int) $house['id']
    ] = true;
}


/*
|--------------------------------------------------------------------------
| Mutations
|--------------------------------------------------------------------------
*/

$errors = [];

if (is_post()) {
    require_valid_csrf();

    $formAction =
        post_value(
            'form_action'
        );

    $targetUserId =
        admin_house_members_post_id(
            'user_id'
        );

    $targetUser =
        admin_house_members_user(
            $pdo,
            $targetUserId
        );

    if ($targetUser === null) {
        $errors[] =
            'The selected user could not be found.';
    }

    /*
    |--------------------------------------------------------------------------
    | Assign / Transfer
    |--------------------------------------------------------------------------
    */

    if (
        in_array(
            $formAction,
            [
                'assign_house',
                'transfer_house',
            ],
            true
        )
    ) {
        $houseId =
            admin_house_members_post_id(
                'house_id'
            );

        $newHouse =
            admin_house_members_house(
                $pdo,
                $houseId
            );

        if (
            $newHouse === null
            || (int) $newHouse['is_active'] !== 1
            || !isset(
                $validHouseIds[$houseId]
            )
        ) {
            $errors[] =
                'Choose an active House.';
        }

        if (
            $errors === []
            && $targetUser !== null
            && $newHouse !== null
        ) {
            try {
                $pdo->beginTransaction();

                $currentMembership =
                    admin_house_members_active_membership_for_update(
                        $pdo,
                        $targetUserId
                    );

                if (
                    $currentMembership !== null
                    && (int) $currentMembership['house_id'] === $houseId
                ) {
                    throw new RuntimeException(
                        'This user is already an active member of that House.'
                    );
                }

                if ($currentMembership !== null) {
                    /*
                     * Close every active membership for this user.
                     * This also repairs an accidental duplicate-active state
                     * if one ever existed before this manager was introduced.
                     */
                    $closeStatement =
                        $pdo->prepare(
                            'UPDATE house_memberships
                             SET
                                membership_status = "transferred",
                                left_at = CURRENT_TIMESTAMP
                             WHERE user_id = :user_id
                               AND membership_status = "active"
                               AND left_at IS NULL'
                        );

                    $closeStatement->execute([
                        'user_id' =>
                            $targetUserId,
                    ]);
                }

                $insertStatement =
                    $pdo->prepare(
                        'INSERT INTO house_memberships (
                            user_id,
                            house_id,
                            assigned_by,
                            membership_status,
                            joined_at,
                            left_at
                         ) VALUES (
                            :user_id,
                            :house_id,
                            :assigned_by,
                            "active",
                            CURRENT_TIMESTAMP,
                            NULL
                         )'
                    );

                $insertStatement->execute([
                    'user_id' =>
                        $targetUserId,

                    'house_id' =>
                        $houseId,

                    'assigned_by' =>
                        $currentUserId,
                ]);

                $displayName =
                    trim(
                        (string) (
                            $targetUser['display_name']
                            ?? ''
                        )
                    );

                if ($displayName === '') {
                    $displayName =
                        (string) $targetUser['username'];
                }

                $houseName =
                    trim(
                        (string) (
                            $newHouse['display_name']
                            ?? ''
                        )
                    );

                if ($houseName === '') {
                    $houseName =
                        (string) $newHouse['name'];
                }

                $wasTransfer =
                    $currentMembership !== null;

                admin_house_members_audit(
                    $pdo,
                    $currentUserId,
                    $wasTransfer
                        ? 'house_member_transferred'
                        : 'house_member_assigned',
                    $targetUserId,
                    $wasTransfer
                        ? (
                            'Transferred '
                            . $displayName
                            . ' from '
                            . (
                                $currentMembership['house_display_name']
                                ?: $currentMembership['house_name']
                            )
                            . ' to '
                            . $houseName
                            . '.'
                        )
                        : (
                            'Assigned '
                            . $displayName
                            . ' to '
                            . $houseName
                            . '.'
                        )
                );

                $pdo->commit();

                set_flash(
                    'success',
                    $wasTransfer
                        ? 'House membership transferred successfully.'
                        : 'House membership assigned successfully.'
                );

                $returnQuery =
                    trim(
                        post_value(
                            'return_query'
                        )
                    );

                redirect(
                    url(
                        'admin/house-members.php'
                        . (
                            $returnQuery !== ''
                                ? '?q='
                                    . rawurlencode(
                                        $returnQuery
                                    )
                                : ''
                        )
                    )
                );

            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'Blackthorne House membership assignment error: '
                    . $exception->getMessage()
                );

                $errors[] =
                    $exception instanceof RuntimeException
                        ? $exception->getMessage()
                        : 'The House membership could not be changed.';
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Remove Current House Membership
    |--------------------------------------------------------------------------
    */

    if ($formAction === 'remove_house') {
        if (
            $errors === []
            && $targetUser !== null
        ) {
            try {
                $pdo->beginTransaction();

                $currentMembership =
                    admin_house_members_active_membership_for_update(
                        $pdo,
                        $targetUserId
                    );

                if ($currentMembership === null) {
                    throw new RuntimeException(
                        'This user does not currently have an active House membership.'
                    );
                }

                $closeStatement =
                    $pdo->prepare(
                        'UPDATE house_memberships
                         SET
                            membership_status = "inactive",
                            left_at = CURRENT_TIMESTAMP
                         WHERE user_id = :user_id
                           AND membership_status = "active"
                           AND left_at IS NULL'
                    );

                $closeStatement->execute([
                    'user_id' =>
                        $targetUserId,
                ]);

                $displayName =
                    trim(
                        (string) (
                            $targetUser['display_name']
                            ?? ''
                        )
                    );

                if ($displayName === '') {
                    $displayName =
                        (string) $targetUser['username'];
                }

                $oldHouseName =
                    trim(
                        (string) (
                            $currentMembership['house_display_name']
                            ?? ''
                        )
                    );

                if ($oldHouseName === '') {
                    $oldHouseName =
                        (string) $currentMembership['house_name'];
                }

                admin_house_members_audit(
                    $pdo,
                    $currentUserId,
                    'house_member_removed',
                    $targetUserId,
                    'Removed '
                    . $displayName
                    . ' from '
                    . $oldHouseName
                    . '.'
                );

                $pdo->commit();

                set_flash(
                    'success',
                    'House membership removed successfully.'
                );

                $returnQuery =
                    trim(
                        post_value(
                            'return_query'
                        )
                    );

                redirect(
                    url(
                        'admin/house-members.php'
                        . (
                            $returnQuery !== ''
                                ? '?q='
                                    . rawurlencode(
                                        $returnQuery
                                    )
                                : ''
                        )
                    )
                );

            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'Blackthorne House membership removal error: '
                    . $exception->getMessage()
                );

                $errors[] =
                    $exception instanceof RuntimeException
                        ? $exception->getMessage()
                        : 'The House membership could not be removed.';
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| Search / Results
|--------------------------------------------------------------------------
*/

$searchQuery =
    admin_house_members_search_value();

$users = [];

if ($searchQuery !== '') {
    $like =
        '%'
        . $searchQuery
        . '%';

    $statement =
        $pdo->prepare(
            'SELECT
                u.id,
                u.username,
                u.display_name,
                u.email,
                u.status,
                hm.id AS membership_id,
                hm.house_id,
                hm.membership_status,
                hm.joined_at,
                h.name AS house_name,
                h.display_name AS house_display_name,
                h.display_color AS house_display_color
             FROM users u
             LEFT JOIN house_memberships hm
                ON hm.id = (
                    SELECT hm2.id
                    FROM house_memberships hm2
                    WHERE hm2.user_id = u.id
                      AND hm2.membership_status = "active"
                      AND hm2.left_at IS NULL
                    ORDER BY hm2.id DESC
                    LIMIT 1
                )
             LEFT JOIN houses h
                ON h.id = hm.house_id
             WHERE (
                    u.display_name LIKE :display_search
                    OR u.username LIKE :username_search
                    OR u.email LIKE :email_search
             )
             ORDER BY
                u.display_name ASC,
                u.username ASC,
                u.id ASC
             LIMIT 100'
        );

    $statement->execute([
        'display_search' =>
            $like,

        'username_search' =>
            $like,

        'email_search' =>
            $like,
    ]);

    $users =
        $statement->fetchAll(
            PDO::FETCH_ASSOC
        );

} else {
    /*
     * Default view: recently created active/pending accounts.
     * Search remains the primary way to find a specific member.
     */
    $statement =
        $pdo->prepare(
            'SELECT
                u.id,
                u.username,
                u.display_name,
                u.email,
                u.status,
                hm.id AS membership_id,
                hm.house_id,
                hm.membership_status,
                hm.joined_at,
                h.name AS house_name,
                h.display_name AS house_display_name,
                h.display_color AS house_display_color
             FROM users u
             LEFT JOIN house_memberships hm
                ON hm.id = (
                    SELECT hm2.id
                    FROM house_memberships hm2
                    WHERE hm2.user_id = u.id
                      AND hm2.membership_status = "active"
                      AND hm2.left_at IS NULL
                    ORDER BY hm2.id DESC
                    LIMIT 1
                )
             LEFT JOIN houses h
                ON h.id = hm.house_id
             WHERE u.status IN ("active", "pending")
             ORDER BY
                u.created_at DESC,
                u.id DESC
             LIMIT 40'
        );

    $statement->execute();

    $users =
        $statement->fetchAll(
            PDO::FETCH_ASSOC
        );
}


$successMessage =
    get_flash(
        'success'
    );


/*
|--------------------------------------------------------------------------
| Page Metadata
|--------------------------------------------------------------------------
*/

$pageTitle =
    'House Memberships | Blackthorne Academy';

$pageDescription =
    'Administrator tools for managing current Blackthorne Academy House memberships.';

$pageCanonical =
    url(
        'admin/house-members.php'
    );

$robots =
    'noindex, nofollow';

require INCLUDES_PATH . '/header.php';

?>

<style>
    .house-members-admin-grid {
        display: grid;
        gap: 18px;
    }

    .house-members-search-panel,
    .house-member-card {
        border: 1px solid rgba(205, 171, 91, 0.18);
        border-radius: 14px;
        background: rgba(22, 14, 26, 0.78);
        box-shadow: 0 16px 36px rgba(0, 0, 0, 0.14);
    }

    .house-members-search-panel {
        padding: 22px;
    }

    .house-members-search-form {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 12px;
        align-items: end;
    }

    .house-member-results {
        display: grid;
        gap: 16px;
        margin-top: 24px;
    }

    .house-member-card {
        padding: 20px;
    }

    .house-member-card-header {
        display: flex;
        justify-content: space-between;
        gap: 18px;
        align-items: flex-start;
    }

    .house-member-card h2 {
        margin: 0 0 4px;
        font-size: 1.2rem;
    }

    .house-member-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 8px 14px;
        margin-top: 8px;
        color: #c8bec5;
        font-size: 0.9rem;
    }

    .house-member-status {
        display: inline-flex;
        align-items: center;
        padding: 5px 9px;
        border: 1px solid rgba(205, 171, 91, 0.2);
        border-radius: 999px;
        color: #d9ccd9;
        font-size: 0.78rem;
    }

    .house-member-current {
        margin-top: 16px;
        padding: 14px 16px;
        border: 1px solid rgba(205, 171, 91, 0.18);
        border-radius: 10px;
        background: rgba(10, 7, 13, 0.48);
    }

    .house-member-current strong {
        font-weight: 600;
    }

    .house-member-actions {
        display: grid;
        grid-template-columns: minmax(220px, 1fr) auto auto;
        gap: 10px;
        align-items: end;
        margin-top: 16px;
    }

    .house-member-actions form {
        display: contents;
    }

    .house-member-actions .form-group {
        margin: 0;
    }

    .house-member-remove {
        align-self: end;
    }

    .house-members-warning {
        padding: 16px;
        border: 1px solid rgba(192, 102, 109, 0.36);
        border-radius: 10px;
        background: rgba(84, 34, 43, 0.24);
        color: #efcfd3;
    }

    @media (max-width: 760px) {
        .house-members-search-form {
            grid-template-columns: 1fr;
        }

        .house-member-card-header {
            display: grid;
        }

        .house-member-actions {
            grid-template-columns: 1fr;
        }

        .house-member-actions form {
            display: grid;
            gap: 10px;
        }

        .house-member-actions .button {
            width: 100%;
        }
    }

</style>

<main id="main-content" class="forum-admin-page house-members-admin-page">

    <section class="forum-admin-hero" aria-labelledby="house-members-heading">
        <div class="section-inner">

            <p class="academy-overline">
                Academy Administration
            </p>

            <h1 id="house-members-heading">
                House Memberships
            </h1>

            <p>
                Manually correct, assign, transfer, or remove House memberships.
                The Sorting Ceremony remains the normal student assignment path.
            </p>

            <div class="forum-admin-edit-actions">

                <a href="<?= e(url('admin/houses.php')); ?>" class="button button-secondary">
                    House Management
                </a>

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
                <h2>
                    Please correct the following:
                </h2>

                <ul>
                    <?php foreach ($errors as $error): ?>
                    <li><?= e($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>


            <?php if ($availableHouses === []): ?>

            <div class="house-members-warning">
                No active Houses exist yet.
                Create or reactivate a House before assigning members.
            </div>

            <?php endif; ?>


            <section class="house-members-search-panel" aria-labelledby="house-member-search-heading">
                <header class="forum-admin-titlebar">
                    <p class="forum-admin-step">
                        Member Search
                    </p>

                    <h2 id="house-member-search-heading">
                        Find an Academy member
                    </h2>

                    <p>
                        Search by display name, username, or email address.
                    </p>
                </header>

                <form action="<?= e(url('admin/house-members.php')); ?>" method="get" class="house-members-search-form">
                    <div class="form-group">
                        <label for="member-search">
                            Search Members
                        </label>

                        <input class="form-control" id="member-search" type="search" name="q"
                            value="<?= e($searchQuery); ?>" placeholder="Name, username, or email" autocomplete="off">
                    </div>

                    <button type="submit" class="button button-primary">
                        Search
                    </button>
                </form>
            </section>


            <div class="house-member-results">

                <?php if ($users === []): ?>

                <div class="forum-admin-empty">
                    No matching members were found.
                </div>

                <?php else: ?>

                <?php foreach ($users as $user): ?>
                <?php
                        $userId =
                            (int) $user['id'];

                        $displayName =
                            trim(
                                (string) (
                                    $user['display_name']
                                    ?? ''
                                )
                            );

                        if ($displayName === '') {
                            $displayName =
                                (string) $user['username'];
                        }

                        $currentHouseId =
                            isset($user['house_id'])
                            && $user['house_id'] !== null
                                ? (int) $user['house_id']
                                : 0;

                        $currentHouseName =
                            trim(
                                (string) (
                                    $user['house_display_name']
                                    ?? ''
                                )
                            );

                        if (
                            $currentHouseName === ''
                            && !empty($user['house_name'])
                        ) {
                            $currentHouseName =
                                (string) $user['house_name'];
                        }

                        $houseColor =
                            trim(
                                (string) (
                                    $user['house_display_color']
                                    ?? ''
                                )
                            );

                        $validHouseColor =
                            preg_match(
                                '/^#[0-9A-Fa-f]{6}$/',
                                $houseColor
                            ) === 1;
                        ?>

                <article class="house-member-card">

                    <header class="house-member-card-header">

                        <div>
                            <h2>
                                <?= e($displayName); ?>
                            </h2>

                            <div class="house-member-meta">
                                <span>
                                    @<?= e((string) $user['username']); ?>
                                </span>

                                <span>
                                    <?= e((string) $user['email']); ?>
                                </span>

                                <span>
                                    User #<?= $userId; ?>
                                </span>
                            </div>
                        </div>

                        <span class="house-member-status">
                            <?= e(
                                        ucfirst(
                                            (string) $user['status']
                                        )
                                    ); ?>
                        </span>

                    </header>


                    <div class="house-member-current">

                        <?php if ($currentHouseId > 0): ?>

                        Current House:
                        <strong <?php if ($validHouseColor): ?> style="color: <?= e($houseColor); ?>;" <?php endif; ?>>
                            <?= e($currentHouseName); ?>
                        </strong>

                        <?php if (!empty($user['joined_at'])): ?>
                        <span>
                            · joined
                            <?= e(
                                                date(
                                                    'M j, Y',
                                                    strtotime(
                                                        (string) $user['joined_at']
                                                    )
                                                )
                                            ); ?>
                        </span>
                        <?php endif; ?>

                        <?php else: ?>

                        <strong>
                            No active House membership
                        </strong>.

                        <?php endif; ?>

                    </div>


                    <?php if ($availableHouses !== []): ?>

                    <div class="house-member-actions">

                        <form action="<?= e(url('admin/house-members.php')); ?>" method="post">
                            <?= csrf_field(); ?>

                            <input type="hidden" name="user_id" value="<?= $userId; ?>">

                            <input type="hidden" name="return_query" value="<?= e($searchQuery); ?>">

                            <input type="hidden" name="form_action" value="<?= $currentHouseId > 0
                                                ? 'transfer_house'
                                                : 'assign_house'; ?>">

                            <div class="form-group">
                                <label for="house-for-user-<?= $userId; ?>">
                                    <?= $currentHouseId > 0
                                                    ? 'Transfer to House'
                                                    : 'Assign to House'; ?>
                                </label>

                                <select class="form-control" id="house-for-user-<?= $userId; ?>" name="house_id"
                                    required>
                                    <option value="">
                                        Choose a House
                                    </option>

                                    <?php foreach ($availableHouses as $house): ?>
                                    <?php
                                                    $houseId =
                                                        (int) $house['id'];

                                                    if ($houseId === $currentHouseId) {
                                                        continue;
                                                    }

                                                    $houseName =
                                                        trim(
                                                            (string) (
                                                                $house['display_name']
                                                                ?? ''
                                                            )
                                                        );

                                                    if ($houseName === '') {
                                                        $houseName =
                                                            (string) $house['name'];
                                                    }
                                                    ?>

                                    <option value="<?= $houseId; ?>">
                                        <?= e($houseName); ?>
                                    </option>

                                    <?php endforeach; ?>

                                </select>
                            </div>

                            <button type="submit" class="button button-primary">
                                <?= $currentHouseId > 0
                                                ? 'Transfer'
                                                : 'Assign'; ?>
                            </button>

                        </form>


                        <?php if ($currentHouseId > 0): ?>

                        <form action="<?= e(url('admin/house-members.php')); ?>" method="post"
                            class="house-member-remove"
                            onsubmit="return confirm('Remove this member from their current House? Their membership history will be preserved.');">
                            <?= csrf_field(); ?>

                            <input type="hidden" name="form_action" value="remove_house">

                            <input type="hidden" name="user_id" value="<?= $userId; ?>">

                            <input type="hidden" name="return_query" value="<?= e($searchQuery); ?>">

                            <button type="submit" class="button button-secondary">
                                Remove from House
                            </button>

                        </form>

                        <?php endif; ?>

                    </div>

                    <?php endif; ?>

                </article>

                <?php endforeach; ?>

                <?php endif; ?>

            </div>

        </div>
    </section>

</main>

<?php require INCLUDES_PATH . '/footer.php'; ?>
