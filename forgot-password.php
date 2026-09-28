<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/mailer.php';


/*
|--------------------------------------------------------------------------
| Already Logged In
|--------------------------------------------------------------------------
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
    'Forgot Password | Blackthorne Academy';

$pageDescription =
    'Request a secure password reset link for your Blackthorne Academy account.';

$pageCanonical =
    url('forgot-password.php');

$robots =
    'noindex, nofollow';


/*
|--------------------------------------------------------------------------
| State
|--------------------------------------------------------------------------
*/

$formErrors =
    [];

$formValues = [
    'email' => '',
];

$requestComplete =
    false;

$resetLinkLifetimeMinutes =
    60;


/*
|--------------------------------------------------------------------------
| Password Reset Email
|--------------------------------------------------------------------------
*/

function send_blackthorne_password_reset_email(
    string $name,
    string $email,
    string $resetUrl,
    int $expiresInMinutes
): bool {

    $mail =
        new \PHPMailer\PHPMailer\PHPMailer(
            true
        );

    try {

        configure_blackthorne_mailer(
            $mail
        );

        $mail->addAddress(
            $email,
            $name
        );

        $mail->Subject =
            'Reset Your Blackthorne Academy Password';


        $safeName =
            htmlspecialchars(
                $name,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );

        $safeResetUrl =
            htmlspecialchars(
                $resetUrl,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );

        $safeFromAddress =
            htmlspecialchars(
                MAIL_FROM_ADDRESS,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );


        $htmlBody =
            '<!doctype html>'
            . '<html lang="en">'
            . '<head>'
            . '<meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Reset Your Blackthorne Academy Password</title>'
            . '</head>'
            . '<body style="margin:0;padding:0;background:#120f14;color:#e8e0e5;font-family:Arial,Helvetica,sans-serif;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#120f14;padding:32px 16px;">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:620px;background:#1b161d;border:1px solid #8d7651;border-radius:10px;overflow:hidden;">'
            . '<tr><td style="padding:32px;">'
            . '<p style="margin:0 0 8px;color:#bda77d;font-size:13px;letter-spacing:2px;text-transform:uppercase;">Blackthorne Academy</p>'
            . '<h1 style="margin:0 0 20px;color:#f0e6d2;font-size:28px;line-height:1.25;">Reset Your Password</h1>'
            . '<p style="margin:0 0 16px;line-height:1.7;">Hello ' . $safeName . ',</p>'
            . '<p style="margin:0 0 20px;line-height:1.7;">We received a request to reset the password for your Blackthorne Academy account.</p>'
            . '<p style="margin:0 0 26px;text-align:center;">'
            . '<a href="' . $safeResetUrl . '" style="display:inline-block;background:#8d7651;color:#120f14;text-decoration:none;font-weight:700;padding:13px 22px;border-radius:6px;">Reset Password</a>'
            . '</p>'
            . '<p style="margin:0 0 16px;line-height:1.7;">This link expires in ' . $expiresInMinutes . ' minutes and can only be used once.</p>'
            . '<p style="margin:0 0 16px;line-height:1.7;">If you did not request a password reset, you can ignore this message. Your current password will remain unchanged.</p>'
            . '<p style="margin:24px 0 0;color:#b9aeb5;font-size:13px;line-height:1.6;">If the button does not work, copy and paste this address into your browser:<br>'
            . '<a href="' . $safeResetUrl . '" style="color:#d0bd95;word-break:break-all;">' . $safeResetUrl . '</a></p>'
            . '<hr style="border:0;border-top:1px solid #3c313d;margin:28px 0;">'
            . '<p style="margin:0;color:#8f858c;font-size:12px;line-height:1.6;">This is an automated account-security message from '
            . $safeFromAddress
            . '.</p>'
            . '</td></tr>'
            . '</table>'
            . '</td></tr>'
            . '</table>'
            . '</body>'
            . '</html>';


        $plainBody =
            "BLACKTHORNE ACADEMY\n"
            . "Password Reset\n\n"
            . "Hello {$name},\n\n"
            . "We received a request to reset the password for your Blackthorne Academy account.\n\n"
            . "Reset your password here:\n"
            . $resetUrl
            . "\n\n"
            . "This link expires in {$expiresInMinutes} minutes and can only be used once.\n\n"
            . "If you did not request a password reset, you can ignore this message. Your current password will remain unchanged.\n";


        $mail->isHTML(
            true
        );

        $mail->Body =
            $htmlBody;

        $mail->AltBody =
            $plainBody;

        return
            $mail->send();

    } catch (\PHPMailer\PHPMailer\Exception $exception) {

        error_log(
            'Blackthorne password reset mail error: '
            . $mail->ErrorInfo
        );

        return false;
    }
}


/*
|--------------------------------------------------------------------------
| Process Request
|--------------------------------------------------------------------------
*/

