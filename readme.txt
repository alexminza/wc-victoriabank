=== WooCommerce Victoriabank Payment Gateway ===
Contributors: alexminza
Donate link: https://www.paypal.me/AlexMinza
Tags: WooCommerce, Moldova, Victoriabank, VB, payment, gateway
Requires at least: 4.8
Tested up to: 5.2.1
Stable tag: trunk
Requires PHP: 7.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

WooCommerce Payment Gateway for Victoriabank

== Description ==

WooCommerce Payment Gateway for Victoriabank

= Features =

* Charge and Authorization card transaction types
* Reverse transactions - partial or complete refunds
* Admin order actions - complete authorized transaction
* Order confirmation email with card transaction details

= Getting Started =

* [Installation Instructions](./installation/)
* [Frequently Asked Questions](./faq/)

== Installation ==

1. Generate the private and public keys according to the instructions from *Appendix A*, section *"2. Key Generation and transmission"* of the *"e-Commerce Gateway merchant interface (CGI/WWW forms version)"* document received from the bank
2. Configure the plugin Connection Settings by performing one of the following steps:
    * **BASIC**: Upload the generated PEM key files and the bank public key
    * **ADVANCED**: Copy the PEM key files to the server, securely set up the owner and file system permissions, configure the paths to the files
3. Set the private key password (or leave the field empty if the private key is not encrypted)
3. Provide the *Callback URL* to the bank to enable online payment notifications
4. Perform all the tests described in *Appendix B*, section *"Test Cases"* of the document received from the bank:
    * **Test case No 1**: Set *Transaction type* to *Charge*, create a new order and pay with a test card
    * **Test case No 2**: Set *Transaction type* to *Authorization*, create a new order and pay with a test card, afterwards perform a full order refund
    * **Test case No 3**: Set *Transaction type* to *Charge*, create a new order and pay with a test card, afterwards perform a full order refund
6. Disable *Debug mode* when ready to accept live payments

== Frequently Asked Questions ==

= How can I configure the plugin settings? =

Use the *WooCommerce > Settings > Payments > Victoriabank* screen to configure the plugin.

= Where can I get the Merchant Data and Connection Settings? =

The merchant data and connection settings are provided by Victoriabank. This data is used by the plugin to connect to the Victoriabank payment gateway and process the card transactions. Please see [www.victoriabank.md](https://www.victoriabank.md) and contact [Card.Acceptare@vb.md](mailto:Card.Acceptare@vb.md) for details.

= What store settings are supported? =

Victoriabank currently supports transactions in MDL (Moldovan Leu).

= What is the difference between transaction types? =

* **Charge** submits all transactions for settlement.
* **Authorization** simply authorizes the order total for capture later. Use the *Complete transaction* order action to settle the previously authorized transaction.

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

= 1.1 =
Simplified payment gateway setup.
See Changelog for details.
