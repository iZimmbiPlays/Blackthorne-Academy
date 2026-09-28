<?php

$crestReference =
    house_safe_crest_reference(
        $houseForm['crest_image']
        ?? null
    );

$crestWebp =
    house_crest_webp_reference(
        $crestReference
    );

$heroReference =
    house_safe_hero_reference(
        $houseForm['hero_image']
        ?? null
    );

$heroWebp =
    house_hero_webp_reference(
        $heroReference
    );

$mascotReference =
    house_safe_mascot_reference(
        $houseForm['mascot_image']
        ?? null
    );

$mascotWebp =
    house_mascot_webp_reference(
        $mascotReference
    );

$colorFields = [
    'display_color' => [
        'label' => 'Member Name Color',
        'help' => 'Used for House members’ display names across Blackthorne.',
        'default' => '#D7B867',
    ],
    'primary_color' => [
        'label' => 'Primary Color',
        'help' => 'Primary House identity and major headings.',
        'default' => '#6B3D73',
    ],
    'secondary_color' => [
        'label' => 'Secondary Color',
        'help' => 'Supporting buttons, cards, and secondary accents.',
        'default' => '#43264D',
    ],
    'accent_color' => [
        'label' => 'Accent Color',
        'help' => 'Links, borders, selected controls, and decorative accents.',
        'default' => '#C9A85B',
    ],
    'dark_neutral_color' => [
        'label' => 'Dark Neutral',
        'help' => 'House-specific dark surfaces and card backgrounds.',
        'default' => '#1D1421',
    ],
    'highlight_color' => [
        'label' => 'Highlight Color',
        'help' => 'Hover states, badges, emphasis, and brighter details.',
        'default' => '#E8DCB9',
    ],
];

?>

<div class="house-admin-field-grid">

    <div class="form-group">
        <label for="house-name">
            House Name
        </label>

        <input
            class="form-control"
            type="text"
            id="house-name"
            name="house_name"
            maxlength="100"
            required
            value="<?= e((string) ($houseForm['name'] ?? '')); ?>"
        >

        <p class="form-help">
            Internal House name. Its URL slug is generated automatically.
        </p>
    </div>


    <div class="form-group">
        <label for="house-display-name">
            House Display Name
        </label>

        <input
            class="form-control"
            type="text"
            id="house-display-name"
            name="display_name"
            maxlength="150"
            required
            value="<?= e((string) ($houseForm['display_name'] ?? '')); ?>"
        >

        <p class="form-help">
            The full name members will see throughout the Academy.
        </p>
    </div>

</div>


<div class="form-group">
    <label for="house-description">
        Description
    </label>

    <textarea
        class="form-control"
        id="house-description"
        name="description"
        rows="4"
        maxlength="5000"
    ><?= e((string) ($houseForm['description'] ?? '')); ?></textarea>

    <p class="form-help">
        A short administrative/reference description of the House.
    </p>
</div>


<div class="form-group">
    <label for="house-motto">
        House Motto
    </label>

    <input
        class="form-control"
        type="text"
        id="house-motto"
        name="motto"
        maxlength="255"
        value="<?= e((string) ($houseForm['motto'] ?? '')); ?>"
        placeholder="Enter the House motto"
    >

    <p class="form-help">
        Displayed in italics as a quotation in the Common Room hero.
    </p>
</div>


<div class="form-group">
    <label for="house-introduction">
        House Introduction
    </label>

    <textarea
        class="form-control"
        id="house-introduction"
        name="house_introduction"
        rows="10"
    ><?= e((string) ($houseForm['house_introduction'] ?? '')); ?></textarea>

    <p class="form-help">
        The longer welcome text shown in the House Common Room hero.
    </p>
</div>


<section aria-labelledby="house-theme-heading">

    <header class="house-admin-titlebar">
        <h3 id="house-theme-heading">
            House Theme
        </h3>

        <p>
            These colors will become scoped CSS variables inside House-specific
            areas such as the Common Room and House forums.
        </p>
    </header>

    <div class="house-theme-grid">

        <?php foreach ($colorFields as $fieldName => $config): ?>
            <?php
            $value =
                trim(
                    (string) (
                        $houseForm[$fieldName]
                        ?? ''
                    )
                );

            if ($value === '') {
                $value =
                    (string) $config['default'];
            }

            $pickerId =
                'picker-'
                . $fieldName;

            $inputId =
                'house-'
                . str_replace(
                    '_',
                    '-',
                    $fieldName
                );
            ?>

            <div class="form-group">

                <label for="<?= e($inputId); ?>">
                    <?= e((string) $config['label']); ?>
                </label>

                <div class="house-theme-control">

                    <input
                        type="color"
                        id="<?= e($pickerId); ?>"
                        value="<?= e($value); ?>"
                        data-color-picker="<?= e($fieldName); ?>"
                        aria-label="<?= e((string) $config['label']); ?> color picker"
                    >

                    <input
                        class="form-control"
                        type="text"
                        id="<?= e($inputId); ?>"
                        name="<?= e($fieldName); ?>"
                        maxlength="7"
                        pattern="^#[0-9A-Fa-f]{6}$"
                        value="<?= e($value); ?>"
                        placeholder="#6B3D73"
                    >

                </div>

                <p class="form-help">
                    <?= e((string) $config['help']); ?>
                </p>

            </div>

        <?php endforeach; ?>

    </div>


    <div
        class="house-theme-preview"
        data-house-theme-preview
        style="
            --preview-primary: <?= e((string) ($houseForm['primary_color'] ?: '#6B3D73')); ?>;
            --preview-secondary: <?= e((string) ($houseForm['secondary_color'] ?: '#43264D')); ?>;
            --preview-accent: <?= e((string) ($houseForm['accent_color'] ?: '#C9A85B')); ?>;
            --preview-dark: <?= e((string) ($houseForm['dark_neutral_color'] ?: '#1D1421')); ?>;
            --preview-highlight: <?= e((string) ($houseForm['highlight_color'] ?: '#E8DCB9')); ?>;
        "
    >
        <h3>
            House Theme Preview
        </h3>

        <p>
            This is an example of House-specific text, links,
            borders, and surfaces.
        </p>

        <a href="#" onclick="return false;">
            Example House Link
        </a>

        <button
            type="button"
            class="button"
        >
            Example Button
        </button>
    </div>

