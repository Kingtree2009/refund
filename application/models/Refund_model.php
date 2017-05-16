<?php

/**
 * Created by PhpStorm.
 * User: kt
 * Date: 2017/3/13
 * Time: 下午9:00
 */
class Refund_model extends CI_Model {

    public function __construct()
    {
        $this->load->database();
        $this->load->helper('date');
        $this->load->library('util');
    }

    public function get_order_items_Worderid($orderid)
    {
        $this->db->where('order_id',$orderid);
        $this->db->select('sn,num');
        $res = $this->db->get('es_order_items')->result_array();
        return $res;

    }

//退款类型；1101：取消订单；1102：补发货；1103：部分退款；1104：整单退货
    //退款状态,1201:申请退款，1202：待审核，1203：通过审核，1204：待验货，1205：待客服确认退款，
//1206：完成退款，1207：拒绝退款，1208：退款失败（支付宝，微信打款失败），1209：待补发审核，1210：确认补发，1211补发完成，1212：补发失败，1213：拒绝补发




    public function insert_refund_order($orderid,$comment,$uid,$dep_id,$sn,$memid)
    {
        $uuid = $this->util->uuid();

        $data = array(
            'id'=>$uuid,
            'sn'=>$sn,
            'order_id' => $orderid,
            'refund_status'=>1201,
            'comment'=>$comment,
            'creator_user_id'=>$uid,
            'creator_dept_id'=>$dep_id,
            'mem_id'=>$memid

        );

        $this->db->insert('yd_refund', $data);
        $num = $this->db->affected_rows();
        if($num ===1)
        {
            $res['status'] = 200;
            $res['msg'] = 'OK';
            $res['uuid'] = $uuid;
        }
        else
        {
            $res['status'] = 503;
            $res['msg'] ='Insert error';
        }
        return $res;

    }


    public function validate_refund_order($orderid)
    {
        $query_sql = "select order_id,refund_status from yd_refund where order_id = {$orderid} 
         and refund_status not in (1207)";
        $query = $this->db->query($query_sql);
        return $query->num_rows();
    }

    public function whether_transfered($orderid,$type)
    {

        $this->db->select("order_id,status as order_status,create_time");
        $this->db->where("order_id",$orderid);

        $row = $this->db->get("es_order",1)->row_array();

        if(empty($row))
        {
            return ['status'=>503,'msg'=>"不存在此订单"];
        }
        $status = $row['order_status'];
        $ct = $row['create_time'];

        $now = now();

        $time_diff = $this->util->timediff($ct,$now);
        $days = $time_diff['day'];


        if ($days > REFOUND_TIME_LIMIT)
        {
            return ['status'=>502,'msg'=>'已经超过退款期限'];

        }


        if($type ==1101 && $status != 2)
        {

            //type is cancel order
            $msg = "已经发货，不允许取消订单。";
            return ['status'=>502,'msg'=>$msg];

        }

        return ['status'=>200,'msg'=>'OK'];

    }



    public function update_refund_status_refundid($refundid,$current_status,$status)
    {
        $this->db->set('refund_status', $status);
        $this->db->where('id', $refundid);
        $this->db->where('refund_status', $current_status);
        $this->db->update('yd_refund'); // gives UPDATE `mytable` SET `field` = 'field+1' WHERE `id` = 2
        $count = $this->db->affected_rows();
        if($count === 1)
        {
            $res['status'] =200;
            $res['msg'] = 'OK';
        }
        else
        {
            $res['status'] =502;
            $res['msg'] = "更新数据库失败 refund_id :$refundid";
            $this->util->save_log('error',$res['msg']);
        }
        return $res;
    }

    public function add_comment($uuid,$comment) {
        $this->db->set('comment',$comment);
        $this->db->set('refund_status',1202);
        $this->db->where('uuid',$uuid);
        $this->db->where('refund_status',1204);
        $this->db->update('yd_refund');
        $num = $this->db->affected_rows();
        if($num === 1)
        {
            $res['status'] =200;
            $res['msg'] = 'update db OK';
        }
        else
        {
            $res['status'] =502;
            $res['msg'] = "更新数据库失败 uuid :$uuid";
            $this->util->save_log('error',$res['msg']);
        }
        return $res;
    }

