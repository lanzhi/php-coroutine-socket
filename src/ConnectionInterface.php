<?php
/**
 * Created by PhpStorm.
 * User: lanzhi
 * Date: 2018/4/13
 * Time: 下午3:31
 */

namespace lanzhi\socket;


use Generator;

interface ConnectionInterface
{
    const STATUS_NOT_READY = 'not-ready';
    const STATUS_CONNECTED = 'connected';
    const STATUS_CLOSED    = 'closed';

    const SOCKET_UNAVAILABLE = 'unavailable';
    const SOCKET_WRITABLE    = 'writable';
    const SOCKET_READABLE    = 'readable';

    const UNMARKED = null;

    /**
     * 通过连接写入数据，isEnd表示是否还有剩余数据写入
     * @param string $data
     * @param bool $isEnd
     * @return Generator
     */
    public function write(string $data, bool $isEnd=false):Generator;

    /**
     * 调用该方法之后，说明写入结束，连接状态变更为读模式
     * @param string|null $data
     * @return Generator
     */
    public function end(string $data=null):Generator;

    /**
     * 通过连接读取数据，何时读取完毕以及之后是否应该关闭连接由handler控制
     * 注意：必须告诉连接何时读取完毕，否则该生成器将会一致处于valid状态，导致连接将一直不可写
     * @param ReadHandlerInterface $handler
     * @return Generator
     */
    public function read(ReadHandlerInterface $handler):Generator;

    /**
     * 关闭连接，此后该连接名存实亡
     */
    public function close():void;

    /**
     * @return string
     */
    public function getId():string;

    /**
     * @param string $name
     * @return ConnectionInterface
     */
    public function setName(string $name):self;
    /**
     * 获取连接名称，同一名称可对应多个连接
     * @return string
     */
    public function getName():string;

    /**
     * 获取连接当前所处状态
     * @return string
     */
    public function getStatus():string;

    /**
     * 获取连接内部socket状态
     * @return string
     */
    public function getSocketStatus():string;

    /**
     * 判断当前连接是否可用
     * @return bool
     */
    public function isAvailable():bool;

    /**
     * 返回最近一次使用的时间戳
     * @return int
     */
    public function getLastActiveTime():int;

    /**
     * 为使用者提供一种方便，对连接进行标记，如连接已认证等
     * @param string $mark
     * @return ConnectionInterface
     */
    public function setMark(string $mark):self;

    /**
     * 返回最近设置过的标记，默认为空字符串
     * @return string
     */
    public function getMark():string;
}
