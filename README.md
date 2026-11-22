# Woo Bulk Product API Importer

Import & update WooCommerce variable products via REST API

## Installation and use

Place the `wc-bulk-product-importer` directory in `wp-content/plugins/` and activate **Woo Bulk Product API Importer** from the WordPress Plugins screen.

With WooCommerce active, submit a JSON array to `POST /wp-json/wc-bulk/v1/import` using an account with the `manage_woocommerce` capability. Rows are grouped by SKU.

## Credits

Developed by [Mahim Zaman](https://mahimzaman.com) for [Condixia](https://condixia.com).
