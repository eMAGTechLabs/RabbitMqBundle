<?php


namespace OldSound\RabbitMqBundle\Receiver;

interface ReplyReceiverInterface
{
    /**
     * @param AMQPMessage $message
     * @return mixed
     * @throws ReceiverException
     * @throws NotReadyReceiveException
     */
    public function execute(AMQPMessage $message);
}