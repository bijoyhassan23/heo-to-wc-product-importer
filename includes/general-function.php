<?php

trait General_function{

    private function general_function_init(){
        add_filter( 'manage_edit-product_columns', [$this, 'keep_date_column_last'], 20 );
    }

    private function get_auth() {
        $o = get_option(self::OPT, []);
        $env = $o['environment'] ?? 'sandbox';
        $user = $this->trim_cred($o['username'] ?? '');
        $pass = $this->trim_cred($env === 'production' ? ($o['pass_prod'] ?? '') : ($o['pass_sbx'] ?? ''));
        return [$user,$pass,$env];
    }

    private function trim_cred($s) { 
        $s = (string)$s; 
        $s = preg_replace("/[\r\n\t]+/", '', $s); 
        return trim($s); 
    }

    private function headers() {
        return ['Accept'=>'application/json','Content-Type'=>'application/json','User-Agent'=>'heo-wooimporter/1.4.1 (+WordPress)'];
    }

    private function api_get_info($params = []){
        $defaults = [ 'sku' => false, 'api_type' => 'products', 'page' => 1, 'page_size' => self::BATCH ];
        $params = wp_parse_args( $params, $defaults );
        [ 'sku' => $sku, 'api_type' => $api_type, 'page' => $page, 'page_size' => $page_size ] = $params;

        list($user, $pass, $env) = $this->get_auth();
        if ($user === '' || $pass === '') { $this->log('No credentials for API GET ('.$env.').'); return null; }
        $auth = 'Basic '.base64_encode($user.':'.$pass);
        $url = $env === 'production' ? "https://integrate.heo.com/retailer-api" : "https://integrate.heo.com/retailer-api-test";
        $url .= "/v1/catalog/" . $api_type;
        if($sku){
            $url .= "?query=productNumber=={$sku}";
        }else{
            $url .= "?pageSize={$page_size}&page={$page}";
        }

        $method = 'GET';
        $args = ['headers' => $this->headers() + ['Authorization' => $auth, 'Accept-Language'=>'en']];
        $args = $args + ['timeout'=>60, 'redirection'=>0, 'sslverify'=>true, 'httpversion'=>'1.1', 'method'=>$method];

        $res = wp_remote_request($url, $args);

        if (is_wp_error($res)) {
            $this->log('HTTP '.$method.' error: '.$res->get_error_message().' ['.$url.']');
            return false;
        }
        return json_decode($res['body'], true);
    }

    private function log($line) { 
        $ts = date_i18n('Y-m-d H:i:s'); 
        $prev = get_transient(self::LOG_TRANSIENT); 
        $prev = $prev ? "\n".$prev : ''; 
        $msg = '['.$ts.'] '.$line; 
        set_transient(self::LOG_TRANSIENT, $msg.$prev, 12 * HOUR_IN_SECONDS); 
        if (defined('WP_CLI') && WP_CLI) WP_CLI::log($line); 
    }

    public function handle_clear_log() {
        if ( ! current_user_can('manage_woocommerce') ) wp_die('Unauthorized');
        check_admin_referer('heo_clear_log');
        delete_transient(self::LOG_TRANSIENT);
        wp_safe_redirect(add_query_arg(['page'=>self::PAGE_SLUG], admin_url('admin.php'))); exit;
    }

    public function keep_date_column_last( $columns ) {
        if ( isset( $columns['date'] ) ) {
            $date_column = $columns['date'];
            unset( $columns['date'] ); 
            $columns['date'] = $date_column;
        }
        return $columns;
    }
}



