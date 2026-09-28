<?php

declare(strict_types=1);

/**
 * Blackthorne Academy
 * Friendship / Profile-Slug Helpers
 *
 * Expected environment:
 * - includes/bootstrap.php has already been loaded.
 * - $pdo is a configured PDO instance.
 *
 * This file does not render HTML and does not process requests directly.
 * It provides the shared backend rules used by profile actions, friend pages,
 * notifications, blocking, and later private messaging.
 */


/*
|--------------------------------------------------------------------------
| Friendship Result Helper
|--------------------------------------------------------------------------
*/

function blackthorne_friend_result(
    bool $success,
    string $code,
    string $message,
    array $data = []
): array {
    return [
        'success' => $success,
        'code' => $code,
        'message' => $message,
        'data' => $data,
    ];
}


/*
|--------------------------------------------------------------------------
| Normalize Friendship Pair
|--------------------------------------------------------------------------
|
| The database stores one row per relationship:
|
| user_low_id  = lower numeric user ID
| user_high_id = higher numeric user ID
|
| This prevents A→B and B→A from becoming duplicate relationships.
|
*/

function blackthorne_friend_pair(
    int $firstUserId,
    int $secondUserId
): array {
    if (
        $firstUserId <= 0
        || $secondUserId <= 0
        || $firstUserId === $secondUserId
    ) {
        throw new InvalidArgumentException(
            'A friendship pair requires two different valid user IDs.'
        );
    }

    return [
        'low' => min(
            $firstUserId,
            $secondUserId
        ),
        'high' => max(
            $firstUserId,
            $secondUserId
        ),
    ];
}


/*
|--------------------------------------------------------------------------
| Fetch Active User
|--------------------------------------------------------------------------
*/

function blackthorne_friend_fetch_user(
    PDO $pdo,
    int $userId
): ?array {
    if ($userId <= 0) {
        return null;
    }

    $statement =
        $pdo->prepare(
            'SELECT
                id,
                username,
                profile_slug,
                display_name,
                avatar,
                status

             FROM users

             WHERE id = :user_id
               AND status = \'active\'

             LIMIT 1'
        );

    $statement->execute([
        'user_id' => $userId,
    ]);

    $user =
        $statement->fetch(
            PDO::FETCH_ASSOC
        );

    return is_array($user)
        ? $user
        : null;
}


/*
|--------------------------------------------------------------------------
| Blocking
|--------------------------------------------------------------------------
|
| Blocking is considered mutual for interaction purposes:
|
| - If A blocks B, neither side may create a friend request.
| - Existing/pending friendship rows are removed when a block is created.
|
| The user_blocks table still records the direction of the actual block.
|
*/

function blackthorne_users_blocked_for_profile_interaction(
    PDO $pdo,
    int $firstUserId,
    int $secondUserId
): bool {
    if (
        $firstUserId <= 0
        || $secondUserId <= 0
        || $firstUserId === $secondUserId
    ) {
        return false;
    }

    $statement =
        $pdo->prepare(
            'SELECT 1

             FROM user_blocks

             WHERE block_profile_interactions = 1
               AND (
                    (
                        blocker_user_id = :first_user_a
                        AND blocked_user_id = :second_user_a
                    )
                    OR
                    (
                        blocker_user_id = :second_user_b
                        AND blocked_user_id = :first_user_b
                    )
               )

             LIMIT 1'
        );

    $statement->execute([
        'first_user_a' => $firstUserId,
        'second_user_a' => $secondUserId,
        'second_user_b' => $secondUserId,
        'first_user_b' => $firstUserId,
    ]);

    return $statement->fetchColumn() !== false;
}


function blackthorne_user_has_blocked(
    PDO $pdo,
    int $blockerUserId,
    int $blockedUserId
): bool {
    if (
        $blockerUserId <= 0
        || $blockedUserId <= 0
        || $blockerUserId === $blockedUserId
    ) {
        return false;
    }

    $statement =
        $pdo->prepare(
            'SELECT 1

             FROM user_blocks

             WHERE blocker_user_id = :blocker_user_id
               AND blocked_user_id = :blocked_user_id

             LIMIT 1'
        );

    $statement->execute([
        'blocker_user_id' => $blockerUserId,
        'blocked_user_id' => $blockedUserId,
    ]);

    return $statement->fetchColumn() !== false;
}


/*
|--------------------------------------------------------------------------
| Fetch Friendship Row
|--------------------------------------------------------------------------
*/

