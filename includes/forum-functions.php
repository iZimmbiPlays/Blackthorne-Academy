<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Blackthorne Academy
| Shared Forum Functions
|--------------------------------------------------------------------------
|
| Centralized forum/category/thread/post access and moderation helpers.
| Public forum pages should use these functions instead of duplicating
| permission checks.
|
*/

/*
|--------------------------------------------------------------------------
| Small Helpers
|--------------------------------------------------------------------------
*/

function forum_int_id(mixed $value): int
{
    if (is_int($value)) {
        return max(0, $value);
    }

    if (is_string($value) && ctype_digit($value)) {
        return max(0, (int) $value);
    }

    return 0;
}


/*
|--------------------------------------------------------------------------
| Safe Forum YouTube Embeds
|--------------------------------------------------------------------------
|
| Editors store a normal sanitized YouTube link with a Blackthorne-only
| title marker. Forum display converts only that validated marker into a
| youtube-nocookie.com embed. Raw member iframe HTML is never accepted.
|
*/

function blackthorne_youtube_video_id_from_url(string $url): ?string
{
    $url = trim($url);

    if ($url === '') {
        return null;
    }

    $parts = parse_url($url);

    if (!is_array($parts)) {
        return null;
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = trim((string) ($parts['path'] ?? ''), '/');

    if ($scheme !== 'https' && $scheme !== 'http') {
        return null;
    }

    $host = preg_replace('/^(?:www\.|m\.)/i', '', $host) ?? $host;
    $videoId = null;

    if ($host === 'youtu.be') {
        $segments = explode('/', $path);
        $videoId = $segments[0] ?? null;
    } elseif (
        $host === 'youtube.com'
        || $host === 'youtube-nocookie.com'
    ) {
        if ($path === 'watch') {
            parse_str((string) ($parts['query'] ?? ''), $query);
            $videoId = isset($query['v']) ? (string) $query['v'] : null;
        } else {
            $segments = explode('/', $path);

            if (
                isset($segments[0], $segments[1])
                && in_array(
                    strtolower($segments[0]),
                    ['shorts', 'embed', 'live'],
                    true
                )
            ) {
                $videoId = $segments[1];
            }
        }
    }

    if (
        !is_string($videoId)
        || preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) !== 1
    ) {
        return null;
    }

    return $videoId;
}


function blackthorne_render_forum_content(?string $html): string
{
    $safeHtml = sanitize_rich_text($html);

    if (
        $safeHtml === ''
        || !class_exists('DOMDocument')
    ) {
        return $safeHtml;
    }

    $document = new DOMDocument('1.0', 'UTF-8');
    $previousLibxmlState = libxml_use_internal_errors(true);

    $document->loadHTML(
        '<?xml encoding="utf-8" ?>'
        . '<div id="blackthorne-forum-render-root">'
        . $safeHtml
        . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );

    libxml_clear_errors();
    libxml_use_internal_errors($previousLibxmlState);

    $root = $document->getElementById('blackthorne-forum-render-root');

    if (!$root instanceof DOMElement) {
        return $safeHtml;
    }

    $anchors = [];

    foreach ($root->getElementsByTagName('a') as $anchor) {
        $anchors[] = $anchor;
    }

    foreach ($anchors as $anchor) {
        if (!$anchor instanceof DOMElement) {
            continue;
        }

        $title = trim($anchor->getAttribute('title'));

        if (
            preg_match(
                '/^blackthorne-youtube:([A-Za-z0-9_-]{11})$/',
                $title,
                $matches
            ) !== 1
        ) {
            continue;
        }

        $markedVideoId = $matches[1];
        $hrefVideoId =
            blackthorne_youtube_video_id_from_url(
                $anchor->getAttribute('href')
            );

        if (
            $hrefVideoId === null
            || !hash_equals($markedVideoId, $hrefVideoId)
        ) {
            continue;
        }

        $wrapper = $document->createElement('div');
        $wrapper->setAttribute('class', 'forum-youtube-embed');

        $iframe = $document->createElement('iframe');
        $iframe->setAttribute(
            'src',
            'https://www.youtube-nocookie.com/embed/'
            . rawurlencode($markedVideoId)
        );
        $iframe->setAttribute('title', 'YouTube video player');
        $iframe->setAttribute('loading', 'lazy');
        $iframe->setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
        $iframe->setAttribute(
            'allow',
            'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share'
        );
        $iframe->setAttribute('allowfullscreen', 'allowfullscreen');

        $wrapper->appendChild($iframe);

        if ($anchor->parentNode !== null) {
            $anchor->parentNode->replaceChild($wrapper, $anchor);
        }
    }

    $output = '';

    foreach ($root->childNodes as $child) {
        $output .= $document->saveHTML($child);
    }

    return $output;
}


