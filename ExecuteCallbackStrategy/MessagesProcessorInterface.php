<?php

namespace OldSound\RabbitMqBundle\ExecuteCallbackStrategy;

use OldSound\RabbitMqBundle\Declarations\QueueConsuming;
use PhpAmqpLib\Message\AMQPMessage;

interface MessagesProcessorInterface
{
    /**
     * @param AMQPMessage[] $messages
     */
    public function processMessages(array $messages);
}