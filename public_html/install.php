<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$configFile=$root.'/config/config.local.php';
$lockFile=$root.'/storage/install.lock';
$schemaFile=$root.'/database/schema.sql';
$seedFile=$root.'/database/seed.sql';

if(session_status()!==PHP_SESSION_ACTIVE){
    session_name('acquavale_install');
    session_start();
}
if(empty($_SESSION['install_csrf'])) $_SESSION['install_csrf']=bin2hex(random_bytes(32));

$locked=is_file($lockFile);
$error=null;
$success=false;

$scriptDir=str_replace('\\','/',dirname((string)($_SERVER['SCRIPT_NAME']??'')));
$detectedBase=($scriptDir==='/'||$scriptDir==='.'||$scriptDir==='\\')?'':rtrim($scriptDir,'/');
$https=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https';
$host=$_SERVER['HTTP_HOST']??'localhost';
$detectedUrl=($https?'https':'http').'://'.$host.$detectedBase;

function runSqlFile(PDO $pdo,string $file):void{
    $sql=file_get_contents($file);
    if($sql===false) throw new RuntimeException('Não foi possível ler '.basename($file));
    $statements=preg_split('/;\s*(?:\r?\n|$)/',$sql)?:[];
    foreach($statements as $statement){
        $statement=trim($statement);
        if($statement!=='') $pdo->exec($statement);
    }
}

