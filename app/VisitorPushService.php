<?php
declare(strict_types=1);

namespace AcquaVale;

use RuntimeException;
use Throwable;

final class VisitorPushService
{
    private string $consumer;
    private string $url;
    private string $secret;
    private bool $tlsVerify;

    public function __construct()
    {
        $this->consumer=trim((string)\cfg('visitor_receiver.consumer','vale-visitor')) ?: 'vale-visitor';
        $this->url=trim((string)\cfg('visitor_receiver.url',''));
        $this->secret=(string)\cfg('visitor_receiver.shared_secret','');
        $this->tlsVerify=(bool)\cfg('visitor_receiver.tls_verify',true);
    }

    public function configured(): bool
    {
        return $this->url!=='' && $this->secret!=='';
    }

    public function dispatchOrder(int $orderId,bool $forceResend=false): array
    {
        if (!$this->configured()) {
            return ['ok'=>false,'skipped'=>true,'error'=>'receiver_not_configured'];
        }

        $pdo=\db();
        $pdo->beginTransaction();

        try {
            $s=$pdo->prepare(
                "SELECT *
                 FROM acquavale_vendas_orders
                 WHERE id=? AND status='paid'
                 FOR UPDATE"
            );
            $s->execute([$orderId]);
            $order=$s->fetch();

            if (!$order) {
                $pdo->rollBack();
                throw new RuntimeException('Pedido pago não encontrado para integração.');
            }

            if ($order['integration_status']==='processed' && !$forceResend) {
                $pdo->commit();
                return ['ok'=>true,'already_processed'=>true];
            }

            $claim=(string)($order['integration_claim_token']??'');
            if ($claim==='') $claim=bin2hex(random_bytes(32));

            if ($forceResend) {
                // Debug resend must not regress a processed order back to claimed.
                $pdo->prepare(
                    "UPDATE acquavale_vendas_orders
                     SET integration_claim_token=COALESCE(NULLIF(integration_claim_token,''),?),
                         integration_claim_consumer=COALESCE(NULLIF(integration_claim_consumer,''),?),
                         integration_push_attempts=COALESCE(integration_push_attempts,0)+1,
                         integration_push_last_at=NOW(),
                         updated_at=NOW()
                     WHERE id=?"
                )->execute([$claim,$this->consumer,$orderId]);
            } else {
                $pdo->prepare(
                    "UPDATE acquavale_vendas_orders
                     SET integration_status='claimed',
                         integration_claim_token=?,
                         integration_claim_consumer=?,
                         integration_claimed_at=NOW(),
                         integration_push_attempts=COALESCE(integration_push_attempts,0)+1,
                         integration_push_last_at=NOW(),
                         updated_at=NOW()
                     WHERE id=?"
                )->execute([$claim,$this->consumer,$orderId]);
            }

            $s=$pdo->prepare(
                "SELECT oi.*
                 FROM acquavale_vendas_order_items oi
                 WHERE oi.order_id=?
                 ORDER BY oi.id"
            );
            $s->execute([$orderId]);
            $items=$s->fetchAll();

            $s=$pdo->prepare(
                "SELECT
                    t.id ticket_id,t.ticket_code,t.valid_from,t.valid_to,t.validation_mode,t.status ticket_status,
                    p.sku,p.name product_name,
                    v.id visitor_id,v.first_name,v.last_name,v.email,v.phone,
                    v.document_type,v.document_number,v.sex
                 FROM acquavale_vendas_tickets t
                 JOIN acquavale_vendas_products p ON p.id=t.product_id
                 JOIN acquavale_vendas_visitors v ON v.id=t.visitor_id
                 WHERE t.order_id=?
                 ORDER BY t.id"
            );
            $s->execute([$orderId]);
            $tickets=$s->fetchAll();

            foreach ($tickets as &$ticket) {
                $ticket['photo_url']=\absolute_url('api.php?action=visitor-photo&visitor_id='.(int)$ticket['visitor_id']);
            }
            unset($ticket);

            $payload=[
                'version'=>1,
                'event'=>'sale.paid',
                'consumer'=>$this->consumer,
                'order'=>[
                    'id'=>(int)$order['id'],
                    'order_code'=>$order['order_code'],
                    'buyer_email'=>$order['buyer_email'],
                    'buyer_phone'=>$order['buyer_phone'],
                    'total'=>(float)$order['total'],
                    'paid_at'=>$order['paid_at'],
                    'claim_token'=>$claim,
                    'reservation'=>[
                        'code'=>$order['expresso_reservation_code']??null,
                        'id'=>$order['expresso_reservation_id']??null,
                        'guest_name'=>$order['expresso_guest_name']??null,
                        'checkin'=>$order['expresso_checkin_date']??null,
                        'checkout'=>$order['expresso_checkout_date']??null,
                        'uh'=>$order['expresso_uh']??null,
                    ],
                    'items'=>$items,
                    'tickets'=>$tickets,
                ],
            ];

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $raw=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $timestamp=(string)time();
        $deliverySeed=$payload['order']['order_code']."\n".$payload['order']['claim_token'];
        if ($forceResend) $deliverySeed.="\nDEBUG-".bin2hex(random_bytes(16));
        $deliveryId=hash('sha256',$deliverySeed);
        $signature=hash_hmac('sha256',$timestamp."\n".$raw,$this->secret);

        try {
            $response=$this->post($raw,[
                'Content-Type: application/json',
                'Accept: application/json',
                'X-AQV-Timestamp: '.$timestamp,
                'X-AQV-Delivery-Id: '.$deliveryId,
                'X-AQV-Signature: sha256='.$signature,
            ]);

            \db()->prepare(
                "UPDATE acquavale_vendas_orders
                 SET integration_push_last_error=NULL,updated_at=NOW()
                 WHERE id=?"
            )->execute([$orderId]);

            return [
                'ok'=>true,
                'http_code'=>$response['http_code'],
                'response'=>$response['json'],
                'force_resend'=>$forceResend,
            ];
        } catch (Throwable $e) {
            \db()->prepare(
                "UPDATE acquavale_vendas_orders
                 SET integration_push_last_error=?,updated_at=NOW()
                 WHERE id=?"
            )->execute([mb_substr($e->getMessage(),0,1000),$orderId]);
            throw $e;
        }
    }

    public function dispatchPending(int $limit=20): array
    {
        $limit=max(1,min(100,$limit));
        $s=\db()->query(
            "SELECT id
             FROM acquavale_vendas_orders
             WHERE status='paid'
               AND integration_status IN ('pending','claimed','error')
             ORDER BY COALESCE(integration_push_last_at,'1970-01-01'),id
             LIMIT {$limit}"
        );

        $result=['processed'=>0,'accepted'=>0,'errors'=>[]];
        foreach ($s->fetchAll() as $row) {
            $result['processed']++;
            try {
                $response=$this->dispatchOrder((int)$row['id']);
                if (!empty($response['ok'])) $result['accepted']++;
            } catch (Throwable $e) {
                $result['errors'][]=[
                    'order_id'=>(int)$row['id'],
                    'message'=>$e->getMessage(),
                ];
            }
        }
        return $result;
    }

    private function post(string $raw,array $headers): array
    {
        if (!extension_loaded('curl')) throw new RuntimeException('Extensão cURL indisponível.');

        $ch=curl_init($this->url);
        curl_setopt_array($ch,[
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>$raw,
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>3,
            CURLOPT_TIMEOUT=>8,
            CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_SSL_VERIFYPEER=>$this->tlsVerify,
            CURLOPT_SSL_VERIFYHOST=>$this->tlsVerify ? 2 : 0,
            CURLOPT_FOLLOWLOCATION=>false,
        ]);

        $body=curl_exec($ch);
        $error=curl_error($ch);
        $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body===false) throw new RuntimeException('Vale Visitor indisponível: '.$error);
        $decoded=json_decode((string)$body,true);
        if ($code<200 || $code>=300) {
            $message=is_array($decoded) ? (string)($decoded['message']??$decoded['error']??'') : '';
            throw new RuntimeException('Vale Visitor HTTP '.$code.($message!=='' ? ': '.$message : ''));
        }
        if (!is_array($decoded) || empty($decoded['ok'])) {
            throw new RuntimeException('Vale Visitor retornou uma resposta inválida.');
        }

        return ['http_code'=>$code,'json'=>$decoded];
    }
}
