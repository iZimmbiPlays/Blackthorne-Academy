<?php

declare(strict_types=1);

/**
 * Blackthorne Academy
 * Authentication Helpers
 */


/*
|--------------------------------------------------------------------------
| Authentication Constants
|--------------------------------------------------------------------------
*/

const BLACKTHORNE_REMEMBER_COOKIE =
    'blackthorne_remember';

const BLACKTHORNE_STANDARD_SESSION_LIFETIME =
    86400; // 24 hours


/*
|--------------------------------------------------------------------------
| Authentication Token Helpers
|--------------------------------------------------------------------------
*/

function auth_generate_token(
    int $bytes = 32
): string {

    return bin2hex(
        random_bytes(
            $bytes
        )
    );

}


function auth_hash_token(
    string $token
): string {

    return hash(
        'sha256',
        $token
    );

}


/*
|--------------------------------------------------------------------------
| Request Information
|--------------------------------------------------------------------------
*/

function auth_ip_address(): ?string
{
    $ip =
        trim(
            (string) (
                $_SERVER['REMOTE_ADDR']
                ?? ''
            )
        );


    if ($ip === '') {
        return null;
    }


    return substr(
        $ip,
        0,
        45
    );
}


function auth_user_agent(): ?string
{
    $userAgent =
        trim(
            (string) (
                $_SERVER['HTTP_USER_AGENT']
                ?? ''
            )
        );


    if ($userAgent === '') {
        return null;
    }


    return substr(
        $userAgent,
        0,
        500
    );
}


/*
|--------------------------------------------------------------------------
| Remember Cookie
|--------------------------------------------------------------------------
*/

function auth_set_remember_cookie(
    int $sessionId,
    string $rememberToken
): void {

    $cookieValue =
        $sessionId
        . ':'
        . $rememberToken;


    setcookie(
        BLACKTHORNE_REMEMBER_COOKIE,
        $cookieValue,
        [
            'expires' =>
                time()
                + REMEMBER_ME_LIFETIME,

            'path' =>
                '/',

            'domain' =>
                '',

            'secure' =>
                true,

            'httponly' =>
                true,

            'samesite' =>
                'Lax',
        ]
    );


    /*
     * Make the new cookie available during this request as well.
     */

    $_COOKIE[BLACKTHORNE_REMEMBER_COOKIE] =
        $cookieValue;

}


function auth_clear_remember_cookie(): void
{
    setcookie(
        BLACKTHORNE_REMEMBER_COOKIE,
        '',
        [
            'expires' =>
                time() - 3600,

            'path' =>
                '/',

            'domain' =>
                '',

            'secure' =>
                true,

            'httponly' =>
                true,

            'samesite' =>
                'Lax',
        ]
    );


    unset(
        $_COOKIE[
            BLACKTHORNE_REMEMBER_COOKIE
        ]
    );
}


/*
|--------------------------------------------------------------------------
| Account Sanction Helpers
|--------------------------------------------------------------------------
|
| Account suspensions and bans are stored in user_sanctions rather than
| duplicating sanction state into users.status. A sanction is effective when
| it is active, has started, and has not expired.
|
*/

function auth_active_account_sanction(
    int $userId
): ?array {
    global $pdo;

    if ($userId <= 0) {
        return null;
    }

    try {
        $statement =
            $pdo->prepare(
                'SELECT
                    id,
                    sanction_type,
                    reason,
                    starts_at,
                    expires_at,
                    issued_by
                 FROM user_sanctions
                 WHERE user_id = :user_id
                   AND sanction_type IN (
                        "account_suspension",
                        "account_ban"
                   )
                   AND is_active = 1
                   AND starts_at <= NOW()
                   AND (
                        expires_at IS NULL
                        OR expires_at > NOW()
                   )
                 ORDER BY
                    CASE
                        WHEN sanction_type = "account_ban" THEN 1
                        ELSE 2
                    END ASC,
                    created_at DESC,
                    id DESC
                 LIMIT 1'
            );

        $statement->execute([
            'user_id' =>
                $userId,
        ]);

        $sanction =
            $statement->fetch(
                PDO::FETCH_ASSOC
            );

        return
            $sanction
                ?: null;

    } catch (PDOException $exception) {
        error_log(
            'Blackthorne account sanction lookup error: '
            . $exception->getMessage()
        );

        /*
         * Authentication should not fail closed because of a temporary
         * sanction-query error. The database-backed account/session checks
         * still apply normally.
         */
        return null;
    }
}


function auth_user_has_active_account_sanction(
    int $userId
): bool {
    return
        auth_active_account_sanction(
            $userId
        )
        !== null;
}


function auth_deactivate_user_sessions(
    int $userId
): void {
    global $pdo;

    if ($userId <= 0) {
        return;
    }

    try {
        $statement =
            $pdo->prepare(
                'UPDATE user_sessions
                 SET is_active = 0
                 WHERE user_id = :user_id
                   AND is_active = 1'
            );

        $statement->execute([
            'user_id' =>
                $userId,
        ]);

    } catch (PDOException $exception) {
        error_log(
            'Blackthorne session deactivation error: '
            . $exception->getMessage()
        );
    }
}


/*
|--------------------------------------------------------------------------
| Create Login Session
|--------------------------------------------------------------------------
*/

