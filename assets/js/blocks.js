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
    var mark = data.icon
      ? el('img', {
          src: data.icon,
          alt: '',
          style: { height: '24px', width: 'auto', marginRight: '8px', verticalAlign: 'middle' },
        })
      : null;
    return el(
      'span',
      { style: { display: 'inline-flex', alignItems: 'center' } },
      mark,
      el(props.components.PaymentMethodLabel, { text: title }),
    );
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
