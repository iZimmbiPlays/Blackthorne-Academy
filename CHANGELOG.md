# Changelog

All notable development milestones for **Blackthorne Academy** are
documented here.

Blackthorne Academy was already under active development before Git
source control was established on September 28, 2026. This changelog
therefore reconstructs the pre-repository development history from the
project's development record and verifies the current state against the
source snapshot prepared for GitHub.

This is intentionally a **completed-work changelog, not a roadmap**. A
source file, database hook, navigation item, or partially built
interface is not treated as a completed feature by itself. Features that
remain visibly unfinished are listed separately under **Still in
Development** rather than being represented as complete.

------------------------------------------------------------------------

------------------------------------------------------------------------

## September 29, 2026

### Repository & Code Quality Preparation

- Completed a final pre-publication repository audit for the Blackthorne Academy portfolio repository.
- Verified that private database and SMTP credentials remain excluded from source control.
- Verified that runtime uploads, Composer dependencies, database dumps, backups, logs, environment files, and other development artifacts are excluded from the public repository.
- Verified tracked PHP source files for syntax errors prior to publication.
- Completed a full audit and cleanup of the primary application stylesheet.
- Removed redundant CSS rules and declarations while preserving existing application behavior.
- Reduced unnecessary `!important` declarations from 627 to 507 through multiple conservative cleanup passes.
- Consolidated safely mergeable responsive media queries without changing cascade order.
- Moved remaining shared header presentation styles from `header.php` into the external stylesheet.
- Standardized fixed-header spacing and responsive desktop/mobile header-height handling.
- Preserved full-viewport public hero layouts across the Home, Features, About, Contact, and Login pages.
- Verified accessibility-related styling for skip links, keyboard focus visibility, screen-reader-only content, and reduced-motion preferences.
- Completed final stylesheet validation with zero CSS parse errors, malformed declarations, empty rulesets, or merge-conflict artifacts.
- Finalized the primary stylesheet for the public GitHub portfolio repository.

## September 28, 2026

### Repository, Dependency & Security Preparation

-   Prepared a dedicated sanitized Git working copy of Blackthorne
    Academy for portfolio publication.
-   Moved private database and SMTP credentials out of tracked
    configuration files and into `config/secrets.php`.
-   Added `config/secrets.example.php` as a safe configuration template.
-   Added Git ignore rules for private secrets, runtime uploads,
    database dumps, temporary files, IDE files, and generated Composer
    dependencies.
-   Added an empty `uploads/.gitkeep` so the runtime upload directory
    can exist in source control without publishing user-uploaded
    content.
-   Converted PHPMailer loading from manually bundled source files to
    Composer autoloading.
-   Added `composer.json` and `composer.lock`.
-   Established PHP 8.3+ and PHPMailer as Composer-managed project
    requirements.
-   Verified the project against common credential, password, token,
    email-address, and private-key patterns before publication.
-   Added portfolio-focused project documentation.
-   Added an all-rights-reserved proprietary usage notice for the public
    portfolio repository.
-   Created the initial Git repository and baseline source-control
    commit.

------------------------------------------------------------------------

## September 21--22, 2026

### Points System

-   Built the staff-facing House Point management workflow.
-   Added permission-controlled awarding and deduction of House Points.
-   Added point-change reasons and history records.
-   Added member-specific point adjustment controls to profiles for
    authorized staff.
-   Kept Homework Points separate from manual profile point editing.
-   Added notifications for point changes.
-   Built the point-history interface.
-   Added school-year-aware point history and filtering.
-   Updated staff point navigation and shared sidebar integration.
-   Established the distinction between House Points and Homework
    Points.
-   Integrated point information into member/profile-facing areas where
    applicable.

### Achievements

-   Built the administrative achievement-management interface.
-   Added support for achievement names, slugs, descriptions, images,
    ordering, and award criteria.
-   Added reusable achievement helper logic.
-   Added automatic achievement-award infrastructure for supported
    activity triggers.
-   Added achievement notifications.
-   Added achievement displays to member profiles.
-   Added interactive achievement details so selecting a badge can
    display a larger badge and styled achievement information.
-   Added initial activity-based achievement support, including
    first-post, first-topic, first-like, and Sorting Ceremony-related
    milestones.
-   Designed the achievement system so additional rewards can be added
    through data rather than requiring every achievement to be hardcoded
    into the interface.

### Academic Progression Foundations

-   Added school-year progression-rule administration.
-   Established Homework Points as the academic point type intended to
    contribute toward yearly progression thresholds.
-   Kept House competition points and academic progression points
    logically separate.

### Still in Development from This Area

The source snapshot still exposes unfinished navigation or workflows for
the House Cup interface and direct staff-side achievement awarding. The
visual House Cup/hourglass leaderboard concept is therefore not recorded
as a completed feature.

------------------------------------------------------------------------

## September 20, 2026

### Orientation Course & Lesson Content

-   Established Orientation as a course rather than a standalone
    onboarding page.
