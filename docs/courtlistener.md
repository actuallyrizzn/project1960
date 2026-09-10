# CourtListener SDK wiring (CL-I1)

Project 1960 does **not** ship a custom CourtListener HTTP client.

## Dependency

- Package: `courtlistener/sdk-php` from https://github.com/actuallyrizzn/courtlistener-sdk (`php/`)
- Composer path repo: `../courtlistener-sdk/php` (symlink) — clone the SDK as a **sibling** of `project1960` on any host that runs `composer install`
- Factory: `Project1960\CourtListener\ClientFactory::make()` → `CourtListener\CourtListenerClient`

## Token

```bash
set -a && . ~/.ssh/courtlistener-api.pass && set +a
# COURTLISTENER_API_TOKEN=…
```

Never commit the token. Tasks Doc #1310.

## Smoke

```bash
set -a && . ~/.ssh/courtlistener-api.pass && set +a
php -r 'require "vendor/autoload.php";
$c = Project1960\CourtListener\ClientFactory::make();
$r = $c->dockets->listDockets(["page_size" => 1]);
echo "ok keys=" . implode(",", array_keys($r)) . "\n";'
```

## Multihost

On multihost SRC_DIR (`/root/repos/project1960.rizzn.net`):

1. `git clone https://github.com/actuallyrizzn/courtlistener-sdk.git /root/repos/courtlistener-sdk` (once)
2. Ensure path `../courtlistener-sdk/php` resolves from the project1960 clone
3. `composer install --no-dev`
4. Export `COURTLISTENER_API_TOKEN` for CLI workers (vault inject — not site env as SoT)
