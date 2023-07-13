DigitalChalk WooCommerce Plugin (dcwoo)
========================================

### Assumptions

This plugin provides an integration between DigitalChalk and WooCommerce inside a WordPress site.  It is assumed that you have installed WordPress and the WooCommerce plugin prior to installing this plugin.

It is also assumed that you have Administrator level access to your WordPress site.

### Downloading the DCWoo Plugin

Download the latest dcwoo plugin zip file from GitHub.  You can download the latest version of the plugin from this link: https://github.com/digitalchalk/dcwoo/raw/master/releases/dcwoo-2.0.2.zip


### Installing the Plugin

Login to your WordPress admin dashboard (wp-admin).  From the side menu, select Plugins > Add New.  Click "Upload" under the Install Plugins header.  Upload the plugin zip file (that you downloaded in the previous step) into WordPress.

### Configure the Plugin

Select Plugins > Installed Plugins.  Find the "DigitalChalk / WooCommerce Integration" plugin.  Click "Activate" under the plugin.

Click on "Settings" under the "DigitalChalk / WooCommerce Integration" plugin.

For API v5 Hostname, enter your DigitalChalk virtual host name (e.g. myhost.digitalchalk.com)
For API v5 Token, enter your token (this value will be supplied to you by DigitalChalk Support)

Click on "Save Changes".

### Configure Products

In wp-admin, click on the "Products" in the left menu (right under "WooCommerce").  Under Products, click on the "Add DigitalChalk Product" link.  You will be presented with a list of available DigitalChalk offerings.  To create a WooCommerce product for one of these offering, click on "Make Product".  The DCWoo plugin will automatically import the title, description, and student price of the new product (but feel free to change any of these).  Be sure to click on the "Publish" button when you are done making changes.

The new product should now be available in your WooCommerce store.  It acts like any normal WooCommerce product, and can be added or removed from the cart, or purchased by a user.  If the user purchases a DigitalChalk product, they will be automatically registered for the course on DigitalChalk (and a user automatically created for them on DigitalChalk if it doesn't exist already).  Note that registration does not take place until payment is made (if payment is required).

If there are problems registering the user, but sure to check the notes on the WooCommerce order (wp-admin > WooCommerce > Orders).
















