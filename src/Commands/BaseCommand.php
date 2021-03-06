<?php

/**
 * These commands use the `@authenticated`
 * attribute to signal Terminus to require an authenticated session to
 * use this command.
 */

namespace Pantheon\TerminusPowerTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\TerminusLando\Model\Greeter;
use Consolidation\AnnotatedCommand\CommandData;

use Pantheon\TerminusBuildTools\Commands\ProjectCreateCommand;
use Pantheon\TerminusBuildTools\Commands\BuildToolsBase;
use Pantheon\Terminus\DataStore\FileStore;
use Pantheon\TerminusBuildTools\ServiceProviders\ProviderEnvironment;
use Symfony\Component\Console\Style\SymfonyStyle;
use Pantheon\Terminus\Models\Environment;
use Pantheon\Terminus\Models\Site;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Robo\Collection\CollectionBuilder;
use Robo\Contract\BuilderAwareInterface;
use Robo\TaskAccessor;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Robo\LoadAllTasks;

/**
 * Provides commands to create and manage decoupled sites on the Pantheon platform.
 * Extends terminus-build-tools, provides recommended default configuration and project architecture,
 * CI/CD templates, generates local development environment.
 *
 */
class BaseCommand extends ProjectCreateCommand {

    /**
     * Provide default configuration to terminus-build-tools project create command.
     *
     * @hook pre-command build:project:create
     */
    public function adjustDefaultOptions(CommandData $commandData)
    {

        $ci_template = $commandData->input()->getOption('ci-template');

        if (str_contains($ci_template, 'pantheon-systems/tbt-ci-templates')) {
            $commandData->input()->setOption('ci-template', 'git@github.com:lcatlett/tbt-ci-templates.git');
        }

        $commandData->input()->setOption('keep', 'TRUE');
        $commandData->input()->setOption('visibility', 'private');
        $commandData->input()->setOption('stability', 'dev');

        $options_after = $commandData->input()->getOptions();
    }

    /**
     * Configures Lando local development environment.
     * @authorize
     *
     * @command build:lando:setup
     * @aliases lando:setup
     */

    public function landoSetup()
    {
        $this->log()->notice('Generating Lando local development environment configuration');

        // Get the project name
        if (empty($site_name)) {
            $site_name = basename(getcwd());
        }

        // If a template.lando.yml file exists in the template repository, copy it to a new .lando.yml.
        if (file_exists('template.lando.yml')) {
            $this->log()->notice('template.lando.yml exists, generating new .lando.yml');

            $result = $this->taskFilesystemStack()
                ->copy('template.lando.yml', '.lando.yml')
                ->stopOnFail()
                ->run();

            if (!$result->wasSuccessful()) {
                $this->log()->notice('Error: Unable to copy template.lando.yml to .lando.yml');
                return;
            }

            $result = $this->taskReplaceInFile('.lando.yml')
                ->from('%SITE_NAME%')
                ->to($site_name)
                ->run();

            if (!$result->wasSuccessful()) {
                $this->log()->notice('Error: Unable to generate config for .lando.yml');
                return;
            }

            $builder = $this->collectionBuilder();

            $builder
                ->taskGitStack()
                ->stopOnFail()
                ->add('.lando.yml')
                ->commit('Added .lando.yml local development environment configuration.');

            $builder->taskFilesystemStack()
                ->remove('template.lando.yml');

            return $builder->run();

            if (!$builder->wasSuccessful()) {
                $this->log()->notice('Error: Unable to commit .lando.yml to local repository.');
                return;
            }
            $this->log()->notice('Success! Lando configuration was generated and committed to the local repository. Start lando with "lando start"');
        }

    }

    /**
     * Enables Pantheon site plan add-ons.
     *
     * @command build:addons:enable
     * @param string $site_name Pantheon site name.
     *
     * @aliases addons:enable
     *
     */

    public function setupAddOns($site_name) {
        $this->log()->notice('Setting up Pantheon site plan add-ons');

        // Enable Redis.
        $this->log()->notice('Enabling Redis on Pantheon site.');
        passthru("terminus redis:enable $site_name --no-interaction");

        // Enable New Relic.
        $this->log()->notice('Enabling New Relic on Pantheon site.');
        passthru("terminus new-relic:enable $site_name --no-interaction");
    }

    /** 
     * Configures Lando for local behat testing.
     * @authorize
     *
     * @command build:lando:behat
     * @aliases lando:behat
     */
    public function setupBehat()
    {
        $this->log()->notice('Generating ./tests/behat/behat-lando.yml files for Behat configuration');
        // Get the project name
        if (empty($site_name)) {
            $site_name = basename(getcwd());
        }
        if (file_exists('.ci/test/template.behat-lando.yml')) {
            $this->configureBehat('./tests/behat/behat-lando.yml', '.ci/test/template.behat-lando.yml', $site_name);
        }

        $this->log()->notice('Success! Behat configuration was generated and committed to the local repository. Run Behat by running "lando behat"');
    }

    /**
     * Helper method for configuring Behat for lando.
     * 
     * @param string $$generate_file Generate file relevant path.
     * @param string $template_file Template relevant path.
     * @param string $site_name Pantheon site name.
     */
    protected function configureBehat($generate_file, $template_file, $site_name)
    {
        $this->log()->notice($template_file . ' exists, generating new ' . $generate_file);

            $result = $this->taskFilesystemStack()
                ->copy($template_file, $generate_file)
                ->stopOnFail()
                ->run();

            if (!$result->wasSuccessful()) {
                $this->log()->notice('Error: Unable to copy ' . $template_file . ' to ' . $generate_file);
                return;
            }

            $result = $this->taskReplaceInFile($generate_file)
                ->from('%SITE_NAME%')
                ->to($site_name)
                ->run();

            if (!$result->wasSuccessful()) {
                $this->log()->notice('Error: Unable to generate config for ' . $generate_file);
                return;
            }

            $builder = $this->collectionBuilder();

            $builder
                ->taskGitStack()
                ->stopOnFail()
                ->add($generate_file)
                ->commit('Added ' . $generate_file . ' local behat testing configuration.');

            $builder->taskFilesystemStack()
                ->remove($template_file);

            return $builder->run();

            if (!$builder->wasSuccessful()) {
                $this->log()->notice('Error: Unable to commit ' . $generate_file . ' to local repository.');
                return;
            }
    }

}
