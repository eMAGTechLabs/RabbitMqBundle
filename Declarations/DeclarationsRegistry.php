<?php

namespace OldSound\RabbitMqBundle\Declarations;

use OldSound\RabbitMqBundle\RabbitMq\BindingDeclaration;
use OldSound\RabbitMqBundle\RabbitMq\ExchangeDeclaration;
use OldSound\RabbitMqBundle\RabbitMq\QueueDeclaration;

class DeclarationsRegistry
{
    /** @var ExchangeDeclaration[] */
    public $exchanges;
    /** @var QueueDeclaration[] */
    public $queues;
    /** @var BindingDeclaration[] */
    public $bindings = [];
    
    public function addExchange(ExchangeDeclaration $exchangeDeclaration)
    {
        $this->exchanges[] = $exchangeDeclaration;
    }
    
    public function addQueue(QueueDeclaration $queueDeclaration)
    {
        $this->queues[] = $queueDeclaration;    
    }
}