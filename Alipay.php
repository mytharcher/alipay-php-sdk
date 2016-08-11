<?php
/**
 * 支付宝支付，包含 PC网页支付、wap网页支付、移动APP签名生成
 */
namespace mytharcher\sdk\alipay;

use \DOMDocument;
class Alipay
{
    // 配置信息在实例化时传入，以下为范例
    public $config = array(
        // // 即时到账方式
        'payment_type' => 1,
        // // 传输协议
        'transport' => 'http',
        // // 编码方式
        'input_charset' => 'utf-8',
        // // 签名方法
        'sign_type' => 'MD5',
        // // 证书路径
        'cacert' => dirname(__FILE__).'/cacert.pem',
        'public_key_path' => dirname(__FILE__).'/alipay_public_key.pem', //验签公钥地址
        // // 支付完成异步通知调用地址
        // 'notify_url' => 'http://'.$_SERVER['HTTP_HOST'.'>/order/callback_alipay/notify',
        // // 支付完成同步返回地址
        // 'return_url' => 'http://'.$_SERVER['HTTP_HOST'.'>/order/callback_alipay/return',
        // // 支付宝商家 ID
        // 'partner'      => '2088xxxxxxxx',
        // // 支付宝商家 KEY
        // 'key'          => 'xxxxxxxxxxxx',
        // // 支付宝商家注册邮箱
        // 'seller_email' => 'email@domain.com'
    );

    public $is_mobile = FALSE;

    private $service        = 'create_direct_pay_by_user';
    private $service_wap    = 'alipay.wap.create.direct.pay.by.user';
    private $service_app    = 'mobile.securitypay.pay';

    private $alipay_gateway = 'https://mapi.alipay.com/gateway.do?';

    public $verify_url       = 'http://notify.alipay.com/trade/notify_query.do?';
    public $verify_url_https = 'https://mapi.alipay.com/gateway.do?service=notify_verify&';
    /**
     * rsa私钥路径
     * @var string
     */
    public $rsaPriKeyPath = '';

    /**
     * rsa公钥路径
     * @var string
     */
    public $rsaPubKeyPath = '';

    /**
     * 配置
     * @param $config  array 配置信息
     * @param null $type string 类型  wap app
     */
    public function __construct($config, $type = null)
    {
        if(isset($config['private_key_path'])){
            $this->rsaPriKeyPath = $config['private_key_path'];
            unset($config['private_key_path']);
        }

        if(isset($config['public_key_path'])){
            $this->rsaPubKeyPath = $config['public_key_path'];
            unset($config['public_key_path']);
        }

        $this->config = array_merge($this->config, (array)$config);

        $this->is_mobile = (($type == 'wap' || $type === true) ? true : false);

        if ($type == 'wap' || $type === true) {
            $this->service = $this->service_wap;
        } elseif ($type == 'app') {
            $this->service = $this->service_app;
        }

    }

    /**
     * 生成请求参数的签名
     *
     * @param $params <Array>
     * @return string <String>
     *
     */
    function signParameters($params)
    {
        // 支付宝的签名串必须是未经过 urlencode 的字符串
        // 不清楚为何 PHP 5.5 里没有 http_build_str() 方法
        //$paramStr = http_build_query($params);
        //mobile
        $paramStr = [];
        foreach ($params as $k => $param) {
            $paramStr[] = $k . '=' . $param;
        }
        $paramStr = implode('&', $paramStr);

        switch (strtoupper(trim($this->config['sign_type']))) {
            case "MD5" :
                $result = md5($paramStr . $this->config['key']);
                break;

            case "RSA" :
            case "0001" :
                $priKey = file_get_contents($this->rsaPriKeyPath);
                $res = openssl_get_privatekey($priKey);
                openssl_sign($paramStr, $sign, $res);
                openssl_free_key($res);
                //base64编码
                $result = base64_encode($sign);
                break;

            default :
                $result = "";
        }

        return $result;
    }

