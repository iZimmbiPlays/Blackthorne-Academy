<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/profile-functions.php';

require_login();
require_active_account();


$userId =
    (int) current_user_id();

$user =
    current_user();

if (
    $user === null
    || $userId <= 0
) {
    redirect(
        LOGIN_URL
    );
}


/*
|--------------------------------------------------------------------------
| Registration Birthday / Age
|--------------------------------------------------------------------------
|
| Birthday is collected during registration and remains the authoritative
| source for both the public Birthday field and calculated Age.
|
*/

$birthdayStatement =
    $pdo->prepare(
        'SELECT date_of_birth
         FROM users
         WHERE id = :user_id
         LIMIT 1'
    );

$birthdayStatement->execute([
    'user_id' => $userId,
]);

$registeredBirthday =
    trim(
        (string) (
            $birthdayStatement->fetchColumn()
            ?: ''
        )
    );

$birthdayDate =
    null;

$registeredBirthdayLabel =
    'Unavailable';

$calculatedAge =
    null;

if ($registeredBirthday !== '') {
    try {
        $academyTimezone =
            new DateTimeZone(
                'America/New_York'
            );

        $birthdayDate =
            new DateTimeImmutable(
                $registeredBirthday,
                $academyTimezone
            );

        $registeredBirthdayLabel =
            $birthdayDate->format(
                'F j, Y'
            );

        $today =
            new DateTimeImmutable(
                'today',
                $academyTimezone
            );

        $calculatedAge =
            $birthdayDate
                ->diff(
                    $today
                )
                ->y;

    } catch (Throwable) {
        $birthdayDate =
            null;
    }
}


/*
|--------------------------------------------------------------------------
| Load Profile
|--------------------------------------------------------------------------
*/

$profileStatement =
    $pdo->prepare(
        'SELECT
            up.*
         FROM user_profiles up
         WHERE up.user_id = :user_id
         LIMIT 1'
    );

$profileStatement->execute([
    'user_id' => $userId,
]);

$profile =
    $profileStatement->fetch(
        PDO::FETCH_ASSOC
    );

if (!$profile) {
    $profile = [
        'cover_image'          => null,
        'bio'                  => null,
        'pronouns'             => null,
        'gender'               => null,
        'birthday_display'     => 'month_day',
        'location'             => null,
        'timezone'             => null,
        'profile_visibility'   => 'members',
        'show_pronouns'        => 1,
        'show_location'        => 1,
        'show_timezone'        => 0,
        'show_join_date'       => 1,
        'show_roles'           => 1,
        'show_house'           => 1,
        'show_year_group'      => 1,
        'show_activity'        => 1,
        'show_achievements'    => 1,
        'show_online_status'   => 1,
        'show_current_location'=> 1,
        'show_last_seen'       => 1,
    ];
}


/*
|--------------------------------------------------------------------------
| Social Links
|--------------------------------------------------------------------------
*/

$socialPlatforms = [
    'instagram' => [
        'label' => 'Instagram',
        'placeholder' => 'https://instagram.com/username',
    ],
    'tiktok' => [
        'label' => 'TikTok',
        'placeholder' => 'https://www.tiktok.com/@username',
    ],
    'facebook' => [
        'label' => 'Facebook',
        'placeholder' => 'https://facebook.com/username',
    ],
    'youtube' => [
        'label' => 'YouTube',
        'placeholder' => 'https://youtube.com/@channel',
    ],
    'twitch' => [
        'label' => 'Twitch',
        'placeholder' => 'https://twitch.tv/username',
    ],
    'x' => [
        'label' => 'X',
        'placeholder' => 'https://x.com/username',
    ],
    'threads' => [
        'label' => 'Threads',
        'placeholder' => 'https://threads.net/@username',
    ],
    'bluesky' => [
        'label' => 'Bluesky',
        'placeholder' => 'https://bsky.app/profile/username.bsky.social',
    ],
    'pinterest' => [
        'label' => 'Pinterest',
        'placeholder' => 'https://pinterest.com/username',
    ],
    'reddit' => [
        'label' => 'Reddit',
        'placeholder' => 'https://reddit.com/user/username',
    ],
    'tumblr' => [
        'label' => 'Tumblr',
        'placeholder' => 'https://username.tumblr.com',
    ],
    'discord' => [
        'label' => 'Discord',
        'placeholder' => 'https://discord.gg/invite',
    ],
    'website' => [
        'label' => 'Website',
        'placeholder' => 'https://example.com',
    ],
];

$socialLinks = [];

$socialLinksStatement =
    $pdo->prepare(
        'SELECT platform, profile_url
         FROM user_social_links
         WHERE user_id = :user_id
         ORDER BY sort_order ASC, id ASC'
    );

$socialLinksStatement->execute([
    'user_id' => $userId,
]);

foreach (
    $socialLinksStatement->fetchAll(
        PDO::FETCH_ASSOC
    ) as $socialLinkRow
) {
    $platform =
        (string) (
            $socialLinkRow['platform']
            ?? ''
        );

    if (
        isset(
            $socialPlatforms[$platform]
        )
    ) {
        $socialLinks[$platform] =
            trim(
                (string) (
                    $socialLinkRow['profile_url']
                    ?? ''
                )
            );
    }
}


/*
|--------------------------------------------------------------------------
| Current Values
|--------------------------------------------------------------------------
*/

$displayName =
    trim(
        (string) (
            $user['display_name']
            ?? ''
        )
    );

$username =
    trim(
        (string) (
            $user['username']
            ?? ''
        )
    );

$bio =
    trim(
        (string) (
            $profile['bio']
            ?? ''
        )
    );

/*
|--------------------------------------------------------------------------
| Bio Editor Display HTML
|--------------------------------------------------------------------------
|
| Older profiles may contain plain text. New saves use sanitized rich-text
| HTML. This keeps both formats readable inside the editor.
|
*/

if ($bio === '') {
    $bioEditorHtml = '';
} elseif (
    preg_match(
        '/<\/?[a-z][^>]*>/i',
        $bio
    ) === 1
) {
    $bioEditorHtml =
        sanitize_rich_text(
            $bio
        );
} else {
    $bioEditorHtml =
        nl2br(
            e(
                $bio
            )
        );
}

$pronouns =
    trim(
        (string) (
            $profile['pronouns']
            ?? ''
        )
    );

$gender =
    trim(
        (string) (
            $profile['gender']
            ?? ''
        )
    );

$birthdayDisplay =
    (string) (
        $profile['birthday_display']
        ?? 'month_day'
    );

if (
    !in_array(
        $birthdayDisplay,
        [
            'month_day',
            'month_day_year',
            'month',
        ],
        true
    )
) {
    $birthdayDisplay =
        'month_day';
}

$location =
    trim(
        (string) (
            $profile['location']
            ?? ''
        )
    );

$timezone =
    trim(
        (string) (
            $profile['timezone']
            ?? ''
        )
    );

$profileVisibility =
    (string) (
        $profile['profile_visibility']
        ?? 'members'
    );

$currentAvatar =
    profile_safe_image_reference(
        isset($user['avatar'])
            ? (string) $user['avatar']
            : null
    );

$currentCover =
    profile_safe_image_reference(
        isset($profile['cover_image'])
            ? (string) $profile['cover_image']
            : null
    );


/*
|--------------------------------------------------------------------------
| Privacy Settings
|--------------------------------------------------------------------------
*/

$privacyKeys = [
    'show_pronouns',
    'show_location',
    'show_timezone',
    'show_join_date',
    'show_roles',
    'show_activity',
    'show_achievements',
    'show_online_status',
    'show_current_location',
    'show_last_seen',
];

$privacyValues = [];

foreach ($privacyKeys as $privacyKey) {
    $privacyValues[$privacyKey] =
        (int) (
            $profile[$privacyKey]
            ?? 0
        ) === 1;
}

/*
 * House and Class Year are Academy system identity fields. They are always
 * displayed and cannot be hidden by the member.
 */
$privacyValues['show_house'] =
    true;

$privacyValues['show_year_group'] =
    true;


/*
|--------------------------------------------------------------------------
| Timezones
|--------------------------------------------------------------------------
*/

$timezoneOptions =
    DateTimeZone::listIdentifiers();

if (
    $timezone !== ''
    && !in_array(
        $timezone,
        $timezoneOptions,
        true
    )
) {
    $timezoneOptions[] =
        $timezone;

    sort(
        $timezoneOptions
    );
}


/*
|--------------------------------------------------------------------------
| Personal Bookmark Helpers
|--------------------------------------------------------------------------
*/

function blackthorne_profile_bookmark_url(
    string $bookmarkUrl
): ?string {

    $bookmarkUrl =
        trim(
            $bookmarkUrl
        );

    if ($bookmarkUrl === '') {
        return null;
    }

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

    if (
        !in_array(
            $host,
            [
                'blkthrnacad.com',
                'www.blkthrnacad.com',
            ],
            true
        )
    ) {
        return null;
    }

    return $bookmarkUrl;
}


/*
|--------------------------------------------------------------------------
| Personal Bookmarks
|--------------------------------------------------------------------------
*/

$profileBookmarkErrors = [];

$bookmarkEditId =
    max(
        0,
        (int) (
            $_GET['bookmark_edit']
            ?? 0
        )
    );

$bookmarkFormTitle = '';
$bookmarkFormUrl = '';
$bookmarkFormSortOrder = 0;

$profilePostAction =
    is_post()
        ? trim(
            (string) (
                $_POST['action']
                ?? ''
            )
        )
        : '';

