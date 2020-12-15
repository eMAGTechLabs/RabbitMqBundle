<?php


namespace OldSound\RabbitMqBundle\ReceiverExecutor;

use OldSound\RabbitMqBundle\Declarations\BatchConsumeOptions;
use OldSound\RabbitMqBundle\Receiver\BatchReceiverInterface;

class BatchReceiverExecutor implements ReceiverExecutorInterface
{
    /**
     * @param BatchReceiverInterface $receiver
     */
    public function execute($messages, $receiver): array
    {
        if (!$this->support($receiver)) {
            throw new \InvalidArgumentException('TOOD');
        }
        $flags = $receiver->batchExecute($messages);

        if (!is_array($flags)) { // flat flag for each delivery tag
            $flag = $flags;
            $flags = [];
            foreach ($messages as $message) {
                $flags[$message->getDeliveryTag()] = $flag;
            }
        } else if (count($flags) !== count($messages)) {
            throw new AMQPRuntimeException(// TODO
                'Method batchExecute() should return an array with elements equal with the number of messages processed'
            );
        }

        return $flags;
    }

    public function support($receiver): bool
    {
        return $receiver instanceof BatchReceiverInterface;
    }
}