    /**
     * 生成签名后的请求参数
     *
     * @param $params <Array>
     *        $params['out_trade_no']     唯一订单编号
     *        $params['subject']
     *        $params['total_fee']
     *        $params['body']
     *        $params['show_url']
     *        $params['anti_phishing_key']
     *        $params['exter_invoke_ip']
     *        $params['it_b_pay']
     *        $params['_input_charset']
     * @return array <Array>
     *
     */
    function buildSignedParameters($params)
    {
        $default = array(
            'service' => $this->service,
            'partner' => $this->config['partner'],
            '_input_charset' => trim(strtolower($this->config['input_charset']))
        );

        $default = array_merge($default, array(
            'payment_type' => $this->config['payment_type'],
            'seller_id' => $this->config['partner'],
            'notify_url' => $this->config['notify_url'],
        ));
        if (isset($this->config['return_url'])) {
            $default['return_url'] = $this->config['return_url'];
        }
        $params = $this->filterSignParameter(array_merge($default, (array)$params));
        ksort($params);
        reset($params);

        $params['sign'] = $this->signParameters($params);
        if ($params['service'] != 'alipay.wap.trade.create.direct' && $params['service'] != 'alipay.wap.auth.authAndExecute') {
            $params['sign_type'] = strtoupper(trim($this->config['sign_type']));
        }

        return $params;
    }

    /**
     * https://doc.open.alipay.com/doc2/detail.htm?spm=a219a.7629140.0.0.NgdeQA&treeId=59&articleId=103663&docType=1
     * 服务端生成app支付使用的参数以及签名
     * @param $params
     * @return array
     */
    function buildSignedParametersForApp($params)
    {
        $default = array(
            'service' => $this->service,
            'partner' => $this->config['partner'],
            '_input_charset' => trim(strtolower($this->config['input_charset']))
        );

        $default = array_merge($default, array(
            'payment_type' => $this->config['payment_type'],
            'seller_id' => $this->config['partner'],
            'notify_url' => $this->config['notify_url'],
        ));
        if (isset($this->config['return_url'])) {
            $default['return_url'] = $this->config['return_url'];
        }
        $params = $this->filterSignParameter(array_merge($default, (array)$params));
        ksort($params);
        reset($params);
        $paramStr = [];
        foreach ($params as $k => &$param) {
            $param = '"' . $param . '"';
            $paramStr[] = $k . '=' . $param;
        }
        $sign = $this->signParameters($params);
        $paramStr['sign'] = 'sign="' . urlencode($sign) . '"';
        $paramStr['sign_type'] = 'sign_type="RSA"';
        return implode('&', $paramStr);
    }

    /**
     * 生成请求参数的发送表单HTML
     *
     * 其实这个函数没有必要，更应该使用签名后的参数自己组装，只不过有时候方便就从官方 SDK 里留下了。
     *
     * @param $params <Array> 请求参数（未签名的）
     * @param string $method <String> 请求方法，默认：post，可选 get
     * @param string $target <String> 提交目标，默认：_self
     * @return string <String>
     *
     */
    function buildRequestFormHTML($params, $method = 'post', $target = '_self')
    {
        $params = $this->buildSignedParameters($params);
        $html = "<meta charset='" . $this->config['input_charset'] . "' /><form id='alipaysubmit' name='alipaysubmit' action='" . $this->alipay_gateway . "_input_charset=" . trim(strtolower($this->config['input_charset'])) . "' method='$method' target='$target'>";

        foreach ($params as $key => $value) {
            $html .= "<input type='hidden' name='$key' value='$value'/>";
        }

        $html .= "</form><script>document.forms['alipaysubmit'].submit();</script>";

        return $html;
    }

