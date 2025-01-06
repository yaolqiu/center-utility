<?php

namespace Lyqiu\CenterUtility;

use EasySwoole\Command\Color;
use EasySwoole\EasySwoole\Command\Utility;
use EasySwoole\EasySwoole\ServerManager;
use EasySwoole\EasySwoole\Swoole\EventRegister;
use EasySwoole\ORM\DbManager;
use EasySwoole\Redis\Redis;
use EasySwoole\RedisPool\RedisPool;
use EasySwoole\Socket\AbstractInterface\ParserInterface;
use EasySwoole\Spl\SplBean;
use Lyqiu\EsNotify\EsNotify;
use Lyqiu\EsNotify\Interfaces\ConfigInterface;

class EventMainServerCreate extends SplBean
{
    /**
     * 必传，MainServerCreate EventRegister对象
     * @var null | EventRegister
     */
    protected $EventRegister = null;

    /**
     * WebSocket事件， [EventRegister::onOpen => [Events::class, 'onOpen']]
     * @var null
     */
    protected $webSocketEvents = null;

    /**
     * WebSocket解释器
     * @var null
     */
    protected $WebSocketParser = WebSocket\Parser::class;

    /**
     *
     * @var null
     */
    protected $crontabClass = Crontab\Crontab::class;
    protected $crontabRunEnv = ['dev', 'produce'];


    protected $hotReloadWatchDirs = [EASYSWOOLE_ROOT . '/App', EASYSWOOLE_ROOT . '/vendor/wonder-game'];
    protected $hotReloadFunc = [
        'on_change' => null, // callback Change事件
        'on_exception' => null, // callback 异常
        'reload_before' => null, // callback worker process reload 前
        'reload_after' => null, // callback worker process reload 后
    ];

    /**
     * @var null ['key' => new EsNotify/Config([])]
     */
    protected $notifyConfig = null;

    protected $consumerJobs = null;

    protected function initialize(): void
    {
        if (is_null($this->notifyConfig)) {
            $this->notifyConfig = config('ES_NOTIFY');
        }
    }

    public function run()
    {
        // 仅在开启的是WebSocket服务时
        if (config('MAIN_SERVER.SERVER_TYPE') === EASYSWOOLE_WEB_SOCKET_SERVER) {
            $this->registerWebSocketServer();
        }
        $this->registerCrontab();
        $this->registerConsumer();
        $this->watchHotReload();
        $this->registerNotify();

        if (config('PROCESS_INFO.isopen')) {
            $this->EventRegister->add(EventRegister::onWorkerStart, [static::class, 'listenProcessInfo']);
        }
    }

    protected function registerWebSocketServer()
    {
        $register = $this->EventRegister;
        if ( ! $register instanceof EventRegister) {
            throw new \Exception('EventRegister Error');
        }

        $config = new \EasySwoole\Socket\Config();
        $config->setType(\EasySwoole\Socket\Config::WEB_SOCKET);
        if ($this->WebSocketParser) {
            $parserClassName = $this->WebSocketParser;
            $ParserClass = new $parserClassName();
            if ($ParserClass instanceof ParserInterface) {
                $config->setParser($ParserClass);
            }
        }

        $dispatch = new \EasySwoole\Socket\Dispatcher($config);
        $register->set(
            $register::onMessage,
            function (\Swoole\Websocket\Server $server, \Swoole\WebSocket\Frame $frame) use ($dispatch) {
                $dispatch->dispatch($server, $frame->data, $frame);
            }
        );
        $events = $this->webSocketEvents;
        if (is_array($events)) {
            foreach ($events as $event => $item) {
                $register->add($event, $item);
            }
        } else if (is_string($events) && class_exists($events)) {
            $allowNames = (new \ReflectionClass(EventRegister::class))->getConstants();
            $Ref = new \ReflectionClass($events);
            $public = $Ref->getMethods(\ReflectionMethod::IS_PUBLIC);

            foreach ($public as $item) {
                $name = $item->name;
                if ($item->isStatic() && isset($allowNames[$name])) {
                    $register->add($allowNames[$name], [$item->class, $name]);
                }
            }
        }
    }

