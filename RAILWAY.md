# Deploy Adyos (FOSSBilling) on Railway

## 1) Create a Railway project
1. Open Railway → **New Project → Deploy from GitHub repo**.
2. Select this repository (`azerty197358/hostinger`). It will build the `Dockerfile`.
3. Wait for the build to finish.

## 2) Add a database
Best option (persistent & managed):
1. In the project click **+ New → Database → Add PostgreSQL**.
2. Railway injects `DATABASE_URL` automatically.

## 3) Add persistent storage
1. Service → **Volumes** → **Add Volume**.
2. **Mount Path:** `/var/www/html/data`

## 4) Set environment variables
In the web service → **Variables** add:
```
FOSSBILLING_CONFIG=/var/www/html/data/config.php
```
`DATABASE_URL` is already set by the PostgreSQL plugin.

## 5) Deploy
1. Redeploy the service so the volume and variables take effect.
2. Open `https://<your-app>.up.railway.app/install/install.php`.
3. Choose **PostgreSQL** as the database type.
4. In **Database Connection String**, paste the Railway `DATABASE_URL` (starts with `postgres://...`). The fields will fill automatically.
5. Complete the installation wizard.

## 6) After installation
- Open `/admin` to sign in as the administrator you created.
- Set site name, logos, company info, and enable **SofizPay** under Payment Gateways.
- Do **not** delete `/var/www/html/data` (volume) — it keeps `config.php`, cache, logs and uploads.

## Troubleshooting
- **502 / “Application failed to respond”**: make sure the service uses the Docker build (not Railpack/Node) and that `PORT` is injected (it is by default). The included entrypoint makes Apache listen on Railway's `PORT`.
- **Installer shows again after redeploy**: the volume is missing or `FOSSBILLING_CONFIG` is not set. Add both, then redeploy.
- **Database “relation already exists”**: the PostgreSQL database is not empty. Drop everything and reinstall:
  ```sql
  DROP SCHEMA public CASCADE;
  CREATE SCHEMA public;
  ```
