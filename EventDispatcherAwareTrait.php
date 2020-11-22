<?php

namespace OldSound\RabbitMqBundle;

use OldSound\RabbitMqBundle\Event\AMQPEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface as ContractsEventDispatcherInterface;

trait EventDispatcherAwareTrait
{
    /** @var EventDispatcherInterface|null */
    protected $eventDispatcher = null;

    /**
     * @param EventDispatcherInterface $eventDispatcher
     *
     * @return BaseAmqp
     */
    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher)
    {
        $this->eventDispatcher = $eventDispatcher;

        return $this;
    }

    /**
     * @param string $eventName
     * @param AMQPEvent  $event
     */
    protected function dispatchEvent($eventName, AMQPEvent $event)
    {
        if ($this->getEventDispatcher() instanceof ContractsEventDispatcherInterface) {
            $this->getEventDispatcher()->dispatch(
                $event,
                $eventName
            );
        }
    }

    /**
     * @return EventDispatcherInterface|null
     */
    public function getEventDispatcher()
    {
        return $this->eventDispatcher;
    }
}