jQuery(function ($) {
    const sbo = {
        params: typeof swedbank_pay_overlay_params !== 'undefined' ? swedbank_pay_overlay_params : {},
        checkout: null,
        redirectUrl: null,
        onPaidRedirectUrl: null,

        /**
         * Initialize the overlay checkout script.
         *
         * @returns {void}
         */
        init: function () {
            $('form.checkout').on('checkout_place_order_success', sbo.onCheckoutSuccess);
        },

        /**
         * Open the overlay instead of redirecting when the payment order has been created.
         *
         * @param {Event} e The event object.
         * @param {Object} result The result object from WooCommerce.
         * @returns {boolean} True to let WooCommerce continue.
         */
        onCheckoutSuccess: function (e, result) {
            // Not an overlay payment, e.g. another gateway or a mobile device.
            if (!result.swedbank_pay_view_checkout) {
                return true;
            }

            sbo.redirectUrl = result.redirect;
            sbo.onPaidRedirectUrl = result.redirect_on_paid;

            // WooCommerce follows the redirect, so replace it with a hash to stay on the page.
            result.redirect = '#swedbank-pay-overlay';

            sbo.openOverlay(result.swedbank_pay_view_checkout);

            return true;
        },

        /**
         * Create the overlay and load the payment menu into it.
         *
         * @param {string} scriptUrl The view-checkout script URL for the payment order.
         * @returns {void}
         */
        openOverlay: function (scriptUrl) {
            const $closeButton = $('<button type="button" class="swedbank-pay-overlay-close"></button>')
                .attr('aria-label', sbo.params.close_label)
                .html('&times;')
                .on('click', sbo.closeOverlay);

            $('<div id="swedbank-pay-overlay" role="dialog" aria-modal="true"></div>')
                .append(
                    $('<div class="swedbank-pay-overlay-content"></div>')
                        .append($closeButton)
                        .append('<div id="swedbank-pay-overlay-container"></div>')
                )
                .appendTo('body');

            // Each payment order has its own script.
            $('#swedbank-pay-overlay-script').remove();

            const script = document.createElement('script');
            script.id = 'swedbank-pay-overlay-script';
            script.src = scriptUrl;
            script.onload = sbo.initCheckout;
            script.onerror = sbo.fallbackToRedirect;
            document.body.appendChild(script);
        },

        /**
         * Initialize the payment menu in the overlay.
         *
         * @returns {void}
         */
        initCheckout: function () {
            sbo.checkout = payex.hostedView.checkout({
                container: {
                    checkout: 'swedbank-pay-overlay-container'
                },
                culture: sbo.params.culture,
                onPaid: sbo.onPaid,
                onAborted: sbo.closeOverlay,
                onError: sbo.fallbackToRedirect,
            });

            sbo.checkout.open();
        },

        /**
         * Handle the paid event from the payment menu.
         *
         * @returns {void}
         */
        onPaid: function () {
            window.location.href = sbo.onPaidRedirectUrl;
        },

        /**
         * Close the overlay and let the customer continue with the checkout.
         *
         * @returns {void}
         */
        closeOverlay: function () {
            if (sbo.checkout !== null) {
                sbo.checkout.close();
                sbo.checkout = null;
            }

            $('#swedbank-pay-overlay').remove();
            $('form.checkout').removeClass('processing').unblock();
        },

        /**
         * Continue the payment in the redirect menu if the overlay cannot be used.
         *
         * @returns {void}
         */
        fallbackToRedirect: function () {
            window.location.href = sbo.redirectUrl;
        },
    };

    sbo.init();
});
