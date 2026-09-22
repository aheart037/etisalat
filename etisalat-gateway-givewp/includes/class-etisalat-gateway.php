<?php
/**
 * Etisalat Payment Gateway (EPG) integration for GiveWP.
 *
 * Transaction flow (EPG e-commerce REST, 3D secure redirection model):
 *
 *  1. createPayment()             - the donor submits the donation form. GiveWP
 *                                   creates the donation, then this method
 *                                   registers the transaction with EPG
 *                                   (Registration API) and returns a
 *                                   RedirectOffsite command pointing to an
 *                                   intermediate route.
 *  2. handleEtisalatPaymentPage() - a signed route that renders a page which
 *                                   auto-submits a POST form (with the
 *                                   TransactionID) to the EPG Payment Page,
 *                                   exactly as required by the EPG guide.
 *  3. The donor authenticates (3D secure) and pays on the EPG hosted page.
 *  4. handleEtisalatReturn()      - EPG returns the donor to the signed
 *                                   ReturnPath URL. This method reads the
 *                                   TransactionID, calls the Finalization API
 *                                   and marks the donation complete/failed.
 *
 * Supports both donation form generations:
 *  - v2 (option-based form editor) via getLegacyFormFieldMarkup()
 *  - v3 (visual form builder) via enqueueScript() + formSettings()
 *
 * @package etisalat-gateway-givewp
 */

use Exception;
use Give\Donations\Models\Donation;
use Give\Donations\Models\DonationNote;
use Give\Donations\ValueObjects\DonationStatus;
use Give\Framework\Http\Response\Types\RedirectResponse;
use Give\Framework\PaymentGateways\Commands\PaymentRefunded;
use Give\Framework\PaymentGateways\Commands\RedirectOffsite;
use Give\Framework\PaymentGateways\Exceptions\PaymentGatewayException;
use Give\Framework\PaymentGateways\Log\PaymentGatewayLog;
use Give\Framework\PaymentGateways\PaymentGateway;

defined('ABSPATH') || exit;

/**
 * @inheritDoc
 */
class EtisalatGateway extends PaymentGateway
{
    /**
     * Signed routes used by this gateway.
     *
     * @inheritDoc
     */
    public $secureRouteMethods = [
        'handleEtisalatPaymentPage',
        'handleEtisalatReturn',
    ];

    /**
     * @inheritDoc
     */
    public static function id(): string
    {
        return 'etisalat';
    }

    /**
     * @inheritDoc
     */
    public function getId(): string
    {
        return self::id();
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return __('Etisalat Payment Gateway', 'etisalat-gateway-givewp');
    }

    /**
     * @inheritDoc
     */
    public function getPaymentMethodLabel(): string
    {
        return give_get_option(
            'give_etisalat_checkout_label',
            __('Debit / Credit Card', 'etisalat-gateway-givewp')
        );
    }

    /**
     * Register the JS gateway for donation forms created with the
     * Visual Form Builder (v3).
     *
     * @since 2.30.0
     * @inheritDoc
     */
    public function enqueueScript(int $formId)
    {
        wp_enqueue_script(
            'etisalat-gateway-givewp',
            GIVE_ETISALAT_URL . 'assets/js/etisalat-gateway.js',
            ['react', 'wp-element'],
            GIVE_ETISALAT_VERSION,
            true
        );
    }

    /**
     * Pass data to the JS gateway counterpart (v3 forms).
     *
     * @inheritDoc
     */
    public function formSettings(int $formId): array
    {
        return [
            'message' => wp_kses_post($this->getDonorFacingDescription()),
        ];
    }

    /**
     * Donor-facing description from the gateway settings.
     *
     * @return string
     */
    public function getDonorFacingDescription()
    {
        $description = give_get_option('give_etisalat_description', '');

        if ('' === $description) {
            $description = __(
                'Secure payment with credit/debit card via the Etisalat Payment Gateway. You will be redirected to the secure payment page to complete your donation.',
                'etisalat-gateway-givewp'
            );
        }

        return apply_filters('give_etisalat_description', $description);
    }

