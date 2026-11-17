<?php

/*
Plugin Name: WooCommerce Incomplete Orders
Description: WooCommerce orders that are not completed, by creation date, with XLSX export.
Author: FirstTracks Marketing
Author URI: https://firsttracksmarketing.com/
Version: 1.0.0
*/

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('ftwci_is_xlsx_export_request')) {
    function ftwci_is_xlsx_export_request() {
        return isset($_GET['action'], $_GET['page']) &&
            $_GET['action'] === 'ftwci_export_xlsx' &&
            $_GET['page'] === 'wc-incomplete-orders';
    }
}

if (!function_exists('ftwci_write_export_error_log')) {
    function ftwci_write_export_error_log($message) {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->error($message, array('source' => 'wc-incomplete-orders'));
        }
    }
}

if (ftwci_is_xlsx_export_request()) {
    register_shutdown_function(function () {
        $error = error_get_last();
        if (!$error) {
            return;
        }

        $fatal_types = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR);
        if (!in_array($error['type'], $fatal_types, true)) {
            return;
        }

        ftwci_write_export_error_log(
            $error['message'] . ' in ' . $error['file'] . ':' . $error['line']
        );
    });
}

class FT_WC_Incomplete_Orders_Report {

    private const PAGE_SIZE = 50;
    private const EXPORT_BATCH_SIZE = 500;
    private $date_range_orders_cache = array();

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_init', array($this, 'handle_export'));
    }

    /**
     * Add admin menu item
     */
    public function add_admin_menu() {
        add_menu_page(
            'Incomplete Orders',
            'Incomplete Orders',
            'manage_woocommerce',
            'wc-incomplete-orders',
            array($this, 'display_orders_page'),
            'dashicons-clipboard',
            56
        );
    }

    /**
     * Enqueue admin styles and scripts
     */
    public function enqueue_assets($hook) {
        if ($hook !== 'toplevel_page_wc-incomplete-orders') {
            return;
        }

        wp_enqueue_style(
            'ftwci-admin',
            plugin_dir_url(__FILE__) . 'admin-style.css',
            array(),
            '2.3.2'
        );

        wp_enqueue_script(
            'ftwci-admin',
            plugin_dir_url(__FILE__) . 'admin-script.js',
            array('jquery'),
            '2.1.0',
            true
        );
    }

    /** Default column visibility before browser cookie preferences are applied. */
    public function get_column_preferences() {
        return array(
            'id'       => true,
            'date'     => true,
            'customer' => true,
            'status'   => true,
            'coupon'   => true,
            'items'    => true,
            'renewal'  => true,
            'chapter'  => true,
            'total'    => true,
            'invoice'  => true,
            'payment'  => true,
            'last4'    => true,
            'reference' => true,
        );
    }

    /** Selected inclusive date range in the WordPress site timezone. */
    public function get_selected_date_range() {
        $has_from = isset($_GET['ftwci_date_from']);
        $has_to = isset($_GET['ftwci_date_to']);

        if ($has_from || $has_to) {
            if (!$has_from || !$has_to) {
                wp_die('Both From and To dates are required.', '', array('response' => 400));
            }

            $from = is_string($_GET['ftwci_date_from']) ? wp_unslash($_GET['ftwci_date_from']) : '';
            $to = is_string($_GET['ftwci_date_to']) ? wp_unslash($_GET['ftwci_date_to']) : '';

            if (!$this->is_valid_calendar_date($from) || !$this->is_valid_calendar_date($to)) {
                wp_die('Invalid report date. Use YYYY-MM-DD.', '', array('response' => 400));
            }

            if ($from > $to) {
                wp_die('The From date must be on or before the To date.', '', array('response' => 400));
            }

            return array('from' => $from, 'to' => $to);
        }

        // Continue to support previously bookmarked month-based report URLs.
        if (isset($_GET['ftwci_month_number'], $_GET['ftwci_year'])) {
            $month_number = is_scalar($_GET['ftwci_month_number']) ? absint($_GET['ftwci_month_number']) : 0;
            $year = is_scalar($_GET['ftwci_year']) ? absint($_GET['ftwci_year']) : 0;

            if ($year < 1000 || $year > 9999 || $month_number < 1 || $month_number > 12) {
                wp_die('Invalid report month or year.', '', array('response' => 400));
            }

            return $this->get_month_date_range(sprintf('%04d-%02d', $year, $month_number));
        }

        if (isset($_GET['ftwci_month'])) {
            $month = is_string($_GET['ftwci_month']) ? wp_unslash($_GET['ftwci_month']) : '';
            if (!preg_match('/^[1-9][0-9]{3}-(0[1-9]|1[0-2])$/D', $month)) {
                wp_die('Invalid report month. Use YYYY-MM.', '', array('response' => 400));
            }
            return $this->get_month_date_range($month);
        }

        return $this->get_month_date_range(current_datetime()->format('Y-m'));
    }

    private function is_valid_calendar_date($date) {
        if (!preg_match('/^[1-9][0-9]{3}-(0[1-9]|1[0-2])-([0-2][0-9]|3[0-1])$/D', $date)) {
            return false;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, wp_timezone());
        return $parsed && $parsed->format('Y-m-d') === $date;
    }

    /** Convert a YYYY-MM value to its first and last calendar dates. */
    private function get_month_date_range($month) {
        $start = new DateTimeImmutable($month . '-01', wp_timezone());
        return array(
            'from' => $start->format('Y-m-d'),
            'to' => $start->modify('last day of this month')->format('Y-m-d'),
        );
    }

    /** Timestamp bounds include both selected dates, including DST transitions. */
    public function get_timestamp_range(array $date_range) {
        $start = new DateTimeImmutable($date_range['from'] . ' 00:00:00', wp_timezone());
        $end = new DateTimeImmutable($date_range['to'] . ' 00:00:00', wp_timezone());
        return $start->getTimestamp() . '...' . ($end->modify('+1 day')->getTimestamp() - 1);
    }

    /** Selected WooCommerce product IDs. An empty selection includes all products. */
    private function get_selected_product_ids() {
        if (!isset($_GET['ftwci_products'])) {
            return array();
        }

        $raw_product_ids = wp_unslash($_GET['ftwci_products']);
        if (!is_array($raw_product_ids)) {
            wp_die('Invalid product filter.', '', array('response' => 400));
        }

        $product_ids = array_values(array_unique(array_filter(array_map('absint', $raw_product_ids))));
        sort($product_ids, SORT_NUMERIC);
        return $product_ids;
    }

    /** Registered order statuses that belong in the incomplete-orders report. */
    private function get_reportable_order_statuses() {
        $excluded_statuses = array(
            'wc-completed',
            'completed',
            'wc-refunded',
            'refunded',
            'wc-cancelled',
            'cancelled',
            'wc-credit-issued',
            'credit-issued',
        );
        $reportable_statuses = array();

        foreach (wc_get_order_statuses() as $status_slug => $status_label) {
            if (
                in_array($status_slug, $excluded_statuses, true) ||
                strcasecmp(trim(wp_strip_all_tags($status_label)), 'Credit Issued') === 0
            ) {
                continue;
            }

            $reportable_statuses[$status_slug] = $status_label;
        }

        return $reportable_statuses;
    }

    /** Selected status slugs. An empty selection includes every reportable status. */
    private function get_selected_order_statuses(array $reportable_statuses) {
        if (!isset($_GET['ftwci_statuses'])) {
            return array();
        }

        $raw_statuses = wp_unslash($_GET['ftwci_statuses']);
        if (!is_array($raw_statuses)) {
            wp_die('Invalid status filter.', '', array('response' => 400));
        }

        $selected_statuses = array_values(array_unique(array_map('sanitize_key', $raw_statuses)));
        foreach ($selected_statuses as $status_slug) {
            if (!isset($reportable_statuses[$status_slug])) {
                wp_die('Invalid order status.', '', array('response' => 400));
            }
        }

        sort($selected_statuses, SORT_STRING);
        return $selected_statuses;
    }

    /** Match simple products or any variation belonging to a selected parent product. */
    private function order_matches_products($order, array $selected_product_ids) {
        if (empty($selected_product_ids)) {
            return true;
        }

        $selected_lookup = array_fill_keys($selected_product_ids, true);
        foreach ($order->get_items('line_item') as $item) {
            $product_id = absint($item->get_product_id());
            $variation_id = absint($item->get_variation_id());
            if (isset($selected_lookup[$product_id]) || isset($selected_lookup[$variation_id])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return one row per order whose current status is not a final state.
     * The date filter is based on the order's creation date.
     */
    public function get_incomplete_orders($page = 1, $limit = self::PAGE_SIZE) {
        $selected_range = $this->get_selected_date_range();
        $selected_product_ids = $this->get_selected_product_ids();
        $reportable_statuses = $this->get_reportable_order_statuses();
        $selected_statuses = $this->get_selected_order_statuses($reportable_statuses);
        $query_statuses = empty($selected_statuses) ? array_keys($reportable_statuses) : $selected_statuses;
        $cache_key = implode(':', array(
            $selected_range['from'],
            $selected_range['to'],
            implode(',', $selected_product_ids),
            implode(',', $query_statuses),
        ));

        if (!isset($this->date_range_orders_cache[$cache_key])) {
            $date_range = $this->get_timestamp_range($selected_range);
            $batch_size = 200;
            $orders = array();
            $query_page = 1;
            do {
                $query_result = wc_get_orders(array(
                    'type' => 'shop_order',
                    'status' => $query_statuses,
                    'date_created' => $date_range,
                    'limit' => $batch_size,
                    'paged' => $query_page,
                    'paginate' => true,
                    'orderby' => 'date',
                    'order' => 'DESC',
                ));
                $max_pages = (int) $query_result->max_num_pages;

                foreach ($query_result->orders as $order) {
                    if ($this->order_matches_products($order, $selected_product_ids)) {
                        $orders[] = $order;
                    }
                }

                unset($query_result);
                $query_page++;
            } while ($query_page <= $max_pages);

            $this->date_range_orders_cache[$cache_key] = $orders;
        }

        $orders = $this->date_range_orders_cache[$cache_key];
        $total = count($orders);
        $limit = max(1, (int) $limit);
        $max_num_pages = max(1, (int) ceil($total / $limit));
        $page = min(max(1, (int) $page), $max_num_pages);

        return (object) array(
            'orders' => array_slice($orders, ($page - 1) * $limit, $limit),
            'total' => $total,
            'max_num_pages' => $max_num_pages,
            'page' => $page,
        );
    }

    /** Only accept four stored digits; never infer them from transaction IDs. */
    private function last_four($value) {
        return is_scalar($value) && preg_match('/^[0-9]{4}$/D', (string) $value) ? (string) $value : '';
    }

    public function get_payment_details($order) {
        $recorded = $order->get_meta('_wac_recorded_payment_method', true);
        if (in_array($recorded, array('ach', 'check'), true)) {
            $reference = $order->get_meta('_wac_payment_reference', true);
            return array(
                'payment' => $recorded === 'ach' ? 'ACH' : 'Check',
                'last4' => '',
                'reference' => is_scalar($reference) ? (string) $reference : '',
            );
        }

        $gateway = $order->get_payment_method();
        $details = array(
            'payment' => $order->get_payment_method_title() ?: ($gateway ?: 'Not recorded'),
            'last4' => '',
            'reference' => '',
        );
        // SkyVerge stores order-specific account_four for both cards and eChecks.
        // Restrict this lookup to card gateways so bank digits are never labeled card digits.
        if (in_array($gateway, array('authorize_net_cim_credit_card', 'authorize_net_aim'), true)) {
            $details['last4'] = $this->last_four($order->get_meta('_wc_' . $gateway . '_account_four', true));
            if ($details['last4'] === '') {
                $details['last4'] = 'Not recorded';
            }
        }
        return $details;
    }

    /** Whether this is a subscription renewal or contains a renewal product. */
    private function is_subscription_renewal($order) {
        if (function_exists('wcs_order_contains_renewal') && wcs_order_contains_renewal($order)) {
            return true;
        }

        foreach ($order->get_items() as $item) {
            if (stripos((string) $item->get_name(), 'renewal') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the structured data array used by the table and the export.
     *
     * @param  WC_Order[] $orders Incomplete WooCommerce orders.
     * @return array
     */
    private function build_order_rows(array $orders) {
        $rows = array();

        foreach ($orders as $order) {
            if (!$order instanceof WC_Order) {
                continue;
            }

            $order_status = $order->get_status();
            $order_total = (float) wc_format_decimal($order->get_total(), wc_get_price_decimals());
            $order_id    = $order->get_id();
            $customer_id = $order->get_customer_id();

            // ── Customer account and ACF user fields ───────────────────────────────
            $user              = $customer_id ? get_userdata($customer_id) : false;
            if ($user) {
                $customer = trim((string) $user->first_name . ' ' . (string) $user->last_name);
                if ($customer === '') {
                    $customer = trim(
                        (string) $order->get_billing_first_name() . ' ' .
                        (string) $order->get_billing_last_name()
                    );
                }
                $email = (string) $user->user_email;
                if ($email === '') {
                    $email = (string) $order->get_billing_email();
                }
            } else {
                $customer = trim(
                    (string) $order->get_billing_first_name() . ' ' .
                    (string) $order->get_billing_last_name()
                );
                $customer = $customer !== '' ? $customer . ' (Guest)' : '(Guest)';
                $email = (string) $order->get_billing_email();
            }

            if ($customer === '') {
                if ($user) {
                    $customer = trim((string) $user->display_name);
                    if ($customer === '') {
                        $customer = $email !== '' ? $email : 'Customer #' . $customer_id;
                    }
                } else {
                    $customer = '(Guest)';
                }
            }
            $additional_emails = $customer_id
                ? (string) get_user_meta($customer_id, 'wc_additional_email_addresses', true)
                : '';

            $user_fields = array(
                'group_number' => '',
                'local_board'  => '',
                'buddyboss_groups' => '',
                'phone'        => '',
                'mobile'       => '',
                'sales_staff'  => '',
            );

            if ($customer_id) {
                foreach (array_keys($user_fields) as $field_name) {
                    $user_fields[$field_name] = (string) get_user_meta($customer_id, $field_name, true);
                }
            }

            // ── Order items ───────────────────────────────────────────────
            $item_names = array();
            foreach ($order->get_items() as $item) {
                /** @var WC_Order_Item_Product $item */
                $qty          = $item->get_quantity();
                $item_names[] = $item->get_name() . ($qty > 1 ? ' ×' . $qty : '');
            }
            $item_names = array_values(array_unique($item_names));

            // ── Invoice number & PDF link ──────────────────────────
            // Webtoffee PDF Invoices plugin stores:
            //   wf_invoice_number  — the invoice number (may include prefix, e.g. "WC095510")
            $invoice_number = $this->get_invoice_number($order);
            $invoice_url    = $invoice_number ? $this->get_invoice_url($order_id) : '';

            $payment = $this->get_payment_details($order);
            $coupon_codes = method_exists($order, 'get_coupon_codes')
                ? $order->get_coupon_codes()
                : $order->get_used_coupons();
            $created_date = $order->get_date_created();
            $rows[] = array(
                'payment' => $payment['payment'],
                'last4' => $payment['last4'],
                'reference' => $payment['reference'],
                'edit_url' => $order->get_edit_order_url(),
                'order_date' => $created_date ? wp_date('F j, Y', $created_date->getTimestamp(), wp_timezone()) : '',
                'order_id'       => $order_id,
                'date'           => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : '',
                'customer'       => $customer,
                'customer_id'    => $customer_id,
                'status'         => $order_status,
                'coupon'         => implode(', ', array_map('strval', $coupon_codes)),
                'items'          => $item_names,
                'renewal'        => $this->is_subscription_renewal($order) ? 'Yes' : 'No',
                'total'          => $order_total,
                'currency'       => $order->get_currency(),
                'invoice_number' => $invoice_number,
                'invoice_url'    => $invoice_url,
                'email'          => $email,
                'extra_emails'   => $additional_emails,
                'group_number'   => $user_fields['group_number'],
                'local_board'    => $user_fields['local_board'],
                'buddyboss_groups' => $user_fields['buddyboss_groups'],
                'phone'          => $user_fields['phone'],
                'mobile'         => $user_fields['mobile'],
                'sales_staff'    => $user_fields['sales_staff'],
            );
        }

        return $rows;
    }

    /**
     * Retrieve the invoice number stored by the Webtoffee PDF Invoices plugin.
     *
     * Confirmed meta key from official Webtoffee documentation:
     *   wf_invoice_number  (no leading underscore)
     *
     * This key stores the full invoice number as displayed in the plugin metabox,
     * including any configured prefix/suffix (e.g. "WC095510").
     *
     * @param  WC_Order $order
     * @return string   Invoice number, or empty string if not yet generated.
     */
    private function get_invoice_number(WC_Order $order) {
        $value = $order->get_meta('wf_invoice_number', true);
        if ($value !== '' && $value !== null && $value !== false) {
            return (string) $value;
        }
        return '';
    }

    /**
     * Build the admin URL to download a PDF invoice via the WebToffee plugin.
     *
     * WebToffee's admin helper builds:
     *   wp-admin/?print_packinglist=true&post={order_id}&type=download_invoice&_wpnonce={nonce}
     * with nonce action WF_PKLIST_PLUGIN_NAME.
     *
     * @param  int $order_id
     * @return string
     */
    private function get_invoice_url($order_id) {
        $order_id = absint($order_id);
        if ($order_id <= 0) {
            return '';
        }

        if (
            class_exists('Wf_Woocommerce_Packing_List_Admin') &&
            method_exists('Wf_Woocommerce_Packing_List_Admin', 'get_print_url')
        ) {
            return Wf_Woocommerce_Packing_List_Admin::get_print_url($order_id, 'download_invoice');
        }

        $nonce_action = defined('WF_PKLIST_PLUGIN_NAME')
            ? WF_PKLIST_PLUGIN_NAME
            : 'print-invoices-packing-slip-labels-for-woocommerce';

        return wp_nonce_url(
            admin_url('?print_packinglist=true&post=' . $order_id . '&type=download_invoice'),
            $nonce_action
        );
    }

    /**
     * Return a human-readable, CSS-class-friendly status label.
     *
     * @param  string $raw_status  e.g. "pending", "on-hold"
     * @return array{ label: string, class: string }
     */
    private function status_meta($raw_status) {
        $labels = array(
            'pending'        => array('label' => 'Pending Payment', 'class' => 'status-pending'),
            'processing'     => array('label' => 'Processing',      'class' => 'status-processing'),
            'on-hold'        => array('label' => 'On Hold',         'class' => 'status-on-hold'),
            'cancelled'      => array('label' => 'Cancelled',       'class' => 'status-cancelled'),
            'refunded'       => array('label' => 'Refunded',        'class' => 'status-refunded'),
            'failed'         => array('label' => 'Failed',          'class' => 'status-failed'),
            'checkout-draft' => array('label' => 'Draft',           'class' => 'status-draft'),
        );

        if (isset($labels[$raw_status])) {
            return $labels[$raw_status];
        }

        return array(
            'label' => ucwords(str_replace('-', ' ', $raw_status)),
            'class' => 'status-custom',
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // EXPORT
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Handle export requests triggered by query string
     */
    public function handle_export() {
        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';
        $page   = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

        if (
            $page !== 'wc-incomplete-orders' ||
            $action !== 'ftwci_export_xlsx'
        ) {
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die('Insufficient permissions');
        }

        check_admin_referer('ftwci_export');

        if (!function_exists('wc_get_orders')) {
            wp_die('WooCommerce is not active.');
        }
        $rows = $this->export_rows();
        $this->export_xlsx($rows);
    }

    /** Fetch the complete export in bounded server-side batches. */
    private function export_rows() {
        $batch = 1;
        do {
            $result = $this->get_incomplete_orders($batch, self::EXPORT_BATCH_SIZE);
            $max_batches = $result->max_num_pages;
            foreach ($this->build_order_rows($result->orders) as $row) {
                yield $row;
            }
            unset($result);
            gc_collect_cycles();
            $batch++;
        } while ($batch <= $max_batches);
    }

    /** Return the columns included in the XLSX export. */
    private function get_xlsx_headers() {
        return array(
            'Invoice #',
            'Order ID',
            'Order Date',
            'Customer',
            'Status',
            'Coupon',
            'Product',
            'Membership Renewal',
            'Chapter(s)',
            'Total',
            'Payment Method',
            'CC Digits',
            'ACH/Check Ref',
            'Email',
        );
    }

    /** Format one row using the XLSX column order. */
    private function get_xlsx_row(array $row) {
        $product_names = array_map(
            function ($item_name) {
                return html_entity_decode(
                    wp_strip_all_tags((string) $item_name),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                );
            },
            $row['items']
        );

        return array(
            $row['invoice_number'],
            $row['order_id'],
            $row['order_date'],
            $row['customer'],
            ucwords(str_replace('-', ' ', $row['status'])),
            $row['coupon'],
            implode(', ', $product_names),
            $row['renewal'],
            $row['buddyboss_groups'],
            $row['total'],
            $row['payment'],
            $row['last4'],
            $row['reference'],
            $row['email'],
        );
    }

    /** Build a unique export filename for the current site and report date range. */
    private function get_export_filename($extension) {
        $site_domain = wp_parse_url(home_url(), PHP_URL_HOST);
        $site_domain = $site_domain ? sanitize_file_name($site_domain) : 'wordpress-site';
        $unique_id = gmdate('YmdHis') . wp_rand(1000, 9999);
        $date_range = $this->get_selected_date_range();

        return sprintf(
            '%s-wc-incomplete-orders-%s-to-%s-%s.%s',
            $site_domain,
            $date_range['from'],
            $date_range['to'],
            $unique_id,
            $extension
        );
    }

    /**
     * Return whether any PhpSpreadsheet class/interface/trait is already loaded.
     *
     * Loading a second unprefixed PhpSpreadsheet copy in WordPress can mix class
     * versions with other plugins and cause fatal method signature errors.
     *
     * @return bool
     */
    private function has_loaded_phpspreadsheet_symbols() {
        $symbols = array_merge(get_declared_classes(), get_declared_interfaces());

        if (function_exists('get_declared_traits')) {
            $symbols = array_merge($symbols, get_declared_traits());
        }

        foreach ($symbols as $symbol) {
            if (strpos($symbol, 'PhpOffice\\PhpSpreadsheet\\') === 0) {
                return true;
            }
        }

        return false;
    }

    /** Load the plugin's locked PhpSpreadsheet dependency. */
    private function load_spreadsheet_library() {
        if (class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet') && class_exists('PhpOffice\\PhpSpreadsheet\\Writer\\Xlsx')) {
            return;
        }

        if ($this->has_loaded_phpspreadsheet_symbols()) {
            throw new \RuntimeException('Another plugin loaded an incomplete or incompatible PhpSpreadsheet library before this export. Update or deactivate that plugin and try again.');
        }

        $autoload = __DIR__ . '/vendor/autoload.php';
        if (!is_readable($autoload)) {
            throw new \RuntimeException('The Incomplete Orders plugin is missing its bundled Composer dependencies. Reinstall the complete plugin package.');
        }

        require_once $autoload;

        if (!class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet') || !class_exists('PhpOffice\\PhpSpreadsheet\\Writer\\Xlsx')) {
            throw new \RuntimeException('The Incomplete Orders plugin does not contain a complete compatible PhpSpreadsheet XLSX writer.');
        }
    }

    /**
     * Export order rows to XLSX via PhpSpreadsheet.
     *
     * @param array $rows
     */
    private function export_xlsx(iterable $rows) {
        $temp_file = '';

        try {
            $this->load_spreadsheet_library();

            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet       = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Sheet1');

            // Headers
            $headers = $this->get_xlsx_headers();
            $columns = range('A', 'N');

            foreach ($headers as $index => $header) {
                $sheet->setCellValue($columns[$index] . '1', $header);
            }

            $header_style = array(
                'font' => array('bold' => true),
                'fill' => array(
                    'fillType'   => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => array('rgb' => 'E8E8E8'),
                ),
            );
            $sheet->getStyle('A1:N1')->applyFromArray($header_style);

            // Data rows
            $row = 2;
            foreach ($rows as $r) {
                foreach ($this->get_xlsx_row($r) as $index => $value) {
                    $cell = $columns[$index] . $row;

                    if ($index === 9) {
                        $xlsx_total = (float) $r['total'];
                        $sheet->getCell($cell)->setValueExplicit(
                            $xlsx_total,
                            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC
                        );
                        continue;
                    }

                    if ($index === 1) {
                        $sheet->getCell($cell)->setValueExplicit(
                            (int) $value,
                            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC
                        );
                        continue;
                    }

                    if ($index === 11 && preg_match('/^[0-9]{4}$/D', (string) $value)) {
                        $sheet->getCell($cell)->setValueExplicit(
                            (int) $value,
                            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC
                        );
                        continue;
                    }

                    $sheet->getCell($cell)->setValueExplicit((string) $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }
                $row++;
            }

            if ($row > 2) {
                $sheet->getStyle('J2:J' . ($row - 1))
                    ->getNumberFormat()
                    ->setFormatCode('#,##0.00;(#,##0.00)');
                $sheet->getStyle('L2:L' . ($row - 1))
                    ->getNumberFormat()
                    ->setFormatCode('0000');
            }

            foreach ($columns as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }

            $filename = $this->get_export_filename('xlsx');
            $temp_file = wp_tempnam($filename);

            if (!$temp_file) {
                throw new \RuntimeException('Unable to create XLSX export file.');
            }

            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save($temp_file);

            $file_size = filesize($temp_file);
            if ($file_size === false || $file_size === 0) {
                throw new \RuntimeException('Unable to finalize XLSX export file.');
            }

            while (ob_get_level() > 0) {
                if (!@ob_end_clean()) {
                    break;
                }
            }

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . $file_size);
            header('Cache-Control: max-age=0');

            readfile($temp_file);
            @unlink($temp_file);
            exit;
        } catch (\Throwable $e) {
            if ($temp_file && file_exists($temp_file)) {
                @unlink($temp_file);
            }

            ftwci_write_export_error_log(
                $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
            );

            $message = '<p>Unable to generate XLSX export.</p>' .
                '<p><strong>Error:</strong> ' . esc_html($e->getMessage()) . '</p>';

            wp_die($message, 'XLSX Export Error', array('response' => 500));
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // DISPLAY
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Main admin page callback
     */
    public function display_orders_page() {
        if (!function_exists('wc_get_orders')) {
            echo '<div class="notice notice-error"><p>WooCommerce is not active.</p></div>';
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die('Insufficient permissions');
        }
        $column_prefs = $this->get_column_preferences();
        $date_range = $this->get_selected_date_range();
        $selected_product_ids = $this->get_selected_product_ids();
        $reportable_statuses = $this->get_reportable_order_statuses();
        $selected_statuses = $this->get_selected_order_statuses($reportable_statuses);
        $products = wc_get_products(array(
            'status' => array('publish', 'private', 'draft', 'pending', 'future'),
            'limit' => -1,
            'orderby' => 'name',
            'order' => 'ASC',
        ));
        $page = isset($_GET['ftwci_paged']) && is_scalar($_GET['ftwci_paged']) ? max(1, absint($_GET['ftwci_paged'])) : 1;
        $result = $this->get_incomplete_orders($page);
        $page = $result->page;
        $rows = $this->build_order_rows($result->orders);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <form class="wco-order-filters wco-order-filters--top" method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                <input type="hidden" name="page" value="wc-incomplete-orders">
                <div class="wco-filter-field">
                    <label for="ftwci-date-from">From:</label>
                    <input type="date" id="ftwci-date-from" name="ftwci_date_from" value="<?php echo esc_attr($date_range['from']); ?>" required>
                </div>

                <div class="wco-filter-field">
                    <label for="ftwci-date-to">To:</label>
                    <input type="date" id="ftwci-date-to" name="ftwci_date_to" value="<?php echo esc_attr($date_range['to']); ?>" required>
                </div>

                <div class="wco-filter-field wco-multiselect-filter">
                    <span id="ftwci-products-label" class="wco-multiselect-filter-label">Products:</span>
                    <div class="wco-multiselect" data-default-summary="All products" data-plural-label="products selected">
                        <button
                            type="button"
                            class="button wco-multiselect-toggle"
                            aria-expanded="false"
                            aria-controls="ftwci-product-options"
                            aria-labelledby="ftwci-products-label ftwci-products-summary"
                        >
                            <span id="ftwci-products-summary">All products</span>
                            <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                        </button>
                        <div id="ftwci-product-options" class="wco-multiselect-panel" hidden>
                            <div class="wco-multiselect-toolbar">
                                <input type="search" class="wco-multiselect-search" placeholder="Search products…" aria-label="Search products">
                                <button type="button" class="button-link wco-multiselect-clear">Uncheck all</button>
                            </div>
                            <div class="wco-multiselect-options">
                                <?php if (empty($products)) : ?>
                                    <span class="wco-multiselect-empty">No products found.</span>
                                <?php else : ?>
                                    <?php foreach ($products as $product) : ?>
                                        <?php $product_label = $product->get_name(); ?>
                                        <label class="wco-multiselect-option">
                                            <input
                                                type="checkbox"
                                                class="wco-multiselect-checkbox"
                                                name="ftwci_products[]"
                                                value="<?php echo esc_attr($product->get_id()); ?>"
                                                <?php checked(in_array($product->get_id(), $selected_product_ids, true)); ?>
                                            >
                                            <span class="wco-multiselect-option-name"><?php echo esc_html($product_label); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="wco-filter-field wco-multiselect-filter wco-status-filter">
                    <span id="ftwci-statuses-label" class="wco-multiselect-filter-label">Status:</span>
                    <div class="wco-multiselect" data-default-summary="All statuses" data-plural-label="statuses selected">
                        <button
                            type="button"
                            class="button wco-multiselect-toggle"
                            aria-expanded="false"
                            aria-controls="ftwci-status-options"
                            aria-labelledby="ftwci-statuses-label ftwci-statuses-summary"
                        >
                            <span id="ftwci-statuses-summary">All statuses</span>
                            <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                        </button>
                        <div id="ftwci-status-options" class="wco-multiselect-panel" hidden>
                            <div class="wco-multiselect-toolbar">
                                <input type="search" class="wco-multiselect-search" placeholder="Search statuses…" aria-label="Search statuses">
                                <button type="button" class="button-link wco-multiselect-clear">Uncheck all</button>
                            </div>
                            <div class="wco-multiselect-options">
                                <?php foreach ($reportable_statuses as $status_slug => $status_label) : ?>
                                    <label class="wco-multiselect-option">
                                        <input
                                            type="checkbox"
                                            class="wco-multiselect-checkbox"
                                            name="ftwci_statuses[]"
                                            value="<?php echo esc_attr($status_slug); ?>"
                                            <?php checked(in_array($status_slug, $selected_statuses, true)); ?>
                                        >
                                        <span class="wco-multiselect-option-name"><?php echo esc_html($status_label); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <button type="submit" class="button button-primary">Filter</button>
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('ftwci_export')); ?>">
                <button type="submit" name="action" value="ftwci_export_xlsx" class="button wco-export-xlsx">
                    <span class="dashicons dashicons-download" aria-hidden="true"></span> Export XLSX
                </button>
            </form>

            <!-- Column visibility controls -->
            <div class="usr-controls">
                <div class="usr-column-controls">
                    <div class="usr-column-toggles" role="group" aria-label="Show or hide table columns">
                        <?php
                        $toggles = array(
                            'id'       => 'Order ID',
                            'date'     => 'Order Date',
                            'payment'  => 'Payment Method',
                            'last4'    => 'CC Digits',
                            'reference' => 'ACH/Check Ref',
                            'customer' => 'Customer',
                            'status'   => 'Status',
                            'coupon'   => 'Coupon',
                            'items'    => 'Product',
                            'renewal'  => 'Membership Renewal',
                            'chapter'  => 'Chapter(s)',
                            'total'    => 'Total',
                            'invoice'  => 'Invoice #',
                        );
                        foreach ($toggles as $col_key => $col_label) :
                        ?>
                            <label>
                                <input
                                    type="checkbox"
                                    class="usr-column-toggle"
                                    data-column="<?php echo esc_attr($col_key); ?>"
                                    <?php checked($column_prefs[$col_key]); ?>
                                >
                                <?php echo esc_html($col_label); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>

                </div>
            </div>

            <div id="wco-table-wrap">
                <?php if (empty($rows)) : ?>
                    <div class="notice notice-info"><p>No incomplete orders found on this page for the selected creation date range.</p></div>
                <?php else : ?>
                    <?php $this->render_table($rows, $column_prefs, $result->total); ?>
                <?php endif; ?>
            </div>
            <?php if ($result->max_num_pages > 1) : ?>
                <div class="tablenav wco-pagination"><div class="tablenav-pages">
                <?php echo wp_kses_post(paginate_links(array(
                    'base' => str_replace('999999999', '%#%', add_query_arg(array('page' => 'wc-incomplete-orders', 'ftwci_date_from' => $date_range['from'], 'ftwci_date_to' => $date_range['to'], 'ftwci_products' => $selected_product_ids, 'ftwci_statuses' => $selected_statuses, 'ftwci_paged' => 999999999), admin_url('admin.php'))),
                    'format' => '', 'current' => $page, 'total' => $result->max_num_pages,
                ))); ?>
                </div></div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render the orders table
     *
     * @param array $rows         Rows from build_order_rows()
     * @param array $column_prefs Visibility preferences
     * @param int   $total        Total matching events across every page
     */
    private function render_table(array $rows, array $column_prefs, $total) {
        // Helper: inline style to hide a column when pref is false
        $hide = function($key) use ($column_prefs) {
            return !$column_prefs[$key] ? ' style="display:none;"' : '';
        };
        ?>
        <table class="wp-list-table widefat fixed striped usr-renewals-table">
            <thead>
                <tr>
                    <th class="usr-col-id"<?php echo $hide('id'); ?>>
                        Order
                    </th>
                    <th class="usr-col-date"<?php echo $hide('date'); ?>>
                        Order Date
                    </th>
                    <th class="usr-col-customer"<?php echo $hide('customer'); ?>>
                        Customer
                    </th>
                    <th class="usr-col-status"<?php echo $hide('status'); ?>>
                        Status
                    </th>
                    <th class="usr-col-coupon"<?php echo $hide('coupon'); ?>>
                        Coupon
                    </th>
                    <th class="usr-col-items"<?php echo $hide('items'); ?>>
                        Product
                    </th>
                    <th class="usr-col-renewal"<?php echo $hide('renewal'); ?>>
                        Membership Renewal
                    </th>
                    <th class="usr-col-chapter"<?php echo $hide('chapter'); ?>>
                        Chapter(s)
                    </th>
                    <th class="usr-col-total"<?php echo $hide('total'); ?>>
                        Total
                    </th>
                    <?php foreach (array('payment' => 'Payment Method', 'last4' => 'CC Digits', 'reference' => 'ACH/Check Ref') as $key => $label) : ?>
                        <th class="usr-col-<?php echo esc_attr($key); ?>"<?php echo $hide($key); ?>><?php echo esc_html($label); ?></th>
                    <?php endforeach; ?>
                    <th class="usr-col-invoice"<?php echo $hide('invoice'); ?>>
                        Invoice #
                    </th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row) :
                    $status_meta = $this->status_meta($row['status']);
                ?>
                    <tr>
                        <!-- Order ID -->
                        <td class="usr-col-id"<?php echo $hide('id'); ?>>
                            <a href="<?php echo esc_url($row['edit_url']); ?>">
                                #<?php echo absint($row['order_id']); ?>
                            </a>
                        </td>

                        <!-- Date -->
                        <td class="usr-col-date"<?php echo $hide('date'); ?>>
                            <?php echo $row['order_date'] ? esc_html($row['order_date']) : '—'; ?>
                        </td>

                        <!-- Customer -->
                        <td class="usr-col-customer"<?php echo $hide('customer'); ?>>
                            <?php if ($row['customer_id']) : ?>
                                <a href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . $row['customer_id'])); ?>">
                                    <?php echo esc_html($row['customer']); ?>
                                </a>
                            <?php else : ?>
                                <?php echo esc_html($row['customer']); ?>
                            <?php endif; ?>
                        </td>

                        <!-- Status badge -->
                        <td class="usr-col-status"<?php echo $hide('status'); ?>>
                            <span class="wco-status-badge <?php echo esc_attr($status_meta['class']); ?>">
                                <?php echo esc_html($status_meta['label']); ?>
                            </span>
                        </td>

                        <!-- Coupon codes -->
                        <td class="usr-col-coupon"<?php echo $hide('coupon'); ?>>
                            <?php echo $row['coupon'] !== '' ? esc_html($row['coupon']) : '—'; ?>
                        </td>

                        <!-- Product -->
                        <td class="usr-col-items"<?php echo $hide('items'); ?>>
                            <?php if (!empty($row['items'])) : ?>
                                <ul class="wco-item-list">
                                    <?php foreach ($row['items'] as $item_name) : ?>
                                        <li><?php echo esc_html($item_name); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else : ?>
                                <em>No product</em>
                            <?php endif; ?>
                        </td>

                        <!-- Subscription renewal -->
                        <td class="usr-col-renewal"<?php echo $hide('renewal'); ?>>
                            <?php echo esc_html($row['renewal']); ?>
                        </td>

                        <!-- Chapter -->
                        <td class="usr-col-chapter"<?php echo $hide('chapter'); ?>>
                            <?php echo $row['buddyboss_groups'] !== '' ? esc_html($row['buddyboss_groups']) : '—'; ?>
                        </td>

                        <!-- Total -->
                        <td class="usr-col-total"<?php echo $hide('total'); ?>>
                            <?php
                            $display_total = (float) $row['total'];
                            echo wp_kses_post(wc_price($display_total, array('currency' => $row['currency'])));
                            ?>
                        </td>

                        <?php foreach (array('payment', 'last4', 'reference') as $key) : ?>
                            <td class="usr-col-<?php echo esc_attr($key); ?>"<?php echo $hide($key); ?>><?php echo esc_html($row[$key] !== '' ? $row[$key] : '—'); ?></td>
                        <?php endforeach; ?>
                        <!-- Invoice -->
                        <td class="usr-col-invoice"<?php echo $hide('invoice'); ?>>
                            <?php if ($row['invoice_number'] && $row['invoice_url']) : ?>
                                <a
                                    href="<?php echo esc_url($row['invoice_url']); ?>"
                                    target="_blank"
                                    rel="noopener"
                                    class="wco-invoice-link"
                                    title="Download PDF invoice"
                                >
                                    <span class="dashicons dashicons-pdf"></span>
                                    <span class="wco-invoice-number"><?php echo esc_html($row['invoice_number']); ?></span>
                                </a>
                            <?php elseif ($row['invoice_number']) : ?>
                                <?php echo esc_html($row['invoice_number']); ?>
                            <?php else : ?>
                                <span class="wco-no-invoice">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="13">
                        <strong>
                            Showing <?php echo esc_html(number_format_i18n(count($rows))); ?>
                            of <?php echo esc_html(number_format_i18n((int) $total)); ?> orders
                        </strong>
                    </td>
                </tr>
            </tfoot>
        </table>
        <?php
    }
}

// Initialise
function ft_wc_incomplete_orders_init() {
    new FT_WC_Incomplete_Orders_Report();
}
add_action('plugins_loaded', 'ft_wc_incomplete_orders_init');

add_action('before_woocommerce_init', function () {
    if (class_exists('Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});
