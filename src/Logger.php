<?php
/**
 * Logger 日志记录类
 *
 *     //use demo
 *     use Lib\Log\Logger;
 *
 *     //开发调试使用
 *     Logger::debug('debug print is ok',array('uid'=>1,'name'=>'Tom'));
 *
 *     //业务记录使用
 *     Logger::info('this is order about money',array('sql'=>'INSERT INTO ......','orderid'=>'123456789'));
 *
 *     //错误信息记录
 *     Logger::error('this is a big error',array('here'=>'goods'));
 *
 *      //自定义文件记录
 *      Logger::custom('this is a custom'.array('test'=>1),'test');
 *
 *
 * ```
 *      2016-07-08 12:04:50|Common.Desc.TestLog.Index|DEBUG|debug test |{"printdata":"debug test","requesttime":1467950690}
 *      2016-07-08 12:04:50|Common.Desc.TestLog.Index|INFO|info test |{"printdata":"info data","post":{"method":"Common.Desc.TestLog.Index","appkey":"23067374","v":"1","aaa":"222"}}
 *      2016-07-08 12:04:50|Common.Desc.TestLog.Index|ERROR|error test |{"printdata":"error data"}
 *
 * ```
 */
namespace hnLog;
class Logger
{
    const EMERGENCY = 'emergency';
    const ALERT     = 'alert';
    const CRITICAL  = 'critical';
    const ERROR     = 'error';
    const WARNING   = 'warning';
    const NOTICE    = 'notice';
    const INFO      = 'info';
    const DEBUG     = 'debug';

    protected static $levels = array(
        self::DEBUG,
        self::INFO,
        self::NOTICE,
        self::WARNING,
        self::ERROR,
        self::CRITICAL,
        self::ALERT,
        self::EMERGENCY
    );

    // 兼容 ext 方法
    protected static $logExtra = false;
    // 自定义日志类型
    protected static $logType = '';

    // 日志记录等级
    protected static $atleastLevel = self::DEBUG;

    protected static $currentIP;
    protected static $requestId;

    protected static $appgroup = ''; // 手动设置业务名称
    protected static $appname = ''; // 手动设置产品名称

    public static function refreshRequestId($identifier = null)
    {
        if (!empty($identifier)) {
            self::$requestId = $identifier;
        } else {
            // 某些处理
            $identifier = (string) isset($_SERVER['REQUEST_TIME_FLOAT'])
                ? $_SERVER['REQUEST_TIME_FLOAT'] : $_SERVER['REQUEST_TIME'];

            if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $identifier .= $_SERVER['HTTP_X_FORWARDED_FOR'];
            } elseif (isset($_SERVER['REMOTE_ADDR'])) {
                $identifier .= $_SERVER['REMOTE_ADDR'];
            }

            $identifier .= microtime(true) . rand(100, 999);

            $requestId = md5($identifier);

            self::$requestId = $requestId;
        }

