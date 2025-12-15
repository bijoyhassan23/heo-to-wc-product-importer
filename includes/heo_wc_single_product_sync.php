<?php

trait HEO_WC_Single_Product_Sync {
    private function single_product_sync_init(){
        add_action('woocommerce_before_single_product', [$this, 'single_product_sync_handler']);
    }

    public function single_product_sync_handler(){
        if(!is_product()) return;
        global $product;
        if (!$product instanceof WC_Product ) return;
        $product_id = $product->get_id();
        $last_sync = (int) get_post_meta($product_id, '_last_update', true);
        $last_sync = $last_sync ? (time() - $last_sync) : 0;
        if($last_sync <= 1800) return; // 30 minutes
        $sku = $product->get_sku();
        $this->product_stock_and_price_update(['product_id' => $product_id, 'sku' => $sku, 'sync' => 'both']);
        $this->log('Single product sync for product ID '.$product_id);
    }
}