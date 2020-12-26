<?php

namespace OldSound\RabbitMqBundle\ReceiverExecutor;

use OldSound\RabbitMqBundle\Declarations\ConsumeOptions;
use OldSound\RabbitMqBundle\Receiver\ReceiverInterface;
use PhpAmqpLib\Message\AMQPMessage;

class SingleReceiverExecutor implements ReceiverExecutorInterface
{
    public function execute(array $messages, callable $receiver): array
    {
        if (count($messages) !== 1) {
            throw new \InvalidArgumentException('todo');
        }

        /** @var AMQPMessage $message */
        $message = reset($messages);

        return [$message->getDeliveryTag() => $receiver($message)];
    }
}