/*
|--------------------------------------------------------------------------
| Forum Mute Sanction
|--------------------------------------------------------------------------
|
| A forum mute blocks content submission while still allowing the member to
| read any forums they would normally be allowed to access. The protected
| Admin cannot receive sanctions and therefore always bypasses this check.
|
*/

function forum_active_mute(
    PDO $pdo,
    ?int $userId = null
): ?array {
    $resolvedUserId =
        forum_user_id(
            $userId
        );

    if ($resolvedUserId <= 0) {
        return null;
    }

    if (
        function_exists(
            'user_is_protected_super_admin'
        )
        && user_is_protected_super_admin(
            $resolvedUserId
        )
    ) {
        return null;
    }

    $statement =
        $pdo->prepare(
            'SELECT
                id,
                user_id,
                issued_by,
                reason,
                starts_at,
                expires_at,
                created_at
             FROM user_sanctions
             WHERE user_id = :user_id
               AND sanction_type = "forum_mute"
               AND is_active = 1
               AND starts_at <= NOW()
               AND (
                    expires_at IS NULL
                    OR expires_at > NOW()
               )
             ORDER BY created_at DESC, id DESC
             LIMIT 1'
        );

    $statement->execute([
        'user_id' =>
            $resolvedUserId,
    ]);

    $sanction =
        $statement->fetch(
            PDO::FETCH_ASSOC
        );

    return
        $sanction
            ?: null;
}


function forum_user_is_muted(
    PDO $pdo,
    ?int $userId = null
): bool {
    return
        forum_active_mute(
            $pdo,
            $userId
        )
        !== null;
}


function forum_user_id(?int $userId = null): int
{
    if ($userId !== null) {
        return max(0, $userId);
    }

    return (int) current_user_id();
}


function forum_fetch_user_role_ids(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }

    $statement = $pdo->prepare(
        'SELECT ur.role_id
         FROM user_roles ur
         INNER JOIN roles r
             ON r.id = ur.role_id
         WHERE ur.user_id = :user_id
           AND ur.is_active = 1
           AND ur.revoked_at IS NULL
           AND (ur.expires_at IS NULL OR ur.expires_at > CURRENT_TIMESTAMP)
           AND r.is_active = 1'
    );

    $statement->execute([
        'user_id' => $userId,
    ]);

    return array_map(
        'intval',
        $statement->fetchAll(PDO::FETCH_COLUMN)
    );
}



function forum_fetch_user_active_house_ids(
    PDO $pdo,
    int $userId
): array {
    if ($userId <= 0) {
        return [];
    }

    $statement = $pdo->prepare(
        'SELECT DISTINCT hm.house_id
         FROM house_memberships hm
         INNER JOIN houses h
             ON h.id = hm.house_id
         WHERE hm.user_id = :user_id
           AND hm.membership_status = "active"
           AND hm.left_at IS NULL
           AND h.is_active = 1'
    );

    $statement->execute([
        'user_id' => $userId,
    ]);

    return array_values(
        array_unique(
            array_map(
                'intval',
                $statement->fetchAll(PDO::FETCH_COLUMN)
            )
        )
    );
}


function forum_access_permission_keys(): array
{
    return [
        'can_view_forum',
        'can_access_forum',
        'can_view_all_threads',
        'can_create_threads',
        'can_reply',
        'can_create_polls',
        'can_edit_posts',
        'can_delete_posts',
        'can_edit_threads',
        'can_delete_threads',
        'can_pin_threads',
        'can_lock_threads',
        'can_move_threads',
        'can_move_posts',
        'can_mark_announcements',
        'can_apply_labels',
        'can_manage_labels',
    ];
}


function forum_empty_access_row(): array
{
    return array_fill_keys(
        forum_access_permission_keys(),
        false
    );
}


function forum_fetch_matching_role_access(
    PDO $pdo,
    int $forumId,
    array $roleIds
): array {
    $access = forum_empty_access_row();

    if ($forumId <= 0 || $roleIds === []) {
        return $access;
    }

    $roleIds = array_values(
        array_unique(
            array_filter(
                array_map('intval', $roleIds),
                static fn (int $id): bool => $id > 0
            )
        )
    );

    if ($roleIds === []) {
        return $access;
    }

    $placeholders = implode(
        ',',
        array_fill(0, count($roleIds), '?')
    );

    $selectParts = [];

    foreach (forum_access_permission_keys() as $key) {
        $selectParts[] =
            'MAX(' . $key . ') AS ' . $key;
    }

    $statement = $pdo->prepare(
        'SELECT '
        . implode(",\n", $selectParts)
        . "
         FROM forum_role_access
         WHERE forum_id = ?
           AND role_id IN ($placeholders)"
    );

    $statement->execute([
        $forumId,
        ...$roleIds,
    ]);

    $row =
        $statement->fetch(PDO::FETCH_ASSOC)
        ?: [];

    foreach (forum_access_permission_keys() as $key) {
        $access[$key] =
            isset($row[$key])
            && $row[$key] !== null
            && (int) $row[$key] === 1;
    }

    return $access;
}


