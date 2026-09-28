<?php

declare(strict_types=1);

/**
 * Blackthorne Academy
 * Dynamic Achievement Service
 *
 * Upload path:
 * /includes/achievement-functions.php
 *
 * This service owns achievement-definition matching and achievement awards.
 * It does not hard-code individual achievements. Staff may create additional
 * achievements later by reusing an event/trigger type already emitted by the
 * site and configuring its threshold, target, repeat mode, and reward in the
 * database.
 *
 * Point rewards are always sent through the central Points service. Achievement
 * code must never write directly to points_ledger.
 */

require_once __DIR__ . '/points-functions.php';


/*
|--------------------------------------------------------------------------
| Supported Repeat Modes
|--------------------------------------------------------------------------
|
| requirement_type is intentionally open-ended in the database. repeat_mode
| is also stored as VARCHAR so we can add behavior later without another enum
| migration. These are the modes understood by this version of the service.
|
*/

function achievements_supported_repeat_modes(): array
{
    return [
        'once_ever',
        'once_per_school_year',
        'once_per_source',
        'once_per_target',
        'repeatable',
    ];
}


/*
|--------------------------------------------------------------------------
| Small Normalization Helpers
|--------------------------------------------------------------------------
*/

function achievements_normalize_trigger_type(string $triggerType): string
{
    $triggerType = strtolower(trim($triggerType));

    if ($triggerType === '') {
        throw new InvalidArgumentException(
            'Achievement trigger type cannot be empty.'
        );
    }

    if (strlen($triggerType) > 100) {
        throw new InvalidArgumentException(
            'Achievement trigger type is too long.'
        );
    }

    return $triggerType;
}


function achievements_normalize_source_type(?string $sourceType): ?string
{
    if ($sourceType === null) {
        return null;
    }

    $sourceType = strtolower(trim($sourceType));

    if ($sourceType === '') {
        return null;
    }

    if (strlen($sourceType) > 50) {
        throw new InvalidArgumentException(
            'Achievement source type is too long.'
        );
    }

    return $sourceType;
}


function achievements_normalize_award_key(string $awardKey): string
{
    $awardKey = trim($awardKey);

    if ($awardKey === '') {
        throw new InvalidArgumentException(
            'Achievement award key cannot be empty.'
        );
    }

    if (strlen($awardKey) > 191) {
        throw new InvalidArgumentException(
            'Achievement award key is too long.'
        );
    }

    return $awardKey;
}


/*
|--------------------------------------------------------------------------
| Achievement Definition Access
|--------------------------------------------------------------------------
*/

function achievements_definition_by_id(
    PDO $pdo,
    int $achievementId,
    bool $activeOnly = false
): ?array {
    if ($achievementId <= 0) {
        return null;
    }

    $sql =
        'SELECT
            id,
            name,
            slug,
            description,
            badge_image,
            requirement_type,
            requirement_value,
            threshold_value,
            target_type,
            target_id,
            points_awarded,
            repeat_mode,
            trigger_config,
            is_active,
            sort_order,
            created_at,
            updated_at
         FROM achievements
         WHERE id = :achievement_id';

    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }

    $sql .= ' LIMIT 1';

    $statement = $pdo->prepare($sql);
    $statement->execute([
        'achievement_id' => $achievementId,
    ]);

    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return is_array($row)
        ? $row
        : null;
}


function achievements_definition_by_slug(
    PDO $pdo,
    string $slug,
    bool $activeOnly = false
): ?array {
    $slug = trim($slug);

    if ($slug === '') {
        return null;
    }

    $sql =
        'SELECT
            id,
            name,
            slug,
            description,
            badge_image,
            requirement_type,
            requirement_value,
            threshold_value,
            target_type,
            target_id,
            points_awarded,
            repeat_mode,
            trigger_config,
            is_active,
            sort_order,
            created_at,
            updated_at
         FROM achievements
         WHERE slug = :slug';

    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }

    $sql .= ' LIMIT 1';

    $statement = $pdo->prepare($sql);
    $statement->execute([
        'slug' => $slug,
    ]);

    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return is_array($row)
        ? $row
        : null;
}


