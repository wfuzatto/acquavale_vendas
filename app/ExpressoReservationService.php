<?php
declare(strict_types=1);

namespace AcquaVale;

use RuntimeException;

final class ExpressoReservationService
{
    public function lookup(string $reservationCode): array
    {
        $reservationCode=trim($reservationCode);
        if ($reservationCode==='') {
            throw new RuntimeException('Informe o número da reserva.');
        }

        $tokenUrl=(string)\cfg('expresso.token_url','https://vale.expresso.app/api/obter_token');
        $reservationUrl=(string)\cfg('expresso.reservation_url','https://vale.expresso.app/api/reserva');
        $user=(string)(\cfg('expresso.user','') ?: getenv('ACQUAVALE_EXPRESSO_USER') ?: '');
        $password=(string)(\cfg('expresso.password','') ?: getenv('ACQUAVALE_EXPRESSO_PASSWORD') ?: '');
        $timeout=max(5,(int)\cfg('expresso.timeout_seconds',20));

        if ($user==='' || $password==='') {
            throw new RuntimeException('A integração com o Expresso ainda não foi configurada.');
        }

        $tokenResponse=$this->postJson($tokenUrl,[
            'user'=>$user,
            'password'=>$password,
        ],$timeout);

        $token=(string)($tokenResponse['json']['token']??'');
        if ($tokenResponse['error']!=='' || $token==='' || $tokenResponse['http_code']<200 || $tokenResponse['http_code']>=300) {
            throw new RuntimeException('Não foi possível autenticar na API Expresso.');
        }

        $reservationResponse=$this->postJson($reservationUrl,[
            'token'=>$token,
            'numero_reserva'=>$reservationCode,
        ],$timeout);

        if ($reservationResponse['error']!=='') {
            throw new RuntimeException('Falha de comunicação ao consultar a reserva.');
        }

        if ($reservationResponse['http_code']<200 || $reservationResponse['http_code']>=300) {
            throw new RuntimeException('A API Expresso não localizou a reserva informada.');
        }

        $root=$reservationResponse['json'];
        if (!is_array($root)) {
            throw new RuntimeException('Resposta inválida da API Expresso.');
        }

        $status=strtolower(trim((string)($root['status']??'')));
        if ($status==='fail' || $status==='error') {
            $message=trim((string)($root['msg']??$root['message']??''));
            throw new RuntimeException($message!=='' ? $message : 'Reserva não encontrada.');
        }

        $data=$root;
        if (isset($root['data']) && is_array($root['data'])) {
            $data=$root['data'];
        } elseif (isset($root['reserva']) && is_array($root['reserva'])) {
            $data=$root['reserva'];
        }

        $id=$this->read($data,['reserva_id','reservation_id','id']);
        $code=$this->read($data,['numero_reserva','reserva','reservation','codigo','code']);
        $guestName=$this->read($data,['hospede_nome','nome_hospede_principal','hospede_principal','guest_name','nome']);
        $guestCpf=$this->read($data,['hospede_cpf','cpf','guest_cpf']);
        $checkin=$this->read($data,['data_checkin','checkin','checkin_date']);
        $checkout=$this->read($data,['data_checkout','checkout','checkout_date']);
        $adults=$this->read($data,['adultos','adults','numero_adultos','qt_pessoas']);
        $children=$this->read($data,['criancas','children','numero_criancas']);
        $uh=$this->read($data,['uh','unidade_habitacional','room','apartamento']);

        if ($id==='' && $code==='' && $guestName==='') {
            throw new RuntimeException('Reserva não encontrada na API Expresso.');
        }

        return [
            'reservation_id'=>$id,
            'reservation_code'=>$code!=='' ? $code : $reservationCode,
            'guest_name'=>$guestName,
            'guest_cpf'=>$guestCpf,
            'checkin_date'=>$checkin,
            'checkout_date'=>$checkout,
            'adults'=>$adults,
            'children'=>$children,
            'uh'=>$uh,
            'verified_at'=>date(DATE_ATOM),
        ];
    }

    private function postJson(string $url,array $payload,int $timeout): array
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('A extensão cURL do PHP não está habilitada.');
        }

        $ch=curl_init($url);
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_POST=>true,
            CURLOPT_HTTPHEADER=>[
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: AcquaValeVendas/1.0',
            ],
            CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            CURLOPT_CONNECTTIMEOUT=>min(10,$timeout),
            CURLOPT_TIMEOUT=>$timeout,
            CURLOPT_FOLLOWLOCATION=>false,
        ]);

        $raw=curl_exec($ch);
        $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        $error=curl_error($ch);
        curl_close($ch);

        return [
            'http_code'=>$code,
            'raw'=>$raw===false ? '' : (string)$raw,
            'error'=>$error,
            'json'=>json_decode($raw===false ? '' : (string)$raw,true),
        ];
    }

    private function read(array $data,array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key,$data) || $data[$key]===null) continue;
            $value=trim((string)$data[$key]);
            if ($value!=='') return $value;
        }
        return '';
    }
}
