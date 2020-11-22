<?php

namespace OldSound\RabbitMqBundle\ExecuteCallbackStrategy;

use OldSound\RabbitMqBundle\Declarations\QueueConsuming;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

interface ExecuteCallbackStrategyInterface
{
    public function setProccessMessagesFn(callable $proccessMessagesFn);

    public function canPrecessMultiMessages(): bool;

    public function consumeCallback(AMQPMessage $message);

    public function onCatchTimeout(AMQPTimeoutException $e);

    public function onStopConsuming();
}