<?php
if (!class_exists("DCWOO")) {

    define('DCWOO_OFFERING_ID_META', '_dc_offering_id');
    define('DCWOO_PROCESSED_META', '_dc_processed');

    class DCWOO
    {

        private $last_api_error = null;

        public function DCWOO()
        {
            $this->__construct();
        }

        public function __construct()
        {
            if (is_admin()) {

                // wp-admin

                add_action('admin_menu', array($this, 'init_admin_menu'));
                add_action('wp_ajax_get_dc_offerings', array($this, 'get_dc_offerings_callback'));
                add_action('wp_ajax_resolve_order', array($this, 'resolve_order_ajax'));

            } else {

                // frontend - storefront actions only
                add_action('woocommerce_check_cart_items', array($this, 'check_cart_items_action'));
                add_filter('woocommerce_add_to_cart_validation', array($this, 'add_to_cart_validation_filter'), 10, 3);

            }
            add_action( 'woocommerce_order_status_completed', array($this, 'mysite_completed'),10,2);
            add_action( 'woocommerce_order_status_processing', array($this, 'mysite_completed'),10,2);
            add_action( 'woocommerce_order_status_changed', array($this, 'order_status_changed_debug'),10,4);
            // order status change actions (these must be set in both admin and frontend)
            add_filter('woocommerce_payment_complete_order_status', array($this, 'payment_complete_order_status_filter'), 10, 2);
            // Note the filter below is at priority 11... in order to check the one above first (at 10)
            add_filter('woocommerce_payment_complete_order_status', array($this, 'payment_complete_order_status_filter_step2'), 11, 2);

        }

        //----------------------------------------------------------------------------
        //
        // WP-ADMIN PAGES
        //
        //----------------------------------------------------------------------------

        public function init_admin_menu()
        {

            add_options_page('DigitalChalk API v5 Settings', 'DigitalChalk - WooCommerce Integration', 'manage_options', 'dcwoo_options', array($this, 'display_settings_page'));
            add_submenu_page('edit.php?post_type=product', 'Add DigitalChalk Product', 'Add DigitalChalk Product', 'manage_options', 'add_dc_product', array($this, 'display_add_dc_product_page'));

            //  http://codex.wordpress.org/Function_Reference/add_submenu_page
            //  says that options.php hides this submenu from all menus
            add_submenu_page('options.php', 'Resolve Issue', 'Resolve Issue', 'manage_options', 'resolve_issue', array($this, 'display_resolve_issue'));

        }

        public function display_resolve_issue()
        {
            $order_id = $_REQUEST['order_id'];
            include DCWOO_ABSPATH . '/includes/resolveOrderIssue.php';
        }

        public function resolve_order_ajax()
        {
            $order_id = $_REQUEST['order_id'];
            $this->debug_log("resolve_order_ajax called for order #{$order_id}", 'INFO');
            $order = new WC_Order($order_id);
            $response = array();
            if ($this->process_order($order_id, true)) {
                add_post_meta($order_id, DCWOO_PROCESSED_META, 'yes') || update_post_meta($order_id, DCWOO_PROCESSED_META, 'yes');

                $order->add_order_note("Issue resolution was successful!");
                if ($order->get_status() != 'completed') {
                    $order->update_status('completed');
                }
                $response['process_result'] = 'true';
                $this->debug_log("resolve_order_ajax: order #{$order_id} resolved successfully", 'INFO');
            } else {
                $order->add_order_note("Retry of processing failed.");
                $response['process_result'] = 'false';
                $this->debug_log("resolve_order_ajax: order #{$order_id} resolution FAILED", 'ERROR');
            }

            echo json_encode($response);
            die; // required by wp-ajax.. see the wp codex
        }



        public function add_to_cart_validation_filter($valid, $product_id, $quantity)
        {
            global $woocommerce;
            global $current_user;
            if ($current_user->ID > 0) {
                $dcMeta = get_post_meta($product_id, DCWOO_OFFERING_ID_META, true);
                if (!empty($dcMeta)) {
                    $dcUser = $this->getDCUserByEmail($current_user->user_email);
                    if (!empty($dcUser)) {
                        $availableOfferings = $this->getAvailableOfferingsForUserId($dcUser->id);
                        if ($availableOfferings != null) {
                            if (!in_array($dcMeta, $availableOfferings)) {
                                wc_add_notice(__('You cannot purchase this item at this time.  You may have purchased it previously.', 'dcwoo'));
                                return false;
                            }
                        }
                    }
                }

            }
            return true;
        }

        /**
         * process_order - NOTE: This function body is commented out and always returns true.
         * The actual registration logic lives in mysite_completed().
         * This is called by payment_complete_order_status_filter_step2 and resolve_order_ajax.
         */
        public function process_order($order_id, $isRetry = false)
        {
            global $woocommerce;
            $this->debug_log("process_order called for order #{$order_id} (isRetry=" . ($isRetry ? 'true' : 'false') . ") — NOTE: function body is commented out, always returns true", 'WARN');
            $processResult = true;

            // IMPORTANT: The entire process_order body is commented out.
            // Registration logic has been moved to mysite_completed().
            // This function currently does nothing and returns true.

            return $processResult;
        }

        /**
         * Main registration handler — triggered by woocommerce_order_status_completed action.
         * This is where DC user creation and course registration actually happens.
         */
        function mysite_completed( $order_id )
        {
            global $woocommerce;
            $this->debug_log("=== mysite_completed START for order #{$order_id} ===", 'INFO');

            $processResult = true;
            $resolveUrl = admin_url('options.php?page=resolve_issue&order_id=' . $order_id);

            $order = new WC_Order($order_id);
            if (!$order) {
                $this->debug_log("Order #{$order_id}: WC_Order returned falsy — cannot proceed", 'ERROR');
                return false;
            }
            $this->debug_log("Order #{$order_id}: Loaded. Status='" . $order->get_status() . "'", 'INFO');

            // Check if already processed to avoid double-processing
            $processedMeta = get_post_meta($order_id, DCWOO_PROCESSED_META, true);
            if (!empty($processedMeta) && $processedMeta == 'yes') {
                $this->debug_log("Order #{$order_id}: Already has DCWOO_PROCESSED_META='yes' — skipping", 'WARN');
                return true;
            }

            // Collect DC products from order items
            $dcProducts = array();
            $items = $order->get_items();
            $this->debug_log("Order #{$order_id}: " . count($items) . " line item(s)", 'INFO');

            foreach ($items as $item) {
                $product_id = $item->get_product_id();
                $product = wc_get_product($product_id);

                if (!$product) {
                    $this->debug_log("Order #{$order_id}: Could not load WC product for product_id={$product_id} — skipping item", 'ERROR');
                    continue;
                }

                $type = $product->get_type();
                $dcMeta = get_post_meta($product_id, DCWOO_OFFERING_ID_META, true);
                $this->debug_log("Order #{$order_id}: Item product_id={$product_id}, type={$type}, name='" . $item->get_name() . "', dc_offering_id=" . ($dcMeta ?: '(none)'), 'INFO');

                if (!empty($dcMeta)) {
                    $dcProducts[$dcMeta] = $item;
                }
            }

            $this->debug_log("Order #{$order_id}: Found " . count($dcProducts) . " DC product(s)", 'INFO');

            if (count($dcProducts) == 0) {
                $this->debug_log("Order #{$order_id}: No DC products — nothing to process", 'INFO');
                $this->debug_log("=== mysite_completed END for order #{$order_id} (no DC products) ===", 'INFO');
                return $processResult;
            }

            // Determine user identity — always use billing email from the order
            // to avoid issues when admin changes order status from wp-admin
            // (is_user_logged_in() would return the admin, not the customer)
            $emailForLookup = $order->get_billing_email();
            $firstName = $order->get_billing_first_name();
            $lastName = $order->get_billing_last_name();
            $this->debug_log("Order #{$order_id}: Using billing email='{$emailForLookup}', name='{$firstName} {$lastName}'", 'INFO');

            if (empty($emailForLookup)) {
                $this->debug_log("Order #{$order_id}: No email available — cannot look up or create DC user", 'ERROR');
                $order->add_order_note("DCWOO: No email available for DC user lookup/creation. Cannot process registrations. <a href='" . $resolveUrl . "'>Resolve</a>");
                $this->debug_log("=== mysite_completed END for order #{$order_id} (FAILED — no email) ===", 'ERROR');
                return false;
            }

            // Look up DC user
            $this->debug_log("Order #{$order_id}: Looking up DC user by email '{$emailForLookup}'", 'INFO');
            $dcUser = $this->getDCUserByEmail($emailForLookup);

            if (empty($dcUser)) {
                // Create DC user
                $this->debug_log("Order #{$order_id}: DC user not found — creating new user (firstName='{$firstName}', lastName='{$lastName}', email='{$order->get_billing_email()}')", 'INFO');

                $result = $this->makeApiV5Call("/dc/api/v5/users", "POST", array(
                    'firstName' => $firstName,
                    'lastName' => $lastName,
                    'email' => $order->get_billing_email(),
                    'password' => '***1UNPARSEABLE2***'
                ));

                if ($result['api_result'] == 'success') {
                    $this->debug_log("Order #{$order_id}: DC user created successfully for '{$emailForLookup}'", 'INFO');
                    $order->add_order_note("Created new DC user with email '" . $emailForLookup . "'.");
                    $dcUser = $this->getDCUserByEmail($emailForLookup);

                    if (empty($dcUser)) {
                        $this->debug_log("Order #{$order_id}: Created DC user but subsequent lookup FAILED for '{$emailForLookup}'", 'ERROR');
                        $order->add_order_note("DCWOO: Created DC user but could not retrieve it. <a href='" . $resolveUrl . "'>Resolve</a>");
                        $this->debug_log("=== mysite_completed END for order #{$order_id} (FAILED — post-create lookup) ===", 'ERROR');
                        return false;
                    }

                    $dcUserId = is_object($dcUser) ? $dcUser->id : $dcUser['id'];
                    $this->debug_log("Order #{$order_id}: DC user retrieved after creation. dcUserId={$dcUserId}", 'INFO');
                    $this->dc_user_update_info($order_id, $dcUserId);
                } else {
                    $httpStatus = isset($result['http_status_code']) ? $result['http_status_code'] : 'N/A';
                    $error = isset($result['error']) ? $result['error'] : 'unknown';
                    $errorDesc = isset($result['error_description']) ? $result['error_description'] : '';
                    $fieldErrors = isset($result['fieldErrors']) ? json_encode($result['fieldErrors']) : '';
                    $errors = isset($result['errors']) ? json_encode($result['errors']) : '';

                    $this->debug_log("Order #{$order_id}: FAILED to create DC user. HTTP={$httpStatus}, error={$error}, desc={$errorDesc}, fieldErrors={$fieldErrors}, errors={$errors}", 'ERROR');
                    $this->debug_log("Order #{$order_id}: Full create-user API result: " . json_encode($result), 'ERROR');

                    $order->add_order_note("Tried to add user '" . $emailForLookup . "' to DigitalChalk and failed (HTTP {$httpStatus}: {$error}). No registrations done. <a href='" . $resolveUrl . "'>Resolve</a>");
                    $processResult = false;
                }
            } else {
                $dcUserId = is_object($dcUser) ? $dcUser->id : $dcUser['id'];
                $this->debug_log("Order #{$order_id}: Found existing DC user. dcUserId={$dcUserId}", 'INFO');
            }

            // Register DC user for each offering
            if (!empty($dcUser)) {
                $dcUserId = is_object($dcUser) ? $dcUser->id : $dcUser['id'];

                foreach ($dcProducts as $dcOfferingId => $wooItem) {
                    $itemName = is_object($wooItem) && method_exists($wooItem, 'get_name') ? $wooItem->get_name() : (isset($wooItem['name']) ? $wooItem['name'] : 'unknown');
                    $this->debug_log("Order #{$order_id}: Registering dcUserId={$dcUserId} for offeringId={$dcOfferingId} (product: '{$itemName}')", 'INFO');

                    $result = $this->makeApiV5Call("/dc/api/v5/registrations", "POST", array(
                        'userId' => $dcUserId,
                        'offeringId' => $dcOfferingId
                    ));

                    if ($result['api_result'] == 'success') {
                        $this->debug_log("Order #{$order_id}: Registration SUCCESS — dcUserId={$dcUserId}, offeringId={$dcOfferingId}", 'INFO');
                        $order->add_order_note('Success registering DigitalChalk user ' . $emailForLookup . ' to product ' . $itemName);
                        $this->dc_user_update_info($order_id, $dcUserId);
                    } else {
                        $httpStatus = isset($result['http_status_code']) ? $result['http_status_code'] : 'N/A';
                        $error = isset($result['error']) ? $result['error'] : 'unknown';
                        $errors = isset($result['errors']) ? json_encode($result['errors']) : '';

                        $this->debug_log("Order #{$order_id}: Registration FAILED — dcUserId={$dcUserId}, offeringId={$dcOfferingId}, HTTP={$httpStatus}, error={$error}, errors={$errors}", 'ERROR');
                        $this->debug_log("Order #{$order_id}: Full registration API result: " . json_encode($result), 'ERROR');

                        $order->add_order_note('Failed to register DigitalChalk user ' . $emailForLookup . ' to product ' . $itemName . ' (HTTP ' . $httpStatus . '). <a href="' . $resolveUrl . '">Resolve</a>');
                        $processResult = false;
                    }
                }
            }

            // Mark as processed if all succeeded
            if ($processResult) {
                add_post_meta($order_id, DCWOO_PROCESSED_META, 'yes') || update_post_meta($order_id, DCWOO_PROCESSED_META, 'yes');
                $this->debug_log("Order #{$order_id}: Marked DCWOO_PROCESSED_META='yes'", 'INFO');
            }

            $this->debug_log("=== mysite_completed END for order #{$order_id} — result=" . ($processResult ? 'SUCCESS' : 'FAILED') . " ===", 'INFO');
            return $processResult;
        }

        /**
         * Debug hook to trace all order status changes.
         */
        public function order_status_changed_debug($order_id, $old_status, $new_status, $order)
        {
            $this->debug_log("ORDER STATUS CHANGED: order #{$order_id} from '{$old_status}' to '{$new_status}'", 'INFO');
        }


        public function payment_complete_order_status_filter_step2($order_status, $order_id)
        {
            $this->debug_log("payment_complete_order_status_filter_step2 called for order #{$order_id}, incoming status='{$order_status}'", 'INFO');

            if ($order_status != 'completed') {
                $this->debug_log("payment_complete_order_status_filter_step2: order #{$order_id} status is not 'completed' — returning '{$order_status}' unchanged", 'INFO');
                return $order_status;
            }

            // Just pass through 'completed' status — actual DC registration is handled
            // by mysite_completed() on the woocommerce_order_status_completed action.
            // process_order() is a no-op so we should not mark orders as processed here.
            $this->debug_log("payment_complete_order_status_filter_step2: order #{$order_id} passing through 'completed' — registration will be handled by mysite_completed()", 'INFO');
            return $order_status;
        }

        public function check_cart_items_action()
        {
            // User is at the cart.  Check if they are logged in, and if they are
            // remove any products that are DC products that they cannot buy
            global $woocommerce;
            global $current_user;
            if (is_checkout()) {
                wp_get_current_user();
                if ($current_user->ID > 0) {

                    // Are there any DC products in the cart?
                    $dcProducts = array();
                    $cart = $woocommerce->cart;
                    foreach ($cart->cart_contents as $item_key => $product) {
                        $dcmeta = get_post_meta($product['product_id'], DCWOO_OFFERING_ID_META, true);
                        if (!empty($dcmeta)) {
                            array_push($dcProducts, $dcmeta);
                        }
                    }

                    if (count($dcProducts) > 0) {
                        // There are DC products in the cart, and the user is logged into Woo.  Check them against the API
                        $email = $current_user->user_email;
                        if (!empty($email)) {
                            $dcUser = $this->getDCUserByEmail($email);
                            if (!empty($dcUser)) {
                                $availableOfferings = $this->getAvailableOfferingsForUserId($dcUser->id);
                                if (!($availableOfferings === null)) { // check specifically for NULL, as the array *might* be empty.. later note: empty is OK, only === NULL is bad here
                                    $okToReg = array_intersect($availableOfferings, $dcProducts);
                                    $toRemove = array_diff($dcProducts, $okToReg);
                                    if (count($toRemove) > 0) {
                                        // There are offerings that the user cannot register for
                                        foreach ($cart->cart_contents as $item_key => $product) {
                                            $dcmeta = get_post_meta($product['product_id'], DCWOO_OFFERING_ID_META, true);
                                            if (!empty($dcmeta)) {
                                                if (in_array($dcmeta, $toRemove)) {
                                                    // Remove the product from the cart
                                                    $wooproduct = $woocommerce->product_factory->get_product($product['product_id']);
                                                    $woocommerce->cart->set_quantity($item_key, 0);
                                                    wc_add_notice('You cannot purchase item "' . $wooproduct->get_title() . '" at this time.  You may have purchased it previously.  We have removed the item from your cart.');
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }

                }
            }
        }

        public function payment_complete_order_status_filter($order_status, $order_id)
        {
            $this->debug_log("payment_complete_order_status_filter called for order #{$order_id}, incoming status='{$order_status}'", 'INFO');
            $order = new WC_Order($order_id);
            $new_order_status = $order_status;
            $current_status = $order->get_status();

            if ('processing' == $order_status &&
                ('on-hold' == $current_status || 'pending' == $current_status || 'failure' == $current_status)) {

                $new_order_status = 'completed';
                $this->debug_log("payment_complete_order_status_filter: order #{$order_id} qualifies for auto-complete (was '{$current_status}' -> 'processing' -> 'completed')", 'INFO');

                $userId = $order->get_customer_id();
                $user = new WP_User($userId);
                $items = $order->get_items();
                $productFactory = new WC_Product_Factory();
                foreach ($items as $item) {
                    $product = $productFactory->get_product($item['product_id']);
                    $meta = get_post_meta($item['product_id']);
                }
            }
            return $new_order_status;
        }

        //
        // Kick virtual-only orders to completed once they hit processing
        //
        public function all_payment_complete_order_status_filter($order_status, $order_id)
        {
            $order = new WC_Order($order_id);

            $virtual_order = null;
            $allowCompletion = false;
            $current_status = $order->get_status();

            if ('processing' == $order_status &&
                ('on-hold' == $current_status || 'pending' == $current_status || 'failure' == $current_status)) {

                if (count($order->get_items()) > 0) {
                    foreach ($order->get_items() as $item) {
                        if ('line_item' == $item['type']) {
                            $product = $item->get_product();
                            if (!$product->is_virtual()) {
                                $virtual_order = false;
                            } else {
                                $virtual_order = true;
                            }
                        }
                    }
                }
            }

            if ($virtual_order && $allowCompletion) {
                return 'completed';
            }

            return $order_status;
        }

        public function write_dc_product_panel_tabs()
        {
            ?>
<li class="dc_product_tab advanced_options"><a href="#dc_product_tab"><?php _e('DigitalChalk Product', 'dcwoo');?></a></li>
<?php
        }

        public function write_dc_product_panel()
        {
            ?>
<div id="dc_product_tab" class="panel woocommerce_options_panel">
Reserved for future use
</div>
<?php
        }


        public function display_add_dc_product_page()
        {
            $rmethod = $_SERVER['REQUEST_METHOD'];
            if (strtoupper($rmethod) == 'POST') {
                $offeringId = $_POST['offeringId'];
                if (!empty($offeringId)) {
                    $response = $this->makeApiV5Call("/dc/api/v5/offerings/" . $offeringId, "GET");
                    if (isset($response) && isset($response['api_result']) && $response['api_result'] == 'success') {
                        $offering = $response['results'][0];
                        if ($offering) {
                            $newPost = array();
                            $newPost['post_title'] = is_object($offering) ? $offering->title : $offering['title'];
                            $newPost['post_content'] = is_object($offering) ? ($offering->catalogDescription ?? '') : ($offering['catalogDescription'] ?? '');
                            $newPost['post_status'] = 'draft';
                            $newPost['post_author'] = get_current_user_id();
                            $newPost['post_type'] = 'product'; // a woocommerce product type
                            $newId = wp_insert_post($newPost, true);
                            if (!is_wp_error($newId) && $newId > 0) {
                                $offeringPrice = is_object($offering) ? ($offering->price ?? 0) : ($offering['price'] ?? 0);
                                if (empty($offeringPrice)) {
                                    $offeringPrice = 0;
                                }
                                $offeringId_value = is_object($offering) ? $offering->id : $offering['id'];
                                add_post_meta($newId, '_virtual', 'yes', true) || update_post_meta($newId, '_virtual', 'yes');
                                add_post_meta($newId, '_sold_individually', 'yes', true) || update_post_meta($newId, '_sold_individually', 'yes');
                                add_post_meta($newId, '_regular_price', number_format($offeringPrice, 2), true) || update_post_meta($newId, '_regular_price', number_format($offeringPrice, 2));
                                add_post_meta($newId, '_price', number_format($offeringPrice, 2), true) || update_post_meta($newId, '_price', number_format($offeringPrice, 2));
                                add_post_meta($newId, DCWOO_OFFERING_ID_META, $offeringId_value, true) || update_post_meta($newId, DCWOO_OFFERING_ID_META, $offeringId_value);

                                // purchase notes show up on the view order page (after purchase only)
                                $offeringUrl = 'https://' . get_option('dcwoo_hostname') . '/dc/student/course/' . $offeringId . '/deliver';
                                add_post_meta($newId, '_purchase_note', 'Your course is located <a href="' . $offeringUrl . '">here</a>.', true);

                                $this->display_create_dc_product_success($newId);

                            } else {
                                $this->display_offerings_page();
                            }
                        } else {
                            $this->display_offerings_page();
                        }
                    } else {
                        $this->display_offerings_page();
                    }
                } else {
                    $this->display_offerings_page();
                }
            } else {
                $this->display_offerings_page();
            }
        }

        public function display_create_dc_product_success($newId)
        {
            $newEdit = get_admin_url() . 'post.php?post=' . $newId . '&action=edit';
            ?>
<div class="wrap">
<h2><?php esc_html_e('Success Creating Product', 'dcwoo');?></h2>
<div>
	<a href="<?php echo $newEdit ?>">Click here to edit it</a>
</div>
<div>
Your new product is currently in DRAFT mode.  You must publish it before it will be available for purchase.
</div>
</div>
<?php
        }

        public function display_settings_page()
        {
            include DCWOO_ABSPATH . '/includes/displayApiSettings.php';
        }

        public function display_offerings_page()
        {
            include DCWOO_ABSPATH . '/includes/listOfferings.php';
        }

        public function getAvailableOfferingsAjax($offset = 0, $filter = null)
        {
            $dataToSend = array();
            if ($offset > 0) {
                $dataToSend['offset'] = $offset;
            }
            if (!empty($filter)) {
                $dataToSend['title'] = $filter;
            }
            $response = $this->makeAPIV5Call('/dc/api/v5/offerings', 'GET', $dataToSend);

            return $response;
        }

        public function get_dc_offerings_callback()
        {
            $offset = $_POST['offset'];
            $filter = $_POST['filter'];
            if (empty($offset)) {
                $offset = 0;
            }
            $response = $this->getAvailableOfferingsAjax($offset, $filter);

            echo json_encode($response);
            die(); // required by wp ajax
        }

        //----------------------------------------------------------------------------
        //
        // API v5 COMMUNICATIONS ROUTINES
        //
        //----------------------------------------------------------------------------

        public function makeApiV5Call($path, $method, $dataToSend = null)
        {

            $token = get_option('dcwoo_token');
            $hostname = get_option('dcwoo_hostname');
            if (!$token) {
                $this->debug_log("makeApiV5Call: No token configured — API call to '{$path}' aborted", 'ERROR');
                return null;
            }
            if (!$hostname) {
                $this->debug_log("makeApiV5Call: No hostname configured — API call to '{$path}' aborted", 'ERROR');
                return null;
            }

            $url = 'https://' . $hostname . $path;
            $this->debug_log("makeApiV5Call: {$method} {$url}" . ($dataToSend ? " payload=" . json_encode($dataToSend) : ''), 'INFO');

            if (strtoupper($method) == 'GET') {
                if ($dataToSend) {
                    $url .= '?' . http_build_query($dataToSend);
                }
            }

            try {
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                curl_setopt($ch, CURLOPT_HEADER, 1);
                if (strtoupper($method) == 'POST' || strtoupper($method) == "PUT") {
                    if ($dataToSend) {
                        $jsonToSend = json_encode($dataToSend);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonToSend);
                        $this->debug_log("makeApiV5Call: POST/PUT body: {$jsonToSend}", 'INFO');
                    }
                }
                // The following two lines allow self-signed and wildcard SSL certificates
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Authorization: Bearer ' . $token)
                );

                $curlResult = curl_exec($ch);

                $result = array();
                $result['api_request_url'] = $url;

                if ($curlResult == false) {
                    $curlError = curl_error($ch);
                    $curlErrno = curl_errno($ch);
                    $result['error'] = $curlError;
                    $result['api_result'] = 'failed';
                    $this->debug_log("makeApiV5Call: cURL FAILED for {$method} {$url} — errno={$curlErrno}, error='{$curlError}'", 'ERROR');
                } else {
                    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                    $headerpart = substr($curlResult, 0, $header_size);
                    $body = substr($curlResult, $header_size);
                    $result['response_headers'] = $this->http_parse_headers($headerpart);

                    if ($body) {
                        try {
                            $bodyJson = json_decode($body);

                            if ($bodyJson === null && json_last_error() !== JSON_ERROR_NONE) {
                                $this->debug_log("makeApiV5Call: JSON decode FAILED for {$method} {$url} — json_error='" . json_last_error_msg() . "', raw body (first 500 chars): " . substr($body, 0, 500), 'ERROR');
                            }

                            $result['body'] = $bodyJson;
                            if (isset($bodyJson->error)) {
                                $result['error'] = $bodyJson->error;
                            }
                            if (isset($bodyJson->error_description)) {
                                $result['error_description'] = $bodyJson->error_description;
                            }
                            if (isset($bodyJson->errors)) {
                                $result['errors'] = (array) $bodyJson->errors;
                            }
                            if (isset($bodyJson->fieldErrors)) {
                                $result['fieldErrors'] = (array) $bodyJson->fieldErrors;
                            }
                            if (isset($bodyJson->results)) {
                                $result['results'] = (array) $bodyJson->results;
                            }
                            if (isset($bodyJson->previous)) {
                                $result['previous'] = $bodyJson->previous;
                                $prevParts = explode('?', $bodyJson->previous);
                                if (count($prevParts) > 1) {
                                    parse_str($prevParts[1], $qarray);
                                    if (array_key_exists("offset", $qarray)) {
                                        $result['prevOffset'] = $qarray['offset'];
                                    }
                                }
                            }
                            if (isset($bodyJson->next)) {
                                $result['next'] = $bodyJson->next;
                                $nextParts = explode('?', $bodyJson->next);
                                if (count($nextParts) > 1) {
                                    parse_str($nextParts[1], $qarray);
                                    if (array_key_exists("offset", $qarray)) {
                                        $result['nextOffset'] = $qarray['offset'];
                                    }
                                }
                            }
                        } catch (Exception $e) {
                            $result['bodyexception'] = $e;
                            $this->debug_log("makeApiV5Call: Exception parsing response body for {$method} {$url}: " . $e->getMessage(), 'ERROR');
                        }
                    } else {
                        $this->debug_log("makeApiV5Call: Empty response body for {$method} {$url}", 'WARN');
                    }
                }

                $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $result['http_status_code'] = $httpStatus;

                switch (strtoupper($method)) {
                    case 'POST':
                        if ($httpStatus == '201') {
                            $result['api_result'] = 'success';
                        } else {
                            $result['api_result'] = 'failed';
                        }
                        break;
                    case 'PUT':
                    case 'DELETE':
                        if ($httpStatus == '204') {
                            $result['api_result'] = 'success';
                        } else {
                            $result['api_result'] = 'failed';
                        }
                        break;
                    default:
                        if ($httpStatus == '200') {
                            $result['api_result'] = 'success';
                        } else {
                            $result['api_result'] = 'failed';
                        }
                }

                $this->debug_log("makeApiV5Call: {$method} {$url} — HTTP {$httpStatus}, api_result='{$result['api_result']}'" . (isset($result['error']) ? ", error='{$result['error']}'" : ''), ($result['api_result'] == 'success' ? 'INFO' : 'ERROR'));

                // result vs results
                if ($result['api_result'] == 'success' && !isset($result['results']) && isset($result['body'])) {
                    $result['results'] = array();
                    $result['results'][] = (array) $result['body'];
                }

                unset($result['body']);

                curl_close($ch);
                return $result;
            } catch (Exception $curlEx) {
                $this->debug_log("makeApiV5Call: EXCEPTION during {$method} {$url}: " . $curlEx->getMessage(), 'ERROR');
                $result = array();
                $result['api_result'] = 'failed';
                $result['error'] = $curlEx->getMessage();
                return $result;
            }
        }


        public function getDCUserByEmail($email)
        {
            $this->debug_log("getDCUserByEmail: Looking up email='{$email}'", 'INFO');
            if (empty($email)) {
                $this->debug_log("getDCUserByEmail: Empty email provided — returning null", 'WARN');
                return null;
            }
            $user = null;
            $result = $this->makeApiV5Call("/dc/api/v5/users", "GET", array('email' => $email));
            if ($result === null) {
                $this->debug_log("getDCUserByEmail: API call returned null (likely missing token/hostname)", 'ERROR');
                return null;
            }
            if ($result['api_result'] == 'success') {
                if (isset($result['results']) && count($result['results']) > 0) {
                    $user = $result['results'][0];
                    $userId = is_object($user) ? $user->id : (is_array($user) ? $user['id'] : 'unknown');
                    $this->debug_log("getDCUserByEmail: Found DC user for '{$email}' — dcUserId={$userId}", 'INFO');
                } else {
                    $this->debug_log("getDCUserByEmail: API success but no results for '{$email}' — user does not exist in DC", 'INFO');
                }
            } else {
                $httpStatus = isset($result['http_status_code']) ? $result['http_status_code'] : 'N/A';
                $error = isset($result['error']) ? $result['error'] : 'unknown';
                $this->debug_log("getDCUserByEmail: API FAILED for '{$email}' — HTTP={$httpStatus}, error={$error}", 'ERROR');
            }
            return $user;
        }

        public function getAvailableOfferingsForUserId($dcUserId)
        {
            if (empty($dcUserId)) {
                return null;
            }
            $result = $this->makeApiV5Call("/dc/api/v5/offerings?limit=100", "GET", array('userId' => $dcUserId));
            if ($result['api_result'] == 'success') {
                $offeringIds = array();
                foreach ($result['results'] as $oneOffering) {
                    array_push($offeringIds, $oneOffering->id);
                }
                return $offeringIds;
            } else {
                return null;
            }
        }

        // Update User Info
        public function dc_user_update_info($orderid, $dcuser_id)
        {
            $this->debug_log("dc_user_update_info: Updating user fields for dcUserId={$dcuser_id}, orderId={$orderid}", 'INFO');
            global $woocommerce;
            $token = get_option('dcwoo_token');
            $hostname = get_option('dcwoo_hostname');

            $url = 'https://' . $hostname;

            $curl2 = curl_init();

            curl_setopt_array($curl2, array(
                CURLOPT_URL => $url . "/dc/api/v5/userfields",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => "GET",
                CURLOPT_HTTPHEADER => array(
                    "Authorization: Bearer " . $token,
                    "Content-Type:  application/json",
                ),
            ));

            $response = curl_exec($curl2);

            if ($response === false) {
                $this->debug_log("dc_user_update_info: cURL FAILED fetching userfields — " . curl_error($curl2), 'ERROR');
                curl_close($curl2);
                return;
            }

            curl_close($curl2);
            $res = json_decode($response, true);

            if (!isset($res['results'])) {
                $this->debug_log("dc_user_update_info: No 'results' in userfields response — raw response: " . substr($response, 0, 500), 'ERROR');
                return;
            }

            $phone = $street1 = $street2 = $state = $pcode = $city = null;
            foreach ($res['results'] as $ufild) {
                $fieldName = isset($ufild['name']) ? $ufild['name'] : '';
                if ($fieldName == "Phone Number") {
                    $phone = $ufild['id'];
                }
                if ($fieldName == "Street 1") {
                    $street1 = $ufild['id'];
                }
                if ($fieldName == "Street 2") {
                    $street2 = $ufild['id'];
                }
                if ($fieldName == "State/Province") {
                    $state = $ufild['id'];
                }
                if ($fieldName == "Postal Code") {
                    $pcode = $ufild['id'];
                }
                if ($fieldName == "City") {
                    $city = $ufild['id'];
                }
            }

            $cssoid = $dcuser_id;
            $order = wc_get_order($orderid);
            $user_info = array($phone => $order->get_billing_phone(), $street1 => $order->get_billing_address_1(), $street2 => $order->get_billing_address_2(), $state => $order->get_billing_state(), $pcode => $order->get_billing_postcode(), $city => $order->get_billing_city());

            $curl3 = curl_init();

            curl_setopt_array($curl3, array(
                CURLOPT_URL => $url . "/dc/api/v5/users/" . $cssoid . "/userfieldvalues",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => "PUT",
                CURLOPT_POSTFIELDS => json_encode($user_info),
                CURLOPT_HTTPHEADER => array(
                    "Authorization: Bearer " . $token,
                    "Content-Type:  application/json",
                    "Accept:  application/json",
                ),
            ));

            $response = curl_exec($curl3);

            if ($response === false) {
                $this->debug_log("dc_user_update_info: cURL FAILED updating userfieldvalues for dcUserId={$dcuser_id} — " . curl_error($curl3), 'ERROR');
            } else {
                $httpCode = curl_getinfo($curl3, CURLINFO_HTTP_CODE);
                $this->debug_log("dc_user_update_info: Updated userfieldvalues for dcUserId={$dcuser_id} — HTTP {$httpCode}", ($httpCode == 204 ? 'INFO' : 'WARN'));
            }

            curl_close($curl3);
        }

        public function getFilteredOfferings($filter = null)
        {
            $offerings = array();
            try {

                do {
                    $dataToSend = array();
                    if (isset($result) && isset($result['nextOffset']) && $result['nextOffset']) {
                        $dataToSend['offset'] = $result['nextOffset'];
                    }
                    $result = array(); // clear it out
                    $result = $this->makeApiV5Call("/dc/api/v5/offerings", "GET", $dataToSend);
                    if ($result['api_result'] == 'success') {
                        $offerings = array_merge($offerings, $result['results']);
                    } else {
                        // if api_result != success, just return the result
                        return $result;
                    }
                } while (!empty($result['nextOffset']));

                $result = array();
                $result['api_result'] = 'success';
                $sortfunction = create_function('$a,$b', 'if($a->title == $b->title) {return 0;} else {return ($a->title < $b->title) ? -1 : 1;}');
                usort($offerings, $sortfunction);
                $result['results'] = $offerings;
                return $result;

            } catch (Exception $apiEx) {
                $result = array();
                $result['api_result'] = 'failure';
                $result['error'] = 'Unknown error';
                $result['error_description'] = $apiEx->getMessage();
                return $result;
            }
        }

        //----------------------------------------------------------------------------
        //
        // LOGGING
        //
        //----------------------------------------------------------------------------

        public function debug_log($message, $level = 'DEBUG')
        {
            // Always log errors; only log INFO/DEBUG/WARN when WP_DEBUG is on
            if ($level !== 'ERROR' && (!defined('WP_DEBUG') || WP_DEBUG !== true)) {
                return;
            }
            $prefix = '[DCWOO][' . $level . '] ';
            if (is_array($message) || is_object($message)) {
                error_log($prefix . print_r($message, true));
            } else {
                error_log($prefix . $message);
            }
        }

        // ---------------------------------------------------------------------------
        //
        // UTILITY FUNCTIONS
        //
        // ---------------------------------------------------------------------------

        public function http_parse_headers($header)
        {
            $retVal = array();
            $fields = explode("\r\n", preg_replace('/\x0D\x0A[\x09\x20]+/', ' ', $header));
            foreach ($fields as $field) {
                if (preg_match('/([^:]+): (.+)/m', $field, $match)) {
                    $match[1] = preg_replace_callback('/(?<=^|[\x09\x20\x2D])./', function($m) { return strtoupper($m[0]); }, strtolower(trim($match[1])));
                    if (isset($retVal[$match[1]])) {
                        $retVal[$match[1]] = array($retVal[$match[1]], $match[2]);
                    } else {
                        $retVal[$match[1]] = trim($match[2]);
                    }
                }
            }
            return $retVal;
        }

        // ---------------------------------------------------------------------------
        //
        // PLUGIN ACTIVATION ROUTINES
        //
        // ---------------------------------------------------------------------------
        public static function activate()
        {
            add_option('dcwoo_hostname');
            add_option('dcwoo_token');
        }

        public static function deactivate()
        {
            // These are commented out because if you deactivate the plugin you lose these settings otherwise
            //delete_option('dcwoo_hostname');
            //delete_option('dcwoo_token');
        }

    } // end class definition
} // end if class exists
?>
