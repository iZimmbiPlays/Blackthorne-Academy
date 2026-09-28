<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/sorting-ceremony.php';

require_login();
require_active_account();

$userId = (int) (current_user_id() ?? 0);
$user = current_user();

if ($userId <= 0 || $user === null) {
    redirect(LOGIN_URL);
}

$errors = [];

/**
 * Keep all state-changing ceremony actions behind POST + CSRF, then redirect
 * back to this page. This prevents refresh from resubmitting an answer.
 */
if (is_post()) {
    require_valid_csrf();

    $action = post_value('ceremony_action');

    try {
        if ($action === 'begin') {
            sorting_ceremony_start_or_resume_attempt($userId);
            redirect(url('sorting-ceremony.php'));
        }

        if ($action === 'answer_primary') {
            $attemptId = (int) post_value('attempt_id');
            $questionId = (int) post_value('question_id');
            $answerId = (int) post_value('answer_id');

            if ($answerId <= 0) {
                throw new SortingCeremonyStateException('Choose an answer before continuing.');
            }

            sorting_ceremony_submit_primary_answer(
                $attemptId,
                $userId,
                $questionId,
                $answerId
            );

            redirect(url('sorting-ceremony.php'));
        }

        if ($action === 'continue_interlude') {
            $attemptId = (int) post_value('attempt_id');
            sorting_ceremony_acknowledge_interlude($attemptId, $userId);
            redirect(url('sorting-ceremony.php'));
        }

        if ($action === 'answer_choosing') {
            $attemptId = (int) post_value('attempt_id');
            $questionId = (int) post_value('question_id');
            $answerId = (int) post_value('answer_id');

            if ($answerId <= 0) {
                throw new SortingCeremonyStateException('Choose an answer before continuing.');
            }

            sorting_ceremony_submit_choosing_answer(
                $attemptId,
                $userId,
                $questionId,
                $answerId
            );

            redirect(url('sorting-ceremony.php'));
        }
    } catch (SortingCeremonyException | InvalidArgumentException $exception) {
        $errors[] = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Blackthorne Sorting Ceremony page error: ' . $exception->getMessage());
        $errors[] = 'The Sorting Ceremony could not continue. Please try again.';
    }
}

$entryState = sorting_ceremony_entry_state($userId);
$state = (string) ($entryState['state'] ?? 'unavailable');
$attemptState = null;

if ($state === 'resume') {
    $attempt = $entryState['attempt'] ?? null;

    if (is_array($attempt) && (int) ($attempt['id'] ?? 0) > 0) {
        try {
            $attemptState = sorting_ceremony_attempt_screen((int) $attempt['id'], $userId);
            $state = (string) ($attemptState['state'] ?? 'resume');
        } catch (SortingCeremonyException $exception) {
            $errors[] = $exception->getMessage();
        }
    }
}

if ($state === 'sorted') {
    $reveal = sorting_ceremony_reveal_data($userId);

    if ($reveal !== null && is_array($reveal['attempt'] ?? null)) {
        $state = 'reveal';
        $attemptState = [
            'state' => 'reveal',
            'attempt' => $reveal['attempt'],
            'reveal' => $reveal,
        ];
    }
}

if ($state === 'completed_history') {
    $reveal = sorting_ceremony_reveal_data($userId);
    if ($reveal !== null) {
        $state = 'reveal';
        $attemptState = [
            'state' => 'reveal',
            'attempt' => $reveal['attempt'] ?? null,
            'reveal' => $reveal,
        ];
    }
}

$displayName = trim((string) ($user['display_name'] ?? $user['username'] ?? 'Scholar'));
if ($displayName === '') {
    $displayName = 'Scholar';
}

$pageTitle = 'Sorting Ceremony | Blackthorne Academy';
$pageDescription = 'Discover your House in the Blackthorne Academy Sorting Ceremony.';
$pageCanonical = url('sorting-ceremony.php');
$robots = 'noindex, nofollow';

require INCLUDES_PATH . '/header.php';

$version = is_array($entryState['version'] ?? null) ? $entryState['version'] : null;

$revealData = is_array($attemptState['reveal'] ?? null) ? $attemptState['reveal'] : null;
$revealHouse = is_array($revealData['house'] ?? null) ? $revealData['house'] : null;

$housePrimary = '#5B355F';
$houseSecondary = '#2B182F';
$houseAccent = '#C7A55B';
$houseHighlight = '#E3D2AE';
$houseDark = '#100B13';