        return self::$requestId;
    }

    public static function getRequestId()
    {
        if (empty(self::$requestId)) {
            self::refreshRequestId();
        }

        return self::$requestId;
    }

    public static function setLogLevel($atleastLevel = self::INFO)
    {
        self::$atleastLevel = $atleastLevel;
    }
    /**
     * 日志等级
     * @param string $level
     * @return boolean
     */
    public static function isReachedLogLevel($level)
    {
        return \array_search($level, self::$levels) >= \array_search(self::$atleastLevel, self::$levels);
    }

    /**
     * 初始化业务文件夹/自定义
     * @param string $appgroup test OR test/test2;
     * @return [type] [description]
     */
    public static function setAppGroup($appgroup)
    {
        self::$appgroup = $appgroup;
    }
    /**
     * 单独设置appname 便于生成想要的文件夹日志
     * @param string $appgroup test OR test/test2;
     * @return [type] [description]
     */
    public static function setAppName($appname)
    {
        self::$appname = $appname;
    }

    /**
     * 初始化日志文件
     * @return [type] [description]
     */
    protected static function initLogFile($level, $extra = '')
    {
        $folderFormat = implode(DIRECTORY_SEPARATOR, array(
            '$base$extra', '$appname', '$appgroup', date('Ym')
        ));
        $fileFormat = date('Ymd') . '_$suffix$name.log';


        self::$appgroup = self::$appgroup ? self::$appgroup : implode(DIRECTORY_SEPARATOR, array(GROUP_NAME, MODULE_NAME));
        self::$appname = self::$appname ? self::$appname : (defined('HN_APPNAME') ? HN_APPNAME : "noAppName");

        // 命名前缀扩展
        $suffix = '';
        if (self::$logExtra) {
            $apimethod = ACTION_NAME;
            $suffix .= $apimethod . '_';
        }
        // 文件命名
        $name = $level;
        if (!empty(self::$logType) && is_scalar(self::$logType)) {
            $name = self::$logType;
        }

        // 获取到的填充元数据
        $metadata = array(
            '$base' => HN_LOG_PATH,
            '$extra' => $extra,
            '$appname' => self::$appname,
            '$appgroup' => self::$appgroup,
            '$suffix' => $suffix,
            '$name' => $name,
        );

        // **目录名**
        $folder = str_replace(array_keys($metadata), array_values($metadata), $folderFormat);

        // **文件名**
        $logFile = $folder . DIRECTORY_SEPARATOR . str_replace(array_keys($metadata), array_values($metadata), $fileFormat);

        // 创建 目录
        if (!file_exists($folder)) {
            self::mkdirMode($folder . '/', 0777, true);
        }
        // 创建 文件
        if (!file_exists($logFile)) {
            touch($logFile);
            chmod($logFile, 0777);
        }

        return $logFile;
    }

    protected static function format($data, $format)
    {
        if (strtolower($format) == 'json') {
            return self::myJsonEncode($data);
        } elseif (strtolower($format) == 'plain') {
            return implode('|', array(
                $data['log_time'],
                $data['function'],
                $data['log_level'],
                $data['request_id'],
                str_replace(PHP_EOL, '\n', $data['title']),
                isset($data['description']['full_description'])
                    ? $data['description']['full_description']
                    : self::myJsonEncode($data['description'])
            ));
        }
    }

    /**
     * 将日志写入文件
     * @param  [type] $type       [description]
     * @param  [type] $msg        [description]
     * @param  [type] $data       [description]
     * @return [type]             [description]
     */
    protected static function log($level, $msg, $context)
    {
        if (!self::isReachedLogLevel($level)) {
            return;
        }

        $data = array(
            'log_time' => date('Y-m-d H:i:s') . substr((string) microtime(), 1, 4),
            'log_level' => strtoupper($level),
            'log_type' => self::$logType,

            // 请求ID
            'request_method' => GROUP_NAME . '/' . MODULE_NAME . '/' . ACTION_NAME,
            'request_id' => self::getRequestId(),

            'appname' => defined('HN_APPNAME') ? HN_APPNAME : "noAppName",
            'appname_set' => !empty(self::$appname) ? self::$appname : (defined('HN_APPNAME') ? HN_APPNAME : "noAppName"),

            'module' => GROUP_NAME,
            'module_class' => implode(DIRECTORY_SEPARATOR, array(GROUP_NAME, MODULE_NAME)),
            'module_set' => self::$appgroup,
            'function' => ACTION_NAME,

            'title_q' => $msg,
            'title' => '' . preg_replace_callback('/\{\<([\w\d]+)\>\}/', function ($match) use ($context) {
                return is_array($context) && isset($context[$match[1]]) ? $context[$match[1]] : $match[1];
            }, $msg),
            // 详情
            'description' => self::contextFormat($context),
        );


        $file = self::initLogFile($level, 'new');
        $content = self::format($data, 'json') . PHP_EOL;

        file_put_contents($file, $content, FILE_APPEND);
    }

    /**
     * 开发调试使用
     */
    public static function debug($msg, $data = null)
    {
        self::log(self::DEBUG, $msg, $data);
    }

    /**
     * 业务记录使用
     */
    public static function info($msg, $data = null)
    {
        self::log(self::INFO, $msg, $data);
    }

    public static function notice($msg, $data = null)
    {
        self::log(self::NOTICE, $msg, $data);
    }

    public static function warning($msg, $data = null)
    {
        self::log(self::WARNING, $msg, $data);
    }

    /**
     * 错误记录使用
     */
    public static function error($msg, $data = null)
    {
        self::log(self::ERROR, $msg, $data);
    }

    /**
     * 自定义日志记录
     * @param  string $msg      描述
     * @param  array/string $data     内容
     * @param  string $logType 日志类型 (info.customType)
     * @return [type]           [description]
     */
    public static function custom($msg, $data = null, $logType = null)
    {

        if ($logType == null || empty($logType)) {
            exit('自定义日志记录文件名错误');
        }

        self::$logType = $logType;
        self::log(self::INFO, $msg, $data);
        // 用完重置 'logType'
        self::$logType = '';
    }

    /**
     * API错误记录使用  - fenglj
     * @param  string $msg  记录描述
     * @param  array/string $data 记录数据
     * @return [type]       [description]
     */
    public static function apierror($msg, $data = null)
    {
        self::custom($msg, $data, 'apierror');
    }


    // 兼容之前加 apimethod
    protected static function logext($level, $msg, $data)
    {
        self::$logExtra = true;
        self::log($level, $msg, $data);
        self::$logExtra = false;
    }
    public static function infoext($msg, $data = null)
    {
        self::logext(self::INFO, $msg, $data);
    }


    public static function prettyPrint($json)
    {
        $result = '';
        $level = 0;
        $in_quotes = false;
        $in_escape = false;
        $ends_line_level = null;
        $json_length = strlen($json);

        for ($i = 0; $i < $json_length; $i++) {
            $char = $json[$i];
            $new_line_level = null;
            $post = "";
            if ($ends_line_level !== null) {
                $new_line_level = $ends_line_level;
                $ends_line_level = null;
            }
            if ($in_escape) {
                $in_escape = false;
            } else if ($char === '"') {
                $in_quotes = !$in_quotes;
            } else if (!$in_quotes) {
                switch ($char) {
                    case '}':case ']':
                        $level--;
                        $ends_line_level = null;
                        $new_line_level = $level;
                        break;

                    case '{':case '[':
                        $level++;
                    case ',':
                        $ends_line_level = $level;
                        break;

                    case ':':
                        $post = " ";
                        break;

                    case " ":case "\t":case "\n":case "\r":
                        $char = "";
                        $ends_line_level = $new_line_level;
                        $new_line_level = null;
                        break;
                }
            } else if ($char === '\\') {
                $in_escape = true;
            }
            if ($new_line_level !== null) {
                $result .= "\n" . str_repeat("\t", $new_line_level);
            }
            $result .= $char . $post;
        }

        return $result;
    }

    public static function myJsonEncode($input)
    {
        // 从 PHP 5.4.0 起, 增加了这个选项.
        if (defined('JSON_UNESCAPED_UNICODE')) {
            return json_encode($input, JSON_UNESCAPED_UNICODE);
        }
        if (is_string($input)) {
            $text = $input;
            $text = str_replace('\\', '\\\\', $text);
            $text = str_replace(
                array("\r", "\n", "\t", "\""),
                array('\r', '\n', '\t', '\\"'),
                $text);
            return '"' . $text . '"';
        } else if (is_array($input) || is_object($input)) {
            $arr = array();
            $is_obj = is_object($input) || (array_keys($input) !== range(0, count($input) - 1));
            foreach ($input as $k => $v) {
                if ($is_obj) {
                    $arr[] = self::myJsonEncode($k) . ':' . self::myJsonEncode($v);
                } else {
                    $arr[] = self::myJsonEncode($v);
                }
            }
            if ($is_obj) {
                return '{' . join(',', $arr) . '}';
            } else {
                return '[' . join(',', $arr) . ']';
            }
        } else {
            return $input . '';
        }
    }

    public static function contextFormat($context)
    {
        // 数组
        if (is_array($context)) {
            // 关联数组 并且 可直接作为对象提供
            if (
                array_keys($context) !== range(0, count($context) - 1)
                && count($context) < 30
                && count(array_filter(array_keys($context), 'is_string')) > 0
            ) {
                // 遍历处理二级属性，直接转为字符串
                foreach ($context as &$value) {
                    $value = is_array($value)
                        ? self::prettyPrint(self::myJsonEncode($value))
                        : (is_scalar($value) ? '' . $value : serialize($value));
                }

                return $context;
            } else {
                // 非关联数组
                return array(
                    'full_description' => self::prettyPrint(self::myJsonEncode($context))
                );
            }

        } else {
            // 标量以及对象序列化
            return array(
                'full_description' => (is_scalar($context) || is_null($context)) ? '' . $context : serialize($context)
            );
        }
    }

    /**
     * 跳过umask限制 设置文件夹目录
     * @param $dir
     * @param int $mode
     * @param $recursive
     */
    protected static function mkdirMode($dir, $mode = 0777, $recursive)
    {
        $umask = umask(0);
        mkdir($dir, $mode, $recursive);
        umask($umask);
    }
}
