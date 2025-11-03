<?php
add_shortcode('product_filters', function() {
    ob_start();

    global $wpdb;
    // Get distinct stock statuses dynamically
    $stock_statuses = $wpdb->get_col("
        SELECT DISTINCT meta_value 
        FROM {$wpdb->postmeta} 
        WHERE meta_key = '_stock_status' 
        AND meta_value != ''
    ");
    ?>

    <div class="filter_con">

        <!-- ORDERBY -->
        <div class="each_filter orderby_filter">
            <div class="filter_header">
                <label>Order By</label>
                <span class="toggle-icon">+</span>
            </div>
            <div class="clapse_able_part">
                <?php
                $orderby_options = [
                    'atoz' => 'A to Z',
                    'ztoa' => 'Z to A',
                    'lowtohigh' => 'Low to High',
                    'hightolow' => 'High to Low',
                ];
                foreach ($orderby_options as $val => $label) {
                    echo '<label><input type="radio" class="filter-radio" name="orderby" value="' . esc_attr($val) . '"> ' . esc_html($label) . '</label>';
                }
                ?>
            </div>
        </div>

        <!-- PER PAGE -->
        <div class="each_filter per_page_filter">
            <div class="filter_header">
                <label>Products Per Page</label>
                <span class="toggle-icon">+</span>
            </div>
            <div class="clapse_able_part">
                <?php
                $per_page_options = [18, 60, 120, 240];
                foreach ($per_page_options as $num) {
                    echo '<label><input type="radio" class="filter-radio" name="per_page" value="' . esc_attr($num) . '"> ' . esc_html($num) . '</label>';
                }
                ?>
            </div>
        </div>

        <!-- STOCK STATUS -->
        <div class="each_filter stock_status_filter">
            <div class="filter_header">
                <label>Stock</label>
                <span class="toggle-icon">+</span>
            </div>
            <div class="clapse_able_part" translate="no">
                <?php
                if ($stock_statuses) {
                    foreach ($stock_statuses as $status) {
                        echo '<label><input type="checkbox" class="filter-input" name="stock_status" value="' . esc_attr($status) . '"> ' . esc_html(ucwords(str_replace(['_', '-'], ' ', $status))) . '</label>';
                    }
                } else {
                    echo '<em>No stock statuses found.</em>';
                }
                ?>
            </div>
        </div>

        <!-- PRICE RANGE -->
        <div class="each_filter price_range_filter">
            <div class="filter_header">
                <label>Price</label>
                <span class="toggle-icon">+</span>
            </div>
            <div class="clapse_able_part" translate="no">
				<div class="price_inputs">
					<input type="number" id="min_price" placeholder="Min"> -
					<input type="number" id="max_price" placeholder="Max">
				</div>
                <button class="apply-price">Apply</button>
            </div>
        </div>

        <!-- CATEGORY -->
        <div class="each_filter category_filter">
            <div class="filter_header">
                <label>Category</label>
                <span class="toggle-icon">+</span>
            </div>
            <div class="clapse_able_part" translate="no">
                <?php
                $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
                foreach ($categories as $cat) {
                    echo '<label><input type="checkbox" class="filter-input" name="product_cat" value="' . esc_attr($cat->slug) . '"> ' . esc_html($cat->name) . '</label>';
                }
                ?>
            </div>
        </div>

        <!-- BRAND -->
        <div class="each_filter brand_filter">
            <div class="filter_header">
                <label>Manufacturers</label>
                <span class="toggle-icon">+</span>
            </div>
            <div class="clapse_able_part" translate="no">
                <?php
                $brands = get_terms(['taxonomy' => 'product_brand', 'hide_empty' => false]);
                foreach ($brands as $brand) {
                    echo '<label><input type="checkbox" class="filter-input" name="product_brand" value="' . esc_attr($brand->slug) . '"> ' . esc_html($brand->name) . '</label>';
                }
                ?>
            </div>
        </div>

        <!-- SERIES -->
        <div class="each_filter series_filter">
            <div class="filter_header">
                <label>Series</label>
                <span class="toggle-icon">+</span>
            </div>
            <div class="clapse_able_part" translate="no">
                <?php
                $series = get_terms(['taxonomy' => 'series', 'hide_empty' => false]);
                foreach ($series as $s) {
                    echo '<label><input type="checkbox" class="filter-input" name="series" value="' . esc_attr($s->slug) . '"> ' . esc_html($s->name) . '</label>';
                }
                ?>
            </div>
        </div>

        <!-- TYPE -->
        <div class="each_filter type_filter">
            <div class="filter_header">
                <label>Type</label>
                <span class="toggle-icon">+</span>
            </div>
            <div class="clapse_able_part" translate="no">
                <?php
                $types = get_terms(['taxonomy' => 'type', 'hide_empty' => false]);
                foreach ($types as $t) {
                    echo '<label><input type="checkbox" class="filter-input" name="type" value="' . esc_attr($t->slug) . '"> ' . esc_html($t->name) . '</label>';
                }
                ?>
            </div>
        </div>

        <!-- RESET BUTTON -->
        <button class="reset-filters">Reset Filters</button>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const baseUrl = window.location.origin + window.location.pathname;
        const params = new URLSearchParams(window.location.search);

        // ✅ Restore saved filters
        params.forEach((value, key) => {
            if (key === 'price') {
                const [min, max] = value.split('to');
                if (min) document.getElementById('min_price').value = min;
                if (max) document.getElementById('max_price').value = max;
            } else {
                const values = value.split(',');
                document.querySelectorAll(`[name="${key}"]`).forEach(el => {
                    if (values.includes(el.value)) el.checked = true;
                });
            }
        });

        function updateURL() {
            const newParams = new URLSearchParams(location.search);

            document.querySelectorAll('.filter-input').forEach(input => {
                const name = input.name;
                const selected = [...document.querySelectorAll(`input[name="${name}"]:checked`)].map(i => i.value);
                if (selected.length) newParams.set(name, selected.join(','));
            });

            document.querySelectorAll('.filter-radio:checked').forEach(input => {
                newParams.set(input.name, input.value);
            });

            const min = document.getElementById('min_price').value;
            const max = document.getElementById('max_price').value;
            if (min && max) newParams.set('price', `${min}to${max}`);

            const qs = newParams.toString();
            window.location.href = qs ? `${baseUrl}?${qs}` : baseUrl;
        }

        // Filter auto apply
        document.querySelectorAll('.filter-input, .filter-radio').forEach(el => el.addEventListener('change', updateURL));
        document.querySelector('.apply-price')?.addEventListener('click', updateURL);
        document.querySelector('.reset-filters')?.addEventListener('click', () => window.location.href = baseUrl);

        // 🔽 Collapsible sections (closed by default)
        document.querySelectorAll('.filter_header').forEach(header => {
            const icon = header.querySelector('.toggle-icon');
            const part = header.nextElementSibling;

            part.classList.remove('open');
            icon.textContent = '+';

            header.addEventListener('click', () => {
                part.classList.toggle('open');
                icon.textContent = part.classList.contains('open') ? '−' : '+';
            });
        });
    });
    </script>
    <?php
    return ob_get_clean();
});
