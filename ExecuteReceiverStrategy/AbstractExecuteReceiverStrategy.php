<?php

namespace OldSound\RabbitMqBundle\ExecuteReceiverStrategy;

use OldSound\RabbitMqBundle\ReceiverExecutor\ReceiverExecutorInterface;
use PhpAmqpLib\Message\AMQPMessage;

abstract class AbstractExecuteReceiverStrategy implements ExecuteReceiverStrategyInterface
{
    /** @var ReceiverExecutorInterface */
    private $receiverExecutor;

    public function setReceiverExecutor(ReceiverExecutorInterface $receiverExecutor)
    {
        $this->receiverExecutor = $receiverExecutor;
    }

    /**
     * @param AMQPMessage[] $meesages
     */
    protected function execute(array $messages): array
    {
        return $this->receiverExecutor->execute($messages);
    }
}