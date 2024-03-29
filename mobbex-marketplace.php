<?php
/**
 * Plugin Name: Mobbex Marketplace
 * Description: Plugin to extend Mobbex Marketplace functionality.
 * Version: 1.6.0
 * WC tested up to: 4.2.2
 * Author: mobbex.com
 * Author URI: https://mobbex.com/
 * Copyright: 2021 mobbex.com
 */

require_once plugin_dir_path(__FILE__) . 'includes/helper.php';
require_once plugin_dir_path(__FILE__) . 'includes/settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/shipping.php';
require_once plugin_dir_path(__FILE__) . 'includes/wcfmmp-gateway-mobbex.php';
require_once plugin_dir_path(__FILE__) . 'plugin-update-checker/plugin-update-checker.php';

class MobbexMarketplace
{
    /** @var \Mbbxm_Helper */
    public static $helper;

    /** @var \MM_Settings */
    public static $settings;

    /** @var \Mobbex\WP\Checkout\Model\Logger */
    public static $logger;

    /** Plugin info */
    public static $version           = '1.6.0';
    public static $errors            = [];
    public static $site_url          = "https://mobbex.com";
    public static $doc_url           = "https://mobbex.dev";
    public static $github_url        = "https://github.com/mobbexco/woocommerce-marketplace";
    public static $github_issues_url = "https://github.com/mobbexco/woocommerce-marketplace/issues";

    public function init()
    {
        try {
            self::check_dependencies();
            self::load_textdomain();
            self::load_update_checker();
        } catch (\Exception $e) {
            self::$errors[] = $e->getMessage();
        }

        foreach (MobbexMarketplace::$errors as $error)
            self::notice('error', $error);

        if (count(self::$errors))
            return;

        // Load all classes
        self::$helper   = new \Mbbxm_Helper();
        self::$logger   = new \Mobbex\WP\Checkout\Model\Logger();
        self::$settings = new \MM_Settings(__FILE__);

        add_filter('plugin_row_meta', [$this, 'plugin_row_meta'], 10, 2);
        
        // Add marketplace data to checkout
        add_filter('mobbex_checkout_custom_data', [$this, 'modify_checkout_data'], 10, 2);
        
        // Save split data from Mobbex response
        add_action('mobbex_checkout_process', [$this, 'save_mobbex_response'], 10 , 2);
        
        //Filter Mobbex Webhook
        add_filter('mobbex_order_webhook', [$this, 'mobbex_webhook'], 10, 1);

        //Filter Mobbex entity uid from vendor product
        add_filter('filter_mbbx_entity', [$this, 'get_vendor_entity'], 10, 2);

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

            // Admin vendor edit
            add_action('show_user_profile', [$this, 'dokan_admin_add_vendor_fields'], 30);
            add_action('edit_user_profile', [$this, 'dokan_admin_add_vendor_fields'], 30);
            add_action('edit_user_profile_update', [$this, 'dokan_admin_save_vendor_fields']);

            // Vendor registration/edit fields
            add_filter('wcfm_membership_registration_fields', [$this, 'wcfm_add_vendor_fields']);
            add_filter('wcfm_marketplace_settings_fields_general', [$this, 'wcfm_add_vendor_fields'], 10, 2);
            add_filter('wcfm_form_custom_validation', [$this, 'wcfm_validate_vendor_fields'], 10, 2);
            add_action('wcfm_membership_registration', [$this, 'wcfm_save_vendor_fields'], 10, 2);
            add_action('wcfm_vendor_settings_update', [$this, 'wcfm_save_vendor_fields'], 10, 2);
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
        $myUpdateChecker = Puc_v4_Factory::buildUpdateChecker(
            'https://github.com/mobbexco/woocommerce-marketplace/',
            __FILE__,
            'mobbex-marketplace-plugin-update-checker'
        );
        $myUpdateChecker->getVcsApi()->enableReleaseAssets();
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
            'label'       => __('Tax Id (Deprecated)', 'mobbex-marketplace'),
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

        $uid_field = [
            'id'          => 'mobbex_marketplace_uid',
            'value'       => get_post_meta(get_the_ID(), 'mobbex_marketplace_uid', true),
            'label'       => __('Entity UID', 'mobbex-marketplace'),
            'description' => __('Set the Mobbex uid of the seller. Only multivendor mode required', 'mobbex-marketplace'),
            'desc_tip'    => true
        ];

        woocommerce_wp_text_input($cuit_field);
        woocommerce_wp_text_input($fee_field); 
        woocommerce_wp_text_input($uid_field); 
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
        $uid = !empty($_POST['mobbex_marketplace_uid']) ? esc_attr($_POST['mobbex_marketplace_uid']) : null;

        update_post_meta(get_the_ID(), 'mobbex_marketplace_cuit', $cuit);
        update_post_meta(get_the_ID(), 'mobbex_marketplace_uid', $fee);
        update_post_meta(get_the_ID(), 'mobbex_marketplace_uid', $uid);
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
                    <label for="mobbex_marketplace_cuit"><?= __('Tax Id (Deprecated)', 'mobbex-marketplace'); ?></label>
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
        $fee  = !empty($_POST['mobbex_marketplace_fee']) ? esc_attr($_POST['mobbex_marketplace_fee']) : null;
        $uid  = !empty($_POST['mobbex_marketplace_uid']) ? esc_attr($_POST['mobbex_marketplace_uid']) : null;

        update_term_meta($term_id, 'mobbex_marketplace_cuit', $cuit);
        update_term_meta($term_id, 'mobbex_marketplace_fee', $fee);
        update_term_meta($term_id, 'mobbex_marketplace_uid', $uid);
    }

