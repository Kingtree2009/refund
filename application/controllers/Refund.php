<?php

/**
 * Created by PhpStorm.
 * User: kt
 * Date: 2017/3/13
 * Time: 下午8:11
 */


class Refund extends CI_Controller
{

    public function __construct()
    {
        parent::__construct();
        $this->load->model('refund_model');
        $this->load->helper('url_helper');
        $this->load->library('util');
        $this->load->library('refundservice');

//        $this->load->library('input');
    }

//receive refund request from app
//退款类型；1101：取消订单；1102：补发货；1103：部分退款；1104：整单退货
//申请退货
    public function receiveRefund()
    {
        $user = $this->util->get_user();
        $key = 'receiveRefund'.$user['id'];
        $frqnt = $this->util->is_too_frqnt($key);
        if($frqnt)
        {
            $res['status'] = 402;
            $res['msg'] = "api receiveRefund too frequent";
            $this->util->send_json($res);

        }

        $res['msg'] = 'receive refund success!';
        $res['status'] = 1;

        $orderid = $this->util->post('orderid');
        $refund_type = $this->util->post('type');
        $comment = $this->util->post('comment');



        if (NULL === $orderid or NULL === $refund_type) {
            $res['status'] = 502;
            $res['msg'] = "orderid or type or memberId is null";
            $this->util->send_json($res);

        }

        $depid = $this->util->get_dep();
        //check whether transfer completed from es_order table
        try {
            $res = $this->refund_model->whether_transfered($orderid, $refund_type);
            if (200 !== $res['status']) {

                $this->util->send_json($res);
                return NULL;

            }

//            query is there no finished refund order
            $count = $this->refund_model->validate_refund_order($orderid);
            if ($count !== 0) {
                $res['msg'] = "该订单已经在处理中或已经完成售后";
                $res['status'] = 503;

                $this->util->send_json($res);

                return NULL;
            }
            $sn = $this->refundservice->get_refund_sn();
            $oi = $this->refund_model->get_order_item($orderid);
            $memid = $oi['member_id'];
            $this->db->trans_start();
            /*
             * insert yd_refund order
             */
            $res = $this->refund_model->insert_refund_order($orderid, $comment, $user['id'], $depid, $sn, $memid);

            if ($res['status'] !== 200) {
                $this->util->send_json($res);
                return NULL;
            }
            $msg = $this->refundservice->get_order_status_name($orderid);
            $uuid = $res['uuid'];
            $data['id'] = $this->util->uuid();
            $data['refund_id'] = $uuid;
            $data['event_code'] = 1000;
            $data['event_name'] = $this->refund_model->get_refund_status($data['event_code']);
            $data['message'] = $msg;
            $data['operator_user'] = $user['name'];
            $data['operator_id'] = $user['id'];


            $status =$this->refundservice->is_shiped($orderid);
            if($status)
            {
                /*
                 * change status to for review
                 */
                $this->refund_model->update_refund_status_direct($uuid,1201);
                /*
                 * save log to refund log
                 */
                $data['id'] = $this->util->uuid();
                $data['refund_id'] = $uuid;
                $data['event_code'] = 1201;
                $data['event_name'] = $this->refund_model->get_refund_status($data['event_code']);
                $data['message'] = "已经发货，待客服审核";
                $data['operator_user'] = $user['name'];
                $data['operator_id'] = $user['id'];
                $this->refund_model->insert_yd_refund_log($data);
            }
            else
            {
                /*
                 * 取消订单
                 */
                $res = $this->refundservice->cancel_order($orderid,$uuid);
            }


            $this->db->trans_complete();

        }
        catch (Exception $e) {
            echo 'Message: ' . $e->getMessage();
            $res['msg'] = 'Exception in database';
            $res['status'] = 502;
            $this->util->send_json($res);
        }

        $this->util->send_json($res);

    }




