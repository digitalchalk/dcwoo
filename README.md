DigitalChalk WooCommerce Plugin (dcwoo)
========================================

Note: The DigitalChalk WooCommerce Plugin is not an officially supported plugin by the DigitalChalk development team.  It was written as a starting point for those wanting to extend and experiment with a Wordpress integration for DigitalChalk.  Please feel free to fork and extend and help support it for the future.

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

1. Commit and push your changes to `master`
2. Tag the commit: `git tag v2.0.6` (using your new version number)
3. Push the tag: `git push origin v2.0.6`

The GitHub Actions workflow will automatically update the version in the plugin files, build the zip, create a GitHub Release, and update the version manifest.

### Create a Test Release

To build a local zip for testing without pushing to GitHub, run the `makeRelease` script from the repo root:

```bash
./makeRelease
```

The script will:
1. Show the current highest version from the `releases/` folder
2. Prompt you for a new version number
3. Update the version in `update/latestversion`, `README.md`, and `src/dcwoo/dcwoo.php`
4. Zip the `src/dcwoo/` directory into `releases/dcwoo-<version>.zip`

You can then upload the generated zip to your WordPress site via Plugins > Add New > Upload to test before creating an official release.
