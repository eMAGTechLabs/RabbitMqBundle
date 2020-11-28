<?php

namespace OldSound\RabbitMqBundle;

use OldSound\RabbitMqBundle\Event\AbstractAMQPEvent;
use OldSound\RabbitMqBundle\Event\AMQPEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface as ContractsEventDispatcherInterface;

trait EventDispatcherAwareTrait
{
    /** @var EventDispatcherInterface|null */
    protected $eventDispatcher = null;

    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher)
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    protected function dispatchEvent(string $eventName, AbstractAMQPEvent $event)
    {
        if (null !== $this->eventDispatcher) {
            $this->eventDispatcher->dispatch($event, $eventName);
        }
    }

    public function getEventDispatcher(): ?EventDispatcherInterface
    {
        return $this->eventDispatcher;
    }
}