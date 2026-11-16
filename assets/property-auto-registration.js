/* v0.2.213: adapt legacy staging property workspace without replacing its existing JS.
   Capture submit before the older manual-tag handler; server assigns final numbers. */
(function(){'use strict';
  function init(){
    var form=document.getElementById('registration'), count=document.getElementById('property-tags');
    var school=document.getElementById('org'), line=document.getElementById('iar-line');
    var msg=document.getElementById('message');
    if(!form||!count||!school||!line||!msg)return;
    form.addEventListener('submit',async function(event){
      event.preventDefault();event.stopImmediatePropagation();
      var units=Number(count.value),org=Number(school.value),iar=Number(line.value);
      if(!Number.isInteger(units)||units<1||units>100||!Number.isInteger(org)||org<1||!Number.isInteger(iar)||iar<1){
        msg.textContent='Choose a school and accepted IAR line and enter 1–100 whole units.';msg.className='message error';return;
      }
      if(!window.confirm('Register '+units+' property unit(s)? Unique property numbers will be assigned automatically.'))return;
      var submit=document.getElementById('register');if(submit)submit.disabled=true;
      try{
        var session=await fetch('api/index.php?route=auth.session',{credentials:'same-origin'});
        var auth=await session.json();if(!session.ok||!auth.data?.csrf)throw Error('Sign in again before property registration.');
        var key='property-auto-'+(typeof crypto!=='undefined'&&crypto.randomUUID?crypto.randomUUID():String(Date.now()));
        var res=await fetch('api/index.php?route=asset.iar.register',{
          method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':auth.data.csrf,'X-Idempotency-Key':key},
          body:JSON.stringify({organization_id:org,iar_line_id:iar,unit_count:units})});
        var result=await res.json();if(!res.ok)throw Error(result.error||'Registration failed');
        var data=result.data||result;
        var detail=document.getElementById('property-auto-success');
        if(!detail){detail=document.createElement('p');detail.id='property-auto-success';detail.setAttribute('role','status');form.insertAdjacentElement('afterend',detail);}
        detail.textContent='Generated property numbers: '+((data.property_numbers||[]).join(', ')||'See registered property table.');
        msg.textContent='Registration saved successfully.';msg.className='message';count.value='1';
        // Refresh existing read-only lists without changing selected school.
        school.dispatchEvent(new Event('change'));
      }catch(e){msg.textContent=e.message||'Registration failed';msg.className='message error';}
      finally{if(submit)submit.disabled=false;}
    },true);
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
