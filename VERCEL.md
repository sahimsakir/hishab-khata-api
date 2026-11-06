# Vercel deployment

This repository includes `Dockerfile.vercel` for Vercel's Container runtime. It uses Apache/PHP, copies the application into the image, and installs locked production dependencies. The existing `Dockerfile` remains for local Compose development.

## Required before the app can work

- A connected Vercel account and the Container framework/preset.
- A hosted PostgreSQL database reachable over TLS from the deployment.
- Stable application encryption and Passport signing keys stored as Vercel secrets.
- A frontend origin, API origin, and cookie-compatible domain or proxy arrangement.

Do not deploy the local Compose database on ephemeral function storage. Do not generate APP_KEY or Passport keys during a build/cold start. Do not put secrets in GitHub, the Docker image, or any `VITE_` variable.

## Production environment

Set these in the API project's production environment, with actual values:

- `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` (public HTTPS API origin).
- `APP_KEY` (generated once), `PASSPORT_PRIVATE_KEY`, `PASSPORT_PUBLIC_KEY` (a matching persistent PEM pair).
- `FRONTEND_URL` (exact frontend origin).
- `DB_CONNECTION=pgsql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_SSLMODE=require`.
- `SESSION_DRIVER=database`, `SESSION_ENCRYPT=true`, `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=lax`, `SESSION_DOMAIN=null`.
- `CACHE_STORE=database`, `LOG_CHANNEL=stderr`.
- `TRUSTED_PROXIES=*` only behind Vercel's trusted ingress.
- Google client ID, secret and callback URI when Google login is enabled.

Once connected to the actual database, initialize it once using a trusted local/admin process with these production variables loaded:

```bash
php artisan migrate --force
php artisan passport:client --personal --provider=users --name="HishabKhata Browser"
```

These are not cold-start commands. Create the personal client once per database; retain it across deployments.

## Authentication routing

Separate `*.vercel.app` projects are separate sites for browser cookies. Do not assume the existing SameSite=Lax authentication works between them. Use same-site custom domains (such as `app.example.com` and `api.example.com`) or a carefully configured same-origin `/api` proxy. For a proxy, route Google initiation and callback through that public origin too, and keep session/auth cookies host-only (`SESSION_DOMAIN=null`). The frontend's callback redirect and the Google Console redirect URI must match the public routes. Configure credentialed CORS and HTTPS accordingly.

## Verification

After deployment, check `/up`, registration, cookie-authenticated `/api/auth/me`, a ৳0.10 expense, CSRF rejection, logout/revocation, and Google callback. Container build and hosted PostgreSQL connectivity must be verified during deployment; they have not been exercised locally because Docker is unavailable here.

References: https://vercel.com/docs/functions/runtimes/container
