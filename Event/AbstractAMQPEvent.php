<?php

namespace OldSound\RabbitMqBundle\Event;

use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Contracts\EventDispatcher\Event as ContractsBaseEvent;

abstract class AbstractAMQPEvent extends ContractsBaseEvent
{
    /** @var AMQPMessage[] */
    protected $messages;

    protected $stoppingConsumer = false;

    /**
     * {@inheritdoc}
     */
    public function isStoppingConsumer(): bool
    {
        return $this->stoppingConsumer;
    }
}
