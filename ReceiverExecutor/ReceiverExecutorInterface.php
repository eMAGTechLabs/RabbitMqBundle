<?php

namespace OldSound\RabbitMqBundle\ReceiverExecutor;

use OldSound\RabbitMqBundle\RabbitMq\Exception\StopConsumerException;

interface ReceiverExecutorInterface
{
    /**
     * @param AMQPMessage[] $messages
     * @param mixed $receiver
     * @return int[] Flags
     *
     * @throws StopConsumerException
     */
    public function execute(array $messages, $receiver): array;

    /**
     * @param object $receiver
     * @return bool
     */
    public function support($receiver): bool;
}