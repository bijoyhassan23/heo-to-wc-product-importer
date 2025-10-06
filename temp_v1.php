<?php

if ( ! defined('ABSPATH') ) exit;

class HEO_WC_Importer {
    const OPT = 'heo_wc_importer_settings';
    const CRON_HOOK = 'heo_wc_importer_cron_sync';
    const PAGE_SLUG = 'heo-wc-importer';
    const LOG_TRANSIENT = 'heo_wc_import_log';

    const BATCH = 50;
    const LOOKUP_CHUNK = 50;

    // Action Scheduler
    const AS_GROUP   = 'heo_wc_importer_queue';
    const AS_SPACING = 5;    // seconds between single-product jobs
    const MAX_ARG_BYTES = 80000; // if job args exceed this, schedule by SKU instead

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_page']);
        add_action('admin_init', [$this, 'register_settings']);

        add_action('admin_post_heo_test_connection', [$this, 'handle_test_connection']);
        add_action('admin_post_heo_run_sync',       [$this, 'handle_manual_sync']);
        add_action('admin_post_heo_log_sample',     [$this, 'handle_log_sample']);
        add_action('admin_post_heo_clear_log',      [$this, 'handle_clear_log']);

        add_action(self::CRON_HOOK,                 [$this, 'seed_async_queue']);

        // Action Scheduler (positional single arg)
        add_action('heo_wc_seed_page',              [$this, 'seed_page_job'], 10, 1);
        add_action('heo_wc_import_single',          [$this, 'process_single_job'], 10, 1);

        register_activation_hook(__FILE__,          [$this, 'activate']);
        register_deactivation_hook(__FILE__,        [$this, 'deactivate']);

