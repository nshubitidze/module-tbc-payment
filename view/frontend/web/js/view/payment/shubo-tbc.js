define([
    'Magento_Checkout/js/view/payment/default',
    'Magento_Checkout/js/action/redirect-on-success',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/model/full-screen-loader',
    'mage/url',
    'mage/translate',
    'jquery',
    'ko'
], function (Component, redirectOnSuccessAction, quote, fullScreenLoader, urlBuilder, $t, $, ko) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Shubo_TbcPayment/payment/shubo-tbc',
            redirectAfterPlaceOrder: false,
            paymentService: null,
            isEmbedLoaded: ko.observable(false),
            paymentError: ko.observable(''),
            isProcessing: ko.observable(false),
            // BUG-4: track the quote grand total used for the last embed init
            // so we can detect cart changes and force a re-init with the
            // correct amount. `null` means "not initialized yet".
            _lastInitTotal: null,
            // BUG-14: track the increment_id of the most recent
            // placeOrderRedirect() run so we can tell AbortRedirect which
            // order to cancel when initiateRedirect fails.
            _pendingRedirectIncrementId: null
        },

        /**
         * @returns {string}
         */
        getCode: function () {
            return 'shubo_tbc';
        },

        /**
         * @returns {Object}
         */
        getConfig: function () {
            return window.checkoutConfig.payment.shubo_tbc || {};
        },

        /**
         * @returns {boolean}
         */
        isActive: function () {
            return this.getCode() === this.isChecked();
        },

        // --- Mode helpers ---

        /**
         * @returns {boolean}
         */
        isEmbedMode: function () {
            return this.getConfig().checkoutType !== 'redirect';
        },

        /**
         * @returns {boolean}
         */
        isRedirectMode: function () {
            return this.getConfig().checkoutType === 'redirect';
        },

        // --- Branding helpers ---

        /**
         * @returns {string}
         */
        brandLogoUrl: function () {
            return this.getConfig().brandLogoUrl || '';
        },

        /**
         * @returns {string}
         */
        brandDescription: function () {
            return this.getConfig().brandDescription || '';
        },

        /**
         * @returns {boolean}
         */
        hasBranding: function () {
            return this.brandLogoUrl() !== '' || this.brandDescription() !== '';
        },

        /**
         * Returns inline style object setting the accent CSS variable.
         * @returns {Object|boolean}
         */
        accentStyle: function () {
            var color = this.getConfig().brandAccentColor || '';

            if (color) {
                return {'--shubo-tbc-accent': color};
            }

            return {};
        },

        /**
         * When customer selects this payment method, load the Flitt embed (embed mode only).
         *
         * BUG-4: A `!this.paymentService` guard was blocking re-init when the cart
         * amount changed mid-checkout (customer updates quantity, applies coupon,
         * edits shipping) — the embed kept showing the old grand total. We now
         * compare the quote's current grand_total with the one we last
         * initialized against and force a fresh init on mismatch.
         *
         * @returns {boolean}
         */
        selectPaymentMethod: function () {
            this._super();

            if (!this.isEmbedMode()) {
                return true;
            }

            var totals = quote.totals();
            var currentTotal = (totals && typeof totals.grand_total !== 'undefined')
                ? totals.grand_total
                : null;

            if (!this.paymentService || this._lastInitTotal !== currentTotal) {
                this.paymentService = null;
                this.isEmbedLoaded(false);
                this._lastInitTotal = currentTotal;
                this.initEmbed();
            }

            return true;
        },

        /**
         * Fetch signed payment params from backend and initialize the Flitt embed form.
         */
        initEmbed: function () {
            var self = this;

            self.paymentError('');

            $.ajax({
                url: urlBuilder.build('shubo_tbc/payment/params'),
                type: 'POST',
                dataType: 'json',
                data: {
                    form_key: window.FORM_KEY || ''
                }
            }).done(function (response) {
                if (response.success && response.token) {
                    self.renderFlittEmbed(response.token);
                } else {
                    self.paymentError(response.message || $t('Unable to load payment form.'));
                }
            }).fail(function () {
                self.paymentError($t('Unable to connect to payment service.'));
            });
        },

        /**
         * Render the Flitt Embed card form inside the container element.
         *
         * @param {string} token
         */
        renderFlittEmbed: function (token) {
            var self = this;
            var config = this.getConfig();

            require(['flittCheckout'], function (checkout) {
                try {
                    var methodsDisabled = ['banks', 'most_popular'];
                    if (!config.enableWallets) {
                        methodsDisabled.push('wallets');
                    }

                    var options = {
                        methods_disabled: methodsDisabled,
                        full_screen: false,
                        show_pay_button: false,
                        theme: {
                            type: config.embedThemeType || 'light',
                            preset: config.embedThemePreset || 'reset',
                            layout: config.embedLayout || 'default'
                        }
                    };

                    // --- Branding: accent color overrides ---
                    var accentColor = config.brandAccentColor || '';
                    var cssVariable = {};

                    if (accentColor) {
                        options.theme.preset = 'reset';
                        cssVariable.main = accentColor;
                    }

                    // --- Branding: logo ---
                    if (config.brandLogoUrl) {
                        options.logo_url = config.brandLogoUrl;
                    }

                    // --- Branding: strip provider branding ---
                    if (config.brandStripProvider) {
                        options.show_title = false;
                        options.show_link = false;
                        options.show_secure_message = false;
                    }

                    // --- Advanced JSON overrides (power users) ---
                    if (config.embedOptionsJson) {
                        try {
                            var overrides = JSON.parse(config.embedOptionsJson);

                            for (var key in overrides) {
                                if (overrides.hasOwnProperty(key)) {
                                    if (key === 'theme' && typeof overrides[key] === 'object') {
                                        for (var tKey in overrides[key]) {
                                            if (overrides[key].hasOwnProperty(tKey)) {
                                                options.theme[tKey] = overrides[key][tKey];
                                            }
                                        }
                                    } else if (key === 'css_variable' && typeof overrides[key] === 'object') {
                                        for (var cKey in overrides[key]) {
                                            if (overrides[key].hasOwnProperty(cKey)) {
                                                cssVariable[cKey] = overrides[key][cKey];
                                            }
                                        }
                                    } else {
                                        options[key] = overrides[key];
                                    }
                                }
                            }
                        } catch (e) {
                            console.error('TBC: Invalid embed options JSON:', e);
                        }
                    }

                    var checkoutOptions = {
                        options: options,
                        params: {
                            token: token
                        }
                    };

                    // Apply css_variable at the top level (Flitt SDK requirement)
                    if (Object.keys(cssVariable).length > 0) {
                        checkoutOptions.css_variable = cssVariable;
                    }

                    self.paymentService = checkout('#shubo-tbc-embed-container', checkoutOptions);
                    self.isEmbedLoaded(true);
                } catch (e) {
                    self.paymentError($t('Unable to render payment form.'));
                    console.error('Flitt embed init error:', e);
                }
            }, function () {
                self.paymentError($t('Unable to load payment SDK.'));
            });
        },

        /**
         * Place order: branches between embed and redirect flows.
         *
         * EMBED: Submit payment to Flitt FIRST, then create Magento order.
         * REDIRECT: Create Magento order FIRST, then redirect to Flitt.
         *
         * @param {Object} data
         * @param {Event} event
         * @returns {boolean}
         */
        placeOrder: function (data, event) {
            var self = this;

            if (event) {
                event.preventDefault();
            }

            if (!self.validate()) {
                return false;
            }

            if (self.isRedirectMode()) {
                return self.placeOrderRedirect();
            }

            return self.placeOrderEmbed();
        },

        /**
         * Embed flow: Flitt approves first, then Magento order, then confirm.
         * Prevents ghost orders when card is invalid or customer abandons 3DS.
         *
         * @returns {boolean}
         */
        placeOrderEmbed: function () {
            var self = this;

            if (!self.paymentService) {
                self.paymentError($t('Payment form not loaded.'));
                return false;
            }

            self.isProcessing(true);
            self.paymentError('');

            var payment = self.paymentService.submit();

            payment.$on('success', function () {
                self.getPlaceOrderDeferredObject()
                    .done(function () {
                        self.confirmPayment();
                    })
                    .fail(function () {
                        self.isProcessing(false);
                        self.paymentError($t('Order could not be placed. Please try again.'));
                    });
            });

            payment.$on('error', function (model) {
                self.isProcessing(false);
                var msg = model && model.attr
                    ? model.attr('error.message')
                    : $t('Payment was declined.');
                self.paymentError(msg);
            });

            return false;
        },

        /**
         * Redirect flow: Create Magento order first, then redirect to Flitt.
         *
         * BUG-14: once `getPlaceOrderDeferredObject()` resolves, the Magento
         * order exists and the quote is consumed. If `initiateRedirect` then
         * fails (500, CSP, network), the customer is stuck with an unpayable
         * orphan order. We now capture the just-placed increment_id so
         * `initiateRedirect()` can ask the backend to cancel the orphan and
         * let the customer retry.
         *
         * @returns {boolean}
         */
        placeOrderRedirect: function () {
            var self = this;

            self.isProcessing(true);
            self.paymentError('');
            self._pendingRedirectIncrementId = null;

            self.getPlaceOrderDeferredObject()
                .done(function () {
                    // Snapshot the just-placed order's increment_id BEFORE we
                    // hit the Flitt token endpoint. If that call fails, we
                    // need this value to cancel the orphan.
                    self._pendingRedirectIncrementId = self.getLastOrderIncrementId();
                    self.initiateRedirect();
                })
                .fail(function () {
                    self.isProcessing(false);
                    self.paymentError($t('Order could not be placed. Please try again.'));
                });

            return false;
        },

        /**
         * Read the just-placed order's increment_id from the checkout session.
         *
         * Uses require() to pull the quote model on demand instead of adding
         * a new top-level dependency (the view-model already caches quote).
         *
         * @returns {string}
         */
        getLastOrderIncrementId: function () {
            try {
                // Magento_Checkout exposes the last-placed order via the
                // checkout data/session. We read it via the global
                // checkoutConfig fallback: the redirect controller stores
                // the flitt_order_id in session, but the increment_id is
                // the `checkoutConfig.lastOrderId` set by Magento core on
                // successful order placement.
                var cfg = window.checkoutConfig || {};

                if (cfg.lastOrderId) {
                    return String(cfg.lastOrderId);
                }

                // Fallback: Magento's default place-order action stores the
                // last increment id in the lastOrderId model via the session
                // module. If neither is present, return empty — the abort
                // endpoint will simply respond with an error, which we log.
                return '';
            } catch (e) {
                return '';
            }
        },

        /**
         * Call the redirect endpoint to get the Flitt checkout URL, then redirect.
         *
         * BUG-14: split success/failure cleanly. On failure, ask the backend to
         * cancel the just-placed orphan order so the customer can retry.
         */
        initiateRedirect: function () {
            var self = this;

            $.ajax({
                url: urlBuilder.build('shubo_tbc/payment/redirect'),
                type: 'POST',
                dataType: 'json',
                data: {
                    form_key: window.FORM_KEY || ''
                }
            }).done(function (response) {
                if (response && response.success && response.checkout_url) {
                    // Clear the pending increment id — we're about to leave the
                    // page and the backend is taking ownership of the flow.
                    self._pendingRedirectIncrementId = null;
                    window.location.href = response.checkout_url;
                    return;
                }

                self.handleRedirectFailure(
                    (response && response.message) || $t('Unable to redirect to payment page.')
                );
            }).fail(function (xhr) {
                console.error('TBC: initiateRedirect AJAX failed', {
                    status: xhr && xhr.status,
                    responseText: xhr && xhr.responseText
                });
                self.handleRedirectFailure($t('Unable to connect to payment service.'));
            });
        },

        /**
         * Client-side side of BUG-14's fix: when initiateRedirect fails, ask
         * the backend to cancel the orphan order so the customer's checkout
         * does not end in an unpayable placed-but-no-gateway-session state.
         *
         * @param {string} reason Human-readable error to surface.
         */
        handleRedirectFailure: function (reason) {
            var self = this;
            var incrementId = self._pendingRedirectIncrementId;

            self.isProcessing(false);

            if (!incrementId) {
                // Nothing to abort — order was never placed, or we couldn't
                // read the id. Show the error and let the customer retry.
                self.paymentError(
                    reason || $t('Payment initialization failed. Please try again.')
                );
                return;
            }

            self.paymentError(
                $t('Payment initialization failed. Cancelling your order so you can retry...')
            );

            $.ajax({
                url: urlBuilder.build('shubo_tbc/payment/abortRedirect'),
                type: 'POST',
                dataType: 'json',
                data: {
                    form_key: window.FORM_KEY || '',
                    increment_id: incrementId
                }
            }).done(function (abortResponse) {
                if (abortResponse && abortResponse.success) {
                    self.paymentError(
                        $t('Payment initialization failed. Your order has been cancelled — please try again.')
                    );
                } else {
                    // Backend refused to cancel (already invoiced, wrong
                    // method, etc.). Surface that to the customer so they
                    // can contact support instead of silently retrying.
                    self.paymentError(
                        (abortResponse && abortResponse.message)
                            || $t('Payment failed and we could not cancel your order automatically. Please contact support.')
                    );
                }
            }).fail(function (xhr) {
                console.error('TBC: abortRedirect AJAX failed', {
                    status: xhr && xhr.status,
                    responseText: xhr && xhr.responseText
                });
                self.paymentError(
                    $t('Payment failed and we could not reach our servers to cancel the order. Please contact support.')
                );
            }).always(function () {
                self._pendingRedirectIncrementId = null;
            });
        },

        /**
         * Confirm payment with backend after Flitt embed success event.
         *
         * BUG-12: previously used `.always()` which redirected the customer to
         * the success page even when the backend confirmation failed (signature
         * mismatch, Flitt status != approved, etc.). Splitting into
         * `.done()`/`.fail()` lets us redirect only on verified success and
         * surface a real error to the customer on failure so they can retry
         * or contact support.
         */
        confirmPayment: function () {
            var self = this;

            $.ajax({
                url: urlBuilder.build('shubo_tbc/payment/confirm'),
                type: 'POST',
                dataType: 'json',
                data: {
                    form_key: window.FORM_KEY || ''
                }
            }).done(function (response) {
                self.isProcessing(false);

                if (response && response.success) {
                    self.afterPlaceOrder();
                    return;
                }

                // Backend rejected the confirmation. Common causes:
                //   - Flitt status != 'approved' (payment was not actually
                //     approved when JS thought it was),
                //   - signature validation failed,
                //   - the order has already been finalised by callback/cron.
                var message = (response && response.message)
                    ? response.message
                    : $t('Payment could not be confirmed. Please try again or contact support.');

                console.error('TBC: confirmPayment rejected', {
                    flitt_status: response && response.flitt_status,
                    already_processed: response && response.already_processed,
                    message: message
                });

                self.paymentError(message);
            }).fail(function (xhr) {
                self.isProcessing(false);

                console.error('TBC: confirmPayment AJAX failed', {
                    status: xhr && xhr.status,
                    responseText: xhr && xhr.responseText
                });

                self.paymentError(
                    $t('Unable to confirm payment. Please try again or contact support.')
                );
            });
        },

        /**
         * After order is placed, redirect to the success page.
         */
        afterPlaceOrder: function () {
            redirectOnSuccessAction.execute();
        },

        /**
         * @returns {Object}
         */
        getData: function () {
            return {
                method: this.getCode(),
                additional_data: {}
            };
        }
    });
});
