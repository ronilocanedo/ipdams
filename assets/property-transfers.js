'use strict';
window.addEventListener('DOMContentLoaded', () => {
  const printButton = document.getElementById('pt-print');
  if (printButton) printButton.addEventListener('click', () => window.print());
  const form = document.getElementById('pt-transfer-form');
  if (!form) return;
  const kind = document.getElementById('pt-kind');
  const division = document.getElementById('pt-division');
  const targetMode = document.getElementById('pt-target-mode');
  const externalDivision = document.getElementById('pt-external-division');
  const externalOffice = document.getElementById('pt-external-office');
  const destination = document.getElementById('pt-destination');
  const internal = document.getElementById('pt-self');
  const employee = document.getElementById('pt-employee');
  const submit = document.getElementById('pt-submit');
  const search = document.getElementById('pt-property-search');
  const checks = [...form.querySelectorAll('.pt-property-check')];
  const rows = [...form.querySelectorAll('.pt-filter-row')];
  const count = document.getElementById('pt-selected-count');
  const footer = document.getElementById('pt-selected-footer');
  const message = document.getElementById('pt-selection-message');
  const normalize = str => String(str || '').toLocaleLowerCase().trim();
  function refreshDestinations() {
    const external = kind.value === 'EXTERNAL';
    const outside = external && targetMode.value === 'OUTSIDE';
    const registered = external && !outside;
    form.querySelectorAll('.pt-external:not(.pt-registered):not(.pt-outside)').forEach(el => el.hidden = !external);
    form.querySelectorAll('.pt-registered').forEach(el => el.hidden = !registered);
    form.querySelectorAll('.pt-outside').forEach(el => el.hidden = !outside);
    form.querySelectorAll('.pt-internal').forEach(el => el.hidden = external);
    targetMode.disabled = !external;
    division.disabled = !registered;
    destination.disabled = !registered;
    destination.required = registered;
    externalDivision.disabled = !outside;
    externalOffice.disabled = !outside;
    externalDivision.required = outside;
    externalOffice.required = outside;
    internal.disabled = external;
    employee.disabled = external;
    employee.required = !external;
    if (registered) {
      const selectedDivision = division.value;
      [...destination.options].forEach(opt => {
        if (!opt.value) return;
        opt.hidden = opt.dataset.division !== selectedDivision;
        // Source school cannot be its own external destination.
        opt.disabled = opt.value === form.dataset.sourceId || opt.dataset.division !== selectedDivision;
      });
      const selected = destination.selectedOptions[0];
      if (!selected || selected.disabled || !selectedDivision) destination.value = '';
    }
    submit.textContent = external ? 'Prepare external transfer (awaiting receipt)' : 'Record internal transfer';
  }
  function selected() { return checks.filter(el => el.checked); }
  function refreshSelection() {
    const chosen = selected();
    const label = `${chosen.length} ${chosen.length === 1 ? 'property' : 'properties'} selected`;
    count.textContent = label;
    footer.textContent = label;
    const distinctTypes = new Set(chosen.map(el => el.dataset.type));
    const invalid = chosen.length > 20 || distinctTypes.size > 1;
    message.textContent = chosen.length > 20 ? 'Maximum 20 properties per transfer.' : distinctTypes.size > 1 ? 'Separate PPE and semi-expendable properties.' : '';
    submit.disabled = chosen.length === 0 || invalid;
    return !invalid && chosen.length > 0;
  }
  kind.addEventListener('change', refreshDestinations);
  division.addEventListener('change', refreshDestinations);
  targetMode.addEventListener('change', refreshDestinations);
  search.addEventListener('input', () => {
    const term = normalize(search.value);
    rows.forEach(row => { row.hidden = term !== '' && !normalize(row.dataset.haystack).includes(term); });
  });
  checks.forEach(box => box.addEventListener('change', refreshSelection));
  document.getElementById('pt-select-visible').addEventListener('click', () => {
    const current = selected();
    const pickedType = current.length ? current[0].dataset.type : null;
    const available = rows.filter(row => !row.hidden).map(row => row.querySelector('.pt-property-check')).filter(Boolean);
    const type = pickedType || available[0]?.dataset.type;
    const candidate = available.filter(box => box.dataset.type === type);
    for (const box of candidate) { if (selected().length >= 20) break; box.checked = true; }
    refreshSelection();
  });
  document.getElementById('pt-clear').addEventListener('click', () => {
    checks.forEach(box => { box.checked = false; }); refreshSelection();
  });
  form.addEventListener('submit', event => {
    if (!refreshSelection()) { event.preventDefault(); message.textContent ||= 'Select properties of one classification.'; return; }
    if (kind.value === 'EXTERNAL') {
      if (targetMode.value === 'REGISTERED' && (!division.value || !destination.value)) {
        event.preventDefault(); division.focus();
        message.textContent = 'Select a receiving Division and school/Division Office.';
      } else if (targetMode.value === 'OUTSIDE' &&
          (externalDivision.value.trim().length < 5 || externalOffice.value.trim().length < 5)) {
        event.preventDefault(); externalDivision.focus();
        message.textContent = 'Enter both official receiving Division and school/office names.';
      }
    }
  });
  refreshDestinations();
  refreshSelection();
});