        if (defined('WP_CLI') && WP_CLI) WP_CLI::add_command('heo sync', [$this, 'cli_sync']);
    }

    /* ---------------- Admin UI ---------------- */

    public function add_admin_page() {
        add_submenu_page('woocommerce','heo Importer','heo Importer','manage_woocommerce', self::PAGE_SLUG, [$this,'render_page']);
    }

    public function register_settings() {
        register_setting(self::OPT, self::OPT, [$this, 'sanitize_settings']);
    }

    public function sanitize_settings($opts) {
        $defaults = [
            'environment'     => 'sandbox',
            'username'        => '',
            'pass_prod'       => '',
            'pass_sbx'        => '',
            'schedule'        => 'hourly',
            'paging_mode'     => 'pageNumber',
            'force_instock'   => 1,
            'image_base'      => '', 
            'force_https'     => 1,
        ];
        $opts = wp_parse_args($opts, $defaults);

        $opts['environment']     = in_array($opts['environment'], ['sandbox','production'], true) ? $opts['environment'] : 'sandbox';
        $opts['username']        = $this->trim_cred($opts['username'] ?? '');
        $opts['pass_prod']       = $this->trim_cred($opts['pass_prod'] ?? '');
        $opts['pass_sbx']        = $this->trim_cred($opts['pass_sbx'] ?? '');
        $opts['schedule']        = in_array($opts['schedule'], ['hourly','twicedaily','daily'], true) ? $opts['schedule'] : 'hourly';
        $opts['paging_mode']     = in_array($opts['paging_mode'], ['pageNumber','page','limit'], true) ? $opts['paging_mode'] : 'pageNumber';
        $opts['force_instock']   = !empty($opts['force_instock']) ? 1 : 0;
        $opts['image_base']      = esc_url_raw(trim((string)($opts['image_base'] ?? '')));
        $opts['force_https']     = !empty($opts['force_https']) ? 1 : 0;

        $this->maybe_reschedule($opts['schedule']);
        return $opts;
    }

    private function trim_cred($s) { 
        $s = (string)$s; 
        $s = preg_replace("/[\r\n\t]+/", '', $s); 
        return trim($s); 
    }
    private function maybe_reschedule($schedule) { 
        wp_clear_scheduled_hook(self::CRON_HOOK); 
        wp_schedule_event(time()+60, $schedule, self::CRON_HOOK); 
    }

    public function render_page() {
        if ( ! current_user_can('manage_woocommerce') ) return;
        $o = get_option(self::OPT, []);
        $env      = $o['environment'] ?? 'sandbox';
        $username = $o['username'] ?? '';
        $pass_prod= $o['pass_prod'] ?? '';
        $pass_sbx = $o['pass_sbx'] ?? '';
        $schedule = $o['schedule'] ?? 'hourly';
        $paging   = $o['paging_mode'] ?? 'pageNumber';
        $force_in = !empty($o['force_instock']);
        $img_base = $o['image_base'] ?? '';
        $force_https = !empty($o['force_https']);
        $defult_price_multiplier = $o['defult_price_multiplier'] ?? '';
        $log = get_transient(self::LOG_TRANSIENT);
        ?>
        <div class="wrap">
            <h1>heo → Woo Importer</h1>
            <form method="post" action="options.php">
                <?php settings_fields(self::OPT); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row"><label>Environment</label></th><td><select name="<?php echo esc_attr(self::OPT); ?>[environment]"><option value="sandbox" <?php selected($env,'sandbox'); ?>>Sandbox (Test)</option><option value="production" <?php selected($env,'production'); ?>>Production</option></select></td></tr>
                    <tr><th scope="row"><label>Username</label></th><td><input type="text" name="<?php echo esc_attr(self::OPT); ?>[username]" value="<?php echo esc_attr($username); ?>" class="regular-text" required></td></tr>
                    <tr><th scope="row"><label>Sandbox Password</label></th><td><input type="password" name="<?php echo esc_attr(self::OPT); ?>[pass_sbx]" value="<?php echo esc_attr($pass_sbx); ?>" class="regular-text" autocomplete="new-password"></td></tr>
                    <tr><th scope="row"><label>Production Password</label></th><td><input type="password" name="<?php echo esc_attr(self::OPT); ?>[pass_prod]" value="<?php echo esc_attr($pass_prod); ?>" class="regular-text" autocomplete="new-password"></td></tr>
                    <tr><th scope="row"><label>Auto-Sync Schedule</label></th><td><select name="<?php echo esc_attr(self::OPT); ?>[schedule]"><option value="hourly" <?php selected($schedule,'hourly'); ?>>Hourly</option><option value="twicedaily" <?php selected($schedule,'twicedaily'); ?>>Twice daily</option><option value="daily" <?php selected($schedule,'daily'); ?>>Daily</option></select></td></tr>
                    <tr><th scope="row"><label>Paging Mode</label></th><td><select name="<?php echo esc_attr(self::OPT); ?>[paging_mode]"><option value="pageNumber" <?php selected($paging,'pageNumber'); ?>>pageNumber / pageSize</option><option value="page" <?php selected($paging,'page'); ?>>page / size</option><option value="limit" <?php selected($paging,'limit'); ?>>limit / offset</option></select></td></tr>
                    <tr><th scope="row"><label>Force In-Stock if quantity unknown</label></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPT); ?>[force_instock]" value="1" <?php checked($force_in, true); ?>> Yes</label></td></tr>
                    <tr><th scope="row"><label>Image Base URL (for relative paths)</label></th><td><input type="text" name="<?php echo esc_attr(self::OPT); ?>[image_base]" value="<?php echo esc_attr($img_base); ?>" class="regular-text" placeholder="https://integrate.heo.com"></td></tr>
                    <tr><th scope="row"><label>Force HTTPS for images</label></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPT); ?>[force_https]" value="1" <?php checked($force_https, true); ?>> Yes</label></td></tr>
                    <!-- Price Multiplier -->
                    <tr>
                        <th scope="row"><label>Default Price Multiplier</label></th>
                        <td>
                            <input type="hidden" name="<?php echo esc_attr(self::OPT); ?>[defult_price_multiplier]" value="<?php echo esc_attr($defult_price_multiplier); ?>">
                            <p class="description" style="margin-top:0">
                                Define discount tiers for this brand using price ranges and a multiply factor (e.g., 1.95). Leave <em>End</em> empty for open-ended range.
                            </p>
                            <style>
                                #ft-price-range-table th { text-align: left; }
                                #ft-price-range-table th, #ft-price-range-table td { padding: 8px 6px; }
                                #ft-price-range-table th { background: #f1f1f1; }
                                #ft-price-range-table tr:nth-child(even) { background: #fafafa; }
                                #ft-price-range-table tr:hover { background: #ffffe0; }
                            </style>
                            <table class="widefat striped" id="ft-price-range-table" style="max-width:960px;margin-top:8px">
                                <thead>
                                    <tr>
                                        <th style="width:28%">Start price</th>
                                        <th style="width:28%">End price</th>
                                        <th style="width:28%">Multiply</th>
                                        <th style="width:16%"></th>
                                    </tr>
                                </thead>
                                <tbody>

                                </tbody>
                            </table>

                            <p style="margin-top:8px">
                                <button type="button" class="button button-secondary" id="ft-row-add">+ Add range</button>
                            </p>

                            <style>
                                #ft-price-range-table input.regular-text { width: 100%; }
                                #ft-price-range-table td, #ft-price-range-table th { vertical-align: middle; }
                                #ft-price-range-table .ft-pr-row.removed { opacity: 0.5; }
                            </style>

                            <script>
                                (function(){
                                    const table = document.getElementById('ft-price-range-table');
                                    const tbody = table.querySelector('tbody');
                                    const addBtn = document.getElementById('ft-row-add');
                                    const defultPriceMultiplierIn = document.querySelector('[name="heo_wc_importer_settings[defult_price_multiplier]"]');

                                    function makeRow(values){
                                        const tr = document.createElement('tr');
                                        tr.className = 'ft-pr-row';
                                        tr.innerHTML = `
                                            <td><input type="number" step="0.01" min="0" placeholder="0.00"
                                                    name="ft_price_rows[start][]" value="${values?.start ?? ''}" class="regular-text"></td>
                                            <td><input type="number" step="0.01" min="0" placeholder="e.g. 9.99 (or leave empty)"
                                                    name="ft_price_rows[end][]" value="${values?.end ?? ''}" class="regular-text"></td>
                                            <td><input type="number" step="0.01" min="0" placeholder="e.g. 1.95"
                                                    name="ft_price_rows[multiplier][]" value="${values?.multiplier ?? ''}" class="regular-text"></td>
                                            <td><button type="button" class="button ft-row-remove" aria-label="Remove row">Remove</button></td>
                                        `;

                                        tr.querySelector('[name="ft_price_rows[start][]"]').addEventListener('input', defaultMult);
                                        tr.querySelector('[name="ft_price_rows[end][]"]').addEventListener('input', defaultMult);
                                        tr.querySelector('[name="ft_price_rows[multiplier][]"]').addEventListener('input', defaultMult);
                                        return tr;
                                    }

                                    addBtn.addEventListener('click', function(){
                                        tbody.appendChild(makeRow());
                                    });

                                    function defaultMult(){
                                        const defaultMultData = [];
                                        table.querySelectorAll('.ft-pr-row').forEach(function (item) {
                                            let eachMult = {
                                                start: item.querySelector('[name="ft_price_rows[start][]"]').value,
                                                end: item.querySelector('[name="ft_price_rows[end][]"]').value,
                                                multiplier: item.querySelector('[name="ft_price_rows[multiplier][]"]').value,
                                            };
                                            defaultMultData.push(eachMult);
                                        });
                                        defultPriceMultiplierIn.value = JSON.stringify(defaultMultData);
                                    }

                                    tbody.addEventListener('click', function(e){
                                        if (e.target && e.target.classList.contains('ft-row-remove')) {
                                            const rows = tbody.querySelectorAll('.ft-pr-row');
                                            if (rows.length > 1) {
                                                e.target.closest('.ft-pr-row').remove();
                                            } else {
                                                // If only 1 row left, just clear its inputs instead of removing
                                                const inputs = e.target.closest('.ft-pr-row').querySelectorAll('input');
                                                inputs.forEach(i => i.value = '');
                                            }
                                            defaultMult();
                                        }
                                    });

                                    (() => {
                                        let initialData = JSON.parse(defultPriceMultiplierIn.value || '[]');
                                        initialData.forEach(item => {
                                            tbody.appendChild(makeRow(item));
                                        });
                                    })()
                                })();
                            </script>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save Settings'); ?>
            </form>

            <hr>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:10px">
                <?php wp_nonce_field('heo_test_connection'); ?>
                <input type="hidden" name="action" value="heo_test_connection">
                <?php submit_button('Test Connection', 'secondary', 'submit', false); ?>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:10px">
                <?php wp_nonce_field('heo_run_sync'); ?>
                <input type="hidden" name="action" value="heo_run_sync">
                <?php submit_button('Run Sync Now (Queue One-by-One)', 'primary', 'submit', false); ?>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:10px">
                <?php wp_nonce_field('heo_log_sample'); ?>
                <input type="hidden" name="action" value="heo_log_sample">
                <?php submit_button('Debug: Log Sample Product', 'secondary', 'submit', false); ?>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block">
                <?php wp_nonce_field('heo_clear_log'); ?>
                <input type="hidden" name="action" value="heo_clear_log">
                <?php submit_button('Clear Log', 'secondary', 'submit', false, ['onclick'=>"return confirm('Clear the importer log?');"]); ?>
            </form>

            <?php if ($log): ?>
                <h2 style="margin-top:24px">Last Log</h2>
                <pre style="max-height:420px;overflow:auto;background:#111;color:#0f0;padding:12px;"><?php echo esc_html($log); ?></pre>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ---------------- Lifecycle ---------------- */

    public function activate() { if ( ! wp_next_scheduled(self::CRON_HOOK) ) wp_schedule_event(time()+60, 'hourly', self::CRON_HOOK); }
    public function deactivate() { wp_clear_scheduled_hook(self::CRON_HOOK); }

    /* ---------------- Actions ---------------- */

    public function handle_test_connection() {
        if ( ! current_user_can('manage_woocommerce') ) wp_die('Unauthorized');
        check_admin_referer('heo_test_connection');
        $this->install_crash_guard();
        ob_start();
        $authOK = $this->probe_me();
        $ok = $this->ping();
        if ($authOK || $ok) $this->log('✅ heo API reachable (auth seems OK).');
        else $this->log('❌ Connection/auth failed. Check username/password & environment. See 401/403 hints in earlier log lines.');
        ob_end_clean();
        wp_safe_redirect(add_query_arg(['page'=>self::PAGE_SLUG], admin_url('admin.php'))); exit;
    }

    public function handle_manual_sync() {
        if ( ! current_user_can('manage_woocommerce') ) wp_die('Unauthorized');
        check_admin_referer('heo_run_sync');
        $this->install_crash_guard();
        ob_start();
        $this->seed_async_queue();
        ob_end_clean();
        wp_safe_redirect(add_query_arg(['page'=>self::PAGE_SLUG], admin_url('admin.php'))); exit;
    }

    public function handle_log_sample() {
        if ( ! current_user_can('manage_woocommerce') ) wp_die('Unauthorized');
        check_admin_referer('heo_log_sample');
        $this->install_crash_guard();
        ob_start();
        $sampleRaw = $this->fetch_products_page_raw(0, 1);
        $content = (is_array($sampleRaw) && isset($sampleRaw['content']) && is_array($sampleRaw['content'])) ? $sampleRaw['content'] : [];
        if (!empty($content[0]) && is_array($content[0])) {
            $p = $content[0];
            $sku   = $this->to_string($p['productNumber'] ?? '');
            $title = $this->extract_title($p);
            $price = $this->extract_price_from_product($p);
            $stock = $this->extract_stock_from_product($p);
            $brand = $this->extract_brand($p);
            $why = [];
            $urls  = $this->collect_image_urls($p, $why);
            $cats  = $this->extract_categories($p);
            $this->log('🔎 Sample product keys: '.implode(', ', array_slice(array_keys($p), 0, 25)));
            $this->log('🔎 Detected → SKU: '.$sku.' | title: '.substr($title,0,60).' | brand: '.$brand.' | raw price: '.var_export($price,true).' | stock: '.var_export($stock,true).' | images: '.count($urls).' | cats: '.implode(' | ', array_slice($cats,0,5)));
            if (!empty($why)) $this->log('🔎 Image hints: '.implode(' ; ', array_slice($why, 0, 6)));
        } else {
            $this->log('🔎 Sample fetch returned empty.');
        }
        ob_end_clean();
        wp_safe_redirect(add_query_arg(['page'=>self::PAGE_SLUG], admin_url('admin.php'))); exit;
    }

    public function handle_clear_log() {
        if ( ! current_user_can('manage_woocommerce') ) wp_die('Unauthorized');
        check_admin_referer('heo_clear_log');
        delete_transient(self::LOG_TRANSIENT);
        wp_safe_redirect(add_query_arg(['page'=>self::PAGE_SLUG], admin_url('admin.php'))); exit;
    }

    /* ---------------- Crash guard ---------------- */

    private function install_crash_guard() {
        ignore_user_abort(true);
        if (function_exists('wp_raise_memory_limit')) wp_raise_memory_limit('admin');
        @set_time_limit(600);
        set_error_handler(function($errno, $errstr, $errfile, $errline){
            $this->log('PHP '.$errno.' at '.$errfile.':'.$errline.' → '.$errstr);
            return true;
        });
        register_shutdown_function(function(){
            $e = error_get_last();
            if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                $this->log('FATAL '.$e['type'].' at '.$e['file'].':'.$e['line'].' → '.$e['message']);
            }
        });
    }

    /* ---------------- Bulk sync (CLI fallback) ---------------- */

    public function cli_sync() { $this->install_crash_guard(); $this->sync_all(true); }

    public function sync_all($cli = false) {
        if ( ! class_exists('WooCommerce') ) { $this->log('❌ WooCommerce not active.'); return; }
        $this->log('🚀 Sync started (direct)');
        $page = 0; $total = 0; $empty_hits = 0;
        do {
            $pageRaw = $this->fetch_products_page_raw($page, self::BATCH);
            $products = (isset($pageRaw['content']) && is_array($pageRaw['content'])) ? $pageRaw['content'] : [];
            if (!is_array($products)) { $this->log('… products not an array on page '.$page); break; }
            $count = count($products);
            if ($count === 0) { $this->log('… fetched page '.$page.' (0 items)'); $empty_hits++; if ($empty_hits > 1) break; $page++; continue; }
            $this->log('… fetched page '.$page.' ('.$count.' items)'); $empty_hits = 0;

            $skus = [];
            foreach ($products as $p) {
                if (!is_array($p)) continue;
                $sku = $this->to_string($p['productNumber'] ?? '');
                if ($sku !== '') $skus[] = $sku;
            }
            $priceMap = $this->fetch_prices_map_chunked($skus);
            $stockMap = $this->fetch_availability_map_chunked($skus);

            $cPrices=0; $cStocks=0; $cImgs=0; $cCats=0;
            foreach ($products as $p) {
                if (!is_array($p)) continue;
                $sku = $this->to_string($p['productNumber'] ?? '');
                if ($sku === '') continue;

                $price = $priceMap[$sku] ?? $this->extract_price_from_product($p);
                $stock = $stockMap[$sku] ?? $this->extract_stock_from_product($p);
                if ($price !== null && $price !== '') $cPrices++;
                if ($stock !== null && $stock !== '') $cStocks++;

                try {
                    // HYDRATE if needed before upsert
                    $pHydrated = $this->hydrate_product_if_needed($p, ['manufacturers']);
                    $pid = $this->upsert_product($pHydrated, $price, $stock, $gotImg, $gotCats);
                    if ($pid) $total++;
                    if (!empty($gotImg))  $cImgs++;
                    if (!empty($gotCats)) $cCats++;
                } catch (\Throwable $t) {
                    $this->log('upsert_product error for SKU '.$sku.': '.$t->getMessage());
                }
            }
            $this->log('… page '.$page.' summary → prices: '.$cPrices.', stocks: '.$cStocks.', images set: '.$cImgs.', categories set: '.$cCats);

            $totalPages = isset($pageRaw['pagination']['totalPages']) ? (int)$pageRaw['pagination']['totalPages'] : null;
            if ($totalPages !== null) {
                if ($page >= ($totalPages - 1)) break;
            }

            $page++; usleep(200000);
        } while (true);

        $this->log('✅ Sync finished. Imported/updated: '.$total);
    }

    private function upsert_product(array $p, $price, $stock, &$gotImage=false, &$gotCats=false) {
        $sku = $this->to_string($p['productNumber'] ?? ''); if ($sku === '') return 0;

        $title = $this->extract_title($p);
        $desc  = $this->extract_description($p);

        if ( ! class_exists('WC_Product_Simple') ) {
            $this->log('❌ WooCommerce product classes missing.');
            return 0;
        }
        $this->log('this is new p:-'.json_encode($p));
        $existing_id = wc_get_product_id_by_sku($sku);
        $product_id  = $existing_id ? $existing_id : 0;
        if (!$existing_id) {
            $product = new WC_Product_Simple();
            $product->set_sku($sku);
            $product->set_name($title !== '' ? $title : $sku);
            $product->set_status('publish');
            $product_id = $product->save();
            $this->log('↳ Created product #'.$product_id.' for SKU '.$sku);
        } else {
            $product = wc_get_product($product_id);
            if ( ! $product ) { $this->log('⚠️ wc_get_product returned null for #'.$product_id); return 0; }
            $product->set_name($title !== '' ? $title : $sku);
        }

        if ($desc !== '') wp_update_post(['ID'=> $product_id, 'post_content'=> $desc]);

        if ($price !== null && $price !== '') {
            $final_price = (float)$price;
            $product->set_regular_price(wc_format_decimal($final_price));
            $product->set_price(wc_format_decimal($final_price));
        }

        if (is_numeric($stock)) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity((int)$stock);
            $product->set_stock_status(((int)$stock) > 0 ? 'instock' : 'outofstock');
        } else {
            $product->set_manage_stock(false);
            $product->set_stock_status('instock');
        }

        $product->set_backorders('no');
        $product->save();

        $gotImage = $this->import_images($product_id, $p);
        $gotCats  = $this->map_terms($product_id, $p);

        return $product_id;
    }

    /* ---------- Extractors ---------- */

    private function extract_title(array $p) : string {
        $keys = ['name','title','productName'];
        foreach ($keys as $k) {
            if (!array_key_exists($k, $p)) continue;
            $v = $p[$k];
            if (is_array($v)) {
                $candidate = $this->pick_localized($v, ['EN','en']);
                if ($candidate !== '') return $candidate;
                $flat = $this->flatten_strings($v);
                if ($flat !== '') return $flat;
            } else {
                $s = $this->to_string($v);
                if ($s !== '') return $s;
            }
        }
        return '';
    }

    private function extract_description(array $p) : string {
        if (!empty($p['description']) && is_array($p['description'])) {
            $candidate = $this->pick_localized($p['description'], ['EN','en','DE','de']);
            if ($candidate !== '') return wp_kses_post($candidate);
            $flat = $this->flatten_strings($p['description']);
            if ($flat !== '') return wp_kses_post($flat);
        }
        $fallback = $this->first_string(
            $p['longDescription'] ?? null,
            $p['descriptionText'] ?? null,
            $p['shortDescription'] ?? null
        );
        return wp_kses_post($fallback);
    }

    private function extract_price_from_product(array $p) {
        if (isset($p['prices']['basePricePerUnit']['amount'])) return (float)$p['prices']['basePricePerUnit']['amount'];
        if (isset($p['prices']['basePrice']['amount'])) return (float)$p['prices']['basePrice']['amount'];
        if (isset($p['price'])) return (float)$p['price'];
        if (isset($p['retailPrice'])) return (float)$p['retailPrice'];
        return null;
    }

    private function truthy($v) {
        if (is_bool($v)) return $v;
        if (is_numeric($v)) return ((int)$v) > 0;
        if (is_string($v)) { $s = strtolower(trim($v)); return in_array($s, ['1','true','yes','y','available','instock','in_stock'], true); }
        return false;
    }

    private function extract_stock_from_product(array $p) {
        if (isset($p['availability'])) {
            if (is_numeric($p['availability'])) return (int)$p['availability'];
            if (is_array($p['availability'])) {
                if (isset($p['availability']['availableQuantity'])) return (int)$p['availability']['availableQuantity'];
                if (isset($p['availability']['quantity'])) return (int)$p['availability']['quantity'];
            }
        }
        foreach (['availableQuantity','stock','qty','quantity','onHand','inventory'] as $k) { if (isset($p[$k]) && is_numeric($p[$k])) return (int)$p[$k]; }
        foreach (['inStock','instock','available','isAvailable'] as $k) { if (isset($p[$k]) && $this->truthy($p[$k])) return 1; }
        foreach (['stockStatus','availabilityStatus','availabilityState','status'] as $k) {
            if (!empty($p[$k]) && is_string($p[$k])) {
                $s = strtolower($p[$k]);
                if (strpos($s,'in') !== false && strpos($s,'stock') !== false) return 1;
                if (in_array($s, ['available','onhand','on_hand','ok'], true)) return 1;
                if (in_array($s, ['out','outofstock','out_of_stock','unavailable'], true)) return 0;
            }
        }
        return null;
    }

    /* ---------------- Images & Taxonomy ---------------- */

    private function import_images($product_id, array $p) : bool {
        $this->log('B:- Image Import called for product '. $product_id);
        $why = []; $urls = $this->collect_image_urls($p, $why);
        if (!$urls) {
            if (!empty($why)) $this->log('🖼️ no image URLs: '.implode(' ; ', array_slice($why,0,6)));
            if (!empty($p['media'])) {
                $snippet = substr(print_r(array_slice((array)$p['media'], 0, 10, true), true), 0, 500);
                $this->log('🖼️ media subtree sample: '.$snippet);
            }
            return false;
        }
        if ( ! function_exists('media_handle_sideload') ) {
            require_once ABSPATH.'wp-admin/includes/media.php';
            require_once ABSPATH.'wp-admin/includes/file.php';
            require_once ABSPATH.'wp-admin/includes/image.php';
        }
        $setThumb = false; $gallery = [];
        foreach ($urls as $url) {
            $att_id = $this->sideload_image_to_media($url, $product_id);
            if ($att_id) {
                if (!$setThumb) { set_post_thumbnail($product_id, $att_id); $setThumb = true; }
                else { $gallery[] = $att_id; }
            }
        }
        if ($gallery) update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery));
        return $setThumb || !empty($gallery);
    }

    private function normalize_image_url($raw) {
        $o = get_option(self::OPT, []);
        $base  = trim((string)($o['image_base'] ?? ''));
        $force = !empty($o['force_https']);
        $u = trim((string)$raw);
        if ($u === '') return '';
        if (strpos($u, '//') === 0) $u = 'https:'.$u;
        if (parse_url($u, PHP_URL_SCHEME) === null) { if ($base !== '') { $u = rtrim($base, '/').'/'.ltrim($u, '/'); } }
        if ($force && stripos($u, 'http://') === 0) { $u = 'https://'.substr($u, 7); }
        return $u;
    }

    private function collect_image_urls(array $p, &$why = []) : array {
        $why = []; $urls = [];
        $append_from_node = function($node) use (&$urls) {
            if (is_string($node)) { $u = $this->normalize_image_url($node); if (filter_var($u, FILTER_VALIDATE_URL)) $urls[] = $u; }
            elseif (is_array($node)) { foreach (['url','imageUrl','href','link','downloadUrl','src'] as $k) { if (!empty($node[$k]) && is_string($node[$k])) { $u = $this->normalize_image_url($node[$k]); if (filter_var($u, FILTER_VALIDATE_URL)) { $urls[] = $u; break; } } } }
        };
        $scan_recursive = function($node, $depth = 0) use (&$scan_recursive, &$append_from_node) {
            if ($depth > 4) return;
            if (is_array($node)) {
                foreach ($node as $k => $v) {
                    if (is_string($v) && preg_match('~\.(jpg|jpeg|png|webp|gif)(\?.*)?$~i', $v)) $append_from_node($v);
                    elseif (is_array($v)) { if (is_string($k) && preg_match('~(image|img|thumb|media|url)~i', $k)) $append_from_node($v); $scan_recursive($v, $depth + 1); }
                    else { if (is_string($v) && (strpos($v, 'http://') === 0 || strpos($v, 'https://') === 0)) $append_from_node($v); }
                }
            } else { $append_from_node($node); }
        };
        foreach (['images','media','assets','mediaGallery','pictures','gallery'] as $k) { if (!empty($p[$k])) $scan_recursive($p[$k], 0); }
        foreach (['imageUrl','image','thumbnailUrl','primaryImage','mainImage'] as $k) { if (!empty($p[$k]) && is_string($p[$k])) $scan_recursive($p[$k], 0); }
        $urls = array_values(array_unique(array_filter($urls)));
        if (!$urls) { if (!empty($p['images'])) $why[]='images present but contained no absolute/normalized URLs'; if (!empty($p['media'])) $why[]='media present but recursive scan found no URL-like fields (set Image Base URL if relative)'; }
        return $urls;
    }

    /* ---------------- Brand & Categories ---------------- */

    private function map_terms($product_id, array $p) : bool {
        $set = false;

        // BRAND (manufacturers translations preferred, EN first)
        $brand = $this->extract_brand($p);
        if ($brand !== '') {
            $used_tax = $this->assign_brand($product_id, $brand);
            if ($used_tax !== '') {
                $this->log('🏷️ Brand "'.$brand.'" assigned via taxonomy '.$used_tax.' for product #'.$product_id);
                $set = true;
            } else {
                $this->log('⚠️ Brand "'.$brand.'" could not be assigned (no suitable taxonomy).');
            }
        } else {
            $this->log('ℹ️ No brand found in payload (manufacturers/brand).');
        }

        // CATEGORIES
        $cats = $this->extract_categories($p);
        if ($cats) {
            $term_ids = [];
            foreach ($cats as $name) {
                $term = term_exists($name, 'product_cat');
                if (!$term) $term = wp_insert_term($name, 'product_cat');
                if (!is_wp_error($term) && isset($term['term_id'])) $term_ids[] = (int)$term['term_id'];
            }
            if ($term_ids) { wp_set_post_terms($product_id, $term_ids, 'product_cat', false); $set = true; }
        }

        return $set;
    }

    private function extract_brand(array $p) : string {
        if (!empty($p['brand'])) {
            $s = $this->to_string($p['brand']);
            if ($s !== '') return $s;
        }
        if (!empty($p['manufacturers']) && is_array($p['manufacturers'])) {
            foreach ($p['manufacturers'] as $m) {
                if (!is_array($m)) continue;
                if (!empty($m['translations'])) {
                    $en = $this->pick_localized($m['translations'], ['EN','en']);
                    if ($en !== '') return $en;
                }
                foreach (['name','displayName','label','title'] as $k) {
                    if (!empty($m[$k])) {
                        $s = $this->to_string($m[$k]);
                        if ($s !== '') return $s;
                    }
                }
            }
        }
        return '';
    }

    private function choose_brand_taxonomy() : array {
        if (taxonomy_exists('brand'))          return ['brand', 'custom'];
        if (taxonomy_exists('product_brand'))  return ['product_brand', 'plugin'];
        if (taxonomy_exists('pa_brand'))       return ['pa_brand', 'attribute'];
        register_taxonomy('brand', 'product', [
            'label'        => 'Brand',
            'hierarchical' => false,
            'show_ui'      => true,
            'show_in_rest' => true,
        ]);
        return ['brand', 'created'];
    }

    private function assign_brand(int $product_id, string $brand) : string {
        $brand = $this->to_string($brand);
        if ($brand === '') return '';
        list($taxonomy,) = $this->choose_brand_taxonomy();

        $term = term_exists($brand, $taxonomy);
        if (!$term) $term = wp_insert_term($brand, $taxonomy);
        if (is_wp_error($term) || empty($term['term_id'])) {
            $msg = is_wp_error($term) ? $term->get_error_message() : 'unknown';
            $this->log('❌ Brand term insert failed for "'.$brand.'" in '.$taxonomy.' → '.$msg);
            return '';
        }

        wp_set_post_terms($product_id, [(int)$term['term_id']], $taxonomy, false);

        if (strpos($taxonomy, 'pa_') === 0) {
            $attrs = (array) get_post_meta($product_id, '_product_attributes', true);
            $attrs[$taxonomy] = [
                'name'         => $taxonomy,
                'value'        => '',
                'position'     => 0,
                'is_visible'   => 1,
                'is_variation' => 0,
                'is_taxonomy'  => 1,
            ];
            update_post_meta($product_id, '_product_attributes', $attrs);
        }

        return $taxonomy;
    }

    private function extract_categories(array $p) : array {
        $cats = [];
        if (!empty($p['categories']) && is_array($p['categories'])) {
            foreach ($p['categories'] as $c) {
                if (is_string($c)) $cats[] = $this->clean_category_label($c);
                elseif (is_array($c)) {
                    foreach (['displayName','name','label','title'] as $k) { if (!empty($c[$k])) { $cats[] = $this->clean_category_label($this->to_string($c[$k])); break; } }
                    if (!empty($c['translations'])) {
                        $t = $c['translations'];
                        $chosen = is_array($t) ? $this->pick_localized($t, ['EN','en','DE','de']) : '';
                        if ($chosen !== '') $cats[] = $this->clean_category_label($chosen);
                    }
                    if (!empty($c['path']) && is_string($c['path'])) {
                        $parts = array_map('trim', explode('>', $c['path']));
                        $last = end($parts); if ($last) $cats[] = $this->clean_category_label($last);
                    }
                }
            }
        }
        foreach (['category','categoryName'] as $k) {
            if (!empty($p[$k])) $cats[] = $this->clean_category_label($this->to_string($p[$k]));
        }
        $cats = array_values(array_filter(array_map('trim', $cats)));
        $fixed = [];
        foreach ($cats as $label) {
            $devide_arr = explode("&", $label);
            foreach ($devide_arr as $each_cat){
                $each_cat = trim($each_cat);
                if($each_cat) $fixed[] = $each_cat;
            }
        }
        return array_values(array_unique($fixed));
    }

    private function clean_category_label($s) : string {
        $s = $this->to_string($s); if ($s === '') return '';
        $s = preg_replace('/^[A-Za-z0-9]{5,12}\s+/', '', $s);
        $s = preg_replace('/^(DE|EN|FR|ES|IT|NL)\s+:/i', '', $s);
        $s = preg_replace('/^(DE|EN|FR|ES|IT|NL)\s+/i', '', $s);
        $s = preg_replace('/\s{2,}/', ' ', $s);
        return trim($s);
    }

    private function sideload_image_to_media($url, $post_id) {
        $existing = $this->find_attachment_by_source($url); if ($existing) return $existing;
        $tmp = download_url($url, 45); if (is_wp_error($tmp)) { $this->log('img download failed: '.$tmp->get_error_message().' | '.$url); return 0; }
        $path = parse_url($url, PHP_URL_PATH); $name = $path ? wp_basename($path) : 'image.jpg';
        $file_array = ['name' => $name, 'tmp_name' => $tmp];
        $att_id = media_handle_sideload($file_array, $post_id);
        if (is_wp_error($att_id)) { @unlink($tmp); $this->log('img sideload failed: '.$att_id->get_error_message().' | '.$url); return 0; }
        update_post_meta($att_id, '_source_url', esc_url_raw($url));
        return $att_id;
    }

    private function find_attachment_by_source($url) {
        $q = new WP_Query(['post_type'=>'attachment','meta_key'=>'_source_url','meta_value'=>esc_url_raw($url),'fields'=>'ids','posts_per_page'=>'1','no_found_rows'=>true]);
        return $q->have_posts() ? (int)$q->posts[0] : 0;
    }

    /* ---------------- AUTH/HTTP (403 hardening) ---------------- */

    private function base_candidates() {
        $o = get_option(self::OPT, []); 
        $env = $o['environment'] ?? 'sandbox';
        if ($env === 'production') {
            return [
                'https://integrate.heo.com/retailer-api/v1',
                'https://integrate.heo.com/retailer-api',
            ];
        }
        return [
            'https://integrate.heo.com/retailer-api-test/v1',
            'https://integrate.heo.com/retailer-api-test',
        ];
    }

    private function get_auth() {
        $o = get_option(self::OPT, []);
        $env = $o['environment'] ?? 'sandbox';
        $user = $this->trim_cred($o['username'] ?? '');
        $pass = $this->trim_cred($env === 'production' ? ($o['pass_prod'] ?? '') : ($o['pass_sbx'] ?? ''));
        return [$user,$pass,$env];
    }

    private function headers() {
        return ['Accept'=>'application/json','Content-Type'=>'application/json','User-Agent'=>'heo-wooimporter/1.4.1 (+WordPress)'];
    }

    private function request_with_retries($method, $url, $args = [], $max_tries = 5) {
        $tries = 0; $delay = 0.5; $last = null;
        do {
            $tries++;
            $args2 = $args + ['timeout'=>60, 'redirection'=>0, 'sslverify'=>true, 'httpversion'=>'1.1', 'method'=>$method];
            $res = wp_remote_request($url, $args2);
            $last = $res;
            if (is_wp_error($res)) {
                $this->log('HTTP '.$method.' error: '.$res->get_error_message().' ['.$url.']');
            } else {
                $code = (int) wp_remote_retrieve_response_code($res);
                if ($code >= 200 && $code < 300) return $res;

                if ($code === 401 || $code === 403) {
                    $this->log('AUTH HINT: HTTP '.$code.' on '.$url.' — verify environment + username + password (no spaces).');
                    return $res;
                }
                if ($code == 429 || $code == 503) {
                    $retry_after = wp_remote_retrieve_header($res, 'retry-after');
                    $wait = $retry_after !== '' ? (int)$retry_after : $delay;
                    $this->log('HTTP '.$code.' — retrying in '.$wait.'s (try '.$tries.'/'.$max_tries.') on '.$url);
                    sleep($wait); $delay = min($delay * 2, 8);
                } else {
                    $this->log('HTTP '.$code.' body: '.substr((string)wp_remote_retrieve_body($res),0,250).' ['.$url.']');
                    return $res;
                }
            }
        } while ($tries < $max_tries);
        return $last;
    }

    private function api_get($path) {
        list($user,$pass,$env) = $this->get_auth();
        if ($user === '' || $pass === '') { $this->log('No credentials for API GET ('.$env.').'); return null; }
        $auth = 'Basic '.base64_encode($user.':'.$pass);
        $bases = $this->base_candidates();

        $alt_path = null;
        if (strpos($path, '/catalog/products') === 0) $alt_path = str_replace('/catalog/products', '/products', $path);
        elseif (strpos($path, '/products') === 0)     $alt_path = str_replace('/products', '/catalog/products', $path);

        $paths_to_try = [$path];
        if ($alt_path && $alt_path !== $path) $paths_to_try[] = $alt_path;

        foreach ($bases as $base) {
            foreach ($paths_to_try as $p) {
                $url = rtrim($base,'/') . $p;
                $args = ['headers' => $this->headers() + ['Authorization' => $auth, 'Accept-Language'=>'en']];
                $res = $this->request_with_retries('GET', $url, $args);
                if (is_wp_error($res)) continue;
                $code = (int) wp_remote_retrieve_response_code($res);
                $raw  = (string) wp_remote_retrieve_body($res);
                if ($code >= 200 && $code < 300) {
                    $json = json_decode($raw, true);
                    return (json_last_error() === JSON_ERROR_NONE) ? $json : $raw;
                }
            }
        }
        $this->log('GET '.$path.' failed on all bases (env='.$env.').');
        return null;
    }

    /* ---------------- Robust ping ---------------- */

    private function ping() {
        $seq = [
            '/catalog/products?pageNumber=0&pageSize=1',
            '/catalog/products?page=0&size=1',
            '/catalog/products?limit=1&offset=0',
            '/catalog/products',
            '/products?pageNumber=0&size=1',
            '/products?page=0&size=1',
            '/products?limit=1&offset=0',
            '/products',
        ];
        foreach ($seq as $p) { $r = $this->api_get($p); if (!empty($r)) { $this->log('Ping OK: '.$p); return true; } }
        return false;
    }

    private function probe_me() {
        $r = $this->api_get('/me');
        if (!empty($r)) { 
            $this->log('🔐 Auth probe OK on /me'); 
            return true; 
        }
        $this->log('🔐 Auth probe failed on /me (403 likely permissions or wrong password).');
        return false;
    }

    /* ---------------- Data fetchers ---------------- */

    private function fetch_products_page_raw($page, $size) {
        $o = get_option(self::OPT, []); $mode = $o['paging_mode'] ?? 'pageNumber';

        $paths = [];
        if ($mode === 'pageNumber') $paths[] = '/catalog/products?pageNumber=' . intval($page) . '&pageSize=' . intval($size);
        elseif ($mode === 'page')   $paths[] = '/catalog/products?page=' . intval($page) . '&size=' . intval($size);
        else { $offset = intval($page) * intval($size); $paths[] = '/catalog/products?limit=' . intval($size) . '&offset=' . $offset; }

        $offset = intval($page) * intval($size);
        $paths = array_unique(array_merge($paths, [
            '/catalog/products?pageNumber=' . intval($page) . '&size=' . intval($size),
            '/catalog/products?page=' . intval($page) . '&size=' . intval($size),
            '/catalog/products?limit=' . intval($size) . '&offset=' . $offset,
            '/catalog/products',
            '/products?pageNumber=' . intval($page) . '&size=' . intval($size),
            '/products?page=' . intval($page) . '&size=' . $size,
            '/products?limit=' . intval($size) . '&offset=' . $offset,
            '/products',
        ]));

        foreach ($paths as $p) {
            $resp = $this->api_get($p);
            if (is_array($resp) && (!empty($resp['content']) || isset($resp['pagination']))) return $resp;
            if (is_array($resp) && !empty($resp)) return ['content' => $resp, 'pagination' => []];
        }
        return ['content'=>[], 'pagination'=>[]];
    }

    private function fetch_products_page($page, $size) {
        $raw = $this->fetch_products_page_raw($page, $size);
        return (isset($raw['content']) && is_array($raw['content'])) ? $raw['content'] : [];
    }

    private function fetch_product_by_sku($sku) {
        $sku = trim((string)$sku);
        if ($sku === '') return null;

        $paths = [
            '/catalog/products?productNumbers=' . rawurlencode($sku),
            '/products?productNumbers=' . rawurlencode($sku),
        ];
        foreach ($paths as $p) {
            $resp = $this->api_get($p);
            if (isset($resp['content']) && is_array($resp['content']) && !empty($resp['content'][0])) return $resp['content'][0];
            if (is_array($resp) && !empty($resp[0])) return $resp[0];
        }
        $directs = [
            '/catalog/products/' . rawurlencode($sku),
            '/products/' . rawurlencode($sku),
        ];
        foreach ($directs as $p) {
            $resp = $this->api_get($p);
            if (is_array($resp) && !empty($resp)) return $resp;
        }
        return null;
    }

    private function fetch_prices_map_chunked(array $skus) {
        if (!$skus) return [];
        $map = [];
        $chunks = array_chunk($skus, self::LOOKUP_CHUNK);
        foreach ($chunks as $chunk) { $m = $this->fetch_prices_map($chunk); if ($m) $map += $m; usleep(100000); }
        return $map;
    }

    private function fetch_prices_map(array $skus) {
        $map = [];
        $qs = http_build_query(['productNumbers' => implode(',', $skus)]);
        $resp = $this->api_get('/catalog/prices?' . $qs);
        if (!is_array($resp)) {
            $repeat = [];
            foreach ($skus as $s) $repeat[] = 'productNumber=' . rawurlencode($s);
            $resp = $this->api_get('/catalog/prices?' . implode('&', $repeat));
        }
        if (is_array($resp)) {
            $items = (isset($resp['content']) && is_array($resp['content'])) ? $resp['content'] : $resp;
            foreach ($items as $row) {
                if (!is_array($row)) continue;
                $sku = $this->to_string($row['productNumber'] ?? '');
                if ($sku === '') continue;
                $price = null;
                if (isset($row['prices']['basePricePerUnit']['amount'])) $price = (float)$row['prices']['basePricePerUnit']['amount'];
                elseif (isset($row['price'])) $price = (float)$row['price'];
                if ($price !== null) $map[$sku] = $price;
            }
        }
        return $map;
    }

    private function fetch_availability_map_chunked(array $skus) {
        if (!$skus) return [];
        $map = [];
        $chunks = array_chunk($skus, self::LOOKUP_CHUNK);
        foreach ($chunks as $chunk) { $m = $this->fetch_availability_map($chunk); if ($m) $map += $m; usleep(100000); }
        return $map;
    }

    private function fetch_availability_map(array $skus) {
        $map = [];
        $qs = http_build_query(['productNumbers' => implode(',', $skus)]);
        $resp = $this->api_get('/catalog/availabilities?' . $qs);
        if (!is_array($resp)) {
            $repeat = [];
            foreach ($skus as $s) $repeat[] = 'productNumber=' . rawurlencode($s);
            $resp = $this->api_get('/catalog/availabilities?' . implode('&', $repeat));
        }
        if (is_array($resp)) {
            $items = (isset($resp['content']) && is_array($resp['content'])) ? $resp['content'] : $resp;
            foreach ($items as $row) {
                if (!is_array($row)) continue;
                $sku = $this->to_string($row['productNumber'] ?? '');
                if ($sku === '') continue;
                $qty = null;
                if (isset($row['availability'])) $qty = is_array($row['availability']) ? (int)($row['availability']['availableQuantity'] ?? 0) : (int)$row['availability'];
                if (isset($row['availableQuantity'])) $qty = (int)$row['availableQuantity'];
                if ($qty !== null) $map[$sku] = $qty;
            }
        }
        return $map;
    }

    /* ---------------- String/array utils ---------------- */

    private function to_string($v) : string { 
        if (is_string($v)) return trim($v);
        if (is_numeric($v)) return (string)$v;
        if (is_array($v)) { 
            if (isset($v['text']) && is_string($v['text'])) return trim($v['text']);
            if (isset($v['translation']) && is_string($v['translation'])) return trim($v['translation']);
            return $this->flatten_strings($v);
        } 
        return ''; 
    }

    private function first_string() : string { 
        $args = func_get_args(); 
        foreach ($args as $v) { 
            $s = $this->to_string($v); 
            if ($s !== '') return $s; 
        } 
        return ''; 
    }

    private function flatten_strings($arr) : string { 
        if (!is_array($arr)) return $this->to_string($arr); 
        $out = []; 
        $walker = function($node) use (&$out, &$walker) { 
            if (is_string($node)) { 
                $t = trim($node); 
                if ($t !== '') $out[] = $t; 
            } elseif (is_numeric($node)) { 
                $out[] = (string)$node; 
            } elseif (is_array($node)) { 
                foreach ($node as $v) $walker($v); 
            } 
        }; 
        $walker($arr); 
        return $out ? implode(' ', array_slice($out, 0, 3)) : ''; 
    }

    private function pick_localized(array $nodes, array $langs) : string {
        if ($this->is_assoc($nodes)) {
            foreach ($langs as $lg){
                foreach ($nodes as $k=>$v){
                    if (strtolower($k) === strtolower($lg)) { 
                        $s = $this->to_string($v); 
                        if ($s !== '') return $s; 
                    }            
                }
            }
        }
        foreach ($nodes as $node) {
            if (!is_array($node)) continue;
            foreach ($langs as $lg) {
                $hasLang = (isset($node['langIso2']) && strtolower($node['langIso2']) === strtolower($lg)) || (isset($node['lang']) && strtolower($node['lang']) === strtolower($lg));
                if ($hasLang) {
                    $s = $this->to_string(isset($node['translation']) ? $node['translation'] : (isset($node['text']) ? $node['text'] : ''));
                    if ($s !== '') return $s;
                }
            }
        }
        return '';
    }
    
    private function is_assoc(array $arr) : bool { 
        return array_keys($arr) !== range(0, count($arr) - 1); 
    }

    private function log($line) { 
        $ts = date_i18n('Y-m-d H:i:s'); 
        $prev = get_transient(self::LOG_TRANSIENT); 
        $prev = $prev ? $prev."\n" : ''; 
        $msg = '['.$ts.'] '.$line; 
        set_transient(self::LOG_TRANSIENT, $prev.$msg, 12 * HOUR_IN_SECONDS); 
        if (defined('WP_CLI') && WP_CLI) WP_CLI::log($line); 
    }

    /* ---------------- Async Queue (Action Scheduler) ---------------- */

    public function seed_async_queue($start_page = 0) {
        if (!function_exists('as_schedule_single_action')) {
            $this->log('⚠️ Action Scheduler not found — running direct sync as fallback.');
            $this->sync_all();
            return;
        }
        as_schedule_single_action(time() + 5, 'heo_wc_seed_page', [ (int)$start_page ], self::AS_GROUP);
        $this->log('🗓️ Queued seeding for page '.(int)$start_page.' (group: '.self::AS_GROUP.', spacing: '.self::AS_SPACING.'s).');
    }

    // Keep only fields we need in job args — INCLUDE manufacturers when present
    private function minify_product_payload(array $p) : array {
        $this->log('before minified: '.json_encode($p));
        $keepKeys = [
            'productNumber','name','title','productName','description','longDescription','descriptionText','shortDescription',
            'prices','price','retailPrice',
            'availability','availableQuantity','stock','qty','quantity','onHand','inventory','inStock','instock','available','isAvailable',
            'stockStatus','availabilityStatus','availabilityState','status',
            'images','image','imageUrl','thumbnailUrl','primaryImage','mainImage','media','assets','mediaGallery','pictures','gallery',
            'categories','category','categoryName','brand',
            'manufacturers', 'preorderDeadline' // may be absent in list endpoints; worker hydrates if missing
        ];
        $min = [];
        foreach ($keepKeys as $k) if (array_key_exists($k, $p)) $min[$k] = $p[$k];
        $this->log('keycaps in payload: '.json_encode($min));
        return $min;
    }

    public function seed_page_job($page /* positional arg */) {
        $page = max(0, (int)$page);
        $this->log('📄 Seeding import jobs from API page '.$page.' (batch '.self::BATCH.').');

        $raw = $this->fetch_products_page_raw($page, self::BATCH);
        $products = (isset($raw['content']) && is_array($raw['content'])) ? $raw['content'] : [];
        $count = count($products);

        if ($count === 0) { $this->log('… page '.$page.' empty; stopping seeding.'); return; }

        $ts = time(); 
        $i  = 0; 
        $scheduledBySKU = 0;
        foreach ($products as $p) {
            if (!is_array($p)) continue;
            $sku = $this->to_string($p['productNumber'] ?? '');
            if ($sku === '') continue;

            $this->log('x---: '.json_encode($p));
            $payload = $this->minify_product_payload($p);
            $jsonLen = strlen(wp_json_encode($payload));
            $run_at = $ts + ($i * self::AS_SPACING);
            if ($jsonLen > self::MAX_ARG_BYTES) { as_schedule_single_action($run_at, 'heo_wc_import_single', [ $sku ], self::AS_GROUP); $scheduledBySKU++; }
            else { as_schedule_single_action($run_at, 'heo_wc_import_single', [ $payload ], self::AS_GROUP); }
            $i++;
        }
        $this->log('➕ Scheduled '.$i.' single-product jobs from page '.$page.'. (bySKU: '.$scheduledBySKU.')');

        $totalPages = isset($raw['pagination']['totalPages']) ? (int)$raw['pagination']['totalPages'] : null;
        if ($totalPages !== null) {
            if ($page < ($totalPages - 1)) {
                as_schedule_single_action($ts + ($i * self::AS_SPACING) + 2, 'heo_wc_seed_page', [ $page + 1 ], self::AS_GROUP);
                $this->log('➡️ Next seed scheduled for page '.($page+1).' of '.$totalPages.'.');
            } else {
                $this->log('🏁 Seeding complete (last page '.$page.' of '.$totalPages.').');
            }
            return;
        }

        if ($count === self::BATCH) {
            as_schedule_single_action($ts + ($i * self::AS_SPACING) + 2, 'heo_wc_seed_page', [ $page + 1 ], self::AS_GROUP);
            $this->log('➡️ Next seed scheduled for page '.($page+1).' (fallback heuristic).');
        } else {
            $this->log('🏁 Seeding complete (no pagination meta; last chunk size='.$count.').');
        }
    }

    public function process_single_job($arg /* positional: payload array OR sku string */) {
        if (is_object($arg)) $arg = (array)$arg;

        $p = null; $sku = '';
        if (is_array($arg)) {
            if (isset($arg['productNumber']) || isset($arg['prices']) || isset($arg['categories']) || isset($arg['manufacturers'])) {
                $p = $arg;
            } elseif (isset($arg['p'])) {
                $p = is_object($arg['p']) ? (array)$arg['p'] : (is_array($arg['p']) ? $arg['p'] : null);
                if (!$p && isset($arg['sku'])) $sku = $this->to_string($arg['sku']);
            } elseif (array_key_exists(0, $arg)) {
                if (is_array($arg[0]) || is_object($arg[0])) $p = is_object($arg[0]) ? (array)$arg[0] : $arg[0];
                elseif (is_string($arg[0])) $sku = $this->to_string($arg[0]);
            } elseif (isset($arg['sku'])) {
                $sku = $this->to_string($arg['sku']);
            }
        } elseif (is_string($arg)) {
            $sku = $this->to_string($arg);
        } else {
            $this->log('❌ heo_wc_import_single unrecognized arg type: '.gettype($arg));
            return;
        }

        if (!$p && $sku === '') { $this->log('❌ heo_wc_import_single missing payload/sku after normalization (arg type: '.gettype($arg).')'); return; }

        if (!$p) {
            $this->log('🔁 Fetching product by SKU inside worker: '.$sku);
            $p = $this->fetch_product_by_sku($sku);
            if (!is_array($p) || empty($p)) { $this->log('❌ Could not fetch product by SKU '.$sku.' from API'); return; }
        }

        $sku   = $this->to_string($p['productNumber'] ?? ($sku ?? ''));

        // >>> HYDRATE if required keys (e.g., manufacturers for Brand) are missing
        $p = $this->hydrate_product_if_needed($p, ['manufacturers']);
        $price = $this->extract_price_from_product($p);
        $stock = $this->extract_stock_from_product($p);
        $gotImage = false; $gotCats = false;
        $id = $this->upsert_product($p, $price, $stock, $gotImage, $gotCats);
        if ($id) $this->log('✅ Imported/updated SKU '.$sku.' → product #'.$id.' (img:'.($gotImage?'y':'n').', cats:'.($gotCats?'y':'n').')');
        else     $this->log('⚠️ Upsert returned 0 for SKU '.$sku);
    }

    /* ---------------- Hydration helper ---------------- */

    // Add missing keys by refetching a full product (by SKU) when the list payload is incomplete.
    private function hydrate_product_if_needed(array $p, array $neededKeys) : array {
        $missing = [];
        foreach ($neededKeys as $k) {
            if (!array_key_exists($k, $p) || (is_array($p[$k]) && count($p[$k]) === 0) || $p[$k] === null || $p[$k] === '') {
                $missing[] = $k;
            }
        }
        if (!$missing) return $p;

        $sku = $this->to_string($p['productNumber'] ?? '');
        if ($sku === '') return $p;

        $this->log('🧩 Hydrating SKU '.$sku.' for missing keys: '.implode(', ', $missing));
        $full = $this->fetch_product_by_sku($sku);
        if (!is_array($full) || empty($full)) { $this->log('⚠️ Hydration fetch failed for SKU '.$sku); return $p; }

        foreach ($missing as $k) {
            if (array_key_exists($k, $full)) $p[$k] = $full[$k];
        }
        return $p;
    }
}

