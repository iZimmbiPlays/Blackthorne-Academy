<?php

declare(strict_types=1);

/**
 * Blackthorne Academy
 * General Helper Functions
 */


/*
|--------------------------------------------------------------------------
| Escape HTML Output
|--------------------------------------------------------------------------
|
| Any text that may contain user-provided or database-provided content
| should normally be escaped before being displayed in HTML.
|
| Example:
| echo e($user['display_name']);
|
*/

function e(?string $value): string
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}


/*
|--------------------------------------------------------------------------
| Build Application URLs
|--------------------------------------------------------------------------
|
| Creates a full URL using APP_URL.
|
| Example:
| url('login.php')
|
*/

function url(string $path = ''): string
{
    $path =
        ltrim(
            $path,
            '/'
        );


    if ($path === '') {

        return
            rtrim(
                APP_URL,
                '/'
            ) .
            '/';

    }


    return
        rtrim(
            APP_URL,
            '/'
        ) .
        '/' .
        $path;
}


/*
|--------------------------------------------------------------------------
| Build Asset URLs
|--------------------------------------------------------------------------
|
| Example:
| asset('css/style.css')
| asset('images/logo/logo.png')
|
*/

function asset(string $path): string
{
    return
        rtrim(
            ASSETS_URL,
            '/'
        ) .
        '/' .
        ltrim(
            $path,
            '/'
        );
}


/*
|--------------------------------------------------------------------------
| Redirect
|--------------------------------------------------------------------------
|
| Redirects the browser and immediately stops execution.
|
*/

