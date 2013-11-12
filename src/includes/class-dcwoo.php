<?php
if (!class_exists("DCWOO")) {
	
	define('DCWOO_OFFERING_ID_META', '_dc_offering_id');
	define('DCWOO_PROCESSED_META', '_dc_processed');
	
	class DCWOO {

		private $last_api_error = NULL;
		
		function DCWOO() {
			$this->__construct();
		}

		function __construct() {
			if(is_admin()) {
				
				// wp-admin
				
				add_action('admin_menu', array($this, 'init_admin_menu'));
				add_action('wp_ajax_get_dc_offerings', array($this,'get_dc_offerings_callback'));
				add_action('wp_ajax_resolve_order', array($this, 'resolve_order_ajax'));
				
			} else {
				
				// frontend - storefront actions only
				add_action('woocommerce_check_cart_items', array($this, 'check_cart_items_action'));
				add_filter('woocommerce_add_to_cart_validation', array($this, 'add_to_cart_validation_filter'), 10, 3);
				
			}
			
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
		
		function init_admin_menu() {
			
			add_options_page('DigitalChalk API v5 Settings', 'DigitalChalk - WooCommerce Integration','manage_options','dcwoo_options', array($this, 'display_settings_page'));
			// add_submenu_page('woocommerce', 'DigitalChalk v5 Offerings','DigitalChalk v5 Offerings', 'manage_options', 'dcwoo_offerings', array($this, 'display_offerings_page'));
			//add_menu_page('DigitalChalk v5 Offerings', 'DigitalChalk v5 Offerings', 'manage_options', 'dcwoo_offerings', array($this, 'display_offerings_page'), plugin_dir_url( __FILE__ ) . '/images/icon-courseoffering-med.png', '58.2');
			add_submenu_page( 'edit.php?post_type=product', 'Add DigitalChalk Product', 'Add DigitalChalk Product', 'manage_options', 'add_dc_product', array($this, 'display_add_dc_product_page'));
			//woocommerce_product_write_panel_tabs
			//add_action('woocommerce_product_write_panel_tabs', array($this, 'write_dc_product_panel_tabs'));
			//add_action('woocommerce_product_write_panels', array($this, 'write_dc_product_panel'));
			
			//  http://codex.wordpress.org/Function_Reference/add_submenu_page
			//  says that options.php hides this submenu from all menus
			add_submenu_page('options.php', 'Resolve Issue', 'Resolve Issue', 'manage_options', 'resolve_issue', array($this, 'display_resolve_issue'));
			
		}
		
		function display_resolve_issue() {
			$order_id = $_REQUEST['order_id'];
			include  DCWOO_ABSPATH . '/includes/resolveOrderIssue.php';	 
		}
		
		function resolve_order_ajax() {
			$order_id = $_REQUEST['order_id'];	
			$order = new WC_Order( $order_id );
			$response = array();
			if($this->process_order($order_id, true)) {
				add_post_meta($order_id, DCWOO_PROCESSED_META, 'yes') || update_post_meta($order_id, DCWOO_PROCESSED_META, 'yes');
				
				$order->add_order_note("Issue resolution was successful!");
				if($order->status != 'completed') {
					$order->update_status('completed');
				}
				$response['process_result'] = 'true';
			} else {
				$order->add_order_note("Retry of processing failed.");
				$response['process_result'] = 'false';
			}
			
			
			echo json_encode($response);
			die;  // required by wp-ajax.. see the wp codex
		}
		
		function add_to_cart_validation_filter( $valid, $product_id, $quantity) {
			global $woocommerce;
			global $current_user;
			if($current_user->ID > 0) {
				$dcMeta = get_post_meta( $product_id, DCWOO_OFFERING_ID_META, true);
				if(!empty($dcMeta)) {
					$dcUser = $this->getDCUserByEmail($current_user->user_email);
					if(!empty($dcUser)) {
						$availableOfferings = $this->getAvailableOfferingsForUserId($dcUser->id);
						if($availableOfferings != NULL) {
							if(!in_array($dcMeta, $availableOfferings)) {
								$woocommerce->add_error(__('You cannot purchase this item at this time.  You may have purchased it previously.', 'dcwoo'));
								return false;
							}
						}
					}
				}
				
			}
			return true;
		}
		
		function process_order($order_id, $isRetry = false) {
			global $woocommerce;
			$processResult = true;
			$resolveUrl = admin_url('options.php?page=resolve_issue&order_id=' . $order_id);  // added to order notes
			$order = new WC_Order( $order_id );
			if(!$order) {
				return false;
			}
			
			// Check if any DC items are present....
			$dcProducts = array();
			$items = $order->get_items();
			foreach($items as $item) {
				$dcMeta = get_post_meta($item['product_id'], DCWOO_OFFERING_ID_META, true);
				if(!empty($dcMeta)) {
					$dcProducts[$dcMeta] = $item;
				}
			}
			
			if(count($dcProducts) > 0) {
				$wpUser = new WP_User($order->customer_user);
				$dcUser = $this->getDCUserByEmail($wpUser->user_email);
				if(empty($dcUser)) {
					// Create user
					$result = $this->makeApiV5Call("/dc/api/v5/users", "POST", array( 'firstName' => $wpUser->first_name, 'lastName' => $wpUser->last_name, 'email' => $wpUser->user_email, 'password' => '***1UNPARSEABLE2***' ));
					if($result['api_result'] == 'success') {
						$order->add_order_note("Created new DC user with email '" . $wpUser->user_email . "'.");
						$dcUser = $this->getDCUserByEmail($wpUser->user_email);
					} else {
						$order->add_order_note("Tried to add user with email '" . $wpUser->user_email . "' to DigitalChalk and failed.  Therefore, no registrations were done from this order. <a href='" . $resolveUrl . "'>Resolve</a>");
						$processResult = false;
						//$order_status = 'processing';
					}
				}
				if(!empty($dcUser)) {
			
					foreach($dcProducts as $dcOfferingId => $wooItem) {
							
						$result = $this->makeApiV5Call("/dc/api/v5/registrations", "POST", array('studentId' => $dcUser->id, 'offeringId' => $dcOfferingId));
						if($result['api_result'] == 'success') {
							$order->add_order_note('Success registering DigitalChalk user ' . $wpUser->user_email . ' to product ' . $wooItem['name']);
						} else {
							$order->add_order_note('Failed to register DigitalChalk user ' . $wpUser->user_email . ' to product ' . $wooItem['name'] . '. <a href="' . $resolveUrl . '">Resolve</a>' );
							$processResult = false;
							//$order_status = 'processing';
						}
							
					}
				}
			}			
			
			return $processResult;
		}
		
		
		
		function payment_complete_order_status_filter_step2($order_status, $order_id) {
			
			if($order_status != 'completed') {
				return $order_status;
			}

			$processedMeta = get_post_meta($order_id, DCWOO_PROCESSED_META, true );
			if(empty($processedMeta)  || $processedMeta != 'yes') {
				
				if($this->process_order($order_id)) {
					add_post_meta($order_id, DCWOO_PROCESSED_META, 'yes') || update_post_meta($order_id, DCWOO_PROCESSED_META, 'yes');
				} else {
					$order_status = 'processing';
				}
			}
			return $order_status;
			
		}
		
		function check_cart_items_action() {
			// User is at the cart.  Check if they are logged in, and if they are
			// remove any products that are DC products that they cannot buy
			global $woocommerce;
			global $current_user;
			if(is_checkout()) {
				wp_get_current_user();
				if($current_user->ID > 0) {
					
					// Are there any DC products in the cart?
					$dcProducts = array();
					$cart = $woocommerce->cart;
					foreach($cart->cart_contents as $item_key => $product) {						
						$dcmeta = get_post_meta($product['product_id'], DCWOO_OFFERING_ID_META, true);
						if(!empty($dcmeta)) {
							array_push($dcProducts, $dcmeta);
						}						
					}
					
					if(count($dcProducts) > 0) {
						// There are DC products in the cart, and the user is logged into Woo.  Check them against the API
						$email = $current_user->user_email;
						if(!empty($email)) {
							$dcUser = $this->getDCUserByEmail($email);
							if(!empty($dcUser)) {
								$availableOfferings = $this->getAvailableOfferingsForUserId($dcUser->id);
								if($availableOfferings != NULL) {   // check specifically for NULL, as the array *might* be empty
									$okToReg = array_intersect($availableOfferings, $dcProducts);
									$toRemove = array_diff($dcProducts, $okToReg);
									if(count($toRemove) > 0) {
										// There are offerings that the user cannot register for
										foreach($cart->cart_contents as $item_key => $product) {
											$dcmeta = get_post_meta($product['product_id'], DCWOO_OFFERING_ID_META, true);
											if(!empty($dcmeta)) {
												if(in_array($dcmeta, $toRemove)) {
													// Remove the product from the cart
													$wooproduct = $woocommerce->product_factory->get_product($product['product_id']);
													$woocommerce->cart->set_quantity($item_key, 0);
													$woocommerce->add_error('You cannot purchase item "' . $wooproduct->get_title() . '" at this time.  You may have purchased it previously.  We have removed the item from your cart.');
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
		
		function payment_complete_order_status_filter($order_status, $order_id) {
			$order = new WC_Order( $order_id );
			if( 'processing' == $order_status &&
					('on-hold' == $order->status || 'pending' == $order->status || 'failure' == $order->status)) {
				
				$new_order_status = 'completed';
				
				$userId = $order->customer_user;
				$user = new WP_User($userId);
				$items = $order->get_items();
				$productFactory = new WC_Product_Factory();
				foreach($items as $item) {
					
					$product = $productFactory->get_product($item['product_id']);
					$meta = get_post_meta($item['product_id']);
					
					// Does User exist?
					
					// If not, create
					// If can't, on-hold
					// Register user for offering
					// If can't on-hold
					$foo = 1;
					
				}
			}
			return $new_order_status;
		}
		
		//
		// Kick virtual-only orders to completed once they hit processing
		//
		function all_payment_complete_order_status_filter($order_status, $order_id) {
			$order = new WC_Order( $order_id );
			
			if( 'processing' == $order_status &&
				('on-hold' == $order->status || 'pending' == $order->status || 'failure' == $order->status)) {
				
				$virtual_order = null;
				
				if(count( $order->get_items() ) > 0 ) {
					foreach($order->get_items() as $item) {
						if('line_item' == $item['type']) {
							$product = $order->get_product_from_item($item);
							if( !$product->is_virtual()) {
								$virtual_order = false;
							} else {
								$virtual_order = true;
							}
						}
					}
				}				
			}
			
			if($virtual_order && $allowCompletion) {
				return 'completed';
			}
		}
		
		function write_dc_product_panel_tabs() {
?>
<li class="dc_product_tab advanced_options"><a href="#dc_product_tab"><?php _e( 'DigitalChalk Product', 'dcwoo' ); ?></a></li>
<?php 
		}
		
		function write_dc_product_panel() {
?>
<div id="dc_product_tab" class="panel woocommerce_options_panel">
Reserved for future use
</div>
<?php 
		}
		
		function display_add_dc_product_page() {
			$rmethod = $_SERVER['REQUEST_METHOD'];
			if(strtoupper($rmethod) == 'POST') {
				$offeringId = $_POST['offeringId'];
				if(!empty($offeringId)) {
					$response = $this->makeApiV5Call("/dc/api/v5/offerings/" . $offeringId, "GET");
					if(isset($response) && isset($response['api_result']) && $response['api_result'] == 'success') {
						$offering = $response['results'][0];
						if($offering) {
							$newPost = array();
							$newPost['post_title'] = $offering['title'];
							$newPost['post_content'] = $offering['catalogDescription'];
							$newPost['post_status'] = 'draft';
							$newPost['post_author'] = get_current_user_id();
							$newPost['post_type'] = 'product'; // a woocommerce product type				
							$newId = wp_insert_post($newPost, $wp_error);
							if($newId > 0) {
								if(empty($offering['price'])) {
									$offering['price'] = 0;
								}
								add_post_meta($newId, '_virtual', 'yes', true) || update_post_meta($newId, '_virtual', 'yes');
								add_post_meta($newId, '_sold_individually', 'yes', true) || update_post_meta($newId, '_sold_individually', 'yes');
								add_post_meta($newId, '_regular_price', number_format($offering['price'],2), true) || update_post_meta($newId, '_regular_price', number_format($offering['price'],2));
								add_post_meta($newId, '_price', number_format($offering['price'],2), true) || update_post_meta($newId, '_price', number_format($offering['price'],2));
								add_post_meta($newId, DCWOO_OFFERING_ID_META, $offering['id'], true) || update_post_meta($newId, DCWOO_OFFERING_ID_META, $offering->id);
								
								// purchase notes show up on the view order page (after purchase only)
								$offeringUrl = 'https://' . get_option('dcwoo_hostname') . '/dc/student/course/' . $offeringId . '/deliver';
								add_post_meta($newId, '_purchase_note', 'Your course is located <a href="' . $offeringUrl . '">here</a>.', true);
								
								wp_safe_redirect('post.php?post=' . $newId . '&action=edit');
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
		
		function display_settings_page() {
			include  DCWOO_ABSPATH . '/includes/displayApiSettings.php';			
		}  
		
		
		function display_offerings_page() {
			include DCWOO_ABSPATH . '/includes/listOfferings.php';
		}  
		
		function getAvailableOfferingsAjax($offset = 0, $filter = NULL) {
			$dataToSend = array();
			//$dataToSend['limit'] = 2;  // debug only
			if($offset > 0) {
				$dataToSend['offset'] = $offset;
			}
			if(!empty($filter)) {
				$dataToSend['title'] = $filter;
			}
			$response = $this->makeAPIV5Call('/dc/api/v5/offerings', 'GET', $dataToSend);			

			return $response;
		}
		
		function get_dc_offerings_callback() {
			$offset = $_POST['offset'];
			$filter = $_POST['filter'];
			if(empty($offset)) {
				$offset = 0;
			}			
			$response = $this->getAvailableOfferingsAjax($offset, $filter);

			echo json_encode($response);
			die();  // required by wp ajax
		}
		
		//----------------------------------------------------------------------------
		//
		// API v5 COMMUNICATIONS ROUTINES
		//
		//----------------------------------------------------------------------------

		function makeApiV5Call($path, $method, $dataToSend = NULL) {
			
			$token = get_option('dcwoo_token');
			$hostname = get_option('dcwoo_hostname');
			if(!$token) {
				$this->debug_log("No token is set in the settings.  DC API v5 call failed");
				return NULL;
			}
			if(!$hostname) {
				$this->debug_log("No hostname is set in the settings.  DC API v5 call failed");
				return NULL;				
			}
			
			$url = 'https://' . $hostname . $path;
			$this->debug_log("Making API v5 call to " . $url);
			
			if(strtoupper($method) == 'GET') {
				if($dataToSend) {
					$url .= '?' . http_build_query($dataToSend);
				}
			}
			
			
			
			try {
				$ch = curl_init($url);
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
				curl_setopt($ch, CURLOPT_HEADER, 1);
				if(strtoupper($method) == 'POST' || strtoupper($method) == "PUT") {
					if($dataToSend) {
						$jsonToSend = json_encode($dataToSend);
						curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonToSend);
					}
				}
				// The following two lines allow self-signed and wildcard SSL certificates
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
				curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
				
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // since we are separating the headers and body anyway
				
				curl_setopt($ch, CURLOPT_HTTPHEADER, array(
				'Content-Type: application/json',
				'Accept: application/json',
				'Authorization: Bearer ' . $token)
				);
				
				$curlResult = curl_exec($ch);
				
				$result = array();
				$result['api_request_url'] = $url;
				
				if($curlResult == FALSE) {
					$result['error'] = curl_error($ch);
					$result['api_result'] = 'failed';
				} else {
					$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
					$headerpart = substr($curlResult, 0, $header_size);
					$body = substr($curlResult, $header_size);
					$result['response_headers'] = $this->http_parse_headers($headerpart);	

					if($body) {
						try {
							$bodyJson = json_decode($body);
							$result['body'] = $bodyJson;
							if(isset($bodyJson->error)) {
								$result['error'] = $bodyJson->error;
							}
							if(isset($bodyJson->error_description)) {
								$result['error_description'] = $bodyJson->error_description;
							}
							if(isset($bodyJson->errors)) {
								$result['errors'] = (array)$bodyJson->errors;
							}
							if(isset($bodyJson->fieldErrors)) {
								$result['fieldErrors'] = (array)$bodyJson->fieldErrors;
							}
							if(isset($bodyJson->results)) {
								$result['results'] = (array)$bodyJson->results;
							}
							if(isset($bodyJson->previous)) {
								$result['previous'] = $bodyJson->previous;
								$prevParts = explode('?', $bodyJson->previous);
								if(count($prevParts) > 1) {
									parse_str($prevParts[1], $qarray);
									if(array_key_exists("offset", $qarray)) {
										$result['prevOffset'] = $qarray['offset'];
									}
								}
							}
							if(isset($bodyJson->next)) {
								$result['next'] = $bodyJson->next;
								$nextParts = explode('?', $bodyJson->next);
								if(count($nextParts) > 1) {
									parse_str($nextParts[1], $qarray);
									if(array_key_exists("offset", $qarray)) {
										$result['nextOffset'] = $qarray['offset'];
									}
								}
							}
						} catch(Exception $e) {
							$result['bodyexception'] = $e;
						}
					}
				}
				
				
				$httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
				$result['http_status_code'] = $httpStatus;
				
				switch(strtoupper($method)) {
					case 'POST':
						if($httpStatus == '201') {
							$result['api_result'] = 'success';
						} else {
							$result['api_result'] = 'failed';
						}
						break;
					case 'PUT':
					case 'DELETE':
						if($httpStatus == '204') {
							$result['api_result'] = 'success';
						} else {
							$result['api_result'] = 'failed';
						}
						break;												
					default:
						if($httpStatus == '200') {
							$result['api_result'] = 'success';
						} else {
							$result['api_result'] = 'failed';
						}						
				}
				
				// result vs results
				if($result['api_result'] == 'success' && !isset($result['results']) && isset($result['body'])) {
					$result['results'] = array();
					$result['results'][] = (array)$result['body'];
				}
				
				unset($result['body']);
				
				return $result;			
			} catch(Exception $curlEx) {
				$this->debug_log("An exception occurred during API v5 call: " . $curlEx->getMessage());
				$result = array();
				$result['api_result'] = 'failed';
				$result['error'] = $curlEx->getMessage();
				return $result;
			}
		}
		
		
		function getDCUserByEmail($email) {
			if(empty($email)) {
				return NULL;
			}
			$user = NULL;
			$result = $this->makeApiV5Call("/dc/api/v5/users", "GET", array('email' => $email));
			if($result['api_result'] == 'success') {
				if(isset($result['results'])) {
					$user = $result['results'][0];
				}
			}
			return $user;
		}
		
		function getAvailableOfferingsForUserId($dcUserId) {
			if(empty($dcUserId)) {
				return NULL;
			}
			$result = $this->makeApiV5Call("/dc/api/v5/offerings", "GET", array('studentId' => $dcUserId ));
			if($result['api_result'] == 'success') {
				$offeringIds = array();
				foreach($result['results'] as $oneOffering) {
					array_push($offeringIds, $oneOffering->id);
				}
				return $offeringIds;
			} else {
				return NULL;
			}
		}
		
		function getFilteredOfferings($filter = NULL) {
			$offerings = array();
			try {
				
				do {
					$dataToSend = array();
					if($result['nextOffset']) {
						$dataToSend['offset'] = $result['nextOffset'];
					}					
					$result = array();  // clear it out
					$result = $this->makeApiV5Call("/dc/api/v5/offerings", "GET", $dataToSend);
					if($result['api_result'] == 'success') {
						$offerings = array_merge($offerings, $result['results']);
					} else {
						// if api_result != success, just return the result
						return $result;
					}
				} while(!empty($result['nextOffset']));
				
				$result = array();
				$result['api_result'] = 'success';
				usort($offerings, function($a,$b) {
					if($a->title == $b->title) {
						return 0;
					}
					return ($a->title < $b->title) ? -1 : 1;
				});
				$result['results'] = $offerings;
				return $result;
				
			} catch(Exception $apiEx) {
				$result = array();
				$result['api_result'] = 'failure';
				$result['error'] = 'Unknown error';
				$result['error_description'] = $apiEx->getMessage();
				return $result;
			}
		}
			
		//----------------------------------------------------------------------------
		//
		// WORDPRESS MENU ROUTINES
		//
		//----------------------------------------------------------------------------		
		
		function debug_log($message) {
			if (WP_DEBUG === true) {
				if (is_array($message) || is_object($message)) {
					error_log(print_r($message, true));
				} else {
					error_log($message);
				}
			}
		}
		
		// ---------------------------------------------------------------------------
		//
		// UTILITY FUNCTIONS
		//
		// ---------------------------------------------------------------------------	
			
		function http_parse_headers( $header )
		{
			$retVal = array();
			$fields = explode("\r\n", preg_replace('/\x0D\x0A[\x09\x20]+/', ' ', $header));
			foreach( $fields as $field ) {
				if( preg_match('/([^:]+): (.+)/m', $field, $match) ) {
					$match[1] = preg_replace('/(?<=^|[\x09\x20\x2D])./e', 'strtoupper("\0")', strtolower(trim($match[1])));
					if( isset($retVal[$match[1]]) ) {
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
		static function activate() {
			add_option('dcwoo_hostname');
			add_option('dcwoo_token');
		}
		
		static function deactivate() {
			// These are commented out because if you deactivate the plugin you lose these settings otherwise
			// Although good plugin practice says delete these options, in practice it is very annoying for a DC user
			//
			//delete_option('dcwoo_hostname');
			//delete_option('dcwoo_token');
		}
		
		
	} // end class definition
} // end if class exists

?>