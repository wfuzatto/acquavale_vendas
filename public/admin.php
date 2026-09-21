<?php
declare(strict_types=1);
require dirname(__DIR__).'/config/bootstrap.php';

use AcquaVale\Auth;

$action=$_GET['action']??'dashboard';
$error=null;

if($action==='logout'){Auth::logout();redirect('/admin.php?action=login');}

if($action==='login'){
    if(Auth::check())redirect('/admin.php');
    if($_SERVER['REQUEST_METHOD']==='POST'){
        csrf_validate();
        if(Auth::login((string)($_POST['email']??''),(string)($_POST['password']??'')))redirect('/admin.php');
        $error='Credenciais inválidas.';
    }
    ?>
    <!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin | AcquaVale</title><link rel="stylesheet" href="/assets/css/app.css"></head>
    <body><div class="login-wrap"><form class="card login-card" method="post"><img src="https://www.acquavale.com.br/acquavale-logo.png" alt="AcquaVale"><h1 style="text-align:center;color:var(--navy)">Gestão de vendas</h1>
    <?php if($error): ?><div class="notice error"><?=e($error)?></div><?php endif; ?>
    <input type="hidden" name="_csrf" value="<?=e(csrf_token())?>">
    <div class="form-group" style="margin-top:16px"><label>E-mail</label><input type="email" name="email" required></div>
    <div class="form-group" style="margin-top:12px"><label>Senha</label><input type="password" name="password" required></div>
    <button class="btn btn-primary" style="width:100%;margin-top:18px">Entrar</button></form></div></body></html>
    <?php exit;
}

Auth::require();

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        csrf_validate();
        if(($_POST['action']??'')==='save_product'){
            $id=(int)($_POST['id']??0);
            $data=[
                trim((string)($_POST['sku']??'')),
                trim((string)($_POST['name']??'')),
                trim((string)($_POST['description']??'')),
                (string)($_POST['product_type']??'ticket'),
                (float)str_replace(',','.',(string)($_POST['price']??'0')),
                trim((string)($_POST['ncm']??''))?:null,
                trim((string)($_POST['cest']??''))?:null,
                max(1,(int)($_POST['duration_days']??1)),
                (string)($_POST['validation_mode']??'once_total'),
                isset($_POST['requires_visitor'])?1:0,
                isset($_POST['active'])?1:0,
                (int)($_POST['sort_order']??0)
            ];
            if($id){
                $s=db()->prepare("UPDATE products SET sku=?,name=?,description=?,product_type=?,price=?,ncm=?,cest=?,duration_days=?,validation_mode=?,requires_visitor=?,active=?,sort_order=?,updated_at=NOW() WHERE id=?");
                $s->execute([...$data,$id]);
            }else{
                $s=db()->prepare("INSERT INTO products(sku,name,description,product_type,price,ncm,cest,duration_days,validation_mode,requires_visitor,active,sort_order,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())");
                $s->execute($data);
                $id=(int)db()->lastInsertId();
            }
            db()->prepare("INSERT INTO audit_log(actor,action,entity_type,entity_id,metadata,ip) VALUES(?,?,?,?,?,?)")
                ->execute([$_SESSION['admin']['email'],'product.save','product',(string)$id,json_encode(['sku'=>$data[0]]),client_ip()]);
            redirect('/admin.php?action=products');
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}

if($action==='photo'){
    $id=(int)($_GET['id']??0);
    $s=db()->prepare("SELECT photo_path FROM visitors WHERE id=?");$s->execute([$id]);$r=$s->fetch();
    if(!$r){http_response_code(404);exit;}
    $file=STORAGE_ROOT.'/private/visitors/'.basename($r['photo_path']);
    if(!is_file($file)){http_response_code(404);exit;}
    header('Content-Type: '.((new finfo(FILEINFO_MIME_TYPE))->file($file)?:'application/octet-stream'));
    header('Cache-Control: private, no-store');
    readfile($file);exit;
}

$metrics=[
    'today'=>(int)db()->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at)=CURDATE()")->fetchColumn(),
    'paid'=>(int)db()->query("SELECT COUNT(*) FROM orders WHERE status='paid'")->fetchColumn(),
    'revenue'=>(float)db()->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status='paid'")->fetchColumn(),
    'pending'=>(int)db()->query("SELECT COUNT(*) FROM orders WHERE integration_status IN('pending','claimed')")->fetchColumn()
];

