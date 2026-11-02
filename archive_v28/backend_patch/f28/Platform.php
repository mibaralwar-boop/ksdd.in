<?php
declare(strict_types=1);
namespace Finance360\V28;
require_once __DIR__.'/Service.php';
final class Platform {
    public function __construct(private readonly \PDO $db,private readonly string $key) {if(strlen($key)<32)throw new ApiError('configuration_required',503,'Platform encryption key is not configured.');}
    private function q(string $sql,array $args=[]): \PDOStatement {$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    public function seal(string $value): string {$iv=random_bytes(12);$tag='';$ct=openssl_encrypt($value,'aes-256-gcm',hash('sha256','f28-platform:'.$this->key,true),OPENSSL_RAW_DATA,$iv,$tag,'platform-totp',16);if($ct===false)throw new \RuntimeException('Encryption failed');return base64_encode($iv.$tag.$ct);}
    private function unseal(string $value): string {$raw=base64_decode($value,true);if($raw===false||strlen($raw)<29)throw new ApiError('invalid_mfa',503,'Platform MFA configuration requires review.');$s=openssl_decrypt(substr($raw,28),'aes-256-gcm',hash('sha256','f28-platform:'.$this->key,true),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'platform-totp');if($s===false)throw new ApiError('invalid_mfa',503,'Platform MFA configuration requires review.');return $s;}
    public static function code(string $secret,int $counter): string {
        if(!preg_match('/^[A-Z2-7]{16,128}$/',$secret)||$counter<0)throw new ApiError('invalid_mfa',422,'Use a valid Base32 TOTP secret.');
        $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$bits='';foreach(str_split($secret) as $c)$bits.=str_pad(decbin(strpos($alphabet,$c)),5,'0',STR_PAD_LEFT);$key='';for($i=0;$i+8<=strlen($bits);$i+=8)$key.=chr(bindec(substr($bits,$i,8)));
        $h=hash_hmac('sha1',pack('N2',intdiv($counter,4294967296),$counter%4294967296),$key,true);$offset=ord($h[19])&15;$v=unpack('N',substr($h,$offset,4))[1]&0x7fffffff;return str_pad((string)($v%1000000),6,'0',STR_PAD_LEFT);
    }
    public function stepup(array $a,array $body): array {
        $otp=$body['code']??'';if(!is_string($otp)||!preg_match('/^[0-9]{6}$/',$otp))throw new ApiError('mfa_required',403,'Enter your six-digit authenticator code.');
        $this->db->beginTransaction();
        try{
            $s=$this->q('SELECT * FROM f28_platform_admins WHERE account_id=? AND active=1 FOR UPDATE',[$a['id']])->fetch(\PDO::FETCH_ASSOC);
            if(!$s)throw new ApiError('platform_denied',403,'Platform access is not authorized.');
            $counter=intdiv(time(),30);$match=null;$secret=$this->unseal($s['totp_secret_enc']);
            foreach([$counter-1,$counter,$counter+1] as $c)if($c>(int)$s['last_counter']&&hash_equals(self::code($secret,$c),$otp))$match=$c;
            if($match===null)throw new ApiError('mfa_invalid',403,'Authenticator code is invalid or was already used.');
            $this->q('UPDATE f28_platform_admins SET last_counter=? WHERE account_id=?',[$match,$a['id']]);
            $this->q('INSERT INTO f28_stepups(session_id,expires_at) VALUES(?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE)) ON DUPLICATE KEY UPDATE expires_at=VALUES(expires_at)',[$a['session_id']]);
            $this->audit($a,'platform.stepup',null,[]);$this->db->commit();return ['verified'=>true,'expires_in'=>300];
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private function need(array $a): void {
        if(!$this->q('SELECT 1 FROM f28_platform_admins p JOIN f28_stepups s ON s.session_id=? WHERE p.account_id=? AND p.active=1 AND s.expires_at>UTC_TIMESTAMP()',[$a['session_id'],$a['id']])->fetchColumn())throw new ApiError('stepup_required',403,'Platform administrator MFA is required.');
    }
    private function audit(array $a,string $action,?int $tenant,array $detail):void{$this->q('INSERT INTO f28_audit(tenant_id,account_id,action,detail_json,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP())',[$tenant,$a['id'],$action,json_encode($detail,JSON_THROW_ON_ERROR)]);}
    public function tenants(array $a): array {
        $this->need($a);$this->audit($a,'platform.tenants_viewed',null,[]);
        return ['tenants'=>$this->q('SELECT t.id,t.name,t.city,t.financial_scope_state,s.state,s.trial_ends_at,s.branch_limit,s.user_limit FROM f28_tenants t JOIN f28_subscriptions s ON s.tenant_id=t.id ORDER BY t.id DESC LIMIT 100')->fetchAll(\PDO::FETCH_ASSOC),'limit'=>100];
    }
    public function trial(array $a,array $body): array {
        $this->need($a);$id=Policy::positiveId($body['tenant_id']??null);$reason=Policy::text($body['reason']??null,500,'reason');$days=$body['days']??null;
        if(!is_int($days)||!in_array($days,[7,10,30],true))throw new ApiError('invalid_trial',422,'Choose a 7, 10 or 30 day trial extension.');
        $this->db->beginTransaction();try{
            $r=$this->q('SELECT * FROM f28_subscriptions WHERE tenant_id=? FOR UPDATE',[$id])->fetch(\PDO::FETCH_ASSOC);
            if(!$r)throw new ApiError('not_found',404,'Subscription not found.');
            if(!in_array($r['state'],['trialing','expired'],true))throw new ApiError('invalid_transition',409,'Only trialing or expired trial accounts may receive a trial extension.');
            if($r['paid_until']!==null)throw new ApiError('invalid_transition',409,'Paid accounts require a separately approved renewal policy.');
            $now=new \DateTimeImmutable('now',new \DateTimeZone('UTC'));$base=new \DateTimeImmutable($r['trial_ends_at'],new \DateTimeZone('UTC'));if($base<$now)$base=$now;$end=Policy::trialEnd($base,$days)->format('Y-m-d H:i:s');
            $this->q("UPDATE f28_subscriptions SET state='trialing',trial_ends_at=?,version=version+1 WHERE tenant_id=?",[$end,$id]);
            $this->audit($a,'platform.trial_extended',$id,['days'=>$days,'previous_end'=>$r['trial_ends_at'],'new_end'=>$end,'reason'=>$reason]);$this->db->commit();return ['trial_ends_at'=>str_replace(' ','T',$end).'Z'];
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function suspend(array $a,array $body): array {
        $this->need($a);$id=Policy::positiveId($body['tenant_id']??null);$reason=Policy::text($body['reason']??null,500,'reason');
        $this->db->beginTransaction();try{
            $r=$this->q('SELECT tenant_id FROM f28_subscriptions WHERE tenant_id=? FOR UPDATE',[$id])->fetchColumn();if(!$r)throw new ApiError('not_found',404,'Subscription not found.');
            $this->q("UPDATE f28_subscriptions SET state='suspended',version=version+1 WHERE tenant_id=?",[$id]);$this->audit($a,'platform.tenant_suspended',$id,['reason'=>$reason]);$this->db->commit();return ['state'=>'suspended'];
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
