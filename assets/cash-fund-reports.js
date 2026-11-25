/* v0.2.449: same-origin, CSP-compatible controls for read-only financial reports. */
(function () {
  'use strict';
  function attach() {
    var printButton = document.querySelector('[data-report-print]');
    if (printButton && !printButton.dataset.reportWired) {
      printButton.dataset.reportWired = '1';
      printButton.addEventListener('click', function () { window.print(); });
    }
    var office = document.querySelector('[data-report-organization-change]');
    if (office && !office.dataset.reportWired) {
      office.dataset.reportWired = '1';
      office.addEventListener('change', function () {
        if (office.form) office.form.requestSubmit();
      });
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', attach);
  else attach();
}());
