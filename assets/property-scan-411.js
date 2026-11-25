'use strict';
(() => {
  const $ = name => document.querySelector(name);
  const form = $('#scan-propose') || $('#scan-decide') || $('#scan-login');
  if (!form) return;
  const status = $('#scan-status');
  let processing = false;
  const message = text => { if (status) status.textContent = text; };
  const json = async response => {
    const body = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(body.error || body.message || 'Request failed. Please try again.');
    return body;
  };
  const csrf = async () => {
    const state = await json(await fetch('index.php?route=auth.session', {
      credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }
    }));
    if (!state.data?.csrf) throw new Error('Sign-in session unavailable; refresh this page.');
    return state.data.csrf;
  };
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (processing) return;
    processing = true;
    const buttons = [...form.querySelectorAll('button')];
    buttons.forEach(button => button.disabled = true);
    message('Checking your request…');
    try {
      const token = await csrf();
      if (form.id === 'scan-login') {
        const data = new FormData(form);
        await json(await fetch('index.php?route=auth.login', {
          method: 'POST', credentials: 'same-origin', cache: 'no-store',
          headers: {'Content-Type':'application/json', 'X-CSRF-Token':token},
          body: JSON.stringify({email: String(data.get('email') || ''),password: String(data.get('password') || '')})
        }));
        window.location.reload();
        return;
      }
      const panel = $('.scan-panel');
      const data = new FormData(form);
      const review = form.id === 'scan-decide';
      const decision = review ? String(event.submitter?.value || '') : '';
      if (review && !['APPROVE','REJECT'].includes(decision)) throw new Error('Choose Confirm or Discard.');
      const payload = review
        ? {decision,review_reason: String(data.get('review_reason')||''),request_version: Number(panel.dataset.version)}
        : {condition:String(data.get('condition')||''),inspected_on:String(data.get('inspected_on')||''),
          inspection_reference:String(data.get('inspection_reference')||''),remarks:String(data.get('remarks')||''),
          physically_inspected: data.get('physically_inspected') !== null,
          expected_version:panel.dataset.version};
      const route = new URL(window.location.href);
      route.searchParams.set('mode', review?'decide':'submit');
      const result=await json(await fetch(route.href, {
        method:'POST',credentials:'same-origin',cache:'no-store',
        headers:{'Content-Type':'application/json','X-CSRF-Token':token,'Accept':'application/json'},
        body:JSON.stringify(payload)
      }));
      message(review ? (result.data?.state==='APPROVED'?'Confirmed. The official condition was updated.':'Discarded. The official condition was not changed.') :
        'Inspection saved. Sign in to the main IPDAMS Action Center to review and confirm it. The official condition is unchanged.');
      form.hidden = true;
    } catch(error) {
      message(String(error?.message || 'Request failed; please try again.'));
    } finally {processing=false;buttons.forEach(button=>button.disabled=false);}
  });
})();
