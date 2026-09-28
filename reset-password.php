<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';


/*
|--------------------------------------------------------------------------
| Already Logged In
|--------------------------------------------------------------------------
|
| A logged-in member does not need the forgotten-password flow.
|
*/

if (is_logged_in()) {
    redirect(DASHBOARD_URL);
}


/*
|--------------------------------------------------------------------------
| SEO
|--------------------------------------------------------------------------
*/

$pageTitle =
    'Reset Password | Blackthorne Academy';

$pageDescription =
    'Choose a new password for your Blackthorne Academy account.';

$pageCanonical =
    url('reset-password.php');

$robots =
    'noindex, nofollow';


/*
|--------------------------------------------------------------------------
| Page State
|--------------------------------------------------------------------------
*/

$resetToken =
    isset($_GET['token']) && is_string($_GET['token'])
        ? trim($_GET['token'])
        : '';

if (
    is_post()
    &&
    isset($_POST['token'])
    &&
    is_string($_POST['token'])
) {
    $resetToken =
        trim($_POST['token']);
}

$formErrors =
    [];

$tokenRecord =
    null;

$tokenIsValid =
    false;

$passwordWasReset =
    false;


/*
|--------------------------------------------------------------------------
| Validate Reset Token
|--------------------------------------------------------------------------
*/

if ($resetToken !== '') {

    /*
     * Reset tokens are generated as 32 random bytes rendered as 64
     * hexadecimal characters. Reject malformed values before touching
     * the database.
     */
    if (
        strlen($resetToken) === 64
        &&
        ctype_xdigit($resetToken)
    ) {

        try {

            $tokenHash =
                hash(
                    'sha256',
                    $resetToken
                );

            $statement =
                $pdo->prepare(
                    '
                    SELECT
                        pr.id,
                        pr.user_id,
                        pr.expires_at,
                        pr.used_at,
                        u.email,
                        u.display_name,
                        u.status
                    FROM password_resets pr
                    INNER JOIN users u
                        ON u.id = pr.user_id
                    WHERE pr.token_hash = :token_hash
                    LIMIT 1
                    '
                );

            $statement->execute([
                'token_hash' =>
                    $tokenHash,
            ]);

            $tokenRecord =
                $statement->fetch(
                    PDO::FETCH_ASSOC
                );

            if (
                is_array($tokenRecord)
                &&
                $tokenRecord !== []
                &&
                $tokenRecord['used_at'] === null
                &&
                in_array(
                    (string) $tokenRecord['status'],
                    [
                        'active',
                        'pending',
                    ],
                    true
                )
            ) {

                $expiresAt =
                    new DateTimeImmutable(
                        (string) $tokenRecord['expires_at'],
                        new DateTimeZone('America/New_York')
                    );

                $now =
                    new DateTimeImmutable(
                        'now',
                        new DateTimeZone('America/New_York')
                    );

                if ($expiresAt > $now) {
                    $tokenIsValid =
                        true;
                }
            }

        } catch (
            PDOException
            |
            Exception $exception
        ) {

            error_log(
                'Blackthorne password reset token validation error: '
                . $exception->getMessage()
            );

            $formErrors[] =
                'Blackthorne could not validate this reset link right now. Please try again shortly.';
        }
    }
}


/*
|--------------------------------------------------------------------------
| Process New Password
|--------------------------------------------------------------------------
*/

