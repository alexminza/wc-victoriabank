=== WooCommerce VictoriaBank Payment Gateway ===
Contributors: alexminza
Donate link: https://www.paypal.me/AlexMinza
Tags: WooCommerce, Moldova, VictoriaBank, payment, gateway
Requires at least: 4.8
Tested up to: 4.9.4
Stable tag: trunk
Requires PHP: 7.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

WooCommerce Payment Gateway for VictoriaBank

== Description ==

WooCommerce Payment Gateway for VictoriaBank

= Features =

* Charge and Authorization card transaction types
* Reverse transactions - partial or complete refunds
* Admin order actions - complete or reverse authorized transaction
* Order confirmation email with card transaction details

== Frequently Asked Questions ==

= How can I configure the plugin settings? =

Use the WooCommerce -> Settings -> Payments -> VictoriaBank screen to configure the plugin.

= Where can I get the Connection Settings data? =

The connection settings and merchant data are provided by VictoriaBank. This data is used by the plugin to connect to the VictoriaBank payment gateway and process the card transactions. Please see [www.victoriabank.md](https://www.victoriabank.md) and contact [Card.Acceptare@vb.md](mailto:Card.Acceptare@vb.md) for details.

= What store settings are supported? =

VictoriaBank currently supports transactions in MDL (Moldovan Leu).

= How can I contribute to the plugin? =

If you're a developer and you have some ideas to improve the plugin or to solve a bug, feel free to raise an issue or submit a pull request in the [Github repository for the plugin](https://github.com/alexminza/wc-victoriabank).

You can also contribute to the plugin by translating it. Simply visit [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/wc-victoriabank) to get started.

== Screenshots ==

1. Plugin settings
2. Connection settings
3. Order actions

== Changelog ==

= 1.0.1 =
* Added total refunds via payment gateway calculation (since WooCommerce 3.4)
* Improved logging and unsupported store settings diagnostics
* Check WooCommerce is active during plugin initialization

= 1.0 =
Initial release

== Upgrade Notice ==

= 1.0.1 =
See Changelog for details
