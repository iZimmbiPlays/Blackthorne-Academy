<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/mailer.php';

if (is_logged_in()) {
    redirect(HOME_URL);
}

$pageTitle = 'Enroll | Blackthorne Academy';
$pageDescription = 'Create your Blackthorne Academy account and begin your enrollment in the academy community.';
$pageCanonical = url('register.php');
$robots = 'index, follow';

$formValues = [
    'username' => '',
    'display_name' => '',
    'email' => '',
    'date_of_birth' => '',
];

$formErrors = [];
$registrationComplete = false;
$verificationEmailSent = false;
$registeredEmail = '';

if (is_post()) {
    $csrfToken = $_POST['_csrf_token'] ?? null;

    if (!is_string($csrfToken) || !verify_csrf_token($csrfToken)) {
        $formErrors[] = 'Your form session expired. Refresh the page and try again.';
    }

    foreach (array_keys($formValues) as $field) {
        $value = $_POST[$field] ?? '';
        $formValues[$field] = is_string($value) ? trim($value) : '';
    }

    $password = isset($_POST['password']) && is_string($_POST['password'])
        ? $_POST['password']
        : '';

    $passwordConfirmation = isset($_POST['password_confirmation']) && is_string($_POST['password_confirmation'])
        ? $_POST['password_confirmation']
        : '';

    $acceptedTerms = isset($_POST['accept_terms']) && $_POST['accept_terms'] === '1';

    if ($formValues['username'] === '') {
        $formErrors[] = 'Choose an academy username.';
    } elseif (strlen($formValues['username']) < 3 || strlen($formValues['username']) > 50) {
        $formErrors[] = 'Your username must be between 3 and 50 characters.';
    } elseif (!preg_match('/^[A-Za-z0-9_]+$/', $formValues['username'])) {
        $formErrors[] = 'Your username may contain only letters, numbers, and underscores.';
    }

    if ($formValues['display_name'] === '') {
        $formErrors[] = 'Enter the display name you want other academy members to see.';
    } elseif (mb_strlen($formValues['display_name']) > 100) {
        $formErrors[] = 'Your display name cannot be longer than 100 characters.';
    }

    if ($formValues['email'] === '' || !filter_var($formValues['email'], FILTER_VALIDATE_EMAIL)) {
        $formErrors[] = 'Enter a valid email address.';
    } elseif (strlen($formValues['email']) > 255) {
        $formErrors[] = 'Your email address is too long.';
    } else {
        $emailParts = explode('@', $formValues['email']);
        $emailDomain = strtolower((string) end($emailParts));

        if (preg_match('/^(hotmail|outlook)\./i', $emailDomain)) {
            $formErrors[] = 'Hotmail and Outlook email addresses cannot be used for registration at this time because Microsoft is currently rejecting Blackthorne Academy verification emails. Please use a different email provider.';
        }
    }

    $birthDate = null;

    if ($formValues['date_of_birth'] === '') {
        $formErrors[] = 'Enter your date of birth.';
    } else {
        $birthDate = DateTimeImmutable::createFromFormat('!Y-m-d', $formValues['date_of_birth']);
        $birthDateErrors = DateTimeImmutable::getLastErrors();

        $birthDateIsValid = $birthDate instanceof DateTimeImmutable
            && ($birthDateErrors === false || (
                ($birthDateErrors['warning_count'] ?? 0) === 0
                && ($birthDateErrors['error_count'] ?? 0) === 0
            ))
            && $birthDate->format('Y-m-d') === $formValues['date_of_birth'];

        if (!$birthDateIsValid) {
            $birthDate = null;
            $formErrors[] = 'Enter a valid date of birth.';
        } else {
            $today = new DateTimeImmutable('today', new DateTimeZone('America/New_York'));

            if ($birthDate > $today) {
                $formErrors[] = 'Your date of birth cannot be in the future.';
            } elseif ($birthDate->diff($today)->y < MINIMUM_USER_AGE) {
                $formErrors[] = 'Blackthorne Academy registration is limited to people age 13 or older. If you entered the wrong birth year, correct it and try again.';
            }
        }
    }

    if (strlen($password) < 8) {
        $formErrors[] = 'Your password must be at least 8 characters long.';
    }

    if ($password !== $passwordConfirmation) {
        $formErrors[] = 'Your password confirmation does not match.';
    }

    if (!$acceptedTerms) {
        $formErrors[] = 'You must agree to the Terms and Privacy Policy before creating an account.';
    }

    if ($formErrors === []) {
        try {
            $duplicateStatement = $pdo->prepare(
                'SELECT username, email
                 FROM users
                 WHERE LOWER(username) = LOWER(:username)
                    OR LOWER(email) = LOWER(:email)
                 LIMIT 1'
            );

            $duplicateStatement->execute([
                'username' => $formValues['username'],
                'email' => $formValues['email'],
            ]);

            $duplicateUser = $duplicateStatement->fetch(PDO::FETCH_ASSOC);

            if ($duplicateUser) {
                if (isset($duplicateUser['username']) && strcasecmp((string) $duplicateUser['username'], $formValues['username']) === 0) {
                    $formErrors[] = 'That academy username is already in use.';
                }

                if (isset($duplicateUser['email']) && strcasecmp((string) $duplicateUser['email'], $formValues['email']) === 0) {
                    $formErrors[] = 'An account already exists with that email address.';
                }
            }
        } catch (PDOException $exception) {
            error_log('Blackthorne registration duplicate check error: ' . $exception->getMessage());
            $formErrors[] = 'Blackthorne could not check your account details right now. Please try again shortly.';
        }
    }

    if ($formErrors === [] && $birthDate instanceof DateTimeImmutable) {
        $rawVerificationToken = bin2hex(random_bytes(32));
        $verificationTokenHash = hash('sha256', $rawVerificationToken);
        $verificationExpiresAt = (new DateTimeImmutable('now', new DateTimeZone('America/New_York')))
            ->modify('+' . EMAIL_VERIFICATION_HOURS . ' hours')
            ->format('Y-m-d H:i:s');

        try {
            $pdo->beginTransaction();

            $userStatement = $pdo->prepare(
                'INSERT INTO users (
                    username,
                    display_name,
                    email,
                    password_hash,
                    date_of_birth,
                    status
                 ) VALUES (
                    :username,
                    :display_name,
                    :email,
                    :password_hash,
                    :date_of_birth,
                    "pending"
                 )'
            );

            $userStatement->execute([
                'username' => $formValues['username'],
                'display_name' => $formValues['display_name'],
                'email' => $formValues['email'],
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'date_of_birth' => $birthDate->format('Y-m-d'),
            ]);

            $userId = (int) $pdo->lastInsertId();

            $verificationStatement = $pdo->prepare(
                'INSERT INTO email_verification_tokens (
                    user_id,
                    token_hash,
                    expires_at
                 ) VALUES (
                    :user_id,
                    :token_hash,
                    :expires_at
                 )'
            );

            $verificationStatement->execute([
                'user_id' => $userId,
                'token_hash' => $verificationTokenHash,
                'expires_at' => $verificationExpiresAt,
            ]);

            $termsStatement = $pdo->prepare(
                'INSERT INTO terms_acceptances (
                    user_id,
                    terms_version,
                    privacy_version,
                    ip_address,
                    user_agent
                 ) VALUES (
                    :user_id,
                    :terms_version,
                    :privacy_version,
                    :ip_address,
                    :user_agent
                 )'
            );

            $termsStatement->execute([
                'user_id' => $userId,
                'terms_version' => TERMS_VERSION,
                'privacy_version' => PRIVACY_VERSION,
                'ip_address' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            ]);

            $pdo->commit();

            $verificationUrl = url('verify.php?token=' . rawurlencode($rawVerificationToken));

            $verificationEmailSent = send_verification_email(
                $formValues['display_name'],
                $formValues['email'],
                $verificationUrl
            );

            $registrationComplete = true;
            $registeredEmail = $formValues['email'];

        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Blackthorne registration error: ' . $exception->getMessage());

            if ($exception instanceof PDOException && (string) $exception->getCode() === '23000') {
                $formErrors[] = 'That username or email address is already registered.';
            } else {
                $formErrors[] = 'Blackthorne could not create your account right now. Please try again shortly.';
            }
        }
    }
}

