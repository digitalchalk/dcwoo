			<div class="wrap">
			    <?php screen_icon( 'options-general' ); ?>
			    <h2><?php esc_html_e( 'DigitalChalk Woo Integration Settings', 'dcwoo' ); ?></h2>
			
			    <form action="options.php" method="post">
				<?php wp_nonce_field('update-options'); ?>	
				<table class="form-table">
					<tr valign="top">
						<th scope="row"><label for="dcwoo_hostname"><?php _e( 'API v5 hostname', 'dcwoo' ); ?></label></th>
						<td>
							<input name="dcwoo_hostname" type="text" id="dcwoo_hostname" value="<?php echo get_option('dcwoo_hostname');?>" class="large-text" />
							<p class="description"><?php _e( 'Your DigitalChalk hostname (e.g. myhost.digitalchalk.com)', 'dcwoo' ); ?></p>
						</td>
					</tr>			
					<tr valign="top">
						<th scope="row"><label for="dcwoo_token"><?php _e( 'API v5 token', 'dcwoo' ); ?></label></th>
						<td>
							<input name="dcwoo_token" type="text" id="dcwoo_token" value="<?php echo get_option('dcwoo_token');?>" class="large-text" />
							<p class="description"><?php _e( 'The OAuth2 token provided to you by DigitalChalk.', 'dcwoo' ); ?></p>
						</td>
					</tr>
				</table>
				<p>
				<input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" />
				</p>
				<input type="hidden" name="action" value="update" />	
				<input type="hidden" name="page_options" value="dcwoo_hostname,dcwoo_token" />
				</form>
			</div>
