# LearnLoop: set up and test on your computer

This guide gets LearnLoop running on your own laptop (Windows), with demo data first and real Canvas after.
It takes about 20–30 minutes the first time. Run every command in **VS Code → Terminal → New Terminal** (PowerShell).

> **Two folders matter.** `git` commands run in the **project folder** (`learnloop`).
> `php artisan`, `composer` and `npm` commands run in **`learnloop\backend`**.
> If you see `Could not open input file: artisan`, you are in the wrong folder: `cd backend`.

---

## 1. Install the tools (once)

| Tool | Version | Check with |
|---|---|---|
| PHP | 8.2 or newer | `php -v` |
| Composer | any recent | `composer -V` |
| Node.js | 22 LTS (20.19+ also works) | `node -v` |
| MariaDB | 10.6+ (we use 12.2) | `mariadb --version` |
| Git | any | `git --version` |

### PHP settings (`php.ini`)

Run `php --ini` and open the file shown after **Loaded Configuration File**. Make sure these lines exist
**without** a `;` at the start:

```
extension=curl
extension=fileinfo
extension=intl
extension=mbstring
extension=openssl
extension=pdo_mysql
extension=zip
```

**Certificates (needed for real Canvas):** PHP on Windows does not trust any https site until you give it a
certificate list. Without this, connecting Canvas fails with "Canvas could not be reached".

```
New-Item -ItemType Directory -Force C:\php-certs
Invoke-WebRequest https://curl.se/ca/cacert.pem -OutFile C:\php-certs\cacert.pem
```

Then in `php.ini` set (remove the `;` if present):

```
curl.cainfo = "C:\php-certs\cacert.pem"
openssl.cafile = "C:\php-certs\cacert.pem"
```

Close and reopen the terminal after editing `php.ini`.

---

## 2. Get the code

```
cd C:\Users\<you>\Documents
git clone https://github.com/BentSul/learnloop.git
cd learnloop
git checkout main
```

If you already have it: `cd` into the project folder, then `git checkout main` and `git pull`.

---

## 3. Create the database (once)

Open the MariaDB client as root (it asks for the root password you chose when installing MariaDB):

```
& "C:\Program Files\MariaDB 12.2\bin\mariadb.exe" -u root -p
```

(Change `12.2` if your version differs.) Then, at the `MariaDB>` prompt, pick your own password:

```
create database learnloop character set utf8mb4 collate utf8mb4_unicode_ci;
create user 'learnloop'@'localhost' identified by 'CHOOSE_A_PASSWORD';
grant all privileges on learnloop.* to 'learnloop'@'localhost';
flush privileges;
exit
```

---

## 4. Configure and install

From the **backend** folder:

```
cd backend
copy .env.example .env
```

Open `backend\.env` and set your database password (keep the quotes):

```
DB_PASSWORD="CHOOSE_A_PASSWORD"
```

Then:

```
composer install
php artisan key:generate
npm install
npm run build
php artisan migrate:fresh --seed
```

`migrate:fresh --seed` **erases** the LearnLoop database and loads the demo data. Only use it when you want a
clean demo. After updates, use `php artisan migrate` instead (keeps your data).

**Never commit `.env`.** It holds your passwords and is already ignored by git.

---

## 5. Run it

```
php artisan serve
```

Open **http://127.0.0.1:8000**. Leave the terminal open while you use the site; `Ctrl+C` stops it.

### Demo logins (password for all: `password123`)

| Email | Role | What it shows |
|---|---|---|
| `jamie.lee@student.learnloop.test` | Student | Needs help in several topics, a Canvas vs LearnLoop conflict, a grade awaiting marking, many recommendations |
| `alex.morgan@student.learnloop.test` | Student | Strong student, few recommendations |
| `sam.patel@student.learnloop.test` | Student | Little evidence ("strong so far, needs more evidence") |
| `dr.sarah.chen@learnloop.test` | Lecturer (Web Technology) | Question bank, Klenam import, Canvas mappings (incl. a Klenam quiz), class overview |
| `prof.james.okafor@learnloop.test` | Lecturer (Python) | Same tools for the Python course |
| `admin@learnloop.test` | Admin | Sees every course |

### Things to try

