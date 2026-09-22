# Etisalat Payment Gateway for GiveWP

A GiveWP payment gateway add-on for the **Etisalat Payment Gateway (EPG)** e-commerce REST API — the same gateway your bank (UBL and other Etisalat/EPG partners) provides.

Donors are redirected to the bank's secure, hosted payment page (3D secure — Visa, Mastercard and any other instruments enabled on your EPG account). No card data is ever collected or stored on your website.

---

## Requirements

| Requirement | Version |
|---|---|
| WordPress | 5.8+ |
| PHP | 7.2+ (with the `curl` extension, recommended) |
| GiveWP | 2.24+ (works with GiveWP 3.x / 4.x) |
| An EPG merchant account | credentials issued by your bank |

## Installation

1. Install and activate [GiveWP](https://wordpress.org/plugins/give/).
2. Upload the `etisalat-gateway-givewp` folder to `/wp-content/plugins/`, or upload the plugin zip via **Plugins → Add New → Upload Plugin**.
3. Activate **Etisalat Payment Gateway for GiveWP**.
4. Go to **Donations → Settings → Payment Gateways → Etisalat** and fill in your credentials (below).
5. Under **Donations → Settings → Payment Gateways → Gateways**, tick **Etisalat Payment Gateway** in the *Enabled Gateways* list and save.

## Configuration

Settings live at **Donations → Settings → Payment Gateways → Etisalat**:

| Setting | Description | Default |
|---|---|---|
| **Customer ID** | Your EPG "Customer" / merchant name from the bank. Required. | — |
| **User Name** | EPG API user name (user name/password authentication). | — |
| **Password** | EPG API password. | — |
| **Store** | Store value from the bank, if provided. Optional. | — |
| **Terminal** | Terminal value from the bank, if provided. Optional. | — |
| **EPG API Endpoint URL** | Production: `https://ipg.comtrust.ae` — Sandbox: `https://demo-ipg.ctdev.comtrust.ae`. Use the URL your bank confirms. | `https://ipg.comtrust.ae` |
| **EPG API Port** | API port. | `2443` |
| **Transaction Hint** | Payment instruments / capture behaviour. `CPT:Y;VCC:Y;` = cards with automatic capture. | `CPT:Y;VCC:Y;` |
| **Accept Header** | `application/json` is the documented value for the EPG REST API. If your bank's EPG instance rejects it, switch to `text/xml-standard-api` (used by the bank's WooCommerce plugin). The plugin also retries automatically. | `application/json` |
| **Checkout Label** | The label donors see on the donation form. | `Debit / Credit Card` |
| **Donor-Facing Description** | Text shown under the gateway on the donation form (HTML allowed). | — |
| **Verify SSL Certificate** | Verify the EPG server certificate against the bundled CA bundle. | Enabled |
| **Debug Logging** | Log all EPG communication under **Donations → Tools → Logs → Payment Gateway** (passwords are never logged). Recommended while testing. | Disabled |

## How the transaction flow works

```
Donation form ──► GiveWP creates a "pending" donation
      │
      ▼
EPG Registration API  (server-to-server, TLS 1.2, port 2443)
      │  returns TransactionID + Payment Page URL
      ▼
Donor is redirected to a signed GiveWP route that auto-submits
the TransactionID to the EPG Payment Page (POST form, as EPG requires)
      │
      ▼
Donor authenticates (3D secure) and pays on the bank's page
      │
      ▼
EPG returns the donor to the ReturnPath URL (POSTs TransactionID)
      │
      ▼
EPG Finalization API  (server-to-server)
      │  ResponseCode 0   → donation completed, receipt sent
      │  ResponseCode 210 → donation stays pending (offline/Central Bank payment)
      │  anything else    → donation marked failed, donor sent to the failed page
      ▼
Donor is redirected to the donation success page / receipt
```

> **Why the ReturnPath is not a signed URL:** EPG rejects return URLs longer than 256 characters (error 6560), and a GiveWP signed route's signature, expiration and argument list alone add ~170 characters. The plugin therefore uses a compact gateway route and keeps security where it belongs: the TransactionID EPG posts back must match the one stored at Registration, and only the server-to-server Finalization response can complete a donation. Requests without a valid TransactionID — and temporary API errors — never change a pending donation.

Every EPG interaction is recorded on the donation (TransactionID, ApprovalCode, masked card number, card brand, amount charged, UniqueID) so you can reconcile payments with the bank.

## Refunds

If your bank has **enabled the Refund API** on your EPG account (it requires bank approval), open a donation in **Donations → Donations**, change its status to *Refunded*, tick the *Refund in Etisalat Gateway* checkbox and save. The plugin calls the EPG Refund API for the full amount. If the refund fails, the reason is stored as a donation note and you can refund from the bank portal instead.

## Recurring donations

Recurring donations are **not supported** in this release (the EPG card-tokenization / recurrence APIs are not implemented yet). One-time donations work on any form. On donation forms where recurring is required, GiveWP will offer the recurring-capable gateways instead.

