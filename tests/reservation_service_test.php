<?php
declare(strict_types=1);

if (PHP_SAPI!=='cli') { http_response_code(404); exit; }

require dirname(__DIR__).'/config/bootstrap.php';

// Run against tests/fixtures/reservation-api.php served on loopback only.
$base=rtrim((string)getenv('AQV_TEST_API_URL'),'/');
if (parse_url($base,PHP_URL_HOST)!=='127.0.0.1') {
    throw new RuntimeException('Set AQV_TEST_API_URL to the local fixture server.');
}
$port=(int)parse_url($base,PHP_URL_PORT);
for ($attempt=0;$attempt<30;$attempt++) {
    $socket=@fsockopen('127.0.0.1',$port,$errno,$error,0.1);
    if ($socket) { fclose($socket); break; }
    usleep(100000);
}
$passed=0;
function expect(bool $condition,string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function configFor(string $case='ok'): void {
    global $base;
    $_SESSION=[];
    $GLOBALS['aqv_config']=['expresso'=>[
        'token_url'=>$base.'/token?case='.urlencode($case),
        'reservation_url'=>$base.'/reservation?case='.urlencode($case),
        'user'=>'fixture-user','password'=>'fixture-password','timeout_seconds'=>5,
    ]];
}
function failsWith(string $case,string $message): void {
    configFor($case);
    try {
        (new AcquaVale\ReservationService())->lookup('2505371');
    } catch (RuntimeException $e) {
        expect(str_contains($e->getMessage(),$message),"Unexpected error for {$case}: ".$e->getMessage());
        return;
    }
    throw new RuntimeException("Expected failure for {$case}");
}
foreach (['ok','root','reserva','id-only','invalid-fields'] as $case) {
    configFor($case);
    $r=(new AcquaVale\ReservationService())->lookup(' 2505371 ');
    expect($r['reservation_code']==='2505371','Wrong reservation');
    expect($r['checkin_date']==='2030-01-15' && $r['checkout_date']==='2030-01-17','Lost dates');
    expect($r['adults']==='2' && $r['children']==='0','Lost guest quantities');
    expect($r['source']==='expresso_api','Wrong provider');
    if ($case==='invalid-fields') expect($r['guest_name']==='','Array value was not rejected');
    $passed++;
}
foreach (['null-token','string-null-token','empty-token'] as $case) {
    failsWith($case,'sem um token válido'); $passed++;
}
foreach (['http-error'=>'HTTP 503','invalid-json'=>'JSON inválida','wrong-code'=>'diferente',
    'empty-data'=>'não contém uma reserva válida','fail'=>'não confirmou'] as $case=>$message) {
    failsWith($case,$message); $passed++;
}
configFor();
$GLOBALS['aqv_config']['iplate']=['server_url'=>$base.'/api/vehicle-entry-create.php',
    'username'=>'fixture-user','password'=>'fixture-password','timeout_seconds'=>5];

// Legacy/auto mode must prefer Expresso when both are configured.
$r=(new AcquaVale\ReservationService())->lookup('2505371');
expect($r['source']==='expresso_api','Auto mode must prefer Expresso when both providers are configured'); $passed++;

// If Expresso credentials are absent, legacy/auto mode can fall back to iPlate.
$GLOBALS['aqv_config']['expresso']['user']='';
$GLOBALS['aqv_config']['expresso']['password']='';
$r=(new AcquaVale\ReservationService())->lookup('2505371');
expect($r['source']==='iplate_backend' && $r['guest_count']==='2','Auto mode must fall back to iPlate when Expresso is unavailable'); $passed++;

// Explicit provider always takes precedence.
configFor();
$GLOBALS['aqv_config']['iplate']=['server_url'=>$base.'/api/vehicle-entry-create.php',
    'username'=>'fixture-user','password'=>'fixture-password','timeout_seconds'=>5];
$GLOBALS['aqv_config']['reservation']['provider']='iplate';
$r=(new AcquaVale\ReservationService())->lookup('2505371');
expect($r['source']==='iplate_backend','Explicit iPlate provider must take precedence'); $passed++;

$GLOBALS['aqv_config']['reservation']['provider']='expresso';
$r=(new AcquaVale\ReservationService())->lookup('2505371');
expect($r['source']==='expresso_api','Explicit Expresso provider must take precedence'); $passed++;
$_SESSION=[];
$GLOBALS['aqv_config']['reservation']['provider']='iplate';
$GLOBALS['aqv_config']['iplate']['username']='missing-endpoint';
try {
    (new AcquaVale\ReservationService())->lookup('2505371');
    throw new LogicException('Expected HTTP 404 failure');
} catch (RuntimeException $e) {
    expect(str_contains($e->getMessage(),'HTTP 404'),'Must report missing endpoint'); $passed++;
}
session_write_close();
echo "PASS: {$passed} reservation integration checks using synthetic data.\n";
