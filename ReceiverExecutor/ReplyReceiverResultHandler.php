<?php

namespace OldSound\RabbitMqBundle\ReceiverExecutor;

use OldSound\RabbitMqBundle\Declarations\RpcConsumeOptions;
use OldSound\RabbitMqBundle\RabbitMq\Consumer;
use OldSound\RabbitMqBundle\Receiver\ReceiverException;
use OldSound\RabbitMqBundle\Receiver\ReceiverInterface;
use OldSound\RabbitMqBundle\Receiver\ReplyReceiverInterface;
use PhpAmqpLib\Message\AMQPMessage;

class ReplyReceiverResultHandler implements ReceiverResultHandlerInterface
{
    /** @var RpcConsumeOptions */
    protected $options;

    public function __construct(RpcConsumeOptions $options)
    {
        $this->options = $options;
    }

    public function handle($result, array $messages): void
    {
        if (count($messages) !== 1) {
            throw new \InvalidArgumentException('Replay consumer not support batch messages execution');
        }

        /** @var AMQPMessage $message */
        $message = first($messages);

        if (!($message->get($this->options->replayToProperty) && $message->get($this->options->correlationIdProperty))) {
            throw new \InvalidArgumentException('todo'); // TODO
        }

        $this->sendReply($message->getChannel(), $result, $message->get($this->options->replayToProperty), $message->get($this->options->correlationIdProperty));

        Consumer::handleProcessMessages($message->getChannel(), [$message->getDeliveryTag() => ReceiverInterface::MSG_ACK]);
    }

    protected function sendReply(\AMQPChannel $channel, $reply, $replyTo, $correlationId)
    {
        $body = $this->serializer->serialize($reply);
        $properties = array_merge(
            ['content_type' => 'text/plain'],
            $this->options->replayMessageProperties,
            [$this->options->correlationIdProperty => $correlationId]
        );

        $message = new AMQPMessage($body, $properties);
        $channel->basic_publish($message , '', $replyTo);
    }
}