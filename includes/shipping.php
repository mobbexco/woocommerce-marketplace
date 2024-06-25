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

        // Get shippings and shipping recipient
        $shippings = $order->get_items('shipping');
        $recipient = Mbbxm_Helper::get_shipping_recipient($integration);

        // Distribute shipping data by vendor
        foreach ($shippings as $shipping) {
            $tax_id        = self::get_shipping_tax_id($shipping, $integration);
            $custom_option = self::get_custom_option($shipping, $custom_options);
            $entity = get_user_meta(
                $shipping->get_meta($integration == 'wcfm' ? 'vendor_id' : 'seller_id'),
                'mobbex_entity_uid',
                true
            );

            // Try to get custom tax id and recipient configured
            if ($shipping_manager == 'custom' && $custom_option) { 
                $recipient = $custom_option['type'];

                if ($recipient == 'cuit')
                    $tax_id = $custom_option['cuit'];
            }

            // Search vendor's position in split array
            $idFound = array_search($entity ?: $tax_id, array_column($checkout_data['split'], $entity ? 'entity' : 'tax_id'));

            if (is_int($idFound)) {
                // Add shipping total to vendor total
                $checkout_data['split'][$idFound]['total'] += $shipping->get_total();

                // If it must be paid by admin, it must be added as a fee
                if ($recipient === 'admin') {
                    $checkout_data['split'][$idFound]['fee'] += $shipping->get_total();
                }
            } else if ($recipient == 'cuit') {
                // Add as a normal split payment
                $checkout_data['split'][] = [
                    'tax_id'      => $tax_id,
                    'description' => 'Shipping Method ' . $shipping->get_name() . '. Cuit ' . $tax_id,
                    'total'       => $shipping->get_total(),
                    'reference'   => $checkout_data['reference'] . '_split_' . $tax_id,
                ];
            } else if (!$integration){
                // By default do not add the shipping amount in standalone mode, the admin will bear the shipping amount
            } else {
                // Exit if the vendor doesn't have products in the Order
                error_log('Mobbex Marketplace ERROR: Shipping from a seller without own products in the order.', 3, 'log.log');
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
        // Get vendor from shipping item
        $vendor_id = $shipping_item->get_meta($integration == 'wcfm' ? 'vendor_id' : 'seller_id');

        // Get tax id and return
        return $integration == 'wcfm' ? Mbbxm_Helper::get_wcfm_cuit($vendor_id) : get_user_meta($vendor_id, 'mobbex_tax_id', true);
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

        return is_int($key) ? $custom_options[$key] : null;
    }
}