<script type="text/javascript">
function retryOrder() {
	var data = {
			action : 'resolve_order',
			order_id : '<?php echo $order_id; ?>'
	}
	jQuery('#retryResultsContainer').html("Retrying order. Please wait");
	jQuery.post(ajaxurl, data, function(responseData) {
		var response = JSON.parse(responseData);
		if(response.process_result && response.process_result == 'true') {
			jQuery('#retryResultsContainer').html("Success!  The order was successfully processed.");
		} else {
			jQuery('#retryResultsContainer').html("Failed!  See the notes on the order for more details.");
		}
	});
}
</script>
<div class="wrap">
<?php screen_icon( 'options-general' ); ?>
<h2><?php esc_html_e( 'Resolve Order Issue', 'dcwoo' ); ?></h2>
<div>
<p>
Clicking "Reprocess Order" below will try to contact DigitalChalk again to process this order or you can <a href="<?php echo admin_url('post.php?post=' . $order_id . '&action=edit');?>">go back to the order page</a>
</p>
</div>
<div id="retryResultsContainer">
<a href="javascript:void(0);" onclick="retryOrder();" class="add-new-h2">Reprocess Order</a>
</div>

</div>
