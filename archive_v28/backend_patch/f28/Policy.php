<?php
declare(strict_types=1);
namespace Finance360\V28;

final class ApiError extends \RuntimeException {
    public function __construct(public readonly string $error, int $status, string $message) { parent::__construct($message, $status); }
}
final class Policy {
    public const OWNER = ['tenant.view','tenant.branding','branches.view','branches.create','subscription.view','members.view','members.manage','export.request'];
    public const FINANCIAL = ['dashboard.view','closing.view','closing.entry','closing.finalize','expenses.view','expenses.create','stock.view','stock.receive','stock.issue','staff.view','staff.manage','payroll.view','payroll.prepare','payroll.approve','payroll.pay','purchase.view','purchase.create','purchase.post','supplier.view','supplier.pay','reports.view','reports.export'];
    public static function trialEnd(\DateTimeImmutable $start, int $days = 30): \DateTimeImmutable {
        if (!in_array($days,[7,10,30],true)) throw new ApiError('invalid_trial',422,'Trial grant must be 7, 10 or 30 calendar days.');
        return $start->setTimezone(new \DateTimeZone('Asia/Kolkata'))->modify("+$days days")->setTimezone(new \DateTimeZone('UTC'));
    }
    public static function state(array $s, \DateTimeImmutable $now): string {
        $state=(string)$s['state'];
        if (!in_array($state,['trialing','active','past_due','grace','expired','suspended','cancelled'],true)) throw new ApiError('invalid_subscription',503,'Subscription state requires administrator review.');
        if (in_array($state,['suspended','cancelled','expired'],true)) return $state;
        $utc=new \DateTimeZone('UTC');
        if ($state==='trialing') return $now < new \DateTimeImmutable($s['trial_ends_at'],$utc) ? 'trialing' : 'expired';
        if ($state==='active' && !empty($s['paid_until']) && $now < new \DateTimeImmutable($s['paid_until'],$utc)) return 'active';
        if (in_array($state,['active','past_due','grace'],true) && !empty($s['grace_until']) && $now < new \DateTimeImmutable($s['grace_until'],$utc)) return 'grace';
        return 'expired';
    }
    public static function permissions(array $m, string $state, bool $verified, string $financialState): array {
        if (empty($m['active']) || $state==='suspended') return [];
        $grants=json_decode($m['grants_json'],true,512,JSON_THROW_ON_ERROR);
        $denies=json_decode($m['denies_json'],true,512,JSON_THROW_ON_ERROR);
        if (!is_array($grants)||!is_array($denies)) throw new ApiError('invalid_permissions',503,'Permissions require review.');
        $base=$m['role']==='owner' ? self::OWNER : [];
        $all=array_intersect(array_unique(array_merge($base,$grants)),array_merge(self::OWNER,self::FINANCIAL));
        if ($financialState!=='ready') $all=array_diff($all,self::FINANCIAL);
        // Cancellation/expiry preserve authorized read and data export-request access, never posting.
        if (!$verified || !in_array($state,['trialing','active','grace'],true)) $all=array_filter($all,fn($p)=>str_ends_with($p,'.view') || $p==='export.request');
        $all=array_values(array_diff($all,$denies));sort($all);return $all;
    }
    public static function requirePermission(array $context, string $permission): void {
        if (!in_array($permission,$context['permissions'],true)) throw new ApiError('permission_denied',403,'This action is not authorized for this restaurant and branch.');
    }
    public static function positiveId(mixed $value): int {
        if ((!is_int($value)&&!is_string($value)) || !preg_match('/^[1-9][0-9]{0,17}$/',(string)$value) || (int)$value<=0) throw new ApiError('invalid_id',422,'Choose a valid restaurant and branch.');
        return (int)$value;
    }
    public static function text(mixed $value,int $max,string $label): string {
        if (!is_string($value)) throw new ApiError('invalid_input',422,"$label is required.");
        $v=trim($value);
        if ($v===''||mb_strlen($v)>$max||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$v)) throw new ApiError('invalid_input',422,"Enter a valid $label (maximum $max characters).");
        return $v;
    }
}
