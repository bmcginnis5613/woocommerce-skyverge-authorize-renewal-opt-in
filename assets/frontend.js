(function () {
    'use strict';
    const { createElement: el, useEffect, useState } = wp.element;
    const { useSelect } = wp.data;
    const { cartStore, paymentStore } = wc.wcBlocksData;
    const metadata = {
        apiVersion: 2, name: 'cer-authorize-net/renewal-opt-in',
        title: 'Automatic renewal opt-in', category: 'woocommerce',
        parent: ['woocommerce/checkout-fields-block'],
        supports: { html: false, multiple: false, reusable: false }
    };
    function RenewalOptIn({ checkoutExtensionData }) {
        const [checked, setChecked] = useState(false);
        const state = useSelect((select) => {
            const cart = select(cartStore).getCartData();
            const extension = cart.extensions && cart.extensions['cer-authorize-net'];
            return {
                eligible: Boolean(extension && extension.eligible),
                allowedGateways: extension && extension.allowed_gateways || [],
                gateway: select(paymentStore).getActivePaymentMethod()
            };
        }, []);
        const visible = state.eligible && state.allowedGateways.includes(state.gateway);
        const { setExtensionData } = checkoutExtensionData;
        useEffect(() => {
            setExtensionData('cer-authorize-net', 'opt_in', visible && checked);
        }, [visible, checked, setExtensionData]);
        useEffect(() => { setChecked(false); }, [state.gateway, state.eligible]);
        if (!visible) { return null; }
        return el('div', { className: 'cer-authnet-opt-in wc-block-components-checkbox' },
            el('label', { htmlFor: 'cer-authnet-opt-in' },
                el('input', {
                    id: 'cer-authnet-opt-in', className: 'wc-block-components-checkbox__input', type: 'checkbox', checked,
                    onChange: (event) => {
                        const value = event.target.checked;
                        setChecked(value);
                        setExtensionData('cer-authorize-net', 'opt_in', value);
                    },
                    'aria-describedby': 'cer-authnet-details'
                }),
                el('svg', { className: 'wc-block-components-checkbox__mark', viewBox: '0 0 24 24', width: 24, height: 24, 'aria-hidden': true, focusable: false },
                    el('path', { d: 'M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z' })
                ),
                el('span', { className: 'wc-block-components-checkbox__label' }, cerAuthnet.label)
            ),
            el('p', { id: 'cer-authnet-details', className: 'cer-authnet-opt-in__description' }, cerAuthnet.details)
        );
    }
    wc.blocksCheckout.registerCheckoutBlock({ metadata, component: RenewalOptIn });
}());
