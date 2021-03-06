<?php
/**
 * Copyright (c) 2010-2012 Arne Blankerts <arne@blankerts.de>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without modification,
 * are permitted provided that the following conditions are met:
 *
 *   * Redistributions of source code must retain the above copyright notice,
 *     this list of conditions and the following disclaimer.
 *
 *   * Redistributions in binary form must reproduce the above copyright notice,
 *     this list of conditions and the following disclaimer in the documentation
 *     and/or other materials provided with the distribution.
 *
 *   * Neither the name of Arne Blankerts nor the names of contributors
 *     may be used to endorse or promote products derived from this software
 *     without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT  * NOT LIMITED TO,
 * THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR
 * PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER ORCONTRIBUTORS
 * BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 * OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * Exit codes:
 *   0 - No error
 *   1 - Execution Error
 *   3 - Parameter Error
 *
 * @package    phpDox
 * @author     Arne Blankerts <arne@blankerts.de>
 * @copyright  Arne Blankerts <arne@blankerts.de>, All rights reserved.
 * @license    BSD License
 *
 */

namespace TheSeer\phpDox {

    use TheSeer\fDOM\fDOMDocument;
    use TheSeer\fDOM\fDOMException;

    class CLI {

        /**
         * Factory instance
         *
         * @var Factory
         */
        protected $factory;

        public function __construct(Factory $factory) {
            $this->factory = $factory;
        }

        /**
         * Main executor for CLI process.
         */
        public function run() {
            error_reporting(-1);
            $errorHandler = $this->factory->getInstanceFor('ErrorHandler');
            $errorHandler->register();
            try {
                $options = $this->processOptions();

                if ($options->getValue('version') === TRUE) {
                    $this->showVersion();
                    exit(0);
                }

                if ($options->getValue('skel') === TRUE) {
                    $this->showSkeletonConfig($options->getValue('strip'));
                    exit(0);
                }

                if ($options->getValue('help') === TRUE) {
                    $this->showVersion();
                    $this->showUsage();
                    exit(0);
                }

                $errorHandler->setDebug($options->getValue('debug'));

                $cfgLoader = $this->factory->getInstanceFor('ConfigLoader');
                $cfgFile = $options->getValue('file');
                if ($cfgFile) {
                    $config = $cfgLoader->load($cfgFile);
                } else {
                    $config = $cfgLoader->autodetect();
                }

                if ($config->isSilentMode()) {
                    $this->factory->setLoggerType('silent');
                } else {
                    $this->showVersion();
                    $this->factory->setLoggerType('shell');
                }

                $logger = $this->factory->getInstanceFor('Logger');
                $logger->log("Using config file '". $config->getFilename(). "'");

                $app = $this->factory->getInstanceFor('Application');

                /** @var $bootstrap Bootstrap */
                $bootstrap = $app->runBootstrap($config->getBootstrapFiles());

                if ($options->getValue('engines')) {
                    $this->showVersion();
                    $this->showList('engines', $bootstrap->getEngines());
                    exit(0);
                }

                if ($options->getValue('backends')) {
                    $this->showVersion();
                    $this->showList('backends', $bootstrap->getBackends());
                    exit(0);
                }

                foreach($config->getAvailableProjects() as $project) {
                    $logger->log("Starting to process project '$project'");
                    $pcfg = $config->getProjectConfig($project);

                    if (!$options->getValue('generator')) {
                        $app->runCollector( $pcfg->getCollectorConfig() );
                    }

                    if (!$options->getValue('collector')) {
                        $app->runGenerator( $pcfg->getGeneratorConfig() );
                    }

                    $logger->log("Processing project '$project' completed.");

                }

                $logger->buildSummary();

            } catch (CLIOptionsException $e) {
                $this->showVersion();
                fwrite(STDERR, "\n".$e->getMessage()."\n\n");
                $this->showUsage();
                exit(3);
            } catch (ConfigLoaderException $e) {
                $this->showVersion();
                fwrite(STDERR, "\nAn error occured while trying to load the configuration file:\n\t" . $e->getMessage()."\n\nUsing --skel might get you started.\n\n");
                exit(3);
            } catch (ConfigException $e) {
                fwrite(STDERR, "\nYour configuration seems to be corrupted:\n\n\t" . $e->getMessage()."\n\nPlease verify your configuration xml file.\n\n");
                exit(3);
            } catch (ApplicationException $e) {
                fwrite(STDERR, "\nAn application error occured while processing:\n\n\t" . $e->getMessage()."\n\nPlease verify your configuration.\n\n");
                exit(1);
            } catch (\Exception $e) {
                if ($e instanceof fDOMException) {
                    $e->toggleFullMessage(TRUE);
                }
                $this->showVersion();
                $errorHandler->handleException($e);
            }
        }

