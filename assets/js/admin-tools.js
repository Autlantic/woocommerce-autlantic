(function () {
  var cfg = window.autlanticAdminTools || {};
  var button = document.getElementById('autlantic-test-connection');
  var output = document.getElementById('autlantic-test-result');
  if (!button || !output) {
    return;
  }

  button.addEventListener('click', function () {
    output.textContent = cfg.checking || 'Checking…';
    var body = new URLSearchParams();
    body.set('action', 'autlantic_test_connection');
    body.set('_ajax_nonce', cfg.nonce || '');

    fetch(cfg.ajaxUrl || window.ajaxurl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
    })
      .then(function (response) {
        return response.json();
      })
      .then(function (data) {
        if (data && data.data && data.data.message) {
          output.textContent = data.data.message;
          return;
        }
        output.textContent = data && data.success ? 'OK' : 'Failed';
      })
      .catch(function () {
        output.textContent = 'Request failed';
      });
  });
})();
