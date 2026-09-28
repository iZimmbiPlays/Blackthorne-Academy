<?php

declare(strict_types=1);

/**
 * Blackthorne Academy
 * Sorting Ceremony Engine
 *
 * Shared server-side logic for the student Sorting Ceremony.
 *
 * This file intentionally contains no page markup. It is responsible for:
 *  - published ceremony lookup
 *  - attempt creation/resume
 *  - one-question-at-a-time progression
 *  - immutable submitted responses
 *  - interludes
 *  - hidden House scoring
 *  - tie detection and The Choosing
 *  - transactional final House assignment
 *  - completion/reveal data
 *
 * Expected usage:
 *
 *   require_once __DIR__ . '/includes/bootstrap.php';
 *   require_once INCLUDES_PATH . '/sorting-ceremony.php';
 */

require_once INCLUDES_PATH . '/house-functions.php';
require_once INCLUDES_PATH . '/achievement-functions.php';

class SortingCeremonyException extends RuntimeException
{
}

final class SortingCeremonyConfigurationException extends SortingCeremonyException
{
}

final class SortingCeremonyStateException extends SortingCeremonyException
{
}

/*
|--------------------------------------------------------------------------
| Small internal helpers
|--------------------------------------------------------------------------
*/

function sorting_ceremony_json_encode(array $value): string
{
    try {
        return json_encode($value, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new SortingCeremonyException(
            'The Sorting Ceremony could not store its internal state.',
            0,
            $exception
        );
    }
}

function sorting_ceremony_json_array(mixed $value): array
{
    if (!is_string($value) || trim($value) === '') {
        return [];
    }

    try {
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return [];
    }

    return is_array($decoded) ? $decoded : [];
}

function sorting_ceremony_normalize_house_ids(array $houseIds): array
{
    $normalized = [];

    foreach ($houseIds as $houseId) {
        if (is_int($houseId) || (is_string($houseId) && ctype_digit($houseId))) {
            $houseId = (int) $houseId;

            if ($houseId > 0) {
                $normalized[] = $houseId;
            }
        }
    }

    $normalized = array_values(array_unique($normalized));
    sort($normalized, SORT_NUMERIC);

    return $normalized;
}

function sorting_ceremony_attempt_row(int $attemptId, ?int $userId = null, bool $forUpdate = false): ?array
{
    global $pdo;

    if ($attemptId <= 0) {
        return null;
    }

    $sql =
        'SELECT sa.*, scv.version_number, scv.name AS ceremony_name,
                scv.status AS ceremony_version_status,
                scv.intro_title, scv.intro_text, scv.reveal_lead_text
         FROM sorting_attempts sa
         INNER JOIN sorting_ceremony_versions scv
            ON scv.id = sa.ceremony_version_id
         WHERE sa.id = :attempt_id';

    $params = [
        'attempt_id' => $attemptId,
    ];

    if ($userId !== null) {
        $sql .= ' AND sa.user_id = :user_id';
        $params['user_id'] = $userId;
    }

    $sql .= ' LIMIT 1';

    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function sorting_ceremony_touch_attempt(int $attemptId): void
{
    global $pdo;

    $statement = $pdo->prepare(
        'UPDATE sorting_attempts
         SET last_activity_at = NOW()
         WHERE id = :attempt_id'
    );
    $statement->execute([
        'attempt_id' => $attemptId,
    ]);
}

/**
 * Emits the dynamic achievement event for a completed Sorting Ceremony.
 *
 * Achievement processing is intentionally best-effort here. House assignment
 * is the primary operation and must remain valid even if no School Year is
 * current yet, an achievement definition is misconfigured, or a notification
 * cannot be created. Duplicate protection in the achievement service makes it
 * safe to call this helper more than once.
 */
function sorting_ceremony_process_completion_achievements(array $attempt): void
{
    global $pdo;

    if ((string) ($attempt['status'] ?? '') !== 'completed') {
        return;
    }

    $userId = (int) ($attempt['user_id'] ?? 0);
    $houseId = (int) ($attempt['final_house_id'] ?? 0);
    $membershipId = (int) ($attempt['house_membership_id'] ?? 0);

    if ($userId <= 0 || $houseId <= 0 || $membershipId <= 0) {
        return;
    }

    /*
     * Do not introduce achievement writes into an unrelated caller-owned
     * transaction. The normal ceremony submission functions call this after
     * their own commit, and reveal_data() provides an idempotent fallback.
     */
    if ($pdo->inTransaction()) {
        return;
    }

    try {
        achievements_process_event(
            $pdo,
            $userId,
            'house_sorting',
            [
                'value' => 1,
                'source_type' => 'house_membership',
                'source_id' => $membershipId,
                'target_type' => 'house',
                'target_id' => $houseId,
                'targets' => [
                    'house' => $houseId,
                ],
                'properties' => [
                    'result_method' => (string) ($attempt['result_method'] ?? ''),
                    'sorting_attempt_id' => (int) ($attempt['id'] ?? 0),
                    'house_membership_id' => $membershipId,
                ],
            ]
        );
    } catch (Throwable $exception) {
        error_log(
            'Blackthorne achievement hook failed after Sorting Ceremony completion: ' .
            $exception->getMessage()
        );
    }
}

/*
|--------------------------------------------------------------------------
| Ceremony + House lookup
|--------------------------------------------------------------------------
*/

function sorting_ceremony_published_version(): ?array
{
    global $pdo;

    $statement = $pdo->query(
        'SELECT *
         FROM sorting_ceremony_versions
         WHERE status = "published"
         ORDER BY published_at DESC, version_number DESC, id DESC
         LIMIT 1'
    );

    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function sorting_ceremony_house(int $houseId): ?array
{
    global $pdo;

    if ($houseId <= 0) {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT *
         FROM houses
         WHERE id = :house_id
         LIMIT 1'
    );
    $statement->execute([
        'house_id' => $houseId,
    ]);

    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function sorting_ceremony_completed_attempt_for_user(int $userId): ?array
{
    global $pdo;

    if ($userId <= 0) {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT sa.*, scv.version_number, scv.name AS ceremony_name,
                scv.reveal_lead_text
         FROM sorting_attempts sa
         INNER JOIN sorting_ceremony_versions scv
            ON scv.id = sa.ceremony_version_id
         WHERE sa.user_id = :user_id
           AND sa.status = "completed"
           AND sa.completed_at IS NOT NULL
           AND sa.final_house_id IS NOT NULL
         ORDER BY sa.completed_at DESC, sa.id DESC
         LIMIT 1'
    );
    $statement->execute([
        'user_id' => $userId,
    ]);

    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function sorting_ceremony_active_attempt_for_user(int $userId, bool $forUpdate = false): ?array
{
    global $pdo;

    if ($userId <= 0) {
        return null;
    }

    $sql =
        'SELECT sa.*, scv.version_number, scv.name AS ceremony_name,
                scv.status AS ceremony_version_status,
                scv.intro_title, scv.intro_text, scv.reveal_lead_text
         FROM sorting_attempts sa
         INNER JOIN sorting_ceremony_versions scv
            ON scv.id = sa.ceremony_version_id
         WHERE sa.user_id = :user_id
           AND sa.status IN ("in_progress", "choosing")
         ORDER BY sa.started_at DESC, sa.id DESC
         LIMIT 1';

    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $statement = $pdo->prepare($sql);
    $statement->execute([
        'user_id' => $userId,
    ]);

    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/**
 * Returns the state needed when a member first visits sorting-ceremony.php.
 * This function does NOT create an attempt.
 */
function sorting_ceremony_entry_state(int $userId): array
{
    if ($userId <= 0) {
        throw new InvalidArgumentException('A valid user ID is required.');
    }

    $membership = house_user_membership($userId);

    if ($membership !== null) {
        return [
            'state' => 'sorted',
            'membership' => $membership,
            'house' => sorting_ceremony_house((int) $membership['house_id']),
            'attempt' => sorting_ceremony_completed_attempt_for_user($userId),
            'version' => null,
        ];
    }

    $completedAttempt = sorting_ceremony_completed_attempt_for_user($userId);

    if ($completedAttempt !== null) {
        return [
            'state' => 'completed_history',
            'membership' => null,
            'house' => sorting_ceremony_house((int) $completedAttempt['final_house_id']),
            'attempt' => $completedAttempt,
            'version' => null,
        ];
    }

    $activeAttempt = sorting_ceremony_active_attempt_for_user($userId);

    if ($activeAttempt !== null) {
        return [
            'state' => 'resume',
            'membership' => null,
            'house' => null,
            'attempt' => $activeAttempt,
            'version' => null,
        ];
    }

    $publishedVersion = sorting_ceremony_published_version();

    if ($publishedVersion === null) {
        return [
            'state' => 'unavailable',
            'membership' => null,
            'house' => null,
            'attempt' => null,
            'version' => null,
        ];
    }

    return [
        'state' => 'intro',
        'membership' => null,
        'house' => null,
        'attempt' => null,
        'version' => $publishedVersion,
    ];
}

/*
|--------------------------------------------------------------------------
| Attempt creation / resume
|--------------------------------------------------------------------------
*/

/**
 * Creates the member's first attempt or returns the existing active attempt.
 *
 * The users row is locked so two tabs cannot safely create two new attempts
 * at the same moment.
 */
function sorting_ceremony_start_or_resume_attempt(int $userId): array
{
    global $pdo;

    if ($userId <= 0) {
        throw new InvalidArgumentException('A valid user ID is required.');
    }

    $startedTransaction = false;

    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }

        $userLock = $pdo->prepare(
            'SELECT id
             FROM users
             WHERE id = :user_id
             LIMIT 1
             FOR UPDATE'
        );
        $userLock->execute([
            'user_id' => $userId,
        ]);

        if ($userLock->fetchColumn() === false) {
            throw new SortingCeremonyStateException('The member account could not be found.');
        }

        $membership = house_user_membership($userId);
        if ($membership !== null) {
            throw new SortingCeremonyStateException('This member has already been sorted.');
        }

        $completedAttempt = sorting_ceremony_completed_attempt_for_user($userId);
        if ($completedAttempt !== null) {
            throw new SortingCeremonyStateException('This member has already completed the Sorting Ceremony.');
        }

        $activeAttempt = sorting_ceremony_active_attempt_for_user($userId, true);
        if ($activeAttempt !== null) {
            sorting_ceremony_touch_attempt((int) $activeAttempt['id']);

            if ($startedTransaction) {
                $pdo->commit();
            }

            return sorting_ceremony_attempt_row((int) $activeAttempt['id'], $userId)
                ?? $activeAttempt;
        }

        $version = sorting_ceremony_published_version();
        if ($version === null) {
            throw new SortingCeremonyConfigurationException('No published Sorting Ceremony is currently available.');
        }

        $questionCountStatement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM sorting_questions
             WHERE ceremony_version_id = :version_id
               AND question_type = "primary"
               AND is_active = 1'
        );
        $questionCountStatement->execute([
            'version_id' => (int) $version['id'],
        ]);

        if ((int) $questionCountStatement->fetchColumn() <= 0) {
            throw new SortingCeremonyConfigurationException('The published Sorting Ceremony has no active primary questions.');
        }

        $insert = $pdo->prepare(
            'INSERT INTO sorting_attempts (
                user_id,
                ceremony_version_id,
                status,
                current_stage,
                current_primary_question_number,
                started_at,
                last_activity_at
             ) VALUES (
                :user_id,
                :ceremony_version_id,
                "in_progress",
                "primary",
                0,
                NOW(),
                NOW()
             )'
        );
        $insert->execute([
            'user_id' => $userId,
            'ceremony_version_id' => (int) $version['id'],
        ]);

        $attemptId = (int) $pdo->lastInsertId();

        if ($startedTransaction) {
            $pdo->commit();
        }

        $attempt = sorting_ceremony_attempt_row($attemptId, $userId);

        if ($attempt === null) {
            throw new SortingCeremonyException('The Sorting Ceremony attempt could not be loaded after it was created.');
        }

        return $attempt;
    } catch (Throwable $exception) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

/*
|--------------------------------------------------------------------------
| Question lookup + randomized student answer display
|--------------------------------------------------------------------------
*/

function sorting_ceremony_primary_questions(int $ceremonyVersionId): array
{
    global $pdo;

    if ($ceremonyVersionId <= 0) {
        return [];
    }

    $statement = $pdo->prepare(
        'SELECT *
         FROM sorting_questions
         WHERE ceremony_version_id = :version_id
           AND question_type = "primary"
           AND is_active = 1
         ORDER BY sort_order, id'
    );
    $statement->execute([
        'version_id' => $ceremonyVersionId,
    ]);

    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function sorting_ceremony_answered_question_ids(int $attemptId, string $phase = 'primary'): array
{
    global $pdo;

    if ($attemptId <= 0 || !in_array($phase, ['primary', 'choosing'], true)) {
        return [];
    }

    $statement = $pdo->prepare(
        'SELECT question_id
         FROM sorting_responses
         WHERE attempt_id = :attempt_id
           AND phase = :phase
         ORDER BY sequence_number, id'
    );
    $statement->execute([
        'attempt_id' => $attemptId,
        'phase' => $phase,
    ]);

    return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

function sorting_ceremony_next_primary_question(int $attemptId, int $userId): ?array
{
    $attempt = sorting_ceremony_attempt_row($attemptId, $userId);

    if ($attempt === null) {
        throw new SortingCeremonyStateException('That Sorting Ceremony attempt could not be found.');
    }

    if ((string) $attempt['status'] !== 'in_progress') {
        return null;
    }

    if ((string) $attempt['current_stage'] === 'interlude') {
        return null;
    }

    $answeredIds = array_flip(sorting_ceremony_answered_question_ids($attemptId, 'primary'));

    foreach (sorting_ceremony_primary_questions((int) $attempt['ceremony_version_id']) as $position => $question) {
        if (!isset($answeredIds[(int) $question['id']])) {
            $question['question_number'] = $position + 1;
            $question['total_primary_questions'] = count(
                sorting_ceremony_primary_questions((int) $attempt['ceremony_version_id'])
            );

            return $question;
        }
    }

    return null;
}

/**
 * Returns only information safe for student-facing rendering.
 * Hidden House IDs are deliberately omitted.
 */
function sorting_ceremony_student_answers(int $questionId, ?array $restrictToHouseIds = null): array
{
    global $pdo;

    if ($questionId <= 0) {
        return [];
    }

    $sql =
        'SELECT id, answer_text
         FROM sorting_answers
         WHERE question_id = :question_id
           AND is_active = 1';

    $params = [
        'question_id' => $questionId,
    ];

    $restrictToHouseIds = $restrictToHouseIds === null
        ? null
        : sorting_ceremony_normalize_house_ids($restrictToHouseIds);

    if ($restrictToHouseIds !== null) {
        if ($restrictToHouseIds === []) {
            return [];
        }

        $placeholders = [];
        foreach ($restrictToHouseIds as $index => $houseId) {
            $key = 'house_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $houseId;
        }

        $sql .= ' AND house_id IN (' . implode(', ', $placeholders) . ')';
    }

    $sql .= ' ORDER BY sort_order, id';

    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $answers = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (count($answers) > 1) {
        shuffle($answers);
    }

    return $answers;
}

function sorting_ceremony_primary_screen(int $attemptId, int $userId): ?array
{
    $question = sorting_ceremony_next_primary_question($attemptId, $userId);

    if ($question === null) {
        return null;
    }

    return [
        'question' => $question,
        'answers' => sorting_ceremony_student_answers((int) $question['id']),
    ];
}

/*
|--------------------------------------------------------------------------
| Interludes
|--------------------------------------------------------------------------
*/

function sorting_ceremony_interlude(int $interludeId, int $ceremonyVersionId): ?array
{
    global $pdo;

    if ($interludeId <= 0 || $ceremonyVersionId <= 0) {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT *
         FROM sorting_interludes
         WHERE id = :interlude_id
           AND ceremony_version_id = :version_id
           AND is_active = 1
         LIMIT 1'
    );
    $statement->execute([
        'interlude_id' => $interludeId,
        'version_id' => $ceremonyVersionId,
    ]);

    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function sorting_ceremony_interlude_after_question(int $ceremonyVersionId, int $primaryQuestionNumber): ?array
{
    global $pdo;

    if ($ceremonyVersionId <= 0 || $primaryQuestionNumber <= 0) {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT *
         FROM sorting_interludes
         WHERE ceremony_version_id = :version_id
           AND after_primary_question_number = :question_number
           AND is_active = 1
         ORDER BY sort_order, id
         LIMIT 1'
    );
    $statement->execute([
        'version_id' => $ceremonyVersionId,
        'question_number' => $primaryQuestionNumber,
    ]);

    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function sorting_ceremony_pending_interlude(int $attemptId, int $userId): ?array
{
    $attempt = sorting_ceremony_attempt_row($attemptId, $userId);

    if ($attempt === null || (int) ($attempt['pending_interlude_id'] ?? 0) <= 0) {
        return null;
    }

    return sorting_ceremony_interlude(
        (int) $attempt['pending_interlude_id'],
        (int) $attempt['ceremony_version_id']
    );
}

/*
|--------------------------------------------------------------------------
| Hidden primary scoring + Choosing selection
|--------------------------------------------------------------------------
*/

function sorting_ceremony_primary_scores(int $attemptId): array
{
    global $pdo;

    if ($attemptId <= 0) {
        return [];
    }

    $attempt = sorting_ceremony_attempt_row($attemptId);
    if ($attempt === null) {
        return [];
    }

    $housesStatement = $pdo->query(
        'SELECT id
         FROM houses
         WHERE is_active = 1
         ORDER BY sort_order, id'
    );
    $scores = [];

    foreach ($housesStatement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $houseId) {
        $scores[(int) $houseId] = 0;
    }

    $scoreStatement = $pdo->prepare(
        'SELECT house_id, COUNT(*) AS score
         FROM sorting_responses
         WHERE attempt_id = :attempt_id
           AND phase = "primary"
         GROUP BY house_id'
    );
    $scoreStatement->execute([
        'attempt_id' => $attemptId,
    ]);

    foreach ($scoreStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $scores[(int) $row['house_id']] = (int) $row['score'];
    }

    ksort($scores, SORT_NUMERIC);

    return $scores;
}

function sorting_ceremony_top_house_ids(array $scores): array
{
    if ($scores === []) {
        return [];
    }

    $maxScore = max($scores);
    $houseIds = [];

    foreach ($scores as $houseId => $score) {
        if ((int) $score === (int) $maxScore) {
            $houseIds[] = (int) $houseId;
        }
    }

    return sorting_ceremony_normalize_house_ids($houseIds);
}

/**
 * Exact-set questions win over generic tied-set questions.
 */
function sorting_ceremony_choose_tiebreak_question(int $ceremonyVersionId, array $tiedHouseIds): ?array
{
    global $pdo;

    $tiedHouseIds = sorting_ceremony_normalize_house_ids($tiedHouseIds);
    $tieCount = count($tiedHouseIds);

    if ($ceremonyVersionId <= 0 || $tieCount < 2) {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT *
         FROM sorting_questions
         WHERE ceremony_version_id = :version_id
           AND question_type = "choosing"
           AND is_active = 1
           AND min_tied_houses <= :tie_count
           AND max_tied_houses >= :tie_count
         ORDER BY
            CASE choosing_strategy
                WHEN "exact_set" THEN 0
                WHEN "tied_set" THEN 1
                ELSE 2
            END,
            sort_order,
            id'
    );
    $statement->execute([
        'version_id' => $ceremonyVersionId,
        'tie_count' => $tieCount,
    ]);

    $candidates = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $houseMapStatement = $pdo->prepare(
        'SELECT house_id
         FROM sorting_question_houses
         WHERE question_id = :question_id
         ORDER BY house_id'
    );

    foreach ($candidates as $candidate) {
        $strategy = (string) $candidate['choosing_strategy'];

        if ($strategy === 'tied_set') {
            return $candidate;
        }

        if ($strategy !== 'exact_set') {
            continue;
        }

        $houseMapStatement->execute([
            'question_id' => (int) $candidate['id'],
        ]);
        $mappedIds = sorting_ceremony_normalize_house_ids(
            $houseMapStatement->fetchAll(PDO::FETCH_COLUMN) ?: []
        );

        if ($mappedIds === $tiedHouseIds) {
            return $candidate;
        }
    }

    return null;
}

function sorting_ceremony_choosing_screen(int $attemptId, int $userId): ?array
{
    $attempt = sorting_ceremony_attempt_row($attemptId, $userId);

    if ($attempt === null || (string) $attempt['status'] !== 'choosing') {
        return null;
    }

    $tiedHouseIds = sorting_ceremony_normalize_house_ids(
        sorting_ceremony_json_array($attempt['tied_house_ids_json'] ?? null)
    );

    if (count($tiedHouseIds) < 2) {
        throw new SortingCeremonyStateException('The Choosing state does not contain a valid House tie.');
    }

    $question = sorting_ceremony_choose_tiebreak_question(
        (int) $attempt['ceremony_version_id'],
        $tiedHouseIds
    );

    if ($question === null) {
        throw new SortingCeremonyConfigurationException(
            'No active Choosing question is configured for this House tie.'
        );
    }

    $answers = sorting_ceremony_student_answers(
        (int) $question['id'],
        $tiedHouseIds
    );

    if (count($answers) < 2) {
        throw new SortingCeremonyConfigurationException(
            'The Choosing question does not have enough active answers for the tied Houses.'
        );
    }

    return [
        'question' => $question,
        'answers' => $answers,
        'tied_house_count' => count($tiedHouseIds),
    ];
}

/*
|--------------------------------------------------------------------------
| Progression after primary answers / interludes
|--------------------------------------------------------------------------
*/

/**
 * Called while the attempt row is already locked inside a transaction.
 */
function sorting_ceremony_resolve_after_primary(PDO $pdo, array $attempt): array
{
    $attemptId = (int) $attempt['id'];
    $versionId = (int) $attempt['ceremony_version_id'];

    $questions = sorting_ceremony_primary_questions($versionId);
    $answeredIds = sorting_ceremony_answered_question_ids($attemptId, 'primary');

    if (count($answeredIds) < count($questions)) {
        $statement = $pdo->prepare(
            'UPDATE sorting_attempts
             SET status = "in_progress",
                 current_stage = "primary",
                 pending_interlude_id = NULL,
                 last_activity_at = NOW()
             WHERE id = :attempt_id'
        );
        $statement->execute([
            'attempt_id' => $attemptId,
        ]);

        return sorting_ceremony_attempt_row($attemptId, (int) $attempt['user_id'], true)
            ?? $attempt;
    }

    $scores = sorting_ceremony_primary_scores($attemptId);
    $tiedHouseIds = sorting_ceremony_top_house_ids($scores);

    if ($tiedHouseIds === []) {
        throw new SortingCeremonyStateException('The Sorting Ceremony could not calculate a House result.');
    }

    if (count($tiedHouseIds) === 1) {
        return sorting_ceremony_finalize_locked_attempt(
            $pdo,
            $attempt,
            $tiedHouseIds[0],
            'primary',
            $scores,
            $tiedHouseIds
        );
    }

    $choosingQuestion = sorting_ceremony_choose_tiebreak_question($versionId, $tiedHouseIds);

    if ($choosingQuestion === null) {
        throw new SortingCeremonyConfigurationException(
            'The ceremony produced a tie, but no matching Choosing question is configured.'
        );
    }

    $statement = $pdo->prepare(
        'UPDATE sorting_attempts
         SET status = "choosing",
             current_stage = "choosing",
             pending_interlude_id = NULL,
             primary_scores_json = :scores_json,
             tied_house_ids_json = :tied_json,
             last_activity_at = NOW()
         WHERE id = :attempt_id'
    );
    $statement->execute([
        'scores_json' => sorting_ceremony_json_encode($scores),
        'tied_json' => sorting_ceremony_json_encode($tiedHouseIds),
        'attempt_id' => $attemptId,
    ]);

    return sorting_ceremony_attempt_row($attemptId, (int) $attempt['user_id'], true)
        ?? $attempt;
}

function sorting_ceremony_acknowledge_interlude(int $attemptId, int $userId): array
{
    global $pdo;

    $startedTransaction = false;

    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }

        $attempt = sorting_ceremony_attempt_row($attemptId, $userId, true);

        if ($attempt === null) {
            throw new SortingCeremonyStateException('That Sorting Ceremony attempt could not be found.');
        }

        if ((string) $attempt['status'] !== 'in_progress') {
            throw new SortingCeremonyStateException('This attempt is not waiting on an interlude.');
        }

        if ((string) $attempt['current_stage'] !== 'interlude' || (int) ($attempt['pending_interlude_id'] ?? 0) <= 0) {
            throw new SortingCeremonyStateException('There is no pending interlude to continue from.');
        }

        $clear = $pdo->prepare(
            'UPDATE sorting_attempts
             SET pending_interlude_id = NULL,
                 current_stage = "primary",
                 last_activity_at = NOW()
             WHERE id = :attempt_id'
        );
        $clear->execute([
            'attempt_id' => $attemptId,
        ]);

        $attempt['pending_interlude_id'] = null;
        $attempt['current_stage'] = 'primary';

        $attempt = sorting_ceremony_resolve_after_primary($pdo, $attempt);

        if ($startedTransaction) {
            $pdo->commit();
        }

        $result = sorting_ceremony_attempt_row($attemptId, $userId) ?? $attempt;
        sorting_ceremony_process_completion_achievements($result);

        return $result;
    } catch (Throwable $exception) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

/*
|--------------------------------------------------------------------------
| Response submission
|--------------------------------------------------------------------------
*/

function sorting_ceremony_submit_primary_answer(
    int $attemptId,
    int $userId,
    int $questionId,
    int $answerId
): array {
    global $pdo;

    if ($attemptId <= 0 || $userId <= 0 || $questionId <= 0 || $answerId <= 0) {
        throw new InvalidArgumentException('A valid attempt, member, question, and answer are required.');
    }

    $startedTransaction = false;

    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }

        $attempt = sorting_ceremony_attempt_row($attemptId, $userId, true);

        if ($attempt === null) {
            throw new SortingCeremonyStateException('That Sorting Ceremony attempt could not be found.');
        }

        if ((string) $attempt['status'] !== 'in_progress' || (string) $attempt['current_stage'] !== 'primary') {
            throw new SortingCeremonyStateException('The ceremony is not currently accepting a primary answer.');
        }

        $questions = sorting_ceremony_primary_questions((int) $attempt['ceremony_version_id']);
        $answeredIds = array_flip(sorting_ceremony_answered_question_ids($attemptId, 'primary'));
        $expectedQuestion = null;
        $questionNumber = 0;

        foreach ($questions as $position => $question) {
            if (!isset($answeredIds[(int) $question['id']])) {
                $expectedQuestion = $question;
                $questionNumber = $position + 1;
                break;
            }
        }

        if ($expectedQuestion === null) {
            throw new SortingCeremonyStateException('All primary questions have already been answered.');
        }

        if ((int) $expectedQuestion['id'] !== $questionId) {
            throw new SortingCeremonyStateException('That is not the next question in this Sorting Ceremony attempt.');
        }

        $answerStatement = $pdo->prepare(
            'SELECT sa.id, sa.answer_text, sa.house_id,
                    sq.id AS question_id, sq.prompt, sq.ceremony_version_id
             FROM sorting_answers sa
             INNER JOIN sorting_questions sq
                ON sq.id = sa.question_id
             INNER JOIN houses h
                ON h.id = sa.house_id
             WHERE sa.id = :answer_id
               AND sa.question_id = :question_id
               AND sa.is_active = 1
               AND sq.is_active = 1
               AND sq.question_type = "primary"
               AND sq.ceremony_version_id = :version_id
               AND h.is_active = 1
             LIMIT 1'
        );
        $answerStatement->execute([
            'answer_id' => $answerId,
            'question_id' => $questionId,
            'version_id' => (int) $attempt['ceremony_version_id'],
        ]);
        $answer = $answerStatement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($answer)) {
            throw new SortingCeremonyStateException('That answer is not valid for this question.');
        }

        $sequenceStatement = $pdo->prepare(
            'SELECT COALESCE(MAX(sequence_number), 0) + 1
             FROM sorting_responses
             WHERE attempt_id = :attempt_id'
        );
        $sequenceStatement->execute([
            'attempt_id' => $attemptId,
        ]);
        $sequenceNumber = (int) $sequenceStatement->fetchColumn();

        $insert = $pdo->prepare(
            'INSERT INTO sorting_responses (
                attempt_id,
                question_id,
                answer_id,
                house_id,
                phase,
                sequence_number,
                question_text_snapshot,
                answer_text_snapshot,
                answered_at
             ) VALUES (
                :attempt_id,
                :question_id,
                :answer_id,
                :house_id,
                "primary",
                :sequence_number,
                :question_text_snapshot,
                :answer_text_snapshot,
                NOW()
             )'
        );
        $insert->execute([
            'attempt_id' => $attemptId,
            'question_id' => $questionId,
            'answer_id' => $answerId,
            'house_id' => (int) $answer['house_id'],
            'sequence_number' => $sequenceNumber,
            'question_text_snapshot' => (string) $answer['prompt'],
            'answer_text_snapshot' => (string) $answer['answer_text'],
        ]);

        $interlude = sorting_ceremony_interlude_after_question(
            (int) $attempt['ceremony_version_id'],
            $questionNumber
        );

        if ($interlude !== null) {
            $update = $pdo->prepare(
                'UPDATE sorting_attempts
                 SET current_primary_question_number = :question_number,
                     current_stage = "interlude",
                     pending_interlude_id = :interlude_id,
                     last_activity_at = NOW()
                 WHERE id = :attempt_id'
            );
            $update->execute([
                'question_number' => $questionNumber,
                'interlude_id' => (int) $interlude['id'],
                'attempt_id' => $attemptId,
            ]);
        } else {
            $update = $pdo->prepare(
                'UPDATE sorting_attempts
                 SET current_primary_question_number = :question_number,
                     current_stage = "primary",
                     pending_interlude_id = NULL,
                     last_activity_at = NOW()
                 WHERE id = :attempt_id'
            );
            $update->execute([
                'question_number' => $questionNumber,
                'attempt_id' => $attemptId,
            ]);

            $attempt['current_primary_question_number'] = $questionNumber;
            $attempt['current_stage'] = 'primary';
            $attempt['pending_interlude_id'] = null;

            $attempt = sorting_ceremony_resolve_after_primary($pdo, $attempt);
        }

        if ($startedTransaction) {
            $pdo->commit();
        }

        $result = sorting_ceremony_attempt_row($attemptId, $userId) ?? $attempt;
        sorting_ceremony_process_completion_achievements($result);

        return $result;
    } catch (PDOException $exception) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        // The unique attempt/question constraint is the final server-side lock
        // against browser Back, refresh, or duplicate submissions.
        if ((string) $exception->getCode() === '23000') {
            throw new SortingCeremonyStateException(
                'That question has already been answered and cannot be changed.',
                0,
                $exception
            );
        }

        throw $exception;
    } catch (Throwable $exception) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function sorting_ceremony_submit_choosing_answer(
    int $attemptId,
    int $userId,
    int $questionId,
    int $answerId
): array {
    global $pdo;

    if ($attemptId <= 0 || $userId <= 0 || $questionId <= 0 || $answerId <= 0) {
        throw new InvalidArgumentException('A valid attempt, member, question, and answer are required.');
    }

    $startedTransaction = false;

    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }

        $attempt = sorting_ceremony_attempt_row($attemptId, $userId, true);

        if ($attempt === null) {
            throw new SortingCeremonyStateException('That Sorting Ceremony attempt could not be found.');
        }

        if ((string) $attempt['status'] !== 'choosing' || (string) $attempt['current_stage'] !== 'choosing') {
            throw new SortingCeremonyStateException('The ceremony is not currently accepting a Choosing answer.');
        }

        $tiedHouseIds = sorting_ceremony_normalize_house_ids(
            sorting_ceremony_json_array($attempt['tied_house_ids_json'] ?? null)
        );

        if (count($tiedHouseIds) < 2) {
            throw new SortingCeremonyStateException('The Choosing state does not contain a valid House tie.');
        }

        $expectedQuestion = sorting_ceremony_choose_tiebreak_question(
            (int) $attempt['ceremony_version_id'],
            $tiedHouseIds
        );

        if ($expectedQuestion === null || (int) $expectedQuestion['id'] !== $questionId) {
            throw new SortingCeremonyStateException('That is not the active Choosing question for this attempt.');
        }

        $answerStatement = $pdo->prepare(
            'SELECT sa.id, sa.answer_text, sa.house_id,
                    sq.prompt
             FROM sorting_answers sa
             INNER JOIN sorting_questions sq
                ON sq.id = sa.question_id
             INNER JOIN houses h
                ON h.id = sa.house_id
             WHERE sa.id = :answer_id
               AND sa.question_id = :question_id
               AND sa.is_active = 1
               AND sq.is_active = 1
               AND sq.question_type = "choosing"
               AND sq.ceremony_version_id = :version_id
               AND h.is_active = 1
             LIMIT 1'
        );
        $answerStatement->execute([
            'answer_id' => $answerId,
            'question_id' => $questionId,
            'version_id' => (int) $attempt['ceremony_version_id'],
        ]);
        $answer = $answerStatement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($answer)) {
            throw new SortingCeremonyStateException('That Choosing answer is not valid.');
        }

        $finalHouseId = (int) $answer['house_id'];
        if (!in_array($finalHouseId, $tiedHouseIds, true)) {
            throw new SortingCeremonyStateException('That answer does not belong to one of the tied Houses.');
        }

        $existingResponse = $pdo->prepare(
            'SELECT id
             FROM sorting_responses
             WHERE attempt_id = :attempt_id
               AND question_id = :question_id
             LIMIT 1'
        );
        $existingResponse->execute([
            'attempt_id' => $attemptId,
            'question_id' => $questionId,
        ]);

        if ($existingResponse->fetchColumn() !== false) {
            throw new SortingCeremonyStateException('The Choosing question has already been answered.');
        }

        $sequenceStatement = $pdo->prepare(
            'SELECT COALESCE(MAX(sequence_number), 0) + 1
             FROM sorting_responses
             WHERE attempt_id = :attempt_id'
        );
        $sequenceStatement->execute([
            'attempt_id' => $attemptId,
        ]);
        $sequenceNumber = (int) $sequenceStatement->fetchColumn();

        $insert = $pdo->prepare(
            'INSERT INTO sorting_responses (
                attempt_id,
                question_id,
                answer_id,
                house_id,
                phase,
                sequence_number,
                question_text_snapshot,
                answer_text_snapshot,
                answered_at
             ) VALUES (
                :attempt_id,
                :question_id,
                :answer_id,
                :house_id,
                "choosing",
                :sequence_number,
                :question_text_snapshot,
                :answer_text_snapshot,
                NOW()
             )'
        );
        $insert->execute([
            'attempt_id' => $attemptId,
            'question_id' => $questionId,
            'answer_id' => $answerId,
            'house_id' => $finalHouseId,
            'sequence_number' => $sequenceNumber,
            'question_text_snapshot' => (string) $answer['prompt'],
            'answer_text_snapshot' => (string) $answer['answer_text'],
        ]);

        $scores = sorting_ceremony_json_array($attempt['primary_scores_json'] ?? null);

        $attempt = sorting_ceremony_finalize_locked_attempt(
            $pdo,
            $attempt,
            $finalHouseId,
            'choosing',
            $scores,
            $tiedHouseIds
        );

        if ($startedTransaction) {
            $pdo->commit();
        }

        $result = sorting_ceremony_attempt_row($attemptId, $userId) ?? $attempt;
        sorting_ceremony_process_completion_achievements($result);

        return $result;
    } catch (PDOException $exception) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ((string) $exception->getCode() === '23000') {
            throw new SortingCeremonyStateException(
                'The Choosing question has already been answered and cannot be changed.',
                0,
                $exception
            );
        }

        throw $exception;
    } catch (Throwable $exception) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

