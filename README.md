# Blackthorne Academy

**A custom-built virtual academy, learning management, and community platform developed from the ground up with PHP and MySQL.**

Blackthorne Academy combines academic course management, community forums, student progression, houses, achievements, moderation, profiles, notifications, and gamification within a single custom web application.

Rather than adapting an existing LMS or community platform, Blackthorne Academy is being designed and developed as a custom application with its own database architecture, permission system, administrative tools, academic systems, and user experience.

> **Status:** Active Development

---

## About the Project

Blackthorne Academy began as an idea for an immersive online academy and evolved into a full custom web application.

The platform is designed to combine structured online learning with the social and community features of a traditional forum. Students can participate in courses, complete lessons and assessments, interact through discussion boards, join houses, earn achievements, accumulate points, customize profiles, and progress through academic years.

Staff members receive their own administrative and moderation tools with access controlled through roles, permissions, and context-specific privileges.

The project is developed without WordPress, Moodle, or another pre-built CMS/LMS framework. The application architecture, database systems, interfaces, administrative tools, and platform features are being built specifically for Blackthorne Academy.

---

## Key Features

### Course & Academic System

Blackthorne Academy includes a custom course-management system designed around an academic-year structure.

Current and planned functionality includes:

- Course creation and management
- Course thumbnails and descriptions
- School-year assignments
- Grade levels
- Course prerequisites
- Self-paced and drip-based courses
- Course start and end dates
- Course categories and tags
- Instructor and teaching-assistant assignments
- Draft, scheduled, and published course states
- Course offerings
- Registration groups
- Student course registration
- Dedicated course forums
- Structured lessons
- Academic progression requirements
- Orientation-based onboarding

The Orientation course serves as the entry point for new students and introduces the academy's systems before normal course registration becomes available.

---

### Lesson System

Courses support structured lesson content with a custom authoring workflow.

Lesson functionality includes:

- Rich-text lesson content
- Headings and text formatting
- Embedded images
- Image alignment
- Percentage-based image sizing
- Lesson thumbnails
- Lesson descriptions
- Course navigation
- Lesson ordering
- Student progress tracking

The lesson system is being expanded alongside the assessment and academic progression systems.

---

### Forums & Community

Blackthorne includes a custom discussion-board system built specifically for the academy rather than relying on third-party forum software.

The forum architecture supports multiple levels of organization and integrates directly with Blackthorne's roles, permissions, houses, courses, announcements, moderation tools, and navigation systems.

Forum functionality includes:

- Categories
- Main boards
- Nested subforums
- Forum descriptions
- Forum labels
- Public and restricted boards
- Group-based access
- Role-based access
- Board-specific moderators
- Announcement forums
- House-specific forums
- Course forums
- Thread creation
- Thread replies
- Quoting
- Post editing
- Post deletion
- Likes
- Reports
- Sticky threads
- Locked threads
- Announcements
- Moderation controls
- Forum notifications

#### Custom Image Maps

Authorized staff creating or managing forums can also build visual navigation using Blackthorne's custom image-map system.

Staff can upload an image for a forum area and use the built-in **Image Map Generator** to define interactive regions directly on that image. Those regions can then act as clickable navigation points to other forums and areas of the academy.

This allows portions of Blackthorne to use immersive visual environments instead of relying exclusively on traditional text-based forum lists.

The custom image-map tools provide an administrative workflow for:

- Uploading navigation images
- Creating interactive regions on an image
- Positioning clickable areas visually
- Connecting regions to forum destinations
- Managing image-map navigation through the forum administration system
- Building interconnected visual environments while retaining the underlying forum hierarchy

For example, an academy location can be represented by a full environmental scene in which individual doors, corridors, staircases, or rooms become interactive links to their corresponding forums.

This system was built specifically for Blackthorne Academy and allows the community's forum structure to function simultaneously as both a traditional discussion board and an explorable visual academy environment.

Forum permissions and image-map management are integrated with the larger Blackthorne role and capability system, ensuring that creation and administrative controls are only available to authorized staff.
---

### Houses & Sorting

Students can participate in Blackthorne's four-house system:

