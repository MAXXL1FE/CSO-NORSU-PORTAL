# Deploying CSO-MIS online (Railway)

This keeps your existing PHP code exactly as-is. Once set up, updating your
live site is just: edit code → `git push` → Railway redeploys automatically.

## 1. Push this project to GitHub

```
cd T7
git init
git add .
git commit -m "Initial commit"
```
Create a new repo on GitHub, then:
```
git remote add origin https://github.com/<you>/cso-mis.git
git push -u origin main
```

## 2. Create the Railway project

1. Go to railway.app → New Project → **Deploy from GitHub repo** → pick your repo.
   Railway will detect the `Dockerfile` and build from it automatically.
2. In the same project, click **New → Database → MySQL**. Railway spins up a
   managed MySQL instance and gives you connection details.

## 3. Import your schema

Open the MySQL service → **Data** tab (or connect with any MySQL client using
the credentials Railway shows you) and run, in order:
- `database/cso_mis.sql`
- `database/upgrade_v2_media.sql`
- `database/upgrade_v3_attendance_committee.sql`

(The app's `includes/schema_check.php` will also self-heal minor gaps on
first load, but importing these first avoids relying on that.)

## 4. Connect the app to the database

On your **web service** (not the database) → **Variables** tab, add:

| Variable | Value |
|---|---|
| `DB_HOST` | from the MySQL service's connection info |
| `DB_USER` | from the MySQL service's connection info |
| `DB_PASS` | from the MySQL service's connection info |
| `DB_NAME` | from the MySQL service's connection info |
| `DB_PORT` | from the MySQL service's connection info |

Railway usually lets you reference these directly from the MySQL service
instead of copy-pasting (look for "Add Reference").

## 5. Keep uploaded images persistent

Without this step, images uploaded through the app (profile photos, event
covers, gallery, announcements) disappear on every redeploy.

Web service → **Settings → Volumes** → add a volume:
- Mount path: `/var/www/html/assets/uploads`

## 6. Set the admin password, then lock the script down

Visit `https://<your-app>.up.railway.app/reset_admin_password.php` once —
this hashes the default admin password. Then **delete that file** from your
repo and push again, so it can't be run a second time by anyone else.

## 7. Ongoing updates

From here on, any change you commit and push to your GitHub repo triggers a
new Railway deploy automatically — no redeployment steps to repeat.

---

**Render** works almost identically if you'd rather use that: "New Web
Service" → connect repo → it detects the Dockerfile → add a "PostgreSQL or
MySQL" instance (Render's managed DB is Postgres-only, so on Render you'd
add MySQL via their "Private Service" + a MySQL Docker image, or use a
free external MySQL host like Railway's or PlanetScale for just the DB).
Railway is the simpler path since MySQL is built in.
