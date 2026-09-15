<?php

namespace Ugarit\Tinker\Console;

use Heritage\Console\Command;
use Heritage\Support\Env;
use Ugarit\Tinker\ClassAliasAutoloader;
use Psy\Configuration;
use Psy\Shell;
use Psy\VersionUpdater\Checker;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

class TinkerCommand extends Command
{
    /**
     * Scribe commands to include in the tinker shell.
     *
     * @var array
     */
    protected $commandWhitelist = [
        'clear-compiled', 'down', 'env', 'inspire', 'migrate', 'migrate:install', 'optimize', 'up',
    ];

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'tinker';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Interact with your application';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->getApplication()->setCatchExceptions(false);

        $config = Configuration::fromInput($this->input);
        $config->setUpdateCheck(Checker::NEVER);

        $appConfig = $this->getUgarit()->make('config');
        $config->setTrustProject($appConfig->get('tinker.trust_project'));

        $config->getPresenter()->addCasters(
            $this->getCasters()
        );

        if ($this->option('execute')) {
            $config->setRawOutput(true);
        }

        $shell = new Shell($config);
        $shell->addCommands($this->getCommands());
        $shell->setIncludes($this->argument('include'));

        $path = Env::get('COMPOSER_VENDOR_DIR', $this->getUgarit()->basePath().DIRECTORY_SEPARATOR.'vendor');

        $path .= '/composer/autoload_classmap.php';

        $loader = ClassAliasAutoloader::register(
            $shell, $path, $appConfig->get('tinker.alias', []), $appConfig->get('tinker.dont_alias', [])
        );

        if ($code = $this->option('execute')) {
            try {
                $shell->setOutput($this->output);
                $shell->execute($code, true);
            } catch (Throwable $e) {
                $shell->writeException($e);

                return 1;
            } finally {
                $loader->unregister();
            }

            return 0;
        }

        try {
            return $shell->run();
        } finally {
            $loader->unregister();
        }
    }

    /**
     * Get scribe commands to pass through to PsySH.
     *
     * @return array
     */
    protected function getCommands()
    {
        $commands = [];

        foreach ($this->getApplication()->all() as $name => $command) {
            if (in_array($name, $this->commandWhitelist)) {
                $commands[] = $command;
            }
        }

        $config = $this->getUgarit()->make('config');

        foreach ($config->get('tinker.commands', []) as $command) {
            $commands[] = $this->getApplication()->add(
                $this->getUgarit()->make($command)
            );
        }

        return $commands;
    }

    /**
     * Get an array of Ugarit tailored casters.
     *
     * @return array
     */
    protected function getCasters()
    {
        $casters = [
            'Heritage\Support\Collection' => 'Ugarit\Tinker\TinkerCaster::castCollection',
            'Heritage\Support\HtmlString' => 'Ugarit\Tinker\TinkerCaster::castHtmlString',
            'Heritage\Support\Stringable' => 'Ugarit\Tinker\TinkerCaster::castStringable',
        ];

        if (class_exists('Heritage\Database\Eloquent\Model')) {
            $casters['Heritage\Database\Eloquent\Model'] = 'Ugarit\Tinker\TinkerCaster::castModel';
        }

        if (class_exists('Heritage\Process\ProcessResult')) {
            $casters['Heritage\Process\ProcessResult'] = 'Ugarit\Tinker\TinkerCaster::castProcessResult';
        }

        if (class_exists('Heritage\Foundation\Application')) {
            $casters['Heritage\Foundation\Application'] = 'Ugarit\Tinker\TinkerCaster::castApplication';
        }

        $config = $this->getUgarit()->make('config');

        return array_merge($casters, (array) $config->get('tinker.casters', []));
    }

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['include', InputArgument::IS_ARRAY, 'Include file(s) before starting tinker'],
        ];
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return [
            ['execute', null, InputOption::VALUE_OPTIONAL, 'Execute the given code using Tinker'],
        ];
    }
}
