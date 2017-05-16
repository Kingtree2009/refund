<?php

/**
 * Created by PhpStorm.
 * User: kt
 * Date: 2017/3/16
 * Time: 上午8:48
 */
class Auth
{
    private $CI;
    public function __construct()
    {
        $this->CI = & get_instance();
    }



    public function check_options()
    {

        $method = $_SERVER['REQUEST_METHOD'];

        if($method == "options")
        {
            header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS");
            //OPTIONS这个预请求的有效时间,20天
            header("Access-Control-Max-Age: 1728000");
            //做出回应
           echo 'OK';
        }
        return NULL;
    }

}