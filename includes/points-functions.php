<?php

declare(strict_types=1);

/**
 * Blackthorne Academy
 * Central Points System Helpers
 *
 * Upload path:
 *   /includes/points-functions.php
 *
 * This file is the single service layer for point writes and point totals.
 * Pages, grading workflows, achievements, staff tools, leaderboards, and
 * House Cup displays should call these helpers instead of writing directly
 * to points_ledger.
 *
 * Core rules:
 * - points_ledger is the source of truth for awarded points.
 * - academic = HW Points. These count toward student progression and House Cup.
 * - house_only = House Points. These count toward House competition, but never
 *   toward academic progression.
 * - House Cup total = academic + house_only for the selected school year.
 * - Point transactions are never destructively edited. Corrections use a new
 *   revision; reversals mark the existing transaction reversed.
 * - The House stored on a ledger row is a historical snapshot. It is never
 *   recalculated later if the member changes Houses.
 * - Finalized school years are read-only for point writes.
 *
 * Source ID contract for current/future integrations:
 * - assignment   => assignments.id
 * - quiz         => quizzes.id where assessment_type = quiz
 * - midterm      => quizzes.id where assessment_type = midterm
 * - final        => quizzes.id where assessment_type = final
 * - forum_thread => forum_threads.id
 * - forum_reply  => forum_posts.id
 * - achievement  => user_achievements.id (earned occurrence, not achievements.id)
 * - contest      => future contest record ID
 * - event        => future event record ID
 * - manual       => normally NULL
 * - bonus        => normally NULL unless tied to a specific future source
 * - penalty      => normally NULL unless tied to a specific future source
 */


/*
|--------------------------------------------------------------------------
| Point / Source Constants
|--------------------------------------------------------------------------
*/

function points_valid_point_types(): array
{
    return [
        'academic',
        'house_only',
    ];
}


function points_valid_source_types(): array
{
    return [
        'assignment',
        'quiz',
        'midterm',
        'final',
        'forum_thread',
        'forum_reply',
        'contest',
        'event',
        'achievement',
        'manual',
        'bonus',
        'penalty',
    ];
}


function points_academic_source_types(): array
{
    return [
        'assignment',
        'quiz',
        'midterm',
        'final',
    ];
}


function points_source_requires_id(string $sourceType): bool
{
    return in_array(
        $sourceType,
        [
            'assignment',
            'quiz',
            'midterm',
            'final',
            'forum_thread',
            'forum_reply',
            'contest',
            'event',
            'achievement',
        ],
        true
    );
}


/*
|--------------------------------------------------------------------------
| Validation / Normalization
|--------------------------------------------------------------------------
*/

function points_normalize_amount(
    int|float|string $points
): string {
    if (!is_numeric($points)) {
        throw new InvalidArgumentException(
            'Points must be a numeric value.'
        );
    }

    $numeric = (float) $points;

    if (!is_finite($numeric)) {
        throw new InvalidArgumentException(
            'Points must be a finite numeric value.'
        );
    }

    if (
        $numeric > 99999999.99
        || $numeric < -99999999.99
    ) {
        throw new InvalidArgumentException(
            'Points are outside the supported ledger range.'
        );
    }

    return number_format(
        round($numeric, 2),
        2,
        '.',
        ''
    );
}


function points_validate_type_and_source(
    string $pointType,
    string $sourceType,
    string $amount
): void {
    if (!in_array($pointType, points_valid_point_types(), true)) {
        throw new InvalidArgumentException(
            'Invalid point type.'
        );
    }

    if (!in_array($sourceType, points_valid_source_types(), true)) {
        throw new InvalidArgumentException(
            'Invalid point source type.'
        );
    }

    $numericAmount = (float) $amount;

    if ($pointType === 'academic') {
        if (!in_array($sourceType, points_academic_source_types(), true)) {
            throw new InvalidArgumentException(
                'HW Points may only come from assignments, quizzes, midterms, or finals.'
            );
        }

        if ($numericAmount < 0) {
            throw new InvalidArgumentException(
                'HW Points cannot be negative. Use a revision to correct an academic score.'
            );
        }
    }

    if (
        $pointType === 'house_only'
        && $sourceType === 'achievement'
        && $numericAmount < 0
    ) {
        throw new InvalidArgumentException(
            'Achievement rewards cannot deduct House Points.'
        );
    }

    if (
        $sourceType === 'bonus'
        && $numericAmount < 0
    ) {
        throw new InvalidArgumentException(
            'A bonus cannot contain negative points.'
        );
    }

    if (
        $sourceType === 'penalty'
        && $numericAmount > 0
    ) {
        throw new InvalidArgumentException(
            'A penalty must be zero or negative.'
        );
    }
}


function points_normalize_description(?string $description): ?string
{
    $description = trim((string) $description);

    if ($description === '') {
        return null;
    }

    if (function_exists('mb_substr')) {
        return mb_substr(
            $description,
            0,
            255,
            'UTF-8'
        );
    }

    return substr(
        $description,
        0,
        255
    );
}


function points_normalize_reversal_reason(string $reason): string
{
    $reason = trim($reason);

    if ($reason === '') {
        throw new InvalidArgumentException(
            'A reversal reason is required.'
        );
    }

    if (function_exists('mb_substr')) {
        return mb_substr(
            $reason,
            0,
            255,
            'UTF-8'
        );
    }

    return substr(
        $reason,
        0,
        255
    );
}


/*
|--------------------------------------------------------------------------
| School Year Resolution
|--------------------------------------------------------------------------
*/

function points_school_year(
    PDO $pdo,
    int $schoolYearId
): ?array {
    if ($schoolYearId <= 0) {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT
            id,
            name,
            start_date,
            end_date,
            finals_end_date,
            course_access_ends_at,
            promotion_window_start,
            is_current,
            is_active,
            is_finalized,
            finalized_at,
            finalized_by
         FROM school_years
         WHERE id = :school_year_id
         LIMIT 1'
    );

    $statement->execute([
        'school_year_id' => $schoolYearId,
    ]);

    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return is_array($row)
        ? $row
        : null;
}


