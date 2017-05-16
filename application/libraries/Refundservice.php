<?php
/**
 * Created by PhpStorm.
 * User: kt
 * Date: 2017/3/13
 * Time: 下午8:25
 */

defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Created by PhpStorm.
 * User: kt
 * Date: 2017/3/14
 * Time: 下午7:27
 */

//退款状态,1201:申请退款，1202：待审核，1203：通过审核，1204：待验货，1205：待客服确认退款，
//1206：完成退款，1207：拒绝退款，1208：退款失败（支付宝，微信打款失败），1209：待补发审核，1210：确认补发，1211补发完成，1212：补发失败，1213：拒绝补发

class Refundservice
{
    private $CI;
    function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->model('refund_model','rm');
        $this->CI->load->library('util');
    }


    public function insert_refund_details($refund_id,$orderid)
    {
        $res = $this->CI->rm->get_order_details($orderid);
        foreach ($res as $item)
        {
            $value['id'] = $this->CI->util->uuid();
            $value['refund_id'] = $refund_id;
            $value['product_code'] = $item['sn'];
            $value['product_name'] = $item['name'];
            $value['product_price'] = $item['price'];
            $value['buy_count'] = $item['num'];
            $value['return_count'] = $item['num'];
            $value['refund_count'] = $item['num'];
            $value['refund_amount'] = $item['price']*$item['num'];
            $value['stored_count'] = $item['num'];
            $values[] = $value;
        }

        $value['id'] = $this->CI->util->uuid();
        $value['refund_id'] = $refund_id;
        $value['product_code'] = 'F01';
        $value['product_name'] = '退快递费';
        $value['product_price'] = $this->CI->rm->get_ship_amount($orderid);
        $value['buy_count'] = 1;
        $value['return_count'] = 0;
        $value['refund_count'] = 1;
        $value['refund_amount'] = $value['product_price'];
        $values[] = $value;
        $this->CI->rm->insert_refund_details($values);
    }

    public function cancel_order($orderid,$rid)
    {
        //Write refund_amount in yd_refund
        $refund_amount = $this->CI->rm->get_refund_amount_from_es_order($orderid);
        $this->CI->rm->update_refund_amount($refund_amount,$rid);


        //CALL YUN CANG interface
        $ordersn = $this->CI->rm->get_ordersn($orderid);

        /*
         * insert into yd_refund_details
         */

        $this->insert_refund_details($rid,$orderid);
        $yc_status = $this->call_yuncang_cancel_order($ordersn);
        $status = $yc_status[0];

        //云仓成功取消
        if(TRUE === $status)
        {
            $msg = "申请云仓取消订单成功,等确认退款";
            $this->add_refund_log($rid,1205,$msg);
            /*
             * set es_order status to have closed
             */
            $this->CI->rm->update_order_status($orderid,7);
            /*
             * set refund_status in yd_refund
             */
            $this->CI->rm->update_refund_status_direct($rid,1205);


        }
        else
        {
            //云仓取消失败
            $msg = "申请云仓取消订单成功,待客服拦截";
            $this->add_refund_log($rid,1208,$msg);
            /*
             * change refund status to for holdup
             */
            $this->CI->rm->update_refund_status_direct($rid,1208);

        }
        $res['msg'] = $msg;
        $res['status'] = 200;
        return $res;

    }


    public function all_refund($rid)
    {
        $update_status = $this->CI->rm->update_refund_status_refundid($rid,1201,1203);

        if($update_status['status'] == 200)
        {
            $res['status'] = 200;
            $res['msg'] = '等待验货';
        }
        else
        {
            $res['status'] = 502;
            $res['msg'] = '数据库错误：更新等待客服验货状态失败';
            $this->CI->util->save_log('error',$res['msg']);
        }

        return $res;
    }
    public function partial_refund($rid)
    {

        $update_status =  $this->CI->rm->update_refund_status_refundid($rid,1201,1202);
        if($update_status['status'] == 200)
        {
            $res['status']=200;
            $res['msg'] = '待客服确认部分退款';
        }
        else
        {
            $res['status'] = 502;
            $res['msg'] = '数据库错误：更新待客服确认部分退款状态失败';
            $this->CI->util->save_log('error',$res['msg']);
        }
        return $res;



    }



    public function call_yuncang_cancel_order($ordersn)
    {
        $ut=date('YmdHis',now());

        $md5 = YCLOCAL_IP.YCPARTNER.$ut.APPKEY;

        $md5 =md5($md5);
        $md5 = strtoupper($md5);

        $obj['operType'] = "CLO";
        $obj['CodeList'] = [["code"=>$ordersn]];
        $json_obj= json_encode($obj);

        $json_obj = base64_encode($json_obj);


        $url = YCURL."saleorder/sendOrderOperationMsg.do?ip=".YCLOCAL_IP.'&partner='.YCPARTNER.'&datetime='.$ut.'&sign='.$md5.'&JSON_OBJ='.$json_obj;
        $ch = curl_init();

// Set url
        curl_setopt($ch, CURLOPT_URL,$url);
// Set method
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');

// Set options
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
// Send the request & save response to $resp
        $resp_json = curl_exec($ch);

// Close request to clear up some resources
        curl_close($ch);

        $resp = json_decode($resp_json,TRUE);
        $code = $resp['ROWSET']['resultCode'];
        if($code == 1000 or ($code == 1005 && $resp['ROWSET']['ERROR'][0]['errorMsg']=='CLO销售订单已取消'))
        {
            $status = TRUE;
            $msg = "取消发货成功";


        }
        else
        {
            $status = FALSE;
            $msg = $resp['ROWSET']['ERROR'][0]['errorMsg'];
        }

        return [$status,$msg,$resp_json];
    }

    private function call_third_payment_api($sn,$orderAmount,$refundAmount,$refundMethod)
    {

        $token = $this->CI->input->get_request_header('Authorization', TRUE);
        $ch = curl_init();

// Set url
        curl_setopt($ch, CURLOPT_URL, PAY_API);

// Set method
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');

// Set options
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        // Set headers
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: $token",
                "Content-Type: application/x-www-form-urlencoded",
            ]
        );

        // Create body
        $body = [
            "sn" => $sn,
            "orderAmount" => $orderAmount,
            "refundAmount" => $refundAmount,
            "refundMethod" => $refundMethod
        ];
        $body = http_build_query($body);
        // Set body
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        // Send the request & save response to $resp
        $resp_json = curl_exec($ch);