require INCLUDES_PATH . '/header.php';
?>

<main id="main-content" class="enroll-page">
    <section class="enroll-intro" aria-labelledby="enroll-heading">
        <div class="section-inner enroll-intro-inner">
            <div class="enroll-intro-mark">
                <img
                    src="<?= e(asset('images/logo/logo_3.png')); ?>"
                    alt=""
                    width="110"
                    height="110"
                    loading="eager"
                    decoding="async"
                >
            </div>

            <p class="academy-overline">Academy Enrollment</p>

            <h1 id="enroll-heading">Enter the Halls of Blackthorne</h1>

            <div class="ornamental-rule" aria-hidden="true">
                <span></span><b>✦</b><span></span>
            </div>

            <p class="enroll-intro-copy">
                Create your academy account to take part in courses, forums, houses,
                community activities, and the growing world of Blackthorne Academy.
            </p>

            <p class="enroll-age-notice">
                <strong>Age requirement:</strong> You must be at least <?= (int) MINIMUM_USER_AGE; ?> years old to register.
            </p>
        </div>
    </section>

    <section class="enroll-form-section">
        <div class="section-inner enroll-layout">
            <div class="enroll-form-panel">
                <?php if ($registrationComplete): ?>
                    <div class="enroll-form-header">
                        <p class="academy-overline">Enrollment Received</p>
                        <h2>Check Your Email</h2>
                    </div>

                    <p class="enroll-age-notice">
                        Your Blackthorne Academy account has been created for
                        <strong><?= e($registeredEmail); ?></strong>.
                    </p>

                    <?php if ($verificationEmailSent): ?>
                        <p class="registration-email-reminder">
                            We sent a verification message to that address. Open the verification link
                            or use the verification code in the email to activate your account before logging in.
                        </p>
                    <?php else: ?>
                        <div class="enroll-status" role="alert">
                            <strong>Your account was created, but the verification email could not be sent.</strong>
                            <ul>
                                <li>Please contact academy administration so we can help you complete verification.</li>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <div class="registration-success-actions">
                        <a class="button button-primary" href="<?= e(url('verify.php')); ?>">
                            Open Verification Page
                        </a>
                    </div>
                <?php else: ?>
                    <div class="enroll-form-header">
                        <p class="academy-overline">Begin Enrollment</p>
                        <h2>Create Your Account</h2>
                        <p>Fields marked with <span class="required-marker">*</span> are required.</p>
                    </div>

                    <?php if ($formErrors !== []): ?>
                        <div class="enroll-status" role="alert" aria-live="polite">
                            <strong>We need you to correct a few things.</strong>
                            <ul>
                                <?php foreach ($formErrors as $formError): ?>
                                    <li><?= e($formError); ?></li>
                                <?php endforeach; ?>
                            </ul>

                            <?php if (in_array('Blackthorne Academy registration is limited to people age 13 or older. If you entered the wrong birth year, correct it and try again.', $formErrors, true)): ?>
                                <div class="enroll-age-correction">
                                    <p>
                                        If the year was entered accidentally, return to the date-of-birth field,
                                        correct it, and submit the form again.
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <form class="enroll-form" method="post" action="<?= e(url('register.php')); ?>" novalidate>
                        <?= csrf_field(); ?>

                        <fieldset class="enroll-fieldset">
                            <legend>Academy Identity</legend>
                            <p class="enroll-fieldset-description">
                                Choose how your account will identify you throughout the academy.
                            </p>

                            <div class="enroll-form-row">
                                <div class="form-field">
                                    <label for="username">
                                        Username <span class="required-marker" aria-hidden="true">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        id="username"
                                        name="username"
                                        value="<?= e($formValues['username']); ?>"
                                        maxlength="50"
                                        autocomplete="username"
                                        required
                                    >
                                    <span class="field-help">3–50 characters. Letters, numbers, and underscores only.</span>
                                </div>

                                <div class="form-field">
                                    <label for="display_name">
                                        Display Name <span class="required-marker" aria-hidden="true">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        id="display_name"
                                        name="display_name"
                                        value="<?= e($formValues['display_name']); ?>"
                                        maxlength="100"
                                        autocomplete="name"
                                        required
                                    >
                                    <span class="field-help">This is the name other academy members will see.</span>
                                </div>
                            </div>

                            <div class="form-field">
                                <label for="email">
                                    Email Address <span class="required-marker" aria-hidden="true">*</span>
                                </label>
                                <input
                                    type="email"
                                    id="email"
                                    name="email"
                                    value="<?= e($formValues['email']); ?>"
                                    maxlength="255"
                                    autocomplete="email"
                                    aria-describedby="email-help email-provider-notice email-provider-warning"
                                    required
                                >
                                <span id="email-help" class="field-help">We will send your account verification link and code here.</span>
                                <span id="email-provider-notice" class="field-help">
                                    <strong>Temporary email restriction:</strong>
                                    Hotmail and Outlook addresses cannot be used for registration right now because Microsoft is rejecting Blackthorne Academy verification emails. Please use another email provider.
                                </span>

                                <div id="email-provider-warning" class="enroll-inline-age-warning" hidden>
                                    <strong>This email provider cannot be used right now.</strong>
                                    <p>Please enter a non-Hotmail, non-Outlook email address to continue registration.</p>
                                </div>
                            </div>

                            <div class="form-field">
                                <label for="date_of_birth">
                                    Date of Birth <span class="required-marker" aria-hidden="true">*</span>
                                </label>
                                <input
                                    type="date"
                                    id="date_of_birth"
                                    name="date_of_birth"
                                    value="<?= e($formValues['date_of_birth']); ?>"
                                    autocomplete="bday"
                                    required
                                >
                                <span class="field-help">You must be at least <?= (int) MINIMUM_USER_AGE; ?> years old to register.</span>

                                <div id="age-warning" class="enroll-inline-age-warning" hidden>
                                    <strong>You must be at least <?= (int) MINIMUM_USER_AGE; ?> years old.</strong>
                                    <p>If you entered the wrong year, correct your date of birth before submitting.</p>
                                </div>
                            </div>
                        </fieldset>

                        <fieldset class="enroll-fieldset">
                            <legend>Secure Your Account</legend>
                            <p class="enroll-fieldset-description">
                                Use a password that you do not reuse on another website.
                            </p>

                            <div class="enroll-form-row">
                                <div class="form-field">
                                    <label for="password">
                                        Password <span class="required-marker" aria-hidden="true">*</span>
                                    </label>
                                    <div class="enroll-password-field">
                                        <input
                                            type="password"
                                            id="password"
                                            name="password"
                                            minlength="8"
                                            autocomplete="new-password"
                                            required
                                        >
                                        <button class="enroll-password-toggle" type="button" data-password-toggle="password" aria-controls="password">Show</button>
                                    </div>
                                    <span class="field-help">At least 8 characters.</span>
                                </div>

                                <div class="form-field">
                                    <label for="password_confirmation">
                                        Confirm Password <span class="required-marker" aria-hidden="true">*</span>
                                    </label>
                                    <div class="enroll-password-field">
                                        <input
                                            type="password"
                                            id="password_confirmation"
                                            name="password_confirmation"
                                            minlength="8"
                                            autocomplete="new-password"
                                            required
                                        >
                                        <button class="enroll-password-toggle" type="button" data-password-toggle="password_confirmation" aria-controls="password_confirmation">Show</button>
                                    </div>
                                </div>
                            </div>
                        </fieldset>

                        <div class="enroll-agreement">
                            <label class="enroll-checkbox" for="accept_terms">
                                <input
                                    type="checkbox"
                                    id="accept_terms"
                                    name="accept_terms"
                                    value="1"
                                    <?= isset($_POST['accept_terms']) ? 'checked' : ''; ?>
                                    required
                                >
                                <span>
                                    I agree to the <a href="<?= e(url('terms.php')); ?>">Terms</a>
                                    and <a href="<?= e(url('privacy.php')); ?>">Privacy Policy</a>.
                                </span>
                            </label>
                        </div>

                        <div class="enroll-submit-area">
                            <button class="button button-primary" type="submit">Create Academy Account</button>
                            <p>Already registered? <a href="<?= e(LOGIN_URL); ?>">Log in here.</a></p>
                        </div>
                    </form>
                <?php endif; ?>
            </div>

            <aside class="enroll-sidebar" aria-label="Enrollment information">
                <div class="enroll-sidebar-inner">
                    <img
                        src="<?= e(asset('images/logo/logo_3.png')); ?>"
                        alt=""
                        width="104"
                        height="104"
                        loading="lazy"
                        decoding="async"
                    >
                    <p class="academy-overline">Before You Enter</p>
                    <h2>A Few Things to Know</h2>
                    <div class="ornamental-rule" aria-hidden="true">
                        <span></span><b>✦</b><span></span>
                    </div>

                    <div class="enroll-sidebar-list">
                        <section>
                            <span aria-hidden="true">I</span>
                            <div>
                                <h3>Verify Your Email</h3>
                                <p>Your account remains pending until you complete email verification.</p>
                            </div>
                        </section>

                        <section>
                            <span aria-hidden="true">II</span>
                            <div>
                                <h3>Build Your Profile</h3>
                                <p>After verification, you can customize your academy profile and privacy settings.</p>
                            </div>
                        </section>

                        <section>
                            <span aria-hidden="true">III</span>
                            <div>
                                <h3>Enter the Community</h3>
                                <p>Explore academy forums and other areas available to registered members.</p>
                            </div>
                        </section>
                    </div>
                </div>
            </aside>
        </div>
    </section>