function points_current_school_year(PDO $pdo): ?array
{
    $statement = $pdo->query(
        'SELECT
            id,
            name,
            start_date,
            end_date,
            finals_end_date,
            course_access_ends_at,
            promotion_window_start,
            is_current,
            is_active,
            is_finalized,
            finalized_at,
            finalized_by
         FROM school_years
         WHERE is_current = 1
           AND is_active = 1
         ORDER BY id DESC
         LIMIT 1'
    );

    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return is_array($row)
        ? $row
        : null;
}


function points_offering_school_year_id(
    PDO $pdo,
    int $offeringId
): ?int {
    if ($offeringId <= 0) {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT school_year_id
         FROM course_offerings
         WHERE id = :offering_id
         LIMIT 1'
    );

    $statement->execute([
        'offering_id' => $offeringId,
    ]);

    $value = $statement->fetchColumn();

    if ($value === false || $value === null) {
        return null;
    }

    $schoolYearId = (int) $value;

    return $schoolYearId > 0
        ? $schoolYearId
        : null;
}


function points_resolve_school_year(
    PDO $pdo,
    ?int $schoolYearId = null,
    ?int $offeringId = null
): array {
    $schoolYearId = ($schoolYearId ?? 0) > 0
        ? (int) $schoolYearId
        : null;

    $offeringId = ($offeringId ?? 0) > 0
        ? (int) $offeringId
        : null;

    $offeringSchoolYearId = null;

    if ($offeringId !== null) {
        $offeringStatement = $pdo->prepare(
            'SELECT id, school_year_id
             FROM course_offerings
             WHERE id = :offering_id
             LIMIT 1'
        );

        $offeringStatement->execute([
            'offering_id' => $offeringId,
        ]);

        $offering = $offeringStatement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($offering)) {
            throw new RuntimeException(
                'The selected course offering does not exist.'
            );
        }

        if ($offering['school_year_id'] !== null) {
            $offeringSchoolYearId =
                (int) $offering['school_year_id'];
        }
    }

    if (
        $schoolYearId !== null
        && $offeringSchoolYearId !== null
        && $schoolYearId !== $offeringSchoolYearId
    ) {
        throw new RuntimeException(
            'The selected school year does not match the course offering.'
        );
    }

    $resolvedId =
        $schoolYearId
        ?? $offeringSchoolYearId;

    if ($resolvedId === null) {
        $current = points_current_school_year($pdo);

        if ($current === null) {
            throw new RuntimeException(
                'No current active school year is configured.'
            );
        }

        return $current;
    }

    $schoolYear = points_school_year(
        $pdo,
        $resolvedId
    );

    if ($schoolYear === null) {
        throw new RuntimeException(
            'The selected school year does not exist.'
        );
    }

    return $schoolYear;
}


function points_assert_school_year_writable(array $schoolYear): void
{
    if ((int) ($schoolYear['is_active'] ?? 0) !== 1) {
        throw new RuntimeException(
            'Points cannot be changed for an inactive school year.'
        );
    }

    if ((int) ($schoolYear['is_finalized'] ?? 0) === 1) {
        throw new RuntimeException(
            'Points cannot be changed after a school year has been finalized.'
        );
    }
}


/*
|--------------------------------------------------------------------------
| User / House Context
|--------------------------------------------------------------------------
*/

function points_user_exists(
    PDO $pdo,
    int $userId
): bool {
    if ($userId <= 0) {
        return false;
    }

    $statement = $pdo->prepare(
        'SELECT 1
         FROM users
         WHERE id = :user_id
         LIMIT 1'
    );

    $statement->execute([
        'user_id' => $userId,
    ]);

    return (bool) $statement->fetchColumn();
}


function points_active_house_id(
    PDO $pdo,
    int $userId
): ?int {
    if ($userId <= 0) {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT house_id
         FROM house_memberships
         WHERE user_id = :user_id
           AND membership_status = \'active\'
           AND left_at IS NULL
         ORDER BY joined_at DESC, id DESC
         LIMIT 1'
    );

    $statement->execute([
        'user_id' => $userId,
    ]);

    $value = $statement->fetchColumn();

    if ($value === false || $value === null) {
        return null;
    }

    $houseId = (int) $value;

    return $houseId > 0
        ? $houseId
        : null;
}


function points_user_school_year_enrollment(
    PDO $pdo,
    int $userId,
    int $schoolYearId
): ?array {
    if (
        $userId <= 0
        || $schoolYearId <= 0
    ) {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT *
         FROM student_year_enrollments
         WHERE user_id = :user_id
           AND school_year_id = :school_year_id
         LIMIT 1'
    );

    $statement->execute([
        'user_id' => $userId,
        'school_year_id' => $schoolYearId,
    ]);

    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return is_array($row)
        ? $row
        : null;
}


/*
|--------------------------------------------------------------------------
| Academic Source Validation
|--------------------------------------------------------------------------
| Academic points are intentionally tied to a real course offering and a
| student's actual enrollment in that offering. This prevents orphaned HW
| point rows from being created by future integrations.
*/

