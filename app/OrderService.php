<?php
declare(strict_types=1);

namespace AcquaVale;

use DateTimeImmutable;
use RuntimeException;
use Throwable;

final class OrderService {
    public function create(array $payload,array $photos): array {
        $cart=$payload['cart'] ?? [];
        if (is_string($cart)) $cart=json_decode($cart,true) ?: [];
        if (!is_array($cart) || !$cart) throw new RuntimeException('Carrinho vazio.');

        $normalized=[];
        foreach($cart as $productId=>$qty){
            $productId=(int)$productId; $qty=max(0,min(20,(int)$qty));
            if($productId>0 && $qty>0) $normalized[$productId]=$qty;
        }
        if(!$normalized) throw new RuntimeException('Carrinho vazio.');

        $ph=implode(',',array_fill(0,count($normalized),'?'));
        $s=\db()->prepare("SELECT * FROM products WHERE id IN ($ph) AND active=1");
        $s->execute(array_keys($normalized));
        $products=[]; foreach($s->fetchAll() as $p) $products[(int)$p['id']]=$p;
        if(count($products)!==count($normalized)) throw new RuntimeException('Um dos produtos não está disponível.');

        $ticketUnits=0; $subtotal=0.0;
        foreach($normalized as $id=>$qty){
            $subtotal+=(float)$products[$id]['price']*$qty;
            if((int)$products[$id]['requires_visitor']===1) $ticketUnits+=$qty;
        }
        $visitors=$payload['visitors'] ?? [];
        if(!is_array($visitors) || count($visitors)!==$ticketUnits) throw new RuntimeException('Preencha os dados de todos os visitantes.');

        $buyerEmail=trim((string)($payload['buyer_email']??''));
        $buyerPhone=trim((string)($payload['buyer_phone']??''));
        if(!filter_var($buyerEmail,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('E-mail inválido.');
        if($buyerPhone==='') throw new RuntimeException('Telefone obrigatório.');

        $pdo=\db(); $saved=[];
        try{
            $pdo->beginTransaction();
            $orderCode=\random_code('PED');
            $s=$pdo->prepare("INSERT INTO orders(order_code,buyer_email,buyer_phone,status,payment_status,subtotal,total,integration_status,created_at,updated_at) VALUES(?,?,?,'pending_payment','pending',?,?,'not_ready',NOW(),NOW())");
            $s->execute([$orderCode,$buyerEmail,$buyerPhone,$subtotal,$subtotal]);
            $orderId=(int)$pdo->lastInsertId();

            foreach($normalized as $productId=>$qty){
                $p=$products[$productId];
                $s=$pdo->prepare("INSERT INTO order_items(order_id,product_id,product_name,unit_price,quantity,ncm,cest,created_at) VALUES(?,?,?,?,?,?,?,NOW())");
                $s->execute([$orderId,$productId,$p['name'],$p['price'],$qty,$p['ncm'],$p['cest']]);
            }

            foreach($visitors as $i=>$v){
                $productId=(int)($v['product_id']??0);
                if(!isset($products[$productId]) || (int)$products[$productId]['requires_visitor']!==1) throw new RuntimeException('Produto de ingresso inválido no visitante.');
                $p=$products[$productId];

                $first=trim((string)($v['first_name']??'')); $last=trim((string)($v['last_name']??''));
                $email=trim((string)($v['email']??$buyerEmail)); $phone=trim((string)($v['phone']??$buyerPhone));
                $entry=trim((string)($v['entry_date']??'')); $docType=strtoupper(trim((string)($v['document_type']??'CPF')));
                $doc=\normalize_document((string)($v['document_number']??'')); $sex=strtolower(trim((string)($v['sex']??'nao_informado')));
                $consent=(string)($v['biometric_consent']??'')==='1';

                if($first===''||$last===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||$phone===''||$doc==='') throw new RuntimeException('Dados incompletos de um visitante.');
                if(!in_array($docType,['CPF','RG','CNH'],true)) throw new RuntimeException('Tipo de documento inválido.');
                if(!in_array($sex,['feminino','masculino','outro','nao_informado'],true)) throw new RuntimeException('Sexo inválido.');
                $entryDate=DateTimeImmutable::createFromFormat('Y-m-d',$entry);
                if(!$entryDate || $entryDate->format('Y-m-d')!==$entry) throw new RuntimeException('Data de entrada inválida.');
                if($entryDate<new DateTimeImmutable('today')) throw new RuntimeException('A data de entrada não pode estar no passado.');
                if(!$consent) throw new RuntimeException('É necessário consentir com o uso da foto para controle de acesso.');

                $photoPath=$this->storePhoto($photos,$i); $saved[]=STORAGE_ROOT.'/private/visitors/'.$photoPath;
                $s=$pdo->prepare("INSERT INTO visitors(order_id,first_name,last_name,email,phone,document_type,document_number,sex,photo_path,biometric_consent_at,created_at) VALUES(?,?,?,?,?,?,?,?,?,NOW(),NOW())");
                $s->execute([$orderId,$first,$last,$email,$phone,$docType,$doc,$sex,$photoPath]);
                $visitorId=(int)$pdo->lastInsertId();

                $days=max(1,(int)$p['duration_days']);
                $validTo=$entryDate->modify('+'.($days-1).' days')->format('Y-m-d');
                $s=$pdo->prepare("INSERT INTO tickets(order_id,product_id,visitor_id,ticket_code,valid_from,valid_to,validation_mode,status,created_at) VALUES(?,?,?,?,?,?,?,'pending',NOW())");
                $s->execute([$orderId,$productId,$visitorId,\random_code('AQV'),$entryDate->format('Y-m-d'),$validTo,$p['validation_mode']]);
            }
            $pdo->commit();
            return ['id'=>$orderId,'order_code'=>$orderCode];
        } catch(Throwable $e){
            if($pdo->inTransaction()) $pdo->rollBack();
            foreach($saved as $file) if(is_file($file)) @unlink($file);
            throw $e;
        }
    }

    private function storePhoto(array $photos,int $index): string {
        if(!isset($photos['tmp_name'][$index]) || !is_uploaded_file($photos['tmp_name'][$index])) throw new RuntimeException('Foto obrigatória para cada visitante.');
        if(($photos['error'][$index]??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new RuntimeException('Falha no envio de uma foto.');
        $max=((int)(\env('MAX_PHOTO_MB','8')??8))*1024*1024;
        $size=(int)($photos['size'][$index]??0);
        if($size<1 || $size>$max) throw new RuntimeException('A foto excede o limite permitido.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($photos['tmp_name'][$index]);
        $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime] ?? null;
        if(!$ext) throw new RuntimeException('Formato de foto inválido. Use JPG, PNG ou WEBP.');
        $name=bin2hex(random_bytes(20)).'.'.$ext; $dest=STORAGE_ROOT.'/private/visitors/'.$name;
        if(!move_uploaded_file($photos['tmp_name'][$index],$dest)) throw new RuntimeException('Não foi possível armazenar a foto.');
        @chmod($dest,0640); return $name;
    }

    public function simulatePayment(int $orderId): void {
        $pdo=\db(); $pdo->beginTransaction();
        $s=$pdo->prepare("SELECT status FROM orders WHERE id=? FOR UPDATE"); $s->execute([$orderId]); $o=$s->fetch();
        if(!$o){$pdo->rollBack();throw new RuntimeException('Pedido não encontrado.');}
        if($o['status']!=='paid'){
            $pdo->prepare("UPDATE orders SET status='paid',payment_status='approved',paid_at=NOW(),integration_status='pending',updated_at=NOW() WHERE id=?")->execute([$orderId]);
            $pdo->prepare("UPDATE tickets SET status='active' WHERE order_id=? AND status='pending'")->execute([$orderId]);
        }
        $pdo->commit();
    }

    public function getOrderByCode(string $code): ?array {
        $s=\db()->prepare("SELECT * FROM orders WHERE order_code=?"); $s->execute([$code]); $o=$s->fetch();
        if(!$o) return null;
        $s=\db()->prepare("SELECT * FROM order_items WHERE order_id=? ORDER BY id"); $s->execute([$o['id']]); $o['items']=$s->fetchAll();
        $s=\db()->prepare("SELECT t.*,v.first_name,v.last_name,v.document_type,v.document_number,p.name product_name FROM tickets t JOIN visitors v ON v.id=t.visitor_id JOIN products p ON p.id=t.product_id WHERE t.order_id=? ORDER BY t.id");
        $s->execute([$o['id']]); $o['tickets']=$s->fetchAll();
        return $o;
    }
}
