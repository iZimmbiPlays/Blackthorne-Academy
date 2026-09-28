<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/profile-functions.php';
require_once INCLUDES_PATH . '/friend-functions.php';

require_login();
require_active_account();


/*
|--------------------------------------------------------------------------
| Current User
|--------------------------------------------------------------------------
*/

$user =
    current_user();

if ($user === null) {
    redirect(
        LOGIN_URL
    );
}

$userId =
    (int) (
        $user['id']
        ?? current_user_id()
        ?? 0
    );

if ($userId <= 0) {
    redirect(
        LOGIN_URL
    );
}


/*
|--------------------------------------------------------------------------
| Blocked Members
|--------------------------------------------------------------------------
*/

$blockedStatement =
    $pdo->prepare(
        'SELECT
            ub.id AS block_id,
            ub.blocked_user_id,
            ub.created_at AS blocked_at,
            u.display_name,
            u.username,
            u.avatar,
            u.status

         FROM user_blocks ub

         INNER JOIN users u
            ON u.id = ub.blocked_user_id

         WHERE ub.blocker_user_id = :blocker_user_id

         ORDER BY
            u.display_name ASC,
            u.id ASC'
    );

$blockedStatement->execute([
    'blocker_user_id' =>
        $userId,
]);

$blockedMembers =
    $blockedStatement->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Prepared Display Data
|--------------------------------------------------------------------------
*/

$blockedMemberCards = [];

foreach ($blockedMembers as $blockedMember) {
    $blockedUserId =
        (int) (
            $blockedMember['blocked_user_id']
            ?? 0
        );

    if ($blockedUserId <= 0) {
        continue;
    }

    $displayName =
        trim(
            (string) (
                $blockedMember['display_name']
                ?? ''
            )
        );

    if ($displayName === '') {
        $displayName =
            trim(
                (string) (
                    $blockedMember['username']
                    ?? 'Member'
                )
            );
    }

    $avatarSources =
        profile_picture_sources(
            isset($blockedMember['avatar'])
                ? (string) $blockedMember['avatar']
                : null
        );

    $blockedMemberCards[] = [
        'user_id' =>
            $blockedUserId,

        'display_name' =>
            $displayName,

        'username' =>
            (string) (
                $blockedMember['username']
                ?? ''
            ),

        'avatar_original' =>
            $avatarSources['original']
            ?? null,

        'avatar_webp' =>
            $avatarSources['webp']
            ?? null,

        'blocked_at' =>
            (string) (
                $blockedMember['blocked_at']
                ?? ''
            ),
    ];
}


/*
|--------------------------------------------------------------------------
| Flash Messages
|--------------------------------------------------------------------------
*/

$successMessage =
    get_flash(
        'success'
    );

$errorMessage =
    get_flash(
        'error'
    );


/*
|--------------------------------------------------------------------------
| Metadata
|--------------------------------------------------------------------------
*/

$pageTitle =
    'Blocked Members | Blackthorne Academy';

$pageDescription =
    'Manage members you have blocked on Blackthorne Academy.';

$robots =
    'noindex, nofollow';

require
    INCLUDES_PATH
    . '/header.php';

?>


<main
    id="main-content"
    class="blocked-members-page"
>

    <section class="blocked-members-hero">

        <div class="section-inner">

            <div class="blocked-members-hero-content">

                <div>

                    <p class="academy-overline">
                        Privacy
                    </p>

                    <h1>
                        Blocked Members
                    </h1>

                    <p class="blocked-members-summary">
                        Review people you have blocked and unblock them at any time.
                    </p>

                </div>

                <a
                    class="button button-secondary"
                    href="<?= e(url('profile.php?u=me')); ?>"
                >
                    Back to Profile
                </a>

            </div>

        </div>

    </section>


    <section class="blocked-members-content">

        <div class="section-inner">

            <?php if ($successMessage !== null): ?>

                <div
                    class="blocked-members-message is-success"
                    role="status"
                >
                    <?= e($successMessage); ?>
                </div>

            <?php endif; ?>


            <?php if ($errorMessage !== null): ?>

                <div
                    class="blocked-members-message is-error"
                    role="alert"
                >
                    <?= e($errorMessage); ?>
                </div>

            <?php endif; ?>


            <?php if ($blockedMemberCards !== []): ?>

                <div class="blocked-members-list">

                    <?php foreach ($blockedMemberCards as $member): ?>

                        <?php
                        $memberUserId =
                            (int) $member['user_id'];

                        $memberName =
                            (string) $member['display_name'];

                        $memberInitial =
                            strtoupper(
                                mb_substr(
                                    $memberName,
                                    0,
                                    1
                                )
                            );
                        ?>

                        <article class="blocked-member-card">

                            <a
                                class="blocked-member-profile-link"
                                href="<?= e(
                                    url(
                                        'profile.php?u='
                                        . $memberUserId
                                    )
                                ); ?>"
                            >

                                <span class="blocked-member-avatar">

                                    <?php if (!empty($member['avatar_original'])): ?>

                                        <picture>

                                            <?php if (!empty($member['avatar_webp'])): ?>

                                                <source
                                                    srcset="<?= e(
                                                        (string) $member['avatar_webp']
                                                    ); ?>"
                                                    type="image/webp"
                                                >

                                            <?php endif; ?>

                                            <img
                                                src="<?= e(
                                                    (string) $member['avatar_original']
                                                ); ?>"
                                                alt="<?= e($memberName); ?>"
                                                loading="lazy"
                                            >

                                        </picture>

                                    <?php else: ?>

                                        <span
                                            class="blocked-member-avatar-fallback"
                                            aria-hidden="true"
                                        >
                                            <?= e($memberInitial); ?>
                                        </span>

                                    <?php endif; ?>

                                </span>


                                <span class="blocked-member-details">

                                    <strong
                                        class="blocked-member-name"
                                        <?= user_display_name_style_attr($memberUserId); ?>
                                    >
                                        <?= e($memberName); ?>
                                    </strong>

                                    <span class="blocked-member-status">
                                        Blocked
                                    </span>

                                </span>

                            </a>


                            <form
                                method="post"
                                action="<?= e(url('friend-action.php')); ?>"
                                class="blocked-member-unblock-form"
                            >
                                <?= csrf_field(); ?>

                                <input
                                    type="hidden"
                                    name="action"
                                    value="unblock"
                                >

                                <input
                                    type="hidden"
                                    name="target_user_id"
                                    value="<?= $memberUserId; ?>"
                                >

                                <input
                                    type="hidden"
                                    name="return_to"
                                    value="<?= e(url('blocked-users.php')); ?>"
                                >

                                <button
                                    type="submit"
                                    class="button button-secondary"
                                >
                                    Unblock
                                </button>

                            </form>

                        </article>

                    <?php endforeach; ?>

                </div>

            <?php else: ?>

                <div class="blocked-members-empty">

                    <p class="academy-overline">
                        No Blocks
                    </p>

                    <h2>
                        Your Block List Is Empty
                    </h2>

                    <p>
                        Members you block will appear here so you can easily unblock them later.
                    </p>

                </div>

            <?php endif; ?>

        </div>

    </section>

</main>


<?php

require
    INCLUDES_PATH
    . '/footer.php';