    public function save_cs_comment($uuid,$comment,$recorder)
    {
        $data = array(
            'uuid' => $uuid,
            'comment' => $comment,
            'reger_id' => $recorder
        );


        $this->db->insert('refund_customer_service_log', $data);
        return $this->db->affected_rows();
    }

    public function get_ordersn($orderid)
    {
        $querysql = "select sn from es_order where order_id ={$orderid} Limit 1";
        $query = $this->db->query($querysql);
        $row = $query->row_array();
        $order_sn = $row['sn'];
        return $order_sn;
    }
    public function get_order_condition($condition,$from = 0,$size =10)
    {

        $querysql = "select * from  ".V_ORDER;
        $countsql = "select count(*) as items from ".V_ORDER;
        if($condition && $condition != "=''")
        {
            $querysql .= " where ".$condition;
            $countsql .= " where ".$condition;
            $querysql .= " order by order_id desc LIMIT {$from},{$size}";
        }
        else
        {
            $querysql .= " order by order_id desc";
            $querysql .= " LIMIT {$from},{$size}";
        }

        $row = $this->db->query($countsql)->row_array();

        $query = $this->db->query($querysql);
        $rows = $query->result_array();
        $data['total'] = $row['items'];
        $data['items'] = $rows;
        return $data;
    }


    public function insert_es_order_log($orderid,$msg,$op_id,$name)
    {
        $time = now();

        $data = array(
            'message'=>$msg,
            'order_id' => $orderid,
            'op_time' => $time,
            'op_id'=>$op_id,
            'op_name'=>$name

        );

        $this->db->insert('es_order_log', $data);
        $num = $this->db->affected_rows();
        if($num ===1)
        {
            $res['status'] = 200;
            $res['msg'] = 'OK';
        }
        else
        {
            $res['status'] = 503;
            $res['msg'] ='Insert error';
        }
        return $res;

    }

    public function get_refund_order($from,$size,$cdn)
    {

        if($cdn&&$cdn!="=''")
        {
            $querysql = "select   * from yd_refund  where  {$cdn} order by order_id DESC LIMIT {$from},{$size}";
        }
        else
        {
            $querysql = "select  * from yd_refund  ORDER BY order_id DESC LIMIT {$from},{$size}";
        }

        $rows = $this->db->query($querysql)->result_array();

        return $rows;
    }

    public function get_refund_detail($orderid)
    {
        $querysql = "select * from v_order_detail where order_id= '{$orderid}'";
        $rows = $this->db->query($querysql)->result_array();
        return $rows;
    }

    public function check_refund_lock($refund_id,$uid)
    {
        $this->db->select('lock_user_id');
        $this->db->where('id',$refund_id);
        $res = $this->db->get('yd_refund',1)->row_array();
        $luid = $res['lock_user_id'];
        if($luid === NULL)
        {
            $rest['status'] =200;
        }
        else
        {
            if($luid == $uid)
            {
                $rest['status'] = 500;
                $rest['msg'] = '锁定工单成功！请尽快处理……';
            }
            else
            {
                $rest['status'] = 501;
                $rest['msg'] = '此工单已被其他人锁定！';
            }
        }
        return $rest;
    }

    public function lock_refund($refund_id,$userid)
    {
        $this->db->set('lock_user_id',$userid);
        $this->db->where('id',$refund_id);
        $this->db->update('yd_refund');
        $num =$this->db->affected_rows();

        if($num === 1 )
        {
            $res['status'] =200;
            if($userid === NULL)
            {
                $res['msg'] = '解锁工单成功！';
            }
            else
            {
                $res['msg'] = '锁定工单成功！请尽快处理……';
            }

        }
        else
        {
            $res['status'] =500;

            if($userid === NULL)
            {
                $res['msg'] = "该工单已被解锁！";
            }
            else
            {
                $res['msg'] = '此工单已被其他人锁定！';
            }

            $this->util->save_log('info',$res['msg']);
        }
        return $res;
    }

