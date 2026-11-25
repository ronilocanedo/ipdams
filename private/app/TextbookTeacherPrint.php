<?php
declare(strict_types=1);
require_once __DIR__.'/TextbookLifecycleWorkflow.php';
/** Printable supporting stewardship slip; no electronic signature or approval is implied. */
final class TextbookTeacherPrint {
    private static function h(mixed $value):string {return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    public static function render(PDO $db,array $user,array $request):string {
        $svc=new TextbookLifecycleWorkflow($db,$user);
        $r=$svc->teacherDetail($request);$s=$r['snapshot'];
        $o=one($db,'SELECT name FROM organizations WHERE id=? AND kind=\'SCHOOL\' AND active=1',[$r['org']]);
        demand($o!==null,'School unavailable',403);
        $custodian=(string)($s['appointed_issuer_name']??'');
        $sum=(int)$s['issued_quantity']-(int)$s['returned_serviceable']-(int)$s['returned_damaged'];
        $history='';foreach($s['events']??[] as $event){
            $units=(int)($event['serviceable_quantity']??0)+(int)($event['damaged_quantity']??0)
                 +(int)($event['lost_quantity']??0)+(int)($event['damage_reported_quantity']??0);
            $history.='<tr><td>'.self::h($event['event_on']).'</td><td>'.self::h($event['kind']).'</td><td>'.self::h($units).'</td><td>'.self::h($event['paper_reference']).'</td></tr>';
        }
        if($history==='')$history='<tr><td colspan="4">No subsequent return / loss / damage recorded.</td></tr>';
        $html='<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Textbook Teacher Accountability Slip</title>';
        $html.='<style>body{font:13px Bookman Old Style,Georgia,serif;max-width:820px;margin:30px auto;color:#172538;padding:0 15px}h1{text-align:center;font-size:19px;margin:10px 0}h2{font-size:14px}table{border-collapse:collapse;width:100%;margin:18px 0}td,th{border:1px solid #8292a0;padding:7px;vertical-align:top;text-align:left}th{background:#e8eef2}.muted{color:#445467}.sign{display:flex;gap:20px;justify-content:space-between;margin-top:70px}.sign div{width:46%;text-align:center;border-top:1px solid #333;padding:8px 2px}.toolbar{margin-bottom:20px;text-align:right}.toolbar button{padding:8px 14px}@media print{.toolbar{display:none}body{margin:0 auto}}</style></head><body>';
        $html.='<div class="toolbar"><button type="button" onclick="window.print()">Print paper slip</button></div><h1>TEXTBOOK TEACHER ACCOUNTABILITY SLIP</h1>';
        $html.='<p style="text-align:center">'.self::h($o['name']).'</p><p><b>Reference:</b> '.self::h($r['number']).' &nbsp; <b>Actual issue date:</b> '.self::h($s['issued_on']).'</p>';
        $html.='<table><tr><th>Teacher / recipient</th><td>'.self::h($s['teacher_name']).'</td></tr>';
        $html.='<tr><th>Book title</th><td>'.self::h($s['title']).'</td></tr><tr><th>ISBN / batch</th><td>'.self::h($s['isbn']).' / '.self::h($s['batch']).'</td></tr>';
        $html.='<tr><th>Issued copies</th><td>'.self::h($s['issued_quantity']).'</td></tr><tr><th>Returned, serviceable</th><td>'.self::h($s['returned_serviceable']).'</td></tr>';
        $html.='<tr><th>Returned, damaged / quarantined</th><td>'.self::h($s['returned_damaged']).'</td></tr>';
        $html.='<tr><th>Reported loss pending relief</th><td>'.self::h($s['lost_pending']).'</td></tr><tr><th>Copies still unreturned (incl. reported loss)</th><td>'.self::h($sum).'</td></tr>';
        $html.='<tr><th>Physical paper source</th><td>'.self::h($s['paper_reference']).'</td></tr></table>';
        $html.='<h2>Documented subsequent events</h2><table><thead><tr><th>Date</th><th>Event</th><th>Copies</th><th>Paper evidence</th></tr></thead><tbody>'.$history.'</tbody></table>';
        $html.='<div class="sign"><div>'.self::h($custodian).'<br/>Issuing Property Custodian · physical signature</div><div>'.self::h($s['teacher_name']).'<br/>Receiving teacher · physical signature</div></div>';
        $html.='<p class="muted" style="margin-top:35px">IPDAMS is a documentary register. The names above are printed for manual physical signature, not electronic approval. Losses require the prescribed relief process before accountability write-off.</p></body></html>';
        return $html;
    }
}