    /**
     * 注册Crontab
     * @return void
     */
    protected function registerCrontab()
    {
        if (is_array($this->crontabRunEnv) && class_exists($this->crontabClass) && is_env($this->crontabRunEnv)) {
            $Crontab = \EasySwoole\EasySwoole\Crontab\Crontab::getInstance();
            $Crontab->addTask($this->crontabClass);
        }
    }

    /**
     * 注册自定义进程
     * @return void
     */
    protected function registerConsumer()
    {
        $jobs = $this->consumerJobs;
        if ( ! is_array($jobs)) {
            return;
        }
        $group = config('SERVER_NAME') . '.my';
        foreach ($jobs as $value) {

            $proName = $group . '.' . $value['name'];

            $class = $value['class'];
            if (empty($class) || ! class_exists($class)) {
                continue;
            }
            $psnum = intval($value['psnum'] ?? 1);
            $proCfg = [];
            if (isset($value['process_config']) && is_array($value['process_config'])) {
                $proCfg = $value['process_config'];
                unset($value['process_config']);
            }

            for ($i = 0; $i < $psnum; ++$i) {
                $cfg = array_merge([
                    'processName' => $proName . '.' . $i,
                    'processGroup' => $group,
                    'arg' => $value,
                    'enableCoroutine' => true,
                ], $proCfg);
                $processConfig = new \EasySwoole\Component\Process\Config($cfg);
                \EasySwoole\Component\Process\Manager::getInstance()->addProcess(new $class($processConfig));
            }
        }
    }

    protected function watchHotReload()
    {
        $watchConfig = $this->hotReloadWatchDirs;

        if ( ! is_env('dev') || ! is_array($watchConfig) || empty($watchConfig)) {
            return;
        }

        $onChange = is_callable($this->hotReloadFunc['on_change'])
            ? $this->hotReloadFunc['on_change']
            : function (array $list, \EasySwoole\FileWatcher\WatchRule $rule) {
                echo PHP_EOL . PHP_EOL . Color::warning(' Worker进程重启，检测到以下文件变更: ') . PHP_EOL;

                foreach ($list as $item) {
                    $scanType = is_file($item) ? 'file' : (is_dir($item) ? 'dir' : '未知');
                    echo Utility::displayItem("[$scanType]", $item) . PHP_EOL;
                }
                $Server = ServerManager::getInstance()->getSwooleServer();

                // worker进程reload不会触发客户端的断线重连，但是原来的fd已经不可用了
                foreach ($Server->connections as $fd) {
                    // 不要在 close 之后写清理逻辑。应当放置到 onClose 回调中处理
                    $Server->close($fd);
                }

                if (is_callable($this->hotReloadFunc['reload_before'])) {
                    $this->hotReloadFunc['reload_before']($list, $rule);
                }

                $Server->reload();

                if (is_callable($this->hotReloadFunc['reload_after'])) {
                    $this->hotReloadFunc['reload_after']($list, $rule);
                }

                echo Color::success('Worker进程启动成功 ') . PHP_EOL;
                echo Color::red('请自行区分 Master 和 Worker 程序 !!!!!!!!!!') . PHP_EOL . PHP_EOL;
            };

        $onException = is_callable($this->hotReloadFunc['on_exception'])
            ? $this->hotReloadFunc['on_exception']
            : function (\Throwable $throwable) {

                echo PHP_EOL . Color::danger('Worker进程重启失败: ') . PHP_EOL;
                echo Utility::displayItem("[message]", $throwable->getMessage()) . PHP_EOL;
                echo Utility::displayItem("[file]", $throwable->getFile() . ', 第 ' . $throwable->getLine() . ' 行') . PHP_EOL;

                echo Color::warning('trace:') . PHP_EOL;
                if ($trace = $throwable->getTrace()) {
                    // 简单打印就行
                    var_dump($trace);
//                    foreach ($trace as $key => $item)
//                    {
//                        echo Utility::displayItem("$key-----------------------", $item) . PHP_EOL;
//                        foreach ($item as $ik => $iv)
//                        {
//                            echo Utility::displayItem("[$ik]", $iv) . PHP_EOL;
//                        }
//                        echo Utility::displayItem("$key-----------------------", $item) . PHP_EOL;
//                    }
                }
            };

        $watcher = new \EasySwoole\FileWatcher\FileWatcher();
        // 设置监控规则和监控目录
        foreach ($watchConfig as $dir) {
            if (is_dir($dir)) {
                $watcher->addRule(new \EasySwoole\FileWatcher\WatchRule($dir));
            }
        }

        $watcher->setOnChange($onChange);
        $watcher->setOnException($onException);
        $watcher->attachServer(ServerManager::getInstance()->getSwooleServer());
    }