    public function modifyAd($orderid,$ad,$region,$mobile,$name,$p,$city_id,$region_id)
    {
        $this->db->set('ship_addr',$ad);
        $this->db->set('shipping_area',$region);
        $this->db->set('ship_mobile',$mobile);
        $this->db->set('ship_name',$name);
        $this->db->set('ship_provinceid',$p);
        $this->db->set('ship_cityid',$city_id);
        $this->db->set('ship_regionid',$region_id);
        $this->db->where('order_id',$orderid);
        $this->db->update('es_order');
        $count = $this->db->affected_rows();
        if($count === 1)
        {
            $res['status'] =200;
            $res['msg'] = 'OK';
        }
        else
        {
            $res['status'] =502;
            $res['msg'] = "更新数据库失败 orderid :$orderid";
            $this->util->save_log('error',$res['msg']);
        }
        return $res;
    }

    public function getYCOrder($sn)
    {
        $querysql = "select order_id,create_time,ship_name,ship_mobile,shipping_area,ship_addr,shipping_type from es_order where sn='{$sn}' LIMIT 1";
        $query_result = $this->db->query($querysql)->row_array();
        if(!$query_result)
        {
            return NULL;
        }
        $address = $query_result['shipping_area'];
        $address_list = explode('-',$address);
        $ordertime =  $query_result['create_time'];
        $data_format = "Y-m-d H:i:s";
        $date_str = date($data_format,$ordertime);

        $orderid = $query_result['order_id'];
        $querysql = "select sn,name,num from es_order_items where order_id ={$orderid}";
        $items_query_result = $this->db->query($querysql)->result_array();
        if(!$items_query_result)
        {
            return NULL;
        }
        $ycitems = [];

        foreach ($items_query_result as $item)
        {
            $ycitem['commodityName'] = $item['name'];
            $ycitem['commodityBarcode'] = $item['sn'];
            $ycitem['num'] = $item['num'];
            $ycitems[] =$ycitem;
        }

        $yc_order['code'] = $sn;
        $yc_order['orderDate'] =  $date_str;
        $yc_order['outCode'] = '';
        $yc_order['receiverName'] = $query_result['ship_name'];
        $yc_order['buyernick'] = '';
        $yc_order['mobile'] = $query_result['ship_mobile'];
        $yc_order['warehouse'] = "丹阳云仓";
        $yc_order['shop'] = "平台自营";
        $yc_order['shopid'] = '1';
        $yc_order['buyerMessage'] = '';
        $yc_order['remark'] = '';
        $yc_order['systemRemark'] = '';
        $yc_order['telPhone'] = '';
        $yc_order['province'] = $address_list[0];
        $yc_order['city'] = $address_list[1];
        $yc_order['district'] = $address_list[2];
        $yc_order['receiverDistrict'] = '';
        $yc_order['receiverZip'] = '100000';
        $yc_order['receiverAddress'] = $query_result['ship_addr'];
        $yc_order['receiverEmail'] = '';
        $yc_order['expressAgencyFee'] = '';

        $yc_order['isCashOnDelivery'] = '0';
        $yc_order['bak1'] = '';
        $yc_order['bak2'] = '';
        $yc_order['bak3'] = '';
        $yc_order['bak4'] = '';
        $yc_order['bak5'] = '';
        $yc_order['bak6'] = '';
        $yc_order['bak7'] = '';
        $yc_order['bak8'] = '';


        $yc_order['item'] = $ycitems;
        $querysql = "select code from es_logi_company where name = '{$query_result['shipping_type']}' LIMIT 1";
        $result = $this->db->query($querysql)->row_array();
        if(empty($result))
        {
            $yc_order['expressName'] = '';
        }
        else
        {
            $yc_order['expressName'] = $result['code'];
        }



        $res['OrderList'] = [$yc_order];
        return $res;
    }

    public function query_regions($p_region)
    {
        if($p_region)
        {
            $query_sql = "select id,name  from yd_region where parent_id='{$p_region}' and is_invalid = 0";

        }
        else
        {
            $query_sql = "select id,name  from yd_region where parent_id is NULL and is_invalid = 0";

        }
        $query_res = $this->db->query($query_sql)->result_array();

        return $query_res;
    }

    public function get_order_detail($orderid)

