<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';


/*
|--------------------------------------------------------------------------
| SEO
|--------------------------------------------------------------------------
*/

$pageTitle =
    'About Blackthorne Academy | An Academy Beyond the Ordinary';

$pageDescription =
    'Discover Blackthorne Academy, an immersive online academy combining structured magical study, academic progression, community, storytelling, and roleplay-inspired student life.';

$pageCanonical = url('about.php');

require INCLUDES_PATH . '/header.php';

?>

<main id="main-content">


    <!-- ================================================================
         HERO
    ================================================================= -->

    <section
        class="about-hero"
        aria-labelledby="about-hero-heading"
    >

        <div
            class="hero-ornament hero-ornament-left"
            aria-hidden="true"
        ></div>

        <div
            class="hero-ornament hero-ornament-right"
            aria-hidden="true"
        ></div>


        <div class="section-inner about-hero-inner">

            <div class="about-hero-copy">

                <p class="academy-overline">

                    Beyond the Academy Gates

                </p>


                <h1 id="about-hero-heading">
                    Some schools are attended. Blackthorne is entered.
                </h1>


                <p class="about-hero-intro">
                    Blackthorne Academy is an immersive online academy built
                    around magical study, academic progression, community,
                    discovery, and the feeling that somewhere beyond the
                    screen, the academy doors are waiting to open.
                </p>


                <div
                    class="hero-actions"
                    aria-label="About Blackthorne actions"
                >

                    <a
    href="<?= e('login.php'); ?>"
    class="button button-primary"
>
    Enter the Academy
</a>


                    <a
                        href="<?= e(url('features.php')); ?>"
                        class="button button-secondary"
                    >
                        Explore Academy Life
                    </a>

                </div>


                <div
                    class="hero-motto"
                    aria-label="Blackthorne Academy motto"
                >

                    <span
                        class="ornament-line"
                        aria-hidden="true"
                    ></span>


                    <p>

                        <span>
                            Study
                        </span>

                        <span aria-hidden="true">
                            •
                        </span>

                        <span>
                            Story
                        </span>

                        <span aria-hidden="true">
                            •
                        </span>

                        <span>
                            Belonging
                        </span>

                    </p>

                </div>

            </div>

        </div>

    </section>


    <!-- ================================================================
         ABOUT BLACKTHORNE
    ================================================================= -->

    <section
        class="about-introduction"
        id="about-blackthorne"
        aria-labelledby="about-blackthorne-heading"
    >

        <div class="section-inner about-introduction-grid">


            <div class="about-introduction-heading">

                <p class="academy-overline">
                    About Blackthorne
                </p>


                <h2 id="about-blackthorne-heading">
                    A fictional academy built as though it were real.
                </h2>

            </div>


            <div class="about-introduction-copy">

                <p class="about-lead">
                    Blackthorne Academy began with a simple idea: an online
                    academy of magical study should feel like more than a
                    website containing lessons.
                </p>


                <p>
                    It should feel like a place.
                </p>


                <p>
                    A place with courses to complete, instructors to learn
                    from, Houses to belong to, achievements to earn,
                    discussions to join, traditions to discover, and a
                    history that becomes more personal the longer a student
                    remains within its halls.
                </p>


                <p>
                    Blackthorne is being built from the ground up around that
                    experience. Academic systems, community spaces,
                    progression, events, profiles, and immersive elements are
                    designed to belong to the same academy rather than exist
                    as disconnected website features.
                </p>


                <p>
                    The result is part online school, part fictional
                    institution, and part shared world: a place where
                    structured learning and imagination are allowed to exist
                    side by side.
                </p>

            </div>

        </div>

    </section>


    <!-- ================================================================
         RP INTRODUCTION
    ================================================================= -->

    <section
        class="about-arrival"
        aria-labelledby="arrival-heading"
    >

        <div class="section-inner">


            <div class="about-arrival-frame">


                <div
                    class="about-arrival-mark"
                    aria-hidden="true"
                >
                    <img
                        src="<?= e(asset('images/logo/logo_3.png')); ?>"
                        alt=""
                        width="130"
                        height="130"
                        loading="lazy"
                        decoding="async"
                    >
                </div>


                <div class="about-arrival-content">

                    <p class="academy-overline">
                        An Introduction, In Character
                    </p>


                    <h2 id="arrival-heading">
                        You have arrived at Blackthorne.
                    </h2>


                    <div
                        class="ornamental-rule"
                        aria-hidden="true"
                    >
                        <span></span>
                        <i></i>
                        <span></span>
                    </div>


                    <div class="about-rp-prose">

                        <p>
                            The road does not announce when the academy has
                            begun.
                        </p>


                        <p>
                            One moment there is only darkness, mountain mist,
                            and the distant suggestion of lights somewhere
                            beyond the valley. Then the iron gates appear
                            ahead of you, already open, their blackened metal
                            catching the gold of lantern flame.
                        </p>


                        <p>
                            Beyond them, Blackthorne rises from the mountainside.
                            Towers disappear into low clouds. Tall windows burn
                            warmly against the night. Somewhere inside, a bell
                            sounds once, deep enough to be felt before it is
                            properly heard.
                        </p>


                        <p>
                            No one rushes to explain what waits beyond the
                            doors.
                        </p>


                        <p>
                            That, you will learn, is rather the point.
                        </p>


                        <p>
                            There are halls you have not walked, subjects you
                            have never studied, names you have not yet learned,
                            and questions you did not know you were allowed to
                            ask. Other students have passed through these gates
                            before you. More will follow.
                        </p>


                        <p>
                            For now, your place in the academy is unwritten.
                        </p>


                        <p>
                            Somewhere beyond the entrance hall waits your first
                            lesson.
                        </p>

                    </div>


                    <footer class="about-arrival-signature">

    <span>
        Welcome to Blackthorne Academy.
    </span>

    <small>
        The gates are open.
    </small>


    <div
        class="about-arrival-actions"
        aria-label="Blackthorne Academy account actions"
    >

        <a
            href="<?= e(LOGIN_URL); ?>"
            class="button button-secondary"
        >
            Login
        </a>


        <a
            href="<?= e(REGISTER_URL); ?>"
            class="button button-primary"
        >
            Enroll
        </a>

    </div>

