/* IPDAMS v0.2.406 — read-only maintenance announcements and lock-screen.
 * The server decides access. This script never grants owner privileges.
 */
(() => {
  'use strict';
  const url = 'api/index.php?route=maintenance.status';
  let state = null, lastReceived = 0, serverOffset = 0, previousLocked = false;
  let ownerFormOpen = false, expanded = true, fetching = false;
  const make = (tag, className, content) => {
    const el=document.createElement(tag); if(className)el.className=className;
    if(content!==undefined)el.textContent=String(content); return el;
  };
  const notice = make('section', 'ipdams-maintenance-toast');
  notice.id='ipdams-maintenance-toast';notice.setAttribute('role','status');notice.setAttribute('aria-label','System maintenance notice');
  const noticeToggle=make('button','ipdams-maintenance-toast-toggle','Upcoming maintenance');
  noticeToggle.type='button';noticeToggle.setAttribute('aria-expanded','true');
  const noticeBody=make('div','ipdams-maintenance-toast-body');
  const noticeHeading=make('strong','ipdams-maintenance-toast-heading');
  const noticeMessage=make('p','ipdams-maintenance-toast-message');
  const noticeTimer=make('span','ipdams-maintenance-timer');noticeTimer.setAttribute('aria-live','off');
  noticeBody.append(noticeHeading,noticeMessage,noticeTimer);notice.append(noticeToggle,noticeBody);
  noticeToggle.addEventListener('click',()=>{
    expanded=!expanded;noticeBody.hidden=!expanded;noticeToggle.setAttribute('aria-expanded',String(expanded));
  });
  const overlay=make('div','ipdams-maintenance-overlay');overlay.id='ipdams-maintenance-overlay';
  overlay.setAttribute('role','region');overlay.setAttribute('aria-label','System maintenance');
  const panel=make('section','ipdams-maintenance-panel');
  const eyebrow=make('div','ipdams-maintenance-eyebrow','IPDAMS · SYSTEM NOTICE');
  const heading=make('h1','ipdams-maintenance-page-heading');
  const message=make('p','ipdams-maintenance-page-message');
  const note=make('p','ipdams-maintenance-page-note','Existing work is preserved according to its last successful save. Unsubmitted entries are not automatically finalized.');
  const ownerToggle=make('button','ipdams-maintenance-owner-button','Owner sign-in');ownerToggle.type='button';
  const ownerForm=make('form','ipdams-maintenance-owner-form');ownerForm.hidden=true;
  const emailLabel=make('label',null,'Original owner email');const email=make('input');email.type='email';email.required=true;email.autocomplete='username';email.maxLength=190;
  const passLabel=make('label',null,'Password');const password=make('input');password.type='password';password.required=true;password.autocomplete='current-password';
  const submit=make('button',null,'Sign in to Maintenance Control');submit.type='submit';
  const error=make('p','ipdams-maintenance-owner-error');error.setAttribute('role','alert');
  emailLabel.append(email);passLabel.append(password);ownerForm.append(emailLabel,passLabel,submit,error);
  ownerToggle.addEventListener('click',()=>{
    ownerFormOpen=!ownerFormOpen;ownerForm.hidden=!ownerFormOpen;
    if(ownerFormOpen)email.focus();
  });
  ownerForm.addEventListener('submit',async e=>{
    e.preventDefault();submit.disabled=true;error.textContent='';
    try{
      const csrfResponse=await fetch('api/index.php?route=auth.session',{credentials:'same-origin',cache:'no-store'});
      if(!csrfResponse.ok)throw Error('Authentication unavailable. Contact the technical administrator.');
      const csrfData=await csrfResponse.json();const token=csrfData?.data?.csrf;
      if(typeof token!=='string'||!token)throw Error('Owner authentication token unavailable.');
      const r=await fetch('api/index.php?route=auth.login',{
        method:'POST',credentials:'same-origin',cache:'no-store',
        headers:{'Content-Type':'application/json','X-CSRF-Token':token},
        body:JSON.stringify({email:email.value,password:password.value})
      });
      const data=await r.json().catch(()=>({error:'Sign-in is currently unavailable.'}));
      if(!r.ok)throw Error(data.error||'Owner authentication failed.');
      password.value='';
      window.location.assign('settings.php?tab=maintenance');
    }catch(err){error.textContent=err?.message||'Owner sign-in failed.';password.value='';}
    finally{submit.disabled=false;}
  });
  panel.append(eyebrow,heading,message,note,ownerToggle,ownerForm);overlay.append(panel);
  function now(){return Math.floor(Date.now()/1000)+serverOffset;}
  function countdown(seconds){
    const s=Math.max(0,Math.floor(seconds));const days=Math.floor(s/86400);const rest=s%86400;
    const time=[Math.floor(rest/3600),Math.floor((rest%3600)/60),rest%60].map(n=>String(n).padStart(2,'0')).join(':');
    return days?`${days} day${days===1?'':'s'} ${time}`:time;
  }
  function publicNow(){
    if(!state)return null;
    let current={...state};
    const t=now();const start=state.starts_at?Math.floor(Date.parse(state.starts_at)/1000):0;
    if(state.status==='COUNTDOWN'||state.status==='SCHEDULED'){
      const seconds=Math.max(0,start-t);current.countdown_seconds=seconds;
      if(seconds===0){
        if(state.auto_activate){current.active=true;current.status='ACTIVE';current.show_notice=true;}
        else {current.status='WAITING_OWNER';current.show_notice=true;}
      }
    }
    return current;
  }
  function render(){
    const cfg=publicNow();if(!cfg)return;
    const locked=cfg.active&&!cfg.owner_session;
    if(locked && !previousLocked){
      // Erase any React state bearing office records under the overlay immediately.
      const root=document.getElementById('root');
      if(root){root.setAttribute('aria-hidden','true');root.inert=true;}
    }
    if(!locked && previousLocked){
      const root=document.getElementById('root');if(root){root.removeAttribute('aria-hidden');root.inert=false;}
      // Re-validate the now-restored session and any cached office data.
      window.location.reload();return;
    }
    previousLocked=locked;
    overlay.hidden=!locked;
    document.documentElement.classList.toggle('ipdams-maintenance-locked',locked);
    if(locked){
      notice.hidden=true;
      heading.textContent=cfg.active_header||'SYSTEM MAINTENANCE';
      message.textContent=cfg.active_message||'IPDAMS is under maintenance.';
    }else if(cfg.show_notice||cfg.active&&cfg.owner_session){
      notice.hidden=false;
      const sec=cfg.countdown_seconds??0;
      const text=countdown(sec);
      const isOwner=cfg.active&&cfg.owner_session;
      noticeHeading.textContent=isOwner?'MAINTENANCE ACTIVE — OWNER ACCESS':cfg.header||'UPCOMING SYSTEM UPDATE';
      noticeMessage.textContent=isOwner?'The system is locked to other accounts. Open Maintenance Control to unlock it.':
        (cfg.message||'Maintenance begins in [countdown].').replaceAll('[countdown]',text);
      noticeTimer.textContent=isOwner?'Owner maintenance controls are available in Settings.':
        cfg.status==='WAITING_OWNER'?'Awaiting owner activation':`Time remaining: ${text}`;
      noticeToggle.textContent=isOwner?'Maintenance active':`Maintenance · ${text}`;
      notice.classList.toggle('ipdams-maintenance-urgent',sec<=300&&!isOwner);
    }else notice.hidden=true;
  }
  async function poll(){
    if(fetching)return;fetching=true;
    try{
      const r=await fetch(url,{credentials:'same-origin',cache:'no-store'});
      if(!r.ok)throw Error('Cannot retrieve maintenance status');
      const payload=(await r.json()).data;
      if(!payload||typeof payload!=='object')throw Error('Invalid status');
      state=payload;lastReceived=Date.now();serverOffset=(Number(payload.server_now)||Math.floor(lastReceived/1000))-Math.floor(lastReceived/1000);
      render();
    }catch(_){
      // Never hide an existing active lock merely because a polling request fails.
      if(state?.active)render();
    }finally{fetching=false;}
  }
  function start(){
    document.body.append(notice,overlay);notice.hidden=true;overlay.hidden=true;
    poll();window.setInterval(render,1000);window.setInterval(poll,15000);
    document.addEventListener('visibilitychange',()=>{if(!document.hidden)poll();});
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