    public function cs_part_check()
    {
        $orderid = $this->input->post('orderid');

        $uuid = $this->input->post('uuid');
        $opinion = $this->input->post('type');

        if (NULL === $orderid or NULL === $uuid or NULL === $opinion) {
            $res['status'] = 502;
            $res['msg'] = "comment or orderid  or uuid is null";
            $this->util->send_json($res);
            return null;
        }
        if ($opinion == 1) {
            //allow refund
            $res = $this->refund_model->update_status_with_uuid($uuid, 1205, 1203);
            if ($res['status'] == 1) {
                $res['msg'] = '通过审核';
            }
        } else {
            $res = $this->refund_model->update_status_with_uuid($uuid, 1205, 1207);
            if ($res['status'] == 1) {
                $res['msg'] = '拒绝退款';
            }
        }
        $this->util->send_json($res);
        return NULL;
    }

//Is there back goods
    public function cs_part_inspect_goods()
    {
        $orderid = $this->input->post('orderid');
        $uuid = $this->input->post('uuid');
        $opinion = $this->input->post('type');

        if (NULL === $orderid or NULL === $uuid or NULL === $opinion) {
            $res['status'] = 2;
            $res['msg'] = "comment or orderid  or uuid is null";
            $this->util->send_json($res);
            return null;
        }
        if ($opinion == 1) {
            //there are back goods
            //change status to inspect goods
            $this->refund_model->update_status_with_uuid($uuid, 1203, 1205);
        } else {
            //refund  to customer
            //call payment function
            $pay_status = $this->refundservice->call_payment($orderid);
            if ($pay_status) {
                $this->refund_model->update_status_with_uuid($uuid, 1203, 1206);
                $res['status'] = 1;
                $res['msg'] = 'Refund success';
            } else {
                $res['status'] = 2;
                $res['msg'] = 'pay fail.orderid:' . $orderid;
                $this->util->save_log('error', $res['msg']);

            }

        }
        $this->util->send_json($res);
        return NULL;
    }

    //customer server record log
    public function refund_comment()
    {
        $recorder_id = $this->input->post('user_id');
        $uuid = $this->input->post('uuid');
        $comment = $this->input->post('comment');

        if (NULL === $recorder_id or NULL === $uuid or NULL === $comment) {
            $res['status'] = 2;
            $res['msg'] = "comment or recorder_id  or uuid is null";
            $this->util->send_json($res);
            return null;
        }
        $num = $this->refund_model->save_cs_comment($uuid, $comment, $recorder_id);

        if ($num == 1) {
            $res['status'] = 200;
            $res['msg'] = 'insert comment success';

        } else {
            $res['status'] = 502;
            $res['msg'] = 'error in insert comment.uuid:' . $uuid;

        }
        $this->util->send_json($res);
        return NULL;

    }


//get order list
    public function get_order_condition()
    {
        $this->util->authentication('b1d7d9a3-9fb3-416d-ae47-8dd781be66b6');

        $page = $this->util->post('page');
        $size = $this->util->post('size');
        $condition = $this->util->post('condition');
        if (NULL === $page or NULL === $size or $page < 1 or $size < 1) {
            $res['status'] = 502;
            $res['msg'] = "page or size is null or invalid (smaller than 1) page:$page,size:$size";
            $this->util->send_json($res);
            return null;
        }
        $from = ($page - 1) * $size;
        $data = $this->refund_model->get_order_condition($condition, $from, $size);

        $res['status'] = 200;
        $res['msg'] = 'Get order success';
        $res['data'] = $data;
        $this->util->send_json($res);
        return NULL;
    }

    public function get_order_detail()
    {
        $this->util->authentication('b1d7d9a3-9fb3-416d-ae47-8dd781be66b6');
        $orderid = $this->util->post('orderid');


        if (NULL === $orderid) {
            $res['status'] = 402;
            $res['msg'] = "orderid is null";
            $this->util->send_json($res);
            return null;
        }

        $data = $this->refund_model->get_order_detail($orderid);

        $res['status'] = 200;
        $res['msg'] = 'query success';
        $res['data'] = $data;
        $this->util->send_json($res);

    }

    /*
     * add comment to es_order_log
     * 订单备注
     */
    public function add_es_order_log()
    {
        $this->util->authentication('43a8968b-25a0-4f03-8476-ee4ae064af41');
        $msg = $this->util->post('msg');
        $orderid = $this->util->post('orderid');

        $user = $this->util->get_user();
        $op_id = $user['id'];
        $name = $user['name'];

        if (NULL === $orderid or NULL === $msg) {
            $res['status'] = 402;
            $res['msg'] = "msg or orderid is null.msg:$msg,orderid:$orderid";
            $this->util->send_json($res);
            return null;
        }

        $res = $this->refund_model->insert_es_order_log($orderid, $msg, $op_id, $name);
        $this->util->send_json($res);
        return NULL;
    }


    /*
     * get refund order by condition
     */

    public function get_refund_order_cdn()
    {
        $page = $this->input->post('page');
        $size = $this->input->post('size');
        $cdn = $this->input->post('condition');

        if (NULL === $page or NULL === $size) {
            $res['status'] = 402;
            $res['msg'] = "msg or orderid is null";
            $this->util->send_json($res);
            return null;
        }
        $from = ($page - 1) * $size;
        $data = $this->refund_model->get_refund_order($from, $size, $cdn);
        $res['status'] = 200;
        $res['msg'] = 'OK';
        $res['data'] = $data;
        $this->util->send_json($res);
        return NULL;
    }