function blackthorne_friendship_row(
    PDO $pdo,
    int $firstUserId,
    int $secondUserId
): ?array {
    if (
        $firstUserId <= 0
        || $secondUserId <= 0
        || $firstUserId === $secondUserId
    ) {
        return null;
    }

    $pair =
        blackthorne_friend_pair(
            $firstUserId,
            $secondUserId
        );

    $statement =
        $pdo->prepare(
            'SELECT
                id,
                user_low_id,
                user_high_id,
                requested_by_user_id,
                status,
                accepted_at,
                created_at,
                updated_at

             FROM user_friendships

             WHERE user_low_id = :user_low_id
               AND user_high_id = :user_high_id

             LIMIT 1'
        );

    $statement->execute([
        'user_low_id' => $pair['low'],
        'user_high_id' => $pair['high'],
    ]);

    $row =
        $statement->fetch(
            PDO::FETCH_ASSOC
        );

    return is_array($row)
        ? $row
        : null;
}


/*
|--------------------------------------------------------------------------
| Relationship State
|--------------------------------------------------------------------------
|
| Returned state values:
|
| self
| blocked
| none
| pending_outgoing
| pending_incoming
| friends
|
*/

function blackthorne_friend_relationship(
    PDO $pdo,
    int $viewerUserId,
    int $otherUserId
): array {
    if (
        $viewerUserId <= 0
        || $otherUserId <= 0
    ) {
        return [
            'state' => 'none',
            'friendship' => null,
        ];
    }

    if ($viewerUserId === $otherUserId) {
        return [
            'state' => 'self',
            'friendship' => null,
        ];
    }

    if (
        blackthorne_users_blocked_for_profile_interaction(
            $pdo,
            $viewerUserId,
            $otherUserId
        )
    ) {
        return [
            'state' => 'blocked',
            'friendship' => null,
        ];
    }

    $friendship =
        blackthorne_friendship_row(
            $pdo,
            $viewerUserId,
            $otherUserId
        );

    if ($friendship === null) {
        return [
            'state' => 'none',
            'friendship' => null,
        ];
    }

    if (
        (string) $friendship['status']
        === 'accepted'
    ) {
        return [
            'state' => 'friends',
            'friendship' => $friendship,
        ];
    }

    $requestedBy =
        (int) $friendship[
            'requested_by_user_id'
        ];

    return [
        'state' =>
            $requestedBy === $viewerUserId
                ? 'pending_outgoing'
                : 'pending_incoming',

        'friendship' => $friendship,
    ];
}


/*
|--------------------------------------------------------------------------
| Notification Preferences
|--------------------------------------------------------------------------
|
| Missing preference rows use the site's normal default-on behavior.
|
*/

function blackthorne_friend_notifications_enabled(
    PDO $pdo,
    int $userId,
    string $preference
): bool {
    $allowedPreferences = [
        'notify_friend_requests',
        'notify_friend_activity',
    ];

    if (
        $userId <= 0
        || !in_array(
            $preference,
            $allowedPreferences,
            true
        )
    ) {
        return false;
    }

    $statement =
        $pdo->prepare(
            'SELECT ' . $preference . '

             FROM notification_preferences

             WHERE user_id = :user_id

             LIMIT 1'
        );

    $statement->execute([
        'user_id' => $userId,
    ]);

    $value =
        $statement->fetchColumn();

    if ($value === false) {
        return true;
    }

    return (int) $value === 1;
}


/*
|--------------------------------------------------------------------------
| Create Friend Notification
|--------------------------------------------------------------------------
*/

function blackthorne_create_friend_notification(
    PDO $pdo,
    int $recipientUserId,
    int $actorUserId,
    string $notificationType,
    string $title,
    string $message,
    string $linkUrl,
    ?int $friendshipId = null
): void {
    if (
        $recipientUserId <= 0
        || $actorUserId <= 0
        || $recipientUserId === $actorUserId
    ) {
        return;
    }

    $preference =
        match ($notificationType) {
            'friend_request' =>
                'notify_friend_requests',

            'friend_accepted' =>
                'notify_friend_activity',

            default =>
                null,
        };

    if (
        $preference === null
        || !blackthorne_friend_notifications_enabled(
            $pdo,
            $recipientUserId,
            $preference
        )
    ) {
        return;
    }

    /*
     * Avoid duplicate unread notifications for the same friendship event.
     */
    if ($friendshipId !== null) {
        $duplicateStatement =
            $pdo->prepare(
                'SELECT 1

                 FROM notifications

                 WHERE user_id = :user_id
                   AND actor_user_id = :actor_user_id
                   AND notification_type = :notification_type
                   AND related_entity_type = \'friendship\'
                   AND related_entity_id = :friendship_id
                   AND is_read = 0

                 LIMIT 1'
            );

        $duplicateStatement->execute([
            'user_id' => $recipientUserId,
            'actor_user_id' => $actorUserId,
            'notification_type' => $notificationType,
            'friendship_id' => $friendshipId,
        ]);

        if (
            $duplicateStatement
                ->fetchColumn()
            !== false
        ) {
            return;
        }
    }

    $statement =
        $pdo->prepare(
            'INSERT INTO notifications (
                user_id,
                actor_user_id,
                notification_type,
                related_entity_type,
                related_entity_id,
                title,
                message,
                link_url,
                is_read,
                read_at
             ) VALUES (
                :user_id,
                :actor_user_id,
                :notification_type,
                :related_entity_type,
                :related_entity_id,
                :title,
                :message,
                :link_url,
                0,
                NULL
             )'
        );

    $statement->execute([
        'user_id' => $recipientUserId,
        'actor_user_id' => $actorUserId,
        'notification_type' => $notificationType,
        'related_entity_type' => 'friendship',
        'related_entity_id' => $friendshipId,
        'title' => $title,
        'message' => $message,
        'link_url' => $linkUrl,
    ]);
}


