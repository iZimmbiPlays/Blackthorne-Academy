<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/forum-functions.php';

require_active_account();

/*
|--------------------------------------------------------------------------
| Blackthorne Academy
| New Forum Thread
|--------------------------------------------------------------------------
|
| Creates a new forum thread and its original post. The editor submits safe
| rich-text HTML that is normalized through the shared sanitize_rich_text()
| helper before it is stored.
|
*/

$userId = (int) current_user_id();

$forumReference = trim((string) (is_post() ? ($_POST['forum_id'] ?? '') : ($_GET['f'] ?? '')));

// On POST, the hidden forum_id is authoritative. This prevents a stale or
// mismatched query string from routing a new thread into another board.
if (is_post() && isset($_GET['f']) && ctype_digit((string) $_GET['f'])) {
    $postedForumId = ctype_digit((string) ($_POST['forum_id'] ?? ''))
        ? (int) $_POST['forum_id']
        : 0;
    if ($postedForumId > 0 && (int) $_GET['f'] !== $postedForumId) {
        header('Location: ' . url('new-thread.php?f=' . $postedForumId));
        exit;
    }
}

if ($forumReference === '' || !ctype_digit($forumReference)) {
    http_response_code(404);

    $pageTitle = 'Forum Not Found | Blackthorne Academy';
    $pageDescription = 'The requested Blackthorne Academy forum could not be found.';
    $robots = 'noindex, nofollow';

    require INCLUDES_PATH . '/header.php';
    ?>
<main id="main-content" class="forum-board-page">
    <section class="forum-board-error">
        <div class="section-inner">
            <h1>Forum Not Found</h1>
            <p>The requested board could not be found.</p>
            <a class="button button-secondary" href="<?= e(url('forums.php')); ?>">
                Return to Forums
            </a>
        </div>
    </section>
</main>
<?php
    require INCLUDES_PATH . '/footer.php';
    exit;
}

$forumId = (int) $forumReference;

$forumStatement = $pdo->prepare(
    'SELECT
        f.*,
        fc.title AS category_title,
        fc.slug AS category_slug
     FROM forums f
     INNER JOIN forum_categories fc
        ON fc.id = f.category_id
     WHERE f.id = :forum_id
     LIMIT 1'
);

$forumStatement->execute([
    'forum_id' => $forumId,
]);

$forum = $forumStatement->fetch(PDO::FETCH_ASSOC);

if (!$forum || (int) $forum['is_visible'] !== 1) {
    http_response_code(404);

    $pageTitle = 'Forum Not Found | Blackthorne Academy';
    $pageDescription = 'The requested Blackthorne Academy forum could not be found.';
    $robots = 'noindex, nofollow';

    require INCLUDES_PATH . '/header.php';
    ?>
<main id="main-content" class="forum-board-page">
    <section class="forum-board-error">
        <div class="section-inner">
            <h1>Forum Not Found</h1>
            <p>The requested board could not be found.</p>
            <a class="button button-secondary" href="<?= e(url('forums.php')); ?>">
                Return to Forums
            </a>
        </div>
    </section>
</main>
<?php
    require INCLUDES_PATH . '/footer.php';
    exit;
}

if (!forum_can_access_forum($pdo, $forumId, $userId)) {
    http_response_code(403);

    $pageTitle = 'Forum Access Restricted | Blackthorne Academy';
    $pageDescription = 'This Blackthorne Academy forum is restricted.';
    $robots = 'noindex, nofollow';

    require INCLUDES_PATH . '/header.php';
    ?>
<main id="main-content" class="forum-board-page">
    <section class="forum-board-error">
        <div class="section-inner">
            <h1>Access Restricted</h1>
            <p>Your account does not have permission to enter this board.</p>
            <a class="button button-secondary" href="<?= e(url('forums.php')); ?>">
                Return to Forums
            </a>
        </div>
    </section>
</main>
<?php
    require INCLUDES_PATH . '/footer.php';
    exit;
}

$activeForumMute =
    forum_active_mute(
        $pdo,
        $userId
    );

$isForumMuted =
    $activeForumMute !== null;

$canCreateThread =
    !$isForumMuted
    && forum_can_create_thread(
        $pdo,
        $forumId,
        $userId
    );

if (!$canCreateThread || (int) $forum['is_locked'] === 1) {
    http_response_code(403);

    $pageTitle = 'Thread Creation Unavailable | Blackthorne Academy';
    $pageDescription = 'A new thread cannot be created in this forum.';
    $robots = 'noindex, nofollow';

    require INCLUDES_PATH . '/header.php';
    ?>
<main id="main-content" class="forum-board-page">
    <section class="forum-board-error">
        <div class="section-inner">
            <h1>Thread Creation Unavailable</h1>

            <?php if ($isForumMuted): ?>

            <p>
                Your account is currently muted from posting forum content.
                <?php if (!empty($activeForumMute['expires_at'])): ?>
                This mute expires
                <?= e(
                                date(
                                    'M j, Y g:i A',
                                    strtotime(
                                        (string) $activeForumMute['expires_at']
                                    )
                                )
                            ); ?>.
                <?php else: ?>
                This mute does not currently have an automatic expiration.
                <?php endif; ?>
            </p>

            <?php else: ?>

            <p>
                You do not currently have permission to create a thread in this board,
                or the board is locked.
            </p>

            <?php endif; ?>

            <a class="button button-secondary" href="<?= e(url('forum.php?f=' . $forumId)); ?>">
                Return to <?= e((string) $forum['title']); ?>
            </a>
        </div>
    </section>
</main>
<?php
    require INCLUDES_PATH . '/footer.php';
    exit;
}

/*
|--------------------------------------------------------------------------
| Forum Ancestors
|--------------------------------------------------------------------------
|
| Forums may be nested recursively. Build the complete visible ancestor chain
| so New Thread keeps the full path for deeply nested sub-forums.
|
*/

$forumAncestors = [];

$ancestorId =
    $forum['parent_forum_id'] !== null
        ? (int) $forum['parent_forum_id']
        : 0;

$visitedAncestorIds = [];

$ancestorStatement =
    $pdo->prepare(
        'SELECT
            id,
            parent_forum_id,
            title

         FROM forums

         WHERE id = :forum_id

         LIMIT 1'
    );

while (
    $ancestorId > 0
    && !isset(
        $visitedAncestorIds[
            $ancestorId
        ]
    )
) {
    $visitedAncestorIds[
        $ancestorId
    ] = true;

    $ancestorStatement->execute([
        'forum_id' =>
            $ancestorId,
    ]);

    $ancestorForum =
        $ancestorStatement->fetch(
            PDO::FETCH_ASSOC
        );

    if (!is_array($ancestorForum)) {
        break;
    }

    if (
        forum_can_view_forum(
            $pdo,
            $ancestorId,
            $userId
        )
    ) {
        $forumAncestors[] =
            $ancestorForum;
    }

    $ancestorId =
        $ancestorForum[
            'parent_forum_id'
        ] !== null
            ? (int) $ancestorForum[
                'parent_forum_id'
            ]
            : 0;
}