new HEO_WC_Importer();

/* ---------- OPTIONAL: push Woo orders to heo (unchanged, creds trimmed) ---------- */
add_action('woocommerce_thankyou', function($order_id){
    if (!$order_id) return;
    $order = wc_get_order($order_id); if (!$order) return;

    $o = get_option(HEO_WC_Importer::OPT, []);
    $env = isset($o['environment']) ? $o['environment'] : 'sandbox';
    $user = isset($o['username']) ? trim((string)$o['username']) : '';
    $pass = $env === 'production' ? ($o['pass_prod'] ?? '') : ($o['pass_sbx'] ?? '');
    $user = preg_replace("/[\r\n\t]+/", '', $user);
    $pass = preg_replace("/[\r\n\t]+/", '', $pass);
    if (!$user || !$pass) return;

    $candidates = $env === 'production'
        ? ['https://integrate.heo.com/retailer-api/v1/orders','https://integrate.heo.com/retailer-api/orders']
        : ['https://integrate.heo.com/retailer-api-test/v1/orders','https://integrate.heo.com/retailer-api-test/orders'];

    $endpoint = $candidates[0];
    $res = null;
    foreach ($candidates as $ep) {
        $endpoint = $ep;
        $items = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product(); if (!$product) continue;
            $items[] = [
                'productNumber' => $product->get_sku(),
                'quantity'      => (int) $item->get_quantity(),
                'unitPrice'     => (float) $product->get_price(),
            ];
        }

        $payload = [
            'orderNumber' => (string) $order->get_order_number(),
            'currency'    => $order->get_currency(),
            'customer'    => [
                'email' => $order->get_billing_email(),
                'name'  => trim($order->get_billing_first_name().' '.$order->get_billing_last_name()),
                'address' => [
                    'line1' => $order->get_billing_address_1(),
                    'line2' => $order->get_billing_address_2(),
                    'city'  => $order->get_billing_city(),
                    'zip'   => $order->get_billing_postcode(),
                    'countryIso2' => $order->get_billing_country(),
                ],
            ],
            'items' => $items,
        ];

        $res = wp_remote_post($endpoint, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($user . ':' . $pass),
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'timeout' => 60,
            'body'    => wp_json_encode($payload),
        ]);
        if (!is_wp_error($res) && (int)wp_remote_retrieve_response_code($res) >= 200 && (int)wp_remote_retrieve_response_code($res) < 300) break;
    }

    if (is_wp_error($res)) { error_log('[heo-orders] Post failed: '.$res->get_error_message()); return; }
    $code = wp_remote_retrieve_response_code($res);
    if ($code < 200 || $code >= 300) {
        error_log('[heo-orders] HTTP '.$code.' endpoint='.$endpoint.' body: '.wp_remote_retrieve_body($res));
    }
}, 20);


