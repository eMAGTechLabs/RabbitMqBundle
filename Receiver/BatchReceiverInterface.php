<?php

namespace OldSound\RabbitMqBundle\Receiver;

use PhpAmqpLib\Message\AMQPMessage;

interface BatchReceiverInterface
{
    /**
     * @param AMQPMessage[] $messages
     * @return array|int
     * @throws ReceiveException
     * @throws NotReadyReceiveException
     */
    public function batchExecute(array $messages);
}
