<?php

namespace OldSound\RabbitMqBundle\ExecuteCallbackStrategy;

use PhpAmqpLib\Message\AMQPMessage;

abstract class AbstractExecuteCallbackStrategy implements ExecuteCallbackStrategyInterface
{
    /** @var callable */
    private $proccessMessagesFn;

    public function setProccessMessagesFn(callable $proccessMessagesFn)
    {
        $this->proccessMessagesFn  = $proccessMessagesFn;
    }

    /**
     * @param AMQPMessage[] $meesages
     */
    protected function proccessMessages(array $messages)
    {
        call_user_func($this->proccessMessagesFn, $messages);
    }
}