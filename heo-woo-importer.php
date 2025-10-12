<?php
/**
 * Plugin Name: heo → WooCommerce Importer
 * Description: Imports & syncs products.
 * Version: 3.0.0
 * Author: Bijoy
 * Author URI: https://bijoy.dev
 */

if ( ! defined('ABSPATH') ) exit;

class HEO_WC_Importer {
    const OPT = 'heo_wc_importer_settings'; // Option name 
    const CRON_HOOK = 'heo_wc_importer_cron_sync';
    const PAGE_SLUG = 'heo-wc-importer';
    const LOG_TRANSIENT = 'heo_wc_import_log';
    
    const LANG_CODE = 'EN';
    
    const AS_GROUP   = 'heo_wc_importer_queue';
    const AS_SPACING = 40;
    const BATCH = 50;


    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_page']);
        add_action('admin_init', [$this, 'register_settings']);

        add_action('admin_post_heo_clear_log',      [$this, 'handle_clear_log']);

        // Action Scheduler (positional single arg)
        add_action('heo_wc_seed_page', [$this, 'seed_page_job']);
        add_action('heo_wc_import_single', [$this, 'process_single_job']);
        add_action('admin_post_heo_run_sync',       [$this, 'handle_manual_sync']);

        // Brand functionality
        add_action('product_brand_edit_form_fields', [$this, 'brand_fild_rendard'], 20, 1);
        add_action('edited_product_brand', [$this, 'brand_fild_save'], 10, 1);

        add_filter('woocommerce_product_get_price', [$this, 'custom_dynamic_price'], 20, 2);