function achievements_active_for_trigger(
    PDO $pdo,
    string $triggerType
): array {
    $triggerType = achievements_normalize_trigger_type(
        $triggerType
    );

    $statement = $pdo->prepare(
        'SELECT
            id,
            name,
            slug,
            description,
            badge_image,
            requirement_type,
            requirement_value,
            threshold_value,
            target_type,
            target_id,
            points_awarded,
            repeat_mode,
            trigger_config,
            is_active,
            sort_order,
            created_at,
            updated_at
         FROM achievements
         WHERE requirement_type = :requirement_type
           AND is_active = 1
         ORDER BY
            sort_order ASC,
            id ASC'
    );

    $statement->execute([
        'requirement_type' => $triggerType,
    ]);

    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}


/*
|--------------------------------------------------------------------------
| Trigger Config Parsing
|--------------------------------------------------------------------------
*/

function achievements_trigger_config(array $achievement): array
{
    $raw = $achievement['trigger_config'] ?? null;

    if ($raw === null || $raw === '') {
        return [];
    }

    if (is_array($raw)) {
        return $raw;
    }

    if (!is_string($raw)) {
        return [];
    }

    try {
        $decoded = json_decode(
            $raw,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException) {
        return [];
    }

    return is_array($decoded)
        ? $decoded
        : [];
}


/*
|--------------------------------------------------------------------------
| Resolve School Year For Achievement Award
|--------------------------------------------------------------------------
*/

function achievements_resolve_school_year_id(
    PDO $pdo,
    ?int $requestedSchoolYearId = null,
    bool $required = false
): ?int {
    if (($requestedSchoolYearId ?? 0) > 0) {
        $schoolYear = points_school_year(
            $pdo,
            (int) $requestedSchoolYearId
        );

        if ($schoolYear === null) {
            throw new InvalidArgumentException(
                'The requested School Year does not exist.'
            );
        }

        return (int) $schoolYear['id'];
    }

    $currentSchoolYear = points_current_school_year($pdo);

    if ($currentSchoolYear !== null) {
        return (int) $currentSchoolYear['id'];
    }

    if ($required) {
        throw new RuntimeException(
            'No current School Year is available for this achievement.'
        );
    }

    return null;
}


/*
|--------------------------------------------------------------------------
| Event Context Helpers
|--------------------------------------------------------------------------
|
| Recommended event context shape:
|
| [
|     'value' => 25,
|     'school_year_id' => 3,
|     'source_type' => 'forum_post',
|     'source_id' => 123,
|     'target_type' => 'course',
|     'target_id' => 7,
|     'targets' => [
|         'course' => 7,
|         'offering' => 12,
|     ],
|     'properties' => [
|         'assessment_type' => 'quiz',
|     ],
|     'award_key' => 'optional-explicit-key',
| ]
|
| target_type/target_id are the primary target. targets allows the same event
| to expose several entity IDs so definitions can choose what they target.
|
*/

function achievements_context_target_id(
    array $context,
    string $targetType
): ?int {
    $targetType = strtolower(trim($targetType));

    if ($targetType === '') {
        return null;
    }

    $targets = $context['targets'] ?? null;

    if (is_array($targets) && array_key_exists($targetType, $targets)) {
        $value = (int) $targets[$targetType];

        return $value > 0
            ? $value
            : null;
    }

    $contextTargetType = strtolower(
        trim((string) ($context['target_type'] ?? ''))
    );

    if ($contextTargetType !== $targetType) {
        return null;
    }

    $value = (int) ($context['target_id'] ?? 0);

    return $value > 0
        ? $value
        : null;
}


function achievements_context_property(
    array $context,
    string $key
): mixed {
    if (array_key_exists($key, $context)) {
        return $context[$key];
    }

    $properties = $context['properties'] ?? null;

    if (is_array($properties) && array_key_exists($key, $properties)) {
        return $properties[$key];
    }

    return null;
}


/*
|--------------------------------------------------------------------------
| Threshold Comparison
|--------------------------------------------------------------------------
*/

function achievements_compare_threshold(
    float $actual,
    float $required,
    string $comparison
): bool {
    return match ($comparison) {
        'gt' => $actual > $required,
        'eq' => abs($actual - $required) < 0.00001,
        'lte' => $actual <= $required,
        'lt' => $actual < $required,
        'gte' => $actual >= $required,
        default => $actual >= $required,
    };
}


/*
|--------------------------------------------------------------------------
| Definition/Event Match
|--------------------------------------------------------------------------
|
| Generic matching rules make individual achievements data-driven:
|
| 1. requirement_type must equal the emitted trigger type.
| 2. If target_type is configured, the event must expose that target.
| 3. If target_id is configured, the event's matching target must equal it.
| 4. If threshold_value is configured, context.value is compared to it.
| 5. trigger_config.conditions may contain exact-match event properties.
| 6. trigger_config.comparison may be gte, gt, eq, lte, or lt.
|
| Adding another achievement using an existing trigger requires only a new
| database row. A new kind of site event still needs a hook that emits it.
|
*/

function achievements_definition_matches_event(
    array $achievement,
    string $triggerType,
    array $context = []
): bool {
    $triggerType = achievements_normalize_trigger_type(
        $triggerType
    );

    if ((int) ($achievement['is_active'] ?? 0) !== 1) {
        return false;
    }

    $definitionTrigger = achievements_normalize_trigger_type(
        (string) ($achievement['requirement_type'] ?? '')
    );

    if ($definitionTrigger !== $triggerType) {
        return false;
    }

    $configuredTargetType = strtolower(
        trim((string) ($achievement['target_type'] ?? ''))
    );

    if ($configuredTargetType !== '') {
        $eventTargetId = achievements_context_target_id(
            $context,
            $configuredTargetType
        );

        if ($eventTargetId === null) {
            return false;
        }

        $configuredTargetId =
            isset($achievement['target_id'])
                ? (int) $achievement['target_id']
                : 0;

        if (
            $configuredTargetId > 0
            && $eventTargetId !== $configuredTargetId
        ) {
            return false;
        }
    }

    $config = achievements_trigger_config($achievement);

    if ($achievement['threshold_value'] !== null) {
        $actualValue = achievements_context_property(
            $context,
            'value'
        );

        if (!is_numeric($actualValue)) {
            return false;
        }

        $comparison = strtolower(
            trim((string) ($config['comparison'] ?? 'gte'))
        );

        if (!achievements_compare_threshold(
            (float) $actualValue,
            (float) $achievement['threshold_value'],
            $comparison
        )) {
            return false;
        }
    }

    $conditions = $config['conditions'] ?? [];

    if (is_array($conditions)) {
        foreach ($conditions as $key => $expectedValue) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            $actualValue = achievements_context_property(
                $context,
                $key
            );

            if (is_array($expectedValue)) {
                $matched = false;

                foreach ($expectedValue as $candidate) {
                    if ((string) $actualValue === (string) $candidate) {
                        $matched = true;
                        break;
                    }
                }

                if (!$matched) {
                    return false;
                }

                continue;
            }

            if ((string) $actualValue !== (string) $expectedValue) {
                return false;
            }
        }
    }

    return true;
}


