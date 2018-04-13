<?php
/**
 * Created by PhpStorm.
 * User: lanzhi
 * Date: 2018/4/13
 * Time: 下午9:07
 */

namespace lanzhi\socket;


class ReadHandler implements ReadHandlerInterface
{
    /**
     * @var callable
     */
    private $callable;

    public function __construct(callable $callable=null)
    {
        $this->callable = $callable;
    }

    public function deal(string &$buffer, int &$size, bool &$isEnd = false, bool &$shouldClose = false): void
    {
        if($this->callable){
            call_user_func_array($this->callable, [&$buffer, &$size, &$isEnd, &$shouldClose]);
        }else{
            $isEnd = true;
        }
    }
}