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

class HEO_WC_Importer {
    const PAGE_SLUG = 'heo-wc-importer';
    const OPT = 'heo_wc_importer_settings'; // Option name 
    const CRON_HOOK = 'heo_wc_importer_cron_sync';
    const LOG_TRANSIENT = 'heo_wc_import_log';

    const LANG_CODE = 'EN';

    const AS_GROUP   = 'heo_wc_importer_queue';
    const AS_SPACING = 20;
    const BATCH = 50;

    const DAILY_CHECK_SCHEDULAR = 'heo_wc_regular_seed_page';
    const EACH_REGULAR_SYNC = 'heo_wc_regular_each_seed_page';

    use General_function, Admin_part, Stock_status, Product_setup;
    public function __construct() {

        $this->general_function_init();

        // admin side
        $this->admin_init();

        $this->product_setup_init();
        

        add_action('admin_post_heo_clear_log', [$this, 'handle_clear_log']);

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
        wp_safe_redirect(add_query_arg(['page'=>self::PAGE_SLUG], admin_url('admin.php'))); exit;
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
        $minified_product['barcodes'] = $p['barcodes'];
        $minified_product['dimensions'] = $p['dimensions']['validatedDimensions'] ?? $p['dimensions']['manufacturerDimensions'] ?? [];

        $categories = [];
        foreach($p['categories'] as $cat){ $categories[] = $this->get_lan_data_from_array($cat['translations']); }
        $minified_product['categories'] =  $categories;

        $themes = [];
        foreach($p['themes'] as $theme){ $themes[] = $this->get_lan_data_from_array($theme['translations']); }
        $minified_product['themes'] =  $themes;

        $manufacturers = [];
        foreach($p['manufacturers'] as $manufac){ $manufacturers[] = $this->get_lan_data_from_array($manufac['translations']); }
        $minified_product['manufacturers'] =  $manufacturers;

        $types = [];
        foreach($p['types'] as $type){ $types[] = $this->get_lan_data_from_array($type['translations']); }
        $minified_product['types'] =  $types;

        $minified_product['prices'] = $p['prices'];
        $minified_product['preorderDeadline'] = $p['preorderDeadline'];

        return $minified_product;
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

    public function upsert_product(array $p){
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

        $availability_info = $this->api_get_info([ 'sku' => $sku, 'api_type' => 'availabilities']);
        if($availability_info && !empty($availability_info['content'])){
            $availability_info = $availability_info['content'][0];
        }else{
            $this->log('No availability info found for SKU '.$sku);
            $availability_info = ['availabilityState' => '', 'availableToOrder' => false, 'eta' => null];
        }

        $price_info = $this->api_get_info([ 'sku' => $sku, 'api_type' => 'prices']);
        if($price_info && !empty($price_info['content'])){
            $price_info = $price_info['content'][0];
        }else{
            $this->log('No Price info found for SKU '.$sku);
            $price_info = [ 'basePricePerUnit' => ['amount'=> null], 'strikePricePerUnit' => null, 'discountedPricePerUnit' => ['amount'=> null] ];
        }
        
        ['title' => $title, 'description' => $description, 'prices' => ['basePricePerUnit' => ['amount'=> $price]], 'manufacturers' => $manufacturers, 'categories' => $categories, 'themes' => $themes, 'media' => $media, 'preorderDeadline' => $preorderDeadline, 'dimensions' => $dimensions, 'types' => $types, 'barcodes' => $barcodes] = $p;
        ['availableToOrder' => $availableToOrder, 'eta' => $eta, 'availabilityState' => $availabilityState] = $availability_info;

        $categories = $this->insert_product_tax($categories, 'product_cat');

        $product = new WC_Product_Simple();
        $product->set_sku($sku);
        $product->set_name($title);
        $product->set_description($description);
        $product->set_status('publish');
        $product->set_category_ids( $categories );

        // Setup the price
        if($price_info['strikePricePerUnit']['amount'] && $price_info['basePricePerUnit']['amount']){
            $product->set_regular_price($price_info['strikePricePerUnit']['amount']);
            $product->set_sale_price($price_info['basePricePerUnit']['amount']);
        }elseif(!$price_info['strikePricePerUnit'] && $price_info['basePricePerUnit']['amount']){
            $product->set_regular_price($price_info['basePricePerUnit']['amount']);
        }else{
            $product->set_regular_price($price);
        }
        

        if($preorderDeadline || (($availabilityState === 'PREORDER' || $availabilityState === 'INCOMING') && $availableToOrder && $eta)){
            $product->set_stock_status('preorder');
        }elseif($availabilityState === 'AVAILABLE' && $availableToOrder){
            $product->set_stock_status('at_supplier');
        }else{
            $product->set_stock_status('outofstock');
        }

        if($preorderDeadline) $product->update_meta_data('_preorder_deadline', $preorderDeadline);
        if($eta) $product->update_meta_data('_eta_deadline', $eta);

        if($barcodes){
            foreach($barcodes as $barcode){
                if($barcode['type'] && $barcode['barcode']){
                    $product->update_meta_data('_product_barcode_type', $barcode['type']);
                    $product->update_meta_data('_product_barcode', $barcode['barcode']);
                    break;
                }
            }
        }

        if($dimensions['width']) $product->set_width($dimensions['width']['value'] / 10);
        if($dimensions['length']) $product->set_length($dimensions['length']['value'] / 10);
        if($dimensions['height']) $product->set_height($dimensions['height']['value'] / 10);
        if($dimensions['weight']) $product->set_weight($dimensions['weight']['value'] / 1000);

        $product_id = $product->save();

        $brands = $this->insert_product_tax($manufacturers, 'product_brand');
        wp_set_object_terms( $product_id, $brands, 'product_brand' );

        $series = $this->insert_product_tax($themes, 'series');
        wp_set_object_terms( $product_id, $series, 'series' );

        $pr_types = $this->insert_product_tax($types, 'type');
        wp_set_object_terms( $product_id, $pr_types, 'type' );
        
        $this->upload_image($product_id, $product, $media);

        $this->log('↳ Created product #'.$product_id.' for SKU '.$sku);

        return $product_id;
    }
    public function custom_dynamic_price($price, $product_obj) {
        // if (is_admin()) return $price; // keep backend unchanged
        $is_price_update = false;

        $product_id = $product_obj->get_id();
        $product = wc_get_product( $product_id );
        $brands = wp_get_post_terms($product_id, 'product_brand');

        $regular_price = $product->get_regular_price();
        $sale_price = $product->get_sale_price();

        // Update price by brand
        if(!empty($brands) && isset($brands[0]->name)){
            $price_renge = $this->ft_get_brand_price_ranges($brands[0]->term_id);
            if(!empty($price_renge)){
                $multiplyer = $this->update_price_from_array_multiplyer($price, $price_renge);
                $regular_price = $regular_price * $multiplyer;
                if($sale_price) $sale_price = $sale_price * $multiplyer;
                $new_price = $sale_price ? $sale_price : $regular_price;
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
                $multiplyer = $this->update_price_from_array_multiplyer($price, $default_ranges);
                $regular_price = $regular_price * $multiplyer;
                if($sale_price) $sale_price = $sale_price * $multiplyer;
                $new_price = $sale_price ? $sale_price : $regular_price;
                if($new_price != $price) $price = $new_price;
            }
        }

        $product_obj->set_regular_price($regular_price);
        if($sale_price) $product_obj->set_sale_price($sale_price);

        // give discount by preorder
        $preorderDeadline = get_post_meta( $product_id, '_preorder_deadline', true );
        if($preorderDeadline){
            $deadline = new DateTime($preorderDeadline);
            $now = new DateTime('now');
            if ($deadline > $now){
                $discount = (int)$o['pre_order_discount'];
                $price = $price - ($price * ($discount / 100));
                $product_obj->set_sale_price( $price );
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

    private function update_price_from_array_multiplyer($price, $ranges) {
        if (!is_array($ranges)) return 1;

        foreach ($ranges as $range) {
            $start = isset($range['start']) ? floatval($range['start']) : null;
            $end = isset($range['end']) && $range['end'] !== '' ? floatval($range['end']) : null;
            $multiplier = isset($range['multiplier']) ? floatval($range['multiplier']) : null;

            if ($start !== null && $multiplier !== null && $multiplier > 0) {
                if ($start <= $price  && ($end === null || $price <= $end)) {
                    return $multiplier;
                }
            }
        }
        return 1;
    }

}
new HEO_WC_Importer();