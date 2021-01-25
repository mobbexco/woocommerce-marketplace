<?php
/**
 * Plugin Name: Mobbex Marketplace
 * Description: Plugin to extend Mobbex Marketplace functionality.
 * Version: 1.0.0
 * WC tested up to: 4.2.2
 * Author: mobbex.com
 * Author URI: https://mobbex.com/
 * Copyright: 2021 mobbex.com
 */

class MobbexMarketplace
{
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
        MobbexMarketplace::check_dependencies();
        MobbexMarketplace::load_textdomain();
        MobbexMarketplace::load_update_checker();
        MobbexMarketplace::load_settings();

        $this->settings = new MM_Settings(__FILE__);

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
        add_filter('mobbex_checkout_process', [$this, 'save_mobbex_response'], 10 , 2);

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

        if(!is_plugin_active('mobbex-for-woocommerce/mobbex-for-woocommerce.php')) {
            MobbexMarketplace::$errors[] = __('Mobbex Marketplace requires Mobbex for WooCommerce to be activated', 'mobbex-marketplace');
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
        
        $field = array(
            'id'          => 'mobbex_marketplace_cuit',
            'value'       => get_post_meta(get_the_ID(), 'mobbex_marketplace_cuit', true),
            'label'       => __('Tax Id', 'mobbex-marketplace'),
            'description' => __('Written without hyphens. Cuit of store to which you want to allocate the payment.', 'mobbex-marketplace'),
            'desc_tip'    => true
        );

        woocommerce_wp_text_input($field); 
        
        $field = array(
            'id'          => 'mobbex_marketplace_fee',
            'value'       => get_post_meta(get_the_ID(), 'mobbex_marketplace_fee', true),
            'label'       => __('Fee (optional)', 'mobbex-marketplace'),
            'description' => __('This option has priority over the one applied at the category level.', 'mobbex-marketplace'),
            'desc_tip'    => true
        );

        woocommerce_wp_text_input($field); 
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
                <span class="description"><?= __('This option has priority over the one applied by default.', 'mobbex-marketplace') ?>
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
        $order = wc_get_order($order_id);
        $items = $order->get_items();

        foreach ($items as $item) {
            $product_id = $item->get_product()->get_id();

            // Get cuit from product/category
            $cuit = $this->get_cuit($product_id);

            if (!empty($cuit)) {

                // Check if a product with the same cuit is already added
                foreach ($checkout_data['split'] as $key => $payment) {
                    if ($payment['tax_id'] === $cuit) {
                        // Combine values
                        $payment['total'] += $item->get_total();
                        $payment['fee'] += $this->get_fee($product_id);
                        $payment['description'] .= ", $product_id";
                        
                        $checkout_data['split'][$key] = $payment;
                        // Go to next item
                        continue 2;
                    }
                }

                // Add split payment
                $checkout_data['split'][] = [
                    'tax_id' => $cuit,
                    'description' => "Cuit $cuit. Product IDs: $product_id",
                    'total' => $item->get_total(),
                    'reference' => $checkout_data['reference'] . '_split_' . $cuit,
                    'fee' => $this->get_fee($product_id)
                ];
            }
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
        }
    }

    /**
     * Get cuit from product/category.
     * @param int $product_id
     */
    public function get_cuit($product_id)
    {
        // Get cuit from categories
        $categories = get_the_terms($product_id, 'product_cat');
        foreach ($categories as $category) {
            $category_cuit = get_term_meta($category->term_id, 'mobbex_marketplace_cuit', true);

            // Break foreach on first match
            if (!empty($category_cuit)) {
                break;
            }
        }

        // Get cuit from product
        $product_cuit = get_post_meta($product_id, 'mobbex_marketplace_cuit', true);

        // Return cuit in order of specificity
        if (!empty($product_cuit)) {
            return $product_cuit;
        } else if (!empty($category_cuit)) {
            return $category_cuit;
        }

        return null;
    }

    /**
     * Get fee from product/category/default.
     * @param int $product_id
     */
    public function get_fee($product_id)
    {
        // Get default fee from plugin config
        $default_fee = get_option('mm_option_default_fee');

        // Get fee from categories
        $categories = get_the_terms($product_id, 'product_cat');
        foreach ($categories as $category) {
            $category_fee = get_term_meta($category->term_id, 'mobbex_marketplace_fee', true);

            // Break foreach on first match
            if (!empty($category_fee)) {
                break;
            }
        }

        // Get fee from product
        $product_fee = get_post_meta($product_id, 'mobbex_marketplace_fee', true);

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
}

$mobbexMarketplace = new MobbexMarketplace;
add_action('plugins_loaded', [ & $mobbexMarketplace, 'init']);