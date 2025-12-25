<?php 

trait HEO_WC_handle_redundant_code{
    public function custom_dynamic_price_depricated($price, $product_obj) {
        // if (is_admin()) return $price; // keep backend unchanged
        $is_price_update = false;

        $product_id = $product_obj->get_id();
        $product = wc_get_product( $product_id );
        $brands = wp_get_post_terms($product_id, 'product_brand');

        $regular_price = (float) $product->get_regular_price();
        $sale_price = (float) $product->get_sale_price();
		$price = (float) $price;

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
}