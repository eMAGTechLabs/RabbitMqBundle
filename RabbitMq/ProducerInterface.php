<?php

namespace OldSound\RabbitMqBundle\RabbitMq;

interface ProducerInterface
{
    /**
     * Publish a message
     */
    public function publish(string $msgBody, string $routingKey = '', array $additionalProperties = array()): ?bool;
}
