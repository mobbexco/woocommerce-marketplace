<?php
/**
 * Plugin Name: Mobbex Marketplace
 * Description: Plugin to extend Mobbex Marketplace functionality.
 * Version: 1.2.0
 * WC tested up to: 4.2.2
 * Author: mobbex.com
 * Author URI: https://mobbex.com/
 * Copyright: 2021 mobbex.com
 */

class MobbexMarketplace
{
    public static $version = '1.2.0';

    /**
     * Settings.
     */
    public static $settings;

    /**
     * Errors.
     */
    public static $errors = [];

    /**
     * Mobbex URL.
     */
    public static $site_url = "https://www.mobbex.com";

    /**
     * Documentation URL.
     */
    public static $doc_url = "https://mobbex.dev";

    /**
     * Github URLs.
     */
    public static $github_url = "https://github.com/mobbexco/woocommerce-marketplace";
    public static $github_issues_url = "https://github.com/mobbexco/woocommerce-marketplace/issues";

    public function init()
    {
        try {
            MobbexMarketplace::check_dependencies();
            MobbexMarketplace::load_textdomain();
            MobbexMarketplace::load_update_checker();
            MobbexMarketplace::load_settings();
            MobbexMarketplace::load_wcfm_gateway();
        } catch (Exception $e) {
            MobbexMarketplace::$errors[] = $e->getMessage();
        }

        if (count(MobbexMarketplace::$errors)) {

            foreach (MobbexMarketplace::$errors as $error) {
                MobbexMarketplace::notice('error', $error);
            }

            return;
        }
        
        add_filter('plugin_row_meta', [$this, 'plugin_row_meta'], 10, 2);

        // Add marketplace data to checkout
        add_filter('mobbex_checkout_custom_data', [$this, 'modify_checkout_data'], 10, 2);
        
        // Save split data from Mobbex response
        add_action('mobbex_checkout_process', [$this, 'save_mobbex_response'], 10 , 2);

        // No integrations hooks
        if (empty(get_option('mm_option_integration'))) {
            // Product config management
            add_filter('woocommerce_product_data_tabs', [$this, 'add_product_tab']);
            add_action('woocommerce_product_data_panels', [$this, 'add_product_config']);
            add_action('woocommerce_process_product_meta', [$this, 'save_product_config']);

            // Category config management
            add_filter('product_cat_add_form_fields', [$this, 'add_category_config']);
            add_filter('product_cat_edit_form_fields', [$this, 'add_category_config']);
            add_filter('edited_product_cat', [$this, 'save_category_config']);
            add_filter('create_product_cat', [$this, 'save_category_config']);
        }

        if (get_option('mm_option_integration') === 'dokan') {
            // Vendor registration
            add_action('dokan_seller_registration_field_after', [$this, 'dokan_add_vendor_fields']);
            add_action('dokan_seller_registration_required_fields', [$this, 'dokan_validate_vendor_fields']);
            add_action('dokan_new_seller_created', [$this, 'dokan_save_vendor_fields']);

            // Store edit
            add_action('dokan_settings_after_store_name', [$this, 'dokan_add_store_fields']);
            add_action('dokan_store_profile_saved', [$this, 'dokan_save_vendor_fields']);

            // Admin vendor edit
            add_action('show_user_profile', [$this, 'dokan_admin_add_vendor_fields'], 30);
            add_action('edit_user_profile', [$this, 'dokan_admin_add_vendor_fields'], 30);
            add_action('dokan_process_seller_meta_fields', [$this, 'dokan_admin_save_vendor_fields']);

            // Unhold order payment action
            add_action('woocommerce_order_actions', [$this, 'add_unhold_actions']);
            add_action('woocommerce_order_actions_end', [$this, 'add_unhold_fields']);
            add_action('woocommerce_order_action_mobbex_unhold_payment', [$this, 'process_unhold_action']);
        }

        // WCFM integration
        if (get_option('mm_option_integration') === 'wcfm'){
            add_filter('wcfm_marketplace_withdrwal_payment_methods',[$this, 'wcfm_addMethod']);
            add_filter('wcfm_marketplace_settings_fields_billing', [$this,'wcfm_addVendortaxid'], 50, 2);
        }
    }