//        var_dump($resp_json);
// Close request to clear up some resources
        curl_close($ch);
        $this->CI->util->mongolog("return payment json is :".$resp_json,"call_third_payment_api");
        if($resp_json) {
            $resp = json_decode($resp_json,TRUE);
            if($resp['successful'] == TRUE)
            {
                return TRUE;
            }
        }
        return FALSE;

    }





    public function call_payment($refund_id,$is_manual=FALSE)
    {
        $user = $this->CI->util->get_user();
        $data['id'] = $this->CI->util->uuid();
        $data['refund_id'] = $refund_id;

        $data['operator_user'] = $user['name'];
        $data['operator_id'] = $user['id'];
        if($is_manual)
        {
            $auto_refund = TRUE;
        }
        else
        {
            $auto_refund = $this->CI->config->item('auto_refund');
        }

        $msg = '未知付款方式';
        $res = $this->CI->rm->get_refund_amount_refund_id($refund_id);
        $sn = $res['sn'];
        $orderAmount = round($res['order_amount'],2);
        $refundAmount = round($res['refund_amount'],2);
        $refundMethod = $res['payment_id'];

        if($refundMethod == 1)
        {
            $msg = '支付宝';
        }

        if($refundMethod == 5)
        {
            $msg = '微信';
        }
        $this->CI->util->mongolog("auto_refund is $auto_refund","call_payment");

        if($auto_refund)
        {
            $status = $this->call_third_payment_api($sn,$orderAmount,$refundAmount,$refundMethod);
            if($status)
            {
                /*
                 * change refund status to 1206
                 */
                $this->CI->rm->update_refund_status_direct($refund_id,1206);
                $data['event_code'] = 1006;
                $data['event_name'] = $this->CI->rm->get_refund_status($data['event_code']);
                $data['message'] = "打款成功，完成售后";
            }
            else
            {
                $data['event_code'] = 1005;
                $data['event_name'] = $this->CI->rm->get_refund_status($data['event_code']);
                $data['message'] = "自动退款失败，请人工打款".$msg;
            }
        }
        else
        {
            $data['event_code'] = 1005;
            $data['event_name'] = $this->CI->rm->get_refund_status($data['event_code']);
            $data['message'] = "自动退款关闭，请人工打款".$msg;
            $status = FALSE;
        }



        $this->CI->rm->insert_yd_refund_log($data);

        return $status;
    }


    public function getOrder($orderid)
    {
        return $this->CI->rm->getYCOrder($orderid);
    }


    public function sendYcOrder($order)
    {
        $ut=date('YmdHis',now());

        $md5 = YCLOCAL_IP.YCPARTNER.$ut.APPKEY;

        $md5 =md5($md5);
        $md5 = strtoupper($md5);
        $json_obj= json_encode($order);

        $encode_json = base64_encode($json_obj);

        $url =YCURL.'saleorder/SaleORder.do?';
        $url .= "ip=".YCLOCAL_IP.'&partner='.YCPARTNER.'&datetime='.$ut.'&sign='.$md5.'&JSON_OBJ='.$encode_json;
//echo $url;
        $ch = curl_init();

// Set url
        curl_setopt($ch, CURLOPT_URL,$url);
// Set method
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');

// Set options
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
// Send the request & save response to $resp
        $resp_json = curl_exec($ch);

// Close request to clear up some resources
        curl_close($ch);
        if($resp_json)
        {
            $resp = json_decode($resp_json,TRUE);
            $code = $resp['ROWSET']['resultCode'];

            if($code == 1000 or ($code == 1005 && $resp['ROWSET']['ERROR'][0]['errorMsg']=='该订单处理失败，订单已存在'))
            {
                $status = 200;
                $msg = "发货成功";
            }
            else
            {
                $status = 504;
                $msg = $resp['ROWSET']['resultMsg'];

            }
        }
        else
        {
            $status = 505;
            $msg = '云仓连接失败';
        }


        return [$status,$msg,$resp_json];
    }

    public function get_refund_sn()
    {
        $redis = new Redis();
        $redis->connect(REDIS_SERVER,REDIS_PORT);
        $redis->auth(REDIS_PWD);
        $datestring = '%Y%m%d';
        $time = time();
        $key = mdate($datestring, $time);
        $value = $redis->incr($key);

        $value = sprintf('%05s', $value);
        return $sn = $key.$value;
    }

    public function get_refund_detail($sn)
    {
        $refund_details = $this->CI->rm->get_yd_refund_detail_with_rid($sn);
        if(empty($refund_details))
        {
            $refund_details = [];
        }
        $res['refund_details'] = $refund_details;

        $res['refund_log'] = $this->CI->rm->get_yd_refund_log_with_rid($sn);

        $orderid = $this->CI->rm->get_yd_refund_oid_with_rid($sn);
        if(empty($orderid))
        {
            return [];
        }

        $res['vorder_info'] = $this->CI->rm->v_get_vorderinfo_orderid($orderid);
        $res['vorder_logs']  = $this->CI->rm->v_get_vorderlog_orderid($orderid);
        $res['vorder_items'] = $this->CI->rm->v_get_vorderitems_orderid($orderid);
        return $res;
    }

    /*
     * 减少yd_mkt_supplier_wallet_sell，yd_mkt_supplier_wallet tables
     * 退货成功
     */
    private function reduce_supplier_wallet($orderid,$sn,$amount)
    {
        $this->CI->db->trans_strict(FALSE);
        $this->CI->db->trans_start();
        $res = $this->CI->rm->reduce_supplier_wallet_sell($orderid,$sn,$amount);
        if($res)
        {
            $this->CI->rm->reduce_supplier_wallet($res['SUPPLIER_ID'],$res['MONEY']);
        }

        $this->CI->db->trans_complete();

        if(!$res)
            return FALSE;
    }


    /*
     * 成功拦截订单
     */

    public function suc_holdup($refund_id,$user)
    {
        /*
         * add log in yd_refund_log
         */
        $data['id'] = $this->CI->util->uuid();
        $data['refund_id'] = $refund_id;
        $data['event_code'] =  1002;
        $data['event_name'] = '同意退款';
        $data['message'] = '成功拦截订单';
        $data['operator_user'] = $user['name'];
        $data['operator_id'] = $user['id'];
        $this->CI->rm->insert_yd_refund_log($data);

        $this->CI->rm->update_refund_status_direct($refund_id,1205);

        /*
         * set es_order status to 7
         */
        $order_id = $this->CI->rm->get_yd_refund_oid_with_rid($refund_id);

        $this->CI->rm->update_order_status($order_id,7);
        $res['msg'] = '成功拦截订单';

        return $res;
    }
    /*
     * 失败拦截订单
     */
    public function fail_holdup($refund_id,$user)
    {
        /*
 * add log in yd_refund_log
 */
        $data['id'] = $this->CI->util->uuid();
        $data['refund_id'] = $refund_id;
        $data['event_code'] =  1009;
        $data['event_name'] = '取消订单';
        $data['message'] = '拦截订单失败';
        $data['operator_user'] = $user['name'];
        $data['operator_id'] = $user['id'];
        $this->CI->rm->insert_yd_refund_log($data);
        $this->CI->rm->update_refund_status_direct($refund_id,1201);
        $res['msg'] = $data['message'];
        $res['status'] = 200;
        return $res;
    }


    public function mayI_review($refund_id,$user)
    {
        $uid = $this->CI->rm->get_lock_uid($refund_id);
        if($uid)
        {
            if($uid == $user['id'])
            {
                $res['status'] = 200;
            }
            else
            {
                $res['status'] = 500;
                $res['msg'] = '该订单已被他人锁定，无法操作';
            }
        }
        else
        {
            $res['status'] = 501;
            $res['msg'] = '该订单尚未锁定，请先锁定';
        }
        return $res;

    }


    public function refund_review($refund_id,$i_details,$comment,$user,$expressno)
    {

        /*
         * 事务
         */


        $this->CI->db->trans_start();

        $this->CI->rm->lock_refund($refund_id,NULL);

        if(empty($i_details))
        {
            /*
             * It is refuse to refund
             */

            /*
             * change status in yd_refund table
             */

            $this->CI->rm->update_refund_status_refundid($refund_id,1202,1207);
            /*
             * add log in yd_refund_log
             */
            $data['id'] = $this->CI->util->uuid();
            $data['refund_id'] = $refund_id;
            $data['event_code'] =  1207;
            $data['event_name'] = '拒绝退款';
            $data['message'] = $comment;
            $data['operator_user'] = $user['name'];
            $data['operator_id'] = $user['id'];

            $this->CI->rm->insert_yd_refund_log($data);
            $this->CI->rm->update_refund_status_direct($refund_id,1207);
            $res['status'] = 200;
            $res['msg'] = '已拒绝售后申请！';
        }
        else
        {
            /*
             * add log in yd_refund_log
             */

            $data['id'] = $this->CI->util->uuid();
            $data['refund_id'] = $refund_id;
            $data['event_code'] =  1002;
            $data['event_name'] = '同意退款';
            $data['message'] = $comment;
            $data['operator_user'] = $user['name'];
            $data['operator_id'] = $user['id'];
            $this->CI->rm->insert_yd_refund_log($data);

            $amount_arr = array_column($i_details,'refund_amount');
            $i_price = array_sum($amount_arr);


            $price = $i_price;
            $this->CI->rm->update_refund_amount_id($refund_id,$price);




                /*
                 * insert to refund_details
                 */

            $this->CI->rm->insert_refund_details($i_details);


                $return_count_array = array_column($i_details,'return_count');
                $sum = array_sum($return_count_array);
                $res['sum'] = $sum;
                if($sum)
                {

                    $row = $this->CI->rm->save_refund_expressno($refund_id,$expressno);

                    $status = $this->sendYC_sendSaleReturn($refund_id,$expressno);
                    if($status)
                    {

                        $this->CI->rm->update_refund_status_direct($refund_id,1203);
                    }
                    else
                    {

                        $this->CI->rm->update_refund_status_direct($refund_id,1202);
                    }
                    $res['status'] = 200;
                    $res['msg'] = '有退货，请验货再完成退款';
                }
                else
                {
                    /*
                     * set refund status to for refund money
                     */
                    $this->CI->rm->update_refund_status_direct($refund_id,1205);

                    $res['status'] = 200;
                    $res['msg'] = '无退货，请审核后退款';

                }
            }


        $this->CI->db->trans_complete();
        return $res;
    }
