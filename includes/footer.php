<?php

declare(strict_types=1);

/**
 * Blackthorne Academy
 * Shared Footer
 */

?>

<footer class="site-footer">

    <div class="footer-inner">

        <div class="footer-column footer-brand-column">

            <a href="<?= e(HOME_URL); ?>" class="footer-logo" aria-label="Blackthorne Academy home">

                <img src="<?= e(asset('images/logo/logo_3.png')); ?>" alt="" class="footer-crest-image" width="100"
                    height="100" loading="lazy" decoding="async">

                <span class="brand-text footer-brand-text">

                    <span>
                        Blackthorne
                    </span>

                    <span>
                        Academy
                    </span>

                </span>

            </a>

        </div>


        <div class="footer-column footer-information-column">

            <nav class="footer-legal-links" aria-label="Legal information">

                <a href="<?= e(url('terms.php')); ?>">
                    Terms
                </a>

                <span aria-hidden="true">
                    |
                </span>

                <a href="<?= e(url('privacy.php')); ?>">
                    Privacy
                </a>

                <span aria-hidden="true">
                    |
                </span>

                <a href="<?= e(url('coppa.php')); ?>">
                    COPPA
                </a>

                <span aria-hidden="true">
                    |
                </span>

                <a href="<?= e(url('bullying.php')); ?>">
                    Bullying
                </a>

            </nav>


            <p class="footer-disclaimer">
                Blackthorne Academy is a fantasy-themed online educational
                and roleplay community. Academy content is intended for
                creative, recreational, and educational participation.
                Participation is subject to academy rules, policies, and
                applicable terms of use.
            </p>


            <p class="footer-copyright">
                &copy; 2026 Blackthorne Academy. All Rights Reserved.
            </p>

        </div>

    </div>

</footer>


<button type="button" id="back-to-top" class="back-to-top" aria-label="Back to top" title="Back to top"
    data-visible="false">
    <svg class="back-to-top-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path d="M6.75 10.5 12 5.25l5.25 5.25" fill="none" stroke="currentColor" stroke-width="1.8"
            stroke-linecap="round" stroke-linejoin="round" />
        <path d="M12 5.75v12.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
    </svg>
</button>


<?php

/*
|--------------------------------------------------------------------------
| JavaScript Cache Version
|--------------------------------------------------------------------------
|
| The file modification timestamp forces browsers to load the newest
| JavaScript after the file has been updated.
|
*/

$jsFile =
    ASSETS_PATH .
    '/js/main.js';

$jsVersion =
    file_exists($jsFile)
        ? filemtime($jsFile)
        : time();

?>


<script src="<?= e(
        asset(
            'js/main.js?v=' .
            $jsVersion
        )
    ); ?>"></script>

<script>
    (function() {
        'use strict';

        const backToTopButton = document.getElementById('back-to-top');

        if (!backToTopButton) {
            return;
        }

        const toggleBackToTop = function() {
            const shouldShow = window.scrollY > 350;

            backToTopButton.dataset.visible = shouldShow ? 'true' : 'false';
            backToTopButton.classList.toggle('is-visible', shouldShow);
            backToTopButton.setAttribute('aria-hidden', shouldShow ? 'false' : 'true');
            backToTopButton.tabIndex = shouldShow ? 0 : -1;
        };

        backToTopButton.addEventListener('click', function() {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });

        window.addEventListener('scroll', toggleBackToTop, {
            passive: true
        });
        toggleBackToTop();
    })();

</script>

</body>

</html>
