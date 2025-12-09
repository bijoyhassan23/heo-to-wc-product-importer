<?php
/**
 * Plugin Name: heo → WooCommerce Importer
 * Description: Imports & syncs products.
 * Version: 6.0.0
 * Author: Bijoy
 * Author URI: https://bijoy.dev
 */

if ( ! defined('ABSPATH') ) exit;

define('HEO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HEO_PLUGIN_URL', plugin_dir_url( __FILE__ ));
define('HEO_PLUGIN_VERSION', '6.0.0');

// Include necessary files
include_once HEO_PLUGIN_DIR . 'includes/stock-status.php';
include_once HEO_PLUGIN_DIR . 'includes/admin-part.php';
include_once HEO_PLUGIN_DIR . 'includes/general-function.php';
include_once HEO_PLUGIN_DIR . 'includes/product-setup.php';
include_once HEO_PLUGIN_DIR . 'includes/product-upload.php';

class HEO_WC_Importer {
    const PAGE_SLUG = 'heo-wc-importer';
    const OPT = 'heo_wc_importer_settings'; // Option name 
    const LOG_TRANSIENT = 'heo_wc_import_log';

    const LANG_CODE = 'EN';

    const AS_GROUP   = 'heo_wc_importer_queue';
    const AS_SPACING = 20;
    const BATCH = 50;

    const DAILY_CHECK_SCHEDULAR = 'heo_wc_regular_seed_page';
    const EACH_REGULAR_SYNC = 'heo_wc_regular_each_seed_page';

    use General_function, Admin_part, Stock_status, Product_setup, Product_upload;
    public function __construct() {

        $this->general_function_init();

        // admin side
        $this->admin_init();

        $this->product_setup_init();

        // Action Scheduler (positional single arg)
        add_action('heo_wc_seed_page', [$this, 'seed_page_job']);
        add_action('heo_wc_import_single', [$this, 'upsert_product']);
        add_action('admin_post_heo_run_sync', [$this, 'handle_product_manual_sync']);
        
        add_action('admin_post_heo_run_regular_sync', [$this, 'handle_regular_sync']);
        add_action(self::DAILY_CHECK_SCHEDULAR, [$this, 'seed_regular_sync_job']);
        add_action(self::EACH_REGULAR_SYNC, [$this, 'seed_each_regular_sync_job']);

        add_filter('woocommerce_product_get_price', [$this, 'custom_dynamic_price'], 20, 2);

        // Stock status added
        $this->stock_status_init();
    }

    public function handle_product_manual_sync(){
        $this->seed_page_job(1);
        wp_safe_redirect(add_query_arg(['page'=>self::PAGE_SLUG], admin_url('admin.php'))); 
        exit;
    }

    public function seed_page_job($page = 1){
        $response = $this->api_get_info(['page' => $page]);
        if ($response) {
            $i = 1;
            foreach($response['content'] as $each_product){
                $minifyed_data = $this->minify_product_payload($each_product);
                if ( !as_next_scheduled_action( 'heo_wc_import_single', $minifyed_data ) ) {
                    as_schedule_single_action( time() + self::AS_SPACING * $i, 'heo_wc_import_single', [$minifyed_data],  self::AS_GROUP);
                    // $this->log('new Product Shecduleed: '. $i);
                }
                $i++;
            }    
        }

        $next_page = $page + 1;
        if ( !as_next_scheduled_action('heo_wc_seed_page', [$next_page]) && $response['pagination']['totalPages'] >= $next_page) {
            as_schedule_single_action( time() + (self::AS_SPACING * self::BATCH), 'heo_wc_seed_page', [$next_page],  self::AS_GROUP);
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
                if(!$product_id) continue;

                $product = wc_get_product( $product_id );
                if(!$product) continue;

                ['availabilityState' => $availabilityState, 'availableToOrder' => $availableToOrder, 'eta' => $eta] = $each_product;
                $eta = trim($eta);

                $current_stock_status = $product->get_stock_status();
                if ( $current_stock_status === 'instock' ){
                    $this->log('Product in stock, skipping SKU: '.$sku);
                    continue;
                }

                $currnet_eta = get_post_meta( $product_id, '_eta_deadline', true );
                $currnet_eta = trim($currnet_eta);
                $preorderDeadline = get_post_meta( $product_id, '_preorder_deadline', true );

                if(($preorderDeadline && (strtotime($preorderDeadline) > time())) || (($availabilityState === 'PREORDER' || $availabilityState === 'INCOMING') && $availableToOrder && $eta)){
                    $query_stock_status = 'preorder';
                }elseif($availabilityState === 'AVAILABLE' && $availableToOrder){
                    $query_stock_status = 'at_supplier';
                }else{
                    $query_stock_status = 'outofstock';
                }

                if($current_stock_status === $query_stock_status && $currnet_eta === $eta) continue;

                if($query_stock_status) $product->set_stock_status($query_stock_status);
                if($eta) $product->update_meta_data('_eta_deadline', $eta);

                $product_id = $product->save();
                $this->log('Stock updated for SKU: '.$sku.', ID: '. $product_id);
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
                if(!$product_id) continue;

                $product = wc_get_product( $product_id );
                if(!$product) continue;
                
				$current_stock_status = $product->get_stock_status();
                if ( $current_stock_status === 'instock' ){
                    $this->log('Product in stock, skipping price Update for SKU: '.$sku);
                    continue;
                }
				
                $regular_price = false;
                $sale_price = false;
                // Setup the price
                if($each_product['strikePricePerUnit']['amount'] && $each_product['basePricePerUnit']['amount']){
                    $regular_price = $each_product['strikePricePerUnit']['amount'];
                    $sale_price = $each_product['basePricePerUnit']['amount'];
                }elseif(!$each_product['strikePricePerUnit'] && $each_product['basePricePerUnit']['amount']){
                    $regular_price = $each_product['basePricePerUnit']['amount'];
                }else{
                    continue;
                }
                $regular_price = trim($regular_price);
                $sale_price = trim($sale_price);
                $current_regular_price = trim($product->get_regular_price());
                $current_sale_price = trim($product->get_sale_price());

                if($current_regular_price == $regular_price && $current_sale_price == $sale_price) continue;

                if($regular_price) $product->set_regular_price($regular_price);
                if($sale_price) $product->set_sale_price($sale_price);

                $product_id = $product->save();
                $this->log('Price updated for SKU: '.$sku.', ID: '. $product_id);
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

}
new HEO_WC_Importer();