function points_validate_academic_context(
    PDO $pdo,
    int $userId,
    int $schoolYearId,
    int $offeringId,
    string $sourceType,
    int $sourceId
): void {
    if (
        $userId <= 0
        || $schoolYearId <= 0
        || $offeringId <= 0
        || $sourceId <= 0
    ) {
        throw new InvalidArgumentException(
            'Academic points require a user, school year, course offering, and source.'
        );
    }

    $yearEnrollment = points_user_school_year_enrollment(
        $pdo,
        $userId,
        $schoolYearId
    );

    if ($yearEnrollment === null) {
        throw new RuntimeException(
            'The student does not have a school-year enrollment for these HW Points.'
        );
    }

    $courseEnrollmentStatement = $pdo->prepare(
        'SELECT
            ce.id,
            ce.status,
            co.school_year_id
         FROM course_enrollments ce
         INNER JOIN course_offerings co
            ON co.id = ce.offering_id
         WHERE ce.user_id = :user_id
           AND ce.offering_id = :offering_id
         LIMIT 1'
    );

    $courseEnrollmentStatement->execute([
        'user_id' => $userId,
        'offering_id' => $offeringId,
    ]);

    $courseEnrollment =
        $courseEnrollmentStatement->fetch(
            PDO::FETCH_ASSOC
        );

    if (!is_array($courseEnrollment)) {
        throw new RuntimeException(
            'The student is not enrolled in the selected course offering.'
        );
    }

    if (
        in_array(
            (string) ($courseEnrollment['status'] ?? ''),
            ['withdrawn', 'suspended'],
            true
        )
    ) {
        throw new RuntimeException(
            'HW Points cannot be awarded to a withdrawn or suspended course enrollment.'
        );
    }

    if (
        $courseEnrollment['school_year_id'] !== null
        && (int) $courseEnrollment['school_year_id'] !== $schoolYearId
    ) {
        throw new RuntimeException(
            'The course enrollment belongs to a different school year.'
        );
    }

    if ($sourceType === 'assignment') {
        $statement = $pdo->prepare(
            'SELECT 1
             FROM assignment_offering_settings
             WHERE assignment_id = :source_id
               AND offering_id = :offering_id
             LIMIT 1'
        );

        $statement->execute([
            'source_id' => $sourceId,
            'offering_id' => $offeringId,
        ]);

        if (!$statement->fetchColumn()) {
            throw new RuntimeException(
                'The assignment is not attached to the selected course offering.'
            );
        }

        return;
    }

    $statement = $pdo->prepare(
        'SELECT q.assessment_type
         FROM quiz_offering_settings qos
         INNER JOIN quizzes q
            ON q.id = qos.quiz_id
         WHERE qos.quiz_id = :source_id
           AND qos.offering_id = :offering_id
         LIMIT 1'
    );

    $statement->execute([
        'source_id' => $sourceId,
        'offering_id' => $offeringId,
    ]);

    $assessmentType = $statement->fetchColumn();

    if ($assessmentType === false) {
        throw new RuntimeException(
            'The assessment is not attached to the selected course offering.'
        );
    }

    if ((string) $assessmentType !== $sourceType) {
        throw new RuntimeException(
            'The assessment type does not match the requested HW Point source type.'
        );
    }
}


/*
|--------------------------------------------------------------------------
| Ledger Lookup
|--------------------------------------------------------------------------
*/

function points_ledger_entry(
    PDO $pdo,
    int $ledgerId,
    bool $forUpdate = false
): ?array {
    if ($ledgerId <= 0) {
        return null;
    }

    $sql =
        'SELECT *
         FROM points_ledger
         WHERE id = :ledger_id
         LIMIT 1';

    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $statement = $pdo->prepare($sql);
    $statement->execute([
        'ledger_id' => $ledgerId,
    ]);

    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return is_array($row)
        ? $row
        : null;
}


function points_latest_source_entry(
    PDO $pdo,
    int $userId,
    int $schoolYearId,
    string $pointType,
    string $sourceType,
    int $sourceId,
    bool $forUpdate = false
): ?array {
    if (
        $userId <= 0
        || $schoolYearId <= 0
        || $sourceId <= 0
    ) {
        return null;
    }

    $sql =
        'SELECT *
         FROM points_ledger
         WHERE user_id = :user_id
           AND school_year_id = :school_year_id
           AND point_type = :point_type
           AND source_type = :source_type
           AND source_id = :source_id
         ORDER BY revision_number DESC, id DESC
         LIMIT 1';

    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $statement = $pdo->prepare($sql);
    $statement->execute([
        'user_id' => $userId,
        'school_year_id' => $schoolYearId,
        'point_type' => $pointType,
        'source_type' => $sourceType,
        'source_id' => $sourceId,
    ]);

    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return is_array($row)
        ? $row
        : null;
}


/*
|--------------------------------------------------------------------------
| Student Progression Cache Sync
|--------------------------------------------------------------------------
| points_ledger remains the source of truth for earned HW Points.
|
| academic_points_possible is NOT invented here. Coursework/grading code will
| own that value as those systems are connected to Points. This helper uses
| the currently stored possible-points value to refresh progress_percentage.
|
| Automatic status changes are deliberately limited to active <-> eligible.
| Promoted, repeating, completed, withdrawn, and other reviewed states are
| never overwritten by this helper.
*/

function points_sync_student_year_progression(
    PDO $pdo,
    int $userId,
    int $schoolYearId
): ?array {
    $enrollment = points_user_school_year_enrollment(
        $pdo,
        $userId,
        $schoolYearId
    );

    if ($enrollment === null) {
        return null;
    }

    $earnedStatement = $pdo->prepare(
        'SELECT COALESCE(SUM(points), 0.00)
         FROM points_ledger
         WHERE user_id = :user_id
           AND school_year_id = :school_year_id
           AND point_type = \'academic\'
           AND is_reversed = 0'
    );

    $earnedStatement->execute([
        'user_id' => $userId,
        'school_year_id' => $schoolYearId,
    ]);

    $earned = (float) $earnedStatement->fetchColumn();
    $possible = (float) ($enrollment['academic_points_possible'] ?? 0);

    $percentage = $possible > 0
        ? round(($earned / $possible) * 100, 2)
        : 0.00;

    $requiredPercentage =
        (float) ($enrollment['required_percentage_snapshot'] ?? 85.00);

    $requiredPoints =
        $enrollment['required_points_snapshot'] !== null
            ? (float) $enrollment['required_points_snapshot']
            : null;

    $percentageMet =
        $percentage >= $requiredPercentage;

    $pointsMet =
        $requiredPoints === null
        || $earned >= $requiredPoints;

    $isEligible =
        $percentageMet
        && $pointsMet;

    $currentStatus =
        (string) ($enrollment['promotion_status'] ?? 'active');

    $nextStatus = $currentStatus;
    $eligibleAt = $enrollment['eligible_at'] ?? null;

    if (
        in_array(
            $currentStatus,
            ['active', 'eligible'],
            true
        )
    ) {
        if ($isEligible) {
            $nextStatus = 'eligible';

            if ($eligibleAt === null) {
                $eligibleAt = date('Y-m-d H:i:s');
            }
        } else {
            $nextStatus = 'active';
            $eligibleAt = null;
        }
    }

    $update = $pdo->prepare(
        'UPDATE student_year_enrollments
         SET academic_points_earned = :earned,
             progress_percentage = :progress_percentage,
             points_recalculated_at = NOW(),
             promotion_status = :promotion_status,
             eligible_at = :eligible_at
         WHERE id = :enrollment_id'
    );

    $update->execute([
        'earned' => number_format($earned, 2, '.', ''),
        'progress_percentage' => number_format($percentage, 2, '.', ''),
        'promotion_status' => $nextStatus,
        'eligible_at' => $eligibleAt,
        'enrollment_id' => (int) $enrollment['id'],
    ]);

    return [
        'enrollment_id' => (int) $enrollment['id'],
        'user_id' => $userId,
        'school_year_id' => $schoolYearId,
        'academic_points_earned' => number_format($earned, 2, '.', ''),
        'academic_points_possible' => number_format($possible, 2, '.', ''),
        'progress_percentage' => number_format($percentage, 2, '.', ''),
        'required_percentage' => number_format($requiredPercentage, 2, '.', ''),
        'required_points' => $requiredPoints === null
            ? null
            : number_format($requiredPoints, 2, '.', ''),
        'percentage_requirement_met' => $percentageMet,
        'points_requirement_met' => $pointsMet,
        'is_eligible' => $isEligible,
        'promotion_status' => $nextStatus,
        'eligible_at' => $eligibleAt,
    ];
}