    protected function registerNotify()
    {
        $config = $this->notifyConfig;
        if ( ! is_array($config)) {
            return;
        }
        foreach ($config as $name => $cfg) {
            if ($cfg instanceof ConfigInterface) {
                EsNotify::getInstance()->register($cfg, $name);
            } else {
                trace("EsNotify 注册失败: $name");
            }
        }
    }

    /**
     * 侦听进程、协程、连接池信息
     * config结构： 'PROCESS_INFO' => [
                        'isopen' => true,           // 是否开启
                        'timer' => 5000,            // 定时器间隔时间
                        'pool' => 'log',            // 写入redis连接池
                        'queue' => 'ProcessInfo',   // 写入队列名
                    ]
     * @return void
     */
    public static function listenProcessInfo()
    {
        $cfg = config('PROCESS_INFO');

        if ($cfg && is_numeric($cfg['timer'])) {
            // 服务器id
            $servname = config('SERVNAME');
            // 当前服务
            $servername = config('SERVER_NAME');

            $mysql = config('MYSQL');
            $redis = config('REDIS');

            // jenkins 新旧程序切换,是否要延迟10s ?
            \EasySwoole\Component\Timer::getInstance()->loop($cfg['timer'], function () use ($servname, $servername, $mysql, $redis, $cfg) {
                $pid = getmypid();
                $info = [
                    'servname' => $servname,
                    'servername' => $servername,
                    'pid' => $pid,
                    'instime' => time(),
                ];
                // 进程信息
                $info['process'] = \EasySwoole\Component\Process\Manager::getInstance()->info($pid)[$pid];
                $info['name'] = $info['process']['name'];
                // 总协程信息
                $info['coroutine'] = \Swoole\Coroutine::stats();
                // 单个协程信息
                $coros = \Swoole\Coroutine::list();
                foreach ($coros as $cid) {
                    $info['coroutine_list'][$cid] = [
                        // 已运行时间，浮点毫秒
                        'runtime' => \Swoole\Coroutine::getelapsed($cid)
                    ];
                    // 调用堆栈
                    // \Swoole\Coroutine::getbacktrace($cid)
                }

                // mysql连接池
                foreach ($mysql as $dName => $dVal) {
                    $info['mysql_pool'][$dName] = [];
                    // status返回类型bug，遍历取当前进程
                    $dValues = DbManager::getInstance()->getConnection($dName)->__getClientPool()->status();
                    foreach ($dValues as $value) {
                        if ($value['pid'] === $pid) {
                            $info['mysql_pool'][$dName] = $value;
                        }
                    }
                }
                // redis连接池
                foreach ($redis as $rName => $rVal) {
                    $info['redis_pool'][$rName] = [];
                    $rValues = RedisPool::getInstance()->getPool($rName)->status();
                    foreach ($rValues as $value) {
                        if ($value['pid'] === $pid) {
                            $info['redis_pool'][$rName] = $value;
                        }
                    }
                }

                RedisPool::invoke(function (Redis $redis) use ($info, $cfg) {
                    $redis->rPush($cfg['queue'], json_encode($info, JSON_UNESCAPED_UNICODE));
                }, $cfg['pool'] ?? 'default');
            });
        }
    }
}
