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
         * Get payment method code.
         *
         * @returns {string}
         */
        getCode: function () {
            return 'shubo_tbc';
        },

        /**
         * Check if payment method is active (selected).
         *
         * @returns {boolean}
         */
        isActive: function () {
            return this.getCode() === this.isChecked();
        },

        /**
         * When customer selects this payment method, load the Flitt embed form.
         *
         * @returns {boolean}
         */
        selectPaymentMethod: function () {
            this._super();

            if (!this.paymentService) {
                this.initEmbed();
            }

            return true;
        },

        /**
         * Fetch signed payment params from backend and initialize the Flitt embed form.
         * The backend generates a signature using the merchant secret — the secret
         * itself is never sent to the frontend.
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
         * @param {string} token - Checkout token obtained from the Flitt API.
         */
        renderFlittEmbed: function (token) {
            var self = this;
            var config = window.checkoutConfig.payment.shubo_tbc || {};

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

                    // Merge advanced JSON overrides
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
                                    } else {
                                        options[key] = overrides[key];
                                    }
                                }
                            }
                        } catch (e) {
                            console.error('TBC: Invalid embed options JSON:', e);
                        }
                    }

                    self.paymentService = checkout('#shubo-tbc-embed-container', {
                        options: options,
                        params: {
                            token: token
                        }
                    });
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
         * Override placeOrder: create Magento order FIRST (pending state),
         * then submit payment to Flitt. This prevents charging customers
         * without a corresponding order if order creation fails.
         *
         * @param {Object} data
         * @param {Event} event
         * @returns {boolean}
         */
        placeOrder: function (data, event) {
            var self = this;

            if (!self.paymentService) {
                self.paymentError($t('Payment form not loaded.'));
                return false;
            }

            if (!self.validate()) {
                return false;
            }

            if (event) {
                event.preventDefault();
            }

            self.isProcessing(true);
            self.paymentError('');

            // Step 1: Create Magento order FIRST (pending state)
            self.getPlaceOrderDeferredObject()
                .done(function () {
                    // Step 2: Order created — now submit payment to Flitt
                    var payment = self.paymentService.submit();

                    payment.$on('success', function () {
                        self.isProcessing(false);
                        self.afterPlaceOrder();
                    });

                    payment.$on('error', function (model) {
                        self.isProcessing(false);
                        var msg = model && model.attr
                            ? model.attr('error.message')
                            : $t('Payment was declined.');
                        self.paymentError(msg);
                    });
                })
                .fail(function () {
                    self.isProcessing(false);
                    self.paymentError($t('Order could not be placed. Please try again.'));
                });

            return false;
        },

        /**
         * After Magento order is placed, redirect to the success page.
         */
        afterPlaceOrder: function () {
            redirectOnSuccessAction.execute();
        },

        /**
         * Return payment data for order placement.
         *
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
