<?php

require_once __DIR__ . "/WxConfig.php";
/**
 * 
 * 微信获取用户相关信息类
 *
 * 该类实现了从微信公众平台获取code，通过code获取openid和access_token、userinfo
 * 
 * @author SunHaowei
 *
 */
class WxApi
{

	/**
	 * 通过跳转获取用户的openid和access_token，跳转流程如下：
	 * 1、设置自己需要调回的url及其其他参数，跳转到微信服务器https://open.weixin.qq.com/connect/oauth2/authorize
	 * 2、微信服务处理完成之后会跳转回用户redirect_uri地址，此时会带上一些参数，如：code
	 *
	 * @param $scope snsapi_base和snsapi_userinfo两种
	 * @param $redirect_url
	 *
	 * @return array 用户的openid和access_token数组
	 */
    public function getOpenidAndAccessToken($scope, $redirect_url)
    {
        $code = $this->getCode(urldecode($redirect_url));

        if (empty($code)) {
            // 触发微信返回code码
            if ($scope == 'snsapi_base') {
                $url = $this->__CreateOauthBaseUrlForCode($redirect_url);
            } else {
                $url = $this->__CreateOauthUserInfoUrlForCode($redirect_url);
            }
            Header("Location: $url");
            exit();
        } else {
            // 获取code
            $data = $this->GetOpenidAndAccessTokenFromMp($code);

            return $data;
        }
	}