    {

        $querysql = "select * from v_order_info where order_id= {$orderid} LIMIT 1";
        $queryres['info'] = $this->db->query($querysql)->row_array();

        if(empty($queryres['info']))
        {

            return [];
        }
        $querysql = "select * from v_order_items where order_id ={$orderid}";
        $itemres = $this->db->query($querysql)->result_array();

        $queryres['items'] = $itemres;

        $querysql = "select * from v_order_logs where order_id = {$orderid} order by op_time";
        $logres = $this->db->query($querysql)->result_array();

        $queryres['logs'] = $logres;

        return $queryres;

    }

    public function get_refund_orders($from,$size,$st,$et,$sn,$order_sn,$mobile,$refund_status,$userid)
    {
        $this->db->start_cache();
        if($st)
        {

            $php_st = nice_date($st);
            $datestring = '%Y%m%d%H%i%s';
            $mysql_st = mdate($datestring, $php_st);
            $this->db->where('created_time >',$mysql_st);
        }


        if($et)
        {

            $php_et = nice_date($et);
            $datestring = '%Y%m%d%H%i%s';
            $mysql_et = mdate($datestring,$php_et);
            $this->db->where('created_time <',$mysql_et);
        }

        if($sn)
        {
            $this->db->where('sn',$sn);
        }

        if($order_sn)
        {
            $this->db->where('order_sn',$order_sn);
        }
        if($mobile)
        {
            $this->db->where('mobile',$mobile);
        }
        if($refund_status)
        {
            $this->db->where('refund_status',$refund_status);
            if(1202 == $refund_status OR 1204 == $refund_status)
            {
                $this->db->group_start();

                $this->db->where("lock_user_id is NULL",NULL);
                $this->db->or_where('lock_user_id',$userid);
                $this->db->group_end();

            }


        }


        $this->db->order_by('created_time','DESC');
        $this->db->from('v_refund');
        $this->db->stop_cache();
        $num = $this->db->count_all_results();


        $this->db->limit($size,$from);


        $query_res = $this->db->get()->result_array();

        $res =['total'=>$num,'items'=>$query_res];

        return $res;
    }

    public function get_yd_refund_detail_with_rid($sn)
    {

        $this->db->where('refund_id',$sn);
        $this->db->order_by('product_code','ASC');
        $res = $this->db->get('yd_refund_detail')->result_array();

        return $res;
    }

    public function get_yd_refund_log_with_rid($sn)
    {
        $this->db->where('refund_id',$sn);
        $this->db->order_by('created_time','ASC');
        $res = $this->db->get('yd_refund_log')->result_array();
        return $res;
    }

    public function get_yd_refund_oid_with_rid($sn)
    {
        $this->db->select('order_id');
        $this->db->where('id',$sn);
        $res = $this->db->get('yd_refund',1)->row_array();
        return $res['order_id'];

    }

    public function v_get_vorderinfo_orderid($orderid)
    {
        $this->db->where('order_id',$orderid);
        $res = $this->db->get('v_order_info',1)->row_array();
        return $res;
    }

    public function v_get_vorderlog_orderid($orderid)
    {
        $this->db->where('order_id',$orderid);
        $this->db->order_by('op_time','ASC');
        $res = $this->db->get('v_order_logs')->result_array();
        return $res;

    }

    public function v_get_vorderitems_orderid($orderid)
    {
        $this->db->where('order_id',$orderid);
        $res = $this->db->get('v_order_items')->result_array();
        return $res;
    }

    public function change_es_yc_push_order_status($ycorder,$status)
    {
        $data = array(
            'push_status' => $status
        );

        $this->db->where('order_code', $ycorder);
        $this->db->update('es_yc_push_order', $data);
    }


    public function insert_yd_refund_log($data)
    {
        $this->db->set($data);
        $this->db->insert('yd_refund_log');
        $row = $this->db->affected_rows();
        return $row;
    }

    public function get_refund_status($code)
    {
        $this->db->where('status_id',$code);
        $res = $this->db->get('yd_refund_dic',1)->row_array();
        return $res['status_name'];
    }

