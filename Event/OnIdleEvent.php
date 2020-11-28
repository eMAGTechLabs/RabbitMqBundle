<?php

namespace OldSound\RabbitMqBundle\Event;

use OldSound\RabbitMqBundle\RabbitMq\Consumer;

/**
 * Class OnIdleEvent
 *
 * @package OldSound\RabbitMqBundle\Command
 */
class OnIdleEvent extends AbstractAMQPEvent
{
    const NAME = 'old_sound_rabbit_mq.on_idle';
    /**
     * @var bool
     */
    private $forceStop;

    /**
     * OnConsumeEvent constructor.
     *
     * @param Consumer $consumer
     */
    public function __construct(Consumer $consumer)
    {
        $this->consumer = $consumer;
        $this->forceStop = true;
    }

    /**
     * @return boolean
     */
    public function isForceStop()
    {
        return $this->forceStop;
    }

    /**
     * @param boolean $forceStop
     */
    public function setForceStop($forceStop)
    {
        $this->forceStop = $forceStop;
    }
}
