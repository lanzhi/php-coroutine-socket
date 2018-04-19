<?php
/**
 * Created by PhpStorm.
 * User: lanzhi
 * Date: 2018/4/19
 * Time: 下午2:28
 */


include __DIR__ . "/../vendor/autoload.php";

use lanzhi\socket\Connector;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Logger\ConsoleLogger;
use lanzhi\coroutine\Scheduler;
use lanzhi\coroutine\GeneralRoutine;

class ReadHandler implements \lanzhi\socket\ReadHandlerInterface
{
    private $buffer;
    public function deal(string &$buffer, int &$size, bool &$isEnd = false, bool &$shouldClose = false): void
    {
        $this->buffer = $buffer;
        $isEnd = true;
    }
    public function getResponse()
    {
        return $this->buffer;
    }
}

$output = new ConsoleOutput(ConsoleOutput::VERBOSITY_DEBUG);
$logger = new ConsoleLogger($output);
$scheduler = Scheduler::getInstance()->setLogger($logger);

$connector = Connector::getInstance()->setLogger($logger)->enableGc();

$uri = 'tcp://127.0.0.1:50000';
list($scheme, $host, $port) = Connector::parseUri($uri);

$cons = [];
for($i=0; $i<5; $i++){
    $data = uniqid();
    $handler = new ReadHandler();
    $conn = $connector->get($scheme, $host, $port);
    $routine = new \lanzhi\coroutine\FlexibleRoutine();
    $routine->add(Scheduler::buildRoutineUnit($conn->write($data, true)));
    $routine->add(Scheduler::buildRoutineUnit($conn->read($handler)));

    $generator = function () use($conn, $connector, $handler){
        yield;
        echo "backing\n";
        $connector->back($conn);
        echo "response: ", $handler->getResponse(), "\n";
    };
    $routine->add(Scheduler::buildRoutineUnit($generator()));

    $scheduler->register($routine);

    $routineQueueSize = Scheduler::getInstance()->getRoutineQueueSize();
    echo "routine queue size:{$routineQueueSize}\n";

}

$scheduler->run();
