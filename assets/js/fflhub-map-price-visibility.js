(function () {
  var modal = document.getElementById("fflhub-email-for-quote-modal");
  if (!modal) {
    return;
  }

  var openButtons = document.querySelectorAll("[data-fflhub-quote-open='1']");
  var closeButtons = modal.querySelectorAll("[data-fflhub-quote-close='1']");
  var firstInput = modal.querySelector("input[name='fflhub_first_name']");
  var lastFocused = null;

  function openModal(focusSource) {
    lastFocused = focusSource || document.activeElement;
    modal.classList.add("is-open");
    modal.setAttribute("aria-hidden", "false");
    document.body.classList.add("fflhub-quote-modal-open");

    if (firstInput) {
      firstInput.focus();
    }
  }

  function closeModal() {
    modal.classList.remove("is-open");
    modal.setAttribute("aria-hidden", "true");
    document.body.classList.remove("fflhub-quote-modal-open");

    if (lastFocused && typeof lastFocused.focus === "function") {
      lastFocused.focus();
    }
  }

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
})();