function create_authenticated_session(
    int $userId,
    bool $rememberMe = false
): bool {

    global $pdo;


    /*
    |--------------------------------------------------------------------------
    | Account Sanctions
    |--------------------------------------------------------------------------
    */

    if (
        auth_user_has_active_account_sanction(
            $userId
        )
    ) {
        auth_deactivate_user_sessions(
            $userId
        );

        return false;
    }


    /*
    |--------------------------------------------------------------------------
    | Generate Secure Tokens
    |--------------------------------------------------------------------------
    */

    try {

        $sessionToken =
            auth_generate_token();


        $rememberToken =
            $rememberMe
                ? auth_generate_token()
                : null;


    } catch (Throwable $exception) {

        error_log(
            'Blackthorne authentication token generation error: '
            . $exception->getMessage()
        );


        return false;

    }


    $sessionTokenHash =
        auth_hash_token(
            $sessionToken
        );


    $rememberTokenHash =
        $rememberToken !== null
            ? auth_hash_token(
                $rememberToken
            )
            : null;


    /*
    |--------------------------------------------------------------------------
    | Expiration
    |--------------------------------------------------------------------------
    */

    $lifetime =
        $rememberMe
            ? REMEMBER_ME_LIFETIME
            : BLACKTHORNE_STANDARD_SESSION_LIFETIME;


    $expiresAt =
        (
            new DateTimeImmutable(
                'now'
            )
        )
        ->modify(
            '+'
            . $lifetime
            . ' seconds'
        )
        ->format(
            'Y-m-d H:i:s'
        );


    try {

        /*
        |--------------------------------------------------------------------------
        | Store Session
        |--------------------------------------------------------------------------
        */

        $statement =
            $pdo->prepare(
                '
                INSERT INTO user_sessions (
                    user_id,
                    session_token_hash,
                    remember_token_hash,
                    ip_address,
                    user_agent,
                    is_active,
                    expires_at,
                    last_activity_at
                )
                VALUES (
                    :user_id,
                    :session_token_hash,
                    :remember_token_hash,
                    :ip_address,
                    :user_agent,
                    1,
                    :expires_at,
                    NOW()
                )
                '
            );


        $statement->execute([
            'user_id' =>
                $userId,

            'session_token_hash' =>
                $sessionTokenHash,

            'remember_token_hash' =>
                $rememberTokenHash,

            'ip_address' =>
                auth_ip_address(),

            'user_agent' =>
                auth_user_agent(),

            'expires_at' =>
                $expiresAt,
        ]);


        $sessionId =
            (int) $pdo->lastInsertId();


        /*
        |--------------------------------------------------------------------------
        | Regenerate PHP Session ID
        |--------------------------------------------------------------------------
        */

        session_regenerate_id(
            true
        );


        /*
        |--------------------------------------------------------------------------
        | PHP Session
        |--------------------------------------------------------------------------
        */

        $_SESSION['user_id'] =
            $userId;


        $_SESSION['auth_session_id'] =
            $sessionId;


        $_SESSION['auth_session_token'] =
            $sessionToken;


        /*
        |--------------------------------------------------------------------------
        | Remember Me
        |--------------------------------------------------------------------------
        */

        if (
            $rememberMe
            &&
            $rememberToken !== null
        ) {

            auth_set_remember_cookie(
                $sessionId,
                $rememberToken
            );

        } else {

            auth_clear_remember_cookie();

        }


        /*
        |--------------------------------------------------------------------------
        | Last Login
        |--------------------------------------------------------------------------
        */

        $lastLoginStatement =
            $pdo->prepare(
                '
                UPDATE users
                SET last_login = NOW()
                WHERE id = :user_id
                '
            );


        $lastLoginStatement->execute([
            'user_id' =>
                $userId,
        ]);


        return true;


    } catch (PDOException $exception) {

        error_log(
            'Blackthorne authentication session creation error: '
            . $exception->getMessage()
        );


        return false;

    }

}


/*
|--------------------------------------------------------------------------
| Validate Current Session
|--------------------------------------------------------------------------
*/

function validate_authenticated_session(): bool
{
    global $pdo;


    if (
        !isset(
            $_SESSION['user_id'],
            $_SESSION['auth_session_id'],
            $_SESSION['auth_session_token']
        )
    ) {

        return false;

    }


    if (
        !is_numeric(
            $_SESSION['user_id']
        )
        ||
        !is_numeric(
            $_SESSION['auth_session_id']
        )
        ||
        !is_string(
            $_SESSION['auth_session_token']
        )
    ) {

        return false;

    }


    $userId =
        (int) $_SESSION['user_id'];


    $sessionId =
        (int) $_SESSION['auth_session_id'];


    $rawToken =
        $_SESSION['auth_session_token'];


    if ($rawToken === '') {

        return false;

    }


    try {

        $statement =
            $pdo->prepare(
                '
                SELECT
                    session_token_hash
                FROM user_sessions
                WHERE id = :session_id
                  AND user_id = :user_id
                  AND is_active = 1
                  AND expires_at > NOW()
                LIMIT 1
                '
            );


        $statement->execute([
            'session_id' =>
                $sessionId,

            'user_id' =>
                $userId,
        ]);


        $session =
            $statement->fetch(
                PDO::FETCH_ASSOC
            );


        if (!$session) {

            return false;

        }


        $expectedHash =
            (string) $session[
                'session_token_hash'
            ];


        $actualHash =
            auth_hash_token(
                $rawToken
            );


        if (
            !hash_equals(
                $expectedHash,
                $actualHash
            )
        ) {

            return false;

        }


        /*
        |--------------------------------------------------------------------------
        | Update Activity
        |--------------------------------------------------------------------------
        */

        $activityStatement =
            $pdo->prepare(
                '
                UPDATE user_sessions
                SET
                    last_activity_at = NOW(),
                    ip_address = :ip_address,
                    user_agent = :user_agent
                WHERE id = :session_id
                '
            );


        $activityStatement->execute([
            'ip_address' =>
                auth_ip_address(),

            'user_agent' =>
                auth_user_agent(),

            'session_id' =>
                $sessionId,
        ]);


        return true;


    } catch (PDOException $exception) {

        error_log(
            'Blackthorne session validation error: '
            . $exception->getMessage()
        );


        return false;

    }

}


