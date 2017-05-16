<?php
/**
 * Created by PhpStorm.
 * User: kt
 * Date: 2017/3/28
 * Time: 上午8:58
 */
define("YCURL","http://218.3.139.84:8081/CNSS/rest/");
define("YCLOCAL_IP",'192.168.0.1');
define("YCPARTNER","YD001");
define("APPKEY","123456");
define("AUTH_SERVER",'http://192.168.1.235:6200/securityapi/v1.0/tokens/verify');

define("REDIS_SERVER",'192.168.1.221');
define("REDIS_PORT",6379);
define("REDIS_PWD",'Yd2017)!');
define("PIC_URL","http://images.yindianmall.cn/");
define("PAY_API","http://192.168.1.234:8080/order_api/refund");
define("CMT_PIC","http://app-comment.yindianmall.cn");
define("REDUCE_BONUS_API","http://192.168.1.226:8091/member_ship/bonus/returnBonus");
//define("REDUCE_BONUS_API","http://192.168.1.38:8080/member_ship/bonus/returnBonus");
$config['enable_hooks'] = FALSE;
$config['enable_hooks'] = TRUE;
$config['auto_refund'] = FALSE;
