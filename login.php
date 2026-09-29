<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';


/*
|--------------------------------------------------------------------------
| Already Logged In
|--------------------------------------------------------------------------
*/

if (is_logged_in()) {

    redirect(
        HOME_URL
    );

}


/*
|--------------------------------------------------------------------------
| SEO
|--------------------------------------------------------------------------
|
| Login/account utility pages should not appear in search results.
|
*/

$pageTitle =
    'Academy Login | Blackthorne Academy';

$pageDescription =
    'Log in to your Blackthorne Academy account to continue your studies and return to academy life.';

$pageCanonical =
    LOGIN_URL;

$robots =
    'noindex, nofollow';


/*
|--------------------------------------------------------------------------
| Login Form State
|--------------------------------------------------------------------------
*/

$loginIdentifier =
    '';

$rememberMe =
    false;

$loginNotice =
    null;

$loginNoticeRole =
    'alert';


/*
|--------------------------------------------------------------------------
| Login Protection
|--------------------------------------------------------------------------
|
| Failed attempts are recorded in login_attempts. A short temporary throttle
| slows repeated guessing without permanently locking the account.
|
*/

const BLACKTHORNE_LOGIN_WINDOW_MINUTES =
    15;

const BLACKTHORNE_LOGIN_MAX_IP_FAILURES =
    10;

const BLACKTHORNE_LOGIN_MAX_IDENTIFIER_FAILURES =
    6;


/*
|--------------------------------------------------------------------------
| Record Login Attempt
|--------------------------------------------------------------------------
*/

function blackthorne_record_login_attempt(
    string $identifier,
    ?int $userId,
    bool $wasSuccessful
): void {

    global $pdo;


    try {

        $statement =
            $pdo->prepare(
                '
                INSERT INTO login_attempts (
                    username_or_email,
                    user_id,
                    ip_address,
                    user_agent,
                    was_successful
                )
                VALUES (
                    :username_or_email,
                    :user_id,
                    :ip_address,
                    :user_agent,
                    :was_successful
                )
                '
            );


        $statement->execute([
            'username_or_email' =>
                $identifier !== ''
                    ? substr(
                        $identifier,
                        0,
                        255
                    )
                    : null,

            'user_id' =>
                $userId,

            'ip_address' =>
                auth_ip_address(),

            'user_agent' =>
                auth_user_agent(),

            'was_successful' =>
                $wasSuccessful
                    ? 1
                    : 0,
        ]);


    } catch (
        PDOException $exception
    ) {

        /*
         * Logging a security event should never expose a database error to
         * the visitor or prevent an otherwise valid login.
         */

        error_log(
            'Blackthorne login-attempt logging error: '
            . $exception->getMessage()
        );

    }

}


/*
|--------------------------------------------------------------------------
| Process Login
|--------------------------------------------------------------------------
*/

