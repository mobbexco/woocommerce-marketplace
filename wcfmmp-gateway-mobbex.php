<?php


class WCFMmp_Gateway_Mobbex {
    public $id;
    public $message = array();
    public $gateway_title;
    public $payment_gateway;

    public function __construct() {
        $this->id = 'mobbex';
        $this->gateway_title = __('Mobbex', 'wc-multivendor-marketplace');
        $this->payment_gateway = $this->id;
    }


    public function validate_request() {
        global $WCFMmp;
        return true;
    }
}

