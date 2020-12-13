<?php

namespace OldSound\RabbitMqBundle\Producer;

class NullProducer implements ProducerInterface
{
    public function publish(string $body, string $routingKey = '', array $additionalProperties = [], ?array $headers = null): void
    {
    }
}