/*
 * 扣减分润
 */
    public function deduct_bonus_refundid($refund_id)
    {
        $orderid = $this->CI->rm->get_yd_refund_oid_with_rid($refund_id);
        $refund_items = $this->CI->rm->get_yd_refund_detail_with_rid($refund_id);
        foreach ($refund_items as $item)
        {
            $sn = $item['product_code'];
            $refund_count = $item['refund_count'];
            $sn_list[] = ['sn'=>$sn,'refund_count'=>$refund_count];
        }

        $len = count($sn_list);
        if($len === 0)
        {
            return NULL;
        }
        if($len === 1 && $sn_list[0]['sn'] === 'F01')
        {
            return NULL;
        }
        $this->deduct_bonus($orderid,$sn_list);

    }

    private function update_bonusid_bonus_item($bonus_list)
    {
        foreach ($bonus_list as $item)
        {
            $bonusid = $item['ID'];
            $orderid = $item['ORDER_ID'];
            $memid = $item['MEMBER_ID'];
            $status = 1;
            $this->CI->rm->update_bonusid_bonusitem($bonusid,$orderid,$memid,$status);
        }
    }
	public function deduct_bonus($order_id,$sn_list)
	{
	    try{
            $this->CI->db->trans_strict(FALSE);
            $this->CI->db->trans_start();

            $member_list = $this->deduct_wallet_bonus_item($order_id,$sn_list);

            $res = $this->CI->rm->get_member_order_refund_money($order_id,$member_list);

            $bonus_list = $this->deduct_wallet_bonus($res);
            /*
             * 更新bonus id 与wallet_bonus table associated
             */
            $this->update_bonusid_bonus_item($bonus_list);
            $this->deduct_member_wallet($member_list,$res);
            $this->CI->db->trans_complete();
        }
        catch (Exception $e)
        {
            $msg = $e->getMessage()."order_id:$order_id";
            $this->CI->util->save_log('error',$msg);
            $this->CI->db->trans_complete();
        }

        if ($this->CI->db->trans_status() === FALSE)
        {
            // generate an error... or use the log_message() function to log your error
            $msg = "transaction error in function deduct_bonus  parameter : order_id:$order_id";
            $this->CI->util->save_log('error',$msg);
        }

	}

	/*
	 *deduct_bonus in  yd_mkt_member_wallet_bonus_item
	 */

	private function deduct_wallet_bonus_item($order_id,$sn_list)
    {
        $list_sn_key = array_column($sn_list,'refund_count','sn');
        $list_sn = array_column($sn_list,'sn');

//        $this->CI->util->debug($list_sn);
        $res = $this->CI->rm->get_list_bonus_item($order_id,$list_sn);


        foreach ($res as $key=>$item)
        {
            $sn = $item['SN'];
            $res[$key]['AMOUNT'] = -$list_sn_key[$sn];
            $res[$key]['MONEY'] = round($item['PRICE'] * $res[$key]['AMOUNT'] * $item['POINT']/100,4);
            $res[$key]['UPDATE_TIME'] = $this->CI->util->get_mysql_date();
            $res[$key]['STATUS'] = 1;
            unset($res[$key]['ID']);
        }

        if($res)
        {
            $this->CI->rm->batch_insert_bonus_item($res);
        }

        /*
         * get the member and order sum money
         */
        $member_list = array_column($res,'MEMBER_ID');
        return $member_list;
    }

    private function deduct_wallet_bonus($wlist)
    {

        $res = $this->CI->rm->insert_mem_order_bonus($wlist);
        return $res;
    }

    private function deduct_member_wallet($member_list,$res)
    {
        $res_mem_key = array_column($res,NULL,'MEMBER_ID');

        $balance_list = $this->CI->rm->get_balance($member_list);
        foreach ($balance_list as $key=>$item)
        {
            $memid = $item['MEMBER_ID'];
            $balance_list[$key]['BALANCE'] = $item['BALANCE'] + $res_mem_key[$memid]['MONEY'];
            unset($balance_list[$key]['ID']);
            $balance_list[$key]['UPDATE_TIME'] = $this->CI->util->get_mysql_date();
        }
        $this->CI->rm->update_member_wallet($balance_list);
    }




    public function refuse_refund($rid)
    {
        /*
        * add log in yd_refund_log
        */
        $user = $this->CI->util->get_user();
        $data['id'] = $this->CI->util->uuid();
        $data['refund_id'] = $rid;
        $data['event_code'] =  1007;
        $data['event_name'] = '拒绝退款';
        $data['message'] = "拒绝退款";
        $data['operator_user'] = $user['name'];
        $data['operator_id'] = $user['id'];

        try
        {
            $this->CI->db->trans_strict(FALSE);
            $this->CI->db->trans_start();

            $this->CI->rm->insert_yd_refund_log($data);
            $this->CI->rm->update_refund_status_direct($rid,1207);

            $this->CI->db->trans_complete();

        }
        catch (Exception $e)
        {
            $res['status'] = 500;
            $res['msg'] = '数据库异常';
            $res['data'] = $e->getMessage();
            return $res;
        }

        $res['status'] = 200;
        $res['msg'] =  '拒绝退款,操作成功';
        $res['data'] = '拒绝退款,操作成功';
        return $res;

    }



    public function update_suplier_wallet($rid)
    {
        $order_id = $this->CI->rm->get_yd_refund_oid_with_rid($rid);
        $details = $this->CI->rm->get_yd_refund_detail_with_rid($rid);
        foreach ($details as $detail) {
            $sn = $detail['product_code'];
            $store = $detail['stored_count'];
            $this->reduce_supplier_wallet($order_id,$sn,$store);
        }
    }

    public function get_sendSaleReturnData($refund_id,$expressno)
    {

        $t = $this->CI->rm->get_vrefund_item($refund_id);
        $r = $this->CI->rm->get_ydrefund_item($refund_id);
        $data['salereturnno'] = $r['sn'];


        $data['oldorderno'] = $t['order_sn'];

        $data['custname'] = '';
        $data['custphone'] = $t['mobile'];
        $data['carrierno'] ='';
        $data['carriername'] = '';
        $data['expressno'] = $expressno;
        $data['orderdate'] = '';
        $data['totalamount'] = $r['refund_amount'];
        $data['custcomments'] = '';
        $data['clientcomments'] = '';
        $data['storeno'] = '';
        $data['storename'] = '';
        $d_list = $this->CI->rm->get_yd_refund_detail_with_rid($refund_id);
        foreach ($d_list as $item)
        {
            if($item['return_count'] == 0)
                continue;
            $value['barcode'] = $item['product_code'];
            $value['itemname'] = $item['product_name'];

            $value['unit'] = '';
            $value['qty'] = $item['return_count'];
            $value['price'] = $item['product_price'];
            $value['amount'] = $item['refund_amount'];
            $value['custcomments'] = '';
            $value['clientcomments'] = '';
            $data['Item'][] = $value;
        }
        return $data;


    }

    public function sendYC_sendSaleReturn($refund_id,$expressno)
    {
        $odata = $this->get_sendSaleReturnData($refund_id,$expressno);
        $data['SaleReturnList'][0] =$odata;

        $res = $this->sendYc($data,'saleReturn/sendSaleReturn.do?');

        if($res[0] == 200)
        {
            $this->add_refund_log($refund_id,1008,'向云仓发送销售退货单成功');
            return TRUE;
        }
        else
        {
            $this->add_refund_log($refund_id,1008,$res[1]);
            return FALSE;
        }

    }

    public function sendYc($order,$api)
    {
        $ut=date('YmdHis',now());

        $md5 = YCLOCAL_IP.YCPARTNER.$ut.APPKEY;

        $md5 =md5($md5);
        $md5 = strtoupper($md5);
        $json_obj= json_encode($order);

        $encode_json = base64_encode($json_obj);

        $url =YCURL.$api;
        $url .= "ip=".YCLOCAL_IP.'&partner='.YCPARTNER.'&datetime='.$ut.'&sign='.$md5.'&JSON_OBJ='.$encode_json;

        $ch = curl_init();

// Set url
        curl_setopt($ch, CURLOPT_URL,$url);
// Set method
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');

// Set options
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
// Send the request & save response to $resp
        $resp_json = curl_exec($ch);

// Close request to clear up some resources
        curl_close($ch);


        if($resp_json)
        {
            $resp = json_decode($resp_json,TRUE);
            $code = $resp['ROWSET']['resultCode'];

            if($code == 1000)
            {
                $status = 200;
                $msg = "成功";
            }
            else
            {
                $status = 504;
                $msg = $resp['ROWSET']['resultMsg'];

            }
        }
        else
        {
            $status = 505;
            $msg = '云仓连接失败';
        }


        return [$status,$msg,$resp_json];
    }

    public function add_refund_log($rid,$rcode,$msg)
    {
        $user = $this->CI->util->get_user();
        $data['id'] = $this->CI->util->uuid();
        $data['refund_id'] = $rid;
        $data['event_code'] = $rcode;
        $data['event_name'] = $this->CI->rm->get_refund_status($data['event_code']);
        $data['message'] = $msg;
        $data['operator_user'] = $user['name'];
        $data['operator_id'] = $user['id'];

        $res = $this->CI->rm->insert_yd_refund_log($data);

    }

    public function sendYcRefundOrder($refund_order,$refund_id)
    {
        $r = $this->CI->rm->get_ydrefund_item($refund_id);
        $t = $this->CI->rm->get_vrefund_item($refund_id);

        $data['salereturnno'] = $refund_order;

        $data['oldorderno'] = $t['order_sn'];
        $data['custname'] = '';
        $data['custphone'] = $t['mobile'];
        $data['carrierno'] = '';
        $data['carriername'] = '';
        $data['expressno'] = '';
        $data['orderdate'] = '';
        $data['totalamount'] = $r['refund_amount'];
        $data['custcomments'] = '';
        $data['clientcomments'] = '';
        $data['storeno'] = '';
        $data['storename'] = '';
    }

    private function getRefundApiData($rid)
    {
        $dl = $this->CI->rm->get_yd_refund_detail_with_rid($rid);
        $o = $this->CI->rm->getSnWrefundid($rid);
        $res['orderCode'] = $o['sn'];
        $res['userId'] = $o['member_id'];
        $res['productAmount'] = 0;
        foreach ($dl as $v)
        {
            /*
             *             "count":3,
            "id":"aaaaaaaaaa0",
            "imageUrl":"img0",
            "packageName":"商品0",
            "price":0
             */
            $item['count'] = $v['refund_count'];
            $item['id'] = $v['product_code'];
            if($item['id'] == 'F01')
                continue;
            $item['price'] = $v['product_price'];
            $g = $this->CI->rm->getEsgoods($item['id']);
            $item['imageUrl'] = $g['thumbnail'];
            $item['packageName'] = $g['name'];
            $res['productAmount'] += $v['refund_amount'];
            $res['orderItemsList'][] = $item;
        }

        return $res;

    }

    public function reduceBounsApi($rid)
    {
        $data = $this->getRefundApiData($rid);
        $this->sendReduceBounsApi($data);
    }

    private function sendReduceBounsApi($data)
    {
        $token = $this->CI->input->get_request_header('Authorization', TRUE);
        $ch = curl_init();

        $data_string = json_encode($data);
        curl_setopt($ch, CURLOPT_POSTFIELDS,$data_string);
// Set url
        curl_setopt($ch, CURLOPT_URL, REDUCE_BONUS_API);

// Set method
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');


// Set options
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        // Set headers
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: $token",
                "Content-Type: application/json",
            'Content-Length: '.strlen($data_string)
            ]
        );

        // Create body

