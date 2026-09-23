---
paths:
  - 'database/seeders/DemoEnvironment/**'
---

# Demo Environment

## Client demo = real /admin on its own DB, gated by DEMO_MODE
The client demo is NOT the /demo sandbox panel. It is the unchanged /admin panel booted with APP_ENV=demo, which loads .env.demo (fynn_on_demo DB, DEMO_MODE=true, own SESSION_COOKIE). DemoModeServiceProvider registers the persona picker, the topbar switcher and POST /demo-persona/{slug} only when config('demo.enabled'); personas live in config/demo.php. Rebuild with `php artisan demo-environment:refresh --env=demo --force`, which refuses to run unless both the environment is demo and DEMO_MODE is on. The seeders insert directly and stamp stage history, audits and settlements with explicit user ids, because the app's hooks use auth(). Any new migration must run on MySQL (identifier ≤64 chars), or the nightly demo rebuild breaks. When adding a module, extend a DemoEnvironment seeder so the demo shows it.
