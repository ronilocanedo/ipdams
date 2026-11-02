# Receiving interface integration change set (REVIEW ONLY)

**Target:** \`public/api/property-transfer-intake.php\` (hosting document root \`api/property-transfer-intake.php\`) from the read-only checked installation.

## 1. Additional view state

After the declaration ending \`$loggedInReceiver=false;\`, append:

\`\`\`php
$assessment=null;$mayAssess=false;
\`\`\`

## 2. POST route

In the existing POST \`if($action==='bind_receiver') ... elseif($action==='accept_transfer')\` chain, insert this clause **before** the final unknown-action rejection:

\`\`\`php
}elseif($action==='assess_condition'){
    $result=$acceptance->assessReceivedCondition([
        'organization_id'=>$org,
        'asset_id'=>(int)($_POST['asset_id']??0),
        'expected_condition'=>(string)($_POST['expected_condition']??''),
        'condition'=>(string)($_POST['condition']??''),
        'inspected_on'=>(string)($_POST['inspected_on']??''),
        'inspection_reference'=>(string)($_POST['inspection_reference']??''),
        'remarks'=>(string)($_POST['remarks']??''),
        'physically_inspected'=>($_POST['physically_inspected']??'')==='on'
    ]);
    $_SESSION['pt_intake_notice']='Physical condition assessment saved for '.
        $result['property_tag'].' as '.$result['condition'].
        '. ICS/PAR remains a separate authorized action.';
\`\`\`

Preserve the original bound \`_csrf\` validation. For the POST-completion redirect, return to \`view=received\` for \`assess_condition\`; leave all existing actions redirecting to \`view=incoming\`.

## 3. GET view

Insert before the existing \`elseif($view==='received')\`:

\`\`\`php
}elseif(isset($_GET['assess_asset_id'])){
    $view='assess';
    $assessment=$acceptance->receivedConditionPreview($org,(int)$_GET['assess_asset_id']);
\`\`\`

In the existing \`view=received\` branch, after the register read, append:

\`\`\`php
$mayAssess=$acceptance->mayAssessReceivedCondition($org);
\`\`\`

On each row in Received Asset Register next to its existing \`Property History\` link, display the following link only for current bound officer and ACTIVE row:

\`\`\`php
<?php if($mayAssess&&($item['status']??'')==='ACTIVE'):?>
 · <a class="link" href="<?=ptiE($base.'&assess_asset_id='.(int)$item['id'])?>">
Assess condition</a>
<?php endif;?>
\`\`\`

## 4. Assessment view

Insert a dedicated \`elseif\` HTML view **before** the existing \`elseif($view==='history'&&$history)\`:

\`\`\`php
<?php elseif($view==='assess'&&$assessment):?>
<section>
<h2>Physical Condition Assessment</h2>
<p class="note">Property <?=ptiE($assessment['property_tag'])?> ·
<?=ptiE($assessment['item_name'])?> ·
<?=ptiE($assessment['transfer_ref'])?>.
This is a record of post-receipt physical inspection; it does not issue an ICS/PAR.</p>
<p>Current recorded condition:
<strong><?=ptiE($assessment['condition_note']?:'Not recorded')?></strong>
· Receiving location: <?=ptiE($assessment['location'])?></p>
<form method="post">
<input type="hidden" name="_csrf" value="<?=ptiE($_SESSION['csrf'])?>">
<input type="hidden" name="organization_id" value="<?=ptiE($org)?>">
<input type="hidden" name="action" value="assess_condition">
<input type="hidden" name="asset_id" value="<?=ptiE($assessment['id'])?>">
<input type="hidden" name="expected_condition" value="<?=ptiE($assessment['condition_note']??'')?>">
<div class="grid"><div>
<label>Actual physical inspection date</label>
<input type="date" name="inspected_on" required
min="<?=ptiE($assessment['received_on'])?>"
max="<?=ptiE((new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d'))?>"
value="<?=ptiE((new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d'))?>">
</div><div>
<label>Observed condition after inspection</label>
<select name="condition" required><option value="">Choose observed condition</option>
<?php foreach(['Serviceable','Needs Maintenance','For Inspection','For Repair','Under Repair'] as $choice):?>
<option value="<?=ptiE($choice)?>"><?=ptiE($choice)?></option>
<?php endforeach;?></select></div></div>
<label>Inspection report or repair evidence reference</label>
<input name="inspection_reference" minlength="8" maxlength="160" required
placeholder="Inspection report / work order / signed inspection reference">
<label>Assessment findings and correction reason</label>
<textarea name="remarks" rows="3" minlength="12" maxlength="500" required></textarea>
<p><label><input type="checkbox" name="physically_inspected" required>
I confirm that this property was physically inspected and the recorded condition is accurate.
This electronic attestation does not replace required official signatures or approvals.</label></p>
<p class="muted">Unserviceable/disposal declarations require the formal lifecycle workflow.
An ICS/PAR must still be issued separately after a genuine Serviceable assessment.</p>
<button type="submit">Save audited assessment</button>
<a class="link" href="<?=ptiE($base.'&view=received')?>">Cancel</a>
</form></section>
\`\`\`

## 5. Do not touch
Original receiving transfer acceptance and history, source asset, catalog cost, property numbering, original signed ITR/PTR/ICS/PAR snapshots, stock/fund ledgers or financial approvals.

## 6. Release gate
This is a review fragment, not an installable update. Integrate only against the exact installed two source files, run PHP lint plus authenticated role/tenant/state/browser tests, verify exact installed version, sign update manifest and hash, and prepare a version-gated ZIP before owner-led staging installation.