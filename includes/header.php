<?php

declare(strict_types=1);

/**
 * Blackthorne Academy
 * Shared Header
 */

$pageTitle = $pageTitle ?? APP_NAME;

$pageDescription = $pageDescription
    ?? 'Blackthorne Academy is an immersive online academy for the study of witchcraft, magical disciplines, and interactive academic roleplay.';

$pageCanonical = $pageCanonical ?? HOME_URL;

$robots = $robots ?? 'index, follow';

?>
<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title><?= e($pageTitle); ?></title>

    <meta
        name="description"
        content="<?= e($pageDescription); ?>"
    >

    <meta
        name="robots"
        content="<?= e($robots); ?>"
    >

    <link
        rel="canonical"
        href="<?= e($pageCanonical); ?>"
    >

    <meta
        property="og:site_name"
        content="<?= e(APP_NAME); ?>"
    >

    <meta
        property="og:title"
        content="<?= e($pageTitle); ?>"
    >

    <meta
        property="og:description"
        content="<?= e($pageDescription); ?>"
    >

    <meta
        property="og:type"
        content="website"
    >

    <meta
        property="og:url"
        content="<?= e($pageCanonical); ?>"
    >

    <meta
        name="twitter:card"
        content="summary_large_image"
    >

    <meta
        name="theme-color"
        content="#120b17"
    >

    <meta
        name="color-scheme"
        content="dark"
    >

    <!--
        Critical first-paint styles.
        These intentionally duplicate only the minimum layout needed before
        the full stylesheet is parsed, preventing bright/default paint and
        header/content snapping during page navigation.
    -->
    <style>
        html {
            background: #120b17;
        }

        body {
            margin: 0;
            min-height: 100vh;
            padding-top: 88px;
            background: #120b17;
            color: #f6f0f7;
        }

        .site-header {
            position: fixed;
            top: 0;
            right: 0;
            left: 0;
            z-index: 1000;
            min-height: 88px;
            background: #120a18;
        }

        @media (max-width: 640px) {
            body {
                padding-top: 76px;
            }

            .site-header {
                min-height: 76px;
            }
        }
    </style>

    <?php
    $cssFile = ASSETS_PATH . '/css/style.css';
    $cssVersion = file_exists($cssFile)
        ? filemtime($cssFile)
        : time();
    ?>

    <link
        rel="stylesheet"
        href="<?= e(asset('css/style.css?v=' . $cssVersion)); ?>"
    >

    <link
        rel="icon"
        type="image/png"
        href="<?= e(asset('images/favicon.png')); ?>?v=3"
    >

    <link
        rel="shortcut icon"
        type="image/png"
        href="<?= e(asset('images/favicon.png')); ?>?v=3"
    >

    <link
        rel="apple-touch-icon"
        href="<?= e(asset('images/favicon.png')); ?>?v=3"
    >

</head>

<body>

<a class="skip-link" href="#main-content">
    Skip to main content
</a>

<header class="site-header">

    <div class="header-inner">

        <a
            href="<?= e(HOME_URL); ?>"
            class="site-brand"
            aria-label="Blackthorne Academy home"
        >

            <!--
                Crest Logo Brand Image.
            -->

            <img
                src="<?= e(asset('images/logo/logo_3.png')); ?>"
                alt=""
                class="brand-crest-image"
                width="100"
                height="100"
                loading="eager"
                decoding="sync"
                fetchpriority="high"
            >

            <span class="brand-text">
                <span>Blackthorne</span>
                <span>Academy</span>
            </span>

        </a>

        <?php require INCLUDES_PATH . '/navigation.php'; ?>

    </div>

</header>
