<?php

trait Product_setup{
    private function product_setup_init(){
        add_action('init', [$this, 'register_product_taxonomy']);

        add_action('woocommerce_product_options_general_product_data', [$this, 'heo_add_custom_product_field_for_general']);
        add_action('woocommerce_product_options_inventory_product_data', [$this, 'heo_add_custom_product_field_for_inventory']);
        add_action('woocommerce_admin_process_product_object', [$this, 'heo_save_custom_field_data']);

        // Brand functionality
        add_action('product_brand_edit_form_fields', [$this, 'brand_fild_rendard'], 20, 1);
        add_action('edited_product_brand', [$this, 'brand_fild_save'], 10, 1);


        add_action('admin_head', function () {
            if(!(isset($_GET['post']) && get_post_type($_GET['post']) === 'product') && !(isset($_GET['post_type']) && $_GET['post_type'] === 'product')) return;
            $this->product_stock_and_price_update(['product_id'=> '96039']);
            ?>
                <style>
                    .woocommerce_options_panel input[type=date]{
                        width: 50%;
                        float: left;
                    }
                </style>
                <script>
                    document.addEventListener('DOMContentLoaded', () => {
                        const getPriceLock = document.querySelector('#_enable_price_lock');
                        if(getPriceLock){
                            const regularPriceFild = document.querySelector('#_regular_price');
                            const salePriceFild = document.querySelector('#_sale_price');
                            
                            function changeFun(desabledStatus){
                                regularPriceFild.disabled = !getPriceLock.checked;
                                salePriceFild.disabled = !getPriceLock.checked;
                            }

                            changeFun();

                            getPriceLock.addEventListener('change', changeFun);
                        }
                    });
                </script>
            <?php            
        });
    }

    public function register_product_taxonomy() {
        $labels_series = [
            'name'              => _x( 'Series', 'taxonomy general name', 'textdomain' ),
            'singular_name'     => _x( 'Series', 'taxonomy singular name', 'textdomain' ),
            'search_items'      => __( 'Search Series', 'textdomain' ),
            'all_items'         => __( 'All Series', 'textdomain' ),
            'parent_item'       => __( 'Parent Series', 'textdomain' ),
            'parent_item_colon' => __( 'Parent Series:', 'textdomain' ),
            'edit_item'         => __( 'Edit Series', 'textdomain' ),
            'update_item'       => __( 'Update Series', 'textdomain' ),
            'add_new_item'      => __( 'Add New Series', 'textdomain' ),
            'new_item_name'     => __( 'New Series Name', 'textdomain' ),
            'menu_name'         => __( 'Series', 'textdomain' ),
        ];

        $args_series = [
            'hierarchical'      => true,
            'labels'            => $labels_series,
            'show_ui'           => true,
            'show_admin_column' => true,
            'query_var'         => true,
            'rewrite'           => ['slug' => 'series'],
        ];

        register_taxonomy( 'series', ['product'], $args_series );

        $labels_product_type = [
            'name'              => _x( 'Type', 'taxonomy general name', 'textdomain' ),
            'singular_name'     => _x( 'Type', 'taxonomy singular name', 'textdomain' ),
            'search_items'      => __( 'Search Type', 'textdomain' ),
            'all_items'         => __( 'All Type', 'textdomain' ),
            'parent_item'       => __( 'Parent Type', 'textdomain' ),
            'parent_item_colon' => __( 'Parent Type:', 'textdomain' ),
            'edit_item'         => __( 'Edit Type', 'textdomain' ),
            'update_item'       => __( 'Update Type', 'textdomain' ),
            'add_new_item'      => __( 'Add New Type', 'textdomain' ),
            'new_item_name'     => __( 'New Type Name', 'textdomain' ),
            'menu_name'         => __( 'Type', 'textdomain' ),
        ];

        $args_product_type = [
            'hierarchical'      => true,
            'labels'            => $labels_product_type,
            'show_ui'           => true,
            'show_admin_column' => true,
            'query_var'         => true,
            'rewrite'           => ['slug' => 'type'],
        ];

        register_taxonomy( 'type', ['product'], $args_product_type );
    }

