'use strict';
(() => {
  const $ = id => document.getElementById(id);
  const state = { csrf: '', org: '', rows: { assets: [], accountability: [], received_property: [] }, busy: false };
  const message = (text, error = false) => {
    $('message').textContent = text;
    $('message').className = error ? 'message error' : 'message';
  };
  const option = (value, label) => {
    const o = document.createElement('option');
    o.value = String(value); o.textContent = label; return o;
  };
  const cell = (tr, value) => {
    const td = document.createElement('td');
    td.textContent = String(value ?? '—'); tr.appendChild(td); return td;
  };
  const api = async (route, body) => {
    const post = body !== undefined;
    const url = 'api/index.php?route=' + encodeURIComponent(route) +
      (post ? '' : '&organization_id=' + encodeURIComponent(state.org) +
        (route === 'list' ? '&kind=' + encodeURIComponent(body) : ''));
    const response = await fetch(url, {
      method: post ? 'POST' : 'GET',
      credentials: 'same-origin',
      headers: post ? {
        'Content-Type': 'application/json', 'X-CSRF-Token': state.csrf,
        'X-Idempotency-Key': 'property-' + crypto.randomUUID()
      } : {},
      body: post ? JSON.stringify({ organization_id: Number(state.org), ...body }) : undefined
    });
    const json = await response.json();
    if (!response.ok) throw new Error(json.error || 'Unable to load property records');
    return json.data;
  };
  // GET list uses a dedicated helper: never append kind to a POST.
  const list = async kind => {
    const url = 'api/index.php?route=list&organization_id=' + encodeURIComponent(state.org) +
      '&kind=' + encodeURIComponent(kind);
    const response = await fetch(url, { credentials: 'same-origin' });
    const json = await response.json();
    if (!response.ok) throw new Error(json.error || 'Unable to load ' + kind);
    return json.data;
  };
  const selectedAsset = () => state.rows.assets.find(a => String(a.id) === $('asset').value);
  const selectedLine = () => state.rows.received_property.find(a => String(a.iar_line_id) === $('iar-line').value);
  const renderLine = () => {
    const r = selectedLine();
    if (!r) { $('iar-info').textContent = ''; return; }
    const accepted = Number(r.accepted_qty);
    const remaining = Number.isInteger(accepted) ? accepted - Number(r.registered_units) : 0;
    $('iar-info').textContent = r.item_name + ' · ' + r.category +
      ' · Accepted ' + r.accepted_qty + ' · Registered ' + r.registered_units +
      ' · Remaining ' + remaining + (Number.isInteger(accepted) ? '' : ' · Fractional quantity: review required');
    $('register').disabled = state.busy || remaining <= 0 || !Number.isInteger(accepted);
  };
  const renderAsset = () => {
    const a = selectedAsset();
    const existing = a && (state.rows.accountability.some(r => String(r.asset_id) === String(a.id) && r.status === 'FINALIZED') || a.latest_accountability_number);
    $('asset-info').textContent = a ? ('Required form: ' + a.required_document +
      ' · Status: ' + a.status + ' · Current custodian: ' + (a.custodian || 'Unassigned') +
      (existing ? ' · Existing slip recorded; new issuance blocked' : '')) : '';
    $('issue').disabled = state.busy || !a || a.status !== 'ACTIVE' || Boolean(existing) || !a.required_document;
  };
  const render = () => {
    const lines = $('iar-line');
    const oldLine = lines.value;
    lines.replaceChildren(option('', 'Choose a received IAR line'));
    for (const r of state.rows.received_property) {
      const accepted = Number(r.accepted_qty);
      if (Number.isInteger(accepted) && accepted > Number(r.registered_units))
        lines.appendChild(option(r.iar_line_id, r.iar_number + ' · ' + r.item_name +
          ' · ' + (accepted - Number(r.registered_units)) + ' remaining'));
    }
    lines.value = [...lines.options].some(x => x.value === oldLine) ? oldLine : '';
    renderLine();
    const assets = state.rows.assets;
    const search = $('asset-search').value.trim().toLowerCase();
    const select = $('asset'), oldAsset = select.value;
    select.replaceChildren(option('', 'Choose a registered property item'));
    for (const a of assets.filter(a => [a.property_tag, a.item_name, a.custodian].some(x =>
      String(x || '').toLowerCase().includes(search)))) {
      select.appendChild(option(a.id, a.property_tag + ' · ' + a.item_name));
    }
    select.value = [...select.options].some(x => x.value === oldAsset) ? oldAsset : '';
    renderAsset();
    const slips = $('slips'); slips.replaceChildren();
    for (const r of state.rows.accountability) {
      const tr = document.createElement('tr');
      cell(tr, r.display_number); cell(tr, r.doc_type);
      cell(tr, r.property_tag + ' · ' + r.item_name);
      cell(tr, r.custodian); cell(tr, r.status);
      const td = document.createElement('td');
      if (r.status === 'FINALIZED') {
        const a = document.createElement('a');
        a.href = 'api/print.php?organization_id=' + encodeURIComponent(state.org) +
          '&type=' + encodeURIComponent(r.doc_type) + '&id=' + encodeURIComponent(String(r.id));
        a.target = '_blank'; a.rel = 'noopener noreferrer'; a.textContent = 'Print ' + r.doc_type;
        td.appendChild(a);
      } else td.textContent = 'Unavailable';
      tr.appendChild(td); slips.appendChild(tr);
    }
    const table = $('assets'); table.replaceChildren();
    for (const a of assets) {
      const tr = document.createElement('tr');
      cell(tr, a.property_tag); cell(tr, a.item_name); cell(tr, a.category);
      cell(tr, a.custodian || '—'); cell(tr, a.iar_line_id ? 'IAR line ' + a.iar_line_id : 'Opening record');
      cell(tr, a.status); table.appendChild(tr);
    }
  };
  const refresh = async () => {
    const expected = state.org;
    const [assets, accountability, received_property] = await Promise.all([
      list('assets'), list('accountability'), list('received_property')
    ]);
    if (state.org !== expected) return;
    state.rows = { assets, accountability, received_property }; render();
  };
  const submit = async (route, body, confirmText) => {
    if (state.busy || !state.org) return false;
    if (!window.confirm(confirmText)) return false;
    state.busy = true; $('org').disabled = true; $('register').disabled = true; $('issue').disabled = true;
    try {
      const result = await api(route, body);
      message('Saved successfully. ' + (result.number ? 'Document ' + result.number + '. ' : '') +
        'Review the recorded data and print where applicable.');
      await refresh();
      return true;
    } catch (error) { message(error.message || 'Request failed', true); return false; }
    finally { state.busy = false; $('org').disabled = false; renderLine(); renderAsset(); }
  };
  const init = async () => {
    try {
      const response = await fetch('api/index.php?route=auth.session', { credentials: 'same-origin' });
      const json = await response.json();
      if (!response.ok || !json.data.user) throw Error('Session expired. Return to IPDAMS and sign in again.');
      state.csrf = json.data.csrf;
      $('org').replaceChildren(option('', 'Choose an authorized organization'));
      for (const o of json.data.organizations || []) $('org').appendChild(option(o.id, o.name + ' · ' + o.kind));
      message('Choose an organization to begin.');
    } catch (e) { message(e.message, true); }
  };
  $('org').addEventListener('change', async () => {
    state.org = $('org').value;
    state.rows = { assets: [], accountability: [], received_property: [] }; render();
    if (!state.org) return;
    try { await refresh(); message('Authorized workspace loaded.'); }
    catch (e) { message(e.message, true); }
  });
  $('iar-line').addEventListener('change', renderLine);
  $('asset').addEventListener('change', renderAsset);
  $('asset-search').addEventListener('input', render);
  $('registration').addEventListener('submit', async e => {
    e.preventDefault();
    const line = selectedLine(); if (!line) return;
    const tags = $('property-tags').value.split(/\r?\n/).map(x => x.trim()).filter(Boolean);
    if (!tags.length || tags.length > 100 || new Set(tags).size !== tags.length) {
      message('Enter 1–100 distinct property tags, one per line.', true); return;
    }
    if (tags.some(x => x.length > 80)) { message('Each property tag must be at most 80 characters.', true); return; }
    if (tags.length > Number(line.accepted_qty) - Number(line.registered_units)) {
      message('The number of tags exceeds accepted unregistered units.', true); return;
    }
    const saved = await submit('asset.iar.register', { iar_line_id: Number(line.iar_line_id),
      assets: tags.map(property_tag => ({ property_tag })) },
      'Register ' + tags.length + ' individually tagged property item(s) from ' + line.iar_number + '? This will create permanent property records.');
    if (saved) $('property-tags').value = '';
  });
  $('issuance').addEventListener('submit', async e => {
    e.preventDefault();
    const a = selectedAsset(), custodian = $('custodian').value.trim();
    if (!a || !custodian || $('issue').disabled) return;
    const saved = await submit('accountability.issue', { asset_id: Number(a.id), doc_type: a.required_document, custodian },
      'Issue ' + a.required_document + ' for property ' + a.property_tag + ' to ' + custodian +
      '? This will record custody and create a numbered document. Signatures remain manual.');
    if (saved) $('custodian').value = '';
  });
  init();
})();