/*
|--------------------------------------------------------------------------
| Award Key Construction
|--------------------------------------------------------------------------
*/

function achievements_build_award_key(
    array $achievement,
    array $context,
    ?int $schoolYearId,
    ?string $sourceType,
    ?int $sourceId
): string {
    $repeatMode = strtolower(
        trim((string) ($achievement['repeat_mode'] ?? 'once_ever'))
    );

    if (!in_array(
        $repeatMode,
        achievements_supported_repeat_modes(),
        true
    )) {
        throw new RuntimeException(
            'Achievement "' .
            (string) ($achievement['name'] ?? 'Unknown') .
            '" uses an unsupported repeat mode.'
        );
    }

    if ($repeatMode === 'once_ever') {
        return 'ever';
    }

    if ($repeatMode === 'once_per_school_year') {
        if (($schoolYearId ?? 0) <= 0) {
            throw new RuntimeException(
                'A School Year is required for a once-per-school-year achievement.'
            );
        }

        return achievements_normalize_award_key(
            'school_year:' . (int) $schoolYearId
        );
    }

    if ($repeatMode === 'once_per_source') {
        if ($sourceType === null || ($sourceId ?? 0) <= 0) {
            throw new RuntimeException(
                'A source type and source ID are required for a once-per-source achievement.'
            );
        }

        return achievements_normalize_award_key(
            'source:' . $sourceType . ':' . (int) $sourceId
        );
    }

    if ($repeatMode === 'once_per_target') {
        $targetType = strtolower(
            trim((string) ($achievement['target_type'] ?? ''))
        );

        if ($targetType === '') {
            throw new RuntimeException(
                'A target type is required for a once-per-target achievement.'
            );
        }

        $targetId = achievements_context_target_id(
            $context,
            $targetType
        );

        if (($targetId ?? 0) <= 0) {
            throw new RuntimeException(
                'A target ID is required for a once-per-target achievement.'
            );
        }

        return achievements_normalize_award_key(
            'target:' . $targetType . ':' . (int) $targetId
        );
    }

    /* repeatable */
    $explicitAwardKey = trim(
        (string) ($context['award_key'] ?? '')
    );

    if ($explicitAwardKey !== '') {
        return achievements_normalize_award_key(
            $explicitAwardKey
        );
    }

    if ($sourceType !== null && ($sourceId ?? 0) > 0) {
        return achievements_normalize_award_key(
            'source:' . $sourceType . ':' . (int) $sourceId
        );
    }

    throw new RuntimeException(
        'Repeatable achievements require a deterministic award key or source.'
    );
}


