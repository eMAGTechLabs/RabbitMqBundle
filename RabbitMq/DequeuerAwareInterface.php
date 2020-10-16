<?php

namespace OldSound\RabbitMqBundle\RabbitMq;

interface DequeuerAwareInterface
{
    /**
     * @return mixed
     */
    public function setDequeuer(DequeuerInterface $dequeuer);
}
