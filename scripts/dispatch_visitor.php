<?php
declare(strict_types=1);

if (PHP_SAPI!=='cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__).'/config/bootstrap.php';

use AcquaVale\VisitorPushService;

$service=new VisitorPushService();

if (!$service->configured()) {
    fwrite(STDERR,"Vale Visitor receiver is not configured.\n");
    exit(2);
}

$result=$service->dispatchPending(50);

echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)."\n";

exit(empty($result['errors']) ? 0 : 1);
