'use strict';
(() => {
  const $ = id => document.getElementById(id);
  const processTypes = [
    ['pr','Purchase Requests (PR)',['display_number','document_date','purpose','fund_name','total','status'],'PR'],
    ['rfq','Requests for Quotation (RFQ)',['display_number','issued_at','pr_number','status'],'RFQ'],
    ['quotes','Supplier Quotations',['rfq_number','supplier','offered_at','offered_total'],null],
    ['awards','Award Determinations',['display_number','supplier','status'],'AWARD'],
    ['po','Purchase Orders (PO)',['display_number','supplier','pr_number','status'],'PO'],
    ['iar','Inspection and Acceptance (IAR)',['display_number','accepted_at','po_number','status'],'IAR'],
    ['dv','Disbursement Vouchers (DV)',['display_number','dv_type','payee','gross','net','status'],'DV'],
    ['ris','Requisition and Issue Slips (RIS)',['display_number','requestor','status'],'RIS'],
    ['checks','Checks',['check_number','dv_number','amount','status'],null],
    ['accountability','Property Accountability (ICS/PAR)',['display_number','doc_type','property_tag','item_name','custodian','status'],'ACCOUNTABILITY'],
    ['assets','Property Register',['property_tag','item_name','acquired_on','category','custodian','status'],null]
  ];
  const message = (text, error = false) => {
    $('message').textContent = text; $('message').className = error ? 'message error' : 'message';
  };
  const opt = (value, title) => {
    const item = document.createElement('option'); item.value = value; item.textContent = title; return item;
  };
  const appendCell = (tr, value) => {
    const td=document.createElement('td'); td.textContent=String(value ?? '—');tr.appendChild(td);return td;
  };
  const render = (definition, records, org) => {
    const [kind, title, columns, printType] = definition;
    $('results-title').textContent = title;
    $('result-count').textContent = records.length + (records.length===100 ? ' records shown (limit 100)' : ' records');
    $('table-head').replaceChildren();
    $('table-body').replaceChildren();
    const header = document.createElement('tr');
    for (const column of columns) {
      const th=document.createElement('th');th.textContent=column.replace(/_/g,' ');header.appendChild(th);
    }
    if(printType){const th=document.createElement('th');th.textContent='Print';header.appendChild(th);}
    $('table-head').appendChild(header);
    if(!records.length){
      const tr=document.createElement('tr'),td=appendCell(tr,'No matching records for this period.');
      td.colSpan=columns.length+(printType?1:0);$('table-body').appendChild(tr);return;
    }
    for(const r of records) {
      const tr=document.createElement('tr');
      for(const column of columns)appendCell(tr,r[column]);
      if(printType){
        const td=document.createElement('td'),actualType=printType==='ACCOUNTABILITY'?r.doc_type:printType;
        if(r.display_number && r.status!=='DRAFT' && r.status!=='CANCELLED' &&
          r.status!=='REVERSED' && r.id && actualType){
          const link=document.createElement('a');
          link.href='api/print.php?organization_id='+encodeURIComponent(org)+
            '&type='+encodeURIComponent(actualType)+'&id='+encodeURIComponent(r.id);
          link.target='_blank';link.rel='noopener noreferrer';link.textContent='Print '+actualType;
          td.appendChild(link);
        }else td.textContent='—';
        tr.appendChild(td);
      }
      $('table-body').appendChild(tr);
    }
  };
  let nonce=0;
  const search=async () => {
    const org=$('org').value,year=$('year').value,month=$('month').value,kind=$('process').value;
    const definition=processTypes.find(t=>t[0]===kind);
    const request=++nonce;
    if(!org || !definition){
      $('table-head').replaceChildren(); $('table-body').replaceChildren();
      $('result-count').textContent=''; $('period-info').textContent='';
      message('Choose an authorized organization and process.',true);return;
    }
    const query=new URLSearchParams({route:'list',organization_id:org,kind});
    if(year)query.set('year',year);
    if(month)query.set('month',month);
    $('search').disabled=true;message('Loading records…');
    try{
      const response=await fetch('api/index.php?'+query.toString(),{credentials:'same-origin'});
      const payload=await response.json();
      if(!response.ok)throw Error(payload.error||'Could not load transaction records');
      if(request!==nonce)return;
      const rows=Array.isArray(payload.data)?payload.data:[];
      render(definition,rows,org);
      const mon=month?$('month').selectedOptions[0].textContent:'All months';
      $('period-info').textContent=(year||'All years')+' · '+mon+
        ' · Document / issuance date, scoped to selected organization. Results are read-only.';
      message('Transaction register loaded.');
    }catch(e){if(request===nonce)message(e.message||'Unable to load archive',true);}
    finally{if(request===nonce)$('search').disabled=false;}
  };
  const init=async () => {
    try{
      const r=await fetch('api/index.php?route=auth.session',{credentials:'same-origin'});
      const j=await r.json();if(!r.ok||!j.data.user)throw Error('Sign in to IPDAMS and try again.');
      $('org').replaceChildren(opt('','Choose organization'));
      for(const o of j.data.organizations||[]) $('org').appendChild(opt(String(o.id),o.name+' · '+o.kind));
      $('process').replaceChildren(...processTypes.map(p=>opt(p[0],p[1])));
      $('year').replaceChildren(opt('','All years'));
      const year=new Date().getFullYear();
      for(let n=year+1;n>=2000;n--)$('year').appendChild(opt(String(n),String(n)));
      $('month').replaceChildren(opt('','All months'));
      for(let m=1;m<=12;m++) $('month').appendChild(opt(String(m),new Date(2020,m-1,1).toLocaleString('en-PH',{month:'long'})));
      if((j.data.organizations||[]).length)$('org').value=String(j.data.organizations[0].id);
      $('year').value='';$('month').value='';
      if($('org').value)await search();
      else message('Your account has no assigned organization.',true);
    }catch(e){message(e.message||'Unable to load account',true);}
  };
  $('search').addEventListener('click',search);
  for(const id of ['org','process','year','month'])$(id).addEventListener('change',search);
  init();
})();