    /*
     *  get refund detail from tables
    */
    public function get_refund_detail()
    {
        $type = $this->util->post('type');
        if (NULL === $type)
        {
            $res['status'] = 400;
            $res['msg'] = "type can't be null";
            $this->util->send_json($res);
        }
        switch ($type)
        {
            case 1 :
                $this->util->authentication('6AB593A4-B54A-4122-92D6-F92777661160');
                break;
            case 2 :
                $this->util->authentication('D4FDAC81-5269-4EA0-94AC-6C5538124E94');
                break;
            case 3 :
                $this->util->authentication('FEF918F3-9C21-401A-B8E0-8FE9F33234EF');
                break;
            case 4 :
                $this->util->authentication('9F8F72B1-69C3-41C6-93B8-EF28D207A28F');
                break;
            case 5 :
                $this->util->authentication('F13EDBBA-0029-444B-955F-E13A9B0ED1CB');
                break;
            case 6 :
                $this->util->authentication('FEF918F3-9C21-401A-B8E0-8FE9F33234EF');
                break;
            default :
                $res['status'] = 400;
                $res['msg'] = "Invalid type:$type";
                $this->util->send_json($res);
        }

        $sn = $this->util->post('refund_id');
        if (NULL === $sn)
        {
            $res['status'] = 400;
            $res['msg'] = "refund_id can't be null";
            $this->util->send_json($res);
        }

        $data = $this->refundservice->get_refund_detail($sn);

        $res['status'] = 200;
        $res['msg'] = 'Success';
        $res['data'] = $data;
        $this->util->send_json($res);

    }

    //lock refund order by contract staff
    public function lock_refund_order()
    {
        $refund_id = $this->util->post('refund_id');
        $user = $this->util->get_user();
        if (NULL == $refund_id) {
            $res['status'] = 402;
            $res['msg'] = "refund_id is null";
            $this->util->send_json($res);
            return null;
        }
        $rest = $this->refund_model->check_refund_lock($refund_id, $user['id']);
        if ($rest['status'] == 200) {
            $res = $this->refund_model->lock_refund($refund_id, $user['id']);
        }
        else
        {
            $rest['status'] = $rest['status'] === 500 ? 200:$rest['status'];
            $res = $rest;
        }


        $this->util->send_json($res);
        return NULL;

    }

//unlock refund order by contract staff
    public function unlock_refund_order()
    {
        $refund_id = $this->util->post('refund_id');
        if (NULL == $refund_id) {
            $res['status'] = 2;
            $res['msg'] = "refund_id is null";
            $this->util->send_json($res);
            return null;
        }


        $res = $this->refund_model->lock_refund($refund_id, NULL);
        $this->util->send_json($res);
        return NULL;

    }

    //取消发货
    public function cancel_transfer()
    {
        $this->util->authentication('a2e0e0a1-9d5b-4cf9-87d1-5a4db7396eed');
        $sn = $this->util->post('sn');
        if (NULL == $sn) {
            $res['status'] = 2;
            $res['msg'] = "sn is null";
            $this->util->send_json($res);
            return null;
        }

        $yc_status = $this->refundservice->call_yuncang_cancel_order($sn);
        if ($yc_status[0]) {
            $res['status'] = 200;
            $res['msg'] = $yc_status[1];

        } else {
            $res['status'] = 503;
            $res['msg'] = $yc_status[1];
        }
        $this->util->send_json($res, $yc_status[2]);
        return NULL;

    }

    //modify address
    public function modifyExpressAd()
    {
        $this->util->authentication('43a8968b-25a0-4f03-8476-ee4ae064af41');
        $ad = $this->util->post('address');
        $orderid = $this->util->post('orderid');
        $region = $this->util->post('region');
        $mobile = $this->util->post('mobile');
        $name = $this->util->post('name');
        $p    = $this->util->post('ship_provinceid');
        $city_id = $this->util->post('ship_cityid');
        $region_id = $this->util->post('ship_regionid');

        if (NULL == $ad or NULL === $orderid) {
            $res['status'] = 402;
            $res['msg'] = "orderid or address or region is null";
            $this->util->send_json($res);
            return null;
        }

        $res = $this->refund_model->modifyAd($orderid, $ad, $region, $mobile, $name,$p,$city_id,$region_id);

        $this->util->send_json($res);
        return NULL;

    }

