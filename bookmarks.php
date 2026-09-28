<?php

declare(strict_types=1);

/**
 * Blackthorne Academy
 * Personal Bookmarks
 *
 * Upload path:
 * /bookmarks.php
 */

require_once __DIR__ . '/includes/bootstrap.php';

require_login();
require_active_account();

$user = current_user();

if ($user === null) {
    redirect(LOGIN_URL);
}

$userId =
    (int) (
        $user['id']
        ?? current_user_id()
    );

if ($userId <= 0) {
    redirect(LOGIN_URL);
}


/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function blackthorne_normalize_bookmark_url(
    string $bookmarkUrl
): ?string {

    $bookmarkUrl =
        trim(
            $bookmarkUrl
        );

    if ($bookmarkUrl === '') {
        return null;
    }

    /*
     * Allow site-relative URLs.
     */
    if (
        str_starts_with(
            $bookmarkUrl,
            '/'
        )
    ) {
        return $bookmarkUrl;
    }

    if (
        !filter_var(
            $bookmarkUrl,
            FILTER_VALIDATE_URL
        )
    ) {
        return null;
    }

    $scheme =
        strtolower(
            (string) parse_url(
                $bookmarkUrl,
                PHP_URL_SCHEME
            )
        );

    $host =
        strtolower(
            (string) parse_url(
                $bookmarkUrl,
                PHP_URL_HOST
            )
        );

    if (
        !in_array(
            $scheme,
            [
                'http',
                'https',
            ],
            true
        )
    ) {
        return null;
    }

    $allowedHosts = [
        'blkthrnacad.com',
        'www.blkthrnacad.com',
    ];

    if (
        !in_array(
            $host,
            $allowedHosts,
            true
        )
    ) {
        return null;
    }

    return $bookmarkUrl;
}


function blackthorne_bookmark_href(
    string $bookmarkUrl
): string {

    if (
        str_starts_with(
            $bookmarkUrl,
            '/'
        )
    ) {
        return $bookmarkUrl;
    }

    return $bookmarkUrl;
}


/*
|--------------------------------------------------------------------------
| Form State
|--------------------------------------------------------------------------
*/

$errors = [];

$editingBookmarkId =
    max(
        0,
        (int) (
            $_GET['edit']
            ?? 0
        )
    );

$formTitle = '';
$formUrl = '';
$formSortOrder = 0;


/*
|--------------------------------------------------------------------------
| Actions
|--------------------------------------------------------------------------
*/

if (is_post()) {
    require_valid_csrf();

    $action =
        trim(
            (string) (
                $_POST['action']
                ?? ''
            )
        );

    if (
        $action === 'add_bookmark'
        || $action === 'update_bookmark'
    ) {
        $bookmarkId =
            max(
                0,
                (int) (
                    $_POST['bookmark_id']
                    ?? 0
                )
            );

        $formTitle =
            trim(
                (string) (
                    $_POST['title']
                    ?? ''
                )
            );

        $formUrl =
            trim(
                (string) (
                    $_POST['bookmark_url']
                    ?? ''
                )
            );

        $formSortOrder =
            max(
                0,
                (int) (
                    $_POST['sort_order']
                    ?? 0
                )
            );

        if (
            $formTitle === ''
            || mb_strlen(
                $formTitle
            ) > 150
        ) {
            $errors[] =
                'Bookmark title is required and must be 150 characters or fewer.';
        }

        $normalizedBookmarkUrl =
            blackthorne_normalize_bookmark_url(
                $formUrl
            );

        if (
            $normalizedBookmarkUrl
            === null
        ) {
            $errors[] =
                'Enter a valid Blackthorne Academy URL. You may use a site-relative URL beginning with / or a full blkthrnacad.com URL.';
        }

        if ($errors === []) {

            if ($action === 'add_bookmark') {
                $insertStatement =
                    $pdo->prepare(
                        'INSERT INTO user_bookmarks (
                            user_id,
                            title,
                            bookmark_url,
                            sort_order
                         ) VALUES (
                            :user_id,
                            :title,
                            :bookmark_url,
                            :sort_order
                         )'
                    );

                $insertStatement->execute([
                    'user_id' =>
                        $userId,

                    'title' =>
                        $formTitle,

                    'bookmark_url' =>
                        $normalizedBookmarkUrl,

                    'sort_order' =>
                        $formSortOrder,
                ]);

                set_flash(
                    'success',
                    'Bookmark added.'
                );

                redirect(
                    url(
                        'bookmarks.php'
                    )
                );
            }


            if (
                $action === 'update_bookmark'
                && $bookmarkId > 0
            ) {
                $updateStatement =
                    $pdo->prepare(
                        'UPDATE user_bookmarks
                         SET
                            title = :title,
                            bookmark_url = :bookmark_url,
                            sort_order = :sort_order
                         WHERE id = :bookmark_id
                           AND user_id = :user_id
                         LIMIT 1'
                    );

                $updateStatement->execute([
                    'title' =>
                        $formTitle,

                    'bookmark_url' =>
                        $normalizedBookmarkUrl,

                    'sort_order' =>
                        $formSortOrder,

                    'bookmark_id' =>
                        $bookmarkId,

                    'user_id' =>
                        $userId,
                ]);

                set_flash(
                    'success',
                    'Bookmark updated.'
                );

                redirect(
                    url(
                        'bookmarks.php'
                    )
                );
            }
        }
    }


    if ($action === 'delete_bookmark') {
        $bookmarkId =
            max(
                0,
                (int) (
                    $_POST['bookmark_id']
                    ?? 0
                )
            );

        if ($bookmarkId > 0) {
            $deleteStatement =
                $pdo->prepare(
                    'DELETE FROM user_bookmarks
                     WHERE id = :bookmark_id
                       AND user_id = :user_id
                     LIMIT 1'
                );

            $deleteStatement->execute([
                'bookmark_id' =>
                    $bookmarkId,

                'user_id' =>
                    $userId,
            ]);
        }

        set_flash(
            'success',
            'Bookmark removed.'
        );

        redirect(
            url(
                'bookmarks.php'
            )
        );
    }
}