/*
|--------------------------------------------------------------------------
| Earned Achievement Lookup
|--------------------------------------------------------------------------
*/

function achievements_earned_occurrence(
    PDO $pdo,
    int $userId,
    int $achievementId,
    string $awardKey
): ?array {
    if ($userId <= 0 || $achievementId <= 0) {
        return null;
    }

    $awardKey = achievements_normalize_award_key(
        $awardKey
    );

    $statement = $pdo->prepare(
        'SELECT
            ua.id,
            ua.user_id,
            ua.achievement_id,
            ua.school_year_id,
            ua.source_type,
            ua.source_id,
            ua.award_key,
            ua.earned_at,
            ua.awarded_by,
            ua.notes,
            a.name,
            a.slug,
            a.description,
            a.badge_image,
            a.points_awarded,
            a.repeat_mode
         FROM user_achievements ua
         INNER JOIN achievements a
            ON a.id = ua.achievement_id
         WHERE ua.user_id = :user_id
           AND ua.achievement_id = :achievement_id
           AND ua.award_key = :award_key
         LIMIT 1'
    );

    $statement->execute([
        'user_id' => $userId,
        'achievement_id' => $achievementId,
        'award_key' => $awardKey,
    ]);

    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return is_array($row)
        ? $row
        : null;
}


function achievements_earned_by_id(
    PDO $pdo,
    int $userAchievementId
): ?array {
    if ($userAchievementId <= 0) {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT
            ua.id,
            ua.user_id,
            ua.achievement_id,
            ua.school_year_id,
            ua.source_type,
            ua.source_id,
            ua.award_key,
            ua.earned_at,
            ua.awarded_by,
            ua.notes,
            a.name,
            a.slug,
            a.description,
            a.badge_image,
            a.points_awarded,
            a.repeat_mode
         FROM user_achievements ua
         INNER JOIN achievements a
            ON a.id = ua.achievement_id
         WHERE ua.id = :user_achievement_id
         LIMIT 1'
    );

    $statement->execute([
        'user_achievement_id' => $userAchievementId,
    ]);

    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return is_array($row)
        ? $row
        : null;
}


/*
|--------------------------------------------------------------------------
| Notification Preference
|--------------------------------------------------------------------------
*/

function achievements_notifications_enabled(
    PDO $pdo,
    int $userId
): bool {
    if ($userId <= 0) {
        return false;
    }

    $statement = $pdo->prepare(
        'SELECT notify_achievement_earned
         FROM notification_preferences
         WHERE user_id = :user_id
         LIMIT 1'
    );

    $statement->execute([
        'user_id' => $userId,
    ]);

    $value = $statement->fetchColumn();

    /* No preference row means the site defaults remain enabled. */
    if ($value === false) {
        return true;
    }

    return (int) $value === 1;
}