function forum_fetch_matching_house_access(
    PDO $pdo,
    int $forumId,
    array $houseIds
): array {
    $access = forum_empty_access_row();

    if ($forumId <= 0 || $houseIds === []) {
        return $access;
    }

    $houseIds = array_values(
        array_unique(
            array_filter(
                array_map('intval', $houseIds),
                static fn (int $id): bool => $id > 0
            )
        )
    );

    if ($houseIds === []) {
        return $access;
    }

    $placeholders = implode(
        ',',
        array_fill(0, count($houseIds), '?')
    );

    $selectParts = [];

    foreach (forum_access_permission_keys() as $key) {
        $selectParts[] =
            'MAX(' . $key . ') AS ' . $key;
    }

    $statement = $pdo->prepare(
        'SELECT '
        . implode(",\n", $selectParts)
        . "
         FROM forum_house_access
         WHERE forum_id = ?
           AND house_id IN ($placeholders)"
    );

    $statement->execute([
        $forumId,
        ...$houseIds,
    ]);

    $row =
        $statement->fetch(PDO::FETCH_ASSOC)
        ?: [];

    foreach (forum_access_permission_keys() as $key) {
        $access[$key] =
            isset($row[$key])
            && $row[$key] !== null
            && (int) $row[$key] === 1;
    }

    return $access;
}


function forum_combine_restricted_access(
    array $roleAccess,
    array $houseAccess,
    string $rule
): array {
    $combined = forum_empty_access_row();

    if (
        !in_array(
            $rule,
            [
                'roles',
                'houses',
                'roles_or_houses',
                'roles_and_houses',
            ],
            true
        )
    ) {
        $rule = 'roles';
    }

    foreach (forum_access_permission_keys() as $key) {
        $roleAllowed =
            !empty($roleAccess[$key]);

        $houseAllowed =
            !empty($houseAccess[$key]);

        $combined[$key] =
            match ($rule) {
                'houses' =>
                    $houseAllowed,

                'roles_or_houses' =>
                    $roleAllowed
                    || $houseAllowed,

                'roles_and_houses' =>
                    $roleAllowed
                    && $houseAllowed,

                default =>
                    $roleAllowed,
            };
    }

    return $combined;
}


function forum_user_has_all_permissions_role(PDO $pdo, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }

    /*
     * Blackthorne's protected Super Admin is designated by the private
     * super_admin_user_id setting rather than by a public-facing role.
     * Treat that account as having all forum permissions before checking
     * role-based grants so forum access remains consistent site-wide.
     */
    if (function_exists('configured_super_admin_user_id')) {
        $superAdminUserId = configured_super_admin_user_id();

        if (
            $superAdminUserId !== null
            && $userId === (int) $superAdminUserId
        ) {
            return true;
        }
    }

    $statement = $pdo->prepare(
        'SELECT 1
         FROM user_roles ur
         INNER JOIN roles r
             ON r.id = ur.role_id
         WHERE ur.user_id = :user_id
           AND ur.is_active = 1
           AND ur.revoked_at IS NULL
           AND (ur.expires_at IS NULL OR ur.expires_at > CURRENT_TIMESTAMP)
           AND r.is_active = 1
           AND r.grants_all_permissions = 1
         LIMIT 1'
    );

    $statement->execute([
        'user_id' => $userId,
    ]);

    return $statement->fetchColumn() !== false;
}


/*
|--------------------------------------------------------------------------
| Category Access
|--------------------------------------------------------------------------
*/

