<?php

namespace Wekyun\WebmanLib;

use Webman\Config;
use Wekyun\WebmanLib\common\exception\CheckException;

/**
 * @title TP验证类
 * Class Check
 * @package Wekyun
 */
class Check extends \Webman\Http\Request
{
    private static $config = [];//配置池(各个插件和官方配置隔离),用过就会被缓存下次用直接取值
    private static $class = [];//验证规则对象池(各个插件和官方隔离)

    private static $err_code;
    private static $err_func;
    private static $plugin_name;

    private static $setFieldVal = '';

    //判断是否有值
    private static function check_isset_value($param, $check_key): bool
    {
        if (!isset($param[$check_key])) {
            return false;
        }
        if (is_string($param[$check_key]) && strlen($param[$check_key]) > 0) {
            return true;
        }

        if (is_array($param[$check_key]) && count($param[$check_key]) > 0) {
            return true;
        }

        if (!$param[$check_key]) {
            if ($param[$check_key] === 0 || $param[$check_key] === '0') {
                return true;
            }
            return false;
        }
        return true;
    }

    /**
     * 验证Get和Post集合的参数
     * @autho hugang
     * @param string|null $name 场景类名
     * @param null $param
     * @return array|验证Get和Post集合的参数
     */
    public static function checkAll(string $name = null, $param = null)
    {
        return self::checkBase($name, $param);
    }

    /**
     * 验证Get的参数
     * @autho hugang
     * @param string|null $name 场景类名
     * @param null $param
     * @return array|验证Get的参数
     */
    public static function checkGet(string $name = null, $param = null)
    {
        return self::checkBase($name, $param);
    }

    /**
     * 验证Post的参数
     * @autho hugang
     * @param string|null $name 场景类名
     * @param null $param
     * @return array|验证Post的参数
     */
    public static function checkPost(string $name = null, $param = null)
    {
        return self::checkBase($name, $param);
    }

    /**
     * 只接收指定的字段进行验证(get和post的参数都接受)
     * @autho hugang
     * @param string|null $name 场景类名
     * @param null $param
     * @return array|只接收指定的get和post字段进行验证
     */
    public static function checkOnlyAll(string $name = null, $param = null)
    {
        return self::checkBase($name, $param, 'all', true);
    }

    /**
     * 只接收指定的字段进行验证(只接受get的参数)
     * @autho hugang
     * @param string|null $name 场景类名
     * @param null $param
     * @return array|只接收指定的get字段进行验证
     */
    public static function checkOnlyGet(string $name = null, $param = null)
    {
        return self::checkBase($name, $param, 'get', true);
    }

    /**
     * 只接收指定的字段进行验证(只接受post的参数)
     * @autho hugang
     * @param string|null $name 场景类名
     * @param null $param
     * @return array|只接收指定的post字段进行验证
     */
    public static function checkOnlyPost(string $name = null, $param = null)
    {
        return self::checkBase($name, $param, 'post', true);
    }

    //获取所有参数：Post和Get的集合
    private static function get_all_data($type)
    {
        $data = null;
        $r = request();
        switch ($type) {
            case 'get':
                $data = $r->get();
                break;
            case 'post':
                if (self::$setFieldVal) {
                    $data = $r->post(self::$setFieldVal);
                } else {
                    $data = $r->post();
                }
                break;
            case 'all':
            default:
                if (self::$setFieldVal) {
                    $data = $r->all(self::$setFieldVal);
                } else {
                    $data = $r->all();
                }
                break;
        }
        if (self::$setFieldVal != '') {
            return $data[self::$setFieldVal];
        }
        return $data;
    }

    //获取当前运行插件或者非插件的配置
    protected static function get_this_config_data()
    {
        $plugin_name = self::$plugin_name;
        return self::$config[$plugin_name] ?? [];
    }

    //
    protected static function get_this_class_obj($name)
    {
        $plugin_name = self::$plugin_name;
        $class_name = $plugin_name . '_' . $name;
        return self::$class[$class_name] ?? false;
    }

    protected static function save_this_class_obj($name, $obj)
    {
        $plugin_name = self::$plugin_name;
        self::$class[$plugin_name . '_' . $name] = $obj;
    }