if ($revealHouse !== null) {
    $housePrimary = safe_css_color((string) ($revealHouse['primary_color'] ?? '')) ?? $housePrimary;
    $houseSecondary = safe_css_color((string) ($revealHouse['secondary_color'] ?? '')) ?? $houseSecondary;
    $houseAccent = safe_css_color((string) ($revealHouse['accent_color'] ?? '')) ?? $houseAccent;
    $houseHighlight = safe_css_color((string) ($revealHouse['highlight_color'] ?? '')) ?? $houseHighlight;
    $houseDark = safe_css_color((string) ($revealHouse['dark_neutral_color'] ?? '')) ?? $houseDark;
}
?>


<?php if ($errors !== []): ?>
    <div class="sorting-error" role="alert">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error); ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($state === 'intro' && $version !== null): ?>
    <main id="main-content" class="sorting-page">
        <div class="sorting-stage">
            <section class="sorting-panel sorting-center" aria-labelledby="sorting-intro-heading">
                <p class="sorting-overline">Blackthorne Academy</p>
                <h1 id="sorting-intro-heading"><?= e((string) ($version['intro_title'] ?: 'The Sorting Ceremony')); ?></h1>

                <div class="sorting-intro-copy">
                    <?= nl2br(e((string) ($version['intro_text'] ?? 'The Academy is ready to discover where you belong.'))); ?>
                </div>

                <form method="post" action="<?= e(url('sorting-ceremony.php')); ?>" class="sorting-actions">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="ceremony_action" value="begin">
                    <button type="submit" class="button button-primary">Begin the Sorting Ceremony</button>
                    <a href="<?= e(DASHBOARD_URL); ?>" class="button button-secondary">Return to Dashboard</a>
                </form>
            </section>
        </div>
    </main>

