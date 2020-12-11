<?php

namespace OldSound\RabbitMqBundle\Event;

use OldSound\RabbitMqBundle\RabbitMq\Consumer;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Class AMQPEvent
 *
 * @package OldSound\RabbitMqBundle\Event
 * @codeCoverageIgnore
 */
class AMQPEvent extends AbstractAMQPEvent
{
    const ON_CONSUME                = 'on_consume';
    const ON_IDLE                   = 'on_idle';
    const BEFORE_PROCESSING_MESSAGE = 'before_processing';
    const AFTER_PROCESSING_MESSAGE  = 'after_processing';

    /**
     * @var AMQPMessage
     */
    protected $AMQPMessage;

    /**
     * @var Consumer
     */
    protected $consumer;

    public function getAMQPMessage(): AMQPMessage
    {
        return $this->AMQPMessage;
    }

    public function setAMQPMessage(AMQPMessage $AMQPMessage): AMQPEvent
    {
        $this->AMQPMessage = $AMQPMessage;

        return $this;
    }

    public function getConsumer(): Consumer
    {
        return $this->consumer;
    }

    public function setConsumer(Consumer $consumer): AMQPEvent
    {
        $this->consumer = $consumer;

        return $this;
    }
}
