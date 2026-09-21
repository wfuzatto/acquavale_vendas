SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

INSERT INTO acquavale_vendas_products
(sku,name,description,product_type,price,ncm,cest,duration_days,validation_mode,requires_visitor,active,sort_order)
VALUES
('ING-1D','Ingresso 1 dia','Acesso individual ao AcquaVale por 1 dia.','ticket',129.90,NULL,NULL,1,'once_total',1,1,10),
('ING-2D','Ingresso 2 dias','Acesso individual ao AcquaVale por 2 dias consecutivos.','ticket',219.90,NULL,NULL,2,'once_per_day',1,1,20),
('LOCKER-DIA','Locker diário','Locação de armário durante o período de visita.','locker',35.00,NULL,NULL,1,'once_total',0,1,30)
ON DUPLICATE KEY UPDATE
name=VALUES(name),
description=VALUES(description);