    //send order to yuncang
    public function sendOrderYc()
    {

        $sn = $this->util->post('sn');
        $type = $this->util->post('type');

        if (NULL === $sn or NULL === $type) {
            $res['status'] = 402;
            $res['msg'] = "sn or type is null";
            $this->util->send_json($res);
            return null;
        }
        switch ($type) {
            case 1:
                /*
                 * transfer
                 */
                $this->util->authentication('F582F14E-34B8-4457-9F10-F277E7CA2A9D');
                break;
            case 2:
                /*
                 * retransfer
                 */
                $this->util->authentication('af19f220-6310-42bb-9e8c-b883ee02d5dc');
                break;
            default:
                $res['status'] = 403;
                $res['msg'] = "type is invalid";
                $this->util->send_json($res);
                return null;
        }

        $ycorder = $this->refundservice->getOrder($sn);


        if ($ycorder) {
            $res_yc = $this->refundservice->sendYcOrder($ycorder);

            if (200 === $res_yc[0]) {
                $this->refund_model->change_es_yc_push_order_status($sn, 1);
            }

            $res['status'] = $res_yc[0];
            $res['msg'] = $res_yc[1];
            $res['data'] = $res_yc[2];

            /*
             * save to es_order_log
             */
            $user = $this->util->get_user();

            $this->refund_model->insert_es_order_log($sn, $res['data'], $user['id'], $user['name']);

        } else {
            $res['status'] = 4;
            $res['msg'] = "Can't find the sn:$sn";
        }

        $this->util->send_json($res);

    }

    /*
     * Get regions with p_region from  table es_regions
     */
    public function getRegionList()
    {
        $this->util->authentication('43a8968b-25a0-4f03-8476-ee4ae064af41');
        $p_region = $this->util->post('p_region');


        $region_res = $this->refund_model->query_regions($p_region);
        if ($region_res) {
            $res['status'] = 200;
            $res['msg'] = 'Query success!';
            $res['data'] = $region_res;
        } else {
            $res['status'] = 503;
            $res['msg'] = "Can't query the p_region_id:$p_region";
        }
        echo $this->util->send_json($res);
        return NULL;
    }

    /*
     * 客服发起申请售后流程
     */
    //receive refund request from app
//退款类型；1101：取消订单；1102：补发货；1103：部分退款；1104：整单退货
//申请退货
    public function csRefund()
    {
        $this->util->authentication('ad129652-7ed7-484e-9a2a-0c1b4eb33177');
        $res['msg'] = 'receive refund success!';
        $res['status'] = 200;

        $depid = $this->util->get_dep();
        $user = $this->util->get_user();
        $orderid = $this->util->post('orderid');
        $refund_type = $this->util->post('type');
        $comment = $this->util->post('comment');


        if (NULL === $orderid or NULL === $refund_type) {
            $res['status'] = 502;
            $res['msg'] = "orderid or type is null";
            $this->util->send_json($res);
            return null;
        }

        //check whether transfer completed from es_order table
        try {

            //query is there no finished refund order
            $count = $this->refund_model->validate_refund_order($orderid);
            if ($count !== 0) {
                $res['msg'] = "该订单已经在处理中或已经完成售后";
                $res['status'] = 503;

                $this->util->send_json($res);

                return NULL;
            }
            /*
             * get sn from redis
             */
            $sn = $this->refundservice->get_refund_sn();

            $oi = $this->refund_model->get_order_item($orderid);
            $memid = $oi['member_id'];
            $res = $this->refund_model->insert_refund_order($orderid, $comment, $user['id'], $depid, $sn, $memid);


            if ($res['status'] !== 200) {
                $this->util->send_json($res);
                return NULL;
            }
            $msg = $this->refundservice->get_order_status_name($orderid);
            $uuid = $res['uuid'];
            $data['id'] = $this->util->uuid();
            $data['refund_id'] = $uuid;
            $data['event_code'] = 1000;
            $data['event_name'] = $this->refund_model->get_refund_status($data['event_code']);
            $data['message'] = $msg;
            $data['operator_user'] = $user['name'];
            $data['operator_id'] = $user['id'];

            $this->refund_model->insert_yd_refund_log($data);

            $status =$this->refundservice->is_shiped($orderid);
            if($status)
            {
                /*
                 * change status to for review
                 */
                $this->refund_model->update_refund_status_direct($uuid,1201);

            }
            else
            {
                /*
                 * 取消订单
                 */
                $res = $this->refundservice->cancel_order($orderid,$uuid);
            }

        } catch (Exception $e) {
            echo 'Message: ' . $e->getMessage();
            $res['msg'] = 'Exception in database';
            $res['status'] = 502;
            $this->util->send_json($res);
        }
        $this->util->send_json($res);

    }

    /*
     * get refund order list
     */

