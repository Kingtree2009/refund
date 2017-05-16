<?php

/**
 * Created by PhpStorm.
 * User: kt
 * Date: 2017/3/13
 * Time: 下午8:25
 */

defined('BASEPATH') OR exit('No direct script access allowed');

class Util
{
    private $CI;
    public static $s_TOKEN;
    function __construct()
    {
        $this->CI =& get_instance();
    }

    public function get_json($status= 1,$msg='OK',$data='')
    {
        $this->CI->output->set_header('Content-Type: application/json; charset=utf-8');
        $res_json =["status"=>$status,"msg"=>$msg,"data"=>$data];
        echo json_encode($res_json);
    }

    //功能：计算两个时间戳之间相差的日时分秒
//$begin_time  开始时间戳
//$end_time 结束时间戳
    public function timediff($begin_time,$end_time)
    {
        if($begin_time < $end_time){
            $starttime = $begin_time;
            $endtime = $end_time;
        }else{
            $starttime = $end_time;
            $endtime = $begin_time;
        }

        //计算天数
        $timediff = $endtime-$starttime;
        $days = intval($timediff/86400);
        //计算小时数
        $remain = $timediff%86400;
        $hours = intval($remain/3600);
        //计算分钟数
        $remain = $remain%3600;
        $mins = intval($remain/60);
        //计算秒数
        $secs = $remain%60;
        $res = array("day" => $days,"hour" => $hours,"min" => $mins,"sec" => $secs);
        return $res;
    }

    public function save_log($level,$msg){
        log_message($level,$msg);
    }

    public function send_json($para)
    {
        header('content-type:application/json;charset=utf8');
        header('Access-Control-Allow-Origin:*');
        header('Access-Control-Allow-Methods:POST,OPTIONS,GET');
        header('Access-Control-Allow-Headers:x-requested-with,content-type');
        header("Access-Control-Max-Age:86400");//请求缓存多长时间

        if(empty($para))
        {

            $res['successful'] = FALSE;
            $res['code'] = 500;
            $res['message'] = 'No data';
            $res['data'] = [];

            echo json_encode($res);
            die;
        }

        if($para['status'] == 200)
        {
            $res['successful'] = TRUE;
        }
        else
        {
            $res['successful'] = FALSE;
        }

        $res['message'] = $para['msg'];
        if(isset($para['data']))
        {
            $res['data'] = json_encode($para['data']);
        }
        else
        {
            $res['data'] = '';
        }

        $res['code'] = $para['status'];

        echo json_encode($res);
        die;
    }
    public function uuid($prefix = '')
    {
        if(function_exists("uuid_create")) {
            return uuid_create();
        } else {
            $chars = md5(uniqid(mt_rand(), true));
            $uuid  = substr($chars,0,8) . '-';
            $uuid .= substr($chars,8,4) . '-';
            $uuid .= substr($chars,12,4) . '-';
            $uuid .= substr($chars,16,4) . '-';
            $uuid .= substr($chars,20,12);
            return $prefix . $uuid;
        }
    }

    public function post($key)
    {
        $type = $this->CI->input->server('CONTENT_TYPE');

        switch ($type)
        {
            case "application/x-www-form-urlencoded":
                $res =  $this->CI->input->post($key);
                break;
            case "application/json":
                if(isset($g_json))
                {
                    $g_json[$key];
                }
                else
                {
                    global $g_json;
                    $json = $this->CI->input->raw_input_stream;
                    $json = json_decode($json,TRUE);
                    $g_json = $json;
                    $res = isset($json[$key]) ? $json[$key] : NULL;
                }

                break;
            case "application/json; charset=utf-8" :
                if(isset($g_json))
                {
                    $g_json[$key];
                }
                else
                {
                    global $g_json;
                    $json = $this->CI->input->raw_input_stream;
                    $json = json_decode($json,TRUE);
                    $g_json = $json;
                    $res = isset($json[$key]) ? $json[$key] : NULL;
                }
                break;
            default:
                $res =  $this->CI->input->post($key);

        }
        return $res;
    }

    public function json_post()
    {
        $res = file_get_contents("php://input");
        $res = json_decode($res,TRUE);
        return $res;
    }
/*
 * get userid from token
 */
    public function get_user()
    {
        $token = $this->CI->input->get_request_header('Authorization', TRUE);
        $token_json = base64_decode($token);
        $token = json_decode($token_json);
        $userid = $token->userId;
        $username = $token->userName;
        return ['id'=>$userid,'name'=>$username];
    }


    /*
     * get department id
     */
    public function get_dep()
    {
        $token = $this->CI->input->get_request_header('Authorization', TRUE);
        $token_json = base64_decode($token);
        $token = json_decode($token_json);
        $dep_id = $token->deptId;
        return $dep_id;
    }

//鉴权
    public function authentication($key)
    {
        // Get cURL resource
        $ch = curl_init();
        $url = AUTH_SERVER."?action=".$key;
        $token =self::$s_TOKEN;

// Set url
        curl_setopt($ch, CURLOPT_URL, $url);

// Set method
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');

// Set options
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
// Set headers
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Accept: application/json",
                "Authorization: $token"
            ]
        );
// Send the request & save response to $resp
        $resp_origin = curl_exec($ch);
