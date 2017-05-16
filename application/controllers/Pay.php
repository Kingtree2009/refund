<?php

/**
 * Created by PhpStorm.
 * User: kt
 * Date: 2017/3/16
 * Time: 下午2:40
 */
/**
 * pay 支付控制器
 */
class Pay extends CI_Controller {

    public function __construct(){
        $this->isNeedLogin = FALSE;
        parent::__construct();
    }

    /**
     * index 主页/首页
     */
    public function index(){
        $this->load->view('welcome_message');
    }

    /**
     * alipaySubmit 提交支付宝支付
     * @access public
     * @param string $order_code
     * @return void
     */
    public function alipaySubmit($order_code){
        $this->load->library('MY_Alipay');
        $result = $this->my_alipay->paySubmit($order_code);
        if (is_bool($result) && ! $result) {
            $this->jumpNoticePage('订单信息不存在！', site_url('main'), 'ERROR');
        }
        if (is_bool($result) && $result == true) {
            $this->jumpNoticePage('订单已支付，请不要重复支付！', site_url('user/orders'), 'ERROR');
        }

        $this->assign('html_text', $result);
        $this->display('order/alipay.html');
    }

    /**
     * aliPayNotify 支付宝异步回调
     * @access public
     * @param void
     * @return void
     */
    public function aliPayNotify(){
        $this->load->library('MY_Alipay');
        $this->my_alipay->asynNotify();
    }

    /**
     * aliPayReturn 支付宝同步通知
     * @access public
     * @param void
     * @return void
     */
    public function aliPayReturn(){
        $this->load->library('MY_Alipay');
        $this->my_alipay->syncReturn();
        $order_code = $this->input->get('out_trade_no');
        header('location: '.site_url('order/paysuccess/'.$order_code));
    }

    /**
     * wxPayQRcode 生成微信支付二维码
     * @access public
     * @param void
     * @return void
     */
    public function wxPayQRcode($order_code = ''){
        if ( ! $order_code) $order_code = $this->uri->segment(3, false);
        $this->load->library('MY_WxPay');
        $this->my_wxpay->createWxPayQRCode($order_code);
    }

    /**
     * wxPayResult 微信支付结果查询
     * @access public
     * @param void
     * @return void
     */
    public function wxPayResult(){
        $order_code = $this->input->post('order_code', true);
        $pay_method = $this->input->post('pay_method', true);
        $this->load->model('order_model');
        $order_info = $this->order_model->getOrderByID($order_code, true);
        if ($order_info['pay_status'] == '1') {
            $this->ajaxReturn('SUCCESS');
        } else {
            $this->load->library('MY_WxPay');
            $r = $this->my_wxpay->wxPayOrderQuery($order_code);
            if ($r['return_code'] == 'SUCCESS' && $r['result_code'] == 'SUCCESS') {
                if ($r['trade_state'] == 'SUCCESS') {
                    $this->my_wxpay->updateOrderPay($r);
                    $this->ajaxReturn('SUCCESS');
                }
            }
            $this->ajaxReturn('WAITING');
        }
    }

    /**
     * wxPayNotify
     * @access public
     * @param void
     * @return void
     */
    public function wxPayNotify(){
        $this->load->library('MY_WxPay');
        $this->my_wxpay->notifyProcess();
    }

    /**
     * delay 延时
     * @access public
     */
    public function delay(){
        $this->ajaxReturn('SUCCESS');
    }
}
