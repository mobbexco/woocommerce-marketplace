<?php

class MM_Settings
{
    public function __construct($file)
    {
        // Create settings page
        add_action('admin_menu', [$this, 'add_page_to_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        // Add settings link to plugins page
        add_filter('plugin_action_links_' . plugin_basename($file), [$this, 'add_settings_link']);
    }

    /**
     * Add marketplace settings page to WooCommerce submenu.
     */
    public function add_page_to_menu()
    {
        add_submenu_page(
            'woocommerce',              // Parent slug
            'Mobbex Marketplace',       // Page title
            'Mobbex Marketplace',       // Menu title
            'manage_options',           // Capability
            __FILE__,                   // Menu slug
            [$this, 'render_template']  // Function
        );
    }

    /**
     * Render settings template.
     */
    public function render_template()
    {
        $this->settings = get_option('mm_option_name');
        ?>

        <div class="wrap">
            <h2>Mobbex Marketplace</h2>
            <p><?= __('To distribute a payment without integrating another Marketplace plugin (eg Dokan), you must charge the Tax ID of the commerce to which you want to assign the payment by product or category.', 'mobbex-marketplace') ?></p>
            <p><?= __('This Tax Id must be the same as the one configured in the Mobbex commerce', 'mobbex-marketplace') ?></p>
            <p><?= __('If you want a payment to be commission free, you must leave the commission option empty in the product, category, vendor and plugin configuration (Default Commission) that affect said payment.', 'mobbex-marketplace') ?></p>
            <?php settings_errors(); ?>

            <form method="post" action="options.php">
                <?php
                settings_fields('mm_option_group');
                do_settings_sections('mm-admin');

                // Render Fields
                ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?= __('API Key', 'mobbex-for-woocommerce') ?></th>
                        <td><?= $this->api_key_field() ?>
                            <p class="description">
                                <?= __('Your Mobbex API key.', 'mobbex-for-woocommerce') ?>
                            </p>
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row"><?= __('Access Token', 'mobbex-for-woocommerce') ?></th>
                        <td><?= $this->access_token_field() ?>
                            <p class="description">
                                <?= __('Your Mobbex access token.', 'mobbex-for-woocommerce') ?>
                            </p>
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row"><?= __('Integrations', 'mobbex-marketplace') ?></th>
                        <td><?= $this->integration_field() ?>
                            <p class="description">
                                <?= __('Integrate with other Marketplace plugins', 'mobbex-marketplace') ?>
                            </p>
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row"><?= __('Set Default Payment Fee', 'mobbex-marketplace') ?></th>
                        <td><?= $this->default_fee_field() ?>
                            <p class="description">
                                <?= __('Will be used when not set at product/category/vendor level', 'mobbex-marketplace') ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php

                submit_button();
                ?>
            </form>
        </div>

        <?php
    }

    /**
     * Render api-key field.
     */
    public function api_key_field()
    {
        $value = !empty(get_option('mm_option_api_key')) ? esc_attr(get_option('mm_option_api_key')) : '';
        return '<input class="regular-text" type="text" name="mm_option_api_key" id="api-key" value="' . $value . '">';
    }

    /**
     * Render access token field.
     */
    public function access_token_field()
    {
        $value = !empty(get_option('mm_option_access_token')) ? esc_attr(get_option('mm_option_access_token')) : '';
        return '<input class="regular-text" type="text" name="mm_option_access_token" id="access_token" value="' . $value . '">';
    }

    /**
     * Render plugin integration field.
     */
    public function integration_field()
    {
        $value = !empty(get_option('mm_option_integration')) ? esc_attr(get_option('mm_option_integration')) : '';
        return 
        '<select name="mm_option_integration" id="integration">
            <option value="0" ' . selected($value, 0, false) . '>' . __('No') . '</option>
            <option value="dokan" ' . selected($value, 'dokan', false) . '>' . __('Dokan') . '</option>
        </select>';
    }

    /**
     * Render Default Payment Fee field.
     */
    public function default_fee_field()
    {
        $value = !empty(get_option('mm_option_default_fee')) ? esc_attr(get_option('mm_option_default_fee')) : '';
        return '<input class="regular-text" type="text" name="mm_option_default_fee" id="default_fee" value="' . $value . '">';
    }

    /**
     * Register settings to options group.
     */
    public function register_settings()
    {
        register_setting(
            'mm_option_group',
            'mm_option_api_key'
        );
        register_setting(
            'mm_option_group',
            'mm_option_access_token'
        );
        register_setting(
            'mm_option_group',
            'mm_option_integration'
        );
        register_setting(
            'mm_option_group',
            'mm_option_default_fee'
        );
    }

    /**
     * Add settings page link to links on plugins page.
     */
    public function add_settings_link($links)
    {
        $links = array_merge(['<a href="' . admin_url('admin.php?page=mobbex-marketplace/includes/settings.php') . '">' . __('Settings') . '</a>'], $links);
        return $links;
    }
}