function achievements_create_notification(
    PDO $pdo,
    array $earnedAchievement
): void {
    $userId = (int) ($earnedAchievement['user_id'] ?? 0);
    $userAchievementId = (int) ($earnedAchievement['id'] ?? 0);

    if ($userId <= 0 || $userAchievementId <= 0) {
        return;
    }

    if (!achievements_notifications_enabled($pdo, $userId)) {
        return;
    }

    $duplicateStatement = $pdo->prepare(
        'SELECT 1
         FROM notifications
         WHERE user_id = :user_id
           AND notification_type = \'achievement_earned\'
           AND related_entity_type = \'user_achievement\'
           AND related_entity_id = :user_achievement_id
         LIMIT 1'
    );

    $duplicateStatement->execute([
        'user_id' => $userId,
        'user_achievement_id' => $userAchievementId,
    ]);

    if ($duplicateStatement->fetchColumn() !== false) {
        return;
    }

    $name = trim(
        (string) ($earnedAchievement['name'] ?? 'Achievement')
    );

    $pointsAwarded = (int) (
        $earnedAchievement['points_awarded']
        ?? 0
    );

    $message = 'You earned the achievement “' . $name . '.”';

    if ($pointsAwarded > 0) {
        $message .= ' +' . $pointsAwarded . ' House Points were awarded.';
    }

    $actorUserId = (int) (
        $earnedAchievement['awarded_by']
        ?? 0
    );

    if ($actorUserId <= 0) {
        $actorUserId = null;
    }

    $linkUrl = function_exists('url')
        ? url('profile.php?u=' . $userId)
        : '/profile.php?u=' . $userId;

    $statement = $pdo->prepare(
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
            \'achievement_earned\',
            \'user_achievement\',
            :related_entity_id,
            :title,
            :message,
            :link_url,
            0,
            NULL
         )'
    );

    $statement->execute([
        'user_id' => $userId,
        'actor_user_id' => $actorUserId,
        'related_entity_id' => $userAchievementId,
        'title' => 'Achievement Earned',
        'message' => $message,
        'link_url' => $linkUrl,
    ]);
}


/*
|--------------------------------------------------------------------------
| Award Achievement Definition
|--------------------------------------------------------------------------
|
| Supported $context keys:
| - school_year_id ?int
| - source_type ?string
| - source_id ?int
| - target_type ?string
| - target_id ?int
| - targets array<string,int>
| - value int|float|string
| - properties array
| - award_key ?string
| - awarded_by ?int
| - notes ?string
| - notify bool (default true)
|
| Returns:
| - achievement => definition
| - earned => earned occurrence
| - created => bool
| - duplicate => bool
| - points => result from points_award(), or null
|
*/

