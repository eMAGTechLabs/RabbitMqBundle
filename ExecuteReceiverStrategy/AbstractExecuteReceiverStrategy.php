<?php

namespace OldSound\RabbitMqBundle\ExecuteReceiverStrategy;

use OldSound\RabbitMqBundle\ReceiverExecutor\ReceiverExecutorInterface;
use PhpAmqpLib\Message\AMQPMessage;

abstract class AbstractExecuteReceiverStrategy implements ExecuteReceiverStrategyInterface
{
    /** @var callable */
    private $receiver;
    /** @var ReceiverExecutorInterface */
    private $executor;

    public function setReceiver(callable $receiver, ReceiverExecutorInterface $executor)
    {
        $this->receiver = $receiver;
        $this->executor = $executor;
    }

    /**
     * @param AMQPMessage[] $meesages
     */
    protected function execute(array $messages): array
    {
        return $this->executor->execute($messages, $this->receiver);
    }
}