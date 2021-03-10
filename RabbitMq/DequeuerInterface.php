<?php

namespace OldSound\RabbitMqBundle\RabbitMq;

interface DequeuerInterface
{
    /**
     * Stop dequeuing messages.
     */
    public function forceStopConsumer(): void;

    /**
     * Set idle timeout
     *
     * @param int $idleTimeout
     *
     * @return void
     */
    public function setIdleTimeout(int $idleTimeout);

    /**
     * Get current idle timeout
     */
    public function getIdleTimeout(): int;
}
