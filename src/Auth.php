<?php
/**
 *-------------------------------------------------------------------------p*
 * 用户信息自动维护
 *-------------------------------------------------------------------------h*
 * @copyright  Copyright (c) 2015-2022 Shopwwi Inc. (http://www.shopwwi.com)
 *-------------------------------------------------------------------------c*
 * @license    http://www.shopwwi.com        s h o p w w i . c o m
 *-------------------------------------------------------------------------e*
 * @link       http://www.shopwwi.com by 象讯科技 phcent.com
 *-------------------------------------------------------------------------n*
 * @since      shopwwi象讯·PHP商城系统Pro
 *-------------------------------------------------------------------------t*
 */

namespace Shopwwi\WebmanAuth;

use Shopwwi\WebmanAuth\Exception\JwtTokenException;
use Shopwwi\WebmanAuth\Facade\JWT as JwtFace;
use Shopwwi\WebmanAuth\Facade\Str;
use support\Redis;

class Auth
{
    /**
     * 未携带 token 报错
     * @var bool
     */
    protected $fail = false;
    /**
     * 自定义角色
     * @var string
     */
    protected $guard = 'user';
    /**
     * 配置信息
     * @var array|mixed
     */
    protected $config = [];

    /**
     * token过期时间
     * @var int
     */
    protected $accessTime = 0;
    protected $refreshTime = 0;

    /**
     * 构造方法
     * @access public
     */
    public function __construct()
    {
        $_config = config('plugin.shopwwi.auth.app');
        if (empty($_config)) {
            throw new JwtTokenException('The configuration file is abnormal or does not exist');
        }
        $this->config = $_config;
    }

    /**
     * 设置当前角色
     * @param string $name
     * @return $this
     */
    public function guard(string $name):Auth
    {
        $this->guard = $name;
        return $this;
    }

    /**
     * 单独设定token过期时间
     * @param int $num
     * @return $this
     */
    public function accessTime(int $num): Auth
    {
        $this->accessTime = $num;
        return $this;
    }

    /**
     * 单独设定刷新token过期时间
     * @param int $num
     * @return $this
     */
    public function refreshTime(int $num): Auth
    {
        $this->refreshTime = $num;
        return $this;
    }


    /**
     * 输出报错
     * @param bool $error
     * @return Auth
     */
    public function fail(bool $error = true): Auth
    {
        $this->fail = $error;
        return $this;
    }

    /**
     * 登入信息自动验证
     * @param array $data
     * @return false|mixed
     */
    public function attempt(array $data)
    {
        try {
            if(is_array($data)) {
                $user = $this->getUserClass();
                if($user == null) throw new JwtTokenException('模型不存在');
                foreach ($data as $key=>$val){
                    if($key !== 'password'){
                        $user = $user->where($key,$val);
                    }
                }
                $user = $user->first();
                if($user != null){
                    if(isset($data['password'])){
                        if(!password_verify($data['password'],$user->password)){
                            throw new JwtTokenException('密码错误');
                        }
                    }
                    return  $this->login($user);
                }
            }
            throw new JwtTokenException('数据类型不正确');
        }catch (JwtTokenException $e){
            if($this->fail){
                throw new JwtTokenException($e->getMessage(),$e->getCode());
            }
            return false;
        }
    }

    /**
     * 获取用户模型
     * @return mixed|null
     */
    protected function getUserClass(){
        $guardConfig = $this->config['guard'][$this->guard]['model'];
        if(!empty($guardConfig)){
            return new $guardConfig;
        }
        return null;
    }

    /**
     * 获取会员信息
     * @return mixed|null
     */
    public function user($cache = false)
    {
        try {
            $key = $this->config['guard'][$this->guard]['key']; //获取主键
            $extend = JwtFace::getTokenExtend();
            if(isset($extend->extend) && !empty($extend->extend) && isset($extend->extend->$key)){
                if($cache){
                    return $extend->extend;
                }else{
                    $user = $this->getUserClass();
                    return $user->where($key,$extend->extend->$key)->first();
                }

            }
            throw new JwtTokenException('配置信息异常',401);
        }catch (JwtTokenException $e){
            if($this->fail){
                return $e;
            }
            return null;
        }
    }

    /**
     * 登入并获取Token
     * @param $data
     * @return null|JWT
     */
    public function login($data): ?JWT
    {
        $fields = $this->config['guard'][$this->guard]['field']; //允许使用的数据
        $idKey = $this->config['guard'][$this->guard]['key']; //获取主键
        $newData = [];
        // 过滤存储数据
        if(is_object($data)){
            foreach ($fields as $key){
                if(isset($data->$key)){
                    $newData[$key] = $data->$key;
                }
            }
        }elseif(is_array($data) && count($data) > 0){
            foreach ($fields as $key){
                if(isset($data[$key])){
                    $newData[$key] = $data[$key];
                }
            }
        }

        try {
            if(!isset($newData[$idKey])){
                throw new JwtTokenException('缺少必要主键',400);
            }
            return JwtFace::make($newData,$this->accessTime,$this->refreshTime);
        }catch (JwtTokenException $e){
            if($this->fail){ //当设定自动报错
                throw new JwtTokenException($e->getError(),$e->getCode());
            }
            return null;
        }
    }

    /**
     * 刷新令牌
     * @return false|JWT
     */
    public function refresh()
    {
        try {
            return JwtFace::refresh($this->accessTime);
        }catch (JwtTokenException $e){
            if($this->fail){ //当设定自动报错
                throw new JwtTokenException($e->getError(),$e->getCode());
            }
            return false;
        }
    }

    /**
     * 退出登入
     */
    public function logout($all = false)
    {
        try {
            return JwtFace::logout($all);
        }catch (JwtTokenException $e){
            if($this->fail){ //当设定自动报错
                throw new JwtTokenException($e->getError(),$e->getCode());
            }
            return false;
        }
    }
    /**
     * 生成JWT密钥
     * @return void
     * @throws \Exception
     */
    public function jwtKey()
    {
        $key = Str::random(64);
        file_put_contents(base_path()."/config/plugin/shopwwi/auth/app.php", str_replace(
            "'access_secret_key' => '".config('plugin.shopwwi.auth.app.jwt.access_secret_key')."'",
            "'access_secret_key' => '".$key."'",
            file_get_contents(base_path()."/config/plugin/shopwwi/auth/app.php")
        ));
        file_put_contents(base_path()."/config/plugin/shopwwi/auth/app.php", str_replace(
            "'refresh_secret_key' => '".config('plugin.shopwwi.auth.app.jwt.refresh_secret_key')."'",
            "'refresh_secret_key' => '".$key."'",
            file_get_contents(base_path()."/config/plugin/shopwwi/auth/app.php")
        ));
        echo $key;
    }

    /**
     * 加密密码
     * @param $password
     * @return string|null
     */
    public function bcrypt($password): ?string
    {
        $key = config('plugin.shopwwi.auth.app.app_key');
        if (Str::startsWith($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }
        var_dump($key);
        $hash = password_hash($password, PASSWORD_BCRYPT, [
            'cost' => $key,
        ]);

        if ($hash === false) {
            throw new JwtTokenException('Bcrypt hashing not supported.');
        }

        return $hash;
    }

    /**
     * 动态方法 直接调用is方法进行验证
     * @access public
     * @param string $method 方法名
     * @param array $args   调用参数
     * @return bool
     */
    public function __call(string $method, array $args)
    {
        if ('is' == strtolower(substr($method, 0, 2))) {
            $method = substr($method, 2);
        }

        $args[] = lcfirst($method);

        return call_user_func_array([$this, 'is'], $args);
    }

}