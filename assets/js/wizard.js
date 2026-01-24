/**
 * Setup Wizard JavaScript
 *
 * @package Zbooks
 * @author talas9
 * @link https://github.com/talas9/zbooks-for-woocommerce
 */

(function($) {
    'use strict';

    /**
     * Wizard handler.
     */
    const ZbooksWizard = {
        /**
         * Initialize.
         */
        init: function() {
            this.bindEvents();
        },

        /**
         * Bind events.
         */
        bindEvents: function() {
            // Form submission loading state
            $('form').on('submit', function() {
                const $btn = $(this).find('button[type="submit"]');
                $btn.prop('disabled', true).text(zbooksWizard.i18n.testing);
            });

            // Organization selection highlight
            $('.zbooks-wizard-org input[type="radio"]').on('change', function() {
                $('.zbooks-wizard-org').removeClass('selected');
                $(this).closest('.zbooks-wizard-org').addClass('selected');
            });

            // Datacenter change info
            $('#datacenter').on('change', function() {
                const dc = $(this).val();
                const domains = {
                    us: 'zoho.com',
                    eu: 'zoho.eu',
                    in: 'zoho.in',
                    au: 'zoho.com.au',
                    jp: 'zoho.jp'
                };
                console.log('Selected datacenter:', domains[dc]);
            });
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        ZbooksWizard.init();
    });

})(jQuery);
