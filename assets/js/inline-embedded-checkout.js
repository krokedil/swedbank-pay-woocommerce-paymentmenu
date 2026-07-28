jQuery(document).ready(function ($) {
    const sbie = {
        params: {},
        checkout: null,
        isOpen: false,
        containerSelector: '#payex_container',
        selectedMethod: null,
        payButtonPressed: false,
        paymentOrderId: null,
        onPaidRedirectUrl: null,
        boundContainer: null,
        attemptReleased: true,
        paymentCompleteLockReleased: false,

        /**
         * Initialize the inline embedded checkout script.
         *
         * @returns {void}
         */
        init: function () {
            sbie.params = swedbank_pay_params !== 'undefined' ? swedbank_pay_params : {};

            // Set the initially selected payment method.
            sbie.selectedMethod = sbie.getSelectedPaymentMethod();
            $('body').on('change', 'input[name="payment_method"]', sbie.onPaymentMethodChange);
            $('body').on('update_checkout', sbie.onUpdateCheckout);
            $('body').on('updated_checkout', sbie.onUpdatedCheckout);

            // Register the listener for the form submission, and prevent default if the selected payment method is Swedbank Pay.
            $('form.checkout').on('checkout_place_order_payex_checkout', sbie.onSubmitForm);

            // Toggle the WooCommerce elements based on the initially selected payment method.
            sbie.toggleWooCommerceElements();
            sbie.ifPaymentComplete();
        },

        /**
         * Log messages to the console if script debugging is enabled.
         *
         * @param  {...any} data The data to log.
         * @returns {void}
         */
        consoleLog: function (...data) {
            if ( sbie.params.script_debug ) {
                console.log(...data);
            }
        },

        /**
         * Check if the selected payment method is Swedbank Pay.
         *
         * @returns {boolean}
         */
        isSelectedPaymentMethodSwedbankPay: function () {
            const selectedMethod = sbie.getSelectedPaymentMethod();
            return selectedMethod === 'payex_checkout';
        },

        /**
         * Get the selected payment method.
         *
         * @returns {string|null}
         */
        getSelectedPaymentMethod: function () {
            return $('input[name="payment_method"]:checked').val();
        },

        /**
         * Check if the selected payment method has changed.
         *
         * @returns {boolean}
         */
        hasSelectedPaymentMethodChanged: function () {
            const selectedMethod = sbie.getSelectedPaymentMethod();
            return selectedMethod !== sbie.selectedMethod;
        },

        /**
         * Handle the update checkout event.
         *
         * @returns {void}
         */
        onUpdateCheckout: function () {
            if (sbie.isSelectedPaymentMethodSwedbankPay()) {
                sbie.lockCheckout();
            }
        },

        /**
         * Handle the updated checkout event.
         *
         * @returns {void}
         */
        onUpdatedCheckout: function () {
            if (sbie.isSelectedPaymentMethodSwedbankPay()) {
                sbie.unlockCheckout();
            }

            sbie.toggleWooCommerceElements();
            sbie.ifPaymentComplete();
        },

        /**
         * Handle the payment method change event.
         *
         * @returns {void}
         */
        onPaymentMethodChange: function () {
            // If no change has been made, do nothing.
            if (!sbie.hasSelectedPaymentMethodChanged()) {
                return;
            }
            const selectedMethod = sbie.getSelectedPaymentMethod();

            // If the selected payment method is Swedbank Pay.
            if (sbie.isSelectedPaymentMethodSwedbankPay()) {
                sbie.unlockCheckout();
                sbie.hideWooCommerceElements();
            } else {
                sbie.lockCheckout();
                sbie.showWooCommerceElements();
            }

            sbie.selectedMethod = selectedMethod;
        },

        /**
         * Handle the form submission event.
         * Will either allow or prevent the form submission depending on if the button from the embedded checkout was pressed,
         * and the selected payment method is Swedbank Pay.
         *
         * @param {Event} e The event object.
         * @returns {boolean} True if the form should be submitted, false otherwise.
         */
        onSubmitForm: function (e) {
            if (sbie.isSelectedPaymentMethodSwedbankPay() && ! sbie.payButtonPressed) {
                return false;
            }
            return true;
        },

        /**
         * Handle the checkout success event from WooCommerce.
         *
         * @param {jQuery} wcForm The WooCommerce form object.
         * @param {Object} result The result object from WooCommerce.
         * @returns {boolean} True to indicate the event was handled.
         */
        onCheckoutSuccess: function (wcForm, result) {
            sbie.payButtonPressed = false;
            sbie.onPaidRedirectUrl = result.redirect_on_paid;
            // Disable the event listeners for the checkout result.
            sbie.unbindCheckoutResultListeners();

            sbie.releasePaymentAttempt(true);

            return true;
        },

        /**
         * Handle the checkout error event from WooCommerce.
         *
         * @returns {void}
         */
        onCheckoutError: function () {
            sbie.failPayment();
        },

        /**
         * Handle the payment button pressed event from the embedded checkout.
         *
         * @param {Object} data The data object from the payment button.
         * @returns {void}
         */
        onPaymentButtonPressed: function (data) {
            // Prevent multiple submissions.
            if (sbie.payButtonPressed) {
                return;
            }

            // Set the default z-index for block ui to not block any modals that Swedbank needs to show.
            $.blockUI.defaults.baseZ = 40; // Default for WooCommerce is 1000, but Swedbank modals use 50.

            // Set the payment order ID.
            sbie.paymentOrderId = data.paymentOrder.id;

            // The hosted view keeps its button pending until this attempt is answered with
            // resume(), so from here on every exit has to run through releasePaymentAttempt().
            sbie.attemptReleased = false;

            // Register listeners for the checkout result.
            $('body').on('checkout_error.swedbank', sbie.onCheckoutError);
            $('form.checkout').on('checkout_place_order_success.swedbank', sbie.onCheckoutSuccess);

            // Check the terms checkbox. Deliberately without a change event: WooCommerce queues an
            // update_checkout for checkbox changes in some contexts, and an update landing here
            // would tear down the hosted view mid attempt.
            $('#terms').prop('checked', true);

            // A payment complete return leaves the form flagged as processing and blocked, see
            // ifPaymentComplete(). WooCommerce ignores a submit on a form in that state, so the
            // lock has to be lifted here or this attempt would never reach the server.
            sbie.unlockCheckoutForm();

            // Submit the form.
            const wcForm = $('form.checkout');
            sbie.payButtonPressed = true;
            wcForm.submit();

            // WooCommerce flags the form as processing synchronously when it accepts a submit.
            // Without that flag the submit was refused, and neither checkout_error nor
            // checkout_place_order_success will ever fire, so release the attempt now rather
            // than leave the button pending until the hosted view times out on its own.
            if (!wcForm.hasClass('processing')) {
                sbie.consoleLog('WooCommerce refused the checkout submit, releasing the payment attempt.');
                sbie.failPayment();
            }
        },

        /**
         * Handle the paid event from the embedded checkout.
         *
         * @param {Object} data The data object from the paid event.
         * @returns {void}
         */
        onPaid: function (data) {
            // If the onPaidRedirectUrl is empty, get it from the params instead.
            if (sbie.onPaidRedirectUrl === null) {
                sbie.onPaidRedirectUrl = sbie.params.thankyou_url;
            }

            if (sbie.onPaidRedirectUrl !== null) {
                window.location.href = sbie.onPaidRedirectUrl;
            }
        },

        /**
         * Handle the payment attempt failed event from the embedded checkout.
         *
         * @param {Object} data The data object from the payment attempt failed event.
         * @returns {void}
         */
        onPaymentAttemptFailed: function (data) {
            sbie.failPayment();
        },

        /**
         * Handle an error reported by the embedded checkout.
         *
         * Logs only. The errors reported here are not all fatal to the payment, and failing the
         * attempt on every one of them would unbind the result listeners while the WooCommerce
         * submit may still succeed.
         *
         * @param {Object} data The data object from the error event.
         * @returns {void}
         */
        onError: function (data) {
            sbie.consoleLog('The embedded checkout reported an error.', data);
        },

        /**
         * Fail a payment attempt and resume the checkout without confirmation.
         *
         * @returns {void}
         */
        failPayment: function () {
            // Disable the event listeners for the checkout result.
            sbie.unbindCheckoutResultListeners();

            // Unblock the checkout and remove the processing class.
            sbie.unlockCheckoutForm();

            sbie.releasePaymentAttempt(false);

            sbie.payButtonPressed = false;
        },

        /**
         * Answer the pending payment attempt so the embedded checkout stops showing its button
         * as pending. Guarded on the attempt rather than on payButtonPressed: the release has to
         * happen on every way out of an attempt, but never twice, since the hosted view hands the
         * answer to whichever attempt is currently waiting for one.
         *
         * @param {boolean} confirmation Whether the payment attempt may proceed.
         * @returns {void}
         */
        releasePaymentAttempt: function (confirmation) {
            if (sbie.attemptReleased) {
                return;
            }

            if (sbie.checkout === null || sbie.paymentOrderId === null) {
                sbie.consoleLog('Unable to release the payment attempt, no embedded checkout to resume.');
                return;
            }

            sbie.attemptReleased = true;
            sbie.checkout.resume({
                paymentOrderId: sbie.paymentOrderId,
                confirmation: confirmation,
            });
        },

        /**
         * Remove the listeners for the WooCommerce checkout result.
         *
         * @returns {void}
         */
        unbindCheckoutResultListeners: function () {
            $('body').off('checkout_error.swedbank');
            $('form.checkout').off('checkout_place_order_success.swedbank');
        },

        /**
         * Release the lock on the WooCommerce checkout form.
         *
         * @returns {void}
         */
        unlockCheckoutForm: function () {
            // Remember that the lock was lifted so that ifPaymentComplete() does not put it back
            // on the next updated_checkout, which would block every further payment attempt.
            sbie.paymentCompleteLockReleased = true;

            $('form.checkout').unblock();
            $('form.checkout').removeClass('processing');
        },

        /**
         * Lock the checkout and prevent further actions.
         *
         * @returns {void}
         */
        lockCheckout: function () {
            // Try to close the checkout if it is open.
            if (sbie.checkout !== null && sbie.isOpen) {
                sbie.checkout.close();
                sbie.isOpen = false;
            }
        },

        /**
         * Unlock the checkout and allow further actions.
         *
         * @returns {void}
         */
        unlockCheckout: function () {
            // WooCommerce replaces the entire payment fragment on every updated_checkout, which
            // detaches the element the hosted view was bound to. The SDK resolves its container
            // once, when it is configured, so a detached binding has to be rebuilt against the
            // element that is in the document now, or the checkout renders where nobody can see it.
            if (sbie.checkout !== null && sbie.isCheckoutBindingStale()) {
                sbie.rebindCheckout();
                return;
            }

            // If the checkout has not yet been initialized, do so now.
            if (sbie.checkout === null) {
                sbie.initCheckout();
                return;
            }

            // If the checkout is initialized but not open, open it now.
            if (!sbie.isOpen) {
                sbie.checkout.open();
                sbie.isOpen = true;
            }
        },

        /**
         * Get the container the embedded checkout should render into.
         *
         * @returns {HTMLElement|null}
         */
        getCheckoutContainer: function () {
            return document.querySelector(sbie.containerSelector);
        },

        /**
         * Check whether the container the embedded checkout was bound to has been removed from
         * the document.
         *
         * @returns {boolean}
         */
        isCheckoutBindingStale: function () {
            return sbie.boundContainer === null || !document.body.contains(sbie.boundContainer);
        },

        /**
         * Rebuild the embedded checkout against the container currently in the document.
         *
         * @returns {void}
         */
        rebindCheckout: function () {
            sbie.consoleLog('The embedded checkout container was replaced, rebuilding the hosted view.');

            // Anything still pending belongs to the view that is being discarded. Answer it before
            // the view goes away, so the attempt is not left open on Swedbank Pay's side.
            sbie.releasePaymentAttempt(false);
            sbie.payButtonPressed = false;

            // Close first. The SDK appends a new iframe on every open(), so re-opening without
            // closing would leave the discarded view behind as a duplicate. close() targets the
            // container the view is still configured against, which is the detached one, so it has
            // to happen before initCheckout() rebinds. lockCheckout() has usually closed it
            // already, on the update_checkout that preceded this.
            if (sbie.isOpen) {
                sbie.checkout.close();
            }
            sbie.checkout = null;
            sbie.isOpen = false;

            sbie.initCheckout();
        },

        /**
         * Initialize the embedded checkout.
         *
         * @returns {void}
         */
        initCheckout: function () {
            // If the selected payment method is not Swedbank Pay, do nothing.
            if (!sbie.isSelectedPaymentMethodSwedbankPay()) {
                return;
            }

            // If the checkout is already initialized, do nothing.
            if (sbie.checkout !== null) {
                return;
            }

            // The SDK throws if the container is missing, which happens when the payment fragment
            // has not been rendered yet. The next updated_checkout will initialize it instead.
            const container = sbie.getCheckoutContainer();
            if (container === null) {
                return;
            }

            // Initialize the checkout.
            sbie.checkout = payex.hostedView.checkout({
                container: {
                    checkout: "payex_container"
                },
                culture: sbie.params.culture,
                onPaymentButtonPressed: sbie.onPaymentButtonPressed,
                onPaid: sbie.onPaid,
                onPaymentAttemptFailed: sbie.onPaymentAttemptFailed,
                onError: sbie.onError,
                /*onAborted: function (data) { sbie.consoleLog('Checkout aborted', data); },
                onCheckoutLoaded: function (data) {
                    sbie.consoleLog('Checkout loaded', data);
                },
                onCheckoutResized: function (data) { sbie.consoleLog('Checkout resized', data); },
                onInstrumentSelected: function (data) { sbie.consoleLog('Instrument selected', data); },
                onOutOfViewOpen: function (data) { sbie.consoleLog('Out of view opened', data); },
                onOutOfViewRedirect: function (data) { sbie.consoleLog('Out of view redirected', data); },
                onPaymentAttemptAborted: function (data) { sbie.consoleLog('Payment attempt aborted', data); },
                onPaymentAttemptStarted: function (data) { sbie.consoleLog('Payment attempt started', data); },
                onTermsOfServiceRequested: function (data) { sbie.consoleLog('Terms of service requested', data); },*/
            });

            // Remember what the hosted view bound to, so a replaced fragment can be detected.
            sbie.boundContainer = container;

            sbie.checkout.open();
            sbie.isOpen = true;
        },

        /**
         * Toggle the visibility of WooCommerce elements based on the selected payment method.
         *
         * @returns {void}
         */
        toggleWooCommerceElements: function () {
            // If the selected payment method is Swedbank Pay, hide the WooCommerce elements.
            if (sbie.isSelectedPaymentMethodSwedbankPay()) {
                sbie.hideWooCommerceElements();
            } else {
                sbie.showWooCommerceElements();
            }
        },

        /**
         * Hide the WooCommerce elements that should be hidden when the Swedbank Pay payment method is selected.
         *
         * @returns {void}
         */
        hideWooCommerceElements: function () {
            $('div.form-row.place-order').hide();
        },

        /**
         * Show the WooCommerce elements that should be shown when a non-Swedbank Pay payment method is selected.
         *
         * @returns {void}
         */
        showWooCommerceElements: function () {
            $('div.form-row.place-order').show();
        },

        /**
         * If payment is complete, lock the checkout to prevent further actions.
         *
         * @returns {void}
         */
        ifPaymentComplete: function () {
            // Once the lock has been lifted for a new payment attempt it must stay off. The hidden
            // fields that mark the payment complete return are posted with every update_checkout,
            // so this runs again on each updated_checkout and would otherwise re-block the form.
            if ( sbie.paymentCompleteLockReleased ) {
                return;
            }

            if ( sbie.params.payment_complete ) {
                // Lock the checkout form.
                $('form.checkout').addClass('processing');
                $('form.checkout').block({
                    message: null,
                    overlayCSS: {
                        background: '#fff',
                        opacity: 0.6
                    }
                });

                // Ensure the embedded checkout is positioned above the overlay for the locked form.
                $('#payex_container').css('zIndex', '5000');
                $('#payex_container').css('position', 'relative');

                if( sbie.isOpen ) {
                    sbie.checkout.refresh();
                }
            }
        },
    };
    sbie.init();
});
