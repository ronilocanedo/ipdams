<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
if(PHP_SAPI!=='cli'){http_response_code(403);exit('CLI only');}
$db=database();$db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(200) PRIMARY KEY, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
foreach(glob(dirname(__DIR__).'/db/migrations/*.sql') as $file){
    $name=basename($file);if(one($db,'SELECT name FROM schema_migrations WHERE name=?',[$name])){echo "Skipped $name\n";continue;}
    $sql=file_get_contents($file);$statements=preg_split('/;\s*\n/',trim($sql));
    foreach($statements as $statement){$statement=trim($statement);if($statement==='')continue;$db->exec($statement);}
    q($db,'INSERT INTO schema_migrations(name) VALUES(?)',[$name]);echo "Applied $name\n";
}
