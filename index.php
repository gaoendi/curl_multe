<?php
//使用要求，需要php5.3版本以上
//$access_token和$openid_array的获取省略
define('ROOT_PATH', dirname(__FILE__));
require_once ROOT_PATH.'/core/curl.php';
	$curl = new CURL();
	$curl_url = "https://api.weixin.qq.com/cgi-bin/message/custom/send?access_token=".$access_token;
	foreach($openid_array as $openid){
		$curl_post = charsetToUTF8('{"touser":"'.$openid.'","msgtype":"text","text":{"content":"你好"}}');
		$url = array($curl_url,null,array(
			CURLOPT_POST=>1,
			CURLOPT_POSTFIELDS=>$curl_post,
			CURLOPT_HTTPHEADER=>array("application/json;charset=utf-8",'Content-Length: '.strlen($curl_post)),
		));
		$curl->add(
			$url,
			array('cb',array($openid)),//请求成功的回调函数
			array('cb',array($openid))//请求失败的回调函数
		);
	}
	$curl->go();//开始执行

//$curl回调函数
function cb($res,$val){
//$res 是指请求相关信息 $res['content']是指请求返回的结果
//$val 是调用时传入的参数 （ex:foreach中传入的$openid）
}
?>