<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/private/app/bootstrap.php';
ini_set('session.use_strict_mode','1');
ini_set('session.cookie_httponly','1');
session_name('IPDAMSSESSID');session_start();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$reportEmbedded=(($_GET['embedded']??'')==='1');
header('X-Frame-Options: '.($reportEmbedded?'SAMEORIGIN':'DENY'));
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; connect-src 'self'; form-action 'self'; object-src 'none'; base-uri 'self'; frame-ancestors ".($reportEmbedded?"'self'":"'none'"));
try { currentUser(database()); }
catch(Throwable $e) {
    http_response_code(401);header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Sign in required</title><body><p>Sign in to IPDAMS before opening transaction registers.</p><a href="../index.php">Back to sign-in</a></body></html>';
    exit;
}
header('Content-Type: text/html; charset=utf-8');
?><!doctype html><html lang="en"><head><base href="../"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>IPDAMS · Month and Year Transaction Register</title>
<link rel="stylesheet" href="assets/property-workspace.css"><link rel="stylesheet" href="assets/transaction-archive.css">
<script src="assets/transaction-archive.js" defer></script></head><body<?=$reportEmbedded?' class="reports-embedded"':''?>>
<header class="site-head"><div><strong>IPDAMS</strong> · Month and Year Registers</div><?php if(!$reportEmbedded):?><a href="index.php">← Main IPDAMS</a><?php endif;?></header>
<main>
<div class="banner"><strong>Transaction period review</strong><p>Choose an organization, process, month and/or year. Records are scoped to your account. Document dates are used where available. Processes without their own issue date use the stored registry timestamp, which may follow the database server's timezone. Historical lists do not change any transaction.</p></div>
<div id="message" role="status" aria-live="polite"></div>
<section><h2>Filter transactions</h2><div class="archive-filters">
<label>Authorized organization<select id="org" aria-label="Authorized organization" <?=$reportEmbedded?'disabled':''?>></select></label>
<label>Process<select id="process"></select></label>
<label>Year<select id="year"></select></label>
<label>Month<select id="month"></select></label>
<button id="search" type="button">Search records</button><button id="archive-print" type="button" disabled>Print register</button>
</div><p id="period-info" class="hint"></p>
</section>
<section><div class="section-head"><h2 id="results-title">Transaction register</h2><span id="result-count"></span></div>
<div class="table-wrap"><table><thead id="table-head"></thead><tbody id="table-body"></tbody></table></div>
<p class="hint">Up to 100 most recent matching records are shown per search, after the date filter is applied. Select a smaller period when more records are expected. Documents without an issued/finalized date may not appear in period-specific searches.</p>
</section>
</main><footer>Read-only transaction archive · Manual approval and official print templates remain subject to review.</footer>

</body></html>
