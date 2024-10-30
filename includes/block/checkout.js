const settings = window.wc.wcSettings.getSetting( 'hblpay_data', {} );
const label = window.wp.htmlEntities.decodeEntities( settings.title ) || window.wp.i18n.__( 'Hblpay', 'hblpay' );
const Content = (hblpay_data) => {
	return window.wp.htmlEntities.decodeEntities( settings.description || '' );
};
const Block_Gateway = {

	name: 'hblpay',
	label: label,
	content: Object( window.wp.element.createElement )( Content, null ),
	edit: Object( window.wp.element.createElement )( Content, null ),
	canMakePayment: () => true,
	placeOrderButtonLabel: window.wp.i18n.__( 'Continue', 'wc_hblpay' ),
	ariaLabel: label,
	supports: {
		features: settings.supports,
	},
};
window.wc.wcBlocksRegistry.registerPaymentMethod( Block_Gateway );