function redirect(string $location): never
{
    header(
        'Location: ' .
        $location
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Request Method Helpers
|--------------------------------------------------------------------------
*/

function is_post(): bool
{
    return
        ($_SERVER['REQUEST_METHOD'] ?? 'GET')
        ===
        'POST';
}


function is_get(): bool
{
    return
        ($_SERVER['REQUEST_METHOD'] ?? 'GET')
        ===
        'GET';
}


/*
|--------------------------------------------------------------------------
| Form Input Helper
|--------------------------------------------------------------------------
|
| Retrieves a POST value safely.
|
| This does not escape the value for database storage.
| Prepared SQL statements handle database input.
|
| Escape values with e() when outputting them into HTML.
|
*/

function post_value(
    string $key,
    string $default = ''
): string {

    $value =
        $_POST[$key]
        ??
        $default;


    if (is_array($value)) {

        return
            $default;

    }


    return
        trim(
            (string) $value
        );
}


/*
|--------------------------------------------------------------------------
| Session Flash Messages
|--------------------------------------------------------------------------
|
| Flash messages exist for one page request.
|
| Example:
|
| set_flash('success', 'Your account has been created.');
|
| Then on the next page:
|
| $message = get_flash('success');
|
*/

function set_flash(
    string $key,
    string $message
): void {

    $_SESSION['_flash'][$key] =
        $message;

}


function get_flash(string $key): ?string
{
    if (
        !isset(
            $_SESSION['_flash'][$key]
        )
    ) {

        return null;

    }


    $message =
        (string) $_SESSION['_flash'][$key];


    unset(
        $_SESSION['_flash'][$key]
    );


    if (
        empty(
            $_SESSION['_flash']
        )
    ) {

        unset(
            $_SESSION['_flash']
        );

    }


    return
        $message;
}


/*
|--------------------------------------------------------------------------
| CSRF Protection
|--------------------------------------------------------------------------
|
| Forms that change data should include a token so another website cannot
| silently submit actions on behalf of a logged-in user.
|
*/

function csrf_token(): string
{
    if (
        !isset(
            $_SESSION['_csrf_token']
        )
        ||
        !is_string(
            $_SESSION['_csrf_token']
        )
        ||
        $_SESSION['_csrf_token'] === ''
    ) {

        $_SESSION['_csrf_token'] =
            bin2hex(
                random_bytes(32)
            );

    }


    return
        $_SESSION['_csrf_token'];
}


function csrf_field(): string
{
    return
        '<input type="hidden" name="_csrf_token" value="' .
        e(
            csrf_token()
        ) .
        '">';
}


function verify_csrf_token(?string $token): bool
{
    if (
        !isset(
            $_SESSION['_csrf_token']
        )
        ||
        !is_string(
            $_SESSION['_csrf_token']
        )
        ||
        !is_string($token)
    ) {

        return false;

    }


    return
        hash_equals(
            $_SESSION['_csrf_token'],
            $token
        );
}


/*
|--------------------------------------------------------------------------
| Require Valid CSRF Token
|--------------------------------------------------------------------------
|
| Call this when processing forms that modify data.
|
*/

function require_valid_csrf(): void
{
    $token =
        $_POST['_csrf_token']
        ??
        null;


    if (
        !is_string($token)
        ||
        !verify_csrf_token($token)
    ) {

        http_response_code(
            403
        );


        exit(
            'Invalid or expired form submission. Please go back and try again.'
        );

    }
}


/*
|--------------------------------------------------------------------------
| Generate Random Secure Tokens
|--------------------------------------------------------------------------
|
| Useful for:
| - email verification
| - password resets
| - remember-me tokens
| - invitations
|
*/

function generate_secure_token(
    int $bytes = 32
): string {

    if ($bytes < 16) {

        $bytes =
            16;

    }


    return
        bin2hex(
            random_bytes(
                $bytes
            )
        );
}


/*
|--------------------------------------------------------------------------
| Hash Security Tokens
|--------------------------------------------------------------------------
|
| Security tokens should generally be stored as hashes in the database
| rather than storing the original token.
|
*/

function hash_token(string $token): string
{
    return
        hash(
            'sha256',
            $token
        );
}


/*
|--------------------------------------------------------------------------
| Safe User-Configured CSS Color
|--------------------------------------------------------------------------
|
| House colors are configured by administrators and later printed into an
| inline color declaration. Only standard hex, rgb(a), hsl(a), and simple
| named-color values are accepted.
|
*/

function safe_css_color(?string $value): ?string
{
    $value =
        trim(
            (string) $value
        );


    if ($value === '') {

        return null;

    }


    $isHex =
        preg_match(
            '/^#[0-9a-f]{3,8}$/i',
            $value
        )
        ===
        1;


    $isFunctional =
        preg_match(
            '/^(?:rgb|rgba|hsl|hsla)\([0-9.%+,\s-]+\)$/i',
            $value
        )
        ===
        1;


    $isNamed =
        preg_match(
            '/^[a-z]{3,30}$/i',
            $value
        )
        ===
        1;


    return
        (
            $isHex
            ||
            $isFunctional
            ||
            $isNamed
        )
            ? $value
            : null;
}


/*
|--------------------------------------------------------------------------
| House-Colored Member Display Names
|--------------------------------------------------------------------------
|
| A member's active House display_color is the canonical site-wide color
| for their display name. Role colors remain available for role labels, but
| do not override an active House color on a member name.
|
*/

function house_visible_name(?array $house): string
{
    if ($house === null) {
        return '';
    }

    $displayName =
        trim(
            (string) (
                $house['display_name']
                ?? ''
            )
        );

    if ($displayName !== '') {
        return
            function_exists('mb_strtoupper')
                ? mb_strtoupper(
                    mb_substr(
                        $displayName,
                        0,
                        1,
                        'UTF-8'
                    ),
                    'UTF-8'
                )
                . mb_substr(
                    $displayName,
                    1,
                    null,
                    'UTF-8'
                )
                : ucfirst($displayName);
    }

    $name =
        trim(
            (string) (
                $house['name']
                ?? ''
            )
        );

    if ($name === '') {
        return '';
    }

    return
        function_exists('mb_strtoupper')
            ? mb_strtoupper(
                mb_substr(
                    $name,
                    0,
                    1,
                    'UTF-8'
                ),
                'UTF-8'
            )
            . mb_substr(
                $name,
                1,
                null,
                'UTF-8'
            )
            : ucfirst($name);
}


function user_house_display_color(int $userId): ?string
{
    global $pdo;

    static $cache = [];

    if ($userId <= 0) {
        return null;
    }

    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }

    try {
        $statement =
            $pdo->prepare(
                'SELECT h.display_color
                 FROM house_memberships hm
                 INNER JOIN houses h
                    ON h.id = hm.house_id
                 WHERE hm.user_id = :user_id
                   AND hm.membership_status = "active"
                   AND hm.left_at IS NULL
                   AND h.is_active = 1
                 ORDER BY hm.joined_at DESC, hm.id DESC
                 LIMIT 1'
            );

        $statement->execute([
            'user_id' => $userId,
        ]);

        $color = $statement->fetchColumn();

        $cache[$userId] =
            is_string($color)
                ? safe_css_color($color)
                : null;

    } catch (PDOException $exception) {
        error_log(
            'Blackthorne member House display-color lookup error: '
            . $exception->getMessage()
        );

        $cache[$userId] = null;
    }

    return $cache[$userId];
}


function user_display_name_style_attr(
    int $userId,
    ?string $knownHouseColor = null
): string {
    $color =
        $knownHouseColor !== null
            ? safe_css_color($knownHouseColor)
            : user_house_display_color($userId);

    if ($color === null) {
        return '';
    }

    return ' style="color: ' . e($color) . ';"';
}


/*
|--------------------------------------------------------------------------
| Safe Rich-Text Output
|--------------------------------------------------------------------------
|
| Forum editor content needs to retain useful formatting on homepage cards,
| while scripts, event handlers, unsafe URLs, and unsafe CSS are removed.
|
*/

function sanitize_rich_text(?string $html): string
{
    $html =
        trim(
            (string) $html
        );


    if ($html === '') {

        return '';

    }


    /*
    |--------------------------------------------------------------------------
    | Plain-Text Fallback
    |--------------------------------------------------------------------------
    |
    | If PHP's DOM extension is unavailable, display escaped plain text
    | instead of displaying unsafe HTML.
    |
    */

    if (
        !class_exists(
            'DOMDocument'
        )
    ) {

        return
            nl2br(
                e(
                    html_entity_decode(
                        strip_tags($html),
                        ENT_QUOTES | ENT_HTML5,
                        'UTF-8'
                    )
                )
            );

    }


    /*
    |--------------------------------------------------------------------------
    | Allowed Formatting
    |--------------------------------------------------------------------------
    */

    $allowedTags = [
        'p',
        'br',
        'strong',
        'b',
        'em',
        'i',
        'u',
        's',
        'blockquote',
        'ul',
        'ol',
        'li',
        'a',
        'span',
        'div',
        'h2',
        'h3',
        'h4',
        'hr',
        'img',
    ];


    $allowedStyleProperties = [
        'color',
        'background-color',
        'font-family',
        'font-size',
        'font-style',
        'font-weight',
        'line-height',
        'text-align',
        'text-decoration',
        'text-decoration-line',
    ];


    /*
    |--------------------------------------------------------------------------
    | Member Custom Content Blocks
    |--------------------------------------------------------------------------
    |
    | Members may build decorative profile/forum layouts, but only through
    | Blackthorne-controlled classes and a tightly restricted CSS subset.
    | Positioning, z-index, transforms, overflow tricks, external resources,
    | and arbitrary classes are intentionally not allowed.
    |
    */

    $allowedCustomBlockClasses = [
        'user-custom-box',
        'user-custom-banner',
        'user-custom-panel',
        'user-custom-columns',
        'user-custom-column',

        /*
         * Safe, preset member column ratios.
         * These are the only width-layout classes the editor may preserve.
         */
        'user-columns-50-50',
        'user-columns-60-40',
        'user-columns-40-60',
        'user-columns-70-30',
        'user-columns-30-70',
        'user-columns-75-25',
        'user-columns-25-75',
        'user-columns-80-20',
        'user-columns-20-80',

        'user-columns-33-33-33',
        'user-columns-25-50-25',
        'user-columns-20-60-20',
        'user-columns-40-30-30',
        'user-columns-30-40-30',
        'user-columns-30-30-40',

        /*
         * Safe forum-only YouTube placeholder. Thread pages convert this
         * validated video ID into a sandboxed YouTube embed at render time.
         */
        'forum-youtube-embed',
    ];


    $allowedCustomBlockStyleProperties = [
        'background-color',
        'color',
        'border-color',
        'border-style',
        'border-width',
        'border-radius',
        'padding',
        'margin',
        'width',
        'max-width',
        'text-align',
    ];


    /*
    |--------------------------------------------------------------------------
    | Load Rich Text
    |--------------------------------------------------------------------------
    */

    $document =
        new DOMDocument(
            '1.0',
            'UTF-8'
        );


    $previousLibxmlState =
        libxml_use_internal_errors(
            true
        );


    $document->loadHTML(
        '<?xml encoding="utf-8" ?>' .
        '<div id="blackthorne-rich-text-root">' .
        $html .
        '</div>',
        LIBXML_HTML_NOIMPLIED |
        LIBXML_HTML_NODEFDTD
    );


    libxml_clear_errors();


    libxml_use_internal_errors(
        $previousLibxmlState
    );


    $root =
        $document->getElementById(
            'blackthorne-rich-text-root'
        );


    if (
        !$root instanceof DOMElement
    ) {

        return '';

    }


    /*
    |--------------------------------------------------------------------------
    | Sanitize Elements and Attributes
    |--------------------------------------------------------------------------
    */

    $sanitizeNode =
        static function (DOMNode $node) use (
            &$sanitizeNode,
            $allowedTags,
            $allowedStyleProperties,
            $allowedCustomBlockClasses,
            $allowedCustomBlockStyleProperties
        ): void {

            foreach (
                iterator_to_array(
                    $node->childNodes
                )
                as $child
            ) {

                if (
                    !$child instanceof DOMElement
                ) {

                    continue;

                }


                $tagName =
                    strtolower(
                        $child->tagName
                    );


                /*
                |--------------------------------------------------------------------------
                | Remove Disallowed Elements
                |--------------------------------------------------------------------------
                |
                | Child text and permitted formatting are preserved, but the
                | disallowed wrapper itself is removed.
                |
                */

                if (
                    !in_array(
                        $tagName,
                        $allowedTags,
                        true
                    )
                ) {

                    $sanitizeNode(
                        $child
                    );


                    while (
                        $child->firstChild !== null
                    ) {

                        $node->insertBefore(
                            $child->firstChild,
                            $child
                        );

                    }


                    $node->removeChild(
                        $child
                    );


                    continue;

                }


                /*
                |--------------------------------------------------------------------------
                | Sanitize Attributes
                |--------------------------------------------------------------------------
                */

                foreach (
                    iterator_to_array(
                        $child->attributes
                    )
                    as $attribute
                ) {

                    $attributeName =
                        strtolower(
                            $attribute->name
                        );


                    /*
                    |--------------------------------------------------------------------------
                    | Safe Member Custom Block Classes
                    |--------------------------------------------------------------------------
                    */

                    if (
                        $attributeName === 'class'
                    ) {

                        if ($tagName !== 'div') {

                            $child->removeAttribute(
                                'class'
                            );

                            continue;

                        }


                        $requestedClasses =
                            preg_split(
                                '/\\s+/',
                                trim(
                                    $attribute->value
                                )
                            )
                            ?: [];


                        $safeClasses =
                            [];


                        foreach (
                            $requestedClasses
                            as $requestedClass
                        ) {

                            if (
                                in_array(
                                    $requestedClass,
                                    $allowedCustomBlockClasses,
                                    true
                                )
                            ) {

                                $safeClasses[] =
                                    $requestedClass;

                            }

                        }


                        $safeClasses =
                            array_values(
                                array_unique(
                                    $safeClasses
                                )
                            );


                        if ($safeClasses === []) {

                            $child->removeAttribute(
                                'class'
                            );

                        } else {

                            $child->setAttribute(
                                'class',
                                implode(
                                    ' ',
                                    $safeClasses
                                )
                            );

                        }


                        continue;

                    }


                    /*
                    |--------------------------------------------------------------------------
                    | Safe YouTube Placeholder ID
                    |--------------------------------------------------------------------------
                    */

                    if (
                        $tagName === 'div'
                        &&
                        $attributeName === 'data-youtube-id'
                        &&
                        in_array(
                            'forum-youtube-embed',
                            preg_split(
                                '/\\s+/',
                                trim(
                                    $child->getAttribute(
                                        'class'
                                    )
                                )
                            )
                            ?: [],
                            true
                        )
                    ) {
                        $videoId =
                            trim(
                                $attribute->value
                            );

                        if (
                            preg_match(
                                '/^[A-Za-z0-9_-]{11}$/',
                                $videoId
                            )
                            !== 1
                        ) {
                            $child->removeAttribute(
                                'data-youtube-id'
                            );
                        }

                        continue;
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | Safe Inline Formatting
                    |--------------------------------------------------------------------------
                    */

                    if (
                        $attributeName === 'style'
                    ) {

                        $safeDeclarations =
                            [];


                        foreach (
                            explode(
                                ';',
                                $attribute->value
                            )
                            as $declaration
                        ) {

                            if (
                                !str_contains(
                                    $declaration,
                                    ':'
                                )
                            ) {

                                continue;

                            }


                            [
                                $property,
                                $propertyValue,
                            ] =
                                array_map(
                                    'trim',
                                    explode(
                                        ':',
                                        $declaration,
                                        2
                                    )
                                );


                            $property =
                                strtolower(
                                    $property
                                );


                            $hasUnsafeCss =
                                preg_match(
                                    '/(?:url\s*\(|expression\s*\(|javascript:|data:|@import|behavior\s*:)/i',
                                    $propertyValue
                                )
                                ===
                                1;


                            $isCustomBlock =
                                $tagName === 'div'
                                &&
                                array_intersect(
                                    preg_split(
                                        '/\\s+/',
                                        trim(
                                            $child->getAttribute(
                                                'class'
                                            )
                                        )
                                    )
                                    ?: [],
                                    $allowedCustomBlockClasses
                                )
                                !== [];


                            $isSizedImage =



                                $tagName === 'img'



                                &&



                                in_array(



                                    $property,



                                    [



                                        'width',



                                        'max-width',



                                    ],



                                    true



                                );






                            $isAlignedImage =
                                $tagName === 'img'
                                &&
                                in_array(
                                    $property,
                                    [
                                        'display',
                                        'margin-left',
                                        'margin-right',
                                    ],
                                    true
                                );


                            $propertyAllowed =



                                in_array(



                                    $property,



                                    $allowedStyleProperties,



                                    true



                                )



                                ||



                                (



                                    $isCustomBlock



                                    &&



                                    in_array(



                                        $property,



                                        $allowedCustomBlockStyleProperties,



                                        true



                                    )



                                )



                                ||
                                $isSizedImage
                                ||
                                $isAlignedImage;





                            $valueAllowed =



                                true;





                            if ($isSizedImage) {



                            



                                $valueAllowed =



                                    preg_match(



                                        '/^(?:25%|30%|40%|50%|60%|70%|75%|80%|90%|100%)$/',



                                        $propertyValue



                                    )



                                    === 1;



                            



                            }




                            if ($isAlignedImage) {

                                if ($property === 'display') {

                                    $valueAllowed =
                                        $propertyValue === 'block';

                                } else {

                                    $valueAllowed =
                                        in_array(
                                            $propertyValue,
                                            [
                                                '0',
                                                '0px',
                                                'auto',
                                            ],
                                            true
                                        );

                                }

                            }


                            if (
                                $isCustomBlock
                                &&
                                in_array(
                                    $property,
                                    $allowedCustomBlockStyleProperties,
                                    true
                                )
                            ) {

                                switch ($property) {

                                    case 'border-style':

                                        $valueAllowed =
                                            in_array(
                                                strtolower(
                                                    $propertyValue
                                                ),
                                                [
                                                    'none',
                                                    'solid',
                                                    'dashed',
                                                    'dotted',
                                                    'double',
                                                ],
                                                true
                                            );
                                        break;


                                    case 'border-width':

                                        $valueAllowed =
                                            preg_match(
                                                '/^(?:0|[1-6]px)$/',
                                                $propertyValue
                                            )
                                            === 1;
                                        break;


                                    case 'border-radius':

                                        $valueAllowed =
                                            preg_match(
                                                '/^(?:0|(?:[1-9]|[12][0-9]|30)px)$/',
                                                $propertyValue
                                            )
                                            === 1;
                                        break;


                                    case 'padding':

                                        $valueAllowed =
                                            preg_match(
                                                '/^(?:0|(?:[1-9]|[1-3][0-9]|40)px)(?:\\s+(?:0|(?:[1-9]|[1-3][0-9]|40)px)){0,3}$/',
                                                $propertyValue
                                            )
                                            === 1;
                                        break;


                                    case 'margin':

                                        $valueAllowed =
                                            preg_match(
                                                '/^(?:(?:0|(?:[1-9]|[1-3][0-9]|40)px|auto))(?:\\s+(?:0|(?:[1-9]|[1-3][0-9]|40)px|auto)){0,3}$/',
                                                $propertyValue
                                            )
                                            === 1;
                                        break;


                                    case 'width':
                                    case 'max-width':

                                        $valueAllowed =
                                            preg_match(
                                                '/^(?:auto|100%|90%|80%|75%|70%|60%|50%|40%|30%|25%)$/',
                                                $propertyValue
                                            )
                                            === 1;
                                        break;


                                    case 'text-align':

                                        $valueAllowed =
                                            in_array(
                                                strtolower(
                                                    $propertyValue
                                                ),
                                                [
                                                    'left',
                                                    'center',
                                                    'right',
                                                ],
                                                true
                                            );
                                        break;


                                    case 'background-color':
                                    case 'border-color':
                                    case 'color':

                                        $valueAllowed =
                                            safe_css_color(
                                                $propertyValue
                                            )
                                            !== null
                                            ||
                                            strtolower(
                                                $propertyValue
                                            )
                                            === 'transparent';
                                        break;

                                }

                            }


                            if (
                                $propertyAllowed
                                &&
                                $valueAllowed
                                &&
                                !$hasUnsafeCss
                                &&
                                strlen($propertyValue) <= 100
                            ) {

                                $safeDeclarations[] =
                                    $property .
                                    ': ' .
                                    $propertyValue;

                            }

                        }


                        if (
                            $safeDeclarations === []
                        ) {

                            $child->removeAttribute(
                                'style'
                            );

                        } else {

                            $child->setAttribute(
                                'style',
                                implode(
                                    '; ',
                                    $safeDeclarations
                                )
                            );

                        }


                        continue;

                    }


                    /*
                    |--------------------------------------------------------------------------
                    | Safe Images
                    |--------------------------------------------------------------------------
                    |
                    | Images may come from Blackthorne's own upload directory or from an
                    | HTTPS URL entered by the member. Data URIs, javascript: URLs, blob
                    | URLs, inline event handlers, srcset, and arbitrary attributes are
                    | intentionally rejected.
                    |
                    */

                    if (
                        $tagName === 'img'
                        &&
                        $attributeName === 'src'
                    ) {

                        $src =
                            trim(
                                $attribute->value
                            );


                        $isSafeSrc =
                            preg_match(
                                '#^(?:https://|/)#i',
                                $src
                            )
                            ===
                            1;


                        if (!$isSafeSrc) {

                            $child->removeAttribute(
                                'src'
                            );

                        }


                        continue;

                    }


                    if (
                        $tagName === 'img'
                        &&
                        in_array(
                            $attributeName,
                            [
                                'alt',
                                'title',
                                'width',
                                'height',
                                'loading',
                            ],
                            true
                        )
                    ) {

                        if (
                            in_array(
                                $attributeName,
                                [
                                    'width',
                                    'height',
                                ],
                                true
                            )
                        ) {

                            $dimension =
                                (int) $attribute->value;


                            if (
                                $dimension < 1
                                ||
                                $dimension > 2400
                            ) {

                                $child->removeAttribute(
                                    $attributeName
                                );

                            } else {

                                $child->setAttribute(
                                    $attributeName,
                                    (string) $dimension
                                );

                            }

                        } elseif (
                            $attributeName === 'loading'
                        ) {

                            $child->setAttribute(
                                'loading',
                                'lazy'
                            );

                        } else {

                            $child->setAttribute(
                                $attributeName,
                                mb_substr(
                                    trim(
                                        $attribute->value
                                    ),
                                    0,
                                    255,
                                    'UTF-8'
                                )
                            );

                        }


                        continue;

                    }


                    /*
                    |--------------------------------------------------------------------------
                    | Safe Links
                    |--------------------------------------------------------------------------
                    */

                    if (
                        $tagName === 'a'
                        &&
                        $attributeName === 'href'
                    ) {

                        $href =
                            trim(
                                $attribute->value
                            );


                        $isSafeHref =
                            preg_match(
                                '#^(?:https?://|mailto:|/|\#)#i',
                                $href
                            )
                            ===
                            1;


                        if (!$isSafeHref) {

                            $child->removeAttribute(
                                'href'
                            );

                        }


                        continue;

                    }


                    if (
                        $tagName === 'a'
                        &&
                        in_array(
                            $attributeName,
                            [
                                'title',
                                'target',
                                'rel',
                            ],
                            true
                        )
                    ) {

                        continue;

                    }


                    /*
                    |--------------------------------------------------------------------------
                    | Remove Every Other Attribute
                    |--------------------------------------------------------------------------
                    */

                    $child->removeAttribute(
                        $attributeName
                    );

                }


                /*
                |--------------------------------------------------------------------------
                | Secure New-Tab Links
                |--------------------------------------------------------------------------
                */

                if (
                    $tagName === 'a'
                    &&
                    $child->getAttribute(
                        'target'
                    )
                    ===
                    '_blank'
                ) {

                    $child->setAttribute(
                        'rel',
                        'noopener noreferrer'
                    );

                }


                $sanitizeNode(
                    $child
                );

            }

        };


    $sanitizeNode(
        $root
    );


    /*
    |--------------------------------------------------------------------------
    | Return Sanitized Inner HTML
    |--------------------------------------------------------------------------
    */

    $safeHtml =
        '';


    foreach (
        $root->childNodes
        as $child
    ) {

        $safeHtml .=
            $document->saveHTML(
                $child
            );

    }


    return
        $safeHtml;
}