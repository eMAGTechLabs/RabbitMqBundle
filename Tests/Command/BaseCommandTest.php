<?php

namespace OldSound\RabbitMqBundle\Tests\Command;

use OldSound\RabbitMqBundle\Command\BaseConsumerCommand;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Input\InputDefinition;

abstract class BaseCommandTest extends TestCase
{
    /** @var MockObject | Application */
    protected $application;
    /** @var MockObject | InputDefinition */
    protected $definition;
    /** @var MockObject | HelperSet */
    protected $helperSet;
    /** @var mixed */
    protected $command;

    protected function setUp(): void
    {
        $this->application = $this->getMockBuilder('Symfony\\Component\\Console\\Application')
            ->disableOriginalConstructor()
            ->getMock();
        $this->definition = $this->getMockBuilder('Symfony\\Component\\Console\\Input\\InputDefinition')
            ->disableOriginalConstructor()
            ->getMock();
        $this->helperSet = $this->getMockBuilder('Symfony\\Component\\Console\\Helper\\HelperSet')->getMock();

        $this->application->expects($this->any())
            ->method('getDefinition')
            ->will($this->returnValue($this->definition));
        $this->definition->expects($this->any())
            ->method('getArguments')
            ->will($this->returnValue(array()));
    }
}
