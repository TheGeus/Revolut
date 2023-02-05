=== Revolut Gateway for WooCommerce  ===
Contributors: revolutbusiness
Tags: revolut, revolut business, payments, gateway, payment gateway, credit card, card
Requires at least: 4.4
Tested up to: 6.1
Stable tag: 4.1.0
Requires PHP: 7.0
License: GPLv2 or later
License URI: http://www.apache.org/licenses/LICENSE-2.0

Start accepting credit card payments into your revolut account today. Easy, hassle free and cost-effective.

== Description ==

Revolut WooCommerce plugin lets you accept online card payments via your WooCommerce e-store in an easy and hassle-free fashion. The following guide will help you install and configure the Revolut Gateway for WooCommerce plugin.

To use the plugin you need to have a [Revolut Business account](https://business.revolut.com/signup "Sign up for a Business account") and an active [Merchant Account](https://business.revolut.com/merchant/ "Apply for a merchant account").

If you don't have a Revolut Business account:

* Sign up for a Business account and when asked the reason for opening the account make sure to select "Receive payments from customers" as one of the reasons
* Provide a description for your business and indicate a category that most closely defines your activities
* Provide the domain of your Woocommerce website when asked about website of your business

If you already have a Revolut Business account but your Merchant Account is not active:

* Go to the Home section in the Revolut Business web portal and select the [Merchant tab](https://business.revolut.com/merchant/)
* Click "Get started" and follow the steps to fill in the information of your business
* When prompted, provide the domain of your WooCommerce website

That's it! As soon as you install the Revolut Gateway for WooCommerce plugin you will be ready to start accepting payments. If you want to know more about the advantages of accepting payments via Revolut, you can take a look in [our website](https://www.revolut.com/business/online-payments).

= FEATURES =

* Accept debit and credit card payments [at great rates](https://www.revolut.com/business/business-account-plans)
* Accept payments via our new payment method: Revolut Pay
* Customise the style of the card field in the checkout
* Customise the payment actions (Authorise only or Authorise and capture)
* Refund and capture payments directly from your Woocommerce admin section
* Support for [WooCommerce subscriptions](https://woocommerce.com/products/woocommerce-subscriptions/)
* Support for manual order creation
* **NEW** Support for payments with Apple Pay and Google Pay

= QUICK INSTALLATION =

Follow these steps to install the plugin directly from your admin section:

  1. Log in to the admin section of your WooCommerce webstore
  1. On the menu, on the left hand side of the page, go to the `Plugins` section
  1. At the very top of the page, click the `Add New` button, next to the plugins page title
  1. Locate the `Search plugins...` field at the top-right of this page and search for **Revolut Gateway for WooCommerce**. You should see the plugin appear as the top result. (Make sure it says *By Revolut*)
  1. Click the `Install Now` button and then click the `Activate` button once the installation is done
  1. Congrats! You have just activated the Revolut Gateway for WooCommerce plugin. You'll be automatically redirected to the page with all the plugins you have installed

= MANUAL INSTALLATION =

Follow these steps to install the plugin manually using the *.zip* file that can be downloaded from this page:

  1. Log in to the admin section of your WooCommerce webstore
  1. On the menu, on the left hand side of the page, go the `Plugins` section
  1. At the very top of the page, click the `Add New` button, next to the plugins page title
  1. Click the `Upload Plugin` button, next to the `Add Plugins` page title
  1. Download the plugin file from this page and upload it
  1. Once the installation of the Revolut Gateway for WooCommerce plugin is successfully finished, you can click the `Activate Plugin` button
  1. Congrats! You have just activated the Revolut Gateway for WooCommerce plugin. You'll be automatically redirected to the page with all the plugins you have installed

= CONFIGURATION =

**Test in the Sandbox environment**

1. Log in to your WooCommerce dashboard as the admin role.
2. From the left sidebar menu, click **Plugins**.
3. Under `WooCommerce Revolut Gateway` plugin, click `Settings`.
4. Select the **Enable Revolut** check box.
5. From the **Select Mode** drop-down menu, select **Sandbox**.
6. In the **API Key Sandbox** field, add your Sandbox API key. For more information about getting the Sandbox API key, see [Test in the Sandbox environment](#tutorials-test-in-the-sandbox-environment).
7. In **Setup Webhook Sandbox**, click **Setup** to enable webhooks.
8. Click **Save changes** to apply the changes.

Now you can start testing payments using our [test cards](#tutorials-tutorials-test-in-the-sandbox-environment-use-test-cards).

**Use in the production environment**

1. Return to your WooCommerce dashboard as the admin role.
2. From the left sidebar menu, click **Plugins**.
3. Under `WooCommerce Revolut Gateway` plugin, click **Settings**.
4. Select the **Enable Revolut** check box.
5. From the **Select Mode** drop-down menu, select **Sandbox**.
6. In the **API Key Live** field, add your production API key. For more information about generating the API key, see [Get started: 2. Generate the API key](#get-started-2-generate-the-api-key).
7. In **Setup Webhook Live**, click **Setup** to enable webhooks.
8. Click **Save changes** to apply the changes.

Now you can start accepting real payments in your WooCommerce online store.

== Screenshots ==

1. Searching for the Revolut Gateway for WooCommerce plugin
2. The Revolut Gateway plugin has been added to your Wordpress plugins
3. The general Revolut API settings page for the Revolut Gateway for WooCommerce plugin
4. The Credit card payment settings
5. The Revolut Pay Button settings

== Changelog ==
= 4.1.0 =
* Added Popup card widget
* Fixed product page issue

= 4.0.2 =
* Adjusted minimum PHP version requirement

= 4.0.1 =
* Fixed warnings from lower version of PHP
* Increased recommended version of PHP

= 4.0.0 =
* Fast checkout full launch

= 3.9.0 =
* Fixed express checkout caching
* Fixed admin notifications

= 3.8.0 =
* Added Revolut Pay Express checkout functionality

= 3.7.0 =
* Improved webhook processing
* Updated cashback currency

= 3.6.0 =
* Updated currency list
* Fixed order validation result parsing issue

= 3.5.0 =
* Improved order result processing
* Added compatibility with review plugin

= 3.4.0 =
* Fixed parse notice

= 3.3.0 =
* Fixed subscriptions issue

= 3.2.2 =
* Added payment logos for Revolut Pay method

= 3.2.1 =
* Fixed partial refund issue
* Fixed cart clearing issue
* Fixed order creation issue when card field is empty

= 3.2.0 =
* Added the new version of Revolut Pay widget

= 3.1.6 =
* Fixing compatibility issue with PHP versions

= 3.1.5 =
* Fix minor payment button reloading issue

= 3.1.4 =
* Fixing compatibility issue with the older PHP versions

= 3.1.3 =
* Refactor to adhere to WordPress conventions
* Security updates

= 3.1.2 =
* Fixing security and vulnerability issues

= 3.1.1 =
* Added compatibility for Germanized plugin
* Added size configuration options for Payment Buttons (ApplePay&GooglePay)

= 3.1.0 =
* Added feature to trigger Apple Pay setup manually
* Added feature to set Webhooks automatically
* Fixed duplicated OR labels for payment buttons

= 3.0.2 =
* Fix minor issue ajax endpoint url

= 3.0.1 =
* Fix Pay Button minor issue for out of stock products

= 3.0.0 =
* Payment Request Button (ApplePay&GooglePay) support added

= 2.5.2 =
* Fix minor issue for payment amount validation

= 2.5.1 =
* Fix saved payment methods issue after customer login

= 2.5.0 =
* Avoid duplicated payments when customer account settings is enabled

= 2.4.2 =
* Fix duplicated order status update
* Validate saved payment tokens through API

= 2.4.1 =
* Fix refund issue
* Fix webhook callback order not found issue

= 2.4.0 =
* Refresh checkout page without reloading
* Update payment amount after order creation
* Fix card widget reloading when save card checkbox is updating
* Add configuration in order enable/disable card save feature

= 2.3.3 =
* Fix order process error when create customer checkbox is enabled
* Fix setting webhook issue

= 2.3.2 =
* Minor issues refactored
* Missing dependency issue solved

= 2.3.1 =
* Fixed duplicated order issue
* Tested with the latest WordPress and WooCommerce versions

= 2.3.0 =
* Optimize checkout validation

= 2.2.9 =
* Fix manual order page stack in loading issue
* Fix API callback issue
* Localization files added
* Information about failed Payment attempts added into the order

= 2.2.8 =
* Update available Revolut order currency list
* Update documentation link

= 2.2.7 =
* Fix duplicated API order creation

= 2.2.6 =
* Fix missing parameter issue

= 2.2.5 =
* Improve Revolut Widget error reporting

= 2.2.4 =
* Fix payment process error when some checkout address fields are missing

= 2.2.3 =
* Fix checkout validation issue

= 2.2.2 =
* Minor bug fixes

= 2.2.1 =
* Hotfix for version 2.2.0 for sites that did not have the WooCommerce subscriptions plugin

= 2.2.0 =
* Support for [WooCommerce subscriptions](https://woocommerce.com/products/woocommerce-subscriptions/)
* Support saving card information

= 2.1.0 =
* Support Multisite Wordpress installations
* Support Card Widget styling
* Support manual payments
* Support for multilanguage sites. The text inside of the Card widget will now adapt to the language of the website.

= 2.0.0 =
* Added Revolut Pay

= 1.2.5 =
* Create Woocommerce Order even if transaction failed
* Adjust create order flow
* Allow customer to update payment information at checkout
* Create Woocommerce order before verifying Revolut payment
* Handle webhook responses for different Woo order statuses
* Handle webhook received after payment

= 1.2.4 =
* Compatible with Jupiter theme

= 1.2.3 =
* Added support for refunding orders from the WooCommerce Order view
* Added support to capture orders by changing the status of the order in the WooCommerce order view
* Added webhook support. You can now setup webhooks from the plugin settings. Orders captured in the Revolut Business web portal will change the status of the WooCommerce order
* Fixed bug for mySQL versions older than 5.6.5 where "Something went wrong" was displayed instead of the card field
* Fixed code that was causing PHP notices and warnings to appear in the logs
* Fixed wording of multiple messages to improve clarity

= 1.2.1 =
* Fixed bug that created failed orders even if payment had been captured
* Added instructions in the settings page to get started quickly and easily

= 1.2.0 =
* Added support for "Authorize Only" order types
* Added option to easily switch between "Sandbox" and "Live" environments by keeping the keys saved
* Improved the Checkout widget visually to be compatible with more themes
* Fixed bug that created uncaptured transactions if the checkout form was not properly filled out by the user

= 1.1.5 =
* Minor bug fixes

= 1.0.1 =
* Fixing some compatibility issues with certain WooCommerce themes

= 1.0 =
* First stable version of the Revolut Gateway for WooCommerce plugin


== Upgrade Notice ==

= 1.0 =
* First stable version of the Revolut Gateway for WooCommerce plugin
