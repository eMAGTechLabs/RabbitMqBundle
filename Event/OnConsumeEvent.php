<?php

namespace OldSound\RabbitMqBundle\Event;

use OldSound\RabbitMqBundle\RabbitMq\Consumer;

/**
 * Class OnConsumeEvent
 *
 * @package OldSound\RabbitMqBundle\Command
 */
class OnConsumeEvent extends AbstractAMQPEvent
{
    const NAME = 'old_sound_rabbit_mq.on_consume';

    /**
     * OnConsumeEvent constructor.
     *
     * @param Consumer $consumer
     */
    public function __construct(Consumer $consumer)
    {
        $this->consumer = $consumer;
    }
}
