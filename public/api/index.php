<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/private/app/Domain.php';
require_once dirname(__DIR__).'/private/app/RoleFunctions.php';
require_once dirname(__DIR__).'/private/app/DivisionSchoolAccess.php';
require_once dirname(__DIR__).'/private/app/DivisionConsolidation.php';
require_once dirname(__DIR__).'/private/app/TextbookDirectory.php';
require_once dirname(__DIR__).'/private/app/TextbookCoReceiptWorkflow.php';
require_once dirname(__DIR__).'/private/app/CatalogRegistryLinks.php';
require_once dirname(__DIR__).'/private/app/PrClassificationPreview.php';
require_once dirname(__DIR__).'/private/app/PropertyLineageReview.php';
require_once dirname(__DIR__).'/private/app/PropertyEvidenceSnapshots.php';
require_once dirname(__DIR__).'/private/app/RfqCanvassSignatories.php';
require_once dirname(__DIR__).'/private/app/AwardDocuments.php';
require_once dirname(__DIR__).'/private/app/ProcurementDocumentDates.php';
require_once dirname(__DIR__).'/private/app/IarDocumentCorrections.php';
require_once dirname(__DIR__).'/private/app/PoDocuments.php';
require_once dirname(__DIR__).'/private/app/TravelWorkflow.php';
require_once dirname(__DIR__).'/private/app/TravelReimbursement.php';
require_once dirname(__DIR__).'/private/app/TravelBranding.php';
ini_set('display_errors','0');
ini_set('log_errors','1');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
$isHttps=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https';
ini_set('session.use_strict_mode','1');
ini_set('session.cookie_httponly','1');
ini_set('session.cookie_samesite','Lax');
ini_set('session.cookie_secure',$isHttps?'1':'0');
session_name('IPDAMSSESSID');session_start();

/** M81/v0.2.79: stable request fingerprint. Lists keep their order; JSON
 * objects have sorted keys to accept harmless property-order changes. It is
 * bound to the action as well as the payload, not just the user and key.
 */