/*
|--------------------------------------------------------------------------
| Restore Remembered Login
|--------------------------------------------------------------------------
*/

function restore_remembered_login(): bool
{
    global $pdo;


    /*
     * A valid PHP login session already exists.
     */

    if (
        isset($_SESSION['user_id'])
    ) {

        return false;

    }


    $cookie =
        $_COOKIE[
            BLACKTHORNE_REMEMBER_COOKIE
        ]
        ?? '';


    if (
        !is_string($cookie)
        ||
        $cookie === ''
    ) {

        return false;

    }


    /*
    |--------------------------------------------------------------------------
    | Parse Cookie
    |--------------------------------------------------------------------------
    |
    | Format:
    |
    | session_id:raw_remember_token
    |
    */

    $parts =
        explode(
            ':',
            $cookie,
            2
        );


    if (
        count($parts) !== 2
        ||
        !ctype_digit(
            $parts[0]
        )
        ||
        $parts[1] === ''
    ) {

        auth_clear_remember_cookie();

        return false;

    }


    $sessionId =
        (int) $parts[0];


    $rawRememberToken =
        $parts[1];


    try {

        /*
        |--------------------------------------------------------------------------
        | Find Remembered Session
        |--------------------------------------------------------------------------
        */

        $statement =
            $pdo->prepare(
                '
                SELECT
                    us.id,
                    us.user_id,
                    us.remember_token_hash,

                    u.status,
                    u.email_verified_at

                FROM user_sessions us

                INNER JOIN users u
                    ON u.id = us.user_id

                WHERE us.id = :session_id
                  AND us.is_active = 1
                  AND us.remember_token_hash IS NOT NULL
                  AND us.expires_at > NOW()

                LIMIT 1
                '
            );


        $statement->execute([
            'session_id' =>
                $sessionId,
        ]);


        $rememberedSession =
            $statement->fetch(
                PDO::FETCH_ASSOC
            );


        if (!$rememberedSession) {

            auth_clear_remember_cookie();

            return false;

        }


        /*
        |--------------------------------------------------------------------------
        | Verify Remember Token
        |--------------------------------------------------------------------------
        */

        $storedRememberHash =
            (string) $rememberedSession[
                'remember_token_hash'
            ];


        $submittedRememberHash =
            auth_hash_token(
                $rawRememberToken
            );


        if (
            !hash_equals(
                $storedRememberHash,
                $submittedRememberHash
            )
        ) {

            /*
             * A mismatched token could indicate a stolen or obsolete cookie.
             * Deactivate the database session.
             */

            $deactivateStatement =
                $pdo->prepare(
                    '
                    UPDATE user_sessions
                    SET is_active = 0
                    WHERE id = :session_id
                    '
                );


            $deactivateStatement->execute([
                'session_id' =>
                    $sessionId,
            ]);


            auth_clear_remember_cookie();

            return false;

        }


        /*
        |--------------------------------------------------------------------------
        | Account Must Still Be Active
        |--------------------------------------------------------------------------
        */

        if (
            (string) $rememberedSession[
                'status'
            ] !== 'active'
            ||
            empty(
                $rememberedSession[
                    'email_verified_at'
                ]
            )
        ) {

            $deactivateStatement =
                $pdo->prepare(
                    '
                    UPDATE user_sessions
                    SET is_active = 0
                    WHERE id = :session_id
                    '
                );


            $deactivateStatement->execute([
                'session_id' =>
                    $sessionId,
            ]);


            auth_clear_remember_cookie();

            return false;

        }


        /*
        |--------------------------------------------------------------------------
        | Account Sanctions
        |--------------------------------------------------------------------------
        */

        if (
            auth_user_has_active_account_sanction(
                (int) $rememberedSession['user_id']
            )
        ) {
            auth_deactivate_user_sessions(
                (int) $rememberedSession['user_id']
            );

            auth_clear_remember_cookie();

            return false;
        }


        /*
        |--------------------------------------------------------------------------
        | Rotate Tokens
        |--------------------------------------------------------------------------
        */

        try {

            $newSessionToken =
                auth_generate_token();


            $newRememberToken =
                auth_generate_token();


        } catch (Throwable $exception) {

            error_log(
                'Blackthorne remembered-login token rotation error: '
                . $exception->getMessage()
            );


            return false;

        }


        $newSessionTokenHash =
            auth_hash_token(
                $newSessionToken
            );


        $newRememberTokenHash =
            auth_hash_token(
                $newRememberToken
            );


        $newExpiry =
            (
                new DateTimeImmutable(
                    'now'
                )
            )
            ->modify(
                '+'
                . REMEMBER_ME_LIFETIME
                . ' seconds'
            )
            ->format(
                'Y-m-d H:i:s'
            );


        /*
        |--------------------------------------------------------------------------
        | Update Session Record
        |--------------------------------------------------------------------------
        */

        $rotateStatement =
            $pdo->prepare(
                '
                UPDATE user_sessions

                SET
                    session_token_hash =
                        :session_token_hash,

                    remember_token_hash =
                        :remember_token_hash,

                    expires_at =
                        :expires_at,

                    last_activity_at =
                        NOW(),

                    ip_address =
                        :ip_address,

                    user_agent =
                        :user_agent

                WHERE id =
                    :session_id
                '
            );


        $rotateStatement->execute([
            'session_token_hash' =>
                $newSessionTokenHash,

            'remember_token_hash' =>
                $newRememberTokenHash,

            'expires_at' =>
                $newExpiry,

            'ip_address' =>
                auth_ip_address(),

            'user_agent' =>
                auth_user_agent(),

            'session_id' =>
                $sessionId,
        ]);


        /*
        |--------------------------------------------------------------------------
        | Establish PHP Session
        |--------------------------------------------------------------------------
        */

        session_regenerate_id(
            true
        );


        $_SESSION['user_id'] =
            (int) $rememberedSession[
                'user_id'
            ];


        $_SESSION['auth_session_id'] =
            $sessionId;


        $_SESSION['auth_session_token'] =
            $newSessionToken;


        /*
        |--------------------------------------------------------------------------
        | Rotate Browser Cookie
        |--------------------------------------------------------------------------
        */

        auth_set_remember_cookie(
            $sessionId,
            $newRememberToken
        );


        return true;


    } catch (PDOException $exception) {

        error_log(
            'Blackthorne remembered login error: '
            . $exception->getMessage()
        );


        return false;

    }

}


