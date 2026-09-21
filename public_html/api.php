<?php
declare(strict_types=1);

require dirname(__DIR__).'/config/bootstrap.php';

require_api_key();

$action=$_GET['action']??'';
$in=json_input();
$pdo=db();

try {
    if ($action==='health') {
        json_response([
            'ok'=>true,
            'service'=>'acquavale-vendas',
            'time'=>date(DATE_ATOM),
            'integration'=>'vale-visitor',
        ]);
    }

    if ($action==='sale-next') {
        $consumer=trim((string)($in['consumer']??'vale-visitor')) ?: 'vale-visitor';
        $ttl=max(1,(int)cfg('api.claim_ttl_minutes',10));
        $cutoff=date('Y-m-d H:i:s',time()-($ttl*60));

        $pdo->beginTransaction();

        $s=$pdo->prepare(
            "SELECT *
             FROM orders
             WHERE status='paid'
               AND (
                    integration_status='pending'
                    OR (integration_status='claimed' AND integration_claimed_at<?)
               )
             ORDER BY id
             LIMIT 1
             FOR UPDATE"
        );
        $s->execute([$cutoff]);
        $o=$s->fetch();

        if (!$o) {
            $pdo->commit();
            json_response(['ok'=>true,'sale'=>null]);
        }

        $claim=bin2hex(random_bytes(32));
        $pdo->prepare(
            "UPDATE orders
             SET integration_status='claimed',
                 integration_claim_token=?,
                 integration_claim_consumer=?,
                 integration_claimed_at=NOW(),
                 updated_at=NOW()
             WHERE id=?"
        )->execute([$claim,$consumer,$o['id']]);

        $s=$pdo->prepare("SELECT * FROM order_items WHERE order_id=? ORDER BY id");
        $s->execute([$o['id']]);
        $items=$s->fetchAll();

        $s=$pdo->prepare(
            "SELECT
                t.id ticket_id,
                t.ticket_code,
                t.valid_from,
                t.valid_to,
                t.validation_mode,
                t.status ticket_status,
                p.sku,
                p.name product_name,
                v.id visitor_id,
                v.first_name,
                v.last_name,
                v.email,
                v.phone,
                v.document_type,
                v.document_number,
                v.sex,
                ti.state integration_state,
                ti.external_reservation_id,
                ti.hcp_visitor_id,
                ti.hcp_reference,
                ti.confirmed_at
             FROM tickets t
             JOIN products p ON p.id=t.product_id
             JOIN visitors v ON v.id=t.visitor_id
             LEFT JOIN ticket_integrations ti
                    ON ti.ticket_id=t.id AND ti.consumer=?
             WHERE t.order_id=?
             ORDER BY t.id"
        );
        $s->execute([$consumer,$o['id']]);
        $tickets=$s->fetchAll();

        $init=$pdo->prepare(
            "INSERT IGNORE INTO ticket_integrations(ticket_id,consumer,state,created_at,updated_at)
             VALUES(?,?,'pending',NOW(),NOW())"
        );

        foreach ($tickets as &$ticket) {
            $init->execute([(int)$ticket['ticket_id'],$consumer]);
            $ticket['photo_url']=absolute_url('api.php?action=visitor-photo&visitor_id='.(int)$ticket['visitor_id']);
            $ticket['integration_state']=$ticket['integration_state'] ?: 'pending';
        }
        unset($ticket);

        $pdo->commit();

        json_response([
            'ok'=>true,
            'sale'=>[
                'id'=>(int)$o['id'],
                'order_code'=>$o['order_code'],
                'buyer_email'=>$o['buyer_email'],
                'buyer_phone'=>$o['buyer_phone'],
                'total'=>(float)$o['total'],
                'paid_at'=>$o['paid_at'],
                'claim_token'=>$claim,
                'claim_ttl_minutes'=>$ttl,
                'consumer'=>$consumer,
                'items'=>$items,
                'tickets'=>$tickets,
            ],
        ]);
    }

    if ($action==='sale-ticket-status') {
        $consumer=trim((string)($in['consumer']??'vale-visitor')) ?: 'vale-visitor';
        $orderCode=trim((string)($in['order_code']??''));
        $ticketCode=strtoupper(trim((string)($in['ticket_code']??'')));
        $state=strtolower(trim((string)($in['state']??'')));

        if ($orderCode==='' || $ticketCode==='') {
            json_response(['ok'=>false,'error'=>'order_code_and_ticket_code_required'],422);
        }

        if (!in_array($state,['pending','imported','syncing','confirmed','failed'],true)) {
            json_response(['ok'=>false,'error'=>'invalid_state'],422);
        }

        $s=$pdo->prepare(
            "SELECT t.id
             FROM tickets t
             JOIN orders o ON o.id=t.order_id
             WHERE o.order_code=? AND t.ticket_code=? AND o.status='paid'
             LIMIT 1"
        );
        $s->execute([$orderCode,$ticketCode]);
        $ticketId=(int)($s->fetchColumn() ?: 0);

        if ($ticketId<1) {
            json_response(['ok'=>false,'error'=>'ticket_not_found'],404);
        }

        $details=$in['details']??null;
        if ($details!==null && !is_array($details)) {
            json_response(['ok'=>false,'error'=>'details_must_be_object'],422);
        }

        $externalReservationId=trim((string)($in['external_reservation_id']??'')) ?: null;
        $hcpVisitorId=trim((string)($in['hcp_visitor_id']??'')) ?: null;
        $hcpReference=trim((string)($in['hcp_reference']??'')) ?: null;
        $message=trim((string)($in['message']??'')) ?: null;
        $confirmedAt=$state==='confirmed' ? date('Y-m-d H:i:s') : null;

        $s=$pdo->prepare(
            "INSERT INTO ticket_integrations
                (ticket_id,consumer,state,external_reservation_id,hcp_visitor_id,hcp_reference,message,details,last_attempt_at,confirmed_at,created_at,updated_at)
             VALUES
                (?,?,?,?,?,?,?,?,NOW(),?,NOW(),NOW())
             ON DUPLICATE KEY UPDATE
                state=VALUES(state),
                external_reservation_id=COALESCE(VALUES(external_reservation_id),external_reservation_id),
                hcp_visitor_id=COALESCE(VALUES(hcp_visitor_id),hcp_visitor_id),
                hcp_reference=COALESCE(VALUES(hcp_reference),hcp_reference),
                message=VALUES(message),
                details=VALUES(details),
                last_attempt_at=NOW(),
                confirmed_at=CASE WHEN VALUES(state)='confirmed' THEN COALESCE(confirmed_at,VALUES(confirmed_at)) ELSE confirmed_at END,
                updated_at=NOW()"
        );

        $s->execute([
            $ticketId,
            $consumer,
            $state,
            $externalReservationId,
            $hcpVisitorId,
            $hcpReference,
            $message,
            $details===null ? null : json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            $confirmedAt,
        ]);

        json_response([
            'ok'=>true,
            'ticket_code'=>$ticketCode,
            'state'=>$state,
            'confirmed_at'=>$confirmedAt,
        ]);
    }

    if ($action==='sale-ack') {
        $code=trim((string)($in['order_code']??''));
        $claim=trim((string)($in['claim_token']??''));
        $consumer=trim((string)($in['consumer']??'vale-visitor')) ?: 'vale-visitor';
        $external=trim((string)($in['external_reference']??'')) ?: null;

        if ($code==='' || $claim==='') {
            json_response(['ok'=>false,'error'=>'order_code_and_claim_token_required'],422);
        }

        $pdo->beginTransaction();

        $s=$pdo->prepare("SELECT * FROM orders WHERE order_code=? FOR UPDATE");
        $s->execute([$code]);
        $o=$s->fetch();

        if (!$o) {
            $pdo->rollBack();
            json_response(['ok'=>false,'error'=>'order_not_found'],404);
        }

        if ($o['integration_status']==='processed') {
            $pdo->commit();
            json_response(['ok'=>true,'already_processed'=>true]);
        }

        if (!hash_equals((string)$o['integration_claim_token'],$claim)) {
            $pdo->rollBack();
            json_response(['ok'=>false,'error'=>'invalid_claim'],409);
        }

        if ((string)($o['integration_claim_consumer']??'')!==$consumer) {
            $pdo->rollBack();
            json_response(['ok'=>false,'error'=>'claim_consumer_mismatch'],409);
        }

        $count=$pdo->prepare(
            "SELECT
                COUNT(*) total,
                SUM(CASE WHEN ti.state='confirmed' THEN 1 ELSE 0 END) confirmed
             FROM tickets t
             LEFT JOIN ticket_integrations ti
                    ON ti.ticket_id=t.id AND ti.consumer=?
             WHERE t.order_id=?"
        );
        $count->execute([$consumer,$o['id']]);
        $status=$count->fetch() ?: ['total'=>0,'confirmed'=>0];

        $total=(int)$status['total'];
        $confirmed=(int)$status['confirmed'];

        if ($total<1 || $confirmed!==$total) {
            $pdo->rollBack();
            json_response([
                'ok'=>false,
                'error'=>'hikcentral_confirmation_incomplete',
                'confirmed'=>$confirmed,
                'total'=>$total,
            ],409);
        }

        $pdo->prepare(
            "INSERT INTO integration_receipts(order_id,consumer,external_reference,processed_at)
             VALUES(?,?,?,NOW())
             ON DUPLICATE KEY UPDATE
                external_reference=COALESCE(VALUES(external_reference),external_reference),
                processed_at=VALUES(processed_at)"
        )->execute([$o['id'],$consumer,$external]);

        $pdo->prepare(
            "UPDATE orders
             SET integration_status='processed',
                 integration_processed_at=NOW(),
                 updated_at=NOW()
             WHERE id=?"
        )->execute([$o['id']]);

        $pdo->commit();

        json_response([
            'ok'=>true,
            'processed'=>true,
            'confirmed'=>$confirmed,
            'total'=>$total,
        ]);
    }

    if ($action==='sale-status') {
        $orderCode=trim((string)($in['order_code']??($_GET['order_code']??'')));
        $consumer=trim((string)($in['consumer']??($_GET['consumer']??'vale-visitor'))) ?: 'vale-visitor';

        if ($orderCode==='') {
            json_response(['ok'=>false,'error'=>'order_code_required'],422);
        }

        $s=$pdo->prepare(
            "SELECT
                o.order_code,
                o.integration_status,
                o.integration_claimed_at,
                o.integration_processed_at,
                t.ticket_code,
                v.first_name,
                v.last_name,
                COALESCE(ti.state,'pending') state,
                ti.external_reservation_id,
                ti.hcp_visitor_id,
                ti.hcp_reference,
                ti.message,
                ti.last_attempt_at,
                ti.confirmed_at
             FROM orders o
             JOIN tickets t ON t.order_id=o.id
             JOIN visitors v ON v.id=t.visitor_id
             LEFT JOIN ticket_integrations ti
                    ON ti.ticket_id=t.id AND ti.consumer=?
             WHERE o.order_code=?
             ORDER BY t.id"
        );
        $s->execute([$consumer,$orderCode]);
        $rows=$s->fetchAll();

        if (!$rows) {
            json_response(['ok'=>false,'error'=>'order_not_found'],404);
        }

        json_response([
            'ok'=>true,
            'order_code'=>$orderCode,
            'integration_status'=>$rows[0]['integration_status'],
            'tickets'=>array_map(static fn(array $row): array => [
                'ticket_code'=>$row['ticket_code'],
                'visitor_name'=>$row['first_name'].' '.$row['last_name'],
                'state'=>$row['state'],
                'external_reservation_id'=>$row['external_reservation_id'],
                'hcp_visitor_id'=>$row['hcp_visitor_id'],
                'hcp_reference'=>$row['hcp_reference'],
                'message'=>$row['message'],
                'last_attempt_at'=>$row['last_attempt_at'],
                'confirmed_at'=>$row['confirmed_at'],
            ],$rows),
        ]);
    }

    if ($action==='ticket-validate') {
        $code=strtoupper(trim((string)($in['ticket_code']??'')));
        $gate=trim((string)($in['gate_code']??'')) ?: null;
        $idem=trim((string)($in['idempotency_key']??'')) ?: null;

        if ($code==='') {
            json_response(['ok'=>false,'error'=>'ticket_code_required'],422);
        }

        $pdo->beginTransaction();

        if ($idem) {
            $s=$pdo->prepare(
                "SELECT tr.*,t.ticket_code
                 FROM ticket_redemptions tr
                 JOIN tickets t ON t.id=tr.ticket_id
                 WHERE tr.idempotency_key=?"
            );
            $s->execute([$idem]);

            if ($x=$s->fetch()) {
                $pdo->commit();
                json_response([
                    'ok'=>true,
                    'valid'=>true,
                    'idempotent_replay'=>true,
                    'ticket_code'=>$x['ticket_code'],
                    'validated_at'=>$x['validated_at'],
                ]);
            }
        }

        $s=$pdo->prepare(
            "SELECT
                t.*,
                o.status order_status,
                p.name product_name,
                v.first_name,
                v.last_name,
                v.document_type,
                v.document_number,
                v.id visitor_id
             FROM tickets t
             JOIN orders o ON o.id=t.order_id
             JOIN products p ON p.id=t.product_id
             JOIN visitors v ON v.id=t.visitor_id
             WHERE t.ticket_code=?
             FOR UPDATE"
        );
        $s->execute([$code]);
        $t=$s->fetch();

        if (!$t) {
            $pdo->rollBack();
            json_response(['ok'=>true,'valid'=>false,'reason'=>'not_found'],404);
        }

        $today=date('Y-m-d');

        if ($t['order_status']!=='paid' || $t['status']!=='active') {
            $pdo->rollBack();
            json_response(['ok'=>true,'valid'=>false,'reason'=>'inactive']);
        }

        if ($today<$t['valid_from'] || $today>$t['valid_to']) {
            $pdo->rollBack();
            json_response([
                'ok'=>true,
                'valid'=>false,
                'reason'=>'outside_validity',
                'valid_from'=>$t['valid_from'],
                'valid_to'=>$t['valid_to'],
            ]);
        }

        if ($t['validation_mode']==='once_total') {
            $s=$pdo->prepare("SELECT COUNT(*) FROM ticket_redemptions WHERE ticket_id=?");
            $s->execute([$t['id']]);

            if ((int)$s->fetchColumn()>0) {
                $pdo->rollBack();
                json_response(['ok'=>true,'valid'=>false,'reason'=>'already_used']);
            }
        } elseif ($t['validation_mode']==='once_per_day') {
            $s=$pdo->prepare("SELECT COUNT(*) FROM ticket_redemptions WHERE ticket_id=? AND visit_date=?");
            $s->execute([$t['id'],$today]);

            if ((int)$s->fetchColumn()>0) {
                $pdo->rollBack();
                json_response(['ok'=>true,'valid'=>false,'reason'=>'already_used_today']);
            }
        }

        if ($t['validation_mode']!=='unlimited_validity') {
            $pdo->prepare(
                "INSERT INTO ticket_redemptions(ticket_id,visit_date,gate_code,idempotency_key,validated_at,source_ip)
                 VALUES(?,?,?,?,NOW(),?)"
            )->execute([$t['id'],$today,$gate,$idem,client_ip()]);

            if ($t['validation_mode']==='once_total') {
                $pdo->prepare("UPDATE tickets SET status='used' WHERE id=?")->execute([$t['id']]);
            }
        }

        $pdo->commit();

        json_response([
            'ok'=>true,
            'valid'=>true,
            'ticket'=>[
                'ticket_code'=>$t['ticket_code'],
                'product'=>$t['product_name'],
                'visitor_id'=>(int)$t['visitor_id'],
                'visitor_name'=>$t['first_name'].' '.$t['last_name'],
                'document_type'=>$t['document_type'],
                'document_last4'=>substr($t['document_number'],-4),
                'valid_from'=>$t['valid_from'],
                'valid_to'=>$t['valid_to'],
                'photo_endpoint'=>url('api.php?action=visitor-photo&visitor_id='.(int)$t['visitor_id']),
            ],
        ]);
    }

    if ($action==='visitor-photo') {
        $id=(int)($_GET['visitor_id']??0);
        $s=$pdo->prepare(
            "SELECT v.photo_path
             FROM visitors v
             JOIN orders o ON o.id=v.order_id
             WHERE v.id=? AND o.status='paid'"
        );
        $s->execute([$id]);
        $v=$s->fetch();

        if (!$v) {
            http_response_code(404);
            exit;
        }

        $file=STORAGE_ROOT.'/private/visitors/'.basename($v['photo_path']);
        if (!is_file($file)) {
            http_response_code(404);
            exit;
        }

        header('Content-Type: '.((new finfo(FILEINFO_MIME_TYPE))->file($file) ?: 'application/octet-stream'));
        header('Cache-Control: private, no-store');
        readfile($file);
        exit;
    }

    json_response(['ok'=>false,'error'=>'unknown_action'],404);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log((string)$e);

    json_response([
        'ok'=>false,
        'error'=>'internal_error',
        'message'=>(bool)cfg('app.debug',false) ? $e->getMessage() : null,
    ],500);
}
