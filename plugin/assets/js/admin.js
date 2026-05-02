/* ============================================================
   AGENCY HUB — ADMIN JS
   ============================================================ */

(function($) {
    'use strict';

    // --------------------------------------------------------
    // TABS
    // --------------------------------------------------------

    $(document).on('click', '.ah-tab', function() {
        var tab = $(this).data('tab');

        // Update tab buttons
        $('.ah-tab').removeClass('ah-tab--active');
        $(this).addClass('ah-tab--active');

        // Update panels
        $('.ah-panel').removeClass('ah-panel--active');
        $('#ah-tab-' + tab).addClass('ah-panel--active');
    });

    // --------------------------------------------------------
    // COPY TO CLIPBOARD
    // --------------------------------------------------------

    $(document).on('click', '.ah-copy-btn', function() {
        var targetId  = $(this).data('target');
        var $input    = $('#' + targetId);
        var origType  = $input.attr('type');
        var origText  = $(this).html();

        // Temporarily make password fields readable
        $input.attr('type', 'text');
        $input[0].select();
        document.execCommand('copy');
        $input.attr('type', origType);

        // Feedback
        $(this).html('<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg> Copied!');
        var $btn = $(this);
        setTimeout(function() {
            $btn.html(origText);
        }, 2000);
    });

    // --------------------------------------------------------
    // TOGGLE SECRET VISIBILITY
    // --------------------------------------------------------

    $(document).on('click', '.ah-toggle-secret', function() {
        var targetId = $(this).data('target');
        var $input   = $('#' + targetId);

        if ($input.attr('type') === 'password') {
            $input.attr('type', 'text');
            $(this).text('Hide');
        } else {
            $input.attr('type', 'password');
            $(this).text('Show');
        }
    });

    // --------------------------------------------------------
    // AUTO-DISMISS NOTICES
    // --------------------------------------------------------

    setTimeout(function() {
        $('.ah-notice').fadeOut(400);
    }, 4000);

})(jQuery);