    public function get_yd_refund_list_memid($uid,$from,$size)
    {
        $this->db->where('mem_id',$uid);
        $this->db->select("y.id,e.sn,y.order_id,created_time,y.refund_amount as order_amount,e.num,d.status_name");
        $this->db->join("(
 SELECT
  e.order_id ,
  e.sn ,
  sum(i.num) AS num
 FROM
  es_order e
 JOIN es_order_items i ON i.order_id = e.order_id
 GROUP BY 
  e.order_id
) e ",'y.order_id = e.order_id','left');

        $this->db->join('yd_refund_dic as d','d.status_id = y.refund_status','left');
        $this->db->order_by('y.created_time','DESC');
        $res = $this->db->get('yd_refund as y',$size,$from)->result_array();

        if($res)
        {
//            $res = array_column($res,NULL,'id');
            $orderid_arr = array_column($res,'order_id');
            $this->db->select('order_id,small');
            $this->db->join('es_goods as g','g.goods_id = i.goods_id');
            $this->db->where_in('i.order_id',$orderid_arr);
            $this->db->order_by('i.order_id','DESC');
            $items = $this->db->get('es_order_items as i')->result_array();
            /*
             * reformat orderid to small picture
             */
            foreach($items as $item)
            {
                $key = $item['order_id'];
                $v['order_id'] = $key;
                $v['small'] = PIC_URL.$item['small'];
                $tmp[$key][] = $v;
            }

            foreach ($res as $k=>$item)
            {
                $key = $item['order_id'];

                $res[$k]['items'] = $tmp[$key];
            }
            $res = array_values($res);

        }
        return $res;
    }

    public function get_memid_es_order_orderid($orderid)
    {
        $this->db->select('member_id');
        $this->db->where('order_id',$orderid);
        $res = $this->db->get('es_order',1)->row_array();
        return $res['member_id'];
    }

    public function get_yd_refund_detail_refundid($refund_id)
    {
        $this->db->select('y.order_id,comment,created_time as refund_time,create_time as order_time ,y.refund_amount as price,e.sn,d.status_name');
        $this->db->where('y.id',$refund_id);
        $this->db->join('es_order as e', 'e.order_id = y.order_id');
        $this->db->join('es_order_items as i','i.order_id = y.order_id');
        $this->db->join('yd_refund_dic as d','d.status_id = y.refund_status');
        $res = $this->db->get('yd_refund as y',1)->row_array();
        if($res)
        {

            $datestring = '%Y-%m-%d %H:%i:%s';
            $res['order_time'] = mdate($datestring, $res['order_time']);
            $this->db->select('i.num,g.small,g.name');
            $this->db->where('order_id',$res['order_id']);
            $this->db->join('es_goods as g','g.goods_id = i.goods_id');
            $items = $this->db->get('es_order_items as i')->result_array();
            if($items)
            {
                foreach($items as $key=>$item)
                {
                    $items[$key]['image'] = PIC_URL.$item['small'];
                }

            }
            $res['items'] = $items;

        }
        return $res;
    }

    public function reduce_supplier_wallet_sell($orderid,$sn,$amount)
    {
        $this->db->where('ORDER_ID',$orderid);
        $this->db->where('STATUS',0);
        $this->db->where('SN',$sn);
        $res = $this->db->get('yd_mkt_supplier_wallet_sell',1)->row_array();
        if($res)
        {
            if($amount > $res['AMOUNT'])
            {
                return [];
            }
            $res['MONEY'] = 0 - $amount*$res['PRICE'];
            $res['STATUS'] = 1;
            $res['AMOUNT'] = 0 - $amount;
            $datestring = '%Y-%m-%d %H:%i:%s';
            $now = now();
            $time = mdate($datestring, $now);
            $res['CREATE_TIME'] = $time;
            unset($res['ID']);
            $this->db->insert('yd_mkt_supplier_wallet_sell', $res);
        }
        return $res;
    }

    public function reduce_supplier_wallet($sid,$money)
    {
        $this->db->where('SUPPLIER_ID',$sid);
        $this->db->set('BALANCE',"BALANCE+$money",FALSE);
        $this->db->update('yd_mkt_supplier_wallet');

    }



    public function get_refund_amount_refund_id($refund_id)
    {
        $this->db->where('id',$refund_id);
        $this->db->select('es_order.sn,refund_amount,order_amount,payment_id');
        $this->db->join('es_order','es_order.order_id = yd_refund.order_id');
        $res = $this->db->get('yd_refund',1)->row_array();
        return $res;
    }


    public function update_refund_details($details)
    {
        $this->db->update_batch('yd_refund_detail',$details,'id');
    }

    public function insert_refund_details($details)
    {
        $this->db->insert_batch('yd_refund_detail',$details);
    }

