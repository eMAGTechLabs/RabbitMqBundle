<?php

namespace OldSound\RabbitMqBundle\RabbitMq;

use PhpAmqpLib\Message\AMQPMessage;

class RpcClient extends BaseAmqp
{
    /** @var int */
    protected $requests = 0;
    /** @var array */
    protected $replies = array();
    /** @var bool */
    protected $expectSerializedResponse;
    /** @var int  */
    protected $timeout = 0;
    /** @var string */
    protected $notifyCallback;
    /** @var string|null */
    private $queueName;
    /** @var string */
    private $unserializer = 'unserialize';
    /** @var string|null */
    private $directReplyTo;
    /** @var string|null */
    private $directConsumerTag;

    public function initClient(bool $expectSerializedResponse = true): void
    {
        $this->expectSerializedResponse = $expectSerializedResponse;
    }

    public function addRequest(string $msgBody, string $server, string $requestId = null, string $routingKey = '', int $expiration = 0): void
    {
        if (empty($requestId)) {
            throw new \InvalidArgumentException('You must provide a $requestId');
        }

        if (0 == $this->requests) {
            // On first addRequest() call, clear all replies
            $this->replies = array();

            if ($this->directReplyTo) {
                // On direct reply-to mode, make initial consume call
                $this->directConsumerTag = $this->getChannel()->basic_consume('amq.rabbitmq.reply-to', '', false, true, false, false, array($this, 'processMessage'));
            }
        }

        $msg = new AMQPMessage($msgBody, array('content_type' => 'text/plain',
                                               'reply_to' => $this->directReplyTo
                                                   ? 'amq.rabbitmq.reply-to' // On direct reply-to mode, use predefined queue name
                                                   : $this->getQueueName(),
                                               'delivery_mode' => 1, // non durable
                                               'expiration' => $expiration*1000,
                                               'correlation_id' => $requestId));

        $this->getChannel()->basic_publish($msg, $server, $routingKey);

        $this->requests++;

        if ($expiration > $this->timeout) {
            $this->timeout = $expiration;
        }
    }

    public function getReplies(): array
    {
        if ($this->directReplyTo) {
            $consumer_tag = $this->directConsumerTag;
        } else {
            $consumer_tag = $this->getChannel()->basic_consume($this->getQueueName(), '', false, true, false, false, array($this, 'processMessage'));
        }

        try {
            while (count($this->replies) < $this->requests) {
                $this->getChannel()->wait(null, false, $this->timeout);
            }
        } finally {
            $this->getChannel()->basic_cancel($consumer_tag);
        }

        $this->directConsumerTag = null;
        $this->requests = 0;
        $this->timeout = 0;

        return $this->replies;
    }

    public function processMessage(AMQPMessage $msg): void
    {
        $messageBody = $msg->body;
        if ($this->expectSerializedResponse) {
            $messageBody = call_user_func($this->unserializer, $messageBody);
        }
        if ($this->notifyCallback !== null) {
            call_user_func($this->notifyCallback, $messageBody);
        }

        $this->replies[$msg->get('correlation_id')] = $messageBody;
    }

    protected function getQueueName(): ?string
    {
        if (null === $this->queueName) {
            list($this->queueName, ,) = $this->getChannel()->queue_declare("", false, false, true, false);
        }

        return $this->queueName;
    }

    /**
     * @param callable $unserializer
     */
    public function setUnserializer($unserializer): void
    {
        $this->unserializer = $unserializer;
    }

    /**
     * @param mixed $callback
     */
    public function notify($callback): void
    {
        if (is_callable($callback)) {
            $this->notifyCallback = $callback;
        } else {
            throw new \InvalidArgumentException('First parameter expects to be callable');
        }
    }

    public function setDirectReplyTo(string $directReplyTo): void
    {
        $this->directReplyTo = $directReplyTo;
    }

    public function reset(): void
    {
        $this->replies = array();
        $this->requests = 0;
    }
}
