<?php

namespace OldSound\RabbitMqBundle\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to delete a queue
 */
class DeleteCommand extends ConsumerCommand
{
    protected function configure()
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Consumer Name')
             ->setDescription('Delete a consumer\'s queue')
             ->addOption('no-confirmation', null, InputOption::VALUE_NONE, 'Whether it must be confirmed before deleting');

        $this->setName('rabbitmq:delete');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $noConfirmation = (bool) $input->getOption('no-confirmation');

        if (!$noConfirmation && $input->isInteractive()) {
            $confirmation = $this->getHelper('dialog')->askConfirmation($output, sprintf('<question>Are you sure you wish to delete "%s" consumer\'s queue?(y/n)</question>', $input->getArgument('name')), false);
            if (!$confirmation) {
                $output->writeln('<error>Deletion cancelled!</error>');

                return 1;
            }
        }

        $this->consumer = $this->getContainer()
            ->get(sprintf($this->getConsumerService(), $input->getArgument('name')));
        $this->consumer->delete();
    }
}