    /**
     * 准备移动网页支付的请求参数
     *
     * 移动网页支付接口不同，需要先服务器提交一次请求，拿到返回 token 再返回客户端发起真实支付请求。
     * 该方法只完成第一次服务端请求，生成参数后需要客户端另行处理（可调用`buildRequestFormHTML`生成表单提交）。
     *
     * @param $params <Array>
     *        $params['out_trade_no'] 订单唯一编号
     *        $params['subject']      商品标题
     *        $params['total_fee']    支付总费用
     *        $params['merchant_url'] 商品链接地址
     *        $params['req_id']       请求唯一 ID
     *        $params['it_b_pay']     超期时间（秒）
     * @return array|null <Array>/<NULL>
     */
    function prepareMobileTradeData($params)
    {
        // 不要用 SimpleXML 来构建 xml 结构，因为有第一行文档申明支付宝验证不通过
        $xml_str = '<direct_trade_create_req>' .
            '<notify_url>' . $this->config['notify_url'] . '</notify_url>' .
            '<call_back_url>' . $this->config['return_url'] . '</call_back_url>' .
            '<seller_account_name>' . $this->config['seller_email'] . '</seller_account_name>' .

            '<out_trade_no>' . $params['out_trade_no'] . '</out_trade_no>' .
            '<subject>' . htmlspecialchars($params['subject'], ENT_XML1, 'UTF-8') . '</subject>' .
            '<total_fee>' . $params['total_fee'] . '</total_fee>' .
            '<merchant_url>' . $params['merchant_url'] . '</merchant_url>' .
            (isset($params['it_b_pay']) ? '<pay_expire>' . $params['it_b_pay'] . '</pay_expire>' : '') .
            '</direct_trade_create_req>';

        $request_data = $this->buildSignedParameters(array(
            'service' => $this->service,
            'partner' => $this->config['partner'],
            'sec_id' => $this->config['sign_type'],
            'format' => 'xml',
            'v' => '2.0',

            'req_id' => $params['req_id'],
            'req_data' => $xml_str
        ));

        $url = $this->alipay_gateway;
        $input_charset = trim(strtolower($this->config['input_charset']));

        if (trim($input_charset) != '') {
            $url = $url . "_input_charset=" . $input_charset;
        }
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);//SSL证书认证
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);//严格认证
        curl_setopt($curl, CURLOPT_CAINFO, $this->config['cacert']);//证书地址
        curl_setopt($curl, CURLOPT_HEADER, 0); // 过滤HTTP头
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);// 显示输出结果
        curl_setopt($curl, CURLOPT_POST, true); // post传输数据
        curl_setopt($curl, CURLOPT_POSTFIELDS, $request_data);// post传输数据
        $responseText = curl_exec($curl);
        //var_dump( curl_error($curl) );//如果执行curl过程中出现异常，可打开此开关，以便查看异常内容
        curl_close($curl);

        if (empty($responseText)) {
            return NULL;
        }

        parse_str($responseText, $responseData);

        if (empty($responseData['res_data'])) {
            return NULL;
        }

        if ($this->config['sign_type'] == '0001') {
            $responseData['res_data'] = $this->rsaDecrypt($responseData['res_data'], $this->rsaPriKeyPath);
        }

        //token从res_data中解析出来（也就是说res_data中已经包含token的内容）
        $doc = new \DOMDocument();
        $doc->loadXML($responseData['res_data']);
        $responseData['request_token'] = $doc->getElementsByTagName("request_token")->item(0)->nodeValue;

        $xml_str = '<auth_and_execute_req>' .
            '<request_token>' . $responseData['request_token'] . '</request_token>' .
            '</auth_and_execute_req>';

        return array(
            'service' => 'alipay.wap.auth.authAndExecute',
            'partner' => $this->config['partner'],
            'sec_id' => $this->config['sign_type'],
            'format' => 'xml',
            'v' => '2.0',
            'req_data' => $xml_str
        );
    }

    /**
     * 支付完成验证返回参数（包含同步和异步）
     * @return bool <Boolean>
     *
     */
    function verifyCallback()
    {
        $async = empty($_GET);

        $data = $async ? $_POST : $_GET;
        if (empty($data)) {
            return FALSE;
        }

        $signValid = $this->verifyParameters($data, $data["sign"]);
        $notify_id = isset($data['notify_id']) ? $data['notify_id'] : NULL;
        if ($async && $this->is_mobile) {
            //对notify_data解密
            if ($this->config['sign_type'] == '0001') {
                $data['notify_data'] = $this->rsaDecrypt($data['notify_data'], $this->rsaPriKeyPath);
            }

            //notify_id从decrypt_post_para中解析出来（也就是说decrypt_post_para中已经包含notify_id的内容）
            $doc = new \DOMDocument();
            $doc->loadXML($data['notify_data']);
            $notify_id = $doc->getElementsByTagName('notify_id')->item(0)->nodeValue;
        }
        //获取支付宝远程服务器ATN结果（验证是否是支付宝发来的消息）
        $responseTxt = 'true';
        if (!empty($notify_id)) {
            $responseTxt = $this->verifyFromServer($notify_id);
        }
        //验证
        //$signValid的结果不是true，与安全校验码、请求时的参数格式（如：带自定义参数等）、编码格式有关
        //$responsetTxt的结果不是true，与服务器设置问题、合作身份者ID、notify_id一分钟失效有关
        return $signValid && preg_match("/true$/i", $responseTxt);
    }

    function verifyParameters($params, $sign)
    {
        $params = $this->filterSignParameter($params);

        ksort($params);
        reset($params);

        $content = urldecode(http_build_query($params));

        switch (strtoupper(trim($this->config['sign_type']))) {
            case "MD5" :
                return md5($content . $this->config['key']) == $sign;

            case "RSA" :
            case "0001" :
                return $this->rsaVerify($content, $this->rsaPubKeyPath, $sign);

            default :
                return FALSE;
        }
    }

    /**
     * 过滤参数,去除sign/sign_type参数
     * @param $params
     * @return array
     */
    function filterSignParameter($params)
    {
        $result = array();
        foreach ($params as $key => $value) {
            if ($key != "sign" && $key != 'sign_type' && $value) {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    function verifyFromServer($notify_id)
    {
        $transport = strtolower(trim($this->config['transport']));
        $partner = trim($this->config['partner']);
        $veryfy_url = ($transport == 'https' ? $this->verify_url_https : $this->verify_url) . "partner=$partner&notify_id=$notify_id";
        $curl = curl_init($veryfy_url);
        curl_setopt($curl, CURLOPT_HEADER, 0); // 过滤HTTP头
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);//SSL证书认证
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);//严格认证
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);// 显示输出结果
        curl_setopt($curl, CURLOPT_CAINFO, $this->config['cacert']);//证书地址
        $responseText = curl_exec($curl);
        // var_dump( curl_error($curl) );//如果执行curl过程中出现异常，可打开此开关，以便查看异常内容
        curl_close($curl);
        return $responseText;
    }

    /**
     * RSA验签,注意验签的公钥是支付宝的公钥,不是自己生成的rsa公钥,可以在淘宝的demo中获得
     * @param $data string 待签名数据
     * @param $ali_public_key_path string 支付宝的公钥文件路径
     * @param $sign string 要校对的的签名结果
     * @return bool 验证结果
     * @throws Exception
     */
    function rsaVerify($data, $ali_public_key_path, $sign)
    {
        $pubKey = file_get_contents($ali_public_key_path);
        $res = openssl_get_publickey($pubKey);
        if(!$res){
            throw new Exception('公钥格式错误');
        }
        $result = (bool)openssl_verify($data, base64_decode($sign), $res);
        openssl_free_key($res);
        return $result;
    }

    /**
     * RSA解密
     * @param $content string 需要解密的内容，密文
     * @param $private_key_path string 商户私钥文件路径
     * @return string 解密后内容，明文
     */
    function rsaDecrypt($content, $private_key_path)
    {
        $priKey = file_get_contents($private_key_path);
        $res = openssl_get_privatekey($priKey);
        //用base64将内容还原成二进制
        $content = base64_decode($content);
        //把需要解密的内容，按128位拆开解密
        $result = '';
        for ($i = 0; $i < strlen($content) / 128; $i++) {
            $data = substr($content, $i * 128, 128);
            openssl_private_decrypt($data, $decrypt, $res);
            $result .= $decrypt;
        }
        openssl_free_key($res);
        return $result;
    }
}