(() => {
  'use strict';
  const print = document.getElementById('rr-print');
  if (print) print.addEventListener('click', () => window.print());
  // The account-holder chooser is a search-enabled, server-scoped datalist.
  // A successful exact selection performs a GET only; no custody-changing POST.
  const chooserForm = document.getElementById('rr-employee-form');
  const chooser = document.getElementById('rr-employee-search');
  const status = document.getElementById('rr-employee-live');
  const form = document.getElementById('rr-receive-form');
  if (chooserForm && chooser) {
    const choices = new Set([...document.querySelectorAll('#rr-employee-options option')].map(o => o.value));
    const loadedEmployee = form?.querySelector('input[name="employee"]')?.value || '';
    let pending = null;
    let loading = false;
    const setStatus = text => { if (status) status.textContent = text; };
    const syncChoice = () => {
      const name = chooser.value.trim();
      // Do not allow an earlier employee's loaded rows to be returned while
      // the search field refers to a different, or incomplete, employee.
      if (form) form.hidden = name !== loadedEmployee;
      chooser.setCustomValidity('');
      if (!name) setStatus('Choose an employee from the suggestions to load their assigned properties automatically.');
      else if (!choices.has(name)) setStatus('Continue typing or choose an employee from the suggested names.');
      else if (name === loadedEmployee) setStatus(`Showing open assignments for ${name}.`);
      else setStatus(`Selected ${name}. Loading assigned properties automatically…`);
    };
    const loadChoice = () => {
      if (loading) return;
      const name = chooser.value.trim();
      if (!choices.has(name) || name === loadedEmployee) return;
      chooserForm.requestSubmit();
    };
    chooser.addEventListener('input', () => {
      if (pending !== null) clearTimeout(pending);
      syncChoice();
      // Datalist option selections fire input across supported browsers;
      // a short delay prevents loading midway through typing a longer name.
      pending = setTimeout(() => { pending = null; loadChoice(); }, 350);
    });
    chooser.addEventListener('change', () => {
      if (pending !== null) {clearTimeout(pending); pending = null;}
      syncChoice();
      loadChoice();
    });
    chooserForm.addEventListener('submit', event => {
      if (pending !== null) { clearTimeout(pending); pending = null; }
      const name = chooser.value.trim();
      if (!choices.has(name)) {
        event.preventDefault();
        chooser.setCustomValidity('Select an employee from the available account holders.');
        chooser.reportValidity();
        syncChoice();
        return;
      }
      if (name === loadedEmployee || loading) {
        event.preventDefault();
        syncChoice();
        return;
      }
      chooser.value = name;
      loading = true;
      chooser.readOnly = true;
      chooserForm.setAttribute('aria-busy', 'true');
      setStatus(`Loading all open property assignments for ${name}…`);
    });
    syncChoice();
  }
  if (!form) return;
  const checks = [...form.querySelectorAll('.rr-item-pick input[type="checkbox"]')];
  const counter = document.getElementById('rr-selection-count');
  const note = document.getElementById('rr-selection-note');
  const submit = document.getElementById('rr-save');
  const all = document.getElementById('rr-select-all');
  const none = document.getElementById('rr-select-none');
  function sync() {
    const picked = checks.filter(c => c.checked);
    const counts = { SEMI_EXPENDABLE: 0, PPE: 0 };
    let valid = true;
    for (const c of picked) {
      counts[c.dataset.category]++;
      const select = c.closest('.rr-item').querySelector('[data-return-condition]');
      if (select && !select.value) valid = false;
    }
    counter.textContent = `${picked.length} of ${checks.length} selected`;
    const receipts = Math.ceil(counts.SEMI_EXPENDABLE / 20) + Math.ceil(counts.PPE / 20);
    note.textContent = `${picked.length} selected: ${counts.SEMI_EXPENDABLE} semi-expendable / ${counts.PPE} PPE · ${receipts} separate return receipt(s).${valid ? '' : ' Select a condition for every checked item.'}`;
    submit.disabled = !picked.length || !valid;
    for (const check of checks) check.closest('.rr-item').classList.toggle('rr-selected', check.checked);
  }
  checks.forEach(c => c.addEventListener('change', sync));
  form.querySelectorAll('[data-return-condition]').forEach(s => s.addEventListener('change', sync));
  all?.addEventListener('click', () => { checks.forEach(c => {c.checked = true;}); sync(); });
  none?.addEventListener('click', () => { checks.forEach(c => {c.checked = false;}); sync(); });
  form.addEventListener('submit', e => {
    if (form.hidden) { e.preventDefault(); return; }
    sync();
    if (submit.disabled) {e.preventDefault(); return;}
    const chosen = checks.filter(c => c.checked);
    const typeCount = [...new Set(chosen.map(c => c.dataset.category))].length;
    if (!window.confirm(`Receive ${chosen.length} checked property unit(s) from the selected employee?${typeCount > 1 ? ' Separate RRSP and PPE receipts will be issued.' : ''} These items will leave the employee's active accountability. Confirm conditions and physical receipt before proceeding.`)) e.preventDefault();
    else submit.disabled = true; // Avoid accidental double submission.
  });
  sync();
})();
