<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';


/*
|--------------------------------------------------------------------------
| SEO
|--------------------------------------------------------------------------
*/

$pageTitle =
    'Verify Your Account | Blackthorne Academy';

$pageDescription =
    'Verify your Blackthorne Academy account and complete registration.';

$pageCanonical =
    url(
        'verify.php'
    );

$robots =
    'noindex, nofollow';


/*
|--------------------------------------------------------------------------
| Verification State
|--------------------------------------------------------------------------
*/

$verificationSuccess =
    false;

$verificationAlreadyUsed =
    false;

$verificationExpired =
    false;

$verificationAttempted =
    false;

$linkToken =
    '';

$linkTokenReady =
    false;

$verificationMessage =
    'Enter the verification code from your email to activate your Blackthorne Academy account.';


/*
|--------------------------------------------------------------------------
| Read Verification Credential
|--------------------------------------------------------------------------
|
| Email providers and security scanners may automatically visit links before
| the member clicks them. For that reason, a GET request must never activate
| the account or consume the verification token.
|
| GET only stages the token. Verification happens only after a human submits
| a CSRF-protected POST from this page.
|
*/

$token =
    '';


if (
    is_post()
) {

    $verificationAttempted =
        true;


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

        $verificationMessage =
            'Your verification form session expired. Refresh the page and try again.';

    } else {

        $submittedLinkToken =
            isset(
                $_POST['verification_token']
            )
            &&
            is_string(
                $_POST['verification_token']
            )
                ? trim(
                    $_POST['verification_token']
                )
                : '';


        if (
            $submittedLinkToken !== ''
        ) {

            $token =
                strtolower(
                    $submittedLinkToken
                );

        } else {

            $submittedCode =
                isset(
                    $_POST['verification_code']
                )
                &&
                is_string(
                    $_POST['verification_code']
                )
                    ? trim(
                        $_POST['verification_code']
                    )
                    : '';


            $normalizedCode =
                preg_replace(
                    '/[\s-]+/',
                    '',
                    $submittedCode
                );


            $token =
                is_string(
                    $normalizedCode
                )
                    ? strtolower(
                        $normalizedCode
                    )
                    : '';

        }

    }

} elseif (
    isset(
        $_GET['token']
    )
    &&
    is_string(
        $_GET['token']
    )
) {

    $candidateLinkToken =
        strtolower(
            trim(
                $_GET['token']
            )
        );


    if (
        preg_match(
            '/^[a-f0-9]{64}$/',
            $candidateLinkToken
        )
    ) {

        $linkToken =
            $candidateLinkToken;

        $linkTokenReady =
            true;

        $verificationMessage =
            'Your verification link is ready. Confirm below to activate your Blackthorne Academy account.';

    } else {

        $verificationMessage =
            'This verification link is invalid. You can enter the verification code from your email below.';

    }

}


/*
|--------------------------------------------------------------------------
| Validate and Process Token
|--------------------------------------------------------------------------
*/