function forum_can_view_category(
    PDO $pdo,
    int $categoryId,
    ?int $userId = null
): bool {
    $userId = forum_user_id($userId);

    $statement = $pdo->prepare(
        'SELECT
            id,
            is_visible,
            access_mode
         FROM forum_categories
         WHERE id = :category_id
         LIMIT 1'
    );

    $statement->execute([
        'category_id' => $categoryId,
    ]);

    $category = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$category || (int) $category['is_visible'] !== 1) {
        return false;
    }

    if ((string) $category['access_mode'] === 'public') {
        return true;
    }

    if ($userId <= 0) {
        return false;
    }

    if (forum_user_has_all_permissions_role($pdo, $userId)) {
        return true;
    }

    $userOverrideStatement = $pdo->prepare(
        'SELECT can_view_category
         FROM forum_category_user_access
         WHERE category_id = :category_id
           AND user_id = :user_id
         LIMIT 1'
    );

    $userOverrideStatement->execute([
        'category_id' => $categoryId,
        'user_id' => $userId,
    ]);

    $userOverride = $userOverrideStatement->fetchColumn();

    if ($userOverride !== false) {
        return (int) $userOverride === 1;
    }

    $roleIds = forum_fetch_user_role_ids($pdo, $userId);

    if ($roleIds === []) {
        return false;
    }

    $placeholders = implode(
        ',',
        array_fill(0, count($roleIds), '?')
    );

    $roleStatement = $pdo->prepare(
        "SELECT 1
         FROM forum_category_role_access
         WHERE category_id = ?
           AND can_view_category = 1
           AND role_id IN ($placeholders)
         LIMIT 1"
    );

    $roleStatement->execute([
        $categoryId,
        ...$roleIds,
    ]);

    return $roleStatement->fetchColumn() !== false;
}


/*
|--------------------------------------------------------------------------
| Forum Access
|--------------------------------------------------------------------------
*/

function forum_fetch_forum(PDO $pdo, int $forumId): ?array
{
    if ($forumId <= 0) {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT *
         FROM forums
         WHERE id = :forum_id
         LIMIT 1'
    );

    $statement->execute([
        'forum_id' => $forumId,
    ]);

    $forum = $statement->fetch(PDO::FETCH_ASSOC);

    return $forum ?: null;
}


