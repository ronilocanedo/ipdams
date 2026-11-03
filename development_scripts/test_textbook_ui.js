const fs=require('fs');const assert=require('assert');const path=require('path');
const root=path.join(__dirname,'..');
const script=fs.readFileSync(path.join(root,'public/assets/app.js'),'utf8');
const html=fs.readFileSync(path.join(root,'public/app.html'),'utf8');
const first=script.indexOf('        this.textbookView = () => {');
const last=script.indexOf('        // Non-posting travel reimbursement documentary intake and review.',first);
assert(first>0&&last>first);
const segment=script.slice(first,last);
const React={createElement:(name,props,...children)=>({name,props:props||{},children})};
const calls=[];
const app={state:{tbBatches:[{id:1,title:'Science 5',isbn:'0-13-235088-2',grade:'5',subject:'Science',batch:'A',storage:'Library',on_hand:'5'}],
 tbDirectory:{rows:[{id:1,title:'Science 5',canonical_isbn:'9780132350884',isbn:'0-13-235088-2',grade:'5',subject:'Science',review:'DUPLICATE',source_rows:2,source_ids:'1, 2'}],
 summary:{scanned_title_rows:2,coverage_complete:true,duplicate_isbn_groups:1,conflicting_isbn_groups:0,invalid_isbn_rows:0,without_isbn_rows:0},matching_groups:1,page:1,next_page:null},
 tbDirectoryQuery:'',tbDirectoryLoading:false,tbDirectoryError:'',tbLoading:false,tbHistoryLoading:false,tbHistory:[],tbHistoryError:'',tbHistoryMonth:'',tbHistoryBatch:'',tbHistoryHasMore:false},
 header:(...args)=>({name:'header',children:args}),table:(...args)=>({name:'table',children:args}),
 loadTextbookDirectory:(...args)=>calls.push(['directory',...args]),loadTextbookBatches:()=>{},loadTextbookHistory:()=>{},setState:()=>{}
};
new Function('React',segment).call(app,React);
const view=app.textbookView();
const txt=[];let forms=0;const walk=(x)=>{
 if(x===null||x===undefined)return;
 if(typeof x==='string'||typeof x==='number'){txt.push(String(x));return;}
 if(Array.isArray(x)){x.forEach(walk);return;}
 if(x.name==='form')forms++;
 (x.children||[]).forEach(walk);
};walk(view);
let line=txt.join(' | ');
assert(line.includes('Book Directory · shared ISBN catalog'));
assert(line.includes('Legacy title rows scanned: 2'));
assert(line.includes('Legacy direct opening, transfer, loss, disposal'));
assert(line.includes('Original textbook movement history'));
assert(!line.includes('Record opening textbook batch'));
assert(!line.includes('Record textbook movement'));
assert(forms===1,`Expected only directory GET form, got ${forms}`);
assert(html.includes('assets/app.js?v=0271-textbook-directory'));
assert(!html.includes('assets/app.js?v=0270-item-tag-stack'));
console.log('PASS React textbookView smoke: shared directory, legacy audit, balances, movement history, posting form removed, cache refreshed');