function achievements_award_definition(
    PDO $pdo,
    int $userId,
    array $achievement,
    array $context = []
): array {
    if ($userId <= 0 || !points_user_exists($pdo, $userId)) {
        throw new InvalidArgumentException(
            'A valid user is required to award an achievement.'
        );
    }

    $achievementId = (int) ($achievement['id'] ?? 0);

    if ($achievementId <= 0) {
        throw new InvalidArgumentException(
            'A valid achievement definition is required.'
        );
    }

    if ((int) ($achievement['is_active'] ?? 0) !== 1) {
        throw new RuntimeException(
            'Inactive achievements cannot be awarded.'
        );
    }

    $sourceType = achievements_normalize_source_type(
        isset($context['source_type'])
            ? (string) $context['source_type']
            : null
    );

    $sourceId = isset($context['source_id'])
        ? (int) $context['source_id']
        : null;

    if (($sourceId ?? 0) <= 0) {
        $sourceId = null;
    }

    $awardedBy = isset($context['awarded_by'])
        ? (int) $context['awarded_by']
        : null;

    if (($awardedBy ?? 0) <= 0) {
        $awardedBy = null;
    }

    if (
        $awardedBy !== null
        && !points_user_exists($pdo, $awardedBy)
    ) {
        throw new InvalidArgumentException(
            'The awarding staff member does not exist.'
        );
    }

    $pointsAwarded = (int) (
        $achievement['points_awarded']
        ?? 0
    );

    $repeatMode = strtolower(
        trim((string) ($achievement['repeat_mode'] ?? 'once_ever'))
    );

    $schoolYearRequired =
        $pointsAwarded > 0
        || $repeatMode === 'once_per_school_year';

    $schoolYearId = achievements_resolve_school_year_id(
        $pdo,
        isset($context['school_year_id'])
            ? (int) $context['school_year_id']
            : null,
        $schoolYearRequired
    );

    $awardKey = achievements_build_award_key(
        $achievement,
        $context,
        $schoolYearId,
        $sourceType,
        $sourceId
    );

    $existing = achievements_earned_occurrence(
        $pdo,
        $userId,
        $achievementId,
        $awardKey
    );

    if ($existing !== null) {
        return [
            'achievement' => $achievement,
            'earned' => $existing,
            'created' => false,
            'duplicate' => true,
            'points' => null,
        ];
    }

    $notes = isset($context['notes'])
        ? trim((string) $context['notes'])
        : null;

    if ($notes === '') {
        $notes = null;
    }

    $ownsTransaction = !$pdo->inTransaction();

    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $statement = $pdo->prepare(
            'INSERT INTO user_achievements (
                user_id,
                achievement_id,
                school_year_id,
                source_type,
                source_id,
                award_key,
                awarded_by,
                notes
             ) VALUES (
                :user_id,
                :achievement_id,
                :school_year_id,
                :source_type,
                :source_id,
                :award_key,
                :awarded_by,
                :notes
             )'
        );

        $statement->execute([
            'user_id' => $userId,
            'achievement_id' => $achievementId,
            'school_year_id' => $schoolYearId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'award_key' => $awardKey,
            'awarded_by' => $awardedBy,
            'notes' => $notes,
        ]);

        $userAchievementId = (int) $pdo->lastInsertId();

        $pointResult = null;

        if ($pointsAwarded > 0) {
            $config = achievements_trigger_config(
                $achievement
            );

            $pointResult = points_award(
                $pdo,
                $userId,
                'house_only',
                'achievement',
                $pointsAwarded,
                [
                    'school_year_id' => $schoolYearId,
                    'source_id' => $userAchievementId,
                    'description' =>
                        'Achievement: ' .
                        (string) $achievement['name'],
                    'is_public' =>
                        array_key_exists('points_public', $config)
                            ? (bool) $config['points_public']
                            : true,
                    'awarded_by' => $awardedBy,
                ]
            );
        }

        $earned = achievements_earned_by_id(
            $pdo,
            $userAchievementId
        );

        if ($earned === null) {
            throw new RuntimeException(
                'The achievement was created but could not be reloaded.'
            );
        }

        if (!empty($context['notify']) || !array_key_exists('notify', $context)) {
            /*
             * Notifications are useful but must never invalidate an earned
             * achievement. A notification failure is intentionally ignored.
             */
            try {
                achievements_create_notification(
                    $pdo,
                    $earned
                );
            } catch (Throwable) {
                // Best-effort only.
            }
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }

        return [
            'achievement' => $achievement,
            'earned' => $earned,
            'created' => true,
            'duplicate' => false,
            'points' => $pointResult,
        ];
    } catch (PDOException $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $mysqlErrorCode =
            isset($e->errorInfo[1])
                ? (int) $e->errorInfo[1]
                : 0;

        if ($mysqlErrorCode === 1062) {
            $existing = achievements_earned_occurrence(
                $pdo,
                $userId,
                $achievementId,
                $awardKey
            );

            if ($existing !== null) {
                return [
                    'achievement' => $achievement,
                    'earned' => $existing,
                    'created' => false,
                    'duplicate' => true,
                    'points' => null,
                ];
            }
        }

        throw $e;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}


/*
|--------------------------------------------------------------------------
| Process Dynamic Achievement Event
|--------------------------------------------------------------------------
|
| This is the function normal site features will call after an event happens.
| For example, a forum-post hook can emit "forum_post_count" with value 25.
| Every active definition using that trigger is evaluated automatically.
|
| Achievement failures are isolated from the original site action. A forum
| post, course completion, or grading action must not fail merely because one
| achievement definition is misconfigured. Errors are returned for logging or
| administration instead of being thrown from this dispatcher.
|
*/

function achievements_process_event(
    PDO $pdo,
    int $userId,
    string $triggerType,
    array $context = []
): array {
    if ($userId <= 0 || !points_user_exists($pdo, $userId)) {
        return [
            'trigger_type' => $triggerType,
            'evaluated' => 0,
            'matched' => 0,
            'created' => 0,
            'duplicates' => 0,
            'awards' => [],
            'errors' => [
                'A valid user is required to process achievements.',
            ],
        ];
    }

    try {
        $triggerType = achievements_normalize_trigger_type(
            $triggerType
        );
    } catch (Throwable $e) {
        return [
            'trigger_type' => trim($triggerType),
            'evaluated' => 0,
            'matched' => 0,
            'created' => 0,
            'duplicates' => 0,
            'awards' => [],
            'errors' => [$e->getMessage()],
        ];
    }

    $definitions = achievements_active_for_trigger(
        $pdo,
        $triggerType
    );

    $result = [
        'trigger_type' => $triggerType,
        'evaluated' => count($definitions),
        'matched' => 0,
        'created' => 0,
        'duplicates' => 0,
        'awards' => [],
        'errors' => [],
    ];

    foreach ($definitions as $definition) {
        try {
            if (!achievements_definition_matches_event(
                $definition,
                $triggerType,
                $context
            )) {
                continue;
            }

            $result['matched']++;

            $award = achievements_award_definition(
                $pdo,
                $userId,
                $definition,
                $context
            );

            if (!empty($award['created'])) {
                $result['created']++;
            }

            if (!empty($award['duplicate'])) {
                $result['duplicates']++;
            }

            $result['awards'][] = $award;
        } catch (Throwable $e) {
            $result['errors'][] = [
                'achievement_id' =>
                    (int) ($definition['id'] ?? 0),
                'achievement_slug' =>
                    (string) ($definition['slug'] ?? ''),
                'message' => $e->getMessage(),
            ];
        }
    }

    return $result;
}