function forum_get_user_forum_permissions(
    PDO $pdo,
    int $forumId,
    ?int $userId = null
): array {
    $userId = forum_user_id($userId);
    $forum = forum_fetch_forum($pdo, $forumId);

    $permissions = [
        'can_view_forum' => false,
        'can_access_forum' => false,
        'can_view_all_threads' => false,
        'can_create_threads' => false,
        'can_reply' => false,
        'can_create_polls' => false,

        'can_edit_posts' => false,
        'can_delete_posts' => false,
        'can_edit_threads' => false,
        'can_delete_threads' => false,
        'can_pin_threads' => false,
        'can_lock_threads' => false,
        'can_move_threads' => false,
        'can_move_posts' => false,
        'can_mark_announcements' => false,
        'can_apply_labels' => false,
        'can_manage_labels' => false,

        'is_assigned_moderator' => false,
        'has_all_permissions_role' => false,
    ];

    if (!$forum || (int) $forum['is_visible'] !== 1) {
        return $permissions;
    }

    if (!forum_can_view_category(
        $pdo,
        (int) $forum['category_id'],
        $userId
    )) {
        return $permissions;
    }

    $permissions['can_view_forum'] =
        (int) $forum['default_can_view_forum'] === 1;

    $permissions['can_access_forum'] =
        (int) $forum['default_can_access_forum'] === 1;

    $permissions['can_create_threads'] =
        (int) $forum['default_can_create_threads'] === 1;

    $permissions['can_reply'] =
        (int) $forum['default_can_reply'] === 1;

    $permissions['can_create_polls'] =
        (int) $forum['default_can_create_polls'] === 1
        && (int) $forum['allow_polls'] === 1;

    if ($userId <= 0) {
        return $permissions;
    }

    $hasAllPermissionsRole =
        forum_user_has_all_permissions_role(
            $pdo,
            $userId
        );

    $permissions['has_all_permissions_role'] =
        $hasAllPermissionsRole;

    if ($hasAllPermissionsRole) {
        foreach ($permissions as $key => $value) {
            if ($key === 'is_assigned_moderator') {
                continue;
            }

            if (str_starts_with($key, 'can_')) {
                $permissions[$key] = true;
            }
        }

        if ((int) $forum['allow_polls'] !== 1) {
            $permissions['can_create_polls'] = false;
        }

        return $permissions;
    }

    /*
     * A forum-specific moderator assignment may grant most board moderation
     * capabilities, but deleting another member's post requires an explicit
     * role/group grant for this forum.
     */
    $roleCanDeletePosts = false;

    /*
     * Per-user access overrides role/default access.
     */
    $userStatement = $pdo->prepare(
        'SELECT
            can_view_forum,
            can_access_forum,
            can_view_all_threads,
            can_create_threads,
            can_reply,
            can_create_polls
         FROM forum_user_access
         WHERE forum_id = :forum_id
           AND user_id = :user_id
         LIMIT 1'
    );

    $userStatement->execute([
        'forum_id' => $forumId,
        'user_id' => $userId,
    ]);

    $userAccess = $userStatement->fetch(PDO::FETCH_ASSOC);

    $roleIds =
        forum_fetch_user_role_ids(
            $pdo,
            $userId
        );

    $roleDeleteAccess =
        forum_fetch_matching_role_access(
            $pdo,
            $forumId,
            $roleIds
        );

    $roleCanDeletePosts =
        !empty(
            $roleDeleteAccess[
                'can_delete_posts'
            ]
        );

    if ($userAccess) {
        foreach ([
            'can_view_forum',
            'can_access_forum',
            'can_view_all_threads',
            'can_create_threads',
            'can_reply',
            'can_create_polls',
        ] as $key) {
            $permissions[$key] =
                (int) $userAccess[$key] === 1;
        }
    } else {
        /*
         * Forum access may be granted by selected roles, selected Houses,
         * either source, or both sources depending on restricted_access_rule.
         *
         * House membership is persistent across school years. Only the user's
         * current active House grants House-based forum access; transferred or
         * inactive historical memberships do not.
         */
        $houseIds =
            forum_fetch_user_active_house_ids(
                $pdo,
                $userId
            );

        $roleAccess =
            $roleDeleteAccess;

        $houseAccess =
            forum_fetch_matching_house_access(
                $pdo,
                $forumId,
                $houseIds
            );

        $restrictedRule =
            (string) (
                $forum['restricted_access_rule']
                ?? 'roles'
            );

        $matchedAccess =
            forum_combine_restricted_access(
                $roleAccess,
                $houseAccess,
                $restrictedRule
            );

        /*
         * Existing "Everyone" defaults remain valid. For a restricted forum,
         * the selected role/House rule adds permissions on top of those
         * defaults. This preserves existing forum behavior while allowing
         * House-only and mixed access.
         */
        foreach (
            forum_access_permission_keys()
            as $key
        ) {
            if (
                array_key_exists(
                    $key,
                    $permissions
                )
            ) {
                $permissions[$key] =
                    $permissions[$key]
                    || !empty(
                        $matchedAccess[$key]
                    );
            }
        }
    }

    /*
     * Deleting another user's post is role/group-gated. House access or
     * ordinary forum access must not grant this destructive capability.
     */
    $permissions['can_delete_posts'] =
        $roleCanDeletePosts;

    /*
     * Per-user forum moderator assignment grants the stored moderator
     * capabilities, except can_delete_posts which remains role/group-gated.
     */
    $moderatorStatement = $pdo->prepare(
        'SELECT
            can_edit_posts,
            can_delete_posts,
            can_edit_threads,
            can_delete_threads,
            can_pin_threads,
            can_lock_threads,
            can_move_threads,
            can_move_posts,
            can_mark_announcements,
            can_apply_labels,
            can_manage_labels
         FROM forum_moderators
         WHERE forum_id = :forum_id
           AND user_id = :user_id
           AND is_active = 1
           AND ended_at IS NULL
         ORDER BY id DESC
         LIMIT 1'
    );

    $moderatorStatement->execute([
        'forum_id' => $forumId,
        'user_id' => $userId,
    ]);

    $moderatorAccess =
        $moderatorStatement->fetch(PDO::FETCH_ASSOC);

    if ($moderatorAccess) {
        $permissions['is_assigned_moderator'] =
            true;

        foreach ($moderatorAccess as $key => $value) {
            if ($key === 'can_delete_posts') {
                /*
                 * Moderator assignment alone never grants deletion of
                 * another member's post. Preserve only a role/group grant.
                 */
                $permissions[$key] =
                    $permissions[$key]
                    && $roleCanDeletePosts;

                continue;
            }

            $permissions[$key] =
                $permissions[$key]
                || (int) $value === 1;
        }

        /*
         * A current moderator must be able to see and enter the forum
         * they moderate.
         */
        $permissions['can_view_forum'] =
            true;

        $permissions['can_access_forum'] =
            true;
    }

    if ((int) $forum['allow_polls'] !== 1) {
        $permissions['can_create_polls'] =
            false;
    }

    return $permissions;
}


function forum_can_view_forum(
    PDO $pdo,
    int $forumId,
    ?int $userId = null
): bool {
    return forum_get_user_forum_permissions(
        $pdo,
        $forumId,
        $userId
    )['can_view_forum'];
}


function forum_can_access_forum(
    PDO $pdo,
    int $forumId,
    ?int $userId = null
): bool {
    $permissions =
        forum_get_user_forum_permissions(
            $pdo,
            $forumId,
            $userId
        );

    return
        $permissions['can_view_forum']
        && $permissions['can_access_forum'];
}


