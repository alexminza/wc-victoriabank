=== WooCommerce Victoriabank Payment Gateway ===
Contributors: alexminza
Donate link: https://www.paypal.me/AlexMinza
Tags: WooCommerce, Moldova, Victoriabank, VB, bank, payment, gateway, visa, mastercard, credit card
Requires at least: 4.8
Tested up to: 5.8.1
Stable tag: trunk
Requires PHP: 7.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

WooCommerce Payment Gateway for Victoriabank

== Description ==

Accept Visa and Mastercard directly on your store with the Victoriabank payment gateway for WooCommerce.

= Features =

* Charge and Authorization card transaction types
* Reverse transactions – partial or complete refunds
* Admin order actions – complete authorized transaction
* Order confirmation email with card transaction details
* Free to use – [Open-source GPL-3.0 license on GitHub](https://github.com/alexminza/wc-victoriabank)

= Getting Started =

* [Installation Instructions](./installation/)
* [Frequently Asked Questions](./faq/)

== Installation ==

1. Generate the public / private key pair according to the instructions from *Appendix A*, section *"2. Key Generation and transmission"* of the *"e-Commerce Gateway merchant interface (CGI/WWW forms version)"* document received from the bank
2. Configure the plugin Connection Settings by performing one of the following steps:
    * **BASIC**: Upload the generated PEM key files and the bank public key
    * **ADVANCED**: Copy the PEM key files to the server, securely set up the owner and file system permissions, configure the paths to the files
3. Set the private key password
4. Provide the *Callback URL* to the bank to enable online payment notifications
5. Enable *Test* and *Debug* modes in the plugin settings
6. Perform all the tests described in *Appendix B*, section *"Test Cases"* of the document received from the bank:
    * **Test case No 1**: Set *Transaction type* to *Charge*, create a new order and pay with a test card
    * **Test case No 2**: Set *Transaction type* to *Authorization*, create a new order and pay with a test card, afterwards perform a full order refund
    * **Test case No 3**: Set *Transaction type* to *Charge*, create a new order and pay with a test card, afterwards perform a full order refund
7. Disable *Test* and *Debug* modes when ready to accept live payments

== Frequently Asked Questions ==

= How can I configure the plugin settings? =

Use the *WooCommerce > Settings > Payments > Victoriabank* screen to configure the plugin.

= Where can I get the Merchant Data and Connection Settings? =

The merchant data and connection settings are provided by Victoriabank. This data is used by the plugin to connect to the Victoriabank payment gateway and process the card transactions. Please see [https://www.victoriabank.md/en/details-corporate-banking-cards-ecommerce](https://www.victoriabank.md/en/details-corporate-banking-cards-ecommerce) and contact [Card.Acceptare@vb.md](mailto:Card.Acceptare@vb.md) for details.

= What store settings are supported? =

Victoriabank currently supports transactions in MDL (Moldovan Leu), EUR (Euro) and USD (United States Dollar).

= What is the difference between transaction types? =

* **Charge** submits all transactions for settlement.
* **Authorization** simply authorizes the order total for capture later. Use the *Complete transaction* order action to settle the previously authorized transaction.

= Why the last four digits of the card number are not received from the bank? =

Make sure Victoriabank has properly set up the *Callback URL* in the payment gateway terminal settings. See [Installation Instructions](./installation/) for more details.

To verify the exact response data received from the bank payment gateway - enable *Debug mode* logging in the plugin settings.

= How can I manually process a bank transaction response callback data message received by email from the bank? =

As part of the backup procedure Victoriabank payment gateway sends a duplicate copy of the transaction responses to a specially designated merchant email address specified during initial setup.
If the automated response payment notification callback failed the shop administrator can manually process the transaction response message received from the bank.
Go to the payment gateway settings screen *Payment Notification* section and click *Advanced*, paste the bank transaction response data as received in the email and click *Process*.

= How can I contribute to the plugin? =

If you're a developer and you have some ideas to improve the plugin or to solve a bug, feel free to raise an issue or submit a pull request in the [Github repository for the plugin](https://github.com/alexminza/wc-victoriabank).

You can also contribute to the plugin by translating it. Simply visit [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/wc-victoriabank) to get started.

== Screenshots ==

1. Plugin settings
2. Merchant data
3. Connection settings
4. Advanced connection settings
5. Refunds
6. Order actions

== Changelog ==

See [wc-victoriabank project releases on GitHub](https://github.com/alexminza/wc-victoriabank/releases) for details.

= 1.3.4 =
Updated Tested up to 5.8.1 and WC tested up to 5.7.1

= 1.3.3 =
Modified Victoriabank payment gateway URL for 3DS v2 compliance

= 1.3.2 =
Updated Tested up to 5.6 and WC tested up to 4.8.0

= 1.3 =
Added manual processing of bank transaction response callback data

= 1.2.2 =
Added support for EUR and USD currencies

= 1.2 =
Added Test mode option – use Victoriabank test payment gateway during development and integration tests

= 1.1.1 =
Minor improvements

= 1.1 =
* Simplified payment gateway setup
* Added key files upload
* Added payment method logo image selection
* Added validations for keys and settings
* Fixed email order meta fields error

= 1.0.1 =
* Added total refunds via payment gateway calculation (since WooCommerce 3.4)
* Improved logging and unsupported store settings diagnostics
* Check WooCommerce is active during plugin initialization

= 1.0 =
Initial release

== Upgrade Notice ==

= 1.3.4 =
Updated Tested up to 5.8.1 and WC tested up to 5.7.1

= 1.3.3 =
Modified Victoriabank payment gateway URL for 3DS v2 compliance
