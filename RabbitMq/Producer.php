<?php

namespace OldSound\RabbitMqBundle\RabbitMq;

use OldSound\RabbitMqBundle\Declarations\DeclarationsRegistry;
use OldSound\RabbitMqBundle\Declarations\Declarator;
use OldSound\RabbitMqBundle\EventDispatcherAwareTrait;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;

/**
 * Producer, that publishes AMQP Messages
 */
class Producer implements ProducerInterface
{
    use LoggerAwareTrait;
    use EventDispatcherAwareTrait;

    public $contentType = 'text/plain';
    public $deliveryMode = 2;
    public $autoDeclare = true;

    /** @var AMQPChannel */
    protected $channel;
    /** @var string */
    protected $exchange;

    public function __construct(AMQPChannel $channel, string $exchange)
    {
        $this->channel = $channel;
        $this->exchange = $exchange;
        $this->logger = new NullLogger();
    }

    /**
     * Publishes the message and merges additional properties with basic properties
     *
     * @param string $msgBody
     * @param string $routingKey
     * @param array $additionalProperties
     * @param array $headers
     */
    public function publish($msgBody, string $routingKey = '', array $additionalProperties = [], array $headers = null)
    {
        if ($this->autoDeclare) {
            // TODO (new Declarator($this->channel))->declareForExchange($this->exchange);
        }

        $msg = new AMQPMessage((string) $msgBody, array_merge([
            'content_type' => $this->contentType,
            'delivery_mode' => $this->deliveryMode
        ], $additionalProperties));

        if (!empty($headers)) {
            $headersTable = new AMQPTable($headers);
            $msg->set('application_headers', $headersTable);
        }

        $this->channel->basic_publish($msg, $this->exchange, $routingKey);
        $this->logger->debug('AMQP message published', [
            'amqp' => [
                'body' => $msgBody,
                'routingkeys' => $routingKey,
                'properties' => $additionalProperties,
                'headers' => $headers
            ]
        ]);
    }
}
