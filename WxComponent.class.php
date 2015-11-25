<?php
/**
 * 微信开放平台PHP-SDK, 非官方API
 * author:chenchenjsyz@163.com
 * date:2015-05-25
 */
class WxComponent
{
    private $component_appid;
    private $component_appsecret;
    private $component_access_token;
    private $authorization_code;
    private $component_verify_ticket;
    private $db;
    private $cache_table_name;
    public $errCode;
    public $errMsg;
    public $memc;

    /**
     * 构造函数
     * @param $component_appid
     * @param $component_appsecret
     * @param $component_verify_ticket
     */
    public function __construct($options){
        $db_config = require_once '/alidata/www/weixin/framework/config/db.php';
        $wxcachedb_config =  $db_config[strtoupper('wxcachedb_config')];
        require_once '/alidata/www/weixin/framework/orm/medoo.php';
        $this->db = medoo::getInstance($wxcachedb_config);
        $this->cache_table_name = 'component';
        $this->component_appid         = isset($options['component_appid'])?$options['component_appid']:'';
        $this->component_appsecret     = isset($options['component_appsecret'])?$options['component_appsecret']:'';
        $this->memc = $this->getMemcached();
        $this->prefix =  $this->component_appid.'_component_'; 
        $this->component_access_token  = $this->getComponentAccessToken();              
    }
    /**
     * 获取第三方平台令牌（component_access_token）
     * http请求方式: POST（请使用https协议）
     * https://api.weixin.qq.com/cgi-bin/component/api_component_token
     * @param $component_appid
     * @param $component_appsecret
     * @param $component_verify_ticket
     * @return bool/string
     */
    public function getComponentAccessToken()
    {
        $component_appid = $this->component_appid;
        $component_appsecret = $this->component_appsecret;
        $component_verify_ticket = $this->getComponentVerifyTicket();    
        $cache_data =  $this->memc->get($this->prefix.'access_token'); 
        if (!$cache_data) {
            $param = array(
                "component_appid"         => $component_appid,
                "component_appsecret"     => $component_appsecret,
                "component_verify_ticket" => $component_verify_ticket
            );
            $json_data = self::json_encode($param);
            $url = "https://api.weixin.qq.com/cgi-bin/component/api_component_token";
            $res = $this->httpPost($url, $json_data);          
            if ($res) {
                $data = json_decode($res, true);
                if (!$data || isset($data['errcode'])) {
                    $this->errCode = $data['errcode'];
                    $this->errMsg = $data['errmsg'];
                    return false;
                }
                $component_access_token = $data['component_access_token'];
                //TODO: cache access_token
                if ($component_access_token) {
                    $expire_in = $data['expires_in'] ? intval($data['expires_in']) - 200 : 7000;
                    $this->memc->set($this->prefix.'access_token',$component_access_token,$expire_in); 
                    return $component_access_token;
                } else {
                    return false;
                }
            }else{
                return false;
            }
        }else {
            return $cache_data;
        }
    }

    /**
     * 获取预授权码
     * http请求方式: POST（请使用https协议）
     * https://api.weixin.qq.com/cgi-bin/component/api_create_preauthcode?component_access_token=xxx
     * POST数据示例:
     * {
     *   "component_appid":"appid_value"
     * }
     * @return bool/string
     */
    public function getPreAuthCode(){
        $component_access_token = $this->component_access_token;
        $url = "https://api.weixin.qq.com/cgi-bin/component/api_create_preauthcode?component_access_token=".$component_access_token;
        $component_appid = $this->component_appid;
        $arr_data = array(
            'component_appid' => $component_appid,
        );
        $json_data = self::json_encode($arr_data);
        $result = $this->httpPost($url,$json_data);
        if($result){
            $data = json_decode($result,true);
            if (!$data || isset($data['errcode'])) {
                $this->errCode = $data['errcode'];
                $this->errMsg  = $data['errmsg'];
                return false;
            }
            $pre_auth_code = $data['pre_auth_code'];
            if($pre_auth_code){
                return $pre_auth_code;
            }else{
                return false;
            }
        }
    }

