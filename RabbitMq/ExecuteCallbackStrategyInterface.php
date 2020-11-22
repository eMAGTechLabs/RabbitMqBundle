<?php


namespace OldSound\RabbitMqBundle\RabbitMq;

use OldSound\RabbitMqBundle\Declarations\QueueConsuming;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

interface ExecuteCallbackStrategyInterface
{
    public function consumeCallback(AMQPMessage $message);

    public function onCatchTimeout(AMQPTimeoutException $e);

    public function onStopConsuming();
}