<?php
declare(strict_types=1);

require dirname(__DIR__).'/config/bootstrap.php';

use AcquaVale\OrderService;

$service = new OrderService();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_validate();
        $action = $_POST['action'] ?? '';

        if ($action === 'create_order') {
            $created = $service->create($_POST, $_FILES['photos'] ?? []);
            redirect(url('index.php?order='.urlencode($created['order_code'])));
        }

        if ($action === 'simulate_payment') {
            $service->simulatePayment((int)($_POST['order_id'] ?? 0));
            redirect(url('index.php?order='.urlencode((string)($_POST['order_code'] ?? ''))));
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$order = !empty($_GET['order'])
    ? $service->getOrderByCode((string)$_GET['order'])
    : null;

$products = db()->query("SELECT * FROM products WHERE active=1 ORDER BY sort_order,id")->fetchAll();

$productMeta = [];
foreach ($products as $product) {
    $productMeta[(string)$product['id']] = [
        'name' => $product['name'],
        'price' => (float)$product['price'],
        'requires_visitor' => (bool)$product['requires_visitor'],
        'product_type' => $product['product_type'],
        'duration_days' => (int)$product['duration_days'],
    ];
}

$validatedReservation = (!$order && isset($_SESSION['validated_reservation']) && is_array($_SESSION['validated_reservation']))
    ? $_SESSION['validated_reservation']
    : null;

$currentStep = $order
    ? ($order['status'] === 'paid' ? 6 : 5)
    : ($validatedReservation ? 1 : 0);

function renderSteps(int $current, bool $interactive = false): void
{
    $steps = [
        0 => ['Reserva', 'Validação do hóspede'],
        1 => ['Ingresso', 'Escolha e quantidade'],
        2 => ['Pessoas', 'Cadastro completo'],
        3 => ['Regras', 'Aceites'],
        4 => ['Resumo', 'Conferência'],
        5 => ['Pagamento', 'Finalização'],
        6 => ['QR Codes', 'Entrega'],
    ];
    ?>
    <div class="wizard-progress" data-server-current="<?=$current?>">
        <?php foreach ($steps as $number => [$title, $subtitle]):
            $class = $number < $current ? 'is-done' : ($number === $current ? 'is-active' : '');
        ?>
        <?php if ($interactive): ?>
        <button
            class="wizard-progress-item <?=$class?>"
            type="button"
            data-progress-step="<?=$number?>"
            data-flow-step="<?=$number?>"
            aria-label="Ir para etapa <?=$number?>: <?=e($title)?>"
            <?=$number > $current ? 'aria-disabled="true"' : ''?>
        >
            <div class="wizard-number"><?=$number?></div>
            <div class="wizard-progress-copy">
                <strong><?=e($title)?></strong>
                <span><?=e($subtitle)?></span>
            </div>
        </button>
        <?php else: ?>
        <div class="wizard-progress-item <?=$class?>" data-progress-step="<?=$number?>">
            <div class="wizard-number"><?=$number?></div>
            <div class="wizard-progress-copy">
                <strong><?=e($title)?></strong>
                <span><?=e($subtitle)?></span>
            </div>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Ingressos | AcquaVale Park</title>
<meta name="description" content="Compre ingressos e adicionais para o AcquaVale Park.">
<link rel="stylesheet" href="assets/css/app.css">
<link rel="stylesheet" href="assets/css/wizard.css">
</head>
<body>
<header class="site-header">
    <div class="container nav">
        <a class="brand" href="./"><img src="https://www.acquavale.com.br/acquavale-logo.png" alt="AcquaVale"></a>
        <div class="nav-actions">
            <a class="btn btn-outline" href="https://www.acquavale.com.br/">Conheça o parque</a>
            <?php if ($order): ?>
                <a class="btn btn-primary" href="./">Nova compra</a>
            <?php else: ?>
                <button class="btn btn-primary" type="button" id="start-purchase">Comprar ingressos</button>
            <?php endif; ?>
        </div>
    </div>
</header>

<?php if ($order): ?>
<main>
    <section class="purchase-head">
        <div class="container">
            <span class="eyebrow dark">Pedido <?=e($order['order_code'])?></span>
            <h1><?= $currentStep === 5 ? 'Pagamento' : 'QR Codes liberados' ?></h1>
            <p><?= $currentStep === 5
                ? 'Seu pedido já foi criado. Agora falta apenas concluir o pagamento.'
                : 'Pagamento confirmado. Cada pessoa possui um QR Code individual para o acesso.' ?></p>
            <?php renderSteps($currentStep); ?>
        </div>
    </section>

    <?php if ($currentStep === 5): ?>
    <section class="wizard-stage is-active">
        <div class="container">
            <div class="stage-heading">
                <span class="stage-kicker">Passo 5</span>
                <h2>Pagamento</h2>
                <p>O gateway real será conectado depois. Nesta versão, a aprovação é simulada para testar todo o fluxo.</p>
            </div>

            <div class="payment-layout">
                <div class="card panel">
                    <h3 class="panel-title">Resumo do pedido</h3>
                    <?php foreach ($order['items'] as $item): ?>
                        <div class="summary-row">
                            <span><?=(int)$item['quantity']?>× <?=e($item['product_name'])?></span>
                            <strong><?=money((float)$item['unit_price'] * (int)$item['quantity'])?></strong>
                        </div>
                    <?php endforeach; ?>
                    <div class="summary-row total">
                        <span>Total</span>
                        <strong><?=money($order['total'])?></strong>
                    </div>

                    <div class="person-summary-list">
                        <?php foreach ($order['tickets'] as $index => $ticket): ?>
                            <div class="person-summary">
                                <div class="person-summary-number"><?=($index + 1)?></div>
                                <div>
                                    <strong><?=e($ticket['first_name'].' '.$ticket['last_name'])?></strong>
                                    <span><?=e($ticket['product_name'])?> · entrada <?=e(date('d/m/Y', strtotime($ticket['valid_from'])))?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <aside class="card panel payment-card">
                    <span class="pill">Simulação</span>
                    <h3>Escolha do pagamento</h3>
                    <div class="payment-methods">
                        <button type="button" class="payment-method is-selected">PIX</button>
                        <button type="button" class="payment-method">Cartão</button>
                    </div>
                    <div class="notice">Nenhuma cobrança real será realizada nesta versão.</div>

                    <form method="post">
                        <input type="hidden" name="_csrf" value="<?=e(csrf_token())?>">
                        <input type="hidden" name="action" value="simulate_payment">
                        <input type="hidden" name="order_id" value="<?=(int)$order['id']?>">
                        <input type="hidden" name="order_code" value="<?=e($order['order_code'])?>">
                        <button class="btn btn-lime btn-wide" type="submit">Simular pagamento aprovado</button>
                    </form>
                </aside>
            </div>
        </div>
    </section>
    <?php else: ?>
    <section class="wizard-stage is-active">
        <div class="container">
            <div class="stage-heading">
                <span class="stage-kicker">Passo 6</span>
                <h2>QR Codes e envio</h2>
                <p>Os QR Codes foram gerados individualmente. A integração de envio automático será ligada ao serviço de e-mail/WhatsApp na próxima etapa do projeto.</p>
            </div>

            <div class="notice success final-success">
                <strong>Compra concluída.</strong>
                Os ingressos estão ativos e preparados para validação nas catracas.
            </div>

            <div class="qr-grid">
                <?php foreach ($order['tickets'] as $index => $ticket): ?>
                <article class="card qr-ticket">
                    <div class="qr-ticket-top">
                        <span class="pill">Pessoa <?=($index + 1)?></span>
                        <span class="status active">Ativo</span>
                    </div>
                    <h3><?=e($ticket['first_name'].' '.$ticket['last_name'])?></h3>
                    <p><?=e($ticket['product_name'])?></p>
                    <div class="qr-box" data-qr="<?=e($ticket['ticket_code'])?>"></div>
                    <div class="ticket-code"><?=e($ticket['ticket_code'])?></div>
                    <div class="qr-validity">
                        <span>Entrada</span>
                        <strong><?=e(date('d/m/Y', strtotime($ticket['valid_from'])))?></strong>
                    </div>
                    <div class="qr-validity">
                        <span>Válido até</span>
                        <strong><?=e(date('d/m/Y', strtotime($ticket['valid_to'])))?></strong>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>

            <div class="delivery-panel card panel">
                <div>
                    <span class="pill">Envio</span>
                    <h3>Entrega dos ingressos</h3>
                    <p>Contato principal: <strong><?=e($order['buyer_email'])?></strong> · <?=e($order['buyer_phone'])?></p>
                    <p class="muted">O ponto de integração para disparo por e-mail/WhatsApp ficará conectado aqui, sem alterar a geração dos ingressos.</p>
                </div>
                <button class="btn btn-outline" type="button" onclick="window.print()">Imprimir QR Codes</button>
            </div>
        </div>
    </section>

    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <script>
    document.querySelectorAll('.qr-box').forEach(function(el){
        new QRCode(el,{
            text:el.dataset.qr,
            width:190,
            height:190,
            correctLevel:QRCode.CorrectLevel.M
        });
    });
    </script>
    <?php endif; ?>
</main>
<?php else: ?>
<main>
    <section class="hero compact-hero">
        <div class="container hero-grid">
            <div>
                <span class="eyebrow">Ingressos oficiais AcquaVale</span>
                <h1>Primeiro, <span>localize sua reserva.</span></h1>
                <p>A compra é exclusiva para hóspedes com reserva no hotel. Validamos sua reserva no Expresso antes de liberar ingressos, cadastros e pagamento.</p>
                <button class="btn btn-lime" type="button" onclick="document.getElementById('compra').scrollIntoView({behavior:'smooth'})">Começar compra</button>
            </div>
            <div class="hero-card">
                <strong>Reserva válida primeiro, compra depois</strong>
                <ul>
                    <li>Informe o número da sua reserva para consultar o Expresso</li>
                    <li>Somente reservas encontradas liberam a compra</li>
                    <li>3 ingressos de 2 dias = 3 pessoas cadastradas</li>
                    <li>Cada pessoa terá sua própria foto e documento</li>
                    <li>Cada ingresso terá seu próprio QR Code</li>
                </ul>
            </div>
        </div>
    </section>
    <div class="wave"></div>

    <section class="wizard-wrap" id="compra">
        <div class="container">
            <?php renderSteps($currentStep, true); ?>

            <?php if ($error): ?>
                <div class="notice error wizard-error"><?=e($error)?></div>
            <?php endif; ?>

            <section class="wizard-stage <?=$validatedReservation ? '' : 'is-active'?>" data-step="0">
                <div class="stage-heading">
                    <span class="stage-kicker">Passo 0</span>
                    <h2>Procure sua reserva</h2>
                    <p>Informe o número da reserva do hotel. A consulta é feita diretamente na API Expresso, usando a mesma integração já utilizada pelo iPlate.</p>
                </div>

                <div class="reservation-gate card panel">
                    <div class="reservation-gate-search">
                        <div class="form-group">
                            <label for="reservation-code">Número da reserva</label>
                            <input
                                id="reservation-code"
                                type="text"
                                inputmode="numeric"
                                autocomplete="off"
                                placeholder="Ex.: 123456"
                                value="<?=e((string)($validatedReservation['reservation_code']??''))?>"
                            >
                            <small>Use o mesmo número informado na confirmação da hospedagem.</small>
                        </div>
                        <button class="btn btn-primary" type="button" id="reservation-search-button">
                            Procurar reserva
                        </button>
                    </div>

                    <div id="reservation-loading" class="reservation-loading" hidden>
                        <span class="reservation-spinner" aria-hidden="true"></span>
                        <div><strong>Consultando o Expresso...</strong><small>Aguarde alguns segundos.</small></div>
                    </div>

                    <div
                        id="reservation-result"
                        class="reservation-result <?=$validatedReservation ? 'is-visible' : ''?>"
                        <?=$validatedReservation ? '' : 'hidden'?>
                    >
                        <div class="reservation-result-head">
                            <div>
                                <span class="pill">Reserva validada</span>
                                <h3 id="reservation-result-name"><?=e((string)($validatedReservation['guest_name']??''))?></h3>
                            </div>
                            <span class="status active">Encontrada</span>
                        </div>
                        <div class="reservation-result-grid">
                            <div><span>Reserva</span><strong id="reservation-result-code"><?=e((string)($validatedReservation['reservation_code']??''))?></strong></div>
                            <div><span>Check-in</span><strong id="reservation-result-checkin"><?=e((string)($validatedReservation['checkin_date']??'—'))?></strong></div>
                            <div><span>UH</span><strong id="reservation-result-uh"><?=e((string)($validatedReservation['uh']??'—'))?></strong></div>
                            <div><span>Hóspedes</span><strong id="reservation-result-guests"><?php
                                $a=(string)($validatedReservation['adults']??'');
                                $ch=(string)($validatedReservation['children']??'');
                                echo e(trim(($a!=='' ? $a.' adulto(s)' : '').($ch!=='' ? ' · '.$ch.' criança(s)' : '')) ?: '—');
                            ?></strong></div>
                        </div>
                        <div class="reservation-gate-actions">
                            <button class="btn btn-outline" type="button" id="reservation-change-button">Trocar reserva</button>
                            <button class="btn btn-lime" type="button" id="continue-step-0">Continuar para ingressos</button>
                        </div>
                    </div>
                </div>
            </section>

            <section class="wizard-stage <?=$validatedReservation ? 'is-active' : ''?>" data-step="1">
                <div class="stage-heading">
                    <span class="stage-kicker">Passo 1</span>
                    <h2>Escolha os ingressos e a quantidade</h2>
                    <p>Selecione quantos ingressos serão usados. Cada unidade de ingresso representa uma pessoa diferente e exigirá um cadastro completo na próxima etapa.</p>
                </div>

                <div class="notice step-explanation">
                    <strong>Exemplo:</strong> 3 ingressos de 2 dias significam 3 pessoas diferentes visitando o parque por 2 dias. Na etapa seguinte aparecerão Pessoa 1, Pessoa 2 e Pessoa 3.
                </div>

                <div class="products wizard-products">
                    <?php foreach ($products as $product): ?>
                    <article class="card product-card">
                        <span class="pill">
                            <?=e($product['product_type'] === 'ticket'
                                ? $product['duration_days'].' dia(s)'
                                : 'Adicional')?>
                        </span>
                        <h3><?=e($product['name'])?></h3>
                        <p><?=e($product['description'])?></p>
                        <div class="price"><?=money($product['price'])?></div>
                        <div class="qty">
                            <button type="button" data-qty-action="minus" data-product="<?=(int)$product['id']?>" aria-label="Diminuir">−</button>
                            <input type="number" min="0" max="20" value="0" data-product-id="<?=(int)$product['id']?>" aria-label="Quantidade">
                            <button type="button" data-qty-action="plus" data-product="<?=(int)$product['id']?>" aria-label="Aumentar">+</button>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>

                <div class="step-footer">
                    <div class="step-mini-summary">
                        <span id="step1-count">Nenhum ingresso selecionado</span>
                        <strong id="step1-total">R$ 0,00</strong>
                    </div>
                    <button class="btn btn-primary" id="continue-step-1" type="button">Continuar para cadastros</button>
                </div>
            </section>

            <form method="post" enctype="multipart/form-data" id="checkout-form" novalidate>
                <input type="hidden" name="_csrf" value="<?=e(csrf_token())?>">
                <input type="hidden" name="action" value="create_order">
                <input type="hidden" name="cart" id="cart-json" value="{}">
                <input type="hidden" name="buyer_email" id="buyer-email">
                <input type="hidden" name="buyer_phone" id="buyer-phone">

                <section class="wizard-stage" data-step="2">
                    <div class="stage-heading">
                        <span class="stage-kicker">Passo 2</span>
                        <h2>Cadastro das pessoas</h2>
                        <p>Preencha um cadastro completo para cada ingresso. Os formulários aparecem um abaixo do outro para evitar confusão.</p>
                    </div>

                    <div class="notice">
                        A foto e o documento pertencem à pessoa que utilizará aquele ingresso. O e-mail e telefone da Pessoa 1 serão usados também como contato principal do pedido.
                    </div>

                    <div id="visitors" class="people-stack"></div>

                    <div class="step-footer split">
                        <button class="btn btn-outline" type="button" data-back="1">Voltar</button>
                        <button class="btn btn-primary" id="continue-step-2" type="button">Continuar para regras</button>
                    </div>
                </section>

                <section class="wizard-stage" data-step="3">
                    <div class="stage-heading">
                        <span class="stage-kicker">Passo 3</span>
                        <h2>Regras de utilização</h2>
                        <p>Antes de fechar o pedido, confirme as condições de uso dos ingressos e da identificação facial.</p>
                    </div>

                    <div class="rules-card card panel">
                        <div class="rule-item">
                            <div class="rule-icon">1</div>
                            <div><strong>Ingresso individual</strong><p>Cada ingresso fica associado à pessoa cadastrada e não deve ser transferido depois da vinculação.</p></div>
                        </div>
                        <div class="rule-item">
                            <div class="rule-icon">2</div>
                            <div><strong>Validade conforme o produto</strong><p>Ingressos de múltiplos dias seguem o período calculado a partir da data de entrada informada.</p></div>
                        </div>
                        <div class="rule-item">
                            <div class="rule-icon">3</div>
                            <div><strong>Identificação na entrada</strong><p>A foto cadastrada será usada na futura integração com reconhecimento facial das catracas. O documento poderá ser solicitado para conferência.</p></div>
                        </div>
                        <div class="rule-item">
                            <div class="rule-icon">4</div>
                            <div><strong>QR Code individual</strong><p>Após a confirmação do pagamento, cada pessoa recebe um QR Code próprio, vinculado ao respectivo ingresso.</p></div>
                        </div>
                    </div>

                    <div class="acceptance-card card panel">
                        <label class="accept-line">
                            <input type="checkbox" name="terms_accepted" value="1" required>
                            <span>Li e aceito as regras de compra e utilização dos ingressos.</span>
                        </label>
                        <label class="accept-line">
                            <input type="checkbox" name="biometric_consent" value="1" required>
                            <span>Autorizo o tratamento das fotos cadastradas para identificação e controle de acesso ao AcquaVale, conforme a política de privacidade aplicável.</span>
                        </label>
                    </div>

                    <div class="step-footer split">
                        <button class="btn btn-outline" type="button" data-back="2">Voltar</button>
                        <button class="btn btn-primary" id="continue-step-3" type="button">Continuar para resumo</button>
                    </div>
                </section>

                <section class="wizard-stage" data-step="4">
                    <div class="stage-heading">
                        <span class="stage-kicker">Passo 4</span>
                        <h2>Resumo da compra</h2>
                        <p>Confira produtos, pessoas, datas e total antes de criar o pedido.</p>
                    </div>

                    <div class="review-layout">
                        <div>
                            <div class="card panel review-card">
                                <h3 class="panel-title">Ingressos e adicionais</h3>
                                <div id="review-products"></div>
                            </div>

                            <div class="card panel review-card">
                                <h3 class="panel-title">Pessoas cadastradas</h3>
                                <div id="review-people"></div>
                            </div>
                        </div>

                        <aside class="card panel review-total-card">
                            <span class="pill">Total da compra</span>
                            <strong id="review-total">R$ 0,00</strong>
                            <p>Nenhum pagamento será realizado antes da próxima etapa.</p>
                            <button class="btn btn-lime btn-wide" type="submit">Confirmar pedido e ir para pagamento</button>
                        </aside>
                    </div>

                    <div class="step-footer">
                        <button class="btn btn-outline" type="button" data-back="3">Voltar para regras</button>
                    </div>
                </section>
            </form>
        </div>
    </section>

    <script>
        window.AQV_PRODUCTS = <?=json_encode($productMeta, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
        window.AQV_RESERVATION = <?=json_encode($validatedReservation, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
        window.AQV_INITIAL_STEP = <?=json_encode($currentStep)?>;
        window.AQV_CSRF = <?=json_encode(csrf_token())?>;
        window.AQV_RESERVATION_ENDPOINT = <?=json_encode(url('reservation.php'))?>;
    </script>
    <script src="assets/js/app.js"></script>
</main>
<?php endif; ?>

<footer class="footer">
    <div class="container footer-grid">
        <img src="https://www.acquavale.com.br/acquavale-logo.png" alt="AcquaVale">
        <div>AcquaVale Park · Serra da Mantiqueira<br><small>Venda online preparada para pagamento, QR Code e integração com catracas.</small></div>
    </div>
</footer>
</body>
</html>
