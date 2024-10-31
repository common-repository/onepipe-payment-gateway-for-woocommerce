<?php
/**
 * Plugin Name: OnePipe Payment Gateway for WooCommerce
 * Plugin URI: https://onepipe.io
 * Description: Accept payments in your WooCommerce store from multiple banks and fintechs using OnePipe.
 * Version: 1.0.0
 * Author: OnePipe
 * Author URI: https://tormuto.com
 * License: GPLv2 or later
 * WC tested up to: 5.7
 */
 
if(!defined('ABSPATH'))exit;

define('ONEPIPE_PLUGIN_ID','onepipe');

add_action('plugins_loaded', 'onepipe_init', 99);
function onepipe_init(){
    if (!class_exists( 'WC_Payment_Gateway')){ return;    }

	class WC_Gateway_OnePipe extends WC_Payment_Gateway{
		public function __construct(){
			$this->id			= ONEPIPE_PLUGIN_ID;
			$this->method_title = __('OnePipe', 'woothemes');
			$this->method_description = __('OnePipe enables seamless integrations of banks and fintechs', 'woocommerce');
			$this->order_button_text = __('Pay via OnePipe', 'woocommerce');
            
            $this->assets_base  =   WP_PLUGIN_URL.'/'.plugin_basename(dirname(__FILE__)).'/assets/';
            $this->icon_alt     = $this->assets_base.'images/logo.png';
			$this->supported_currencies=array('NGN');

	        $this->has_fields 	= true;
			$this->init_form_fields();
			$this->init_settings();
	
			// Define user set variables
			$this->enabled = $this->settings['enabled'];
			$this->title = $this->settings['title'];
			$this->description = $this->settings['description'];
			
			$this->onepipe_client_secret = $this->get_option('onepipe_client_secret');
			$this->onepipe_api_key = $this->get_option('onepipe_api_key');
			$this->testmode = ($this->get_option('environment') === 'sandbox') ? true : false;
			
			$this->onepipe_default_view_option = $this->get_option('onepipe_default_view_option');
			$this->onepipe_default_view_provider = $this->get_option('onepipe_default_view_provider');
			if(empty($this->onepipe_default_view_option))$this->onepipe_default_view_option=null;
			if(empty($this->onepipe_default_view_provider))$this->onepipe_default_view_provider=null;
			
			$temp = (float)$this->get_option('onepipe_close_popup_timeout');
			if($temp<=0)$temp=5;
			$this->onepipe_close_popup_timeout = $temp;
			
			$temp = @json_decode($this->get_option('onepipe_close_popup_status'),true);
			if(empty($temp))$temp = null;
			$this->onepipe_close_popup_status = $temp;			

			add_action(	'woocommerce_update_options_payment_gateways_' . $this->id, array($this,'process_admin_options'));
			add_action('woocommerce_api_'.strtolower(get_class($this)), array($this,'ipn_callback'));
			if(!$this->is_valid_for_use()) $this->enabled = false;
		}
	
		private function is_valid_for_use(){
			if(!empty($this->supported_currencies)&&!in_array(get_option('woocommerce_currency'),$this->supported_currencies)) return false;
			return true;
		}
		
		public function get_icon(){
			$icon_html =
				'<img src="'.plugins_url('assets/images/logo.png', __FILE__).'" alt="OnePipe" style="min-height:40px; margin-top:-10px;max-height:50px; float: right;" />';
			return apply_filters('woocommerce_gateway_icon', $icon_html, $this->id);
		}
		
		public function admin_options(){
	    	?>
			<h3><?php _e('OnePipe', 'woothemes'); ?></h3>
			<p><?php _e('OnePipe enable customers to make payments using various options.', 'woothemes'); ?></p>
	    	<table class="form-table">
	    	<?php
	    		if($this->is_valid_for_use()){  	
	    			$this->generate_settings_html();
				}
	    		else {	?>
	            	<div class="inline error"><p><strong><?php _e( 'Gateway Disabled', 'woothemes' ); ?></strong>: <?php _e( 'OnePipe does not support your store currency. It supports: '.implode(', ',$this->supported_currencies), 'woothemes' ); ?></p></div>
	        		<?php	        		
	    		}
	    	?>
			</table>
	    	<p class="auto-style1">&nbsp;</p>
	    	<?php
	    }		
		
		function init_form_fields(){
			global $woocommerce;
			
			$temp=array(
			  "Successful"=>array("bank.account","voucher","airtime","custom"),
			  "Failed"=>array("bank.account","voucher","airtime","custom")
			);
			$temp = json_encode($temp,JSON_PRETTY_PRINT);
			
			$this->form_fields = array(
				'enabled' => array(
					'title' => __( 'Enable/Disable', 'woothemes' ),
					'type' => 'checkbox',
					'label' => __( 'Enable OnePipe Gateway', 'woothemes' ),
					'default' => 'yes'
				),
				'title' => array(
					'title' => __( 'Title', 'woothemes' ),
					'type' => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'woothemes' ),
					'default' => __( 'OnePipe', 'woothemes' )
				),
				'description' => array(
					'title' => __( 'Description', 'woothemes' ),
					'type' => 'text',
					'description' => __( 'This is the message box that will appear on the checkout page when they select OnePipe.', 'woothemes' ),
					'default' => __('You will be presented with various payment options.', 'woothemes' )
				),
				'environment' => array(
					'title' => __('Mock Mode', 'woo-onepipe'),
					'type' => 'select',
					'description' => __('Specifies if this is for real transactions or testing.', 'woo-onepipe'),
					'default' => 'production',
					'desc_tip' => false,
					'options' => array(
						'production' => __('Live (Production)', 'woo-onepipe'),
						'sandbox' => __('Inspect/Test (Sandbox)', 'woo-onepipe'),
					),
				),
				'onepipe_api_key'           => array(
					'title'       => __( 'API Key', 'woo-onepipe' ),
					'type'        => 'text',
					'desc_tip'    => false,
					'description' => __( 'Get your API Key from your OnePipe Merchant Dashboard.', 'woocommerce' ),
				),
				
				'onepipe_client_secret'           => array(
					'title'       => __( 'Client Secret', 'woo-onepipe' ),
					'type'        => 'password',
					'desc_tip'    => false,
					'description' => __( 'Get your Client Secret key from your OnePipe Merchant Dashboard.' ,'woocommerce' ),
				),			
				
				'onepipe_close_popup_timeout'           => array(
					'title'       => __( 'Close-popup Timeout', 'woo-onepipe' ),
					'type'        => 'number',
					'desc_tip'    => false,
					'description' => __( 'Number of seconds, before the popup closes automatically after transaction.' ,'woocommerce' ),
					'default'	=> 5
				),
				'onepipe_close_popup_status'           => array(
					'title'       => __( 'Close-popup Status', 'woo-onepipe' ),
					'type'        => 'textarea',
					'desc_tip'    => false,
					'description' => __( "Optional.  Supply value in a valid JSON format. E.G:
						<pre>$temp</pre>" ),
				),
				'onepipe_default_view_option'           => array(
					'title'       => __( 'Default-view Option', 'woo-onepipe' ),
					'type'        => 'text',
					'desc_tip'    => false,
					'description' => __( 'Optional. E.G: bank.account' ),
				),
				'onepipe_default_view_provider'           => array(
					'title'       => __( 'Default-view Provider', 'woo-onepipe' ),
					'type'        => 'text',
					'desc_tip'    => false,
					'description' => __( 'Optional. E.G: Suntrust' ),
				),
			);			
		}

		public function payment_fields(){
			if(!$this->description)$more='';
			else $more= wpautop(wptexturize($this->description));
			?>
			<script id='onepipe_external'></script>
			<script>
			var onepipe_external=document.getElementById('onepipe_external');
			onepipe_external.onload = function() { OnePipePopup.isInitialized || OnePipePopup.initialize(); }
			onepipe_external.src = 'https://js.onepipe.io/v2';
			var onepipe_processing = false;

			window.addEventListener('load',function(){
				jQuery( 'form.checkout' ).on( 'checkout_place_order', function() {
					var isSelectedMethod = jQuery('#payment_method_onepipe').is(':checked');

					if(isSelectedMethod){
						if(onepipe_processing){ 
							//console.log('OnePipe already processing');
							return false;
						}
						onepipe_processing=true;
						
						var resp_div = jQuery('#onepipe_checkout_response');
						resp_div.html("Placing order, please wait..")
						
						var temp_url = '<?php echo home_url(); ?>/?wc-ajax=checkout';
						var temp_data=jQuery('form.woocommerce-checkout').serializeArray();
						var form_data = {}
						for (var i = 0; i < temp_data.length; i++){
							form_data[temp_data[i]['name']] = temp_data[i]['value'];
						}						
						
						jQuery.ajax({
							url: temp_url,
							type: 'POST',
							data: form_data,
							success: function(resp) {
								//console.log(resp);							
								if(resp.messages)resp_div.html(resp.messages);
								if(resp.result != 'success')onepipe_processing=false; //failure
								else {
									//if(resp.onepipeData){ //must be.
										resp_div.html('Order initiated, please proceed to payment.');
										OnePipePay(resp.onepipeData);
									//}
								}
							},
							error: function(xhr) {
								console.log("AJAX error initiating order.",xhr);
								var errorMessage = xhr.status + ': ' + xhr.statusText
								resp_div.html("Error "+errorMessage);
								onepipe_processing=false;
							}
						});
					}
					
					return !isSelectedMethod;
				});
			});
			
			function OnePipePay(params) {
				//console.log(JSON.stringify(params.requestData));
				var handler = OnePipePopup.setup({
					requestData: params.requestData,
					callback: function (response) {
						onepipe_processing = false;
						console.log('onepipe callback:',response);
						window.location.href=params.callback_url; //not success_url
					},
					onClose: function () {
						onepipe_processing = false;
						console.log('Payment process cancelled');
						window.location.href=params.cancel_url;
					}
				});
				
				handler.execute();
			}
			</script>			
		<?php
			echo "<div>$more</div>
			<div id='onepipe_checkout_response'></div>";
		}
		
		public function process_payment($order_id){
			global $woocommerce; global $wpdb;
			$order = new WC_Order($order_id);	
			//Validate custom-inline form-data here, if posted. Returns redirect url. 
			
			$email=$order->get_billing_email();
			$firstname=$order->get_billing_first_name();
			$lastname=$order->get_billing_last_name();
			$phone=$order->get_billing_phone();
			if(empty($phone))$phone='';
			
			$order_number=$order->get_order_number();
			$amountTotal=floatval(number_format($order->calculate_totals(),2,'.',''));
			$woocommerce_currency = get_woocommerce_currency();
			$payment_url=$order->get_checkout_payment_url(); 
			$order_title="Order: #$order_number - ".get_option('blogname');
				
			$order_items = $order->get_items();
			foreach($order_items as $item ){
				$products_item_line = implode(' x ',array($item->get_quantity(),$item->get_name()));
				$product_items[] = $products_item_line;
			}
			$order_description=implode(', ',$product_items);
			$ddate=date('YmdHis');
			$transRef = "$order_id.$ddate";

			$cancel_url = $order->get_cancel_order_url();
			$success_url = $this->get_return_url($order);
			$callback_url=WC()->api_request_url(get_class($this));
			if(stristr($callback_url,'?'))$callback_url.="&order_id=$order_id&trans_ref=$transRef";
			else $callback_url.="?order_id=$order_id&trans_ref=$transRef";

			//--------------- OnePipe Transaction Initiation
			$mock_mode=$this->testmode?'inspect':'live';
			$requestData=array(
				'request_ref'=>$transRef,
				'request_type'=>'collect',
				'api_key'=>$this->onepipe_api_key,
				'auth'=>null,
				'transaction'=>array(
					'amount'=>ceil($amountTotal*100),
					'currency'=>$woocommerce_currency,
					'mock_mode'=>$mock_mode,
					'transaction_ref'=>$transRef,
					'transaction_desc'=>$order_title,
					'customer'=>array(
						'customer_ref'=>$email,
						'firstname'=>$firstname,
						'surname'=>$lastname,
						'email'=>$email,
						'mobile_no'=>$phone
					),
					'details'=>null
				),
				
				'meta'=>null,
				'options'=>array(
					'close_popup'=>array(
						'timeout'=>$this->onepipe_close_popup_timeout,
						'status'=>$this->onepipe_close_popup_status,
					),
					'default_view'=>array(
						'option'=>$this->onepipe_default_view_option,
						'provider'=>$this->onepipe_default_view_provider
					)
				),
			);
			
			$onepipeData=array(
				'requestData'=>$requestData,
				'cancel_url' => $cancel_url,
				'success_url' => $success_url,
				'callback_url' => $callback_url,
			);
			
			//$order->set_payment_method($this);
			$order->update_status('pending', __("OnePipe payment pending.", 'woothemes'));		
		   if(true){			
				$domain=$_SERVER['HTTP_HOST'];
				if(substr($domain,0,4)=='www.')$domain=substr($domain,4);
				$site_name=get_option('blogname');
				$mail_from="$site_name<no-reply@$domain>";
				$customer_fullname=ucwords(strtolower("$firstname $lastname"));
				
				$transaction_date=date('jS M. Y g:i a');
				$amountTotals=number_format($amountTotal);
				//$mail_message="Hello $customer_fullname\r\n\r\nHere are the details of your transaction:\r\n\r\nDETAILS: $order_title\r\nAMOUNT: $amountTotals $woocommerce_currency \r\nDATE: $transaction_date\r\n\r\nYou can always confirm your transaction/payment status at $callback_url\r\n\r\nRegards.";
				
				$mail_message="<div style='background-color:#fbfbfb;width:100%;text-align:center;'>
			<div style='background-color:#fbfbfb;width:100%;max-width:650px;display:inline-block;'>
				<div style='text-align:left;padding:30px 0;'>
					<img src='{$this->icon_alt}' style='width:130px;height:auto;border:0' alt='' />
				</div>
				<div style='border-top:7px solid #1c84c6;padding:30px 30px 60px 30px;color:#777777;font-family: Lato, Arial, sans-serif;font-size:17px;line-height:30px;'>			
					<h3 style='color:#000000;font-size:24px;line-height:32px;font-weight:bold;'>Transaction Information</h3>			
					<div><b>Customer Name :</b><span>$customer_fullname</span></div>
					<div><b>Order Details :</b><span>$order_title</span></div>
					<div><b>Amount :</b><span>$amountTotals $woocommerce_currency</span></div>
					<div><b>Date :</b><span>$transaction_date</span></div>
					<div style='margin-top:25px;'>
						<a href='$callback_url' target='_blank' style='color:#ffffff;text-decoration: none;background:#008bb1;font-size:14px; line-height:18px; padding:12px 20px;font-weight:bold; border-radius:22px;'>VIEW TRANSACTION DETAILS</a>
					</div>
				</div>
			</div>
		</div>";
				
				$mail_headers = "MIME-Version: 1.0\r\n";
				$mail_headers .= "Content-Type: text/html; charset=iso-8859-1\r\n";
				$mail_headers .= "X-Priority: 1 (Highest)\r\n";
				$mail_headers .= "X-MSMail-Priority: High\r\n";
				$mail_headers .= "Importance: High\r\n";
				$mail_headers .= "From: $mail_from\r\n";
				@mail($email,"OnePipe Transaction Information",$mail_message,$mail_headers);
			}
			
			$resp=array('result'=>'success','onepipeData'=>$onepipeData,'redirect'=>'#');
			return $resp;
		}
		
		public function ipn_callback(){			
			//$order_received_url=$order->get_checkout_order_received_url();			
			if(!empty($_GET['order_id'])&&!empty($_GET['trans_ref'])){
				$order_id = sanitize_text_field($_GET['order_id']);
				$trans_ref = sanitize_text_field($_GET['trans_ref']);				

				$request_url='https://api.onepipe.io/v2/transact/query';
				$request_ref = time().'.'.mt_rand(1000,9999);
				
				$request_body=array(
					  'request_ref'=>$request_ref, 
					  'request_type'=>'collect',
					  'transaction'=>array(
						'transaction_ref'=>$trans_ref, 
					)
				);
				
				$arg = array(
					'body'=>json_encode($request_body),
					'timeout'=>50,'redirection'=>5,
					'headers'=>array(
						'Content-Type'=>'application/json',
						'Authorization'=>"Bearer {$this->onepipe_api_key}",
						'Signature'=>md5("$request_ref;{$this->onepipe_client_secret}"),
					)
				);
				$response = wp_remote_post($request_url, $arg);
				
				if(is_wp_error($response)) {
					$error_message = $response->get_error_message();
					//$error="Error verifying payment at OnePipe: $error_message";
					$error="Unable to verify transaction at OnePipe.";
				}
				else {
					$json = @json_decode($response['body'],true);
					
					if(empty($json))$error="Error interpreting OnePipe verification response: {$response['body']}";
					elseif(empty($json['data']))$error="Unsuccessful attempt at verifying payment from OnePipe: {$response['body']}";
					else {						
						$porder=$json['data'];						
						$amount_paid = null;
						$currency_paid = null;
						
						if(!empty($porder['provider_response']['transaction_final_amount'])){
							$amount_paid=$porder['provider_response']['transaction_final_amount']/100;
						}						
						
						$pstatus=$json['status'];
						$pmessage=empty($porder['message'])?trim($json['message']):trim($porder['message']);
						
						if($pstatus=='Successful'&&!empty($porder['error'])){
							$pstatus='Unsuccessful';
							$pmessage.=";; ".json_encode($porder['error']);
						}
					}
					
					if(!empty($error))$this->_log_stuff("$error\n\n$url\n".json_encode($_POST,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
				}
				
				//---------------------------------------
				$order = wc_get_order($order_id);
				if(empty($order)){
					$error="IPN Received for non-existent order ID: $order_id.";
					$this->_log_stuff("$error\n\n".json_encode($_GET,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
				}
				elseif($order->has_status('completed')){ //||$order->has_status('processing')
					$error="This order is currently being processed or completed.";
					wp_redirect($this->get_return_url($order));	 exit;
				}
				else {
					$order_total = floatval($order->get_total());
					$order_currency = method_exists($order,'get_currency')?$order->get_currency():$order->get_order_currency();
					
					if($amount_paid===null)$amount_paid=$order_total;
					if($currency_paid===null)$currency_paid=$order_currency;
				}
				//---------------------------------
				

				if(!empty($error))wc_add_notice($error,'error');	
				elseif(!empty($porder)){
					$new_status = 0; //0:pending, 1:successful, -1: failed					
					
					if($pstatus=='Successful'){
						if($amount_paid<$order_total) {
							$new_status = -1;
							$info = "Amount paid ($amount_paid) is less than the total order amount ($order_total).";
						}
						elseif($currency_paid!=$order_currency) {
							$new_status = -1;
							$info = "Order currency ($order_currency) is different from the payment currency ($currency_paid).";
						}
						elseif(!$this->testmode&&!empty($testmode_payment)) {
							$new_status = -1;
							$info = "This store doesn't run on test-mode, where-as payment was made in test mode. Suspicious!";
						}
						else { $new_status = 1; $info = "Payment successful via OnePipe. Ref: $trans_ref. $pmessage"; }
					}
					elseif($pstatus=='Failed'){
						$new_status = -1;
						$info = "Payment cancelled. $pmessage";
					}
					else { $info = "Payment status: $pstatus. $pmessage"; }
					
					if($new_status==-1){
						if($pstatus=='Successful')$order->update_status('on-hold', $info);
						else $order->update_status('failed', $info); //'cancelled'
					}
					elseif($new_status==1)$order->update_status( 'completed', $info);
					
					$order->add_order_note($info,true); //for customer
					//$order->add_order_note($info);

					if($new_status==-1){
						wc_add_notice($info, 'error');
						$this->_log_stuff("$info\nOrderID: $order_id\nTransRef: $trans_ref\n".json_encode($porder,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
						wp_redirect($order->get_cancel_order_url()); exit;
					}
					elseif($new_status==1){
						$order->payment_complete($trans_ref);
						function_exists('wc_reduce_stock_levels')?wc_reduce_stock_levels($order_id):$order->reduce_order_stock();
						wp_redirect($this->get_return_url($order)); exit;
					}
					else {
						wc_add_notice($info, 'notice');
						//wp_redirect($order->get_checkout_payment_url()); exit;
					}
				}
			}
			
			wp_redirect(wc_get_page_permalink('cart'));	
		}
	
		private function _log_stuff($str){
			$ddate=date('jS M. Y g:ia');
			file_put_contents(__DIR__ .'/debug.log',"$ddate\n$str\n---------------\n",FILE_APPEND); 
		}
	}

   add_filter( 'woocommerce_payment_gateways','onepipe_add_to_woo');
}

function onepipe_add_to_woo($methods){
	$methods[] = 'WC_Gateway_OnePipe';
	return $methods;
}

add_filter((is_network_admin()?'network_admin_':'') .'plugin_action_links_'.plugin_basename(__FILE__),
	'onepipe_action_links');
function onepipe_action_links($links){
    $admin_settings_link = array(
    	'settings' => '<a href="'.admin_url('admin.php?page=wc-settings&tab=checkout&section='.ONEPIPE_PLUGIN_ID ).'" title="OnePipe Settings">Settings</a>'
    );
    return array_merge($links,$admin_settings_link);
}
