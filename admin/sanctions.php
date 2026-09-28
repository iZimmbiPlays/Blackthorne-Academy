<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

require_active_account();


/*
|--------------------------------------------------------------------------
| Access
|--------------------------------------------------------------------------
*/

$isProtectedAdmin =
    current_user_is_superuser();

$currentUserId =
    (int) (
        current_user_id()
        ?? 0
    );

$canViewSanctions =
    $isProtectedAdmin
    || user_can('users.sanctions.view')
    || user_can('users.sanctions.manage')
    || user_can('moderation.mutes.manage');

$canManageMutes =
    $isProtectedAdmin
    || user_can('moderation.mutes.manage');

$canManageAccountSanctions =
    $isProtectedAdmin
    || user_can('users.sanctions.manage');

if (!$canViewSanctions) {
    http_response_code(403);
    exit('You do not have permission to access User Sanctions.');
}


/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function sanctions_query_value(
    string $key,
    string $default = ''
): string {
    $value =
        $_GET[$key]
        ?? $default;

    if (!is_scalar($value)) {
        return $default;
    }

    return trim((string) $value);
}


function sanctions_query_id(
    string $key
): int {
    $value =
        $_GET[$key]
        ?? '';

    if (
        !is_scalar($value)
        || !ctype_digit((string) $value)
    ) {
        return 0;
    }

    return max(
        0,
        (int) $value
    );
}


function sanctions_type_label(
    string $type
): string {
    return match ($type) {
        'forum_mute' =>
            'Forum Mute',

        'messaging_mute' =>
            'Messaging Mute',

        'account_suspension' =>
            'Account Suspension',

        'account_ban' =>
            'Account Ban',

        default =>
            ucwords(
                str_replace(
                    '_',
                    ' ',
                    $type
                )
            ),
    };
}


function sanctions_action_type(
    string $sanctionType,
    bool $lifting = false
): string {
    if ($lifting) {
        return match ($sanctionType) {
            'forum_mute',
            'messaging_mute' =>
                'unmute_user',

            'account_suspension' =>
                'unsuspend_user',

            'account_ban' =>
                'unban_user',

            default =>
                'other',
        };
    }

    return match ($sanctionType) {
        'forum_mute',
        'messaging_mute' =>
            'mute_user',

        'account_suspension' =>
            'suspend_user',

        'account_ban' =>
            'ban_user',

        default =>
            'other',
    };
}


function sanctions_datetime(
    ?string $value
): string {
    $value =
        trim(
            (string) $value
        );

    if ($value === '') {
        return '—';
    }

    $timestamp =
        strtotime($value);

    if ($timestamp === false) {
        return $value;
    }

    return date(
        'M j, Y g:i A',
        $timestamp
    );
}


function sanctions_parse_expiration(
    string $value
): ?string {
    $value =
        trim($value);

    if ($value === '') {
        return null;
    }

    $date =
        DateTime::createFromFormat(
            'Y-m-d\TH:i',
            $value
        );

    if (
        !$date
        || $date->format(
            'Y-m-d\TH:i'
        ) !== $value
    ) {
        return null;
    }

    return $date->format(
        'Y-m-d H:i:00'
    );
}


function sanctions_user_name(
    array $user,
    string $displayKey = 'display_name',
    string $usernameKey = 'username'
): string {
    $displayName =
        trim(
            (string) (
                $user[$displayKey]
                ?? ''
            )
        );

    if ($displayName !== '') {
        return $displayName;
    }

    return trim(
        (string) (
            $user[$usernameKey]
            ?? ''
        )
    );
}


function sanctions_can_manage_type(
    string $type,
    bool $canManageMutes,
    bool $canManageAccountSanctions
): bool {
    if (
        in_array(
            $type,
            [
                'forum_mute',
                'messaging_mute',
            ],
            true
        )
    ) {
        return $canManageMutes;
    }

    if (
        in_array(
            $type,
            [
                'account_suspension',
                'account_ban',
            ],
            true
        )
    ) {
        return $canManageAccountSanctions;
    }

    return false;
}


/*
|--------------------------------------------------------------------------
| Normalize Expired Sanction Records
|--------------------------------------------------------------------------
|
| Expired sanctions are historical, not active. The actual feature-level
| enforcement will also check the expiration timestamp directly.
|
*/

$pdo->exec(
    'UPDATE user_sanctions
     SET
        is_active = 0,
        lifted_at = COALESCE(lifted_at, expires_at)
     WHERE is_active = 1
       AND expires_at IS NOT NULL
       AND expires_at <= NOW()'
);


/*
|--------------------------------------------------------------------------
| Selected User
|--------------------------------------------------------------------------
*/

