(function ($) {
    function openModal(panelId) {
        var $modal = $('#fflhub-modal');
        var $panels = $modal.find('.fflhub-modal-panel');

        // Hide all panels first.
        $panels
            .removeClass('is-active')
            .attr('aria-hidden', 'true');

        // Show the requested panel.
        var $targetPanel = $('#' + panelId);
        if ($targetPanel.length) {
            $targetPanel
                .addClass('is-active')
                .attr('aria-hidden', 'false');
        }

        // Open the modal.
        $modal
            .addClass('is-open')
            .attr('aria-hidden', 'false');

        // Lock body scroll (simple way).
        $('body').addClass('fflhub-modal-open');
    }

    function closeModal() {
        var $modal = $('#fflhub-modal');

        $modal
            .removeClass('is-open')
            .attr('aria-hidden', 'true');

        $('body').removeClass('fflhub-modal-open');
    }

    $(document).ready(function () {
        // Open modal when clicking a distributor card.
        $('.fflhub-distributor-card').on('click', function () {
            var panelId = $(this).data('fflhub-target');
            if (panelId) {
                openModal(panelId);
            }
        });

        // Close modal when clicking overlay or close button.
        $(document).on('click', '[data-fflhub-close="true"]', function () {
            closeModal();
        });

        // Close on ESC key.
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });
    });
})(jQuery);
