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
            // TODO: Get shipping recipient also from individual verndor configuration
        }

        return null;
    }

    public static function get_wcfm_cuit($vendor_id)
    {
        $cuit = get_user_meta($vendor_id, 'mobbex_tax_id', true);

        if ($cuit)
            return $cuit;

        // Try to get using previous save method
        $vendor_data = get_user_meta($vendor_id, 'wcfmmp_profile_settings', true);
        $cuit        = !empty($vendor_data['payment']['mobbex']['tax_id']) ? $vendor_data['payment']['mobbex']['tax_id'] : null;

        if ($cuit)
            update_user_meta($vendor_id, 'mobbex_tax_id', $cuit);

        return $cuit;
    }

    public static function get_wcfm_fee($item)
    {
        global $WCFMmp;

        $item = new WC_Order_Item_Product($item);

        return $WCFMmp->wcfmmp_commission->wcfmmp_get_order_item_commission(
            $item->get_order_id(),
            wcfm_get_vendor_id_by_post($item->get_product_id()),
            $item->get_product_id(),
            $item->get_variation_id(),
            $item->get_total(),
            $item->get_quantity()
        );
    }

    public static function get_items_by_vendor($order)
    {
        $integration = self::get_integration();

        // For dokan use the existing 'dokan_get_sellers_by' function
        if ($integration == 'dokan')
            return dokan_get_sellers_by($order);

        $items = [];

        foreach ($order->get_items() as $item) {
            // Get cuit from item vendor
            $vendor_id = wcfm_get_vendor_id_by_post($item->get_product()->get_id());

            // Exit if the vendor is not found
            if (empty($vendor_id))
                throw new \Exception('Vendor not found. Product #' . $item->get_product()->get_id());

            $items[$vendor_id][] = $item;
        }

        return $items;
    }

    public static function get_store_name($vendor_id)
    {
        return self::get_integration() == 'wcfm' ? get_user_meta($vendor_id, 'store_name', true) : dokan_get_store_info($vendor_id)['store_name'];
    }
}