$selectedUserId =
    sanctions_query_id(
        'user'
    );

$selectedUser = null;

if ($selectedUserId > 0) {
    $selectedUserStatement =
        $pdo->prepare(
            'SELECT
                id,
                username,
                display_name,
                email,
                status,
                created_at
             FROM users
             WHERE id = :user_id
             LIMIT 1'
        );

    $selectedUserStatement->execute([
        'user_id' =>
            $selectedUserId,
    ]);

    $selectedUser =
        $selectedUserStatement->fetch(
            PDO::FETCH_ASSOC
        )
        ?: null;
}


/*
|--------------------------------------------------------------------------
| Form Processing
|--------------------------------------------------------------------------
*/

if (is_post()) {
    require_valid_csrf();

    $formAction =
        post_value(
            'form_action'
        );

    $targetUserId =
        max(
            0,
            (int) post_value(
                'user_id',
                '0'
            )
        );

    $targetUserStatement =
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

    $targetUserStatement->execute([
        'user_id' =>
            $targetUserId,
    ]);

    $targetUser =
        $targetUserStatement->fetch(
            PDO::FETCH_ASSOC
        )
        ?: null;

    if (!$targetUser) {
        set_flash(
            'error',
            'The selected user could not be found.'
        );

        redirect(
            url('admin/sanctions.php')
        );
    }

    if (
        user_is_protected_super_admin(
            $targetUserId
        )
    ) {
        set_flash(
            'error',
            'The protected Admin account cannot receive sanctions.'
        );

        redirect(
            url(
                'admin/sanctions.php?user='
                . $targetUserId
            )
        );
    }

    if ($targetUserId === $currentUserId) {
        set_flash(
            'error',
            'You cannot issue or lift a sanction on your own account.'
        );

        redirect(
            url(
                'admin/sanctions.php?user='
                . $targetUserId
            )
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Issue Sanction
    |--------------------------------------------------------------------------
    */

    if ($formAction === 'issue_sanction') {
        $sanctionType =
            post_value(
                'sanction_type'
            );

        $reason =
            trim(
                post_value(
                    'reason'
                )
            );

        $expirationRaw =
            post_value(
                'expires_at'
            );

        $validTypes = [
            'forum_mute',
            'messaging_mute',
            'account_suspension',
            'account_ban',
        ];

        if (
            !in_array(
                $sanctionType,
                $validTypes,
                true
            )
        ) {
            set_flash(
                'error',
                'Choose a valid sanction type.'
            );

            redirect(
                url(
                    'admin/sanctions.php?user='
                    . $targetUserId
                )
            );
        }

        if (
            !sanctions_can_manage_type(
                $sanctionType,
                $canManageMutes,
                $canManageAccountSanctions
            )
        ) {
            http_response_code(403);
            exit('You do not have permission to issue that sanction.');
        }

        if ($reason === '') {
            set_flash(
                'error',
                'A reason is required when issuing a sanction.'
            );

            redirect(
                url(
                    'admin/sanctions.php?user='
                    . $targetUserId
                )
            );
        }

        $expiresAt =
            sanctions_parse_expiration(
                $expirationRaw
            );

        if (
            trim($expirationRaw) !== ''
            && $expiresAt === null
        ) {
            set_flash(
                'error',
                'The expiration date and time is invalid.'
            );

            redirect(
                url(
                    'admin/sanctions.php?user='
                    . $targetUserId
                )
            );
        }

        if (
            $expiresAt !== null
            && strtotime($expiresAt) <= time()
        ) {
            set_flash(
                'error',
                'The expiration must be in the future.'
            );

            redirect(
                url(
                    'admin/sanctions.php?user='
                    . $targetUserId
                )
            );
        }


        /*
         * Avoid stacking duplicate effective sanctions of the same type.
         */
        $duplicateStatement =
            $pdo->prepare(
                'SELECT id
                 FROM user_sanctions
                 WHERE user_id = :user_id
                   AND sanction_type = :sanction_type
                   AND is_active = 1
                   AND starts_at <= NOW()
                   AND (
                        expires_at IS NULL
                        OR expires_at > NOW()
                   )
                 LIMIT 1'
            );

        $duplicateStatement->execute([
            'user_id' =>
                $targetUserId,
            'sanction_type' =>
                $sanctionType,
        ]);

        if ($duplicateStatement->fetchColumn()) {
            set_flash(
                'error',
                'That user already has an active '
                . strtolower(
                    sanctions_type_label(
                        $sanctionType
                    )
                )
                . '.'
            );

            redirect(
                url(
                    'admin/sanctions.php?user='
                    . $targetUserId
                )
            );
        }


        try {
            $pdo->beginTransaction();

            $insertSanction =
                $pdo->prepare(
                    'INSERT INTO user_sanctions (
                        user_id,
                        issued_by,
                        sanction_type,
                        reason,
                        starts_at,
                        expires_at,
                        is_active
                     ) VALUES (
                        :user_id,
                        :issued_by,
                        :sanction_type,
                        :reason,
                        NOW(),
                        :expires_at,
                        1
                     )'
                );

            $insertSanction->execute([
                'user_id' =>
                    $targetUserId,
                'issued_by' =>
                    $currentUserId,
                'sanction_type' =>
                    $sanctionType,
                'reason' =>
                    $reason,
                'expires_at' =>
                    $expiresAt,
            ]);

            $sanctionId =
                (int) $pdo->lastInsertId();


            $insertModerationAction =
                $pdo->prepare(
                    'INSERT INTO moderation_actions (
                        moderator_id,
                        target_user_id,
                        action_type,
                        reason,
                        notes,
                        expires_at
                     ) VALUES (
                        :moderator_id,
                        :target_user_id,
                        :action_type,
                        :reason,
                        :notes,
                        :expires_at
                     )'
                );

            $insertModerationAction->execute([
                'moderator_id' =>
                    $currentUserId,
                'target_user_id' =>
                    $targetUserId,
                'action_type' =>
                    sanctions_action_type(
                        $sanctionType
                    ),
                'reason' =>
                    $reason,
                'notes' =>
                    sanctions_type_label(
                        $sanctionType
                    )
                    . ' issued through User Sanctions. Sanction #'
                    . $sanctionId
                    . '.',
                'expires_at' =>
                    $expiresAt,
            ]);


            /*
             * Account sanctions should take effect immediately for existing
             * sessions. Login/current-session enforcement is handled by the
             * shared authentication layer in the next integration step.
             */
            if (
                in_array(
                    $sanctionType,
                    [
                        'account_suspension',
                        'account_ban',
                    ],
                    true
                )
            ) {
                $endSessions =
                    $pdo->prepare(
                        'UPDATE user_sessions
                         SET is_active = 0
                         WHERE user_id = :user_id
                           AND is_active = 1'
                    );

                $endSessions->execute([
                    'user_id' =>
                        $targetUserId,
                ]);
            }

            $pdo->commit();

            set_flash(
                'success',
                sanctions_type_label(
                    $sanctionType
                )
                . ' issued successfully.'
            );

        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'Blackthorne sanction issue error: '
                . $exception->getMessage()
            );

            set_flash(
                'error',
                'The sanction could not be issued.'
            );
        }

        redirect(
            url(
                'admin/sanctions.php?user='
                . $targetUserId
            )
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Lift Sanction
    |--------------------------------------------------------------------------
    */

    if ($formAction === 'lift_sanction') {
        $sanctionId =
            max(
                0,
                (int) post_value(
                    'sanction_id',
                    '0'
                )
            );

        $sanctionStatement =
            $pdo->prepare(
                'SELECT
                    id,
                    user_id,
                    sanction_type,
                    reason,
                    expires_at,
                    is_active
                 FROM user_sanctions
                 WHERE id = :sanction_id
                   AND user_id = :user_id
                 LIMIT 1'
            );

        $sanctionStatement->execute([
            'sanction_id' =>
                $sanctionId,
            'user_id' =>
                $targetUserId,
        ]);

        $sanction =
            $sanctionStatement->fetch(
                PDO::FETCH_ASSOC
            )
            ?: null;

        if (
            !$sanction
            || (int) $sanction['is_active'] !== 1
        ) {
            set_flash(
                'error',
                'That active sanction could not be found.'
            );

            redirect(
                url(
                    'admin/sanctions.php?user='
                    . $targetUserId
                )
            );
        }

        $sanctionType =
            (string) $sanction['sanction_type'];

        if (
            !sanctions_can_manage_type(
                $sanctionType,
                $canManageMutes,
                $canManageAccountSanctions
            )
        ) {
            http_response_code(403);
            exit('You do not have permission to lift that sanction.');
        }


        try {
            $pdo->beginTransaction();

            $liftStatement =
                $pdo->prepare(
                    'UPDATE user_sanctions
                     SET
                        is_active = 0,
                        lifted_at = NOW(),
                        lifted_by = :lifted_by
                     WHERE id = :sanction_id
                       AND is_active = 1'
                );

            $liftStatement->execute([
                'lifted_by' =>
                    $currentUserId,
                'sanction_id' =>
                    $sanctionId,
            ]);

            $insertModerationAction =
                $pdo->prepare(
                    'INSERT INTO moderation_actions (
                        moderator_id,
                        target_user_id,
                        action_type,
                        reason,
                        notes
                     ) VALUES (
                        :moderator_id,
                        :target_user_id,
                        :action_type,
                        :reason,
                        :notes
                     )'
                );

            $insertModerationAction->execute([
                'moderator_id' =>
                    $currentUserId,
                'target_user_id' =>
                    $targetUserId,
                'action_type' =>
                    sanctions_action_type(
                        $sanctionType,
                        true
                    ),
                'reason' =>
                    'Sanction lifted by staff.',
                'notes' =>
                    sanctions_type_label(
                        $sanctionType
                    )
                    . ' lifted through User Sanctions. Sanction #'
                    . $sanctionId
                    . '. Original reason: '
                    . trim(
                        (string) (
                            $sanction['reason']
                            ?? ''
                        )
                    ),
            ]);

            $pdo->commit();

            set_flash(
                'success',
                sanctions_type_label(
                    $sanctionType
                )
                . ' was lifted.'
            );

        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'Blackthorne sanction lift error: '
                . $exception->getMessage()
            );

            set_flash(
                'error',
                'The sanction could not be lifted.'
            );
        }

        redirect(
            url(
                'admin/sanctions.php?user='
                . $targetUserId
            )
        );
    }
}