    /**
     * Support option-based donation forms (v2): simple help text + description.
     *
     * @inheritDoc
     */
    public function getLegacyFormFieldMarkup(int $formId, array $args): string
    {
        ob_start();
        ?>
        <fieldset class="give-etisalat-gateway-fields">
            <div class="give-etisalat-description" style="margin: 0 0 12px 0;">
                <?php echo wp_kses_post($this->getDonorFacingDescription()); ?>
            </div>
            <div class="give-etisalat-badges" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <span style="font-size:12px;opacity:.75;"><?php esc_html_e('Pay securely with', 'etisalat-gateway-givewp'); ?></span>
                <span style="font-weight:600;font-size:13px;">VISA</span>
                <span style="font-weight:600;font-size:13px;">Mastercard</span>
            </div>
        </fieldset>
        <?php
        return trim(ob_get_clean());
    }

    /**
     * Step into the EPG flow: register the transaction and redirect the donor
     * to the EPG hosted payment page.
     *
     * @inheritDoc
     */
    public function createPayment(Donation $donation, $gatewayData): RedirectOffsite
    {
        try {
            $api = EtisalatApi::fromSettings();

            if (!$api->isConfigured()) {
                throw new EtisalatApiException(
                    __('The Etisalat Payment Gateway is not configured. Please contact the site administrator.', 'etisalat-gateway-givewp')
                );
            }

            $successUrl = !empty($gatewayData['successUrl'])
                ? $gatewayData['successUrl']
                : give_get_success_page_uri();

            $failedUrl = !empty($gatewayData['failedUrl'])
                ? $gatewayData['failedUrl']
                : (!empty($gatewayData['cancelUrl']) ? $gatewayData['cancelUrl'] : give_get_failed_transaction_uri());

            /*
             * The ReturnPath is where EPG sends the donor after the 3D secure
             * authentication. It is a signed gateway route, so only GiveWP and
             * the donation it was created for can use it.
             */
            $returnPath = $this->generateSecureGatewayRouteUrl(
                'handleEtisalatReturn',
                $donation->id,
                [
                    'donation-id'          => $donation->id,
                    'givewp-success-url'   => $successUrl,
                    'givewp-failed-url'    => $failedUrl,
                ]
            );

            $orderName = sprintf(
                /* translators: %s: donation id */
                __('Donation #%s', 'etisalat-gateway-givewp'),
                $donation->id
            );

            $orderInfo = sprintf('%s — %s %s', $donation->formTitle, $donation->firstName, $donation->lastName);

            $registrationParams = [
                'Currency'         => $donation->amount->getCurrency()->getCode(),
                'Amount'           => $donation->amount->formatToDecimal(),
                'OrderID'          => (string) $donation->id,
                'OrderName'        => $this->truncate($orderName, 64),
                'OrderInfo'        => $this->truncate($orderInfo, 128),
                'Channel'          => 'Web',
                'TransactionHint'  => $api->getTransactionHint(),
                'ReturnPath'       => $returnPath,
                'Store'            => give_get_option('give_etisalat_store', ''),
                'Terminal'         => give_get_option('give_etisalat_terminal', ''),
            ];

            /**
             * Filter the Registration request parameters.
             *
             * @param array $registrationParams
             * @param Donation $donation
             */
            $registrationParams = apply_filters(
                'give_etisalat_registration_params',
                $registrationParams,
                $donation
            );

            $this->log(
                'EPG Registration request',
                [
                    'Donation' => $donation,
                    'request'  => $this->redact($registrationParams),
                ]
            );

            $transaction = $api->registerTransaction($registrationParams);

            $this->log(
                'EPG Registration response',
                [
                    'Donation' => $donation,
                    'response' => $transaction,
                    'transport' => $api->lastLog,
                ]
            );

            $transactionId = isset($transaction['TransactionID']) ? $transaction['TransactionID'] : '';
            $paymentPage = '';

            if (isset($transaction['PaymentPage']) && '' !== $transaction['PaymentPage']) {
                $paymentPage = $transaction['PaymentPage'];
            } elseif (isset($transaction['PaymentPortal']) && '' !== $transaction['PaymentPortal']) {
                $paymentPage = $transaction['PaymentPortal'];
            }

            if ('' === $transactionId || '' === $paymentPage) {
                throw new EtisalatApiException(
                    __('The Etisalat gateway did not return a payment page. Please try again.', 'etisalat-gateway-givewp'),
                    $transaction
                );
            }

            /*
             * Keep the donation pending while the donor is on the EPG page and
             * remember the TransactionID + Payment Page URL for the return trip.
             */
            $donation->gatewayTransactionId = (string) $transactionId;
            $donation->status = DonationStatus::PENDING();
            $donation->save();

            give_update_meta($donation->id, '_give_etisalat_payment_page', esc_url_raw($paymentPage), '', 'donation');

            DonationNote::create([
                'donationId' => $donation->id,
                'content'    => sprintf(
                    /* translators: %s: EPG transaction id */
                    __('Etisalat transaction registered. TransactionID: %s. Donor redirected to the EPG payment page.', 'etisalat-gateway-givewp'),
                    $transactionId
                ),
            ]);

            /*
             * EPG requires the payer to be POSTed to the Payment Page with the
             * TransactionID as a hidden field, so redirect the donor to a
             * signed route which renders and submits that form.
             */
            return new RedirectOffsite(
                $this->generateSecureGatewayRouteUrl(
                    'handleEtisalatPaymentPage',
                    $donation->id,
                    [
                        'donation-id' => $donation->id,
                    ]
                )
            );
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();

            $donation->status = DonationStatus::FAILED();
            $donation->save();

            DonationNote::create([
                'donationId' => $donation->id,
                'content'    => sprintf(
                    /* translators: %s: error message */
                    __('Donation failed. Etisalat gateway reason: %s', 'etisalat-gateway-givewp'),
                    $errorMessage
                ),
            ]);

            PaymentGatewayLog::error(
                sprintf('[%s] Payment registration failed', $this->getName()),
                [
                    'Donation' => $donation,
                    'error'    => $errorMessage,
                ]
            );

            throw new PaymentGatewayException($errorMessage);
        }
    }

