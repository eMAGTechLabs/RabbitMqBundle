<?php

namespace OldSound\RabbitMqBundle\ExecuteReceiverStrategy;

use OldSound\RabbitMqBundle\ReceiverExecutor\ReceiverExecutorInterface;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

interface ExecuteReceiverStrategyInterface
{
    public function setReceiver(callable $receiver, ReceiverExecutorInterface $executor);

    public function onConsumeCallback(AMQPMessage $message): ?array;

    public function onMessageProcessed(AMQPMessage $message);


    public function onStopConsuming();
}