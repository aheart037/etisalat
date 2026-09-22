<?php
/**
 * Plugin Name:       Etisalat Payment Gateway for GiveWP
 * Plugin URI:        https://github.com/aheart037/etisalat
 * Description:       Accept donations through the Etisalat Payment Gateway (EPG) — Visa, Mastercard and other payment methods — with GiveWP donation forms. Redirects donors to the secure Etisalat payment page and finalizes the transaction when they return.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            Almarah Foundation
 * Author URI:        https://almarah.org
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       etisalat-gateway-givewp
 * Domain Path:       /languages
 * Requires Plugins:  give
 *
 * Etisalat Payment Gateway for GiveWP is free software: you can redistribute it
 * and/or modify it under the terms of the GNU General Public License as published
 * by the Free Software Foundation, either version 2 of the License, or any later version.
 *
 * This add-on implements the Etisalat Payment Gateway (EPG) e-commerce REST API:
 * Registration -> redirect to Payment Page -> ReturnPath callback -> Finalization.
 * It follows the GiveWP Payment Gateway API (givewp_register_payment_gateway).
 */

defined('ABSPATH') || exit;

define('GIVE_ETISALAT_VERSION', '1.0.0');
define('GIVE_ETISALAT_FILE', __FILE__);
define('GIVE_ETISALAT_DIR', plugin_dir_path(__FILE__));
define('GIVE_ETISALAT_URL', plugin_dir_url(__FILE__));

/**
 * Register the gateway with GiveWP.
 *
 * The "givewp_register_payment_gateway" action only fires when GiveWP is active,
 * so this callback never runs when GiveWP is missing.
 */
add_action('givewp_register_payment_gateway', static function ($paymentGatewayRegister) {
    if (!class_exists('EtisalatApi')) {
        require_once GIVE_ETISALAT_DIR . 'includes/class-etisalat-api.php';
    }

    if (!class_exists('EtisalatGateway')) {
        require_once GIVE_ETISALAT_DIR . 'includes/class-etisalat-gateway.php';
    }

    $paymentGatewayRegister->registerGateway(EtisalatGateway::class);
});

/**
 * Register the settings section: Donations -> Settings -> Payment Gateways -> Etisalat.
 */
add_filter('give_get_sections_gateways', static function ($sections) {
    $sections[EtisalatGateway_Settings::SECTION_ID] = __('Etisalat Payment Gateway', 'etisalat-gateway-givewp');

    return $sections;
});

/**
 * Register the settings fields for the Etisalat section.
 */
add_filter('give_get_settings_gateways', static function ($settings) {
    if (function_exists('give_get_current_setting_section')
        && give_get_current_setting_section() === EtisalatGateway_Settings::SECTION_ID) {
        $settings = EtisalatGateway_Settings::getFields();
    }

    return $settings;
});

/**
 * Helper for settings definition (kept separate from the gateway logic).
 */
class EtisalatGateway_Settings
{
    const SECTION_ID = 'etisalat';

    /**
     * Default sandbox (staging) endpoint provided in the EPG integration guide.
     */
    public static function defaultGatewayUrl()
    {
        return 'https://ipg.comtrust.ae';
    }