</footer>

                </div>

            </div>

        </div>

    </section>


    <!-- ================================================================
         TWO SIDES OF BLACKTHORNE
    ================================================================= -->

    <section
        class="about-two-worlds"
        aria-labelledby="two-worlds-heading"
    >

        <div class="section-inner">


            <header class="about-section-heading about-section-heading-centered">

                <p class="academy-overline">
                    The Academy &amp; The Experience
                </p>


                <h2 id="two-worlds-heading">
                    Blackthorne exists in two worlds at once.
                </h2>


                <p>
                    The academy is both a functioning online community and the
                    setting through which that community experiences study,
                    progression, and student life.
                </p>

            </header>


            <div class="about-worlds-layout">


                <article class="about-world about-world-real">

                    <span
                        class="about-world-number"
                        aria-hidden="true"
                    >
                        I
                    </span>


                    <p class="academy-overline">
                        Beyond the Story
                    </p>


                    <h3>
                        The Online Academy
                    </h3>


                    <p>
                        Behind the atmosphere is a real platform designed for
                        structured courses, lessons, assignments, assessments,
                        academic progress, discussions, events, achievements,
                        and community participation.
                    </p>


                    <p>
                        Students interact with actual academy systems while
                        progressing through their Blackthorne experience.
                    </p>

                </article>


                <div
                    class="about-world-divider"
                    aria-hidden="true"
                >

                    <span></span>

                    <div>
                        B
                    </div>

                    <span></span>

                </div>


                <article class="about-world about-world-story">

                    <span
                        class="about-world-number"
                        aria-hidden="true"
                    >
                        II
                    </span>


                    <p class="academy-overline">
                        Within the Story
                    </p>


                    <h3>
                        The Fictional Institution
                    </h3>


                    <p>
                        Inside the experience, Blackthorne is treated as an
                        academy with its own spaces, traditions, academic
                        culture, Houses, history, and student life.
                    </p>


                    <p>
                        Roleplay and in-world presentation provide atmosphere
                        and continuity without requiring every interaction to
                        become formal roleplay.
                    </p>

                </article>

            </div>

        </div>

    </section>


    <!-- ================================================================
         OUR APPROACH
    ================================================================= -->

    <section
        class="about-philosophy"
        aria-labelledby="philosophy-heading"
    >

        <div class="section-inner">


            <div class="about-philosophy-heading">

                <span
                    class="about-section-number"
                    aria-hidden="true"
                >
                    03
                </span>


                <p class="academy-overline">
                    The Blackthorne Approach
                </p>


                <h2 id="philosophy-heading">
                    Learning should give you somewhere to go next.
                </h2>


                <p>
                    Blackthorne is designed around three ideas that shape
                    both the academic system and the world surrounding it.
                </p>

            </div>


            <div class="about-philosophy-list">


                <article class="about-philosophy-entry">

                    <span
                        class="about-philosophy-symbol"
                        aria-hidden="true"
                    >
                        I
                    </span>


                    <div>

                        <h3>
                            Study With Structure
                        </h3>


                        <p>
                            Courses follow defined lessons and academic paths
                            so students are progressing through an education,
                            not simply browsing disconnected information.
                        </p>

                    </div>

                </article>


                <article class="about-philosophy-entry">

                    <span
                        class="about-philosophy-symbol"
                        aria-hidden="true"
                    >
                        II
                    </span>


                    <div>

                        <h3>
                            Make Progress Matter
                        </h3>


                        <p>
                            Completed courses, achievements, advancement,
                            House participation, and academy milestones
                            become part of an ongoing student history.
                        </p>

                    </div>

                </article>


                <article class="about-philosophy-entry">

                    <span
                        class="about-philosophy-symbol"
                        aria-hidden="true"
                    >
                        III
                    </span>


                    <div>

                        <h3>
                            Build Somewhere to Belong
                        </h3>


                        <p>
                            Forums, Houses, events, polls, contests, and
                            shared traditions give students reasons to remain
                            part of Blackthorne beyond finishing an assignment.
                        </p>

                    </div>

                </article>

            </div>

        </div>

    </section>


    <!-- ================================================================
         IMMERSION WITHOUT OBLIGATION
    ================================================================= -->

    <section
        class="about-roleplay"
        aria-labelledby="roleplay-heading"
    >

        <div class="section-inner about-roleplay-grid">


            <div class="about-roleplay-copy">

                <p class="academy-overline">
                    Roleplay &amp; Immersion
                </p>


                <h2 id="roleplay-heading">
                    You can inhabit the world without performing every moment.
                </h2>


                <p>
                    Blackthorne uses roleplay-inspired presentation to make
                    the academy feel alive, but students should not need to
                    remain in character every time they participate.
                </p>


                <p>
                    Some spaces and activities may invite more immersive or
                    creative interaction. Others exist simply for discussion,
                    coursework, questions, or community conversation.
                    The atmosphere supports the experience rather than
                    becoming a barrier to taking part in it.
                </p>


                <p>
                    In other words, you are welcome to walk through the gates
                    as deeply into the story as you wish.
                </p>

            </div>


            <aside
                class="about-roleplay-note"
                aria-label="Blackthorne roleplay philosophy"
            >

                <div
                    class="about-roleplay-note-mark"
                    aria-hidden="true"
                >
                    ✦
                </div>


                <blockquote>
                    <p>
                        The academy should feel real enough to enter, but
                        flexible enough to live in.
                    </p>
                </blockquote>


                <span>
                    Blackthorne Academy
                </span>

            </aside>

        </div>

    </section>


    <!-- ================================================================
         WHAT BLACKTHORNE IS BECOMING
    ================================================================= -->

    <section
        class="about-future"
        aria-labelledby="future-heading"
    >

        <div class="section-inner">


            <header class="about-section-heading about-section-heading-centered">

                <p class="academy-overline">
                    Still Being Written
                </p>


                <h2 id="future-heading">
                    An academy designed to grow with its students.
                </h2>


                <div
                    class="ornamental-rule"
                    aria-hidden="true"
                >
                    <span></span>
                    <i></i>
                    <span></span>
                </div>


                <p>
                    Blackthorne is being built as a living institution.
                    Courses, traditions, events, community spaces, academic
                    systems, and academy history can expand over time rather
                    than remaining frozen at launch.
                </p>

            </header>


            <div class="about-future-path">


                <article>

                    <span aria-hidden="true">
                        01
                    </span>

                    <h3>
                        A Beginning
                    </h3>

                    <p>
                        Enter the academy, complete orientation, and begin
                        your first year of magical study.
                    </p>

                </article>


                <div
                    class="about-path-line"
                    aria-hidden="true"
                ></div>


                <article>

                    <span aria-hidden="true">
                        02
                    </span>

                    <h3>
                        A History
                    </h3>

                    <p>
                        Build a record of courses, milestones, participation,
                        achievements, and academy experiences.
                    </p>

                </article>


                <div
                    class="about-path-line"
                    aria-hidden="true"
                ></div>


                <article>

                    <span aria-hidden="true">
                        03
                    </span>

                    <h3>
                        A Place in Blackthorne
                    </h3>

                    <p>
                        Become part of the traditions and community that will
                        continue shaping the academy itself.
                    </p>

                </article>

            </div>

        </div>

    </section>


    <!-- ================================================================
         CLOSING
    ================================================================= -->

    <section
        class="about-closing"
        aria-labelledby="about-closing-heading"
    >

        <div class="section-inner">

            <div class="about-closing-frame">


                <img
                    src="<?= e(asset('images/logo/logo_3.png')); ?>"
                    alt=""
                    class="about-closing-crest"
                    width="145"
                    height="145"
                    loading="lazy"
                    decoding="async"
                >


                <p class="academy-overline">
                    Blackthorne Academy
                </p>


                <h2 id="about-closing-heading">
                    There are still rooms in the academy no one has entered yet.
                </h2>


                <p>
                    Some of them have not even been built.
                </p>


                <p>
                    That is part of the story too.
                </p>

            </div>

        </div>

    </section>


    <!-- ================================================================
         ADMISSIONS
    ================================================================= -->

    <section
        class="admissions-notice about-admissions"
        aria-labelledby="about-admissions-heading"
    >

        <div class="section-inner">

            <div class="admissions-frame">


                <div
                    class="admissions-seal"
                    aria-hidden="true"
                >
                    B
                </div>


                <div class="admissions-copy">

                    <p class="academy-overline">
                        Admissions
                    </p>


                    <h2 id="about-admissions-heading">
                        Your first chapter begins at the academy gates.
                    </h2>


                    <p>
                        Create your Blackthorne account and take the first
                        step into academy life.
                    </p>

                </div>


                <div class="admissions-action">

                    <a
                        href="<?= e(REGISTER_URL); ?>"
                        class="button button-primary button-large"
                    >
                        Enroll at Blackthorne
                    </a>

                </div>

            </div>

        </div>

    </section>


</main>


<?php

require INCLUDES_PATH . '/footer.php';