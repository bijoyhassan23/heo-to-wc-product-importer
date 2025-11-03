<?php
// [product_discount_percent text='hello' preorder_add='true'] 
function discount_percent($product_id, $preorder_addition = true){
    if(!$product_id) return 0;

    $regular_price = floatval(get_post_meta($product_id, '_regular_price', true));
    $sale_price = floatval(get_post_meta($product_id, '_sale_price', true));

    $discount = 0;
    if($regular_price > 0 && $sale_price > 0 && $regular_price > $sale_price){
        $discount = round((($regular_price - $sale_price) / $regular_price) * 100);
    }

    $preorder_addition = filter_var($preorder_addition, FILTER_VALIDATE_BOOLEAN);
    if($preorder_addition){
        $o = get_option('heo_wc_importer_settings', []);
        $preorderDeadline = get_post_meta( $product_id, '_preorder_deadline', true );
        if($preorderDeadline){
            $deadline = new DateTime($preorderDeadline);
            $now = new DateTime('now');
            if ($deadline > $now){
                $discount += (int)$o['pre_order_discount'];
            }
        }
    }
    return $discount;
}

add_shortcode('product_discount_percent', function($atts){
    $atts = shortcode_atts([ 'id' => false , 'text' => '', 'preorder_add' => 'true'], $atts);
    $product_id = $atts['id'] ? intval($atts['id']) : get_the_ID();

    $discount = discount_percent( $product_id, $atts['preorder_add'] );

    if($discount > 0){
        return "{$atts['text']} {$discount}%";
    }else{
        return '';
    }
});


// Global discounted products array
$discounted_products_ids = [];
$hot_deals_ids = [];
function set_global_discounted_products() {
    if(!is_page( ['anisales', 'hot-deals'] )) return;
    global $discounted_products_ids, $hot_deals_ids;

    // Initialize global array if not set
    if (!isset($discounted_products_ids) || !is_array($discounted_products_ids)) {
        $discounted_products_ids = [];
    }

    // Initialize global array if not set
    if (!isset($hot_deals_ids) || !is_array($hot_deals_ids)) {
        $hot_deals_ids = [];
    }

    // Query all published WooCommerce products (IDs only)
    $query = new WP_Query([
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ]);

    if ($query->have_posts()) {
        foreach ($query->posts as $product_id) {
            if (function_exists('discount_percent')) {
                $discount = discount_percent($product_id, true);
                if ($discount > 0) {
                    $discounted_products_ids[] = $product_id;
                }
                if ($discount >= 10) {
                    $hot_deals_ids[] = $product_id;
                }
            }
        }
    }

    wp_reset_postdata();
    return $discounted_products_ids;
}
add_action( 'wp_head', 'set_global_discounted_products' );

// Ani sale products
add_action('elementor/query/anisale_product', function($query) {
    global $discounted_products_ids;
    if (!empty($discounted_products_ids)) {
        $query->set('post__in', $discounted_products_ids);
    } else {
        // No discounted products found — return nothing
        $query->set('post__in', [0]);
    }
});

// anisale count
add_shortcode('anisale_count', function() {
    global $discounted_products_ids;
    $total = is_array($discounted_products_ids) ? count($discounted_products_ids) : 0;
    return 'Total Products <span class="total_product">' . $total . '</span>';
});

// Hot deals products
add_action('elementor/query/hotedeals_product', function($query) {
    global $hot_deals_ids;
    if (!empty($hot_deals_ids)) {
        $query->set('post__in', $hot_deals_ids);
    } else {
        // No discounted products found — return nothing
        $query->set('post__in', [0]);
    }

});
// hot deals count
add_shortcode('hot_deals_count', function() {
    global $hot_deals_ids;
    $total = is_array($hot_deals_ids) ? count($hot_deals_ids) : 0;
    return 'Total Products <span class="total_product">' . $total . '</span>';
});