if($_SERVER['REQUEST_METHOD']==='POST'&&!$locked){
    try{
        $csrf=(string)($_POST['_csrf']??'');
        if(!hash_equals($_SESSION['install_csrf'],$csrf)) throw new RuntimeException('Sessão expirada. Atualize a página.');

        foreach(['pdo_mysql','fileinfo','json','curl'] as $ext){
            if(!extension_loaded($ext)) throw new RuntimeException("A extensão PHP {$ext} não está habilitada.");
        }

        $dbHost=trim((string)($_POST['db_host']??'localhost'));
        $dbPort=max(1,(int)($_POST['db_port']??3306));
        $dbName=trim((string)($_POST['db_name']??''));
        $dbUser=trim((string)($_POST['db_user']??''));
        $dbPass=(string)($_POST['db_password']??'');
        $adminEmail=trim((string)($_POST['admin_email']??''));
        $adminPassword=(string)($_POST['admin_password']??'');
        $basePath=trim((string)($_POST['base_path']??$detectedBase));
        $appUrl=rtrim(trim((string)($_POST['app_url']??$detectedUrl)),'/');
        $reservationProvider=strtolower(trim((string)($_POST['reservation_provider']??'expresso')));
        $expressoUser=trim((string)($_POST['expresso_user']??''));
        $expressoPassword=(string)($_POST['expresso_password']??'');
        $iplateServerUrl=rtrim(trim((string)($_POST['iplate_server_url']??'https://vale.expresso.app/iplate/backend/api/vehicle-entry-create.php')),'/');
        $iplateUsername=trim((string)($_POST['iplate_username']??''));
        $iplatePassword=(string)($_POST['iplate_password']??'');

        if($dbName===''||$dbUser==='') throw new RuntimeException('Informe o banco e o usuário MySQL criados no cPanel.');
        if(!filter_var($adminEmail,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Informe um e-mail administrativo válido.');
        if(strlen($adminPassword)<10) throw new RuntimeException('A senha administrativa deve ter pelo menos 10 caracteres.');
        if(!in_array($reservationProvider,['expresso','iplate'],true)) throw new RuntimeException('Selecione um provedor de reservas válido.');
        if($reservationProvider==='expresso'){
            if($expressoUser==='') throw new RuntimeException('Informe o usuário da API Expresso.');
            if($expressoPassword==='') throw new RuntimeException('Informe a senha da API Expresso.');
        }else{
            if($iplateServerUrl==='') throw new RuntimeException('Informe a URL do backend iPlate.');
            if($iplateUsername==='') throw new RuntimeException('Informe o usuário do backend iPlate.');
            if($iplatePassword==='') throw new RuntimeException('Informe a senha do backend iPlate.');
        }

        $pdo=new PDO(
            "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
            $dbUser,
            $dbPass,
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]
        );

        runSqlFile($pdo,$schemaFile);
        runSqlFile($pdo,$seedFile);

        foreach([$root.'/storage',$root.'/storage/logs',$root.'/storage/private',$root.'/storage/private/visitors',$root.'/storage/cache'] as $dir){
            if(!is_dir($dir)&&!mkdir($dir,0750,true)&&!is_dir($dir)) throw new RuntimeException('Não foi possível criar '.$dir);
        }
        if(!is_writable($root.'/config')) throw new RuntimeException('A pasta config não possui permissão de escrita para finalizar a instalação.');

        $config=[
            'app'=>[
                'url'=>$appUrl,
                'base_path'=>$basePath===''?'':'/'.trim($basePath,'/'),
                'timezone'=>'America/Sao_Paulo',
                'debug'=>false,
                'session_secure'=>$https,
            ],
            'db'=>[
                'host'=>$dbHost,
                'port'=>$dbPort,
                'name'=>$dbName,
                'user'=>$dbUser,
                'password'=>$dbPass,
            ],
            'admin'=>[
                'email'=>$adminEmail,
                'password_hash'=>password_hash($adminPassword,PASSWORD_DEFAULT),
            ],
            'api'=>[
                'key'=>bin2hex(random_bytes(32)),
                'claim_ttl_minutes'=>10,
            ],
            'uploads'=>[
                'max_photo_mb'=>8,
            ],
            'reservation'=>[
                'provider'=>$reservationProvider,
            ],
            'expresso'=>[
                'token_url'=>'https://vale.expresso.app/api/obter_token',
                'reservation_url'=>'https://vale.expresso.app/api/reserva',
                'user'=>$expressoUser,
                'password'=>$expressoPassword,
                'timeout_seconds'=>20,
            ],
            'iplate'=>[
                'server_url'=>$iplateServerUrl,
                'username'=>$iplateUsername,
                'password'=>$iplatePassword,
                'timeout_seconds'=>20,
            ],
        ];

        $php="<?php\nreturn ".var_export($config,true).";\n";
        if(file_put_contents($configFile,$php,LOCK_EX)===false) throw new RuntimeException('Não foi possível gravar config/config.local.php.');
        @chmod($configFile,0640);
        if(file_put_contents($lockFile,date(DATE_ATOM)."\n",LOCK_EX)===false) throw new RuntimeException('Não foi possível criar storage/install.lock.');
        @chmod($lockFile,0640);

        $success=true;
        $locked=true;
    }catch(Throwable $e){
        $error=$e->getMessage();
    }
}

$checks=[
    'PHP 8.2 ou superior'=>version_compare(PHP_VERSION,'8.2.0','>='),
    'PDO MySQL'=>extension_loaded('pdo_mysql'),
    'Fileinfo'=>extension_loaded('fileinfo'),
    'JSON'=>extension_loaded('json'),
    'cURL'=>extension_loaded('curl'),
    'Config gravável'=>is_writable($root.'/config'),
    'Storage gravável ou criável'=>is_writable($root.'/storage')||is_writable($root),
];
?><!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Instalação | AcquaVale Vendas</title><link rel="stylesheet" href="assets/css/app.css"></head>
<body class="admin-body"><div class="container" style="padding:36px 0;max-width:900px">
<div class="section-title"><div><span class="pill">cPanel / HostGator</span><h2>Instalação AcquaVale Vendas</h2></div><p>PHP + MySQL nativos. Sem Docker.</p></div>
<div class="card panel" style="margin-bottom:22px"><h3 style="margin-top:0;color:var(--navy)">Verificação do servidor</h3>
<?php foreach($checks as $label=>$ok):?><div class="summary-row"><span><?=htmlspecialchars($label)?></span><strong style="color:<?=$ok?'#315d21':'#b42318'?>"><?=$ok?'OK':'FALHOU'?></strong></div><?php endforeach;?>
</div>
<?php if($error):?><div class="notice error" style="margin-bottom:20px"><?=htmlspecialchars($error)?></div><?php endif;?>
<?php if($success):?><div class="notice success" style="margin-bottom:20px"><strong>Instalação concluída.</strong> O banco foi preparado, a configuração privada foi criada e o instalador foi bloqueado.</div><?php endif;?>
<?php if($locked):?>
<div class="card panel"><h3 style="margin-top:0;color:var(--navy)">Sistema instalado</h3><p>O arquivo <code>storage/install.lock</code> está presente. Para segurança, este instalador não executará novamente.</p><div style="display:flex;gap:12px;flex-wrap:wrap"><a class="btn btn-primary" href="./">Abrir loja</a><a class="btn btn-outline" href="admin.php">Abrir administração</a></div></div>
<?php else:?>
<div class="card panel"><h3 style="margin-top:0;color:var(--navy)">Dados do cPanel</h3><div class="notice" style="margin-bottom:20px">Antes de continuar, crie no cPanel um banco MySQL, um usuário MySQL e vincule o usuário ao banco com todos os privilégios. O instalador cria somente as tabelas.</div>
<form method="post"><input type="hidden" name="_csrf" value="<?=htmlspecialchars($_SESSION['install_csrf'])?>">
<div class="form-grid">
<div class="form-group"><label>Host MySQL</label><input name="db_host" value="<?=htmlspecialchars((string)($_POST['db_host']??'localhost'))?>" required></div>
<div class="form-group"><label>Porta</label><input type="number" name="db_port" value="<?=htmlspecialchars((string)($_POST['db_port']??'3306'))?>" required></div>
<div class="form-group"><label>Nome do banco</label><input name="db_name" value="<?=htmlspecialchars((string)($_POST['db_name']??''))?>" placeholder="usuario_acquavale" required></div>
<div class="form-group"><label>Usuário MySQL</label><input name="db_user" value="<?=htmlspecialchars((string)($_POST['db_user']??''))?>" placeholder="usuario_acquavale" required></div>
<div class="form-group full"><label>Senha MySQL</label><input type="password" name="db_password" required autocomplete="new-password"></div>
<div class="form-group"><label>E-mail administrador</label><input type="email" name="admin_email" value="<?=htmlspecialchars((string)($_POST['admin_email']??''))?>" required></div>
<div class="form-group"><label>Senha administrador</label><input type="password" name="admin_password" minlength="10" required autocomplete="new-password"></div>
<div class="form-group"><label>URL do sistema</label><input name="app_url" value="<?=htmlspecialchars((string)($_POST['app_url']??$detectedUrl))?>" required></div>
<div class="form-group"><label>Caminho base</label><input name="base_path" value="<?=htmlspecialchars((string)($_POST['base_path']??$detectedBase))?>" placeholder="/ingressos"><small>Deixe vazio quando o domínio/subdomínio apontar diretamente para public_html.</small></div>
<div class="form-group full" style="margin-top:10px"><div class="notice"><strong>Validação de reservas:</strong> use <strong>Expresso</strong> como padrão de produção. O iPlate fica disponível como alternativa quando seu backend estiver publicado e acessível por HTTPS.</div></div>
<div class="form-group full"><label>Provedor de reservas</label><select name="reservation_provider" id="reservation-provider"><option value="expresso" <?=($_POST['reservation_provider']??'expresso')==='expresso'?'selected':''?>>Expresso (recomendado)</option><option value="iplate" <?=($_POST['reservation_provider']??'')==='iplate'?'selected':''?>>Backend iPlate</option></select></div>

<div class="form-group full reservation-provider-fields" data-provider="expresso"><strong style="color:var(--navy)">API Expresso</strong><small>Mesmo fluxo que já funciona na instalação local: obter token e consultar a reserva.</small></div>
<div class="form-group reservation-provider-fields" data-provider="expresso"><label>Usuário API Expresso</label><input type="email" name="expresso_user" value="<?=htmlspecialchars((string)($_POST['expresso_user']??''))?>" autocomplete="username"><small>Credencial usada em <code>/api/obter_token</code>.</small></div>
<div class="form-group reservation-provider-fields" data-provider="expresso"><label>Senha API Expresso</label><input type="password" name="expresso_password" autocomplete="new-password"><small>Gravada somente no <code>config/config.local.php</code>.</small></div>

<div class="form-group full reservation-provider-fields" data-provider="iplate"><strong style="color:var(--navy)">Backend iPlate</strong><small>Use somente quando <code>login.php</code> e <code>reservation-search.php</code> estiverem publicados e acessíveis pelo servidor web.</small></div>
<div class="form-group full reservation-provider-fields" data-provider="iplate"><label>URL do backend iPlate</label><input name="iplate_server_url" value="<?=htmlspecialchars((string)($_POST['iplate_server_url']??'https://vale.expresso.app/iplate/backend/api/vehicle-entry-create.php'))?>"><small>O sistema deriva automaticamente <code>login.php</code> e <code>reservation-search.php</code>.</small></div>
<div class="form-group reservation-provider-fields" data-provider="iplate"><label>Usuário do backend iPlate</label><input name="iplate_username" value="<?=htmlspecialchars((string)($_POST['iplate_username']??''))?>" autocomplete="username"></div>
<div class="form-group reservation-provider-fields" data-provider="iplate"><label>Senha do backend iPlate</label><input type="password" name="iplate_password" autocomplete="new-password"></div>
</div><button class="btn btn-primary" style="margin-top:20px">Instalar sistema</button></form></div>
<script>
(function(){
    const select=document.getElementById('reservation-provider');
    if(!select) return;
    const refresh=()=>{
        document.querySelectorAll('.reservation-provider-fields').forEach(el=>{
            el.style.display=el.dataset.provider===select.value?'':'none';
        });
    };
    select.addEventListener('change',refresh);
    refresh();
})();
</script>
<?php endif;?>
</div></body></html>
