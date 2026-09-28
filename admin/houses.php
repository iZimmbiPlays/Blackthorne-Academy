<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/house-functions.php';

require_active_account();


/*
|--------------------------------------------------------------------------
| Access
|--------------------------------------------------------------------------
*/

$isProtectedSuperAdmin =
    current_user_is_superuser();

$canViewHouses =
    $isProtectedSuperAdmin
    || user_can('houses.view')
    || user_can('houses.create')
    || user_can('houses.edit')
    || user_can('houses.delete')
    || user_can('houses.members.manage');

if (!$canViewHouses) {
    require_permission('houses.view');
}

$canCreateHouses =
    $isProtectedSuperAdmin
    || user_can('houses.create');

$canEditHouses =
    $isProtectedSuperAdmin
    || user_can('houses.edit');

$canDeleteHouses =
    $isProtectedSuperAdmin
    || user_can('houses.delete');

$currentUserId =
    (int) (current_user_id() ?? 0);


/*
|--------------------------------------------------------------------------
| Local Helpers
|--------------------------------------------------------------------------
*/

function admin_houses_query_id(string $key): int
{
    $value = $_GET[$key] ?? '';

    if (
        !is_scalar($value)
        || !ctype_digit((string) $value)
    ) {
        return 0;
    }

    return max(0, (int) $value);
}


function admin_houses_post_int(string $key): ?int
{
    $value = $_POST[$key] ?? '';

    if (
        $value === ''
        || $value === null
    ) {
        return null;
    }

    if (
        !is_scalar($value)
        || !ctype_digit((string) $value)
    ) {
        return null;
    }

    $id = (int) $value;

    return $id > 0
        ? $id
        : null;
}


function admin_houses_text_length(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);
}


function admin_houses_forum_exists(
    PDO $pdo,
    ?int $forumId
): bool {
    if ($forumId === null) {
        return true;
    }

    $statement = $pdo->prepare(
        'SELECT 1
         FROM forums
         WHERE id = :forum_id
         LIMIT 1'
    );

    $statement->execute([
        'forum_id' => $forumId,
    ]);

    return
        $statement->fetchColumn()
        !== false;
}


function admin_houses_history_counts(
    PDO $pdo,
    int $houseId
): array {
    $statement = $pdo->prepare(
        'SELECT
            (
                SELECT COUNT(*)
                FROM house_memberships
                WHERE house_id = :house_id_memberships
            ) AS memberships,
            (
                SELECT COUNT(*)
                FROM house_cup_results
                WHERE house_id = :house_id_cup
            ) AS cup_results,
            (
                SELECT COUNT(*)
                FROM points_ledger
                WHERE house_id = :house_id_points
            ) AS points_entries'
    );

    $statement->execute([
        'house_id_memberships' => $houseId,
        'house_id_cup' => $houseId,
        'house_id_points' => $houseId,
    ]);

    $row =
        $statement->fetch(PDO::FETCH_ASSOC)
        ?: [];

    return [
        'memberships' =>
            (int) ($row['memberships'] ?? 0),

        'cup_results' =>
            (int) ($row['cup_results'] ?? 0),

        'points_entries' =>
            (int) ($row['points_entries'] ?? 0),
    ];
}


function admin_houses_has_history(
    array $counts
): bool {
    return
        (int) ($counts['memberships'] ?? 0) > 0
        || (int) ($counts['cup_results'] ?? 0) > 0
        || (int) ($counts['points_entries'] ?? 0) > 0;
}


function admin_houses_audit(
    PDO $pdo,
    int $actorUserId,
    string $actionType,
    int $houseId,
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
            'user_id' =>
                $actorUserId,

            'action_type' =>
                $actionType,

            'entity_type' =>
                'house',

            'entity_id' =>
                $houseId,

            'description' =>
                $description,

            'ip_address' =>
                substr(
                    (string) (
                        $_SERVER['REMOTE_ADDR']
                        ?? ''
                    ),
                    0,
                    45
                ),

            'user_agent' =>
                substr(
                    (string) (
                        $_SERVER['HTTP_USER_AGENT']
                        ?? ''
                    ),
                    0,
                    500
                ),
        ]);

    } catch (PDOException $exception) {
        error_log(
            'Blackthorne House audit error: '
            . $exception->getMessage()
        );
    }
}


/*
|--------------------------------------------------------------------------
| Available Forums
|--------------------------------------------------------------------------
*/

$forumsStatement = $pdo->query(
    'WITH RECURSIVE forum_tree AS (
        SELECT
            f.id,
            f.title,
            f.slug,
            f.category_id,
            f.parent_forum_id,
            f.is_visible,
            f.is_announcement_forum,
            f.sort_order,
            0 AS hierarchy_depth,
            CAST(
                LPAD(f.sort_order, 10, "0")
                AS CHAR(2000)
            ) AS hierarchy_sort

        FROM forums f

        WHERE f.parent_forum_id IS NULL

        UNION ALL

        SELECT
            child.id,
            child.title,
            child.slug,
            child.category_id,
            child.parent_forum_id,
            child.is_visible,
            child.is_announcement_forum,
            child.sort_order,
            parent.hierarchy_depth + 1,
            CONCAT(
                parent.hierarchy_sort,
                ".",
                LPAD(child.sort_order, 10, "0")
            )

        FROM forums child

        INNER JOIN forum_tree parent
            ON parent.id = child.parent_forum_id
    )

    SELECT
        ft.id,
        ft.title,
        ft.slug,
        ft.parent_forum_id,
        ft.is_visible,
        ft.is_announcement_forum,
        ft.hierarchy_depth,
        fc.title AS category_title

    FROM forum_tree ft

    INNER JOIN forum_categories fc
        ON fc.id = ft.category_id

    ORDER BY
        fc.sort_order ASC,
        fc.title ASC,
        ft.hierarchy_sort ASC,
        ft.title ASC'
);

$availableForums =
    $forumsStatement->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Forms
|--------------------------------------------------------------------------
*/

$errors = [];
$formAction =
    post_value('form_action');