</main>

<script>
(function () {
    'use strict';

    const birthInput = document.getElementById('date_of_birth');
    const ageWarning = document.getElementById('age-warning');
    const emailInput = document.getElementById('email');
    const emailProviderWarning = document.getElementById('email-provider-warning');
    const registrationForm = document.querySelector('.enroll-form');
    const minimumAge = <?= (int) MINIMUM_USER_AGE; ?>;

    function isUnderMinimumAge(value) {
        if (!value) {
            return false;
        }

        const parts = value.split('-').map(Number);
        if (parts.length !== 3 || parts.some(Number.isNaN)) {
            return false;
        }

        const birthDate = new Date(parts[0], parts[1] - 1, parts[2]);
        const today = new Date();
        let age = today.getFullYear() - birthDate.getFullYear();
        const monthDifference = today.getMonth() - birthDate.getMonth();

        if (monthDifference < 0 || (monthDifference === 0 && today.getDate() < birthDate.getDate())) {
            age--;
        }

        return age < minimumAge;
    }

    function updateAgeWarning() {
        if (!birthInput || !ageWarning) {
            return;
        }

        const underAge = isUnderMinimumAge(birthInput.value);
        ageWarning.hidden = !underAge;
        birthInput.setAttribute('aria-invalid', underAge ? 'true' : 'false');
    }

    if (birthInput) {
        birthInput.addEventListener('change', updateAgeWarning);
        birthInput.addEventListener('input', updateAgeWarning);
        updateAgeWarning();
    }

    function isBlockedEmailProvider(value) {
        if (!value || value.indexOf('@') === -1) {
            return false;
        }

        const domain = value.split('@').pop().trim().toLowerCase();

        return /^(hotmail|outlook)\./i.test(domain);
    }

    function updateEmailProviderWarning() {
        if (!emailInput || !emailProviderWarning) {
            return false;
        }

        const blocked = isBlockedEmailProvider(emailInput.value);

        emailProviderWarning.hidden = !blocked;
        emailInput.setAttribute('aria-invalid', blocked ? 'true' : 'false');

        if (blocked) {
            emailInput.setCustomValidity(
                'Hotmail and Outlook email addresses cannot be used for registration at this time. Please use a different email provider.'
            );
        } else {
            emailInput.setCustomValidity('');
        }

        return blocked;
    }

    if (emailInput) {
        emailInput.addEventListener('input', updateEmailProviderWarning);
        emailInput.addEventListener('change', updateEmailProviderWarning);
        updateEmailProviderWarning();
    }

    if (registrationForm) {
        registrationForm.addEventListener('submit', function (event) {
            if (updateEmailProviderWarning()) {
                event.preventDefault();

                if (emailInput) {
                    emailInput.focus();
                    emailInput.reportValidity();
                }
            }
        });
    }

    document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            const inputId = button.getAttribute('data-password-toggle');
            const input = inputId ? document.getElementById(inputId) : null;

            if (!input) {
                return;
            }

            const showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            button.textContent = showing ? 'Show' : 'Hide';
        });
    });
})();
</script>

<?php require INCLUDES_PATH . '/footer.php'; ?>