/*
|--------------------------------------------------------------------------
| Destroy Authentication Session
|--------------------------------------------------------------------------
*/

function logout_current_user(): void
{
    global $pdo;


    $sessionId =
        isset(
            $_SESSION[
                'auth_session_id'
            ]
        )
        &&
        is_numeric(
            $_SESSION[
                'auth_session_id'
            ]
        )
            ? (int) $_SESSION[
                'auth_session_id'
            ]
            : null;


    /*
    |--------------------------------------------------------------------------
    | Deactivate Database Session
    |--------------------------------------------------------------------------
    */

    if ($sessionId !== null) {

        try {

            $statement =
                $pdo->prepare(
                    '
                    UPDATE user_sessions
                    SET is_active = 0
                    WHERE id = :session_id
                    '
                );


            $statement->execute([
                'session_id' =>
                    $sessionId,
            ]);


        } catch (PDOException $exception) {

            error_log(
                'Blackthorne logout database error: '
                . $exception->getMessage()
            );

        }

    }


    /*
    |--------------------------------------------------------------------------
    | Remove Remember Cookie
    |--------------------------------------------------------------------------
    */

    auth_clear_remember_cookie();


    /*
    |--------------------------------------------------------------------------
    | Clear Session Variables
    |--------------------------------------------------------------------------
    */

    $_SESSION = [];


    /*
    |--------------------------------------------------------------------------
    | Remove PHP Session Cookie
    |--------------------------------------------------------------------------
    */

    if (
        ini_get(
            'session.use_cookies'
        )
    ) {

        $params =
            session_get_cookie_params();


        setcookie(
            session_name(),
            '',
            [
                'expires' =>
                    time() - 42000,

                'path' =>
                    $params['path']
                    ?? '/',

                'domain' =>
                    $params['domain']
                    ?? '',

                'secure' =>
                    (bool) (
                        $params['secure']
                        ?? true
                    ),

                'httponly' =>
                    (bool) (
                        $params['httponly']
                        ?? true
                    ),

                'samesite' =>
                    'Lax',
            ]
        );

    }


    /*
    |--------------------------------------------------------------------------
    | Destroy PHP Session
    |--------------------------------------------------------------------------
    */

    session_destroy();

}


/*
|--------------------------------------------------------------------------
| Current User
|--------------------------------------------------------------------------
*/

function current_user(): ?array
{
    global $pdo;

    static $cachedUser = null;

    static $hasChecked = false;


    if ($hasChecked) {

        return $cachedUser;

    }


    $hasChecked =
        true;


    /*
    |--------------------------------------------------------------------------
    | Restore Remember-Me Login
    |--------------------------------------------------------------------------
    */

    if (
        !isset(
            $_SESSION['user_id']
        )
    ) {

        restore_remembered_login();

    }


    /*
    |--------------------------------------------------------------------------
    | No User Session
    |--------------------------------------------------------------------------
    */

    if (
        !isset(
            $_SESSION['user_id']
        )
        ||
        !is_numeric(
            $_SESSION['user_id']
        )
    ) {

        return null;

    }


    /*
    |--------------------------------------------------------------------------
    | Validate Database-Backed Session
    |--------------------------------------------------------------------------
    */

    if (
        !validate_authenticated_session()
    ) {

        unset(
            $_SESSION['user_id'],
            $_SESSION['auth_session_id'],
            $_SESSION['auth_session_token']
        );


        auth_clear_remember_cookie();


        return null;

    }


    $userId =
        (int) $_SESSION[
            'user_id'
        ];


    /*
    |--------------------------------------------------------------------------
    | Fetch User
    |--------------------------------------------------------------------------
    */

    $statement =
        $pdo->prepare(
            '
            SELECT
                id,
                username,
                display_name,
                email,
                email_verified_at,
                date_of_birth,
                status,
                avatar,
                created_at,
                updated_at,
                last_login

            FROM users

            WHERE id =
                :user_id

            LIMIT 1
            '
        );


    $statement->execute([
        'user_id' =>
            $userId,
    ]);


    $user =
        $statement->fetch(
            PDO::FETCH_ASSOC
        );


    if (!$user) {

        unset(
            $_SESSION['user_id'],
            $_SESSION['auth_session_id'],
            $_SESSION['auth_session_token']
        );


        auth_clear_remember_cookie();


        return null;

    }


    /*
    |--------------------------------------------------------------------------
    | Account Sanctions
    |--------------------------------------------------------------------------
    |
    | This protects existing PHP sessions as well as normal logins. If an
    | account sanction became active after the session was created, the user
    | is logged out and all of their database-backed sessions are revoked.
    |
    */

    if (
        auth_user_has_active_account_sanction(
            $userId
        )
    ) {
        auth_deactivate_user_sessions(
            $userId
        );

        unset(
            $_SESSION['user_id'],
            $_SESSION['auth_session_id'],
            $_SESSION['auth_session_token']
        );

        auth_clear_remember_cookie();

        return null;
    }


    $cachedUser =
        $user;


    return $cachedUser;

}


