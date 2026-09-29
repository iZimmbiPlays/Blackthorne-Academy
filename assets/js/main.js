'use strict';

/*
|--------------------------------------------------------------------------
| Blackthorne Academy
| Main JavaScript
|--------------------------------------------------------------------------
*/

document.addEventListener('DOMContentLoaded', function () {
    /*
    |--------------------------------------------------------------------------
    | Mobile Navigation
    |--------------------------------------------------------------------------
    */

    const menuToggle = document.querySelector('.mobile-menu-toggle');

    const primaryMenu = document.querySelector('.primary-menu');

    if (menuToggle && primaryMenu) {
        menuToggle.addEventListener('click', function () {
            const isOpen = menuToggle.getAttribute('aria-expanded') === 'true';

            menuToggle.setAttribute('aria-expanded', isOpen ? 'false' : 'true');

            primaryMenu.classList.toggle('is-open');
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Header Search
    |--------------------------------------------------------------------------
    */

    const searchToggle = document.querySelector('.search-toggle');

    const searchPanel = document.querySelector('.header-search');

    const searchInput = document.querySelector('#academy-search');

    const searchClose = document.querySelector('.search-close');

    function openSearch() {
        if (!searchPanel || !searchToggle) {
            return;
        }

        searchPanel.hidden = false;

        searchToggle.setAttribute('aria-expanded', 'true');

        if (searchInput) {
            searchInput.focus();
        }
    }

    function closeSearch() {
        if (!searchPanel || !searchToggle) {
            return;
        }

        searchPanel.hidden = true;

        searchToggle.setAttribute('aria-expanded', 'false');

        searchToggle.focus();
    }

    if (searchToggle) {
        searchToggle.addEventListener('click', openSearch);
    }

    if (searchClose) {
        searchClose.addEventListener('click', closeSearch);
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && searchPanel && !searchPanel.hidden) {
            closeSearch();
        }
    });

    /*
    |--------------------------------------------------------------------------
    | Registered Homepage Sidebar Panels
    |--------------------------------------------------------------------------
    */

    const sidebarTitlebars = document.querySelectorAll('.sidebar-titlebar');

    sidebarTitlebars.forEach(function (titlebar) {
        const toggle = titlebar.querySelector('.sidebar-collapse-toggle');

        if (!toggle) {
            return;
        }

        const panelId = toggle.getAttribute('aria-controls');

        if (!panelId) {
            return;
        }

        const panel = document.getElementById(panelId);

        const marker = toggle.querySelector('.sidebar-toggle-mark');

        if (!panel) {
            return;
        }

        function setPanelState(isOpen) {
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');

            panel.hidden = !isOpen;

            if (marker) {
                marker.textContent = isOpen ? '−' : '+';
            }
        }

        function togglePanel() {
            const isOpen = toggle.getAttribute('aria-expanded') === 'true';

            setPanelState(!isOpen);
        }

        toggle.addEventListener('click', togglePanel);

        titlebar.addEventListener('click', function (event) {
            if (event.target.closest('a') || event.target.closest('.sidebar-collapse-toggle')) {
                return;
            }

            togglePanel();
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Eastern Time Display
    |--------------------------------------------------------------------------
    */

    const easternDateTime = document.querySelector('[data-eastern-datetime]');

    if (easternDateTime) {
        const easternFormatter = new Intl.DateTimeFormat('en-US', {
            timeZone: 'America/New_York',
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
            hour12: true,
        });

        function ordinalSuffix(day) {
            if (day >= 11 && day <= 13) {
                return day + 'th';
            }

            switch (day % 10) {
                case 1:
                    return day + 'st';

                case 2:
                    return day + 'nd';

                case 3:
                    return day + 'rd';

                default:
                    return day + 'th';
            }
        }

        function updateEasternDateTime() {
            const parts = easternFormatter.formatToParts(new Date());

            const dateParts = {};

            parts.forEach(function (part) {
                if (part.type !== 'literal') {
                    dateParts[part.type] = part.value;
                }
            });

            easternDateTime.textContent =
                dateParts.weekday +
                ', ' +
                dateParts.month +
                ' ' +
                ordinalSuffix(Number(dateParts.day)) +
                ', ' +
                dateParts.year +
                ' - ' +
                dateParts.hour +
                ':' +
                dateParts.minute +
                ' ' +
                dateParts.dayPeriod;
        }

        updateEasternDateTime();

        window.setInterval(updateEasternDateTime, 30000);
    }
});

/* ==========================================================================
   Blackthorne Forum Picker
   ========================================================================== */

(function () {
    'use strict';

    var openPicker = null;

    function textValue(value) {
        return String(value == null ? '' : value).trim();
    }

    function optionTitle(option) {
        return textValue(option.dataset.forumTitle || option.textContent);
    }

    function optionCategory(option) {
        return textValue(option.dataset.categoryTitle || '');
    }

    function optionDepth(option, optionMap, guard) {
        var explicitDepth = Number(option.dataset.depth);

        if (Number.isInteger(explicitDepth) && explicitDepth >= 0) {
            return explicitDepth;
        }

        var parentId = textValue(option.dataset.parentForumId);

        if (parentId === '' || parentId === '0' || !optionMap.has(parentId)) {
            return 0;
        }

        guard = guard || new Set();

        if (guard.has(option.value)) {
            return 0;
        }

        guard.add(option.value);

        return 1 + optionDepth(optionMap.get(parentId), optionMap, guard);
    }

    function enhanceForumPicker(select) {
        if (!select || select.dataset.forumPickerEnhanced === '1' || select.multiple) {
            return;
        }

        select.dataset.forumPickerEnhanced = '1';
        select.classList.add('forum-picker-native');

        var wrapper = document.createElement('div');
        wrapper.className = 'forum-picker';

        var trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'forum-picker-trigger';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');

        var current = document.createElement('span');
        current.className = 'forum-picker-current';

        var chevron = document.createElement('span');
        chevron.className = 'forum-picker-chevron';
        chevron.setAttribute('aria-hidden', 'true');
        chevron.textContent = '▾';

        trigger.appendChild(current);
        trigger.appendChild(chevron);

        var panel = document.createElement('div');
        panel.className = 'forum-picker-panel';
        panel.hidden = true;

        var searchWrap = document.createElement('div');
        searchWrap.className = 'forum-picker-search-wrap';

        var search = document.createElement('input');
        search.type = 'search';
        search.className = 'forum-picker-search';
        search.placeholder = 'Search forums…';
        search.autocomplete = 'off';
        search.setAttribute('aria-label', 'Search forums');

        searchWrap.appendChild(search);

        var optionsBox = document.createElement('div');
        optionsBox.className = 'forum-picker-options';
        optionsBox.setAttribute('role', 'listbox');

        panel.appendChild(searchWrap);
        panel.appendChild(optionsBox);

        select.insertAdjacentElement('afterend', wrapper);
        wrapper.appendChild(trigger);
        wrapper.appendChild(panel);

        var label = select.id ? document.querySelector('label[for="' + CSS.escape(select.id) + '"]') : null;

        if (label) {
            trigger.setAttribute('aria-label', textValue(label.textContent));

            label.addEventListener('click', function (event) {
                event.preventDefault();
                trigger.focus();
            });
        }

        function selectableOptions() {
            return Array.from(select.options).filter(function (option) {
                return !option.disabled && !option.hidden;
            });
        }

        function updateCurrent() {
            var selected = select.options[select.selectedIndex];

            if (!selected) {
                current.textContent = select.dataset.forumPickerPlaceholder || 'Choose a forum';
            } else {
                current.textContent = optionTitle(selected);
            }

            trigger.disabled = select.disabled;
        }

        function renderOptions(query) {
            query = textValue(query).toLowerCase();

            optionsBox.innerHTML = '';

            var options = selectableOptions();
            var optionMap = new Map();

            options.forEach(function (option) {
                if (option.value !== '' && option.value !== '0') {
                    optionMap.set(String(option.value), option);
                }
            });

            var rendered = [];
            var lastCategory = null;

            options.forEach(function (option) {
                var title = optionTitle(option);

                var category = optionCategory(option);

                var searchable = (title + ' ' + category + ' ' + textValue(option.dataset.forumPath)).toLowerCase();

                if (query !== '' && !searchable.includes(query)) {
                    return;
                }

                var isPlaceholder = option.value === '' || (option.value === '0' && option.dataset.forumId !== '0');

                if (!isPlaceholder && category !== '' && category !== lastCategory) {
                    var categoryRow = document.createElement('div');

                    categoryRow.className = 'forum-picker-category';

                    categoryRow.textContent = category;

                    optionsBox.appendChild(categoryRow);

                    lastCategory = category;
                }

                var button = document.createElement('button');

                button.type = 'button';
                button.className = 'forum-picker-option';

                button.setAttribute('role', 'option');

                button.dataset.value = option.value;

                var depth = isPlaceholder ? 0 : optionDepth(option, optionMap);

                button.style.setProperty('--forum-picker-depth', String(depth));

                if (isPlaceholder) {
                    button.classList.add('forum-picker-placeholder');
                } else if (depth === 0) {
                    button.classList.add('is-main-forum');
                } else {
                    button.classList.add('is-child-forum');
                }

                if (option.selected) {
                    button.classList.add('is-selected');

                    button.setAttribute('aria-selected', 'true');
                } else {
                    button.setAttribute('aria-selected', 'false');
                }

                var titleSpan = document.createElement('span');

                titleSpan.className = 'forum-picker-option-title';

                titleSpan.textContent = title;

                button.appendChild(titleSpan);

                if (!isPlaceholder && option.dataset.forumId) {
                    var meta = document.createElement('span');

                    meta.className = 'forum-picker-option-meta';

                    meta.textContent = 'ID ' + option.dataset.forumId;

                    button.appendChild(meta);
                }

                button.addEventListener('click', function () {
                    select.value = option.value;

                    select.dispatchEvent(
                        new Event('change', {
                            bubbles: true,
                        }),
                    );

                    updateCurrent();
                    close();
                    trigger.focus();
                });

                optionsBox.appendChild(button);

                rendered.push(button);
            });

            if (rendered.length === 0) {
                var empty = document.createElement('div');

                empty.className = 'forum-picker-empty';

                empty.textContent = query === '' ? 'No forums are available.' : 'No forums match your search.';

                optionsBox.appendChild(empty);
            }
        }

        function open() {
            if (trigger.disabled) {
                return;
            }

            if (openPicker && openPicker !== wrapper) {
                var previousTrigger = openPicker.querySelector('.forum-picker-trigger');

                var previousPanel = openPicker.querySelector('.forum-picker-panel');

                if (previousPanel) {
                    previousPanel.hidden = true;
                }

                if (previousTrigger) {
                    previousTrigger.setAttribute('aria-expanded', 'false');
                }

                openPicker.classList.remove('is-open');
            }

            renderOptions('');
            search.value = '';
            panel.hidden = false;
            wrapper.classList.add('is-open');
            trigger.setAttribute('aria-expanded', 'true');

            openPicker = wrapper;

            window.setTimeout(function () {
                search.focus();
            }, 0);
        }

        function close() {
            panel.hidden = true;
            wrapper.classList.remove('is-open');
            trigger.setAttribute('aria-expanded', 'false');

            if (openPicker === wrapper) {
                openPicker = null;
            }
        }

        trigger.addEventListener('click', function () {
            if (panel.hidden) {
                open();
            } else {
                close();
            }
        });

        search.addEventListener('input', function () {
            renderOptions(search.value);
        });

        search.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                close();
                trigger.focus();
            }
        });

        select.addEventListener('change', updateCurrent);

        document.addEventListener('click', function (event) {
            if (!panel.hidden && !wrapper.contains(event.target)) {
                close();
            }
        });

        updateCurrent();
    }

    function initializeForumPickers(root) {
        (root || document).querySelectorAll('select[data-forum-picker]').forEach(enhanceForumPicker);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initializeForumPickers(document);
        });
    } else {
        initializeForumPickers(document);
    }

    window.BlackthorneForumPicker = {
        refresh: function () {
            initializeForumPickers(document);

            document.querySelectorAll('select[data-forum-picker]').forEach(function (select) {
                select.dispatchEvent(
                    new Event('change', {
                        bubbles: false,
                    }),
                );
            });
        },
    };
})();