    /**
     * Modify checkout data to add split functionality.
     * @param array $checkout_data
     */
    public function modify_checkout_data($checkout_data, $order_id)
    {
        // Wallet split payment only works with Mobbex for Woocommerce > 3.1.2
        if (Mbbxm_Helper::get_marketplace_mode() !== 'split')
            return $checkout_data;

        if (!empty($checkout_data['wallet']) && version_compare(MOBBEX_VERSION, '3.1.3', '<')) 
            return;

        $order       = wc_get_order($order_id);
        $integration = Mbbxm_Helper::get_integration();

        try {
            if ($integration == 'dokan' || $integration == 'wcfm') {
                foreach (Mbbxm_Helper::get_items_by_vendor($order) as $vendor_id => $items) {
                    $total = $fee = 0;
                    $product_ids = [];
                    
                    //Get vendor uid
                    $entity = get_user_meta($vendor_id, 'mobbex_entity_uid', true) ?: '';

                    // Get cuit from user meta
                    $cuit = $integration == 'wcfm' ? Mbbxm_Helper::get_wcfm_cuit($vendor_id) : get_user_meta($vendor_id, 'mobbex_tax_id', true);

                    // Exit if cuit is not configured
                    if (empty($cuit) && empty($entity))
                        throw new \Exception('Empty entity UID or Tax id. Vendor ' . Mbbxm_Helper::get_store_name($vendor_id));

                    foreach ($items as $item) {
                        $total        += $item->get_total();
                        $fee          += $integration == 'wcfm' ? Mbbxm_Helper::get_wcfm_fee($item) : dokan()->commission->get_earning_by_product($item->get_product(), 'admin') * $item->get_quantity();
                        $product_ids[] = $item->get_product()->get_id();
                    }

                    $checkout_data['split'][] = [
                        'entity'      => $entity,
                        'tax_id'      => $cuit ?: null,
                        'hold'        => get_user_meta($vendor_id, 'mobbex_marketplace_hold', true) === 'yes',
                        'fee'         => $fee,
                        'total'       => $total,
                        'reference'   => $checkout_data['reference'] . '_split_' . $cuit,
                        'description' => "Split payment - CUIT: $cuit - Product IDs: " . implode(", ", $product_ids),
                    ];
                }
            } else {
                $items = $order->get_items();

                foreach ($items as $item) {
                    $product_id = $item->get_product()->get_id();

                    // Get configs from product/category/vendor/default
                    $entity = $this->get_entity($product_id);
                    $cuit   = $this->get_cuit($product_id);
                    $fee    = $this->get_fee($item);
                    $hold   = $this->get_hold($product_id);

                    // Exit if cuit is not configured
                    if (empty($cuit) && empty($entity))
                        throw new \Exception('Empty entity UID or Tax id. Product #' . $product_id);

                    if (!isset($checkout_data['split'])) $checkout_data['split'] = [];

                    // Search if a product with the same cuit is already added
                    $key = array_search($cuit, array_column($checkout_data['split'], 'tax_id'));

                    if (is_int($key)) {
                        // Combine values
                        $checkout_data['split'][$key]['total']       += $item->get_total();
                        $checkout_data['split'][$key]['fee']         += $fee;
                        $checkout_data['split'][$key]['description'] .= ", $product_id";
                        $checkout_data['split'][$key]['hold']         = $checkout_data['split'][$key]['hold'] || $hold;
                    } else {
                        // Add split payment
                        $checkout_data['split'][] = [
                            'entity' => $entity,
                            'tax_id' => $cuit ?: '',
                            'description' => "Cuit $cuit. Product IDs: $product_id",
                            'total' => $item->get_total(),
                            'reference' => $checkout_data['reference'] . '_split_' . $cuit,
                            'fee' => $fee,
                            'hold' => $hold,
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            self::$logger->log('error', 'Mobbex Marketplace Error: ' . $e->getMessage(), $checkout_data);
        }

        // Try to get shipping items from order
        $checkout_data = Mbbxm_Shipping::add_shippings($order, $checkout_data);

        // Add Plugin versions
        $checkout_data['options']['platform']['extensions'][] = [
            'name'    => 'mobbex_marketplace',
            'version' => MobbexMarketplace::$version,
        ];

        // Add integration version
        if ($integration) {
            $version = $integration == 'dokan' ? dokan()->version : WCFMmp_VERSION;

            $checkout_data['options']['platform']['extensions'][] = [
                'name'    => $integration,
                'version' => $version,
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
     * Intercept the Mobbex Webhook to filter his data & set the seller earning.
     * @param array $response
     */
    public function mobbex_webhook($response)
    {
        global $wpdb;

        // Get order
        $order_id = $_GET['mobbex_order_id'] ?: str_replace('Pedido #', '', $response['data']['payment']['description']);
        $order    = wc_get_order($order_id);

        if (get_option('mm_option_integration') != 'dokan')
            return $response;

        if (($response['data']['status_code'] < 200 && $response['data']['status_code'] >= 400) || $response['data']['checkout']['total'] <= 0)
            return $response;

        try {
            $sub_orders = wc_get_orders([
                'type'   => 'shop_order',
                'parent' => $order_id,
                'limit'  => -1
            ]) ?: [$order];

            array_walk($sub_orders, function($sub_order) use ($wpdb, $response) {
                $wpdb->update(
                    $wpdb->dokan_orders,
                    ['net_amount' => self::$helper->get_dokan_vendor_earning($response['data'], $sub_order)],
                    ['order_id'   => $sub_order->get_id()]
                );
            });
        } catch (\Exception $e) {
            self::$logger->log('error', 'Mobbex Marketplace Error: ' . $e->getMessage(), $response);
        }

        return $response;
    }

    /**
     * Get vendor uid from product/category.
     * @param int $product_id
     */
    public function get_entity($product_id)
    {
        // Set default to null
        $product_uid = $category_uid = null;

        // Get uid from product
        
        $product_uid = get_metadata('post', $product_id, 'mbbx_entity', true);

        // Get uid from categories
        $categories = get_the_terms($product_id, 'product_cat') ?: [];
        foreach ($categories as $category) {
            $category_uid = get_metadata('term', $category->term_id, 'mbbx_entity', true);
            // Break foreach on first match
            if (!empty($category_uid))
                break;
        }

        // Return uid in order of specificity
        if (!empty($product_uid)) {
            return $product_uid;
        } else if (!empty($category_uid)) {
            return $category_uid;
        }

        return null;
    }
    
    /**
     * Get cuit from product/category.
     * @param int $product_id
     */
    public function get_cuit($product_id)
    {
        // Set default to null
        $product_cuit = $category_cuit = null;

        // Get cuit from product
        $product_cuit = get_post_meta($product_id, 'mobbex_marketplace_cuit', true);

        // Get cuit from categories
        $categories = get_the_terms($product_id, 'product_cat') ?: [];
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
    public function get_fee($item)
    {
        // Set default to null
        $product_fee = $category_fee = $default_fee = null;

        // Get fee from product
        $product_id = $item->get_product()->get_id();
        $product_fee = get_post_meta($product_id, 'mobbex_marketplace_fee', true);

        // Get fee from categories
        $categories = get_the_terms($product_id, 'product_cat') ?: [];
        foreach ($categories as $category) {
            $category_fee = get_term_meta($category->term_id, 'mobbex_marketplace_fee', true);
            // Break foreach on first match
            if (!empty($category_fee))
                break;
        }

        // Get default fee from plugin config
        $default_fee = get_option('mm_option_default_fee');

        // Return fee in order of specificity
        if (!empty($product_fee)) {
            return $product_fee;
        } else if (!empty($category_fee)) {
            return $category_fee;
        } else if (!empty($default_fee)) {
            return $default_fee;
        }

        return 0;
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
            <label for="mobbex_tax_id"><?= __('Entity UID', 'mobbex-marketplace') ?><span class="required">*</span></label>
            <input type="text" class="input-text form-control" name="mobbex_entity_uid" id="mobbex_entity_uid" required="required"/>
            <small><?= __('Entity UID of the vendor configured in your Mobbex commerce', 'mobbex-marketplace') ?></small>
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
        // Add Mobbex entity uid to required fields array
        $required_fields['mobbex_entity_uid'] = __('Please enter the Entity UID configured in your Mobbex commerce.', 'mobbex-marketplace');
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
        // If Mobbex enttity uid is sent save it
        if (!empty($user_id) && $_POST['mobbex_entity_uid']) {
            update_user_meta($user_id, 'mobbex_entity_uid', sanitize_text_field($_POST['mobbex_entity_uid']));
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

        if (Mbbxm_Helper::get_integration() == 'dokan' && !user_can($user, 'dokandar')) {
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
                        <label for="mobbex_tax_id"><?= __('Tax Id (Deprecated)', 'mobbex-marketplace'); ?></label>
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

                    <th>
                        <label for="mobbex_entity_uid"><?= __('Entity UID (required)', 'mobbex-marketplace'); ?></label>
                    </th>
                    <td>
                        <input type="text" name="mobbex_entity_uid" id="mobbex_entity_uid" class="regular-text"
                        value="<?= get_user_meta($user->ID, 'mobbex_entity_uid', true) ?>">
                        <br/>
                        <span class="description">
                        <?= __('Entity UID configured in Mobbex commerce', 'mobbex-marketplace') ?>
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
            $tax_id     = isset($post_data['mobbex_tax_id']) ? sanitize_text_field($post_data['mobbex_tax_id']) : '';
            $entity_uid = isset($post_data['mobbex_entity_uid']) ? sanitize_text_field($post_data['mobbex_entity_uid']) : '';
            $hold       = isset($post_data['mobbex_marketplace_hold']) ? 'yes' : 'no';

            update_user_meta($user_id, 'mobbex_tax_id', $tax_id);
            update_user_meta($user_id, 'mobbex_entity_uid', $entity_uid);
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
            <label class="dokan-w3 dokan-control-label" for="mobbex_tax_id"><?= __('Tax Id (Deprecated)', 'mobbex-marketplace') ?></label>

            <div class="dokan-w5 dokan-text-left">
                <input type="text" name="mobbex_tax_id" id="mobbex_tax_id" required value="<?= get_user_meta($user_id, 'mobbex_tax_id', true) ?>" class="dokan-form-control">
                <small><?= __('Tax Id configured in your Mobbex commerce', 'mobbex-marketplace') ?></small>
            </div>
        </div>

        <div class="dokan-form-group">
            <label class="dokan-w3 dokan-control-label" for="mobbex_entity_uid"><?= __('Entity UID', 'mobbex-marketplace') ?></label>

            <div class="dokan-w5 dokan-text-left">
                <input type="text" name="mobbex_entity_uid" id="mobbex_entity_uid" value="<?= get_user_meta($user_id, 'mobbex_entity_uid', true) ?>" class="dokan-form-control">
                <small><?= __('Entity UID configured in your Mobbex commerce', 'mobbex-marketplace') ?></small>
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
        $order_id = $theorder instanceof WC_Data ? $theorder->get_id() : null;

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

    public function wcfm_add_vendor_fields($fields, $user_id = null)
    {
        // In some pages wcfm calls this hook multiple times...
        if (isset($fields['list_banner_video']))
            return $fields;

        if (!$user_id)
            $user_id = get_current_user_id();

        $fields['mobbex_tax_id'] = [
            'type'              => 'text',
            'label'             => __('CUIT', 'mobbex-marketplace'),
            'value'             => get_user_meta($user_id, 'mobbex_tax_id', true) ?: '',
            'hints'             => 'CUIT configurado en su cuenta de Mobbex',
            'class'             => 'wcfm-text',
            'label_class'       => 'wcfm_title',
            'custom_attributes' => [
                'required' => true
            ],
        ];
        $fields['mobbex_entity_uid'] = [
            'type'              => 'text',
            'label'             => __('UID', 'mobbex-marketplace'),
            'value'             => get_user_meta($user_id, 'mobbex_entity_uid', true) ?: '',
            'hints'             => 'UID configurado en su cuenta de Mobbex',
            'class'             => 'wcfm-text',
            'label_class'       => 'wcfm_title',
        ];

        return $fields;
    }

    public function wcfm_validate_vendor_fields($data, $form)
    {
        if ($form != 'vendor_registration' && $form != 'vendor_setting_manage')
            return;

        if (empty($data['mobbex_tax_id']))
            return [
                'has_error' => true,
                'message'   => 'Campo CUIT incompleto'
            ];
    }

    public function wcfm_save_vendor_fields($id, $data)
    {
        update_user_meta($id, 'mobbex_tax_id', $data['mobbex_tax_id']);
        update_user_meta($id, 'mobbex_entity_uid', $data['mobbex_entity_uid']);
    }

    public function get_vendor_entity($product_id)
    {
        if (!empty($checkout_data['wallet']) && version_compare(MOBBEX_VERSION, '3.1.3', '<' && Mbbxm_Helper::get_marketplace_mode() != 'multivendor'))
            return;
        
        return Mbbxm_Helper::get_entity_uid($product_id);
    }
}

$mobbexMarketplace = new MobbexMarketplace;
add_action('plugins_loaded', [ & $mobbexMarketplace, 'init']);