/* ---------- Render on Edit Brand screen ---------- */
add_action('product_brand_edit_form_fields', function($term){
    $meta_key = '_brand_price_range_multipliers';
    $rows = get_term_meta($term->term_id, $meta_key, true);
    if (!is_array($rows)) $rows = [];

    // Ensure at least one empty row for UI
    if (empty($rows)) {
        $rows = [
            ['start' => '', 'end' => '', 'multiplier' => '']
        ];
    }

    // Nonce
    wp_nonce_field('ft_brand_price_ranges', 'ft_brand_price_ranges_nonce');
    ?>
    <tr class="form-field">
        <th scope="row">
            <label><?php esc_html_e('Price Range Multipliers', 'ft'); ?></label>
        </th>
        <td>
            <p class="description" style="margin-top:0">
                Define discount tiers for this brand using price ranges and a multiply factor (e.g., 1.95). Leave <em>End</em> empty for open-ended range.
            </p>
            <style>
                #ft-price-range-table th { text-align: left; }
                #ft-price-range-table th, #ft-price-range-table td { padding: 8px 6px; }
                #ft-price-range-table th { background: #f1f1f1; }
                #ft-price-range-table tr:nth-child(even) { background: #fafafa; }
                #ft-price-range-table tr:hover { background: #ffffe0; }
            </style>
            <table class="widefat striped" id="ft-price-range-table" style="max-width:960px;margin-top:8px">
                <thead>
                    <tr>
                        <th style="width:28%">Start price</th>
                        <th style="width:28%">End price</th>
                        <th style="width:28%">Multiply</th>
                        <th style="width:16%"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $i => $row):
                        $start = isset($row['start']) ? $row['start'] : '';
                        $end   = isset($row['end']) ? $row['end'] : '';
                        $mult  = isset($row['multiplier']) ? $row['multiplier'] : '';
                    ?>
                    <tr class="ft-pr-row">
                        <td>
                            <input type="number" step="0.01" min="0" placeholder="0.00"
                                   name="ft_price_rows[start][]" value="<?php echo esc_attr($start); ?>" class="regular-text">
                        </td>
                        <td>
                            <input type="number" step="0.01" min="0" placeholder="e.g. 9.99 (or leave empty)"
                                   name="ft_price_rows[end][]" value="<?php echo esc_attr($end); ?>" class="regular-text">
                        </td>
                        <td>
                            <input type="number" step="0.01" min="0" placeholder="e.g. 1.95"
                                   name="ft_price_rows[multiplier][]" value="<?php echo esc_attr($mult); ?>" class="regular-text">
                        </td>
                        <td>
                            <button type="button" class="button ft-row-remove" aria-label="Remove row">Remove</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p style="margin-top:8px">
                <button type="button" class="button button-secondary" id="ft-row-add">+ Add range</button>
            </p>

            <style>
                #ft-price-range-table input.regular-text { width: 100%; }
                #ft-price-range-table td, #ft-price-range-table th { vertical-align: middle; }
                #ft-price-range-table .ft-pr-row.removed { opacity: 0.5; }
            </style>

            <script>
            (function(){
                const table = document.getElementById('ft-price-range-table');
                const tbody = table.querySelector('tbody');
                const addBtn = document.getElementById('ft-row-add');

                function makeRow(values){
                    const tr = document.createElement('tr');
                    tr.className = 'ft-pr-row';
                    tr.innerHTML = `
                        <td><input type="number" step="0.01" min="0" placeholder="0.00"
                                   name="ft_price_rows[start][]" value="${values?.start ?? ''}" class="regular-text"></td>
                        <td><input type="number" step="0.01" min="0" placeholder="e.g. 9.99 (or leave empty)"
                                   name="ft_price_rows[end][]" value="${values?.end ?? ''}" class="regular-text"></td>
                        <td><input type="number" step="0.01" min="0" placeholder="e.g. 1.95"
                                   name="ft_price_rows[multiplier][]" value="${values?.multiplier ?? ''}" class="regular-text"></td>
                        <td><button type="button" class="button ft-row-remove" aria-label="Remove row">Remove</button></td>
                    `;
                    return tr;
                }

                addBtn.addEventListener('click', function(){
                    tbody.appendChild(makeRow());
                });

                tbody.addEventListener('click', function(e){
                    if (e.target && e.target.classList.contains('ft-row-remove')) {
                        const rows = tbody.querySelectorAll('.ft-pr-row');
                        if (rows.length > 1) {
                            e.target.closest('.ft-pr-row').remove();
                        } else {
                            // If only 1 row left, just clear its inputs instead of removing
                            const inputs = e.target.closest('.ft-pr-row').querySelectorAll('input');
                            inputs.forEach(i => i.value = '');
                        }
                    }
                });
            })();
            </script>
        </td>
    </tr>
    <?php
}, 20, 1);