    /**
     * Check dependencies.
     *
     * @throws Exception
     */
    public static function check_dependencies()
    {
        if (!class_exists('WooCommerce')) {
            MobbexMarketplace::$errors[] = __('WooCommerce needs to be installed and activated.', 'mobbex-for-woocommerce');
        }

        if (!function_exists('WC')) {
            MobbexMarketplace::$errors[] = __('Mobbex requires WooCommerce to be activated', 'mobbex-for-woocommerce');
        }

        if(!class_exists('MobbexGateway')) {
            MobbexMarketplace::$errors[] = __('Mobbex Marketplace requires Mobbex for WooCommerce to be activated', 'mobbex-marketplace');
        }

        if(class_exists('MobbexGateway') && version_compare(MOBBEX_VERSION, '3.1.1', '<')) {
            MobbexMarketplace::$errors[] = __('Mobbex Marketplace requires Mobbex for WooCommerce version 3.1.1 or greater', 'mobbex-marketplace');
        }

        // Warning for wallet option support
        if (class_exists('MobbexGateway') && version_compare(MOBBEX_VERSION, '3.1.3', '<')) {
            MobbexMarketplace::notice('warning', __('Warning: Mobbex Marketplace requires Mobbex for Woocommerce 3.1.3 to use Wallet option with split payments', 'mobbex-marketplace'));
        }

        if (!is_ssl()) {
            MobbexMarketplace::$errors[] = __('Your site needs to be served via HTTPS to comunicate securely with Mobbex.', 'mobbex-for-woocommerce');
        }

        if (version_compare(WC_VERSION, '2.6', '<')) {
            MobbexMarketplace::$errors[] = __('Mobbex requires WooCommerce version 2.6 or greater', 'mobbex-for-woocommerce');
        }

        if (!function_exists('curl_init')) {
            MobbexMarketplace::$errors[] = __('Mobbex requires the cURL PHP extension to be installed on your server', 'mobbex-for-woocommerce');
        }

        if (!function_exists('json_decode')) {
            MobbexMarketplace::$errors[] = __('Mobbex requires the JSON PHP extension to be installed on your server', 'mobbex-for-woocommerce');
        }

        $openssl_warning = __('Mobbex requires OpenSSL >= 1.0.1 to be installed on your server', 'mobbex-for-woocommerce');
        if (!defined('OPENSSL_VERSION_TEXT')) {
            MobbexMarketplace::$errors[] = $openssl_warning;
        }

        preg_match('/^(?:Libre|Open)SSL ([\d.]+)/', OPENSSL_VERSION_TEXT, $matches);
        if (empty($matches[1])) {
            MobbexMarketplace::$errors[] = $openssl_warning;
        }

        if (!version_compare($matches[1], '1.0.1', '>=')) {
            MobbexMarketplace::$errors[] = $openssl_warning;
        }
    }