if (is_post()) {
    require_valid_csrf();

    /*
    |--------------------------------------------------------------------------
    | Create House
    |--------------------------------------------------------------------------
    */

    if ($formAction === 'create_house') {
        if (!$canCreateHouses) {
            $errors[] =
                'You do not have permission to create Houses.';
        }

        $name =
            trim(
                post_value('house_name')
            );

        $slug =
            house_slugify($name);

        $displayName =
            trim(
                post_value('display_name')
            );

        $description =
            trim(
                post_value('description')
            );

        $motto =
            trim(
                post_value('motto')
            );

        $mascotType =
            trim(
                post_value('mascot_type')
            );

        $mascotName =
            trim(
                post_value('mascot_name')
            );

        $mascotRepresents =
            trim(
                post_value('mascot_represents')
            );

        $introduction =
            sanitize_rich_text(
                post_value('house_introduction')
            );

        $displayColor =
            trim(
                post_value('display_color')
            );

        $primaryColor =
            trim(
                post_value('primary_color')
            );

        $secondaryColor =
            trim(
                post_value('secondary_color')
            );

        $accentColor =
            trim(
                post_value('accent_color')
            );

        $darkNeutralColor =
            trim(
                post_value('dark_neutral_color')
            );

        $highlightColor =
            trim(
                post_value('highlight_color')
            );

        $commonRoomForumId =
            admin_houses_post_int(
                'common_room_forum_id'
            );

        $announcementForumId =
            admin_houses_post_int(
                'announcement_forum_id'
            );

        $isActive =
            post_value('is_active') === '1'
                ? 1
                : 0;

        if ($name === '') {
            $errors[] =
                'Enter the internal House name.';
        } elseif (
            admin_houses_text_length($name)
            > 100
        ) {
            $errors[] =
                'The House name cannot exceed 100 characters.';
        }

        if ($slug === '') {
            $errors[] =
                'The House name could not be converted into a valid URL slug.';
        }

        if ($displayName === '') {
            $errors[] =
                'Enter the House display name.';
        } elseif (
            admin_houses_text_length(
                $displayName
            ) > 150
        ) {
            $errors[] =
                'The House display name cannot exceed 150 characters.';
        }

        if (
            admin_houses_text_length(
                $description
            ) > 5000
        ) {
            $errors[] =
                'The House description cannot exceed 5,000 characters.';
        }

        if (
            admin_houses_text_length(
                $motto
            ) > 255
        ) {
            $errors[] =
                'The House motto cannot exceed 255 characters.';
        }

        if (
            admin_houses_text_length(
                $mascotType
            ) > 100
        ) {
            $errors[] =
                'The mascot type cannot exceed 100 characters.';
        }

        if (
            admin_houses_text_length(
                $mascotName
            ) > 100
        ) {
            $errors[] =
                'The mascot name cannot exceed 100 characters.';
        }

        if (
            admin_houses_text_length(
                $mascotRepresents
            ) > 255
        ) {
            $errors[] =
                'The mascot representation cannot exceed 255 characters.';
        }

        try {
            $displayColor =
                house_normalize_hex_color(
                    $displayColor
                );

            $primaryColor =
                house_normalize_hex_color(
                    $primaryColor
                );

            $secondaryColor =
                house_normalize_hex_color(
                    $secondaryColor
                );

            $accentColor =
                house_normalize_hex_color(
                    $accentColor
                );

            $darkNeutralColor =
                house_normalize_hex_color(
                    $darkNeutralColor
                );

            $highlightColor =
                house_normalize_hex_color(
                    $highlightColor
                );

        } catch (
            InvalidArgumentException $exception
        ) {
            $errors[] =
                $exception->getMessage();
        }

        if (
            !admin_houses_forum_exists(
                $pdo,
                $commonRoomForumId
            )
        ) {
            $errors[] =
                'Choose a valid Common Room forum.';
        }

        if (
            !admin_houses_forum_exists(
                $pdo,
                $announcementForumId
            )
        ) {
            $errors[] =
                'Choose a valid announcement forum.';
        }

        $duplicateStatement =
            $pdo->prepare(
                'SELECT id
                 FROM houses
                 WHERE name = :name
                    OR slug = :slug
                 LIMIT 1'
            );

        $duplicateStatement->execute([
            'name' => $name,
            'slug' => $slug,
        ]);

        if (
            $duplicateStatement->fetchColumn()
            !== false
        ) {
            $errors[] =
                'A House already uses that name or URL slug.';
        }

        if ($errors === []) {
            try {
                $pdo->beginTransaction();

                $sortStatement =
                    $pdo->query(
                        'SELECT
                            COALESCE(
                                MAX(sort_order),
                                0
                            ) + 10
                         FROM houses'
                    );

                $sortOrder =
                    (int) $sortStatement
                        ->fetchColumn();

                $insertStatement =
                    $pdo->prepare(
                        'INSERT INTO houses (
                            name,
                            display_name,
                            slug,
                            description,
                            motto,
                            house_introduction,
                            display_color,
                            crest_image,
                            hero_image,
                            mascot_image,
                            mascot_type,
                            mascot_name,
                            mascot_represents,
                            primary_color,
                            secondary_color,
                            accent_color,
                            dark_neutral_color,
                            highlight_color,
                            common_room_forum_id,
                            announcement_forum_id,
                            is_active,
                            sort_order
                         ) VALUES (
                            :name,
                            :display_name,
                            :slug,
                            :description,
                            :motto,
                            :house_introduction,
                            :display_color,
                            NULL,
                            NULL,
                            NULL,
                            :mascot_type,
                            :mascot_name,
                            :mascot_represents,
                            :primary_color,
                            :secondary_color,
                            :accent_color,
                            :dark_neutral_color,
                            :highlight_color,
                            :common_room_forum_id,
                            :announcement_forum_id,
                            :is_active,
                            :sort_order
                         )'
                    );

                $insertStatement->execute([
                    'name' =>
                        $name,

                    'display_name' =>
                        $displayName,

                    'slug' =>
                        $slug,

                    'description' =>
                        $description !== ''
                            ? $description
                            : null,

                    'motto' =>
                        $motto !== ''
                            ? $motto
                            : null,

                    'house_introduction' =>
                        $introduction !== ''
                            ? $introduction
                            : null,

                    'display_color' =>
                        $displayColor,

                    'mascot_type' =>
                        $mascotType !== ''
                            ? $mascotType
                            : null,

                    'mascot_name' =>
                        $mascotName !== ''
                            ? $mascotName
                            : null,

                    'mascot_represents' =>
                        $mascotRepresents !== ''
                            ? $mascotRepresents
                            : null,

                    'primary_color' =>
                        $primaryColor,

                    'secondary_color' =>
                        $secondaryColor,

                    'accent_color' =>
                        $accentColor,

                    'dark_neutral_color' =>
                        $darkNeutralColor,

                    'highlight_color' =>
                        $highlightColor,

                    'common_room_forum_id' =>
                        $commonRoomForumId,

                    'announcement_forum_id' =>
                        $announcementForumId,

                    'is_active' =>
                        $isActive,

                    'sort_order' =>
                        $sortOrder,
                ]);

                $houseId =
                    (int) $pdo->lastInsertId();

                $crestReference = null;

                if (
                    isset($_FILES['crest_upload'])
                    && is_array(
                        $_FILES['crest_upload']
                    )
                    && (
                        (int) (
                            $_FILES['crest_upload']['error']
                            ?? UPLOAD_ERR_NO_FILE
                        )
                    ) !== UPLOAD_ERR_NO_FILE
                ) {
                    $crestReference =
                        house_store_crest_upload(
                            $_FILES['crest_upload'],
                            $houseId
                        );

                    $crestStatement =
                        $pdo->prepare(
                            'UPDATE houses
                             SET crest_image = :crest_image
                             WHERE id = :house_id'
                        );

                    $crestStatement->execute([
                        'crest_image' =>
                            $crestReference,

                        'house_id' =>
                            $houseId,
                    ]);
                }


                $heroReference = null;

                if (
                    isset($_FILES['hero_upload'])
                    && is_array(
                        $_FILES['hero_upload']
                    )
                    && (
                        (int) (
                            $_FILES['hero_upload']['error']
                            ?? UPLOAD_ERR_NO_FILE
                        )
                    ) !== UPLOAD_ERR_NO_FILE
                ) {
                    $heroReference =
                        house_store_hero_upload(
                            $_FILES['hero_upload'],
                            $houseId
                        );

                    $heroStatement =
                        $pdo->prepare(
                            'UPDATE houses
                             SET hero_image = :hero_image
                             WHERE id = :house_id'
                        );

                    $heroStatement->execute([
                        'hero_image' =>
                            $heroReference,

                        'house_id' =>
                            $houseId,
                    ]);
                }



                $mascotReference = null;

                if (
                    isset(
                        $_FILES['mascot_upload']
                    )
                    && is_array(
                        $_FILES['mascot_upload']
                    )
                    && (
                        (int) (
                            $_FILES['mascot_upload']['error']
                            ?? UPLOAD_ERR_NO_FILE
                        )
                    ) !== UPLOAD_ERR_NO_FILE
                ) {
                    $mascotReference =
                        house_store_mascot_upload(
                            $_FILES['mascot_upload'],
                            $houseId
                        );

                    $mascotStatement =
                        $pdo->prepare(
                            'UPDATE houses
                             SET mascot_image = :mascot_image
                             WHERE id = :house_id'
                        );

                    $mascotStatement->execute([
                        'mascot_image' =>
                            $mascotReference,

                        'house_id' =>
                            $houseId,
                    ]);
                }


                admin_houses_audit(
                    $pdo,
                    $currentUserId,
                    'house_created',
                    $houseId,
                    'Created House '
                    . $displayName
                    . '.'
                );

                $pdo->commit();

                set_flash(
                    'success',
                    'House created successfully.'
                );

                redirect(
                    url(
                        'admin/houses.php?edit_house='
                        . $houseId
                    )
                );

            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'Blackthorne House create error: '
                    . $exception->getMessage()
                );

                $errors[] =
                    $exception instanceof RuntimeException
                        ? $exception->getMessage()
                        : 'The House could not be created.';
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Update House
    |--------------------------------------------------------------------------
    */

    if ($formAction === 'update_house') {
        if (!$canEditHouses) {
            $errors[] =
                'You do not have permission to edit Houses.';
        }

        $houseId =
            admin_houses_post_int(
                'house_id'
            )
            ?? 0;

        $existingHouse =
            house_by_id(
                $houseId,
                true
            );

        if ($existingHouse === null) {
            $errors[] =
                'That House could not be found.';
        }

        $name =
            trim(
                post_value('house_name')
            );

        $slug =
            house_slugify($name);

        $displayName =
            trim(
                post_value('display_name')
            );

        $description =
            trim(
                post_value('description')
            );

        $motto =
            trim(
                post_value('motto')
            );

        $mascotType =
            trim(
                post_value('mascot_type')
            );

        $mascotName =
            trim(
                post_value('mascot_name')
            );

        $mascotRepresents =
            trim(
                post_value('mascot_represents')
            );

        $introduction =
            sanitize_rich_text(
                post_value('house_introduction')
            );

        $displayColor =
            trim(
                post_value('display_color')
            );

        $primaryColor =
            trim(
                post_value('primary_color')
            );

        $secondaryColor =
            trim(
                post_value('secondary_color')
            );

        $accentColor =
            trim(
                post_value('accent_color')
            );

        $darkNeutralColor =
            trim(
                post_value('dark_neutral_color')
            );

        $highlightColor =
            trim(
                post_value('highlight_color')
            );

        $commonRoomForumId =
            admin_houses_post_int(
                'common_room_forum_id'
            );

        $announcementForumId =
            admin_houses_post_int(
                'announcement_forum_id'
            );

        $isActive =
            post_value('is_active') === '1'
                ? 1
                : 0;

        $removeCrest =
            post_value('remove_crest') === '1';

        $removeHero =
            post_value('remove_hero') === '1';

        $removeMascot =
            post_value('remove_mascot') === '1';

        if ($name === '') {
            $errors[] =
                'Enter the internal House name.';
        } elseif (
            admin_houses_text_length($name)
            > 100
        ) {
            $errors[] =
                'The House name cannot exceed 100 characters.';
        }

        if ($slug === '') {
            $errors[] =
                'The House name could not be converted into a valid URL slug.';
        }

        if ($displayName === '') {
            $errors[] =
                'Enter the House display name.';
        } elseif (
            admin_houses_text_length(
                $displayName
            ) > 150
        ) {
            $errors[] =
                'The House display name cannot exceed 150 characters.';
        }

        if (
            admin_houses_text_length(
                $description
            ) > 5000
        ) {
            $errors[] =
                'The House description cannot exceed 5,000 characters.';
        }

        if (
            admin_houses_text_length(
                $motto
            ) > 255
        ) {
            $errors[] =
                'The House motto cannot exceed 255 characters.';
        }

        if (
            admin_houses_text_length(
                $mascotType
            ) > 100
        ) {
            $errors[] =
                'The mascot type cannot exceed 100 characters.';
        }

        if (
            admin_houses_text_length(
                $mascotName
            ) > 100
        ) {
            $errors[] =
                'The mascot name cannot exceed 100 characters.';
        }

        if (
            admin_houses_text_length(
                $mascotRepresents
            ) > 255
        ) {
            $errors[] =
                'The mascot representation cannot exceed 255 characters.';
        }

        try {
            $displayColor =
                house_normalize_hex_color(
                    $displayColor
                );

            $primaryColor =
                house_normalize_hex_color(
                    $primaryColor
                );

            $secondaryColor =
                house_normalize_hex_color(
                    $secondaryColor
                );

            $accentColor =
                house_normalize_hex_color(
                    $accentColor
                );

            $darkNeutralColor =
                house_normalize_hex_color(
                    $darkNeutralColor
                );

            $highlightColor =
                house_normalize_hex_color(
                    $highlightColor
                );

        } catch (
            InvalidArgumentException $exception
        ) {
            $errors[] =
                $exception->getMessage();
        }

        if (
            !admin_houses_forum_exists(
                $pdo,
                $commonRoomForumId
            )
        ) {
            $errors[] =
                'Choose a valid Common Room forum.';
        }

        if (
            !admin_houses_forum_exists(
                $pdo,
                $announcementForumId
            )
        ) {
            $errors[] =
                'Choose a valid announcement forum.';
        }

        if ($houseId > 0) {
            $duplicateStatement =
                $pdo->prepare(
                    'SELECT id
                     FROM houses
                     WHERE (
                            name = :name
                            OR slug = :slug
                     )
                       AND id <> :house_id
                     LIMIT 1'
                );

            $duplicateStatement->execute([
                'name' =>
                    $name,

                'slug' =>
                    $slug,

                'house_id' =>
                    $houseId,
            ]);

            if (
                $duplicateStatement
                    ->fetchColumn()
                !== false
            ) {
                $errors[] =
                    'Another House already uses that name or URL slug.';
            }
        }

        if (
            $errors === []
            && $existingHouse !== null
        ) {
            $oldCrest =
                house_safe_crest_reference(
                    $existingHouse['crest_image']
                    ?? null
                );

            $newCrest =
                $oldCrest;

            $oldHero =
                house_safe_hero_reference(
                    $existingHouse['hero_image']
                    ?? null
                );

            $newHero =
                $oldHero;

            $oldMascot =
                house_safe_mascot_reference(
                    $existingHouse['mascot_image']
                    ?? null
                );

            $newMascot =
                $oldMascot;

            try {
                if (
                    isset(
                        $_FILES['crest_upload']
                    )
                    && is_array(
                        $_FILES['crest_upload']
                    )
                    && (
                        (int) (
                            $_FILES['crest_upload']['error']
                            ?? UPLOAD_ERR_NO_FILE
                        )
                    ) !== UPLOAD_ERR_NO_FILE
                ) {
                    $newCrest =
                        house_store_crest_upload(
                            $_FILES['crest_upload'],
                            $houseId
                        );

                } elseif ($removeCrest) {
                    $newCrest = null;
                }


                if (
                    isset(
                        $_FILES['hero_upload']
                    )
                    && is_array(
                        $_FILES['hero_upload']
                    )
                    && (
                        (int) (
                            $_FILES['hero_upload']['error']
                            ?? UPLOAD_ERR_NO_FILE
                        )
                    ) !== UPLOAD_ERR_NO_FILE
                ) {
                    $newHero =
                        house_store_hero_upload(
                            $_FILES['hero_upload'],
                            $houseId
                        );

                } elseif ($removeHero) {
                    $newHero = null;
                }


                if (
                    isset(
                        $_FILES['mascot_upload']
                    )
                    && is_array(
                        $_FILES['mascot_upload']
                    )
                    && (
                        (int) (
                            $_FILES['mascot_upload']['error']
                            ?? UPLOAD_ERR_NO_FILE
                        )
                    ) !== UPLOAD_ERR_NO_FILE
                ) {
                    $newMascot =
                        house_store_mascot_upload(
                            $_FILES['mascot_upload'],
                            $houseId
                        );

                } elseif ($removeMascot) {
                    $newMascot = null;
                }

                $updateStatement =
                    $pdo->prepare(
                        'UPDATE houses
                         SET
                            name = :name,
                            display_name = :display_name,
                            slug = :slug,
                            description = :description,
                            motto = :motto,
                            house_introduction = :house_introduction,
                            display_color = :display_color,
                            crest_image = :crest_image,
                            hero_image = :hero_image,
                            mascot_image = :mascot_image,
                            mascot_type = :mascot_type,
                            mascot_name = :mascot_name,
                            mascot_represents = :mascot_represents,
                            primary_color = :primary_color,
                            secondary_color = :secondary_color,
                            accent_color = :accent_color,
                            dark_neutral_color = :dark_neutral_color,
                            highlight_color = :highlight_color,
                            common_room_forum_id = :common_room_forum_id,
                            announcement_forum_id = :announcement_forum_id,
                            is_active = :is_active
                         WHERE id = :house_id'
                    );

                $updateStatement->execute([
                    'name' =>
                        $name,

                    'display_name' =>
                        $displayName,

                    'slug' =>
                        $slug,

                    'description' =>
                        $description !== ''
                            ? $description
                            : null,

                    'motto' =>
                        $motto !== ''
                            ? $motto
                            : null,

                    'house_introduction' =>
                        $introduction !== ''
                            ? $introduction
                            : null,

                    'display_color' =>
                        $displayColor,

                    'crest_image' =>
                        $newCrest,

                    'hero_image' =>
                        $newHero,

                    'mascot_image' =>
                        $newMascot,

                    'mascot_type' =>
                        $mascotType !== ''
                            ? $mascotType
                            : null,

                    'mascot_name' =>
                        $mascotName !== ''
                            ? $mascotName
                            : null,

                    'mascot_represents' =>
                        $mascotRepresents !== ''
                            ? $mascotRepresents
                            : null,

                    'primary_color' =>
                        $primaryColor,

                    'secondary_color' =>
                        $secondaryColor,

                    'accent_color' =>
                        $accentColor,

                    'dark_neutral_color' =>
                        $darkNeutralColor,

                    'highlight_color' =>
                        $highlightColor,

                    'common_room_forum_id' =>
                        $commonRoomForumId,

                    'announcement_forum_id' =>
                        $announcementForumId,

                    'is_active' =>
                        $isActive,

                    'house_id' =>
                        $houseId,
                ]);

                if (
                    $oldCrest !== null
                    && $oldCrest !== $newCrest
                ) {
                    house_delete_local_crest(
                        $oldCrest
                    );
                }


                if (
                    $oldHero !== null
                    && $oldHero !== $newHero
                ) {
                    house_delete_local_hero(
                        $oldHero
                    );
                }


                if (
                    $oldMascot !== null
                    && $oldMascot !== $newMascot
                ) {
                    house_delete_local_mascot(
                        $oldMascot
                    );
                }

                admin_houses_audit(
                    $pdo,
                    $currentUserId,
                    'house_updated',
                    $houseId,
                    'Updated House '
                    . $displayName
                    . '.'
                );

                set_flash(
                    'success',
                    'House updated successfully.'
                );

                redirect(
                    url(
                        'admin/houses.php?edit_house='
                        . $houseId
                    )
                );

            } catch (Throwable $exception) {
                if (
                    isset($newCrest)
                    && is_string($newCrest)
                    && $newCrest !== ''
                    && $newCrest !== $oldCrest
                ) {
                    house_delete_local_crest(
                        $newCrest
                    );
                }


                if (
                    isset($newHero)
                    && is_string($newHero)
                    && $newHero !== ''
                    && $newHero !== $oldHero
                ) {
                    house_delete_local_hero(
                        $newHero
                    );
                }


                if (
                    isset($newMascot)
                    && is_string($newMascot)
                    && $newMascot !== ''
                    && $newMascot !== $oldMascot
                ) {
                    house_delete_local_mascot(
                        $newMascot
                    );
                }

                error_log(
                    'Blackthorne House update error: '
                    . $exception->getMessage()
                );

                $errors[] =
                    $exception instanceof RuntimeException
                        ? $exception->getMessage()
                        : 'The House could not be updated.';
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Deactivate / Reactivate House
    |--------------------------------------------------------------------------
    */

    if (
        $formAction === 'deactivate_house'
        || $formAction === 'reactivate_house'
    ) {
        if (!$canEditHouses) {
            $errors[] =
                'You do not have permission to change House status.';
        }

        $houseId =
            admin_houses_post_int(
                'house_id'
            )
            ?? 0;

        $house =
            house_by_id(
                $houseId,
                true
            );

        if ($house === null) {
            $errors[] =
                'That House could not be found.';
        }

        if (
            $errors === []
            && $house !== null
        ) {
            $newStatus =
                $formAction === 'reactivate_house'
                    ? 1
                    : 0;

            $statement =
                $pdo->prepare(
                    'UPDATE houses
                     SET is_active = :is_active
                     WHERE id = :house_id'
                );

            $statement->execute([
                'is_active' =>
                    $newStatus,

                'house_id' =>
                    $houseId,
            ]);

            admin_houses_audit(
                $pdo,
                $currentUserId,
                $newStatus === 1
                    ? 'house_reactivated'
                    : 'house_deactivated',
                $houseId,
                (
                    $newStatus === 1
                        ? 'Reactivated House '
                        : 'Deactivated House '
                )
                . (
                    $house['display_name']
                    ?? $house['name']
                )
                . '.'
            );

            set_flash(
                'success',
                $newStatus === 1
                    ? 'House reactivated.'
                    : 'House deactivated.'
            );

            redirect(
                url(
                    'admin/houses.php?edit_house='
                    . $houseId
                )
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Delete Unused House
    |--------------------------------------------------------------------------
    */

    if ($formAction === 'delete_house') {
        if (!$canDeleteHouses) {
            $errors[] =
                'You do not have permission to delete Houses.';
        }

        $houseId =
            admin_houses_post_int(
                'house_id'
            )
            ?? 0;

        $house =
            house_by_id(
                $houseId,
                true
            );

        if ($house === null) {
            $errors[] =
                'That House could not be found.';
        }

        if (
            $errors === []
            && $house !== null
        ) {
            $historyCounts =
                admin_houses_history_counts(
                    $pdo,
                    $houseId
                );

            if (
                admin_houses_has_history(
                    $historyCounts
                )
            ) {
                $errors[] =
                    'This House has membership, points, or House Cup history and cannot be permanently deleted. Deactivate it instead.';
            } else {
                $crest =
                    house_safe_crest_reference(
                        $house['crest_image']
                        ?? null
                    );

                $hero =
                    house_safe_hero_reference(
                        $house['hero_image']
                        ?? null
                    );

                $mascot =
                    house_safe_mascot_reference(
                        $house['mascot_image']
                        ?? null
                    );

                try {
                    $pdo->beginTransaction();

                    admin_houses_audit(
                        $pdo,
                        $currentUserId,
                        'house_deleted',
                        $houseId,
                        'Deleted unused House '
                        . (
                            $house['display_name']
                            ?? $house['name']
                        )
                        . '.'
                    );

                    $deleteStatement =
                        $pdo->prepare(
                            'DELETE FROM houses
                             WHERE id = :house_id'
                        );

                    $deleteStatement->execute([
                        'house_id' =>
                            $houseId,
                    ]);

                    $pdo->commit();

                    if ($crest !== null) {
                        house_delete_local_crest(
                            $crest
                        );
                    }


                    if ($hero !== null) {
                        house_delete_local_hero(
                            $hero
                        );
                    }


                    if ($mascot !== null) {
                        house_delete_local_mascot(
                            $mascot
                        );
                    }

                    set_flash(
                        'success',
                        'Unused House permanently deleted.'
                    );

                    redirect(
                        url(
                            'admin/houses.php'
                        )
                    );

                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    error_log(
                        'Blackthorne House delete error: '
                        . $exception->getMessage()
                    );

                    $errors[] =
                        'The House could not be deleted.';
                }
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| Houses
|--------------------------------------------------------------------------
*/

$housesStatement =
    $pdo->query(
        'SELECT
            h.*,
            cr.title AS common_room_forum_title,
            af.title AS announcement_forum_title,
            (
                SELECT COUNT(*)
                FROM house_memberships hm
                WHERE hm.house_id = h.id
                  AND hm.membership_status = "active"
                  AND hm.left_at IS NULL
            ) AS active_member_count
         FROM houses h
         LEFT JOIN forums cr
            ON cr.id = h.common_room_forum_id
         LEFT JOIN forums af
            ON af.id = h.announcement_forum_id
         ORDER BY
            h.sort_order ASC,
            h.display_name ASC,
            h.name ASC,
            h.id ASC'
    );

$houses =
    $housesStatement->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Selected House
|--------------------------------------------------------------------------
*/

$editHouseId =
    admin_houses_query_id(
        'edit_house'
    );

$editHouse = null;
$editHistoryCounts = [
    'memberships' => 0,
    'cup_results' => 0,
    'points_entries' => 0,
];

if ($editHouseId > 0) {
    $editHouse =
        house_by_id(
            $editHouseId,
            true
        );

    if ($editHouse !== null) {
        $editHistoryCounts =
            admin_houses_history_counts(
                $pdo,
                $editHouseId
            );
    }
}


$successMessage =
    get_flash('success');


/*
|--------------------------------------------------------------------------
| Page Metadata
|--------------------------------------------------------------------------
*/

$pageTitle =
    'House Management | Blackthorne Academy';

$pageDescription =
    'Create and manage Blackthorne Academy Houses, Common Room settings, House crests, and House themes.';

$pageCanonical =
    url('admin/houses.php');

$robots =
    'noindex, nofollow';

require INCLUDES_PATH . '/header.php';

?>

<style>
.house-admin-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.05fr) minmax(300px, 0.95fr);
    gap: 24px;
    align-items: start;
}

.house-admin-card,
.house-admin-list-card {
    border: 1px solid rgba(205, 171, 91, 0.18);
    border-radius: 14px;
    background: rgba(22, 14, 26, 0.78);
    box-shadow: 0 16px 36px rgba(0, 0, 0, 0.16);
}

.house-admin-card {
    padding: 24px;
}

.house-admin-card + .house-admin-card {
    margin-top: 22px;
}

.house-admin-titlebar {
    margin-bottom: 20px;
}

.house-admin-titlebar h2,
.house-admin-titlebar h3 {
    margin-bottom: 6px;
}

.house-admin-form {
    display: grid;
    gap: 18px;
}

.house-admin-field-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}

.house-theme-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
}

.house-theme-control {
    display: grid;
    grid-template-columns: 52px minmax(0, 1fr);
    gap: 10px;
    align-items: end;
}

.house-theme-control input[type="color"] {
    width: 52px;
    height: 44px;
    padding: 3px;
    border: 1px solid rgba(205, 171, 91, 0.28);
    border-radius: 8px;
    background: #1d1421;
    cursor: pointer;
}

.house-theme-preview {
    display: grid;
    gap: 14px;
    margin-top: 6px;
    padding: 18px;
    border: 1px solid var(--preview-accent, #a77e49);
    border-radius: 12px;
    background:
        linear-gradient(
            145deg,
            var(--preview-dark, #1d1421),
            rgba(17, 11, 21, 0.96)
        );
}

.house-theme-preview h3 {
    margin: 0;
    color: var(--preview-primary, #d7b867);
}

.house-theme-preview p {
    margin: 0;
    color: var(--preview-highlight, #e8dcb9);
}

.house-theme-preview a {
    color: var(--preview-accent, #d3ad63);
}

.house-theme-preview .button {
    justify-self: start;
    border-color: var(--preview-highlight, #e2ca87);
    background: var(--preview-secondary, #5a355f);
    color: #fff;
}

.house-crest-preview {
    width: min(260px, 100%);
    aspect-ratio: 1 / 1;
    display: grid;
    place-items: center;
    overflow: hidden;
    border: 1px solid rgba(205, 171, 91, 0.22);
    border-radius: 14px;
    background: rgba(12, 8, 15, 0.72);
}

.house-crest-preview img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}

.house-mascot-preview {
    margin-top: 16px;
    width: min(320px, 100%);
    min-height: 190px;
    display: grid;
    place-items: center;
    overflow: hidden;
    padding: 18px;
    border: 1px solid rgba(205, 171, 91, 0.22);
    border-radius: 14px;
    background: rgba(12, 8, 15, 0.72);
}

.house-mascot-preview img {
    display: block;
    width: min(100%, 260px);
    max-height: 280px;
    object-fit: contain;
}

.house-admin-list {
    display: grid;
    gap: 16px;
}

.house-admin-list-card {
    padding: 18px;
}

.house-admin-list-card header {
    display: flex;
    justify-content: space-between;
    gap: 18px;
    align-items: flex-start;
}

.house-admin-list-card h3 {
    margin-bottom: 4px;
}

.house-admin-color-row {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 12px;
}

.house-admin-color-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 8px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 999px;
    font-size: 0.8rem;
    color: #d7ccd9;
}

.house-admin-color-dot {
    width: 13px;
    height: 13px;
    border-radius: 50%;
    border: 1px solid rgba(255, 255, 255, 0.28);
}

.house-status-badge {
    display: inline-flex;
    padding: 4px 8px;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.04em;
}

.house-status-active {
    background: rgba(83, 128, 88, 0.18);
    color: #c7e4ca;
}

.house-status-inactive {
    background: rgba(139, 81, 91, 0.2);
    color: #e8c4cb;
}

.house-admin-danger-zone {
    border-color: rgba(178, 81, 95, 0.34);
    background: rgba(61, 27, 34, 0.2);
}

.house-admin-danger-zone h3 {
    color: #e5bbc1;
}

.house-admin-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
}

.house-admin-delete-button {
    border-color: rgba(189, 95, 105, 0.48);
    background: linear-gradient(
        180deg,
        rgba(106, 48, 58, 0.94),
        rgba(71, 31, 40, 0.98)
    );
    color: #f2dadd;
}

.house-admin-delete-button:hover,
.house-admin-delete-button:focus-visible {
    border-color: rgba(222, 132, 142, 0.72);
    color: #fff0f2;
}

@media (max-width: 900px) {
    .house-admin-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 650px) {
    .house-admin-field-grid,
    .house-theme-grid {
        grid-template-columns: 1fr;
    }

    .house-admin-list-card header {
        display: grid;
    }

    .house-admin-actions .button {
        width: 100%;
    }
}
</style>

<main
    id="main-content"
    class="forum-admin-page house-admin-page"
>

    <section
        class="forum-admin-hero"
        aria-labelledby="house-admin-heading"
    >
        <div class="section-inner">

            <p class="academy-overline">
                Academy Administration
            </p>

            <h1 id="house-admin-heading">
                House Management
            </h1>

            <p>
                Create and configure Academy Houses, House themes,
                Common Room forums, announcement sources, and crests.
            </p>

            <div class="forum-admin-edit-actions">

                <?php if (
                    $isProtectedSuperAdmin
                    || user_can('houses.sorting.manage')
                ): ?>
                    <a
                        href="<?= e(url('admin/sorting-ceremony.php')); ?>"
                        class="button button-primary"
                    >
                        Sorting Ceremony Manager
                    </a>
                <?php endif; ?>

                <?php if (current_user_is_admin()): ?>
                    <a
                        href="<?= e(url('admin/house-members.php')); ?>"
                        class="button button-primary"
                    >
                        Manage House Members
                    </a>
                <?php endif; ?>

                <a
                    href="<?= e(url('staff-dashboard.php')); ?>"
                    class="button button-secondary"
                >
                    Staff Dashboard
                </a>

                <a
                    href="<?= e(DASHBOARD_URL); ?>"
                    class="button button-secondary"
                >
                    Return to Dashboard
                </a>

            </div>

        </div>
    </section>


    <section class="forum-admin-content">
        <div class="section-inner">

            <?php if ($successMessage !== null): ?>
                <div
                    class="form-message form-message-success"
                    role="status"
                >
                    <?= e($successMessage); ?>
                </div>
            <?php endif; ?>


            <?php if ($errors !== []): ?>
                <div
                    class="form-message form-message-error"
                    role="alert"
                >
                    <h2>Please correct the following:</h2>

                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= e($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>


            <div class="house-admin-grid">

                <div>

                    <?php if (
                        $canCreateHouses
                        && $editHouse === null
                    ): ?>

                        <section
                            class="house-admin-card"
                            aria-labelledby="create-house-heading"
                        >
                            <header class="house-admin-titlebar">
                                <p class="forum-admin-step">
                                    House Setup
                                </p>

                                <h2 id="create-house-heading">
                                    Create a House
                                </h2>

                                <p>
                                    Create the House identity first. Forums may
                                    be attached now or later.
                                </p>
                            </header>

                            <form
                                action="<?= e(url('admin/houses.php')); ?>"
                                method="post"
                                enctype="multipart/form-data"
                                class="house-admin-form"
                                data-house-form
                            >
                                <?= csrf_field(); ?>

                                <input
                                    type="hidden"
                                    name="form_action"
                                    value="create_house"
                                >

                                <?php
                                $houseForm = [
                                    'name' => '',
                                    'display_name' => '',
                                    'description' => '',
                                    'house_introduction' => '',
                                    'display_color' => '#D7B867',
                                    'primary_color' => '#6B3D73',
                                    'secondary_color' => '#43264D',
                                    'accent_color' => '#C9A85B',
                                    'dark_neutral_color' => '#1D1421',
                                    'highlight_color' => '#E8DCB9',
                                    'common_room_forum_id' => null,
                                    'announcement_forum_id' => null,
                                    'is_active' => 1,
                                    'crest_image' => null,
                                    'hero_image' => null,
                                    'mascot_image' => null,
                                    'mascot_type' => null,
                                    'mascot_name' => null,
                                    'mascot_represents' => null,
                                ];
                                ?>

                                <?php require __DIR__ . '/partials/house-form-fields.php'; ?>

                                <div class="house-admin-actions">
                                    <button
                                        type="submit"
                                        class="button button-primary"
                                    >
                                        Create House
                                    </button>
                                </div>

                            </form>
                        </section>

                    <?php endif; ?>


                    <?php if ($editHouse !== null): ?>

                        <?php if ($canEditHouses): ?>

                            <section
                                class="house-admin-card"
                                aria-labelledby="edit-house-heading"
                            >
                                <header class="house-admin-titlebar">
                                    <p class="forum-admin-step">
                                        House Setup
                                    </p>

                                    <h2 id="edit-house-heading">
                                        Edit
                                        <?= e(
                                            (string) (
                                                $editHouse['display_name']
                                                ?? $editHouse['name']
                                            )
                                        ); ?>
                                    </h2>

                                    <p>
                                        Changes here control the House identity,
                                        Common Room configuration, and theme.
                                    </p>
                                </header>

                                <?php
                                $houseForm =
                                    $editHouse;
                                ?>

                                <form
                                    action="<?= e(url('admin/houses.php?edit_house=' . $editHouseId)); ?>"
                                    method="post"
                                    enctype="multipart/form-data"
                                    class="house-admin-form"
                                    data-house-form
                                >
                                    <?= csrf_field(); ?>

                                    <input
                                        type="hidden"
                                        name="form_action"
                                        value="update_house"
                                    >

                                    <input
                                        type="hidden"
                                        name="house_id"
                                        value="<?= (int) $editHouseId; ?>"
                                    >

                                    <?php require __DIR__ . '/partials/house-form-fields.php'; ?>

                                    <div class="house-admin-actions">

                                        <button
                                            type="submit"
                                            class="button button-primary"
                                        >
                                            Save House
                                        </button>

                                        <a
                                            href="<?= e(url('admin/houses.php')); ?>"
                                            class="button button-secondary"
                                        >
                                            Create / View Houses
                                        </a>

                                    </div>

                                </form>
                            </section>


                            <section
                                class="house-admin-card house-admin-danger-zone"
                                aria-labelledby="house-status-heading"
                            >
                                <header class="house-admin-titlebar">
                                    <p class="forum-admin-step">
                                        House Status
                                    </p>

                                    <h3 id="house-status-heading">
                                        Deactivate or Delete
                                    </h3>

                                    <p>
                                        Deactivation preserves House history.
                                        Permanent deletion is only available
                                        before a House has membership, points,
                                        or House Cup records.
                                    </p>
                                </header>

                                <div class="house-admin-actions">

                                    <form
                                        action="<?= e(url('admin/houses.php?edit_house=' . $editHouseId)); ?>"
                                        method="post"
                                    >
                                        <?= csrf_field(); ?>

                                        <input
                                            type="hidden"
                                            name="house_id"
                                            value="<?= (int) $editHouseId; ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="form_action"
                                            value="<?= (int) $editHouse['is_active'] === 1 ? 'deactivate_house' : 'reactivate_house'; ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="button button-secondary"
                                        >
                                            <?= (int) $editHouse['is_active'] === 1
                                                ? 'Deactivate House'
                                                : 'Reactivate House'; ?>
                                        </button>
                                    </form>


                                    <?php if (
                                        $canDeleteHouses
                                        && !admin_houses_has_history(
                                            $editHistoryCounts
                                        )
                                    ): ?>

                                        <form
                                            action="<?= e(url('admin/houses.php')); ?>"
                                            method="post"
                                            onsubmit="return confirm('Permanently delete this unused House? This cannot be undone.');"
                                        >
                                            <?= csrf_field(); ?>

                                            <input
                                                type="hidden"
                                                name="form_action"
                                                value="delete_house"
                                            >

                                            <input
                                                type="hidden"
                                                name="house_id"
                                                value="<?= (int) $editHouseId; ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="button house-admin-delete-button"
                                            >
                                                Delete Permanently
                                            </button>
                                        </form>

                                    <?php endif; ?>

                                </div>

                                <?php if (
                                    admin_houses_has_history(
                                        $editHistoryCounts
                                    )
                                ): ?>
                                    <p class="form-help">
                                        Permanent deletion is disabled because
                                        this House has historical records:
                                        <?= number_format($editHistoryCounts['memberships']); ?>
                                        membership record(s),
                                        <?= number_format($editHistoryCounts['cup_results']); ?>
                                        House Cup record(s), and
                                        <?= number_format($editHistoryCounts['points_entries']); ?>
                                        point ledger record(s).
                                    </p>
                                <?php endif; ?>

                            </section>

                        <?php endif; ?>

                    <?php elseif (
                        !$canCreateHouses
                    ): ?>

                        <section class="house-admin-card">
                            <p>
                                You can view Houses, but you do not currently
                                have permission to create one.
                            </p>
                        </section>

                    <?php endif; ?>

                </div>


                <aside
                    class="house-admin-card"
                    aria-labelledby="house-list-heading"
                >
                    <header class="house-admin-titlebar">
                        <p class="forum-admin-step">
                            Academy Houses
                        </p>

                        <h2 id="house-list-heading">
                            Current Houses
                        </h2>

                        <p>
                            Houses remain listed when inactive so staff can
                            preserve and review historical configuration.
                        </p>
                    </header>

                    <?php if ($houses === []): ?>

                        <div class="forum-admin-empty">
                            No Houses have been created yet.
                        </div>

                    <?php else: ?>

                        <div class="house-admin-list">

                            <?php foreach ($houses as $house): ?>
                                <?php
                                $houseId =
                                    (int) $house['id'];

                                $houseName =
                                    trim(
                                        (string) (
                                            $house['display_name']
                                            ?? ''
                                        )
                                    );

                                if ($houseName === '') {
                                    $houseName =
                                        (string) $house['name'];
                                }

                                $palette = [
                                    'Name' =>
                                        $house['display_color']
                                        ?? null,

                                    'Primary' =>
                                        $house['primary_color']
                                        ?? null,

                                    'Secondary' =>
                                        $house['secondary_color']
                                        ?? null,

                                    'Accent' =>
                                        $house['accent_color']
                                        ?? null,

                                    'Dark' =>
                                        $house['dark_neutral_color']
                                        ?? null,

                                    'Highlight' =>
                                        $house['highlight_color']
                                        ?? null,
                                ];
                                ?>

                                <article class="house-admin-list-card">

                                    <header>

                                        <div>
                                            <h3
                                                <?php if (
                                                    !empty(
                                                        $house['display_color']
                                                    )
                                                ): ?>
                                                    style="color: <?= e((string) $house['display_color']); ?>;"
                                                <?php endif; ?>
                                            >
                                                <?= e($houseName); ?>
                                            </h3>

                                            <p>
                                                /<?= e((string) $house['slug']); ?>
                                                ·
                                                <?= number_format(
                                                    (int) $house['active_member_count']
                                                ); ?>
                                                active member(s)
                                            </p>

                                            <span
                                                class="house-status-badge <?= (int) $house['is_active'] === 1 ? 'house-status-active' : 'house-status-inactive'; ?>"
                                            >
                                                <?= (int) $house['is_active'] === 1
                                                    ? 'Active'
                                                    : 'Inactive'; ?>
                                            </span>
                                        </div>

                                        <?php if ($canEditHouses): ?>
                                            <a
                                                href="<?= e(url('admin/houses.php?edit_house=' . $houseId)); ?>"
                                                class="button button-secondary"
                                            >
                                                Edit
                                            </a>
                                        <?php endif; ?>

                                    </header>


                                    <div class="house-admin-color-row">

                                        <?php foreach ($palette as $label => $color): ?>
                                            <?php if (
                                                is_string($color)
                                                && $color !== ''
                                            ): ?>
                                                <span class="house-admin-color-chip">
                                                    <span
                                                        class="house-admin-color-dot"
                                                        style="background: <?= e($color); ?>;"
                                                        aria-hidden="true"
                                                    ></span>

                                                    <?= e($label); ?>
                                                </span>
                                            <?php endif; ?>
                                        <?php endforeach; ?>

                                    </div>


                                    <p>
                                        Common Room:
                                        <?= $house['common_room_forum_title'] !== null
                                            ? e((string) $house['common_room_forum_title'])
                                            : 'Not selected'; ?>
                                    </p>

                                    <p>
                                        Announcements:
                                        <?= $house['announcement_forum_title'] !== null
                                            ? e((string) $house['announcement_forum_title'])
                                            : 'Not selected'; ?>
                                    </p>

                                </article>

                            <?php endforeach; ?>

                        </div>

                    <?php endif; ?>

                </aside>

            </div>

        </div>
    </section>

</main>


<script>
document.addEventListener('DOMContentLoaded', function () {
    document
        .querySelectorAll('[data-house-form]')
        .forEach(function (form) {

            var preview =
                form.querySelector(
                    '[data-house-theme-preview]'
                );

            if (!preview) {
                return;
            }

            var mappings = {
                primary_color: '--preview-primary',
                secondary_color: '--preview-secondary',
                accent_color: '--preview-accent',
                dark_neutral_color: '--preview-dark',
                highlight_color: '--preview-highlight'
            };

            Object.keys(mappings).forEach(function (name) {
                var textInput =
                    form.querySelector(
                        '[name="' + name + '"]'
                    );

                var colorInput =
                    form.querySelector(
                        '[data-color-picker="' + name + '"]'
                    );

                if (!textInput || !colorInput) {
                    return;
                }

                function applyValue(value) {
                    if (
                        /^#[0-9A-Fa-f]{6}$/.test(
                            value
                        )
                    ) {
                        colorInput.value =
                            value;

                        preview.style.setProperty(
                            mappings[name],
                            value
                        );
                    }
                }

                colorInput.addEventListener(
                    'input',
                    function () {
                        textInput.value =
                            colorInput.value
                                .toUpperCase();

                        applyValue(
                            textInput.value
                        );
                    }
                );

                textInput.addEventListener(
                    'input',
                    function () {
                        applyValue(
                            textInput.value
                        );
                    }
                );

                applyValue(
                    textInput.value
                );
            });
        });
});
</script>

<?php require INCLUDES_PATH . '/footer.php'; ?>
