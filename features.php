<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';


/*
|--------------------------------------------------------------------------
| SEO
|--------------------------------------------------------------------------
*/

$pageTitle =
    'Academy Features | Blackthorne Academy';

$pageDescription =
    'Explore Blackthorne Academy coursework, academic progression, Houses, achievements, community features, events, student profiles, and immersive academy life.';

$pageCanonical = url('features.php');

require INCLUDES_PATH . '/header.php';

?>

<main id="main-content">


    <!-- ================================================================
         SVG ICON DEFINITIONS
         Decorative symbols used throughout the Features page.
         Hidden from assistive technology because every icon appears
         beside visible descriptive text.
    ================================================================= -->

    <svg class="feature-icon-library" aria-hidden="true" focusable="false">

        <defs>


            <!-- Open Book -->

            <symbol id="icon-book" viewBox="0 0 24 24">
                <path d="M3.5 5.5A3.5 3.5 0 0 1 7 2h5v18H7a3.5 3.5 0 0 0-3.5 3.5z" />
                <path d="M20.5 5.5A3.5 3.5 0 0 0 17 2h-5v18h5a3.5 3.5 0 0 1 3.5 3.5z" />
            </symbol>


            <!-- Scroll -->

            <symbol id="icon-scroll" viewBox="0 0 24 24">
                <path d="M6 3h12a2 2 0 0 1 2 2v2H8a2 2 0 0 0-2 2v11" />
                <path d="M6 20a2 2 0 0 1-2-2v-2h12a2 2 0 0 0 2-2V7" />
                <path d="M9 10h6" />
                <path d="M9 13h5" />
            </symbol>


            <!-- Quill -->

            <symbol id="icon-quill" viewBox="0 0 24 24">
                <path d="M20.5 3.5c-6.2.2-11.7 4.4-13.5 10.4" />
                <path d="M20.5 3.5c-.2 6.2-4.4 11.7-10.4 13.5" />
                <path d="M6 18 16.5 7.5" />
                <path d="M4 21l2-5" />
            </symbol>


            <!-- Academic Seal -->

            <symbol id="icon-seal" viewBox="0 0 24 24">
                <circle cx="12" cy="10" r="6" />
                <path d="m9 15-1 7 4-2 4 2-1-7" />
                <path d="m12 6 1.1 2.2 2.4.4-1.7 1.7.4 2.4-2.2-1.1-2.2 1.1.4-2.4-1.7-1.7 2.4-.4z" />
            </symbol>


            <!-- Compass -->

            <symbol id="icon-compass" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="9" />
                <path d="m15.5 8.5-2.1 4.9-4.9 2.1 2.1-4.9z" />
                <circle cx="12" cy="12" r="1" />
            </symbol>


            <!-- Hourglass -->

            <symbol id="icon-hourglass" viewBox="0 0 24 24">
                <path d="M6 3h12" />
                <path d="M6 21h12" />
                <path d="M8 3c0 4 1.5 6.2 4 9-2.5 2.8-4 5-4 9" />
                <path d="M16 3c0 4-1.5 6.2-4 9 2.5 2.8 4 5 4 9" />
            </symbol>


            <!-- Progress -->

            <symbol id="icon-progress" viewBox="0 0 24 24">
                <path d="M4 19h5v-5h5V9h6" />
                <path d="m16 5 4 4-4 4" />
            </symbol>


            <!-- Archive -->

            <symbol id="icon-archive" viewBox="0 0 24 24">
                <path d="M4 6h16v15H4z" />
                <path d="M3 3h18v4H3z" />
                <path d="M9 11h6" />
                <path d="M9 15h6" />
            </symbol>


            <!-- Shield -->

            <symbol id="icon-shield" viewBox="0 0 24 24">
                <path d="M12 2 20 5v6c0 5.1-3.1 8.7-8 11-4.9-2.3-8-5.9-8-11V5z" />
                <path d="m12 7 1.3 2.7 3 .4-2.2 2.1.6 3-2.7-1.4-2.7 1.4.6-3-2.2-2.1 3-.4z" />
            </symbol>


            <!-- Star -->

            <symbol id="icon-star" viewBox="0 0 24 24">
                <path d="m12 2 2.8 6.3 6.7.7-5 4.6 1.4 6.6-5.9-3.3-5.9 3.3 1.4-6.6-5-4.6 6.7-.7z" />
            </symbol>


            <!-- Medal -->

            <symbol id="icon-medal" viewBox="0 0 24 24">
                <path d="m7 3 5 8 5-8" />
                <circle cx="12" cy="15" r="5" />
                <path d="m12 12 1 2 2 .3-1.5 1.5.4 2.2-1.9-1-1.9 1 .4-2.2L9 14.3l2-.3z" />
            </symbol>


            <!-- Laurel -->

            <symbol id="icon-laurel" viewBox="0 0 24 24">
                <path d="M8 20c-4-3-5-8-3-13" />
                <path d="M16 20c4-3 5-8 3-13" />
                <path d="M5 9 2.5 7" />
                <path d="M5 13 2 12" />
                <path d="M7 17l-3 .5" />
                <path d="m19 9 2.5-2" />
                <path d="m19 13 3-1" />
                <path d="m17 17 3 .5" />
                <path d="M9 21h6" />
            </symbol>


            <!-- Discussion -->

            <symbol id="icon-discussion" viewBox="0 0 24 24">
                <path d="M4 4h12v9H9l-4 3v-3H4z" />
                <path d="M9 17h6l4 3v-3h1V9h-2" />
            </symbol>


            <!-- Poll -->

            <symbol id="icon-poll" viewBox="0 0 24 24">
                <path d="M4 20V10h4v10" />
                <path d="M10 20V4h4v16" />
                <path d="M16 20v-7h4v7" />
                <path d="M2 20h20" />
            </symbol>


            <!-- Calendar -->

            <symbol id="icon-calendar" viewBox="0 0 24 24">
                <rect x="3" y="5" width="18" height="16" rx="2" />
                <path d="M7 2v6" />
                <path d="M17 2v6" />
                <path d="M3 10h18" />
                <path d="m12 13 1 2 2 .3-1.5 1.5.4 2.2-1.9-1-1.9 1 .4-2.2L9 15.3l2-.3z" />
            </symbol>


            <!-- Trophy -->

            <symbol id="icon-trophy" viewBox="0 0 24 24">
                <path d="M8 4h8v4c0 4-1.8 7-4 7s-4-3-4-7z" />
                <path d="M8 6H4v2c0 3 1.5 5 4.5 5" />
                <path d="M16 6h4v2c0 3-1.5 5-4.5 5" />
                <path d="M12 15v4" />
                <path d="M8 21h8" />
            </symbol>


            <!-- Profile -->

            <symbol id="icon-profile" viewBox="0 0 24 24">
                <circle cx="12" cy="8" r="4" />
                <path d="M4 21c.7-4.7 3.4-7 8-7s7.3 2.3 8 7" />
            </symbol>


            <!-- History -->

            <symbol id="icon-history" viewBox="0 0 24 24">
                <path d="M4 5v6h6" />
                <path d="M5.5 10a8 8 0 1 1 1.2 7" />
                <path d="M12 7v5l3 2" />
            </symbol>


            <!-- Mentor -->

            <symbol id="icon-mentor" viewBox="0 0 24 24">
                <circle cx="9" cy="8" r="3" />
                <path d="M3 20c.5-4 2.5-6 6-6 2 0 3.6.7 4.6 2" />
                <path d="M15 7h6v8h-3l-3 3v-3h-1V8a1 1 0 0 1 1-1z" />
            </symbol>


            <!-- Academy -->

            <symbol id="icon-academy" viewBox="0 0 24 24">
                <path d="m3 10 9-7 9 7" />
                <path d="M5 9v12" />
                <path d="M19 9v12" />
                <path d="M9 21v-7h6v7" />
                <path d="M3 21h18" />
                <path d="M8 10h8" />
            </symbol>


        </defs>

    </svg>


    <!-- ================================================================
         HERO
    ================================================================= -->

    <section class="features-hero" aria-labelledby="features-hero-heading">

        <div class="hero-ornament hero-ornament-left" aria-hidden="true"></div>

        <div class="hero-ornament hero-ornament-right" aria-hidden="true"></div>


        <div class="section-inner features-hero-inner">

            <div class="features-hero-copy">

                <p class="academy-overline">
                    The Blackthorne Experience
                </p>


                <h1 id="features-hero-heading">
                    More than coursework. An academy life built around discovery.
                </h1>


                <p class="features-hero-intro">
                    Blackthorne brings academics, progression, Houses,
                    achievements, community, events, and student life into
                    one connected academy experience designed to grow with
                    every chapter of your journey.
                </p>


                <div class="hero-actions" aria-label="Features page actions">

                    <a href="#academic-study" class="button button-primary">
                        Explore the Experience
                    </a>


                    <a href="<?= e(REGISTER_URL); ?>" class="button button-secondary">
                        Begin Enrollment
                    </a>

                </div>


                <div class="hero-motto" aria-label="Blackthorne Academy experience">

                    <span class="ornament-line" aria-hidden="true"></span>


                    <p>

                        <span>
                            Study
                        </span>

                        <span aria-hidden="true">
                            •
                        </span>

                        <span>
                            Belong
                        </span>

                        <span aria-hidden="true">
                            •
                        </span>

                        <span>
                            Progress
                        </span>

                    </p>

                </div>

            </div>

        </div>

    </section>


    <!-- ================================================================
         INTRODUCTION
    ================================================================= -->

    <section class="features-introduction" aria-labelledby="features-introduction-heading">

        <div class="section-inner">

            <header class="features-section-intro features-section-intro-centered">

                <p class="academy-overline">
                    Inside the Academy
                </p>


                <h2 id="features-introduction-heading">
                    Every part of Blackthorne belongs to the same story.
                </h2>


                <div class="ornamental-rule" aria-hidden="true">
                    <span></span>
                    <i></i>
                    <span></span>
                </div>


                <p>
                    Classes are only one part of academy life. Your studies,
                    House, achievements, community participation, and academic
                    history are designed to become parts of one continuing
                    experience rather than a collection of disconnected
                    features.
                </p>

            </header>

        </div>

    </section>


    <!-- ================================================================
         01 — ACADEMIC STUDY
    ================================================================= -->

    <section class="features-prospectus-section features-prospectus-light" id="academic-study"
        aria-labelledby="academic-study-heading">

        <div class="section-inner features-prospectus-grid">


            <!-- Section Introduction -->

            <div class="features-prospectus-heading">

                <span class="features-section-number" aria-hidden="true">
                    01
                </span>


                <p class="academy-overline">
                    Academic Study
                </p>


                <h2 id="academic-study-heading">
                    Magical study with structure, purpose, and progression.
                </h2>


                <p>
                    Blackthorne courses are designed as complete academic
                    experiences. Students move through lessons, complete
                    coursework, demonstrate understanding, and build toward
                    larger assessments throughout the school year.
                </p>

            </div>


            <!-- Feature List -->

            <div class="features-ledger">


                <article class="feature-ledger-entry">

                    <div class="feature-icon" aria-hidden="true">
                        <svg>
                            <use href="#icon-book"></use>
                        </svg>
                    </div>


                    <div>

                        <h3>
                            Structured Magical Curriculum
                        </h3>

                        <p>
                            Study within an organized curriculum where courses
                            belong to a broader academic path rather than
                            existing as isolated lessons.
                        </p>

                    </div>

                </article>


                <article class="feature-ledger-entry">

                    <div class="feature-icon" aria-hidden="true">
                        <svg>
                            <use href="#icon-scroll"></use>
                        </svg>
                    </div>


                    <div>

                        <h3>
                            Courses &amp; Lessons
                        </h3>

                        <p>
                            Progress through individual lessons with defined
                            topics, learning goals, resources, and work that
                            builds upon what came before.
                        </p>

                    </div>

                </article>


                <article class="feature-ledger-entry">

                    <div class="feature-icon" aria-hidden="true">
                        <svg>
                            <use href="#icon-quill"></use>
                        </svg>
                    </div>


                    <div>

                        <h3>
                            Assignments &amp; Creative Practicals
                        </h3>

                        <p>
                            Apply course material through written assignments,
                            imaginative exercises, roleplay scenarios, and
                            creative practical work without requiring students
                            to appear on camera.
                        </p>

                    </div>

                </article>


                <article class="feature-ledger-entry">

                    <div class="feature-icon" aria-hidden="true">
                        <svg>
                            <use href="#icon-seal"></use>
                        </svg>
                    </div>


                    <div>

                        <h3>
                            Quizzes, Midterms &amp; Finals
                        </h3>

                        <p>
                            Check understanding throughout a course with
                            quizzes and larger academic assessments designed
                            around the material students have studied.
                        </p>

                    </div>

                </article>


            </div>

        </div>

    </section>


    <!-- ================================================================
         02 — ACADEMIC JOURNEY
    ================================================================= -->

    <section class="features-prospectus-section features-prospectus-dark" aria-labelledby="academic-journey-heading">

        <div class="section-inner features-prospectus-grid features-prospectus-reversed">


            <!-- Feature List -->

            <div class="features-ledger">


                <article class="feature-ledger-entry">

                    <div class="feature-icon" aria-hidden="true">
                        <svg>
                            <use href="#icon-compass"></use>
                        </svg>
                    </div>


                    <div>

                        <h3>
                            Required Academy Orientation
                        </h3>

                        <p>
                            Every first-year student begins with orientation,
                            a self-paced introduction to Blackthorne,
                            academic expectations, academy systems, and
                            student life.
                        </p>

                    </div>

                </article>


                <article class="feature-ledger-entry">

                    <div class="feature-icon" aria-hidden="true">
                        <svg>
                            <use href="#icon-hourglass"></use>
                        </svg>
                    </div>


                    <div>

                        <h3>
                            School Years
                        </h3>

                        <p>
                            Academic life unfolds across school years so a
                            student's time at Blackthorne feels like an
                            ongoing education rather than a collection of
                            unrelated course completions.
                        </p>

                    </div>

                </article>


                <article class="feature-ledger-entry">

                    <div class="feature-icon" aria-hidden="true">
                        <svg>
                            <use href="#icon-progress"></use>
                        </svg>
                    </div>


                    <div>

                        <h3>
                            Academic Progression
                        </h3>

                        <p>
                            Course requirements, prerequisites, completion,
                            and advancement work together to create a clear
                            path through the academy.
                        </p>

                    </div>

                </article>


                <article class="feature-ledger-entry">

                    <div class="feature-icon" aria-hidden="true">
                        <svg>
                            <use href="#icon-archive"></use>
                        </svg>
                    </div>


                    <div>

                        <h3>
                            A Lasting Academic Record
                        </h3>

                        <p>
                            Build a history of completed studies, academic
                            milestones, achievements, and progression that
                            grows alongside your time at Blackthorne.
                        </p>

                    </div>

                </article>


            </div>


            <!-- Section Introduction -->

            <div class="features-prospectus-heading">

                <span class="features-section-number" aria-hidden="true">
                    02
                </span>


                <p class="academy-overline">
                    Your Academic Journey
                </p>


                <h2 id="academic-journey-heading">
                    Your first lesson is only the beginning.
                </h2>


                <p>
                    Blackthorne is designed around the idea that students
                    should be able to look back and see an actual academy
                    history: where they began, what they studied, what they
                    accomplished, and how far they have progressed.
                </p>

            </div>

        </div>

    </section>


    <!-- ================================================================
         03 — HOUSES & ACHIEVEMENT
    ================================================================= -->

    <section class="features-houses-section" aria-labelledby="houses-achievement-heading">

        <div class="section-inner">


            <header class="features-section-intro features-section-intro-centered">

                <span class="features-section-number" aria-hidden="true">
                    03
                </span>


                <p class="academy-overline">
                    Houses &amp; Achievement
                </p>


                <h2 id="houses-achievement-heading">
                    Belong to something. Work toward something.
                </h2>


                <p>
                    Academy life extends beyond individual grades. Houses,
                    shared points, achievements, and milestones create
                    traditions and accomplishments that students can share
                    with the wider Blackthorne community.
                </p>

            </header>


            <div class="features-emblem-layout">


                <article class="feature-emblem-entry">

                    <div class="feature-icon feature-icon-large" aria-hidden="true">
                        <svg>
                            <use href="#icon-shield"></use>
                        </svg>
                    </div>


                    <h3>
                        Academy Houses
                    </h3>


                    <p>
                        Become part of a House and carry an identity within
                        the wider academy community.
                    </p>

                </article>


                <article class="feature-emblem-entry">

                    <div class="feature-icon feature-icon-large" aria-hidden="true">
                        <svg>
                            <use href="#icon-star"></use>
                        </svg>
                    </div>


                    <h3>
                        House Points
                    </h3>


                    <p>
                        Individual participation can contribute to shared
                        House standing and friendly academy competition.
                    </p>

                </article>


                <div class="features-central-emblem" aria-hidden="true">

                    <span class="features-emblem-ring">

                        <img src="<?= e(asset('images/logo/logo_3.png')); ?>" alt="" width="180" height="180"
                            loading="lazy" decoding="async">

                    </span>

                </div>


                <article class="feature-emblem-entry">

                    <div class="feature-icon feature-icon-large" aria-hidden="true">
                        <svg>
                            <use href="#icon-medal"></use>
                        </svg>
                    </div>


                    <h3>
                        Achievements
                    </h3>


                    <p>
                        Earn recognition for academic accomplishments,
                        participation, exploration, and special academy
                        milestones.
                    </p>

                </article>


                <article class="feature-emblem-entry">

                    <div class="feature-icon feature-icon-large" aria-hidden="true">
                        <svg>
                            <use href="#icon-laurel"></use>
                        </svg>
                    </div>


                    <h3>
                        Milestones &amp; Recognition
                    </h3>


                    <p>
                        Mark meaningful moments throughout your academy
                        journey and build a visible history of what you have
                        accomplished.
                    </p>

                </article>


            </div>

        </div>

    </section>


    <!-- ================================================================
         04 — COMMUNITY
    ================================================================= -->

    <section class="features-community-section" aria-labelledby="community-heading">

        <div class="section-inner">


            <div class="features-community-heading">

                <span class="features-section-number" aria-hidden="true">
                    04
                </span>


                <p class="academy-overline">
                    Beyond the Classroom
                </p>


                <h2 id="community-heading">
                    An academy should have somewhere to gather.
                </h2>


                <p>
                    Blackthorne's community spaces are designed to give
                    students more to do than submit coursework. Discuss
                    ideas, participate in academy decisions, attend events,
                    enter contests, and interact with instructors and staff.
                </p>

            </div>


            <div class="features-community-list">


                <article class="feature-community-entry">

                    <div class="feature-icon" aria-hidden="true">
                        <svg>
                            <use href="#icon-discussion"></use>
                        </svg>
                    </div>


                    <div>

                        <h3>
                            Forums &amp; Discussions
                        </h3>

                        <p>
                            Join conversations throughout the academy,
                            participate in course and community discussions,
                            and stay connected beyond individual lessons.
                        </p>

                    </div>

                </article>


                <article class="feature-community-entry">

                    <div class="feature-icon" aria-hidden="true">
                        <svg>
                            <use href="#icon-poll"></use>
                        </svg>
                    </div>


                    <div>

                        <h3>
                            Community &amp; Academy Polls
                        </h3>

                        <p>
                            Participate in discussion polls as well as larger
                            academy-wide questions that can appear across
                            shared Blackthorne spaces.
                        </p>

                    </div>

                </article>


                <article class="feature-community-entry">

                    <div class="feature-icon" aria-hidden="true">
                        <svg>
                            <use href="#icon-calendar"></use>
                        </svg>
                    </div>


                    <div>

                        <h3>
                            Academy Events
                        </h3>

                        <p>
                            Take part in scheduled academy activities,
                            seasonal experiences, community gatherings, and
                            special events beyond normal coursework.
                        </p>

                    </div>

                </article>


                <article class="feature-community-entry">

                    <div class="feature-icon" aria-hidden="true">
                        <svg>
                            <use href="#icon-trophy"></use>
                        </svg>
                    </div>


                    <div>

                        <h3>
                            Contests &amp; Challenges
                        </h3>

                        <p>
                            Participate in optional creative and academic
                            challenges that offer another way to engage with
                            the academy community.
                        </p>

                    </div>

                </article>


            </div>

        </div>

    </section>


    <!-- ================================================================
         05 — YOUR PLACE AT BLACKTHORNE
    ================================================================= -->

    <section class="features-prospectus-section features-prospectus-final" aria-labelledby="student-life-heading">

        <div class="section-inner features-prospectus-grid">


            <!-- Section Introduction -->

            <div class="features-prospectus-heading">

                <span class="features-section-number" aria-hidden="true">
                    05
                </span>


                <p class="academy-overline">
                    Your Place at Blackthorne
                </p>


                <h2 id="student-life-heading">
                    The academy remembers the story you build here.
                </h2>


                <p>
                    Your Blackthorne identity is intended to become more than
                    an account name. Profiles, academic history,
                    accomplishments, and participation create an evolving
                    record of your place within the academy.
                </p>

            </div>


            <!-- Feature List -->

            <div class="features-ledger">


                <article class="feature-ledger-entry">

                    <div class="feature-icon" aria-hidden="true">
                        <svg>
                            <use href="#icon-profile"></use>
                        </svg>
                    </div>


                    <div>

                        <h3>
                            Student Profiles
                        </h3>

                        <p>
                            Maintain an academy identity that can grow to
                            reflect your studies, House, achievements, and
                            participation.
                        </p>

                    </div>

                </article>


                <article class="feature-ledger-entry">

                    <div class="feature-icon" aria-hidden="true">
                        <svg>
                            <use href="#icon-history"></use>
                        </svg>
                    </div>


                    <div>

                        <h3>
                            Personal Academy History
                        </h3>

                        <p>
                            Look back across completed courses, advancement,
                            earned achievements, and other milestones from
                            your time at Blackthorne.
                        </p>

                    </div>

                </article>


                <article class="feature-ledger-entry">

                    <div class="feature-icon" aria-hidden="true">
                        <svg>
                            <use href="#icon-mentor"></use>
                        </svg>
                    </div>


                    <div>

                        <h3>
                            Instructor Interaction
                        </h3>

                        <p>
                            Learn within an academy where instructors can
                            guide coursework, respond to students, and remain
                            part of the educational experience.
                        </p>

                    </div>

                </article>


                <article class="feature-ledger-entry">

                    <div class="feature-icon" aria-hidden="true">
                        <svg>
                            <use href="#icon-academy"></use>
                        </svg>
                    </div>


                    <div>

                        <h3>
                            A Living Academy
                        </h3>

                        <p>
                            Courses, Houses, achievements, community,
                            progression, and academy traditions are designed
                            to work together as parts of the same world.
                        </p>

                    </div>

                </article>


            </div>

        </div>

    </section>


    <!-- ================================================================
         CLOSING STATEMENT
    ================================================================= -->

    <section class="features-closing" aria-labelledby="features-closing-heading">

        <div class="section-inner">

            <div class="features-closing-frame">

                <p class="academy-overline">
                    Built for Immersion
                </p>


                <h2 id="features-closing-heading">
                    Not a generic classroom with fantasy names attached.
                </h2>


                <div class="ornamental-rule" aria-hidden="true">
                    <span></span>
                    <i></i>
                    <span></span>
                </div>


                <p>
                    Blackthorne Academy is being built around the idea that
                    an online school can feel like an actual place with its
                    own academics, traditions, communities, milestones, and
                    history. Every system is intended to contribute to that
                    larger experience.
                </p>

            </div>

        </div>

    </section>


    <!-- ================================================================
         ADMISSIONS
    ================================================================= -->

    <section class="admissions-notice features-admissions" aria-labelledby="features-admissions-heading">

        <div class="section-inner">

            <div class="admissions-frame">


                <div class="admissions-seal" aria-hidden="true">
                    B
                </div>


                <div class="admissions-copy">

                    <p class="academy-overline">
                        Admissions
                    </p>


                    <h2 id="features-admissions-heading">
                        Your place in the academy begins at the gates.
                    </h2>


                    <p>
                        Create your Blackthorne account, complete academy
                        orientation, and begin building a story of study,
                        discovery, community, and achievement.
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


<?php

require INCLUDES_PATH . '/footer.php';
