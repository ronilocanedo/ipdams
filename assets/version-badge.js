/* Add a small server-backed version label to the existing React sidebar without editing the main frontend bundle. */
(function () {
  'use strict';
  function show(version) {
    if (!/^\d+\.\d+\.\d+$/.test(version)) return;
    function insert() {
      var sidebar = document.querySelector('.sidebar');
      if (!sidebar || sidebar.querySelector('[data-ipdams-version]')) return;
      var label = document.createElement('div');
      label.dataset.ipdamsVersion='1';
      label.textContent='IPDAMS · v'+version;
      label.style.cssText='padding:5px 15px 14px;color:#bad8e0;font-size:12px;font-weight:650;letter-spacing:.02em;';
      sidebar.appendChild(label);
    }
    insert();
    var root = document.getElementById('root');
    if (root && typeof MutationObserver !== 'undefined') {
      var observer = new MutationObserver(insert);
      observer.observe(root,{childList:true,subtree:true});
    }
  }
  fetch('version.php',{credentials:'same-origin',cache:'no-store'})
    .then(function(response){if(!response.ok)throw Error('Unavailable');return response.json();})
    .then(function(data){show(String(data.version||''));})
    .catch(function(){/* Version label is noncritical to app operation. */});
})();
