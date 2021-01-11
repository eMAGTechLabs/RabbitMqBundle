<?php

namespace OldSound\RabbitMqBundle\Event;

use OldSound\RabbitMqBundle\Declarations\ConsumeOptions;
use OldSound\RabbitMqBundle\RabbitMq\Consumer;
use PhpAmqpLib\Message\AMQPMessage;

class ReceiverArgumentsEvent extends AbstractAMQPEvent
{
    const NAME = 'old_sound_rabbit_mq.before_processing';

    /** @var ConsumeOptions */
    public $queueConsuming;

    public function __construct(array $arguments, ConsumeOptions $queueConsuming)
    {
        $this->messages = $arguments;
        $this->queueConsuming = $queueConsuming;
    }
}
