<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';


/*
|--------------------------------------------------------------------------
| Logout
|--------------------------------------------------------------------------
|
| Logout is intentionally POST-only. The navigation uses a small form so a
| third-party page cannot log a member out merely by loading a URL.
|
*/

if (
    !is_post()
) {

    redirect(
        HOME_URL
    );

}


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

    set_flash(
        'error',
        'Your logout request could not be verified. Please try again.'
    );


    redirect(
        DASHBOARD_URL
    );

}


logout_current_user();


/*
|--------------------------------------------------------------------------
| Start Fresh Guest Session
|--------------------------------------------------------------------------
|
| logout_current_user() destroys the authenticated PHP session. Start a new
| guest session so flash messaging and CSRF protection continue to work on the
| next request.
|
*/

if (
    session_status()
    !== PHP_SESSION_ACTIVE
) {

    session_start();

}


set_flash(
    'success',
    'You have been logged out of Blackthorne Academy.'
);


redirect(
    LOGIN_URL
);
