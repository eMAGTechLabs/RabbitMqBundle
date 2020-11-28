<?php

namespace OldSound\RabbitMqBundle\Event;

use OldSound\RabbitMqBundle\RabbitMq\Consumer;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Class AfterProcessingMessageEvent
 *
 * @package OldSound\RabbitMqBundle\Event
 */
class AfterProcessingMessagesEvent extends AMQPEvent
{
    const NAME = AMQPEvent::AFTER_PROCESSING_MESSAGE;

    /**
     * AfterProcessingMessageEvent constructor.
     *
     * @param AMQPMessage $AMQPMessage
     */
    public function __construct(Consumer $consumer, array $messages)
    {
        $this->setConsumer($consumer);
    }
}