/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
*/

$searchQuery =
    sanctions_query_value(
        'q'
    );

$searchResults = [];

if ($searchQuery !== '') {
    $searchStatement =
        $pdo->prepare(
            'SELECT
                id,
                username,
                display_name,
                email,
                status
             FROM users
             WHERE display_name LIKE :display_name
                OR username LIKE :username
                OR email LIKE :email
             ORDER BY display_name ASC, username ASC
             LIMIT 25'
        );

    $searchLike =
        '%'
        . $searchQuery
        . '%';

    $searchStatement->execute([
        'display_name' =>
            $searchLike,
        'username' =>
            $searchLike,
        'email' =>
            $searchLike,
    ]);

    $searchResults =
        $searchStatement->fetchAll(
            PDO::FETCH_ASSOC
        );
}


/*
|--------------------------------------------------------------------------
| Selected User Sanctions
|--------------------------------------------------------------------------
*/

$activeSanctions = [];
$sanctionHistory = [];

if ($selectedUser) {
    $activeStatement =
        $pdo->prepare(
            'SELECT
                us.*,
                issuer.display_name
                    AS issued_by_display_name,
                issuer.username
                    AS issued_by_username
             FROM user_sanctions us
             INNER JOIN users issuer
                ON issuer.id = us.issued_by
             WHERE us.user_id = :user_id
               AND us.is_active = 1
               AND us.starts_at <= NOW()
               AND (
                    us.expires_at IS NULL
                    OR us.expires_at > NOW()
               )
             ORDER BY us.created_at DESC, us.id DESC'
        );

    $activeStatement->execute([
        'user_id' =>
            $selectedUserId,
    ]);

    $activeSanctions =
        $activeStatement->fetchAll(
            PDO::FETCH_ASSOC
        );


    $historyStatement =
        $pdo->prepare(
            'SELECT
                us.*,
                issuer.display_name
                    AS issued_by_display_name,
                issuer.username
                    AS issued_by_username,
                lifter.display_name
                    AS lifted_by_display_name,
                lifter.username
                    AS lifted_by_username
             FROM user_sanctions us
             INNER JOIN users issuer
                ON issuer.id = us.issued_by
             LEFT JOIN users lifter
                ON lifter.id = us.lifted_by
             WHERE us.user_id = :user_id
               AND (
                    us.is_active = 0
                    OR (
                        us.expires_at IS NOT NULL
                        AND us.expires_at <= NOW()
                    )
               )
             ORDER BY us.created_at DESC, us.id DESC
             LIMIT 100'
        );

    $historyStatement->execute([
        'user_id' =>
            $selectedUserId,
    ]);

    $sanctionHistory =
        $historyStatement->fetchAll(
            PDO::FETCH_ASSOC
        );
}


