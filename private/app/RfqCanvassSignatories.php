<?php
declare(strict_types=1);
/** Non-approval documentary participants for a single numbered RFQ. Saved as
 * an immutable sidecar in the same transaction as RFQ issuance, never an
 * electronic signature and never a change to the RFQ source snapshot. */
final class RfqCanvassSignatories {
    private const KEY_PREFIX = 'RFQ_CANVASS_SIGN_V1_';
    public static function forIssue(PDO $db,int $organizationId,array $input): array {
        $issuedAt=requiredString($input,'issued_at',10);
        demand(preg_match('/^\d{4}-\d{2}-\d{2}$/D',$issuedAt)===1,'Enter the RFQ date',422);
        [$year,$month,$day]=array_map('intval',explode('-',$issuedAt));
        demand(checkdate($month,$day,$year),'Invalid RFQ date',422);
        $canvasser=idOf($input,'canvasser_employee_id');
        $chair=idOf($input,'bac_chair_employee_id');
        $canvasserEvidence=self::personOnRfqDate($db,$organizationId,$canvasser,$issuedAt,
            'canvasser','historical_canvasser_attested','historical_canvasser_reference',$input);
        $terms=q($db,'SELECT id FROM bac_terms WHERE organization_id=? AND starts<=?
            AND (ends IS NULL OR ends>?) ORDER BY id DESC',[$organizationId,$issuedAt,$issuedAt])->fetchAll();
        demand(count($terms)===1,'Configure exactly one BAC term effective on the RFQ date under BAC & Heads',409);
        $chairs=q($db,'SELECT employee_id FROM bac_members WHERE term_id=? AND role_title=?',[(int)$terms[0]['id'],'CHAIR'])->fetchAll();
        demand(count($chairs)===1,'Set exactly one BAC Chairperson in BAC & Heads before creating RFQ',409);
        demand((int)$chairs[0]['employee_id']===$chair,'BAC Chairperson has changed or is not effective on RFQ date. Refresh BAC & Heads',409);
        $chairEvidence=self::personOnRfqDate($db,$organizationId,$chair,$issuedAt,
            'BAC Chairperson','historical_chair_attested','historical_chair_reference',$input);
        return [
            'issued_at'=>$issuedAt,
            'bac_term_id'=>(int)$terms[0]['id'],
            'bac_chair'=>$chairEvidence['person'],
            'canvasser'=>$canvasserEvidence['person'],
            'historical_participants'=>[
                'canvasser'=>$canvasserEvidence['historical'],
                'bac_chair'=>$chairEvidence['historical'],
            ],
        ];
    }
    /** The employee-directory entry date is not proof of first employment.
     * Never rewrite dated staff assignments to backfill an older RFQ. If no
     * historical assignment exists, explicitly attest a genuine earlier
     * designation; keep the explanation alongside the immutable RFQ sidecar.
     */
    private static function personOnRfqDate(PDO $db,int $org,int $employee,string $rfqDate,
        string $label,string $attestedKey,string $referenceKey,array $input): array {
        $sql='SELECT a.employee_id,e.full_name,a.designation,e.position_title,a.effective_from FROM employee_assignments a
            JOIN employees e ON e.id=a.employee_id WHERE a.organization_id=? AND a.employee_id=?
            AND a.effective_from<=? AND (a.effective_to IS NULL OR a.effective_to>?) ORDER BY a.id DESC';
        $historical=q($db,$sql,[$org,$employee,$rfqDate,$rfqDate])->fetchAll();
        demand(count($historical)<=1,'Conflicting employee assignments on the RFQ date. Review Employee Directory.',409);
        if(count($historical)===1){
            return ['person'=>self::safePerson($historical[0]),'historical'=>null];
        }
        $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        $current=q($db,$sql,[$org,$employee,$today,$today])->fetchAll();
        demand(count($current)===1 && (string)$current[0]['effective_from']>$rfqDate,
            $label.' has no confirmed assignment to this organization for the RFQ date. Choose an active employee or review assignment history.',409);
        // Never use an attestation to override a known conflicting historical
        // appointment to another school/division.
        $other=q($db,'SELECT id FROM employee_assignments WHERE employee_id=? AND organization_id<>?
            AND effective_from<=? AND (effective_to IS NULL OR effective_to>?) LIMIT 1',
            [$employee,$org,$rfqDate,$rfqDate])->fetchAll();
        demand(!$other,$label.' has a different organization assignment recorded for the RFQ date; review the source records.',409);
        demand(($input[$attestedKey]??null)===true,
            'Confirm the documented earlier '.$label.' designation for this RFQ date.',422);
        $ref=trim((string)($input[$referenceKey]??''));
        demand(mbSafeLen($ref)>=20 && mbSafeLen($ref)<=500 &&
            !preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$ref),
            'Enter the genuine '.$label.' designation order or supporting reference (20–500 characters).',422);
        return ['person'=>self::safePerson($current[0]),'historical'=>[
            'attested'=>true,'supporting_reference'=>$ref,
            'directory_effective_from'=>(string)$current[0]['effective_from'],
            'rfq_date'=>$rfqDate,'employee_id'=>$employee,
            'validation'=>'Current organization assignment plus explicit historic document attestation; no historical assignment backfill',
        ]];
    }
    private static function safePerson(array $row): array {
        $name=trim((string)($row['full_name']??''));
        demand($name!==''&&mbSafeLen($name)<=160,'Employee name is missing',409);
        return ['employee_id'=>(int)$row['employee_id'],'name'=>$name,
            'designation'=>(string)($row['designation']?:$row['position_title']??'')];
    }
    public static function record(PDO $db,int $organizationId,int $actorId,int $rfqId,array $data): void {
        demand($rfqId>0,'Invalid RFQ reference',409);
        $key=self::KEY_PREFIX.$rfqId;
        demand(one($db,'SELECT id FROM configuration_versions WHERE organization_id=? AND config_key=? LIMIT 1',
            [$organizationId,$key])===null,'RFQ participants already recorded; do not overwrite',409);
        $v=one($db,"SELECT id,version_no,snapshot_json FROM document_versions
            WHERE organization_id=? AND doc_type='RFQ' AND entity_id=? ORDER BY version_no DESC LIMIT 1",[$organizationId,$rfqId]);
        demand($v!==null,'RFQ snapshot was not recorded',500);
        $payload=['rfq_id'=>$rfqId,'source_version_id'=>(int)$v['id'],
            'source_version_no'=>(int)$v['version_no'],'source_sha256'=>hash('sha256',(string)$v['snapshot_json'])]+$data;
        $insert=q($db,'INSERT INTO configuration_versions(organization_id,config_key,config_json,effective_from,review_note)
            VALUES(?,?,?,?,?)',[$organizationId,$key,json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
                $data['issued_at'],'RFQ canvasser and BAC Chairperson recorded at issuance']);
        $recordId=(int)$db->lastInsertId();
        demand($recordId>0 && $insert->rowCount()===1,'RFQ participants could not be recorded',500);
        q($db,'INSERT INTO audit_events(organization_id,actor_id,event_type,entity_type,entity_id,details)
            VALUES(?,?,?,?,?,?)',[$organizationId,$actorId,'RFQ_CANVASS_RECORDED','RFQ',$rfqId,
                json_encode(['source_version_id'=>(int)$v['id'],'bac_term_id'=>$data['bac_term_id'],
                    'bac_chair_employee_id'=>$data['bac_chair']['employee_id'],
                    'canvasser_employee_id'=>$data['canvasser']['employee_id'],
                    'historical_participants'=>$data['historical_participants']??null],JSON_THROW_ON_ERROR)]);
    }
    public static function read(PDO $db,int $org,int $rfqId): ?array {
        $rows=q($db,'SELECT config_json FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 2',
            [$org,self::KEY_PREFIX.$rfqId])->fetchAll();
        demand(count($rows)<=1,'Conflicting RFQ canvass participant records; administrative review required',409);
        if(!$rows)return null; // Historical RFQs predate this requirement.
        $data=json_decode((string)$rows[0]['config_json'],true,32,JSON_THROW_ON_ERROR);
        demand(is_array($data)&&(int)($data['rfq_id']??0)===$rfqId &&
            trim((string)($data['bac_chair']['name']??''))!=='' && trim((string)($data['canvasser']['name']??''))!=='',
            'RFQ canvass participant record requires review',409);
        $source=one($db,"SELECT snapshot_json FROM document_versions WHERE id=? AND organization_id=?
            AND doc_type='RFQ' AND entity_id=?",[(int)($data['source_version_id']??0),$org,$rfqId]);
        demand($source!==null && is_string($data['source_sha256']??null) &&
            hash_equals((string)$data['source_sha256'],hash('sha256',(string)$source['snapshot_json'])),
            'Recorded RFQ signatory source snapshot has changed; administrative review required',409);
        return $data;
    }
}
