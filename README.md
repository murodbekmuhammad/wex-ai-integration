# Wex

A simple Gmail viewer built with Laravel + Vue. Sign in with your Google account to see your real Gmail messages, sort them by any column, and filter them by receiver.

## 1. Create Google OAuth credentials (one time)

1. In [Google Cloud Console](https://console.cloud.google.com/), create a project (or pick an existing one).
2. **APIs & Services → Library**: enable the **Gmail API**.
3. **APIs & Services → OAuth consent screen**: choose **External**, fill in the app name and your email. Under **Test users**, add every Gmail address that should be able to sign in.
4. **APIs & Services → Credentials → Create credentials → OAuth client ID**:
   - Application type: **Web application**
   - Authorized redirect URI: `http://localhost:8000/auth/google/callback`
5. Copy the client ID and secret into `.env`:

```
GOOGLE_CLIENT_ID=xxxxxxxx.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=xxxxxxxx
```

While the app is in "Testing" mode, Google shows a "Google hasn't verified this app" warning. Click **Continue**. Only the test users you added can sign in.

## 2. Add a Claude API key (for "Ask Claude")

Create a key at [console.anthropic.com](https://console.anthropic.com/settings/keys) (API usage is billed to that account) and add it to `.env`:

```
ANTHROPIC_API_KEY=sk-ant-...
```

## 3. Run it

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate   # first time only
php artisan migrate
composer run dev
```

Open **http://localhost:8000**. Use `localhost`, not `127.0.0.1`, because the Google redirect URI must match exactly.

The dev server runs 4 PHP workers so the inbox stays responsive while Claude is answering. To make that work, it doesn't reload on its own, so **restart `composer run dev` after editing `.env`**.

## How it works

- **Sign in:** `AuthController` sends you to Google, asking for read-only Gmail access (`gmail.readonly`). The callback saves your Google tokens (encrypted) and logs you in. `App\Services\GoogleAuth` refreshes the access token when it expires.
- **Sync:** when the inbox opens, or when you click **Refresh**, `App\Services\GmailService` fetches the headers of your newest `GMAIL_SYNC_LIMIT` messages (default 100) and stores them in the `emails` table. Messages already stored are skipped, so later syncs are quick.
- **Sort and filter:** these run as database queries over the synced messages. The receiver filter matches any address in To or Cc.
- **Reading a message:** clicking a row fetches the full body from Gmail. HTML emails are shown in a sandboxed iframe, so scripts in them can't run.
- **Ask Claude:** `AssistantController` sends your question plus your synced emails to Claude Opus 5 (`App\Services\EmailAssistant`). It sends the sender, receivers, date, subject and snippet of up to 300 emails, limited to the current receiver filter. The answer streams back as Claude writes it. The email list is prompt-cached, so follow-up questions cost less. Email text is sent to Anthropic's API.

## Tests

```bash
php artisan test
```

Google and Gmail are faked in the tests, so no credentials are needed.
