<?php
/**
 * Created by PhpStorm.
 * User: tsingsun
 * Date: 2017/2/19
 * Time: 下午7:25
 */

namespace yii\swoole\server;

use Swoole;
use yii\base\ErrorHandler;

/**
 * swoole server
 * events: 'Start', 'ManagerStart', 'ManagerStop', 'PipeMessage', 'Task', 'Packet', 'Finish',
 *         'Receive', 'Connect', 'Close', 'Timer', 'WorkerStart', 'WorkerStop', 'Shutdown', 'WorkerError'
 * @package yii\swoole\server
 */
abstract class Server
{

    protected $id;
    /**
     * @var \Swoole\Server
     */
    protected $swoole;

    protected $serverType = 'http';

    /**
     * @var string
     */
    public $host = '0.0.0.0';

    public $port = 9501;

    /**
     * @var \yii\swoole\bootstrap\BootstrapInterface
     */
    public $bootstrap;
    /**
     * @var string root directory
     */
    public $root;
    /**
     * @var int swoole's process mode
     */
    public $swooleMode;
    /**
     * @var int swoole socket
     */
    public $sockType;
    /**
     * @var array server setting
     * @see https://wiki.swoole.com/wiki/page/274.html
     */
    public $setting = [];

    public function __construct(array $config = [])
    {
        $this->parseConfig($config);
        $this->init();
    }

    /**
     *
     */
    public function init()
    {
        switch ($this->serverType){
            case 'http':
                $this->swoole = new Swoole\Http\Server($this->host,$this->port);
                $events = ['Request'];
                break;
            case 'socket':
                $this->swoole = new Swoole\WebSocket\Server($this->host,$this->port);
                $events = ['Open','Message','Close','HandShake'];
                break;
            default:
                $this->swoole = new Swoole\Server($this->host,$this->port,$this->swooleMode,$this->sockType);
                $events    = ['ManagerStart', 'ManagerStop', 'PipeMessage','Packet',
                    'Receive', 'Connect', 'Close', 'Timer', 'WorkerStop', 'WorkerError'];
                break;
        }
        if(!isset($this->setting['chroot'])){
            //默认当前站点路径
            $this->setting['chroot'] = $this->root;
        }
        $this->swoole->set($this->setting);

        $this->swoole->on('Start',[$this,'onStart']);
        $this->swoole->on('Shutdown',[$this,'onShutdown']);
        $this->swoole->on('WorkerStart',[$this,'onWorkerStart']);
        $this->swoole->on('WorkerStop',[$this,'onWorkerStop']);
        if(isset($this->setting['task_worker_num'])){
            $this->swoole->on('Task',[$this,'onTask']);
            $this->swoole->on('Finish',[$this,'onFinish']);
        }
        foreach ($events as $event){
            if(method_exists($this,'on'.$event)){
                $this->swoole->on($event,[$this,'on'.$event]);
            }
        }
    }

    public function getId()
    {
        return $this->id;
    }

    public function setId($value)
    {
        $this->id = $value;
    }

    /**
     * @return Swoole\Server
     */
    public function getSwoole()
    {
        return $this->swoole;
    }
    /**
     * 启动服务
     */
    public function start()
    {
        $this->swoole->start();
    }

    /**
     * @see https://wiki.swoole.com/wiki/page/p-event/onStart.html
     */
    public function onStart()
    {
        $this->setProcessTitle($this->id,'master');
    }

    /**
     *
     * @param Swoole\Server $server
     * @param $worker_id
     */
    public function onWorkerStart(Swoole\Server $server,$worker_id)
    {
        if($worker_id >= $server->setting['worker_num']) {
            $this->setProcessTitle($this->id,'task process');
        } else {
            $this->setProcessTitle($this->id,'worker process');
        }
        try{
            $this->bootstrap->onWorkerStart($server,$worker_id);
        }catch (\Exception $e){
            print_r("start yii error:".ErrorHandler::convertExceptionToString($e).PHP_EOL);
            $this->swoole->shutdown();
            die;
        }
    }

    public function onWorkerStop(Swoole\Server $server,$worker_id)
    {
        $this->bootstrap->onWorkerStop($server,$worker_id);
    }

    /**
     * @param Swoole\Server $server
     * @see https://wiki.swoole.com/wiki/page/45.html
     */
    public function onShutdown(Swoole\Server $server)
    {
//        echo 'swoole server shutdown';
    }

    /**
     * set process title
     * @param $siteName
     * @param $channelName
     */
    public function setProcessTitle($siteName,$channelName)
    {
        //低版本Linux内核和Mac OSX不支持进程重命名
        //@see https://wiki.swoole.com/wiki/page/125.html
        @swoole_set_process_name("php $siteName :$channelName swoole process");
    }

    private function parseConfig(array $config)
    {
        foreach ($config as $key=>$value){
            if(property_exists($this,$key)){
                if($value){
                    $this->$key = $value;
                }
                continue;
            }
            $className = get_called_class();
            throw new \Exception("config '$key' not found in $className->$key");
        }

    }

    /**
     * 根据配置文件创建swoole 服务
     * @param array $nodeConfig 单节点的swoole配置
     * @return static
     * @throws \Exception
     */
    static function autoCreate($nodeConfig)
    {
        if(isset($nodeConfig['class'])){
            $class = $nodeConfig['class'];
            unset($nodeConfig['class']);
            $instance = new $class($nodeConfig);
            if($instance instanceof Server){
               return $instance;
            }
            throw new \Exception('class must implement yii\swoole\server');
        }else{
            throw new \Exception("config 'class' not found");
        }

    }

    /**
     * 服务运行入口
     * @param array $config swoole配置文件
     * @param callable $func 启动回调
     */
    static function run($config,callable $func)
    {
        global $argv;
        if(!isset($argv[0],$argv[1],$argv[2])){
            print_r("invalid run params,see help,run like:php swoole.php http start|stop|reload".PHP_EOL);
            return;
        }
        $nodeName = $argv[1];
        $command = $argv[2];
        if(!isset($config[$nodeName])){
            print_r("invalid node name:$nodeName in config file".PHP_EOL);
            return;
        }

        $cfg = $config[$nodeName];
        $cfg['id'] = $nodeName;
        $pidFile = $cfg['setting']['pid_file'];
        $masterPid     = file_exists($pidFile) ? file_get_contents($pidFile) : null;
        if ($command == 'start'){
            if ($masterPid > 0 and posix_kill($masterPid,0)) {
                print_r('Server is already running. Please stop it first.'.PHP_EOL);
                exit;
            }
            $func($cfg);
        }elseif($command == 'stop'){
            if(!empty($masterPid)){
                posix_kill($masterPid,SIGTERM);
                if(PHP_OS=="Darwin"){
                    //mac下.发送信号量无法触发shutdown.
                    unlink($pidFile);
                }
            }else{
                print_r('master pid is null, maybe you delete the pid file we created. you can manually kill the master process with signal SIGTERM.'.PHP_EOL);
            }
            exit;
        }elseif($command == 'reload'){
            if (!empty($masterPid)) {
                posix_kill($masterPid, SIGUSR1); // reload all worker
                posix_kill($masterPid, SIGUSR2); // reload all task
            } else {
                print_r('master pid is null, maybe you delete the pid file we created. you can manually kill the master process with signal SIGUSR1.'.PHP_EOL);
            }
            exit;
        }
    }

}