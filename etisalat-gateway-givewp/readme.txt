=== Etisalat Payment Gateway for GiveWP ===
Contributors: aheart037
Tags: givewp, donations, payment gateway, etisalat, epg
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.2
Requires Give: 2.30.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept donations through your bank's Etisalat Payment Gateway (EPG) with GiveWP donation forms.

== Description ==

This add-on connects GiveWP to the Etisalat Payment Gateway (EPG) e-commerce REST API provided by your bank (UBL, Etisalat UAE and other EPG partners).

**How it works**

1. The donor submits your GiveWP donation form.
2. The donation is registered with EPG (Registration API) and the donor is redirected to the secure EPG payment page (3D secure: Visa, Mastercard, and other methods enabled on your EPG account).
3. After paying, the donor is returned to your website and the transaction is completed with the EPG Finalization API.
4. The donation is marked as complete and GiveWP sends the donation receipt.

**Features**

* Works with both GiveWP donation form generations: the Visual Form Builder (v3) and option-based forms (v2).
* Off-site hosted payment page (no card data ever touches your site).
* Signed, expiring return URLs (GiveWP secure gateway routes).
* Transaction ID, approval code, card brand and masked card number stored as donation notes.
* EPG Refund API support (when enabled on your EPG account by your bank).
* Handles offline/central-bank payments (response code 210) as pending donations.
* Optional debug logging of Registration/Finalization/Refund calls (passwords are never logged).

== Installation ==

1. Install and activate the GiveWP plugin.
2. Upload the `etisalat-gateway-givewp` folder to `/wp-content/plugins/` (or upload the zip via Plugins → Add New → Upload).
3. Activate "Etisalat Payment Gateway for GiveWP".
4. Go to Donations → Settings → Payment Gateways → Etisalat and enter the credentials provided by your bank.
5. Enable the gateway under Donations → Settings → Payment Gateways → Gateways.

== Frequently Asked Questions ==

= Where do I get the credentials? =

Your bank's merchant integration team provides the Customer ID, User Name, Password, Store/Terminal values and the EPG endpoint URL. These are the same values used by the bank's WooCommerce plugin, if you were given one.

= What URL and port should I use? =

Per the EPG integration guide: production `https://ipg.comtrust.ae` and sandbox `https://demo-ipg.ctdev.comtrust.ae`, both on port `2443`. Use the URL your bank confirms for your account.

= Can I refund donations? =

Yes, if your bank has enabled the Refund API on your EPG account. Refund a donation from the donation details screen and the plugin will call the EPG Refund API.

== Changelog ==

= 1.0.0 =
* Initial release: Registration → payment page redirect → ReturnPath → Finalization flow, refunds, v2 + v3 form support, debug logging.
