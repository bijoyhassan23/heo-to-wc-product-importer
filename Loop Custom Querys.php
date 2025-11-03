<?php
// Instock Prodcuts
add_action( 'elementor/query/in_stock_product', function( $query ) {
    if(function_exists('loop_filters')) loop_filters($query);

    $meta_query = $query->get( 'meta_query' );
    if (!$meta_query) $meta_query = [];
    $meta_query[] = [
        'key'     => '_stock_status',
        'value'   => 'instock',
        'compare' => '='
    ];

    $query->set( 'meta_query', $meta_query );
});

// Pre order products
add_action( 'elementor/query/preorder_product', function( $query ) {
    if(function_exists('loop_filters')) loop_filters($query);
	
    $meta_query = $query->get( 'meta_query' );
    if (!$meta_query) $meta_query = [];
    $meta_query[] = [
        'key'     => '_stock_status',
        'value'   => 'preorder',
        'compare' => '='
    ];
    $query->set( 'meta_query', $meta_query );
});

add_action( 'elementor/query/archive_products', function( $query ) {
    if (function_exists('loop_filters')) loop_filters( $query );

    if(is_shop()) return;

    global $wp_query;
    if (is_archive()) {
        $query_vars = $wp_query->query_vars;
        if (isset($query_vars['taxonomy']) && isset($query_vars['term'])) {
            $tax_query = $query->get('tax_query') ?: [];
            $tax_query[] = [
                'taxonomy' => $query_vars['taxonomy'],
                'field'    => 'slug',
                'terms'    => $query_vars['term'],
                'operator' => 'IN',
            ];
            $query->set('tax_query', $tax_query);
        }
    }
});

add_action( 'elementor/query/related_series_products', function( $query ) {
    if ( ! is_product() ) {
        return;
    }

    global $wp_query;
    $product_id = $wp_query->post->ID;

    $terms = wp_get_post_terms( $product_id, 'series', [ 'fields' => 'ids' ] );

    if ( empty( $terms ) || is_wp_error( $terms ) ) {
        return;
    }
	
	$meta_query = $query->get( 'meta_query' );
    if (!$meta_query) $meta_query = [];
	$meta_query[] =  [
		'taxonomy' => 'series',
		'field'    => 'term_id',
		'terms'    => $terms,
	];
    $query->set( 'meta_query', $meta_query );
    $query->set( 'post__not_in', [ $product_id ] );
});