/*
|--------------------------------------------------------------------------
| Edit Existing Bookmark
|--------------------------------------------------------------------------
*/

if (
    !is_post()
    && $editingBookmarkId > 0
) {
    $editStatement =
        $pdo->prepare(
            'SELECT
                id,
                title,
                bookmark_url,
                sort_order
             FROM user_bookmarks
             WHERE id = :bookmark_id
               AND user_id = :user_id
             LIMIT 1'
        );

    $editStatement->execute([
        'bookmark_id' =>
            $editingBookmarkId,

        'user_id' =>
            $userId,
    ]);

    $editingBookmark =
        $editStatement->fetch(
            PDO::FETCH_ASSOC
        );

    if ($editingBookmark) {
        $formTitle =
            (string) (
                $editingBookmark['title']
                ?? ''
            );

        $formUrl =
            (string) (
                $editingBookmark['bookmark_url']
                ?? ''
            );

        $formSortOrder =
            (int) (
                $editingBookmark['sort_order']
                ?? 0
            );

    } else {
        $editingBookmarkId = 0;
    }
}


/*
|--------------------------------------------------------------------------
| Bookmark List
|--------------------------------------------------------------------------
*/

$bookmarkStatement =
    $pdo->prepare(
        'SELECT
            id,
            title,
            bookmark_url,
            sort_order,
            created_at,
            updated_at
         FROM user_bookmarks
         WHERE user_id = :user_id
         ORDER BY
            sort_order ASC,
            title ASC,
            id ASC'
    );

$bookmarkStatement->execute([
    'user_id' =>
        $userId,
]);

$bookmarks =
    $bookmarkStatement->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Page Metadata
|--------------------------------------------------------------------------
*/

$successMessage =
    get_flash(
        'success'
    );

$pageTitle =
    'Bookmarks | Blackthorne Academy';

$pageDescription =
    'Manage your personal Blackthorne Academy bookmarks.';

$pageCanonical =
    url(
        'bookmarks.php'
    );

$robots =
    'noindex, nofollow';

require
    INCLUDES_PATH
    . '/header.php';

?>

<main
    id="main-content"
    class="profile-edit-page"