/*
|--------------------------------------------------------------------------
| House Point Notifications
|--------------------------------------------------------------------------
| Staff-controlled House Point changes notify the affected member through
| the existing house_points notification channel. Achievement rewards keep
| their dedicated achievement_earned notification so members are not sent
| two notifications for one achievement.
*/

function points_house_notifications_enabled(
    PDO $pdo,
    int $userId
): bool {
    if ($userId <= 0) {
        return false;
    }

    $statement = $pdo->prepare(
        'SELECT notify_house_points
         FROM notification_preferences
         WHERE user_id = :user_id
         LIMIT 1'
    );

    $statement->execute([
        'user_id' => $userId,
    ]);

    $value = $statement->fetchColumn();

    /* No preference row means the schema default: notifications enabled. */
    if ($value === false) {
        return true;
    }

    return (int) $value === 1;
}


function points_format_display_amount(
    int|float|string $points
): string {
    $numeric = (float) $points;

    if (abs($numeric - round($numeric)) < 0.00001) {
        return number_format((int) round(abs($numeric)));
    }

    return number_format(abs($numeric), 2);
}


function points_create_house_notification(
    PDO $pdo,
    array $ledger,
    string $changeType,
    ?int $actorUserId = null,
    ?string $detail = null,
    ?string $oldPoints = null
): void {
    $userId = (int) ($ledger['user_id'] ?? 0);
    $ledgerId = (int) ($ledger['id'] ?? 0);

    if (
        $userId <= 0
        || $ledgerId <= 0
        || (string) ($ledger['point_type'] ?? '') !== 'house_only'
        || !points_house_notifications_enabled($pdo, $userId)
    ) {
        return;
    }

    if (($actorUserId ?? 0) <= 0) {
        $actorUserId = null;
    }

    $points = (float) ($ledger['points'] ?? 0);
    $amountText = points_format_display_amount($points);
    $description = trim((string) ($ledger['description'] ?? ''));
    $detail = trim((string) $detail);

    switch ($changeType) {
        case 'award':
            if ($points < 0) {
                $title = 'House Points Deducted';
                $message = $amountText . ' House Points were deducted from your account.';
            } else {
                $title = 'House Points Awarded';
                $message = 'You were awarded ' . $amountText . ' House Points.';
            }
            break;

        case 'revision':
            $title = 'House Points Adjusted';
            $oldAmountText = $oldPoints !== null
                ? points_format_display_amount($oldPoints)
                : null;

            if ($oldAmountText !== null) {
                $message =
                    'A House Point entry was adjusted from '
                    . $oldAmountText
                    . ' to '
                    . $amountText
                    . ' points.';
            } else {
                $message =
                    'A House Point entry was adjusted to '
                    . $amountText
                    . ' points.';
            }
            break;

        case 'reversal':
            $title = 'House Point Change Reversed';
            $message =
                'A '
                . $amountText
                . '-point House Point entry was reversed.';
            break;

        default:
            return;
    }

    if ($description !== '') {
        $message .= ' ' . $description;
    }

    if ($detail !== '') {
        $message .= ' Reason: ' . $detail;
    }

    if (function_exists('mb_substr')) {
        $message = mb_substr($message, 0, 2000, 'UTF-8');
    } else {
        $message = substr($message, 0, 2000);
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
            \'house_points\',
            \'points_ledger\',
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
        'related_entity_id' => $ledgerId,
        'title' => $title,
        'message' => $message,
        'link_url' => $linkUrl,
    ]);
}


/*
|--------------------------------------------------------------------------
| Award Points
|--------------------------------------------------------------------------
| Supported $options keys:
| - school_year_id ?int
| - offering_id ?int
| - source_id ?int
| - description ?string
| - is_public bool
| - awarded_by ?int
|
| Return shape includes:
| - ledger => inserted/existing row
| - created => true when a new row was inserted
| - duplicate => true when an automatic/source-bound award already existed
| - progression => refreshed academic progression data or null
*/

