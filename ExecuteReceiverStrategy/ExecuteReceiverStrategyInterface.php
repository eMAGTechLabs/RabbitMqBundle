<?php

namespace OldSound\RabbitMqBundle\ExecuteCallbackStrategy;

use OldSound\RabbitMqBundle\Declarations\QueueConsuming;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

interface ExecuteReceiverStrategyInterface
{
    public function setMessagesProcessor(MessagesProcessorInterface $messagesProcessor);

    public function canPrecessMultiMessages(): bool;

    public function onConsumerCallback(AMQPMessage $message);

    public function onMessageProcessed(AMQPMessage $message);

    public function onCatchTimeout(AMQPTimeoutException $e);

    public function onStopConsuming();
}