$forumAncestors =
    array_reverse(
        $forumAncestors
    );

$errors = [];
$title = '';
$content = '';

/*
|--------------------------------------------------------------------------
| Image Upload Helpers
|--------------------------------------------------------------------------
*/

$allowedImageMimeTypes = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
    'image/avif' => 'avif',
];

$normalizeUploadFiles = static function (array $files): array {
    if (!isset($files['name']) || !is_array($files['name'])) {
        return [];
    }

    $normalized = [];

    foreach ($files['name'] as $index => $name) {
        $normalized[] = [
            'name' => (string) $name,
            'type' => (string) ($files['type'][$index] ?? ''),
            'tmp_name' => (string) ($files['tmp_name'][$index] ?? ''),
            'error' => (int) ($files['error'][$index] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($files['size'][$index] ?? 0),
        ];
    }

    return $normalized;
};

$stripPendingUploadImages = static function (string $html): string {
    return (string) preg_replace(
        '#<img\b[^>]*\bsrc=["\'](?:/)?__blackthorne_pending_image_[a-z0-9_-]+__["\'][^>]*>#i',
        '',
        $html
    );
};

if (is_post()) {
    require_valid_csrf();

    $title = trim((string) ($_POST['title'] ?? ''));
    $content = trim((string) ($_POST['content'] ?? ''));

    $titleLength = function_exists('mb_strlen')
        ? mb_strlen($title, 'UTF-8')
        : strlen($title);

    $plainContent = trim(
        html_entity_decode(
            strip_tags($content),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        )
    );
    $contentHasImage = preg_match('/<img\b/i', $content) === 1;

    if ($title === '') {
        $errors[] = 'Enter a title for your thread.';
    } elseif ($titleLength > 200) {
        $errors[] = 'The thread title cannot exceed 200 characters.';
    }

    if ($plainContent === '' && !$contentHasImage) {
        $errors[] = 'Write a message or add an image for your thread.';
    }

    if (strlen($content) > 500000) {
        $errors[] = 'The thread message is too large. Please shorten it and try again.';
    }

    /*
     * Re-check permissions on POST so a stale form cannot bypass a forum
     * lock or permission change made after the page was opened.
     */
    if (
        forum_user_is_muted(
            $pdo,
            $userId
        )
    ) {
        $errors[] =
            'Your account is currently muted from posting forum content.';
    } elseif (
        !forum_can_create_thread(
            $pdo,
            $forumId,
            $userId
        )
        || (int) $forum['is_locked'] === 1
    ) {
        $errors[] =
            'You no longer have permission to create a thread in this board.';
    }

    /*
     * Uploaded images are paired with unique client-generated tokens. The
     * editor stores temporary placeholder URLs in the HTML; those URLs are
     * replaced with permanent upload URLs only after the files are validated.
     */
    $uploadFiles = $normalizeUploadFiles($_FILES['uploaded_images'] ?? []);
    $uploadFiles = array_values(array_filter(
        $uploadFiles,
        static fn (array $file): bool => $file['error'] !== UPLOAD_ERR_NO_FILE
    ));

    $uploadTokensRaw = json_decode((string) ($_POST['upload_tokens'] ?? '[]'), true);
    $uploadTokens = is_array($uploadTokensRaw) ? array_values($uploadTokensRaw) : [];

    $maxUploads = max(0, (int) $forum['max_attachments_per_post']);
    $maxImageBytes = max(1, (int) $forum['max_image_size_mb']) * 1024 * 1024;
    $validatedUploads = [];

    if ($uploadFiles !== [] && (int) $forum['allow_images'] !== 1) {
        $errors[] = 'Image uploads are not enabled in this board.';
    }

    if ($maxUploads > 0 && count($uploadFiles) > $maxUploads) {
        $errors[] = 'You can upload up to ' . $maxUploads . ' images in one post.';
    }

    if (count($uploadTokens) !== count($uploadFiles)) {
        if ($uploadFiles !== []) {
            $errors[] = 'The selected images could not be matched to the editor. Please select them again.';
        }
    } else {
        $finfo = new finfo(FILEINFO_MIME_TYPE);

        foreach ($uploadFiles as $index => $file) {
            $token = strtolower(trim((string) ($uploadTokens[$index] ?? '')));

            if (preg_match('/^[a-z0-9_-]{8,80}$/', $token) !== 1) {
                $errors[] = 'One of the selected images has an invalid upload reference. Please select it again.';
                continue;
            }

            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'The image “' . $file['name'] . '” could not be uploaded.';
                continue;
            }

            if ($file['size'] < 1 || $file['size'] > $maxImageBytes) {
                $errors[] = 'The image “' . $file['name'] . '” must be ' . (int) $forum['max_image_size_mb'] . ' MB or smaller.';
                continue;
            }

            if (!is_uploaded_file($file['tmp_name'])) {
                $errors[] = 'The image “' . $file['name'] . '” was not received as a valid upload.';
                continue;
            }

            $mimeType = (string) $finfo->file($file['tmp_name']);

            if (!array_key_exists($mimeType, $allowedImageMimeTypes)) {
                $errors[] = 'The image “' . $file['name'] . '” must be a JPG, PNG, GIF, WEBP, or AVIF file.';
                continue;
            }

            if (@getimagesize($file['tmp_name']) === false) {
                $errors[] = 'The file “' . $file['name'] . '” is not a valid image.';
                continue;
            }

            $validatedUploads[] = [
                'token' => $token,
                'name' => mb_substr(basename($file['name']), 0, 255, 'UTF-8'),
                'tmp_name' => $file['tmp_name'],
                'size' => $file['size'],
                'mime_type' => $mimeType,
                'extension' => $allowedImageMimeTypes[$mimeType],
            ];
        }
    }

    /*
     * External HTTPS images can already be sanitized immediately. Local image
     * placeholders stay in the raw HTML until their uploads receive permanent
     * paths inside the transaction below.
     */
    $preSanitizedContent = sanitize_rich_text($content);
    $preSanitizedPlainContent = trim(
        html_entity_decode(
            strip_tags($preSanitizedContent),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        )
    );
    $preSanitizedHasImage = preg_match('/<img\b[^>]*\bsrc=/i', $preSanitizedContent) === 1;

    if (($plainContent !== '' || $contentHasImage) && $preSanitizedPlainContent === '' && !$preSanitizedHasImage) {
        $errors[] = 'Your message did not contain any supported forum content.';
    }

    if ($errors === []) {
        $replyVisibility = (string) $forum['access_mode'] === 'private_threads'
            ? 'staff_only'
            : 'public';

        try {
            $pdo->beginTransaction();

            $threadStatement = $pdo->prepare(
                'INSERT INTO forum_threads (
                    forum_id,
                    user_id,
                    title,
                    reply_visibility,
                    last_activity_at
                 ) VALUES (
                    :forum_id,
                    :user_id,
                    :title,
                    :reply_visibility,
                    CURRENT_TIMESTAMP
                 )'
            );

            $threadStatement->execute([
                'forum_id' => $forumId,
                'user_id' => $userId,
                'title' => $title,
                'reply_visibility' => $replyVisibility,
            ]);

            $threadId = (int) $pdo->lastInsertId();

            /*
             * Give the original post an ID before saving uploaded images so
             * forum_attachments can reference it. The post content is updated
             * after all temporary image placeholders have permanent URLs.
             */
            $postStatement = $pdo->prepare(
                'INSERT INTO forum_posts (
                    thread_id,
                    user_id,
                    parent_post_id,
                    content
                 ) VALUES (
                    :thread_id,
                    :user_id,
                    NULL,
                    :content
                 )'
            );

            $postStatement->execute([
                'thread_id' => $threadId,
                'user_id' => $userId,
                'content' => '<p>Preparing post…</p>',
            ]);

            $postId = (int) $pdo->lastInsertId();
            $finalContent = $content;
            $movedUploadPaths = [];

            if ($validatedUploads !== []) {
                $relativeDirectory = 'forum/' . date('Y') . '/' . date('m');
                $absoluteDirectory = rtrim(UPLOADS_PATH, '/\\') . DIRECTORY_SEPARATOR
                    . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);

                if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0755, true) && !is_dir($absoluteDirectory)) {
                    throw new RuntimeException('The forum image upload directory could not be created.');
                }

                $uploadsUrlPath = (string) parse_url(UPLOADS_URL, PHP_URL_PATH);
                $uploadsUrlPath = '/' . trim($uploadsUrlPath, '/');

                $attachmentStatement = $pdo->prepare(
                    'INSERT INTO forum_attachments (
                        post_id,
                        uploaded_by,
                        original_filename,
                        stored_filename,
                        file_path,
                        mime_type,
                        file_size,
                        attachment_type
                     ) VALUES (
                        :post_id,
                        :uploaded_by,
                        :original_filename,
                        :stored_filename,
                        :file_path,
                        :mime_type,
                        :file_size,
                        :attachment_type
                     )'
                );

                foreach ($validatedUploads as $upload) {
                    $storedFilename = bin2hex(random_bytes(18)) . '.' . $upload['extension'];
                    $relativePath = $relativeDirectory . '/' . $storedFilename;
                    $absolutePath = $absoluteDirectory . DIRECTORY_SEPARATOR . $storedFilename;
                    $publicPath = rtrim($uploadsUrlPath, '/') . '/' . $relativePath;

                    if (!move_uploaded_file($upload['tmp_name'], $absolutePath)) {
                        throw new RuntimeException('An uploaded forum image could not be saved.');
                    }

                    $movedUploadPaths[] = $absolutePath;

                    $attachmentStatement->execute([
                        'post_id' => $postId,
                        'uploaded_by' => $userId,
                        'original_filename' => $upload['name'],
                        'stored_filename' => $storedFilename,
                        'file_path' => $relativePath,
                        'mime_type' => $upload['mime_type'],
                        'file_size' => $upload['size'],
                        'attachment_type' => 'image',
                    ]);

                    $placeholder = '/__blackthorne_pending_image_' . $upload['token'] . '__';
                    $finalContent = str_replace($placeholder, $publicPath, $finalContent);
                }
            }

            /*
             * Never save an unresolved upload placeholder. This also protects
             * against a member removing a selected file after inserting it.
             */
            $finalContent = $stripPendingUploadImages($finalContent);
            $safeContent = sanitize_rich_text($finalContent);
            $safePlainContent = trim(
                html_entity_decode(
                    strip_tags($safeContent),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                )
            );
            $safeHasImage = preg_match('/<img\b[^>]*\bsrc=/i', $safeContent) === 1;

            if ($safePlainContent === '' && !$safeHasImage) {
                throw new RuntimeException('The finished forum post did not contain supported content.');
            }

            $updatePostStatement = $pdo->prepare(
                'UPDATE forum_posts
                 SET content = :content
                 WHERE id = :post_id
                 LIMIT 1'
            );

            $updatePostStatement->execute([
                'content' => $safeContent,
                'post_id' => $postId,
            ]);

            /*
             * Mark the author's own new post as read immediately.
             */
            $readStatement = $pdo->prepare(
                'INSERT INTO thread_read_status (
                    user_id,
                    thread_id,
                    last_read_post_id,
                    last_read_at
                 ) VALUES (
                    :user_id,
                    :thread_id,
                    :last_read_post_id,
                    CURRENT_TIMESTAMP
                 )
                 ON DUPLICATE KEY UPDATE
                    last_read_post_id = VALUES(last_read_post_id),
                    last_read_at = CURRENT_TIMESTAMP'
            );

            $readStatement->execute([
                'user_id' => $userId,
                'thread_id' => $threadId,
                'last_read_post_id' => $postId,
            ]);

            /*
             * Respect the member's auto-bookmark preference when present.
             */
            $settingsStatement = $pdo->prepare(
                'SELECT auto_bookmark_created_threads
                 FROM user_forum_settings
                 WHERE user_id = :user_id
                 LIMIT 1'
            );

            $settingsStatement->execute([
                'user_id' => $userId,
            ]);

            $autoBookmark = $settingsStatement->fetchColumn();

            if ($autoBookmark !== false && (int) $autoBookmark === 1) {
                $bookmarkStatement = $pdo->prepare(
                    'INSERT INTO thread_bookmarks (
                        user_id,
                        thread_id,
                        notify_on_update,
                        last_notified_post_id
                     ) VALUES (
                        :user_id,
                        :thread_id,
                        1,
                        :last_notified_post_id
                     )
                     ON DUPLICATE KEY UPDATE
                        notify_on_update = 1,
                        last_notified_post_id = VALUES(last_notified_post_id)'
                );

                $bookmarkStatement->execute([
                    'user_id' => $userId,
                    'thread_id' => $threadId,
                    'last_notified_post_id' => $postId,
                ]);
            }

            $pdo->commit();

            header(
                'Location: ' . url('thread.php?t=' . $threadId)
            );
            exit;

        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if (isset($movedUploadPaths) && is_array($movedUploadPaths)) {
                foreach ($movedUploadPaths as $movedPath) {
                    if (is_string($movedPath) && is_file($movedPath)) {
                        @unlink($movedPath);
                    }
                }
            }

            error_log(
                'Blackthorne thread creation error: '
                . $exception->getMessage()
            );

            $errors[] = 'Your thread could not be created. Please try again.';
        }
    }
}

