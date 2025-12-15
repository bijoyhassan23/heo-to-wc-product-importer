<?php
/**
 * Plugin Name: HEO Importer for woocommerce
 * Description: Imports & syncs products
 * Version: 6.0.0
 * Author: Bijoy
 * Author URI: https://bijoy.dev
 * Requires Plugins: woocommerce
 * Text Domain: heo-to-wc-product-importer
 * License: GPL v2 or later
 */

defined('ABSPATH') or exit;

define('HEO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HEO_PLUGIN_URL', plugin_dir_url( __FILE__ ));
define('HEO_PLUGIN_VERSION', '6.0.0');

// Include necessary files
spl_autoload_register(function ($class_name) {
    if (strpos($class_name, 'HEO_WC_') === 0) {
        $file_name = strtolower($class_name) . '.php';
        $file_path = HEO_PLUGIN_DIR . 'includes/' . $file_name;
        if (file_exists($file_path)) {
            include_once $file_path;
        }
    }
});

class HEO_WC_Importer {
    // Create instance
    private static $instance = null;
    public static function get_instance(){
        if(self::$instance == null) self::$instance = new self();
        return self::$instance;
    }
    const PAGE_SLUG = 'heo-wc-importer';
    const OPT = 'heo_wc_importer_settings'; // Option name 
    const LOG_TRANSIENT = 'heo_wc_import_log';

    const LANG_CODE = 'EN';

    const AS_GROUP   = 'heo_wc_importer_queue';
    const AS_SPACING = 10;
    const BATCH = 5;

    const DAILY_CHECK_SCHEDULAR = 'heo_wc_regular_seed_page';
    const EACH_REGULAR_SYNC = 'heo_wc_regular_each_seed_page';

    use HEO_WC_General_function, HEO_WC_Admin_part, HEO_WC_Stock_status, HEO_WC_Product_setup, HEO_WC_Product_upload, HEO_WC_Single_Product_Sync;
    use HEO_WC_handle_redundant_code;
    public function __construct() {

        $this->general_function_init();

        // admin side
        $this->admin_init();

        $this->product_setup_init();

        // Action Scheduler (positional single arg)
        add_action('heo_wc_seed_page', [$this, 'seed_page_job']);
        add_action('heo_wc_import_single', [$this, 'upsert_product']);
        
        add_action('admin_post_heo_run_regular_sync', [$this, 'handle_regular_sync']);
        add_action(self::DAILY_CHECK_SCHEDULAR, [$this, 'seed_regular_sync_job']);
        add_action(self::EACH_REGULAR_SYNC, [$this, 'seed_each_regular_sync_job']);

        add_filter('woocommerce_product_get_price', [$this, 'custom_dynamic_price'], 20, 2);

        // Stock status added
        $this->stock_status_init();

        $this->single_product_sync_init();
    }

    public function seed_page_job($page = 1){
        $this->log('Seeding page job started for page: '.$page);
        $response = $this->api_get_info(['page' => $page]);
        if ($response) {
            $i = 1;
            foreach($response['content'] as $each_product){
                if(empty($each_product['productNumber']) || wc_get_product_id_by_sku($each_product['productNumber'])) continue;
                $minifyed_data = $this->minify_product_payload($each_product);
                if ( !as_next_scheduled_action( 'heo_wc_import_single', $minifyed_data ) ) {
                    as_schedule_single_action( time() + self::AS_SPACING * $i, 'heo_wc_import_single', [$minifyed_data],  self::AS_GROUP);
                    // $this->log('new Product Shecduleed: '. $i);
                    $i++;
                }
            }    
        }else{
            $this->log('No response from API for page: '.$page);
            $this->log(json_encode($response));
            return;
        }

        $next_page = $page + 1;
        if ( !as_next_scheduled_action('heo_wc_seed_page', [$next_page]) && $response['pagination']['totalPages'] >= $next_page) {
            as_schedule_single_action( time() + (self::AS_SPACING * $i), 'heo_wc_seed_page', [$next_page],  self::AS_GROUP);
            // $this->log('Next page event Add: '.$next_page);
        }
    }

    public function handle_regular_sync(){
        if(as_next_scheduled_action( self::DAILY_CHECK_SCHEDULAR)) as_unschedule_action( self::DAILY_CHECK_SCHEDULAR );
        if(as_next_scheduled_action( self::EACH_REGULAR_SYNC )) as_unschedule_action( self::EACH_REGULAR_SYNC );
        as_schedule_recurring_action( time(), DAY_IN_SECONDS, self::DAILY_CHECK_SCHEDULAR, [], self::AS_GROUP );
        wp_safe_redirect(add_query_arg(['page'=>self::PAGE_SLUG], admin_url('admin.php'))); exit;
    }