//        $body = $data;
//        $body = http_build_query($body);
//        // Set body
//        curl_setopt($ch, CURLOPT_POST, 1);
//        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        // Send the request & save response to $resp
        $resp_json = curl_exec($ch);

//        $this->CI->util->debug($resp_json);
// Close request to clear up some resources
        curl_close($ch);
        $this->CI->util->mongolog("return reducebouns json is :".$resp_json,"call_third_reduce_bonus_api");
        if($resp_json) {
            $resp = json_decode($resp_json,TRUE);
            if($resp['successful'] == TRUE)
            {
                return TRUE;
            }
        }
        return FALSE;
    }

    public function is_shiped($orderid)
    {
        $status = $this->CI->rm->get_order_status($orderid);
        if($status == 2)
        {
            return FALSE;
        }
        return TRUE;
    }

    public function get_order_status_name($orderid)
    {
        $status = $this->CI->rm->get_order_status($orderid);

        switch ($status)
        {
            case 2:
                $name = '订单待发货,进入订单取消流程';
                break;
            case 3:
                $name = '订单已发货,进入客服审核流程';
                break;
            case 4:
                $name = '订单已收货,进入客服审核流程';
                break;
            case 5:
                $name = '订单已完成,进入客服审核流程';
                break;
            default:
                $name = "订单状态未定义，状态：$status";
                break;
        }
        return $name;
    }
}