/*
|--------------------------------------------------------------------------
| Final House assignment
|--------------------------------------------------------------------------
*/

/**
 * Finalizes an already-locked attempt inside the caller's transaction.
 *
 * The user row is locked before checking House membership again. This is the
 * critical concurrency guard that prevents two tabs/processes from assigning
 * two active Houses at the same time.
 */
function sorting_ceremony_finalize_locked_attempt(
    PDO $pdo,
    array $attempt,
    int $finalHouseId,
    string $resultMethod,
    array $scores,
    array $tiedHouseIds
): array {
    $attemptId = (int) ($attempt['id'] ?? 0);
    $userId = (int) ($attempt['user_id'] ?? 0);

    if ($attemptId <= 0 || $userId <= 0 || $finalHouseId <= 0) {
        throw new SortingCeremonyStateException('The Sorting Ceremony does not have enough information to finalize this attempt.');
    }

    if (!in_array($resultMethod, ['primary', 'choosing'], true)) {
        throw new InvalidArgumentException('The Sorting Ceremony result method is invalid.');
    }

    $house = sorting_ceremony_house($finalHouseId);
    if ($house === null || (int) $house['is_active'] !== 1) {
        throw new SortingCeremonyStateException('The selected House is not active.');
    }

    $userLock = $pdo->prepare(
        'SELECT id
         FROM users
         WHERE id = :user_id
         LIMIT 1
         FOR UPDATE'
    );
    $userLock->execute([
        'user_id' => $userId,
    ]);

    if ($userLock->fetchColumn() === false) {
        throw new SortingCeremonyStateException('The member account could not be locked for House assignment.');
    }

    $membershipStatement = $pdo->prepare(
        'SELECT id, house_id
         FROM house_memberships
         WHERE user_id = :user_id
           AND membership_status = "active"
           AND left_at IS NULL
         ORDER BY joined_at DESC, id DESC
         LIMIT 1
         FOR UPDATE'
    );
    $membershipStatement->execute([
        'user_id' => $userId,
    ]);
    $existingMembership = $membershipStatement->fetch(PDO::FETCH_ASSOC);

    if (is_array($existingMembership)) {
        throw new SortingCeremonyStateException(
            'This member received an active House assignment before the ceremony could finish.'
        );
    }

    $membershipInsert = $pdo->prepare(
        'INSERT INTO house_memberships (
            user_id,
            house_id,
            assigned_by,
            membership_status,
            joined_at,
            left_at
         ) VALUES (
            :user_id,
            :house_id,
            NULL,
            "active",
            NOW(),
            NULL
         )'
    );
    $membershipInsert->execute([
        'user_id' => $userId,
        'house_id' => $finalHouseId,
    ]);
    $membershipId = (int) $pdo->lastInsertId();

    $updateAttempt = $pdo->prepare(
        'UPDATE sorting_attempts
         SET status = "completed",
             current_stage = "reveal",
             pending_interlude_id = NULL,
             primary_scores_json = :scores_json,
             tied_house_ids_json = :tied_json,
             final_house_id = :final_house_id,
             house_membership_id = :membership_id,
             result_method = :result_method,
             completed_at = NOW(),
             last_activity_at = NOW()
         WHERE id = :attempt_id
           AND user_id = :user_id
           AND status IN ("in_progress", "choosing")'
    );
    $updateAttempt->execute([
        'scores_json' => sorting_ceremony_json_encode($scores),
        'tied_json' => sorting_ceremony_json_encode(
            sorting_ceremony_normalize_house_ids($tiedHouseIds)
        ),
        'final_house_id' => $finalHouseId,
        'membership_id' => $membershipId,
        'result_method' => $resultMethod,
        'attempt_id' => $attemptId,
        'user_id' => $userId,
    ]);

    if ($updateAttempt->rowCount() !== 1) {
        throw new SortingCeremonyStateException('The Sorting Ceremony attempt changed before it could be finalized.');
    }

    $attempt['status'] = 'completed';
    $attempt['current_stage'] = 'reveal';
    $attempt['pending_interlude_id'] = null;
    $attempt['primary_scores_json'] = sorting_ceremony_json_encode($scores);
    $attempt['tied_house_ids_json'] = sorting_ceremony_json_encode(
        sorting_ceremony_normalize_house_ids($tiedHouseIds)
    );
    $attempt['final_house_id'] = $finalHouseId;
    $attempt['house_membership_id'] = $membershipId;
    $attempt['result_method'] = $resultMethod;

    return $attempt;
}