if (
    is_post()
    &&
    $tokenIsValid
    &&
    is_array($tokenRecord)
) {

    $csrfToken =
        $_POST['_csrf_token']
        ?? '';

    if (
        !is_string($csrfToken)
        ||
        !verify_csrf_token($csrfToken)
    ) {

        $formErrors[] =
            'Your form session expired. Refresh the page and try again.';

    }

    $newPassword =
        isset($_POST['password'])
        &&
        is_string($_POST['password'])
            ? $_POST['password']
            : '';

    $confirmPassword =
        isset($_POST['password_confirmation'])
        &&
        is_string($_POST['password_confirmation'])
            ? $_POST['password_confirmation']
            : '';


    /*
    |--------------------------------------------------------------------------
    | Password Validation
    |--------------------------------------------------------------------------
    */

    if ($newPassword === '') {

        $formErrors[] =
            'Enter a new password.';

    } elseif (strlen($newPassword) < 12) {

        $formErrors[] =
            'Your new password must be at least 12 characters long.';

    } elseif (strlen($newPassword) > 255) {

        $formErrors[] =
            'Your new password is too long.';

    }


    if ($confirmPassword === '') {

        $formErrors[] =
            'Confirm your new password.';

    } elseif (
        $newPassword !== ''
        &&
        !hash_equals(
            $newPassword,
            $confirmPassword
        )
    ) {

        $formErrors[] =
            'The passwords do not match.';

    }


    /*
    |--------------------------------------------------------------------------
    | Save Password
    |--------------------------------------------------------------------------
    */

    if ($formErrors === []) {

        try {

            $passwordHash =
                password_hash(
                    $newPassword,
                    PASSWORD_DEFAULT
                );

            if (!is_string($passwordHash)) {
                throw new RuntimeException(
                    'Password hashing failed.'
                );
            }

            $pdo->beginTransaction();

            /*
             * Lock and re-check the token inside the transaction so the same
             * reset link cannot be submitted successfully twice at once.
             */
            $tokenHash =
                hash(
                    'sha256',
                    $resetToken
                );

            $lockStatement =
                $pdo->prepare(
                    '
                    SELECT
                        id,
                        user_id,
                        expires_at,
                        used_at
                    FROM password_resets
                    WHERE token_hash = :token_hash
                    LIMIT 1
                    FOR UPDATE
                    '
                );

            $lockStatement->execute([
                'token_hash' =>
                    $tokenHash,
            ]);

            $lockedToken =
                $lockStatement->fetch(
                    PDO::FETCH_ASSOC
                );

            if (
                !is_array($lockedToken)
                ||
                $lockedToken === []
                ||
                $lockedToken['used_at'] !== null
            ) {
                throw new RuntimeException(
                    'This reset link has already been used.'
                );
            }

            $lockedExpiresAt =
                new DateTimeImmutable(
                    (string) $lockedToken['expires_at'],
                    new DateTimeZone('America/New_York')
                );

            $now =
                new DateTimeImmutable(
                    'now',
                    new DateTimeZone('America/New_York')
                );

            if ($lockedExpiresAt <= $now) {
                throw new RuntimeException(
                    'This reset link has expired.'
                );
            }

            $userId =
                (int) $lockedToken['user_id'];


            /*
             * Update the member password.
             */
            $userStatement =
                $pdo->prepare(
                    '
                    UPDATE users
                    SET
                        password_hash = :password_hash,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :user_id
                    LIMIT 1
                    '
                );

            $userStatement->execute([
                'password_hash' =>
                    $passwordHash,

                'user_id' =>
                    $userId,
            ]);

            if ($userStatement->rowCount() !== 1) {
                throw new RuntimeException(
                    'The account password could not be updated.'
                );
            }


            /*
             * Mark this reset token as used.
             */
            $usedStatement =
                $pdo->prepare(
                    '
                    UPDATE password_resets
                    SET used_at = NOW()
                    WHERE id = :reset_id
                      AND used_at IS NULL
                    LIMIT 1
                    '
                );

            $usedStatement->execute([
                'reset_id' =>
                    (int) $lockedToken['id'],
            ]);

            if ($usedStatement->rowCount() !== 1) {
                throw new RuntimeException(
                    'The reset link could not be finalized.'
                );
            }


            /*
             * Invalidate any other unused reset links for the account.
             */
            $invalidateStatement =
                $pdo->prepare(
                    '
                    UPDATE password_resets
                    SET used_at = NOW()
                    WHERE user_id = :user_id
                      AND used_at IS NULL
                    '
                );

            $invalidateStatement->execute([
                'user_id' =>
                    $userId,
            ]);


            /*
             * Password changes must sign out all existing sessions, including
             * remembered sessions on other browsers/devices.
             */
            $sessionStatement =
                $pdo->prepare(
                    '
                    UPDATE user_sessions
                    SET is_active = 0
                    WHERE user_id = :user_id
                      AND is_active = 1
                    '
                );

            $sessionStatement->execute([
                'user_id' =>
                    $userId,
            ]);


            $pdo->commit();

            $passwordWasReset =
                true;

            set_flash(
                'password_reset_success',
                'Your password has been changed. You can now log in with your new password.'
            );

        } catch (
            Throwable $exception
        ) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'Blackthorne password reset save error: '
                . $exception->getMessage()
            );

            if (
                $exception instanceof RuntimeException
                &&
                in_array(
                    $exception->getMessage(),
                    [
                        'This reset link has already been used.',
                        'This reset link has expired.',
                    ],
                    true
                )
            ) {

                $formErrors[] =
                    $exception->getMessage();

                $tokenIsValid =
                    false;

            } else {

                $formErrors[] =
                    'Your password could not be changed right now. Please try again.';
            }
        }
    }
}


require_once INCLUDES_PATH . '/header.php';

?>