/*
|--------------------------------------------------------------------------
| Manual Achievement Awards
|--------------------------------------------------------------------------
|
| Manual awards are allowed when:
| - the achievement itself uses requirement_type = manual, OR
| - trigger_config.allow_manual_award = true
|
| The actor must have achievements.award through the existing capability
| resolver. Protected Super Admin remains covered by that resolver.
|
*/

function achievements_manual_award_allowed(
    array $achievement
): bool {
    if (
        strtolower(
            trim((string) ($achievement['requirement_type'] ?? ''))
        ) === 'manual'
    ) {
        return true;
    }

    $config = achievements_trigger_config($achievement);

    return !empty($config['allow_manual_award']);
}


function achievements_award_manually(
    PDO $pdo,
    int $userId,
    int $achievementId,
    int $awardedBy,
    ?string $notes = null,
    array $context = []
): array {
    if ($awardedBy <= 0) {
        throw new InvalidArgumentException(
            'The staff member awarding the achievement is required.'
        );
    }

    if (!function_exists('user_can_by_id')) {
        throw new RuntimeException(
            'The global permission service must be loaded before manual achievement awards.'
        );
    }

    if (!user_can_by_id($awardedBy, 'achievements.award')) {
        throw new RuntimeException(
            'This staff member does not have permission to award achievements.'
        );
    }

    $achievement = achievements_definition_by_id(
        $pdo,
        $achievementId,
        true
    );

    if ($achievement === null) {
        throw new InvalidArgumentException(
            'The achievement does not exist or is inactive.'
        );
    }

    if (!achievements_manual_award_allowed($achievement)) {
        throw new RuntimeException(
            'This achievement is not configured for manual awarding.'
        );
    }

    $context['awarded_by'] = $awardedBy;
    $context['source_type'] =
        achievements_normalize_source_type(
            isset($context['source_type'])
                ? (string) $context['source_type']
                : 'manual'
        );

    if ($notes !== null) {
        $context['notes'] = $notes;
    }

    return achievements_award_definition(
        $pdo,
        $userId,
        $achievement,
        $context
    );
}


/*
|--------------------------------------------------------------------------
| User Achievement History
|--------------------------------------------------------------------------
*/

function achievements_user_history(
    PDO $pdo,
    int $userId,
    ?int $schoolYearId = null,
    int $limit = 100,
    int $offset = 0
): array {
    if ($userId <= 0) {
        return [];
    }

    $limit = max(1, min($limit, 500));
    $offset = max(0, $offset);

    $sql =
        'SELECT
            ua.id,
            ua.user_id,
            ua.achievement_id,
            ua.school_year_id,
            ua.source_type,
            ua.source_id,
            ua.award_key,
            ua.earned_at,
            ua.awarded_by,
            ua.notes,
            a.name,
            a.slug,
            a.description,
            a.badge_image,
            a.points_awarded,
            a.repeat_mode,
            sy.name AS school_year_name
         FROM user_achievements ua
         INNER JOIN achievements a
            ON a.id = ua.achievement_id
         LEFT JOIN school_years sy
            ON sy.id = ua.school_year_id
         WHERE ua.user_id = :user_id';

    $params = [
        'user_id' => $userId,
    ];

    if (($schoolYearId ?? 0) > 0) {
        $sql .= ' AND ua.school_year_id = :school_year_id';
        $params['school_year_id'] = (int) $schoolYearId;
    }

    $sql .=
        ' ORDER BY
            ua.earned_at DESC,
            ua.id DESC
          LIMIT ' . $limit .
        ' OFFSET ' . $offset;

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
