<?php

trait HEO_WC_Admin_part{
    private function admin_init(){
        add_action('admin_menu', [$this, 'add_admin_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_heo_clear_log', [$this, 'handle_clear_log']);
        add_action('admin_post_heo_run_sync', [$this, 'handle_product_manual_sync']);
    }

    public function add_admin_page() {
        add_submenu_page(
            'woocommerce', // Parent slug
            'heo Importer', // Page title
            'heo Importer', // Menu title
            'manage_woocommerce', // Capability
            self::PAGE_SLUG, // Menu slug
            [$this,'render_page'] // Callback function
        );
    }

    public function render_page() {
        if (!current_user_can('manage_woocommerce') ) return;
        $o = get_option(self::OPT, []); // setted Option
        $env      = $o['environment'] ?? 'sandbox';
        $username = $o['username'] ?? '';
        $pass_prod= $o['pass_prod'] ?? '';
        $pass_sbx = $o['pass_sbx'] ?? '';
        $pre_order_discount = $o['pre_order_discount'] ?? '';
        $defult_price_multiplier = $o['defult_price_multiplier'] ?? '';

        $log = get_transient(self::LOG_TRANSIENT);
        ?>
        <div class="wrap">
            <h1>heo → Woo Importer</h1>
            <form method="post" action="options.php">
                <?php settings_fields(self::OPT); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label>Environment</label></th>
                        <td>
                            <select name="<?php echo esc_attr(self::OPT); ?>[environment]">
                                <option value="sandbox" <?php selected($env,'sandbox'); ?>>Sandbox (Test)</option>
                                <option value="production" <?php selected($env,'production'); ?>>Production</option>
                            </select>
                        </td>
                    </tr>
                    <tr><th scope="row"><label>Username</label></th><td><input type="text" name="<?php echo esc_attr(self::OPT); ?>[username]" value="<?php echo esc_attr($username); ?>" class="regular-text" required></td></tr>
                    <tr><th scope="row"><label>Sandbox Password</label></th><td><input type="text" name="<?php echo esc_attr(self::OPT); ?>[pass_sbx]" value="<?php echo esc_attr($pass_sbx); ?>" class="regular-text" autocomplete="new-password"></td></tr>
                    <tr><th scope="row"><label>Production Password</label></th><td><input type="text" name="<?php echo esc_attr(self::OPT); ?>[pass_prod]" value="<?php echo esc_attr($pass_prod); ?>" class="regular-text" autocomplete="new-password"></td></tr>
                    <tr><th scope="row"><label>Preorder Discount (%)</label></th><td><input style='width: 65px' type="number" step="0.01" name="<?php echo esc_attr(self::OPT); ?>[pre_order_discount]" value="<?php echo esc_attr($pre_order_discount); ?>" class="regular-text" ></td></tr>
                    
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
                <?php wp_nonce_field('heo_run_sync'); ?>
                <input type="hidden" name="action" value="heo_run_sync">
                <?php submit_button('Run Sync Now (Queue One-by-One)', 'primary', 'submit', false); ?>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:10px">
                <?php wp_nonce_field('heo_run_regular_sync'); ?>
                <input type="hidden" name="action" value="heo_run_regular_sync">
                <?php submit_button('Sync Info Now', 'primary', 'submit', false); ?>
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

    public function register_settings(){
        register_setting(self::OPT, self::OPT, [$this, 'sanitize_settings']);
    }

    public function sanitize_settings($opts) {
        $defaults = [
            'environment'     => 'sandbox',
            'username'        => '',
            'pass_prod'       => '',
            'pass_sbx'        => '',
            'pre_order_discount'      => '',
            'defult_price_multiplier' => ''
        ];
        $opts = wp_parse_args($opts, $defaults);
        $opts['environment']     = in_array($opts['environment'], ['sandbox','production'], true) ? $opts['environment'] : 'sandbox';
        $opts['username']        = $this->trim_cred($opts['username'] ?? '');
        $opts['pass_prod']       = $this->trim_cred($opts['pass_prod'] ?? '');
        $opts['pass_sbx']        = $this->trim_cred($opts['pass_sbx'] ?? '');
        $opts['pre_order_discount']        = $this->trim_cred($opts['pre_order_discount'] ?? 1);
        return $opts;
    }

    public function handle_product_manual_sync(){
        $this->log('Manual sync triggered by user.');
        $this->seed_page_job(1);
        wp_safe_redirect(add_query_arg(['page'=>self::PAGE_SLUG], admin_url('admin.php'))); 
        exit;
    }
}