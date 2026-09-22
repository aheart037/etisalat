# Etisalat Payment Gateway — GiveWP add-on + bank reference plugin

This repository contains:

| Path | What it is |
|---|---|
| **`etisalat-gateway-givewp/`** | ✅ **The GiveWP payment gateway add-on** (this is what you install on your WordPress site) |
| `etisalat-gateway-givewp.zip` | Ready-to-upload zip of the add-on (Plugins → Add New → Upload Plugin) |
| `EtisalatPay (1).zip` | The bank's original WooCommerce plugin (kept for reference only — not needed for GiveWP) |

> ℹ️ The bank's WooCommerce plugin cannot be used with GiveWP — GiveWP needs an add-on built on its own Payment Gateway API. The add-on in this repository implements the exact same Etisalat/EPG REST flow (Registration → payment page → Finalization) natively for GiveWP.

---

## Quick start

1. **Install GiveWP** on your WordPress site (if not already installed).
2. **Install this add-on**: download `etisalat-gateway-givewp.zip` from this repository, then in WordPress go to **Plugins → Add New → Upload Plugin**, upload the zip and activate it.
3. **Configure it**: go to **Donations → Settings → Payment Gateways → Etisalat** and enter the credentials your bank provided:
   * **Customer ID** — your EPG "Customer" / merchant name *(required)*
   * **User Name** and **Password** — API credentials from the bank
   * **Store** and **Terminal** — only if the bank provided them
   * **EPG API Endpoint URL** and **Port** — use exactly what your bank confirms
     (production `https://ipg.comtrust.ae`, sandbox `https://demo-ipg.ctdev.comtrust.ae`, port `2443`)
4. **Enable the gateway**: **Donations → Settings → Payment Gateways → Gateways** → tick *Etisalat Payment Gateway* → Save.
5. Make a small **test donation** with the sandbox/test credentials your bank provided, and confirm the donation shows as **Complete** in **Donations → Donations** (with a TransactionID and ApprovalCode in the donation notes).

Full documentation: [`etisalat-gateway-givewp/README.md`](etisalat-gateway-givewp/README.md)

## How it works (short version)

```
Donor submits GiveWP form
   → plugin calls EPG Registration API (server-to-server, TLS 1.2, port 2443)
   → donor is redirected to the bank's secure payment page (3D secure)
   → bank returns the donor to the site
   → plugin calls EPG Finalization API
   → donation marked Complete / Pending / Failed in GiveWP, receipt email sent
```

Both donation form generations are supported: the **Visual Form Builder (v3)** and **option-based forms (v2)**.

## Testing the code (optional)

The add-on includes a standalone test harness that needs only the PHP CLI:

```bash
php etisalat-gateway-givewp/tests/run-tests.php
```

## Support

* Bank API reference: [EPG REST Integration Guide (V1.7)](https://www.ubldigital.com/portals/0/Pdf/EPG-REST-Integration-V17.pdf)
* GiveWP gateway developer guide: [How to Build a Gateway Add-on for GiveWP](https://docs.nexcess.com/software/give/how-to-build-a-gateway-add-on-for-givewp/)
