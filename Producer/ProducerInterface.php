<?php

namespace OldSound\RabbitMqBundle\Producer;

interface ProducerInterface
{
    const DELIVERY_MODE_NON_PERSISTENT = 1;
    const DELIVERY_MODE_PERSISTENT = 2;
    
    public function publish(string $body, string $routingKey = '', array $additionalProperties = [], ?array $headers = null): void;
}