- **Nightbriar**
- **Grimwood**
- **Emberveil**
- **Silverthorne**

The platform includes a Sorting Ceremony that assigns students to a house based on their responses.

Each house has its own identity, including its crest, mascot, colors, introduction, community spaces, and house-specific forums.

House membership integrates with the academy's points, achievements, permissions, and community systems.

---

### Points & Progression

Blackthorne uses multiple point systems for different purposes.

**House Points** are awarded by authorized staff for participation, events, contests, accomplishments, and other academy activities.

**Homework Points (HW Points)** are earned through academic work such as assignments, quizzes, exams, and other assessments.

The system includes or is being developed to support:

- House Points
- Homework Points
- User point histories
- Staff point adjustments
- Permission-controlled point management
- Point-change notifications
- Academic progression thresholds
- School-year progression
- House leaderboards
- Annual House Point resets
- House competition tracking

House leaderboards are designed around custom visual hourglasses that represent each house's relative standing throughout the school year.

---

### Achievements

Students can earn achievements dynamically as they participate in the academy.

The achievement system supports:

- Custom achievement creation
- Achievement artwork
- Automatic achievement awards
- Manually managed achievements
- Achievement notifications
- Profile achievement displays
- Interactive achievement details
- Dynamic future rewards

Examples include achievements for:

- Completing the Sorting Ceremony
- Creating a first forum post
- Creating a first topic
- Receiving a first like
- Completing Orientation
- Reaching future participation milestones

The system is designed so additional achievements can be added without hardcoding each reward into the interface.

---

### Profiles & Social Features

Blackthorne includes custom member profiles and community tools.

Features include:

- User profiles
- Profile editing
- Avatars
- Cover images
- Online/offline status
- Display preferences
- Friends
- Block lists
- Achievements
- House information
- Point information
- Activity information
- Notifications

Profile functionality is integrated with forum participation, houses, achievements, permissions, and academic systems.

---

### Roles & Permissions

Blackthorne uses capability-based permissions rather than relying solely on hardcoded role checks.

Staff roles include structures such as:

- Super Administrator
- Administrator
- Global Moderator
- Board Moderator
- House Staff

Permissions can be assigned according to responsibilities, while certain privileges can also be scoped to individual forums or areas of the application.

The Super Administrator retains system-wide access while more limited staff roles can be granted only the capabilities required for their responsibilities.

---

### Administration

The custom staff and administrative interfaces provide centralized management for the academy.

Administrative functionality includes or is being developed for:

- Users
- Roles
- Permissions
- Forums
- Categories
- Subforums
- Moderators
- Courses
- Course offerings
- Lessons
- School years
- Grade levels
- Points
- Achievements
- Sanctions
- Registration
- Academic progression
- Forum image maps
- Visual navigation regions

Administrative interfaces are permission-aware so controls are available only to staff members authorized to use them.

---

### Notifications

Blackthorne includes an internal notification system for user and staff activity.

Notifications are used for events such as:

- Achievement awards
- Point changes
- Forum activity
- Account activity
- Other platform events

The interface includes notification counts and quick-access notification displays.

---

### Security & Account Management

Account and application security features include:

- PDO database access
- Prepared statements
- Password hashing and verification
- Email verification
- Secure verification tokens
- Password-reset workflows
- Session management
- Remember-me authentication
- Role and capability authorization
- Restricted administrative actions
- Development/production error handling
- HTML output escaping
- Protected application secrets
- Authenticated SMTP email

Sensitive installation credentials are deliberately excluded from this repository.

---

## Technology Stack

### Backend

- PHP 8.3+
- MySQL
- PDO
- PHPMailer
- Composer

### Frontend

- HTML5
- CSS3
- JavaScript
- Responsive layouts
- Custom SVG/interface assets

### Development & Infrastructure

- Git
- GitHub
- Composer
- cPanel
- Shared Linux web hosting

The production application is designed to operate within a traditional PHP/MySQL hosting environment without requiring a heavyweight application framework.

---

## Project Architecture

Blackthorne Academy follows a custom PHP application structure with reusable configuration, includes, views, administrative interfaces, and public-facing application pages.