if (
    is_post()
    && in_array(
        $profilePostAction,
        [
            'add_bookmark',
            'update_bookmark',
            'delete_bookmark',
        ],
        true
    )
) {
    require_valid_csrf();

    $bookmarkId =
        max(
            0,
            (int) (
                $_POST['bookmark_id']
                ?? 0
            )
        );

    if ($profilePostAction === 'delete_bookmark') {
        if ($bookmarkId > 0) {
            $deleteBookmarkStatement =
                $pdo->prepare(
                    'DELETE FROM user_bookmarks
                     WHERE id = :bookmark_id
                       AND user_id = :user_id
                     LIMIT 1'
                );

            $deleteBookmarkStatement->execute([
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
                'profile-edit.php'
            )
        );
    }

    $bookmarkFormTitle =
        trim(
            (string) (
                $_POST['bookmark_title']
                ?? ''
            )
        );

    $bookmarkFormUrl =
        trim(
            (string) (
                $_POST['bookmark_url']
                ?? ''
            )
        );

    $bookmarkFormSortOrder =
        max(
            0,
            (int) (
                $_POST['bookmark_sort_order']
                ?? 0
            )
        );

    if (
        $bookmarkFormTitle === ''
        || mb_strlen(
            $bookmarkFormTitle
        ) > 150
    ) {
        $profileBookmarkErrors[] =
            'Bookmark title is required and must be 150 characters or fewer.';
    }

    $normalizedBookmarkUrl =
        blackthorne_profile_bookmark_url(
            $bookmarkFormUrl
        );

    if ($normalizedBookmarkUrl === null) {
        $profileBookmarkErrors[] =
            'Enter a valid Blackthorne Academy URL.';
    }

    if (
        $profileBookmarkErrors === []
        && $profilePostAction === 'add_bookmark'
    ) {
        $bookmarkCountStatement =
            $pdo->prepare(
                'SELECT COUNT(*)
                 FROM user_bookmarks
                 WHERE user_id = :user_id'
            );

        $bookmarkCountStatement->execute([
            'user_id' =>
                $userId,
        ]);

        $existingBookmarkCount =
            (int) $bookmarkCountStatement->fetchColumn();

        if ($existingBookmarkCount >= 10) {
            $profileBookmarkErrors[] =
                'You can save a maximum of 10 bookmarks. Delete one before adding another.';
        }
    }

    if ($profileBookmarkErrors === []) {

        if ($profilePostAction === 'add_bookmark') {
            $addBookmarkStatement =
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

            $addBookmarkStatement->execute([
                'user_id' =>
                    $userId,

                'title' =>
                    $bookmarkFormTitle,

                'bookmark_url' =>
                    $normalizedBookmarkUrl,

                'sort_order' =>
                    $bookmarkFormSortOrder,
            ]);

            set_flash(
                'success',
                'Bookmark added.'
            );

            redirect(
                url(
                    'profile-edit.php'
                )
            );
        }

        if (
            $profilePostAction === 'update_bookmark'
            && $bookmarkId > 0
        ) {
            $updateBookmarkStatement =
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

            $updateBookmarkStatement->execute([
                'title' =>
                    $bookmarkFormTitle,

                'bookmark_url' =>
                    $normalizedBookmarkUrl,

                'sort_order' =>
                    $bookmarkFormSortOrder,

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
                    'profile-edit.php'
                )
            );
        }
    }
}

if (
    !is_post()
    && $bookmarkEditId > 0
) {
    $bookmarkEditStatement =
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

    $bookmarkEditStatement->execute([
        'bookmark_id' =>
            $bookmarkEditId,

        'user_id' =>
            $userId,
    ]);

    $editingBookmark =
        $bookmarkEditStatement->fetch(
            PDO::FETCH_ASSOC
        );

    if ($editingBookmark) {
        $bookmarkFormTitle =
            (string) (
                $editingBookmark['title']
                ?? ''
            );

        $bookmarkFormUrl =
            (string) (
                $editingBookmark['bookmark_url']
                ?? ''
            );

        $bookmarkFormSortOrder =
            (int) (
                $editingBookmark['sort_order']
                ?? 0
            );

    } else {
        $bookmarkEditId = 0;
    }
}

$profileBookmarksStatement =
    $pdo->prepare(
        'SELECT
            id,
            title,
            bookmark_url,
            sort_order
         FROM user_bookmarks
         WHERE user_id = :user_id
         ORDER BY
            sort_order ASC,
            title ASC,
            id ASC'
    );

$profileBookmarksStatement->execute([
    'user_id' =>
        $userId,
]);

$profileBookmarks =
    $profileBookmarksStatement->fetchAll(
        PDO::FETCH_ASSOC
    );

$profileBookmarkLimit = 10;

$profileBookmarkCount =
    count(
        $profileBookmarks
    );

$profileBookmarkLimitReached =
    $profileBookmarkCount
    >= $profileBookmarkLimit;


/*
|--------------------------------------------------------------------------
| Form State
|--------------------------------------------------------------------------
*/

$errors = [];

$successMessage =
    get_flash(
        'success'
    );


/*
|--------------------------------------------------------------------------
| Save Profile
|--------------------------------------------------------------------------
*/

if (
    is_post()
    && !in_array(
        $profilePostAction,
        [
            'add_bookmark',
            'update_bookmark',
            'delete_bookmark',
        ],
        true
    )
) {
    require_valid_csrf();

    $displayName =
        trim(
            post_value(
                'display_name'
            )
        );

    $submittedBio =
        trim(
            post_value(
                'bio'
            )
        );

    $bio =
        sanitize_rich_text(
            $submittedBio
        );

    $bioEditorHtml =
        $bio;

    $bioPlainText =
        trim(
            html_entity_decode(
                strip_tags(
                    $bio
                ),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            )
        );

    $pronouns =
        trim(
            post_value(
                'pronouns'
            )
        );

    $gender =
        trim(
            post_value(
                'gender'
            )
        );

    $birthdayDisplay =
        post_value(
            'birthday_display'
        );

    $submittedSocialLinks =
        $_POST['social_links']
        ?? [];

    if (
        !is_array(
            $submittedSocialLinks
        )
    ) {
        $submittedSocialLinks =
            [];
    }

    $socialLinks =
        [];

    foreach (
        $socialPlatforms
        as $platformKey => $platformConfig
    ) {
        $socialUrl =
            trim(
                (string) (
                    $submittedSocialLinks[$platformKey]
                    ?? ''
                )
            );

        if ($socialUrl === '') {
            continue;
        }

        if (
            strlen(
                $socialUrl
            ) > 500
        ) {
            $errors[] =
                $platformConfig['label']
                . ' link must be 500 characters or fewer.';

            continue;
        }

        $parsedScheme =
            strtolower(
                (string) (
                    parse_url(
                        $socialUrl,
                        PHP_URL_SCHEME
                    )
                    ?? ''
                )
            );

        if (
            !filter_var(
                $socialUrl,
                FILTER_VALIDATE_URL
            )
            || !in_array(
                $parsedScheme,
                [
                    'http',
                    'https',
                ],
                true
            )
        ) {
            $errors[] =
                'Enter a valid http:// or https:// URL for '
                . $platformConfig['label']
                . '.';

            continue;
        }

        $socialLinks[$platformKey] =
            $socialUrl;
    }

    $location =
        trim(
            post_value(
                'location'
            )
        );

    $timezone =
        trim(
            post_value(
                'timezone'
            )
        );

    $profileVisibility =
        post_value(
            'profile_visibility'
        );

    if (
        $displayName === ''
        || mb_strlen(
            $displayName
        ) > 100
    ) {
        $errors[] =
            'Display name is required and must be 100 characters or fewer.';
    }

    if (
        mb_strlen(
            $bioPlainText
        ) > 5000
    ) {
        $errors[] =
            'Your bio must be 5,000 characters or fewer.';
    }

    if (
        strlen(
            $submittedBio
        ) > 50000
    ) {
        $errors[] =
            'Your styled bio is too large. Please simplify the formatting and try again.';
    }

    if (
        mb_strlen(
            $pronouns
        ) > 50
    ) {
        $errors[] =
            'Pronouns must be 50 characters or fewer.';
    }

    if (
        mb_strlen(
            $gender
        ) > 50
    ) {
        $errors[] =
            'Gender must be 50 characters or fewer.';
    }

    if (
        !in_array(
            $birthdayDisplay,
            [
                'month_day',
                'month_day_year',
                'month',
            ],
            true
        )
    ) {
        $errors[] =
            'Choose a valid birthday display format.';
    }

    if (
        mb_strlen(
            $location
        ) > 100
    ) {
        $errors[] =
            'Location must be 100 characters or fewer.';
    }

    if (
        $timezone !== ''
        && !in_array(
            $timezone,
            DateTimeZone::listIdentifiers(),
            true
        )
    ) {
        $errors[] =
            'Choose a valid timezone.';
    }

    if (
        !in_array(
            $profileVisibility,
            [
                'everyone',
                'members',
                'staff_only',
            ],
            true
        )
    ) {
        $errors[] =
            'Choose a valid profile visibility setting.';
    }

    foreach ($privacyKeys as $privacyKey) {
        $privacyValues[$privacyKey] =
            isset(
                $_POST[$privacyKey]
            );
    }


    /*
    |--------------------------------------------------------------------------
    | Image Choices
    |--------------------------------------------------------------------------
    */

    $removeAvatar =
        isset(
            $_POST['remove_avatar']
        );

    $removeCover =
        isset(
            $_POST['remove_cover']
        );

    $avatarUrl =
        trim(
            post_value(
                'avatar_url'
            )
        );

    $coverUrl =
        trim(
            post_value(
                'cover_url'
            )
        );

    $avatarUpload =
        $_FILES['avatar_upload']
        ?? null;

    $coverUpload =
        $_FILES['cover_upload']
        ?? null;

    $hasAvatarUpload =
        is_array(
            $avatarUpload
        )
        && (
            (int) (
                $avatarUpload['error']
                ?? UPLOAD_ERR_NO_FILE
            )
        ) !== UPLOAD_ERR_NO_FILE;

    $hasCoverUpload =
        is_array(
            $coverUpload
        )
        && (
            (int) (
                $coverUpload['error']
                ?? UPLOAD_ERR_NO_FILE
            )
        ) !== UPLOAD_ERR_NO_FILE;

    if (
        $hasAvatarUpload
        && $avatarUrl !== ''
    ) {
        $errors[] =
            'Choose either an avatar upload or an avatar image URL, not both.';
    }

    if (
        $hasCoverUpload
        && $coverUrl !== ''
    ) {
        $errors[] =
            'Choose either a cover upload or a cover image URL, not both.';
    }

    if (
        $removeAvatar
        && (
            $hasAvatarUpload
            || $avatarUrl !== ''
        )
    ) {
        $errors[] =
            'Remove Avatar cannot be selected while adding a new avatar.';
    }

    if (
        $removeCover
        && (
            $hasCoverUpload
            || $coverUrl !== ''
        )
    ) {
        $errors[] =
            'Remove Cover cannot be selected while adding a new cover image.';
    }


    /*
    |--------------------------------------------------------------------------
    | Remote Image Links
    |--------------------------------------------------------------------------
    |
    | Links are not stored directly anymore. They are imported through the
    | shared helper, validated, saved locally, resized when needed, and given
    | a WebP derivative before the database is updated.
    |
    */

    $hasAvatarUrl =
        $avatarUrl !== '';

    $hasCoverUrl =
        $coverUrl !== '';


    /*
    |--------------------------------------------------------------------------
    | Save
    |--------------------------------------------------------------------------
    */

    if ($errors === []) {
        $newAvatarUploadPath =
            null;

        $newCoverUploadPath =
            null;

        $newAvatarImportedPath =
            null;

        $newCoverImportedPath =
            null;

        $oldAvatarReference =
            $currentAvatar;

        $oldCoverReference =
            $currentCover;

        $profileSaveStage =
            'starting profile save';

        try {

            /*
             * Store/import new images before the database transaction. If the
             * database update fails, new local files are removed in the catch
             * block.
             */
            if ($hasAvatarUpload) {
                $profileSaveStage = 'storing avatar upload';
                $newAvatarUploadPath =
                    profile_store_image_upload(
                        $avatarUpload,
                        $userId,
                        'avatar'
                    );

            } elseif ($hasAvatarUrl) {
                $profileSaveStage = 'importing avatar URL';
                $newAvatarImportedPath =
                    profile_import_external_image(
                        $avatarUrl,
                        $userId,
                        'avatar'
                    );
            }

            if ($hasCoverUpload) {
                $profileSaveStage = 'storing cover upload';
                $newCoverUploadPath =
                    profile_store_image_upload(
                        $coverUpload,
                        $userId,
                        'cover'
                    );

            } elseif ($hasCoverUrl) {
                $profileSaveStage = 'importing cover URL';
                $newCoverImportedPath =
                    profile_import_external_image(
                        $coverUrl,
                        $userId,
                        'cover'
                    );
            }

            $newAvatarReference =
                $oldAvatarReference;

            if ($removeAvatar) {
                $newAvatarReference =
                    null;

            } elseif (
                $newAvatarUploadPath !== null
            ) {
                $newAvatarReference =
                    $newAvatarUploadPath;

            } elseif (
                $newAvatarImportedPath !== null
            ) {
                $newAvatarReference =
                    $newAvatarImportedPath;
            }

            $newCoverReference =
                $oldCoverReference;

            if ($removeCover) {
                $newCoverReference =
                    null;

            } elseif (
                $newCoverUploadPath !== null
            ) {
                $newCoverReference =
                    $newCoverUploadPath;

            } elseif (
                $newCoverImportedPath !== null
            ) {
                $newCoverReference =
                    $newCoverImportedPath;
            }


            $profileSaveStage = 'starting database transaction';
            $pdo->beginTransaction();


            /*
             * Account-level display information.
             */
            $profileSaveStage = 'updating users display name/avatar';
            $userUpdateStatement =
                $pdo->prepare(
                    'UPDATE users
                     SET
                        display_name = :display_name,
                        avatar = :avatar
                     WHERE id = :user_id
                     LIMIT 1'
                );

            $userUpdateStatement->execute([
                'display_name' =>
                    $displayName,

                'avatar' =>
                    $newAvatarReference,

                'user_id' =>
                    $userId,
            ]);


            /*
             * Profile data. The unique user_id key makes this a safe one-row
             * per member upsert.
             */
            $profileSaveStage = 'saving user_profiles row';
            $profileSaveStatement =
                $pdo->prepare(
                    'INSERT INTO user_profiles (
                        user_id,
                        cover_image,
                        bio,
                        pronouns,
                        gender,
                        birthday_display,
                        location,
                        timezone,
                        profile_visibility,
                        show_pronouns,
                        show_location,
                        show_timezone,
                        show_join_date,
                        show_roles,
                        show_house,
                        show_year_group,
                        show_activity,
                        show_achievements,
                        show_online_status,
                        show_current_location,
                        show_last_seen
                     ) VALUES (
                        :user_id,
                        :cover_image,
                        :bio,
                        :pronouns,
                        :gender,
                        :birthday_display,
                        :location,
                        :timezone,
                        :profile_visibility,
                        :show_pronouns,
                        :show_location,
                        :show_timezone,
                        :show_join_date,
                        :show_roles,
                        :show_house,
                        :show_year_group,
                        :show_activity,
                        :show_achievements,
                        :show_online_status,
                        :show_current_location,
                        :show_last_seen
                     )
                     ON DUPLICATE KEY UPDATE
                        cover_image = VALUES(cover_image),
                        bio = VALUES(bio),
                        pronouns = VALUES(pronouns),
                        gender = VALUES(gender),
                        birthday_display = VALUES(birthday_display),
                        location = VALUES(location),
                        timezone = VALUES(timezone),
                        profile_visibility = VALUES(profile_visibility),
                        show_pronouns = VALUES(show_pronouns),
                        show_location = VALUES(show_location),
                        show_timezone = VALUES(show_timezone),
                        show_join_date = VALUES(show_join_date),
                        show_roles = VALUES(show_roles),
                        show_house = VALUES(show_house),
                        show_year_group = VALUES(show_year_group),
                        show_activity = VALUES(show_activity),
                        show_achievements = VALUES(show_achievements),
                        show_online_status = VALUES(show_online_status),
                        show_current_location = VALUES(show_current_location),
                        show_last_seen = VALUES(show_last_seen)'
                );

            $profileSaveStatement->execute([
                'user_id' =>
                    $userId,

                'cover_image' =>
                    $newCoverReference,

                'bio' =>
                    $bio !== ''
                        ? $bio
                        : null,

                'pronouns' =>
                    $pronouns !== ''
                        ? $pronouns
                        : null,

                'gender' =>
                    $gender !== ''
                        ? $gender
                        : null,

                'birthday_display' =>
                    $birthdayDisplay,

                'location' =>
                    $location !== ''
                        ? $location
                        : null,

                'timezone' =>
                    $timezone !== ''
                        ? $timezone
                        : null,

                'profile_visibility' =>
                    $profileVisibility,

                'show_pronouns' =>
                    $privacyValues['show_pronouns']
                        ? 1
                        : 0,

                'show_location' =>
                    $privacyValues['show_location']
                        ? 1
                        : 0,

                'show_timezone' =>
                    $privacyValues['show_timezone']
                        ? 1
                        : 0,

                'show_join_date' =>
                    $privacyValues['show_join_date']
                        ? 1
                        : 0,

                'show_roles' =>
                    $privacyValues['show_roles']
                        ? 1
                        : 0,

                'show_house' =>
                    $privacyValues['show_house']
                        ? 1
                        : 0,

                'show_year_group' =>
                    $privacyValues['show_year_group']
                        ? 1
                        : 0,

                'show_activity' =>
                    $privacyValues['show_activity']
                        ? 1
                        : 0,

                'show_achievements' =>
                    $privacyValues['show_achievements']
                        ? 1
                        : 0,

                'show_online_status' =>
                    $privacyValues['show_online_status']
                        ? 1
                        : 0,

                'show_current_location' =>
                    $privacyValues['show_current_location']
                        ? 1
                        : 0,

                'show_last_seen' =>
                    $privacyValues['show_last_seen']
                        ? 1
                        : 0,
            ]);

            /*
             * Optional social profile links.
             */
            $profileSaveStage = 'replacing social links';
            $socialDeleteStatement =
                $pdo->prepare(
                    'DELETE FROM user_social_links
                     WHERE user_id = :user_id'
                );

            $socialDeleteStatement->execute([
                'user_id' => $userId,
            ]);

            if ($socialLinks !== []) {
                $socialInsertStatement =
                    $pdo->prepare(
                        'INSERT INTO user_social_links (
                            user_id,
                            platform,
                            profile_url,
                            sort_order
                         ) VALUES (
                            :user_id,
                            :platform,
                            :profile_url,
                            :sort_order
                         )'
                    );

                $socialSortOrder =
                    0;

                foreach (
                    $socialPlatforms
                    as $platformKey => $platformConfig
                ) {
                    if (
                        !isset(
                            $socialLinks[$platformKey]
                        )
                    ) {
                        continue;
                    }

                    $socialInsertStatement->execute([
                        'user_id' =>
                            $userId,

                        'platform' =>
                            $platformKey,

                        'profile_url' =>
                            $socialLinks[$platformKey],

                        'sort_order' =>
                            $socialSortOrder,
                    ]);

                    $socialSortOrder++;
                }
            }

            $profileSaveStage = 'committing database transaction';
            $pdo->commit();
            $profileSaveStage = 'post-commit image cleanup';


            /*
             * Only remove the previous local files after the database has
             * successfully switched to the replacement reference.
             */
            if (
                $oldAvatarReference !== null
                && $oldAvatarReference !== $newAvatarReference
            ) {
                profile_delete_local_image(
                    $oldAvatarReference,
                    $userId,
                    'avatar'
                );
            }

            if (
                $oldCoverReference !== null
                && $oldCoverReference !== $newCoverReference
            ) {
                profile_delete_local_image(
                    $oldCoverReference,
                    $userId,
                    'cover'
                );
            }

            set_flash(
                'success',
                'Your profile settings were saved.'
            );

            redirect(
                url(
                    'profile-edit.php'
                )
            );

        } catch (Throwable $exception) {

            if (
                $pdo->inTransaction()
            ) {
                $pdo->rollBack();
            }

            /*
             * Clean up only brand-new uploads created during this request.
             */
            if (
                $newAvatarUploadPath !== null
            ) {
                profile_delete_local_image(
                    $newAvatarUploadPath,
                    $userId,
                    'avatar'
                );
            }

            if (
                $newCoverUploadPath !== null
            ) {
                profile_delete_local_image(
                    $newCoverUploadPath,
                    $userId,
                    'cover'
                );
            }

            if (
                $newAvatarImportedPath !== null
            ) {
                profile_delete_local_image(
                    $newAvatarImportedPath,
                    $userId,
                    'avatar'
                );
            }

            if (
                $newCoverImportedPath !== null
            ) {
                profile_delete_local_image(
                    $newCoverImportedPath,
                    $userId,
                    'cover'
                );
            }
error_log(
                'Blackthorne profile save error at stage ['
                . ($profileSaveStage ?? 'unknown')
                . ']: '
                . $exception->getMessage()
            );

            $errors[] =
                'Your profile could not be saved. Please try again.';
        }
    }
}


