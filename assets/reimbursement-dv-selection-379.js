/* IPDAMS v0.2.379 QA candidate — advisory only, no financial writes. */
(function () {
  'use strict';
  var modalSelector = '.dv-compose-modal[aria-label="Prepare consolidated employee travel reimbursement DV"]';
  var tableSelector = '.travel-dv-claim-table';
  var checkSelector = 'input.travel-dv-select-checkbox';
  var scheduled = false;
  function update() {
    scheduled = false;
    var modals = document.querySelectorAll(modalSelector);
    for (var i = 0; i < modals.length; i++) {
      var modal = modals[i];
      var table = modal.querySelector(tableSelector);
      if (!table || !table.parentNode) continue;
      var panel = modal.querySelector('[data-ipdams-reim-379]');
      if (!panel) {
        panel = document.createElement('p');
        panel.className = 'reim-379-selection-summary';
        panel.setAttribute('data-ipdams-reim-379', 'true');
        panel.setAttribute('role', 'status');
        panel.setAttribute('aria-live', 'polite');
        table.parentNode.insertBefore(panel, table);
      }
      var selected = table.querySelectorAll(checkSelector + ':checked').length;
      var message = 'Selected claims: ' + selected + '. Use approved claims from the same employee and paying fund. This selection prepares a DRAFT DV only; it does not approve or release a payment.';
      if (panel.textContent !== message) panel.textContent = message;
    }
  }
  function schedule() {
    if (scheduled) return;
    scheduled = true;
    window.requestAnimationFrame(update);
  }
  function start() {
    var root = document.getElementById('root') || document.body;
    if (!root) return;
    new MutationObserver(schedule).observe(root, {childList: true, subtree: true});
    document.addEventListener('change', schedule, true);
    schedule();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once: true});
  else start();
}());
