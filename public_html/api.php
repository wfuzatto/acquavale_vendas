<?php
declare(strict_types=1);
require dirname(__DIR__).'/config/bootstrap.php';
require_api_key();$action=$_GET['action']??'';$in=json_input();$pdo=db();
try{
 if($action==='health')json_response(['ok'=>true,'service'=>'acquavale-vendas','time'=>date(DATE_ATOM)]);
 if($action==='sale-next'){
  $consumer=trim((string)($in['consumer']??'default'))?:'default';$ttl=max(1,(int)cfg('api.claim_ttl_minutes',10));$cutoff=date('Y-m-d H:i:s',time()-($ttl*60));
  $pdo->beginTransaction();$s=$pdo->prepare("SELECT * FROM orders WHERE status='paid' AND (integration_status='pending' OR (integration_status='claimed' AND integration_claimed_at<?)) ORDER BY id LIMIT 1 FOR UPDATE");$s->execute([$cutoff]);$o=$s->fetch();
  if(!$o){$pdo->commit();json_response(['ok'=>true,'sale'=>null]);}
  $claim=bin2hex(random_bytes(32));$pdo->prepare("UPDATE orders SET integration_status='claimed',integration_claim_token=?,integration_claimed_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$claim,$o['id']]);
  $s=$pdo->prepare("SELECT * FROM order_items WHERE order_id=? ORDER BY id");$s->execute([$o['id']]);$items=$s->fetchAll();
  $s=$pdo->prepare("SELECT t.ticket_code,t.valid_from,t.valid_to,t.validation_mode,t.status ticket_status,p.sku,p.name product_name,v.id visitor_id,v.first_name,v.last_name,v.email,v.phone,v.document_type,v.document_number,v.sex FROM tickets t JOIN products p ON p.id=t.product_id JOIN visitors v ON v.id=t.visitor_id WHERE t.order_id=? ORDER BY t.id");$s->execute([$o['id']]);$tickets=$s->fetchAll();$pdo->commit();
  json_response(['ok'=>true,'sale'=>['id'=>(int)$o['id'],'order_code'=>$o['order_code'],'buyer_email'=>$o['buyer_email'],'buyer_phone'=>$o['buyer_phone'],'total'=>(float)$o['total'],'paid_at'=>$o['paid_at'],'claim_token'=>$claim,'consumer'=>$consumer,'items'=>$items,'tickets'=>$tickets]]);
 }
 if($action==='sale-ack'){
  $code=trim((string)($in['order_code']??''));$claim=trim((string)($in['claim_token']??''));$consumer=trim((string)($in['consumer']??'default'))?:'default';$external=trim((string)($in['external_reference']??''))?:null;
  if(!$code||!$claim)json_response(['ok'=>false,'error'=>'order_code_and_claim_token_required'],422);
  $pdo->beginTransaction();$s=$pdo->prepare("SELECT * FROM orders WHERE order_code=? FOR UPDATE");$s->execute([$code]);$o=$s->fetch();
  if(!$o){$pdo->rollBack();json_response(['ok'=>false,'error'=>'order_not_found'],404);}if($o['integration_status']==='processed'){$pdo->commit();json_response(['ok'=>true,'already_processed'=>true]);}
  if(!hash_equals((string)$o['integration_claim_token'],$claim)){$pdo->rollBack();json_response(['ok'=>false,'error'=>'invalid_claim'],409);}
  $pdo->prepare("INSERT INTO integration_receipts(order_id,consumer,external_reference,processed_at) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE external_reference=COALESCE(VALUES(external_reference),external_reference)")->execute([$o['id'],$consumer,$external]);
  $pdo->prepare("UPDATE orders SET integration_status='processed',integration_processed_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$o['id']]);$pdo->commit();json_response(['ok'=>true,'processed'=>true]);
 }
 if($action==='ticket-validate'){
  $code=strtoupper(trim((string)($in['ticket_code']??'')));$gate=trim((string)($in['gate_code']??''))?:null;$idem=trim((string)($in['idempotency_key']??''))?:null;if(!$code)json_response(['ok'=>false,'error'=>'ticket_code_required'],422);
  $pdo->beginTransaction();
  if($idem){$s=$pdo->prepare("SELECT tr.*,t.ticket_code FROM ticket_redemptions tr JOIN tickets t ON t.id=tr.ticket_id WHERE tr.idempotency_key=?");$s->execute([$idem]);if($x=$s->fetch()){$pdo->commit();json_response(['ok'=>true,'valid'=>true,'idempotent_replay'=>true,'ticket_code'=>$x['ticket_code'],'validated_at'=>$x['validated_at']]);}}
  $s=$pdo->prepare("SELECT t.*,o.status order_status,p.name product_name,v.first_name,v.last_name,v.document_type,v.document_number,v.id visitor_id FROM tickets t JOIN orders o ON o.id=t.order_id JOIN products p ON p.id=t.product_id JOIN visitors v ON v.id=t.visitor_id WHERE t.ticket_code=? FOR UPDATE");$s->execute([$code]);$t=$s->fetch();
  if(!$t){$pdo->rollBack();json_response(['ok'=>true,'valid'=>false,'reason'=>'not_found'],404);}$today=date('Y-m-d');
  if($t['order_status']!=='paid'||$t['status']!=='active'){$pdo->rollBack();json_response(['ok'=>true,'valid'=>false,'reason'=>'inactive']);}
  if($today<$t['valid_from']||$today>$t['valid_to']){$pdo->rollBack();json_response(['ok'=>true,'valid'=>false,'reason'=>'outside_validity','valid_from'=>$t['valid_from'],'valid_to'=>$t['valid_to']]);}
  if($t['validation_mode']==='once_total'){$s=$pdo->prepare("SELECT COUNT(*) FROM ticket_redemptions WHERE ticket_id=?");$s->execute([$t['id']]);if((int)$s->fetchColumn()>0){$pdo->rollBack();json_response(['ok'=>true,'valid'=>false,'reason'=>'already_used']);}}
  elseif($t['validation_mode']==='once_per_day'){$s=$pdo->prepare("SELECT COUNT(*) FROM ticket_redemptions WHERE ticket_id=? AND visit_date=?");$s->execute([$t['id'],$today]);if((int)$s->fetchColumn()>0){$pdo->rollBack();json_response(['ok'=>true,'valid'=>false,'reason'=>'already_used_today']);}}
  if($t['validation_mode']!=='unlimited_validity'){$pdo->prepare("INSERT INTO ticket_redemptions(ticket_id,visit_date,gate_code,idempotency_key,validated_at,source_ip) VALUES(?,?,?,?,NOW(),?)")->execute([$t['id'],$today,$gate,$idem,client_ip()]);if($t['validation_mode']==='once_total')$pdo->prepare("UPDATE tickets SET status='used' WHERE id=?")->execute([$t['id']]);}
  $pdo->commit();json_response(['ok'=>true,'valid'=>true,'ticket'=>['ticket_code'=>$t['ticket_code'],'product'=>$t['product_name'],'visitor_id'=>(int)$t['visitor_id'],'visitor_name'=>$t['first_name'].' '.$t['last_name'],'document_type'=>$t['document_type'],'document_last4'=>substr($t['document_number'],-4),'valid_from'=>$t['valid_from'],'valid_to'=>$t['valid_to'],'photo_endpoint'=>url('api.php?action=visitor-photo&visitor_id='.(int)$t['visitor_id'])]]);
 }
 if($action==='visitor-photo'){
  $id=(int)($_GET['visitor_id']??0);$s=$pdo->prepare("SELECT v.photo_path FROM visitors v JOIN orders o ON o.id=v.order_id WHERE v.id=? AND o.status='paid'");$s->execute([$id]);$v=$s->fetch();if(!$v){http_response_code(404);exit;}
  $file=STORAGE_ROOT.'/private/visitors/'.basename($v['photo_path']);if(!is_file($file)){http_response_code(404);exit;}header('Content-Type: '.((new finfo(FILEINFO_MIME_TYPE))->file($file)?:'application/octet-stream'));header('Cache-Control: private, no-store');readfile($file);exit;
 }
 json_response(['ok'=>false,'error'=>'unknown_action'],404);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log((string)$e);json_response(['ok'=>false,'error'=>'internal_error','message'=>(bool)cfg('app.debug',false)?$e->getMessage():null],500);}
