/**
 * Etisalat Payment Gateway - front-end gateway for GiveWP donation forms
 * created with the Visual Form Builder (v3).
 *
 * This is an off-site gateway: no card fields are collected on the donation
 * form. The donor is redirected to the secure Etisalat Payment Gateway (EPG)
 * page after submitting the donation.
 *
 * Registering a gateway object with window.givewp.gateways.register() is the
 * documented way to support GiveWP's visual form builder. The object must use
 * the same id as the PHP gateway class (EtisalatGateway::id() => 'etisalat').
 */
(() => {
    let settings = {};

    const el = window.wp.element.createElement;

    /**
     * Simple brand marks for the badges shown under the description.
     */
    function VisaMark() {
        return el(
            'span',
            {
                className: 'give-etisalat-badge give-etisalat-badge--visa',
                style: {fontWeight: 700, fontStyle: 'italic', fontSize: '14px', color: '#1A1F71'},
            },
            'VISA'
        );
    }

    function MastercardMark() {
        return el(
            'span',
            {
                className: 'give-etisalat-badge give-etisalat-badge--mastercard',
                style: {display: 'inline-flex', alignItems: 'center'},
                'aria-label': 'Mastercard',
            },
            el('span', {
                style: {
                    display: 'inline-block',
                    width: '18px',
                    height: '18px',
                    borderRadius: '50%',
                    background: '#EB001B',
                    marginRight: '-7px',
                },
            }),
            el('span', {
                style: {
                    display: 'inline-block',
                    width: '18px',
                    height: '18px',
                    borderRadius: '50%',
                    background: '#F79E1B',
                    opacity: 0.85,
                },
            })
        );
    }

    /**
     * Fields rendered inside the donation form when this gateway is selected.
     */
    function EtisalatGatewayFields() {
        return el(
            'div',
            {className: 'give-etisalat-gateway-fields'},
            settings.message
                ? el('div', {
                      className: 'give-etisalat-description',
                      style: {marginBottom: '12px', lineHeight: 1.5},
                      dangerouslySetInnerHTML: {__html: settings.message},
                  })
                : null,
            el(
                'div',
                {
                    className: 'give-etisalat-badges',
                    style: {
                        display: 'flex',
                        alignItems: 'center',
                        gap: '10px',
                        flexWrap: 'wrap',
                    },
                },
                el(
                    'span',
                    {style: {fontSize: '12px', opacity: 0.7}},
                    'Pay securely with'
                ),
                el(VisaMark),
                el(MastercardMark)
            )
        );
    }

    /**
     * The front-end gateway object.
     */
    const EtisalatGateway = {
        id: 'etisalat',
        initialize() {
            settings = this.settings || {};
        },
        Fields() {
            return el(EtisalatGatewayFields);
        },
    };

    if (window.givewp && window.givewp.gateways && window.givewp.gateways.register) {
        window.givewp.gateways.register(EtisalatGateway);
    }
})();
