<?php


namespace OldSound\RabbitMqBundle\ReceiverExecutor;

class BatchReceiverExecutor implements ReceiverExecutorInterface
{
    public function execute(array $messages, callable $receiver): array
    {
        $flags = $receiver($messages);

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
}