function points_award(
    PDO $pdo,
    int $userId,
    string $pointType,
    string $sourceType,
    int|float|string $points,
    array $options = []
): array {
    if ($userId <= 0 || !points_user_exists($pdo, $userId)) {
        throw new InvalidArgumentException(
            'A valid user is required to award points.'
        );
    }

    $pointType = trim($pointType);
    $sourceType = trim($sourceType);
    $amount = points_normalize_amount($points);

    points_validate_type_and_source(
        $pointType,
        $sourceType,
        $amount
    );

    $offeringId = isset($options['offering_id'])
        ? (int) $options['offering_id']
        : null;

    if (($offeringId ?? 0) <= 0) {
        $offeringId = null;
    }

    $sourceId = isset($options['source_id'])
        ? (int) $options['source_id']
        : null;

    if (($sourceId ?? 0) <= 0) {
        $sourceId = null;
    }

    if (
        points_source_requires_id($sourceType)
        && $sourceId === null
    ) {
        throw new InvalidArgumentException(
            'This point source requires a source ID.'
        );
    }

    if (
        $pointType === 'academic'
        && $offeringId === null
    ) {
        throw new InvalidArgumentException(
            'HW Points require a course offering.'
        );
    }

    $awardedBy = isset($options['awarded_by'])
        ? (int) $options['awarded_by']
        : null;

    if (($awardedBy ?? 0) <= 0) {
        $awardedBy = null;
    }

    if (
        in_array($sourceType, ['manual', 'bonus', 'penalty'], true)
        && $awardedBy === null
    ) {
        throw new InvalidArgumentException(
            'Manual point changes require the staff member who made the change.'
        );
    }

    if (
        $awardedBy !== null
        && !points_user_exists($pdo, $awardedBy)
    ) {
        throw new InvalidArgumentException(
            'The awarding staff member does not exist.'
        );
    }

    $schoolYearId = isset($options['school_year_id'])
        ? (int) $options['school_year_id']
        : null;

    if (($schoolYearId ?? 0) <= 0) {
        $schoolYearId = null;
    }

    $schoolYear = points_resolve_school_year(
        $pdo,
        $schoolYearId,
        $offeringId
    );

    points_assert_school_year_writable(
        $schoolYear
    );

    $resolvedSchoolYearId =
        (int) $schoolYear['id'];

    if ($pointType === 'academic') {
        points_validate_academic_context(
            $pdo,
            $userId,
            $resolvedSchoolYearId,
            (int) $offeringId,
            $sourceType,
            (int) $sourceId
        );
    }

    $description = points_normalize_description(
        $options['description'] ?? null
    );

    $isPublic = !empty($options['is_public'])
        ? 1
        : 0;

    /* Academic point details are never placed in the public House feed. */
    if ($pointType === 'academic') {
        $isPublic = 0;
    }

    $ownsTransaction = !$pdo->inTransaction();

    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        if ($sourceId !== null) {
            $existing = points_latest_source_entry(
                $pdo,
                $userId,
                $resolvedSchoolYearId,
                $pointType,
                $sourceType,
                $sourceId,
                true
            );

            if ($existing !== null) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }

                return [
                    'ledger' => $existing,
                    'created' => false,
                    'duplicate' => true,
                    'progression' => null,
                ];
            }
        }

        $houseId = points_active_house_id(
            $pdo,
            $userId
        );

        $statement = $pdo->prepare(
            'INSERT INTO points_ledger (
                user_id,
                house_id,
                school_year_id,
                offering_id,
                point_type,
                source_type,
                source_id,
                revision_number,
                replaces_ledger_id,
                points,
                description,
                is_public,
                awarded_by,
                is_reversed
             ) VALUES (
                :user_id,
                :house_id,
                :school_year_id,
                :offering_id,
                :point_type,
                :source_type,
                :source_id,
                1,
                NULL,
                :points,
                :description,
                :is_public,
                :awarded_by,
                0
             )'
        );

        $statement->execute([
            'user_id' => $userId,
            'house_id' => $houseId,
            'school_year_id' => $resolvedSchoolYearId,
            'offering_id' => $offeringId,
            'point_type' => $pointType,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'points' => $amount,
            'description' => $description,
            'is_public' => $isPublic,
            'awarded_by' => $awardedBy,
        ]);

        $ledgerId = (int) $pdo->lastInsertId();

        $progression = null;

        if ($pointType === 'academic') {
            $progression = points_sync_student_year_progression(
                $pdo,
                $userId,
                $resolvedSchoolYearId
            );
        }

        $ledger = points_ledger_entry(
            $pdo,
            $ledgerId
        );

        if (!is_array($ledger)) {
            throw new RuntimeException(
                'The point transaction was created but could not be reloaded.'
            );
        }

        if (
            $pointType === 'house_only'
            && in_array(
                $sourceType,
                ['manual', 'bonus', 'penalty'],
                true
            )
        ) {
            try {
                points_create_house_notification(
                    $pdo,
                    $ledger,
                    'award',
                    $awardedBy
                );
            } catch (Throwable $notificationError) {
                error_log(
                    'House Point notification error: '
                    . $notificationError->getMessage()
                );
            }
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }

        return [
            'ledger' => $ledger,
            'created' => true,
            'duplicate' => false,
            'progression' => $progression,
        ];
    } catch (PDOException $e) {
        if (
            $ownsTransaction
            && $pdo->inTransaction()
        ) {
            $pdo->rollBack();
        }

        /*
         * The database unique key is the final concurrency guard for a
         * source-bound automatic award. If another request inserted first,
         * return the existing transaction instead of double-awarding.
         */
        $mysqlErrorCode =
            isset($e->errorInfo[1])
                ? (int) $e->errorInfo[1]
                : 0;

        if (
            $mysqlErrorCode === 1062
            && $sourceId !== null
        ) {
            $existing = points_latest_source_entry(
                $pdo,
                $userId,
                $resolvedSchoolYearId,
                $pointType,
                $sourceType,
                $sourceId
            );

            if ($existing !== null) {
                return [
                    'ledger' => $existing,
                    'created' => false,
                    'duplicate' => true,
                    'progression' => null,
                ];
            }
        }

        throw $e;
    } catch (Throwable $e) {
        if (
            $ownsTransaction
            && $pdo->inTransaction()
        ) {
            $pdo->rollBack();
        }

        throw $e;
    }
}


/*
|--------------------------------------------------------------------------
| Revise Existing Point Transaction
|--------------------------------------------------------------------------
| A revision preserves the original transaction for history, marks the old
| row reversed, and inserts a replacement row with revision_number + 1.
|
| The original House snapshot, School Year, offering, source, and point type
| are preserved. A member changing Houses later does not rewrite history.
*/

