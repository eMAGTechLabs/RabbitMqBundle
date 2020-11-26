<?php

namespace OldSound\RabbitMqBundle\Declarations;

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

    public function addBinding(BindingDeclaration $bindingDeclaration)
    {
        $this->bindings[] = $bindingDeclaration;
    }

    /**
     * @param ExchangeDeclaration $exchange
     * @return BindingDeclaration[]
     */
    public function getBindingsByExchange(ExchangeDeclaration $exchange): array
    {
        return array_filter($this->bindings, function ($binding) use ($exchange) {
            return $binding->exchange === $exchange->name || ($binding->destinationIsExchange && $binding->destination === $exchange->name);
        });
    }

    public function initDeclarationsRefs()
    {
        foreach ($this->queues as $queue) {
            $bindings = array_filter($this->bindings, function ($binding) use ($queue) {
                return !$binding->destinationIsExchange && $binding->destination === $queue->name;
            });
            $queue->bindings += $bindings;
        }

        foreach ($this->exchanges as $exchange) {

            $exchange->bindings += $bindings;
        }
    }
}