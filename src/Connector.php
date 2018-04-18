<?php
/**
 * Created by PhpStorm.
 * User: lanzhi
 * Date: 2018/4/13
 * Time: 下午4:55
 */

namespace lanzhi\socket;


use lanzhi\coroutine\GeneralRoutine;
use lanzhi\coroutine\Scheduler;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Connector
{
    const SCHEME_TCP    = 'tcp';
    const SCHEME_SSL    = 'ssl';
    const SCHEME_UNIX   = 'unix';

    const GC_INTERVAL   = 60;
    const MAX_IDLE_TIME = 60;

    /**
     * @var array
     * ```php
     * [
     *     'baidu.com' => [
     *         ['220.181.57.216', '111.13.101.208'],
     *         0
     *     ]
     * ]
     * ```
     */
    private static $dns = [];
    /**
     * @var array
     */
    private static $defaultPorts = [
        'http'  => 80,
        'https' => 443,
        'redis' => 6379
    ];
    /**
     * @var self
     */
    private static $instance;

    /**
     * @var array
     */
    private $options = [];
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var array
     */
    private $busyConnections;
    /**
     * @var array
     */
    private $idleConnections = [];
    /**
     * @var int
     */
    private $lastGcTime = 0;

    /**
     * @param $uri
     * @return [scheme, host, port]
     */
    public static function parseUri(string $uri)
    {
        $schemeMap = [
            'http'  => 'tcp',
            'redis' => 'tcp'
        ];
        $parts = parse_url($uri);
        $scheme = $parts['scheme'] ?? '';
        $host   = $parts['host']   ?? '';
        $port   = $parts['port']   ?? 0;

        if(empty($parts['scheme'])){
            throw new \Exception("scheme can't be empty! uri:{$uri}");
        }

        if(empty($port) && isset(self::$defaultPorts[$scheme])){
            $port = self::$defaultPorts[$scheme];
        }

        $scheme = isset($schemeMap[$scheme]) ? $schemeMap[$scheme] : $scheme;
        return [$scheme, $host, $port];
    }

    /**
     * @return self
     */
    public static function getInstance(): self
    {
        if(!self::$instance){
            self::$instance = new static();
        }
        return self::$instance;
    }

    /**
     * Connector constructor.
     * @param array $options
     * ```php
     * [
     *     'timeout' => [
     *         'connect' => 3,
     *         'write'   => 10,
     *         'read'    => 10
     *     ]
     * ]
     *
     * ```
     * @param LoggerInterface|null $logger
     */
    protected function __construct()
    {
        $this->logger  = new NullLogger();
    }

    /**
     * @param array $options
     * ```php
     * [
     *     'timeout' => [
     *         'connect' => 3,
     *         'write'   => 10,
     *         'read'    => 10
     *     ]
     * ]
     * ```
     * @return $this
     */
    public function setOptions(array $options)
    {
        $this->options = $options;
        return $this;
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * @param string $scheme
     * @param string $host
     * @param int $port
     * @param array $options
     * @return ConnectionInterface
     */
    public function get(string $scheme, string $host, int $port, array $options=[]): ConnectionInterface
    {
        $name = $this->buildConnectionName($scheme, $host, $port);
        $connection = $this->getFromIdleQueue($name);
        if(!$connection){
            $connection = $this->buildOne($scheme, $host, $port, $options);
            $connection->setName($name);
        }

        $this->addToBusyQueue($name, $connection);
        return $connection;
    }

    /**
     * 归还连接，如果此时连接已经关闭，则销毁变量，否则追加到空闲连接队列
     * @param ConnectionInterface $connection
     * @throws \Exception
     */
    public function back(ConnectionInterface $connection): void
    {
        $name = $connection->getName();
        if(empty($this->busyConnections[$name])){
            throw new \Exception("unknown connection A; may be not created by this connector");
        }

        $this->logger->info("recycle connection; name:{$name}");
        $miss = true;
        foreach ($this->busyConnections[$name] as $key=>$item){
            if($connection===$item){
                unset($this->busyConnections[$name][$key]);
                $miss = false;
            }
        }
        if($miss){
            throw new \Exception("unknown connection B; may be not created by this connector");
        }

        if(!$connection->isAvailable()){
            unset($connection);
            $this->logger->info("clear connection; it's unavailable, may be closed; name:{$name}");
        }else{
            //追加到空闲队列
            $this->idleConnections[$name][] = $connection;
            //仅当有空闲连接的时候才需要执行连接的垃圾回收机制
            $this->registerGcToScheduler();
        }
    }

    /**
     * 创建连接
     * @param string $scheme
     * @param string $host
     * @param int $port
     * @param array $options
     * @return ConnectionInterface
     * @throws \Exception
     */
    private function buildOne(string $scheme, string $host, int $port, array $options=[])
    {
        if(empty($scheme) || empty($host) || empty($port)){
            throw new \Exception("scheme or host or port empty");
        }
        if(empty(self::$dns[$host])){
            $startTime = microtime(true);
            $ips = gethostbynamel($host);

            if($ips===false){
                throw new \Exception("can't resolve host:{$host}");
            }else{
                $this->logger->info("resole host; time usage:".(round(microtime(true)-$startTime, 5)));
            }
            self::$dns[$host] = [$ips, 0];
        }

        $ip = $this->getBalanceIp(self::$dns[$host][0], self::$dns[$host][1]);
        switch ($scheme){
            case self::SCHEME_TCP:
                $connection = new TcpConnection($ip, $port, $this->options + $options, $this->logger);
                break;
            case self::SCHEME_SSL:
            case self::SCHEME_UNIX:
            default:
                throw new \Exception("unsupported now; scheme: {$scheme}");
        }

        return $connection;
    }

    /**
     * 如果一个host对应多个IP，则轮询
     * @param array $ips
     * @param $counter
     * @return mixed
     * @throws \Exception
     */
    private function getBalanceIp(array $ips, &$counter)
    {
        $size = count($ips);
        if($size==0){
            throw new \Exception("ip list is empty");
        }
        $index = $counter%$size;
        $counter++;

        return $ips[$index];
    }

    private function buildConnectionName($scheme, $host, $port)
    {
        return "{$scheme}://{$host}:{$port}";
    }

    private function getFromIdleQueue(string $name)
    {
        if(empty($this->idleConnections[$name])){
            return false;
        }

        return array_pop($this->idleConnections[$name]);
    }

    private function addToBusyQueue(string $name, ConnectionInterface $connection)
    {
        if(empty($this->busyConnections[$name])){
            $this->busyConnections[$name] = [$connection];
        }else{
            $this->busyConnections[$name][] = $connection;
        }
    }

    private $registered = false;
    private function registerGcToScheduler()
    {
        if($this->registered){
            return ;
        }else{
            Scheduler::getInstance()->register(new GeneralRoutine($this->gc(), 'connector-gc'));
        }
    }

    private function gc():\Generator
    {
        /**
         * @var ConnectionInterface $connection
         */
        while (true){
            //当空闲连接为空时，就没有必要再执行 GC
            if(empty($this->idleConnections)){
                $this->registered = false;
                break;
            }
            if(time()-$this->lastGcTime > self::GC_INTERVAL){
                foreach ($this->idleConnections as $name=>$connections){
                    foreach ($connections as $key=>$connection){
                        if(time() - $connection->getLastActiveTime() >= self::MAX_IDLE_TIME){
                            $connection->close();
                            unset($this->idleConnections[$name][$key]);
                        }
                    }
                }
                $this->lastGcTime = time();
            }

            usleep(10000);
            yield;
        }

    }
}