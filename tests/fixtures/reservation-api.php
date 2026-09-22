<?php
declare(strict_types=1);

// Synthetic responses for local tests only; no database or real credentials.
header('Content-Type: application/json');
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$case=$_GET['case']??'ok';
$input=json_decode(file_get_contents('php://input'),true)??[];
if ($path==='/token') {
    if ($_SERVER['REQUEST_METHOD']!=='POST' || ($input['user']??'')!=='fixture-user' || ($input['password']??'')!=='fixture-password') {
        http_response_code(400);
        echo json_encode(['error'=>'invalid_auth_contract']);
    } elseif ($case==='http-error') {
        http_response_code(503);
        echo json_encode(['error'=>'unavailable']);
    } elseif ($case==='invalid-json') {
        echo '<html>upstream error</html>';
    } else {
        echo json_encode(['token'=>match ($case) {
            'null-token'=>null,
            'string-null-token'=>'null',
            'empty-token'=>'',
            default=>'fixture-token',
        }]);
    }
    return;
}
if ($path==='/reservation') {
    if ($_SERVER['REQUEST_METHOD']!=='POST' || ($input['token']??'')!=='fixture-token' || ($input['numero_reserva']??'')!=='2505371') {
        http_response_code(400);
        echo json_encode(['error'=>'invalid_reservation_contract']);
        return;
    }
    $data=['reserva_id'=>123,'numero_reserva'=>'2505371','hospede_nome'=>'Visitante de teste',
        'data_checkin'=>'2030-01-15','data_checkout'=>'2030-01-17','adultos'=>2,'criancas'=>0,'uh'=>'101'];
    if ($case==='wrong-code') $data['numero_reserva']='9999999';
    if ($case==='empty-data') $data=[];
    if ($case==='invalid-fields') $data['hospede_nome']=['unexpected'=>'array'];
    if ($case==='id-only') unset($data['numero_reserva']);
    echo json_encode(match ($case) {
        'fail'=>['status'=>'fail','msg'=>'Private upstream detail'],
        'root'=>$data,
        'reserva'=>['reserva'=>$data],
        default=>['data'=>$data],
    });
    return;
}
if ($path==='/api/login.php') {
    if ($_POST['username']==='missing-endpoint') {
        http_response_code(404);
        echo '{}';
    } else {
        echo json_encode(['success'=>true,'user'=>['api_token'=>'fixture-iplate-token']]);
    }
    return;
}
if ($path==='/api/reservation-search.php') {
    if (($_GET['api_token']??'')!=='fixture-iplate-token' || ($_GET['exact']??'')!=='1' || ($_GET['term']??'')!=='2505371') {
        http_response_code(400);
        echo '{}';
    } else {
        echo json_encode(['success'=>true,'items'=>[['reservation_id'=>123,'reservation_code'=>'2505371',
            'guest_name'=>'Visitante de teste','checkin_date'=>'2030-01-15','checkout_date'=>'2030-01-17',
            'guest_count'=>2,'status'=>'confirmada']]]);
    }
    return;
}
http_response_code(404);
echo '{}';
