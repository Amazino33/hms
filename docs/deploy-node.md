# Production server: Node.js for front-end builds

Since Phase 0F, `deploy.sh` rebuilds the front-end assets (`npm ci && npm run build`) after migrations, **if `npm` is installed on the server**. This matters because Tailwind only compiles the classes it finds in the Blade templates. A deploy that changes a template but doesn't rebuild ships screens with missing styling. That happened: the screens changed in Phases 0E–1B were unstyled until `public/build` was rebuilt in Phase 2.

## Recommended: install Node LTS on the server

Install the current **Node.js LTS** (it includes `npm`) for the account that runs `deploy.sh`. On shared hosting such as HostAfrica's cPanel, check whether **"Setup Node.js App"** or a Node selector is offered. If it isn't, ask HostAfrica support whether Node can be enabled for your account. Confirm it works over SSH:

```bash
node -v
npm -v
```

Once both print a version, the next `./deploy.sh` builds the assets itself.

## If Node can't be installed

`deploy.sh` keeps working. It prints a loud **"Front-end NOT rebuilt"** warning and uses the `public/build` committed in git. In that case, **every change that touches a Blade template, CSS or JS must be built on a dev machine and committed:**

```bash
npm run build
git add public/build
git commit -m "Rebuild front-end assets"
```

## Related: the patched Filament notification script

`composer install` republishes Filament's assets and overwrites the hand-patched `public/js/filament/notifications/notifications.js` (commit 475df40, "Fix silent hang"). `deploy.sh` now restores the patched file after every composer step, and resets it, along with `public/build`, before `git pull`, so the pull is never blocked by files the deploy itself rewrote.
