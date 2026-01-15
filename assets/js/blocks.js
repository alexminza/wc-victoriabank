const victoriabank_settings = window.wc.wcSettings.getSetting('victoriabank_data', {});
const victoriabank_title = window.wp.htmlEntities.decodeEntities(victoriabank_settings.title);

const victoriabank_content = () => {
    return window.wp.htmlEntities.decodeEntities(victoriabank_settings.description || '');
};

const victoriabank_label = () => {
    let icon = victoriabank_settings.icon
        ? window.wp.element.createElement(
            'img',
            {
                alt: victoriabank_title,
                title: victoriabank_title,
                src: victoriabank_settings.icon,
                style: { float: 'right', paddingRight: '1em' }
            }
        )
        : null;

    let label = window.wp.element.createElement(
        'span',
        icon ? { style: { width: '100%' } } : null,
        victoriabank_title,
        icon
    );

    return label;
};

const victoriabank_blockGateway = {
    name: victoriabank_settings.id,
    label: window.wp.element.createElement(victoriabank_label, null),
    icons: ['visa', 'mastercard'],
    content: window.wp.element.createElement(victoriabank_content, null),
    edit: window.wp.element.createElement(victoriabank_content, null),
    canMakePayment: () => true,
    ariaLabel: victoriabank_title,
    supports: {
        features: victoriabank_settings.supports,
    },
};

window.wc.wcBlocksRegistry.registerPaymentMethod(victoriabank_blockGateway);
