<?php
declare(strict_types=1);

namespace AcquaVale;

use RuntimeException;

final class IPlateReservationService
{
    public function lookup(string $reservationCode): array
    {
        $reservationCode=trim($reservationCode);
        if ($reservationCode==='') {
            throw new RuntimeException('Informe o número da reserva.');
        }

        $serverUrl=(string)\cfg(
            'iplate.server_url',
            'https://vale.expresso.app/iplate/backend/api/vehicle-entry-create.php'
        );
        $username=(string)\cfg('iplate.username','');
        $password=(string)\cfg('iplate.password','');
        $timeout=max(5,(int)\cfg('iplate.timeout_seconds',20));

        if ($serverUrl==='' || $username==='' || $password==='') {
            throw new RuntimeException('A integração com o iPlate ainda não foi configurada.');
        }

        $token=$this->getApiToken($serverUrl,$username,$password,$timeout,false);

        try {
            $reservation=$this->searchExact($serverUrl,$token,$reservationCode,$timeout);
        } catch (UnauthorizedIPlateException) {
            unset($_SESSION['iplate_api_token'],$_SESSION['iplate_api_token_at']);
            $token=$this->getApiToken($serverUrl,$username,$password,$timeout,true);
            $reservation=$this->searchExact($serverUrl,$token,$reservationCode,$timeout);
        }

        if (!$reservation) {
            throw new RuntimeException('Reserva não localizada ou não está com status confirmado/check-in.');
        }

        return [
            'reservation_id'=>(string)($reservation['reservation_id']??''),
            'reservation_code'=>(string)($reservation['reservation_code']??$reservationCode),
            'guest_name'=>(string)($reservation['guest_name']??''),
            'guest_cpf'=>'',
            'checkin_date'=>(string)($reservation['checkin_date']??''),
            'checkout_date'=>(string)($reservation['checkout_date']??''),
            'adults'=>'',
            'children'=>'',
            'guest_count'=>(string)($reservation['guest_count']??''),
            'uh'=>'',
            'status'=>(string)($reservation['status']??''),
            'source'=>'iplate_backend',
            'verified_at'=>date(DATE_ATOM),
        ];
    }

    private function getApiToken(
        string $serverUrl,
        string $username,
        string $password,
        int $timeout,
        bool $forceRefresh
    ): string {
        if (
            !$forceRefresh &&
            !empty($_SESSION['iplate_api_token']) &&
            !empty($_SESSION['iplate_api_token_at']) &&
            (time()-(int)$_SESSION['iplate_api_token_at'])<1800
        ) {
            return (string)$_SESSION['iplate_api_token'];
        }

        $loginUrl=$this->deriveApiUrl($serverUrl,'login.php');
        $response=$this->postForm($loginUrl,[
            'username'=>$username,
            'password'=>$password,
        ],$timeout);

        $json=$response['json'];
        $token=is_array($json)
            ? trim((string)($json['user']['api_token']??''))
            : '';

        if (
            $response['error']!=='' ||
            $response['http_code']<200 ||
            $response['http_code']>=300 ||
            !is_array($json) ||
            empty($json['success']) ||
            $token===''
        ) {
            $message=is_array($json)
                ? trim((string)($json['message']??''))
                : '';
            throw new RuntimeException(
                $message!=='' ? $message : 'Não foi possível autenticar no backend iPlate.'
            );
        }

        $_SESSION['iplate_api_token']=$token;
        $_SESSION['iplate_api_token_at']=time();

        return $token;
    }

    private function searchExact(
        string $serverUrl,
        string $token,
        string $reservationCode,
        int $timeout
    ): ?array {
        $url=$this->deriveApiUrl($serverUrl,'reservation-search.php')
            .'?'.http_build_query([
                'api_token'=>$token,
                'term'=>$reservationCode,
                'exact'=>'1',
            ]);

        $response=$this->getJson($url,$timeout);

        if ($response['http_code']===401) {
            throw new UnauthorizedIPlateException();
        }

        if (
            $response['error']!=='' ||
            $response['http_code']<200 ||
            $response['http_code']>=300
        ) {
            throw new RuntimeException('Falha ao consultar a reserva no backend iPlate.');
        }

        $json=$response['json'];
        if (!is_array($json) || empty($json['success'])) {
            $message=is_array($json)
                ? trim((string)($json['message']??''))
                : '';
            throw new RuntimeException(
                $message!=='' ? $message : 'Resposta inválida do backend iPlate.'
            );
        }

        foreach (($json['items']??[]) as $item) {
            if (!is_array($item)) continue;
            if ((string)($item['reservation_code']??'')===$reservationCode) {
                return $item;
            }
        }

        // Compatibilidade temporária com o backend iPlate antigo,
        // que ainda não reconhecia exact=1 e limitava a busca ao dia atual.
        $legacyUrl=$this->deriveApiUrl($serverUrl,'reservation-search.php')
            .'?'.http_build_query([
                'api_token'=>$token,
                'term'=>$reservationCode,
            ]);
        $legacy=$this->getJson($legacyUrl,$timeout);
        if ($legacy['http_code']===401) {
            throw new UnauthorizedIPlateException();
        }
        if (
            $legacy['error']==='' &&
            $legacy['http_code']>=200 &&
            $legacy['http_code']<300 &&
            is_array($legacy['json'])
        ) {
            foreach (($legacy['json']['items']??[]) as $item) {
                if (!is_array($item)) continue;
                if ((string)($item['reservation_code']??'')===$reservationCode) {
                    return $item;
                }
            }
        }

        return null;
    }

    private function deriveApiUrl(string $serverUrl,string $endpoint): string
    {
        $marker='/api/';
        $position=strpos($serverUrl,$marker);

        if ($position!==false) {
            return substr($serverUrl,0,$position+strlen($marker)).$endpoint;
        }

        return rtrim($serverUrl,'/').'/api/'.$endpoint;
    }

    private function postForm(string $url,array $fields,int $timeout): array
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('A extensão cURL do PHP não está habilitada.');
        }

        $ch=curl_init($url);
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>http_build_query($fields),
            CURLOPT_HTTPHEADER=>[
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
                'User-Agent: AcquaValeVendas/1.0',
            ],
            CURLOPT_CONNECTTIMEOUT=>min(10,$timeout),
            CURLOPT_TIMEOUT=>$timeout,
            CURLOPT_FOLLOWLOCATION=>false,
        ]);

        return $this->finishCurl($ch);
    }

    private function getJson(string $url,int $timeout): array
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('A extensão cURL do PHP não está habilitada.');
        }

        $ch=curl_init($url);
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_HTTPHEADER=>[
                'Accept: application/json',
                'User-Agent: AcquaValeVendas/1.0',
            ],
            CURLOPT_CONNECTTIMEOUT=>min(10,$timeout),
            CURLOPT_TIMEOUT=>$timeout,
            CURLOPT_FOLLOWLOCATION=>false,
        ]);

        return $this->finishCurl($ch);
    }

    private function finishCurl($ch): array
    {
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
}

final class UnauthorizedIPlateException extends RuntimeException
{
}