/*
|--------------------------------------------------------------------------
| Page Metadata
|--------------------------------------------------------------------------
*/

$pageTitle =
    'User Sanctions | Blackthorne Academy';

$pageDescription =
    'Manage user mutes, suspensions, bans, and sanction history.';

$pageCanonical =
    url('admin/sanctions.php');

$robots =
    'noindex, nofollow';

require
    INCLUDES_PATH
    . '/header.php';

$successMessage =
    get_flash('success');

$errorMessage =
    get_flash('error');

?>

<style>
.sanctions-page .sanctions-layout {
    display: grid;
    grid-template-columns: minmax(250px, 0.38fr) minmax(0, 1fr);
    gap: 1.2rem;
    align-items: start;
}

.sanctions-page .sanctions-panel {
    border: 1px solid rgba(150, 113, 147, 0.26);
    background: rgba(20, 12, 23, 0.58);
    padding: 1rem;
}

.sanctions-page .sanctions-panel h2,
.sanctions-page .sanctions-panel h3 {
    margin-top: 0;
}

.sanctions-page .sanctions-search-results {
    display: grid;
    gap: 0.55rem;
    margin-top: 0.9rem;
}

.sanctions-page .sanctions-user-result {
    display: block;
    padding: 0.7rem 0.8rem;
    border: 1px solid rgba(150, 113, 147, 0.28);
    background: rgba(31, 17, 35, 0.42);
    color: #eee7ef;
    text-decoration: none;
}

