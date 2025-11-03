<?php
// All Product count
add_shortcode('all_count', function() {
    $args = [
        'limit'       => -1,
        'return'      => 'ids',
    ];

    $products = wc_get_products($args);
    $total = count($products);

    return 'Total Products <span class="total_product">' . $total . '</span>';
});

// Pre order Product count
add_shortcode('preorder_count', function() {
    $args = [
        'limit'       => -1,
        'return'      => 'ids',
        'meta_key'    => '_stock_status',
        'meta_value'  => 'preorder',
    ];

    $products = wc_get_products($args);
    $total = count($products);

    return 'Total Products <span class="total_product">' . $total . '</span>';
});

// Instock Product count
add_shortcode('instock_count', function() {
    $args = [
        'limit'       => -1,
        'return'      => 'ids',
        'meta_key'    => '_stock_status',
        'meta_value'  => 'instock',
    ];

    $products = wc_get_products($args);
    $total = count($products);

    return 'Total Products <span class="total_product">' . $total . '</span>';
});