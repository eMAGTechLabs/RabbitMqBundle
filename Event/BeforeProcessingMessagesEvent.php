<?php

namespace OldSound\RabbitMqBundle\Event;

use OldSound\RabbitMqBundle\Declarations\QueueConsuming;
use OldSound\RabbitMqBundle\RabbitMq\Consumer;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Class BeforeProcessingMessageEvent
 *
 * @package OldSound\RabbitMqBundle\Command
 */
class BeforeProcessingMessagesEvent extends AbstractAMQPEvent
{
    const NAME = 'before_processing';

    /** @var QueueConsuming */
    public $queueConsuming;

    /**
     * BeforeProcessingMessageEvent constructor.
     *
     * @param AMQPMessage $AMQPMessage
     */
    public function __construct(
        Consumer $consumer, 
        array $messages,
        QueueConsuming $queueConsuming
    )
    {
        $this->consumer = $consumer;
        $this->messages = $messages;
        $this->queueConsuming = $queueConsuming;
    }
}
