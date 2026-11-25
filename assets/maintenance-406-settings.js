(() => {
  'use strict';
  document.querySelectorAll('[data-maintenance-confirm]').forEach(button=>{
    button.addEventListener('click',event=>{if(!window.confirm(button.dataset.maintenanceConfirm))event.preventDefault();});
  });
  const form=document.getElementById('maintenance-schedule-form');
  const input=document.getElementById('maintenance-preset-data');
  if(!form||!input)return;
  let presets=[];
  try{presets=JSON.parse(input.textContent||'[]');}catch(_){presets=[];}
  const select=document.getElementById('maintenance-preset-select');
  select?.addEventListener('change',()=>{
    const index=Number(select.value);
    if(select.value===''||!Number.isInteger(index)||!presets[index])return;
    const preset=presets[index];form.elements.header.value=preset.header||'';
    form.elements.message.value=preset.message||'';
  });
  const noticeDays=document.getElementById('maintenance-notice-days');
  const quickDays=document.getElementById('maintenance-quick-days');
  quickDays?.addEventListener('change',()=>{if(quickDays.value)noticeDays.value=quickDays.value;});
  noticeDays?.addEventListener('input',()=>{if(quickDays)quickDays.value='';});
  const repeat=document.getElementById('maintenance-repeat');
  const duration=document.getElementById('maintenance-duration');
  const validate=()=>{
    if(repeat.value==='NONE')duration.min='0';
    else{duration.min='1';if(Number(duration.value)<1)duration.value='60';}
  };
  repeat?.addEventListener('change',validate);validate();
})();