// Close request to clear up some resources
        curl_close($ch);
        if(!$resp_origin) {
            $str = 'Error: "' . curl_error($ch) . '" - Code: ' . curl_errno($ch);
            die($str);
        } else {
            $resp = json_decode($resp_origin,TRUE);
            if($resp['successful'])
            {
                //auth pass

                return NULL;
            }
            echo $resp_origin;
            die;
        }

    }


    //认证
    public function vali_token()
    {
        $token = $this->CI->input->get_request_header('Authorization', TRUE);

        if(!$token)
        {
            $res['status'] = 402;
            $res['suc'] = FALSE;
            $res['msg'] = "Authorization is NULL";
            $this->send_json($res);
        }

// Get cURL resource
        $ch = curl_init();

// Set url
        curl_setopt($ch, CURLOPT_URL, AUTH_SERVER);

// Set method
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');

// Set options
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

// Set headers
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Accept: application/json",
                "Authorization: ".$token,
            ]
        );


// Send the request & save response to $resp
        $resp_origin = curl_exec($ch);
// Close request to clear up some resources
        curl_close($ch);

        $resp = json_decode($resp_origin,TRUE);


        if($resp['successful'])
        {
            //auth pass

            self::$s_TOKEN = $token;

            return NULL;
        }
        echo $resp_origin;
        die;
    }


    public function curl_post_json()
    {


        // Get cURL resource
        $ch = curl_init();

        // Set url
        curl_setopt($ch, CURLOPT_URL, 'https://echo.paw.cloud/?action=b1d7d9a3-9fb3-416d-ae47-8dd781be66b6');

        // Set method
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');

        // Set options
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        // Set headers
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Content-Type: application/json; charset=utf-8",
            ]
        );
        // Create body
        $json_array = [
            "key" => "value"
        ];
        $body = json_encode($json_array);

        // Set body
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        // Send the request & save response to $resp
        $resp = curl_exec($ch);

        if(!$resp) {
            die('Error: "' . curl_error($ch) . '" - Code: ' . curl_errno($ch));
        } else {
            echo "Response HTTP Status Code : " . curl_getinfo($ch, CURLINFO_HTTP_CODE);
            echo "\nResponse HTTP Body : " . $resp;
        }

        // Close request to clear up some resources
        curl_close($ch);
    }

    public function curl_post_form()
    {
        // Get cURL resource
        $ch = curl_init();

        // Set url
        curl_setopt($ch, CURLOPT_URL, 'https://echo.paw.cloud/?action=b1d7d9a3-9fb3-416d-ae47-8dd781be66b6');

        // Set method
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');

        // Set options
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        // Set headers
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Content-Type: application/x-www-form-urlencoded; charset=utf-8",
            ]
        );
        // Create body
        $body = [
            "key" => "value",
        ];
        $body = http_build_query($body);

        // Set body
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        // Send the request & save response to $resp
        $resp = curl_exec($ch);

        if(!$resp) {
            die('Error: "' . curl_error($ch) . '" - Code: ' . curl_errno($ch));
        } else {
            echo "Response HTTP Status Code : " . curl_getinfo($ch, CURLINFO_HTTP_CODE);
            echo "\nResponse HTTP Body : " . $resp;
        }

        // Close request to clear up some resources
        curl_close($ch);

    }


    /**
     * 对字符串执行指定次数替换
     * @param  Mixed $search   查找目标值
     * @param  Mixed $replace  替换值
     * @param  Mixed $subject  执行替换的字符串／数组
     * @param  Int   $limit    允许替换的次数，默认为-1，不限次数
     * @return Mixed
     */
    function str_replace_limit($search, $replace, $subject, $limit=-1){
        if(is_array($search)){
            foreach($search as $k=>$v){
                $search[$k] = '`'. preg_quote($search[$k], '`'). '`';
            }
        }else{
            $search = '`'. preg_quote($search, '`'). '`';
        }
        return preg_replace($search, $replace, $subject, $limit);
    }

    public function get_mysql_date()
    {
        $datestring = '%Y-%m-%d %H:%i:%s';
        $now = now();
        $time = mdate($datestring, $now);
        return $time;
    }

    public function debug ( $what ) {
        echo '<pre>';
        if ( is_array( $what ) )  {
            print_r ( $what );
        } else {
            var_dump ( $what );
        }
        echo '</pre>';
        die;
    }
    public function console_log( $data )
    {
        echo '<script>';
        echo 'console.log('. json_encode( $data ) .')';
        echo '</script>';
        die;
    }

    public function mongolog($msg,$module= 'default',$level='info')
    {

        $ch = curl_init();

// Set url
        curl_setopt($ch, CURLOPT_URL, "http://mongdb.yindianmall.cn/log/addlog");

// Set method
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');

// Set options
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        // Set headers
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Content-Type: application/x-www-form-urlencoded",
            ]
        );

        // Create body
        $body = [
            "id"=>$module,
            "log"=>$msg,
            "module"=>"refund",
            "level"=>$level
        ];
        $body = http_build_query($body);
        // Set body
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        // Send the request & save response to $resp
        $resp_json = curl_exec($ch);

// Close request to clear up some resources
        curl_close($ch);

    }


    public function is_too_frqnt($key)
    {
        $redis = new Redis();
        $redis->connect(REDIS_SERVER,REDIS_PORT);
        $redis->auth(REDIS_PWD);

        $nx = $redis->setnx($key,1);
        if($nx)
        {
            $redis->expire($key,5);
            return FALSE;
        }
        return TRUE;

    }



}