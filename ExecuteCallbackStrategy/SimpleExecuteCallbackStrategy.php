<?php

namespace OldSound\RabbitMqBundle\ExecuteCallbackStrategy;

use OldSound\RabbitMqBundle\Declarations\QueueConsuming;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

class SimpleExecuteCallbackStrategy extends AbstractExecuteCallbackStrategy
{
    public function canPrecessMultiMessages(): bool
    {
        return false;
    }

    public function consumeCallback(AMQPMessage $message)
    {
        $this->proccessMessages([$message]);
    }

    public function onMessageProcessed(AMQPMessage $message)
    {
    }

    public function onCatchTimeout(AMQPTimeoutException $e)
    {
    }

    public function onStopConsuming()
    {
    }
}