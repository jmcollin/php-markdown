# php-markdown Playground

A self-contained Docker environment for testing the php-markdown library without
installing PHP or configuring a local server.

## Requirements

- Docker >= 20.10
- Docker Compose >= 2.0 (the `docker compose` plugin, or standalone `docker-compose`)

## Start the playground

From the **project root** (where `docker-compose.yml` lives):

```bash
docker-compose up --build
```

The first build downloads the `php:8.3-apache` base image and installs the
required extensions (`ctype`, `intl`, `mbstring`). Subsequent starts reuse the
cached image and are much faster.

Once the healthcheck passes (up to 60 seconds), open your browser at:

```
http://localhost:8080
```

## Stop the playground

```bash
docker-compose down
```

## Playground Web UI

Opening `http://localhost:8080` in your browser loads the interactive Markdown playground.

The page is split into two equal panes side by side:

- **Left pane** — a plain-text editor (textarea) pre-filled with a short Markdown sample covering a heading, bold text, a bullet list, and a fenced code block. Edit freely.
- **Right pane** — a sandboxed iframe that shows the rendered HTML output. The iframe uses `sandbox="allow-same-origin"` only, so any `<script>` tags in the rendered HTML are inert.

Rendering is triggered automatically 400 ms after you stop typing (debounced). The request is a `POST /render` JSON call; the response `html` field is written directly into the iframe via `srcdoc`. If the server cannot be reached, or returns an error, a message bar appears above the panes with a plain-English description of the problem.

The page requires no JavaScript to be minimally usable: a "Render" button is exposed when JS is disabled, and the form submits to `/render` via standard POST.

## Notes

- `src/` is mounted **read-only** — changes inside the container cannot affect
  the library source on your host.
- The container runs as a non-root user (UID 1000).
- PHP version is pinned to `8.3`; no floating `latest` tag is used.

## Change the exposed port

The default host port is `8080`. To use a different port (e.g. `9090`), create a
`docker-compose.override.yml` file at the project root:

```yaml
services:
  playground:
    ports:
      - "9090:8080"
```

Then start normally with `docker-compose up --build`. The container port (`8080`)
is fixed; only the host-side port changes.

## Note

This playground is intended for **development and evaluation only**. It is not
hardened for production use: no TLS, no authentication, and no rate-limiting are
configured.
