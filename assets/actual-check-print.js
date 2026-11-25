/* IPDAMS v0.2.448 — DV-dated check preview, quick printing and shared alignment.
 * Every actual printing attempt records CHECK_PRINT_PREPARED, never a payment.
 * No automatic signing, release, clearing, balance change or new check serial.
 * Both DV row and Cash & Bank use this exact component and backend route.
 */
(function(){
'use strict';
const h=React.createElement;
const digitsDate=value=>/^\d{4}-\d{2}-\d{2}$/.test(value)?[value.slice(5,7),value.slice(8,10),value.slice(0,4)]:['','',''];
class ActualCheckPrinter extends React.Component {
    constructor(props){
        super(props);
        this.state={loading:true,data:null,error:'',notice:'',busy:false,
            checkDate:'',alignMode:false,testMode:false};
        this.openSource=null;
        this.load=this.load.bind(this);this.print=this.print.bind(this);this.testPrint=this.testPrint.bind(this);
        this.clearTestMode=this.clearTestMode.bind(this);this.close=this.close.bind(this);
    }
    componentDidMount(){this.load();}
    componentDidUpdate(prev){if(prev.organizationId!==this.props.organizationId||
        prev.target?.check_id!==this.props.target?.check_id||prev.target?.dv_id!==this.props.target?.dv_id)this.load();}
    componentWillUnmount(){this.unsetPageSize();window.removeEventListener('afterprint',this.clearTestMode);}
    setPageSize(profile){
        this.unsetPageSize();
        const style=document.createElement('style');style.id='ipdams-0445-actual-check-size';
        style.textContent='@media print{@page{size:'+Number(profile.width_mm)+'mm '+Number(profile.height_mm)+'mm;margin:0}}';
        document.head.appendChild(style);
    }
    unsetPageSize(){document.getElementById('ipdams-0445-actual-check-size')?.remove();}
    async load(){
        const org=Number(this.props.organizationId),target=this.props.target||{};
        this.setState({loading:true,data:null,error:'',testMode:false,notice:'',checkDate:'',alignMode:false});
        try {
            const query={organization_id:org};
            if(target.check_id)query.check_id=Number(target.check_id);
            else query.dv_id=Number(target.dv_id);
            const data=await this.props.get('check.print.snapshot',query);
            if(Number(this.props.organizationId)!==org)return;
            this.setPageSize(data.layout.profile);
            this.setState({loading:false,data,checkDate:data.dv?.document_date||''});
        }catch(err){if(Number(this.props.organizationId)===org)this.setState({loading:false,error:err.message||'Check printing data could not load.'});}
    }
    close(){if(this.state.busy)return;this.unsetPageSize();this.clearTestMode();this.props.close?.();}
    fits(){
        for(const e of document.querySelectorAll('.actual-check-paper .actual-check-field')){
            if(e.scrollWidth>e.clientWidth+1 || e.scrollHeight>e.clientHeight+1)return e.getAttribute('data-label')||'a check field';
        }
        return '';
    }
    clearTestMode(){
        if(this.state.testMode)this.setState({testMode:false});
    }
    testPrint(){
        if(this.state.busy||!this.state.data?.ready)return;
        const overflow=this.fits();
        if(overflow){this.setState({error:'The '+overflow+' does not fit within the check layout.'});return;}
        // Test sheets remain visibly NON-NEGOTIABLE on paper and are never
        // recorded as actual check print preparations or payment events.
        window.addEventListener('afterprint',this.clearTestMode,{once:true});
        this.setState({testMode:true,error:'',notice:'Test copy: NOT NEGOTIABLE. No check status or ledger change.'},()=>window.print());
    }
    async print(){
        const {data,checkDate}=this.state;
        if(this.state.busy||!data?.ready)return;
        const serial=String(data.check.check_number||'');
        if(!/^\d{4}-\d{2}-\d{2}$/.test(checkDate)||checkDate!==data.dv.document_date){
            this.setState({error:'The check date must equal the finalized DV document date. Reload the check preview.'});return;
        }
        const overflow=this.fits();
        if(overflow){this.setState({error:'The '+overflow+' does not fit within the check layout. Adjust the approved alignment and verify on plain paper before printing.'});return;}
        this.setState({busy:true,error:'',notice:''});
        try {
            const prepared=await this.props.task('check.print.prepare',{
                organization_id:Number(this.props.organizationId),check_id:Number(data.check.id),
                layout_version:Number(data.layout.version_id),check_date:checkDate,
                physical_serial:serial
            });
            if(!prepared?.prepared)throw new Error('The server did not authorize this check print preparation.');
            // Only after the authorization event completes: the on-screen preview
            // watermark is excluded from the printing CSS; no signature is printed.
            const updated={...data,print_count:Number(prepared.print_count||Number(data.print_count||0)+1),
                already_prepared:true,last_print_date:checkDate};
            this.setState({busy:false,data:updated,testMode:false,
                notice:'Print attempt '+updated.print_count+' recorded. Print again if needed; check status and financial records remain unchanged.'},()=>window.print());
        }catch(err){this.setState({busy:false,error:err.message||'Check print preparation was not authorized.'});}
    }
    paper(){
        const {data,checkDate}=this.state;if(!data?.layout?.profile)return null;
        const p=data.layout.profile,pos=p.positions,check=data.check;
        const amount=window.IpdamsCheckText?.parseCheckAmount(check.amount);
        const words=window.IpdamsCheckText?.amountWords(check.amount)||'AMOUNT UNAVAILABLE';
        const date=digitsDate(checkDate);
        const values={date_month:date[0],date_day:date[1],date_year:date[2],payee:check.payee_name,
            numeric:amount?'₱'+amount.display:'AMOUNT UNAVAILABLE',words};
        const styleFor=k=>{
            const x=Number(pos[k].x),y=Number(pos[k].y);
            let width=Math.max(3,Number(p.width_mm)-x-3);
            if(k==='payee')width=Math.min(width,Math.max(3,Number(pos.numeric.x)-x-4));
            if(k.startsWith('date_'))width=Math.min(width,k==='date_year'?21:12);
            return {left:x+'mm',top:y+'mm',maxWidth:width+'mm',width:width+'mm',fontSize:(k==='words'?Math.max(8,Number(p.font_pt)-1):Number(p.font_pt))+'pt'};
        };
        return h('div',{className:'actual-check-scroll'},
            h('div',{className:'actual-check-paper'+(this.state.testMode?' actual-check-test-mode':''),role:'img',
                'aria-label':'Check stationery layout preview. No signature is printed.',
                style:{width:p.width_mm+'mm',height:p.height_mm+'mm'}},
                h('div',{className:'actual-check-screen-watermark','aria-hidden':'true'},this.state.testMode?'TEST — NOT NEGOTIABLE':'PREVIEW ONLY'),
                ...Object.entries(values).map(([k,v])=>h('div',{key:k,'data-label':k.replace(/_/g,' '),
                    className:'actual-check-field actual-check-field-'+k,style:styleFor(k)},String(v))),
                h('div',{className:'actual-check-signature-guide','aria-hidden':'true',style:{left:pos.signatory.x+'mm',top:pos.signatory.y+'mm'}},'MANUAL SIGNATURE AREA')));
    }
    render(){
        const s=this.state,d=s.data,c=d?.check;
        const stages=['ISSUED','SIGNED','RELEASED','CLEARED'];
        const currentStage=stages.indexOf(c?.status);
        const nextStage=({
            ISSUED:'Printing may be repeated. Record physical signing separately after the authorized signatories sign.',
            SIGNED:'The check is signed. Reprinting does not change its signing record. Release must be recorded separately.',
            RELEASED:'The release record remains intact. Reprinting cannot issue another payment or change its status.',
            CLEARED:'The bank clearing record remains intact. Copies are printing attempts, not new disbursements.'
        })[c?.status]||'This check status is not eligible for negotiable printing.';
        return h('div',{className:'actual-check-modal-backdrop',onMouseDown:e=>{if(e.target===e.currentTarget)this.close();}},
            h('section',{className:'actual-check-modal',role:'dialog','aria-modal':'true',
                'aria-label':'Print actual check on approved stationery'},
                h('header',{className:'actual-check-modal-head'},
                    h('div',null,h('h2',null,'Print Check'),
                        h('p',null,'Check preview · saved DV date · shared printer alignment')),
                    h('button',{type:'button',className:'outline','aria-label':'Close actual check print',disabled:s.busy,onClick:this.close},'×')),
                h('div',{className:'actual-check-modal-body'},
                    s.loading?h('p',{role:'status'},'Checking live check status, physical stock and alignment…'):null,
                    s.error?h('p',{className:'notice error',role:'alert'},s.error):null,
                    d?h('div',{className:'actual-check-summary'},
                        h('strong',null,'Check '+c.check_number+' · '+c.status),
                        h('span',null,'DV '+d.dv.display_number+' · '+d.bank.bank_name+' / '+d.bank.account_label),
                        h('span',null,'Payee: '+c.payee_name),
                        h('span',null,'Saved amount: ₱'+(window.IpdamsCheckText?.parseCheckAmount(c.amount)?.display||'NOT VERIFIED')),
                        h('span',null,'Check date (from DV): '+(d.dv.document_date||'UNAVAILABLE')),
                        h('span',null,'Printer layout: '+(d.layout.version_id?'saved version '+d.layout.version_id:'not configured'))):null,
                    d?h('div',{className:'actual-check-lifecycle',role:'group','aria-label':'Check lifecycle stages'},
                        ...stages.map((stage,index)=>h('span',{key:stage,
                            className:'actual-check-stage '+(currentStage>=index?'is-complete ':'')+
                                (currentStage===index?'is-current':''),
                            'aria-current':currentStage===index?'step':undefined},stage)),
                        h('p',{className:'actual-check-next',role:'status'},nextStage),
                        h('p',{className:'hint'},'Recorded actual print preparations: '+Number(d.print_count||0)+
                            '. Repeat prints are allowed. Printing never signs, releases or clears a check.')):null,

                    d&&d.ready&&c.status!=='ISSUED'?h('p',{className:'notice',role:'status'},
                        'This check is already '+c.status+'. Reprinting creates another physical copy of the same instrument; account for originals, copies and spoiled sheets. No additional payment is authorized.'):null,
                    d&&!d.ready?h('div',{role:'alert',className:'notice error'},
                        h('strong',null,'Printing unavailable'),
                        h('ul',null,...(d.warnings||[]).map((w,i)=>h('li',{key:i},w)))):null,
                    d&&d.ready&&s.alignMode?h('div',{className:'actual-check-align-workspace'},
                        h('div',{className:'actual-check-align-bar'},
                            h('strong',null,'Adjust alignment for the issuing office'),
                            h('button',{type:'button',className:'outline',disabled:s.busy,
                                onClick:()=>this.load()},'Return to check preview')),
                        window.IpdamsCheckProof?h(window.IpdamsCheckProof,{
                            organizationId:Number(this.props.organizationId),
                            dv:d.dv,checkNo:c.check_number,payee:c.payee_name,amount:c.amount,
                            get:this.props.get,task:this.props.task,startEditor:true,
                            close:()=>this.load()
                        }):h('p',{role:'alert'},'Alignment editor is unavailable. Reload IPDAMS.')):null,
                    d&&d.ready&&!s.alignMode?h('div',{className:'actual-check-preview-workspace'},
                        h('div',{className:'actual-check-print-toolbar'},
                            h('button',{type:'button',className:'primary',disabled:s.busy,onClick:this.print},
                                s.busy?'Preparing print…':Number(d.print_count||0)?'Print / Reprint Check':'Print Check'),
                            h('button',{type:'button',className:'outline',disabled:s.busy,onClick:this.testPrint},
                                'Test Print — Not Negotiable'),
                            h('button',{type:'button',className:'outline',disabled:s.busy,
                                onClick:()=>this.setState({alignMode:true,testMode:false,error:'',notice:''})},
                                'Adjust Alignment'),
                            h('button',{type:'button',className:'outline',disabled:s.busy,onClick:this.close},'Close')),
                        h('p',{className:'hint'},'Preview uses the finalized DV date and saved check payee, amount, and serial. Test copies are watermarked; printing never signs, releases, clears, or posts payment.'),
                        s.notice?h('p',{className:'notice success',role:'status'},s.notice):null,
                        h('div',{className:'actual-check-preview-wrap'},this.paper())):null,
                    !d?.ready?h('div',{className:'actual-check-actions'},
                        h('button',{type:'button',className:'outline',onClick:()=>this.load(),disabled:s.loading},'Recheck status'),
                        h('button',{type:'button',className:'outline',onClick:this.close},'Close')):null)));
    }
}
window.IpdamsActualCheckPrinter=ActualCheckPrinter;
})();