    public function seed_regular_sync_job($page = 1){
        if(as_next_scheduled_action( self::EACH_REGULAR_SYNC )) {
            $this->log('Stock sync already running, skipping new schedule.');
            return;
        }
        as_schedule_single_action( time(), self::EACH_REGULAR_SYNC, [1],  self::AS_GROUP);
    }

    public function seed_each_regular_sync_job($page = 1){
        $availabilities_page = $this->add_availabilities($page);
        $prices_page = $this->add_prices($page);

        if(!$availabilities_page && $prices_page){
            $next_page = $prices_page;
        }elseif($availabilities_page && !$prices_page){
            $next_page = $availabilities_page;
        }elseif($availabilities_page && $prices_page){
            $next_page = min($availabilities_page, $prices_page);  
        }else{
            return;
        }
        as_schedule_single_action( time() + self::AS_SPACING, self::EACH_REGULAR_SYNC, [$next_page],  self::AS_GROUP);
    }

    private function add_availabilities($page = 1){
        $response = $this->api_get_info([ 'api_type' => 'availabilities', 'page' => $page, ]);
        if($response && !empty($response['content'])){
            foreach($response['content'] as $each_product){
                $sku = $each_product['productNumber'] ?? ''; 
                if ($sku === '') continue;

                $product_id = wc_get_product_id_by_sku($sku);
                if(!$product_id){
                    $this->single_product_upload($sku);
                    continue;
                } 

                $update_status = $this->product_stock_and_price_update(['product_id' => $product_id, 'sku' => $sku, 'availability_info' => $each_product, 'sync' => 'availability']);
                if($update_status['availability_updated']) $this->log('Stock updated for SKU: '.$sku.', ID: '. $product_id);
            } 
        }

        $next_page = $page + 1;
        if ( !as_next_scheduled_action(self::EACH_REGULAR_SYNC, [$next_page]) && $response['pagination']['totalPages'] >= $next_page) {
            return $next_page;
        }else{
            return false;
        }
    }

    private function add_prices($page = 1){
        $response = $this->api_get_info([ 'api_type' => 'prices', 'page' => $page, ]);
        if($response && !empty($response['content'])){
            foreach($response['content'] as $each_product){
                $sku = $each_product['productNumber'] ?? ''; 
                if ($sku === '') continue;

                $product_id = wc_get_product_id_by_sku($sku);
                if(!$product_id){
                    $this->single_product_upload($sku);
                    continue;
                }
                
                $update_status = $this->product_stock_and_price_update(['product_id' => $product_id, 'sku' => $sku, 'price_info' => $each_product, 'sync' => 'price']);
                if($update_status['price_updated']){
                    $this->log('Price updated for SKU: '.$sku.', ID: '. $product_id);
                }
            } 
        }

        $next_page = $page + 1;
        if ( !as_next_scheduled_action(self::EACH_REGULAR_SYNC, [$next_page]) && $response['pagination']['totalPages'] >= $next_page) {
            return $next_page;
        }else{
            return false;
        }
    }

    public function custom_dynamic_price($price, $product_obj) {
        $price = (int) $price;

        $product_id = $product_obj->get_id();

        // depricated code check start
        $last_sync = get_post_meta($product_id, '_last_update', true);
        if(!$last_sync){
            $depricated_price = $this->custom_dynamic_price_depricated($price, $product_obj);
            return $depricated_price;
        }
        // depricated code check end

        // give discount by preorder
        $preorderDeadline = get_post_meta( $product_id, '_preorder_deadline', true );
        if($preorderDeadline){
            $o = get_option(self::OPT, []);
            $deadline = new DateTime($preorderDeadline);
            $now = new DateTime('now');
            if ($deadline > $now){
                $discount = (int)$o['pre_order_discount'];
                $price = round($price - ($price * ($discount / 100)));
                $product_obj->set_sale_price( $price );
            }
        }
		return $price ;
    }

    private function single_product_upload($sku){
        if ($sku === '') return false;

        $product_data = $this->api_get_info([ 'sku' => $sku, 'api_type' => 'products']);
        if($product_data && !empty($product_data['content'])){
            $product_data = $product_data['content'][0];
        }else{
            $this->log('No product info found for SKU '.$sku);
            return false;
        }

        if(empty($product_data['productNumber'])) return;
        $minifyed_data = $this->minify_product_payload($product_data);
        if ( !as_next_scheduled_action( 'heo_wc_import_single', $minifyed_data ) ) {
            as_schedule_single_action( time() + self::AS_SPACING, 'heo_wc_import_single', [$minifyed_data],  self::AS_GROUP);
        }
        return true;
    }

}

// Initialize the plugin
HEO_WC_Importer::get_instance();