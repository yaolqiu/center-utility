<?php

namespace Lyqiu\CenterUtility;

use EasySwoole\Component\Di;
use EasySwoole\EasySwoole\Config;
use EasySwoole\EasySwoole\SysConst;
use EasySwoole\Http\Request;
use EasySwoole\Http\Response;
use EasySwoole\I18N\I18N;
use EasySwoole\ORM\DbManager;
use EasySwoole\Spl\SplBean;
use EasySwoole\Trigger\TriggerInterface;
use EasySwoole\Utility\File;
use Lyqiu\CenterUtility\Common\Classes\CtxRequest;
use Lyqiu\CenterUtility\Common\Classes\ExceptionTrigger;
use Lyqiu\CenterUtility\Common\Classes\LamUnit;
use Lyqiu\CenterUtility\HttpTracker\Index as HttpTracker;

class EventInitialize extends SplBean
{
    /**
     * @var TriggerInterface
     */
    protected $ExceptionTrigger = ExceptionTrigger::class;

    /**
     * @var string[]
     */
    protected $configDir = [EASYSWOOLE_ROOT . '/App/Common/Config'];

    /**
     * @var array
     */
    protected $mysqlConfig = null;

    protected $redisConfig = null;

    protected $mysqlOnQueryOpen = true;
    protected $mysqlOnQueryFunc = [
        '_before_func' => null, // 前置
        '_save_log' => true, // 保存日志
        '_save_sql' => null, // 自定义保存
        '_after_func' => null, // 后置
    ];

    protected $languageConfig = null;

    protected $httpOnRequestOpen = true;
    protected $httpOnRequestFunc = [
        '_before_func' => null, // 前置
        '_after_func' => null, // 后置
    ];

    protected $httpAfterRequestOpen = true;
    protected $httpAfterRequestFunc = [
        '_before_func' => null, // 前置
        '_after_func' => null, // 后置
    ];

    /**
     * 开启链路追踪，string-根节点名称, empty=false 不开启
     * @var null | string
     */
    protected $httpTracker = null;
    protected $httpTrackerConfig = [];

    /**
     * 设置属性默认值
     * @return void
     */
    protected function initialize(): void
    {
        if (is_null($this->mysqlConfig)) {
            $this->mysqlConfig = config('MYSQL');
        }
        if (is_null($this->redisConfig)) {
            $this->redisConfig = config('REDIS');
        }

        if (is_null($this->languageConfig)) {
            $this->languageConfig = config('LANGUAGES') ?: [];
        }
    }

    public function run()
    {
        $this->registerConfig();

        // 数据可能依赖新配置
        $this->initialize();

        $this->registerExceptionTrigger();
        $this->registerMysqlPool();
        $this->registerRedisPool();
        $this->registerMysqlOnQuery();
        $this->registerI18n();
        $this->registerHttpOnRequest();
        $this->registerAfterRequest();
    }

    /**
     * 注册异常处理器
     * @return void
     */
    protected function registerExceptionTrigger()
    {
        if ($this->ExceptionTrigger && class_exists($this->ExceptionTrigger)) {
            $className = $this->ExceptionTrigger;
            $class = new $className();
            \EasySwoole\EasySwoole\Trigger::getInstance($class);
        }
    }

    /**
     * 加载项目配置
     * @return void
     */
    protected function registerConfig()
    {
        if ( ! $arr = $this->configDir) {
            return;
        }
        settype($arr, 'array');
        $fileConfig = [];
        $config = Config::getInstance()->toArray();
        foreach ($arr as $item) {
            if (is_dir($item)) {
                // 遍历目录下的文件
                $scanResult = scandir($item);
                foreach ($scanResult as $files) {
                    if (in_array($files, ['.', '..'])) {
                        continue;
                    }
                    // 加载配置
                    is_file($realPath = "$item/$files") && is_array($_cfg = include($realPath)) && ($config = array_merge_multi($config, $_cfg));
                }
            } elseif (is_file($item)) {
                is_array($_cfg = include($item)) && ($fileConfig = array_merge_multi($fileConfig, $_cfg));
            }
        }

        // 文件配置优先级高于目录配置
        if ($fileConfig) {
            $config = array_merge_multi($config, $fileConfig);
        }
        Config::getInstance()->merge($config);
    }

    /**
     * 注册MySQL连接池
     * @return void
     */
    protected function registerMysqlPool()
    {
        $config = $this->mysqlConfig;
        print_R($config);
        if ( ! is_array($config)) {
            return;
        }
        foreach ($config as $mname => $mvalue) {
            DbManager::getInstance()->addConnection(
                new \EasySwoole\ORM\Db\Connection(new \EasySwoole\ORM\Db\Config($mvalue)),
                $mname
            );
        }
    }

    /**
     * 注册Redis连接池
     * @return void
     * @throws \EasySwoole\RedisPool\Exception\Exception
     * @throws \EasySwoole\RedisPool\RedisPoolException
     */
    protected function registerRedisPool()
    {
        $config = $this->redisConfig;
        print_r($config);
        if ( ! is_array($config)) {
            return;
        }
        foreach ($config as $rname => $rvalue) {
            $RedisPoolConfig = \EasySwoole\RedisPool\RedisPool::getInstance()->register(
                new \EasySwoole\Redis\Config\RedisConfig($rvalue),
                $rname
            );
            // 排序，maxObjectNum > minObjectNum
            ksort($rvalue);
            foreach ($rvalue as $key => $value) {
                $method = 'set' . ucfirst($key);
                if (method_exists($RedisPoolConfig, $method)) {
                    call_user_func([$RedisPoolConfig, $method], $value);
                }
            }
        }
    }

