<?php

namespace OldSound\RabbitMqBundle\ExecuteCallbackStrategy;

use OldSound\RabbitMqBundle\Declarations\QueueConsuming;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

class SimpleExecuteReveiverStrategy extends AbstractExecuteCallbackStrategy
{
    /** @var AMQPMessage */
    private $processingMessage;
    public function canPrecessMultiMessages(): bool
    {
        return false;
    }

    public function consumeCallback(AMQPMessage $message)
    {
        $this->processingMessage = $message;
        $this->proccessMessages([$this->processingMessage]);
    }

    public function onMessageProcessed(AMQPMessage $message)
    {
        if ($this->processingMessage !== $message) {
            throw new \InvalidArgumentException('TODO');
        }
        $this->processingMessage = null;
    }

    public function onCatchTimeout(AMQPTimeoutException $e)
    {
    }

    public function onStopConsuming()
    {
        if ($this->processingMessage) {
            $this->proccessMessages([$this->processingMessage]);
        }
    }
}