    /**
     * Intermediate route: renders the page that submits the TransactionID to
     * the EPG Payment Page, as required by the EPG integration guide:
     *
     *   <form action="{PaymentPage}" method="post">
     *       <input type="hidden" name="TransactionID" value="{TransactionID}">
     *   </form>
     *
     * The route URL is signed, so it only works for the donation it was
     * created for and expires after one day.
     *
     * @param array $queryParams
     */
    protected function handleEtisalatPaymentPage(array $queryParams)
    {
        $donationId = isset($queryParams['donation-id']) ? (int) $queryParams['donation-id'] : 0;

        /** @var Donation|null $donation */
        $donation = $donationId ? Donation::find($donationId) : null;

        if (!$donation || $donation->gatewayId !== self::id()) {
            wp_die(
                esc_html__('This payment link is no longer valid. Please donate again.', 'etisalat-gateway-givewp'),
                esc_html__('Etisalat Payment Gateway', 'etisalat-gateway-givewp'),
                ['response' => 410]
            );
        }

        $transactionId = (string) $donation->gatewayTransactionId;
        $paymentPage = (string) give_get_meta($donation->id, '_give_etisalat_payment_page', true, '', 'donation');

        /*
         * If this donation was already paid (the donor revisits the link),
         * send them to the donation success page instead of the payment page.
         */
        if ($donation->status->isComplete()) {
            wp_safe_redirect(add_query_arg(['donation-id' => $donation->id], give_get_success_page_uri()));
            exit;
        }

        if ('' === $transactionId || '' === $paymentPage) {
            wp_die(
                esc_html__('This payment link is no longer valid. Please donate again.', 'etisalat-gateway-givewp'),
                esc_html__('Etisalat Payment Gateway', 'etisalat-gateway-givewp'),
                ['response' => 410]
            );
        }

        /**
         * Filter the page title shown while redirecting to the EPG payment page.
         *
         * @param string $title
         */
        $title = apply_filters(
            'give_etisalat_redirect_page_title',
            __('You are being redirected to the Etisalat Payment Gateway…', 'etisalat-gateway-givewp')
        );

        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);

