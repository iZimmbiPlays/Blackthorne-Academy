<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/forum-functions.php';


/*
|--------------------------------------------------------------------------
| Determine Homepage State
|--------------------------------------------------------------------------
|
| Blackthorne Academy will eventually show different homepage content
| depending on the visitor's account/academic role.
|
| Current states available from auth.php:
|
| guest
| registered
| student
| staff
| admin
|
| Important:
| - index.php always remains the academy Home page.
| - Logged-in visitors are NOT redirected to the dashboard.
| - The current public homepage remains visible for now.
| - Logged-in homepage content can be introduced later using $homepageState.
|
*/

$homepageState =
    current_dashboard_type();

/*
 * Every authenticated member uses the shared member homepage. Dashboard
 * types still control dashboard content and can later add role-specific
 * homepage links without replacing the member homepage itself.
 */
$registeredHomepage =
    is_logged_in();


/*
|--------------------------------------------------------------------------
| Authenticated-Member Homepage Data
|--------------------------------------------------------------------------
|
| This is the shared homepage for registered members, students, staff, and
| administrators. Role-specific links can be added inside this shared layout
| later without sending anyone back to the public guest homepage.
|
*/

$registeredHomeData = [];


if ($registeredHomepage) {

    $user =
        current_user();


    if ($user === null) {

        redirect(
            LOGIN_URL
        );

    }


    $userId =
        (int) $user['id'];


    $displayName =
        trim(
            (string) (
                $user['display_name']
                ?? $user['username']
                ?? 'Scholar'
            )
        );


    if ($displayName === '') {

        $displayName =
            'Scholar';

    }


    $roleName =
        'Registered User';


    if (current_user_is_superuser()) {

        /*
         * The protected status stays private. Public/member-facing identity
         * simply displays Admin.
         */

        $roleName =
            'Admin';

    } else {

        $assignedRoles =
            current_user_roles();

        /*
         * Prefer the first active staff-designated role so a user such as a
         * Global Moderator is displayed by the actual role/group name rather
         * than a generic Staff label.
         */

        foreach ($assignedRoles as $assignedRole) {

            $assignedRoleName =
                trim(
                    (string) (
                        $assignedRole['name']
                        ?? ''
                    )
                );

            if (
                $assignedRoleName !== ''
                && (int) ($assignedRole['is_staff'] ?? 0) === 1
            ) {

                $roleName =
                    $assignedRoleName;

                break;

            }

        }


        /*
         * Otherwise display the first active assigned role.
         */

        if ($roleName === 'Registered User') {

            foreach ($assignedRoles as $assignedRole) {

                $assignedRoleName =
                    trim(
                        (string) (
                            $assignedRole['name']
                            ?? ''
                        )
                    );

                if ($assignedRoleName !== '') {

                    $roleName =
                        $assignedRoleName;

                    break;

                }

            }

        }


        if (
            $roleName === 'Registered User'
            && current_user_has_student_enrollment()
        ) {

            $roleName =
                'Student';

        }

    }



    $registeredHomeData = [
        'user_id'          => $userId,
        'display_name'     => $displayName,
        'role_name'        => $roleName,
        'house'            => null,
        'class_year'       => null,
        'online_count'     => 1,
        'unread_messages'  => 0,
        'online_friends'   => null,
        'forum_categories' => [],
        'announcements'    => [],
    ];


    try {

        /* Count members with an active session in the last 15 minutes. */

        $statement =
            $pdo->query(
                '
                SELECT COUNT(DISTINCT user_id)

                FROM user_sessions

                WHERE is_active = 1

                  AND expires_at > NOW()

                  AND last_activity_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                '
            );


        $registeredHomeData['online_count'] =
            max(
                1,
                (int) $statement->fetchColumn()
            );


        /* Current active House membership, when one exists. */

        $statement =
            $pdo->prepare(
                '
                SELECT
                    h.id,
                    h.name,
                    h.display_name,
                    h.display_color,
                    h.primary_color,
                    h.common_room_forum_id

                FROM house_memberships hm

                INNER JOIN houses h
                    ON h.id = hm.house_id

                WHERE hm.user_id = :user_id

                  AND hm.membership_status = "active"

                  AND hm.left_at IS NULL

                  AND h.is_active = 1

                ORDER BY
                    hm.joined_at DESC,
                    hm.id DESC

                LIMIT 1
                '
            );


        $statement->execute([
            'user_id' => $userId,
        ]);


        $house =
            $statement->fetch(
                PDO::FETCH_ASSOC
            );


        if ($house) {

            $registeredHomeData['house'] =
                $house;

        }


        /* Class year remains hidden until an active student year exists. */

        $statement =
            $pdo->prepare(
                '
                SELECT yg.name

                FROM student_year_enrollments sye

                INNER JOIN year_groups yg
                    ON yg.id = sye.year_group_id

                INNER JOIN school_years sy
                    ON sy.id = sye.school_year_id

                WHERE sye.user_id = :user_id

                  AND sye.promotion_status IN (
                        "active",
                        "eligible",
                        "not_eligible",
                        "repeating"
                      )

                ORDER BY
                    sy.is_current DESC,
                    sye.started_at DESC

                LIMIT 1
                '
            );


        $statement->execute([
            'user_id' => $userId,
        ]);


        $classYear =
            $statement->fetchColumn();


        if (is_string($classYear) && $classYear !== '') {

            $registeredHomeData['class_year'] =
                $classYear;

        }


        /* Unread messages use each participant's conversation read time. */

        $statement =
            $pdo->prepare(
                '
                SELECT COUNT(DISTINCT pm.id)

                FROM conversation_participants cp

                INNER JOIN private_messages pm
                    ON pm.conversation_id = cp.conversation_id

                LEFT JOIN private_message_user_states pmus
                    ON pmus.message_id = pm.id
                   AND pmus.user_id = cp.user_id

                WHERE cp.user_id = :user_id

                  AND cp.left_at IS NULL

                  AND pm.sender_id <> :sender_id

                  AND (
                        cp.last_read_at IS NULL
                        OR pm.created_at > cp.last_read_at
                      )

                  AND COALESCE(pmus.is_hidden, 0) = 0
                '
            );


        $statement->execute([
            'user_id'   => $userId,
            'sender_id' => $userId,
        ]);


        $registeredHomeData['unread_messages'] =
            (int) $statement->fetchColumn();


        /*
         * The existing schema does not yet include friendships, so the
         * Friends link is ready without displaying an invented count.
         */


        /*
         * Sidebar forum navigation is database-driven.
         *
         * A visible category appears in the member sidebar when
         * forum_categories.show_in_sidebar = 1 and the current user can view
         * that category. Forums and sub-forums are then loaded from that
         * category using the same role/user visibility rules.
         */

        $categoryStatement =
            $pdo->prepare(
                '
                SELECT
                    fc.id,
                    fc.title,
                    fc.slug,
                    fc.description,
                    fc.sort_order

                FROM forum_categories fc

                WHERE fc.is_visible = 1

                  AND fc.show_in_sidebar = 1

                  AND (
                        fc.access_mode = "public"

                        OR EXISTS (
                            SELECT 1

                            FROM forum_category_user_access fcua

                            WHERE fcua.category_id = fc.id

                              AND fcua.user_id = :category_user_id

                              AND fcua.can_view_category = 1
                        )

                        OR EXISTS (
                            SELECT 1

                            FROM user_roles ur

                            INNER JOIN forum_category_role_access fcra
                                ON fcra.role_id = ur.role_id

                            WHERE ur.user_id = :category_role_user_id

                              AND ur.is_active = 1

                              AND ur.revoked_at IS NULL

                              AND (
                                    ur.expires_at IS NULL
                                    OR ur.expires_at > NOW()
                                  )

                              AND fcra.category_id = fc.id

                              AND fcra.can_view_category = 1
                        )
                      )

                ORDER BY
                    fc.sort_order ASC,
                    fc.title ASC
                '
            );


        $categoryStatement->execute([
            'category_user_id' => $userId,
            'category_role_user_id' => $userId,
        ]);


        $categories =
            $categoryStatement->fetchAll(
                PDO::FETCH_ASSOC
            );


        /*
         * Homepage forum quick-access intentionally contains only top-level
         * forums. Nested sub-forums remain inside their actual parent pages,
         * regardless of hierarchy depth.
         */
        $forumStatement =
            $pdo->prepare(
                '
                SELECT
                    f.id,
                    f.category_id,
                    f.parent_forum_id,
                    f.title,
                    f.slug,
                    f.description,
                    f.sort_order

                FROM forums f

                WHERE f.category_id = :category_id

                  AND f.parent_forum_id IS NULL

                  AND f.forum_type = "general"

                  AND f.is_visible = 1

                ORDER BY
                    f.sort_order ASC,
                    f.title ASC
                '
            );



        foreach ($categories as $category) {

            $categoryId =
                (int) $category['id'];


            $forumStatement->execute([
                'category_id' => $categoryId,
            ]);


            $accessibleForums =
                array_values(
                    array_filter(
                        $forumStatement->fetchAll(
                            PDO::FETCH_ASSOC
                        ),
                        static function (array $forum) use ($pdo, $userId): bool {
                            return forum_can_view_forum(
                                $pdo,
                                (int) $forum['id'],
                                $userId
                            );
                        }
                    )
                );


            /*
             * The query already limits the homepage quick-access to main
             * forums. Keep the data flat so deeper nesting never leaks into
             * global navigation.
             */

            /*
             * A sidebar-enabled category is included even when it has no
             * forums yet. This lets administrators create the category first
             * and immediately see its title bar on the member homepage.
             */

            $category['forums'] =
                $accessibleForums;


            $registeredHomeData['forum_categories'][] =
                $category;

        }


        /* Latest accessible threads across all Announcement Forums, with their opening post. */

        /* Latest accessible threads across all Announcement Forums. */
        $statement =
            $pdo->query(
                '
                SELECT
                    ft.id,
                    ft.forum_id,
                    ft.title,
                    ft.user_id AS author_id,
                    ft.created_at,
                    ft.is_locked,
                    u.display_name AS author_display_name,
                    author_house.display_color AS author_house_color,
                    fp.content,
                    (
                        SELECT COUNT(*)

                        FROM forum_posts replies

                        WHERE replies.thread_id = ft.id

                          AND replies.is_deleted = 0

                          AND replies.id <> fp.id
                    ) AS reply_count

                FROM forum_threads ft

                INNER JOIN forums f
                    ON f.id = ft.forum_id

                INNER JOIN forum_categories fc
                    ON fc.id = f.category_id

                INNER JOIN users u
                    ON u.id = ft.user_id

                LEFT JOIN house_memberships author_membership
                    ON author_membership.id = (
                        SELECT hm2.id
                        FROM house_memberships hm2
                        WHERE hm2.user_id = ft.user_id
                          AND hm2.membership_status = "active"
                          AND hm2.left_at IS NULL
                        ORDER BY hm2.joined_at DESC, hm2.id DESC
                        LIMIT 1
                    )

                LEFT JOIN houses author_house
                    ON author_house.id = author_membership.house_id
                   AND author_house.is_active = 1

                INNER JOIN forum_posts fp
                    ON fp.id = (
                        SELECT MIN(opening_post.id)

                        FROM forum_posts opening_post

                        WHERE opening_post.thread_id = ft.id

                          AND opening_post.is_deleted = 0
                    )

                WHERE f.is_announcement_forum = 1

                  AND f.is_visible = 1

                  AND ft.is_deleted = 0

                  AND fc.is_visible = 1

                ORDER BY
                    ft.is_pinned DESC,
                    ft.created_at DESC

                LIMIT 100
                '
            );


        $registeredHomeData['announcements'] = [];

        foreach (
            $statement->fetchAll(PDO::FETCH_ASSOC)
            as $announcement
        ) {
            if (
                !forum_can_access_forum(
                    $pdo,
                    (int) $announcement['forum_id'],
                    $userId
                )
                || !forum_can_view_thread(
                    $pdo,
                    (int) $announcement['id'],
                    $userId
                )
            ) {
                continue;
            }

            $registeredHomeData['announcements'][] =
                $announcement;

            if (
                count(
                    $registeredHomeData['announcements']
                ) >= 10
            ) {
                break;
            }
        }

    } catch (PDOException $exception) {

        error_log(
            'Registered homepage data could not be loaded: '
            . $exception->getMessage()
        );

    }

}