if (
    $token !== ''
) {

    /*
    |--------------------------------------------------------------------------
    | Validate Token Format
    |--------------------------------------------------------------------------
    |
    | Registration creates a 32-byte random value and converts it with
    | bin2hex(), so the raw token must be exactly 64 hexadecimal characters.
    |
    */

    if (
        !preg_match(
            '/^[a-f0-9]{64}$/',
            $token
        )
    ) {

        $verificationMessage =
            is_post()
                ? 'That verification code is invalid. Check the code and try again.'
                : 'This verification link is invalid. You can enter the verification code from your email below.';

    } else {

        /*
        |--------------------------------------------------------------------------
        | Hash Token
        |--------------------------------------------------------------------------
        */

        $tokenHash =
            hash(
                'sha256',
                $token
            );


        try {

            /*
            |--------------------------------------------------------------------------
            | Begin Transaction
            |--------------------------------------------------------------------------
            */

            $pdo->beginTransaction();


            /*
            |--------------------------------------------------------------------------
            | Find Token
            |--------------------------------------------------------------------------
            */

            $verificationStatement =
                $pdo->prepare(
                    '
                    SELECT
                        evt.id AS verification_id,
                        evt.user_id,
                        evt.expires_at,
                        evt.used_at,

                        u.email_verified_at,
                        u.status

                    FROM email_verification_tokens evt

                    INNER JOIN users u
                        ON u.id = evt.user_id

                    WHERE evt.token_hash = :token_hash

                    LIMIT 1

                    FOR UPDATE
                    '
                );


            $verificationStatement->execute([
                'token_hash' =>
                    $tokenHash,
            ]);


            $verification =
                $verificationStatement->fetch(
                    PDO::FETCH_ASSOC
                );


            /*
            |--------------------------------------------------------------------------
            | Token Not Found
            |--------------------------------------------------------------------------
            */

            if (
                !$verification
            ) {

                $pdo->rollBack();


                $verificationMessage =
                    is_post()
                        ? 'That verification code could not be found. Check the code and try again.'
                        : 'This verification link is invalid. You can enter the verification code from your email below.';

            } else {

                /*
                |--------------------------------------------------------------------------
                | Already Used
                |--------------------------------------------------------------------------
                */

                if (
                    $verification['used_at']
                    !== null
                ) {

                    $pdo->rollBack();


                    $verificationAlreadyUsed =
                        true;


                    $verificationMessage =
                        'This verification code has already been used. If your account was previously verified, you may continue to the academy login.';

                } else {

                    /*
                    |--------------------------------------------------------------------------
                    | Check Expiration
                    |--------------------------------------------------------------------------
                    */

                    $expiresAt =
                        new DateTimeImmutable(
                            (string)
                            $verification['expires_at']
                        );


                    $now =
                        new DateTimeImmutable(
                            'now'
                        );


                    if (
                        $expiresAt <= $now
                    ) {

                        $pdo->rollBack();


                        $verificationExpired =
                            true;


                        $verificationMessage =
                            'This verification code has expired. Please contact academy administration for help completing your account registration.';

                    } else {

                        /*
                        |--------------------------------------------------------------------------
                        | Activate Account
                        |--------------------------------------------------------------------------
                        */

                        $userStatement =
                            $pdo->prepare(
                                '
                                UPDATE users

                                SET
                                    email_verified_at =
                                        COALESCE(
                                            email_verified_at,
                                            NOW()
                                        ),

                                    status =
                                        CASE
                                            WHEN status = :pending_status
                                                THEN :active_status
                                            ELSE status
                                        END

                                WHERE id = :user_id
                                '
                            );


                        $userStatement->execute([
                            'pending_status' =>
                                'pending',

                            'active_status' =>
                                'active',

                            'user_id' =>
                                (int)
                                $verification['user_id'],
                        ]);


                        /*
                        |--------------------------------------------------------------------------
                        | Mark Token Used
                        |--------------------------------------------------------------------------
                        */

                        $tokenStatement =
                            $pdo->prepare(
                                '
                                UPDATE email_verification_tokens

                                SET
                                    used_at = NOW()

                                WHERE id = :verification_id
                                  AND used_at IS NULL
                                '
                            );


                        $tokenStatement->execute([
                            'verification_id' =>
                                (int)
                                $verification['verification_id'],
                        ]);


                        /*
                        |--------------------------------------------------------------------------
                        | Commit
                        |--------------------------------------------------------------------------
                        */

                        $pdo->commit();


                        $verificationSuccess =
                            true;


                        $verificationMessage =
                            'Your email address has been verified and your Blackthorne Academy account is now active.';

                    }

                }

            }


        } catch (
            Throwable $exception
        ) {

            /*
            |--------------------------------------------------------------------------
            | Roll Back
            |--------------------------------------------------------------------------
            */

            if (
                $pdo->inTransaction()
            ) {

                $pdo->rollBack();

            }


            /*
            |--------------------------------------------------------------------------
            | Log Internally
            |--------------------------------------------------------------------------
            */

            error_log(
                'Blackthorne email verification error: '
                . $exception->getMessage()
            );


            /*
            |--------------------------------------------------------------------------
            | Safe User Message
            |--------------------------------------------------------------------------
            */

            $verificationMessage =
                'Blackthorne could not verify your account right now. Please try again shortly.';

        }

    }

} elseif (
    $verificationAttempted
    &&
    is_post()
    &&
    $verificationMessage
    ===
    'Enter the verification code from your email to activate your Blackthorne Academy account.'
) {

    $verificationMessage =
        'Enter the verification code from your email.';

}


/*
|--------------------------------------------------------------------------
| Manual Form Visibility
|--------------------------------------------------------------------------
*/

$showManualVerificationForm =
    !$verificationSuccess
    &&
    !$verificationAlreadyUsed
    &&
    !$verificationExpired
    &&
    !$linkTokenReady;


/*
|--------------------------------------------------------------------------
| Header
|--------------------------------------------------------------------------
*/

require
    INCLUDES_PATH
    . '/header.php';

?>

