<?php

namespace OldSound\RabbitMqBundle\RabbitMq\Exception;


use OldSound\RabbitMqBundle\RabbitMq\ConsumerInterface;

class AckStopConsumerException extends StopConsumerException
{
    public function getHandleCode(): int
    {
        return ConsumerInterface::MSG_ACK;
    }

}
