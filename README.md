
支付宝即时到账 SDK 简化版（含移动网页支付）
==========

该项目精简和重构了官方的 SDK 开发包，将签名参数和验证返回合并在一个类里，仅一个文件，引入方便，调用简单。


适用范围
----------

* 即时到账支付（含移动网页版）
* 各类 PHP 项目（含各种框架）
* 有一定 PHP 项目经验（能 debug 类库源码）的开发者



使用方式
----------

### Composer 包 ###

	$ composer require mytharcher/alipay-php-sdk

或者在已有`composer.json`中添加：

	"require": {
		"mytharcher/alipay-php-sdk": "dev-master"
	}

### 普通引入 ###

1. 下载项目代码，将`Alipay.php`和`cacert.pem`放置到项目的合适位置；
2. 如果使用移动端签名需要同时下载alipay_public_key.pem到合适位置
3. 使用框架加载第三方类库方法，或者直接引入`Alipay.php`；

### 支付前 ###

支付前需要将订单相关数据按支付宝的接口规则准备好，并在客户端生成表单由用户发起支付提交。

桌面版和移动网页版接口不同，需要由两种方式生成数据。

注意，桌面版和移动版需要自行根据`userAgent`进行判断（推荐：[Mobile Detect](https://github.com/serbanghita/Mobile-Detect)）。

#### 桌面版 ####

引入类库后，创建一个实例，调用`buildRequestForm`方法来生成支付表单：

	$alipay = new Alipay(array(/* config */));

	$body = $alipay->buildRequestFormHTML(array(
		'out_trade_no'      => $order['id'],
		'subject'	        => '杜蕾斯xx',
		'total_fee'         => $order['price'],
		'body'              => $order['category']['name'],
		'show_url'          => 'http://'.$_SERVER['HTTP_HOST'].'/product/xx',
		'anti_phishing_key' => '',
		'exter_invoke_ip'   => '',
		'it_b_pay'          => $this->setting['paymentTimeout'] / 60 . 'm',
		'_input_charset'    => $this->config->item('input_charset', 'alipay')
	));
	
	// 输出 HTML 到浏览器，JS 会自动发起提交
	echo $body;

#### 移动网页 ####

移动网页版初始化实例时需要传入第二个参数`wap`表明是移动版网页支付：

	$alipay = new Alipay(array(/*...*/), 'wap');

在生成提交表单之前需要一个额外的步骤，调用`prepareMobileTradeData`方法会在后端发起一次预支付提交到支付宝服务器，并准备好要生成的参数，提交参数也略有不同：

	$params = $alipay->prepareMobileTradeData(array(
		'out_trade_no' => $order['id']
		'subject'	   => '杜蕾斯xx',
		'body'         => $order['category']['name'],
		'total_fee'    => $order['price'],
		'merchant_url' => 'http://'.$_SERVER['HTTP_HOST'].'/product/xx',
		'req_id'       => date('Ymdhis-').$order['id']
	))
	
	// 移动网页版接口只支持 GET 方式提交
	$body = $alipay->buildRequestFormHTML($params, 'get');

有时候需要适配微信内的支付跳转，由于双方竞争关系导致无法自动跳转（参见：《[关于微信公众平台无法使用支付宝收付款的解决方案说明](https://cshall.alipay.com/enterprise/help_detail.htm?help_id=524702)》），则可以不生成表单，直接使用`$params`参数数组在页面生成支付链接，提示用户用浏览器打开支付。

### 支付后 ###

支付后不再区分桌面版和移动网页版，都可以调用统一的接口验证返回信息。唯一区别是分为**同步**和**异步**两种方式，而且这两种方式会根据收到的请求数据自动判断完成，使用者无需关心。

	$alipay = new Alipay(array(/* config... */));
	// 获得验证结果 true/false
	$result = $alipay->verifyCallback();

调用接口后根据结果取值进行后续的业务处理，如订单成功支付完成，或者支付失败等。

注意：正常情况下，支付宝的异步通知模式会比返回更早调用，在业务处理中需要考虑重复调用的情况。

### APP支付 服务端生成签名以及验签

移动APP签名支持 RSA

> 注意：rsa签名验签时的公钥是支付提供的，不是自己生成rsa签名时生成的公钥


```
//以下配置必须
$config['sign_type'] = 'RSA';
$config['private_key_path'] = '';//rsa私钥路径


$alipay = new Alipay(/* config... */,'app');
$params = array(
    'out_trade_no' => 324242342342,
    'subject' => '主题 产品名称',,
    'total_fee' => '0.01',,
    '_input_charset' => 'utf-8',
    'sign_type' => 'RSA'
);
$paramStr = $alipay->buildSignedParametersForApp(/*$params*/); //此代码可以直接给APP端提交

```
验签：

```
$config['sign_type'] = 'RSA';
$alipay = new Alipay(/* config... */,'app');
// 获得验证结果 true/false
$result = $alipay->verifyCallback();
```


API
----------

主要的几个方法如下：

### `new Alipay($config = array(), $type='')` ###

- $config 配置数组
- $type    wap 移动网页支付 /app 移动APP /其他PC支付

构造方法，创建支付对象实例。

参数`$config`：支付配置数组，可用参数列表：

	array(
		// 即时到账方式
		'payment_type' => 1,
		// 传输协议
		'transport' => 'http',
		// 编码方式
		'input_charset' => 'utf-8',
		// 签名方法
		'sign_type' => 'MD5',
		// 支付完成异步通知调用地址
		'notify_url' => 'http://'.$_SERVER['HTTP_HOST'.'>/order/callback_alipay/notify',
		// 支付完成同步返回地址
		'return_url' => 'http://'.$_SERVER['HTTP_HOST'.'>/order/callback_alipay/return',
		// 证书路径
		'cacert' => APPPATH.'third_party/alipay/cacert.pem',
		// 支付宝商家 ID
		'partner'      => '2088xxxxxxxx',
		// 支付宝商家 KEY
		'key'          => 'xxxxxxxxxxxx',
		// 支付宝商家注册邮箱
		'seller_email' => 'email@domain.com'
	)

参数`$is_mobile`：当前是否是移动支付，默认`FALSE`（需要使用者自行判断，见前文）。

### `buildSignedParameters(array(/*params*/))` ###

根据交易信息创建准备提交的已签名参数组。主要用于自定义生成提交信息页面，当不需要按标准方式输出自动提交表单时，可以通过该方法准备好要提交的参数，自行处理提交页面。

	$params = $alipay->buildSignedParameters(array(/*params*/));

参数见“支付前”部分。

### `buildRequestFormHTML(array(/*params*/))` ###

根据交易信息创建自动提交表单的 HTML 内容。参数同上。

	// 向客户端页面输出所有要提交的参数表单内容
	echo $alipay->buildRequestFormHTML($params);

### `prepareMobileTradeData(array(/*params*/))` ###

移动网页版准备提交数据。由于移动网页支付分为两个步骤，第一步在后端向支付宝服务器发起一次预提交，获取 token，之后才由客户端带着 token 一起提交准备完成的参数。

	$params = $alipay->prepareMobileTradeData(array(/*params*/));
	// 将准备好的参数生成表单输出到客户端自动提交
	echo $alipay->buildRequestFormHTML($params);

### `verifyCallback()` ###

对支付完成返回结果的验证。合并了异步通知模式和同步返回模式的参数验证，均可通过调用此接口完成。且是否是移动模式也会自动判断。

	$alipay = new Alipay(array(/* config */));
	// 获得验证结果 true/false
	$result = $alipay->verifyCallback();

大多数情况使用这几个接口就可以完成支付，其他子功能详见源码中相应函数注释。


吐槽
----------

懒得吐槽原来的接口代码了。


MIT Licensed
----------

-EOF-