<?php elseif ($state === 'primary' && is_array($attemptState['screen'] ?? null)): ?>
    <?php
    $attempt = $attemptState['attempt'];
    $screen = $attemptState['screen'];
    $question = $screen['question'];
    $answers = $screen['answers'];
    $questionNumber = max(1, (int) ($question['question_number'] ?? 1));
    $totalQuestions = max(1, (int) ($question['total_primary_questions'] ?? 1));
    $progress = min(100, max(0, (($questionNumber - 1) / $totalQuestions) * 100));
    ?>
    <main id="main-content" class="sorting-page">
        <div class="sorting-stage">
            <section class="sorting-panel" aria-labelledby="sorting-question-heading">
                <div class="sorting-progress-wrap" aria-label="Sorting Ceremony progress">
                    <div class="sorting-progress-meta">
                        <span>Question <?= $questionNumber; ?> of <?= $totalQuestions; ?></span>
                        <span>The ceremony is listening.</span>
                    </div>
                    <div class="sorting-progress-track" aria-hidden="true">
                        <div class="sorting-progress-fill" style="width:<?= e(number_format($progress, 2, '.', '')); ?>%;"></div>
                    </div>
                </div>

                <?php if (trim((string) ($question['prelude_text'] ?? '')) !== ''): ?>
                    <p class="sorting-question-prelude"><?= e((string) $question['prelude_text']); ?></p>
                <?php endif; ?>

                <div class="sorting-question-prompt">
                    <p class="sorting-overline">Choose instinctively</p>
                    <h1 id="sorting-question-heading"><?= e((string) $question['prompt']); ?></h1>
                </div>

                <form method="post" action="<?= e(url('sorting-ceremony.php')); ?>">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="ceremony_action" value="answer_primary">
                    <input type="hidden" name="attempt_id" value="<?= (int) $attempt['id']; ?>">
                    <input type="hidden" name="question_id" value="<?= (int) $question['id']; ?>">

                    <div class="sorting-answer-list">
                        <?php foreach ($answers as $index => $answer): ?>
                            <label class="sorting-answer">
                                <input
                                    type="radio"
                                    name="answer_id"
                                    value="<?= (int) $answer['id']; ?>"
                                    <?= $index === 0 ? 'required' : ''; ?>
                                >
                                <span class="sorting-answer-box"><?= e((string) $answer['answer_text']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="sorting-submit sorting-center">
                        <button type="submit" class="button button-primary">Lock In Answer</button>
                    </div>
                </form>
            </section>
        </div>
    </main>

<?php elseif ($state === 'interlude' && is_array($attemptState['interlude'] ?? null)): ?>
    <?php
    $attempt = $attemptState['attempt'];
    $interlude = $attemptState['interlude'];
    $animation = in_array((string) ($interlude['animation_style'] ?? ''), ['fade', 'reveal', 'glow', 'whisper'], true)
        ? (string) $interlude['animation_style']
        : 'reveal';
    ?>
    <main id="main-content" class="sorting-page sorting-interlude sorting-animation-<?= e($animation); ?>">
        <div class="sorting-stage">
            <section class="sorting-panel" aria-labelledby="sorting-interlude-heading">
                <div class="sorting-interlude-inner">
                    <div class="sorting-interlude-symbol" aria-hidden="true"></div>
                    <p class="sorting-overline">The magic is stirring</p>
                    <h1 id="sorting-interlude-heading"><?= e((string) ($interlude['title'] ?: 'The Ceremony Listens')); ?></h1>
                    <div class="sorting-interlude-body"><?= nl2br(e((string) $interlude['body_text'])); ?></div>

                    <form method="post" action="<?= e(url('sorting-ceremony.php')); ?>" class="sorting-actions">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="ceremony_action" value="continue_interlude">
                        <input type="hidden" name="attempt_id" value="<?= (int) $attempt['id']; ?>">
                        <button type="submit" class="button button-primary">Continue</button>
                    </form>
                </div>
            </section>
        </div>
    </main>

<?php elseif ($state === 'choosing' && is_array($attemptState['screen'] ?? null)): ?>
    <?php
    $attempt = $attemptState['attempt'];
    $screen = $attemptState['screen'];
    $question = $screen['question'];
    $answers = $screen['answers'];
    ?>
    <main id="main-content" class="sorting-page sorting-choosing">
        <div class="sorting-stage">
            <section class="sorting-panel" aria-labelledby="sorting-choosing-heading">
                <div class="sorting-choosing-mark">The Choosing</div>

                <?php if (trim((string) ($question['prelude_text'] ?? '')) !== ''): ?>
                    <p class="sorting-question-prelude"><?= e((string) $question['prelude_text']); ?></p>
                <?php else: ?>
                    <p class="sorting-question-prelude">For the first time, the magic does not move on. One final distinction remains.</p>
                <?php endif; ?>

                <div class="sorting-question-prompt">
                    <h1 id="sorting-choosing-heading"><?= e((string) $question['prompt']); ?></h1>
                </div>

                <form method="post" action="<?= e(url('sorting-ceremony.php')); ?>">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="ceremony_action" value="answer_choosing">
                    <input type="hidden" name="attempt_id" value="<?= (int) $attempt['id']; ?>">
                    <input type="hidden" name="question_id" value="<?= (int) $question['id']; ?>">

                    <div class="sorting-answer-list">
                        <?php foreach ($answers as $index => $answer): ?>
                            <label class="sorting-answer">
                                <input
                                    type="radio"
                                    name="answer_id"
                                    value="<?= (int) $answer['id']; ?>"
                                    <?= $index === 0 ? 'required' : ''; ?>
                                >
                                <span class="sorting-answer-box"><?= e((string) $answer['answer_text']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="sorting-submit sorting-center">
                        <button type="submit" class="button button-primary">Make the Final Choice</button>
                    </div>
                </form>
            </section>
        </div>
    </main>

<?php elseif ($state === 'reveal' && $revealData !== null && $revealHouse !== null): ?>
    <?php
    $houseName = trim((string) ($revealHouse['display_name'] ?? $revealHouse['name'] ?? 'Your House'));
    if ($houseName === '') {
        $houseName = 'Your House';
    }

    $crestReference = house_safe_crest_reference(
        isset($revealHouse['crest_image']) ? (string) $revealHouse['crest_image'] : null
    );
    // Use the original stored crest on the reveal screen. This avoids a stale or
    // incorrectly converted WebP derivative replacing a transparent crest.
    $crestUrl = $crestReference !== null ? url(ltrim($crestReference, '/')) : null;

    $leadText = trim((string) ($revealData['reveal_lead_text'] ?? ''));
    $description = trim((string) ($revealHouse['description'] ?? ''));
    $motto = trim((string) ($revealHouse['motto'] ?? ''));
    $mascotType = trim((string) ($revealHouse['mascot_type'] ?? ''));
    $mascotName = trim((string) ($revealHouse['mascot_name'] ?? ''));
    ?>
    <main
        id="main-content"
        class="sorting-page sorting-reveal"
        style="--house-primary: <?= e($housePrimary); ?>; --house-secondary: <?= e($houseSecondary); ?>; --house-accent: <?= e($houseAccent); ?>; --house-highlight: <?= e($houseHighlight); ?>; --house-dark: <?= e($houseDark); ?>;"
    >
        <div class="sorting-stage">
            <section class="sorting-panel sorting-center" aria-labelledby="sorting-reveal-heading">
                <p class="sorting-overline">The choice is made</p>

                <?php if ($crestUrl !== null): ?>
                    <div class="sorting-crest-wrap">
                        <div class="sorting-crest-aura" aria-hidden="true"></div>
                        <img
                            class="sorting-crest"
                            src="<?= e($crestUrl); ?>"
                            alt="<?= e($houseName); ?> crest"
                            decoding="async"
                        >
                    </div>
                <?php endif; ?>

                <?php if ($leadText !== ''): ?>
                    <div class="sorting-reveal-copy"><?= nl2br(e($leadText)); ?></div>
                <?php endif; ?>

                <h1 id="sorting-reveal-heading" class="sorting-house-name"><?= e($houseName); ?></h1>

                <?php if ($motto !== ''): ?>
                    <p class="sorting-motto">“<?= e($motto); ?>”</p>
                <?php endif; ?>

                <?php if ($description !== ''): ?>
                    <p class="sorting-reveal-copy"><?= e($description); ?></p>
                <?php endif; ?>

                <?php if ($mascotType !== '' || $mascotName !== ''): ?>
                    <p class="sorting-mascot">
                        <?php if ($mascotType !== ''): ?>Mascot: <?= e($mascotType); ?><?php endif; ?>
                        <?php if ($mascotType !== '' && $mascotName !== ''): ?> · <?php endif; ?>
                        <?php if ($mascotName !== ''): ?><?= e($mascotName); ?><?php endif; ?>
                    </p>
                <?php endif; ?>

                <div class="sorting-actions">
                    <?php if (is_array($revealData['membership'] ?? null)): ?>
                        <a href="<?= e(url('common-room.php')); ?>" class="button button-primary">Enter Your Common Room</a>
                    <?php endif; ?>
                    <a href="<?= e(DASHBOARD_URL); ?>" class="button button-secondary">Return to Dashboard</a>
                </div>
            </section>
        </div>
    </main>

<?php elseif ($state === 'sorted'): ?>
    <?php
    $membership = is_array($entryState['membership'] ?? null) ? $entryState['membership'] : null;
    $house = is_array($entryState['house'] ?? null) ? $entryState['house'] : null;
    $houseName = trim((string) ($house['display_name'] ?? $house['name'] ?? 'your House'));
    ?>
    <main id="main-content" class="sorting-page">
        <div class="sorting-stage">
            <section class="sorting-panel sorting-center">
                <p class="sorting-overline">The ceremony remembers</p>
                <h1>You have already been sorted.</h1>
                <p class="sorting-intro-copy">Your place is with <?= e($houseName); ?>. The Sorting Ceremony can only be completed once.</p>
                <div class="sorting-actions">
                    <?php if ($membership !== null): ?>
                        <a href="<?= e(url('common-room.php')); ?>" class="button button-primary">Enter Your Common Room</a>
                    <?php endif; ?>
                    <a href="<?= e(DASHBOARD_URL); ?>" class="button button-secondary">Return to Dashboard</a>
                </div>
            </section>
        </div>
    </main>

<?php else: ?>
    <main id="main-content" class="sorting-page">
        <div class="sorting-stage">
            <section class="sorting-panel sorting-center">
                <div class="sorting-unavailable-icon" aria-hidden="true">✦</div>
                <p class="sorting-overline">Sorting Ceremony</p>
                <h1>The ceremony is not available right now.</h1>
                <p class="sorting-intro-copy">
                    No published ceremony is currently available, or your ceremony state could not be loaded.
                    Please return later or contact Academy staff if this continues.
                </p>
                <div class="sorting-actions">
                    <a href="<?= e(DASHBOARD_URL); ?>" class="button button-secondary">Return to Dashboard</a>
                </div>
            </section>
        </div>
    </main>
<?php endif; ?>

<?php require INCLUDES_PATH . '/footer.php'; ?>