/*
|--------------------------------------------------------------------------
| Remove Pending Friend-Request Notifications
|--------------------------------------------------------------------------
*/

function blackthorne_remove_friend_request_notifications(
    PDO $pdo,
    int $friendshipId
): void {
    if ($friendshipId <= 0) {
        return;
    }

    $statement =
        $pdo->prepare(
            'DELETE FROM notifications

             WHERE notification_type = \'friend_request\'
               AND related_entity_type = \'friendship\'
               AND related_entity_id = :friendship_id'
        );

    $statement->execute([
        'friendship_id' => $friendshipId,
    ]);
}


/*
|--------------------------------------------------------------------------
| Send Friend Request
|--------------------------------------------------------------------------
*/

function blackthorne_send_friend_request(
    PDO $pdo,
    int $requesterUserId,
    int $recipientUserId
): array {
    if (
        $requesterUserId <= 0
        || $recipientUserId <= 0
    ) {
        return blackthorne_friend_result(
            false,
            'invalid_user',
            'That friend request could not be sent.'
        );
    }

    if ($requesterUserId === $recipientUserId) {
        return blackthorne_friend_result(
            false,
            'self_request',
            'You cannot send a friend request to yourself.'
        );
    }

    $requester =
        blackthorne_friend_fetch_user(
            $pdo,
            $requesterUserId
        );

    $recipient =
        blackthorne_friend_fetch_user(
            $pdo,
            $recipientUserId
        );

    if (
        $requester === null
        || $recipient === null
    ) {
        return blackthorne_friend_result(
            false,
            'user_unavailable',
            'That member is not available for friend requests.'
        );
    }

    if (
        blackthorne_users_blocked_for_profile_interaction(
            $pdo,
            $requesterUserId,
            $recipientUserId
        )
    ) {
        return blackthorne_friend_result(
            false,
            'blocked',
            'A friend request cannot be sent between these accounts.'
        );
    }

    $existing =
        blackthorne_friendship_row(
            $pdo,
            $requesterUserId,
            $recipientUserId
        );

    if ($existing !== null) {
        if (
            (string) $existing['status']
            === 'accepted'
        ) {
            return blackthorne_friend_result(
                false,
                'already_friends',
                'You are already friends.'
            );
        }

        if (
            (int) $existing[
                'requested_by_user_id'
            ] === $requesterUserId
        ) {
            return blackthorne_friend_result(
                false,
                'already_pending',
                'Your friend request is already pending.'
            );
        }

        return blackthorne_friend_result(
            false,
            'incoming_pending',
            'This member has already sent you a friend request.'
        );
    }

    $pair =
        blackthorne_friend_pair(
            $requesterUserId,
            $recipientUserId
        );

    try {
        $pdo->beginTransaction();

        $insertStatement =
            $pdo->prepare(
                'INSERT INTO user_friendships (
                    user_low_id,
                    user_high_id,
                    requested_by_user_id,
                    status,
                    accepted_at
                 ) VALUES (
                    :user_low_id,
                    :user_high_id,
                    :requested_by_user_id,
                    \'pending\',
                    NULL
                 )'
            );

        $insertStatement->execute([
            'user_low_id' => $pair['low'],
            'user_high_id' => $pair['high'],
            'requested_by_user_id' =>
                $requesterUserId,
        ]);

        $friendshipId =
            (int) $pdo->lastInsertId();

        blackthorne_create_friend_notification(
            $pdo,
            $recipientUserId,
            $requesterUserId,
            'friend_request',
            'New Friend Request',
            (string) $requester['display_name']
                . ' sent you a friend request.',
            'profile.php?u='
                . $requesterUserId,
            $friendshipId
        );

        $pdo->commit();

        return blackthorne_friend_result(
            true,
            'request_sent',
            'Friend request sent.',
            [
                'friendship_id' =>
                    $friendshipId,
            ]
        );

    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        /*
         * A concurrent request may have inserted the same unique pair.
         * Return the resulting relationship state instead of exposing SQL.
         */
        $relationship =
            blackthorne_friend_relationship(
                $pdo,
                $requesterUserId,
                $recipientUserId
            );

        if (
            $relationship['state']
            !== 'none'
        ) {
            return blackthorne_friend_result(
                false,
                'relationship_exists',
                'A friend relationship or request already exists.',
                $relationship
            );
        }

        throw $exception;
    }
}


