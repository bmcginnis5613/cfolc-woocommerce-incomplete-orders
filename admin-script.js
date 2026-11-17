jQuery(document).ready(function ($) {
    'use strict';

    // Keep the calendar range valid while allowing dates in either direction.
    var $dateFrom = $('#ftwci-date-from');
    var $dateTo = $('#ftwci-date-to');

    function syncDateRangeLimits() {
        $dateFrom.attr('max', $dateTo.val() || null);
        $dateTo.attr('min', $dateFrom.val() || null);
    }

    $dateFrom.add($dateTo).on('change', syncDateRangeLimits);
    syncDateRangeLimits();

    function closeMultiselect($multiselect, returnFocus) {
        var $toggle = $multiselect.find('.wco-multiselect-toggle');

        $multiselect.find('.wco-multiselect-panel').prop('hidden', true);
        $toggle.attr('aria-expanded', 'false');
        $multiselect.find('.wco-multiselect-search').val('');
        $multiselect.find('.wco-multiselect-option').show();

        if (returnFocus) {
            $toggle.trigger('focus');
        }
    }

    function closeAllMultiselects($except) {
        $('.wco-multiselect').each(function () {
            var $multiselect = $(this);
            if (!$except || !$multiselect.is($except)) {
                closeMultiselect($multiselect, false);
            }
        });
    }

    $('.wco-multiselect').each(function () {
        var $multiselect = $(this);
        var $toggle = $multiselect.find('.wco-multiselect-toggle');
        var $panel = $multiselect.find('.wco-multiselect-panel');
        var $search = $multiselect.find('.wco-multiselect-search');

        function updateSummary() {
            var $selected = $multiselect.find('.wco-multiselect-checkbox:checked');
            var summary = $multiselect.data('default-summary');

            if ($selected.length === 1) {
                summary = $selected.first().closest('.wco-multiselect-option')
                    .find('.wco-multiselect-option-name').text();
            } else if ($selected.length > 1) {
                summary = $selected.length + ' ' + $multiselect.data('plural-label');
            }

            $toggle.find('span').first().text(summary);
        }

        $toggle.on('click', function () {
            var willOpen = $panel.prop('hidden');
            closeAllMultiselects($multiselect);
            $panel.prop('hidden', !willOpen);
            $toggle.attr('aria-expanded', willOpen ? 'true' : 'false');
            if (willOpen) {
                $search.trigger('focus');
            }
        });

        $multiselect.on('click', function (event) {
            event.stopPropagation();
        });

        $multiselect.on('change', '.wco-multiselect-checkbox', updateSummary);

        $multiselect.find('.wco-multiselect-clear').on('click', function () {
            $multiselect.find('.wco-multiselect-checkbox').prop('checked', false);
            updateSummary();
        });

        $search.on('input', function () {
            var query = $(this).val().toLowerCase().trim();
            $multiselect.find('.wco-multiselect-option').each(function () {
                $(this).toggle($(this).text().toLowerCase().indexOf(query) !== -1);
            });
        });

        updateSummary();
    });

    $(document).on('click', function () {
        closeAllMultiselects();
    });

    $(document).on('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }

        $('.wco-multiselect').each(function () {
            var $multiselect = $(this);
            if (!$multiselect.find('.wco-multiselect-panel').prop('hidden')) {
                closeMultiselect($multiselect, true);
            }
        });
    });

    var columnPrefsCookie = 'ftwci_column_visibility';

    function readColumnPrefsCookie() {
        var cookiePrefix = columnPrefsCookie + '=';
        var cookies = document.cookie ? document.cookie.split(';') : [];

        for (var i = 0; i < cookies.length; i++) {
            var cookie = cookies[i].trim();
            if (cookie.indexOf(cookiePrefix) !== 0) {
                continue;
            }

            try {
                var value = JSON.parse(decodeURIComponent(cookie.substring(cookiePrefix.length)));
                return value && typeof value === 'object' ? value : null;
            } catch (error) {
                return null;
            }
        }

        return null;
    }

    function writeColumnPrefsCookie(preferences) {
        var cookie = columnPrefsCookie + '=' + encodeURIComponent(JSON.stringify(preferences)) +
            '; Max-Age=31536000; Path=/; SameSite=Lax';

        if (window.location.protocol === 'https:') {
            cookie += '; Secure';
        }

        document.cookie = cookie;
    }

    function collectColumnPreferences() {
        var preferences = {};

        $('.usr-column-toggle').each(function () {
            preferences[$(this).data('column')] = $(this).is(':checked');
        });

        return preferences;
    }

    function applyColumnPreferences(preferences) {
        $.each(preferences, function (column, visible) {
            var $checkbox = $('.usr-column-toggle').filter(function () {
                return $(this).data('column') === column;
            });
            if (!$checkbox.length || typeof visible !== 'boolean') {
                return;
            }

            $checkbox.prop('checked', visible);
            $('.usr-col-' + column).toggle(visible);
        });
    }

    // ── Column visibility toggles ─────────────────────────────────────────
    $('.usr-column-toggle').on('change', function () {
        var $checkbox  = $(this);
        var column     = $checkbox.data('column');
        var visible    = $checkbox.is(':checked');
        var colClass   = '.usr-col-' + column;

        // Show / hide all cells (th + td) for this column
        if (visible) {
            $(colClass).show();
        } else {
            $(colClass).hide();
        }

        writeColumnPrefsCookie(collectColumnPreferences());

    });

    // ── Apply saved preferences on page load ─────────────────────────────
    var cookiePreferences = readColumnPrefsCookie();

    if (cookiePreferences) {
        applyColumnPreferences(cookiePreferences);
    } else {
        writeColumnPrefsCookie(collectColumnPreferences());
    }
});
