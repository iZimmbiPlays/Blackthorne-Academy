<?php

declare(strict_types=1);

/**
 * Blackthorne Academy
 * Shared Application Bootstrap
 */

/*
|--------------------------------------------------------------------------
| Load Core Configuration
|--------------------------------------------------------------------------
*/

require_once dirname(__DIR__) . '/config/config.php';

/*
|--------------------------------------------------------------------------
| Configure Session
|--------------------------------------------------------------------------
|
| These settings must be applied before session_start().
|
*/

if (session_status() === PHP_SESSION_NONE) {

    session_name(SESSION_NAME);

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

/*
|--------------------------------------------------------------------------
| Load Database
|--------------------------------------------------------------------------
*/

require_once CONFIG_PATH . '/database.php';

/*
|--------------------------------------------------------------------------
| Load Shared Helpers
|--------------------------------------------------------------------------
|
| These files may be mostly empty at first.
| We are loading them here now so every page can eventually use the same
| authentication, permissions, and general helper functions.
|
*/

require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permissions.php';
require_once CONFIG_PATH . '/mail.php';

/*
|--------------------------------------------------------------------------
| Track Logged-In Member Presence
|--------------------------------------------------------------------------
|
| Presence is intentionally stored separately from authentication sessions so
| it can power member profiles and the future Academy map without exposing
| session/security data.
|
| Only the request path is stored. Query strings are never written to
| user_presence.current_path.
|
*/

if (
    function_exists('current_user_id')
    && isset($pdo)
    && $pdo instanceof PDO
) {
    $presenceUserId =
        (int) (
            current_user_id()
            ?? 0
        );

    if ($presenceUserId > 0) {
        try {
            $requestUri =
                (string) (
                    $_SERVER['REQUEST_URI']
                    ?? '/'
                );

            $requestPath =
                parse_url(
                    $requestUri,
                    PHP_URL_PATH
                );

            if (
                !is_string($requestPath)
                || $requestPath === ''
            ) {
                $requestPath =
                    '/';
            }

            /*
             * Keep the stored path reasonably small and never include query
             * parameters, fragments, form data, or other sensitive values.
             */
            $requestPath =
                substr(
                    $requestPath,
                    0,
                    500
                );

            $pageFilename =
                strtolower(
                    basename(
                        $requestPath
                    )
                );

            $locationLabel =
                'Around the Academy';

            $locationType =
                'other';

            $locationEntityId =
                null;


            /*
             * Helper for numeric entity IDs from safe GET parameters.
             */
            $presenceEntityId =
                static function (
                    array $keys
                ): ?int {
                    foreach ($keys as $key) {
                        $value =
                            $_GET[$key]
                            ?? null;

                        if (
                            is_scalar($value)
                            && ctype_digit(
                                (string) $value
                            )
                        ) {
                            $id =
                                (int) $value;

                            if ($id > 0) {
                                return $id;
                            }
                        }
                    }

                    return null;
                };


            /*
             * Member-facing location labels.
             *
             * Most pages use a useful page-specific label. Dashboards,
             * profiles, and courses intentionally keep their existing broad
             * labels. Forum and thread pages resolve to the containing forum
             * title so presence can show where the member is actually browsing.
             */
            switch ($pageFilename) {
                case '':
                case 'index.php':
                    $locationLabel =
                        'Academy Home';

                    $locationType =
                        'home';
                    break;

                case 'dashboard.php':
                    $locationLabel =
                        'Dashboard';

                    $locationType =
                        'dashboard';
                    break;

                case 'forums.php':
                    $locationLabel =
                        'Forums';

                    $locationType =
                        'forums';
                    break;

                case 'announcements.php':
                    $locationLabel =
                        'Announcements';

                    $locationType =
                        'forums';
                    break;

                case 'forum.php':
                    $locationType =
                        'forum';

                    $locationEntityId =
                        $presenceEntityId(
                            [
                                'f',
                                'forum_id',
                                'id',
                            ]
                        );

                    if ($locationEntityId !== null) {
                        $forumPresenceStatement =
                            $pdo->prepare(
                                'SELECT title
                                 FROM forums
                                 WHERE id = :forum_id
                                   AND is_visible = 1
                                 LIMIT 1'
                            );

                        $forumPresenceStatement->execute([
                            'forum_id' =>
                                $locationEntityId,
                        ]);

                        $forumPresenceTitle =
                            trim(
                                (string) (
                                    $forumPresenceStatement->fetchColumn()
                                    ?: ''
                                )
                            );

                        if ($forumPresenceTitle !== '') {
                            $locationLabel =
                                $forumPresenceTitle;
                        } else {
                            $locationLabel =
                                'Forums';
                        }
                    } else {
                        $locationLabel =
                            'Forums';
                    }
                    break;

                case 'thread.php':
                    $locationType =
                        'thread';

                    $locationEntityId =
                        $presenceEntityId(
                            [
                                't',
                                'thread_id',
                                'id',
                            ]
                        );

                    if ($locationEntityId !== null) {
                        $threadPresenceStatement =
                            $pdo->prepare(
                                'SELECT f.title
                                 FROM forum_threads ft
                                 INNER JOIN forums f
                                    ON f.id = ft.forum_id
                                 WHERE ft.id = :thread_id
                                   AND ft.is_deleted = 0
                                   AND f.is_visible = 1
                                 LIMIT 1'
                            );

                        $threadPresenceStatement->execute([
                            'thread_id' =>
                                $locationEntityId,
                        ]);

                        $threadForumTitle =
                            trim(
                                (string) (
                                    $threadPresenceStatement->fetchColumn()
                                    ?: ''
                                )
                            );

                        if ($threadForumTitle !== '') {
                            $locationLabel =
                                $threadForumTitle;
                        } else {
                            $locationLabel =
                                'Forums';
                        }
                    } else {
                        $locationLabel =
                            'Forums';
                    }
                    break;

                case 'new-thread.php':
                    $locationType =
                        'forum';

                    $locationEntityId =
                        $presenceEntityId(
                            [
                                'f',
                                'forum_id',
                            ]
                        );

                    if ($locationEntityId !== null) {
                        $newThreadPresenceStatement =
                            $pdo->prepare(
                                'SELECT title
                                 FROM forums
                                 WHERE id = :forum_id
                                   AND is_visible = 1
                                 LIMIT 1'
                            );

                        $newThreadPresenceStatement->execute([
                            'forum_id' =>
                                $locationEntityId,
                        ]);

                        $newThreadForumTitle =
                            trim(
                                (string) (
                                    $newThreadPresenceStatement->fetchColumn()
                                    ?: ''
                                )
                            );

                        if ($newThreadForumTitle !== '') {
                            $locationLabel =
                                $newThreadForumTitle;
                        } else {
                            $locationLabel =
                                'Forums';
                        }
                    } else {
                        $locationLabel =
                            'Forums';
                    }
                    break;

                case 'profile.php':
                    $locationLabel =
                        'Member Profiles';

                    $locationType =
                        'profile';

                    $profileReference =
                        $_GET['u']
                        ?? null;

                    if (
                        is_scalar($profileReference)
                        && ctype_digit(
                            (string) $profileReference
                        )
                    ) {
                        $profileEntityId =
                            (int) $profileReference;

                        if ($profileEntityId > 0) {
                            $locationEntityId =
                                $profileEntityId;
                        }
                    }
                    break;

                case 'profile-edit.php':
                    $locationLabel =
                        'Member Profiles';

                    $locationType =
                        'profile';

                    $locationEntityId =
                        $presenceUserId;
                    break;

                case 'courses.php':
                    $locationLabel =
                        'Courses';

                    $locationType =
                        'courses';
                    break;

                case 'course.php':
                    $locationLabel =
                        'Courses';

                    $locationType =
                        'course';

                    $locationEntityId =
                        $presenceEntityId(
                            [
                                'c',
                                'course_id',
                                'id',
                            ]
                        );
                    break;

                case 'messages.php':
                case 'message.php':
                case 'conversation.php':
                    $locationLabel =
                        'Messages';

                    $locationType =
                        'messages';
                    break;

                case 'notification.php':
                case 'notifications.php':
                    $locationLabel =
                        'Notifications';

                    $locationType =
                        'other';
                    break;

                case 'friends.php':
                    $locationLabel =
                        'Friends';

                    $locationType =
                        'other';
                    break;

                case 'blocked-users.php':
                    $locationLabel =
                        'Blocked Members';

                    $locationType =
                        'other';
                    break;

                case 'bookmarks.php':
                    $locationLabel =
                        'Bookmarks';

                    $locationType =
                        'other';
                    break;

                case 'news.php':
                    $locationLabel =
                        'News';

                    $locationType =
                        'other';
                    break;

                case 'living-ledger.php':
                case 'ledger.php':
                case 'map.php':
                    $locationLabel =
                        'The Living Ledger';

                    $locationType =
                        'other';
                    break;

                default:
                    if (
                        str_contains(
                            strtolower($requestPath),
                            '/admin/'
                        )
                    ) {
                        $locationLabel =
                            'Academy Administration';

                        $locationType =
                            'other';

                    } elseif (
                        str_contains(
                            strtolower($requestPath),
                            '/instructor/'
                        )
                    ) {
                        $locationLabel =
                            'Instructor Area';

                        $locationType =
                            'other';

                    } elseif (
                        $pageFilename !== ''
                        && str_ends_with(
                            $pageFilename,
                            '.php'
                        )
                    ) {
                        $pageLabelSource =
                            substr(
                                $pageFilename,
                                0,
                                -4
                            );

                        $pageLabelSource =
                            str_replace(
                                [
                                    '-',
                                    '_',
                                ],
                                ' ',
                                $pageLabelSource
                            );

                        $pageLabelSource =
                            trim(
                                preg_replace(
                                    '/\s+/',
                                    ' ',
                                    $pageLabelSource
                                )
                                ?? ''
                            );

                        if ($pageLabelSource !== '') {
                            $locationLabel =
                                ucwords(
                                    $pageLabelSource
                                );

                            $locationType =
                                'other';
                        }
                    }
                    break;
            }


            $presenceStatement =
                $pdo->prepare(
                    'INSERT INTO user_presence (
                        user_id,
                        location_label,
                        location_type,
                        location_entity_id,
                        current_path,
                        last_seen_at
                     ) VALUES (
                        :user_id,
                        :location_label,
                        :location_type,
                        :location_entity_id,
                        :current_path,
                        CURRENT_TIMESTAMP
                     )
                     ON DUPLICATE KEY UPDATE
                        location_label = VALUES(location_label),
                        location_type = VALUES(location_type),
                        location_entity_id = VALUES(location_entity_id),
                        current_path = VALUES(current_path),
                        last_seen_at = CURRENT_TIMESTAMP'
                );

            $presenceStatement->execute([
                'user_id' =>
                    $presenceUserId,

                'location_label' =>
                    $locationLabel,

                'location_type' =>
                    $locationType,

                'location_entity_id' =>
                    $locationEntityId,

                'current_path' =>
                    $requestPath,
            ]);

        } catch (Throwable $exception) {
            /*
             * Presence tracking must never break the site. If tracking fails,
             * authentication and the requested page continue normally.
             */
            error_log(
                'Blackthorne presence tracking error: '
                . $exception->getMessage()
            );
        }
    }
}