/*
|--------------------------------------------------------------------------
| Authentication Status
|--------------------------------------------------------------------------
*/

function is_logged_in(): bool
{
    return
        current_user()
        !== null;
}


function is_guest(): bool
{
    return
        !is_logged_in();
}


/*
|--------------------------------------------------------------------------
| Account Status
|--------------------------------------------------------------------------
*/

function user_account_status(): ?string
{
    $user =
        current_user();


    if (
        $user === null
    ) {

        return null;

    }


    return
        (string) $user[
            'status'
        ];
}


function user_account_is_active(): bool
{
    return
        user_account_status()
        === 'active';
}


function user_email_is_verified(): bool
{
    $user =
        current_user();


    if (
        $user === null
    ) {

        return false;

    }


    return
        !empty(
            $user[
                'email_verified_at'
            ]
        );
}


/*
|--------------------------------------------------------------------------
| Require Authentication
|--------------------------------------------------------------------------
*/

function require_login(): void
{
    if (
        !is_logged_in()
    ) {

        set_flash(
            'error',
            'Please log in to continue.'
        );


        redirect(
            LOGIN_URL
        );

    }
}


/*
|--------------------------------------------------------------------------
| Require Active Account
|--------------------------------------------------------------------------
*/

function require_active_account(): void
{
    require_login();


    if (
        !user_account_is_active()
    ) {

        http_response_code(
            403
        );


        exit(
            'Your account is not currently active.'
        );

    }
}


/*
|--------------------------------------------------------------------------
| User Roles
|--------------------------------------------------------------------------
*/

function current_user_roles(): array
{
    global $pdo;

    static $cachedRoles = null;


    if (
        $cachedRoles !== null
    ) {

        return $cachedRoles;

    }


    $user =
        current_user();


    if (
        $user === null
    ) {

        $cachedRoles = [];

        return
            $cachedRoles;

    }


    $statement =
        $pdo->prepare(
            '
            SELECT
                r.id,
                r.name,
                r.slug,
                r.description,
                r.display_color,
                r.is_system_role,
                r.grants_all_permissions,
                r.is_staff,
                r.sort_order

            FROM user_roles ur

            INNER JOIN roles r
                ON r.id =
                    ur.role_id

            WHERE ur.user_id =
                    :user_id

              AND ur.is_active =
                    1

              AND ur.revoked_at
                    IS NULL

              AND (
                    ur.expires_at
                        IS NULL

                    OR ur.expires_at
                        > NOW()
                  )

              AND r.is_active =
                    1

            ORDER BY
                r.sort_order ASC,
                r.name ASC
            '
        );


    $statement->execute([
        'user_id' =>
            (int) $user['id'],
    ]);


    $cachedRoles =
        $statement->fetchAll(
            PDO::FETCH_ASSOC
        );


    return
        $cachedRoles;

}


/*
|--------------------------------------------------------------------------
| Role Checks
|--------------------------------------------------------------------------
*/

function user_has_role(
    string $roleSlug
): bool {

    $roleSlug =
        strtolower(
            trim(
                $roleSlug
            )
        );


    foreach (
        current_user_roles()
        as $role
    ) {

        if (
            strtolower(
                (string) $role[
                    'slug'
                ]
            )
            === $roleSlug
        ) {

            return true;

        }

    }


    return false;

}


/*
|--------------------------------------------------------------------------
| Protected Super Admin
|--------------------------------------------------------------------------
|
| Blackthorne Academy has exactly one protected Super Admin. The account is
| designated by the private `super_admin_user_id` setting rather than by a
| public-facing role. This lets the account display the normal Admin role
| while ensuring that no other administrator automatically receives owner
| privileges.
|
*/

function configured_super_admin_user_id(): ?int
{
    global $pdo;

    static $hasChecked = false;
    static $superAdminUserId = null;


    if ($hasChecked) {
        return $superAdminUserId;
    }


    $hasChecked = true;


    try {

        $statement =
            $pdo->prepare(
                '
                SELECT setting_value

                FROM settings

                WHERE setting_key =
                    :setting_key

                  AND setting_type =
                    "integer"

                  AND is_public =
                    0

                LIMIT 1
                '
            );


        $statement->execute([
            'setting_key' =>
                'super_admin_user_id',
        ]);


        $settingValue =
            $statement->fetchColumn();


        if (
            $settingValue === false
            ||
            !is_numeric($settingValue)
        ) {

            return null;

        }


        $candidateUserId =
            (int) $settingValue;


        if ($candidateUserId < 1) {
            return null;
        }


        $superAdminUserId =
            $candidateUserId;


        return $superAdminUserId;


    } catch (PDOException $exception) {

        error_log(
            'Blackthorne Super Admin setting lookup error: '
            . $exception->getMessage()
        );


        return null;

    }
}