function forum_can_create_thread(
    PDO $pdo,
    int $forumId,
    ?int $userId = null
): bool {
    $permissions =
        forum_get_user_forum_permissions(
            $pdo,
            $forumId,
            $userId
        );

    return
        $permissions['can_view_forum']
        && $permissions['can_access_forum']
        && $permissions['can_create_threads'];
}


function forum_can_reply(
    PDO $pdo,
    int $forumId,
    ?int $userId = null
): bool {
    $permissions =
        forum_get_user_forum_permissions(
            $pdo,
            $forumId,
            $userId
        );

    return
        $permissions['can_view_forum']
        && $permissions['can_access_forum']
        && $permissions['can_reply'];
}


/*
|--------------------------------------------------------------------------
| Thread Helpers
|--------------------------------------------------------------------------
*/

function forum_fetch_thread(
    PDO $pdo,
    int $threadId
): ?array {
    if ($threadId <= 0) {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT ft.*
         FROM forum_threads ft
         WHERE ft.id = :thread_id
         LIMIT 1'
    );

    $statement->execute([
        'thread_id' => $threadId,
    ]);

    $thread = $statement->fetch(PDO::FETCH_ASSOC);

    return $thread ?: null;
}


function forum_can_view_thread(
    PDO $pdo,
    int $threadId,
    ?int $userId = null
): bool {
    $userId = forum_user_id($userId);
    $thread = forum_fetch_thread(
        $pdo,
        $threadId
    );

    if (!$thread || (int) $thread['is_deleted'] === 1) {
        return false;
    }

    $permissions =
        forum_get_user_forum_permissions(
            $pdo,
            (int) $thread['forum_id'],
            $userId
        );

    if (
        !$permissions['can_view_forum']
        || !$permissions['can_access_forum']
    ) {
        return false;
    }

    /*
     * public = any user with forum access
     * staff_only = thread author or someone with can_view_all_threads
     */
    if ((string) $thread['reply_visibility'] === 'public') {
        return true;
    }

    if ($userId <= 0) {
        return false;
    }

    return
        (int) $thread['user_id'] === $userId
        || $permissions['can_view_all_threads'];
}


function forum_can_reply_to_thread(
    PDO $pdo,
    int $threadId,
    ?int $userId = null
): bool {
    $thread = forum_fetch_thread(
        $pdo,
        $threadId
    );

    if (!$thread) {
        return false;
    }

    if (
        (int) $thread['is_deleted'] === 1
        || (int) $thread['is_locked'] === 1
    ) {
        return false;
    }

    return
        forum_can_view_thread(
            $pdo,
            $threadId,
            $userId
        )
        && forum_can_reply(
            $pdo,
            (int) $thread['forum_id'],
            $userId
        );
}


/*
|--------------------------------------------------------------------------
| Post Helpers
|--------------------------------------------------------------------------
*/

function forum_fetch_post(
    PDO $pdo,
    int $postId
): ?array {
    if ($postId <= 0) {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT
            fp.*,
            ft.forum_id,
            ft.is_locked AS thread_is_locked,
            ft.is_deleted AS thread_is_deleted
         FROM forum_posts fp
         INNER JOIN forum_threads ft
             ON ft.id = fp.thread_id
         WHERE fp.id = :post_id
         LIMIT 1'
    );

    $statement->execute([
        'post_id' => $postId,
    ]);

    $post = $statement->fetch(PDO::FETCH_ASSOC);

    return $post ?: null;
}


function forum_can_edit_post(
    PDO $pdo,
    int $postId,
    ?int $userId = null
): bool {
    $userId = forum_user_id($userId);
    $post = forum_fetch_post(
        $pdo,
        $postId
    );

    if (
        !$post
        || $userId <= 0
        || (int) $post['is_deleted'] === 1
        || (int) $post['thread_is_deleted'] === 1
    ) {
        return false;
    }

    /*
     * Members may edit their own posts.
     */
    if ((int) $post['user_id'] === $userId) {
        return true;
    }

    $permissions =
        forum_get_user_forum_permissions(
            $pdo,
            (int) $post['forum_id'],
            $userId
        );

    return
        $permissions['can_view_forum']
        && $permissions['can_access_forum']
        && $permissions['can_edit_posts'];
}


function forum_can_delete_post(
    PDO $pdo,
    int $postId,
    ?int $userId = null
): bool {
    $userId = forum_user_id($userId);
    $post = forum_fetch_post(
        $pdo,
        $postId
    );

    if (
        !$post
        || $userId <= 0
        || (int) $post['is_deleted'] === 1
        || (int) $post['thread_is_deleted'] === 1
    ) {
        return false;
    }

    /*
     * Members may delete their own posts.
     */
    if ((int) $post['user_id'] === $userId) {
        return true;
    }

    /*
     * Deleting another member's post is a moderation capability.
     */
    $permissions =
        forum_get_user_forum_permissions(
            $pdo,
            (int) $post['forum_id'],
            $userId
        );

    return
        $permissions['can_view_forum']
        && $permissions['can_access_forum']
        && $permissions['can_delete_posts'];
}


