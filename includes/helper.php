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
     * Retrieve the current Marketplace Mode
     */
    public static function get_marketplace_mode()
    {
        // Get vendor mode saved value
        $option = get_option('mm_option_marketplace_mode');

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
                error_log(__('Mobbex Marketplace ERROR: Custom Shipping Configs incorrect format.', 'mobbex-marketplace'));
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
        // Try to get wcfm options
        $wcfm_options = get_option('wcfm_commission_options');

        if ($integration == 'dokan')
            return dokan_get_option('shipping_fee_recipient', 'dokan_selling', 'seller');
        else if ($integration == 'wcfm')
            return (isset($wcfm_options['get_shipping']) && $wcfm_options['get_shipping'] == 'yes') ? 'seller' : 'admin'; // TODO: Get shipping recipient also from individual verndor configuration
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
    /*
    *  Get the entity uid by integration
    */
    public static function get_entity_uid($product_id)
    {
        if (self::get_integration() === 'dokan') 
        {           
            $vendor_id = dokan_get_vendor_by_product($product_id);
            return !empty($vendor_id) ? get_user_meta($vendor_id->get_id(), 'mobbex_entity_uid', true) : '';
        }
        if (self::get_integration() === 'wcfm')
        {
            $vendor_id = wcfm_get_vendor_id_by_post($product_id);
            return !empty($vendor_id) ? get_user_meta($vendor_id, 'mobbex_entity_uid', true) : '';
        }
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

    /**
     * Calculates the earning percent of a seller in dokan integration & add it in
     * the order panel with the discount/financial cost item.
     *
     * @param array $data
     * @param object $order
     */
    public function get_dokan_vendor_earning($data, $order)
    {
        //Calculate final earning
        $order_total            = $order->get_total();
        $net_amount             = dokan()->commission->get_earning_by_order($order, 'seller');
        $order_financial_cost   = $data['payment']['total'] - $data['checkout']['total'];
        $seller_earning_percent = $order_total/$data['checkout']['total'];
        $fee                    = $net_amount/$order_total;
        $seller_financial_cost  = $seller_earning_percent * $order_financial_cost;
        $seller_earning         = $seller_financial_cost * $fee + $net_amount;

        //Add financial cost/discount item in order panel
        $this->update_order_total($order, $order_total + $seller_financial_cost);

        return $seller_earning;
    }

    /**
     * Update order total paid using webhook formatted data.
     * 
     * @param WC_Order $order
     * @param int $total
     */
    public function update_order_total($order, $total)
    {
        if ($order->get_total() == $total || $order->get_meta('mbbx_total_updated'))
            return;

        // Add a fee item to order with the difference
        $item = new \WC_Order_Item_Fee;
        $item->set_props([
            'name'   => $total > $order->get_total() ? 'Cargo financiero' : 'Descuento',
            'amount' => $total - $order->get_total(),
            'total'  => $total - $order->get_total(),
        ]);
        
        $order->add_item($item);

        // Recalculate totals and add flag to not do it again
        $order->calculate_totals();
        $order->update_meta_data('mbbx_total_updated', 1);
    }
}