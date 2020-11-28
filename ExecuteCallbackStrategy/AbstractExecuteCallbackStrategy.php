<?php

namespace OldSound\RabbitMqBundle\ExecuteCallbackStrategy;

use PhpAmqpLib\Message\AMQPMessage;

abstract class AbstractExecuteCallbackStrategy implements ExecuteCallbackStrategyInterface
{
    /** @var MessagesProcessorInterface */
    private $messagesProcessor;

    public function setMessagesProccessor(MessagesProcessorInterface $messagesProcessor)
    {
        $this->messagesProcessor = $messagesProcessor;
    }

    /**
     * @param AMQPMessage[] $meesages
     */
    protected function proccessMessages(array $messages)
    {
        $this->messagesProcessor->processMessages($messages);
    }
}