<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/profile-functions.php';

require_active_account();

header('Content-Type: application/json; charset=UTF-8');


/*
|--------------------------------------------------------------------------
| Blackthorne Academy
| Profile Bio Image Upload Endpoint
|--------------------------------------------------------------------------
|
| Authenticated, CSRF-protected endpoint for images inserted directly into
| the profile bio rich-text editor.
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

    header('Allow: POST');

    echo json_encode([
        'success' => false,
        'message' => 'Method Not Allowed',
    ]);

    exit;
}


if (
    !verify_csrf_token(
        isset($_POST['_csrf_token'])
            ? (string) $_POST['_csrf_token']
            : null
    )
) {
    http_response_code(403);

    echo json_encode([
        'success' => false,
        'message' => 'Your session token expired. Refresh the page and try again.',
    ]);

    exit;
}


$userId =
    (int) (
        current_user_id()
        ?? 0
    );


if ($userId <= 0) {
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'You must be signed in to upload a profile image.',
    ]);

    exit;
}


$file =
    $_FILES['image']
    ?? null;


if (!is_array($file)) {
    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => 'Choose an image to upload.',
    ]);

    exit;
}


try {
    $storedPath =
        profile_store_image_upload(
            $file,
            $userId,
            'bio'
        );

    echo json_encode([
        'success' => true,
        'url'     => $storedPath,
    ]);

} catch (Throwable $exception) {
    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => $exception->getMessage(),
    ]);
}
