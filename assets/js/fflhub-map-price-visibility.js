(function () {
  var modal = document.getElementById("fflhub-email-for-quote-modal");
  if (!modal) {
    return;
  }

  var openButtons = document.querySelectorAll("[data-fflhub-quote-open='1']");
  var closeButtons = modal.querySelectorAll("[data-fflhub-quote-close='1']");
  var firstInput = modal.querySelector("input[name='fflhub_first_name']");
  var form = modal.querySelector(".fflhub-email-for-quote-form");
  var rootElement = document.documentElement;
  var lastFocused = null;
  var scrollLockState = null;

  function lockPageScroll() {
    if (scrollLockState) {
      return;
    }

    var currentScrollY = window.pageYOffset || window.scrollY || 0;
    scrollLockState = {
      scrollY: currentScrollY,
      position: document.body.style.position || "",
      top: document.body.style.top || "",
      left: document.body.style.left || "",
      right: document.body.style.right || "",
      width: document.body.style.width || "",
      overflow: document.body.style.overflow || "",
      touchAction: document.body.style.touchAction || ""
    };

    rootElement.classList.add("fflhub-quote-modal-open");
    document.body.classList.add("fflhub-quote-modal-open");
    document.body.style.position = "fixed";
    document.body.style.top = "-" + String(currentScrollY) + "px";
    document.body.style.left = "0";
    document.body.style.right = "0";
    document.body.style.width = "100%";
    document.body.style.overflow = "hidden";
    document.body.style.touchAction = "none";
  }

  function unlockPageScroll() {
    if (!scrollLockState) {
      rootElement.classList.remove("fflhub-quote-modal-open");
      document.body.classList.remove("fflhub-quote-modal-open");
      return;
    }

    var previousScrollY = scrollLockState.scrollY;
    document.body.style.position = scrollLockState.position;
    document.body.style.top = scrollLockState.top;
    document.body.style.left = scrollLockState.left;
    document.body.style.right = scrollLockState.right;
    document.body.style.width = scrollLockState.width;
    document.body.style.overflow = scrollLockState.overflow;
    document.body.style.touchAction = scrollLockState.touchAction;

    rootElement.classList.remove("fflhub-quote-modal-open");
    document.body.classList.remove("fflhub-quote-modal-open");

    scrollLockState = null;
    window.scrollTo(0, previousScrollY);
  }

  function shouldPreventOutsideScroll(target) {
    var dialog = modal.querySelector(".fflhub-email-for-quote-modal__dialog");
    if (!dialog) {
      return true;
    }

    return !dialog.contains(target);
  }

  function openModal(focusSource) {
    lastFocused = focusSource || document.activeElement;
    modal.classList.add("is-open");
    modal.setAttribute("aria-hidden", "false");
    lockPageScroll();

    if (firstInput) {
      firstInput.focus();
    }
  }

  function closeModal() {
    modal.classList.remove("is-open");
    modal.setAttribute("aria-hidden", "true");
    unlockPageScroll();

    if (lastFocused && typeof lastFocused.focus === "function") {
      lastFocused.focus();
    }
  }

  modal.addEventListener(
    "touchmove",
    function (event) {
      if (!modal.classList.contains("is-open")) {
        return;
      }

      if (shouldPreventOutsideScroll(event.target)) {
        event.preventDefault();
      }
    },
    { passive: false }
  );

  for (var i = 0; i < openButtons.length; i += 1) {
    openButtons[i].addEventListener("click", function (event) {
      event.preventDefault();
      openModal(event.currentTarget);
    });
  }

  for (var j = 0; j < closeButtons.length; j += 1) {
    closeButtons[j].addEventListener("click", function (event) {
      event.preventDefault();
      closeModal();
    });
  }

  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape" && modal.classList.contains("is-open")) {
      closeModal();
    }
  });

  if (modal.getAttribute("data-open-on-load") === "1") {
    openModal(null);
  }

  if (form) {
    form.addEventListener("submit", function (event) {
      if (form.getAttribute("data-submitting") === "1") {
        event.preventDefault();
        return false;
      }

      form.setAttribute("data-submitting", "1");
      var submitButton = form.querySelector(".fflhub-email-for-quote-submit");
      if (submitButton) {
        var submittingLabel = submitButton.getAttribute("data-submitting-label") || "Sending...";
        submitButton.setAttribute("aria-disabled", "true");
        submitButton.disabled = true;
        submitButton.textContent = submittingLabel;
      }
      return true;
    });
  }
})();
