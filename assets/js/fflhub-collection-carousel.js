(function() {
    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
            return;
        }

        callback();
    }

    function cardStep(track) {
        var firstCard = track.querySelector('.fflhub-collection-carousel__card');
        if (!firstCard) {
            return track.clientWidth;
        }

        var style = window.getComputedStyle(track);
        var gap = parseFloat(style.columnGap || style.gap || '0') || 0;

        return firstCard.getBoundingClientRect().width + gap;
    }

    function scrollByStep(track, direction) {
        var step = cardStep(track) * direction;
        var maxScroll = track.scrollWidth - track.clientWidth;
        var nextLeft = track.scrollLeft + step;

        if (direction > 0 && nextLeft >= maxScroll - 4) {
            nextLeft = 0;
        } else if (direction < 0 && nextLeft <= 4) {
            nextLeft = maxScroll;
        }

        track.scrollTo({
            left: nextLeft,
            behavior: 'smooth'
        });
    }

    function setupCarousel(carousel) {
        var track = carousel.querySelector('.fflhub-collection-carousel__track');
        if (!track) {
            return;
        }

        var previous = carousel.querySelector('.fflhub-collection-carousel__button--prev');
        var next = carousel.querySelector('.fflhub-collection-carousel__button--next');
        var canAutoplay = carousel.getAttribute('data-autoplay') === '1';
        var intervalMs = parseInt(carousel.getAttribute('data-interval') || '4500', 10);
        var prefersReducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var timer = null;

        if (previous) {
            previous.addEventListener('click', function() {
                scrollByStep(track, -1);
            });
        }

        if (next) {
            next.addEventListener('click', function() {
                scrollByStep(track, 1);
            });
        }

        function stop() {
            if (timer) {
                window.clearInterval(timer);
                timer = null;
            }
        }

        function start() {
            stop();
            if (!canAutoplay || prefersReducedMotion || track.scrollWidth <= track.clientWidth) {
                return;
            }

            timer = window.setInterval(function() {
                scrollByStep(track, 1);
            }, Math.max(1500, intervalMs));
        }

        carousel.addEventListener('mouseenter', stop);
        carousel.addEventListener('mouseleave', start);
        carousel.addEventListener('focusin', stop);
        carousel.addEventListener('focusout', start);
        window.addEventListener('resize', start);

        start();
    }

    ready(function() {
        document.querySelectorAll('.fflhub-collection-carousel').forEach(setupCarousel);
    });
})();