/* ---------- Save on Edit ---------- */
add_action('edited_product_brand', function($term_id){
    if (empty($_POST['ft_brand_price_ranges_nonce']) ||
        !wp_verify_nonce($_POST['ft_brand_price_ranges_nonce'], 'ft_brand_price_ranges')) {
        return;
    }

    $meta_key = '_brand_price_range_multipliers';
    $rows_in  = isset($_POST['ft_price_rows']) && is_array($_POST['ft_price_rows']) ? $_POST['ft_price_rows'] : null;

    if (!$rows_in || !isset($rows_in['start'], $rows_in['end'], $rows_in['multiplier'])) {
        delete_term_meta($term_id, $meta_key);
        return;
    }

    $starts = (array) $rows_in['start'];
    $ends   = (array) $rows_in['end'];
    $mults  = (array) $rows_in['multiplier'];

    $clean = [];
    $count = max(count($starts), count($ends), count($mults));

    for ($i = 0; $i < $count; $i++) {
        $s = isset($starts[$i]) ? trim((string)$starts[$i]) : '';
        $e = isset($ends[$i]) ? trim((string)$ends[$i]) : '';
        $m = isset($mults[$i]) ? trim((string)$mults[$i]) : '';

        // Skip completely empty rows
        if ($s === '' && $e === '' && $m === '') continue;

        // Numeric sanitization
        $start = $s === '' ? '' : floatval($s);
        $end   = $e === '' ? '' : floatval($e);
        $mult  = $m === '' ? '' : floatval($m);

        // Basic validation: require start & multiplier; end can be empty (open range)
        if ($start === '' || $mult === '') continue;
        if ($start < 0) $start = 0;
        if ($end !== '' && $end < 0) $end = 0;

        $clean[] = [
            'start'      => $start,
            'end'        => $end,           // '' means open-ended
            'multiplier' => $mult
        ];
    }

    if (!empty($clean)) {
        update_term_meta($term_id, $meta_key, $clean);
    } else {
        delete_term_meta($term_id, $meta_key);
    }
}, 10, 1);

