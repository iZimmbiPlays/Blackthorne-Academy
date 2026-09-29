<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';


/*
|--------------------------------------------------------------------------
| SEO
|--------------------------------------------------------------------------
*/

$pageTitle =
    'Contact Blackthorne Academy | Academy Correspondence';

$pageDescription =
    'Contact Blackthorne Academy with questions about enrollment, academics, account access, technical support, community matters, and academy life.';

$pageCanonical =
    url('contact.php');


/*
|--------------------------------------------------------------------------
| Subject Options
|--------------------------------------------------------------------------
*/

$contactSubjects = [
    'general' => 'General Question',
    'admissions' => 'Admissions & Enrollment',
    'academics' => 'Courses & Academics',
    'account' => 'Account / Login Help',
    'technical' => 'Technical Support',
    'community' => 'Community & Forums',
    'safety' => 'Safety, Conduct & Moderation',
    'staff' => 'Staff / Instructor Inquiry',
    'other' => 'Other',
];


/*
|--------------------------------------------------------------------------
| Form State
|--------------------------------------------------------------------------
*/

$formValues = [
    'name' => '',
    'email' => '',
    'username' => '',
    'subject' => '',
    'message' => '',
];


$formErrors = [];


/*
|--------------------------------------------------------------------------
| Success Flash Message
|--------------------------------------------------------------------------
*/

$contactSuccess =
    get_flash(
        'contact_success'
    );


/*
|--------------------------------------------------------------------------
| Process Contact Form
|--------------------------------------------------------------------------
*/

if (is_post()) {

    /*
    |--------------------------------------------------------------------------
    | CSRF Validation
    |--------------------------------------------------------------------------
    */

    $csrfToken =
        $_POST['_csrf_token']
        ?? null;


    if (
        !is_string($csrfToken)
        ||
        !verify_csrf_token($csrfToken)
    ) {

        $formErrors[] =
            'Your form session expired. Please refresh the page and try again.';

    }


    /*
    |--------------------------------------------------------------------------
    | Honeypot Spam Check
    |--------------------------------------------------------------------------
    */

    $website =
        post_value(
            'website'
        );


    if ($website !== '') {

        /*
         * Silently pretend the message succeeded.
         *
         * This avoids telling simple spambots that they were detected.
         */

        set_flash(
            'contact_success',
            'Your correspondence has been sent to Blackthorne Academy.'
        );


        redirect(
            url(
                'contact.php?sent=1#contact-form'
            )
        );

    }


    /*
    |--------------------------------------------------------------------------
    | Read Submitted Fields
    |--------------------------------------------------------------------------
    */

    $formValues['name'] =
        post_value(
            'name'
        );


    $formValues['email'] =
        post_value(
            'email'
        );


    $formValues['username'] =
        post_value(
            'username'
        );


    $formValues['subject'] =
        post_value(
            'subject'
        );


    $formValues['message'] =
        post_value(
            'message'
        );


    /*
    |--------------------------------------------------------------------------
    | Name Validation
    |--------------------------------------------------------------------------
    */

    if ($formValues['name'] === '') {

        $formErrors[] =
            'Please enter your name.';

    } elseif (
        mb_strlen(
            $formValues['name']
        ) > 100
    ) {

        $formErrors[] =
            'Your name must be 100 characters or fewer.';

    }


    /*
    |--------------------------------------------------------------------------
    | Email Validation
    |--------------------------------------------------------------------------
    */

    if ($formValues['email'] === '') {

        $formErrors[] =
            'Please enter your email address.';

    } elseif (
        !filter_var(
            $formValues['email'],
            FILTER_VALIDATE_EMAIL
        )
    ) {

        $formErrors[] =
            'Please enter a valid email address.';

    } elseif (
        mb_strlen(
            $formValues['email']
        ) > 254
    ) {

        $formErrors[] =
            'Your email address is too long.';

    }


    /*
    |--------------------------------------------------------------------------
    | Username Validation
    |--------------------------------------------------------------------------
    */

    if (
        mb_strlen(
            $formValues['username']
        ) > 100
    ) {

        $formErrors[] =
            'Your academy username must be 100 characters or fewer.';

    }


    /*
    |--------------------------------------------------------------------------
    | Subject Validation
    |--------------------------------------------------------------------------
    */

    if (
        $formValues['subject'] === ''
        ||
        !array_key_exists(
            $formValues['subject'],
            $contactSubjects
        )
    ) {

        $formErrors[] =
            'Please choose a valid subject.';

    }


    /*
    |--------------------------------------------------------------------------
    | Message Validation
    |--------------------------------------------------------------------------
    */

    $messageLength =
        mb_strlen(
            $formValues['message']
        );


    if ($formValues['message'] === '') {

        $formErrors[] =
            'Please enter a message.';

    } elseif ($messageLength < 20) {

        $formErrors[] =
            'Please provide at least 20 characters in your message.';

    } elseif ($messageLength > 5000) {

        $formErrors[] =
            'Your message must be 5,000 characters or fewer.';

    }


    /*
    |--------------------------------------------------------------------------
    | Send Message
    |--------------------------------------------------------------------------
    */

    if ($formErrors === []) {

        require_once
            INCLUDES_PATH
            . '/mailer.php';


        $subjectLabel =
            $contactSubjects[
                $formValues['subject']
            ];


        $sent =
            send_contact_message(
                $formValues['name'],
                $formValues['email'],
                $formValues['username'],
                $subjectLabel,
                $formValues['message']
            );


        if ($sent) {

            /*
             * Rotate CSRF token after successful form submission.
             */

            unset(
                $_SESSION['_csrf_token']
            );


            set_flash(
                'contact_success',
                'Your correspondence has been sent to Blackthorne Academy.'
            );


            redirect(
                url(
                    'contact.php?sent=1#contact-form'
                )
            );

        }


        $formErrors[] =
            'Blackthorne could not send your correspondence right now. Please try again shortly.';

    }

}


