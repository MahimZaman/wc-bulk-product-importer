<?php
/*
Plugin Name: Woo Bulk Product API Importer
Plugin URI: https://condixia.com
Author: Mahim Zaman (Condixia)
Author URI: https://mahimzaman.com
Description: Import & update WooCommerce variable products via REST API
Version: 2.0
*/

if (!defined('ABSPATH')) {
    exit;
}

/*
|--------------------------------------------------------------------------
| WooCommerce Check
|--------------------------------------------------------------------------
*/

add_action('plugins_loaded', function () {

    if (!class_exists('WooCommerce')) {
        return;
    }

    add_action('rest_api_init', 'wc_bulk_register_routes');
});

/*
|--------------------------------------------------------------------------
| Register REST Route
|--------------------------------------------------------------------------
*/

function wc_bulk_register_routes()
{

    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    register_rest_route('wc-bulk/v1', '/import', [
        'methods'  => 'POST',
        'callback' => 'wc_bulk_import_products',
        'permission_callback' => function () {
            return current_user_can('manage_woocommerce');
        }
    ]);
}

/*
|--------------------------------------------------------------------------
| Main Import Function
|--------------------------------------------------------------------------
*/

function wc_bulk_import_products(WP_REST_Request $request)
{

    $rows = $request->get_json_params();

    if (!is_array($rows) || empty($rows)) {

        return new WP_Error(
            'invalid_data',
            'Invalid JSON payload',
            ['status' => 400]
        );
    }

    $grouped = [];

    foreach ($rows as $row) {

        if (empty($row['sku'])) {
            continue;
        }

        $sku = sanitize_text_field($row['sku']);

        $grouped[$sku][] = $row;
    }

    $results = [];

    foreach ($grouped as $sku => $items) {

        try {

            $parent_row = $items[0];

            /*
            |--------------------------------------------------------------------------
            | Get Existing Product OR Create New
            |--------------------------------------------------------------------------
            */

            $product_id = wc_get_product_id_by_sku($sku);

            if ($product_id) {

                $product = wc_get_product($product_id);

                if (!$product) {
                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | Convert To Variable If Needed
                |--------------------------------------------------------------------------
                */

                if ($product->get_type() !== 'variable') {

                    wp_set_object_terms(
                        $product_id,
                        'variable',
                        'product_type'
                    );

                    $product = new WC_Product_Variable($product_id);
                }
            } else {

                $product = new WC_Product_Variable();
            }

            /*
            |--------------------------------------------------------------------------
            | Product Basic Data
            |--------------------------------------------------------------------------
            */

            if (!empty($parent_row['product_name'])) {
                $product->set_name(
                    sanitize_text_field($parent_row['product_name'])
                );
            }

            $product->set_sku($sku);

            $product->set_status('publish');

            if (!empty($parent_row['slug'])) {

                $product->set_slug(
                    sanitize_title($parent_row['slug'])
                );
            }

            if (!empty($parent_row['short_description'])) {

                $product->set_short_description(
                    wp_kses_post($parent_row['short_description'])
                );
            }

            if (!empty($parent_row['long_description'])) {

                $product->set_description(
                    wp_kses_post($parent_row['long_description'])
                );
            }

            $product_id = $product->save();

            /*
            |--------------------------------------------------------------------------
            | Meta
            |--------------------------------------------------------------------------
            */

            wc_bulk_add_meta($product_id, $parent_row);

            /*
            |--------------------------------------------------------------------------
            | Categories
            |--------------------------------------------------------------------------
            */

            wc_bulk_set_categories(
                $product_id,
                $parent_row['categories'] ?? ''
            );

            /*
            |--------------------------------------------------------------------------
            | Tags
            |--------------------------------------------------------------------------
            */

            wc_bulk_set_tags(
                $product_id,
                $parent_row['tags'] ?? ''
            );

            /*
            |--------------------------------------------------------------------------
            | Images
            |--------------------------------------------------------------------------
            */

            wc_bulk_set_images(
                $product_id,
                $parent_row['images'] ?? ''
            );

            /*
            |--------------------------------------------------------------------------
            | Attributes
            |--------------------------------------------------------------------------
            */

            $attributes = wc_bulk_collect_attributes($items);

            wc_bulk_assign_attributes(
                $product_id,
                $attributes
            );

            /*
            |--------------------------------------------------------------------------
            | Refresh Product
            |--------------------------------------------------------------------------
            */

            $product = wc_get_product($product_id);

            if ($product) {
                $product->save();
            }

            wc_delete_product_transients($product_id);

            /*
            |--------------------------------------------------------------------------
            | Variations
            |--------------------------------------------------------------------------
            */

            foreach ($items as $row) {

                wc_bulk_create_or_update_variation(
                    $product_id,
                    $row
                );
            }

            $results[] = [
                'sku'        => $sku,
                'product_id' => $product_id,
                'status'     => 'success'
            ];
        } catch (Exception $e) {

            error_log(
                'WC BULK IMPORT ERROR: ' . $e->getMessage()
            );

            $results[] = [
                'sku'    => $sku,
                'status' => 'failed',
                'error'  => $e->getMessage()
            ];
        }
    }

    return [
        'success' => true,
        'results' => $results
    ];
}

/*
|--------------------------------------------------------------------------
| Meta Fields
|--------------------------------------------------------------------------
*/

function wc_bulk_add_meta($product_id, $row)
{

    if (!empty($row['long_description'])) {

        update_post_meta(
            $product_id,
            'long_description',
            wp_kses_post($row['long_description'])
        );
    }

    if (!empty($row['what_included'])) {

        update_post_meta(
            $product_id,
            'whats_included',
            wp_kses_post($row['what_included'])
        );
    }

    if (!empty($row['technical_specs'])) {

        update_post_meta(
            $product_id,
            'technical_specs',
            wp_kses_post($row['technical_specs'])
        );
    }
}

/*
|--------------------------------------------------------------------------
| Categories
|--------------------------------------------------------------------------
*/

function wc_bulk_set_categories($product_id, $cats)
{

    if (empty($cats)) {
        return;
    }

    $names = array_filter(
        array_map('trim', explode(',', $cats))
    );

    $term_ids = [];

    foreach ($names as $name) {

        $term = term_exists($name, 'product_cat');

        if (!$term) {

            $term = wp_insert_term(
                $name,
                'product_cat'
            );
        }

        if (!is_wp_error($term)) {

            $term_ids[] = intval($term['term_id']);
        }
    }

    if (!empty($term_ids)) {

        wp_set_object_terms(
            $product_id,
            $term_ids,
            'product_cat'
        );
    }
}

/*
|--------------------------------------------------------------------------
| Tags
|--------------------------------------------------------------------------
*/

function wc_bulk_set_tags($product_id, $tags)
{

    if (empty($tags)) {
        return;
    }

    $names = array_filter(
        array_map('trim', explode(',', $tags))
    );

    $term_ids = [];

    foreach ($names as $name) {

        $term = term_exists($name, 'product_tag');

        if (!$term) {

            $term = wp_insert_term(
                $name,
                'product_tag'
            );
        }

        if (!is_wp_error($term)) {

            $term_ids[] = intval($term['term_id']);
        }
    }

    if (!empty($term_ids)) {

        wp_set_object_terms(
            $product_id,
            $term_ids,
            'product_tag'
        );
    }
}

/*
|--------------------------------------------------------------------------
| Collect Attributes
|--------------------------------------------------------------------------
*/

function wc_bulk_collect_attributes($rows)
{

    $attributes = [];

    foreach ($rows as $row) {

        foreach ($row as $key => $value) {

            if (strpos($key, 'pa_') !== 0) {
                continue;
            }

            if (empty($value)) {
                continue;
            }

            $attributes[$key][] = sanitize_text_field($value);
        }
    }

    foreach ($attributes as $tax => $values) {

        $attributes[$tax] = array_unique($values);
    }

    return $attributes;
}

/*
|--------------------------------------------------------------------------
| Assign Attributes
|--------------------------------------------------------------------------
*/

function wc_bulk_assign_attributes($product_id, $attributes)
{

    if (empty($attributes)) {
        return;
    }

    $product_attributes = [];

    foreach ($attributes as $taxonomy => $terms) {

        if (!taxonomy_exists($taxonomy)) {
            continue;
        }

        $term_ids = [];

        foreach ($terms as $value) {

            $slug = sanitize_title($value);

            $term = get_term_by(
                'slug',
                $slug,
                $taxonomy
            );

            if (!$term) {

                $term = wp_insert_term(
                    $value,
                    $taxonomy,
                    [
                        'slug' => $slug
                    ]
                );

                if (is_wp_error($term)) {
                    continue;
                }

                $term_id = $term['term_id'];
            } else {

                $term_id = $term->term_id;
            }

            $term_ids[] = $term_id;
        }

        if (empty($term_ids)) {
            continue;
        }

        wp_set_object_terms(
            $product_id,
            $term_ids,
            $taxonomy
        );

        $product_attributes[$taxonomy] = [
            'name'         => $taxonomy,
            'value'        => '',
            'position'     => 0,
            'is_visible'   => 1,
            'is_variation' => 1,
            'is_taxonomy'  => 1
        ];
    }

    if (!empty($product_attributes)) {

        update_post_meta(
            $product_id,
            '_product_attributes',
            $product_attributes
        );
    }
}

/*
|--------------------------------------------------------------------------
| Create OR Update Variation
|--------------------------------------------------------------------------
*/

function wc_bulk_create_or_update_variation($product_id, $row)
{

    $attributes = [];

    foreach ($row as $key => $value) {

        if (
            strpos($key, 'pa_') === 0 &&
            !empty($value)
        ) {

            $taxonomy = $key;

            if (!taxonomy_exists($taxonomy)) {
                continue;
            }

            $attributes['attribute_' . $taxonomy] = sanitize_title($value);
        }
    }

    if (empty($attributes)) {
        return;
    }

    /*
    |--------------------------------------------------------------------------
    | Find Existing Variation
    |--------------------------------------------------------------------------
    */

    $existing_variation_id = wc_bulk_find_variation(
        $product_id,
        $attributes
    );

    if ($existing_variation_id) {

        $variation = new WC_Product_Variation(
            $existing_variation_id
        );
    } else {

        $variation = new WC_Product_Variation();

        $variation->set_parent_id($product_id);
    }

    /*
    |--------------------------------------------------------------------------
    | Variation Data
    |--------------------------------------------------------------------------
    */

    if (!empty($row['price'])) {

        $variation->set_regular_price(
            wc_format_decimal($row['price'])
        );
    }

    if (!empty($row['sale_price'])) {

        $variation->set_sale_price(
            wc_format_decimal($row['sale_price'])
        );
    }

    if (!empty($row['stock'])) {

        $variation->set_manage_stock(true);

        $variation->set_stock_quantity(
            intval($row['stock'])
        );
    }

    if (!empty($row['variation_sku'])) {

        $variation->set_sku(
            sanitize_text_field($row['variation_sku'])
        );
    }

    $variation->set_attributes($attributes);

    try {

        $variation->save();
    } catch (Exception $e) {

        error_log(
            'VARIATION ERROR: ' . $e->getMessage()
        );
    }
}

/*
|--------------------------------------------------------------------------
| Find Existing Variation
|--------------------------------------------------------------------------
*/

function wc_bulk_find_variation(
    $product_id,
    $attributes
) {

    $children = get_posts([
        'post_parent' => $product_id,
        'post_type'   => 'product_variation',
        'numberposts' => -1,
        'post_status' => ['publish', 'private']
    ]);

    foreach ($children as $child) {

        $match = true;

        foreach ($attributes as $key => $value) {

            $saved = get_post_meta(
                $child->ID,
                $key,
                true
            );

            if ($saved !== $value) {

                $match = false;
                break;
            }
        }

        if ($match) {
            return $child->ID;
        }
    }

    return false;
}

/*
|--------------------------------------------------------------------------
| Images
|--------------------------------------------------------------------------
*/

function wc_bulk_set_images($product_id, $image_urls)
{

    if (empty($image_urls)) {
        return;
    }

    $urls = array_filter(
        array_map('trim', explode(',', $image_urls))
    );

    if (empty($urls)) {
        return;
    }

    $gallery = [];

    foreach ($urls as $index => $url) {

        /*
        |--------------------------------------------------------------------------
        | Avoid Duplicate Imports
        |--------------------------------------------------------------------------
        */

        $existing = attachment_url_to_postid($url);

        if ($existing) {

            $attachment_id = $existing;
        } else {

            $attachment_id = media_sideload_image(
                esc_url_raw($url),
                $product_id,
                null,
                'id'
            );
        }

        if (is_wp_error($attachment_id)) {

            error_log(
                'IMAGE ERROR: ' .
                    $attachment_id->get_error_message()
            );

            continue;
        }

        if ($index === 0) {

            set_post_thumbnail(
                $product_id,
                $attachment_id
            );
        } else {

            $gallery[] = $attachment_id;
        }
    }

    update_post_meta(
        $product_id,
        '_product_image_gallery',
        implode(',', $gallery)
    );
}
