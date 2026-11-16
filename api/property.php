<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/private/app/bootstrap.php';
require_once dirname(__DIR__).'/private/app/DivisionSchoolAccess.php';
ini_set('session.use_strict_mode','1');
ini_set('session.cookie_httponly','1');
session_name('IPDAMSSESSID');
session_start();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; connect-src 'self'; form-action 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
try {
    $db=database();$user=currentUser($db);
    demand($user['role']!=='VIEWER','Use the issued monthly document register',403);
    $targetOrg=(int)($_GET['organization_id']??0);
    if(DivisionSchoolAccess::isDivisionSchool($db,$user,$targetOrg))
        demand(false,'Use the authorized ICS/PAR issuance panels in the main workspace, not the asset register',403);
} catch (Throwable $e) {
    http_response_code($e instanceof DomainError?$e->status:401);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Sign in required</title><body><p>Sign in to IPDAMS before opening property records.</p><a href="../index.php">Return to IPDAMS sign-in</a></body></html>';
    exit;
}
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="en"><head><base href="../">
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>IPDAMS · Property accountability staging workspace</title>
<link rel="stylesheet" href="assets/property-workspace.css">
<script src="assets/property-workspace.js" defer></script>
<script src="assets/property-auto-registration.js?v=0213" defer></script>
</head><body>
<header class="site-head"><div><strong>IPDAMS</strong><span> Property and accountability workspace</span></div><a href="index.php">← Main IPDAMS</a></header>
<main>
<div class="banner"><strong>Staging review · Not an official ICS/PAR approval</strong><p>Registration automatically generates a unique school property number per individual accepted property unit. Custody issuance creates a numbered working slip; manual signatures and approved policy are still required. Financial and existing stock ledgers are not changed by property tagging.</p></div>
<div id="message" role="status" aria-live="polite"></div>
<label>Authorized organization <select id="org" aria-label="Authorized organization"></select></label>
<div class="columns">
<section><h2>1 · Register received property</h2>
<p>Choose an accepted IAR line. The system issues school-specific property numbers automatically with an all-time sequence.</p>
<form id="registration">
<label>IAR / received item<select id="iar-line" required></select></label>
<p id="iar-info" class="hint"></p>
<label>Number of individual units to register<input id="property-tags" type="number" min="1" max="100" step="1" value="1" required></label>
<p class="hint">Automatically assigned: School ID-YYMM-00001, then 00002, 00003... across all future months and years. Existing numbers are preserved.</p>
<button type="submit" id="register">Register property and generate numbers</button>
</form></section>
<section><h2>2 · Issue ICS / PAR</h2>
<p>Select a registered active property item and enter the accountable employee's full name.</p>
<form id="issuance">
<label>Search registered property<input id="asset-search" placeholder="Tag, description or current custodian"></label>
<label>Property item<select id="asset" required></select></label>
<p id="asset-info" class="hint"></p>
<label>Accountable employee's full name<input id="custodian" maxlength="160" required></label>
<button type="submit" id="issue">Issue numbered accountability slip</button>
</form></section>
</div>
<section><h2>Recorded ICS / PAR</h2><p>Printing an existing record does not create another document or change custody.</p>
<div class="table-wrap"><table><thead><tr><th>Number</th><th>Type</th><th>Property</th><th>Recorded custodian</th><th>Status</th><th>Print</th></tr></thead><tbody id="slips"></tbody></table></div></section>
<section><h2>Registered property</h2><div class="table-wrap"><table><thead><tr><th>Tag</th><th>Item</th><th>Class</th><th>Custodian</th><th>Origin</th><th>Status</th></tr></thead><tbody id="assets"></tbody></table></div></section>
</main><footer>Working forms pending policy/template review · Do not use with production records until staging verification passes.</footer>
</body></html>