    public function update_refund_amount_id($refund_id,$price)
    {
        $this->db->where('id',$refund_id);
        $this->db->set('refund_amount',$price);
        $this->db->update('yd_refund');
    }

    public function get_holdup_orders($from,$size)
    {
        $total = $this->db->count_all('v_be_holdup');
        $this->db->order_by('created_time','ASC');
        $data = $this->db->get('v_be_holdup',$size,$from)->result_array();
        $res['total'] = $total;
        $res['items'] = $data;
        return $res;
    }

    public function  get_v_be_refund_orders($from,$size)
    {
        $total = $this->db->count_all('v_be_refund');
        $this->db->order_by('created_time','ASC');
        $data = $this->db->get('v_be_refund',$size,$from)->result_array();
        $res['total'] = $total;
        $res['items'] = $data;
        return $res;
    }

    public function get_lock_uid($refund_id)
    {
        $this->db->select('lock_user_id');
        $this->db->where('id',$refund_id);
        $res = $this->db->get('yd_refund',1)->row_array();
        $uid = $res['lock_user_id'];
        return $uid;
    }

    public function get_returned_goods($from,$size)
    {
        $res['total'] = $this->db->count_all('v_be_return');
        $this->db->order_by('created_time','ASC');
        $res['items'] = $this->db->get('v_be_return',$size,$from)->result_array();
        return $res;
    }
	
	/*
	 * Get the order items from member_wallet_bonus_item;
	 */
	 public function get_list_bonus_item($oid,$sn_list)
	 {
	 	$this->db->where('ORDER_ID',$oid);
	 	$this->db->where('STATUS',0);
		$this->db->where_in('SN',$sn_list);
		$res = $this->db->get('yd_mkt_member_wallet_bonus_item')->result_array();
		return $res;
		
	 }
	 /*
	  * batch insert refund bonus item in bonus items table
	  */
	 public function batch_insert_bonus_item($res)
     {

         $this->db->insert_batch('yd_mkt_member_wallet_bonus_item',$res);

     }

     public function get_member_order_refund_money($order_id,$member_list)
     {
         $this->db->select('ORDER_ID,MEMBER_ID,sum(MONEY) as MONEY');
         $this->db->where('ORDER_ID',$order_id);
         $this->db->where('STATUS',1);
         $this->db->where_in('MEMBER_ID',$member_list);
         $this->db->group_by('MEMBER_ID');
         $res = $this->db->get('yd_mkt_member_wallet_bonus_item')->result_array();

         return $res;
     }

     public function update_bonusid_bonusitem($bonusid,$orderid,$memid,$status)
     {
         $this->db->set('BONUS_ID',$bonusid);
         $this->db->where('ORDER_ID',$orderid);
         $this->db->where('MEMBER_ID',$memid);
         $this->db->where('STATUS',$status);
         $this->db->update('yd_mkt_member_wallet_bonus_item');
     }

     public function insert_mem_order_bonus($res)
     {
         foreach ($res as $key=>$value)
         {
             $res[$key]['CREATE_TIME'] = $this->util->get_mysql_date();
             $res[$key]['EXPIRED'] = 0;
             $res[$key]['MODAL_ID'] = 1;
         }

         $this->db->insert_batch('yd_mkt_member_wallet_bonus',$res);
         $time_list = array_column($res,'CREATE_TIME');
         $order_list = array_column($res,'ORDER_ID');
         $member_list = array_column($res,'MEMBER_ID');
         /*
          * get bonus id in wallet_bonus
          */
         $this->db->where_in('CREATE_TIME',$time_list);
         $this->db->where_in('ORDER_ID',$order_list);
         $this->db->where_in('MEMBER_ID',$member_list);
         $res = $this->db->get('yd_mkt_member_wallet_bonus')->result_array();
         return $res;

     }

     public function get_balance($member_list)
     {
         $this->db->where_in('MEMBER_ID',$member_list);
         $res = $this->db->get('yd_mkt_member_wallet')->result_array();
         return $res;
     }

     public function update_member_wallet($balance_list)
     {
         $this->db->update_batch('yd_mkt_member_wallet',$balance_list,'MEMBER_ID');
     }

