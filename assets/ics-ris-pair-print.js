/* IPDAMS v0.2.222: documentation-only ICS / RIS browser print.
 * No network calls, authentication changes or mutations. Caller supplies only
 * data from the authenticated school-scoped application API.
 */
(function(global){'use strict';
  function e(v){return String(v===null||v===undefined?'':v).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
  function amount(v){if(v===null||v===undefined||v==='')return '';const n=Number(v);return Number.isFinite(n)?n.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}):'';}
  // RIS/ICS are paper documents only. All fields are escaped, including
  // historic signatories; absent signing dates and fund codes stay blank.
  function escapeSrc(v){return e(v);}
  function printHref(){
    const href=String(global.location?.href||'');
    const absolute=href?new URL('assets/ics-ris-pair-print.css?v=0292-compact-pair-details',
      new URL('app.html',href)).href:'assets/ics-ris-pair-print.css?v=0292-compact-pair-details';
    return e(absolute);
  }
  // Separate line under each item name. Only use verified saved property fields;
  // omit missing values rather than printing placeholders or inferred identities.
  function itemDescription(entry){
    const identifiers=[['Brand',entry.brand],['Model',entry.model],['Serial No.',entry.serial_number]]
      .filter(([label,value])=>String(value===null||value===undefined?'':value).trim()!=='')
      .map(([label,value])=>'<span>'+e(label)+': '+e(String(value).trim())+'</span>');
    return '<div class="item-primary">'+e(entry.item_name||'')+'</div>'+
      (identifiers.length?'<div class="item-identifiers">'+identifiers.join(' <span aria-hidden="true">·</span> ')+'</div>':'');
  }
  function risSignatureRows(parties){
    const cols=parties.map(x=>'<col class="ris-sig-person">').join('');
    const names=parties.map(x=>'<td class="sig-name">'+e(x.person.name||'')+'</td>').join('');
    const jobs=parties.map(x=>'<td class="sig-position">'+e(x.person.designation||'')+'</td>').join('');
    const dates=parties.map(x=>'<td class="sig-date">'+e(x.person.verified_date||'')+'</td>').join('');
    return '<table class="ris-signatures" aria-label="RIS wet-signature grid"><colgroup><col class="ris-sig-label">'+cols+'</colgroup><thead><tr><th></th>'+parties.map(x=>'<th class="role-head">'+e(x.role)+'</th>').join('')+'</tr></thead><tbody>'+
      '<tr><th class="row-title">Signature:</th>'+parties.map(()=>'<td class="sign-space"></td>').join('')+'</tr>'+
      '<tr><th class="row-title">Printed Name:</th>'+names+'</tr><tr><th class="row-title">Designation:</th>'+jobs+'</tr><tr><th class="row-title">Date:</th>'+dates+'</tr></tbody></table>';
  }
  function icsSigner(role,person){
    const p=person||{};
    return '<div class="sign-party"><div class="sign-label">'+e(role)+'</div><div class="sign-name">'+e(p.name||'')+'</div>'+
      '<div class="sign-caption">Signature Over Printed Name</div><div class="sign-designation">'+e(p.designation||'')+'</div>'+
      '<div class="sign-caption">Position / Office</div><div class="sign-date">'+e(p.verified_date||'')+'</div><div class="date-caption">Date</div></div>';
  }
  function buildRisSheet(d, items, no){
    const recipient=d.recipient||{}, ao=d.school_ao||{}, principal=d.school_principal||{};
    const roles=[{role:'Requested by:',person:recipient},{role:'Approved by:',person:principal},
      {role:'Issued by:',person:ao},{role:'Received by:',person:recipient}];
    const rows=items.map((entry,index)=>'<tr class="item-row"><td class="center">'+(index+1)+'</td><td class="center">'+e(entry.unit||'unit')+'</td><td>'+itemDescription(entry)+'</td>'+
      '<td class="center">1</td><td class="center">✓</td><td></td><td class="center">1</td><td></td></tr>').join('');
    const blanks=Array.from({length:Math.max(0,7-items.length)},()=>'<tr class="blank-row pad-row">'+Array.from({length:8},()=>'<td></td>').join('')+'</tr>').join('');
    return '<section class="form-sheet ris-sheet" aria-label="Appendix 63 Requisition and Issue Slip">'+
      '<div class="appendix">Appendix 63</div><h1 class="form-heading">REQUISITION AND ISSUE SLIP</h1>'+
      '<div class="form-head"><div class="head-pair"><span>Entity Name:</span><strong>'+e(d.entity_name||'DEPARTMENT OF EDUCATION')+'</strong></div>'+
      '<div class="head-pair align-right"><span>Fund Cluster:</span><strong>'+e(d.fund_cluster||'')+'</strong></div></div>'+
      '<table class="ris-meta"><tbody><tr><td><span class="label">Division:</span><span class="meta-strong">'+e(d.division_name||'')+'</span></td>'+
      '<td><span class="label">Responsibility Center Code:</span><span class="meta-strong">'+e(d.rcc||'')+'</span></td></tr>'+
      '<tr><td><span class="label">Office:</span><span class="meta-strong">'+e(d.school_name||d.organization_name||'')+'</span></td>'+
      '<td><span class="label">RIS No.:</span><span class="meta-strong">'+e(d.ris_number||'________________')+'</span></td></tr></tbody></table>'+
      '<table class="ris-table"><colgroup><col class="ris-stock"><col class="ris-unit"><col class="ris-description"><col class="ris-quantity"><col class="ris-yes"><col class="ris-no"><col class="ris-issue"><col class="ris-remarks"></colgroup>'+
      '<thead><tr><th colspan="4">Requisition</th><th colspan="2">Stock Available?</th><th colspan="2">Issue</th></tr>'+
      '<tr><th>Stock<br>No.</th><th>Unit</th><th>Description</th><th>Quantity</th><th>Yes</th><th>No</th><th>Quantity</th><th>Remarks</th></tr></thead>'+
      '<tbody>'+rows+'<tr class="blank-row"><td></td><td></td><td colspan="6" class="nothing-follows">*** NOTHING FOLLOWS ***</td></tr>'+blanks+'</tbody></table>'+
      '<div class="ris-purpose"><strong>Purpose:</strong>'+e(d.purpose||'Property issuance for accountable custody')+'</div>'+
      risSignatureRows(roles)+'</section>';
  }
  function buildIcsSheet(d,items,no){
    const rows=items.map(entry=>'<tr class="item-row"><td class="center">1</td><td class="center">'+e(entry.unit||'unit')+'</td>'+
      '<td class="money">'+amount(entry.unit_cost)+'</td><td class="money">'+amount(entry.unit_cost)+'</td>'+
      '<td>'+itemDescription(entry)+'</td><td class="center">'+e(entry.property_tag)+'</td><td class="center">'+e(entry.useful_life)+'</td></tr>').join('');
    const blank=Array.from({length:Math.max(0,Math.min(5,10-items.length))},()=>'<tr class="blank-row pad-row">'+Array.from({length:7},()=>'<td></td>').join('')+'</tr>').join('');
    return '<section class="form-sheet ics-sheet" aria-label="Appendix 59 Inventory Custodian Slip">'+
      '<div class="appendix">Appendix 59</div><h1 class="form-heading ics-title">INVENTORY CUSTODIAN SLIP</h1>'+
      '<div class="form-head ics-head"><div class="head-pair"><span>Entity Name:</span><strong>'+e(d.entity_name||'DEPARTMENT OF EDUCATION')+'</strong></div><div></div>'+
      '<div class="head-pair"><span>Fund Cluster:</span><strong>'+e(d.fund_cluster||'')+'</strong></div>'+
      '<div class="head-record-number">ICS No.: <strong>'+e(no)+'</strong></div></div>'+
      '<table class="ics-table"><colgroup><col class="ics-qty"><col class="ics-unit"><col class="ics-cost"><col class="ics-total"><col class="ics-description"><col class="ics-tag"><col class="ics-life"></colgroup>'+
      '<thead><tr><th rowspan="2">Quantity</th><th rowspan="2">Unit</th><th colspan="2">Amount</th><th rowspan="2">Description</th><th rowspan="2">Inventory<br>Item No.</th><th rowspan="2">Estimated<br>Useful Life</th></tr>'+
      '<tr><th>Unit Cost</th><th>Total Cost</th></tr></thead><tbody>'+rows+blank+'</tbody></table>'+
      '<div class="ics-signatures">'+icsSigner('Received from:',d.school_ao||{})+icsSigner('Receive by:',d.recipient||{})+'</div></section>';
  }
  function render(input){
    if(!input||input.doc_type!=='ICS'||input.status!=='FINALIZED')throw new Error('Finalized ICS required.');
    const no=String(input.display_number||'').trim();
    const items=Array.isArray(input.items)&&input.items.length?input.items:
      [{property_tag:input.property_tag,item_name:input.item_name,unit:input.unit,unit_cost:input.unit_cost,useful_life:input.useful_life}];
    if(!no||!input.organization_name||items.length<1||items.length>20||items.some(x=>!x.property_tag||!x.item_name))
      throw new Error('Saved ICS items or document identity are incomplete.');
    const icsOnly=input.print_mode==='ICS';
    const risOnly=input.print_mode==='RIS';
    // Put a short RIS + ICS pair on one physical A4 sheet. Longer item
    // memberships continue to print as separate forms; browser pagination
    // still protects unexpectedly long descriptions or signatory names.
    const compactPair=!icsOnly && !risOnly && items.length<=4 &&
      String(input.organization_name||'').length<=90 &&
      [input.recipient,input.school_ao,input.school_principal].every(person=>(
        String(person?.name||'').length<=42 && String(person?.designation||'').length<=58)) &&
      items.every(item=>(
        String(item.item_name||'').length+String(item.brand||'').length+
        String(item.model||'').length+String(item.serial_number||'').length)<=90);

    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'+
      '<title>'+e(icsOnly?'ICS '+no:risOnly?'RIS for ICS '+no:'RIS + ICS '+no)+'</title><link rel="stylesheet" href="'+printHref()+'"></head><body class="formal-accountability-print'+(compactPair?' pair-compact':'')+'">'+
      '<div class="print-toolbar"><button id="print-button" type="button">Print '+(icsOnly?'ICS':risOnly?'RIS':'RIS + ICS')+'</button>'+
      '<span>Print for physical signatures. Printing does not issue, authorize or post a new transaction.</span></div>'+
      '<main class="print-pages">'+(icsOnly?'':buildRisSheet(input,items,no))+(risOnly?'':buildIcsSheet(input,items,no))+'</main></body></html>';
  }
  function semiPrintHref(){
    const href=String(global.location?.href||'');
    const absolute=href?new URL('assets/semi-issued-report.css?v=0295-division-mooe-posted-by',new URL('app.html',href)).href
      :'assets/semi-issued-report.css?v=0295-division-mooe-posted-by';
    return e(absolute);
  }
  // Report of Semi-Expendable Property Issued: read-only Appendix 64
  // working print for one verified ICS / documentary companion RIS.
  // This does not register a separate report number, certify/post accounting,
  // or consume any supplies stock. Source ICS is required, not a supplies RIS.
  function renderSemiIssued(input){
    if(!input||input.doc_type!=='ICS'||input.status!=='FINALIZED')
      throw new Error('Finalized ICS required for semi-expendable report.');
    const icsNumber=String(input.display_number||'').trim();
    const items=Array.isArray(input.items)?input.items:[];
    if(!icsNumber||!String(input.organization_name||'').trim()||!items.length||items.length>20)
      throw new Error('Source ICS or saved property list is incomplete.');
    const seen=new Set();let total=0;
    const cents=v=>{
      const txt=String(v===null||v===undefined?'':v).trim();
      if(!/^(?:0|[1-9]\d{0,11})(?:\.\d{1,2})?$/.test(txt))
        throw new Error('A registered property lacks a verified acquisition unit cost.');
      const [whole,frac='']=txt.split('.');
      const value=Number(whole)*100+Number(frac.padEnd(2,'0'));
      if(!Number.isSafeInteger(value))throw new Error('Invalid property amount.');
      return value;
    };
    const money=v=>Number(v/100).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
    const lineItems=items.map((item,i)=>{
      const tag=String(item.property_tag||'').trim(),name=String(item.item_name||'').trim();
      if(!tag||!name||seen.has(tag))throw new Error('Duplicate or invalid permanent property number.');
      seen.add(tag);
      const price=cents(item.unit_cost);total+=price;
      if(!Number.isSafeInteger(total))throw new Error('Invalid report amount.');
      const code=String(item.uacs_code||'').trim();
      if(code && !/^\d{10}$/.test(code))throw new Error('Unexpected UACS code in report data.');
      // The title is descriptive reference data, never a financial posting.
      const accountTitle=code?String(item.uacs_account_title||'').trim():'';
      return {number:i+1,tag,name,price,unit:String(item.unit||'unit'),code,accountTitle};
    });
    const reportDate=String(input.report_date||'').slice(0,10);
    const match=/^(\d{4})-(\d{2})-(\d{2})$/.exec(reportDate);
    const date=match && !Number.isNaN(Date.parse(reportDate+'T00:00:00Z')) &&
      new Date(reportDate+'T00:00:00Z').toISOString().slice(0,10)===reportDate
        ?match[2]+'/'+match[3]+'/'+match[1]:'';
    // A finalized ICS is NOT itself a RIS. Only show a real linked RIS number.
    const risNo=String(input.ris_number||'').trim();
    const sourceNotes='This is a read-only report preview. Serial number, supply fund and UACS values are not fabricated; printing does not create an accounting entry.';
    const itemCell=r=>'<div class="semi-item-name">'+e(r.name)+'</div><div class="semi-tag">'+e(r.tag)+'</div>';
    const uacsCell=r=>'<div class="semi-uacs-code">'+e(r.code)+'</div>'+
      (r.code&&r.accountTitle?'<div class="semi-uacs-title">'+e(r.accountTitle)+'</div>':'');
    const rows=lineItems.map(r=>'<tr class="semi-item-row"><td class="center">'+e(risNo)+'</td><td class="center">'+e(input.rcc||'')+'</td><td class="center">'+r.number+'</td><td>'+itemCell(r)+'</td><td class="center">'+e(r.unit)+'</td><td class="center">1</td><td class="right">'+money(r.price)+'</td><td class="right">'+money(r.price)+'</td></tr>').join('');
    const recap=lineItems.map(r=>'<tr class="semi-item-row"><td class="center">'+e(risNo)+'</td><td class="center">'+r.number+'</td><td class="center">1</td><td>'+itemCell(r)+'</td><td class="center">'+e(r.unit)+'</td><td class="right">'+money(r.price)+'</td><td class="right">'+money(r.price)+'</td><td>'+uacsCell(r)+'</td></tr>').join('');
    // Keep a handful of writable paper rows while avoiding page overflow for long reports.
    const blanks=Array.from({length:Math.max(0,4-lineItems.length)},()=>
      '<tr class="semi-blank-row">'+Array.from({length:8},()=>'<td>&nbsp;</td>').join('')+'</tr>').join('');
    const cert=input.supply_custodian||{};
    const posted=input.posted_by||{};
    const postedName=String(posted.name||'').trim(),postedDesignation=String(posted.designation||'').trim();
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'+
      '<title>Report of Semi-Expendable Property Issued · ICS '+e(icsNumber)+'</title>'+ 
      '<link rel="stylesheet" href="'+semiPrintHref()+'"></head><body class="semi-report">'+
      '<div class="semi-toolbar"><button id="print-button" type="button">Print report</button><span>'+e(sourceNotes+(postedName?'':' No effective Division MOOE in-charge assignment is recorded for this school on the report date; check Division MOOE Assignments.'))+'</span></div>'+ 
      '<main class="semi-page"><div class="semi-appendix">Appendix 64</div>'+ 
      '<h1>REPORT OF SEMI-EXPENDABLE PROPERTY ISSUED</h1>'+  
      '<div class="semi-identity"><div>Entity Name: <strong>'+e(input.organization_name)+'</strong></div>'+ 
      '<div>Serial No.: <strong>________________</strong></div>'+ 
      '<div>Fund Cluster: <strong>'+e(input.fund_cluster||'')+'</strong></div>'+ 
      '<div>Date: <strong>'+e(date)+'</strong></div></div>'+ 
      '<table class="semi-table semi-issued-table"><colgroup>'+ 
      '<col class="col-ris"><col class="col-rcc"><col class="col-stock"><col class="col-item">'+ 
      '<col class="col-unit"><col class="col-qty"><col class="col-unit-cost"><col class="col-amount"></colgroup>'+ 
      '<thead><tr class="semi-instruction"><th colspan="6">To be filled up by the Supply and/or Property Division/Unit</th>'+ 
      '<th colspan="2">To be filled up by the Accounting Division/Unit</th></tr>'+ 
      '<tr><th>RIS No.</th><th>Responsibility Center Code</th><th>Stock No.</th><th>Item</th><th>Unit</th>'+ 
      '<th>Quantity Issued</th><th>Unit Cost</th><th>Amount</th></tr></thead><tbody>'+rows+blanks+ 
      '<tr class="semi-total-row"><td colspan="7">TOTAL</td><td class="right">'+money(total)+'</td></tr></tbody></table>'+ 
      '<table class="semi-table semi-recap-table"><colgroup>'+ 
      '<col class="col-recap-ris"><col class="col-recap-stock"><col class="col-recap-qty"><col class="col-recap-item">'+ 
      '<col class="col-recap-unit"><col class="col-recap-unitcost"><col class="col-recap-totalcost"><col class="col-recap-uacs"></colgroup>'+ 
      '<thead><tr class="semi-instruction"><th colspan="3">Recapitulation:</th><th colspan="5">Recapitulation:</th></tr>'+ 
      '<tr><th>RIS No.</th><th>Stock No.</th><th>Quantity</th><th>Item</th><th>Unit</th>'+ 
      '<th>Unit Cost</th><th>Total Cost</th><th>UACS Object Code</th></tr></thead>'+ 
      '<tbody>'+recap+blanks+ 
      '<tr class="semi-total-row"><td colspan="6">TOTAL</td><td class="right">'+money(total)+'</td><td></td></tr></tbody></table>'+ 
      '<div class="semi-signatures"><div class="semi-certified">'+ 
      '<div>I hereby certify to the correctness of the above information.</div>'+ 
      '<div class="semi-sign-name">'+e(cert.name||'')+'</div>'+ 
      '<div class="semi-sign-caption">Signature over Printed Name of Supply and/or Property Custodian</div></div>'+ 
      '<div class="semi-posted"><div>Posted by:</div>'+ 
      '<div class="semi-post-signature">____________________________</div>'+ 
      '<div class="semi-posted-name">'+(postedName?e(postedName):'&nbsp;')+'</div>'+ 
      '<div class="semi-posted-designation">'+(postedDesignation?e(postedDesignation):'&nbsp;')+'</div>'+ 
      '<div class="semi-sign-caption">Division MOOE In-Charge / Designated Accounting Staff</div>'+ 
      '<div class="semi-sign-date">Date: ____________</div></div></div>'+ 
      '</main></body></html>';
  }
  function show(input,target){
    const page=target||global.open('about:blank','_blank');
    if(!page)throw new Error('Your browser blocked the print window. Allow popups for IPDAMS and retry.');
    page.document.open();page.document.write(input?.print_mode==='SEMI_REPORT'?renderSemiIssued(input):render(input));page.document.close();
    const btn=page.document.getElementById('print-button');
    if(btn)btn.addEventListener('click',()=>page.print());
    page.focus();return page;
  }
  global.IPDAMSCombinedICSPrint=Object.freeze({render,renderSemiIssued,show});
})(typeof window==='undefined'?globalThis:window);