<main class="site-main">

    <section class="login-page-section">

        <div class="container">

            <div class="login-page-card">

                <div class="login-page-heading">

                    <p class="eyebrow">
                        Academy Account
                    </p>

                    <h1>
                        Reset Your Password
                    </h1>

                    <p id="reset-form-description">
                        Choose a new password for your Blackthorne Academy account.
                    </p>

                </div>


                <?php if ($passwordWasReset): ?>

                    <div
                        class="login-page-notice"
                        role="status"
                    >
                        Your password has been changed successfully.
                    </div>

                    <div class="login-page-enrollment">

                        <a
                            class="button button-primary login-page-submit"
                            href="<?= e(LOGIN_URL); ?>"
                        >
                            Return to Login
                        </a>

                    </div>


                <?php elseif (!$tokenIsValid): ?>

                    <?php if ($formErrors !== []): ?>

                        <div
                            class="login-page-notice"
                            role="alert"
                        >

                            <?php foreach ($formErrors as $error): ?>

                                <p>
                                    <?= e($error); ?>
                                </p>

                            <?php endforeach; ?>

                        </div>

                    <?php endif; ?>


                    <div
                        class="login-page-notice"
                        role="alert"
                    >
                        This password reset link is invalid, expired, or has already been used.
                    </div>


                    <div class="login-page-enrollment">

                        <p>
                            Request a new password reset link to continue.
                        </p>

                        <a
                            href="<?= e(url('forgot-password.php')); ?>"
                        >
                            Request a New Reset Link
                        </a>

                    </div>


                <?php else: ?>

                    <?php if ($formErrors !== []): ?>

                        <div
                            class="login-page-notice"
                            role="alert"
                        >

                            <?php foreach ($formErrors as $error): ?>

                                <p>
                                    <?= e($error); ?>
                                </p>

                            <?php endforeach; ?>

                        </div>

                    <?php endif; ?>


                    <form
                        class="login-page-form"
                        action="<?= e(url('reset-password.php')); ?>"
                        method="post"
                        aria-describedby="reset-form-description"
                    >

                        <?= csrf_field(); ?>

                        <input
                            type="hidden"
                            name="token"
                            value="<?= e($resetToken); ?>"
                        >


                        <div class="form-group">

                            <label for="reset-password">
                                New Password
                            </label>

                            <div class="login-password-field">

                                <input
                                    class="form-control"
                                    type="password"
                                    id="reset-password"
                                    name="password"
                                    minlength="12"
                                    maxlength="255"
                                    autocomplete="new-password"
                                    required
                                >

                                <button
                                    type="button"
                                    class="login-password-toggle"
                                    data-password-toggle="reset-password"
                                    aria-controls="reset-password"
                                    aria-pressed="false"
                                >
                                    <span class="password-show-text">
                                        Show
                                    </span>

                                    <span class="password-hide-text">
                                        Hide
                                    </span>
                                </button>

                            </div>

                            <small class="form-help">
                                Use at least 12 characters.
                            </small>

                        </div>


                        <div class="form-group">

                            <label for="reset-password-confirmation">
                                Confirm New Password
                            </label>

                            <div class="login-password-field">

                                <input
                                    class="form-control"
                                    type="password"
                                    id="reset-password-confirmation"
                                    name="password_confirmation"
                                    minlength="12"
                                    maxlength="255"
                                    autocomplete="new-password"
                                    required
                                >

                                <button
                                    type="button"
                                    class="login-password-toggle"
                                    data-password-toggle="reset-password-confirmation"
                                    aria-controls="reset-password-confirmation"
                                    aria-pressed="false"
                                >
                                    <span class="password-show-text">
                                        Show
                                    </span>

                                    <span class="password-hide-text">
                                        Hide
                                    </span>
                                </button>

                            </div>

                        </div>


                        <button
                            type="submit"
                            class="button button-primary login-page-submit"
                        >
                            Change Password
                        </button>

                    </form>

                <?php endif; ?>


                <div class="login-security-note">

                    <span aria-hidden="true">
                        ✦
                    </span>

                    <p>
                        Blackthorne Academy will never ask you to send your password by email or academy correspondence.
                    </p>

                </div>

            </div>

        </div>

    </section>

</main>


<script>

document.addEventListener(
    'DOMContentLoaded',
    () => {

        document.querySelectorAll(
            '[data-password-toggle]'
        ).forEach(
            (toggle) => {

                const inputId =
                    toggle.getAttribute(
                        'data-password-toggle'
                    );

                const password =
                    document.getElementById(
                        inputId
                    );

                if (!password) {
                    return;
                }

                toggle.addEventListener(
                    'click',
                    () => {

                        const showing =
                            password.type === 'text';

                        password.type =
                            showing
                                ? 'password'
                                : 'text';

                        toggle.setAttribute(
                            'aria-pressed',
                            showing
                                ? 'false'
                                : 'true'
                        );
                    }
                );
            }
        );
    }
);

</script>


<?php

require_once INCLUDES_PATH . '/footer.php';

?>
