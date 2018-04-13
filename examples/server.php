<?php
/**
 * Created by PhpStorm.
 * User: lanzhi
 * Date: 2018/4/13
 * Time: 下午9:31
 */

// pipe a connection into itself

use React\EventLoop\Factory;
use React\Socket\Server;
use React\Socket\ConnectionInterface;

require __DIR__ . '/../vendor/autoload.php';

$loop = Factory::create();

$uri  = "tcp://127.0.0.1:50000";
$server = new Server($uri, $loop);

$server->on('connection', function (ConnectionInterface $conn) {
    echo '[' . $conn->getRemoteAddress() . ' connected]' . PHP_EOL;
    $conn->pipe($conn);
});

$server->on('error', 'printf');

echo 'Listening on ' . $server->getAddress() . PHP_EOL;

$loop->run();