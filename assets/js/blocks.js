/**
 * Autlantic Billing – WooCommerce Blocks payment method.
 */
(function () {
  if (typeof wc === 'undefined' || !wc.wcBlocksRegistry || !wc.wcSettings || !wp || !wp.element) {
    return;
  }

  var data = wc.wcSettings.getSetting('autlantic_data', {});
  var title = data.title || 'USDC (Autlantic)';
  var description = data.description || '';
  var el = wp.element.createElement;

  var Label = function (props) {
    return el(props.components.PaymentMethodLabel, { text: title });
  };

  var Content = function () {
    return el('div', { className: 'autlantic-blocks-description' }, description);
  };

  wc.wcBlocksRegistry.registerPaymentMethod({
    name: 'autlantic',
    label: el(Label, null),
    content: el(Content, null),
    edit: el(Content, null),
    canMakePayment: function () {
      return true;
    },
    ariaLabel: title,
    supports: {
      features: data.supports || ['products'],
    },
  });
})();