if (
    is_post()
) {

    /*
    |--------------------------------------------------------------------------
    | Read Form Values
    |--------------------------------------------------------------------------
    */

    $loginIdentifier =
        trim(
            post_value(
                'identifier'
            )
        );


    /*
     * Passwords must never be passed through a trimming/general text helper.
     */

    $password =
        isset(
            $_POST['password']
        )
        &&
        is_string(
            $_POST['password']
        )
            ? $_POST['password']
            : '';


    $rememberMe =
        isset(
            $_POST['remember_me']
        )
        &&
        $_POST['remember_me'] === '1';


    /*
    |--------------------------------------------------------------------------
    | CSRF
    |--------------------------------------------------------------------------
    */

    $csrfToken =
        $_POST['_csrf_token']
        ?? '';


    if (
        !is_string(
            $csrfToken
        )
        ||
        !verify_csrf_token(
            $csrfToken
        )
    ) {

        $loginNotice =
            'Your login form session expired. Refresh the page and try again.';

    } elseif (
        $loginIdentifier === ''
        ||
        $password === ''
    ) {

        $loginNotice =
            'Enter your username or email address and password.';

    } else {

        /*
        |--------------------------------------------------------------------------
        | Temporary Rate Limit
        |--------------------------------------------------------------------------
        */

        $rateLimitExceeded =
            false;


        try {

            $ipAddress =
                auth_ip_address();


            $rateLimitStatement =
                $pdo->prepare(
                    '
                    SELECT
                        SUM(
                            CASE
                                WHEN ip_address = :ip_address
                                    THEN 1
                                ELSE 0
                            END
                        ) AS ip_failures,

                        SUM(
                            CASE
                                WHEN username_or_email = :identifier
                                    THEN 1
                                ELSE 0
                            END
                        ) AS identifier_failures

                    FROM login_attempts

                    WHERE was_successful = 0
                      AND attempted_at >= DATE_SUB(
                            NOW(),
                            INTERVAL 15 MINUTE
                      )
                      AND (
                            ip_address = :ip_address_again
                            OR username_or_email = :identifier_again
                      )
                    '
                );


            $rateLimitStatement->execute([
                'ip_address' =>
                    $ipAddress,

                'identifier' =>
                    $loginIdentifier,

                'ip_address_again' =>
                    $ipAddress,

                'identifier_again' =>
                    $loginIdentifier,
            ]);


            $rateLimit =
                $rateLimitStatement->fetch(
                    PDO::FETCH_ASSOC
                );


            $ipFailures =
                (int) (
                    $rateLimit['ip_failures']
                    ?? 0
                );


            $identifierFailures =
                (int) (
                    $rateLimit['identifier_failures']
                    ?? 0
                );


            $rateLimitExceeded =
                $ipFailures
                    >=
                    BLACKTHORNE_LOGIN_MAX_IP_FAILURES
                ||
                $identifierFailures
                    >=
                    BLACKTHORNE_LOGIN_MAX_IDENTIFIER_FAILURES;


        } catch (
            PDOException $exception
        ) {

            error_log(
                'Blackthorne login rate-limit error: '
                . $exception->getMessage()
            );

        }


        if (
            $rateLimitExceeded
        ) {

            $loginNotice =
                'Too many unsuccessful login attempts were made recently. Wait about 15 minutes and try again.';

        } else {

            /*
            |--------------------------------------------------------------------------
            | Find Account
            |--------------------------------------------------------------------------
            */

            $user =
                null;


            try {

                $userStatement =
                    $pdo->prepare(
                        '
                        SELECT
                            id,
                            username,
                            email,
                            password_hash,
                            email_verified_at,
                            status

                        FROM users

                        WHERE username = :username
                           OR email = :email

                        LIMIT 1
                        '
                    );


                $userStatement->execute([
                    'username' =>
                        $loginIdentifier,

                    'email' =>
                        $loginIdentifier,
                ]);


                $user =
                    $userStatement->fetch(
                        PDO::FETCH_ASSOC
                    );


            } catch (
                PDOException $exception
            ) {

                error_log(
                    'Blackthorne login account lookup error: '
                    . $exception->getMessage()
                );


                $loginNotice =
                    'Blackthorne could not process your login right now. Please try again shortly.';

            }


            if (
                $loginNotice === null
            ) {

                /*
                |--------------------------------------------------------------------------
                | Verify Credentials
                |--------------------------------------------------------------------------
                */

                $credentialsValid =
                    $user !== false
                    &&
                    is_array(
                        $user
                    )
                    &&
                    isset(
                        $user['password_hash']
                    )
                    &&
                    is_string(
                        $user['password_hash']
                    )
                    &&
                    password_verify(
                        $password,
                        $user['password_hash']
                    );


                if (
                    !$credentialsValid
                ) {

                    blackthorne_record_login_attempt(
                        $loginIdentifier,
                        is_array($user)
                        &&
                        isset(
                            $user['id']
                        )
                            ? (int) $user['id']
                            : null,
                        false
                    );


                    /*
                     * Keep this message generic so an attacker cannot learn
                     * whether a username or email address exists.
                     */

                    $loginNotice =
                        'The username/email or password you entered is incorrect.';

                } else {

                    $userId =
                        (int) $user['id'];


                    /*
                    |--------------------------------------------------------------------------
                    | Verified Email Required
                    |--------------------------------------------------------------------------
                    */

                    if (
                        empty(
                            $user['email_verified_at']
                        )
                        ||
                        (string) $user['status']
                            === 'pending'
                    ) {

                        blackthorne_record_login_attempt(
                            $loginIdentifier,
                            $userId,
                            false
                        );


                        $loginNotice =
                            'Your password is correct, but your academy account still needs email verification. Open your verification email and complete that step before logging in.';

                    } elseif (
                        (string) $user['status']
                            === 'suspended'
                    ) {

                        blackthorne_record_login_attempt(
                            $loginIdentifier,
                            $userId,
                            false
                        );


                        $loginNotice =
                            'This academy account is currently suspended. Contact academy administration if you believe this is an error.';

                    } elseif (
                        (string) $user['status']
                            === 'archived'
                    ) {

                        blackthorne_record_login_attempt(
                            $loginIdentifier,
                            $userId,
                            false
                        );


                        $loginNotice =
                            'This academy account is archived and cannot currently be used to log in. Contact academy administration for assistance.';

                    } elseif (
                        (string) $user['status']
                            !== 'active'
                    ) {

                        blackthorne_record_login_attempt(
                            $loginIdentifier,
                            $userId,
                            false
                        );


                        $loginNotice =
                            'This academy account is not currently available for login. Contact academy administration for assistance.';

                    } else {

                        /*
                        |--------------------------------------------------------------------------
                        | Rehash Password When Needed
                        |--------------------------------------------------------------------------
                        */

                        if (
                            password_needs_rehash(
                                $user['password_hash'],
                                PASSWORD_DEFAULT
                            )
                        ) {

                            try {

                                $newPasswordHash =
                                    password_hash(
                                        $password,
                                        PASSWORD_DEFAULT
                                    );


                                if (
                                    $newPasswordHash
                                    !== false
                                ) {

                                    $rehashStatement =
                                        $pdo->prepare(
                                            '
                                            UPDATE users
                                            SET password_hash = :password_hash
                                            WHERE id = :user_id
                                            '
                                        );


                                    $rehashStatement->execute([
                                        'password_hash' =>
                                            $newPasswordHash,

                                        'user_id' =>
                                            $userId,
                                    ]);

                                }


                            } catch (
                                PDOException $exception
                            ) {

                                error_log(
                                    'Blackthorne password rehash error: '
                                    . $exception->getMessage()
                                );

                            }

                        }


                        /*
                        |--------------------------------------------------------------------------
                        | Establish Authenticated Session
                        |--------------------------------------------------------------------------
                        */

                        if (
                            create_authenticated_session(
                                $userId,
                                $rememberMe
                            )
                        ) {

                            blackthorne_record_login_attempt(
                                $loginIdentifier,
                                $userId,
                                true
                            );


                            redirect(
                                HOME_URL
                            );

                        }


                        blackthorne_record_login_attempt(
                            $loginIdentifier,
                            $userId,
                            false
                        );


                        $loginNotice =
                            'Blackthorne could not create your login session right now. Please try again.';

                    }

                }

            }

        }

    }

}