/*
|--------------------------------------------------------------------------
| Reveal + convenience state
|--------------------------------------------------------------------------
*/

function sorting_ceremony_reveal_data(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $membership = house_user_membership($userId);
    $attempt = sorting_ceremony_completed_attempt_for_user($userId);

    if ($attempt !== null) {
        sorting_ceremony_process_completion_achievements($attempt);
    }

    if ($membership === null && $attempt === null) {
        return null;
    }

    $houseId = $membership !== null
        ? (int) $membership['house_id']
        : (int) ($attempt['final_house_id'] ?? 0);

    $house = sorting_ceremony_house($houseId);
    if ($house === null) {
        return null;
    }

    return [
        'house' => $house,
        'membership' => $membership,
        'attempt' => $attempt,
        'reveal_lead_text' => $attempt['reveal_lead_text'] ?? null,
    ];
}

/**
 * Convenience function for the student page after an attempt exists.
 * It intentionally never exposes House scores or hidden House mappings.
 */
function sorting_ceremony_attempt_screen(int $attemptId, int $userId): array
{
    $attempt = sorting_ceremony_attempt_row($attemptId, $userId);

    if ($attempt === null) {
        throw new SortingCeremonyStateException('That Sorting Ceremony attempt could not be found.');
    }

    sorting_ceremony_touch_attempt($attemptId);

    if ((string) $attempt['status'] === 'completed' || (string) $attempt['current_stage'] === 'reveal') {
        return [
            'state' => 'reveal',
            'attempt' => $attempt,
            'reveal' => sorting_ceremony_reveal_data($userId),
        ];
    }

    if ((string) $attempt['current_stage'] === 'interlude') {
        $interlude = sorting_ceremony_pending_interlude($attemptId, $userId);

        if ($interlude === null) {
            throw new SortingCeremonyStateException('The pending interlude could not be loaded.');
        }

        return [
            'state' => 'interlude',
            'attempt' => $attempt,
            'interlude' => $interlude,
        ];
    }

    if ((string) $attempt['status'] === 'choosing' || (string) $attempt['current_stage'] === 'choosing') {
        return [
            'state' => 'choosing',
            'attempt' => $attempt,
            'screen' => sorting_ceremony_choosing_screen($attemptId, $userId),
        ];
    }

    $screen = sorting_ceremony_primary_screen($attemptId, $userId);

    if ($screen === null) {
        throw new SortingCeremonyStateException('The next Sorting Ceremony question could not be determined.');
    }

    return [
        'state' => 'primary',
        'attempt' => $attempt,
        'screen' => $screen,
    ];
}
