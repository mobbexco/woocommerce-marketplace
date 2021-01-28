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
            <p><?= __('To establish a new supplier, use the configuration panel of each product 
			or category in which you want to distribute your payment', 'mobbex-marketplace') ?></p>
            <?php settings_errors(); ?>

            <form method="post" action="options.php">
                <?php
                settings_fields('mm_option_group');
                do_settings_sections('mm-admin');

                // Render Fields
                ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?= __('Set Default Payment Fee', 'mobbex-marketplace') ?></th>
                        <td><?= $this->default_fee_field() ?>
                            <p class="description">
                                <?= __('Will be used when not set at product/category level', 'mobbex-marketplace') ?>
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
            'mm_option_default_fee',
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