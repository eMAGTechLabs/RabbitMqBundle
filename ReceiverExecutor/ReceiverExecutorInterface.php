<?php

namespace OldSound\RabbitMqBundle\ReceiverExecutor;

use OldSound\RabbitMqBundle\RabbitMq\Exception\StopConsumerException;

interface ReceiverExecutorInterface
{
    /**
     * @param array $messages
     * @param callable $receiver
     * @return int[] Flags
     *
     * @throws StopConsumerException
     */
    public function execute(array $messages, callable $receiver): array;
}