function points_revise(
    PDO $pdo,
    int $ledgerId,
    int|float|string $newPoints,
    ?string $description,
    int $revisedBy,
    string $reason
): array {
    if ($ledgerId <= 0) {
        throw new InvalidArgumentException(
            'A valid ledger entry is required.'
        );
    }

    if (
        $revisedBy <= 0
        || !points_user_exists($pdo, $revisedBy)
    ) {
        throw new InvalidArgumentException(
            'A valid staff member is required to revise points.'
        );
    }

    $reason = points_normalize_reversal_reason($reason);
    $description = points_normalize_description($description);
    $amount = points_normalize_amount($newPoints);

    $ownsTransaction = !$pdo->inTransaction();

    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $existing = points_ledger_entry(
            $pdo,
            $ledgerId,
            true
        );

        if ($existing === null) {
            throw new RuntimeException(
                'The ledger entry does not exist.'
            );
        }

        $schoolYear = points_school_year(
            $pdo,
            (int) $existing['school_year_id']
        );

        if ($schoolYear === null) {
            throw new RuntimeException(
                'The ledger entry references a missing school year.'
            );
        }

        points_assert_school_year_writable(
            $schoolYear
        );

        if ((int) $existing['is_reversed'] === 1) {
            throw new RuntimeException(
                'A reversed ledger entry cannot be revised.'
            );
        }

        $latest = null;

        if ($existing['source_id'] !== null) {
            $latest = points_latest_source_entry(
                $pdo,
                (int) $existing['user_id'],
                (int) $existing['school_year_id'],
                (string) $existing['point_type'],
                (string) $existing['source_type'],
                (int) $existing['source_id'],
                true
            );

            if (
                $latest !== null
                && (int) $latest['id'] !== $ledgerId
            ) {
                throw new RuntimeException(
                    'Only the latest revision of a point transaction can be revised.'
                );
            }
        }

        points_validate_type_and_source(
            (string) $existing['point_type'],
            (string) $existing['source_type'],
            $amount
        );

        $reverse = $pdo->prepare(
            'UPDATE points_ledger
             SET is_reversed = 1,
                 reversed_at = NOW(),
                 reversed_by = :reversed_by,
                 reversal_reason = :reversal_reason
             WHERE id = :ledger_id
               AND is_reversed = 0'
        );

        $reverse->execute([
            'reversed_by' => $revisedBy,
            'reversal_reason' => $reason,
            'ledger_id' => $ledgerId,
        ]);

        if ($reverse->rowCount() !== 1) {
            throw new RuntimeException(
                'The point transaction could not be locked for revision.'
            );
        }

        $nextRevision =
            ((int) $existing['revision_number']) + 1;

        $insert = $pdo->prepare(
            'INSERT INTO points_ledger (
                user_id,
                house_id,
                school_year_id,
                offering_id,
                point_type,
                source_type,
                source_id,
                revision_number,
                replaces_ledger_id,
                points,
                description,
                is_public,
                awarded_by,
                is_reversed
             ) VALUES (
                :user_id,
                :house_id,
                :school_year_id,
                :offering_id,
                :point_type,
                :source_type,
                :source_id,
                :revision_number,
                :replaces_ledger_id,
                :points,
                :description,
                :is_public,
                :awarded_by,
                0
             )'
        );

        $insert->execute([
            'user_id' => (int) $existing['user_id'],
            'house_id' => $existing['house_id'] !== null
                ? (int) $existing['house_id']
                : null,
            'school_year_id' => (int) $existing['school_year_id'],
            'offering_id' => $existing['offering_id'] !== null
                ? (int) $existing['offering_id']
                : null,
            'point_type' => (string) $existing['point_type'],
            'source_type' => (string) $existing['source_type'],
            'source_id' => $existing['source_id'] !== null
                ? (int) $existing['source_id']
                : null,
            'revision_number' => $nextRevision,
            'replaces_ledger_id' => $ledgerId,
            'points' => $amount,
            'description' => $description
                ?? $existing['description'],
            'is_public' => (int) $existing['is_public'],
            'awarded_by' => $revisedBy,
        ]);

        $replacementId = (int) $pdo->lastInsertId();

        $progression = null;

        if ((string) $existing['point_type'] === 'academic') {
            $progression = points_sync_student_year_progression(
                $pdo,
                (int) $existing['user_id'],
                (int) $existing['school_year_id']
            );
        }

        $replacement = points_ledger_entry(
            $pdo,
            $replacementId
        );

        if (!is_array($replacement)) {
            throw new RuntimeException(
                'The replacement point transaction could not be reloaded.'
            );
        }

        if ((string) $existing['point_type'] === 'house_only') {
            try {
                points_create_house_notification(
                    $pdo,
                    $replacement,
                    'revision',
                    $revisedBy,
                    $reason,
                    (string) $existing['points']
                );
            } catch (Throwable $notificationError) {
                error_log(
                    'House Point revision notification error: '
                    . $notificationError->getMessage()
                );
            }
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }

        return [
            'reversed_ledger_id' => $ledgerId,
            'ledger' => $replacement,
            'progression' => $progression,
        ];
    } catch (Throwable $e) {
        if (
            $ownsTransaction
            && $pdo->inTransaction()
        ) {
            $pdo->rollBack();
        }

        throw $e;
    }
}


/*
|--------------------------------------------------------------------------
| Reverse Existing Point Transaction
|--------------------------------------------------------------------------
*/

