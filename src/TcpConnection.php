<?php
/**
 * Created by PhpStorm.
 * User: lanzhi
 * Date: 2018/4/13
 * Time: 上午11:32
 */

namespace lanzhi\socket;

use Generator;
use lanzhi\socket\exceptions\SocketConnectException;
use lanzhi\socket\exceptions\SocketReadException;
use lanzhi\socket\exceptions\SocketWriteException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class TcpConnection implements ConnectionInterface
{
    const TIMEOUT_CONNECT = 3;
    const TIMEOUT_WRITE   = 60;
    const TIMEOUT_READ    = 60;

    const BUFFER_SIZE     = 65536;//64k

    private $id;
    private $name;
    private $mark;
    /**
     * @var LoggerInterface
     */
    private $logger;
    private $host;
    private $port;

    private $timeoutConnect;
    private $timeoutWrite;
    private $timeoutRead;

    /**
     * @var resource
     */
    private $socket;
    /**
     * @var string
     */
    private $status;
    /**
     * @var string
     */
    private $socketStatus;
    /**
     * @var int
     */
    private $lastActiveTime;

    /**
     * Connection constructor.
     * @param string $ip
     * @param int $port
     * @param array $options
     * @param LoggerInterface|null $logger
     */
    public function __construct(string $ip, int $port, array $options=[], LoggerInterface $logger=null)
    {
        $this->host           = $ip;
        $this->port           = $port;
        $this->timeoutConnect = $options['timeout']['connect'] ?? self::TIMEOUT_CONNECT;
        $this->timeoutWrite   = $options['timeout']['write']   ?? self::TIMEOUT_WRITE;
        $this->timeoutRead    = $options['timeout']['read']    ?? self::TIMEOUT_READ;
        $this->logger         = $logger                        ?? new NullLogger();

        $this->status       = self::STATUS_NOT_READY;
        $this->socketStatus = self::SOCKET_UNAVAILABLE;

        $this->id   = uniqid();
        $this->name = 'unnamed';
        $this->mark = self::UNMARKED;
        $this->active();
    }

    private function active()
    {
        $this->lastActiveTime = time();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setName(string $name): ConnectionInterface
    {
        $this->name = $name;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getStatus():string
    {
        return $this->status;
    }

    public function getSocketStatus():string
    {
        return $this->socketStatus;
    }

    public function isAvailable(): bool
    {
        return $this->status===self::STATUS_CONNECTED;
    }

    public function getLastActiveTime(): int
    {
        return $this->lastActiveTime;
    }

    public function setMark(string $mark): ConnectionInterface
    {
        $this->mark = $mark;
        return $this;
    }

    public function getMark(): string
    {
        return $this->mark;
    }

    /**
     * @return \Generator
     * @throws SocketConnectException
     * @throws \Exception
     */
    public function connect(): \Generator
    {
        if($this->status===self::STATUS_CONNECTED){
            return ;
        }
        if($this->status===self::STATUS_CLOSED){
            throw new \Exception("connection has been closed; get a new one by connector");
        }

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if(!socket_set_nonblock($socket)){
            throw new \Exception("set non-block fail");
        }
        $this->logger->info("socket created; ".(string)$socket);

        if (!socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1)) {
            throw new \Exception('Unable to set option on socket: '. socket_strerror(socket_last_error()));
        }

        $yields = 0;
        $startTime = microtime(true);
        //因为非阻塞，当时无法连接并不能认为是错误
        if(!socket_connect($socket, $this->host, $this->port)){
            $errorNo = socket_last_error($socket);
            if($errorNo===SOCKET_EINPROGRESS){
                //判断连接超时
                while(true){
                    if(microtime(true)-$startTime > $this->timeoutConnect){
                        throw new SocketConnectException($socket, "connect timeout; timeout:{$this->timeoutConnect}");
                    }
                    $reads  = [];
                    $writes = [$socket];
                    $excepts= [];
                    $changes = socket_select($reads, $writes, $excepts, 0);

                    if($changes===false){
                        throw new SocketConnectException($socket);
                    }elseif($changes===1){
                        break;
                    }

                    $yields++;
                    yield;
                }
            }else{
                throw new SocketConnectException($socket);
            }
        }

        $this->logger->info("connect successfully; host:{host}:{port}; time usage:{timeUsage}; yield times:{yield}; {socket}", [
            'host'      => $this->host,
            'port'      => $this->port,
            'timeUsage' => round(microtime(true)-$startTime, 6),
            'yield'     => $yields,
            'socket'    => (string)$socket
        ]);

        $this->socket       = $socket;
        $this->status       = self::STATUS_CONNECTED;
        $this->socketStatus = self::SOCKET_WRITABLE;
        $this->active();
    }

    /**
     * @param string $data
     * @param bool $isEnd
     * @return \Generator
     * @throws SocketWriteException
     * @throws \Exception
     */
    public function write(string $data=null, bool $isEnd=false):\Generator
    {
        if($this->status===self::STATUS_NOT_READY){
            yield from $this->connect();
        }

        if($this->socketStatus!==self::SOCKET_WRITABLE){
            throw new \Exception("connection not writable now; status:{$this->status}");
        }

        $this->logger->debug("request header:\n".$data);
        $startTime = microtime(true);
        $length    = strlen($data);
        $written   = 0;
        $yields    = 0;

        while(true){
            //写入完毕
            if($written==$length){
                break;
            }

            if(microtime(true)-$startTime > $this->timeoutWrite){
                throw new SocketWriteException($this->socket, "write timeout; timeout:{$this->timeoutWrite}");
            }

            $reads  = [];
            $writes = [$this->socket];
            $excepts= [];
            $changes = socket_select($reads, $writes, $excepts, 0);

            if($changes===false){
                throw new SocketWriteException($this->socket);
            }elseif($changes>0){
                //此时可写
                $size = socket_write($this->socket, substr($data, $written), 4096);
                if($size===false && socket_last_error($this->socket)!==SOCKET_EAGAIN){
                    throw new SocketWriteException($this->socket);
                }elseif($size>0){
                    $written += $size;
                }
            }

            $yields++;
            yield;
        }

        $this->logger->info("write successfully; time usage:{timeUsage}; yield times:{yield}; data length:{length}; {socket}", [
            'timeUsage' => round(microtime(true)-$startTime, 6),
            'yield'     => $yields,
            'length'    => strlen($data),
            'socket'    => (string)$this->socket
        ]);

        //如果写结束，则变更状态
        if($isEnd){
            $this->socketStatus = self::SOCKET_READABLE;
        }
        $this->active();
    }

    /**
     * @param string|null $data
     * @return Generator
     */
    public function end(string $data = null): Generator
    {
        if($data){
            yield from $this->write($data, true);
        }else{
            $this->socketStatus = self::SOCKET_READABLE;
        }
        $this->active();
    }

    /**
     * 从连接中读取数据
     * @param ReadHandlerInterface $handle
     * @return \Generator
     * @throws SocketReadException
     * @throws \Exception
     */
    public function read(ReadHandlerInterface $handle):\Generator
    {
        if($this->socketStatus!==self::SOCKET_READABLE){
            throw new \Exception("connection not readable now; status:{$this->status}");
        }

        $startTime = microtime(true);
        $buffer = null;
        $size   = self::BUFFER_SIZE;
        $isEnd  = false;
        $shouldClose = false;

        $yields = 0;
        while (true){
            if(microtime(true)-$startTime > $this->timeoutRead){
                throw new SocketReadException($this->socket, "request timeout; timeout:{$this->timeoutRead}");
            }

            $reads  = [$this->socket];
            $writes = [];
            $excepts= [];
            $changes = socket_select($reads, $writes, $excepts, 0);
            if($changes===false){
                throw new SocketReadException($this->socket);
            }elseif($changes>0){
                //此时可读
                $chunk = socket_read($this->socket, $size);
                if($chunk===false){
                    $no = socket_last_error($this->socket);
                    if($no!==SOCKET_EAGAIN && $no!==SOCKET_EINTR){
                        throw new SocketReadException($this->socket);
                    }
                }elseif($chunk){
                    $buffer .= $chunk;
                    $handle->deal($buffer, $size, $isEnd, $shouldClose);
                }elseif(SOCKET_EINTR!==socket_last_error($this->socket)){
                    //此时对端 socket 已经关闭
                    $this->close();
                    break;
                }
            }

            if($isEnd){
                break;
            }

            $yields++;
            yield;
        }

        $this->logger->info("read successfully; time usage:{timeUsage}; yield times:{yield}; {socket}", [
            'timeUsage' => round(microtime(true)-$startTime, 6),
            'yield'     => $yields,
            'socket'    => (string)$this->socket
        ]);

        if($isEnd){
            $this->socketStatus = self::SOCKET_WRITABLE;
        }

        if($shouldClose){
            $this->close();
        }
        $this->active();
    }

    public function close():void
    {
        if($this->socket && $this->status===self::STATUS_CONNECTED){
            socket_close($this->socket);
            $this->logger->info("socket closed; ".(string)$this->socket);
            $this->socket = null;
        }
        $this->status       = self::STATUS_CLOSED;
        $this->socketStatus = self::SOCKET_UNAVAILABLE;
    }

    public function __destruct()
    {
        if($this->socket){
            $this->close();
        }
    }
}