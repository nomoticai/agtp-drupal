# AGTP for Drupal

A Drupal module that exposes your site to the Agent Transfer Protocol
(AGTP). Site builders write handler classes the same way they'd write
Drupal services; AGTP traffic routes through them via the gateway
protocol.

This module pairs with two other packages:

- **[agtp-php](https://github.com/nomoticai/agtp-php)** — the language
  library that defines `EndpointContext`, `EndpointResponse`,
  `EndpointError`, and the `#[AgtpEndpoint]` attribute. Handler classes
  use these directly.
- **[mod_php](https://github.com/nomoticai/agtp-php)** — the runtime
  that connects to `agtpd` over a gateway socket. The drush command in
  this module wraps it. Lives in the `agtp-php` repository alongside
  the SDK.

You do not run a separate `agtpd` daemon as part of Drupal — `agtpd`
is the AGTP server. You install it once on the host, and it listens on
TCP/4480 the same way Apache listens on 80. This module is the
Drupal-side worker that connects to it.

## Why AGTP instead of JSON:API or REST?

You can already expose Drupal content over HTTP via JSON:API. So what
does AGTP buy you?

**A warm Drupal process per request.** AGTP handlers run inside a
long-lived `drush agtp:serve` worker. Drupal's container is built
**once** at worker startup; every subsequent request reuses it. Cold
boot for a typical Drupal 10 site is 200-500 ms. Warm PHP-FPM behind
nginx is 50-150 ms per request. The AGTP worker is sub-millisecond per
request for the dispatch layer plus whatever the handler does — the
bootstrap tax is paid once at process start, not on every call. For
agent traffic — which is bursty and often hits the same endpoints
repeatedly — this is a measurable performance difference.

**Identity and scope at the protocol level.** `$ctx->agentId` is a
cryptographically verified agent identifier by the time it reaches your
handler. `$ctx->authorityScope` is the scope claim the daemon already
checked against the endpoint's declared `requiredScopes`. You don't
have to rebuild this with JWTs and middleware.

**Attribution at the protocol level.** Every method invocation
produces a daemon-signed Attribution-Record. Audit logging is the
transport's job, not yours.

**HTTP keeps working.** AGTP runs on its own port via `agtpd`. Drupal
answers HTTP on 80/443 as before. The two protocols coexist on the
same host without interfering.

## Requirements

- Drupal 10.2+ or Drupal 11
- PHP 8.1+
- `agtpd` running locally or on the same host
- Drush 12+

## Deployment compatibility

| Environment | Long-lived workers? | Status |
|---|---|---|
| Self-hosted (VPS, bare metal, Kubernetes, Docker Compose) | Yes — systemd, Supervisor, k8s `Deployment` | **Supported** |
| Platform.sh | Yes — native worker containers | Recipe pending; should work |
| DDEV / Lando (local dev) | Yes — custom service overlays | Recipe pending; should work |
| Acquia Cloud | No native long-running workers | Not supported. Run `agtpd` + worker on a sibling instance. |
| Pantheon | Quicksilver is event-triggered only | Not supported. Same answer as Acquia. |

AGTP for Drupal is **self-hosted-first**. Sites on PaaS platforms
without long-running worker support need to run `agtpd` and the
`drush agtp:serve` worker on a sibling host they control, then point
the gateway socket at it via TCP loopback (`127.0.0.1:4481`) or over
the network.

## Install

```bash
composer require agtp/agtp-drupal
drush en agtp_drupal
```

## Writing a handler

Three files: a handler class, a service registration tagging it
`agtp.endpoint`, and (for a fresh module) an info file. Drupal's DI
container collects everything tagged `agtp.endpoint` and feeds it to
the worker at boot.

### 1. The handler class

```php
// web/modules/custom/example_agtp/src/Agtp/RoomHandlers.php
namespace Drupal\example_agtp\Agtp;

use Agtp\AgtpEndpoint;
use Agtp\EndpointContext;
use Agtp\EndpointError;
use Agtp\EndpointResponse;
use Drupal\Core\Entity\EntityTypeManagerInterface;

final class RoomHandlers
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    #[AgtpEndpoint(
        method: 'BOOK',
        path: '/room',
        errors: ['room_unavailable'],
        requiredScopes: ['booking:write'],
    )]
    public function book(EndpointContext $ctx): EndpointResponse|EndpointError
    {
        $nodes = $this->entityTypeManager
            ->getStorage('node')
            ->loadByProperties([
                'type' => 'room',
                'field_room_type' => $ctx->input['room_type'] ?? 'double',
            ]);

        if ($nodes === []) {
            return new EndpointError(
                code: 'room_unavailable',
                message: 'No rooms of that type are bookable.',
                details: ['room_type' => $ctx->input['room_type'] ?? null],
            );
        }

        $node = reset($nodes);
        return new EndpointResponse(body: [
            'reservation_id' => 'res-' . $node->id() . '-' . $ctx->agentId,
            'room_id' => $node->id(),
        ]);
    }
}
```

### 2. The service registration

Tag the handler service with `agtp.endpoint`. The collector picks it
up at boot.

```yaml
# web/modules/custom/example_agtp/example_agtp.services.yml
services:
  example_agtp.room_handlers:
    class: Drupal\example_agtp\Agtp\RoomHandlers
    arguments:
      - '@entity_type.manager'
    tags:
      - { name: agtp.endpoint }
```

### 3. The module info file

```yaml
# web/modules/custom/example_agtp/example_agtp.info.yml
name: Example AGTP handlers
type: module
package: AGTP
core_version_requirement: ^10.2 || ^11
dependencies:
  - agtp:agtp_drupal
```

Enable: `drush en example_agtp`.

## Generate the daemon manifest

After authoring handlers, project the `#[AgtpEndpoint]` attributes
into daemon-side endpoint TOML files. This closes the silent-drift
gap between the handler attribute and what `agtpd` is configured to
serve.

```bash
# Write one TOML per handler into the agtpd endpoints directory
drush agtp:export-manifest --output=/etc/agtpd/endpoints

# Or preview to stdout
drush agtp:export-manifest --dry-run
```

The attribute is the source of truth. Re-run the command after every
handler change. A typical deploy script runs `drush agtp:export-manifest`
right after `drush updb` and before `systemctl reload agtp-drupal`.

## Running the worker

```bash
drush agtp:serve --gateway-socket=/var/run/agtpd/gateway.sock
```

What happens:

1. Drush bootstraps Drupal so the service container is built and your
   handler service is available.
2. `AgtpHandlerCollector` walks every service tagged `agtp.endpoint`
   and calls `HandlerRegistry::registerInstance()` on each, picking up
   every method decorated with `#[AgtpEndpoint]`.
3. A `GatewayClient` connects to the daemon, performs the handshake,
   receives the daemon's endpoint registration, and dispatches
   requests by looking up the registered handler.
4. The process serves until the daemon sends `goodbye` or the socket
   closes.

### Production deployment

Run the worker under a process supervisor:

```ini
# /etc/systemd/system/agtp-drupal.service
[Unit]
Description=AGTP for Drupal worker
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/example.com
ExecStart=/usr/bin/drush --root=/var/www/example.com/web agtp:serve --gateway-socket=/var/run/agtpd/gateway.sock
Restart=on-failure
RestartSec=5s

[Install]
WantedBy=multi-user.target
```

For higher request concurrency, run multiple worker units — `agtpd`
accepts multiple module connections and routes among them.

## Admin settings

After enabling, the settings page lives at
`/admin/config/services/agtp`. It shows the configured gateway socket,
the module identifier reported to `agtpd`, and a read-only listing of
every endpoint the service container has collected — useful as a
sanity check after deploy.

The page does not author handlers; handlers are PHP code in your
custom modules. The page reflects what's in code.

## Testing handlers

Use [`Agtp\Testing`](https://github.com/nomoticai/agtp-php#testing-handlers)
to exercise handler methods directly. Build a synthetic
`EndpointContext`, call the method, assert on the result. No daemon,
no gateway socket, no AGTP traffic.

```php
public function testBookSuccess(): void
{
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    // ... stub entityTypeManager as needed ...
    $handler = new RoomHandlers($entityTypeManager);

    $ctx = Testing::makeContext(input: ['room_type' => 'double']);
    $response = Testing::assertOk($handler->book($ctx));
    $this->assertArrayHasKey('reservation_id', $response->body);
}
```

## What this module does not do

- **Does not serve AGTP traffic over Drupal's HTTP request pipeline.**
  AGTP runs on its own port (4480) via `agtpd`. Drupal answers HTTP on
  its usual port. The two protocols coexist on the same host.
- **Does not expose handler endpoints to anonymous traffic.**
  Authentication happens at the `agtpd` layer (Agent-ID resolution
  and, when Agent-Cert lands, mTLS). Inside the handler,
  `$ctx->agentId` is the verified agent identity; trust it.
- **Does not provide a UI to author handlers.** Handlers are PHP code
  in your modules. The admin page surfaces what code declared.

## Related

- [`agtp-php`](https://github.com/nomoticai/agtp-php) — the SDK and the
  `mod_php` runtime
- [`agtp-symfony`](https://github.com/nomoticai/agtp-symfony) — the
  Symfony equivalent of this module