```text
blackthorne-academy/
├── admin/          # Administrative interfaces
├── assets/         # Stylesheets, JavaScript, images, and static assets
├── config/         # Application and environment configuration
├── includes/       # Shared application logic and services
├── instructor/     # Instructor-facing functionality
├── uploads/        # Runtime upload storage
├── vendor/         # Composer dependencies (not committed)
├── views/          # Shared presentation components
├── composer.json
├── composer.lock
├── LICENSE
└── README.md
```

Runtime-generated content, user uploads, third-party dependency files, and private environment credentials are excluded from source control.

---

## Development Environment

### Requirements

A development environment should provide:

- PHP 8.3 or newer
- MySQL
- Composer
- PHP PDO MySQL extension
- PHP ZIP extension
- A web server capable of serving PHP

### 1. Clone the Repository

Clone the repository and enter the project directory.

```bash
git clone <repository-url>
cd blackthorne-academy
```

### 2. Install PHP Dependencies

Run:

```bash
composer install
```

Composer will install the required dependencies, including PHPMailer, and generate the application autoloader.

### 3. Configure Application Secrets

Copy:

```text
config/secrets.example.php
```

to:

```text
config/secrets.php
```

Then configure the local database and SMTP credentials.

`config/secrets.php` is intentionally excluded from Git and must never be committed.

### 4. Database

Blackthorne Academy requires a MySQL database.

The production database, its data, and private database configuration are not included in this repository. This repository is provided for portfolio, demonstration, and code-review purposes rather than as a distributable application package.

### 5. Configure the Application URL

The application's base URL and environment settings are located in:

```text
config/config.php
```

Adjust these values as necessary for the local environment.

---

## Repository Security

Sensitive production information is intentionally excluded from this repository.

The following are not committed:

- Database passwords
- Database usernames
- SMTP passwords
- Private SMTP configuration
- Environment-specific secrets
- User-uploaded files
- Assignment submissions
- User avatars
- Forum attachments
- Runtime/generated files
- Composer's generated `vendor/` directory

A sanitized configuration template is provided at:

```text
config/secrets.example.php
```

The real configuration is stored locally in:

```text
config/secrets.php
```

and excluded through `.gitignore`.

---

## Development Philosophy

Blackthorne Academy is being developed incrementally, with each major system integrated into the larger application rather than implemented as an isolated demonstration.

The project emphasizes:

- Reusable application components
- Capability-based authorization
- Consistent administrative interfaces
- Database-driven configuration
- Separation of private environment configuration from source code
- Responsive design
- Accessible, readable interfaces
- Maintainable PHP and SQL architecture
- Integration between academic and community systems
- Custom interactive tools instead of relying on third-party plugins where project-specific functionality is required

Features are developed and tested as part of the working academy rather than as standalone prototypes.

---

## Project Status

**Blackthorne Academy is currently under active development.**

Major systems are functional, while additional academic, administrative, assessment, progression, and community functionality continues to be developed.

Because this repository represents an active project, database structures, interfaces, and internal architecture may continue to evolve.

---

## Screenshots

Screenshots and demonstrations of Blackthorne Academy will be added as development continues.

Planned examples include:

- Academy homepage
- Member dashboard
- Forums
- House areas
- Student profiles
- Course dashboard
- Lesson interface
- Staff dashboard
- Points management
- Achievement system
- Administrative tools

---

## Author

**Amanda Zimmer | AKA Teg/iZimmbiPlays**

Web Designer & Developer  
ByZimm

Portfolio: https://byzimm.com  
GitHub: https://github.com/iZimmbiPlays  
LinkedIn: https://www.linkedin.com/in/amanda-zimmer24/

---

## License & Usage

**Copyright © 2026 Amanda Zimmer. All rights reserved.**

Blackthorne Academy is proprietary software.

This repository is publicly available solely for portfolio, demonstration, and code-review purposes. No permission is granted to copy, reproduce, modify, adapt, distribute, publish, sublicense, sell, use, or create derivative works from this project without prior written permission from the copyright holder.

Viewing or accessing this repository does not grant a license to the source code, design, content, branding, artwork, or other materials contained within the project.

See [LICENSE](LICENSE) for additional information.