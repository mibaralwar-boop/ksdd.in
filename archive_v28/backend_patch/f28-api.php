<?php
declare(strict_types=1);
require_once __DIR__.'/lib.php';
require_once __DIR__.'/f28/Service.php';
require_once __DIR__.'/f28/Platform.php';
use Finance360\V28\Service;
use Finance360\V28\ApiError;
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
function f28_response(array $data,int $status=200): never {http_response_code($status);echo json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);exit;}
try {
    if(empty(cfg()['f28_enabled']))throw new ApiError('not_enabled',503,'Finance 360 mobile services are not enabled.');
    $https=!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off';
    $local=!empty(cfg()['f28_allow_local_http'])&&in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true);
    if(!$https&&!$local)throw new ApiError('https_required',426,'Use HTTPS.');
    $route=$_GET['route']??'';$method=$_SERVER['REQUEST_METHOD']??'';
    $routes=[
      '/v1/register'=>'POST','/v1/login'=>'POST','/v1/refresh'=>'POST','/v1/logout'=>'POST',
      '/v1/memberships'=>'GET','/v1/context'=>'GET','/v1/branches'=>'GET','/v1/branches/create'=>'POST',
      '/v1/branding'=>'POST','/v1/plans'=>'GET','/v1/account/deletion-request'=>'POST',
      '/v1/financial/status'=>'GET',
      '/v1/platform/step-up'=>'POST','/v1/platform/tenants'=>'GET','/v1/platform/trial'=>'POST','/v1/platform/suspend'=>'POST',
    ];
    if(!is_string($route)||!isset($routes[$route]))throw new ApiError('not_found',404,'Route not found.');
    if($routes[$route]!==$method){header('Allow: '.$routes[$route]);throw new ApiError('method_not_allowed',405,'Method not allowed.');}
    $body=[];
    if($method==='POST'){
      if(!str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json'))throw new ApiError('unsupported_media_type',415,'Send application/json.');
      $raw=file_get_contents('php://input',false,null,0,32769);
      if($raw===false||strlen($raw)>32768)throw new ApiError('body_too_large',413,'Request is too large.');
      try{$body=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(JsonException $e){throw new ApiError('invalid_json',400,'Request JSON is invalid.');}
      if(!is_array($body)||!str_starts_with(ltrim($raw),'{'))throw new ApiError('invalid_json',400,'Send a JSON object.');
    }
    $svc=new Service(db());
    if(in_array($route,['/v1/register','/v1/login','/v1/refresh'],true)){
      // Store an irreversible keyed IP bucket; no forwarded address is trusted.
      $pepper=(string)(cfg()['pii_key']??cfg()['setup_key']??'');
      if(strlen($pepper)<32)throw new ApiError('configuration_required',503,'Configure the existing protected application key.');
      $ip=hash_hmac('sha256',$_SERVER['REMOTE_ADDR']??'unknown',$pepper);
      $svc->limit('public:'.$route.':'.$ip,$route==='/v1/register'?5:30,900);
    }
    switch($route){
      case '/v1/register':f28_response(['data'=>$svc->register($body)],201);
      case '/v1/login':f28_response(['data'=>$svc->login($body)]);
      case '/v1/refresh':f28_response(['data'=>$svc->refresh($body)]);
      case '/v1/plans':f28_response(['data'=>$svc->plans()]);
    }
    $auth=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';
    if(!preg_match('/^Bearer ([a-f0-9]{64})$/',$auth,$m))throw new ApiError('unauthenticated',401,'Please sign in.');
    $account=$svc->authenticate($m[1]);
    $tenant=$_SERVER['HTTP_X_TENANT_ID']??null;$branch=$_SERVER['HTTP_X_BRANCH_ID']??null;
    $platform=new Finance360\V28\Platform(db(),(string)(cfg()['pii_key']??cfg()['setup_key']??''));
    if($route==='/v1/platform/step-up')$svc->limit('platform-mfa:'.$account['id'],5,300);
    $result=match($route){
      '/v1/platform/step-up'=>$platform->stepup($account,$body),
      '/v1/platform/tenants'=>$platform->tenants($account),
      '/v1/platform/trial'=>$platform->trial($account,$body),
      '/v1/platform/suspend'=>$platform->suspend($account,$body),
      '/v1/logout'=>$svc->logout($account),
      '/v1/memberships'=>$svc->memberships($account),
      '/v1/context'=>$svc->context($account,$tenant,$branch),
      '/v1/branches'=>$svc->branches($account,$tenant,$branch),
      '/v1/branches/create'=>$svc->createBranch($account,$tenant,$branch,$body),
      '/v1/branding'=>$svc->branding($account,$tenant,$branch,$body),
      '/v1/account/deletion-request'=>$svc->deleteRequest($account),
      '/v1/financial/status'=>(function()use($svc,$account,$tenant,$branch){$svc->context($account,$tenant,$branch);$svc->financialUnavailable();})(),
    };
    f28_response(['data'=>$result]);
}catch(ApiError $e){if($e->getCode()===429)header('Retry-After: 900');f28_response(['error'=>['code'=>$e->error,'message'=>$e->getMessage()]],$e->getCode());}
catch(Throwable $e){error_log('Finance360 V28 internal error: '.get_class($e));f28_response(['error'=>['code'=>'internal_error','message'=>'The request could not be completed. Please try again or contact support.']],500);}