    public function get_refund_orders()
    {
        $type = $this->util->post('type');
        switch ($type)
        {
            case 1:
                $this->util->authentication('6AB593A4-B54A-4122-92D6-F92777661160');
                break;
            case 2:
                $this->util->authentication('D4FDAC81-5269-4EA0-94AC-6C5538124E94');
                break;
            case 3:
                $this->util->authentication('FEF918F3-9C21-401A-B8E0-8FE9F33234EF');
                break;
            case 4:
                $this->util->authentication('9F8F72B1-69C3-41C6-93B8-EF28D207A28F');
                break;
            case 5:
                $this->util->authentication('A70CF186-0935-449E-9C43-598B6DCE75AA');
                break;
            default:
                $res['status'] = 400;
                $res['msg'] = "type is $type";
                $this->util->send_json($res);
        }


        $st = $this->util->post('start_time');
        $et = $this->util->post('end_time');
        $sn = $this->util->post('sn');
        $order_sn = $this->util->post('order_sn');
        $mobile = $this->util->post('mobile');
        $refund_status = $this->util->post('refund_status');
        $page = $this->util->post('page');
        $size = $this->util->post('size');
        if (NULL === $page OR NULL === $size) {
            $res['status'] = 400;
            $res['msg'] = "page and size can't be null";
            $this->util->send_json($res);
        }
        if ($page < 1 or $size < 1) {
            $res['status'] = 400;
            $res['msg'] = "page and size can't little than 1";
            $this->util->send_json($res);
        }

        if (NULL === $st && NULL === $et && NULL === $sn && NULL === $order_sn && NULL === $mobile && NULL === $refund_status) {
            $res['status'] = 400;
            $res['msg'] = "start_time,end_time,sn,order_sn,mobile,refund_status must have one";
            $this->util->send_json($res);
        }

        $from = ($page - 1) * $size;
        $user = $this->util->get_user();

        $data = $this->refund_model->get_refund_orders($from, $size, $st, $et, $sn, $order_sn, $mobile, $refund_status, $user['id']);
        $res['status'] = 200;
        $res['msg'] = 'sucess';
        $res['data'] = $data;
        $this->util->send_json($res);

    }

    public function app_get_refund_list()
    {

        $page = $this->util->post('page');
        $size = $this->util->post('size');
        $user = $this->util->get_user();
        $memid = $user['id'];
        if ( NULL === $page OR NULL === $size)
        {
            $res['status'] = 400;
            $res['msg'] = "page,size can't be null";
            $this->util->send_json($res);
        }
        if ($page < 1 OR $size < 1)
        {
            $res['status'] = 401;
            $res['msg'] = 'page,size must not be little than one';
            $this->util->send_json($res);
        }
        $from = ($page - 1) * $size;
        $data = $this->refund_model->get_yd_refund_list_memid($memid, $from, $size);
        $res['status'] = 200;
        $res['msg'] = 'sucess';
        $res['data'] = $data;
        $this->util->send_json($res);
    }

    public function app_get_refund_detail()
    {
        $refund_id = $this->util->post('refund_id');
        $data = $this->refund_model->get_yd_refund_detail_refundid($refund_id);
        $res['status'] = 200;
        $res['msg'] = 'sucess';
        $res['data'] = $data;
        $this->util->send_json($res);

    }


    public function reduce_supplier_wallet()
    {
        $orderid = $this->util->post('orderid');
        $sn = $this->util->post('sn');
        $amount = $this->util->post('amount');
        $this->refundservice->reduce_supplier_wallet($orderid, $sn, $amount);
        echo 'OK';
    }

    public function add_yd_refund_log()
    {
        $uuid = $this->util->post('refund_id');
        $message = $this->util->post('message');

        if (NULL === $uuid or NULL === $message) {
            $res['status'] = 400;
            $res['msg'] = "refund_id or message can't be null";
            $this->util->send_json($res);
        }
        $user = $this->util->get_user();
        $data['id'] = $this->util->uuid();
        $data['refund_id'] = $uuid;
        $data['event_code'] = 1001;
        $data['event_name'] = $this->refund_model->get_refund_status(1001);
        $data['message'] = $message;
        $data['operator_user'] = $user['name'];
        $data['operator_id'] = $user['id'];
        $this->refund_model->insert_yd_refund_log($data);
        $res['status'] = 200;
        $res['msg'] = 'sucess';
        $res['data'] = $data;
        $this->util->send_json($res);

    }

    public function cs_confirm_refund()
    {

        $refund_id = $this->util->post('refund_id');

        if (NULL === $refund_id) {
            $res['status'] = 400;
            $res['msg'] = "refund_id can't be null";
            $this->util->send_json($res);
        }
        $this->refund_model->update_refund_status_direct($refund_id, 1205);

        $user = $this->util->get_user();
        $data['id'] = $this->util->uuid();
        $data['refund_id'] = $refund_id;
        $data['event_code'] = 1004;
        $data['event_name'] = $this->refund_model->get_refund_status(1004);
        $data['message'] = 'confirm refund success';
        $data['operator_user'] = $user['name'];
        $data['operator_id'] = $user['id'];
        $this->refund_model->insert_yd_refund_log($data);

        $res['status'] =200;
        $res['msg'] = '确认退款';
        $res['data'] = $data;
        $this->util->send_json($res);
    }