/*
|--------------------------------------------------------------------------
| SEO
|--------------------------------------------------------------------------
*/

$pageTitle =
    'Blackthorne Academy | An Online Academy of Magical Study';

$pageDescription =
    'Enter Blackthorne Academy, a fantasy-styled online academy featuring magical coursework, academic progression, Houses, achievements, community, and immersive student life.';

$pageCanonical = HOME_URL;

require INCLUDES_PATH . '/header.php';

?>

<?php if ($registeredHomepage): ?>

<?php require __DIR__ . '/views/homepages/registered.php'; ?>

<?php else: ?>

<main id="main-content">


    <!-- ================================================================
         HERO
    ================================================================= -->

    <section class="home-hero" aria-labelledby="home-hero-heading">

        <!-- Decorative elements -->

        <div class="hero-ornament hero-ornament-left" aria-hidden="true"></div>

        <div class="hero-ornament hero-ornament-right" aria-hidden="true"></div>


        <div class="section-inner home-hero-grid">


            <!-- ========================================================
                 Hero Introduction
            ========================================================= -->

            <div class="home-hero-copy">

                <p class="academy-overline">

                    Est. 2026

                    <span aria-hidden="true">
                        ◆
                    </span>

                    Blackthorne Academy

                </p>


                <h1 id="home-hero-heading">
                    Where curiosity becomes craft.
                </h1>


                <p class="home-hero-intro">
                    Step beyond the ordinary classroom and into an academy
                    devoted to magical study, structured learning, discovery,
                    and a community built around the strange, the curious,
                    and the arcane.
                </p>


                <div class="hero-actions" aria-label="Academy introduction actions">

                    <a href="<?= e(REGISTER_URL); ?>" class="button button-primary">
                        Begin Enrollment
                    </a>


                    <a href="<?= e(url('about.php')); ?>" class="button button-secondary">
                        Discover the Academy
                    </a>

                </div>


                <div class="hero-motto" aria-label="Blackthorne Academy values">

                    <span class="ornament-line" aria-hidden="true"></span>


                    <p>

                        <span>
                            Knowledge
                        </span>

                        <span aria-hidden="true">
                            •
                        </span>

                        <span>
                            Practice
                        </span>

                        <span aria-hidden="true">
                            •
                        </span>

                        <span>
                            Discovery
                        </span>

                    </p>

                </div>

            </div>


            <!-- ========================================================
                 Login Panel
            ========================================================= -->

            <aside class="academy-login-panel" aria-labelledby="login-heading">

                <div class="login-panel-top">

                    <img src="<?= e(asset('images/logo/logo_3.png')); ?>" alt="" class="login-crest-image" width="100"
                        height="100" loading="eager" decoding="async">


                    <div>

                        <p class="login-eyebrow">
                            Academy Access
                        </p>

                        <h2 id="login-heading">
                            Student &amp; Staff Login
                        </h2>

                    </div>

                </div>


                <div class="ornamental-rule" aria-hidden="true">
                    <span></span>
                    <i></i>
                    <span></span>
                </div>


                <p class="sr-only" id="login-description">
                    Log in to your Blackthorne Academy account using your
                    username or email address and password.
                </p>


                <form action="<?= e(LOGIN_URL); ?>" method="post" aria-describedby="login-description">

                    <?= csrf_field(); ?>


                    <div class="form-group">

                        <label for="login-identifier">
                            Username or Email
                        </label>

                        <input class="form-control" type="text" id="login-identifier" name="identifier"
                            autocomplete="username" autocapitalize="none" spellcheck="false" required>

                    </div>


                    <div class="form-group">

                        <label for="login-password">
                            Password
                        </label>

                        <input class="form-control" type="password" id="login-password" name="password"
                            autocomplete="current-password" required>

                    </div>


                    <div class="login-options">

                        <label class="remember-me">

                            <input type="checkbox" name="remember_me" value="1">

                            <span>
                                Remember me
                            </span>

                        </label>


                        <span class="password-note">
                            Password recovery coming soon
                        </span>

                    </div>


                    <button type="submit" class="button button-primary login-submit">
                        Enter the Academy
                    </button>


                    <p class="login-footer">

                        Not yet enrolled?

                        <a href="<?= e(REGISTER_URL); ?>">
                            Create your academy account.
                        </a>

                    </p>

                </form>

            </aside>

        </div>

    </section>


    <!-- ================================================================
         BLACKTHORNE EXPERIENCE
    ================================================================= -->

    <section class="academy-experience" aria-labelledby="experience-heading">

        <div class="section-inner">

            <header class="academy-section-heading">

                <p class="academy-overline">
                    The Blackthorne Experience
                </p>


                <h2 id="experience-heading">
                    An academy designed to feel lived in.
                </h2>


                <p>
                    Blackthorne is more than a collection of online classes.
                    Coursework, Houses, achievements, community spaces, and
                    academic progression are meant to feel like parts of the
                    same school.
                </p>

            </header>


            <div class="experience-ledger">


                <!-- Study -->

                <article class="experience-entry">

                    <div class="experience-symbol" aria-hidden="true">
                        ✦
                    </div>


                    <div class="experience-entry-heading">

                        <span class="experience-label">
                            Study
                        </span>

                        <h3>
                            A Structured Magical Curriculum
                        </h3>

                    </div>


                    <p>
                        Work through lessons, assignments, practical
                        exercises, quizzes, midterms, and finals while
                        progressing through Blackthorne's academic years.
                    </p>

                </article>


                <!-- Belong -->

                <article class="experience-entry">

                    <div class="experience-symbol" aria-hidden="true">
                        ◇
                    </div>


                    <div class="experience-entry-heading">

                        <span class="experience-label">
                            Belong
                        </span>

                        <h3>
                            A School Beyond the Classroom
                        </h3>

                    </div>


                    <p>
                        Join academy discussions, Houses, events, polls,
                        contests, and shared spaces designed to make
                        Blackthorne feel like a community rather than a
                        dashboard.
                    </p>

                </article>


                <!-- Progress -->

                <article class="experience-entry">

                    <div class="experience-symbol" aria-hidden="true">
                        ✧
                    </div>


                    <div class="experience-entry-heading">

                        <span class="experience-label">
                            Progress
                        </span>

                        <h3>
                            A History That Grows With You
                        </h3>

                    </div>


                    <p>
                        Earn academic points, achievements, course
                        completions, House points, and a lasting record of
                        your time within the academy.
                    </p>

                </article>

            </div>


            <div class="academy-section-link">

                <a href="<?= e(url('features.php')); ?>" class="text-link">
                    Explore everything Blackthorne offers

                    <span aria-hidden="true">
                        &rarr;
                    </span>
                </a>

            </div>

        </div>

    </section>


    <!-- ================================================================
         ABOUT BLACKTHORNE
    ================================================================= -->

    <section class="academy-about-preview" aria-labelledby="about-preview-heading">

        <div class="section-inner academy-about-grid">


            <!-- ========================================================
                 About Copy
            ========================================================= -->

            <div class="academy-about-copy">

                <p class="academy-overline">
                    Beyond the Gates
                </p>


                <h2 id="about-preview-heading">
                    A fictional academy built as though it were real.
                </h2>


                <div class="ornamental-rule ornamental-rule-left" aria-hidden="true">
                    <span></span>
                    <i></i>
                    <span></span>
                </div>


                <p>
                    Blackthorne Academy is an online fantasy school centered
                    on magical study, academic progression, creative
                    participation, and community. Students do not simply
                    collect lessons. They move through an academy with its
                    own systems, traditions, spaces, and history.
                </p>


                <p>
                    The platform itself is being built specifically for
                    Blackthorne. Courses, Houses, forums, achievements,
                    events, progression, and student life are designed to
                    work together rather than exist as disconnected
                    features.
                </p>


                <a href="<?= e(url('about.php')); ?>" class="button button-secondary">
                    Read About Blackthorne
                </a>

            </div>


            <!-- ========================================================
                 Academy Artwork
            ========================================================= -->

            <div class="academy-image-frame">

                <picture>

                    <source srcset="<?= e(asset('images/about_image.webp')); ?>" type="image/webp">

                    <img src="<?= e(asset('images/about_image.png')); ?>"
                        alt="Blackthorne Academy overlooking a misty mountain valley beneath a moonlit purple sky"
                        class="academy-about-image" width="1024" height="1024" loading="lazy" decoding="async">

                </picture>


                <div class="frame-corner frame-corner-tl" aria-hidden="true"></div>

                <div class="frame-corner frame-corner-tr" aria-hidden="true"></div>

                <div class="frame-corner frame-corner-bl" aria-hidden="true"></div>

                <div class="frame-corner frame-corner-br" aria-hidden="true"></div>

            </div>

        </div>

    </section>


    <!-- ================================================================
         ADMISSIONS
    ================================================================= -->

    <section class="admissions-notice" aria-labelledby="admissions-heading">

        <div class="section-inner">

            <div class="admissions-frame">


                <div class="admissions-seal" aria-hidden="true">
                    B
                </div>


                <div class="admissions-copy">

                    <p class="academy-overline">
                        Admissions
                    </p>


                    <h2 id="admissions-heading">
                        Your first chapter begins at the academy gates.
                    </h2>


                    <p>
                        Create your account, complete academy orientation,
                        choose your courses, and begin your first year at
                        Blackthorne.
                    </p>

                </div>


                <div class="admissions-action">

                    <a href="<?= e(REGISTER_URL); ?>" class="button button-primary button-large">
                        Enroll at Blackthorne
                    </a>

                </div>

            </div>

        </div>

    </section>

</main>

<?php endif; ?>


<?php

require INCLUDES_PATH . '/footer.php';
