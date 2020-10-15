<?php

namespace OldSound\RabbitMqBundle\RabbitMq;

class Fallback implements ProducerInterface
{
    public function publish(string $msgBody, string $routingKey = '', array $additionalProperties = array()): bool
    {
        return false;
    }
}