function forum_can_quote_post(
    PDO $pdo,
    int $postId,
    ?int $userId = null
): bool {
    $post = forum_fetch_post(
        $pdo,
        $postId
    );

    if (
        !$post
        || (int) $post['is_deleted'] === 1
    ) {
        return false;
    }

    return forum_can_reply_to_thread(
        $pdo,
        (int) $post['thread_id'],
        $userId
    );
}


/*
|--------------------------------------------------------------------------
| Moderation Capability Helpers
|--------------------------------------------------------------------------
*/

function forum_user_can_moderate(
    PDO $pdo,
    int $forumId,
    ?int $userId = null
): bool {
    $permissions =
        forum_get_user_forum_permissions(
            $pdo,
            $forumId,
            $userId
        );

    foreach ([
        'can_edit_posts',
        'can_delete_posts',
        'can_edit_threads',
        'can_delete_threads',
        'can_pin_threads',
        'can_lock_threads',
        'can_move_threads',
        'can_move_posts',
        'can_mark_announcements',
        'can_apply_labels',
        'can_manage_labels',
    ] as $key) {
        if ($permissions[$key]) {
            return true;
        }
    }

    return false;
}


function forum_can_edit_thread(
    PDO $pdo,
    int $threadId,
    ?int $userId = null
): bool {
    $userId = forum_user_id($userId);
    $thread = forum_fetch_thread(
        $pdo,
        $threadId
    );

    if (
        !$thread
        || $userId <= 0
        || (int) $thread['is_deleted'] === 1
    ) {
        return false;
    }

    /*
     * Thread authors may edit their own title.
     */
    if ((int) $thread['user_id'] === $userId) {
        return true;
    }

    return forum_get_user_forum_permissions(
        $pdo,
        (int) $thread['forum_id'],
        $userId
    )['can_edit_threads'];
}


function forum_can_delete_thread(
    PDO $pdo,
    int $threadId,
    ?int $userId = null
): bool {
    $userId = forum_user_id($userId);

    $thread = forum_fetch_thread(
        $pdo,
        $threadId
    );

    if (
        !$thread
        || $userId <= 0
        || (int) $thread['is_deleted'] === 1
    ) {
        return false;
    }

    /*
     * Thread authors may delete their own thread.
     */
    if ((int) $thread['user_id'] === $userId) {
        return true;
    }

    /*
     * Deleting another member's thread is a moderation capability.
     * Protected Super Admin is already resolved as full-access by the
     * centralized forum permission system.
     */
    return forum_get_user_forum_permissions(
        $pdo,
        (int) $thread['forum_id'],
        $userId
    )['can_delete_threads'];
}


function forum_can_pin_thread(
    PDO $pdo,
    int $threadId,
    ?int $userId = null
): bool {
    $thread = forum_fetch_thread(
        $pdo,
        $threadId
    );

    if (!$thread) {
        return false;
    }

    return forum_get_user_forum_permissions(
        $pdo,
        (int) $thread['forum_id'],
        $userId
    )['can_pin_threads'];
}


function forum_can_lock_thread(
    PDO $pdo,
    int $threadId,
    ?int $userId = null
): bool {
    $thread = forum_fetch_thread(
        $pdo,
        $threadId
    );

    if (!$thread) {
        return false;
    }

    return forum_get_user_forum_permissions(
        $pdo,
        (int) $thread['forum_id'],
        $userId
    )['can_lock_threads'];
}


function forum_can_mark_announcement(
    PDO $pdo,
    int $forumId,
    ?int $userId = null
): bool {
    return forum_get_user_forum_permissions(
        $pdo,
        $forumId,
        $userId
    )['can_mark_announcements'];
}


function forum_can_apply_labels(
    PDO $pdo,
    int $forumId,
    ?int $userId = null
): bool {
    return forum_get_user_forum_permissions(
        $pdo,
        $forumId,
        $userId
    )['can_apply_labels'];
}


function forum_can_manage_labels(
    PDO $pdo,
    int $forumId,
    ?int $userId = null
): bool {
    return forum_get_user_forum_permissions(
        $pdo,
        $forumId,
        $userId
    )['can_manage_labels'];
}


function forum_can_move_thread(
    PDO $pdo,
    int $forumId,
    ?int $userId = null
): bool {
    return forum_get_user_forum_permissions(
        $pdo,
        $forumId,
        $userId
    )['can_move_threads'];
}


