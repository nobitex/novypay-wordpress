<?php
/*
Plugin Name: Nobitex
Version:
Description:
Plugin URI:
Author:
Author URI:

*/

if (!defined('ABSPATH')) {
    exit;
}


function nobitex_load()
{
    include_once "class-nobitex.php";

    if (!class_exists('Woocommerce_Ir_Gateway_PayIr')) {

        nobitex_abstract::register('Nobitex');

        class Woocommerce_Ir_Gateway_Nobitex extends nobitex_abstract
        {

            public function __construct()
            {

                $this->method_title = 'Nobitex.ir';
//                $this->icon = apply_filters('woocommerce_ir_gateway_nobitex_icon', PW()->plugin_url('assets/images/nobitex.png'));

                parent::init($this);
            }

            public function fields()
            {

                return array(
                    'api' => array(
                        'title' => 'API',
                        'type' => 'text',
                        'description' => 'API درگاه nobitex.ir',
                        'default' => '',
                        'desc_tip' => true
                    ),
                    'sandbox' => array(
                        'title' => 'فعالسازی حالت آزمایشی',
                        'type' => 'checkbox',
                        'label' => 'فعالسازی حالت آزمایشی nobitex.ir',
                        'description' => 'برای فعال سازی حالت آزمایشی nobitex.ir چک باکس را تیک بزنید.',
                        'default' => 'no',
                        'desc_tip' => true,
                    ),
                    'cancelled_massage' => array(),
                    'shortcodes' => array(
                        'transaction_id' => 'شماره تراکنش',
                    ),
                    'exchange_rate' => array(
                        'title' => 'Exchange Rate',
                        'type' => 'float',
                        'description' => 'نرخ تبدیل ارز',
                        'default' => '1',
                        'desc_tip' => true
                    ),
                );
            }

            public function request($order)
            {
                if (empty($this->option('secret_key'))) {
                    $error_message = 'you have to set api secret first';
                    return print_r("<p style='color: red'>" . $error_message . "</p>");
                }

                if (!extension_loaded('curl')) {
                    return 'تابع cURL روی هاست شما فعال نیست.';
                }
                $currencies = '';
                if ($this->option('btc') == '1') {
                    if ($currencies != '') {
                        $currencies .= ',btc';
                    } else {
                        $currencies .= 'btc';
                    }
                }
                if ($this->option('ltc') == '1') {
                    if ($currencies != '') {
                        $currencies .= ',ltc';
                    } else {
                        $currencies .= 'ltc';
                    }
                }
                if ($this->option('xrp') == '1') {
                    if ($currencies != '') {
                        $currencies .= ',xrp';
                    } else {
                        $currencies .= 'xrp ';
                    }
                }
                global $woocommerce;

                $url = $this->option('sandbox') == '1' ? "https://testnetapi.nobitex.ir/" : "https://api.nobitex.ir/";
                $site_url = $this->option('sandbox') == '1' ? "https://testnet.nobitex.ir/" : "https://nobitex.ir/";
                $rate = $this->option('exchange_rate');
                $amount = $this->get_total('IRR') * $rate;
                $amount = apply_filters('filter_nobitex_final_amount', $amount, $total_amount = $woocommerce->cart->total);
//                $callback = $this->option('sandbox')=='1' ? 'http://testnet.nobitex.net/app/callback-gateway/' : $this->get_verify_url();
                $callback = $this->get_verify_url();
                $mobile = $this->get_order_mobile();
                $order_number = $this->get_order_props('order_number');
                $description = 'شماره سفارش #' . $order_number;
                $apiID = $this->option('sandbox') == '1' ? (!empty($this->option('api')) ? $this->option('api') : 'DemoApiKey') : $this->option('api');
                $data = array("api" => $apiID, "callbackURL" => $callback, "amount" => $amount, "currencies" => $currencies);
                $header = array("content-type" => "application/json");

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url . 'pg/send/');
                // curl_setopt( $ch, CURLOPT_POSTFIELDS, "api=$apiID&amount=$amount&callbackURL=$callback&factorNumber=$order_number&mobile=$mobile&description=$description&resellerId=1000000800&currencies=btc" );
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                $result = curl_exec($ch);

                curl_close($ch);
                $result = json_decode($result);
                if (!empty($result->status) && $result->status == "success") {
                    return $this->redirect($site_url . "app/paygate/" . $result->token);
                } else {
                    $error_message = !empty($result->message) ? $result->message : (!empty($result->errorCode) ? $this->errors($result->errorCode) : '');
                    return print_r("<p style='color: red'>" . $error_message . "</p>");
                }
            }

            public function verify($order)
            {
                $url = $this->option('sandbox') == '1' ? "https://testnetapi.nobitex.ir/" : "https://api.nobitex.ir/";
                $site_url = $this->option('sandbox') == '1' ? "https://testnet.nobitex.ir/" : "https://nobitex.ir/";
                $apiID = $this->option('sandbox') == '1' ? (!empty($this->option('api')) ? $this->option('api') : 'DemoApiKey') : $this->option('api');
                $transaction_id = $this->get('token');
                //$factorNumber = $this->post( 'factorNumber' );

                $this->check_verification($transaction_id);

                $token = $_GET['token'];

                $total = $this->get_total('IRR');
                $total = apply_filters('filter_nobitex_verify_amount', $total);

                $amount_string = number_format($total, 0, '.', '');
                $amount_string = strval($amount_string);

                $secret_key = str_replace("-", "", $this->option('secret_key'));
                $md5_secret = hash('sha256', $token . $amount_string . $secret_key);

                $error = '';
                $status = 'failed';
                if ($apiID == 'DemoApiKey' || $this->get('status')) {

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $url . 'pg/verify/');
                    curl_setopt($ch, CURLOPT_POSTFIELDS, "api=$apiID&token=$transaction_id");
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    $result = curl_exec($ch);
                    curl_close($ch);
                    $result = json_decode($result);

                    if (!empty($result->status) && $result->status) {
                        if ($md5_secret === $result->txHash)
                            $status = 'completed';
                        else {
                            $error = 'your secret key is wrong! or there is a Man In The Middle!';
                        }
                    } else {
                        $error = !empty($result->errorMessage) ? $result->errorMessage : (!empty($result->errorCode) ? $this->errors(($result->errorCode . '1')) : '');
                    }

                } else {
                    $error = $this->post('message');
                }

                $this->set_shortcodes(array('transaction_id' => $transaction_id));

                return compact('status', 'transaction_id', 'error');
            }

            private function errors($error)
            {

                switch ($error) {

                    case '-1' :
                        $message = 'ارسال Api الزامی می باشد.';
                        break;

                    case '-2' :
                        $message = 'ارسال Amount (مبلغ تراکنش) الزامی می باشد.';
                        break;

                    case '-3' :
                        $message = 'مقدار Amount (مبلغ تراکنش)باید به صورت عددی باشد.';
                        break;

                    case '-4' :
                        $message = 'Amount نباید کمتر از 1000 باشد.';
                        break;

                    case '-5' :
                        $message = 'ارسال Redirect الزامی می باشد.';
                        break;

                    case '-6' :
                        $message = 'درگاه پرداختی با Api ارسالی یافت نشد و یا غیر فعال می باشد.';
                        break;

                    case '-7' :
                        $message = 'فروشنده غیر فعال می باشد.';
                        break;

                    case '-8' :
                        $message = 'آدرس بازگشتی با آدرس درگاه پرداخت ثبت شده همخوانی ندارد.';
                        break;

                    case 'failed' :
                        $message = 'تراکنش با خطا مواجه شد.';
                        break;

                    case '-11' :
                        $message = 'ارسال Api الزامی می باشد.';
                        break;

                    case '-21' :
                        $message = 'ارسال TransId الزامی می باشد.';
                        break;

                    case '-31' :
                        $message = 'درگاه پرداختی با Api ارسالی یافت نشد و یا غیر فعال می باشد.';
                        break;

                    case '-41' :
                        $message = 'فروشنده غیر فعال می باشد.';
                        break;

                    case '-51' :
                        $message = 'تراکنش با خطا مواجه شده است.';
                        break;

                    default:
                        $message = 'خطای ناشناخته رخ داده است.';
                        break;
                }

                return $message;
            }
        }
    }
//endif;
}

add_action('plugins_loaded', 'nobitex_load', 0);

