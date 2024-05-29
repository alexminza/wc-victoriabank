const vb_settings = window.wc.wcSettings.getSetting('victoriabank_data', {});
const vb_title = window.wp.htmlEntities.decodeEntities(vb_settings.title);

const Content = () => {
    return window.wp.htmlEntities.decodeEntities(vb_settings.description || '');
};

const Label = () => {
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

const vb_Block_Gateway = {
    name: vb_settings.id,
    label: Object(window.wp.element.createElement)(Label, null),
    icons: ['visa', 'mastercard'],
    content: Object(window.wp.element.createElement)(Content, null),
    edit: Object(window.wp.element.createElement)(Content, null),
    canMakePayment: () => true,
    ariaLabel: vb_title,
    supports: {
        features: vb_settings.supports,
    },
};

window.wc.wcBlocksRegistry.registerPaymentMethod(vb_Block_Gateway);