        // Preorder functionality
        add_action('woocommerce_product_options_general_product_data', [$this, 'heo_add_custom_product_field']);
        add_action('woocommerce_admin_process_product_object', [$this, 'heo_save_preorder_deadline_field']);

    }

    public function add_admin_page() {
        add_submenu_page(
            'woocommerce', // Parent slug
            'heo Importer', // Page title
            'heo Importer', // Menu title
            'manage_woocommerce', // Capability
            self::PAGE_SLUG, // Menu slug
            [$this,'render_page'] // Callback function
        );
    }

    public function render_page() {
        if (!current_user_can('manage_woocommerce') ) return;
        $o = get_option(self::OPT, []); // setted Option
        $env      = $o['environment'] ?? 'sandbox';
        $username = $o['username'] ?? '';
        $pass_prod= $o['pass_prod'] ?? '';
        $pass_sbx = $o['pass_sbx'] ?? '';
        $pre_order_discount = $o['pre_order_discount'] ?? '';
        $defult_price_multiplier = $o['defult_price_multiplier'] ?? '';

        $log = get_transient(self::LOG_TRANSIENT);
        // echo '<pre>';
        //     var_export($o);
        // echo '</pre>';
        ?>
        <div class="wrap">
            <h1>heo → Woo Importer</h1>
            <form method="post" action="options.php">
                <?php settings_fields(self::OPT); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label>Environment</label></th>
                        <td>
                            <select name="<?php echo esc_attr(self::OPT); ?>[environment]">
                                <option value="sandbox" <?php selected($env,'sandbox'); ?>>Sandbox (Test)</option>
                                <option value="production" <?php selected($env,'production'); ?>>Production</option>
                            </select>
                        </td>
                    </tr>
                    <tr><th scope="row"><label>Username</label></th><td><input type="text" name="<?php echo esc_attr(self::OPT); ?>[username]" value="<?php echo esc_attr($username); ?>" class="regular-text" required></td></tr>
                    <tr><th scope="row"><label>Sandbox Password</label></th><td><input type="password" name="<?php echo esc_attr(self::OPT); ?>[pass_sbx]" value="<?php echo esc_attr($pass_sbx); ?>" class="regular-text" autocomplete="new-password"></td></tr>
                    <tr><th scope="row"><label>Production Password</label></th><td><input type="password" name="<?php echo esc_attr(self::OPT); ?>[pass_prod]" value="<?php echo esc_attr($pass_prod); ?>" class="regular-text" autocomplete="new-password"></td></tr>
                    <tr><th scope="row"><label>Preorder Discount (%)</label></th><td><input style='width: 65px' type="number" step="0.01" name="<?php echo esc_attr(self::OPT); ?>[pre_order_discount]" value="<?php echo esc_attr($pre_order_discount); ?>" class="regular-text" ></td></tr>
                    
                    <!-- Price Multiplier -->
                    <tr>
                        <th scope="row"><label>Default Price Multiplier</label></th>
                        <td>
                            <input type="hidden" name="<?php echo esc_attr(self::OPT); ?>[defult_price_multiplier]" value="<?php echo esc_attr($defult_price_multiplier); ?>">
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
                                    const defultPriceMultiplierIn = document.querySelector('[name="heo_wc_importer_settings[defult_price_multiplier]"]');

                                    function makeRow(values){
                                        const tr = document.createElement('tr');
                                        tr.className = 'ft-pr-row';
                                        tr.innerHTML = `
                                            <td><input type="number" step="0.01" min="0" placeholder="0.00"
                                                    name="ft_price_rows[start][]" value="${values?.start ?? ''}" class="regular-text"></td>
                                            <td><input type="number" step="0.01" min="0" placeholder="e.g. 9.99 (or leave empty)"
                                                    name="ft_price_rows[end][]" value="${values?.end ?? ''}" class="regular-text"></td>
                                            <td><input type="number" step="0.01" min="0" placeholder="e.g. 1.95"
                                                    name="ft_price_rows[multiplier][]" value="${values?.multiplier ?? ''}" class="regular-text"></td>
                                            <td><button type="button" class="button ft-row-remove" aria-label="Remove row">Remove</button></td>
                                        `;

                                        tr.querySelector('[name="ft_price_rows[start][]"]').addEventListener('input', defaultMult);
                                        tr.querySelector('[name="ft_price_rows[end][]"]').addEventListener('input', defaultMult);
                                        tr.querySelector('[name="ft_price_rows[multiplier][]"]').addEventListener('input', defaultMult);
                                        return tr;
                                    }

                                    addBtn.addEventListener('click', function(){
                                        tbody.appendChild(makeRow());
                                    });

                                    function defaultMult(){
                                        const defaultMultData = [];
                                        table.querySelectorAll('.ft-pr-row').forEach(function (item) {
                                            let eachMult = {
                                                start: item.querySelector('[name="ft_price_rows[start][]"]').value,
                                                end: item.querySelector('[name="ft_price_rows[end][]"]').value,
                                                multiplier: item.querySelector('[name="ft_price_rows[multiplier][]"]').value,
                                            };
                                            defaultMultData.push(eachMult);
                                        });
                                        defultPriceMultiplierIn.value = JSON.stringify(defaultMultData);
                                    }

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
                                            defaultMult();
                                        }
                                    });

                                    (() => {
                                        let initialData = JSON.parse(defultPriceMultiplierIn.value || '[]');
                                        initialData.forEach(item => {
                                            tbody.appendChild(makeRow(item));
                                        });
                                    })()
                                })();
                            </script>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save Settings'); ?>
            </form>

            <hr>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:10px">
                <?php wp_nonce_field('heo_run_sync'); ?>
                <input type="hidden" name="action" value="heo_run_sync">
                <?php submit_button('Run Sync Now (Queue One-by-One)', 'primary', 'submit', false); ?>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block">
                <?php wp_nonce_field('heo_clear_log'); ?>
                <input type="hidden" name="action" value="heo_clear_log">
                <?php submit_button('Clear Log', 'secondary', 'submit', false, ['onclick'=>"return confirm('Clear the importer log?');"]); ?>
            </form>

            <?php if ($log): ?>
                <h2 style="margin-top:24px">Last Log</h2>
                <pre style="max-height:420px;overflow:auto;background:#111;color:#0f0;padding:12px;"><?php echo esc_html($log); ?></pre>
            <?php endif; ?>
        </div>
        <?php
        
    }

    public function register_settings(){
        register_setting(self::OPT, self::OPT, [$this, 'sanitize_settings']);
    }

    public function sanitize_settings($opts) {
        $defaults = [
            'environment'     => 'sandbox',
            'username'        => '',
            'pass_prod'       => '',
            'pass_sbx'        => '',
            'pre_order_discount'      => '',
            'defult_price_multiplier' => ''
        ];
        $opts = wp_parse_args($opts, $defaults);
        $opts['environment']     = in_array($opts['environment'], ['sandbox','production'], true) ? $opts['environment'] : 'sandbox';
        $opts['username']        = $this->trim_cred($opts['username'] ?? '');
        $opts['pass_prod']       = $this->trim_cred($opts['pass_prod'] ?? '');
        $opts['pass_sbx']        = $this->trim_cred($opts['pass_sbx'] ?? '');
        $opts['pre_order_discount']        = $this->trim_cred($opts['pre_order_discount'] ?? 1);
        return $opts;
    }

    public function handle_clear_log() {
        if ( ! current_user_can('manage_woocommerce') ) wp_die('Unauthorized');
        check_admin_referer('heo_clear_log');
        delete_transient(self::LOG_TRANSIENT);
        wp_safe_redirect(add_query_arg(['page'=>self::PAGE_SLUG], admin_url('admin.php'))); exit;
    }

    public function handle_manual_sync(){
        $this->seed_page_job(1);
        wp_safe_redirect(add_query_arg(['page'=>self::PAGE_SLUG], admin_url('admin.php'))); exit;
    }

    public function seed_page_job($page = 1){
        // $this->log('Page call no: '.$page );
        $this->api_get($page);
    }

    public function process_single_job($p){
        $this->upsert_product($p);
    }

    public function heo_add_custom_product_field() {
        woocommerce_wp_text_input([
            'id'          => '_preorder_deadline',
            'label'       => __('Preorder Deadline', 'woocommerce'),
            'desc_tip'    => true,
            'description' => __('Select the deadline date for preorders.', 'woocommerce'),
            'type'        => 'date',
        ]);
    }

    public function heo_save_preorder_deadline_field($product) {
        if (isset($_POST['_preorder_deadline'])) {
            $product->update_meta_data('_preorder_deadline', sanitize_text_field($_POST['_preorder_deadline']));
        }
    }

    private function trim_cred($s) { 
        $s = (string)$s; 
        $s = preg_replace("/[\r\n\t]+/", '', $s); 
        return trim($s); 
    }

    private function log($line) { 
        $ts = date_i18n('Y-m-d H:i:s'); 
        $prev = get_transient(self::LOG_TRANSIENT); 
        $prev = $prev ? "\n".$prev : ''; 
        $msg = '['.$ts.'] '.$line; 
        set_transient(self::LOG_TRANSIENT, $msg.$prev, 12 * HOUR_IN_SECONDS); 
        if (defined('WP_CLI') && WP_CLI) WP_CLI::log($line); 
    }

    private function get_api_url($page = 1, $page_size = 50) {
        $o = get_option(self::OPT, []); 
        $env = $o['environment'] ?? 'sandbox';
        if ($env === 'production') {
            return "https://integrate.heo.com/retailer-api/v1/catalog/products?pageSize={$page_size}&page=${page}";
        }
        return "https://integrate.heo.com/retailer-api-test/v1/catalog/products?pageSize={$page_size}&page=${page}";
    }
    
    private function headers() {
        return ['Accept'=>'application/json','Content-Type'=>'application/json','User-Agent'=>'heo-wooimporter/1.4.1 (+WordPress)'];
    }

    private function get_auth() {
        $o = get_option(self::OPT, []);
        $env = $o['environment'] ?? 'sandbox';
        $user = $this->trim_cred($o['username'] ?? '');
        $pass = $this->trim_cred($env === 'production' ? ($o['pass_prod'] ?? '') : ($o['pass_sbx'] ?? ''));
        return [$user,$pass,$env];
    }

    private function get_lan_data_from_array($arr){
        foreach($arr as $each_lang){
            if($each_lang['langIso2'] === self::LANG_CODE) return $each_lang['translation'];
        }
    }

    private function minify_product_payload(array $p) {
        $minified_product = [];
        $minified_product['productNumber'] = $p['productNumber'];
        $minified_product['title'] =  $this->get_lan_data_from_array($p['name']);
        $minified_product['description'] =  $this->get_lan_data_from_array($p['description']);
        $minified_product['media'] = $p['media'];
        $minified_product['isEndOfLife'] = $p['isEndOfLife'];

        $categories = [];
        foreach($p['categories'] as $cat){ $categories[] = $this->get_lan_data_from_array($cat['translations']); }
        $minified_product['categories'] =  $categories;

        $manufacturers = [];
        foreach($p['manufacturers'] as $manufac){ $manufacturers[] = $this->get_lan_data_from_array($manufac['translations']); }
        $minified_product['manufacturers'] =  $manufacturers;

        $minified_product['prices'] = $p['prices'];
        $minified_product['preorderDeadline'] = $p['preorderDeadline'];

        return $minified_product;
    }

    private function api_get($page = 1){
        list($user, $pass, $env) = $this->get_auth();
        if ($user === '' || $pass === '') { $this->log('No credentials for API GET ('.$env.').'); return null; }
        $auth = 'Basic '.base64_encode($user.':'.$pass);
        $url = $this->get_api_url($page, self::BATCH);
        $method = 'GET';
        $args = ['headers' => $this->headers() + ['Authorization' => $auth, 'Accept-Language'=>'en']];
        $args = $args + ['timeout'=>60, 'redirection'=>0, 'sslverify'=>true, 'httpversion'=>'1.1', 'method'=>$method];

        $res = wp_remote_request($url, $args);
        if (is_wp_error($res)) {
            $this->log('HTTP '.$method.' error: '.$res->get_error_message().' ['.$url.']');
            return false;
        } else {
            $code = (int) wp_remote_retrieve_response_code($res);
        }
        $response = json_decode($res['body'], true);
        $i = 1;
        foreach($response['content'] as $each_product){
            $minifyed_data = $this->minify_product_payload($each_product);
            if ( !as_next_scheduled_action( 'heo_wc_import_single', $minifyed_data ) ) {
                as_schedule_single_action( time() + self::AS_SPACING * $i, 'heo_wc_import_single', [$minifyed_data],  self::AS_GROUP);
                // $this->log('new Product Shecduleed: '. $i);
            }
            $i++;
        }        

        $next_page = $page + 1;
        if ( !as_next_scheduled_action('heo_wc_seed_page', [$next_page]) && $response['pagination']['totalPages'] > $next_page) {
            as_schedule_single_action( time() + (self::AS_SPACING * self::BATCH), 'heo_wc_seed_page', [$next_page],  self::AS_GROUP);
            // $this->log('Next page event Add: '.$next_page);
        }
    }

    private function insert_product_tax( array $term_names, string $taxonomy){
        $terms_ids = [];
        foreach($term_names as $term_name_com){
            $term_name_com = explode("&", $term_name_com);
            foreach($term_name_com as $term_name){
                $term_name = trim($term_name);
                $term = term_exists( $term_name, $taxonomy );
                if ( !$term ) $term = wp_insert_term( $term_name, $taxonomy );
                $terms_ids[] = is_array($term) ? (int)$term['term_id'] : (int)$term;
            }
        }
        return $terms_ids;
    }

    private function import_images($product_id, array $image_urls){
        if ( ! function_exists('media_handle_sideload') ) {
            require_once ABSPATH.'wp-admin/includes/media.php';
            require_once ABSPATH.'wp-admin/includes/file.php';
            require_once ABSPATH.'wp-admin/includes/image.php';
        }
        $image_ids = [];
        foreach($image_urls as $image_url){
            $url = $image_url['url'];
            $attachment_id = media_sideload_image($url, $product_id, null, 'id');
            if ( is_wp_error( $attachment_id ) ){
                $this->log('Image Upload fail, product ID'.$product_id.' url : '.$url);
                continue;
            }
            // else{
            //     $this->log('Image Upload Successfull, product ID'.$product_id.' url : '.$url);
            // }
            $image_ids[] = $attachment_id;
        }
        return $image_ids;
    }

    private function upload_image($product_id, $product, $media){
        ['mainImage' => $mainImage, 'additionalMedia' => $additionalMedia] = $media;

        $update_image_status = false;

        $main_image = $this->import_images($product_id, [$mainImage]);
        if(is_array($main_image) && count($main_image) > 0){
            $main_image = $main_image[0];
            $product->set_image_id( $main_image );
            $update_image_status = true;
        }

        $gallery_images = $this->import_images($product_id, $additionalMedia);
        if(is_array($gallery_images) && count($gallery_images) > 0){
            $product->set_gallery_image_ids( $gallery_images );
            $update_image_status = true;
        }

        if($update_image_status) $product_id = $product->save();
    }

    private function upsert_product(array $p){
        $sku = $p['productNumber'] ?? ''; 
        if ($sku === '') return 0;

        if ( ! class_exists('WC_Product_Simple') ) {
            $this->log('❌ WooCommerce product classes missing.');
            return 0;
        }

        $product_id = wc_get_product_id_by_sku($sku);

        if($product_id){
            $this->log('The Product is already Updaloded SKU: '.$sku.', ID: '. $product_id);
            return false;
        }
        
        ['title' => $title, 'description' => $description, 'prices' => ['basePricePerUnit' => ['amount'=> $price]], 'manufacturers' => $manufacturers, 'categories' => $categories, 'media' => $media, 'preorderDeadline' => $preorderDeadline] = $p;

        $categories = $this->insert_product_tax($categories, 'product_cat');

        $product = new WC_Product_Simple();
        $product->set_sku($sku);
        $product->set_name($title);
        $product->set_description($description);
        $product->set_status('publish');
        $product->set_regular_price($price);
        $product->set_category_ids( $categories );
        if($preorderDeadline){
            $product->update_meta_data('_preorder_deadline', $preorderDeadline);
            $product->set_stock_status('preorder');
        }else{
            $product->set_stock_status('at_supplier');
        }

        $product_id = $product->save();

        $brands = $this->insert_product_tax($manufacturers, 'product_brand');
        wp_set_object_terms( $product_id, $brands, 'product_brand' );
        
        $this->upload_image($product_id, $product, $media);

        $this->log('↳ Created product #'.$product_id.' for SKU '.$sku);

        return $product_id;
    }

    /*Brand Price variation */
    public function brand_fild_rendard($term){
        $meta_key = '_brand_price_range_multipliers';
        $rows = get_term_meta($term->term_id, $meta_key, true);
        if (!is_array($rows)) $rows = [];

        // Ensure at least one empty row for UI
        if (empty($rows)) {
            $rows = [
                ['start' => '', 'end' => '', 'multiplier' => '']
            ];
        }

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
                            <td>
                                <input type="number" step="0.01" min="0" placeholder="0.00"
                                    name="ft_price_rows[start][]" value="<?php echo esc_attr($start); ?>" class="regular-text">
                            </td>
                            <td>
                                <input type="number" step="0.01" min="0" placeholder="e.g. 9.99 (or leave empty)"
                                    name="ft_price_rows[end][]" value="<?php echo esc_attr($end); ?>" class="regular-text">
                            </td>
                            <td>
                                <input type="number" step="0.01" min="0" placeholder="e.g. 1.95"
                                    name="ft_price_rows[multiplier][]" value="<?php echo esc_attr($mult); ?>" class="regular-text">
                            </td>
                            <td>
                                <button type="button" class="button ft-row-remove" aria-label="Remove row">Remove</button>
                            </td>
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
                            <td><input type="number" step="0.01" min="0" placeholder="0.00"
                                    name="ft_price_rows[start][]" value="${values?.start ?? ''}" class="regular-text"></td>
                            <td><input type="number" step="0.01" min="0" placeholder="e.g. 9.99 (or leave empty)"
                                    name="ft_price_rows[end][]" value="${values?.end ?? ''}" class="regular-text"></td>
                            <td><input type="number" step="0.01" min="0" placeholder="e.g. 1.95"
                                    name="ft_price_rows[multiplier][]" value="${values?.multiplier ?? ''}" class="regular-text"></td>
                            <td><button type="button" class="button ft-row-remove" aria-label="Remove row">Remove</button></td>
                        `;
                        return tr;
                    }

                    addBtn.addEventListener('click', function(){
                        tbody.appendChild(makeRow());
                    });

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

    /* ---------- Save on Edit ---------- */
    public function brand_fild_save($term_id){
        if (empty($_POST['ft_brand_price_ranges_nonce']) ||
            !wp_verify_nonce($_POST['ft_brand_price_ranges_nonce'], 'ft_brand_price_ranges')) {
            return;
        }

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

            $clean[] = [
                'start'      => $start,
                'end'        => $end,           // '' means open-ended
                'multiplier' => $mult
            ];
        }

        if (!empty($clean)) {
            update_term_meta($term_id, $meta_key, $clean);
        } else {
            delete_term_meta($term_id, $meta_key);
        }
    }

    public function custom_dynamic_price($price, $product) {
        // if (is_admin()) return $price; // keep backend unchanged
        $is_price_update = false;

        $product_id = $product->get_id();
        $brands = wp_get_post_terms($product_id, 'product_brand');

        // Update price by brand
        if(!empty($brands) && isset($brands[0]->name)){
            $price_renge = $this->ft_get_brand_price_ranges($brands[0]->term_id);
            if(!empty($price_renge)){
                $new_price = $this->update_price_from_array($price, $price_renge);
                if($new_price != $price){
                    $is_price_update = true;
                    $price = $new_price;
                }
            }
        }

        // update price by defult multiplyer
        $o = get_option(self::OPT, []);
        if(!$is_price_update){
            $default_ranges = isset($o['defult_price_multiplier']) ? $o['defult_price_multiplier'] : [];
            if(is_string($default_ranges)) $default_ranges = json_decode($default_ranges, true);
            if(!empty($default_ranges)){
                $new_price = $this->update_price_from_array($price, $default_ranges);
                if($new_price != $price) $price = $new_price;
            }
        }
        $product->set_regular_price($price);

        // give discount by preorder
        $preorderDeadline = get_post_meta( $product_id, '_preorder_deadline', true );
        if($preorderDeadline){
            $deadline = new DateTime($preorderDeadline);
            $now = new DateTime('now');
            if ($deadline > $now){
                $discount = (int)$o['pre_order_discount'];
                $price = $price - ($price * ($discount / 100));
                $product->set_sale_price( $price );

            }
        }

        return $price ;
    }

    private function ft_get_brand_price_ranges( $brand ) {
        if ( is_numeric( $brand ) ) {
            $term_id = (int) $brand;
        } else { 
            $term = get_term_by( 'slug', $brand, 'product_brand' );
            if ( ! $term ) $term = get_term_by( 'name', $brand, 'product_brand' );
            if ( ! $term || is_wp_error( $term ) ) return []; 
            $term_id = (int) $term->term_id;
        }

        $rows = get_term_meta( $term_id, '_brand_price_range_multipliers', true );
        return is_array( $rows ) ? $rows : null;
    }

    private function update_price_from_array($price, $ranges) {
        if (!is_array($ranges)) return $price;

        foreach ($ranges as $range) {
            $start = isset($range['start']) ? floatval($range['start']) : null;
            $end = isset($range['end']) && $range['end'] !== '' ? floatval($range['end']) : null;
            $multiplier = isset($range['multiplier']) ? floatval($range['multiplier']) : null;

            if ($start !== null && $multiplier !== null && $multiplier > 0) {
                if ($end === null) {
                    // Open-ended range
                    if ($price >= $start) {
                        return round($price * $multiplier, 2);
                    }
                } else {
                    // Closed range
                    if ($price >= $start && $price <= $end) {
                        return round($price * $multiplier, 2);
                    }
                }
            }
        }
        return $price;
    }

}
new HEO_WC_Importer();


// Add custom stock statuses to WooCommerce
add_filter('woocommerce_product_stock_status_options', function($status_options) {
    $status_options['at_supplier'] = __('At Supplier', 'woocommerce');
    $status_options['preorder'] = __('Pre-Order', 'woocommerce');
    return $status_options;
});

// Make WooCommerce recognize custom statuses
add_filter('woocommerce_product_stock_status', function($status, $product) {
    if (in_array($status, ['at_supplier', 'preorder'])) {
        return $status;
    }
    return $status;
}, 10, 2);

// Display correct label on the front end (product page)
add_filter('woocommerce_get_availability_text', function($availability, $product) {
    $status = $product->get_stock_status();
    if ($status === 'at_supplier') {
        $availability = __('Available at Supplier', 'woocommerce');
    } elseif ($status === 'preorder') {
        $availability = __('Pre-Order Now', 'woocommerce');
    }
    return $availability;
}, 10, 2);

// Optional: change color or style
add_filter('woocommerce_get_availability_class', function($class, $product) {
    $status = $product->get_stock_status();
    if ($status === 'at_supplier') {
        $class = 'stock at-supplier';
    } elseif ($status === 'preorder') {
        $class = 'stock preorder';
    }
    return $class;
}, 10, 2);


// Ensure custom stock statuses show in admin product list (Stock column)
add_filter('woocommerce_admin_stock_html', function($stock_html, $product) {
    $status = $product->get_stock_status();

    // Map your custom statuses to readable text
    $custom_labels = [
        'at_supplier' => __('At Supplier', 'woocommerce'),
        'preorder'    => __('Pre-Order', 'woocommerce'),
    ];

    if (isset($custom_labels[$status])) {
        // Optional: add color/style
        $color = $status === 'at_supplier' ? '#e6b800' : '#0073aa'; // yellow / blue
        $stock_html = '<mark class="stock" style="background: transparent none;color:' . esc_attr($color) . ';">' . esc_html($custom_labels[$status]) . '</mark>';
    }

    return $stock_html;
}, 10, 2);