/*
|--------------------------------------------------------------------------
| Subject Selection
|--------------------------------------------------------------------------
|
| Preserve a submitted subject after validation errors.
| Otherwise allow links such as:
|
| contact.php?subject=admissions
|
*/

$selectedSubject =
    $formValues['subject'];


if (
    $selectedSubject === ''
    &&
    isset($_GET['subject'])
    &&
    is_string($_GET['subject'])
    &&
    array_key_exists(
        $_GET['subject'],
        $contactSubjects
    )
) {

    $selectedSubject =
        $_GET['subject'];

}


require INCLUDES_PATH . '/header.php';

?>

<main id="main-content">


    <!-- ================================================================
         HERO
    ================================================================= -->

    <section class="contact-hero" aria-labelledby="contact-hero-heading">

        <div class="hero-ornament hero-ornament-left" aria-hidden="true"></div>

        <div class="hero-ornament hero-ornament-right" aria-hidden="true"></div>


        <div class="section-inner contact-hero-inner">

            <div class="contact-hero-copy">

                <p class="academy-overline">
                    Academy Correspondence
                </p>


                <h1 id="contact-hero-heading">
                    Every question begins a conversation.
                </h1>


                <p class="contact-hero-intro">
                    Whether you are standing outside the academy gates,
                    already walking its halls, or simply trying to find the
                    right person to ask, correspondence is always welcome.
                </p>


                <div class="hero-actions" aria-label="Contact page actions">

                    <a href="#contact-form" class="button button-primary">
                        Send a Message
                    </a>


                    <a href="<?= e(LOGIN_URL); ?>" class="button button-secondary">
                        Academy Login
                    </a>

                </div>


                <div class="hero-motto" aria-label="Blackthorne Academy correspondence">

                    <span class="ornament-line" aria-hidden="true"></span>


                    <p>

                        <span>
                            Questions
                        </span>

                        <span aria-hidden="true">
                            •
                        </span>

                        <span>
                            Guidance
                        </span>

                        <span aria-hidden="true">
                            •
                        </span>

                        <span>
                            Correspondence
                        </span>

                    </p>

                </div>

            </div>

        </div>

    </section>


    <!-- ================================================================
         CONTACT INTRODUCTION
    ================================================================= -->

    <section class="contact-introduction" aria-labelledby="contact-introduction-heading">

        <div class="section-inner">

            <header class="contact-section-heading contact-section-heading-centered">

                <p class="academy-overline">
                    The Correspondence Office
                </p>


                <h2 id="contact-introduction-heading">
                    Direct your message to the right desk.
                </h2>


                <div class="ornamental-rule" aria-hidden="true">
                    <span></span>
                    <i></i>
                    <span></span>
                </div>


                <p>
                    Questions arrive at Blackthorne for many reasons.
                    Choosing the closest subject helps academy correspondence
                    reach the appropriate area more quickly.
                </p>

            </header>

        </div>

    </section>


    <!-- ================================================================
         CONTACT ROUTES
    ================================================================= -->

    <section class="contact-routes" aria-labelledby="contact-routes-heading">

        <div class="section-inner">


            <div class="contact-routes-heading">

                <p class="academy-overline">
                    Common Correspondence
                </p>


                <h2 id="contact-routes-heading">
                    Not every letter begins with the same question.
                </h2>

            </div>


            <div class="contact-route-list">


                <a href="<?= e(url('contact.php?subject=admissions#contact-form')); ?>" class="contact-route">

                    <span class="contact-route-number" aria-hidden="true">
                        01
                    </span>


                    <div>

                        <h3>
                            Admissions &amp; Enrollment
                        </h3>

                        <p>
                            Questions about joining Blackthorne, creating an
                            account, enrollment, orientation, or beginning
                            academy study.
                        </p>

                    </div>


                    <span class="contact-route-arrow" aria-hidden="true">
                        →
                    </span>

                </a>


                <a href="<?= e(url('contact.php?subject=account#contact-form')); ?>" class="contact-route">

                    <span class="contact-route-number" aria-hidden="true">
                        02
                    </span>


                    <div>

                        <h3>
                            Account &amp; Access
                        </h3>

                        <p>
                            Help with login problems, account access, profile
                            concerns, or other issues connected to an existing
                            academy account.
                        </p>

                    </div>


                    <span class="contact-route-arrow" aria-hidden="true">
                        →
                    </span>

                </a>


                <a href="<?= e(url('contact.php?subject=academics#contact-form')); ?>" class="contact-route">

                    <span class="contact-route-number" aria-hidden="true">
                        03
                    </span>


                    <div>

                        <h3>
                            Courses &amp; Academics
                        </h3>

                        <p>
                            Questions about coursework, lessons, academic
                            expectations, progression, or the Blackthorne
                            learning experience.
                        </p>

                    </div>


                    <span class="contact-route-arrow" aria-hidden="true">
                        →
                    </span>

                </a>


                <a href="<?= e(url('contact.php?subject=community#contact-form')); ?>" class="contact-route">

                    <span class="contact-route-number" aria-hidden="true">
                        04
                    </span>


                    <div>

                        <h3>
                            Community &amp; Academy Life
                        </h3>

                        <p>
                            Questions involving forums, Houses, events,
                            community participation, or other parts of
                            student life beyond coursework.
                        </p>

                    </div>


                    <span class="contact-route-arrow" aria-hidden="true">
                        →
                    </span>

                </a>

            </div>

        </div>

    </section>


    <!-- ================================================================
         CONTACT FORM
    ================================================================= -->

    <section class="contact-correspondence" id="contact-form" aria-labelledby="contact-form-heading">

        <div class="section-inner contact-correspondence-grid">


            <!-- ========================================================
                 Form Panel
            ========================================================= -->

            <div class="contact-form-panel">


                <?php if ($contactSuccess !== null): ?>

                <div class="contact-form-status contact-form-status-success" role="status">

                    <strong>
                        Correspondence sent.
                    </strong>

                    <p>
                        <?= e($contactSuccess); ?>
                    </p>

                </div>

                <?php endif; ?>


                <?php if ($formErrors !== []): ?>

                <div class="contact-form-status contact-form-status-error" role="alert">

                    <strong>
                        Your correspondence could not be sent yet.
                    </strong>


                    <ul>

                        <?php foreach ($formErrors as $error): ?>

                        <li>
                            <?= e($error); ?>
                        </li>

                        <?php endforeach; ?>

                    </ul>

                </div>

                <?php endif; ?>


                <div class="contact-form-heading">

                    <p class="academy-overline">
                        Send Correspondence
                    </p>


                    <h2 id="contact-form-heading">
                        Write to Blackthorne.
                    </h2>


                    <p>
                        Fields marked with an asterisk
                        <span aria-hidden="true">*</span>
                        are required.
                    </p>

                </div>


                <form class="contact-form" action="<?= e(url('contact.php#contact-form')); ?>" method="post"
                    aria-describedby="contact-form-guidance">

                    <?= csrf_field(); ?>


                    <!-- =================================================
                         Honeypot
                    ================================================== -->

                    <div class="contact-honeypot" aria-hidden="true">

                        <label for="contact-website">
                            Website
                        </label>

                        <input type="text" id="contact-website" name="website" tabindex="-1" autocomplete="off">

                    </div>


                    <!-- =================================================
                         Name + Email
                    ================================================== -->

                    <div class="contact-form-row">


                        <div class="form-field">

                            <label for="contact-name">

                                Your Name

                                <span class="required-marker" aria-hidden="true">
                                    *
                                </span>

                            </label>


                            <input type="text" id="contact-name" name="name" value="<?= e($formValues['name']); ?>"
                                maxlength="100" autocomplete="name" required>

                        </div>


                        <div class="form-field">

                            <label for="contact-email">

                                Email Address

                                <span class="required-marker" aria-hidden="true">
                                    *
                                </span>

                            </label>


                            <input type="email" id="contact-email" name="email" value="<?= e($formValues['email']); ?>"
                                maxlength="254" autocomplete="email" inputmode="email" required>

                        </div>

                    </div>


                    <!-- =================================================
                         Username
                    ================================================== -->

                    <div class="form-field">

                        <label for="contact-username">
                            Academy Username

                            <span class="field-optional">
                                Optional
                            </span>
                        </label>


                        <input type="text" id="contact-username" name="username"
                            value="<?= e($formValues['username']); ?>" maxlength="100" autocomplete="username"
                            spellcheck="false" autocapitalize="none" aria-describedby="contact-username-help">


                        <small class="field-help" id="contact-username-help">
                            If your question concerns an existing Blackthorne
                            account, including your username may help identify
                            the account involved.
                        </small>

                    </div>


                    <!-- =================================================
                         Subject
                    ================================================== -->

                    <div class="form-field">

                        <label for="contact-subject">

                            Subject

                            <span class="required-marker" aria-hidden="true">
                                *
                            </span>

                        </label>


                        <select id="contact-subject" name="subject" required>

                            <option value="" <?= $selectedSubject === '' ? 'selected' : ''; ?> disabled>
                                Choose the closest subject
                            </option>


                            <?php foreach ($contactSubjects as $value => $label): ?>

                            <option value="<?= e($value); ?>" <?= $selectedSubject === $value ? 'selected' : ''; ?>>
                                <?= e($label); ?>
                            </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <!-- =================================================
                         Message
                    ================================================== -->

                    <div class="form-field">

                        <label for="contact-message">

                            Message

                            <span class="required-marker" aria-hidden="true">
                                *
                            </span>

                        </label>


                        <textarea id="contact-message" name="message" rows="9" minlength="20" maxlength="5000" required
                            aria-describedby="contact-message-help"><?= e($formValues['message']); ?></textarea>


                        <div class="field-meta" id="contact-message-help">

                            <small>
                                Please provide enough detail for us to
                                understand the issue. Maximum 5,000 characters.
                            </small>

                        </div>

                    </div>


                    <!-- =================================================
                         Security Notice
                    ================================================== -->

                    <div class="contact-form-notice" id="contact-form-guidance">

                        <span class="contact-notice-icon" aria-hidden="true">
                            ✦
                        </span>


                        <p>
                            <strong>
                                Never include your password.
                            </strong>

                            Blackthorne Academy correspondence should never
                            require you to send account passwords, security
                            codes, or other private login credentials.
                        </p>

                    </div>


                    <!-- =================================================
                         Submit
                    ================================================== -->

                    <div class="contact-form-actions">

                        <button type="submit" class="button button-primary button-large">
                            Send Correspondence
                        </button>


                        <p>
                            By sending this form, you are providing the
                            information above so Blackthorne Academy can
                            respond to your inquiry.
                        </p>

                    </div>

                </form>

            </div>


            <!-- ========================================================
                 Guidance Sidebar
            ========================================================= -->

            <aside class="contact-guidance" aria-labelledby="contact-guidance-heading">

                <div class="contact-guidance-inner">

                    <img src="<?= e(asset('images/logo/logo_3.png')); ?>" alt="" class="contact-guidance-crest"
                        width="110" height="110" loading="lazy" decoding="async">


                    <p class="academy-overline">
                        Before You Write
                    </p>


                    <h2 id="contact-guidance-heading">
                        Help your message find its way.
                    </h2>


                    <div class="ornamental-rule" aria-hidden="true">
                        <span></span>
                        <i></i>
                        <span></span>
                    </div>


                    <div class="contact-guidance-list">


                        <section>

                            <span aria-hidden="true">
                                I
                            </span>


                            <div>

                                <h3>
                                    Choose the closest subject.
                                </h3>

                                <p>
                                    This makes it easier to identify what kind
                                    of help or response your message needs.
                                </p>

                            </div>

                        </section>


                        <section>

                            <span aria-hidden="true">
                                II
                            </span>


                            <div>

                                <h3>
                                    Include useful details.
                                </h3>

                                <p>
                                    If something went wrong, explain what you
                                    were trying to do, what happened, and what
                                    you expected instead.
                                </p>

                            </div>

                        </section>


                        <section>

                            <span aria-hidden="true">
                                III
                            </span>


                            <div>

                                <h3>
                                    Protect your account.
                                </h3>

                                <p>
                                    Never send passwords, verification codes,
                                    or other credentials through the contact
                                    form.
                                </p>

                            </div>

                        </section>


                    </div>


                    <div class="contact-guidance-actions">

                        <a href="<?= e(LOGIN_URL); ?>" class="button button-secondary">
                            Login
                        </a>


                        <a href="<?= e(REGISTER_URL); ?>" class="button button-primary">
                            Enroll
                        </a>

                    </div>

                </div>

            </aside>

        </div>

    </section>


    <!-- ================================================================
         SAFETY / MODERATION
    ================================================================= -->

    <section class="contact-safety" aria-labelledby="contact-safety-heading">

        <div class="section-inner">

            <div class="contact-safety-frame">


                <div class="contact-safety-symbol" aria-hidden="true">
                    !
                </div>


                <div>

                    <p class="academy-overline">
                        Safety &amp; Community Concerns
                    </p>


                    <h2 id="contact-safety-heading">
                        Some messages should not wait behind general questions.
                    </h2>


                    <p>
                        If your message concerns harassment, bullying,
                        inappropriate behavior, community safety, or a
                        moderation issue, choose
                        <strong>Safety, Conduct &amp; Moderation</strong>
                        in the contact form so the nature of the concern is
                        immediately clear.
                    </p>

                </div>


                <div class="contact-safety-action">

                    <a href="<?= e(url('contact.php?subject=safety#contact-form')); ?>" class="button button-secondary">
                        Report a Concern
                    </a>

                </div>

            </div>

        </div>

    </section>


    <!-- ================================================================
         CLOSING
    ================================================================= -->

    <section class="contact-closing" aria-labelledby="contact-closing-heading">

        <div class="section-inner">

            <div class="contact-closing-frame">

                <p class="academy-overline">
                    Blackthorne Correspondence
                </p>


                <h2 id="contact-closing-heading">
                    Every letter has to cross the gates somehow.
                </h2>


                <div class="ornamental-rule" aria-hidden="true">
                    <span></span>
                    <i></i>
                    <span></span>
                </div>


                <p>
                    Quill, parchment, and enchanted courier are optional.
                    A working email address is considerably more helpful.
                </p>

            </div>

        </div>

    </section>


</main>


<?php

require INCLUDES_PATH . '/footer.php';
