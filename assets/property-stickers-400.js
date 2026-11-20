/* IPDAMS v0.2.383 — read-only bulk property sticker printing */
'use strict';
(() => {
 const $=id=>document.getElementById(id), org=document.body.dataset.org, code=document.body.dataset.orgcode;
 const selected=new Map();let all=[],cursor=null,hasMore=false,busy=false,csrf='',template={};
 const referenceBackground='assets/deped-property-sticker-background-400.jpg';
 const status=(s,error=false)=>{$('message').textContent=s;$('message').className=error?'error':'hint';};
 const apiUrl=(mode,extra='')=>'api/property-stickers.php?organization_id='+encodeURIComponent(org)+'&mode='+mode+extra;
 const clear=(node)=>node.replaceChildren();
 const str=v=>String(v??'').trim();
 async function request(url,options={}) { const res=await fetch(url,{credentials:'same-origin',...options});
  const val=await res.json();if(!res.ok)throw Error(val.error||'Sticker data unavailable');return val.data; }
 function updateCount(){$('count').textContent=selected.size+' selected (maximum 90)';}
 function assetRow(a){const label=document.createElement('label');label.className='asset-row';
  const input=document.createElement('input');input.type='checkbox';input.checked=selected.has(String(a.id));
  input.addEventListener('change',()=>{if(input.checked){if(selected.size>=90){input.checked=false;status('Select at most 90 stickers per print job.',true);return;} selected.set(String(a.id),a);}else selected.delete(String(a.id));updateCount();$('print').disabled=true;});
  const span=document.createElement('span');span.textContent=str(a.property_tag)+' · '+str(a.item_name)+' · '+str(a.custodian||'Unassigned');
  label.append(input,span);return label;
 }
 function drawList(){const node=$('assets');clear(node);for(const a of all)node.append(assetRow(a));$('more').hidden=!hasMore;updateCount();}
 async function load(reset=true){if(busy)return;busy=true;status('Loading property records…');$('load').disabled=true;
  try {const search=$('search').value.trim();const params='&search='+encodeURIComponent(search)+(reset?'':(cursor?'&before_id='+encodeURIComponent(cursor):''));
    const data=await request(apiUrl('data',params));
    template=data.template; $('background-uploaded').disabled=!template.has_image; $('source').textContent=template.origin==='DIVISION_FALLBACK'?'Using Division Office sticker template':
        (template.has_image?'Using this office’s uploaded sticker template':'Using default printable sticker layout');
    $('template-form').hidden=!template.can_manage;
    $('reset-template').hidden=!(template.can_manage&&template.has_own_template);
    all=reset?data.page.rows:all.concat(data.page.rows);cursor=data.page.next_before_id;
    hasMore=data.page.has_more;drawList();status(all.length+' property records displayed. Select stickers to preview.');
  }catch(e){status(e.message,true);}finally{busy=false;$('load').disabled=false;}
 }
 function line(parent,label,value){
  const div=document.createElement('div');div.className='line';
  const title=document.createElement('span');title.className='label';title.textContent=label+':';
  const data=document.createElement('span');data.className='line-value';data.textContent=str(value)||'—';
  const length=data.textContent.length;
  if(length>33)data.classList.add('value-long');
  if(length>52)data.classList.add('value-very-long');
  data.title=data.textContent;
  div.append(title,data);parent.append(div);
 }
 function qrSvg(payload){const matrix=window.IPDAMSStickerQR(payload),n=matrix.length,pad=4;
  const svg=document.createElementNS('http://www.w3.org/2000/svg','svg');
  svg.setAttribute('viewBox',`0 0 ${n+pad*2} ${n+pad*2}`);svg.setAttribute('role','img');svg.setAttribute('aria-label','Property number QR code');
  svg.setAttribute('shape-rendering','crispEdges');
  const bg=document.createElementNS(svg.namespaceURI,'rect');bg.setAttribute('width',n+pad*2);bg.setAttribute('height',n+pad*2);bg.setAttribute('fill','#fff');svg.append(bg);
  for(let y=0;y<n;y++)for(let x=0;x<n;x++)if(matrix[y][x]){
    const rect=document.createElementNS(svg.namespaceURI,'rect');rect.setAttribute('x',x+pad);rect.setAttribute('y',y+pad);
    rect.setAttribute('width','1');rect.setAttribute('height','1');rect.setAttribute('fill','#000');svg.append(rect);
  }return svg;
 }
 function sheet(){const node=document.createElement('section');node.className='sheet';return node;}
 function sticker(a){
  const card=document.createElement('div');card.className='sticker';
  const useOffice=$('background-choice').value==='uploaded'&&template.has_image;
  card.style.backgroundImage=`url("${useOffice?apiUrl('image','&v='+encodeURIComponent(template.image_sha256)):referenceBackground}")`;
  const school=document.createElement('div');school.className='sticker-school';school.textContent='School: '+(str(a.school)||'—');
  const contents=document.createElement('div');contents.className='sticker-content';
  line(contents,'Property No.',a.property_tag);
  line(contents,'PAR / ICS No.',a.accountability_number);
  line(contents,'Date Acquired',a.acquired_on);
  line(contents,'Property Description',a.item_name);
  line(contents,'Brand',a.brand);line(contents,'Model',a.model);
  line(contents,'Serial No.',a.serial_number);
  const raw=Number(a.acquisition_cost);
  line(contents,'Cost',str(a.acquisition_cost)!==''&&Number.isFinite(raw)?raw.toLocaleString('en-PH',{style:'currency',currency:'PHP'}):'—');
  line(contents,'Accountable Person',a.custodian);
  let extras=0;
  for(const i of [1,2]){
    const lab=$('extra'+i+'-label').value.trim(),value=$('extra'+i+'-value').value.trim();
    if(lab||value){line(contents,lab||'Additional '+i,value);extras++;}
  }
  if(extras)card.classList.add('sticker-extra-'+extras);
  const qr=document.createElement('div');qr.className='sticker-qr';
  qr.append(qrSvg('IPDAMS|'+code+'|'+str(a.property_tag)));
  const corner=document.createElement('div');corner.className='sticker-qr-note';corner.textContent='SCAN PROPERTY ID';
  card.append(school,contents,qr,corner);return card;
 }
 function preview(){const records=Array.from(selected.values());if(!records.length){status('Select at least one property.',true);return;}
  const output=$('sheets');clear(output);let page;
  try {records.forEach((a,i)=>{if(i%9===0){page=sheet();output.append(page);}page.append(sticker(a));});
    $('print').disabled=false;status(records.length+' stickers arranged into '+Math.ceil(records.length/9)+' legal-size sheet(s). Set printer scale to 100%, paper 8.5 × 13 in and margins None.');
  }catch(e){clear(output);$('print').disabled=true;status('QR/preview error: '+e.message,true);}
 }
 $('search').addEventListener('input',()=>{$('more').hidden=true;});
 $('load').addEventListener('click',()=>load(true));$('more').addEventListener('click',()=>load(false));
 $('search').addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();load(true);}});
 $('preview').addEventListener('click',preview);$('print').addEventListener('click',()=>window.print());
 $('background-choice').addEventListener('change',()=>{$('print').disabled=true;status('Background changed. Preview again before printing.');});
 for(const i of [1,2])for(const suffix of ['label','value'])$('extra'+i+'-'+suffix).addEventListener('input',()=>{$('print').disabled=true;});
 $('reset-template').addEventListener('click',async()=>{
    if(!window.confirm('Remove this office’s sticker template? A school will automatically use the Division template if available.'))return;
    try{template=await request(apiUrl('template.reset'),{method:'POST',headers:{'X-CSRF-Token':csrf}});
      $('reset-template').hidden=true; $('print').disabled=true;
      if(!template.has_image)$('background-choice').value='reference';$('background-uploaded').disabled=!template.has_image;
      $('source').textContent=template.origin==='DIVISION_FALLBACK'?'Using Division Office sticker template':'Using default printable sticker layout';
      status('Office template override removed. Preview again before printing.');
    }catch(e){status(e.message,true);}
 });
 $('template-form').addEventListener('submit',async e=>{e.preventDefault();const file=$('template').files[0];
  if(!file){status('Select a template image.',true);return;}if(file.size>5242880){status('Image exceeds 5 MB.',true);return;}
  const body=new FormData();body.append('template',file);
  try {template=await request(apiUrl('template.upload'),{method:'POST',headers:{'X-CSRF-Token':csrf},body});
   $('source').textContent='Office template saved successfully. Preview stickers before printing.';
   $('print').disabled=true;$('reset-template').hidden=false;$('background-uploaded').disabled=false;
   $('background-choice').value='uploaded';status('Template saved. Office/Division background selected. Preview before printing.');
  }catch(err){status(err.message,true);}
 });
 (async()=>{try {const session=await request('api/index.php?route=auth.session');
    if(!session.user)throw Error('Sign in first');csrf=session.csrf;await load(true);
  }catch(e){status(e.message,true);}})();
})();