    /**
     * 通过code从工作平台获取openid和access_token
     *
     * @param $code string 微信跳转回来带上的code
     * @return array openid和access_token数组
     */
	public function GetOpenidAndAccessTokenFromMp($code)
	{
		$url = $this->__CreateOauthUrlForOpenidAndAccessToken($code);
		//初始化curl
		$ch = curl_init();
		//设置超时
		curl_setopt($ch, CURLOPT_TIMEOUT, 600);
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER,FALSE);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,FALSE);
		curl_setopt($ch, CURLOPT_HEADER, FALSE);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		if(WxConfig::CURL_PROXY_HOST != "0.0.0.0"
			&& WxConfig::CURL_PROXY_PORT != 0){
			curl_setopt($ch,CURLOPT_PROXY, WxConfig::CURL_PROXY_HOST);
			curl_setopt($ch,CURLOPT_PROXYPORT, WxConfig::CURL_PROXY_PORT);
		}
		//运行curl，结果以json形式返回
		$res = curl_exec($ch);
		curl_close($ch);

        // json转换成数组
		$data = json_decode($res,true);

        return $data;
	}

	/**
	 * 获取分享所需js参数
	 *
	 * @param $jsapi_ticket
	 * @return string
	 */
	public function getShareParameters($jsapi_ticket)
	{
		$data = array();
		$data['noncestr'] = self::getNonceStr();
		$data['url'] = "http://".$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
		$data['timestamp'] = time();
		$data['jsapi_ticket'] = $jsapi_ticket;

		ksort($data);
		$params = $this->ToUrlParams($data);

		$signature = sha1($params);

		$afterData = array(
			'appId' => WxConfig::APPID,
			'timestamp' => $data['timestamp'],
			'nonceStr' => $data['noncestr'],
			'signature' => $signature,
		);

		return $afterData;
	}

    /**
     * 获取用户基本信息(需要手动授权，无需关注)
     *
     * @param $access_token
     * @param $openid
     * @return mixed
     */
	public function getOAuthUserInfo($access_token, $openid)
	{
		$url = "https://api.weixin.qq.com/sns/userinfo?access_token=" . $access_token . "&openid=" . $openid . "&lang=zh_CN";

		$data = $this->curl($url);

		return $data;
	}

    /**
     * 获取普通access_token
     *
     * @return mixed
     */
    public function getApiAccessToken()
    {
        $url = "https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid=" . WxConfig::APPID . "&secret=" . WxConfig::APPSECRET;

        $data = $this->curl($url);

        $access_token = $data['access_token'];
        $this->api_access_token = $access_token;

        return $access_token;
    }

	/**
	 * 获取用户基本信息(无需授权，需要关注)
	 *
	 * @param $access_token
	 * @param $openid
	 * @return mixed
	 */
	public function getUserInfo($access_token, $openid)
	{
		$url = "https://api.weixin.qq.com/cgi-bin/user/info?access_token=" . $access_token .  "&openid=" . $openid . "&lang=zh_CN";

        $data = $this->curl($url);

		return $data;
	}

	public function getJsapiTicket($access_token)
	{
		$url = "https://api.weixin.qq.com/cgi-bin/ticket/getticket?access_token=" . $access_token . "&type=jsapi";

		$data = $this->curl($url);

		return $data['ticket'];
	}

    /**
     * 构造获取code的url链接，snsapi_base，需要静默授权，需要关注
     *
     * @param $redirectUrl string 微信服务器回跳的url，需要url编码
     * @return string 构造好的url
     */
	private function __CreateOauthBaseUrlForCode($redirectUrl)
	{
		$urlObj["appid"] = WxConfig::APPID;
		$urlObj["redirect_uri"] = "$redirectUrl";
		$urlObj["response_type"] = "code";
		$urlObj["scope"] = "snsapi_base";
		$urlObj["state"] = "STATE"."#wechat_redirect";
		$bizString = $this->ToUrlParams($urlObj);

		return "https://open.weixin.qq.com/connect/oauth2/authorize?".$bizString;
	}

    /**
     * 构造获取code的url链接，snsapi_userinfo模式，需要手动授权无需关注
     *
     * @param $redirectUrl string 微信服务器回跳的url，需要url编码
     * @return string 构造好的url
     */
	private function __CreateOauthUserInfoUrlForCode($redirectUrl)
	{
		$urlObj["appid"] = WxConfig::APPID;
		$urlObj["redirect_uri"] = "$redirectUrl";
		$urlObj["response_type"] = "code";
		$urlObj["scope"] = "snsapi_userinfo";
		$urlObj["state"] = "STATE"."#wechat_redirect";
		$bizString = $this->ToUrlParams($urlObj);

		return "https://open.weixin.qq.com/connect/oauth2/authorize?".$bizString;
	}

    /**
     * 构造获取openid和access_toke的url地址
     *
     * @param $code string 微信跳转带回的code
     * @return string 请求的url
     */
	private function __CreateOauthUrlForOpenidAndAccessToken($code)
	{
		$urlObj["appid"] = WxConfig::APPID;
		$urlObj["secret"] = WxConfig::APPSECRET;
		$urlObj["code"] = $code;
		$urlObj["grant_type"] = "authorization_code";
		$bizString = $this->ToUrlParams($urlObj);

		return "https://api.weixin.qq.com/sns/oauth2/access_token?".$bizString;
	}

	private function curl($url)
	{
		//初始化curl
		$ch = curl_init();
		//设置超时
		curl_setopt($ch, CURLOPT_TIMEOUT, 600);
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER,FALSE);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,FALSE);
		curl_setopt($ch, CURLOPT_HEADER, FALSE);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		if(WxConfig::CURL_PROXY_HOST != "0.0.0.0"
			&& WxConfig::CURL_PROXY_PORT != 0){
			curl_setopt($ch,CURLOPT_PROXY, WxConfig::CURL_PROXY_HOST);
			curl_setopt($ch,CURLOPT_PROXYPORT, WxConfig::CURL_PROXY_PORT);
		}
		//运行curl，结果以jason形式返回
		$res = curl_exec($ch);
		curl_close($ch);

		return json_decode($res,true);
	}

    /**
     * 拼接字符串
     *
     * @param $urlObj
     * @return string 返回已经拼接好的字符串
     */
    private function ToUrlParams($urlObj)
    {
        $buff = "";
        foreach ($urlObj as $k => $v)
        {
            if($k != "sign"){
                $buff .= $k . "=" . $v . "&";
            }
        }

        $buff = trim($buff, "&");

        return $buff;
    }

    /**
     * 产生随机字符串，不长于32位
     *
     * @param int $length
     * @return string
     */
    public static function getNonceStr($length = 32)
    {
        $chars = "abcdefghijklmnopqrstuvwxyz0123456789";
        $str ="";
        for ( $i = 0; $i < $length; $i++ )  {
            $str .= substr($chars, mt_rand(0, strlen($chars)-1), 1);
        }
        return $str;
    }

    /**
     * 从url上获取get到的code参数的值
     *
     * @param $url
     * @return string
     */
    public function getCode($url)
    {
        $query = end(explode('?', $url));
        $code = '';
        if (!empty($query)) {
            $queryParts = explode('&', $query);

            foreach ($queryParts as $param) {
                $item = explode('=', $param);
                if ($item[0] == 'code') {
                    $code = $item[1];
                }
            }

        }

        return $code;
    }
}