<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

require_active_account();

$isProtectedSuperAdmin = current_user_is_superuser();
$canManageSorting = $isProtectedSuperAdmin || user_can('houses.sorting.manage');

if (!$canManageSorting) {
    require_permission('houses.sorting.manage');
}

$currentUserId = (int) (current_user_id() ?? 0);

function sorting_admin_query_id(string $key): int
{
    $value = $_GET[$key] ?? '';

    if (!is_scalar($value) || !ctype_digit((string) $value)) {
        return 0;
    }

    return max(0, (int) $value);
}

function sorting_admin_post_id(string $key): int
{
    $value = $_POST[$key] ?? '';

    if (!is_scalar($value) || !ctype_digit((string) $value)) {
        return 0;
    }

    return max(0, (int) $value);
}

function sorting_admin_post_int(string $key, int $default = 0): int
{
    $value = $_POST[$key] ?? null;

    if ($value === null || $value === '' || !is_scalar($value)) {
        return $default;
    }

    $filtered = filter_var((string) $value, FILTER_VALIDATE_INT);

    return $filtered === false ? $default : (int) $filtered;
}

function sorting_admin_text_length(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);
}

function sorting_admin_version(PDO $pdo, int $versionId): ?array
{
    if ($versionId <= 0) {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT
            v.*,
            (SELECT COUNT(*) FROM sorting_attempts sa WHERE sa.ceremony_version_id = v.id) AS attempt_count,
            (SELECT COUNT(*) FROM sorting_questions sq WHERE sq.ceremony_version_id = v.id AND sq.question_type = \'primary\') AS primary_count,
            (SELECT COUNT(*) FROM sorting_questions sq WHERE sq.ceremony_version_id = v.id AND sq.question_type = \'choosing\') AS choosing_count,
            (SELECT COUNT(*) FROM sorting_interludes si WHERE si.ceremony_version_id = v.id) AS interlude_count
         FROM sorting_ceremony_versions v
         WHERE v.id = :version_id
         LIMIT 1'
    );

    $statement->execute(['version_id' => $versionId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function sorting_admin_version_locked(array $version): bool
{
    return (int) ($version['attempt_count'] ?? 0) > 0;
}

function sorting_admin_question(PDO $pdo, int $questionId): ?array
{
    if ($questionId <= 0) {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT sq.*,
                (SELECT COUNT(*) FROM sorting_answers sa WHERE sa.question_id = sq.id) AS answer_count
         FROM sorting_questions sq
         WHERE sq.id = :question_id
         LIMIT 1'
    );

    $statement->execute(['question_id' => $questionId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function sorting_admin_interlude(PDO $pdo, int $interludeId): ?array
{
    if ($interludeId <= 0) {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT *
         FROM sorting_interludes
         WHERE id = :interlude_id
         LIMIT 1'
    );

    $statement->execute(['interlude_id' => $interludeId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function sorting_admin_audit(
    PDO $pdo,
    int $actorUserId,
    string $actionType,
    string $entityType,
    ?int $entityId,
    string $description
): void {
    if ($actorUserId <= 0) {
        return;
    }

    try {
        $statement = $pdo->prepare(
            'INSERT INTO audit_log (
                user_id,
                action_type,
                entity_type,
                entity_id,
                description,
                ip_address,
                user_agent
             ) VALUES (
                :user_id,
                :action_type,
                :entity_type,
                :entity_id,
                :description,
                :ip_address,
                :user_agent
             )'
        );

        $statement->execute([
            'user_id' => $actorUserId,
            'action_type' => $actionType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'description' => $description,
            'ip_address' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        ]);
    } catch (PDOException $exception) {
        error_log('Blackthorne Sorting Ceremony audit error: ' . $exception->getMessage());
    }
}

function sorting_admin_redirect(int $versionId = 0, array $query = []): never
{
    if ($versionId > 0) {
        $query = ['version' => $versionId] + $query;
    }

    $target = 'admin/sorting-ceremony.php';

    if ($query !== []) {
        $target .= '?' . http_build_query($query);
    }

    redirect(url($target));
}

function sorting_admin_validate_publish(PDO $pdo, int $versionId): array
{
    $errors = [];

    $primaryStatement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM sorting_questions
         WHERE ceremony_version_id = :version_id
           AND question_type = \'primary\'
           AND is_active = 1'
    );
    $primaryStatement->execute(['version_id' => $versionId]);

    if ((int) $primaryStatement->fetchColumn() === 0) {
        $errors[] = 'Add at least one active primary question before publishing.';
    }

    $answerStatement = $pdo->prepare(
        'SELECT sq.question_key, COUNT(sa.id) AS active_answers
         FROM sorting_questions sq
         LEFT JOIN sorting_answers sa
           ON sa.question_id = sq.id
          AND sa.is_active = 1
         WHERE sq.ceremony_version_id = :version_id
           AND sq.is_active = 1
         GROUP BY sq.id, sq.question_key
         HAVING COUNT(sa.id) < 2'
    );
    $answerStatement->execute(['version_id' => $versionId]);

    foreach ($answerStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $errors[] = 'Question "' . (string) $row['question_key'] . '" needs at least two active answers.';
    }

    $setStatement = $pdo->prepare(
        'SELECT sq.question_key, COUNT(sqh.id) AS house_count
         FROM sorting_questions sq
         LEFT JOIN sorting_question_houses sqh
           ON sqh.question_id = sq.id
         WHERE sq.ceremony_version_id = :version_id
           AND sq.question_type = \'choosing\'
           AND sq.choosing_strategy = \'exact_set\'
           AND sq.is_active = 1
         GROUP BY sq.id, sq.question_key
         HAVING COUNT(sqh.id) < 2'
    );
    $setStatement->execute(['version_id' => $versionId]);

    foreach ($setStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $errors[] = 'Exact-set Choosing question "' . (string) $row['question_key'] . '" needs at least two Houses.';
    }

    return $errors;
}

$errors = [];
$formAction = post_value('form_action');

if (is_post()) {
    require_valid_csrf();

    try {
        if ($formAction === 'create_version') {
            $name = trim(post_value('name'));
            $introTitle = trim(post_value('intro_title'));
            $introText = trim(post_value('intro_text'));
            $revealLeadText = trim(post_value('reveal_lead_text'));

            if ($name === '') {
                $errors[] = 'Enter a ceremony version name.';
            } elseif (sorting_admin_text_length($name) > 150) {
                $errors[] = 'The version name cannot exceed 150 characters.';
            }

            if (sorting_admin_text_length($introTitle) > 200) {
                $errors[] = 'The intro title cannot exceed 200 characters.';
            }

            if ($errors === []) {
                $nextNumber = (int) $pdo->query(
                    'SELECT COALESCE(MAX(version_number), 0) + 1 FROM sorting_ceremony_versions'
                )->fetchColumn();

                $statement = $pdo->prepare(
                    'INSERT INTO sorting_ceremony_versions (
                        version_number, name, status, intro_title, intro_text,
                        reveal_lead_text, created_by, updated_by
                     ) VALUES (
                        :version_number, :name, \'draft\', :intro_title, :intro_text,
                        :reveal_lead_text, :created_by, :updated_by
                     )'
                );
                $statement->execute([
                    'version_number' => $nextNumber,
                    'name' => $name,
                    'intro_title' => $introTitle !== '' ? $introTitle : null,
                    'intro_text' => $introText !== '' ? $introText : null,
                    'reveal_lead_text' => $revealLeadText !== '' ? $revealLeadText : null,
                    'created_by' => $currentUserId ?: null,
                    'updated_by' => $currentUserId ?: null,
                ]);

                $newVersionId = (int) $pdo->lastInsertId();

                sorting_admin_audit(
                    $pdo,
                    $currentUserId,
                    'sorting_version_created',
                    'sorting_ceremony_version',
                    $newVersionId,
                    'Created Sorting Ceremony version ' . $nextNumber . ' (' . $name . ').'
                );

                set_flash('success', 'Draft ceremony version created.');
                sorting_admin_redirect($newVersionId);
            }
        }

        if ($formAction === 'update_version') {
            $versionId = sorting_admin_post_id('version_id');
            $version = sorting_admin_version($pdo, $versionId);

            if ($version === null) {
                $errors[] = 'That ceremony version could not be found.';
            } elseif (sorting_admin_version_locked($version)) {
                $errors[] = 'This version is locked because a student attempt already exists. Duplicate it to make changes.';
            } else {
                $name = trim(post_value('name'));
                $introTitle = trim(post_value('intro_title'));
                $introText = trim(post_value('intro_text'));
                $revealLeadText = trim(post_value('reveal_lead_text'));

                if ($name === '') {
                    $errors[] = 'Enter a ceremony version name.';
                } elseif (sorting_admin_text_length($name) > 150) {
                    $errors[] = 'The version name cannot exceed 150 characters.';
                }

                if (sorting_admin_text_length($introTitle) > 200) {
                    $errors[] = 'The intro title cannot exceed 200 characters.';
                }

                if ($errors === []) {
                    $statement = $pdo->prepare(
                        'UPDATE sorting_ceremony_versions
                         SET name = :name,
                             intro_title = :intro_title,
                             intro_text = :intro_text,
                             reveal_lead_text = :reveal_lead_text,
                             updated_by = :updated_by
                         WHERE id = :version_id'
                    );
                    $statement->execute([
                        'name' => $name,
                        'intro_title' => $introTitle !== '' ? $introTitle : null,
                        'intro_text' => $introText !== '' ? $introText : null,
                        'reveal_lead_text' => $revealLeadText !== '' ? $revealLeadText : null,
                        'updated_by' => $currentUserId ?: null,
                        'version_id' => $versionId,
                    ]);

                    sorting_admin_audit(
                        $pdo,
                        $currentUserId,
                        'sorting_version_updated',
                        'sorting_ceremony_version',
                        $versionId,
                        'Updated Sorting Ceremony version ' . (int) $version['version_number'] . '.'
                    );

                    set_flash('success', 'Ceremony version details saved.');
                    sorting_admin_redirect($versionId);
                }
            }
        }

        if ($formAction === 'duplicate_version') {
            $sourceVersionId = sorting_admin_post_id('version_id');
            $source = sorting_admin_version($pdo, $sourceVersionId);

            if ($source === null) {
                $errors[] = 'That ceremony version could not be found.';
            } else {
                $pdo->beginTransaction();

                $nextNumber = (int) $pdo->query(
                    'SELECT COALESCE(MAX(version_number), 0) + 1 FROM sorting_ceremony_versions FOR UPDATE'
                )->fetchColumn();

                $copyName = 'Copy of ' . (string) $source['name'];
                if (sorting_admin_text_length($copyName) > 150) {
                    $copyName = 'Ceremony Version ' . $nextNumber;
                }

                $versionInsert = $pdo->prepare(
                    'INSERT INTO sorting_ceremony_versions (
                        version_number, name, status, intro_title, intro_text,
                        reveal_lead_text, created_by, updated_by
                     ) VALUES (
                        :version_number, :name, \'draft\', :intro_title, :intro_text,
                        :reveal_lead_text, :created_by, :updated_by
                     )'
                );
                $versionInsert->execute([
                    'version_number' => $nextNumber,
                    'name' => $copyName,
                    'intro_title' => $source['intro_title'],
                    'intro_text' => $source['intro_text'],
                    'reveal_lead_text' => $source['reveal_lead_text'],
                    'created_by' => $currentUserId ?: null,
                    'updated_by' => $currentUserId ?: null,
                ]);
                $newVersionId = (int) $pdo->lastInsertId();

                $questionsStatement = $pdo->prepare(
                    'SELECT * FROM sorting_questions
                     WHERE ceremony_version_id = :version_id
                     ORDER BY question_type, sort_order, id'
                );
                $questionsStatement->execute(['version_id' => $sourceVersionId]);

                $questionInsert = $pdo->prepare(
                    'INSERT INTO sorting_questions (
                        ceremony_version_id, question_key, question_type, choosing_strategy,
                        prompt, prelude_text, min_tied_houses, max_tied_houses,
                        sort_order, is_active, created_by, updated_by
                     ) VALUES (
                        :ceremony_version_id, :question_key, :question_type, :choosing_strategy,
                        :prompt, :prelude_text, :min_tied_houses, :max_tied_houses,
                        :sort_order, :is_active, :created_by, :updated_by
                     )'
                );
                $houseMapSelect = $pdo->prepare(
                    'SELECT house_id FROM sorting_question_houses WHERE question_id = :question_id ORDER BY id'
                );
                $houseMapInsert = $pdo->prepare(
                    'INSERT INTO sorting_question_houses (question_id, house_id) VALUES (:question_id, :house_id)'
                );
                $answerSelect = $pdo->prepare(
                    'SELECT * FROM sorting_answers WHERE question_id = :question_id ORDER BY sort_order, id'
                );
                $answerInsert = $pdo->prepare(
                    'INSERT INTO sorting_answers (
                        question_id, answer_text, house_id, sort_order, is_active, created_by, updated_by
                     ) VALUES (
                        :question_id, :answer_text, :house_id, :sort_order, :is_active, :created_by, :updated_by
                     )'
                );

                foreach ($questionsStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $question) {
                    $questionInsert->execute([
                        'ceremony_version_id' => $newVersionId,
                        'question_key' => $question['question_key'],
                        'question_type' => $question['question_type'],
                        'choosing_strategy' => $question['choosing_strategy'],
                        'prompt' => $question['prompt'],
                        'prelude_text' => $question['prelude_text'],
                        'min_tied_houses' => $question['min_tied_houses'],
                        'max_tied_houses' => $question['max_tied_houses'],
                        'sort_order' => $question['sort_order'],
                        'is_active' => $question['is_active'],
                        'created_by' => $currentUserId ?: null,
                        'updated_by' => $currentUserId ?: null,
                    ]);
                    $newQuestionId = (int) $pdo->lastInsertId();

                    $houseMapSelect->execute(['question_id' => (int) $question['id']]);
                    foreach ($houseMapSelect->fetchAll(PDO::FETCH_COLUMN) ?: [] as $houseId) {
                        $houseMapInsert->execute([
                            'question_id' => $newQuestionId,
                            'house_id' => (int) $houseId,
                        ]);
                    }

                    $answerSelect->execute(['question_id' => (int) $question['id']]);
                    foreach ($answerSelect->fetchAll(PDO::FETCH_ASSOC) ?: [] as $answer) {
                        $answerInsert->execute([
                            'question_id' => $newQuestionId,
                            'answer_text' => $answer['answer_text'],
                            'house_id' => $answer['house_id'],
                            'sort_order' => $answer['sort_order'],
                            'is_active' => $answer['is_active'],
                            'created_by' => $currentUserId ?: null,
                            'updated_by' => $currentUserId ?: null,
                        ]);
                    }
                }

                $interludeSelect = $pdo->prepare(
                    'SELECT * FROM sorting_interludes
                     WHERE ceremony_version_id = :version_id
                     ORDER BY after_primary_question_number, sort_order, id'
                );
                $interludeSelect->execute(['version_id' => $sourceVersionId]);
                $interludeInsert = $pdo->prepare(
                    'INSERT INTO sorting_interludes (
                        ceremony_version_id, after_primary_question_number, title, body_text,
                        animation_style, sort_order, is_active, created_by, updated_by
                     ) VALUES (
                        :ceremony_version_id, :after_primary_question_number, :title, :body_text,
                        :animation_style, :sort_order, :is_active, :created_by, :updated_by
                     )'
                );

                foreach ($interludeSelect->fetchAll(PDO::FETCH_ASSOC) ?: [] as $interlude) {
                    $interludeInsert->execute([
                        'ceremony_version_id' => $newVersionId,
                        'after_primary_question_number' => $interlude['after_primary_question_number'],
                        'title' => $interlude['title'],
                        'body_text' => $interlude['body_text'],
                        'animation_style' => $interlude['animation_style'],
                        'sort_order' => $interlude['sort_order'],
                        'is_active' => $interlude['is_active'],
                        'created_by' => $currentUserId ?: null,
                        'updated_by' => $currentUserId ?: null,
                    ]);
                }

                $pdo->commit();

                sorting_admin_audit(
                    $pdo,
                    $currentUserId,
                    'sorting_version_duplicated',
                    'sorting_ceremony_version',
                    $newVersionId,
                    'Duplicated Sorting Ceremony version ' . (int) $source['version_number'] . ' into version ' . $nextNumber . '.'
                );

                set_flash('success', 'Ceremony version duplicated into a new editable draft.');
                sorting_admin_redirect($newVersionId);
            }
        }

        if ($formAction === 'publish_version') {
            $versionId = sorting_admin_post_id('version_id');
            $version = sorting_admin_version($pdo, $versionId);

            if ($version === null) {
                $errors[] = 'That ceremony version could not be found.';
            } elseif ((string) $version['status'] !== 'draft') {
                $errors[] = 'Only a draft ceremony version can be published.';
            } else {
                $errors = array_merge($errors, sorting_admin_validate_publish($pdo, $versionId));

                if ($errors === []) {
                    $pdo->beginTransaction();

                    $retireStatement = $pdo->prepare(
                        'UPDATE sorting_ceremony_versions
                         SET status = \'retired\', updated_by = :updated_by
                         WHERE status = \'published\'
                           AND id <> :version_id'
                    );
                    $retireStatement->execute([
                        'updated_by' => $currentUserId ?: null,
                        'version_id' => $versionId,
                    ]);

                    $publishStatement = $pdo->prepare(
                        'UPDATE sorting_ceremony_versions
                         SET status = \'published\',
                             published_at = NOW(),
                             published_by = :published_by,
                             updated_by = :updated_by
                         WHERE id = :version_id
                           AND status = \'draft\''
                    );
                    $publishStatement->execute([
                        'published_by' => $currentUserId ?: null,
                        'updated_by' => $currentUserId ?: null,
                        'version_id' => $versionId,
                    ]);

                    $pdo->commit();

                    sorting_admin_audit(
                        $pdo,
                        $currentUserId,
                        'sorting_version_published',
                        'sorting_ceremony_version',
                        $versionId,
                        'Published Sorting Ceremony version ' . (int) $version['version_number'] . ' and retired any previously published version.'
                    );

                    set_flash('success', 'Ceremony version published. The previously published version, if any, was retired.');
                    sorting_admin_redirect($versionId);
                }
            }
        }

        if ($formAction === 'delete_version') {
            $versionId = sorting_admin_post_id('version_id');
            $version = sorting_admin_version($pdo, $versionId);

            if ($version === null) {
                $errors[] = 'That ceremony version could not be found.';
            } elseif ((string) $version['status'] !== 'draft') {
                $errors[] = 'Only unused draft versions can be deleted.';
            } elseif (sorting_admin_version_locked($version)) {
                $errors[] = 'This draft has a student attempt and cannot be deleted.';
            } else {
                $statement = $pdo->prepare(
                    'DELETE FROM sorting_ceremony_versions
                     WHERE id = :version_id
                       AND status = \'draft\''
                );
                $statement->execute(['version_id' => $versionId]);

                sorting_admin_audit(
                    $pdo,
                    $currentUserId,
                    'sorting_version_deleted',
                    'sorting_ceremony_version',
                    $versionId,
                    'Deleted unused Sorting Ceremony draft version ' . (int) $version['version_number'] . '.'
                );

                set_flash('success', 'Unused draft ceremony version deleted.');
                sorting_admin_redirect();
            }
        }

        if ($formAction === 'save_question') {
            $versionId = sorting_admin_post_id('version_id');
            $questionId = sorting_admin_post_id('question_id');
            $version = sorting_admin_version($pdo, $versionId);
            $question = $questionId > 0 ? sorting_admin_question($pdo, $questionId) : null;

            if ($version === null) {
                $errors[] = 'That ceremony version could not be found.';
            } elseif (sorting_admin_version_locked($version)) {
                $errors[] = 'This version is locked because a student attempt already exists. Duplicate it to edit ceremony content.';
            } elseif ($questionId > 0 && ($question === null || (int) $question['ceremony_version_id'] !== $versionId)) {
                $errors[] = 'That question does not belong to this ceremony version.';
            } else {
                $questionKey = trim(post_value('question_key'));
                $questionType = post_value('question_type');
                $choosingStrategy = post_value('choosing_strategy');
                $prompt = trim(post_value('prompt'));
                $preludeText = trim(post_value('prelude_text'));
                $minTiedHouses = sorting_admin_post_int('min_tied_houses', 0);
                $maxTiedHouses = sorting_admin_post_int('max_tied_houses', 0);
                $sortOrder = max(0, sorting_admin_post_int('sort_order', 0));
                $isActive = post_value('is_active') === '1' ? 1 : 0;

                if ($questionKey === '' || sorting_admin_text_length($questionKey) > 100) {
                    $errors[] = 'Enter a question key up to 100 characters.';
                }

                if (!in_array($questionType, ['primary', 'choosing'], true)) {
                    $errors[] = 'Choose a valid question type.';
                }

                if ($prompt === '') {
                    $errors[] = 'Enter the question prompt.';
                }

                if ($questionType === 'primary') {
                    $choosingStrategy = 'none';
                    $minTiedHouses = 0;
                    $maxTiedHouses = 0;
                } else {
                    if (!in_array($choosingStrategy, ['exact_set', 'tied_set'], true)) {
                        $errors[] = 'Choosing questions must use Exact House Set or Tied House Count.';
                    }

                    if ($minTiedHouses < 2 || $minTiedHouses > 4 || $maxTiedHouses < 2 || $maxTiedHouses > 4 || $minTiedHouses > $maxTiedHouses) {
                        $errors[] = 'Choosing questions must use a tie range between 2 and 4 Houses.';
                    }
                }

                $selectedHouseIds = [];
                $postedHouseIds = $_POST['house_ids'] ?? [];
                if (is_array($postedHouseIds)) {
                    foreach ($postedHouseIds as $houseId) {
                        if (is_scalar($houseId) && ctype_digit((string) $houseId) && (int) $houseId > 0) {
                            $selectedHouseIds[] = (int) $houseId;
                        }
                    }
                }
                $selectedHouseIds = array_values(array_unique($selectedHouseIds));

                if ($questionType === 'choosing' && $choosingStrategy === 'exact_set' && count($selectedHouseIds) < 2) {
                    $errors[] = 'An Exact House Set Choosing question must include at least two Houses.';
                }

                $duplicateSql =
                    'SELECT id FROM sorting_questions
                     WHERE ceremony_version_id = :version_id
                       AND question_key = :question_key';
                $duplicateParams = [
                    'version_id' => $versionId,
                    'question_key' => $questionKey,
                ];
                if ($questionId > 0) {
                    $duplicateSql .= ' AND id <> :question_id';
                    $duplicateParams['question_id'] = $questionId;
                }
                $duplicateSql .= ' LIMIT 1';

                $duplicateStatement = $pdo->prepare($duplicateSql);
                $duplicateStatement->execute($duplicateParams);
                if ($duplicateStatement->fetchColumn() !== false) {
                    $errors[] = 'That question key is already used in this ceremony version.';
                }

                if ($selectedHouseIds !== []) {
                    $placeholders = implode(',', array_fill(0, count($selectedHouseIds), '?'));
                    $houseCheck = $pdo->prepare(
                        'SELECT COUNT(*) FROM houses WHERE id IN (' . $placeholders . ') AND is_active = 1'
                    );
                    $houseCheck->execute($selectedHouseIds);
                    if ((int) $houseCheck->fetchColumn() !== count($selectedHouseIds)) {
                        $errors[] = 'One or more selected Houses are invalid or inactive.';
                    }
                }

                if ($errors === []) {
                    $pdo->beginTransaction();

                    if ($questionId > 0) {
                        $statement = $pdo->prepare(
                            'UPDATE sorting_questions
                             SET question_key = :question_key,
                                 question_type = :question_type,
                                 choosing_strategy = :choosing_strategy,
                                 prompt = :prompt,
                                 prelude_text = :prelude_text,
                                 min_tied_houses = :min_tied_houses,
                                 max_tied_houses = :max_tied_houses,
                                 sort_order = :sort_order,
                                 is_active = :is_active,
                                 updated_by = :updated_by
                             WHERE id = :question_id
                               AND ceremony_version_id = :version_id'
                        );
                        $statement->execute([
                            'question_key' => $questionKey,
                            'question_type' => $questionType,
                            'choosing_strategy' => $choosingStrategy,
                            'prompt' => $prompt,
                            'prelude_text' => $preludeText !== '' ? $preludeText : null,
                            'min_tied_houses' => $minTiedHouses > 0 ? $minTiedHouses : null,
                            'max_tied_houses' => $maxTiedHouses > 0 ? $maxTiedHouses : null,
                            'sort_order' => $sortOrder,
                            'is_active' => $isActive,
                            'updated_by' => $currentUserId ?: null,
                            'question_id' => $questionId,
                            'version_id' => $versionId,
                        ]);
                    } else {
                        $statement = $pdo->prepare(
                            'INSERT INTO sorting_questions (
                                ceremony_version_id, question_key, question_type, choosing_strategy,
                                prompt, prelude_text, min_tied_houses, max_tied_houses,
                                sort_order, is_active, created_by, updated_by
                             ) VALUES (
                                :ceremony_version_id, :question_key, :question_type, :choosing_strategy,
                                :prompt, :prelude_text, :min_tied_houses, :max_tied_houses,
                                :sort_order, :is_active, :created_by, :updated_by
                             )'
                        );
                        $statement->execute([
                            'ceremony_version_id' => $versionId,
                            'question_key' => $questionKey,
                            'question_type' => $questionType,
                            'choosing_strategy' => $choosingStrategy,
                            'prompt' => $prompt,
                            'prelude_text' => $preludeText !== '' ? $preludeText : null,
                            'min_tied_houses' => $minTiedHouses > 0 ? $minTiedHouses : null,
                            'max_tied_houses' => $maxTiedHouses > 0 ? $maxTiedHouses : null,
                            'sort_order' => $sortOrder,
                            'is_active' => $isActive,
                            'created_by' => $currentUserId ?: null,
                            'updated_by' => $currentUserId ?: null,
                        ]);
                        $questionId = (int) $pdo->lastInsertId();
                    }

                    $pdo->prepare('DELETE FROM sorting_question_houses WHERE question_id = :question_id')
                        ->execute(['question_id' => $questionId]);

                    if ($questionType === 'choosing' && $choosingStrategy === 'exact_set') {
                        $mapInsert = $pdo->prepare(
                            'INSERT INTO sorting_question_houses (question_id, house_id)
                             VALUES (:question_id, :house_id)'
                        );
                        foreach ($selectedHouseIds as $houseId) {
                            $mapInsert->execute([
                                'question_id' => $questionId,
                                'house_id' => $houseId,
                            ]);
                        }
                    }

                    $pdo->commit();

                    sorting_admin_audit(
                        $pdo,
                        $currentUserId,
                        $question === null ? 'sorting_question_created' : 'sorting_question_updated',
                        'sorting_question',
                        $questionId,
                        ($question === null ? 'Created' : 'Updated') . ' Sorting Ceremony question ' . $questionKey . '.'
                    );

                    set_flash('success', $question === null ? 'Question created.' : 'Question saved.');
                    sorting_admin_redirect($versionId, ['question' => $questionId]);
                }
            }
        }

        if ($formAction === 'delete_question') {
            $versionId = sorting_admin_post_id('version_id');
            $questionId = sorting_admin_post_id('question_id');
            $version = sorting_admin_version($pdo, $versionId);
            $question = sorting_admin_question($pdo, $questionId);

            if ($version === null || $question === null || (int) $question['ceremony_version_id'] !== $versionId) {
                $errors[] = 'That question could not be found.';
            } elseif (sorting_admin_version_locked($version)) {
                $errors[] = 'This version is locked and its questions cannot be deleted.';
            } else {
                $statement = $pdo->prepare(
                    'DELETE FROM sorting_questions
                     WHERE id = :question_id
                       AND ceremony_version_id = :version_id'
                );
                $statement->execute([
                    'question_id' => $questionId,
                    'version_id' => $versionId,
                ]);

                sorting_admin_audit(
                    $pdo,
                    $currentUserId,
                    'sorting_question_deleted',
                    'sorting_question',
                    $questionId,
                    'Deleted Sorting Ceremony question ' . (string) $question['question_key'] . '.'
                );

                set_flash('success', 'Question deleted.');
                sorting_admin_redirect($versionId);
            }
        }

        if ($formAction === 'save_answer') {
            $versionId = sorting_admin_post_id('version_id');
            $questionId = sorting_admin_post_id('question_id');
            $answerId = sorting_admin_post_id('answer_id');
            $version = sorting_admin_version($pdo, $versionId);
            $question = sorting_admin_question($pdo, $questionId);

            if ($version === null || $question === null || (int) $question['ceremony_version_id'] !== $versionId) {
                $errors[] = 'That question could not be found.';
            } elseif (sorting_admin_version_locked($version)) {
                $errors[] = 'This version is locked and its answers cannot be changed.';
            } else {
                $answerText = trim(post_value('answer_text'));
                $houseId = sorting_admin_post_id('house_id');
                $sortOrder = max(0, sorting_admin_post_int('answer_sort_order', 0));
                $isActive = post_value('answer_is_active') === '1' ? 1 : 0;

                if ($answerText === '') {
                    $errors[] = 'Enter answer text.';
                }

                $houseStatement = $pdo->prepare('SELECT 1 FROM houses WHERE id = :house_id AND is_active = 1 LIMIT 1');
                $houseStatement->execute(['house_id' => $houseId]);
                if ($houseId <= 0 || $houseStatement->fetchColumn() === false) {
                    $errors[] = 'Choose a valid active House for this answer.';
                }

                if ($answerId > 0) {
                    $answerCheck = $pdo->prepare(
                        'SELECT 1 FROM sorting_answers WHERE id = :answer_id AND question_id = :question_id LIMIT 1'
                    );
                    $answerCheck->execute([
                        'answer_id' => $answerId,
                        'question_id' => $questionId,
                    ]);
                    if ($answerCheck->fetchColumn() === false) {
                        $errors[] = 'That answer does not belong to this question.';
                    }
                }

                if ($errors === []) {
                    if ($answerId > 0) {
                        $statement = $pdo->prepare(
                            'UPDATE sorting_answers
                             SET answer_text = :answer_text,
                                 house_id = :house_id,
                                 sort_order = :sort_order,
                                 is_active = :is_active,
                                 updated_by = :updated_by
                             WHERE id = :answer_id
                               AND question_id = :question_id'
                        );
                        $statement->execute([
                            'answer_text' => $answerText,
                            'house_id' => $houseId,
                            'sort_order' => $sortOrder,
                            'is_active' => $isActive,
                            'updated_by' => $currentUserId ?: null,
                            'answer_id' => $answerId,
                            'question_id' => $questionId,
                        ]);
                    } else {
                        $statement = $pdo->prepare(
                            'INSERT INTO sorting_answers (
                                question_id, answer_text, house_id, sort_order, is_active, created_by, updated_by
                             ) VALUES (
                                :question_id, :answer_text, :house_id, :sort_order, :is_active, :created_by, :updated_by
                             )'
                        );
                        $statement->execute([
                            'question_id' => $questionId,
                            'answer_text' => $answerText,
                            'house_id' => $houseId,
                            'sort_order' => $sortOrder,
                            'is_active' => $isActive,
                            'created_by' => $currentUserId ?: null,
                            'updated_by' => $currentUserId ?: null,
                        ]);
                        $answerId = (int) $pdo->lastInsertId();
                    }

                    sorting_admin_audit(
                        $pdo,
                        $currentUserId,
                        'sorting_answer_saved',
                        'sorting_answer',
                        $answerId,
                        'Saved an answer for Sorting Ceremony question ' . (string) $question['question_key'] . '.'
                    );

                    set_flash('success', 'Answer saved.');
                    sorting_admin_redirect($versionId, ['question' => $questionId]);
                }
            }
        }

        if ($formAction === 'delete_answer') {
            $versionId = sorting_admin_post_id('version_id');
            $questionId = sorting_admin_post_id('question_id');
            $answerId = sorting_admin_post_id('answer_id');
            $version = sorting_admin_version($pdo, $versionId);
            $question = sorting_admin_question($pdo, $questionId);

            if ($version === null || $question === null || (int) $question['ceremony_version_id'] !== $versionId) {
                $errors[] = 'That question could not be found.';
            } elseif (sorting_admin_version_locked($version)) {
                $errors[] = 'This version is locked and its answers cannot be deleted.';
            } else {
                $statement = $pdo->prepare(
                    'DELETE FROM sorting_answers WHERE id = :answer_id AND question_id = :question_id'
                );
                $statement->execute([
                    'answer_id' => $answerId,
                    'question_id' => $questionId,
                ]);

                set_flash('success', 'Answer deleted.');
                sorting_admin_redirect($versionId, ['question' => $questionId]);
            }
        }

        if ($formAction === 'save_interlude') {
            $versionId = sorting_admin_post_id('version_id');
            $interludeId = sorting_admin_post_id('interlude_id');
            $version = sorting_admin_version($pdo, $versionId);
            $interlude = $interludeId > 0 ? sorting_admin_interlude($pdo, $interludeId) : null;

            if ($version === null) {
                $errors[] = 'That ceremony version could not be found.';
            } elseif (sorting_admin_version_locked($version)) {
                $errors[] = 'This version is locked and its interludes cannot be changed.';
            } elseif ($interludeId > 0 && ($interlude === null || (int) $interlude['ceremony_version_id'] !== $versionId)) {
                $errors[] = 'That interlude does not belong to this ceremony version.';
            } else {
                $afterQuestion = max(1, sorting_admin_post_int('after_primary_question_number', 1));
                $title = trim(post_value('interlude_title'));
                $bodyText = trim(post_value('body_text'));
                $animationStyle = post_value('animation_style');
                $sortOrder = max(0, sorting_admin_post_int('interlude_sort_order', 0));
                $isActive = post_value('interlude_is_active') === '1' ? 1 : 0;

                if ($bodyText === '') {
                    $errors[] = 'Enter interlude text.';
                }

                if (sorting_admin_text_length($title) > 200) {
                    $errors[] = 'The interlude title cannot exceed 200 characters.';
                }

                if (!in_array($animationStyle, ['fade', 'reveal', 'glow', 'whisper'], true)) {
                    $errors[] = 'Choose a valid interlude animation style.';
                }

                if ($errors === []) {
                    if ($interludeId > 0) {
                        $statement = $pdo->prepare(
                            'UPDATE sorting_interludes
                             SET after_primary_question_number = :after_primary_question_number,
                                 title = :title,
                                 body_text = :body_text,
                                 animation_style = :animation_style,
                                 sort_order = :sort_order,
                                 is_active = :is_active,
                                 updated_by = :updated_by
                             WHERE id = :interlude_id
                               AND ceremony_version_id = :version_id'
                        );
                        $statement->execute([
                            'after_primary_question_number' => $afterQuestion,
                            'title' => $title !== '' ? $title : null,
                            'body_text' => $bodyText,
                            'animation_style' => $animationStyle,
                            'sort_order' => $sortOrder,
                            'is_active' => $isActive,
                            'updated_by' => $currentUserId ?: null,
                            'interlude_id' => $interludeId,
                            'version_id' => $versionId,
                        ]);
                    } else {
                        $statement = $pdo->prepare(
                            'INSERT INTO sorting_interludes (
                                ceremony_version_id, after_primary_question_number, title, body_text,
                                animation_style, sort_order, is_active, created_by, updated_by
                             ) VALUES (
                                :ceremony_version_id, :after_primary_question_number, :title, :body_text,
                                :animation_style, :sort_order, :is_active, :created_by, :updated_by
                             )'
                        );
                        $statement->execute([
                            'ceremony_version_id' => $versionId,
                            'after_primary_question_number' => $afterQuestion,
                            'title' => $title !== '' ? $title : null,
                            'body_text' => $bodyText,
                            'animation_style' => $animationStyle,
                            'sort_order' => $sortOrder,
                            'is_active' => $isActive,
                            'created_by' => $currentUserId ?: null,
                            'updated_by' => $currentUserId ?: null,
                        ]);
                        $interludeId = (int) $pdo->lastInsertId();
                    }

                    sorting_admin_audit(
                        $pdo,
                        $currentUserId,
                        'sorting_interlude_saved',
                        'sorting_interlude',
                        $interludeId,
                        'Saved a Sorting Ceremony interlude after primary question ' . $afterQuestion . '.'
                    );

                    set_flash('success', 'Interlude saved.');
                    sorting_admin_redirect($versionId, ['interlude' => $interludeId]);
                }
            }
        }

        if ($formAction === 'delete_interlude') {
            $versionId = sorting_admin_post_id('version_id');
            $interludeId = sorting_admin_post_id('interlude_id');
            $version = sorting_admin_version($pdo, $versionId);
            $interlude = sorting_admin_interlude($pdo, $interludeId);

            if ($version === null || $interlude === null || (int) $interlude['ceremony_version_id'] !== $versionId) {
                $errors[] = 'That interlude could not be found.';
            } elseif (sorting_admin_version_locked($version)) {
                $errors[] = 'This version is locked and its interludes cannot be deleted.';
            } else {
                $statement = $pdo->prepare(
                    'DELETE FROM sorting_interludes
                     WHERE id = :interlude_id
                       AND ceremony_version_id = :version_id'
                );
                $statement->execute([
                    'interlude_id' => $interludeId,
                    'version_id' => $versionId,
                ]);

                set_flash('success', 'Interlude deleted.');
                sorting_admin_redirect($versionId);
            }
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Blackthorne Sorting Ceremony Manager error: ' . $exception->getMessage());
        $errors[] = 'The requested Sorting Ceremony change could not be completed.';
    }
}

$successMessage = get_flash('success');

$versionsStatement = $pdo->query(
    'SELECT
        v.*,
        (SELECT COUNT(*) FROM sorting_attempts sa WHERE sa.ceremony_version_id = v.id) AS attempt_count,
        (SELECT COUNT(*) FROM sorting_questions sq WHERE sq.ceremony_version_id = v.id AND sq.question_type = \'primary\') AS primary_count,
        (SELECT COUNT(*) FROM sorting_questions sq WHERE sq.ceremony_version_id = v.id AND sq.question_type = \'choosing\') AS choosing_count,
        (SELECT COUNT(*) FROM sorting_interludes si WHERE si.ceremony_version_id = v.id) AS interlude_count
     FROM sorting_ceremony_versions v
     ORDER BY v.version_number DESC, v.id DESC'
);
$versions = $versionsStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];

$selectedVersionId = sorting_admin_query_id('version');
if ($selectedVersionId <= 0 && $versions !== []) {
    foreach ($versions as $candidate) {
        if ((string) $candidate['status'] === 'published') {
            $selectedVersionId = (int) $candidate['id'];
            break;
        }
    }

    if ($selectedVersionId <= 0) {
        $selectedVersionId = (int) $versions[0]['id'];
    }
}

$selectedVersion = sorting_admin_version($pdo, $selectedVersionId);
if ($selectedVersion === null) {
    $selectedVersionId = 0;
}

$housesStatement = $pdo->query(
    'SELECT id, name, display_name, display_color, is_active, sort_order
     FROM houses
     WHERE is_active = 1
     ORDER BY sort_order, COALESCE(NULLIF(display_name, \'\'), name), id'
);
$houses = $housesStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];

$questions = [];
$interludes = [];
$selectedQuestion = null;
$selectedQuestionHouseIds = [];
$selectedQuestionAnswers = [];
$selectedInterlude = null;

if ($selectedVersionId > 0) {
    $questionsStatement = $pdo->prepare(
        'SELECT sq.*,
                (SELECT COUNT(*) FROM sorting_answers sa WHERE sa.question_id = sq.id) AS answer_count
         FROM sorting_questions sq
         WHERE sq.ceremony_version_id = :version_id
         ORDER BY
            CASE sq.question_type WHEN \'primary\' THEN 0 ELSE 1 END,
            sq.sort_order,
            sq.id'
    );
    $questionsStatement->execute(['version_id' => $selectedVersionId]);
    $questions = $questionsStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $interludesStatement = $pdo->prepare(
        'SELECT * FROM sorting_interludes
         WHERE ceremony_version_id = :version_id
         ORDER BY after_primary_question_number, sort_order, id'
    );
    $interludesStatement->execute(['version_id' => $selectedVersionId]);
    $interludes = $interludesStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $selectedQuestionId = sorting_admin_query_id('question');
    if ($selectedQuestionId > 0) {
        $candidateQuestion = sorting_admin_question($pdo, $selectedQuestionId);
        if ($candidateQuestion !== null && (int) $candidateQuestion['ceremony_version_id'] === $selectedVersionId) {
            $selectedQuestion = $candidateQuestion;

            $houseMap = $pdo->prepare(
                'SELECT house_id FROM sorting_question_houses WHERE question_id = :question_id ORDER BY id'
            );
            $houseMap->execute(['question_id' => $selectedQuestionId]);
            $selectedQuestionHouseIds = array_map('intval', $houseMap->fetchAll(PDO::FETCH_COLUMN) ?: []);

            $answerStatement = $pdo->prepare(
                'SELECT sa.*, h.name AS house_name, h.display_name AS house_display_name, h.display_color AS house_display_color
                 FROM sorting_answers sa
                 INNER JOIN houses h ON h.id = sa.house_id
                 WHERE sa.question_id = :question_id
                 ORDER BY sa.sort_order, sa.id'
            );
            $answerStatement->execute(['question_id' => $selectedQuestionId]);
            $selectedQuestionAnswers = $answerStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }

    $selectedInterludeId = sorting_admin_query_id('interlude');
    if ($selectedInterludeId > 0) {
        $candidateInterlude = sorting_admin_interlude($pdo, $selectedInterludeId);
        if ($candidateInterlude !== null && (int) $candidateInterlude['ceremony_version_id'] === $selectedVersionId) {
            $selectedInterlude = $candidateInterlude;
        }
    }
}

$isSelectedVersionLocked = $selectedVersion !== null && sorting_admin_version_locked($selectedVersion);

$pageTitle = 'Sorting Ceremony Manager | Blackthorne Academy';
$pageDescription = 'Manage Blackthorne Academy Sorting Ceremony versions, questions, answers, Choosing rules, and magical interludes.';
$pageCanonical = url('admin/sorting-ceremony.php');
$robots = 'noindex, nofollow';

require INCLUDES_PATH . '/header.php';
?>


<main id="main-content" class="forum-admin-page sorting-admin-page">
    <section class="forum-admin-hero" aria-labelledby="sorting-admin-heading">
        <div class="section-inner">
            <p class="academy-overline">Academy Administration</p>
            <h1 id="sorting-admin-heading">Sorting Ceremony Manager</h1>
            <p>
                Manage ceremony versions, hidden House mappings, Choosing questions,
                answers, and atmospheric interludes without editing SQL manually.
            </p>

            <div class="forum-admin-edit-actions">
                <a href="<?= e(url('admin/houses.php')); ?>" class="button button-secondary">House Management</a>
                <a href="<?= e(url('staff-dashboard.php')); ?>" class="button button-secondary">Staff Dashboard</a>
                <a href="<?= e(DASHBOARD_URL); ?>" class="button button-secondary">Return to Dashboard</a>
            </div>
        </div>
    </section>

    <section class="forum-admin-content">
        <div class="section-inner">
            <?php if ($successMessage !== null): ?>
            <div class="form-message form-message-success" role="status"><?= e($successMessage); ?></div>
            <?php endif; ?>

            <?php if ($errors !== []): ?>
            <div class="form-message form-message-error" role="alert">
                <h2>Please correct the following:</h2>
                <ul>
                    <?php foreach ($errors as $error): ?>
                    <li><?= e($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <div class="sorting-version-grid">
                <section class="sorting-card" aria-labelledby="versions-heading">
                    <header class="forum-admin-titlebar">
                        <p class="forum-admin-step">Ceremony Library</p>
                        <h2 id="versions-heading">Ceremony Versions</h2>
                        <p>Only one version is published at a time. Publishing a draft automatically retires the old
                            live version.</p>
                    </header>
                    <div class="sorting-card-body sorting-version-list">
                        <?php if ($versions === []): ?>
                        <p class="forum-admin-empty">No ceremony versions exist yet.</p>
                        <?php else: ?>
                        <?php foreach ($versions as $version): ?>
                        <?php
                                $versionId = (int) $version['id'];
                                $status = (string) $version['status'];
                                $locked = (int) $version['attempt_count'] > 0;
                                ?>
                        <a class="sorting-version-item <?= $versionId === $selectedVersionId ? 'is-selected' : ''; ?>"
                            href="<?= e(url('admin/sorting-ceremony.php?version=' . $versionId)); ?>">
                            <div class="sorting-status-row">
                                <span
                                    class="sorting-status sorting-status-<?= e($status); ?>"><?= e(ucfirst($status)); ?></span>
                                <?php if ($locked): ?><span class="sorting-lock">Locked by student
                                    history</span><?php endif; ?>
                            </div>
                            <h3 class="sorting-item-title">Version <?= (int) $version['version_number']; ?> ·
                                <?= e((string) $version['name']); ?></h3>
                            <p class="sorting-item-meta">
                                <?= number_format((int) $version['primary_count']); ?> primary ·
                                <?= number_format((int) $version['choosing_count']); ?> Choosing ·
                                <?= number_format((int) $version['interlude_count']); ?> interludes ·
                                <?= number_format((int) $version['attempt_count']); ?> attempts
                            </p>
                        </a>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="sorting-card" aria-labelledby="create-version-heading">
                    <header class="forum-admin-titlebar">
                        <p class="forum-admin-step">New Draft</p>
                        <h2 id="create-version-heading">Create Ceremony Version</h2>
                        <p>Start a blank editable draft. You can also duplicate an existing version from its editor.</p>
                    </header>
                    <form method="post" action="<?= e(url('admin/sorting-ceremony.php')); ?>"
                        class="forum-admin-form sorting-card-body">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="form_action" value="create_version">

                        <div class="form-group">
                            <label for="new-version-name">Version Name</label>
                            <input id="new-version-name" class="form-control" type="text" name="name" maxlength="150"
                                required placeholder="2027 Sorting Ceremony">
                        </div>

                        <div class="form-group">
                            <label for="new-intro-title">Intro Title</label>
                            <input id="new-intro-title" class="form-control" type="text" name="intro_title"
                                maxlength="200" placeholder="The Hall Falls Silent">
                        </div>

                        <div class="form-group">
                            <label for="new-intro-text">Intro Text</label>
                            <textarea id="new-intro-text" class="form-control" name="intro_text" rows="4"></textarea>
                        </div>

                        <div class="form-group">
                            <label for="new-reveal-text">Reveal Lead Text</label>
                            <textarea id="new-reveal-text" class="form-control" name="reveal_lead_text"
                                rows="3"></textarea>
                        </div>

                        <button type="submit" class="button button-primary">Create Draft Version</button>
                    </form>
                </section>
            </div>

            <?php if ($selectedVersion !== null): ?>
            <section class="sorting-card" aria-labelledby="selected-version-heading">
                <header class="forum-admin-titlebar">
                    <p class="forum-admin-step">Selected Version</p>
                    <h2 id="selected-version-heading">
                        Version <?= (int) $selectedVersion['version_number']; ?> ·
                        <?= e((string) $selectedVersion['name']); ?>
                    </h2>
                    <div class="sorting-status-row">
                        <span class="sorting-status sorting-status-<?= e((string) $selectedVersion['status']); ?>">
                            <?= e(ucfirst((string) $selectedVersion['status'])); ?>
                        </span>
                        <?php if ($isSelectedVersionLocked): ?>
                        <span class="sorting-lock">Locked:
                            <?= number_format((int) $selectedVersion['attempt_count']); ?> student attempt(s)</span>
                        <?php else: ?>
                        <span class="sorting-count">Editable: no student attempts yet</span>
                        <?php endif; ?>
                    </div>
                </header>

                <div class="sorting-card-body">
                    <?php if ($isSelectedVersionLocked): ?>
                    <div class="form-message">
                        This ceremony version is historical and cannot be edited because a student attempt exists.
                        Duplicate it to create a new editable draft.
                    </div>
                    <?php endif; ?>

                    <form method="post"
                        action="<?= e(url('admin/sorting-ceremony.php?version=' . $selectedVersionId)); ?>"
                        class="forum-admin-form">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="form_action" value="update_version">
                        <input type="hidden" name="version_id" value="<?= $selectedVersionId; ?>">

                        <div class="sorting-field-grid">
                            <div class="form-group">
                                <label for="version-name">Version Name</label>
                                <input id="version-name" class="form-control" type="text" name="name" maxlength="150"
                                    required value="<?= e((string) $selectedVersion['name']); ?>"
                                    <?= $isSelectedVersionLocked ? 'disabled' : ''; ?>>
                            </div>
                            <div class="form-group">
                                <label for="version-intro-title">Intro Title</label>
                                <input id="version-intro-title" class="form-control" type="text" name="intro_title"
                                    maxlength="200" value="<?= e((string) ($selectedVersion['intro_title'] ?? '')); ?>"
                                    <?= $isSelectedVersionLocked ? 'disabled' : ''; ?>>
                            </div>
                            <div class="form-group is-wide">
                                <label for="version-intro-text">Intro Text</label>
                                <textarea id="version-intro-text" class="form-control" name="intro_text" rows="4"
                                    <?= $isSelectedVersionLocked ? 'disabled' : ''; ?>><?= e((string) ($selectedVersion['intro_text'] ?? '')); ?></textarea>
                            </div>
                            <div class="form-group is-wide">
                                <label for="version-reveal-text">Reveal Lead Text</label>
                                <textarea id="version-reveal-text" class="form-control" name="reveal_lead_text" rows="3"
                                    <?= $isSelectedVersionLocked ? 'disabled' : ''; ?>><?= e((string) ($selectedVersion['reveal_lead_text'] ?? '')); ?></textarea>
                            </div>
                        </div>

                        <?php if (!$isSelectedVersionLocked): ?>
                        <button type="submit" class="button button-primary">Save Version Details</button>
                        <?php endif; ?>
                    </form>

                    <div class="sorting-actions" style="margin-top:1rem;">
                        <form method="post"
                            action="<?= e(url('admin/sorting-ceremony.php?version=' . $selectedVersionId)); ?>">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="form_action" value="duplicate_version">
                            <input type="hidden" name="version_id" value="<?= $selectedVersionId; ?>">
                            <button type="submit" class="button button-secondary">Duplicate to New Draft</button>
                        </form>

                        <?php if ((string) $selectedVersion['status'] === 'draft'): ?>
                        <form method="post"
                            action="<?= e(url('admin/sorting-ceremony.php?version=' . $selectedVersionId)); ?>"
                            onsubmit="return confirm('Publish this ceremony version? The currently published version will be retired.');">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="form_action" value="publish_version">
                            <input type="hidden" name="version_id" value="<?= $selectedVersionId; ?>">
                            <button type="submit" class="button button-primary">Publish This Version</button>
                        </form>
                        <?php endif; ?>

                        <?php if ((string) $selectedVersion['status'] === 'draft' && !$isSelectedVersionLocked): ?>
                        <form method="post" action="<?= e(url('admin/sorting-ceremony.php')); ?>"
                            onsubmit="return confirm('Permanently delete this unused draft and all of its questions, answers, and interludes?');">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="form_action" value="delete_version">
                            <input type="hidden" name="version_id" value="<?= $selectedVersionId; ?>">
                            <button type="submit" class="button sorting-danger-button">Delete Unused Draft</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <div class="sorting-content-grid">
                <div>
                    <section class="sorting-card" aria-labelledby="questions-heading">
                        <header class="forum-admin-titlebar">
                            <p class="forum-admin-step">Primary + The Choosing</p>
                            <h2 id="questions-heading">Questions</h2>
                            <p>Primary questions build hidden House scores. Choosing questions resolve ties without
                                exposing scores to students.</p>
                        </header>
                        <div class="sorting-card-body sorting-question-list">
                            <?php if ($questions === []): ?>
                            <p class="forum-admin-empty">No questions exist in this version yet.</p>
                            <?php else: ?>
                            <?php foreach ($questions as $question): ?>
                            <?php $questionId = (int) $question['id']; ?>
                            <a class="sorting-question-item <?= $selectedQuestion !== null && (int) $selectedQuestion['id'] === $questionId ? 'is-selected' : ''; ?>"
                                href="<?= e(url('admin/sorting-ceremony.php?version=' . $selectedVersionId . '&question=' . $questionId)); ?>">
                                <div class="sorting-status-row">
                                    <span
                                        class="sorting-count"><?= e(ucfirst((string) $question['question_type'])); ?></span>
                                    <?php if ((string) $question['question_type'] === 'choosing'): ?>
                                    <span
                                        class="sorting-count"><?= e(str_replace('_', ' ', (string) $question['choosing_strategy'])); ?></span>
                                    <?php endif; ?>
                                    <?php if ((int) $question['is_active'] !== 1): ?><span
                                        class="sorting-lock">Inactive</span><?php endif; ?>
                                </div>
                                <h3 class="sorting-item-title"><?= e((string) $question['question_key']); ?></h3>
                                <p class="sorting-item-meta">Order <?= (int) $question['sort_order']; ?> ·
                                    <?= number_format((int) $question['answer_count']); ?> answers</p>
                            </a>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="sorting-card" aria-labelledby="interludes-heading">
                        <header class="forum-admin-titlebar">
                            <p class="forum-admin-step">Atmosphere</p>
                            <h2 id="interludes-heading">Magical Interludes</h2>
                            <p>Interludes appear after configured primary questions and can use fade, reveal, glow, or
                                whisper animation.</p>
                        </header>
                        <div class="sorting-card-body sorting-interlude-list">
                            <?php if ($interludes === []): ?>
                            <p class="forum-admin-empty">No interludes exist in this version.</p>
                            <?php else: ?>
                            <?php foreach ($interludes as $interlude): ?>
                            <?php $interludeId = (int) $interlude['id']; ?>
                            <a class="sorting-interlude-item <?= $selectedInterlude !== null && (int) $selectedInterlude['id'] === $interludeId ? 'is-selected' : ''; ?>"
                                href="<?= e(url('admin/sorting-ceremony.php?version=' . $selectedVersionId . '&interlude=' . $interludeId)); ?>">
                                <h3 class="sorting-item-title">
                                    <?= e((string) ($interlude['title'] ?: 'Untitled Interlude')); ?></h3>
                                <p class="sorting-item-meta">After primary question
                                    <?= (int) $interlude['after_primary_question_number']; ?> ·
                                    <?= e(ucfirst((string) $interlude['animation_style'])); ?><?= (int) $interlude['is_active'] === 1 ? '' : ' · Inactive'; ?>
                                </p>
                            </a>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>

                <div>
                    <?php
                        $questionForm = $selectedQuestion ?? [
                            'id' => 0,
                            'question_key' => '',
                            'question_type' => 'primary',
                            'choosing_strategy' => 'none',
                            'prompt' => '',
                            'prelude_text' => '',
                            'min_tied_houses' => null,
                            'max_tied_houses' => null,
                            'sort_order' => count($questions) * 10 + 10,
                            'is_active' => 1,
                        ];
                        ?>
                    <section class="sorting-card" aria-labelledby="question-editor-heading">
                        <header class="forum-admin-titlebar">
                            <p class="forum-admin-step">
                                <?= $selectedQuestion !== null ? 'Edit Question' : 'New Question'; ?></p>
                            <h2 id="question-editor-heading">
                                <?= $selectedQuestion !== null ? e((string) $selectedQuestion['question_key']) : 'Add Question'; ?>
                            </h2>
                        </header>
                        <form method="post"
                            action="<?= e(url('admin/sorting-ceremony.php?version=' . $selectedVersionId)); ?>"
                            class="forum-admin-form sorting-card-body">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="form_action" value="save_question">
                            <input type="hidden" name="version_id" value="<?= $selectedVersionId; ?>">
                            <input type="hidden" name="question_id" value="<?= (int) $questionForm['id']; ?>">

                            <div class="form-group">
                                <label for="question-key">Question Key</label>
                                <input id="question-key" class="form-control" type="text" name="question_key"
                                    maxlength="100" required value="<?= e((string) $questionForm['question_key']); ?>"
                                    <?= $isSelectedVersionLocked ? 'disabled' : ''; ?>>
                                <p class="form-help">Internal unique key for this version, such as
                                    <code>primary_01</code> or <code>choose_nightbriar_grimwood</code>.
                                </p>
                            </div>

                            <div class="sorting-field-grid">
                                <div class="form-group">
                                    <label for="question-type">Question Type</label>
                                    <select id="question-type" class="form-control" name="question_type"
                                        <?= $isSelectedVersionLocked ? 'disabled' : ''; ?>>
                                        <option value="primary"
                                            <?= (string) $questionForm['question_type'] === 'primary' ? 'selected' : ''; ?>>
                                            Primary</option>
                                        <option value="choosing"
                                            <?= (string) $questionForm['question_type'] === 'choosing' ? 'selected' : ''; ?>>
                                            Choosing</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="choosing-strategy">Choosing Strategy</label>
                                    <select id="choosing-strategy" class="form-control" name="choosing_strategy"
                                        <?= $isSelectedVersionLocked ? 'disabled' : ''; ?>>
                                        <option value="none"
                                            <?= (string) $questionForm['choosing_strategy'] === 'none' ? 'selected' : ''; ?>>
                                            None / Primary</option>
                                        <option value="exact_set"
                                            <?= (string) $questionForm['choosing_strategy'] === 'exact_set' ? 'selected' : ''; ?>>
                                            Exact House Set</option>
                                        <option value="tied_set"
                                            <?= (string) $questionForm['choosing_strategy'] === 'tied_set' ? 'selected' : ''; ?>>
                                            Tied House Count</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="question-prompt">Prompt</label>
                                <textarea id="question-prompt" class="form-control" name="prompt" rows="5" required
                                    <?= $isSelectedVersionLocked ? 'disabled' : ''; ?>><?= e((string) $questionForm['prompt']); ?></textarea>
                            </div>

                            <div class="form-group">
                                <label for="question-prelude">Prelude Text</label>
                                <textarea id="question-prelude" class="form-control" name="prelude_text" rows="3"
                                    <?= $isSelectedVersionLocked ? 'disabled' : ''; ?>><?= e((string) ($questionForm['prelude_text'] ?? '')); ?></textarea>
                            </div>

                            <div class="sorting-field-grid">
                                <div class="form-group">
                                    <label for="min-tied">Minimum Tied Houses</label>
                                    <input id="min-tied" class="form-control" type="number" min="2" max="4"
                                        name="min_tied_houses"
                                        value="<?= e((string) ($questionForm['min_tied_houses'] ?? '')); ?>"
                                        <?= $isSelectedVersionLocked ? 'disabled' : ''; ?>>
                                </div>
                                <div class="form-group">
                                    <label for="max-tied">Maximum Tied Houses</label>
                                    <input id="max-tied" class="form-control" type="number" min="2" max="4"
                                        name="max_tied_houses"
                                        value="<?= e((string) ($questionForm['max_tied_houses'] ?? '')); ?>"
                                        <?= $isSelectedVersionLocked ? 'disabled' : ''; ?>>
                                </div>
                            </div>

                            <fieldset class="forum-admin-fieldset">
                                <legend>Exact House Set</legend>
                                <p class="form-help">Used only for pair-specific or other exact-set Choosing questions.
                                </p>
                                <div class="sorting-house-options">
                                    <?php foreach ($houses as $house): ?>
                                    <?php $houseId = (int) $house['id']; ?>
                                    <label class="sorting-house-choice">
                                        <input type="checkbox" name="house_ids[]" value="<?= $houseId; ?>"
                                            <?= in_array($houseId, $selectedQuestionHouseIds, true) ? 'checked' : ''; ?>
                                            <?= $isSelectedVersionLocked ? 'disabled' : ''; ?>>
                                        <span class="sorting-house-dot"
                                            style="background:<?= e((string) ($house['display_color'] ?: '#c9ab68')); ?>;"></span>
                                        <span><?= e((string) ($house['display_name'] ?: $house['name'])); ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </fieldset>

                            <div class="sorting-field-grid">
                                <div class="form-group">
                                    <label for="question-sort-order">Sort Order</label>
                                    <input id="question-sort-order" class="form-control" type="number" min="0"
                                        name="sort_order" value="<?= (int) $questionForm['sort_order']; ?>"
                                        <?= $isSelectedVersionLocked ? 'disabled' : ''; ?>>
                                </div>
                                <div class="form-group">
                                    <label for="question-active">Status</label>
                                    <select id="question-active" class="form-control" name="is_active"
                                        <?= $isSelectedVersionLocked ? 'disabled' : ''; ?>>
                                        <option value="1"
                                            <?= (int) $questionForm['is_active'] === 1 ? 'selected' : ''; ?>>Active
                                        </option>
                                        <option value="0"
                                            <?= (int) $questionForm['is_active'] !== 1 ? 'selected' : ''; ?>>Inactive
                                        </option>
                                    </select>
                                </div>
                            </div>

                            <?php if (!$isSelectedVersionLocked): ?>
                            <div class="sorting-actions">
                                <button type="submit"
                                    class="button button-primary"><?= $selectedQuestion !== null ? 'Save Question' : 'Create Question'; ?></button>
                                <?php if ($selectedQuestion !== null): ?>
                                <a href="<?= e(url('admin/sorting-ceremony.php?version=' . $selectedVersionId)); ?>"
                                    class="button button-secondary">Add Another</a>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </form>

                        <?php if ($selectedQuestion !== null && !$isSelectedVersionLocked): ?>
                        <div class="sorting-card-body" style="padding-top:0;">
                            <form method="post"
                                action="<?= e(url('admin/sorting-ceremony.php?version=' . $selectedVersionId)); ?>"
                                onsubmit="return confirm('Delete this question and all of its answers?');">
                                <?= csrf_field(); ?>
                                <input type="hidden" name="form_action" value="delete_question">
                                <input type="hidden" name="version_id" value="<?= $selectedVersionId; ?>">
                                <input type="hidden" name="question_id" value="<?= (int) $selectedQuestion['id']; ?>">
                                <button type="submit" class="button sorting-danger-button">Delete Question</button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </section>

                    <?php if ($selectedQuestion !== null): ?>
                    <section class="sorting-card" aria-labelledby="answers-heading">
                        <header class="forum-admin-titlebar">
                            <p class="forum-admin-step">Hidden Mapping</p>
                            <h2 id="answers-heading">Answers</h2>
                            <p>Students see only answer text. The House assignment remains hidden and powers scoring or
                                Choosing.</p>
                        </header>
                        <div class="sorting-card-body sorting-answer-list">
                            <?php foreach ($selectedQuestionAnswers as $answer): ?>
                            <div class="sorting-answer-item">
                                <div class="sorting-status-row">
                                    <span class="sorting-count">
                                        <span class="sorting-house-dot"
                                            style="background:<?= e((string) ($answer['house_display_color'] ?: '#c9ab68')); ?>;"></span>
                                        <?= e((string) ($answer['house_display_name'] ?: $answer['house_name'])); ?>
                                    </span>
                                    <span class="sorting-count">Order <?= (int) $answer['sort_order']; ?></span>
                                    <?php if ((int) $answer['is_active'] !== 1): ?><span
                                        class="sorting-lock">Inactive</span><?php endif; ?>
                                </div>
                                <p><?= e((string) $answer['answer_text']); ?></p>

                                <?php if (!$isSelectedVersionLocked): ?>
                                <details class="sorting-answer-edit">
                                    <summary>Edit answer</summary>
                                    <form method="post"
                                        action="<?= e(url('admin/sorting-ceremony.php?version=' . $selectedVersionId . '&question=' . (int) $selectedQuestion['id'])); ?>"
                                        class="forum-admin-form" style="margin-top:.8rem;">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="form_action" value="save_answer">
                                        <input type="hidden" name="version_id" value="<?= $selectedVersionId; ?>">
                                        <input type="hidden" name="question_id"
                                            value="<?= (int) $selectedQuestion['id']; ?>">
                                        <input type="hidden" name="answer_id" value="<?= (int) $answer['id']; ?>">
                                        <div class="form-group">
                                            <label>Answer Text</label>
                                            <textarea class="form-control" name="answer_text" rows="3"
                                                required><?= e((string) $answer['answer_text']); ?></textarea>
                                        </div>
                                        <div class="sorting-field-grid">
                                            <div class="form-group">
                                                <label>House</label>
                                                <select class="form-control" name="house_id" required>
                                                    <?php foreach ($houses as $house): ?>
                                                    <option value="<?= (int) $house['id']; ?>"
                                                        <?= (int) $house['id'] === (int) $answer['house_id'] ? 'selected' : ''; ?>>
                                                        <?= e((string) ($house['display_name'] ?: $house['name'])); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label>Sort Order</label>
                                                <input class="form-control" type="number" min="0"
                                                    name="answer_sort_order"
                                                    value="<?= (int) $answer['sort_order']; ?>">
                                            </div>
                                            <div class="form-group">
                                                <label>Status</label>
                                                <select class="form-control" name="answer_is_active">
                                                    <option value="1"
                                                        <?= (int) $answer['is_active'] === 1 ? 'selected' : ''; ?>>
                                                        Active</option>
                                                    <option value="0"
                                                        <?= (int) $answer['is_active'] !== 1 ? 'selected' : ''; ?>>
                                                        Inactive</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="sorting-actions">
                                            <button type="submit" class="button button-primary">Save Answer</button>
                                        </div>
                                    </form>
                                    <form method="post"
                                        action="<?= e(url('admin/sorting-ceremony.php?version=' . $selectedVersionId . '&question=' . (int) $selectedQuestion['id'])); ?>"
                                        onsubmit="return confirm('Delete this answer?');" style="margin-top:.6rem;">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="form_action" value="delete_answer">
                                        <input type="hidden" name="version_id" value="<?= $selectedVersionId; ?>">
                                        <input type="hidden" name="question_id"
                                            value="<?= (int) $selectedQuestion['id']; ?>">
                                        <input type="hidden" name="answer_id" value="<?= (int) $answer['id']; ?>">
                                        <button type="submit" class="button sorting-danger-button">Delete
                                            Answer</button>
                                    </form>
                                </details>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>

                            <?php if (!$isSelectedVersionLocked): ?>
                            <form method="post"
                                action="<?= e(url('admin/sorting-ceremony.php?version=' . $selectedVersionId . '&question=' . (int) $selectedQuestion['id'])); ?>"
                                class="forum-admin-form sorting-answer-item">
                                <?= csrf_field(); ?>
                                <input type="hidden" name="form_action" value="save_answer">
                                <input type="hidden" name="version_id" value="<?= $selectedVersionId; ?>">
                                <input type="hidden" name="question_id" value="<?= (int) $selectedQuestion['id']; ?>">
                                <input type="hidden" name="answer_id" value="0">
                                <div class="form-group">
                                    <label for="new-answer-text">Add Answer</label>
                                    <textarea id="new-answer-text" class="form-control" name="answer_text" rows="3"
                                        required></textarea>
                                </div>
                                <div class="sorting-field-grid">
                                    <div class="form-group">
                                        <label for="new-answer-house">Hidden House</label>
                                        <select id="new-answer-house" class="form-control" name="house_id" required>
                                            <option value="">Choose House</option>
                                            <?php foreach ($houses as $house): ?>
                                            <option value="<?= (int) $house['id']; ?>">
                                                <?= e((string) ($house['display_name'] ?: $house['name'])); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="new-answer-order">Sort Order</label>
                                        <input id="new-answer-order" class="form-control" type="number" min="0"
                                            name="answer_sort_order"
                                            value="<?= count($selectedQuestionAnswers) * 10 + 10; ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="new-answer-active">Status</label>
                                        <select id="new-answer-active" class="form-control" name="answer_is_active">
                                            <option value="1">Active</option>
                                            <option value="0">Inactive</option>
                                        </select>
                                    </div>
                                </div>
                                <button type="submit" class="button button-primary">Add Answer</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </section>
                    <?php endif; ?>

                    <?php
                        $interludeForm = $selectedInterlude ?? [
                            'id' => 0,
                            'after_primary_question_number' => 4,
                            'title' => '',
                            'body_text' => '',
                            'animation_style' => 'reveal',
                            'sort_order' => count($interludes) * 10 + 10,
                            'is_active' => 1,
                        ];
                        ?>
                    <section class="sorting-card" aria-labelledby="interlude-editor-heading">
                        <header class="forum-admin-titlebar">
                            <p class="forum-admin-step">
                                <?= $selectedInterlude !== null ? 'Edit Interlude' : 'New Interlude'; ?></p>
                            <h2 id="interlude-editor-heading">
                                <?= $selectedInterlude !== null ? e((string) ($selectedInterlude['title'] ?: 'Untitled Interlude')) : 'Add Magical Interlude'; ?>
                            </h2>
                        </header>
                        <form method="post"
                            action="<?= e(url('admin/sorting-ceremony.php?version=' . $selectedVersionId)); ?>"
                            class="forum-admin-form sorting-card-body">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="form_action" value="save_interlude">
                            <input type="hidden" name="version_id" value="<?= $selectedVersionId; ?>">
                            <input type="hidden" name="interlude_id" value="<?= (int) $interludeForm['id']; ?>">

                            <div class="sorting-field-grid">
                                <div class="form-group">
                                    <label for="interlude-after">After Primary Question #</label>
                                    <input id="interlude-after" class="form-control" type="number" min="1"
                                        name="after_primary_question_number"
                                        value="<?= (int) $interludeForm['after_primary_question_number']; ?>" required
                                        <?= $isSelectedVersionLocked ? 'disabled' : ''; ?>>
                                </div>
                                <div class="form-group">
                                    <label for="interlude-animation">Animation</label>
                                    <select id="interlude-animation" class="form-control" name="animation_style"
                                        <?= $isSelectedVersionLocked ? 'disabled' : ''; ?>>
                                        <?php foreach (['fade', 'reveal', 'glow', 'whisper'] as $animation): ?>
                                        <option value="<?= e($animation); ?>"
                                            <?= (string) $interludeForm['animation_style'] === $animation ? 'selected' : ''; ?>>
                                            <?= e(ucfirst($animation)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="interlude-title">Title</label>
                                <input id="interlude-title" class="form-control" type="text" name="interlude_title"
                                    maxlength="200" value="<?= e((string) ($interludeForm['title'] ?? '')); ?>"
                                    <?= $isSelectedVersionLocked ? 'disabled' : ''; ?>>
                            </div>

                            <div class="form-group">
                                <label for="interlude-body">Interlude Text</label>
                                <textarea id="interlude-body" class="form-control" name="body_text" rows="5" required
                                    <?= $isSelectedVersionLocked ? 'disabled' : ''; ?>><?= e((string) $interludeForm['body_text']); ?></textarea>
                            </div>

                            <div class="sorting-field-grid">
                                <div class="form-group">
                                    <label for="interlude-order">Sort Order</label>
                                    <input id="interlude-order" class="form-control" type="number" min="0"
                                        name="interlude_sort_order" value="<?= (int) $interludeForm['sort_order']; ?>"
                                        <?= $isSelectedVersionLocked ? 'disabled' : ''; ?>>
                                </div>
                                <div class="form-group">
                                    <label for="interlude-active">Status</label>
                                    <select id="interlude-active" class="form-control" name="interlude_is_active"
                                        <?= $isSelectedVersionLocked ? 'disabled' : ''; ?>>
                                        <option value="1"
                                            <?= (int) $interludeForm['is_active'] === 1 ? 'selected' : ''; ?>>Active
                                        </option>
                                        <option value="0"
                                            <?= (int) $interludeForm['is_active'] !== 1 ? 'selected' : ''; ?>>Inactive
                                        </option>
                                    </select>
                                </div>
                            </div>

                            <?php if (!$isSelectedVersionLocked): ?>
                            <div class="sorting-actions">
                                <button type="submit"
                                    class="button button-primary"><?= $selectedInterlude !== null ? 'Save Interlude' : 'Create Interlude'; ?></button>
                                <?php if ($selectedInterlude !== null): ?>
                                <a href="<?= e(url('admin/sorting-ceremony.php?version=' . $selectedVersionId)); ?>"
                                    class="button button-secondary">Add Another</a>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </form>

                        <?php if ($selectedInterlude !== null && !$isSelectedVersionLocked): ?>
                        <div class="sorting-card-body" style="padding-top:0;">
                            <form method="post"
                                action="<?= e(url('admin/sorting-ceremony.php?version=' . $selectedVersionId)); ?>"
                                onsubmit="return confirm('Delete this interlude?');">
                                <?= csrf_field(); ?>
                                <input type="hidden" name="form_action" value="delete_interlude">
                                <input type="hidden" name="version_id" value="<?= $selectedVersionId; ?>">
                                <input type="hidden" name="interlude_id" value="<?= (int) $selectedInterlude['id']; ?>">
                                <button type="submit" class="button sorting-danger-button">Delete Interlude</button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </section>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </section>
</main>

<script>
    (() => {
        const storageKey = 'blackthorne:sorting-admin-scroll';
        const currentPath = window.location.pathname;

        function rememberScrollPosition() {
            try {
                sessionStorage.setItem(storageKey, JSON.stringify({
                    path: currentPath,
                    y: window.scrollY,
                    savedAt: Date.now()
                }));
            } catch (error) {
                // Scroll preservation is a convenience only; never block an admin action.
            }
        }

        document.addEventListener('click', (event) => {
            const link = event.target.closest('a[href]');

            if (!link) {
                return;
            }

            try {
                const destination = new URL(link.href, window.location.href);

                if (destination.origin === window.location.origin && destination.pathname === currentPath) {
                    rememberScrollPosition();
                }
            } catch (error) {
                // Ignore malformed/non-standard links.
            }
        });

        document.addEventListener('submit', (event) => {
            const form = event.target;

            if (!(form instanceof HTMLFormElement)) {
                return;
            }

            try {
                const destination = new URL(form.action || window.location.href, window.location.href);

                if (destination.origin === window.location.origin && destination.pathname === currentPath) {
                    rememberScrollPosition();
                }
            } catch (error) {
                rememberScrollPosition();
            }
        });

        window.addEventListener('pageshow', () => {
            let saved = null;

            try {
                saved = JSON.parse(sessionStorage.getItem(storageKey) || 'null');
                sessionStorage.removeItem(storageKey);
            } catch (error) {
                saved = null;
            }

            if (!saved || saved.path !== currentPath || typeof saved.y !== 'number') {
                return;
            }

            // Only restore a position from a recent interaction on this manager page.
            if (typeof saved.savedAt === 'number' && Date.now() - saved.savedAt > 15000) {
                return;
            }

            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    window.scrollTo({
                        top: Math.max(0, saved.y),
                        left: 0,
                        behavior: 'instant'
                    });
                });
            });
        });
    })();

</script>

<?php require INCLUDES_PATH . '/footer.php'; ?>
