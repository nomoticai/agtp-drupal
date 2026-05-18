<?php

declare(strict_types=1);

namespace Drupal\agtp_drupal\Form;

use Agtp\HandlerRegistry;
use Drupal\agtp_drupal\Registry\AgtpHandlerCollector;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings form for AGTP for Drupal.
 *
 * The form exposes the gateway-socket path operators most commonly
 * need to configure, plus a read-only view of the handlers the
 * service container has registered. Most operators will find this
 * useful as a sanity check after deploy: "did my handler get
 * collected by the tagged-iterator pass?"
 *
 * The actual ``drush agtp:serve`` invocation does not read from this
 * config — passing ``--gateway-socket`` on the command line takes
 * precedence — but a future systemd template unit could read the
 * configured value to avoid hard-coding the path in unit files.
 */
final class AgtpSettingsForm extends ConfigFormBase
{
    public function __construct(
        \Drupal\Core\Config\ConfigFactoryInterface $config_factory,
        \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager,
        private readonly AgtpHandlerCollector $collector,
    ) {
        parent::__construct($config_factory, $typed_config_manager);
    }

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('config.factory'),
            $container->get('config.typed'),
            $container->get('agtp_drupal.handler_collector'),
        );
    }

    public function getFormId(): string
    {
        return 'agtp_drupal_settings';
    }

    protected function getEditableConfigNames(): array
    {
        return ['agtp_drupal.settings'];
    }

    public function buildForm(array $form, FormStateInterface $form_state): array
    {
        $config = $this->config('agtp_drupal.settings');

        $form['gateway_socket'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Gateway socket'),
            '#default_value' => $config->get('gateway_socket') ?? '/var/run/agtpd/gateway.sock',
            '#description' => $this->t(
                'Path to the agtpd gateway socket, or @example for TCP loopback. ' .
                'Used as the default when @cmd is invoked without an explicit ' .
                '<code>--gateway-socket</code> option.',
                [
                    '@example' => '127.0.0.1:4481',
                    '@cmd' => 'drush agtp:serve',
                ],
            ),
            '#required' => true,
        ];

        $form['module_id'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Module identifier'),
            '#default_value' => $config->get('module_id') ?? 'agtp_drupal',
            '#description' => $this->t(
                'Identifier reported in the gateway hello frame. ' .
                'Visible in agtpd logs to distinguish multiple connected modules.',
            ),
            '#required' => true,
        ];

        $form['endpoints_panel'] = [
            '#type' => 'fieldset',
            '#title' => $this->t('Registered endpoints'),
            '#description' => $this->t(
                'Endpoints the service container has collected via ' .
                'the @tag tag. This is read-only; handlers are defined in ' .
                'code via @attr attributes.',
                [
                    '@tag' => 'agtp.endpoint',
                    '@attr' => '#[AgtpEndpoint]',
                ],
            ),
        ];
        $form['endpoints_panel']['table'] = $this->buildEndpointsTable();

        return parent::buildForm($form, $form_state);
    }

    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
        $this->config('agtp_drupal.settings')
            ->set('gateway_socket', (string) $form_state->getValue('gateway_socket'))
            ->set('module_id', (string) $form_state->getValue('module_id'))
            ->save();
        parent::submitForm($form, $form_state);
    }

    /**
     * Build a render array showing each registered endpoint.
     *
     * @return array<string, mixed>
     */
    private function buildEndpointsTable(): array
    {
        HandlerRegistry::resetDefault();
        $registry = HandlerRegistry::default();
        $rows = [];
        try {
            foreach ($this->collector->collect($registry) as $entry) {
                $rows[] = [
                    $entry->method,
                    $entry->path,
                    implode(', ', $entry->requiredScopes) ?: '—',
                    implode(', ', $entry->errors) ?: '—',
                ];
            }
        } catch (\Throwable $e) {
            return [
                '#markup' => '<p>' . $this->t(
                    'Could not enumerate handlers: @msg',
                    ['@msg' => $e->getMessage()],
                ) . '</p>',
            ];
        }

        if (empty($rows)) {
            return [
                '#markup' => '<p>' . $this->t(
                    'No services tagged <code>agtp.endpoint</code> are registered.'
                ) . '</p>',
            ];
        }

        return [
            '#type' => 'table',
            '#header' => [
                $this->t('Method'),
                $this->t('Path'),
                $this->t('Required scopes'),
                $this->t('Declared errors'),
            ],
            '#rows' => $rows,
        ];
    }
}