    /**
     * Settings shown at Donations -> Settings -> Payment Gateways -> Etisalat.
     *
     * @return array
     */
    public static function getFields()
    {
        return [
            [
                'id'   => 'give_etisalat_title',
                'type' => 'title',
                'name' => __('Etisalat Payment Gateway Settings', 'etisalat-gateway-givewp'),
                'desc' => sprintf(
                    /* translators: %s: link to the gateway settings docs */
                    __(
                        'Connect GiveWP to your bank\'s Etisalat Payment Gateway (EPG). The credentials below are provided by your bank\'s merchant integration team. See the <a href="%s" target="_blank" rel="noopener noreferrer">EPG REST integration guide</a> for reference.',
                        'etisalat-gateway-givewp'
                    ),
                    'https://www.ubldigital.com/portals/0/Pdf/EPG-REST-Integration-V17.pdf'
                ),
            ],
            [
                'id'          => 'give_etisalat_customer_id',
                'name'        => __('Customer ID', 'etisalat-gateway-givewp'),
                'desc'        => __('Your EPG Customer ID (sometimes called "Customer" or "Merchant Name"). Required.', 'etisalat-gateway-givewp'),
                'type'        => 'text',
                'default'     => '',
                'placeholder' => __('e.g. Demo Merchant', 'etisalat-gateway-givewp'),
            ],
            [
                'id'          => 'give_etisalat_username',
                'name'        => __('User Name', 'etisalat-gateway-givewp'),
                'desc'        => __('API user name provided by your bank (used for user name/password authentication).', 'etisalat-gateway-givewp'),
                'type'        => 'text',
                'default'     => '',
                'placeholder' => __('e.g. Demo_fY9c', 'etisalat-gateway-givewp'),
            ],
            [
                'id'      => 'give_etisalat_password',
                'name'    => __('Password', 'etisalat-gateway-givewp'),
                'desc'    => __('API password provided by your bank.', 'etisalat-gateway-givewp'),
                'type'    => 'password',
                'default' => '',
            ],
            [
                'id'      => 'give_etisalat_store',
                'name'    => __('Store', 'etisalat-gateway-givewp'),
                'desc'    => __('Store value from your bank, if provided (optional).', 'etisalat-gateway-givewp'),
                'type'    => 'text',
                'default' => '',
            ],
            [
                'id'      => 'give_etisalat_terminal',
                'name'    => __('Terminal', 'etisalat-gateway-givewp'),
                'desc'    => __('Terminal value from your bank, if provided (optional).', 'etisalat-gateway-givewp'),
                'type'    => 'text',
                'default' => '',
            ],
            [
                'id'          => 'give_etisalat_gateway_url',
                'name'        => __('EPG API Endpoint URL', 'etisalat-gateway-givewp'),
                'desc'        => sprintf(
                    /* translators: 1: production url, 2: sandbox url */
                    __('The URL of the EPG server. Production: %1$s. Sandbox (staging): %2$s. Use the URL your bank confirms for your account.', 'etisalat-gateway-givewp'),
                    '<code>https://ipg.comtrust.ae</code>',
                    '<code>https://demo-ipg.ctdev.comtrust.ae</code>'
                ),
                'type'        => 'text',
                'default'     => self::defaultGatewayUrl(),
                'placeholder' => 'https://ipg.comtrust.ae',
            ],
            [
                'id'      => 'give_etisalat_port',
                'name'    => __('EPG API Port', 'etisalat-gateway-givewp'),
                'desc'    => __('Port used by the EPG REST API. Usually 2443. Set to 443 if your bank tells you otherwise.', 'etisalat-gateway-givewp'),
                'type'    => 'text',
                'default' => '2443',
            ],
            [
                'id'      => 'give_etisalat_transaction_hint',
                'name'    => __('Transaction Hint', 'etisalat-gateway-givewp'),
                'desc'    => __('Controls which payment instruments are offered and the capture behaviour. Default: <code>CPT:Y;VCC:Y;</code> (credit/debit cards, automatic capture). Only change this if your bank instructs you to.', 'etisalat-gateway-givewp'),
                'type'    => 'text',
                'default' => 'CPT:Y;VCC:Y;',
            ],
            [
                'id'      => 'give_etisalat_accept_header',
                'name'    => __('Accept Header', 'etisalat-gateway-givewp'),
                'desc'    => __('<code>application/json</code> is the documented value for the EPG REST API. If your bank\'s EPG instance rejects it, try <code>text/xml-standard-api</code> (used by some bank-provided plugins). The plugin also retries automatically with the alternative value.', 'etisalat-gateway-givewp'),
                'type'    => 'select',
                'default' => 'application/json',
                'options' => [
                    'application/json'      => 'application/json',
                    'text/xml-standard-api' => 'text/xml-standard-api',
                ],
            ],
            [
                'id'      => 'give_etisalat_checkout_label',
                'name'    => __('Checkout Label', 'etisalat-gateway-givewp'),
                'desc'    => __('The payment method label donors see on your donation form.', 'etisalat-gateway-givewp'),
                'type'    => 'text',
                'default' => __('Debit / Credit Card', 'etisalat-gateway-givewp'),
            ],
            [
                'id'      => 'give_etisalat_description',
                'name'    => __('Donor-Facing Description', 'etisalat-gateway-givewp'),
                'desc'    => __('Shown to donors below the gateway on the donation form. You may include basic HTML.', 'etisalat-gateway-givewp'),
                'type'    => 'textarea',
                'default' => __('Secure payment with credit/debit card via the Etisalat Payment Gateway. You will be redirected to the secure payment page to complete your donation.', 'etisalat-gateway-givewp'),
            ],
            [
                'id'      => 'give_etisalat_sslverify',
                'name'    => __('Verify SSL Certificate', 'etisalat-gateway-givewp'),
                'desc'    => __('Verify the EPG server\'s SSL certificate against the bundled certificate authority bundle. Disable only if your bank confirms their (sandbox) certificate cannot be verified.', 'etisalat-gateway-givewp'),
                'type'    => 'radio_inline',
                'default' => 'enabled',
                'options' => [
                    'enabled'  => __('Enabled', 'etisalat-gateway-givewp'),
                    'disabled' => __('Disabled', 'etisalat-gateway-givewp'),
                ],
            ],
            [
                'id'      => 'give_etisalat_debug',
                'name'    => __('Debug Logging', 'etisalat-gateway-givewp'),
                'desc'    => __('Log Registration, Finalization and Refund communication with the gateway under Donations → Tools → Logs → Payment Gateway (passwords are never logged). Recommended while testing.', 'etisalat-gateway-givewp'),
                'type'    => 'checkbox',
                'default' => 'off',
            ],
            [
                'id'   => 'give_etisalat_sectionend',
                'type' => 'sectionend',
            ],
        ];
    }
}

/**
 * Show an admin notice when GiveWP is not active.
 */
add_action('admin_notices', static function () {
    if (!function_exists('give') || !class_exists('Give')) {
        echo '<div class="error"><p><strong>'
            . esc_html__('Etisalat Payment Gateway for GiveWP', 'etisalat-gateway-givewp')
            . ':</strong> '
            . esc_html__('The GiveWP donation plugin must be installed and activated for this add-on to work.', 'etisalat-gateway-givewp')
            . '</p></div>';
    }
});

/**
 * Load translations.
 */
add_action('init', static function () {
    load_plugin_textdomain('etisalat-gateway-givewp', false, dirname(plugin_basename(GIVE_ETISALAT_FILE)) . '/languages');
});