if (is_post()) {

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


    $formValues['email'] =
        isset($_POST['email'])
        &&
        is_string($_POST['email'])
            ? trim($_POST['email'])
            : '';


    if (
        $formValues['email'] === ''
        ||
        !filter_var(
            $formValues['email'],
            FILTER_VALIDATE_EMAIL
        )
    ) {

        $formErrors[] =
            'Enter a valid email address.';

    } elseif (
        strlen(
            $formValues['email']
        ) > 255
    ) {

        $formErrors[] =
            'Your email address is too long.';
    }


    if ($formErrors === []) {

        try {

            /*
             * Look up the account without ever revealing to the visitor
             * whether it exists.
             */
            $userStatement =
                $pdo->prepare(
                    '
                    SELECT
                        id,
                        display_name,
                        email,
                        status
                    FROM users
                    WHERE LOWER(email) = LOWER(:email)
                    LIMIT 1
                    '
                );

            $userStatement->execute([
                'email' =>
                    $formValues['email'],
            ]);

            $user =
                $userStatement->fetch(
                    PDO::FETCH_ASSOC
                );


            if (
                is_array($user)
                &&
                $user !== []
                &&
                in_array(
                    (string) $user['status'],
                    [
                        'active',
                        'pending',
                    ],
                    true
                )
            ) {

                $userId =
                    (int) $user['id'];


                /*
                 * Basic resend throttling:
                 * if a still-unused reset token was created in the last
                 * 60 seconds, do not create another one.
                 */
                $recentStatement =
                    $pdo->prepare(
                        '
                        SELECT id
                        FROM password_resets
                        WHERE user_id = :user_id
                          AND used_at IS NULL
                          AND created_at >= DATE_SUB(NOW(), INTERVAL 60 SECOND)
                        ORDER BY created_at DESC
                        LIMIT 1
                        '
                    );

                $recentStatement->execute([
                    'user_id' =>
                        $userId,
                ]);

                $recentReset =
                    $recentStatement->fetch(
                        PDO::FETCH_ASSOC
                    );


                if (
                    !is_array($recentReset)
                    ||
                    $recentReset === []
                ) {

                    $rawToken =
                        bin2hex(
                            random_bytes(
                                32
                            )
                        );

                    $tokenHash =
                        hash(
                            'sha256',
                            $rawToken
                        );

                    $expiresAt =
                        (
                            new DateTimeImmutable(
                                'now',
                                new DateTimeZone(
                                    'America/New_York'
                                )
                            )
                        )
                            ->modify(
                                '+'
                                . $resetLinkLifetimeMinutes
                                . ' minutes'
                            )
                            ->format(
                                'Y-m-d H:i:s'
                            );


                    $pdo->beginTransaction();


                    /*
                     * Expire all older unused reset links before issuing
                     * the new one.
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


                    $insertStatement =
                        $pdo->prepare(
                            '
                            INSERT INTO password_resets (
                                user_id,
                                token_hash,
                                expires_at
                            ) VALUES (
                                :user_id,
                                :token_hash,
                                :expires_at
                            )
                            '
                        );

                    $insertStatement->execute([
                        'user_id' =>
                            $userId,

                        'token_hash' =>
                            $tokenHash,

                        'expires_at' =>
                            $expiresAt,
                    ]);


                    $pdo->commit();


                    $resetUrl =
                        url(
                            'reset-password.php?token='
                            . rawurlencode(
                                $rawToken
                            )
                        );


                    $emailSent =
                        send_blackthorne_password_reset_email(
                            (string) $user['display_name'],
                            (string) $user['email'],
                            $resetUrl,
                            $resetLinkLifetimeMinutes
                        );


                    /*
                     * Do not expose mail-delivery failures to the visitor,
                     * because doing so could reveal whether an account exists.
                     * The server error log records a failure for administrators.
                     */
                    if (!$emailSent) {

                        error_log(
                            'Blackthorne password reset email could not be sent for user ID '
                            . $userId
                            . '.'
                        );
                    }
                }
            }


            /*
             * Always show the same result, regardless of account existence.
             */
            $requestComplete =
                true;

        } catch (Throwable $exception) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'Blackthorne forgot-password error: '
                . $exception->getMessage()
            );

            /*
             * Keep the public response generic. The user can retry without
             * learning whether the supplied address matched an account.
             */
            $requestComplete =
                true;
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
                        Trouble Logging In?
                    </h1>

                    <p id="forgot-password-description">
                        Enter the email address connected to your Blackthorne Academy account.
                    </p>

                </div>


                <?php if ($requestComplete): ?>

                    <div
                        class="login-page-notice"
                        role="status"
                    >
                        If an eligible Blackthorne Academy account is connected to that email address, a password reset link has been sent. Check your inbox and spam folder.
                    </div>


                    <div class="login-page-enrollment">

                        <p>
                            The reset link expires in 60 minutes.
                        </p>

                        <a
                            href="<?= e(LOGIN_URL); ?>"
                        >
                            Return to Login
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
                        action="<?= e(url('forgot-password.php')); ?>"
                        method="post"
                        aria-describedby="forgot-password-description"
                    >

                        <?= csrf_field(); ?>


                        <div class="form-group">

                            <label for="forgot-email">
                                Email Address
                            </label>

                            <input
                                class="form-control"
                                type="email"
                                id="forgot-email"
                                name="email"
                                value="<?= e($formValues['email']); ?>"
                                maxlength="255"
                                autocomplete="email"
                                required
                            >

                        </div>


                        <button
                            type="submit"
                            class="button button-primary login-page-submit"
                        >
                            Send Reset Link
                        </button>

                    </form>


                    <div class="login-page-enrollment">

                        <p>
                            Remembered your password?
                        </p>

                        <a
                            href="<?= e(LOGIN_URL); ?>"
                        >
                            Return to Login
                        </a>

                    </div>

                <?php endif; ?>


                <div class="login-security-note">

                    <span aria-hidden="true">
                        ✦
                    </span>

                    <p>
                        For your privacy, Blackthorne Academy will not confirm whether an email address is registered.
                    </p>

                </div>

            </div>

        </div>

    </section>

</main>


<?php

require_once INCLUDES_PATH . '/footer.php';

?>
