<script type="text/javascript">
function createOffering(selectedOfferingId) {	
	
	var postUrl = '<?php echo admin_url('edit.php?post_type=product&page=add_dc_product'); ?>';
	
	var f = document.createElement("form");
	f.setAttribute('method', 'POST');
	f.setAttribute('action', postUrl);

	var i = document.createElement('input');
	i.setAttribute('type', 'text');
	i.setAttribute('name', 'offeringId');
	i.setAttribute('value', selectedOfferingId);

	f.appendChild(i);
	f.submit();
	
	//return false;
}
function getDCOfferings(pageOffset, filter) {

	var data = {
			action : 'get_dc_offerings'
	};
	if(pageOffset > 0) {
		data.offset = pageOffset;
	}
	if(filter) {
		data.filter = filter;
	}
	jQuery('#offeringContents').html('Getting offerings...please wait');
	jQuery.post(ajaxurl, data, function(responseData) {
		var response = JSON.parse(responseData);
		if(response.api_result == 'failed') {
			
			jQuery('#offeringContents').html('An error occurred getting the offerings from DigitalChalk');
			if(response.error) {
				jQuery('#offeringContents').append('<br>ERROR ' + response.error);
			}
			if(response.error_description) {
				jQuery('#offeringContents').append('<br>' + response.error_description);
			}
			if(response.api_request_url) {
				jQuery('#offeringContents').append('<br>Using request URL of ' + response.api_request_url);
			}
			
		} else if(response.api_result == 'success') {
			var output = '';
			if(!filter) {
				filter = '';
			}
			if(response.results) {
				output = '<table class="wp-list-table widefat fixed posts" cellspacing="0">';
				output += '<tr><th width="10%"></th><th width="20%">Identifier</th><th width="20%">Offering Title';
				if(filter) {
					output += ' <small>(filter: ' + filter + ')</small>';
				}
				output += '</th><th width="5%">Price</th><th></th></tr>';			
				for(var i = 0; i < response.results.length; i++) {
					var offering = response.results[i];
					output += '<tr>';
					
					//output += '<td width="10%"><a href="javascript:void(0);" onclick="createOffering(\'' + offering.id + '\');" class="add-new-h2">Make Product</a>';
					output += '<td width="10%"><form action="<?php echo admin_url('edit.php?post_type=product&page=add_dc_product')?>" method="POST"><input type="hidden" name="offeringId" value="' + offering.id + '"/><input class="add-new-h2" type="submit" value="Make Product"/></form>';
					output += '</td>';

					output += '<td>' + offering.id + '</td>';
					output += '<td>' + offering.title + '</td>';

					if(offering.price) {
						output += '<td>' + offering.price.toFixed(2) + '</td>';
					} else {
						output += '<td>0.00</td>';
					}		
								
					output += '<td>';
					if(offering.catalogDescription) {
						output += jQuery(offering.catalogDescription).text().substring(0,70);
					} 
					output += '</td>';
					
					output += '</tr>';
				}

				if(response.nextOffset || response.prevOffset) {
					output += '<tr>';
					output += '<td colspan="2">';
					if(response.prevOffset) {
						output += '<a href="javascript:void(0);" onclick="getDCOfferings(' + response.prevOffset + ', \'' + filter + '\');">&laquo; Previous Page</a>';
					}
					output += '</td>';
					output += '<td></td><td></td>';
					output += '<td>';
					if(response.nextOffset) {
						output += '<a href="javascript:void(0);" onclick="getDCOfferings(' + response.nextOffset + ', \'' + filter + '\');">Next Page &raquo;</a>';
					}
					// put next page here
					output += '</td>';
					output += '</tr>';
					output += '</tr>';
				}
								
				output += '</table>';
			}
			jQuery('#offeringContents').html(output);
			
		} else {
			jQuery('#offeringContents').html('An unknown error occurred getting the offerings from DigitalChalk');
		}
		
	});	
}

function filterOfferings() {
	var offeringFilter = jQuery('#offeringFilter').val();
	getDCOfferings(0, jQuery.trim(offeringFilter));
	return false;
}

jQuery(function() {	
	getDCOfferings();
});

</script>
<div class="wrap">
<?php screen_icon( 'options-general' ); ?>
<h2><?php esc_html_e( 'Add DigitalChalk Product', 'dcwoo' ); ?>&nbsp;<a href="javascript:void(0);" onclick="getDCOfferings();" class="add-new-h2">Refresh Offerings</a></h2>
<div id="offeringFilterContainer">

	<p class="search-box">
	<label class="screen-reader-text" for="offeringFilter">Filter Offerings</label>
	<input type="text" id="offeringFilter" name="offeringFilter">&nbsp;<button class="button action" onclick="filterOfferings();">Filter Offerings</button>
	</p>

</div>
<div id="offeringContents">
Click "Refresh Offerings" above to retreive the current list of offerings from DigitalChalk			
</div>
</div>
