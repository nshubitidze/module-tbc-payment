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
            isProcessing: ko.observable(false)
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
         * @returns {boolean}
         */
        selectPaymentMethod: function () {
            this._super();

            if (this.isEmbedMode() && !this.paymentService) {
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
         * @returns {boolean}
         */
        placeOrderRedirect: function () {
            var self = this;

            self.isProcessing(true);
            self.paymentError('');

            self.getPlaceOrderDeferredObject()
                .done(function () {
                    self.initiateRedirect();
                })
                .fail(function () {
                    self.isProcessing(false);
                    self.paymentError($t('Order could not be placed. Please try again.'));
                });

            return false;
        },

        /**
         * Call the redirect endpoint to get the Flitt checkout URL, then redirect.
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
                if (response.success && response.checkout_url) {
                    window.location.href = response.checkout_url;
                } else {
                    self.isProcessing(false);
                    self.paymentError(response.message || $t('Unable to redirect to payment page.'));
                }
            }).fail(function () {
                self.isProcessing(false);
                self.paymentError($t('Unable to connect to payment service.'));
            });
        },

        /**
         * Confirm payment with backend after Flitt embed success event.
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
            }).always(function () {
                self.isProcessing(false);
                self.afterPlaceOrder();
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
