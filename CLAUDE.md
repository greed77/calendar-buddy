# Meeting Poll

An internal Doodle/Rallly-style app. Its only job is polling for meeting times.

- **Internal users** sign in with Google Workspace (`example.com` only) and create polls.
- **External invitees** never log in. They answer through emailed or shared links.
- **Google Calendar:** the creator's busy times are shaded on the slot picker. When a time is finalized, the app creates the Calendar event and Google emails the invites to everyone, outside people included.
- **Out of scope** unless asked: booking pages, round-robin scheduling, checking invitees' free/busy, and auto-answering for invitees.

## Stack

- Laravel 13, Livewire 4 (class-based components in `app/Livewire`, views in `resources/views/livewire`), Tailwind 4 via Vite.
- MySQL 8.4. Everything runs in Docker, so the only thing the host needs is Docker. **Don't install PHP, Composer or MySQL on the host.** Node runs in a container too, so the host's Node version (if any) doesn't matter.
- Image: `serversideup/php:8.4-fpm-nginx`, which listens on 8080 inside the container.

## Running it

```sh
docker compose up -d                          # app :8080, vite :5173, mailpit :8025, mysql :3306, queue worker
docker compose exec app php artisan test      # tests (in-memory SQLite)
docker compose exec app php artisan <cmd>     # any artisan command
docker compose restart worker                 # after changing anything a queued job runs
docker compose exec app composer require <pkg>   # add a package (must run in the container, see below)
```

- **In dev, `vendor/` lives in a Docker volume, not in the project folder.** With Docker Desktop, especially on Windows, reading files from the mounted project folder is slow. Loading the framework's ~1,000 files from there made every request take about 2 seconds; from the volume it takes about 0.1 seconds. That means Composer has to run inside the `app` container so it writes to the volume. A `vendor/` folder on the host, if there is one, is only for your editor's autocompletion and may be out of date.
- If the app crash-loops with `Class "...ServiceProvider" not found`, `vendor/` and `bootstrap/cache/packages.php` are out of sync. Delete `bootstrap/cache/packages.php` and `services.php`, then run `docker compose run --rm --no-deps --user root -e AUTORUN_ENABLED=false app composer install`.

- `compose.yaml` is the production-style stack. `compose.override.yaml` (merged automatically) adds the dev bind mount, Vite, Mailpit, and turns off caching.
- Migrations run automatically when the app container starts.
- All outgoing mail in dev lands in Mailpit at http://localhost:8025.

## Layout

- `app/Services/GoogleCalendar.php`: the only code that talks to Google Calendar. It refreshes tokens, lists busy blocks, and creates events. It uses Laravel's `Http` client. **Don't add `google/apiclient`**: it's huge (thousands of files), and installing it can time out on a slow Docker Desktop mount.
- `app/Http/Controllers/Auth/GoogleController.php`: Socialite sign-in, the domain check, and storing tokens (encrypted casts on `User`).
- `config/meetingpoll.php`: allowed domain, dev allowlist, grid hours, meeting lengths.
- Livewire pages:
  - `CreatePoll` (`/polls/create`): week grid with busy times shaded.
  - `ManagePoll` (`/polls/{poll}`, owner only): share link, email invites, results grid, and "Pick", which finalizes the poll and creates the Google event with `sendUpdates=all`.
  - `VotePoll` (`/p/{poll}/{token?}`, public): name/email and yes / if-need-be / no answers. Unanswered times are saved as "no".
- `app/Mail/PollInvitation.php`: queued markdown mail with the participant's personal link. Replies go to the organizer.
- Rules for voting:
  - A walk-up voter's token is kept in the session. An invitee's email is locked.
  - Voters only see tallies, not other people's names.
  - Finalizing uses a cache lock so a double-click can't create two Calendar events.
- Data model:
  - `polls`: routed by a `public_id` ULID and never by the integer id.
  - `poll_slots`: stored in UTC.
  - `participants`: both invited and walk-up people. `token` is their no-login edit key.
  - `votes`: `yes`, `maybe` or `no`.
- Times are always stored in UTC and shown in the viewer's timezone. `polls.timezone` records the creator's zone, which is used for the Calendar event.

## Auth rules

- Sign-in requires `hd == ALLOWED_GOOGLE_DOMAIN` **and** a matching, verified email. The `hd` request parameter only filters Google's account picker, so the callback enforces the rule.
- `DEV_ALLOWED_GOOGLE_EMAILS` (comma-separated) lets specific outside accounts sign in, such as a personal Gmail for testing. It only applies when `APP_ENV=local`, and tests cover that.
- Google issues a refresh token only from the consent screen. `/auth/google?consent=1` forces consent, and the callback redirects there when no refresh token is stored. A user can decline the calendar scope and still sign in; the UI then shows a "Connect Google Calendar" prompt.

## Gotchas (each one cost a debugging cycle)

- **Don't use `$slots` anywhere in a Livewire view**, including as a property or a `@foreach` variable. Livewire 4 reserves it. That's why the create-poll property is `$options` and the voting view loops over `$daySlots`.
- **Laravel 13's cache won't unserialize objects** (`serializable_classes => false`). Cache plain arrays or strings, such as ISO dates, and rebuild Carbon objects after reading.
- **Tests and the database:**
  - Docker env vars sit in `$_SERVER`, so `phpunit.xml` sets every value as both `<env force>` and `<server force>`.
  - A cached config (`php artisan optimize`) would still point tests at the dev MySQL, and `RefreshDatabase` would wipe it. `tests/TestCase.php` refuses to run unless the database is in-memory SQLite. **Keep that guard.**
  - Dev turns off all the image's `AUTORUN_LARAVEL_*_CACHE` steps for the same reason. If tests ever trip the guard, run `php artisan optimize:clear`.
- **Bind-mount permissions:** the app runs as `www-data` (UID 33), which may not own the files in the mounted project folder. If the container can't write to `storage/` or `bootstrap/cache`, fix it once with `docker compose run --rm --no-deps --user root -e AUTORUN_ENABLED=false app chmod -R a+rwX storage bootstrap/cache`.
- **Shell quoting:** when writing PHP files from a shell, heredocs containing apostrophes can break quoting. Use a file-writing or editing tool for PHP files instead.

## Conventions

- Match Laravel 13 skeleton style: attribute-based `#[Fillable]` / `#[Hidden]` on models, and `casts()` methods.
- Add a feature test for every behavior change. Fake Google with `Http::fake()` and `Socialite::fake('google', GoogleUser::fake([...]))`, and never call real Google APIs from tests.
- The repo is on GitHub (`greed77/meeting-poll`). The default branch is `main`.
