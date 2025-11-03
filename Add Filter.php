<?php
function loop_filters(&$query) {
    // --- META QUERY ---
    $meta_query = $query->get('meta_query') ?: [];

    // Stock Status Filter
    $stock_status = isset($_GET['stock_status']) ? sanitize_text_field($_GET['stock_status']) : '';
    if (!empty($stock_status)) {
        $statuses = array_map('trim', explode(',', $stock_status));
        $meta_query[] = [
            'key'     => '_stock_status',
            'value'   => $statuses,
            'compare' => 'IN'
        ];
    }

    // Price Range Filter
    $price = isset($_GET['price']) ? sanitize_text_field($_GET['price']) : '';
    if (!empty($price) && strpos($price, 'to') !== false) {
        list($min_price, $max_price) = explode('to', $price);

        $min_price = floatval(trim($min_price));
        $max_price = floatval(trim($max_price));

        if ($min_price >= 0 && $max_price > 0) {
            $meta_query[] = [
                'key'     => '_price',
                'value'   => [$min_price, $max_price],
                'type'    => 'NUMERIC',
                'compare' => 'BETWEEN'
            ];
        }
    }

    if (!empty($meta_query)) {
        $query->set('meta_query', $meta_query);
    }

    // --- TAX QUERY ---
    $tax_query = $query->get('tax_query') ?: [];

    // Product Category Filter
    $product_cat = isset($_GET['product_cat']) ? sanitize_text_field($_GET['product_cat']) : '';
    if (!empty($product_cat)) {
        $tax_query[] = [
            'taxonomy' => 'product_cat',
            'field'    => 'slug',
            'terms'    => explode(',', $product_cat),
            'operator' => 'IN',
        ];
    }

    // Product Brand Filter
    $product_brand = isset($_GET['product_brand']) ? sanitize_text_field($_GET['product_brand']) : '';
    if (!empty($product_brand)) {
        $tax_query[] = [
            'taxonomy' => 'product_brand',
            'field'    => 'slug',
            'terms'    => explode(',', $product_brand),
            'operator' => 'IN',
        ];
    }

    // Series Filter
    $series = isset($_GET['series']) ? sanitize_text_field($_GET['series']) : '';
    if (!empty($series)) {
        $tax_query[] = [
            'taxonomy' => 'series',
            'field'    => 'slug',
            'terms'    => explode(',', $series),
            'operator' => 'IN',
        ];
    }

    // Type Filter
    $type = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : '';
    if (!empty($type)) {
        $tax_query[] = [
            'taxonomy' => 'type',
            'field'    => 'slug',
            'terms'    => explode(',', $type),
            'operator' => 'IN',
        ];
    }

    // Apply taxonomy filters
    if (!empty($tax_query)) {
        $tax_query['relation'] = 'AND';
        $query->set('tax_query', $tax_query);
    }

    // --- ORDERING ---
    $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : '';
    switch ($orderby) {
        case 'atoz':
            $query->set('orderby', 'title');
            $query->set('order', 'ASC');
            break;

        case 'ztoa':
            $query->set('orderby', 'title');
            $query->set('order', 'DESC');
            break;

        case 'lowtohigh':
            $query->set('meta_key', '_price');
            $query->set('orderby', 'meta_value_num');
            $query->set('order', 'ASC');
            break;

        case 'hightolow':
            $query->set('meta_key', '_price');
            $query->set('orderby', 'meta_value_num');
            $query->set('order', 'DESC');
            break;

        default:
            // keep WooCommerce or Elementor default order
            break;
    }

    // --- PRODUCTS PER PAGE ---
    $per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 0;
    if ($per_page > 0) {
        $query->set('posts_per_page', $per_page);
    }
}