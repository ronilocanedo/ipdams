/* IPDAMS v0.2.291: static browser-only physical print trigger; no mutation. */
(function(){'use strict';
  function ready(){var button=document.getElementById('print-button');
    if(button)button.addEventListener('click',function(){window.print();});}
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',ready);
  else ready();
})();