/*
|--------------------------------------------------------------------------
| Cancel Outgoing Friend Request
|--------------------------------------------------------------------------
*/

function blackthorne_cancel_friend_request(
    PDO $pdo,
    int $requesterUserId,
    int $recipientUserId
): array {
    $friendship =
        blackthorne_friendship_row(
            $pdo,
            $requesterUserId,
            $recipientUserId
        );

    if (
        $friendship === null
        || (string) $friendship['status']
            !== 'pending'
        || (int) $friendship[
            'requested_by_user_id'
        ] !== $requesterUserId
    ) {
        return blackthorne_friend_result(
            false,
            'not_pending_outgoing',
            'There is no outgoing friend request to cancel.'
        );
    }

    $friendshipId =
        (int) $friendship['id'];

    try {
        $pdo->beginTransaction();

        blackthorne_remove_friend_request_notifications(
            $pdo,
            $friendshipId
        );

        $deleteStatement =
            $pdo->prepare(
                'DELETE FROM user_friendships

                 WHERE id = :friendship_id
                   AND status = \'pending\'
                   AND requested_by_user_id = :requester_user_id'
            );

        $deleteStatement->execute([
            'friendship_id' =>
                $friendshipId,

            'requester_user_id' =>
                $requesterUserId,
        ]);

        $pdo->commit();

        return blackthorne_friend_result(
            true,
            'request_cancelled',
            'Friend request cancelled.'
        );

    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}


/*
|--------------------------------------------------------------------------
| Accept Incoming Friend Request
|--------------------------------------------------------------------------
*/

function blackthorne_accept_friend_request(
    PDO $pdo,
    int $recipientUserId,
    int $requesterUserId
): array {
    if (
        $recipientUserId <= 0
        || $requesterUserId <= 0
        || $recipientUserId === $requesterUserId
    ) {
        return blackthorne_friend_result(
            false,
            'invalid_request',
            'That friend request could not be accepted.'
        );
    }

    if (
        blackthorne_users_blocked_for_profile_interaction(
            $pdo,
            $recipientUserId,
            $requesterUserId
        )
    ) {
        return blackthorne_friend_result(
            false,
            'blocked',
            'That friend request can no longer be accepted.'
        );
    }

    $friendship =
        blackthorne_friendship_row(
            $pdo,
            $recipientUserId,
            $requesterUserId
        );

    if (
        $friendship === null
        || (string) $friendship['status']
            !== 'pending'
        || (int) $friendship[
            'requested_by_user_id'
        ] !== $requesterUserId
    ) {
        return blackthorne_friend_result(
            false,
            'not_pending_incoming',
            'That incoming friend request is no longer available.'
        );
    }

    $recipient =
        blackthorne_friend_fetch_user(
            $pdo,
            $recipientUserId
        );

    $requester =
        blackthorne_friend_fetch_user(
            $pdo,
            $requesterUserId
        );

    if (
        $recipient === null
        || $requester === null
    ) {
        return blackthorne_friend_result(
            false,
            'user_unavailable',
            'That friend request can no longer be accepted.'
        );
    }

    $friendshipId =
        (int) $friendship['id'];

    try {
        $pdo->beginTransaction();

        /*
         * Lock/re-check the row so two simultaneous actions cannot both
         * transition the same pending request.
         */
        $lockStatement =
            $pdo->prepare(
                'SELECT
                    id,
                    status,
                    requested_by_user_id

                 FROM user_friendships

                 WHERE id = :friendship_id

                 FOR UPDATE'
            );

        $lockStatement->execute([
            'friendship_id' =>
                $friendshipId,
        ]);

        $locked =
            $lockStatement->fetch(
                PDO::FETCH_ASSOC
            );

        if (
            !is_array($locked)
            || (string) $locked['status']
                !== 'pending'
            || (int) $locked[
                'requested_by_user_id'
            ] !== $requesterUserId
        ) {
            $pdo->rollBack();

            return blackthorne_friend_result(
                false,
                'not_pending_incoming',
                'That incoming friend request is no longer available.'
            );
        }

        $updateStatement =
            $pdo->prepare(
                'UPDATE user_friendships

                 SET
                    status = \'accepted\',
                    accepted_at = NOW()

                 WHERE id = :friendship_id
                   AND status = \'pending\''
            );

        $updateStatement->execute([
            'friendship_id' =>
                $friendshipId,
        ]);

        blackthorne_remove_friend_request_notifications(
            $pdo,
            $friendshipId
        );

        blackthorne_create_friend_notification(
            $pdo,
            $requesterUserId,
            $recipientUserId,
            'friend_accepted',
            'Friend Request Accepted',
            (string) $recipient['display_name']
                . ' accepted your friend request.',
            'profile.php?u='
                . $recipientUserId,
            $friendshipId
        );

        $pdo->commit();

        return blackthorne_friend_result(
            true,
            'request_accepted',
            'Friend request accepted.',
            [
                'friendship_id' =>
                    $friendshipId,
            ]
        );

    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}


/*
|--------------------------------------------------------------------------
| Decline Incoming Friend Request
|--------------------------------------------------------------------------
*/

function blackthorne_decline_friend_request(
    PDO $pdo,
    int $recipientUserId,
    int $requesterUserId
): array {
    $friendship =
        blackthorne_friendship_row(
            $pdo,
            $recipientUserId,
            $requesterUserId
        );

    if (
        $friendship === null
        || (string) $friendship['status']
            !== 'pending'
        || (int) $friendship[
            'requested_by_user_id'
        ] !== $requesterUserId
    ) {
        return blackthorne_friend_result(
            false,
            'not_pending_incoming',
            'That incoming friend request is no longer available.'
        );
    }

    $friendshipId =
        (int) $friendship['id'];

    try {
        $pdo->beginTransaction();

        blackthorne_remove_friend_request_notifications(
            $pdo,
            $friendshipId
        );

        $deleteStatement =
            $pdo->prepare(
                'DELETE FROM user_friendships

                 WHERE id = :friendship_id
                   AND status = \'pending\'
                   AND requested_by_user_id = :requester_user_id'
            );

        $deleteStatement->execute([
            'friendship_id' =>
                $friendshipId,

            'requester_user_id' =>
                $requesterUserId,
        ]);

        $pdo->commit();

        return blackthorne_friend_result(
            true,
            'request_declined',
            'Friend request declined.'
        );

    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}


/*
|--------------------------------------------------------------------------
| Unfriend
|--------------------------------------------------------------------------
*/

function blackthorne_unfriend(
    PDO $pdo,
    int $actingUserId,
    int $otherUserId
): array {
    $friendship =
        blackthorne_friendship_row(
            $pdo,
            $actingUserId,
            $otherUserId
        );

    if (
        $friendship === null
        || (string) $friendship['status']
            !== 'accepted'
    ) {
        return blackthorne_friend_result(
            false,
            'not_friends',
            'These accounts are not currently friends.'
        );
    }

    $friendshipId =
        (int) $friendship['id'];

    try {
        $pdo->beginTransaction();

        /*
         * Remove unread acceptance notices associated with a relationship that
         * no longer exists. Historical read notifications remain untouched.
         */
        $cleanupStatement =
            $pdo->prepare(
                'DELETE FROM notifications

                 WHERE related_entity_type = \'friendship\'
                   AND related_entity_id = :friendship_id
                   AND notification_type IN (
                        \'friend_request\',
                        \'friend_accepted\'
                   )
                   AND is_read = 0'
            );

        $cleanupStatement->execute([
            'friendship_id' =>
                $friendshipId,
        ]);

        $deleteStatement =
            $pdo->prepare(
                'DELETE FROM user_friendships

                 WHERE id = :friendship_id
                   AND status = \'accepted\''
            );

        $deleteStatement->execute([
            'friendship_id' =>
                $friendshipId,
        ]);

        $pdo->commit();

        return blackthorne_friend_result(
            true,
            'unfriended',
            'Friend removed.'
        );

    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}


/*
|--------------------------------------------------------------------------
| Block User
|--------------------------------------------------------------------------
|
| A block immediately removes any pending request or accepted friendship.
| Existing read notification history is preserved.
|
*/

function blackthorne_block_user(
    PDO $pdo,
    int $blockerUserId,
    int $blockedUserId,
    ?string $reason = null
): array {
    if (
        $blockerUserId <= 0
        || $blockedUserId <= 0
        || $blockerUserId === $blockedUserId
    ) {
        return blackthorne_friend_result(
            false,
            'invalid_block',
            'That member could not be blocked.'
        );
    }

    if (
        blackthorne_friend_fetch_user(
            $pdo,
            $blockedUserId
        ) === null
    ) {
        return blackthorne_friend_result(
            false,
            'user_unavailable',
            'That member could not be blocked.'
        );
    }

    $reason =
        $reason === null
            ? null
            : trim($reason);

    if ($reason === '') {
        $reason = null;
    }

    if (
        $reason !== null
        && function_exists(
            'mb_substr'
        )
    ) {
        $reason =
            mb_substr(
                $reason,
                0,
                255,
                'UTF-8'
            );
    } elseif ($reason !== null) {
        $reason =
            substr(
                $reason,
                0,
                255
            );
    }

    try {
        $pdo->beginTransaction();

        $friendship =
            blackthorne_friendship_row(
                $pdo,
                $blockerUserId,
                $blockedUserId
            );

        if ($friendship !== null) {
            $friendshipId =
                (int) $friendship['id'];

            $notificationCleanup =
                $pdo->prepare(
                    'DELETE FROM notifications

                     WHERE related_entity_type = \'friendship\'
                       AND related_entity_id = :friendship_id
                       AND is_read = 0'
                );

            $notificationCleanup->execute([
                'friendship_id' =>
                    $friendshipId,
            ]);

            $friendshipDelete =
                $pdo->prepare(
                    'DELETE FROM user_friendships

                     WHERE id = :friendship_id'
                );

            $friendshipDelete->execute([
                'friendship_id' =>
                    $friendshipId,
            ]);
        }

        $existingBlockStatement =
            $pdo->prepare(
                'SELECT id

                 FROM user_blocks

                 WHERE blocker_user_id = :blocker_user_id
                   AND blocked_user_id = :blocked_user_id

                 LIMIT 1

                 FOR UPDATE'
            );

        $existingBlockStatement->execute([
            'blocker_user_id' =>
                $blockerUserId,

            'blocked_user_id' =>
                $blockedUserId,
        ]);

        $existingBlockId =
            $existingBlockStatement
                ->fetchColumn();

        if ($existingBlockId !== false) {
            $updateBlockStatement =
                $pdo->prepare(
                    'UPDATE user_blocks

                     SET
                        block_messages = 1,
                        block_profile_interactions = 1,
                        reason = :reason

                     WHERE id = :block_id'
                );

            $updateBlockStatement->execute([
                'reason' => $reason,
                'block_id' =>
                    (int) $existingBlockId,
            ]);

        } else {
            $insertBlockStatement =
                $pdo->prepare(
                    'INSERT INTO user_blocks (
                        blocker_user_id,
                        blocked_user_id,
                        block_messages,
                        block_profile_interactions,
                        reason
                     ) VALUES (
                        :blocker_user_id,
                        :blocked_user_id,
                        1,
                        1,
                        :reason
                     )'
                );

            $insertBlockStatement->execute([
                'blocker_user_id' =>
                    $blockerUserId,

                'blocked_user_id' =>
                    $blockedUserId,

                'reason' =>
                    $reason,
            ]);
        }

        $pdo->commit();

        return blackthorne_friend_result(
            true,
            'blocked',
            'Member blocked.'
        );

    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}


/*
|--------------------------------------------------------------------------
| Unblock User
|--------------------------------------------------------------------------
*/

function blackthorne_unblock_user(
    PDO $pdo,
    int $blockerUserId,
    int $blockedUserId
): array {
    if (
        $blockerUserId <= 0
        || $blockedUserId <= 0
        || $blockerUserId === $blockedUserId
    ) {
        return blackthorne_friend_result(
            false,
            'invalid_unblock',
            'That member could not be unblocked.'
        );
    }

    $statement =
        $pdo->prepare(
            'DELETE FROM user_blocks

             WHERE blocker_user_id = :blocker_user_id
               AND blocked_user_id = :blocked_user_id'
        );

    $statement->execute([
        'blocker_user_id' =>
            $blockerUserId,

        'blocked_user_id' =>
            $blockedUserId,
    ]);

    if ($statement->rowCount() < 1) {
        return blackthorne_friend_result(
            false,
            'not_blocked',
            'That member was not blocked.'
        );
    }

    return blackthorne_friend_result(
        true,
        'unblocked',
        'Member unblocked.'
    );
}


/*
|--------------------------------------------------------------------------
| Friends List
|--------------------------------------------------------------------------
*/

function blackthorne_friend_list(
    PDO $pdo,
    int $userId,
    int $limit = 0,
    int $offset = 0
): array {
    if ($userId <= 0) {
        return [];
    }

    $limit =
        max(
            0,
            $limit
        );

    $offset =
        max(
            0,
            $offset
        );

    $sql =
        'SELECT
            u.id,
            u.username,
            u.profile_slug,
            u.display_name,
            u.avatar,
            uf.accepted_at

         FROM user_friendships uf

         INNER JOIN users u
            ON u.id =
                CASE
                    WHEN uf.user_low_id = :user_id_case
                        THEN uf.user_high_id
                    ELSE uf.user_low_id
                END

         WHERE uf.status = \'accepted\'
           AND (
                uf.user_low_id = :user_id_low
                OR uf.user_high_id = :user_id_high
           )
           AND u.status = \'active\'

         ORDER BY
            u.display_name ASC,
            u.id ASC';

    if ($limit > 0) {
        $sql .=
            ' LIMIT '
            . $limit
            . ' OFFSET '
            . $offset;
    }

    $statement =
        $pdo->prepare(
            $sql
        );

    $statement->execute([
        'user_id_case' => $userId,
        'user_id_low' => $userId,
        'user_id_high' => $userId,
    ]);

    return $statement->fetchAll(
        PDO::FETCH_ASSOC
    );
}


function blackthorne_friend_count(
    PDO $pdo,
    int $userId
): int {
    if ($userId <= 0) {
        return 0;
    }

    $statement =
        $pdo->prepare(
            'SELECT COUNT(*)

             FROM user_friendships uf

             INNER JOIN users u
                ON u.id =
                    CASE
                        WHEN uf.user_low_id = :user_id_case
                            THEN uf.user_high_id
                        ELSE uf.user_low_id
                    END

             WHERE uf.status = \'accepted\'
               AND (
                    uf.user_low_id = :user_id_low
                    OR uf.user_high_id = :user_id_high
               )
               AND u.status = \'active\''
        );

    $statement->execute([
        'user_id_case' => $userId,
        'user_id_low' => $userId,
        'user_id_high' => $userId,
    ]);

    return
        (int) $statement
            ->fetchColumn();
}


/*
|--------------------------------------------------------------------------
| Pending Request Lists
|--------------------------------------------------------------------------
*/

function blackthorne_incoming_friend_requests(
    PDO $pdo,
    int $userId
): array {
    if ($userId <= 0) {
        return [];
    }

    $statement =
        $pdo->prepare(
            'SELECT
                uf.id AS friendship_id,
                uf.created_at,
                u.id,
                u.username,
                u.profile_slug,
                u.display_name,
                u.avatar

             FROM user_friendships uf

             INNER JOIN users u
                ON u.id = uf.requested_by_user_id

             WHERE uf.status = \'pending\'
               AND uf.requested_by_user_id <> :user_id_requester
               AND (
                    uf.user_low_id = :user_id_low
                    OR uf.user_high_id = :user_id_high
               )
               AND u.status = \'active\'

             ORDER BY
                uf.created_at DESC,
                uf.id DESC'
        );

    $statement->execute([
        'user_id_requester' => $userId,
        'user_id_low' => $userId,
        'user_id_high' => $userId,
    ]);

    return $statement->fetchAll(
        PDO::FETCH_ASSOC
    );
}


function blackthorne_outgoing_friend_requests(
    PDO $pdo,
    int $userId
): array {
    if ($userId <= 0) {
        return [];
    }

    $statement =
        $pdo->prepare(
            'SELECT
                uf.id AS friendship_id,
                uf.created_at,
                u.id,
                u.username,
                u.profile_slug,
                u.display_name,
                u.avatar

             FROM user_friendships uf

             INNER JOIN users u
                ON u.id =
                    CASE
                        WHEN uf.user_low_id = :user_id_case
                            THEN uf.user_high_id
                        ELSE uf.user_low_id
                    END

             WHERE uf.status = \'pending\'
               AND uf.requested_by_user_id = :user_id_requester
               AND (
                    uf.user_low_id = :user_id_low
                    OR uf.user_high_id = :user_id_high
               )
               AND u.status = \'active\'

             ORDER BY
                uf.created_at DESC,
                uf.id DESC'
        );

    $statement->execute([
        'user_id_case' => $userId,
        'user_id_requester' => $userId,
        'user_id_low' => $userId,
        'user_id_high' => $userId,
    ]);

    return $statement->fetchAll(
        PDO::FETCH_ASSOC
    );
}


/*
|--------------------------------------------------------------------------
| Profile Slug Helpers
|--------------------------------------------------------------------------
|
| Slugs are generated from display_name first, then username, then user-ID
| fallback. Existing slugs are never changed automatically after creation.
|
*/

function blackthorne_profile_slug_base(
    string $value
): string {
    $value =
        trim(
            $value
        );

    if ($value === '') {
        return '';
    }

    if (
        function_exists(
            'transliterator_transliterate'
        )
    ) {
        $transliterated =
            transliterator_transliterate(
                'Any-Latin; Latin-ASCII',
                $value
            );

        if (
            is_string(
                $transliterated
            )
            && $transliterated !== ''
        ) {
            $value =
                $transliterated;
        }

    } elseif (
        function_exists(
            'iconv'
        )
    ) {
        $transliterated =
            @iconv(
                'UTF-8',
                'ASCII//TRANSLIT//IGNORE',
                $value
            );

        if (
            is_string(
                $transliterated
            )
            && $transliterated !== ''
        ) {
            $value =
                $transliterated;
        }
    }

    $value =
        strtolower(
            $value
        );

    $value =
        preg_replace(
            '/[^a-z0-9]+/',
            '-',
            $value
        ) ?? '';

    $value =
        trim(
            $value,
            '-'
        );

    if (
        strlen(
            $value
        ) > 140
    ) {
        $value =
            rtrim(
                substr(
                    $value,
                    0,
                    140
                ),
                '-'
            );
    }

    return $value;
}


function blackthorne_profile_generate_unique_slug(
    PDO $pdo,
    int $userId,
    string $displayName,
    string $username
): string {
    $base =
        blackthorne_profile_slug_base(
            $displayName
        );

    if ($base === '') {
        $base =
            blackthorne_profile_slug_base(
                $username
            );
    }

    if ($base === '') {
        $base =
            'member-'
            . $userId;
    }

    $candidate =
        $base;

    $suffix =
        2;

    while (true) {
        $statement =
            $pdo->prepare(
                'SELECT 1

                 FROM users

                 WHERE profile_slug = :profile_slug
                   AND id <> :user_id

                 LIMIT 1'
            );

        $statement->execute([
            'profile_slug' => $candidate,
            'user_id' => $userId,
        ]);

        if (
            $statement->fetchColumn()
            === false
        ) {
            return $candidate;
        }

        $suffixText =
            '-'
            . $suffix;

        $maxBaseLength =
            160
            - strlen(
                $suffixText
            );

        $trimmedBase =
            rtrim(
                substr(
                    $base,
                    0,
                    $maxBaseLength
                ),
                '-'
            );

        $candidate =
            $trimmedBase
            . $suffixText;

        $suffix++;
    }
}


function blackthorne_profile_ensure_slug(
    PDO $pdo,
    int $userId
): ?string {
    if ($userId <= 0) {
        return null;
    }

    $userStatement =
        $pdo->prepare(
            'SELECT
                id,
                username,
                display_name,
                profile_slug

             FROM users

             WHERE id = :user_id

             LIMIT 1'
        );

    $userStatement->execute([
        'user_id' => $userId,
    ]);

    $user =
        $userStatement->fetch(
            PDO::FETCH_ASSOC
        );

    if (!is_array($user)) {
        return null;
    }

    $existingSlug =
        trim(
            (string) (
                $user['profile_slug']
                ?? ''
            )
        );

    if ($existingSlug !== '') {
        return $existingSlug;
    }

    /*
     * Lock the user row during creation so the same account cannot be assigned
     * two different slugs by simultaneous requests.
     */
    try {
        $pdo->beginTransaction();

        $lockStatement =
            $pdo->prepare(
                'SELECT
                    username,
                    display_name,
                    profile_slug

                 FROM users

                 WHERE id = :user_id

                 LIMIT 1

                 FOR UPDATE'
            );

        $lockStatement->execute([
            'user_id' => $userId,
        ]);

        $lockedUser =
            $lockStatement->fetch(
                PDO::FETCH_ASSOC
            );

        if (!is_array($lockedUser)) {
            $pdo->rollBack();

            return null;
        }

        $lockedSlug =
            trim(
                (string) (
                    $lockedUser['profile_slug']
                    ?? ''
                )
            );

        if ($lockedSlug !== '') {
            $pdo->commit();

            return $lockedSlug;
        }

        $slug =
            blackthorne_profile_generate_unique_slug(
                $pdo,
                $userId,
                (string) $lockedUser[
                    'display_name'
                ],
                (string) $lockedUser[
                    'username'
                ]
            );

        $updateStatement =
            $pdo->prepare(
                'UPDATE users

                 SET profile_slug = :profile_slug

                 WHERE id = :user_id
                   AND profile_slug IS NULL'
            );

        $updateStatement->execute([
            'profile_slug' => $slug,
            'user_id' => $userId,
        ]);

        $pdo->commit();

        return $slug;

    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        /*
         * If a unique-key race happened, fetch the slug that won.
         */
        $retryStatement =
            $pdo->prepare(
                'SELECT profile_slug

                 FROM users

                 WHERE id = :user_id

                 LIMIT 1'
            );

        $retryStatement->execute([
            'user_id' => $userId,
        ]);

        $retrySlug =
            $retryStatement
                ->fetchColumn();

        if (
            is_string(
                $retrySlug
            )
            && trim(
                $retrySlug
            ) !== ''
        ) {
            return trim(
                $retrySlug
            );
        }

        throw $exception;
    }
}