    /**
     * 使用授权码换取公众号的授权信息
     */
    public function getAuthorizationInfo($authorization_code){
        $component_appid = $this->component_appid;
        $param = self::json_encode(array(
            'component_appid'    => $component_appid,
            'authorization_code' => $authorization_code,
        ));
        $url = "https://api.weixin.qq.com/cgi-bin/component/api_query_auth?component_access_token=".$this->component_access_token;
        $res = $this->httpPost($url,$param);
        if($res){
            $res = json_decode($res,true);
            if (!$res || isset($res['errcode'])) {
                $this->errCode = $res['errcode'];
                $this->errMsg  = $res['errmsg'];
                return false;
            }
            return $res;
        }else{
            return false;
        }
    }
    /**
     * @param authorizer_appid 授权方appid
     * 获取授权方的账户信息
     */
    public function getAuthorizerInfo($authorizer_appid){
        $component_appid = $this->component_appid;
        $url = "https://api.weixin.qq.com/cgi-bin/component/api_get_authorizer_info?component_access_token=".$this->component_access_token;
        $param = array(
            'component_appid'  =>  $component_appid,
            'authorizer_appid' =>  $authorizer_appid,
        );
        $json_param = self::json_encode($param);
        $res = $this->httpPost($url,$json_param);
        if($res){
            $res = json_decode($res,true);
            if (!$res || isset($res['errcode'])) {
                $this->errCode = $res['errcode'];
                $this->errMsg = $res['errmsg'];
                return false;
            }
            return $res['authorizer_info'];
        }else{
            return false;
        }
    }

    /**
     * 获取（刷新）授权公众号的令牌
     * @param string $authorizer_appid
     * @param string $authorizer_refresh_token
     * @return bool|mixed|string
     */
    public function getAuthorizerAccessToken($authorizer_appid=''){
        if($authorizer_appid === ''){
            return false;
            die;
        }
        $cache_data = $this->memc->get($authorizer_appid.'_authorizer_access_token');
        if(!$cache_data){
            $authorizer_refresh_token = $this->memc->get($authorizer_appid.'_authorizer_refresh_token');
            if(!$authorizer_refresh_token){
                $authorizer_refresh_token = $this->db->get('authorizer','refresh_token',['appid'=>$authorizer_appid]);
            }
            $component_appid = $this->component_appid;
            $param = self::json_encode(array(
                'component_appid'          => $component_appid,
                'authorizer_appid'         => $authorizer_appid,
                'authorizer_refresh_token' => $authorizer_refresh_token
            ));
            $url = 'https://api.weixin.qq.com/cgi-bin/component/api_authorizer_token?component_access_token='.$this->component_access_token;
            $res = $this->httpPost($url,$param);
            if($res){
                $res = json_decode($res,true);
                if (!$res || isset($res['errcode'])) {
                    $this->errCode = $res['errcode'];
                    $this->errMsg  = $res['errmsg'];
                    return false;
                }else{
                   $authorizer_access_token = $res['authorizer_access_token'];                
                   $expire_in               = $res['expires_in'] ? intval($res['expires_in']) - 200 : 7000;
                   $refresh_token           = $res['authorizer_refresh_token'];
                   $expire_time             = $expire_in + time();
                   //存入数据库，使得authorizer_refresh_token持久化
                   $this->db->update(
                       'authorizer',
                       ['expire_time'=>$expire_time,'access_token'=>$authorizer_access_token,'refresh_token'=>$refresh_token],
                       ['appid'=>$authorizer_appid]
                   );
                   //存入memcached
                   $this->memc->set($authorizer_appid.'_authorizer_access_token',$authorizer_access_token,$expire_in);
                   $this->memc->set($authorizer_appid.'_authorizer_refresh_token',$refresh_token,$expire_in);
                   return $authorizer_access_token; 
               }                
            }else{
                return false;
            }
        }else{
            return $cache_data;
        }
    }

    /**
     * 获取component_verify_ticket
     * @return string
     */
    public function getComponentVerifyTicket(){
        $component_verify_ticket = $this->memc->get($this->prefix.'verify_ticket');
        if(!$component_verify_ticket){
            $component_verify_ticket = $this->db->get(
                                                    $this->cache_table_name,
                                                    'component_verify_ticket',
                                                    ['component_appid'=>$this->component_appid]
                                                );
            $this->memc->set($this->prefix.'verify_ticket',$component_verify_ticket,600);
        }
        return $component_verify_ticket;
    }