function adminHead(string $title):void{ ?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($title)?> | AcquaVale</title><link rel="stylesheet" href="/assets/css/app.css"></head>
<body class="admin-body"><div class="admin-top"><div class="container"><strong>AcquaVale · Gestão de vendas</strong><a class="btn btn-outline" href="/admin.php?action=logout">Sair</a></div></div>
<div class="container admin-grid"><aside class="card sidebar"><a href="/admin.php">Dashboard</a><a href="/admin.php?action=products">Produtos</a><a href="/admin.php?action=orders">Pedidos</a><a href="/validator.php" target="_blank">Validador</a><a href="/">Loja</a></aside><main>
<?php }
function adminFoot():void{ ?></main></div></body></html><?php }

adminHead(ucfirst($action));
if($error)echo '<div class="notice error">'.e($error).'</div>';

if($action==='products'){
    $edit=null;
    if(isset($_GET['edit'])){$s=db()->prepare("SELECT * FROM products WHERE id=?");$s->execute([(int)$_GET['edit']]);$edit=$s->fetch();}
    $rows=db()->query("SELECT * FROM products ORDER BY sort_order,id")->fetchAll();
    ?>
    <div class="section-title"><div><span class="pill">Catálogo</span><h2>Produtos</h2></div><a class="btn btn-primary" href="/admin.php?action=products&new=1">Novo produto</a></div>
    <?php if($edit||isset($_GET['new'])):
        $p=$edit?:['id'=>0,'sku'=>'','name'=>'','description'=>'','product_type'=>'ticket','price'=>'0.00','ncm'=>'','cest'=>'','duration_days'=>1,'validation_mode'=>'once_total','requires_visitor'=>1,'active'=>1,'sort_order'=>0];
    ?>
    <form class="card panel" method="post" style="margin-bottom:24px">
      <input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_product"><input type="hidden" name="id" value="<?=(int)$p['id']?>">
      <div class="form-grid">
        <div class="form-group"><label>SKU</label><input name="sku" value="<?=e($p['sku'])?>" required></div>
        <div class="form-group"><label>Nome</label><input name="name" value="<?=e($p['name'])?>" required></div>
        <div class="form-group full"><label>Descrição</label><textarea name="description"><?=e($p['description'])?></textarea></div>
        <div class="form-group"><label>Tipo</label><select name="product_type"><?php foreach(['ticket'=>'Ingresso','locker'=>'Locker','extra'=>'Extra'] as $v=>$label): ?><option value="<?=$v?>" <?=$p['product_type']===$v?'selected':''?>><?=$label?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Preço</label><input name="price" value="<?=e((string)$p['price'])?>" required></div>
        <div class="form-group"><label>NCM</label><input name="ncm" value="<?=e($p['ncm'])?>" maxlength="10"></div>
        <div class="form-group"><label>CEST</label><input name="cest" value="<?=e($p['cest'])?>" maxlength="10"></div>
        <div class="form-group"><label>Duração (dias)</label><input type="number" min="1" max="365" name="duration_days" value="<?=(int)$p['duration_days']?>"></div>
        <div class="form-group"><label>Regra de validação</label><select name="validation_mode"><?php foreach(['once_total'=>'Uma vez no total','once_per_day'=>'Uma vez por dia','unlimited_validity'=>'Ilimitado na validade'] as $v=>$label): ?><option value="<?=$v?>" <?=$p['validation_mode']===$v?'selected':''?>><?=$label?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Ordem</label><input type="number" name="sort_order" value="<?=(int)$p['sort_order']?>"></div>
        <div class="form-group" style="justify-content:end"><label><input style="width:auto" type="checkbox" name="requires_visitor" <?=$p['requires_visitor']?'checked':''?>> Exige visitante/foto</label><label><input style="width:auto" type="checkbox" name="active" <?=$p['active']?'checked':''?>> Ativo</label></div>
      </div>
      <button class="btn btn-primary" style="margin-top:18px">Salvar produto</button>
    </form>
    <?php endif; ?>
    <div class="card panel table-wrap"><table><thead><tr><th>SKU</th><th>Produto</th><th>Tipo</th><th>Preço</th><th>NCM</th><th>CEST</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach($rows as $p): ?><tr><td><?=e($p['sku'])?></td><td><strong><?=e($p['name'])?></strong><br><small><?=e($p['description'])?></small></td><td><?=e($p['product_type'])?></td><td><?=money($p['price'])?></td><td><?=e($p['ncm']?:'—')?></td><td><?=e($p['cest']?:'—')?></td><td><span class="status <?=$p['active']?'active':'blocked'?>"><?=$p['active']?'Ativo':'Inativo'?></span></td><td><a href="/admin.php?action=products&edit=<?=(int)$p['id']?>">Editar</a></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php adminFoot();exit;
}

