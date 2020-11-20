<?php

namespace OldSound\RabbitMqBundle\RabbitMq;

class BindingDeclaration
{
    /** @var string */
    public $exchange;

    /** @var string */
    public $destination;

    /** 
     * @link https://www.rabbitmq.com/e2e.html
     * @var bool 
     */
    public $destinationIsExchange = false;

    /** @var string[] */
    public $routingKeys = [];

    /** @var bool */
    public $nowait = false;

    /** @var array */
    public $arguments;
}
