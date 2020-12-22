<?php

namespace OldSound\RabbitMqBundle\ReceiverExecutor;

use OldSound\RabbitMqBundle\Declarations\RpcConsumeOptions;
use OldSound\RabbitMqBundle\Receiver\ReceiverException;
use OldSound\RabbitMqBundle\Receiver\ReceiverInterface;
use OldSound\RabbitMqBundle\Receiver\ReplyReceiverInterface;

class ReplyReceiverExecutor implements ReceiverExecutorInterface
{
    /** @var RpcConsumeOptions */
    protected $options;

    public function __construct(RpcConsumeOptions $options)
    {
        $this->options = $options;
    }

    public function execute(array $messages, callable $receiver): array
    {
        if (count($messages) !== 1) {
            throw new \InvalidArgumentException('todo');
        }

        $message = $messages[0];

        if (!($message->get($this->options->replayToProperty) && $message->get($this->options->correlationIdProperty))) {
            throw new \InvalidArgumentException('todo'); // TODO
        }

        try {
            $reply = $receiver($message);
        } catch (ReceiverException $exception) {
            return [$exception->getCode()];
        }
        $this->sendReply($message->getChannel(), $reply, $message->get($this->options->replayToProperty), $message->get($this->options->correlationIdProperty));

        return [ReceiverInterface::MSG_ACK];
    }

    protected function sendReply(\AMQPChannel $channel, $reply, $replyTo, $correlationId)
    {
        $body = $this->serializer->serialize($reply);
        $message = new AMQPMessage($body, ['content_type' => 'text/plain'] + $this->options->replayMessageProperties + [
            $this->options->correlationIdProperty => $correlationId,
        ]);
        $channel->basic_publish($message , '', $replyTo);
    }
}