.sanctions-page .sanctions-user-result:hover {
    border-color: rgba(212, 178, 91, 0.55);
}

.sanctions-page .sanctions-user-result strong,
.sanctions-page .sanctions-user-result span {
    display: block;
}

.sanctions-page .sanctions-user-result span {
    margin-top: 0.18rem;
    font-size: 0.78rem;
    opacity: 0.72;
}

.sanctions-page select.form-control,
.sanctions-page textarea.form-control,
.sanctions-page input.form-control {
    color: #eee7ef;
    background: #160d19;
    border: 1px solid rgba(150, 113, 147, 0.55);
    border-radius: 5px;
    box-shadow: none;
}

.sanctions-page select.form-control:hover,
.sanctions-page textarea.form-control:hover,
.sanctions-page input.form-control:hover {
    border-color: rgba(212, 178, 91, 0.55);
}

.sanctions-page select.form-control:focus,
.sanctions-page textarea.form-control:focus,
.sanctions-page input.form-control:focus {
    color: #fff8ef;
    background: #1c1020;
    border-color: #d4b25b;
    outline: 2px solid rgba(212, 178, 91, 0.18);
    outline-offset: 2px;
    box-shadow: none;
}

.sanctions-page select.form-control {
    color-scheme: dark;
}

.sanctions-page select.form-control option {
    color: #eee7ef;
    background: #160d19;
}

.sanctions-page .selected-user-card {
    margin-bottom: 1rem;
    padding: 0.85rem 1rem;
    border-left: 2px solid rgba(212, 178, 91, 0.62);
    background: rgba(40, 23, 43, 0.34);
}

.sanctions-page .selected-user-card p {
    margin: 0.15rem 0;
}

.sanctions-page .protected-account-note {
    padding: 0.9rem;
    border: 1px solid rgba(212, 178, 91, 0.3);
    background: rgba(212, 178, 91, 0.06);
}

.sanctions-page .sanction-section {
    margin-top: 1.25rem;
}

.sanctions-page .sanction-list {
    display: grid;
    gap: 0.75rem;
}

.sanctions-page .sanction-card {
    padding: 0.9rem 1rem;
    border: 1px solid rgba(150, 113, 147, 0.25);
    background: rgba(10, 8, 13, 0.58);
}

.sanctions-page .sanction-card-header {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 0.7rem;
    align-items: start;
}

.sanctions-page .sanction-card-header h4 {
    margin: 0;
    font-size: 0.98rem;
}

.sanctions-page .sanction-badge {
    display: inline-flex;
    padding: 0.25rem 0.55rem;
    border: 1px solid rgba(212, 178, 91, 0.35);
    border-radius: 999px;
    color: #d4b25b;
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.sanctions-page .sanction-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem 0.8rem;
    margin: 0.55rem 0 0;
    font-size: 0.78rem;
    opacity: 0.76;
}

.sanctions-page .sanction-reason {
    margin: 0.7rem 0 0;
    padding: 0.7rem 0.8rem;
    border-left: 2px solid rgba(212, 178, 91, 0.48);
    background: rgba(40, 23, 43, 0.3);
}

.sanctions-page .issue-sanction-grid {
    display: grid;
    grid-template-columns: minmax(0, 0.7fr) minmax(0, 0.7fr);
    gap: 0.8rem 1rem;
}

.sanctions-page .issue-sanction-grid .full-width {
    grid-column: 1 / -1;
}

.sanctions-page .empty-state {
    padding: 1rem;
    border: 1px dashed rgba(150, 113, 147, 0.28);
    text-align: center;
    opacity: 0.78;
}

@media (max-width: 860px) {
    .sanctions-page .sanctions-layout,
    .sanctions-page .issue-sanction-grid {
        grid-template-columns: 1fr;
    }

    .sanctions-page .issue-sanction-grid .full-width {
        grid-column: auto;
    }
}
</style>