        /**
         * Helper to output version information.
         */
        protected function showVersion() {
            static $shown = FALSE;
            if ($shown) {
                return;
            }
            $shown = TRUE;
            echo Version::getInfoString() . "\n\n";
        }

        protected function showSkeletonConfig($strip) {
            $config = file_get_contents(__DIR__ . '/config/skeleton.xml');
            if ($strip) {
                $dom = new fDOMDocument();
                $dom->loadXML($config);
                foreach($dom->query('//comment()') as $c) {
                    $c->parentNode->removeChild($c);
                }
                $dom->preserveWhiteSpace = FALSE;
                $dom->formatOutput = TRUE;
                $dom->loadXML($dom->saveXML());
                $config = $dom->saveXML();
            }
            echo $config;
        }

        protected function showList($title, Array $list) {
            echo "\nThe following $title are registered:\n\n";
            foreach($list as $name => $desc) {
                printf("   %s \t %s\n", $name, $desc);
            }
            echo "\n\n";
        }

        /**
         * Helper to register and process supported CLI options into an ezcConsoleInput
         *
         * @throws CLIOptionsException
         * @return CLIOptions
         */
        protected function processOptions() {
            $input = new \ezcConsoleInput();
            $versionOption = $input->registerOption( new \ezcConsoleOption( 'v', 'version' ) );
            $versionOption->shorthelp    = 'Prints the version and exits';
            $versionOption->isHelpOption = TRUE;

            $helpOption = $input->registerOption( new \ezcConsoleOption( 'h', 'help' ) );
            $helpOption->isHelpOption = TRUE;
            $helpOption->shorthelp    = 'Prints this usage information';

            $input->registerOption( new \ezcConsoleOption(
                'f', 'file', \ezcConsoleInput::TYPE_STRING, NULL, FALSE,
                'Configuration file to load'
            ));

            $c = $input->registerOption( new \ezcConsoleOption(
                    'c', 'collector', \ezcConsoleInput::TYPE_NONE, NULL, FALSE,
                    'Run collector process only'
            ));

            $g = $input->registerOption( new \ezcConsoleOption(
                    'g', 'generator', \ezcConsoleInput::TYPE_NONE, NULL, FALSE,
                    'Run generator process only'
            ));

            $g->addExclusion(new \ezcConsoleOptionRule($c));
            $c->addExclusion(new \ezcConsoleOptionRule($g));

            $input->registerOption( new \ezcConsoleOption(
                NULL, 'debug', \ezcConsoleInput::TYPE_NONE, NULL, FALSE,
                'For plugin developers only, enable php error reporting'
            ));
            $input->registerOption( new \ezcConsoleOption(
                NULL, 'engines', \ezcConsoleInput::TYPE_NONE, NULL, FALSE,
                'Show a list of available engines and exit'
            ));

            $input->registerOption( new \ezcConsoleOption(
                NULL, 'backends', \ezcConsoleInput::TYPE_NONE, NULL, FALSE,
                'Show a list of available backends and exit'
            ));

            $skel = $input->registerOption( new \ezcConsoleOption(
                    NULL, 'skel', \ezcConsoleInput::TYPE_NONE, NULL, FALSE,
                    'Show a skeleton config xml file and exit'
            ));

            $strip = $input->registerOption( new \ezcConsoleOption(
                    NULL, 'strip', \ezcConsoleInput::TYPE_NONE, NULL, FALSE,
                    'Strip xml config when showing'
            ));
            $strip->addDependency(new \ezcConsoleOptionRule($skel));

            try {
                $input->process();
                return new CLIOptions($input);
            } catch (\ezcConsoleException $e) {
                throw new CLIOptionsException($e->getMessage(), $e->getCode());
            }
        }

        /**
         * Helper to output usage information.
         */
        protected function showUsage() {
            print <<<EOF
Usage: phpdox [switches]

  -f, --file       Configuration file to use (defaults to ./phpdox.xml[.dist])

  -h, --help       Prints this usage information
  -v, --version    Prints the version and exits

      --debug      For plugin developers only, enable php error reporting

      --engines    Show a list of available output engines and exit
      --backends   Show a list of available backends and exit

      --skel       Show an annotated skeleton config xml file and exit
      --strip      Strip comments from skeleton config xml when showing


EOF;
        }

    }

}
