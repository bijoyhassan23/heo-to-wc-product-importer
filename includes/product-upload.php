<?php

trait Product_upload{
    private function product_upload_init(){
        
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

    private function product_price_calculator($product_id = null){
        if(!$product_id) return;

        $price_lock = get_post_meta($product_id, '_enable_price_lock', true);
        if($price_lock === 'yes') return;
        
        $server_regular_price = (int) get_post_meta($product_id, '_server_regular_price', true);
        $server_sale_price = (int) get_post_meta($product_id, '_server_sale_price', true);
        $brands = wp_get_post_terms($product_id, 'product_brand');
        $multiplyer = false;

        // Update price by brand
        if(!empty($brands) && isset($brands[0]->name)){
            $price_renge = $this->ft_get_brand_price_ranges($brands[0]->term_id);
            if(!empty($price_renge)) $multiplyer = $this->update_price_from_array_multiplyer($server_regular_price, $price_renge);
        }

        // update price by defult multiplyer
        if(!$multiplyer){
            $o = get_option(self::OPT, []);
            $default_ranges = isset($o['defult_price_multiplier']) ? $o['defult_price_multiplier'] : [];
            if(is_string($default_ranges)) $default_ranges = json_decode($default_ranges, true);
            if(!empty($default_ranges)){
                $multiplyer = $this->update_price_from_array_multiplyer($server_regular_price, $default_ranges);
            }
        }
        
        update_post_meta($product_id, '_regular_price', round($server_regular_price * $multiplyer));
        if($server_sale_price){
            update_post_meta($product_id, '_sale_price', round($server_sale_price * $multiplyer));
            update_post_meta($product_id, '_price', round($server_sale_price * $multiplyer));
        }else{
            update_post_meta($product_id, '_price', round($server_regular_price * $multiplyer));
            update_post_meta($product_id, '_sale_price', '');
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

    private function product_stock_and_price_update($params = []){
        $defults = ['product_id' => false, 'sku' => false , 'price_info' => [], 'availability_info' => [], 'sync' => 'both', 'price' => null];
        $params = wp_parse_args( $params, $defults );
        ['product_id' => $product_id, 'sku' => $sku, 'price_info' => $price_info, 'availability_info' => $availability_info, 'sync' => $sync, 'price' => $price] = $params;
        if(!$product_id) return false;
        try{
            if($sync === 'both' || $sync === 'price'){
                if(empty($price_info)){
                    if(!$sku) $sku = get_post_meta( $product_id, '_sku', true );
                    $price_info = $this->api_get_info([ 'sku' => $sku, 'api_type' => 'prices']);
                    if($price_info && !empty($price_info['content'])){
                        $price_info = $price_info['content'][0];
                    }else{
                        $this->log('No Price info found for SKU '.$sku);
                        $price_info = [ 'basePricePerUnit' => ['amount'=> null], 'strikePricePerUnit' => null, 'discountedPricePerUnit' => ['amount'=> null] ];
                    }
                }

                if($price_info['strikePricePerUnit']['amount'] && $price_info['basePricePerUnit']['amount']){
                    update_post_meta($product_id, '_server_regular_price', $price_info['strikePricePerUnit']['amount']);
                    update_post_meta($product_id, '_server_sale_price', $price_info['basePricePerUnit']['amount']);
                }elseif(!$price_info['strikePricePerUnit'] && $price_info['basePricePerUnit']['amount']){
                    update_post_meta($product_id, '_server_regular_price', $price_info['basePricePerUnit']['amount']);                
                }else{
                    update_post_meta($product_id, '_server_regular_price', $price);            
                }
                $this->product_price_calculator($product_id);
            }
        }catch(Exception $e){
            $this->log('Price update error for product ID '.$product_id.' : '.$e->getMessage());
        }

        try{
            if($sync === 'both' || $sync === 'availability'){
                if(empty($availability_info)){
                    if(!$sku) $sku = get_post_meta( $product_id, '_sku', true );
                    $availability_info = $this->api_get_info([ 'sku' => $sku, 'api_type' => 'availabilities']);
                    if($availability_info && !empty($availability_info['content'])){
                        $availability_info = $availability_info['content'][0];
                    }else{
                        $this->log('No availability info found for SKU '.$sku);
                        $availability_info = ['availabilityState' => '', 'availableToOrder' => false, 'eta' => null, 'availability' => null];
                    }
                }

                ['availableToOrder' => $availableToOrder, 'eta' => $eta, 'availabilityState' => $availabilityState] = $availability_info;
                $current_stock_status = get_post_meta($product_id, '_stock_status', true);
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

                if(!($current_stock_status === $query_stock_status && $currnet_eta === $eta)){
                    if($query_stock_status) wc_update_product_stock_status( $product_id, $query_stock_status );
                    if($eta) update_post_meta($product_id, '_eta_deadline', $eta);
                }
            }
        }catch(Exception $e){
            $this->log('Availability update error for product ID '.$product_id.' : '.$e->getMessage());
        }
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
        
        ['title' => $title, 'description' => $description, 'prices' => ['basePricePerUnit' => ['amount'=> $price]], 'manufacturers' => $manufacturers, 'categories' => $categories, 'themes' => $themes, 'media' => $media, 'preorderDeadline' => $preorderDeadline, 'dimensions' => $dimensions, 'types' => $types, 'barcodes' => $barcodes] = $p;

        $categories = $this->insert_product_tax($categories, 'product_cat');

        $product = new WC_Product_Simple();
        $product->set_sku($sku);
        $product->set_name($title);
        $product->set_description($description);
        $product->set_status('publish');
        $product->set_category_ids( $categories );

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

        $this->product_stock_and_price_update(['product_id'=> $product_id, 'price' => $price]);

        $brands = $this->insert_product_tax($manufacturers, 'product_brand');
        wp_set_object_terms( $product_id, $brands, 'product_brand' );

        $series = $this->insert_product_tax($themes, 'series');
        wp_set_object_terms( $product_id, $series, 'series' );

        $pr_types = $this->insert_product_tax($types, 'type');
        wp_set_object_terms( $product_id, $pr_types, 'type' );
        
        $this->upload_image($product_id, $product, $media);

        $this->product_price_calculator($product->get_id());

        $this->log('↳ Created product #'.$product_id.' for SKU '.$sku);

        return $product_id;
    }
}