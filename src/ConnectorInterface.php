<?php
/**
 * Created by PhpStorm.
 * User: lanzhi
 * Date: 2018/4/13
 * Time: 下午4:30
 */

namespace lanzhi\socket;


interface ConnectorInterface
{
    const SCHEME_TCP  = 'tcp';
    const SCHEME_SSL  = 'ssl';
    const SCHEME_UNIX = 'unix';

    /**
     * 获取连接
     * @param string $scheme
     * @param string $host
     * @param int $port
     * @param array $options
     * @return ConnectionInterface
     */
    public function get(string $scheme, string $host, int $port, array $options=[]):ConnectionInterface;

    /**
     * 归还连接，如果此时连接已经关闭，则销毁变量，否则追加到空闲连接队列
     * @param ConnectionInterface $connection
     */
    public function back(ConnectionInterface $connection):void;
}