<?php
declare(strict_types=1);

require dirname(__DIR__).'/config/bootstrap.php';

use AcquaVale\ExpressoReservationService;

if ($_SERVER['REQUEST_METHOD']!=='POST') {
    json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
}

$input=json_input();
$csrf=(string)($input['_csrf']??($_SERVER['HTTP_X_CSRF_TOKEN']??''));
if ($csrf==='' || !hash_equals($_SESSION['csrf']??'',$csrf)) {
    json_response(['ok'=>false,'error'=>'csrf','message'=>'Sessão expirada. Atualize a página.'],419);
}

$action=trim((string)($input['action']??'lookup'));

if ($action==='clear') {
    unset($_SESSION['validated_reservation']);
    json_response(['ok'=>true,'cleared'=>true]);
}

if ($action!=='lookup') {
    json_response(['ok'=>false,'error'=>'unknown_action'],404);
}

$code=trim((string)($input['reservation_code']??''));
if ($code==='') {
    json_response(['ok'=>false,'error'=>'reservation_code_required','message'=>'Informe o número da reserva.'],422);
}

try {
    $service=new ExpressoReservationService();
    $reservation=$service->lookup($code);

    $_SESSION['validated_reservation']=$reservation;

    json_response([
        'ok'=>true,
        'reservation'=>$reservation,
    ]);
} catch (Throwable $e) {
    unset($_SESSION['validated_reservation']);
    error_log('Expresso reservation lookup: '.$e->getMessage());

    json_response([
        'ok'=>false,
        'error'=>'reservation_not_validated',
        'message'=>$e->getMessage(),
    ],422);
}