/*
|--------------------------------------------------------------------------
| Refresh Preview Values After Successful/Failed POST State
|--------------------------------------------------------------------------
*/

$avatarPreview =
    profile_picture_sources(
        $currentAvatar
    );

$coverPreview =
    profile_picture_sources(
        $currentCover
    );


/*
|--------------------------------------------------------------------------
| Page Metadata
|--------------------------------------------------------------------------
*/

$pageTitle =
    'Edit Profile | Blackthorne Academy';

$pageDescription =
    'Manage your Blackthorne Academy profile, profile images, and privacy settings.';

$pageCanonical =
    url(
        'profile-edit.php'
    );

$robots =
    'noindex, nofollow';

require
    INCLUDES_PATH
    . '/header.php';

?>

<main id="main-content" class="profile-edit-page">

    <section class="profile-edit-heading" aria-labelledby="profile-edit-title">

        <div class="section-inner">

            <p class="academy-overline">
                Member Profile
            </p>

            <h1 id="profile-edit-title">
                Edit Your Profile
            </h1>

            <p>
                Choose how you appear around Blackthorne and what other
                Academy members may see.
            </p>

            <div class="profile-edit-top-actions">
                <a href="<?= e(url('profile.php?u=me')); ?>"
                    class="button profile-action-button profile-action-profile">
                    Back to Profile
                </a>
            </div>

        </div>

    </section>


    <section class="profile-edit-content">

        <div class="section-inner">

            <?php if (
                is_string($successMessage)
                && $successMessage !== ''
            ): ?>

            <div class="form-notice form-notice-success" role="status">
                <?= e($successMessage); ?>
            </div>

            <?php endif; ?>


            <?php if ($errors !== []): ?>

            <div class="form-notice form-notice-error" role="alert">

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


            <form method="post" enctype="multipart/form-data" class="profile-edit-form">

                <?= csrf_field(); ?>


                <!-- =====================================================
                     Identity
                ====================================================== -->

                <section class="profile-edit-panel" aria-labelledby="profile-identity-heading">

                    <header class="profile-edit-panel-heading">

                        <p class="academy-overline">
                            Identity
                        </p>

                        <h2 id="profile-identity-heading">
                            Profile Information
                        </h2>

                    </header>


                    <div class="profile-edit-fields">

                        <div class="form-group">

                            <label for="profile-display-name">
                                Display Name
                            </label>

                            <input class="form-control" type="text" id="profile-display-name" name="display_name"
                                value="<?= e($displayName); ?>" maxlength="100" required>

                            <p class="form-help">
                                This is the name other members will see around
                                the Academy.
                            </p>

                        </div>


                        <div class="form-group">

                            <label for="profile-username">
                                Username
                            </label>

                            <input class="form-control" type="text" id="profile-username" value="<?= e($username); ?>"
                                readonly aria-readonly="true">

                            <p class="form-help">
                                Username changes are not handled from the
                                profile editor.
                            </p>

                        </div>


                        <div class="form-group form-group-full profile-bio-editor-field">

                            <label id="profile-bio-label" for="profile-bio-editor">
                                Bio
                            </label>

                            <div class="forum-rich-editor profile-bio-editor" data-profile-bio-editor>

                                <div class="forum-rich-editor-toolbar" role="toolbar" aria-label="Bio formatting tools">

                                    <div class="forum-editor-tool-group">

                                        <button type="button" class="forum-editor-tool" data-profile-command="bold"
                                            title="Bold" aria-label="Bold">
                                            <strong>B</strong>
                                        </button>

                                        <button type="button" class="forum-editor-tool" data-profile-command="italic"
                                            title="Italic" aria-label="Italic">
                                            <em>I</em>
                                        </button>

                                        <button type="button" class="forum-editor-tool" data-profile-command="underline"
                                            title="Underline" aria-label="Underline">
                                            <u>U</u>
                                        </button>

                                        <button type="button" class="forum-editor-tool"
                                            data-profile-command="strikeThrough" title="Strikethrough"
                                            aria-label="Strikethrough">
                                            <s>S</s>
                                        </button>

                                    </div>


                                    <div class="forum-editor-tool-group">

                                        <select class="forum-editor-select" data-profile-format title="Text style"
                                            aria-label="Text style">
                                            <option value="p">Paragraph</option>
                                            <option value="h2">Heading 2</option>
                                            <option value="h3">Heading 3</option>
                                            <option value="h4">Heading 4</option>
                                            <option value="blockquote">Quote</option>
                                        </select>

                                        <select class="forum-editor-select" data-profile-size title="Font size"
                                            aria-label="Font size">
                                            <option value="2">Small</option>
                                            <option value="3" selected>Normal</option>
                                            <option value="4">Large</option>
                                            <option value="5">Larger</option>
                                            <option value="6">Very Large</option>
                                        </select>

                                    </div>


                                    <div class="forum-editor-tool-group forum-editor-color-tools">

                                        <label class="forum-editor-color-label" title="Text color">
                                            <span class="sr-only">
                                                Text color
                                            </span>

                                            <input type="color" value="#e8e1e6" data-profile-color
                                                aria-label="Text color">
                                        </label>

                                        <button type="button" class="forum-editor-tool" data-profile-apply-color
                                            title="Apply the current text color to the selected text">
                                            Apply Text
                                        </button>

                                        <label class="forum-editor-color-label" title="Highlight color">
                                            <span class="sr-only">
                                                Highlight color
                                            </span>

                                            <input type="color" value="#55336f" data-profile-highlight
                                                aria-label="Highlight color">
                                        </label>

                                        <button type="button" class="forum-editor-tool" data-profile-apply-highlight
                                            title="Apply the current highlight color to the selected text">
                                            Apply Highlight
                                        </button>

                                    </div>


                                    <div class="forum-editor-tool-group">

                                        <button type="button" class="forum-editor-tool"
                                            data-profile-command="insertUnorderedList" title="Bulleted list"
                                            aria-label="Bulleted list">
                                            • List
                                        </button>

                                        <button type="button" class="forum-editor-tool"
                                            data-profile-command="insertOrderedList" title="Numbered list"
                                            aria-label="Numbered list">
                                            1. List
                                        </button>

                                        <button type="button" class="forum-editor-tool" data-profile-quote
                                            title="Format selected text as a quote" aria-label="Quote selected text">
                                            Quote
                                        </button>

                                    </div>


                                    <div class="forum-editor-tool-group">

                                        <button type="button" class="forum-editor-tool"
                                            data-profile-command="justifyLeft" title="Align left"
                                            aria-label="Align left">
                                            Left
                                        </button>

                                        <button type="button" class="forum-editor-tool"
                                            data-profile-command="justifyCenter" title="Align center"
                                            aria-label="Align center">
                                            Center
                                        </button>

                                        <button type="button" class="forum-editor-tool"
                                            data-profile-command="justifyRight" title="Align right"
                                            aria-label="Align right">
                                            Right
                                        </button>

                                    </div>


                                    <div class="forum-editor-tool-group">

                                        <button type="button" class="forum-editor-tool" data-profile-link
                                            title="Insert link">
                                            Link
                                        </button>

                                        <select class="forum-editor-select" data-profile-image-size title="Image size">
                                            <option value="">Image Size</option>
                                            <option value="25%">25%</option>
                                            <option value="40%">40%</option>
                                            <option value="50%">50%</option>
                                            <option value="60%">60%</option>
                                            <option value="75%">75%</option>
                                            <option value="90%">90%</option>
                                            <option value="100%">100%</option>
                                        </select>

                                        <select class="forum-editor-select" data-profile-image-align
                                            title="Image alignment">
                                            <option value="">Image Align</option>
                                            <option value="left">Left</option>
                                            <option value="center">Center</option>
                                            <option value="right">Right</option>
                                        </select>

                                        <button type="button" class="forum-editor-tool" data-profile-image-upload
                                            title="Upload an image from your device">
                                            Upload Image
                                        </button>

                                        <input type="file" accept="image/jpeg,image/png,image/webp,image/avif"
                                            data-profile-image-input hidden>

                                        <button type="button" class="forum-editor-tool" data-profile-image-url
                                            title="Insert image from HTTPS URL">
                                            Image URL
                                        </button>

                                        <button type="button" class="forum-editor-tool" data-profile-command="unlink"
                                            title="Remove link">
                                            Unlink
                                        </button>

                                        <button type="button" class="forum-editor-tool"
                                            data-profile-command="removeFormat" title="Clear formatting">
                                            Clear
                                        </button>

                                    </div>

                                </div>


                                <div class="forum-rich-editor-surface profile-bio-editor-surface"
                                    id="profile-bio-editor" contenteditable="true" role="textbox" aria-multiline="true"
                                    aria-labelledby="profile-bio-label"
                                    data-placeholder="Tell the Academy a little about yourself." spellcheck="true">
                                    <?= $bioEditorHtml; ?></div>


                                <textarea class="forum-rich-editor-input" id="profile-bio" name="bio"
                                    hidden><?= e($bio); ?></textarea>

                                <div class="forum-editor-counts" aria-live="polite" aria-atomic="true">
                                    <span data-profile-word-count>0 words</span>
                                    <span aria-hidden="true">•</span>
                                    <span data-profile-character-count>0 / 5,000 characters</span>
                                </div>

                            </div>


                            <p class="form-help">
                                You can style your bio with headings, colors,
                                lists, alignment, links, and images. The
                                5,000-character limit applies to the visible
                                text, not the formatting code.
                            </p>

                        </div>


                        <div class="form-group">

                            <label for="profile-pronouns">
                                Pronouns
                            </label>

                            <input class="form-control" type="text" id="profile-pronouns" name="pronouns"
                                value="<?= e($pronouns); ?>" maxlength="50" placeholder="Optional">

                        </div>


                        <div class="form-group">

                            <label for="profile-gender">
                                Gender
                            </label>

                            <input class="form-control" type="text" id="profile-gender" name="gender"
                                value="<?= e($gender); ?>" maxlength="50" placeholder="Optional">

                            <p class="form-help">
                                Optional. Enter the gender description you want
                                shown on your profile.
                            </p>

                        </div>


                        <div class="form-group">

                            <label>
                                Registered Birthday
                            </label>

                            <div class="form-control profile-readonly-value" aria-readonly="true">
                                <?= e($registeredBirthdayLabel); ?>
                            </div>

                            <p class="form-help">
                                Your birthday comes from the date of birth used
                                when your Academy account was registered.
                            </p>

                        </div>


                        <div class="form-group">

                            <label>
                                Age
                            </label>

                            <div class="form-control profile-readonly-value" aria-readonly="true">
                                <?= $calculatedAge !== null
                                    ? e((string) $calculatedAge)
                                    : 'Unavailable'; ?>
                            </div>

                            <p class="form-help">
                                Age is calculated automatically from your
                                registered birthday and is always shown.
                            </p>

                        </div>


                        <div class="form-group">

                            <label for="profile-birthday-display">
                                Birthday Display
                            </label>

                            <select class="form-control" id="profile-birthday-display" name="birthday_display" required>
                                <option value="month_day" <?= $birthdayDisplay === 'month_day' ? 'selected' : ''; ?>>
                                    Month / Day
                                </option>

                                <option value="month_day_year"
                                    <?= $birthdayDisplay === 'month_day_year' ? 'selected' : ''; ?>>
                                    Month / Day / Year
                                </option>

                                <option value="month" <?= $birthdayDisplay === 'month' ? 'selected' : ''; ?>>
                                    Month Only
                                </option>
                            </select>

                            <p class="form-help">
                                Birthday is always shown, but you choose how much
                                of the registered date appears.
                            </p>

                        </div>


                        <div class="form-group">

                            <label for="profile-location">
                                Location
                            </label>

                            <input class="form-control" type="text" id="profile-location" name="location"
                                value="<?= e($location); ?>" maxlength="100" placeholder="Optional">

                            <p class="form-help">
                                Use as much or as little detail as you are
                                comfortable sharing.
                            </p>

                        </div>


                        <div class="form-group form-group-full">

                            <label for="profile-timezone">
                                Timezone
                            </label>

                            <select class="form-control" id="profile-timezone" name="timezone">

                                <option value="">
                                    Use Academy default
                                </option>

                                <?php foreach ($timezoneOptions as $timezoneOption): ?>

                                <option value="<?= e($timezoneOption); ?>"
                                    <?= $timezone === $timezoneOption ? 'selected' : ''; ?>>
                                    <?= e($timezoneOption); ?>
                                </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                    </div>

                </section>


                <!-- =====================================================
                     Avatar
                ====================================================== -->

                <section class="profile-edit-panel" aria-labelledby="profile-avatar-heading">

                    <header class="profile-edit-panel-heading">

                        <p class="academy-overline">
                            Portrait
                        </p>

                        <h2 id="profile-avatar-heading">
                            Avatar
                        </h2>

                    </header>


                    <div class="profile-image-editor">

                        <div class="profile-image-preview profile-avatar-preview">

                            <?php if ($avatarPreview['original'] !== null): ?>

                            <picture>

                                <?php if (
                                        $avatarPreview['webp'] !== null
                                        && $avatarPreview['webp'] !== $avatarPreview['original']
                                    ): ?>

                                <source srcset="<?= e($avatarPreview['webp']); ?>" type="image/webp">

                                <?php endif; ?>

                                <img src="<?= e($avatarPreview['original']); ?>" alt="Current profile avatar">

                            </picture>

                            <?php else: ?>

                            <span class="profile-avatar-fallback" aria-label="Current profile avatar">
                                <?= e(
                                        profile_avatar_initial(
                                            $displayName,
                                            $username
                                        )
                                    ); ?>
                            </span>

                            <?php endif; ?>

                        </div>


                        <div class="profile-image-controls">

                            <div class="form-group">

                                <label for="avatar-upload">
                                    Upload Avatar
                                </label>

                                <input class="form-control" type="file" id="avatar-upload" name="avatar_upload"
                                    accept="image/jpeg,image/png,image/webp,image/avif">

                                <p class="form-help">
                                    JPG, PNG, WebP, or AVIF. Maximum 5 MB.
                                </p>

                            </div>


                            <div class="profile-image-or">
                                or
                            </div>


                            <div class="form-group">

                                <label for="avatar-url">
                                    Avatar Image URL
                                </label>

                                <input class="form-control" type="url" id="avatar-url" name="avatar_url" maxlength="255"
                                    placeholder="https://example.com/avatar.jpg">

                                <p class="form-help">
                                    HTTPS links only. Blackthorne will import the image locally
                                    and create an optimized WebP copy.
                                </p>

                            </div>


                            <?php if ($currentAvatar !== null): ?>

                            <label class="profile-checkbox profile-remove-image">

                                <input type="checkbox" name="remove_avatar" value="1">

                                <span>
                                    Remove current avatar
                                </span>

                            </label>

                            <?php endif; ?>

                        </div>

                    </div>

                </section>


                <!-- =====================================================
                     Cover
                ====================================================== -->

                <section class="profile-edit-panel" aria-labelledby="profile-cover-heading">

                    <header class="profile-edit-panel-heading">

                        <p class="academy-overline">
                            Profile Hero
                        </p>

                        <h2 id="profile-cover-heading">
                            Cover Image
                        </h2>

                        <p>
                            Your cover image will become the background of the
                            hero at the top of your member profile.
                        </p>

                    </header>


                    <div class="profile-cover-preview">

                        <?php if ($coverPreview['original'] !== null): ?>

                        <picture>

                            <?php if (
                                    $coverPreview['webp'] !== null
                                    && $coverPreview['webp'] !== $coverPreview['original']
                                ): ?>

                            <source srcset="<?= e($coverPreview['webp']); ?>" type="image/webp">

                            <?php endif; ?>

                            <img src="<?= e($coverPreview['original']); ?>" alt="Current profile cover">

                        </picture>

                        <?php else: ?>

                        <div class="profile-cover-fallback">

                            <span>
                                Blackthorne Academy
                            </span>

                        </div>

                        <?php endif; ?>

                    </div>


                    <div class="profile-cover-controls">

                        <div class="form-group">

                            <label for="cover-upload">
                                Upload Cover Image
                            </label>

                            <input class="form-control" type="file" id="cover-upload" name="cover_upload"
                                accept="image/jpeg,image/png,image/webp,image/avif">

                            <p class="form-help">
                                JPG, PNG, WebP, or AVIF. Maximum 10 MB. Wide
                                landscape images will work best.
                            </p>

                        </div>


                        <div class="profile-image-or">
                            or
                        </div>


                        <div class="form-group">

                            <label for="cover-url">
                                Cover Image URL
                            </label>

                            <input class="form-control" type="url" id="cover-url" name="cover_url" maxlength="500"
                                placeholder="https://example.com/cover.jpg">

                            <p class="form-help">
                                HTTPS links only. Leave blank to keep your
                                current cover.
                            </p>

                        </div>


                        <?php if ($currentCover !== null): ?>

                        <label class="profile-checkbox profile-remove-image">

                            <input type="checkbox" name="remove_cover" value="1">

                            <span>
                                Remove current cover image
                            </span>

                        </label>

                        <?php endif; ?>

                    </div>

                </section>


                <!-- =====================================================
                     Social Links
                ====================================================== -->

                <section class="profile-edit-panel" aria-labelledby="profile-social-heading">

                    <header class="profile-edit-panel-heading">

                        <p class="academy-overline">
                            Connect
                        </p>

                        <h2 id="profile-social-heading">
                            Social Media & Website
                        </h2>

                        <p>
                            All social links are optional. Only links you add
                            will appear on your public profile.
                        </p>

                    </header>


                    <div class="profile-edit-grid profile-social-edit-grid">

                        <div class="form-group">
                            <label for="social-instagram">
                                Instagram
                            </label>

                            <input class="form-control" type="url" id="social-instagram" name="social_links[instagram]"
                                value="<?= e((string) ($socialLinks['instagram'] ?? '')); ?>" maxlength="500"
                                placeholder="https://instagram.com/username" inputmode="url">
                        </div>
                        <div class="form-group">
                            <label for="social-tiktok">
                                TikTok
                            </label>

                            <input class="form-control" type="url" id="social-tiktok" name="social_links[tiktok]"
                                value="<?= e((string) ($socialLinks['tiktok'] ?? '')); ?>" maxlength="500"
                                placeholder="https://www.tiktok.com/@username" inputmode="url">
                        </div>
                        <div class="form-group">
                            <label for="social-facebook">
                                Facebook
                            </label>

                            <input class="form-control" type="url" id="social-facebook" name="social_links[facebook]"
                                value="<?= e((string) ($socialLinks['facebook'] ?? '')); ?>" maxlength="500"
                                placeholder="https://facebook.com/username" inputmode="url">
                        </div>
                        <div class="form-group">
                            <label for="social-youtube">
                                YouTube
                            </label>

                            <input class="form-control" type="url" id="social-youtube" name="social_links[youtube]"
                                value="<?= e((string) ($socialLinks['youtube'] ?? '')); ?>" maxlength="500"
                                placeholder="https://youtube.com/@channel" inputmode="url">
                        </div>
                        <div class="form-group">
                            <label for="social-twitch">
                                Twitch
                            </label>

                            <input class="form-control" type="url" id="social-twitch" name="social_links[twitch]"
                                value="<?= e((string) ($socialLinks['twitch'] ?? '')); ?>" maxlength="500"
                                placeholder="https://twitch.tv/username" inputmode="url">
                        </div>
                        <div class="form-group">
                            <label for="social-x">
                                X
                            </label>

                            <input class="form-control" type="url" id="social-x" name="social_links[x]"
                                value="<?= e((string) ($socialLinks['x'] ?? '')); ?>" maxlength="500"
                                placeholder="https://x.com/username" inputmode="url">
                        </div>
                        <div class="form-group">
                            <label for="social-threads">
                                Threads
                            </label>

                            <input class="form-control" type="url" id="social-threads" name="social_links[threads]"
                                value="<?= e((string) ($socialLinks['threads'] ?? '')); ?>" maxlength="500"
                                placeholder="https://threads.net/@username" inputmode="url">
                        </div>
                        <div class="form-group">
                            <label for="social-bluesky">
                                Bluesky
                            </label>

                            <input class="form-control" type="url" id="social-bluesky" name="social_links[bluesky]"
                                value="<?= e((string) ($socialLinks['bluesky'] ?? '')); ?>" maxlength="500"
                                placeholder="https://bsky.app/profile/username.bsky.social" inputmode="url">
                        </div>
                        <div class="form-group">
                            <label for="social-pinterest">
                                Pinterest
                            </label>

                            <input class="form-control" type="url" id="social-pinterest" name="social_links[pinterest]"
                                value="<?= e((string) ($socialLinks['pinterest'] ?? '')); ?>" maxlength="500"
                                placeholder="https://pinterest.com/username" inputmode="url">
                        </div>
                        <div class="form-group">
                            <label for="social-reddit">
                                Reddit
                            </label>

                            <input class="form-control" type="url" id="social-reddit" name="social_links[reddit]"
                                value="<?= e((string) ($socialLinks['reddit'] ?? '')); ?>" maxlength="500"
                                placeholder="https://reddit.com/user/username" inputmode="url">
                        </div>
                        <div class="form-group">
                            <label for="social-tumblr">
                                Tumblr
                            </label>

                            <input class="form-control" type="url" id="social-tumblr" name="social_links[tumblr]"
                                value="<?= e((string) ($socialLinks['tumblr'] ?? '')); ?>" maxlength="500"
                                placeholder="https://username.tumblr.com" inputmode="url">
                        </div>
                        <div class="form-group">
                            <label for="social-discord">
                                Discord
                            </label>

                            <input class="form-control" type="url" id="social-discord" name="social_links[discord]"
                                value="<?= e((string) ($socialLinks['discord'] ?? '')); ?>" maxlength="500"
                                placeholder="https://discord.gg/invite" inputmode="url">
                        </div>
                        <div class="form-group">
                            <label for="social-website">
                                Website
                            </label>

                            <input class="form-control" type="url" id="social-website" name="social_links[website]"
                                value="<?= e((string) ($socialLinks['website'] ?? '')); ?>" maxlength="500"
                                placeholder="https://example.com" inputmode="url">
                        </div>

                    </div>

                </section>


                <!-- =====================================================
                     Personal Bookmarks
                ====================================================== -->

                <section class="profile-edit-panel" aria-labelledby="profile-bookmarks-heading">

                    <header class="profile-edit-panel-heading">

                        <p class="academy-overline">
                            Personal Shortcuts
                        </p>

                        <h2 id="profile-bookmarks-heading">
                            Bookmarks
                        </h2>

                        <p>
                            Save quick links to pages around Blackthorne Academy.
                            These are private shortcuts for your account. You may
                            save up to <?= (int) $profileBookmarkLimit; ?> bookmarks.
                        </p>

                    </header>


                    <p class="form-help">
                        <?= (int) $profileBookmarkCount; ?>
                        of
                        <?= (int) $profileBookmarkLimit; ?>
                        bookmarks saved.
                    </p>


                    <?php if ($profileBookmarkErrors !== []): ?>

                    <div class="form-notice form-notice-error" role="alert">

                        <p>
                            Please correct the following:
                        </p>

                        <ul>

                            <?php foreach ($profileBookmarkErrors as $bookmarkError): ?>

                            <li>
                                <?= e($bookmarkError); ?>
                            </li>

                            <?php endforeach; ?>

                        </ul>

                    </div>

                    <?php endif; ?>


                    <?php if ($profileBookmarkLimitReached && $bookmarkEditId <= 0): ?>

                    <div class="form-notice">

                        <p>
                            You have reached the
                            <?= (int) $profileBookmarkLimit; ?>-bookmark limit.
                            Delete an existing bookmark before adding another.
                        </p>

                    </div>

                    <?php else: ?>

                    <div class="profile-bookmark-form">

                        <div class="profile-edit-grid">

                            <div class="form-group">

                                <label for="profile-bookmark-title">
                                    Bookmark Title
                                </label>

                                <input class="form-control" type="text" id="profile-bookmark-title"
                                    name="bookmark_title" value="<?= e($bookmarkFormTitle); ?>" maxlength="150"
                                    placeholder="Example: Potions Discussion Board" form="profile-bookmark-action-form"
                                    required>

                            </div>


                            <div class="form-group">

                                <label for="profile-bookmark-sort-order">
                                    Sort Order
                                </label>

                                <input class="form-control" type="number" id="profile-bookmark-sort-order"
                                    name="bookmark_sort_order" value="<?= (int) $bookmarkFormSortOrder; ?>" min="0"
                                    step="1" form="profile-bookmark-action-form">

                                <p class="form-help">
                                    Lower numbers appear first.
                                </p>

                            </div>


                            <div class="form-group form-group-full">

                                <label for="profile-bookmark-url">
                                    Blackthorne URL
                                </label>

                                <input class="form-control" type="text" id="profile-bookmark-url" name="bookmark_url"
                                    value="<?= e($bookmarkFormUrl); ?>" maxlength="500"
                                    placeholder="/dashboard.php or https://blkthrnacad.com/dashboard.php"
                                    form="profile-bookmark-action-form" required>

                                <p class="form-help">
                                    Use a Blackthorne Academy page URL or a
                                    site-relative path beginning with /.
                                </p>

                            </div>

                        </div>


                        <div class="profile-edit-actions profile-bookmark-form-actions">

                            <button type="submit" class="button button-primary" form="profile-bookmark-action-form">
                                <?= $bookmarkEditId > 0
                                        ? 'Save Bookmark'
                                        : 'Add Bookmark'; ?>
                            </button>

                            <?php if ($bookmarkEditId > 0): ?>

                            <a href="<?= e(
                                            url(
                                                'profile-edit.php'
                                            )
                                        ); ?>#profile-bookmarks-heading" class="button button-secondary">
                                Cancel
                            </a>

                            <?php endif; ?>

                            <a href="<?= e(
                                        url(
                                            'bookmarks.php'
                                        )
                                    ); ?>" class="button button-secondary profile-bookmark-open-button">
                                Open Bookmarks Page
                            </a>

                        </div>

                    </div>

                    <?php endif; ?>


                    <div class="announcement-list profile-bookmark-list">

                        <?php if ($profileBookmarks === []): ?>

                        <div class="announcement-empty-state">

                            <p>
                                You have not saved any bookmarks yet.
                            </p>

                        </div>

                        <?php else: ?>

                        <?php foreach ($profileBookmarks as $profileBookmark): ?>

                        <?php
                                $profileBookmarkId =
                                    (int) (
                                        $profileBookmark['id']
                                        ?? 0
                                    );

                                $profileBookmarkTitle =
                                    trim(
                                        (string) (
                                            $profileBookmark['title']
                                            ?? 'Bookmark'
                                        )
                                    );

                                $profileBookmarkUrl =
                                    (string) (
                                        $profileBookmark['bookmark_url']
                                        ?? '/'
                                    );
                                ?>

                        <article class="announcement-entry profile-bookmark-entry">

                            <h3>
                                <a href="<?= e($profileBookmarkUrl); ?>">
                                    <?= e($profileBookmarkTitle); ?>
                                </a>
                            </h3>

                            <p class="announcement-byline">
                                <?= e($profileBookmarkUrl); ?>
                            </p>

                            <div class="announcement-actions profile-bookmark-item-actions">

                                <a href="<?= e(
                                                url(
                                                    'profile-edit.php?bookmark_edit='
                                                    . $profileBookmarkId
                                                )
                                            ); ?>#profile-bookmarks-heading"
                                    class="button button-secondary profile-bookmark-edit-button">
                                    Edit
                                </a>

                                <button type="submit" class="button button-secondary profile-bookmark-delete-button"
                                    form="profile-bookmark-delete-form" name="bookmark_id"
                                    value="<?= $profileBookmarkId; ?>"
                                    onclick="return confirm('Remove this bookmark?');">
                                    Delete
                                </button>

                            </div>

                        </article>

                        <?php endforeach; ?>

                        <?php endif; ?>

                    </div>

                </section>


                <!-- =====================================================
                     Privacy
                ====================================================== -->

                <section class="profile-edit-panel" aria-labelledby="profile-privacy-heading">

                    <header class="profile-edit-panel-heading">

                        <p class="academy-overline">
                            Privacy
                        </p>

                        <h2 id="profile-privacy-heading">
                            Profile Visibility
                        </h2>

                        <p>
                            These controls determine what other members may see
                            when they visit your profile.
                        </p>

                    </header>


                    <div class="form-group">

                        <label for="profile-visibility">
                            Who can view your profile?
                        </label>

                        <select class="form-control" id="profile-visibility" name="profile_visibility">

                            <option value="everyone" <?= $profileVisibility === 'everyone' ? 'selected' : ''; ?>>
                                Everyone
                            </option>

                            <option value="members" <?= $profileVisibility === 'members' ? 'selected' : ''; ?>>
                                Academy Members
                            </option>

                            <option value="staff_only" <?= $profileVisibility === 'staff_only' ? 'selected' : ''; ?>>
                                Staff Only
                            </option>

                        </select>

                        <p class="form-help">
                            Everyone includes visitors who are not logged in.
                            Academy Members requires a logged-in account. Staff
                            Only limits the profile to you and authorized Academy
                            staff.
                        </p>

                    </div>


                    <fieldset class="profile-privacy-options">

                        <legend>
                            Information shown on your profile
                        </legend>


                        <?php
                        $privacyLabels = [
                            'show_pronouns' =>
                                'Show my pronouns',

                            'show_location' =>
                                'Show my location',

                            'show_timezone' =>
                                'Show my timezone',

                            'show_join_date' =>
                                'Show when I joined Blackthorne',

                            'show_roles' =>
                                'Show my roles / groups',

                            'show_activity' =>
                                'Show my Academy activity',

                            'show_achievements' =>
                                'Show my achievements',

                            'show_online_status' =>
                                'Show when I am online',

                            'show_current_location' =>
                                'Show what part of the Academy I am browsing',

                            'show_last_seen' =>
                                'Show when I was last seen',
                        ];
                        ?>


                        <div class="profile-privacy-grid">

                            <?php foreach ($privacyLabels as $privacyKey => $privacyLabel): ?>

                            <label class="profile-checkbox">

                                <input type="checkbox" name="<?= e($privacyKey); ?>" value="1"
                                    <?= $privacyValues[$privacyKey] ? 'checked' : ''; ?>>

                                <span>
                                    <?= e($privacyLabel); ?>
                                </span>

                            </label>

                            <?php endforeach; ?>

                        </div>

                    </fieldset>


                    <aside class="profile-privacy-note">

                        <strong>
                            Presence privacy
                        </strong>

                        <p>
                            Online status, current Academy location, and last
                            seen are separate controls. For example, you may
                            show that you are online without showing which page
                            you are currently visiting.
                        </p>

                    </aside>

                </section>


                <!-- =====================================================
                     Save
                ====================================================== -->

                <div class="profile-edit-actions profile-page-actions">

                    <button type="submit" class="button profile-action-button profile-action-save">
                        Save Profile
                    </button>

                    <a href="<?= e(url('profile.php?u=me')); ?>"
                        class="button profile-action-button profile-action-profile">
                        Back to Profile
                    </a>

                    <a href="<?= e(DASHBOARD_URL); ?>" class="button profile-action-button profile-action-dashboard">
                        Back to Dashboard
                    </a>

                </div>

            </form>


            <form id="profile-bookmark-action-form" method="post" hidden>

                <?= csrf_field(); ?>

                <input type="hidden" name="action" value="<?= $bookmarkEditId > 0
                        ? 'update_bookmark'
                        : 'add_bookmark'; ?>">

                <?php if ($bookmarkEditId > 0): ?>

                <input type="hidden" name="bookmark_id" value="<?= (int) $bookmarkEditId; ?>">

                <?php endif; ?>

            </form>


            <form id="profile-bookmark-delete-form" method="post" hidden>

                <?= csrf_field(); ?>

                <input type="hidden" name="action" value="delete_bookmark">

            </form>


        </div>

    </section>