    public function heo_add_custom_product_field_for_general() {
        woocommerce_wp_checkbox([
            'id'          => '_enable_price_lock',
            'label'       => __('Price Lock', 'woocommerce'),
            'description' => __('Check this to use custom price.', 'woocommerce'),
        ]);

        woocommerce_wp_text_input([
            'id'          => '_server_regular_price',
            'label'       => __('Server Regular Price', 'woocommerce'),
            'desc_tip'    => true,
            'description' => __('This Price Will autometically come from server. Override if it\'s not comming.', 'woocommerce'),
            'type'        => 'number',
            'placeholder' => 'Price will come from server'
        ]);

        woocommerce_wp_text_input([
            'id'          => '_server_sale_price',
            'label'       => __('Server Sale Price', 'woocommerce'),
            'desc_tip'    => true,
            'description' => __('This Price Will autometically come from server. Override if it\'s not comming.', 'woocommerce'),
            'type'        => 'number',
            'placeholder' => 'Price will come from server'
        ]);

        // Preorder functionality
        woocommerce_wp_text_input([
            'id'          => '_preorder_deadline',
            'label'       => __('Preorder Deadline', 'woocommerce'),
            'desc_tip'    => true,
            'description' => __('Select the deadline date for preorders.', 'woocommerce'),
            'type'        => 'date',
        ]);

        // ETA
        woocommerce_wp_text_input([
            'id'          => '_eta_deadline',
            'label'       => __('ETA Deadline', 'woocommerce'),
            'desc_tip'    => true,
            'description' => __('Select the deadline date for ETA.', 'woocommerce'),
            'type'        => 'date',
        ]);

        global $post;
        $timestamp = get_post_meta($post->ID, '_last_update', true);
        $readable = $timestamp ? date('Y-m-d H:i:s', $timestamp) : '—';

        woocommerce_wp_text_input([
            'id'          => '_last_update',
            'label'       => __('Last Update', 'woocommerce'),
            'desc_tip'    => true,
            'description' => __('Product Last update Time and date.', 'woocommerce'),
            'type'        => 'text',
            'value'             => $readable, 
            'custom_attributes' => ['disabled' => 'disabled'],
        ]);
    }

    public function heo_add_custom_product_field_for_inventory() {
        woocommerce_wp_text_input([
            'id'          => '_product_barcode_type',
            'label'       => __('Barcode Type', 'woocommerce'),
            'desc_tip'    => true,
            'description' => __('Enter the product barcode type.', 'woocommerce'),
        ]);
        woocommerce_wp_text_input([
            'id'          => '_product_barcode',
            'label'       => __('Product Barcode', 'woocommerce'),
            'desc_tip'    => true,
            'description' => __('Enter the product barcode.', 'woocommerce'),
        ]);
    }

    public function heo_save_custom_field_data($product) {
        if (isset($_POST['_server_regular_price'])) $product->update_meta_data('_server_regular_price', sanitize_text_field($_POST['_server_regular_price']));
        if (isset($_POST['_server_sale_price']))    $product->update_meta_data('_server_sale_price', sanitize_text_field($_POST['_server_sale_price']));
        if (isset($_POST['_preorder_deadline']))    $product->update_meta_data('_preorder_deadline', sanitize_text_field($_POST['_preorder_deadline']));
        if (isset($_POST['_eta_deadline']))         $product->update_meta_data('_eta_deadline', sanitize_text_field($_POST['_eta_deadline']));
        if (isset($_POST['_product_barcode_type'])) $product->update_meta_data('_product_barcode_type', sanitize_text_field($_POST['_product_barcode_type']));
        if (isset($_POST['_product_barcode']))      $product->update_meta_data('_product_barcode', sanitize_text_field($_POST['_product_barcode']));

        $product->update_meta_data('_enable_price_lock', isset($_POST['_enable_price_lock']) ? 'yes' : 'no');
        $product->update_meta_data('_last_update', time());
        $this->product_price_calculator($product->get_id());
    }

    public function brand_fild_rendard($term){
        $meta_key = '_brand_price_range_multipliers';
        $rows = get_term_meta($term->term_id, $meta_key, true);
        if (!is_array($rows)) $rows = [];

        // Ensure at least one empty row for UI
        if (empty($rows)) $rows = [['start' => '', 'end' => '', 'multiplier' => '']];

        // Nonce
        wp_nonce_field('ft_brand_price_ranges', 'ft_brand_price_ranges_nonce');
        ?>
        <tr class="form-field">
            <th scope="row">
                <label><?php esc_html_e('Price Range Multipliers', 'ft'); ?></label>
            </th>
            <td>
                <p class="description" style="margin-top:0">
                    Define discount tiers for this brand using price ranges and a multiply factor (e.g., 1.95). Leave <em>End</em> empty for open-ended range.
                </p>
                <style>
                    #ft-price-range-table th { text-align: left; }
                    #ft-price-range-table th, #ft-price-range-table td { padding: 8px 6px; }
                    #ft-price-range-table th { background: #f1f1f1; }
                    #ft-price-range-table tr:nth-child(even) { background: #fafafa; }
                    #ft-price-range-table tr:hover { background: #ffffe0; }
                </style>
                <table class="widefat striped" id="ft-price-range-table" style="max-width:960px;margin-top:8px">
                    <thead>
                        <tr>
                            <th style="width:28%">Start price</th>
                            <th style="width:28%">End price</th>
                            <th style="width:28%">Multiply</th>
                            <th style="width:16%"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $i => $row):
                            $start = isset($row['start']) ? $row['start'] : '';
                            $end   = isset($row['end']) ? $row['end'] : '';
                            $mult  = isset($row['multiplier']) ? $row['multiplier'] : '';
                        ?>
                        <tr class="ft-pr-row">
                            <td><input type="number" step="0.01" min="0" placeholder="0.00" name="ft_price_rows[start][]" value="<?php echo esc_attr($start); ?>" class="regular-text"></td>
                            <td><input type="number" step="0.01" min="0" placeholder="e.g. 9.99 (or leave empty)" name="ft_price_rows[end][]" value="<?php echo esc_attr($end); ?>" class="regular-text"></td>
                            <td><input type="number" step="0.01" min="0" placeholder="e.g. 1.95" name="ft_price_rows[multiplier][]" value="<?php echo esc_attr($mult); ?>" class="regular-text"></td>
                            <td><button type="button" class="button ft-row-remove" aria-label="Remove row">Remove</button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p style="margin-top:8px">
                    <button type="button" class="button button-secondary" id="ft-row-add">+ Add range</button>
                </p>

