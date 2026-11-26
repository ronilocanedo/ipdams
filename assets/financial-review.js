'use strict';
document.querySelector('[data-finance-print]')?.addEventListener('click',()=>window.print());
for(const form of document.querySelectorAll('form[data-finance-route]')){
    if(form.dataset.financeRoute==='finance.review.source'){
        const kind=form.elements.kind;
        const update=()=>{const resolving=kind.value==='SOURCE_RESOLVED';form.elements.amount.required=!resolving;form.elements.source_reference.required=!resolving;form.elements.source_id.required=resolving;form.elements.reason.required=resolving;};
        kind.addEventListener('change',update);update();
    }
    form.addEventListener('submit',async event=>{
        event.preventDefault();const buttons=[...form.querySelectorAll('button')];buttons.forEach(b=>b.disabled=true);const status=document.getElementById('finance-status');
        try{
            const values=new FormData(form),data=Object.fromEntries(values);const route=event.submitter?.dataset.financeAction||form.dataset.financeRoute;
            for(const key of ['organization_id','bank_account_id','expected_version','predecessor_id','fund_event_id','accounting_line_id','source_id']){
                if(data[key]==='')delete data[key];else if(key in data)data[key]=Number(data[key]);
            }
            delete data['check_ids[]'];delete data['source_ids[]'];
            if(form.id==='finance-draft')data.lines=[...values.getAll('source_ids[]').map(id=>({source_id:Number(id)})),...values.getAll('check_ids[]').map(id=>({check_id:Number(id),evidence:data.statement_evidence}))];
            const response=await fetch('index.php?route='+route,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':form.dataset.csrf,'X-Idempotency-Key':form.dataset.requestKey},body:JSON.stringify(data)});
            const payload=await response.json();if(!response.ok)throw new Error(payload.error||'Request failed');
            const url=new URL(location.href);if(payload.data.snapshot_id)url.searchParams.set('snapshot_id',payload.data.snapshot_id);location.assign(url);
        }catch(error){status.textContent=error.message;status.scrollIntoView({block:'nearest'});}finally{buttons.forEach(b=>b.disabled=false);}
    });
}
