define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push({
        type: 'shubo_tbc',
        component: 'Shubo_TbcPayment/js/view/payment/shubo-tbc'
    });

    return Component.extend({});
});
