define([
    'Magento_Checkout/js/view/payment/default',
    'Magento_Checkout/js/model/full-screen-loader',
    'Magento_Checkout/js/action/redirect-on-success',
    'Magento_Checkout/js/model/quote',
    'mage/url',
    'mage/translate',
    'jquery',
    'ko'
], function (Component, fullScreenLoader, redirectOnSuccessAction, quote, urlBuilder, $t, $, ko) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Shubo_TbcPayment/payment/shubo-tbc',
            redirectAfterPlaceOrder: false,
            flittToken: null,
            isPaymentProcessing: ko.observable(false),
            paymentError: ko.observable('')
        },

        /**
         * Get payment method code.
         */
        getCode: function () {
            return 'shubo_tbc';
        },

        /**
         * Check if payment method is active.
         */
        isActive: function () {
            return this.getCode() === this.isChecked();
        },

        /**
         * Get payment method title from config.
         */
        getTitle: function () {
            var config = window.checkoutConfig.payment.shubo_tbc;
            return config ? config.title : $t('TBC Bank (Card Payment)');
        },

        /**
         * After order is placed, get token and initialize Flitt Embed.
         */
        afterPlaceOrder: function () {
            var self = this;

            self.isPaymentProcessing(true);
            self.paymentError('');
            fullScreenLoader.startLoader();

            $.ajax({
                url: urlBuilder.build('shubo_tbc/payment/gettoken'),
                type: 'GET',
                dataType: 'json',
                cache: false
            }).done(function (response) {
                fullScreenLoader.stopLoader();

                if (response.success && response.token) {
                    self.flittToken = response.token;
                    self.initFlittEmbed(response.token);
                } else {
                    self.isPaymentProcessing(false);
                    self.paymentError(
                        response.message || $t('Unable to initialize payment. Please try again.')
                    );
                }
            }).fail(function () {
                fullScreenLoader.stopLoader();
                self.isPaymentProcessing(false);
                self.paymentError($t('Unable to connect to payment gateway. Please try again.'));
            });
        },

        /**
         * Initialize Flitt Embed SDK with the obtained token.
         */
        initFlittEmbed: function (token) {
            var self = this;
            var config = window.checkoutConfig.payment.shubo_tbc;
            var containerId = '#shubo-tbc-payment-container';

            // Load CSS dynamically
            if (config && config.sdkCssUrl) {
                var linkEl = document.createElement('link');
                linkEl.rel = 'stylesheet';
                linkEl.href = config.sdkCssUrl;
                document.head.appendChild(linkEl);
            }

            // Load SDK and initialize
            require(['flittCheckout'], function (checkout) {
                try {
                    checkout(containerId, {
                        params: {
                            token: token
                        },
                        options: {
                            locales: ['en', 'ka'],
                            active_tab: 'card',
                            logo_url: '',
                            full_screen: false
                        },
                        callbackUrl: function () {
                            // Payment completed - redirect to success page
                            self.isPaymentProcessing(false);
                            redirectOnSuccessAction.execute();
                        },
                        onClose: function () {
                            self.isPaymentProcessing(false);
                        },
                        onError: function (error) {
                            self.isPaymentProcessing(false);
                            self.paymentError(
                                $t('Payment failed. Please try again or use a different payment method.')
                            );
                        }
                    });
                } catch (e) {
                    self.isPaymentProcessing(false);
                    self.paymentError($t('Unable to load payment form. Please try again.'));
                    console.error('Flitt Embed initialization error:', e);
                }
            }, function (err) {
                self.isPaymentProcessing(false);
                self.paymentError($t('Unable to load payment SDK. Please try again.'));
                console.error('Flitt SDK load error:', err);
            });
        },

        /**
         * Get payment method data for order placement.
         */
        getData: function () {
            return {
                'method': this.getCode(),
                'additional_data': {}
            };
        }
    });
});
