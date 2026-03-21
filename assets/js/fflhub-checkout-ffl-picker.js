// assets/js/fflhub-checkout-ffl-picker.js
(function () {
  function ready(fn) {
    if (document.readyState !== "loading") {
      fn();
    } else {
      document.addEventListener("DOMContentLoaded", fn);
    }
  }

  ready(function () {
    const picker = document.querySelector(".fflhub-ffl-picker");
    if (!picker) {
      return;
    }

    // Additional Checkout text field (Receiving FFL).
    // Attribute may be on the input itself or on a wrapper.
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


    const zipInput = picker.querySelector("#fflhub-ffl-picker-zip");
    const searchButton = picker.querySelector(
      ".fflhub-ffl-picker__search-button"
    );
    const listContainer = picker.querySelector(
      ".fflhub-ffl-picker__list-inner"
    );
    const placeholderText = picker.querySelector(
      ".fflhub-ffl-picker__placeholder"
    );
    const mapIframe = picker.querySelector(".fflhub-ffl-picker__map-iframe");
    const hiddenSelected = picker.querySelector("#fflhub-ffl-picker-selected");

    if (!listContainer || !mapIframe) {
      return;
    }

    // REST endpoint + default limit from PHP localization.
    // e.g. restUrl = "https://example.com/wp-json/fflhub/v1/ffls"
    const settings = window.fflhubFFLPickerSettings || {};
    const restUrl = settings.restUrl || "";
    const defaultLimit = parseInt(settings.defaultLimit || 50, 10);

    let currentSelection = null;

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

    function updateMap(dealer) {
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

    function renderDealers(dealers) {
      listContainer.innerHTML = "";

      if (!dealers || dealers.length === 0) {
        const emptyMsg = document.createElement("p");
        emptyMsg.className = "fflhub-ffl-picker__placeholder";
        emptyMsg.textContent =
          "No FFLs found near that ZIP. Please double-check your ZIP Code or try another.";
        listContainer.appendChild(emptyMsg);
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
          dealer.ffl_number + (dealer.phone ? " • " + dealer.phone : "");

        li.appendChild(nameEl);
        li.appendChild(document.createElement("br"));
        li.appendChild(addrEl);
        li.appendChild(metaEl);

        li.addEventListener("click", function () {
          // Remove previous selection highlight.
          listContainer
            .querySelectorAll(".fflhub-ffl-picker__dealer.is-selected")
            .forEach(function (node) {
              node.classList.remove("is-selected");
            });

          li.classList.add("is-selected");
          currentSelection = dealer;

          // Update hidden field (for future order-meta usage).
          if (hiddenSelected) {
            hiddenSelected.value = JSON.stringify(dealer);
          }

          // 👉 Fill the Additional Checkout text field with the FFL number.
          const receivingInput = getReceivingInput();
          if (receivingInput) {
            const value = dealer.ffl_number || "";

            // This updates the real underlying property WooCommerce listens to.
            const nativeSetter = Object.getOwnPropertyDescriptor(
              window.HTMLInputElement.prototype,
              "value"
            ).set;
            nativeSetter.call(receivingInput, value);

            // Dispatch proper events so React updates its internal state.
            receivingInput.dispatchEvent(new Event("input", { bubbles: true }));
            receivingInput.dispatchEvent(
              new Event("change", { bubbles: true })
            );
            receivingInput.dispatchEvent(new Event("blur", { bubbles: true }));
          }

          // Update map to center on selected dealer.
          updateMap(dealer);
        });

        listContainer.appendChild(li);
      });
    }

    /**
     * Transform your API's FFL object into the internal shape we use in the picker.
     *
     * API shape:
     * {
     *   ffl_number,
     *   name,
     *   premise: { street, city, state, zip },
     *   mailing: { ... },
     *   phone
     * }
     */
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

    function handleSearchClick() {
      if (placeholderText) {
        placeholderText.style.display = "none";
      }

      const zip = zipInput ? zipInput.value.trim() : "";
      if (!zip) {
        listContainer.innerHTML = "";
        const msg = document.createElement("p");
        msg.className = "fflhub-ffl-picker__placeholder";
        msg.textContent = "Please enter a ZIP Code.";
        listContainer.appendChild(msg);
        return;
      }

      if (!restUrl) {
        listContainer.innerHTML = "";
        const msg = document.createElement("p");
        msg.className = "fflhub-ffl-picker__placeholder";
        msg.textContent =
          "FFL search endpoint is not configured. Please contact the store owner.";
        listContainer.appendChild(msg);
        return;
      }

      listContainer.innerHTML = "";
      const loadingMsg = document.createElement("p");
      loadingMsg.className = "fflhub-ffl-picker__placeholder";
      loadingMsg.textContent = "Searching for FFLs...";
      listContainer.appendChild(loadingMsg);

      const limit =
        defaultLimit && !Number.isNaN(defaultLimit) ? defaultLimit : 50;

      const url =
        restUrl +
        (restUrl.includes("?") ? "&" : "?") +
        "zip=" +
        encodeURIComponent(zip) +
        "&limit=" +
        encodeURIComponent(limit);

      fetch(url, {
        method: "GET",
        headers: {
          "Content-Type": "application/json",
        },
      })
        .then(function (response) {
          if (!response.ok) {
            throw new Error("Error from FFL search API.");
          }
          return response.json();
        })
        .then(function (data) {
          // Your API returns: { count: X, ffls: [ ... ] }
          const list = Array.isArray(data.ffls) ? data.ffls : [];
          const dealers = list.map(transformApiFFL);
          renderDealers(dealers);
        })
        .catch(function (error) {
          console.error("FFL search error:", error);
          listContainer.innerHTML = "";
          const msg = document.createElement("p");
          msg.className = "fflhub-ffl-picker__placeholder";
          msg.textContent =
            "There was a problem fetching FFLs. Please try again or contact the store.";
          listContainer.appendChild(msg);
        });
    }

    if (searchButton) {
      searchButton.addEventListener("click", handleSearchClick);
    }

    // Optional: allow pressing Enter in the ZIP field to trigger search.
    if (zipInput) {
      zipInput.addEventListener("keydown", function (event) {
        if (event.key === "Enter") {
          event.preventDefault();
          handleSearchClick();
        }
      });
    }
  });
})();
