<?php
declare(strict_types=1);

/**
 * Read-only RFQ quotation availability calculated from the source PR/RFQ lines
 * and non-cancelled, already-issued PO lines. No inferred delivery or DV status.
 * A previous award also closes a quotation cycle, even if the PO is partial.
 */
final class RfqQuotationEligibility {
    private static function milli(mixed $raw): ?int {
        if(!is_string($raw) && !is_int($raw) && !is_float($raw)) return null;
        $s=trim((string)$raw);
        if(!preg_match('/^(\d{1,12})(?:\.(\d{1,3}))?$/D',$s,$m))return null;
        $whole=(int)$m[1];
        if($whole>9000000000000)return null;
        return $whole*1000+(int)str_pad($m[2]??'',3,'0');
    }
    /** Evaluates one saved RFQ, never assumes an empty/invalid selection is fulfilled. */
    public static function one(array $rfq,array $prLines,array $poQuantities): array {
        $valid=true;$selected=[];
        $raw=$rfq['selected_lines_json']??null;
        if($raw===null||trim((string)$raw)===''){
            // Legacy RFQs covered the complete originating PR.
            foreach($prLines as $line){$selected[]=['pr_line_id'=>$line['id'],'quantity'=>$line['quantity']];}
        } else {
            $decoded=json_decode((string)$raw,true);
            if(!is_array($decoded))$valid=false;
            elseif(count($decoded)===0){
                // Match the original legacy fallback, without treating zero lines as purchased.
                foreach($prLines as $line){$selected[]=['pr_line_id'=>$line['id'],'quantity'=>$line['quantity']];}
            } else $selected=$decoded;
        }
        $prIndex=[];
        foreach($prLines as $line)$prIndex[(int)$line['id']]=$line;
        $seen=[];$itemCount=0;$orderedCount=0;$anyOrder=false;
        foreach($selected as $entry){
            $id=(int)($entry['pr_line_id']??0);
            $qty=self::milli($entry['quantity']??null);
            if($id<=0||$qty===null||$qty<=0||!isset($prIndex[$id])||isset($seen[$id])){$valid=false;break;}
            $prQty=self::milli($prIndex[$id]['quantity']??null);
            if($prQty===null||$prQty<=0||$qty>$prQty){$valid=false;break;}
            $seen[$id]=true;$itemCount++;
            $ordered=self::milli($poQuantities[$id]??'0');
            if($ordered===null){$valid=false;break;}
            if($ordered>0)$anyOrder=true;
            if($ordered>=$qty)$orderedCount++;
        }
        if($itemCount===0)$valid=false;
        $fullyOrdered=$valid&&$orderedCount===$itemCount;
        $awarded=(int)($rfq['award_count']??0)>0;
        $quoted=(int)($rfq['quote_count']??0)>0;
        $closed=$fullyOrdered||$awarded||$anyOrder;
        $allowed=$valid&&$rfq['status']==='FINALIZED'&&!$closed;
        $state=!$valid?'REVIEW_REQUIRED':($fullyOrdered?'FULLY_ORDERED':($awarded?'AWARDED':($anyOrder?'PARTLY_ORDERED':($quoted?'ALREADY_QUOTED':'NEEDS_QUOTATION'))));
        return [
            'rfq_item_count'=>$itemCount,'ordered_item_count'=>$orderedCount,
            'fully_ordered'=>$fullyOrdered?1:0,'quote_entry_allowed'=>$allowed?1:0,
            'quotation_state'=>$state,
        ];
    }
    /** All input rows are pre-scoped by the caller to the same organization. */
    public static function annotate(array $rfqs,array $prLines,array $poRows): array {
        $byPr=[];$orders=[];
        foreach($prLines as $line)$byPr[(int)$line['pr_id']][]=$line;
        foreach($poRows as $po)$orders[(int)$po['pr_line_id']]=(string)$po['ordered_quantity'];
        foreach($rfqs as &$rfq){
            $rfq+=self::one($rfq,$byPr[(int)$rfq['pr_id']]??[],$orders);
            unset($rfq['selected_lines_json']); // do not send raw source snapshots to the browser
        }unset($rfq);
        return $rfqs;
    }
}
