(function () {
    'use strict';
    const { createElement: el } = wp.element;
    wp.blocks.registerBlockType('cer-authorize-net/renewal-opt-in', {
        apiVersion: 2, title: 'Automatic renewal opt-in', icon: 'update', category: 'woocommerce',
        parent: ['woocommerce/checkout-fields-block'],
        supports: { html: false, multiple: false, reusable: false },
        edit: function () {
            return el('div', wp.blockEditor.useBlockProps(),
                el('strong', null, 'Automatic renewal opt-in'),
                el('p', null, 'Enable Auto Renewal'),
                el('p', null, cerAuthnet.details),
                el('small', null, 'Shown only for existing manual subscription renewals paid with SkyVerge Authorize.Net credit card or eCheck.')
            );
        },
        save: function () { return el('div', wp.blockEditor.useBlockProps.save()); }
    });
}());
