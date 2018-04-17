<?php
/**
 * Created by PhpStorm.
 * User: lanzhi
 * Date: 2018/4/13
 * Time: 下午8:52
 */

include __DIR__ . "/../vendor/autoload.php";

use lanzhi\socket\Connector;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Logger\ConsoleLogger;

class ReadHandler implements \lanzhi\socket\ReadHandlerInterface
{
    public function deal(string &$buffer, int &$size, bool &$isEnd = false, bool &$shouldClose = false): void
    {
        echo $buffer, "\n";
        $isEnd = true;
    }
}

$output = new ConsoleOutput(ConsoleOutput::VERBOSITY_VERY_VERBOSE);
$logger = new ConsoleLogger($output);
$connector = new Connector([], $logger);

$uri = 'tcp://127.0.0.1:50000';
list($scheme, $host, $port) = Connector::parseUri($uri);

$connection = $connector->get($scheme, $host, $port);

$data = "hello, world";
foreach ($connection->write($data, true) as $item){

}

$response = null;
$handler = new \lanzhi\socket\ReadHandler(function(string &$buffer, int &$size, bool &$isEnd = false, bool &$shouldClose = false)use(&$response){
    $response = $buffer;
    echo $buffer, "\n";
    $isEnd = true;
});
//$handler = new ReadHandler();
foreach ($connection->read($handler) as $item){

}

$connector->back($connection);

var_dump($response, $data);
assert($response==$data);

