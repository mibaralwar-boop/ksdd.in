<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/lib.php';require_once dirname(__DIR__).'/f28/Service.php';
$o=getopt('',['expected-db:','account:','evidence:']);
if(empty($o['expected-db'])||!hash_equals((string)cfg()['db_name'],(string)$o['expected-db']))exit("Refusing: supply exact --expected-db.\n");
$id=Finance360\V28\Policy::positiveId($o['account']??null);
(new Finance360\V28\Service(db()))->verifyAccount($id,$o['evidence']??'');
echo "Manual verification recorded with audit evidence. Financial scope remains blocked.\n";
