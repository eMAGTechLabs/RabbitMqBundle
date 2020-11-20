<?php


namespace OldSound\RabbitMqBundle\RabbitMq;


class QueueDeclaration
{
    public $name;
    public $passive;
    public $durable;
    public $exclusive;
    public $autoDelete;
    public $nowait;
    public $arguments;
    public $ticket;
    public $declare;
    /** @var BindingDeclaration[] */
    public $bindings = [];
    
    public function setAnonymus() {
        $this->setQueueOptions(array(
            'name' => '',
            'passive' => false,
            'durable' => false,
            'exclusive' => true,
            'auto_delete' => true,
            'nowait' => false,
            'arguments' => null,
            'ticket' => null
        ));
    }
    
    public function declure() {
        foreach ($this->queues as $name => $options) {
            list($queueName, ,) = $this->getChannel()->queue_declare($name, $options['passive'],
                $options['durable'], $options['exclusive'],
                $options['auto_delete'], $options['nowait'],
                $options['arguments'], $options['ticket']);

            if (isset($options['routing_keys']) && count($options['routing_keys']) > 0) {
                foreach ($options['routing_keys'] as $routingKey) {
                    $this->queueBind($queueName, $this->exchangeOptions['name'], $routingKey, $options['arguments'] ?? []);
                }
            } else {
                $this->queueBind($queueName, $this->exchangeOptions['name'], $this->routingKey, $options['arguments'] ?? []);
            }
        }
    }
}