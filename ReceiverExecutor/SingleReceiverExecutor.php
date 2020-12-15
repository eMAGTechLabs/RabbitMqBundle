<?php

namespace OldSound\RabbitMqBundle\ReceiverExecutor;

use OldSound\RabbitMqBundle\Declarations\ConsumeOptions;
use OldSound\RabbitMqBundle\Receiver\ReceiverInterface;

class SingleReceiverExecutor implements ReceiverExecutorInterface
{
    /**
     * @param ReceiverInterface $receiver
     */
    public function execute($messages, $receiver): array
    {
        if (!$this->support($receiver)) {
            throw new \InvalidArgumentException('todo');
        }
        if (count($messages) !== 1) {
            throw new \InvalidArgumentException('todo');
        }

        return [$receiver->execute($messages[0])];
    }

    public function support($receiver): bool
    {
        return $receiver instanceof ReceiverInterface;
    }
}