if (is_post() && $errors !== [] && isset($uploadFiles) && $uploadFiles !== []) {
    $content = $stripPendingUploadImages($content);
    $errors[] = 'For security, browsers cannot keep selected image files after an unsuccessful submission. Please add your uploaded images again.';
}

$pageTitle = 'New Thread | ' . (string) $forum['title'] . ' | Blackthorne Academy';
$pageDescription = 'Create a new discussion thread in ' . (string) $forum['title'] . '.';
$robots = 'noindex, nofollow';

require INCLUDES_PATH . '/header.php';
?>

<main id="main-content" class="forum-board-page forum-new-thread-page">

    <section class="forum-board-hero">
        <div class="section-inner">

            <nav class="forum-breadcrumbs" aria-label="Breadcrumb">
                <a href="<?= e(url('forums.php')); ?>">Forums</a>
                <span aria-hidden="true">›</span>

                <?php foreach ($forumAncestors as $ancestorForum): ?>
                <a href="<?= e(
                            url(
                                'forum.php?f='
                                . (int) $ancestorForum[
                                    'id'
                                ]
                            )
                        ); ?>">
                    <?= e(
                            (string) $ancestorForum[
                                'title'
                            ]
                        ); ?>
                </a>
                <span aria-hidden="true">›</span>
                <?php endforeach; ?>

                <a href="<?= e(url('forum.php?f=' . $forumId)); ?>">
                    <?= e((string) $forum['title']); ?>
                </a>
                <span aria-hidden="true">›</span>
                <span aria-current="page">New Thread</span>
            </nav>

            <div class="forum-board-heading">
                <p class="forum-board-eyebrow">NEW DISCUSSION</p>
                <h1>Create a Thread</h1>
                <p>
                    Start a new discussion in
                    <strong><?= e((string) $forum['title']); ?></strong>.
                </p>
            </div>

        </div>
    </section>

    <section class="forum-thread-composer-section">
        <div class="section-inner">

            <?php if ($errors !== []): ?>
            <div class="alert alert-error forum-thread-form-errors" role="alert">
                <strong>Your thread was not created.</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                    <li><?= e($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php if ((string) $forum['access_mode'] === 'private_threads'): ?>
            <div class="forum-thread-private-notice">
                <strong>Private thread:</strong>
                this board is configured so your thread is visible to you and members
                who have permission to view all private threads.
            </div>
            <?php endif; ?>

            <form class="forum-thread-composer" method="post" enctype="multipart/form-data"
                action="<?= e(url('new-thread.php?f=' . $forumId)); ?>" id="new-thread-form">
                <?= csrf_field(); ?>
                <input type="hidden" name="forum_id" value="<?= $forumId; ?>">

                <div class="form-group forum-thread-title-field">
                    <label for="thread-title">Thread Title</label>
                    <input class="form-control" type="text" id="thread-title" name="title" value="<?= e($title); ?>"
                        maxlength="200" autocomplete="off" required>
                    <div class="forum-thread-title-meta">
                        <span>Choose a clear title for the discussion.</span>
                        <span id="thread-title-count" aria-live="polite">0 / 200</span>
                    </div>
                </div>

                <div class="form-group forum-rich-editor-field">
                    <label id="thread-message-label" for="thread-editor">Message</label>

                    <div class="forum-rich-editor" data-forum-editor>

                        <div class="forum-rich-editor-toolbar" role="toolbar" aria-label="Message formatting">
                            <div class="forum-editor-tool-group">
                                <button type="button" class="forum-editor-tool" data-command="bold" title="Bold"
                                    aria-label="Bold">
                                    <strong>B</strong>
                                </button>
                                <button type="button" class="forum-editor-tool" data-command="italic" title="Italic"
                                    aria-label="Italic">
                                    <em>I</em>
                                </button>
                                <button type="button" class="forum-editor-tool" data-command="underline"
                                    title="Underline" aria-label="Underline">
                                    <u>U</u>
                                </button>
                                <button type="button" class="forum-editor-tool" data-command="strikeThrough"
                                    title="Strikethrough" aria-label="Strikethrough">
                                    <s>S</s>
                                </button>
                            </div>

                            <div class="forum-editor-tool-group">
                                <label class="sr-only" for="thread-format">Text style</label>
                                <select id="thread-format" class="forum-editor-select" data-editor-format
                                    title="Text style">
                                    <option value="p">Paragraph</option>
                                    <option value="h2">Heading 2</option>
                                    <option value="h3">Heading 3</option>
                                    <option value="h4">Heading 4</option>
                                    <option value="blockquote">Quote Block</option>
                                </select>

                                <label class="sr-only" for="thread-font-size">Font size</label>
                                <select id="thread-font-size" class="forum-editor-select" data-editor-size
                                    title="Font size">
                                    <option value="3">Normal</option>
                                    <option value="2">Small</option>
                                    <option value="4">Large</option>
                                    <option value="5">Larger</option>
                                    <option value="6">Very Large</option>
                                </select>
                            </div>

                            <div class="forum-editor-tool-group forum-editor-color-tools">
                                <label class="forum-editor-color-label" title="Text color">
                                    <span>A</span>
                                    <input type="color" value="#e8e1e6" data-editor-color aria-label="Text color">
                                </label>
                                <button type="button" class="forum-editor-tool" data-editor-apply-color
                                    title="Apply the current text color to the selected text">Apply Text</button>
                                <label class="forum-editor-color-label" title="Highlight color">
                                    <span>▰</span>
                                    <input type="color" value="#55336f" data-editor-highlight
                                        aria-label="Highlight color">
                                </label>
                                <button type="button" class="forum-editor-tool" data-editor-apply-highlight
                                    title="Apply the current highlight color to the selected text">Apply
                                    Highlight</button>
                            </div>

                            <div class="forum-editor-tool-group">
                                <button type="button" class="forum-editor-tool" data-command="insertUnorderedList"
                                    title="Bulleted list" aria-label="Bulleted list">• List</button>
                                <button type="button" class="forum-editor-tool" data-command="insertOrderedList"
                                    title="Numbered list" aria-label="Numbered list">1. List</button>
                                <button type="button" class="forum-editor-tool" data-editor-quote
                                    title="Format selected text as a quote" aria-label="Quote selected text">
                                    Quote
                                </button>
                            </div>

                            <div class="forum-editor-tool-group">
                                <button type="button" class="forum-editor-tool" data-command="justifyLeft"
                                    title="Align left" aria-label="Align left">Left</button>
                                <button type="button" class="forum-editor-tool" data-command="justifyCenter"
                                    title="Align center" aria-label="Align center">Center</button>
                                <button type="button" class="forum-editor-tool" data-command="justifyRight"
                                    title="Align right" aria-label="Align right">Right</button>
                            </div>

                            <div class="forum-editor-tool-group">
                                <button type="button" class="forum-editor-tool" data-editor-link
                                    title="Insert link">Link</button>
                                <button type="button" class="forum-editor-tool" data-command="unlink"
                                    title="Remove link">Unlink</button>
                                <button type="button" class="forum-editor-tool forum-editor-youtube-tool"
                                    data-editor-youtube title="Embed a YouTube video">YouTube</button>
                                <button type="button" class="forum-editor-tool" data-command="removeFormat"
                                    title="Clear formatting">Clear</button>
                            </div>

                            <div class="forum-editor-tool-group forum-editor-image-tools">
                                <?php if ((int) $forum['allow_images'] === 1): ?>
                                <select class="forum-editor-select" data-editor-image-size title="Image size">
                                    <option value="">Image Size</option>
                                    <option value="25%">25%</option>
                                    <option value="40%">40%</option>
                                    <option value="50%">50%</option>
                                    <option value="60%">60%</option>
                                    <option value="75%">75%</option>
                                    <option value="90%">90%</option>
                                    <option value="100%">100%</option>
                                </select>

                                <select class="forum-editor-select" data-editor-image-align title="Image alignment">
                                    <option value="">Image Align</option>
                                    <option value="left">Left</option>
                                    <option value="center">Center</option>
                                    <option value="right">Right</option>
                                </select>

                                <button type="button" class="forum-editor-tool" data-editor-image-upload
                                    title="Upload an image from your device">
                                    Upload Image
                                </button>
                                <input class="forum-editor-image-upload-input" type="file" id="thread-image-upload"
                                    name="uploaded_images[]"
                                    accept="image/jpeg,image/png,image/gif,image/webp,image/avif" multiple
                                    data-editor-image-input>
                                <input type="hidden" name="upload_tokens" id="thread-upload-tokens" value="[]"
                                    data-editor-upload-tokens>
                                <?php endif; ?>

                                <button type="button" class="forum-editor-tool" data-editor-image-url
                                    title="Insert an image from an HTTPS URL">
                                    Image URL
                                </button>
                            </div>

                        </div>

                        <div class="forum-rich-editor-surface" id="thread-editor" contenteditable="true" role="textbox"
                            aria-labelledby="thread-message-label" aria-multiline="true"
                            data-placeholder="Write your thread here..." spellcheck="true">
                            <?= $content !== '' ? sanitize_rich_text($content) : ''; ?></div>

                        <?php if ((int) $forum['allow_images'] === 1): ?>
                        <div class="forum-editor-image-preview-list" data-editor-image-previews hidden
                            aria-live="polite"></div>
                        <?php endif; ?>

                        <div class="forum-editor-counts" aria-live="polite" aria-atomic="true">
                            <span data-editor-word-count>0 words</span>
                            <span aria-hidden="true">•</span>
                            <span data-editor-character-count>0 characters</span>
                        </div>

                        <textarea class="forum-rich-editor-input" name="content" id="thread-content" required
                            aria-hidden="true" tabindex="-1"><?= e($content); ?></textarea>

                    </div>

                    <p class="form-help">
                        Formatting is preserved when the thread is posted. You may insert an HTTPS image
                        URL<?php if ((int) $forum['allow_images'] === 1): ?> or upload up to
                        <?= (int) $forum['max_attachments_per_post']; ?> images
                        (<?= (int) $forum['max_image_size_mb']; ?> MB each)<?php endif; ?>.
                    </p>
                </div>

                <div class="forum-thread-composer-actions">
                    <a class="button button-secondary" href="<?= e(url('forum.php?f=' . $forumId)); ?>">
                        Cancel
                    </a>

                    <button class="button button-primary" type="submit">
                        Create Thread
                    </button>
                </div>

            </form>

        </div>
    </section>

</main>

<script>
    (() => {
        'use strict';

        const form = document.getElementById('new-thread-form');
        const editor = document.getElementById('thread-editor');
        const input = document.getElementById('thread-content');
        const title = document.getElementById('thread-title');
        const titleCount = document.getElementById('thread-title-count');
        const imageUploadButton = document.querySelector('[data-editor-image-upload]');
        const imageInput = document.querySelector('[data-editor-image-input]');
        const imageUrlButton = document.querySelector('[data-editor-image-url]');
        const imageSizeSelect = document.querySelector('[data-editor-image-size]');
        const imageAlignSelect = document.querySelector('[data-editor-image-align]');
        const imagePreviews = document.querySelector('[data-editor-image-previews]');
        const uploadTokensInput = document.querySelector('[data-editor-upload-tokens]');
        const wordCount = document.querySelector('[data-editor-word-count]');
        const characterCount = document.querySelector('[data-editor-character-count]');
        const quoteButton = document.querySelector('[data-editor-quote]');
        const youtubeButton = document.querySelector('[data-editor-youtube]');
        const maxImageUploads = <?= max(0, (int) $forum['max_attachments_per_post']); ?>;
        const maxImageBytes = <?= max(1, (int) $forum['max_image_size_mb']) * 1024 * 1024; ?>;

        if (!form || !editor || !input) {
            return;
        }

        let selectedUploads = [];
        let savedRange = null;
        let selectedImage = null;

        try {
            document.execCommand('styleWithCSS', false, true);
        } catch (error) {
            // Formatting still works in browsers that ignore styleWithCSS.
        }

        const escapeHtml = (value) => String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');

        const getPlainEditorText = () =>
            editor.textContent
            .replace(/\u00a0/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();

        const updateEditorCounts = () => {
            const plainText = getPlainEditorText();

            const characters =
                plainText.length;

            const words =
                plainText === '' ?
                0 :
                plainText
                .split(/\s+/u)
                .filter(Boolean)
                .length;

            if (wordCount) {
                wordCount.textContent =
                    `${words} ${words === 1 ? 'word' : 'words'}`;
            }

            if (characterCount) {
                characterCount.textContent =
                    `${characters} ${characters === 1 ? 'character' : 'characters'}`;
            }
        };

        const normalizeForumLink = (rawValue) => {
            const value =
                String(rawValue || '')
                .trim();

            if (
                value === '' ||
                /[\u0000-\u001F\u007F\\]/u.test(value)
            ) {
                return null;
            }

            if (value.startsWith('#')) {
                return value;
            }

            if (
                value.startsWith('/') &&
                !value.startsWith('//')
            ) {
                return value;
            }

            if (/^mailto:/i.test(value)) {
                const address =
                    value.slice(7);

                if (
                    address === '' ||
                    /\s/u.test(address)
                ) {
                    return null;
                }

                return `mailto:${address}`;
            }

            try {
                const parsed =
                    new URL(value);

                if (
                    parsed.protocol !== 'http:' &&
                    parsed.protocol !== 'https:'
                ) {
                    return null;
                }

                return parsed.href;
            } catch (error) {
                return null;
            }
        };

        const insertPlainTextAtSelection = (plainText) => {
            const normalized =
                String(plainText || '')
                .replace(/\r\n?/g, '\n');

            if (normalized === '') {
                return;
            }

            const safeHtml =
                escapeHtml(normalized)
                .replace(/\n/g, '<br>');

            insertHtmlAtSelection(
                safeHtml
            );
        };

        const youtubeVideoIdFromUrl = (rawValue) => {
            const value = String(rawValue || '').trim();

            if (value === '') {
                return null;
            }

            let parsed;

            try {
                parsed = new URL(value);
            } catch (error) {
                return null;
            }

            if (
                parsed.protocol !== 'https:' &&
                parsed.protocol !== 'http:'
            ) {
                return null;
            }

            const host =
                parsed.hostname
                .toLowerCase()
                .replace(/^(?:www\.|m\.)/, '');

            let videoId = null;

            if (host === 'youtu.be') {
                videoId =
                    parsed.pathname
                    .split('/')
                    .filter(Boolean)[0] ||
                    null;
            } else if (
                host === 'youtube.com' ||
                host === 'youtube-nocookie.com'
            ) {
                if (parsed.pathname === '/watch') {
                    videoId =
                        parsed.searchParams.get('v');
                } else {
                    const segments =
                        parsed.pathname
                        .split('/')
                        .filter(Boolean);

                    if (
                        segments.length >= 2 && ['shorts', 'embed', 'live'].includes(
                            segments[0].toLowerCase()
                        )
                    ) {
                        videoId = segments[1];
                    }
                }
            }

            return /^[A-Za-z0-9_-]{11}$/.test(videoId || '') ?
                videoId :
                null;
        };

        const insertYoutubeEmbedPlaceholder = (videoId) => {
            const href =
                `https://www.youtube.com/watch?v=${videoId}`;

            const safeHref =
                escapeHtml(href);

            const safeTitle =
                escapeHtml(
                    `blackthorne-youtube:${videoId}`
                );

            insertHtmlAtSelection(
                `<a href="${safeHref}" title="${safeTitle}" target="_blank" rel="noopener noreferrer nofollow">YouTube video: ${safeHref}</a>`
            );
        };

        const saveSelection = () => {
            const selection = window.getSelection();

            if (!selection || selection.rangeCount === 0) {
                return;
            }

            const range = selection.getRangeAt(0);

            if (editor.contains(range.commonAncestorContainer)) {
                savedRange = range.cloneRange();
            }
        };

        const restoreSelection = () => {
            if (!savedRange) {
                return;
            }

            /*
             * Clone the author's selection before returning focus to the
             * contenteditable. Chrome can collapse the live selection when the
             * toolbar/color control takes focus. Restoring from this private copy
             * keeps formatting attached to the highlighted text.
             */
            const rangeToRestore =
                savedRange.cloneRange();

            try {
                editor.focus({
                    preventScroll: true
                });
            } catch (error) {
                editor.focus();
            }

            const selection =
                window.getSelection();

            if (!selection) {
                return;
            }

            selection.removeAllRanges();
            selection.addRange(
                rangeToRestore
            );
        };

        const syncEditor = () => {
            const clone = editor.cloneNode(true);

            clone.querySelectorAll('img[data-upload-token]').forEach((image) => {
                const token = image.getAttribute('data-upload-token');

                if (token) {
                    image.setAttribute('src', `/__blackthorne_pending_image_${token}__`);
                }

                image.removeAttribute('data-upload-token');
            });

            input.value = clone.innerHTML.trim();
            updateEditorCounts();
        };

        const updateSelectedImageControls = () => {
            if (!selectedImage) {
                if (imageSizeSelect) {
                    imageSizeSelect.value = '';
                }

                if (imageAlignSelect) {
                    imageAlignSelect.value = '';
                }

                return;
            }

            if (imageSizeSelect) {
                const currentWidth =
                    selectedImage.style.width;

                const hasSize =
                    Array.from(
                        imageSizeSelect.options
                    ).some(
                        (option) =>
                        option.value === currentWidth
                    );

                imageSizeSelect.value =
                    hasSize ?
                    currentWidth :
                    '';
            }

            if (imageAlignSelect) {
                const left =
                    selectedImage.style.marginLeft;

                const right =
                    selectedImage.style.marginRight;

                if (
                    left === '0px' &&
                    right === 'auto'
                ) {
                    imageAlignSelect.value = 'left';
                } else if (
                    left === 'auto' &&
                    right === '0px'
                ) {
                    imageAlignSelect.value = 'right';
                } else if (
                    left === 'auto' &&
                    right === 'auto'
                ) {
                    imageAlignSelect.value = 'center';
                } else {
                    imageAlignSelect.value = '';
                }
            }
        };

        editor.addEventListener(
            'click',
            (event) => {
                const target =
                    event.target;

                selectedImage =
                    target instanceof HTMLImageElement &&
                    editor.contains(target) ?
                    target :
                    null;

                updateSelectedImageControls();
            }
        );

        if (imageSizeSelect) {
            imageSizeSelect.addEventListener(
                'change',
                () => {
                    if (
                        !selectedImage ||
                        !editor.contains(selectedImage)
                    ) {
                        window.alert(
                            'Click an image in the editor first, then choose its size.'
                        );

                        imageSizeSelect.value = '';
                        return;
                    }

                    const size =
                        imageSizeSelect.value;

                    if (size === '') {
                        return;
                    }

                    selectedImage.style.width =
                        size;

                    selectedImage.style.maxWidth =
                        '100%';

                    selectedImage.style.height =
                        'auto';

                    syncEditor();
                }
            );
        }

        if (imageAlignSelect) {
            imageAlignSelect.addEventListener(
                'change',
                () => {
                    if (
                        !selectedImage ||
                        !editor.contains(selectedImage)
                    ) {
                        window.alert(
                            'Click an image in the editor first, then choose its alignment.'
                        );

                        imageAlignSelect.value = '';
                        return;
                    }

                    const alignment =
                        imageAlignSelect.value;

                    if (alignment === '') {
                        return;
                    }

                    selectedImage.style.display =
                        'block';

                    if (alignment === 'left') {
                        selectedImage.style.marginLeft =
                            '0';

                        selectedImage.style.marginRight =
                            'auto';
                    } else if (alignment === 'right') {
                        selectedImage.style.marginLeft =
                            'auto';

                        selectedImage.style.marginRight =
                            '0';
                    } else {
                        selectedImage.style.marginLeft =
                            'auto';

                        selectedImage.style.marginRight =
                            'auto';
                    }

                    syncEditor();
                }
            );
        }

        const focusEditor = () => {
            editor.focus({
                preventScroll: true
            });
        };

        const applyBlockAlignment = (alignmentCommand) => {
            const alignmentMap = {
                justifyLeft: 'left',
                justifyCenter: 'center',
                justifyRight: 'right',
            };

            const alignment =
                alignmentMap[
                    alignmentCommand
                ] ??
                '';

            if (alignment === '') {
                return false;
            }

            if (!savedRange) {
                return true;
            }

            /*
             * Do not run a browser alignment command on the live selection.
             * Chrome can merge inline formatting when a selection crosses a
             * heading/paragraph boundary. Instead, identify the selected blocks,
             * apply alignment to a detached clone, then replace the editor HTML.
             * This preserves the exact <strong>, <em>, color, link, etc. markup.
             */
            const range =
                savedRange.cloneRange();

            const blockSelector =
                'p,h1,h2,h3,h4,h5,h6,blockquote,li,div';

            const liveBlocks =
                Array.from(
                    editor.querySelectorAll(
                        blockSelector
                    )
                );

            let selectedIndexes =
                liveBlocks
                .map(
                    (block, index) => {
                        try {
                            return range.intersectsNode(block) ?
                                index :
                                -1;
                        } catch (error) {
                            return -1;
                        }
                    }
                )
                .filter(
                    (index) => index >= 0
                );

            /*
             * If both an outer DIV and its inner P/H2 are selected, only style
             * the innermost blocks. This avoids wrapping/inheritance surprises.
             */
            selectedIndexes =
                selectedIndexes.filter(
                    (index) => {
                        const block =
                            liveBlocks[index];

                        return !selectedIndexes.some(
                            (otherIndex) =>
                            otherIndex !== index &&
                            block.contains(
                                liveBlocks[
                                    otherIndex
                                ]
                            )
                        );
                    }
                );

            if (selectedIndexes.length === 0) {
                let node =
                    range.commonAncestorContainer;

                if (node.nodeType === Node.TEXT_NODE) {
                    node =
                        node.parentElement;
                }

                const nearestBlock =
                    node instanceof Element ?
                    node.closest(
                        blockSelector
                    ) :
                    null;

                if (
                    nearestBlock &&
                    editor.contains(
                        nearestBlock
                    )
                ) {
                    const index =
                        liveBlocks.indexOf(
                            nearestBlock
                        );

                    if (index >= 0) {
                        selectedIndexes = [
                            index,
                        ];
                    }
                }
            }

            if (selectedIndexes.length === 0) {
                return true;
            }

            const editorClone =
                editor.cloneNode(true);

            const clonedBlocks =
                Array.from(
                    editorClone.querySelectorAll(
                        blockSelector
                    )
                );

            selectedIndexes.forEach(
                (index) => {
                    const clonedBlock =
                        clonedBlocks[index];

                    if (clonedBlock) {
                        clonedBlock.style.textAlign =
                            alignment;
                    }
                }
            );

            editor.innerHTML =
                editorClone.innerHTML;

            /*
             * The old Range points at nodes that were just replaced, so discard
             * it. The next mouse/keyboard selection will establish a fresh one.
             */
            savedRange = null;

            syncEditor();
            return true;
        };

        const runCommand = (command, value = null) => {
            if (applyBlockAlignment(command)) {
                return;
            }

            restoreSelection();
            document.execCommand(command, false, value);
            saveSelection();
            syncEditor();
        };

        const insertHtmlAtSelection = (html) => {
            restoreSelection();
            document.execCommand('insertHTML', false, html);
            saveSelection();
            syncEditor();
        };

        const createUploadToken = () => {
            if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
                const bytes = new Uint8Array(12);
                window.crypto.getRandomValues(bytes);
                return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
            }

            return `${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 14)}`;
        };

        const rebuildFileInput = () => {
            if (!imageInput || !uploadTokensInput) {
                return;
            }

            const transfer = new DataTransfer();

            selectedUploads.forEach((upload) => {
                transfer.items.add(upload.file);
            });

            imageInput.files = transfer.files;
            uploadTokensInput.value = JSON.stringify(selectedUploads.map((upload) => upload.token));
        };

        const renderImagePreviews = () => {
            if (!imagePreviews) {
                return;
            }

            imagePreviews.innerHTML = '';
            imagePreviews.hidden = selectedUploads.length === 0;

            selectedUploads.forEach((upload) => {
                const card = document.createElement('div');
                card.className = 'forum-editor-image-preview';
                card.dataset.uploadToken = upload.token;

                const image = document.createElement('img');
                image.src = upload.previewUrl;
                image.alt = '';

                const name = document.createElement('span');
                name.className = 'forum-editor-image-preview-name';
                name.textContent = upload.file.name;

                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'forum-editor-image-preview-remove';
                remove.setAttribute('aria-label', `Remove ${upload.file.name}`);
                remove.textContent = '×';
                remove.addEventListener('click', () => {
                    const target = selectedUploads.find((item) => item.token === upload.token);

                    if (target) {
                        URL.revokeObjectURL(target.previewUrl);
                    }

                    selectedUploads = selectedUploads.filter((item) => item.token !== upload
                        .token);
                    editor.querySelectorAll(
                        `img[data-upload-token="${CSS.escape(upload.token)}"]`).forEach((
                        embedded) => embedded.remove());
                    rebuildFileInput();
                    renderImagePreviews();
                    syncEditor();
                });

                card.append(image, name, remove);
                imagePreviews.appendChild(card);
            });
        };

        document.querySelectorAll('[data-command]').forEach((button) => {
            button.addEventListener('mousedown', (event) => {
                event.preventDefault();
            });

            button.addEventListener('click', () => {
                runCommand(button.dataset.command || '');
            });
        });

        const formatSelect = document.querySelector('[data-editor-format]');
        if (formatSelect) {
            formatSelect.addEventListener('change', () => {
                const value = formatSelect.value;
                runCommand('formatBlock', value === 'p' ? 'p' : value);
                formatSelect.value = 'p';
            });
        }

        const sizeSelect = document.querySelector('[data-editor-size]');
        if (sizeSelect) {
            sizeSelect.addEventListener('change', () => {
                runCommand('fontSize', sizeSelect.value);
                sizeSelect.value = '3';
            });
        }

        const colorInput = document.querySelector('[data-editor-color]');
        const applyColorButton = document.querySelector('[data-editor-apply-color]');
        const applyHighlightButton = document.querySelector('[data-editor-apply-highlight]');

        const applyTextColor = () => {
            if (!savedRange || savedRange.collapsed) {
                return;
            }

            runCommand('foreColor', colorInput.value);
        };

        if (colorInput) {
            colorInput.addEventListener('pointerdown', saveSelection);
            colorInput.addEventListener('click', applyTextColor);
            colorInput.addEventListener('input', applyTextColor);
            colorInput.addEventListener('change', applyTextColor);
        }

        const highlightInput = document.querySelector('[data-editor-highlight]');

        const applyHighlightColor = () => {
            if (!savedRange || savedRange.collapsed) {
                return;
            }

            restoreSelection();

            document.execCommand(
                document.queryCommandSupported('hiliteColor') ?
                'hiliteColor' :
                'backColor',
                false,
                highlightInput.value
            );

            syncEditor();
            saveSelection();
        };

        if (highlightInput) {
            highlightInput.addEventListener('pointerdown', saveSelection);
            highlightInput.addEventListener('click', applyHighlightColor);
            highlightInput.addEventListener('input', applyHighlightColor);
            highlightInput.addEventListener('change', applyHighlightColor);
        }

        if (applyColorButton) {
            applyColorButton.addEventListener('mousedown', (event) => event.preventDefault());
            applyColorButton.addEventListener('click', applyTextColor);
        }

        if (applyHighlightButton) {
            applyHighlightButton.addEventListener('mousedown', (event) => event.preventDefault());
            applyHighlightButton.addEventListener('click', applyHighlightColor);
        }

        const linkButton = document.querySelector('[data-editor-link]');
        if (linkButton) {
            linkButton.addEventListener('mousedown', (event) => {
                event.preventDefault();
            });

            linkButton.addEventListener('click', () => {
                saveSelection();

                const href =
                    window.prompt(
                        'Enter the link URL:'
                    );

                if (!href) {
                    return;
                }

                const normalizedHref =
                    normalizeForumLink(
                        href
                    );

                if (!normalizedHref) {
                    window.alert(
                        'Use a full http:// or https:// URL, a mailto: link, a #anchor, or a Blackthorne site-relative link beginning with a single /.'
                    );
                    return;
                }

                restoreSelection();

                const selection =
                    window.getSelection();

                if (
                    selection &&
                    selection.rangeCount > 0 &&
                    !selection.getRangeAt(0).collapsed
                ) {
                    document.execCommand(
                        'createLink',
                        false,
                        normalizedHref
                    );

                    const range =
                        selection.getRangeAt(0);

                    const anchor =
                        (
                            range.commonAncestorContainer instanceof Element ?
                            range.commonAncestorContainer :
                            range.commonAncestorContainer.parentElement
                        )?.closest('a');

                    if (anchor) {
                        const isExternal =
                            /^https?:\/\//i.test(
                                normalizedHref
                            );

                        anchor.setAttribute(
                            'rel',
                            'noopener noreferrer nofollow'
                        );

                        if (isExternal) {
                            anchor.setAttribute(
                                'target',
                                '_blank'
                            );
                        }
                    }
                } else {
                    const safeHref =
                        escapeHtml(
                            normalizedHref
                        );

                    insertHtmlAtSelection(
                        `<a href="${safeHref}" rel="noopener noreferrer nofollow">${safeHref}</a>`
                    );
                }

                saveSelection();
                syncEditor();
            });
        }

        if (quoteButton) {
            quoteButton.addEventListener(
                'mousedown',
                (event) => {
                    event.preventDefault();
                }
            );

            quoteButton.addEventListener(
                'click',
                () => {
                    runCommand(
                        'formatBlock',
                        'blockquote'
                    );
                }
            );
        }

        if (youtubeButton) {
            youtubeButton.addEventListener(
                'mousedown',
                (event) => {
                    event.preventDefault();
                    saveSelection();
                }
            );

            youtubeButton.addEventListener(
                'click',
                () => {
                    const youtubeUrl =
                        window.prompt(
                            'Paste the YouTube video URL:'
                        );

                    if (!youtubeUrl) {
                        return;
                    }

                    const videoId =
                        youtubeVideoIdFromUrl(
                            youtubeUrl
                        );

                    if (!videoId) {
                        window.alert(
                            'Use a valid YouTube video, Shorts, Live, or youtu.be URL.'
                        );
                        return;
                    }

                    insertYoutubeEmbedPlaceholder(
                        videoId
                    );
                }
            );
        }

        if (imageUrlButton) {
            imageUrlButton.addEventListener('mousedown', (event) => {
                event.preventDefault();
            });

            imageUrlButton.addEventListener('click', () => {
                const imageUrl = window.prompt('Enter the direct HTTPS image URL:');

                if (!imageUrl) {
                    return;
                }

                const trimmed = imageUrl.trim();

                if (!/^https:\/\//i.test(trimmed)) {
                    window.alert('Image URLs must begin with https://');
                    return;
                }

                const altText = window.prompt('Optional image description (alt text):') || '';
                insertHtmlAtSelection(
                    `<img src="${escapeHtml(trimmed)}" alt="${escapeHtml(altText.trim())}" loading="lazy" style="display:block;margin-left:auto;margin-right:auto;max-width:100%;height:auto;">`
                );
            });
        }

        if (imageUploadButton && imageInput) {
            imageUploadButton.addEventListener('mousedown', (event) => {
                event.preventDefault();
                saveSelection();
            });

            imageUploadButton.addEventListener('click', () => {
                imageInput.click();
            });

            imageInput.addEventListener('change', () => {
                const incomingFiles = Array.from(imageInput.files || []);

                if (incomingFiles.length === 0) {
                    rebuildFileInput();
                    return;
                }

                if (maxImageUploads > 0 && selectedUploads.length + incomingFiles.length >
                    maxImageUploads) {
                    window.alert(`You can upload up to ${maxImageUploads} images in one post.`);
                    rebuildFileInput();
                    return;
                }

                for (const file of incomingFiles) {
                    if (!file.type.startsWith('image/')) {
                        window.alert(`${file.name} is not an image file.`);
                        continue;
                    }

                    if (file.size > maxImageBytes) {
                        window.alert(`${file.name} is too large for this board.`);
                        continue;
                    }

                    const token = createUploadToken();
                    const previewUrl = URL.createObjectURL(file);

                    selectedUploads.push({
                        token,
                        file,
                        previewUrl
                    });
                    insertHtmlAtSelection(
                        `<img src="${escapeHtml(previewUrl)}" alt="${escapeHtml(file.name)}" data-upload-token="${escapeHtml(token)}">`
                    );
                }

                rebuildFileInput();
                renderImagePreviews();
                syncEditor();
            });
        }

        editor.addEventListener(
            'paste',
            (event) => {
                const clipboard =
                    event.clipboardData;

                if (!clipboard) {
                    return;
                }

                const plainText =
                    clipboard.getData(
                        'text/plain'
                    );

                if (plainText === '') {
                    return;
                }

                event.preventDefault();

                saveSelection();

                insertPlainTextAtSelection(
                    plainText
                );
            }
        );

        editor.addEventListener('input', () => {
            saveSelection();
            syncEditor();
        });
        editor.addEventListener('keyup', saveSelection);
        editor.addEventListener('mouseup', saveSelection);
        editor.addEventListener('blur', syncEditor);

        updateEditorCounts();

        if (title && titleCount) {
            const updateTitleCount = () => {
                titleCount.textContent = `${title.value.length} / 200`;
            };

            title.addEventListener('input', updateTitleCount);
            updateTitleCount();
        }

        form.addEventListener('submit', (event) => {
            rebuildFileInput();
            syncEditor();

            const plainText = getPlainEditorText();
            const hasImage = editor.querySelector('img') !== null;

            if (plainText === '' && !hasImage) {
                event.preventDefault();
                editor.focus();
                window.alert('Write a message or add an image before posting the thread.');
            }
        });
    })();

</script>


<style>
    /*
|--------------------------------------------------------------------------
| Rich Editor Working Area
|--------------------------------------------------------------------------
|
| Keep the editor at a practical default height so long content scrolls
| inside the writing area while the formatting toolbar stays accessible.
| The lower edge can still be dragged vertically when more room is useful.
|
*/

    .forum-rich-editor {
        display: flex;
        flex-direction: column;
        min-width: 0;
    }

    .forum-rich-editor-toolbar {
        position: relative;
        z-index: 3;
        flex: 0 0 auto;
    }

    .forum-rich-editor-surface {
        box-sizing: border-box;
        width: 100%;
        height: 420px;
        min-height: 260px;
        max-height: 78vh;
        overflow-x: auto;
        overflow-y: auto;
        resize: vertical;
        overscroll-behavior: contain;
        scrollbar-gutter: stable;
    }

    .forum-rich-editor-surface:focus {
        overflow-y: auto;
    }

    @media (max-width: 720px) {
        .forum-rich-editor-surface {
            height: 340px;
            min-height: 220px;
            max-height: 70vh;
        }
    }

</style>


<?php require INCLUDES_PATH . '/footer.php'; ?>