function forum_can_move_post(
    PDO $pdo,
    int $forumId,
    ?int $userId = null
): bool {
    return forum_get_user_forum_permissions(
        $pdo,
        $forumId,
        $userId
    )['can_move_posts'];
}


/*
|--------------------------------------------------------------------------
| Labels
|--------------------------------------------------------------------------
*/

function forum_fetch_active_labels(
    PDO $pdo,
    int $forumId
): array {
    if ($forumId <= 0) {
        return [];
    }

    $statement = $pdo->prepare(
        'SELECT
            id,
            name,
            label_color,
            sort_order
         FROM forum_labels
         WHERE forum_id = :forum_id
           AND is_active = 1
         ORDER BY sort_order ASC, name ASC'
    );

    $statement->execute([
        'forum_id' => $forumId,
    ]);

    return $statement->fetchAll(PDO::FETCH_ASSOC);
}


/*
|--------------------------------------------------------------------------
| Forum Moderators
|--------------------------------------------------------------------------
*/

function forum_fetch_active_moderators(
    PDO $pdo,
    int $forumId
): array {
    if ($forumId <= 0) {
        return [];
    }

    $statement = $pdo->prepare(
        'SELECT
            u.id,
            u.username,
            u.display_name,
            u.avatar
         FROM forum_moderators fm
         INNER JOIN users u
             ON u.id = fm.user_id
         WHERE fm.forum_id = :forum_id
           AND fm.is_active = 1
           AND fm.ended_at IS NULL
           AND u.status = "active"
         ORDER BY u.display_name ASC'
    );

    $statement->execute([
        'forum_id' => $forumId,
    ]);

    return $statement->fetchAll(PDO::FETCH_ASSOC);
}


/*
|--------------------------------------------------------------------------
| Reply Pagination
|--------------------------------------------------------------------------
*/

function forum_posts_per_page(
    PDO $pdo,
    ?int $userId = null
): int {
    $userId = forum_user_id($userId);

    if ($userId <= 0) {
        return 20;
    }

    $statement = $pdo->prepare(
        'SELECT posts_per_page
         FROM user_forum_settings
         WHERE user_id = :user_id
         LIMIT 1'
    );

    $statement->execute([
        'user_id' => $userId,
    ]);

    $value = $statement->fetchColumn();

    if ($value === false) {
        return 20;
    }

    /*
     * Blackthorne thread pages are intentionally capped at 20 replies.
     */
    return min(
        20,
        max(
            1,
            (int) $value
        )
    );
}


/*
|--------------------------------------------------------------------------
| Thread Activity
|--------------------------------------------------------------------------
*/

function forum_touch_thread_activity(
    PDO $pdo,
    int $threadId
): void {
    if ($threadId <= 0) {
        return;
    }

    $statement = $pdo->prepare(
        'UPDATE forum_threads
         SET last_activity_at = CURRENT_TIMESTAMP
         WHERE id = :thread_id'
    );

    $statement->execute([
        'thread_id' => $threadId,
    ]);
}


/*
|--------------------------------------------------------------------------
| Moderation Audit
|--------------------------------------------------------------------------
*/

function forum_log_moderation_action(
    PDO $pdo,
    string $actionType,
    int $moderatorId,
    ?int $forumId = null,
    ?int $threadId = null,
    ?int $postId = null,
    ?int $labelId = null,
    ?int $targetUserId = null,
    ?int $sourceThreadId = null,
    ?int $destinationThreadId = null,
    ?string $reason = null,
    ?string $notes = null
): void {
    if ($moderatorId <= 0) {
        return;
    }

    $statement = $pdo->prepare(
        'INSERT INTO moderation_actions (
            moderator_id,
            target_user_id,
            forum_id,
            thread_id,
            post_id,
            label_id,
            source_thread_id,
            destination_thread_id,
            action_type,
            reason,
            notes
         ) VALUES (
            :moderator_id,
            :target_user_id,
            :forum_id,
            :thread_id,
            :post_id,
            :label_id,
            :source_thread_id,
            :destination_thread_id,
            :action_type,
            :reason,
            :notes
         )'
    );

    $statement->execute([
        'moderator_id' => $moderatorId,
        'target_user_id' => $targetUserId,
        'forum_id' => $forumId,
        'thread_id' => $threadId,
        'post_id' => $postId,
        'label_id' => $labelId,
        'source_thread_id' => $sourceThreadId,
        'destination_thread_id' => $destinationThreadId,
        'action_type' => $actionType,
        'reason' => $reason,
        'notes' => $notes,
    ]);
}