    /*
     * manual sendYC_sendSaleReturn
     * bird 4
     */

    public function m_send_YC_sreturn()
    {
        $refund_id = $this->util->post('refund_id');
        if (NULL === $refund_id)
        {
            $res['status'] = 400;
            $res['msg'] = "refund_id can't be null";
            $this->util->send_json($res);
        }

        $refund_item = $this->refund_model->get_ydrefund_item($refund_id);
        $eno = $refund_item['delivery_no'];
        $status = $this->refundservice->sendYC_sendSaleReturn($refund_id,$eno);
        $res['status'] = 200;

        if($status)
        {
            $this->add_refund_log($refund_id,1204,'发送云仓销售退货成功');
            $res['msg'] = '发送云仓销售退货成功';
        }
        else
        {
            $this->add_refund_log($refund_id,1204,'发送云仓销售退货失败');
            $res['msg'] = '发送云仓销售退货失败';
        }

        $this->util->send_json($res);
    }
    /*
     * customer service review
     * bird 3
     */
    public function refund_review()
    {
        $type = $this->util->post('type');
        if ($type == 1)
        {
            $this->util->authentication('FBD920AF-36A4-40B3-B739-C7949193B40B');
        }

        if ($type == 2)
        {
            $this->util->authentication('61403151-5661-4998-A158-4396200CE798');
        }
        $refund_id = $this->util->post('refund_id');
        if (NULL === $refund_id)
        {
            $res['status'] = 400;
            $res['msg'] = "refund_id can't be null";
            $this->util->send_json($res);
        }

        $i_details = $this->util->post('details');
        $comment = $this->util->post('comment');
        $expressno = $this->util->post('expressno');
        $user = $this->util->get_user();

        /*
     * check out mayI_review
     */
        $res = $this->refundservice->mayI_review($refund_id, $user);
        $res['status'] = 200;
        if ($res['status'] == 200) {

            $res = $this->refundservice->refund_review($refund_id, $i_details, $comment, $user,$expressno);
        }

        $this->util->send_json($res);
    }

    public function get_holdup_orders()
    {
        $this->util->authentication('A70CF186-0935-449E-9C43-598B6DCE75AA');

        $page = $this->util->post('page');
        $size = $this->util->post('size');
        if (NULL === $page OR NULL === $size) {
            $res['status'] = 400;
            $res['msg'] = "page and size can't be null";
            $this->util->send_json($res);
        }
        if ($page < 1 or $size < 1) {
            $res['status'] = 400;
            $res['msg'] = "page and size can't little than 1";
            $this->util->send_json($res);
        }


        $from = ($page - 1) * $size;
        $res['data'] = $this->refund_model->get_holdup_orders($from, $size);
        $res['status'] = 200;
        $res['msg'] = 'success';
        $this->util->send_json($res);
    }

    public function get_v_be_refund_orders()
    {
        $this->util->authentication('F13EDBBA-0029-444B-955F-E13A9B0ED1CB');

        $page = $this->util->post('page');
        $size = $this->util->post('size');
        if (NULL === $page OR NULL === $size) {
            $res['status'] = 400;
            $res['msg'] = "page and size can't be null";
            $this->util->send_json($res);
        }
        if ($page < 1 or $size < 1) {
            $res['status'] = 400;
            $res['msg'] = "page and size can't little than 1";
            $this->util->send_json($res);
        }


        $from = ($page - 1) * $size;
        $res['data'] = $this->refund_model->get_v_be_refund_orders($from, $size);
        $res['status'] = 200;
        $res['msg'] = 'success';
        $this->util->send_json($res);
    }



    /*
     * 1101
     * 客服拦截订单:type:1,success,2,fail
     * bird 6
     */

    public function cs_holdup_order()
    {
        $type = $this->util->post('type');
        $refund_id = $this->util->post('refund_id');
        if (NULL === $refund_id OR NULL === $type) {
            $res['status'] = 400;
            $res['msg'] = "refund_id and type can't be null";
            $this->util->send_json($res);
        }
        $user = $this->util->get_user();
        if ($type == 1) {
            $this->util->authentication('43C32041-F8AB-4A05-84FE-170E250E0027');
            $res = $this->refundservice->suc_holdup($refund_id, $user);
        }

        if ($type == 2) {
            $this->util->authentication('09AEEF9E-B08F-45C7-96B9-3EBCD9C515AA');
            $res = $this->refundservice->fail_holdup($refund_id, $user);
        }

        $res['status'] = 200;

        $this->util->send_json($res);

    }