>

    <section
        class="profile-edit-heading"
        aria-labelledby="bookmarks-title"
    >

        <div class="section-inner">

            <p class="academy-overline">
                Personal Shortcuts
            </p>

            <h1 id="bookmarks-title">
                Bookmarks
            </h1>

            <p>
                Save quick links to places around Blackthorne Academy so you can
                find them again easily.
            </p>

        </div>

    </section>


    <section class="profile-edit-content">

        <div class="section-inner">

            <?php if (
                is_string(
                    $successMessage
                )
                && $successMessage !== ''
            ): ?>

                <div
                    class="form-notice form-notice-success"
                    role="status"
                >
                    <?= e($successMessage); ?>
                </div>

            <?php endif; ?>


            <?php if ($errors !== []): ?>

                <div
                    class="form-notice form-notice-error"
                    role="alert"
                >

                    <p>
                        Please correct the following:
                    </p>

                    <ul>

                        <?php foreach ($errors as $error): ?>

                            <li>
                                <?= e($error); ?>
                            </li>

                        <?php endforeach; ?>

                    </ul>

                </div>

            <?php endif; ?>


            <section
                class="profile-edit-panel"
                aria-labelledby="bookmark-form-heading"
            >

                <header class="profile-edit-panel-heading">

                    <p class="academy-overline">
                        <?= $editingBookmarkId > 0
                            ? 'Edit Shortcut'
                            : 'Add Shortcut'; ?>
                    </p>

                    <h2 id="bookmark-form-heading">
                        <?= $editingBookmarkId > 0
                            ? 'Edit Bookmark'
                            : 'Add Bookmark'; ?>
                    </h2>

                    <p>
                        Use a title you will recognize and paste the Blackthorne
                        Academy page URL you want to save.
                    </p>

                </header>


                <form method="post">

                    <?= csrf_field(); ?>

                    <input
                        type="hidden"
                        name="action"
                        value="<?= $editingBookmarkId > 0
                            ? 'update_bookmark'
                            : 'add_bookmark'; ?>"
                    >

                    <?php if ($editingBookmarkId > 0): ?>

                        <input
                            type="hidden"
                            name="bookmark_id"
                            value="<?= (int) $editingBookmarkId; ?>"
                        >

                    <?php endif; ?>


                    <div class="profile-edit-grid">

                        <div class="form-group">

                            <label for="bookmark-title">
                                Title
                            </label>

                            <input
                                class="form-control"
                                type="text"
                                id="bookmark-title"
                                name="title"
                                value="<?= e($formTitle); ?>"
                                maxlength="150"
                                placeholder="Example: Potions Discussion Board"
                                required
                            >

                        </div>


                        <div class="form-group">

                            <label for="bookmark-sort-order">
                                Sort Order
                            </label>

                            <input
                                class="form-control"
                                type="number"
                                id="bookmark-sort-order"
                                name="sort_order"
                                value="<?= (int) $formSortOrder; ?>"
                                min="0"
                                step="1"
                            >

                            <p class="form-help">
                                Lower numbers appear first.
                            </p>

                        </div>


                        <div class="form-group form-group-full">

                            <label for="bookmark-url">
                                Blackthorne URL
                            </label>

                            <input
                                class="form-control"
                                type="text"
                                id="bookmark-url"
                                name="bookmark_url"
                                value="<?= e($formUrl); ?>"
                                maxlength="500"
                                placeholder="/forums.php or https://blkthrnacad.com/forums.php"
                                required
                            >

                            <p class="form-help">
                                Bookmarks are limited to pages on blkthrnacad.com.
                            </p>

                        </div>

                    </div>


                    <div class="profile-edit-actions profile-bookmark-form-actions">

                        <button
                            type="submit"
                            class="button button-primary"
                        >
                            <?= $editingBookmarkId > 0
                                ? 'Save Changes'
                                : 'Add Bookmark'; ?>
                        </button>

                        <?php if ($editingBookmarkId > 0): ?>

                            <a
                                href="<?= e(
                                    url(
                                        'bookmarks.php'
                                    )
                                ); ?>"
                                class="button button-secondary profile-bookmark-open-button"
                            >
                                Cancel
                            </a>

                        <?php endif; ?>

                    </div>

                </form>

            </section>


            <section
                class="profile-edit-panel"
                aria-labelledby="saved-bookmarks-heading"
            >

                <header class="profile-edit-panel-heading">

                    <p class="academy-overline">
                        Saved Shortcuts
                    </p>

                    <h2 id="saved-bookmarks-heading">
                        Your Bookmarks
                    </h2>

                </header>


                <?php if ($bookmarks === []): ?>

                    <div class="announcement-empty-state">

                        <p>
                            You have not saved any bookmarks yet.
                        </p>

                    </div>

                <?php else: ?>

                    <div class="announcement-list profile-bookmark-list">

                        <?php foreach ($bookmarks as $bookmark): ?>

                            <?php

                            $bookmarkId =
                                (int) (
                                    $bookmark['id']
                                    ?? 0
                                );

                            $bookmarkTitle =
                                trim(
                                    (string) (
                                        $bookmark['title']
                                        ?? 'Bookmark'
                                    )
                                );

                            $bookmarkUrl =
                                (string) (
                                    $bookmark['bookmark_url']
                                    ?? '/'
                                );

                            ?>

                            <article class="announcement-entry profile-bookmark-entry">

                                <h3>

                                    <a
                                        href="<?= e(
                                            blackthorne_bookmark_href(
                                                $bookmarkUrl
                                            )
                                        ); ?>"
                                    >
                                        <?= e($bookmarkTitle); ?>
                                    </a>

                                </h3>

                                <p class="announcement-byline">
                                    <?= e($bookmarkUrl); ?>
                                </p>

                                <div class="announcement-actions profile-bookmark-item-actions">

                                    <a
                                        href="<?= e(
                                            url(
                                                'bookmarks.php?edit='
                                                . $bookmarkId
                                            )
                                        ); ?>"
                                        class="button button-secondary profile-bookmark-edit-button"
                                    >
                                        Edit
                                    </a>

                                    <form
                                        method="post"
                                        onsubmit="return confirm('Remove this bookmark?');"
                                    >

                                        <?= csrf_field(); ?>

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="delete_bookmark"
                                        >

                                        <input
                                            type="hidden"
                                            name="bookmark_id"
                                            value="<?= $bookmarkId; ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="button button-secondary profile-bookmark-delete-button"
                                        >
                                            Delete
                                        </button>

                                    </form>

                                </div>

                            </article>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            </section>

        </div>

    </section>

</main>

<?php

require
    INCLUDES_PATH
    . '/footer.php';

?>
