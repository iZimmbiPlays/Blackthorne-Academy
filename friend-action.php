<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/friend-functions.php';

require_active_account();


/*
|--------------------------------------------------------------------------
| Blackthorne Academy
| Friend Action Endpoint
|--------------------------------------------------------------------------
|
| POST-only handler for:
|
| - add
| - cancel
| - accept
| - decline
| - unfriend
| - block
| - unblock
|
| Every mutation requires:
|
| - active authenticated account
| - valid CSRF token
| - valid target member ID
| - server-side relationship/blocking validation
|
*/


if (
    strtoupper(
        (string) (
            $_SERVER['REQUEST_METHOD']
            ?? 'GET'
        )
    ) !== 'POST'
) {
    http_response_code(405);

    header(
        'Allow: POST'
    );

    exit(
        'Method Not Allowed'
    );
}


require_valid_csrf();


$currentUserId =
    (int) (
        current_user_id()
        ?? 0
    );


/*
|--------------------------------------------------------------------------
| Safe Return URL
|--------------------------------------------------------------------------
|
| Forms may provide a relative internal return path, usually the member
| profile currently being viewed.
|
*/

function blackthorne_friend_action_return_url(
    ?string $returnTo
): string {
    $fallback =
        url(
            'index.php'
        );

    $returnTo =
        trim(
            (string) $returnTo
        );

    if ($returnTo === '') {
        return $fallback;
    }

    if (
        str_contains(
            $returnTo,
            '\\'
        )
        || preg_match(
            '/[\x00-\x1F\x7F]/',
            $returnTo
        ) === 1
        || str_starts_with(
            $returnTo,
            '//'
        )
    ) {
        return $fallback;
    }

    /*
     * Root-relative internal path.
     */
    if (
        str_starts_with(
            $returnTo,
            '/'
        )
    ) {
        return
            rtrim(
                APP_URL,
                '/'
            )
            . $returnTo;
    }

    /*
     * Relative internal path. Reject URI schemes.
     */
    $scheme =
        parse_url(
            $returnTo,
            PHP_URL_SCHEME
        );

    if ($scheme !== null) {
        return $fallback;
    }

    return
        url(
            $returnTo
        );
}


/*
|--------------------------------------------------------------------------
| Inputs
|--------------------------------------------------------------------------
*/

$action =
    strtolower(
        trim(
            (string) (
                $_POST['action']
                ?? ''
            )
        )
    );

$targetReference =
    trim(
        (string) (
            $_POST['target_user_id']
            ?? ''
        )
    );

$returnUrl =
    blackthorne_friend_action_return_url(
        isset(
            $_POST['return_to']
        )
            ? (string) $_POST['return_to']
            : null
    );


$validActions = [
    'add',
    'cancel',
    'accept',
    'decline',
    'unfriend',
    'block',
    'unblock',
];


if (
    !in_array(
        $action,
        $validActions,
        true
    )
) {
    set_flash(
        'error',
        'That member action was not recognized.'
    );

    redirect(
        $returnUrl
    );
}


if (
    $targetReference === ''
    || !ctype_digit(
        $targetReference
    )
) {
    set_flash(
        'error',
        'That member could not be found.'
    );

    redirect(
        $returnUrl
    );
}


$targetUserId =
    (int) $targetReference;


if (
    $targetUserId <= 0
    || $targetUserId === $currentUserId
) {
    set_flash(
        'error',
        'That member action could not be completed.'
    );

    redirect(
        $returnUrl
    );
}


/*
|--------------------------------------------------------------------------
| Process Action
|--------------------------------------------------------------------------
*/

try {
    $result =
        match ($action) {
            'add' =>
                blackthorne_send_friend_request(
                    $pdo,
                    $currentUserId,
                    $targetUserId
                ),

            'cancel' =>
                blackthorne_cancel_friend_request(
                    $pdo,
                    $currentUserId,
                    $targetUserId
                ),

            'accept' =>
                blackthorne_accept_friend_request(
                    $pdo,
                    $currentUserId,
                    $targetUserId
                ),

            'decline' =>
                blackthorne_decline_friend_request(
                    $pdo,
                    $currentUserId,
                    $targetUserId
                ),

            'unfriend' =>
                blackthorne_unfriend(
                    $pdo,
                    $currentUserId,
                    $targetUserId
                ),

            'block' =>
                blackthorne_block_user(
                    $pdo,
                    $currentUserId,
                    $targetUserId,
                    isset(
                        $_POST['reason']
                    )
                        ? (string) $_POST['reason']
                        : null
                ),

            'unblock' =>
                blackthorne_unblock_user(
                    $pdo,
                    $currentUserId,
                    $targetUserId
                ),

            default =>
                blackthorne_friend_result(
                    false,
                    'invalid_action',
                    'That member action could not be completed.'
                ),
        };


    if (
        (bool) (
            $result['success']
            ?? false
        )
    ) {
        set_flash(
            'success',
            (string) (
                $result['message']
                ?? 'Your changes were saved.'
            )
        );

    } else {
        set_flash(
            'error',
            (string) (
                $result['message']
                ?? 'That member action could not be completed.'
            )
        );
    }

} catch (Throwable $exception) {
    /*
     * Keep database/internal details out of member-facing output.
     *
     * The exception can still be surfaced through normal server/PHP logging.
     */
    error_log(
        'Blackthorne friend action failed: '
        . $exception->getMessage()
    );

    set_flash(
        'error',
        'That member action could not be completed. Please try again.'
    );
}


redirect(
    $returnUrl
);
