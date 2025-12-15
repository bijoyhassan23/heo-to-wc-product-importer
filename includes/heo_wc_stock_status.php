<?php

trait HEO_WC_Stock_status{
    private function stock_status_init(){
        add_filter('woocommerce_product_stock_status_options', [$this, 'add_custom_stock_status']);
        add_filter('woocommerce_product_stock_status',         [$this, 'woocomerce_recognize'], 10, 2);
        add_filter('woocommerce_get_availability_text',        [$this, 'display_correct_label'], 10, 2);
        add_filter('woocommerce_get_availability_class',       [$this, 'change_color'], 10, 2);
        add_filter('woocommerce_admin_stock_html',             [$this, 'show_admin_list'], 10, 2);
    }

    // Add custom stock statuses to WooCommerce
    public function add_custom_stock_status($status_options) {
        $status_options['at_supplier'] = __('At Supplier', 'heo-woo-importer');
        $status_options['preorder'] = __('Pre Order', 'heo-woo-importer');
        return $status_options;
    }

    // Make WooCommerce recognize custom statuses
    public function woocomerce_recognize($status, $product) {
        if (in_array($status, ['at_supplier', 'preorder'])) {
            return $status;
        }
        return $status;
    }

    // Display correct label on the front end (product page)
    public function display_correct_label($availability, $product) {
        $status = $product->get_stock_status();
        if ($status === 'at_supplier') {
            $availability = __('Available at Supplier', 'heo-woo-importer');
        } elseif ($status === 'preorder') {
            $availability = __('Pre Order Now', 'heo-woo-importer');
        }
        return $availability;
    }

    // change color or style
    public function change_color($class, $product) {
        $status = $product->get_stock_status();
        if ($status === 'at_supplier') {
            $class = 'stock at-supplier';
        } elseif ($status === 'preorder') {
            $class = 'stock preorder';
        }
        return $class;
    }

    // Ensure custom stock statuses show in admin product list (Stock column)
    public function show_admin_list($stock_html, $product) {
        $status = $product->get_stock_status();

        // Map your custom statuses to readable text
        $custom_labels = [
            'at_supplier' => __('At Supplier', 'heo-woo-importer'),
            'preorder'    => __('Pre Order', 'heo-woo-importer'),
        ];

        if (isset($custom_labels[$status])) {
            // Optional: add color/style
            $color = $status === 'at_supplier' ? '#e6b800' : '#0073aa'; // yellow / blue
            $stock_html = '<mark class="stock" style="background: transparent none;color:' . esc_attr($color) . ';">' . esc_html($custom_labels[$status]) . '</mark>';
        }

        return $stock_html;
    }
}