    /**
     * 注册MySQL全局OnQuery回调
     * @return void
     */
    protected function registerMysqlOnQuery()
    {
        if ( ! $this->mysqlOnQueryOpen) {
            return;
        }
        DbManager::getInstance()->onQuery(
            function (\EasySwoole\ORM\Db\Result $result, \EasySwoole\Mysqli\QueryBuilder $builder, $start) {
                // 前置
                if (is_callable($this->mysqlOnQueryFunc['_before_func'])) {
                    // 返回false不继续运行
                    if ($this->mysqlOnQueryFunc['_before_func']($result, $builder, $start) === false) {
                        return;
                    }
                }
                $sql = $builder->getLastQuery();
                if (empty($sql)) {
                    return;
                }

                // 除非显示声明_save_log不记录日志
                if ( ! isset($this->mysqlOnQueryFunc['_save_log']) || $this->mysqlOnQueryFunc['_save_log'] !== false) {
                    trace($sql, 'info', 'sql');
                }

                // 不记录的SQL，表名
                $logtable = config('NOT_WRITE_SQL.table');
                if (is_array($logtable)) {
                    foreach ($logtable as $v) {
                        if (
                            strpos($sql, "`$v`")
                            ||
                            // 支持  XXX*这种模糊匹配
                            (strpos($v, '*') && strpos($sql, '`' . str_replace('*', '', $v)))
                        ) {
                            return;
                        }
                    }
                }
                // 不记录的SQL，正则
                $not = config('NOT_WRITE_SQL.pattern');
                if (is_array($not)) {
                    foreach ($not as $pattern) {
                        if (preg_match($pattern, $sql)) {
                            return;
                        }
                    }
                }

                if (is_callable($this->mysqlOnQueryFunc['_save_sql'])) {
                    $this->mysqlOnQueryFunc['_save_sql']($sql);
                } else {
                    /** @var \App\Model\Account\LogSql $Log */
                    $Log = model_admin('LogSql');
                    $Log->sqlWriteLog($sql);
                }

                // 后置
                if (is_callable($this->mysqlOnQueryFunc['_after_func'])) {
                    $this->mysqlOnQueryFunc['_after_func']($result, $builder, $start);
                }
            }
        );
    }

    /**
     * 注册I18n国际化
     * @return void
     */
    protected function registerI18n()
    {
        $languages = $this->languageConfig;
        if ( ! is_array($languages)) {
            return;
        }
        foreach ($languages as $lang => $language) {
            $className = $language['class'];
            if ( ! class_exists($className)) {
                continue;
            }
            I18N::getInstance()->addLanguage(new $className(), $lang);
            empty($language['default']) or I18N::getInstance()->setDefaultLanguage($lang);
        }
    }

    /**
     * 注册Http全局Request回调
     * @return void
     */
    protected function registerHttpOnRequest()
    {
        if ( ! $this->httpOnRequestOpen) {
            return;
        }
        Di::getInstance()->set(
            SysConst::HTTP_GLOBAL_ON_REQUEST,
            function (Request $request, Response $response) {
                // 前置
                if (is_callable($this->httpOnRequestFunc['_before_func'])) {
                    // 返回false终止本次Request
                    if ($this->httpOnRequestFunc['_before_func']($request, $response) === false) {
                        return false;
                    }
                }
                // 自定义协程单例Request
                CtxRequest::getInstance()->request = $request;

                LamUnit::setI18n($request);

                if ( ! is_null($this->httpTracker)) {
                    $repeated = intval(stripos($request->getHeaderLine('user-agent'), ';HttpTracker') !== false);
                    // 开启链路追踪
                    $point = HttpTracker::getInstance($this->httpTrackerConfig)->createStart($this->httpTracker);
                    $point && $point->setStartArg(
                        HttpTracker::startArgsRequest($request, ['repeated' => $repeated])
                    );
                }

                // 后置
                if (is_callable($this->httpOnRequestFunc['_after_func'])) {
                    $return = $this->httpOnRequestFunc['_after_func']($request, $response);
                    // 如果返回bool，则直接使用
                    if (is_bool($return)) {
                        return $return;
                    }
                }
                return true;
            }
        );
    }

    protected function registerAfterRequest()
    {
        if ( ! ($this->httpAfterRequestOpen || ! is_null($this->httpTracker))) {
            return;
        }

        Di::getInstance()->set(
            SysConst::HTTP_GLOBAL_AFTER_REQUEST,
            function (Request $request, Response $response) {
                // 前置
                if (is_callable($this->httpAfterRequestFunc['_before_func'])) {
                    // 返回false结束运行
                    if ($this->httpAfterRequestFunc['_before_func']($request, $response) === false) {
                        return;
                    }
                }

                if ( ! is_null($this->httpTracker)) {
                    $point = HttpTracker::getInstance()->startPoint();
                    $point && $point->setEndArg(HttpTracker::endArgsResponse($response))->end();
                }

                // 后置
                if (is_callable($this->httpAfterRequestFunc['_after_func'])) {
                    $this->httpAfterRequestFunc['_after_func']($request, $response);
                }
            }
        );
    }
}
