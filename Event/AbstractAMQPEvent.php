<?php

namespace OldSound\RabbitMqBundle\Event;

use OldSound\RabbitMqBundle\RabbitMq\Consumer;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Contracts\EventDispatcher\Event as ContractsBaseEvent;

abstract class AbstractAMQPEvent extends ContractsBaseEvent
{
    /** @var AMQPMessage[] */
    protected $messages;

    /** @var Consumer */
    protected $consumer;
}
