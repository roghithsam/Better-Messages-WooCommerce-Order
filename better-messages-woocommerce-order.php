<?php
/**
 * Plugin Name: Better Messages WooCommerce Order
 * Plugin URI: https://github.com/roghithsam/Better-Messages-WooCommerce-Order
 * Description: A WooCommerce extension that integrates chat functionality for post-order support, allowing customers and shop managers to communicate seamlessly about customized orders.
 * Version: 1.0.0
 * Author: Roghithsam
 * Author URI: https://roghithsam.zhark.in
 * Text Domain: better-messages-woocommerce-order
 * Domain Path: /languages
 *
 * @package Better_Messages_WooOrder
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'Better_Messages' ) ) {
    exit;
}

if ( ! class_exists( 'Better_Messages_WooOrder' ) ) {

	class Better_Messages_WooOrder
	{

		public static function instance()
		{

			static $instance = null;

			if (null === $instance) {
				$instance = new Better_Messages_WooOrder();
			}

			return $instance;
		}

		public function __construct(){
			//if( Better_Messages()->settings['WooOrderIntegration'] !== '1' ) return;

			add_filter( 'better_messages_rest_thread_item', array( $this, 'thread_item' ), 10, 5 );
			add_filter( 'better_messages_rest_user_item', array( $this, 'vendor_user_meta' ), 20, 3 );

			add_action('init', array( $this, 'handle_bm_support_fast_start_form_submission'));

			add_action( 'woocommerce_thankyou', array( &$this, 'vieworder_page_contact_button' ), 35 );
			add_action( 'woocommerce_view_order', array( &$this, 'vieworder_page_contact_button' ), 35 );
			
			add_action( 'woocommerce_before_trash_order', array( &$this, 'bp_trash_order' ), 35, 1);
			add_action( 'woocommerce_untrash_order', array( &$this, 'bp_untrash_order' ), 35, 1);
			add_action( 'woocommerce_before_delete_order', array( &$this, 'bp_delete_order' ), 35, 1);
			
			add_action( 'woocommerce_order_status_processing', array( &$this, 'custom_function_on_order_processing' ), 10, 2 );
			add_action( 'add_meta_boxes', array( &$this, 'add_chat_box_metabox'), 10, 1);

			// Add a new tab to the product data metabox
			add_action( 'woocommerce_product_data_tabs', array( &$this, 'wpsh_add_custom_chat_tab'), 999);

			// Add the panel to the product data metabox
			add_action( 'woocommerce_product_data_panels', array( &$this, 'wpsh_add_chat_tab_content'), 999);

			// Save custom field values
			add_action( 'woocommerce_admin_process_product_object', array( &$this, 'wpsh_save_fields'), 10, 1);

		}

		public function bp_trash_order($order_id){

			$unique_key_check = 'wooorder_'.$order_id;

			$thread_id = $this->get_unique_conversation_id_only($unique_key_check);

			if ( $thread_id > 0) { 

				// Returns array of user ids (int), returns empty array if no participants or thread not exists
				$user_ids = Better_Messages()->functions->get_recipients_ids( $thread_id );

				if($user_ids){

					foreach($user_ids as $user_id){

						Better_Messages()->functions->archive_thread( $user_id, $thread_id );		

					}
				}
				
			}

		}

		public function bp_untrash_order($order_id){

			$unique_key_check = 'wooorder_'.$order_id;

			$thread_id = $this->get_unique_conversation_id_only($unique_key_check);

			if ( $thread_id > 0) { 

				// Returns array of user ids (int), returns empty array if no participants or thread not exists
				$user_ids = Better_Messages()->functions->get_recipients_ids( $thread_id );

				if($user_ids){

					global $wpdb;
			        
		            $time = Better_Messages()->functions->get_microtime();

		            // Mark messages as undeleted
					foreach($user_ids as $user_id){

						$wpdb->query( $wpdb->prepare( "UPDATE " . bm_get_table('recipients') . " SET is_deleted = 0, last_update = %d WHERE thread_id = %d AND user_id = %d", $time, $thread_id, $user_id ) );

			            do_action( 'better_messages_thread_updated', $thread_id );			  
				   
					}
				}
				
			}

		}

		public function bp_delete_order($order_id){

			$unique_key = 'wooorder_'.$order_id;

			$thread_id = $this->get_unique_conversation_id_only($unique_key);

			if ( $thread_id > 0) { 

				Better_Messages()->functions->erase_thread( $thread_id );		
			}

		}

		
		public function wpsh_add_custom_chat_tab( $tabs ) {
		    $tabs['chat_tab'] = array(
		        'label'    => __( 'Chat Tab', 'woocommerce' ),
		        'target'   => 'chat_tab_data',		
		        'priority' => 11,
				'class'	   => array( 'show_if_simple', 'show_if_variable'  ),
		    );

		    return $tabs;
		}

		public function wpsh_add_chat_tab_content() {
		    global $post;

		    echo '<div id="chat_tab_data" class="panel woocommerce_options_panel">';
		    echo '<style>#woocommerce-product-data ul.wc-tabs li.chat_tab_options a:before { content: "\f125"; }</style>' ;	
			
			$product = wc_get_product( $post->ID );
		    $combined_values = $product->get_meta( '_chat_msg' );
		    $values_array = explode( '|', $combined_values );
			
			woocommerce_wp_checkbox( [
                'id'          => '_send_notification',
                'label'       => __( 'Enable Chat & Notification', 'woocommerce' ),
                'description' => __( 'Check this box to Enable & Send a notification.', 'woocommerce' ),
                'desc_tip'    => true,
                'value'       => ( isset( $values_array[0] ) && $values_array[0] === 'yes' ) ? 'yes' : 'no',
            ] );

		    // Dropdown with list of shop managers
		    $shop_managers = get_users( array( 'role' => 'shop_manager' ) );
		    $current_user_id = get_current_user_id();
		    $current_user_display_name = wp_get_current_user()->display_name ?: 'Me';
		  	
		    $options = array();
		    $options = [ $current_user_id => $current_user_display_name ];
		    foreach ( $shop_managers as $manager ) {
		        $options[ $manager->ID ] = $manager->display_name;
		    }

		    woocommerce_wp_select( array(
		        'id'            => '_selected_manager',
		        'label'         => __( 'Select Shop Manager', 'woocommerce' ),
		        'options'       => $options,
		        'description'   => __( 'By Default Message Will send to who added this product, you can overwrite here by assigning a shop manager.', 'woocommerce' ),
		        'desc_tip'      => true,
				'value'         => $values_array[1] ?? '',
		    ) );
			
			
		    woocommerce_wp_textarea_input( array(
		        'id'            => '_chat_msg',
				'name'          => '_chat_msg_textarea',
		        'label'         => __( 'Chat Message', 'woocommerce' ),
				'placeholder'   => __( 'Thank You For Purchace, Please Send a 2 High-Quality Photos to Print', 'woocommerce' ),
		        'description'   => __( 'This message is send to customers once their payment is successfull, you can write here anything you want.', 'woocommerce' ),
		        'desc_tip'      => true,
				'value'         => $values_array[2] ?? '',
		    ) );
			echo '<p class="description"> 	Hi, [Customer Name] ! <br>	Thank You for Your Purchase!, We\'re excited to thank you for choosing [Shop Name] <br>	 [Chat Message] </p>';
			echo '</div>';
		}

		
		public function wpsh_save_fields( $product ) {
            $combined_values = implode( '|', [
                isset( $_POST['_send_notification'] ) ? 'yes' : 'no',
                sanitize_text_field( $_POST['_selected_manager'] ?? '' ),
                sanitize_textarea_field( $_POST['_chat_msg_textarea'] ?? '' ),
            ]);

            $product->update_meta_data( '_chat_msg', $combined_values );
        }
		
		public static function get_unique_conversation_id_only( string $unique_key ) {
            global $wpdb;
            $unique_tag = $unique_key . '|%';
            return (int) $wpdb->get_var( $wpdb->prepare( "
                SELECT thread_meta.bm_thread_id FROM " . bm_get_table( 'threadsmeta' ) . " thread_meta WHERE `meta_key` = 'unique_tag' AND `meta_value` LIKE %s LIMIT 1", $unique_tag ) );
        }

		public function thread_item( $thread_item, $thread_id, $thread_type, $include_personal, $user_id ){
			if( $thread_type !== 'thread'){
				return $thread_item;
			}

			$unique_tag = Better_Messages()->functions->get_thread_meta( $thread_id, 'unique_tag' );

			if( ! empty( $unique_tag ) ){
				if( str_starts_with( $unique_tag, 'wooorder_' ) ){
					$parts = explode('|', $unique_tag);
					if( isset( $parts[0] ) ){
						$order_id = str_replace( 'wooorder_', '', $parts[0]);
						$thread_info = '';
						if( isset( $thread_item['threadInfo'] ) ) $thread_info = $thread_item['threadInfo'];
						$thread_info .= $this->thread_info( $order_id );
						$thread_item['threadInfo'] = $thread_info;


						$order = wc_get_order($order_id);

						//$product = wc_get_product( $product_id );
						if( ! $order ) return '';


						foreach ($order->get_items() as $item_id => $item) {
							// Get product ID
							$product_id = $item->get_product_id();
							// Get product object
							$product = wc_get_product($product_id);

							// Get seller/user ID
							$seller_id = get_post_field('post_author', $product_id);

							// Get product image
							$image_src = wp_get_attachment_image_src(get_post_thumbnail_id($product_id), [100, 100]);

							$image         = false;
							//$title  = $product->get_name();
							// $url           = get_permalink($product_id);
							// $price         = $product->get_price_html();

							if( $image_src ){
								$image = $image_src[0];
							}

							//  $thread_item['subject'] = $title;
							// $thread_item['title'] = html_entity_decode($title );

							// Overwrite subject
							$thread_item['image'] = $image;

							$sender_id  = get_current_user_id();
							
								
							$content    = ' Your recent gift purchase is appreciated';
							

							/*$message_id = Better_Messages()->functions->new_message([
								'sender_id'    => $sender_id,
								'thread_id'    => $thread_id,
								'content'      => $content,
								'return'       => 'message_id',
								'error_type'   => 'wp_error'
							]);

							if ( is_wp_error( $message_id ) ) {
								$error = $message_id->get_error_message();
								// Process error
							} else {
								// Message created
								// var_dump( $message_id );
							}*/

						}
					} 
				}
			}
			return $thread_item;
		}

		function vendor_user_meta( $item, $user_id, $include_personal ){
			$item['url'] = false;
			return $item;
		}

		public function thread_info( $order_id ) {
		    if ( ! function_exists( 'wc_get_product' ) ) {
		        return '';
		    }

		    $order = wc_get_order( $order_id );
		    if ( ! $order ) {
		        return '';
		    }

		    $html = '';
		    foreach ( $order->get_items() as $item ) {
		        $product_id = $item->get_product_id();
		        $product    = wc_get_product( $product_id );
		        
		        if ( ! $product ) {
		            continue; // Skip if the product doesn't exist
		        }

		        $image_src = wp_get_attachment_image_src( get_post_thumbnail_id( $product_id ), [ 100, 100 ] );
		        $image     = $image_src ? $image_src[0] : false;
		        $title     = $product->get_name();
		        $url       = get_permalink( $product_id );
		        $price     = $product->get_price_html();

		        $html .= '<div class="bm-product-info">';

		        // Product image
		        if ( $image ) {
		            $html .= '<div class="bm-product-image">';
		            $html .= '<a href="' . esc_url( $url ) . '" target="_blank"><img src="' . esc_url( $image ) . '" alt="' . esc_attr( $title ) . '" /></a>';
		            $html .= '</div>';
		        }

		        // Product details
		        $html .= '<div class="bm-product-details">';
		        $html .= '<div class="bm-product-title"><a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( $title ) . '</a></div>';
		        $html .= '<div class="bm-product-price">' . wp_kses_post( $price ) . '</div>';
		        $html .= '</div>';

		        // View order button
		        $view_orders_page_link = current_user_can( 'shop_manager' ) || current_user_can( 'administrator' )
		            ? $order->get_edit_order_url()
		            : $order->get_view_order_url();

		        $html .= '<div class="bm-product-button"><a href="' . esc_url( $view_orders_page_link ) . '" class="button">View Order</a></div>';
		        $html .= '</div>'; // Closing bm-product-info
		    }

		    return $html;
		}


		public function vieworder_page_contact_button( $order_id ) {
		    $unique_key = 'wooorder_' . $order_id;
		    $thread_id = $this->get_unique_conversation_id_only( $unique_key );

		    if ( $thread_id > 0 ) {
		        echo '<div class="custom-content">';
		        echo '<h2>Chat Conversation</h2>';
		        echo do_shortcode( '[better_messages_single_conversation thread_id="' . esc_attr( $thread_id ) . '"]' );
		        echo '</div>';
		    } else {
		        ob_start();
		        ?>
		        <form method="post" action="<?php echo esc_url( $_SERVER['REQUEST_URI'] ); ?>">
		            <?php wp_nonce_field( 'bm_support_fast_start_nonce', 'bm_support_fast_start_nonce' ); ?>
		            <input type="hidden" name="bm_support_fast_start_unique_tag" value="<?php echo esc_attr( $order_id ); ?>">
		            <input type="submit" value="Get Support" class="button">
		        </form>
		        <?php
		        echo ob_get_clean();
		    }
		}

		
		function handle_bm_support_fast_start_form_submission() {
		    if (isset($_POST['bm_support_fast_start_nonce']) && wp_verify_nonce($_POST['bm_support_fast_start_nonce'], 'bm_support_fast_start_nonce')) {
		        // Process form data
		        $order_id = sanitize_text_field($_POST['bm_support_fast_start_unique_tag']);
		        $order = wc_get_order($order_id);

		        if (!$order) {
		            wp_safe_redirect(esc_url($_SERVER['REQUEST_URI']));
		            exit; // Ensure exit after redirection
		        }

		        $purchaser_id = $order->get_customer_id();
		        $current_user_id = get_current_user_id();

		        if ($purchaser_id != $current_user_id) {
		            wc_add_notice('Something went wrong.', 'error');
		            wp_safe_redirect(esc_url($_SERVER['REQUEST_URI']));
		            exit; // Ensure exit after redirection
		        }

		        if ($current_user_id) {
		            foreach ($order->get_items() as $item) {
		                $product_id = $item->get_product_id();
		                $product = wc_get_product($product_id);
		                $product_name = $product->get_name();
		                $seller_id = get_post_field('post_author', $product_id);

		                if ($seller_id == $current_user_id) {
		                    wc_add_notice('Cannot chat with the same user.', 'error');
		                    wp_safe_redirect(esc_url($_SERVER['REQUEST_URI']));
		                    exit; // Ensure exit after redirection
		                } elseif ($seller_id) {
		                    $user_ids = [$current_user_id, $seller_id];
		                    $unique_key = 'wooorder_' . $order_id;
		                    $subject = 'Support: ' . $product_name;

		                    $thread_id = Better_Messages()->functions->get_unique_conversation_id($user_ids, $unique_key, $subject);

		                    if ($thread_id) {
		                        // Redirect to the messages page or handle accordingly
		                        wp_safe_redirect(esc_url($_SERVER['REQUEST_URI']));
		                        exit; // Ensure exit after redirection
		                    }
		                }
		            }
		        }
		    }
		    // Redirect after submission if nonce is not valid
		    wp_safe_redirect(esc_url($_SERVER['REQUEST_URI']));
		    exit; // Ensure exit after redirection
		}


		public function add_chat_box_metabox($post_type) {
		    // Check if the post type is either the shop order screen or a single shop order
		    if (in_array($post_type, [wc_get_page_screen_id('shop-order'), 'shop_order'], true)) {
		        add_meta_box(
		            'chat-box-information',
		            __('Chat Conversation', 'WooOrder Integration'),
		            [$this, 'show_chat_box_metabox'],
		            $post_type,
		            'advanced',
		            'high'
		        );
		    }
		}

		public function show_chat_box_metabox($post) {
		    $order_id = $post->get_id();
		    $unique_key = 'wooorder_' . $order_id;
		    $thread_id = $this->get_unique_conversation_id_only($unique_key);

		    // Enqueue necessary CSS and JS for Better Messages
		    Better_Messages()->enqueue_css();
		    Better_Messages()->enqueue_js();

		    echo '<div class="custom-content">';
		    echo '<style>.bm-thread-info .bm-product-button { margin-left: auto; }</style>';

		    if ($thread_id > 0) {
		        echo Better_Messages()->functions->get_conversation_layout($thread_id);
		    } else {
		        echo 'No Conversations';
		    }
		    
		    echo '</div>';
		}

		
		public function custom_function_on_order_processing( $order_id, $order ) {

			if ( ! $order ) { return; }

            $onceMore = true;
            $order = wc_get_order( $order_id );

            $unique_key_check = 'wooorder_'.$order_id;
			       
			$thread_id_check = $this->get_unique_conversation_id_only($unique_key_check);

			if ( $thread_id_check > 0) { 
				return; 
			}

			// Proceed if order status is processing or completed
            $order_status = $order->get_status();
            if ($order_status !== 'processing' && $order_status !== 'completed') {
                return;
            }

            // Initialize variables
            $seller_id = 1;
            $purchaser_id = $order->get_customer_id(); // Assuming purchaser ID is the customer ID

            // Get the items in the order
            $items = $order->get_items();

            if($onceMore == true ){
           
                foreach ( $items as $item ) {
                    $product_id = $item->get_product_id();

                    $product = wc_get_product($product_id);

                    $custom_field_value = get_post_meta($product_id, '_chat_msg', true);	
					$values = explode( '|', $custom_field_value );
			
					// Example: Accessing individual values
					$send_notification = isset( $values[0] ) ? $values[0] : '';

					if ($send_notification == "yes"){

	                    $product_name = $product->get_name();
	                    $seller_id = get_post_field('post_author', $product_id);
				
	                    // Break the loop if a seller ID is found for the first product
	                    if ( $seller_id ) {

							$selected_manager = isset( $values[1] ) ? $values[1] : $seller_id;
							$text_area_content = isset( $values[2] ) ? $values[2] : '';
														
	                        $user_ids =[$selected_manager, $purchaser_id];

	                        $log_message = var_export( $user_ids, true);
							
	                        $unique_key = 'wooorder_'.$order_id;

	                        $subject = sprintf(
	                            esc_html_x('Order #%d: %s', 'WooOrder Integration (Thankyou page)', 'bp-better-messages'),
	                            $order_id,
	                            $product_name
	                        );
	                        $thread_id = Better_Messages()->functions->get_unique_conversation_id( $user_ids, $unique_key, $subject );

	                        if($thread_id > 0){
								
								$user_data = get_userdata($purchaser_id);

								if ($user_data) {
									$customer_name = 'Hi, '. $user_data->display_name .'!';
								}else{
									$customer_name ="";
								}

								$site_title = get_bloginfo('name');

								$intro = $customer_name . "\n" . 'Thank You for Your Purchase! We\'re excited to thank you for choosing ' . $site_title . ".\n";

								$content = !empty($text_area_content) ? $intro . $text_area_content : $intro . ' We hope you enjoy our products. If you have any questions or need assistance, feel free to reply here. We\'re here to help!';

	                            $message_id = Better_Messages()->functions->new_message([
	                                'sender_id'    => $selected_manager,
	                                'thread_id'    => $thread_id,
	                                'content'      => $content,
	                                'return'       => 'message_id',
	                                'error_type'   => 'wp_error'
	                            ]);

	                            if ( is_wp_error( $message_id ) ) {
	                                $error = $message_id->get_error_message();                            
	                            } else {  
	                                // var_dump( $message_id );
	                            }
	                        }

	                        break;
	                    }
						$onceMore=false;
					}
                }

            }

        }

	}
}