    /*
     * customer service confirm payment
     * 客服确认退款
     * bird 5
     */
    public function cs_confirm_payment()
    {
        $type = $this->util->post('type');
        if ($type == 1) {
            $this->util->authentication('9C650523-E09F-403C-AFFC-6D1B57613BB7');
        }

        if ($type == 2) {
            $this->util->authentication('695E66F3-E2F3-4E83-B237-850E2049AD48');
        }
        $refund_id = $this->util->post('refund_id');
        if (NULL === $refund_id) {
            $res['status'] = 400;
            $res['msg'] = "refund_id can't be null";
            $this->util->send_json($res);
        }
        $details = $this->util->post('details');
        if (NULL === $details) {
            $res['status'] = 400;
            $res['msg'] = "details can't be null";
            $this->util->send_json($res);
        }
        $comment = $this->util->post('comment');
        $user = $this->util->get_user();

        /*
         * 事务
         */
        $this->db->trans_start();
/*
 * unlock
 */

        $this->refund_model->lock_refund($refund_id,NULL);
        /*
         * add log in yd_refund_log
         */
        $data['id'] = $this->util->uuid();
        $data['refund_id'] = $refund_id;
        $data['event_code'] = 1004;
        $data['event_name'] = '确认退款';
        $data['message'] = $comment;
        $data['operator_user'] = $user['name'];
        $data['operator_id'] = $user['id'];
        $this->refund_model->insert_yd_refund_log($data);


        $amount_arr = array_column($details,'refund_amount');
        $price = array_sum($amount_arr);

        $this->refund_model->update_refund_amount_id($refund_id,$price);


        /*
         * update to refund_details with input details
         */
        $this->refund_model->update_refund_details($details);

        $this->db->trans_complete();

        /*
         * set refund status to 1205 and save refund log
         */

        $this->refundservice->add_refund_log($refund_id,1005,$comment);

        $this->refund_model->update_refund_status_direct($refund_id,1205);

        $res['status'] = 200;
        $res['msg'] = 'success approve refund';

        $this->util->send_json($res);


    }




    /*
     * 等待退库列表
     */
    public function returned_goods()
    {
        $this->util->authentication('FEF918F3-9C21-401A-B8E0-8FE9F33234EF');

        $page = $this->util->post('page');
        $size = $this->util->post('size');
        if (NULL === $page OR NULL === $size) {
            $res['status'] = 400;
            $res['msg'] = "page and size can't be null";
            $this->util->send_json($res);
        }
        if ($page < 1 or $size < 1) {
            $res['status'] = 400;
            $res['msg'] = "page and size can't little than 1";
            $this->util->send_json($res);
        }

        $from = ($page-1)*$size;

        $res['data']= $this->refund_model->get_returned_goods($from,$size);
        $res['status'] = 200;
        $res['msg'] = '成功得到退库列表';
        $this->util->send_json($res);
    }

/*
 * 财务给客户转账退款
 * bird 8
 */

    public function cs_refund_money()
    {
        $this->util->authentication('CBD97A64-F7E9-42D3-9B7E-A3BDBBB95215');
        $refund_id = $this->util->post('refund_id');
        if (NULL === $refund_id )
        {
            $res['status'] = 400;
            $res['msg'] = "refund_id can't be null";
            $this->util->send_json($res);
        }

        $user = $this->util->get_user();
        $status = $this->refundservice->call_payment($refund_id,TRUE);
        $data['id'] = $this->util->uuid();
        $data['refund_id'] = $refund_id;
        $data['event_code'] = 1005;
        $data['event_name'] = $this->refund_model->get_refund_status($data['event_code']);

        $data['operator_user'] = $user['name'];
        $data['operator_id'] = $user['id'];


        if($status)
        {
            $data['message'] = "打款成功";
            $res['msg'] = $data['message'];


            $this->refundservice->reduceBounsApi($refund_id);
            $this->refund_model->update_refund_status_direct($refund_id,1206);

            $data['id'] = $this->util->uuid();
            $data['message'] = "扣减返利成功";
            $res['status'] = 200;

        }
        else
        {
            $data['message'] = "打款失败";
            $res['status'] = 500;
            $res['msg'] = $data['message'];
        }

        $this->refund_model->insert_yd_refund_log($data);
        $this->util->send_json($res);
    }




    /*
     * 客服拒绝退款申请
     */
    public function cs_refuse_refund()
    {
        $this->util->authentication('1FA96B64-2F0C-4DFA-A042-95CDA1D316D2');
        $rid = $this->util->post('refund_id');
        if(NULL === $rid)
        {
            $res['status'] = 400;
            $res['msg'] = "refund_id can't be null";
            $this->util->send_json($res);
        }

        $res = $this->refundservice->refuse_refund($rid);
        $this->util->send_json($res);

    }

