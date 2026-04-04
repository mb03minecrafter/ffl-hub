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

            let billingCol = null;
            let shippingCol = null;

            cols.forEach((col) => {
                const heading = col.querySelector('h3');
                const text = normalize(heading ? heading.textContent : '');
                if (!text) {
                    return;
                }

                if (text.includes('billing')) {
                    billingCol = col;
                } else if (text.includes('shipping')) {
                    shippingCol = col;
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
