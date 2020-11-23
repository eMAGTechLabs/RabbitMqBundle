<?php

namespace OldSound\RabbitMqBundle\RabbitMq;

use PhpAmqpLib\Message\AMQPMessage;

interface BatchConsumerInterface
{
    /**
     * @param AMQPMessage[] $messages
     * @return array|int
     */
    public function batchExecute(array $messages);
}
