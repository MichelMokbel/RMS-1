# GCP development deployment

This deployment runs the RMS, its queue worker and scheduler, a private MySQL database, and the customer orders website as containers. Nginx exposes only two development hostnames and keeps the RMS browser interface behind HTTP basic authentication. The public RMS API remains available for the customer website and SkipCash webhook.

The current development hostnames are:

* `https://rms-dev.34-156-128-236.sslip.io`
* `https://orders-dev.34-156-128-236.sslip.io`

They resolve through `sslip.io` to the VM address and do not alter either production Layla Kitchen domain.

Runtime files live under `/opt/layla-dev`. Keep `env/rms.env`, `env/mysql.env`, `env/orders.env`, and the plaintext RMS test credentials outside Git with mode `0600`. The Nginx password hash file at `/etc/nginx/layla-dev.htpasswd` must be owned by `root:www-data` with mode `0640` so the worker can read it. Set `PAYMENT_TERMS_URL` to the orders development hostname so the retained acceptance snapshot does not point testers at production.

The image build intentionally excludes environment files, local storage, customer submission data, test artifacts, the legacy public cache-clear script, and the unused legacy SMTP credential file.

The normal deployment sequence is:

1. Build both images for `linux/amd64` and load them on the VM.
2. Copy `compose.dev.yaml` and `compose.env` to `/opt/layla-dev`.
3. Start the database, run migrations and the synthetic development seed, then configure the SkipCash clearing account and payment source.
4. Start the RMS, queue, scheduler, and orders containers.
5. Install the Nginx development site, obtain one certificate for both hostnames, and enable the HTTPS configuration.
6. Run setup diagnostics, public endpoint checks, customer registration, quote, hosted checkout, return, webhook, accounting, and idempotency checks.

## Automatic development deployment

The `develop` branch in each repository deploys only its own development image. The RMS workflow restarts `rms`, `queue`, and `scheduler` after a successful migration. The customer website workflow restarts only `orders`. Both workflows keep the private database, environment files, and persistent volumes unchanged.

Each workflow authenticates to Google Cloud through GitHub OIDC and a repository and branch restricted Workload Identity provider. The temporary identity may deploy only to the development VM. No permanent Google Cloud or SSH credential is stored in GitHub.

The workflows stream their built image to a fixed root owned deployment script. The scripts serialize deployments, retain the prior image tag for a basic rollback, preserve runtime secrets outside Git, and require an HTTP health check before reporting success.

The workflows do not run for `main` and do not modify either production deployment.
