<?php

namespace OldSound\RabbitMqBundle\RabbitMq;

class AmqpPartsHolder
{
    /** @var array */
	protected $parts;

	public function __construct()
	{
		$this->parts = array();
	}

	public function addPart(string $type, BaseAmqp $part): void
	{
		$this->parts[$type][] = $part;
	}

	public function getParts(string $type): array
	{
        $type = (string) $type;
		return isset($this->parts[$type]) ? $this->parts[$type] : array();
	}
}
