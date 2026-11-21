<?php
declare(strict_types=1);
/**
 * M82 schema-only evidence scanner. No application record queries.
 * This file contains only a deliberately bounded, reviewable compatibility
 * contract. It is not a complete model of the production schema.
 */
final class M82SchemaContract {
    public const VERSION='M82-FINANCIAL-CORE-1';
    /** @return array<string,array{columns:array<string,string>,unique?:list<list<string>>}> */
    public static function requirements(): array {
        return [
            'organizations'=>['columns'=>['id'=>'bigint','kind'=>'enum','parent_division_id'=>'bigint']],
            'users'=>['columns'=>['id'=>'bigint','role'=>'enum','active'=>'tinyint']],
            'user_organization_scopes'=>[
                'columns'=>['user_id'=>'bigint','organization_id'=>'bigint'],
                'unique'=>[['user_id','organization_id']]],
            'funds'=>[
                'columns'=>['id'=>'bigint','organization_id'=>'bigint','source_code'=>'varchar',
                    'fiscal_year'=>'smallint'],
                'unique'=>[['organization_id','fiscal_year','source_code']]],
            'fund_events'=>['columns'=>[
                'id'=>'bigint','organization_id'=>'bigint','fund_id'=>'bigint',
                'event_type'=>'enum','amount'=>'decimal(18,2)',
                'bank_account_id'=>'bigint','bank_credit_date'=>'varchar',
                'bank_credit_ref'=>'varchar','bank_advice_ref'=>'varchar',
                'bank_evidence_ref'=>'varchar']],
            'purchase_requests'=>['columns'=>[
                'id'=>'bigint','organization_id'=>'bigint','fund_id'=>'bigint',
                'status'=>'enum','total'=>'decimal(18,2)','document_date'=>'varchar']],
            'suppliers'=>['columns'=>['id'=>'bigint','name'=>'varchar',
                'business_classification'=>'varchar|enum','proprietor_name'=>'varchar']],
            'disbursement_vouchers'=>['columns'=>[
                'id'=>'bigint','organization_id'=>'bigint','fund_id'=>'bigint',
                'dv_type'=>'enum','status'=>'enum','gross'=>'decimal(18,2)',
                'deductions'=>'decimal(18,2)','net'=>'decimal(18,2)',
                'supplier_id'=>'bigint','business_name_snapshot'=>'varchar',
                'proprietor_name_snapshot'=>'varchar']],
            'dv_allocation_lines'=>['columns'=>[
                'id'=>'bigint','dv_id'=>'bigint','pr_id'=>'bigint',
                'iar_line_id'=>'bigint','amount'=>'decimal(18,2)']],
            'uacs_accounts'=>['columns'=>['code'=>'varchar','account_name'=>'varchar',
                'active'=>'tinyint']],
            'dv_accounting_versions'=>[
                'columns'=>['id'=>'bigint','dv_id'=>'bigint','version_no'=>'int'],
                'unique'=>[['dv_id','version_no']]],
            'voucher_accounting_lines'=>['columns'=>[
                'id'=>'bigint','accounting_version_id'=>'bigint','uacs_code'=>'varchar',
                'debit'=>'decimal(18,2)','credit'=>'decimal(18,2)']],
            'document_counters'=>[
                'columns'=>['organization_id'=>'bigint','doc_type'=>'varchar',
                    'period'=>'char','next_sequence'=>'int'],
                'unique'=>[['organization_id','doc_type','period']]],
            'document_registry'=>[
                'columns'=>['id'=>'bigint','organization_id'=>'bigint',
                    'doc_type'=>'varchar','display_number'=>'char','entity_id'=>'bigint'],
                'unique'=>[['organization_id','doc_type','display_number'],
                    ['doc_type','entity_id']]],
            'document_versions'=>[
                'columns'=>['id'=>'bigint','doc_type'=>'varchar',
                    'entity_id'=>'bigint','version_no'=>'int'],
                'unique'=>[['doc_type','entity_id','version_no']]],
            'idempotency_keys'=>[
                'columns'=>['user_id'=>'bigint','request_key'=>'varchar',
                    'response_json'=>'json|longtext'],
                'unique'=>[['user_id','request_key']]],
            'audit_events'=>['columns'=>[
                'id'=>'bigint','organization_id'=>'bigint','actor_id'=>'bigint',
                'event_type'=>'varchar','entity_type'=>'varchar',
                'entity_id'=>'bigint','details'=>'json|longtext']],
            'configuration_versions'=>['columns'=>[
                'id'=>'bigint','organization_id'=>'bigint','config_key'=>'varchar',
                'config_json'=>'json|longtext','effective_from'=>'date',
                'review_note'=>'varchar']],
            'bank_accounts'=>['columns'=>['id'=>'bigint','organization_id'=>'bigint']],
            'checks'=>[
                'columns'=>['id'=>'bigint','bank_account_id'=>'bigint','dv_id'=>'bigint',
                    'check_number'=>'varchar','amount'=>'decimal(18,2)','status'=>'enum'],
                'unique'=>[['bank_account_id','check_number']]],
        ];
    }
    /** Convert ONLY information_schema metadata to a minimal, non-sensitive inventory. */
    public static function inventory(PDO $db): array {
        $columns=$db->query("SELECT TABLE_NAME,COLUMN_NAME,DATA_TYPE,NUMERIC_PRECISION,NUMERIC_SCALE
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()")->fetchAll(PDO::FETCH_ASSOC);
        $indexes=$db->query("SELECT TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX,COLUMN_NAME,NON_UNIQUE
            FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
            ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX")->fetchAll(PDO::FETCH_ASSOC);
        $want=self::requirements();
        $observed=[];
        foreach($columns as $row){
            $table=(string)$row['TABLE_NAME'];
            $column=(string)$row['COLUMN_NAME'];
            if(!isset($want[$table]['columns'][$column]))continue;
            $observed[$table]['columns'][$column]=[
                'type'=>strtolower((string)$row['DATA_TYPE']),
                'precision'=>$row['NUMERIC_PRECISION']===null?null:(int)$row['NUMERIC_PRECISION'],
                'scale'=>$row['NUMERIC_SCALE']===null?null:(int)$row['NUMERIC_SCALE']];
        }
        $grouped=[];
        foreach($indexes as $row){
            $table=(string)$row['TABLE_NAME'];
            if(!isset($want[$table]) || (int)$row['NON_UNIQUE']!==0)continue;
            $name=(string)$row['INDEX_NAME'];
            $seq=(int)$row['SEQ_IN_INDEX'];
            // Null means functional/expression index; it cannot satisfy a
            // composite business uniqueness invariant.
            $grouped[$table][$name][$seq]=$row['COLUMN_NAME']===null?'':(string)$row['COLUMN_NAME'];
        }
        foreach($grouped as $table=>$list){
            foreach($list as $columnsBySeq){
                ksort($columnsBySeq,SORT_NUMERIC);
                $observed[$table]['unique'][]=array_values($columnsBySeq);
            }
        }
        return $observed;
    }
    /** Show only restricted metadata tokens, never raw schema definitions or values. */
    private static function safeTypeLabel(string $dataType,?int $precision,?int $scale): string {
        $dataType=strtolower($dataType);
        if(!preg_match('/^[a-z][a-z0-9_]{0,31}$/D',$dataType))return 'unrecognized type';
        if($dataType==='decimal' && $precision!==null && $scale!==null){
            return 'decimal('.min(999,max(0,$precision)).','.min(99,max(0,$scale)).')';
        }
        return $dataType;
    }
    /** Result intentionally contains only allowlisted schema paths and issues. */
    public static function diagnose(array $inventory): array {
        $issues=[];$checked=0;
        foreach(self::requirements() as $table=>$spec){
            $have=$inventory[$table]['columns']??[];
            if(!$have){
                // Detect whether there are any observed required columns.
                $issues[]=['code'=>'MISSING_TABLE_OR_ALL_COLUMNS','path'=>$table];
                // Do not emit a separate missing-column message for every
                // field in a missing table.
                continue;
            }
            foreach($spec['columns'] as $column=>$type){
                $checked++;
                if(!isset($have[$column])){
                    $issues[]=['code'=>'MISSING_COLUMN','path'=>$table.'.'.$column];
                    continue;
                }
                $actual=strtolower((string)($have[$column]['type']??''));
                $actualType=self::safeTypeLabel($actual,
                    isset($have[$column]['precision'])?(int)$have[$column]['precision']:null,
                    isset($have[$column]['scale'])?(int)$have[$column]['scale']:null);
                $accepted=explode('|',$type);
                if(preg_match('/^decimal\((\d+),(\d+)\)$/',$type,$m)){
                    $precision=(int)($have[$column]['precision']??0);
                    $scale=(int)($have[$column]['scale']??-1);
                    if($actual!=='decimal'||$precision<(int)$m[1]||$scale!==(int)$m[2]){
                        $issues[]=['code'=>'INCOMPATIBLE_MONEY_COLUMN',
                            'path'=>$table.'.'.$column,
                            'expected_type'=>$type,'detected_type'=>$actualType];
                    }
                }elseif(!in_array($actual,$accepted,true)){
                    $issues[]=['code'=>'INCOMPATIBLE_COLUMN_TYPE',
                        'path'=>$table.'.'.$column,
                        'expected_type'=>$type,'detected_type'=>$actualType];
                }
            }
            foreach($spec['unique']??[] as $required){
                $checked++;
                $found=false;
                foreach($inventory[$table]['unique']??[] as $actual){
                    if($actual===$required){$found=true;break;}
                }
                if(!$found)$issues[]=['code'=>'MISSING_UNIQUE_CONSTRAINT',
                    'path'=>$table.'.('.implode(',',$required).')'];
            }
        }
        usort($issues,static fn($a,$b)=>strcmp($a['path'].'/'.$a['code'],$b['path'].'/'.$b['code']));
        return [
            'contract_version'=>self::VERSION,
            'assessment'=>count($issues)?'NOT_VERIFIED':'MATCHES_BOUNDED_CONTRACT',
            'checked_elements'=>$checked,
            'issues'=>$issues,
            'limitations'=>[
                'Contract is a selected financial/API subset, not complete schema parity.',
                'No financial, supplier, employee or user data are queried.',
                'Matching this contract does not certify finance policy or deployment readiness.',
            ],
        ];
    }
}
