<?php

namespace OldSound\RabbitMqBundle\ExecuteReceiverStrategy;

use OldSound\RabbitMqBundle\ReceiverExecutor\ReceiverExecutorInterface;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

interface ExecuteReceiverStrategyInterface
{
    public function setReceiverExecutor(ReceiverExecutorInterface $receiverExecutor);

    public function canPrecessMultiMessages(): bool;

    public function onConsumeCallback(AMQPMessage $message): ?array;

    public function onMessageProcessed(AMQPMessage $message);

    public function onCatchTimeout(AMQPTimeoutException $e);

    public function onStopConsuming();
}