    /**
     * Load textdomain for translations.
     */
    public static function load_textdomain()
    {
        load_plugin_textdomain('mobbex-marketplace', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }

    /**
     * Load plugin update checker.
     */
    public static function load_update_checker()
    {
        require 'plugin-update-checker/plugin-update-checker.php';
        $myUpdateChecker = Puc_v4_Factory::buildUpdateChecker(
            'https://github.com/mobbexco/woocommerce-marketplace/',
            __FILE__,
            'mobbex-marketplace-plugin-update-checker'
        );
        $myUpdateChecker->getVcsApi()->enableReleaseAssets();
    }

    /**
     * Load settings page.
     */
    public static function load_settings()
    {
        require_once plugin_dir_path(__FILE__) . 'includes/settings.php';
        return new MM_Settings(__FILE__);
    }

    /**
     * Load WCFM Integration Class.
     */
    public static function load_wcfm_gateway()
    {
        require_once plugin_dir_path(__FILE__) . 'includes/wcfmmp-gateway-mobbex.php';
    }

    /**
     * Plugin row meta links
     *
     * @param  array $links already defined meta links
     * @param  string $file plugin file path and name being processed
     * @return array $links
     */
    public function plugin_row_meta($links, $file)
    {
        if (strpos($file, plugin_basename(__FILE__)) !== false) {
            $plugin_links = [
                '<a href="' . esc_url(MobbexMarketplace::$site_url) . '" target="_blank">' . __('Website') . '</a>',
                '<a href="' . esc_url(MobbexMarketplace::$doc_url) . '" target="_blank">' . __('Documentation') . '</a>',
                '<a href="' . esc_url(MobbexMarketplace::$github_url) . '" target="_blank">' . __('Contribute') . '</a>',
                '<a href="' . esc_url(MobbexMarketplace::$github_issues_url) . '" target="_blank">' . __('Report Issues') . '</a>',
            ];

            $links = array_merge($links, $plugin_links);
        }

        return $links;
    }

    /**
     * Use for debug.
     */
    public static function notice($type, $msg)
    {
        add_action('admin_notices', function () use ($type, $msg) {
            $class = esc_attr("notice notice-$type");
            $msg = esc_html($msg);

            ob_start();

            ?>

            <div class="<?=$class?>">
                <h2>Mobbex Marketplace</h2>
                <p><?=$msg?></p>
            </div>

            <?php

            echo ob_get_clean();
        });
    }

    /**
     * Add marketplace tab to product admin.
     */
    public function add_product_tab($tabs)
    {
        $tabs['mobbex_marketplace'] = array(
            'label'    => 'Mobbex Marketplace',
            'target'   => 'mobbex_marketplace',
            'priority' => 21,
        );
        return $tabs;
    }

    /**
     * Add marketplace config to product admin.
     */
    public function add_product_config()
    {
        echo '<div id="mobbex_marketplace" class="panel woocommerce_options_panel hidden">
        <hr><h2>Mobbex Marketplace</h2>';

        $cuit_field = [
            'id'          => 'mobbex_marketplace_cuit',
            'value'       => get_post_meta(get_the_ID(), 'mobbex_marketplace_cuit', true),
            'label'       => __('Tax Id', 'mobbex-marketplace'),
            'description' => __('Written without hyphens. Cuit of store to which you want to allocate the payment.', 'mobbex-marketplace'),
            'desc_tip'    => true
        ];

        $fee_field = [
            'id'          => 'mobbex_marketplace_fee',
            'value'       => get_post_meta(get_the_ID(), 'mobbex_marketplace_fee', true),
            'label'       => __('Fee (optional)', 'mobbex-marketplace'),
            'description' => __('This option has priority over the one applied at category and vendor level.', 'mobbex-marketplace'),
            'desc_tip'    => true
        ];

        woocommerce_wp_text_input($cuit_field);
        woocommerce_wp_text_input($fee_field); 
        echo '</div>';
    }

    /**
     * Save product admin config.
     * @param int|string $post_id
     */
    public function save_product_config($post_id)
    {
        // Get options and update product metadata
        $cuit = !empty($_POST['mobbex_marketplace_cuit']) ? esc_attr($_POST['mobbex_marketplace_cuit']) : null;
        $fee = !empty($_POST['mobbex_marketplace_fee']) ? esc_attr($_POST['mobbex_marketplace_fee']) : null;

        update_post_meta(get_the_ID(), 'mobbex_marketplace_cuit', $cuit);
        update_post_meta(get_the_ID(), 'mobbex_marketplace_fee', $fee);
    }

    /**
     * Add marketplace config to category admin.
     */
    public function add_category_config($term)
    {
        ?>
        <tr class="form-field">
            <th scope="row" valign="top" style="padding: 0;">
                <hr>
                <h2>Mobbex Marketplace</h2>
            </th>
        </tr>
            <tr class="form-field">
                <th scope="row" valign="top">
                    <label for="mobbex_marketplace_cuit"><?= __('Tax Id', 'mobbex-marketplace'); ?></label>
                </th>
                <td>
                    <input type="text" name="mobbex_marketplace_cuit" id="mobbex_marketplace_cuit" size="3" 
                    value="<?= get_term_meta($term->term_id, 'mobbex_marketplace_cuit', true) ?>">
                    <br/>
                    <span class="description"><?= __('Written without hyphens. Cuit of store to which you want to allocate the payment.', 'mobbex-marketplace') ?>
                    </span>
                </td>
            </tr>

        <tr class="form-field">
            <th scope="row" valign="top" style="border-bottom: 1px solid #ddd;">
                <label for="mobbex_marketplace_fee"><?= __('Fee (optional)', 'mobbex-marketplace'); ?></label>
            </th>
            <td>
                <input type="text" name="mobbex_marketplace_fee" id="mobbex_marketplace_fee" size="3"
                value="<?= get_term_meta($term->term_id, 'mobbex_marketplace_fee', true) ?>">
                <br/>
                <span class="description"><?= __('This option has priority over the one applied at vendor and default level.', 'mobbex-marketplace') ?>
                </span>
            </td>
        </tr>
        <?php
    }

    /**
     * Save category admin config.
     * @param int|string $post_id
     */
    public function save_category_config($term_id)
    {
        // Get options and update category metadata
        $cuit = !empty($_POST['mobbex_marketplace_cuit']) ? esc_attr($_POST['mobbex_marketplace_cuit']) : null;
        $fee = !empty($_POST['mobbex_marketplace_fee']) ? esc_attr($_POST['mobbex_marketplace_fee']) : null;

        update_term_meta($term_id, 'mobbex_marketplace_cuit', $cuit);
        update_term_meta($term_id, 'mobbex_marketplace_fee', $fee);
    }

    /**
     * Modify checkout data to add split functionality.
     * @param array $checkout_data
     */
    public function modify_checkout_data($checkout_data, $order_id)
    {
        // Wallet split payment only works with Mobbex for Woocommerce > 3.1.2
        if (!empty($checkout_data['wallet']) && version_compare(MOBBEX_VERSION, '3.1.3', '<')) {
            return;
        }

        $order = wc_get_order($order_id);

        if (get_option('mm_option_integration') === 'dokan' && function_exists('dokan_get_sellers_by')) {
            // Get vendors with Product items from order
            $vendors = dokan_get_sellers_by($order);

            foreach ($vendors as $vendor_id => $items) {
                // Get total price, fee and product IDs from items
                $total       = 0;
                $fee         = 0;
                $product_ids = [];
                foreach ($items as $item) {
                    $total        += $item->get_total();
                    $fee          += dokan()->commission->get_earning_by_product($item->get_product(), 'admin') * $item->get_quantity();
                    $product_ids[] = $item->get_product()->get_id();
                }

                // Split data
                $cuit        = get_user_meta($vendor_id, 'mobbex_tax_id', true);
                $description = "Split payment - CUIT: $cuit - Product IDs: " . implode(", ", $product_ids);
                $reference   = $checkout_data['reference'] . '_split_' . $cuit;
                $hold        = (get_user_meta($vendor_id, 'mobbex_marketplace_hold', true) === 'yes');

                $checkout_data['split'][] = [
                    'tax_id' => $cuit,
                    'description' => $description,
                    'total' => $total,
                    'reference' => $reference,
                    'fee' => $fee,
                    'hold' => $hold,
                ];
            }
            // Get shipping items from order
            $shippings = $order->get_items('shipping');

            // Get Shipping Manager
            $shipping_manager = get_option('mm_option_shipping_manager');

            // Add checkout data according the Shipping Manager configured
            if ($shipping_manager === 'custom') {
                $checkout_data = $this->add_custom_shippings($shippings, $checkout_data);
            } else if ($shipping_manager === 'dokan') {
                $checkout_data = $this->add_dokan_shippings($shippings, $checkout_data);
            } else {
                error_log(__('Mobbex Marketplace ERROR: Shipping Manager is not configured.', 'mobbex-marketplace'));
                exit;
            }
        } else {
            $items = $order->get_items();

            foreach ($items as $item) {
                $product_id = $item->get_product()->get_id();

                // Get configs from product/category/vendor/default
                $cuit = $this->get_cuit($product_id);
                $fee = $this->get_fee($product_id);
                $hold = $this->get_hold($product_id);

                if (!empty($cuit)) {
                    // Check if a product with the same cuit is already added
                    if (!empty($checkout_data['split'])) {
                        foreach ($checkout_data['split'] as $key => $payment) {
                            if ($payment['tax_id'] === $cuit) {
                                // Combine values
                                $payment['total'] += $item->get_total();
                                $payment['fee'] += $fee;
                                $payment['description'] .= ", $product_id";
    
                                $checkout_data['split'][$key] = $payment;
                                // Go to next item
                                continue 2;
                            }
                        }
                    }

                    // Add split payment
                    $checkout_data['split'][] = [
                        'tax_id' => $cuit,
                        'description' => "Cuit $cuit. Product IDs: $product_id",
                        'total' => $item->get_total(),
                        'reference' => $checkout_data['reference'] . '_split_' . $cuit,
                        'fee' => $fee,
                        'hold' => $hold,
                    ];
                }
            }
        }

        // Catch empty CUITs
        if (isset($checkout_data['split'])) {
            foreach ($checkout_data['split'] as $payment) {
                if (empty($payment['tax_id'])) {
                    $error = sprintf(__('Mobbex Marketplace ERROR: Attempt to make a payment with an empty CUIT. Order Id %s', 'mobbex-marketplace'), $order_id);
                    error_log($error);
                    exit;
                }
            }
        }

        // Add Plugin versions
        $checkout_data['options']['platform']['extensions'][] = [
            'name'    => 'mobbex_marketplace',
            'version' => MobbexMarketplace::$version,
        ];

        if (function_exists('dokan')) {
            $checkout_data['options']['platform']['extensions'][] = [
                'name'    => 'dokan',
                'version' => dokan()->version,
            ];
        }

        return $checkout_data;
    }

    /**
     * Save split data from Mobbex checkout response.
     * @param array $response
     */
    public function save_mobbex_response($response, $order_id = null)
    {
        // If order id is not provided
        if (empty($order_id)) {
            $order_id = str_replace('Orden #', '', $response['description']);
        }

        // Save split data if exists
        if (!empty($response['split']) && !empty($order_id)) {
            update_post_meta($order_id, 'mobbex_split_data', $response['split']);

            // Add note if any split is on hold
            foreach ($response['split'] as $split) {
                if (!empty($split['hold'])) {
                    $order = wc_get_order($order_id);
                    $order->add_order_note(__('A payment of this order is withheld', 'mobbex-marketplace'));
                }
            }
        }
    }

    /**
     * Get cuit from vendor/product/category.
     * @param int $product_id
     */
    public function get_cuit($product_id)
    {
        // Set default to null
        $vendor_cuit = null;
        $product_cuit = null;
        $category_cuit = null;

        // Get cuit from Vendor
        if (get_option('mm_option_integration') === 'dokan' && function_exists('dokan_get_vendor_by_product')) {
            $vendor = dokan_get_vendor_by_product($product_id);
            if (!empty($vendor)) {
                $user_id = $vendor->get_id();
                $vendor_cuit = get_user_meta($user_id, 'mobbex_tax_id', true);
            }
            // If dokan is enabled only use Dokan Vendor cuits
            return $vendor_cuit;
        } else if (get_option('mm_option_integration') === 'wcfm' && function_exists('wcfm_get_vendor_store_by_post')){
            $vendor_id = wcfm_get_vendor_id_by_post($product_id);
            if($vendor_id){
                $vendor_data = get_user_meta($vendor_id, 'wcfmmp_profile_settings', true);
                if($vendor_data) $vendor_cuit = $vendor_data['payment']['mobbex']['tax_id'];
                // If WCFM is enabled only use WCFM Vendor cuits
                return $vendor_cuit;
            }
        }

        // Get cuit from product
        $product_cuit = get_post_meta($product_id, 'mobbex_marketplace_cuit', true);

        // Get cuit from categories
        $categories = get_the_terms($product_id, 'product_cat');
        foreach ($categories as $category) {
            $category_cuit = get_term_meta($category->term_id, 'mobbex_marketplace_cuit', true);
            // Break foreach on first match
            if (!empty($category_cuit))
                break;
        }

        // Return cuit in order of specificity
        if (!empty($product_cuit)) {
            return $product_cuit;
        } else if (!empty($category_cuit)) {
            return $category_cuit;
        }

        return null;
    }

    /**
     * Get fee from product/category/vendor/default.
     * @param int $product_id
     */
    public function get_fee($product_id)
    {
        // Set default to null
        $product_fee = null;
        $category_fee = null;
        $vendor_fee = null;
        $default_fee = null;

        //Get product using woocommerce method
        $product = wc_get_product( $product_id );
        // Get fee from product
        if(get_option('mm_option_integration') === 'wcfm' ){
            $product_fee = $this->wcfm_product_fee($product_id,$product);
        }else{
            $product_fee = get_post_meta($product_id, 'mobbex_marketplace_fee', true);
        }
        // Get fee from categories
        $categories = get_the_terms($product_id, 'product_cat');
        foreach ($categories as $category) {
            $category_fee = get_term_meta($category->term_id, 'mobbex_marketplace_fee', true);
            // Break foreach on first match
            if (!empty($category_fee))
                break;
        }

        // Get fee from Dokan Vendor
        if (get_option('mm_option_integration') === 'dokan' && function_exists('dokan_get_vendor_by_product')) {
            $vendor = dokan_get_vendor_by_product($product_id);
            if (!empty($vendor)) {
                $user_id = $vendor->get_id();
                $vendor_fee = get_user_meta($user_id, 'mobbex_marketplace_fee', true);
            }
        } else if (get_option('mm_option_integration') === 'wcfm' && function_exists('wcfm_get_vendor_store_by_post')){
            // Get fee from WCFM Vendor
            $vendor_id  = wcfm_get_vendor_id_by_post($product_id);
            if($vendor_id){
                $vendor_fee = $this->wcfm_vendor_fee($vendor_id,$product);
            }
        }

        // Get default fee from plugin config
        $default_fee = get_option('mm_option_default_fee');

        // Return fee in order of specificity
        if (!empty($product_fee)) {
            return $product_fee;
        } else if (!empty($category_fee)) {
            return $category_fee;
        } else if (!empty($vendor_fee)) {
            return $vendor_fee;
        } else if (!empty($default_fee)) {
            return $default_fee;
        }

        return 0;
    }

    /**
     * Return WCFM product fee
     * @param $product_id : integer
     * @param $product : Product
     * @return real
     */
    private function wcfm_product_fee($product_id, $product ){
        $product_commission_data = get_post_meta($product_id, '_wcfmmp_commission', true);
        //if the product commission is set
        if($product_commission_data){
            // Comission modes : fixed / percent / percent + fixed, global is calculated in vendor fee function
            switch($product_commission_data['commission_mode']){
                case 'fixed':
                    $product_fee = $product_commission_data['commission_fixed'];
                break;
                case 'percent':
                    $commission_percent = get_post_meta( $product_id, 'commission_percent', true);
                    $product_fee = $commission_percent * $product->get_price() / 100;
                break;
                case 'percent_fixed':
                    $commission_percent = get_post_meta( $product_id, 'commission_percent', true);
                    $commission_fixed = $product_commission_data['commission_fixed'];
                    $product_fee = ($commission_percent * $product->get_price() / 100) + $commission_fixed;
                break;         
            }
        }else{
            $product_fee = get_post_meta($product_id, 'mobbex_marketplace_fee', true);    
        }

        return $product_fee;
    }

    /**
     * Return WCFM vendor fee
     * @param $vendor_id : integer
     * @param $product : Product
     * @return real
     */
    private function wcfm_vendor_fee($vendor_id, $product ){
        $vendor_fee = null;
        $vendor = wcfm_get_vendor_store_address_by_vendor( $vendor_id );
        $vendor_data = get_user_meta( $vendor_id, 'wcfmmp_profile_settings', true );
        // Comission modes : fixed / percent / percent + fixed or global in case is not defined
        $vendor_commission_mode        = isset( $vendor_data['commission']['commission_mode'] ) ? $vendor_data['commission']['commission_mode'] : 'global';
        switch($vendor_commission_mode){
            case 'fixed':
                $vendor_fee = isset( $vendor_data['commission']['commission_fixed'] ) ? $vendor_data['commission']['commission_fixed'] : '0';
            break;
            case 'percent':
                $commission_percent = isset( $vendor_data['commission']['commission_percent'] ) ? $vendor_data['commission']['commission_percent'] : '0';        
                if($commission_percent)
                    $vendor_fee = ($commission_percent * $product->get_price() / 100);
            break;
            case 'percent_fixed':
                $vendor_fee = isset( $vendor_data['commission']['commission_fixed'] ) ? $vendor_data['commission']['commission_fixed'] : '0';
                $commission_percent = isset( $vendor_data['commission']['commission_percent'] ) ? $vendor_data['commission']['commission_percent'] : '0';        
                if($commission_percent)
                    $vendor_fee = ($commission_percent * $product->get_price() / 100) + $vendor_fee;
            break;
            case 'global':
                //Get commission options from admin settings
                $wcfm_commission_options = get_option( 'wcfm_commission_options', array() );
                $comission_mode = $wcfm_commission_options['commission_mode'];
                switch($comission_mode){
                    case 'fixed':
                        $vendor_fee = isset( $wcfm_commission_options['commission_fixed'] ) ? $wcfm_commission_options['commission_fixed'] : '0';    
                    break;
                    case 'percent':
                        $vendor_fee = $wcfm_commission_options['commission_percent'] * $product->get_price() / 100;
                    break;   
                    case 'percent_fixed':
                        $vendor_fee = isset( $wcfm_commission_options['commission_fixed'] ) ? $wcfm_commission_options['commission_fixed'] : '0';    
                        $commission_percent = isset( $wcfm_commission_options['commission_percent'] ) ? $wcfm_commission_options['commission_percent'] : '0';        
                        if($commission_percent)
                            $vendor_fee = ($commission_percent * $product->get_price() / 100) + $vendor_fee;
                    break;
                }
            break;
        }
        
        return $vendor_fee;
    }

    /**
     * @deprecated
     * Get hold from Dokan Vendor.
     * @param int $product_id
     */
    public function get_hold($product_id)
    {
        if (get_option('mm_option_integration') === 'dokan' && function_exists('dokan_get_vendor_by_product')) {
            $vendor = dokan_get_vendor_by_product($product_id);
            if (!empty($vendor)) {
                $user_id = $vendor->get_id();
                // Get hold from Dokan Vendor meta data
                return (get_user_meta($user_id, 'mobbex_marketplace_hold', true) === 'yes');
            }
        }
        return null;
    }

    /**
     * Add Mobbex fields to vendor registration.
     * 
     * (Dokan hook)
     */
    public function dokan_add_vendor_fields()
    {
        ?>
        <p class="form-row form-group form-row-wide">
            <label for="mobbex_tax_id"><?= __('Tax Id', 'mobbex-marketplace') ?><span class="required">*</span></label>
            <input type="text" class="input-text form-control" name="mobbex_tax_id" id="mobbex_tax_id" required="required"/>
            <small><?= __('Tax Id configured in your Mobbex commerce', 'mobbex-marketplace') ?></small>
        </p>
        <?php
    }
    
    /**
     * Validate Mobbex fields on vendor registration.
     * 
     * (Dokan hook)
     * @param array $required_fields
     * @return array $required_fields
     */
    public function dokan_validate_vendor_fields($required_fields)
    {
        // Add Mobbex Tax Id to required fields array
        $required_fields['mobbex_tax_id'] = __('Please enter the Tax Id configured in your Mobbex commerce.', 'mobbex-marketplace');
        return $required_fields;
    }

    /**
     * Save Mobbex data from vendor registration.
     * 
     * (Dokan hook)
     * @param int $user_id
     */
    public function dokan_save_vendor_fields($user_id)
    {
        // If Mobbex tax id is sent save it
        if (!empty($user_id) && $_POST['mobbex_tax_id']) {
            update_user_meta($user_id, 'mobbex_tax_id', sanitize_text_field($_POST['mobbex_tax_id']));
        } else {
            // Report save error
            return new WP_Error('mobbex_vendor_error', __('Failed to save vendor Mobbex information with User Id:' . $user_id, 'mobbex-marketplace'));
        }
    }

    /**
     * Add Mobbex fields to admin vendor edit.
     * 
     * (Dokan hook)
     * @param WP_User $user
     */
    public function dokan_admin_add_vendor_fields($user)
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        if (!user_can($user, 'dokandar')) {
            return;
        }

        ?>
        <table class="form-table">
            <tbody>
                <tr>
                    <th style="padding: 0;">
                        Mobbex Options :
                    </th>
                </tr>

                <tr>
                    <th>
                        <label for="mobbex_tax_id"><?= __('Tax Id', 'mobbex-marketplace'); ?></label>
                    </th>
                    <td>
                        <input type="text" name="mobbex_tax_id" id="mobbex_tax_id" class="regular-text"
                        value="<?= get_user_meta($user->ID, 'mobbex_tax_id', true) ?>">
                        <br/>
                        <span class="description">
                        <?= __('Tax Id configured in Mobbex commerce', 'mobbex-marketplace') ?>
                        </span>
                    </td>
                </tr>

                <tr>
                    <th>
                        <?= __('Payment Withholding', 'mobbex-marketplace') ?>
                    </th>
                    <td>
                        <label for="mobbex_marketplace_hold">
                            <input type="checkbox" name="mobbex_marketplace_hold" id="mobbex_marketplace_hold"
                            <?= (get_user_meta($user->ID, 'mobbex_marketplace_hold', true) === 'yes') ? 'checked="checked"' : ''?>>
                            <?= __('Withhold payments', 'mobbex-marketplace') ?>
                            <p class="description"><?= __('You can release them on order panel using "Unhold Mobbex Payment" action', 'mobbex-marketplace') ?>
                            </p>
                        </label>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    /**
     * Save Mobbex fields from admin vendor edit.
     * 
     * (Dokan hook)
     * @param int $user_id
     */
    public function dokan_admin_save_vendor_fields($user_id)
    {
        $post_data = wp_unslash($_POST);

        if (!empty($user_id)) {
            // If Mobbex values is sent save it
            $tax_id = isset($post_data['mobbex_tax_id']) ? sanitize_text_field($post_data['mobbex_tax_id']) : '';
            $hold = isset($post_data['mobbex_marketplace_hold']) ? 'yes' : 'no';

            update_user_meta($user_id, 'mobbex_tax_id', $tax_id);
            update_user_meta($user_id, 'mobbex_marketplace_fee', $fee);
            update_user_meta($user_id, 'mobbex_marketplace_hold', $hold);
        } else {
            // Report save error
            MobbexMarketplace::notice('error', __('Failed to save vendor Mobbex information with User Id:' . $user_id, 'mobbex-marketplace'));
        }
    }

    /**
     * Add Mobbex fields to vendor store edit.
     * 
     * (Dokan hook)
     * @param int $user_id
     */
    public function dokan_add_store_fields($user_id)
    {
        ?>
        <div class="dokan-form-group">
            <label class="dokan-w3 dokan-control-label" for="mobbex_tax_id"><?= __('Tax Id', 'mobbex-marketplace') ?></label>

            <div class="dokan-w5 dokan-text-left">
                <input type="text" name="mobbex_tax_id" id="mobbex_tax_id" required value="<?= get_user_meta($user_id, 'mobbex_tax_id', true) ?>" class="dokan-form-control">
                <small><?= __('Tax Id configured in your Mobbex commerce', 'mobbex-marketplace') ?></small>
            </div>
        </div>
        <?php
    }

    /**
     * Add Unhold payment actions to order actions select.
     * Only added for orders with holded payments
     *
     * @param array $actions
     * @return array $actions
     */
    public function add_unhold_actions($actions) 
    {
        global $theorder;
        $order_id = $theorder->id;

        // Add action if any split is on hold
        $split_data = get_post_meta($order_id, 'mobbex_split_data', true);
        if (!empty($split_data) && is_array($split_data)) {
            foreach ($split_data as $split) {
                if ($split['hold']) {
                    $actions['mobbex_unhold_payment'] = __('Unhold Mobbex payment', 'mobbex-marketplace');
                    break;
                }
            }
        }

        return $actions;
    }

    /**
     * Add Unhold payments select to Order actions form.
     *
     * @param int $order_id
     */
    public function add_unhold_fields($order_id) 
    {
        $unholdables = [];

        // Add select if any split is on hold
        $split_data = get_post_meta($order_id, 'mobbex_split_data', true);
        if (!empty($split_data) && is_array($split_data)) {
            foreach ($split_data as $split) {
                if ($split['hold']) {
                    $unholdables[$split['tax_id']] = [
                        'total' => $split['total'],
                        'uid' => $split['uid'],
                    ];
                }
            }
        }

        // Add select after actions
        if (!empty($unholdables)) {
            // Get vendor data
            $vendors = dokan_get_sellers_by($order_id);
            foreach ($vendors as $vendor_id => $vendor) {
                $vendor_cuit = get_user_meta($vendor_id, 'mobbex_tax_id', true);
                if (array_key_exists($vendor_cuit, $unholdables)) {
                    $unholdables[$vendor_cuit]['name'] = get_user_meta($vendor_id, 'nickname', true);
                }
            }

            // Create select element
            $options = '';
            foreach ($unholdables as $vendor_data) {
                $options .= '<option value="' . $vendor_data['uid'] . '">' . sprintf(__('Unhold $ %s of %s', 'mobbex-marketplace'), $vendor_data['total'], $vendor_data['name']) . '</option>';
            }
            $select = '<select name="mobbex_unhold" style="float: left; max-width: 225px; display: none;" id="mobbex_unhold">' . $options . '</select>';

            ?>
            <script>
                var actionsCont = document.getElementById('actions');
                var selectStr = '<?= $select ?>';
                actionsCont.innerHTML += selectStr;

                var actionsSelect = document.getElementsByName('wc_order_action')[0];
                var select = document.getElementById('mobbex_unhold');

                function toggleSelectDisplay() {
                    var value = actionsSelect.options[actionsSelect.selectedIndex].value;
                    if (value && value == 'mobbex_unhold_payment') {
                        select.style.display = 'block';
                    } else {
                        select.style.display = 'none';
                    }
                }

                toggleSelectDisplay();
                actionsSelect.onchange = function() { toggleSelectDisplay()};
            </script>
        <?php 
        } 
    }

    /**
     * Process Unhold payment actions.
     *
     * @param WC_Order $order
     */
    public function process_unhold_action($order) 
    {
        $post_data = wp_unslash($_POST);

        // If a vendor is selected
        if ($post_data['mobbex_unhold']) {
            $status_message = $this->unhold_payment($post_data['mobbex_unhold']);
            if (is_string($status_message)) {
                // Add order note with status
                $order->add_order_note($status_message);
            } else {
                // Add order note with status
                $order->add_order_note(__('Unhold Error.', 'mobbex-marketplace'));
            }
        }
    }

    /**
     * Unhold payment from Mobbex.
     * @param string $uid
     */
    public function unhold_payment($uid)
    {
        // Check required params
        $api_key = get_option('mm_option_api_key');
        $access_token = get_option('mm_option_access_token');
        if (!$uid || !$api_key || !$access_token) {
            return new WP_Error('mobbex_unhold_error', __('Unhold Error: UID, API key or access token is empty or not configured.', 'mobbex-marketplace'));
        }

        $response = wp_remote_get("https://api.mobbex.com/p/operations/$uid/release", [
            'headers' => [
                'cache-control' => 'no-cache',
                'content-type' => 'application/json',
                'x-api-key' => $api_key,
                'x-access-token' => $access_token,
            ],
        ]);

        $result = json_decode($response['body']);

        if ($result instanceof stdClass) {
            if ($result->result) {
                return $result->status_message;
            } else if ($result->error) {
                return $result->error;
            }
        }
        return new WP_Error('mobbex_unhold_error', __('Unhold Error: Sorry! This is not a unholdable transaction.', 'mobbex-marketplace'));
    }

    /**
     * Add Mobbex as payment method
     * @param $payment_methods : array
     * @return array
     */
    public function wcfm_addMethod( $payment_methods ) 
    {
        try {
            if(!array_key_exists('mobbex', $payment_methods))
            {
                $payment_methods['mobbex'] = 'Mobbex';
            }
        } catch (Exception $e) {
            echo 'Excepción capturada: ',  $e->getMessage(), "\n";
        }
        return $payment_methods;    
    }

    /**
     * Add Vendor tax id in the payment  page
     * Works only in Vendor registration and edit pages
     * Not working for Admin 
     * @param $vendor_billing_fileds : array
     * @param $vendor_id : int
     * @return array
     */
    public function wcfm_addVendortaxid( $vendor_billing_fileds, $vendor_id ) 
    {
        $gateway_slug = 'mobbex';
        $vendor_data = get_user_meta( $vendor_id, 'wcfmmp_profile_settings', true );
        if( !$vendor_data ) $vendor_data = array();
        $mobbex_tax_id = isset( $vendor_data['payment'][$gateway_slug]['tax_id'] ) ? esc_attr( $vendor_data['payment'][$gateway_slug]['tax_id'] ) : '' ;
        
        //if the social key in the array is empty then it's a vendor registration and the field need to have in_table attribute
        if(sizeof($vendor_data['social']) == 0){
            $vendor_mobbex_billing_fileds = array(
                "mobbex" => array('label' => __('Tax ID(CUIT)', 'wc-frontend-manager'), 'name' => 'vendor_data[payment][mobbex][tax_id]', 'type' => 'number', 'in_table' => 'yes', 'class' => 'wcfm-text wcfm_ele paymode_field paymode_mobbex', 'label_class' => 'wcfm_title wcfm_ele paymode_field paymode_mobbex', 'value' => $mobbex_tax_id ),
            );
        }else{
            $vendor_mobbex_billing_fileds = array(
                $gateway_slug => array('label' => __('Tax ID', 'wc-frontend-manager'), 'name' => 'payment['.$gateway_slug.'][tax_id]', 'type' => 'text', 'class' => 'wcfm-text wcfm_ele paymode_field paymode_'.$gateway_slug, 'label_class' => 'wcfm_title wcfm_ele paymode_field paymode_'.$gateway_slug, 'value' => $mobbex_tax_id ),
            );
        }
        
        $vendor_billing_fileds = array_merge( $vendor_billing_fileds, $vendor_mobbex_billing_fileds );
        return $vendor_billing_fileds;
    }

    /**
     * Add Dokan Pro Shippings to checkout data.
     * 
     * @param array $shipping
     * @return array $checkout_data
     */
    public function add_dokan_shippings($shippings, $checkout_data)
    {
        // Get Dokan shipping recipient configuration
        $shipping_fee_recipient = dokan_get_option('shipping_fee_recipient', 'dokan_selling', 'seller');
        // Exit if config data do not look fine
        if ($shipping_fee_recipient != 'seller' && $shipping_fee_recipient != 'admin') {
            error_log(__('Mobbex Marketplace ERROR: Dokan Shipping Recipient incompatible.', 'mobbex-marketplace'));
            exit;
        }

        foreach ($shippings as $shipping) {
            // Get Vendor and CUIT from shipping
            $vendor_id = $shipping->get_meta('seller_id');
            $cuit = get_user_meta($vendor_id, 'mobbex_tax_id', true);

            foreach ($checkout_data['split'] as $key => $payment) {
                // Add shipping total to vendor total (in split payment array)
                if ($payment['tax_id'] == $cuit) {
                    $checkout_data['split'][$key]['total'] += $shipping->get_total();

                    if ($shipping_fee_recipient === 'admin') {
                        // If it must be paid by admin, it must be added as a fee
                        $checkout_data['split'][$key]['fee'] += $shipping->get_total();
                    }
                }
            }

            // Exit if assigned seller did not have any products in the Order
            if (!in_array($cuit, array_column($checkout_data['split'], 'tax_id'))) {
                error_log(__('Mobbex Marketplace ERROR: Shipping from a seller without own products in the order.', 'mobbex-marketplace'));
                exit;
            }
        }

        return $checkout_data;
    }

    /**
     * Add Custom Configured Shippings to checkout data.
     * 
     * @param array $shipping
     * @return array $checkout_data
     */
    public function add_custom_shippings($shippings, $checkout_data)
    {
        $custom_shippings_configs = json_decode(get_option('mm_option_custom_shipping'), true);
        // Exit if config data do not look fine
        if (empty($custom_shippings_configs)) {
            error_log(__('Mobbex Marketplace ERROR: Custom Shipping Configs active but empty.', 'mobbex-marketplace'));
            exit;
        }

        try {
            foreach ($shippings as $shipping) {
                foreach ($custom_shippings_configs as $shipping_config) {
                    // Get options
                    $method = $shipping_config['shipping_method'];
                    $type   = $shipping_config['type'];
                    $cuit   = (isset($shipping_config['cuit'])) ? $shipping_config['cuit'] : '';

                    if ($shipping->get_name() == $method) {
                        if ($type == 'cuit') {
                            // Add as a normal split payment
                            $checkout_data['split'][] = [
                                'tax_id' => $cuit,
                                'description' => "Shipping Method $method.Cuit $cuit.",
                                'total' => $shipping->get_total(),
                                'reference' => $checkout_data['reference'] . '_split_' . $cuit,
                                'fee' => null,
                                'hold' => null,
                            ];

                            break;
                        } else if ($type == 'vendor' || $type == 'admin') {
                            // Get Vendor and CUIT from shipping
                            $vendor_id = $shipping->get_meta('seller_id');
                            $vendor_cuit = get_user_meta($vendor_id, 'mobbex_tax_id', true);

                            foreach ($checkout_data['split'] as $key => $payment) {
                                // Add shipping total to vendor total (in split payment array)
                                if ($payment['tax_id'] == $vendor_cuit) {
                                    $checkout_data['split'][$key]['total'] += $shipping->get_total();

                                    if ($type === 'admin') {
                                        // If it must be paid by admin, it must be added as a fee
                                        $checkout_data['split'][$key]['fee'] += $shipping->get_total();
                                    }
                                }
                            }

                            // Exit if shipping seller id did not have any products in the Order
                            if (!in_array($vendor_cuit, array_column($checkout_data['split'], 'tax_id'))) {
                                error_log(__('Mobbex Marketplace ERROR: Custom Shipping Configs active but empty.', 'mobbex-marketplace'));
                                exit;
                            }

                            break;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            error_log(__('Mobbex Marketplace ERROR: Custom Shipping Configs incorrect format.' . $e->getMessage(), 'mobbex-marketplace'));
            exit;
        }

        return $checkout_data;
    }
}

$mobbexMarketplace = new MobbexMarketplace;
add_action('plugins_loaded', [ & $mobbexMarketplace, 'init']);