<?php

class Mbbxm_Shipping
{
    /**
     * Add Shippings configured by vendor to checkout data.
     * 
     * @param array $shippings
     * @param array $checkout_data
     * 
     * @return array $checkout_data
     */
    public static function add_shippings($order, $checkout_data)
    {
        $integration      = Mbbxm_Helper::get_integration();
        $shipping_manager = Mbbxm_Helper::get_shipping_manager();
        $custom_options   = Mbbxm_Helper::get_custom_shipping_options();

        // Shippings are not supported in standalone mode
        if (!$integration)
            return $checkout_data;

        // Get shippings and shipping recipient
        $shippings = $order->get_items('shipping');
        $recipient = Mbbxm_Helper::get_shipping_recipient($integration);

        // Distribute shipping data by vendor
        foreach ($shippings as $shipping) {
            $tax_id        = self::get_shipping_tax_id($shipping, $integration);
            $custom_option = self::get_custom_option($shipping, $custom_options);

            // Try to get custom tax id and recipient configured
            if ($shipping_manager == 'custom' && $custom_option) {
                $recipient = $custom_option['type']; // Investigar eníos de manager híbridos

                if ($recipient == 'cuit')
                    $tax_id = $custom_option['cuit'];
            }

            // Search vendor's position in split array
            $key = array_search($tax_id, array_column($checkout_data['split'], 'tax_id'));

            if (is_int($key)) {
                // Add shipping total to vendor total
                $checkout_data['split'][$key]['total'] += $shipping->get_total();

                // If it must be paid by admin, it must be added as a fee
                if ($recipient === 'admin') {
                    $checkout_data['split'][$key]['fee'] += $shipping->get_total();
                }
            } else if ($recipient == 'cuit') {
                // Add as a normal split payment
                $checkout_data['split'][] = [
                    'tax_id'      => $tax_id,
                    'description' => 'Shipping Method ' . $shipping->get_name() . '. Cuit ' . $tax_id,
                    'total'       => $shipping->get_total(),
                    'reference'   => $checkout_data['reference'] . '_split_' . $tax_id,
                ];
            } else {
                // Exit if the vendor doesn't have products in the Order
                error_log(__('Mobbex Marketplace ERROR: Shipping from a seller without own products in the order.', 'mobbex-marketplace'));
                exit;
            }
        }

        return $checkout_data;
    }

    /**
     * Get tax id from any shipping item.
     * 
     * @param WC_Order_Item $shipping_item 
     * @param string $integration 
     * 
     * @return string|null $tax_id 
     */
    public static function get_shipping_tax_id($shipping_item, $integration)
    {
        if ($integration == 'dokan') {
            // Get vendor from shipping item
            $vendor_id = $shipping_item->get_meta('seller_id');

            // Get tax id and return
            return get_user_meta($vendor_id, 'mobbex_tax_id', true);
        }

        if ($integration == 'wcfm') {
            // Get vendor data from shipping item
            $vendor_id   = $shipping_item->get_meta('vendor_id');
            $vendor_data = get_user_meta($vendor_id, 'wcfmmp_profile_settings', true);

            // Get tax id and return
            return isset($vendor_data['payment']['mobbex']['tax_id']) ? $vendor_data['payment']['mobbex']['tax_id'] : null;
        }

        return null;
    }

    /**
     * Get custom options for this shipping 
     * from custom shipping options array.
     * 
     * @param WC_Order_Item $shipping_item 
     * @param array $custom_options 
     * 
     * @return array|null $custom_option 
     */
    public static function get_custom_option($shipping_item, $custom_options)
    {
        // Search shipping method position in custom options array
        $method = $shipping_item->get_name();
        $key    = array_search($method, array_column($custom_options, 'shipping_method'));

        if (is_int($key)) {
            return $custom_options[$key];
        }

        return false;
    }
}