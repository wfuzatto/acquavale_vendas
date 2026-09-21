<?php
declare(strict_types=1);

namespace AcquaVale;

use DateTimeImmutable;
use RuntimeException;
use Throwable;

final class OrderService
{
    public function create(array $payload, array $photos): array
    {
        $sessionReservation=$_SESSION['validated_reservation']??null;
        if (!is_array($sessionReservation) || trim((string)($sessionReservation['reservation_code']??''))==='') {
            throw new RuntimeException('Localize e valide sua reserva antes de iniciar a compra.');
        }

        // Double check server-side: nunca confia apenas no passo visual do navegador.
        $reservation=(new ExpressoReservationService())->lookup((string)$sessionReservation['reservation_code']);
        $_SESSION['validated_reservation']=$reservation;

        $cart = $payload['cart'] ?? [];
        if (is_string($cart)) {
            $cart = json_decode($cart, true) ?: [];
        }
        if (!is_array($cart) || !$cart) {
            throw new RuntimeException('Carrinho vazio.');
        }

        $normalized = [];
        foreach ($cart as $productId => $qty) {
            $productId = (int)$productId;
            $qty = max(0, min(20, (int)$qty));
            if ($productId > 0 && $qty > 0) {
                $normalized[$productId] = $qty;
            }
        }
        if (!$normalized) {
            throw new RuntimeException('Carrinho vazio.');
        }

        $placeholders = implode(',', array_fill(0, count($normalized), '?'));
        $stmt = \db()->prepare("SELECT * FROM acquavale_vendas_products WHERE id IN ($placeholders) AND active=1");
        $stmt->execute(array_keys($normalized));

        $products = [];
        foreach ($stmt->fetchAll() as $product) {
            $products[(int)$product['id']] = $product;
        }
        if (count($products) !== count($normalized)) {
            throw new RuntimeException('Um dos produtos selecionados não está disponível.');
        }

        $ticketUnits = 0;
        $subtotal = 0.0;
        foreach ($normalized as $id => $qty) {
            $subtotal += (float)$products[$id]['price'] * $qty;
            if ((int)$products[$id]['requires_visitor'] === 1) {
                $ticketUnits += $qty;
            }
        }

        if ($ticketUnits < 1) {
            throw new RuntimeException('Adicione ao menos um ingresso para continuar.');
        }

        $visitors = $payload['visitors'] ?? [];
        if (!is_array($visitors) || count($visitors) !== $ticketUnits) {
            throw new RuntimeException('Preencha o cadastro completo de todas as pessoas.');
        }

        $termsAccepted = (string)($payload['terms_accepted'] ?? '') === '1';
        $biometricConsent = (string)($payload['biometric_consent'] ?? '') === '1';
        if (!$termsAccepted) {
            throw new RuntimeException('É necessário aceitar as regras de compra e utilização.');
        }
        if (!$biometricConsent) {
            throw new RuntimeException('É necessário autorizar o uso das fotos para identificação e controle de acesso.');
        }

        $buyerEmail = trim((string)($payload['buyer_email'] ?? ''));
        $buyerPhone = trim((string)($payload['buyer_phone'] ?? ''));
        if (!filter_var($buyerEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('E-mail principal inválido.');
        }
        if ($buyerPhone === '') {
            throw new RuntimeException('Telefone principal obrigatório.');
        }

        $pdo = \db();
        $savedFiles = [];

        try {
            $pdo->beginTransaction();

            $orderCode = \random_code('PED');
            $stmt = $pdo->prepare(
                "INSERT INTO acquavale_vendas_orders
                (order_code,buyer_email,buyer_phone,
                 expresso_reservation_id,expresso_reservation_code,expresso_guest_name,expresso_guest_cpf,
                 expresso_checkin_date,expresso_checkout_date,expresso_adults,expresso_children,expresso_uh,
                 expresso_reservation_snapshot,reservation_verified_at,
                 status,payment_status,subtotal,total,integration_status,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),'pending_payment','pending',?,?,'not_ready',NOW(),NOW())"
            );
            $stmt->execute([
                $orderCode,
                $buyerEmail,
                $buyerPhone,
                $reservation['reservation_id']??null,
                $reservation['reservation_code']??null,
                $reservation['guest_name']??null,
                $reservation['guest_cpf']??null,
                $reservation['checkin_date']??null,
                $reservation['checkout_date']??null,
                $reservation['adults']??null,
                $reservation['children']??null,
                $reservation['uh']??null,
                json_encode($reservation,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                $subtotal,
                $subtotal,
            ]);
            $orderId = (int)$pdo->lastInsertId();

            foreach ($normalized as $productId => $qty) {
                $product = $products[$productId];
                $stmt = $pdo->prepare(
                    "INSERT INTO acquavale_vendas_order_items
                    (order_id,product_id,product_name,unit_price,quantity,ncm,cest,created_at)
                    VALUES (?,?,?,?,?,?,?,NOW())"
                );
                $stmt->execute([
                    $orderId,
                    $productId,
                    $product['name'],
                    $product['price'],
                    $qty,
                    $product['ncm'],
                    $product['cest'],
                ]);
            }

            foreach ($visitors as $index => $visitor) {
                $productId = (int)($visitor['product_id'] ?? 0);
                if (!isset($products[$productId]) || (int)$products[$productId]['requires_visitor'] !== 1) {
                    throw new RuntimeException('Ingresso inválido no cadastro de uma das pessoas.');
                }

                $product = $products[$productId];
                $firstName = trim((string)($visitor['first_name'] ?? ''));
                $lastName = trim((string)($visitor['last_name'] ?? ''));
                $email = trim((string)($visitor['email'] ?? ''));
                $phone = trim((string)($visitor['phone'] ?? ''));
                $entryDateRaw = trim((string)($visitor['entry_date'] ?? ''));
                $documentType = strtoupper(trim((string)($visitor['document_type'] ?? 'CPF')));
                $documentNumber = \normalize_document((string)($visitor['document_number'] ?? ''));
                $sex = strtolower(trim((string)($visitor['sex'] ?? 'nao_informado')));

                if (
                    $firstName === '' ||
                    $lastName === '' ||
                    !filter_var($email, FILTER_VALIDATE_EMAIL) ||
                    $phone === '' ||
                    $documentNumber === ''
                ) {
                    throw new RuntimeException('Há um cadastro de pessoa incompleto.');
                }

                if (!in_array($documentType, ['CPF', 'RG', 'CNH'], true)) {
                    throw new RuntimeException('Tipo de documento inválido.');
                }

                if (!in_array($sex, ['feminino', 'masculino', 'outro', 'nao_informado'], true)) {
                    throw new RuntimeException('Sexo inválido.');
                }

                $entryDate = DateTimeImmutable::createFromFormat('Y-m-d', $entryDateRaw);
                if (!$entryDate || $entryDate->format('Y-m-d') !== $entryDateRaw) {
                    throw new RuntimeException('Data de entrada inválida.');
                }
                if ($entryDate < new DateTimeImmutable('today')) {
                    throw new RuntimeException('A data de entrada não pode estar no passado.');
                }

                $photoPath = $this->storePhoto($photos, (int)$index);
                $savedFiles[] = STORAGE_ROOT . '/private/visitors/' . $photoPath;

                $stmt = $pdo->prepare(
                    "INSERT INTO acquavale_vendas_visitors
                    (order_id,first_name,last_name,email,phone,document_type,document_number,sex,photo_path,biometric_consent_at,created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())"
                );
                $stmt->execute([
                    $orderId,
                    $firstName,
                    $lastName,
                    $email,
                    $phone,
                    $documentType,
                    $documentNumber,
                    $sex,
                    $photoPath,
                ]);
                $visitorId = (int)$pdo->lastInsertId();

                $days = max(1, (int)$product['duration_days']);
                $validTo = $entryDate->modify('+' . ($days - 1) . ' days')->format('Y-m-d');

                $stmt = $pdo->prepare(
                    "INSERT INTO acquavale_vendas_tickets
                    (order_id,product_id,visitor_id,ticket_code,valid_from,valid_to,validation_mode,status,created_at)
                    VALUES (?,?,?,?,?,?,?,'pending',NOW())"
                );
                $stmt->execute([
                    $orderId,
                    $productId,
                    $visitorId,
                    \random_code('AQV'),
                    $entryDate->format('Y-m-d'),
                    $validTo,
                    $product['validation_mode'],
                ]);
            }

            $metadata = json_encode([
                'terms_version' => '2026-09-21',
                'terms_accepted' => true,
                'biometric_consent' => true,
                'visitor_count' => $ticketUnits,
                'expresso_reservation_code' => $reservation['reservation_code']??null,
                'expresso_reservation_id' => $reservation['reservation_id']??null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $stmt = $pdo->prepare(
                "INSERT INTO acquavale_vendas_audit_log(actor,action,entity_type,entity_id,metadata,ip)
                 VALUES (?,?,?,?,?,?)"
            );
            $stmt->execute([
                'checkout:' . $buyerEmail,
                'checkout.rules.accepted',
                'order',
                (string)$orderId,
                $metadata,
                \client_ip(),
            ]);

            $pdo->commit();
            unset($_SESSION['validated_reservation']);

            return [
                'id' => $orderId,
                'order_code' => $orderCode,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            foreach ($savedFiles as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }

            throw $e;
        }
    }

    private function storePhoto(array $photos, int $index): string
    {
        if (!isset($photos['tmp_name'][$index]) || !is_uploaded_file($photos['tmp_name'][$index])) {
            throw new RuntimeException('A foto é obrigatória para cada pessoa.');
        }

        if (($photos['error'][$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Falha no envio de uma das fotos.');
        }

        $maxBytes = ((int)\cfg('uploads.max_photo_mb', 8)) * 1024 * 1024;
        $size = (int)($photos['size'][$index] ?? 0);
        if ($size < 1 || $size > $maxBytes) {
            throw new RuntimeException('Uma das fotos excede o limite permitido.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($photos['tmp_name'][$index]);
        $extension = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ][$mime] ?? null;

        if (!$extension) {
            throw new RuntimeException('Formato de foto inválido. Use JPG, PNG ou WEBP.');
        }

        $name = bin2hex(random_bytes(20)) . '.' . $extension;
        $destination = STORAGE_ROOT . '/private/visitors/' . $name;

        if (!move_uploaded_file($photos['tmp_name'][$index], $destination)) {
            throw new RuntimeException('Não foi possível armazenar uma das fotos.');
        }

        @chmod($destination, 0640);
        return $name;
    }

    public function simulatePayment(int $orderId): void
    {
        $pdo = \db();
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT status FROM acquavale_vendas_orders WHERE id=? FOR UPDATE");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if (!$order) {
            $pdo->rollBack();
            throw new RuntimeException('Pedido não encontrado.');
        }

        if ($order['status'] !== 'paid') {
            $pdo->prepare(
                "UPDATE acquavale_vendas_orders
                 SET status='paid',payment_status='approved',paid_at=NOW(),
                     integration_status='pending',updated_at=NOW()
                 WHERE id=?"
            )->execute([$orderId]);

            $pdo->prepare(
                "UPDATE acquavale_vendas_tickets SET status='active'
                 WHERE order_id=? AND status='pending'"
            )->execute([$orderId]);
        }

        $pdo->commit();
    }

    public function getOrderByCode(string $code): ?array
    {
        $stmt = \db()->prepare("SELECT * FROM acquavale_vendas_orders WHERE order_code=?");
        $stmt->execute([$code]);
        $order = $stmt->fetch();

        if (!$order) {
            return null;
        }

        $stmt = \db()->prepare("SELECT * FROM acquavale_vendas_order_items WHERE order_id=? ORDER BY id");
        $stmt->execute([$order['id']]);
        $order['items'] = $stmt->fetchAll();

        $stmt = \db()->prepare(
            "SELECT
                t.*,
                v.first_name,
                v.last_name,
                v.email,
                v.phone,
                v.document_type,
                v.document_number,
                p.name AS product_name
             FROM acquavale_vendas_tickets t
             JOIN acquavale_vendas_visitors v ON v.id=t.visitor_id
             JOIN acquavale_vendas_products p ON p.id=t.product_id
             WHERE t.order_id=?
             ORDER BY t.id"
        );
        $stmt->execute([$order['id']]);
        $order['tickets'] = $stmt->fetchAll();

        return $order;
    }
}