        $redirectForm = sprintf(
            '<form method="post" id="give-etisalat-redirect" name="give-etisalat-redirect" action="%s">%s</form>',
            esc_url($paymentPage),
            sprintf(
                '<input type="hidden" name="TransactionID" value="%s" />',
                esc_attr($transactionId)
            )
        );

        printf(
            '<!DOCTYPE html><html %s><head><meta charset="utf-8" /><meta name="viewport" content="width=device-width, initial-scale=1" /><title>%s</title><style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;background:#f7f8fa;color:#1c2333;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}.etisalat-redirect{background:#fff;border:1px solid #e3e6ee;border-radius:10px;box-shadow:0 6px 24px rgba(16,24,40,.07);padding:36px 40px;text-align:center;max-width:420px}.etisalat-redirect h1{font-size:18px;margin:0 0 10px}.etisalat-redirect p{font-size:14px;color:#5a6478;margin:0 0 18px}.etisalat-redirect button{font-size:15px;padding:10px 22px;border:0;border-radius:6px;background:#69bf6d;color:#fff;cursor:pointer}.spinner{width:34px;height:34px;margin:0 auto 16px;border-radius:50%;border:3px solid #dfe4ef;border-top-color:#69bf6d;animation:spin .9s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}</style></head><body><div class="etisalat-redirect"><div class="spinner"></div><h1>%s</h1><p>%s</p>%s<noscript><p><button type="submit" form="give-etisalat-redirect">%s</button></p></noscript></div><script>(function(){var f=document.getElementById(\'give-etisalat-redirect\');if(f){f.submit();}})();</script></body></html>',
            esc_attr(get_language_attributes() ?: 'lang="en"'),
            esc_html($title),
            esc_html($title),
            esc_html__('Please do not press the back button or close this window.', 'etisalat-gateway-givewp'),
            $redirectForm,
            esc_html__('Continue to payment', 'etisalat-gateway-givewp')
        );