<main id="main-content" class="enroll-page verification-page">


    <section class="enroll-intro verification-section" aria-labelledby="verification-heading">

        <div class="section-inner">

            <div class="enroll-intro-inner">


                <!-- ====================================================
                     Crest
                ===================================================== -->

                <div class="enroll-intro-mark">

                    <img src="<?= e(asset('images/logo/logo_3.png')); ?>" alt="" width="110" height="110"
                        loading="eager" decoding="async">

                </div>


                <!-- ====================================================
                     Eyebrow
                ===================================================== -->

                <p class="academy-overline">

                    <?php if (
                        $verificationSuccess
                    ): ?>

                    Account Verified

                    <?php elseif (
                        $verificationAlreadyUsed
                    ): ?>

                    Verification Complete

                    <?php elseif (
                        !$verificationAttempted
                    ): ?>

                    Manual Verification

                    <?php else: ?>

                    Account Verification

                    <?php endif; ?>

                </p>


                <!-- ====================================================
                     Heading
                ===================================================== -->

                <h1 id="verification-heading">

                    <?php if (
                        $verificationSuccess
                    ): ?>

                    The gates are open.

                    <?php elseif (
                        $verificationAlreadyUsed
                    ): ?>

                    You have already crossed the threshold.

                    <?php elseif (
                        !$verificationAttempted
                    ): ?>

                    Complete your verification.

                    <?php else: ?>

                    The gates remain closed.

                    <?php endif; ?>

                </h1>


                <!-- ====================================================
                     Message
                ===================================================== -->

                <p class="enroll-intro-copy">
                    <?= e($verificationMessage); ?>
                </p>


                <!-- ====================================================
                     Ornament
                ===================================================== -->

                <div class="ornamental-rule" aria-hidden="true">
                    <span></span>
                    <i></i>
                    <span></span>
                </div>


                <!-- ====================================================
                     Success Actions
                ===================================================== -->

                <?php if (
                    $verificationSuccess
                    ||
                    $verificationAlreadyUsed
                ): ?>

                <p class="enroll-age-notice">
                    Your academy account is ready.
                </p>


                <div class="verification-actions">

                    <a href="<?= e(LOGIN_URL); ?>" class="button button-primary">
                        Enter the Academy
                    </a>

                </div>


                <?php else: ?>


                <?php if (
                        $linkTokenReady
                    ): ?>

                <div class="enroll-form-panel">

                    <header class="enroll-form-header">

                        <p class="academy-overline">
                            Verification Ready
                        </p>

                        <h2>
                            Confirm your email address
                        </h2>

                        <p>
                            Your verification link is valid. Press the button below to activate your Blackthorne Academy
                            account.
                        </p>

                    </header>


                    <form class="enroll-form" action="<?= e(url('verify.php')); ?>" method="post">

                        <?= csrf_field(); ?>

                        <input type="hidden" name="verification_token" value="<?= e($linkToken); ?>">


                        <div class="enroll-submit-area">

                            <button type="submit" class="button button-primary button-large">
                                Verify My Academy Account
                            </button>

                        </div>

                    </form>

                </div>

                <?php endif; ?>


                <?php if (
                        $showManualVerificationForm
                    ): ?>

                <!-- =============================================
                             Manual Verification Form
                        ============================================== -->

                <div class="enroll-form-panel">

                    <header class="enroll-form-header">

                        <p class="academy-overline">
                            Verification Code
                        </p>


                        <h2>
                            Enter your code manually
                        </h2>


                        <p>
                            Copy the verification code from your email
                            and paste it below. Spaces and hyphens are
                            ignored.
                        </p>

                    </header>


                    <form class="enroll-form" action="<?= e(url('verify.php')); ?>" method="post">

                        <?= csrf_field(); ?>


                        <div class="form-field">

                            <label for="verification-code">
                                Verification Code
                            </label>


                            <input type="text" id="verification-code" name="verification_code" maxlength="96"
                                autocomplete="off" autocapitalize="characters" spellcheck="false" inputmode="text"
                                required>


                            <small class="field-help">
                                Paste the full code exactly as it appears
                                in your verification email.
                            </small>

                        </div>


                        <div class="enroll-submit-area">

                            <button type="submit" class="button button-primary button-large">
                                Verify My Account
                            </button>

                        </div>

                    </form>

                </div>


                <?php endif; ?>


                <!-- =============================================
                         Failure / Support Actions
                    ============================================== -->

                <?php if (
                        $verificationExpired
                    ): ?>

                <p class="enroll-age-notice">
                    The original verification credential can no longer
                    be used.
                </p>

                <?php else: ?>

                <p class="enroll-age-notice">
                    You can use either the email link or the manual
                    verification code.
                </p>

                <?php endif; ?>


                <div class="verification-actions">

                    <a href="<?= e(url('contact.php?subject=account#contact-form')); ?>"
                        class="button button-secondary">
                        Contact Academy Support
                    </a>


                    <a href="<?= e(LOGIN_URL); ?>" class="button button-secondary">
                        Academy Login
                    </a>

                </div>


                <?php endif; ?>


            </div>

        </div>

    </section>


</main>


<?php

/*
|--------------------------------------------------------------------------
| Footer
|--------------------------------------------------------------------------
*/

require
    INCLUDES_PATH
    . '/footer.php';

?>
