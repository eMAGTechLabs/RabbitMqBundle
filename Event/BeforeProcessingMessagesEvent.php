<?php

namespace OldSound\RabbitMqBundle\Event;

use OldSound\RabbitMqBundle\Declarations\ConsumeOptions;
use OldSound\RabbitMqBundle\RabbitMq\Consumer;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Class BeforeProcessingMessageEvent
 *
 * @package OldSound\RabbitMqBundle\Command
 */
class BeforeProcessingMessagesEvent extends AbstractAMQPEvent
{
    const NAME = 'old_sound_rabbit_mq.before_processing';

    /** @var ConsumeOptions */
    public $queueConsuming;

    /**
     * BeforeProcessingMessageEvent constructor.
     *
     * @param AMQPMessage $AMQPMessage
     */
    public function __construct(array $messages, ConsumeOptions $queueConsuming)
    {
        $this->messages = $messages;
        $this->queueConsuming = $queueConsuming;
    }
}