    /**
     * @autho hugang
     * @param null $name 场景类名
     * @param null $param
     * @param string $param_type
     * @param bool $is_only
     * @return array|mixed|null
     * @throws CheckException
     */
    protected static function checkBase($name = null, $param = null, string $param_type = 'all', bool $is_only = false)
    {
        $new_data = [];//最终接收的参数
        //参数为空，接收所有参数
        $input = self::get_all_data($param_type);
        if (!$name && !$param) return $input;
        $dfCconfigKey = 'webman_guanfang_Confgig_999';
        $plugin_name = $dfCconfigKey;//这是一个不会重复的名称,每个应用插件都有一个唯一标识，这个标识由字母组成。
        list(, $caller) = debug_backtrace(false, 2);
        if (strpos($caller['file'], 'plugin') !== false) {//插件中运行
            $result_str = substr($caller['file'], strripos($caller['file'], "plugin") + 1);
            $plugin_res_array = explode(DIRECTORY_SEPARATOR, $result_str);
            $plugin_name = $plugin_res_array[1];
        }
        self::$plugin_name = $plugin_name;
        $config = self::get_this_config_data();
        $configErr = '';
        if ($config == []) {
            //加载
            if ($plugin_name == $dfCconfigKey) {//非插件
                $config = config('check');
                $configErr = '系统';
            } else {
                $config = config('plugin.' . $plugin_name . '.check');
                $configErr = '插件[' . $plugin_name . ']';
            }
            if (!$config) return self::err_json($configErr . ' check验证配置为空');
            if (!isset($config['mapping'])) return self::err_json('验证配置:mapping 未定义');
            if (count($config['mapping']) == 0) return self::err_json('验证配置:mapping 值为空');
            self::$err_code = $config['err_code'] ?? 203;
            self::$err_func = $config['err_func'] ?? false;
            self::$config[$plugin_name] = $config;
        }
        $obj = self::get_this_class_obj($name);
        if (!$obj) {
            $class = $config['mapping'][$name];
            try {
                $obj = new $class();
                self::save_this_class_obj($name, $obj);
            } catch (\Exception $e) {
                return self::err_json('验证规则配置文件错误，请检查验证配置文件mapping设置的验证器路径是否正确；' . $e->getMessage());
            }
        }
        if (is_string($param)) {
            $param = explode(',', $param);
        }
        if (!is_array($param)) {
            return self::err_json('接收参数请传递数组或者字符串');
        }
        //处理：是不是必传，按照什么验证
        $check_param = [];//指定要接收的参数
        foreach ($param as $value) {
            if (strpos($value, '>.') !== false) {
                return self::err_json('验证规则书写错误了 . 必须在 > 前面');
            }
            $value_more = explode('.', $value);//0是key部分未解析的，1是默认值和提示部分的
            $check_key = null;
            $vali_data = [
                'key' => '',
                'def_val' => '',
                'tips_name' => '',
                'tips_msg' => '',
                'is_check_rule' => true,//是否验证规则
                'is_mast' => false,
            ];
            $is_mast = false;
            if (count($value_more) == 1) {
                //不是必传
                $value_more1_rule_val = explode(':', $value_more[0]);
                if (count($value_more1_rule_val) == 2) {
                    $value_more[0] = $value_more1_rule_val[0];
                    $value_more[1] = $value_more1_rule_val[1];
                    $vali_data['def_val'] = $value_more1_rule_val[1];

                    if (strpos($value_more[1], '>') !== false) {
                        $value_more1_def_val = explode('>', $value_more[1]);
                        $vali_data['def_val'] = $value_more1_def_val[0];
                        $vali_data['tips_msg'] = $value_more1_def_val[1];
                    }
                } else {
                    //只有自定义提示
                    if (strpos($value_more1_rule_val[0], '>') !== false) {
                        $value_more1_tip_val = explode('>', $value_more1_rule_val[0]);
                        $value_more[0] = $value_more1_tip_val[0];
                        $vali_data['tips_msg'] = $value_more1_tip_val[1];
                    }
                }
            } else {
                //必传
                $is_mast = true;
                if (strpos($value_more[1], ':') !== false) {
                    return self::err_json('必填项只能设置错误提示，不能设置默认值');
                }
                if (strpos($value_more[1], '>') !== false) {
                    $value_more1_def_val = explode('>', $value_more[1]);
                    $vali_data['tips_msg'] = $value_more1_def_val[1];
                    $vali_data['def_val'] = '';
                }
            }

            $vali_data['key'] = $value_more[0];

            if (strpos($vali_data['key'], '|') !== false) {
                $value_more0_val = explode('|', $vali_data['key']);
                if (!self::is_mast($value_more0_val[1])) {
                    return self::err_json('使用|设置的提示名称,不能为空。|后面应该定义提示的名称');
                }
                $vali_data['key'] = $value_more0_val[0];
                $vali_data['tips_name'] = $value_more0_val[1];
            }

            $data_log_name_more = explode('!', $vali_data['key']);
            $is_check_rule = false;
            if (count($data_log_name_more) == 2) {
                //指定不验证这个参数的格式
                $vali_data['key'] = $data_log_name_more[1];
            } else {
                $is_check_rule = true;
                $vali_data['key'] = $data_log_name_more[0];
            }

            $check_key = $vali_data['key'];
            if (!self::check_isset_value($input, $check_key)) {
                if ($is_mast) {//必传
                    if ($vali_data['tips_msg'] != '') {
                        return self::err_json($vali_data['tips_msg']);
                    } else {
                        $tps = $vali_data['tips_name'] ? $vali_data['tips_name'] : $check_key;
                        return self::err_json($tps . '不可为空');
                    }
                } else {
                    //默认参数和空,不验证参数因为不是传递过来的参数,这个是后端自己定义的,说明是可以用的,比如手机不传就为0,就是可以的,传就必须是格式正确的
                    $is_check_rule = false;
                    if ($vali_data['def_val']) {
                        $new_data[$check_key] = $vali_data['def_val'];
                    } else {
                        $new_data[$check_key] = '';
                    }
                }
            } else {
                $new_data[$check_key] = $input[$check_key];
            }

            if ($is_check_rule) {
                if (!$obj->check($new_data)) {
                    $err_msg = $obj->getError();
                    if ($vali_data['tips_name']) {
                        $err_msg = str_replace($check_key, $vali_data['tips_name'], $err_msg);
                    }
                    return self::err_json($err_msg);
                }
            }
        }
        //删除指定的集合字段参数
        self::$setFieldVal = '';
        if ($is_only) {
            return $new_data;
        }
        return array_merge($input, $new_data);
    }

    //错误提示
    private static function err_json($msg)
    {
        //删除指定的集合字段参数
        self::$setFieldVal = '';
        $err_func = self::$err_func;
        if ($err_func instanceof \Closure) {
            return $err_func($msg, self::$err_code);
        }
        throw new CheckException($msg, self::$err_code);
    }

    /** 验证变量是否存在有值
     * @autho hugang
     * @param string $val 场景类名
     * @return 验证变量是否存在有值
     * */
    private static function is_mast(string $val): bool
    {
        if (strlen($val) > 0) {
            return true;
        }
        return false;
    }


}