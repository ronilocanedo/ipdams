const semiSubcategories = [
    ['ICT_EQUIPMENT', 'ICT Equipment'],
    ['ICT_EQUIPMENT_DCP', 'ICT Equipment (DCP)'],
    ['ICT_EQUIPMENT_ADM', 'ICT Equipment (ADM)'],
    ['FURNITURE_FIXTURES', 'Furniture and Fixtures'],
    ['MEDICAL_DENTAL_EQUIPMENT', 'Medical Dental Equipment'],
    ['OFFICE_EQUIPMENT', 'Office Equipment'],
    ['OTHER_MACHINERIES_EQUIPMENT', 'Other Machineries and Equipment'],
    ['OTHER_SUPPLIES_MATERIALS', 'Other Supplies & Materials'],
    ['TLE_TVL_EQUIPMENT', 'TLE/TVL Equipment'],
    ['SPORTS_EQUIPMENT', 'Sports Equipment']
];

// Documentary explanation only. All rates, tax bases and withheld amounts come
// from the saved DV detail, never from editable Particulars text or inputs.
const dvCompactSavedWithholding = (h, r, components) => {
    const entries=Array.isArray(components)?components:[];
    const money=x=>Number(x||0).toLocaleString('en-PH',{style:'currency',currency:'PHP'});
    const hCell=(tag,txt,style={})=>h(tag,{style:{padding:'2px 5px',fontSize:'11px',lineHeight:'1.25',...style}},txt);
    const numeric={textAlign:'right',whiteSpace:'nowrap',fontVariantNumeric:'tabular-nums'};
    return h('div',{className:'dv-tax-compact','aria-label':'Automatically computed withholding taxes — read only',
        style:{marginTop:'6px',maxWidth:'590px',fontSize:'11px',lineHeight:'1.25'}},
        h('h4',{style:{margin:'4px 0 3px',fontSize:'11px',fontWeight:700}},'Less: Withholding Taxes · read-only'),
        entries.length?h('div',{style:{overflowX:'auto'}},
            h('table',{className:'dv-compact-tax-table',style:{borderCollapse:'collapse',width:'100%',fontSize:'11px'},'aria-label':'Saved VAT/EWT calculation'},
                h('thead',null,h('tr',null,
                    hCell('th','Tax / ATC',{textAlign:'left'}),hCell('th','Tax base',numeric),
                    hCell('th','Rate',numeric),hCell('th','Withheld',numeric))),
                h('tbody',null,...entries.map((t,i)=>h('tr',{key:t.id??i},
                    hCell('td',(t.tax_kind==='VAT'?'VAT':t.tax_kind==='EWT'?'EWT':'WTX')+' · '+String(t.atc_code||'—')),
                    hCell('td',money(t.tax_base),numeric),
                    hCell('td',t.rate===null||t.rate===undefined?'—':(Number(t.rate)*100).toFixed(2)+'%',numeric),
                    hCell('td',money(t.amount),numeric)))))):
            h('small',{style:{fontSize:'11px'}},'No withholding taxes recorded.'),
        h('div',{className:'dv-compact-tax-totals',style:{marginTop:'3px',display:'grid',gridTemplateColumns:'1fr auto',gap:'2px 10px',fontVariantNumeric:'tabular-nums'}},
            h('span',null,'Gross amount'),h('strong',{style:numeric},money(r.gross)),
            h('span',null,'Total withholding / Due to BIR'),h('strong',{style:numeric},money(r.deductions)),
            h('strong',null,'Net amount due'),h('strong',{style:numeric},money(r.net))),
        h('small',{style:{display:'block',fontSize:'10px',color:'#64748b',marginTop:'3px'}},
            'Saved tax components; not editable here. Printing does not record remittance.'));
};

const semiSubcategoryLabel = value => semiSubcategories.find(([code]) => code === value)?.[1] || '';
const supplierProfileTypes = ['GOODS','SERVICES','RENTAL','CONTRACTOR','UTILITIES','OTHER'];
const supplierProfileLabels = {GOODS:'Goods',SERVICES:'Services',RENTAL:'Rental',CONTRACTOR:'Contractor',UTILITIES:'Utilities',OTHER:'Other'};
const localToday = () => {
    const d=new Date(), pad=n=>String(n).padStart(2,'0');
    return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate());
};
const validPrDate = value => typeof value === 'string' && /^[0-9]{4}-[0-9]{2}-[0-9]{2}$/.test(value);
// Date-only, timezone-independent advisory for accountable documentary dates.
// Do not warn for staff appointments, bank deposit dates, policy effective dates,
// or planned delivery/travel dates: those are not document preparation dates.
const weekendDocumentDay = value => {
    if(typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value))return '';
    const [year,month,day]=value.split('-').map(Number);
    const parsed=new Date(Date.UTC(year,month-1,day,12));
    if(parsed.getUTCFullYear()!==year || parsed.getUTCMonth()!==month-1 || parsed.getUTCDate()!==day)return '';
    const weekday=parsed.getUTCDay();
    return weekday===6?'Saturday':weekday===0?'Sunday':'';
};
const weekendDocumentNotice = (h,value,label='Document date') => {
    const day=weekendDocumentDay(value);
    return day?h('small',{className:'weekend-document-notice',role:'status',
        style:{display:'block',marginTop:'6px',fontSize:'12px',color:'#8a540a',lineHeight:'1.45'}},
        'Weekend warning: '+label+' falls on '+day+'. Verify the actual documentary date. Weekends are allowed when genuine; normal date and chronology rules still apply.'):null;
};

// Selection-only DV safeguard. The server retains the authoritative
// same-PO exclusion under transaction lock, including legacy IAR links.
// A cancelled/voided historical DV is NOT permission to start another one.
const dvUnusedPoRows = rows => (Array.isArray(rows) ? rows : []).filter(po => !Array.isArray(po.existing_dvs) || po.existing_dvs.length === 0);
const dvPoMatchesSearch = (po, search) => {
    const needle = String(search || '').trim().toLowerCase();
    if (!needle) return true;
    return ((po.po_number || 'PO #'+po.po_id)+' '+(po.pr_number || '')+' '+(po.original_supplier || '')).toLowerCase().includes(needle);
};

// UI suggestion only: manual entry always overrides the suggestion.
// The server still independently validates whether a supplier is eligible and
// whether a higher-priced quotation needs a documented exception.
const recommendedAwardNote = (eligible, quoteId) => {
    if(!eligible.length)return '';
    const selected=eligible.find(q=>String(q.id)===String(quoteId));
    if(!selected)return '';
    const lowest=Math.min(...eligible.map(q=>Number(q.offered_total)));
    return Number(selected.offered_total)<=lowest+0.000001?'Lowest Calculated and Responsive Bid':'';
};

// Navigation labels reorganize presentation only; route/state names remain unchanged.
const nav = [
    ['Dashboard','▦','OVERVIEW'], ['Action Center','◉','OVERVIEW'],
    ['Procurement','▤','DOCUMENT WORKFLOWS'], ['Disbursements','▣','DOCUMENT WORKFLOWS'], ['Travel & Training','↗','DOCUMENT WORKFLOWS'],
    ['Fund Management','◈','FINANCE'], ['Cash & Bank','▥','FINANCE'],
    ['Inventory Management','◫','STOCK & PROPERTY'], ['Property Accountability','▣','STOCK & PROPERTY'], ['Textbooks','▧','STOCK & PROPERTY'], ['Division Consolidation','▦','STOCK & PROPERTY'],
    ['Reference Data','▤','MANAGEMENT'], ['Reports','▨','MANAGEMENT'], ['Organization','⌂','MANAGEMENT'],
    ['Administration','⚙','SYSTEM'], ['Settings','⚙','SYSTEM']
];
const schoolScopedDivision = state => state.user?.role==='DIVISION' && (state.orgs||[]).some(o=>String(o.id)===String(state.organization_id)&&o.kind==='SCHOOL');
// Visible module tabs are derived from current per-school named grants, never
// from a different organization's grant or a configured-only management code.
const divisionLiveSpecial = (state,type) => {
    if(!schoolScopedDivision(state))return false;
    const code={STOCK:'inventory.stocks.view',ASSET:'property.assets.view',TEXTBOOK:'textbooks.batches.view'}[type];
    const row=state.divisionModuleGrants;
    return !!code && String(row?.school_id)===String(state.organization_id) &&
        (row?.functions||[]).some(g=>g.code===code && g.school_register_enabled===true);
};
const emptyTravelClaim = () => ({authority_id:'',claim_date:localToday(),actual_from:localToday(),actual_to:localToday(),expense_note:'',lines:[{category:'TRANSPORT',description:'',amount:''}]});
const emptyTravelVerification = () => ({approved_request_checked:false,division_countersign_checked:false,authority_signed_checked:false,travel_completed_checked:false,receipts_checked:false,duplicate_claim_checked:false});
const emptyTravelRequest = () => ({employee_id:'',chargeable_to:'SCHOOL_MOOE',fund_id:'',chargeability_reference:'',request_date:localToday(),activity_title:'',host:'',purpose:'',destination:'',travel_from:localToday(),travel_to:localToday(),itinerary_reference:'',supporting_reference:''});
// Historical custodian appointments may predate IPDAMS employee data entry.
// This is client-side guidance only; Domain::saveSupplyCustodian() enforces
// staff scope, historical supporting records, audit and concurrency on server.
const custodianAppointmentEvidence = state => {
    const person=(state.staffData?.employees||[]).find(item=>item.active && String(item.employee_id)===String(state.staffCustodianId));
    const staffRecordedFrom=String(person?.effective_from||'');
    const effective=String(state.staffCustodianDate||'');
    const historical=!!person && !!staffRecordedFrom && !!effective && effective<staffRecordedFrom;
    const minimum=historical?20:(effective && effective<localToday()?10:0);
    const length=[...String(state.staffCustodianReason||'').trim()].length;
    return {historical,staffRecordedFrom,effective,minimum,length,
        missingCharacters:Math.max(0,minimum-length),
        needsConfirmation:historical&&!state.staffCustodianAttested};
};

// RFQ participants may have an authentic earlier designation than the date
// their employee directory record was first entered in IPDAMS. Never alter
// employment history; the RFQ server checks evidence and organization scope.
const rfqHistoricalParticipant = (staff,employeeId,rfqDate) => {
    const person=(staff?.employees||[]).find(p=>p.active&&String(p.employee_id)===String(employeeId));
    const recordedFrom=String(person?.effective_from||'');
    return {needed:!!person&&/^\d{4}-\d{2}-\d{2}$/.test(rfqDate)&&!!recordedFrom&&rfqDate<recordedFrom,
        recordedFrom,person:person||null};
};

const initial = { user: null, travelStage:'REQUEST',travelRequestFormOpen:false,travelDocuments:{requests:[],authorities:[]},travelClaims:{claims:[],required_evidence:{},cost_types:{}},travelClaimsLoading:false,travelClaimError:'',travelClaimUploadKind:'',travelClaimDetail:null,travelClaimEditing:false,travelClaimDraft:emptyTravelClaim(),travelClaimChecks:emptyTravelVerification(),travelLoading:false,travelError:'',travelSignatories:null,travelSignatoriesLoading:false,travelSignatoriesError:'',travelBranding:null,travelBrandingLoading:false,travelBrandingError:'',travelBrandingPending:null,travelBrandingReason:'',travelSignatoryIds:{budget_officer:'',division_accountant:'',asds:'',sds:''},travelSignatoryRegion:'',travelSignatorySalutation:'Sir/Madam:',travelSignatoryReason:'',travelRefreshSignatoryId:'',travelRefreshSignatoryReason:'',travelRequest:emptyTravelRequest(),travelEditId:'',travelEditVersion:0,travelEditReason:'',travelAuthorityRequestId:'',travelAuthorityDate:localToday(),travelApprovalReference:'',travelPaperApproved:false, sessionStatus: 'checking', sessionError: '', csrf: '', orgs: [], organization_id: '', tab: 'Dashboard', busy: false, workspaceLoading: false, workspaceFailures: [], error: '', success: '', lists: {}, eligible: [], eligibleLoading: false, eligibleError: '', eligibleLoadedFor: '', dashboard: {}, accounts: [], loginEmail: '', loginPassword: '', loginPasswordVisible:false, purpose: '', requesting_unit: '', pr_requestor: '', fund_id: '', pr_doc_date: localToday(), prLines: [{ item_id: '', quantity: '1', unit_price: '0.00' }], prRegisterMonth:'',prRegisterYear:'',workflowRegisterMonth:localToday().slice(5,7),workflowRegisterYear:localToday().slice(0,4),workflowExpandedQuotes:[],workflowFormOpen:false,prUnits:[],prUnitRecords:[],prUnitsShowInactive:false,prUnitsVersion:0,prUnitsLoading:false,prUnitsError:'',prUnitsDraft:'',prUnitsReason:'',prUnitEdit:'',prUnitName:'',staffData:null,staffLoading:false,mooeData:null,mooeViewerEmail:'',mooeViewerPassword:'',mooeViewerBusy:false,mooeLoading:false,mooeError:'',mooeSchoolId:'',mooeEmployeeId:'',mooeDate:localToday(),mooeBusy:false,mooeNotice:'',mooeHistoricalConfirmed:false,mooeAppointmentReference:'',staffSavingAction:'',staffSavedMessage:'',staffError:'',staffAddOpen:false,staffAddOrg:'',staffFullName:'',staffPosition:'',staffDesignation:'',staffEmployeeNo:'',staffTIN:'',staffAddress:'',staffProfileId:'',staffProfileOrg:'',staffProfileName:'',staffProfileEmployeeNo:'',staffProfileTIN:'',staffProfileAddress:'',staffProfileVersion:0,staffProfileBusy:false,staffEditName:'',staffEditPosition:'',staffEditDesignation:'',staffEditReason:'',staffEditOriginal:null,staffEditProfileOriginal:null,staffShowInactive:false,staffRenameId:'',staffRenameName:'',staffRenameReason:'',staffHeadId:'',staffDvPartA:'',staffDvPartC:'',staffDvPartD:'',staffHeadReason:'',staffCustodianId:'',staffCustodianDate:localToday(),staffCustodianReason:'',staffCustodianAttested:false,staffChair:'',staffCoChair:'',staffMembers:[],staffBacReason:'',staffBacDate:'',staffBacDateReason:'',staffBacAttested:false,prCreateOpen:false, prCreateBaseline:'', prCreateError:'', prEditOpen:false, prEdit:null, prEditError:'', prPickerOpen:'', prPickerQuery:'', prPickerResults:[],prRegistryMatches:[], prPickerLoading:false, prPickerError:'', prPickerActive:0, prQuickAdd:null, prQuickAddBusy:false, prQuickAddError:'', dvEdit:null,dvEditOriginal:'',dvEditError:'',dvParticularsEdit:null,dvParticularsError:'',dvCancel:null,dvCancelError:'',dvComposeOpen:false,dvSearch:'',dv_type: 'REGULAR', dv_po: '', dv_po_tax_profile: '', dvInvoiceVat:'', dvVatMode:'STANDARD',dvVatExceptionReason:'',dvTaxPreview:null, dvTaxBusy:false, dvTaxError:'', payee: '', bill_reference: '', allocations: {}, dv_fund: '', selectedDv: '', debitAccount: '', creditAccount: '', inventorySection:'stock', propertySection:'assets',propertyLastSection:{},propertyReportModal:null,propertyReportFrom:localToday().slice(0,7)+'-01',propertyReportThrough:localToday(),divisionModuleGrants:null,consolView:'OVERVIEW',consolSchool:'',consolSchools:[],consolMonth:'',consolReport:null,consolBusy:false,consolError:'',consolScopeId:'',divisionModuleGrantsBusy:false,divisionModuleGrantsError:'',divisionContextRegister:null,divisionContextBusy:false,divisionContextError:'',divisionRisLines:[],divisionRisLots:[],mooeIssuedMonth:localToday().slice(0,7),mooeIssuedDocs:null,mooeIssuedBusy:false,mooeIssuedError:'', ris_mode: 'DIRECT', ris_requestor: '', ris_item: '', ris_qty: '1', ris_pr: '', ris_id: '', ris_line_id: '', ris_lot_id: '', ris_issue_qty: '1', stock_item: '', stock_qty: '', tb_title:'',tb_isbn:'',tb_grade:'',tb_subject:'',tb_batch:'',tb_condition_note:'',tb_storage:'',tb_opening_qty:'1',tb_move_batch_id:'',tb_move_event_type:'RECEIPT',tb_move_quantity:'1',tb_move_direction:'IN',tb_move_reference:'',tbBatches:[],tbLoading:false,tbError:'',tbHistory:[],tbHistoryMonth:'',tbHistoryBatch:'',tbHistoryLoading:false,tbHistoryError:'',tbHistoryHasMore:false,actionCenterOrg:'',actionCenterItems:[],actionCenterCount:0,actionCenterLoading:false,actionCenterError:'',actionCenterChecked:'',propertyRecords:{assets:[],accountability:[],received_property:[]},
    // v0.2.219: register state MUST exist on initial render, not only after
    // the user switches organizations. Keep matching reset shape in session().
    propertyRegister:{rows:[],facets:{items:[],classifications:[],custodians:[],statuses:[]},has_more:false,next_before_id:null},
    propertyRegisterFilters:{search:'',category:'ALL',classification:'ALL',item_id:'ALL',custodian:'ALL',status:'ALL',source:'ALL'},
    propertyRegisterLoading:false,propertyRegisterError:'',
    propertyDetailId:'',propertyDetailOrg:'',propertyDetail:null,propertyDetailLoading:false,propertyDetailError:'',propertyDetailTab:'overview',propertyDetailBusy:false,propertyDetailEditShared:{},propertyDetailEditUnit:{},propertyDetailEditReason:'',propertyDetailEditMode:'UNIT',
    propertyBusy:false,propertyError:'',propertyReceivedId:'',propertyUnitCount:'1',propertyRegistrationNotice:'',propertyTags:'',propertyBatchItem:'',propertyBatchCount:'1',propertyBatchDate:'',propertyBatchCost:'',propertyBatchShared:{brand:'',model:'',acquisition_type:'PURCHASE',useful_life_years:'',historical_po:'',historical_iar:'',historical_dv:'',evidence_note:''},propertyBatchUnits:[{serial_number:'',location:'',condition_note:'',legacy_property_number:''}],propertyEditAssetId:'',propertyEditPreview:null,propertyEditMode:'UNIT',propertyEditReason:'',propertyEditShared:{},propertyEditUnit:{},propertyAssetId:'',propertySearch:'',propertyCustodian:'',propertyCustodianSearch:'',propertyCustodianEmployeeId:'', searchTerm: '', searchResults: [], procurementSection:'PR',workflowStage:'RFQ',workflowPR:'',workflowRfqLines:[],workflowRfqDate:'',workflowCanvasserId:'',workflowCanvasserHistoricalAttested:false,workflowCanvasserHistoricalReference:'',workflowChairHistoricalAttested:false,workflowChairHistoricalReference:'',workflowConsolidate:null,workflowConsolidateLoading:false,workflowConsolidateError:'',workflowConsolidatePrIds:[],workflowConsolidateExcluded:[],workflowRFQEditId:'',workflowRFQEditDate:'',workflowRFQEditLines:[],workflowRFQEditVersion:0,workflowRFQEditReason:'',workflowRFQEditConfirm:false,workflowRFQEditError:'',workflowRFQEditLoading:false,workflowRFQ:'',workflowSupplier:'',workflowQuoteDate:localToday(),workflowQuoteEvidence:'',workflowQuoteLines:[],workflowAwardRFQ:'',workflowAwardDate:localToday(),workflowQuoteId:'',workflowAwardNote:'',workflowAwardNoteManual:false,workflowAwardApproved:false,workflowAward:'',workflowPoDate:localToday(),workflowPoLines:[],workflowPoDeliveryDate:localToday(),workflowPoDeliveryPlace:'',workflowPoDeliveryTerm:'Door-to-Door Delivery',workflowPoPaymentTerm:'Full Payment upon Delivery',workflowPoPaymentType:'Check',workflowPoMethod:'',workflowPoEdit:null,workflowPoEditError:'',workflowPoEditLoading:false,workflowPO:'',workflowIARLines:[],workflowDelivered:'',workflowAccepted:'',workflowIARInvoice:'',workflowIARInvoiceDate:'',workflowIARPoMeta:null,workflowIarDate:localToday(),workflowIARReviewed:false,workflowIarEdit:null,workflowIarEditLoading:false,workflowIarEditError:'',workflowMessage:'',workflowError:'', advancedRoute: 'rfq.create', advancedBody: '{}', details: null, viewType: '', pending: false, catalogRegistryLinks:{links:{}},prAccountingPreview:{},prAccountingBusy:{},prLineage:null,prLineageBusy:'',prLineageError:'',registryLinkDialog:null,registryLinkQuery:'',registryLinkOptions:[],registryLinkBusy:false,registryLinkError:'',propertyRegistry:null,propertyRegistryBusy:false,propertyRegistryError:'',propertyRegistrySearch:'',propertyRegistrySection:'',propertyUnitCost:'',propertyThresholdDialog:false,propertyThresholdValue:'5000.00',propertyThresholdDate:localToday(),propertyThresholdReason:'',propertyLifeDialog:null,propertyLifeMonths:'',propertyLifeReason:'',propertyEntryDialog:null,propertyEntrySection:'',propertyEntryDescription:'',propertyEntryMode:'HV_LV',propertyEntryHvCode:'',propertyEntryLvCode:'',propertyEntryReferenceCode:'',propertyEntryDetail:'',propertyEntryMonths:'',propertyEntryReason:'',masterSection: 'suppliers', fundSection: 'overview', fundPolicyClass:'NON_IUS',fundPolicies:null,fundPoliciesLoading:false,sourceDialog:false,policyDialog:false,sourceCode:'',sourceName:'',sourceActive:true,sourceReason:'',fundPolicySource:'',fundPolicyMode:'NOT_CONFIGURED',fundPolicyDate:localToday(),fundPolicyAuthority:'',fundPolicyReason:'',schoolClass:'',schoolClassDate:localToday(),schoolClassReason:'',schoolClassEdit:'',schoolClassEditClass:'',schoolClassEditDate:localToday(),schoolClassEditReason:'', masterEditorOpen: false, masterSearch: '', divisionClassifications: semiSubcategories.map(([code,name])=>({code,name,active:true,default_uacs_code:null})), divisionName: '', classEditCode: '', classCode: '', className: '', classUacs: '', classAppliesTo:'SEMI_EXPENDABLE', classProcurementTypes:[], classActive: true, classDialogOpen: false, uacsQuery: '', uacsStatus: 'ALL', uacsClassification: 'ALL', uacsPage: 0, uacsLoading: false, uacsData: {rows: [],total: 0,offset: 0,limit:80,summary:{total:0,enabled:0,disabled:0}}, sup_name: '', sup_proprietor: '', sup_tax_id: '', sup_address: '', sup_zip: '', sup_commercial: 'UNSPECIFIED', sup_vat_status: 'UNKNOWN', sup_atcs: [], sup_profiles: [], sup_legacy_atcs: [], sup_edit_id: '', atc_code: '', atc_description: '', atc_supply_type: 'BOTH', atc_profile_category:'', atc_edit_profile_category:'', atc_goods_applicable:true, atc_services_applicable:true, atc_rental_applicable:false, atc_contractor_applicable:false, atc_utilities_applicable:false, atc_other_applicable:false, atc_business_type: 'BOTH', atc_tax_kind: '', atc_tax_filter:'ALL', rate_atc_code: '', rate_percent: '', rate_starts: '', rate_ends: '', rate_authority: '', rate_close_id: '', rate_close_date: '', atc_initial_percent: '', atc_initial_start: '', atc_initial_end: '', atc_initial_authority: '', atc_selected_code: '', atc_action: '', atc_list_loading: false, atc_dialog: '', atc_edit_description: '', atc_edit_supply_type: 'BOTH', atc_edit_goods_applicable:true, atc_edit_services_applicable:true, atc_edit_rental_applicable:false, atc_edit_contractor_applicable:false, atc_edit_utilities_applicable:false, atc_edit_other_applicable:false, atc_edit_business_type: 'BOTH', atc_edit_tax_kind: '', dvTaxes: [], unit_code: '', unit_name: '', item_name: '', item_unit_id: '', item_category: 'SUPPLY', item_subcategory: '', item_school_class:'', item_edit_id: '', itemUacsSettings:null,itemUacsLoading:false,vatSettings:null,vatSettingsLoading:false,vatRateInput:'12.00',vatRateDate:localToday(),vatRateAuthority:'',vatRateReason:'',itemUacsId:'',itemUacsCode:'',itemUacsDate:localToday(),itemUacsReason:'',itemUacsDialog:false,uacsCorrection:null,uacsCorrectionPreview:null,bulkUacsOpen:false,bulkUacsIds:[],bulkUacsClass:'',bulkUacsDate:localToday(),bulkUacsReason:'',bulkUacsPreview:null, fund_name: '', fund_source_code: '', fund_allocation_open:false,bank_download_open:false,bank_download_mode:'NEW',bank_download_fund:'',bank_download_bank:'',bank_download_date:localToday(),bank_download_amount:'',bank_download_ref:'',bank_download_advice:'',bank_download_evidence:'',bank_download_particulars:'',bank_download_existing_id:'',bank_download_link_reason:'',fund_allocation_type:'OBLIGATION',fund_allocation_id:'',fund_allocation_amount:'',fund_allocation_reference:'',fund_allocation_reason:'',bank_name:'',bank_label:'',bank_mask:'',checkDv:'',checkBank:'',checkNo:'',checkAmount:'',checkPayeeBasis:'BUSINESS',checkStockPreview:null,checkLeafConfirmed:false,checkStockList:{rows:[],stats:[]},checkStockLoaded:false,checkStockLoading:false,checkStockError:'',cashBankSection:'prepare',checkProofOpen:false,checkProofIssued:null,cashCheckSearch:'',cashCheckStatus:'ALL',cashLeafSearch:'',cashLeafStatus:'ALL',checkbookReference:'',checkbookFirst:'',checkbookLast:'',checkbookVerified:false,checkbookBank:'',dvCheckBank:'',dvCheckStock:null,dvCheckLoading:false,dvCheckLeafConfirmed:false,dvCheckPayeeBasis:'DV_PAYEE',dvFundAvailable:null,dvFundLoading:false, fund_fiscal_year: String(new Date().getFullYear()), pr_method: 'REVIEW_REQUIRED', setup_code: '', setup_name: '', setup_kind: 'DIVISION', orgHierarchy: [], orgUsers: [], organizationSection: (() => { const q=new URLSearchParams(window.location.search).get('orgtab'); return ['division','schools','users','roles','myfunctions','account','school'].includes(q) ? q : 'division'; })(), roleCatalog:null,roleDetail:null,roleBusy:false,roleError:'',roleNotice:'',roleUserId:'',roleSchoolId:'',rolePermission:'',roleReason:'',roleEndsOn:'',roleStartsOn:localToday(),roleAuditOpen:false,roleFunctionSearch:'',roleModule:'ALL',myFunctions:null,myFuncOrg:'',myFuncType:'PR',myFuncMonth:localToday().slice(0,7),myFuncRecords:null,myFuncBusy:false,myFuncError:'', division_code: '', division_name: '', division_address: '', division_email: '', division_phone: '', school_code: '', school_name: '', school_address: '', school_email: '', school_phone: '', selected_division_id: '', legacy_school_id: '', new_division_user_name: '', new_division_user_email: '', new_division_user_password: '', new_school_user_name: '', new_school_user_email: '', new_school_user_password: '', new_user_school_id: '', organizationPendingAction: '', edit_org_id:'',edit_name:'',edit_code:'',edit_code_reason:'',edit_address:'',edit_email:'',edit_phone:'', own_current_password:'', own_new_password:'', own_confirm_password:'', reset_user_id:'',reset_admin_password:'',reset_new_password:'',reset_confirm_password:'',reset_identity_confirmed:false };
class App extends React.Component {
    componentDidUpdate(prevProps,prevState) {
        // Changing organizations invalidates all older Action Center responses.
        if(this.state.sessionStatus==='ready' && this.state.user && this.state.organization_id &&
           (prevState.organization_id!==this.state.organization_id ||
            String(prevState.user?.id||'')!==String(this.state.user.id)))
            this.loadActionCenter();
        if(this.state.tab==='Division Consolidation' && this.state.sessionStatus==='ready' &&
           (prevState.tab!==this.state.tab || prevState.organization_id!==this.state.organization_id ||
            prevState.user?.id!==this.state.user?.id))
            this.loadDivisionConsolidation(false);
        if(schoolScopedDivision(this.state) && this.state.tab==='Textbooks' &&
           this.state.divisionModuleGrants && !divisionLiveSpecial(this.state,'TEXTBOOK'))
            this.setState({tab:'Inventory Management',inventorySection:'ris'});
        // Every entry into a sensitive Division-school module rechecks grant state;
        // last rendered grants are cleared before the asynchronous request.
        if(schoolScopedDivision(this.state) && ['Inventory Management','Property Accountability','Textbooks'].includes(this.state.tab) &&
           (prevState.tab!==this.state.tab || prevState.organization_id!==this.state.organization_id ||
            String(prevState.user?.id||'')!==String(this.state.user?.id||'')))
            this.loadDivisionModuleGrants();
        if(schoolScopedDivision(this.state) && this.state.tab==='Inventory Management' && this.state.inventorySection==='special_stock' &&
           divisionLiveSpecial(this.state,'STOCK') && prevState.divisionModuleGrants!==this.state.divisionModuleGrants)
            this.loadDivisionContextRegister('STOCK');
        if(schoolScopedDivision(this.state) && this.state.tab==='Property Accountability' && this.state.propertySection==='special_assets' &&
           divisionLiveSpecial(this.state,'ASSET') && prevState.divisionModuleGrants!==this.state.divisionModuleGrants)
            this.loadDivisionContextRegister('ASSET');
        if(schoolScopedDivision(this.state) && this.state.tab==='Textbooks' &&
           divisionLiveSpecial(this.state,'TEXTBOOK') &&
           (prevState.tab!=='Textbooks'||prevState.organization_id!==this.state.organization_id||
            prevState.divisionModuleGrants!==this.state.divisionModuleGrants))
            this.loadDivisionContextRegister('TEXTBOOK');
        if(schoolScopedDivision(this.state) && this.state.ris_id && (prevState.ris_id!==this.state.ris_id || prevState.organization_id!==this.state.organization_id))this.loadDivisionRisLines();
        if(schoolScopedDivision(this.state) && this.state.ris_line_id && (prevState.ris_line_id!==this.state.ris_line_id || prevState.organization_id!==this.state.organization_id))this.loadDivisionRisLots();
        // Authenticated direct links also resolve to the canonical Settings page.
        // PHP settingsAdmin() remains the authoritative authorization check.
        if(['SUPER_ADMIN','SCHOOL'].includes(this.state.user?.role) && this.state.sessionStatus==='ready' &&
           this.state.tab==='Settings' &&
           (prevState.tab!=='Settings' || prevState.sessionStatus!=='ready')) {
            window.location.replace(this.state.user?.role==='SCHOOL'?'settings.php?tab=numbering':'settings.php?tab=updates');
            return;
        }
        if(this.state.user?.role==='VIEWER')this.disableViewerEditingControls();

        if(prevState.atc_dialog!==this.state.atc_dialog)document.body.classList.toggle('atc-dialog-open',Boolean(this.state.atc_dialog));
        if(prevState.prCreateOpen!==this.state.prCreateOpen)document.body.classList.toggle('ipdams-pr-create-open',Boolean(this.state.prCreateOpen));
        if(prevState.dvComposeOpen!==this.state.dvComposeOpen)document.body.classList.toggle('ipdams-dv-compose-open',Boolean(this.state.dvComposeOpen));
        const prevAssetDialog=!!prevState.propertyDetailId && prevState.propertyDetailOrg===String(prevState.organization_id) && prevState.tab==='Property Accountability' && prevState.propertySection==='assets';
        const nextAssetDialog=!!this.state.propertyDetailId && this.state.propertyDetailOrg===String(this.state.organization_id) && this.state.tab==='Property Accountability' && this.state.propertySection==='assets';
        if(prevAssetDialog!==nextAssetDialog){
            document.body.classList.toggle('ipdams-asset-dialog-open',nextAssetDialog);
            if(nextAssetDialog){
                const close=document.querySelector('.ipdams-asset-dialog-close');
                if(close)close.focus();
            }else if(this.propertyDialogReturnFocus && document.contains(this.propertyDialogReturnFocus)){
                this.propertyDialogReturnFocus.focus();this.propertyDialogReturnFocus=null;
            }
        }

        if((this.state.tab==='Fund Management' && this.state.fundSection==='funds' &&
             (prevState.tab!==this.state.tab || prevState.fundSection!==this.state.fundSection || prevState.organization_id!==this.state.organization_id)) ||
           (this.state.tab==='Disbursements' && (prevState.tab!==this.state.tab || prevState.organization_id!==this.state.organization_id)))
            this.loadFundPolicySettings();
        if(this.state.tab==='Property Accountability' && this.state.user?.role!=='VIEWER' && (prevState.tab!=='Property Accountability'||prevState.organization_id!==this.state.organization_id)){this.loadAccountableProperty();this.loadStaffDirectory();}
        if(this.state.tab==='Textbooks' && !schoolScopedDivision(this.state) && (prevState.tab!=='Textbooks'||prevState.organization_id!==this.state.organization_id))this.loadTextbookBatches();
        if(this.state.tab==='Textbooks' && !schoolScopedDivision(this.state) && (prevState.tab!=='Textbooks'||prevState.organization_id!==this.state.organization_id))this.loadTextbookHistory();
        if((this.state.tab==='Reference Data' && this.state.masterSection==='requesting_units' && (prevState.tab!==this.state.tab||prevState.masterSection!==this.state.masterSection||prevState.organization_id!==this.state.organization_id)) || (this.state.tab==='Procurement' && (prevState.tab!==this.state.tab||prevState.organization_id!==this.state.organization_id)))this.loadPrRequestingUnits();
        if(this.state.tab==='Reference Data' && this.state.masterSection==='division_mooe' &&
           (prevState.tab!==this.state.tab||prevState.masterSection!==this.state.masterSection||prevState.organization_id!==this.state.organization_id))this.loadDivisionMooe();
        if(this.state.tab==='Reference Data' && this.state.masterSection==='travel_signatories' &&
           (prevState.tab!==this.state.tab||prevState.masterSection!==this.state.masterSection||prevState.organization_id!==this.state.organization_id))this.loadTravelSignatories();
        if(this.state.tab==='Reference Data' && this.state.masterSection==='letterhead' &&
           (prevState.tab!==this.state.tab||prevState.masterSection!==this.state.masterSection||prevState.organization_id!==this.state.organization_id))this.loadTravelBranding();
        // RFQ issuance depends on the owning school's active canvasser and BAC Chairperson.
        // Load the scoped employee directory on Procurement entry and workspace switches,
        // including direct navigation to ?section=Procurement after session restoration.
        if((this.state.tab==='Reference Data' && ['employees','officials'].includes(this.state.masterSection) &&
            (prevState.tab!==this.state.tab||prevState.masterSection!==this.state.masterSection||prevState.organization_id!==this.state.organization_id)) ||
           (this.state.tab==='Procurement' &&
            (prevState.tab!==this.state.tab||prevState.organization_id!==this.state.organization_id)))
            this.loadStaffDirectory();
        if(this.state.tab==='Travel & Training' && (prevState.tab!=='Travel & Training'||prevState.organization_id!==this.state.organization_id)){
            this.loadTravelDocuments();this.loadTravelClaims();this.loadStaffDirectory();this.loadTravelSignatories();
        }
        if(this.state.tab==='Reference Data' && this.state.masterSection==='items' &&
           (prevState.masterSection!==this.state.masterSection || prevState.tab!==this.state.tab || prevState.organization_id!==this.state.organization_id))
            {this.loadDivisionItemUacs();this.loadCatalogRegistryLinks();}
        if(this.state.tab==='Reference Data' && this.state.masterSection==='vat_config' &&
           (prevState.masterSection!==this.state.masterSection || prevState.tab!==this.state.tab || prevState.organization_id!==this.state.organization_id))
            this.loadVatSettings();
        if(this.state.tab==='Cash & Bank' && (prevState.tab!==this.state.tab || prevState.organization_id!==this.state.organization_id))this.loadPhysicalCheckStock();
        if((prevState.masterSection!==this.state.masterSection && this.state.masterSection==='property_registry') || (prevState.organization_id!==this.state.organization_id && this.state.masterSection==='property_registry'))this.loadPropertyRegistry();
    }
    disableViewerEditingControls(){
        // Presentation-only. Server authorization denies mutations independently.
        const root=document.getElementById('root');if(!root)return;
        const deny=/^(?:save|submit|add |new |create|prepare |approve|finalize|issue |release |post |cancel |delete|remove |update |edit |correct |register |upload |transfer |reverse |return |record |activate |deactivate |reset |confirm |generate |close |apply |proceed to|proceed with|start new)/i;
        root.querySelectorAll('button').forEach(button=>{
            if(button.classList.contains('navitem'))return;
            if(/^change my password$/i.test((button.textContent||'').trim()))return;
            if(button.type==='submit'||deny.test((button.textContent||'').trim())){
                button.disabled=true;button.setAttribute('aria-disabled','true');
                button.title='Read-only Division MOOE reviewer';
            }
        });
        root.querySelectorAll('input[type="file"]').forEach(el=>{el.disabled=true;});
    }
    componentWillUnmount() {
        if(this.actionTimer)window.clearInterval(this.actionTimer);
        if(this.actionVisibilityRefresh)document.removeEventListener('visibilitychange',this.actionVisibilityRefresh);
        document.body.classList.remove('atc-dialog-open','ipdams-pr-create-open','ipdams-dv-compose-open','ipdams-asset-dialog-open');
    }
    constructor() {
        super(...arguments);
        this.state = { ...initial, tab: (() => { const section = new URLSearchParams(window.location.search).get('section'); return section === 'Property & Supply' ? 'Inventory Management' : nav.some(([name]) => name === section) ? section : 'Dashboard'; })() };
        this.api = async (route, body) => {
            const post = body !== undefined;
            if(post && this.state.user?.role==='VIEWER' && route!=='auth.change_password')
                throw new Error('Read-only Division MOOE account: changes are not permitted.');
            const endpoint = 'api/index.php?route=' + encodeURIComponent(route);
            const url = post ? endpoint : endpoint + (route === 'auth.session' || route === 'accounts' ? '' : '&organization_id=' + encodeURIComponent(this.state.organization_id));
            const r = await fetch(url, { method: post ? 'POST' : 'GET', credentials: 'same-origin', headers: post ? { 'Content-Type': 'application/json', 'X-CSRF-Token': this.state.csrf, 'X-Idempotency-Key': String(Date.now()) + '-' + Math.random().toString(36).slice(2, 14) } : {}, body: post ? JSON.stringify(body) : undefined });
            const data = await r.json().catch(() => ({error: 'Server returned a non-JSON error. Contact your technical administrator.'}));
            if (!r.ok)
                throw new Error(data.error || 'Server returned an error');
            return data.data;
        };
        // Workspace is a user-scoped preference, never an authorization token. The server
        // returns the authoritative list of allowed organizations on every session load.
        this.workspacePreferenceKey = (user) => 'ipdams.workspace.user.' + String(user.id);
        this.rememberWorkspace = (user, orgId) => {
            try { if (user && orgId) window.localStorage.setItem(this.workspacePreferenceKey(user), String(orgId)); } catch (_) { /* Browsers can block local storage. */ }
        };
        this.session = async () => { try {
            const r = await this.api('auth.session');
            const available = r.organizations || [];
            let saved = '';
            try { if (r.user) saved = window.localStorage.getItem(this.workspacePreferenceKey(r.user)) || ''; } catch (_) { /* Optional preference only. */ }
            const previous = this.state.organization_id;
            const selected = available.find(o => String(o.id) === String(previous)) ||
                available.find(o => String(o.id) === String(saved)) || available[0];
            const org = selected?.id;
            this.setState({ user: r.user, orgs: available, csrf: r.csrf, organization_id: org ? String(org) : '',mooeIssuedDocs:null,mooeIssuedError:'',mooeIssuedBusy:false,divisionModuleGrants:null,divisionModuleGrantsBusy:false,divisionContextRegister:null,divisionContextError:'',
                sessionStatus: 'ready', sessionError: '', error: '',
                workspaceLoading: Boolean(r.user && org) }, () => {
                if (r.user && org) { this.rememberWorkspace(r.user, org); this.refresh(); this.loadHierarchy(); }
            });
        }
        catch (e) {
            // A failed session lookup is not proof the user has signed out.
            // Do not display the login form after a transient API/database error.
            this.setState({ sessionStatus: this.state.user ? 'ready' : 'error',
                sessionError: e.message || 'Unable to verify your session.', error: e.message });
        } };
        this.loadHierarchy = async () => {
            if(!this.state.user) return;
            try {
                const data=await this.get('organization.hierarchy');
                this.setState({orgHierarchy:data.organizations||[],orgUsers:data.users||[],error:''});
            } catch(e) {
                this.setState({error:'Organization data unavailable. Confirm the v0.2.2 SQL migration is installed. '+e.message});
            }
        };
        this.loadFundPolicySettings = async () => {
            const workspace=String(this.state.organization_id||'');
            if(!workspace || this.state.fundPoliciesLoading)return;
            this.setState({fundPoliciesLoading:true});
            try { const data=await this.get('fund.policy.settings',{organization_id:workspace});
                if(String(this.state.organization_id)===workspace)this.setState({fundPolicies:data,
                    ...(this.state.user?.role==='SCHOOL'&&data.school_classification?.classification
                       ?{fundPolicyClass:data.school_classification.classification}:{})});
            }catch(e){if(String(this.state.organization_id)===workspace)this.setState({error:e.message});}
            finally {this.setState({fundPoliciesLoading:false});}
        };
        this.loadDivisionItemUacs = async () => {
            const org=String(this.state.organization_id||'');
            if(!org || this.state.itemUacsLoading)return;
            this.setState({itemUacsLoading:true});
            try{
                const data=await this.get('division.item_uacs',{organization_id:org});
                if(String(this.state.organization_id)===org)this.setState({itemUacsSettings:data});
            }catch(error){if(String(this.state.organization_id)===org)this.setState({error:'Division item UACS mappings could not load: '+error.message});}
            finally {this.setState({itemUacsLoading:false});}
        };
        // v0.2.65: every catalog item, including consumable supplies, uses the
        // *same* Division-approved effective-dated item-account mapping API.
        // Classification suggestions never become financial postings by themselves.
        this.latestItemUacs = itemId => (this.state.itemUacsSettings?.mappings||[])
            .filter(m=>Number(m.item_id)===Number(itemId))
            .sort((a,b)=>String(b.effective_from).localeCompare(String(a.effective_from)))[0]||null;
        this.openItemUacsDialog = item => {
            if(this.state.busy||!['DIVISION','SUPER_ADMIN'].includes(this.state.user?.role))return;
            this.setState({itemUacsDialog:true,itemUacsId:String(item.id),itemUacsCode:'',
                itemUacsDate:localToday(),itemUacsReason:'',error:'',success:''});
            if(!this.state.itemUacsSettings)this.loadDivisionItemUacs();
        };
        this.closeItemUacsDialog = () => {
            if(this.state.busy)return;
            this.setState({itemUacsDialog:false,itemUacsId:'',itemUacsCode:'',itemUacsReason:'',error:''});
        };
        // v0.2.68: Bulk-backfill existing, never-mapped consumables only.
        // Each candidate is explicitly selected and the server reviews the exact
        // class/account/IDs before the administrator may apply any changes.
        this.bulkUacsCandidates = () => (this.state.lists.all_items||[])
            .filter(item=>item.category==='SUPPLY' && Number(item.active)===1 &&
                !(this.state.itemUacsSettings?.mappings||[]).some(m=>Number(m.item_id)===Number(item.id)))
            .sort((a,b)=>String(a.name).localeCompare(String(b.name))).slice(0,100);
        this.openBulkUacs = () => {
            if(this.state.busy || !['DIVISION','SUPER_ADMIN'].includes(this.state.user?.role))return;
            const candidates=this.bulkUacsCandidates();
            this.setState({bulkUacsOpen:true,bulkUacsIds:candidates.map(item=>Number(item.id)),
                bulkUacsClass:'',bulkUacsDate:localToday(),bulkUacsReason:'',bulkUacsPreview:null,
                error:'',success:''});
            if(!this.state.itemUacsSettings)this.loadDivisionItemUacs();
        };
        this.closeBulkUacs = () => {
            if(this.state.busy)return;
            this.setState({bulkUacsOpen:false,bulkUacsPreview:null,bulkUacsIds:[],bulkUacsReason:'',error:''});
        };
        this.bulkUacsBody = () => ({
            item_ids:this.state.bulkUacsIds.map(Number),
            classification_code:this.state.bulkUacsClass,
            effective_from:this.state.bulkUacsDate,
            expected_version:Number(this.state.itemUacsSettings?.version_id??-1)
        });
        this.previewBulkUacs = async e => {
            e.preventDefault();
            if(!this.state.itemUacsSettings){this.setState({error:'Refresh the Division UACS mapping list before preview.'});return;}
            const result=await this.task('division.item_uacs.bulk.preview',this.bulkUacsBody(),{refresh:false});
            if(result)this.setState({bulkUacsPreview:result,error:'',success:''});
        };
        this.applyBulkUacs = async () => {
            const preview=this.state.bulkUacsPreview;
            if(!preview || this.state.busy)return;
            const reason=this.state.bulkUacsReason.trim();
            if(!reason){this.setState({error:'Enter the Division classification authority and reason.'});return;}
            const result=await this.task('division.item_uacs.bulk.apply',{
                ...this.bulkUacsBody(),review_token:preview.review_token,reason
            },{refresh:false});
            if(result){this.setState({bulkUacsOpen:false,bulkUacsPreview:null,bulkUacsIds:[],bulkUacsReason:'',
                success:result.changed+' consumable item(s) assigned to UACS '+result.uacs_code+'. All mapping changes are audited.'});
                await this.loadDivisionItemUacs();}
        };
        this.loadVatSettings = async () => {
            const org=String(this.state.organization_id||'');
            if(!org || this.state.vatSettingsLoading)return;
            this.setState({vatSettingsLoading:true});
            try{
                const data=await this.get('division.vat.settings',{organization_id:org});
                if(org===String(this.state.organization_id))this.setState({vatSettings:data,
                    vatRateInput:data.current?.rate_percent||'12.00'});
            }catch(e){if(org===String(this.state.organization_id))this.setState({error:'VAT configuration could not load: '+e.message});}
            finally{this.setState({vatSettingsLoading:false});}
        };
        this.saveVatRate = async e => {
            e.preventDefault();const v=this.state.vatSettings;
            if(!v){this.setState({error:'Load the Division VAT reference data before saving.'});return;}
            const r=await this.task('division.vat.save',{rate_percent:this.state.vatRateInput,
                effective_from:this.state.vatRateDate,authority:this.state.vatRateAuthority.trim(),
                reason:this.state.vatRateReason.trim(),expected_version:Number(v.latest_version)},{refresh:false});
            if(r){this.setState({vatRateAuthority:'',vatRateReason:'',
                success:'Division standard VAT rate saved with an effective date. Existing DVs were not modified.'});
                await this.loadVatSettings();}
        };

        this.openUacsCorrection = map => {
            const org=this.state.orgs.find(x=>String(x.id)===String(this.state.organization_id));
            if(this.state.busy||org?.kind!=='DIVISION'||!['DIVISION','SUPER_ADMIN'].includes(this.state.user?.role))return;
            this.setState({itemUacsDialog:false,uacsCorrection:{item_id:Number(map.item_id),uacs_code:map.uacs_code,
                old_effective_from:map.effective_from,new_effective_from:map.effective_from,
                expected_version:Number(this.state.itemUacsSettings?.version_id??-1),
                reason:'',authority_reference:'',workspace:String(this.state.organization_id)},
                uacsCorrectionPreview:null,error:'',success:''});
        };
        this.patchUacsCorrection=(key,value)=>this.setState({
            uacsCorrection:{...this.state.uacsCorrection,[key]:value},uacsCorrectionPreview:null,error:''});
        this.closeUacsCorrection=()=>{if(!this.state.busy)this.setState({
            uacsCorrection:null,uacsCorrectionPreview:null,error:''});};
        this.uacsCorrectionBody=()=>{
            const c=this.state.uacsCorrection;
            return {item_id:c.item_id,uacs_code:c.uacs_code,
                old_effective_from:c.old_effective_from,new_effective_from:c.new_effective_from,
                expected_version:c.expected_version};
        };
        this.previewUacsCorrection=async e=>{
            e.preventDefault();
            const org=String(this.state.organization_id);
            this.setState({busy:true,error:'',uacsCorrectionPreview:null});
            try{
                const result=await this.api('division.item_uacs.effectivity.preview',
                    {organization_id:Number(org),...this.uacsCorrectionBody()});
                if(String(this.state.organization_id)===org)this.setState({uacsCorrectionPreview:result});
            }catch(error){if(String(this.state.organization_id)===org)this.setState({error:error.message});}
            finally{this.setState({busy:false});}
        };
        this.applyUacsCorrection=async()=>{
            const c=this.state.uacsCorrection,p=this.state.uacsCorrectionPreview;
            if(!c||!p?.can_apply||c.workspace!==String(this.state.organization_id))return;
            const applied=await this.task('division.item_uacs.effectivity.apply',{
                ...this.uacsCorrectionBody(),review_token:p.review_token,
                reason:c.reason.trim(),authority_reference:c.authority_reference.trim()},{refresh:false});
            if(applied){
                this.setState({uacsCorrection:null,uacsCorrectionPreview:null,
                    success:'UACS effective-date correction recorded with audit history. Refresh the draft DV to review its updated workpaper.'});
                await this.loadDivisionItemUacs();
            }
        };
        this.saveDivisionItemUacs = async e => {
            e.preventDefault();
            const config=this.state.itemUacsSettings;
            if(!config){this.setState({error:'Reload the Division item UACS directory first.'});return;}
            const body={item_id:Number(this.state.itemUacsId),uacs_code:this.state.itemUacsCode,
                effective_from:this.state.itemUacsDate,reason:this.state.itemUacsReason.trim(),expected_version:Number(config.version_id)};
            const r=await this.task('division.item_uacs.save',body,{refresh:false});
            if(r){this.setState({itemUacsReason:'',itemUacsDialog:false,itemUacsId:'',itemUacsCode:'',success:'Division item UACS mapping recorded. Earlier DV/account snapshots remain unchanged.'});
                await this.loadDivisionItemUacs();}
        };
        this.saveDivisionSource = async e => {
            e.preventDefault();const r=await this.task('division.fund_source.save',{code:this.state.sourceCode.toUpperCase().trim(),name:this.state.sourceName.trim(),
                active:this.state.sourceActive,reason:this.state.sourceReason.trim(),catalog_version:Number(this.state.fundPolicies?.catalog_version??-1)},{refresh:false});
            if(r){this.setState({sourceDialog:false,sourceCode:'',sourceName:'',sourceReason:''});await this.loadFundPolicySettings();}
        };
        this.saveDivisionFundPolicy = async e => {
            e.preventDefault();const r=await this.task('division.fund_policy.save',{source_code:this.state.fundPolicySource,school_class:this.state.fundPolicyClass,
                policy:this.state.fundPolicyMode,effective_from:this.state.fundPolicyDate,authority:this.state.fundPolicyAuthority.trim(),reason:this.state.fundPolicyReason.trim()},{refresh:false});
            if(r){this.setState({policyDialog:false,fundPolicyReason:'',fundPolicyAuthority:''});await this.loadFundPolicySettings();}
        };
        this.submitOrganization = async (e, route, body, action = '') => {
            e.preventDefault(); if(this.state.busy) return null;
            this.setState({busy:true,organizationPendingAction:action,error:'',success:''});
            try {
                const res=await this.api(route,body);
                await this.session();
                const resultMessage=action==='DIVISION_USER'?'Division Administrator account created successfully.':
                    action==='SCHOOL_USER'?'School Account created successfully.':
                    action==='ADMIN_PASSWORD_RESET'?'Password reset saved. Tell the account owner privately and ask them to change it after login.':
                    'Saved successfully. Organization access has been refreshed.';
                this.setState({success:resultMessage});
                return res;
            } catch(e) {this.setState({error:e.message});return null;}
            finally {this.setState({busy:false,organizationPendingAction:''});}
        };
        this.makeDivision = e => this.submitOrganization(e,'organization.division.create',{
            code:this.state.division_code.trim().toUpperCase(),name:this.state.division_name.trim(),
            address:this.state.division_address.trim(),contact_email:this.state.division_email.trim(),contact_phone:this.state.division_phone.trim()
        });
        this.makeSchool = e => this.submitOrganization(e,'organization.school.create',{
            division_id:Number(this.state.selected_division_id),code:this.state.school_code.trim().toUpperCase(),name:this.state.school_name.trim(),
            address:this.state.school_address.trim(),contact_email:this.state.school_email.trim(),contact_phone:this.state.school_phone.trim(),
            classification:this.state.schoolClass,effective_from:this.state.schoolClassDate
        });
        this.linkLegacySchool = e => this.submitOrganization(e,'organization.school.attach',{
            division_id:Number(this.state.selected_division_id),school_id:Number(this.state.legacy_school_id)
        });
        this.changeProfile = (e) => {
            const id=e.target.value;const o=(this.state.orgHierarchy||[]).find(x=>String(x.id)===id);
            this.setState({edit_org_id:id,edit_name:o?.name||'',edit_code:o?.kind==='SCHOOL'?(o.code||''):'',edit_code_reason:'',edit_address:o?.address||'',edit_email:o?.contact_email||'',edit_phone:o?.contact_phone||'',
                schoolClassEdit:id,schoolClassEditClass:o?.implementation_class||'',
                schoolClassEditDate:o?.implementation_effective_from||localToday(),schoolClassEditReason:''});
        };
        this.saveProfile = async e => {
            const org=(this.state.orgHierarchy||[]).find(o=>String(o.id)===String(this.state.edit_org_id));
            const isSchool=org?.kind==='SCHOOL';
            // Saving a phone or address must not trigger an unrelated,
            // possibly unconfigured implementation-classification validation.
            const classChanged=isSchool && (
                this.state.schoolClassEditClass!==(org.implementation_class||'') ||
                (this.state.schoolClassEditClass!=='' && this.state.schoolClassEditDate!==(org.implementation_effective_from||'')));
            const result=await this.submitOrganization(e,'organization.profile.update',{
                target_organization_id:Number(this.state.edit_org_id),name:this.state.edit_name.trim(),
                address:this.state.edit_address.trim(),contact_email:this.state.edit_email.trim(),contact_phone:this.state.edit_phone.trim(),
                ...(isSchool?{code:this.state.edit_code.trim(),expected_code:org.code,code_change_reason:this.state.edit_code_reason.trim(),
                    ...(classChanged?{classification:this.state.schoolClassEditClass,
                        classification_effective_from:this.state.schoolClassEditDate,
                        classification_reason:this.state.schoolClassEditReason.trim(),
                        expected_classification_version:Number(org.implementation_class_version_id||0)}:{})}:{})
            },'PROFILE_SAVE');
            if(result){this.setState({edit_code:result.code||'',edit_code_reason:'',schoolClassEditReason:''});await this.loadHierarchy();}
        };
        this.changeOwnPassword = async e => {
            e.preventDefault();
            if(this.state.own_new_password!==this.state.own_confirm_password){this.setState({error:'New passwords do not match.'});return;}
            await this.submitOrganization(e,'auth.change_password',{
                current_password:this.state.own_current_password,new_password:this.state.own_new_password
            });
            this.setState({own_current_password:'',own_new_password:'',own_confirm_password:''});
        };
        this.resetOrganizationUserPassword = async e => {
            e.preventDefault();
            if(!this.state.reset_user_id){this.setState({error:'Select the existing account to recover.'});return;}
            if(this.state.reset_new_password!==this.state.reset_confirm_password){this.setState({error:'New passwords do not match.'});return;}
            if(!this.state.reset_identity_confirmed){this.setState({error:'Confirm you verified the account owner.'});return;}
            const result=await this.submitOrganization(e,'organization.user.reset_password',{
                target_user_id:Number(this.state.reset_user_id),
                current_admin_password:this.state.reset_admin_password,
                new_password:this.state.reset_new_password,
                confirm_password:this.state.reset_confirm_password,
                identity_confirmed:true
            },'ADMIN_PASSWORD_RESET');
            // Clear all sensitive values after submission, including failed attempts.
            this.setState({reset_admin_password:'',reset_new_password:'',reset_confirm_password:'',reset_identity_confirmed:false,
                ...(result?{reset_user_id:''}:{})});
        };
        this.makeOrganizationUser = async (e, requestedRole) => {
            const prefix=requestedRole==='DIVISION'?'new_division_user':'new_school_user';
            const result=await this.submitOrganization(e,'organization.user.create',{
                role:requestedRole,
                target_organization_id:Number(requestedRole==='DIVISION'?this.state.selected_division_id:this.state.new_user_school_id),
                full_name:this.state[prefix+'_name'].trim(),
                email:this.state[prefix+'_email'].trim(),
                password:this.state[prefix+'_password']
            }, requestedRole==='DIVISION'?'DIVISION_USER':'SCHOOL_USER');
            // Clear ONLY the submitted form. A failed request preserves both drafts
            // for correction; the unrelated Division/School form is never overwritten.
            if(result)this.setState({
                [prefix+'_name']:'',
                [prefix+'_email']:'',
                [prefix+'_password']:''
            });
            return result;
        };
        this.login = async (e) => { e.preventDefault(); this.setState({ busy: true, error: '' }); try {
            const r = await this.api('auth.login', { email: this.state.loginEmail, password: this.state.loginPassword });
            this.setState({ csrf: r.csrf, loginPassword: '', sessionStatus: 'checking' }, () => this.session());
        }
        catch (e) {
            this.setState({ error: e.message });
        }
        finally {
            this.setState({ busy: false });
        } };
        this.logout = async () => { try {
            await this.api('auth.logout', {});
        }
        finally {
            this.actionRequest=(this.actionRequest||0)+1;
            this.setState({ ...initial, csrf: this.state.csrf }, () => this.session());
        } };
        // v0.2.211: Division-only live consolidation. No writes, snapshots or
        // client-issued auth tokens; every tab and page is rechecked on server.
        this.loadDivisionConsolidation = async (more=false) => {
            const org=String(this.state.organization_id||'');
            const active=this.state.orgs.find(o=>String(o.id)===org);
            if(!['DIVISION','SUPER_ADMIN'].includes(this.state.user?.role)||active?.kind!=='DIVISION'){
                this.setState({consolReport:null,consolBusy:false,consolError:'Select an authorized Division workspace.'});return;
            }
            const type=this.state.consolView;
            const school=this.state.consolSchool;
            const month=this.state.consolMonth;
            const previous=this.state.consolReport;
            const before=more && previous?.has_more ? previous.next_before_id : '';
            const token=this.consolToken=(this.consolToken||0)+1;
            this.setState({consolBusy:true,consolError:'',...(more?{}:{consolReport:null})});
            try {
                const result=await this.get('division.inventory.consolidation',{
                    organization_id:org,view:type,school_id:school,month,before_id:before
                });
                if(token!==this.consolToken||org!==String(this.state.organization_id)||this.state.tab!=='Division Consolidation')return;
                this.setState({consolBusy:false,consolReport:more&&previous?{
                    ...result,rows:[...(previous.rows||[]),...(result.rows||[])]}:result,
                    ...(school===''?{consolSchools:result.offices||result.schools||[],consolScopeId:org}:{})});
            }catch(error){
                if(token===this.consolToken&&org===String(this.state.organization_id))
                    this.setState({consolBusy:false,consolReport:null,consolError:error.message});
            }
        };
        // v0.2.212: presentation-only grouping of the existing authorized
        // v0.2.211 consolidation views. No new routes, grants or data totals.
        this.divisionConsolidationView = () => {
            const h=React.createElement;
            const active=this.state.orgs.find(o=>String(o.id)===String(this.state.organization_id));
            if(!['DIVISION','SUPER_ADMIN'].includes(this.state.user?.role)||active?.kind!=='DIVISION')
                return h('div',{className:'section-stack'},this.header('Division Inventory Consolidation','Select an authorized Division workspace first.'),
                    h('div',{className:'surface',role:'alert'},'Not available in a school-only workspace.'));
            const sections=[
                {id:'supplies',title:'Supplies & RIS',description:'Stock balances, receipts, issuances and requisitions',items:[
                    ['STOCK','Stock on Hand'],['STOCK_TOTALS','Division Supply Totals'],['STOCK_EVENT','Stock Movements'],
                    ['STOCK_EXCEPTION','Stock Exceptions'],['RIS','RIS']]},
                {id:'property',title:'Property & Accountability',description:'Accountable assets, movements, receipts and custody slips',items:[
                    ['ASSET','Current Asset Register'],['ASSET_EVENT','Asset Movements'],['ICS_PAR','ICS / PAR'],['RECEIPTS','Property Receipts']]},
                {id:'textbooks',title:'Textbooks',description:'Current textbook batches, title totals and movements',items:[
                    ['TEXTBOOK','Textbook Stocks'],['TEXTBOOK_TOTALS','Division Textbook Totals'],['TEXTBOOK_EVENT','Textbook Movements']]}
            ];
            const groups=[['OVERVIEW','Overview'],...sections.flatMap(s=>s.items)];
            const dated=['STOCK_EVENT','RIS','ASSET_EVENT','ICS_PAR','RECEIPTS','TEXTBOOK_EVENT'];
            const view=this.state.consolView;
            const currentTitle=groups.find(g=>g[0]===view)?.[1]||'Overview';
            const isDated=dated.includes(view);
            const data=this.state.consolReport;
            const options=this.state.consolSchools||[];
            const fmt=value=>value===null||value===undefined||value===''?'—':String(value);
            const chooseType=type=>{
                if(type===view)return;
                this.setState({consolView:type,consolReport:null,consolError:''},()=>this.loadDivisionConsolidation());
            };
            const tab=(code,label)=>h('button',{key:code,type:'button',role:'tab',
                'aria-selected':view===code,'aria-controls':'divcon-report-panel',
                className:'divcon-tab'+(view===code?' is-active':''),onClick:()=>chooseType(code)},label);
            const children=[this.header('Division · Consolidated Stocks and Property',
                'Live Division Office and authorized school records — with transferred assets assigned to their current owning office.')];
            children.push(h('section',{key:'filter',className:'surface divcon-filter'},
                h('div',{className:'divcon-intro'},
                    h('div',null,h('strong',{className:'divcon-intro-title'},'Consolidated records'),
                        h('p',null,'Review current office holdings and original school/Division records without changing inventory, custody or posting.')),
                    h('span',{className:'divcon-status'},'Read-only · Live data')),
                h('div',{className:'divcon-controls'},
                    h('label',{className:'divcon-control'},h('span',null,'Office'),
                        h('select',{value:this.state.consolSchool,
                            onChange:e=>this.setState({consolSchool:e.target.value,consolReport:null},()=>this.loadDivisionConsolidation())},
                            h('option',{value:''},'Division Office + authorized schools'),
                            ...options.map(o=>h('option',{key:o.id,value:String(o.id)},o.label||(o.kind==='DIVISION'?'Division Office · ':'School · ')+o.name)))),
                    h('label',{className:'divcon-control'},h('span',null,'Month · dated records only'),
                        h('input',{type:'month',value:this.state.consolMonth,disabled:!isDated,
                            title:!isDated?'Only dated document or movement reports use this filter':'Filter dates for this document or movement view',
                            onChange:e=>this.setState({consolMonth:e.target.value,consolReport:null},()=>this.loadDivisionConsolidation())})),
                    h('button',{type:'button',className:'outline divcon-refresh',disabled:this.state.consolBusy,
                        onClick:()=>this.loadDivisionConsolidation()},this.state.consolBusy?'Loading…':'Refresh data'),
                    (this.state.consolSchool||this.state.consolMonth)?h('button',{type:'button',className:'outline divcon-clear',
                        disabled:this.state.consolBusy,
                        onClick:()=>this.setState({consolSchool:'',consolMonth:'',consolReport:null},()=>this.loadDivisionConsolidation())},'Clear filters'):null),
                h('p',{className:'divcon-caveat'},isDated?
                    'Month filters this document or movement report. Stock and textbook balances remain current.':
                    'Balances are current, not a historical month-end snapshot. For date-specific records, choose a movement or document report.')));
            children.push(h('div',{key:'nav',className:'divcon-nav',role:'tablist','aria-label':'Division consolidation record categories'},
                h('div',{className:'divcon-nav-overview'},tab('OVERVIEW','Overview')),
                ...sections.map(section=>h('section',{key:section.id,className:'divcon-nav-group divcon-nav-'+section.id},
                    h('div',{className:'divcon-nav-grouphead'},h('h3',null,section.title),h('small',null,section.description)),
                    h('div',{className:'divcon-nav-items'},...section.items.map(([code,label])=>tab(code,label)))))));
            if(this.state.consolError)children.push(h('section',{key:'error',className:'surface divcon-error',role:'alert'},this.state.consolError));
            if(this.state.consolBusy)children.push(h('p',{key:'busy',className:'divcon-loading',role:'status'},'Loading authorized records…'));
            if(data){
                const offices=data.offices||data.schools||[];
                const scope=offices.length;
                const divisionOffices=offices.filter(o=>o.kind==='DIVISION').length;
                const schoolOffices=offices.filter(o=>o.kind==='SCHOOL').length;
                const scopeLabel=schoolOffices+' '+(schoolOffices===1?'school':'schools')+' + '+divisionOffices+' Division Office';
                const info=[h('div',{key:'report-title',className:'divcon-report-head'},
                    h('div',null,h('span',{className:'divcon-eyebrow'},view==='OVERVIEW'?'Division at a glance':'Consolidated register'),
                        h('h2',null,currentTitle),
                        h('p',{className:'divcon-report-note'},view==='OVERVIEW'?data.count_definition||data.note:data.note)),
                    h('div',{className:'divcon-report-meta'},
                        h('span',null,scopeLabel),
                        h('span',null,view==='OVERVIEW'?'Source record counts':isDated?'Dated record view':'Current register')))];
                if(view==='OVERVIEW'){
                    const visible=groups.filter(([code])=>code!=='OVERVIEW'&&data.counts?.[code]!==null&&data.counts?.[code]!==undefined).length;
                    info.push(h('div',{key:'overview-stat',className:'divcon-overview-strip'},
                        h('div',null,h('strong',null,String(scope)),h('span',null,'authorized offices')),
                        h('div',null,h('strong',null,String(visible)),h('span',null,'accessible registers')),
                        h('div',null,h('strong',null,'Source ledgers'),h('span',null,'no duplicate inventory'))));
                    sections.forEach(section=>{
                        const cards=section.items.map(([code,label])=>{
                            const count=data.counts?.[code];
                            const allowed=count!==null && count!==undefined;
                            const uniqueTotals=code==='STOCK_TOTALS'?'items':code==='TEXTBOOK_TOTALS'?'titles':'records';
                            return h('div',{key:code,className:'divcon-summary-card'+(allowed?'':' is-restricted')},
                                h('span',{className:'divcon-summary-name'},label),
                                allowed?h('strong',{className:'divcon-summary-value'},fmt(count)):h('strong',{className:'divcon-summary-lock'},'Restricted'),
                                h('div',{className:'divcon-summary-foot'},
                                    h('small',null,allowed?uniqueTotals:'Additional permission required'),
                                    allowed?h('button',{key:'open',type:'button',className:'divcon-card-link',
                                        onClick:()=>chooseType(code),'aria-label':'Open '+label},'Open register'):null));
                        });
                        info.push(h('section',{key:section.id,className:'divcon-overview-group'},
                            h('h3',null,section.title),h('div',{className:'divcon-overview-grid'},...cards)));
                    });
                } else {
                    const cols=data.columns||[];
                    const rows=data.rows||[];
                    const label=c=>({on_hand:'On hand',iar_number:'IAR number',accepted_qty:'Accepted qty',
                        registered_units:'Registered units',property_tag:'Property tag',school:'Holding office',origin:'Registration origin',
                        condition:'Condition',school_count:'Offices',
                        lot_count:'Lots',batch_count:'Batches',source:'Source',date:'Date'}[c]||c.replaceAll('_',' '));
                    const numeric=c=>['on_hand','change','cost','accepted_qty','registered_units','school_count','lot_count','batch_count'].includes(c);
                    info.push(h('div',{key:'table-area',className:'divcon-table-region'},
                        h('div',{className:'divcon-table-bar'},h('strong',null,rows.length+' '+(rows.length===1?'row':'rows')+' loaded'),
                            h('span',null,data.has_more?'Additional records available':'End of available records')),
                        rows.length?h('div',{className:'divcon-table-scroll',tabIndex:0,role:'region',
                            'aria-label':currentTitle+' records (horizontal scrolling if needed)'},
                            h('table',{className:'divcon-table'},
                                h('thead',null,h('tr',null,...cols.map(c=>h('th',{key:c,scope:'col',className:numeric(c)?'is-numeric':''},label(c))))),
                                h('tbody',null,...rows.map((row,index)=>h('tr',{key:String(row.id)+':'+index},
                                    ...cols.map(c=>h('td',{key:c,className:numeric(c)?'is-numeric':''},fmt(row[c])))))))):h('div',{className:'divcon-empty'},
                            h('strong',null,'No records to display'),h('p',null,'There are no records for the selected office, category and applicable month.')),
                        data.has_more?h('div',{className:'divcon-load-more'},h('button',{type:'button',className:'outline',
                            disabled:this.state.consolBusy,onClick:()=>this.loadDivisionConsolidation(true)},
                            this.state.consolBusy?'Loading more…':'Load 50 more')):null));
                }
                info.push(h('p',{key:'disclaimer',className:'divcon-disclaimer'},
                    'Division Office records and only authorized school records are included. Transferred-out historical source assets do not count as current holdings. Restricted school categories never contribute to disclosed totals. RSMI is not implemented.'));
                children.push(h('section',{key:'report',id:'divcon-report-panel',role:'tabpanel',className:'surface divcon-report',
                    'aria-label':currentTitle},...info));
            }
            return h('div',{className:'section-stack division-consolidation'},...children);
        };
        this.get = async (route, params = {}) => { const qs = new URLSearchParams({ route, organization_id: this.state.organization_id, ...params }); const res = await fetch('api/index.php?' + qs.toString(), { credentials: 'same-origin' });
            const json = await res.json().catch(() => ({ error: 'The server returned an invalid response.' }));
            if (!res.ok) { const error = new Error(json.error || 'Cannot load ' + route); error.status = res.status; throw error; }
            return json.data; };
        // Read-only workspace queries can occasionally fail while shared-host PHP
        // workers are busy. Retry transient 429/5xx/network failures once only;
        // NEVER retry POSTs or authorization / validation failures.
        this.workspaceRead = async (route, params) => {
            for (let attempt = 0; ; attempt++) {
                try { return await this.get(route, params); }
                catch (e) {
                    const status = e.status;
                    const retryable = status === undefined || status === 429 || status >= 500;
                    if (attempt > 0 || !retryable) throw e;
                    await new Promise(resolve => setTimeout(resolve, 350));
                }
            }
        };
        this.loadActionCenter = async () => {
            const org=String(this.state.organization_id||'');
            if(!org || this.state.sessionStatus!=='ready' || !this.state.user ||
                !['SUPER_ADMIN','SCHOOL','DIVISION'].includes(this.state.user.role))return;
            const uid=String(this.state.user.id);
            const request=this.actionRequest=(this.actionRequest||0)+1;
            const isCurrent=()=>request===this.actionRequest && org===String(this.state.organization_id) &&
                uid===String(this.state.user?.id||'') && this.state.sessionStatus==='ready';
            // Never retain previous office's tasks during loading.
            this.setState({actionCenterOrg:org,
                ...(this.state.actionCenterOrg===org ? {} : {actionCenterItems:[],actionCenterCount:0,actionCenterChecked:''}),
                actionCenterLoading:true,actionCenterError:''});
            try{
                const response=await fetch('api/action-center.php?organization_id='+encodeURIComponent(org),
                    {credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}});
                const result=await response.json().catch(()=>({error:'The Action Center server response was invalid.'}));
                if(!response.ok)throw new Error(result.error||'Action Center is unavailable.');
                const data=result.data||{};
                if(!isCurrent())return;
                this.setState({actionCenterOrg:org,actionCenterItems:Array.isArray(data.incoming_transfers)?data.incoming_transfers:[],
                    actionCenterCount:Number(data.pending_count)||0,actionCenterLoading:false,
                    actionCenterError:'',actionCenterChecked:String(data.checked_at||'')});
            }catch(error){
                if(isCurrent())this.setState({actionCenterItems:[],actionCenterCount:0,actionCenterLoading:false,
                    actionCenterError:String(error.message||'Unable to load pending work. Try Refresh.')});
            }
        };
        this.refresh = async () => {
            const orgId = String(this.state.organization_id || '');
            if (!orgId) return;
            const version = this.workspaceRequest = (this.workspaceRequest || 0) + 1;
            const kinds = (this.state.user?.role==='VIEWER' ? ['pr','rfq','quotes','awards','po','iar','dv','funds','banks','bank_downloads','checks','suppliers','items','units','all_items','atcs','atc_rates'] : schoolScopedDivision(this.state) ? ['pr','rfq','quotes','awards','po','iar','dv','ris','funds','banks','bank_downloads','checks','suppliers','items','units','all_items','atcs','atc_rates'] : ['pr','rfq','quotes','awards','po','iar','dv','ris','funds','banks','bank_downloads','stocks','stock_exceptions','checks','suppliers','items','units','all_items','atcs','atc_rates']);
            // Avoid simultaneous bursts of 20 PHP/MySQL requests on shared hosting.
            // Keep existing successful data only when it belongs to this workspace.
            const sameWorkspace = this.lastWorkspaceOrg === orgId;
            const previousLists = sameWorkspace ? this.state.lists : {};
            const requests = [...kinds.map(k => () => this.workspaceRead('list', { kind: k, organization_id: orgId })),
                () => this.workspaceRead('dashboard', { organization_id: orgId }),
                () => this.workspaceRead('accounts', { organization_id: orgId }),
                () => this.workspaceRead('division.classifications', { organization_id: orgId })];
            const results = [];
            for (let i = 0; i < requests.length; i += 2) {
                results.push(...await Promise.allSettled(requests.slice(i, i + 2).map(request => request())));
                if (version !== this.workspaceRequest || String(this.state.organization_id) !== orgId) return;
            }
            const data = {}, failures = [];
            kinds.forEach((k, i) => {
                if (results[i].status === 'fulfilled') data[k] = results[i].value;
                else { data[k] = previousLists[k] || []; failures.push(k); }
            });
            const dashboard = results[kinds.length].status === 'fulfilled' ? results[kinds.length].value : (sameWorkspace ? this.state.dashboard : {});
            if (results[kinds.length].status === 'rejected') failures.push('dashboard');
            const accounts = results[kinds.length + 1].status === 'fulfilled' ? results[kinds.length + 1].value : (sameWorkspace ? this.state.accounts : []);
            if (results[kinds.length + 1].status === 'rejected') failures.push('accounts');
            const divisionResult=results[kinds.length+2];
            const classes=divisionResult.status==='fulfilled'?divisionResult.value:null;
            if(!classes)failures.push('division classifications');
            const backgroundRefs=['atc_rates','atcs','division classifications'];
            const otherFailures=failures.filter(x=>!backgroundRefs.includes(x));
            this.lastWorkspaceOrg=orgId;
            this.setState({ lists: data, dashboard, accounts,
                divisionClassifications: classes?.categories||(sameWorkspace?this.state.divisionClassifications:[]),
                divisionName: classes?.division_name||(sameWorkspace?this.state.divisionName:''),
                workspaceLoading: false, workspaceFailures: failures,
                error: otherFailures.length ? 'Some workspace data could not load (' + otherFailures.join(', ') + '). Please refresh.' : '' });
            // The DV source list is only useful on the Disbursements page. Never
            // start this potentially expensive query from an unrelated workspace tab.
            if (this.state.tab === 'Disbursements' && this.state.user?.role!=='VIEWER') {this.loadEligible(this.state.dv_type, orgId);this.loadFundPolicySettings();}
        };
        this.loadEligible = async (type, requestedOrg) => {
            const orgId = String(requestedOrg || this.state.organization_id || '');
            if (!orgId || this.state.tab !== 'Disbursements') return;
            const version = this.eligibleRequest = (this.eligibleRequest || 0) + 1;
            const isCurrent = () => version === this.eligibleRequest && String(this.state.organization_id) === orgId &&
                this.state.dv_type === type && this.state.tab === 'Disbursements';
            this.setState({ eligibleLoading: true, eligibleError: '', eligibleLoadedFor: '', eligible: [], allocations: {}, dv_po: '', dv_po_tax_profile: '', dvInvoiceVat:'',dvTaxPreview:null,dvTaxBusy:false,dvTaxError:'', dvTaxes: [] });
            try {
                let records;
                // Read-only retry, once, for transient server errors. Never retry
                // permission/validation errors or stale workspace requests.
                for (let attempt = 0; attempt < 2; attempt++) {
                    try { records = await this.get('eligible', { type, organization_id: orgId,
                            // New interface intentionally displays the existing DV separately,
                            // outside the selectable PO list. Legacy clients see only unused POs.
                            ...(type === 'REGULAR' ? { include_existing: '1' } : {}) }); break; }
                    catch (e) {
                        if (attempt !== 0 || !(e.status >= 500) || !isCurrent()) throw e;
                        await new Promise(resolve => setTimeout(resolve, 400));
                        if (!isCurrent()) return;
                    }
                }
                if (!isCurrent()) return;
                this.setState({ eligible: Array.isArray(records) ? records : [], eligibleLoading: false,
                    eligibleError: '', eligibleLoadedFor: orgId + ':' + type, allocations: {}, dv_po: '', dv_po_tax_profile: '', dvInvoiceVat:'',dvTaxPreview:null,dvTaxBusy:false,dvTaxError:'', dvTaxes: [] });
            } catch (e) {
                if (isCurrent()) this.setState({ eligibleLoading: false, eligible: [], eligibleLoadedFor: '',
                    eligibleError: 'Voucher references could not load: ' + (e.message || 'Request failed.') });
            }
        };
        // Reporting scopes can be broader than operational write authority.
        this.canOperateWorkspace = () => {
            const org=this.state.orgs.find(o=>String(o.id)===String(this.state.organization_id));
            const role=this.state.user?.role;
            return !!org && (role==='SUPER_ADMIN'||(role==='SCHOOL'&&org.kind==='SCHOOL')||
                (role==='DIVISION'&&org.kind==='DIVISION'));
        };
        this.orgChange = (e) => {
            const orgId = String(e.target.value);
            if (this.state.busy || this.state.mooeBusy || this.staffRequestInFlight || this.state.workspaceLoading || orgId === String(this.state.organization_id)) return;
            const target = this.state.orgs.find(o => String(o.id) === orgId);
            if (!target) { this.setState({ error: 'This organization is not available to your account.' }); return; }
            if (this.state.prCreateOpen && this.state.prCreateBaseline !== this.prCreateSnapshot() &&
                !window.confirm('Switching workspace will discard the unsaved Purchase Request. Continue?')) return;
            // Invalidate previous requests before changing context; never mix school records.
            this.workspaceRequest = (this.workspaceRequest || 0) + 1;
            this.eligibleRequest = (this.eligibleRequest || 0) + 1;
            this.staffIdentityRequest = (this.staffIdentityRequest || 0) + 1;
            this.mooeLoadToken = (this.mooeLoadToken || 0) + 1;
            this.rememberWorkspace(this.state.user, orgId);
            this.divisionGrantToken=(this.divisionGrantToken||0)+1;
            this.divisionContextToken=(this.divisionContextToken||0)+1;
            this.consolToken=(this.consolToken||0)+1;
            this.actionRequest=(this.actionRequest||0)+1;
            this.setState({ organization_id: orgId, tab: 'Dashboard', actionCenterOrg:'',actionCenterItems:[],actionCenterCount:0,actionCenterLoading:false,actionCenterError:'',actionCenterChecked:'', lists: {}, dashboard: {}, mooeIssuedDocs:null,mooeIssuedError:'',mooeIssuedBusy:false,
                accounts: [], eligible: [], eligibleLoading: false, eligibleError: '', eligibleLoadedFor: '', workspaceFailures: [], divisionClassifications: [], divisionName: '', allocations: {}, details: null, viewType: '', uacsData: {rows:[],total:0,offset:0,limit:80,summary:{total:0,enabled:0,disabled:0}},
                fund_id: '', dv_fund: '', selectedDv: '', ris_pr: '', ris_id: '', ris_line_id: '', ris_lot_id: '', divisionRisLines:[], divisionRisLots:[], consolReport:null,consolBusy:false,consolError:'',consolSchool:'',consolSchools:[],consolScopeId:'',propertyRecords:{assets:[],accountability:[],received_property:[]},propertyRegister:{rows:[],facets:{items:[],classifications:[],custodians:[],statuses:[]},has_more:false,next_before_id:null},propertyRegisterFilters:{search:'',category:'ALL',classification:'ALL',item_id:'ALL',custodian:'ALL',status:'ALL',source:'ALL'},propertyRegisterLoading:false,propertyRegisterError:'',propertyDetailId:'',propertyDetailOrg:'',propertyDetail:null,propertyDetailLoading:false,propertyDetailError:'',propertyDetailTab:'overview',propertyDetailBusy:false,propertyDetailEditShared:{},propertyDetailEditUnit:{},propertyDetailEditReason:'',propertyDetailEditMode:'UNIT',propertyAssetId:'',propertyAssetIds:[],propertyCustodian:'',propertyCustodianSearch:'',propertyCustodianEmployeeId:'',propertyReceivedId:'',propertyBatchItem:'',propertyBatchCount:'1',propertyBatchDate:'',propertyBatchCost:'',propertyRegistrationNotice:'',propertyBatchUnits:[{}],propertyBatchShared:{brand:'',model:'',acquisition_type:'PURCHASE',useful_life_years:'',historical_po:'',historical_iar:'',historical_dv:'',evidence_note:''},propertyEditAssetId:'',propertyEditPreview:null,propertyEditShared:{},propertyEditUnit:{},propertyEditReason:'',propertyConfirmRegistration:false,divisionModuleGrants:null,divisionModuleGrantsBusy:false,divisionModuleGrantsError:'',divisionContextRegister:null,divisionContextBusy:false,divisionContextError:'',
                ris_item: '', stock_item: '', purpose: '', requesting_unit: '',
                prLines: [{ item_id: '', quantity: '1', unit_price: '0.00' }],pr_requestor:'', bank_download_open:false,bank_download_fund:'',bank_download_bank:'',bank_download_existing_id:'',staffData:null,staffLoading:false,mooeData:null,mooeViewerEmail:'',mooeViewerPassword:'',mooeViewerBusy:false,mooeLoading:false,mooeError:'',mooeSchoolId:'',mooeEmployeeId:'',mooeDate:localToday(),mooeBusy:false,mooeNotice:'',mooeHistoricalConfirmed:false,mooeAppointmentReference:'',staffSavingAction:'',staffSavedMessage:'',staffError:'',staffAddOpen:false,staffAddOrg:'',staffFullName:'',staffPosition:'',staffDesignation:'',staffEmployeeNo:'',staffTIN:'',staffAddress:'',staffProfileId:'',staffProfileOrg:'',staffProfileName:'',staffProfileEmployeeNo:'',staffProfileTIN:'',staffProfileAddress:'',staffProfileVersion:0,staffProfileBusy:false,staffEditName:'',staffEditPosition:'',staffEditDesignation:'',staffEditReason:'',staffEditOriginal:null,staffEditProfileOriginal:null,staffShowInactive:false,staffRenameId:'',staffRenameName:'',staffRenameReason:'',staffHeadId:'',staffDvPartA:'',staffDvPartC:'',staffDvPartD:'',staffHeadReason:'',staffCustodianId:'',staffCustodianDate:localToday(),staffCustodianReason:'',staffCustodianAttested:false,staffChair:'',staffCoChair:'',staffMembers:[],staffBacReason:'',staffBacDate:'',staffBacDateReason:'',staffBacAttested:false,travelStage:'REQUEST',travelRequestFormOpen:false,travelDocuments:{requests:[],authorities:[]},travelClaims:{claims:[],required_evidence:{},cost_types:{}},travelClaimsLoading:false,travelClaimError:'',travelClaimUploadKind:'',travelClaimDetail:null,travelClaimEditing:false,travelClaimDraft:emptyTravelClaim(),travelClaimChecks:emptyTravelVerification(),travelLoading:false,travelError:'',travelSignatories:null,travelSignatoriesLoading:false,travelSignatoriesError:'',travelBranding:null,travelBrandingLoading:false,travelBrandingError:'',travelBrandingPending:null,travelBrandingReason:'',travelSignatoryIds:{budget_officer:'',division_accountant:'',asds:'',sds:''},travelSignatoryRegion:'',travelSignatorySalutation:'Sir/Madam:',travelSignatoryReason:'',travelRefreshSignatoryId:'',travelRefreshSignatoryReason:'',travelRequest:emptyTravelRequest(),travelEditId:'',travelEditVersion:0,travelEditReason:'',travelAuthorityRequestId:'',travelAuthorityDate:localToday(),travelApprovalReference:'',travelPaperApproved:false,prUnits:[],prUnitsVersion:0,prUnitsLoading:false,prUnitsError:'',prUnitsDraft:'',prUnitsReason:'',prRegisterMonth:'',prRegisterYear:'',workflowRegisterMonth:localToday().slice(5,7),workflowRegisterYear:localToday().slice(0,4),workflowExpandedQuotes:[],workflowFormOpen:false,prCreateOpen:false,prCreateBaseline:'',prCreateError:'',dvComposeOpen:false,dvSearch:'',fundPolicies:null,itemUacsSettings:null,vatSettings:null,itemUacsId:'',itemUacsCode:'',itemUacsReason:'',itemUacsDialog:false,bulkUacsOpen:false,bulkUacsPreview:null,bulkUacsIds:[],bulkUacsClass:'',bulkUacsReason:'', fund_allocation_open:false, prEditOpen:false, prEdit:null, prEditError:'', dvEdit:null,dvEditOriginal:'',dvEditError:'',dvParticularsEdit:null,dvParticularsError:'',dvCancel:null,dvCancelError:'',dvFinalizePreview:null,dvFinalizeDate:'',dvCheckBank:'',dvCheckStock:null,dvCheckLoading:false,checkStockPreview:null,checkLeafConfirmed:false,checkStockList:{rows:[],stats:[]},checkStockLoaded:false,checkStockLoading:false,checkStockError:'',cashBankSection:'prepare',checkProofIssued:null,cashCheckSearch:'',cashCheckStatus:'ALL',cashLeafSearch:'',cashLeafStatus:'ALL',checkBank:'',checkDv:'',checkNo:'',checkbookBank:'',checkbookFirst:'',checkbookLast:'',checkbookReference:'',checkbookVerified:false,dvVoidPreview:null,dvActionError:'',prPickerOpen:'', prPickerResults:[], prQuickAdd:null, prQuickAddError:'',
                searchResults: [],procurementSection:'PROCESS',workflowStage:'RFQ',workflowExpandedQuotes:[],workflowPR:'',workflowRfqDate:'',workflowCanvasserId:'',workflowCanvasserHistoricalAttested:false,workflowCanvasserHistoricalReference:'',workflowChairHistoricalAttested:false,workflowChairHistoricalReference:'',workflowConsolidate:null,workflowConsolidateLoading:false,workflowConsolidateError:'',workflowConsolidatePrIds:[],workflowConsolidateExcluded:[],workflowRFQEditId:'',workflowRFQEditDate:'',workflowRFQEditLines:[],workflowRFQEditVersion:0,workflowRFQEditReason:'',workflowRFQEditConfirm:false,workflowRFQEditError:'',workflowRFQEditLoading:false,workflowRFQ:'',workflowSupplier:'',workflowQuoteDate:localToday(),workflowQuoteEvidence:'',workflowQuoteLines:[],workflowAwardRFQ:'',workflowAwardDate:localToday(),workflowQuoteId:'',workflowAwardNote:'',workflowAwardNoteManual:false,workflowAwardApproved:false,workflowAward:'',workflowPoDate:localToday(),workflowPoLines:[],workflowPoDeliveryDate:localToday(),workflowPoDeliveryPlace:'',workflowPoDeliveryTerm:'Door-to-Door Delivery',workflowPoPaymentTerm:'Full Payment upon Delivery',workflowPoPaymentType:'Check',workflowPoMethod:'',workflowPoEdit:null,workflowPoEditError:'',workflowPoEditLoading:false,workflowPO:'',workflowIARLines:[],workflowDelivered:'',workflowAccepted:'',workflowIARInvoice:'',workflowIARInvoiceDate:'',workflowIARPoMeta:null,workflowIarDate:localToday(),workflowIARReviewed:false,workflowIarEdit:null,workflowIarEditLoading:false,workflowIarEditError:'',workflowMessage:'',workflowError:'', success: '', error: '', workspaceLoading: true, atc_dialog: '', atc_selected_code: '', atc_action: '' }, () => this.refresh());
        };
        this.set = (key) => (e) => this.setState({[key]:e.target.value,
            ...(key==='item_category'?{item_school_class:'',item_subcategory:''}:{}),
            ...(key==='classAppliesTo'?{classUacs:''}:{}),
            error:'',success:''});
        // Procurement create dialogs must surface submission errors inside the
        // active dialog. Focus its alert so the message is visible even when
        // the form has been scrolled to the bottom (e.g. IAR quantities).
        this.workflowShowError = message => {
            this.setState({workflowError:String(message||'The document could not be saved.')},()=>{
                if(!this.state.workflowFormOpen)return;
                const alert=document.getElementById('workflow-create-error');
                if(alert){alert.scrollIntoView({block:'nearest'});alert.focus({preventScroll:true});}
            });
        };
        this.task = async (route, data, options = {}) => { this.setState({ busy: true, error: '', success: '' }); try {
            const result = await this.api(route, { organization_id: Number(this.state.organization_id), ...data });
            this.setState(options.errorTarget==='workflow'
                ? {details:result}
                : {success:'Saved successfully' + (result.number ? ' · ' + result.number : ''),details:result});
            // Reference-data maintenance refreshes only its affected lists. A full
            // 18-request workspace refresh during an ATC write overwhelmed shared hosting
            // and was immediately followed by two more ATC reads.
            if (options.refresh !== false) await this.refresh();
            return result;
        }
        catch (e) {
            if(options.errorTarget==='workflow')this.workflowShowError(e.message);
            else this.setState({ error: e.message });
            return null;
        }
        finally {
            this.setState({ busy: false });
        } };
        // Reuse the PR detail/edit dialog pattern for creating new requests.
        // A baseline snapshot prevents accidentally discarding unsaved fields.
        this.prCreateSnapshot = () => JSON.stringify({
            fund_id:this.state.fund_id,pr_doc_date:this.state.pr_doc_date,
            requesting_unit:this.state.requesting_unit,pr_requestor:this.state.pr_requestor,purpose:this.state.purpose,
            pr_method:this.state.pr_method,
            lines:this.state.prLines.map(x=>({item_id:x.item_id,quantity:x.quantity,unit_price:x.unit_price}))
        });
        this.openCreatePR = () => {
            if(this.state.busy || !this.canOperateWorkspace() || this.state.workspaceLoading)return;
            this.prCreateReturnFocus=document.activeElement;
            this.setState({prCreateOpen:true,prCreateError:'',prCreateBaseline:this.prCreateSnapshot()},()=>{
                this.loadStaffDirectory();
                requestAnimationFrame(()=>document.querySelector('.pr-create-modal input[type=date]')?.focus());
            });
        };
        this.closeCreatePR = () => {
            if(this.state.busy)return false;
            if(this.state.prCreateBaseline!==this.prCreateSnapshot() &&
               !window.confirm('Discard the unsaved Purchase Request changes?'))return false;
            this.setState({prCreateOpen:false,prCreateBaseline:'',prCreateError:'',prPickerOpen:'',prPickerResults:[]},()=>{
                if(this.prCreateReturnFocus && document.contains(this.prCreateReturnFocus))this.prCreateReturnFocus.focus();
                this.prCreateReturnFocus=null;
            });
            return true;
        };
        this.prCreateKeyDown = event => {
            if(event.key==='Escape'){
                event.preventDefault();event.stopPropagation();this.closeCreatePR();return;
            }
            if(event.key!=='Tab')return;
            const nodes=[...event.currentTarget.querySelectorAll('button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),a[href],[tabindex]:not([tabindex="-1"])')]
                .filter(el=>el.getClientRects().length>0);
            if(!nodes.length){event.preventDefault();return;}
            const first=nodes[0],last=nodes[nodes.length-1];
            if(event.shiftKey && (document.activeElement===first || !event.currentTarget.contains(document.activeElement))){
                event.preventDefault();last.focus();
            }else if(!event.shiftKey && (document.activeElement===last || !event.currentTarget.contains(document.activeElement))){
                event.preventDefault();first.focus();
            }
        };
        this.createPR = async (e) => {
            e.preventDefault();
            if (this.state.busy || this.state.workspaceLoading || !this.canOperateWorkspace()) return;
            const s = this.state;
            const funds = s.lists.funds || [];
            const completed=s.prLines.filter((x,i)=>!(i===s.prLines.length-1&&!x.item_id&&!Number(x.unit_price)));
            const lines = completed.map(x => ({item_id: Number(x.item_id), quantity: x.quantity, unit_price: x.unit_price}));
            let issue = '';
            if (!s.organization_id || !funds.some(f => String(f.id) === String(s.fund_id)))
                issue = 'Select an available source of fund before saving.';
            else if (!validPrDate(s.pr_doc_date))
                issue = 'Select the document date for this PR.';
            else if (!(s.staffData?.employees||[]).some(x=>x.active && String(x.employee_id)===String(s.pr_requestor)))
                issue = 'Choose an active requesting employee from this school or Division Office. Configure Employees in Reference Data first.';
            else if (!s.requesting_unit.trim() || !s.purpose.trim())
                issue = 'Enter the requesting unit and purpose of this purchase request.';
            else if (!lines.length || lines.some(x => !Number.isSafeInteger(x.item_id) || x.item_id <= 0))
                issue = 'Select a catalog item for every line.';
            else if (lines.some(x => !Number.isFinite(Number(x.quantity)) || Number(x.quantity) <= 0 || !Number.isFinite(Number(x.unit_price)) || Number(x.unit_price) <= 0))
                issue = 'Each item needs a quantity and an estimated unit price greater than zero.';
            if (issue) { this.setState({prCreateError:issue}); return; }
            const owner=s.orgs.find(o=>String(o.id)===String(s.organization_id));
            const fund=funds.find(f=>String(f.id)===String(s.fund_id));
            if (!owner || !fund) {this.setState({prCreateError:'Workspace/fund is not verified. Refresh before saving.'});return;}
            const total=s.prLines.reduce((n,x)=>n+(Number(x.quantity)||0)*(Number(x.unit_price)||0),0);
            const confirmText='CONFIRM PURCHASE REQUEST WORKSPACE\n\n'+
                (owner.kind==='DIVISION'?'DIVISION OFFICE':'SCHOOL')+': '+owner.name+'\n'+
                'Source of fund: '+fund.name+' ('+fund.source_code+')\n'+                'Requestor: '+(s.staffData?.employees||[]).find(x=>String(x.employee_id)===String(s.pr_requestor))?.full_name+'\n'+
                'Estimated amount: ₱'+total.toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2})+'\n'+
                'This PR will belong only to the organization above. It will not appear in another workspace.\n\nSave as draft?';
            if (!window.confirm(confirmText)) return;
            if (String(this.state.organization_id)!==String(owner.id) || !this.canOperateWorkspace()) {
                this.setState({prCreateError:'Workspace changed. Review the PR again.'});return;
            }
            this.setState({prCreateError:''});
            const result = await this.task('pr.create', {purpose:s.purpose.trim(),requesting_unit:s.requesting_unit.trim(),requestor_employee_id:Number(s.pr_requestor),
                fund_id:Number(s.fund_id),document_date:s.pr_doc_date,method:s.pr_method,lines});
            if (result) this.setState({purpose:'',requesting_unit:'',pr_requestor:'',pr_doc_date:localToday(),prLines:[{item_id:'',quantity:'1',unit_price:'0.00'}],
                prCreateOpen:false,prCreateBaseline:'',prCreateError:''});
        };
        this.finalizePR = async (id) => {
            if(!this.canOperateWorkspace()) {this.setState({error:'Read-only oversight: school and Division transactions are independent.'});return;}
            const ok = window.confirm('Finalize this Purchase Request in the ACTIVE organization and assign its permanent control number? Manual approval/signature remains required.');
            if (!ok) return;
            await this.task('pr.finalize', { pr_id: Number(id) });
        };
        this.createInitialOrganization = async (e) => {
            e.preventDefault();
            if (this.state.busy || this.state.user?.role !== 'SUPER_ADMIN') return;
            this.setState({ busy: true, error: '', success: '' });
            try {
                await this.api('organization.bootstrap', {
                    code: this.state.setup_code.trim().toUpperCase(),
                    name: this.state.setup_name.trim(),
                    kind: 'DIVISION'
                });
                await this.session();
                this.setState({success:'Organization created. You may now manage Reference Data.'});
            } catch (e) {
                this.setState({error:e.message});
            } finally {
                this.setState({busy:false});
            }
        };
        this.createMaster = async (e) => {
            e.preventDefault();
            const section = this.state.tab==='Fund Management' && this.state.fundSection==='funds' ? 'funds' : this.state.masterSection;
            const forms = {
                suppliers: [this.state.sup_edit_id?'master.supplier.update':'master.supplier.create', { supplier_id: Number(this.state.sup_edit_id)||undefined, name: this.state.sup_name.trim(), proprietor_name:this.state.sup_commercial==='INDIVIDUAL'?this.state.sup_proprietor.trim():'', tax_id: this.state.sup_tax_id.trim(), address: this.state.sup_address.trim(), zip_code: this.state.sup_zip.trim(), business_classification: this.state.sup_commercial, vat_status: this.state.sup_vat_status,
                    tax_profiles:this.state.sup_profiles.map(profile=>({profile_type:profile.supply_type,
                        vat_atc_codes:profile.vat_atc_codes,ewt_atc_codes:profile.ewt_atc_codes})),
                    legacy_atc_codes:this.state.sup_legacy_atcs }],
                units: ['master.unit.create', { code: this.state.unit_code.trim(), name: this.state.unit_name.trim() }],
                items: this.state.item_edit_id
                    ? ['master.item.subcategory', {item_id:Number(this.state.item_edit_id),subcategory:this.state.item_subcategory}]
                    : ['master.item.create', { name: this.state.item_name.trim(), category: this.state.item_category, subcategory: this.state.item_category==='SEMI_EXPENDABLE'?this.state.item_subcategory:'', division_class_code:this.state.item_category==='SEMI_EXPENDABLE'?this.state.item_subcategory:this.state.item_school_class, school_class_code:this.state.item_category==='SUPPLY'?this.state.item_school_class:'', unit_id: Number(this.state.item_unit_id) }],
                funds: ['master.fund.create', { name: this.state.fund_name.trim(), source_code: this.state.fund_source_code.trim(), fiscal_year: Number(this.state.fund_fiscal_year) }]
            };
            const [route,payload] = forms[section];
            const autoMappedNewItem=section==='items'&&!this.state.item_edit_id;
            const result=await this.task(route,payload);
            if(result&&autoMappedNewItem)await this.loadDivisionItemUacs();
            if (result) this.setState({masterEditorOpen:false,sup_name:'',sup_proprietor:'',sup_tax_id:'',sup_address:'',sup_zip:'',sup_edit_id:'',sup_atcs:[],sup_profiles:[],sup_legacy_atcs:[],sup_commercial:'UNSPECIFIED',sup_vat_status:'UNKNOWN',unit_code:'',unit_name:'',item_name:'',item_subcategory:'',item_school_class:'',item_edit_id:'',fund_name:'',fund_source_code:''});
        };
        this.saveFundAllocation = async (e) => {
            e.preventDefault();
            if(!['SUPER_ADMIN','DIVISION'].includes(this.state.user?.role))return;
            if(!window.confirm('Confirm this source-of-fund ledger action? It will be permanent and auditable.'))return;
            const result=await this.task('fund.event',{fund_id:Number(this.state.fund_allocation_id),event_type:this.state.fund_allocation_type,amount:this.state.fund_allocation_amount,
                reference:this.state.fund_allocation_reference.trim(),reason:this.state.fund_allocation_reason.trim()});
            if(result)this.setState({fund_allocation_open:false,fund_allocation_amount:'',fund_allocation_reference:'',fund_allocation_reason:''});
        };
        this.saveBankDownload = async (e) => {
            e.preventDefault();
            if(!['SUPER_ADMIN','DIVISION'].includes(this.state.user?.role))return;
            const s=this.state;
            const existing=s.bank_download_mode==='LINK';
            const original=(s.lists.bank_downloads||[]).find(r=>String(r.id)===String(s.bank_download_existing_id));
            if(existing && (!original || original.bank_account_id!==null)){
                this.setState({error:'The original allotment is missing or already linked. Refresh the journal.'});return;
            }
            const prompt=existing
              ? 'Link this VERIFIED bank credit to existing allotment #'+original.id+' (₱'+original.amount+')? NO additional allocation will be created.'
              : 'Confirm the money was credited to the selected bank account? This posts ONE allocation and its linked bank receipt.';
            if(!window.confirm(prompt))return;
            const payload={bank_account_id:Number(s.bank_download_bank),bank_credit_date:s.bank_download_date,
                bank_credit_ref:s.bank_download_ref.trim(),bank_advice_ref:s.bank_download_advice.trim(),bank_evidence_ref:s.bank_download_evidence.trim()};
            if(existing){payload.fund_event_id=Number(s.bank_download_existing_id);payload.link_reason=s.bank_download_link_reason.trim();}
            else {payload.fund_id=Number(s.bank_download_fund);payload.amount=s.bank_download_amount;payload.particulars=s.bank_download_particulars.trim();}
            const result=await this.task(existing?'bank.download.link_existing':'bank.download.record',payload);
            if(result)this.setState({bank_download_open:false,bank_download_amount:'',bank_download_ref:'',bank_download_advice:'',bank_download_evidence:'',bank_download_particulars:'',bank_download_link_reason:'',bank_download_existing_id:''});
        };
        this.openBankDownload=(mode='NEW',record=null)=>this.setState({
            bank_download_open:true,bank_download_mode:mode,bank_download_existing_id:record?String(record.id):'',
            bank_download_fund:record?String(record.fund_id):'',bank_download_bank:'',bank_download_date:localToday(),
            bank_download_amount:record?String(record.amount):'',bank_download_ref:'',bank_download_advice:'',
            bank_download_evidence:'',bank_download_particulars:'',bank_download_link_reason:''},()=>{
                requestAnimationFrame(()=>document.querySelector('[aria-label="Record bank download"] select, [aria-label="Record bank download"] input')?.focus());
            });
        this.saveBankAccount = async (e) => {
            e.preventDefault();
            const result=await this.task('bank.create',{bank_name:this.state.bank_name.trim(),account_label:this.state.bank_label.trim(),masked_account:this.state.bank_mask.trim()});
            if(result)this.setState({bank_name:'',bank_label:'',bank_mask:''});
        };
        this.editItemSubcategory = (record) => {
            if(record.category!=='SEMI_EXPENDABLE') return;
            this.setState({masterSection:'items',item_edit_id:String(record.id),item_name:record.name||'',
                item_category:'SEMI_EXPENDABLE',item_unit_id:String(record.unit_id),
                item_subcategory:record.subcategory||'',masterEditorOpen:true,error:'',success:'',masterSearch:''},()=>{
                    document.getElementById('reference-master-editor')?.scrollIntoView({behavior:'smooth',block:'start'});
                });
        };
        this.cancelItemEdit = () => this.setState({masterEditorOpen:false,item_edit_id:'',item_name:'',item_subcategory:'',item_category:'SUPPLY',item_unit_id:''});
        this.changeMasterStatus = async (kind, record) => {
            if (!window.confirm((record.active ? 'Deactivate' : 'Reactivate') + ' this ' + (kind === 'supplier' ? 'supplier' : 'item') + '? Historical documents remain unchanged.')) return;
            const route = kind === 'supplier' ? 'master.supplier.status' : 'master.item.status';
            const key = kind === 'supplier' ? 'supplier_id' : 'item_id';
            await this.task(route, { [key]: Number(record.id), active: !Number(record.active) });
        };
        this.loadPrRequestingUnits = async () => {
            const org=String(this.state.organization_id||'');
            if(!org)return;
            const token=this.prUnitsRequest=(this.prUnitsRequest||0)+1;
            this.setState({prUnitsLoading:true,prUnitsError:'',prUnits:[],prUnitRecords:[],prUnitsVersion:0});
            try {
                const result=await this.get('requesting.units',{organization_id:org});
                if(token!==this.prUnitsRequest||String(this.state.organization_id)!==org)return;
                this.setState({prUnits:result.units||[],prUnitRecords:result.records||[],prUnitsVersion:Number(result.version_id||0),prUnitsLoading:false,
                    prUnitsReason:''});
            }catch(e){
                if(token===this.prUnitsRequest&&String(this.state.organization_id)===org)
                    this.setState({prUnitsError:e.message||'Unable to load requesting units',prUnitsLoading:false});
            }
        };
        this.changePrRequestingUnit = async (action,recordId='') => {
            const name=(this.state.prUnitName||'').trim(),reason=(this.state.prUnitsReason||'').trim();
            if((action!=='ADD'&&!reason) || (['ADD','RENAME'].includes(action)&&!name)){
                this.setState({prUnitsError:'Enter the required department name or reason.'});return;
            }
            const selected=(this.state.prUnitRecords||[]).find(x=>x.id===recordId);
            const label=selected?.name||name;
            if(!window.confirm((action==='ADD'?'Add ':action==='RENAME'?'Rename ':action==='DEACTIVATE'?'Deactivate ':'Reactivate ')+label+'? Other departments and historical PRs will remain unchanged.'))return;
            const org=String(this.state.organization_id);
            const result=await this.task('requesting.units.change',{action,name,record_id:recordId,
                expected_version:this.state.prUnitsVersion,reason},{refresh:false});
            if(result&&org===String(this.state.organization_id)){
                this.setState({prUnitEdit:'',prUnitName:'',prUnitsReason:'',prUnitsError:''});
                await this.loadPrRequestingUnits();
            }
        };
        // Approved full-width image strips; these replace, rather than layer
        // over, the normal printed travel form header and footer.
        this.loadTravelBranding = async () => {
            const org=String(this.state.organization_id||'');if(!org||this.state.travelBrandingLoading)return;
            this.setState({travelBrandingLoading:true,travelBrandingError:''});
            try{const data=await this.get('travel.branding.settings',{organization_id:org});
                if(String(this.state.organization_id)===org)this.setState({travelBranding:data,travelBrandingLoading:false});
            }catch(error){if(String(this.state.organization_id)===org)this.setState({travelBrandingLoading:false,travelBrandingError:error.message});}
        };
        this.prepareTravelBranding = (file,slot) => {
            if(!file)return;
            this.setState({travelBrandingError:'',travelBrandingPending:null});
            if(!['image/png','image/jpeg'].includes(file.type)||file.size>614400){
                this.setState({travelBrandingError:'Select a PNG or JPEG letterhead strip of at most 600 KB.'});return;
            }
            const org=String(this.state.organization_id);
            const reader=new FileReader();
            reader.onerror=()=>this.setState({travelBrandingError:'The selected image could not be read.'});
            reader.onload=()=>{
                if(String(this.state.organization_id)!==org)return;
                const data=typeof reader.result==='string'?reader.result:'';
                const encoded=data.split(',')[1]||'';
                if(!encoded){this.setState({travelBrandingError:'Unable to read selected artwork.'});return;}
                this.setState({travelBrandingPending:{slot,image_base64:encoded,file_name:file.name,
                    preview:data},travelBrandingError:''});
            };
            reader.readAsDataURL(file);
        };
        this.saveTravelBranding = async (e) => {
            e.preventDefault();const s=this.state,d=s.travelBranding,selected=s.travelBrandingPending;
            if(!d?.can_manage||!selected||!s.travelBrandingReason.trim())return;
            const outcome=await this.task('travel.branding.save',{
                organization_id:Number(s.organization_id),expected_version:d.version_id,
                action:'UPLOAD',slot:selected.slot,image_base64:selected.image_base64,
                reason:s.travelBrandingReason.trim()
            },{refresh:false});
            if(outcome){this.setState({travelBrandingPending:null,travelBrandingReason:'',
                success:'Approved '+selected.slot+' artwork saved for this workspace. Reopen travel print to check A4 alignment.'});
                await this.loadTravelBranding();}
        };
        this.removeTravelBranding = async slot => {
            const s=this.state,d=s.travelBranding;
            if(!d?.can_manage||!d[slot])return;
            const reason=window.prompt('Reason for removing the '+slot+'? The prior artwork remains in immutable history.');
            if(!reason||!reason.trim())return;
            if(!window.confirm('Return this organization’s '+slot+' to the standard printed fallback?'))return;
            const outcome=await this.task('travel.branding.save',{
                organization_id:Number(s.organization_id),expected_version:d.version_id,
                action:'REMOVE',slot,reason:reason.trim()
            },{refresh:false});
            if(outcome){this.setState({travelBrandingPending:null,success:'Letterhead '+slot+' reset to fallback.'});
                await this.loadTravelBranding();}
        };
        this.travelBrandingView = () => {
            const h=React.createElement,s=this.state,d=s.travelBranding,canManage=!!d?.can_manage;
            const tabs=[['suppliers','Suppliers'],['items','Item Catalog'],['requesting_units','Requesting Units'],
                ['employees','Employees'],['officials','BAC & Heads'],['travel_signatories','Division Travel Signatories'],
                ['letterhead','Document Letterhead'],['classifications','Division Classifications'],
                ['property_registry','Property Registry'],['units','Units of Measure'],
                ['atcs','Withholding Tax / ATC'],['vat_config','VAT Configuration'],['uacs','UACS Accounts']];
            const tabsNode=h('div',{className:'master-tabs',role:'tablist','aria-label':'Reference data'},
                ...tabs.map(([key,label])=>h('button',{key,type:'button',role:'tab',
                    'aria-selected':key==='letterhead',className:'master-tab '+(key==='letterhead'?'selected':''),
                    onClick:()=>this.setState({masterSection:key,travelBrandingError:'',travelBrandingPending:null})},label)));
            const picture=(slot)=>{
                const stored=d?.[slot],selected=s.travelBrandingPending?.slot===slot?s.travelBrandingPending:null;
                const url=stored?'api/travel_branding_image.php?organization_id='+encodeURIComponent(s.organization_id)+
                    '&slot='+slot+'&version='+encodeURIComponent(d.version_id):'';
                return h('section',{key:slot,className:'surface'},
                    h('h3',null,slot==='header'?'Header artwork':'Footer artwork'),
                    h('p',{className:'body-muted'},slot==='header'
                        ?'Upload the COMPLETE approved header as one wide image. It replaces the generated DepEd/office heading and seal (no duplicate text).'
                        :'Upload the COMPLETE approved footer as one wide image. It replaces the generated logos and contact details.'),
                    stored?h('img',{src:url,alt:'Approved '+slot,style:{display:'block',width:'100%',maxHeight:'175px',objectFit:'contain',
                        border:'1px solid #d6dee2',background:'#fff',padding:'8px'}}):
                        h('p',{className:'hint'},'No custom '+slot+' uploaded. The standard print fallback will be used.'),
                    canManage?h('label',{className:'field'},h('span',null,'Select '+slot+' PNG/JPEG (max 600 KB)'),
                        h('input',{type:'file',accept:'image/png,image/jpeg',disabled:s.busy,
                            onChange:e=>this.prepareTravelBranding(e.target.files?.[0],slot)})):null,
                    selected?h('p',{className:'hint'},'Selected: '+selected.file_name+' — review the image below, then save with a reason.'):null,
                    selected?h('img',{src:selected.preview,alt:'Pending '+slot+' preview',style:{display:'block',width:'100%',
                        maxHeight:'170px',objectFit:'contain',background:'#fff',border:'1px dashed #a5b6c4',padding:'8px'}}):null,
                    canManage&&stored?h('button',{type:'button',className:'outline',disabled:s.busy,
                        onClick:()=>this.removeTravelBranding(slot)},'Remove custom '+slot+' (restore fallback)'):null);
            };
            return h('div',{className:'section-stack'},this.header('Document Letterhead',
                'Upload approved header and footer artwork for this school or Division Office.'),tabsNode,
                h('section',{className:'surface'},
                    h('div',{className:'section-head'},h('h3',null,'Organization-specific A4 artwork'),
                        h('button',{className:'outline',type:'button',onClick:this.loadTravelBranding,disabled:s.travelBrandingLoading},
                            s.travelBrandingLoading?'Loading…':'Refresh')),
                    h('p',{className:'body-muted'},'The selected workspace owns its own artwork. No other school uses this letterhead. '
                        +'Recommended export: header 1600 × 450 px, footer 1600 × 200 px, white background, PNG for crisp text. '
                        +'Use a full-width horizontal image (2:1 to 18:1), not a PDF or editable document. '
                        +'Current use: Activity Request and Travel Authority printing.'),
                    d?h('p',{className:'hint'},'Artwork version: '+(d.version_id||'None')+'. '+
                        (canManage?'You may replace each slot independently.':'View only; ask an authorized office user to upload approved artwork.')):null,
                    s.travelBrandingError?h('p',{className:'error',role:'alert'},s.travelBrandingError):null),
                picture('header'),picture('footer'),
                canManage?h('section',{className:'surface'},h('h3',null,'Save approved artwork'),
                    h('form',{onSubmit:this.saveTravelBranding},
                        h('label',{className:'field'},h('span',null,'Approval / change reason *'),
                            h('textarea',{value:s.travelBrandingReason,maxLength:500,rows:2,required:true,
                                onChange:e=>this.setState({travelBrandingReason:e.target.value}),
                                placeholder:'Approval reference for the new school/Division letterhead'})),
                        h('button',{className:'primary',type:'submit',disabled:!s.travelBrandingPending||s.busy||!d},
                            'Save selected '+(s.travelBrandingPending?.slot||'artwork')))):null,
                h('p',{className:'hint'},'Uploading changes stationery only. Document data, approvals, signatories, funds and accounting are unchanged. Earlier image versions are retained for audit.'));
        };
        this.loadTravelSignatories = async () => {
            const org=String(this.state.organization_id||'');if(!org||this.state.travelSignatoriesLoading)return;
            this.setState({travelSignatoriesLoading:true,travelSignatoriesError:''});
            try{const data=await this.get('travel.signatories.settings',{organization_id:org});
                if(String(this.state.organization_id)!==org)return;
                const ids={};['budget_officer','division_accountant','asds','sds'].forEach(key=>ids[key]=String(data.roles?.[key]?.employee_id||''));
                this.setState({travelSignatories:data,travelSignatoriesLoading:false,travelSignatoryIds:ids,
                    travelSignatoryRegion:data.region_heading||'',travelSignatorySalutation:data.salutation||'Sir/Madam:',
                    travelSignatoryReason:''});
            }catch(error){if(String(this.state.organization_id)===org)this.setState({travelSignatoriesLoading:false,travelSignatoriesError:error.message});}
        };
        this.saveTravelSignatories = async e => {
            e.preventDefault();const s=this.state,d=s.travelSignatories;
            if(!d?.can_manage)return;
            const r=await this.task('travel.signatories.save',{
                expected_version:d.version_id,region_heading:s.travelSignatoryRegion.trim(),
                salutation:s.travelSignatorySalutation,roles:s.travelSignatoryIds
            },{refresh:false});
            if(r){this.setState({success:'Division Activity Request signatories recorded. New documents will snapshot these appointments; prior drafts are unchanged.'});
                await this.loadTravelSignatories();}
        };
        this.travelSignatoriesView = () => {
            const h=React.createElement,s=this.state,d=s.travelSignatories;
            const tabs=[['suppliers','Suppliers'],['items','Item Catalog'],['requesting_units','Requesting Units'],['employees','Employees'],['officials','BAC & Heads'],['travel_signatories','Division Travel Signatories'],['letterhead','Document Letterhead'],['classifications','Division Classifications'],['property_registry','Property Registry'],['units','Units of Measure'],['atcs','Withholding Tax / ATC'],['vat_config','VAT Configuration'],['uacs','UACS Accounts']];
            const officialRoles=[['budget_officer','Division Budget Officer — Certification of Available Allotment'],
                ['division_accountant','Division Accountant — Certification of Availability of Funds'],
                ['asds','ASDS — Recommending Approval'],['sds','SDS — Approval / Disapproval']];
            const tabsNode=h('div',{className:'master-tabs',role:'tablist','aria-label':'Reference data'},...tabs.map(([key,label])=>h('button',{key,type:'button',role:'tab',
                'aria-selected':key==='travel_signatories',className:'master-tab '+(key==='travel_signatories'?'selected':''),
                onClick:()=>this.setState({masterSection:key,travelSignatoriesError:''})},label)));
            const canManage=!!d?.can_manage;
            return h('div',{className:'section-stack'},this.header('Division Travel Signatories',
                    'Configure four independent officials for Activity Request and Travel Authority. Each change is versioned, never a digital signature.'),
                tabsNode,
                h('section',{className:'surface'},
                    h('div',{className:'section-head'},h('h3',null,'Activity Request · prescribed signatories'),
                        h('button',{type:'button',className:'outline',disabled:s.travelSignatoriesLoading,onClick:this.loadTravelSignatories},s.travelSignatoriesLoading?'Loading…':'Refresh')),
                    h('p',{className:'body-muted'},'Configured at Division level. Schools can view the authorized signatory names. Printed forms use the assignment saved in each document version and leave actual signatures blank.'),
                    s.travelSignatoriesError?h('p',{className:'error'},s.travelSignatoriesError):null,
                    !d?h('p',{className:'hint'},'Load the Division signatory settings.'):null,
                    d?h('form',{onSubmit:this.saveTravelSignatories},
                        h('div',{className:'form-grid'},
                            h('label',{className:'field'},h('span',null,'Region heading (as printed) *'),
                                h('input',{required:true,disabled:!canManage,value:s.travelSignatoryRegion,maxLength:160,
                                    placeholder:'e.g. REGION VII – CENTRAL VISAYAS',onChange:e=>this.setState({travelSignatoryRegion:e.target.value})})),
                            h('label',{className:'field'},h('span',null,'Letter salutation *'),
                                h('select',{required:true,disabled:!canManage,value:s.travelSignatorySalutation,onChange:e=>this.setState({travelSignatorySalutation:e.target.value})},
                                    ...['Sir/Madam:','Madam:','Sir:'].map(v=>h('option',{key:v,value:v},v)))),
                            ...officialRoles.map(([key,label])=>h('label',{key,className:'field'},h('span',null,label),
                                canManage?h('select',{value:s.travelSignatoryIds[key]||'',required:true,
                                    onChange:e=>this.setState({travelSignatoryIds:{...s.travelSignatoryIds,[key]:e.target.value}})},
                                    h('option',{value:''},'Select active Division employee'),
                                    ...(d.staff||[]).map(p=>h('option',{key:p.employee_id,value:String(p.employee_id)},p.full_name+' · '+(p.designation||p.position_title||'')))):
                                    h('input',{readOnly:true,value:d.roles?.[key]?.full_name?d.roles[key].full_name+' · '+(d.roles[key].designation||d.roles[key].position_title||''):'Not yet configured'}))),
                            h('p',{className:'hint'},'Changes are automatically dated, versioned and recorded in the audit trail. No reason entry is required.')),
                        canManage?h('button',{type:'submit',className:'primary',disabled:s.busy||s.travelSignatoriesLoading},'Save Division Signatories'):null,
                        h('p',{className:'hint'},'Configuration version: '+(d.version_id||'Not yet saved')+'. School employees are not included in the Division officer selector. Configure them under Reference Data → Employees while the Division workspace is selected.')):null));
        };
        // Employee profiles are separate from dated BAC / office-head assignments.
        this.loadStaffDirectory = async () => {
            const org=String(this.state.organization_id||'');
            // Allow a new organization to load while an old organization's request is pending.
            if(!org||(this.state.staffLoading&&this.staffFetchOrg===org))return;
            this.staffFetchOrg=org;
            this.setState({staffLoading:true,staffError:''});
            try{
                const data=await this.get('staff.directory',{organization_id:org});
                if(String(this.state.organization_id)!==org||this.staffFetchOrg!==org)return;
                if(String(data?.organization?.id)!==org)throw Error('Employee directory does not match the selected school. Refresh before issuing an RFQ.');
                this.setState({staffData:data,staffLoading:false,staffHeadId:String(data.head?.employee_id||''),staffDvPartA:String(data.dv_signatories?.part_a||''),staffDvPartC:String(data.dv_signatories?.part_c||''),staffDvPartD:String(data.dv_signatories?.part_d||''),
                    staffCustodianId:String(data.custodian?.employee_id||''),staffCustodianDate:data.custodian?.effective_from||localToday(),staffCustodianReason:'',staffCustodianAttested:false,
                    staffChair:String(data.bac?.chair||''),staffCoChair:String(data.bac?.cochair||''),
                    staffMembers:(data.bac?.members||[]).map(String),staffHeadReason:'',staffBacReason:'',staffBacDate:data.bac?.starts||localToday(),staffBacDateReason:'',staffBacAttested:false});
            }catch(e){if(String(this.state.organization_id)===org&&this.staffFetchOrg===org)
                this.setState({staffLoading:false,staffError:e.message});}
            finally{if(this.staffFetchOrg===org)this.staffFetchOrg=null;}
        };
        // The selected row opens the modal immediately; the read occurs *inside* that modal.
        // Do not turn every employee-row button into a shared loading/disabled state.
        this.openStaffAdd = () => {
            if(this.state.busy||this.state.staffLoading||!this.canOperateWorkspace())return;
            const org=String(this.state.organization_id||'');
            if(!org)return;
            this.setState({staffAddOpen:true,staffAddOrg:org,staffProfileId:'',staffError:'',error:'',staffSavedMessage:'',
                staffFullName:'',staffPosition:'',staffDesignation:'',staffEmployeeNo:'',staffTIN:'',staffAddress:''});
        };
        this.closeStaffAdd = () => {
            if(this.state.busy)return;
            this.setState({staffAddOpen:false,staffAddOrg:'',staffError:'',error:'',
                staffFullName:'',staffPosition:'',staffDesignation:'',staffEmployeeNo:'',staffTIN:'',staffAddress:''});
        };
        this.closeStaffPaymentIdentity = () => {
            if(this.state.busy)return;
            this.staffIdentityRequest=(this.staffIdentityRequest||0)+1; // invalidate pending reads
            this.setState({staffProfileId:'',staffProfileOrg:'',staffProfileName:'',staffProfileEmployeeNo:'',
                staffProfileTIN:'',staffProfileAddress:'',staffProfileVersion:0,staffProfileBusy:false,staffEditName:'',staffEditPosition:'',staffEditDesignation:'',staffEditReason:'',staffEditOriginal:null,staffEditProfileOriginal:null,staffError:''});
        };
        this.closeStaffRename = () => {
            if(this.state.busy)return;
            this.setState({staffRenameId:'',staffRenameName:'',staffRenameReason:'',staffError:''});
        };
        this.staffEmployeeDialogKeyDown = (event,close) => {
            if(event.key==='Escape'){event.preventDefault();if(!this.state.busy)close();return;}
            if(event.key!=='Tab')return;
            // Keyboard focus remains inside the active employee editor.
            const focusables=Array.from(event.currentTarget.querySelectorAll('input, textarea, button, select'))
                .filter(el=>!el.disabled&&el.getClientRects().length);
            if(!focusables.length)return;
            const first=focusables[0],last=focusables[focusables.length-1];
            if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}
            else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
        };
        this.loadStaffPaymentIdentity = async (employee) => {
            if(this.state.staffProfileBusy||this.state.busy||!this.canOperateWorkspace())return;
            const org=String(this.state.organization_id||''), id=String(employee.employee_id);
            const token=(this.staffIdentityRequest||0)+1;
            this.staffIdentityRequest=token;
            this.setState({error:'',staffProfileId:id,staffProfileOrg:org,staffProfileName:employee.full_name,
                staffProfileEmployeeNo:'',staffProfileTIN:'',staffProfileAddress:'',staffProfileVersion:0,
                staffEditName:employee.full_name||'',staffEditPosition:employee.position_title||'',staffEditDesignation:employee.designation||'',
                staffEditReason:'',staffEditProfileOriginal:null,staffEditOriginal:{assignment_id:Number(employee.assignment_id),full_name:employee.full_name||'',
                    position_title:employee.position_title||'',designation:employee.designation||''},
                staffProfileBusy:true,staffError:'',staffRenameId:'',staffSavedMessage:''});
            try{
                const data=await this.get('staff.employee.payment_identity',{organization_id:org,employee_id:employee.employee_id});
                if(String(this.state.organization_id)!==org||this.staffIdentityRequest!==token)return;
                this.setState({staffProfileEmployeeNo:data.employee_no||'',staffProfileTIN:data.tin||'',
                    staffProfileAddress:data.address||'',staffEditProfileOriginal:{employee_no:data.employee_no||'',tin:data.tin||'',address:data.address||''},staffProfileVersion:Number(data.version_id||0),staffProfileBusy:false});
            }catch(e){
                if(String(this.state.organization_id)===org&&this.staffIdentityRequest===token)
                    this.setState({staffError:e.message||'Unable to load this employee.',staffProfileBusy:false});
            }
        };
        this.staffAction = async (action,employeeId) => {
            if(this.state.busy||!this.canOperateWorkspace())return;
            const s=this.state;
            if(action==='DEACTIVATE'&&!window.confirm('Deactivate this employee in the selected workspace? Historical records will be preserved.'))return;
            const data={action};
            if(action==='ADD'){
                if(!s.staffAddOpen||s.staffAddOrg!==String(s.organization_id)){
                    this.setState({staffError:'Reopen Add employee for the current workspace.'});return;
                }
                data.full_name=s.staffFullName.trim();data.position_title=s.staffPosition.trim();
                data.designation=s.staffDesignation.trim()||data.position_title;
                data.employee_no=s.staffEmployeeNo.trim();data.tin=s.staffTIN.trim();data.address=s.staffAddress.trim();
                if(!data.full_name||!data.position_title){this.setState({staffError:'Enter employee name and position.'});return;}
            } else {
                data.employee_id=Number(employeeId);
                if(action==='PROFILE'||action==='EDIT'){
                    data.expected_version=Number(s.staffProfileVersion);
                    data.employee_no=s.staffProfileEmployeeNo.trim();
                    data.tin=s.staffProfileTIN.trim();data.address=s.staffProfileAddress.trim();
                }
                if(action==='EDIT'){
                    const old=s.staffEditOriginal;
                    if(!old||String(old.assignment_id)===''){
                        this.setState({error:'Employee details did not finish loading. Reopen Edit.'});return;
                    }
                    data.full_name=s.staffEditName.trim();data.position_title=s.staffEditPosition.trim();
                    data.designation=s.staffEditDesignation.trim()||data.position_title;
                    data.expected_assignment_id=Number(old.assignment_id);
                    data.expected_full_name=old.full_name;
                    data.expected_position_title=old.position_title;
                    data.expected_designation=old.designation;
                    data.reason=s.staffEditReason.trim();
                    if(!data.full_name||!data.position_title){
                        this.setState({error:'Enter the full name and position title.'});return;
                    }
                    if(data.full_name!==old.full_name&&!data.reason){
                        this.setState({error:'Enter the reason for changing this employee’s legal name.'});return;
                    }
                }
                if(action==='RENAME'){
                    data.full_name=s.staffRenameName.trim();data.reason=s.staffRenameReason.trim();
                    if(!data.full_name||!data.reason){this.setState({staffError:'Enter the corrected name and the reason for the correction.'});return;}
                }
            }
            const result=await this.task('staff.employee.change',data,{refresh:false});
            if(result){this.setState({staffAddOpen:false,staffAddOrg:'',staffFullName:'',staffPosition:'',staffDesignation:'',staffEmployeeNo:'',staffTIN:'',staffAddress:'',
                staffProfileId:'',staffProfileOrg:'',staffProfileName:'',staffProfileEmployeeNo:'',staffProfileTIN:'',staffProfileAddress:'',staffProfileVersion:0,
                staffEditName:'',staffEditPosition:'',staffEditDesignation:'',staffEditReason:'',staffEditOriginal:null,
                staffRenameId:'',staffRenameName:'',staffRenameReason:'',staffError:'',staffSavedMessage:action==='EDIT'?'Employee details saved.':action==='PROFILE'?'Employee DV details saved.':'Employee directory updated.'});
                await this.loadStaffDirectory();}
        };
        this.staffSaveOne = async (route,payload,kind) => {
            if(this.staffRequestInFlight || this.state.busy || !this.canOperateWorkspace()){
                this.setState({staffError:'Another update is in progress. Complete it before saving a different appointment.'});
                return;
            }
            const organizationId=String(this.state.organization_id);
            this.staffRequestInFlight=true;
            this.setState({staffSavingAction:kind,staffError:'',staffSavedMessage:''});
            try{
                await this.api(route,{organization_id:Number(organizationId),...payload});
                if(String(this.state.organization_id)===organizationId){
                    await this.loadStaffDirectory();
                    this.setState({staffSavedMessage:kind+' saved successfully.'});
                }
            }catch(e){
                if(String(this.state.organization_id)!==organizationId)return;
                // An interrupted fetch may occur *after* the server committed.
                // Never repeat this financial/appointment POST automatically.
                // Read back the server's authoritative version before claiming success.
                if(route==='staff.custodian.save' && e?.name==='TypeError'){
                    try{
                        const directory=await this.get('staff.directory',{organization_id:organizationId});
                        if(String(this.state.organization_id)!==organizationId)return;
                        const saved=directory?.custodian||{};
                        const changed=Number(saved.version_id||0)>0 &&
                            Number(saved.version_id)!==Number(payload.expected_version||0) &&
                            String(saved.employee_id??'')===String(payload.employee_id??'') &&
                            String(saved.effective_from||'')===String(payload.effective_from||'');
                        if(changed){
                            await this.loadStaffDirectory();
                            if(String(this.state.organization_id)===organizationId)
                                this.setState({staffError:'',staffSavedMessage:'Custodian appointment verified as saved. The original response was interrupted; no second save was sent.'});
                        }else{
                            this.setState({staffError:'Custodian appointment: the connection was interrupted. A read-back check did not find this new appointment. Refresh Data and review the current record before retrying; do not repeatedly press Save.'});
                        }
                    }catch(_readError){
                        if(String(this.state.organization_id)===organizationId)
                            this.setState({staffError:'Custodian appointment: the connection was interrupted and IPDAMS could not verify the result. The save may have succeeded. Refresh Data and check the current appointment before trying again.'});
                    }
                }else{
                    this.setState({staffError:kind+': '+(e.message||'Save failed')});
                }
            }finally{
                this.staffRequestInFlight=false;
                this.setState({staffSavingAction:''});
            }
        };
        this.staffSaveHead = async (e) => {
            e.preventDefault();const s=this.state;
            await this.staffSaveOne('staff.head.save',{
                employee_id:s.staffHeadId||null,expected_version:Number(s.staffData?.head?.version_id||0)
            },'Head appointment');
        };
        this.staffSaveDvSectionSignatories = async (e) => {
            e.preventDefault();const s=this.state;
            await this.staffSaveOne('staff.dv.signatories.save',{
                part_a:s.staffDvPartA||null,part_c:s.staffDvPartC||null,part_d:s.staffDvPartD||null,
                expected_version:Number(s.staffData?.dv_signatories?.version_id||0)
            },'Appendix 32 section signatories');
        };
        this.staffSaveCustodian = async (e) => {
            e.preventDefault();const s=this.state;
            const evidence=custodianAppointmentEvidence(s);
            if(evidence.missingCharacters){
                this.setState({staffError:'Appointment reference is '+evidence.length+' characters; enter '+evidence.missingCharacters+' more using the genuine appointment order, memorandum or supporting record.'});return;
            }
            if(evidence.needsConfirmation){
                this.setState({staffError:'Confirm that the historical appointment date is supported by the official record.'});return;
            }
            await this.staffSaveOne('staff.custodian.save',{
                employee_id:s.staffCustodianId||null,effective_from:s.staffCustodianDate,
                expected_version:Number(s.staffData?.custodian?.version_id||0),
                reason:s.staffCustodianReason.trim(),
                historical_appointment_attested:!!s.staffCustodianAttested
            },'Custodian appointment');
        };
        this.staffSaveBac = async (e) => {
            e.preventDefault();const s=this.state;
            await this.staffSaveOne('staff.bac.save',{
                chair_employee_id:Number(s.staffChair),cochair_employee_id:s.staffCoChair||null,
                member_employee_ids:s.staffMembers.map(Number),
                expected_term_id:Number(s.staffData?.bac?.term_id||0)
            },'BAC appointments');
        };
        this.staffCorrectBacDate = async (e) => {
            e.preventDefault();const s=this.state;
            if(!s.staffBacAttested){this.setState({staffError:'Confirm that the official BAC appointment supports this date.'});return;}
            await this.staffSaveOne('staff.bac.date.correct',{
                term_id:Number(s.staffData?.bac?.term_id||0),expected_starts:s.staffData?.bac?.starts||'',
                effective_from:s.staffBacDate,appointment_reference:(s.staffBacDateReason||'').trim(),
                official_record_confirmed:!!s.staffBacAttested
            },'BAC effectivity correction');
        };
        this.staffDirectoryView = () => {
            const h=React.createElement,s=this.state,all=s.staffData?.employees||[],canEdit=this.canOperateWorkspace();
            const isRoles=s.masterSection==='officials',active=all.filter(x=>x.active),
                custodianEvidence=custodianAppointmentEvidence(s),
                rows=all.filter(x=>x.active||s.staffShowInactive),
                tabs=[['suppliers','Suppliers'],['items','Item Catalog'],['requesting_units','Requesting Units'],['employees','Employees'],['officials','BAC & Heads'],['travel_signatories','Division Travel Signatories'],['letterhead','Document Letterhead'],['classifications','Division Classifications'],['property_registry','Property Registry'],['units','Units of Measure'],['atcs','Withholding Tax / ATC'],['vat_config','VAT Configuration'],['uacs','UACS Accounts']];
            const selector=(label,value,onChange,emptyLabel='Choose employee')=>h('label',{className:'field'},
                h('span',null,label),h('select',{value,onChange,required:false},h('option',{value:''},emptyLabel),
                    ...active.map(person=>h('option',{key:person.employee_id,value:String(person.employee_id)},person.full_name+' · '+(person.position_title||person.designation||'')))));
            const field=(label,key,placeholder,required=true)=>h('label',{className:'field'},h('span',null,label),
                h('input',{value:s[key]||'',required,maxLength:(key.includes('TIN')?40:key.includes('EmployeeNo')?50:160),placeholder,onChange:this.set(key)}));
            const tabsNode=h('div',{className:'master-tabs',role:'tablist','aria-label':'Reference data'},...tabs.map(([value,label])=>h('button',{key:value,type:'button',role:'tab','aria-selected':s.masterSection===value,className:'master-tab '+(s.masterSection===value?'selected':''),onClick:()=>{this.staffIdentityRequest=(this.staffIdentityRequest||0)+1;this.setState({masterSection:value,staffAddOpen:false,staffAddOrg:'',staffError:'',staffSavedMessage:'',staffRenameId:'',staffProfileId:'',staffProfileBusy:false});}},label)));
            const wait=s.staffLoading?h('p',{className:'hint'},'Loading staff records…'):null;
            const notice=h('div',null,s.staffError?h('p',{className:'error',role:'alert'},s.staffError):null, s.staffSavedMessage?h('p',{className:'notice success',role:'status'},s.staffSavedMessage):null);
            let panel;
            if(!isRoles){
                panel=h('div',null,
                    h('section',{className:'surface'},h('div',{className:'section-head'},h('h3',null,'Employees in this workspace'),
                        h('div',{className:'inline-actions'},
                            canEdit?h('button',{type:'button',className:'primary',disabled:s.staffLoading||s.busy,
                                onClick:this.openStaffAdd},'Add employee'):null,
                            h('button',{type:'button',className:'outline',onClick:this.loadStaffDirectory,disabled:s.staffLoading},'Refresh'))),
                        h('p',{className:'hint'},'One person per entry. Inactive employees are preserved, not deleted. Switching schools changes the directory.'),
                        h('label',{style:{display:'inline-flex',gap:'8px',alignItems:'center'}},h('input',{type:'checkbox',style:{width:'16px',height:'16px',margin:0},checked:!!s.staffShowInactive,onChange:e=>this.setState({staffShowInactive:e.target.checked})}),'Show inactive employees'),
                        h('div',{className:'table-scroll'},h('table',{className:'master-table'},h('thead',null,h('tr',null,...['Name','Position','Designation','Status','Actions'].map(x=>h('th',{key:x},x)))),h('tbody',null,...rows.map(person=>h('tr',{key:person.employee_id},
                            h('td',null,person.full_name),h('td',null,person.position_title||'—'),h('td',null,person.designation||'—'),h('td',null,person.active?'Active':'Inactive'),
                            h('td',null,canEdit?h('div',{className:'inline-actions'},
                                person.active?h('button',{type:'button',className:'outline',disabled:s.busy,
                                    onClick:()=>this.loadStaffPaymentIdentity(person)},'Edit'):null,
                                h('button',{type:'button',className:'outline',disabled:s.busy,onClick:()=>this.staffAction(person.active?'DEACTIVATE':'REACTIVATE',person.employee_id)},person.active?'Deactivate':'Reactivate')):'Read only'))),
                            !rows.length?h('tr',{key:'none'},h('td',{colSpan:5},'No employees configured yet.')):null)))),
                    null);
            }else{
                panel=h('div',null,
                    h('section',{className:'surface'},h('h3',null,(s.staffData?.organization?.kind==='SCHOOL'?'School Head':'Head of Office')),
                        h('p',{className:'hint'},'Choose the currently assigned head. This only records a directory appointment; it does not electronically approve any PR or other form.'),
                        h('form',{onSubmit:this.staffSaveHead},h('div',{className:'form-grid'},selector('Head of office',s.staffHeadId,e=>this.setState({staffHeadId:e.target.value}),'Not yet assigned')),
                            h('p',{className:'hint'},'Head appointments are recorded automatically in the audit trail.'),
                            canEdit?h('button',{type:'submit',className:'primary',disabled:s.staffSavingAction==='Head appointment'||!s.staffData},s.staffSavingAction==='Head appointment'?'Saving Head Appointment…':'Save Head Appointment'):null)),
                    s.staffData?.organization?.kind==='SCHOOL'?h('section',{className:'surface'},
                        h('h3',null,'Disbursement Voucher — Printed Signatories'),
                        h('p',{className:'hint'},'Assign an authorized staff member for each section of Appendix 32. For Non-IUS schools, you may select the Principal for all three. IUS schools can select their own authorized certifying officers. Assignments are school-specific and audited; the chosen names are saved with newly finalized DVs. Printed signature spaces remain blank.'),
                        h('form',{onSubmit:this.staffSaveDvSectionSignatories},
                            h('div',{className:'form-grid'},
                                selector('Part A — Expenses / Supervisor certification',s.staffDvPartA,e=>this.setState({staffDvPartA:e.target.value}),'No Part A signatory assigned'),
                                selector('Part C — Cash Available certification',s.staffDvPartC,e=>this.setState({staffDvPartC:e.target.value}),'No Part C signatory assigned'),
                                selector('Part D — Approved for Payment',s.staffDvPartD,e=>this.setState({staffDvPartD:e.target.value}),'No Part D signatory assigned')),
                            canEdit?h('button',{type:'submit',className:'primary',disabled:!!s.staffSavingAction||!s.staffData},'Save DV Signatories'):null)):null,
                    h('section',{className:'surface'},h('h3',null,'Administrative Officer / Property Custodian (IAR Signatory)'),
                        h('p',{className:'hint'},'Set the IAR acceptance signatory for this School or Division. This is a directory appointment, not a digital signature or approval. A valid historical appointment may predate the employee’s entry in IPDAMS.'),
                        s.staffData?.organization?.kind==='DIVISION'?h('p',{className:'hint'},'Separate role: To appoint the officer who approves high-value unserviceable declarations and disposals, use Property Accountability → Condition & Disposal → Technical administrator → Appoint Division Supply Officer. This form does not grant that approval role.'):null,
                        h('form',{onSubmit:this.staffSaveCustodian},h('div',{className:'form-grid'},
                            selector('Assigned AO / Property Custodian',s.staffCustodianId,e=>this.setState({staffCustodianId:e.target.value,staffCustodianAttested:false}),'No custodian assigned'),
                            h('label',{className:'field'},h('span',null,'Appointment effective date *'),
                                h('input',{type:'date',value:s.staffCustodianDate||localToday(),max:localToday(),required:true,
                                    onChange:e=>this.setState({staffCustodianDate:e.target.value,staffCustodianAttested:false})})),
                            h('label',{className:'field'},h('span',null,'Official appointment reference / supporting record'+(custodianEvidence.minimum?' *':'')),
                                h('input',{value:s.staffCustodianReason||'',maxLength:500,
                                    minLength:custodianEvidence.minimum||undefined,required:custodianEvidence.minimum>0,
                                    'aria-describedby':'custodian-appointment-reference-hint',
                                    onChange:e=>this.setState({staffCustodianReason:e.target.value,staffError:''}),
                                    placeholder:'Document title, office order/memo number and date'}),
                                h('span',{id:'custodian-appointment-reference-hint',className:'hint',role:'status',
                                    style:{color:custodianEvidence.missingCharacters?'#a33e2c':undefined}},
                                    custodianEvidence.minimum
                                        ?'Official supporting record: '+custodianEvidence.length+' / '+custodianEvidence.minimum+' characters'+(custodianEvidence.missingCharacters?' — add '+custodianEvidence.missingCharacters+' more before saving.': ' — requirement met.')
                                        :'Add an official designation reference when available. The saved appointment is audited.'))),
                            custodianEvidence.historical?h('div',{className:'notice warning',role:'group','aria-label':'Historical appointment confirmation'},
                                h('p',null,'The appointment date is earlier than the employee assignment date recorded in IPDAMS ('+custodianEvidence.staffRecordedFrom+'). This is permitted with authentic supporting evidence. It does not alter the employee directory date or any finalized historical IAR.'),
                                h('label',{style:{display:'inline-flex',gap:'8px',alignItems:'flex-start'}},
                                    h('input',{type:'checkbox',checked:!!s.staffCustodianAttested,required:true,style:{width:'18px',height:'18px',margin:0},onChange:e=>this.setState({staffCustodianAttested:e.target.checked,staffError:''})}),
                                    'I confirm the earlier appointment date is supported by an official document.')):null,
                            h('p',{className:'hint'},'Appointment changes are dated and audited. Earlier IAR copies keep their original recorded signatory history; the historical date does not change the employee assignment record.'),
                            canEdit?h('button',{type:'submit',className:'primary',disabled:s.staffSavingAction==='Custodian appointment'||!s.staffData},s.staffSavingAction==='Custodian appointment'?'Saving Custodian Appointment…':'Save Custodian Appointment'):null)),
                    h('section',{className:'surface'},h('h3',null,'Bids and Awards Committee (BAC)'),
                        h('p',{className:'hint'},'BAC Chairman is required to save a term. Co-chairman is optional. Other BAC members are selected individually. Changes create a new historical term, not an overwrite.'),
                        h('form',{onSubmit:this.staffSaveBac},h('div',{className:'form-grid'},
                            selector('BAC Chairman *',s.staffChair,e=>this.setState({staffChair:e.target.value,staffMembers:this.state.staffMembers.filter(id=>id!==e.target.value),staffCoChair:this.state.staffCoChair===e.target.value?'':this.state.staffCoChair})),
                            selector('BAC Co-chairman (optional)',s.staffCoChair,e=>this.setState({staffCoChair:e.target.value,staffMembers:this.state.staffMembers.filter(id=>id!==e.target.value),staffChair:this.state.staffChair===e.target.value?'':this.state.staffChair}),'None')),
                            h('h4',null,'Other BAC members'),h('div',{style:{display:'grid',gridTemplateColumns:'repeat(auto-fit,minmax(185px,1fr))',gap:'8px'}},
                                ...active.filter(person=>!([s.staffChair,s.staffCoChair].includes(String(person.employee_id)))).map(person=>h('label',{key:person.employee_id,style:{display:'inline-flex',alignItems:'center',gap:'7px'}},
                                    h('input',{type:'checkbox',checked:s.staffMembers.includes(String(person.employee_id)),style:{width:'16px',height:'16px',margin:0},onChange:e=>this.setState({staffMembers:e.target.checked?[...s.staffMembers,String(person.employee_id)]:s.staffMembers.filter(id=>id!==String(person.employee_id))})}),person.full_name))),
                            h('p',{className:'hint'},'Current BAC term #'+(s.staffData?.bac?.term_id||'none')+' · Started '+(s.staffData?.bac?.starts||'Not yet appointed')+' · All appointment changes are audited automatically.'),
                            canEdit?h('button',{type:'submit',className:'primary',disabled:s.staffSavingAction==='BAC appointments'||!s.staffChair||!s.staffData},s.staffSavingAction==='BAC appointments'?'Saving BAC Appointments…':'Save BAC Appointments'):null)),
                    s.staffData?.bac?.term_id?h('section',{className:'surface'},
                        h('h3',null,'Correct BAC Term Effective Date'),
                        h('p',{className:'hint'},'This corrects the documented effectivity of BAC Term #'+s.staffData.bac.term_id+' without changing its Chairperson or members. Use the official BAC appointment/order; earlier signed document snapshots and employee assignment dates remain unchanged.'),
                        h('form',{onSubmit:this.staffCorrectBacDate},h('div',{className:'form-grid'},
                            h('label',{className:'field'},h('span',null,'Original BAC appointment effective date *'),
                                h('input',{type:'date',value:s.staffBacDate||'',max:localToday(),required:true,
                                    onChange:e=>this.setState({staffBacDate:e.target.value,staffBacAttested:false})})),
                            h('label',{className:'field'},h('span',null,'Official BAC appointment memorandum/order reference *'),
                                h('input',{value:s.staffBacDateReason||'',maxLength:500,minLength:20,required:true,
                                    placeholder:'Official appointment order, date and reference',onChange:this.set('staffBacDateReason')})),
                            h('label',{style:{display:'inline-flex',gap:'8px',alignItems:'flex-start'}},
                                h('input',{type:'checkbox',checked:!!s.staffBacAttested,required:true,
                                    style:{width:'18px',height:'18px',margin:0},onChange:e=>this.setState({staffBacAttested:e.target.checked})}),
                                'I confirm this date is supported by the official BAC appointment document.')),
                            h('p',{className:'hint'},'Recorded start: '+s.staffData.bac.starts+' · The system will check other BAC terms and any earlier employee-assignment dates. All corrections are audited.'),
                            canEdit?h('button',{type:'submit',className:'primary',disabled:s.staffSavingAction==='BAC effectivity correction'||!s.staffBacAttested||!s.staffBacDate||!s.staffBacDateReason||s.staffBacDate===s.staffData.bac.starts},
                                s.staffSavingAction==='BAC effectivity correction'?'Saving BAC Date…':'Save Corrected BAC Effectivity Date'):null)):null);
            }
            // Keep both employee edit actions in focused, independent dialogs instead of
            // appending edit forms below a potentially very long employee directory.
            const showPaymentDialog=!isRoles&&canEdit&&!!s.staffProfileId&&s.staffProfileOrg===String(s.organization_id);
            const modalShell=(title,id,close,body)=>h('div',{className:'modal-backdrop',
                style:{position:'fixed',inset:0,zIndex:1200,display:'flex',alignItems:'center',justifyContent:'center',padding:'14px',background:'rgba(15,34,53,.58)'},
                onMouseDown:event=>{if(event.target===event.currentTarget&&!s.busy)close();}},
                h('section',{className:'modal pr-detail-modal',role:'dialog','aria-modal':'true','aria-labelledby':id,
                    style:{display:'flex',flexDirection:'column',width:'min(540px,100%)',maxWidth:'540px',maxHeight:'min(92vh,740px)',height:'auto',minHeight:0,padding:0,overflow:'hidden',borderRadius:'12px',background:'#fff',boxShadow:'0 16px 50px rgba(0,0,0,.25)'},
                    onKeyDown:event=>this.staffEmployeeDialogKeyDown(event,close),onMouseDown:event=>event.stopPropagation()},
                    h('div',{style:{display:'flex',alignItems:'center',gap:'12px',justifyContent:'space-between',padding:'16px 18px',borderBottom:'1px solid #dbe5ec'}},
                        h('h3',{id,style:{margin:0,fontSize:'18px',overflowWrap:'anywhere'}},title),
                        h('button',{type:'button',className:'outline','aria-label':'Close employee window',disabled:s.busy,
                            onClick:close,style:{flex:'0 0 auto',padding:'4px 9px'}},'✕')),
                    body));
            const employeeHasChanges=!!(s.staffEditOriginal&&s.staffEditProfileOriginal&&(
                s.staffEditName.trim()!==s.staffEditOriginal.full_name||
                s.staffEditPosition.trim()!==s.staffEditOriginal.position_title||
                (s.staffEditDesignation.trim()||s.staffEditPosition.trim())!==s.staffEditOriginal.designation||
                s.staffProfileEmployeeNo.trim()!==s.staffEditProfileOriginal.employee_no||
                s.staffProfileTIN.trim()!==s.staffEditProfileOriginal.tin||
                s.staffProfileAddress.trim()!==s.staffEditProfileOriginal.address));
            const showAddDialog=!isRoles&&canEdit&&!!s.staffAddOpen&&s.staffAddOrg===String(s.organization_id);
            const addModal=showAddDialog?modalShell('Add employee','staff-add-modal-title',this.closeStaffAdd,
                h('form',{style:{display:'flex',flexDirection:'column',minHeight:0},
                    onSubmit:e=>{e.preventDefault();if(!s.busy)this.staffAction('ADD');}},
                    h('div',{style:{padding:'16px 18px',overflowY:'auto',minHeight:0}},
                        h('p',{className:'hint'},'Employee records do not create user login accounts.'),
                        s.staffError?h('p',{className:'error',role:'alert'},s.staffError):null,
                        s.error&&!s.busy?h('p',{className:'error',role:'alert'},s.error):null,
                        h('div',{className:'form-grid',style:{gridTemplateColumns:'1fr'}},
                            field('Full name *','staffFullName','Employee name'),
                            field('Position title *','staffPosition','Position title'),
                            field('Designation (optional)','staffDesignation','Defaults to position',false),
                            field('Employee No. (optional)','staffEmployeeNo','Employee number',false),
                            field('TIN (optional)','staffTIN','TIN, digits and hyphens',false),
                            h('label',{className:'field'},h('span',null,'Address (optional)'),
                                h('textarea',{value:s.staffAddress,maxLength:500,rows:3,placeholder:'Address',onChange:this.set('staffAddress')})))),
                    h('div',{className:'inline-actions',style:{padding:'12px 18px',borderTop:'1px solid #dbe5ec',justifyContent:'flex-end',margin:0}},
                        h('button',{type:'button',className:'outline',disabled:s.busy,onClick:this.closeStaffAdd},'Cancel'),
                        h('button',{type:'submit',className:'primary',disabled:s.busy||!s.staffFullName.trim()||!s.staffPosition.trim()},
                            s.busy?'Saving…':'Save employee')))):null;
            const paymentModal=showPaymentDialog?modalShell('Edit employee — '+s.staffProfileName,
                'staff-payment-modal-title',this.closeStaffPaymentIdentity,
                h('form',{style:{display:'flex',flexDirection:'column',minHeight:0},
                    onSubmit:event=>{event.preventDefault();if(!s.staffProfileBusy&&!s.staffError)this.staffAction('EDIT',s.staffProfileId);}},
                    h('div',{style:{padding:'16px 18px',overflowY:'auto',minHeight:0}},
                        h('p',{className:'hint'},'Update this employee in the selected workspace. Changes are audited. Earlier finalized vouchers are not rewritten; dated appointments remain accountable.'),
                        s.staffProfileBusy?h('p',{role:'status'},'Loading employee details…'):null,
                        s.staffError?h('p',{className:'error',role:'alert'},s.staffError):null,
                        s.error&&!s.busy?h('p',{className:'error',role:'alert'},s.error):null,
                        !s.staffProfileBusy&&!s.staffError?h('div',{className:'form-grid',style:{gridTemplateColumns:'1fr'}},
                            h('label',{className:'field'},h('span',null,'Full name *'),
                                h('input',{autoFocus:true,required:true,value:s.staffEditName,maxLength:160,onChange:this.set('staffEditName')})),
                            h('label',{className:'field'},h('span',null,'Position title *'),
                                h('input',{required:true,value:s.staffEditPosition,maxLength:160,onChange:this.set('staffEditPosition')})),
                            h('label',{className:'field'},h('span',null,'Designation (optional)'),
                                h('input',{value:s.staffEditDesignation,maxLength:160,placeholder:'Defaults to position title',onChange:this.set('staffEditDesignation')})),
                            s.staffEditOriginal&&s.staffEditName.trim()!==s.staffEditOriginal.full_name?
                                h('label',{className:'field'},h('span',null,'Reason for correcting the employee name *'),
                                    h('input',{required:true,value:s.staffEditReason,maxLength:500,
                                        placeholder:'Brief documentary reason',onChange:this.set('staffEditReason')})):null,
                            h('label',{className:'field'},h('span',null,'Employee No. (optional)'),
                                h('input',{value:s.staffProfileEmployeeNo,maxLength:50,placeholder:'Employee number',onChange:this.set('staffProfileEmployeeNo')})),
                            h('label',{className:'field'},h('span',null,'TIN (optional)'),
                                h('input',{value:s.staffProfileTIN,maxLength:40,placeholder:'TIN, digits and hyphens',onChange:this.set('staffProfileTIN')})),
                            h('label',{className:'field'},h('span',null,'Address (optional)'),
                                h('textarea',{value:s.staffProfileAddress,maxLength:500,rows:3,placeholder:'Address',onChange:this.set('staffProfileAddress')}))):null),
                    h('div',{className:'inline-actions',style:{padding:'12px 18px',borderTop:'1px solid #dbe5ec',justifyContent:'flex-end',margin:0}},
                        h('button',{type:'button',className:'outline',disabled:s.busy,onClick:this.closeStaffPaymentIdentity},'Cancel'),
                        s.staffError?h('button',{type:'button',className:'outline',disabled:s.busy,
                            onClick:()=>{const p=all.find(person=>String(person.employee_id)===String(s.staffProfileId));if(p)this.loadStaffPaymentIdentity(p);}},'Retry'):null,
                        h('button',{type:'submit',className:'primary',disabled:s.busy||s.staffProfileBusy||!!s.staffError||!employeeHasChanges},
                            s.busy?'Saving…':'Save changes')))):null;
            return h('div',{className:'section-stack'},this.header(isRoles?'BAC & Heads':'Employee Directory',
                'Scoped to '+(s.staffData?.organization?.name||(this.state.orgs.find(x=>String(x.id)===String(s.organization_id))?.name||'the selected organization'))),
                tabsNode,wait,notice,panel,addModal,paymentModal);
        };

        this.requestingUnitsView = () => {
            const h=React.createElement,s=this.state;
            const options=[['suppliers','Suppliers'],['items','Item Catalog'],['requesting_units','Requesting Units'],['employees','Employees'],['officials','BAC & Heads'],['travel_signatories','Division Travel Signatories'],['letterhead','Document Letterhead'],['classifications','Division Classifications'],['property_registry','Property Registry'],['units','Units of Measure'],['atcs','Withholding Tax / ATC'],['vat_config','VAT Configuration'],['uacs','UACS Accounts']];
            const editing=s.prUnitEdit||'',parts=editing.split(':'),action=parts[0],recordId=parts.slice(1).join(':');
            const selected=(s.prUnitRecords||[]).find(x=>x.id===recordId);
            const writable=this.canOperateWorkspace(),records=(s.prUnitRecords||[]).filter(x=>x.active||s.prUnitsShowInactive);
            const choose=(action,unit)=>this.setState({prUnitEdit:action+':'+unit.id,prUnitName:action==='RENAME'?unit.name:'',
                prUnitsReason:'',prUnitsError:''});
            return h('div',{className:'section-stack'},this.header('Reference data','Maintain departments independently for each school and the Division Office.'),
                h('div',{className:'master-tabs',role:'tablist','aria-label':'Reference data'},
                    ...options.map(([id,label])=>h('button',{key:id,type:'button',role:'tab','aria-selected':id==='requesting_units',
                        className:'master-tab '+(id==='requesting_units'?'selected':''),
                        onClick:()=>this.setState({masterSection:id,prUnitEdit:'',prUnitName:'',prUnitsReason:''},()=>{if(id==='uacs')this.loadUacs(0);})},label))),
                h('section',{className:'surface'},
                    h('div',{className:'section-head'},h('h3',null,'Requesting units / departments'),
                        h('button',{type:'button',className:'outline',disabled:s.prUnitsLoading,onClick:this.loadPrRequestingUnits},s.prUnitsLoading?'Loading…':'Refresh')),
                    h('p',{className:'hint'},'Manage one department at a time. Deactivation is reversible and historical PRs remain unchanged.'),
                    h('label',{style:{display:'inline-flex',alignItems:'center',gap:'8px',margin:'8px 0',cursor:'pointer'}},
                        h('input',{type:'checkbox',checked:!!s.prUnitsShowInactive,
                            style:{width:'16px',height:'16px',minWidth:'16px',flex:'0 0 16px',margin:0},
                            onChange:e=>this.setState({prUnitsShowInactive:e.target.checked})}),
                        'Show inactive departments'),
                    s.prUnitsError?h('p',{className:'error',role:'alert'},s.prUnitsError):null,
                    h('div',{className:'table-scroll'},h('table',{className:'master-table'},
                        h('thead',null,h('tr',null,h('th',null,'Department'),h('th',null,'Status'),h('th',null,'Actions'))),
                        h('tbody',null,...records.map(unit=>h('tr',{key:unit.id},
                            h('td',null,unit.name),h('td',null,unit.active?'Active':'Inactive'),
                            h('td',null,writable?h('div',{className:'inline-actions'},
                                unit.active?h('button',{type:'button',className:'text-btn',disabled:s.busy,onClick:()=>choose('RENAME',unit)},'Rename'):null,
                                h('button',{type:'button',className:'text-btn',disabled:s.busy,
                                    onClick:()=>choose(unit.active?'DEACTIVATE':'REACTIVATE',unit)},unit.active?'Deactivate':'Reactivate')):'View only'))),
                            !records.length?h('tr',{key:'none'},h('td',{colSpan:3},'No departments to display for this organization.')):null))),
                    writable?h('section',{className:'surface',style:{marginTop:'16px'}},
                        h('h3',null,action==='DEACTIVATE'?'Deactivate department':action==='REACTIVATE'?'Reactivate department':action==='RENAME'?'Rename department':'Add department'),
                        selected?h('p',{className:'hint'},'Selected: '+selected.name):null,
                        h('form',{onSubmit:e=>{e.preventDefault();this.changePrRequestingUnit(action||'ADD',recordId);}},
                            (!action||action==='RENAME')?h('label',{className:'field'},h('span',null,'Department name'),
                                h('input',{required:true,maxLength:160,value:s.prUnitName||'',onChange:e=>this.setState({prUnitName:e.target.value})})):null,
                            action?h('label',{className:'field'},h('span',null,'Reason for change'),
                                h('input',{required:true,maxLength:500,value:s.prUnitsReason,onChange:e=>this.setState({prUnitsReason:e.target.value})})):null,
                            h('div',{className:'inline-actions'},
                                h('button',{type:'submit',className:'primary',disabled:s.busy||s.prUnitsLoading},
                                    action==='DEACTIVATE'?'Confirm deactivation':action==='REACTIVATE'?'Confirm reactivation':action==='RENAME'?'Save rename':'Add department'),
                                editing?h('button',{type:'button',className:'outline',onClick:()=>this.setState({prUnitEdit:'',prUnitName:'',prUnitsReason:''})},'Cancel'):null))):null));
        };
        // v0.2.94: keyboard-first PR item entry. Keep saving an explicit action.
        // Focus after React commits an appended row; never use synthetic key events
        // or setState side-effects during render.
        this.prFocusField = (scope,i,field) => requestAnimationFrame(() => {
            const id=field==='catalog'?'pr-item-'+scope+'-'+i:'pr-'+field+'-'+scope+'-'+i;
            const node=document.getElementById(id);
            if(node && !node.disabled && node.getClientRects().length)node.focus();
        });
        this.prLineComplete = line => !!line?.item_id && Number(line.quantity)>0 && Number(line.unit_price)>0;
        this.prAdvanceLine = (scope,i,focusNext=true) => {
            const editing=scope==='edit',rows=editing?this.state.prEdit?.lines:this.state.prLines;
            if(this.state.busy || (editing?!this.state.prEdit:!this.state.prCreateOpen) || !rows?.[i] || !this.prLineComplete(rows[i]))return false;
            if(i<rows.length-1){if(focusNext)this.prFocusField(scope,i+1,'catalog');return true;}
            if(editing){
                this.setState(prev=>{
                    const edit=prev.prEdit,existing=edit?.lines||[];
                    if(!edit || prev.busy || i!==existing.length-1 || !this.prLineComplete(existing[i]))return null;
                    return {prEdit:{...edit,lines:[...existing,{item_id:'',item_label:'',item_unit:'',quantity:'1',unit_price:'0.00'}]}};
                },()=>{if(focusNext)this.prFocusField(scope,i+1,'catalog');});
            }else{
                this.setState(prev=>{
                    const existing=prev.prLines;
                    if(prev.busy || !prev.prCreateOpen || i!==existing.length-1 || !this.prLineComplete(existing[i]))return null;
                    return {prLines:[...existing,{item_id:'',quantity:'1',unit_price:'0.00'}]};
                },()=>{if(focusNext)this.prFocusField(scope,i+1,'catalog');});
            }
            return true;
        };
        // Retain automatic new-item row creation on mouse/trackpad blur.
        this.appendNextPrRow = (i,scope='create') => {this.prAdvanceLine(scope,i,false);};
        this.prPriceKeyDown = (event,scope,i) => {
            if((event.key==='Tab' && !event.shiftKey)||event.key==='Enter'){
                const advanced=this.prAdvanceLine(scope,i,true);
                // Enter must never submit the PR implicitly, even if incomplete.
                if(event.key==='Enter'||advanced)event.preventDefault();
            }
        };
        this.prQuantityKeyDown = (event,scope,i) => {
            if(event.key==='Enter'){
                event.preventDefault();this.prFocusField(scope,i,'price');
            }
        };
        this.prInputEnterGuard = event => {
            // The explicit Save PR button can still be activated with Enter.
            if(event.key!=='Enter'||event.defaultPrevented)return;
            const el=event.target;
            if(!el || !['INPUT','SELECT'].includes(el.tagName))return;
            event.preventDefault();
            // Enter in ordinary header fields advances to the next data field.
            // The catalog picker and line inputs have their own handlers.
            const fields=[...event.currentTarget.querySelectorAll('input:not([type="hidden"]),select,textarea')]
                .filter(node=>!node.disabled&&node.getClientRects().length>0);
            const idx=fields.indexOf(el);
            if(idx>=0&&idx<fields.length-1)fields[idx+1].focus();
        };
        this.updateLine = (i, k, v) => { const lines = this.state.prLines.map((x, n) => n === i ? { ...x, [k]: v } : x); this.setState({ prLines: lines }); };
        // Search the full catalog on the server, not the first 100 in the workspace cache.
        this.patchPrLine = (scope,i,patch) => {
            if (scope==='edit') {
                const edit=this.state.prEdit;
                if (!edit) return;
                this.setState({prEdit:{...edit,lines:edit.lines.map((x,n)=>n===i?{...x,...patch}:x)},prEditError:''});
            } else {
                this.setState({prLines:this.state.prLines.map((x,n)=>n===i?{...x,...patch}:x),error:''});
            }
        };
        this.loadCatalogRegistryLinks = async () => {
            const org=String(this.state.organization_id||'');if(!org)return;
            // Never display a previous workspace's property associations when
            // switching schools or while the current read is unavailable.
            if(String(this.state.catalogRegistryLinks?.organization_id)!==org)
                this.setState({catalogRegistryLinks:{organization_id:org,links:{}},registryLinkError:''});
            try {const data=await this.get('catalog.registry.links',{organization_id:org});
                if(String(this.state.organization_id)===org)this.setState({catalogRegistryLinks:data,registryLinkError:''});
            } catch(err) {if(String(this.state.organization_id)===org)
                this.setState({catalogRegistryLinks:{organization_id:org,links:{}},registryLinkError:'Property registry links unavailable: '+err.message});}
        };
        this.findRegistryCandidates = async term => {
            const org=String(this.state.organization_id||'');
            if(!org||term.trim().length<2){this.setState({registryLinkOptions:[]});return;}
            const ticket=this.registryTicket=(this.registryTicket||0)+1;
            try {const results=await this.get('catalog.registry.search',{organization_id:org,term});
                if(ticket===this.registryTicket&&String(this.state.organization_id)===org)this.setState({registryLinkOptions:Array.isArray(results)?results:[]});
            } catch(err) {if(ticket===this.registryTicket)this.setState({registryLinkError:err.message,registryLinkOptions:[]});}
        };
        this.openRegistryLink = item => {
            this.setState({registryLinkDialog:item,registryLinkQuery:item.name||'',registryLinkOptions:[],registryLinkError:''},
                ()=>this.findRegistryCandidates(item.name||''));
        };
        this.saveRegistryLink = async row => {
            const item=this.state.registryLinkDialog;
            if(!item||!row||this.state.registryLinkBusy)return;
            const org=String(this.state.organization_id);
            const versions=this.state.catalogRegistryLinks;
            if(!versions||String(versions.organization_id)!==org){
                this.setState({registryLinkError:'Catalog links are not current. Refresh Reference Data before linking.'});return;
            }
            const workspace=this.state.orgs.find(o=>String(o.id)===org);
            const expectedVersion=workspace?.kind==='DIVISION'?versions.division_version_id:versions.local_version_id;
            if(!Number.isInteger(expectedVersion)||expectedVersion<0){
                this.setState({registryLinkError:'Catalog link revision unavailable. Refresh and retry.'});return;
            }
            this.setState({registryLinkBusy:true,registryLinkError:''});
            try {await this.api('master.item.registry.link',{organization_id:Number(org),item_id:Number(item.id),registry_id:row.registry_id,expected_version_id:expectedVersion});
                if(String(this.state.organization_id)!==org)return;
                this.setState({registryLinkDialog:null,registryLinkOptions:[],registryLinkBusy:false,success:'Catalog item linked to Division property type. UACS accounts are unchanged.'});
                this.loadCatalogRegistryLinks();
            } catch(err){this.setState({registryLinkBusy:false,registryLinkError:err.message});this.loadCatalogRegistryLinks();}
        };
        this.registryLinkModal = () => {
            const item=this.state.registryLinkDialog;if(!item)return null;
            const h=React.createElement;
            return h('div',{className:'ipr-overlay',role:'presentation'},h('div',{className:'ipr-dialog',role:'dialog','aria-modal':'true','aria-label':'Link catalog item to Division Property Registry'},
                h('h3',null,'Link to Division Property Registry'),h('p',null,item.name),
                h('p',{className:'hint'},'Select an item TYPE code, not a unique physical property number. Existing UACS assignments are not changed.'),
                h('label',{className:'field'},h('span',null,'Find Division property type'),h('input',{autoFocus:true,type:'search',value:this.state.registryLinkQuery,
                    onChange:e=>{const value=e.target.value;this.setState({registryLinkQuery:value},()=>this.findRegistryCandidates(value));}})),
                this.state.registryLinkError?h('p',{className:'ipr-error',role:'alert'},this.state.registryLinkError):null,
                h('div',{className:'ipr-registry-results'},...this.state.registryLinkOptions.map(row=>h('button',{type:'button',key:row.registry_id,
                    disabled:this.state.registryLinkBusy,onClick:()=>this.saveRegistryLink(row)},
                    h('strong',null,row.description),h('small',null,(row.hv_code||row.reference_code||'')+' '+(row.lv_code||'')+' · '+row.section))),
                    !this.state.registryLinkOptions.length?h('p',{className:'hint'},'Search by description or property code (at least two characters).'):null),
                h('div',{className:'ipr-actions'},h('button',{type:'button',className:'outline',disabled:this.state.registryLinkBusy,
                    onClick:()=>this.setState({registryLinkDialog:null,registryLinkError:''})},'Close'))));
        };
        this.queryPrCatalog = async (term,key) => {
            const ticket=this.prCatalogTicket=(this.prCatalogTicket||0)+1;
            const org=String(this.state.organization_id);
            this.setState({prPickerLoading:true,prPickerError:''});
            try {
                // Optional Division-registry suggestions must never break the
                // established catalog picker if their reference lookup fails.
                const [catalogLookup,registryLookup]=await Promise.allSettled([
                    this.get('catalog.search',{term,organization_id:org}),
                    term.trim().length>=2?this.get('catalog.registry.search',{term,organization_id:org}):Promise.resolve([])]);
                if (ticket!==this.prCatalogTicket || String(this.state.organization_id)!==org || this.state.prPickerOpen!==key) return;
                if(catalogLookup.status==='rejected')throw catalogLookup.reason;
                const results=catalogLookup.value;
                const registry=registryLookup.status==='fulfilled'?registryLookup.value:[];
                this.setState({prPickerResults:Array.isArray(results)?results:[],
                    prRegistryMatches:Array.isArray(registry)?registry:[],prPickerLoading:false,prPickerActive:0,
                    prPickerError:registryLookup.status==='rejected'?'Division registry suggestions are unavailable; existing catalog items can still be selected.':''});
            } catch(err) {
                if (ticket===this.prCatalogTicket && String(this.state.organization_id)===org && this.state.prPickerOpen===key)
                    this.setState({prPickerResults:[],prPickerLoading:false,prPickerError:err.message||'Catalog search failed'});
            }
        };
        this.openPrPicker = (scope,i) => {
            const key=scope+':'+i;
            if (this.prSearchTimer) clearTimeout(this.prSearchTimer);
            this.setState({prPickerOpen:key,prPickerQuery:'',prPickerResults:[],prRegistryMatches:[],prPickerError:'',prPickerActive:0},()=>this.queryPrCatalog('',key));
        };
        this.typePrPicker = (scope,i,value) => {
            const key=scope+':'+i;
            // Typing cancels any previous selection: never submit an old item ID
            // with a new free-text label.
            this.patchPrLine(scope,i,{item_id:'',item_label:'',item_unit:''});
            if(this.prSearchTimer)clearTimeout(this.prSearchTimer);
            this.prCatalogTicket=(this.prCatalogTicket||0)+1;
            this.setState({prPickerOpen:key,prPickerQuery:value,prPickerResults:[],prRegistryMatches:[],prPickerLoading:true,prPickerActive:0,prPickerError:''},()=>{
                this.prSearchTimer=setTimeout(()=>this.queryPrCatalog(value,key),240);
            });
        };
        this.choosePrItem = (scope,i,item) => {
            if(this.prSearchTimer)clearTimeout(this.prSearchTimer);
            this.prCatalogTicket=(this.prCatalogTicket||0)+1;
            this.patchPrLine(scope,i,{item_id:String(item.id),item_label:item.name,item_unit:item.unit});
            this.setState({prPickerOpen:'',prPickerQuery:'',prPickerResults:[],prPickerLoading:false,prPickerError:''});
        };
        this.openPrQuickAdd = (scope,i,registry=null) => {
            if(!this.canOperateWorkspace())return;
            const name=String(registry?.description||this.state.prPickerQuery||'').trim().slice(0,255);
            this.setState({prQuickAdd:{scope,index:i,org:String(this.state.organization_id),name,
                registry_id:registry?.registry_id||'',
                category:registry?(registry.mode==='REFERENCE'?'TEXTBOOK':'SEMI_EXPENDABLE'):'SUPPLY',
                unit_id:'',division_class_code:''},prQuickAddBusy:false,
                prQuickAddError:'',prPickerOpen:'',prPickerResults:[]});
        };
        this.savePrQuickAdd = async e => {
            e.preventDefault();
            const q=this.state.prQuickAdd;
            if(!q || this.state.prQuickAddBusy)return;
            if(!this.canOperateWorkspace() || String(this.state.organization_id)!==q.org){
                this.setState({prQuickAddError:'Workspace changed. Reopen item creation in the correct school.'});return;
            }
            const chosen=(this.state.divisionClassifications||[]).find(c=>c.code===q.division_class_code
                && this.procurementClassMatches(c,q.category));
            if(!chosen){this.setState({prQuickAddError:'Choose an active Division-approved class with an enabled UACS account.'});return;}
            this.setState({prQuickAddBusy:true,prQuickAddError:''});
            try{
                const result=await this.api('master.item.create',{organization_id:Number(q.org),
                    name:q.name.trim(),category:q.category,unit_id:Number(q.unit_id),registry_id:q.registry_id||'',
                    division_class_code:q.division_class_code,
                    school_class_code:q.category==='SUPPLY'?q.division_class_code:'',
                    subcategory:q.category==='SEMI_EXPENDABLE'?q.division_class_code:''});
                // Catalog reference refresh only; preserve all unsaved PR fields and rows.
                let updated={};
                try{
                    const [items,allItems,mappings]=await Promise.all([
                        this.get('list',{kind:'items',organization_id:q.org}),
                        this.get('list',{kind:'all_items',organization_id:q.org}),
                        this.get('division.item_uacs',{organization_id:q.org})]);
                    updated={lists:{...this.state.lists,items,all_items:allItems},itemUacsSettings:mappings};
                }catch(refreshErr){
                    // The item is already committed: keep its ID usable in this PR.
                    updated={prQuickAddNotice:'Item saved, but catalog refresh was interrupted. Refresh Reference Data later.'};
                }
                if(String(this.state.organization_id)!==q.org || !this.state.prQuickAdd)return;
                this.setState({...updated,prQuickAdd:null,prQuickAddError:'',prQuickAddBusy:false,
                    success:'Catalog item added for this '+(this.state.orgs.find(o=>String(o.id)===q.org)?.kind==='DIVISION'?'Division and its schools':'school')+'.'},()=>{
                        this.choosePrItem(q.scope,q.index,{id:result.id,name:result.name||q.name,unit:result.unit||''});
                    });
            }catch(err){this.setState({prQuickAddError:err.message||'Item was not saved.',prQuickAddBusy:false});}
        };
        this.prQuickAddModal = () => {
            const q=this.state.prQuickAdd;if(!q)return null;
            const h=React.createElement;
            const classes=(this.state.divisionClassifications||[]).filter(c=>this.procurementClassMatches(c,q.category));
            const change=(field)=>e=>this.setState({prQuickAdd:{...this.state.prQuickAdd,[field]:e.target.value,
                ...(field==='category'?{division_class_code:''}:{})},prQuickAddError:''});
            const owner=this.state.orgs.find(o=>String(o.id)===q.org);
            return h('div',{className:'ipr-overlay pr-quick-add-backdrop',role:'presentation'},
                h('div',{className:'ipr-dialog pr-quick-add-dialog',role:'dialog','aria-modal':'true','aria-label':'Add catalog item without leaving Purchase Request'},
                    h('h3',null,'Add new catalog item'),
                    h('p',{className:'hint'},owner?.kind==='DIVISION'?'This Division item will be available to all schools in the Division.':'This item will be available only in '+(owner?.name||'this school')+'.'),
                    h('p',{className:'hint'},'All previously entered PR items, quantities and prices will be preserved.'),
                    q.registry_id?h('p',{className:'hint'},'Division property type: '+q.registry_id+'. Choose its approved classification and UACS before saving.'):null,
                    this.state.prQuickAddError?h('p',{className:'ipr-error',role:'alert'},this.state.prQuickAddError):null,
                    h('form',{onSubmit:this.savePrQuickAdd},
                        h('div',{className:'form-grid'},
                            h('label',{className:'field'},h('span',null,'Item description *'),h('input',{required:true,maxLength:255,value:q.name,onChange:change('name'),autoFocus:true})),
                            h('label',{className:'field'},h('span',null,'Unit of measure *'),h('select',{required:true,value:q.unit_id,onChange:change('unit_id')},
                                h('option',{value:''},'Choose unit'),...(this.state.lists.units||[]).map(u=>h('option',{key:u.id,value:String(u.id)},u.code+' — '+u.name)))),
                            h('label',{className:'field'},h('span',null,'Item classification *'),h('select',{value:q.category,onChange:change('category')},
                                ...[['SUPPLY','Consumable supply'],['SEMI_EXPENDABLE','Semi-expendable'],['PPE','PPE'],['TEXTBOOK','Textbook'],['SERVICE','Service']].map(([v,l])=>h('option',{key:v,value:v},l)))),
                            h('label',{className:'field'},h('span',null,'Division-approved class / UACS *'),h('select',{required:true,value:q.division_class_code,onChange:change('division_class_code')},
                                h('option',{value:''},classes.length?'Choose approved class':'No approved class available'),
                                ...classes.map(c=>h('option',{key:c.code,value:c.code},c.name+(c.applies_to==='OTHER_MOOE'?' · Other MOOE':'')+' · '+c.default_uacs_code))))),
                        !classes.length?h('p',{className:'hint'},'Ask the Division Office to configure an active classification and UACS account for this item type.'):null,
                        h('div',{className:'ipr-actions'},
                            h('button',{type:'button',className:'outline',disabled:this.state.prQuickAddBusy,onClick:()=>this.setState({prQuickAdd:null,prQuickAddError:''})},'Back to PR'),
                            h('button',{type:'submit',className:'primary',disabled:this.state.prQuickAddBusy||!classes.length||!q.unit_id},this.state.prQuickAddBusy?'Saving…':'Save and select item')))));
        };
        // Advisory ONLY: never changes the PR row, item category or saved UACS.
        this.requestPrClassification = async (scope,i,line) => {
            const org=String(this.state.organization_id||''), date=scope==='edit'
                ?String(this.state.prEdit?.document_date||''):String(this.state.pr_doc_date||'');
            if(!org||!line.item_id||!(Number(line.unit_price)>0)||!date)return;
            const key=scope+':'+i,stamp=[org,line.item_id,line.unit_price,date].join('|');
            if(this.state.prAccountingPreview[key]?.stamp===stamp||this.state.prAccountingBusy[key]===stamp)return;
            this.setState({prAccountingBusy:{...this.state.prAccountingBusy,[key]:stamp}});
            try {
                const data=await this.get('pr.classification.preview',{organization_id:org,
                    item_id:String(line.item_id),unit_price:String(line.unit_price),as_of:date});
                if(String(this.state.organization_id)!==org)return;
                this.setState({prAccountingPreview:{...this.state.prAccountingPreview,
                    [key]:{stamp,data}}});
            } catch(err) {
                if(String(this.state.organization_id)!==org)return;
                this.setState({prAccountingPreview:{...this.state.prAccountingPreview,
                    [key]:{stamp,error:err.message||'Unable to check preliminary classification'}}});
            } finally {
                if(this.state.prAccountingBusy[key]===stamp)this.setState({prAccountingBusy:{
                    ...this.state.prAccountingBusy,[key]:null}});
            }
        };
        this.prItemPicker = (line,i,scope) => {
            const h=React.createElement,key=scope+':'+i,open=this.state.prPickerOpen===key;
            const choices=this.state.prPickerResults||[];
            const chosen=line.item_id?((line.item_label||((this.state.lists.items||[]).find(x=>String(x.id)===String(line.item_id))?.name)||'Item #'+line.item_id)+(line.item_unit?' · '+line.item_unit:'')):'';
            const value=open?this.state.prPickerQuery:chosen;
            const policyKey=scope+':'+i,policyDate=scope==='edit'?String(this.state.prEdit?.document_date||''):String(this.state.pr_doc_date||'');
            const policyStamp=[this.state.organization_id,line.item_id,line.unit_price,policyDate].join('|');
            const policy=this.state.prAccountingPreview[policyKey]?.stamp===policyStamp?this.state.prAccountingPreview[policyKey]:null;
            return h('div',{className:'field pr-picker'},
                h('label',{htmlFor:'pr-item-'+scope+'-'+i},'Catalog item'),
                h('input',{id:'pr-item-'+scope+'-'+i,type:'search',role:'combobox','aria-autocomplete':'list','aria-expanded':open,
                    'aria-controls':open?'pr-results-'+scope+'-'+i:undefined,
                    autoComplete:'off',placeholder:line.item_id?'Change item…':'Search item name, class or unit…',value,
                    onFocus:()=>{if(!open)this.openPrPicker(scope,i);},
                    onChange:e=>this.typePrPicker(scope,i,e.target.value),
                    onKeyDown:e=>{
                        if(e.key==='Escape'){e.preventDefault();this.setState({prPickerOpen:'',prPickerQuery:'',prPickerResults:[]});}
                        else if(open && e.key==='ArrowDown'){e.preventDefault();this.setState({prPickerActive:Math.min(choices.length-1,this.state.prPickerActive+1)});}
                        else if(open && e.key==='ArrowUp'){e.preventDefault();this.setState({prPickerActive:Math.max(0,this.state.prPickerActive-1)});}
                        else if(e.key==='Enter'){
                            // A catalog-search Enter selects a suggestion, never saves a PR.
                            e.preventDefault();
                            if(open&&choices.length){
                                this.choosePrItem(scope,i,choices[Math.max(0,this.state.prPickerActive)]);
                                this.prFocusField(scope,i,'qty');
                            }else if(!open&&line.item_id)this.prFocusField(scope,i,'qty');
                        }
                    }}),
                open?h('div',{className:'pr-picker-options',id:'pr-results-'+scope+'-'+i,role:'listbox'},
                    this.state.prPickerLoading?h('p',{className:'pr-picker-hint'},'Searching catalog…'):null,
                    this.state.prPickerError?h('p',{className:'pr-picker-hint pr-picker-error'},this.state.prPickerError):null,
                    !this.state.prPickerLoading&&!choices.length&&!this.state.prPickerError?h('p',{className:'pr-picker-hint'},'No matching active catalog items. Check Division registry suggestions below.'):null,
                    ...choices.map((item,j)=>h('button',{type:'button',key:item.id,role:'option',
                        'aria-selected':j===this.state.prPickerActive,
                        className:'pr-picker-option'+(j===this.state.prPickerActive?' chosen':''),
                        onMouseDown:e=>e.preventDefault(),onClick:()=>this.choosePrItem(scope,i,item)},
                        h('strong',null,item.name),h('small',null,(item.unit||'')+' · '+String(item.category||'').replaceAll('_',' ')+(item.subcategory?' · '+item.subcategory.replaceAll('_',' '):'')+' · '+(item.catalog_scope||'Shared')))),
                    !this.state.prPickerLoading&&(this.state.prRegistryMatches||[]).length?h('p',{className:'pr-picker-hint'},'Division Property Registry — link a catalog item or create one:'):null,
                    ...((this.state.prRegistryMatches||[]).map(row=>h('button',{type:'button',key:'registry-'+row.registry_id,
                        className:'pr-picker-option',disabled:!this.canOperateWorkspace(),
                        onMouseDown:e=>e.preventDefault(),onClick:()=>this.openPrQuickAdd(scope,i,row)},
                        h('strong',null,row.description),h('small',null,'Registry type · '+(row.hv_code||row.reference_code||'')+' '+(row.lv_code||''))))),
                    this.canOperateWorkspace()&&this.state.prPickerQuery.trim()&&!this.state.prPickerLoading
                        ?h('button',{type:'button',className:'pr-picker-option pr-quick-add-option',
                            onMouseDown:e=>e.preventDefault(),onClick:()=>this.openPrQuickAdd(scope,i)},'+ Add new item: '+this.state.prPickerQuery.trim()):null) : null,
                line.item_id?h('small',{className:'pr-item-selected'},'Selected: '+(line.item_label||'Item #'+line.item_id)):null,
                line.item_id && Number(line.unit_price)>0?h('div',{className:'pr-accounting-preview'},
                    h('button',{type:'button',className:'pr-accounting-refresh',
                        disabled:Boolean(this.state.prAccountingBusy[policyKey]),
                        onClick:()=>this.requestPrClassification(scope,i,line)},
                        this.state.prAccountingBusy[policyKey]?'Checking…':'Check property / UACS'),
                    policy?.error?h('small',{role:'alert'},'Preliminary reference unavailable: '+policy.error):null,
                    policy?.data?h('div',{className:'pr-accounting-summary',role:'status'},
                        h('strong',null,'Cost bracket: '+String(policy.data.cost_band||'').replaceAll('_',' ')),
                        h('span',null,'Division UACS: '+(policy.data.uacs_code||'Needs review')),
                        policy.data.division_property_type?.reference_code?h('span',null,'Property type: '+policy.data.division_property_type.reference_code):null,
                        ...(policy.data.warnings||[]).map((w,k)=>h('small',{key:k},'Review: '+w)),
                        h('small',null,'Preliminary only · not an approval or accounting entry')):null):null);
        };
        this.startEditPR = (data=this.state.details) => {
            if (!data?.record || !data.edit_allowed || !data.draft_token || !this.canOperateWorkspace())return;
            const r=data.record;
            const lines=(data.lines||[]).map(l=>({item_id:String(l.item_id),item_label:l.description_snapshot,
                item_unit:l.unit_snapshot,quantity:String(l.quantity),unit_price:String(l.estimated_unit_price)}));
            this.loadStaffDirectory();
            this.setState({prEditOpen:true,prEdit:{id:r.id,status:r.status,display_number:r.display_number,draft_token:data.draft_token,
                requestor_employee_id:String(data.requestor?.employee_id||''),requestor_name:data.requestor?.name||'',
                purpose:r.purpose,requesting_unit:r.requesting_unit,document_date:r.document_date||'',fund_id:String(r.fund_id),
                method:data.method||'REVIEW_REQUIRED',lines},prEditError:'',prPickerOpen:'',prPickerResults:[],error:'',success:''});
        };
        this.saveEditPR = async e => {
            e.preventDefault();if(this.state.busy || !this.state.prEdit)return;
            const entry=this.state.prEdit,org=String(this.state.organization_id);
            const lines=entry.lines.filter((line,i)=>!(i===entry.lines.length-1 && !line.item_id && !(Number(line.unit_price)>0)));
            if(entry.requestor_employee_id && entry.requestor_employee_id!==String(this.state.details?.requestor?.employee_id||'') &&
                !(this.state.staffData?.employees||[]).some(x=>x.active&&String(x.employee_id)===String(entry.requestor_employee_id))){
                this.setState({prEditError:'Choose an active employee assigned to this workspace.'});return;
            }
            if(!validPrDate(entry.document_date)){this.setState({prEditError:'Select a valid PR document date.'});return;}
            if(!lines.length || lines.some(l=>!l.item_id)){
                this.setState({prEditError:'Select a catalog item for every line.'});return;
            }
            if(lines.some(l=>!(Number(l.quantity)>0)||!(Number(l.unit_price)>0))){
                this.setState({prEditError:'All quantities and prices must be greater than zero.'});return;
            }
            this.setState({busy:true,prEditError:''});
            try {
                await this.api('pr.update',{organization_id:Number(org),pr_id:Number(entry.id),
                    draft_token:entry.draft_token,purpose:entry.purpose,requesting_unit:entry.requesting_unit,
                    requestor_employee_id:entry.requestor_employee_id||null,document_date:entry.document_date,
                    fund_id:Number(entry.fund_id),method:entry.method,
                    lines:lines.map(l=>({item_id:Number(l.item_id),quantity:l.quantity,unit_price:l.unit_price}))});
                const detail=await this.get('detail',{organization_id:org,type:'PR',id:String(entry.id)});
                if(String(this.state.organization_id)!==org)return;
                this.setState({details:detail,prEditOpen:false,prEdit:null,prPickerOpen:'',prPickerResults:[],
                    success:(entry.status==='FINALIZED'?'Finalized PR #':'Draft PR #')+entry.id+' updated successfully.',prEditError:''});
                // Targeted refresh; a successful save must not be mistaken for a
                // failure merely because an unrelated Dashboard request failed.
                try {
                    const rows=await this.get('list',{organization_id:org,kind:'pr'});
                    if(String(this.state.organization_id)===org)this.setState({lists:{...this.state.lists,pr:rows}});
                } catch (_) { /* register can be refreshed later; saved draft remains valid */ }
            } catch(err) {
                this.setState({prEditError:'Could not complete PR editing: '+(err.message||'Request failed')+'. If the save may have succeeded, close and reopen the PR to verify before retrying.'});
            } finally {this.setState({busy:false});}
        };
        this.profileLabel = type => supplierProfileLabels[type] || type;
        this.supplierProfileActive = (record,type) => {
            const flag=record?.[type.toLowerCase()+'_profile_active'];
            const hasCodes=Boolean(record?.[type.toLowerCase()+'_vat_atc_codes']||record?.[type.toLowerCase()+'_ewt_atc_codes']);
            return flag===null||flag===undefined ? hasCodes : Number(flag)===1;
        };
        this.atcSupportsSupplierProfile = (atc,type) => {
            if(type==='GOODS'||type==='SERVICES'){
                const field=type==='GOODS'?'goods_applicable':'services_applicable';
                const explicit=atc?.[field];
                if(explicit!==null&&explicit!==undefined)return Number(explicit)===1;
                return atc?.supply_type==='BOTH'||atc?.supply_type===type;
            }
            return Number(atc?.[type.toLowerCase()+'_applicable'])===1;
        };
        this.atcProfileNames = atc => supplierProfileTypes.filter(type=>this.atcSupportsSupplierProfile(atc,type)).map(type=>this.profileLabel(type));
        this.editSupplier = (r) => {
            const profiles=supplierProfileTypes.filter(type=>this.supplierProfileActive(r,type)).map(type=>({supply_type:type,
                vat_atc_codes:(r[type.toLowerCase()+'_vat_atc_codes']||'').split(',').filter(Boolean),
                ewt_atc_codes:(r[type.toLowerCase()+'_ewt_atc_codes']||'').split(',').filter(Boolean)}));
            this.setState({masterSection:'suppliers',sup_edit_id:String(r.id),
                sup_name:r.name||'',sup_tax_id:r.tax_id||'',sup_address:r.address||'',sup_proprietor:r.proprietor_name||'',sup_zip:r.zip_code||'',
                sup_commercial:['COMMERCIAL','INDIVIDUAL'].includes(r.business_classification)?r.business_classification:'UNSPECIFIED',
                sup_vat_status:r.vat_status||'UNKNOWN',sup_profiles:profiles,
                sup_legacy_atcs:(r.unclassified_atc_codes||'').split(',').filter(Boolean),
                masterEditorOpen:true,error:'',success:''},()=>document.getElementById('reference-master-editor')?.scrollIntoView({behavior:'smooth',block:'start'}));
        };
        this.resetSupplier = () => this.setState({masterEditorOpen:false,sup_edit_id:'',sup_name:'',sup_proprietor:'',sup_tax_id:'',
            sup_address:'',sup_zip:'',sup_commercial:'UNSPECIFIED',sup_vat_status:'UNKNOWN',sup_atcs:[],
            sup_profiles:[],sup_legacy_atcs:[]});
        this.addSupplierTaxProfile = type => {
            if(!supplierProfileTypes.includes(type)||this.state.sup_profiles.some(p=>p.supply_type===type))return;
            this.setState({sup_profiles:[...this.state.sup_profiles,{supply_type:type,vat_atc_codes:[],ewt_atc_codes:[]}]});
        };
        this.removeSupplierTaxProfile = type => {
            const p=this.state.sup_profiles.find(x=>x.supply_type===type);
            if(!p)return;
            if((p.vat_atc_codes.length||p.ewt_atc_codes.length)&&!window.confirm('Remove the '+this.profileLabel(type).toLowerCase()+' profile and its supplier ATC assignments? Previously issued vouchers will not change.'))return;
            this.setState({sup_profiles:this.state.sup_profiles.filter(x=>x.supply_type!==type)});
        };
        this.toggleSupplierProfileAtc = (type,kind,code,enabled) => {
            const field=kind==='VAT'?'vat_atc_codes':'ewt_atc_codes';
            this.setState(prev=>({sup_profiles:prev.sup_profiles.map(p=>p.supply_type!==type?p:{...p,
                [field]:enabled?[...new Set([...p[field],code])]:p[field].filter(c=>c!==code)})}));
        };
        // The ATC is already committed by the POST before this read begins. Load
        // only the two affected reference lists, sequentially, and retry a temporary
        // HTTP 5xx/network failure once. This avoids an unnecessary workspace-wide
        // burst and never retries the create POST (which could duplicate records).
        this.atcReadWithRetry = async (kind, orgId) => {
            for (let attempt = 0; attempt < 2; attempt++) {
                try { return await this.get('list', {kind, organization_id: orgId}); }
                catch (err) {
                    if (attempt || (err.status && err.status < 500)) throw err;
                    await new Promise(resolve => setTimeout(resolve, 350));
                }
            }
        };
        this.reloadAtcLists = async (options = {}) => {
            const org = String(this.state.organization_id);
            if (!org) return false;
            const ticket = this.atcReloadRequest = (this.atcReloadRequest || 0) + 1;
            this.setState({atc_list_loading: true});
            const updates = {}, errors = [];
            for (const [kind, label] of [['atcs', 'ATC directory'], ['atc_rates', 'Rate history']]) {
                try { updates[kind] = await this.atcReadWithRetry(kind, org); }
                catch (err) { errors.push(label + ': ' + (err.message || 'request failed')); }
                if (ticket !== this.atcReloadRequest || String(this.state.organization_id) !== org) return false;
            }
            const refreshed = errors.length === 0;
            const prefix = options.afterWrite && !refreshed
                ? 'Your ATC change was saved, but the latest lists could not be fully refreshed. Do not submit it again. Select Refresh list to retry. '
                : '';
            this.setState(prev => ({
                lists: {...prev.lists, ...updates},
                atc_list_loading: false,
                error: refreshed ? '' : prefix + errors.join(' | ')
            }));
            return refreshed;
        };
        this.saveAtc = async (e) => {
            e.preventDefault();
            const code=this.state.atc_code.trim().toUpperCase();
            const r=await this.task('master.atc.create',{
                code,description:this.state.atc_description.trim(),business_type:this.state.atc_business_type,tax_kind:this.state.atc_tax_kind,
                profile_category:this.state.atc_profile_category,
                rate_percent:this.state.atc_initial_percent,starts:this.state.atc_initial_start,
                ends:this.state.atc_initial_end,authority_reference:this.state.atc_initial_authority.trim()
            }, {refresh:false});
            if(r){
                this.setState({...this.emptyAtcDraft(),atc_selected_code:'',atc_action:'',atc_dialog:''});
                await this.reloadAtcLists({afterWrite:true});
            }
        };
        // A new ATC is always a fresh draft. Do not retain values after a rejected
        // duplicate-code submission, Cancel, Escape, or the modal close button.
        this.emptyAtcDraft = () => ({
            atc_code:'', atc_description:'', atc_supply_type:'BOTH',atc_profile_category:'',
            atc_goods_applicable:true,atc_services_applicable:true,atc_rental_applicable:false,
            atc_contractor_applicable:false,atc_utilities_applicable:false,atc_other_applicable:false,
            atc_business_type:'BOTH', atc_tax_kind:'',
            atc_initial_percent:'', atc_initial_start:'', atc_initial_end:'',
            atc_initial_authority:''
        });
        this.focusAtcDialog = () => { const target=document.querySelector('.atc-dialog [data-atc-focus]'); if(target)target.focus(); };
        this.openNewAtc = () => {
            this.atcPreviousFocus=document.activeElement;
            this.setState({...this.emptyAtcDraft(),atc_dialog:'new',atc_selected_code:'',atc_action:'',error:'',success:''}, this.focusAtcDialog);
        };
        this.openAtc = (code) => {
            const a=(this.state.lists.atcs||[]).find(x=>x.code===code);
            if(!a)return;
            this.atcPreviousFocus=document.activeElement;
            this.setState({atc_dialog:'manage',atc_selected_code:code,atc_edit_description:a.description||'',atc_edit_supply_type:a.supply_type||'BOTH',atc_edit_profile_category:this.atcProfileNames(a).length===1?supplierProfileTypes.find(type=>this.atcSupportsSupplierProfile(a,type)):'',
                atc_edit_goods_applicable:this.atcSupportsSupplierProfile(a,'GOODS'),atc_edit_services_applicable:this.atcSupportsSupplierProfile(a,'SERVICES'),
                atc_edit_rental_applicable:this.atcSupportsSupplierProfile(a,'RENTAL'),atc_edit_contractor_applicable:this.atcSupportsSupplierProfile(a,'CONTRACTOR'),
                atc_edit_utilities_applicable:this.atcSupportsSupplierProfile(a,'UTILITIES'),atc_edit_other_applicable:this.atcSupportsSupplierProfile(a,'OTHER'),
                atc_edit_business_type:a.business_type||'BOTH',atc_edit_tax_kind:a.tax_kind==='UNCLASSIFIED'?'':(a.tax_kind||''),atc_action:'',rate_atc_code:code,rate_percent:'',rate_starts:'',rate_ends:'',rate_authority:'',rate_close_id:'',rate_close_date:'',error:'',success:''},this.focusAtcDialog);
        };
        this.closeAtcDialog = () => {
            if(this.state.busy)return;
            this.setState({...this.emptyAtcDraft(),atc_dialog:'',atc_action:'',atc_selected_code:'',error:'',success:''},()=>{
                if(this.atcPreviousFocus&&this.atcPreviousFocus.isConnected)this.atcPreviousFocus.focus();
                this.atcPreviousFocus=null;
            });
        };
        this.atcDialogKeyDown = (e) => {
            if(e.key==='Escape'){e.preventDefault();this.closeAtcDialog();return;}
            if(e.key!=='Tab')return;
            const els=Array.from(e.currentTarget.querySelectorAll('button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),a[href]')).filter(el=>el.getClientRects().length>0);
            if(!els.length)return;
            if(e.shiftKey&&document.activeElement===els[0]){e.preventDefault();els[els.length-1].focus();}
            else if(!e.shiftKey&&document.activeElement===els[els.length-1]){e.preventDefault();els[0].focus();}
        };
        this.saveAtcMetadata = async (e) => {
            e.preventDefault();
            const code=this.state.atc_selected_code;
            if(!code)return;
            const r=await this.task('master.atc.update',{code,description:this.state.atc_edit_description.trim(),business_type:this.state.atc_edit_business_type,tax_kind:this.state.atc_edit_tax_kind,
                profile_category:this.state.atc_edit_profile_category}, {refresh:false});
            if(r)await this.reloadAtcLists({afterWrite:true});
        };
        this.saveAtcRate = async (e) => {
            e.preventDefault();
            const code=this.state.atc_selected_code;
            if(!code){this.setState({error:'Choose an ATC first.'});return;}
            const r=await this.task('master.atc.rate.create',{atc_code:code,rate_percent:this.state.rate_percent,starts:this.state.rate_starts,ends:this.state.rate_ends,authority_reference:this.state.rate_authority.trim()}, {refresh:false});
            if(r){this.setState({rate_percent:'',rate_starts:'',rate_ends:'',rate_authority:'',atc_action:''});await this.reloadAtcLists({afterWrite:true});}
        };
        this.closeAtcRate = async (e) => {
            e.preventDefault();
            const rates=(this.state.lists.atc_rates||[]).filter(r=>r.atc_code===this.state.atc_selected_code && !r.ends);
            if(rates.length!==1){this.setState({error:'Cannot close rate: expected exactly one open-ended version for the selected ATC.'});return;}
            const id=Number(rates[0].id);
            if(!window.confirm('Close '+this.state.atc_selected_code+' rate through '+this.state.rate_close_date+'? Historical DVs remain unchanged.'))return;
            const r=await this.task('master.atc.rate.close',{rate_version_id:id,ends:this.state.rate_close_date}, {refresh:false});
            if(r){this.setState({rate_close_id:'',rate_close_date:'',atc_action:''});await this.reloadAtcLists({afterWrite:true});}
        };
        this.toggleAtc = async (r) => {if(!window.confirm((Number(r.active)?'Deactivate':'Reactivate')+' ATC '+r.code+'? Existing vouchers retain their rates.'))return;const ok=await this.task('master.atc.status',{code:r.code,active:!Number(r.active)}, {refresh:false});if(ok)await this.reloadAtcLists({afterWrite:true});};
        // A Regular DV uses ONE supplier tax profile. Auto-select only when
        // precisely one is configured. Multiple profiles require user's choice.
        this.chooseDvPo = (id) => {
            this.dvTaxTicket=(this.dvTaxTicket||0)+1;
            const row=this.state.eligible.find(r=>String(r.po_id)===String(id));
            if(id && (!row || (Array.isArray(row.existing_dvs) && row.existing_dvs.length))){
                this.setState({error:row?this.sourceVoucherMessage(row):'This Purchase Order is no longer available. Reload the eligible POs.',
                    dv_po:'',dv_po_tax_profile:'',dvTaxPreview:null,dvTaxes:[]});
                return;
            }
            const options=row?this.allocationProfileOptions(row):[];
            const profile=options.length===1?options[0]:'';
            this.setState({dv_po:id,dv_po_tax_profile:profile,dvInvoiceVat:'',dvVatMode:'STANDARD',dvVatExceptionReason:'',
                dvTaxPreview:null,dvTaxError:'',dvTaxBusy:false,dvTaxes:[]},()=>{
                if(profile&&row)this.loadDvTaxPreview();
            });
        };
        this.chooseDvTaxProfile = (profile) => {
            this.dvTaxTicket=(this.dvTaxTicket||0)+1;
            this.setState({dv_po_tax_profile:profile,dvTaxPreview:null,dvTaxError:'',dvTaxBusy:false,dvTaxes:[]},()=>{
                const po=this.state.eligible.find(r=>String(r.po_id)===String(this.state.dv_po));
                if(profile&&po)this.loadDvTaxPreview();
            });
        };
        this.loadDvTaxPreview = async () => {
            const poId=String(this.state.dv_po||''),profile=this.state.dv_po_tax_profile;
            const po=this.state.eligible.find(r=>String(r.po_id)===poId);
            const invoiceVat=this.state.dvInvoiceVat;
            const vatMode=this.state.dvVatMode,exceptionReason=this.state.dvVatExceptionReason;
            const workspace=String(this.state.organization_id||'');
            const ticket=this.dvTaxTicket=(this.dvTaxTicket||0)+1;
            if(!po||!profile){this.setState({dvTaxPreview:null,dvTaxError:'Select the PO and its supplier tax profile.'});return;}
            if(po.vat_status==='VAT'&&vatMode==='INVOICE_OVERRIDE'&&
                (!/^\d{1,15}\.\d{2}$/.test(invoiceVat)||exceptionReason.trim().length<10)){
                this.setState({dvTaxPreview:null,dvTaxError:'Enter the invoice VAT and a documented reason (at least 10 characters) for this exception.'});return;
            }
            this.setState({dvTaxBusy:true,dvTaxError:'',dvTaxPreview:null});
            try{
                const data=await this.get('dv.tax.preview',{
                    organization_id:workspace,po_id:poId,profile_type:profile,vat_mode:vatMode,
                    invoice_vat:po.vat_status==='VAT'&&vatMode==='INVOICE_OVERRIDE'?invoiceVat:'',
                    exception_reason:vatMode==='INVOICE_OVERRIDE'?exceptionReason:''});
                if(ticket===this.dvTaxTicket&&workspace===String(this.state.organization_id)&&
                   poId===String(this.state.dv_po)&&profile===this.state.dv_po_tax_profile&&invoiceVat===this.state.dvInvoiceVat&&
                   vatMode===this.state.dvVatMode&&exceptionReason===this.state.dvVatExceptionReason){
                    this.setState({dvTaxBusy:false,dvTaxPreview:data,dvTaxError:''});
                }
            }catch(e){if(ticket===this.dvTaxTicket)this.setState({dvTaxBusy:false,dvTaxPreview:null,dvTaxError:e.message||'Unable to calculate withholding.'});}
        };
        this.regularDvTaxesView = (po,money) => {
            const h=React.createElement,p=this.state.dvTaxPreview;
            return h('section',{className:'dv-form-panel'},h('h3',null,'Withholding taxes · Automatic calculation'),
                h('p',{className:'body-muted'},'IPDAMS reads the selected supplier profile, configured VAT-withholding and EWT ATCs, and effective rate versions. Amounts are recalculated by the server when the Draft DV is saved.'),
                po?.vat_status==='VAT'?h('div',{className:'section-stack'},
                    h('p',{className:'hint'},'Standard VAT-inclusive invoices are calculated automatically using the Division VAT rate in Reference Data (12% by default). This is NOT the withholding rate.'),
                    h('label',{className:'field'},h('span',null,'VAT treatment'),
                        h('select',{value:this.state.dvVatMode,onChange:e=>{this.dvTaxTicket=(this.dvTaxTicket||0)+1;this.setState({dvVatMode:e.target.value,dvInvoiceVat:'',dvVatExceptionReason:'',dvTaxPreview:null,dvTaxError:''},()=>{if(e.target.value==='STANDARD'&&this.state.dv_po_tax_profile)this.loadDvTaxPreview();});}},
                            h('option',{value:'STANDARD'},'Standard VAT-inclusive · calculate automatically'),
                            h('option',{value:'INVOICE_OVERRIDE'},'Different invoice VAT treatment · documented exception'))),
                    this.state.dvVatMode==='INVOICE_OVERRIDE'?h('div',{className:'form-grid'},
                        h('label',{className:'field'},h('span',null,'VAT amount on actual invoice (₱) *'),
                            h('input',{required:true,type:'text',inputMode:'decimal',value:this.state.dvInvoiceVat,placeholder:'0.00',
                                onChange:e=>{this.dvTaxTicket=(this.dvTaxTicket||0)+1;this.setState({dvInvoiceVat:e.target.value,dvTaxPreview:null,dvTaxError:''});}})),
                        h('label',{className:'field'},h('span',null,'Reason / evidence for exception *'),
                            h('textarea',{required:true,rows:2,minLength:10,maxLength:500,value:this.state.dvVatExceptionReason,
                                onChange:e=>{this.dvTaxTicket=(this.dvTaxTicket||0)+1;this.setState({dvVatExceptionReason:e.target.value,dvTaxPreview:null,dvTaxError:''});},
                                placeholder:'Explain invoice treatment, VAT-exclusive pricing, exemption or zero rating, citing evidence'}))):null):null,
                po?.vat_status==='UNKNOWN'?h('p',{className:'notice error',role:'alert'},'Supplier VAT status is unverified. Ask Division to correct the Supplier Profile.'):null,
                po&&this.allocationProfileOptions(po).length>1&&!this.state.dv_po_tax_profile?
                    h('p',{className:'notice',role:'status'},'This supplier has multiple profiles. Choose the profile that applies to this PO before calculating withholding.'):null,
                h('button',{type:'button',className:'outline',disabled:!po||!this.state.dv_po_tax_profile||this.state.dvTaxBusy,
                    onClick:this.loadDvTaxPreview},this.state.dvTaxBusy?'Calculating…':'Recalculate taxes'),
                this.state.dvTaxError?h('p',{className:'notice error',role:'alert'},this.state.dvTaxError):null,
                p?h('div',{className:'section-stack'},
                    h('p',{className:'hint'},'Supplier: '+(po?.original_supplier||'—')+' · '+this.profileLabel(p.profile)+' · VAT status: '+p.vat_status),
                    p.vat_status==='VAT'?h('p',{className:'hint'},'VAT rate: '+p.vat_rate_percent+'% ('+(p.vat_system_default?'system default, Division review recommended':'Division setting #'+p.vat_rate_version)+') · VAT component: '+money(p.invoice_vat)+' · VAT-exclusive withholding base: '+money(p.tax_base)):null,
                    h('div',{className:'table-scroll'},h('table',{className:'master-table'},
                        h('thead',null,h('tr',null,...['Tax type','ATC','Rate','Base','Withheld'].map(x=>h('th',{key:x},x)))),
                        h('tbody',null,...(p.components||[]).map(t=>h('tr',{key:t.atc_code},
                            h('td',null,t.tax_kind==='VAT'?'VAT withholding':'EWT'),h('td',null,t.atc_code),
                            h('td',null,(Number(t.rate)*100).toFixed(2)+'%'),
                            h('td',null,money(t.tax_base)),h('td',null,money(t.amount))))))),
                    h('div',{className:'dv-detail-grid'},
                        h('div',{className:'dv-readonly'},h('span',null,'Gross PO payable'),h('strong',null,money(p.gross))),
                        h('div',{className:'dv-readonly'},h('span',null,'Withholding'),h('strong',null,money(p.deductions))),
                        h('div',{className:'dv-readonly'},h('span',null,'Net supplier payment'),h('strong',null,money(p.net)))),
                    h('p',{className:'hint'},p.review_notice),
                    h('p',{className:'hint'},'Tax disposition: '+(p.fund_policy?.disposition==='SCHOOL_UTILIZATION'?'School utilization under Division policy (not yet credited or spent).':p.fund_policy?.disposition==='REMIT'?'For remittance under Division policy.':'Not configured; draft preparation blocked.')+' Due to BIR remains a liability; no tax settlement, new allotment or bank receipt is recorded.')):
                    h('p',{className:'hint'},po?'Choose the applicable supplier profile. Standard invoice VAT is calculated automatically; review the exception option only when required.':'Select a PO to see withholding calculations.'));
        };
        this.updateDvTax = (index,field,value) => this.setState({dvTaxes:this.state.dvTaxes.map((row,i)=>i===index?{...row,[field]:value}:row)});
        this.addDvTax = (taxKind='EWT') => this.setState({dvTaxes:[...this.state.dvTaxes,{tax_kind:taxKind,rate_version_id:'',allocation_index:'0',tax_base:'',disposition:'REMIT'}]});
        this.allocationProfileOptions = record => supplierProfileTypes.filter(type=>this.supplierProfileActive(record,type));
        this.defaultAllocationTaxProfile = record => {
            const options=this.allocationProfileOptions(record);
            if(record?.item_category==='MIXED')return '';
            if(record?.item_category==='SERVICE'){
                const serviceProfiles=options.filter(type=>type!=='GOODS');
                return serviceProfiles.length===1?serviceProfiles[0]:'';
            }
            if(options.includes('GOODS'))return 'GOODS';
            return options.length===1?options[0]:'';
        };
        this.supplierProfileAtcCodes = (record,profile) => {
            if(!record||!profile)return [];
            const prefix=profile.toLowerCase();
            return [...new Set([...(record[prefix+'_vat_atc_codes']||'').split(',').filter(Boolean),...(record[prefix+'_ewt_atc_codes']||'').split(',').filter(Boolean)])];
        };
        this.suggestSupplierTaxes = () => {
            const isPO=this.state.dv_type==='REGULAR';
            const po=isPO?this.state.eligible.find(r=>String(r.po_id)===String(this.state.dv_po)):null;
            if(isPO && !po){this.setState({error:'Select the Purchase Order before suggesting withholding tax.'});return;}
            if(isPO && !this.state.dv_po_tax_profile){this.setState({error:'Select the applicable supplier tax profile for this PO.'});return;}
            const selected=isPO?[[String(po.po_id),{tax_profile:this.state.dv_po_tax_profile}]]:
                Object.entries(this.state.allocations).filter(([_,v])=>v.enabled);
            const today=new Intl.DateTimeFormat('sv-SE',{timeZone:'Asia/Manila'}).format(new Date());
            const proposals=[]; let unconfigured=0,needsProfile=0;
            selected.forEach(([key,allocation],index)=>{
                const record=isPO?po:this.state.eligible.find(r=>this.allocationKey(this.state.dv_type,r)===key);
                if(!record?.supplier_id)return;
                const profile=allocation.tax_profile||this.defaultAllocationTaxProfile(record);
                if(!profile){needsProfile++;return;}
                const codes=this.supplierProfileAtcCodes(record,profile);
                for(const code of codes){
                    const atcMeta=(this.state.lists.atcs||[]).find(a=>a.code===code);
                    if(!atcMeta||!['VAT','EWT'].includes(atcMeta.tax_kind)) continue;
                    const rate=(this.state.lists.atc_rates||[]).find(r=>r.atc_code===code&&Number(r.atc_active)&&r.starts<=today&&(!r.ends||r.ends>=today));
                    if(!rate){unconfigured++;continue;}
                    const already=[...this.state.dvTaxes,...proposals].some(x=>String(x.allocation_index)===String(index)&&String(x.rate_version_id)===String(rate.id));
                    if(!already)proposals.push({tax_kind:atcMeta.tax_kind,rate_version_id:String(rate.id),allocation_index:String(index),tax_base:'',disposition:'REMIT'});
                }
            });
            const errors=[];
            if(needsProfile)errors.push('Choose the supplier tax profile before suggesting ATCs.');
            if(unconfigured)errors.push(unconfigured+' supplier ATC(s) have no active rate today; check ATC maintenance.');
            this.setState({dvTaxes:[...this.state.dvTaxes,...proposals],
                success:proposals.length?proposals.length+' ATC suggestion(s) added. Verify the PO-wide tax base and disposition before saving.':'No new effective supplier ATCs available.',
                error:errors.join(' ')});
        };
        this.removeDvTax = (i) => this.setState({dvTaxes:this.state.dvTaxes.filter((_,j)=>i!==j)});
        this.createDV = async (e) => {
            e.preventDefault();
            if (!this.canOperateWorkspace()) {this.setState({error:'Read-only oversight: only the owning organization can prepare DVs.'});return;}
            const t=this.state.dv_type;
            const taxes=this.state.dvTaxes.map(tax=>({tax_kind:tax.tax_kind,rate_version_id:Number(tax.rate_version_id),
                ...(t==='REGULAR'?{}:{allocation_index:Number(tax.allocation_index)}),
                tax_base:tax.tax_base,disposition:tax.disposition}));
            if(t==='REGULAR'){
                const po=this.state.eligible.find(r=>String(r.po_id)===String(this.state.dv_po));
                if(!po){this.setState({error:'Select a PO with accepted, unpaid deliveries.'});return;}
                if(po.existing_dvs?.length){this.setState({error:this.sourceVoucherMessage(po)});return;}
                if(po.business_classification==='INDIVIDUAL'&&!po.proprietor_name){
                    this.setState({error:'Update the supplier profile with the proprietor’s legal name before preparing the DV.'});return;
                }
                if(!this.state.dv_po_tax_profile||!this.state.dvTaxPreview||this.state.dvTaxBusy){
                    this.setState({error:'Choose the supplier tax profile and complete the automatic withholding preview before saving.'});return;
                }
                const result=await this.task('dv.create',{dv_type:'REGULAR',po_id:Number(po.po_id),
                    tax_profile:this.state.dv_po_tax_profile,
                    expected_vat_version:Number(this.state.dvTaxPreview.vat_rate_version),
                    vat_mode:this.state.dvVatMode,
                    invoice_vat:po.vat_status==='VAT'&&this.state.dvVatMode==='INVOICE_OVERRIDE'?this.state.dvInvoiceVat:'',
                    exception_reason:this.state.dvVatMode==='INVOICE_OVERRIDE'?this.state.dvVatExceptionReason:''});
                if(result){this.dvTaxTicket=(this.dvTaxTicket||0)+1;
                    this.setState({selectedDv:String(result.id),dv_po:'',dv_po_tax_profile:'',dvInvoiceVat:'',dvVatMode:'STANDARD',dvVatExceptionReason:'',dvTaxPreview:null,dvTaxError:'',dvTaxes:[],dvComposeOpen:false,dvSearch:''},()=>this.openDetail('DV',Number(result.id)));}
                return;
            }
            const key=t==='DIRECT'?'pr_id':'advance_id';
            const enabled=Object.entries(this.state.allocations).filter(([_,v])=>v.enabled);
            const records=enabled.map(([id])=>this.state.eligible.find(r=>this.allocationKey(t,r)===id));
            if(!enabled.length||records.some(r=>!r)){this.setState({error:'Select an eligible PR or supported advance.'});return;}
            if(enabled.length!==1){this.setState({error:'Select only one PR or advance for a DV.'});return;}
            if(records[0].existing_dvs?.length){this.setState({error:this.sourceVoucherMessage(records[0])});return;}
            if(records.some(r=>String(r.fund_id)!==String(records[0].fund_id))){
                this.setState({error:'Selected documents charge different funds; prepare separate DVs.'});return;
            }
            let payee=this.state.payee.trim();
            if(t==='REIMBURSEMENT'){
                if(records.some(r=>r.original_payer!==records[0].original_payer)){
                    this.setState({error:'One reimbursement DV cannot combine different original payers.'});return;
                }
                payee=records[0].original_payer;
            }
            const result=await this.task('dv.create',{dv_type:t,payee,fund_id:Number(records[0].fund_id),
                bill_reference:t==='DIRECT'?this.state.bill_reference:undefined,
                allocations:enabled.map(([id,v])=>({[key]:Number(id),amount:v.amount})),withholding:taxes});
            if(result)this.setState({selectedDv:String(result.id),allocations:{},dvTaxes:[],payee:'',dvComposeOpen:false,dvSearch:''},()=>this.openDetail('DV',Number(result.id)));
        };
        this.manualTaxPostingBlocked = (dv) => {
            if(!dv || !(Number(dv.deductions)>0))return false;
            const org=this.state.orgs.find(o=>String(o.id)===String(this.state.organization_id));
            const fund=(this.state.lists.funds||[]).find(f=>String(f.id)===String(dv.fund_id));
            const auto=org?.kind==='SCHOOL'&&dv.dv_type==='REGULAR'&&fund?.source_code==='MOOE'&&
                this.state.fundPolicies?.school_classification?.classification==='NON_IUS';
            return !auto;
        };
        this.finalizeDV = async (e) => { e.preventDefault(); if (!this.canOperateWorkspace()) {this.setState({error:'Read-only oversight: Division accounts cannot finalize school DVs.'});return;} const dv = this.state.lists.dv?.find((x) => String(x.id) === this.state.selectedDv); if (!dv) {
            this.setState({ error: 'Select an existing draft DV' });
            return;
        }
        const fund=(this.state.lists.funds||[]).find(f=>String(f.id)===String(dv.fund_id));
        const selectedOrg=this.state.orgs.find(o=>String(o.id)===String(this.state.organization_id));
        const autoAdvance=selectedOrg?.kind==='SCHOOL'&&dv.dv_type==='REGULAR' && fund?.source_code==='MOOE' &&
            this.state.fundPolicies?.school_classification?.classification==='NON_IUS';
        if(this.manualTaxPostingBlocked(dv)){
            this.setState({error:'Manual taxable DV finalization is blocked pending the Division Accountant’s approved multi-line journal and UACS configuration. The DV remains draft.'});return;
        }
        const payload={dv_id:Number(dv.id)};
        if(!autoAdvance)payload.accounting=[{uacs_code:this.state.debitAccount,debit:dv.gross,credit:'0'},
            {uacs_code:this.state.creditAccount,debit:'0',credit:dv.gross}];
        await this.task('dv.finalize',payload);
    };
        this.fetchNextPhysicalCheck = async (bankId, target='issue') => {
            const org=String(this.state.organization_id||'');
            if(!bankId){this.setState(target==='finalize'?{dvCheckStock:null,dvCheckLoading:false}:{checkStockPreview:null,checkNo:'',checkLeafConfirmed:false});return;}
            if(target==='finalize')this.setState({dvCheckLoading:true,dvCheckStock:null});
            else this.setState({checkStockPreview:null,checkNo:'',checkLeafConfirmed:false});
            try{
                const record=await this.get('check.stock.preview',{organization_id:org,bank_account_id:Number(bankId)});
                if(String(this.state.organization_id)!==org || String(target==='finalize'?this.state.dvCheckBank:this.state.checkBank)!==String(bankId))return;
                this.setState(target==='finalize'?{dvCheckStock:record,dvCheckLoading:false}:{checkStockPreview:record,checkNo:record.check_number||'',checkLeafConfirmed:false});
            }catch(error){if(String(this.state.organization_id)===org)this.setState(target==='finalize'?{dvCheckStock:{message:error.message},dvCheckLoading:false}:{checkStockPreview:{message:error.message},checkNo:'',checkLeafConfirmed:false});}
        };
        this.loadPhysicalCheckStock = async()=>{
            const org=String(this.state.organization_id||'');if(!org)return;
            this.setState({checkStockLoading:true,checkStockError:''});
            try{const data=await this.get('check.stock.list',{organization_id:org});
                if(String(this.state.organization_id)===org)this.setState({checkStockList:data||{rows:[],stats:[]},checkStockLoaded:true,checkStockLoading:false});
            }catch(error){if(String(this.state.organization_id)===org)this.setState({checkStockList:{rows:[],stats:[]},checkStockLoaded:false,checkStockLoading:false,checkStockError:error.message,error:error.message});}
        };
        this.registerPhysicalCheckStock = async(e)=>{
            e.preventDefault();if(!this.state.checkbookVerified)return this.setState({error:'Confirm the actual blank physical check leaves first.'});
            const saved=await this.task('check.stock.register',{bank_account_id:Number(this.state.checkbookBank),book_reference:this.state.checkbookReference.trim(),first_serial:this.state.checkbookFirst.trim(),last_serial:this.state.checkbookLast.trim(),physically_verified_blank:true},{refresh:false});
            if(saved){this.setState({checkbookReference:'',checkbookFirst:'',checkbookLast:'',checkbookVerified:false,success:'Verified physical check stock registered; no check was issued.'});await this.loadPhysicalCheckStock();
                if(this.state.checkBank===this.state.checkbookBank)await this.fetchNextPhysicalCheck(this.state.checkBank);}
        };
        this.markPhysicalCheckUnusable = async(row)=>{
            const status=window.prompt('Status for UNISSUED paper leaf '+row.serial+': SPOILED, LOST, or VOID','SPOILED');
            if(!status)return;const reason=window.prompt('Reason and supporting physical check custody notes (at least 12 characters):','');
            if(!reason)return;
            const saved=await this.task('check.stock.unusable',{leaf_id:Number(row.id),status:status.toUpperCase().trim(),reason},{refresh:false});
            if(saved){await this.loadPhysicalCheckStock();if(this.state.checkBank)await this.fetchNextPhysicalCheck(this.state.checkBank);}
        };
        this.correctUnissuedCheckLeaf = async(row)=>{
            if(!['SPOILED','VOID'].includes(row.status)||row.issued_check_id)return;
            if(!window.confirm('Correct serial '+row.serial+' from '+row.status+' back to AVAILABLE? Only if the ACTUAL leaf is intact, completely blank, unsigned, unprinted, and never issued.'))return;
            const reason=window.prompt('Describe the mistaken stock status and custody verification (at least 20 characters):','');
            if(!reason)return;
            const saved=await this.task('check.stock.correct',{leaf_id:Number(row.id),status:'AVAILABLE',
                confirmed_physical_leaf:true,never_printed_signed_released:true,reason},{refresh:false});
            if(saved){await this.loadPhysicalCheckStock();if(this.state.checkBank)await this.fetchNextPhysicalCheck(this.state.checkBank);}
        };
        this.createGuidedCheck = async (e) => {
            e.preventDefault();
            const dv=(this.state.lists.dv||[]).find(v=>String(v.id)===this.state.checkDv);
            if(!dv||dv.status!=='FINALIZED'){this.setState({error:'Select a finalized DV'});return;}
            const isRegular=dv.dv_type==='REGULAR'&&Number(dv.supplier_id)>0;
            const basis=isRegular?this.state.checkPayeeBasis:'DV_PAYEE';
            if(basis==='PROPRIETOR'&&!dv.proprietor_name_snapshot){this.setState({error:'No verified proprietor in the finalized DV'});return;}
            if(!this.state.checkStockPreview?.leaf_id || !this.state.checkLeafConfirmed){this.setState({error:'Verify the next physical paper serial and confirm it matches before issuing a check.'});return;}
            if(!window.confirm('Issue check #'+this.state.checkNo+' payable to '+(basis==='PROPRIETOR'?dv.proprietor_name_snapshot:basis==='BUSINESS'?dv.business_name_snapshot:dv.payee)+' for ₱'+this.state.checkAmount+'? The payee cannot be silently changed afterward.'))return;
            const created=await this.task('check.create',{dv_id:Number(dv.id),bank_account_id:Number(this.state.checkBank),
                check_number:this.state.checkNo.trim(),expected_leaf_id:Number(this.state.checkStockPreview.leaf_id),confirmed_physical_leaf:true,amount:this.state.checkAmount,payee_basis:basis});
            if(created){this.setState({checkDv:'',checkBank:'',checkNo:'',checkAmount:'',checkPayeeBasis:'BUSINESS',checkStockPreview:null,checkLeafConfirmed:false});await this.loadPhysicalCheckStock();}
        };
        this.loadDivisionRisLines = async () => {
            if(!schoolScopedDivision(this.state)||!this.state.ris_id)return;
            const org=String(this.state.organization_id),id=String(this.state.ris_id);
            this.setState({divisionRisLines:[],divisionRisLots:[],ris_line_id:'',ris_lot_id:''});
            try{
                const rows=await this.get('division.ris.lines',{organization_id:org,ris_id:id});
                if(String(this.state.organization_id)===org && String(this.state.ris_id)===id)this.setState({divisionRisLines:rows});
            }catch(e){if(String(this.state.organization_id)===org)this.setState({error:e.message||'Unable to load RIS lines'});}
        };
        this.loadDivisionRisLots = async () => {
            if(!schoolScopedDivision(this.state)||!this.state.ris_line_id)return;
            const org=String(this.state.organization_id),line=String(this.state.ris_line_id);
            this.setState({divisionRisLots:[],ris_lot_id:''});
            try{
                const rows=await this.get('division.ris.lots',{organization_id:org,ris_line_id:line});
                if(String(this.state.organization_id)===org && String(this.state.ris_line_id)===line)this.setState({divisionRisLots:rows});
            }catch(e){if(String(this.state.organization_id)===org)this.setState({error:e.message||'Unable to load eligible RIS lots'});}
        };
        this.createRIS = async (e) => { e.preventDefault(); const data = { source_mode: this.state.ris_mode, requestor: this.state.ris_requestor }; if (this.state.ris_mode === 'PR')
            data.pr_id = Number(this.state.ris_pr);
        else
            data.lines = [{ item_id: Number(this.state.ris_item), requested_qty: this.state.ris_qty }]; const r = await this.task('ris.create', data); if (r)
            this.setState({ ris_id: String(r.id) }); };
        this.issueRIS = async (e) => { e.preventDefault(); await this.task('ris.issue', { ris_id: Number(this.state.ris_id), lines: [{ ris_line_id: Number(this.state.ris_line_id), lot_id: Number(this.state.ris_lot_id), issued_qty: this.state.ris_issue_qty }] }); };
        this.loadTextbookBatches = async () => {
            const org=String(this.state.organization_id||'');
            if(!org)return this.setState({tbBatches:[],tbError:'',tbLoading:false});
            // Never show another organization's cached textbook balance while loading.
            this.setState({tbLoading:true,tbError:'',tbBatches:[]});
            try {
                const rows=await this.get('list',{kind:'textbook_batches',organization_id:org});
                if(String(this.state.organization_id)===org)this.setState({tbBatches:rows||[],tbLoading:false});
            } catch(error) {
                if(String(this.state.organization_id)===org)this.setState({tbLoading:false,tbError:error.message});
            }
        };
        this.loadTextbookHistory = async (older=false) => {
            const org=String(this.state.organization_id||'');
            const month=this.state.tbHistoryMonth||'';
            const batch=this.state.tbHistoryBatch||'';
            const previous=older?(this.state.tbHistory||[]):[];
            const before=older&&previous.length?previous[previous.length-1].id:'';
            const request=this.textbookHistoryRequest=(this.textbookHistoryRequest||0)+1;
            if(!org)return this.setState({tbHistory:[],tbHistoryError:'',tbHistoryHasMore:false,tbHistoryLoading:false});
            this.setState({tbHistoryLoading:true,tbHistoryError:'',...(older?{}:{tbHistory:[],tbHistoryHasMore:false})});
            try {
                const rows=await this.workspaceRead('list',{kind:'textbook_movements',organization_id:org,
                    ...(month?{month}:{}),...(batch?{batch_id:batch}:{}),...(before?{before_id:before}:{})});
                if(request!==this.textbookHistoryRequest || String(this.state.organization_id)!==org ||
                   (this.state.tbHistoryMonth||'')!==month || (this.state.tbHistoryBatch||'')!==batch)return;
                const page=(rows||[]).slice(0,100);
                this.setState({tbHistory:older?previous.concat(page):page,tbHistoryHasMore:(rows||[]).length>100,
                    tbHistoryLoading:false});
            } catch(error) {
                if(request===this.textbookHistoryRequest && String(this.state.organization_id)===org)
                    this.setState({tbHistoryLoading:false,tbHistoryError:error.message||'Unable to load history.'});
            }
        };
        this.createTextbookOpening = async e => {
            e.preventDefault();
            if(!this.state.tb_title.trim()||!this.state.tb_batch.trim()||
               !/^[1-9][0-9]*$/.test(this.state.tb_opening_qty))return this.setState({error:'Enter a title, batch and positive whole quantity.'});
            const result=await this.task('textbook.opening',{
                title:this.state.tb_title.trim(),isbn:this.state.tb_isbn.trim(),grade:this.state.tb_grade.trim(),
                subject:this.state.tb_subject.trim(),batch:this.state.tb_batch.trim(),
                condition_note:this.state.tb_condition_note.trim(),storage:this.state.tb_storage.trim(),
                quantity:this.state.tb_opening_qty
            },{refresh:false});
            if(result){
                this.setState({tb_title:'',tb_isbn:'',tb_grade:'',tb_subject:'',tb_batch:'',
                    tb_condition_note:'',tb_storage:'',tb_opening_qty:'1',tb_move_batch_id:String(result.batch_id)});
                await this.loadTextbookBatches();
                await this.loadTextbookHistory();
            }
        };
        this.createTextbookMove = async e => {
            e.preventDefault();
            if(!this.state.tb_move_batch_id||!/^[1-9][0-9]*$/.test(this.state.tb_move_quantity)||
               !this.state.tb_move_reference.trim())return this.setState({error:'Select a batch, enter a positive whole quantity and a reference.'});
            const data={batch_id:Number(this.state.tb_move_batch_id),
                event_type:this.state.tb_move_event_type,quantity:this.state.tb_move_quantity,
                reference:this.state.tb_move_reference.trim()};
            if(data.event_type==='COUNT_ADJUSTMENT')data.direction=this.state.tb_move_direction;
            const result=await this.task('textbook.move',data,{refresh:false});
            if(result){this.setState({tb_move_quantity:'1',tb_move_reference:''});await this.loadTextbookBatches();await this.loadTextbookHistory();}
        };
        this.runAdvanced = async (e) => { e.preventDefault(); try {
            const body = JSON.parse(this.state.advancedBody);
            const r = await this.task(this.state.advancedRoute, body);
            if (r)
                this.setState({ details: r });
        }
        catch (e) {
            this.setState({ error: 'Payload JSON invalid: ' + e.message });
        } };
        this.search = async (e) => { e.preventDefault(); try {
            const rows = await this.get('search', { term: this.state.searchTerm });
            this.setState({ searchResults: rows, error: '' });
        }
        catch (e) {
            this.setState({ error: e.message });
        } };
        this.beginDvEdit = (data=this.state.details) => {
            if(!data?.edit_allowed || !data?.draft_token || !this.canOperateWorkspace())return;
            const r=data.record,lines=data.lines||[];
            const entry={id:r.id,dv_type:r.dv_type,status:r.status,
                bill_reference:r.bill_reference||'',reason:'',expected_token:data.draft_token,
                uacs_allocations:(data.advance_liquidation?.item_diagnostics||[]).map(row=>({
                    allocation_id:Number(row.allocation_id),uacs_code:String(row.override_uacs_code||'')}))};
            this.setState({dvEdit:entry,dvEditOriginal:JSON.stringify(entry),dvEditError:'',error:''});
        };
        this.dismissDvEdit = () => {
            if(this.state.dvEdit && JSON.stringify(this.state.dvEdit)!==this.state.dvEditOriginal &&
                !window.confirm('Discard unsaved DV changes?'))return;
            // Back returns to a read-only view of THIS SAME saved DV; it never opens another DV.
            this.setState({dvEdit:null,dvEditOriginal:'',dvEditError:''});
        };
        this.closeDvDetail = () => {
            if(this.state.busy)return;
            if(this.state.dvEdit && JSON.stringify(this.state.dvEdit)!==this.state.dvEditOriginal &&
                !window.confirm('Discard unsaved DV changes and close this voucher?'))return;
            // Close is distinct from Back: remove the entire DV dialog and its stale detail.
            this.documentDetailTicket=(this.documentDetailTicket||0)+1;
            this.setState({viewType:'',details:null,dvEdit:null,dvEditOriginal:'',dvEditError:'',
                dvCancel:null,dvCancelError:'',dvParticularsEdit:null,dvParticularsError:'',dvPrintPanel:null});
        };
        this.saveDvEdit = async e => {
            e.preventDefault();const entry=this.state.dvEdit;
            if(!entry||this.state.busy)return;
            if(!entry.reason.trim()){this.setState({dvEditError:'Enter the Division accounting instruction or reason for this correction.'});return;}
            const org=String(this.state.organization_id);
            this.setState({busy:true,dvEditError:''});
            try{
                await this.api('dv.update',{organization_id:Number(org),dv_id:Number(entry.id),
                    expected_token:entry.expected_token,bill_reference:entry.bill_reference,
                    uacs_allocations:entry.dv_type==='REGULAR'?entry.uacs_allocations:[],
                    reason:entry.reason.trim()});
                if(String(this.state.organization_id)!==org)return;
                // A successful Save returns to the DV register, not to an unexpected
                // second detail dialog. No voucher is created or finalized here.
                this.documentDetailTicket=(this.documentDetailTicket||0)+1;
                this.setState({viewType:'',details:null,dvEdit:null,dvEditOriginal:'',dvEditError:'',
                    success:(entry.status==='FINALIZED'?'Finalized DV accounting classification corrected; DV number and payment amounts unchanged.':'Draft DV #'+entry.id+' updated. No official DV number assigned.')});
                await this.refresh();
            }catch(error){this.setState({dvEditError:error.message||'Draft update failed.'});}
            finally{this.setState({busy:false});}
        };

        // Tax component bases/rates/amounts remain read-only. This merely requests
        // the server to apply the currently effective Division source policy.
        this.saveDvTaxDisposition = async e => {
            e.preventDefault();const record=this.state.details?.record;
            const edit=this.state.dvTaxDispositionFix;
            if(!record || !edit || Number(record.id)!==edit.dv_id || this.state.busy)return;
            const org=String(this.state.organization_id);
            this.setState({busy:true,dvTaxDispositionError:''});
            try{
                await this.api('dv.tax.disposition.correct',{
                    organization_id:Number(org),dv_id:edit.dv_id,
                    expected_token:edit.expected_token,reason:edit.reason});
                if(org!==String(this.state.organization_id))return;
                const detail=await this.get('detail',{type:'DV',id:String(edit.dv_id),organization_id:org});
                if(org!==String(this.state.organization_id))return;
                this.setState({details:detail,dvTaxDispositionFix:null,dvTaxDispositionError:'',
                    success:'Draft tax disposition updated from Division policy. Withholding amounts and accounting entries remain unchanged.'});
                await this.refresh();
            }catch(error){this.setState({dvTaxDispositionError:error.message||'Could not correct draft tax disposition.'});}
            finally{this.setState({busy:false});}
        };
        this.beginDvParticularsEdit = (detail=this.state.details) => {
            const r=detail?.record;
            if(!r||r.status!=='DRAFT'||!this.canOperateWorkspace())return;
            this.setState({dvParticularsEdit:{dv_id:Number(r.id),expected_particulars:String(r.particulars||''),
                particulars:String(r.particulars||detail.suggested_particulars||'')},dvParticularsError:''});
        };
        this.saveDvParticulars = async e => {
            e.preventDefault();const edit=this.state.dvParticularsEdit;
            if(!edit||this.state.busy)return;
            if(!edit.particulars.trim()){this.setState({dvParticularsError:'Enter what this DV pays for.'});return;}
            const org=String(this.state.organization_id);
            this.setState({busy:true,dvParticularsError:''});
            try{
                await this.api('dv.particulars.update',{organization_id:Number(org),dv_id:edit.dv_id,
                    expected_particulars:edit.expected_particulars,particulars:edit.particulars});
                if(org!==String(this.state.organization_id))return;
                const detail=await this.get('detail',{type:'DV',id:String(edit.dv_id),organization_id:org});
                if(org!==String(this.state.organization_id))return;
                this.setState({details:detail,dvParticularsEdit:null,dvParticularsError:'',
                    success:'Draft DV Particulars saved. The gross, tax, journal and payment details are unchanged.'});
                await this.refresh();
            }catch(error){this.setState({dvParticularsError:error.message||'Particulars could not be saved.'});}
            finally{this.setState({busy:false});}
        };
        // Accountable DV issuance: preview does NOT register a DV or check number.
        this.openDvFinalization = async (row) => {
            if(this.state.busy || !this.canOperateWorkspace() || row?.status!=='DRAFT')return;
            const org=String(this.state.organization_id),id=Number(row.id);
            this.setState({dvActionError:'',dvFinalizePreview:null,dvVoidPreview:null});
            try {
                const detail=await this.get('detail',{type:'DV',id:String(id),organization_id:org});
                if(org!==String(this.state.organization_id))return;
                const rec=detail?.record;
                if(rec?.status!=='DRAFT'||Number(rec.id)!==id||String(rec.organization_id)!==org)
                    throw Error('This DV changed after the register loaded. Refresh the DV register.');
                const latest=detail.dv_last_accountable_date||'';
                const earliest=detail.dv_source_earliest_date||'';
                const minimum=[earliest,latest].filter(Boolean).sort().pop()||'';
                const suggestion=minimum && minimum>localToday()?minimum:localToday();
                this.setState({selectedDv:String(id),dvFinalizePreview:detail,dvFinalizeDate:suggestion,
                    dvCheckBank:'',dvCheckStock:null,dvCheckLoading:false,dvCheckLeafConfirmed:false,
                    dvCheckPayeeBasis:rec.dv_type==='REGULAR'&&Number(rec.supplier_id)>0?'BUSINESS':'DV_PAYEE',
                    dvFundAvailable:null,dvFundLoading:false,dvActionError:''});
            }catch(e){if(org===String(this.state.organization_id))this.setState({error:e.message});}
        };
        this.confirmDvFinalization = async () => {
            const detail=this.state.dvFinalizePreview,rec=detail?.record;
            if(!rec || rec.status!=='DRAFT' || this.state.busy || !this.canOperateWorkspace())return;
            const org=String(this.state.organization_id);
            const row=(this.state.lists.dv||[]).find(x=>Number(x.id)===Number(rec.id));
            if(!row || row.status!=='DRAFT'){
                this.setState({dvActionError:'The DV status has changed. Close and refresh before finalization.'});return;
            }
            if(this.manualTaxPostingBlocked(row)){
                this.setState({dvActionError:'Taxable manual accounting cannot be finalized without the required balanced treatment.'});return;
            }
            const fund=(this.state.lists.funds||[]).find(f=>Number(f.id)===Number(rec.fund_id));
            const school=this.state.orgs.find(o=>String(o.id)===org);
            const auto=school?.kind==='SCHOOL'&&row.dv_type==='REGULAR'&&fund?.source_code==='MOOE'&&
                this.state.fundPolicies?.school_classification?.classification==='NON_IUS';
            const date=String(this.state.dvFinalizeDate||'');
            const earlier=[detail.dv_source_earliest_date,detail.dv_last_accountable_date].filter(Boolean).sort().pop()||'';
            if(!validPrDate(date) || date>localToday() || date<earlier){
                this.setState({dvActionError:'Choose a DV Date on or after the linked source forms and previous numbered DV, and not later than today.'});return;
            }
            const stock=this.state.dvCheckStock;
            if(!this.state.dvCheckBank || this.state.dvCheckLoading || !stock?.leaf_id || !stock?.check_number){
                this.setState({dvActionError:'Choose a registered bank/checkbook with a verified available physical check leaf.'});return;
            }
            if(!this.state.dvCheckLeafConfirmed){
                this.setState({dvActionError:'Personally compare the exact serial with the intact physical check leaf and tick the confirmation.'});return;
            }
            const payload={dv_id:Number(rec.id),document_date:date,
                bank_account_id:Number(this.state.dvCheckBank),expected_leaf_id:Number(stock.leaf_id),
                check_number:String(stock.check_number),confirmed_physical_leaf:true,
                payee_basis:this.state.dvCheckPayeeBasis};
            if(!auto){
                if(!this.state.debitAccount||!this.state.creditAccount||this.state.debitAccount===this.state.creditAccount){
                    this.setState({dvActionError:'Select different valid debit and credit UACS accounts before finalization.'});return;
                }
                payload.accounting=[{uacs_code:this.state.debitAccount,debit:row.gross,credit:'0'},
                    {uacs_code:this.state.creditAccount,debit:'0',credit:row.gross}];
            }
            this.setState({dvActionError:''});
            if(String(rec.organization_id)!==org){this.setState({dvActionError:'The selected workspace changed. Close this review and reopen the DV.'});return;}
            this.setState({busy:true,dvActionError:''});
            try {
                const result=await this.api('dv.finalize',{organization_id:Number(org),...payload});
                if(org!==String(this.state.organization_id))return;
                this.setState({dvFinalizePreview:null,selectedDv:'',success:'DV '+result.number+' finalized with physically verified Check No. '+result.check_number+'. The check is assigned for printing, NOT signed, released or cleared.'});
                await this.refresh();
            }catch(error){if(org===String(this.state.organization_id))this.setState({dvActionError:error.message||'Finalization was not completed.'});}
            finally{this.setState({busy:false});}
        };
        this.openDvVoid = async(row) => {
            if(this.state.busy||!this.canOperateWorkspace()||row?.status!=='FINALIZED')return;
            const org=String(this.state.organization_id),id=Number(row.id);
            try{
                const detail=await this.get('detail',{type:'DV',id:String(id),organization_id:org});
                if(org!==String(this.state.organization_id))return;
                const rec=detail?.record;
                if(rec?.status!=='FINALIZED'||Number(rec.id)!==id||String(rec.organization_id)!==org)
                    throw Error('Finalized DV changed; refresh the register before voiding.');
                const checks=Array.isArray(detail.linked_checks)?detail.linked_checks:(this.state.lists.checks||[]).filter(c=>Number(c.dv_id)===Number(rec.id));
                this.setState({dvVoidPreview:{record:rec,reason:'',confirmed:false,
                    checkNumber:checks[0]?.check_number||'',checkStatus:checks[0]?.status||'',
                    checkCondition:checks.length?'':'NONE',checkConfirmed:false},
                    dvFinalizePreview:null,dvActionError:''});
            }catch(e){if(org===String(this.state.organization_id))this.setState({error:e.message});}
        };
        this.confirmDvVoid = async() => {
            const v=this.state.dvVoidPreview,r=v?.record;
            if(!r||r.status!=='FINALIZED'||this.state.busy||!this.canOperateWorkspace())return;
            if(v.checkNumber && (v.checkCondition!=='UNUSABLE'||!v.checkConfirmed)){
                this.setState({dvActionError:'Confirm the linked physical check is unusable. A still-usable check cannot be recovered by ordinary DV void; use the controlled recovery workflow after finance review.'});return;
            }
            if(!v.confirmed||String(v.reason||'').trim().length<12){
                this.setState({dvActionError:'Provide a documentary reason (at least 12 characters), then confirm the numbered DV being voided.'});return;
            }
            const org=String(this.state.organization_id);
            if(String(r.organization_id)!==org){this.setState({dvActionError:'The selected workspace changed. Close this review and reopen the DV.'});return;}
            this.setState({busy:true,dvActionError:''});
            try {
                await this.api('document.cancel',{organization_id:Number(org),doc_type:'DV',id:Number(r.id),
                    expected_status:'FINALIZED',expected_number:String(r.display_number),
                    expected_gross:String(r.gross),reason:v.reason.trim(),
                    linked_check_condition:v.checkNumber?v.checkCondition:'NONE',
                    confirmed_physical_leaf:v.checkNumber?!!v.checkConfirmed:false});
                if(org!==String(this.state.organization_id))return;
                this.setState({dvVoidPreview:null,dvActionError:'',success:'DV '+r.display_number+' voided and retained in the register. '+(v.checkNumber?'Physical Check No. '+v.checkNumber+' marked unusable and permanently accountable.':'No check was attached.')});
                await this.refresh();
            }catch(error){if(org===String(this.state.organization_id))this.setState({dvActionError:error.message||'Voiding was not completed. Check for linked checks or tax events.'});}
            finally{this.setState({busy:false});}
        };
        // v0.2.77: one purpose-built review flow, never expose JSON actions.
        this.openDraftDvCancel = (data=this.state.details) => {
            const record=data?.record;
            if(this.state.busy||!this.canOperateWorkspace()||record?.status!=='DRAFT'||
               String(record.organization_id)!==String(this.state.organization_id))return;
            const editing=this.state.dvEdit;
            if(editing && JSON.stringify(editing)!==this.state.dvEditOriginal &&
                !window.confirm('Discard unsaved DV edits before reviewing cancellation?'))return;
            const duplicateIds=(data.duplicate_source_dvs||[])
                .filter(v=>v.status==='DRAFT').map(v=>Number(v.id));
            const po=(data.links||[]).find(l=>l.source_type==='PO'&&l.target_type==='DV');
            this.setState({dvEdit:null,dvEditOriginal:'',dvEditError:'',
                dvCancel:{id:Number(record.id),organization_id:String(this.state.organization_id),
                    gross:String(record.gross),payee:String(record.business_name_snapshot||record.payee||''),
                    dv_type:String(record.dv_type),linked_source:po?'PO #'+po.source_id:'Linked source as recorded',
                    duplicate_draft_ids:duplicateIds,
                    reason:duplicateIds.length?
                        'Duplicate draft created for the same source document and accepted items.':
                        '',confirmed:false},
                dvCancelError:'',error:''});
        };
        this.closeDraftDvCancel=()=>{
            if(this.state.busy)return;
            this.setState({dvCancel:null,dvCancelError:''});
        };
        this.confirmDraftDvCancel=async e=>{
            e.preventDefault();
            const c=this.state.dvCancel,d=this.state.details;
            if(this.state.busy||!c||!c.confirmed||c.reason.trim().length<12||
                !this.canOperateWorkspace()||String(c.organization_id)!==String(this.state.organization_id)||
                d?.record?.status!=='DRAFT'||Number(d.record.id)!==c.id){
                this.setState({dvCancelError:'Review the draft number, provide a reason, and confirm before cancellation.'});
                return;
            }
            const org=String(this.state.organization_id);
            this.setState({busy:true,dvCancelError:''});
            try{
                const result=await this.api('dv.draft.cancel',{organization_id:Number(org),
                    id:c.id,expected_gross:c.gross,reason:c.reason.trim()});
                if(String(this.state.organization_id)!==org)return;
                this.setState({dvCancel:null,dvCancelError:'',dvEdit:null,dvEditOriginal:'',
                    viewType:'',details:null,selectedDv:String(this.state.selectedDv)===String(c.id)?'':this.state.selectedDv,
                    success:'Draft DV #'+result.id+' cancelled with audit history preserved. No DV number or payment was issued.'});
                await this.refresh();
            }catch(error){if(String(this.state.organization_id)===org)
                this.setState({dvCancelError:error.message||'Draft cancellation was not completed. Refresh and try again.'});}
            finally{this.setState({busy:false});}
        };
        this.dvCancelModal=()=>{
            const h=React.createElement,c=this.state.dvCancel;
            if(!c)return null;
            const money=n=>Number(n||0).toLocaleString('en-PH',{style:'currency',currency:'PHP'});
            const close=this.closeDraftDvCancel;
            return h('div',{className:'modal-backdrop',onClick:close},
                h('section',{className:'modal pr-detail-modal dv-cancel-dialog',role:'dialog',
                    'aria-modal':'true','aria-label':'Review cancellation of draft DV #'+c.id,
                    onClick:e=>e.stopPropagation()},
                    h('header',{className:'pr-detail-header'},
                        h('div',null,h('span',{className:'pr-detail-eyebrow'},'DISBURSEMENTS · DRAFT ONLY'),
                            h('h2',null,'Cancel Draft DV #'+c.id)),
                        h('button',{type:'button',className:'icon-button pr-detail-close',
                            disabled:this.state.busy,onClick:close,'aria-label':'Close cancellation review'},'×')),
                    h('form',{className:'pr-edit-form',onSubmit:this.confirmDraftDvCancel},
                        h('div',{className:'modal-scroll pr-detail-scroll'},
                            h('p',{className:'notice error',role:'status'},
                                'This cancels only Draft DV #'+c.id+'. It will not delete its history, cancel another DV, or release a check. A finalized DV cannot be cancelled here.'),
                            h('div',{className:'pr-detail-fields'},
                                h('div',{className:'pr-detail-field'},h('span',null,'Draft voucher'),h('strong',null,'DV #'+c.id)),
                                h('div',{className:'pr-detail-field'},h('span',null,'Supplier / payee'),h('strong',null,c.payee)),
                                h('div',{className:'pr-detail-field'},h('span',null,'Source document'),h('strong',null,c.linked_source)),
                                h('div',{className:'pr-detail-field'},h('span',null,'Gross amount'),h('strong',null,money(c.gross)))),
                            c.duplicate_draft_ids.length?h('p',{className:'hint dv-cancel-kept'},
                                'Other draft(s) for this source: '+c.duplicate_draft_ids.map(id=>'DV #'+id).join(', ')+
                                '. These records will remain unchanged.'):null,
                            h('label',{className:'field'},h('span',null,'Reason for cancelling this draft *'),
                                h('textarea',{required:true,minLength:12,maxLength:500,rows:3,value:c.reason,
                                    onChange:e=>this.setState({dvCancel:{...this.state.dvCancel,reason:e.target.value},dvCancelError:''}),
                                    placeholder:'Explain why this draft was prepared in error'})),
                            h('label',{className:'dv-cancel-acknowledge'},
                                h('input',{type:'checkbox',checked:c.confirmed,
                                    onChange:e=>this.setState({dvCancel:{...this.state.dvCancel,confirmed:e.target.checked},dvCancelError:''})}),
                                h('span',null,'I have verified this is DV #'+c.id+' and authorize cancellation of this draft only.')),
                            this.state.dvCancelError?h('p',{className:'notice error',role:'alert'},this.state.dvCancelError):null),
                        h('footer',{className:'pr-detail-actions'},
                            h('button',{type:'button',className:'outline',disabled:this.state.busy,onClick:close},'Keep draft'),
                            h('button',{type:'submit',className:'dv-cancel-submit',
                                disabled:this.state.busy||!c.confirmed||c.reason.trim().length<12},
                                this.state.busy?'Cancelling…':'Confirm cancellation of DV #'+c.id)))));
        };
        this.dvDetailModal = () => {
            const h=React.createElement,d=this.state.details||{},r=d.record||{},e=this.state.dvEdit;
            const linked=d.links||[],lines=d.lines||[],taxes=d.withholding||[],duplicates=d.duplicate_source_dvs||[];
            const duplicateAlert=()=>duplicates.length?h('div',{className:'notice error',role:'alert'},
                'Duplicate source-document vouchers detected: '+duplicates.map(v=>'DV #'+v.id+' ('+v.status+')').join(', ')+
                '. Do not finalize either DV until an authorized finance administrator has reviewed and formally reconciled the duplicate drafts.'):null;
            const accountingReadiness=()=>{
                const a=d.accounting_readiness;
                if(!a)return null;
                const moneyValue=n=>Number(n||0).toLocaleString('en-PH',{style:'currency',currency:'PHP'});
                return h('section',{className:'dv-advance-workpaper','aria-label':'DV accounting readiness'},
                    h('h3',null,'DV accounting readiness'),
                    h('p',{className:'hint'},a.saved_accounting_versions>0
                        ?'Saved accounting workpaper version(s): '+a.saved_accounting_versions+'. This is not a bank debit or evidence of tax remittance.'
                        :'No accounting journal version has been saved. Draft withholding components are separate from accounting entries, which are saved only upon DV finalization.'),
                    h('p',{className:'hint'},'Journal treatment: '+(a.mode==='SCHOOL_NON_IUS_MOOE_ADVANCE'?'Non-IUS school MOOE operating advance':'Reviewed manual/Division accounting')+'. '+(a.explanation||'')),
                    !a.has_authorized_allocation
                        ?h('p',{className:'notice error',role:'alert'},'No authorized allocation is recorded for this DV’s own source of fund. Finalization is blocked; another organization’s fund or bank balance cannot be used.')
                        :!a.sufficient_allocation
                            ?h('p',{className:'notice error',role:'alert'},'Insufficient allocation for this DV’s fund: available '+moneyValue(a.available_allocation)+'; required gross '+moneyValue(a.gross)+'. Finalization is blocked.')
                            :h('p',{className:'hint'},'Recorded source-fund allocation appears sufficient ('+moneyValue(a.available_allocation)+'). The server rechecks this during finalization; bank cash is tracked separately.'),
                    (a.manual_posting_issues||[]).length
                        ?h('div',{className:'notice error',role:'alert'},h('strong',null,'Manual DV finalization blocked pending accounting review'),
                            h('ul',null,...a.manual_posting_issues.map((issue,i)=>h('li',{key:i},issue))))
                        :null);
            };

            // All diagnostics are derived from the already-authorized DV detail
            // response. They do not recalculate posting or alter saved history.
            const accountLine=entry=>h('div',{className:'dv-account-line'},
                h('strong',null,entry.uacs_code+' · '+(entry.account_name||'Account name unavailable')),
                h('small',null,entry.explanation||''));
            const journalTotals=()=>{
                const w=d.advance_liquidation;
                if(!w?.applicable)return null;
                const amount=n=>Number(n||0).toLocaleString('en-PH',{style:'currency',currency:'PHP'});
                return h('div',{className:'dv-journal-summary',role:'status'},
                    h('span',null,'Total debits: ',h('strong',null,amount(w.debit_total))),
                    h('span',null,'Total credits: ',h('strong',null,amount(w.credit_total))),
                    h('span',{className:w.ready?'dv-journal-ok':'dv-journal-blocked'},
                        w.ready?'Workpaper ready for review (not posted)':
                        Number(w.debit_difference)===0
                            ?'Amounts balance; accounting review required — not for posting'
                            :'Incomplete — not for posting · difference '+amount(w.debit_difference)));
            };
            const itemDiagnostics=()=>{
                const w=d.advance_liquidation;
                if(!w?.applicable||!w.item_diagnostics?.length)return null;
                const amount=n=>Number(n||0).toLocaleString('en-PH',{style:'currency',currency:'PHP'});
                const accountDescription=code=>{
                    const account=(this.state.accounts||[]).find(a=>String(a.code)===String(code));
                    return String(account?.account_name||'Description unavailable — check Reference Data');
                };
                const labelWithDescription=code=>code
                    ?String(code)+' — '+accountDescription(code)
                    :'No effective original UACS mapping';
                const mappingText=item=>item.status==='DV_CORRECTION'
                    ?'Current DV debit: '+labelWithDescription(item.uacs_code)+' (source item mapping unchanged)'
                    :item.status==='MAPPED'
                    ?'Original item mapping: '+labelWithDescription(item.uacs_code)+' · effective '+item.effective_from
                    :item.status==='AMBIGUOUS_MAPPING'
                        ?'Competing effective mappings — accountant review required'
                        :'No mapping effective on '+item.iar_accepted_at+
                            (item.next_effective_from?' · next mapping '+item.next_effective_from+' (not retroactive)':'');
                const correctedCode=item=>String((e?.uacs_allocations||[]).find(x=>Number(x.allocation_id)===Number(item.allocation_id))?.uacs_code||'');
                const debitOptions=(this.state.accounts||[]).filter(a=>/^(?:502|104|106|108)[0-9]{7}$/.test(String(a.code)));
                const correctionControl=item=>(e && r.dv_type==='REGULAR')
                    ?h('label',{className:'field'},
                        h('span',null,'Correct DV item UACS'),
                        h('select',{value:correctedCode(item),
                            onChange:event=>update('uacs_allocations',(e.uacs_allocations||[]).map(line=>
                                Number(line.allocation_id)===Number(item.allocation_id)
                                    ?{...line,uacs_code:event.target.value}:line))},
                            h('option',{value:''},'Keep original mapping: '+labelWithDescription(item.status==='MAPPED'?item.uacs_code:'')),
                            ...debitOptions.map(a=>h('option',{key:a.code,value:a.code},
                                labelWithDescription(a.code)))))
                    :null;
                const itemRow=(item,i)=>h('tr',{key:item.allocation_id||item.item_id+'-'+i},
                    h('td',{'data-label':'PO/IAR item'},item.item_name+' · item #'+item.item_id),
                    h('td',{'data-label':'Accepted date'},item.iar_accepted_at),
                    h('td',{'data-label':'DV amount',className:'dv-journal-money'},amount(item.allocated_amount)),
                    h('td',{'data-label':'Applicable UACS',className:['MAPPED','DV_CORRECTION'].includes(item.status)?'':'dv-diagnostic-warning'},
                        mappingText(item),correctionControl(item)));
                return h('section',{className:'dv-item-diagnostics','aria-label':'DV item accounting review'},
                    h('h4',null,'Item-level accounting review'),
                    h('p',{className:'hint'},e && r.dv_type==='REGULAR'
                        ?'Correct debit UACS per accepted item. Amounts and original Division item classification stay locked; saved accounting versions are retained.'
                        :'Source: saved DV allocations and finalized IAR acceptance dates. Corrections are specific to their DV.'),
                    h('div',{className:'table-scroll dv-mobile-table-wrap'},
                        h('table',{className:'data-table dv-item-table'},
                            h('thead',null,h('tr',null,...['PO/IAR item','Accepted date','DV amount','Applicable UACS mapping'].map(label=>h('th',{key:label},label)))),
                            h('tbody',null,...w.item_diagnostics.map(itemRow)))),
                    Number(w.unmapped_amount)>0?h('p',{className:'notice error',role:'alert'},
                        amount(w.unmapped_amount)+' of saved DV allocations lacks an approved effective UACS mapping. Correct the draft classification before finalization.'):null);
            };
            const fund=(this.state.lists.funds||[]).find(x=>String(x.id)===String(r.fund_id));
            const po=linked.find(x=>x.source_type==='PO'&&x.target_type==='DV');
            const dvChecks=(Array.isArray(d.linked_checks)?d.linked_checks:
                (this.state.lists.checks||[]).filter(c=>String(c.dv_id)===String(r.id)));
            const activeDvCheck=dvChecks.filter(c=>['ISSUED','SIGNED','RELEASED','CLEARED'].includes(c.status))
                .sort((a,b)=>Number(b.id)-Number(a.id))[0]||null;
            const tax2307Components=taxes.filter(t=>String(t.tax_kind||'').toUpperCase()==='EWT');
            const dvPrintPanel=this.state.dvPrintPanel;
            const checkProofPanel=()=>h('section',{className:'surface dv-shortcut-panel','aria-label':'Check alignment proof'},
                h('div',{className:'section-head'},h('h3',null,'Check print alignment · TEST only'),
                    h('button',{type:'button',className:'outline',onClick:()=>this.setState({dvPrintPanel:null})},'Close proof')),
                activeDvCheck&&window.IpdamsCheckProof?
                    h(window.IpdamsCheckProof,{organizationId:Number(this.state.organization_id),
                        dv:r,checkNo:activeDvCheck.check_number,amount:activeDvCheck.amount,
                        payee:activeDvCheck.payee_name||r.business_name_snapshot||r.payee||'',
                        mode:'ISSUED',startEditor:true,
                        get:(route,body)=>this.get(route,body),
                        task:(route,body)=>this.task(route,body,{refresh:false})}):
                    h('p',{className:'notice error',role:'status'},'No active saved check is available for this finalized DV. Check register or printing module must be reviewed; no serial is assigned by opening this proof.'),
                h('p',{className:'hint'},'The alignment sheet is non-negotiable. Official check printing remains unavailable until bank-cash controls, paper and signatory UAT are accepted.'));
            const bir2307Panel=()=>h('section',{className:'surface dv-shortcut-panel','aria-label':'BIR 2307 preparation status'},
                h('div',{className:'section-head'},h('h3',null,'BIR Form 2307 · source review'),
                    h('button',{type:'button',className:'outline',onClick:()=>this.setState({dvPrintPanel:null})},'Close')),
                h('p',{className:'notice',role:'status'},'An official BIR Form 2307 generator is not available in IPDAMS yet. These saved tax details are provided for review only, not as a certificate or proof of tax remittance.'),
                h('div',{className:'pr-detail-fields'},
                    h('div',{className:'pr-detail-field'},h('span',null,'DV / document date'),h('strong',null,(r.display_number||'#'+r.id)+' · '+(r.document_date||'Unrecorded'))),
                    h('div',{className:'pr-detail-field'},h('span',null,'Supplier / payee'),h('strong',null,r.business_name_snapshot||r.payee||'—')),
                    h('div',{className:'pr-detail-field'},h('span',null,'Total recorded deductions'),h('strong',null,money(r.deductions)))),
                tax2307Components.length?
                    h('div',{className:'table-scroll'},h('table',{className:'data-table'},
                        h('thead',null,h('tr',null,...['ATC','Tax base','Withheld tax'].map(x=>h('th',{key:x},x)))),
                        h('tbody',null,...tax2307Components.map((t,i)=>h('tr',{key:t.id||i},
                            h('td',null,t.atc_code||'—'),h('td',null,money(t.tax_base)),h('td',null,money(t.amount))))))):
                    h('p',{className:'hint'},'No separately classified EWT amounts are recorded for this voucher; do not create Form 2307 from other tax components.'),
                h('p',{className:'hint'},'The future official form workflow must verify payor/payee TIN and address, quarter and income period, ATC, certifying signatory, template and withholding evidence before enabling Print BIR 2307.'));
            const money=x=>Number(x||0).toLocaleString('en-PH',{style:'currency',currency:'PHP'});
            const close=()=>this.closeDvDetail();
            const field=(name,value)=>h('div',{className:'pr-detail-field',key:name},h('span',null,name),h('strong',null,value||'—'));
            const update=(key,value)=>this.setState({dvEdit:{...this.state.dvEdit,[key]:value},dvEditError:''});
            return h('div',{className:'modal-backdrop',onClick:close},
                h('section',{key:e?'dv-editor':'dv-detail',className:'modal pr-detail-modal pr-edit-modal',role:'dialog','aria-modal':'true','aria-label':e?'Correct DV accounting classification':'Disbursement voucher',style:{height:'min(900px, calc(100dvh - 28px))'},onClick:x=>x.stopPropagation()},
                    h('header',{className:'pr-detail-header'},
                        h('div',null,h('span',{className:'pr-detail-eyebrow'},'DISBURSEMENTS · '+r.dv_type),
                            h('h2',null,e?'Correct Accounting UACS · '+(r.display_number||'Draft DV #'+r.id):(r.display_number||'Draft DV #'+r.id))),
                        h('button',{type:'button',className:'icon-button pr-detail-close',disabled:this.state.busy,onClick:close,'aria-label':'Close DV'},'×')),
                    e?h('form',{className:'pr-edit-form',onSubmit:this.saveDvEdit},
                        h('div',{className:'modal-scroll pr-detail-scroll'},
                            duplicateAlert(),
                            accountingReadiness(),
                            h('p',{className:'hint'},'The '+r.dv_type+' source, PO/PR, supplier, accepted allocations, funding source and gross amount are locked to protect the linked procurement records. Only DV-specific debit UACS codes can be changed. There are no digital approval steps. Supplier, credit entries, withholding, net payment and source classification remain fixed.'),
                            h('div',{className:'pr-detail-fields'},
                                field('Linked PO',po?'PO #'+po.source_id:'See linked source below'),
                                field('Source of fund',fund?.name||'Fund #'+r.fund_id),
                                field('Payee / business',r.business_name_snapshot||r.payee),
                                field('Proprietor',r.proprietor_name_snapshot||'Not applicable'),
                                field('Gross amount (unchanged)',money(r.gross))),
                            h('label',{className:'field'},h('span',null,'Bill / invoice reference'+(e.status==='FINALIZED'?' (locked)':'')),
                                h('input',{maxLength:255,value:e.bill_reference,readOnly:e.status==='FINALIZED',onChange:x=>update('bill_reference',x.target.value)})),
                            h('section',{className:'dv-withholding-locked','aria-label':'Withholding — read-only'},
                                h('h3',null,'Withholding taxes · read-only'),
                                h('p',{className:'hint'},'Tax type, ATC, rate, tax base and treatment are read-only. Correcting debit UACS never recalculates tax, gross/net or any payment amount.'),
                                taxes.length?h('div',{className:'table-scroll'},h('table',{className:'data-table'},
                                    h('thead',null,h('tr',null,...['ATC','Tax base','Withheld','Treatment'].map(x=>h('th',{key:x},x)))),
                                    h('tbody',null,...taxes.map(t=>h('tr',{key:t.id},
                                        h('td',null,t.atc_code),h('td',null,money(t.tax_base)),
                                        h('td',null,money(t.amount)),
                                        h('td',null,t.disposition==='SCHOOL_UTILIZATION'?'School utilization':'For remittance'))))))
                                    :h('p',{className:'hint'},'No withholding recorded on this draft.')),
                            h('section',{className:'dv-advance-workpaper','aria-label':'Automatic journal preview for saved voucher'},
                                h('div',{className:'section-head'},h('h3',null,'Journal entries · saved DV preview')),
                                h('p',{className:'hint'},'Journal preview of the LAST SAVED classification. After saving a correction, reopen this DV to see revised debits. Its original credit and payment amounts do not change.'),
                                d.advance_liquidation?.applicable
                                    ?h('div',null,
                                        d.advance_liquidation.issues?.length
                                            ?h('div',{className:'notice error',role:'alert'},
                                                h('strong',null,'Accounting review required before finalization:'),
                                                h('ul',null,...d.advance_liquidation.issues.map((issue,i)=>h('li',{key:i},issue))))
                                            :h('p',{className:'hint'},'This saved voucher has a balanced item-based accounting workpaper. No bank movement is created by this preview.'),
                                        h('div',{className:'table-scroll dv-mobile-table-wrap'},h('table',{className:'data-table dv-journal-table'},
                                            h('thead',null,h('tr',null,h('th',null,'UACS / Description'),h('th',null,'Debit'),h('th',null,'Credit'))),
                                            h('tbody',null,...(d.advance_liquidation.entries||[]).map((entry,i)=>h('tr',{key:i},
                                                h('td',{'data-label':'Account'},accountLine(entry)),
                                                h('td',{'data-label':'Debit',className:'dv-journal-money'},Number(entry.debit)>0?money(entry.debit):'—'),
                                                h('td',{'data-label':'Credit',className:'dv-journal-money'},Number(entry.credit)>0?money(entry.credit):'—')))))),
                                        journalTotals(),
                                        itemDiagnostics(),
                                        h('p',{className:'hint'},'Choose the debit UACS stated by the Division’s accounting instruction. The source item mapping and credit entries remain unchanged; past accounting versions remain in the audit record.'))
                                    :h('p',{className:'hint'},d.advance_liquidation?.message||'No automatic operating-advance journal is configured for this voucher type or funding arrangement; use the applicable reviewed accounting workflow.')),
                            h('label',{className:'field'},h('span',null,'Division accounting instruction / correction reason *'),
                                h('textarea',{required:true,maxLength:500,rows:2,value:e.reason,onChange:x=>update('reason',x.target.value),placeholder:'e.g. Per Division Accountant instruction dated YYYY-MM-DD: use Traveling Expenses – Local for transport and per diem'})),
                            e.dv_type==='REGULAR'?h('p',{className:'hint'},'Need a different PO? This correction form does not reassign the PO or alter the gross amount. Contact the accounting administrator to follow the controlled draft replacement procedure.'):null,
                            this.state.dvEditError?h('p',{className:'notice error',role:'alert'},this.state.dvEditError):null),
                        h('footer',{className:'pr-detail-actions'},
                            e.status==='DRAFT'?h('button',{type:'button',className:'dv-cancel-link',disabled:this.state.busy,
                                onClick:()=>this.openDraftDvCancel(d)},'Cancel this draft DV'):null,
                            h('button',{type:'button',className:'outline',onClick:this.dismissDvEdit,disabled:this.state.busy},'Back to DV details'),
                            h('button',{className:'primary',type:'submit',disabled:this.state.busy},this.state.busy?'Saving…':'Save Accounting Correction')))
                    :h('div',{className:'dv-detail-readonly',style:{display:'flex',flexDirection:'column',flex:'1 1 auto',minHeight:0,overflow:'hidden'}},
                        h('div',{className:'modal-scroll pr-detail-scroll',style:{flex:'1 1 auto',minHeight:0,overflowY:'auto',overscrollBehavior:'contain'}},
                            r.status==='FINALIZED'&&dvPrintPanel==='check'?checkProofPanel():null,
                            r.status==='FINALIZED'&&dvPrintPanel==='2307'?bir2307Panel():null,
                            duplicateAlert(),
                            accountingReadiness(),
                            h('section',{className:'dv-particulars','aria-label':'DV Particulars'},
                                h('h3',null,'Particulars'),
                                this.state.dvParticularsEdit?.dv_id===Number(r.id)?
                                    h('form',{onSubmit:this.saveDvParticulars},
                                        h('label',{className:'field'},h('span',null,'Payment or reimbursement description'),
                                            h('textarea',{required:true,maxLength:1200,rows:5,value:this.state.dvParticularsEdit.particulars,
                                                onChange:e=>this.setState({dvParticularsEdit:{...this.state.dvParticularsEdit,particulars:e.target.value},dvParticularsError:''})})),
                                        h('p',{className:'hint'},'The PR purpose and accepted item count were suggested automatically. Edit wording only; amounts and account classifications stay locked.'),
                                        this.state.dvParticularsError?h('p',{className:'notice error',role:'alert'},this.state.dvParticularsError):null,
                                        h('div',{className:'inline-actions'},
                                            h('button',{type:'button',className:'outline',disabled:this.state.busy,onClick:()=>this.setState({dvParticularsEdit:null,dvParticularsError:''})},'Cancel editing'),
                                            h('button',{type:'submit',className:'primary',disabled:this.state.busy},this.state.busy?'Saving…':'Save Particulars'))):
                                    h('div',null,
                                        h('p',{style:{whiteSpace:'pre-wrap',lineHeight:'1.65'}},r.particulars||d.suggested_particulars||'Enter particulars before finalization.'),
                                        r.status==='DRAFT'&&this.canOperateWorkspace()?h('button',{type:'button',className:'outline',onClick:()=>this.beginDvParticularsEdit(d)},'Edit Particulars'):null),
                                dvCompactSavedWithholding(h,r,taxes),
                                d.tax_policy && taxes.length?h('div',{className:'dv-fund-tax-policy',style:{marginTop:'10px'}},
                                    h('p',{className:d.tax_disposition_mismatch?'notice error':'hint',role:d.tax_disposition_mismatch?'alert':'status'},
                                        'Division fund policy: '+(d.tax_policy.mode==='DIVISION_SETTLES_UTILIZABLE'?'Division settles BIR; school cash may be utilized under recorded MOOE authority':
                                        d.tax_policy.mode==='REMIT_TO_DIVISION'?'For remittance to Division':
                                        'Not configured / school class unverified')+'. ',
                                        d.tax_disposition_mismatch?'Saved draft tax disposition does not match; do not finalize until corrected.':
                                        d.tax_policy_is_current_not_original?'Current Division setting shown for reference only; the issued DV keeps its original recorded tax disposition.':
                                        'The Due to BIR liability remains; this is not evidence of settlement or a new fund allocation.'),
                                    d.tax_disposition_edit_allowed&&this.canOperateWorkspace()?
                                        (this.state.dvTaxDispositionFix?.dv_id===Number(r.id)?
                                            h('form',{onSubmit:this.saveDvTaxDisposition},
                                                h('label',{className:'field'},h('span',null,'Reason for applying Division tax policy'),
                                                    h('textarea',{required:true,minLength:12,maxLength:500,rows:2,
                                                        value:this.state.dvTaxDispositionFix.reason,
                                                        onChange:e=>this.setState({dvTaxDispositionFix:{...this.state.dvTaxDispositionFix,reason:e.target.value},dvTaxDispositionError:''}),
                                                        placeholder:'e.g. Align draft WI640/WV010 with current Division Non-IUS MOOE utilization policy.'})),
                                                this.state.dvTaxDispositionError?h('p',{className:'notice error',role:'alert'},this.state.dvTaxDispositionError):null,
                                                h('div',{className:'inline-actions'},
                                                    h('button',{type:'button',className:'outline',onClick:()=>this.setState({dvTaxDispositionFix:null,dvTaxDispositionError:''})},'Keep draft unchanged'),
                                                    h('button',{type:'submit',className:'primary',disabled:this.state.busy||this.state.dvTaxDispositionFix.reason.trim().length<12},'Apply configured tax treatment'))):
                                            h('button',{type:'button',className:'outline',
                                                onClick:()=>this.setState({dvTaxDispositionFix:{dv_id:Number(r.id),expected_token:d.tax_disposition_token,reason:''},dvTaxDispositionError:''})},
                                                'Correct Draft Tax Disposition')):
                                        null):null),
                            h('div',{className:'pr-detail-fields'},
                                field('DV type',r.dv_type),field('Status',r.status),
                                field('Source of fund',fund?.name||'Fund #'+r.fund_id),
                                field('Purchase Order',po?'PO #'+po.source_id:'Not applicable'),
                                field('Registered business / payee',r.business_name_snapshot||r.payee),
                                field('Proprietor',r.proprietor_name_snapshot||'Not applicable'),
                                field('Bill reference',r.bill_reference||'Not recorded'),
                                field('Gross',money(r.gross)),field('Withholding',money(r.deductions)),field('Net payment',money(r.net))),
                            d.advance_liquidation?.applicable?h('section',{className:'dv-advance-workpaper'},
                                h('h3',null,'Automatic advance-liquidation journal preview'),
                                h('p',{className:'hint'},'Non-IUS regular MOOE · document-level workpaper only. This is NOT a bank debit. Actual checks are tracked separately.'),
                                d.advance_liquidation.issues?.length?h('div',{className:'notice error',role:'alert'},
                                    h('strong',null,'Before finalization, resolve:'),
                                    h('ul',null,...d.advance_liquidation.issues.map((issue,i)=>h('li',{key:i},issue)))):
                                    h('p',{className:'hint'},'Balanced from recorded PO/IAR UACS mappings, withholding and net payable.'),
                                h('table',{className:'data-table dv-journal-table'},h('thead',null,h('tr',null,
                                    h('th',null,'UACS account'),h('th',null,'Debit'),h('th',null,'Credit'))),
                                    h('tbody',null,...(d.advance_liquidation.entries||[]).map((entry,i)=>h('tr',{key:i},
                                        h('td',{'data-label':'Account'},accountLine(entry)),
                                        h('td',{'data-label':'Debit',className:'dv-journal-money'},Number(entry.debit)>0?money(entry.debit):'—'),
                                        h('td',{'data-label':'Credit',className:'dv-journal-money'},Number(entry.credit)>0?money(entry.credit):'—'))))),
                                journalTotals(),
                                itemDiagnostics(),
                                h('p',{className:'hint'},'The due-to-BIR component is not proof of Division/BIR settlement. Any UACS correction only updates item debit distribution; no bank receipt or tax movement is generated.')):
                                h('p',{className:'hint'},d.advance_liquidation?.message||'This DV uses a different accounting treatment; Division accounting configuration is required before automation.'),
                            h('details',{className:'pr-detail-history'},h('summary',null,'Linked documents and internal allocation history'),
                                h('p',null,'These allocation lines were derived from accepted source records; they are not manually edited.'),
                                h('ul',null,...linked.map((x,i)=>h('li',{key:i},x.source_type+' #'+x.source_id+' → '+x.target_type+' #'+x.target_id))),
                                h('p',null,lines.length+' internal allocation lines · '+(d.versions||[]).length+' finalized versions'))),
                        h('footer',{className:'pr-detail-actions'},
                            h('button',{type:'button',className:'outline',onClick:close},'Close'),
                            d.edit_allowed&&this.canOperateWorkspace()?h('button',{type:'button',className:'primary',onClick:()=>this.beginDvEdit(d)},'Correct Accounting UACS'):null,
                            d.correction_readonly_reason?h('p',{className:'hint'},d.correction_readonly_reason):null,
                            r.status==='DRAFT'&&this.canOperateWorkspace()?h('button',{type:'button',
                                className:'dv-cancel-link',disabled:this.state.busy,
                                onClick:()=>this.openDraftDvCancel(d)},'Cancel Draft DV'):null,
                            r.status==='FINALIZED'&&r.display_number?h('a',{className:'pr-detail-print',target:'_blank',rel:'noopener',href:'api/print.php?organization_id='+encodeURIComponent(this.state.organization_id)+'&type=DV&id='+r.id},'Print DV ↗'):null,
                            r.status==='FINALIZED'?h('button',{type:'button',className:'outline',
                                onClick:()=>this.setState({dvPrintPanel:'check'})},'Check alignment proof'):null,
                            r.status==='FINALIZED'&&Number(r.deductions)>0?h('button',{type:'button',className:'outline',
                                onClick:()=>this.setState({dvPrintPanel:'2307'})},'BIR 2307 readiness'):null))));
        };
        this.openDetail = async (type, id, edit=false, shortcut=null) => {
            const ticket=this.documentDetailTicket=(this.documentDetailTicket||0)+1;
            const orgId=String(this.state.organization_id);
            try {
                const data=await this.get('detail',{type,id:String(id),organization_id:orgId});
                if(ticket!==this.documentDetailTicket||String(this.state.organization_id)!==orgId)return;
                // An IAR's saved lines carry PO-line IDs, not item names. Fetch the
                // same-workspace saved PO read-only for label resolution. Failure to
                // load it must never conceal or change the saved IAR itself.
                if(type==='IAR'&&data?.record?.po_id){
                    try {
                        const po=await this.get('detail',{type:'PO',id:String(data.record.po_id),organization_id:orgId});
                        if(po?.record&&String(po.record.id)===String(data.record.po_id)&&
                           String(po.record.organization_id)===String(data.record.organization_id)){
                            data.related_po=po;
                        }else data.related_po_error='The linked PO could not be verified in this workspace.';
                    }catch(_){data.related_po_error='Linked PO item descriptions are temporarily unavailable.';}
                }
                if(ticket!==this.documentDetailTicket||String(this.state.organization_id)!==orgId)return;
                this.setState({details:data,viewType:type,dvEdit:null,dvEditOriginal:'',dvEditError:'',
                    dvPrintPanel:type==='DV'?shortcut:null,
                    dvCancel:null,dvCancelError:'',prEditOpen:false,prEdit:null,prPickerOpen:''},()=>{
                    if(type==='PR'&&edit)this.startEditPR(data);
                    if(type==='DV'&&edit)this.beginDvEdit(data);
                });
            }catch(e){
                if(ticket===this.documentDetailTicket&&String(this.state.organization_id)===orgId)
                    this.setState({error:e.message});
            }
        };
        this.field = (key, label, placeholder = '', type = 'text') => React.createElement("label", { className: "field" },
            React.createElement("span", null, label),
            React.createElement("input", { type: type, name:key,
                autoComplete:key.startsWith('new_division_user_')||key.startsWith('new_school_user_')
                    ?(type==='password'?'new-password':'off'):undefined,
                value: this.state[key], placeholder: placeholder, onChange: this.set(key) }));
        this.select = (key, label, choices, prompt = 'Select...') => React.createElement("label", { className: "field" },
            React.createElement("span", null, label),
            React.createElement("select", { value: this.state[key], onChange: this.set(key) },
                React.createElement("option", { value: "" }, prompt),
                choices.map((c) => React.createElement("option", { key: c.id ?? c.value, value: c.id ?? c.value }, c.name ?? c.label))));
        this.act = (name, handle) => React.createElement("button", { disabled: this.state.busy || !this.state.organization_id, className: "primary", type: "submit" },
            this.state.busy ? 'Processing…' : name,
            React.createElement("span", null, "\u2192"));
        this.header = (title, sub) => React.createElement("div", { className: "content-header" },
            React.createElement("div", null,
                React.createElement("div", { className: "eyebrow" },
                    "IPDAMS / ",
                    this.state.tab.toUpperCase()),
                React.createElement("h1", null, title),
                React.createElement("p", null, sub)),
            React.createElement("div", { className: "header-actions" },
                React.createElement("button", { className: "outline", onClick: () => this.refresh() }, "\u21BB Refresh data")));
        this.table = (name, columns, data, type) => React.createElement("div", { className: "surface table-box" },
            React.createElement("div", { className: "section-head" },
                React.createElement("h3", null, name),
                React.createElement("span", { className: "count" },
                    data.length,
                    " records")),
            React.createElement("div", { className: "table-scroll" },
                React.createElement("table", null,
                    React.createElement("thead", null,
                        React.createElement("tr", null,
                            columns.map(c => React.createElement("th", { key: c }, c.replace(/_/g, ' '))),
                            type ? React.createElement("th", null, "View") : null)),
                    React.createElement("tbody", null, data.length ? data.slice(0, 50).map((r, i) => React.createElement("tr", { key: r.id ?? i },
                        columns.map(c => React.createElement("td", { key: c }, c === 'status' ? React.createElement("span", { className: 'status ' + r[c] }, r[c]) : (r[c] ?? '—'))),
                        type ? React.createElement("td", null,
                            React.createElement("button", { className: "text-btn", onClick: () => this.openDetail(type, Number(r.id)) }, "Details \u2197"),
                            type==='DV'&&r.status==='DRAFT'&&this.canOperateWorkspace()?
                                React.createElement('button',{type:'button',className:'text-btn',onClick:()=>this.openDetail('DV',Number(r.id),true)},'Edit Draft'):null,
                            type==='DV'&&r.status==='DRAFT'&&this.canOperateWorkspace()?
                                React.createElement('button',{type:'button',className:'text-btn',disabled:this.state.busy,onClick:()=>this.openDvFinalization(r)},'Finalize'):null,
                            type==='DV'&&r.status==='FINALIZED'&&this.canOperateWorkspace()?
                                React.createElement('button',{type:'button',className:'text-btn',disabled:this.state.busy,onClick:()=>this.openDvVoid(r)},'Void issued DV'):null) : null)) : React.createElement("tr", null,
                        React.createElement("td", { className: "empty", colSpan: columns.length + (type ? 1 : 0) }, "No records yet. Create your first document using the form above."))))));
        // Security-review-only: configuring a grant records a policy snapshot; it
        // NEVER bypasses existing Domain, print, scope or MOOE viewer checks.
        this.loadRoleCatalog = async () => {
            if(this.state.user?.role!=='SUPER_ADMIN')return;
            this.setState({roleBusy:true,roleError:''});
            try { const data=await this.get('role.functions.catalog'); this.setState({roleCatalog:data}); }
            catch(e){this.setState({roleError:e.message});}
            finally{this.setState({roleBusy:false});}
        };
        this.loadRoleUser = async () => {
            const {roleUserId,roleSchoolId}=this.state;
            if(!roleUserId||!roleSchoolId){this.setState({roleDetail:null});return;}
            this.setState({roleBusy:true,roleError:''});
            try{const data=await this.get('role.functions.user',{user_id:roleUserId,school_id:roleSchoolId});
                if(String(this.state.roleUserId)===String(roleUserId)&&String(this.state.roleSchoolId)===String(roleSchoolId))this.setState({roleDetail:data,rolePermission:''});
            }catch(e){this.setState({roleDetail:null,roleError:e.message});}
            finally{this.setState({roleBusy:false});}
        };
        this.addRoleFunction = async event => {
            event.preventDefault();
            const s=this.state;
            if(s.roleBusy||!s.roleDetail||!s.rolePermission)return;
            if(!(s.roleDetail.available_functions||[]).includes(s.rolePermission)){
                this.setState({rolePermission:'',roleError:'This function has already been assigned. Refresh and review current access.'});
                return;
            }
            this.setState({roleBusy:true,roleError:'',roleNotice:''});
            try{await this.api('role.functions.grant',{user_id:Number(s.roleUserId),school_id:Number(s.roleSchoolId),
                permission:s.rolePermission,starts_on:s.roleStartsOn,ends_on:s.roleEndsOn,reason:s.roleReason,expected_version:Number(s.roleDetail.version_id)});
                this.setState({rolePermission:'',roleReason:'',roleEndsOn:'',roleNotice:'Special Function saved. The school-scoped read-only registers and finalized PR reprint take effect immediately; manage functions remain configured-only.'});
                await this.loadRoleUser();
            }catch(e){this.setState({roleError:e.message});}
            finally{this.setState({roleBusy:false});}
        };
        this.removeRoleFunction = async grant => {
            const s=this.state;
            const reason=window.prompt('Enter a reason for revoking this configured Special Function (at least 10 characters):');
            if(reason===null)return;
            if(reason.trim().length<10){this.setState({roleError:'Enter a clear revocation reason of at least 10 characters.'});return;}
            this.setState({roleBusy:true,roleError:'',roleNotice:''});
            try{await this.api('role.functions.revoke',{user_id:Number(s.roleUserId),school_id:Number(s.roleSchoolId),
                grant_id:grant.grant_id,reason:reason.trim(),expected_version:Number(s.roleDetail.version_id)});
                this.setState({roleNotice:'Special Function revoked immediately. Audit history remains available.'});
                await this.loadRoleUser();
            }catch(e){this.setState({roleError:e.message});}
            finally{this.setState({roleBusy:false});}
        };
        // v0.2.210: surface LIVE school-scoped read-only grants in their natural
        // modules. A new login/workspace/module entry fetches active grants again.
        this.loadDivisionModuleGrants = async () => {
            if(!schoolScopedDivision(this.state))return;
            const actor=String(this.state.user?.id||''), school=String(this.state.organization_id||'');
            const token=this.divisionGrantToken=(this.divisionGrantToken||0)+1;
            this.setState({divisionModuleGrants:null,divisionModuleGrantsBusy:true,
                divisionModuleGrantsError:'',divisionContextRegister:null,divisionContextError:''});
            try{
                const result=await this.get('role.functions.mine');
                if(token!==this.divisionGrantToken || String(this.state.user?.id||'')!==actor ||
                    String(this.state.organization_id||'')!==school)return;
                const row=(result.schools||[]).find(r=>String(r.school_id)===school);
                this.setState({divisionModuleGrants:row||{school_id:school,functions:[]},divisionModuleGrantsBusy:false});
            }catch(error){
                if(token===this.divisionGrantToken && String(this.state.organization_id||'')===school)
                    this.setState({divisionModuleGrants:{school_id:school,functions:[]},divisionModuleGrantsBusy:false,
                        divisionModuleGrantsError:'Could not verify Special Functions. Try Refresh Permissions.'});
            }
        };
        this.loadDivisionContextRegister = async (type) => {
            if(!divisionLiveSpecial(this.state,type)){
                this.setState({divisionContextRegister:null,divisionContextError:'This school has no current permission for the requested register.'});return;
            }
            const org=String(this.state.organization_id),actor=String(this.state.user?.id||'');
            const token=this.divisionContextToken=(this.divisionContextToken||0)+1;
            this.setState({divisionContextRegister:null,divisionContextBusy:true,divisionContextError:''});
            try{
                // The immutable named-register endpoint rechecks exact account,
                // school membership, live grant dates and revocation on EVERY GET.
                const data=await this.get('role.functions.school_register',{organization_id:org,type});
                if(token===this.divisionContextToken && org===String(this.state.organization_id) &&
                   actor===String(this.state.user?.id) && divisionLiveSpecial(this.state,type))
                    this.setState({divisionContextRegister:{...data,type,organization_id:org},divisionContextBusy:false});
            }catch(error){
                if(token===this.divisionContextToken && org===String(this.state.organization_id)){
                    this.setState({divisionContextRegister:null,divisionContextBusy:false,
                        divisionContextError:error.status===403?'Permission no longer active. Refresh Permissions to update your tabs.':error.message});
                    if(error.status===403)this.loadDivisionModuleGrants();
                }
            }
        };
        this.divisionContextPanel = (type) => {
            const h=React.createElement;
            const title={STOCK:'Stock on Hand',ASSET:'Asset Register',TEXTBOOK:'Textbook Records'}[type]||'Register';
            if(!divisionLiveSpecial(this.state,type))return h('section',{className:'surface',role:'status'},
                'This register is not included in your current school assignment.');
            const record=this.state.divisionContextRegister;
            const current=record && record.type===type && String(record.organization_id)===String(this.state.organization_id);
            return h('section',{className:'surface',role:'region','aria-label':title+' read-only Special Function'},
                h('div',{className:'section-head'},h('h3',null,title+' · read-only Special Function'),
                    h('button',{type:'button',className:'outline',disabled:this.state.divisionContextBusy,
                        onClick:()=>this.loadDivisionContextRegister(type)},this.state.divisionContextBusy?'Checking…':'Refresh Register')),
                h('p',{className:'hint'},'Additional permission for this assigned school only. No create, edit, issue, post, adjustment, print or full register access is granted.'),
                this.state.divisionContextError?h('p',{role:'alert',className:'error'},this.state.divisionContextError):null,
                this.state.divisionContextBusy?h('p',{role:'status'},'Checking authorization and loading records…'):null,
                current?this.table(title,record.columns||[],record.rows||[]):
                    h('p',{className:'hint'},'No verified records loaded. Choose Refresh Register.'));
        };
        this.loadMyFunctions = async () => {
            if(!['SCHOOL','DIVISION'].includes(this.state.user?.role))return;
            this.setState({myFuncBusy:true,myFuncError:''});
            try {
                const data=await this.get('role.functions.mine');
                const schools=data.schools||[];
                const match=schools.find(o=>String(o.school_id)===String(this.state.myFuncOrg));
                const chosen=match||schools[0];
                this.setState({myFunctions:data,myFuncOrg:chosen?String(chosen.school_id):'',myFuncRecords:null});
            }catch(e){this.setState({myFuncError:e.message,myFunctions:null,myFuncRecords:null});}
            finally{this.setState({myFuncBusy:false});}
        };
        this.loadMyFunctionDocuments = async () => {
            const {myFuncOrg,myFuncType,myFuncMonth}=this.state;
            if(!myFuncOrg||!myFuncType)return;
            this.setState({myFuncBusy:true,myFuncError:'',myFuncRecords:null});
            try {
                const schoolRegister=['STOCK','ASSET','TEXTBOOK'].includes(myFuncType);
                const data=await this.get(schoolRegister?'role.functions.school_register':'role.functions.register',
                    schoolRegister?{organization_id:myFuncOrg,type:myFuncType}:{organization_id:myFuncOrg,type:myFuncType,month:myFuncMonth});
                if(String(this.state.myFuncOrg)===String(myFuncOrg) && this.state.myFuncType===myFuncType && this.state.myFuncMonth===myFuncMonth)
                    this.setState({myFuncRecords:data});
            }catch(e){this.setState({myFuncError:e.message});}
            finally{this.setState({myFuncBusy:false});}
        };
        this.organizationView = () => {
            const h = React.createElement;
            const role = this.state.user?.role;
            const rows = this.state.orgHierarchy || [];
            const divisions = rows.filter(o => o.kind === 'DIVISION' && Number(o.active) === 1);
            const schools = rows.filter(o => o.kind === 'SCHOOL');
            const unassigned = schools.filter(o => !o.parent_division_id);
            const divisionOptions = divisions.map(o => ({id:o.id,name:o.name+' · '+o.code}));
            const chosenDivision = divisions.find(d => String(d.id) === this.state.selected_division_id) || divisions[0];
            const chosenDivisionId = chosenDivision?.id || '';
            const schoolOptions = schools.filter(o => Number(o.parent_division_id) === Number(chosenDivisionId) && Number(o.active) === 1)
                .map(o => ({id:o.id,name:o.name+' · '+o.code}));
            const formButton = (label, action = '') => {
                const processing=this.state.busy && this.state.organizationPendingAction===action;
                return h('button',{type:'submit',className:'primary',disabled:this.state.busy,'aria-busy':processing},
                    processing?'Processing…':label);
            };
            const surface = (...children) => h('section',{className:'surface'},...children);
            const tabs = (role === 'SCHOOL' || role === 'VIEWER')
                ? [['school','School Profile'],...(role==='SCHOOL'?[['myfunctions','My Special Functions']]:[]),['account','My Account']]
                : [['division','Division Profile'],['schools','Schools'],['users','User Accounts'],...(role==='SUPER_ADMIN'?[['roles','Role Functions']]:[]),...(role==='DIVISION'?[['myfunctions','My Special Functions']]:[]),['account','My Account']];
            const section = tabs.some(([id]) => id === this.state.organizationSection) ? this.state.organizationSection : tabs[0][0];
            const editor = (choices,label) => {
                if (!choices.length) return null;
                const selected=choices.find(o => String(o.id) === String(this.state.edit_org_id));
                return surface(h('h3',null,label),h('form',{onSubmit:this.saveProfile},
                    h('div',{className:'form-grid'},
                        h('label',{className:'field'},h('span',null,'Select profile to edit'),
                            h('select',{value:selected?String(selected.id):'',onChange:this.changeProfile},
                                h('option',{value:''},'Choose a profile'),
                                choices.map(o=>h('option',{key:o.id,value:o.id},o.name+' · '+o.code)))),
                        selected&&selected.kind==='SCHOOL'?h('label',{className:'field'},h('span',null,'Official School ID * (editable)'),
                            h('input',{type:'text',inputMode:'numeric',
                                pattern:this.state.edit_code===selected.code&&!/^[0-9]{6}$/.test(selected.code)?undefined:'[0-9]{6}',
                                maxLength:this.state.edit_code===selected.code?Math.max(6,String(selected.code).length):6,required:true,
                                autoComplete:'off',value:this.state.edit_code,onChange:this.set('edit_code')})):null,
                        selected&&selected.kind==='SCHOOL'&&this.state.edit_code!==selected.code?
                            h('label',{className:'field'},h('span',null,'Reason for correcting School ID *'),
                                h('textarea',{required:true,maxLength:500,rows:2,value:this.state.edit_code_reason,
                                    onChange:this.set('edit_code_reason'),placeholder:'Official correction or Division instruction'})):null,
                        selected?this.field('edit_name','Official name','Organization name'):null,
                        selected?this.field('edit_address','Address','Office address'):null,
                        selected?this.field('edit_email','Contact email','Email','email'):null,
                        selected?this.field('edit_phone','Contact number','Telephone'):null,
                        selected&&selected.kind==='SCHOOL'?h('label',{className:'field'},h('span',null,'Implementation classification *'),
                            h('select',{required:false,value:this.state.schoolClassEditClass||'',onChange:this.set('schoolClassEditClass')},
                                h('option',{value:''},'Choose IUS or Non-IUS'),
                                h('option',{value:'IUS'},'IUS — Implementing Unit'),
                                h('option',{value:'NON_IUS'},'Non-IUS — Non-Implementing Unit'))):null,
                        selected&&selected.kind==='SCHOOL'?h('label',{className:'field'},h('span',null,'Classification effective from *'),
                            h('input',{required:this.state.schoolClassEditClass!=='',type:'date',value:this.state.schoolClassEditDate,
                                onChange:this.set('schoolClassEditDate')})):null,
                        selected&&selected.kind==='SCHOOL'&&(
                            this.state.schoolClassEditClass!==(selected.implementation_class||'') ||
                            (this.state.schoolClassEditClass!=='' && this.state.schoolClassEditDate!==(selected.implementation_effective_from||'')))?
                            h('label',{className:'field'},h('span',null,'Classification change / effective-date correction reason *'),
                                h('textarea',{required:true,maxLength:500,rows:2,value:this.state.schoolClassEditReason,
                                    onChange:this.set('schoolClassEditReason'),placeholder:'Division verification or reason for the corrected date'})):null),
                    selected&&selected.kind==='SCHOOL'?h('p',{className:'hint'},
                        'Current classification: '+(selected.implementation_class||'Awaiting Division verification')+
                        (selected.implementation_effective_from?' · effective '+selected.implementation_effective_from:'')+
                        '. Profile and classification save together; any classification change is recorded in history.'):null,
                    selected?h('div',null,
                        this.state.error?h('p',{role:'alert',className:'workflow-error'},this.state.error):null,
                        this.state.success?h('p',{role:'status',className:'workflow-success'},this.state.success):null,
                        formButton(selected.kind==='SCHOOL'?'Save School Profile':'Save Division Profile','PROFILE_SAVE')):
                        h('p',{className:'hint'},'Select an organization to edit its information.')));
            };
            const panel=[];
            if(section === 'division') {
                panel.push(surface(h('h3',null,'Division Office'),
                    h('p',{className:'body-muted'},'Division details and the schools registered under each Division Office.'),
                    divisions.length?h('div',{className:'org-hierarchy'},divisions.map(d=>h('div',{key:d.id,className:'org-branch'},
                        h('strong',null,'▣ '+d.name+' · '+d.code),
                        h('p',{className:'org-summary'},schools.filter(s=>Number(s.parent_division_id)===Number(d.id)).length+' linked school(s)')))):
                        h('p',{className:'hint'},'No Division Office has been created yet.')));
                if(role === 'SUPER_ADMIN' && !divisions.length) panel.push(surface(
                    h('h3',null,'Establish the Division Office'),
                    h('p',{className:'body-muted'},'Existing school data is preserved. Only the Super Administrator establishes the Division.'),
                    h('form',{onSubmit:this.makeDivision},h('div',{className:'form-grid'},
                        this.field('division_code','Division code','Official Division code'),this.field('division_name','Division Office name','Official Division name'),
                        this.field('division_address','Address (optional)','Postal address'),this.field('division_email','Contact email (optional)','division@example.gov.ph','email'),
                        this.field('division_phone','Contact number (optional)','Contact number')),formButton('Create Division Office'))));
                if(role === 'SUPER_ADMIN' || role === 'DIVISION') {
                    const editDivisions=rows.filter(o=>o.kind==='DIVISION');
                    panel.push(editor(editDivisions,'Edit Division Profile'));
                }
                panel.push(this.table('Division Office directory',['code','name','active'],rows.filter(o=>o.kind==='DIVISION')));
            } else if(section === 'schools') {
                panel.push(surface(h('h3',null,'School Management'),
                    h('p',{className:'body-muted'},'Register new schools, link existing school records, and maintain school profiles without changing accounts.'),
                    h('p',{className:'org-summary'},schools.length+' school(s) · '+unassigned.length+' awaiting Division assignment')));
                if((role==='SUPER_ADMIN' || role==='DIVISION') && divisions.length) {
                    panel.push(surface(h('h3',null,'Register a school'),h('form',{onSubmit:this.makeSchool},
                        h('div',{className:'form-grid'},this.select('selected_division_id','Parent Division Office',divisionOptions,'Choose Division'),
                        h('label',{className:'field'},h('span',null,'Official School ID * (six digits)'),
                            h('input',{type:'text',inputMode:'numeric',pattern:'[0-9]{6}',maxLength:6,required:true,
                                autoComplete:'off',placeholder:'e.g. 306351',value:this.state.school_code,onChange:this.set('school_code')})),
                        this.field('school_name','Official School name','School name'),
                        this.field('school_address','Address (optional)','School address'),this.field('school_email','Contact email (optional)','school@deped.gov.ph','email'),
                        this.field('school_phone','Contact number (optional)','Telephone'),
                        h('label',{className:'field'},h('span',null,'Implementation classification *'),h('select',{required:true,value:this.state.schoolClass,onChange:this.set('schoolClass')},
                            h('option',{value:''},'Choose classification'),h('option',{value:'IUS'},'IUS — Implementing Unit'),h('option',{value:'NON_IUS'},'Non-IUS — Non-Implementing Unit'))),
                        h('label',{className:'field'},h('span',null,'Effective from *'),h('input',{required:true,type:'date',value:this.state.schoolClassDate,onChange:this.set('schoolClassDate')}))),formButton('Register School'))));
                    if(unassigned.length) panel.push(surface(h('h3',null,'Link Existing School'),
                        h('p',{className:'body-muted'},'Link a legacy school without changing its ID, procurements, funds, or inventory.'),
                        h('form',{onSubmit:this.linkLegacySchool},h('div',{className:'form-grid'},
                            this.select('selected_division_id','Division Office',divisionOptions,'Select Division'),
                            this.select('legacy_school_id','Unassigned school',unassigned.map(o=>({id:o.id,name:o.name+' · '+o.code})))),formButton('Link Existing School'))));
                    const canEdit=rows.filter(o=>o.kind==='SCHOOL' && (role==='SUPER_ADMIN'||!!o.parent_division_id));
                    panel.push(editor(canEdit,'Edit School Profile'));
                }
                panel.push(this.table('School directory',['code','name','implementation_class','parent_division_id','active'],schools));
            } else if(section === 'school') {
                const current=schools.find(o=>String(o.id)===String(this.state.organization_id))||schools[0];
                panel.push(surface(h('h3',null,'My School Profile'),
                    current?h('div',{className:'org-profile-readonly'},
                        h('p',null,h('strong',null,'School Name: '),current.name),
                        h('p',null,h('strong',null,'School ID: '),current.code),
                        h('p',null,h('strong',null,'Implementation classification: '),current.implementation_class||'Awaiting Division verification'),
                        h('p',null,h('strong',null,'Address: '),current.address||'—'),
                        h('p',null,h('strong',null,'Contact: '),current.contact_email||'—')):h('p',{className:'hint'},'School profile unavailable.'),
                    h('p',{className:'body-muted'},'Contact your Division Administrator to request profile changes.')));
            } else if(section === 'users') {
                panel.push(surface(h('h3',null,'User Account Management'),
                    h('p',{className:'body-muted'},'Create accounts within the correct Division or School. Actions remain attributed to your own signed-in identity.')));
                if(role==='SUPER_ADMIN'&&divisions.length) panel.push(surface(
                    h('h3',null,'Provision Division Administrator'),
                    h('form',{onSubmit:e=>this.makeOrganizationUser(e,'DIVISION'),autoComplete:'off'},h('div',{className:'form-grid'},
                        this.select('selected_division_id','Division Office',divisionOptions,'Choose Division'),
                        this.field('new_division_user_name','Administrator full name','Full name'),
                        this.field('new_division_user_email','Email address','name@deped.gov.ph','email'),
                        this.field('new_division_user_password','Temporary password (12+ characters)','Unique password','password')),
                        h('p',{className:'hint'},'Share temporary credentials privately.'),formButton('Create Division Administrator','DIVISION_USER'))));
                if((role==='DIVISION'||role==='SUPER_ADMIN') && divisions.length) panel.push(surface(
                    h('h3',null,'Provision School Account'),
                    h('form',{onSubmit:e=>this.makeOrganizationUser(e,'SCHOOL'),autoComplete:'off'},h('div',{className:'form-grid'},
                        this.select('new_user_school_id','School',schoolOptions,'Choose your school'),
                        this.field('new_school_user_name','School user full name','Full name'),
                        this.field('new_school_user_email','School user email','name@deped.gov.ph','email'),
                        this.field('new_school_user_password','Temporary password (12+ characters)','Unique password','password')),
                        h('p',{className:'hint'},'The account will only access its authorized school.'),formButton('Create School Account','SCHOOL_USER'))));
                panel.push(this.table('Division and School users',['full_name','email','role','active'],this.state.orgUsers||[]));
                if(role==='SUPER_ADMIN') {
                    const resettable=(this.state.orgUsers||[]).filter(u=>Number(u.active)===1&&['DIVISION','SCHOOL'].includes(u.role));
                    panel.push(surface(
                        h('h3',null,'Reset an Existing User Password'),
                        h('p',{className:'body-muted'},'Recover an existing Division or School account without changing its permissions or creating a duplicate. Only the Super Administrator can reset passwords.'),
                        h('form',{onSubmit:this.resetOrganizationUserPassword,autoComplete:'off'},
                            h('div',{className:'form-grid'},
                                h('label',{className:'field'},h('span',null,'Existing user *'),
                                    h('select',{required:true,value:this.state.reset_user_id,onChange:this.set('reset_user_id')},
                                        h('option',{value:''},'Choose user account'),
                                        resettable.map(u=>h('option',{key:u.id,value:String(u.id)},u.full_name+' · '+u.email+' ('+u.role+')')))),
                                h('label',{className:'field'},h('span',null,'Your current Super Administrator password *'),
                                    h('input',{type:'password',required:true,autoComplete:'current-password',value:this.state.reset_admin_password,onChange:this.set('reset_admin_password')})),
                                h('label',{className:'field'},h('span',null,'New password for user (16+ characters) *'),
                                    h('input',{type:'password',required:true,minLength:16,maxLength:256,autoComplete:'new-password',value:this.state.reset_new_password,onChange:this.set('reset_new_password')})),
                                h('label',{className:'field'},h('span',null,'Confirm new password *'),
                                    h('input',{type:'password',required:true,minLength:16,maxLength:256,autoComplete:'new-password',value:this.state.reset_confirm_password,onChange:this.set('reset_confirm_password')}))),
                            h('label',{className:'field'},h('input',{type:'checkbox',required:true,checked:this.state.reset_identity_confirmed,
                                onChange:e=>this.setState({reset_identity_confirmed:e.target.checked})}),
                                ' I verified that this is the correct account owner and will share the password privately.'),
                            h('p',{className:'hint'},'Existing password cannot be recovered. Password resets are audited. Do not share credentials in screenshots, chat or public email.'),
                            formButton('Reset Selected User Password','ADMIN_PASSWORD_RESET'))));
                }

            } else if(section === 'roles' && role==='SUPER_ADMIN') {
                const c=this.state.roleCatalog;
                const d=this.state.roleDetail;
                const users=(this.state.orgUsers||[]).filter(u=>Number(u.active)===1&&['DIVISION','SCHOOL'].includes(u.role));
                const approvedSchools=schools.filter(o=>Number(o.active)===1);
                const chosenUser=users.find(u=>String(u.id)===String(this.state.roleUserId));
                const templates=c?.role_templates||{};
                const modules=['ALL',...new Set((c?.functions||[]).map(f=>f.module))];
                // Only show functions the selected user has NOT been assigned in this school.
                // The server returns this school-specific, effective/scheduled grant-filtered list.
                // Role template text is explanatory, not an authoritative code-based permission map.
                const availableCodes=new Set(d?.available_functions||[]);
                const availableFunctions=(c?.functions||[]).filter(f=>availableCodes.has(f.code));
                const visibleFunctions=availableFunctions.filter(f=>(this.state.roleModule==='ALL'||f.module===this.state.roleModule)&&
                    (f.label+' '+f.module).toLowerCase().includes(this.state.roleFunctionSearch.toLowerCase()));
                const selectedAvailable=visibleFunctions.some(f=>f.code===this.state.rolePermission);
                panel.push(h('div',{className:'roles-workspace'},
                    h('p',{className:'roles-warning',role:'status'},'Read-only Special Function registers are active for individually granted PR/RFQ/PO/IAR, Stock on Hand, Asset Register and Textbook VIEW. PR reprint requires another grant. All register and textbook management actions remain configured-only and legacy module write permissions do not change.'),
                    h('div',{className:'roles-toolbar'},
                        h('label',null,'Selected user',h('select',{value:this.state.roleUserId,onChange:e=>this.setState({roleUserId:e.target.value,roleDetail:null,rolePermission:'',roleError:'',roleNotice:''})},
                            h('option',{value:''},'Choose an existing user'),users.map(u=>h('option',{key:u.id,value:String(u.id)},u.full_name+' · '+u.role)))),
                        h('label',null,'Assigned school',h('select',{value:this.state.roleSchoolId,onChange:e=>this.setState({roleSchoolId:e.target.value,roleDetail:null,rolePermission:'',roleError:'',roleNotice:''})},
                            h('option',{value:''},'Select school scope'),approvedSchools.map(o=>h('option',{key:o.id,value:String(o.id)},o.name)))),
                        h('button',{type:'button',className:'outline',disabled:this.state.roleBusy||!this.state.roleUserId||!this.state.roleSchoolId,
                            onClick:this.loadRoleUser},'Review Access'),
                        h('button',{type:'button',className:'outline',disabled:this.state.roleBusy,onClick:this.loadRoleCatalog},'Refresh Functions')),
                    this.state.roleError?h('p',{role:'alert',className:'error'},this.state.roleError):null,
                    this.state.roleNotice?h('p',{role:'status',className:'success'},this.state.roleNotice):null,
                    h('div',{className:'roles-two-column'},
                        h('section',{className:'surface'},h('h3',null,'Default Functions'),
                            h('p',{className:'body-muted'},'Role defaults are shown for explanation. Existing authorization and tenant rules remain authoritative.'),
                            !chosenUser?h('p',{className:'hint'},'Select an individual user to review the role template.'):
                            h('div',null,h('strong',null,(templates[chosenUser.role]?.title)||chosenUser.role),
                                h('p',null,(templates[chosenUser.role]?.summary)||'Legacy role rules remain active.'),
                                ...((templates[chosenUser.role]?.functions)||[]).map((label,i)=>h('p',{key:i,className:'roles-default-row'},'Default · '+label)),
                                h('p',{className:'hint'},'Source: Default · server-side hard restrictions still apply.'))),
                        h('section',{className:'surface'},h('h3',null,'Special Functions'),
                            h('p',{className:'body-muted'},'Choose additional school-specific read-only registers for Stock on Hand, Asset Register and Textbooks, or authorized procurement documents. Manage/edit privileges are displayed as future configuration only and do not activate legacy routes.'),
                            d?h('div',null,
                                h('h4',null,d.user.full_name+' · selected school'),
                                ...(d.grants||[]).filter(g=>g.status==='ACTIVE').map(g=>h('div',{key:g.grant_id,className:'roles-grant'},
                                    h('div',null,h('strong',null,g.label),h('span',{className:'roles-grant-state'},g.effectiveness),
                                        h('small',null,g.starts_on+' to '+(g.ends_on||'No expiry')+' · school-scoped')),
                                    h('button',{type:'button',disabled:this.state.roleBusy,onClick:()=>this.removeRoleFunction(g)},'Revoke'))),
                                !(d.grants||[]).some(g=>g.status==='ACTIVE')?h('p',{className:'hint'},'No configured Special Functions for this user and school.'):null,
                                h('form',{onSubmit:this.addRoleFunction},
                                    h('div',{className:'roles-function-filter'},
                                        h('label',null,'Search functions',h('input',{type:'search',value:this.state.roleFunctionSearch,
                                            onChange:e=>this.setState({roleFunctionSearch:e.target.value,rolePermission:''}),placeholder:'Search by module or action'})),
                                        h('label',null,'Module',h('select',{value:this.state.roleModule,onChange:e=>this.setState({roleModule:e.target.value,rolePermission:''})},
                                            ...modules.map(m=>h('option',{key:m,value:m},m==='ALL'?'All modules':m))))),
                                    h('label',null,'Add Special Function',h('select',{required:true,disabled:this.state.roleBusy||!visibleFunctions.length,
                                        value:selectedAvailable?this.state.rolePermission:'',
                                        onChange:e=>this.setState({rolePermission:e.target.value})},
                                        h('option',{value:''},visibleFunctions.length?'Choose an unassigned function':'No unassigned functions available'),
                                        ...visibleFunctions.map(f=>h('option',{key:f.code,value:f.code},f.module+' · '+f.label)))),
                                    !availableFunctions.length?h('p',{className:'hint'},'All available Special Functions have already been assigned to this user for this school. Revoke a function to make it selectable again.'):null,
                                    availableFunctions.length&&!visibleFunctions.length?h('p',{className:'hint'},'No unassigned functions match the selected search or module.'):null,
                                    h('div',{className:'roles-date-row'},
                                        h('label',null,'Effective from',h('input',{type:'date',required:true,value:this.state.roleStartsOn,min:localToday(),onChange:e=>this.setState({roleStartsOn:e.target.value})})),
                                        h('label',null,'Ends (optional)',h('input',{type:'date',value:this.state.roleEndsOn,min:this.state.roleStartsOn,onChange:e=>this.setState({roleEndsOn:e.target.value})}))),
                                    h('label',null,'Authority and justification',h('textarea',{required:true,minLength:15,maxLength:500,rows:2,value:this.state.roleReason,onChange:e=>this.setState({roleReason:e.target.value}),placeholder:'Reference the approved scope and reason'})),
                                    h('button',{type:'submit',className:'primary',disabled:this.state.roleBusy||!selectedAvailable},'Record Special Function')),
                                h('button',{type:'button',className:'outline',onClick:()=>this.setState({roleAuditOpen:!this.state.roleAuditOpen})},this.state.roleAuditOpen?'Hide Audit History':'View Audit History'),
                                this.state.roleAuditOpen?h('div',{className:'roles-history'},
                                    ...(d.history||[]).map(event=>h('p',{key:event.id},event.event_type+' · '+event.permission+' · '+event.created_at+' · '+(event.actor_name||'Authorized administrator')+' · '+event.reason)),
                                    !(d.history||[]).length?h('p',{className:'hint'},'No recorded grant or revoke events.'):null):null
                            ):h('p',{className:'hint'},'Select a user and currently authorized school, then choose Review Access.')))));
            } else if(section === 'myfunctions' && (role==='SCHOOL'||role==='DIVISION')) {
                const data=this.state.myFunctions;
                const eligible=data?.schools||[];
                const selected=eligible.find(o=>String(o.school_id)===String(this.state.myFuncOrg));
                const fn=selected?.functions||[];
                const readableTypes=[['PR','procurement.pr.view'],['RFQ','procurement.rfq.view'],['PO','procurement.po.view'],['IAR','procurement.iar.view'],['STOCK','inventory.stocks.view'],['ASSET','property.assets.view'],['TEXTBOOK','textbooks.batches.view']];
                const allowedTypes=readableTypes.filter(([type,code])=>fn.some(f=>f.code===code&&(f.register_enabled||f.school_register_enabled)));
                const printPR=fn.some(f=>f.code==='procurement.pr.print_existing'&&f.print_existing_enabled);
                const docs=this.state.myFuncRecords?.documents||[];
                const schoolRows=this.state.myFuncRecords?.rows||[];
                const schoolCols=this.state.myFuncRecords?.columns||[];
                const isSchoolRegister=['STOCK','ASSET','TEXTBOOK'].includes(this.state.myFuncType);
                panel.push(surface(h('h3',null,'My Special Functions'),
                    h('p',{className:'body-muted'},'Additional named functions are available only for your assigned school and current effective dates. This register is read-only; other workflows retain their usual authorization.'),
                    h('button',{type:'button',className:'outline',disabled:this.state.myFuncBusy,onClick:this.loadMyFunctions},this.state.myFuncBusy?'Checking…':'Refresh My Functions'),
                    this.state.myFuncError?h('p',{role:'alert',className:'error'},this.state.myFuncError):null,
                    !eligible.length?h('p',{className:'hint'},'No current Special Functions assigned.'):null,
                    eligible.length?h('div',{className:'roles-toolbar'},
                        h('label',null,'Assigned school',h('select',{value:this.state.myFuncOrg,onChange:e=>this.setState({myFuncOrg:e.target.value,myFuncRecords:null})},
                            ...eligible.map(o=>h('option',{key:o.school_id,value:String(o.school_id)},o.school_name)))),
                        h('label',null,'Authorized register',h('select',{value:allowedTypes.some(x=>x[0]===this.state.myFuncType)?this.state.myFuncType:'',
                            onChange:e=>this.setState({myFuncType:e.target.value,myFuncRecords:null})},
                            h('option',{value:''},'Select authorized type'),...allowedTypes.map(([type])=>h('option',{key:type,value:type},({STOCK:'Stock on Hand',ASSET:'Asset Register',TEXTBOOK:'Textbook Records'})[type]||type)))),
                        !isSchoolRegister?h('label',null,'Document month',h('input',{type:'month',value:this.state.myFuncMonth,onChange:e=>this.setState({myFuncMonth:e.target.value,myFuncRecords:null})})):null,
                        h('button',{type:'button',className:'primary',disabled:this.state.myFuncBusy||!this.state.myFuncOrg||!allowedTypes.some(x=>x[0]===this.state.myFuncType),onClick:this.loadMyFunctionDocuments},'Open Read-only Register')):null,
                    selected?h('div',null,h('h4',null,'Currently assigned functions'),
                        ...fn.map(g=>h('p',{key:g.code,className:'roles-default-row'},g.label+' · '+((g.register_enabled||g.school_register_enabled)?'Active read-only register':g.print_existing_enabled?'Existing PR reprint':'Configured only (no legacy access)')+(g.ends_on?' · expires '+g.ends_on:'')))):null,
                    this.state.myFuncRecords?h('div',null,
                        h('h4',null,isSchoolRegister?'Read-only '+({STOCK:'Stock on Hand',ASSET:'Asset Register',TEXTBOOK:'Textbook Records'})[this.state.myFuncType]:'Finalized '+this.state.myFuncType+' · '+this.state.myFuncMonth),
                        isSchoolRegister?h('div',{className:'surface'},
                            h('p',{className:'hint'},'School-scoped special access · read-only · first 100 records; no opening balances, adjustments, source transactions or issuance authority added.'),
                            this.table('Authorized register',schoolCols,schoolRows)):null,
                        !isSchoolRegister&&!docs.length?h('p',{className:'hint'},'No finalized documents found for this period.'):null,
                        ...(!isSchoolRegister?docs:[]).map(d=>h('div',{key:d.id,className:'roles-grant'},h('div',null,
                            h('strong',null,d.display_number),h('small',null,d.period)),
                            this.state.myFuncType==='PR'&&printPR?h('a',{className:'outline',
                                href:'api/print.php?special=1&type=PR&organization_id='+encodeURIComponent(this.state.myFuncOrg)+'&id='+encodeURIComponent(d.id),
                                target:'_blank',rel:'noopener noreferrer'},'Reprint Existing'):h('small',null,'View only')))):null));
            } else if(section === 'account') {
                panel.push(surface(h('h3',null,'My Account · Change Password'),
                    h('p',{className:'body-muted'},'Change the password for your current account without changing organization roles or scope.'),
                    h('form',{onSubmit:this.changeOwnPassword},h('div',{className:'form-grid'},
                        this.field('own_current_password','Current password','','password'),
                        this.field('own_new_password','New password (12+ characters)','','password'),
                        this.field('own_confirm_password','Confirm new password','','password')),formButton('Change My Password'))));
            }
            const tabBar=h('div',{className:'org-tabbar',role:'tablist','aria-label':'Organization sections'},
                tabs.map(([id,label])=>h('button',{type:'button',key:id,id:'org-tab-'+id,role:'tab',
                    className:'org-tab'+(section===id?' is-active':''),'aria-selected':section===id,
                    'aria-controls':'org-tab-panel',tabIndex:section===id?0:-1,
                    onClick:()=>this.setState({organizationSection:id,error:'',success:''},()=>{if(id==='roles'&&!this.state.roleCatalog)this.loadRoleCatalog();if(id==='myfunctions')this.loadMyFunctions();}),
                    onKeyDown:e=>{if(!['ArrowLeft','ArrowRight','Home','End'].includes(e.key))return;
                        e.preventDefault();const i=tabs.findIndex(t=>t[0]===id);
                        const next=e.key==='Home'?0:e.key==='End'?tabs.length-1:
                            (i+(e.key==='ArrowRight'?1:-1)+tabs.length)%tabs.length;
                        const key=tabs[next][0];this.setState({organizationSection:key,error:'',success:''},
                            ()=>document.getElementById('org-tab-'+key)?.focus());}},label)));
            return h('div',{className:'section-stack org-section'},
                this.header('Organization Management','Division, school, user accounts and scoped role functions.'),
                h('p',{className:'org-build-marker'},'Organization tabs · interface v0.2.5'),
                tabBar,h('div',{className:'org-tab-panel',id:'org-tab-panel',role:'tabpanel',
                    'aria-labelledby':'org-tab-'+section},...panel));
        };
        this.dashboardView = () => { const v = this.state.dashboard; const groups = [['Purchase requests', v.pr, '▤'], ['Purchase orders', v.po, '▥'], ['Inspections & acceptance', v.iar, '◫'], ['Disbursement vouchers', v.dv, '▣'], ['Requisition & issues', v.ris, '▧']]; return React.createElement("div", { className: "section-stack ipdams-dashboard-page" },
            React.createElement("section", { className: "ipdams-school-banner", "aria-label": "Active organization dashboard" },
                React.createElement("img", { className: "ipdams-school-banner-icon", src: "api/branding.php?kind=icon", alt: "" }),
                React.createElement("div", { className: "ipdams-school-banner-details" },
                    React.createElement("span", { className: "ipdams-school-banner-kicker" }, "IPDAMS / " + (this.state.orgs.find(o => String(o.id) === String(this.state.organization_id))?.kind === "DIVISION" ? "DIVISION WORKSPACE" : "SCHOOL WORKSPACE")),
                    React.createElement("h1", null, this.state.orgs.find(o => String(o.id) === String(this.state.organization_id))?.name || "Dashboard"),
                    React.createElement("p", null, "Integrated Procurement, Disbursement and Asset Management System")),
                React.createElement("div", { className: "ipdams-school-banner-motto" }, "Integrated records.", React.createElement("br", null), "Accountable decisions.")),
            React.createElement("div", { className: "ipdams-dashboard-welcome" },
                React.createElement("div", null,
                    React.createElement("h2", null, "Welcome back", this.state.user?.name ? ", " + this.state.user.name.split(/\s+/)[0] : "", "!"),
                    React.createElement("p", null, "Here is your organization's procurement, disbursement, and property overview.")),
                React.createElement("div", { className: "ipdams-dashboard-welcome-actions" },
                    React.createElement("time", { dateTime: localToday() }, new Intl.DateTimeFormat('en-PH', { timeZone:'Asia/Manila', year:'numeric', month:'long', day:'numeric' }).format(new Date())),
                    this.canOperateWorkspace() ? React.createElement("button", { className: "ipdams-dashboard-new-pr", type: "button", onClick: () => this.setState({ tab: 'Procurement' }, () => this.workflowReload()) }, "Create a purchase request →") : null)),
            React.createElement("div", { className: "ipdams-dashboard-areas", "aria-label": "Open a workspace" },
                [["▣","Procurement","Procurement"],["₱","Disbursements","Disbursements"],["▥","Inventory","Inventory Management"],["⌂","Property","Property Accountability"]].map(([symbol,label,target])=>
                    React.createElement("button", { type: "button", key: target, className: "ipdams-dashboard-area", onClick: () => this.setState({ tab: target, error: '', success: '', details: null }, () => { if(target === 'Procurement') this.workflowReload(); if(target === 'Disbursements') {this.loadEligible(this.state.dv_type);this.loadFundPolicySettings();} }) },
                        React.createElement("span", { className: "ipdams-dashboard-area-icon", "aria-hidden": "true" }, symbol),
                        React.createElement("strong", null, label),
                        React.createElement("span", { "aria-hidden": "true" }, "›")))),
            React.createElement("div", { className: "ipdams-dashboard-kpi-title" },
                React.createElement("h2", null, "Workspace records"),
                React.createElement("span", null, "Counts reflect the selected organization")),
            React.createElement("div", { className: "stat-grid" }, groups.map(g => React.createElement("div", { className: "stat", key: g[0] },
                React.createElement("span", { className: "stat-icon" }, g[2]),
                React.createElement("div", { className: "stat-number" }, g[1] ?? '—'),
                React.createElement("div", { className: "stat-label" }, g[0])))),
            React.createElement("div", { className: "two-col" },
                React.createElement("div", { className: "surface" },
                    React.createElement("div", { className: "section-head" },
                        React.createElement("h3", null, "Workflow guide"),
                        React.createElement("span", { className: "muted" }, "Manual approvals remain external")),
                    [['01', 'Request', 'PR + funding'], ['02', 'Source & award', 'RFQ, quotation, award'], ['03', 'Order & accept', 'PO + IAR'], ['04', 'Disburse', 'Regular, direct, reimbursement DV']].map(x => React.createElement("div", { className: "step", key: x[0] },
                        React.createElement("span", null, x[0]),
                        React.createElement("div", null,
                            React.createElement("strong", null, x[1]),
                            React.createElement("small", null, x[2])),
                        React.createElement("b", null, "\u2192")))),
                React.createElement("div", { className: "surface" },
                    React.createElement("div", { className: "section-head" },
                        React.createElement("h3", null, "Attention & safeguards")),
                    React.createElement("div", { className: "alert-row" },
                        React.createElement("span", { className: "alert-dot amber" }),
                        React.createElement("div", null,
                            React.createElement("b", null, "Pending school-utilizable withholding"),
                            React.createElement("p", null,
                                "\u20B1",
                                v.pending_tax ?? '0.00',
                                " remains non-spendable until authorized confirmation."))),
                    React.createElement("div", { className: "alert-row" },
                        React.createElement("span", { className: "alert-dot blue" }),
                        React.createElement("div", null,
                            React.createElement("b", null, "Document signatures"),
                            React.createElement("p", null, "Finalized documents record paperwork; they never grant electronic approval."))),
                    React.createElement("div", { className: "alert-row" },
                        React.createElement("span", { className: "alert-dot blue" }),
                        React.createElement("div", null,
                            React.createElement("b", null, "School-scoped access"),
                            React.createElement("p", null, "All requests are validated by the PHP backend, not just this interface.")))))); };
        this.prForm = () => {
            const h=React.createElement,s=this.state;
            if(!this.canOperateWorkspace())return h('p',{className:'pr-start-notice'},'Your account has view-only access to this workspace. You can inspect existing Purchase Requests but cannot create one.');
            const funds=s.lists.funds||[];
            const estimate=s.prLines.reduce((sum,line)=>sum+(Number(line.quantity)||0)*(Number(line.unit_price)||0),0);
            const estimateLabel=Number.isFinite(estimate)?estimate.toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}):'—';
            return h('div',{className:'pr-start-form'},
                h('div',{className:'section-head'},h('h3',null,'New Purchase Request'),h('span',{className:'mini-tag'},'Save as draft first')),
                h('p',{className:'pr-start-notice'},'Prepare a request for the active organization shown below. Saving creates an editable draft; finalizing assigns a control number and leaves room for manual signatures.'),
                (()=>{const o=s.orgs.find(x=>String(x.id)===String(s.organization_id));return o?h('div',{className:'pr-organization-confirm'},h('strong',null,(o.kind==='DIVISION'?'DIVISION OFFICE':'SCHOOL')+' — '+o.name),h('span',null,'Every PR, PO, IAR and DV created from this procurement remains owned by this organization.')):null;})(),
                !funds.length ? h('div',{className:'pr-start-alert',role:'status'},
                    h('strong',null,'A fund source is needed before you can prepare a PR.'),
                    h('p',null,'Ask an authorized administrator to set up the school fund in Reference Data.'),
                    h('button',{type:'button',className:'outline',onClick:()=>{if(this.closeCreatePR())this.setState({tab:'Fund Management',fundSection:'funds',masterEditorOpen:false});}},'Open Sources of Funds')):null,
                h('form',{id:'pr-create-form',onSubmit:this.createPR,onKeyDown:this.prInputEnterGuard,className:'pr-start-fields'},
                    h('div',{className:'form-grid'},
                        h('label',{className:'field'},h('span',null,'PR document date *'),
                            h('input',{type:'date',required:true,min:'1900-01-01',max:localToday(),value:s.pr_doc_date,onChange:this.set('pr_doc_date')}),
                            weekendDocumentNotice(h,s.pr_doc_date,'PR document date')),
                        h('label',{className:'field'},h('span',null,'Source of fund *'),
                            h('select',{value:s.fund_id,required:true,disabled:s.workspaceLoading||!funds.length,onChange:this.set('fund_id')},
                                h('option',{value:''},'Choose a fund'),...funds.map(f=>h('option',{key:f.id,value:f.id},f.fiscal_year+' · '+f.name+' ('+f.source_code+')')))),
                        h('label',{className:'field'},h('span',null,'Requesting unit / department *'),
                            h('select',{required:true,value:s.requesting_unit,disabled:s.prUnitsLoading||!s.prUnits.length,onChange:this.set('requesting_unit')},
                                h('option',{value:''},s.prUnitsLoading?'Loading departments…':s.prUnits.length?'Choose department':'Configure departments in Reference Data'),
                                ...s.prUnits.map(unit=>h('option',{key:unit,value:unit},unit)))),
                        !s.prUnits.length?h('p',{className:'pr-start-notice'},'No departments are configured yet. Close this form and open Reference Data → Requesting Units.'):null,
                        h('label',{className:'field'},h('span',null,'Requestor / requesting employee *'),
                            h('select',{required:true,value:s.pr_requestor,disabled:s.staffLoading,onChange:this.set('pr_requestor')},
                                h('option',{value:''},s.staffLoading?'Loading employees…':'Choose the employee requesting these items'),
                                ...(s.staffData?.employees||[]).filter(emp=>emp.active).map(emp=>h('option',{key:emp.employee_id,value:emp.employee_id},emp.full_name+' · '+(emp.designation||emp.position_title||''))))),
                        !(s.staffData?.employees||[]).some(emp=>emp.active)?h('p',{className:'pr-start-notice'},'No active employee in this workspace. Add one under Reference Data → Employees.'):null,
                        h('label',{className:'field'},h('span',null,'Purpose / transaction title *'),
                            h('input',{type:'text',required:true,maxLength:240,placeholder:'What is being procured and why?',value:s.purpose,onChange:this.set('purpose')})),
                        h('label',{className:'field'},h('span',null,'Proposed procurement route (for manual review)'),
                            h('select',{value:s.pr_method,onChange:this.set('pr_method')},
                                h('option',{value:'REVIEW_REQUIRED'},'To be determined'),
                                h('option',{value:'RFQ_CANVASS'},'Canvass / RFQ'),
                                h('option',{value:'DIRECT_DISBURSEMENT'},'Direct disbursement (when allowed)')))),
                    h('div',{className:'line-heading'},h('strong',null,'Requested items *'),
                        h('button',{type:'button',className:'text-btn',disabled:s.busy,onClick:()=>this.setState({prLines:[...s.prLines,{item_id:'',quantity:'1',unit_price:'0.00'}]})},'+ Add item')),
                    h('p',{className:'pr-start-help'},'Search an item, enter quantity and price, then press Tab or Enter to focus the next catalog item. Save PR Draft only when finished.'),
                    h('div',{className:'pr-compact-head','aria-hidden':'true'},h('span',null,'#'),h('span',null,'Catalog item'),h('span',null,'Quantity'),h('span',null,'Unit price (₱)'),h('span',null,'Amount (₱)'),h('span',null,'')),
                    s.prLines.map((line,i)=>h('div',{className:'line-grid pr-compact-line',key:i},
                        h('span',{className:'pr-compact-number'},i+1),
                        this.prItemPicker(line,i,'create'),
                        h('label',{className:'field'},h('span',null,'Quantity'),h('input',{id:'pr-qty-create-'+i,type:'number','aria-label':'Quantity for line '+(i+1),required:true,min:'0.001',step:'0.001',value:line.quantity,onChange:e=>this.updateLine(i,'quantity',e.target.value),onKeyDown:e=>this.prQuantityKeyDown(e,'create',i)})),
                        h('label',{className:'field'},h('span',null,'Est. unit price (₱)'),h('input',{id:'pr-price-create-'+i,type:'number','aria-label':'Estimated unit price for line '+(i+1),required:!!line.item_id||Number(line.unit_price)>0||i!==s.prLines.length-1,min:'0.01',step:'0.01',
                            value:!line.item_id&&!(Number(line.unit_price)>0)&&i===s.prLines.length-1?'':line.unit_price,
                            onChange:e=>this.updateLine(i,'unit_price',e.target.value),
                            onKeyDown:e=>this.prPriceKeyDown(e,'create',i),onBlur:()=>{this.requestPrClassification('create',i,line);this.appendNextPrRow(i);}})),
                        h('output',{className:'pr-compact-amount','aria-label':'Estimated amount for line '+(i+1)},'₱'+((Number(line.quantity)||0)*(Number(line.unit_price)||0)).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2})),
                        h('button',{className:'remove-line',type:'button','aria-label':'Remove item '+(i+1),disabled:s.busy||s.prLines.length===1,onClick:()=>this.setState({prLines:s.prLines.filter((_,n)=>n!==i),prPickerOpen:''})},'×'))),
                    h('div',{className:'pr-start-estimate',role:'status'},h('span',null,'Estimated PR total'),h('strong',null,'₱'+estimateLabel)),
                    s.prCreateError?h('p',{className:'pr-start-alert',role:'alert'},s.prCreateError):null,
                    h('p',{className:'pr-start-help'},'Save as draft first. You can edit it before finalization.')));
        };
        this.prCreateDialog = () => {
            const h=React.createElement,s=this.state;
            if(!s.prCreateOpen || !this.canOperateWorkspace() || s.tab!=='Procurement')return null;
            const canSave=!s.busy && !s.workspaceLoading && !!s.organization_id && !!(s.lists.funds||[]).length;
            return h('div',{className:'modal-backdrop',onMouseDown:e=>{
                if(e.target===e.currentTarget)this.closeCreatePR();
            }},h('section',{className:'modal pr-detail-modal pr-edit-modal pr-create-modal',role:'dialog',
                'aria-modal':'true','aria-labelledby':'pr-create-title',onKeyDown:this.prCreateKeyDown},
                h('header',{className:'pr-detail-header'},
                    h('div',null,h('span',{className:'pr-detail-eyebrow'},'PROCUREMENT / PURCHASE REQUESTS'),
                        h('h2',{id:'pr-create-title'},'New Purchase Request')),
                    h('button',{type:'button',className:'icon-button pr-detail-close',disabled:s.busy,
                        onClick:this.closeCreatePR,'aria-label':'Close New PR editor'},'×')),
                h('div',{className:'modal-scroll pr-detail-scroll'},this.prForm()),
                h('footer',{className:'pr-detail-actions'},
                    h('button',{type:'button',className:'outline',disabled:s.busy,onClick:this.closeCreatePR},'Cancel'),
                    h('button',{type:'submit',form:'pr-create-form',className:'primary',disabled:!canSave},
                        s.busy?'Saving…':'Save PR Draft'))));
        };
        this.atcView = () => {
            const h=React.createElement;
            const all=this.state.lists.atcs||[], rates=this.state.lists.atc_rates||[];
            const search=this.state.masterSearch.toLowerCase();
            const visible=all.filter(a=>(this.state.atc_tax_filter==='ALL'||(a.tax_kind||'UNCLASSIFIED')===this.state.atc_tax_filter)&&(a.code+' '+a.description+' '+this.atcProfileNames(a).join(' ')+' '+(a.business_type||'BOTH')+' '+(a.tax_kind||'UNCLASSIFIED')).toLowerCase().includes(search));
            const selected=all.find(a=>a.code===this.state.atc_selected_code);
            const canEdit=['SUPER_ADMIN','DIVISION'].includes(this.state.user?.role);
            const tabs=[['suppliers','Suppliers'],['items','Item Catalog'],['requesting_units','Requesting Units'],['employees','Employees'],['officials','BAC & Heads'],['travel_signatories','Division Travel Signatories'],['letterhead','Document Letterhead'],['classifications','Division Classifications'],['property_registry','Property Registry'],['units','Units of Measure'],['atcs','Withholding Tax / ATC'],['vat_config','VAT Configuration'],['uacs','UACS Accounts']];
            const currentDay=new Intl.DateTimeFormat('sv-SE',{timeZone:'Asia/Manila'}).format(new Date());
            const percent=r=>(Number(r.rate)*100).toFixed(4).replace(/0+$/,'').replace(/\.$/,'')+'%';
            const field=(key,label,type='text',placeholder='')=>h('label',{className:'field',key},h('span',null,label),h('input',{type,value:this.state[key],onChange:this.set(key),placeholder,required:label.includes('*'),...(type==='number'?{min:'0.0001',max:'100',step:'any'}:{})}));
            const profileChecks=edit=>{
                const field=edit?'atc_edit_profile_category':'atc_profile_category';
                const value=this.state[field]||'';
                return h('label',{className:'field master-wide'},
                    h('span',null,'Supplier profile category *'),
                    h('select',{value,onChange:this.set(field),required:true},
                        h('option',{value:''},'Select one category'),
                        ...supplierProfileTypes.map(type=>h('option',{key:type,value:type},this.profileLabel(type)))),
                    h('span',{className:'hint'},'Each ATC belongs to exactly one category. An existing multi-category ATC must be reviewed and classified before saving.'));
            };
            const rateFor=a=>rates.find(r=>r.atc_code===a.code && r.starts<=currentDay && (!r.ends||r.ends>=currentDay));
            const current=selected?rates.filter(r=>r.atc_code===selected.code):[];
            const openRates=current.filter(r=>!r.ends);
            const dialogTitle=this.state.atc_dialog==='new'?'Add ATC and initial rate':selected?'Manage ATC — '+selected.code:'ATC details';
            const dialogMessage=(this.state.error||this.state.success)?h('div',{className:this.state.error?'atc-dialog-alert is-error':'atc-dialog-alert is-success',role:this.state.error?'alert':'status'},this.state.error||this.state.success):null;
            const addForm=h('form',{onSubmit:this.saveAtc,className:'atc-dialog-form'},
                h('div',{className:'form-grid'},field('atc_code','ATC code *','text','Official ATC code'),h('label',{className:'field master-wide'},h('span',null,'Description *'),h('textarea',{value:this.state.atc_description,onChange:this.set('atc_description'),required:true,rows:4,placeholder:'Complete official ATC description'})),
                    profileChecks(false),
                    h('label',{className:'field'},h('span',null,'Business type'),h('select',{value:this.state.atc_business_type,onChange:this.set('atc_business_type')},...[['CORPORATE','Corporate / Commercial'],['INDIVIDUAL','Individual'],['BOTH','Both']].map(([v,l])=>h('option',{key:v,value:v},l)))),
                    h('label',{className:'field'},h('span',null,'Withholding Tax Type *'),h('select',{required:true,value:this.state.atc_tax_kind,onChange:this.set('atc_tax_kind')},...[['','Select VAT or EWT'],['VAT','VAT Withholding'],['EWT','Expanded Withholding Tax (EWT)']].map(([v,l])=>h('option',{key:v,value:v},l)))),
                    field('atc_initial_percent','Initial withholding rate (%) *','number','e.g. 1.00'),field('atc_initial_start','Effective from *','date'),field('atc_initial_end','Effective until (optional)','date'),
                    h('label',{className:'field master-wide'},h('span',null,'Authority / regulation reference *'),h('textarea',{value:this.state.atc_initial_authority,onChange:this.set('atc_initial_authority'),required:true,rows:2,placeholder:'Official basis and reference'}))),
                h('div',{className:'atc-dialog-actions'},h('button',{type:'button',className:'outline',disabled:this.state.busy,onClick:this.closeAtcDialog},'Cancel'),h('button',{type:'submit',className:'primary',disabled:this.state.busy},this.state.busy?'Saving…':'Save ATC and Rate')));
            const editForm=selected?h('form',{className:'atc-dialog-form atc-edit-form',onSubmit:this.saveAtcMetadata},
                h('div',{className:'atc-edit-caption'},'Code ',h('strong',null,selected.code),' · The ATC code cannot be changed.'),
                h('div',{className:'form-grid'},h('label',{className:'field master-wide'},h('span',null,'Description *'),h('textarea',{value:this.state.atc_edit_description,onChange:this.set('atc_edit_description'),required:true,rows:4,placeholder:'Complete official ATC description'})),
                    profileChecks(true),
                    h('label',{className:'field'},h('span',null,'Business type'),h('select',{value:this.state.atc_edit_business_type,onChange:this.set('atc_edit_business_type')},...[['CORPORATE','Corporate / Commercial'],['INDIVIDUAL','Individual'],['BOTH','Both']].map(([v,l])=>h('option',{key:v,value:v},l)))),
                    h('label',{className:'field'},h('span',null,'Withholding Tax Type *'),h('select',{required:true,value:this.state.atc_edit_tax_kind,onChange:this.set('atc_edit_tax_kind')},...[['','Classify before use in DV'],['VAT','VAT Withholding'],['EWT','Expanded Withholding Tax (EWT)']].map(([v,l])=>h('option',{key:v,value:v},l))))),
                h('div',{className:'atc-dialog-actions'},h('button',{className:'primary',type:'submit',disabled:this.state.busy},this.state.busy?'Saving…':'Save ATC Details'),
                    h('button',{type:'button',className:'outline',disabled:this.state.busy,onClick:()=>this.toggleAtc(selected)},Number(selected.active)?'Deactivate ATC':'Reactivate ATC'))):null;
            const ratesView=selected?h('div',{className:'atc-rate-section'},
                h('div',{className:'section-head'},h('h4',null,'Rate History'),h('span',{className:'count'},current.length+' version(s)')),
                h('div',{className:'table-scroll'},h('table',{className:'master-table'},h('thead',null,h('tr',null,...['Rate','From','Until','Authority'].map(x=>h('th',{key:x},x)))),h('tbody',null,...current.map(r=>h('tr',{key:r.id},h('td',null,percent(r)),h('td',null,r.starts),h('td',null,r.ends||'Open-ended'),h('td',null,r.authority_reference))),!current.length?h('tr',null,h('td',{colSpan:4,className:'empty'},'No rate versions recorded')):null))),
                canEdit?h('div',{className:'inline-actions atc-actions'},h('button',{type:'button',className:'outline',disabled:!Number(selected.active)||this.state.busy,onClick:()=>this.setState({atc_action:this.state.atc_action==='add'?'':'add',rate_atc_code:selected.code,error:''})},'+ Add Rate Version'),
                    h('button',{type:'button',className:'outline',disabled:openRates.length!==1||this.state.busy,onClick:()=>this.setState({atc_action:this.state.atc_action==='close'?'':'close',rate_close_id:String(openRates[0]?.id||''),error:''})},'Close Current Rate')):null,
                canEdit&&this.state.atc_action==='add'?h('form',{className:'atc-inner-form',onSubmit:this.saveAtcRate},h('h4',null,'Add rate version for '+selected.code),h('div',{className:'form-grid'},field('rate_percent','New withholding rate (%) *','number','e.g. 1.00'),field('rate_starts','Effective from *','date'),field('rate_ends','Effective until (optional)','date'),h('label',{className:'field master-wide'},h('span',null,'Authority / regulation reference *'),h('textarea',{value:this.state.rate_authority,onChange:this.set('rate_authority'),required:true,rows:2}))),h('p',{className:'hint'},'Close any overlapping open-ended rate before entering a new one. Historical rates are not overwritten.'),h('div',{className:'atc-dialog-actions'},h('button',{type:'button',className:'outline',onClick:()=>this.setState({atc_action:''})},'Cancel'),h('button',{type:'submit',className:'primary',disabled:this.state.busy},'Save New Rate Version'))):null,
                canEdit&&this.state.atc_action==='close'?h('form',{className:'atc-inner-form',onSubmit:this.closeAtcRate},h('h4',null,'Close open-ended rate for '+selected.code),h('p',{className:'body-muted'},openRates[0]?'Current rate: '+percent(openRates[0])+' from '+openRates[0].starts:'No open-ended rate'),field('rate_close_date','Last effective date *','date'),h('div',{className:'atc-dialog-actions'},h('button',{type:'button',className:'outline',onClick:()=>this.setState({atc_action:''})},'Cancel'),h('button',{type:'submit',className:'primary',disabled:this.state.busy},'Close This Rate'))):null):null;
            const modal=this.state.atc_dialog && (this.state.atc_dialog==='new'||selected)?h('div',{className:'atc-modal-backdrop',onMouseDown:e=>{if(e.target===e.currentTarget)this.closeAtcDialog();}},
                h('section',{className:'atc-dialog',role:'dialog','aria-modal':'true','aria-labelledby':'atc-dialog-heading',onKeyDown:this.atcDialogKeyDown},
                    h('div',{className:'atc-dialog-head'},h('div',null,h('span',{className:'eyebrow'},'REFERENCE DATA / ATC'),h('h2',{id:'atc-dialog-heading'},dialogTitle)),
                        h('button',{type:'button',className:'atc-dialog-close',title:'Close window','aria-label':'Close ATC window','data-atc-focus':true,disabled:this.state.busy,onClick:this.closeAtcDialog},'×')),
                    h('div',{className:'atc-dialog-content'},dialogMessage,
                        this.state.atc_dialog==='new'?h('div',null,h('p',{className:'body-muted'},'Enter a verified ATC and its initial effective-dated rate. VAT registration remains a supplier attribute and is separate from VAT withholding vs EWT.'),addForm):
                            h('div',null,h('p',{className:'body-muted'},'Update ATC details or manage effective-dated rates. Previously finalized voucher rates are not recalculated.'),canEdit?editForm:h('p',{className:'hint'},'You have read-only access.'),ratesView)))):null;
            return h('div',{className:'section-stack'},
                this.header('Reference data','Supplier, catalog, funding and withholding-tax reference settings.'),
                h('div',{className:'master-tabs',role:'tablist','aria-label':'Reference data'},...tabs.map(([v,label])=>h('button',{key:v,type:'button',role:'tab','aria-selected':v==='atcs',className:'master-tab '+(v==='atcs'?'selected':''),onClick:()=>this.setState({masterSection:v,masterSearch:'',masterEditorOpen:false,atc_dialog:''})},label))),
                h('div',{className:'surface atc-directory'},
                    h('div',{className:'section-head atc-directory-head'},h('div',null,h('h3',null,'ATC Directory'),h('p',{className:'body-muted'},'VAT withholding and EWT are maintained separately. Review unclassified older codes before using them in a new DV.')),
                        h('div',{className:'inline-actions'},h('span',{className:'count'},all.length+' ATCs'),h('button',{type:'button',className:'outline',disabled:this.state.atc_list_loading,onClick:this.reloadAtcLists},this.state.atc_list_loading?'Loading…':'Refresh list'),
                            canEdit?h('button',{type:'button',className:'primary',onClick:this.openNewAtc},'+ Add ATC'):null)),
                    h('div',{className:'inline-actions',role:'group','aria-label':'ATC tax type filter'},...[['ALL','All'],['VAT','VAT Withholding'],['EWT','EWT'],['UNCLASSIFIED','Needs Review']].map(([v,label])=>h('button',{key:v,type:'button',className:this.state.atc_tax_filter===v?'primary':'outline',onClick:()=>this.setState({atc_tax_filter:v})},label))),
                    h('label',{className:'field atc-search-field'},h('span',null,'Search ATC'),h('input',{type:'search',value:this.state.masterSearch,onChange:this.set('masterSearch'),placeholder:'Code, description, profile or business type'})),
                    !all.length?h('div',{className:'hint'},'No ATCs loaded. If a code was already saved, select Refresh list; API errors appear above the page.'):null,
                    h('div',{className:'table-scroll'},h('table',{className:'master-table'},h('thead',null,h('tr',null,...['ATC','Description','Tax Type','Supplier Profile Category','Business Type','Current Rate','Status','Action'].map(c=>h('th',{key:c},c)))),h('tbody',null,...visible.map(a=>h('tr',{key:a.code},h('td',null,h('strong',null,a.code)),h('td',null,a.description),h('td',null,a.tax_kind==='VAT'?'VAT Withholding':a.tax_kind==='EWT'?'EWT':'Needs Review'),h('td',null,this.atcProfileNames(a).length===1?this.atcProfileNames(a)[0]:'Needs classification review ('+(this.atcProfileNames(a).join(', ')||'none')+')'),h('td',null,(a.business_type||'BOTH')==='CORPORATE'?'Corporate':(a.business_type||'BOTH')==='INDIVIDUAL'?'Individual':'Both'),h('td',null,rateFor(a)?percent(rateFor(a)):'No current rate'),h('td',null,Number(a.active)?'Active':'Inactive'),h('td',null,h('button',{type:'button',className:'text-btn',onClick:()=>this.openAtc(a.code)},canEdit?'Edit / Manage':'View Rates')))),!visible.length?h('tr',{key:'empty'},h('td',{colSpan:8,className:'empty'},all.length?'No matching ATCs':'No ATCs created yet')):null)))),
                modal
            );
        };
        this.loadUacs = async (offset=0) => {
            const orgId=String(this.state.organization_id||'');
            if(!orgId)return;
            const serial=this.uacsRequest=(this.uacsRequest||0)+1;
            this.setState({uacsLoading:true,error:''});
            try{
                const data=await this.get('uacs',{organization_id:orgId,q:this.state.uacsQuery,
                    status:this.state.uacsStatus,classification:this.state.uacsClassification,offset});
                if(serial!==this.uacsRequest||orgId!==String(this.state.organization_id))return;
                this.setState({uacsData:data,uacsPage:offset,uacsLoading:false});
            }catch(err){if(serial===this.uacsRequest)this.setState({uacsLoading:false,error:'UACS accounts could not load: '+err.message});}
        };
        this.toggleUacs = async account => {
            if(this.state.busy||!['SUPER_ADMIN','DIVISION'].includes(this.state.user?.role))return;
            const enable=!Number(account.active);
            if(!window.confirm((enable?'Enable':'Disable')+' UACS '+account.code+'? '+(enable?'Verify its accounting applicability before using it in a DV.':'Existing finalized DV entries will be preserved.')))return;
            const result=await this.task('master.uacs.status',{code:account.code,active:enable});
            if(result)await this.loadUacs(this.state.uacsPage);
        };
        this.uacsView = () => {
            const h=React.createElement, data=this.state.uacsData||{}, rows=data.rows||[], summary=data.summary||{};
            const canEdit=['SUPER_ADMIN','DIVISION'].includes(this.state.user?.role);
            const options=[['suppliers','Suppliers'],['items','Item Catalog'],['requesting_units','Requesting Units'],['employees','Employees'],['officials','BAC & Heads'],['travel_signatories','Division Travel Signatories'],['letterhead','Document Letterhead'],['classifications','Division Classifications'],['property_registry','Property Registry'],['units','Units of Measure'],['atcs','Withholding Tax / ATC'],['vat_config','VAT Configuration'],['uacs','UACS Accounts']];
            const tabbar=h('div',{className:'master-tabs',role:'tablist','aria-label':'Reference data'},...options.map(([v,title])=>h('button',{
                key:v,type:'button',role:'tab','aria-selected':v==='uacs',className:'master-tab '+(v==='uacs'?'selected':''),
                onClick:()=>this.setState({masterSection:v,masterSearch:'',masterEditorOpen:false},()=>{if(v==='uacs')this.loadUacs(0);})},title)));
            const select=(label,value,values,onChange)=>h('label',{className:'field'},h('span',null,label),h('select',{value,onChange},...values.map(([key,title])=>h('option',{key,value:key},title))));
            return h('div',{className:'section-stack'},this.header('Reference data','Suppliers, items, withholding tax and UACS accounting references.'),tabbar,
                h('div',{className:'surface uacs-reference'},
                    h('div',{className:'section-head'},h('h3',null,'UACS Account Directory'),h('span',{className:'mini-tag'},'Shared reference')),
                    h('p',{className:'body-muted'},'Imported accounts are disabled by default, except source-active Inventory (104) and MOOE (502) accounts. Division/technical administrators can activate additional accounts after review.'),
                    h('div',{className:'uacs-stats'},
                        h('span',null,'Total: ',h('strong',null,summary.total??0)),
                        h('span',null,'Enabled: ',h('strong',null,summary.enabled??0)),
                        h('span',null,'Disabled: ',h('strong',null,summary.disabled??0))),
                    h('form',{className:'uacs-filters',onSubmit:e=>{e.preventDefault();this.loadUacs(0);}},
                        h('label',{className:'field'},h('span',null,'Code or account name'),h('input',{type:'search',value:this.state.uacsQuery,onChange:this.set('uacsQuery'),placeholder:'Search UACS accounts',maxLength:80})),
                        select('Classification',this.state.uacsClassification,[['ALL','All classifications'],['INVENTORY','Inventory (104)'],['MOOE','MOOE (502)'],['OTHER','Other codes']],e=>this.setState({uacsClassification:e.target.value},()=>this.loadUacs(0))),
                        select('Status',this.state.uacsStatus,[['ALL','All statuses'],['ACTIVE','Enabled'],['INACTIVE','Disabled']],e=>this.setState({uacsStatus:e.target.value},()=>this.loadUacs(0))),
                        h('button',{type:'submit',className:'outline',disabled:this.state.uacsLoading},this.state.uacsLoading?'Loading…':'Search / Refresh')),
                    h('div',{className:'uacs-paging'},h('span',null,'Showing '+(rows.length?(Number(data.offset)+1):0)+'–'+(Number(data.offset||0)+rows.length)+' of '+Number(data.total||0)),
                        h('div',{className:'inline-actions'},
                            h('button',{className:'outline',type:'button',disabled:this.state.uacsLoading||!Number(data.offset),onClick:()=>this.loadUacs(Math.max(0,Number(data.offset)-80))},'Previous'),
                            h('button',{className:'outline',type:'button',disabled:this.state.uacsLoading||(Number(data.offset)+rows.length>=Number(data.total)),onClick:()=>this.loadUacs(Number(data.offset)+80)},'Next'))),
                    h('div',{className:'table-scroll'},h('table',{className:'master-table'},
                        h('thead',null,h('tr',null,...['UACS','Account Name','Classification','Status','Action'].map(x=>h('th',{key:x},x)))),
                        h('tbody',null,...rows.map(r=>h('tr',{key:r.code},h('td',null,h('strong',null,r.code)),h('td',null,r.account_name),
                            h('td',null,r.code.startsWith('104')?'Inventory':r.code.startsWith('502')?'MOOE':'Other'),
                            h('td',null,Number(r.active)?'Enabled':'Disabled'),
                            h('td',null,canEdit?h('button',{type:'button',className:'text-btn',disabled:this.state.busy||this.state.uacsLoading,onClick:()=>this.toggleUacs(r)},Number(r.active)?'Disable':'Enable'):'Read only'))),
                            !rows.length?h('tr',null,h('td',{colSpan:5,className:'empty'},this.state.uacsLoading?'Loading accounts…':'No accounts match the filters')):null)))));
        };
        // Keep Division classification maintenance in an immediately visible
        // dialog.  Before v0.2.64 Edit / Map silently updated a form *below*
        // the long classification table, so the click appeared unresponsive.
        this.startClassEdit = record => this.setState({
            classEditCode:record.code||'',classCode:record.code||'',className:record.name||'',
            classUacs:record.default_uacs_code||'',classAppliesTo:record.applies_to||'SEMI_EXPENDABLE',
            classProcurementTypes:[...(record.procurement_item_types||[])],classActive:Boolean(record.active),
            classDialogOpen:true,error:'',success:''
        });
        this.resetClassEdit = () => this.setState({classDialogOpen:false,classEditCode:'',classCode:'',className:'',classUacs:'',classAppliesTo:'SEMI_EXPENDABLE',classProcurementTypes:[],classActive:true});
        // Reference-only MOOE expense presets. Never activate a UACS account or create an item.
        this.startMooeExpensePreset = kind => {
            if(!['TRAVEL_LOCAL','TRAINING'].includes(kind))return;
            const p=kind==='TRAVEL_LOCAL'
                ? {code:'TRAVEL_LOCAL',name:'Traveling Expenses – Local',uacs:'5020101000'}
                : {code:'TRAINING_EXPENSES',name:'Training Expenses',uacs:'5020201000'};
            const existing=(this.state.divisionClassifications||[]).find(c=>c.code===p.code);
            if(existing){this.startClassEdit(existing);return;}
            const enabled=!!(this.state.accounts||[]).find(a=>a.code===p.uacs);
            this.setState({classDialogOpen:true,classEditCode:'',classCode:p.code,
                className:p.name,classAppliesTo:'OTHER_MOOE',classProcurementTypes:[],classUacs:enabled?p.uacs:'',
                classActive:true,error:'',success:enabled?'':'UACS '+p.uacs+' is not enabled. The class can be kept pending, but the Division Accountant must review and enable the account before it is mapped.'});
        };
        this.openItemUacsFromClass = () => {
            if(this.state.busy)return;
            // Historic item mappings remain explicitly versioned and auditable.
            // This shortcut is for reviewing older items and exceptions.
            this.resetClassEdit();
            this.setState({masterSection:'items',masterEditorOpen:false,itemUacsId:'',itemUacsCode:'',error:''},()=>{
                this.loadDivisionItemUacs();
                window.requestAnimationFrame(()=>document.getElementById('division-item-uacs-map')?.scrollIntoView({behavior:'smooth',block:'start'}));
            });
        };
        this.saveClass = async e => {
            e.preventDefault();
            const code=this.state.classCode.trim().toUpperCase();
            const result=await this.task('master.division_classification.save',{
                code,name:this.state.className.trim(),active:Boolean(this.state.classActive),
                applies_to:this.state.classAppliesTo,default_uacs_code:this.state.classUacs,
                procurement_item_types:this.state.classAppliesTo==='OTHER_MOOE'?this.state.classProcurementTypes:[]
            });
            if(result)this.resetClassEdit();
        };
        // Conservative account-family checks in the selector. The server
        // repeats these checks, and the Division Accountant approves each code.
        this.procurementClassMatches = (cls,category) => !!cls && !!cls.active && (
            cls.applies_to===category ||
            (cls.applies_to==='OTHER_MOOE' && ['SUPPLY','SERVICE'].includes(category)
                && (cls.procurement_item_types||[]).includes(category))
        ) && this.divisionClassUacsEligible(category,cls.default_uacs_code);
        this.divisionClassUacsEligible = (category,code) => {
            const groups={SUPPLY:['502'],SEMI_EXPENDABLE:['502','104','106','108'],
                PPE:['106','108'],TEXTBOOK:['104','502'],SERVICE:['502'],
                OTHER_MOOE:['502']};
            return /^\d{10}$/.test(String(code||'')) && (groups[category]||[]).includes(String(code).slice(0,3));
        };
        this.divisionClassTypeName = type => ({SUPPLY:'School-use consumables',SEMI_EXPENDABLE:'Semi-expendable',
            PPE:'Property, plant & equipment',TEXTBOOK:'Textbooks',SERVICE:'Services',
            OTHER_MOOE:'Other MOOE expenses (non-inventory)'})[type]||type;
        this.classificationLabel = code => this.state.divisionClassifications.find(c=>c.code===code)?.name || semiSubcategoryLabel(code) || code || '';
        this.loadPropertyRegistry = async () => {
            const org=String(this.state.organization_id||'');
            if(!org)return;
            this.setState({propertyRegistryBusy:true,propertyRegistryError:'',propertyRegistry:null});
            try {
                const data=await this.api('division.property_registry');
                if(String(this.state.organization_id)!==org)return;
                this.setState({propertyRegistry:data,propertyRegistryBusy:false});
            } catch(e) { if(String(this.state.organization_id)===org)this.setState({propertyRegistryBusy:false,propertyRegistryError:e.message}); }
        };
        this.savePropertyThreshold = async (e) => {
            e.preventDefault();
            const reg=this.state.propertyRegistry;if(!reg)return;
            this.setState({busy:true,propertyRegistryError:''});
            try {
                await this.api('master.property.threshold.save',{
                    organization_id:Number(this.state.organization_id),
                    expected_version:reg.version_id,
                    threshold:this.state.propertyThresholdValue,
                    effective_from:this.state.propertyThresholdDate,
                    reason:this.state.propertyThresholdReason.trim()
                });
                this.setState({propertyThresholdDialog:false,propertyThresholdReason:'',success:'Property threshold saved'},()=>this.loadPropertyRegistry());
            }catch(e){this.setState({propertyRegistryError:e.message});}
            finally{this.setState({busy:false});}
        };
        this.savePropertyLife = async (e) => {
            e.preventDefault();
            const reg=this.state.propertyRegistry,entry=this.state.propertyLifeDialog;if(!reg||!entry)return;
            const raw=this.state.propertyLifeMonths.trim();
            if(raw!=='' && (!/^\d+$/.test(raw)||Number(raw)<1||Number(raw)>1200)){
                this.setState({propertyRegistryError:'Useful life must be blank or 1–1200 whole months'});return;
            }
            this.setState({busy:true,propertyRegistryError:''});
            try {
                await this.api('master.property.useful_life.save',{
                    organization_id:Number(this.state.organization_id),expected_version:reg.version_id,
                    registry_id:entry.id,useful_life_months:raw===''?null:Number(raw),
                    reason:this.state.propertyLifeReason.trim()
                });
                this.setState({propertyLifeDialog:null,propertyLifeReason:'',success:'Estimated useful life updated'},()=>this.loadPropertyRegistry());
            }catch(e){this.setState({propertyRegistryError:e.message});}
            finally{this.setState({busy:false});}
        };
        this.openPropertyEntry = (entry=null) => {
            const all=this.state.propertyRegistry?.records||[];
            this.setState({propertyEntryDialog:entry||'NEW',
                propertyEntrySection:entry?.section||this.state.propertyRegistrySection||all[0]?.section||'',
                propertyEntryDescription:entry?.description||'',propertyEntryMode:entry?.mode||'HV_LV',
                propertyEntryHvCode:entry?.hv_code||'',propertyEntryLvCode:entry?.lv_code||'',
                propertyEntryReferenceCode:entry?.reference_code||'',propertyEntryDetail:entry?.detail||'',
                propertyEntryMonths:entry?.effective_useful_life_months===null?'':String(entry?.effective_useful_life_months??''),
                propertyEntryReason:'',propertyRegistryError:''});
        };
        this.savePropertyEntry = async e => {
            e.preventDefault();
            const reg=this.state.propertyRegistry;if(!reg)return;
            const raw=this.state.propertyEntryMonths.trim();
            if(raw!=='' && (!/^\d+$/.test(raw)||Number(raw)<1||Number(raw)>1200)){
                this.setState({propertyRegistryError:'Useful life must be blank or 1–1200 whole months'});return;
            }
            this.setState({busy:true,propertyRegistryError:''});
            try {
                await this.api('master.property.registry.save',{
                    organization_id:Number(this.state.organization_id),expected_version:reg.version_id,
                    registry_id:this.state.propertyEntryDialog==='NEW'?'':this.state.propertyEntryDialog.id,
                    section:this.state.propertyEntrySection.trim(),description:this.state.propertyEntryDescription.trim(),
                    mode:this.state.propertyEntryMode,hv_code:this.state.propertyEntryMode==='HV_LV'?this.state.propertyEntryHvCode.trim():'',
                    lv_code:this.state.propertyEntryMode==='HV_LV'?this.state.propertyEntryLvCode.trim():'',
                    reference_code:this.state.propertyEntryMode==='REFERENCE'?this.state.propertyEntryReferenceCode.trim():'',
                    detail:this.state.propertyEntryDetail.trim(),useful_life_months:raw===''?null:Number(raw),
                    reason:this.state.propertyEntryReason.trim()
                });
                this.setState({propertyEntryDialog:null,propertyRegistrySearch:this.state.propertyEntryDescription.trim(),
                    propertyRegistrySection:'',success:'Division registry entry saved'},()=>this.loadPropertyRegistry());
            }catch(e){this.setState({propertyRegistryError:e.message});}
            finally{this.setState({busy:false});}
        };
        this.propertyRegistryView = () => {
            const h=React.createElement,reg=this.state.propertyRegistry;
            const canEdit=['SUPER_ADMIN','DIVISION'].includes(this.state.user?.role)&&Boolean(reg?.can_edit);
            const options=[['suppliers','Suppliers'],['items','Item Catalog'],['requesting_units','Requesting Units'],['employees','Employees'],['officials','BAC & Heads'],['travel_signatories','Division Travel Signatories'],['letterhead','Document Letterhead'],['classifications','Division Classifications'],['property_registry','Property Registry'],['units','Units of Measure'],['atcs','Withholding Tax / ATC'],['vat_config','VAT Configuration'],['uacs','UACS Accounts']];
            const sections=reg?[...new Set(reg.records.map(r=>r.section))]:[];
            const q=this.state.propertyRegistrySearch.trim().toLowerCase();
            const matches=reg?reg.records.filter(r=>(!this.state.propertyRegistrySection || r.section===this.state.propertyRegistrySection) &&
                (!q || [r.description,r.section,r.hv_code,r.lv_code,r.reference_code,r.detail].some(v=>String(v||'').toLowerCase().includes(q)))):[];
            const show=matches.slice(0,80);
            const previewValue=this.state.propertyUnitCost.trim();
            const validPreview=/^\d{1,10}(?:\.\d{1,2})?$/.test(previewValue);
            const previewCents=validPreview?Math.round(Number(previewValue)*100):null;
            const thresholdCents=reg?Math.round(Number(reg.threshold)*100):500000;
            const previewTier=previewCents===null?null:previewCents>=thresholdCents?'HV':'LV';
            const modal=(title,form,onSubmit)=>h('div',{className:'ipr-overlay',role:'presentation'},
                h('div',{className:'ipr-dialog',role:'dialog','aria-modal':'true','aria-label':title},
                    h('h3',null,title),h('form',{onSubmit},this.state.propertyRegistryError?h('p',{className:'ipr-error',role:'alert'},this.state.propertyRegistryError):null,form,
                        h('div',{className:'ipr-actions'},
                            h('button',{type:'button',onClick:()=>{if(window.confirm('Discard unsaved changes?'))this.setState({propertyThresholdDialog:false,propertyLifeDialog:null,propertyEntryDialog:null,propertyRegistryError:''});}},'Cancel'),
                            h('button',{type:'submit',className:'primary',disabled:this.state.busy},this.state.busy?'Saving…':'Save')))));
            return h('div',{className:'section-stack'},this.header('Reference data','Official Division item codes and estimated useful life; blank means pending Division guidance.'),
                h('div',{className:'master-tabs',role:'tablist','aria-label':'Reference data'},...options.map(([v,label])=>h('button',{
                    key:v,type:'button',role:'tab','aria-selected':v==='property_registry',
                    className:'master-tab '+(v==='property_registry'?'selected':''),
                    onClick:()=>this.setState({masterSection:v,masterSearch:'',masterEditorOpen:false},()=>{if(v==='uacs')this.loadUacs(0);})
                },label))),
                this.state.propertyRegistryError?h('p',{className:'ipr-error',role:'alert'},this.state.propertyRegistryError):null,
                this.state.propertyRegistryBusy?h('p',{role:'status'},'Loading Division registry…'):null,
                reg?h('div',{className:'surface ipr-body'},
                    h('div',{className:'section-head'},h('h3',null,'Division Property Number Registry'),
                        h('div',{className:'ipr-head-actions'},h('span',{className:'mini-tag'},String(reg.count)+' Division entries'),
                            canEdit?h('button',{type:'button',className:'primary',onClick:()=>this.openPropertyEntry()},'+ Add registry item'):h('span',{className:'ipr-readonly'},'School access · View only'))),
                    h('p',{className:'body-muted'},'Division-controlled master registry, originally imported from 17 worksheets. HV begins at the effective threshold, inclusive. Schools may search and view entries but cannot change them. Missing codes and useful life remain pending.'),
                    h('p',{className:'body-muted'},'Threshold rules: '+reg.threshold_rules.map(x=>x.effective_from+' → ₱'+(Number(x.cents)/100).toFixed(2)).join(' · ')),
                    h('div',{className:'ipr-threshold'},
                        h('div',null,h('small',null,'Current HV threshold'),h('strong',null,'₱'+Number(reg.threshold).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2})),
                            h('div',{className:'body-muted'},'HV at or above threshold · LV below')),
                        canEdit?h('button',{type:'button',onClick:()=>this.setState({propertyThresholdDialog:true,propertyThresholdValue:reg.threshold,propertyThresholdDate:localToday(),propertyThresholdReason:'',propertyRegistryError:''})},'Change threshold'):null),
                    h('label',{className:'field ipr-preview'},h('span',null,'Optional unit cost (₱) — preview code, not saved to PR'),
                        h('input',{type:'text',inputMode:'decimal',placeholder:'e.g. 5000.00',value:this.state.propertyUnitCost,onChange:e=>this.setState({propertyUnitCost:e.target.value})}),
                        previewValue&&!validPreview?h('small',null,'Enter a non-negative amount with up to 2 decimal places'):previewTier?h('small',null,'Preview tier: '+previewTier):null),
                    h('div',{className:'ipr-controls'},
                        h('label',{className:'field'},h('span',null,'Search official descriptions and codes'),
                            h('input',{type:'search',value:this.state.propertyRegistrySearch,onChange:e=>this.setState({propertyRegistrySearch:e.target.value}),placeholder:'Printer, computer, book, code…'})),
                        h('label',{className:'field'},h('span',null,'Registry section'),h('select',{value:this.state.propertyRegistrySection,onChange:e=>this.setState({propertyRegistrySection:e.target.value})},
                            h('option',{value:''},'All 17 sections'),...sections.map(v=>h('option',{key:v,value:v},v))))),
                    h('p',{className:'body-muted',role:'status'},matches.length+' entries found'+(matches.length>80?' — showing first 80; narrow your search':'')+'.'),
                    h('div',{className:'table-scroll'},h('table',{className:'master-table ipr-table'},
                        h('thead',null,h('tr',null,...['Item / title','HV code','LV / reference code','Preview at unit cost','Useful life','Action'].map(x=>h('th',{key:x},x)))),
                        h('tbody',null,...show.map(r=>h('tr',{key:r.id},
                            h('td',null,h('strong',null,r.description),h('small',{className:'ipr-muted'},r.section),r.detail?h('small',{className:'ipr-muted'},r.detail):null),
                            h('td',null,r.hv_code||'—'),
                            h('td',null,r.lv_code||r.reference_code||'—'),
                            h('td',null,previewTier?(r.mode==='HV_LV'?(previewTier==='HV'?r.hv_code:r.lv_code)||'Pending Division coding':r.reference_code||'Pending Division coding'):'Enter a unit cost'),
                            h('td',null,r.effective_useful_life_months===null?'Pending':(Number(r.effective_useful_life_months)/12)+' years',r.useful_life_overridden?' (edited)':''),
                            h('td',null,canEdit?h('div',{className:'ipr-row-actions'},
                                h('button',{type:'button',onClick:()=>this.openPropertyEntry(r)},'Edit'),
                                h('button',{type:'button',onClick:()=>this.setState({propertyLifeDialog:r,propertyLifeMonths:r.effective_useful_life_months===null?'':String(r.effective_useful_life_months),propertyLifeReason:'',propertyRegistryError:''})},'Useful life')):'View only'))))))):null,
                this.state.propertyThresholdDialog?modal('Change HV/LV threshold',[
                    h('label',{key:'threshold',className:'field'},h('span',null,'HV threshold (₱)'),h('input',{required:true,inputMode:'decimal',value:this.state.propertyThresholdValue,onChange:e=>this.setState({propertyThresholdValue:e.target.value})})),
                    h('label',{key:'date',className:'field'},h('span',null,'Effective date (today or later)'),h('input',{required:true,type:'date',min:localToday(),value:this.state.propertyThresholdDate,onChange:e=>this.setState({propertyThresholdDate:e.target.value})})),
                    h('label',{key:'reason',className:'field'},h('span',null,'Reason / Division authority'),h('textarea',{required:true,value:this.state.propertyThresholdReason,onChange:e=>this.setState({propertyThresholdReason:e.target.value})})),
                    h('p',{key:'hint',className:'body-muted'},'Already-recorded properties are not automatically reclassified. Earlier threshold rules remain in history.')
                ],this.savePropertyThreshold):null,
                this.state.propertyLifeDialog?modal('Edit estimated useful life',[
                    h('p',{key:'title'},this.state.propertyLifeDialog.description),
                    h('label',{key:'months',className:'field'},h('span',null,'Estimated useful life (months; blank = pending)'),
                        h('input',{type:'number',min:'1',max:'1200',step:'1',value:this.state.propertyLifeMonths,onChange:e=>this.setState({propertyLifeMonths:e.target.value})})),
                    h('label',{key:'reason',className:'field'},h('span',null,'Reason / authority'),h('textarea',{required:true,value:this.state.propertyLifeReason,onChange:e=>this.setState({propertyLifeReason:e.target.value})})),
                    h('p',{key:'hint',className:'body-muted'},'This changes a reference suggestion only. Existing assets and financial entries are not rewritten.')
                ],this.savePropertyLife):null,
                this.state.propertyEntryDialog?modal(this.state.propertyEntryDialog==='NEW'?'Add Division registry entry':'Edit Division registry entry',[
                    h('label',{key:'section',className:'field'},h('span',null,'Division category / registry section'),
                        h('input',{required:true,maxLength:160,list:'ipr-sections',value:this.state.propertyEntrySection,onChange:e=>this.setState({propertyEntrySection:e.target.value})}),
                        h('datalist',{id:'ipr-sections'},...sections.map(v=>h('option',{key:v,value:v})))),
                    h('label',{key:'description',className:'field'},h('span',null,'Item / property description'),
                        h('input',{required:true,maxLength:240,value:this.state.propertyEntryDescription,onChange:e=>this.setState({propertyEntryDescription:e.target.value})})),
                    h('label',{key:'mode',className:'field'},h('span',null,'Official code format'),
                        h('select',{value:this.state.propertyEntryMode,onChange:e=>this.setState({propertyEntryMode:e.target.value})},
                            h('option',{value:'HV_LV'},'High value / low value'),h('option',{value:'REFERENCE'},'Reference code (e.g. book)'))),
                    ...(this.state.propertyEntryMode==='HV_LV'?[
                        h('label',{key:'hv',className:'field'},h('span',null,'Official HV code (optional)'),
                            h('input',{maxLength:100,value:this.state.propertyEntryHvCode,onChange:e=>this.setState({propertyEntryHvCode:e.target.value})})),
                        h('label',{key:'lv',className:'field'},h('span',null,'Official LV code (optional)'),
                            h('input',{maxLength:100,value:this.state.propertyEntryLvCode,onChange:e=>this.setState({propertyEntryLvCode:e.target.value})}))]:[
                        h('label',{key:'reference',className:'field'},h('span',null,'Official reference code (optional)'),
                            h('input',{maxLength:100,value:this.state.propertyEntryReferenceCode,onChange:e=>this.setState({propertyEntryReferenceCode:e.target.value})}))]),
                    h('label',{key:'months',className:'field'},h('span',null,'Estimated useful life (months, optional)'),
                        h('input',{type:'number',min:1,max:1200,step:1,value:this.state.propertyEntryMonths,onChange:e=>this.setState({propertyEntryMonths:e.target.value})})),
                    h('label',{key:'detail',className:'field'},h('span',null,'Registry notes / detail (optional)'),
                        h('textarea',{maxLength:300,value:this.state.propertyEntryDetail,onChange:e=>this.setState({propertyEntryDetail:e.target.value})})),
                    h('label',{key:'reason',className:'field'},h('span',null,'Reason / Division authority (required)'),
                        h('textarea',{required:true,maxLength:400,value:this.state.propertyEntryReason,onChange:e=>this.setState({propertyEntryReason:e.target.value})})),
                    h('p',{key:'hint',className:'body-muted'},'Blank Division codes and useful life remain pending. Changes are audited. This does not assign a unique property number or alter existing documents.')
                ],this.savePropertyEntry):null);
        };
        this.classificationView = () => {
            const h=React.createElement;
            const options=[['suppliers','Suppliers'],['items','Item Catalog'],['requesting_units','Requesting Units'],['employees','Employees'],['officials','BAC & Heads'],['travel_signatories','Division Travel Signatories'],['letterhead','Document Letterhead'],['classifications','Division Classifications'],['property_registry','Property Registry'],['units','Units of Measure'],['atcs','Withholding Tax / ATC'],['vat_config','VAT Configuration'],['uacs','UACS Accounts']];
            const canEdit=['SUPER_ADMIN','DIVISION'].includes(this.state.user?.role);
            const cats=this.state.divisionClassifications||[];
            // 'accounts' API returns enabled UACS only; its rows do not include an active flag.
            const accounts=(this.state.accounts||[]).filter(a=>/^(502|104|106|108)\d{7}$/.test(String(a.code)));
            const modal=canEdit&&this.state.classDialogOpen?h('div',{className:'ipr-overlay',role:'presentation',
                    onMouseDown:e=>{if(e.target===e.currentTarget&&!this.state.busy)this.resetClassEdit();}},
                h('section',{className:'ipr-dialog',role:'dialog','aria-modal':'true','aria-label':'Division classification UACS configuration',
                    onKeyDown:e=>{if(e.key==='Escape'&&!this.state.busy){e.preventDefault();this.resetClassEdit();}}},
                    h('div',{className:'section-head'},h('div',null,h('p',{className:'eyebrow'},'REFERENCE DATA / DIVISION CLASSIFICATIONS'),
                        h('h3',null,this.state.classEditCode?'Edit / Map classification':'Add Division classification')),
                        h('button',{type:'button',className:'outline','aria-label':'Close classification editor',disabled:this.state.busy,onClick:this.resetClassEdit},'×')),
                    h('p',{className:'body-muted'},this.state.classAppliesTo==='OTHER_MOOE'
                        ? 'Other MOOE is an expense account reference, not an item category. A Division administrator may allow its approved UACS for procured goods and/or services. This never creates a PR, DV, advance, stock or property record by itself.'
                        : 'A newly created item inherits the selected Division class’s approved default account into an explicit, dated Division item map. Existing items and finalized documents are never rewritten. Account eligibility does not replace Division Accountant review.'),
                    this.state.error?h('p',{className:'ipr-error',role:'alert'},this.state.error):null,
                    !this.state.classEditCode?h('div',{className:'inline-actions'},
                        h('button',{type:'button',className:'outline',onClick:()=>this.startMooeExpensePreset('TRAVEL_LOCAL')},'Use Traveling Expenses – Local (5020101000)'),
                        h('button',{type:'button',className:'outline',onClick:()=>this.startMooeExpensePreset('TRAINING')},'Use Training Expenses (5020201000)')):null,
                    h('form',{onSubmit:this.saveClass,className:'division-class-editor'},
                        h('div',{className:'form-grid'},
                            h('label',{className:'field'},h('span',null,'Classification code *'),h('input',{required:true,maxLength:64,readOnly:Boolean(this.state.classEditCode),value:this.state.classCode,onChange:this.set('classCode'),placeholder:'e.g. ICT_EQUIPMENT_ADM'})),
                            h('label',{className:'field'},h('span',null,'Display name *'),h('input',{required:true,maxLength:120,value:this.state.className,onChange:this.set('className')})),
                            h('label',{className:'field'},h('span',null,'Classification use *'),h('select',{disabled:Boolean(this.state.classEditCode),value:this.state.classAppliesTo,onChange:e=>this.setState({classAppliesTo:e.target.value,classUacs:'',classProcurementTypes:[]})},
                                h('option',{value:'SEMI_EXPENDABLE'},'Division semi-expendable'),
                                h('option',{value:'SUPPLY'},'School-use non-inventoriable consumables'),
                                h('option',{value:'PPE'},'Property, plant & equipment'),
                                h('option',{value:'TEXTBOOK'},'Textbooks'),
                                h('option',{value:'SERVICE'},'Services'),
                                h('option',{value:'OTHER_MOOE'},'Other MOOE expenses (non-inventory)'))),
                            h('label',{className:'field'},h('span',null,this.state.classAppliesTo==='OTHER_MOOE'?'Division-approved expense UACS':'Division-approved default debit UACS (for new items)'),h('select',{value:this.state.classUacs,onChange:this.set('classUacs')},
                                h('option',{value:''},'Not yet mapped — leave pending'),
                                ...(this.state.classUacs&&!accounts.some(a=>a.code===this.state.classUacs)?[h('option',{key:'old',value:this.state.classUacs,disabled:true},this.state.classUacs+' (unavailable — choose an active debit account)')]:[]),
                                ...accounts.filter(a=>this.divisionClassUacsEligible(this.state.classAppliesTo,a.code)).map(a=>h('option',{key:a.code,value:a.code},a.code+' — '+a.account_name)))),
                            h('label',{className:'field'},h('span',null,'Classification status'),h('select',{value:this.state.classActive?'1':'0',onChange:e=>this.setState({classActive:e.target.value==='1'})},
                                h('option',{value:'1'},'Enabled'),h('option',{value:'0'},'Disabled')))),
                        this.state.classAppliesTo==='OTHER_MOOE'?h('div',{className:'field master-wide division-procurement-options'},
                            h('strong',{className:'division-procurement-heading'},'Allow this expense class for new procurement items'),
                            h('p',{className:'hint division-procurement-help'},'Division authorization only. Select the actual purchase type; goods and services retain their respective tax profiles. Reimbursements remain separate.'),
                            h('div',{className:'division-procurement-list'},
                                ...[['SUPPLY','Procured goods / direct-expensed consumables'],['SERVICE','Procured services']].map(([code,label])=>
                                    h('label',{key:code,className:'division-procurement-choice'},
                                        h('input',{type:'checkbox',checked:this.state.classProcurementTypes.includes(code),
                                            onChange:e=>this.setState({classProcurementTypes:e.target.checked
                                                ?[...this.state.classProcurementTypes.filter(x=>x!==code),code]
                                                :this.state.classProcurementTypes.filter(x=>x!==code)})}),
                                        h('span',null,label))))) : null,
                        !accounts.length?h('p',{className:'ipr-error',role:'alert'},'No active debit UACS accounts are available. Review and enable the correct account in UACS Accounts before assigning a suggestion.'):null,
                        h('div',{className:'ipr-actions'},h('button',{type:'button',className:'outline',disabled:this.state.busy,onClick:this.resetClassEdit},'Cancel'),
                            h('button',{type:'submit',className:'primary',disabled:this.state.busy||(this.state.classUacs!==''&&!accounts.some(a=>a.code===this.state.classUacs&&this.divisionClassUacsEligible(this.state.classAppliesTo,a.code)))},this.state.busy?'Saving…':'Save Classification')),
                        h('p',{className:'hint'},this.state.classAppliesTo==='OTHER_MOOE'
                            ? 'By default this class is reference-only. Selecting a procurement type permits new catalog items to inherit its UACS only after Division approval. The item remains Goods or Service and follows the normal PR → RFQ → PO → IAR → DV flow. Advances and reimbursements remain separate.'
                            : 'Approved defaults become explicit Division item mappings for new items of this category. Old items are not automatically backfilled. A pending class may be saved without a code, but cannot be used to create an item.')),
                    this.state.classAppliesTo==='OTHER_MOOE'?null:h('div',{className:'ipr-actions'},h('button',{type:'button',className:'outline',disabled:this.state.busy,onClick:this.openItemUacsFromClass},'Open Item Catalog UACS mappings')))):null;
            return h('div',{className:'section-stack'},this.header('Reference data','Division-approved item classifications and separately managed non-inventory MOOE expense accounts.'),
                h('div',{className:'master-tabs',role:'tablist','aria-label':'Reference data'},...options.map(([v,label])=>h('button',{
                    key:v,type:'button',role:'tab','aria-selected':v==='classifications',className:'master-tab '+(v==='classifications'?'selected':''),
                    onClick:()=>this.setState({masterSection:v,masterSearch:'',masterEditorOpen:false,classDialogOpen:false},()=>{if(v==='uacs')this.loadUacs(0);})},label))),
                h('div',{className:'surface'},
                    h('div',{className:'section-head'},h('div',null,h('h3',null,'Division classifications'),h('span',{className:'mini-tag'},this.state.divisionName||'Division')),
                        canEdit?h('button',{type:'button',className:'primary',onClick:()=>{this.resetClassEdit();this.setState({classDialogOpen:true,error:'',success:''});}},'+ Add classification'):null),
                    h('p',{className:'body-muted'},'Other MOOE expense references are normally reference-only. The Division can explicitly authorize approved Other MOOE accounts for NEW procured goods/services in both the Item Catalog and PR quick-add. No reimbursement is converted to procurement; existing items and documents remain unchanged.'),
                    h('div',{className:'table-scroll'},h('table',{className:'master-table'},
                        h('thead',null,h('tr',null,...['Code','Classification','Applies to','Default / suggested UACS','Status','Action'].map(t=>h('th',{key:t},t)))),
                        h('tbody',null,...cats.map(c=>h('tr',{key:c.code},
                            h('td',null,c.code),h('td',null,c.name),h('td',null,this.divisionClassTypeName(c.applies_to||'SEMI_EXPENDABLE')+
                                (c.applies_to==='OTHER_MOOE'&&c.procurement_item_types?.length
                                    ?' · Procured: '+c.procurement_item_types.join(', '):'')),h('td',null,c.default_uacs_code||'Not mapped'),
                            h('td',null,c.active?'Enabled':'Disabled'),h('td',null,canEdit?h('button',{type:'button',className:'text-btn',disabled:this.state.busy,onClick:()=>this.startClassEdit(c)},'Edit / Map'):'Read only'))),
                            !cats.length?h('tr',{key:'empty'},h('td',{colSpan:6,className:'empty'},'No classifications could be loaded for this workspace')):null))),
                    h('div',{className:'inline-actions'},h('p',{className:'hint'},'New items inherit the chosen Division class’s approved default UACS. Older items still require individually reviewed mapping at the transaction date.'),
                        h('button',{type:'button',className:'outline',onClick:this.openItemUacsFromClass},'Map catalog items to UACS'))),
                modal);
        };
        this.vatConfigurationView = () => {
            const h=React.createElement;
            const v=this.state.vatSettings;
            const admin=['DIVISION','SUPER_ADMIN'].includes(this.state.user?.role);
            const options=[['suppliers','Suppliers'],['items','Item Catalog'],['requesting_units','Requesting Units'],['employees','Employees'],['officials','BAC & Heads'],['travel_signatories','Division Travel Signatories'],['letterhead','Document Letterhead'],['classifications','Division Classifications'],
                ['property_registry','Property Registry'],['units','Units of Measure'],['atcs','Withholding Tax / ATC'],
                ['vat_config','VAT Configuration'],['uacs','UACS Accounts']];
            return h('div',{className:'section-stack'},this.header('Reference data','Division VAT rates and supplier withholding references.'),
                h('div',{className:'master-tabs',role:'tablist','aria-label':'Reference data'},...options.map(([id,label])=>
                    h('button',{key:id,type:'button',role:'tab','aria-selected':id==='vat_config',
                        className:'master-tab '+(id==='vat_config'?'selected':''),
                        onClick:()=>this.setState({masterSection:id,masterEditorOpen:false},()=>{if(id==='uacs')this.loadUacs(0);})},label))),
                h('section',{className:'surface'},h('div',{className:'section-head'},h('div',null,
                    h('h3',null,'Standard VAT Configuration'),
                    h('p',{className:'body-muted'},'Division-wide standard VAT rate for VAT-registered suppliers. Separate from VAT withholding and EWT ATCs.')),
                    h('button',{type:'button',className:'outline',disabled:this.state.vatSettingsLoading,
                        onClick:this.loadVatSettings},this.state.vatSettingsLoading?'Loading…':'Refresh')),
                    h('div',{className:'dv-detail-grid'},
                        h('div',{className:'dv-readonly'},h('span',null,'Effective standard VAT rate'),
                            h('strong',null,(v?.current?.rate_percent||'12.00')+'%')),
                        h('div',{className:'dv-readonly'},h('span',null,'Source'),
                            h('strong',null,v?.current?.system_default?'System default · not yet adopted by Division':v?.current?.effective_from?'Division setting · effective '+v.current.effective_from:'Loading…'))),
                    h('p',{className:'hint'},'For a VAT-inclusive PO: VAT component = payable gross × VAT rate / (100 + VAT rate). Withholding tax uses the supplier’s separate ATCs and effective rates. Invoice evidence must be checked; special VAT treatment needs an explicit documented exception.'),
                    !v?h('p',{className:'hint'},'Loading VAT configuration…'):null),
                admin?h('section',{className:'surface'},h('h3',null,'Add an effective-dated VAT rate'),
                    h('p',{className:'body-muted'},'New settings are audited and effective-dated. Earlier finalized DVs are never recalculated.'),
                    h('form',{onSubmit:this.saveVatRate},h('div',{className:'form-grid'},
                        h('label',{className:'field'},h('span',null,'Standard VAT rate (%) *'),
                            h('input',{required:true,type:'number',min:'0.01',max:'25',step:'0.01',value:this.state.vatRateInput,onChange:this.set('vatRateInput')})),
                        h('label',{className:'field'},h('span',null,'Effective from *'),
                            h('input',{required:true,type:'date',value:this.state.vatRateDate,onChange:this.set('vatRateDate')})),
                        h('label',{className:'field'},h('span',null,'Authority / regulation *'),
                            h('input',{required:true,maxLength:255,value:this.state.vatRateAuthority,onChange:this.set('vatRateAuthority'),placeholder:'Verified VAT rate authority'})),
                        h('label',{className:'field'},h('span',null,'Reason / supporting reference *'),
                            h('textarea',{required:true,rows:2,maxLength:500,value:this.state.vatRateReason,onChange:this.set('vatRateReason'),placeholder:'Why this rate and effective date are approved'}))),
                        h('button',{type:'submit',className:'primary',disabled:this.state.busy||this.state.vatSettingsLoading||!v},'Save VAT rate version'))):
                    h('p',{className:'hint'},'Read-only. Division and Super Administrators maintain the VAT rate.'),
                h('section',{className:'surface'},h('h3',null,'VAT Rate History'),
                    h('div',{className:'table-scroll'},h('table',{className:'master-table'},
                        h('thead',null,h('tr',null,...['Rate','Effective from','Authority','Reason / note'].map(x=>h('th',{key:x},x)))),
                        h('tbody',null,...(v?.history||[]).map((r,i)=>h('tr',{key:r.version_id},
                            h('td',null,r.rate_percent+'%'),h('td',null,r.effective_from),h('td',null,r.authority||'—'),h('td',null,r.reason||'—'))),
                            !(v?.history||[]).length?h('tr',{key:'empty'},h('td',{colSpan:4},'No Division rate has been saved yet. System standard VAT default: 12.00%.')):null)))));
        };
        // MOOE assignments are Division-owned, school-specific, and read-only to school users.
        this.loadDivisionMooe = async () => {
            const org=String(this.state.organization_id||'');
            if(!org)return;
            const token=(this.mooeLoadToken||0)+1;
            this.mooeLoadToken=token;
            this.setState({mooeLoading:true,mooeError:''});
            try{
                const data=await this.get('division.mooe.assignments',{organization_id:org});
                if(token!==this.mooeLoadToken||org!==String(this.state.organization_id))return;
                const schools=data.schools||[];
                const selected=schools.find(x=>String(x.id)===String(this.state.mooeSchoolId))||schools[0]||null;
                this.setState({mooeData:data,mooeLoading:false,mooeSchoolId:selected?String(selected.id):'',
                    mooeEmployeeId:selected?.current?.employee_id?String(selected.current.employee_id):'',
                    mooeDate:localToday(),mooeHistoricalConfirmed:false,mooeAppointmentReference:'',mooeNotice:''});
            }catch(e){if(token===this.mooeLoadToken&&org===String(this.state.organization_id))
                this.setState({mooeError:e.message||'Division school assignments could not load.',mooeLoading:false});}
        };
        this.saveDivisionMooe = async e => {
            e.preventDefault();
            const s=this.state,org=String(s.organization_id),school=(s.mooeData?.schools||[]).find(x=>String(x.id)===String(s.mooeSchoolId));
            if(!s.mooeData?.can_manage||!school||!s.mooeEmployeeId||s.mooeBusy||s.busy){
                this.setState({mooeError:'Select a school and a verified Division employee before saving.'});return;
            }
            this.setState({mooeBusy:true,mooeError:'',mooeNotice:''});
            try{
                await this.api('division.mooe.assignment.save',{organization_id:Number(org),school_id:Number(school.id),
                    employee_id:Number(s.mooeEmployeeId),effective_from:s.mooeDate,expected_version:Number(school.latest_version_id||0),
                    historical_appointment_attested:!!s.mooeHistoricalConfirmed,
                    appointment_reference:s.mooeAppointmentReference.trim()});
                if(org!==String(this.state.organization_id))return;
                await this.loadDivisionMooe();
                this.setState({mooeNotice:'Assignment saved with its effective date and audit history.'});
            }catch(e){if(org===String(this.state.organization_id))this.setState({mooeError:e.message||'Unable to save assignment.'});}
            finally{if(org===String(this.state.organization_id))this.setState({mooeBusy:false});}
        };
        this.createMooeViewer = async e => {
            e.preventDefault();
            const s=this.state,org=String(s.organization_id||'');
            if(!s.mooeData?.can_manage||!s.mooeEmployeeId||s.mooeViewerBusy)return;
            this.setState({mooeViewerBusy:true,mooeError:'',mooeNotice:''});
            try{
                await this.api('organization.mooe.viewer.create',{
                    organization_id:Number(org),employee_id:Number(s.mooeEmployeeId),
                    email:s.mooeViewerEmail.trim(),password:s.mooeViewerPassword
                });
                if(org!==String(this.state.organization_id))return;
                await this.loadDivisionMooe();
                this.setState({mooeViewerEmail:'',mooeViewerPassword:'',
                    mooeNotice:'School-scoped read-only account provisioned. Share the password securely; it is never displayed again.'});
            }catch(error){if(org===String(this.state.organization_id))this.setState({mooeError:error.message||'Unable to provision account.'});}
            finally{if(org===String(this.state.organization_id))this.setState({mooeViewerBusy:false});}
        };
        this.divisionMooeView = () => {
            const h=React.createElement,s=this.state,d=s.mooeData,schools=d?.schools||[];
            const school=schools.find(x=>String(x.id)===String(s.mooeSchoolId))||schools[0]||null;
            const canEdit=!!d?.can_manage && !s.workspaceLoading;
            const staff=(d?.division_employees||[]).filter(x=>x.active);
            const staffSelected=school?.current;
            return h('div',{className:'section-stack'},this.header('Division MOOE Assignments',
                'Configure the Division accounting officer responsible for posting each school’s Appendix 64 RSMI.'),
                h('section',{className:'surface'},
                    h('div',{className:'section-head'},h('h3',null,'Assigned Division MOOE In-Charge'),
                        h('button',{type:'button',className:'outline',disabled:s.mooeLoading||s.mooeBusy,onClick:this.loadDivisionMooe},'Refresh')),
                    s.mooeLoading?h('p',{className:'hint',role:'status'},'Loading Division MOOE assignments…'):null,
                    s.mooeError?h('p',{className:'error',role:'alert'},s.mooeError):null,
                    s.mooeNotice?h('p',{className:'success',role:'status'},s.mooeNotice):null,
                    !s.mooeLoading&&!schools.length?h('p',{className:'hint'},'No schools are available for this workspace. Check the organization hierarchy.'):null,
                    schools.length?h('label',{className:'field',style:{maxWidth:'480px'}},h('span',null,'School'),
                        h('select',{value:String(school?.id||''),onChange:e=>{
                            const next=schools.find(x=>String(x.id)===e.target.value);
                            this.setState({mooeSchoolId:e.target.value,mooeEmployeeId:next?.current?.employee_id?String(next.current.employee_id):'',
                                mooeDate:localToday(),mooeHistoricalConfirmed:false,mooeAppointmentReference:'',mooeError:'',mooeNotice:''});}},
                            ...schools.map(x=>h('option',{key:x.id,value:String(x.id)},x.name)))):null,
                    school?h('div',{className:'dv-detail-grid',style:{marginTop:'12px'}},
                        h('div',{className:'dv-readonly'},h('span',null,'Current Division MOOE in-charge'),
                            h('strong',null,staffSelected?staffSelected.name:'Not configured')),
                        h('div',{className:'dv-readonly'},h('span',null,'Official designation'),
                            h('strong',null,staffSelected?.designation||'—')),
                        h('div',{className:'dv-readonly'},h('span',null,'Effective from'),
                            h('strong',null,staffSelected?.effective_from||'—'))):null,
                    h('p',{className:'hint'},'On RSMI Appendix 64, this officer will be identified in “Posted by”. '
                        +'Selecting an officer is not a signature or an accounting posting. School workspaces are read-only.')),
                canEdit&&school?h('section',{className:'surface'},h('h3',null,'Update school assignment'),
                    h('form',{onSubmit:this.saveDivisionMooe},h('div',{className:'form-grid'},
                        h('label',{className:'field'},h('span',null,'Division employee *'),
                            h('select',{required:true,value:s.mooeEmployeeId,onChange:this.set('mooeEmployeeId')},
                                h('option',{value:''},'Choose an active Division employee'),
                                ...staff.map(x=>h('option',{key:x.employee_id,value:String(x.employee_id)},x.full_name+' · '+x.designation)))),
                        h('label',{className:'field'},h('span',null,'Assignment effective date *'),
                            h('input',{type:'date',required:true,max:localToday(),value:s.mooeDate,onChange:this.set('mooeDate')}))),
                        (staff.find(x=>String(x.employee_id)===String(s.mooeEmployeeId))?.effective_from||'')>s.mooeDate?
                            h('div',{className:'surface',style:{padding:'12px',marginBottom:'10px'}},
                                h('p',{className:'hint'},'The documented appointment predates this employee’s IPDAMS Division assignment entry. Verify the original appointment; do not change employment dates to make the form pass.'),
                                h('label',{className:'field'},h('input',{type:'checkbox',checked:!!s.mooeHistoricalConfirmed,
                                    onChange:e=>this.setState({mooeHistoricalConfirmed:e.target.checked})}),
                                    h('span',null,'Official Division assignment confirmed')),
                                h('label',{className:'field'},h('span',null,'Assignment memorandum / reference *'),
                                    h('input',{required:true,maxLength:300,value:s.mooeAppointmentReference,
                                        onChange:this.set('mooeAppointmentReference'),placeholder:'Official assignment document and date'}))):null,
                        h('p',{className:'hint'},'Use the date supported by the actual Division assignment. A new effective date must follow the preceding record. Prior assignments remain in the audit history.'),
                        h('button',{className:'primary',type:'submit',disabled:s.mooeBusy||s.busy||!s.mooeEmployeeId},s.mooeBusy?'Saving…':'Save assignment'))):null,
                canEdit&&school?h('section',{className:'surface'},h('h3',null,'Read-only Division MOOE login'),
                    h('p',{className:'hint'},'The employee must first be assigned to at least one school. Their login can switch ONLY between their currently assigned schools, view school records and print existing documents. No transactions or editing.'),
                    h('form',{onSubmit:this.createMooeViewer},
                        h('div',{className:'form-grid'},
                            h('label',{className:'field'},h('span',null,'Login email'),
                                h('input',{type:'email',required:true,maxLength:190,value:s.mooeViewerEmail,onChange:this.set('mooeViewerEmail')})),
                            h('label',{className:'field'},h('span',null,'Initial password (16+ characters)'),
                                h('input',{type:'password',required:true,minLength:16,maxLength:256,
                                    autoComplete:'new-password',value:s.mooeViewerPassword,onChange:this.set('mooeViewerPassword')}))),
                        h('button',{type:'submit',className:'primary',disabled:s.mooeViewerBusy||s.mooeBusy||!s.mooeEmployeeId},
                            s.mooeViewerBusy?'Creating account…':'Create restricted read-only login')),
                    (d?.viewer_accounts||[]).length?h('div',{className:'table-scroll'},h('table',{className:'master-table'},
                        h('thead',null,h('tr',null,...['Employee','Login email','Status'].map(x=>h('th',{key:x},x)))),
                        h('tbody',null,...(d.viewer_accounts||[]).map(x=>h('tr',{key:x.user_id},
                            h('td',null,(staff.find(e=>e.employee_id===x.employee_id)?.full_name)||'Division employee #'+x.employee_id),
                            h('td',null,x.email),h('td',null,x.active?'Active':'Disabled')))))):null):null,
                school?.history?.length?h('section',{className:'surface'},h('h3',null,'Assignment history'),
                    h('div',{className:'table-scroll'},h('table',{className:'master-table'},
                        h('thead',null,h('tr',null,...['Effective from','Division officer','Official designation'].map(x=>h('th',{key:x},x)))),
                        h('tbody',null,...school.history.map(x=>h('tr',{key:x.version_id},
                            h('td',null,x.effective_from),h('td',null,x.name),h('td',null,x.designation))))))):null);
        };
        // One consistent navigation hierarchy replaces the thirteen scattered reference tab bars.
        // Keep the existing reference components and their authorization/edit handlers unchanged.
        this.referenceNavigation = () => {
            const h=React.createElement;
            const groups=[
                {key:'catalog',label:'Catalog & Suppliers',sections:[
                    ['suppliers','Suppliers'],['items','Item Catalog'],['requesting_units','Requesting Units'],['units','Units of Measure']]},
                {key:'people',label:'People & Documents',sections:[
                    ['employees','Employees'],['officials','BAC & Heads'],['travel_signatories','Division Signatories'],['division_mooe','Division MOOE Assignments'],['letterhead','Document Letterhead']]},
                {key:'classification',label:'Classification & Tax',sections:[
                    ['classifications','Item Classifications'],['property_registry','Property Registry'],['uacs','UACS Accounts'],
                    ['atcs','Withholding Tax / ATC'],['vat_config','VAT Configuration']]}
            ];
            const current=['funds','banks'].includes(this.state.masterSection)?'suppliers':this.state.masterSection;
            const active=groups.find(group=>group.sections.some(([key])=>key===current))||groups[0];
            const changeSection=(section)=>{
                if(section===current)return;
                if(this.state.busy)return;
                if((this.state.masterEditorOpen||this.state.staffAddOpen||this.state.classDialogOpen||this.state.travelBrandingPending)
                    && !window.confirm('Leave the current reference entry form? Unsaved changes will be discarded.'))return;
                this.staffIdentityRequest=(this.staffIdentityRequest||0)+1;
                this.setState({masterSection:section,masterSearch:'',masterEditorOpen:false,staffAddOpen:false,
                    staffAddOrg:'',staffError:'',staffSavedMessage:'',staffRenameId:'',staffProfileId:'',staffProfileBusy:false,
                    travelBrandingError:'',travelBrandingPending:null,travelSignatoriesError:'',classDialogOpen:false,
                    error:'',success:''},()=>{if(section==='uacs')this.loadUacs(0);});
            };
            const keyNav=(e,keys,currentKey,select,elementPrefix)=>{
                const index=keys.indexOf(currentKey);
                const target=e.key==='ArrowRight'?keys[(index+1)%keys.length]:
                    e.key==='ArrowLeft'?keys[(index+keys.length-1)%keys.length]:
                    e.key==='Home'?keys[0]:e.key==='End'?keys[keys.length-1]:null;
                if(target!==null){e.preventDefault();select(target);
                    // Focus the target only when the selection changed; preserves keyboard tab semantics.
                    if(target===currentKey||!this.state.masterEditorOpen)
                        window.requestAnimationFrame?.(()=>document.getElementById(elementPrefix+target)?.focus());
                }
            };
            return h('section',{className:'ref-nav-shell','aria-label':'Reference data navigation'},
                h('div',{className:'ref-nav-caption'},'REFERENCE DATA',h('span',null,'Organized by task · choose a category, then a record type')),
                h('nav',{className:'ref-category-tabs',role:'tablist','aria-label':'Reference data categories'},
                    ...groups.map(group=>h('button',{key:group.key,type:'button',id:'ref-category-'+group.key,role:'tab',
                        className:'ref-category-tab'+(active.key===group.key?' active':''),
                        'aria-selected':active.key===group.key,tabIndex:active.key===group.key?0:-1,
                        onClick:()=>{if(active.key!==group.key)changeSection(group.sections[0][0]);},
                        onKeyDown:e=>keyNav(e,groups.map(g=>g.key),active.key,key=>changeSection(groups.find(g=>g.key===key).sections[0][0]),'ref-category-')},group.label))),
                h('nav',{className:'ref-section-tabs',role:'tablist','aria-label':active.label+' reference types'},
                    ...active.sections.map(([key,label])=>h('button',{key,type:'button',id:'ref-section-'+key,role:'tab',
                        className:'ref-section-tab'+(current===key?' active':''),
                        'aria-selected':current===key,tabIndex:current===key?0:-1,
                        onClick:()=>changeSection(key),
                        onKeyDown:e=>keyNav(e,active.sections.map(pair=>pair[0]),current,changeSection,'ref-section-')},label))));
        };
        this.masterView = (context='reference') => {
            const h=React.createElement;
            const finance=context==='finance';
            const financeSection=this.state.fundSection||'overview';
            const section=finance ? (financeSection==='banks'?'banks':'funds') : (['funds','banks'].includes(this.state.masterSection)?'suppliers':this.state.masterSection);
            if(section==='requesting_units')return this.requestingUnitsView();
            if(section==='division_mooe')return this.divisionMooeView();
            if(section==='travel_signatories')return this.travelSignatoriesView();
            if(section==='letterhead')return this.travelBrandingView();
            if(section==='employees'||section==='officials')return this.staffDirectoryView();
            if(section==='atcs')return this.atcView();
            if(section==='vat_config')return this.vatConfigurationView();
            if(section==='uacs')return this.uacsView();
            if(section==='classifications')return this.classificationView();
            if(section==='property_registry')return this.propertyRegistryView();
            const isAdmin=['SUPER_ADMIN','DIVISION'].includes(this.state.user?.role);
            const options=[['suppliers','Suppliers'],['items','Item Catalog'],['requesting_units','Requesting Units'],['employees','Employees'],['officials','BAC & Heads'],['travel_signatories','Division Travel Signatories'],['letterhead','Document Letterhead'],['classifications','Division Classifications'],['property_registry','Property Registry'],['units','Units of Measure'],['atcs','Withholding Tax / ATC'],['vat_config','VAT Configuration'],['uacs','UACS Accounts']];
            const financeOptions=[['overview','Overview'],['funds','Sources of Funds'],['banks','Bank Accounts'],['downloads','D.O. Bank Downloads'],['allocations','Allocation & Obligations']];
            const financeTabs=h('div',{className:'master-tabs',role:'tablist','aria-label':'Fund management'},
                ...financeOptions.map(([v,title])=>h('button',{key:v,type:'button',role:'tab',
                    'aria-selected':financeSection===v,className:'master-tab '+(financeSection===v?'selected':''),disabled:this.state.busy,
                    onClick:()=>{
                        if((this.state.bank_download_open||this.state.fund_allocation_open||this.state.masterEditorOpen||this.state.sourceDialog||this.state.policyDialog) &&
                            !window.confirm('Leave this form? Unsaved entries will be discarded.'))return;
                        this.setState({fundSection:v,masterSearch:'',masterEditorOpen:false,bank_download_open:false,fund_allocation_open:false,error:'',success:''});
                    }},title)));
            if(finance&&financeSection==='overview')return h('div',{className:'section-stack'},
                this.header('Fund management','Manage school funding authority and linked D.O. bank receipts.'),
                financeTabs,
                this.table('School funds',['name','fiscal_year','authorized_allocation','outstanding_obligations','finalized_dv_gross','available_allocation'],this.state.lists.funds||[]),
                h('p',{className:'hint'},'Available allocation includes bank-verified, effective-date allotments and authorized adjustments, less outstanding obligations and finalized DV gross. Historical unlinked allotments cannot fund new DVs. Check release does not double-charge the DV.'),
                h('p',{className:'body-muted'},'For new D.O. credits and bank accounts, use the tabs above. Checks and future statement reconciliation remain under Cash & Bank.'));
            const rows=section==='suppliers'?this.state.lists.suppliers||[]:section==='items'?this.state.lists.all_items||[]:section==='funds'?this.state.lists.funds||[]:section==='banks'?this.state.lists.banks||[]:section==='units'?this.state.lists.units||[]:this.state.lists.atcs||[];
            const filtered=rows.filter(r=>{
                const content=Object.values(r).map(v=>String(v??'')).join(' ') + (section==='items'?' '+this.classificationLabel(r.subcategory):'');
                return content.toLowerCase().includes(this.state.masterSearch.toLowerCase());
            });
            // Each school owns its bank profiles. Keep all OTHER finance and
            // Division reference editors on their existing stricter roles.
            const canEdit=['items','banks'].includes(section)?this.canOperateWorkspace():isAdmin;
            const heading={suppliers:'Supplier Directory',items:'Item Catalog & Classification',funds:'School Sources of Funds',banks:'Bank Accounts',units:'Units of Measure',atcs:'Withholding Tax ATC Configuration'}[section];
            const input=(key,label,placeholder='',type='text')=>this.field(key,label,placeholder,type);
            const pick=(key,label,choices)=>h('label',{className:'field'},h('span',null,label),h('select',{value:this.state[key],onChange:this.set(key)},...choices.map(([v,name])=>h('option',{key:v,value:v},name))));
            const vatOptions=[['UNKNOWN','Not yet verified'],['VAT','VAT-registered'],['NON_VAT','Non-VAT']];
            const supplierBusiness=this.state.sup_commercial==='COMMERCIAL'?'CORPORATE':this.state.sup_commercial;
            const supplierProfileCards=h('fieldset',{className:'supplier-tax-profiles master-wide'},
                h('legend',null,'Supplier Tax Profiles — Six Categories'),
                h('p',{className:'body-muted'},'Add only the categories this supplier actually uses. Each profile keeps independent VAT Withholding and EWT ATC selections. VAT registration remains a separate supplier-level field.'),
                h('div',{className:'supplier-profile-toolbar'},
                    ...supplierProfileTypes.map(type=>h('button',{type:'button',key:type,className:'outline',
                        disabled:this.state.sup_profiles.some(p=>p.supply_type===type),
                        onClick:()=>this.addSupplierTaxProfile(type)},'+ '+this.profileLabel(type)))),
                !this.state.sup_profiles.length?h('p',{className:'hint'},'No tax profile added yet. You may save the supplier now and configure profiles later.'):null,
                ...this.state.sup_profiles.map(profile=>{
                    const type=profile.supply_type;
                    const available=(this.state.lists.atcs||[]).filter(a=>{
                        const eligible=this.atcProfileNames(a).length===1 && this.atcSupportsSupplierProfile(a,type) &&
                            ((a.business_type||'BOTH')==='BOTH'||a.business_type===supplierBusiness);
                        const linked=profile.vat_atc_codes.includes(a.code)||profile.ewt_atc_codes.includes(a.code);
                        return ((eligible&&Number(a.active))||linked) && ['VAT','EWT'].includes(a.tax_kind);
                    });
                    return h('div',{key:type,className:'supplier-profile-card'},
                        h('div',{className:'supplier-profile-heading'},h('div',null,h('h4',null,this.profileLabel(type)+' Tax Profile'),
                            h('p',{className:'body-muted'},'ATCs here apply only when this '+this.profileLabel(type).toLowerCase()+' profile is selected for the transaction.')),
                            h('button',{type:'button',className:'outline',onClick:()=>this.removeSupplierTaxProfile(type)},'Remove Profile')),
                        ...[['VAT','VAT Withholding'],['EWT','Expanded Withholding Tax (EWT)']].map(([kind,title])=>{
                            const matches=available.filter(a=>a.tax_kind===kind);
                            const chosen=kind==='VAT'?profile.vat_atc_codes:profile.ewt_atc_codes;
                            return h('div',{className:'supplier-profile-tax-group',key:kind},h('h5',null,title),
                                !matches.length?h('p',{className:'hint'},'No '+kind+' ATCs are configured for this supplier and '+this.profileLabel(type).toLowerCase()+' profile.'):null,
                                ...matches.map(a=>h('label',{className:'supplier-atc-choice',key:a.code},
                                    h('input',{type:'checkbox',checked:chosen.includes(a.code),disabled:!Number(a.active)&&!chosen.includes(a.code),
                                        onChange:e=>this.toggleSupplierProfileAtc(type,kind,a.code,e.target.checked)}),
                                    h('span',null,h('strong',null,a.code),' — '+a.description+(Number(a.active)?'':' (inactive; preserved)')))));
                        }));
                }),
                this.state.sup_legacy_atcs.length?h('div',{className:'hint'},
                    'Unclassified legacy ATCs preserved until reviewed in ATC maintenance: '+this.state.sup_legacy_atcs.join(', ')):null);
            const supplierForm=h('div',{className:'form-grid'},
                input('sup_name','Registered business name *','As shown on the business registration'),
                this.state.sup_commercial==='INDIVIDUAL'?input('sup_proprietor','Proprietor full legal name *','As officially registered'):null,
                input('sup_tax_id','TIN / tax ID','As shown on registration'),
                input('sup_zip','ZIP / Postal code','e.g. 6300'),
                pick('sup_commercial','Business Classification',[['UNSPECIFIED','Not yet verified'],['COMMERCIAL','Corporate / Commercial'],['INDIVIDUAL','Individual']]),
                pick('sup_vat_status','VAT Registration',[['UNKNOWN','Not yet verified'],['VAT','VAT-registered'],['NON_VAT','Non-VAT']]),
                h('label',{className:'field master-wide'},h('span',null,'Registered address'),h('textarea',{value:this.state.sup_address,onChange:this.set('sup_address'),rows:2,maxLength:2000})),
                supplierProfileCards);
            const unitForm=h('div',{className:'form-grid'},input('unit_code','Unit code *','REAM / PCS'),input('unit_name','Unit name *','Ream / Piece'));
            const itemForm=h('div',{className:'form-grid'},
                this.state.item_edit_id
                    ? h('div',{className:'field master-wide'},h('span',null,'Existing catalog item'),h('strong',null,this.state.item_name),h('span',{className:'body-muted'},'Main classification: Semi-expendable · Unit: '+((this.state.lists.units||[]).find(u=>String(u.id)===this.state.item_unit_id)?.code||'—')))
                    : input('item_name','Standard item name *','e.g. A4 Bond Paper'),
                !this.state.item_edit_id?this.select('item_unit_id','Unit of measure *',this.state.lists.units||[]):null,
                !this.state.item_edit_id?pick('item_category','Accounting classification *',[['SUPPLY','Consumable supply'],['SEMI_EXPENDABLE','Semi-expendable'],['PPE','PPE'],['TEXTBOOK','Textbook'],['SERVICE','Service']]):null,
                this.state.item_category==='SEMI_EXPENDABLE'?pick('item_subcategory','Division-approved semi-expendable subclass *',[['','Select the Division subcategory'],...this.state.divisionClassifications.filter(c=>(c.applies_to||'SEMI_EXPENDABLE')==='SEMI_EXPENDABLE'&&(c.active||c.code===this.state.item_subcategory)).map(c=>[c.code,c.name+(c.active?(this.divisionClassUacsEligible('SEMI_EXPENDABLE',c.default_uacs_code)?' · UACS '+c.default_uacs_code:' · UACS approval required'):' (inactive)')])]):null,
                this.state.item_category==='SEMI_EXPENDABLE'?h('p',{className:'body-muted master-wide'},'The chosen Division subclass also supplies the default UACS for this NEW item. Parent category remains Semi-expendable for ICS and accountability.'):null,
                !this.state.item_edit_id&&this.state.item_category!=='SEMI_EXPENDABLE'?pick('item_school_class','Division-approved '+this.divisionClassTypeName(this.state.item_category)+' class *',[['','Choose approved class'],...this.state.divisionClassifications.filter(c=>this.procurementClassMatches(c,this.state.item_category)).map(c=>[c.code,c.name+(c.applies_to==='OTHER_MOOE'?' · Other MOOE':'')+' · UACS '+c.default_uacs_code])]):null,
                !this.state.item_edit_id?h('p',{className:'hint master-wide'},
                    this.state.divisionClassifications.some(c=>this.procurementClassMatches(c,this.state.item_category))
                    ?'An approved Division class determines the new item’s UACS. The explicit item mapping is saved with an effective date; existing items and older vouchers stay unchanged.'
                    :'No ready Division classification for '+this.divisionClassTypeName(this.state.item_category)+'. Ask a Division administrator to approve an eligible active UACS first.'):null);
            const fundForm=h('div',{className:'form-grid'},
                h('label',{className:'field'},h('span',null,'Division-approved source of fund *'),h('select',{required:true,value:this.state.fund_source_code,onChange:this.set('fund_source_code')},
                    h('option',{value:''},'Select master source'),...((this.state.fundPolicies?.sources||[]).filter(s=>s.active).map(s=>h('option',{key:s.code,value:s.code},s.name+' ('+s.code+')'))))),
                input('fund_fiscal_year','Fiscal year *','2026','number'));
            const bankForm=h('div',{className:'form-grid'},
                input('bank_name','Bank name *','Official bank name'),
                input('bank_label','Account label *','School operating account'),
                input('bank_mask','Masked account reference *','****1234'),
                h('p',{className:'hint master-wide'},
                    'Register only an approved account belonging to this school or office. Enter a MASKED account reference, not a complete account number. This register does not receive funds, issue checks, or change the bank balance.'));
            const fundAllocationEditor=finance&&financeSection==='allocations'?h('div',{className:'surface reference-editor'},
                h('div',{className:'section-head'},h('h3',null,'Fund allocation controls'),
                    isAdmin?h('button',{className:'outline',type:'button',onClick:()=>this.setState({fund_allocation_open:true})},'Record allocation / obligation'):null),
                h('p',{className:'hint'},'Allocation becomes spendable only after its D.O. bank download is linked and verified. Earlier unmatched allotments remain in the journal and are not included in the spendable amount. Manual obligations remain separate from bank cash.'),
                this.state.fund_allocation_open&&isAdmin?h('div',{className:'ipr-overlay',role:'presentation'},
                    h('div',{className:'ipr-dialog',role:'dialog','aria-modal':'true','aria-label':'Record source-of-fund ledger action'},
                        h('h3',null,'Record source-of-fund ledger action'),
                        h('form',{onSubmit:this.saveFundAllocation},
                            h('label',{className:'field'},h('span',null,'Action'),h('select',{value:this.state.fund_allocation_type,onChange:this.set('fund_allocation_type')},
                                h('option',{value:'OBLIGATION'},'Reserve an obligation'),
                                h('option',{value:'OBLIGATION_REVERSAL'},'Settle/reverse existing obligation'))),
                            h('label',{className:'field'},h('span',null,'Source of fund *'),h('select',{required:true,value:this.state.fund_allocation_id,onChange:this.set('fund_allocation_id')},
                                h('option',{value:''},'Select fund'),...rows.map(f=>h('option',{key:f.id,value:String(f.id)},f.fiscal_year+' · '+f.name+' ('+f.source_code+')')))),
                            h('label',{className:'field'},h('span',null,'Amount (₱) *'),
                                h('input',{required:true,inputMode:'decimal',pattern:'[0-9]+(\.[0-9]{1,2})?',value:this.state.fund_allocation_amount,placeholder:'0.00',onChange:this.set('fund_allocation_amount')})),
                            input('fund_allocation_reference','Authority/reference number *','Official allotment or obligation reference'),
                            input('fund_allocation_reason','Basis / reason *','Documented reason'),
                            h('p',{className:'hint'},'The system audits every event. An obligation reversal cannot exceed existing outstanding obligations. A previous obligation must be settled to avoid double counting against a final DV.'),
                            h('div',{className:'ipr-actions'},
                                h('button',{type:'button',onClick:()=>{if(window.confirm('Discard the unsaved fund action?'))this.setState({fund_allocation_open:false});}},'Cancel'),
                                h('button',{type:'submit',className:'primary',disabled:this.state.busy},'Record action'))))):null):null;
            const downloads=this.state.lists.bank_downloads||[];
            const unlinked=downloads.filter(r=>r.bank_account_id===null);
            const downloadManager=finance&&financeSection==='downloads'?h('div',{className:'surface reference-editor'},
                h('div',{className:'section-head'},h('div',null,h('h3',null,'D.O. Bank Downloads · linked allocation receipts'),
                    h('p',{className:'hint'},'One confirmed bank credit provides both a source-fund allotment and a bank book receipt. Unreconciled does NOT mean the credit has been matched to a bank statement.')),isAdmin?h('button',{type:'button',className:'primary',onClick:()=>this.openBankDownload()},'+ Record D.O. Bank Download'):null),
                unlinked.length?h('p',{className:'hint'},unlinked.length+' historical allotment(s) await bank credit evidence and are EXCLUDED from spendable allocation until linked. Do not record the same deposit a second time.'):null,
                h('div',{className:'table-scroll'},h('table',{className:'master-table'},
                    h('thead',null,h('tr',null,...['Entry','Fund','Bank','Credit date','Amount','Bank reference','Status','Action'].map(x=>h('th',{key:x},x)))),
                    h('tbody',null,...downloads.map(r=>h('tr',{key:r.id},
                        h('td',null,'#'+r.id),h('td',null,r.fund_name+' · '+r.fiscal_year),
                        h('td',null,r.bank_name?r.bank_name+' / '+r.account_label:'Unlinked'),
                        h('td',null,r.bank_credit_date||'Not verified'),h('td',null,'₱'+r.amount),
                        h('td',null,r.bank_credit_ref||'—'),
                        h('td',null,r.bank_status==='AWAITING_BANK_LINK'?'Awaiting bank evidence':'Unreconciled'),
                        h('td',null,r.bank_status==='AWAITING_BANK_LINK'&&isAdmin?h('button',{type:'button',className:'outline',onClick:()=>this.openBankDownload('LINK',r)},'Link existing allotment'):'—'))),
                        !downloads.length?h('tr',null,h('td',{colSpan:8},'No downloads or legacy allotments recorded')):null))),
                this.state.bank_download_open&&isAdmin?h('div',{className:'ipr-overlay',role:'presentation'},
                    h('div',{className:'ipr-dialog',role:'dialog','aria-modal':'true','aria-label':'Record bank download',onKeyDown:e=>{
                        if(e.key==='Escape'&&!this.state.busy){e.stopPropagation();if(window.confirm('Discard unsaved bank download details?'))this.setState({bank_download_open:false});}
                    }},
                        h('h3',null,this.state.bank_download_mode==='LINK'?'Link Existing Allotment to Confirmed Bank Credit':'Record D.O. Bank Download'),
                        h('form',{onSubmit:this.saveBankDownload},
                            this.state.bank_download_mode==='LINK'?h('p',{className:'hint'},'Existing fund #'+this.state.bank_download_fund+' · ₱'+this.state.bank_download_amount+'; original amount, author and date stay unchanged.'):h('label',{className:'field'},h('span',null,'Source of fund *'),h('select',{required:true,value:this.state.bank_download_fund,onChange:this.set('bank_download_fund')},
                                h('option',{value:''},'Select source of fund'),...(this.state.lists.funds||[]).map(f=>h('option',{key:f.id,value:String(f.id)},f.fiscal_year+' · '+f.name+' ('+f.source_code+')')))),
                            h('label',{className:'field'},h('span',null,'Bank account *'),h('select',{required:true,value:this.state.bank_download_bank,onChange:this.set('bank_download_bank')},
                                h('option',{value:''},'Select registered bank account'),...(this.state.lists.banks||[]).map(b=>h('option',{key:b.id,value:String(b.id)},b.bank_name+' · '+b.account_label+' ('+b.masked_account+')')))),
                            h('label',{className:'field'},h('span',null,'Date bank credited the account *'),h('input',{type:'date',required:true,max:localToday(),value:this.state.bank_download_date,onChange:this.set('bank_download_date')})),
                            h('label',{className:'field'},h('span',null,'Amount credited (₱) *'),h('input',{required:true,inputMode:'decimal',pattern:'[0-9]+(\\.[0-9]{1,2})?',disabled:this.state.bank_download_mode==='LINK',value:this.state.bank_download_amount,onChange:this.set('bank_download_amount')})),
                            h('label',{className:'field'},h('span',null,'Bank transaction / credit reference *'),h('input',{required:true,maxLength:120,value:this.state.bank_download_ref,onChange:this.set('bank_download_ref')})),
                            h('label',{className:'field'},h('span',null,'D.O. download / advice reference *'),h('input',{required:true,maxLength:120,value:this.state.bank_download_advice,onChange:this.set('bank_download_advice')})),
                            h('label',{className:'field'},h('span',null,'Bank statement / credit evidence reference *'),h('input',{required:true,maxLength:255,value:this.state.bank_download_evidence,onChange:this.set('bank_download_evidence')})),
                            h('label',{className:'field'},h('span',null,this.state.bank_download_mode==='LINK'?'Reason for historical linkage *':'Particulars *'),h('textarea',{required:true,rows:2,maxLength:300,value:this.state.bank_download_mode==='LINK'?this.state.bank_download_link_reason:this.state.bank_download_particulars,
                                onChange:this.state.bank_download_mode==='LINK'?this.set('bank_download_link_reason'):this.set('bank_download_particulars')})),
                            h('p',{className:'hint'},'Record only a confirmed bank credit. The credit date is the allocation effective date. Recording a bank download does not reconcile the statement or establish a complete bank balance.'),
                            h('div',{className:'ipr-actions'},h('button',{type:'button',disabled:this.state.busy,onClick:()=>{if(window.confirm('Discard unsaved download details?'))this.setState({bank_download_open:false});}},'Cancel'),
                                h('button',{type:'submit',className:'primary',disabled:this.state.busy||!(this.state.lists.banks||[]).length},this.state.busy?'Saving…':this.state.bank_download_mode==='LINK'?'Link existing allocation':'Record confirmed credit'))))) : null):null;
            const fundPolicyPanel=finance&&financeSection==='funds'?h('div',{className:'section-stack'},
                h('section',{className:'surface'},h('h3',null,'Division withholding policies · '+(this.state.fundPolicyClass==='NON_IUS'?'Non-IUS schools':'IUS schools')),
                    h('p',{className:'hint'},'Each source has independent, effective-dated IUS and Non-IUS rules. Unconfigured policies cannot authorize tax-cash utilization. School classification is maintained by Division under Organization → Schools.'),
                    isAdmin?h('div',{className:'master-tabs',role:'tablist','aria-label':'School implementation policy'},...[['NON_IUS','Non-IUS Schools'],['IUS','IUS Schools']].map(([key,label])=>h('button',{type:'button',key,className:'master-tab '+(this.state.fundPolicyClass===key?'selected':''),'aria-selected':this.state.fundPolicyClass===key,onClick:()=>this.setState({fundPolicyClass:key}),role:'tab'},label))):
                        h('p',{className:'hint'},'Read-only policy for '+(this.state.fundPolicies?.school_classification?.classification||'unclassified school')+'. Contact Division to update classification or funding rules.'),
                    this.state.fundPoliciesLoading?h('p',{className:'hint'},'Loading Division fund policies…'):null,
                    h('div',{className:'table-scroll'},h('table',{className:'master-table'},
                        h('thead',null,h('tr',null,...['Source','Master status','Tax treatment','Effective from'].map(t=>h('th',{key:t},t)))),
                        h('tbody',null,...((this.state.fundPolicies?.sources||[]).map(s=>{
                            const policy=(this.state.fundPolicies?.policies||[]).find(p=>p.source_code===s.code&&p.school_class===this.state.fundPolicyClass)||{policy:'NOT_CONFIGURED'};
                            const label=policy.policy==='DIVISION_SETTLES_UTILIZABLE'?'Division settles; authorized retained cash may be utilized':policy.policy==='REMIT_TO_DIVISION'?'School remits to Division':'Not configured — utilization blocked';
                            return h('tr',{key:s.code},h('td',null,s.name+' ('+s.code+')'),h('td',null,s.active?'Active':'Inactive / legacy; review'),h('td',null,label),h('td',null,policy.effective_from||'—'));
                        }))))),
                    !this.state.fundPolicies?h('p',{className:'hint'},'Open this tab to load Division policies.'):null),
                isAdmin?h('section',{className:'surface'},h('h3',null,'Division master source directory'),
                    h('p',{className:'hint'},'Register a source once for the Division. Existing school funds appear as legacy references until explicitly reviewed. Deactivation preserves historic entries and reports.'),
                    h('button',{type:'button',className:'primary',onClick:()=>this.setState({sourceDialog:true})},'+ Add / edit Division source'),
                    this.state.sourceDialog?h('div',{className:'ipr-overlay',role:'presentation'},h('div',{className:'ipr-dialog',role:'dialog','aria-modal':'true','aria-label':'Division master source'},
                        h('h3',null,'Division master source'),h('form',{onSubmit:this.saveDivisionSource},h('div',{className:'form-grid'},
                        h('label',{className:'field'},h('span',null,'Source code *'),h('input',{required:true,maxLength:60,value:this.state.sourceCode,onChange:this.set('sourceCode'),placeholder:'MOOE / SIIF / SNED'})),
                        h('label',{className:'field'},h('span',null,'Official source name *'),h('input',{required:true,maxLength:160,value:this.state.sourceName,onChange:this.set('sourceName')})),
                        h('label',{className:'field'},h('span',null,'Status'),h('select',{value:this.state.sourceActive?'ACTIVE':'INACTIVE',onChange:e=>this.setState({sourceActive:e.target.value==='ACTIVE'})},
                            h('option',{value:'ACTIVE'},'Active'),h('option',{value:'INACTIVE'},'Inactive — retain history'))),
                        h('label',{className:'field'},h('span',null,'Reason / authority *'),h('textarea',{required:true,rows:2,maxLength:500,value:this.state.sourceReason,onChange:this.set('sourceReason')}))),
                        h('button',{type:'submit',className:'primary',disabled:this.state.busy||!this.state.fundPolicies},'Save Division fund source'),
                        h('button',{type:'button',className:'outline',onClick:()=>{if(window.confirm('Discard unsaved Division source changes?'))this.setState({sourceDialog:false});}},'Cancel')))):null):null,
                isAdmin?h('section',{className:'surface'},h('h3',null,'Set withholding treatment · '+(this.state.fundPolicyClass==='NON_IUS'?'Non-IUS':'IUS')),
                    h('button',{type:'button',className:'primary',onClick:()=>this.setState({policyDialog:true})},'Configure '+(this.state.fundPolicyClass==='NON_IUS'?'Non-IUS':'IUS')+' policy'),
                    this.state.policyDialog?h('div',{className:'ipr-overlay',role:'presentation'},h('div',{className:'ipr-dialog',role:'dialog','aria-modal':'true','aria-label':'Withholding fund policy'},
                        h('h3',null,'Division withholding policy'),h('form',{onSubmit:this.saveDivisionFundPolicy},h('div',{className:'form-grid'},
                        h('label',{className:'field'},h('span',null,'Source of funds *'),h('select',{required:true,value:this.state.fundPolicySource,onChange:this.set('fundPolicySource')},
                            h('option',{value:''},'Select approved source'),...((this.state.fundPolicies?.sources||[]).filter(s=>s.active).map(s=>h('option',{key:s.code,value:s.code},s.name+' ('+s.code+')'))))),
                        h('label',{className:'field'},h('span',null,'Withholding cash treatment *'),h('select',{required:true,value:this.state.fundPolicyMode,onChange:this.set('fundPolicyMode')},
                            h('option',{value:'NOT_CONFIGURED'},'Not configured — block utilization'),
                            h('option',{value:'REMIT_TO_DIVISION'},'School remits withheld tax to Division'),
                            h('option',{value:'DIVISION_SETTLES_UTILIZABLE'},'Division handles tax; utilization permitted under recorded arrangement'))),
                        h('label',{className:'field'},h('span',null,'Effective from *'),h('input',{type:'date',required:true,value:this.state.fundPolicyDate,onChange:this.set('fundPolicyDate')})),
                        h('label',{className:'field'},h('span',null,'Division instruction / GC / meeting reference *'),h('input',{required:true,maxLength:255,value:this.state.fundPolicyAuthority,onChange:this.set('fundPolicyAuthority')})),
                        h('label',{className:'field'},h('span',null,'Reason / supporting details *'),h('textarea',{required:true,rows:2,maxLength:500,value:this.state.fundPolicyReason,onChange:this.set('fundPolicyReason')}))),
                        h('p',{className:'hint'},'Effective-dated changes are audited. They do not retrospectively rewrite finalized DV totals, recorded tax dispositions or issued checks. Permission alone is not evidence of BIR settlement or a new allotment.'),
                        h('button',{type:'submit',className:'primary',disabled:this.state.busy||!this.state.fundPolicies},'Save '+(this.state.fundPolicyClass==='NON_IUS'?'Non-IUS':'IUS')+' policy'),
                        h('button',{type:'button',className:'outline',onClick:()=>{if(window.confirm('Discard unsaved withholding policy changes?'))this.setState({policyDialog:false});}},'Cancel')))):null):null
            ):null;
            const cols=section==='suppliers' ?['name','proprietor_name','tax_id','zip_code','business_classification','vat_status','tax_profiles','active']:section==='items'?['name','catalog_scope','category','division_class','item_uacs','property_registry','unit','active']:section==='funds'?['name','source_code','fiscal_year','authorized_allocation','outstanding_obligations','finalized_dv_gross','available_allocation','awaiting_bank_link']:section==='banks'?['bank_name','account_label','masked_account']:section==='units'?['code','name']:['code','description','supply_type','active'];
            const hasActions=canEdit&&['suppliers','items','atcs'].includes(section);
            const workspace=this.state.orgs.find(o=>String(o.id)===String(this.state.organization_id));
            const itemOwns=r=>r.owner_organization_id!==null&&r.owner_organization_id!==undefined
                ?String(r.owner_organization_id)===String(this.state.organization_id)
                :isAdmin&&workspace?.kind==='DIVISION';
            const heads=cols.map(col=>h('th',{key:col},col.replace(/_/g,' ')));if(hasActions)heads.push(h('th',{key:'action'},'Actions'));
            const body=filtered.map(r=>h('tr',{key:r.id||r.code},...cols.map(col=>h('td',{key:col},
                section==='suppliers' && col==='business_classification' ? (r.business_classification==='COMMERCIAL'?'Corporate':r.business_classification==='INDIVIDUAL'?'Individual':'Not yet verified')
                : section==='suppliers' && col==='tax_profiles' ? (supplierProfileTypes.filter(type=>this.supplierProfileActive(r,type)).map(type=>this.profileLabel(type)).join(' · ')||'Not configured')
                : section==='items' && col==='category' ? ({SUPPLY:'Consumable supply',SEMI_EXPENDABLE:'Semi-expendable',PPE:'PPE',TEXTBOOK:'Textbook',SERVICE:'Service'}[r.category]||r.category)
                : section==='items' && col==='division_class' ? (this.latestItemUacs(r.id)?.classification_code?this.classificationLabel(this.latestItemUacs(r.id).classification_code):r.category==='SEMI_EXPENDABLE'?(this.classificationLabel(r.subcategory)||'Needs classification'):'Not assigned (legacy)')
                : section==='items' && col==='property_registry' ? (String(this.state.catalogRegistryLinks?.organization_id)===String(this.state.organization_id)&&this.state.catalogRegistryLinks?.links?.[String(r.id)]?.registry_id
                    ? ((this.state.catalogRegistryLinks.links[String(r.id)].review_required?'Review property link · ':'')+this.state.catalogRegistryLinks.links[String(r.id)].description+' · '+(this.state.catalogRegistryLinks.links[String(r.id)].hv_code||this.state.catalogRegistryLinks.links[String(r.id)].reference_code||''))
                    : 'Not linked')
                : section==='items' && col==='item_uacs' ? (this.latestItemUacs(r.id)?this.latestItemUacs(r.id).uacs_code+' · from '+this.latestItemUacs(r.id).effective_from+(['AUTO_SCHOOL_CONSUMABLE','AUTO_DIVISION_CLASS'].includes(this.latestItemUacs(r.id).source)?' · auto from '+(this.latestItemUacs(r.id).classification_code||'class'):''):'Not mapped')
                : col==='active'?(Number(r.active)?'Active':'Inactive'):(r[col]??'—'))),hasActions?h('td',{key:'actions'},h('div',{className:'inline-actions'},section==='suppliers'?h('button',{className:'text-btn',type:'button',onClick:()=>this.editSupplier(r)},'Edit'):null,
                section==='items'&&isAdmin&&itemOwns(r)?h('button',{className:'text-btn',type:'button',disabled:this.state.busy,
                    onClick:()=>this.openItemUacsDialog(r)},'Manage UACS'):null,
                section==='items'&&itemOwns(r)&&['PPE','SEMI_EXPENDABLE','TEXTBOOK'].includes(r.category)?h('button',{className:'text-btn',
                    type:'button',disabled:this.state.busy,onClick:()=>this.openRegistryLink(r)},'Link Property Type'):null,
                section==='items'&&itemOwns(r)&&r.category==='SEMI_EXPENDABLE'?h('button',{className:'text-btn',type:'button',disabled:this.state.busy,onClick:()=>this.editItemSubcategory(r)},r.subcategory?'Edit subcategory':'Classify'):null,
                (section!=='items'||itemOwns(r))?h('button',{type:'button',className:'text-btn',disabled:this.state.busy,onClick:()=>section==='atcs'?this.toggleAtc(r):this.changeMasterStatus(section==='suppliers'?'supplier':'item',r)},Number(r.active)?'Deactivate':'Reactivate'):null)):null));
            if(!body.length)body.push(h('tr',{key:'none'},h('td',{colSpan:heads.length,className:'empty'},'No matching records')));
            const listing=h('div',{className:'surface'},h('div',{className:'section-head'},h('h3',null,heading+' records'),h('div',{className:'inline-actions'},h('span',{className:'count'},rows.length+' records'),
                section==='items'&&isAdmin?h('button',{type:'button',className:'primary',disabled:this.state.busy||this.state.itemUacsLoading||!this.state.itemUacsSettings,onClick:this.openBulkUacs},'Bulk Assign UACS'):null)),h('label',{className:'field'},h('span',null,'Search records'),h('input',{type:'search',value:this.state.masterSearch,onChange:this.set('masterSearch'),placeholder:'Search records'})),h('div',{className:'table-scroll'},h('table',{className:'master-table'},h('thead',null,h('tr',null,...heads)),h('tbody',null,...body))));
            // v0.2.76: Reference register ONLY. UACS maintenance has one entry
            // point: the Manage UACS action beside the item in the catalog.
            const allItemUacsMappings=(this.state.itemUacsSettings?.mappings||[]).filter(m=>(this.state.lists.all_items||[]).some(i=>Number(i.id)===Number(m.item_id)));
            const itemUacsPanel=!finance&&section==='items'?h('details',
                {className:'surface uacs-mapping-register',id:'division-item-uacs-map'},
                h('summary',null,
                    h('strong',null,'UACS mapping register — view only'),
                    h('small',null,allItemUacsMappings.length+' effective-dated mapping record(s) · Select to view')),
                h('div',{className:'uacs-mapping-register-body'},
                    h('p',{className:'hint'},'This register shows the active Division-controlled mapping configuration; it is not a second editor. To add a dated mapping or correct an existing effective date, choose Manage UACS beside the item above. Earlier configuration versions remain in the audit history.'),
                    h('div',{className:'inline-actions'},
                        h('button',{type:'button',className:'outline',
                            disabled:this.state.busy||this.state.itemUacsLoading,
                            onClick:()=>this.loadDivisionItemUacs()},this.state.itemUacsLoading?'Refreshing…':'Refresh register')),
                    h('div',{className:'table-scroll'},h('table',{className:'master-table'},
                        h('thead',null,h('tr',null,h('th',null,'Catalog item'),h('th',null,'Account'),h('th',null,'Effective from'))),
                        h('tbody',null,...allItemUacsMappings.slice(-200).map((map,i)=>{
                            const item=(this.state.lists.all_items||[]).find(it=>Number(it.id)===Number(map.item_id));
                            const account=(this.state.accounts||[]).find(a=>String(a.code)===String(map.uacs_code));
                            return h('tr',{key:map.item_id+'-'+map.effective_from+'-'+i},
                                h('td',null,item?.name||'Item #'+map.item_id),
                                h('td',null,map.uacs_code+(account?' · '+account.account_name:'')),
                                h('td',null,map.effective_from));
                        }),!allItemUacsMappings.length?h('tr',null,h('td',{colSpan:3},
                            'No Division item UACS mappings recorded. Use Manage UACS beside an item to add a mapping.')):null))),
                    allItemUacsMappings.length>200?h('p',{className:'hint'},
                        'Showing the last 200 active mapping records. Manage the specific item through the Item Catalog.'):null)):null;
            const mappingItem=(this.state.lists.all_items||[]).find(i=>String(i.id)===String(this.state.itemUacsId));
            const previousMapping=mappingItem?this.latestItemUacs(mappingItem.id):null;
            const itemUacsMappings=(this.state.itemUacsSettings?.mappings||[])
                .filter(m=>Number(m.item_id)===Number(mappingItem?.id))
                .sort((a,b)=>String(b.effective_from).localeCompare(String(a.effective_from)));
            const debitAccounts=(this.state.accounts||[]).filter(a=>/^(502|104|106|108)\d{7}$/.test(String(a.code)));

            const correction=this.state.uacsCorrection,p=this.state.uacsCorrectionPreview;
            const correctionItem=(this.state.lists.all_items||[]).find(i=>Number(i.id)===Number(correction?.item_id));
            const uacsCorrectionModal=!finance&&section==='items'&&isAdmin&&correction?h('div',
                {className:'ipr-overlay',role:'presentation',
                    onMouseDown:e=>{if(e.target===e.currentTarget)this.closeUacsCorrection();}},
                h('section',{className:'ipr-dialog uacs-correction-dialog',role:'dialog','aria-modal':'true',
                    'aria-label':'Correct UACS effective date',
                    onKeyDown:e=>{if(e.key==='Escape')this.closeUacsCorrection();}},
                    h('div',{className:'section-head'},
                        h('div',null,h('p',{className:'eyebrow'},'REFERENCE DATA / AUTHORIZED CORRECTION'),
                            h('h3',null,'Correct UACS effective date')),
                        h('button',{type:'button',className:'outline',disabled:this.state.busy,
                            'aria-label':'Close correction',onClick:this.closeUacsCorrection},'×')),
                    h('p',{className:'body-muted'},'Correct the effective date of one existing mapping. The UACS code stays the same. A new configuration version and audit event will preserve the original date; no IAR, DV, amount or journal will be edited.'),
                    h('div',{className:'uacs-correction-summary'},
                        h('strong',null,correctionItem?.name||'Item #'+correction.item_id),
                        h('span',null,'UACS '+correction.uacs_code+' · Original effective date '+correction.old_effective_from)),
                    this.state.error?h('p',{className:'ipr-error',role:'alert'},this.state.error):null,
                    h('form',{onSubmit:this.previewUacsCorrection},
                        h('label',{className:'field'},h('span',null,'Corrected effective date *'),
                            h('input',{type:'date',required:true,value:correction.new_effective_from,
                                onChange:e=>this.patchUacsCorrection('new_effective_from',e.target.value)})),
                        h('label',{className:'field'},h('span',null,'Reason for correction *'),
                            h('textarea',{required:true,maxLength:200,rows:2,value:correction.reason,
                                onChange:e=>this.patchUacsCorrection('reason',e.target.value),
                                placeholder:'Explain the actual accounting effectivity and date-entry error'})),
                        h('label',{className:'field'},h('span',null,'Supporting accounting authority or document reference *'),
                            h('input',{required:true,maxLength:200,value:correction.authority_reference,
                                onChange:e=>this.patchUacsCorrection('authority_reference',e.target.value),
                                placeholder:'Memo / approved policy / signed reference'})),
                        h('button',{type:'submit',className:'outline',disabled:this.state.busy||
                            correction.new_effective_from===correction.old_effective_from||
                            correction.reason.trim().length<12||correction.authority_reference.trim().length<5},
                            this.state.busy?'Reviewing…':'Preview affected IARs and DVs')),
                    p?h('section',{className:'uacs-correction-impact'},
                        h('h4',null,'Review correction impact'),
                        h('p',{className:'hint'},'From '+p.old_effective_from+' to '+p.new_effective_from+
                            ' · '+p.affected_line_count+' accepted IAR line(s) change their Division mapping.'),
                        p.affected_iars.length?h('div',{className:'table-scroll'},
                            h('table',{className:'data-table'},
                                h('thead',null,h('tr',null,...['Organization / IAR','Accepted','Amount','UACS before → after','Draft DVs'].map(x=>h('th',{key:x},x)))),
                                h('tbody',null,...p.affected_iars.map(r=>h('tr',{key:r.iar_line_id},
                                    h('td',null,r.organization_name+' · '+(r.iar_number||'#'+r.iar_id)),
                                    h('td',null,r.accepted_at),h('td',null,'₱'+Number(r.accepted_amount).toFixed(2)),
                                    h('td',null,(r.old_division_uacs||'No Division mapping')+' → '+(r.new_division_uacs||'No Division mapping')),
                                    h('td',null,r.draft_dv_ids||'—')))))):null,
                        p.can_apply?h('p',{className:'hint'},'Only the item mapping configuration changes. Draft workpapers must be reopened for review. All other finalization guards remain.'):
                            h('p',{className:'notice error',role:'alert'},'Correction blocked: '+p.affected_finalized_line_count+
                                ' affected IAR line(s) are linked to finalized DVs. Formal accounting review required.'),
                        h('div',{className:'ipr-actions'},
                            h('button',{type:'button',className:'outline',disabled:this.state.busy,
                                onClick:this.closeUacsCorrection},'Cancel'),
                            h('button',{type:'button',className:'primary',disabled:this.state.busy||!p.can_apply,
                                onClick:this.applyUacsCorrection},'Apply reviewed effective-date correction'))):null
                )):null;
            const itemUacsModal=!finance&&section==='items'&&isAdmin&&this.state.itemUacsDialog?h('div',
                {className:'ipr-overlay',role:'presentation',onMouseDown:e=>{if(e.target===e.currentTarget)this.closeItemUacsDialog();}},
                h('section',{className:'ipr-dialog',role:'dialog','aria-modal':'true','aria-label':'Map catalog item to UACS',
                    onKeyDown:e=>{if(e.key==='Escape'){e.preventDefault();this.closeItemUacsDialog();}}},
                    h('div',{className:'section-head'},h('div',null,h('p',{className:'eyebrow'},'REFERENCE DATA / ITEM CATALOG'),
                        h('h3',null,'Manage item UACS')),
                        h('button',{className:'outline',type:'button','aria-label':'Close UACS mapping',disabled:this.state.busy,onClick:this.closeItemUacsDialog},'×')),
                    h('p',{className:'body-muted'},'One place for authorized item-level UACS maintenance. View existing effective-dated mappings here, add a new dated mapping, or use Correct effective date for an encoding error. No existing IAR, DV, or posted journal is changed.'),
                    h('div',{className:'hint'},h('div',null,h('strong',null,mappingItem?.name||'Item unavailable')),
                        h('div',null,'Category: '+(mappingItem?.category||'—').replaceAll('_',' ')),
                        h('div',null,previousMapping?'Most recent mapping: '+previousMapping.uacs_code+' from '+previousMapping.effective_from:'No item-specific mapping yet')),
                    this.state.orgs.find(o=>String(o.id)===String(this.state.organization_id))?.kind!=='DIVISION'
                        ?h('p',{className:'hint'},'Switch to the owning Division Office workspace to correct an existing effective date.'):null,
                    this.state.error?h('p',{className:'ipr-error',role:'alert'},this.state.error):null,
                    !this.state.itemUacsSettings?h('p',{className:'hint'},'Loading Division item mapping version. Saving will be unavailable until it loads.'):null,
                    h('section',{className:'uacs-item-current-mappings','aria-label':'Current effective-dated item UACS mappings'},
                        h('h4',null,'Existing mappings for this item'),
                        itemUacsMappings.length?h('div',{className:'uacs-item-mapping-list'},
                            ...itemUacsMappings.map((map,i)=>{
                                const account=(this.state.accounts||[]).find(a=>String(a.code)===String(map.uacs_code));
                                return h('div',{className:'uacs-item-mapping-row',key:map.effective_from+'-'+i},
                                    h('div',null,
                                        h('strong',null,map.uacs_code+(account?' · '+account.account_name:'')),
                                        h('small',null,'Effective '+map.effective_from)),
                                    h('button',{type:'button',className:'outline',
                                        disabled:this.state.busy||this.state.itemUacsLoading||
                                            this.state.orgs.find(o=>String(o.id)===String(this.state.organization_id))?.kind!=='DIVISION',
                                        onClick:()=>this.openUacsCorrection(map)},'Correct effective date'));
                            })):h('p',{className:'hint'},'No existing item-level UACS mapping. You can add the first mapping below.')),
                    h('div',{className:'uacs-item-new-heading'},
                        h('h4',null,'Add or update an account for a specific effective date'),
                        h('p',{className:'hint'},'A different date creates another effective-dated rule; it does not correct an old date. An existing date can update that date’s UACS with an audit record. Use Correct effective date above when the encoded date itself is wrong.')),
                    h('form',{onSubmit:this.saveDivisionItemUacs},
                        h('label',{className:'field'},h('span',null,'Approved debit UACS account *'),
                            h('select',{required:true,value:this.state.itemUacsCode,onChange:this.set('itemUacsCode')},
                                h('option',{value:''},'Select reviewed active account'),
                                ...debitAccounts.map(a=>h('option',{key:a.code,value:a.code},a.code+' — '+a.account_name)))),
                        h('label',{className:'field'},h('span',null,'Effective from *'),
                            h('input',{type:'date',required:true,value:this.state.itemUacsDate,onChange:this.set('itemUacsDate')})),
                        h('label',{className:'field'},h('span',null,'Division classification reason / authority *'),
                            h('textarea',{required:true,rows:3,maxLength:500,value:this.state.itemUacsReason,onChange:this.set('itemUacsReason'),
                                placeholder:'Official classification basis and reason for this mapping'})),
                        h('p',{className:'hint'},'Accounting uses the latest applicable dated item mapping on the IAR acceptance date. This form does not correct an existing effective date or rewrite finalized accounting.'),
                        !debitAccounts.length?h('p',{className:'ipr-error'},'No approved debit UACS accounts are enabled. Review UACS Accounts first.'):null,
                        h('div',{className:'ipr-actions'},h('button',{type:'button',className:'outline',disabled:this.state.busy,onClick:this.closeItemUacsDialog},'Cancel'),
                            h('button',{type:'submit',className:'primary',disabled:this.state.busy||this.state.itemUacsLoading||!this.state.itemUacsSettings||!mappingItem||!debitAccounts.length},
                                this.state.busy?'Saving…':'Save dated UACS mapping'))))) :null;
            const bulkCandidates=this.bulkUacsCandidates();
            const bulkClasses=(this.state.divisionClassifications||[]).filter(c=>c.applies_to==='SUPPLY'&&c.active&&
                this.divisionClassUacsEligible('SUPPLY',c.default_uacs_code)&&
                (this.state.accounts||[]).some(a=>a.code===c.default_uacs_code));
            const bulkUacsModal=!finance&&section==='items'&&isAdmin&&this.state.bulkUacsOpen?h('div',
                {className:'ipr-overlay',role:'presentation',onMouseDown:e=>{if(e.target===e.currentTarget)this.closeBulkUacs();}},
                h('section',{className:'ipr-dialog',role:'dialog','aria-modal':'true','aria-label':'Bulk assign consumable UACS',
                    style:{maxWidth:'800px',width:'min(96vw,800px)',maxHeight:'90vh',overflowY:'auto'},
                    onKeyDown:e=>{if(e.key==='Escape'){e.preventDefault();this.closeBulkUacs();}}},
                    h('div',{className:'section-head'},h('div',null,h('p',{className:'eyebrow'},'REFERENCE DATA / ITEM CATALOG'),
                        h('h3',null,'Bulk Assign UACS to Existing Consumables')),
                        h('button',{type:'button',className:'outline',disabled:this.state.busy,onClick:this.closeBulkUacs},'Close')),
                    h('p',{className:'body-muted'},'Only selected active, previously unmapped consumables will be assigned. Existing item mappings, other Divisions, and finalized documents will not be changed. Apply one approved class at a time.'),
                    this.state.error?h('p',{className:'ipr-error',role:'alert'},this.state.error):null,
                    !this.state.itemUacsSettings?h('p',{className:'ipr-error'},'Refresh mapping data before beginning.'):null,
                    h('form',{onSubmit:this.previewBulkUacs},
                        h('div',{className:'form-grid'},
                            h('label',{className:'field'},h('span',null,'Division-approved consumable class *'),
                                h('select',{required:true,value:this.state.bulkUacsClass,
                                    onChange:e=>this.setState({bulkUacsClass:e.target.value,bulkUacsPreview:null})},
                                    h('option',{value:''},'Select a reviewed school-use class'),
                                    ...bulkClasses.map(c=>h('option',{key:c.code,value:c.code},c.name+' · '+c.default_uacs_code)))),
                            h('label',{className:'field'},h('span',null,'Mapping effective date *'),
                                h('input',{required:true,type:'date',value:this.state.bulkUacsDate,
                                    onChange:e=>this.setState({bulkUacsDate:e.target.value,bulkUacsPreview:null})}))),
                        h('div',{className:'section-head'},h('strong',null,'Selected unmapped supplies: '+this.state.bulkUacsIds.length+' of '+bulkCandidates.length),
                            h('div',{className:'inline-actions'},
                                h('button',{type:'button',className:'outline',disabled:this.state.busy,
                                    onClick:()=>this.setState({bulkUacsIds:bulkCandidates.map(it=>Number(it.id)),bulkUacsPreview:null})},'Select all'),
                                h('button',{type:'button',className:'outline',disabled:this.state.busy,
                                    onClick:()=>this.setState({bulkUacsIds:[],bulkUacsPreview:null})},'Clear'))),
                        h('div',{className:'table-scroll',style:{maxHeight:'240px',overflowY:'auto'}},
                            h('table',{className:'master-table'},h('thead',null,h('tr',null,h('th',null,'Select'),h('th',null,'Existing consumable'),h('th',null,'Unit'))),
                                h('tbody',null,...bulkCandidates.map(item=>h('tr',{key:item.id},
                                    h('td',null,h('input',{type:'checkbox',checked:this.state.bulkUacsIds.includes(Number(item.id)),
                                        onChange:e=>this.setState({bulkUacsIds:e.target.checked?[...this.state.bulkUacsIds,Number(item.id)]:this.state.bulkUacsIds.filter(id=>id!==Number(item.id)),bulkUacsPreview:null})})),
                                    h('td',null,item.name),h('td',null,item.unit||'—'))),
                                !bulkCandidates.length?h('tr',null,h('td',{colSpan:3},'No active unmapped consumables available in this Division.')):null))),
                        h('p',{className:'hint'},'Maximum 100 items per batch. The system refuses the entire batch if any selected item gains another mapping or the Division class/account changes before Apply. For older IARs, the effective date must be consistent with the Division Accountant’s documented authority.'),
                        h('label',{className:'field'},h('span',null,'Division authority / classification reason *'),
                            h('textarea',{required:true,maxLength:500,rows:2,value:this.state.bulkUacsReason,
                                onChange:e=>this.setState({bulkUacsReason:e.target.value}) ,
                                placeholder:'Document the approved class and accounting classification authority'})),
                        h('div',{className:'ipr-actions'},h('button',{type:'button',className:'outline',disabled:this.state.busy,onClick:this.closeBulkUacs},'Cancel'),
                            h('button',{type:'submit',className:'primary',disabled:this.state.busy||!this.state.itemUacsSettings||!this.state.bulkUacsClass||!this.state.bulkUacsIds.length||!this.state.bulkUacsReason.trim()},
                                this.state.busy?'Checking…':'Preview selected mappings'))),
                    this.state.bulkUacsPreview?h('div',{className:'surface',style:{marginTop:'12px'}},
                        h('h3',null,'Reviewed batch · '+this.state.bulkUacsPreview.items.length+' item(s)'),
                        h('p',null,'Classification: '+this.state.bulkUacsPreview.classification_name+' · UACS '+this.state.bulkUacsPreview.uacs_code),
                        h('p',null,'Effective from: '+this.state.bulkUacsPreview.effective_from),
                        h('div',{className:'table-scroll',style:{maxHeight:'150px',overflowY:'auto'}},
                            h('ul',null,...this.state.bulkUacsPreview.items.map(item=>h('li',{key:item.id},item.name)))),
                        h('p',{className:'hint'},'Applying writes one new Division configuration version and an audit entry for each item. No existing mapping is overwritten.'),
                        h('button',{type:'button',className:'primary',disabled:this.state.busy||!this.state.bulkUacsReason.trim(),onClick:this.applyBulkUacs},
                            this.state.busy?'Applying…':'Apply reviewed mappings')):null)) :null;
            const atcForms=h('div',{className:'tax-setup-stack'},h('div',{className:'surface'},h('h3',null,'Add ATC code'),h('p',{className:'body-muted'},'Enter only verified official ATCs. No example statutory rates are preloaded.'),h('form',{onSubmit:this.saveAtc},h('div',{className:'form-grid'},input('atc_code','ATC code *','Official ATC code'),input('atc_description','Description *','Official withholding category'),pick('atc_supply_type','Applies to',[['GOODS','Goods'],['SERVICES','Services'],['BOTH','Goods or Services']])),h('button',{type:'submit',className:'primary',disabled:this.state.busy},'Save ATC'))),h('div',{className:'surface'},h('h3',null,'Create effective-dated ATC rate'),h('form',{onSubmit:this.saveAtcRate},h('div',{className:'form-grid'},this.select('rate_atc_code','ATC *',(this.state.lists.atcs||[]).filter(x=>Number(x.active)).map(a=>({id:a.code,name:a.code+' — '+a.description}))),input('rate_percent','Withholding rate (%) *','e.g. 1.0000'),input('rate_starts','Effective from *','','date'),input('rate_ends','Effective until (optional)','','date'),h('label',{className:'field master-wide'},h('span',null,'Authority / regulation reference *'),h('textarea',{value:this.state.rate_authority,onChange:this.set('rate_authority'),placeholder:'Approved official legal/regulatory basis',rows:2}))),h('p',{className:'hint'},'A rate entered as 1.00 means 1%, stored as 0.010000. Existing rates are not overwritten. Overlapping effective periods are rejected.'),h('button',{type:'submit',className:'primary',disabled:this.state.busy},'Save rate version'))),h('div',{className:'surface'},h('h3',null,'Close an existing open-ended rate'),h('form',{onSubmit:this.closeAtcRate},h('div',{className:'form-grid'},this.select('rate_close_id','Open-ended rate to close *',(this.state.lists.atc_rates||[]).filter(r=>!r.ends).map(r=>({id:r.id,name:r.atc_code+' · '+(Number(r.rate)*100).toFixed(4)+'% · since '+r.starts}))),input('rate_close_date','Last effective date *','','date')),h('button',{type:'submit',className:'primary',disabled:this.state.busy},'Close rate'))));
            const rateRows=(this.state.lists.atc_rates||[]);
            const rateList=section==='atcs'?h('div',{className:'surface'},h('h3',null,'Rate history (read-only)'),h('div',{className:'table-scroll'},h('table',{className:'master-table'},h('thead',null,h('tr',null,...['ATC','Rate (%)','Start','End','Authority'].map(t=>h('th',{key:t},t)))),h('tbody',null,...rateRows.map(r=>h('tr',{key:r.id},h('td',null,r.atc_code),h('td',null,(Number(r.rate)*100).toFixed(4)),h('td',null,r.starts),h('td',null,r.ends||'Ongoing'),h('td',null,r.authority_reference||'—'))))))):null;
            const editor=canEdit?(section==='atcs'?atcForms:h('div',{className:'surface reference-editor',id:'reference-master-editor'},h('div',{className:'section-head'},h('h3',null,section==='suppliers'&&this.state.sup_edit_id?'Edit supplier #'+this.state.sup_edit_id:section==='items'&&this.state.item_edit_id?'Edit semi-expendable subcategory':'Add '+heading),h('span',{className:'mini-tag'},section==='items'?(this.state.orgs.find(o=>String(o.id)===String(this.state.organization_id))?.kind==='DIVISION'?'Division-wide catalog':'School-only catalog'):section==='funds'?'School-specific':'Shared reference')),section==='items'&&!(this.state.lists.units||[]).length?h('div',{className:'hint'},'Create at least one unit first.'):null,h('form',{onSubmit:section==='banks'?this.saveBankAccount:this.createMaster},section==='suppliers'?supplierForm:section==='units'?unitForm:section==='items'?itemForm:section==='banks'?bankForm:fundForm,section==='funds'?h('p',{className:'body-muted'},'A fund source does not automatically add allotment or cash.'):null,h('div',{className:'inline-actions'},h('button',{className:'primary',disabled:this.state.busy,type:'submit'},this.state.busy?'Saving…':section==='suppliers'&&this.state.sup_edit_id?'Save Supplier Changes':section==='items'&&this.state.item_edit_id?'Save Subcategory':'Save '+heading),section==='suppliers'&&this.state.sup_edit_id?h('button',{type:'button',className:'outline',onClick:this.resetSupplier},'Cancel Edit'):null,
                section==='items'&&this.state.item_edit_id?h('button',{type:'button',className:'outline',onClick:this.cancelItemEdit},'Cancel Edit'):null)))):h('div',{className:'hint'},'You have read access; Division/technical administrators maintain shared tax and supplier reference data.');
            return h('div',{className:'section-stack'},this.header(finance?'Fund management':'Reference data',finance?'School funding sources, bank accounts, linked D.O. downloads and authorized allocation controls.':'Suppliers, items, property, withholding tax and other master references.'),finance?financeTabs:h('div',{className:'master-tabs',role:'tablist','aria-label':'Reference data'},...options.map(([v,title])=>h('button',{key:v,type:'button',role:'tab','aria-selected':section===v,className:'master-tab '+(section===v?'selected':''),onClick:()=>this.setState({masterSection:v,masterSearch:'',masterEditorOpen:false},()=>{if(v==='uacs')this.loadUacs(0);})},title))),((!finance||['funds','banks'].includes(financeSection))&&canEdit)?h('div',{className:'reference-editor-toolbar'},h('p',null,'Find records below. Open the entry form only when adding or editing.'),h('button',{type:'button',className:'outline reference-editor-toggle','aria-expanded':Boolean(this.state.masterEditorOpen),'aria-controls':'reference-master-editor',onClick:()=>{
                const open=!this.state.masterEditorOpen;
                const fresh= open && !this.state.sup_edit_id && !this.state.item_edit_id;
                this.setState({masterEditorOpen:open,...(!open?{sup_edit_id:'',item_edit_id:''}:{}),...(fresh?{sup_name:'',sup_tax_id:'',sup_address:'',sup_zip:'',sup_atcs:[],sup_profiles:[],sup_legacy_atcs:[],sup_commercial:'UNSPECIFIED',sup_vat_status:'UNKNOWN',item_name:'',item_category:'SUPPLY',item_subcategory:'',item_school_class:'',item_unit_id:''}:{}),...(open?{error:'',success:''}:{})});
            }},this.state.masterEditorOpen?'− Hide entry form':'+ Add '+(section==='suppliers'?'supplier':section==='items'?'catalog item':section==='funds'?'fund source':section==='banks'?'bank account':'unit'))):null,fundPolicyPanel,this.state.masterEditorOpen&&section==='banks'?h('div',{className:'ipr-overlay',role:'presentation'},h('div',{className:'ipr-dialog',role:'dialog','aria-modal':'true','aria-label':'Register bank account'},editor,h('button',{type:'button',className:'outline',onClick:()=>{if(window.confirm('Discard unsaved bank details?'))this.setState({masterEditorOpen:false});}},'Cancel'))):this.state.masterEditorOpen?editor:null,(!finance||['funds','banks'].includes(financeSection))?listing:null,itemUacsPanel,itemUacsModal,uacsCorrectionModal,bulkUacsModal,
                section==='items'?this.registryLinkModal():null,downloadManager,fundAllocationEditor,rateList);
        };
        // v0.2.26: user-facing PR review. Raw JSON remains only in the generic
        // administrative document viewer for other types until their dedicated
        // record views are implemented.
        // Read-only PO document preview: financial values come solely from the
        // persisted PO detail, not from editable catalog / form defaults.
        // Supplier and item directory labels are reference information only.
        // Finalized IAR view: counts and acceptance value come ONLY from the
        // saved IAR. The read-only, linked PO supplies human-readable item labels.
        this.iarDetailModal = () => {
            const h=React.createElement,d=this.state.details||{},r=d.record||{};
            const lines=Array.isArray(d.lines)?d.lines:[],links=Array.isArray(d.links)?d.links:[];
            const versions=Array.isArray(d.versions)?d.versions:[],signed=Array.isArray(d.manual_signature_records)?d.manual_signature_records:[];
            const po=d.related_po||{},poRecord=po.record||{},poLines=Array.isArray(po.lines)?po.lines:[];
            const catalog=this.state.lists.all_items||[],units=this.state.lists.units||[];
            const poFromList=(this.state.lists.po||[]).find(x=>String(x.id)===String(r.po_id));
            const supplierId=poRecord.supplier_id||poFromList?.supplier_id;
            const supplier=(this.state.lists.suppliers||[]).find(x=>String(x.id)===String(supplierId));
            const supplierName=supplier?.name||poFromList?.supplier||(
                supplierId?'Supplier record #'+supplierId:'Supplier not available');
            const org=(this.state.orgs||[]).find(x=>String(x.id)===String(r.organization_id));
            const money=n=>(n!==null&&n!==''&&n!==undefined&&Number.isFinite(Number(n)))?
                '₱'+Number(n).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}):'—';
            const qty=n=>(n!==null&&n!==''&&n!==undefined&&Number.isFinite(Number(n)))?
                Number(n).toLocaleString('en-PH',{maximumFractionDigits:3}):'—';
            const currencyTotal=lines.length&&lines.every(l=>l.accepted_amount!==null&&l.accepted_amount!==''&&
                Number.isFinite(Number(l.accepted_amount)))?
                lines.reduce((sum,l)=>sum+Math.round(Number(l.accepted_amount)*100),0)/100:null;
            const field=(label,value)=>h('div',{className:'pr-detail-field',key:label},
                h('span',null,label),h('strong',null,value===null||value===undefined||value===''?'—':String(value)));
            const close=()=>{this.documentDetailTicket=(this.documentDetailTicket||0)+1;
                this.setState({viewType:'',details:null});};
            const lineRows=lines.length?lines.map((l,i)=>{
                const poLine=poLines.find(x=>String(x.id)===String(l.po_line_id));
                const item=poLine&&catalog.find(x=>String(x.id)===String(poLine.item_id));
                const unit=item&&units.find(u=>String(u.id)===String(item.unit_id));
                const itemLabel=poLine?(item?.name||'Catalog item #'+(poLine.item_id||'—')):
                    'PO line #'+(l.po_line_id||'—')+' (name unavailable)';
                return h('tr',{key:l.id||i},
                    h('td',null,i+1),
                    h('td',{className:'pr-detail-item'},itemLabel,
                        poLine?h('span',{className:'body-muted'},' · Unit: '+(item?.unit_name||item?.unit_code||unit?.code||'—')):null),
                    h('td',{className:'pr-detail-num'},qty(l.delivered_qty)),
                    h('td',{className:'pr-detail-num'},qty(l.accepted_qty)),
                    h('td',{className:'pr-detail-num'},qty(l.rejected_qty)),
                    h('td',{className:'pr-detail-num'},money(l.accepted_amount)));
            }):[h('tr',{key:'none'},h('td',{colSpan:6,className:'pr-detail-empty'},'No inspection lines were returned.'))];
            const printUrl='api/print.php?organization_id='+encodeURIComponent(this.state.organization_id)+
                '&type=IAR&id='+encodeURIComponent(r.id);
            return h('div',{className:'modal-backdrop',onClick:close},
                h('section',{className:'modal pr-detail-modal',role:'dialog','aria-modal':'true',
                    'aria-label':'Inspection and acceptance report details',onClick:e=>e.stopPropagation()},
                    h('header',{className:'pr-detail-header'},
                        h('div',null,h('span',{className:'pr-detail-eyebrow'},'PROCUREMENT · INSPECTION & ACCEPTANCE'),
                            h('div',{className:'pr-detail-heading'},
                                h('h2',null,r.display_number?'IAR '+r.display_number:'IAR #'+r.id),
                                h('span',{className:'status '+String(r.status||'')},String(r.status||'').replace(/_/g,' ')))),
                        h('button',{type:'button',className:'icon-button pr-detail-close',
                            'aria-label':'Close inspection report',onClick:close},'×')),
                    h('div',{className:'modal-scroll pr-detail-scroll'},
                        h('div',{className:'pr-detail-fields'},
                            field('School / Office',org?.name||'Organization #'+r.organization_id),
                            field('Purchase Order',poRecord.display_number||poFromList?.display_number||'PO record #'+r.po_id),
                            field('Supplier (current directory)',supplierName),
                            supplier?.proprietor_name?field('Proprietor (current directory)',supplier.proprietor_name):null,
                            field('Date delivered',r.delivered_at||'Not recorded'),
                            field('Date inspected / accepted',r.accepted_at||'Not recorded'),
                            field('Invoice No.',d.iar_header?.invoice_no||'Not recorded'),
                            field('IAR status',r.status||'Not specified'),
                            field('Total accepted value',money(currencyTotal))),
                        h('p',{className:'pr-detail-note'},
                            'Read-only inspection record. Delivered, accepted and rejected quantities and accepted amounts are taken from the saved IAR. Item names and supplier details are displayed from the linked PO/current reference directory, not as historical name snapshots.'),
                        d.related_po_error?h('p',{className:'pr-detail-note'},
                            d.related_po_error+' The original IAR quantities and amounts are still shown.'):null,
                        h('div',{className:'pr-detail-table-heading'},
                            h('h3',null,'Inspection and acceptance items'),
                            h('span',null,lines.length+' line'+(lines.length===1?'':'s'))),
                        h('div',{className:'table-scroll pr-detail-table-wrap'},
                            h('table',{className:'pr-detail-table'},
                                h('thead',null,h('tr',null,...['#','PO item / Description','Delivered','Accepted','Rejected','Accepted Value'].map(t=>h('th',{key:t},t)))),
                                h('tbody',null,...lineRows),
                                lines.length?h('tfoot',null,h('tr',null,
                                    h('td',{colSpan:5},'Total Value Accepted'),
                                    h('td',{className:'pr-detail-num'},money(currencyTotal)))):null)),
                        h('details',{className:'pr-detail-history'},
                            h('summary',null,'Documents and audit history · '+links.length+' links · '+versions.length+' versions · '+signed.length+' signed copies'),
                            h('div',{className:'pr-detail-history-body'},
                                h('h4',null,'Linked documents'),links.length?h('ul',null,...links.map((v,i)=>h('li',{key:i},
                                    (v.source_type||'Document')+' #'+v.source_id+' → '+(v.target_type||'Document')+' #'+v.target_id))):h('p',null,'No links recorded.'),
                                h('h4',null,'Version history'),versions.length?h('ul',null,...versions.map((v,i)=>h('li',{key:i},
                                    'Version '+v.version_no+' · '+(v.created_at||'')+' · '+(v.reason||'Finalization')))):h('p',null,'No history recorded.'),
                                h('h4',null,'Manual signed-copy attestations'),signed.length?h('ul',null,...signed.map((v,i)=>h('li',{key:i},
                                    (v.received_at||'Date unavailable')+' · '+(v.evidence_reference||'Evidence reference not provided')))):h('p',null,'No signed copies attested.')))),
                    h('footer',{className:'pr-detail-actions'},
                        h('button',{type:'button',className:'outline',onClick:close},'Close'),
                        r.status==='FINALIZED'?h('a',{className:'pr-detail-print',href:printUrl,target:'_blank',rel:'noopener'},'Open print snapshot ↗'):null)));
        };
        this.poDetailModal = () => {
            const h=React.createElement,d=this.state.details||{},r=d.record||{};
            const lines=Array.isArray(d.lines)?d.lines:[],links=Array.isArray(d.links)?d.links:[];
            const versions=Array.isArray(d.versions)?d.versions:[],signatures=Array.isArray(d.manual_signature_records)?d.manual_signature_records:[];
            const suppliers=this.state.lists.suppliers||[],catalog=this.state.lists.all_items||[];
            const suppliersById=suppliers.find(s=>String(s.id)===String(r.supplier_id));
            const inPOList=(this.state.lists.po||[]).find(p=>String(p.id)===String(r.id));
            const name=suppliersById?.name||inPOList?.supplier||'Supplier record #'+(r.supplier_id||'—');
            const poPr=(this.state.lists.pr||[]).find(pr=>String(pr.id)===String(r.pr_id));
            const poAward=(this.state.lists.awards||[]).find(a=>String(a.id)===String(r.award_id));
            const org=(this.state.orgs||[]).find(o=>String(o.id)===String(r.organization_id));
            const money=n=>Number.isFinite(Number(n))&&n!==null&&n!==''?
                '₱'+Number(n).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}):'—';
            const qty=n=>Number.isFinite(Number(n))&&n!==null&&n!==''?
                Number(n).toLocaleString('en-PH',{maximumFractionDigits:3}):'—';
            const unit=line=>{
                const item=catalog.find(i=>String(i.id)===String(line.item_id));
                const found=(this.state.lists.units||[]).find(u=>String(u.id)===String(item?.unit_id));
                return item?.unit_name||item?.unit_code||found?.code||'—';
            };
            const total=lines.every(l=>Number.isFinite(Number(l.quantity))&&Number.isFinite(Number(l.unit_price)))?
                lines.reduce((sum,l)=>sum+Math.round(Number(l.quantity)*Number(l.unit_price)*100),0)/100:null;
            const close=()=>this.setState({viewType:'',details:null});
            const field=(label,val)=>h('div',{className:'pr-detail-field',key:label},
                h('span',null,label),h('strong',null,val===null||val===undefined||val===''?'—':String(val)));
            const lineRows=lines.length?lines.map((line,index)=>{
                const item=catalog.find(i=>String(i.id)===String(line.item_id));
                return h('tr',{key:line.id||index},
                    h('td',null,index+1),
                    h('td',{className:'pr-detail-item'},item?.name||'Catalog item #'+(line.item_id||'—')),
                    h('td',null,unit(line)),
                    h('td',{className:'pr-detail-num'},qty(line.quantity)),
                    h('td',{className:'pr-detail-num'},money(line.unit_price)),
                    h('td',{className:'pr-detail-num'},money(Number(line.quantity)*Number(line.unit_price))));
            }):[h('tr',{key:'empty'},h('td',{colSpan:6,className:'pr-detail-empty'},'No PO items were returned.'))];
            const printUrl='api/print.php?organization_id='+encodeURIComponent(this.state.organization_id)+
                '&type=PO&id='+encodeURIComponent(r.id);
            return h('div',{className:'modal-backdrop',onClick:close},
                h('section',{className:'modal pr-detail-modal',role:'dialog','aria-modal':'true','aria-label':'Purchase order details',onClick:e=>e.stopPropagation()},
                    h('header',{className:'pr-detail-header'},
                        h('div',null,h('span',{className:'pr-detail-eyebrow'},'PROCUREMENT · PURCHASE ORDER'),
                            h('div',{className:'pr-detail-heading'},
                                h('h2',null,r.display_number?'PO '+r.display_number:'PO draft #'+r.id),
                                h('span',{className:'status '+String(r.status||'')},String(r.status||'').replace(/_/g,' ')))),
                        h('button',{type:'button',className:'icon-button pr-detail-close','aria-label':'Close purchase order',onClick:close},'×')),
                    h('div',{className:'modal-scroll pr-detail-scroll'},
                        h('div',{className:'pr-detail-fields'},
                            field('School / Office',org?.name||'Organization #'+r.organization_id),
                            field('Supplier (current directory)',name),
                            suppliersById?.proprietor_name?field('Proprietor (current directory)',suppliersById.proprietor_name):null,
                            field('Purchase Request',poPr?.display_number||'PR record #'+(r.pr_id||'—')),
                            field('Abstract / Award',poAward?.display_number||'Award record #'+(r.award_id||'—')),
                            field('PO status',r.status||'Not specified'),
                            versions[0]?.created_at?field('Recorded version date',versions[0].created_at):null),
                        h('p',{className:'pr-detail-note'},'This is a read-only summary of the saved Purchase Order. Quantities and unit prices come from the finalized PO. Supplier and item descriptions are shown from the current reference directory where available; the official signed PO is accessed through Print PO.'),
                        h('div',{className:'pr-detail-table-heading'},
                            h('h3',null,'Ordered items'),h('span',null,lines.length+' line'+(lines.length===1?'':'s'))),
                        h('div',{className:'table-scroll pr-detail-table-wrap'},
                            h('table',{className:'pr-detail-table'},
                                h('thead',null,h('tr',null,...['#','Item / Description','Unit','Quantity','Unit Price','Amount'].map(t=>h('th',{key:t},t)))),
                                h('tbody',null,...lineRows),
                                lines.length?h('tfoot',null,h('tr',null,
                                    h('td',{colSpan:5},'Total Purchase Order Amount'),
                                    h('td',{className:'pr-detail-num'},money(total)))):null)),
                        h('details',{className:'pr-detail-history'},
                            h('summary',null,'Documents and audit history · '+links.length+' links · '+versions.length+' versions · '+signatures.length+' signed copies'),
                            h('div',{className:'pr-detail-history-body'},
                                h('h4',null,'Linked documents'),links.length?h('ul',null,...links.map((link,i)=>h('li',{key:i},
                                    (link.source_type||'Document')+' #'+link.source_id+' → '+(link.target_type||'Document')+' #'+link.target_id))):h('p',null,'No links recorded.'),
                                h('h4',null,'Version history'),versions.length?h('ul',null,...versions.map((v,i)=>h('li',{key:i},
                                    'Version '+v.version_no+' · '+(v.created_at||'')+' · '+(v.reason||'Finalization')))):h('p',null,'No history recorded.'),
                                h('h4',null,'Manual signed-copy attestations'),signatures.length?h('ul',null,...signatures.map((v,i)=>h('li',{key:i},
                                    (v.received_at||'Date unavailable')+' · '+(v.evidence_reference||'Evidence reference not provided')))):h('p',null,'No signed copies attested.')))),
                    h('footer',{className:'pr-detail-actions'},
                        h('button',{type:'button',className:'outline',onClick:close},'Close'),
                        r.status==='FINALIZED'?h('a',{className:'pr-detail-print',href:printUrl,target:'_blank',rel:'noopener'},'Open print snapshot ↗'):null)));
        };
        this.loadPrPropertyLineage = async prId => {
            const org=String(this.state.organization_id||'');
            const stamp=org+'|'+prId;
            if(this.state.prLineageBusy===stamp||!prId||!org)return;
            this.setState({prLineage:null,prLineageBusy:stamp,prLineageError:''});
            try {
                const result=await this.get('pr.property.lineage',{organization_id:org,pr_id:String(prId)});
                // Evidence is supplementary; a missing legacy snapshot must
                // never break the existing PR->PO->IAR documentary inspector.
                try {
                    result.recorded_documentary_context=await this.get('document.property.evidence',{
                        organization_id:org,doc_type:'PR',doc_id:String(prId)});
                } catch (e) {
                    result.recorded_documentary_context={status:'REFERENCE_UNAVAILABLE',
                        review_required:true,notice:'Recorded property/UACS reference could not be verified. No approval is implied.'};
                }
                // Discard an in-flight reply when switching offices or PRs.
                if(String(this.state.organization_id)!==org||String(this.state.details?.record?.id)!==String(prId))return;
                this.setState({prLineage:result,prLineageError:''});
            } catch (err) {
                if(String(this.state.organization_id)===org && String(this.state.details?.record?.id)===String(prId))
                    this.setState({prLineageError:err.message||'Unable to load property document chain.'});
            } finally {
                if(this.state.prLineageBusy===stamp)this.setState({prLineageBusy:''});
            }
        };
        this.prPropertyLineageDetails = pr => {
            const h=React.createElement, report=this.state.prLineage;
            if(!report || String(report.pr_id)!==String(pr.id) || String(report.organization_id)!==String(this.state.organization_id))return null;
            return h('section',{className:'pr-lineage-review','aria-label':'PR property documentation chain'},
                h('h3',null,'Property document chain · read-only'),
                h('p',{className:'hint'},report.notice),
                h('p',{className:'hint'},'PR snapshot version '+(report.pr_version||'not recorded')+
                    ' · line snapshot '+(report.pr_snapshot_matches_current_lines?'matches':'requires review')+
                    ' · property/UACS decision in original document '+(report.property_uacs_decision_in_snapshot?'recorded':'not recorded')),
                report.recorded_documentary_context?h('div',{className:'pr-lineage-evidence'},
                    h('strong',null,'Version-linked property/UACS reference'),
                    h('p',{className:'hint'},report.recorded_documentary_context.status==='RECORDED_FOR_MANUAL_VERIFICATION'
                        ?'Recorded for documentary review — not signed or accounting-approved.'
                        :(report.recorded_documentary_context.notice||'Reference not recorded. Human review required.')),
                    ...(report.recorded_documentary_context.lines||[]).slice(0,120).map((ev,i)=>
                        h('p',{key:'evidence'+i,className:'hint'},
                            'PR item '+(ev.pr_line_id||'?')+' · Registry '+(ev.division_property_type?.reference_code||'not verified')+
                            ' · UACS '+(ev.uacs_code||'not verified')+(ev.review_required?' · Review required':'')))):null,
                ...(report.warnings||[]).map((warning,i)=>h('p',{key:'alert'+i,className:'pr-lineage-warning'},warning)),
                ...(report.lines||[]).map(line=>h('div',{key:'line'+line.pr_line_id,className:'pr-lineage-line'},
                    h('strong',null,line.description+' · '+line.requested_qty+' '+(line.unit||'')),
                    h('p',{className:'hint'},'PR estimate ₱'+line.estimated_unit_price+' per unit · current catalog category: '+line.current_category),
                    !(line.orders||[]).length?h('p',{className:'hint'},'No linked PO line recorded.'):null,
                    ...(line.orders||[]).map(po=>h('div',{key:'po'+po.po_line_id,className:'pr-lineage-level'},
                        h('strong',null,'PO '+(po.number||('#'+po.po_id))+' · '+po.status+' · ₱'+po.actual_unit_price+' per unit'),
                        ...(po.warnings||[]).map((w,i)=>h('p',{key:i,className:'pr-lineage-warning'},w)),
                        !(po.receipts||[]).length?h('p',{className:'hint'},'No linked inspection/acceptance record.'):null,
                        ...(po.receipts||[]).map(receipt=>h('div',{key:'iar'+receipt.iar_line_id,className:'pr-lineage-level'},
                            h('span',null,'IAR '+(receipt.number||('#'+receipt.iar_id))+' · '+receipt.status+
                                ' · accepted '+receipt.accepted_qty+' on '+receipt.accepted_at),
                            ...(receipt.assets||[]).map(asset=>h('div',{key:'asset'+asset.asset_id,className:'pr-lineage-level'},
                                h('span',null,'Property '+asset.property_tag+' · ₱'+(asset.acquisition_cost||'not recorded')),
                                ...(asset.accountability||[]).map(doc=>h('p',{key:doc.document_id,className:'hint'},
                                    doc.kind+' '+(doc.number||('#'+doc.document_id))+' · '+doc.status)),
                                ...(asset.warnings||[]).map((w,i)=>h('p',{key:i,className:'pr-lineage-warning'},w)))))))))));
        };
        this.prDetailModal = () => {
            const h=React.createElement;
            const d=this.state.details || {};
            const r=d.record || {};
            const lines=Array.isArray(d.lines)?d.lines:[];
            const links=Array.isArray(d.links)?d.links:[];
            const versions=Array.isArray(d.versions)?d.versions:[];
            const signatures=Array.isArray(d.manual_signature_records)?d.manual_signature_records:[];
            const money=x=>{
                const n=Number(x);
                return Number.isFinite(n) ? '₱'+n.toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}) : '—';
            };
            const qty=x=>{
                const n=Number(x);
                return Number.isFinite(n)?n.toLocaleString('en-PH',{maximumFractionDigits:3}):String(x??'—');
            };
            const fund=(this.state.lists.funds||[]).find(f=>String(f.id)===String(r.fund_id));
            const org=(this.state.orgs||[]).find(o=>String(o.id)===String(r.organization_id));
            const close=()=>this.setState({viewType:'',details:null,prEditOpen:false,prEdit:null,prPickerOpen:'',prPickerResults:[],prLineage:null,prLineageError:''});
            const field=(title,value)=>h('div',{className:'pr-detail-field',key:title},
                h('span',null,title),h('strong',null,value||'—'));
            const linesBody=lines.length?lines.map((line,i)=>h('tr',{key:line.id??i},
                h('td',null,i+1),
                h('td',{className:'pr-detail-item'},line.description_snapshot||'Item #'+(line.item_id||'—')),
                h('td',null,line.unit_snapshot||'—'),
                h('td',{className:'pr-detail-num'},qty(line.quantity)),
                h('td',{className:'pr-detail-num'},money(line.estimated_unit_price)),
                h('td',{className:'pr-detail-num'},money(Number(line.quantity)*Number(line.estimated_unit_price)))))
                :[h('tr',{key:'empty'},h('td',{colSpan:6,className:'pr-detail-empty'},'No items recorded'))];
            const printUrl='api/print.php?organization_id='+encodeURIComponent(this.state.organization_id)+'&type=PR&id='+encodeURIComponent(r.id);
            const excelUrl='api/pr_xlsx.php?organization_id='+encodeURIComponent(this.state.organization_id)+'&id='+encodeURIComponent(r.id);
            return h('div',{className:'modal-backdrop',onClick:close},
                h('section',{className:'modal pr-detail-modal',role:'dialog','aria-modal':'true','aria-labelledby':'pr-detail-title',onClick:e=>e.stopPropagation()},
                    h('header',{className:'pr-detail-header'},
                        h('div',null,h('span',{className:'pr-detail-eyebrow'},'PROCUREMENT · PURCHASE REQUEST'),
                            h('div',{className:'pr-detail-heading'},h('h2',{id:'pr-detail-title'},r.display_number||'Draft PR #'+r.id),
                                h('span',{className:'status '+String(r.status||'')},String(r.status||'').replace(/_/g,' ')))),
                        h('button',{type:'button',className:'icon-button pr-detail-close',onClick:close,'aria-label':'Close purchase request'},'×')),
                    h('div',{className:'modal-scroll pr-detail-scroll'},
                        h('div',{className:'pr-detail-fields'},
                            field('School / Office',org?.name||'Organization #'+r.organization_id),
                            field('Requesting unit',r.requesting_unit),
                            field('Requestor',d.requestor?d.requestor.name+' · '+d.requestor.designation:'Not recorded (legacy PR)'),
                            field('PR document date',r.document_date||'Not recorded — edit to set'),
                            field('Source of funds',fund?(fund.fiscal_year+' · '+fund.name):'Fund #'+r.fund_id),
                            field('Created',r.created_at?String(r.created_at).slice(0,16):'—'),
                            h('div',{className:'pr-detail-purpose'},h('span',null,'Purpose'),h('strong',null,r.purpose||'—'))),
                        r.status==='DRAFT'?h('p',{className:'pr-detail-note'},'Draft review only. Finalizing assigns a control number; signatures are manual. The PR remains editable until canvassing begins.'):null,
                        r.status==='FINALIZED' && d.edit_allowed ? h('p',{className:'pr-detail-note'},'This finalized PR may be corrected before canvassing begins. Its number stays the same; reprint and re-sign any previously signed copy.'):null,
                        r.status==='FINALIZED' && !d.edit_allowed ? h('p',{className:'pr-detail-note'},d.edit_block_reason||'Editing is locked because downstream records exist.'):null,
                        h('div',{className:'pr-detail-table-heading'},h('h3',null,'Requested items'),h('span',null,lines.length+' line'+(lines.length===1?'':'s'))),
                        h('div',{className:'table-scroll pr-detail-table-wrap'},
                            h('table',{className:'pr-detail-table'},
                                h('thead',null,h('tr',null,...['#','Item / Description','Unit','Qty','Est. Unit Price','Est. Amount'].map(label=>h('th',{key:label},label)))),
                                h('tbody',null,...linesBody),
                                h('tfoot',null,h('tr',null,h('td',{colSpan:5},'Estimated PR total'),h('td',{className:'pr-detail-num'},money(r.total)))))),
                        this.state.prLineageError?h('p',{role:'alert',className:'pr-lineage-warning'},this.state.prLineageError):null,
                        this.prPropertyLineageDetails(r),
                        h('details',{className:'pr-detail-history'},
                            h('summary',null,'Related documents and history · '+links.length+' links · '+versions.length+' versions · '+signatures.length+' signed copies'),
                            h('div',{className:'pr-detail-history-body'},
                                h('h4',null,'Linked documents'),links.length?h('ul',null,...links.map((x,i)=>h('li',{key:i},(x.source_type||'Document')+' #'+x.source_id+' → '+(x.target_type||'Document')+' #'+x.target_id))):h('p',null,'No linked documents yet.'),
                                h('h4',null,'Version history'),versions.length?h('ul',null,...versions.map((v,i)=>h('li',{key:i},'Version '+v.version_no+' · '+(v.created_at||'')+' · '+(v.reason||'Finalized')))):h('p',null,'No finalized versions yet.'),
                                h('h4',null,'Manual signed-copy attestations'),signatures.length?h('ul',null,...signatures.map((v,i)=>h('li',{key:i},(v.received_at||'Date unavailable')+' · '+(v.evidence_reference||'Evidence reference not provided')))):h('p',null,'No signed-copy attestations recorded.')))),
                    h('footer',{className:'pr-detail-actions'},
                        h('button',{type:'button',className:'outline',onClick:close},'Close'),
                        r.status==='FINALIZED'?h('button',{type:'button',className:'outline',disabled:this.state.prLineageBusy,onClick:()=>this.loadPrPropertyLineage(r.id)},this.state.prLineageBusy?'Checking chain…':'Review property chain'):null,
                        d.edit_allowed && this.canOperateWorkspace() ? h('button',{type:'button',className:'outline',onClick:()=>this.startEditPR(d)},r.status==='FINALIZED'?'Edit Finalized PR':'Edit Draft') : null,
                        r.status==='DRAFT' && this.canOperateWorkspace() ? h('button',{type:'button',className:'primary',disabled:this.state.busy,onClick:()=>this.finalizePR(r.id)},this.state.busy?'Processing…':'Finalize PR') : null,
                        r.status==='DRAFT'?h('span',{className:'pr-detail-print-hint'},'Print snapshot available after finalization'):
                            r.display_number?h('a',{className:'pr-detail-print',href:printUrl,target:'_blank',rel:'noopener'},'Print / Save PDF ↗'):null,
                        r.status==='FINALIZED'&&r.display_number?h('a',{className:'pr-detail-print',href:excelUrl},'Download Excel (.xlsx)'):null)));
        };
        this.prEditModal = () => {
            const h=React.createElement,e=this.state.prEdit;
            if(!e)return this.prDetailModal();
            const close=()=>this.setState({prEditOpen:false,prEdit:null,prPickerOpen:'',prPickerResults:[],prEditError:''});
            const number=(x)=>{const n=Number(x);return Number.isFinite(n)?'₱'+n.toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}):'—';};
            const sum=e.lines.reduce((total,line)=>total+(Number(line.quantity)||0)*(Number(line.unit_price)||0),0);
            const update=(field,value)=>this.setState({prEdit:{...this.state.prEdit,[field]:value},prEditError:''});
            const formField=(title,field,limit)=>h('label',{className:'field'},h('span',null,title),
                h('input',{value:e[field],maxLength:limit,onChange:event=>update(field,event.target.value),required:true}));
            return h('div',{className:'modal-backdrop',onClick:()=>{if(!this.state.busy)close();}},
                h('section',{className:'modal pr-detail-modal pr-edit-modal',role:'dialog','aria-modal':'true','aria-labelledby':'pr-edit-title',onClick:event=>event.stopPropagation()},
                    h('header',{className:'pr-detail-header'},h('div',null,h('span',{className:'pr-detail-eyebrow'},'EDIT PURCHASE REQUEST'),
                        h('h2',{id:'pr-edit-title'},e.status==='FINALIZED'?'Edit Finalized PR '+e.display_number:'Edit Draft PR #'+e.id)),
                        h('button',{type:'button',className:'icon-button pr-detail-close',disabled:this.state.busy,onClick:close,'aria-label':'Close draft editor'},'×')),
                    h('form',{className:'pr-edit-form',onSubmit:this.saveEditPR,onKeyDown:this.prInputEnterGuard},
                        h('div',{className:'modal-scroll pr-detail-scroll'},
                            h('div',{className:'pr-edit-fields'},
                                h('label',{className:'field'},h('span',null,'PR document date *'),
                                    h('input',{type:'date',required:true,min:'1900-01-01',max:localToday(),value:e.document_date,onChange:event=>update('document_date',event.target.value)}),
                                    weekendDocumentNotice(h,e.document_date,'PR document date')),
                                h('label',{className:'field'},h('span',null,'Source of funds'),
                                    h('select',{value:e.fund_id,required:true,onChange:event=>update('fund_id',event.target.value)},
                                        h('option',{value:''},'Select fund'),
                                        ...(this.state.lists.funds||[]).map(f=>h('option',{key:f.id,value:f.id},f.fiscal_year+' · '+f.name)))),
                                h('label',{className:'field'},h('span',null,'Requesting unit / department'),
                                    h('select',{value:e.requesting_unit,required:true,onChange:event=>update('requesting_unit',event.target.value)},
                                        ...(e.requesting_unit&&!this.state.prUnits.includes(e.requesting_unit)?
                                            [h('option',{key:'legacy',value:e.requesting_unit},e.requesting_unit+' · existing PR value')]:[]),
                                        ...this.state.prUnits.map(unit=>h('option',{key:unit,value:unit},unit)),
                                        !this.state.prUnits.length&&!e.requesting_unit?h('option',{value:''},'Configure departments in Reference Data'):null)),
                                h('label',{className:'field'},h('span',null,'Requestor / requesting employee'),
                                    h('select',{value:e.requestor_employee_id||'',onChange:event=>update('requestor_employee_id',event.target.value)},
                                        h('option',{value:''},e.requestor_employee_id?'Choose employee':'Not recorded (legacy PR)'),
                                        ...(e.requestor_employee_id && !(this.state.staffData?.employees||[]).some(emp=>String(emp.employee_id)===String(e.requestor_employee_id))?
                                            [h('option',{key:'historical',value:e.requestor_employee_id},e.requestor_name+' · recorded requestor')]:[]),
                                        ...(this.state.staffData?.employees||[]).filter(emp=>emp.active||String(emp.employee_id)===String(e.requestor_employee_id)).map(emp=>h('option',{key:emp.employee_id,value:emp.employee_id},emp.full_name+' · '+(emp.designation||emp.position_title||'')+(emp.active?'':' · historical'))))),
                                h('label',{className:'field pr-edit-wide'},h('span',null,'Purpose / transaction title'),
                                    h('textarea',{rows:2,maxLength:500,required:true,value:e.purpose,onChange:event=>update('purpose',event.target.value)})),
                                h('label',{className:'field'},h('span',null,'Proposed procurement route'),
                                    h('select',{value:e.method,onChange:event=>update('method',event.target.value)},
                                        ...[['REVIEW_REQUIRED','To be determined'],['RFQ_CANVASS','Canvass / RFQ'],['DIRECT_DISBURSEMENT','Direct disbursement (when allowed)']].map(([v,label])=>h('option',{key:v,value:v},label))))),
                            h('div',{className:'pr-detail-table-heading'},h('h3',null,'Requested items'),
                                h('button',{type:'button',className:'outline',onClick:()=>this.setState({prEdit:{...e,lines:[...e.lines,{item_id:'',item_label:'',item_unit:'',quantity:'1',unit_price:'0.00'}]},prPickerOpen:''})},'+ Add item')),
                            h('div',{className:'pr-compact-head pr-compact-edit-head','aria-hidden':'true'},h('span',null,'#'),h('span',null,'Catalog item'),h('span',null,'Quantity'),h('span',null,'Unit price (₱)'),h('span',null,'Amount (₱)'),h('span',null,'')),
                            ...e.lines.map((line,i)=>h('div',{className:'pr-edit-line pr-compact-line',key:i},
                                h('span',{className:'pr-compact-number'},i+1),
                                this.prItemPicker(line,i,'edit'),
                                h('label',{className:'field'},h('span',null,'Quantity'),h('input',{id:'pr-qty-edit-'+i,type:'number','aria-label':'Quantity for line '+(i+1),step:'0.001',min:'0.001',required:true,value:line.quantity,
                                    onChange:event=>this.patchPrLine('edit',i,{quantity:event.target.value}),onKeyDown:event=>this.prQuantityKeyDown(event,'edit',i)})),
                                h('label',{className:'field'},h('span',null,'Estimated unit price (₱)'),h('input',{id:'pr-price-edit-'+i,type:'number','aria-label':'Estimated unit price for line '+(i+1),step:'0.01',min:'0.01',required:!!line.item_id||Number(line.unit_price)>0||i!==e.lines.length-1,
                                    value:!line.item_id&&!(Number(line.unit_price)>0)&&i===e.lines.length-1?'':line.unit_price,
                                    onChange:event=>this.patchPrLine('edit',i,{unit_price:event.target.value}),onKeyDown:event=>this.prPriceKeyDown(event,'edit',i),onBlur:()=>{this.requestPrClassification('edit',i,line);this.appendNextPrRow(i,'edit');}})),
                                h('output',{className:'pr-compact-amount','aria-label':'Estimated amount for line '+(i+1)},number((Number(line.quantity)||0)*(Number(line.unit_price)||0))),
                                h('button',{type:'button',className:'outline pr-edit-remove',disabled:e.lines.length===1,
                                    onClick:()=>this.setState({prEdit:{...e,lines:e.lines.filter((_,n)=>n!==i)},prPickerOpen:''})},'Remove'))),
                            h('p',{className:'pr-edit-estimate'},'Updated estimated total: ',h('strong',null,number(sum))),
                            h('p',{className:'pr-start-help'},'At unit price, press Tab or Enter to continue to the next catalog item. Use Save Changes only when finished.'),
                            h('p',{className:'pr-detail-note'},e.status==='FINALIZED'?'The permanent PR number is preserved. Saving creates a recorded revision; reprint and re-sign any previously signed copy. No edits are allowed after canvassing begins.':'Changes affect this draft; finalization assigns a permanent number. Signatures remain manual.'),
                            this.state.prEditError?h('p',{role:'alert',className:'pr-edit-error'},this.state.prEditError):null),
                        h('footer',{className:'pr-detail-actions'},
                            h('button',{type:'button',className:'outline',disabled:this.state.busy,onClick:close},'Cancel'),
                            h('button',{type:'submit',className:'primary',disabled:this.state.busy},this.state.busy?'Saving…':'Save Changes')))));
        };
        this.prRegister = () => {
            const h=React.createElement;
            const allRows=this.state.lists.pr||[];
            const month=this.state.prRegisterMonth||'';
            const year=this.state.prRegisterYear||'';
            const years=[...new Set(allRows.map(r=>String(r.document_date||'').slice(0,4)).filter(v=>/^\d{4}$/.test(v)))].sort((a,b)=>b.localeCompare(a));
            const rows=allRows.filter(r=>{
                const date=String(r.document_date||'');
                return (!year||date.slice(0,4)===year)&&(!month||date.slice(5,7)===month);
            });
            const months=['January','February','March','April','May','June','July','August','September','October','November','December'];
            const heads=['Number','PR Date','Purpose','Fund','Amount','Status','Actions'].map(name=>h('th',{key:name},name));
            const bodies=rows.map(r=>{
                const actions=[h('button',{type:'button',key:'detail',className:'text-btn',onClick:()=>this.openDetail('PR',Number(r.id))},'View')];
                if((r.status==='DRAFT'||r.status==='FINALIZED')&&this.canOperateWorkspace())actions.push(h('button',{type:'button',key:'edit',className:'text-btn',onClick:()=>this.openDetail('PR',Number(r.id),true)},'Edit'));
                if(r.status==='DRAFT'&&this.canOperateWorkspace())actions.push(h('button',{type:'button',key:'finalize',className:'text-btn',disabled:this.state.busy,onClick:()=>this.finalizePR(r.id)},'Finalize'));
                if(r.status==='FINALIZED')actions.push(h('a',{key:'print',className:'text-btn',target:'_blank',rel:'noopener',href:'api/print.php?organization_id='+encodeURIComponent(this.state.organization_id)+'&type=PR&id='+encodeURIComponent(r.id)},'Print'));
                if(r.status==='FINALIZED')actions.push(h('a',{key:'excel',className:'text-btn',href:'api/pr_xlsx.php?organization_id='+encodeURIComponent(this.state.organization_id)+'&id='+encodeURIComponent(r.id)},'Excel'));
                return h('tr',{key:r.id},h('td',null,r.display_number||'Draft #'+r.id),h('td',null,r.document_date||'—'),h('td',null,r.purpose),h('td',null,r.fund_name),h('td',null,'₱'+r.total),h('td',null,h('span',{className:'status '+r.status},r.status)),h('td',null,h('div',{className:'inline-actions'},...actions)));
            });
            if(!bodies.length)bodies.push(h('tr',{key:'empty'},h('td',{colSpan:7,className:'empty'},allRows.length?'No purchase requests match the selected month and year.':'No purchase requests yet')));
            return h('div',{className:'surface'},
                h('div',{className:'section-head'},
                    h('h3',null,'Purchase Request Register'),
                    h('div',{className:'inline-actions',style:{display:'flex',gap:'8px',flexWrap:'wrap',alignItems:'center',justifyContent:'flex-end'}},
                        h('label',{className:'field',style:{margin:0,minWidth:'120px'}},
                            h('span',null,'Month'),
                            h('select',{'aria-label':'Filter PR register by month',value:month,
                                onChange:e=>this.setState({prRegisterMonth:e.target.value})},
                                h('option',{value:''},'All months'),
                                ...months.map((name,i)=>h('option',{key:i,value:String(i+1).padStart(2,'0')},name)))),
                        h('label',{className:'field',style:{margin:0,minWidth:'95px'}},
                            h('span',null,'Year'),
                            h('select',{'aria-label':'Filter PR register by year',value:year,
                                onChange:e=>this.setState({prRegisterYear:e.target.value})},
                                h('option',{value:''},'All years'),
                                ...years.map(y=>h('option',{key:y,value:y},y)))),
                        h('span',{className:'count','aria-live':'polite'},rows.length===allRows.length?allRows.length+' PRs':rows.length+' of '+allRows.length+' PRs'))),
                h('div',{className:'table-scroll'},h('table',{className:'master-table'},h('thead',null,h('tr',null,...heads)),h('tbody',null,...bodies))));
        };
        // Guided forms replace manual JSON entry for core procurement documents.
        this.workflowBeginPoEdit = async id => {
            const org=String(this.state.organization_id);
            this.setState({workflowPoEdit:null,workflowPoEditError:'',workflowPoEditLoading:true});
            try {
                const data=await this.get('po.document.edit',{po_id:String(id),organization_id:org});
                if(String(this.state.organization_id)!==org)return;
                this.setState({workflowPoEdit:{...data,reason:''},workflowPoEditError:'',workflowPoEditLoading:false});
            }catch(e){if(String(this.state.organization_id)===org)this.setState({workflowPoEditError:e.message,workflowPoEditLoading:false});}
        };
        this.workflowSavePoEdit = async e => {
            e.preventDefault();const edit=this.state.workflowPoEdit;
            if(!edit||this.state.busy)return;
            const org=String(this.state.organization_id);
            const lines=(edit.lines||[]).filter(x=>x.included).map(x=>({pr_line_id:Number(x.pr_line_id),
                quantity:String(x.quantity),unit_price:String(x.unit_price)}));
            const data={organization_id:Number(org),po_id:Number(edit.po_id),expected_revision:Number(edit.revision),
                document_date:edit.document_date,supplier_profile_sha256:edit.supplier_profile_sha256,
                lines,
                delivery_date:edit.delivery_date,delivery_place:edit.delivery_place,delivery_term:edit.delivery_term,
                payment_term:edit.payment_term,payment_type:edit.payment_type,procurement_method:edit.procurement_method,
                reason:(edit.reason||'').trim()};
            if(!lines.length){this.setState({workflowPoEditError:'Select at least one PO item.'});return;}
            if(!validPrDate(data.document_date)||data.document_date>localToday()||
                data.document_date<(edit.min_document_date||'')){
                this.setState({workflowPoEditError:'PO date must be valid, not future and not earlier than the linked award date.'});return;
            }
            if(!validPrDate(data.delivery_date)||data.delivery_date<data.document_date){
                this.setState({workflowPoEditError:'Expected delivery date cannot precede the corrected PO date.'});return;
            }
            this.setState({busy:true,workflowPoEditError:'',error:''});
            let result;
            try {result=await this.api('po.document.correct',data);}
            catch(error){if(String(this.state.organization_id)===org)this.setState({workflowPoEditError:error.message});return;}
            finally {this.setState({busy:false});}
            if(!result||String(this.state.organization_id)!==org)return;
            this.setState({workflowPoEdit:null,workflowPoEditError:'',workflowMessage:'PO corrected with a recorded revision. Reprint to review all updated items, date and terms.'});
            await this.workflowReload();
        };
        this.workflowLoadIarEdit = async id => {
            const org=String(this.state.organization_id);
            this.setState({workflowIarEdit:null,workflowIarEditLoading:true,workflowIarEditError:''});
            try {
                const data=await this.get('iar.document.edit',{iar_id:String(id)});
                if(String(this.state.organization_id)!==org)return;
                this.setState({workflowIarEdit:{...data,reason:''},workflowIarEditLoading:false});
            } catch(e){if(String(this.state.organization_id)===org)this.setState({workflowIarEditLoading:false,workflowIarEditError:e.message});}
        };
        this.workflowSaveIarEdit = async e => {
            e.preventDefault();const edit=this.state.workflowIarEdit;
            if(!edit||this.state.busy)return;
            const org=String(this.state.organization_id);
            if(!validPrDate(edit.delivered_at)||!validPrDate(edit.accepted_at)||edit.accepted_at<edit.delivered_at||
                edit.accepted_at>localToday()||edit.delivered_at>localToday()){
                this.setState({workflowIarEditError:'Check the delivery and inspection dates, including their chronological order.'});return;
            }
            const data={organization_id:Number(org),iar_id:Number(edit.iar_id),expected_version:Number(edit.version),
                delivered_at:edit.delivered_at,accepted_at:edit.accepted_at,invoice_no:edit.invoice_no,invoice_date:edit.invoice_date||'',reason:(edit.reason||'').trim()};
            this.setState({busy:true,workflowIarEditError:''});
            let result;
            try{result=await this.api('iar.document.correct',data);}
            catch(error){if(String(this.state.organization_id)===org)this.setState({workflowIarEditError:error.message});return;}
            finally{this.setState({busy:false});}
            if(!result||String(this.state.organization_id)!==org)return;
            this.setState({workflowIarEdit:null,workflowIarEditError:'',workflowMessage:'IAR '+result.number+' corrected in document version '+result.version+'. No receipt quantities or stock entries were changed.'});
            await this.workflowReload();
        };
        this.workflowReload = async () => {
            const org=String(this.state.organization_id);
            // Keep PR purpose and RFQ numbers in the same organization-scoped refresh.
            const kinds=['pr','rfq','quotes','awards','po','iar'];
            const pairs=await Promise.allSettled(kinds.map(kind=>this.get('list',{kind,organization_id:org})));
            if(String(this.state.organization_id)!==org)return;
            const updated={...this.state.lists};
            const errors=[];
            kinds.forEach((kind,i)=>{if(pairs[i].status==='fulfilled')updated[kind]=pairs[i].value;else errors.push(kind);});
            this.setState({lists:updated,workflowError:errors.length?'Some procurement lists could not load ('+errors.join(', ')+'). Use Refresh process to try again.':''});
        };
        this.workflowSetStage = stage => {
            this.setState({workflowStage:stage,workflowFormOpen:false,workflowPoEdit:null,workflowPoEditError:'',workflowIarEdit:null,workflowIarEditError:'',workflowError:'',workflowMessage:'',workflowRFQEditId:'',workflowRFQEditLines:[],workflowRFQEditVersion:0,workflowRFQEditError:''},()=>this.workflowReload());
        };
        this.workflowLoadPR = async id => {
            this.setState({workflowPR:id,workflowRfqLines:[],workflowRfqDate:'',workflowCanvasserId:'',workflowCanvasserHistoricalAttested:false,workflowCanvasserHistoricalReference:'',workflowChairHistoricalAttested:false,workflowChairHistoricalReference:'',workflowError:'',workflowConsolidate:null,workflowConsolidateLoading:!!id,workflowConsolidateError:'',workflowConsolidatePrIds:id?[String(id)]:[],workflowConsolidateExcluded:[]});if(!id)return;
            const org=String(this.state.organization_id);
            try {const [d,candidates]=await Promise.all([this.get('detail',{type:'PR',id:String(id)}),this.get('workflow.rfq_candidates',{pr_id:String(id)})]);
                if(this.state.organization_id!==org||String(this.state.workflowPR)!==String(id))return;
                const prDate=String(d.record?.document_date||'');
                if(!/^\d{4}-\d{2}-\d{2}$/.test(prDate))throw Error('The finalized PR has no valid document date. Ask an administrator to review it.');
                this.setState({workflowRfqDate:prDate,workflowConsolidate:candidates,workflowConsolidateLoading:false,workflowRfqLines:(d.lines||[]).map(l=>({pr_line_id:Number(l.id),item_name:l.description_snapshot,unit:l.unit_snapshot,pr_quantity:Number(l.quantity),quantity:String(l.quantity),included:true}))});
            }catch(e){this.setState({workflowError:'Could not load PR items: '+e.message,workflowConsolidateLoading:false,workflowConsolidateError:e.message});}
        };
        this.workflowLoadRFQEdit = async id => {
            this.setState({workflowStage:'RFQ',workflowFormOpen:true,workflowRFQEditId:String(id),workflowRFQEditDate:'',workflowRFQEditLines:[],
                workflowRFQEditVersion:0,workflowRFQEditReason:'',workflowRFQEditConfirm:false,workflowRFQEditError:'',workflowRFQEditLoading:true});
            const org=String(this.state.organization_id);
            try {
                const details=await this.get('workflow.rfq_lines',{rfq_id:String(id)});
                if(!details.edit?.allowed)throw Error(details.edit?.reason||'This RFQ is locked.');
                const source=await this.get('detail',{type:'PR',id:String(details.rfq.pr_id)});
                if(String(this.state.organization_id)!==org||String(this.state.workflowRFQEditId)!==String(id))return;
                const selected=new Map((details.lines||[]).map(l=>[String(l.pr_line_id),l]));
                this.setState({workflowRFQEditDate:String(details.rfq.issued_at||''),
                    workflowRFQEditVersion:Number(details.edit.expected_version),
                    workflowRFQEditLines:(source.lines||[]).map(l=>{
                        const chosen=selected.get(String(l.id));
                        return {pr_line_id:Number(l.id),item_name:l.description_snapshot,unit:l.unit_snapshot,
                            pr_quantity:Number(l.quantity),quantity:String(chosen?.quantity||l.quantity),included:!!chosen};
                    }),workflowRFQEditError:''});
            } catch(e){this.setState({workflowRFQEditError:e.message});}
            finally {this.setState({workflowRFQEditLoading:false});}
        };
        this.workflowPatchRFQEditLine = (index,prop,value) => {
            this.setState({workflowRFQEditLines:this.state.workflowRFQEditLines.map((l,i)=>i===index?{...l,[prop]:value}:l),workflowRFQEditError:''});
        };
        this.workflowSaveRFQEdit = async e => {
            e.preventDefault();if(this.state.busy)return;
            const s=this.state;
            if(!this.canOperateWorkspace()){this.setState({workflowRFQEditError:'Read-only workspace.'});return;}
            const lines=s.workflowRFQEditLines.filter(l=>l.included);
            if(!/^\d{4}-\d{2}-\d{2}$/.test(s.workflowRFQEditDate) || !lines.length ||
                lines.some(l=>!(Number(l.quantity)>0)||Number(l.quantity)>Number(l.pr_quantity))){
                this.setState({workflowRFQEditError:'Check the date and positive RFQ quantities against the original PR.'});return;
            }
            if(!s.workflowRFQEditReason.trim()){this.setState({workflowRFQEditError:'Enter the reason for correction.'});return;}
            if(!s.workflowRFQEditConfirm){this.setState({workflowRFQEditError:'Confirm that the RFQ has not been distributed to suppliers.'});return;}
            if(!window.confirm('Correct this RFQ using its existing number? Earlier versions remain in the audit history. The eight-hour deadline does not restart.'))return;
            const org=String(s.organization_id),id=String(s.workflowRFQEditId);
            const result=await this.task('rfq.update',{rfq_id:Number(id),issued_at:s.workflowRFQEditDate,
                expected_version:s.workflowRFQEditVersion,reason:s.workflowRFQEditReason.trim(),
                not_distributed_confirmed:!!s.workflowRFQEditConfirm,
                lines:lines.map(l=>({pr_line_id:l.pr_line_id,quantity:l.quantity}))},{refresh:false});
            if(!result){this.setState({workflowRFQEditError:this.state.error||'RFQ could not be corrected.'});return;}
            if(String(this.state.organization_id)!==org)return;
            this.setState({workflowFormOpen:false,workflowRFQEditId:'',workflowRFQEditLines:[],workflowRFQEditReason:'',workflowRFQEditConfirm:false,
                workflowMessage:'RFQ '+(result.number||id)+' updated as version '+result.version+'. Existing control number retained.',workflowError:''});
            await this.workflowReload();
        };
        this.workflowLoadRFQ = async id => {
            this.setState({workflowRFQ:id,workflowSupplier:'',workflowQuoteEvidence:'',workflowQuoteLines:[],workflowError:''});
            if(!id)return;
            const org=String(this.state.organization_id);
            const r=(this.state.lists.rfq||[]).find(r=>String(r.id)===String(id));
            if(!r)return;
            try{
                const d=await this.get('workflow.rfq_lines',{rfq_id:String(id)});
                if(this.state.organization_id!==org||String(this.state.workflowRFQ)!==String(id))return;
                this.setState({workflowQuoteLines:(d.lines||[]).map(x=>({pr_line_id:Number(x.pr_line_id),item_name:x.item_name,unit:x.unit,quantity:x.quantity,price:'',compliant:true}))});
            }catch(e){this.setState({workflowError:'Unable to load PR items: '+e.message});}
        };
        // Populate new PO delivery address from the currently selected SCHOOL,
        // never a previously selected school's data or a fixed template address.
        // Organization Hierarchy may contain a fresher configured address than
        // auth.session; an empty address remains empty for manual completion.
        this.workflowDefaultDeliveryPlace = () => {
            const workspace=this.state.orgs.find(o=>String(o.id)===String(this.state.organization_id));
            if(!workspace || workspace.kind!=='SCHOOL')return '';
            const fresh=(this.state.orgHierarchy||[]).find(o=>String(o.id)===String(workspace.id) && o.kind==='SCHOOL');
            return String(fresh?.address ?? workspace.address ?? '').trim();
        };
        this.workflowLoadAward = async id => {
            this.setState({workflowAward:id,workflowPoLines:[],workflowError:''});
            if(!id)return;
            const org=String(this.state.organization_id);
            const a=(this.state.lists.awards||[]).find(x=>String(x.id)===String(id));
            if(!a)return;
            if(a.existing_po_id){
                this.setState({workflowAward:'',workflowPoLines:[],workflowError:'This bidder already has Purchase Order '+(a.existing_po_number||'#'+a.existing_po_id)+'. Open the existing PO in the register; a second PO is not allowed.'});
                return;
            }
            try{
                const d=await this.get('detail',{type:'PR',id:String(a.pr_id)});
                const matching=(this.state.lists.quotes||[]).find(x=>String(x.rfq_id)===String(a.rfq_id)&&String(x.supplier_id)===String(a.supplier_id));
                if(!matching)throw Error('The supplier quote could not be found. Refresh the process lists before issuing the PO.');
                const q=await this.get('workflow.quote',{quote_id:String(matching.id)});
                if(this.state.organization_id!==org||String(this.state.workflowAward)!==String(id))return;
                this.setState({workflowPoLines:(d.lines||[]).map(l=>{
                    const offered=(q.lines||[]).find(x=>String(x.pr_line_id)===String(l.id));
                    return {pr_line_id:Number(l.id),item_name:l.description_snapshot,unit:l.unit_snapshot,quantity:String(offered?offered.quantity:l.quantity),rfq_quantity:Number(offered?offered.quantity:l.quantity),unit_price:offered?String(offered.offered_price):'',included:!!offered&&Number(offered.compliant)===1};
                })});
            }catch(e){this.setState({workflowError:'Unable to load award items: '+e.message});}
        };
        this.workflowLoadPO = async id => {
            this.setState({workflowPO:id,workflowIARLines:[],workflowDelivered:'',workflowAccepted:'',workflowIARInvoice:'',workflowIARInvoiceDate:'',workflowIARPoMeta:null,workflowIarDate:localToday(),workflowIARReviewed:false,workflowError:''});
            if(!id)return;
            const org=String(this.state.organization_id);
            try{
                const result=await this.get('workflow.po_lines',{po_id:String(id)});
                if(this.state.organization_id!==org||String(this.state.workflowPO)!==String(id))return;
                const poFromRegister=(this.state.lists.po||[]).find(p=>String(p.id)===String(id));
                // Prefer the effective document date from the scoped PO register;
                // never invent an official date from the creation timestamp.
                const poDay=String(poFromRegister?.document_date||result.po?.document_date||'').slice(0,10);
                const validDay=validPrDate(poDay)&&poDay<=localToday()?poDay:'';
                this.setState({
                    workflowDelivered:validDay,workflowAccepted:validDay,workflowIARInvoice:'',workflowIARInvoiceDate:'',
                    workflowIARPoMeta:result.document_meta||null,workflowIARReviewed:false,
                    workflowIARLines:(result.lines||[]).map(l=>{
                        // Same three-decimal precision as the backend quantity ledger.
                        const milliqty=Math.max(0,Math.round(Number(l.quantity)*1000)-Math.round(Number(l.delivered_to_date)*1000));
                        const remaining=milliqty/1000;
                        return {po_line_id:Number(l.po_line_id),item_name:l.item_name,unit:l.unit,ordered:l.quantity,
                            delivered_to_date:l.delivered_to_date,remaining,
                            delivered_qty:String(remaining),accepted_qty:String(remaining)};
                    })
                });
            }catch(e){if(this.state.organization_id===org&&String(this.state.workflowPO)===String(id))
                this.workflowShowError('Unable to load PO items: '+e.message);}
        };
        this.workflowPatchLine = (kind,index,prop,value) => {
            const k={rfq:'workflowRfqLines',quote:'workflowQuoteLines',po:'workflowPoLines',iar:'workflowIARLines'}[kind];
            if(!k)return;
            this.setState({[k]:this.state[k].map((line,i)=>i===index?{...line,[prop]:value}:line),workflowIARReviewed:kind==='iar'?false:this.state.workflowIARReviewed,workflowError:''});
        };
        this.workflowSubmit = async (e,stage) => {
            e.preventDefault();if(this.state.busy)return;
            if(!this.canOperateWorkspace()){this.setState({workflowError:'Read-only oversight. Use the owning organization to prepare procurement documents.'});return;}
            let route='',data={},next='';
            const positive=v=>Number.isFinite(Number(v))&&Number(v)>0;
            try{
                if(stage==='RFQ'){
                    if(!this.state.workflowPR)throw Error('Select a finalized Purchase Request.');
                    if((this.state.workflowConsolidatePrIds||[]).some(id=>String(id)!==String(this.state.workflowPR)))
                        throw Error('Added PRs are preview-only in this version. Remove the additional PRs before creating a single-PR RFQ.');
                    if((this.state.lists.rfq||[]).some(r=>String(r.pr_id)===String(this.state.workflowPR)))throw Error('This PR already has an RFQ. Reprint the existing RFQ and record quotations from each supplier.');
                    if(!/^\d{4}-\d{2}-\d{2}$/.test(this.state.workflowRfqDate))throw Error('Select an RFQ date. It defaults to the linked PR date.');
                    const lines=this.state.workflowRfqLines.filter(l=>l.included);
                    if(this.state.staffLoading||String(this.state.staffData?.organization?.id||'')!==String(this.state.organization_id))
                        throw Error('School Employee Directory is not ready. Please wait or use Refresh employees and BAC.');
                    const canvasser=(this.state.staffData?.employees||[]).find(e=>e.active&&String(e.employee_id)===String(this.state.workflowCanvasserId));
                    const chair=(this.state.staffData?.employees||[]).find(e=>e.active&&String(e.employee_id)===String(this.state.staffData?.bac?.chair||''));
                    if(!canvasser)throw Error('Select an active canvasser from the Employee Directory.');
                    if(!chair)throw Error('Configure the BAC Chairperson under Reference Data → BAC & Heads before creating an RFQ.');
                    const canvasserHistoric=rfqHistoricalParticipant(this.state.staffData,canvasser.employee_id,this.state.workflowRfqDate);
                    const chairHistoric=rfqHistoricalParticipant(this.state.staffData,chair.employee_id,this.state.workflowRfqDate);
                    if(canvasserHistoric.needed && (!s.workflowCanvasserHistoricalAttested||[...s.workflowCanvasserHistoricalReference.trim()].length<20))
                        throw Error('For an earlier canvasser designation, confirm the actual supporting appointment/order and enter at least 20 characters.');
                    if(chairHistoric.needed && (!s.workflowChairHistoricalAttested||[...s.workflowChairHistoricalReference.trim()].length<20))
                        throw Error('For an earlier BAC Chairperson designation, confirm the actual supporting appointment/order and enter at least 20 characters.');
                    if(!lines.length)throw Error('Include at least one RFQ item.');
                    if(lines.some(l=>!positive(l.quantity)||Number(l.quantity)>Number(l.pr_quantity)))throw Error('RFQ quantities must be positive and may not exceed the PR quantities.');
                    if(!window.confirm('Create one numbered RFQ dated '+this.state.workflowRfqDate+' for this PR? This same RFQ can be reprinted for any number of suppliers.'))return;
                    route='rfq.create';data={pr_id:Number(this.state.workflowPR),issued_at:this.state.workflowRfqDate,
                        canvasser_employee_id:Number(canvasser.employee_id),bac_chair_employee_id:Number(chair.employee_id),
                        historical_canvasser_attested:canvasserHistoric.needed&&!!s.workflowCanvasserHistoricalAttested,
                        historical_canvasser_reference:canvasserHistoric.needed?s.workflowCanvasserHistoricalReference.trim():'',
                        historical_chair_attested:chairHistoric.needed&&!!s.workflowChairHistoricalAttested,
                        historical_chair_reference:chairHistoric.needed?s.workflowChairHistoricalReference.trim():'',
                        lines:lines.map(l=>({pr_line_id:l.pr_line_id,quantity:l.quantity}))};next='QUOTES';
                }else if(stage==='QUOTES'){
                    if(!this.state.workflowRFQ||!this.state.workflowSupplier)throw Error('Choose an RFQ and an active supplier.');
                    const lines=this.state.workflowQuoteLines;
                    if(!lines.length||lines.some(l=>!positive(l.price)))throw Error('Enter a positive offered unit price for every item.');
                    if(!this.state.workflowQuoteEvidence.trim())throw Error('Enter the supplier quotation or evidence reference.');
                    if((this.state.lists.quotes||[]).some(x=>String(x.rfq_id)===String(this.state.workflowRFQ)&&String(x.supplier_id)===String(this.state.workflowSupplier)))throw Error('This supplier already has a quotation for this RFQ. Do not enter a duplicate.');
                    const rfqDate=(this.state.lists.rfq||[]).find(r=>String(r.id)===String(this.state.workflowRFQ))?.issued_at?.slice(0,10);
                    if(!validPrDate(this.state.workflowQuoteDate)||this.state.workflowQuoteDate>localToday())throw Error('Select a valid, non-future Supplier Quotation date.');
                    if(rfqDate&&this.state.workflowQuoteDate<rfqDate)throw Error('Supplier Quotation date cannot precede the selected RFQ date.');
                    route='quote.create';data={rfq_id:Number(this.state.workflowRFQ),supplier_id:Number(this.state.workflowSupplier),offered_at:this.state.workflowQuoteDate,evidence_note:this.state.workflowQuoteEvidence.trim(),lines:lines.map(l=>({pr_line_id:l.pr_line_id,price:l.price,compliant:!!l.compliant}))};next='QUOTES';
                }else if(stage==='AWARD'){
                    const eligible=(this.state.lists.quotes||[]).filter(x=>String(x.rfq_id)===String(this.state.workflowAwardRFQ)&&Number(x.line_count)>0&&Number(x.noncompliant_lines)===0).sort((a,b)=>Number(a.offered_total)-Number(b.offered_total)||Number(a.id)-Number(b.id));
                    // Preserve the original lowest-compliant-price benchmark even
                    // when that supplier already has an award. A different winning
                    // bidder is permitted, but not a second award to the same one.
                    const available=eligible.filter(x=>Number(x.already_awarded)!==1);
                    const selected=this.state.workflowQuoteId||String(available[0]?.id||'');
                    const q=available.find(x=>String(x.id)===String(selected));
                    if(!q)throw Error('Select an eligible supplier who has not already been awarded this RFQ.');
                    const awardNote=(this.state.workflowAwardNoteManual?this.state.workflowAwardNote:recommendedAwardNote(eligible,selected)).trim();
                    if(!awardNote)throw Error('Record the reason for the award determination.');
                    if(!this.state.workflowAwardApproved)throw Error('Confirm you have reviewed the quotations for documentation and manual signing.');
                    const isHigher=Number(q.offered_total)>Number(eligible[0].offered_total)+0.000001;
                    if(isHigher&&awardNote.length<20)throw Error('Explain in at least 20 characters why a higher-priced compliant offer is being selected.');
                    if(!window.confirm(isHigher?'This supplier is not the lowest compliant offer. Record a documented exception and number the Abstract/Award?':'Record the lowest compliant quotation as the default proposed winner? A signed Abstract/Award is handled manually.'))return;
                    if(!/^\d{4}-\d{2}-\d{2}$/.test(this.state.workflowAwardDate))throw Error('Select an Abstract / BAC Resolution date.');
                    const linkedRfq=(this.state.lists.rfq||[]).find(r=>String(r.id)===String(q.rfq_id));
                    if(linkedRfq?.issued_at && this.state.workflowAwardDate<String(linkedRfq.issued_at).slice(0,10))throw Error('Resolution date cannot precede RFQ issuance.');
                    if(this.state.workflowAwardDate>localToday())throw Error('Resolution date cannot be in the future.');
                    const latestQuoteDate=(this.state.lists.quotes||[]).filter(x=>String(x.rfq_id)===String(q.rfq_id)).map(x=>String(x.offered_at||'').slice(0,10)).filter(validPrDate).sort().pop();
                    if(latestQuoteDate&&this.state.workflowAwardDate<latestQuoteDate)throw Error('Resolution date cannot precede recorded supplier quotations.');
                    route='award.create';data={pr_id:Number(q.pr_id),rfq_id:Number(q.rfq_id),supplier_id:Number(q.supplier_id),
                        resolution_date:this.state.workflowAwardDate,determination_note:awardNote,higher_bid_exception:isHigher};next='PO';
                }else if(stage==='PO'){
                    if(!this.state.workflowAward)throw Error('Select a finalized award.');
                    const alreadyOrdered=(this.state.lists.awards||[]).find(a=>String(a.id)===String(this.state.workflowAward));
                    if(alreadyOrdered?.existing_po_id)throw Error('This bidder already has Purchase Order '+(alreadyOrdered.existing_po_number||'#'+alreadyOrdered.existing_po_id)+'. Open the existing PO instead of creating another.');
                    const lines=this.state.workflowPoLines.filter(l=>l.included);
                    if(!lines.length||lines.some(l=>!positive(l.quantity)||!positive(l.unit_price)||Number(l.quantity)>Number(l.rfq_quantity)))throw Error('Include at least one item and enter positive quantities and prices.');
                    if(!window.confirm('Issue a numbered Purchase Order for the selected award? Check that the required manual approval was obtained.'))return;
                    const selectedAward=(this.state.lists.awards||[]).find(a=>String(a.id)===String(this.state.workflowAward));
                    const minAward=String(selectedAward?.resolution_date||selectedAward?.recorded_at||'').slice(0,10);
                    if(!validPrDate(this.state.workflowPoDate)||this.state.workflowPoDate>localToday())throw Error('Select a valid, non-future Purchase Order date.');
                    if(minAward&&this.state.workflowPoDate<minAward)throw Error('PO date cannot precede the linked Abstract / Award date.');
                    if(!validPrDate(this.state.workflowPoDeliveryDate)||this.state.workflowPoDeliveryDate<this.state.workflowPoDate)
                        throw Error('Expected delivery date must be on or after the PO date.');
                    if(!this.state.workflowPoDeliveryPlace.trim())throw Error('Enter the place of delivery.');
                    if(!this.state.workflowPoMethod)throw Error('Select the documented mode of procurement.');
                    route='po.create';data={award_id:Number(this.state.workflowAward),document_date:this.state.workflowPoDate,
                        delivery_date:this.state.workflowPoDeliveryDate,delivery_place:this.state.workflowPoDeliveryPlace.trim(),
                        delivery_term:this.state.workflowPoDeliveryTerm,payment_term:this.state.workflowPoPaymentTerm,
                        payment_type:this.state.workflowPoPaymentType,procurement_method:this.state.workflowPoMethod,
                        lines:lines.map(l=>({pr_line_id:l.pr_line_id,quantity:l.quantity,unit_price:l.unit_price}))};next='IAR';
                }else if(stage==='IAR'){
                    if(!this.state.workflowPO)throw Error('Select the Purchase Order.');
                    if(this.state.workflowIARInvoiceDate && (!validPrDate(this.state.workflowIARInvoiceDate)||this.state.workflowIARInvoiceDate>this.state.workflowIarDate))
                        throw Error('Supplier Invoice Date cannot be after the IAR Date.');
                    if(this.state.workflowIARReviewed!==true)throw Error('Review the actual delivery and inspection details, then check the confirmation box before finalizing.');
                    if(!this.state.workflowDelivered||!this.state.workflowAccepted)throw Error('Enter actual delivery and acceptance dates.');
                    if(this.state.workflowAccepted<this.state.workflowDelivered)throw Error('Acceptance date cannot precede delivery date.');
                    if(!validPrDate(this.state.workflowIarDate)||this.state.workflowIarDate<this.state.workflowAccepted||this.state.workflowIarDate>localToday())
                        throw Error('IAR document date must be between inspection date and today.');
                    const precedingPo=(this.state.lists.po||[]).find(r=>String(r.id)===String(this.state.workflowPO));
                    const minPo=String(precedingPo?.document_date||'').slice(0,10);
                    if(minPo&&this.state.workflowDelivered<minPo)throw Error('Delivery date cannot precede the Purchase Order date.');
                    const lines=this.state.workflowIARLines.filter(l=>Number(l.delivered_qty)>0);
                    if(!lines.length)throw Error('Enter the actually delivered quantity for at least one item.');
                    if(lines.some(l=>Number(l.delivered_qty)>Number(l.remaining)||Number(l.accepted_qty)<0||Number(l.accepted_qty)>Number(l.delivered_qty)))throw Error('Check delivered and accepted quantities. They cannot exceed the PO balance.');
                    if(!window.confirm('Finalize this IAR for the accepted quantities? Eligible PPE/semi-expendable physical units will also receive permanent asset numbers automatically (up to 100 whole units per receipt), but must be reviewed before ICS/PAR. Consumable stock remains separate.'))return;
                    route='iar.create';data={po_id:Number(this.state.workflowPO),iar_date:this.state.workflowIarDate,delivered_at:this.state.workflowDelivered,accepted_at:this.state.workflowAccepted,invoice_no:this.state.workflowIARInvoice,invoice_date:this.state.workflowIARInvoiceDate,lines:lines.map(l=>({po_line_id:l.po_line_id,delivered_qty:l.delivered_qty,accepted_qty:l.accepted_qty})),review_confirmed:true};next='IAR';
                }else return;
            }catch(error){this.workflowShowError(error.message);return;}
            // Never automatically repeat write requests: server idempotency keys prevent duplicates.
            // A successful quotation save intentionally does NOT advance to Abstract:
            // the operator may record further suppliers on the SAME RFQ.
            const submittedOrg=String(this.state.organization_id);
            const submittedRFQ=stage==='QUOTES'?String(this.state.workflowRFQ):'';
            const submittedSupplier=stage==='QUOTES'?(this.state.lists.suppliers||[]).find(x=>String(x.id)===String(this.state.workflowSupplier)):null;
            const result=await this.task(route,data,{refresh:false,errorTarget:'workflow'});
            if(!result||String(this.state.organization_id)!==submittedOrg)return;
            await this.workflowReload();
            if(String(this.state.organization_id)!==submittedOrg)return;
            if(stage==='QUOTES'){
                // Keep the chosen RFQ, reset supplier-specific entry fields, and
                // clear every quoted price so a previous supplier's offer cannot
                // accidentally be copied into the next supplier's quotation.
                this.setState({workflowStage:'QUOTES',workflowRFQ:submittedRFQ,workflowSupplier:'',
                    workflowQuoteEvidence:'',workflowQuoteLines:this.state.workflowQuoteLines.map(l=>({...l,price:'',compliant:true})),
                    workflowAwardRFQ:submittedRFQ,workflowQuoteId:'',workflowAwardNote:'',workflowAwardNoteManual:false,
                    workflowAwardApproved:false,workflowError:'',workflowMessage:'Quotation from '+(submittedSupplier?.name||'supplier')+
                    ' saved. You can enter another supplier quotation for this RFQ, or choose Prepare Abstract / Award when ready.'});
                return;
            }
            this.setState({workflowFormOpen:false,workflowMessage:(result.number?'Document '+result.number:'Record #'+result.id)+' saved successfully.'+(stage==='IAR'&&Number(result.auto_registered_property_units)>0?' '+result.auto_registered_property_units+' physical property unit(s) were registered with permanent tags. Review them under Property Accountability → Review Newly Received before ICS/PAR.':'')+(stage==='IAR'&&(result.deferred_property_iar_lines||[]).length?' Some oversized or fractional accepted property lines need the earlier IAR batch registration process.':'')+' Select the next process tab when ready.',workflowError:''});
            if(stage==='RFQ'&&result.id)this.workflowLoadRFQ(result.id);
            if(stage==='AWARD'&&result.id)this.workflowLoadAward(result.id);
            if(stage==='PO'&&result.id)this.workflowLoadPO(result.id);
            // Keep the document register visible after saving; navigation to the next stage is deliberate.
        };
        // Preview is deliberately read-only for added PRs until the multi-PR
        // claim ledger and one-Award/one-PO server contracts are installed.
        this.workflowAddConsolidationPR = () => {
            const h=React.createElement,s=this.state,c=s.workflowConsolidate;
            if(!s.workflowPR)return null;
            const selected=new Set((s.workflowConsolidatePrIds||[]).map(String));
            const others=(c?.prs||[]).filter(p=>Number(p.eligible_count)>0&&
                String(p.id)!==String(s.workflowPR));
            const choices=others.filter(p=>!selected.has(String(p.id)));
            // Hide Add PR entirely when this workspace/fund has no additional
            // eligible finalized PRs; still show already-added PRs for removal.
            // Never render an empty or disabled second PR selector.
            if(!others.length&&!s.workflowConsolidateLoading&&!s.workflowConsolidateError)return null;
            return h('div',{className:'workflow-form',style:{padding:'12px',margin:'8px 0 16px',background:'var(--surface,#f6fafb)',border:'1px solid var(--border,#dce7eb)',borderRadius:8},'aria-label':'Add PRs for consolidated RFQ'},
                h('strong',null,'Additional Purchase Requests'),
                h('p',{className:'workflow-hint'},'Add finalized PRs from another department. Only unused items with the SAME source of funds as the first PR can be added.'),
                s.workflowConsolidateLoading?h('p',{className:'workflow-hint'},'Loading eligible PRs…'):null,
                s.workflowConsolidateError?h('p',{role:'alert',className:'workflow-error'},'Cannot check other PRs: '+s.workflowConsolidateError):null,
                c?h('div',null,
                    choices.length?h('label',{className:'field workflow-field'},h('span',null,'Add another finalized PR'),
                        h('select',{value:'',disabled:!this.canOperateWorkspace(),'aria-label':'Add another finalized PR',onChange:e=>{
                            const id=String(e.target.value);if(!id||selected.has(id))return;
                            this.setState({workflowConsolidatePrIds:[...selected,id],workflowError:''});
                        }},h('option',{value:''},'Choose PR to add…'),
                            ...choices.map(pr=>h('option',{key:pr.id,value:String(pr.id)},(pr.display_number||'PR #'+pr.id)+' — '+pr.requesting_unit+' ('+pr.eligible_count+' unused item'+(Number(pr.eligible_count)===1?'':'s')+')')))):null,
                    ...others.filter(pr=>selected.has(String(pr.id))).map(pr=>h('div',{key:pr.id,style:{display:'flex',alignItems:'center',justifyContent:'space-between',gap:12,padding:'9px 0',borderBottom:'1px solid var(--border,#dce7eb)'}},
                        h('span',null,h('strong',null,pr.display_number||'PR #'+pr.id),' · ',pr.requesting_unit,' · ',pr.eligible_count+' eligible item(s)'),
                        h('button',{type:'button',className:'outline',onClick:()=>{
                            const ids=(this.state.workflowConsolidatePrIds||[]).filter(x=>String(x)!==String(pr.id));
                            const lineIds=new Set((c.lines||[]).filter(l=>String(l.pr_id)===String(pr.id)).map(l=>String(l.pr_line_id)));
                            this.setState({workflowConsolidatePrIds:ids,workflowConsolidateExcluded:(this.state.workflowConsolidateExcluded||[]).filter(id=>!lineIds.has(String(id)))});
                        },'aria-label':'Remove '+(pr.display_number||'PR #'+pr.id)},'Remove PR'))),
                    null):null);
        };
        this.workflowConsolidationPreview = () => {
            const h=React.createElement,s=this.state,c=s.workflowConsolidate;
            if(!s.workflowPR)return null;
            if(s.workflowConsolidateLoading)return h('p',{className:'workflow-hint'},'Loading uncanvassed PR items…');
            if(!c)return h('p',{className:'workflow-error',role:'alert'},'Cannot load eligible items: '+(s.workflowConsolidateError||'Choose the PR again.'));
            const chosen=new Set((s.workflowConsolidatePrIds||[]).map(String));
            const excluded=new Set((s.workflowConsolidateExcluded||[]).map(String));
            const primaryMap=new Map((s.workflowRfqLines||[]).map((l,i)=>[String(l.pr_line_id),{line:l,index:i}]));
            const available=(c.lines||[]).filter(l=>chosen.has(String(l.pr_id)));
            const groups=new Map();
            const selected=[];
            const toggleExtra=(lineId,enabled)=>this.setState({workflowConsolidateExcluded:enabled?[...excluded].filter(id=>id!==String(lineId)):[...excluded,String(lineId)]});
            for(const line of available){
                const primary=String(line.pr_id)===String(s.workflowPR);
                const original=primaryMap.get(String(line.pr_line_id));
                const include=primary?(original?.line.included??false):!excluded.has(String(line.pr_line_id));
                if(!include)continue;
                const quantity=primary?Number(original?.line.quantity):Number(line.quantity);
                selected.push({...line,selected_quantity:quantity});
                const key=line.group_key;
                if(!groups.has(key))groups.set(key,{name:line.description_snapshot,unit:line.unit_snapshot,quantity:0,prs:[]});
                const g=groups.get(key);g.quantity+=quantity;
                g.prs.push((line.pr_number||'PR #'+line.pr_id)+' · '+line.requesting_unit+' ('+quantity.toFixed(3)+')');
            }
            const extraCount=[...chosen].filter(id=>id!==String(s.workflowPR)).length;
            return h('section',{style:{marginTop:12},'aria-label':'Items for RFQ, grouped by source PR'},
                h('h4',null,'Items for RFQ / Canvass'),
                h('p',{className:'workflow-hint'},'Uncheck any item you do not want included. Items from additional PRs are clearly labeled with their PR number and department. Source of funds: ',h('strong',null,c.fund_name||String(c.fund_id)),'.'),
                available.length?h('div',{className:'workflow-table-scroll'},h('table',{className:'workflow-table'},
                    h('thead',null,h('tr',null,...['Include','Source PR / Department','Item Description','PR qty','RFQ qty'].map(label=>h('th',{key:label},label)))),
                    h('tbody',null,...available.map(l=>{
                        const primary=String(l.pr_id)===String(s.workflowPR);
                        const item=primaryMap.get(String(l.pr_line_id));
                        const checked=primary?!!item?.line.included:!excluded.has(String(l.pr_line_id));
                        const count=primary?String(item?.line.quantity??l.quantity):Number(l.quantity).toFixed(3);
                        return h('tr',{key:l.pr_line_id,style:primary?{}:{background:'rgba(16,151,142,0.055)'}},
                            h('td',null,h('input',{type:'checkbox',checked,onChange:e=>primary?
                                this.workflowPatchLine('rfq',item.index,'included',e.target.checked):toggleExtra(l.pr_line_id,e.target.checked),
                                'aria-label':(checked?'Exclude ':'Include ')+l.description_snapshot+' from '+(l.pr_number||'PR #'+l.pr_id)})),
                            h('td',null,h('strong',null,l.pr_number||'PR #'+l.pr_id),h('div',{style:{fontSize:'0.85em'}},l.requesting_unit),
                                h('small',{style:{opacity:0.75}},primary?'First PR':'Added PR')),
                            h('td',null,l.description_snapshot+' ('+l.unit_snapshot+')'),
                            h('td',null,Number(l.quantity).toFixed(3)),
                            h('td',null,primary?h('input',{type:'number',min:'0.001',max:l.quantity,step:'0.001',required:checked,disabled:!checked,
                                value:count,'aria-label':'RFQ quantity for '+l.description_snapshot+' in '+(l.pr_number||'PR #'+l.pr_id),
                                onChange:e=>this.workflowPatchLine('rfq',item.index,'quantity',e.target.value)}):
                                h('span',null,count,h('small',{style:{display:'block',opacity:0.7}},'Preview only'))));
                    })))) : h('p',{className:'workflow-hint'},'No uncanvassed lines available for the selected PRs.'),
                h('h4',{style:{marginTop:16}},'Consolidated canvass quantities · Preview'),
                selected.length?h('div',{className:'workflow-table-scroll'},h('table',{className:'workflow-table'},
                    h('thead',null,h('tr',null,...['Item','Unit','Total quantity','Originating PRs / departments'].map(name=>h('th',{key:name},name)))),
                    h('tbody',null,...[...groups].map(([key,g])=>h('tr',{key},
                        h('td',null,g.name),h('td',null,g.unit),h('td',null,g.quantity.toFixed(3)),h('td',null,g.prs.join('; '))))))):
                    h('p',{className:'workflow-hint'},'No items selected. Check at least one item to preview a canvass.'),
                extraCount?h('p',{role:'status',className:'workflow-hint'},'PREVIEW ONLY: '+extraCount+' additional PR(s) selected. Multi-PR RFQ creation is not yet enabled pending database and single-Award/PO integrity controls. No RFQ will be created from these additional PRs.'):null);
        };
        this.workflowView = () => {
            const h=React.createElement,s=this.state,steps=[['RFQ','RFQ / Canvass'],['QUOTES','Supplier Quotations'],['AWARD','Abstract / Award'],['PO','Purchase Order'],['IAR','Inspection & Acceptance']];
            const finalized=(s.lists.pr||[]).filter(p=>p.status==='FINALIZED');
            const rfqs=(s.lists.rfq||[]).filter(x=>x.status==='FINALIZED');
            const allRfqs=s.lists.rfq||[];
            const quotes=s.lists.quotes||[],awards=(s.lists.awards||[]).filter(x=>x.status==='FINALIZED');
            const orders=(s.lists.po||[]).filter(x=>x.status==='FINALIZED');
            const write=this.canOperateWorkspace();
            const field=(title,child,documentDate)=>h('label',{className:'field workflow-field'},h('span',null,title),child,
                documentDate===undefined?null:weekendDocumentNotice(h,documentDate,title.replace(/\s*\*$/, '')));
            const select=(value,onChange,placeholder,rows,label)=>h('select',{value,onChange:e=>onChange(e.target.value),disabled:!write},h('option',{value:''},placeholder),...rows.map(r=>h('option',{key:r.id,value:r.id},label(r))));
            const btn=(label)=>h('button',{type:'submit',className:'primary',disabled:s.busy||!write},s.busy?'Saving…':label);
            const money=v=>'₱'+Number(v||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
            const blankRfqUrl=r=>'api/print.php?organization_id='+encodeURIComponent(s.organization_id)+'&type=RFQ&id='+encodeURIComponent(r.id);
            // v0.2.186: PR figures are read-only context for choosing the correct RFQ.
            // The RFQ list itself contains the linked PR purpose/total so the
            // selector works even when the independent PR register hits its 100-row cap.
            const prById=new Map((s.lists.pr||[]).map(p=>[String(p.id),p]));
            const rfqPrContext=r=>{
                const p=prById.get(String(r.pr_id))||{};
                const purpose=String(r.pr_purpose??p.purpose??'').replace(/\s+/g,' ').trim();
                const rawTotal=r.pr_total??p.total;
                const totalKnown=rawTotal!==null&&rawTotal!==undefined&&String(rawTotal).trim()!==''&&Number.isFinite(Number(rawTotal))&&Number(rawTotal)>=0;
                return {purpose:purpose||'Purpose unavailable',total:totalKnown?money(rawTotal):'Amount unavailable',prNumber:r.pr_number||p.display_number||'PR #'+r.pr_id};
            };
            const rfqQuotationChoice=r=>{
                const c=rfqPrContext(r);
                const compact=c.purpose.length>55?c.purpose.slice(0,52).trimEnd()+'…':c.purpose;
                return (r.display_number||'RFQ #'+r.id)+' — '+c.prNumber+' — '+compact+' — PR total: '+c.total;
            };

            const smallTable=(cols,rows)=>h('div',{className:'workflow-table-scroll'},h('table',{className:'workflow-table'},h('thead',null,h('tr',null,...cols.map(c=>h('th',{key:c},c)))),h('tbody',null,...rows)));
            let content=null;
            if(s.workflowStage==='RFQ'){
                const priorRfqs=allRfqs.filter(r=>String(r.pr_id)===String(s.workflowPR));
                const staffCurrent=String(s.staffData?.organization?.id||'')===String(s.organization_id);
                const activeStaff=staffCurrent?(s.staffData.employees||[]).filter(e=>e.active):[];
                const bacChair=activeStaff.find(e=>String(e.employee_id)===String(s.staffData?.bac?.chair||''));
                const canvasserHistoric=rfqHistoricalParticipant(s.staffData,s.workflowCanvasserId,s.workflowRfqDate);
                const chairHistoric=rfqHistoricalParticipant(s.staffData,bacChair?.employee_id,s.workflowRfqDate);
                const evidenceControl=(person,kind,info,confirmed,reference,flagKey,referenceKey) =>
                    !info.needed?null:h('div',{className:'notice warning',role:'group','aria-label':kind+' historical designation evidence'},
                        h('p',null,kind+' was entered into this school’s employee directory effective '+info.recordedFrom+', later than this RFQ date. If an authentic earlier designation exists, enter its official supporting reference. IPDAMS will not backdate employment or modify past documents.'),
                        field(kind+' supporting order / memo *',h('input',{type:'text',required:true,minLength:20,maxLength:500,
                            value:reference,onChange:e=>this.setState({[referenceKey]:e.target.value,workflowError:''}),
                            placeholder:'Actual office order/memorandum and date'})),
                        h('span',{className:'workflow-hint'},[...reference.trim()].length+' / 20 characters minimum'),
                        h('label',{style:{display:'flex',gap:'8px',alignItems:'center'}},
                            h('input',{type:'checkbox',required:true,checked:!!confirmed,
                                onChange:e=>this.setState({[flagKey]:e.target.checked,workflowError:''})}),
                            'I confirm an official record supports this earlier '+kind+' designation.'));
                content=h('div',null,s.workflowRFQEditId?null:h('form',{onSubmit:e=>this.workflowSubmit(e,'RFQ'),className:'workflow-form'},
                    h('p',{className:'body-muted'},'Select a first finalized PR, then add eligible PRs from other departments below. All item selection uses the same checkbox table. Multi-PR RFQ issuance remains preview-only pending database checks.'),
                    field('Finalized Purchase Request',select(s.workflowPR,v=>this.workflowLoadPR(v),'Choose a finalized PR',finalized,r=>(r.display_number||'PR #'+r.id)+' — '+r.purpose)),
                    this.workflowAddConsolidationPR(),
                    field('RFQ Date',h('input',{type:'date',required:true,max:localToday(),min:String((finalized.find(r=>String(r.id)===String(s.workflowPR))?.document_date||'')).slice(0,10)||undefined,value:s.workflowRfqDate,
                        disabled:!write||!s.workflowPR||!s.workflowRfqLines.length||priorRfqs.length>0,
                        'aria-label':'RFQ date (defaults to PR date)',
                        onChange:e=>this.setState({workflowRfqDate:e.target.value,workflowCanvasserHistoricalAttested:false,workflowCanvasserHistoricalReference:'',workflowChairHistoricalAttested:false,workflowChairHistoricalReference:'',workflowError:''})}),s.workflowRfqDate),
                    s.workflowPR?h('p',{className:'workflow-hint'},'Defaults to the first PR document date. A finalized RFQ may be corrected only within eight hours after creation, before quotations or awards.'):null,
                    field('Canvasser *',h('select',{required:true,value:staffCurrent?s.workflowCanvasserId:'',
                        disabled:!write||s.staffLoading||!staffCurrent,
                        onChange:e=>this.setState({workflowCanvasserId:e.target.value,workflowCanvasserHistoricalAttested:false,workflowCanvasserHistoricalReference:'',workflowError:''})},
                        h('option',{value:''},s.staffLoading?'Loading school employees…':
                            staffCurrent?'Select the canvasser from this school':'Load school employees first'),
                        ...activeStaff.map(e=>h('option',
                            {key:e.employee_id,value:String(e.employee_id)},e.full_name+' · '+(e.designation||e.position_title||''))))),
                    field('BAC Chairperson *',h('input',{type:'text',readOnly:true,
                        value:s.staffLoading?'Loading BAC appointments…':
                            bacChair?.full_name||'Configure BAC & Heads first'})),
                    evidenceControl(canvasserHistoric.person,'Canvasser',canvasserHistoric,
                        s.workflowCanvasserHistoricalAttested,s.workflowCanvasserHistoricalReference,
                        'workflowCanvasserHistoricalAttested','workflowCanvasserHistoricalReference'),
                    evidenceControl(chairHistoric.person,'BAC Chairperson',chairHistoric,
                        s.workflowChairHistoricalAttested,s.workflowChairHistoricalReference,
                        'workflowChairHistoricalAttested','workflowChairHistoricalReference'),
                    s.staffError?h('div',{className:'workflow-hint',role:'alert'},
                        'Employee and BAC data could not load: '+s.staffError):null,
                    (!staffCurrent||s.staffError)?h('button',{type:'button',className:'outline',
                        disabled:s.staffLoading,onClick:()=>this.loadStaffDirectory()},
                        s.staffLoading?'Loading employees…':'Refresh employees and BAC'):null,
                    h('p',{className:'workflow-hint'},'The canvasser and BAC Chairperson are required for issuance. Both names are recorded with the RFQ; signatures remain handwritten.'),
                    finalized.length?null:h('div',{className:'workflow-hint workflow-next-step'},
                        h('span',null,'No finalized PR is available. Review and finalize a draft first.'),
                        h('button',{type:'button',className:'outline',onClick:()=>this.setState({procurementSection:'PR',workflowFormOpen:false})},'Open Purchase Requests')),
                    this.workflowConsolidationPreview(),
                    h('p',{className:'workflow-hint'},priorRfqs.length?
                        'The first PR already has an RFQ. Its remaining lines may appear in the preview, but the old single-PR creator cannot issue a second RFQ for that PR yet.':
                        'For a single-PR RFQ, only checked items will be included in the numbered document. Additional PRs are currently for eligibility preview only.'),
                    priorRfqs.length?null:((s.workflowConsolidatePrIds||[]).some(id=>String(id)!==String(s.workflowPR))?
                        h('div',{className:'workflow-hint',role:'status'},
                            'Multi-PR RFQ creation is not yet available. Remove added PRs to create a single-PR RFQ. The consolidated preview above does not save a document.'):
                        s.workflowConsolidateLoading||s.workflowConsolidateError?
                            h('p',{className:'workflow-hint'},'Wait for the eligibility check to finish before creating an RFQ.'):
                            btn('Create RFQ / Canvass'))),
                    s.workflowRFQEditId?h('form',{className:'workflow-form',onSubmit:e=>this.workflowSaveRFQEdit(e)},
                        h('h4',null,'Correct RFQ #'+s.workflowRFQEditId+' — within original 8-hour window'),
                        h('p',{className:'workflow-hint'},'Corrections are blocked after eight elapsed hours or as soon as supplier quotations or an award exist. The RFQ number stays the same.'),
                        s.workflowRFQEditLoading?h('p',null,'Loading editable RFQ…'):null,
                        s.workflowRFQEditError?h('p',{className:'workflow-error',role:'alert'},s.workflowRFQEditError):null,
                        s.workflowRFQEditVersion>0?h('div',null,
                            field('RFQ date',h('input',{type:'date',required:true,max:localToday(),value:s.workflowRFQEditDate,
                                onChange:e=>this.setState({workflowRFQEditDate:e.target.value,workflowRFQEditError:''})}),s.workflowRFQEditDate),
                            smallTable(['Include','PR item','PR qty','RFQ qty'],s.workflowRFQEditLines.map((l,i)=>h('tr',{key:l.pr_line_id},
                                h('td',null,h('input',{type:'checkbox',checked:l.included,onChange:e=>this.workflowPatchRFQEditLine(i,'included',e.target.checked)})),
                                h('td',null,l.item_name+' ('+l.unit+')'),h('td',null,String(l.pr_quantity)),
                                h('td',null,h('input',{type:'number',min:'0.001',max:l.pr_quantity,step:'0.001',required:!!l.included,
                                    disabled:!l.included,value:l.quantity,onChange:e=>this.workflowPatchRFQEditLine(i,'quantity',e.target.value)}))))),
                            field('Reason for correction',h('input',{type:'text',required:true,maxLength:500,
                                placeholder:'Briefly explain the change',value:s.workflowRFQEditReason,
                                onChange:e=>this.setState({workflowRFQEditReason:e.target.value})})),
                            h('label',{className:'workflow-check'},
                                h('input',{type:'checkbox',required:true,checked:!!s.workflowRFQEditConfirm,
                                    onChange:e=>this.setState({workflowRFQEditConfirm:e.target.checked})}),
                                h('span',null,'I confirm this RFQ has not yet been distributed to suppliers.')),
                            h('div',{className:'workflow-actions'},btn('Save RFQ correction'),
                                h('button',{type:'button',className:'outline',onClick:()=>this.setState({workflowFormOpen:false,workflowRFQEditId:'',workflowRFQEditLines:[],workflowRFQEditVersion:0,workflowRFQEditConfirm:false})},'Cancel'))):
                            h('button',{type:'button',className:'outline',onClick:()=>this.setState({workflowFormOpen:false,workflowRFQEditId:'',workflowRFQEditLines:[],workflowRFQEditError:'',workflowRFQEditLoading:false})},'Close')):null);
            }else if(s.workflowStage==='QUOTES'){
                const recorded=quotes.filter(q=>String(q.rfq_id)===String(s.workflowRFQ));
                const suppliers=(s.lists.suppliers||[]).filter(x=>Number(x.active)&&
                    !recorded.some(q=>String(q.supplier_id)===String(x.id)));
                content=h('div',{className:'workflow-quotation-workspace'},
                    h('form',{onSubmit:e=>this.workflowSubmit(e,'QUOTES'),className:'workflow-form'},
                        h('p',{className:'body-muted'},'Enter quotations from suppliers one at a time. After Save, this screen stays open on the same RFQ. Prepare the Abstract only when you decide that the quotations are ready for evaluation.'),
                        h('div',{className:'workflow-fields'},
                            field('RFQ',select(s.workflowRFQ,v=>this.workflowLoadRFQ(v),'Choose RFQ',rfqs,rfqQuotationChoice)),
                            (()=>{const chosen=rfqs.find(r=>String(r.id)===String(s.workflowRFQ));
                                if(!chosen)return null;
                                const c=rfqPrContext(chosen);
                                return h('div',{className:'workflow-hint',role:'status'},
                                    h('strong',null,'Selected PR purpose: '),c.purpose,
                                    h('div',null,h('strong',null,'PR total: '),c.total,' · '+c.prNumber));
                            })(),
                            field('Supplier',select(s.workflowSupplier,v=>this.setState({workflowSupplier:v}),'Choose a supplier not yet quoted',suppliers,r=>r.name))),
                        s.workflowRFQ&&rfqs.some(r=>String(r.id)===String(s.workflowRFQ))?h('div',{className:'workflow-blank-rfq-inline'},
                            h('span',null,'Use the same issued RFQ for each supplier.'),
                            h('a',{className:'workflow-blank-rfq-link',href:blankRfqUrl(rfqs.find(r=>String(r.id)===String(s.workflowRFQ))),target:'_blank',rel:'noopener'},'Print Blank RFQ ↗')):null,
                        s.workflowRFQ&&recorded.length>0&&suppliers.length===0?h('p',{className:'workflow-hint'},'All currently active suppliers already have a recorded quotation for this RFQ.'):null,
                        field('Supplier quotation date *',h('input',{type:'date',required:true,max:localToday(),min:String((rfqs.find(r=>String(r.id)===String(s.workflowRFQ))?.issued_at||'')).slice(0,10)||undefined,value:s.workflowQuoteDate,onChange:e=>this.setState({workflowQuoteDate:e.target.value,workflowError:''})}),s.workflowQuoteDate),
                    field('Quotation reference / evidence',h('input',{type:'text',maxLength:500,required:true,placeholder:'Quotation number, date or received document reference',value:s.workflowQuoteEvidence,onChange:e=>this.setState({workflowQuoteEvidence:e.target.value})})),
                        s.workflowQuoteLines.length?smallTable(['Item','Quantity','Offered unit price (₱)','Compliant?'],s.workflowQuoteLines.map((l,i)=>h('tr',{key:l.pr_line_id},h('td',null,l.item_name+' ('+l.unit+')'),h('td',null,l.quantity),h('td',null,h('input',{type:'number',min:'0.01',step:'0.01',required:true,value:l.price,onChange:e=>this.workflowPatchLine('quote',i,'price',e.target.value),'aria-label':'Offered price for '+l.item_name})),h('td',null,h('input',{type:'checkbox',checked:l.compliant,onChange:e=>this.workflowPatchLine('quote',i,'compliant',e.target.checked),'aria-label':'Compliant '+l.item_name}))))):h('p',{className:'workflow-hint'},'Select an RFQ to load its item lines.'),
                        btn('Save Supplier Quotation')),
                    s.workflowRFQ?h('div',{className:'workflow-quotation-review'},
                        h('div',{className:'section-head'},h('h4',null,'Recorded supplier quotations'),h('span',{className:'count'},recorded.length+' saved')),
                        recorded.length?smallTable(['Supplier','Quoted total','Compliance'],recorded.map(q=>h('tr',{key:q.id},
                            h('td',null,q.supplier),h('td',null,money(q.offered_total)),
                            h('td',null,Number(q.noncompliant_lines)>0?'Contains noncompliant items':'Compliant')))):
                            h('p',{className:'workflow-hint'},'No quotations saved yet for this RFQ.'),
                        h('p',{className:'workflow-hint'},'You may save more quotations at any time. The Abstract will not be prepared automatically.'),
                        recorded.length?h('button',{type:'button',className:'outline',disabled:s.busy,
                            onClick:()=>this.setState({workflowStage:'AWARD',workflowFormOpen:true,workflowAwardRFQ:String(s.workflowRFQ),
                                workflowQuoteId:'',workflowAwardNote:'',workflowAwardNoteManual:false,workflowAwardApproved:false,
                                workflowError:'',workflowMessage:''})},'Prepare Abstract / Award →'):null):null);
            }else if(s.workflowStage==='AWARD'){
                const allOffers=quotes.filter(q=>String(q.rfq_id)===String(s.workflowAwardRFQ));
                const eligibleOffers=allOffers.filter(q=>Number(q.line_count)>0&&Number(q.noncompliant_lines)===0).sort((a,b)=>Number(a.offered_total)-Number(b.offered_total)||Number(a.id)-Number(b.id));
                const availableOffers=eligibleOffers.filter(q=>Number(q.already_awarded)!==1);
                const lowestOffer=eligibleOffers[0]||null;
                const chosen=s.workflowQuoteId||String(availableOffers[0]?.id||'');
                content=h('form',{onSubmit:e=>this.workflowSubmit(e,'AWARD'),className:'workflow-form'},
                    h('p',{className:'body-muted'},'Compare quotations for the same RFQ. The lowest complete, compliant offer is recommended by default. Print and obtain signatures manually.'),
                    // Reuse the Supplier Quotation RFQ label so both workflow stages
                    // identify the same linked PR purpose and original PR total.
                    field('RFQ',select(s.workflowAwardRFQ,v=>this.setState({workflowAwardRFQ:v,workflowQuoteId:'',workflowAwardNote:'',workflowAwardNoteManual:false,workflowAwardApproved:false}),'Choose RFQ for evaluation',rfqs,rfqQuotationChoice)),
                    (()=>{const selected=rfqs.find(r=>String(r.id)===String(s.workflowAwardRFQ));
                        if(!selected)return null;
                        const c=rfqPrContext(selected);
                        return h('div',{className:'workflow-hint',role:'status'},
                            h('strong',null,'Selected PR purpose: '),c.purpose,
                            h('div',null,h('strong',null,'PR total: '),c.total,' · '+c.prNumber));
                    })(),
                    allOffers.length?smallTable(['Supplier','Lines','Quoted total','Evaluation'],allOffers.map(q=>h('tr',{key:q.id},h('td',null,q.supplier),h('td',null,q.line_count),h('td',null,money(q.offered_total)),h('td',null,Number(q.already_awarded)===1?'Already awarded — unavailable':Number(q.noncompliant_lines)>0?'Noncompliant':lowestOffer&&String(q.id)===String(lowestOffer.id)?'Lowest compliant':'Compliant')))):h('p',{className:'workflow-hint'},'Select an RFQ with recorded supplier quotations to compare.'),
                    lowestOffer?h('p',{className:'workflow-hint'},'Lowest compliant quotation (evaluation benchmark): '+lowestOffer.supplier+' — '+money(lowestOffer.offered_total)+(Number(lowestOffer.already_awarded)===1?' — award already recorded':'')):null,
                    allOffers.length&&!availableOffers.length?h('p',{className:'workflow-hint',role:'status'},'No eligible unawarded supplier remains for this RFQ. Existing awards must be reviewed rather than duplicated.'):null,
                    field('Selected quotation',select(chosen,v=>this.setState({workflowQuoteId:v,workflowAwardApproved:false}),'Select compliant supplier not yet awarded',availableOffers,q=>q.supplier+' — '+money(q.offered_total))),
                    field('Abstract / BAC Resolution date *',h('input',{type:'date',required:true,value:s.workflowAwardDate||localToday(),
                        max:localToday(),min:String((rfqs.find(r=>String(r.id)===String(s.workflowAwardRFQ))?.issued_at||'')).slice(0,10)||undefined,onChange:e=>this.setState({workflowAwardDate:e.target.value,workflowError:''})}),s.workflowAwardDate||localToday()),
                    h('p',{className:'workflow-hint'},'This date will be captured with the numbered award. The BAC Resolution and Abstract will remain unsigned until wet signatures are obtained.'),
                    field('Evaluation and determination note',h('textarea',{rows:3,maxLength:4000,required:true,placeholder:'Lowest bidder: suggested note. Otherwise enter your evaluation and justification here.',value:s.workflowAwardNoteManual?s.workflowAwardNote:recommendedAwardNote(eligibleOffers,chosen),onChange:e=>this.setState({workflowAwardNote:e.target.value,workflowAwardNoteManual:true})})),
                    h('label',{className:'workflow-check'},h('input',{type:'checkbox',checked:s.workflowAwardApproved,onChange:e=>this.setState({workflowAwardApproved:e.target.checked})}),h('span',null,'I have reviewed the quotations. Printed documents will be signed manually.')),
                    h('button',{type:'submit',className:'primary',disabled:s.busy||!write||availableOffers.length===0},s.busy?'Saving…':'Record Award Determination'));
            }else if(s.workflowStage==='PO'){
                const awarded=awards.find(a=>String(a.id)===String(s.workflowAward));
                const availableAwards=awards.filter(a=>!a.existing_po_id);
                const supplier=(s.lists.suppliers||[]).find(x=>String(x.id)===String(awarded?.supplier_id));
                const poLines=s.workflowPoLines.filter(x=>x.included);
                const estimatedTotal=poLines.reduce((sum,l)=>sum+Number(l.quantity||0)*Number(l.unit_price||0),0);
                const choices=(value,change,label,items)=>field(label,h('select',{value,onChange:e=>this.setState({[change]:e.target.value,workflowError:''}),required:true},
                    ...items.map(([key,label])=>h('option',{key,value:key},label))));
                content=h('form',{onSubmit:e=>this.workflowSubmit(e,'PO'),className:'workflow-form workflow-po-form'},
                    h('p',{className:'body-muted'},'Prepare the Appendix 61 Purchase Order. This is documentation for manual signatures; issuing does not sign or approve the paper. Quoted unit prices remain locked to the supplier offer.'),
                    field('Finalized award — supplier and PR purpose',h('select',{
                        value:s.workflowAward,onChange:e=>this.workflowLoadAward(e.target.value),disabled:!write,
                        'aria-label':'Choose a finalized award without an issued Purchase Order'},
                        h('option',{value:''},'Choose award without an existing PO'),
                        ...awards.map(r=>h('option',{key:r.id,value:r.id,disabled:!!r.existing_po_id},
                            (r.display_number||'Award #'+r.id)+' — '+r.supplier+' — '+(r.pr_purpose||'PR purpose unavailable')+
                            (r.existing_po_id?' — PO '+(r.existing_po_number||'#'+r.existing_po_id)+' ('+r.existing_po_status+') — already issued':''))))),
                    availableAwards.length===0?h('p',{className:'workflow-hint',role:'status'},
                        'No awarded bidder is available for a new PO. Review existing Purchase Orders or record another eligible award.'):null,
                    awarded?.existing_po_id?h('p',{className:'workflow-error',role:'alert'},
                        'This bidder already has PO '+(awarded.existing_po_number||'#'+awarded.existing_po_id)+'. Open that PO in the register; ordinary duplicate creation is blocked.'):null,
                    awarded?h('div',{className:'workflow-po-purpose'},
                        h('strong',null,'Linked PR: '+(awarded.pr_number||'PR #'+awarded.pr_id)),
                        h('div',null,'Purpose: '+(awarded.pr_purpose||'Not available')),
                        h('span',{className:'workflow-hint'},'One award currently references one PR. Additional PR consolidation remains preview-only.')):null,
                    field('Purchase Order date *',h('input',{type:'date',required:true,max:localToday(),min:String((awarded?.resolution_date||awarded?.recorded_at||'')).slice(0,10)||undefined,value:s.workflowPoDate,onChange:e=>this.setState({workflowPoDate:e.target.value,workflowError:''})}),s.workflowPoDate),
                    awarded?h('section',{className:'workflow-po-supplier','aria-label':'Selected supplier details'},
                        h('h4',null,'Supplier information'),
                        h('div',null,h('strong',null,supplier?.name||awarded.supplier||'')),
                        h('div',null,'Address: '+(supplier?.address||'Not recorded in supplier profile')),
                        h('div',null,'TIN: '+(supplier?.tax_id||'Not recorded in supplier profile')),
                        supplier?.zip_code?h('div',null,'ZIP: '+supplier.zip_code):null,
                        supplier?.proprietor_name?h('div',null,'Proprietor: '+supplier.proprietor_name):null,
                        h('small',null,'Supplier details will be captured at issuance; edit the supplier profile before issuing if incorrect.')):null,
                    s.workflowPoLines.length?smallTable(['Include','Item / Unit','Quantity','Unit price (₱)','Amount (₱)'],s.workflowPoLines.map((l,i)=>h('tr',{key:l.pr_line_id},
                        h('td',null,h('input',{type:'checkbox',checked:l.included,onChange:e=>this.workflowPatchLine('po',i,'included',e.target.checked),'aria-label':'Include '+l.item_name})),
                        h('td',null,l.item_name+' ('+l.unit+')'),
                        h('td',null,h('input',{type:'number',min:'0.001',max:l.rfq_quantity,step:'0.001',value:l.quantity,onChange:e=>this.workflowPatchLine('po',i,'quantity',e.target.value),'aria-label':'Ordered quantity '+l.item_name})),
                        h('td',null,h('input',{type:'number',min:'0.01',step:'0.01',value:l.unit_price,readOnly:true,title:'Locked to the awarded compliant quotation','aria-label':'Awarded unit price '+l.item_name})),
                        h('td',{className:'workflow-po-money'},l.included?money(Number(l.quantity)*Number(l.unit_price)):'—')))):
                        h('p',{className:'workflow-hint'},'Choose a finalized award to load its items and offered prices.'),
                    h('div',{className:'workflow-po-total'},h('strong',null,'Grand Total'),h('strong',null,money(estimatedTotal))),
                    h('h4',null,'Terms and Conditions'),
                    h('div',{className:'workflow-po-terms'},
                        field('Expected delivery date *',h('input',{type:'date',required:true,min:s.workflowPoDate||undefined,value:s.workflowPoDeliveryDate,onChange:e=>this.setState({workflowPoDeliveryDate:e.target.value,workflowError:''})})),
                        field('Place of delivery *',h('input',{type:'text',maxLength:500,required:true,value:s.workflowPoDeliveryPlace,onChange:e=>this.setState({workflowPoDeliveryPlace:e.target.value,workflowError:''}),placeholder:'School address not configured — enter delivery location'})),
                        h('p',{className:'workflow-hint'},'For schools, the saved school address is filled in automatically. You may edit the delivery location for this PO.'),
                        choices(s.workflowPoDeliveryTerm,'workflowPoDeliveryTerm','Delivery term *',[
                            ['Door-to-Door Delivery','Door-to-Door Delivery'],['For Pick-up','For Pick-up'],['Courier / Freight','Courier / Freight'],['Other (as agreed)','Other (as agreed)']]),
                        choices(s.workflowPoPaymentTerm,'workflowPoPaymentTerm','Payment term *',[
                            ['Full Payment upon Delivery','Full Payment upon Delivery'],['Payment upon Inspection and Acceptance','Payment upon Inspection and Acceptance'],['Progress Billing (subject to contract)','Progress Billing (subject to contract)'],['Other (as agreed)','Other (as agreed)']]),
                        choices(s.workflowPoPaymentType,'workflowPoPaymentType','Payment type *',[
                            ['Check','Check'],['Cash','Cash'],['Bank Transfer','Bank Transfer'],['Other (as agreed)','Other (as agreed)']]),
                        choices(s.workflowPoMethod,'workflowPoMethod','Mode of procurement *',[
                            ['','Select documented method'],['Small Value Procurement','Small Value Procurement'],['Negotiated Procurement','Negotiated Procurement'],['Competitive Bidding','Competitive Bidding'],['Other (documented)','Other (documented)']])),
                    h('p',{className:'workflow-hint'},'The PO printout follows Appendix 61. Fund certification, ORS/BURS and signatures remain blank until provided or signed by the authorized officers.'),
                    h('button',{type:'submit',className:'primary',disabled:s.busy||!write||!s.workflowAward||!!awarded?.existing_po_id||availableAwards.length===0},s.busy?'Saving…':'Issue Purchase Order'));
            }else if(s.workflowStage==='IAR'){
                content=h('form',{onSubmit:e=>this.workflowSubmit(e,'IAR'),className:'workflow-form'},
                    h('p',{className:'body-muted'},'Record actual deliveries against the PO. Accepted quantities will be posted as receipts according to the current inventory rules.'),
                    field('Purchase Order',select(s.workflowPO,v=>this.workflowLoadPO(v),'Choose Purchase Order',orders,r=>r.display_number+' — '+r.supplier)),
                    h('div',{className:'workflow-fields'},field('Delivery date',h('input',{type:'date',required:true,max:localToday(),min:String((orders.find(r=>String(r.id)===String(s.workflowPO))?.document_date||'')).slice(0,10)||undefined,value:s.workflowDelivered,onChange:e=>this.setState({workflowDelivered:e.target.value,workflowIARReviewed:false,workflowError:''})})),field('Inspection / acceptance date',h('input',{type:'date',required:true,max:localToday(),min:s.workflowDelivered||undefined,value:s.workflowAccepted,onChange:e=>this.setState({workflowAccepted:e.target.value,workflowIARReviewed:false,workflowError:''})}))),
                    field('IAR Date (report date) *',h('input',{type:'date',required:true,min:s.workflowAccepted||undefined,max:localToday(),value:s.workflowIarDate,
                        onChange:e=>this.setState({workflowIarDate:e.target.value,workflowIARReviewed:false,workflowError:''})}),s.workflowIarDate),
                    s.workflowIARPoMeta?h('section',{className:'workflow-po-supplier','aria-label':'Source PO and supplier details'},
                        h('h4',null,'Linked Purchase Order and supplier (read only)'),
                        h('p',null,'Supplier: '+(s.workflowIARPoMeta.supplier_name||'Not recorded')),
                        h('p',null,'Address: '+(s.workflowIARPoMeta.supplier_address||'Not recorded')),
                        h('p',null,'PO No.: '+(s.workflowIARPoMeta.number||'Not recorded')+' · PO Date: '+(s.workflowIARPoMeta.document_date||'Not recorded')),
                        h('p',null,'Responsibility Center Code (source of fund): '+(s.workflowIARPoMeta.responsibility_center||'Not configured'))):null,
                    field('Supplier Invoice No. (optional)',h('input',{type:'text',maxLength:120,value:s.workflowIARInvoice,onChange:e=>this.setState({workflowIARInvoice:e.target.value,workflowIARReviewed:false,workflowError:''}),placeholder:'Enter the actual supplier invoice reference'})),
                    field('Supplier Invoice Date (optional)',h('input',{type:'date',max:localToday(),value:s.workflowIARInvoiceDate,
                        onChange:e=>this.setState({workflowIARInvoiceDate:e.target.value,workflowIARReviewed:false,workflowError:''})})),
                    s.workflowIARLines.length?smallTable(['PO item','Remaining','Delivered now','Accepted now','Rejected'],s.workflowIARLines.map((l,i)=>h('tr',{key:l.po_line_id},h('td',null,l.item_name+' ('+l.unit+')'),h('td',null,String(l.remaining)),h('td',null,h('input',{type:'number',min:0,max:l.remaining,step:'0.001',value:l.delivered_qty,onChange:e=>this.workflowPatchLine('iar',i,'delivered_qty',e.target.value),'aria-label':'Delivered '+l.item_name})),h('td',null,h('input',{type:'number',min:0,max:l.delivered_qty,step:'0.001',value:l.accepted_qty,onChange:e=>this.workflowPatchLine('iar',i,'accepted_qty',e.target.value),'aria-label':'Accepted '+l.item_name})),h('td',null,Math.max(0,Number(l.delivered_qty)-Number(l.accepted_qty)).toFixed(3))))):h('p',{className:'workflow-hint'},'Select a Purchase Order to load its outstanding items.'),
                    h('label',{className:'workflow-check',style:{margin:'14px 0 10px',display:'flex',alignItems:'flex-start',gap:'8px'}},
                        h('input',{type:'checkbox',required:true,checked:!!s.workflowIARReviewed,
                            disabled:!write||s.busy||!s.workflowPO||!s.workflowIARLines.length,
                            onChange:e=>this.setState({workflowIARReviewed:e.target.checked,workflowError:''}),
                            'aria-label':'I have reviewed and checked all IAR dates and quantities'}),
                        h('span',null,'I have reviewed and checked the invoice reference, dates, delivered quantities and accepted quantities above against the actual delivery and inspection.')),
                    h('p',{className:'workflow-hint'},'The prefilled values are suggestions, not proof of delivery or acceptance. Correct any differences before checking the box; finalizing records inventory receipts.'),
                    s.workflowError?h('p',{className:'workflow-error'},s.workflowError):null,
                    h('button',{type:'submit',className:'primary',
                        disabled:s.busy||!write||!s.workflowIARReviewed||!s.workflowPO||!s.workflowIARLines.some(l=>Number(l.delivered_qty)>0)},
                        s.busy?'Saving…':'Finalize Inspection & Acceptance'));
            }
            const stageMeta={
                RFQ:{label:'RFQ / Canvass',singular:'RFQ / Canvass',kind:'rfq'},
                QUOTES:{label:'Supplier Quotations',singular:'Supplier Quotation',kind:'quotes'},
                AWARD:{label:'Abstract / Award',singular:'Abstract / Award',kind:'awards'},
                PO:{label:'Purchase Orders',singular:'Purchase Order',kind:'po'},
                IAR:{label:'Inspection & Acceptance',singular:'Inspection & Acceptance',kind:'iar'}
            };
            const meta=stageMeta[s.workflowStage]||stageMeta.RFQ;
            const allRows=Array.isArray(s.lists[meta.kind])?s.lists[meta.kind]:[];
            const documentDate=r=>{
                const possible=s.workflowStage==='RFQ'?['issued_at','created_at']:
                    s.workflowStage==='QUOTES'?['offered_at','quotation_date','quoted_at','created_at']:
                    s.workflowStage==='AWARD'?['resolution_date','determined_at','recorded_at','created_at']:
                    s.workflowStage==='PO'?['document_date','issued_at','created_at']:
                        ['iar_date','accepted_at','delivered_at','created_at'];
                for(const key of possible){
                    const val=String(r[key]||'').slice(0,10);
                    if(/^\d{4}-\d{2}-\d{2}$/.test(val))return val;
                }
                return '';
            };
            const months=['January','February','March','April','May','June','July','August','September','October','November','December'];
            const month=s.workflowRegisterMonth||'',year=s.workflowRegisterYear||'';
            const registerDate=r=>s.workflowStage==='QUOTES'?String(r.issued_at||r.created_at||'').slice(0,10):documentDate(r);
            const dateSource=s.workflowStage==='QUOTES'?allRfqs:allRows;
            const years=[...new Set([localToday().slice(0,4),...dateSource.map(r=>registerDate(r).slice(0,4)).filter(v=>/^\d{4}$/.test(v))])].sort((a,b)=>b.localeCompare(a));
            const visibleRows=allRows.filter(r=>{
                const date=documentDate(r);
                return (!month||date.slice(5,7)===month)&&(!year||date.slice(0,4)===year);
            });
            const prFor=r=>{
                let id=r.pr_id;
                if(!id&&r.rfq_id)id=allRfqs.find(x=>String(x.id)===String(r.rfq_id))?.pr_id;
                if(!id&&r.award_id)id=awards.find(x=>String(x.id)===String(r.award_id))?.pr_id;
                if(!id&&r.po_id){
                    const po=(s.lists.po||[]).find(x=>String(x.id)===String(r.po_id));
                    id=po?.pr_id||awards.find(a=>String(a.id)===String(po?.award_id))?.pr_id;
                }
                return (s.lists.pr||[]).find(p=>String(p.id)===String(id));
            };
            const stageDateLabel=s.workflowStage==='RFQ'?'RFQ Date':s.workflowStage==='AWARD'?'Resolution / Recorded Date':s.workflowStage==='IAR'?'IAR Date / Acceptance Date':'Date';
            const renderRecordRow=r=>{
                const relatedPr=prFor(r);
                const purpose=relatedPr?.purpose||r.pr_purpose||'Purpose unavailable — refresh data';
                const number=r.display_number||(s.workflowStage==='QUOTES'?'Quotation #':'Record #')+r.id;
                const description=s.workflowStage==='RFQ'?(r.pr_number||relatedPr?.display_number||'PR #'+r.pr_id):
                    s.workflowStage==='QUOTES'?(r.supplier||'Supplier not listed')+' · '+(r.rfq_number||'RFQ #'+r.rfq_id):
                    s.workflowStage==='AWARD'?'Winner: '+(r.supplier||'Supplier unavailable')+' · '+(r.rfq_number||'RFQ #'+r.rfq_id):
                    s.workflowStage==='PO'?(r.supplier||'')+' · '+(r.award_number||'Award #'+r.award_id):
                    (r.po_number||'PO #'+r.po_id)+(s.workflowStage==='IAR'&&r.invoice_no?' · Invoice '+r.invoice_no:'');
                const actions=[];
                if(s.workflowStage==='RFQ'){
                    actions.push(h('a',{key:'print',className:'workflow-blank-rfq-link',href:blankRfqUrl(r),target:'_blank',rel:'noopener'},'Print Blank RFQ ↗'));
                    if(write&&r.status==='FINALIZED'&&r.age_seconds!==null&&
                       Number(r.age_seconds)>=0&&Number(r.age_seconds)<28800&&
                       Number(r.quote_count)===0&&Number(r.award_count)===0){
                        actions.push(h('button',{key:'edit',type:'button',className:'outline',onClick:()=>this.workflowLoadRFQEdit(r.id)},'Edit RFQ ('+Math.ceil((28800-Number(r.age_seconds))/60)+' min left)'));
                    }
                } else if(s.workflowStage==='AWARD'){
                    const path='api/print.php?organization_id='+encodeURIComponent(s.organization_id)+'&type=AWARD&id='+encodeURIComponent(r.id);
                    actions.push(h('a',{key:'abstract',href:path+'&form=ABSTRACT',target:'_blank',rel:'noopener'},'Print Abstract ↗'));
                    actions.push(h('a',{key:'resolution',href:path+'&form=RESOLUTION',target:'_blank',rel:'noopener'},'Print BAC Resolution ↗'));
                } else if(s.workflowStage==='PO'){
                    const path='api/print.php?organization_id='+encodeURIComponent(s.organization_id)+'&type=PO&id='+encodeURIComponent(r.id);
                    actions.push(h('a',{key:'print',className:'workflow-blank-rfq-link',href:path,target:'_blank',rel:'noopener','aria-label':'Print Purchase Order '+number},'Print PO ↗'));
                    if(write&&r.status==='FINALIZED')actions.push(r.iar_locked
                        ?h('span',{key:'locked',className:'workflow-hint',title:'An IAR has been prepared for this PO'},'Locked (IAR)')
                        :h('button',{key:'edit',type:'button',className:'outline',disabled:s.workflowPoEditLoading,
                            onClick:()=>this.workflowBeginPoEdit(r.id)},'Edit PO'));
                } else if(s.workflowStage==='IAR'){
                    const path='api/print.php?organization_id='+encodeURIComponent(s.organization_id)+'&type=IAR&id='+encodeURIComponent(r.id);
                    actions.push(h('a',{key:'print-iar',href:path,target:'_blank',rel:'noopener'},'Print IAR ↗'));
                    if(write&&r.status==='FINALIZED')actions.push(h('button',{key:'edit-iar',type:'button',className:'outline',disabled:s.workflowIarEditLoading,onClick:()=>this.workflowLoadIarEdit(r.id)},'Edit IAR'));
                } else if(s.workflowStage==='QUOTES'){
                    actions.push(h('button',{key:'related',type:'button',className:'outline',onClick:()=>this.setState({workflowFormOpen:true},()=>this.workflowLoadRFQ(String(r.rfq_id)))},'View RFQ quotations'));
                }
                return h('tr',{key:r.id},
                    h('td',{className:'workflow-register-nowrap'},number),
                    h('td',{className:'workflow-register-nowrap'},documentDate(r)?
                        (s.workflowStage==='AWARD'&&r.date_source==='RECORDED_AT_ONLY'?
                            documentDate(r)+' (recorded)':documentDate(r)):'Date unavailable'),
                    h('td',null,description),
                    h('td',null,purpose),
                    h('td',null,r.status|| (s.workflowStage==='QUOTES'?'Recorded':'—')),
                    h('td',null,h('div',{className:'workflow-register-actions'},...actions)));
            };
            // Quotations belong under their issued RFQ. A single RFQ can have many
            // quotations, and its issue date (not each supplier's offer date)
            // controls month/year grouping. No backend record or amount is changed.
            const quoteGroups=s.workflowStage==='QUOTES'?allRfqs.filter(r=>{
                const date=registerDate(r);
                return /^\d{4}-\d{2}-\d{2}$/.test(date)&&
                    (!month||date.slice(5,7)===month)&&(!year||date.slice(0,4)===year);
            }):[];
            const quoteByRfq=new Map();
            if(s.workflowStage==='QUOTES')for(const q of (s.lists.quotes||[])){
                const key=String(q.rfq_id??'');
                if(!quoteByRfq.has(key))quoteByRfq.set(key,[]);
                quoteByRfq.get(key).push(q);
            }
            const expandQuotes=rfqId=>{
                const key=String(rfqId);
                const expanded=(this.state.workflowExpandedQuotes||[]).map(String);
                this.setState({workflowExpandedQuotes:expanded.includes(key)?expanded.filter(id=>id!==key):[...expanded,key]});
            };
            const openQuoteFor=rfqId=>{
                if(!write)return;
                this.setState({workflowFormOpen:true,workflowRFQ:String(rfqId),workflowSupplier:'',workflowQuoteEvidence:'',
                    workflowQuoteLines:[],workflowError:'',workflowMessage:''},()=>this.workflowLoadRFQ(String(rfqId)));
            };
            const renderQuoteGroup=rfq=>{
                const key=String(rfq.id);
                const relatedPr=prFor(rfq);
                const supplierQuotes=quoteByRfq.get(key)||[];
                const expanded=(s.workflowExpandedQuotes||[]).some(id=>String(id)===key);
                const rfqLabel=rfq.display_number||'RFQ #'+key;
                return [h('tr',{key:'rfq-'+key,className:'workflow-quotation-parent'},
                    h('td',{className:'workflow-register-nowrap'},
                        h('button',{type:'button',className:'workflow-quote-expand',onClick:()=>expandQuotes(key),
                            'aria-expanded':expanded,
                            'aria-label':(expanded?'Collapse':'Expand')+' supplier quotations for '+rfqLabel},
                            h('span',{className:'workflow-quote-chevron','aria-hidden':'true'},expanded?'▾':'▸'),
                            h('strong',null,rfqLabel))),
                    h('td',{className:'workflow-register-nowrap'},registerDate(rfq)||'Date unavailable'),
                    h('td',{className:'workflow-register-nowrap'},rfq.pr_number||relatedPr?.display_number||'PR #'+(rfq.pr_id||'—')),
                    h('td',null,relatedPr?.purpose||rfq.pr_purpose||'Purpose unavailable — refresh data'),
                    h('td',null,h('span',{className:'count'},supplierQuotes.length+' '+(supplierQuotes.length===1?'quotation':'quotations'))),
                    h('td',null,h('div',{className:'workflow-register-actions'},
                        h('a',{className:'workflow-blank-rfq-link',href:blankRfqUrl(rfq),target:'_blank',rel:'noopener'},'Print Blank RFQ ↗'),
                        write?h('button',{type:'button',className:'outline',onClick:()=>openQuoteFor(key)},'+ Add quotation'):null))),
                    expanded?(supplierQuotes.length?supplierQuotes.map(q=>h('tr',{key:'quote-'+key+'-'+q.id,className:'workflow-quotation-child'},
                        h('td',null,h('span',{className:'workflow-quote-child-label'},'↳ Quotation record #'+q.id)),
                        h('td',{className:'workflow-register-nowrap'},documentDate(q)||'Date unavailable'),
                        h('td',null,q.supplier||'Supplier not listed'),
                        h('td',null,'Quoted total: '+money(q.offered_total)),
                        h('td',null,Number(q.noncompliant_lines)>0?'Has noncompliant items':'Compliant'),
                        h('td',null,h('span',{className:'workflow-quote-recorded'},'Recorded')))):
                        [h('tr',{key:'empty-'+key,className:'workflow-quotation-child'},
                            h('td',{colSpan:6,className:'workflow-quote-empty'},'No supplier quotations recorded for this RFQ yet.'))]):[]];
            };
            // An RFQ may be outside the bounded recent RFQ list while its
            // saved quote is still in the scoped quote list. Never lose it or
            // infer a date or PR number for an unavailable RFQ.
            const knownRfqIds=new Set(allRfqs.map(r=>String(r.id)));
            const historicalQuotes=s.workflowStage==='QUOTES'?(s.lists.quotes||[]).filter(q=>!knownRfqIds.has(String(q.rfq_id))):[];
            const undatedRfqs=s.workflowStage==='QUOTES'?allRfqs.filter(r=>!/^\d{4}-\d{2}-\d{2}$/.test(registerDate(r))):[];
            const quoteRows=quoteGroups.flatMap(r=>renderQuoteGroup(r).flat());
            const dataRows=s.workflowStage==='QUOTES'?quoteRows:visibleRows.map(renderRecordRow);
            const undatedRows=s.workflowStage==='QUOTES'?[]:allRows.filter(r=>!documentDate(r)).map(renderRecordRow);
            if(!dataRows.length)dataRows.push(h('tr',{key:'empty'},h('td',{colSpan:6,className:'empty'},
                s.workflowStage==='QUOTES'?(allRfqs.length?'No issued RFQs match the selected month and year. Change the filters to see earlier RFQs.':
                    'No issued RFQs are available in the recent register.'): 
                allRows.length?'No '+meta.label.toLowerCase()+' match the selected month and year. Change the filters to see earlier documents.':
                    'No dated '+meta.label.toLowerCase()+' are available in this workspace yet.')));
            const columnNames=s.workflowStage==='RFQ'?['RFQ No.','RFQ Date','PR No.','Purpose','Status','Actions']:
                s.workflowStage==='QUOTES'?['RFQ / Quotation','RFQ / Quote Date','PR No. / Supplier','Purpose / Quoted Total','Quotations / Compliance','Actions']:
                s.workflowStage==='AWARD'?['Award No.','Resolution / Recorded Date','Winning Bidder / RFQ','Purpose','Status','Actions']:
                ['Document No.',stageDateLabel,'Related document / supplier','Purpose','Status','Actions'];
            const modalTitle=s.workflowRFQEditId&&s.workflowStage==='RFQ'?'Correct RFQ':'New '+meta.singular;
            const closeModal=()=>{if(!this.state.busy)this.setState({workflowFormOpen:false,workflowRFQEditId:'',workflowRFQEditLines:[],workflowRFQEditVersion:0,workflowRFQEditError:'',workflowError:''});};
            const updatePoEdit=(key,value)=>this.setState({workflowPoEdit:{...this.state.workflowPoEdit,[key]:value},workflowPoEditError:''});
            return h('div',{className:'surface workflow-surface workflow-register-first'},
                h('div',{className:'section-head'},h('div',null,h('h3',null,'Procurement process'),h('p',{className:'body-muted'},'Select a stage to review its register. Create or edit documents in a separate window.')),
                    h('button',{className:'outline',type:'button',onClick:()=>this.workflowReload()},'↻ Refresh process')),
                h('div',{className:'workflow-steps',role:'tablist','aria-label':'Procurement steps'},...steps.map(([id,name],i)=>h('button',{key:id,type:'button',role:'tab','aria-selected':s.workflowStage===id,className:'workflow-step '+(s.workflowStage===id?'active':''),onClick:()=>this.workflowSetStage(id)},h('span',{className:'workflow-index'},String(i+1)),name))),
                h('section',{className:'workflow-panel',role:'tabpanel'},
                    s.workflowMessage?h('p',{className:'workflow-success',role:'status'},s.workflowMessage):null,
                    !s.workflowFormOpen&&s.workflowError?h('p',{className:'workflow-error',role:'alert'},s.workflowError):null,
                    h('div',{className:'workflow-register-intro'},
                        h('div',null,h('h3',null,meta.label+' Register'),h('p',{className:'body-muted'},s.workflowStage==='QUOTES'?'RFQs issued in the selected month. Expand an RFQ to see all supplier quotations, even those received later.':s.workflowStage==='AWARD'?'Every recorded award winner appears here, with links to the Abstract and BAC Resolution. Historical records show their recorded date when no Resolution date was captured.':'Documents in this workspace. Filter by month and year to trace issued records.')),
                        write?h('button',{type:'button',className:'primary',onClick:()=>{
                            const reset=s.workflowStage==='RFQ'?{workflowPR:'',workflowRfqDate:'',workflowRfqLines:[],workflowCanvasserId:'',workflowCanvasserHistoricalAttested:false,workflowCanvasserHistoricalReference:'',workflowChairHistoricalAttested:false,workflowChairHistoricalReference:'',workflowConsolidate:null,workflowConsolidatePrIds:[],workflowConsolidateExcluded:[],workflowConsolidateError:''}:
                                s.workflowStage==='QUOTES'?{workflowRFQ:'',workflowSupplier:'',workflowQuoteDate:localToday(),workflowQuoteEvidence:'',workflowQuoteLines:[]}:
                                s.workflowStage==='AWARD'?{workflowAwardRFQ:'',workflowAwardDate:localToday(),workflowQuoteId:'',workflowAwardNote:'',workflowAwardNoteManual:false,workflowAwardApproved:false}:
                                s.workflowStage==='PO'?{workflowAward:'',workflowPoDate:localToday(),workflowPoLines:[],workflowPoDeliveryDate:localToday(),workflowPoDeliveryPlace:this.workflowDefaultDeliveryPlace(),workflowPoDeliveryTerm:'Door-to-Door Delivery',workflowPoPaymentTerm:'Full Payment upon Delivery',workflowPoPaymentType:'Check',workflowPoMethod:''}:
                                {workflowPO:'',workflowIARLines:[],workflowDelivered:'',workflowAccepted:'',workflowIARInvoice:'',workflowIARInvoiceDate:'',workflowIARPoMeta:null,workflowIarDate:localToday(),workflowIARReviewed:false};
                            this.setState({...reset,workflowFormOpen:true,workflowError:'',workflowMessage:'',workflowRFQEditId:'',workflowRFQEditError:''},()=>{
                                if(s.workflowStage==='RFQ')this.loadStaffDirectory();
                            });
                        }},'+ New '+meta.singular):null),
                    h('div',{className:'workflow-register-controls'},
                        h('label',{className:'field'},h('span',null,'Month'),h('select',{'aria-label':'Filter '+meta.label+' register by month',value:month,onChange:e=>this.setState({workflowRegisterMonth:e.target.value})},
                            h('option',{value:''},'All months'),...months.map((name,i)=>h('option',{key:i,value:String(i+1).padStart(2,'0')},name)))),
                        h('label',{className:'field'},h('span',null,'Year'),h('select',{'aria-label':'Filter '+meta.label+' register by year',value:year,onChange:e=>this.setState({workflowRegisterYear:e.target.value})},
                            h('option',{value:''},'All years'),...years.map(y=>h('option',{key:y,value:y},y)))),
                        h('span',{className:'count','aria-live':'polite'},s.workflowStage==='QUOTES'?quoteGroups.length+' RFQs · '+quoteGroups.reduce((n,r)=>n+(quoteByRfq.get(String(r.id))||[]).length,0)+' quotations':visibleRows.length===allRows.length?allRows.length+' records':visibleRows.length+' of '+allRows.length+' records')),
                    h('div',{className:'workflow-table-scroll'},h('table',{className:'workflow-table'+(s.workflowStage==='QUOTES'?' workflow-quotation-tree':''),'aria-label':meta.label+' register'},
                        h('thead',null,h('tr',null,...columnNames.map(x=>h('th',{key:x,scope:'col'},x)))),h('tbody',null,...dataRows))),
                    s.workflowStage==='QUOTES'&&undatedRfqs.length?h('details',{className:'workflow-register-undated'},
                        h('summary',null,undatedRfqs.length+' RFQ(s) lack an issue date in the current response — view separately'),
                        h('p',{className:'workflow-register-note'},'These RFQs cannot be reliably assigned to a month.'),
                        h('div',{className:'workflow-table-scroll'},h('table',{className:'workflow-table workflow-quotation-tree'},
                            h('thead',null,h('tr',null,...columnNames.map(x=>h('th',{key:x,scope:'col'},x)))),
                            h('tbody',null,...undatedRfqs.flatMap(r=>renderQuoteGroup(r).flat()))))):null,
                    s.workflowStage==='QUOTES'&&historicalQuotes.length?h('details',{className:'workflow-register-undated'},
                        h('summary',null,historicalQuotes.length+' quotation(s) refer to RFQs outside the recently loaded RFQ register — view separately'),
                        h('p',{className:'workflow-register-note'},'Older linked RFQs are not included in the recent RFQ list. Their issue month and linked PR cannot be verified here. Quotations remain visible without inventing those details.'),
                        h('div',{className:'workflow-table-scroll'},h('table',{className:'workflow-table workflow-quotation-tree'},
                            h('thead',null,h('tr',null,...columnNames.map(x=>h('th',{key:x,scope:'col'},x)))),
                            h('tbody',null,...historicalQuotes.map(q=>h('tr',{key:'historic-quote-'+q.id},
                                h('td',null,'Quotation record #'+q.id),
                                h('td',null,documentDate(q)||'Date unavailable'),
                                h('td',null,q.supplier||'Supplier not listed'),
                                h('td',null,'Quoted total: '+money(q.offered_total)),
                                h('td',null,Number(q.noncompliant_lines)>0?'Has noncompliant items':'Compliant'),
                                h('td',null,'RFQ #'+String(q.rfq_id??'—')+' · outside recent list'))))))):null,
                    undatedRows.length?h('details',{className:'workflow-register-undated'},
                        h('summary',null,undatedRows.length+' record(s) have no date in the current register response — view separately'),
                        h('p',{className:'workflow-register-note'},'These records cannot be assigned to a month safely. They are excluded from the month/year totals until the server supplies the document date.'),
                        h('div',{className:'workflow-table-scroll'},h('table',{className:'workflow-table','aria-label':'Undated '+meta.label+' records'},
                            h('thead',null,h('tr',null,...columnNames.map(x=>h('th',{key:x,scope:'col'},x)))),
                            h('tbody',null,...undatedRows)))):null,
                    h('p',{className:'workflow-register-note'},'The register displays records returned by the current organization-scoped service (currently limited to recent records). Older records may require server-side history lookup.')),
                s.workflowStage==='IAR'&&s.workflowIarEditError&&!s.workflowIarEdit?h('p',{role:'alert',className:'workflow-error'},'Cannot edit IAR: '+s.workflowIarEditError):null,
                s.workflowStage==='IAR'&&s.workflowIarEdit?h('div',{className:'modal-backdrop workflow-create-backdrop',onMouseDown:e=>{if(e.target===e.currentTarget&&!s.busy)this.setState({workflowIarEdit:null});}},
                    h('section',{className:'modal workflow-create-modal',role:'dialog','aria-modal':'true','aria-label':'Correct IAR header',onClick:e=>e.stopPropagation()},
                        h('header',{className:'workflow-create-header'},h('h2',null,'Edit IAR '+s.workflowIarEdit.number),
                            h('button',{type:'button',className:'icon-button',disabled:s.busy,onClick:()=>this.setState({workflowIarEdit:null}),'aria-label':'Close Edit IAR'},'×')),
                        h('div',{className:'workflow-create-body'},
                            h('p',{className:'workflow-hint'},'Only dates delivered/inspected and supplier Invoice No./Date may be corrected. Item quantities, accepted value, PO linkage and inventory receipts remain locked.'),
                            s.workflowIarEdit.date_dependency_warning?h('p',{className:'workflow-hint',role:'alert'},
                                'Downstream evidence exists: '+s.workflowIarEdit.date_dependency_warning+'. Date corrections are documentary only; original inventory lot dates and DV/property evidence stay unchanged. Enter a correction reason for reconciliation.'):null,
                            s.workflowIarEditError?h('p',{className:'workflow-error',role:'alert'},s.workflowIarEditError):null,
                            h('form',{id:'iar-edit-form',onSubmit:e=>this.workflowSaveIarEdit(e),className:'workflow-form'},
                                field('Date Delivered',h('input',{type:'date',required:true,max:localToday(),disabled:s.busy,value:s.workflowIarEdit.delivered_at,onChange:e=>this.setState({workflowIarEdit:{...this.state.workflowIarEdit,delivered_at:e.target.value},workflowIarEditError:''})})),
                                field('Date Inspected / Accepted',h('input',{type:'date',required:true,max:localToday(),disabled:s.busy,min:s.workflowIarEdit.delivered_at,value:s.workflowIarEdit.accepted_at,onChange:e=>this.setState({workflowIarEdit:{...this.state.workflowIarEdit,accepted_at:e.target.value},workflowIarEditError:''})})),
                                s.workflowIarEdit.iar_date?h('p',{className:'workflow-hint'},'Separate issued IAR Date: '+s.workflowIarEdit.iar_date+' (not modified by this correction).'):null,
                                field('Invoice No.',h('input',{type:'text',maxLength:120,value:s.workflowIarEdit.invoice_no,onChange:e=>this.setState({workflowIarEdit:{...this.state.workflowIarEdit,invoice_no:e.target.value},workflowIarEditError:''})})),
                                field('Invoice Date',h('input',{type:'date',max:localToday(),value:s.workflowIarEdit.invoice_date||'',onChange:e=>this.setState({workflowIarEdit:{...this.state.workflowIarEdit,invoice_date:e.target.value},workflowIarEditError:''})})),
                                field('Correction note (optional)',h('textarea',{rows:2,maxLength:500,value:s.workflowIarEdit.reason,onChange:e=>this.setState({workflowIarEdit:{...this.state.workflowIarEdit,reason:e.target.value},workflowIarEditError:''})})),
                                h('button',{type:'submit',className:'primary',disabled:s.busy},s.busy?'Saving…':'Save IAR correction'))))):null,
                s.workflowStage==='PO'&&s.workflowPoEditError&&!s.workflowPoEdit?h('p',{role:'alert',className:'workflow-error'},'Cannot edit Purchase Order: '+s.workflowPoEditError):null,
                s.workflowStage==='PO'&&s.workflowPoEdit?h('div',{className:'modal-backdrop workflow-create-backdrop',
                    onMouseDown:e=>{if(e.target===e.currentTarget&&!s.busy)this.setState({workflowPoEdit:null,workflowPoEditError:''});}},
                    h('section',{className:'modal workflow-create-modal',role:'dialog','aria-modal':'true','aria-label':'Edit full Purchase Order'},
                        h('header',{className:'workflow-create-header'},h('div',null,h('h2',null,'Edit Purchase Order '+s.workflowPoEdit.number)),
                            h('button',{type:'button',className:'icon-button',onClick:()=>this.setState({workflowPoEdit:null,workflowPoEditError:''}),'aria-label':'Close edit Purchase Order'},'×')),
                        h('div',{className:'workflow-create-body'},
                            h('p',{className:'workflow-hint'},'This is a documentary correction, not approval. Edit the PO date, items, quantities and delivery/payment terms. Supplier information comes only from Supplier Profile; correct it there, then reopen this PO to refresh those details when saving. The awarded supplier and quoted unit costs remain linked to the recorded offer. Editing locks when an IAR is prepared.'),
                            h('p',null,'PO No.: '+s.workflowPoEdit.number+' · Awarded supplier: '+s.workflowPoEdit.supplier),
                            s.workflowPoEditError?h('p',{role:'alert',className:'workflow-error'},s.workflowPoEditError):null,
                            h('form',{className:'workflow-form',onSubmit:this.workflowSavePoEdit},
                                field('Purchase Order date *',h('input',{type:'date',required:true,max:localToday(),min:s.workflowPoEdit.min_document_date||undefined,
                                    value:s.workflowPoEdit.document_date,onChange:e=>updatePoEdit('document_date',e.target.value)}),s.workflowPoEdit.document_date),
                                h('section',{className:'workflow-po-supplier','aria-label':'Supplier details on this PO'},
                                    h('h4',null,'Supplier information'),
                                    h('p',null,h('strong',null,s.workflowPoEdit.supplier)),
                                    h('p',null,'Address: '+(s.workflowPoEdit.supplier_address||'Not recorded')),
                                    h('p',null,'TIN: '+(s.workflowPoEdit.supplier_tin||'Not recorded')),
                                    h('p',null,'ZIP: '+(s.workflowPoEdit.supplier_zip||'Not recorded')),
                                    h('p',null,'Proprietor: '+(s.workflowPoEdit.supplier_proprietor||'Not recorded')),
                                    h('p',{className:'workflow-hint'},'Read-only from Supplier Profile. To change these details, update Reference Data → Suppliers, then reopen this PO. Saving will capture the current profile in the next document revision.'),
                                    s.workflowPoEdit.supplier_profile_changed?h('p',{className:'workflow-hint'},'The Supplier Profile differs from the previous PO copy; saving this PO records the updated supplier profile in a new version.'):null),
                                h('h4',null,'Ordered items'),
                                smallTable(['Include','Item / Unit','Ordered quantity','Awarded unit cost (₱)','Amount (₱)'],
                                    (s.workflowPoEdit.lines||[]).map((l,i)=>h('tr',{key:l.pr_line_id},
                                        h('td',null,h('input',{type:'checkbox',checked:!!l.included,
                                            onChange:e=>this.setState({workflowPoEdit:{...s.workflowPoEdit,
                                                lines:s.workflowPoEdit.lines.map((x,j)=>i===j?{...x,included:e.target.checked,
                                                    quantity:e.target.checked&&Number(x.quantity)<=0?'1.000':x.quantity}:x)},workflowPoEditError:''}),
                                            'aria-label':'Include '+l.description})),
                                        h('td',null,l.description+' ('+l.unit+')'),
                                        h('td',null,h('input',{type:'number',min:'0.001',max:l.max_quantity,step:'0.001',disabled:!l.included,
                                            value:l.quantity,onChange:e=>this.setState({workflowPoEdit:{...s.workflowPoEdit,
                                                lines:s.workflowPoEdit.lines.map((x,j)=>i===j?{...x,quantity:e.target.value}:x)},workflowPoEditError:''}),
                                            'aria-label':'Corrected quantity '+l.description})),
                                        h('td',null,money(l.unit_price)),
                                        h('td',null,l.included?money(Number(l.quantity||0)*Number(l.unit_price||0)):'—')))),
                                h('p',{className:'workflow-po-grand-total'},h('strong',null,'Corrected grand total: ₱'+money(
                                    (s.workflowPoEdit.lines||[]).filter(x=>x.included).reduce((a,l)=>a+Number(l.quantity||0)*Number(l.unit_price||0),0)))),
                                field('Delivery date *',h('input',{type:'date',required:true,min:s.workflowPoEdit.document_date,
                                    value:s.workflowPoEdit.delivery_date,onChange:e=>updatePoEdit('delivery_date',e.target.value)})),
                                field('Place of delivery *',h('input',{required:true,type:'text',maxLength:500,value:s.workflowPoEdit.delivery_place,onChange:e=>updatePoEdit('delivery_place',e.target.value)})),
                                field('Delivery term',h('select',{required:true,value:s.workflowPoEdit.delivery_term,onChange:e=>updatePoEdit('delivery_term',e.target.value)},...['Door-to-Door Delivery','For Pick-up','Courier / Freight','Other (as agreed)'].map(x=>h('option',{key:x,value:x},x)))),
                                field('Payment term',h('select',{required:true,value:s.workflowPoEdit.payment_term,onChange:e=>updatePoEdit('payment_term',e.target.value)},...['Full Payment upon Delivery','Payment upon Inspection and Acceptance','Progress Billing (subject to contract)','Other (as agreed)'].map(x=>h('option',{key:x,value:x},x)))),
                                field('Payment type',h('select',{required:true,value:s.workflowPoEdit.payment_type,onChange:e=>updatePoEdit('payment_type',e.target.value)},...['Check','Cash','Bank Transfer','Other (as agreed)'].map(x=>h('option',{key:x,value:x},x)))),
                                field('Mode of procurement',h('select',{required:true,value:s.workflowPoEdit.procurement_method,onChange:e=>updatePoEdit('procurement_method',e.target.value)},...['Small Value Procurement','Negotiated Procurement','Competitive Bidding','Other (documented)'].map(x=>h('option',{key:x,value:x},x)))),
                                field('Correction note (optional)',h('textarea',{maxLength:500,rows:2,placeholder:'Example: one ordered item unavailable; adjust PO before IAR',value:s.workflowPoEdit.reason,onChange:e=>updatePoEdit('reason',e.target.value)})),
                                btn('Save Corrected PO'))),
                        h('footer',{className:'workflow-create-footer'},h('span',null,'The PO remains subject to handwritten signatures.'),
                            h('button',{type:'button',className:'outline',onClick:()=>this.setState({workflowPoEdit:null,workflowPoEditError:''})},'Cancel')))):null,
                s.workflowFormOpen?h('div',{className:'modal-backdrop workflow-create-backdrop',onMouseDown:e=>{if(e.target===e.currentTarget)closeModal();}},
                    h('section',{className:'modal workflow-create-modal',role:'dialog','aria-modal':'true','aria-labelledby':'workflow-create-title',
                        'aria-describedby':s.workflowError?'workflow-create-error':undefined,
                        onKeyDown:e=>{if(e.key==='Escape'){e.preventDefault();closeModal();}}},
                        h('header',{className:'workflow-create-header'},
                            h('div',null,h('span',{className:'workflow-create-eyebrow'},'PROCUREMENT / '+meta.label.toUpperCase()),h('h2',{id:'workflow-create-title'},modalTitle)),
                            h('button',{type:'button',className:'icon-button',disabled:s.busy,onClick:closeModal,'aria-label':'Close '+modalTitle},'×')),
                        h('div',{className:'workflow-create-body'},
                            s.workflowError?h('p',{id:'workflow-create-error',className:'workflow-error',role:'alert',tabIndex:-1,'aria-live':'assertive'},s.workflowError):null,
                            s.workflowMessage?h('p',{className:'workflow-success',role:'status'},s.workflowMessage):null,
                            content),
                        h('footer',{className:'workflow-create-footer'},
                            h('span',null,'Official document approvals require physical signatures.'),
                            h('button',{type:'button',className:'outline',disabled:s.busy,onClick:closeModal},'Close window')))):null);

        };
        this.procurementView = () => {
            const h=React.createElement,section=this.state.procurementSection||'PR';
            const prs=this.state.lists.pr||[];
            const finalized=prs.filter(p=>p.status==='FINALIZED').length;
            const drafts=prs.filter(p=>p.status==='DRAFT').length;
            const canWrite=this.canOperateWorkspace();
            return h('div',{className:'section-stack procurement-page'},
                this.header('Procurement workspace','Start with a Purchase Request, then continue through RFQ, quotations, award, PO and inspection.'),
                h('div',{className:'procurement-switch',role:'tablist','aria-label':'Procurement sections'},
                    h('button',{type:'button',role:'tab','aria-selected':section==='PR',className:'procurement-switch-tab '+(section==='PR'?'active':''),onClick:()=>this.setState({procurementSection:'PR'})},
                        '1 · Purchase Requests',h('small',null,'Create · Review · Finalize')),
                    h('button',{type:'button',role:'tab','aria-selected':section==='PROCESS',className:'procurement-switch-tab '+(section==='PROCESS'?'active':''),onClick:()=>this.setState({procurementSection:'PROCESS'},()=>this.workflowReload())},
                        '2 · Procurement Process',h('small',null,'RFQ · Quotations · Award · PO · IAR'))),
                section==='PR'?h('section',{role:'tabpanel',className:'procurement-section'},
                    h('div',{className:'surface pr-start-intro'},
                        h('div',{className:'pr-start-eyebrow'},'STEP 1 · BEGIN HERE'),
                        h('h3',null,'Prepare a Purchase Request'),
                        h('p',null,'Select the fund, describe what is needed, and add items from the catalog. Save a draft, review it, then finalize when ready for the required manual approval.'),
                        h('div',{className:'pr-start-counts'},h('span',null,drafts+' draft PR'+(drafts===1?'':'s')),
                            h('span',null,finalized+' finalized PR'+(finalized===1?'':'s'))),
                        h('div',{className:'pr-start-tools'},
                            canWrite?h('button',{type:'button',className:'primary',onClick:this.openCreatePR},
                                '+ New Purchase Request'):null,
                            finalized?h('button',{type:'button',className:'outline',onClick:()=>this.setState({procurementSection:'PROCESS',workflowStage:'RFQ'},()=>this.workflowReload())},
                                'Continue to RFQ →'):null)),
                    this.prRegister(),
                    h('p',{className:'pr-start-footnote'},'Finalization assigns a PR number. You may still revise it before canvassing; previous versions are recorded, and manual signatures remain required.')):
                    h('section',{role:'tabpanel',className:'procurement-section'},
                        this.workflowView(),
                        h('details',{className:'workflow-documents'},h('summary',null,'View Purchase Orders and Inspection Reports'),
                            this.table('Purchase orders',['display_number','supplier','status'],this.state.lists.po||[],'PO'),
                            this.table('Inspection & acceptance',['display_number','po_number','delivered_at','status'],this.state.lists.iar||[],'IAR'))));
        };
        this.sourceVoucherMessage = (r) => {
            const entries=r?.existing_dvs||[];
            if(!entries.length)return '';
            if(entries.length>1)return 'Multiple vouchers ('+entries.map(v=>'DV #'+v.id+' '+v.status).join(', ')+') already reference this document. Stop: review the duplicates before finalization.';
            const one=entries[0];
            return one.status==='DRAFT'
                ? 'Draft DV #'+one.id+' already exists. Use Edit Draft DV; do not create another.'
                : 'DV #'+one.id+' is '+one.status+'. Another DV cannot be created against this document.';
        };
        this.sourceVoucherLinks = (r) => (r?.existing_dvs||[]).map(v =>
            React.createElement('button',{type:'button',key:v.id,className:'outline',
                onClick:()=>this.setState({dvComposeOpen:false},()=>this.openDetail('DV',Number(v.id)))},
                (v.status==='DRAFT'?'Open / Edit Draft DV #':'Review DV #')+v.id));
        this.allocationKey = (type, r) => String(type === 'DIRECT' ? r.id : type === 'REGULAR' ? r.po_id : r.advance_id);
        this.dvView = () => {
            const h=React.createElement;
            const type=this.state.dv_type,isRegular=type==='REGULAR';
            const selectedSourceRows=isRegular?[]:Object.entries(this.state.allocations).filter(([_,v])=>v.enabled)
                .map(([id])=>this.state.eligible.find(r=>this.allocationKey(type,r)===id)).filter(Boolean);
            const selectedPO=isRegular?this.state.eligible.find(r=>String(r.po_id)===String(this.state.dv_po)):null;
            const availablePoRows=isRegular?dvUnusedPoRows(this.state.eligible):[];
            const searchedAvailablePoRows=isRegular?availablePoRows.filter(po=>
                dvPoMatchesSearch(po,this.state.dvSearch)||String(po.po_id)===String(this.state.dv_po)):[];
            const usedPoRows=isRegular?this.state.eligible.filter(po=>Array.isArray(po.existing_dvs)&&po.existing_dvs.length):[];
            const matchingUsedPoRows=isRegular&&String(this.state.dvSearch||'').trim()
                ?usedPoRows.filter(po=>dvPoMatchesSearch(po,this.state.dvSearch)).slice(0,5):[];
            const firstSource=isRegular?selectedPO:selectedSourceRows[0];
            const automaticPayee=isRegular?(selectedPO?.original_supplier||'Select a Purchase Order'):
                type==='REIMBURSEMENT'?firstSource?.original_payer||'Select an advance':this.state.payee;
            const poAlreadyUsed=isRegular&&!!selectedPO?.existing_dvs?.length;
            const chosenFund=firstSource?(this.state.lists.funds||[]).find(f=>String(f.id)===String(firstSource.fund_id)):null;
            const fundLabel=chosenFund?.name||(firstSource?'Fund #'+firstSource.fund_id:'Select a source first');
            const options=[{value:'REGULAR',label:'Regular DV',hint:'From Purchase Order'},{value:'DIRECT',label:'Direct / PR-only',hint:'From Purchase Request'},{value:'REIMBURSEMENT',label:'Reimbursement',hint:'From documented advance'}];
            const gross=isRegular?(selectedPO?Math.max(0,Number(selectedPO.accepted_total||0)-Number(selectedPO.committed||0)):null):
                selectedSourceRows.length?Number(this.state.allocations[this.allocationKey(type,firstSource)]?.amount||0):null;
            const money=n=>Number.isFinite(Number(n))?'₱'+Number(n).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}):'—';
            const display=(label,value)=>h('div',{className:'dv-readonly'},h('span',null,label),h('strong',null,value||'—'));
            const readonlySection=(heading,children)=>h('section',{className:'dv-form-panel'},h('h3',null,heading),children);
            return h('div',{className:'section-stack dv-workspace'},
                this.header('Disbursement vouchers','Prepare one voucher per source document, edit drafts and review the accounting before finalization.'),
                h('div',{className:'surface dv-start-panel'},
                    h('div',null,h('h3',null,'Disbursement voucher register'),
                        h('p',{className:'body-muted'},'Select an existing draft to continue editing. A Purchase Order or PR cannot start a second DV.')),
                    h('button',{type:'button',className:'primary',disabled:this.state.busy||!this.canOperateWorkspace(),
                        onClick:()=>this.setState({dvComposeOpen:true,dvSearch:'',dv_type:'REGULAR'},()=>this.loadEligible('REGULAR'))},'+ Prepare DV')),
                h('section',{className:'surface dv-register-desktop dv-register-compact','aria-label':'Disbursement voucher register'},
                    h('div',{className:'section-head'},h('h3',null,'Disbursement voucher register'),
                        h('span',{className:'count'},(this.state.lists.dv||[]).length+' records')),
                    h('div',{className:'dv-register-table-wrap'},
                        h('table',{className:'dv-register-table'},
                            h('thead',null,h('tr',null,
                                ...['DV Number','Type','Business / Payee','Gross','Deductions','Net','Status','Actions'].map(label=>h('th',{key:label},label)))),
                            h('tbody',null,(this.state.lists.dv||[]).length
                                ?(this.state.lists.dv||[]).slice(0,50).map(dv=>h('tr',{key:dv.id},
                                    h('td',{className:'dv-reg-number'},dv.display_number||'—'),
                                    h('td',null,dv.dv_type||'—'),
                                    h('td',{className:'dv-reg-payee'},
                                        h('strong',null,dv.business_name_snapshot||dv.payee||'—'),
                                        dv.proprietor_name_snapshot?h('small',null,'Proprietor: '+dv.proprietor_name_snapshot):null),
                                    h('td',{className:'dv-reg-money'},money(dv.gross)),
                                    h('td',{className:'dv-reg-money'},money(dv.deductions)),
                                    h('td',{className:'dv-reg-money'},money(dv.net)),
                                    h('td',null,h('span',{className:'status '+(dv.status||'')},dv.status||'—')),
                                    h('td',{className:'dv-reg-actions'},
                                        h('button',{type:'button',className:'text-btn',onClick:()=>this.openDetail('DV',Number(dv.id))},'Details'),
                                        dv.status==='FINALIZED'&&dv.display_number?h('a',{className:'text-btn',target:'_blank',rel:'noopener',
                                            href:'api/print.php?organization_id='+encodeURIComponent(this.state.organization_id)+'&type=DV&id='+dv.id},'Print DV ↗'):null,
                                        dv.status==='FINALIZED'?h('button',{type:'button',className:'text-btn',
                                            onClick:()=>this.openDetail('DV',Number(dv.id),false,'check')},'Check proof'):null,
                                        dv.status==='FINALIZED'&&Number(dv.deductions)>0?h('button',{type:'button',className:'text-btn',
                                            onClick:()=>this.openDetail('DV',Number(dv.id),false,'2307')},'BIR 2307 readiness'):null,
                                        dv.status==='DRAFT'&&this.canOperateWorkspace()?h('button',{type:'button',className:'text-btn',onClick:()=>this.openDetail('DV',Number(dv.id),true)},'Edit Draft'):null,
                                        dv.status==='DRAFT'&&this.canOperateWorkspace()?h('button',{type:'button',className:'text-btn',disabled:this.state.busy,onClick:()=>this.openDvFinalization(dv)},'Finalize'):null,
                                        dv.status==='FINALIZED'&&this.canOperateWorkspace()?h('button',{type:'button',className:'text-btn',disabled:this.state.busy,onClick:()=>this.openDvVoid(dv)},'Void issued DV'):null)))
                                :h('tr',null,h('td',{colSpan:8,className:'empty'},'No vouchers yet. Choose Prepare DV to start one.')))))),
                h('section',{className:'surface dv-register-mobile','aria-label':'Disbursement voucher register'},
                    h('div',{className:'section-head'},h('h3',null,'Disbursement voucher register'),
                        h('span',{className:'count'},(this.state.lists.dv||[]).length+' records')),
                    (this.state.lists.dv||[]).length
                        ?h('div',{className:'dv-register-cards'},...(this.state.lists.dv||[]).slice(0,50).map(dv=>
                            h('article',{className:'dv-mobile-register-card',key:dv.id},
                                h('div',{className:'dv-mobile-card-head'},
                                    h('div',null,
                                        h('strong',null,dv.display_number||(dv.status==='DRAFT'?'Draft DV #':'DV #')+dv.id),
                                        h('small',null,(dv.dv_type||'DV')+' · ID #'+dv.id)),
                                    h('span',{className:'status '+dv.status},dv.status)),
                                h('p',{className:'dv-mobile-payee'},dv.business_name_snapshot||dv.payee||'Payee unavailable',
                                    dv.proprietor_name_snapshot?h('small',{className:'dv-mobile-proprietor'},'Proprietor: '+dv.proprietor_name_snapshot):null),
                                h('dl',{className:'dv-mobile-card-totals'},
                                    h('div',null,h('dt',null,'Gross'),h('dd',null,money(dv.gross))),
                                    h('div',null,h('dt',null,'Withholding'),h('dd',null,money(dv.deductions))),
                                    h('div',null,h('dt',null,'Net'),h('dd',null,money(dv.net)))),
                                h('div',{className:'dv-mobile-card-actions'},
                                    h('button',{type:'button',className:'outline',
                                        onClick:()=>this.openDetail('DV',Number(dv.id))},'View details'),
                                    dv.status==='FINALIZED'?h('a',{className:'outline',target:'_blank',rel:'noopener',
                                        href:'api/print.php?organization_id='+encodeURIComponent(this.state.organization_id)+'&type=DV&id='+dv.id},'Print DV ↗'):null,
                                    dv.status==='FINALIZED'?h('button',{type:'button',className:'outline',
                                        onClick:()=>this.openDetail('DV',Number(dv.id),false,'check')},'Check proof'):null,
                                    dv.status==='FINALIZED'&&Number(dv.deductions)>0?h('button',{type:'button',className:'outline',
                                        onClick:()=>this.openDetail('DV',Number(dv.id),false,'2307')},'BIR 2307 readiness'):null,
                                    dv.status==='DRAFT'&&this.canOperateWorkspace()
                                        ?h('button',{type:'button',className:'primary',
                                            onClick:()=>this.openDetail('DV',Number(dv.id),true)},'Edit draft')
                                        :null,
                                    dv.status==='DRAFT'&&this.canOperateWorkspace()?h('button',{type:'button',className:'outline',disabled:this.state.busy,onClick:()=>this.openDvFinalization(dv)},'Finalize'):null,
                                    dv.status==='FINALIZED'&&this.canOperateWorkspace()?h('button',{type:'button',className:'outline',disabled:this.state.busy,onClick:()=>this.openDvVoid(dv)},'Void issued DV'):null))))
                        :h('p',{className:'hint'},'No vouchers yet. Choose Prepare DV to start one.'),
                    (this.state.lists.dv||[]).length>50?h('p',{className:'hint'},
                        'Showing the first 50 vouchers. Older records are not displayed in this register.'):null),
                this.state.dvFinalizePreview&&this.state.tab==='Disbursements'?
                    (()=>{const p=this.state.dvFinalizePreview,r=p.record||{},a=p.accounting_readiness||{},w=p.advance_liquidation||{};
                        const money=n=>Number(n||0).toLocaleString('en-PH',{style:'currency',currency:'PHP'});
                        const line=(label,value)=>h('div',{className:'pr-detail-field'},h('span',null,label),h('strong',null,String(value??'—')));
                        const auto=a.mode==='SCHOOL_NON_IUS_MOOE_ADVANCE';
                        const dataReady=!!a.sufficient_allocation&&!p.duplicate_source_dvs?.length&&(auto?!!w.ready:!(a.manual_posting_blocked));
                        return h('div',{className:'modal-backdrop',onMouseDown:e=>{if(e.target===e.currentTarget&&!this.state.busy)this.setState({dvFinalizePreview:null,dvActionError:''});}},
                            h('section',{className:'modal pr-detail-modal',role:'dialog','aria-modal':'true','aria-label':'Final review of draft DV',style:{display:'flex',flexDirection:'column',maxHeight:'min(90vh,850px)',minHeight:0}},
                                h('header',{className:'pr-detail-header'},h('div',null,h('span',{className:'pr-detail-eyebrow'},'ACCOUNTABLE DOCUMENT — FINAL REVIEW'),h('h2',null,'Finalize Draft DV #'+r.id)),
                                    h('button',{type:'button',className:'icon-button pr-detail-close',disabled:this.state.busy,onClick:()=>this.setState({dvFinalizePreview:null,dvActionError:''}),'aria-label':'Cancel final review'},'×')),
                                h('div',{className:'modal-scroll pr-detail-scroll',style:{overflowY:'auto',flex:'1 1 auto',minHeight:0,padding:'16px'}},
                                    h('p',{className:'notice'},'Review these saved voucher details before permanently assigning an accountable DV number. This review shows saved entries and the same compact withholding disclosure used by Appendix 32 printing; confirm all values before issuing the numbered DV.'),
                                    h('div',{className:'pr-detail-fields'},line('Payee',r.business_name_snapshot||r.payee),line('Voucher type',r.dv_type),line('Source fund',(this.state.lists.funds||[]).find(f=>Number(f.id)===Number(r.fund_id))?.name||'Fund #'+r.fund_id),
                                        line('Gross amount',money(r.gross)),line('Withholding',money(r.deductions)),line('Net payable',money(r.net)),line('DV number','Assigned only after Confirm Finalize')),
                                    h('section',{className:'surface',style:{margin:'12px 0'},'aria-label':'DV issuance date and physical check availability'},
                                        h('h3',null,'DV Date and expected check serial'),
                                        h('label',{className:'field'},h('span',null,'DV Date *'),
                                            h('input',{type:'date',required:true,max:localToday(),
                                                min:[p.dv_source_earliest_date,p.dv_last_accountable_date].filter(Boolean).sort().pop()||undefined,
                                                value:this.state.dvFinalizeDate||'',disabled:this.state.busy,
                                                onChange:e=>this.setState({dvFinalizeDate:e.target.value,dvActionError:''})})),
                                        weekendDocumentNotice(h,this.state.dvFinalizeDate,'DV Date'),
                                        h('p',{className:'hint'},'Earliest linked document: '+(p.dv_source_earliest_date||'not available')+
                                            ' · Previous numbered DV lower bound: '+(p.dv_last_accountable_date||'none recorded')),
                                        h('label',{className:'field'},h('span',null,'Bank / verified physical checkbook *'),
                                            h('select',{value:this.state.dvCheckBank,disabled:this.state.busy,onChange:e=>{
                                                const bank=e.target.value;
                                                this.setState({dvCheckBank:bank,dvCheckStock:null,dvCheckLeafConfirmed:false,
                                                    dvFundAvailable:null,dvFundLoading:!!bank},async()=>{
                                                    this.fetchNextPhysicalCheck(bank,'finalize');
                                                    if(!bank)return;
                                                    const org=String(this.state.organization_id),fundId=Number(r.fund_id);
                                                    try{const value=await this.get('fund.availability',{organization_id:org,fund_id:fundId});
                                                        if(String(this.state.organization_id)===org && String(this.state.dvCheckBank)===bank && Number(this.state.dvFinalizePreview?.record?.id)===Number(r.id))
                                                            this.setState({dvFundAvailable:value,dvFundLoading:false});
                                                    }catch(err){if(String(this.state.organization_id)===org && String(this.state.dvCheckBank)===bank)
                                                        this.setState({dvFundAvailable:{error:err.message},dvFundLoading:false});}
                                                });
                                            }},h('option',{value:''},'Select a registered school bank'),...(this.state.lists.banks||[]).map(b=>h('option',{key:b.id,value:String(b.id)},b.bank_name+' — '+b.account_label)))),
                                        h('div',{className:'pr-detail-fields'},
                                            h('div',{className:'pr-detail-field'},h('span',null,'Expected next check number'),
                                                h('strong',null,this.state.dvCheckLoading?'Checking verified stock…':
                                                    this.state.dvCheckStock?.check_number
                                                    ?this.state.dvCheckStock.check_number+' · '+this.state.dvCheckStock.book_reference+' · Awaiting physical confirmation'
                                                    :this.state.dvCheckStock?.message||'Select bank to view verified physical check stock'))),
                                        this.state.dvCheckBank?h('div',{className:'pr-detail-fields',style:{marginTop:8}},
                                            h('div',{className:'pr-detail-field'},h('span',null,'Available fund balance — selected fund ONLY'),
                                                h('strong',null,this.state.dvFundLoading?'Refreshing fund balance…':
                                                    this.state.dvFundAvailable?.error?'Unavailable: '+this.state.dvFundAvailable.error:
                                                    this.state.dvFundAvailable?.available_allocation!=null?
                                                    money(this.state.dvFundAvailable.available_allocation):'No verified balance loaded'))):null,
                                        r.dv_type==='REGULAR'&&Number(r.supplier_id)>0?h('label',{className:'field'},
                                            h('span',null,'Check payee name *'),
                                            h('select',{value:this.state.dvCheckPayeeBasis,onChange:e=>this.setState({dvCheckPayeeBasis:e.target.value})},
                                                h('option',{value:'BUSINESS'},'Business — '+(r.business_name_snapshot||'Missing business name')),
                                                r.proprietor_name_snapshot?h('option',{value:'PROPRIETOR'},'Proprietor — '+r.proprietor_name_snapshot):null)):null,
                                        h('label',{className:'dv-cancel-acknowledge',style:{marginTop:8}},
                                            h('input',{type:'checkbox',checked:!!this.state.dvCheckLeafConfirmed,
                                                disabled:!this.state.dvCheckStock?.leaf_id||this.state.dvCheckLoading,
                                                onChange:e=>this.setState({dvCheckLeafConfirmed:e.target.checked})}),
                                            h('span',null,'I personally compared Check No. '+(this.state.dvCheckStock?.check_number||'—')+
                                                ' with the next blank, intact leaf in the actual checkbook and confirm it is available for assignment to this DV.')),
                                        h('p',{className:'hint'},'The chosen check will be assigned when DV finalization succeeds and will appear on the printed DV. It is NOT yet signed, released, bank-cleared, or a new cash disbursement.')),
                                    h('section',{className:'dv-finalize-particulars','aria-label':'Payment particulars'},
                                        h('h3',null,'Particulars'),
                                        h('p',{style:{whiteSpace:'pre-wrap',lineHeight:'1.65'}},r.particulars||p.suggested_particulars||'Particulars not yet recorded.'),
                                        h('p',{className:'hint'},'This wording becomes part of the accountable DV on finalization. Edit the draft to change it; this preview does not change the source PR.'),
                                        h('button',{type:'button',className:'outline',disabled:this.state.busy,onClick:()=>{
                                            this.setState({dvFinalizePreview:null,viewType:'DV',details:p,dvActionError:''},()=>this.beginDvParticularsEdit(p));
                                        }},'Edit Draft Particulars'),
                                        dvCompactSavedWithholding(h,r,p.withholding||[])),
                                    h('section',{className:'dv-finalize-journal','aria-label':'Debit and credit workpaper'},
                                        h('h3',null,'Debit and credit workpaper'),
                                        auto?h('div',null,
                                            h('p',{className:'hint'},'Journal presentation only. Expense and asset accounts are debits; the operating advance and withheld-tax liability are credits.'),
                                            h('div',{className:'table-scroll',style:{overflowX:'auto'}},
                                                h('table',{className:'data-table dv-journal-table',style:{minWidth:'475px',width:'100%',fontVariantNumeric:'tabular-nums'},'aria-label':'DV finalization journal entries'},
                                                    h('thead',null,h('tr',null,
                                                        h('th',{scope:'col'},'Account title / UACS'),
                                                        h('th',{scope:'col',style:{textAlign:'right',whiteSpace:'nowrap'}},'Debit'),
                                                        h('th',{scope:'col',style:{textAlign:'right',whiteSpace:'nowrap'}},'Credit'))),
                                                    h('tbody',null,...(w.entries||[]).map((entry,i)=>{
                                                        const credit=Number(entry.credit)>0;
                                                        return h('tr',{key:i},
                                                            h('td',{'data-label':'Account'},
                                                                h('div',{style:{paddingLeft:credit?'22px':'0',display:'flex',alignItems:'baseline',gap:'10px'}},
                                                                    h('strong',{style:{color:credit?'#476477':'#203b50',minWidth:'20px'}},credit?'Cr':'Dr'),
                                                                    h('div',null,
                                                                        h('div',{style:{fontWeight:credit?500:650}},entry.account_name||'Account description unavailable'),
                                                                        h('small',{style:{color:'#64788c'}},entry.uacs_code||'UACS not recorded')))),
                                                            h('td',{'data-label':'Debit',className:'dv-journal-money',style:{textAlign:'right',whiteSpace:'nowrap'}},Number(entry.debit)>0?money(entry.debit):'—'),
                                                            h('td',{'data-label':'Credit',className:'dv-journal-money',style:{textAlign:'right',whiteSpace:'nowrap'}},credit?money(entry.credit):'—'));
                                                    })),
                                                    h('tfoot',null,h('tr',null,
                                                        h('th',{scope:'row'},'Total'),
                                                        h('th',{style:{textAlign:'right',whiteSpace:'nowrap'}},money(w.debit_total)),
                                                        h('th',{style:{textAlign:'right',whiteSpace:'nowrap'}},money(w.credit_total)))))),
                                            h('p',{className:w.ready?'hint':'notice error'},w.ready?'Balanced workpaper ready (not a bank posting).':(w.issues||[]).join(' '))):
                                            h('div',{className:'form-grid'},
                                                this.select('debitAccount','Debit UACS account',(this.state.accounts||[]).map(x=>({id:x.code,name:x.code+' — '+x.account_name}))),
                                                this.select('creditAccount','Credit UACS account',(this.state.accounts||[]).map(x=>({id:x.code,name:x.code+' — '+x.account_name}))),
                                                (a.manual_posting_issues||[]).length?h('p',{className:'notice error'},a.manual_posting_issues.join(' ')):null)),
                                    h('p',{className:a.sufficient_allocation?'hint':'notice error'},a.sufficient_allocation?'Funding-source allocation will be rechecked at confirmation.':'Insufficient authorized source fund allocation; cannot finalize.'),
                                    h('p',{className:'hint'},'On confirmation, the exact verified physical leaf is linked to the numbered DV. A printed/spoiled check must not be casually freed or reassigned.'),
                                    p.tax_disposition_mismatch?h('p',{className:'notice error',role:'alert'},
                                        'Draft withholding tax disposition conflicts with Division fund policy. Close this preview, open DV Details, and use Correct Draft Tax Disposition before finalizing.'):null,
                                    this.state.dvActionError?h('p',{className:'notice error',role:'alert'},this.state.dvActionError):null),
                                h('footer',{className:'pr-detail-actions'},
                                    h('button',{type:'button',className:'outline',disabled:this.state.busy,onClick:()=>this.setState({dvFinalizePreview:null,dvActionError:''})},'Cancel'),
                                    h('button',{type:'button',className:'primary',disabled:this.state.busy||!dataReady||this.state.dvFundLoading||!!this.state.dvFundAvailable?.error||
                                            !this.state.dvCheckBank||!this.state.dvCheckStock?.leaf_id||!this.state.dvCheckLeafConfirmed||
                                            this.manualTaxPostingBlocked(r)||!!p.tax_disposition_mismatch,onClick:this.confirmDvFinalization},this.state.busy?'Finalizing…':'Confirm Finalize'))));})():null,
                this.state.dvVoidPreview&&this.state.tab==='Disbursements'?
                    (()=>{const v=this.state.dvVoidPreview,r=v.record;
                        return h('div',{className:'modal-backdrop',onMouseDown:e=>{if(e.target===e.currentTarget&&!this.state.busy)this.setState({dvVoidPreview:null,dvActionError:''});}},
                            h('section',{className:'modal pr-detail-modal',role:'dialog','aria-modal':'true','aria-label':'Void accountable finalized DV',style:{display:'flex',flexDirection:'column',maxHeight:'min(90vh,720px)',minHeight:0}},
                                h('header',{className:'pr-detail-header'},h('div',null,h('span',{className:'pr-detail-eyebrow'},'ACCOUNTABLE ISSUANCE RECORD'),h('h2',null,'Void Issued DV '+r.display_number)),
                                    h('button',{type:'button',className:'icon-button pr-detail-close',disabled:this.state.busy,onClick:()=>this.setState({dvVoidPreview:null,dvActionError:''})},'×')),
                                h('div',{className:'modal-scroll pr-detail-scroll',style:{overflowY:'auto',flex:'1 1 auto',minHeight:0,padding:'16px'}},
                                    h('p',{className:'notice error'},'The original DV number always remains accountable. For a linked check, direct void is permitted ONLY if it was assigned but never signed or released AND is physically damaged/unusable. Still-usable check recovery or issued/released checks require controlled reconciliation.'),
                                    h('div',{className:'pr-detail-fields'},h('div',{className:'pr-detail-field'},h('span',null,'Payee'),h('strong',null,r.payee||'—')),
                                        h('div',{className:'pr-detail-field'},h('span',null,'Gross / withholding / net'),h('strong',null,[r.gross,r.deductions,r.net].join(' / '))),
                                        h('div',{className:'pr-detail-field'},h('span',null,'Assigned check'),
                                            h('strong',null,v.checkNumber?('#'+v.checkNumber+' · '+v.checkStatus):'None recorded'))),
                                    v.checkNumber?h('label',{className:'field'},h('span',null,'Actual condition of attached physical check *'),
                                        h('select',{value:v.checkCondition,onChange:e=>this.setState({dvVoidPreview:{...v,checkCondition:e.target.value,checkConfirmed:false},dvActionError:''})},
                                            h('option',{value:''},'Choose actual physical condition'),
                                            h('option',{value:'USABLE'},'Intact / still usable — requires separate recovery review'),
                                            h('option',{value:'UNUSABLE'},'Damaged / spoiled / not usable — permanently cancel check'))):null,
                                    v.checkNumber && v.checkCondition==='UNUSABLE'?h('label',{className:'dv-cancel-acknowledge'},
                                        h('input',{type:'checkbox',checked:!!v.checkConfirmed,onChange:e=>this.setState({dvVoidPreview:{...v,checkConfirmed:e.target.checked},dvActionError:''})}),
                                        h('span',null,'I physically verified Check No. '+v.checkNumber+' is unusable; its serial will never be reused.')):null,
                                    v.checkNumber && v.checkCondition==='USABLE'?h('p',{className:'notice error'},
                                        'Do not void or free this usable physical check through this form. A finance-approved recovery/reassignment procedure is required to preserve the original assignment history.'):null,
                                    h('label',{className:'field'},h('span',null,'Documentary reason for voiding *'),
                                        h('textarea',{required:true,minLength:12,maxLength:500,rows:3,value:v.reason,onChange:e=>this.setState({dvVoidPreview:{...v,reason:e.target.value},dvActionError:''})})),
                                    h('label',{className:'dv-cancel-acknowledge'},h('input',{type:'checkbox',checked:v.confirmed,onChange:e=>this.setState({dvVoidPreview:{...v,confirmed:e.target.checked},dvActionError:''})}),
                                        h('span',null,'I verified DV '+r.display_number+' must be voided. Its original number and history stay in the register.')),
                                    this.state.dvActionError?h('p',{className:'notice error',role:'alert'},this.state.dvActionError):null),
                                h('footer',{className:'pr-detail-actions'},
                                    h('button',{type:'button',className:'outline',disabled:this.state.busy,onClick:()=>this.setState({dvVoidPreview:null,dvActionError:''})},'Keep DV'),
                                    h('button',{type:'button',className:'primary',disabled:this.state.busy||!v.confirmed||v.reason.trim().length<12||
                                        (v.checkNumber && (v.checkCondition!=='UNUSABLE'||!v.checkConfirmed)),onClick:this.confirmDvVoid},this.state.busy?'Voiding…':'Confirm Void Issued DV'))));})():null,
                this.state.dvComposeOpen&&this.state.tab==='Disbursements'?h('div',{className:'modal-backdrop dv-compose-backdrop',onMouseDown:e=>{
                        if(e.target===e.currentTarget&&!this.state.busy)this.setState({dvComposeOpen:false});
                    }},h('section',{className:'modal dv-compose-modal',role:'dialog','aria-modal':'true','aria-labelledby':'dv-compose-title',
                        onKeyDown:e=>{if(e.key==='Escape'&&!this.state.busy)this.setState({dvComposeOpen:false});}},
                    h('header',{className:'pr-detail-header'},
                        h('div',null,h('span',{className:'pr-detail-eyebrow'},'DISBURSEMENTS / VOUCHER CREATION'),
                            h('h2',{id:'dv-compose-title'},'Prepare Disbursement Voucher'),
                            h('small',{className:'hint'},'DV picker · v0.2.160')),
                        h('button',{type:'button',className:'icon-button pr-detail-close',disabled:this.state.busy,
                            onClick:()=>this.setState({dvComposeOpen:false}),'aria-label':'Close DV form'},'×')),
                    h('div',{className:'modal-scroll dv-compose-scroll'},
                        h('nav',{className:'dv-type-tabs','aria-label':'Type of disbursement voucher'},
                            options.map(o=>h('button',{key:o.value,type:'button',className:type===o.value?'active':'',
                                'aria-pressed':type===o.value,
                                onClick:()=>this.setState({dv_type:o.value,dvSearch:''},()=>this.loadEligible(o.value))},
                                h('strong',null,o.label),h('small',null,o.hint)))),
                        h('form',{id:'dv-compose-form',onSubmit:this.createDV},
                            readonlySection(isRegular?'Purchase Order search':type==='DIRECT'?'Purchase Request search':'Advance / reimbursement reference',
                                h('div',{className:'dv-source-picker'},
                                    isRegular?h('label',{className:'field dv-search-box'},h('span',null,'Filter available POs by number, PR or supplier'),
                                        h('input',{type:'search',value:this.state.dvSearch,onChange:e=>this.setState({dvSearch:e.target.value}),placeholder:'Search purchase orders…'})):null,
                                    isRegular?React.createElement('div',{className:'section-stack'},
                        React.createElement('label',{className:'field'},React.createElement('span',null,'Purchase Order *'),
                            React.createElement('select',{required:true,value:this.state.dv_po,onChange:e=>this.chooseDvPo(e.target.value)},
                                React.createElement('option',{value:''},'Select one finalized PO with accepted unpaid delivery'),
                                ...searchedAvailablePoRows.map(po=>React.createElement('option',{key:po.po_id,value:po.po_id},
                                    (po.po_number||'PO #'+po.po_id)+' · '+po.original_supplier+' · payable ₱'+
                                    (Number(po.accepted_total)-Number(po.committed)).toFixed(2) )))),
                        this.state.eligibleLoading?React.createElement('p',{className:'hint'},'Loading eligible POs…'):null,
                        this.state.eligibleError?React.createElement('p',{className:'notice error'},this.state.eligibleError):null,
                        !this.state.eligibleLoading&&!this.state.eligibleError&&!availablePoRows.length?
                            React.createElement('p',{className:'hint'},usedPoRows.length?
                                'No unused PO is available for a new DV. Existing POs already have a linked voucher; open the Disbursement Voucher register to continue or review those records.':
                                'No PO has accepted unpaid deliveries. Finalize an IAR first, or verify that the PO has not already been paid.'):null,
                        !this.state.eligibleLoading&&usedPoRows.length?
                            React.createElement('p',{className:'hint',role:'status'},usedPoRows.length+
                                ' Purchase Order(s) with an existing Draft, Finalized or Cancelled DV are excluded from this selector. Use the DV register for existing records.'):null,
                        matchingUsedPoRows.length?React.createElement('div',{className:'dv-existing-po-results','aria-label':'Existing vouchers for searched POs'},
                            React.createElement('p',{className:'hint'},'Already linked to a DV — not selectable for a new voucher:'),
                            ...matchingUsedPoRows.map(po=>React.createElement('div',{key:po.po_id,className:'dv-existing-po-result'},
                                React.createElement('span',null,(po.po_number||'PO #'+po.po_id)+' · '+(po.original_supplier||'Supplier')+' — '+this.sourceVoucherMessage(po)),
                                ...this.sourceVoucherLinks(po)))):null,
                        poAlreadyUsed?React.createElement('div',{className:'notice error',role:'alert'},
                            React.createElement('p',null,this.sourceVoucherMessage(selectedPO)),
                            ...this.sourceVoucherLinks(selectedPO)):null,
                        selectedPO?React.createElement('label',{className:'field'},
                            React.createElement('span',null,'Applicable supplier tax profile *'),
                            React.createElement('select',{required:true,value:this.state.dv_po_tax_profile,onChange:e=>this.chooseDvTaxProfile(e.target.value)},
                                React.createElement('option',{value:''},'Select applicable profile'),
                                ...this.allocationProfileOptions(selectedPO).map(profile=>React.createElement('option',{key:profile,value:profile},this.profileLabel(profile))))):null,
                        React.createElement('p',{className:'hint'},'PO details and accepted unpaid amounts are retrieved automatically. No item selection needed.')):null,
                                    !isRegular?React.createElement('div',{className:'dv-manual-allocations'},
                    React.createElement("div", { className: "line-heading" },
                        React.createElement("strong", null, "Eligible source allocations"),
                        React.createElement("span", { className: "muted" }, "Select ONE PR / advance · Edit existing drafts")),
                    React.createElement("div", { className: "allocation-list" },
                        this.state.eligibleLoading ? React.createElement('p', { className: 'empty', role: 'status' }, 'Loading eligible voucher references…') :
                        this.state.eligibleError ? React.createElement('div', { className: 'notice error', role: 'alert' },
                            React.createElement('p', null, this.state.eligibleError),
                            React.createElement('button', { type: 'button', className: 'outline', onClick: () => this.loadEligible(type) }, 'Retry loading references')) :
                        !this.state.eligible.length ? React.createElement("p", { className: "empty" }, "No available voucher references for this DV type.") : this.state.eligible.map((r) => {
                            const key=this.allocationKey(type,r),s=this.state.allocations[key]||{};
                            const profileOptions=type==='DIRECT'?[]:this.allocationProfileOptions(r);
                            const defaultProfile=type==='DIRECT'?'':this.defaultAllocationTaxProfile(r);
                            return React.createElement("div",{className:"allocation",key:key},
                                React.createElement("input",{type:"checkbox",checked:!!s.enabled,disabled:!!r.existing_dvs?.length,"aria-label":"Select allocation "+key,
                                    onChange:e=>this.setState({allocations:e.target.checked?{[key]:{...s,enabled:true,
                                        amount:s.amount||String(type==='DIRECT'?Math.max(0,Number(r.total||0)-Number(r.committed||0)).toFixed(2):Math.max(0,Number(r.amount||0)-Number(r.reimbursed||0)).toFixed(2)),
                                        tax_profile:s.tax_profile||defaultProfile}}:{}})}),
                                React.createElement("div",{className:"allocation-details"},
                                    React.createElement("strong",null,type==='DIRECT'?r.display_number:type==='REGULAR'?r.iar_number:r.iar_number+' · '+r.original_supplier),
                                    r.existing_dvs?.length?React.createElement('div',{className:'notice error',role:'status'},
                                        React.createElement('p',null,this.sourceVoucherMessage(r)),...this.sourceVoucherLinks(r)):null,
                                    React.createElement("small",null,type==='REIMBURSEMENT'
                                        ? 'Original payer: '+r.original_payer+' · Supplier VAT: '+(r.vat_status||'Unknown')+' · Profiles: '+(profileOptions.map(x=>this.profileLabel(x)).join(', ')||'none')+' · '+r.source+' · Reimbursed ₱'+r.reimbursed+' · remaining ₱'+(Number(r.amount)-Number(r.reimbursed||0)).toFixed(2)
                                        : type==='REGULAR'
                                            ? 'PR '+(r.pr_number||'—')+' · PO '+(r.po_number||'—')+' · Supplier: '+(r.original_supplier||r.supplier_id)+' · '+(r.vat_status||'Unknown VAT')+' · Profiles: '+(profileOptions.map(x=>this.profileLabel(x)).join(', ')||'none')+' · Accepted ₱'+r.accepted_amount+' · allocated ₱'+r.committed
                                            : 'PR total ₱'+r.total+' · already paid ₱'+(r.committed||'0.00')+' · remaining ₱'+(Number(r.total)-Number(r.committed||0)).toFixed(2)),
                                    s.enabled&&type!=='DIRECT'?React.createElement("label",{className:"allocation-profile"},
                                        React.createElement("span",null,"Tax profile"),
                                        React.createElement("select",{value:s.tax_profile||defaultProfile||'',
                                            onChange:e=>this.setState({allocations:{...this.state.allocations,[key]:{...s,tax_profile:e.target.value}}})},
                                            React.createElement("option",{value:""},"Select profile"),
                                            ...profileOptions.map(profile=>React.createElement("option",{key:profile,value:profile},this.profileLabel(profile))))):null),
                                React.createElement("input",{"aria-label":'Allocation amount '+key,className:"money-input",placeholder:"Amount (₱)",value:s.amount||'',
                                    onChange:e=>this.setState({allocations:{...this.state.allocations,[key]:{...s,amount:e.target.value}}})}));
                        }))
                    ):null)),
                            h('div',{className:'dv-info-grid'},
                                readonlySection('Supplier details',h('div',{className:'dv-detail-grid'},
                                    type==='DIRECT'?null:display(type==='REIMBURSEMENT'?'Original payer':'Business name / payee',automaticPayee),
                                    isRegular?display('Proprietor',selectedPO?.business_classification==='INDIVIDUAL'?(selectedPO?.proprietor_name||'Missing in supplier profile'):'Not applicable'):null,
                                    display('Source of fund',fundLabel),
                                    isRegular?display('VAT registration',selectedPO?.vat_status||'Not recorded'):null,
                                    type==='DIRECT'?this.field('payee','Payee name','Full name of payee'):null,
                                    type==='DIRECT'?this.field('bill_reference','Bill / evidence reference','Invoice or bill reference'):null)),
                                readonlySection('Payment details',h('div',{className:'dv-detail-grid'},
                                    display('Gross amount',gross===null?'Select a reference':money(gross)),
                                    display('Withholding',isRegular?(this.state.dvTaxPreview?money(this.state.dvTaxPreview.deductions):'Awaiting tax calculation'):
                                        (this.state.dvTaxes.length?'Review '+this.state.dvTaxes.length+' tax component(s)':'No tax components selected')),
                                    display('Net amount',isRegular?(this.state.dvTaxPreview?money(this.state.dvTaxPreview.net):'Awaiting tax calculation'):
                                        'Calculated and verified when draft is saved'),
                                    display('DV number','Assigned only upon finalization')))),
                            readonlySection('Payment particulars',h('div',{className:'dv-particulars'},
                                isRegular&&selectedPO?h('p',null,'PR ',selectedPO.pr_number||'—',' · PO ',selectedPO.po_number||'PO #'+selectedPO.po_id,
                                    ' · Accepted unpaid purchase items are linked automatically. No item re-entry is needed.'):
                                h('p',null,'Select the source document above. Its references and supported amounts will be retained in the DV.'),
                                h('p',{className:'hint'},'Official supporting documents and manual signatures remain required.'))),
                            (isRegular?this.regularDvTaxesView(selectedPO,money):readonlySection('Withholding taxes',h('div',{className:'dv-tax-simple'},
                                h('p',{className:'body-muted'},'Use supplier ATC suggestions when available. Verify the applicable tax profile, rates and tax base before saving. Suggestions are not automatic approval.'),
                                React.createElement('div',{className:'dv-tax-builder'},
                        React.createElement('div',{className:'section-head'},React.createElement('h3',null,'Withholding tax (ATC)'),React.createElement('div',{className:'inline-actions'},React.createElement('button',{type:'button',className:'outline',onClick:this.suggestSupplierTaxes},'Suggest supplier ATCs'),React.createElement('button',{type:'button',className:'outline',onClick:()=>this.addDvTax('VAT')},'+ VAT Withholding'),React.createElement('button',{type:'button',className:'outline',onClick:()=>this.addDvTax('EWT')},'+ EWT'))),
                        React.createElement('p',{className:'body-muted'},'VAT withholding and EWT are separate tax components. For Regular DVs the tax base applies to the selected PO, not to individual items. Choose the correct supplier tax profile first. Rental, Contractor, Utilities and Other are never folded into ordinary Services. Verify the ATC, base, VAT registration status, and disposition before saving.'),
                        React.createElement('p',{className:'hint',role:'status'},(()=>{
                            const fund=(this.state.lists.funds||[]).find(f=>String(f.id)===String(firstSource?.fund_id));
                            if(!fund)return 'Select a PO or PR to review its Division withholding treatment.';
                            const schoolType=this.state.fundPolicies?.school_classification?.classification;
                            if(!this.state.fundPolicies)return 'Loading Division withholding policy; the server checks it again at DV finalization.';
                            if(!schoolType)return 'School IUS/Non-IUS status is unverified; school utilization is blocked until the Division classifies this school.';
                            const record=(this.state.fundPolicies.policies||[]).find(r=>r.school_class===schoolType&&r.source_code===fund.source_code);
                            return record?.policy==='DIVISION_SETTLES_UTILIZABLE'
                                ? 'Division policy: '+fund.source_code+' ('+schoolType+') permits school-utilization disposition. This does not create cash, increase the authorized allocation, confirm tax settlement or show that retained tax cash has already been spent.'
                                : 'Division policy: '+fund.source_code+' ('+schoolType+') does not permit school-utilization disposition. Use remittance, or ask Division to register its current policy.';
                        })()),
                        ...this.state.dvTaxes.map((tax,i)=>React.createElement('div',{className:'dv-tax-row',key:i},
                            React.createElement('label',{className:'field'},React.createElement('span',null,'Tax type'),React.createElement('select',{value:tax.tax_kind||'EWT',onChange:e=>this.setState({dvTaxes:this.state.dvTaxes.map((row,j)=>j===i?{...row,tax_kind:e.target.value,rate_version_id:''}:row)})},React.createElement('option',{value:'VAT'},'VAT Withholding'),React.createElement('option',{value:'EWT'},'EWT'))),
                            React.createElement('label',{className:'field'},React.createElement('span',null,'ATC / effective rate'),React.createElement('select',{required:true,value:tax.rate_version_id,onChange:e=>this.updateDvTax(i,'rate_version_id',e.target.value)},React.createElement('option',{value:''},'Select rate version'),...(this.state.lists.atc_rates||[]).filter(r=>r.tax_kind===(tax.tax_kind||'EWT')&&Number(r.atc_active)&&r.starts<=new Intl.DateTimeFormat('sv-SE',{timeZone:'Asia/Manila'}).format(new Date())&&(!r.ends||r.ends>=new Intl.DateTimeFormat('sv-SE',{timeZone:'Asia/Manila'}).format(new Date()))).map(r=>React.createElement('option',{key:r.id,value:r.id},r.atc_code+' · '+(Number(r.rate)*100).toFixed(4)+'% · '+r.description)))),
                            !isRegular?React.createElement('label',{className:'field'},React.createElement('span',null,'Apply to selected reference'),React.createElement('select',{value:tax.allocation_index,onChange:e=>this.updateDvTax(i,'allocation_index',e.target.value)},...Object.entries(this.state.allocations).filter(([k,v])=>v.enabled).map(([key,value],idx)=>React.createElement('option',{key:key,value:String(idx)},'#'+(idx+1)+' · '+key+' · ₱'+value.amount)))):null,
                            React.createElement('label',{className:'field'},React.createElement('span',null,isRegular?'PO tax base (₱)':'Tax base (₱)'),React.createElement('input',{required:true,value:tax.tax_base,onChange:e=>this.updateDvTax(i,'tax_base',e.target.value),placeholder:'Taxable base'})),
                            React.createElement('label',{className:'field'},React.createElement('span',null,'Disposition'),React.createElement('select',{value:tax.disposition,onChange:e=>this.updateDvTax(i,'disposition',e.target.value)},React.createElement('option',{value:'REMIT'},'For remittance'),React.createElement('option',{value:'SCHOOL_UTILIZATION'},'For school utilization (pending confirmation)'))),
                            React.createElement('button',{type:'button',className:'text-btn',onClick:()=>this.removeDvTax(i)},'Remove component'))))))),
                            readonlySection('Journal entries',h('div',{className:'dv-journal-help'},
                                h('p',null,'The balanced UACS workpaper is available in DV Details after saving the draft. It does not issue a check or post a second bank expense.'),
                                h('p',{className:'hint'},'Missing item-to-UACS mappings must be resolved by the Division before finalization.'))),
                            poAlreadyUsed?h('p',{className:'notice error',role:'alert'},'This PO already has a DV. Open and edit the existing Draft DV instead of creating another.'):null,
                            type==='REIMBURSEMENT'?h('p',{className:'hint'},'Reimbursement payee must be the documented original payer; no second stock receipt is created.'):null)),
                    h('footer',{className:'pr-detail-actions dv-compose-actions'},
                        h('button',{type:'button',className:'outline',disabled:this.state.busy,onClick:()=>this.setState({dvComposeOpen:false})},'Cancel'),
                        !poAlreadyUsed?h('button',{type:'submit',form:'dv-compose-form',className:'primary',disabled:this.state.busy||!firstSource||(isRegular&&(!this.state.dvTaxPreview||this.state.dvTaxBusy))},
                            this.state.busy?'Saving…':'Save Draft DV'):null))) :null);
        };
        // v0.2.218 uses the existing school-scoped list route. No global assets
        // or browser-side 100-record truncation; server provides 25-row pages.
        this.loadPropertyRegister = async (reset=true) => {
            const org=String(this.state.organization_id||'');
            if(!org||schoolScopedDivision(this.state)||this.state.user?.role==='VIEWER')return;
            const token=this.propertyRegisterToken=(this.propertyRegisterToken||0)+1;
            const cursor=reset?null:this.state.propertyRegister?.next_before_id;
            this.setState({propertyRegisterLoading:true,propertyRegisterError:''});
            try{
                const filters=this.state.propertyRegisterFilters||{search:'',category:'ALL',classification:'ALL',item_id:'ALL',custodian:'ALL',status:'ALL',source:'ALL'};
                const params=Object.assign({kind:'assets',register_page:'1',organization_id:org},filters,
                    cursor?{before_id:cursor}:{});
                const data=await this.get('list',params);
                if(token!==this.propertyRegisterToken||String(this.state.organization_id)!==org)return;
                this.setState(prev=>({propertyRegister:{rows:reset?data.rows:[...(prev.propertyRegister?.rows||[]),...data.rows],
                    facets:data.facets,has_more:Boolean(data.has_more),next_before_id:data.next_before_id},propertyRegisterLoading:false}));
            }catch(e){if(token===this.propertyRegisterToken)this.setState({propertyRegisterError:e.message||'Could not load Asset Register',propertyRegisterLoading:false});}
        };
        this.propertyFilter = (key,value) => {
            this.setState(prev=>({propertyRegisterFilters:Object.assign({},prev.propertyRegisterFilters,{[key]:value})}),
                ()=>this.loadPropertyRegister(true));
        };
        this.openPropertyDetails = async (id,fromBatch=false) => {
            const org=String(this.state.organization_id||'');
            if(!fromBatch)this.propertyDialogReturnFocus=document.activeElement;
            const token=this.propertyDetailToken=(this.propertyDetailToken||0)+1;
            this.setState({propertyDetailId:String(id),propertyDetailOrg:org,propertyDetail:null,propertyDetailLoading:true,propertyDetailError:'',propertyDetailTab:fromBatch?'batch':'overview'});
            try{
                const detail=await this.get('property.registration.detail',{organization_id:org,asset_id:id});
                if(token===this.propertyDetailToken && String(this.state.organization_id)===org)
                    this.setState({propertyDetail:detail,propertyDetailLoading:false,propertyDetailEditShared:Object.assign({},detail.shared||{}),propertyDetailEditUnit:Object.assign({},detail.unit||{}),propertyDetailEditReason:'',propertyDetailEditMode:'UNIT'});
            }catch(e){if(token===this.propertyDetailToken)this.setState({propertyDetailLoading:false,propertyDetailError:e.message||'Unable to load property details'});}
        };
        this.closePropertyDetails = () => {if(this.state.propertyDetailBusy)return;this.propertyDetailToken=(this.propertyDetailToken||0)+1;this.setState({propertyDetailId:'',propertyDetailOrg:'',propertyDetail:null,propertyDetailError:'',propertyDetailLoading:false,propertyDetailTab:'overview',propertyDetailEditReason:'',propertyDetailEditShared:{},propertyDetailEditUnit:{},propertyDetailEditMode:'UNIT'});};
        this.savePropertyDetailProfile = async event => {
            event.preventDefault();
            const d=this.state.propertyDetail,org=String(this.state.organization_id),mode=this.state.propertyDetailEditMode;
            if(!d||this.state.propertyDetailBusy||this.state.propertyDetailOrg!==org)return;
            if(String(this.state.propertyDetailEditReason||'').trim().length<12){this.setState({propertyDetailError:'Enter a meaningful correction reason (at least 12 characters).'});return;}
            const count=mode==='BATCH'?(d.batch_units||[]).length:1;
            if(!window.confirm('Save audited property profile corrections for '+count+' physical unit(s)? This does not alter property tags, cost, custodian, issued ICS/PAR or approved condition.'))return;
            this.setState({propertyDetailBusy:true,propertyDetailError:''});
            try{
                const ids=mode==='BATCH'?(d.batch_units||[]).map(x=>Number(x.id)):[Number(d.asset_id)];
                const versions=(d.versions||[]).filter(x=>ids.includes(Number(x.id)));
                const response=await this.task('property.registration.correct',{
                    asset_id:Number(d.asset_id),mode,profile_edit:true,reason:this.state.propertyDetailEditReason,
                    expected_asset_ids:ids,expected_versions:versions,shared:this.state.propertyDetailEditShared,
                    ...(mode==='UNIT'?{unit:this.state.propertyDetailEditUnit}:{})
                },{refresh:false});
                if(org!==String(this.state.organization_id))return;
                const updated=await this.get('property.registration.detail',{organization_id:org,asset_id:d.asset_id});
                if(this.state.propertyDetailOrg!==org||Number(this.state.propertyDetailId)!==Number(d.asset_id))return;
                this.setState({propertyDetail:updated,propertyDetailTab:'overview',propertyDetailError:'',
                    propertyDetailEditShared:Object.assign({},updated.shared||{}),propertyDetailEditUnit:Object.assign({},updated.unit||{}),
                    propertyDetailEditReason:'',propertyDetailEditMode:'UNIT'});
                this.showPropertyNotice('Property details updated: '+(response.corrected_asset_ids||[]).length+' physical unit(s). Each change is audited.');
                await this.loadPropertyRegister(true);
            }catch(error){this.setState({propertyDetailError:error.message||'Property correction could not be saved. Reload this unit before retrying.'});}
            finally{this.setState({propertyDetailBusy:false});}
        };
        this.loadAccountableProperty = async () => {
            const org=String(this.state.organization_id||'');
            if(!org)return;
            const token=this.propertyLoadToken=(this.propertyLoadToken||0)+1;
            this.setState({propertyBusy:true,propertyError:'',propertyAssetIds:[],propertyCustodianSearch:'',propertyCustodianEmployeeId:'',propertyRecords:{assets:[],accountability:[],received_property:[]}});
            this.loadPropertyRegister(true);
            try {
                const [assets,accountability,received_property]=schoolScopedDivision(this.state)
                    ? [...await Promise.all([this.get('division.accountability.eligible',{organization_id:org}),
                        this.get('list',{kind:'accountability',organization_id:org})]),[]]
                    : await Promise.all(['assets','accountability','received_property'].map(kind=>this.get('list',{kind,organization_id:org})));
                if(token===this.propertyLoadToken&&String(this.state.organization_id)===org)
                    this.setState({propertyRecords:{assets,accountability,received_property},propertyBusy:false});
            }catch(e){
                if(token===this.propertyLoadToken&&String(this.state.organization_id)===org)
                    this.setState({propertyError:e.message||'Unable to load property records',propertyBusy:false});
            }
        };
        this.registerAccountableAssets = async e => {
            e.preventDefault();
            if(this.state.propertyBusy||this.state.user?.role==='VIEWER')return;
            const row=(this.state.propertyRecords.received_property||[]).find(r=>String(r.iar_line_id)===this.state.propertyReceivedId);
            const count=Number(this.state.propertyUnitCount);
            const remaining=row?Number(row.accepted_qty)-Number(row.registered_units):0;
            if(!row||!Number.isInteger(count)||count<1||count>100||!Number.isInteger(remaining)||count>remaining){
                this.setState({propertyError:'Select an accepted IAR line and register 1–100 whole units, not exceeding the remaining accepted units.'});return;
            }
            if(!window.confirm('Register '+count+' accountable property unit(s) from '+row.iar_number+'? The school registration numbers will be automatically assigned and permanently recorded.'))return;
            this.setState({propertyBusy:true,propertyError:'',propertyRegistrationNotice:''});
            try{
                const result=await this.task('asset.iar.register',{iar_line_id:Number(row.iar_line_id),unit_count:count,shared:{brand:this.state.propertyBatchShared.brand,model:this.state.propertyBatchShared.model,useful_life_years:this.state.propertyBatchShared.useful_life_years,evidence_note:this.state.propertyBatchShared.evidence_note},units:Array.from({length:count},(_,i)=>this.state.propertyBatchUnits[i]||{})},{refresh:false});
                if(result){
                    const numbers=Array.isArray(result.property_numbers)?result.property_numbers:[];
                    this.showPropertyNotice(numbers.length?'Registered '+numbers.length+' property unit(s): '+numbers.join(', '):'Property registration completed.',{
                        propertyUnitCount:'1',propertyReceivedId:'',propertyBatchUnits:[this.emptyPropertyUnit()],propertyBatchShared:this.emptyPropertyShared()});
                    await this.loadAccountableProperty();
                }
            }catch(e){this.setState({propertyError:e.message||'Property registration failed'});}
            finally{this.setState({propertyBusy:false});}
        };
        this.issueAccountability = async e => {
            e.preventDefault();
            if(this.state.propertyBusy||this.state.user?.role==='VIEWER')return;
            const assets=this.state.propertyRecords.assets||[];
            const ids=(this.state.propertyAssetIds||[]).map(Number);
            const selected=ids.map(id=>assets.find(a=>Number(a.id)===id));
            const kind=selected[0]?.required_document;
            const staff=String(this.state.staffData?.organization?.id||'')===String(this.state.organization_id)
                ?(this.state.staffData?.employees||[]):[];
            const chosen=staff.find(e=>e.active&&String(e.employee_id)===String(this.state.propertyCustodianEmployeeId));
            const already=a=>(this.state.propertyRecords.accountability||[]).some(r=>
                String(r.asset_id)===String(a.id)&&r.status==='FINALIZED'&&!Boolean(Number(r.returned_to_school)))||a.latest_accountability_number;
            if(!selected.length||selected.length>20||selected.some(a=>!a||a.required_document!==kind||a.status!=='ACTIVE'||a.review_state==='PENDING_REVIEW'||already(a)||(Number(a.ever_returned_to_school)===1&&a.condition_note!=='Serviceable'))||
                (selected.length>1&&kind!=='ICS')||!chosen){
                this.setState({propertyError:'Choose up to 20 reviewed and unissued ICS properties of the same type, and an active employee from this school.'});return;
            }
            const custodian=String(chosen.full_name||'').trim();
            if(!custodian||!window.confirm('Issue ONE '+kind+' for '+selected.length+' property unit(s) to '+custodian+'? A single ICS number will appear on all listed assets. Manual signatures are still required.'))return;
            this.setState({propertyBusy:true,propertyError:''});
            try{
                const result=await this.task('accountability.issue',{
                    asset_ids:ids,doc_type:kind,custodian_employee_id:Number(chosen.employee_id)
                },{refresh:false});
                if(result){this.setState({propertyAssetId:'',propertyAssetIds:[],propertyCustodian:'',propertyCustodianSearch:'',propertyCustodianEmployeeId:''});
                    this.showPropertyNotice('Issued '+kind+' '+result.number+' for '+selected.length+' property unit(s) to '+custodian+'. Obtain physical signatures.');
                    await this.loadAccountableProperty();}
            }catch(error){this.setState({propertyError:error.message||'Accountability issuance failed'});}
            finally{this.setState({propertyBusy:false});}
        };
        // Keep property successes short-lived and user-dismissible. Do not let a late
        // timer clear a newer result or a notice belonging to another school/tab.
        this.clearPropertyNotice=()=>{
            this.propertyNoticeToken=(this.propertyNoticeToken||0)+1;
            if(this.propertyNoticeTimer)window.clearTimeout(this.propertyNoticeTimer);
            this.propertyNoticeTimer=null;
            if(this.state.propertyRegistrationNotice)this.setState({propertyRegistrationNotice:''});
        };
        this.showPropertyNotice=(message,additionalState={})=>{
            this.propertyNoticeToken=(this.propertyNoticeToken||0)+1;
            const token=this.propertyNoticeToken;
            if(this.propertyNoticeTimer)window.clearTimeout(this.propertyNoticeTimer);
            this.setState(Object.assign({},additionalState,{propertyRegistrationNotice:message}));
            this.propertyNoticeTimer=window.setTimeout(()=>{
                if(this.propertyNoticeToken===token){
                    this.propertyNoticeTimer=null;
                    this.setState({propertyRegistrationNotice:''});
                }
            },7000);
        };
        this.emptyPropertyShared=()=>({brand:'',model:'',acquisition_type:'PURCHASE',useful_life_years:'',historical_po:'',historical_iar:'',historical_dv:'',evidence_note:''});
        this.emptyPropertyUnit=()=>({serial_number:'',location:'',condition_note:'',legacy_property_number:''});
        // Historical property registration and controlled pre-issuance corrections.
        // Source documents supplied here are evidence text, never official linked PO/IAR/DV IDs.
        this.propertySetShared = (key,value) => this.setState(prev=>({propertyBatchShared:Object.assign({},prev.propertyBatchShared,{[key]:value})}));
        this.propertySetUnit = (idx,key,value) => this.setState(prev=>{
            const next=prev.propertyBatchUnits.slice();
            next[idx]=Object.assign({},next[idx]||{}, {[key]:value});
            return {propertyBatchUnits:next};
        });
        this.propertyUnitGrid = count => {
            const h=React.createElement;
            if(!Number.isInteger(count)||count<1||count>100)return h('p',{className:'error'},'Enter a number of units between 1 and 100.');
            return h('div',{className:'property-unit-grid'},
                ...Array.from({length:count},(_,i)=>{
                    const unit=this.state.propertyBatchUnits[i]||{};
                    return h('fieldset',{key:i,className:'property-unit-card'},
                        h('legend',null,'Physical unit '+(i+1)+' · property number generated upon saving'),
                        ...[
                            ['serial_number','Serial number'],['location','Location or room'],
                            ['condition_note','Condition or remarks'],['legacy_property_number','Old school property number']
                        ].map(([key,title])=>h('label',{key,className:'field'},h('span',null,title),
                            h('input',{value:unit[key]||'',maxLength:key==='condition_note'?255:(key==='serial_number'?120:(key==='legacy_property_number'?100:(key==='reported_custodian'?160:180))),
                                onChange:e=>this.propertySetUnit(i,key,e.target.value)}))));
                }));
        };
        this.propertySharedFields = historical => {
            const h=React.createElement,b=this.state.propertyBatchShared||{};
            const fields=[['brand','Brand'],['model','Model'],['useful_life_years','Useful life (years, optional)'],['evidence_note','Acquisition evidence / reference note']];
            if(historical)fields.splice(2,0,['acquisition_type','Acquisition type (PURCHASE, DONATION, TRANSFER, OTHER or UNKNOWN)']);
            if(historical)fields.push(['historical_po','Historical PO No. (optional)'],['historical_iar','Historical IAR No. (optional)'],['historical_dv','Historical DV No. (optional)']);
            return h('div',{className:'form-grid'},...fields.map(([key,label])=>h('label',{className:'field',key},
                h('span',null,label),key==='acquisition_type'?h('select',{value:b[key]||'PURCHASE',onChange:e=>this.propertySetShared(key,e.target.value)},
                    ...['PURCHASE','DONATION','TRANSFER','OTHER','UNKNOWN'].map(v=>h('option',{key:v,value:v},v))):
                    h('input',{maxLength:key==='evidence_note'?500:120,value:b[key]||'',
                    onChange:e=>this.propertySetShared(key,e.target.value)}))));
        };
        this.registerHistoricalProperty = async ev => {
            ev.preventDefault();
            if(this.state.propertyBusy || schoolScopedDivision(this.state) || this.state.user?.role==='VIEWER')return;
            const count=Number(this.state.propertyBatchCount);
            if(!Number.isInteger(count)||count<1||count>100||!this.state.propertyBatchItem){
                this.setState({propertyError:'Select a PPE/semi-expendable item and 1–100 whole physical units.'});return;
            }
            if(!window.confirm('Register '+count+' historical accountable unit(s) with unique permanent property numbers? This does NOT create a PO/IAR/DV, stock event or ICS/PAR.'))return;
            this.setState({propertyBusy:true,propertyError:'',propertyRegistrationNotice:''});
            try{
                const response=await this.task('asset.opening',{
                    item_id:Number(this.state.propertyBatchItem),unit_count:count,
                    acquired_on:this.state.propertyBatchDate||null,acquisition_cost:this.state.propertyBatchCost,
                    shared:this.state.propertyBatchShared,
                    units:Array.from({length:count},(_,i)=>this.state.propertyBatchUnits[i]||{})
                },{refresh:false});
                if(response){
                    this.showPropertyNotice('Historical property registered: '+(response.property_numbers||[]).join(', '),{
                        propertyBatchItem:'',propertyBatchCount:'1',propertyBatchDate:'',propertyBatchCost:'',
                        propertyBatchShared:this.emptyPropertyShared(),propertyBatchUnits:[this.emptyPropertyUnit()]});
                    await this.loadAccountableProperty();
                }
            }catch(e){this.setState({propertyError:e.message||'Historical registration failed'});}
            finally{this.setState({propertyBusy:false});}
        };
        this.loadPropertyEditPreview=async id=>{
            if(!id)return this.setState({propertyEditAssetId:'',propertyEditPreview:null});
            const requestedOrg=String(this.state.organization_id);
            this.setState({propertyBusy:true,propertyError:'',propertyEditPreview:null,propertyEditAssetId:id});
            try{
                const detail=await this.get('property.registration.detail',{organization_id:requestedOrg,asset_id:id});
                if(String(this.state.organization_id)===requestedOrg && String(this.state.propertyEditAssetId)===String(id))
                    this.setState({propertyEditPreview:detail,propertyEditShared:detail.shared||{},propertyEditUnit:detail.unit||{},propertyEditMode:'UNIT',propertyEditReason:'',propertyConfirmRegistration:false});
            }catch(e){this.setState({propertyError:e.message||'Unable to read property edit preview'});}
            finally{this.setState({propertyBusy:false});}
        };
        this.savePropertyEdit=async ev=>{
            ev.preventDefault();
            const item=this.state.propertyEditPreview;
            if(!item||this.state.propertyBusy)return;
            const mode=this.state.propertyEditMode;
            if(!window.confirm(this.state.propertyConfirmRegistration
                ?'Confirm that you physically checked this unit and recorded its current location and condition? The review and any changes will be audited.'
                :'Correct '+(mode==='BATCH'?(item.batch_units||[]).length+' units in the identified batch':'one physical unit')+'? The prior and new values will be audited.'))return;
            this.setState({propertyBusy:true,propertyError:''});
            try{
                const response=await this.task('property.registration.correct',{
                    asset_id:Number(item.asset_id),mode,reason:this.state.propertyEditReason,
                    expected_asset_ids:mode==='BATCH'?(item.batch_units||[]).map(x=>Number(x.id)):[Number(item.asset_id)],
                    expected_versions:mode==='BATCH'?item.versions:(item.versions||[]).filter(x=>Number(x.id)===Number(item.asset_id)),
                    ...(mode==='BATCH'?{shared:this.state.propertyEditShared}:{unit:this.state.propertyEditUnit,confirm_registration:Boolean(this.state.propertyConfirmRegistration),...(this.state.propertyConfirmRegistration?{shared:this.state.propertyEditShared}:{})})
                },{refresh:false});
                if(response){
                    this.showPropertyNotice((this.state.propertyConfirmRegistration?'Property physically reviewed and available for controlled ICS/PAR issuance. ':'Corrected '+(response.corrected_asset_ids||[]).length+' asset(s). ')+'Permanent property number and prior custody records remain unchanged.',{propertyEditPreview:null,propertyEditAssetId:'',propertyConfirmRegistration:false});
                    await this.loadAccountableProperty();
                }
            }catch(e){this.setState({propertyError:e.message||'Property correction rejected'});}
            finally{this.setState({propertyBusy:false});}
        };
        this.printCombinedIcsRis = async (record,icsOnly=false,semiReport=false) => {
            // Read-only print from the already authenticated, school-scoped UI.
            // Popup must open on the click, before any asynchronous reads.
            if(!record||record.doc_type!=='ICS'||record.status!=='FINALIZED')return;
            const org=String(this.state.organization_id||'');
            const popup=window.open('about:blank','_blank');
            if(!popup){this.setState({propertyError:'Allow popups for IPDAMS to print RIS + ICS.'});return;}
            popup.document.write('<!doctype html><title>Preparing RIS + ICS</title><p>Reading saved document details…</p>');
            popup.document.close();
            try {
                if(!window.IPDAMSCombinedICSPrint)throw new Error('Combined print template did not load. Hard refresh before printing.');
                const memberIds=String(record.property_asset_ids||record.asset_id).split(',').filter(Boolean);
                if(memberIds.length<1||memberIds.length>20||new Set(memberIds).size!==memberIds.length)throw new Error('Invalid ICS property membership.');
                const details=await Promise.all(memberIds.map(asset_id=>this.get('property.registration.detail',{organization_id:org,asset_id})));
                const detail=details[0];
                if(org!==String(this.state.organization_id))throw new Error('Active school changed during printing. Start again.');
                if(String(detail.asset_id)!==String(record.asset_id)||String(detail.property_number)!==String(record.property_tag))
                    throw new Error('ICS and property identity do not match. Refresh this school.');
                for(const member of details){
                    if(!(member.accountability||[]).some(a=>a.doc_type==='ICS'&&a.status==='FINALIZED'&&String(a.display_number)===String(record.display_number)))
                        throw new Error('An ICS property is not confirmed in the issued custody register.');
                }
                const orgRow=(this.state.orgs||[]).find(x=>String(x.id)===org)||{};
                // Current school appointments may suggest names; never fabricate a
                // historical approval, date, position or wet signature.
                let directory=null;
                try{directory=await this.get('staff.directory',{organization_id:org});}catch(_ignored){/* names remain blank */}
                if(org!==String(this.state.organization_id))throw new Error('School switched while preparing the document.');
                const employee=id=>(directory?.employees||[]).find(e=>String(e.employee_id)===String(id)&&e.active)||null;
                const principal=employee(directory?.head?.employee_id);
                const custodianOfficer=employee(directory?.custodian?.employee_id);
                const aoTitle=String(custodianOfficer?.position_title||custodianOfficer?.designation||'');
                const schoolAO=/administrative officer|\bao\s*[-i0-9]/i.test(aoTitle)?custodianOfficer:null;
                const namedRecipient=(directory?.employees||[]).find(e=>e.active&&String(e.full_name||'').trim().toLowerCase()===String(record.custodian||'').trim().toLowerCase());
                const shared=detail.details?.shared||{};
                const dto={doc_type:'ICS',status:record.status,display_number:record.display_number,
                    print_mode:semiReport?'SEMI_REPORT':(icsOnly?'ICS':'PAIR'),report_date:String(record.created_at||'').slice(0,10),items:details.map(member=>({
                        property_tag:member.property_number,item_name:member.item_name,unit:'unit',
                        unit_cost:member.details?.acquisition_cost,
                        useful_life:member.details?.shared?.useful_life_years?String(member.details.shared.useful_life_years)+' years':''
                    })),
                    property_tag:detail.property_number,item_name:detail.item_name,
                    unit:'unit',unit_cost:detail.details?.acquisition_cost,
                    useful_life:shared.useful_life_years?String(shared.useful_life_years)+' years':'',
                    organization_name:orgRow.name||'DEPARTMENT OF EDUCATION',
                    school_name:orgRow.kind==='SCHOOL'?(orgRow.name||''):'',
                    division_name:'',fund_cluster:'',rcc:'',
                    recipient:{name:record.custodian||'',designation:namedRecipient?.position_title||namedRecipient?.designation||''},
                    school_principal:{name:principal?.full_name||'',designation:principal?.position_title||principal?.designation||'School Principal'},
                    school_ao:{name:schoolAO?.full_name||'',designation:schoolAO?.position_title||schoolAO?.designation||'Administrative Officer'},
                    supply_custodian:{name:custodianOfficer?.full_name||'',designation:custodianOfficer?.position_title||custodianOfficer?.designation||''}};
                window.IPDAMSCombinedICSPrint.show(dto,popup);
            } catch(error){
                const safeMsg=String(error?.message||'Unable to prepare RIS + ICS').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
                popup.document.open();popup.document.write('<!doctype html><title>Print unavailable</title><p>'+safeMsg+'</p>');popup.document.close();
                this.setState({propertyError:error?.message||'Unable to prepare RIS + ICS'});
            }
        };
        this.propertyAccountabilityView = () => {
            if(this.state.user?.role==='VIEWER')return this.mooeIssuedDocumentView('Property Accountability');
            const h=React.createElement,records=this.state.propertyRecords||{};
            const assets=records.assets||[],accountability=records.accountability||[];
            const selectedLine=(records.received_property||[]).find(r=>String(r.iar_line_id)===this.state.propertyReceivedId);
            const selectedAssets=(this.state.propertyAssetIds||[]).map(id=>assets.find(a=>String(a.id)===String(id))).filter(Boolean);
            const selectedAsset=selectedAssets[0]||null;
            const selectedKind=selectedAsset?.required_document||null;
            const issued=selectedAsset&&((accountability.some(r=>String(r.asset_id)===String(selectedAsset.id)&&r.status==='FINALIZED'&&!Boolean(Number(r.returned_to_school))))||selectedAsset.latest_accountability_number);
            const canWrite=this.state.user?.role!=='VIEWER';
            const staffForProperty=String(this.state.staffData?.organization?.id||'')===String(this.state.organization_id)
                ?(this.state.staffData?.employees||[]).filter(e=>e.active && String(e.full_name||'').trim()):[];
            const custodianQuery=this.state.propertyCustodianSearch.trim().toLocaleLowerCase();
            const matchingCustodians=staffForProperty.filter(e=>!custodianQuery||
                [e.full_name,e.designation,e.position_title].some(v=>String(v||'').toLocaleLowerCase().includes(custodianQuery)));
            const chosenCustodian=staffForProperty.find(e=>String(e.employee_id)===String(this.state.propertyCustodianEmployeeId));
            const conditionChoices=['Serviceable','Needs Maintenance','For Inspection','For Repair','Under Repair'];
            const available=(records.received_property||[]).filter(r=>Number.isInteger(Number(r.accepted_qty))&&Number(r.accepted_qty)>Number(r.registered_units));
            // Issuance candidates only: never offer an already accountable or otherwise
            // ineligible asset in the new ICS/PAR picker. The backend remains authoritative.
            const finalizedAssetIds=new Set(accountability.filter(r=>r.status==='FINALIZED'&&!Boolean(Number(r.returned_to_school))).map(r=>String(r.asset_id)));
            const issuableProperties=assets.filter(a=>a.status==='ACTIVE'&&a.review_state!=='PENDING_REVIEW'&&
                Boolean(a.required_document)&&!String(a.custodian||'').trim()&&!a.latest_accountability_number&&
                (!Number(a.ever_returned_to_school)||a.condition_note==='Serviceable')&&
                !finalizedAssetIds.has(String(a.id)));
            const eligible=issuableProperties.filter(r=>[r.property_tag,r.item_name].some(x=>
                String(x||'').toLocaleLowerCase().includes(this.state.propertySearch.trim().toLocaleLowerCase())));
            const tabs=schoolScopedDivision(this.state)?[...(divisionLiveSpecial(this.state,'ASSET')?[['special_assets','Asset Register (View)']]:[]),['issue','Issue ICS / PAR'],['slips','Issued Slips']]:[['assets','Asset Register'],['edit','Review Newly Received'],['register','Earlier IAR Receipts'],['historical','Historical Property'],['issue','Issue ICS / PAR'],['slips','Issued Slips']];
            // The transfer desk remains a separately authenticated, school-scoped service,
            // but is presented within the regular Property Accountability tabs.
            const transferWorkspace=(this.state.orgs||[]).find(o=>String(o.id)===String(this.state.organization_id));
            const transferWorkspaceKind=transferWorkspace?.kind;
            // Match the transfer desk to the selected organization's kind,
            // including a Super Admin who has switched to a Division Office.
            if(['SCHOOL','SUPER_ADMIN','DIVISION'].includes(this.state.user?.role)
                && (this.state.user?.role==='SUPER_ADMIN'||this.state.user?.role===transferWorkspaceKind))
                tabs.push(['transfers',transferWorkspaceKind==='DIVISION'?'Incoming Transfers':'Property Transfers']);
            if(['SCHOOL','SUPER_ADMIN'].includes(this.state.user?.role))tabs.push(['returns','Return Property']);
            if(transferWorkspaceKind==='SCHOOL'&&['SCHOOL','SUPER_ADMIN'].includes(this.state.user?.role))tabs.push(['incoming','Incoming Transfers']);
            if(transferWorkspaceKind==='DIVISION'&&['DIVISION','SUPER_ADMIN'].includes(this.state.user?.role))tabs.push(['received_assets','Received Assets']);
            if(['SCHOOL','SUPER_ADMIN','DIVISION'].includes(this.state.user?.role))tabs.push(['lifecycle','Condition & Disposal']);
            // v0.2.240: discoverable, compact report tab; server continues to enforce authorization.
            if(canWrite)tabs.splice(1,0,['reports','Reports']);
            // Keep the original section identifiers and role-specific visibility, but
            // present them in short workflow groups instead of one overflowing strip.
            const propertyGroupDefs=[
                {id:'records',label:'Register & Review',description:'Assets, verification and history',sections:['assets','special_assets','received_assets','edit','register','historical']},
                {id:'custody',label:'Custody & Returns',description:'ICS/PAR, transfers and returns',sections:['issue','slips','transfers','incoming','returns']},
                {id:'conditions',label:'Condition & Disposal',description:'Condition and disposal approvals',sections:['lifecycle']},
                {id:'reports',label:'Reports',description:'Property reports and COA annexes',sections:['reports']}
            ];
            const propertyGroups=propertyGroupDefs.map(group=>({...group,
                tabs:tabs.filter(([id])=>group.sections.includes(id))})).filter(group=>group.tabs.length);
            const section=tabs.some(([id])=>id===this.state.propertySection)?this.state.propertySection:(schoolScopedDivision(this.state)?'issue':'assets');
            const groupForSection=id=>propertyGroups.find(group=>group.tabs.some(([tabId])=>tabId===id));
            const activePropertyGroup=groupForSection(section)||propertyGroups[0];
            const changeTab=id=>{
                const targetGroup=groupForSection(id);
                if(!targetGroup)return;
                this.clearPropertyNotice();
                if(this.state.propertyDetailId)this.closePropertyDetails();
                this.setState(prev=>({propertySection:id,propertyError:'',propertyReportModal:null,
                    propertyLastSection:{...(prev.propertyLastSection||{}),[targetGroup.id]:id}}),()=>{
                    if(id==='special_assets')this.loadDivisionContextRegister('ASSET');
                    if(id==='assets')this.loadPropertyRegister(true);
                    if(id==='issue'||id==='slips')this.loadAccountableProperty();
                });
            };
            const changePropertyGroup=id=>{
                const targetGroup=propertyGroups.find(group=>group.id===id);
                if(!targetGroup||targetGroup.id===activePropertyGroup?.id)return;
                const previous=this.state.propertyLastSection?.[id];
                const firstAvailable=targetGroup.tabs.some(([tabId])=>tabId===previous)?previous:targetGroup.tabs[0][0];
                changeTab(firstAvailable);
            };
            const renderPropertyDetails=()=>{
                const d=this.state.propertyDetail;
                if(!this.state.propertyDetailId||this.state.propertyDetailOrg!==String(this.state.organization_id))return null;
                const record=d&&d.details||{},shared=record.shared||{},unit=record.unit||{};
                const tab=this.state.propertyDetailTab||'overview';
                const val=x=>x===null||x===undefined||x===''?'—':String(x);
                const field=(label,v)=>h('div',{className:'asset-modal-field',key:label},
                    h('span',{className:'asset-modal-field-label'},label),h('strong',null,val(v)));
                const fields=items=>h('div',{className:'asset-modal-fields'},...items.map(item=>field(item[0],item[1])));
                const sectionTitle=(name,note)=>h('div',{className:'asset-modal-section-title'},h('h3',null,name),note?h('p',null,note):null);
                const empty=(title,desc)=>h('div',{className:'asset-modal-empty'},h('strong',null,title),h('span',null,desc));
                const auditChanges=(entry)=>{
                    const before=entry.details?.before||{},after=entry.details?.after||{};
                    const paths=[
                        ['Location','location'],['Serial number','serial_number'],['Condition','condition_note'],
                        ['Brand','profile.shared.brand'],['Model','profile.shared.model'],['Acquisition type','profile.shared.acquisition_type'],
                        ['Useful life','profile.shared.useful_life_years'],['Evidence note','profile.shared.evidence_note'],
                        ['Historical PO','profile.shared.historical_po'],['Historical IAR','profile.shared.historical_iar'],
                        ['Historical DV','profile.shared.historical_dv'],['Legacy property number','profile.unit.legacy_property_number'],
                        ['Reported custodian','profile.unit.reported_custodian']
                    ];
                    const dig=(object,path)=>path.split('.').reduce((node,k)=>node&&node[k],object);
                    const differences=paths.filter(([label,path])=>String(dig(before,path)??'')!==String(dig(after,path)??''));
                    return differences.length?h('div',{className:'asset-modal-audit-diff'},...differences.map(([label,path])=>
                        h('div',{className:'asset-modal-audit-row',key:path},
                            h('strong',null,label),h('span',null,val(dig(before,path))),
                            h('span',{className:'asset-modal-audit-arrow','aria-hidden':'true'},'→'),
                            h('span',{className:'asset-modal-audit-new'},val(dig(after,path)))))):
                        entry.details?.before||entry.details?.after?
                        h('p',{className:'asset-modal-muted'},'Correction recorded. Full values are retained in the audit log.'):
                        h('p',{className:'asset-modal-muted'},'Original system event recorded.');
                };
                const tabs=[['overview','Overview'],['acquisition','Acquisition'],['batch','Registration batch'],['history','Custody & audit'],...(!schoolScopedDivision(this.state)&&['SCHOOL','SUPER_ADMIN'].includes(this.state.user?.role)?[['edit','Edit property']]:[])];
                const onDialogKey=e=>{
                    if(e.key==='Escape'&&!this.state.propertyDetailBusy){e.preventDefault();this.closePropertyDetails();return;}
                    if(e.key!=='Tab')return;
                    const controls=Array.from(e.currentTarget.querySelectorAll('button:not([disabled]),[href],input:not([disabled]),select:not([disabled]),[tabindex="0"]'));
                    if(!controls.length)return;
                    const first=controls[0],last=controls[controls.length-1];
                    if(e.shiftKey && document.activeElement===first){e.preventDefault();last.focus();}
                    else if(!e.shiftKey && document.activeElement===last){e.preventDefault();first.focus();}
                };
                const dialog=h('div',{className:'ipdams-asset-dialog-backdrop',onMouseDown:e=>{if(e.target===e.currentTarget)this.closePropertyDetails();}},
                    h('section',{className:'ipdams-asset-dialog',role:'dialog','aria-modal':'true','aria-labelledby':'asset-detail-title',onKeyDown:onDialogKey},
                        h('header',{className:'asset-modal-header'},
                            h('div',null,h('span',{className:'asset-modal-eyebrow'},tab==='edit'?'Property accountability · Audited corrections':'Property accountability · Verified details'),
                                h('h2',{id:'asset-detail-title'},'Property details')),
                            h('button',{type:'button',className:'outline ipdams-asset-dialog-close',onClick:this.closePropertyDetails,'aria-label':'Close property details'},'✕ Close')),
                        d?h('div',{className:'asset-modal-summary'},
                            h('div',{className:'asset-modal-summary-identity'},
                                h('span',{className:'asset-modal-tag'},d.property_number),
                                h('strong',null,d.item_name),
                                h('span',{className:'asset-modal-muted'},val(record.classification_name||record.category)+' · '+(d.source==='HISTORICAL_OPENING'?'Historical property':'Registered from accepted IAR'))),
                            h('div',{className:'asset-modal-summary-facts'},
                                h('span',null,'Status',h('strong',{className:'asset-modal-status'},val(record.status))),
                                h('span',null,'Location',h('strong',null,val(record.location))),
                                h('span',null,'Custodian',h('strong',null,val(record.custodian||'Unassigned'))))):
                            h('div',{className:'asset-modal-summary'},h('span',null,'Loading property details…')),
                        h('nav',{className:'asset-modal-tabs','aria-label':'Property details sections'},
                            ...tabs.map(([name,label])=>h('button',{type:'button',key:name,
                                className:'asset-modal-tab'+(tab===name?' is-active':''),
                                'aria-pressed':tab===name,
                                onClick:()=>this.setState({propertyDetailTab:name})},label))),
                        h('div',{className:'asset-modal-content'},
                            this.state.propertyDetailError?h('p',{className:'error',role:'alert'},this.state.propertyDetailError):null,
                            this.state.propertyDetailLoading?h('p',{role:'status'},'Loading verified school property details…'):null,
                            d&&tab==='overview'?h('div',null,
                                sectionTitle('Identity & condition','The latest saved values for this individual physical unit.'),
                                fields([['Property number',d.property_number],['Item',d.item_name],['Accounting category',record.category],['Division classification',record.classification_name],['Serial number',record.serial_number],['Current location',record.location],['Condition',record.condition_note],['Old property number',unit.legacy_property_number],['Status',record.status],['Current custodian',record.custodian]]),
                                h('div',{className:'asset-modal-help'},'Property number is permanent. Open the Custody & audit section to verify saved corrections and prior values.')):null,
                            d&&tab==='acquisition'?h('div',null,
                                sectionTitle('Acquisition details','Historical references are evidence only; they do not create official procurement or financial transactions.'),
                                fields([['Registration origin',d.source==='HISTORICAL_OPENING'?'Historical opening':'Verified accepted IAR'],['Date acquired',record.acquired_on],['Acquisition cost',record.acquisition_cost],['Brand',shared.brand],['Model',shared.model],['Acquisition type',shared.acquisition_type],['Useful life (years)',shared.useful_life_years],['Evidence note',shared.evidence_note],['Historical PO (text only)',shared.historical_po],['Historical IAR (text only)',shared.historical_iar],['Historical DV (text only)',shared.historical_dv],['Verified IAR number',d.iar_reference?.display_number]])):null,
                            d&&tab==='batch'?h('div',null,
                                sectionTitle('Registration batch','Select another property to view its own serial, location and history without closing this window.'),
                                fields([['Original batch reference',d.batch_key],['Physical units in batch',(d.batch_details||[]).length]]),
                                h('div',{className:'asset-modal-batch-list'},
                                    ...(d.batch_details||[]).map(x=>h('div',{className:'asset-modal-batch-item'+(Number(x.id)===Number(d.asset_id)?' is-current':''),key:x.id},
                                        h('div',null,h('strong',null,x.property_tag),h('span',null,val(x.serial_number)+' · '+val(x.location)+' · '+val(x.status))),
                                        h('button',{type:'button',className:'outline',disabled:Number(x.id)===Number(d.asset_id),onClick:()=>this.openPropertyDetails(x.id,true)},
                                            Number(x.id)===Number(d.asset_id)?'Current':'View unit'))),
                                    !(d.batch_details||[]).length?empty('No linked batch','This asset may have been registered individually.'):null)):null,
                            d&&tab==='edit'?h('form',{onSubmit:this.savePropertyDetailProfile,className:'property-profile-edit'},
                                sectionTitle('Edit property information','All property details are shown here. Source values and custody are locked; descriptive corrections are audited.'),
                                h('div',{className:'asset-modal-fields'},
                                    ...[['Property number',d.property_number],['Catalog item / description',d.item_name],['Accounting classification',record.classification_name],
                                    ['Acquisition cost',record.acquisition_cost],['Date acquired',record.acquired_on],['Verified IAR',d.iar_reference?.display_number],
                                    ['Current custodian',record.custodian],['Current asset status',record.status]].map(([label,value])=>
                                        h('div',{className:'asset-modal-field',key:label},h('span',{className:'asset-modal-field-label'},label+' · Locked'),h('strong',null,val(value))))),
                                d.issued?h('p',{className:'hint'},'Issued ICS/PAR: useful life, acquisition type and official source references are locked. The prior printed ICS retains its prescribed item, cost and useful life. Custodian changes require a new accountable document or Property Transfer.'):null,
                                h('label',{className:'field'},h('span',null,'Correction scope'),h('select',{value:this.state.propertyDetailEditMode,onChange:e=>this.setState({propertyDetailEditMode:e.target.value,propertyDetailError:''}),disabled:this.state.propertyDetailBusy},
                                    h('option',{value:'UNIT'},'This physical property only'),d.batch_key&&d.batch_units?.length>1?h('option',{value:'BATCH'},'Shared details for all '+d.batch_units.length+' batch units'):null)),
                                h('div',{className:'form-grid'},
                                    ...[['brand','Brand',120],['model','Model',120],['evidence_note','Acquisition evidence / supporting remarks',500]].map(([key,label,maxLength])=>
                                        h('label',{className:'field',key},h('span',null,label),h('input',{maxLength,value:this.state.propertyDetailEditShared[key]??'',disabled:this.state.propertyDetailBusy,
                                            onChange:e=>{const value=e.target.value;this.setState(p=>({propertyDetailEditShared:{...p.propertyDetailEditShared,[key]:value}}));}}))),
                                    h('label',{className:'field'},h('span',null,'Acquisition type'+(d.issued?' · Locked':'')),h('select',{
                                        value:this.state.propertyDetailEditShared.acquisition_type||'UNKNOWN',disabled:d.issued||this.state.propertyDetailBusy,
                                        onChange:e=>{const value=e.target.value;this.setState(p=>({propertyDetailEditShared:{...p.propertyDetailEditShared,acquisition_type:value}}));}},
                                        ...['PURCHASE','DONATION','TRANSFER','OTHER','UNKNOWN'].map(x=>h('option',{value:x,key:x},x)))),
                                    h('label',{className:'field'},h('span',null,'Estimated useful life (years)'+(d.issued?' · Locked':'')),h('input',{type:'number',min:1,max:99,
                                        value:this.state.propertyDetailEditShared.useful_life_years??'',disabled:d.issued||this.state.propertyDetailBusy,
                                        onChange:e=>{const value=e.target.value;this.setState(p=>({propertyDetailEditShared:{...p.propertyDetailEditShared,useful_life_years:value}}));}})),
                                    ...(!d.issued&&d.source==='HISTORICAL_OPENING'?[['historical_po','Historical PO reference'],['historical_iar','Historical IAR reference'],['historical_dv','Historical DV reference']].map(([key,label])=>
                                        h('label',{className:'field',key},h('span',null,label+' · Evidence only'),h('input',{maxLength:100,value:this.state.propertyDetailEditShared[key]??'',disabled:this.state.propertyDetailBusy,
                                            onChange:e=>{const value=e.target.value;this.setState(p=>({propertyDetailEditShared:{...p.propertyDetailEditShared,[key]:value}}));}}))):[])),
                                this.state.propertyDetailEditMode==='UNIT'?h('div',null,
                                    sectionTitle('Individual unit','These fields apply only to this physical unit, never to the whole batch.'),
                                    h('div',{className:'form-grid'},
                                        ...[['serial_number','Serial number',120],['location','Physical location / room',180],['condition_note','Current condition',255],
                                            ['legacy_property_number','Previous property number',100]].map(([key,label,maxLength])=>
                                            h('label',{className:'field',key},h('span',null,label),
                                                key==='condition_note'?h('select',{
                                                    'aria-label':'Current property condition',value:this.state.propertyDetailEditUnit.condition_note??'',
                                                    disabled:this.state.propertyDetailBusy,
                                                    onChange:e=>{const value=e.target.value;this.setState(p=>({propertyDetailEditUnit:{...p.propertyDetailEditUnit,condition_note:value}}));}},
                                                        h('option',{value:''},'Not yet recorded — select a condition'),
                                                        ...conditionChoices.map(value=>h('option',{key:value,value},value)),
                                                        ...(this.state.propertyDetailEditUnit.condition_note && !conditionChoices.includes(this.state.propertyDetailEditUnit.condition_note)
                                                            ?[h('option',{key:'prior-condition',value:this.state.propertyDetailEditUnit.condition_note},
                                                                'Existing observation (unchanged): '+this.state.propertyDetailEditUnit.condition_note)]:[])):
                                                h('input',{maxLength,value:this.state.propertyDetailEditUnit[key]??'',disabled:this.state.propertyDetailBusy,
                                                    onChange:e=>{const value=e.target.value;this.setState(p=>({propertyDetailEditUnit:{...p.propertyDetailEditUnit,[key]:value}}));}})))),
                                    h('p',{className:'hint'},'To declare an item Unserviceable, use the separate Condition & Disposal tab. The condition choices here describe inspection or repair needs; they do not grant approval or change formal disposal status.')):
                                    h('p',{className:'hint'},'Batch edit changes shared metadata only. Existing per-unit serial numbers, locations and custodian records remain untouched.'),
                                h('p',{className:'hint'},'Accountable employees are assigned using ICS/PAR or Property Transfers, never by editing property details. For formal unserviceable declarations, use Condition & Disposal.'),
                                h('label',{className:'field'},h('span',null,'Reason for correction (required, 12+ characters)'),h('textarea',{required:true,minLength:12,maxLength:500,rows:3,
                                    value:this.state.propertyDetailEditReason,disabled:this.state.propertyDetailBusy,
                                    onChange:e=>this.setState({propertyDetailEditReason:e.target.value})})),
                                h('button',{type:'submit',className:'primary',disabled:this.state.propertyDetailBusy},this.state.propertyDetailBusy?'Saving changes…':'Save audited property changes')):null,
                            d&&tab==='history'?h('div',null,
                                sectionTitle('Custody & audit','Read-only evidence of issuance, movement and changes to this property.'),
                                h('h4',null,'ICS / PAR accountability'),
                                !(d.accountability||[]).length?empty('Not yet issued','No finalized or recorded accountability document for this unit.'):
                                    h('div',{className:'asset-modal-event-list'},...(d.accountability||[]).map((x,i)=>
                                        h('div',{className:'asset-modal-event',key:i},h('strong',null,x.doc_type+' '+x.display_number),h('p',null,x.status+' · '+val(x.custodian)+' · '+val(x.created_at))))),
                                h('h4',null,'Property movements'),
                                !(d.movements||[]).length?empty('No movements recorded','No transfers, returns, repairs or other custody movements yet.'):
                                    h('div',{className:'asset-modal-event-list'},...(d.movements||[]).map((x,i)=>
                                        h('div',{className:'asset-modal-event',key:i},h('strong',null,x.event_type+' · '+val(x.created_at)),
                                            h('p',null,val(x.from_custodian)+' → '+val(x.to_custodian)),x.reason?h('p',null,x.reason):null))),
                                h('h4',null,'Audited history · newest first'),
                                !(d.history||[]).length?empty('No audit entries','Corrections and initial registration events will appear here.'):
                                    h('div',{className:'asset-modal-event-list'},...(d.history||[]).map((x,i)=>h('details',{className:'asset-modal-audit-event',key:i},
                                        h('summary',null,h('strong',null,x.event_type==='PROPERTY_DETAILS_CORRECTED'?'Property details corrected':x.event_type==='HISTORICAL_PROPERTY_REGISTERED'?'Historical property registered':x.event_type),
                                            h('span',null,val(x.created_at)+' · '+val(x.actor))),
                                        h('div',{className:'asset-modal-event-body'},
                                            x.details?.reason?h('p',null,'Reason: '+x.details.reason):null,
                                            auditChanges(x)))))):null),
                        h('footer',{className:'asset-modal-footer'},
                            h('span',null,tab==='edit'?'Edits are saved with a correction reason and audit trail.':'Finalized source and custody records remain protected.'),
                            h('button',{type:'button',className:'outline',onClick:this.closePropertyDetails},'Close window'))));
                return ReactDOM.createPortal(dialog,document.body);
            };
            const renderAssetRegister=()=>{
                const r=this.state.propertyRegister||{rows:[],facets:{}};
                const facets=r.facets||{},filters=this.state.propertyRegisterFilters||{search:'',category:'ALL',classification:'ALL',item_id:'ALL',custodian:'ALL',status:'ALL',source:'ALL'};
                const select=(key,label,opts)=>h('label',{className:'field asset-filter'},h('span',null,label),
                    h('select',{value:filters[key],onChange:e=>this.propertyFilter(key,e.target.value)},
                        ...opts.map(([val,title])=>h('option',{key:val,value:val},title))));
                return h('div',{className:'asset-register-workspace'},
                    h('div',{className:'asset-filter-grid'},
                        h('label',{className:'field asset-filter'},h('span',null,'Search tag, item, serial, room or custodian'),
                            h('input',{type:'search',value:filters.search,onChange:e=>{const value=e.target.value;this.setState(prev=>({propertyRegisterFilters:Object.assign({},prev.propertyRegisterFilters,{search:value})}));},
                                onKeyDown:e=>{if(e.key==='Enter'){e.preventDefault();this.loadPropertyRegister(true);}},placeholder:'Search and press Enter'})),
                        select('category','Asset type',[['ALL','All PPE and semi-expendable'],['PPE','PPE'],['SEMI_EXPENDABLE','Semi-expendable']]),
                        select('classification','Division classification',[['ALL','All classifications'],...(facets.classifications||[]).map(x=>[x.code,x.name])]),
                        select('item_id','Catalog item',[['ALL','All items'],...(facets.items||[]).map(x=>[String(x.id),x.name])]),
                        select('custodian','Accountable user / custodian',[['ALL','All custodians'],['UNASSIGNED','Not yet assigned'],...(facets.custodians||[]).map(x=>[x,x])]),
                        select('status','Status',[['ALL','All statuses'],...(facets.statuses||[]).map(x=>[x,x])]),
                        select('source','Registration origin',[['ALL','All origins'],['HISTORICAL_OPENING','Historical'],['VERIFIED_IAR','Accepted IAR'],['TRANSFER_IN','Received transfer']]),
                        h('div',{className:'asset-filter-actions'},
                            h('button',{type:'button',className:'outline',onClick:()=>this.loadPropertyRegister(true)},'Search / Refresh'),
                            h('button',{type:'button',className:'outline',onClick:()=>this.setState({propertyRegisterFilters:{search:'',category:'ALL',classification:'ALL',item_id:'ALL',custodian:'ALL',status:'ALL',source:'ALL'}},()=>this.loadPropertyRegister(true))},'Clear filters'))),
                    this.state.propertyRegisterError?h('p',{className:'error',role:'alert'},this.state.propertyRegisterError):null,
                    this.state.propertyRegisterLoading?h('p',{className:'hint',role:'status'},'Loading property register…'):null,
                    h('p',{className:'hint'},'Showing '+(r.rows||[]).length+' school property record(s)'+(r.has_more?' · more results available':'')+'. Select View / Edit to review the full property profile, correct descriptions and see its audit history.'),
                    h('div',{className:'table-scroll'},h('table',{className:'master-table'},
                        h('thead',null,h('tr',null,...['Property number','Item','Classification','Custodian','Location','Status','Details'].map(x=>h('th',{key:x},x)))),
                        h('tbody',null,...(r.rows||[]).map(x=>h('tr',{key:x.id},
                            h('td',null,x.property_tag),h('td',null,x.item_name),h('td',null,x.classification_name||x.category),
                            h('td',null,x.custodian||'Unassigned'),h('td',null,x.location||'—'),h('td',null,x.review_state==='PENDING_REVIEW'?'Review required':
                                (x.lifecycle_state&&x.lifecycle_state!=='SERVICEABLE'?x.lifecycle_state.replace(/_/g,' ')+' · '+x.status:x.status)),
                            h('td',null,x.review_state==='PENDING_REVIEW'?h('button',{type:'button',className:'primary',onClick:()=>{changeTab('edit');this.loadPropertyEditPreview(String(x.id));}},'Review'):h('div',{className:'inline-actions'},h('button',{type:'button',className:'outline',onClick:()=>this.openPropertyDetails(x.id)},x.source==='TRANSFER_IN'?'View details':'View / Edit'),x.source==='TRANSFER_IN'?h('a',{className:'print-link',target:'_blank',rel:'noopener noreferrer',href:'api/property-transfer-intake.php?organization_id='+encodeURIComponent(this.state.organization_id)+'&assess_asset_id='+encodeURIComponent(x.id)},'Assess condition'):null,x.source==='TRANSFER_IN'?h('a',{className:'print-link',target:'_blank',rel:'noopener noreferrer',href:'api/property-transfer-intake.php?organization_id='+encodeURIComponent(this.state.organization_id)+'&history_asset_id='+encodeURIComponent(x.id)},'Full history'):null)))),
                            !(r.rows||[]).length?h('tr',null,h('td',{colSpan:7},this.state.propertyRegisterLoading?'Loading…':'No matching property records.')):null))),
                    r.has_more?h('button',{type:'button',className:'outline',disabled:this.state.propertyRegisterLoading,onClick:()=>this.loadPropertyRegister(false)},'Load next 25'):null,
                    (()=>{try{return renderPropertyDetails();}catch(error){
                        console.error('Property details dialog render failed',error);
                        return h('div',{className:'ipdams-asset-dialog-backdrop',role:'alert'},
                            h('section',{className:'ipdams-asset-dialog',style:{padding:'24px',minHeight:'180px'}},
                                h('h2',null,'Property editor could not display'),
                                h('p',null,'This property has not been changed. Close the editor and reopen the property, or use Assess condition for a transferred-in asset.'),
                                h('button',{type:'button',className:'outline',onClick:this.closePropertyDetails},'Close editor')));
                    }})());
            };

            const reportAnnexes=[
                ['A1','Semi-Expendable Property Card'],
                ['A4','Registry of Property Issued'],
                ['A5','Inventory Transfer Report'],
                ['A6','Receipt of Returned Property'],
                ['A7','Report of Property Issued'],
                ['A8','Physical Count of Property'],
                ['A9','Lost / Stolen / Damaged Property'],
                ['A10','Inspection of Unserviceable Property']
            ];
            const validReportPeriod=()=>validPrDate(this.state.propertyReportFrom)&&validPrDate(this.state.propertyReportThrough)
                && this.state.propertyReportFrom<=this.state.propertyReportThrough;
            const reportModal=()=>{
                const annex=this.state.propertyReportModal;
                if(!annex||!canWrite)return null;
                const close=()=>this.setState({propertyReportModal:null},()=>{
                    if(typeof document!=='undefined')document.getElementById('property-annex-'+annex)?.focus();
                });
                const annexTitle=reportAnnexes.find(([code])=>code===annex)?.[1]||'Property report';
                const reportId='ipdams-semi-expendable-report-frame';
                return h('div',{className:'ipdams-report-modal-backdrop',role:'presentation',
                    style:{position:'fixed',inset:0,zIndex:2100,display:'flex',alignItems:'center',justifyContent:'center',padding:'min(2vw,18px)',background:'rgba(12,31,51,.70)'},
                    onMouseDown:event=>{if(event.target===event.currentTarget)close();}},
                    h('section',{className:'ipdams-report-modal',role:'dialog','aria-modal':'true','aria-labelledby':'ipdams-report-modal-title',
                        onKeyDown:event=>{if(event.key==='Escape'){event.preventDefault();close();}},
                        style:{width:'min(1500px,98vw)',maxWidth:'98vw',height:'min(94vh,980px)',maxHeight:'94vh',minHeight:0,background:'#f4f6f9',borderRadius:'12px',boxShadow:'0 22px 65px rgba(0,0,0,.4)',display:'flex',flexDirection:'column',overflow:'hidden'}},
                        h('header',{style:{display:'flex',alignItems:'center',justifyContent:'space-between',gap:'12px',padding:'10px 16px',background:'#173d67',color:'#fff',flex:'0 0 auto'}},
                            h('div',{style:{display:'grid',gap:'2px'}},
                                h('strong',{id:'ipdams-report-modal-title'},'Annex '+annex.replace('A','A.')+' · '+annexTitle),
                                h('small',{style:{fontSize:'11px',opacity:0.9}},this.state.propertyReportFrom+' through '+this.state.propertyReportThrough)),
                            h('div',{style:{display:'flex',gap:'8px',alignItems:'center'}},
                                h('button',{type:'button',className:'outline',style:{background:'#fff',color:'#173d67'},
                                    onClick:()=>{const frame=document.getElementById(reportId);try{frame?.contentWindow?.print();}catch(_){this.setState({propertyError:'Printing unavailable. Refresh report and retry.'});}}},'Print'),
                                h('button',{type:'button',className:'outline',autoFocus:true,style:{background:'#fff',color:'#173d67'},
                                    'aria-label':'Close Property Reports window',onClick:close},'Close ✕'))),
                        h('iframe',{id:reportId,title:'IPDAMS Semi-Expendable Property Reports',
                            src:'api/semi_expendable_reports.php?embedded=1&preview=1&organization_id='+encodeURIComponent(this.state.organization_id)+'&from='+encodeURIComponent(this.state.propertyReportFrom)+'&through='+encodeURIComponent(this.state.propertyReportThrough)+'&annex='+encodeURIComponent(annex),
                            onLoad:event=>{try{const doc=event.currentTarget.contentDocument;
                                doc?.addEventListener('keydown',e=>{if(e.key==='Escape'){e.preventDefault();close();}});
                            }catch(_){/* Parent cannot read a cross-origin page; iframe access is same-origin only. */}},
                            style:{width:'100%',flex:'1 1 auto',minHeight:0,display:'block',border:'none',background:'#f4f6f9'}})));
            };
            const panel={
                special_assets:()=>this.divisionContextPanel('ASSET'),
                assets:()=>h('div',{className:'inventory-tab-content'},
                    available.length?h('p',{className:'inventory-context'},available.length+' earlier or large IAR property line(s) still require batch tagging. Consumables are separate.'):null,
                    available.length?this.table('Accepted property awaiting registration',['iar_number','item_name','category','accepted_qty','registered_units'],available):null,
                    renderAssetRegister()),
                register:()=>canWrite?h('section',{className:'surface'},
                    h('h3',null,'Register earlier or large accepted IAR batches'),
                    h('p',{className:'body-muted'},'Use only for IAR receipts created before automatic registration or those exceeding the 100-unit shared-hosting safety limit. New eligible IARs are registered automatically.'),
                    h('form',{onSubmit:this.registerAccountableAssets},
                        h('label',{className:'field'},h('span',null,'Accepted IAR property item'),
                            h('select',{required:true,value:this.state.propertyReceivedId,onChange:e=>this.setState({propertyReceivedId:e.target.value})},
                                h('option',{value:''},'Choose an accepted IAR line'),
                                ...available.map(r=>h('option',{key:r.iar_line_id,value:String(r.iar_line_id)},r.iar_number+' · '+r.item_name+' · '+(Number(r.accepted_qty)-Number(r.registered_units))+' remaining')))),
                        selectedLine?h('p',{className:'hint'},'Accepted '+selectedLine.accepted_qty+' · already registered '+selectedLine.registered_units):null,
                        h('p',{className:'inventory-context'},'Property registration numbers are generated automatically as School ID-YYMM-Lifetime sequence (example: 306351-2610-00001). The final digits never restart in a new month or year.'),
                        h('label',{className:'field'},h('span',null,'Number of individual units to register'),
                            h('input',{required:true,type:'number',min:1,max:selectedLine?Math.min(100,Math.max(0,Number(selectedLine.accepted_qty)-Number(selectedLine.registered_units))):100,step:1,value:this.state.propertyUnitCount,
                                onChange:e=>this.setState({propertyUnitCount:e.target.value})})),
                        h('h4',null,'Shared descriptive details (optional)'),
                        this.propertySharedFields(false),
                        h('h4',null,'Individual serials, locations and legacy identifiers'),
                        this.propertyUnitGrid(Number(this.state.propertyUnitCount)),
                        h('button',{type:'submit',className:'primary',disabled:this.state.propertyBusy||!selectedLine||Number(this.state.propertyUnitCount)<1||Number(this.state.propertyUnitCount)>100},'Register property & generate numbers'))):
                    h('p',{className:'inventory-context'},'Read-only access: an authorized property custodian is required to register assets.'),
                historical:()=>canWrite?h('section',{className:'surface'},
                    h('h3',null,'Register historical property · acquired before IPDAMS'),
                    h('p',{className:'hint'},'One shared acquisition batch, one permanent generated number per physical unit. Optional PO/IAR/DV numbers are historical references only and will not create official documents or ledger movements.'),
                    h('form',{onSubmit:this.registerHistoricalProperty},
                        h('div',{className:'form-grid'},
                            h('label',{className:'field'},h('span',null,'Existing PPE / semi-expendable catalog item'),
                                h('select',{required:true,value:this.state.propertyBatchItem,onChange:e=>this.setState({propertyBatchItem:e.target.value})},
                                    h('option',{value:''},'Select item'),
                                    ...(this.state.lists.items||[]).filter(i=>['PPE','SEMI_EXPENDABLE'].includes(i.category)&&Number(i.active??1)===1).map(i=>h('option',{key:i.id,value:String(i.id)},i.name)))),
                            h('label',{className:'field'},h('span',null,'Number of separate physical units (1–100)'),
                                h('input',{type:'number',min:1,max:100,step:1,required:true,value:this.state.propertyBatchCount,onChange:e=>this.setState({propertyBatchCount:e.target.value})})),
                            h('label',{className:'field'},h('span',null,'Date acquired (historical date)'),
                                h('input',{type:'date',value:this.state.propertyBatchDate,onChange:e=>this.setState({propertyBatchDate:e.target.value})})),
                            h('label',{className:'field'},h('span',null,'Unit acquisition cost (optional)'),
                                h('input',{value:this.state.propertyBatchCost,onChange:e=>this.setState({propertyBatchCost:e.target.value}),placeholder:'Based on supporting source evidence'}))),
                        h('h4',null,'Details common to this exact batch'),this.propertySharedFields(true),
                        h('h4',null,'Individual properties (different serials and locations)'),this.propertyUnitGrid(Number(this.state.propertyBatchCount)),
                        h('button',{type:'submit',className:'primary',disabled:this.state.propertyBusy},'Register historical property'))):null,
                edit:()=>canWrite?h('section',{className:'surface'},
                    h('h3',null,'Review newly received property / edit details'),
                    h('p',{className:'hint'},'New IAR property receives permanent tags automatically. Verify each unit’s serial number, location and condition before issuing ICS/PAR. Custody is assigned only through ICS/PAR or documented Property Transfers; property review never assigns an employee.'),
                    h('label',{className:'field'},h('span',null,'Registered asset'),
                        h('select',{value:this.state.propertyEditAssetId,onChange:e=>this.loadPropertyEditPreview(e.target.value)},
                            h('option',{value:''},'Select existing asset'),
                            ...assets.map(a=>h('option',{value:String(a.id),key:a.id},a.property_tag+' · '+a.item_name)))),
                    this.state.propertyEditPreview?h('form',{onSubmit:this.savePropertyEdit},
                        h('p',null,'Property number (immutable): '+this.state.propertyEditPreview.property_number),
                        this.state.propertyEditPreview.issued?h('p',{className:'inventory-context'},'Issued ICS/PAR record: corrections require a separately approved workflow.'):null,
                        h('label',{className:'field'},h('span',null,'Correction scope'),
                            h('select',{value:this.state.propertyEditMode,onChange:e=>this.setState({propertyEditMode:e.target.value,propertyConfirmRegistration:false})},
                                h('option',{value:'UNIT'},'This physical unit only'),
                                this.state.propertyEditPreview.batch_key?h('option',{value:'BATCH'},'All '+(this.state.propertyEditPreview.batch_units||[]).length+' units from this exact registration batch'):null)),
                        this.state.propertyEditMode==='BATCH'?h('div',{className:'property-edit-scoped'},
                            h('p',{className:'hint'},'Batch correction affects only these property numbers: '+(this.state.propertyEditPreview.batch_units||[]).map(a=>a.property_tag).join(', ')),
                            ...['brand','model','acquisition_type','useful_life_years','historical_po','historical_iar','historical_dv','evidence_note'].filter(k=>this.state.propertyEditPreview.source==='HISTORICAL_OPENING'||!['historical_po','historical_iar','historical_dv'].includes(k)).map(k=>h('label',{className:'field',key:k},h('span',null,k.replace(/_/g,' ')),
                                h('input',{value:this.state.propertyEditShared[k]||'',onChange:e=>{
                                    const value=e.target.value;
                                    this.setState(prev=>({propertyEditShared:Object.assign({},prev.propertyEditShared,{[k]:value})}));
                                }})))):
                            this.state.propertyEditPreview.review_state==='PENDING_REVIEW' && this.state.propertyEditMode==='UNIT'?h('div',{className:'property-edit-scoped'},
                                h('h4',null,'Known brand and model (if applicable)'),
                                ...['brand','model'].map(k=>h('label',{className:'field',key:k},h('span',null,k==='brand'?'Brand':'Model'),
                                    h('input',{value:this.state.propertyEditShared[k]||'',onChange:e=>{const value=e.target.value;this.setState(prev=>({propertyEditShared:{...prev.propertyEditShared,[k]:value}}));}})))):null,
                            h('div',{className:'property-edit-scoped'},...['serial_number','location','condition_note','legacy_property_number'].map(k=>h('label',{className:'field',key:k},h('span',null,k==='condition_note'?'Property condition':k.replace(/_/g,' ')),
                                k==='condition_note'?h('select',{value:this.state.propertyEditUnit.condition_note||'',onChange:e=>{
                                    const value=e.target.value;
                                    this.setState(prev=>({propertyEditUnit:{...prev.propertyEditUnit,condition_note:value}}));
                                }},h('option',{value:''},'Select property condition'),
                                    ...conditionChoices.map(value=>h('option',{key:value,value},value)),
                                    ...(this.state.propertyEditUnit.condition_note && !conditionChoices.includes(this.state.propertyEditUnit.condition_note)
                                        ?[h('option',{key:'previous',value:this.state.propertyEditUnit.condition_note},'Previously recorded: '+this.state.propertyEditUnit.condition_note)]:[])):
                                h('input',{value:this.state.propertyEditUnit[k]||'',onChange:e=>{
                                    const value=e.target.value;
                                    this.setState(prev=>({propertyEditUnit:Object.assign({},prev.propertyEditUnit,{[k]:value})}));
                                }})))),
                        this.state.propertyEditPreview.review_state==='PENDING_REVIEW' && this.state.propertyEditMode==='UNIT'?h('label',{className:'check-label'},
                            h('input',{type:'checkbox',checked:Boolean(this.state.propertyConfirmRegistration),onChange:e=>this.setState({propertyConfirmRegistration:e.target.checked})}),
                            'I physically checked this unit and recorded its actual location and condition. Make it available for ICS/PAR issuance.'):null,
                        h('label',{className:'field'},
                            h('span',null,this.state.propertyConfirmRegistration
                                ?'Physical verification note (optional)'
                                :'Correction reason (12+ characters required)'),
                            h('textarea',{required:!this.state.propertyConfirmRegistration,
                                minLength:this.state.propertyConfirmRegistration?0:12,maxLength:500,
                                placeholder:this.state.propertyConfirmRegistration
                                    ?'Optional remarks; the checked verification and field changes are audited automatically.'
                                    :'Describe why the property details are being corrected.',
                                value:this.state.propertyEditReason,onChange:e=>this.setState({propertyEditReason:e.target.value})})),
                        h('button',{type:'submit',className:'primary',
                            disabled:this.state.propertyBusy||this.state.propertyEditPreview.issued||
                                (!this.state.propertyConfirmRegistration&&this.state.propertyEditReason.trim().length<12)},
                            'Save '+(this.state.propertyConfirmRegistration?'review & enable issuance':'audited details'))):null):null,
                issue:()=>canWrite?h('section',{className:'surface'},
                    h('h3',null,'Issue ICS / PAR'),
                    h('p',{className:'body-muted'},'The system determines the required document for the selected asset. Obtain official manual signatures.'),
                    h('form',{onSubmit:this.issueAccountability},
                        h('label',{className:'field ics-custodian-first'},h('span',null,'1. Accountable employee / custodian'),
                            h('input',{type:'search',role:'combobox','aria-autocomplete':'list',
                                'aria-expanded':Boolean(this.state.propertyCustodianSearch&&!chosenCustodian&&matchingCustodians.length),
                                value:this.state.propertyCustodianSearch,placeholder:'Start typing an employee name…',autoComplete:'off',
                                disabled:this.state.staffLoading||!staffForProperty.length,onKeyDown:e=>{
                                    if(e.key==='Enter'&&!chosenCustodian)e.preventDefault();
                                    if(e.key==='ArrowDown'&&matchingCustodians.length){
                                        e.preventDefault();const btn=e.currentTarget.parentElement?.nextSibling?.querySelector('button');if(btn)btn.focus();
                                    }
                                },onChange:e=>{
                                    const value=e.target.value;
                                    this.setState({propertyCustodianSearch:value,propertyCustodianEmployeeId:''});
                                }})),
                        this.state.propertyCustodianSearch&&!chosenCustodian?
                            h('div',{role:'listbox',className:'surface',style:{border:'1px solid #ccd6df',borderRadius:'8px',maxHeight:'220px',overflowY:'auto',marginTop:'-8px'}},
                                ...matchingCustodians.slice(0,12).map(employee=>h('button',{key:employee.employee_id,type:'button',role:'option',
                                    'aria-selected':false,style:{display:'block',width:'100%',padding:'9px 12px',textAlign:'left',border:'0',background:'transparent',cursor:'pointer'},
                                    onMouseDown:e=>e.preventDefault(),onClick:()=>this.setState({propertyCustodianSearch:employee.full_name,
                                        propertyCustodianEmployeeId:String(employee.employee_id)})},
                                    employee.full_name+(employee.designation||employee.position_title?' · '+(employee.designation||employee.position_title):''))),
                                !matchingCustodians.length?h('p',{className:'hint'},'No matching active employee.'):null):null,
                        chosenCustodian?h('p',{className:'hint'},'Selected accountable recipient: '+chosenCustodian.full_name):null,
                        this.state.staffError?h('p',{className:'alert bad'},this.state.staffError):null,
                        !this.state.staffLoading && !staffForProperty.length?h('p',{className:'hint'},
                            'No active employees are assigned to this school. Configure Reference Data → Employees first.'):null,
                        h('label',{className:'field'},h('span',null,'2. Search unassigned properties'),
                            h('input',{type:'search',value:this.state.propertySearch,onChange:e=>this.setState({propertySearch:e.target.value}),placeholder:'Search by property number or item description'})),
                        h('div',{className:'ics-selection-group'},
                            h('div',{className:'ics-selection-heading'},
                                h('strong',null,'3. Select properties (up to 20 per ICS)'),
                                h('span',{className:'ics-selection-totals','aria-live':'polite'},
                                    h('strong',{className:'ics-selected-count'},selectedAssets.length+' selected'),
                                    h('small',null,eligible.length+' unassigned '+(eligible.length===1?'property':'properties')+' shown'))),
                            h('div',{className:'ics-selection-list',role:'group','aria-label':'Properties available for ICS or PAR issuance'},
                                ...eligible.map(a=>{
                                    const picked=(this.state.propertyAssetIds||[]).some(id=>String(id)===String(a.id));
                                    const alreadyIssued=a.latest_accountability_number||finalizedAssetIds.has(String(a.id));
                                    const disabled=Boolean(alreadyIssued)||a.status!=='ACTIVE'||a.review_state==='PENDING_REVIEW'||!a.required_document||
                                        (Boolean(selectedKind)&&selectedKind!==a.required_document)||
                                        (selectedKind==='PAR'&&Boolean(selectedAssets.length)&&!picked)||
                                        (!picked&&selectedAssets.length>=20);
                                    const statusText=alreadyIssued?'Already issued':a.status!=='ACTIVE'?'Not active':
                                        a.review_state==='PENDING_REVIEW'?'Review first':!a.required_document?'Not eligible':
                                        selectedKind&&selectedKind!==a.required_document?'Different slip type':
                                        selectedKind==='PAR'&&selectedAssets.length&&!picked?'One PAR at a time':
                                        !picked&&selectedAssets.length>=20?'20 selected':picked?'Selected':'Available';
                                    return h('label',{key:a.id,className:'ics-selection-row'+(disabled?' is-disabled':'')+(picked?' is-selected':'')},
                                        h('input',{type:'checkbox',className:'ics-selection-check',checked:picked,disabled,
                                            'aria-label':'Select '+a.property_tag+' · '+a.item_name,onChange:e=>{
                                            const checked=e.target.checked;
                                            this.setState(prev=>({propertyAssetIds:checked?
                                                [...(prev.propertyAssetIds||[]),String(a.id)]:
                                                (prev.propertyAssetIds||[]).filter(id=>String(id)!==String(a.id))}));
                                        }}),
                                        h('span',{className:'ics-selection-details'},
                                            h('span',{className:'ics-selection-title'},
                                                h('strong',{className:'ics-selection-tag'},a.property_tag),
                                                h('span',{className:'ics-selection-name'},a.item_name)),
                                            h('span',{className:'ics-selection-meta'},
                                                h('span',{className:'ics-selection-type'},a.required_document||'Not eligible'),
                                                h('span',{className:'ics-selection-status'+(alreadyIssued?' is-issued':'')},statusText))));
                                }),!eligible.length?h('p',{className:'ics-selection-empty'},
                                    this.state.propertySearch.trim()? 'No unassigned properties match your search.':
                                    'No unassigned, reviewed properties are available for new ICS/PAR issuance.'):null)),
                        h('p',{className:'ics-selection-summary','aria-live':'polite'},
                            selectedAssets.length+' '+(selectedAssets.length===1?'property':'properties')+' selected'+
                            (selectedKind?' · '+selectedKind:'')+
                            (selectedAssets.length>1?' · One document number for all selected properties':'')+
                            (chosenCustodian?' · Accountable employee: '+chosenCustodian.full_name:'')),
                        h('button',{type:'submit',className:'primary',disabled:this.state.propertyBusy||this.state.staffLoading||!selectedAssets.length||!selectedKind||!chosenCustodian},'Issue '+(selectedKind||'ICS / PAR')+' · '+selectedAssets.length+' asset(s)'))):
                    h('p',{className:'inventory-context'},'Read-only access: authorized users may view issued slips without creating new accountability records.'),
                slips:()=>h('section',{className:'surface'},
                    h('h3',null,'Issued accountability slips'),
                    h('p',{className:'hint'},'Print and obtain official manual signatures. Do not interpret the digital record as an electronic signature.'),
                    h('div',{className:'table-scroll'},h('table',{className:'master-table'},
                        h('thead',null,h('tr',null,...['Number','Type','Property','Custodian','Status','Print'].map(x=>h('th',{key:x},x)))),
                        h('tbody',null,...accountability.map(r=>h('tr',{key:r.id},
                            h('td',null,r.display_number||'—'),h('td',null,r.doc_type),h('td',null,r.property_tag+' · '+r.item_name+(Number(r.property_count)>1?' + '+(Number(r.property_count)-1)+' more properties':'')),
                            h('td',null,r.custodian),h('td',null,Number(r.transferred_out)?'Transferred to receiving office (historical ICS/PAR)':(Number(r.returned_to_school)?'Returned to school (historical ICS/PAR)':r.status)),
                            h('td',null,r.status==='FINALIZED'?h('div',{className:'inline-actions'},
                                r.doc_type==='ICS'&&Number(r.property_count)>1?h('button',{type:'button',className:'outline',onClick:()=>this.printCombinedIcsRis(r,true)},'Print ICS ('+r.property_count+' assets)'):h('a',{className:'print-link',target:'_blank',rel:'noopener noreferrer',href:'api/print.php?organization_id='+encodeURIComponent(this.state.organization_id)+'&type='+encodeURIComponent(r.doc_type)+'&id='+encodeURIComponent(r.id)},'Print '+r.doc_type),
                                r.doc_type==='ICS'?h('button',{type:'button',className:'outline',onClick:()=>this.printCombinedIcsRis(r)},'Print RIS + ICS'):null,
                                r.doc_type==='ICS'?h('button',{type:'button',className:'outline',onClick:()=>this.printCombinedIcsRis(r,false,true)},'Report of Semi-Expendable Property Issued'):null):'—'))),
                            !accountability.length?h('tr',{key:'none'},h('td',{colSpan:6},'No accountability slips recorded for this organization.')):null)))),
                reports:()=>h('section',{className:'surface'},
                    h('h3',null,'Semi-Expendable Property Reports'),
                    h('p',{className:'body-muted'},'Choose a reporting period, then click an annex below to open just that report in a floating preview. The Report Center remains in IPDAMS.'),
                    h('div',{className:'property-report-filter-row',style:{display:'flex',alignItems:'end',flexWrap:'wrap',gap:'12px',padding:'12px 14px',border:'1px solid #d9e2ea',borderRadius:'10px',background:'#f7fafc',marginBottom:'14px'}},
                        h('label',{style:{display:'grid',gap:'4px',fontSize:'13px',fontWeight:600}},'From',
                            h('input',{type:'date',value:this.state.propertyReportFrom,required:true,
                                onChange:e=>this.setState({propertyReportFrom:e.target.value})})),
                        h('label',{style:{display:'grid',gap:'4px',fontSize:'13px',fontWeight:600}},'Through',
                            h('input',{type:'date',value:this.state.propertyReportThrough,required:true,
                                onChange:e=>this.setState({propertyReportThrough:e.target.value})})),
                        h('span',{className:'hint',style:{maxWidth:'440px',alignSelf:'center',margin:0}},
                            'A.4, A.5 and other movement-based reports show events within these dates. A.1 includes registered-asset evidence and is not yet a certified balance ledger.')),
                    !validReportPeriod()?h('p',{className:'warning',role:'alert'},'Choose valid reporting dates. From must not be later than Through.'):null,
                    h('div',{className:'property-report-annex-grid',role:'group','aria-label':'Choose an annex to preview',
                        style:{display:'grid',gridTemplateColumns:'repeat(auto-fit,minmax(210px,1fr))',gap:'10px'}},
                        ...reportAnnexes.map(([code,name])=>h('button',{type:'button',key:code,id:'property-annex-'+code,
                            className:'outline',disabled:!validReportPeriod(),
                            onClick:()=>this.setState({propertyReportModal:code}),
                            style:{textAlign:'left',display:'flex',flexDirection:'column',alignItems:'start',gap:'4px',padding:'12px',height:'auto',minHeight:'64px'}},
                            h('strong',{style:{fontSize:'14px'}},'Annex '+code.replace('A','A.')),
                            h('span',{style:{fontSize:'12px',fontWeight:400}},name)))),
                    h('p',{className:'hint',style:{marginTop:'12px'}},'Only the selected annex opens in the floating window. Close the preview to choose another report or change the dates. These are preliminary review reports, not certified COA forms.')),
                lifecycle:()=>h('div',{className:'property-transfer-tab'},
                    h('p',{className:'hint'},'Low-value: school-only unserviceable and disposal. High-value: separate Division Supply Officer approval for each step. Active inventory closes only after documented disposal.'),
                    h('iframe',{title:'Property Condition and Disposal',
                        src:'api/property-lifecycle.php?embedded=1&organization_id='+encodeURIComponent(this.state.organization_id),
                        loading:'lazy',style:{width:'100%',height:'1050px',border:'0',background:'transparent'},
                        onLoad:e=>{try{const f=e.currentTarget,main=f.contentDocument?.querySelector('main.page');
                            if(main)f.style.height=Math.max(450,Math.ceil(main.getBoundingClientRect().height)+30)+'px';
                        }catch(_err){/* same-origin embedding only; fallback height stays */}}
                    })),
                returns:()=>h('div',{className:'property-transfer-tab'},
                    h('p',{className:'hint'},'Record the physical return to school property custody. The original ICS/PAR remains in historical records. Serviceable returned items can later be reassigned using a new ICS/PAR.'),
                    h('iframe',{title:'Return Property · RRSP and PPE Return Register',
                        src:'api/property-returns.php?embedded=1&organization_id='+encodeURIComponent(this.state.organization_id),
                        loading:'lazy',className:'property-transfer-frame',
                        style:{width:'100%',height:'1100px',border:'0',display:'block',background:'transparent'},
                        onLoad:e=>{try{const frame=e.currentTarget,main=frame.contentDocument?.querySelector('main.rr-main');
                            if(main)frame.style.height=Math.max(500,Math.ceil(main.getBoundingClientRect().height)+40)+'px';
                        }catch(_err){/* if embedded height cannot be read, fallback remains */}}
                    })),
                incoming:()=>h('div',{className:'property-transfer-tab'},
                    h('iframe',{title:'Incoming transfers · School property receipt',
                        src:'api/property-transfer-intake.php?embedded=1&organization_id='+encodeURIComponent(this.state.organization_id),
                        className:'property-transfer-frame',loading:'lazy',
                        style:{width:'100%',height:'1350px',border:'0',display:'block',background:'transparent'},
                        onLoad:e=>{try{const frame=e.currentTarget,main=frame.contentDocument?.querySelector('main');
                            if(main)frame.style.height=Math.max(600,Math.ceil(main.getBoundingClientRect().height)+35)+'px';
                        }catch(_err){/* safe static height fallback */}}})),
                received_assets:()=>h('div',{className:'property-transfer-tab'},
                    h('iframe',{title:'Division Received Asset Register',
                        src:'api/property-transfer-intake.php?embedded=1&organization_id='+encodeURIComponent(this.state.organization_id)+'&view=received',
                        className:'property-transfer-frame',loading:'lazy',
                        style:{width:'100%',height:'950px',border:'0',display:'block',background:'transparent'},
                        onLoad:e=>{try{const frame=e.currentTarget,main=frame.contentDocument?.querySelector('main');
                            if(main)frame.style.height=Math.max(500,Math.ceil(main.getBoundingClientRect().height)+35)+'px';
                        }catch(_err){/* safe static height fallback */}}})),
                transfers:()=>h('div',{className:'property-transfer-tab'},
                    h('iframe',{
                        title:transferWorkspaceKind==='DIVISION'?'Incoming Transfers · Division receiving register':'Property Transfers · school transfer desk',
                        src:(transferWorkspaceKind==='DIVISION'?'api/property-transfer-intake.php':'api/property-transfers.php')+'?embedded=1&organization_id='+encodeURIComponent(this.state.organization_id),
                        className:'property-transfer-frame',
                        loading:'lazy',
                        style:{width:'100%',height:'960px',border:'0',display:'block',background:'transparent'},
                        onLoad:e=>{
                            // Same-origin only. Read the content height without allowing the
                            // embedded page to control the main application or its identity.
                            const frame=e.currentTarget;
                            if(frame._ipdamsTransferHeightObserver){frame._ipdamsTransferHeightObserver.disconnect();frame._ipdamsTransferHeightObserver=null;}
                            try{
                                const child=frame.contentDocument;
                                if(!child||!child.querySelector('main.shell'))return;
                                const shell=child.querySelector('main.shell');
                                const resize=()=>{if(!frame.isConnected)return;
                                    const height=Math.ceil(shell.getBoundingClientRect().height+22);
                                    frame.style.height=Math.max(350,height)+'px';};
                                resize();
                                if(typeof ResizeObserver==='function'){
                                    const observer=new ResizeObserver(resize);
                                    observer.observe(shell);
                                    frame._ipdamsTransferHeightObserver=observer;
                                }
                            }catch(_err){/* Keep a scrollable safe fallback height. */}
                        }
                    }))
            };
            return h('div',{className:'section-stack inventory-workspace'},
                this.header(schoolScopedDivision(this.state)?'Property Accountability · ICS / PAR':'Asset inventory · Property Accountability',schoolScopedDivision(this.state)?'Issue ICS/PAR in your assigned school. Full asset maintenance stays restricted; separately granted asset viewing is read-only.':'Track individually registered equipment, ICS / PAR issuance and custody.'),
                this.state.propertyError?h('p',{className:'error',role:'alert'},this.state.propertyError):null,
                this.state.propertyRegistrationNotice?h('div',{className:'notice success',role:'status',style:{display:'flex',alignItems:'center',justifyContent:'space-between',gap:'12px'}},
                    h('span',null,this.state.propertyRegistrationNotice),
                    h('button',{type:'button',className:'text-btn',onClick:this.clearPropertyNotice,'aria-label':'Dismiss property success message'},'Dismiss')):null,
                schoolScopedDivision(this.state)?h('div',{className:'inventory-guide'},
                    h('span',null,this.state.divisionModuleGrantsError||'Extra read-only tabs appear when an approved Special Function is active for this school.'),
                    h('button',{type:'button',className:'outline',disabled:this.state.divisionModuleGrantsBusy,
                        onClick:this.loadDivisionModuleGrants},this.state.divisionModuleGrantsBusy?'Checking…':'Refresh Permissions')):null,
                h('div',{className:'inventory-guide'},
                    h('span',null,'Finalizing a new IAR auto-registers eligible physical property (up to 100 whole units per receipt) with unique permanent tags. Review physical details before ICS/PAR. Older and larger IAR receipts remain in Earlier IAR Receipts. Supplies/RIS are separate.'),
                    h('div',{className:'inventory-guide-actions'},
                        h('button',{type:'button',className:'outline inventory-property-link',disabled:this.state.propertyBusy,onClick:this.loadAccountableProperty},this.state.propertyBusy?'Loading…':'Refresh'),
                        h('button',{type:'button',className:'outline inventory-property-link',onClick:()=>changeTab('reports')},'Property Reports'),
                        h('button',{type:'button',className:'outline inventory-property-link',onClick:()=>this.setState({tab:'Inventory Management',error:'',success:''})},'← Supplies Inventory'))),
                h('div',{className:'property-workspace-navigation'},
                    h('nav',{className:'property-workspace-groups','aria-label':'Property Accountability workflow groups'},
                        ...propertyGroups.map(group=>h('button',{
                            key:group.id,id:'property-group-'+group.id,type:'button',
                            className:'property-group-tab'+(group.id===activePropertyGroup.id?' is-active':''),
                            'aria-pressed':group.id===activePropertyGroup.id,
                            'aria-controls':'property-panel',
                            onClick:()=>changePropertyGroup(group.id)},
                            h('span',{className:'property-group-name'},group.label),
                            h('span',{className:'property-group-description'},group.description)))),
                    activePropertyGroup.tabs.length>1?h('div',{className:'property-workspace-subnav'},
                        h('span',{className:'property-workspace-subnav-label'},activePropertyGroup.label),
                        h('nav',{className:'property-workspace-subtabs',role:'tablist',
                            'aria-label':activePropertyGroup.label+' sections'},
                            ...activePropertyGroup.tabs.map(([id,label])=>h('button',{
                                key:id,id:'property-subtab-'+id,type:'button',role:'tab',
                                className:'property-workspace-subtab'+(id===section?' is-active':''),
                                'aria-selected':id===section,'aria-controls':'property-panel',
                                tabIndex:id===section?0:-1,
                                onClick:()=>changeTab(id),
                                onKeyDown:e=>{
                                    if(!['ArrowLeft','ArrowRight','Home','End'].includes(e.key))return;
                                    e.preventDefault();
                                    const current=activePropertyGroup.tabs.findIndex(tab=>tab[0]===id);
                                    const next=e.key==='Home'?0:e.key==='End'?activePropertyGroup.tabs.length-1:
                                        (current+(e.key==='ArrowRight'?1:-1)+activePropertyGroup.tabs.length)%activePropertyGroup.tabs.length;
                                    const nextId=activePropertyGroup.tabs[next][0];
                                    changeTab(nextId);
                                    if(typeof document!=='undefined')document.getElementById('property-subtab-'+nextId)?.focus();
                                }},label)))):null),
                h('section',{className:'inventory-tab-panel property-workspace-panel',id:'property-panel',role:'tabpanel',
                    'aria-labelledby':activePropertyGroup.tabs.length>1?'property-subtab-'+section:'property-group-'+activePropertyGroup.id,
                    key:section},panel[section]()),
                reportModal());
        };
        this.loadMooeIssuedDocs = async (month=this.state.mooeIssuedMonth) => {
            if(this.state.user?.role!=='VIEWER')return;
            const org=String(this.state.organization_id||'');
            if(!org)return;
            this.setState({mooeIssuedBusy:true,mooeIssuedError:'',mooeIssuedDocs:null});
            try{
                const result=await this.get('mooe.issued.documents',{organization_id:org,month});
                if(org===String(this.state.organization_id)&&month===this.state.mooeIssuedMonth)
                    this.setState({mooeIssuedDocs:result,mooeIssuedBusy:false});
            }catch(e){if(org===String(this.state.organization_id))this.setState({mooeIssuedBusy:false,
                mooeIssuedError:e.message||'Issued document register unavailable'});}
        };
        this.mooeIssuedDocumentView = (heading) => {
            const h=React.createElement,property=heading==='Property Accountability';
            const types=property?['ICS','PAR']:['RIS','RSMI'];
            const rows=(this.state.mooeIssuedDocs?.rows||[]).filter(r=>types.includes(r.doc_type));
            return h('div',{className:'section-stack'},
                this.header(heading+' · issued documents','Read-only: no stock balances, property register, drafts or editing.'),
                h('section',{className:'panel'},
                    h('div',{style:{display:'flex',gap:'12px',flexWrap:'wrap',alignItems:'end'}},
                        h('label',{className:'field'},h('span',null,'Issue month'),
                            h('input',{type:'month',value:this.state.mooeIssuedMonth,
                                onChange:e=>this.setState({mooeIssuedMonth:e.target.value,mooeIssuedDocs:null})})),
                        h('button',{type:'button',disabled:this.state.mooeIssuedBusy,
                            onClick:()=>this.loadMooeIssuedDocs()},'Show issued documents')),
                    this.state.mooeIssuedError?h('p',{className:'error'},this.state.mooeIssuedError):null,
                    this.state.mooeIssuedBusy?h('p',null,'Loading issued documents…'):null,
                    h('div',{className:'table-wrap'},h('table',null,
                        h('thead',null,h('tr',null,...['Type','Document No.','Recorded Date','Print'].map(x=>h('th',{key:x},x)))),
                        h('tbody',null,...rows.map(r=>h('tr',{key:r.doc_type+':'+r.id},
                            h('td',null,r.doc_type),h('td',null,r.display_number),
                            h('td',null,String(r.created_at||'').slice(0,10)),
                            h('td',null,h('a',{href:'api/print.php?organization_id='+encodeURIComponent(this.state.organization_id)+
                                '&type='+encodeURIComponent(r.doc_type)+'&id='+encodeURIComponent(r.id)+
                                '&month='+encodeURIComponent(this.state.mooeIssuedMonth),target:'_blank',rel:'noopener noreferrer'},'Print')))),
                            !rows.length?h('tr',{key:'empty'},h('td',{colSpan:4},
                                this.state.mooeIssuedDocs?'No issued '+types.join(' / ')+' recorded in this month.':
                                    'Select the month and click Show issued documents.')):null))),
                    !property?h('p',{className:'body-muted'},'RSMI Appendix 64 is not yet implemented. Only issued RIS is available.'):null));
        };
        this.stockView = () => {
            if(this.state.user?.role==='VIEWER')return this.mooeIssuedDocumentView('Inventory Management');
            const h=React.createElement;
            const tabs=schoolScopedDivision(this.state)?[...(divisionLiveSpecial(this.state,'STOCK')?[['special_stock','Stock on Hand (View)']]:[]),['ris','RIS Requests'],['issue','Issue Stock']]:[['stock','Stock on Hand'],['ris','RIS Requests'],['issue','Issue Stock'],['opening','Opening Balances']];
            const section=tabs.some(([id])=>id===this.state.inventorySection)?this.state.inventorySection:(schoolScopedDivision(this.state)?'ris':'stock');
            const changeTab=id=>this.setState({inventorySection:id},()=>{if(id==='special_stock')this.loadDivisionContextRegister('STOCK');});
            const tabBar=h('nav',{className:'inventory-tabbar',role:'tablist','aria-label':'Inventory sections'},
                ...tabs.map(([id,label])=>h('button',{key:id,id:'inventory-tab-'+id,type:'button',role:'tab',
                    className:'inventory-tab'+(id===section?' is-active':''),'aria-selected':id===section,
                    'aria-controls':'inventory-panel',tabIndex:id===section?0:-1,
                    onClick:()=>changeTab(id),
                    onKeyDown:e=>{
                        if(!['ArrowLeft','ArrowRight','Home','End'].includes(e.key))return;
                        e.preventDefault();const i=tabs.findIndex(t=>t[0]===id);
                        const j=e.key==='Home'?0:e.key==='End'?tabs.length-1:
                            (i+(e.key==='ArrowRight'?1:-1)+tabs.length)%tabs.length;
                        changeTab(tabs[j][0]);
                        if(typeof document!=='undefined')document.getElementById('inventory-tab-'+tabs[j][0])?.focus();
                    }},label)));
            const stockPanel=()=>{
                const excluded=this.state.lists.stock_exceptions||[];
                return h('div',{className:'inventory-tab-content'},
                    h('p',{className:'inventory-context'},'Only catalog-classified consumable SUPPLY lots are available here for RIS. Accepted property is managed separately through Asset Inventory; service acceptance is not physical stock.'),
                    this.table('Consumable supplies on hand', ['id','item_name','batch','on_hand'],this.state.lists.stocks||[]),
                    excluded.length?h('details',{className:'inventory-stock-review'},
                        h('summary',null,excluded.length+' legacy non-supply receipt lot(s) excluded · View read-only reconciliation'),
                        h('p',{className:'hint'},'These historical records have NOT been deleted or reposted. PPE/semi-expendable lots belong in Asset Inventory; services and textbook receipts need the appropriate documentary/ledger review. A catalog classification is not sufficient evidence of an approved retrospective correction.'),
                        this.table('Excluded historical receipts — not issuable as supplies', ['id','item_name','category','iar_number','on_hand'],excluded)):null);
            };
            const risPanel=()=>h('div',{className:'inventory-tab-content'},
                h('section',{className:'surface'},
                    h('h3',null,'New requisition & issue slip'),
                    h('form',{onSubmit:this.createRIS},
                        h('label',{className:'field'},h('span',null,'Source of requested items'),
                            h('select',{value:this.state.ris_mode,onChange:this.set('ris_mode')},
                                h('option',{value:'DIRECT'},'Select stock item directly'),
                                h('option',{value:'PR'},'Import items from PR'))),
                        this.state.ris_mode==='PR'?h('p',{className:'hint'},'The requesting employee recorded on the PR will be used automatically. For older PRs with no employee recorded, enter the requester below.'):null,
                        this.field('ris_requestor','Requesting employee / unit (legacy PR / direct)','Required only for legacy PRs and direct RIS'),
                        this.state.ris_mode==='PR'?this.select('ris_pr','Source PR',(this.state.lists.pr||[]).filter(r=>r.status==='FINALIZED').map(r=>({id:r.id,name:r.display_number+' '+r.purpose}))):
                            h('div',{className:'form-grid'},
                                this.select('ris_item','Consumable supply',(this.state.lists.items||[]).filter(i=>i.category==='SUPPLY')),
                                this.field('ris_qty','Requested quantity','e.g. 3')),
                        this.act('Save RIS draft',this.createRIS))),
                this.table('Requisition and issue slips',['id','display_number','requestor','source_mode','status'],this.state.lists.ris||[],'RIS'));
            const issuePanel=()=>h('div',{className:'inventory-tab-content'},
                h('section',{className:'surface'},
                    h('h3',null,'Issue physically available stock'),
                    h('p',{className:'inventory-context'},'Confirm the RIS item matches the actual lot. Do not issue service or accountable property as ordinary supplies.'),
                    h('form',{onSubmit:this.issueRIS},
                        h('div',{className:'form-grid'},
                            this.select('ris_id','Draft RIS',(this.state.lists.ris||[]).filter(r=>r.status==='DRAFT').map(r=>({id:r.id,name:'RIS #'+r.id+' '+r.requestor}))),
                            schoolScopedDivision(this.state)?this.select('ris_line_id','RIS line',this.state.divisionRisLines.map(l=>({id:l.id,name:l.item_name+' · requested '+l.requested_qty}))):this.field('ris_line_id','RIS line ID','See RIS details'),
                            this.select('ris_lot_id','Eligible stock lot',(schoolScopedDivision(this.state)?this.state.divisionRisLots:(this.state.lists.stocks||[])).map(l=>({id:l.id,name:schoolScopedDivision(this.state)?l.item_name+' · '+l.batch+' (lot '+l.id+')':l.item_name+' · '+l.on_hand+' available (lot '+l.id+')'}))),
                            this.field('ris_issue_qty','Issued quantity','Must not exceed on-hand')),
                        this.act('Post RIS issuance',this.issueRIS))),
                h('p',{className:'hint'},'Issuance subtracts physical inventory exactly once. Importing PR lines never creates stock.'));
            const openingPanel=()=>h('div',{className:'inventory-tab-content'},
                h('section',{className:'surface'},
                    h('h3',null,'Enter legacy / opening stock'),
                    h('p',{className:'inventory-context'},'SUPPLY items only. Enter independently verified historical quantities; never duplicate IAR receipts. PPE/semi-expendable assets belong in Property Accountability.'),
                    h('form',{onSubmit:async(e)=>{e.preventDefault();await this.task('stock.opening',{item_id:Number(this.state.stock_item),quantity:this.state.stock_qty});}},
                        h('div',{className:'form-grid'},
                            this.select('stock_item','Consumable supply',(this.state.lists.items||[]).filter(i=>i.category==='SUPPLY')),
                            this.field('stock_qty','Opening quantity','Quantity counted')),
                        this.act('Post opening stock',null))));
            const panels={stock:stockPanel,special_stock:()=>this.divisionContextPanel('STOCK'),ris:risPanel,issue:issuePanel,opening:openingPanel};
            return h('div',{className:'section-stack inventory-workspace'},
                this.header('Inventory management',schoolScopedDivision(this.state)?'Assigned school: RIS preparation and issue. Full stock maintenance stays restricted; separately granted Stock on Hand viewing is read-only.':'Physical stock, requests, issuance and opening balances in separate sections.'),
                schoolScopedDivision(this.state)?h('div',{className:'inventory-guide'},
                    h('span',null,this.state.divisionModuleGrantsError||'Extra read-only tabs appear when an approved Special Function is active for this school.'),
                    h('button',{type:'button',className:'outline',disabled:this.state.divisionModuleGrantsBusy,
                        onClick:this.loadDivisionModuleGrants},this.state.divisionModuleGrantsBusy?'Checking…':'Refresh Permissions')):null,
                h('div',{className:'inventory-guide'},
                    h('span',null,'Accountable equipment and ICS/PAR are managed in Property Accountability.'),
                    h('button',{type:'button',className:'outline inventory-property-link',onClick:()=>this.setState({tab:'Property Accountability',error:'',success:''})},'Open Asset Inventory →')),
                tabBar,
                h('section',{id:'inventory-panel',role:'tabpanel','aria-labelledby':'inventory-tab-'+section,
                    className:'inventory-tab-panel',key:section},panels[section]()));
        };
        this.advanced = () => React.createElement("div", { className: "surface advanced" },
            React.createElement("div", { className: "section-head" },
                React.createElement("h3", null, "Advanced workflow actions"),
                React.createElement("span", { className: "mini-tag" }, "Structured API workbench")),
            React.createElement("p", { className: "body-muted" }, "For complex multi-line transactions and administrative functions, send valid JSON to the selected server action. Server-side scoping, validation and audit apply to every request. See the User Guide for payload samples."),
            React.createElement("form", { onSubmit: this.runAdvanced },
                React.createElement("div", { className: "form-grid" },
                    React.createElement("label", { className: "field" },
                        React.createElement("span", null, "Action"),
                        React.createElement("select", { value: this.state.advancedRoute, onChange: this.set('advancedRoute') }, ['pr.finalize', 'rfq.create', 'quote.create', 'award.create', 'po.create', 'po.amend', 'iar.create', 'advance.create', 'dv.accounting', 'tax.confirm', 'ris.reverse', 'fund.event', 'check.create', 'check.status', 'check.replace', 'bank.reconcile', 'accountability.issue', 'asset.opening', 'asset.move', 'textbook.opening', 'textbook.move', 'document.cancel', 'document.signed-copy'].map(r => React.createElement("option", { key: r, value: r }, r))))),
                React.createElement("label", { className: "field" },
                    React.createElement("span", null, "JSON request data (organization_id is added automatically)"),
                    React.createElement("textarea", { className: "json-input", rows: 5, spellCheck: false, value: this.state.advancedBody, onChange: this.set('advancedBody') })),
                this.act('Validate & execute action', this.runAdvanced)));
        this.textbookView = () => {
            const batches=this.state.tbBatches||[];
            const options=batches.map(b=>({id:b.id,name:b.title+' — '+b.batch+' (on hand: '+b.on_hand+')'}));
            const canWrite=this.state.user?.role!=='VIEWER';
            return React.createElement('div',{className:'section-stack'},
                this.header('Textbook inventory','Record opening title batches, stock movements and balances for this organization.'),
                React.createElement('div',{className:'surface'},
                    React.createElement('div',{className:'section-head'},
                        React.createElement('h3',null,'Textbook batches'),
                        React.createElement('button',{type:'button',className:'outline',disabled:this.state.tbLoading,
                            onClick:this.loadTextbookBatches},this.state.tbLoading?'Loading…':'Refresh batches')),
                    this.state.tbError?React.createElement('p',{role:'alert'},this.state.tbError):null,
                    this.table('Current balances',['id','title','isbn','grade','subject','batch','storage','on_hand'],batches)),
                canWrite?React.createElement('div',{className:'two-col-main'},
                    React.createElement('div',{className:'surface'},
                        React.createElement('h3',null,'Record opening textbook batch'),
                        React.createElement('p',{className:'body-muted'},'Opening balance is historical stock, not a purchase or new receipt.'),
                        React.createElement('form',{onSubmit:this.createTextbookOpening},
                            React.createElement('div',{className:'form-grid'},
                                this.field('tb_title','Textbook title *','e.g. Science Grade 5'),
                                this.field('tb_isbn','ISBN','Optional ISBN'),
                                this.field('tb_grade','Grade / level','Grade 5'),
                                this.field('tb_subject','Subject','Science'),
                                this.field('tb_batch','Batch identifier *','e.g. 2026-A'),
                                this.field('tb_condition_note','Condition','Good / New'),
                                this.field('tb_storage','Storage location','Library'),
                                this.field('tb_opening_qty','Opening copies *','Whole number','number')),
                            this.act('Record opening balance',this.createTextbookOpening))),
                    React.createElement('div',{className:'surface'},
                        React.createElement('h3',null,'Record textbook movement'),
                        React.createElement('form',{onSubmit:this.createTextbookMove},
                            React.createElement('div',{className:'form-grid'},
                                this.select('tb_move_batch_id','Textbook batch *',options,'Select a registered batch'),
                                React.createElement('label',{className:'field'},React.createElement('span',null,'Movement type'),
                                    React.createElement('select',{value:this.state.tb_move_event_type,onChange:this.set('tb_move_event_type')},
                                        ['RECEIPT','RETURN','DISTRIBUTION','TRANSFER','LOST','DISPOSAL','COUNT_ADJUSTMENT'].map(x=>
                                            React.createElement('option',{key:x,value:x},x)))),
                                this.field('tb_move_quantity','Quantity *','Whole number','number'),
                                this.state.tb_move_event_type==='COUNT_ADJUSTMENT'?
                                    React.createElement('label',{className:'field'},React.createElement('span',null,'Adjustment direction'),
                                        React.createElement('select',{value:this.state.tb_move_direction,onChange:this.set('tb_move_direction')},
                                            React.createElement('option',{value:'IN'},'Increase'),
                                            React.createElement('option',{value:'OUT'},'Decrease'))):null,
                                this.field('tb_move_reference','Reference / remarks *','Document, recipient or reason')),
                            this.act('Record movement',this.createTextbookMove)))):React.createElement('p',{className:'body-muted'},'Read-only access; authorized staff can record movements.'),
                React.createElement('div',{className:'surface'},
                    React.createElement('div',{className:'section-head'},
                        React.createElement('h3',null,'Read-only textbook movement history'),
                        React.createElement('button',{type:'button',className:'outline',disabled:this.state.tbHistoryLoading,
                            onClick:()=>this.loadTextbookHistory()},this.state.tbHistoryLoading?'Loading…':'Refresh history')),
                    React.createElement('p',{className:'body-muted'},'Showing up to 100 transactions per page. Filter by month or batch; use Load older records for earlier entries.'),
                    React.createElement('div',{className:'form-grid'},
                        React.createElement('label',{className:'field'},React.createElement('span',null,'Month (optional)'),
                            React.createElement('input',{type:'month',value:this.state.tbHistoryMonth||'',
                                onChange:e=>this.setState({tbHistoryMonth:e.target.value},()=>this.loadTextbookHistory())})),
                        React.createElement('label',{className:'field'},React.createElement('span',null,'Batch (optional)'),
                            React.createElement('select',{value:this.state.tbHistoryBatch||'',
                                onChange:e=>this.setState({tbHistoryBatch:e.target.value},()=>this.loadTextbookHistory())},
                                React.createElement('option',{value:''},'All textbook batches'),
                                batches.map(b=>React.createElement('option',{key:b.id,value:String(b.id)},
                                    b.title+' — '+b.batch))))),
                    this.state.tbHistoryError?React.createElement('p',{role:'alert'},this.state.tbHistoryError):null,
                    this.table('Transactions',['created_at','title','batch','event_type','quantity_delta','reference','actor_id'],
                        this.state.tbHistory||[]),
                    this.state.tbHistoryHasMore?React.createElement('button',{type:'button',className:'outline',
                        disabled:this.state.tbHistoryLoading,onClick:()=>this.loadTextbookHistory(true)},
                        this.state.tbHistoryLoading?'Loading…':'Load older records'):null));
        };
                // Non-posting travel reimbursement documentary intake and review.
        this.loadTravelClaims = async () => {
            const org=String(this.state.organization_id||'');if(!org)return;
            this.setState({travelClaimsLoading:true,travelClaimError:''});
            try{const result=await this.get('travel.claims',{organization_id:org});
                if(String(this.state.organization_id)===org)this.setState({travelClaims:result,travelClaimsLoading:false});
            }catch(error){if(String(this.state.organization_id)===org)this.setState({travelClaimsLoading:false,travelClaimError:error.message});}
        };
        this.openTravelClaim = async id => {
            const org=String(this.state.organization_id||'');
            try{const result=await this.get('travel.claim.detail',{organization_id:org,claim_id:id});
                if(String(this.state.organization_id)!==org)return;
                const snap=result.snapshot;
                this.setState({travelClaimDetail:result,travelClaimEditing:snap.state==='DRAFT_DOCUMENTS_PENDING',
                    travelClaimDraft:{authority_id:String(snap.travel_authority_id),claim_date:snap.claim_date,
                        actual_from:snap.actual_from,actual_to:snap.actual_to,expense_note:snap.expense_note||'',
                        lines:(snap.claim_lines||[]).map(l=>({category:l.category,description:l.description,amount:l.amount}))},
                    travelClaimChecks:emptyTravelVerification(),travelClaimError:''});
            }catch(error){this.setState({travelClaimError:error.message});}
        };
        this.saveTravelClaim = async event => {
            event.preventDefault();if(this.state.busy||this.travelClaimActionPending||!this.canOperateWorkspace())return;
            this.travelClaimActionPending=true;
            try{const d=this.state.travelClaimDraft,r=this.state.travelClaimDetail;
                const editing=!!r && this.state.travelClaimEditing;
                const body={...d,authority_id:Number(d.authority_id),
                    ...(editing?{claim_id:Number(r.id),expected_version:r.version}:{})};
                const saved=await this.task(editing?'travel.claim.revise':'travel.claim.create',body,{refresh:false});
                if(saved){await this.loadTravelClaims();await this.openTravelClaim(saved.id);
                    this.setState({success:'Reimbursement claim '+saved.number+' saved as documentary draft. Upload evidence; no payment or DV was created.'});}
            }finally{this.travelClaimActionPending=false;}
        };
        this.uploadTravelClaimEvidence = async (kind,file) => {
            const record=this.state.travelClaimDetail;
            if(!record||!file||this.travelClaimActionPending||this.state.busy||!this.canOperateWorkspace())return;
            if(!['application/pdf','image/png','image/jpeg'].includes(file.type) || file.size>8388608||!file.size){
                this.setState({travelClaimError:'Select a PDF, PNG or JPEG file no larger than 8 MB.'});return;
            }
            const org=String(this.state.organization_id);this.travelClaimActionPending=true;
            this.setState({travelClaimUploadKind:kind,travelClaimError:''});
            try{
                const form=new FormData();form.append('organization_id',org);form.append('doc_type','RC');
                form.append('entity_id',String(record.id));form.append('attachment',file,file.name);
                const response=await fetch('api/attachment.php',{method:'POST',credentials:'same-origin',
                    headers:{'X-CSRF-Token':this.state.csrf},body:form});
                const result=await response.json().catch(()=>({error:'Upload response unreadable'}));
                if(!response.ok)throw new Error(result.error||'Attachment upload failed');
                const saved=await this.api('travel.claim.evidence',{organization_id:Number(org),claim_id:Number(record.id),
                    expected_version:record.version,evidence_kind:kind,attachment_id:Number(result.data.id)});
                if(String(this.state.organization_id)!==org)return;
                await this.openTravelClaim(saved.id);await this.loadTravelClaims();
                this.setState({success:'Document uploaded and linked to this reimbursement claim.'});
            }catch(error){if(String(this.state.organization_id)===org)this.setState({travelClaimError:error.message||'Document was not linked. Refresh the claim before retrying.'});}
            finally{this.travelClaimActionPending=false;this.setState({travelClaimUploadKind:''});}
        };
        this.verifyTravelClaim = async event => {
            event.preventDefault();
            const record=this.state.travelClaimDetail;
            if(!record||this.travelClaimActionPending||this.state.busy||!['DIVISION','SUPER_ADMIN'].includes(this.state.user?.role))return;
            this.travelClaimActionPending=true;this.setState({travelClaimError:''});
            try{const saved=await this.task('travel.claim.verify',{claim_id:Number(record.id),
                expected_version:record.version,...this.state.travelClaimChecks},{refresh:false});
                if(saved){await this.openTravelClaim(saved.id);await this.loadTravelClaims();
                    this.setState({success:'Required documents manually checked. Accounting review still required; no DV or payment was created.'});}
            }finally{this.travelClaimActionPending=false;}
        };
        this.travelClaimsView = () => {
            const h=React.createElement,s=this.state,canEdit=this.canOperateWorkspace();
            const list=s.travelClaims?.claims||[],required=s.travelClaims?.required_evidence||{};
            const types=s.travelClaims?.cost_types||{
                TRANSPORT:'Transportation / fares',DAILY_EXPENSE:'Allowable daily travel expenses',
                LODGING:'Lodging',OTHER_TRAVEL:'Other eligible travel costs (for review)'};
            const already=new Set(list.map(c=>String(c.authority_id)));
            const d=s.travelClaimDraft,detail=s.travelClaimDetail,doc=detail?.snapshot||null;
            const editing=!!detail && s.travelClaimEditing;
            const hasAny=Object.keys(doc?.evidence||{}).length;
            const money=value=>Number(value||0).toLocaleString('en-PH',{style:'currency',currency:'PHP'});
            const update=(key,val)=>this.setState({travelClaimDraft:{...this.state.travelClaimDraft,[key]:val}});
            const changeLine=(index,key,value)=>this.setState(prev=>({travelClaimDraft:{...prev.travelClaimDraft,
                lines:prev.travelClaimDraft.lines.map((l,i)=>i===index?{...l,[key]:value}:l)}}));
            const total=d.lines.reduce((sum,l)=>sum+(Number(l.amount)||0),0);
            // A claim may only be prepared after the planned trip has ended.
            // The server additionally checks actual dates, school funding and original linkage.
            const eligibleAuthorities=(s.travelDocuments?.authorities||[]).filter(a=>
                !already.has(String(a.id)) && !!a.travel_to && a.travel_to<=localToday() &&
                (s.travelDocuments?.requests||[]).some(r=>String(r.id)===String(a.request_id) &&
                    ['SCHOOL_MOOE','SPLIT'].includes(r.chargeable_to_code||
                        (r.chargeable_to==='School MOOE'?'SCHOOL_MOOE':
                         r.chargeable_to==='Split / Multiple funding offices'?'SPLIT':''))));
            const upcomingAuthorities=(s.travelDocuments?.authorities||[]).filter(a=>
                !already.has(String(a.id)) && a.travel_to>localToday());
            const canCreate=canEdit&&eligibleAuthorities.length>0;
            return h('section',{className:'surface travel-claims','aria-label':'Travel reimbursement documentary claims'},
                h('header',{className:'travel-claims-head'},h('div',null,
                    h('h3',null,'Travel Reimbursement · Supporting Documents'),
                    h('p',{className:'body-muted'},'Prepare actual expense claims for completed travel. The returned, approved and Division-countersigned Activity Request, signed Travel Authority, travel completion evidence and expense proof must be uploaded. Nothing here creates a DV or pays an employee.')),
                    h('button',{type:'button',className:'outline',onClick:()=>{this.loadTravelClaims();this.loadTravelDocuments();},disabled:s.travelClaimsLoading},
                        s.travelClaimsLoading?'Loading…':'Refresh claims')),
                s.travelClaimError?h('p',{className:'notice error',role:'alert'},s.travelClaimError):null,
                list.length?h('div',{className:'travel-claim-register'},
                    ...list.map(claim=>h('div',{className:'travel-record',key:'RC'+claim.id},
                        h('div',null,h('strong',null,claim.number+' · '+claim.employee),
                            h('p',{className:'hint'},money(claim.total)+' · '+claim.status.replace(/_/g,' ')+
                                ' · '+claim.uploaded_count+'/'+claim.required_count+' evidence types')),
                        h('button',{type:'button',className:'outline',onClick:()=>this.openTravelClaim(claim.id)},'Open claim')))):
                    h('p',{className:'hint'},'No reimbursement claims saved yet.'),
                !eligibleAuthorities.length&&!detail?h('div',{className:'travel-claim-empty',role:'status'},
                    h('strong',null,upcomingAuthorities.length?'Your recorded trip is still upcoming.':'No completed travel is ready for a new claim.'),
                    h('p',null,upcomingAuthorities.length?
                        'Prepare the reimbursement only after the trip and its supporting documents are completed.':
                        'Prepare a linked Travel Authority first. Only completed trips chargeable to School MOOE or an eligible school share can use the school reimbursement route.'),
                    h('button',{type:'button',className:'outline',onClick:()=>this.setState({travelStage:'AUTHORITY'})},'View Travel Authorities')):null,
                (eligibleAuthorities.length>0||detail)?h('div',{className:'travel-actions'},h('button',{type:'button',className:'outline',disabled:s.busy||!canEdit,
                    onClick:()=>this.setState({travelClaimDetail:null,travelClaimEditing:false,
                        travelClaimDraft:emptyTravelClaim(),travelClaimChecks:emptyTravelVerification(),travelClaimError:''})},'Prepare another claim')):null,
                (!detail||editing)&&(eligibleAuthorities.length>0||editing)?h('form',{className:'travel-claim-form',onSubmit:this.saveTravelClaim},
                    h('h4',null,editing?'Correct saved reimbursement claim':'Prepare reimbursement claim'),
                    !eligibleAuthorities.length&&!editing?h('p',{className:'hint'},
                        'Create a Travel Authority for completed School MOOE or split-funded travel first. Fully Division/Region-funded travel must be claimed with the paying office, not the school.'):null,
                    h('div',{className:'form-grid'},
                        h('label',{className:'field'},h('span',null,'Linked Travel Authority'),
                            h('select',{required:true,disabled:editing||!canEdit,value:d.authority_id,
                                onChange:e=>update('authority_id',e.target.value)},
                                h('option',{value:''},'Select an eligible Travel Authority'),
                                ...eligibleAuthorities.map(a=>h('option',{key:a.id,value:String(a.id)},a.number+' · '+a.employee)),
                                editing?h('option',{value:d.authority_id},doc?.travel_authority_number+' · '+doc?.employee?.full_name):null)),
                        ...[['claim_date','Claim date'],['actual_from','Actual travel started'],['actual_to','Actual travel completed']].map(([key,label])=>
                            h('label',{className:'field',key},h('span',null,label),h('input',{type:'date',required:true,max:localToday(),
                                disabled:!canEdit,value:d[key],onChange:e=>update(key,e.target.value)}),
                                key==='claim_date'?weekendDocumentNotice(h,d[key],label):null))),
                    h('h4',null,'Actual expenses claimed (subject to Accounting verification)'),
                    ...d.lines.map((line,index)=>h('div',{className:'travel-claim-line',key:index},
                        h('label',{className:'field'},h('span',null,'Expense type'),h('select',{required:true,disabled:!canEdit,
                            value:line.category,onChange:e=>changeLine(index,'category',e.target.value)},
                            ...Object.entries(types).map(([key,label])=>h('option',{key,value:key},label)))),
                        h('label',{className:'field'},h('span',null,'Actual expense / particular'),
                            h('input',{type:'text',required:true,maxLength:240,disabled:!canEdit,
                                value:line.description,onChange:e=>changeLine(index,'description',e.target.value)})),
                        h('label',{className:'field'},h('span',null,'Amount (₱)'),
                            h('input',{required:true,type:'number',min:'0.01',max:'1000000',step:'0.01',disabled:!canEdit,
                                value:line.amount,onChange:e=>changeLine(index,'amount',e.target.value)})),
                        h('button',{type:'button',className:'outline',disabled:!canEdit||d.lines.length===1,
                            onClick:()=>update('lines',d.lines.filter((_,i)=>i!==index))},'Remove'))),
                    h('div',{className:'travel-actions'},h('button',{type:'button',className:'outline',disabled:!canEdit||d.lines.length>=20,
                        onClick:()=>update('lines',[...d.lines,{category:'TRANSPORT',description:'',amount:''}])},'Add expense'),
                        h('strong',null,'Claimed total '+money(total))),
                    h('label',{className:'field'},h('span',null,'Notes / differences from planned itinerary (optional)'),
                        h('textarea',{maxLength:600,rows:2,disabled:!canEdit,value:d.expense_note,
                            onChange:e=>update('expense_note',e.target.value)})),
                    h('p',{className:'hint'},'Training registration fees and supplier services follow their own authorized routes. This is an employee travel claim only.'),
                    h('button',{type:'submit',className:'primary',disabled:s.busy||!canEdit||(!editing&&!canCreate)},
                        editing?'Save claim corrections (audited)':'Save reimbursement draft')):null,
                detail?h('section',{className:'travel-claim-evidence'},
                    h('h4',null,'Supporting documents · '+detail.number),
                    h('p',{className:'hint'},'Upload scanned PDF/JPEG/PNG files (max 8 MB each). Files are stored privately and linked to this claim; uploads do NOT mean they are approved.'),
                    ...Object.entries(required).map(([kind,label])=>{
                        const recorded=doc.evidence?.[kind],locked=doc.state!=='DRAFT_DOCUMENTS_PENDING';
                        return h('div',{className:'travel-claim-evidence-row',key:kind},
                            h('div',null,h('strong',null,label),recorded?
                                h('p',{className:'hint'},'Linked: '+recorded.original_name):
                                h('p',{className:'hint'},'Required · not yet linked')),
                            recorded?h('a',{href:'api/attachment.php?organization_id='+encodeURIComponent(s.organization_id)+'&id='+recorded.id,
                                target:'_blank',rel:'noopener',className:'print-link'},'View file'):null,
                            !locked&&canEdit?h('input',{type:'file',accept:'.pdf,.png,.jpg,.jpeg,application/pdf,image/png,image/jpeg',
                                disabled:!!s.travelClaimUploadKind||s.busy,
                                'aria-label':'Upload '+label,onChange:e=>{
                                    const selected=e.target.files?.[0];if(selected)this.uploadTravelClaimEvidence(kind,selected);
                                    e.target.value='';}}):null);
                    }),
                    h('p',{className:'hint'},'Evidence linked: '+hasAny+' of '+Object.keys(required).length+'. A signed paper original/certified copy remains authoritative.'),
                    doc.state==='DRAFT_DOCUMENTS_PENDING'&&['DIVISION','SUPER_ADMIN'].includes(s.user?.role)?
                        h('form',{className:'travel-claim-review',onSubmit:this.verifyTravelClaim},
                            h('h4',null,'Division manual-document review (not payment approval)'),
                            ...[
                                ['approved_request_checked','I inspected the returned Activity Request with actual authorizing signatures.'],
                                ['division_countersign_checked','The Division handwritten estimate is countersigned on the approved request.'],
                                ['authority_signed_checked','The Travel Authority is properly signed and linked to this trip.'],
                                ['travel_completed_checked','Actual travel took place; appearance/completion was checked.'],
                                ['receipts_checked','Actual expense statements and required receipts/authorized alternatives were inspected.'],
                                ['duplicate_claim_checked','I checked that these costs were not already reimbursed or paid by another office.']
                            ].map(([key,label])=>h('label',{className:'travel-check',key},
                                h('input',{type:'checkbox',checked:!!s.travelClaimChecks[key],
                                    onChange:e=>this.setState({travelClaimChecks:{...s.travelClaimChecks,[key]:e.target.checked}})}),label)),
                            h('button',{type:'submit',className:'primary',
                                disabled:s.busy||hasAny!==Object.keys(required).length||!Object.values(s.travelClaimChecks).every(Boolean)},
                                'Mark documents checked for Accounting review')):
                        h('p',{className:'hint'},doc.state==='DOCUMENTS_CHECKED_FOR_ACCOUNTING_REVIEW'?
                            'Documents have been checked by an authorized reviewer. Accounting eligibility, DV and fund checks are NOT complete.':
                            'A separate authorized Division reviewer must inspect every required document before Accounting review.'),
                    h('div',{className:'travel-actions'},
                        h('button',{type:'button',className:'outline',onClick:()=>this.openTravelClaim(detail.id)},'Refresh document evidence'))):null);
        };

        this.loadTravelDocuments = async () => {
            const org=String(this.state.organization_id||'');if(!org)return;
            this.setState({travelLoading:true,travelError:''});
            try{const data=await this.get('travel.documents',{organization_id:org});
                if(String(this.state.organization_id)===org)this.setState({travelDocuments:data,travelLoading:false});
            }catch(err){if(String(this.state.organization_id)===org)this.setState({travelError:err.message,travelLoading:false});}
        };
        this.editTravelRequest = async id => {
            try{const r=await this.get('travel.detail',{type:'AR',id});const d=r.snapshot;
                this.setState({travelStage:'REQUEST',travelRequestFormOpen:true,travelEditId:String(id),travelEditVersion:r.version,travelEditReason:'',
                    travelRequest:{employee_id:String(d.employee?.id||''),fund_id:String(d.fund?.id||''),
                        chargeable_to:d.chargeability?.code||'SCHOOL_MOOE',
                        chargeability_reference:d.chargeability?.reference||'',
                        request_date:d.document_date,activity_title:d.activity_title,host:d.host||'',
                        purpose:d.purpose,destination:d.destination,travel_from:d.travel_from,
                        travel_to:d.travel_to,itinerary_reference:d.itinerary_reference,
                        supporting_reference:d.supporting_reference||''},travelError:''});
            }catch(err){this.setState({travelError:err.message});}
        };
        this.saveTravelRequest = async event => {
            event.preventDefault();
            // A Division signatory correction is NOT a new Activity Request.
            // Keep all document writes single-flight even before React re-renders.
            if(this.travelActionPending || this.state.busy || this.state.travelRefreshSignatoryId || !this.canOperateWorkspace())return;
            this.travelActionPending='activity-request';
            try {
                const edit=!!this.state.travelEditId;
                const req=this.state.travelRequest;
                // Never send handwritten Division estimates from an employee draft.
                const {estimated_cost:_discardParticipantEstimate,...safeRequest}=req;
                const body={...safeRequest,employee_id:Number(req.employee_id),
                    fund_id:req.fund_id?Number(req.fund_id):null,
                    ...(edit?{request_id:Number(this.state.travelEditId),expected_version:this.state.travelEditVersion,reason:this.state.travelEditReason}:{})};
                const saved=await this.task(edit?'travel.activity.revise':'travel.activity.create',body,{refresh:false});
                if(saved){this.setState({travelEditId:'',travelEditVersion:0,travelEditReason:'',travelRequestFormOpen:false,travelRequest:emptyTravelRequest(),success:'Activity Request draft '+saved.number+' saved; manual approvals are still required.'});
                    await this.loadTravelDocuments();}
            } finally {this.travelActionPending='';}
        };
        this.saveTravelAuthority = async event => {
            event.preventDefault();if(!this.canOperateWorkspace())return;
            const saved=await this.task('travel.authority.create',{
                request_id:Number(this.state.travelAuthorityRequestId),authority_date:this.state.travelAuthorityDate,
                approval_reference:this.state.travelApprovalReference.trim(),paper_approval_confirmed:this.state.travelPaperApproved
            },{refresh:false});
            if(saved){this.setState({travelAuthorityRequestId:'',travelAuthorityDate:localToday(),travelPaperApproved:false,
                travelApprovalReference:'',success:'Travel Authority draft '+saved.number+' prepared for manual signatures; no electronic approval recorded.'});
                await this.loadTravelDocuments();}
        };
        this.refreshTravelRequestSignatories = async event => {
            if(event && typeof event.preventDefault==='function')event.preventDefault();
            // Independent button action; never propagate through the draft form.
            if(event && typeof event.stopPropagation==='function')event.stopPropagation();
            if(this.travelActionPending || this.state.busy || !this.canOperateWorkspace())return;
            const requestId=Number(this.state.travelRefreshSignatoryId);
            if(!requestId)return;
            const record=(this.state.travelDocuments?.requests||[]).find(r=>Number(r.id)===requestId);
            if(!record){this.setState({travelError:'Activity Request is no longer in this list. Refresh the register.'});return;}
            this.travelActionPending='signatory-refresh';
            this.setState({travelError:''});
            try {
                const saved=await this.task('travel.activity.signatories.refresh',{
                    request_id:requestId,expected_version:record.version
                },{refresh:false});
                if(saved){this.setState({travelRefreshSignatoryId:'',travelRefreshSignatoryReason:'',
                    success:'Division signatories updated in an audited Activity Request version. Reprint the form; earlier versions are preserved.'});
                    await this.loadTravelDocuments();}
            } finally {this.travelActionPending='';}
        };
        this.travelView = () => {
            const h=React.createElement,s=this.state,canEdit=this.canOperateWorkspace();
            const employees=(s.staffData?.employees||[]).filter(e=>e.active);
            const funds=s.lists.funds||[];
            const requests=s.travelDocuments?.requests||[], authorities=s.travelDocuments?.authorities||[];
            const already=new Set(authorities.map(x=>String(x.request_id)));
            const stage=['REQUEST','AUTHORITY','CLAIM'].includes(s.travelStage)?s.travelStage:'REQUEST';
            const showRequestForm=!!s.travelEditId||!!s.travelRequestFormOpen||!requests.length;
            const claimCount=(s.travelClaims?.claims||[]).length;
            const stages=[
                {id:'REQUEST',title:'1 · Activity Request',description:'Prepare and print Annex A',count:requests.length},
                {id:'AUTHORITY',title:'2 · Travel Authority',description:'Confirm paper approval, prepare TA',count:authorities.length},
                {id:'CLAIM',title:'3 · Reimbursement',description:'After travel: claims and evidence',count:claimCount}
            ];
            const input=(field,label,type='text',required=true)=>h('label',{className:'field',key:field},
                h('span',null,label),h('input',{type,required,disabled:!canEdit,max:type==='date'?localToday():undefined,
                    min:type==='number'?'0.01':undefined,step:type==='number'?'0.01':undefined,
                    maxLength:type==='text'?255:undefined,value:s.travelRequest[field]||'',
                    onChange:e=>this.setState({travelRequest:{...s.travelRequest,[field]:e.target.value}})}));
            const date=(field,label,planned=false)=>h('label',{className:'field',key:field},h('span',null,label),
                h('input',{required:true,type:'date',max:planned?undefined:localToday(),value:s.travelRequest[field],
                    onChange:e=>this.setState({travelRequest:{...s.travelRequest,[field]:e.target.value}})}),
                field==='request_date'?weekendDocumentNotice(h,s.travelRequest[field],label):null);
            const sel=(field,label,options)=>h('label',{className:'field'},h('span',null,label),
                h('select',{required:true,disabled:!canEdit,value:s.travelRequest[field],onChange:e=>this.setState({travelRequest:{...s.travelRequest,[field]:e.target.value}})},
                    h('option',{value:''},'Select '+label.toLowerCase()),...options));
            const printLink=(type,rec)=>h('a',{href:'api/travel_print.php?organization_id='+encodeURIComponent(s.organization_id)+'&type='+type+'&id='+rec.id,
                target:'_blank',rel:'noopener',className:'print-link'},'Print / Save PDF');
            const wordLink=(type,rec)=>h('a',{href:'api/travel_word.php?organization_id='+encodeURIComponent(s.organization_id)+'&type='+type+'&id='+rec.id,
                className:'print-link',download:'',rel:'noopener'},'Download Word');
            return h('div',{className:'section-stack'},
                this.header('Travel & Training',
                    'Follow the steps below. Prepare the Activity Request, then the linked Travel Authority, then submit actual expenses after travel.'),
                h('nav',{className:'travel-stage-nav',role:'tablist','aria-label':'Travel document workflow'},
                    ...stages.map(item=>h('button',{key:item.id,type:'button',role:'tab',
                        'aria-selected':stage===item.id,'aria-controls':'travel-workflow-panel',
                        className:'travel-stage-tab'+(stage===item.id?' active':''),
                        onClick:()=>this.setState({travelStage:item.id,travelRefreshSignatoryId:'',travelError:'',travelClaimError:''})},
                        h('strong',null,item.title),
                        h('span',null,item.description),
                        h('small',null,item.count+' saved')))),
                h('div',{id:'travel-workflow-panel',role:'tabpanel',className:'travel-stage-panel',
                    'aria-label':stages.find(item=>item.id===stage).title},
                stage==='REQUEST'?h('section',{className:'surface travel-stage-intro'},
                    h('div',null,h('h3',null,'Activity Requests'),
                        h('p',{className:'body-muted'},'Create Annex A, arrange paper approvals, then prepare the linked Travel Authority in Step 2.')),
                    requests.length?h('button',{type:'button',className:showRequestForm?'outline':'primary',
                        disabled:!canEdit||s.busy,onClick:()=>this.setState({travelRequestFormOpen:!showRequestForm,
                            travelEditId:'',travelEditVersion:0,travelEditReason:'',travelRequest:emptyTravelRequest()})},
                        showRequestForm?'Close request form':'New Activity Request'):null):null,
                stage==='REQUEST'&&showRequestForm?h('section',{className:'surface travel-working'},
                    h('h3',null,s.travelEditId?'Revise Activity Request draft':'Prepare Activity Request (Annex A)'),
                    h('p',{className:'body-muted'},'The printed Annex A uses the supplied prescribed margins and indentation, and the four officials configured in Reference Data → Division Travel Signatories. Printed names are not electronic signatures.'),
                    s.travelSignatories && (!s.travelSignatories.version_id || Object.values(s.travelSignatories.roles||{}).some(v=>!v?.full_name))?
                        h('p',{className:'hint',role:'status'},'Division signatories are not fully configured. Ask an authorized Division user to complete all four roles in Reference Data → Division Travel Signatories; older drafts require an audited refresh.'):null,
                    h('p',{className:'body-muted'},'Select which office bears the expense. Division- and Region-chargeable requests remain documentary only and do not charge school MOOE.'),
                    h('button',{type:'button',className:'outline',onClick:()=>this.setState({tab:'Reference Data',masterSection:'travel_signatories'})},'View Division Travel Signatories'),
                    h('form',{onSubmit:this.saveTravelRequest},
                        h('div',{className:'travel-form-grid'},
                            sel('employee_id','Requesting employee',employees.map(e=>h('option',{key:e.employee_id,value:e.employee_id},e.full_name+' · '+(e.designation||e.position_title||'')))),
                            h('label',{className:'field'},h('span',null,'Expenses Chargeable To'),
                                h('select',{required:true,disabled:!canEdit,value:s.travelRequest.chargeable_to||'SCHOOL_MOOE',
                                    onChange:e=>{const c=e.target.value;
                                        this.setState({travelRequest:{...s.travelRequest,chargeable_to:c,
                                            fund_id:['DIVISION_OFFICE','REGIONAL_OFFICE'].includes(c)?'':s.travelRequest.fund_id,
                                            chargeability_reference:c==='SCHOOL_MOOE'?'':s.travelRequest.chargeability_reference}});}},
                                    h('option',{value:'SCHOOL_MOOE'},'School MOOE'),
                                    h('option',{value:'DIVISION_OFFICE'},'Division Office'),
                                    h('option',{value:'REGIONAL_OFFICE'},'Regional Office'),
                                    h('option',{value:'SPLIT'},'Split / Multiple funding offices'))),
                            ['SCHOOL_MOOE','SPLIT'].includes(s.travelRequest.chargeable_to||'SCHOOL_MOOE')?
                                h('label',{className:'field'},h('span',null,(s.travelRequest.chargeable_to==='SPLIT'?'School share — funding source (if applicable)':'School funding source')),
                                    h('select',{required:s.travelRequest.chargeable_to!=='SPLIT',disabled:!canEdit,value:s.travelRequest.fund_id,
                                        onChange:e=>this.setState({travelRequest:{...s.travelRequest,fund_id:e.target.value}})},
                                        h('option',{value:''},s.travelRequest.chargeable_to==='SPLIT'?'No school-paid component':'Select school funding source'),
                                        ...funds.map(f=>h('option',{key:f.id,value:f.id},f.name+' · '+f.source_code)))):null,
                            s.travelRequest.chargeable_to!=='SCHOOL_MOOE'?h('label',{className:'field'},
                                h('span',null,s.travelRequest.chargeable_to==='SPLIT'?'Funding allocation breakdown / reference':'Funding-office approval / reference (optional)'),
                                h('input',{type:'text',required:s.travelRequest.chargeable_to==='SPLIT',disabled:!canEdit,maxLength:255,
                                    placeholder:s.travelRequest.chargeable_to==='SPLIT'?'State each paying office and amount':'e.g. sponsoring memo or office reference',
                                    value:s.travelRequest.chargeability_reference||'',
                                    onChange:e=>this.setState({travelRequest:{...s.travelRequest,chargeability_reference:e.target.value}})})):null,
                            date('request_date','Activity Request date'),
                            input('activity_title','Activity / training title'),
                            input('host','Host / organizing office','text',false),
                            input('destination','Destination / venue'),
                            date('travel_from','Planned travel from',true),date('travel_to','Planned travel to',true),
                            h('p',{className:'hint travel-estimate-notice',role:'note'},'Estimated expenses on Annex A must remain blank. Authorized Division personnel will calculate, handwrite and countersign the amount on the paper Activity Request. The requesting employee cannot enter it in IPDAMS.'),
                            input('itinerary_reference','Itinerary / attachment reference'),
                            input('supporting_reference','Other supporting-document reference','text',false)),
                        h('label',{className:'field'},h('span',null,'Purpose / justification'),
                            h('textarea',{required:true,rows:3,maxLength:600,value:s.travelRequest.purpose,
                                onChange:e=>this.setState({travelRequest:{...s.travelRequest,purpose:e.target.value}})})),
                        s.travelEditId?h('label',{className:'field'},h('span',null,'Reason for correcting the draft'),
                            h('input',{required:true,maxLength:300,value:s.travelEditReason,onChange:e=>this.setState({travelEditReason:e.target.value})})):null,
                        h('div',{className:'travel-actions'},
                            h('button',{type:'submit',className:'primary',disabled:!canEdit||s.busy||!!s.travelRefreshSignatoryId||!employees.length||(s.travelRequest.chargeable_to==='SCHOOL_MOOE'&&!funds.length)},
                                s.busy?'Saving…':s.travelEditId?'Save audited correction':'Save Activity Request Draft'),
                            s.travelEditId?h('button',{type:'button',className:'outline',onClick:()=>this.setState({travelEditId:'',travelEditVersion:0,travelEditReason:'',travelRequestFormOpen:false,travelRequest:emptyTravelRequest()})},'Cancel correction'):null),
                        !employees.length?h('p',{className:'hint'},'Add a current employee in Reference Data → Employees first.'):null)):null,
                stage==='AUTHORITY'?h('section',{className:'surface travel-stage-intro'},
                    h('div',null,h('h3',null,'Prepare Travel Authority'),
                        h('p',{className:'body-muted'},'Choose an Activity Request below. Confirm its signed paper approval before generating an unsigned Travel Authority draft.')),
                    !requests.length?h('button',{type:'button',className:'outline',onClick:()=>this.setState({travelStage:'REQUEST'})},'Go to Activity Request'):null):null,
                (stage==='REQUEST'||stage==='AUTHORITY')?h('section',{className:'surface'},h('div',{className:'travel-head'},h('h3',null,stage==='AUTHORITY'?'Activity Requests awaiting Travel Authority':'Activity Requests'),
                    h('button',{type:'button',className:'outline',onClick:this.loadTravelDocuments,disabled:s.travelLoading},s.travelLoading?'Loading…':'Refresh')),
                    s.travelError?h('p',{className:'error'},s.travelError):null,
                    stage==='AUTHORITY'&&!requests.some(r=>!already.has(String(r.id)))?h('p',{className:'hint'},
                        'No requests are waiting for a Travel Authority. Existing Travel Authorities are listed below; create a new request in Step 1 for another trip.'):null,
                    !requests.length?h('p',{className:'hint'},'No activity requests have been prepared in this workspace.'):null,
                    ...requests.filter(r=>stage!=='AUTHORITY'||!already.has(String(r.id))).map(r=>h('div',{className:'travel-record',key:'AR'+r.id},
                        h('div',null,h('strong',null,r.number+' · '+r.employee),h('p',{className:'hint'},r.activity_title+' · '+(r.chargeable_to||'School MOOE')+' · '+r.travel_from+' to '+r.travel_to+' · Draft / manual signatures required')),
                        h('div',{className:'travel-actions'},printLink('AR',r),wordLink('AR',r),
                            !r.signatories_complete?h('span',{className:'hint'},'Missing signatories in saved draft'):null,
                            stage==='REQUEST'&&!already.has(String(r.id))&&canEdit?h('button',{type:'button',className:'outline',
                                onClick:()=>this.setState({travelRefreshSignatoryId:String(r.id),travelRefreshSignatoryReason:'',travelError:''})},
                                r.signatories_complete?'Update Division Signatories':'Add Division Signatories'):null,
                            stage==='REQUEST'&&!already.has(String(r.id))&&canEdit?h('button',{type:'button',className:'outline',onClick:()=>this.editTravelRequest(r.id)},'Edit draft'):null,
                            !already.has(String(r.id))&&canEdit?h('button',{type:'button',className:'outline',onClick:()=>this.setState({travelStage:'AUTHORITY',travelAuthorityRequestId:String(r.id),travelApprovalReference:'',travelPaperApproved:false,travelAuthorityDate:localToday()})},'Prepare Travel Authority'):null))),
                    stage==='REQUEST'&&s.travelRefreshSignatoryId?h('div',{className:'travel-authority-form',role:'group','aria-label':'Update Division Signatories on Activity Request'},
                        h('h3',null,'Update Division Signatories on Activity Request'),
                        h('p',{className:'hint'},'Only this existing Activity Request will be updated. Save Activity Request Draft is disabled until you save or cancel this correction.'),
                        h('p',{className:'body-muted'},'Use the four Division-approved appointments in a NEW audited draft version. Existing signed copies and linked Travel Authorities are not changed. Configure the appointments in Reference Data first.'),
                        h('div',{className:'travel-actions'},
                            h('button',{type:'button',onClick:this.refreshTravelRequestSignatories,className:'primary',disabled:s.busy||!canEdit},'Save audited signatory update'),
                            h('button',{type:'button',className:'outline',onClick:()=>this.setState({travelRefreshSignatoryId:''})},'Cancel'))):null,
                    stage==='AUTHORITY'&&s.travelAuthorityRequestId?h('form',{onSubmit:this.saveTravelAuthority,className:'travel-authority-form'},
                        h('h3',null,'Prepare linked Travel Authority'),
                        h('p',{className:'body-muted'},'The signed Activity Request must first be checked by the preparer. IPDAMS automatically assigns the next organization-specific Travel Authority control number when the record is prepared (YYYY-MM-NNNN); all signatures are wet signatures.'),
                        h('label',{className:'field'},h('span',null,'Date of Travel Authority preparation'),h('input',{type:'date',required:true,max:localToday(),value:s.travelAuthorityDate,onChange:e=>this.setState({travelAuthorityDate:e.target.value})}),
                            weekendDocumentNotice(h,s.travelAuthorityDate,'Travel Authority preparation date')),
                        h('label',{className:'field'},h('span',null,'Paper approval / signed-copy reference'),h('input',{required:true,maxLength:255,value:s.travelApprovalReference,onChange:e=>this.setState({travelApprovalReference:e.target.value}),placeholder:'Document filing or signed-copy reference'})),
                        h('label',{className:'travel-check'},h('input',{type:'checkbox',required:true,checked:s.travelPaperApproved,onChange:e=>this.setState({travelPaperApproved:e.target.checked})}),
                            'I checked the approved paper Activity Request and understand this does not constitute an electronic approval.'),
                        h('div',{className:'travel-actions'},h('button',{type:'submit',className:'primary',disabled:s.busy||!canEdit},'Prepare unsigned Travel Authority'),h('button',{type:'button',className:'outline',onClick:()=>this.setState({travelAuthorityRequestId:''})},'Cancel'))):null):null,
                stage==='AUTHORITY'?h('section',{className:'surface'},h('h3',null,'Travel Authorities'),
                    !authorities.length?h('p',{className:'hint'},'No linked Travel Authority drafts yet.'):null,
                    ...authorities.map(a=>h('div',{className:'travel-record',key:'TA'+a.id},
                        h('div',null,h('strong',null,a.number+' · '+a.employee),h('p',{className:'hint'},
                            'Linked Request #'+a.request_id+' · '+a.travel_from+' to '+a.travel_to+' · '+
                            (a.travel_to>localToday()?'Upcoming travel; reimbursement comes later':'Travel dates passed; confirm completion and collect signed documents'))),
                        printLink('TA',a),wordLink('TA',a)))):null,
                stage==='CLAIM'?this.travelClaimsView():null,
                stage==='CLAIM'?h('p',{className:'travel-finance-notice',role:'note'},
                    'Claims and evidence checks do not create a DV, certify funds, issue cash advances or pay employees. Accounting review is a separate later step.'):null));
        };
        this.actionCenterView = () => {
            const h=React.createElement,s=this.state;
            const current=s.actionCenterOrg===String(s.organization_id);
            const items=current?s.actionCenterItems:[];
            const checked=current?s.actionCenterChecked:'';
            return h('div',{className:'section-stack ipdams-action-center'},
                this.header('Action Center','Pending work addressed to the currently selected school or Division Office.'),
                h('section',{className:'surface'},
                    h('div',{className:'ipdams-action-top'},
                        h('div',null,h('h2',null,'Incoming property transfers'),
                            h('p',null,'Showing only verified, awaiting-receipt transfers addressed to this office.')),
                        h('button',{type:'button',className:'outline',disabled:s.actionCenterLoading,onClick:this.loadActionCenter},
                            s.actionCenterLoading?'Checking…':'Refresh actions')),
                    h('p',{className:'ipdams-action-summary',role:'status','aria-live':'polite'},
                        s.actionCenterLoading?'Checking pending property transfers…':
                        current&&!s.actionCenterError?s.actionCenterCount+' '+(s.actionCenterCount===1?'transfer':'transfers')+' awaiting receipt':
                        'Pending transfers have not been verified.'),
                    s.actionCenterError?h('p',{className:'ipdams-action-error',role:'alert'},s.actionCenterError):null,
                    !s.actionCenterError&&!s.actionCenterLoading&&items.length===0
                        ?h('div',{className:'ipdams-action-empty'},'No pending incoming property transfers for this office.') : null,
                    ...items.map(item=>h('article',{className:'ipdams-action-row',key:item.id},
                        h('div',{className:'ipdams-action-row-content'},
                            h('strong',null,item.type+' '+item.number),
                            h('small',null,'From '+item.source+' · '+item.items+' '+(item.items===1?'property':'properties')),
                            h('small',null,item.can_accept?'Ready for inspection by your appointed receiving custodian':'Awaiting inspection by the authorized receiving Property Custodian')),
                        h('a',{className:'ipdams-action-open',target:'_blank',rel:'noopener noreferrer',
                            href:'api/property-transfer-intake.php?organization_id='+encodeURIComponent(s.organization_id)+'&accept_id='+encodeURIComponent(item.id)},
                            item.can_accept?'Inspect / Accept':'View pending transfer'))),
                    checked?h('p',{className:'body-muted'},'Last checked: '+checked.replace('T',' ').slice(0,19)+' (Manila time)'):null,
                    h('p',{className:'body-muted'},'Only the named receiving office may accept. A Super Administrator can see outstanding work but cannot accept on behalf of the appointed custodian. Other workflows will be added only after their authorization and status rules are verified.')));
        };
        this.otherView = () => {
            const tab = this.state.tab;
            if (tab === 'Fund Management') return this.masterView('finance');
            if (tab === 'Cash & Bank'){
                try {
                const h=React.createElement;
                const s=this.state;
                const finalized=(Array.isArray(s.lists?.dv)?s.lists.dv:[]).filter(d=>d && d.status==='FINALIZED');
                const chosen=finalized.find(d=>String(d.id)===s.checkDv);
                const supplierBacked=chosen&&chosen.dv_type==='REGULAR'&&Number(chosen.supplier_id)>0;
                const checkPayee=chosen?(supplierBacked?(s.checkPayeeBasis==='PROPRIETOR'?chosen.proprietor_name_snapshot:chosen.business_name_snapshot):chosen.payee):'';
                const alreadyIssued=chosen?(s.lists.checks||[]).filter(c=>String(c.dv_id)===String(chosen.id)&&!['CANCELLED','REPLACED','STOPPED'].includes(c.status)).reduce((acc,c)=>acc+Number(c.amount),0):0;
                const remaining=chosen?Math.max(0,Number(chosen.net)-alreadyIssued):0;
                const banks=Array.isArray(s.lists?.banks)?s.lists.banks:[];
                const registeredChecks=Array.isArray(s.lists?.checks)?s.lists.checks:[];
                const inventory=s.checkStockList||{rows:[],stats:[]};
                const leafRows=Array.isArray(inventory.rows)?inventory.rows:[];
                const stats=Array.isArray(inventory.stats)?inventory.stats:[];
                const stat=n=>Number(stats.find(x=>x.status===n)?.n||0);
                const available=s.checkStockLoaded?stat('AVAILABLE'):'—';
                const leafText=String(s.cashLeafSearch||'').trim().toLowerCase();
                const filteredLeaves=leafRows.filter(x=>(s.cashLeafStatus==='ALL'||x.status===s.cashLeafStatus)&&(!leafText||[x.serial,x.bank_name,x.book_reference].some(v=>String(v??'').toLowerCase().includes(leafText))));
                const checkText=String(s.cashCheckSearch||'').trim().toLowerCase();
                const filteredChecks=registeredChecks.filter(x=>(s.cashCheckStatus==='ALL'||x.status===s.cashCheckStatus)&&(!checkText||[x.check_number,x.dv_number,x.payee_name,x.status].some(v=>String(v??'').toLowerCase().includes(checkText))));
                const current=s.cashBankSection||'prepare';
                const tabs=[['prepare','Prepare Check'],['inventory','Checkbook Inventory'],['register','Check Register'],['banks','Bank Accounts']];
                const tabButton=([key,label])=>h('button',{key,id:'cb-tab-'+key,type:'button',role:'tab',
                    className:'cb-tab'+(key===current?' is-active':''),'aria-controls':'cb-panel-'+key,
                    'aria-selected':key===current,tabIndex:key===current?0:-1,
                    onClick:()=>this.setState({cashBankSection:key}),
                    onKeyDown:e=>{
                        const index=tabs.findIndex(x=>x[0]===key);
                        const next=e.key==='ArrowRight'?tabs[(index+1)%tabs.length][0]:
                            e.key==='ArrowLeft'?tabs[(index+tabs.length-1)%tabs.length][0]:
                            e.key==='Home'?tabs[0][0]:e.key==='End'?tabs[tabs.length-1][0]:null;
                        if(next){e.preventDefault();this.setState({cashBankSection:next},()=>document.getElementById('cb-tab-'+next)?.focus());}
                    }},label);
                const prepareCard=()=>React.createElement('div',{className:'surface'},
                        React.createElement('h3',null,'Prepare check from finalized DV'),
                        React.createElement('form',{onSubmit:this.createGuidedCheck},
                            React.createElement('div',{className:'form-grid'},
                                React.createElement('label',{className:'field'},React.createElement('span',null,'Finalized DV *'),
                                    React.createElement('select',{required:true,value:this.state.checkDv,onChange:e=>{
                                        const dv=finalized.find(x=>String(x.id)===e.target.value);
                                        const prior=dv?(this.state.lists.checks||[]).filter(c=>String(c.dv_id)===String(dv.id)&&!['CANCELLED','REPLACED','STOPPED'].includes(c.status)).reduce((a,c)=>a+Number(c.amount),0):0;
                                        this.setState({checkDv:e.target.value,checkAmount:dv?Math.max(0,Number(dv.net)-prior).toFixed(2):'',checkPayeeBasis:'BUSINESS'});
                                    }},React.createElement('option',{value:''},'Select finalized DV'),...finalized.map(d=>React.createElement('option',{key:d.id,value:d.id},(d.display_number||'#'+d.id)+' · '+d.payee+' · net ₱'+d.net)))),
                                React.createElement('label',{className:'field'},React.createElement('span',null,'School bank account *'),
                                    React.createElement('select',{required:true,value:this.state.checkBank,onChange:e=>{const bank=e.target.value;this.setState({checkBank:bank,checkStockPreview:null,checkNo:'',checkLeafConfirmed:false},()=>this.fetchNextPhysicalCheck(bank));}},React.createElement('option',{value:''},'Choose registered bank'),...(this.state.lists.banks||[]).map(b=>React.createElement('option',{key:b.id,value:b.id},b.bank_name+' / '+b.account_label)))),
                                React.createElement('label',{className:'field'},React.createElement('span',null,'Check number *'),
                                    React.createElement('input',{required:true,maxLength:40,readOnly:true,value:this.state.checkNo,placeholder:'Register verified physical stock first'})),
                                React.createElement('label',{className:'field'},React.createElement('span',null,'Check amount (₱) *'),
                                    React.createElement('input',{required:true,type:'number',min:'0.01',step:'0.01',max:remaining||undefined,value:this.state.checkAmount,onChange:this.set('checkAmount')}))),
                            chosen?React.createElement('div',{className:'hint'},'DV net ₱'+chosen.net+' · Existing active checks ₱'+alreadyIssued.toFixed(2)+' · Remaining ₱'+remaining.toFixed(2)):null,
                            React.createElement('p',{className:'hint'},this.state.checkStockPreview?.check_number?'Verified next paper leaf '+this.state.checkStockPreview.check_number+' from '+this.state.checkStockPreview.book_reference+' — not issued until Confirm Issue Check.':this.state.checkStockPreview?.message||'Select school bank to look up the next physical paper serial.'),
                            React.createElement('label',{className:'field cb-checkbox-field'},React.createElement('input',{type:'checkbox',checked:this.state.checkLeafConfirmed,disabled:!this.state.checkStockPreview?.leaf_id,onChange:e=>this.setState({checkLeafConfirmed:e.target.checked})}),' I compared the serial with the actual blank physical leaf and confirmed it is intact and unused.'),
                            supplierBacked?React.createElement('fieldset',{className:'surface'},React.createElement('legend',null,'Check payee name'),
                                React.createElement('label',null,React.createElement('input',{type:'radio',name:'check-payee-choice',checked:this.state.checkPayeeBasis!=='PROPRIETOR',onChange:()=>this.setState({checkPayeeBasis:'BUSINESS'})}),' Business — '+(chosen.business_name_snapshot||'Not recorded')),
                                React.createElement('label',null,React.createElement('input',{type:'radio',name:'check-payee-choice',disabled:!chosen.proprietor_name_snapshot,checked:this.state.checkPayeeBasis==='PROPRIETOR',onChange:()=>this.setState({checkPayeeBasis:'PROPRIETOR'})}),' Proprietor — '+(chosen.proprietor_name_snapshot||'Not recorded'))):null,
                            chosen?React.createElement('div',{className:'hint'},'Check preview · Pay to the order of: '+(checkPayee||'Select verified payee')+' · ₱'+(this.state.checkAmount||'0.00')):null,
                            React.createElement('p',{className:'hint'},'Check payee is frozen on issuance and copied to replacements. Actual check stationery printing and verified bank cash sufficiency are separate pending features.'),
                            React.createElement('button',{type:'submit',className:'primary',disabled:this.state.busy||!chosen||remaining<=0||!this.state.checkStockPreview?.leaf_id||!this.state.checkLeafConfirmed},'Confirm issue check')));
                const inventoryCard=()=>React.createElement('div',{className:'surface'},
                        React.createElement('h3',null,'Physical checkbook inventory'),
                        React.createElement('p',{className:'hint'},'Only register consecutive serials physically present and verified completely blank, intact, unsigned and never issued. Include leading zeroes. Limit 200 leaves per batch.'),
                        React.createElement('form',{onSubmit:this.registerPhysicalCheckStock},
                            React.createElement('div',{className:'form-grid'},
                                React.createElement('label',{className:'field'},React.createElement('span',null,'School bank *'),React.createElement('select',{required:true,value:this.state.checkbookBank,onChange:this.set('checkbookBank')},React.createElement('option',{value:''},'Select bank'),...(this.state.lists.banks||[]).map(b=>React.createElement('option',{key:b.id,value:String(b.id)},b.bank_name+' / '+b.account_label)))),
                                React.createElement('label',{className:'field'},React.createElement('span',null,'Checkbook reference *'),React.createElement('input',{required:true,maxLength:100,value:this.state.checkbookReference,onChange:this.set('checkbookReference'),placeholder:'Physical book reference'})),
                                React.createElement('label',{className:'field'},React.createElement('span',null,'First unused serial *'),React.createElement('input',{required:true,inputMode:'numeric',pattern:'[0-9]+',maxLength:24,value:this.state.checkbookFirst,onChange:this.set('checkbookFirst'),placeholder:'Blank paper serial'})),
                                React.createElement('label',{className:'field'},React.createElement('span',null,'Last unused serial *'),React.createElement('input',{required:true,inputMode:'numeric',pattern:'[0-9]+',maxLength:24,value:this.state.checkbookLast,onChange:this.set('checkbookLast'),placeholder:'Last blank paper serial'}))),
                            React.createElement('label',{className:'field cb-checkbox-field'},React.createElement('input',{type:'checkbox',checked:this.state.checkbookVerified,onChange:e=>this.setState({checkbookVerified:e.target.checked})}),' I physically checked EVERY numbered leaf is unused, blank and intact.'),
                            React.createElement('button',{className:'outline',type:'submit',disabled:this.state.busy||!this.state.checkbookVerified},'Register verified unused leaves')),
                        React.createElement('button',{type:'button',className:'outline',onClick:this.loadPhysicalCheckStock},'Refresh stock register'),
                        React.createElement('div',{className:'hint'},(this.state.checkStockList.stats||[]).map(x=>x.status+': '+x.n).join(' · ')||'No verified physical check stock loaded.'),
                        h('div',{className:'cb-filters'},
                            h('label',{className:'field'},h('span',null,'Find a physical serial'),h('input',{type:'search',value:this.state.cashLeafSearch,placeholder:'Serial, bank, or book reference',onChange:e=>this.setState({cashLeafSearch:e.target.value})})),
                            h('label',{className:'field'},h('span',null,'Leaf status'),h('select',{value:this.state.cashLeafStatus,onChange:e=>this.setState({cashLeafStatus:e.target.value})},...['ALL','AVAILABLE','ISSUED','SPOILED','LOST','VOID'].map(v=>h('option',{key:v,value:v},v==='ALL'?'All statuses':v))))),
                        this.table('Physical serial ledger',['bank_name','serial','book_reference','status','issued_check_id'],filteredLeaves),
                        ...filteredLeaves.filter(x=>x.status==='AVAILABLE').slice(0,5).map(x=>React.createElement('button',{key:x.id,type:'button',className:'outline',onClick:()=>this.markPhysicalCheckUnusable(x)},'Report unusable blank leaf '+x.serial)),
                        ...filteredLeaves.filter(x=>['SPOILED','VOID'].includes(x.status)&&!x.issued_check_id).slice(0,5).map(x=>
                            React.createElement('button',{key:'correct-'+x.id,type:'button',className:'outline',
                                onClick:()=>this.correctUnissuedCheckLeaf(x)},'Correct unused physical serial '+x.serial+' to AVAILABLE')));
                const registerCard=()=>h('section',{className:'surface cb-panel-surface'},
                    h('div',{className:'cb-panel-header'},h('div',null,h('h3',null,'Issued check register'),h('p',{className:'hint'},'Review the check numbers and their linked DV records. Cancelled and replaced records remain accountable.'))),
                    h('div',{className:'cb-filters'},
                        h('label',{className:'field'},h('span',null,'Search checks'),h('input',{type:'search',value:s.cashCheckSearch,onChange:e=>this.setState({cashCheckSearch:e.target.value}),placeholder:'Check no., DV no., or payee'})),
                        h('label',{className:'field'},h('span',null,'Status'),h('select',{value:s.cashCheckStatus,onChange:e=>this.setState({cashCheckStatus:e.target.value})},
                            ...['ALL',...new Set(registeredChecks.map(x=>x.status).filter(Boolean))].map(v=>h('option',{key:v,value:v},v==='ALL'?'All statuses':v))))),
                    registeredChecks.length
                        ?(filteredChecks.length?this.table('Check register',['check_number','dv_number','payee_name','payee_basis','amount','status'],filteredChecks):
                            h('p',{className:'cb-empty'},'No checks match these filters. Try another check number, payee, or status.'))
                        :h('div',{className:'cb-empty'},'No issued check records yet. Finalize a DV and prepare its check in the Prepare Check tab.',
                            h('button',{type:'button',className:'outline',onClick:()=>this.setState({cashBankSection:'prepare'})},'Go to Prepare Check')),
                    h('p',{className:'hint'},'Search and status filters affect only this view; they do not change issued check records.'));
                const bankCard=()=>h('section',{className:'surface cb-panel-surface'},
                    h('div',{className:'cb-panel-header'},h('div',null,h('h3',null,'Bank account directory'),h('p',{className:'hint'},'School bank accounts are maintained through Fund Management.'))),
                    banks.length?this.table('Bank account directory',['bank_name','account_label','masked_account'],banks):
                        h('p',{className:'cb-empty'},'No school bank accounts are registered. Use Fund Management to register the bank account.'),
                    h('p',{className:'hint'},'Bank-cash sufficiency and statement reconciliation require a separate verified bank transaction ledger.'),
                    h('button',{type:'button',className:'outline',onClick:()=>this.setState({tab:'Fund Management',fundSection:'banks',masterEditorOpen:false})},'Manage bank accounts'));
                const summaryItem=(label,value,note)=>h('div',{className:'cb-stat'},h('span',{className:'cb-stat-label'},label),h('strong',{className:'cb-stat-value'},value),h('small',null,note));
                return h('div',{className:'section-stack cb-page'},
                    this.header('Cash & bank','Prepare checks, verify physical check leaves, and review bank accountability.'),
                    h('div',{className:'cb-summary','aria-label':'Cash and bank overview'},
                        summaryItem('Blank checks available',s.checkStockLoading?'Loading…':available,s.checkStockError?'Stock unavailable':'Verified unused leaves'),
                        summaryItem('Check records',registeredChecks.length,'Includes all listed check statuses'),
                        summaryItem('Registered banks',banks.length,'Accounts in this workspace'),
                        summaryItem('Next physical serial',s.checkBank?(s.checkStockPreview?.check_number||'—'):'Select bank',s.checkBank?'Provisional · not issued':'Choose bank in Prepare Check')),
                    s.checkStockError?h('p',{className:'hint',role:'alert'},'Check stock could not be loaded: '+s.checkStockError):null,
                    h('nav',{className:'cb-tabs',role:'tablist','aria-label':'Cash and bank functions'},...tabs.map(tabButton)),
                    h('section',{id:'cb-panel-'+current,role:'tabpanel','aria-labelledby':'cb-tab-'+current,className:'cb-active-panel'},
                        current==='prepare'?h('div',{className:'cb-prepare-group'},
                            s.checkStockLoaded&&available===0?h('div',{className:'cb-empty cb-action-note'},'No verified blank check leaves are available. Register an actual unused checkbook before issuing a check. ',
                                h('button',{type:'button',className:'outline',onClick:()=>this.setState({cashBankSection:'inventory'})},'Open Checkbook Inventory')):null,
                            prepareCard(),
                            chosen&&window.IpdamsCheckProof?h(window.IpdamsCheckProof,{
                                organizationId:Number(s.organization_id),dv:chosen,
                                checkNo:s.checkNo,amount:s.checkAmount,
                                payee:checkPayee||'',bank:banks.find(x=>String(x.id)===String(s.checkBank)),
                                mode:'UNISSUED',get:(route,body)=>this.get(route,body),
                                task:(route,body)=>this.task(route,body,{refresh:false})}):null)
                            :current==='inventory'?inventoryCard():current==='register'?registerCard():bankCard(),
                        current==='register'?h('div',{className:'cb-proof-register-list'},
                            h('h3',null,'Check printing proofs'),
                            h('p',{className:'hint'},'Review the frozen payee and amount for each issued record before the eventual official check print operation.'),
                            ...registeredChecks.slice(0,25).map(c=>h('button',{key:'proof-'+c.id,type:'button',className:'outline',
                                onClick:()=>this.setState({checkProofIssued:c})},'Preview #'+c.check_number))):null,
                        s.checkProofIssued&&window.IpdamsCheckProof?h(window.IpdamsCheckProof,{
                            organizationId:Number(s.organization_id),
                            dv:(s.lists.dv||[]).find(d=>String(d.id)===String(s.checkProofIssued.dv_id))||null,
                            checkNo:s.checkProofIssued.check_number,amount:s.checkProofIssued.amount,
                            payee:s.checkProofIssued.payee_name||'',mode:'ISSUED',
                            close:()=>this.setState({checkProofIssued:null}),
                            get:(route,body)=>this.get(route,body),task:(route,body)=>this.task(route,body,{refresh:false})}):null));
                } catch (displayError) {
                    console.error('Cash & Bank display error (no check or bank mutation)',displayError);
                    return React.createElement('div',{className:'section-stack'},
                        this.header('Cash & bank','Check records remain unchanged. The interface could not display the selected section.'),
                        React.createElement('section',{className:'surface',role:'alert'},
                            React.createElement('h3',null,'Cash & Bank display needs to be refreshed'),
                            React.createElement('p',null,'No check was issued or modified by this display error. Try refreshing data or reload the page.'),
                            React.createElement('button',{type:'button',className:'outline',onClick:()=>this.refresh()},'Refresh data'),
                            React.createElement('button',{type:'button',className:'outline',onClick:()=>this.setState({cashBankSection:'prepare',checkStockList:{rows:[],stats:[]},checkStockLoaded:false},()=>this.loadPhysicalCheckStock())},'Reset Cash & Bank view')));
                }
            }
            if (tab === 'Division Consolidation') return this.divisionConsolidationView();
            if (tab === 'Textbooks') return schoolScopedDivision(this.state)
                ? React.createElement('div',{className:'section-stack'},
                    this.header('Textbook Records · read-only Special Function','Only an active school-scoped VIEW grant can display the bounded textbook register; movements are not permitted.'),
                    this.state.divisionModuleGrantsBusy?React.createElement('p',{role:'status'},'Checking current textbook permission…'):null,
                    this.divisionContextPanel('TEXTBOOK'))
                : this.textbookView();
            if (tab === 'Reports')
                return React.createElement("div", { className: "section-stack" },
                    this.header('Reports & global search', 'Find finalized and cancelled documents in your authorized school.'),
                    React.createElement('div',{className:'surface'},
                        React.createElement('h3',null,'Monthly document issuances'),
                        React.createElement('p',{className:'body-muted'},'Print an organization-scoped monthly register of issued control numbers for PR through ICS/PAR, plus Activity Requests and Travel Authorities. Paper signatures and approvals remain outside IPDAMS.'),
                        this.state.organization_id
                            ?React.createElement('a',{className:'outline',href:'api/monthly_issuances.php?organization_id='+encodeURIComponent(this.state.organization_id)},'Open monthly issuance report ↗')
                            :React.createElement('p',{className:'hint'},'Select an organization first.'),
                        React.createElement('p',{className:'hint'},'For historical transaction details, use the separate month/year transaction register.'),
                        this.state.organization_id && this.state.user?.role!=='VIEWER'
                            ?React.createElement('a',{className:'outline',href:'api/semi_expendable_reports.php?organization_id='+encodeURIComponent(this.state.organization_id),target:'_blank',rel:'noopener noreferrer'},'Open Semi-Expendable COA Report Center ↗')
                            :null,
                        React.createElement('a',{className:'print-link',href:'api/transactions.php'},'Browse Month / Year transaction registers ↗')),
                    React.createElement("div", { className: "surface" },
                        React.createElement("form", { className: "search-form", onSubmit: this.search },
                            this.field('searchTerm', 'Search printed document number', '2026-10-0001'),
                            this.act('Find document', this.search))),
                    this.table('Search matches', ['type', 'display_number', 'status'], this.state.searchResults));
            if (tab === 'Reference Data') return React.createElement('div',{className:'reference-workspace section-stack'},this.referenceNavigation(), React.createElement('div',{className:'reference-current'},this.masterView()));
            if (tab === 'Organization') return this.organizationView();
            if (tab === 'Settings')
                return React.createElement("div", { className: "section-stack" },
                    this.header('System Settings', 'Release updates and controlled SQL access for the technical super administrator.'),
                    React.createElement("div", { className: "surface" },
                        React.createElement("h3", null, "Technical settings"),
                        React.createElement("p", { className: "body-muted" }, "Settings now keeps the current IPDAMS sidebar. Protected updater, database diagnostics, SQL review, reset and branding tools continue to run in the separate Super Administrator-only technical console."),
                        this.state.user?.role === 'SUPER_ADMIN'
                            ? React.createElement("div", { className: "inline-actions", style: { flexWrap: 'wrap', gap: '8px' } },
                                ...[
                                    ['System Updates', 'updates'],
                                    ['Database Health', 'health'],
                                    ['SQL Query', 'sql'],
                                    ['Data Reset', 'reset'],
                                    ['System Branding', 'branding']
                                ].map(([label, section]) => React.createElement("a", {
                                    key: section, className: 'outline',
                                    href: 'settings.php?tab=' + encodeURIComponent(section),
                                    target: '_blank', rel: 'noopener noreferrer',
                                    'aria-label': label + ' (opens secure technical console in a new tab)'
                                }, label)))
                            : React.createElement("p", null, "Super Administrator access required.")));
            return React.createElement("div", { className: "section-stack" },
                this.header('Administration', 'System settings, auditable document control, and implementation notes.'),
                React.createElement("div", { className: "surface" },
                    React.createElement("h3", null, "Operational safeguards"),
                    React.createElement("p", { className: "body-muted" }, "Manual signatures only; no electronic approval. No free-form SQL console for school administrators. School scoping enforced by API. Production backup, restore drill, official templates, and policy review remain owner responsibilities.")),
                this.state.user?.role==='SUPER_ADMIN' ? React.createElement('details',{className:'workflow-technical'},React.createElement('summary',null,'Technical API workbench — Super Administrator only'),this.advanced()) : null);
        };
    }
    componentDidMount() {
        this.actionVisibilityRefresh=()=>{
            if(!document.hidden && this.state.sessionStatus==='ready')this.loadActionCenter();
        };
        document.addEventListener('visibilitychange',this.actionVisibilityRefresh);
        // Lightweight status refresh for an open authenticated workspace, not a write.
        this.actionTimer=window.setInterval(()=>{
            if(!document.hidden && this.state.sessionStatus==='ready')this.loadActionCenter();
        },120000);
        this.session();
    }
    render() {
        // Auth starts in an unknown state. A navigation from Settings performs a
        // full page load; rendering the login form before auth.session resolves
        // makes a valid session look briefly signed out.
        if (this.state.sessionStatus === 'checking')
            return React.createElement('div', { className: 'setup-screen' },
                React.createElement('div', { className: 'setup-shell', role: 'status', 'aria-live': 'polite', 'aria-busy': true },
                    React.createElement('span', { className: 'eyebrow' }, 'IPDAMS · SECURE WORKSPACE'),
                    React.createElement('h1', null, 'Restoring your workspace…'),
                    React.createElement('p', { className: 'body-muted' }, 'Checking your session and opening the selected section.')));
        if (this.state.sessionStatus === 'error')
            return React.createElement('div', { className: 'setup-screen' },
                React.createElement('div', { className: 'setup-shell', role: 'alert' },
                    React.createElement('span', { className: 'eyebrow' }, 'IPDAMS · SESSION CHECK'),
                    React.createElement('h1', null, 'Unable to restore your workspace'),
                    React.createElement('p', { className: 'body-muted' }, this.state.sessionError || 'Session verification is temporarily unavailable.'),
                    React.createElement('button', { type: 'button', className: 'primary', onClick: () => this.setState({ sessionStatus: 'checking', sessionError: '', error: '' }, () => this.session()) }, 'Try again')));
        if (!this.state.user)
            return React.createElement("div", { className: "login-screen ipdams-login-v135" },
                React.createElement("section", { className: "login-side", "aria-label": "IPDAMS platform introduction" },
                    React.createElement("div", { className: "ipdams-login-banner" },
                        React.createElement("img", { src: "api/branding.php?kind=header", alt: "IPDAMS — Integrated Procurement, Disbursement and Asset Management System, by Loy" })),
                    React.createElement("div", { className: "ipdams-login-story" },
                        React.createElement("h1", null, "Integrated records.", React.createElement("br", null), "Accountable decisions."),
                        React.createElement("p", null, "Procurement, disbursements, inventory and property in one school-scoped workspace."),
                        React.createElement("div", { className: "ipdams-login-features", "aria-label": "System areas" },
                            [["▣","Procurement"],["₱","Disbursement"],["▥","Inventory"],["⌂","Property"]].map(([mark,label]) =>
                                React.createElement("div", { className: "ipdams-login-feature", key: label },
                                    React.createElement("span", { className: "ipdams-feature-mark", "aria-hidden": "true" }, mark),
                                    React.createElement("strong", null, label))))),
                    React.createElement("div", { className: "login-footer" }, "IPDAMS · Integrated Procurement, Disbursement and Asset Management System")),
                React.createElement("div", { className: "login-main" },
                    React.createElement("form", { className: "login-card", onSubmit: this.login },
                        React.createElement("div", { className: "ipdams-login-card-brand" },
                            React.createElement("img", { className: "ipdams-login-card-icon", src: "api/branding.php?kind=icon", alt: "" }),
                            React.createElement("img", { className: "ipdams-login-card-header", src: "api/branding.php?kind=header", alt: "IPDAMS — Integrated Procurement, Disbursement and Asset Management System" })),
                        React.createElement("h2", null, "Welcome back"),
                        React.createElement("p", null, "Sign in with your authorized IPDAMS account."),
                        React.createElement("label", { className: "field" },
                            React.createElement("span", null, "Email address"),
                            React.createElement("input", { type: "email", name: "loginEmail", autoComplete: "username", value: this.state.loginEmail, placeholder: "name@school.gov.ph", required: true, onChange: this.set('loginEmail') })),
                        React.createElement("label", { className: "field" },
                            React.createElement("span", null, "Password"),
                            React.createElement("div", { className: "ipdams-password-wrap" },
                                React.createElement("input", { type: this.state.loginPasswordVisible ? "text" : "password", name: "loginPassword", autoComplete: "current-password", value: this.state.loginPassword, required: true, onChange: this.set('loginPassword') }),
                                React.createElement("button", { type: "button", className: "ipdams-password-visibility", "aria-label": this.state.loginPasswordVisible ? "Hide password" : "Show password", "aria-pressed": this.state.loginPasswordVisible, onClick: () => this.setState({ loginPasswordVisible: !this.state.loginPasswordVisible }) },
                                    this.state.loginPasswordVisible ? "Hide" : "Show"))),
                        this.state.error ? React.createElement("div", { className: "error", role: "alert" }, this.state.error) : null,
                        React.createElement("button", { className: "primary full", disabled: this.state.busy, type: "submit" }, "Sign in securely →"),
                        React.createElement("small", null, "Accounts are provisioned by authorized administrators. No public registration."))));
        if (!this.state.orgs.length) {
            const isAdmin=this.state.user?.role==='SUPER_ADMIN';
            return React.createElement('div',{className:'setup-screen'},
                React.createElement('div',{className:'setup-shell'},
                    React.createElement('span',{className:'eyebrow'},'IPDAMS · INITIAL ORGANIZATION SETUP'),
                    React.createElement('h1',null,isAdmin?'Set up your Division Office':'Organization access required'),
                    React.createElement('p',{className:'body-muted'},isAdmin
                        ? 'IPDAMS starts with the Division Office. As Super Administrator, you can register schools and manage Division or School workspaces without changing accounts. No sample school or financial balances will be created.'
                        : 'Your account has no authorized school or Division Office. Contact your Super Administrator to grant access.'),
                    this.state.error?React.createElement('div',{className:'error',role:'alert'},this.state.error):null,
                    isAdmin?React.createElement('form',{onSubmit:this.createInitialOrganization,className:'setup-form'},
                        React.createElement('p',{className:'body-muted'},'Organization type: Division Office (required)'),
                        React.createElement('label',{className:'field'},React.createElement('span',null,'Division Office name *'),
                            React.createElement('input',{required:true,maxLength:200,value:this.state.setup_name,onChange:this.set('setup_name'),placeholder:'Full official organization name'})),
                        React.createElement('label',{className:'field'},React.createElement('span',null,'Organization code *'),
                            React.createElement('input',{required:true,maxLength:24,minLength:2,pattern:'[A-Za-z0-9_-]{2,24}',value:this.state.setup_code,onChange:this.set('setup_code'),placeholder:'Division Office code'})),
                        React.createElement('p',{className:'body-muted'},'The code identifies the organization internally. Use its existing official code if available. You can add fund sources afterward under Reference Data.'),
                        React.createElement('button',{type:'submit',disabled:this.state.busy,className:'primary full'},this.state.busy?'Creating…':'Create organization and continue')):null,
                    React.createElement('button',{type:'button',className:'setup-signout',onClick:this.logout},'Sign out')));
        }
        const activeWorkspace = this.state.orgs.find(o => String(o.id) === String(this.state.organization_id));
        return React.createElement("div", { className: "shell" },
            React.createElement("aside", { className: "sidebar" },
                React.createElement("div", { className: "brand" },
                    React.createElement("div", { className: "brand-square" },
                        React.createElement("img", { className: "ipdams-brand-icon", src: "api/branding.php?kind=icon", alt: "" })),
                    React.createElement("div", null,
                        React.createElement("strong", null, "IPDAMS"),
                        React.createElement("small", null, "OPERATIONS PLATFORM"))),
                React.createElement("div", { className: "side-label" }, "WORKSPACE"),
                React.createElement("nav", null, (this.state.user?.role==='VIEWER'
                    ? nav.filter(([name])=>!['Administration','Settings','Division Consolidation','Action Center'].includes(name)) :
                    nav.filter(([name])=>(name!=='Textbooks'||!schoolScopedDivision(this.state)||divisionLiveSpecial(this.state,'TEXTBOOK')) &&
                        (name!=='Division Consolidation'||(['SUPER_ADMIN','DIVISION'].includes(this.state.user?.role)&&
                        this.state.orgs.some(o=>String(o.id)===String(this.state.organization_id)&&o.kind==='DIVISION')))))
                    .reduce((children, [name, symbol, group], navIndex) => {
                    if(navIndex===0||!children.some(entry=>entry?.key==='group-'+group))children.push(React.createElement('div',{key:'group-'+group,className:'nav-group-label'},group));
                    // v0.2.206: one canonical protected Settings screen, not a
                    // second React landing page with links to another layout.
                    // The Settings console owns its PHP auth/CSRF/form handling.
                    if (name === 'Settings' && ['SUPER_ADMIN','SCHOOL'].includes(this.state.user?.role)) {
                        children.push(React.createElement('a', {
                            key: name, className: 'navitem',
                            href: this.state.user?.role === 'SCHOOL' ? 'settings.php?tab=numbering' : 'settings.php?tab=updates',
                            style: { textDecoration: 'none' },
                            'aria-label': this.state.user?.role === 'SCHOOL' ? 'School Numbering' : 'Settings'
                        }, React.createElement('span', null, symbol), this.state.user?.role === 'SCHOOL' ? 'School Numbering' : name));
                        return children;
                    }
                    children.push(React.createElement("button", { key: name, className: 'navitem ' + (name === this.state.tab ? 'active' : ''), onClick: () => (
                    // Ignore late results after leaving Disbursements.
                    name !== 'Disbursements' && (this.eligibleRequest = (this.eligibleRequest || 0) + 1),
                    this.setState({ tab: name, error: '', success: '', details: null, atc_dialog: '', atc_action: '', atc_selected_code: '',
                        ...(name === 'Disbursements' ? {} : { eligibleLoading: false, eligibleError: '', eligibleLoadedFor: '' }) }, () => {
                        if (name === 'Organization') this.loadHierarchy();
                        if (name === 'Procurement') this.workflowReload();
                        if (name === 'Action Center') this.loadActionCenter();
                        if (name === 'Disbursements' && this.state.user?.role!=='VIEWER') {this.loadEligible(this.state.dv_type);this.loadFundPolicySettings();}
                    })) },
                    React.createElement("span", null, symbol),
                    name === 'Action Center'
                        ? React.createElement('span',{className:'ipdams-action-nav-label'},
                            React.createElement('span',{className:'ipdams-action-title'},name),
                            this.state.actionCenterOrg===String(this.state.organization_id)&&!this.state.actionCenterError&&this.state.actionCenterCount>0
                                ?React.createElement('span',{className:'ipdams-action-count',
                                    'aria-label':this.state.actionCenterCount+' pending actions'},
                                    this.state.actionCenterCount>99?'99+':this.state.actionCenterCount):null)
                        : name,
                    name === this.state.tab ? React.createElement("b", null, "\u203A") : null));
                    return children;
                }, [])),
                React.createElement("div", { className: "sidebar-foot" },
                    React.createElement("div", { className: "avatar" }, (this.state.user.name || '?').slice(0, 1).toUpperCase()),
                    React.createElement("div", { className: "account" },
                        React.createElement("strong", null, this.state.user.name),
                        React.createElement("small", null, this.state.user.role.replace('_', ' '))),
                    React.createElement("button", { "aria-label": "Sign out", title: "Sign out", className: "logout", onClick: this.logout }, "\u21AA"))),
            React.createElement("main", null,
                React.createElement("div", { className: "topbar" },
                    React.createElement("div", { className: "mobile-brand" }, "IPDAMS"),
                    React.createElement("div", { className: "ipdams-header-logo", "aria-label": "IPDAMS application branding" }, React.createElement("img", { src: "api/branding.php?kind=header", alt: "IPDAMS — by Loy" })),
                    React.createElement("div", { className: "topbar-school" },
                        React.createElement("span", { className: "muted" }, this.state.user?.role === "SUPER_ADMIN" ? "Manage workspace" : "Viewing"),
                        React.createElement("select", { "aria-label": "Active organization", value: this.state.organization_id, onChange: this.orgChange, disabled: this.state.busy || this.state.workspaceLoading }, this.state.orgs.map((o) => React.createElement("option", { key: o.id, value: o.id }, (o.kind === 'DIVISION' ? 'Division: ' : 'School: ') + o.name)))),
                    React.createElement("div", { className: "topbar-right" },
                        React.createElement("span", { className: "secure" }, "\u25CF " + (this.state.user?.role === "SUPER_ADMIN" ? "Super Admin · original account · all scopes" : "Authorized organization scope")),
                        React.createElement("div", { className: "mini-avatar" }, (this.state.user.name || '?').slice(0, 1).toUpperCase()))),
                React.createElement("div", { className: "main-body" },
                    this.state.user?.role==='VIEWER'?React.createElement('div',{
                        role:'status',className:'notice',style:{padding:'10px 14px',border:'1px solid #cbd5e1',marginBottom:'10px'}},
                        'DIVISION MOOE · READ ONLY — Switch among your assigned school workspaces. You may inspect and print existing documents; transactions and changes are disabled.'):null,
                    activeWorkspace ? React.createElement('div', { className: 'workspace-context workspace-context--'+(activeWorkspace.kind==='DIVISION'?'division':'school'), role: 'status', 'aria-live': 'polite' },
                        React.createElement('span', { className: 'workspace-context-label' }, this.state.workspaceLoading ? 'Switching workspace…' : 'ACTIVE ORGANIZATION — ALL NEW DOCUMENTS BELONG HERE'),
                        React.createElement('strong', null, activeWorkspace.name),
                        React.createElement('span', { className: 'workspace-context-kind' }, activeWorkspace.kind === 'DIVISION' ? 'DIVISION OFFICE' : 'SCHOOL'),
                        React.createElement('span',{className:'workspace-context-roles'},this.canOperateWorkspace()?'Independent procurement and DV workflow':'Read-only organizational oversight · No approval of school DVs')) : null,
                    this.state.error ? React.createElement("div", { className: "error", role: "alert" },
                        this.state.error,
                        React.createElement("button", { onClick: () => this.setState({ error: '' }) }, "\u00D7")) : null,
                    (() => {
                        const affected=(this.state.workspaceFailures||[]).filter(name =>
                            (this.state.tab==='Reference Data' && (
                                (this.state.masterSection==='atcs' && ['atcs','atc_rates'].includes(name)) ||
                                (this.state.masterSection==='classifications' && name==='division classifications') ||
                                (this.state.masterSection==='items' && name==='division classifications') ||
                                (this.state.masterSection==='suppliers' && name==='atcs'))) ||
                            (this.state.tab==='Disbursements' && name==='atc_rates'));
                        return affected.length ? React.createElement('div',{className:'error',role:'alert'},
                            'Some reference data could not refresh ('+affected.join(', ')+'). Displayed values may be outdated. ',
                            React.createElement('button',{type:'button',onClick:()=>this.refresh(),disabled:this.state.workspaceLoading},'Retry loading')) : null;
                    })(),
                    this.state.success ? React.createElement("div", { className: "success", role: "status" },
                        "\u2713 ",
                        this.state.success,
                        React.createElement("button", { onClick: () => this.setState({ success: '' }) }, "\u00D7")) : null,
                    this.state.tab === 'Dashboard' ? this.dashboardView() : this.state.tab === 'Action Center' ? this.actionCenterView() : this.state.tab === 'Procurement' ? this.procurementView() : this.state.tab === 'Disbursements' ? this.dvView() : this.state.tab === 'Travel & Training' ? this.travelView() : this.state.tab === 'Inventory Management' ? this.stockView() : this.state.tab === 'Property Accountability' ? this.propertyAccountabilityView() : this.otherView(),
                    this.prCreateDialog(),
                    this.prQuickAddModal(),
                    this.state.viewType && this.state.details?.record ? (this.state.viewType==='PR' ? (this.state.prEditOpen?this.prEditModal():this.prDetailModal()) : this.state.viewType==='DV' ? (this.state.dvCancel?this.dvCancelModal():this.dvDetailModal()) : this.state.viewType==='PO' ? this.poDetailModal() : this.state.viewType==='IAR' ? this.iarDetailModal() : React.createElement("div", { className: "modal-backdrop", onClick: () => this.setState({ viewType: '', details: null }) },
                        React.createElement("div", { className: "modal", role: "dialog", "aria-modal": "true", onClick: (e) => e.stopPropagation() },
                            React.createElement("div", { className: "section-head" },
                                React.createElement("h3", null,
                                    this.state.viewType,
                                    " ",
                                    this.state.details.record.display_number ?? '#' + this.state.details.record.id),
                                React.createElement("button", { className: "icon-button", onClick: () => this.setState({ viewType: '', details: null }) }, "\u00D7")),
                            React.createElement("div", { className: "modal-scroll" },
                                React.createElement("h4", null, "Record"),
                                React.createElement("pre", null, JSON.stringify(this.state.details.record, null, 2)),
                                React.createElement("h4", null, "Line details"),
                                React.createElement("pre", null, JSON.stringify(this.state.details.lines, null, 2)),
                                React.createElement("h4", null, "Linked documents"),
                                React.createElement("pre", null, JSON.stringify(this.state.details.links, null, 2)),
                                React.createElement("h4", null, "Version history"),
                                React.createElement("pre", null, JSON.stringify(this.state.details.versions, null, 2)),
                                React.createElement("h4", null, "Manual signed-copy attestations"),
                                React.createElement("pre", null, JSON.stringify(this.state.details.manual_signature_records, null, 2))),
                            React.createElement("a", { className: "print-link", target: "_blank", rel: "noopener", href: 'api/print.php?organization_id=' + this.state.organization_id + '&type=' + this.state.viewType + '&id=' + this.state.details.record.id }, "Open print snapshot \u2197")))) : null,
                    React.createElement("footer", null, "IPDAMS Development Build \u00B7 Government templates and financial rules require owner validation \u00B7 Asia/Manila"))));
    }
}
ReactDOM.render(React.createElement(App, null), document.getElementById('root'));
