DigitalChalk WooCommerce Plugin (dcwoo)
========================================

### Assumptions

This plugin provides an integration between DigitalChalk and WooCommerce inside a WordPress site.  It is assumed that you have installed WordPress and the WooCommerce plugin prior to installing this plugin.

It is also assumed that you have Administrator level access to your WordPress site.

### Downloading the DCWoo Plugin

Download the latest dcwoo plugin zip file from the [GitHub Releases page](https://github.com/digitalchalk/dcwoo/releases/latest).


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

### Creating a Release

1. Update the version in `src/dcwoo/dcwoo.php` (both the `Version:` header and `DCWOO_VERSION_NUM`)
2. Commit and push your changes
3. Tag the commit: `git tag v2.0.6` (using your new version number)
4. Push the tag: `git push origin v2.0.6`

The GitHub Actions workflow will automatically build the zip, create a GitHub Release, and update the version manifest.