     public function get_refund_amount_from_es_order($oid)
     {
         $this->db->select('order_amount');
         $this->db->where('order_id',$oid);
         $res = $this->db->get('es_order',1)->row_array();
         return $res['order_amount'];
     }

    public function update_refund_amount($refund_amount,$rid)
    {
        $this->db->set('refund_amount', $refund_amount);
        $this->db->where('id', $rid);
        $this->db->update('yd_refund'); // gives UPDATE `mytable` SET `field` = 'field+1' WHERE `id` = 2
    }

    public function update_refund_status_direct($refund_id,$status)
    {
        $this->db->set('refund_status', $status);

        $this->db->where('id', $refund_id);

        $this->db->update('yd_refund');
    }

    public function update_refund_detail_store($id,$store)
    {
        $this->db->set('stored_count',$store);
        $this->db->where('id',$id);
        $this->db->where('return_count >=',$store);
        $this->db->update('yd_refund_detail');
        $rows = $this->db->affected_rows();
        if($rows == 1)
        {
            return TRUE;
        }
        else
        {
            return FALSE;
        }
    }

    public function update_product_store($sn,$store)
    {
        $this->db->set('store',"store+$store",FALSE);
        $this->db->where('sn',$sn);
        $this->db->update('es_product');
    }

    public function update_is_stored($rid)
    {
        $this->db->set('is_stored',1);
        $this->db->where('id',$rid);
        $this->db->update('yd_refund');
    }

    public function get_order_details($orderid)
    {
        $this->db->where('order_id',$orderid);
        return $this->db->get('es_order_items')->result_array();
    }

    public function get_ship_amount($oid)
    {
        $this->db->select('shipping_amount');
        $this->db->where('order_id',$oid);
        $res = $this->db->get('es_order',1)->row_array();
        return $res['shipping_amount'];
    }

    public function get_negative_cmt($from,$size,$reply_status,$key)
    {
        $this->db->start_cache();
        $this->db->where('replystatus',$reply_status);
        if($key)
        {
            $this->db->like('content',$key);
        }

        $this->db->stop_cache();


        $res['total'] = $this->db->count_all_results('v_comment');
        $res['items'] = $this->db->get('v_comment',$size,$from)->result_array();
        return $res;
    }

    public function add_cs_reply($cmtid,$reply)
    {
        $t = now();

        $this->db->set('reply',$reply);
        $this->db->set('replytime',$t);
        $this->db->set('replystatus',1);
        $this->db->where('comment_id',$cmtid);
        $this->db->update('es_member_comment');

    }

    public function get_comment_img($cmid)
    {
        $this->db->where('comment_id',$cmid);
        return $this->db->get('es_member_comment_gallery')->result_array();
    }

    public function get_ydrefund_item($rid)
    {
        $this->db->where('id',$rid);
        return $this->db->get('yd_refund',1)->row_array();
    }

    public function get_vrefund_item($rid)
    {
        $this->db->where('id',$rid);
        return $this->db->get('v_refund',1)->row_array();
    }

    public function save_refund_expressno($refund_id,$expressno)
    {
        $this->db->where('id',$refund_id);
        $this->db->set('delivery_no',$expressno);
        $this->db->update('yd_refund');
        return $this->db->affected_rows();
    }

    public function getSnWrefundid($rid)
    {
        $this->db->where('r.id',$rid);
        $this->db->join('es_order as e','r.order_id = e.order_id');
        $this->db->select('e.sn,e.member_id');
        $res = $this->db->get('yd_refund as r',1)->row_array();
        return $res;

    }

    /**
     * @return CI_Benchmark
     */
    public function getEsgoods($code)
    {
        $this->db->where('sn',$code);
        return $this->db->get('es_goods',1)->row_array();
    }

    public function get_order_status($orderid)
    {
        $this->db->where('order_id',$orderid);
        $this->db->select('status');
        $res = $this->db->get('es_order',1)->row_array();
        return $res['status'];

    }

    public function get_order_item($orderid)
    {
        $this->db->where('order_id',$orderid);
        $res = $this->db->get('es_order',1)->row_array();
        return $res;

    }

    public function update_order_status($orderid,$status)
    {
        $this->db->set('status',$status);
        $this->db->where('order_id',$orderid);
        $this->db->update('es_order');
    }


}