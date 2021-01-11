<?php

namespace OldSound\RabbitMqBundle\ReceiverExecutor;

use OldSound\RabbitMqBundle\Declarations\ConsumeOptions;
use OldSound\RabbitMqBundle\RabbitMq\Consumer;
use OldSound\RabbitMqBundle\Receiver\ReceiverInterface;
use PhpAmqpLib\Message\AMQPMessage;

class SingleReceiverResultHandler implements ReceiverResultHandlerInterface
{
    public function handle($result, array $messages, ConsumeOptions $options): void
    {
        if (count($messages) !== 1) {
            throw new \InvalidArgumentException('todo');
        }

        /** @var AMQPMessage $message */
        $message = reset($messages);

        if ($options->noAck) {
            if ($result !== null) {
                throw new \InvalidArgumentException('sdf');
            }
        } else {
            if ($result === true) {
                $result = ReceiverInterface::MSG_ACK;
            } else if ($result === false) {
                $result = ReceiverInterface::MSG_REJECT;
            }

            Consumer::handleProcessMessages($message->getChannel(), [$message->getDeliveryFlag() => $result]);
        }
    }
}