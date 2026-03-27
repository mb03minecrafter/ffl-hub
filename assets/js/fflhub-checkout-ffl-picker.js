// assets/js/fflhub-checkout-ffl-picker.js
(function () {
  function ready(fn) {
    if (document.readyState !== "loading") {
      fn();
      return;
    }
    document.addEventListener("DOMContentLoaded", fn);
  }

  function getReceivingInput() {
    const receivingFieldContainer = document.querySelector(
      '[data-fflhub-receiving-ffl-input="1"]'
    );
    if (!receivingFieldContainer) {
      return null;
    }

    if (receivingFieldContainer.tagName.toLowerCase() === "input") {
      return receivingFieldContainer;
    }

    return receivingFieldContainer.querySelector("input");
  }

  function placeMapAboveReceivingField() {
    const mapWrapper = document.querySelector(".fflhub-checkout-map-wrapper");
    const receivingInput = document.querySelector(
      '[data-fflhub-receiving-ffl-input="1"]'
    );

    if (!mapWrapper || !receivingInput) {
      return;
    }

    const fieldRow =
      receivingInput.closest(".wc-block-components-text-input") ||
      receivingInput.closest(".wc-block-components-checkout-step__container") ||
      receivingInput.parentElement;

    if (!fieldRow || !fieldRow.parentNode) {
      return;
    }

    if (mapWrapper.nextElementSibling === fieldRow) {
      return;
    }

    fieldRow.parentNode.insertBefore(mapWrapper, fieldRow);
  }

  function formatAddress(dealer) {
    return (
      dealer.street +
      ", " +
      dealer.city +
      ", " +
      dealer.state +
      " " +
      dealer.postal_code
    );
  }

  function updateMap(mapIframe, dealer) {
    if (!dealer || !mapIframe) {
      return;
    }

    const query =
      dealer.fullAddress ||
      formatAddress(dealer) ||
      dealer.ffl_number ||
      dealer.name;

    const url =
      "https://www.google.com/maps?q=" +
      encodeURIComponent(query) +
      "&z=13&output=embed";

    mapIframe.setAttribute("src", url);
  }

  function transformApiFFL(apiFFL) {
    const premise = apiFFL.premise || {};

    const street = premise.street || "";
    const city = premise.city || "";
    const state = premise.state || "";
    const postal_code = premise.zip || "";
    const fullAddress = [street, city, state, postal_code]
      .filter(Boolean)
      .join(", ");

    return {
      ffl_number: apiFFL.ffl_number,
      name: apiFFL.name || "",
      street: street,
      city: city,
      state: state,
      postal_code: postal_code,
      phone: apiFFL.phone || "",
      fullAddress: fullAddress,
    };
  }

  function initPicker(picker) {
    if (!(picker instanceof HTMLElement)) {
      return;
    }

    // Idempotent init: Woo checkout can re-render portions of the DOM.
    if (picker.dataset.fflhubInit === "1") {
      return;
    }
    picker.dataset.fflhubInit = "1";

    const zipInput = picker.querySelector("#fflhub-ffl-picker-zip");
    const searchButton = picker.querySelector(".fflhub-ffl-picker__search-button");
    const listContainer = picker.querySelector(".fflhub-ffl-picker__list-inner");
    const placeholderText = picker.querySelector(".fflhub-ffl-picker__placeholder");
    const mapIframe = picker.querySelector(".fflhub-ffl-picker__map-iframe");
    const hiddenSelected = picker.querySelector("#fflhub-ffl-picker-selected");

    if (!listContainer || !mapIframe) {
      return;
    }

    const settings = window.fflhubFFLPickerSettings || {};
    const restUrl = typeof settings.restUrl === "string" ? settings.restUrl : "";
    const defaultLimit = parseInt(settings.defaultLimit || 50, 10);

    let lastTriggerTs = 0;
    let inFlightController = null;

    function showMessage(message) {
      listContainer.innerHTML = "";
      const msg = document.createElement("p");
      msg.className = "fflhub-ffl-picker__placeholder";
      msg.textContent = message;
      listContainer.appendChild(msg);
    }

    function renderDealers(dealers) {
      listContainer.innerHTML = "";

      if (!dealers || dealers.length === 0) {
        showMessage(
          "No FFLs found near that ZIP. Please double-check your ZIP Code or try another."
        );
        return;
      }

      dealers.forEach(function (dealer) {
        const li = document.createElement("li");
        li.className = "fflhub-ffl-picker__dealer";
        li.dataset.fflId = dealer.ffl_number;

        const nameEl = document.createElement("strong");
        nameEl.textContent = dealer.name || dealer.ffl_number;

        const addrEl = document.createElement("div");
        addrEl.className = "fflhub-ffl-picker__dealer-address";
        addrEl.textContent = formatAddress(dealer);

        const metaEl = document.createElement("div");
        metaEl.className = "fflhub-ffl-picker__dealer-meta";
        metaEl.textContent =
          dealer.ffl_number + (dealer.phone ? " | " + dealer.phone : "");

        li.appendChild(nameEl);
        li.appendChild(document.createElement("br"));
        li.appendChild(addrEl);
        li.appendChild(metaEl);

        li.addEventListener("click", function () {
          listContainer
            .querySelectorAll(".fflhub-ffl-picker__dealer.is-selected")
            .forEach(function (node) {
              node.classList.remove("is-selected");
            });

          li.classList.add("is-selected");

          if (hiddenSelected) {
            hiddenSelected.value = JSON.stringify(dealer);
          }

          const receivingInput = getReceivingInput();
          if (receivingInput) {
            const value = dealer.ffl_number || "";

            const descriptor = Object.getOwnPropertyDescriptor(
              window.HTMLInputElement.prototype,
              "value"
            );

            if (descriptor && typeof descriptor.set === "function") {
              descriptor.set.call(receivingInput, value);
            } else {
              receivingInput.value = value;
            }

            receivingInput.dispatchEvent(new Event("input", { bubbles: true }));
            receivingInput.dispatchEvent(new Event("change", { bubbles: true }));
            receivingInput.dispatchEvent(new Event("blur", { bubbles: true }));
          }

          updateMap(mapIframe, dealer);
        });

        listContainer.appendChild(li);
      });
    }

    function handleSearch() {
      if (placeholderText) {
        placeholderText.style.display = "none";
      }

      const zip = zipInput ? zipInput.value.trim() : "";
      if (!zip) {
        showMessage("Please enter a ZIP Code.");
        return;
      }

      if (!restUrl) {
        showMessage(
          "FFL search endpoint is not configured. Please contact the store owner."
        );
        return;
      }

      showMessage("Searching for FFLs...");

      const limit =
        defaultLimit && !Number.isNaN(defaultLimit) ? defaultLimit : 50;

      const url =
        restUrl +
        (restUrl.includes("?") ? "&" : "?") +
        "zip=" +
        encodeURIComponent(zip) +
        "&limit=" +
        encodeURIComponent(limit);

      if (
        inFlightController &&
        typeof inFlightController.abort === "function"
      ) {
        inFlightController.abort();
      }

      const fetchOptions = {
        method: "GET",
        headers: {
          "Content-Type": "application/json",
        },
      };

      if (typeof AbortController !== "undefined") {
        inFlightController = new AbortController();
        fetchOptions.signal = inFlightController.signal;
      } else {
        inFlightController = null;
      }

      fetch(url, fetchOptions)
        .then(function (response) {
          if (!response.ok) {
            throw new Error("Error from FFL search API.");
          }
          return response.json();
        })
        .then(function (data) {
          const list = Array.isArray(data.ffls) ? data.ffls : [];
          const dealers = list.map(transformApiFFL);
          renderDealers(dealers);
        })
        .catch(function (error) {
          if (error && error.name === "AbortError") {
            return;
          }
          console.error("FFL search error:", error);
          showMessage(
            "There was a problem fetching FFLs. Please try again or contact the store."
          );
        });
    }

    function triggerSearch(event) {
      if (event && typeof event.preventDefault === "function") {
        event.preventDefault();
      }

      // Deduplicate overlapping touch/pointer/click events.
      const now = Date.now();
      if (now - lastTriggerTs < 300) {
        return;
      }
      lastTriggerTs = now;

      handleSearch();
    }

    if (searchButton) {
      searchButton.addEventListener("click", triggerSearch);
      searchButton.addEventListener("pointerup", triggerSearch);
      searchButton.addEventListener(
        "touchend",
        function () {
          triggerSearch();
        },
        { passive: true }
      );
    }

    if (zipInput) {
      zipInput.addEventListener("keydown", function (event) {
        if (event.key === "Enter") {
          event.preventDefault();
          handleSearch();
        }
      });
    }
  }

  ready(function () {
    const checkoutRoot = document.querySelector(".wc-block-checkout");
    const observeRoot = checkoutRoot || document.body;

    const boot = function () {
      placeMapAboveReceivingField();
      document.querySelectorAll(".fflhub-ffl-picker").forEach(initPicker);
    };

    boot();

    if (observeRoot) {
      const observer = new MutationObserver(function () {
        boot();
      });
      observer.observe(observeRoot, { childList: true, subtree: true });
    }
  });
})();
