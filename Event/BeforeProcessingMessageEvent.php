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
class BeforeProcessingMessageEvent extends AMQPEvent
{
    const NAME = AMQPEvent::BEFORE_PROCESSING_MESSAGE;

    /** @var QueueConsuming */
    public $queueConsuming;
    
    /**
     * BeforeProcessingMessageEvent constructor.
     *
     * @param AMQPMessage $AMQPMessage
     */
    public function __construct(
        Consumer $consumer, 
        AMQPMessage $AMQPMessage,
        QueueConsuming $queueConsuming
    )
    {
        $this->setConsumer($consumer);
        $this->setAMQPMessage($AMQPMessage);
        $this->queueConsuming = $queueConsuming;
    }
}