require INCLUDES_PATH . '/header.php';

?>

<main id="main-content" class="login-page">


    <!-- ================================================================
         LOGIN HERO
    ================================================================= -->

    <section class="login-hero" aria-labelledby="login-page-heading">


        <!-- Decorative Hero Corners -->

        <div class="hero-ornament hero-ornament-left" aria-hidden="true"></div>


        <div class="hero-ornament hero-ornament-right" aria-hidden="true"></div>


        <div class="section-inner login-hero-inner">


            <!-- ========================================================
                 Hero Introduction
            ========================================================= -->

            <div class="login-hero-copy">

                <p class="academy-overline">
                    Academy Access
                </p>


                <h1 id="login-page-heading">
                    The gates remember you.
                </h1>


                <p class="login-hero-intro">
                    Return to Blackthorne and continue the story already
                    written in its halls: your studies, your progress, your
                    House, and the academy history that belongs to you.
                </p>


                <div class="login-hero-motto" aria-hidden="true">

                    <span></span>

                    <p>
                        Welcome back to Blackthorne.
                    </p>

                </div>

            </div>


            <!-- ========================================================
                 Login Panel
            ========================================================= -->

            <div class="login-page-panel" aria-labelledby="login-form-heading">


                <!-- Crest -->

                <div class="login-page-crest">

                    <img src="<?= e(asset('images/logo/logo_3.png')); ?>" alt="" width="120" height="120"
                        loading="eager" decoding="async" fetchpriority="high">

                </div>


                <!-- Heading -->

                <div class="login-page-panel-heading">

                    <p class="login-eyebrow">
                        Blackthorne Academy
                    </p>


                    <h2 id="login-form-heading">
                        Enter the Academy
                    </h2>


                    <p>
                        Student &amp; staff access
                    </p>

                </div>


                <!-- Ornament -->

                <div class="ornamental-rule" aria-hidden="true">

                    <span></span>

                    <i></i>

                    <span></span>

                </div>


                <!-- Screen Reader Description -->

                <p class="sr-only" id="login-form-description">
                    Log in to Blackthorne Academy using your username or
                    email address and password.
                </p>


                <!-- ====================================================
                     Login Notice
                ===================================================== -->

                <?php if ($loginNotice !== null): ?>

                <div class="login-page-notice" role="<?= e($loginNoticeRole); ?>">

                    <span aria-hidden="true">
                        ✦
                    </span>


                    <p>
                        <?= e($loginNotice); ?>
                    </p>

                </div>

                <?php endif; ?>


                <!-- ====================================================
                     Login Form
                ===================================================== -->

                <form class="login-page-form" action="<?= e(LOGIN_URL); ?>" method="post"
                    aria-describedby="login-form-description">

                    <?= csrf_field(); ?>


                    <!-- Username / Email -->

                    <div class="form-group">

                        <label for="login-identifier">
                            Username or Email
                        </label>


                        <input class="form-control" type="text" id="login-identifier" name="identifier"
                            value="<?= e($loginIdentifier); ?>" maxlength="254" autocomplete="username"
                            autocapitalize="none" spellcheck="false" enterkeyhint="next" required>

                    </div>


                    <!-- Password -->

                    <div class="form-group">

                        <label for="login-password">
                            Password
                        </label>


                        <div class="login-password-field">

                            <input class="form-control" type="password" id="login-password" name="password"
                                autocomplete="current-password" enterkeyhint="go" required>


                            <button type="button" class="login-password-toggle" data-password-toggle
                                aria-controls="login-password" aria-pressed="false">

                                <span class="password-show-text">
                                    Show
                                </span>

                                <span class="password-hide-text">
                                    Hide
                                </span>

                            </button>

                        </div>

                    </div>


                    <!-- Login Options -->

                    <div class="login-page-options">

                        <label class="remember-me">

                            <input type="checkbox" name="remember_me" value="1" <?= $rememberMe ? 'checked' : ''; ?>>


                            <span>
                                Remember me
                            </span>

                        </label>


                        <a href="<?= e(url('forgot-password.php')); ?>" class="login-help-link">
                            Trouble logging in?
                        </a>

                    </div>


                    <!-- Submit -->

                    <button type="submit" class="button button-primary login-page-submit">
                        Enter the Academy
                    </button>


                    <!-- Enrollment -->

                    <div class="login-page-enrollment">

                        <p>
                            Your story has not begun yet?
                        </p>


                        <a href="<?= e(REGISTER_URL); ?>">
                            Enroll at Blackthorne
                        </a>

                    </div>

                </form>


                <!-- ====================================================
                     Security Note
                ===================================================== -->

                <div class="login-security-note">

                    <span aria-hidden="true">
                        ✦
                    </span>


                    <p>
                        Blackthorne Academy will never ask you to send your
                        password by email or through academy correspondence.
                    </p>

                </div>

            </div>

        </div>

    </section>

</main>


<script>
    /*
|--------------------------------------------------------------------------
| Login Password Visibility
|--------------------------------------------------------------------------
*/

    document.addEventListener(
        'DOMContentLoaded',
        () => {

            const toggle =
                document.querySelector(
                    '[data-password-toggle]'
                );

            const password =
                document.getElementById(
                    'login-password'
                );

            if (
                !toggle ||
                !password
            ) {

                return;

            }

            toggle.addEventListener(
                'click',
                () => {

                    const isVisible =
                        password.type === 'text';

                    password.type =
                        isVisible ?
                        'password' :
                        'text';

                    toggle.setAttribute(
                        'aria-pressed',
                        isVisible ?
                        'false' :
                        'true'
                    );

                }
            );

        }
    );

</script>


<script src="<?= e(asset('js/main.js')); ?>"></script>

</body>

</html>