    /*
     * finance refuse refund
     * bird 9
     */
    public function fr_refuse_refund()
    {
        $refund_id = $this->util->post('refund_id');
        if (NULL === $refund_id )
        {
            $res['status'] = 400;
            $res['msg'] = "refund_id can't be null";
            $this->util->send_json($res);
        }

        $this->refund_model->update_refund_status_direct($refund_id,1007);
        $this->refundservice->add_refund_log($refund_id,1005,'财务退款，驳回');
        $res['status'] = 200;
        $res['msg'] = '财务退款，驳回退款申请';
        $this->util->send_json($res);
    }

    /*
     * 确认退货
     */
    public function confirm_return_goods()
    {

        $this->util->authentication('55143ABB-ED7C-4A3D-93C2-4B0FDAB6F85E');
        $refund_id = $this->util->post('refund_id');
        if (NULL === $refund_id) {
            $res['status'] = 400;
            $res['msg'] = "refund_id can't be null";
            $this->util->send_json($res);
        }
        $details = $this->util->post('details');
        if (NULL === $details) {
            $res['status'] = 400;
            $res['msg'] = "details can't be null";
            $this->util->send_json($res);
        }
        $comment = $this->util->post('comment');
        $user = $this->util->get_user();
        $data['id'] = $this->util->uuid();
        $data['refund_id'] = $refund_id;
        $data['event_code'] = 1008;
        $data['event_name'] = $this->refund_model->get_refund_status($data['event_code']);

        $data['operator_user'] = $user['name'];
        $data['operator_id'] = $user['id'];
        $data['message'] = $comment;

        try{
            $this->db->trans_strict(FALSE);
            $this->db->trans_start();

            $this->refund_model->insert_yd_refund_log($data);
/*
 * 去掉回加库存及供应商货款操作，该操作在云仓回掉中实现，申志强实现
 */
//            $status = $this->refundservice->update_refund_detail_store($details);
//
//
//            $this->refundservice->update_es_product_store($details);
////            $this->refundservice->sendYC_sendSaleReturn($refund_id);
//
//            $this->refund_model->update_is_stored($refund_id);
//            $this->refundservice->update_suplier_wallet($refund_id,$details);

            $this->db->trans_complete();
        }
        catch (Exception $e)
        {

            $res['status'] = 500;
            $res['msg'] = '数据库异常,确认退货入库失败';
            $res['data'] = $e->getMessage();
            $this->util->send_json($res);
        }

        $res['status'] = 200;
        $res['msg'] =  '确认退货入库成功';
        $res['data'] = '确认退货入库成功';
        $this->util->send_json($res);

    }

    public function get_negative_comment()
    {
        $this->util->authentication('D1B8EEAF-B0C8-44F5-8FE2-CD6CED981D2B');
        $page = $this->util->post('page');
        $size = $this->util->post('size');
        $reply_status = $this->util->post('reply_status');
        $content = $this->util->post('content');

        if (NULL === $page OR NULL === $size) {
            $res['status'] = 400;
            $res['msg'] = "page and size can't be null";
            $this->util->send_json($res);
        }
        if ($page < 1 or $size < 1) {
            $res['status'] = 400;
            $res['msg'] = "page and size can't little than 1";
            $this->util->send_json($res);
        }

        $from = ($page-1)*$size;
        $data = $this->refund_model->get_negative_cmt($from,$size,$reply_status,$content);
        $res['status'] = 200;
        $res['msg'] = 'OK';
        $res['data'] = $data;
        $this->util->send_json($res);
    }

    public function add_cs_reply()
    {
        $this->util->authentication('7EB5AE6F-FDB6-485A-9A75-57F00E57E10C');
        $reply = $this->util->post('reply');
        $cmtid = $this->util->post('commentid');
        $this->refund_model->add_cs_reply($cmtid,$reply);
        $res['status'] = 200;
        $res['msg'] = '成功添加客服回复';
        $this->util->send_json($res);
    }

    public function get_comment_img()
    {
        $this->util->authentication('D1B8EEAF-B0C8-44F5-8FE2-CD6CED981D2B');
        $commentid = $this->util->post('comment_id');

        if (NULL === $commentid) {
            $res['status'] = 400;
            $res['msg'] = "comment id can't be null";
            $this->util->send_json($res);
        }
        $res['data'] = $this->refund_model->get_comment_img($commentid);
        $res['status'] = 200;
        $res['msg'] = '成功';
        $this->util->send_json($res);
    }

    public function test()
    {

        $this->util->mongolog('for new migration test','refund','info');
    }
}