function points_reverse(
    PDO $pdo,
    int $ledgerId,
    int $reversedBy,
    string $reason
): array {
    if ($ledgerId <= 0) {
        throw new InvalidArgumentException(
            'A valid ledger entry is required.'
        );
    }

    if (
        $reversedBy <= 0
        || !points_user_exists($pdo, $reversedBy)
    ) {
        throw new InvalidArgumentException(
            'A valid staff member is required to reverse points.'
        );
    }

    $reason = points_normalize_reversal_reason($reason);

    $ownsTransaction = !$pdo->inTransaction();

    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $entry = points_ledger_entry(
            $pdo,
            $ledgerId,
            true
        );

        if ($entry === null) {
            throw new RuntimeException(
                'The ledger entry does not exist.'
            );
        }

        $schoolYear = points_school_year(
            $pdo,
            (int) $entry['school_year_id']
        );

        if ($schoolYear === null) {
            throw new RuntimeException(
                'The ledger entry references a missing school year.'
            );
        }

        points_assert_school_year_writable(
            $schoolYear
        );

        if ((int) $entry['is_reversed'] === 1) {
            if ($ownsTransaction) {
                $pdo->commit();
            }

            return [
                'ledger' => $entry,
                'reversed' => false,
                'already_reversed' => true,
                'progression' => null,
            ];
        }

        if ($entry['source_id'] !== null) {
            $latest = points_latest_source_entry(
                $pdo,
                (int) $entry['user_id'],
                (int) $entry['school_year_id'],
                (string) $entry['point_type'],
                (string) $entry['source_type'],
                (int) $entry['source_id'],
                true
            );

            if (
                $latest !== null
                && (int) $latest['id'] !== $ledgerId
            ) {
                throw new RuntimeException(
                    'Only the latest revision of a point transaction can be reversed.'
                );
            }
        }

        $statement = $pdo->prepare(
            'UPDATE points_ledger
             SET is_reversed = 1,
                 reversed_at = NOW(),
                 reversed_by = :reversed_by,
                 reversal_reason = :reversal_reason
             WHERE id = :ledger_id
               AND is_reversed = 0'
        );

        $statement->execute([
            'reversed_by' => $reversedBy,
            'reversal_reason' => $reason,
            'ledger_id' => $ledgerId,
        ]);

        if ($statement->rowCount() !== 1) {
            throw new RuntimeException(
                'The point transaction could not be reversed.'
            );
        }

        $progression = null;

        if ((string) $entry['point_type'] === 'academic') {
            $progression = points_sync_student_year_progression(
                $pdo,
                (int) $entry['user_id'],
                (int) $entry['school_year_id']
            );
        }

        $reloaded = points_ledger_entry(
            $pdo,
            $ledgerId
        );

        if (!is_array($reloaded)) {
            throw new RuntimeException(
                'The reversed point transaction could not be reloaded.'
            );
        }

        if ((string) $entry['point_type'] === 'house_only') {
            try {
                points_create_house_notification(
                    $pdo,
                    $reloaded,
                    'reversal',
                    $reversedBy,
                    $reason
                );
            } catch (Throwable $notificationError) {
                error_log(
                    'House Point reversal notification error: '
                    . $notificationError->getMessage()
                );
            }
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }

        return [
            'ledger' => $reloaded,
            'reversed' => true,
            'already_reversed' => false,
            'progression' => $progression,
        ];
    } catch (Throwable $e) {
        if (
            $ownsTransaction
            && $pdo->inTransaction()
        ) {
            $pdo->rollBack();
        }

        throw $e;
    }
}


/*
|--------------------------------------------------------------------------
| Student Totals
|--------------------------------------------------------------------------
*/

function points_student_totals(
    PDO $pdo,
    int $userId,
    int $schoolYearId
): array {
    if (
        $userId <= 0
        || $schoolYearId <= 0
    ) {
        return [
            'user_id' => $userId,
            'school_year_id' => $schoolYearId,
            'academic' => '0.00',
            'house_only' => '0.00',
            'total' => '0.00',
        ];
    }

    $statement = $pdo->prepare(
        'SELECT
            COALESCE(
                SUM(
                    CASE
                        WHEN point_type = \'academic\' THEN points
                        ELSE 0
                    END
                ),
                0.00
            ) AS academic_total,
            COALESCE(
                SUM(
                    CASE
                        WHEN point_type = \'house_only\' THEN points
                        ELSE 0
                    END
                ),
                0.00
            ) AS house_only_total,
            COALESCE(SUM(points), 0.00) AS combined_total
         FROM points_ledger
         WHERE user_id = :user_id
           AND school_year_id = :school_year_id
           AND is_reversed = 0'
    );

    $statement->execute([
        'user_id' => $userId,
        'school_year_id' => $schoolYearId,
    ]);

    $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'user_id' => $userId,
        'school_year_id' => $schoolYearId,
        'academic' => number_format(
            (float) ($row['academic_total'] ?? 0),
            2,
            '.',
            ''
        ),
        'house_only' => number_format(
            (float) ($row['house_only_total'] ?? 0),
            2,
            '.',
            ''
        ),
        'total' => number_format(
            (float) ($row['combined_total'] ?? 0),
            2,
            '.',
            ''
        ),
    ];
}


/*
|--------------------------------------------------------------------------
| House Totals / House Cup
|--------------------------------------------------------------------------
| Active years are always calculated live from points_ledger.
| Finalized years prefer the frozen house_cup_results snapshot when present.
*/

