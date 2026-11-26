'use strict';
const allocationForm=document.getElementById('finance-allocation');
if(allocationForm){
    const host=document.getElementById('allocation-lines');
    const exactCents=value=>{if(!/^\d+(?:\.\d{1,2})?$/.test(value))throw new Error('Use an exact positive amount with at most two decimal places.');const [a,b='']=value.split('.');const result=Number(a)*100+Number((b+'00').slice(0,2));if(!Number.isSafeInteger(result)||result<=0)throw new Error('Invalid allocation amount.');return result;};
    const add=line=>{
        const row=document.getElementById('allocation-row').content.firstElementChild.cloneNode(true);
        const source=row.querySelector('[data-source]');
        source.addEventListener('change',()=>{const manual=source.value.endsWith(':MANUAL');row.querySelector('[data-manual-label]').hidden=!manual;row.querySelector('[data-manual]').required=manual;});
        row.querySelector('[data-remove]').addEventListener('click',()=>row.remove());
        if(line){
            const options=[...source.options];const match=options.find(o=>{const [kind,id,index,uacs]=o.value.split(':');return kind===line.kind&&(Number(id)>0?Number(id)===Number(line.source_id):uacs===line.uacs_code&&(line.accounting_line_index===undefined||Number(index)===Number(line.accounting_line_index)));});
            if(match)source.value=match.value;
            row.querySelector('[data-download]').value=String(line.download_id);row.querySelector('[data-amount]').value=(line.cents/100).toFixed(2);row.querySelector('[data-evidence]').value=line.evidence||line.evidence_reference||'';
        }
        host.append(row);
    };
    document.getElementById('allocation-add').addEventListener('click',()=>add());
    const debitHost=document.getElementById('accounting-debits');
    if(debitHost){
        const wireDebit=row=>row.querySelector('[data-debit-remove]').addEventListener('click',()=>row.remove());
        [...debitHost.children].forEach(wireDebit);
        document.getElementById('debit-add').addEventListener('click',()=>{const row=document.getElementById('debit-row').content.firstElementChild.cloneNode(true);wireDebit(row);debitHost.append(row);});
    }
    const plan=document.getElementById('allocation-plan');if(plan){for(const line of JSON.parse(plan.textContent))add(line);}else add();
    allocationForm.addEventListener('submit',async event=>{
        event.preventDefault();const button=allocationForm.querySelector('[type=submit]');button.disabled=true;const status=document.getElementById('finance-status');
        try{
            const body=Object.fromEntries(new FormData(allocationForm));for(const key of ['organization_id','dv_id','bank_account_id','expected_version','expected_allocation_id','expected_accounting_version'])if(key in body)body[key]=Number(body[key]);
            if('historical_review' in body)body.historical_review=true;
            if('replace_allocation' in body)body.replace_allocation=true;
            body.lines=[...host.querySelectorAll('.allocation-row')].map(row=>{const [kind,id,index,uacs]=row.querySelector('[data-source]').value.split(':');const line={kind,download_id:Number(row.querySelector('[data-download]').value),cents:exactCents(row.querySelector('[data-amount]').value),evidence:row.querySelector('[data-evidence]').value.trim()};if(debitHost&&kind==='GROSS')line.uacs_code=uacs==='MANUAL'?row.querySelector('[data-manual]').value:uacs;else if(Number(id)>0)line.source_id=Number(id);else{line.uacs_code=uacs==='MANUAL'?row.querySelector('[data-manual]').value:uacs;if(index!=='')line.accounting_line_index=Number(index);}return line;});
            if(debitHost)body.accounting=[...debitHost.querySelectorAll('.accounting-debit')].map(row=>({uacs_code:row.querySelector('[data-debit-uacs]').value,debit:(exactCents(row.querySelector('[data-debit-amount]').value)/100).toFixed(2),credit:'0.00'}));
            if(!body.lines.length)throw new Error('Add at least one explicit source allocation.');
            const response=await fetch('index.php?route='+allocationForm.dataset.route,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':allocationForm.dataset.csrf,'X-Idempotency-Key':allocationForm.dataset.requestKey+'-'+(allocationForm.dataset.sequence||'0')},body:JSON.stringify(body)});
            const payload=await response.json();if(!response.ok)throw new Error(payload.error||'Allocation save failed');
            status.textContent=allocationForm.dataset.route.endsWith('.plan')?'Plan saved. Return to the DV final review, refresh and use the selected bank. Capacity is reserved only when finalization succeeds.':'Explicit historical attribution saved with review evidence.';
            allocationForm.dataset.sequence=String(Number(allocationForm.dataset.sequence||'0')+1);
            if(payload.data.version_id)allocationForm.elements.expected_version.value=payload.data.version_id;
            if(payload.data.id&&allocationForm.elements.expected_allocation_id)allocationForm.elements.expected_allocation_id.value=payload.data.id;
            if(debitHost){status.textContent='Accounting correction and source revision saved. Cash and original payment dates are preserved.';const url=new URL(location.href);url.searchParams.delete('mode');location.assign(url);}
        }catch(error){status.textContent=error.message;}finally{button.disabled=false;}
    });
}
