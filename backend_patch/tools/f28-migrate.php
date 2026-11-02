<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/lib.php';
$options=getopt('',['expected-db:','apply-additive-schema']);
$expected=$options['expected-db']??'';
if(!$expected||!hash_equals((string)cfg()['db_name'],(string)$expected))exit("Refusing: --expected-db must exactly match the configured database.\n");
$sql=(string)file_get_contents(dirname(__DIR__).'/f28/schema.sql');
if(!isset($options['apply-additive-schema'])){echo "DRY RUN: additive SaaS metadata only; no existing financial data changes.\n".$sql;exit;}
// MySQL DDL commits implicitly; each CREATE is idempotent, and version is written only after every statement succeeds.
$pdo=db();if((int)$pdo->query("SELECT GET_LOCK('finance360_f28_schema',30)")->fetchColumn()!==1)exit("Could not acquire migration lock. No migration was run.\n");
try{
 foreach(explode(';',preg_replace('/^--.*$/m','',$sql)) as $statement)if(trim($statement)!=='')$pdo->exec($statement);
 $stmt=$pdo->prepare('INSERT IGNORE INTO f28_schema(version,applied_at) VALUES(1,UTC_TIMESTAMP())');$stmt->execute();
 echo "SaaS metadata schema applied. Financial scope remains BLOCKED. No financial rows or keys were migrated.\n";
}finally{$pdo->exec("SELECT RELEASE_LOCK('finance360_f28_schema')");}