1. As **Sam**: open Web Technology, take a practice quiz, then see your topic results and recommendations change.
2. As **Jamie**: Recommendations → *Remind me later*, *Never again*, then *Restore* from the Dismissed tab.
3. As **Dr. Chen**: Lecturer Tools → add a topic, add a question, import a Klenam CSV, change a Canvas mapping,
   look at Class Overview and click a student.
4. Edit a score in `backend\database\fixtures\canvas\course-1001.json`, then click **Sync Canvas now** as a
   student: LearnLoop records the new Canvas grade as a new version.

---

## 6. Test on your phone or tablet

LearnLoop works on phones, tablets and computers. To open it on your phone:

1. Phone and laptop on the **same Wi-Fi** (or connect the laptop to your phone's hotspot; some campus Wi-Fi
   blocks devices from reaching each other).
2. Build the site files: `npm run build` (the phone cannot use `npm run dev`).
3. Start the server so other devices can reach it:
   ```
   php artisan serve --host=0.0.0.0 --port=8000
   ```
   If Windows asks whether to allow PHP through the firewall, allow it on **private** networks.
4. Find your laptop's address: run `ipconfig` and look for **IPv4 Address** under your Wi-Fi adapter,
   e.g. `192.168.1.23`.
5. On the phone open `http://192.168.1.23:8000` (your number).

---

**Want a link that works anywhere, without your laptop?** See [DEPLOY.md](DEPLOY.md) to put LearnLoop online.

## 7. Use real Canvas (optional)

Demo data is the default. To read real Ashesi Canvas data instead:

1. In `backend\.env`:
   ```
   CANVAS_ADAPTER=token
   CANVAS_BASE_URL=https://ashesi.instructure.com
   ```
   then `php artisan config:clear` and restart `php artisan serve`.
2. The demo logins show **no courses** in this mode (they belong to the demo data). **Register** your own account.
3. In Canvas: **Account → Settings → + New Access Token**. Copy it and paste it on LearnLoop's **Canvas** page.
   Treat the token like a password: never paste it into chats, files or commits. Personal tokens are for
   testing your own account; for other users Canvas requires "Sign in with Canvas" (see DEPLOY.md §8).
4. Link a Canvas course (the number is in the course address, `…/courses/12345`):
   ```
   php artisan learnloop:link-course 12345 --name="Web Technologies" --code=CS341
   ```
5. A lecturer of that course (who connected their own token) adds topics and maps Canvas assignments to them in
   Lecturer Tools. Klenam AI quizzes appear in Canvas like assignments: set their **Source** to *Klenam AI*.
   For a solo test, you can make your own account an admin instead:
   ```
   php artisan tinker --execute="App\Models\User::where('email', 'YOUR_EMAIL')->first()->forceFill(['role' => 'admin'])->save(); echo 'done';"
   ```
6. As a student, open the course and click **Sync Canvas now**. Only grades your lecturer has released count.

Switch back to the demo with `CANVAS_ADAPTER=fixture`, `php artisan config:clear` and a restart.

Real student grades are personal data: only use your own, or data your lecturer has approved.

---

## 8. Run the automated tests

From `backend`:

```
php artisan test
```

All tests should pass. They use a separate in-memory database and never touch your `learnloop` data.

---

## 9. Common problems

| Problem | Fix |
|---|---|
| `Could not open input file: artisan` | You are in the project folder: `cd backend` |
| `Access denied for user 'learnloop'` | Password in `.env` doesn't match step 3. Fix it, then `php artisan config:clear` |
| `could not find driver` | Enable `extension=pdo_mysql` in `php.ini` |
| `intl` / `Class "Normalizer" not found` | Enable `extension=intl` in `php.ini` |
| Blank page or `Vite manifest not found` | Run `npm run build` in `backend` |
| "Canvas could not be reached" | Certificate setup in step 1; check `CANVAS_BASE_URL` ends in `.instructure.com` |
| "Canvas tokens are not enabled" | Set `CANVAS_ADAPTER=token`, `php artisan config:clear`, restart the server |
| "This Canvas account is already linked…" | Click **Disconnect** on the Canvas page of the other LearnLoop account first |
| Old settings still used after editing `.env` | `php artisan config:clear`, then stop every server: `Get-Process php \| Stop-Process -Force` and start again |
| Phone can't open the site | Same network? Started with `--host=0.0.0.0`? Firewall allowed? Try a phone hotspot |