if($action==='orders'){
    if(isset($_GET['id'])){
        $id=(int)$_GET['id'];
        $s=db()->prepare("SELECT * FROM orders WHERE id=?");$s->execute([$id]);$o=$s->fetch();
        if(!$o){echo '<div class="notice error">Pedido não encontrado.</div>';adminFoot();exit;}
        $s=db()->prepare("SELECT t.*,v.first_name,v.last_name,v.email,v.phone,v.document_type,v.document_number,v.sex,v.id visitor_id,p.name product_name FROM tickets t JOIN visitors v ON v.id=t.visitor_id JOIN products p ON p.id=t.product_id WHERE t.order_id=?");
        $s->execute([$id]);$tickets=$s->fetchAll();
        ?>
        <div class="section-title"><div><span class="pill">Pedido</span><h2><?=e($o['order_code'])?></h2></div><a href="/admin.php?action=orders">Voltar</a></div>
        <div class="card panel"><p><strong>Status:</strong> <span class="status <?=e($o['status'])?>"><?=e($o['status'])?></span> · <strong>Integração:</strong> <span class="status <?=e($o['integration_status'])?>"><?=e($o['integration_status'])?></span></p><p><strong>Comprador:</strong> <?=e($o['buyer_email'])?> · <?=e($o['buyer_phone'])?></p><p><strong>Total:</strong> <?=money($o['total'])?></p></div>
        <h3 style="color:var(--navy)">Visitantes / ingressos</h3>
        <div class="card panel table-wrap"><table><thead><tr><th>Foto</th><th>Visitante</th><th>Documento</th><th>Ingresso</th><th>Validade</th><th>Código</th></tr></thead><tbody>
        <?php foreach($tickets as $t): ?><tr><td><img src="/admin.php?action=photo&id=<?=(int)$t['visitor_id']?>" style="width:64px;height:64px;object-fit:cover;border-radius:14px"></td><td><strong><?=e($t['first_name'].' '.$t['last_name'])?></strong><br><small><?=e($t['email'])?> · <?=e($t['phone'])?></small></td><td><?=e($t['document_type'].' '.$t['document_number'])?></td><td><?=e($t['product_name'])?></td><td><?=e(date('d/m/Y',strtotime($t['valid_from'])))?> → <?=e(date('d/m/Y',strtotime($t['valid_to'])))?></td><td class="ticket-code"><?=e($t['ticket_code'])?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php adminFoot();exit;
    }

    $rows=db()->query("SELECT * FROM orders ORDER BY id DESC LIMIT 300")->fetchAll();
    ?>
    <div class="section-title"><div><span class="pill">Operação</span><h2>Pedidos</h2></div><p>Últimos 300 pedidos.</p></div>
    <div class="card panel table-wrap"><table><thead><tr><th>Pedido</th><th>Data</th><th>Comprador</th><th>Total</th><th>Status</th><th>Integração</th></tr></thead><tbody>
    <?php foreach($rows as $o): ?><tr><td><a href="/admin.php?action=orders&id=<?=(int)$o['id']?>"><strong><?=e($o['order_code'])?></strong></a></td><td><?=e(date('d/m/Y H:i',strtotime($o['created_at'])))?></td><td><?=e($o['buyer_email'])?></td><td><?=money($o['total'])?></td><td><span class="status <?=e($o['status'])?>"><?=e($o['status'])?></span></td><td><span class="status <?=e($o['integration_status'])?>"><?=e($o['integration_status'])?></span></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php adminFoot();exit;
}
?>
<div class="section-title"><div><span class="pill">Visão geral</span><h2>Dashboard</h2></div><p>Operação de vendas e integração.</p></div>
<div class="metric-grid"><div class="card metric">Pedidos hoje<strong><?=$metrics['today']?></strong></div><div class="card metric">Pedidos pagos<strong><?=$metrics['paid']?></strong></div><div class="card metric">Receita simulada<strong><?=money($metrics['revenue'])?></strong></div><div class="card metric">Fila integração<strong><?=$metrics['pending']?></strong></div></div>
<div class="card panel" style="margin-top:22px"><h3 style="color:var(--navy);margin-top:0">Integração sem duplicidade</h3><p>Vendas aprovadas entram numa fila transacional. O sistema auxiliar faz claim, processa usando o código do pedido como chave idempotente e confirma com ACK.</p></div>
<?php adminFoot();
