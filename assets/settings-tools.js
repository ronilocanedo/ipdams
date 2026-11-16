/* IPDAMS v0.1.7: small progressive enhancement, no external libraries. */
(function () {
  'use strict';
  function status(id, message) { var el = document.getElementById(id); if (el) el.textContent = message; }
  function copy(text, outputId) {
    if (!text) { status(outputId, 'Nothing to copy.'); return; }
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(function () { status(outputId, 'Copied to clipboard.'); }, function () { fallback(text, outputId); });
    } else { fallback(text, outputId); }
  }
  function fallback(text, outputId) {
    var area = document.createElement('textarea'); area.value = text; area.setAttribute('readonly', '');
    area.style.position='fixed'; area.style.left='-9999px'; document.body.appendChild(area);
    area.select(); var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    area.remove(); status(outputId, ok ? 'Copied to clipboard.' : 'Copy blocked by browser. Select the table and copy manually.');
  }
  var sql=document.getElementById('ipdams-sql-input');
  var sqlCopy=document.getElementById('ipdams-copy-sql');
  if (sqlCopy && sql) sqlCopy.addEventListener('click', function () { copy(sql.value, 'ipdams-copy-sql-status'); });
  // The backend intentionally rejects semicolons. Remove just one terminal delimiter
  // for form submissions; all interior delimiters and comment validation stay intact.
  if (sql && sql.form) sql.form.addEventListener('submit', function (ev) {
    if (!ev.submitter || (ev.submitter.value !== 'query_read' && ev.submitter.value !== 'query_write')) return;
    var value=sql.value.trim();
    if (value.endsWith(';')) sql.value = value.slice(0,-1).trimEnd();
  });
  var resultCopy=document.getElementById('ipdams-copy-results');
  if (resultCopy) resultCopy.addEventListener('click',function () {
    var table=document.getElementById('ipdams-query-results-table');
    if (!table) { status('ipdams-copy-results-status', 'No tabular results available.'); return; }
    var lines=[];
    table.querySelectorAll('tr').forEach(function (tr) {
      var cells=Array.from(tr.querySelectorAll('th, td'));
      lines.push(cells.map(function (cell) { return (cell.textContent || '').replace(/\t/g,' ').replace(/\r?\n/g,' '); }).join('\t'));
    });
    copy(lines.join('\n'), 'ipdams-copy-results-status');
  });
  // v0.2.205: leave desktop workspace navigation visible, compact it on phones.
  // Without JavaScript the original expanded navigation remains accessible.
  var workspaceDrawer=document.querySelector('.settings-nav-drawer');
  if(workspaceDrawer && window.matchMedia && window.matchMedia('(max-width: 600px)').matches) {
    workspaceDrawer.open=false;
  }
})();