        exit;
    }

    /**
     * ReturnPath route: EPG sends the donor back here (POSTing the
     * TransactionID) after the 3D secure authentication. Finalize the
     * transaction and redirect to the donation result page.
     *
     * @param array $queryParams
     *
     * @return RedirectResponse
     */
    protected function handleEtisalatReturn(array $queryParams): RedirectResponse
    {
        $donationId = isset($queryParams['donation-id']) ? (int) $queryParams['donation-id'] : 0;

        /** @var Donation|null $donation */
        $donation = $donationId ? Donation::find($donationId) : null;

        $successUrl = !empty($queryParams['givewp-success-url'])
            ? esc_url_raw($queryParams['givewp-success-url'])
            : give_get_success_page_uri();

        $failedUrl = !empty($queryParams['givewp-failed-url'])
            ? esc_url_raw($queryParams['givewp-failed-url'])
            : give_get_failed_transaction_uri();

        if (!$donation || $donation->gatewayId !== self::id()) {
            wp_die(
                esc_html__('This payment link is no longer valid.', 'etisalat-gateway-givewp'),
                esc_html__('Etisalat Payment Gateway', 'etisalat-gateway-givewp'),
                ['response' => 410]
            );
        }

        /*
         * If the donor already completed the payment (e.g. the browser
         * re-posted the return), send them straight to the success page.
         */
        if ($donation->status->isComplete()) {
            return new RedirectResponse($successUrl);
        }

        $transactionId = isset($_POST['TransactionID']) ? give_clean($_POST['TransactionID']) : '';

        if ('' === $transactionId && isset($_GET['TransactionID'])) {
            $transactionId = give_clean($_GET['TransactionID']);
        }

        if ('' === $transactionId) {
            $this->failDonation(
                $donation,
                __('The Etisalat gateway did not return a transaction reference.', 'etisalat-gateway-givewp')
            );

            return new RedirectResponse($failedUrl);
        }

        /*
         * The TransactionID returned by EPG must match the one stored when the
         * transaction was registered.
         */
        if ((string) $donation->gatewayTransactionId !== '' && (string) $transactionId !== (string) $donation->gatewayTransactionId) {
            $this->failDonation(
                $donation,
                sprintf(
                    /* translators: %s: transaction id */
                    __('Returned TransactionID (%s) does not match this donation. Possible tampering attempt.', 'etisalat-gateway-givewp'),
                    $transactionId
                )
            );

            return new RedirectResponse($failedUrl);
        }

        try {
            $api = EtisalatApi::fromSettings();

            $this->log(
                'EPG Finalization request',
                [
                    'Donation' => $donation,
                    'TransactionID' => $transactionId,
                ]
            );

            $transaction = $api->finalizeTransaction($transactionId);

            $this->log(
                'EPG Finalization response',
                [
                    'Donation' => $donation,
                    'response' => $transaction,
                    'transport' => $api->lastLog,
                ]
            );

            $responseCode = isset($transaction['ResponseCode']) ? (int) $transaction['ResponseCode'] : null;
            $approvalCode = isset($transaction['ApprovalCode']) ? $transaction['ApprovalCode'] : '';

            if (0 === $responseCode) {
                // Success.
                $donation->status = DonationStatus::COMPLETE();
                $donation->gatewayTransactionId = (string) $transactionId;
                $donation->save();

                DonationNote::create([
                    'donationId' => $donation->id,
                    'content'    => $this->buildSuccessNote($transaction, $transactionId, $approvalCode),
                ]);

                /**
                 * Fires after a donation has been completed via the Etisalat gateway.
                 *
                 * @param Donation $donation
                 * @param array $transaction Finalization response Transaction object.
                 */
                do_action('give_etisalat_donation_completed', $donation, $transaction);

                return new RedirectResponse($successUrl);
            }

            if (210 === $responseCode) {
                // Offline / Central Bank payment: pending until the bank settles it.
                $donation->status = DonationStatus::PENDING();
                $donation->gatewayTransactionId = (string) $transactionId;
                $donation->save();

                DonationNote::create([
                    'donationId' => $donation->id,
                    'content'    => sprintf(
                        /* translators: %s: transaction id */
                        __('Etisalat transaction %s is pending (response code 210). The payment will be settled by the bank and should be confirmed there before completion.', 'etisalat-gateway-givewp'),
                        $transactionId
                    ),
                ]);

                return new RedirectResponse($successUrl);
            }

            $description = isset($transaction['ResponseDescription'])
                ? $transaction['ResponseDescription']
                : __('Unknown error', 'etisalat-gateway-givewp');

            $this->failDonation(
                $donation,
                sprintf(
                    /* translators: 1: response code, 2: description */
                    __('Finalization failed with response code %1$s: %2$s', 'etisalat-gateway-givewp'),
                    $responseCode,
                    $description
                ),
                $transaction
            );

            return new RedirectResponse($failedUrl);
        } catch (Exception $e) {
            $this->failDonation($donation, $e->getMessage());

            return new RedirectResponse($failedUrl);
        }
    }

    /**
     * Refund a donation through the EPG Refund API.
     *
     * Note: refunds must be enabled on your EPG account by the bank.
     *
     * @inheritDoc
     */
    public function refundDonation(Donation $donation): PaymentRefunded
    {
        try {
            $api = EtisalatApi::fromSettings();

            $transaction = $api->refundTransaction(
                $donation->gatewayTransactionId,
                $donation->amount->formatToDecimal(),
                $donation->amount->getCurrency()->getCode()
            );

            $donation->status = DonationStatus::REFUNDED();
            $donation->save();

            DonationNote::create([
                'donationId' => $donation->id,
                'content'    => sprintf(
                    /* translators: %s: EPG transaction id */
                    __('Donation refunded in the Etisalat Payment Gateway (TransactionID: %s).', 'etisalat-gateway-givewp'),
                    $donation->gatewayTransactionId
                ),
            ]);

            $this->log(
                'EPG Refund response',
                [
                    'Donation' => $donation,
                    'response' => $transaction,
                ]
            );

            return new PaymentRefunded();
        } catch (Exception $e) {
            DonationNote::create([
                'donationId' => $donation->id,
                'content'    => sprintf(
                    /* translators: %s: error message */
                    __('Error! The donation could NOT be refunded through the Etisalat Payment Gateway. Reason: %s. Refund the transaction in the bank portal instead.', 'etisalat-gateway-givewp'),
                    $e->getMessage()
                ),
            ]);

            PaymentGatewayLog::error(
                sprintf('[%s] Refund failed', $this->getName()),
                [
                    'Donation' => $donation,
                    'error'    => $e->getMessage(),
                ]
            );

            throw new PaymentGatewayException($e->getMessage());
        }
    }

    /**
     * Mark a donation as failed and record the reason.
     *
     * @param Donation $donation
     * @param string $reason
     * @param array|null $transaction
     */
    private function failDonation(Donation $donation, $reason, $transaction = null)
    {
        $donation->status = DonationStatus::FAILED();
        $donation->save();

        DonationNote::create([
            'donationId' => $donation->id,
            'content'    => sprintf(
                /* translators: %s: reason */
                __('Donation failed. Etisalat gateway reason: %s', 'etisalat-gateway-givewp'),
                $reason
            ),
        ]);

        PaymentGatewayLog::error(
            sprintf('[%s] Payment failed', $this->getName()),
            [
                'Donation'   => $donation,
                'error'      => $reason,
                'transaction' => $transaction,
            ]
        );
    }

    /**
     * Build the donation note recorded for a successful payment.
     *
     * @param array $transaction Finalization response.
     * @param string $transactionId
     * @param string $approvalCode
     *
     * @return string
     */
    private function buildSuccessNote(array $transaction, $transactionId, $approvalCode)
    {
        $lines = [
            sprintf(
                /* translators: %s: transaction id */
                __('Payment completed via the Etisalat Payment Gateway. TransactionID: %s.', 'etisalat-gateway-givewp'),
                $transactionId
            ),
        ];

        if ('' !== $approvalCode) {
            $lines[] = sprintf(
                /* translators: %s: approval code */
                __('ApprovalCode: %s', 'etisalat-gateway-givewp'),
                $approvalCode
            );
        }

        if (!empty($transaction['CardNumber'])) {
            $lines[] = sprintf(
                /* translators: %s: masked card number */
                __('Card: %s', 'etisalat-gateway-givewp'),
                $transaction['CardNumber']
            );
        }

        if (!empty($transaction['CardBrand'])) {
            $lines[] = sprintf(
                /* translators: %s: card brand */
                __('Card brand: %s', 'etisalat-gateway-givewp'),
                $transaction['CardBrand']
            );
        }

        if (!empty($transaction['Amount']['Value'])) {
            $lines[] = sprintf(
                /* translators: %s: amount charged */
                __('Amount charged: %s', 'etisalat-gateway-givewp'),
                $transaction['Amount']['Value']
            );
        }

        if (!empty($transaction['UniqueID'])) {
            $lines[] = sprintf(
                /* translators: %s: unique reference id */
                __('UniqueID: %s', 'etisalat-gateway-givewp'),
                $transaction['UniqueID']
            );
        }

        return implode("\n", $lines);
    }

    /**
     * Truncate a string to a maximum length, multi-byte safe when possible.
     *
     * @param string $value
     * @param int $length
     *
     * @return string
     */
    private function truncate($value, $length)
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length);
        }

        return substr($value, 0, $length);
    }

    /**
     * Log a message through the GiveWP payment gateway log when debug logging
     * is enabled.
     *
     * @param string $message
     * @param array $context
     */
    private function log($message, array $context = [])
    {
        if (!class_exists('Give\Framework\PaymentGateways\Log\PaymentGatewayLog')) {
            return;
        }

        if (give_get_option('give_etisalat_debug', 'off') !== 'on') {
            return;
        }

        // Never log credentials.
        if (isset($context['response']) || isset($context['request']) || isset($context['transport'])) {
            $context = array_map([$this, 'redact'], $context);
        }

        PaymentGatewayLog::success($message, $context);
    }

    /**
     * Remove sensitive values before logging.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    private function redact($value)
    {
        if (is_array($value)) {
            unset($value['Password'], $value['password']);

            return array_map([$this, 'redact'], $value);
        }

        return $value;
    }
}
