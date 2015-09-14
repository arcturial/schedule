<?php
namespace Arcturial\Schedule;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Yaml\Yaml;
use Cron\CronExpression;

class RunCommand extends Command
{
    private $crontab, $log;

    /**
     * Construct a new schedule
     *
     * @param string $crontab The path to the crontab file
     * @param string $log     The log path
     */
    public function __construct($crontab, $log = '/dev/null')
    {
        $this->crontab = $crontab;
        $this->log = $log;
        parent::__construct();
    }

    /**
     * Return the configuration definition.
     *
     * @return NodeInterface
     */
    private function getConfigDefinition()
    {
        $treeBuilder = new TreeBuilder;

        $treeBuilder
            ->root('schedule')
                ->children()
                    ->arrayNode('environment')
                        ->prototype('scalar')
                        ->end()
                    ->end()
                    ->arrayNode('commands')
                        ->prototype('scalar')
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder->buildTree();
    }

    /**
     * Validate the configuration values
     *
     * @param string $config The config filename
     *
     * @return array
     */
    private function processConfig($config)
    {
        $processor = new Processor;

        return $processor->process($this->getConfigDefinition(), Yaml::parse(file_get_contents($config)));
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('schedule:run')
            ->setDescription('Run the scheduling component. All scheduled tasks due will be forked as other processes.')
            ->addOption('dry-run', 'dr', InputOption::VALUE_NONE, 'Test the schedule before executing.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dryRun = $input->getOption('dry-run');
        $content = $this->processConfig($this->crontab);
        $enviroment = isset($content['env']) ? $content['env'] : array();
        $commands = isset($content['commands']) ? $content['commands'] : array();

        foreach ($commands as $entry)
        {
            preg_match('/\[(.*)\](.*)/', $entry, $match);
            $tab = $match[1];
            $command = $match[2];

            $cron = CronExpression::factory($tab);
            $output->writeLn('<info>- Checking schedule entry: ' . $tab . '</info>');

            // If the cron is not due for execution, just skip
            if (!$cron->isDue()) continue;

            // Construct the fork command
            $fork = $command . " > " . $this->log . " 2>&1 & echo $!";
            $output->writeLn('<info>- Command:</info> ' . $fork);

            // Start a new process
            if (!$dryRun)
            {
                exec($fork, $pid);
                $pid = current($pid);
                $output->writeLn('<info>- Process created:</info> ' . $pid);
            }
            else
            {
                $output->writeLn('<info>- Skipping execution (--dry-run)</info>');
            }
        }
    }
}