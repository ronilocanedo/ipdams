<?php
declare(strict_types=1);
/** v0.2.456 additive schema contract. No schema changes occur on application requests.
 * Settings accepts only these exact statements. MariaDB DDL implicitly commits:
 * verify every pre-existing/retried table before writing its migration ledger row.
 */
final class FinanceSchema {
    public static function definitions(): array {
        $id=['BIGINT UNSIGNED',false,null,'auto_increment'];
        $uid=['BIGINT UNSIGNED',false];$nullable=['BIGINT UNSIGNED',true];
        $stamp=['TIMESTAMP',false,'CURRENT_TIMESTAMP'];
        return [
            '20261126_finance_allocation_sets'=>[
                'table'=>'finance_allocation_sets',
                'columns'=>[
                    'id'=>$id,'organization_id'=>$uid,'dv_id'=>$uid,'bank_account_id'=>$uid,
                    'accounting_version_id'=>$uid,'revision'=>['INT UNSIGNED',false],
                    'active_dv_id'=>$nullable,'state'=>['VARCHAR(16)',false],'predecessor_id'=>$nullable,
                    'gross_cents'=>$uid,'tax_cents'=>$uid,'net_cents'=>$uid,
                    'retained_cents'=>['BIGINT UNSIGNED',false,'0'],
                    'evidence_reference'=>['VARCHAR(255)',false],'created_by'=>$uid,'created_at'=>$stamp,
                ],
                'indexes'=>['PRIMARY'=>[true,['id']],'uq_fas_active_dv'=>[true,['active_dv_id']],
                    'uq_fas_dv_revision'=>[true,['dv_id','revision']],'ix_fas_scope'=>[false,['organization_id','bank_account_id']]],
                'foreign_keys'=>[
                    'fk_fas_org'=>['organization_id','organizations','id'],
                    'fk_fas_dv'=>['dv_id','disbursement_vouchers','id'],
                    'fk_fas_bank'=>['bank_account_id','bank_accounts','id'],
                    'fk_fas_accounting'=>['accounting_version_id','dv_accounting_versions','id'],
                    'fk_fas_actor'=>['created_by','users','id'],
                    'fk_fas_previous'=>['predecessor_id','finance_allocation_sets','id'],
                ],
            ],
            '20261126_finance_allocation_lines'=>[
                'table'=>'finance_allocation_lines',
                'columns'=>['id'=>$id,'allocation_set_id'=>$uid,'download_id'=>$uid,
                    'kind'=>['VARCHAR(8)',false],'source_id'=>$uid,'cents'=>$uid,
                    'uacs_code'=>['VARCHAR(20)',true],'tax_disposition'=>['VARCHAR(32)',true],
                    'evidence_reference'=>['VARCHAR(255)',false]],
                'indexes'=>['PRIMARY'=>[true,['id']],
                    'uq_fal_source'=>[true,['allocation_set_id','download_id','kind','source_id']],
                    'ix_fal_download'=>[false,['download_id']]],
                'foreign_keys'=>['fk_fal_set'=>['allocation_set_id','finance_allocation_sets','id'],
                    'fk_fal_download'=>['download_id','fund_events','id']],
            ],
            '20261126_finance_allocation_events'=>[
                'table'=>'finance_allocation_events',
                'columns'=>['id'=>$id,'allocation_set_id'=>$uid,'check_id'=>$nullable,'payment_event_id'=>$nullable,
                    'event_type'=>['VARCHAR(32)',false],'cash_delta_cents'=>['BIGINT',false,'0'],
                    'event_key'=>['VARCHAR(120)',false],'evidence_reference'=>['VARCHAR(255)',false],
                    'created_by'=>$uid,'created_at'=>$stamp],
                'indexes'=>['PRIMARY'=>[true,['id']],'uq_fae_event'=>[true,['event_key']],
                    'ix_fae_set'=>[false,['allocation_set_id']]],
                'foreign_keys'=>['fk_fae_set'=>['allocation_set_id','finance_allocation_sets','id'],
                    'fk_fae_check'=>['check_id','checks','id'],
                    'fk_fae_payment'=>['payment_event_id','payment_events','id'],'fk_fae_actor'=>['created_by','users','id']],
            ],
            '20261126_finance_bank_sources'=>[
                'table'=>'bank_reconciliation_sources',
                'columns'=>['id'=>$id,'organization_id'=>$uid,'bank_account_id'=>$uid,
                    'kind'=>['VARCHAR(32)',false],'effective_date'=>['DATE',false],
                    'amount'=>['DECIMAL(18,2)',false],'evidence_reference'=>['VARCHAR(255)',false],
                    'statement_reference'=>['VARCHAR(255)',false],'verification_reference'=>['VARCHAR(255)',false],
                    'source_reference'=>['VARCHAR(100)',false],'fund_event_id'=>$nullable,'accounting_line_id'=>$nullable,
                    'created_by'=>$uid,'created_at'=>$stamp],
                'indexes'=>['PRIMARY'=>[true,['id']],
                    'uq_brs_source'=>[true,['bank_account_id','source_reference']],
                    'ix_brs_scope'=>[false,['organization_id','bank_account_id','effective_date','id']]],
                'foreign_keys'=>['fk_brs_org'=>['organization_id','organizations','id'],
                    'fk_brs_bank'=>['bank_account_id','bank_accounts','id'],
                    'fk_brs_credit'=>['fund_event_id','fund_events','id'],
                    'fk_brs_accounting'=>['accounting_line_id','voucher_accounting_lines','id'],'fk_brs_actor'=>['created_by','users','id']],
            ],
        ];
    }
    public static function statements(): array {
        $out=[];
        foreach(self::definitions() as $key=>$spec){
            $parts=[];
            foreach($spec['columns'] as $name=>$column){
                [$type,$nullable]=$column;$part=$name.' '.$type.($nullable?' NULL':' NOT NULL');
                if(isset($column[2]))$part.=' DEFAULT '.$column[2];
                if(($column[3]??'')==='auto_increment')$part.=' AUTO_INCREMENT';
                $parts[]=$part;
            }
            foreach($spec['indexes'] as $name=>[$unique,$columns])
                $parts[]=($name==='PRIMARY'?'PRIMARY KEY':($unique?'UNIQUE KEY ':'KEY ').$name).' ('.implode(', ',$columns).')';
            foreach($spec['foreign_keys'] as $name=>[$column,$table,$reference])
                $parts[]='CONSTRAINT '.$name.' FOREIGN KEY ('.$column.') REFERENCES '.$table.'('.$reference.')';
            $out[$key]='CREATE TABLE IF NOT EXISTS '.$spec['table'].' ( '.implode(', ',$parts).' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        }
        return $out;
    }
    public static function migrationKey(string $sql): ?string {
        $normalized=preg_replace('/\s+/',' ',trim($sql));
        foreach(self::statements() as $id=>$approved)if(hash_equals($approved,$normalized))return $id;
        return null;
    }
    public static function exists(PDO $db,string $key): bool {
        $spec=self::definitions()[$key]??null;demand(is_array($spec),'Unknown finance migration',409);
        return (int)q($db,'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$spec['table']])->fetchColumn()===1;
    }
    private static function type(string $type): string {
        return preg_replace('/\b(bigint|int)\([0-9]+\)/','$1',strtolower($type));
    }
    private static function defaultValue(mixed $value): ?string {
        if($value===null||strtoupper((string)$value)==='NULL')return null;
        $value=strtolower(trim((string)$value,"'"));
        return $value==='current_timestamp()'?'current_timestamp':$value;
    }
    public static function verify(PDO $db,string $key): void {
        $spec=self::definitions()[$key]??null;demand(is_array($spec),'Unknown finance migration',409);$table=$spec['table'];
        $actual=one($db,'SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]);
        demand($actual!==null&&$actual['ENGINE']==='InnoDB'&&$actual['TABLE_COLLATION']==='utf8mb4_unicode_ci','Finance table engine/collation mismatch: '.$table,409);
        $columns=q($db,'SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION',[$table])->fetchAll();
        demand(array_column($columns,'COLUMN_NAME')===array_keys($spec['columns']),'Finance table column inventory/order mismatch: '.$table,409);
        foreach($columns as $column){
            $name=$column['COLUMN_NAME'];$expected=$spec['columns'][$name];
            demand(self::type($column['COLUMN_TYPE'])===self::type($expected[0])
                &&$column['IS_NULLABLE']===($expected[1]?'YES':'NO')
                &&self::defaultValue($column['COLUMN_DEFAULT'])===self::defaultValue($expected[2]??null)
                &&strtolower($column['EXTRA'])===($expected[3]??'')
                &&($column['COLLATION_NAME']===null||$column['COLLATION_NAME']==='utf8mb4_unicode_ci'),
                'Finance table column definition mismatch: '.$table.'.'.$name,409);
        }
        $rows=q($db,'SELECT INDEX_NAME,NON_UNIQUE,COLUMN_NAME,SEQ_IN_INDEX,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX',[$table])->fetchAll();
        $indexes=[];
        foreach($rows as $row){
            demand($row['SUB_PART']===null&&$row['INDEX_TYPE']==='BTREE','Finance index prefix/type mismatch: '.$table,409);
            $indexes[$row['INDEX_NAME']]??=[(int)$row['NON_UNIQUE']===0,[]];$indexes[$row['INDEX_NAME']][1][]=$row['COLUMN_NAME'];
        }
        foreach($spec['indexes'] as $name=>$expected)demand(($indexes[$name]??null)===$expected,'Finance index/uniqueness mismatch: '.$table.'.'.$name,409);
        // MariaDB creates nonunique FK supporting indexes when no declared index covers them.
        $allowed=array_keys($spec['indexes']);
        foreach($spec['foreign_keys'] as $name=>[$column]){
            $covered=false;foreach($spec['indexes'] as [, $names])if($names[0]===$column)$covered=true;
            if(!$covered){$allowed[]=$name;demand(($indexes[$name]??null)===[false,[$column]],'Finance FK supporting index mismatch: '.$table.'.'.$name,409);}
        }
        demand(!array_diff(array_keys($indexes),$allowed),'Unexpected finance table index: '.$table,409);
        $rows=q($db,'SELECT k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE,r.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=? AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION',[$table])->fetchAll();
        $foreign=[];
        foreach($rows as $row){
            demand(in_array($row['DELETE_RULE'],['RESTRICT','NO ACTION'],true)&&in_array($row['UPDATE_RULE'],['RESTRICT','NO ACTION'],true),'Finance FK action mismatch: '.$table,409);
            $foreign[$row['CONSTRAINT_NAME']]=[$row['COLUMN_NAME'],$row['REFERENCED_TABLE_NAME'],$row['REFERENCED_COLUMN_NAME']];
        }
        ksort($foreign);$expected=$spec['foreign_keys'];ksort($expected);
        demand($foreign===$expected,'Finance foreign key inventory/reference mismatch: '.$table,409);
    }
    public static function verifyAll(PDO $db): void {foreach(array_keys(self::definitions()) as $key)self::verify($db,$key);}
}
