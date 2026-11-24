# Putting LearnLoop online (Railway)

This gives LearnLoop a public `https://…up.railway.app` address that works on any phone, tablet or computer,
with the demo accounts **and** real Ashesi Canvas on the same site. It takes about 15 minutes.

**Cost:** new Railway accounts get a 30-day trial with $5 of credit and no credit card. After that the free plan
gives $1 a month and the site sleeps when idle (the first visit after a quiet period takes a few seconds).
The Hobby plan ($5/month) keeps it always on. Check railway.com/pricing for current terms.

---

## 1. Who should do this

Railway deploys straight from GitHub and redeploys automatically every time `main` changes. It needs access to
the repository **BentSul/learnloop**:

- **Easiest:** the repository owner (BentSul) signs in to Railway with GitHub and does steps 2–6, or
- **Anyone else:** fork the repository to your own GitHub account and deploy the fork. After each update to
  `main`, open your fork on GitHub and click **Sync fork** to redeploy.

## 2. Create the project

1. Go to **railway.com** → **Login** → sign in with **GitHub**.
2. **New Project** → **Deploy from GitHub repo** → choose **learnloop** (allow Railway access to it if asked).
3. The first build will fail or misbehave until the settings below are done. That is expected.

## 3. Add the database

In the project: **+ Create** (or **+ New**) → **Database** → **MySQL**. Wait until it shows as deployed.

## 4. Point Railway at the Laravel app

Click the **learnloop** service (not MySQL) → **Settings**:

- **Root Directory:** `/backend`
- **Networking → Generate Domain** (accept the port Railway suggests). Copy the address, e.g. `https://learnloop-production.up.railway.app`.
- **Healthcheck Path** (optional): `/up`

## 5. Variables

Still on the learnloop service → **Variables** → **Raw Editor**, paste this and fill in the `<…>` parts:

```
APP_NAME=LearnLoop
APP_ENV=production
APP_DEBUG=false
APP_KEY=<see below>
APP_URL=<the https address from step 4>

DB_CONNECTION=mysql
DB_URL=${{MySQL.MYSQL_URL}}

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
LOG_CHANNEL=stderr
RAILPACK_PHP_EXTENSIONS=intl,pdo_mysql

CANVAS_ADAPTER=hybrid
CANVAS_BASE_URL=https://ashesi.instructure.com

LEARNLOOP_SEED_DEMO=true
DEMO_PASSWORD=<a password only your team knows>
LEARNLOOP_CANVAS_COURSES=
LEARNLOOP_ADMIN_EMAILS=
```

- **APP_KEY:** on your laptop, in `backend`, run `php artisan key:generate --show` and paste the whole output
  (starts with `base64:`). Set it once and never change it: it encrypts stored Canvas tokens.
- **`${{MySQL.MYSQL_URL}}`** is typed exactly like that; Railway fills in the database address. If your database
  service has a different name than `MySQL`, use that name.
- **DEMO_PASSWORD:** the demo accounts (including an admin) use this password on the live site. Do **not** use
  `password123` on a public site; anyone could log in as admin.
- **CANVAS_ADAPTER=hybrid:** the demo accounts keep the sample data; everyone who connects a Canvas token gets
  their real Canvas data.

Click **Deploy** (or **Apply changes**). In **Deployments → View logs** you should see migrations run, then
`Starting LearnLoop ...`. Open the address from step 4.

## 6. Check it

- Log in as `jamie.lee@student.learnloop.test` with your DEMO_PASSWORD. You should see the demo courses.
- Open the site on your phone.
- Register a new account with your own email, open **Canvas**, paste your Canvas token. It should say Connected.

## 7. Add a real Canvas course and lecturers

Railway reads these two variables every time the site starts:

| Variable | Example | Effect |
|---|---|---|
| `LEARNLOOP_CANVAS_COURSES` | `12345\|Web Technologies\|CS341,67890\|Data Structures\|CS213` | Links Canvas courses (number from the course address `…/courses/12345`, then name, then code). Already linked courses are skipped. |
| `LEARNLOOP_ADMIN_EMAILS` | `you@ashesi.edu.gh,teammate@ashesi.edu.gh` | Makes these **already registered** accounts admins (they can add topics and map Canvas items in every course). |

After changing a variable, Railway redeploys; wait for `Starting LearnLoop ...`, then log out and in again.

Real lecturers don't need to be admins: a lecturer who registers and connects their own Canvas token is the
lecturer of the courses they teach in Canvas.

## 8. Sign in with Canvas (needs Ashesi IT)

Canvas's API policy says apps used by more than their developer must use OAuth instead of asking people to
paste access tokens. Personal tokens are fine for testing your own account. For classmates and real students,
ask the Canvas admins (Ashesi IT) for a **developer key** (an "API key", not LTI) with:

- **Redirect URI:** `<APP_URL>/api/canvas/oauth/callback` (e.g. `https://learnloop-production.up.railway.app/api/canvas/oauth/callback`)
- **Name:** LearnLoop; purpose: student practice and progress tracking from course grades
- Scopes: none (or read-only scopes for courses, enrollments, assignments, submissions and the user's profile)

They give you a **Client ID** (a number) and a **Client Secret**. Add on Railway:

```
CANVAS_OAUTH_CLIENT_ID=<client id>
CANVAS_OAUTH_CLIENT_SECRET=<client secret>
```

After the redeploy, Login and Register show **Sign in with Canvas** and the Canvas page shows **Connect with
Canvas**. Keep the secret out of chats and the repository.

## 9. Adding courses

Lecturers (and admins) who connected Canvas can add the courses they teach from **Lecturer Tools → Add a Canvas
course**; `LEARNLOOP_CANVAS_COURSES` is only needed for courses nobody has added that way.

## 10. Updating the live site

Every push to `main` redeploys automatically (or **Sync fork** if you deployed a fork). Your data stays:
migrations only add to the database, and the demo data is loaded only once.

## Troubleshooting

| Problem | Fix |
|---|---|
| Build fails with "could not find composer.json" | Root Directory must be `/backend` |
| `MissingAppKeyException` / 500 on every page | Set `APP_KEY`, redeploy |
| `SQLSTATE[HY000] [2002]` in logs | `DB_URL` is wrong: it must be `${{MySQL.MYSQL_URL}}` with your database service's name |
| Page loads without styling, or "Mixed content" warnings | `APP_URL` must be the exact `https://…` address, then redeploy |
| "Canvas tokens are not enabled" | `CANVAS_ADAPTER=hybrid` (or `token`) |
| Demo logins don't work | `LEARNLOOP_SEED_DEMO=true` loads them only into an empty database; use your `DEMO_PASSWORD` |
| Site slow on first visit | Free plan sleeps when idle; it wakes on the first request |

## Privacy

The live site is on the public internet. Real Canvas tokens are stored encrypted, but only connect accounts
whose owners agreed, and use real student grades only with your lecturer's approval.
