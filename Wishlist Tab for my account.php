<?php


// 1. Register a new endpoint for "My Projects"
function custom_add_my_account_endpoint() {
    add_rewrite_endpoint( 'wishlist', EP_PAGES );
}
add_action( 'init', 'custom_add_my_account_endpoint' );

// 2. Add a new item to the My Account menu
function custom_add_my_account_link( $items ) {
    $new = [];
    foreach ( $items as $key => $label ) {
        $new[ $key ] = $label;
        if ( 'orders' === $key ) {
            $new['wishlist'] = __( 'Wishlist', 'woocommerce' );
        }
    }
    return $new;
}
add_filter( 'woocommerce_account_menu_items', 'custom_add_my_account_link' );

// 3. Display content for the new tab
function custom_wishlist_content() {
   echo do_shortcode('[yith_wcwl_wishlist]');
}
add_action( 'woocommerce_account_wishlist_endpoint', 'custom_wishlist_content' );