</section>


<div class="house-admin-field-grid">

    <div class="form-group">
        <label for="common-room-forum">
            Common Room Forum
        </label>

        <select
            class="form-control"
            id="common-room-forum"
            name="common_room_forum_id"
            data-forum-picker
        >
            <option value="">
                Not selected yet
            </option>

            <?php foreach ($availableForums as $forum): ?>
                <option
                    value="<?= (int) $forum['id']; ?>"
                    data-forum-id="<?= (int) $forum['id']; ?>"
                    data-parent-forum-id="<?= (int) ($forum['parent_forum_id'] ?? 0); ?>"
                    data-depth="<?= (int) ($forum['hierarchy_depth'] ?? 0); ?>"
                    data-category-title="<?= e((string) $forum['category_title']); ?>"
                    data-forum-title="<?= e((string) $forum['title']); ?>"
                    <?= (int) ($houseForm['common_room_forum_id'] ?? 0) === (int) $forum['id']
                        ? 'selected'
                        : ''; ?>
                >
                    <?= e(
                        str_repeat(
                            '    ',
                            (int) ($forum['hierarchy_depth'] ?? 0)
                        )
                        . (string) $forum['title']
                    ); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <p class="form-help">
            The crest on the Common Room page will link to this forum.
        </p>
    </div>


    <div class="form-group">
        <label for="announcement-forum">
            Common Room Announcement Forum
        </label>

        <select
            class="form-control"
            id="announcement-forum"
            name="announcement_forum_id"
            data-forum-picker
        >
            <option value="">
                Not selected yet
            </option>

            <?php foreach ($availableForums as $forum): ?>
                <option
                    value="<?= (int) $forum['id']; ?>"
                    data-forum-id="<?= (int) $forum['id']; ?>"
                    data-parent-forum-id="<?= (int) ($forum['parent_forum_id'] ?? 0); ?>"
                    data-depth="<?= (int) ($forum['hierarchy_depth'] ?? 0); ?>"
                    data-category-title="<?= e((string) $forum['category_title']); ?>"
                    data-forum-title="<?= e((string) $forum['title']); ?>"
                    <?= (int) ($houseForm['announcement_forum_id'] ?? 0) === (int) $forum['id']
                        ? 'selected'
                        : ''; ?>
                >
                    <?= e(
                        str_repeat(
                            '    ',
                            (int) ($forum['hierarchy_depth'] ?? 0)
                        )
                        . (string) $forum['title']
                        . (
                            (int) $forum['is_announcement_forum'] === 1
                                ? ' [Announcement Forum]'
                                : ''
                        )
                    ); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <p class="form-help">
            The Common Room will show the newest announcement from this forum.
        </p>
    </div>

</div>


<section aria-labelledby="house-crest-heading">

    <header class="house-admin-titlebar">
        <h3 id="house-crest-heading">
            House Crest
        </h3>

        <p>
            JPG, PNG, WebP, or AVIF. Maximum 5 MB.
            Blackthorne stores the original-format fallback and creates
            an optimized WebP derivative when supported.
        </p>
    </header>


    <?php if ($crestReference !== null): ?>

        <div class="house-crest-preview">

            <picture>

                <?php if (
                    $crestWebp !== null
                    && $crestWebp !== $crestReference
                ): ?>
                    <source
                        srcset="<?= e(url(ltrim($crestWebp, '/'))); ?>"
                        type="image/webp"
                    >
                <?php endif; ?>

                <img
                    src="<?= e(url(ltrim($crestReference, '/'))); ?>"
                    alt="Current House crest"
                >

            </picture>

        </div>

    <?php endif; ?>


    <div class="form-group">

        <label for="crest-upload">
            <?= $crestReference !== null
                ? 'Replace Crest'
                : 'Upload Crest'; ?>
        </label>

        <input
            class="form-control"
            type="file"
            id="crest-upload"
            name="crest_upload"
            accept="image/jpeg,image/png,image/webp,image/avif"
        >

    </div>


    <?php if ($crestReference !== null): ?>

        <label class="forum-admin-choice">

            <input
                type="checkbox"
                name="remove_crest"
                value="1"
            >

            <span>
                Remove current crest
            </span>

        </label>

    <?php endif; ?>

