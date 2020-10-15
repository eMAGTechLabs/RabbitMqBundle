<?php

namespace OldSound\RabbitMqBundle\RabbitMq;

use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Producer, that publishes AMQP Messages
 */
class Producer extends BaseAmqp implements ProducerInterface
{
    /** @var string */
    protected $contentType = 'text/plain';
    /** @var int */
    protected $deliveryMode = 2;

    public function setContentType(string $contentType): Producer
    {
        $this->contentType = $contentType;

        return $this;
    }

    public function setDeliveryMode(int $deliveryMode): Producer
    {
        $this->deliveryMode = $deliveryMode;

        return $this;
    }

    protected function getBasicProperties(): array
    {
        return array('content_type' => $this->contentType, 'delivery_mode' => $this->deliveryMode);
    }

    /**
     * Publishes the message and merges additional properties with basic properties
     */
    public function publish(string $msgBody, string $routingKey = '', array $additionalProperties = array(), array $headers = null): ?bool
    {
        if ($this->autoSetupFabric) {
            $this->setupFabric();
        }

        $msg = new AMQPMessage((string) $msgBody, array_merge($this->getBasicProperties(), $additionalProperties));

        if (!empty($headers)) {
            $headersTable = new AMQPTable($headers);
            $msg->set('application_headers', $headersTable);
        }

        $this->getChannel()->basic_publish($msg, $this->exchangeOptions['name'], (string)$routingKey);
        $this->logger->debug('AMQP message published', array(
            'amqp' => array(
                'body' => $msgBody,
                'routingkeys' => $routingKey,
                'properties' => $additionalProperties,
                'headers' => $headers
            )
        ));
    }
}
