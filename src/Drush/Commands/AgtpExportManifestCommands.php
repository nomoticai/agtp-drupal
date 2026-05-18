<?php

declare(strict_types=1);

namespace Drupal\agtp_drupal\Drush\Commands;

use Agtp\HandlerRegistry;
use Agtp\ManifestExporter;
use Drupal\agtp_drupal\Registry\AgtpHandlerCollector;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush command: ``drush agtp:export-manifest``.
 *
 * Generates daemon-side endpoint TOML files from the registered
 * ``#[AgtpEndpoint]`` attributes. Closes the silent-drift gap between
 * handler attributes and agtpd's endpoint manifest: the attribute is
 * the source of truth, this command projects it to the daemon's
 * filesystem format.
 *
 * Typical use, run from the Drupal site root:
 *
 *     drush agtp:export-manifest --output=/etc/agtpd/endpoints
 *
 * Or to a staging directory for review before deploy:
 *
 *     drush agtp:export-manifest --output=../agtpd-endpoints-staging
 *
 * Or print to stdout for a quick look:
 *
 *     drush agtp:export-manifest --dry-run
 */
final class AgtpExportManifestCommands extends DrushCommands
{
    public function __construct(
        private readonly AgtpHandlerCollector $collector,
    ) {
        parent::__construct();
    }

    #[CLI\Command(name: 'agtp:export-manifest', aliases: ['agtp-export-manifest'])]
    #[CLI\Option(
        name: 'output',
        description: 'Directory to write endpoint TOML files into. One file per handler.',
    )]
    #[CLI\Option(
        name: 'dry-run',
        description: 'Print TOML to stdout instead of writing files.',
    )]
    #[CLI\Usage(
        name: 'drush agtp:export-manifest --output=/etc/agtpd/endpoints',
        description: 'Write one TOML file per handler into the agtpd endpoints directory.',
    )]
    #[CLI\Usage(
        name: 'drush agtp:export-manifest --dry-run',
        description: 'Preview the TOML that would be generated.',
    )]
    public function exportManifest(array $options = [
        'output' => self::OPT,
        'dry-run' => false,
    ]): int
    {
        $registry = HandlerRegistry::default();
        $count = 0;
        foreach ($this->collector->collect($registry) as $_) {
            $count++;
        }
        if ($count === 0) {
            $this->logger()->warning('No services tagged "agtp.endpoint" were found.');
            return self::EXIT_SUCCESS;
        }

        $exporter = new ManifestExporter($registry);

        if (!empty($options['dry-run'])) {
            $this->output()->writeln($exporter->renderAll());
            return self::EXIT_SUCCESS;
        }

        $outDir = (string) ($options['output'] ?? '');
        if ($outDir === '') {
            $this->logger()->error(
                '--output is required (or pass --dry-run to preview).'
            );
            return self::EXIT_FAILURE;
        }

        $written = $exporter->writeToDirectory($outDir);
        $this->logger()->success(sprintf(
            'Wrote %d endpoint TOML file(s) to %s.',
            count($written),
            $outDir,
        ));
        foreach ($written as $path) {
            $this->output()->writeln('  - ' . $path);
        }
        return self::EXIT_SUCCESS;
    }
}
