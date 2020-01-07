/**
 * Bolt magento2 plugin
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @category   Bolt
 * @package    Bolt_Boltpay
 * @copyright  Copyright (c) 2018 Bolt Financial, Inc (https://www.bolt.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * Bolt minicart method renderer
 */
define(
    [
        'jquery',
        'uiComponent',
        'Magento_Ui/js/modal/modal'
    ],
    function (
        $,
        Component,
        modal
    ) {
        'use strict';
        /** Add view logic here if needed */
        return Component.extend({            
            // called to check if Bolt payment method should be displayed on the checkout page
            isPaymentAvailable: function () {
                try {
                    return !!window.boltConfig.publishable_key_checkout;
                } catch (e) {
                    return false;
                }
            },
        });
    }
);