                <style>
                    #ft-price-range-table input.regular-text { width: 100%; }
                    #ft-price-range-table td, #ft-price-range-table th { vertical-align: middle; }
                    #ft-price-range-table .ft-pr-row.removed { opacity: 0.5; }
                </style>

                <script>
                    (function(){
                        const table = document.getElementById('ft-price-range-table');
                        const tbody = table.querySelector('tbody');
                        const addBtn = document.getElementById('ft-row-add');

                        function makeRow(values){
                            const tr = document.createElement('tr');
                            tr.className = 'ft-pr-row';
                            tr.innerHTML = `
                                <td><input type="number" step="0.01" min="0" placeholder="0.00" name="ft_price_rows[start][]" value="${values?.start ?? ''}" class="regular-text"></td>
                                <td><input type="number" step="0.01" min="0" placeholder="e.g. 9.99 (or leave empty)" name="ft_price_rows[end][]" value="${values?.end ?? ''}" class="regular-text"></td>
                                <td><input type="number" step="0.01" min="0" placeholder="e.g. 1.95" name="ft_price_rows[multiplier][]" value="${values?.multiplier ?? ''}" class="regular-text"></td>
                                <td><button type="button" class="button ft-row-remove" aria-label="Remove row">Remove</button></td>
                            `;
                            return tr;
                        }

                        addBtn.addEventListener('click', () => tbody.appendChild(makeRow()) );

                        tbody.addEventListener('click', function(e){
                            if (e.target && e.target.classList.contains('ft-row-remove')) {
                                const rows = tbody.querySelectorAll('.ft-pr-row');
                                if (rows.length > 1) {
                                    e.target.closest('.ft-pr-row').remove();
                                } else {
                                    // If only 1 row left, just clear its inputs instead of removing
                                    const inputs = e.target.closest('.ft-pr-row').querySelectorAll('input');
                                    inputs.forEach(i => i.value = '');
                                }
                            }
                        });
                    })();
                </script>
            </td>
        </tr>
        <?php
    }
    
    public function brand_fild_save($term_id){
        if (empty($_POST['ft_brand_price_ranges_nonce']) || !wp_verify_nonce($_POST['ft_brand_price_ranges_nonce'], 'ft_brand_price_ranges')) return;

        $meta_key = '_brand_price_range_multipliers';
        $rows_in  = isset($_POST['ft_price_rows']) && is_array($_POST['ft_price_rows']) ? $_POST['ft_price_rows'] : null;

        if (!$rows_in || !isset($rows_in['start'], $rows_in['end'], $rows_in['multiplier'])) {
            delete_term_meta($term_id, $meta_key);
            return;
        }

        $starts = (array) $rows_in['start'];
        $ends   = (array) $rows_in['end'];
        $mults  = (array) $rows_in['multiplier'];

        $clean = [];
        $count = max(count($starts), count($ends), count($mults));

        for ($i = 0; $i < $count; $i++) {
            $s = isset($starts[$i]) ? trim((string)$starts[$i]) : '';
            $e = isset($ends[$i]) ? trim((string)$ends[$i]) : '';
            $m = isset($mults[$i]) ? trim((string)$mults[$i]) : '';

            // Skip completely empty rows
            if ($s === '' && $e === '' && $m === '') continue;

            // Numeric sanitization
            $start = $s === '' ? '' : floatval($s);
            $end   = $e === '' ? '' : floatval($e);
            $mult  = $m === '' ? '' : floatval($m);

            // Basic validation: require start & multiplier; end can be empty (open range)
            if ($start === '' || $mult === '') continue;
            if ($start < 0) $start = 0;
            if ($end !== '' && $end < 0) $end = 0;

            $clean[] = [ 'start' => $start, 'end' => $end, 'multiplier' => $mult ];
        }

        if (!empty($clean)) {
            update_term_meta($term_id, $meta_key, $clean);
        } else {
            delete_term_meta($term_id, $meta_key);
        }
    }
}