/* ---------- helper to read back later ---------- */
function ft_get_brand_price_ranges( $brand ) {
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


function update_price_from_array($price, $ranges) {
    if (!is_array($ranges)) return $price;

    foreach ($ranges as $range) {
        $start = isset($range['start']) ? floatval($range['start']) : null;
        $end = isset($range['end']) && $range['end'] !== '' ? floatval($range['end']) : null;
        $multiplier = isset($range['multiplier']) ? floatval($range['multiplier']) : null;

        if ($start !== null && $multiplier !== null && $multiplier > 0) {
            if ($end === null) {
                // Open-ended range
                if ($price >= $start) {
                    return round($price * $multiplier, 2);
                }
            } else {
                // Closed range
                if ($price >= $start && $price <= $end) {
                    return round($price * $multiplier, 2);
                }
            }
        }
    }
    return $price;
}

add_filter('woocommerce_product_get_price', 'custom_dynamic_price', 20, 2);
add_filter('woocommerce_product_get_regular_price', 'custom_dynamic_price', 20, 2);
add_filter('woocommerce_product_get_sale_price', 'custom_dynamic_price', 20, 2);

function custom_dynamic_price($price, $product) {
    // if (is_admin()) return $price; // keep backend unchanged

    $is_price_update = false;

    $product_id = $product->get_id();
    $brands = wp_get_post_terms($product_id, 'product_brand');

    if(!empty($brands) && isset($brands[0]->name)){
        $price_renge = ft_get_brand_price_ranges($brands[0]->term_id);
        if(!empty($price_renge)){
            $new_price = update_price_from_array($price, $price_renge);
            if($new_price != $price){
                $is_price_update = true;
                $price = $new_price;
            }
        }
    }

    if(!$is_price_update){
        $o = get_option(HEO_WC_Importer::OPT, []);
        $default_ranges = isset($o['defult_price_multiplier']) ? $o['defult_price_multiplier'] : [];
        if(is_string($default_ranges)) $default_ranges = json_decode($default_ranges, true);
        if(!empty($default_ranges)){
            $new_price = update_price_from_array($price, $default_ranges);
            if($new_price != $price){
                $price = $new_price;
            }
        }
    }
    return $price;
}