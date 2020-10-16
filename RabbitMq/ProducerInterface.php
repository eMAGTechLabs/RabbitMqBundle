<?php

namespace OldSound\RabbitMqBundle\RabbitMq;

interface ProducerInterface
{
    /**
     * Publish a message
     * @return mixed
     */
    public function publish(string $msgBody, string $routingKey = '', array $additionalProperties = array());
}