function current_user_is_superuser(): bool
{
    $user =
        current_user();


    if (
        $user === null
        ||
        (string) $user['status'] !== 'active'
        ||
        empty($user['email_verified_at'])
    ) {

        return false;

    }


    $superAdminUserId =
        configured_super_admin_user_id();


    return (
        $superAdminUserId !== null
        &&
        (int) $user['id'] === $superAdminUserId
    );
}


/*
|--------------------------------------------------------------------------
| Administrator Check
|--------------------------------------------------------------------------
|
| The protected Super Admin and active users carrying an administrator role
| use the administrative dashboard. Ordinary administrators still receive
| only the permissions explicitly assigned to them.
|
*/

function current_user_is_admin(): bool
{
    /*
     * Protected Super Admin always receives the administrative dashboard.
     */

    if (current_user_is_superuser()) {
        return true;
    }


    /*
     * New capability-based administrator access. This becomes the primary
     * route once the Roles / Groups / Permissions catalog is populated.
     *
     * function_exists() keeps this helper safe during bootstrap ordering.
     */

    if (
        function_exists('user_can')
        && user_can('admin.access')
    ) {
        return true;
    }


    /*
     * Temporary compatibility bridge.
     *
     * Existing Admin / Administrator roles must continue reaching the admin
     * dashboard until their new admin.access permission has been assigned
     * through the Roles / Groups manager. We will remove this legacy bridge
     * after the permission catalog and role editor are live and tested.
     */

    return (
        user_has_role('admin')
        ||
        user_has_role('administrator')
    );
}


/*
|--------------------------------------------------------------------------
| Staff Detection
|--------------------------------------------------------------------------
*/

function current_user_is_staff(): bool
{
    /*
     * Protected Super Admin is always staff regardless of role assignments.
     */

    if (current_user_is_superuser()) {
        return true;
    }


    /*
     * Staff status is now data-driven. Any active, non-revoked, non-expired
     * role/group marked roles.is_staff = 1 makes the user staff. The flag
     * itself grants no permissions.
     */

    foreach (
        current_user_roles()
        as $role
    ) {

        if (
            isset($role['is_staff'])
            && (int) $role['is_staff'] === 1
        ) {

            return true;

        }

    }


    /*
     * Temporary compatibility bridge for the existing built-in staff roles.
     * This prevents current Instructor / Moderator accounts from losing their
     * staff dashboard before the Roles / Groups editor is available to mark
     * those roles as Staff. This block will be removed once the role editor
     * has been built and the existing roles have been saved through it.
     */

    return (
        user_has_role('instructor')
        ||
        user_has_role('moderator')
        ||
        user_has_role('admin')
        ||
        user_has_role('administrator')
    );
}


/*
|--------------------------------------------------------------------------
| Student Enrollment Status
|--------------------------------------------------------------------------
*/

function current_user_has_student_enrollment(): bool
{
    global $pdo;

    static $hasEnrollment = null;


    if (
        $hasEnrollment !== null
    ) {

        return
            $hasEnrollment;

    }


    $user =
        current_user();


    if (
        $user === null
    ) {

        $hasEnrollment =
            false;


        return false;

    }


    $statement =
        $pdo->prepare(
            '
            SELECT id

            FROM student_year_enrollments

            WHERE user_id =
                    :user_id

              AND promotion_status IN (
                    "active",
                    "eligible",
                    "not_eligible",
                    "repeating"
                  )

            LIMIT 1
            '
        );


    $statement->execute([
        'user_id' =>
            (int) $user['id'],
    ]);


    $hasEnrollment =
        (bool) $statement->fetchColumn();


    return
        $hasEnrollment;
}


/*
|--------------------------------------------------------------------------
| Registered User Status
|--------------------------------------------------------------------------
*/

function current_user_is_registered_only(): bool
{
    return (
        is_logged_in()
        &&
        user_account_is_active()
        &&
        user_email_is_verified()
        &&
        !current_user_has_student_enrollment()
        &&
        !current_user_is_staff()
    );
}


/*
|--------------------------------------------------------------------------
| Student Status
|--------------------------------------------------------------------------
*/

function current_user_is_student(): bool
{
    return (
        is_logged_in()
        &&
        user_account_is_active()
        &&
        current_user_has_student_enrollment()
    );
}


/*
|--------------------------------------------------------------------------
| Dashboard Type
|--------------------------------------------------------------------------
*/

function current_dashboard_type(): string
{
    if (
        !is_logged_in()
    ) {

        return 'guest';

    }


    if (
        current_user_is_admin()
    ) {

        return 'admin';

    }


    if (
        current_user_is_staff()
    ) {

        return 'staff';

    }


    if (
        current_user_is_student()
    ) {

        return 'student';

    }


    return 'registered';
}


/*
|--------------------------------------------------------------------------
| Current User ID
|--------------------------------------------------------------------------
*/

function current_user_id(): ?int
{
    $user =
        current_user();


    if (
        $user === null
    ) {

        return null;

    }


    return
        (int) $user[
            'id'
        ];
}


/*
|--------------------------------------------------------------------------
| Automatic New Student / Orientation Onboarding
|--------------------------------------------------------------------------
|
| Every active, verified, non-staff Academy member who has not completed
| Orientation is automatically kept in the Year 0 "New Student" year group
| for the current school year and enrolled in the current Orientation
| offering. Once Orientation has been completed, the Year 0 record is marked
| completed so the registration workflow can advance the student into First
| Year without leaving a second active year assignment behind.
|
*/