    /**
     * 获取memcached实例
     * @param string $server
     * @return Memcached
     */
    private function getMemcached($server=''){
        //实现单例
        static $memc;
        if(!$memc instanceof Memcached){
            $memc = new Memcached('ocs');  //声明一个新的memcached链接,这里的ocs就是persistent_id
            if (count($memc->getServerList()) == 0) /*建立连接前，先判断*/
            {
                //echo "New connection"."";
                /*所有option都要放在判断里面，因为有的option会导致重连，让长连接变短连接！*/
                $memc->setOption(Memcached::OPT_COMPRESSION, false);
                $memc->setOption(Memcached::OPT_BINARY_PROTOCOL, true);
                /* addServer 代码必须在判断里面，否则相当于重复建立’ocs’这个连接池，可能会导致客户端php程序异常*/
                $memc->addServer('', 11211); //添加OCS实例地址及端口号
                $memc->setSaslAuthData('', ''); //设置OCS帐号密码进行鉴权,如已开启免密码功能，则无需此步骤
            }
            else
            {
                //echo "Now connections is:".count($memc->getServerList())."";
            }            
        }                       
        return $memc;
    }


    /**
     * 显示错误信息
     * @return string
     */
    public function getErrorInfo(){
        return '错误编号为:'.$this->errCode.',错误信息为:'.$this->errMsg;
    }

    /**
     * http get请求
     * @param string $url
     * @return string
     */
    private function httpGet($url) {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 500);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_URL, $url);
        $res = curl_exec($curl);
        curl_close($curl);
        return $res;
    }

    /**
     * POST 请求
     * @param string $url
     * @param array $param
     * @param boolean $post_file 是否文件上传
     * @return string content
     */
    private function httpPost($url,$param,$post_file=false){
        $oCurl = curl_init();
        if(stripos($url,"https://")!==FALSE){
            curl_setopt($oCurl, CURLOPT_SSL_VERIFYPEER, FALSE);
            curl_setopt($oCurl, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($oCurl, CURLOPT_SSLVERSION, 1); //CURL_SSLVERSION_TLSv1
        }
        if (is_string($param) || $post_file) {
            $strPOST = $param;
        } else {
            $aPOST = array();
            foreach($param as $key=>$val){
                $aPOST[] = $key."=".urlencode($val);
            }
            $strPOST =  join("&", $aPOST);
        }
        curl_setopt($oCurl, CURLOPT_URL, $url);
        curl_setopt($oCurl, CURLOPT_RETURNTRANSFER, 1 );
        curl_setopt($oCurl, CURLOPT_POST,true);
        curl_setopt($oCurl, CURLOPT_POSTFIELDS,$strPOST);
        $sContent = curl_exec($oCurl);
        $aStatus = curl_getinfo($oCurl);
        curl_close($oCurl);
        if(intval($aStatus["http_code"])==200){
            return $sContent;
        }else{
            return false;
        }
    }

    /**
     * 微信api不支持中文转义的json结构
     * @param array $arr
     */
    static function json_encode($arr) {
        $parts = array ();
        $is_list = false;
        //Find out if the given array is a numerical array
        $keys = array_keys ( $arr );
        $max_length = count ( $arr ) - 1;
        if (($keys [0] === 0) && ($keys [$max_length] === $max_length )) { //See if the first key is 0 and last key is length - 1
            $is_list = true;
            for($i = 0; $i < count ( $keys ); $i ++) { //See if each key correspondes to its position
                if ($i != $keys [$i]) { //A key fails at position check.
                    $is_list = false; //It is an associative array.
                    break;
                }
            }
        }
        foreach ( $arr as $key => $value ) {
            if (is_array ( $value )) { //Custom handling for arrays
                if ($is_list)
                    $parts [] = self::json_encode ( $value ); /* :RECURSION: */
                else
                    $parts [] = '"' . $key . '":' . self::json_encode ( $value ); /* :RECURSION: */
            } else {
                $str = '';
                if (! $is_list)
                    $str = '"' . $key . '":';
                //Custom handling for multiple data types
                if (is_numeric ( $value ) && $value<2000000000)
                    $str .= $value; //Numbers
                elseif ($value === false)
                    $str .= 'false'; //The booleans
                elseif ($value === true)
                    $str .= 'true';
                else
                    $str .= '"' . addslashes ( $value ) . '"'; //All other things
                // :TODO: Is there any more datatype we should be in the lookout for? (Object?)
                $parts [] = $str;
            }
        }
        $json = implode ( ',', $parts );
        if ($is_list)
            return '[' . $json . ']'; //Return numerical JSON
        return '{' . $json . '}'; //Return associative JSON
    }
}