</main>

<script>
    (() => {
        'use strict';

        const form = document.querySelector('.profile-edit-form');
        const editor = document.getElementById('profile-bio-editor');
        const input = document.getElementById('profile-bio');
        const wordCount = document.querySelector('[data-profile-word-count]');
        const characterCount = document.querySelector('[data-profile-character-count]');
        const imageUploadButton = document.querySelector('[data-profile-image-upload]');
        const imageInput = document.querySelector('[data-profile-image-input]');
        const imageSizeSelect = document.querySelector('[data-profile-image-size]');
        const imageAlignSelect = document.querySelector('[data-profile-image-align]');
        const csrfInput = form?.querySelector('input[name="_csrf_token"]');

        if (!form || !editor || !input) {
            return;
        }

        let savedRange = null;
        let selectedImage = null;
        let savedInsertionContainer = null;
        let selectedCustomBlock = null;
        let selectedColumnLayout = null;
        let customBlockEditTarget = null;

        const rememberClickedContainer = (event) => {
            const target =
                event.target instanceof Element ?
                event.target :
                null;

            if (!target) {
                return;
            }

            const container =
                target.closest(
                    '.user-custom-column, ' +
                    '.user-custom-box, ' +
                    '.user-custom-banner, ' +
                    '.user-custom-panel'
                );

            const block =
                target.closest(
                    '.user-custom-box, ' +
                    '.user-custom-banner, ' +
                    '.user-custom-panel'
                );

            const columns =
                target.closest(
                    '.user-custom-columns'
                );

            if (
                container &&
                editor.contains(container)
            ) {
                savedInsertionContainer =
                    container;
            } else if (
                target === editor ||
                editor.contains(target)
            ) {
                savedInsertionContainer =
                    null;
            }

            selectedCustomBlock =
                block &&
                editor.contains(block) ?
                block :
                null;

            selectedColumnLayout =
                columns &&
                editor.contains(columns) ?
                columns :
                null;
        };

        editor.addEventListener(
            'pointerdown',
            (event) => {
                rememberClickedContainer(
                    event
                );

                window.setTimeout(
                    updateBlockActionButtons,
                    0
                );
            }
        );

        editor.addEventListener(
            'click',
            (event) => {
                rememberClickedContainer(
                    event
                );

                updateBlockActionButtons();
            }
        );

        try {
            document.execCommand('styleWithCSS', false, true);
        } catch (error) {
            // Formatting still works in browsers that ignore styleWithCSS.
        }

        const saveSelection = () => {
            const selection = window.getSelection();

            if (!selection || selection.rangeCount === 0) {
                return;
            }

            const range = selection.getRangeAt(0);

            if (!editor.contains(range.commonAncestorContainer)) {
                return;
            }

            savedRange = range.cloneRange();

            let node = range.commonAncestorContainer;

            if (node.nodeType === Node.TEXT_NODE) {
                node = node.parentElement;
            }

            if (node instanceof Element) {
                const container = node.closest(
                    '.user-custom-column, ' +
                    '.user-custom-box, ' +
                    '.user-custom-banner, ' +
                    '.user-custom-panel'
                );

                const block = node.closest(
                    '.user-custom-box, ' +
                    '.user-custom-banner, ' +
                    '.user-custom-panel'
                );

                const columns = node.closest(
                    '.user-custom-columns'
                );

                if (
                    container &&
                    editor.contains(container)
                ) {
                    savedInsertionContainer = container;
                } else {
                    savedInsertionContainer = null;
                }

                selectedCustomBlock =
                    block &&
                    editor.contains(block) ?
                    block :
                    null;

                selectedColumnLayout =
                    columns &&
                    editor.contains(columns) ?
                    columns :
                    null;
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

        const updateCounts = () => {
            const visibleText =
                (editor.innerText || '')
                .replace(/\u00a0/g, ' ')
                .trim();

            const words =
                visibleText === '' ?
                0 :
                visibleText
                .split(/\s+/)
                .filter(Boolean)
                .length;

            const characters =
                visibleText.length;

            if (wordCount) {
                wordCount.textContent =
                    `${words} ${words === 1 ? 'word' : 'words'}`;
            }

            if (characterCount) {
                characterCount.textContent =
                    `${characters.toLocaleString()} / 5,000 characters`;
            }
        };

        const syncEditor = () => {
            input.value = editor.innerHTML.trim();
            updateCounts();
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

            document.execCommand(
                command,
                false,
                value
            );

            saveSelection();
            syncEditor();
        };

        document
            .querySelectorAll('[data-profile-command]')
            .forEach((button) => {
                button.addEventListener(
                    'mousedown',
                    (event) => {
                        event.preventDefault();
                    }
                );

                button.addEventListener(
                    'click',
                    () => {
                        runCommand(
                            button.dataset.profileCommand || ''
                        );
                    }
                );
            });

        const formatSelect =
            document.querySelector(
                '[data-profile-format]'
            );

        if (formatSelect) {
            formatSelect.addEventListener(
                'change',
                () => {
                    runCommand(
                        'formatBlock',
                        formatSelect.value
                    );

                    formatSelect.value =
                        'p';
                }
            );
        }

        const sizeSelect =
            document.querySelector(
                '[data-profile-size]'
            );

        if (sizeSelect) {
            sizeSelect.addEventListener(
                'change',
                () => {
                    runCommand(
                        'fontSize',
                        sizeSelect.value
                    );

                    sizeSelect.value =
                        '3';
                }
            );
        }

        const colorInput =
            document.querySelector(
                '[data-profile-color]'
            );

        const applyColorButton =
            document.querySelector(
                '[data-profile-apply-color]'
            );

        const applyHighlightButton =
            document.querySelector(
                '[data-profile-apply-highlight]'
            );

        const applyProfileTextColor = () => {
            if (!savedRange || savedRange.collapsed) {
                return;
            }

            runCommand(
                'foreColor',
                colorInput.value
            );
        };

        if (colorInput) {
            colorInput.addEventListener(
                'pointerdown',
                saveSelection
            );

            colorInput.addEventListener(
                'click',
                applyProfileTextColor
            );

            colorInput.addEventListener(
                'input',
                applyProfileTextColor
            );

            colorInput.addEventListener(
                'change',
                applyProfileTextColor
            );
        }

        const highlightInput =
            document.querySelector(
                '[data-profile-highlight]'
            );

        const applyProfileHighlightColor = () => {
            if (!savedRange || savedRange.collapsed) {
                return;
            }

            restoreSelection();

            document.execCommand(
                document.queryCommandSupported(
                    'hiliteColor'
                ) ?
                'hiliteColor' :
                'backColor',
                false,
                highlightInput.value
            );

            syncInput();
            saveSelection();
        };

        if (highlightInput) {
            highlightInput.addEventListener(
                'pointerdown',
                saveSelection
            );

            highlightInput.addEventListener(
                'click',
                applyProfileHighlightColor
            );

            highlightInput.addEventListener(
                'input',
                applyProfileHighlightColor
            );

            highlightInput.addEventListener(
                'change',
                applyProfileHighlightColor
            );
        }

        if (applyColorButton) {
            applyColorButton.addEventListener(
                'mousedown',
                (event) => {
                    event.preventDefault();
                }
            );

            applyColorButton.addEventListener(
                'click',
                applyProfileTextColor
            );
        }

        if (applyHighlightButton) {
            applyHighlightButton.addEventListener(
                'mousedown',
                (event) => {
                    event.preventDefault();
                }
            );

            applyHighlightButton.addEventListener(
                'click',
                applyProfileHighlightColor
            );
        }

        const quoteButton =
            document.querySelector(
                '[data-profile-quote]'
            );

        if (quoteButton) {
            quoteButton.addEventListener(
                'mousedown',
                (event) => {
                    event.preventDefault();
                    saveSelection();
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

        const linkButton =
            document.querySelector(
                '[data-profile-link]'
            );

        if (linkButton) {
            linkButton.addEventListener(
                'mousedown',
                (event) => {
                    event.preventDefault();
                }
            );

            linkButton.addEventListener(
                'click',
                () => {
                    const href =
                        window.prompt(
                            'Enter the link URL:'
                        );

                    if (!href) {
                        return;
                    }

                    const trimmed =
                        href.trim();

                    const isAllowed =
                        /^(https?:\/\/|mailto:|\/|#)/i
                        .test(
                            trimmed
                        );

                    if (!isAllowed) {
                        window.alert(
                            'Use a full http:// or https:// URL, a mailto: link, or a site-relative link beginning with /.'
                        );

                        return;
                    }

                    runCommand(
                        'createLink',
                        trimmed
                    );
                }
            );
        }

        const insertUploadedProfileImage = (
            imageUrl,
            altText
        ) => {
            const image =
                document.createElement('img');

            image.src = imageUrl;
            image.alt = altText;
            image.loading = 'lazy';
            image.style.display = 'block';
            image.style.marginLeft = 'auto';
            image.style.marginRight = 'auto';
            image.style.maxWidth = '100%';
            image.style.height = 'auto';

            restoreSelection();

            const selection =
                window.getSelection();

            if (
                selection &&
                selection.rangeCount > 0
            ) {
                const range =
                    selection.getRangeAt(0);

                if (
                    editor.contains(
                        range.commonAncestorContainer
                    )
                ) {
                    range.deleteContents();
                    range.insertNode(image);

                    const afterRange =
                        document.createRange();

                    afterRange.setStartAfter(image);
                    afterRange.collapse(true);

                    selection.removeAllRanges();
                    selection.addRange(afterRange);

                    savedRange =
                        afterRange.cloneRange();

                    syncEditor();
                    return image;
                }
            }

            editor.appendChild(image);

            const fallbackRange =
                document.createRange();

            fallbackRange.setStartAfter(image);
            fallbackRange.collapse(true);

            if (selection) {
                selection.removeAllRanges();
                selection.addRange(fallbackRange);
            }

            savedRange =
                fallbackRange.cloneRange();

            syncEditor();
            return image;
        };

        if (
            imageUploadButton &&
            imageInput
        ) {
            imageUploadButton.addEventListener(
                'mousedown',
                (event) => {
                    event.preventDefault();
                    saveSelection();
                }
            );

            imageUploadButton.addEventListener(
                'click',
                () => {
                    saveSelection();
                    imageInput.click();
                }
            );

            imageInput.addEventListener(
                'change',
                async () => {
                    const file =
                        imageInput.files?.[0];

                    if (!file) {
                        return;
                    }

                    const altText =
                        window.prompt(
                            'Enter alt text for the image (optional):',
                            ''
                        );

                    const previewUrl =
                        URL.createObjectURL(
                            file
                        );

                    const previewImage =
                        insertUploadedProfileImage(
                            previewUrl,
                            altText === null ?
                            '' :
                            altText.trim()
                        );

                    previewImage.setAttribute(
                        'data-profile-upload-pending',
                        '1'
                    );

                    selectedImage =
                        previewImage;

                    const formData =
                        new FormData();

                    formData.append(
                        '_csrf_token',
                        csrfInput?.value || ''
                    );

                    formData.append(
                        'image',
                        file
                    );

                    imageUploadButton.disabled = true;
                    imageUploadButton.textContent = 'Uploading...';

                    try {
                        const response =
                            await fetch(
                                'profile-bio-image-upload.php', {
                                    method: 'POST',
                                    body: formData,
                                    credentials: 'same-origin',
                                }
                            );

                        const data =
                            await response.json();

                        if (
                            !response.ok ||
                            !data.success ||
                            !data.url
                        ) {
                            throw new Error(
                                data.message ||
                                'The image could not be uploaded.'
                            );
                        }

                        previewImage.src =
                            data.url;

                        previewImage.removeAttribute(
                            'data-profile-upload-pending'
                        );

                        syncEditor();

                    } catch (error) {
                        previewImage.remove();

                        if (
                            selectedImage ===
                            previewImage
                        ) {
                            selectedImage = null;
                        }

                        syncEditor();

                        window.alert(
                            error instanceof Error ?
                            error.message :
                            'The image could not be uploaded.'
                        );

                    } finally {
                        URL.revokeObjectURL(
                            previewUrl
                        );

                        imageUploadButton.disabled = false;
                        imageUploadButton.textContent = 'Upload Image';
                        imageInput.value = '';
                    }
                }
            );
        }

        const imageUrlButton =
            document.querySelector(
                '[data-profile-image-url]'
            );

        const insertProfileImageAtSavedPosition =
            (
                imageUrl,
                altText
            ) => {
                const image =
                    document.createElement(
                        'img'
                    );

                image.setAttribute(
                    'src',
                    imageUrl
                );

                image.setAttribute(
                    'alt',
                    altText
                );

                image.setAttribute(
                    'loading',
                    'lazy'
                );

                /*
                 * If the member was working inside a custom block/column,
                 * prefer that exact container. Native prompts can destroy a
                 * contenteditable caret, while the stored container remains
                 * reliable.
                 */
                if (
                    savedInsertionContainer &&
                    editor.contains(
                        savedInsertionContainer
                    )
                ) {
                    savedInsertionContainer.appendChild(
                        image
                    );

                    const range =
                        document.createRange();

                    range.setStartAfter(
                        image
                    );

                    range.collapse(
                        true
                    );

                    const selection =
                        window.getSelection();

                    if (selection) {
                        selection.removeAllRanges();
                        selection.addRange(
                            range
                        );
                    }

                    savedRange =
                        range.cloneRange();

                    syncEditor();

                    return;
                }

                restoreSelection();

                const selection =
                    window.getSelection();

                if (
                    selection &&
                    selection.rangeCount > 0
                ) {
                    const range =
                        selection.getRangeAt(0);

                    if (
                        editor.contains(
                            range.commonAncestorContainer
                        )
                    ) {
                        range.deleteContents();
                        range.insertNode(
                            image
                        );

                        const afterRange =
                            document.createRange();

                        afterRange.setStartAfter(
                            image
                        );

                        afterRange.collapse(
                            true
                        );

                        selection.removeAllRanges();
                        selection.addRange(
                            afterRange
                        );

                        savedRange =
                            afterRange.cloneRange();

                        syncEditor();

                        return;
                    }
                }

                /*
                 * Final fallback: append to the end of the bio instead of
                 * silently failing when the browser no longer has a valid
                 * editor selection.
                 */
                editor.appendChild(
                    image
                );

                const fallbackRange =
                    document.createRange();

                fallbackRange.setStartAfter(
                    image
                );

                fallbackRange.collapse(
                    true
                );

                if (selection) {
                    selection.removeAllRanges();
                    selection.addRange(
                        fallbackRange
                    );
                }

                savedRange =
                    fallbackRange.cloneRange();

                syncEditor();
            };

        if (imageUrlButton) {
            imageUrlButton.addEventListener(
                'mousedown',
                (event) => {
                    event.preventDefault();
                    saveSelection();
                }
            );

            imageUrlButton.addEventListener(
                'click',
                () => {
                    const enteredUrl =
                        window.prompt(
                            'Enter a direct HTTPS image URL:'
                        );

                    if (!enteredUrl) {
                        return;
                    }

                    const trimmedUrl =
                        enteredUrl.trim();

                    let parsedUrl = null;

                    try {
                        parsedUrl =
                            new URL(
                                trimmedUrl
                            );
                    } catch (error) {
                        window.alert(
                            'That is not a valid URL.'
                        );

                        return;
                    }

                    if (
                        parsedUrl.protocol !== 'https:'
                    ) {
                        window.alert(
                            'Profile bio images must use HTTPS.'
                        );

                        return;
                    }

                    const altTextPrompt =
                        window.prompt(
                            'Enter alt text for the image (optional):',
                            ''
                        );

                    const altText =
                        altTextPrompt === null ?
                        '' :
                        altTextPrompt.trim();

                    /*
                     * Verify the address is actually loadable as an image
                     * before placing it in the editor. This catches normal
                     * webpage URLs and hosts that block external image use.
                     */
                    const testImage =
                        new Image();

                    let completed = false;

                    const finishWithError =
                        () => {
                            if (completed) {
                                return;
                            }

                            completed = true;

                            window.alert(
                                'Blackthorne could not load an image from that URL. ' +
                                'Use a direct HTTPS image address, not a webpage URL.'
                            );
                        };

                    testImage.onload =
                        () => {
                            if (completed) {
                                return;
                            }

                            completed = true;

                            insertProfileImageAtSavedPosition(
                                trimmedUrl,
                                altText
                            );
                        };

                    testImage.onerror =
                        finishWithError;

                    testImage.src =
                        trimmedUrl;

                    /*
                     * Some blocked image hosts never reliably fire an error.
                     * Give them a reasonable timeout instead of leaving the
                     * member wondering whether the button worked.
                     */
                    window.setTimeout(
                        finishWithError,
                        10000
                    );
                }
            );
        }

        const customBlockButton =
            document.querySelector(
                '[data-profile-custom-block]'
            );

        const customBlockDialog =
            document.querySelector(
                '[data-profile-custom-block-dialog]'
            );

        const customPreview =
            document.querySelector(
                '[data-custom-preview]'
            );

        const customType =
            document.querySelector(
                '[data-custom-block-type]'
            );

        const customBg =
            document.querySelector(
                '[data-custom-bg]'
            );

        const customText =
            document.querySelector(
                '[data-custom-text]'
            );

        const customBorder =
            document.querySelector(
                '[data-custom-border]'
            );

        const customBorderStyle =
            document.querySelector(
                '[data-custom-border-style]'
            );

        const customBorderWidth =
            document.querySelector(
                '[data-custom-border-width]'
            );

        const customRadius =
            document.querySelector(
                '[data-custom-radius]'
            );

        const customPadding =
            document.querySelector(
                '[data-custom-padding]'
            );

        const customWidth =
            document.querySelector(
                '[data-custom-width]'
            );

        const customAlign =
            document.querySelector(
                '[data-custom-align]'
            );

        const customInsert =
            document.querySelector(
                '[data-custom-insert]'
            );

        const customCancel =
            document.querySelector(
                '[data-custom-cancel]'
            );

        const customDialogTitle =
            document.querySelector(
                '[data-custom-block-dialog-title]'
            );

        const customDialogDescription =
            document.querySelector(
                '[data-custom-block-dialog-description]'
            );

        const unwrapBlockButton =
            document.querySelector(
                '[data-profile-unwrap-block]'
            );

        const deleteBlockButton =
            document.querySelector(
                '[data-profile-delete-block]'
            );

        const selectedEditableWrapper = () => {
            if (
                selectedCustomBlock &&
                editor.contains(
                    selectedCustomBlock
                )
            ) {
                return selectedCustomBlock;
            }

            if (
                selectedColumnLayout &&
                editor.contains(
                    selectedColumnLayout
                )
            ) {
                return selectedColumnLayout;
            }

            return null;
        };

        const updateBlockActionButtons = () => {
            const hasSelection =
                selectedEditableWrapper() !==
                null;

            if (unwrapBlockButton) {
                unwrapBlockButton.disabled = !hasSelection;
            }

            if (deleteBlockButton) {
                deleteBlockButton.disabled = !hasSelection;
            }
        };

        updateBlockActionButtons();

        const clearSelectedCustomWrappers = () => {
            selectedCustomBlock =
                null;

            selectedColumnLayout =
                null;

            savedInsertionContainer =
                null;

            customBlockEditTarget =
                null;

            updateBlockActionButtons();
        };

        const placeCaretAfterNode = (node) => {
            if (!node || !node.parentNode) {
                return;
            }

            const range =
                document.createRange();

            range.setStartAfter(
                node
            );

            range.collapse(
                true
            );

            const selection =
                window.getSelection();

            if (selection) {
                selection.removeAllRanges();
                selection.addRange(
                    range
                );
            }

            savedRange =
                range.cloneRange();
        };

        const unwrapSelectedWrapper = () => {
            const wrapper =
                selectedEditableWrapper();

            if (
                !wrapper ||
                !wrapper.parentNode
            ) {
                updateBlockActionButtons();
                return;
            }

            const parent =
                wrapper.parentNode;

            const marker =
                document.createElement(
                    'span'
                );

            marker.hidden =
                true;

            parent.insertBefore(
                marker,
                wrapper
            );

            if (
                wrapper.classList.contains(
                    'user-custom-columns'
                )
            ) {
                const columns =
                    Array.from(
                        wrapper.children
                    ).filter(
                        (child) =>
                        child.classList
                        ?.contains(
                            'user-custom-column'
                        )
                    );

                columns.forEach(
                    (column, index) => {
                        while (
                            column.firstChild
                        ) {
                            parent.insertBefore(
                                column.firstChild,
                                wrapper
                            );
                        }

                        if (
                            index <
                            columns.length - 1
                        ) {
                            const spacer =
                                document.createElement(
                                    'p'
                                );

                            spacer.innerHTML =
                                '<br>';

                            parent.insertBefore(
                                spacer,
                                wrapper
                            );
                        }
                    }
                );
            } else {
                while (
                    wrapper.firstChild
                ) {
                    parent.insertBefore(
                        wrapper.firstChild,
                        wrapper
                    );
                }
            }

            wrapper.remove();

            const caretAnchor =
                marker.nextSibling ||
                marker.previousSibling ||
                parent;

            marker.remove();

            clearSelectedCustomWrappers();

            if (
                caretAnchor &&
                caretAnchor !== parent
            ) {
                placeCaretAfterNode(
                    caretAnchor
                );
            }

            syncEditor();
        };

        const deleteSelectedWrapper = () => {
            const wrapper =
                selectedEditableWrapper();

            if (
                !wrapper ||
                !wrapper.parentNode
            ) {
                updateBlockActionButtons();
                return;
            }

            const label =
                wrapper.classList.contains(
                    'user-custom-columns'
                ) ?
                'column layout' :
                'custom block';

            if (
                !window.confirm(
                    'Delete this ' +
                    label +
                    ' and all content inside it?'
                )
            ) {
                return;
            }

            const parent =
                wrapper.parentNode;

            const marker =
                document.createElement(
                    'span'
                );

            marker.hidden =
                true;

            parent.insertBefore(
                marker,
                wrapper
            );

            wrapper.remove();

            const spacer =
                document.createElement(
                    'p'
                );

            spacer.innerHTML =
                '<br>';

            marker.replaceWith(
                spacer
            );

            clearSelectedCustomWrappers();

            placeCaretAfterNode(
                spacer
            );

            syncEditor();
        };

        const setSelectValueIfAvailable = (
            control,
            value,
            fallback
        ) => {
            if (!control) {
                return;
            }

            const hasValue =
                Array.from(control.options)
                .some(
                    (option) =>
                    option.value === value
                );

            control.value =
                hasValue ?
                value :
                fallback;
        };

        const blockTypeFromElement = (block) => {
            if (
                block.classList.contains(
                    'user-custom-banner'
                )
            ) {
                return 'user-custom-banner';
            }

            if (
                block.classList.contains(
                    'user-custom-panel'
                )
            ) {
                return 'user-custom-panel';
            }

            return 'user-custom-box';
        };

        const loadCustomBlockControls = (block = null) => {
            customBlockEditTarget =
                block &&
                editor.contains(block) ?
                block :
                null;

            if (!customBlockEditTarget) {
                if (customType) {
                    customType.value =
                        'user-custom-box';
                }

                if (customBg) {
                    customBg.value =
                        '#1c1023';
                }

                if (customText) {
                    customText.value =
                        '#eee4ed';
                }

                if (customBorder) {
                    customBorder.value =
                        '#8b6b32';
                }

                setSelectValueIfAvailable(
                    customBorderStyle,
                    'solid',
                    'solid'
                );

                setSelectValueIfAvailable(
                    customBorderWidth,
                    '2px',
                    '2px'
                );

                setSelectValueIfAvailable(
                    customRadius,
                    '14px',
                    '14px'
                );

                setSelectValueIfAvailable(
                    customPadding,
                    '22px',
                    '22px'
                );

                setSelectValueIfAvailable(
                    customWidth,
                    '100%',
                    '100%'
                );

                setSelectValueIfAvailable(
                    customAlign,
                    'left',
                    'left'
                );

                if (customDialogTitle) {
                    customDialogTitle.textContent =
                        'Insert Styled Block';
                }

                if (customDialogDescription) {
                    customDialogDescription.textContent =
                        'Create a decorative section for your bio. Styling stays inside the block and is sanitized before saving.';
                }

                if (customInsert) {
                    customInsert.textContent =
                        'Insert Block';
                }

                updateCustomPreview();

                return;
            }

            const style =
                customBlockEditTarget.style;

            if (customType) {
                customType.value =
                    blockTypeFromElement(
                        customBlockEditTarget
                    );
            }

            if (
                customBg &&
                style.backgroundColor
            ) {
                const temp =
                    document.createElement(
                        'div'
                    );

                temp.style.color =
                    style.backgroundColor;

                document.body.appendChild(
                    temp
                );

                const computed =
                    getComputedStyle(temp).color;

                temp.remove();

                const match =
                    computed.match(
                        /rgba?\((\d+),\s*(\d+),\s*(\d+)/
                    );

                if (match) {
                    customBg.value =
                        '#' + [match[1], match[2], match[3]]
                        .map(
                            (part) =>
                            Number(part)
                            .toString(16)
                            .padStart(2, '0')
                        )
                        .join('');
                }
            }

            if (
                customText &&
                style.color
            ) {
                const temp =
                    document.createElement(
                        'div'
                    );

                temp.style.color =
                    style.color;

                document.body.appendChild(
                    temp
                );

                const computed =
                    getComputedStyle(temp).color;

                temp.remove();

                const match =
                    computed.match(
                        /rgba?\((\d+),\s*(\d+),\s*(\d+)/
                    );

                if (match) {
                    customText.value =
                        '#' + [match[1], match[2], match[3]]
                        .map(
                            (part) =>
                            Number(part)
                            .toString(16)
                            .padStart(2, '0')
                        )
                        .join('');
                }
            }

            if (
                customBorder &&
                style.borderColor
            ) {
                const temp =
                    document.createElement(
                        'div'
                    );

                temp.style.color =
                    style.borderColor;

                document.body.appendChild(
                    temp
                );

                const computed =
                    getComputedStyle(temp).color;

                temp.remove();

                const match =
                    computed.match(
                        /rgba?\((\d+),\s*(\d+),\s*(\d+)/
                    );

                if (match) {
                    customBorder.value =
                        '#' + [match[1], match[2], match[3]]
                        .map(
                            (part) =>
                            Number(part)
                            .toString(16)
                            .padStart(2, '0')
                        )
                        .join('');
                }
            }

            setSelectValueIfAvailable(
                customBorderStyle,
                style.borderStyle || 'solid',
                'solid'
            );

            setSelectValueIfAvailable(
                customBorderWidth,
                style.borderWidth || '2px',
                '2px'
            );

            setSelectValueIfAvailable(
                customRadius,
                style.borderRadius || '14px',
                '14px'
            );

            setSelectValueIfAvailable(
                customPadding,
                style.padding || '22px',
                '22px'
            );

            setSelectValueIfAvailable(
                customWidth,
                style.width || '100%',
                '100%'
            );

            setSelectValueIfAvailable(
                customAlign,
                style.textAlign || 'left',
                'left'
            );

            if (customDialogTitle) {
                customDialogTitle.textContent =
                    'Edit Styled Block';
            }

            if (customDialogDescription) {
                customDialogDescription.textContent =
                    'Update this block’s appearance without replacing or deleting its existing content.';
            }

            if (customInsert) {
                customInsert.textContent =
                    'Save Changes';
            }

            updateCustomPreview();
        };

        const buildCustomStyle = () => {
            const declarations = [
                `background-color: ${customBg?.value || '#1c1023'}`,
                `color: ${customText?.value || '#eee4ed'}`,
                `border-color: ${customBorder?.value || '#8b6b32'}`,
                `border-style: ${customBorderStyle?.value || 'solid'}`,
                `border-width: ${customBorderWidth?.value || '2px'}`,
                `border-radius: ${customRadius?.value || '14px'}`,
                `padding: ${customPadding?.value || '22px'}`,
                `width: ${customWidth?.value || '100%'}`,
                `max-width: 100%`,
                `text-align: ${customAlign?.value || 'left'}`
            ];

            if (
                customWidth &&
                customWidth.value !== '100%'
            ) {
                declarations.push(
                    'margin: 0 auto'
                );
            }

            return declarations.join('; ');
        };

        const updateCustomPreview = () => {
            if (!customPreview) {
                return;
            }

            customPreview.className =
                'profile-custom-block-preview ' +
                (
                    customType?.value ||
                    'user-custom-box'
                );

            customPreview.setAttribute(
                'style',
                buildCustomStyle()
            );
        };

        [
            customType,
            customBg,
            customText,
            customBorder,
            customBorderStyle,
            customBorderWidth,
            customRadius,
            customPadding,
            customWidth,
            customAlign
        ].forEach((control) => {
            if (!control) {
                return;
            }

            control.addEventListener(
                'input',
                updateCustomPreview
            );

            control.addEventListener(
                'change',
                updateCustomPreview
            );
        });

        const columnLayoutSelect =
            document.querySelector(
                '[data-profile-column-layout]'
            );

        const allowedColumnLayouts = {
            '2:50-50': {
                count: 2,
                className: 'user-columns-50-50'
            },
            '2:60-40': {
                count: 2,
                className: 'user-columns-60-40'
            },
            '2:40-60': {
                count: 2,
                className: 'user-columns-40-60'
            },
            '2:70-30': {
                count: 2,
                className: 'user-columns-70-30'
            },
            '2:30-70': {
                count: 2,
                className: 'user-columns-30-70'
            },
            '2:75-25': {
                count: 2,
                className: 'user-columns-75-25'
            },
            '2:25-75': {
                count: 2,
                className: 'user-columns-25-75'
            },
            '2:80-20': {
                count: 2,
                className: 'user-columns-80-20'
            },
            '2:20-80': {
                count: 2,
                className: 'user-columns-20-80'
            },
            '3:33-33-33': {
                count: 3,
                className: 'user-columns-33-33-33'
            },
            '3:25-50-25': {
                count: 3,
                className: 'user-columns-25-50-25'
            },
            '3:20-60-20': {
                count: 3,
                className: 'user-columns-20-60-20'
            },
            '3:40-30-30': {
                count: 3,
                className: 'user-columns-40-30-30'
            },
            '3:30-40-30': {
                count: 3,
                className: 'user-columns-30-40-30'
            },
            '3:30-30-40': {
                count: 3,
                className: 'user-columns-30-30-40'
            }
        };

        const getColumnLayoutClass = (wrapper) => {
            if (!wrapper) {
                return '';
            }

            for (
                const layout of Object.values(
                    allowedColumnLayouts
                )
            ) {
                if (
                    wrapper.classList.contains(
                        layout.className
                    )
                ) {
                    return layout.className;
                }
            }

            return '';
        };

        const updateExistingColumnLayout = (
            wrapper,
            layoutKey
        ) => {
            const layout =
                allowedColumnLayouts[
                    layoutKey
                ];

            if (
                !layout ||
                !wrapper ||
                !editor.contains(wrapper)
            ) {
                return false;
            }

            Object.values(
                allowedColumnLayouts
            ).forEach(
                (layoutOption) => {
                    wrapper.classList.remove(
                        layoutOption.className
                    );
                }
            );

            wrapper.classList.add(
                'user-custom-columns',
                layout.className
            );

            let columns =
                Array.from(
                    wrapper.children
                ).filter(
                    (child) =>
                    child.classList
                    ?.contains(
                        'user-custom-column'
                    )
                );

            while (
                columns.length <
                layout.count
            ) {
                const column =
                    document.createElement(
                        'div'
                    );

                column.className =
                    'user-custom-column';

                column.innerHTML =
                    '<p>Column ' +
                    (columns.length + 1) +
                    ' content.</p>';

                wrapper.appendChild(
                    column
                );

                columns.push(
                    column
                );
            }

            while (
                columns.length >
                layout.count
            ) {
                const removedColumn =
                    columns.pop();

                const destination =
                    columns[
                        columns.length - 1
                    ];

                if (
                    removedColumn &&
                    destination
                ) {
                    while (
                        removedColumn.firstChild
                    ) {
                        destination.appendChild(
                            removedColumn.firstChild
                        );
                    }

                    removedColumn.remove();
                }
            }

            selectedColumnLayout =
                wrapper;

            syncEditor();

            return true;
        };

        const insertColumnLayout = (
            layoutKey
        ) => {
            const layout =
                allowedColumnLayouts[
                    layoutKey
                ];

            if (!layout) {
                return;
            }

            const wrapper =
                document.createElement(
                    'div'
                );

            wrapper.className =
                'user-custom-columns ' +
                layout.className;

            for (
                let index = 0; index < layout.count; index++
            ) {
                const column =
                    document.createElement(
                        'div'
                    );

                column.className =
                    'user-custom-column';

                column.innerHTML =
                    '<p>Column ' +
                    (index + 1) +
                    ' content.</p>';

                wrapper.appendChild(
                    column
                );
            }

            restoreSelection();

            const selection =
                window.getSelection();

            if (
                selection &&
                selection.rangeCount > 0
            ) {
                const range =
                    selection.getRangeAt(0);

                range.deleteContents();
                range.insertNode(
                    wrapper
                );

                const spacer =
                    document.createElement(
                        'p'
                    );

                spacer.innerHTML =
                    '<br>';

                wrapper.after(
                    spacer
                );

                const firstColumn =
                    wrapper.querySelector(
                        '.user-custom-column'
                    );

                if (firstColumn) {
                    const insideRange =
                        document.createRange();

                    insideRange.selectNodeContents(
                        firstColumn
                    );

                    insideRange.collapse(
                        false
                    );

                    selection.removeAllRanges();
                    selection.addRange(
                        insideRange
                    );

                    savedRange =
                        insideRange.cloneRange();
                }

            } else {
                editor.appendChild(
                    wrapper
                );
            }

            syncEditor();
        };

        if (columnLayoutSelect) {
            columnLayoutSelect.addEventListener(
                'mousedown',
                saveSelection
            );

            columnLayoutSelect.addEventListener(
                'change',
                () => {
                    const layoutKey =
                        columnLayoutSelect.value;

                    if (layoutKey === '') {
                        return;
                    }

                    if (
                        selectedColumnLayout &&
                        editor.contains(
                            selectedColumnLayout
                        )
                    ) {
                        updateExistingColumnLayout(
                            selectedColumnLayout,
                            layoutKey
                        );
                    } else {
                        insertColumnLayout(
                            layoutKey
                        );
                    }

                    columnLayoutSelect.value =
                        '';
                }
            );
        }

        if (
            customBlockButton &&
            customBlockDialog
        ) {
            customBlockButton.addEventListener(
                'mousedown',
                (event) => {
                    event.preventDefault();
                }
            );

            customBlockButton.addEventListener(
                'click',
                () => {
                    saveSelection();

                    loadCustomBlockControls(
                        selectedCustomBlock
                    );

                    if (
                        typeof customBlockDialog.showModal ===
                        'function'
                    ) {
                        customBlockDialog.showModal();
                    } else {
                        customBlockDialog.setAttribute(
                            'open',
                            ''
                        );
                    }
                }
            );
        }

        if (
            customCancel &&
            customBlockDialog
        ) {
            customCancel.addEventListener(
                'click',
                () => {
                    customBlockEditTarget =
                        null;

                    customBlockDialog.close();
                }
            );
        }

        if (customBlockDialog) {
            customBlockDialog.addEventListener(
                'close',
                () => {
                    customBlockEditTarget =
                        null;
                }
            );
        }

        if (unwrapBlockButton) {
            unwrapBlockButton.addEventListener(
                'click',
                unwrapSelectedWrapper
            );
        }

        if (deleteBlockButton) {
            deleteBlockButton.addEventListener(
                'click',
                deleteSelectedWrapper
            );
        }

        updateBlockActionButtons();

        if (
            customInsert &&
            customBlockDialog
        ) {
            customInsert.addEventListener(
                'click',
                () => {
                    const blockClass =
                        customType?.value ||
                        'user-custom-box';

                    const blockStyle =
                        buildCustomStyle();

                    if (
                        customBlockEditTarget &&
                        editor.contains(
                            customBlockEditTarget
                        )
                    ) {
                        [
                            'user-custom-box',
                            'user-custom-banner',
                            'user-custom-panel'
                        ].forEach(
                            (className) => {
                                customBlockEditTarget
                                    .classList
                                    .remove(
                                        className
                                    );
                            }
                        );

                        customBlockEditTarget
                            .classList
                            .add(
                                blockClass
                            );

                        customBlockEditTarget
                            .setAttribute(
                                'style',
                                blockStyle
                            );

                        selectedCustomBlock =
                            customBlockEditTarget;

                        syncEditor();
                        customBlockDialog.close();

                        return;
                    }

                    const block =
                        document.createElement(
                            'div'
                        );

                    block.className =
                        blockClass;

                    block.setAttribute(
                        'style',
                        blockStyle
                    );

                    block.innerHTML =
                        '<p>Type your custom content here.</p>';

                    restoreSelection();

                    const selection =
                        window.getSelection();

                    if (
                        selection &&
                        selection.rangeCount > 0
                    ) {
                        const range =
                            selection.getRangeAt(0);

                        range.deleteContents();
                        range.insertNode(
                            block
                        );

                        const spacer =
                            document.createElement(
                                'p'
                            );

                        spacer.innerHTML =
                            '<br>';

                        block.after(
                            spacer
                        );

                        const afterRange =
                            document.createRange();

                        afterRange.selectNodeContents(
                            block
                        );

                        afterRange.collapse(
                            false
                        );

                        selection.removeAllRanges();
                        selection.addRange(
                            afterRange
                        );

                        savedRange =
                            afterRange.cloneRange();

                    } else {
                        editor.appendChild(
                            block
                        );
                    }

                    selectedCustomBlock =
                        block;

                    syncEditor();
                    customBlockDialog.close();
                }
            );
        }

        editor.addEventListener(
            'input',
            syncEditor
        );

        editor.addEventListener(
            'keyup',
            saveSelection
        );

        editor.addEventListener(
            'mouseup',
            saveSelection
        );

        editor.addEventListener(
            'blur',
            syncEditor
        );

        form.addEventListener(
            'submit',
            () => {
                syncEditor();
            }
        );

        syncEditor();
        syncEditor();

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


<?php

require
    INCLUDES_PATH
    . '/footer.php';

?>