function points_house_totals(
    PDO $pdo,
    int $schoolYearId,
    bool $preferFinalizedSnapshot = true
): array {
    $schoolYear = points_school_year(
        $pdo,
        $schoolYearId
    );

    if ($schoolYear === null) {
        return [];
    }

    if (
        $preferFinalizedSnapshot
        && (int) $schoolYear['is_finalized'] === 1
    ) {
        $snapshotStatement = $pdo->prepare(
            'SELECT
                h.id AS house_id,
                h.name,
                h.display_name,
                h.slug,
                h.display_color,
                h.primary_color,
                h.secondary_color,
                h.accent_color,
                h.is_active,
                h.sort_order,
                hcr.academic_points_total,
                hcr.house_only_points_total,
                hcr.final_points_total,
                hcr.final_rank,
                hcr.is_winner,
                hcr.finalized_at,
                1 AS is_frozen_snapshot
             FROM house_cup_results hcr
             INNER JOIN houses h
                ON h.id = hcr.house_id
             WHERE hcr.school_year_id = :school_year_id
             ORDER BY
                CASE
                    WHEN hcr.final_rank IS NULL THEN 999999
                    ELSE hcr.final_rank
                END ASC,
                h.sort_order ASC,
                h.id ASC'
        );

        $snapshotStatement->execute([
            'school_year_id' => $schoolYearId,
        ]);

        $snapshots =
            $snapshotStatement->fetchAll(
                PDO::FETCH_ASSOC
            ) ?: [];

        if ($snapshots !== []) {
            return $snapshots;
        }
    }

    $statement = $pdo->prepare(
        'SELECT
            h.id AS house_id,
            h.name,
            h.display_name,
            h.slug,
            h.display_color,
            h.primary_color,
            h.secondary_color,
            h.accent_color,
            h.is_active,
            h.sort_order,
            COALESCE(
                SUM(
                    CASE
                        WHEN pl.point_type = \'academic\' THEN pl.points
                        ELSE 0
                    END
                ),
                0.00
            ) AS academic_points_total,
            COALESCE(
                SUM(
                    CASE
                        WHEN pl.point_type = \'house_only\' THEN pl.points
                        ELSE 0
                    END
                ),
                0.00
            ) AS house_only_points_total,
            COALESCE(SUM(pl.points), 0.00) AS final_points_total,
            NULL AS final_rank,
            0 AS is_winner,
            NULL AS finalized_at,
            0 AS is_frozen_snapshot
         FROM houses h
         LEFT JOIN points_ledger pl
            ON pl.house_id = h.id
           AND pl.school_year_id = :school_year_id
           AND pl.is_reversed = 0
         GROUP BY
            h.id,
            h.name,
            h.display_name,
            h.slug,
            h.display_color,
            h.primary_color,
            h.secondary_color,
            h.accent_color,
            h.is_active,
            h.sort_order
         ORDER BY
            final_points_total DESC,
            h.sort_order ASC,
            h.id ASC'
    );

    $statement->execute([
        'school_year_id' => $schoolYearId,
    ]);

    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

    /*
     * Live ranks are display-only. Tied totals share the same rank.
     * Final official rank remains a finalization concern and is stored in
     * house_cup_results only when the school year is closed.
     */
    $rank = 0;
    $position = 0;
    $previousTotal = null;

    foreach ($rows as &$row) {
        $position++;
        $total = number_format(
            (float) ($row['final_points_total'] ?? 0),
            2,
            '.',
            ''
        );

        if (
            $previousTotal === null
            || $total !== $previousTotal
        ) {
            $rank = $position;
            $previousTotal = $total;
        }

        $row['academic_points_total'] = number_format(
            (float) ($row['academic_points_total'] ?? 0),
            2,
            '.',
            ''
        );

        $row['house_only_points_total'] = number_format(
            (float) ($row['house_only_points_total'] ?? 0),
            2,
            '.',
            ''
        );

        $row['final_points_total'] = $total;
        $row['live_rank'] = $rank;
    }
    unset($row);

    return $rows;
}


/*
|--------------------------------------------------------------------------
| Point History
|--------------------------------------------------------------------------
*/

function points_student_history(
    PDO $pdo,
    int $userId,
    int $schoolYearId,
    int $limit = 50,
    int $offset = 0,
    bool $includeReversed = true
): array {
    if (
        $userId <= 0
        || $schoolYearId <= 0
    ) {
        return [];
    }

    $limit = max(1, min($limit, 200));
    $offset = max(0, $offset);

    $sql =
        'SELECT
            pl.*,
            h.name AS house_name,
            h.display_name AS house_display_name,
            h.slug AS house_slug,
            h.display_color AS house_display_color,
            awarder.display_name AS awarded_by_display_name,
            reverser.display_name AS reversed_by_display_name
         FROM points_ledger pl
         LEFT JOIN houses h
            ON h.id = pl.house_id
         LEFT JOIN users awarder
            ON awarder.id = pl.awarded_by
         LEFT JOIN users reverser
            ON reverser.id = pl.reversed_by
         WHERE pl.user_id = :user_id
           AND pl.school_year_id = :school_year_id';

    if (!$includeReversed) {
        $sql .= ' AND pl.is_reversed = 0';
    }

    $sql .=
        ' ORDER BY pl.created_at DESC, pl.id DESC
          LIMIT ' . $limit . '
          OFFSET ' . $offset;

    $statement = $pdo->prepare($sql);
    $statement->execute([
        'user_id' => $userId,
        'school_year_id' => $schoolYearId,
    ]);

    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}


/*
|--------------------------------------------------------------------------
| Public House Point Feed
|--------------------------------------------------------------------------
| Only explicitly public House-only transactions are eligible. Academic/HW
| rows are intentionally excluded even if a bad caller attempted to mark one
| public.
*/

function points_public_house_feed(
    PDO $pdo,
    int $schoolYearId,
    ?int $houseId = null,
    int $limit = 25
): array {
    if ($schoolYearId <= 0) {
        return [];
    }

    $limit = max(1, min($limit, 100));

    $sql =
        'SELECT
            pl.id,
            pl.user_id,
            pl.house_id,
            pl.school_year_id,
            pl.source_type,
            pl.source_id,
            pl.points,
            pl.description,
            pl.created_at,
            u.display_name,
            u.profile_slug,
            h.name AS house_name,
            h.display_name AS house_display_name,
            h.slug AS house_slug,
            h.display_color AS house_display_color
         FROM points_ledger pl
         INNER JOIN users u
            ON u.id = pl.user_id
         LEFT JOIN houses h
            ON h.id = pl.house_id
         WHERE pl.school_year_id = :school_year_id
           AND pl.point_type = \'house_only\'
           AND pl.is_public = 1
           AND pl.is_reversed = 0';

    $parameters = [
        'school_year_id' => $schoolYearId,
    ];

    if (($houseId ?? 0) > 0) {
        $sql .= ' AND pl.house_id = :house_id';
        $parameters['house_id'] = (int) $houseId;
    }

    $sql .=
        ' ORDER BY pl.created_at DESC, pl.id DESC
          LIMIT ' . $limit;

    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);

    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
