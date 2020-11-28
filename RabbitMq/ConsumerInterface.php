<?php

namespace OldSound\RabbitMqBundle\RabbitMq;

use OldSound\RabbitMqBundle\RabbitMq\Exception\RpcResponseException;
use PhpAmqpLib\Message\AMQPMessage;

interface ConsumerInterface
{
    /**
     * Flag for message ack
     */
    const MSG_ACK = 1;

    /**
     * Flag single for message nack and requeue
     */
    const MSG_SINGLE_NACK_REQUEUE = 2;

    /**
     * Flag for reject and requeue
     */
    const MSG_REJECT_REQUEUE = 0;

    /**
     * Flag for reject and drop
     */
    const MSG_REJECT = -1;

    /**
     * Flag for consumers that wants to handle ACKs on their own
     */
    const MSG_ACK_SENT = -2;

    /**
     * @param AMQPMessage $message
     * @return int|true|false|RpcReponse false to reject and requeue, any other value to acknowledge
     * @throws StopConsumerException
     * @throws RpcResponseException
     */
    public function execute(AMQPMessage $message);
}