## Testing with the sandbox

1. Ask your bank's merchant integration team for **sandbox/staging credentials** (Customer ID, User Name, Password, Store/Terminal) and **test card numbers**.
2. In the gateway settings, set **EPG API Endpoint URL** to the sandbox URL (per the EPG guide: `https://demo-ipg.ctdev.comtrust.ae`, port `2443`) and tick **Debug Logging**.
3. Make a small test donation. Check **Donations → Donations**: it should be marked *Complete*, with the EPG **TransactionID** and **ApprovalCode** in the donation notes.
4. Debug logs are under **Donations → Tools → Logs → Payment Gateway** (Registration / Finalization requests and responses).
5. When moving to production, switch the endpoint URL and credentials to the production values your bank provides and untick Debug Logging.

## Developer notes

### Relationship to the GiveWP example gateway

This add-on was built on the official [GiveWP example gateway](https://github.com/impress-org/givewp-example-gateway) and follows its structure:

| Example gateway | This plugin |
|---|---|
| `example-gateway-givewp.php` registers gateways on `givewp_register_payment_gateway` | `etisalat-gateway-givewp.php` does the same |
| `ExampleGatewayOffsiteClass extends PaymentGateway` | `EtisalatGateway extends PaymentGateway` |
| `createPayment()` returns `RedirectOffsite` | Same |
| `$secureRouteMethods` + `handleCreatePaymentRedirect(): RedirectResponse` | `$secureRouteMethods` + `handleEtisalatPaymentPage()` (signed, browser-only) and `handleEtisalatReturn(): RedirectResponse` |
| `enqueueScript()` + `formSettings()` + JS object via `window.givewp.gateways.register()` | Same (v3 Visual Form Builder support) |
| `getLegacyFormFieldMarkup()` (v2 option-based forms) | Same |
| `refundDonation(): PaymentRefunded` | Same (wired to the EPG Refund API) |
| `class-example-gateway-api.php` (separate API class) | `includes/class-etisalat-api.php` (EPG REST client) |
| Onsite example: catch → `DonationStatus::FAILED()` + `DonationNote` + `throw PaymentGatewayException` | Same pattern in `EtisalatGateway::createPayment()` |

Intentional deviations, with reasons:

1. **The EPG ReturnPath uses an unsigned route (`$routeMethods`), not a signed one.** The example puts its return handler in `$secureRouteMethods`, but a GiveWP signed route adds ~170 characters of signature/expiration/arg data and EPG rejects ReturnPaths over 256 characters (error 6560). Verification is instead done through the TransactionID match plus the Finalization API response. The browser-facing payment-page redirect *is* a signed route, exactly like the example.
2. **The donor is POSTed to the payment page.** The example builds a GET redirect URL; EPG requires the TransactionID to be submitted as a POST form to the Payment Page, hence the signed intermediate route.
3. **No subscription module.** The example ships one; recurring donations need EPG card tokenization which is not implemented yet (see *Recurring donations* above).
4. **Admin settings.** The example has none; this plugin registers a settings section with `give_get_sections_gateways` / `give_get_settings_gateways`, the same mechanism GiveWP core uses for its own gateways.

* Gateway ID: `etisalat` (registered on `givewp_register_payment_gateway`).
* The gateway extends GiveWP's `PaymentGateway` class. The intermediate payment-page redirect uses a **signed gateway route** (browser-only URL, expires after one day). The EPG ReturnPath uses a compact unsigned route because EPG limits it to 256 characters — verification is done through the TransactionID match and the Finalization API response instead.
* Supports **v3 (Visual Form Builder)** forms via `enqueueScript()`/`formSettings()` and **v2 (option-based)** forms via `getLegacyFormFieldMarkup()`.
* Filters available:
  * `give_etisalat_api_config` – override the API client configuration.
  * `give_etisalat_registration_params` – modify the Registration request parameters.
  * `give_etisalat_transaction_hint` – modify the TransactionHint.
  * `give_etisalat_description` – modify the donor-facing description.
  * `give_etisalat_curl_options` – add/override cURL options for EPG calls.
  * `give_etisalat_redirect_page_title` – the "redirecting…" page title.
* Action available: `give_etisalat_donation_completed` – fires after a successful Finalization.

### Running the offline test suite

The plugin ships a small standalone test harness (no WordPress required):

```bash
php tests/run-tests.php
```

It stubs the WordPress/GiveWP functions, injects canned EPG responses and verifies the API client's request payloads, response parsing, error handling, response-code-210 (pending) handling and endpoint URL building.

## References

* [EPG REST Integration Guide (V1.7)](https://www.ubldigital.com/portals/0/Pdf/EPG-REST-Integration-V17.pdf) — the bank's official documentation
* [GiveWP: How to Build a Gateway Add-on](https://docs.nexcess.com/software/give/how-to-build-a-gateway-add-on-for-givewp/)
* [GiveWP example gateway add-on](https://github.com/impress-org/givewp-example-gateway)

## License

GPL-2.0-or-later