-   Defined Orientation as the entry course for new students.
-   Built the first Orientation lesson around the academy's existing
    user-facing systems.
-   Added coverage of the Sorting Ceremony, Houses, Common Rooms,
    forums, profiles, profile editing, friends, block lists,
    notifications, and the Living Ledger.
-   Added the four individual Houses, their identities, crests, mascots,
    and introductions to Orientation content.
-   Added lesson descriptions and lesson imagery support.
-   Established the Blackthorne lesson-writing standard of fuller
    paragraphs, limited single-sentence paragraphs, and avoiding em
    dashes unless necessary.

### Course Presentation

-   Continued refinement of course cards and course dashboard
    presentation.
-   Added course thumbnails and short descriptions to course
    presentation.
-   Refined course information placement and course dashboard sections.
-   Added dedicated course-forum access where a course has an associated
    forum.

------------------------------------------------------------------------

## September 18--19, 2026

### Course Management Foundation

-   Built the custom course-management foundation.
-   Added course creation and editing interfaces.
-   Added course title, thumbnail/image, short description, long
    description, course type, category, tags, dates, and publication
    status fields.
-   Added school-year management.
-   Added grade-level management, including support for Grade 0.
-   Added instructor and teaching-assistant assignment structures.
-   Added course prerequisite selection at the course level.
-   Added self-paced and drip course types.
-   Added draft, scheduled, and published course states.
-   Added course offering creation and editing.
-   Added registration-group management.
-   Added course-specific forum selection.
-   Added shared staff course navigation used across course
    administration pages.
-   Added public/member course listing and course detail interfaces.
-   Added student course-registration workflow foundations.

### Lesson Authoring

-   Built lesson creation and editing within course administration.
-   Added rich lesson content editing.
-   Added headings and inline text formatting.
-   Added image insertion and upload support.
-   Added immediate image preview behavior in the lesson editor.
-   Added percentage-based image sizing.
-   Added image alignment controls.
-   Added lesson images/thumbnails.
-   Added lesson descriptions and ordering.
-   Added student-facing lesson pages.
-   Added course/lesson progress helper infrastructure.
-   Added lesson deletion controls.

### Assignment Foundation

-   Added assignment administration pages and course-assignment support
    files.
-   Added assignment editing and extra-credit administration interfaces.
-   Added instructor-facing assignment navigation.
-   Added student-facing assignment pages and submission helper
    infrastructure.

### Still in Development from This Area

The current source explicitly marks several course operations as
**Coming soon**, including the dedicated Assessments, Enrollments,
Grading, Course Staff, and prerequisite-management navigation workflows.
Their supporting structures may exist, but these are not represented
here as completed end-to-end systems.

------------------------------------------------------------------------

## September 17, 2026

### Forum Hierarchy & Administration

-   Expanded the custom forum architecture to support Categories →
    Forums → nested Subforums.
-   Corrected parent/child forum handling so subforums are associated
    with the intended parent forum.
-   Corrected new-thread routing so threads are created in the forum
    selected by the user.
-   Added forum descriptions throughout the hierarchy.
-   Added forum labels with configurable names and colors.
-   Added private/restricted forum controls.
-   Added role-based and group-based forum access.
-   Added board-specific moderator assignments.
-   Added administrative create, edit, and delete workflows for forum
    structures.
-   Added hierarchical forum selection behavior to administrative
    workflows.
-   Added announcement-forum configuration used by dashboard
    announcement displays.
-   Added House News/announcement configuration for house-specific
    dashboard content.

### Forum Threads & Posts

-   Built thread creation and reply workflows.
-   Added rich forum-editor formatting including bold, italic,
    underline, strikethrough, code, and quote formatting.
-   Added forum image upload/link support.
-   Added temporary upload handling so editor images can be finalized
    when content is saved.
-   Added quoting.
-   Added post likes and unlikes.
-   Added post editing.
-   Added author and staff deletion controls according to permissions.
-   Added post reporting.
-   Added thread locking/unlocking.
-   Added sticky-thread controls.
-   Added announcement-thread controls.
-   Added thread deletion moderation.
-   Added forum-related notification behavior.

### Custom Forum Image Maps

-   Built Blackthorne Academy's custom forum image-map system.
-   Added the ability for authorized staff to enable an image map
    instead of a normal sub-board list.
-   Added image upload support for forum navigation maps.
-   Built an integrated visual image-map generator inside forum
    administration.
-   Added interactive drawing tools for defining clickable navigation
    regions, including polygon regions.
-   Added editable navigation-area data and destination mapping.
-   Added saved/shared image maps that can be reused across multiple
    boards.
-   Added image-map names and alternative text.
-   Added image-map loading, saving, updating, and file-management
    helper logic.
-   Integrated image maps with the existing forum hierarchy and
    permission-controlled administration.
-   Enabled academy environments to act as visual navigation interfaces
    in which architectural areas can link to their corresponding forums.

### Announcements

-   Added dashboard announcement/event retrieval from forums configured
    as announcement sources.
