<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/lib.php';require_once dirname(__DIR__).'/f28/Platform.php';
$o=getopt('',['expected-db:','account:','acknowledge-super-admin']);
if(empty($o['expected-db'])||!hash_equals((string)cfg()['db_name'],(string)$o['expected-db'])||!isset($o['acknowledge-super-admin']))exit("Refusing: exact --expected-db and --acknowledge-super-admin required.\n");
$id=Finance360\V28\Policy::positiveId($o['account']??null);$secret=getenv('F28_TOTP_SECRET')?:'';
if(!preg_match('/^[A-Z2-7]{32,128}$/',$secret))exit("Supply an owner-controlled Base32 TOTP secret through the F28_TOTP_SECRET environment variable.\n");
$p=db();$s=$p->prepare('SELECT id FROM f28_accounts WHERE id=? AND verified_at IS NOT NULL AND disabled_at IS NULL');$s->execute([$id]);if(!$s->fetchColumn())exit("Manually verified active account required.\n");
$platform=new Finance360\V28\Platform($p,(string)(cfg()['pii_key']??cfg()['setup_key']??''));
$p->beginTransaction();try{
 $s=$p->prepare('INSERT INTO f28_platform_admins(account_id,totp_secret_enc) VALUES(?,?)');$s->execute([$id,$platform->seal($secret)]);
 $s=$p->prepare("INSERT INTO f28_audit(account_id,action,detail_json,created_at) VALUES(?,'platform.enrolled','{\"method\":\"owner_cli\"}',UTC_TIMESTAMP())");$s->execute([$id]);$p->commit();
 echo "Platform administrator enrolled. TOTP is required; no financial impersonation is enabled.\n";
}catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
