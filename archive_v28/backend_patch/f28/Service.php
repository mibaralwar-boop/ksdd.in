<?php
declare(strict_types=1);
namespace Finance360\V28;
require_once __DIR__.'/Policy.php';

final class Service {
    private readonly \Closure $clock;
    public function __construct(private readonly \PDO $db, ?\Closure $clock=null) {
        $this->clock=$clock ?? fn()=>new \DateTimeImmutable('now',new \DateTimeZone('UTC'));
    }
    public function now(): \DateTimeImmutable { return ($this->clock)()->setTimezone(new \DateTimeZone('UTC')); }
    private function stamp(): string { return $this->now()->format('Y-m-d H:i:s'); }
    private function q(string $sql,array $args=[]): \PDOStatement { $s=$this->db->prepare($sql);$s->execute($args);return $s; }
    private function one(string $sql,array $args=[]): ?array { return $this->q($sql,$args)->fetch(\PDO::FETCH_ASSOC) ?: null; }
    private function tx(callable $fn): mixed {
        $this->db->beginTransaction();try{$r=$fn();$this->db->commit();return $r;}catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private function audit(?int $tenant,?int $account,string $action,array $detail=[]): void {
        $this->q('INSERT INTO f28_audit(tenant_id,account_id,action,detail_json,created_at) VALUES(?,?,?,?,?)',[$tenant,$account,$action,json_encode($detail,JSON_THROW_ON_ERROR),$this->stamp()]);
    }
    public function limit(string $bucket,int $max=10,int $window=900): void {
        $key=hash('sha256',$bucket);$now=$this->now()->getTimestamp();
        $this->tx(function()use($key,$max,$window,$now){
            $this->q('INSERT IGNORE INTO f28_rate_limits(bucket,attempts,window_start) VALUES(?,0,?)',[$key,$now]);
            $r=$this->one('SELECT * FROM f28_rate_limits WHERE bucket=? FOR UPDATE',[$key]);
            $n=$now-(int)$r['window_start'] >= $window ? 1 : (int)$r['attempts']+1;
            $start=$n===1?$now:(int)$r['window_start'];
            // Persist denied attempts too; do not throw inside the transaction and roll back the rate limit.
            $this->q('UPDATE f28_rate_limits SET attempts=?,window_start=? WHERE bucket=?',[$n,$start,$key]);
        });
        $r=$this->one('SELECT attempts FROM f28_rate_limits WHERE bucket=?',[$key]);
        if((int)$r['attempts']>$max) throw new ApiError('rate_limited',429,'Too many attempts. Please try again later.');
    }
    private function email(mixed $v): string {
        $e=strtolower(Policy::text($v,190,'email'));
        if(!filter_var($e,FILTER_VALIDATE_EMAIL))throw new ApiError('invalid_email',422,'Enter a valid email address.');return $e;
    }
    private function password(mixed $p): string {
        if(!is_string($p)||strlen($p)<12||strlen($p)>128)throw new ApiError('invalid_password',422,'Use a password between 12 and 128 bytes.');return $p;
    }
    private function tokens(int $account,?string $session=null): array {
        $session??=bin2hex(random_bytes(16));
        $this->q('INSERT IGNORE INTO f28_sessions(id,account_id,created_at) VALUES(?,?,?)',[$session,$account,$this->stamp()]);
        $out=['session_id'=>$session,'token_type'=>'Bearer','expires_in'=>900];
        foreach(['access'=>900,'refresh'=>2592000] as $kind=>$seconds){
            $token=bin2hex(random_bytes(32));
            $this->q('INSERT INTO f28_tokens(token_hash,session_id,kind,expires_at,created_at) VALUES(?,?,?,?,?)',[hash('sha256',$token),$session,$kind,$this->now()->modify("+$seconds seconds")->format('Y-m-d H:i:s'),$this->stamp()]);
            $out[$kind.'_token']=$token;
        }return $out;
    }
    public function register(array $b): array {
        $email=$this->email($b['email']??null);$password=$this->password($b['password']??null);
        $name=Policy::text($b['owner_name']??null,160,'owner name');
        $restaurant=Policy::text($b['restaurant_name']??null,160,'restaurant name');
        $address=Policy::text($b['address']??null,500,'address');$city=Policy::text($b['city']??null,100,'city');
        $branch=Policy::text($b['branch_name']??'Main Branch',160,'branch name');
        $language=$b['language']??'en';if(!in_array($language,['en','hi'],true))throw new ApiError('invalid_language',422,'Choose Hindi or English.');
        if(($b['privacy_consent']??null)!==true)throw new ApiError('consent_required',422,'Privacy consent is required to register.');
        try{return $this->tx(function()use($email,$password,$name,$restaurant,$address,$city,$branch,$language){
            $this->q('INSERT INTO f28_accounts(name,email,password_hash,created_at) VALUES(?,?,?,?)',[$name,$email,password_hash($password,PASSWORD_DEFAULT),$this->stamp()]);$a=(int)$this->db->lastInsertId();
            $this->q('INSERT INTO f28_tenants(name,address,city,language,created_at) VALUES(?,?,?,?,?)',[$restaurant,$address,$city,$language,$this->stamp()]);$t=(int)$this->db->lastInsertId();
            $this->q('INSERT INTO f28_branches(tenant_id,name,address,created_at) VALUES(?,?,?,?)',[$t,$branch,$address,$this->stamp()]);$br=(int)$this->db->lastInsertId();
            $this->q("INSERT INTO f28_memberships(tenant_id,account_id,role,grants_json,denies_json) VALUES(?,?,'owner','[]','[]')",[$t,$a]);
            $this->q('INSERT INTO f28_branch_memberships(tenant_id,branch_id,account_id) VALUES(?,?,?)',[$t,$br,$a]);
            $end=Policy::trialEnd($this->now())->format('Y-m-d H:i:s');
            $this->q("INSERT INTO f28_subscriptions(tenant_id,state,trial_started_at,trial_ends_at) VALUES(?,'trialing',?,?)",[$t,$this->stamp(),$end]);
            $this->q("INSERT INTO f28_consents(account_id,purpose,policy_version,accepted_at) VALUES(?,'data_processing','v28-foundation-1',?)",[$a,$this->stamp()]);
            $this->audit($t,$a,'registration.created',['branch_id'=>$br,'verification'=>'manual_pending']);
            return $this->tokens($a)+['tenant_id'=>$t,'branch_id'=>$br,'trial_ends_at'=>str_replace(' ','T',$end).'Z','verification'=>'manual_pending','financial_scope_state'=>'blocked'];
        });}catch(\PDOException $e){if($e->getCode()==='23000')throw new ApiError('registration_conflict',409,'Registration could not be completed. Sign in or contact support.');throw $e;}
    }
    public function login(array $b): array {
        $email=$this->email($b['email']??null);$p=$b['password']??'';
        $this->limit('login:'.$email);
        $r=$this->one('SELECT * FROM f28_accounts WHERE email=?',[$email]);
        // A constant real hash keeps unknown identities on the password verification path.
        $hash=$r['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
        $ok=password_verify(is_string($p)?substr($p,0,128):'',$hash);
        if(!$r||!$ok||$r['disabled_at'])throw new ApiError('invalid_credentials',401,'Email or password is incorrect.');
        return $this->tx(fn()=>$this->tokens((int)$r['id']));
    }
    public function authenticate(string $token): array {
        if(!preg_match('/^[a-f0-9]{64}$/',$token))throw new ApiError('unauthenticated',401,'Please sign in.');
        $r=$this->one("SELECT a.id,a.name,a.email,a.verified_at,a.disabled_at,s.id session_id FROM f28_tokens t JOIN f28_sessions s ON s.id=t.session_id JOIN f28_accounts a ON a.id=s.account_id WHERE t.token_hash=? AND t.kind='access' AND t.consumed_at IS NULL AND t.expires_at>? AND s.revoked_at IS NULL",[hash('sha256',$token),$this->stamp()]);
        if(!$r||$r['disabled_at'])throw new ApiError('unauthenticated',401,'Session expired. Please sign in.');return $r;
    }
    public function refresh(array $b): array {
        $token=$b['refresh_token']??'';if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/',$token))throw new ApiError('unauthenticated',401,'Please sign in.');
        $r=$this->tx(function()use($token){
            $r=$this->one("SELECT t.*,s.account_id,s.revoked_at,a.disabled_at FROM f28_tokens t JOIN f28_sessions s ON s.id=t.session_id JOIN f28_accounts a ON a.id=s.account_id WHERE t.token_hash=? AND t.kind='refresh' FOR UPDATE",[hash('sha256',$token)]);
            if(!$r||$r['revoked_at']||$r['disabled_at']||$r['expires_at']<=$this->stamp())return null;
            if($r['consumed_at']){
                $this->q('UPDATE f28_sessions SET revoked_at=? WHERE id=?',[$this->stamp(),$r['session_id']]);
                $this->audit(null,(int)$r['account_id'],'auth.refresh_replay');return null;
            }
            $this->q('UPDATE f28_tokens SET consumed_at=? WHERE session_id=? AND consumed_at IS NULL',[$this->stamp(),$r['session_id']]);
            return $this->tokens((int)$r['account_id'],$r['session_id']);
        });
        if(!$r)throw new ApiError('unauthenticated',401,'Session expired or revoked. Please sign in.');return $r;
    }
    public function logout(array $a): array {
        $this->q('UPDATE f28_sessions SET revoked_at=? WHERE id=? AND account_id=?',[$this->stamp(),$a['session_id'],$a['id']]);return ['logged_out'=>true];
    }
    public function memberships(array $a): array {
        $r=$this->q('SELECT t.id tenant_id,t.name restaurant_name,t.language,t.financial_scope_state,b.id branch_id,b.name branch_name,m.role,m.revision FROM f28_memberships m JOIN f28_tenants t ON t.id=m.tenant_id JOIN f28_branch_memberships bm ON bm.tenant_id=m.tenant_id AND bm.account_id=m.account_id JOIN f28_branches b ON b.tenant_id=bm.tenant_id AND b.id=bm.branch_id WHERE m.account_id=? AND m.active=1 AND b.active=1 ORDER BY t.name,b.name',[$a['id']])->fetchAll(\PDO::FETCH_ASSOC);
        return ['memberships'=>$r,'account'=>['id'=>(int)$a['id'],'name'=>$a['name'],'email'=>$a['email'],'verified'=>$a['verified_at']!==null]];
    }
    public function context(array $a,mixed $tenant,mixed $branch): array {
        $t=Policy::positiveId($tenant);$b=Policy::positiveId($branch);
        $r=$this->one('SELECT t.name restaurant_name,t.language,t.financial_scope_state,b.name branch_name,b.cutoff_hour,m.role,m.active,m.grants_json,m.denies_json,m.revision FROM f28_memberships m JOIN f28_tenants t ON t.id=m.tenant_id JOIN f28_branch_memberships bm ON bm.tenant_id=m.tenant_id AND bm.account_id=m.account_id JOIN f28_branches b ON b.tenant_id=bm.tenant_id AND b.id=bm.branch_id WHERE m.account_id=? AND m.tenant_id=? AND b.id=? AND m.active=1 AND b.active=1',[$a['id'],$t,$b]);
        if(!$r)throw new ApiError('scope_denied',403,'Restaurant or branch access is not authorized.');
        $sub=$this->one('SELECT * FROM f28_subscriptions WHERE tenant_id=?',[$t]);if(!$sub)throw new ApiError('subscription_missing',503,'Subscription requires administrator review.');
        $state=Policy::state($sub,$this->now());
        $permissions=Policy::permissions($r,$state,$a['verified_at']!==null,$r['financial_scope_state']);
        return ['tenant_id'=>$t,'branch_id'=>$b,'restaurant_name'=>$r['restaurant_name'],'branch_name'=>$r['branch_name'],'language'=>$r['language'],'role'=>$r['role'],'revision'=>(int)$r['revision'],'permissions'=>$permissions,'financial_scope_state'=>$r['financial_scope_state'],'verification'=>$a['verified_at']?'verified':'manual_pending','subscription'=>['state'=>$state,'trial_ends_at'=>str_replace(' ','T',$sub['trial_ends_at']).'Z','paid_until'=>$sub['paid_until']?str_replace(' ','T',$sub['paid_until']).'Z':null,'branch_limit'=>(int)$sub['branch_limit'],'user_limit'=>(int)$sub['user_limit']],'server_time'=>$this->now()->format('c')];
    }
    public function branches(array $a,mixed $tenant,mixed $branch): array {
        $c=$this->context($a,$tenant,$branch);Policy::requirePermission($c,'branches.view');
        // Only explicitly assigned branches are visible, including to owners.
        return ['branches'=>$this->q('SELECT b.id,b.name,b.address,b.cutoff_hour,b.active FROM f28_branches b JOIN f28_branch_memberships m ON m.tenant_id=b.tenant_id AND m.branch_id=b.id WHERE b.tenant_id=? AND m.account_id=? AND b.active=1 ORDER BY b.id',[$c['tenant_id'],$a['id']])->fetchAll(\PDO::FETCH_ASSOC)];
    }
    public function createBranch(array $a,mixed $tenant,mixed $branch,array $body): array {
        $name=Policy::text($body['name']??null,160,'branch name');$address=Policy::text($body['address']??null,500,'branch address');
        $hour=$body['cutoff_hour']??4;if(!is_int($hour)||$hour<0||$hour>23)throw new ApiError('invalid_cutoff',422,'Closing cutoff must be an hour from 0 to 23.');
        return $this->tx(function()use($a,$tenant,$branch,$name,$address,$hour){
            $t=Policy::positiveId($tenant);$sub=$this->one('SELECT tenant_id,branch_limit FROM f28_subscriptions WHERE tenant_id=? FOR UPDATE',[$t]);
            $c=$this->context($a,$tenant,$branch);Policy::requirePermission($c,'branches.create');
            $count=(int)$this->q('SELECT COUNT(*) FROM f28_branches WHERE tenant_id=? AND active=1',[$t])->fetchColumn();
            if($count>=(int)$sub['branch_limit'])throw new ApiError('branch_limit',409,'This subscription has reached its branch limit.');
            $this->q('INSERT INTO f28_branches(tenant_id,name,address,cutoff_hour,created_at) VALUES(?,?,?,?,?)',[$t,$name,$address,$hour,$this->stamp()]);$id=(int)$this->db->lastInsertId();
            $this->q('INSERT INTO f28_branch_memberships(tenant_id,branch_id,account_id) VALUES(?,?,?)',[$t,$id,$a['id']]);
            $this->audit($t,(int)$a['id'],'branch.created',['branch_id'=>$id]);return ['id'=>$id,'name'=>$name];
        });
    }
    public function branding(array $a,mixed $tenant,mixed $branch,array $body): array {
        $c=$this->context($a,$tenant,$branch);Policy::requirePermission($c,'tenant.branding');
        $name=Policy::text($body['name']??null,160,'restaurant name');$lang=$body['language']??'en';
        if(!in_array($lang,['en','hi'],true))throw new ApiError('invalid_language',422,'Choose Hindi or English.');
        return $this->tx(function()use($c,$a,$name,$lang){$this->q('UPDATE f28_tenants SET name=?,language=? WHERE id=?',[$name,$lang,$c['tenant_id']]);$this->audit($c['tenant_id'],(int)$a['id'],'tenant.branding_updated');return ['updated'=>true];});
    }
    public function plans(): array {return ['plans'=>$this->q('SELECT id,name,term_months,price_paise,branch_limit,user_limit,version FROM f28_plans WHERE available=1 ORDER BY term_months')->fetchAll(\PDO::FETCH_ASSOC),'purchase_available'=>false,'notice'=>'Paid checkout is not enabled in this foundation build. No payment can be collected.'];}
    public function deleteRequest(array $a): array {
        return $this->tx(function()use($a){$this->q("INSERT IGNORE INTO f28_deletion_requests(account_id,requested_at) VALUES(?,?)",[$a['id'],$this->stamp()]);$this->audit(null,(int)$a['id'],'account.deletion_requested');return ['state'=>'requested','notice'=>'Request recorded for administrator review. Financial retention obligations require review; data has not been deleted.'];});
    }
    public function verifyAccount(int $account,string $reason): void {
        $reason=Policy::text($reason,500,'verification evidence reference');
        $this->tx(function()use($account,$reason){$r=$this->one('SELECT id FROM f28_accounts WHERE id=? FOR UPDATE',[$account]);if(!$r)throw new ApiError('not_found',404,'Account not found.');$this->q('UPDATE f28_accounts SET verified_at=? WHERE id=?',[$this->stamp(),$account]);$this->audit(null,$account,'account.manually_verified',['evidence_reference'=>$reason]);});
    }
    public function financialUnavailable(): never {throw new ApiError('financial_migration_pending',503,'Financial APIs are locked until existing ledger tenant isolation and reconciliation are completed.');}
}
