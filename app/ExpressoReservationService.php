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

        $user=trim((string)(\cfg('expresso.user','') ?: getenv('ACQUAVALE_EXPRESSO_USER') ?: ''));
        $password=(string)(\cfg('expresso.password','') ?: getenv('ACQUAVALE_EXPRESSO_PASSWORD') ?: '');
        if ($user==='' || $password==='') {
            throw new RuntimeException('Configure o usuário e a senha da API Expresso.');
        }

        $timeout=max(5,(int)\cfg('expresso.timeout_seconds',20));
        $tokenResponse=$this->postJson(
            (string)\cfg('expresso.token_url','https://vale.expresso.app/api/obter_token'),
            ['user'=>$user,'password'=>$password],
            $timeout,
            'autenticar'
        );
        $token=$tokenResponse['token']??null;
        if (!is_string($token) || trim($token)==='' || strtolower(trim($token))==='null') {
            throw new RuntimeException(
                'A API Expresso respondeu sem um token válido. Confira as credenciais e a liberação do usuário na API.'
            );
        }

        // Same JSON contract used by iPlate's fetchReservationDetails.
        $response=$this->postJson(
            (string)\cfg('expresso.reservation_url','https://vale.expresso.app/api/reserva'),
            ['token'=>$token,'numero_reserva'=>$reservationCode],
            $timeout,
            'consultar a reserva'
        );
        if (in_array(strtolower($this->read($response,['status'])),['fail','error'],true)) {
            throw new RuntimeException('A API Expresso não confirmou a reserva informada.');
        }

        $data=$response;
        foreach (['data','reserva'] as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                $data=$response[$key];
                break;
            }
        }
        $id=$this->read($data,['reserva_id','reservation_id','id']);
        $code=$this->read($data,['numero_reserva','reservation_code','reserva','reservation','codigo','code']);
        $guestName=$this->read($data,['hospede_nome','nome_hospede_principal','hospede_principal','guest_name','nome']);
        if ($id==='' && $code==='') {
            throw new RuntimeException('A resposta da API Expresso não contém uma reserva válida.');
        }
        if ($code!=='' && $code!==$reservationCode) {
            throw new RuntimeException('A API Expresso retornou uma reserva diferente da solicitada.');
        }

        return [
            'reservation_id'=>$id,
            'reservation_code'=>$code!=='' ? $code : $reservationCode,
            'guest_name'=>$guestName,
            'guest_cpf'=>$this->read($data,['hospede_cpf','cpf','guest_cpf']),
            'checkin_date'=>$this->read($data,['data_checkin','checkin','checkin_date']),
            'checkout_date'=>$this->read($data,['data_checkout','checkout','checkout_date']),
            'adults'=>$this->read($data,['adultos','adults','numero_adultos','qt_pessoas']),
            'children'=>$this->read($data,['criancas','children','numero_criancas']),
            'guest_count'=>$this->read($data,['guest_count','total_hospedes']),
            'uh'=>$this->read($data,['uh','unidade_habitacional','room','apartamento']),
            'status'=>$this->read($data,['status']),
            'source'=>'expresso_api',
            'verified_at'=>date(DATE_ATOM),
        ];
    }

    private function postJson(string $url,array $payload,int $timeout,string $operation): array
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('A extensão cURL do PHP não está habilitada.');
        }
        $ch=curl_init($url);
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER=>['Content-Type: application/json; charset=utf-8','Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT=>min(10,$timeout),
            CURLOPT_TIMEOUT=>$timeout,
            CURLOPT_FOLLOWLOCATION=>false,
        ]);
        $raw=curl_exec($ch);
        $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        $errno=curl_errno($ch);
        curl_close($ch);
        if ($raw===false || $errno!==0) {
            throw new RuntimeException("Falha de conexão com a API Expresso ao {$operation} (cURL {$errno}).");
        }
        if ($http<200 || $http>=300) {
            throw new RuntimeException("A API Expresso retornou HTTP {$http} ao {$operation}. Confira o endpoint configurado.");
        }
        $json=json_decode((string)$raw,true);
        if (!is_array($json)) {
            throw new RuntimeException("A API Expresso retornou uma resposta JSON inválida ao {$operation}.");
        }
        return $json;
    }

    private function read(array $data,array $keys): string
    {
        foreach ($keys as $key) {
            $value=$data[$key]??null;
            if (is_string($value) || is_int($value) || is_float($value)) {
                $text=trim((string)$value);
                if ($text!=='' && strtolower($text)!=='null') return $text;
            }
        }
        return '';
    }

}