</section>


<section aria-labelledby="house-hero-heading">

    <header class="house-admin-titlebar">
        <h3 id="house-hero-heading">
            Common Room Hero / Cover Image
        </h3>

        <p>
            Wide atmospheric background for the House Common Room hero.
            JPG, PNG, WebP, or AVIF. Maximum 8 MB.
            Blackthorne keeps the original-format fallback and creates
            an optimized WebP derivative when supported.
        </p>
    </header>


    <?php if ($heroReference !== null): ?>

        <div class="house-hero-preview">

            <picture>

                <?php if (
                    $heroWebp !== null
                    && $heroWebp !== $heroReference
                ): ?>
                    <source
                        srcset="<?= e(url(ltrim($heroWebp, '/'))); ?>"
                        type="image/webp"
                    >
                <?php endif; ?>

                <img
                    src="<?= e(url(ltrim($heroReference, '/'))); ?>"
                    alt="Current House Common Room hero"
                >

            </picture>

        </div>

    <?php endif; ?>


    <div class="form-group">

        <label for="hero-upload">
            <?= $heroReference !== null
                ? 'Replace Hero / Cover Image'
                : 'Upload Hero / Cover Image'; ?>
        </label>

        <input
            class="form-control"
            type="file"
            id="hero-upload"
            name="hero_upload"
            accept="image/jpeg,image/png,image/webp,image/avif"
        >

        <p class="form-help">
            A wide landscape image works best. It will be displayed with a
            dark overlay so the House text remains readable.
        </p>

    </div>


    <?php if ($heroReference !== null): ?>

        <label class="forum-admin-choice">

            <input
                type="checkbox"
                name="remove_hero"
                value="1"
            >

            <span>
                Remove current hero / cover image
            </span>

        </label>

    <?php endif; ?>

</section>


<section aria-labelledby="house-mascot-heading">

    <header class="house-admin-titlebar">
        <h3 id="house-mascot-heading">
            House Mascot
        </h3>

        <p>
            Upload the House mascot artwork used in the Common Room.
            Transparent PNG or WebP artwork works especially well.
        </p>
    </header>


    <?php if ($mascotReference !== null): ?>

        <div class="house-mascot-preview">

            <picture>

                <?php if (
                    $mascotWebp !== null
                    && $mascotWebp !== $mascotReference
                ): ?>
                    <source
                        srcset="<?= e(url(ltrim($mascotWebp, '/'))); ?>"
                        type="image/webp"
                    >
                <?php endif; ?>

                <img
                    src="<?= e(url(ltrim($mascotReference, '/'))); ?>"
                    alt="Current House mascot"
                >

            </picture>

        </div>

    <?php endif; ?>


    <div class="form-group">

        <label for="mascot-upload">
            <?= $mascotReference !== null
                ? 'Replace Mascot'
                : 'Upload Mascot'; ?>
        </label>

        <input
            class="form-control"
            type="file"
            id="mascot-upload"
            name="mascot_upload"
            accept="image/jpeg,image/png,image/webp,image/avif"
        >

        <p class="form-help">
            JPG, PNG, WebP, or AVIF. Maximum 5 MB.
        </p>

    </div>


    <?php if ($mascotReference !== null): ?>

        <label class="forum-admin-choice">

            <input
                type="checkbox"
                name="remove_mascot"
                value="1"
            >

            <span>
                Remove current mascot
            </span>

        </label>

    <?php endif; ?>


    <div class="form-group">
        <label for="house-mascot-type">
            Mascot
        </label>

        <input
            class="form-control"
            type="text"
            id="house-mascot-type"
            name="mascot_type"
            maxlength="100"
            value="<?= e((string) ($houseForm['mascot_type'] ?? '')); ?>"
            placeholder="Example: Raven"
        >

        <p class="form-help">
            The creature or symbol used as this House's mascot.
        </p>
    </div>


    <div class="form-group">
        <label for="house-mascot-name">
            Name
        </label>

        <input
            class="form-control"
            type="text"
            id="house-mascot-name"
            name="mascot_name"
            maxlength="100"
            value="<?= e((string) ($houseForm['mascot_name'] ?? '')); ?>"
            placeholder="Mascot name"
        >
    </div>


    <div class="form-group">
        <label for="house-mascot-represents">
            Represents
        </label>

        <input
            class="form-control"
            type="text"
            id="house-mascot-represents"
            name="mascot_represents"
            maxlength="255"
            value="<?= e((string) ($houseForm['mascot_represents'] ?? '')); ?>"
            placeholder="What the mascot represents"
        >
    </div>

</section>


<label class="forum-admin-choice">

    <input
        type="checkbox"
        name="is_active"
        value="1"
        <?= (int) ($houseForm['is_active'] ?? 1) === 1
            ? 'checked'
            : ''; ?>
    >

    <span>
        House is active
    </span>

</label>
