(() => {
    const normalize = (v) => String(v || '').toLowerCase().trim();

    const decorateOrderAddressColumns = () => {
        const containers = document.querySelectorAll(
            '#order_data .order_data_column_container, #woocommerce-order-data .order_data_column_container'
        );

        containers.forEach((container) => {
            const cols = Array.from(container.querySelectorAll(':scope > .order_data_column'));
            if (!cols.length) {
                return;
            }

            cols.forEach((col) => {
                col.classList.remove('fflhub-billing-column', 'fflhub-shipping-column');
            });

            let billingCol = null;
            let shippingCol = null;

            cols.forEach((col) => {
                const heading = col.querySelector('h3');
                const text = normalize(heading ? heading.textContent : '');
                if (!text) {
                    return;
                }

                const hasBilling = /\bbilling\b/.test(text);
                const hasShipping = /\bshipping\b/.test(text);

                if (hasShipping && !hasBilling) {
                    shippingCol = col;
                } else if (hasBilling && !hasShipping) {
                    billingCol = col;
                } else if (hasShipping) {
                    // If both words appear in a heading, bias to shipping.
                    shippingCol = col;
                } else if (hasBilling) {
                    billingCol = col;
                }
            });

            if (billingCol) {
                billingCol.classList.add('fflhub-billing-column');
            }
            if (shippingCol) {
                shippingCol.classList.add('fflhub-shipping-column');
            }

            // Put shipping before billing to reduce address mix-ups.
            if (shippingCol && billingCol && billingCol.parentNode === container) {
                container.insertBefore(shippingCol, billingCol);
            }
        });
    };

    const run = () => {
        decorateOrderAddressColumns();
        // HPOS/admin panels can render asynchronously; retry shortly.
        window.setTimeout(decorateOrderAddressColumns, 300);
        window.setTimeout(decorateOrderAddressColumns, 1200);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
})();
