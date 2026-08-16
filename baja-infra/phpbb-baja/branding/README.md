# Baja forum branding

Drop `site_logo.svg` in this directory to set the logo for the Baja forum
(`forum.bajasaebrasil.com.br` in prod, `forum.baja.local` in dev). That
filename is the whole contract — nothing else here is read at runtime.

## How it reaches the browser

1. `../Dockerfile` copies this directory to `/usr/local/share/baja/branding/`
   in the image — deliberately *outside* `/var/www/html`.
2. `../entrypoint.sh` (step 2) copies `site_logo.svg` into
   `/var/www/html/styles/prosilver/theme/images/site_logo.svg` on every
   container boot.
3. Stock prosilver already resolves `.site_logo` to
   `./images/site_logo.svg` in `colours.css`, so reusing that exact
   filename means no CSS, template, or child-style override is needed.

Step 1's "outside the volume" detail is the part that matters. `/var/www/html`
is the `phpbb_baja_html` named volume, and Docker seeds a named volume from
the image *only when the volume is first created*. A logo copied straight
into the theme at build time would therefore be invisible on every existing
deployment until someone ran `down -v` — the same trap that bites phpBB
extensions (see the FAQ in [`docs/dev-workflow.md`](../../../docs/dev-workflow.md)).
Installing at boot from a path outside the volume avoids it:

```
cd baja-infra
docker compose build phpbb-baja
docker compose up -d phpbb-baja
docker compose restart nginx
```

The `restart nginx` is required, not tidiness. nginx resolves
`fastcgi_pass phpbb-baja:9000` once at config load and caches the IP; the
`up -d` above *recreates* the container, which gives it a new one. Skip the
restart and `forum.baja.local` 502s while `forum.formula.local` starts
serving the Baja board, because the freed IP gets reused by the other phpBB
container. The logo will look correct throughout — static files come off
the volume — so this is easy to misdiagnose as an app bug. See the FAQ in
[`docs/dev-workflow.md`](../../../docs/dev-workflow.md).

If no `site_logo.svg` is present, the entrypoint logs that and leaves
phpBB's stock logo in place. The build and boot still succeed.

## Keep it SVG

prosilver paints the logo as a CSS background on a fixed 149×52 box
(`.site_logo` in `common.css`) with no `background-size`. An SVG scales to
that box; a raster at any other dimensions gets cropped instead. If a raster
logo ever becomes unavoidable, it needs a companion CSS override, which this
mechanism deliberately does not ship.

## Browser caching when you replace it

`nginx/conf.d/phpbb-baja.conf` serves `.svg` with `expires 30d`. Because the
new logo reuses the `site_logo.svg` filename, anyone who already loaded the
previous one keeps it until their cache expires. Hard-refresh when verifying,
and don't read a stale logo as a failed deploy.

## Pulling the current production logo

The old production server is the source of truth for the real artwork. Over
Tailscale, using the values already in `baja-infra/scripts/migration.env`
(see [`../../scripts/migrate.sh`](../../scripts/migrate.sh)):

```
scp -i "$PROD_SSH_KEY" \
  "$PROD_SSH_USER@$PROD_TAILSCALE_IP:$PROD_BAJA_FORUM_PATH/styles/prosilver/theme/images/site_logo.svg" \
  baja-infra/phpbb-baja/branding/site_logo.svg
```

Sanity-check what you pulled: phpBB's stock 3.3.15 logo is 34132 bytes with
sha256 `6e4580dcd712128ac8fe7f1409c51885d33861ab7752c8f7f7ea5d43dd2dff77`. If
you get that file back, prod was never branded either and you need the
artwork from the design side instead.
