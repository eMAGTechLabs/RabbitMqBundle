<?php

namespace OldSound\RabbitMqBundle\ExecuteCallbackStrategy;

class FnMessagesProcessor implements MessagesProcessorInterface
{
    /** @var callable */
    private $fn;

    public function __construct(callable $fn)
    {
        $this->fn = $fn;
    }

    public function processMessages(array $messages)
    {
        return call_user_func($this->fn, $messages);
    }
}