function blackthorne_sync_new_student_orientation(
    PDO $pdo,
    int $userId
): void {
    if ($userId <= 0) {
        return;
    }

    /*
     * Every active, verified Academy member participates in automatic student
     * onboarding when Orientation is incomplete. Staff roles do not exclude a
     * member from being a student; a user may hold staff permissions and still
     * need the student academic record for testing or dual-role use.
     */
    $userStatement =
        $pdo->prepare(
            '
            SELECT
                id,
                status,
                email_verified_at

            FROM users

            WHERE id = :user_id

            LIMIT 1
            '
        );

    $userStatement->execute([
        'user_id' => $userId,
    ]);

    $userRow =
        $userStatement->fetch(
            PDO::FETCH_ASSOC
        );

    if (
        !$userRow
        || (string) ($userRow['status'] ?? '') !== 'active'
        || empty($userRow['email_verified_at'])
    ) {
        return;
    }

    /*
     * Locate the Academy Orientation course. The gateway-course relationship
     * is preferred because it is explicit configuration. The title/slug
     * fallback keeps onboarding working before the first registration group
     * has been created.
     */
    $orientationStatement =
        $pdo->query(
            '
            SELECT c.id

            FROM courses c

            LEFT JOIN course_registration_groups crg
                ON crg.gateway_course_id = c.id
               AND crg.is_active = 1

            WHERE c.status <> "archived"
              AND (
                    crg.id IS NOT NULL
                    OR LOWER(c.slug) IN (
                        "orientation",
                        "academy-orientation"
                    )
                    OR LOWER(c.title) = "orientation"
                  )

            ORDER BY
                CASE
                    WHEN crg.id IS NOT NULL THEN 0
                    WHEN LOWER(c.slug) = "orientation" THEN 1
                    WHEN LOWER(c.slug) = "academy-orientation" THEN 2
                    ELSE 3
                END,
                c.id ASC

            LIMIT 1
            '
        );

    $orientationCourseId =
        (int) (
            $orientationStatement->fetchColumn()
            ?: 0
        );

    if ($orientationCourseId <= 0) {
        return;
    }

    /*
     * Completion of any Orientation offering satisfies the onboarding gate.
     */
    $completionStatement =
        $pdo->prepare(
            '
            SELECT ce.id

            FROM course_enrollments ce

            INNER JOIN course_offerings co
                ON co.id = ce.offering_id

            WHERE ce.user_id = :user_id
              AND co.course_id = :course_id
              AND ce.status = "completed"

            LIMIT 1
            '
        );

    $completionStatement->execute([
        'user_id' => $userId,
        'course_id' => $orientationCourseId,
    ]);

    $orientationCompleted =
        (bool) $completionStatement->fetchColumn();

    /*
     * Find the current Academy school year. The explicit is_current flag is
     * authoritative; the date fallback helps during setup if the year was
     * created but not yet marked current.
     */
    $schoolYearStatement =
        $pdo->query(
            '
            SELECT id

            FROM school_years

            WHERE is_active = 1
              AND (
                    is_current = 1
                    OR CURRENT_DATE BETWEEN start_date AND end_date
                  )

            ORDER BY
                is_current DESC,
                start_date DESC,
                id DESC

            LIMIT 1
            '
        );

    $schoolYearId =
        (int) (
            $schoolYearStatement->fetchColumn()
            ?: 0
        );

    /*
     * Year 0 is the required New Student / pre-Orientation year group.
     */
    $newStudentStatement =
        $pdo->query(
            '
            SELECT id

            FROM year_groups

            WHERE year_number = 0
              AND is_active = 1

            ORDER BY sort_order ASC, id ASC

            LIMIT 1
            '
        );

    $newStudentYearGroupId =
        (int) (
            $newStudentStatement->fetchColumn()
            ?: 0
        );

    if (
        $schoolYearId > 0
        && $newStudentYearGroupId > 0
    ) {
        $yearEnrollmentStatement =
            $pdo->prepare(
                '
                SELECT
                    id,
                    year_group_id,
                    promotion_status

                FROM student_year_enrollments

                WHERE user_id = :user_id
                  AND school_year_id = :school_year_id

                LIMIT 1
                '
            );

        $yearEnrollmentStatement->execute([
            'user_id' => $userId,
            'school_year_id' => $schoolYearId,
        ]);

        $yearEnrollment =
            $yearEnrollmentStatement->fetch(
                PDO::FETCH_ASSOC
            );

        if ($orientationCompleted) {
            /*
             * Only complete a Year 0 record. Never alter a student's First
             * Year or later enrollment here.
             */
            if (
                $yearEnrollment
                && (int) ($yearEnrollment['year_group_id'] ?? 0)
                    === $newStudentYearGroupId
                && !in_array(
                    (string) ($yearEnrollment['promotion_status'] ?? ''),
                    ['completed', 'promoted', 'withdrawn'],
                    true
                )
            ) {
                $completeYearStatement =
                    $pdo->prepare(
                        '
                        UPDATE student_year_enrollments

                        SET
                            promotion_status = "completed",
                            progress_percentage = 100.00,
                            completed_at = COALESCE(
                                completed_at,
                                CURRENT_TIMESTAMP
                            )

                        WHERE id = :id
                        '
                    );

                $completeYearStatement->execute([
                    'id' => (int) $yearEnrollment['id'],
                ]);
            }
        } else {
            $progressionRuleStatement =
                $pdo->prepare(
                    '
                    SELECT
                        required_percentage,
                        required_points

                    FROM year_progression_rules

                    WHERE year_group_id = :year_group_id
                      AND is_active = 1

                    LIMIT 1
                    '
                );

            $progressionRuleStatement->execute([
                'year_group_id' => $newStudentYearGroupId,
            ]);

            $progressionRule =
                $progressionRuleStatement->fetch(
                    PDO::FETCH_ASSOC
                );

            $requiredPercentage =
                is_array($progressionRule)
                    ? (float) (
                        $progressionRule['required_percentage']
                        ?? 100.00
                    )
                    : 100.00;

            $requiredPoints =
                is_array($progressionRule)
                && array_key_exists(
                    'required_points',
                    $progressionRule
                )
                && $progressionRule['required_points'] !== null
                    ? (float) $progressionRule['required_points']
                    : null;

            if (!$yearEnrollment) {
                $insertYearStatement =
                    $pdo->prepare(
                        '
                        INSERT INTO student_year_enrollments (
                            user_id,
                            year_group_id,
                            school_year_id,
                            required_percentage_snapshot,
                            required_points_snapshot,
                            promotion_status
                        ) VALUES (
                            :user_id,
                            :year_group_id,
                            :school_year_id,
                            :required_percentage_snapshot,
                            :required_points_snapshot,
                            "active"
                        )
                        '
                    );

                $insertYearStatement->execute([
                    'user_id' => $userId,
                    'year_group_id' => $newStudentYearGroupId,
                    'school_year_id' => $schoolYearId,
                    'required_percentage_snapshot' => $requiredPercentage,
                    'required_points_snapshot' => $requiredPoints,
                ]);
            } elseif (
                !in_array(
                    (string) ($yearEnrollment['promotion_status'] ?? ''),
                    ['withdrawn', 'completed', 'promoted'],
                    true
                )
                && (int) ($yearEnrollment['year_group_id'] ?? 0)
                    !== $newStudentYearGroupId
            ) {
                /*
                 * Orientation is the prerequisite for Academy-year placement.
                 * Before it is completed, the active current-year record is
                 * normalized back to Year 0.
                 */
                $updateYearStatement =
                    $pdo->prepare(
                        '
                        UPDATE student_year_enrollments

                        SET
                            year_group_id = :year_group_id,
                            academic_points_earned = 0.00,
                            academic_points_possible = 0.00,
                            progress_percentage = 0.00,
                            required_percentage_snapshot = :required_percentage_snapshot,
                            required_points_snapshot = :required_points_snapshot,
                            promotion_status = "active",
                            eligible_at = NULL,
                            completed_at = NULL,
                            promoted_at = NULL,
                            promotion_approved_by = NULL,
                            promotion_notes = NULL

                        WHERE id = :id
                        '
                    );

                $updateYearStatement->execute([
                    'year_group_id' => $newStudentYearGroupId,
                    'required_percentage_snapshot' => $requiredPercentage,
                    'required_points_snapshot' => $requiredPoints,
                    'id' => (int) $yearEnrollment['id'],
                ]);
            }
        }
    }

    if ($orientationCompleted) {
        return;
    }

    /*
     * Give every incomplete New Student an Orientation enrollment. Prefer the
     * perpetual offering model, then the current school-year offering.
     */
    $offeringStatement =
        $pdo->prepare(
            '
            SELECT co.id

            FROM course_offerings co

            WHERE co.course_id = :course_id
              AND co.status IN (
                    "open",
                    "closed",
                    "completed"
                  )
              AND (
                    co.offering_scope = "perpetual"
                    OR co.school_year_id = :school_year_id
                  )

            ORDER BY
                CASE
                    WHEN co.offering_scope = "perpetual" THEN 0
                    ELSE 1
                END,
                CASE
                    WHEN co.status = "open" THEN 0
                    WHEN co.status = "closed" THEN 1
                    ELSE 2
                END,
                co.id DESC

            LIMIT 1
            '
        );

    $offeringStatement->execute([
        'course_id' => $orientationCourseId,
        'school_year_id' => $schoolYearId,
    ]);

    $orientationOfferingId =
        (int) (
            $offeringStatement->fetchColumn()
            ?: 0
        );

    if ($orientationOfferingId <= 0) {
        return;
    }

    $enrollmentStatement =
        $pdo->prepare(
            '
            SELECT
                id,
                status

            FROM course_enrollments

            WHERE user_id = :user_id
              AND offering_id = :offering_id

            LIMIT 1
            '
        );

    $enrollmentStatement->execute([
        'user_id' => $userId,
        'offering_id' => $orientationOfferingId,
    ]);

    $orientationEnrollment =
        $enrollmentStatement->fetch(
            PDO::FETCH_ASSOC
        );

    if (!$orientationEnrollment) {
        $insertEnrollmentStatement =
            $pdo->prepare(
                '
                INSERT INTO course_enrollments (
                    user_id,
                    offering_id,
                    status,
                    progress
                ) VALUES (
                    :user_id,
                    :offering_id,
                    "enrolled",
                    0.00
                )
                '
            );

        $insertEnrollmentStatement->execute([
            'user_id' => $userId,
            'offering_id' => $orientationOfferingId,
        ]);
    }
}


/*
|--------------------------------------------------------------------------
| Run Automatic Student Onboarding
|--------------------------------------------------------------------------
|
| This executes once during the normal shared bootstrap for a logged-in
| member. Failures are logged instead of breaking unrelated Academy pages.
|
*/

if (
    isset($pdo)
    && $pdo instanceof PDO
) {
    try {
        $orientationOnboardingUserId =
            (int) (
                current_user_id()
                ?? 0
            );

        if ($orientationOnboardingUserId > 0) {
            blackthorne_sync_new_student_orientation(
                $pdo,
                $orientationOnboardingUserId
            );
        }
    } catch (Throwable $exception) {
        error_log(
            'Blackthorne automatic Orientation onboarding error: '
            . $exception->getMessage()
        );
    }
}
