<?php
declare(strict_types=1);
/**
 * Paper-only, authenticated document previews matching DepEd Appendix 63 RIS
 * and Appendix 59 ICS. This class never creates or edits a record.
 * No signatures, approval dates, costs or fund codes are invented.
 */
final class OfficialAccountabilityPrint
{
    private static function h(mixed $v): string
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    private static function cell(mixed $v): string
    {
        return self::h($v) ?: '&nbsp;';
    }
    private static function qty(mixed $qty): string
    {
        $v = trim((string)($qty ?? ''));
        if ($v === '') return '';
        if (!is_numeric($v)) return '';
        return rtrim(rtrim(number_format((float)$v, 3, '.', ','), '0'), '.');
    }
    private static function money(mixed $amount): string
    {
        if ($amount === null || $amount === '' || !is_numeric((string)$amount)) return '';
        return number_format((float)$amount, 2, '.', ',');
    }
    private static function itemDescription(array $item): string
    {
        $html = '<div class="item-primary">'.self::cell($item['name'] ?? null).'</div>';
        $parts = [];
        foreach (['brand'=>'Brand', 'model'=>'Model', 'serial_number'=>'Serial No.'] as $key=>$label) {
            $value = trim((string)($item[$key] ?? ''));
            if ($value !== '') $parts[] = '<span>'.self::h($label).': '.self::h($value).'</span>';
        }
        if ($parts) $html .= '<div class="item-identifiers">'.implode(' <span aria-hidden="true">·</span> ', $parts).'</div>';
        return $html;
    }
    private static function wrap(string $title, string $body): string
    {
        $title = self::h($title);
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$title.'</title>'.
            '<link rel="stylesheet" href="../assets/ics-ris-pair-print.css?v=0292-compact-pair-details"></head>'.
            '<body class="formal-accountability-print"><div class="print-toolbar">'.
            '<button type="button" id="print-button">Print form</button>'.
            '<span>For physical signatures. Printing does not record a new issue, approval, or receipt.</span></div>'.
            '<main class="print-pages">'.$body.'</main><script src="../assets/official-print-init.js?v=0291" defer></script></body></html>';
    }
    private static function signatureGrid(array $participants): string
    {
        $roles = ''; $names = ''; $positions = ''; $dates = ''; $signatures = '';
        foreach ($participants as $person) {
            $roles .= '<th class="role-head">'.self::h($person['role']).'</th>';
            $signatures .= '<td class="sign-space"></td>';
            $names .= '<td class="sig-name">'.self::cell($person['name'] ?? null).'</td>';
            $positions .= '<td class="sig-position">'.self::cell($person['designation'] ?? null).'</td>';
            $dates .= '<td class="sig-date">'.self::cell($person['date'] ?? null).'</td>';
        }
        return '<table class="ris-signatures"><colgroup><col class="ris-sig-label">'.str_repeat('<col class="ris-sig-person">', 4).'</colgroup>'.
            '<thead><tr><th></th>'.$roles.'</tr></thead><tbody>'.
            '<tr><th class="row-title">Signature:</th>'.$signatures.'</tr>'.
            '<tr><th class="row-title">Printed Name:</th>'.$names.'</tr>'.
            '<tr><th class="row-title">Designation:</th>'.$positions.'</tr>'.
            '<tr><th class="row-title">Date:</th>'.$dates.'</tr></tbody></table>';
    }
    public static function ris(array $d): string
    {
        $number = trim((string)($d['number'] ?? ''));
        $lines = $d['lines'] ?? [];
        if ($number === '' || !is_array($lines) || !$lines || count($lines) > 100) {
            throw new RuntimeException('Issued RIS and line items are required for printing.');
        }
        $rows = '';
        foreach ($lines as $i => $line) {
            $requested = self::qty($line['requested_qty'] ?? null);
            $issued = self::qty($line['issued_qty'] ?? null);
            $yes = is_numeric($line['issued_qty'] ?? null) && (float)$line['issued_qty'] > 0 ? '&#10003;' : '';
            $rows .= '<tr class="item-row"><td class="center">'.($i+1).'</td>'.
                '<td class="center">'.self::cell($line['unit'] ?? 'unit').'</td>'.
                '<td>'.self::itemDescription($line).'</td><td class="center">'.self::cell($requested).'</td>'.
                '<td class="center">'.$yes.'</td><td></td><td class="center">'.self::cell($issued).'</td><td></td></tr>';
        }
        $rows .= '<tr class="blank-row"><td></td><td></td><td colspan="6" class="nothing-follows">*** NOTHING FOLLOWS ***</td></tr>';
        $rows .= str_repeat('<tr class="blank-row">'.str_repeat('<td></td>',8).'</tr>',max(0,7-count($lines)));
        $participants = [
            ['role'=>'Requested by:','name'=>(string)($d['requestor'] ?? '')],
            ['role'=>'Approved by:'],['role'=>'Issued by:'],['role'=>'Received by:'],
        ];
        $html = '<section class="form-sheet ris-sheet"><div class="appendix">Appendix 63</div>'.
            '<h1 class="form-heading">REQUISITION AND ISSUE SLIP</h1><div class="form-head">'.
            '<div class="head-pair"><span>Entity Name:</span><strong>DEPARTMENT OF EDUCATION</strong></div>'.
            '<div class="head-pair align-right"><span>Fund Cluster:</span><strong>'.self::cell($d['fund_cluster'] ?? null).'</strong></div></div>'.
            '<table class="ris-meta"><tbody><tr><td><span class="label">Division:</span><strong>'.self::cell($d['division_name'] ?? null).'</strong></td>'.
            '<td><span class="label">Responsibility Center Code:</span><strong>'.self::cell($d['rcc'] ?? null).'</strong></td></tr>'.
            '<tr><td><span class="label">Office:</span><strong>'.self::cell($d['school_name'] ?? null).'</strong></td>'.
            '<td><span class="label">RIS No.:</span><strong>'.self::h($number).'</strong></td></tr></tbody></table>'.
            '<table class="ris-table"><colgroup><col class="ris-stock"><col class="ris-unit"><col class="ris-description"><col class="ris-quantity"><col class="ris-yes"><col class="ris-no"><col class="ris-issue"><col class="ris-remarks"></colgroup>'.
            '<thead><tr><th colspan="4">Requisition</th><th colspan="2">Stock Available?</th><th colspan="2">Issue</th></tr>'.
            '<tr><th>Stock<br>No.</th><th>Unit</th><th>Description</th><th>Quantity</th><th>Yes</th><th>No</th><th>Quantity</th><th>Remarks</th></tr></thead>'.
            '<tbody>'.$rows.'</tbody></table>'.
            '<div class="ris-purpose"><strong>Purpose:</strong>'.self::cell($d['purpose'] ?? null).'</div>'.
            self::signatureGrid($participants).'</section>';
        return self::wrap('RIS '.$number, $html);
    }
    private static function icsSigner(string $role, array $p): string
    {
        return '<div class="sign-party"><div class="sign-label">'.self::h($role).'</div>'.
            '<div class="sign-name">'.self::cell($p['name'] ?? null).'</div><div class="sign-caption">Signature Over Printed Name</div>'.
            '<div class="sign-designation">'.self::cell($p['designation'] ?? null).'</div>'.
            '<div class="sign-caption">Position / Office</div>'.
            '<div class="sign-date">'.self::cell($p['date'] ?? null).'</div><div class="date-caption">Date</div></div>';
    }
    public static function ics(array $d): string
    {
        $number = trim((string)($d['number'] ?? ''));
        $items = $d['items'] ?? [];
        if ($number === '' || !is_array($items) || !$items || count($items) > 20) {
            throw new RuntimeException('Finalized ICS and saved property membership required.');
        }
        $rows = '';
        foreach ($items as $item) {
            if (trim((string)($item['property_tag'] ?? '')) === '' || trim((string)($item['name'] ?? '')) === '') {
                throw new RuntimeException('Incomplete ICS property membership.');
            }
            $price = self::money($item['cost'] ?? null);
            $rows .= '<tr class="item-row"><td class="center">1</td><td class="center">'.self::cell($item['unit'] ?? 'unit').'</td>'.
                '<td class="money">'.self::cell($price).'</td><td class="money">'.self::cell($price).'</td>'.
                '<td>'.self::itemDescription($item).'</td><td class="center">'.self::h($item['property_tag']).'</td>'.
                '<td class="center">'.self::cell($item['useful_life'] ?? null).'</td></tr>';
        }
        $rows .= str_repeat('<tr class="blank-row">'.str_repeat('<td></td>',7).'</tr>',max(0,min(5,10-count($items))));
        $html = '<section class="form-sheet ics-sheet"><div class="appendix">Appendix 59</div>'.
            '<h1 class="form-heading ics-title">INVENTORY CUSTODIAN SLIP</h1>'.
            '<div class="form-head ics-head"><div class="head-pair"><span>Entity Name:</span><strong>DEPARTMENT OF EDUCATION</strong></div><div></div>'.
            '<div class="head-pair"><span>Fund Cluster:</span><strong>'.self::cell($d['fund_cluster'] ?? null).'</strong></div>'.
            '<div class="head-record-number">ICS No.: <strong>'.self::h($number).'</strong></div></div>'.
            '<table class="ics-table"><colgroup><col class="ics-qty"><col class="ics-unit"><col class="ics-cost"><col class="ics-total"><col class="ics-description"><col class="ics-tag"><col class="ics-life"></colgroup>'.
            '<thead><tr><th rowspan="2">Quantity</th><th rowspan="2">Unit</th><th colspan="2">Amount</th><th rowspan="2">Description</th><th rowspan="2">Inventory<br>Item No.</th><th rowspan="2">Estimated<br>Useful Life</th></tr>'.
            '<tr><th>Unit Cost</th><th>Total Cost</th></tr></thead><tbody>'.$rows.'</tbody></table>'.
            '<div class="ics-signatures">'.
            self::icsSigner('Received from:',(array)($d['issued_by'] ?? [])).
            self::icsSigner('Receive by:',(array)($d['received_by'] ?? [])).'</div></section>';
        return self::wrap('ICS '.$number,$html);
    }
}
