# Meeting Poll

A small, self-hosted app for finding a meeting time, in the style of Doodle or Rallly. It does one job: you propose some times, people say which ones work, and you pick one.

- **Your team signs in with Google Workspace.** Only accounts on your company's domain can create polls.
- **Anyone can answer.** People outside the company get an emailed link or a shared link and answer without creating an account.
- **It checks your Google Calendar.** While you choose times to offer, your existing meetings are shaded so you don't offer times you're already booked.
- **It books the meeting.** When you pick the winning time, the app puts the event on your Google Calendar, and Google emails a calendar invite to everyone who answered with an email address, including people outside the company.
- **There are no per-user fees.** Run it on your own server for as many people as you like.

## What you need

- **Docker** with Docker Compose (Docker Desktop on Windows or macOS). PHP, Composer, MySQL and Node all run inside containers, so nothing else is needed on your computer.
- **A Google Cloud project** for sign-in and Calendar access (setup is below).
- **An email service** for sending invitations in production, such as Google Workspace's SMTP relay, Postmark or Amazon SES. In development, all emails are caught locally instead.

## Run it on your computer

The commands below work in PowerShell, Command Prompt, macOS Terminal and Linux shells. The `run ... app` commands start a temporary copy of the app container to set things up, then remove it.

1. **Create your settings file** by copying `.env.example` to `.env`, then edit `.env`:
   - Set `DB_PASSWORD` and `DB_ROOT_PASSWORD` to any values you like. They're only used by the local database.
   - Set `ALLOWED_GOOGLE_DOMAIN` to your company's Google Workspace domain.
   - Fill in `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` once you've done the Google setup below.

2. **Build the app and install its dependencies:**

   ```sh
   docker compose build
   docker compose run --rm --no-deps --user root -e AUTORUN_ENABLED=false app composer install
   docker compose run --rm --no-deps --user root -e AUTORUN_ENABLED=false app php artisan key:generate
   docker compose run --rm --no-deps --user root -e AUTORUN_ENABLED=false app chmod -R a+rwX storage bootstrap/cache
   ```

3. **Start everything:**

   ```sh
   docker compose up -d
   ```

   The first start takes a minute while the database initializes and the app sets up its tables.

| What | Where |
|---|---|
| The app | http://localhost:8080 |
| Mailpit, where every email sent in development lands | http://localhost:8025 |
| MySQL, if you want to connect a database tool | `localhost:3306` |

To stop everything, run `docker compose down`. Your data is kept for next time.

## Set up Google sign-in and Calendar access

Do this once in the [Google Cloud Console](https://console.cloud.google.com/), signed in with the account that will own the app.

1. **Create a project** (or pick an existing one).
2. **Enable the Google Calendar API**: go to *APIs & Services → Library*, search for "Google Calendar API", and click *Enable*.
3. **Set up the OAuth consent screen** (*APIs & Services → OAuth consent screen*). Set the user type to **Internal**, so only people in your Workspace can sign in and Google doesn't need to review the app.
4. **Create the OAuth client**: go to *APIs & Services → Credentials → Create credentials → OAuth client ID*, choose **Web application**, and add these as authorized redirect URIs:
   - `http://localhost:8080/auth/google/callback` for development.
   - `https://your-server/auth/google/callback` for production, once you know the address.
5. **Copy the client ID and secret** into `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` in `.env`.
6. **Reload the settings** with `docker compose up -d --force-recreate app worker`. A plain `restart` doesn't pick up changes to `.env`.

When people first sign in, Google asks them to let the app see and edit their calendar events. If someone declines, they can still create polls; they just won't see their busy times, and the app shows a link to connect their calendar later.

### Trying it out with a personal Gmail account

If you aren't a Workspace admin, or want to try the app before involving IT, you can use a personal Gmail account for local testing.

- **Use a personal project:** create the Google Cloud project while signed in with your Gmail. Set the consent screen's user type to **External**, leave it in **Testing** mode, and add your Gmail address under *Test users*.
- **Allow the account to sign in:** in `.env`, set `DEV_ALLOWED_GOOGLE_EMAILS=you@gmail.com`. This setting only works when `APP_ENV=local`, so it can't open up a production server.
- **Expect a warning:** Google will say it hasn't verified the app. Click *Continue*.
- **Reconnect weekly:** in Testing mode, Google ends calendar access after 7 days, and the app asks you to reconnect.

## Using it

1. **Create a poll.** Sign in, click **New poll**, give it a title and meeting length, and click times on the week grid. Times you're already busy show in amber.
2. **Invite people.** On the poll's page, copy the share link, or enter email addresses so each person gets a personal invitation.
3. **Collect answers.** Each person answers *Yes*, *If need be* or *No* for each time, shown in their own timezone. They only see vote totals, not each other's names.
4. **Pick the time.** The results grid shows everyone's answers and highlights the most popular time. Click **Pick** under the time you want. The event goes on your Google Calendar, and Google emails the invites. To change or cancel the meeting later, use Google Calendar.

## Settings

All settings live in `.env`. These are the ones specific to this app:

| Setting | What it does |
|---|---|
| `ALLOWED_GOOGLE_DOMAIN` | The Google Workspace domain whose accounts can sign in and create polls. |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | The OAuth client from the Google setup above. |
| `GOOGLE_REDIRECT_URI` | Where Google sends people after sign-in. The default, built from `APP_URL`, is usually right. |
| `DEV_ALLOWED_GOOGLE_EMAILS` | Extra accounts allowed to sign in for local testing, comma-separated. Ignored unless `APP_ENV=local`. |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Who invitation emails come from. Replies go to the poll's organizer. |

The grid's hours (7am to 7pm by default) and the meeting lengths on offer are set in `config/meetingpoll.php`.

## Running the tests

```sh
docker compose exec app php artisan test
```

The tests use a temporary in-memory database and never touch your development data or call Google.

## Running it in production

`compose.yaml` is the production setup: the app (web server and PHP), a queue worker that sends emails, and MySQL. `compose.override.yaml` adds the development extras and is loaded automatically, so in production name the production file explicitly:

```sh
docker compose -f compose.yaml up -d --build
```

Before you do, set these in the server's `.env`:

- `APP_ENV=production` and `APP_DEBUG=false`.
- `APP_URL` set to the public `https://` address, and that address's `/auth/google/callback` added as a redirect URI on the OAuth client.
- A new `APP_KEY`. Generate one with `docker compose -f compose.yaml run --rm --no-deps -e AUTORUN_ENABLED=false app php artisan key:generate --show` and paste it in.
- Strong database passwords.
- Real mail settings (`MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`) and a `MAIL_FROM_ADDRESS` your mail service is allowed to send from.

The app listens on port 8080 over plain HTTP. Put it behind a reverse proxy that handles HTTPS, such as Caddy, nginx or Traefik. Google requires HTTPS for sign-in redirects anywhere other than `localhost`. Database tables are created and updated automatically each time the app starts.

## Working on the code

The app is built with Laravel, Livewire and Tailwind. [`CLAUDE.md`](CLAUDE.md) explains how the code is organized, the rules the app follows, and some pitfalls that are easy to hit, such as why `vendor/` lives in a Docker volume during development. It's written for AI coding assistants but is just as useful for people.

To add a PHP package, run Composer inside the container: `docker compose exec app composer require <package>`.

## License

GNU General Public License v3.0. See [LICENSE](LICENSE).

## Support

Want to say thanks? [Buy me a coffee](https://ko-fi.com/tehgreedo)!