-   Integrated forum-generated announcements with member-facing
    dashboard/sidebar content.

------------------------------------------------------------------------

## September 16, 2026

### Friends & Blocking

-   Built member friendship workflows.
-   Added friend requests and friend-action handling.
-   Added friends-list presentation.
-   Added blocked-user management.
-   Added reusable friend helper functions.
-   Integrated social relationships with member/profile functionality.

### Forum & Responsive UI Refinement

-   Continued forum-page layout and navigation refinement.
-   Improved mobile spacing for hero/cover areas and controls.
-   Continued shared responsive styling work across member-facing pages.

------------------------------------------------------------------------

## Earlier Development --- Date Not Reliably Reconstructed

The following systems were already present before the dated development
record used for this changelog becomes sufficiently specific. They are
included as baseline functionality rather than being assigned invented
completion dates.

### Authentication & Accounts

-   User registration.
-   User login and logout.
-   Password hashing and verification.
-   Email verification.
-   Forgot-password and password-reset workflows.
-   Verification/reset token handling.
-   Session-based authentication.
-   Remember-me authentication support.
-   Authentication helper infrastructure.
-   SMTP-based application mail handling.

### Profiles

-   Member profile pages.
-   Profile editing.
-   Avatar support.
-   Cover-image support.
-   Profile biography/content editing.
-   Social-link fields.
-   Profile display preferences.
-   Online/offline presence presentation.
-   Member activity/profile information.
-   Profile-integrated House, points, and achievement information.

### Houses & Sorting Ceremony

-   Created the four Blackthorne Academy Houses: Nightbriar, Grimwood,
    Emberveil, and Silverthorne.
-   Added House records, descriptions, mottos, colors, crests, mascot
    information, and related administrative fields.
-   Built House administration.
-   Built House-member administration.
-   Built the student Sorting Ceremony.
-   Added configurable sorting questions/answers and House-assignment
    logic.
-   Added House Common Room functionality.
-   Integrated House membership with forum/community access and member
    presentation.

### Member Dashboard & Academy Navigation

-   Built logged-in member dashboard variants.
-   Added role-aware dashboard presentation.
-   Added member sidebar components.
-   Added online-user information.
-   Added dashboard announcements/events.
-   Added forum quick-access navigation.
-   Added member quick links.
-   Added House and point information to dashboard/member areas.
-   Built shared navigation and footer components.
-   Added public-facing Home, Features, About, and Contact pages.

### Notifications

-   Built the internal notification system.
-   Added notification listing and individual-notification handling.
-   Added notification counts and navigation integration.
-   Connected notifications to supported platform events such as
    achievements and point changes.

### Bookmarks & Living Ledger

-   Added member bookmark functionality.
-   Built the Living Ledger as a member-facing system separate from
    House/Homework Points.

### Roles, Permissions & Staff Administration

-   Built custom roles administration.
-   Added capability-based authorization helpers.
-   Added Super Administrator override behavior.
-   Added administrative and staff dashboard structures.
-   Added user administration.
-   Added House administration.
-   Added moderation and sanctions administration.
-   Added reports and moderation-history interfaces.
-   Added permission-aware controls so administrative actions can be
    limited to authorized staff.
-   Added support for board-specific moderation responsibilities rather
    than requiring global moderation access.

### Moderation

-   Added forum/post reporting workflows.
-   Added staff report handling.
-   Added moderation actions and moderation history.
-   Added sanctions administration.
-   Added permission-scoped moderation controls.

### Security & Application Infrastructure

-   Implemented PDO-based database access.
-   Used prepared statements throughout database-backed workflows.
-   Added shared application bootstrap/configuration.
-   Added reusable helper/service files for major systems.
-   Added output-escaping and validation patterns across application
    interfaces.
-   Added protected configuration handling.
-   Added responsive shared frontend assets and custom JavaScript
    behavior.

------------------------------------------------------------------------

## Current Development State at Repository Baseline

As of the initial Git repository baseline, Blackthorne Academy is a
functioning custom PHP/MySQL application with substantial community,
forum, profile, House, course-authoring, points, achievement,
permission, moderation, and administrative functionality.

The project is **not feature-complete**. The source snapshot
intentionally retains unfinished and future-facing areas, and those have
not been promoted to completed changelog items merely because related
files, database structures, or navigation entries exist.

Notable areas still under active development include:

-   Full assessment workflows
-   Dedicated grading workflows
-   Complete enrollment-management tooling
-   Dedicated course-staff management workflow
-   Some prerequisite-management tooling
-   House Cup/leaderboard presentation
-   Additional achievement-award administration
-   Further academic progression automation
-   Continued course, assignment, and registration expansion
-   Additional dashboard and staff-tool refinement

Future changelog entries should document these systems only as their
working implementations are completed.

------------------------------------------------------------------------

## Repository Baseline

The first Git source-control baseline was created on **September 28,
2026**. Development predating that commit is reconstructed above so the
repository's public history reflects the substantial work completed
before Git tracking began.