<main
    id="main-content"
    class="forum-admin-page sanctions-page"
>

    <section
        class="forum-admin-hero"
        aria-labelledby="sanctions-heading"
    >
        <div class="section-inner">

            <p class="academy-overline">
                Moderation
            </p>

            <h1 id="sanctions-heading">
                User Sanctions
            </h1>

            <p>
                Manage forum and messaging mutes, account suspensions,
                bans, and sanction history.
            </p>

            <div class="forum-admin-edit-actions">

                <a
                    href="<?= e(url('admin/moderation.php')); ?>"
                    class="button button-secondary"
                >
                    Moderation
                </a>

                <?php if (user_can('moderation.history.view')): ?>
                    <a
                        href="<?= e(url('admin/moderation-history.php')); ?>"
                        class="button button-secondary"
                    >
                        Moderation History
                    </a>
                <?php endif; ?>

                <?php if (
                    user_can_any([
                        'moderation.reports.view',
                        'moderation.reports.review',
                        'moderation.reports.resolve',
                        'moderation.reports.dismiss',
                    ])
                ): ?>
                    <a
                        href="<?= e(url('admin/reports.php')); ?>"
                        class="button button-secondary"
                    >
                        Reports &amp; Moderation
                    </a>
                <?php endif; ?>

                <a
                    href="<?= e(url('staff-dashboard.php')); ?>"
                    class="button button-secondary"
                >
                    Staff Dashboard
                </a>

            </div>

        </div>
    </section>


    <section class="forum-admin-content">
        <div class="section-inner">

            <?php if ($successMessage): ?>

                <div class="alert alert-success">
                    <?= e($successMessage); ?>
                </div>

            <?php endif; ?>

            <?php if ($errorMessage): ?>

                <div class="alert alert-error">
                    <?= e($errorMessage); ?>
                </div>

            <?php endif; ?>


            <div class="sanctions-layout">

                <aside class="sanctions-panel">

                    <h2>
                        Find User
                    </h2>

                    <form
                        action="<?= e(url('admin/sanctions.php')); ?>"
                        method="get"
                    >
                        <div class="form-group">
                            <label for="sanction-user-search">
                                Name, username, or email
                            </label>

                            <input
                                type="search"
                                id="sanction-user-search"
                                name="q"
                                class="form-control"
                                value="<?= e($searchQuery); ?>"
                                placeholder="Search users"
                                required
                            >
                        </div>

                        <button
                            type="submit"
                            class="button button-primary"
                        >
                            Search
                        </button>
                    </form>


                    <?php if ($searchQuery !== ''): ?>

                        <div class="sanctions-search-results">

                            <?php if ($searchResults === []): ?>

                                <div class="empty-state">
                                    No users matched that search.
                                </div>

                            <?php else: ?>

                                <?php foreach ($searchResults as $result): ?>

                                    <a
                                        href="<?= e(
                                            url(
                                                'admin/sanctions.php?user='
                                                . (int) $result['id']
                                            )
                                        ); ?>"
                                        class="sanctions-user-result"
                                    >
                                        <strong>
                                            <?= e(
                                                sanctions_user_name(
                                                    $result
                                                )
                                            ); ?>
                                        </strong>

                                        <span>
                                            @<?= e((string) $result['username']); ?>
                                            · <?= e((string) $result['status']); ?>
                                        </span>

                                        <span>
                                            <?= e((string) $result['email']); ?>
                                        </span>
                                    </a>

                                <?php endforeach; ?>

                            <?php endif; ?>

                        </div>

                    <?php endif; ?>

                </aside>


                <div>

                    <?php if (!$selectedUser): ?>

                        <div class="sanctions-panel">

                            <h2>
                                Select a User
                            </h2>

                            <p>
                                Search for a member to view their active
                                sanctions and sanction history.
                            </p>

                        </div>

                    <?php else: ?>

                        <?php

                        $selectedName =
                            sanctions_user_name(
                                $selectedUser
                            );

                        $selectedIsProtected =
                            user_is_protected_super_admin(
                                (int) $selectedUser['id']
                            );

                        ?>

                        <div class="sanctions-panel">

                            <div class="selected-user-card">

                                <h2>
                                    <?= e($selectedName); ?>
                                </h2>

                                <p>
                                    @<?= e((string) $selectedUser['username']); ?>
                                </p>

                                <p>
                                    <?= e((string) $selectedUser['email']); ?>
                                </p>

                                <p>
                                    Account status:
                                    <strong>
                                        <?= e((string) $selectedUser['status']); ?>
                                    </strong>
                                </p>

                            </div>


                            <?php if ($selectedIsProtected): ?>

                                <div class="protected-account-note">

                                    <strong>
                                        Protected Admin Account
                                    </strong>

                                    <p>
                                        Sanctions cannot be issued to or
                                        lifted from this protected account.
                                    </p>

                                </div>

                            <?php elseif (
                                (int) $selectedUser['id']
                                === $currentUserId
                            ): ?>

                                <div class="protected-account-note">

                                    <strong>
                                        Your Account
                                    </strong>

                                    <p>
                                        Staff cannot issue or lift sanctions
                                        on their own account.
                                    </p>

                                </div>

                            <?php elseif (
                                $canManageMutes
                                || $canManageAccountSanctions
                            ): ?>

                                <section class="sanction-section">

                                    <h3>
                                        Issue Sanction
                                    </h3>

                                    <form
                                        action="<?= e(url('admin/sanctions.php')); ?>"
                                        method="post"
                                    >
                                        <?= csrf_field(); ?>

                                        <input
                                            type="hidden"
                                            name="form_action"
                                            value="issue_sanction"
                                        >

                                        <input
                                            type="hidden"
                                            name="user_id"
                                            value="<?= (int) $selectedUser['id']; ?>"
                                        >

                                        <div class="issue-sanction-grid">

                                            <div class="form-group">

                                                <label for="sanction-type">
                                                    Sanction Type
                                                </label>

                                                <select
                                                    id="sanction-type"
                                                    name="sanction_type"
                                                    class="form-control"
                                                    required
                                                >
                                                    <option value="">
                                                        Choose sanction…
                                                    </option>

                                                    <?php if ($canManageMutes): ?>
                                                        <option value="forum_mute">
                                                            Forum Mute
                                                        </option>
                                                        <option value="messaging_mute">
                                                            Messaging Mute
                                                        </option>
                                                    <?php endif; ?>

                                                    <?php if ($canManageAccountSanctions): ?>
                                                        <option value="account_suspension">
                                                            Account Suspension
                                                        </option>
                                                        <option value="account_ban">
                                                            Account Ban
                                                        </option>
                                                    <?php endif; ?>
                                                </select>

                                            </div>


                                            <div class="form-group">

                                                <label for="sanction-expires">
                                                    Expires
                                                </label>

                                                <input
                                                    type="datetime-local"
                                                    id="sanction-expires"
                                                    name="expires_at"
                                                    class="form-control"
                                                >

                                                <small>
                                                    Leave blank for no automatic expiration.
                                                </small>

                                            </div>


                                            <div class="form-group full-width">

                                                <label for="sanction-reason">
                                                    Reason
                                                </label>

                                                <textarea
                                                    id="sanction-reason"
                                                    name="reason"
                                                    class="form-control"
                                                    rows="4"
                                                    maxlength="4000"
                                                    required
                                                ></textarea>

                                            </div>


                                            <div class="full-width">

                                                <button
                                                    type="submit"
                                                    class="button button-primary"
                                                >
                                                    Issue Sanction
                                                </button>

                                            </div>

                                        </div>

                                    </form>

                                </section>

                            <?php endif; ?>


                            <section class="sanction-section">

                                <h3>
                                    Active Sanctions
                                </h3>

                                <?php if ($activeSanctions === []): ?>

                                    <div class="empty-state">
                                        This user has no active sanctions.
                                    </div>

                                <?php else: ?>

                                    <div class="sanction-list">

                                        <?php foreach ($activeSanctions as $sanction): ?>

                                            <?php

                                            $sanctionType =
                                                (string) $sanction['sanction_type'];

                                            $canLiftThis =
                                                !$selectedIsProtected
                                                && (int) $selectedUser['id'] !== $currentUserId
                                                && sanctions_can_manage_type(
                                                    $sanctionType,
                                                    $canManageMutes,
                                                    $canManageAccountSanctions
                                                );

                                            ?>

                                            <article class="sanction-card">

                                                <div class="sanction-card-header">

                                                    <h4>
                                                        <?= e(
                                                            sanctions_type_label(
                                                                $sanctionType
                                                            )
                                                        ); ?>
                                                    </h4>

                                                    <span class="sanction-badge">
                                                        Active
                                                    </span>

                                                </div>

                                                <p class="sanction-meta">

                                                    <span>
                                                        Issued by
                                                        <?= e(
                                                            sanctions_user_name(
                                                                $sanction,
                                                                'issued_by_display_name',
                                                                'issued_by_username'
                                                            )
                                                        ); ?>
                                                    </span>

                                                    <span>
                                                        <?= e(
                                                            sanctions_datetime(
                                                                (string) $sanction['starts_at']
                                                            )
                                                        ); ?>
                                                    </span>

                                                    <span>
                                                        Expires:
                                                        <?= e(
                                                            $sanction['expires_at'] !== null
                                                                ? sanctions_datetime(
                                                                    (string) $sanction['expires_at']
                                                                )
                                                                : 'No expiration'
                                                        ); ?>
                                                    </span>

                                                </p>

                                                <div class="sanction-reason">

                                                    <strong>
                                                        Reason
                                                    </strong>

                                                    <div>
                                                        <?= nl2br(
                                                            e(
                                                                (string) (
                                                                    $sanction['reason']
                                                                    ?? ''
                                                                )
                                                            )
                                                        ); ?>
                                                    </div>

                                                </div>


                                                <?php if ($canLiftThis): ?>

                                                    <form
                                                        action="<?= e(url('admin/sanctions.php')); ?>"
                                                        method="post"
                                                        style="margin-top: 0.75rem;"
                                                        onsubmit="return confirm('Lift this sanction?');"
                                                    >
                                                        <?= csrf_field(); ?>

                                                        <input
                                                            type="hidden"
                                                            name="form_action"
                                                            value="lift_sanction"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="user_id"
                                                            value="<?= (int) $selectedUser['id']; ?>"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="sanction_id"
                                                            value="<?= (int) $sanction['id']; ?>"
                                                        >

                                                        <button
                                                            type="submit"
                                                            class="button button-secondary"
                                                        >
                                                            Lift Sanction
                                                        </button>

                                                    </form>

                                                <?php endif; ?>

                                            </article>

                                        <?php endforeach; ?>

                                    </div>

                                <?php endif; ?>

                            </section>


                            <section class="sanction-section">

                                <h3>
                                    Sanction History
                                </h3>

                                <?php if ($sanctionHistory === []): ?>

                                    <div class="empty-state">
                                        No previous sanctions are recorded for this user.
                                    </div>

                                <?php else: ?>

                                    <div class="sanction-list">

                                        <?php foreach ($sanctionHistory as $sanction): ?>

                                            <?php

                                            $lifterName =
                                                sanctions_user_name(
                                                    $sanction,
                                                    'lifted_by_display_name',
                                                    'lifted_by_username'
                                                );

                                            $expiredNaturally =
                                                $sanction['expires_at'] !== null
                                                && strtotime(
                                                    (string) $sanction['expires_at']
                                                ) <= time()
                                                && $sanction['lifted_by'] === null;

                                            ?>

                                            <article class="sanction-card">

                                                <div class="sanction-card-header">

                                                    <h4>
                                                        <?= e(
                                                            sanctions_type_label(
                                                                (string) $sanction['sanction_type']
                                                            )
                                                        ); ?>
                                                    </h4>

                                                    <span class="sanction-badge">
                                                        <?= $expiredNaturally
                                                            ? 'Expired'
                                                            : 'Lifted'; ?>
                                                    </span>

                                                </div>

                                                <p class="sanction-meta">

                                                    <span>
                                                        Issued:
                                                        <?= e(
                                                            sanctions_datetime(
                                                                (string) $sanction['starts_at']
                                                            )
                                                        ); ?>
                                                    </span>

                                                    <?php if ($expiredNaturally): ?>

                                                        <span>
                                                            Expired:
                                                            <?= e(
                                                                sanctions_datetime(
                                                                    (string) $sanction['expires_at']
                                                                )
                                                            ); ?>
                                                        </span>

                                                    <?php elseif ($sanction['lifted_at'] !== null): ?>

                                                        <span>
                                                            Lifted:
                                                            <?= e(
                                                                sanctions_datetime(
                                                                    (string) $sanction['lifted_at']
                                                                )
                                                            ); ?>
                                                        </span>

                                                        <?php if ($lifterName !== ''): ?>
                                                            <span>
                                                                By <?= e($lifterName); ?>
                                                            </span>
                                                        <?php endif; ?>

                                                    <?php endif; ?>

                                                </p>

                                                <div class="sanction-reason">

                                                    <strong>
                                                        Original Reason
                                                    </strong>

                                                    <div>
                                                        <?= nl2br(
                                                            e(
                                                                (string) (
                                                                    $sanction['reason']
                                                                    ?? ''
                                                                )
                                                            )
                                                        ); ?>
                                                    </div>

                                                </div>

                                            </article>

                                        <?php endforeach; ?>

                                    </div>

                                <?php endif; ?>

                            </section>

                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </div>
    </section>

</main>

<?php

require
    INCLUDES_PATH
    . '/footer.php';

?>