function ipdamsCanonicalRequest(mixed $value): mixed {
    if(!is_array($value))return $value;
    if(array_is_list($value))return array_map('ipdamsCanonicalRequest',$value);
    ksort($value,SORT_STRING);
    foreach($value as $key=>$child)$value[$key]=ipdamsCanonicalRequest($child);
    return $value;
}
function ipdamsRequestFingerprint(string $route,array $body): string {
    return hash('sha256',$route."\\0".json_encode(ipdamsCanonicalRequest($body),
        JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}

$_SESSION['csrf']??=bin2hex(random_bytes(24));
$route=(string)($_GET['route']??'auth.session');$method=$_SERVER['REQUEST_METHOD']??'GET';
try {
    $db=database();
    if($route==='auth.login'&&$method==='POST'){
        csrfCheck();$body=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);demand(is_array($body),'Invalid login request');
        $attempts=(int)($_SESSION['login_attempts']??0);demand($attempts<8,'Too many attempts this session. Retry in a new session.',429);
        $user=one($db,'SELECT * FROM users WHERE email=?',[strtolower(requiredString($body,'email',190))]);
        if(!$user||!(bool)$user['active']||!password_verify((string)($body['password']??''),$user['password_hash'])){$_SESSION['login_attempts']=$attempts+1;throw new DomainError('Invalid credentials',401);}
        session_regenerate_id(true);$_SESSION['uid']=(int)$user['id'];$_SESSION['credential_stamp']=ipdamsCredentialStamp((string)$user['password_hash']);$_SESSION['login_attempts']=0;$_SESSION['csrf']=bin2hex(random_bytes(24));jsonOut(['user'=>['id'=>(int)$user['id'],'name'=>$user['full_name'],'role'=>$user['role']],'csrf'=>$_SESSION['csrf']]);
    }
    if($route==='auth.session'&&$method==='GET'){
        $user=null;$orgs=[];
        if(isset($_SESSION['uid'])){
            try{$u=currentUser($db);$user=['id'=>(int)$u['id'],'name'=>$u['full_name'],'role'=>$u['role']];
                if($u['role']==='VIEWER'){
                    $ids=ipdamsMooeViewerSchools($db,$u);
                    $orgs=[];
                    if($ids){$marks=implode(',',array_fill(0,count($ids),'?'));
                        $orgs=q($db,"SELECT id,code,name,kind,address FROM organizations WHERE active=1 AND kind='SCHOOL' AND id IN ($marks) ORDER BY name,id",$ids)->fetchAll();}
                }else{
                    $orgs=$u['role']==='SUPER_ADMIN'?q($db,"SELECT id,code,name,kind,address FROM organizations WHERE active=1 ORDER BY CASE WHEN kind='DIVISION' THEN 0 ELSE 1 END,name")->fetchAll():q($db,"SELECT o.id,o.code,o.name,o.kind,o.address FROM organizations o JOIN user_organization_scopes s ON s.organization_id=o.id WHERE s.user_id=? AND o.active=1 ORDER BY CASE WHEN o.kind='DIVISION' THEN 0 ELSE 1 END,o.name",[$u['id']])->fetchAll();
                }
            }catch(DomainError $e){unset($_SESSION['uid']);}
        }
        jsonOut(['user'=>$user,'organizations'=>$orgs,'csrf'=>$_SESSION['csrf']]);
    }
    if($route==='auth.logout'&&$method==='POST'){csrfCheck();$_SESSION=[];session_regenerate_id(true);jsonOut(['ok'=>true]);}
    $user=currentUser($db);$domain=new Domain($db,$user);
    // Fail closed before any route dispatch, including hand-written POST handlers.
    // VIEWER accounts are exclusively effective-assignment-scoped MOOE reviewers.
    if($user['role']==='VIEWER'){
        ipdamsMooeViewerSchools($db,$user); // verify binding even for global reads
        // No stock/property register, source balances or unissued forms from direct
        // GET URLs. This gate runs before the broad Domain dispatch table.
        if($method==='GET'){
            if($route==='list'){
                $kind=(string)($_GET['kind']??'');
                demand(!in_array($kind,['ris','stocks','stock_exceptions','received_property','assets',
                    'accountability'],true),'Restricted to monthly issued documents',403);
            }
            demand(!($route==='detail' && (string)($_GET['type']??'')==='RIS'),
                'Use issued RIS documents for the selected month',403);
            demand(!in_array($route,['pr.property.lineage','document.property.evidence',
                'division.property_registry','check.stock.preview','check.stock.list','catalog.registry.links'],true),
                'Restricted reference and inventory data',403);
        }
        if($method==='POST')demand($route==='auth.change_password','Read-only Division MOOE account',403);
        if($method==='GET' && isset($_GET['organization_id']))
            scope($db,$user,idOf($_GET,'organization_id'));
    }
    if($method==='GET'){
        DivisionSchoolAccess::requireGet($db,$user,$route,$_GET);
        if($route==='property.registration.detail')jsonOut($domain->propertyRegistrationDetail($_GET));
        if($route==='division.inventory.consolidation')jsonOut(DivisionConsolidation::read($db,$user,$_GET));
        if($route==='textbook.directory')jsonOut(TextbookDirectory::read($db,$user,$_GET));
        if($route==='textbook.co_receipts')jsonOut((new TextbookCoReceiptWorkflow($db,$user))->list($_GET));
        if($route==='textbook.co_receipt.detail')jsonOut((new TextbookCoReceiptWorkflow($db,$user))->detail($_GET));
        if($route==='division.ris.lines')jsonOut($domain->divisionRisLines($_GET));
        if($route==='division.ris.lots')jsonOut($domain->divisionRisLots($_GET));
        if($route==='division.accountability.eligible')jsonOut($domain->divisionEligibleAccountability($_GET));
        if($route==='role.functions.catalog')jsonOut((new RoleFunctions($db,$user))->catalog());
        if($route==='role.functions.user')jsonOut((new RoleFunctions($db,$user))->userDetail($_GET));
        if($route==='role.functions.mine')jsonOut((new RoleFunctions($db,$user))->mine());
        if($route==='role.functions.register')jsonOut((new RoleFunctions($db,$user))->issuedRegister($_GET));
        if($route==='role.functions.school_register')jsonOut((new RoleFunctions($db,$user))->schoolRegister($_GET));
        if($user['role']==='VIEWER' && $route==='search'){
            $records=$domain->search($_GET);
            jsonOut(array_values(array_filter($records,static fn(array $r)=>($r['type']??'')!=='RIS')));
        }
        if($route==='mooe.issued.documents'){
            demand($user['role']==='VIEWER','Issued-only reviewer endpoint',403);
            jsonOut(ipdamsMooeIssuedDocuments($db,$user,idOf($_GET,'organization_id'),
                requiredString($_GET,'month',7)));
        }
        $read=[
            'dashboard'=>'dashboard','list'=>'list','detail'=>'detail','eligible'=>'eligible','search'=>'search','catalog.search'=>'searchCatalog','organization.hierarchy'=>'organizationHierarchy','fund.policy.settings'=>'fundPolicySettings'
        ];
        if($route==='travel.documents')jsonOut((new TravelWorkflow($db,$user))->listDocuments($_GET));
        if($route==='travel.claims')jsonOut((new TravelReimbursement($db,$user))->list($_GET));
        if($route==='travel.claim.detail')jsonOut((new TravelReimbursement($db,$user))->detail($_GET));
        if($route==='travel.detail')jsonOut((new TravelWorkflow($db,$user))->detail($_GET));
        if($route==='travel.signatories.settings')jsonOut((new TravelWorkflow($db,$user))->signatories($_GET));
        if($route==='travel.branding.settings')jsonOut((new TravelBranding($db,$user))->settings($_GET));
        if($route==='requesting.units')jsonOut($domain->requestingUnits($_GET));
        if($route==='staff.directory')jsonOut($domain->staffDirectory($_GET));
        if($route==='division.mooe.assignments')jsonOut($domain->divisionMooeAssignments($_GET));
        if($route==='staff.employee.payment_identity')jsonOut($domain->employeeDvIdentity($_GET));
        if($route==='accounts')jsonOut($domain->accounts());
        if($route==='workflow.rfq_lines')jsonOut($domain->workflowRfqLines($_GET));
        if($route==='workflow.rfq_candidates')jsonOut($domain->workflowRfqCandidates($_GET));
        if($route==='workflow.quote')jsonOut($domain->workflowQuote($_GET));
        if($route==='workflow.po_lines')jsonOut($domain->workflowPoLines($_GET));
        if($route==='po.document.edit' || $route==='po.terms.edit'){
            $org=idOf($_GET,'organization_id');scope($db,$user,$org);
            jsonOut(PoDocuments::editable($db,$org,idOf($_GET,'po_id')));
        }
        if($route==='uacs')jsonOut($domain->listUacs($_GET));
        if($route==='division.classifications')jsonOut($domain->listDivisionClassifications($_GET));
        if($route==='division.property_registry')jsonOut($domain->listDivisionPropertyRegistry($_GET));
        if($route==='catalog.registry.search')jsonOut((new CatalogRegistryLinks($db,$user,$domain))->search($_GET));
        if($route==='catalog.registry.links')jsonOut((new CatalogRegistryLinks($db,$user,$domain))->links($_GET));
        if($route==='pr.classification.preview')jsonOut((new PrClassificationPreview($db,$user,$domain,new CatalogRegistryLinks($db,$user,$domain)))->preview($_GET));
        if($route==='pr.property.lineage')jsonOut((new PropertyLineageReview($db,$user))->review($_GET));
        if($route==='document.property.evidence')jsonOut((new PropertyEvidenceSnapshots($db,$user,new PrClassificationPreview($db,$user,$domain,new CatalogRegistryLinks($db,$user,$domain))))->read($_GET));
        if($route==='check.print.profile')jsonOut($domain->schoolCheckPrintProfile($_GET));
        if($route==='check.stock.preview')jsonOut($domain->nextPhysicalCheck($_GET));
        if($route==='check.stock.list')jsonOut($domain->listPhysicalChecks($_GET));
        if($route==='fund.availability')jsonOut($domain->fundAvailability($_GET));
        if($route==='dv.tax.preview')jsonOut($domain->previewRegularDvTax($_GET));
        if($route==='division.vat.settings')jsonOut($domain->divisionVatSettings($_GET));
        if($route==='division.item_uacs')jsonOut($domain->divisionItemUacsSettings($_GET));
        if($route==='list' && (string)($_GET['kind']??'')==='awards'){
            $org=idOf($_GET,'organization_id');scope($db,$user,$org);
            $rows=$domain->list($_GET);
            jsonOut(AwardDocuments::decorateList($db,$org,$rows));
        }
        if($route==='list' && (string)($_GET['kind']??'')==='iar'){
            $org=idOf($_GET,'organization_id');scope($db,$user,$org);
            jsonOut(IarDocumentCorrections::decorateList($db,$org,$domain->list($_GET)));
        }
        if($route==='iar.document.edit'){
            $org=idOf($_GET,'organization_id');scope($db,$user,$org);
            jsonOut(IarDocumentCorrections::read($db,$org,idOf($_GET,'iar_id')));
        }
        if($route==='detail' && (string)($_GET['type']??'')==='IAR'){
            $data=$domain->detail($_GET);$org=idOf($_GET,'organization_id');
            $data['iar_header']=IarDocumentCorrections::read($db,$org,idOf($_GET,'id'));
            jsonOut($data);
        }
        if($route==='list' && (string)($_GET['kind']??'')==='po'){
            $org=idOf($_GET,'organization_id');scope($db,$user,$org);
            $rows=ProcurementDocumentDates::decoratePO($db,$org,$domain->list($_GET));
            $locked=q($db,'SELECT DISTINCT i.po_id FROM iars i JOIN purchase_orders po ON po.id=i.po_id AND po.organization_id=?',[$org])->fetchAll();
            $lockedIds=array_fill_keys(array_map(static fn($x)=>(int)$x['po_id'],$locked),true);
            foreach($rows as &$row)$row['iar_locked']=isset($lockedIds[(int)$row['id']]);unset($row);
            jsonOut($rows);
        }
        demand(isset($read[$route]),'Unknown GET endpoint',404);jsonOut($domain->{$read[$route]}($_GET));
    }
    demand($method==='POST','Unsupported HTTP method',405);csrfCheck();
    $contentType=strtolower((string)($_SERVER['CONTENT_TYPE']??''));demand(str_contains($contentType,'application/json'),'JSON request body required',415);
    $raw=file_get_contents('php://input');demand(strlen($raw)<=1048576,'Request body too large',413);
    $body=json_decode($raw,true,512,JSON_THROW_ON_ERROR);demand(is_array($body),'Expected JSON object');
    DivisionSchoolAccess::requirePost($db,$user,$route,$body);
    // Phase A: fail closed. The legacy generic textbox POST routes can mutate
    // inventory without matched receiving acceptance, appointed teacher,
    // verified shortage evidence or Division disposal/relief authority.
    // Preserve all existing data; replacement lifecycle writes will use
    // document-linked, idempotent, approved transactions in later releases.
    if(in_array($route,['textbook.opening','textbook.move'],true))
        demand(false,'Legacy textbook posting is paused for the documented textbook receipt, transfer and loss/disposal workflows. No quantities were changed.',409);
    // CO receipts are stage-gated by approved schema and the exact, appointed
    // receiving custodian binding. Legacy generic textbook writes stay blocked.
    $coReceiptActions=['textbook.co_receipt.create'=>'create','textbook.co_receipt.submit'=>'submit',
        'textbook.co_receipt.inspect'=>'inspect','textbook.co_receipt.accept'=>'accept',
        'textbook.co_receipt.reject'=>'reject','textbook.co_receipt.cancel_draft'=>'cancelDraft'];
    $calls=[
        'organization.bootstrap'=>'bootstrapOrganization','organization.division.create'=>'createDivision','organization.school.create'=>'createSchool','organization.school.attach'=>'attachLegacySchool','organization.user.create'=>'createOrganizationUser','organization.mooe.viewer.create'=>'createMooeViewerAccount','organization.user.reset_password'=>'resetOrganizationUserPassword','organization.profile.update'=>'updateOrganizationProfile','organization.school.classification'=>'saveSchoolImplementationClass','auth.change_password'=>'changeOwnPassword',
        'division.vat.save'=>'saveDivisionVatRate','master.uacs.status'=>'setUacsStatus','division.item_uacs.save'=>'saveDivisionItemUacs','division.item_uacs.effectivity.preview'=>'previewItemUacsEffectivityCorrection','division.item_uacs.effectivity.apply'=>'applyItemUacsEffectivityCorrection','division.item_uacs.bulk.preview'=>'previewBulkConsumableUacs','division.item_uacs.bulk.apply'=>'applyBulkConsumableUacs','master.division_classification.save'=>'saveDivisionClassification','master.property.threshold.save'=>'savePropertyThreshold','master.property.useful_life.save'=>'savePropertyUsefulLife','master.property.registry.save'=>'savePropertyRegistryEntry','master.supplier.create'=>'addSupplier','master.supplier.update'=>'updateSupplier','master.supplier.status'=>'setSupplierStatus','master.atc.create'=>'addAtc','master.atc.update'=>'updateAtc','master.atc.status'=>'setAtcStatus','master.atc.rate.create'=>'addAtcRate','master.atc.rate.close'=>'closeAtcRate','master.unit.create'=>'addUnit','master.item.create'=>'addItem','master.item.subcategory'=>'updateItemSubcategory','master.item.status'=>'setItemStatus','master.fund.create'=>'addFund','division.fund_source.save'=>'saveDivisionFundSource','division.fund_policy.save'=>'saveFundWithholdingPolicy','bank.create'=>'addBankAccount','bank.download.record'=>'recordBankDownload','bank.download.link_existing'=>'linkHistoricalBankDownload',
        'division.mooe.assignment.save'=>'saveDivisionMooeAssignment','staff.employee.change'=>'changeStaffEmployee','staff.head.save'=>'saveOfficeHead','staff.dv.signatories.save'=>'saveDvSectionSignatories','staff.custodian.save'=>'saveSupplyCustodian','staff.bac.save'=>'saveBacTerm','staff.bac.date.correct'=>'correctBacTermEffectiveDate','requesting.units.change'=>'changeRequestingUnit','requesting.units.save'=>'saveRequestingUnits','pr.create'=>'createPR','pr.update'=>'updateDraftPR','pr.finalize'=>'finalizePR','rfq.create'=>'createRFQ','rfq.update'=>'updateRFQ','quote.create'=>'createQuote','award.create'=>'createAward','po.create'=>'createPO','po.terms.correct'=>'correctTerms','po.document.correct'=>'correctDocument','iar.create'=>'createIAR','iar.document.correct'=>'correctIarDocument','advance.create'=>'createAdvance',
        'dv.create'=>'createDV','dv.update'=>'updateDraftDV','dv.particulars.update'=>'updateDvParticulars','dv.tax.disposition.correct'=>'correctDraftDvTaxDisposition','dv.finalize'=>'finalizeDV','dv.accounting'=>'reviseAccounts','tax.confirm'=>'confirmTax',
        'ris.create'=>'createRIS','ris.issue'=>'issueRIS','ris.reverse'=>'reverseRIS',
        'check.create'=>'createCheck','check.status'=>'updateCheck','check.replace'=>'replaceCheck',
        'check.print.profile.save'=>'saveSchoolCheckPrintProfile',
        'check.stock.register'=>'registerPhysicalCheckbook','check.stock.unusable'=>'markPhysicalLeafUnusable','check.stock.correct'=>'correctPhysicalLeafStatus',
        'fund.event'=>'addFundEvent','stock.opening'=>'openingStock','asset.opening'=>'recordAsset','property.registration.correct'=>'correctPropertyRegistration','asset.iar.register'=>'registerIarProperty','asset.move'=>'moveAsset','textbook.opening'=>'textbookOpening','textbook.move'=>'textbookMove','dv.draft.cancel'=>'cancelDraftDV','document.cancel'=>'cancelDocument','po.amend'=>'amendPO','accountability.issue'=>'issueAccountability','bank.reconcile'=>'reconcileBank','document.signed-copy'=>'recordManualSignature',
    ];
    demand(isset($calls[$route]) || in_array($route,['master.item.registry.link','travel.activity.create','travel.activity.revise','travel.activity.signatories.refresh','travel.authority.create','travel.signatories.save','travel.branding.save','travel.claim.create','travel.claim.revise','travel.claim.evidence','travel.claim.verify','role.functions.grant','role.functions.revoke'],true) || isset($coReceiptActions),'Unknown POST endpoint',404);
    $key=$_SERVER['HTTP_X_IDEMPOTENCY_KEY']??'';
    demand(is_string($key)&&preg_match('/^[a-zA-Z0-9_.:-]{12,100}$/',$key)===1,'Valid X-Idempotency-Key required',400);
    // The source-fund row serializes grants, obligations, finalizations and DV
    // cancellation. READ COMMITTED is essential: an earlier scope/lookup SELECT
    // under MariaDB's default REPEATABLE READ could otherwise keep a stale
    // fund-availability snapshot even after a competing request commits.
    // Apply to fund-mutating routes and registry-link writes that need fresh
    // Division configuration after acquiring scoped write locks.
    // Inventory/property aggregates must also observe commits made while waiting
    // for their lot, IAR, RIS or asset mutex; stale counts can over-register assets.
    if(in_array($route,['role.functions.grant','role.functions.revoke','master.item.create','master.item.registry.link','pr.finalize','pr.update','po.create','po.amend','po.terms.correct','po.document.correct','iar.create','iar.document.correct','ris.create','ris.issue','ris.reverse','stock.opening','asset.opening','property.registration.correct','asset.iar.register','asset.move','accountability.issue','textbook.opening','textbook.move','textbook.co_receipt.create','textbook.co_receipt.submit','textbook.co_receipt.inspect','textbook.co_receipt.accept','textbook.co_receipt.reject','textbook.co_receipt.cancel_draft','travel.activity.create','travel.activity.revise','travel.activity.signatories.refresh','travel.authority.create','travel.signatories.save','travel.branding.save','travel.claim.create','travel.claim.revise','travel.claim.evidence','travel.claim.verify','rfq.update','quote.create','award.create','dv.create','dv.finalize','dv.tax.disposition.correct','dv.draft.cancel','fund.event','document.cancel','bank.download.record','bank.download.link_existing','check.create','check.status','check.replace','check.print.profile.save','check.stock.register','check.stock.unusable','check.stock.correct','division.mooe.assignment.save','division.fund_source.save','division.fund_policy.save','division.item_uacs.save','division.item_uacs.bulk.apply','division.vat.save','organization.school.classification','master.fund.create'],true))
        $db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    // v0.2.79: atomically bind each idempotency key to exactly one
    // authorized route and canonical JSON body. Without this association a
    // reused key could return a success receipt for a DIFFERENT operation.
    // Existing pre-upgrade rows lack this fingerprint; fail closed rather than
    // guessing whether a legacy result belongs to the submitted request.
    $fingerprint=ipdamsRequestFingerprint($route,$body);
    $db->beginTransaction();
    try {
        $written=q($db,'INSERT IGNORE INTO idempotency_keys(user_id,request_key,response_json) VALUES (?,?,?)',
            [$user['id'],$key,'{}'])->rowCount();
        if($written===0){
            $old=one($db,'SELECT response_json FROM idempotency_keys WHERE user_id=? AND request_key=?',
                [$user['id'],$key]);
            demand($old!==null,'Idempotency record unavailable. Refresh and retry with a new request key',409);
            $saved=json_decode((string)$old['response_json'],true);
            demand(is_array($saved) &&
                ($saved['_ipdams_request_version']??null)===1 &&
                isset($saved['route'],$saved['fingerprint']) &&
                is_string($saved['route']) && is_string($saved['fingerprint']) &&
                array_key_exists('response',$saved),
                'This earlier request key cannot be safely replayed. Review the document status and retry with a new request key',409);
            demand(hash_equals($saved['route'],$route) &&
                hash_equals($saved['fingerprint'],$fingerprint),
                'This request key was already used for a different action or document. Refresh and use a new request key',409);
            $db->commit();jsonOut($saved['response']);
        }
        // Cross-document date checks run inside idempotent issuance transaction.
        // No workflow date may precede the linked prior form; same-day allowed.
        $chronologyOrg=0;$chronology=null;
        if(in_array($route,['rfq.create','rfq.update','quote.create','po.create','iar.create'],true)){
            $chronologyOrg=idOf($body,'organization_id');scope($db,$user,$chronologyOrg);
            $chronology=ProcurementDocumentDates::before($db,$chronologyOrg,$route,$body);
        }
        $poAppendix=null;
        if($route==='po.create'){
            demand(is_array($chronology) && is_string($chronology['date']??null),'PO date verification required',409);
            $poAppendix=PoDocuments::prepare($db,$chronologyOrg,$body,$chronology['date']);
        }
        // New RFQs require the canvasser and the officially appointed BAC Chairperson.
        // Both are scoped and effective-dated, and validated before consuming a number.
        $rfqCanvass=null;$rfqCanvassOrg=0;
        if($route==='rfq.create'){
            $rfqCanvassOrg=idOf($body,'organization_id');
            scope($db,$user,$rfqCanvassOrg);
            $rfqCanvass=RfqCanvassSignatories::forIssue($db,$rfqCanvassOrg,$body);
        }
        // Capture effective BAC appointees and a real resolution date BEFORE
        // numbering, then atomically bind them to the new award snapshot.
        $awardDoc=null;$awardDocOrg=0;
        if($route==='award.create'){
            $awardDocOrg=idOf($body,'organization_id');scope($db,$user,$awardDocOrg);
            $awardDoc=AwardDocuments::forIssue($db,$awardDocOrg,$body);
        }
        if(isset($coReceiptActions[$route])){
            $out=(new TextbookCoReceiptWorkflow($db,$user))->{$coReceiptActions[$route]}($body);
        }elseif($route==='role.functions.grant'){
            $out=(new RoleFunctions($db,$user))->grant($body);
        }elseif($route==='role.functions.revoke'){
            $out=(new RoleFunctions($db,$user))->revoke($body);
        }elseif($route==='master.item.registry.link'){
            $out=(new CatalogRegistryLinks($db,$user,$domain))->saveExisting($body);
        }elseif($route==='master.item.create' && trim((string)($body['registry_id']??''))!==''){
            $out=$domain->addItem($body);
            (new CatalogRegistryLinks($db,$user,$domain))->linkNewItem($body,(int)$out['id']);
        }elseif(in_array($route,['travel.claim.create','travel.claim.revise','travel.claim.evidence','travel.claim.verify'],true)){
            $claims=new TravelReimbursement($db,$user);
            $claimActions=['travel.claim.create'=>'create','travel.claim.revise'=>'revise',
                'travel.claim.evidence'=>'attachEvidence','travel.claim.verify'=>'verifyEvidence'];
            $out=$claims->{$claimActions[$route]}($body);
        }elseif($route==='iar.document.correct'){
            $org=idOf($body,'organization_id');scope($db,$user,$org);allowedToWrite($user);
            $workspace=one($db,'SELECT kind FROM organizations WHERE id=? AND active=1',[$org]);
            demand($workspace!==null && ($user['role']==='SUPER_ADMIN' ||
                ($user['role']==='SCHOOL' && $workspace['kind']==='SCHOOL') ||
                ($user['role']==='DIVISION' && $workspace['kind']==='DIVISION')),
                'Only an authorized account in the owning workspace may correct the IAR',403);
            $out=IarDocumentCorrections::correct($db,$org,(int)$user['id'],$body);
        }elseif($route==='po.document.correct'){
            $org=idOf($body,'organization_id');scope($db,$user,$org);
            allowedToWrite($user);
            $workspace=one($db,'SELECT kind FROM organizations WHERE id=? AND active=1',[$org]);
            demand($workspace!==null && ($user['role']==='SUPER_ADMIN' ||
                ($user['role']==='SCHOOL' && $workspace['kind']==='SCHOOL') ||
                ($user['role']==='DIVISION' && $workspace['kind']==='DIVISION')),
                'Only an authorized account in the owning workspace may edit the PO',403);
            $out=PoDocuments::correctDocument($db,$org,(int)$user['id'],$body);
        }elseif($route==='po.amend'){
            // The historical advanced mutation bypasses Appendix 61 print and
            // the quotation-priced correction checks; route users to Edit PO.
            demand(false,'Use Purchase Orders → Edit PO for versioned corrections before IAR',409);
        }elseif($route==='po.terms.correct'){
            $org=idOf($body,'organization_id');scope($db,$user,$org);
            allowedToWrite($user);
            $workspace=one($db,'SELECT kind FROM organizations WHERE id=? AND active=1',[$org]);
            demand($workspace!==null && ($user['role']==='SUPER_ADMIN' ||
                ($user['role']==='SCHOOL' && $workspace['kind']==='SCHOOL') ||
                ($user['role']==='DIVISION' && $workspace['kind']==='DIVISION')),
                'Only an authorized account in the owning workspace may correct PO terms',403);
            $out=PoDocuments::correctTerms($db,$org,(int)$user['id'],$body);
        }elseif($route==='travel.branding.save'){
            $out=(new TravelBranding($db,$user))->save($body);
        }elseif(in_array($route,['travel.activity.create','travel.activity.revise','travel.activity.signatories.refresh','travel.authority.create','travel.signatories.save'],true)){
            $travel=new TravelWorkflow($db,$user);
            $actions=['travel.activity.create'=>'createRequest','travel.activity.revise'=>'reviseRequest','travel.activity.signatories.refresh'=>'refreshRequestSignatories','travel.authority.create'=>'createAuthority','travel.signatories.save'=>'saveSignatories'];
            demand(isset($actions[$route]),'Unknown travel action',404);
            $out=$travel->{$actions[$route]}($body);
        }else{
        $out=$domain->{$calls[$route]}($body);
        }
        if(in_array($route,['quote.create','po.create'],true)){
            ProcurementDocumentDates::after($db,$chronologyOrg,(int)$user['id'],$route,$out,$chronology);
        }
        if($route==='po.create'){
            demand(is_array($poAppendix) && (int)($out['id']??0)>0,'PO documentary record unavailable',500);
            PoDocuments::record($db,$chronologyOrg,(int)$user['id'],(int)$out['id'],$poAppendix);
        }
        if($route==='award.create'){
            demand(is_array($awardDoc) && (int)($out['id']??0)>0,'Award documentary record unavailable',500);
            AwardDocuments::record($db,$awardDocOrg,(int)$user['id'],(int)$out['id'],$awardDoc);
        }
        if($route==='rfq.create'){
            demand(is_array($rfqCanvass) && (int)($out['id']??0)>0,'RFQ participants unavailable',500);
            RfqCanvassSignatories::record($db,$rfqCanvassOrg,(int)$user['id'],(int)$out['id'],$rfqCanvass);
        }
        // The existing route has already completed all Domain validations. Capture
        // read-only reference metadata in this SAME idempotent DB transaction;
        // rollback the issuance if the evidence INSERT technically fails.
        if(in_array($route,['pr.finalize','pr.update','po.create','po.amend','po.document.correct','iar.create'],true)){
            $evidence=new PropertyEvidenceSnapshots($db,$user,
                new PrClassificationPreview($db,$user,$domain,new CatalogRegistryLinks($db,$user,$domain)));
            $evidence->capture($route,$body,$out);
        }
        $envelope=[
            '_ipdams_request_version'=>1,
            'route'=>$route,
            'fingerprint'=>$fingerprint,
            'response'=>$out,
        ];
        q($db,'UPDATE idempotency_keys SET response_json=? WHERE user_id=? AND request_key=?',
            [json_encode($envelope,JSON_THROW_ON_ERROR),$user['id'],$key]);
        $db->commit();
        if($route==='auth.change_password'){
            // Bind only the password-changing request's session, AFTER commit.
            // Avoid invalidating the active session if the DB transaction rolls back.
            $updated=one($db,'SELECT password_hash FROM users WHERE id=? AND active=1',[$user['id']]);
            demand($updated!==null,'Password changed. Please sign in again.',401);
            $_SESSION['credential_stamp']=ipdamsCredentialStamp((string)$updated['password_hash']);
        }
        jsonOut($out,201);
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
} catch(DomainError $error){http_response_code($error->status);header('Content-Type: application/json');echo json_encode(['error'=>$error->getMessage()]);}
catch(JsonException $error){http_response_code(400);header('Content-Type: application/json');echo json_encode(['error'=>'Malformed JSON request']);}
catch(PDOException $error){
    // Only operational metadata is logged; never send SQL, connection details,
    // user-entered values or database error descriptions to the browser.
    $incident=bin2hex(random_bytes(4));
    $nativeCode=is_array($error->errorInfo)?(string)($error->errorInfo[1]??'unknown'):'unknown';
    $safeRoute=preg_replace('/[^a-zA-Z0-9._-]/','',(string)($route??'unknown'));
    error_log('IPDAMS database failure incident='.$incident.' route='.$safeRoute.' SQLSTATE='.$error->getCode().' db_code='.$nativeCode);
    http_response_code(500);header('Content-Type: application/json');
    echo json_encode(['error'=>'Database operation failed. Consult server logs. Reference: '.$incident]);
}
catch(Throwable $error){error_log('IPDAMS application failure: '.$error->getMessage());http_response_code(500);header('Content-Type: application/json');echo json_encode(['error'=>'Unexpected server error']);}