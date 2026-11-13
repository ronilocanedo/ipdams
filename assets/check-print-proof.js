/* IPDAMS v0.2.173: school-scoped check stationery ALIGNMENT PROOF only.
 * This component never prints a negotiable check or issues a check number.
 */
(function () {
'use strict';
const h=React.createElement;
const fields=[['date_month','Date month'],['date_day','Date day'],['date_year','Date year'],['payee','Payee'],['numeric','Numeric amount'],['words','Amount in words'],['signatory','Printed signatory']];
const defaults=()=>({width_mm:205,height_mm:82,font_pt:10,positions:{date_month:{x:143,y:11},date_day:{x:155,y:11},date_year:{x:169,y:11},payee:{x:22,y:32},numeric:{x:158,y:31},words:{x:18,y:48},signatory:{x:142,y:65}}});
const digits=(value)=>Number(value).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
function amountWords(value){
    if(!/^[0-9]+(?:\.[0-9]{1,2})?$/.test(String(value).trim()))return 'AMOUNT REQUIRES VERIFICATION';
    const num=Number(value);
    if(!Number.isSafeInteger(Math.floor(num))||num<0||num>999999999.99)return 'AMOUNT REQUIRES VERIFICATION';
    const ones=['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen','Seventeen','Eighteen','Nineteen'];
    const tens=['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
    const small=n=>n<20?ones[n]:(tens[Math.floor(n/10)]+(n%10?' '+ones[n%10]:''));
    const hundreds=n=>(n>=100?ones[Math.floor(n/100)]+' Hundred'+(n%100?' ':''):'')+(n%100?small(n%100):'');
    const integer=Math.floor(num),cent=Math.round((num-integer)*100);
    const a=[Math.floor(integer/1000000),Math.floor(integer/1000)%1000,integer%1000];
    const s=a.map((v,i)=>v?hundreds(v)+(i===0?' Million':i===1?' Thousand':''):'').filter(Boolean).join(' ')||'Zero';
    return s+' Pesos and '+String(cent).padStart(2,'0')+'/100 Only';
}
class CheckProof extends React.Component {
    constructor(props){
        super(props);
        this.state={profile:defaults(),version:0,loadedFor:null,loading:true,editor:!!props.startEditor,selectedField:'payee',error:'',notice:'',saving:false};
        this.load=this.load.bind(this);
        this.save=this.save.bind(this);
    }
    componentDidMount(){this.load();}
    componentDidUpdate(prev){if(prev.organizationId!==this.props.organizationId){this.stopDrag();this.load();}}
    componentWillUnmount(){this.stopDrag();}
    stopDrag(){
        document.removeEventListener('mousemove',this.dragMove);
        document.removeEventListener('mouseup',this.dragEnd);
        document.removeEventListener('touchmove',this.dragMove);
        document.removeEventListener('touchend',this.dragEnd);
        document.removeEventListener('touchcancel',this.dragEnd);
        this.dragging=null;
    }
    beginDrag(field,event){
        if(!this.state.editor||this.state.saving)return;
        if(event.type==='mousedown'&&event.button!==0)return;
        const point=event.touches?.[0]||event;
        const paper=event.currentTarget.closest('.check-proof-paper');
        const rect=paper?.getBoundingClientRect();
        if(!rect?.width||!rect?.height)return;
        const original=this.state.profile.positions[field];
        this.dragging={field,x:point.clientX,y:point.clientY,
            px:Number(original.x),py:Number(original.y),
            xScale:Number(this.state.profile.width_mm)/rect.width,
            yScale:Number(this.state.profile.height_mm)/rect.height};
        this.setState({selectedField:field});
        this.dragMove=this.dragMove||((e)=>{
            if(!this.dragging)return;
            if(e.cancelable)e.preventDefault();
            const pt=e.touches?.[0]||e, d=this.dragging, profile=this.state.profile;
            const maxX=Number(profile.width_mm)-3,maxY=Number(profile.height_mm)-3;
            const quant=v=>Math.round(v*2)/2;
            const x=quant(Math.max(0,Math.min(maxX,d.px+(pt.clientX-d.x)*d.xScale)));
            const y=quant(Math.max(0,Math.min(maxY,d.py+(pt.clientY-d.y)*d.yScale)));
            this.setState(old=>({profile:{...old.profile,positions:{...old.profile.positions,
                [d.field]:{x,y}}},notice:'',error:''}));
        });
        this.dragEnd=this.dragEnd||(()=>this.stopDrag());
        document.addEventListener('mousemove',this.dragMove);
        document.addEventListener('mouseup',this.dragEnd);
        document.addEventListener('touchmove',this.dragMove,{passive:false});
        document.addEventListener('touchend',this.dragEnd);
        document.addEventListener('touchcancel',this.dragEnd);
        if(event.cancelable)event.preventDefault();
    }
    nudge(field,axis,delta){
        const profile=this.state.profile;
        const max=(axis==='x'?Number(profile.width_mm):Number(profile.height_mm))-3;
        const at=Number(profile.positions[field][axis]);
        this.shift(field,axis,Math.round(Math.max(0,Math.min(max,at+delta))*2)/2);
    }
    moveAll(axis,delta){
        this.setState(old=>{
            const pos={};
            Object.entries(old.profile.positions).forEach(([field,at])=>{
                const max=(axis==='x'?Number(old.profile.width_mm):Number(old.profile.height_mm))-3;
                pos[field]={...at,[axis]:Math.round(Math.max(0,Math.min(max,Number(at[axis])+delta))*2)/2};
            });
            return {profile:{...old.profile,positions:pos},notice:'',error:''};
        });
    }
    async load(){
        const org=Number(this.props.organizationId);
        if(!org)return;
        this.setState({loading:true,error:'',loadedFor:null});
        try{
            const data=await this.props.get('check.print.profile',{organization_id:org});
            if(Number(this.props.organizationId)!==org)return;
            this.setState({profile:data.profile||defaults(),version:data.version_id||0,loadedFor:org,loading:false});
        }catch(error){if(Number(this.props.organizationId)===org)this.setState({error:'School check layout unavailable: '+error.message,loading:false});}
    }
    change(key,value){
        this.setState(s=>({profile:{...s.profile,[key]:value},notice:'',error:''}));
    }
    shift(field,axis,value){
        this.setState(s=>({profile:{...s.profile,positions:{...s.profile.positions,[field]:{...s.profile.positions[field],[axis]:value}}},notice:'',error:''}));
    }
    async save(){
        const org=Number(this.props.organizationId);
        if(org!==this.state.loadedFor||this.state.saving)return;
        this.setState({saving:true,error:'',notice:''});
        try{
            const r=await this.props.task('check.print.profile.save',{organization_id:org,expected_version:this.state.version,profile:this.state.profile});
            if(!r){this.setState({error:'Save failed. See the application error message.'});return;}
            if(Number(this.props.organizationId)===org)this.setState({version:r.version_id,profile:r.profile,editor:false,notice:'School printer alignment settings saved; no check amount, payee, or number was changed.'});
        }catch(error){if(Number(this.props.organizationId)===org)this.setState({error:error.message});}
        finally{this.setState({saving:false});}
    }
    proof(){
        const {profile}=this.state;
        const p=profile.positions;
        const {dv,checkNo,payee,amount}=this.props;
        const rawDate=/^\d{4}-\d{2}-\d{2}$/.test(String(dv?.document_date||''))?dv.document_date:'';
        const f={date_month:rawDate?rawDate.slice(5,7):'MM',date_day:rawDate?rawDate.slice(8,10):'DD',date_year:rawDate?rawDate.slice(0,4):'YYYY',
            payee:payee||'PAYEE PENDING',numeric:Number.isFinite(Number(amount))?'₱'+digits(amount):'AMOUNT PENDING',words:amountWords(amount||''),signatory:'AUTHORIZED SIGNATORY — VERIFY SCHOOL APPOINTMENT'};
        return h('div',{className:'check-proof-overflow'},
            h('div',{className:'check-proof-paper',style:{width:profile.width_mm+'mm',height:profile.height_mm+'mm',fontSize:profile.font_pt+'pt'},
                role:'img','aria-label':'Non-negotiable alignment proof with date, payee and amount'},
                h('div',{className:'check-proof-watermark'},'TEST · NOT NEGOTIABLE'),
                h('span',{className:'check-proof-header'},'ALIGNMENT PROOF — NOT A CHECK · SERIAL '+(checkNo||'NOT ISSUED')),
                ...fields.map(([key])=>h('div',{key,className:'check-proof-field check-proof-field-'+key,
                    style:{left:p[key].x+'mm',top:p[key].y+'mm'},
                    tabIndex:this.state.editor?0:undefined,
                    role:this.state.editor?'button':undefined,
                    'aria-label':this.state.editor?'Move '+fields.find(x=>x[0]===key)[1]+' using the arrow keys or drag':undefined,
                    'aria-pressed':this.state.editor?this.state.selectedField===key:undefined,
                    onMouseDown:e=>this.beginDrag(key,e),
                    onTouchStart:e=>this.beginDrag(key,e),
                    onKeyDown:e=>{
                        if(!this.state.editor)return;
                        const moves={ArrowLeft:['x',-1],ArrowRight:['x',1],ArrowUp:['y',-1],ArrowDown:['y',1]};
                        if(moves[e.key]){e.preventDefault();this.setState({selectedField:key});
                            this.nudge(key,moves[e.key][0],moves[e.key][1]*(e.shiftKey?2.5:0.5));}
                    }},String(f[key])))));
    }
    render(){
        const {dv,mode,close}=this.props;
        const {profile}=this.state;
        const unavailable=!this.state.loadedFor;
        const editable=!unavailable&&!this.state.loading;
        return h('section',{className:'surface check-proof-container','aria-label':'Check alignment preview'},
            h('div',{className:'check-proof-head'},
                h('div',null,h('h3',null,mode==='ISSUED'?'Issued check — printing proof':'Check preview and school printer alignment'),
                    h('p',{className:'hint'},'View the selected Business or Proprietor payee. This proof cannot modify the issued check or DV and is never a negotiable bank check.')),
                close?h('button',{type:'button',className:'outline',onClick:close},'Close check proof'):null),
            this.state.loading?h('p',{className:'hint'},'Loading your school’s saved printer layout…'):null,
            this.state.error?h('p',{className:'error',role:'alert'},this.state.error):null,
            this.proof(),
            h('p',{className:'hint'},'School-specific layout: '+(unavailable?'not loaded':this.state.version?'saved version '+this.state.version:'default (unsaved)')+'. The date is from the finalized DV. Signatory is a placeholder until its current registered appointment is verified.'),
            h('div',{className:'check-proof-actions'},
                h('button',{type:'button',className:'outline',disabled:!editable,onClick:()=>this.setState(s=>({editor:!s.editor}))},this.state.editor?'Hide alignment settings':'Adjust check positions'),
                h('button',{type:'button',className:'outline',disabled:!editable,onClick:()=>window.print()},'Print TEST alignment sheet')),
            this.state.notice?h('p',{role:'status',className:'success'},this.state.notice):null,
            this.state.editor?h('div',{className:'check-proof-settings'},
                h('h4',null,'Visual check alignment — drag the fields above'),
                h('p',{className:'hint'},'Drag a printed field on the TEST preview above, or select it and use the arrow keys (0.5 mm, Shift = 2.5 mm). Mouse and touch supported. Save separately for this school. Amounts, check numbers, dates and payees are read-only.'),
                h('label',{className:'field'},h('span',null,'Selected field'),
                    h('select',{value:this.state.selectedField,onChange:e=>this.setState({selectedField:e.target.value})},
                        ...fields.map(([id,label])=>h('option',{key:id,value:id},label)))),
                h('div',{className:'check-proof-nudge','aria-label':'Fine tune selected field'},
                    ...[['←','x',-0.5],['→','x',0.5],['↑','y',-0.5],['↓','y',0.5]].map(([label,axis,delta])=>
                        h('button',{key:label,type:'button',className:'outline',
                            onClick:()=>this.nudge(this.state.selectedField,axis,delta)},label+' 0.5 mm'))),
                h('div',{className:'check-proof-nudge','aria-label':'Move all fields'},
                    h('strong',null,'Printer shifted? Move every field:'),
                    ...[['←','x',-0.5],['→','x',0.5],['↑','y',-0.5],['↓','y',0.5]].map(([label,axis,delta])=>
                        h('button',{key:label,type:'button',className:'outline',onClick:()=>this.moveAll(axis,delta)},label+' 0.5 mm'))),
                h('div',{className:'check-proof-settings-grid'},...[
                    ['width_mm','Paper width (mm)',140,255],['height_mm','Paper height (mm)',65,115],['font_pt','Text size (pt)',8,14]
                ].map(([key,label,min,max])=>h('label',{className:'field',key},h('span',null,label),
                    h('input',{type:'number',min,max,step:key==='font_pt'?1:0.5,value:profile[key],onChange:e=>this.change(key,e.target.value)})))),
                h('details',{className:'check-proof-advanced'},h('summary',null,'Advanced: exact coordinates (mm)'),...fields.map(([key,label])=>h('div',{className:'check-proof-offset-row',key},
                    h('strong',null,label),...['x','y'].map(axis=>h('label',{className:'field',key:axis},h('span',null,axis.toUpperCase()+' (mm)'),
                        h('input',{type:'number',min:0,max:axis==='x'?profile.width_mm:profile.height_mm,step:0.5,
                            value:profile.positions[key][axis],onChange:e=>this.shift(key,axis,e.target.value)})))))),
                h('div',{className:'check-proof-actions'},
                    h('button',{type:'button',className:'primary',disabled:this.state.saving||unavailable,onClick:this.save},
                        this.state.saving?'Saving…':'Save layout for this school'),
                    h('button',{type:'button',className:'outline',onClick:()=>this.load(),disabled:this.state.saving},'Discard unsaved changes'))):null,
            h('p',{className:'check-proof-warning'},'TEST PRINT ONLY — NOT NEGOTIABLE. Official check printing and verified bank-cash sufficiency are not activated by this feature.'));
    }
}
window.IpdamsCheckProof=CheckProof;
})();
