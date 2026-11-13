'use strict';
(() => {
  const trigger = document.getElementById('print-report');
  if (trigger) trigger.addEventListener('click', () => window.print());
})();
