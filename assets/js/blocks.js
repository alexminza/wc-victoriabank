const vb_settings = window.wc.wcSettings.getSetting('victoriabank_data', {});
const vb_title = window.wp.htmlEntities.decodeEntities(vb_settings.title);

const vb_content = () => {
    return window.wp.htmlEntities.decodeEntities(vb_settings.description || '');
};

const vb_label = () => {
    let icon = vb_settings.icon
        ? window.wp.element.createElement(
            'img',
            {
                alt: vb_title,
                title: vb_title,
                src: vb_settings.icon,
                style: { float: 'right', paddingRight: '1em' }
            }
        )
        : null;

    let label = window.wp.element.createElement(
        'span',
        icon ? { style: { width: '100%' } } : null,
        vb_title,
        icon
    );

    return label;
};

const vb_blockGateway = {
    name: vb_settings.id,
    label: Object(window.wp.element.createElement)(vb_label, null),
    icons: ['visa', 'mastercard'],
    content: Object(window.wp.element.createElement)(vb_content, null),
    edit: Object(window.wp.element.createElement)(vb_content, null),
    canMakePayment: () => true,
    ariaLabel: vb_title,
    supports: {
        features: vb_settings.supports,
    },
};

window.wc.wcBlocksRegistry.registerPaymentMethod(vb_blockGateway);
