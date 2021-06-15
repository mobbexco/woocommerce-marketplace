<?php

class Mbbxm_Helper
{
    /**
     * Retrieve the current Marketplace Integration.
     * 
     * @return string|null 'dokan'|'wcfm'|null
     */
    public static function get_integration()
    {
        // Get integration saved value
        $option = get_option('mm_option_integration');

        // Check if the integration plugin is active and return
        return ($option == 'dokan' && function_exists('dokan') || $option == 'wcfm' && get_option('wcfmmp_installed')) ? $option : null;
    }

    /**
     * Retrieve the current Shipping Manager.
     * 
     * @return string 'default'|'custom'
     */
    public static function get_shipping_manager()
    {
        // Get Shipping Manager saved value
        $option = get_option('mm_option_shipping_manager');

        // Support previus version default value
        if ($option == 'dokan')
            $option = 'default';

        return $option;
    }

    /**
     * Retrieve the current Custom Shipping Options.
     * 
     * @return array|null $custom_options
     */
    public static function get_custom_shipping_options()
    {
        // Get custom shipping options 
        $custom_options = get_option('mm_option_custom_shipping') ? json_decode(get_option('mm_option_custom_shipping'), true) : [];

        // Validate option data format
        foreach ($custom_options as $config) {
            if (empty($config['shipping_method']) || empty($config['type']) || empty($config['cuit']) && $config['type'] == 'cuit') {
                error_log(__('Mobbex Marketplace ERROR: Custom Shipping Configs incorrect format.' . $e->getMessage(), 'mobbex-marketplace'));
                exit;
            }
        }

        return $custom_options;
    }

    /**
     * Get shipping recipient by integration.
     * 
     * @param string $integration 
     * 
     * @return string|null 'seller'|'admin'|null
     */
    public static function get_shipping_recipient($integration)
    {
        if ($integration == 'dokan') {
            return dokan_get_option('shipping_fee_recipient', 'dokan_selling', 'seller');
        }

        if ($integration == 'wcfm') {
            $wcfm_options = get_option('wcfm_commission_options');
            return (isset($wcfm_options['get_shipping']) && $wcfm_options['get_shipping'] == 'yes') ? 'seller' : 'admin';
        }

        return null;
    }
}