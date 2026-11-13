<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit('CLI only');}
$email=getenv('INITIAL_ADMIN_EMAIL')?:'';$password=getenv('INITIAL_ADMIN_PASSWORD')?:'';
if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($password)<16){fwrite(STDERR,"Set INITIAL_ADMIN_EMAIL and a unique INITIAL_ADMIN_PASSWORD (16+ characters) in the local CLI environment.\n");exit(1);}
$esc=static fn(string $s)=>"'".str_replace(["\\","'"],["\\\\","''"],$s)."'";
$hash=password_hash($password,PASSWORD_DEFAULT);
echo "-- Run this SQL through phpMyAdmin; never upload it to public_html or commit it.\n";
echo "INSERT INTO users(email,password_hash,full_name,role,active) VALUES (".$esc(strtolower($email)).",".$esc($hash).",'Initial System Administrator','SUPER_ADMIN',1);\n";
